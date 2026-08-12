<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$reel_barcode = $input['reel_barcode'] ?? '';
$line_id = $input['line_id'] ?? '';

if (!$reel_barcode || !$line_id) {
    echo json_encode(["status" => "error", "message" => "데이터 누락"]);
exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT part_no, unsealed_at, status FROM reel_master WHERE reel_barcode = ?");
    $stmt->execute([$reel_barcode]);
    $reel = $stmt->fetch();

    if (!$reel) throw new Exception("미등록 자재입니다.");
    if ($reel['status'] === 'EXPIRED') throw new Exception("MSL 시간 초과! 베이킹이 필요합니다.");

    $is_first_scan = false;
    if (is_null($reel['unsealed_at'])) {
        $pdo->prepare("UPDATE reel_master SET unsealed_at = NOW(), status = 'IN_USE' WHERE reel_barcode = ?")->execute([$reel_barcode]);
        $is_first_scan = true;
    }

    $bomStmt = $pdo->prepare("
        SELECT bd.part_no FROM line_status ls
        JOIN work_order wo ON ls.current_wo_id = wo.wo_id
        JOIN bom_detail bd ON wo.bom_id = bd.bom_id
        WHERE ls.line_id = ? AND bd.part_no = ?
    ");
    $bomStmt->execute([$line_id, $reel['part_no']]);
    if (!$bomStmt->fetch()) throw new Exception("오투입 경고! BOM에 없는 자재입니다.");

    $pdo->commit();
    echo json_encode(["status" => "success", "is_first_scan" => $is_first_scan, "part_no" => $reel['part_no']]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>
