<?php
// backend/api/reset_line.php - 라인 가동 상태 및 작업지시 클린 초기화
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$targetWoId = $input['wo_id'] ?? 'C1-20260813-2A6';

try {
    $pdo->beginTransaction();

    // 1. Node-RED 시뮬레이션 중단 요청
    $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
    $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;
    $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
    if ($fp) {
        $stopData = json_encode(["wo_id" => $targetWoId]);
        $out = "POST /stop-sim HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($stopData) . "\r\nConnection: Close\r\n\r\n" . $stopData;
        fwrite($fp, $out);
        fclose($fp);
    }

    // 2. 임시 테스트 작업지시 정리 (WO-DOCKER-TEST01 등)
    $pdo->exec("DELETE FROM barcode_history WHERE barcode LIKE 'WO-DOCKER-TEST%'");
    $pdo->exec("DELETE FROM barcode_master WHERE wo_id LIKE 'WO-DOCKER-TEST%'");
    $pdo->exec("DELETE FROM work_order WHERE wo_id LIKE 'WO-DOCKER-TEST%'");

    // 3. 대상 작업지시 상태를 READY로 롤백
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'READY', completed_at = NULL WHERE wo_id = ?");
    $stmt->execute([$targetWoId]);

    // 4. 해당 작업지시의 모든 바코드를 WAIT 상태로 초기화
    $stmtBc = $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ?");
    $stmtBc->execute([$targetWoId]);

    // 5. 해당 작업지시의 공정 이력(barcode_history) 클린 삭제
    $stmtHist = $pdo->prepare("DELETE FROM barcode_history WHERE barcode LIKE ?");
    $stmtHist->execute(["{$targetWoId}-%"]);

    // 6. 피더 셋업 100% 검증 상태로 유지 (바로 자삽 시작 가능)
    $slotsStmt = $pdo->prepare("SELECT id, part_no, slot_no FROM feeder_setup WHERE wo_id = ?");
    $slotsStmt->execute([$targetWoId]);
    $slots = $slotsStmt->fetchAll();
    foreach ($slots as $s) {
        $reelStmt = $pdo->prepare("SELECT reel_barcode FROM reel_master WHERE part_no = ? LIMIT 1");
        $reelStmt->execute([$s['part_no']]);
        $reel = $reelStmt->fetch();
        $barcode = $reel ? $reel['reel_barcode'] : 'REEL-SLOT-' . $s['slot_no'];

        $up = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = 'SystemReset' WHERE id = ?");
        $up->execute([$barcode, $s['id']]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "작업지시 [{$targetWoId}] 및 설비 라인이 대기(READY) 상태로 깨끗이 초기화되었습니다.",
        "wo_id" => $targetWoId
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
