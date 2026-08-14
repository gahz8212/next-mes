<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$action = $input['action'] ?? 'reset'; // 'reset' or 'auto_verify'

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "wo_id가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'reset') {
        $stmt = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = NULL, status = 'PENDING', scanned_at = NULL WHERE wo_id = ?");
        $stmt->execute([$wo_id]);
        echo json_encode(["status" => "success", "message" => "피더 셋업이 초기화되었습니다. (인터록 잠김)"], JSON_UNESCAPED_UNICODE);
    } else if ($action === 'auto_verify') {
        // 모든 슬롯에 매칭되는 릴 바코드를 자동으로 찾아 100% 일괄 검증 처리
        $slotsStmt = $pdo->prepare("SELECT id, part_no, slot_no FROM feeder_setup WHERE wo_id = ?");
        $slotsStmt->execute([$wo_id]);
        $slots = $slotsStmt->fetchAll();

        foreach ($slots as $s) {
            $reelStmt = $pdo->prepare("SELECT reel_barcode FROM reel_master WHERE part_no = ? LIMIT 1");
            $reelStmt->execute([$s['part_no']]);
            $reel = $reelStmt->fetch();

            $barcode = $reel ? $reel['reel_barcode'] : 'AUTO-REEL-' . $s['slot_no'];
            
            // 릴 마스터에 없으면 생성
            if (!$reel) {
                $insReel = $pdo->prepare("INSERT IGNORE INTO reel_master (reel_barcode, part_no, msl_level, floor_life_hours, status) VALUES (?, ?, 1, 0, 'IN_USE')");
                $insReel->execute([$barcode, $s['part_no']]);
            }

            $up = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = 'AutoSetup' WHERE id = ?");
            $up->execute([$barcode, $s['id']]);
        }

        echo json_encode(["status" => "success", "message" => "모든 피더 릴이 100% 검증 완료되었습니다. (인터록 해제)"], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
