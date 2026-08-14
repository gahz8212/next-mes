<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$id = $input['id'] ?? null;

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "id가 전달되지 않았습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)$id === 1) {
    echo json_encode(["status" => "error", "message" => "기본 관리자 계정은 삭제할 수 없습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
