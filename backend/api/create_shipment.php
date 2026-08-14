<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$woId = trim($input['wo_id'] ?? '');
$shipQty = $input['ship_qty'] ?? null;
$shipDate = trim($input['ship_date'] ?? '');
$companyId = !empty($input['company_id']) ? (int)$input['company_id'] : null;
$invoiceNo = trim($input['invoice_no'] ?? '') ?: null;
$note = trim($input['note'] ?? '') ?: null;

if (empty($woId) || !is_numeric($shipQty) || (float)$shipQty <= 0 || empty($shipDate)) {
    echo json_encode(["status" => "error", "message" => "필수 입력 항목(wo_id, ship_qty(>0), ship_date)을 확인해주세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "INSERT INTO shipment (wo_id, ship_qty, ship_date, company_id, invoice_no, note)
            VALUES (:wo_id, :ship_qty, :ship_date, :company_id, :invoice_no, :note)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':wo_id' => $woId,
        ':ship_qty' => $shipQty,
        ':ship_date' => $shipDate,
        ':company_id' => $companyId,
        ':invoice_no' => $invoiceNo,
        ':note' => $note
    ]);

    $id = (int)$pdo->lastInsertId();
    echo json_encode(["status" => "success", "data" => ["id" => $id]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
