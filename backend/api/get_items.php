<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $stmt = $pdo->query("SELECT * FROM item ORDER BY item_code ASC");
    $items = $stmt->fetchAll();
    echo json_encode([
        "status" => "success",
        "data" => $items
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
