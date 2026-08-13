<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$id = !empty($input['id']) ? (int)$input['id'] : null;
$process_name = trim($input['process_name'] ?? '');
$check_item = trim($input['check_item'] ?? '');
$standard_value = $input['standard_value'] ?? null;
$unit = trim($input['unit'] ?? '');
$is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;

if (empty($process_name) || empty($check_item)) {
    echo json_encode(["status" => "error", "message" => "필수 항목(공정명, 검사항목)을 입력하세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($id) {
        $stmt = $pdo->prepare("UPDATE quality_standard SET process_name = ?, check_item = ?, standard_value = ?, unit = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$process_name, $check_item, $standard_value, $unit, $is_active, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO quality_standard (process_name, check_item, standard_value, unit, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$process_name, $check_item, $standard_value, $unit, $is_active]);
    }

    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
