<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$id = $input['id'] ?? null;

if (empty($id)) {
    echo json_encode([
        "status" => "error",
        "message" => "ID가 누락되었습니다."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM item WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        "status" => "success",
        "message" => "품목이 삭제되었습니다."
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
