<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$wo_id = $_GET['wo_id'] ?? '';
if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "WO ID가 필요합니다."]);
    exit;
}

try {
    // get bom_id from work_order
    $stmt = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();
    
    if (!$wo || empty($wo['bom_id'])) {
        echo json_encode(["status" => "success", "data" => []]); // No BOM
        exit;
    }

    $bom_id = $wo['bom_id'];
    $stmt = $pdo->prepare("SELECT part_no, req_qty, location FROM bom_detail WHERE bom_id = ?");
    $stmt->execute([$bom_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $details]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
