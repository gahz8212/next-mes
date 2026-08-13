<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$target_qty = $input['target_qty'] ?? 0;
$due_date = $input['due_date'] ?? null;
$company_id = $input['company_id'] ?? null;

if (!$wo_id || $target_qty <= 0) {
    echo json_encode(["status" => "error", "message" => "WO ID와 수량을 입력하세요."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 작업지시 등록 (BOM은 나중에 입력)
    $stmt = $pdo->prepare("INSERT INTO work_order (wo_id, company_id, target_qty, status, due_date) VALUES (?, ?, ?, 'READY', ?)");
    $stmt->execute([$wo_id, $company_id, $target_qty, $due_date]);

    // 바코드 일괄 발행
    $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
    for ($i = 1; $i <= $target_qty; $i++) {
        $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $barcodeStmt->execute([$barcode, $wo_id]);
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "{$target_qty}개 바코드 발행 완료"]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>