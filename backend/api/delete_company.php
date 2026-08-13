<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $id = $input['id'] ?? null;

    if (empty($id)) {
        echo json_encode(["status" => "error", "message" => "필수 입력 항목(id)이 누락되었습니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE company_id = ?");
    $stmt->execute([$id]);
    $count = (int)$stmt->fetchColumn();

    if ($count > 0) {
        echo json_encode(["status" => "error", "message" => "연결된 작업지시(work_order)가 있어 삭제할 수 없습니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM company WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(["status" => "success", "message" => "업체가 삭제되었습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
