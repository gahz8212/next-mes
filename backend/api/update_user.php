<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$id = $input['id'] ?? null;
$name = trim($input['name'] ?? '');
$role = trim($input['role'] ?? '');
$department = trim($input['department'] ?? '');
$is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;
$password = trim($input['password'] ?? '');

if (empty($id) || empty($name)) {
    echo json_encode(["status" => "error", "message" => "필수 항목(id, 이름)을 입력하세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!empty($password)) {
        $password_hash = hash('sha256', $password);
        $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, department = ?, is_active = ?, password_hash = ? WHERE id = ?");
        $stmt->execute([$name, $role, $department, $is_active, $password_hash, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, department = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $role, $department, $is_active, $id]);
    }

    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
