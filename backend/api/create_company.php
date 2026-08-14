<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');

if (!$name) {
    echo json_encode(["status" => "error", "message" => "업체명을 입력하세요."]);
    exit;
}

try {
    // Generate 2-letter random code
    $code = strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 2));
    
    // To ASCII if Korean (just simple mapping or use as is, MySQL supports utf8)
    // Actually, user said: "임시로 업체명 앞 두글자 랜덤" - let's just make it two random letters if we want to be safe, or just use the first two characters.
    $code = strtoupper(bin2hex(random_bytes(1))); // Just 2 random hex chars for safety

    $stmt = $pdo->prepare("INSERT INTO company (name, code) VALUES (?, ?)");
    $stmt->execute([$name, $code]);
    $id = $pdo->lastInsertId();

    echo json_encode(["status" => "success", "data" => ["id" => $id, "name" => $name, "code" => $code]]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
