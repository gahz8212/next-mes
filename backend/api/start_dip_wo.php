<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "WO ID가 필요합니다."]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // 작업지시 조회 및 상태 검증
    $stmt = $pdo->prepare("SELECT status, target_qty FROM work_order WHERE wo_id = ? FOR UPDATE");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();
    if (!$wo) {
        throw new Exception("해당 작업지시를 찾을 수 없습니다: " . $wo_id);
    }
    if ($wo['status'] !== 'SMT_DONE' && $wo['status'] !== 'DIP_IN_PROGRESS') {
        throw new Exception("해당 작업지시를 수삽 시작할 수 없습니다. (현재 상태: " . $wo['status'] . ")");
    }

    // 상태를 DIP_IN_PROGRESS로 갱신
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'DIP_IN_PROGRESS' WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    // 불량을 제외한 바코드들을 SMT 완료(BOTTOM_DONE)로 준비하여 DIP_AOI 공정으로 투입
    $resetBc = $pdo->prepare("UPDATE barcode_master SET status = 'BOTTOM_DONE' WHERE wo_id = ? AND status != 'FAIL'");
    $resetBc->execute([$wo_id]);
    
    $pdo->commit();

    // 3. Node-RED (Docker 1881 포트) 수삽 시뮬레이션 시작 트리거 전송
    $data = json_encode([
        "wo_id" => $wo_id,
        "target_qty" => intval($wo['target_qty'])
    ]);

    $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
    $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;

    $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
    if ($fp) {
        $out = "POST /start-dip-sim HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($data) . "\r\nConnection: Close\r\n\r\n" . $data;
        fwrite($fp, $out);
        fclose($fp);
    }

    echo json_encode(["status" => "success", "message" => "수삽 공정이 시작되었습니다. 시뮬레이션이 가동됩니다."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
