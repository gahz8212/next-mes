<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "WO ID가 필요합니다."]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // 상태 변경
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'IN_PROGRESS' WHERE wo_id = ? AND status = 'READY'");
    $stmt->execute([$wo_id]);
    
    if ($stmt->rowCount() == 0) {
        throw new Exception("해당 작업지시를 시작할 수 없습니다. (이미 진행중이거나 완료됨)");
    }

    // 작업지시 정보 가져오기
    $stmt = $pdo->prepare("SELECT target_qty FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();
    
    $pdo->commit();

    // Node-RED API 호출 (시뮬레이션 시작 트리거)
    $url = 'http://localhost:1880/start-sim';
    $data = json_encode([
        "wo_id" => $wo_id,
        "target_qty" => $wo['target_qty']
    ]);

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => $data,
            'timeout' => 1 // 빠른 응답을 위해 타임아웃 1초 설정
        ]
    ];
    $context  = stream_context_create($options);
    
    // Ignore errors from file_get_contents (e.g. if Node-RED is down)
    @file_get_contents($url, false, $context);

    echo json_encode(["status" => "success", "message" => "작업이 시작되었습니다. 시뮬레이션이 가동됩니다."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
