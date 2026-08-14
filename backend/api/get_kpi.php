<?php
// backend/api/get_kpi.php - 현재 활성 작업지시 기준 실시간 KPI 조회
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    // 1. 현재 진행 중이거나 대기 중인 작업지시 조회
    $stmt = $pdo->query("
        SELECT
            wo.wo_id,
            wo.target_qty,
            wo.status,
            COALESCE(SUM(CASE WHEN bm.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'DONE') THEN 1 ELSE 0 END), 0) as good_qty,
            COALESCE(SUM(CASE WHEN bm.status IN ('DEFECT', 'FAIL') THEN 1 ELSE 0 END), 0) as fail_qty
        FROM work_order wo
        LEFT JOIN barcode_master bm ON wo.wo_id = bm.wo_id
        WHERE wo.status IN ('IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS')
        GROUP BY wo.wo_id, wo.target_qty, wo.status
        ORDER BY wo.due_date ASC
        LIMIT 1
    ");
    $data = $stmt->fetch();

    if ($data) {
        $good = (int)$data['good_qty'];
        $fail = (int)$data['fail_qty'];
        $total = $good + $fail;
        $yield = $total > 0 ? number_format(($good / $total) * 100, 1) : '100.0';

        echo json_encode([
            "status" => "success",
            "data" => [
                "wo_id"      => $data['wo_id'],
                "target_qty" => (int)$data['target_qty'],
                "actual_qty" => $total,
                "good_qty"   => $good,
                "fail_qty"   => $fail,
                "yield_rate" => $yield . '%'
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 활성 작업이 없을 때: 0으로 클린 초기화
        echo json_encode([
            "status" => "success",
            "data" => [
                "wo_id"      => null,
                "target_qty" => 0,
                "actual_qty" => 0,
                "good_qty"   => 0,
                "fail_qty"   => 0,
                "yield_rate" => "100.0%"
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
