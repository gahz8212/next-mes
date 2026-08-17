<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = !empty($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(["status" => "error", "message" => "삭제할 매핑 ID가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM part_alias WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["status" => "success", "message" => "부품 매핑이 삭제되었습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
