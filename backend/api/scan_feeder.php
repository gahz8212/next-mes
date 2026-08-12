<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$reel_barcode = $input['reel_barcode'] ?? '';
$line_id = $input['line_id'] ?? '';

if (!$reel_barcode || !$line_id) {
    echo json_encode(["status" => "error", "message" => "바코드 또는 라인 정보가 누락되었습니다."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 릴 정보 조회
    $stmt = $pdo->prepare("SELECT part_no, unsealed_at, status FROM reel_master WHERE reel_barcode = ?");
    $stmt->execute([$reel_barcode]);
    $reel = $stmt->fetch();

    if (!$reel) {
        throw new Exception("등록되지 않은 자재 바코드입니다.");
    }

    if ($reel['status'] === 'EXPIRED') {
        throw new Exception("MSL 허용 시간이 초과된 자재입니다. 베이킹 처리가 필요합니다.");
    }

    // 2. 스마트 개봉 로직: unsealed_at이 비어있다면 최초 스캔으로 판단하고 타이머 자동 시작!
    $is_first_scan = false;
    if (is_null($reel['unsealed_at'])) {
        $updateStmt = $pdo->prepare("UPDATE reel_master SET unsealed_at = NOW(), status = 'IN_USE' WHERE reel_barcode = ?");
        $updateStmt->execute([$reel_barcode]);
        $is_first_scan = true;
    }

    // 3. 포카요케 BOM 검증: 현재 라인의 작업지시 BOM에 이 자재가 포함되어 있는지 확인
    $bomStmt = $pdo->prepare("
        SELECT bd.part_no 
        FROM line_status ls
        JOIN work_order wo ON ls.current_wo_id = wo.wo_id
        JOIN bom_detail bd ON wo.bom_id = bd.bom_id
        WHERE ls.line_id = ? AND bd.part_no = ?
    ");
    $bomStmt->execute([$line_id, $reel['part_no']]);
    $matched_bom = $bomStmt->fetch();

    if (!$matched_bom) {
        throw new Exception("오투입 경고! 현재 생산 중인 제품의 BOM에 포함되지 않은 자재입니다.");
    }

    $pdo->commit();

    // 성공 응답
    echo json_encode([
        "status" => "success",
        "message" => "포카요케 통과 및 자재 세팅 완료",
        "is_first_scan" => $is_first_scan,
        "part_no" => $reel['part_no']
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        "status" => "fail",
        "message" => $e->getMessage()
    ]);
}
?>