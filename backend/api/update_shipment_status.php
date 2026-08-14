<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$id = $input['id'] ?? null;
$status = strtoupper(trim($input['status'] ?? ''));

if (empty($id) || !in_array($status, ['PENDING', 'SHIPPED', 'CANCELLED'])) {
    echo json_encode(["status" => "error", "message" => "필수 입력 항목(id, status: PENDING/SHIPPED/CANCELLED)을 확인해주세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE shipment SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    if ($status === 'SHIPPED') {
        $stmtWo = $pdo->prepare("UPDATE work_order SET shipped = 1, shipped_at = NOW() WHERE wo_id = (SELECT wo_id FROM shipment WHERE id = ?)");
        $stmtWo->execute([$id]);
    }

    $pdo->commit();

    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
