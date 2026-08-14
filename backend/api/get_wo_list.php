<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $stmt = $pdo->query("
        SELECT 
            w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id,
            c.name as company_name,
            (SELECT count(*) FROM feeder_setup fs WHERE fs.wo_id = w.wo_id) as feeder_total,
            (SELECT SUM(CASE WHEN fs.status = 'VERIFIED' THEN 1 ELSE 0 END) FROM feeder_setup fs WHERE fs.wo_id = w.wo_id) as feeder_verified
        FROM work_order w
        LEFT JOIN company c ON w.company_id = c.id
        WHERE w.status IN ('READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS') 
        ORDER BY w.due_date ASC, w.wo_id ASC
    ");
    $list = $stmt->fetchAll();

    foreach ($list as &$item) {
        $total = (int)($item['feeder_total'] ?? 0);
        $verified = (int)($item['feeder_verified'] ?? 0);
        $item['feeder_total'] = $total;
        $item['feeder_verified'] = $verified;
        $item['feeder_ready'] = ($total > 0 && $verified === $total);
    }
    unset($item);

    echo json_encode(["status" => "success", "data" => $list], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
