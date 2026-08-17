<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$company_id = !empty($input['company_id']) ? (int)$input['company_id'] : null;
$item_code = trim($input['item_code'] ?? '');
$item_name = trim($input['item_name'] ?? '');
$category = isset($input['category']) && $input['category'] !== '' ? trim($input['category']) : null;
$unit = !empty($input['unit']) ? trim($input['unit']) : 'EA';
$description = isset($input['description']) && $input['description'] !== '' ? trim($input['description']) : null;

if ($item_code === '' || $item_name === '') {
    echo json_encode([
        "status" => "error",
        "message" => "품목 코드와 품목명은 필수 입력 항목입니다."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO item (company_id, item_code, item_name, category, unit, description) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$company_id, $item_code, $item_name, $category, $unit, $description]);
    $id = (int)$pdo->lastInsertId();

    echo json_encode([
        "status" => "success",
        "data" => [
            "id" => $id,
            "company_id" => $company_id,
            "item_code" => $item_code,
            "item_name" => $item_name
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode([
            "status" => "error",
            "message" => "이미 존재하는 품목 코드입니다."
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
