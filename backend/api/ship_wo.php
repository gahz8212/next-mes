<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = trim($input['wo_id'] ?? '');

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "WO ID가 필요합니다."]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE work_order SET shipped = 1, shipped_at = NOW() WHERE wo_id = ? AND status = 'DONE'");
    $stmt->execute([$wo_id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(["status" => "success", "message" => "납품 처리되었습니다."]);
    } else {
        echo json_encode(["status" => "error", "message" => "완료된 작업지시만 납품 처리 가능합니다."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
