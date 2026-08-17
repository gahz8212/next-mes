<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$wo_id = trim($_GET['wo_id'] ?? '');
$item_id = !empty($_GET['item_id']) ? (int)$_GET['item_id'] : null;

if (!$wo_id && !$item_id) {
    echo json_encode(["status" => "error", "message" => "WO ID 또는 Item ID가 필요합니다."]);
    exit;
}

try {
    $bom_id = null;
    $version = 'v1.0';

    if (!empty($wo_id)) {
        $stmt = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
        $stmt->execute([$wo_id]);
        $wo = $stmt->fetch();
        if ($wo && !empty($wo['bom_id'])) {
            $bom_id = (int)$wo['bom_id'];
        }
    }

    if (!$bom_id && !empty($item_id)) {
        $stmt = $pdo->prepare("SELECT bom_id, version FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
        $stmt->execute([$item_id]);
        $bm = $stmt->fetch();
        if ($bm) {
            $bom_id = (int)$bm['bom_id'];
            $version = $bm['version'] ?: 'v1.0';
        }
    }

    if (!$bom_id) {
        echo json_encode(["status" => "success", "data" => [], "version" => "v1.0"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT part_no, COALESCE(part_name, '') as part_name, req_qty, COALESCE(location, '') as location, feeder_slot FROM bom_detail WHERE bom_id = ?");
    $stmt->execute([$bom_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $details, "bom_id" => $bom_id, "version" => $version], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
