<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$id = $input['id'] ?? null;

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "필수 입력 항목(id)이 누락되었습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM material_inout WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
