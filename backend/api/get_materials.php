<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $partNo = trim($_GET['part_no'] ?? '');
    $partNoLike = '%' . $partNo . '%';

    $params = [
        ':start' => $startDate,
        ':end' => $endDate,
        ':part_no' => $partNo,
        ':part_no_like' => $partNoLike
    ];

    // Summary Query
    $summarySql = "SELECT 
      SUM(CASE WHEN inout_type='IN' THEN qty ELSE 0 END) as total_in,
      SUM(CASE WHEN inout_type='OUT' THEN qty ELSE 0 END) as total_out,
      COUNT(DISTINCT part_no) as part_count,
      COUNT(*) as record_count
    FROM material_inout
    WHERE DATE(created_at) BETWEEN :start AND :end
      AND (:part_no = '' OR part_no LIKE :part_no_like)";

    $stmtSum = $pdo->prepare($summarySql);
    $stmtSum->execute($params);
    $summaryData = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_in' => (float)($summaryData['total_in'] ?? 0),
        'total_out' => (float)($summaryData['total_out'] ?? 0),
        'part_count' => (int)($summaryData['part_count'] ?? 0),
        'record_count' => (int)($summaryData['record_count'] ?? 0),
    ];

    // Records Query
    $recordsSql = "SELECT m.*, c.name as company_name
    FROM material_inout m
    LEFT JOIN company c ON m.company_id = c.id
    WHERE DATE(m.created_at) BETWEEN :start AND :end
      AND (:part_no = '' OR m.part_no LIKE :part_no_like)
    ORDER BY m.created_at DESC LIMIT 200";

    $stmtRec = $pdo->prepare($recordsSql);
    $stmtRec->execute($params);
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
