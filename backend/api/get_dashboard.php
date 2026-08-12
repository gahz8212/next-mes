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

    // 2. 실시간 최근 공정 통과 로그 (최대 8건)
    $logStmt = $pdo->query("
        SELECT barcode, process_name, result_status, DATE_FORMAT(created_at, '%H:%i:%s') as created_at 
        FROM barcode_history 
        ORDER BY created_at DESC LIMIT 8
    ");
    $logs = $logStmt->fetchAll();

    // 3. 누적 생산량 (History 테이블 전체 기준)
    $countStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN result_status = 'PASS' THEN 1 ELSE 0 END) as pass_count
        FROM barcode_history
    ");
    $counts = $countStmt->fetch();

    echo json_encode([
        "status" => "success",
        "data" => [
            "total_count" => (int)$counts['total_count'],
            "pass_count" => (int)$counts['pass_count'],
            "history" => $logs
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>