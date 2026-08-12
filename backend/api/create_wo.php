<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$target_qty = $input['target_qty'] ?? 0;

if (!$wo_id || $target_qty <= 0) {
    echo json_encode(["status" => "error", "message" => "WO ID와 수량을 입력하세요."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. BOM 존재 확인 (없으면 테스트용 자동 생성)
    $stmt = $pdo->query("SELECT bom_id FROM bom_master LIMIT 1");
    $bom = $stmt->fetch();
    
    if (!$bom) {
        $pdo->exec("INSERT INTO product_master (product_id, product_name) VALUES ('PROD-A', '표준 양산 테스트 보드')");
        $pdo->exec("INSERT INTO bom_master (product_id) VALUES ('PROD-A')");
        $bom_id = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO bom_detail (bom_id, part_no, req_qty) VALUES ($bom_id, 'RES-10K', 2.0)");
    } else {
        $bom_id = $bom['bom_id'];
    }

    // 2. 작업지시 등록
    $stmt = $pdo->prepare("INSERT INTO work_order (wo_id, bom_id, target_qty, status) VALUES (?, ?, ?, 'READY')");
    $stmt->execute([$wo_id, $bom_id, $target_qty]);

    // 3. 바코드 일괄 발행
    $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
    for ($i = 1; $i <= $target_qty; $i++) {
        $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $barcodeStmt->execute([$barcode, $wo_id]);
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "{$target_qty}개 바코드 발행 완료"]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => $e->getMessage()]);
}
?>