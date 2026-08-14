<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $status = trim($_GET['status'] ?? '');

    // Summary Query
    $summarySql = "SELECT COUNT(*) as total, SUM(ship_qty) as total_qty,
      SUM(CASE WHEN status='SHIPPED' THEN 1 ELSE 0 END) as shipped_count,
      SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending_count
    FROM shipment
    WHERE DATE(ship_date) BETWEEN :start AND :end";

    $stmtSum = $pdo->prepare($summarySql);
    $stmtSum->execute([
        ':start' => $startDate,
        ':end' => $endDate
    ]);
    $summaryData = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total' => (int)($summaryData['total'] ?? 0),
        'total_qty' => (float)($summaryData['total_qty'] ?? 0),
        'shipped_count' => (int)($summaryData['shipped_count'] ?? 0),
        'pending_count' => (int)($summaryData['pending_count'] ?? 0),
    ];

    // Records Query
    $recordsSql = "SELECT s.*, w.target_qty, c.name as company_name,
      (SELECT SUM(b.status!='WAIT') FROM barcode_master b WHERE b.wo_id=s.wo_id) as processed_qty
    FROM shipment s
    LEFT JOIN work_order w ON s.wo_id = w.wo_id
    LEFT JOIN company c ON s.company_id = c.id
    WHERE DATE(s.ship_date) BETWEEN :start AND :end
      AND (:status = '' OR s.status = :status)
    ORDER BY s.ship_date DESC";

    $stmtRec = $pdo->prepare($recordsSql);
    $stmtRec->execute([
        ':start' => $startDate,
        ':end' => $endDate,
        ':status' => $status
    ]);
    $records = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => [
            "summary" => $summary,
            "records" => $records
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
