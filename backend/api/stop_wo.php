<?php
// backend/api/stop_wo.php - 작업지시 중단 및 READY 복구 API
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

$data = json_decode(file_get_contents('php://input'), true);
$wo_id = $data['wo_id'] ?? null;

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "wo_id가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // 상태를 READY로 롤백
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'READY' WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    // 진행 중이던 바코드 상태도 WAIT로 롤백
    $stmtBc = $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ? AND status = 'IN_PROGRESS'");
    $stmtBc->execute([$wo_id]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "작업지시 [{$wo_id}] 가동이 안전하게 중단되고 대기(READY) 상태로 전환되었습니다."
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
