<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $stmt = $pdo->query("SELECT id, name, code, tel, email, memo, bom_mapping, created_at FROM company ORDER BY name ASC");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["status" => "success", "data" => $companies], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
