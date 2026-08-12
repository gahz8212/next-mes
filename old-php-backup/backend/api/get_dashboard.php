<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    // 1. 현재 라인(LINE_01)의 작업지시 진행 상황
    $woStmt = $pdo->query("
        SELECT wo.wo_id, wo.target_qty,
               (SELECT COUNT(*) FROM barcode_master WHERE wo_id = wo.wo_id AND status = 'TEST_PASS') as pass_qty,
               (SELECT COUNT(*) FROM barcode_master WHERE wo_id = wo.wo_id AND status = 'TEST_FAIL') as fail_qty
        FROM line_status ls
        JOIN work_order wo ON ls.current_wo_id = wo.wo_id
        WHERE ls.line_id = 'LINE_01'
    ");
    $woData = $woStmt->fetch();

    // 2. 실시간 최근 공정 통과 로그 (최대 5건)
    $logStmt = $pdo->query("
        SELECT barcode, process_name, result_status, created_at 
        FROM barcode_history 
        ORDER BY created_at DESC LIMIT 5
    ");
    $logs = $logLog = $logStmt->fetchAll();

    echo json_encode([
        "status" => "success",
        "work_order" => $woData ? $woData : null,
        "recent_logs" => $logs
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>