<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $year  = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : (int)date('m');

    $stmt = $pdo->prepare("
        SELECT 
          w.wo_id, w.target_qty, w.status, w.due_date, w.completed_at,
          w.shipped, w.shipped_at,
          c.name as company_name,
          SUM(CASE WHEN b.status != 'WAIT' THEN 1 ELSE 0 END) as processed_qty,
          SUM(CASE WHEN b.status IN ('SHIPPING') THEN 1 ELSE 0 END) as good_qty,
          SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END) as fail_qty
        FROM work_order w
        LEFT JOIN company c ON w.company_id = c.id
        LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
        WHERE YEAR(w.due_date) = :year AND (:month = 0 OR MONTH(w.due_date) = :month)
        GROUP BY w.wo_id, w.target_qty, w.status, w.due_date, w.completed_at, w.shipped, w.shipped_at, c.name
        ORDER BY w.due_date ASC
    ");
    $stmt->execute([
        ':year'  => $year,
        ':month' => $month
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $orders = [];
    foreach ($rows as $row) {
        $orders[] = [
            'wo_id'         => $row['wo_id'],
            'target_qty'    => (int)$row['target_qty'],
            'status'        => $row['status'],
            'due_date'      => $row['due_date'],
            'completed_at'  => $row['completed_at'],
            'shipped'       => (int)$row['shipped'],
            'shipped_at'    => $row['shipped_at'],
            'company_name'  => $row['company_name'],
            'processed_qty' => (int)$row['processed_qty'],
            'good_qty'      => (int)$row['good_qty'],
            'fail_qty'      => (int)$row['fail_qty']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'year'   => $year,
            'month'  => $month,
            'orders' => $orders
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
