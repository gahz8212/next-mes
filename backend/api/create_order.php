<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$company_id  = (int)($input['company_id'] ?? 0);
$item_code   = trim($input['item_code'] ?? '');
$item_name   = trim($input['item_name'] ?? '');
$order_qty   = (int)($input['order_qty'] ?? 0);
$unit_price  = (float)($input['unit_price'] ?? 0);
$order_date  = !empty($input['order_date']) ? $input['order_date'] : date('Y-m-d');
$due_date    = !empty($input['due_date']) ? $input['due_date'] : null;
$memo        = trim($input['memo'] ?? '');

if ($company_id <= 0 || $order_qty <= 0 || empty($due_date)) {
    echo json_encode(["status" => "error", "message" => "고객사, 수량(1개 이상), 납기일은 필수 입력값입니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 자동 수주 번호 생성: PO-YYYYMMDD-랜덤3자리
    $order_no = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    $total_price = $order_qty * $unit_price;

    $stmt = $pdo->prepare("
        INSERT INTO sales_order (order_no, company_id, item_code, item_name, order_qty, unit_price, total_price, order_date, due_date, status, memo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RECEIVED', ?)
    ");
    $stmt->execute([$order_no, $company_id, $item_code, $item_name, $order_qty, $unit_price, $total_price, $order_date, $due_date, $memo]);
    $id = (int)$pdo->lastInsertId();

    // 알림 및 시스템 로그 기록
    $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('INFO', '📦 신규 수주 등록', ?, 'order')")
        ->execute(["신규 수주 {$order_no} ({$item_name}, " . number_format($order_qty) . "EA)가 접수되었습니다."]);

    $pdo->prepare("INSERT INTO system_log (username, action_type, description) VALUES ('admin', 'ORDER_CREATE', ?)")
        ->execute(["수주 등록: {$order_no} (수량: {$order_qty}, 납기일: {$due_date})"]);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "id"       => $id,
            "order_no" => $order_no
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
