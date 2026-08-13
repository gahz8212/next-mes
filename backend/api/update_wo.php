<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$target_qty = $input['target_qty'] ?? 0;
$due_date = $input['due_date'] ?? null;

if (empty($wo_id) || $target_qty <= 0) {
    echo json_encode(["status" => "error", "message" => "필수 데이터가 누락되었거나 수량이 잘못되었습니다."]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM work_order WHERE wo_id = ? FOR UPDATE");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();

    if (!$wo) {
        throw new Exception("존재하지 않는 작업지시입니다.");
    }

    if ($wo['status'] !== 'READY') {
        throw new Exception("대기중(READY)인 작업지시만 수정할 수 있습니다.");
    }

    // 작업지시 정보 업데이트
    $stmt = $pdo->prepare("UPDATE work_order SET target_qty = ?, due_date = ? WHERE wo_id = ?");
    $stmt->execute([$target_qty, $due_date, $wo_id]);

    // 기존 바코드 삭제
    $stmt = $pdo->prepare("DELETE FROM barcode_master WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    // 새로운 수량에 맞게 바코드 재발행
    $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
    for ($i = 1; $i <= $target_qty; $i++) {
        $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $barcodeStmt->execute([$barcode, $wo_id]);
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "작업지시가 성공적으로 수정되었습니다."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
