<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    // 작업지시 목록 + 진행률/수율 데이터를 한 번에 조회
    $stmt = $pdo->query("
        SELECT
            w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id, w.company_id,
            w.completed_at, w.shipped, w.shipped_at,
            c.name as company_name, c.bom_mapping,
            SUM(CASE WHEN b.status != 'WAIT' THEN 1 ELSE 0 END) as processed_qty,
            SUM(CASE WHEN b.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING') THEN 1 ELSE 0 END) as good_qty,
            SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END) as fail_qty,
            SUM(CASE WHEN b.status IN ('SHIPPING','FAIL') THEN 1 ELSE 0 END) as dip_qty
        FROM work_order w
        LEFT JOIN company c ON w.company_id = c.id
        LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
        GROUP BY w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id, w.company_id,
                 w.completed_at, w.shipped, w.shipped_at, c.name, c.bom_mapping
        ORDER BY w.due_date ASC
    ");
    $list = $stmt->fetchAll();

    $ready = [];
    $completed = [];

    // 요약 KPI 집계
    $in_progress_count = 0;
    $today_done_count  = 0;
    $urgent_count      = 0;  // D-7 이내
    $total_good        = 0;
    $total_actual      = 0;
    $today = date('Y-m-d');

    foreach ($list as &$wo) {
        $wo['has_bom']      = !empty($wo['bom_id']);
        $wo['processed_qty'] = (int)$wo['processed_qty'];
        $wo['good_qty']      = (int)$wo['good_qty'];
        $wo['fail_qty']      = (int)$wo['fail_qty'];

        // D-Day 계산
        $wo['dday'] = null;
        if ($wo['due_date']) {
            $diff = (strtotime($wo['due_date']) - strtotime($today)) / 86400;
            $wo['dday'] = (int)$diff;
        }

        if (in_array($wo['status'], ['READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS'])) {
            $ready[] = $wo;
            if (in_array($wo['status'], ['IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS'])) {
                $in_progress_count++;
            }
            if ($wo['dday'] !== null && $wo['dday'] <= 7) {
                $urgent_count++;
            }
        } else {
            $completed[] = $wo;
            // 오늘 완료된 건 (completed_at 기준)
            if ($wo['completed_at'] && substr($wo['completed_at'], 0, 10) === $today) {
                $today_done_count++;
            }
            $total_good   += $wo['good_qty'];
            $total_actual += $wo['processed_qty'];
        }
    }
    unset($wo);

    $overall_yield = $total_actual > 0
        ? round($total_good / $total_actual * 100, 1)
        : null;

    echo json_encode([
        "status" => "success",
        "data"   => [
            "ready"        => $ready,
            "completed"    => $completed,
            "summary"      => [
                "in_progress"   => $in_progress_count,
                "today_done"    => $today_done_count,
                "overall_yield" => $overall_yield,
                "urgent"        => $urgent_count,
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
