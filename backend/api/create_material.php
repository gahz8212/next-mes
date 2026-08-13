<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$partNo = trim($input['part_no'] ?? '');
$partName = trim($input['part_name'] ?? '') ?: null;
$inoutType = strtoupper(trim($input['inout_type'] ?? ''));
$qty = $input['qty'] ?? null;
$unit = trim($input['unit'] ?? '') ?: 'EA';
$woId = trim($input['wo_id'] ?? '') ?: null;
$companyId = !empty($input['company_id']) ? (int)$input['company_id'] : null;
$note = trim($input['note'] ?? '') ?: null;

if (empty($partNo) || !in_array($inoutType, ['IN', 'OUT']) || !is_numeric($qty) || (float)$qty <= 0) {
    echo json_encode(["status" => "error", "message" => "필수 입력 항목(part_no, inout_type: IN/OUT, qty > 0)을 확인해주세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "INSERT INTO material_inout (part_no, part_name, inout_type, qty, unit, wo_id, company_id, note)
            VALUES (:part_no, :part_name, :inout_type, :qty, :unit, :wo_id, :company_id, :note)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':part_no' => $partNo,
        ':part_name' => $partName,
        ':inout_type' => $inoutType,
        ':qty' => $qty,
        ':unit' => $unit,
        ':wo_id' => $woId,
        ':company_id' => $companyId,
        ':note' => $note
    ]);

    $id = (int)$pdo->lastInsertId();
    echo json_encode(["status" => "success", "data" => ["id" => $id]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
