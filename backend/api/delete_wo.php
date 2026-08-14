<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';

if (empty($wo_id)) {
    echo json_encode(["status" => "error", "message" => "작업지시 번호가 누락되었습니다."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 진행중이거나 완료된 작업지시는 삭제 불가
    $stmt = $pdo->prepare("SELECT status FROM work_order WHERE wo_id = ? FOR UPDATE");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();

    if (!$wo) {
        throw new Exception("존재하지 않는 작업지시입니다.");
    }

    if ($wo['status'] !== 'READY') {
        throw new Exception("대기중(READY)인 작업지시만 삭제할 수 있습니다.");
    }

    // 바코드 삭제 (cascade가 없으므로 수동 삭제)
    $stmt = $pdo->prepare("DELETE FROM barcode_master WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    // 작업지시 삭제
    $stmt = $pdo->prepare("DELETE FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "작업지시가 성공적으로 삭제되었습니다."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
