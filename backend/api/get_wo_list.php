<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $stmt = $pdo->query("SELECT wo_id, target_qty, due_date, status FROM work_order WHERE status IN ('READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS') AND bom_id IS NOT NULL ORDER BY due_date ASC");
    $list = $stmt->fetchAll();
    echo json_encode(["status" => "success", "data" => $list]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
