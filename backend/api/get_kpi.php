<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    // 현재 진행 중인 작업지시의 실제 생산 실적을 DB에서 조회
    // totalCount 기준: REFLOW 통과(BOTTOM_DONE 이상) 또는 FAIL 된 기판
    // failCount 기준: FAIL 된 기판
    $stmt = $pdo->query("
        SELECT
            wo.wo_id,
            wo.target_qty,
            SUM(CASE WHEN bm.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'FAIL') THEN 1 ELSE 0 END) as actual_qty,
            SUM(CASE WHEN bm.status = 'FAIL' THEN 1 ELSE 0 END) as fail_qty
        FROM work_order wo
        LEFT JOIN barcode_master bm ON wo.wo_id = bm.wo_id
        WHERE wo.status IN ('IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS')
        GROUP BY wo.wo_id, wo.target_qty
        ORDER BY wo.due_date ASC
        LIMIT 1
    ");
    $data = $stmt->fetch();

    if ($data) {
        echo json_encode([
            "status" => "success",
            "data" => [
                "wo_id"      => $data['wo_id'],
                "target_qty" => (int)$data['target_qty'],
                "actual_qty" => (int)$data['actual_qty'],
                "fail_qty"   => (int)$data['fail_qty'],
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "success", "data" => null]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
