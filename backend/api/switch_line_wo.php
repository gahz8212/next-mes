<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$line_id = $input['line_id'] ?? '';
$new_wo_id = $input['new_wo_id'] ?? '';

if (!$line_id || !$new_wo_id) {
    echo json_encode(["status" => "error", "message" => "라인 ID와 새 작업지시 번호를 입력하세요."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 새 작업지시가 존재하는지 확인
    $stmt = $pdo->prepare("SELECT bom_id, status FROM work_order WHERE wo_id = ?");
    $stmt->execute([$new_wo_id]);
    $new_wo = $stmt->fetch();

    if (!$new_wo) throw new Exception("존재하지 않는 작업지시입니다.");
    if ($new_wo['status'] === 'DONE') throw new Exception("이미 완료된 작업지시입니다.");

    // 2. 라인 상태 스위칭 (현재 물고 있는 WO를 새로운 WO로 덮어쓰기)
    $updateLineStmt = $pdo->prepare("UPDATE line_status SET current_wo_id = ?, status = 'RUN' WHERE line_id = ?");
    $updateLineStmt->execute([$new_wo_id, $line_id]);

    // 3. 새 작업지시 상태를 IN_PROGRESS로 변경
    $updateWoStmt = $pdo->prepare("UPDATE work_order SET status = 'IN_PROGRESS' WHERE wo_id = ?");
    $updateWoStmt->execute([$new_wo_id]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "긴급 오더({$new_wo_id})로 라인 작업이 교체되었습니다. 기준 BOM이 즉시 변경됩니다."
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>