<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$order_id = (int)($input['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(["status" => "error", "message" => "수주 ID가 올바르지 않습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 수주 조회
    $stmtOrder = $pdo->prepare("SELECT o.*, c.code as company_code FROM sales_order o LEFT JOIN company c ON o.company_id = c.id WHERE o.id = ?");
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("수주 정보를 찾을 수 없습니다.");
    }
    if (!empty($order['wo_id'])) {
        throw new Exception("이미 작업지시({$order['wo_id']})가 발행된 수주입니다.");
    }

    $cCode = !empty($order['company_code']) ? $order['company_code'] : 'WO';
    $today = date('Ymd');
    $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    $wo_id = "{$cCode}-{$today}-{$rand}";
    $target_qty = (int)$order['order_qty'];
    $due_date = $order['due_date'];
    $company_id = $order['company_id'];

    // 2. 최신 품목 BOM 연동 탐색
    $bom_id = null;
    $bom_version = null;
    $item_name = trim($order['item_name'] ?? '');
    $item_code = trim($order['item_code'] ?? '');

    $stmtItem = $pdo->prepare("SELECT id FROM item WHERE (item_code != '' AND item_code = ?) OR item_name = ? LIMIT 1");
    $stmtItem->execute([$item_code, $item_name]);
    $itemRow = $stmtItem->fetch();

    if ($itemRow) {
        $itemId = (int)$itemRow['id'];
        $stmtBm = $pdo->prepare("SELECT bom_id, version FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
        $stmtBm->execute([$itemId]);
        $bmRow = $stmtBm->fetch();
        if ($bmRow) {
            $bom_id = (int)$bmRow['bom_id'];
            $bom_version = $bmRow['version'];
        }
    }

    // 3. work_order 생성 (BOM 연계)
    $stmtWo = $pdo->prepare("INSERT INTO work_order (wo_id, company_id, target_qty, status, due_date, bom_id) VALUES (?, ?, ?, 'READY', ?, ?)");
    $stmtWo->execute([$wo_id, $company_id, $target_qty, $due_date, $bom_id]);

    // BOM이 상속되었으면 feeder_setup 자동 생성
    if ($bom_id) {
        $stmtDetails = $pdo->prepare("SELECT part_no, req_qty, location, feeder_slot FROM bom_detail WHERE bom_id = ?");
        $stmtDetails->execute([$bom_id]);
        $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        $insFeeder = $pdo->prepare("INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status) VALUES (?, ?, ?, ?, ?, 'PENDING')");
        $slotIdx = 1;
        foreach ($details as $d) {
            $sNo = !empty($d['feeder_slot']) ? (int)$d['feeder_slot'] : $slotIdx++;
            $insFeeder->execute([$wo_id, $sNo, $d['part_no'], $d['location'] ?? '', $d['req_qty'] ?? 1]);
        }
    }

    // 4. 바코드 일괄 생성
    $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
    for ($i = 1; $i <= $target_qty; $i++) {
        $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $barcodeStmt->execute([$barcode, $wo_id]);
    }

    // 4. sales_order 상태 업데이트
    $stmtUpdateOrder = $pdo->prepare("UPDATE sales_order SET wo_id = ?, status = 'IN_PRODUCTION' WHERE id = ?");
    $stmtUpdateOrder->execute([$wo_id, $order_id]);

    // 5. 알림 및 로그 기록
    $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('SUCCESS', '⚡ 작업지시 연계 발행', ?, 'wo')")
        ->execute(["수주({$order['order_no']})로부터 작업지시 {$wo_id} (" . number_format($target_qty) . "EA)가 발행되었습니다."]);

    $pdo->prepare("INSERT INTO system_log (username, action_type, description) VALUES ('admin', 'ORDER_CONVERT_WO', ?)")
        ->execute(["수주-WO 연계: {$order['order_no']} -> {$wo_id} ({$target_qty}EA)"]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "작업지시 [{$wo_id}]가 발행되고 생산 상태로 전환되었습니다.",
        "data" => [
            "wo_id" => $wo_id,
            "target_qty" => $target_qty
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
