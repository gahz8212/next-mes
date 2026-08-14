<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $stmt = $pdo->query("SELECT * FROM company ORDER BY name ASC");
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
