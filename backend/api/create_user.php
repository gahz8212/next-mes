<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');
$name = trim($input['name'] ?? '');
$role = trim($input['role'] ?? '');
$department = trim($input['department'] ?? '');

if (empty($username) || empty($password) || empty($name)) {
    echo json_encode(["status" => "error", "message" => "필수 항목(아이디, 비밀번호, 이름)을 입력하세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 중복 username 확인
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmtCheck->execute([$username]);
    if ($stmtCheck->fetch()) {
        echo json_encode(["status" => "error", "message" => "이미 사용 중인 아이디입니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $password_hash = hash('sha256', $password);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, department) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $password_hash, $name, $role, $department]);
    $id = (int)$pdo->lastInsertId();

    echo json_encode(["status" => "success", "data" => ["id" => $id]], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(["status" => "error", "message" => "이미 사용 중인 아이디입니다."], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
