<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$reel_barcode = trim($input['reel_barcode'] ?? '');
$target_slot_no = !empty($input['slot_no']) ? (int)$input['slot_no'] : null;
$scanned_by = $input['scanned_by'] ?? 'Worker';

if (!$wo_id || !$reel_barcode) {
    echo json_encode(["status" => "error", "message" => "작업지시 ID(wo_id)와 릴 바코드(reel_barcode)가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 릴 마스터에서 릴 정보 확인 (릴 바코드 또는 품번 직접 입력 모두 지원)
    $stmt = $pdo->prepare("SELECT reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status FROM reel_master WHERE reel_barcode = ?");
    $stmt->execute([$reel_barcode]);
    $reel = $stmt->fetch();

    if (!$reel) {
        // 품번(Part No)을 직접 스캔/입력한 경우 자동 검색 및 릴 바코드 생성
        $stmtPart = $pdo->prepare("SELECT reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status FROM reel_master WHERE part_no = ? LIMIT 1");
        $stmtPart->execute([$reel_barcode]);
        $reel = $stmtPart->fetch();

        if ($reel) {
            $reel_barcode = $reel['reel_barcode'];
        } else {
            // BOM에 있는 품번인지 확인하여 임시 릴 자동 등록
            $checkBom = $pdo->prepare("SELECT part_no FROM bom_detail bd JOIN work_order wo ON bd.bom_id = wo.bom_id WHERE wo.wo_id = ? AND bd.part_no = ?");
            $checkBom->execute([$wo_id, $reel_barcode]);
            $bomMatch = $checkBom->fetch();

            if ($bomMatch) {
                $autoBarcode = 'REEL-' . substr(md5($reel_barcode), 0, 8);
                $insReel = $pdo->prepare("INSERT IGNORE INTO reel_master (reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status) VALUES (?, ?, 1, 0, NOW(), 'IN_USE')");
                $insReel->execute([$autoBarcode, $reel_barcode]);
                $reel = ['part_no' => $reel_barcode, 'msl_level' => 1, 'floor_life_hours' => 0, 'unsealed_at' => date('Y-m-d H:i:s'), 'status' => 'IN_USE'];
                $reel_barcode = $autoBarcode;
            } else {
                throw new Exception("등록되지 않은 릴 바코드 또는 품번입니다: [{$reel_barcode}]");
            }
        }
    }

    if ($reel['status'] === 'EXPIRED') {
        throw new Exception("MSL 습기 노출 허용시간이 만료된 자재입니다. 베이킹(Baking) 후 사용해야 합니다.");
    }

    // 2. 스마트 개봉 처리: unsealed_at이 비어있으면 개봉 시간 기록
    $is_first_scan = false;
    if (is_null($reel['unsealed_at'])) {
        $updateReel = $pdo->prepare("UPDATE reel_master SET unsealed_at = NOW(), status = 'IN_USE' WHERE reel_barcode = ?");
        $updateReel->execute([$reel_barcode]);
        $is_first_scan = true;
    }

    // 3. 포카요케 BOM 검증: feeder_setup에서 해당 자재 품번(part_no)과 일치하는 슬롯 찾기
    if ($target_slot_no) {
        // 특정 슬롯을 지정하여 스캔한 경우
        $slotStmt = $pdo->prepare("SELECT id, slot_no, part_no, location, status FROM feeder_setup WHERE wo_id = ? AND slot_no = ?");
        $slotStmt->execute([$wo_id, $target_slot_no]);
        $targetSlot = $slotStmt->fetch();

        if (!$targetSlot) {
            throw new Exception("해당 피더 슬롯 정보를 찾을 수 없습니다.");
        }

        if ($targetSlot['part_no'] !== $reel['part_no']) {
            throw new Exception("오투입(MISMATCH) 경고! 슬롯 {$target_slot_no}번의 필요 부품은 [{$targetSlot['part_no']}]이지만, 스캔된 릴은 [{$reel['part_no']}] 입니다.");
        }

        $matched_slot_id = $targetSlot['id'];
        $matched_slot_no = $targetSlot['slot_no'];
        $matched_location = $targetSlot['location'];
    } else {
        // 특정 슬롯 미지정 시, 해당 품번과 일치하는 미장착 슬롯 검색
        $slotStmt = $pdo->prepare("SELECT id, slot_no, part_no, location, status FROM feeder_setup WHERE wo_id = ? AND part_no = ? AND status != 'VERIFIED' LIMIT 1");
        $slotStmt->execute([$wo_id, $reel['part_no']]);
        $matchedSlot = $slotStmt->fetch();

        if (!$matchedSlot) {
            // 이미 장착되었는지 또는 BOM에 아예 없는지 확인
            $existStmt = $pdo->prepare("SELECT id, slot_no, status FROM feeder_setup WHERE wo_id = ? AND part_no = ?");
            $existStmt->execute([$wo_id, $reel['part_no']]);
            $exist = $existStmt->fetch();

            if ($exist) {
                throw new Exception("이미 검증 장착이 완료된 부품입니다: [{$reel['part_no']}] (피더 슬롯 {$exist['slot_no']}번)");
            } else {
                throw new Exception("오투입(MISMATCH) 경고! 현재 작업지시의 BOM에 포함되지 않은 자재 품번입니다: [{$reel['part_no']}]");
            }
        }

        $matched_slot_id = $matchedSlot['id'];
        $matched_slot_no = $matchedSlot['slot_no'];
        $matched_location = $matchedSlot['location'];
    }

    // 4. 슬롯 검증 완료 업데이트
    $upStmt = $pdo->prepare("
        UPDATE feeder_setup 
        SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = ?
        WHERE id = ?
    ");
    $upStmt->execute([$reel_barcode, $scanned_by, $matched_slot_id]);

    // 5. 전체 피더 진행 현황 재집계
    $statStmt = $pdo->prepare("
        SELECT 
            count(*) as total,
            SUM(CASE WHEN status = 'VERIFIED' THEN 1 ELSE 0 END) as verified
        FROM feeder_setup 
        WHERE wo_id = ?
    ");
    $statStmt->execute([$wo_id]);
    $stats = $statStmt->fetch();

    $total = (int)$stats['total'];
    $verified = (int)$stats['verified'];
    $interlock_released = ($total > 0 && $verified === $total);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "포카요케 검증 성공! 피더 슬롯 {$matched_slot_no}번 [{$matched_location}]에 장착되었습니다.",
        "data" => [
            "slot_no" => $matched_slot_no,
            "location" => $matched_location,
            "part_no" => $reel['part_no'],
            "reel_barcode" => $reel_barcode,
            "is_first_scan" => $is_first_scan,
            "verified_count" => $verified,
            "total_count" => $total,
            "progress_percent" => $total > 0 ? round(($verified / $total) * 100, 1) : 0,
            "interlock_released" => $interlock_released
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>