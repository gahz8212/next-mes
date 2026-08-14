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
    
    // 상태 변경 (SMT_DONE -> DIP_IN_PROGRESS)
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'DIP_IN_PROGRESS' WHERE wo_id = ? AND status = 'SMT_DONE'");
    $stmt->execute([$wo_id]);
    
    if ($stmt->rowCount() == 0) {
        throw new Exception("해당 작업지시를 수삽 시작할 수 없습니다. (상태 오류)");
    }

    // 작업지시 정보 가져오기
    $stmt = $pdo->prepare("SELECT target_qty FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();
    
    $pdo->commit();

    // 3. 로컬 Node-RED (1880 포트) 수삽 시뮬레이션 시작 트리거 전송
    $data = json_encode([
        "wo_id" => $wo_id,
        "target_qty" => intval($wo['target_qty'])
    ]);

    $fp = @fsockopen('127.0.0.1', 1880, $errno, $errstr, 0.5);
    if ($fp) {
        $out = "POST /start-dip-sim HTTP/1.1\r\nHost: 127.0.0.1:1880\r\nContent-Type: application/json\r\nContent-Length: " . strlen($data) . "\r\nConnection: Close\r\n\r\n" . $data;
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
