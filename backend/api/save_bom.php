<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$bom_data = $input['bom_data'] ?? []; // Array of {part_no, part_name, req_qty, location}
$company_id = $input['company_id'] ?? '';
$mapping = $input['mapping'] ?? [];
$auto_inbound = !empty($input['auto_inbound']);

if (!$wo_id || empty($bom_data)) {
    echo json_encode(["status" => "error", "message" => "WO ID와 BOM 데이터가 필요합니다."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Create a product_master for this WO if it doesn't exist
    $product_id = "PROD-" . $wo_id;
    $stmt = $pdo->prepare("INSERT IGNORE INTO product_master (product_id, product_name) VALUES (?, ?)");
    $stmt->execute([$product_id, "Product for " . $wo_id]);
    
    $pdo->exec("INSERT INTO bom_master (product_id) VALUES ('{$product_id}')");
    $bom_id = $pdo->lastInsertId();

    // 2. Update work_order with this bom_id
    $stmt = $pdo->prepare("UPDATE work_order SET bom_id = ? WHERE wo_id = ?");
    $stmt->execute([$bom_id, $wo_id]);

    // 3. Insert BOM details
    $detailStmt = $pdo->prepare("INSERT INTO bom_detail (bom_id, part_no, req_qty, location) VALUES (?, ?, ?, ?)");
    foreach ($bom_data as $row) {
        $detailStmt->execute([$bom_id, $row['part_no'], $row['req_qty'], $row['location'] ?? '']);
    }

    // 4. Update company bom_mapping if provided
    if ($company_id && !empty($mapping)) {
        $mappingJson = json_encode($mapping);
        $stmt = $pdo->prepare("UPDATE company SET bom_mapping = ? WHERE id = ?");
        $stmt->execute([$mappingJson, $company_id]);
    }

    // 5. Auto Inbound to material_inout (사급 자재 자동 입고 연계)
    $inbound_count = 0;
    if ($auto_inbound) {
        $matStmt = $pdo->prepare("INSERT INTO material_inout (part_no, part_name, inout_type, supply_type, qty, unit, wo_id, company_id, note) VALUES (?, ?, 'IN', 'CONSIGNED', ?, 'EA', ?, ?, ?)");
        $comp_id = !empty($company_id) ? (int)$company_id : null;
        
        foreach ($bom_data as $row) {
            $part_no = trim($row['part_no'] ?? '');
            $part_name = trim($row['part_name'] ?? '') ?: $part_no;
            $qty = (float)($row['req_qty'] ?? 0);
            if (!empty($part_no) && $qty > 0) {
                $loc = !empty($row['location']) ? " [위치: {$row['location']}]" : "";
                $note = "BOM 등록 자동 연계 사급 입고 (WO: {$wo_id}{$loc})";
                $matStmt->execute([$part_no, $part_name, $qty, $wo_id, $comp_id, $note]);
                $inbound_count++;
            }
        }
    }

    $pdo->commit();
    $msg = "BOM 저장 완료";
    if ($auto_inbound && $inbound_count > 0) {
        $msg .= " (사급 자재 {$inbound_count}건 창고 자동 입고 완료)";
    }
    echo json_encode(["status" => "success", "message" => $msg, "inbound_count" => $inbound_count]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

