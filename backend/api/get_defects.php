<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $startDate = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
    $endDate   = !empty($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');

    $params = [
        ':start' => $startDate,
        ':end'   => $endDate
    ];

    // 쿼리4 - 전체 요약
    $stmtSummary = $pdo->prepare("
        SELECT 
          COUNT(CASE WHEN result_status='FAIL' THEN 1 END) as total_fail,
          COUNT(CASE WHEN result_status='PASS' THEN 1 END) as total_pass,
          ROUND(COUNT(CASE WHEN result_status='FAIL' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0),1) as fail_rate
        FROM barcode_history
        WHERE DATE(created_at) BETWEEN :start AND :end
    ");
    $stmtSummary->execute($params);
    $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_fail' => $summaryRow ? (int)$summaryRow['total_fail'] : 0,
        'total_pass' => $summaryRow ? (int)$summaryRow['total_pass'] : 0,
        'fail_rate'  => ($summaryRow && $summaryRow['fail_rate'] !== null) ? (float)$summaryRow['fail_rate'] : 0.0
    ];

    // 쿼리1 - 공정별 불량 집계
    $stmtProcess = $pdo->prepare("
        SELECT 
          bh.process_name,
          COUNT(*) as fail_count,
          ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM barcode_history WHERE result_status='FAIL' AND DATE(created_at) BETWEEN :start AND :end), 1) as ratio
        FROM barcode_history bh
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
        GROUP BY bh.process_name
        ORDER BY fail_count DESC
    ");
    $stmtProcess->execute($params);
    $byProcessRows = $stmtProcess->fetchAll(PDO::FETCH_ASSOC);

    $by_process = [];
    foreach ($byProcessRows as $row) {
        $by_process[] = [
            'process_name' => $row['process_name'],
            'fail_count'   => (int)$row['fail_count'],
            'ratio'        => $row['ratio'] !== null ? (float)$row['ratio'] : 0.0
        ];
    }

    // 쿼리2 - 업체별 불량 집계
    $stmtCompany = $pdo->prepare("
        SELECT 
          c.name as company_name,
          COUNT(bh.history_id) as fail_count,
          SUM(CASE WHEN bm.status IN ('SHIPPING') THEN 1 ELSE 0 END) as good_count
        FROM barcode_history bh
        JOIN barcode_master bm ON bh.barcode = bm.barcode
        JOIN work_order w ON bm.wo_id = w.wo_id
        JOIN company c ON w.company_id = c.id
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
        GROUP BY c.id, c.name
        ORDER BY fail_count DESC
    ");
    $stmtCompany->execute($params);
    $byCompanyRows = $stmtCompany->fetchAll(PDO::FETCH_ASSOC);

    $by_company = [];
    foreach ($byCompanyRows as $row) {
        $by_company[] = [
            'company_name' => $row['company_name'],
            'fail_count'   => (int)$row['fail_count'],
            'good_count'   => (int)$row['good_count']
        ];
    }

    // 쿼리3 - 최근 불량 이력 (최신 50건)
    $stmtRecent = $pdo->prepare("
        SELECT 
          bh.barcode, bh.process_name, bh.created_at,
          w.wo_id, c.name as company_name
        FROM barcode_history bh
        JOIN barcode_master bm ON bh.barcode = bm.barcode
        JOIN work_order w ON bm.wo_id = w.wo_id
        JOIN company c ON w.company_id = c.id
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
        ORDER BY bh.created_at DESC
        LIMIT 50
    ");
    $stmtRecent->execute($params);
    $recentRows = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    $recent = [];
    foreach ($recentRows as $row) {
        $recent[] = [
            'barcode'      => $row['barcode'],
            'process_name' => $row['process_name'],
            'created_at'   => $row['created_at'],
            'wo_id'        => $row['wo_id'],
            'company_name' => $row['company_name']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'summary'    => $summary,
            'by_process' => $by_process,
            'by_company' => $by_company,
            'recent'     => $recent
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
