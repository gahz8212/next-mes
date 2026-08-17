<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $startDate   = !empty($_GET['start_date'])   ? $_GET['start_date']   : date('Y-m-d', strtotime('-30 days'));
    $endDate     = !empty($_GET['end_date'])     ? $_GET['end_date']     : date('Y-m-d');
    $process     = !empty($_GET['process'])      ? trim($_GET['process']) : '';
    $companyName = !empty($_GET['company_name']) ? trim($_GET['company_name']) : '';

    $params = [
        ':start' => $startDate,
        ':end'   => $endDate
    ];

    // 조건절 동적 생성
    $procCond = "";
    if (!empty($process)) {
        $procCond = " AND bh.process_name = :process";
        $params[':process'] = $process;
    }

    $compCond = "";
    if (!empty($companyName)) {
        $compCond = " AND c.name = :company_name";
        $params[':company_name'] = $companyName;
    }

    // 1. 전체 요약 (Summary)
    $sqlSummary = "
        SELECT 
          COUNT(CASE WHEN bh.result_status='FAIL' THEN 1 END) as total_fail,
          COUNT(CASE WHEN bh.result_status='PASS' THEN 1 END) as total_pass,
          ROUND(COUNT(CASE WHEN bh.result_status='FAIL' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0),1) as fail_rate
        FROM barcode_history bh
        LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
        LEFT JOIN work_order w ON bm.wo_id = w.wo_id
        LEFT JOIN company c ON w.company_id = c.id
        WHERE DATE(bh.created_at) BETWEEN :start AND :end
    ";
    if (!empty($process))     $sqlSummary .= " AND bh.process_name = :process";
    if (!empty($companyName)) $sqlSummary .= " AND c.name = :company_name";

    $stmtSummary = $pdo->prepare($sqlSummary);
    $stmtSummary->execute($params);
    $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_fail' => $summaryRow ? (int)$summaryRow['total_fail'] : 0,
        'total_pass' => $summaryRow ? (int)$summaryRow['total_pass'] : 0,
        'fail_rate'  => ($summaryRow && $summaryRow['fail_rate'] !== null) ? (float)$summaryRow['fail_rate'] : 0.0
    ];

    // 2. 공정별 불량 집계
    $sqlProcess = "
        SELECT 
          bh.process_name,
          COUNT(*) as fail_count
        FROM barcode_history bh
        LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
        LEFT JOIN work_order w ON bm.wo_id = w.wo_id
        LEFT JOIN company c ON w.company_id = c.id
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
    ";
    if (!empty($companyName)) $sqlProcess .= " AND c.name = :company_name";
    $sqlProcess .= " GROUP BY bh.process_name ORDER BY fail_count DESC";

    $stmtProcess = $pdo->prepare($sqlProcess);
    $procParams = [':start' => $startDate, ':end' => $endDate];
    if (!empty($companyName)) $procParams[':company_name'] = $companyName;
    $stmtProcess->execute($procParams);
    $byProcessRows = $stmtProcess->fetchAll(PDO::FETCH_ASSOC);

    $totalProcFails = array_sum(array_column($byProcessRows, 'fail_count'));
    $by_process = [];
    foreach ($byProcessRows as $row) {
        $cnt = (int)$row['fail_count'];
        $by_process[] = [
            'process_name' => $row['process_name'],
            'fail_count'   => $cnt,
            'ratio'        => $totalProcFails > 0 ? round($cnt * 100.0 / $totalProcFails, 1) : 0.0
        ];
    }

    // 3. 업체별 불량 집계
    $sqlCompany = "
        SELECT 
          COALESCE(c.name, '기타') as company_name,
          COUNT(bh.history_id) as fail_count,
          SUM(CASE WHEN bm.status IN ('SHIPPING') THEN 1 ELSE 0 END) as good_count
        FROM barcode_history bh
        JOIN barcode_master bm ON bh.barcode = bm.barcode
        JOIN work_order w ON bm.wo_id = w.wo_id
        JOIN company c ON w.company_id = c.id
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
    ";
    if (!empty($process)) $sqlCompany .= " AND bh.process_name = :process";
    $sqlCompany .= " GROUP BY c.id, c.name ORDER BY fail_count DESC";

    $stmtCompany = $pdo->prepare($sqlCompany);
    $compParams = [':start' => $startDate, ':end' => $endDate];
    if (!empty($process)) $compParams[':process'] = $process;
    $stmtCompany->execute($compParams);
    $byCompanyRows = $stmtCompany->fetchAll(PDO::FETCH_ASSOC);

    $by_company = [];
    foreach ($byCompanyRows as $row) {
        $by_company[] = [
            'company_name' => $row['company_name'],
            'fail_count'   => (int)$row['fail_count'],
            'good_count'   => (int)$row['good_count']
        ];
    }

    // 4. 최근 불량 이력 (최신 50건)
    $sqlRecent = "
        SELECT 
          bh.barcode, bh.process_name, bh.created_at,
          w.wo_id, COALESCE(c.name, '미지정') as company_name
        FROM barcode_history bh
        JOIN barcode_master bm ON bh.barcode = bm.barcode
        JOIN work_order w ON bm.wo_id = w.wo_id
        JOIN company c ON w.company_id = c.id
        WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
    ";
    if (!empty($process))     $sqlRecent .= " AND bh.process_name = :process";
    if (!empty($companyName)) $sqlRecent .= " AND c.name = :company_name";
    $sqlRecent .= " ORDER BY DATE(bh.created_at) DESC, c.name ASC, bh.barcode ASC LIMIT 50";

    $stmtRecent = $pdo->prepare($sqlRecent);
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

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => '불량 현황 데이터 조회 실패: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
