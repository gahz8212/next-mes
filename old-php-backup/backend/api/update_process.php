<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$barcode = $input['barcode'] ?? '';
$process_name = $input['process_name'] ?? ''; // 예: SMT_TOP, SMT_BOTTOM, ICT_TEST
$result_status = $input['result_status'] ?? 'PASS'; // PASS 또는 FAIL

if (!$barcode || !$process_name) {
    echo json_encode(["status" => "error", "message" => "바코드 또는 공정명이 누락되었습니다."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 바코드 존재 여부 및 현재 상태 확인
    $stmt = $pdo->prepare("SELECT status FROM barcode_master WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $target = $stmt->fetch();

    if (!$target) {
        throw new Exception("존재하지 않는 기판 바코드입니다.");
    }

    // 2. 공정별 상태 전이(Transition) 규칙 정의
    $new_status = $target['status'];
    
    if ($process_name === 'SMT_TOP' && $result_status === 'PASS') {
        $new_status = 'TOP_DONE';
    } else if ($process_name === 'SMT_BOTTOM' && $result_status === 'PASS') {
        $new_status = 'BOTTOM_DONE';
    } else if ($process_name === 'ICT_TEST') {
        $new_status = ($result_status === 'PASS') ? 'TEST_PASS' : 'TEST_FAIL';
    }

    // 3. barcode_master 상태 업데이트
    $updateStmt = $pdo->prepare("UPDATE barcode_master SET status = ? WHERE barcode = ?");
    $updateStmt->execute([$new_status, $barcode]);

    // 4. [핵심 규칙] 상태 덮어쓰기 금지! barcode_history에 무조건 이력 기록
    $historyStmt = $pdo->prepare("
        INSERT INTO barcode_history (barcode, process_name, result_status) 
        VALUES (?, ?, ?)
    ");
    $historyStmt->execute([$barcode, $process_name, $result_status]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "공정 처리 완료",
        "barcode" => $barcode,
        "current_status" => $new_status,
        "result" => $result_status
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        "status" => "fail",
        "message" => $e->getMessage()
    ]);
}
?>