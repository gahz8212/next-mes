<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $startDate  = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate    = $_GET['end_date'] ?? date('Y-m-d');
    $partNo     = trim($_GET['part_no'] ?? '');
    $supplyType = strtoupper(trim($_GET['supply_type'] ?? ''));
    $companyId  = !empty($_GET['company_id']) ? (int)$_GET['company_id'] : null;

    $params = [
        ':start' => $startDate,
        ':end'   => $endDate
    ];

    $cond = " WHERE DATE(m.created_at) BETWEEN :start AND :end";

    if (!empty($partNo)) {
        $cond .= " AND (m.part_no LIKE :part_no_like OR m.part_name LIKE :part_name_like)";
        $params[':part_no_like'] = '%' . $partNo . '%';
        $params[':part_name_like'] = '%' . $partNo . '%';
    }

    if (!empty($supplyType) && in_array($supplyType, ['CONSIGNED', 'PROCURED'])) {
        $cond .= " AND m.supply_type = :supply_type";
        $params[':supply_type'] = $supplyType;
    }

    if (!empty($companyId)) {
        $cond .= " AND m.company_id = :company_id";
        $params[':company_id'] = $companyId;
    }

    // Summary Query
    $summarySql = "SELECT 
      SUM(CASE WHEN m.inout_type='IN' THEN m.qty ELSE 0 END) as total_in,
      SUM(CASE WHEN m.inout_type='OUT' THEN m.qty ELSE 0 END) as total_out,
      SUM(CASE WHEN m.inout_type='IN' AND m.supply_type='CONSIGNED' THEN m.qty ELSE 0 END) as total_consigned,
      SUM(CASE WHEN m.inout_type='IN' AND m.supply_type='PROCURED' THEN m.qty ELSE 0 END) as total_procured,
      COUNT(DISTINCT m.part_no) as part_count,
      COUNT(*) as record_count
    FROM material_inout m
    LEFT JOIN company c ON m.company_id = c.id
    $cond";

    $stmtSum = $pdo->prepare($summarySql);
    $stmtSum->execute($params);
    $summaryData = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_in'         => (float)($summaryData['total_in'] ?? 0),
        'total_out'        => (float)($summaryData['total_out'] ?? 0),
        'total_consigned'  => (float)($summaryData['total_consigned'] ?? 0),
        'total_procured'   => (float)($summaryData['total_procured'] ?? 0),
        'part_count'       => (int)($summaryData['part_count'] ?? 0),
        'record_count'     => (int)($summaryData['record_count'] ?? 0),
    ];

    // Records Query
    $recordsSql = "SELECT m.*, COALESCE(c.name, '자사재고') as company_name
    FROM material_inout m
    LEFT JOIN company c ON m.company_id = c.id
    $cond
    ORDER BY m.created_at DESC LIMIT 200";

    $stmtRec = $pdo->prepare($recordsSql);
    $stmtRec->execute($params);
    $records = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    // Grouped by part_no for Accordion View
    $groupedMap = [];
    foreach ($records as $r) {
        $pNo = $r['part_no'];
        if (!isset($groupedMap[$pNo])) {
            $groupedMap[$pNo] = [
                'part_no'        => $pNo,
                'part_name'      => $r['part_name'] ?: $pNo,
                'unit'           => $r['unit'] ?: 'EA',
                'total_in'       => 0,
                'total_out'      => 0,
                'current_stock'  => 0,
                'consigned_in'   => 0,
                'procured_in'    => 0,
                'history_count'  => 0,
                'items'          => []
            ];
        }
        $qty = (float)$r['qty'];
        if ($r['inout_type'] === 'IN') {
            $groupedMap[$pNo]['total_in'] += $qty;
            if ($r['supply_type'] === 'CONSIGNED') {
                $groupedMap[$pNo]['consigned_in'] += $qty;
            } else {
                $groupedMap[$pNo]['procured_in'] += $qty;
            }
        } else {
            $groupedMap[$pNo]['total_out'] += $qty;
        }
        $groupedMap[$pNo]['history_count']++;
        $groupedMap[$pNo]['items'][] = $r;
    }

    foreach ($groupedMap as &$g) {
        $g['current_stock'] = $g['total_in'] - $g['total_out'];
    }
    unset($g);

    echo json_encode([
        "status" => "success",
        "data" => [
            "summary" => $summary,
            "records" => $records,
            "grouped" => array_values($groupedMap)
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

