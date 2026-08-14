<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$wo_id = $_GET['wo_id'] ?? '';

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "wo_id 파라미터가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. 작업지시 및 BOM 정보 확인
    $stmt = $pdo->prepare("SELECT wo_id, bom_id, target_qty, status FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();

    if (!$wo) {
        throw new Exception("작업지시를 찾을 수 없습니다: " . $wo_id);
    }

    $bom_id = $wo['bom_id'];
    
    // 만약 bom_id가 없다면 기본 1006으로 연결
    if (!$bom_id) {
        $bom_id = 1006;
        $up = $pdo->prepare("UPDATE work_order SET bom_id = ? WHERE wo_id = ?");
        $up->execute([$bom_id, $wo_id]);
    }

    // 2. feeder_setup에 기존 데이터가 있는지 확인
    $checkStmt = $pdo->prepare("SELECT count(*) FROM feeder_setup WHERE wo_id = ?");
    $checkStmt->execute([$wo_id]);
    $count = $checkStmt->fetchColumn();

    // 없으면 bom_detail로부터 자동 생성
    if ($count == 0) {
        $bomStmt = $pdo->prepare("SELECT part_no, req_qty, location FROM bom_detail WHERE bom_id = ? ORDER BY detail_id ASC");
        $bomStmt->execute([$bom_id]);
        $bomList = $bomStmt->fetchAll();

        if (empty($bomList)) {
            // BOM detail이 없으면 기본 예시 생성
            $bomList = [
                ['part_no' => 'MT29F2G08ABAGAH4-IT:G', 'req_qty' => 10, 'location' => 'U2'],
                ['part_no' => 'LIS3MDL', 'req_qty' => 80, 'location' => 'U16'],
                ['part_no' => 'BMA250E', 'req_qty' => 80, 'location' => 'U15'],
                ['part_no' => 'XC61FC2512MR', 'req_qty' => 90, 'location' => 'U36'],
                ['part_no' => 'BMC-2703', 'req_qty' => 10, 'location' => 'U12'],
                ['part_no' => 'MAX1554ETA', 'req_qty' => 69, 'location' => 'U11'],
                ['part_no' => 'SY8008CAAC', 'req_qty' => 221, 'location' => 'U31,U32']
            ];
        }

        $insStmt = $pdo->prepare("
            INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status)
            VALUES (?, ?, ?, ?, ?, 'PENDING')
        ");

        $slot = 1;
        foreach ($bomList as $item) {
            $insStmt->execute([
                $wo_id,
                $slot++,
                $item['part_no'],
                $item['location'] ?? 'U' . $slot,
                $item['req_qty'] ?? 10
            ]);
        }
    }

    // 3. feeder_setup 및 MSL 정보 조회
    $listStmt = $pdo->prepare("
        SELECT 
            fs.id, fs.wo_id, fs.slot_no, fs.part_no, fs.location, fs.req_qty,
            fs.reel_barcode, fs.status, fs.scanned_at, fs.scanned_by,
            rm.msl_level, rm.floor_life_hours, rm.unsealed_at, rm.status as reel_status
        FROM feeder_setup fs
        LEFT JOIN reel_master rm ON fs.reel_barcode = rm.reel_barcode
        WHERE fs.wo_id = ?
        ORDER BY fs.slot_no ASC
    ");
    $listStmt->execute([$wo_id]);
    $slots = $listStmt->fetchAll();

    $total_count = count($slots);
    $verified_count = 0;
    foreach ($slots as $s) {
        if ($s['status'] === 'VERIFIED') {
            $verified_count++;
        }
    }

    $interlock_released = ($total_count > 0 && $verified_count === $total_count);

    // 4. 스캔 가능한 릴 샘플 목록도 함께 제공 (테스트 및 가이드용)
    $reelStmt = $pdo->query("SELECT reel_barcode, part_no, msl_level, status FROM reel_master ORDER BY reel_barcode ASC");
    $allReels = $reelStmt->fetchAll();

    echo json_encode([
        "status" => "success",
        "data" => [
            "wo_id" => $wo_id,
            "target_qty" => (int)$wo['target_qty'],
            "total_slots" => $total_count,
            "verified_slots" => $verified_count,
            "progress_percent" => $total_count > 0 ? round(($verified_count / $total_count) * 100, 1) : 0,
            "interlock_released" => $interlock_released,
            "slots" => $slots,
            "available_reels" => $allReels
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
