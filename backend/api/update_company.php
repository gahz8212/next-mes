<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $id = $input['id'] ?? null;
    $name = isset($input['name']) ? trim($input['name']) : '';

    if (empty($id) || $name === '') {
        echo json_encode(["status" => "error", "message" => "필수 입력 항목(id, name)이 누락되었습니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tel = isset($input['tel']) ? trim($input['tel']) : null;
    $email = isset($input['email']) ? trim($input['email']) : null;
    $memo = isset($input['memo']) ? trim($input['memo']) : null;

    $stmt = $pdo->prepare("UPDATE company SET name=?, tel=?, email=?, memo=? WHERE id=?");
    $stmt->execute([$name, $tel, $email, $memo, $id]);

    echo json_encode(["status" => "success", "message" => "업체 정보가 수정되었습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
