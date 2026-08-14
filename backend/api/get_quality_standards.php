<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $stmt = $pdo->query("SELECT * FROM quality_standard ORDER BY process_name ASC, id ASC");
    $data = $stmt->fetchAll();
    echo json_encode(["status" => "success", "data" => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
