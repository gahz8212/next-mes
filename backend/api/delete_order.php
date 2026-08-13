<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = (int)($input['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(["status" => "error", "message" => "유효하지 않은 ID입니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 이미 작업지시와 연동된 수주인지 확인
    $stmtCheck = $pdo->prepare("SELECT wo_id, order_no FROM sales_order WHERE id = ?");
    $stmtCheck->execute([$id]);
    $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($order && !empty($order['wo_id'])) {
        echo json_encode(["status" => "error", "message" => "이미 작업지시({$order['wo_id']})가 발행된 수주는 삭제할 수 없습니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM sales_order WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(["status" => "success", "message" => "수주가 삭제되었습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
