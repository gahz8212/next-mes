<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $sql = "SELECT 
  c.id, c.name, c.code, c.tel, c.email,
  COUNT(w.wo_id) as total_wo,
  SUM(CASE WHEN w.status = 'DONE' THEN 1 ELSE 0 END) as done_wo,
  SUM(CASE WHEN w.status NOT IN ('DONE') THEN 1 ELSE 0 END) as active_wo,
  SUM(w.target_qty) as total_qty,
  MAX(w.due_date) as last_due_date
FROM company c
LEFT JOIN work_order w ON c.id = w.company_id
GROUP BY c.id, c.name, c.code, c.tel, c.email
ORDER BY c.name ASC";

    $stmt = $pdo->query($sql);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $stats], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
