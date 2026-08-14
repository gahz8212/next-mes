<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 14;
    if ($days <= 0 || $days > 90) $days = 14;

    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    $endDate   = date('Y-m-d');

    // 1. 종합 누적 통계
    $stmtOverall = $pdo->query("
        SELECT
            COUNT(DISTINCT w.wo_id) as total_wo,
            SUM(w.target_qty) as total_target_qty,
            SUM(CASE WHEN b.status != 'WAIT' THEN 1 ELSE 0 END) as total_processed,
            SUM(CASE WHEN b.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING') THEN 1 ELSE 0 END) as total_good,
            SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END) as total_fail
        FROM work_order w
        LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
    ");
    $overall = $stmtOverall->fetch(PDO::FETCH_ASSOC);

    $totalGood = (int)($overall['total_good'] ?? 0);
    $totalFail = (int)($overall['total_fail'] ?? 0);
    $totalProcessed = (int)($overall['total_processed'] ?? 0);
    $overallYield = $totalProcessed > 0 ? round(($totalGood / $totalProcessed) * 100, 1) : 100.0;

    // 2. 납기 준수율 (On-Time Delivery Rate)
    $stmtDelivery = $pdo->query("
        SELECT 
            COUNT(*) as completed_total,
            SUM(CASE WHEN due_date IS NOT NULL AND completed_at IS NOT NULL AND DATE(completed_at) <= due_date THEN 1 ELSE 0 END) as on_time_count
        FROM work_order
        WHERE status = 'DONE'
    ");
    $delivery = $stmtDelivery->fetch(PDO::FETCH_ASSOC);
    $completedTotal = (int)($delivery['completed_total'] ?? 0);
    $onTimeCount    = (int)($delivery['on_time_count'] ?? 0);
    $onTimeRate     = $completedTotal > 0 ? round(($onTimeCount / $completedTotal) * 100, 1) : 100.0;

    // 3. 일별 생산량 및 수율 추이 (Daily Trend)
    $stmtDaily = $pdo->prepare("
        SELECT 
            DATE(created_at) as log_date,
            SUM(CASE WHEN result_status = 'PASS' THEN 1 ELSE 0 END) as pass_count,
            SUM(CASE WHEN result_status = 'FAIL' THEN 1 ELSE 0 END) as fail_count,
            COUNT(*) as total_count
        FROM barcode_history
        WHERE DATE(created_at) BETWEEN :start AND :end
        GROUP BY DATE(created_at)
        ORDER BY log_date ASC
    ");
    $stmtDaily->execute([':start' => $startDate, ':end' => $endDate]);
    $dailyRows = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

    $dailyTrend = [];
    foreach ($dailyRows as $row) {
        $pass = (int)$row['pass_count'];
        $fail = (int)$row['fail_count'];
        $tot  = (int)$row['total_count'];
        $yield = $tot > 0 ? round(($pass / $tot) * 100, 1) : 100.0;
        $dailyTrend[] = [
            'date'  => $row['log_date'],
            'pass'  => $pass,
            'fail'  => $fail,
            'total' => $tot,
            'yield' => $yield
        ];
    }

    // 4. 라인 가동 상태
    $stmtLine = $pdo->query("SELECT * FROM line_status ORDER BY line_id ASC");
    $lines = $stmtLine->fetchAll(PDO::FETCH_ASSOC);

    // 5. 공정별 생산량 점유율
    $stmtProcess = $pdo->prepare("
        SELECT 
            process_name,
            COUNT(*) as count,
            SUM(CASE WHEN result_status = 'FAIL' THEN 1 ELSE 0 END) as fail_count
        FROM barcode_history
        WHERE DATE(created_at) BETWEEN :start AND :end
        GROUP BY process_name
        ORDER BY count DESC
    ");
    $stmtProcess->execute([':start' => $startDate, ':end' => $endDate]);
    $processStats = $stmtProcess->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "summary" => [
                "total_wo"        => (int)($overall['total_wo'] ?? 0),
                "total_target"    => (int)($overall['total_target_qty'] ?? 0),
                "total_processed" => $totalProcessed,
                "total_good"      => $totalGood,
                "total_fail"      => $totalFail,
                "overall_yield"   => $overallYield,
                "on_time_rate"    => $onTimeRate,
                "completed_total" => $completedTotal,
                "on_time_count"   => $onTimeCount
            ],
            "daily_trend"    => $dailyTrend,
            "lines"          => $lines,
            "process_stats"  => $processStats,
            "period"         => [
                "start" => $startDate,
                "end"   => $endDate,
                "days"  => $days
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
