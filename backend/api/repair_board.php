cat << 'EOF' > backend/api/repair_board.php
<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$barcode = $input['barcode'] ?? '';

if (!$barcode) {
    echo json_encode(["status" => "error", "message" => "바코드를 입력하세요."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 현재 기판 상태 확인 (불량 판정을 받은 기판만 수리 가능)
    $stmt = $pdo->prepare("SELECT status FROM barcode_master WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $target = $stmt->fetch();

    if (!$target) throw new Exception("존재하지 않는 바코드입니다.");
    if ($target['status'] !== 'TEST_FAIL') {
        throw new Exception("불량(TEST_FAIL) 판정을 받은 기판만 수리할 수 있습니다. (현재 상태: " . $target['status'] . ")");
    }

    // 2. 상태를 'REPAIRED' (수리완료)로 변경
    $updateStmt = $pdo->prepare("UPDATE barcode_master SET status = 'REPAIRED' WHERE barcode = ?");
    $updateStmt->execute([$barcode]);

    // 3. [핵심] 수리 이력 추가! (과거 FAIL 기록을 지우지 않고 새 로우(Row)를 추가)
    $historyStmt = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status) VALUES (?, ?, ?)");
    $historyStmt->execute([$barcode, 'REPAIR_PROCESS', 'FIXED']);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "기판 수리가 완료되었습니다. 재검사 라인으로 이동합니다.",
        "barcode" => $barcode
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>
EOF