<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$id          = (int)($input['id'] ?? 0);
$company_id  = (int)($input['company_id'] ?? 0);
$item_code   = trim($input['item_code'] ?? '');
$item_name   = trim($input['item_name'] ?? '');
$order_qty   = (int)($input['order_qty'] ?? 0);
$unit_price  = (float)($input['unit_price'] ?? 0);
$due_date    = !empty($input['due_date']) ? $input['due_date'] : null;
$status      = trim($input['status'] ?? 'RECEIVED');
$memo        = trim($input['memo'] ?? '');

if ($id <= 0 || $company_id <= 0 || $order_qty <= 0 || empty($due_date)) {
    echo json_encode(["status" => "error", "message" => "필수 입력 항목이 누락되었습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $total_price = $order_qty * $unit_price;

    $stmt = $pdo->prepare("
        UPDATE sales_order 
        SET company_id = ?, item_code = ?, item_name = ?, order_qty = ?, unit_price = ?, total_price = ?, due_date = ?, status = ?, memo = ?
        WHERE id = ?
    ");
    $stmt->execute([$company_id, $item_code, $item_name, $order_qty, $unit_price, $total_price, $due_date, $status, $memo, $id]);

    echo json_encode(["status" => "success", "message" => "수주 정보가 수정되었습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
