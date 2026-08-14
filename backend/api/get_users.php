<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $stmt = $pdo->query("SELECT id, username, name, role, department, is_active, last_login, created_at FROM users ORDER BY role ASC, name ASC");
    $users = $stmt->fetchAll();
    echo json_encode(["status" => "success", "data" => $users], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
