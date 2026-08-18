<?php
// backend/controllers/OrderController.php

class OrderController {
    /**
     * DB 스키마 자동 동기화 및 마이그레이션
     */
    private static function ensureSchema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_order_item (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                order_no VARCHAR(50) NOT NULL,
                item_code VARCHAR(50) DEFAULT NULL,
                item_name VARCHAR(100) NOT NULL,
                order_qty INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) DEFAULT 0,
                total_price DECIMAL(14,2) DEFAULT 0,
                due_date DATE DEFAULT NULL,
                status ENUM('RECEIVED', 'IN_PRODUCTION', 'COMPLETED', 'CANCELLED') DEFAULT 'RECEIVED',
                wo_id VARCHAR(50) DEFAULT NULL,
                memo TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_soi_order_id (order_id),
                INDEX idx_soi_order_no (order_no),
                INDEX idx_soi_wo_id (wo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
        ");

        try {
            $pdo->exec("ALTER TABLE item ADD COLUMN unit_price DECIMAL(12,2) DEFAULT 0 AFTER unit");
        } catch (\Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE item ADD COLUMN status VARCHAR(20) DEFAULT 'ACTIVE' AFTER unit_price");
        } catch (\Exception $e) {}

        // 기존 1:1 sales_order 데이터를 1:N sales_order_item으로 마이그레이션 (필요시)
        $chk = $pdo->query("SELECT COUNT(*) FROM sales_order_item")->fetchColumn();
        if ((int)$chk === 0) {
            $existingOrders = $pdo->query("SELECT * FROM sales_order WHERE item_name IS NOT NULL AND item_name != ''")->fetchAll();
            $insItem = $pdo->prepare("
                INSERT INTO sales_order_item (order_id, order_no, item_code, item_name, order_qty, unit_price, total_price, due_date, status, wo_id, memo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($existingOrders as $eo) {
                $insItem->execute([
                    $eo['id'],
                    $eo['order_no'],
                    $eo['item_code'] ?? '',
                    $eo['item_name'],
                    $eo['order_qty'] ?: 1,
                    $eo['unit_price'] ?: 0,
                    $eo['total_price'] ?: 0,
                    $eo['due_date'] ?? null,
                    $eo['status'] ?: 'RECEIVED',
                    $eo['wo_id'] ?: null,
                    $eo['memo'] ?? null,
                    $eo['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * 1. 수주 목록 및 KPI 조회 (1:N 복수 품목 포함)
     */
    public static function getOrders(): void {
        try {
            $pdo = Database::getConnection();
            self::ensureSchema($pdo);

            $status    = Request::query('status', '');
            $companyId = Request::query('company_id', '');
            $search    = trim(Request::query('search', ''));

            $where = ["1=1"];
            $params = [];

            if ($status !== '') {
                $where[] = "o.status = :status";
                $params[':status'] = $status;
            }
            if ($companyId !== '') {
                $where[] = "o.company_id = :company_id";
                $params[':company_id'] = (int)$companyId;
            }
            if ($search !== '') {
                $where[] = "(o.order_no LIKE :search OR c.name LIKE :search OR EXISTS (SELECT 1 FROM sales_order_item soi WHERE soi.order_id = o.id AND (soi.item_name LIKE :search2 OR soi.item_code LIKE :search3 OR soi.wo_id LIKE :search4)))";
                $params[':search']  = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
                $params[':search3'] = '%' . $search . '%';
                $params[':search4'] = '%' . $search . '%';
            }

            $whereSql = implode(" AND ", $where);

            // 1. 수주 마스터 목록 조회
            $sql = "
                SELECT 
                    o.id, o.order_no, o.company_id, o.order_date, o.due_date, o.status, o.memo, o.created_at,
                    c.name as company_name, c.code as company_code,
                    COALESCE((SELECT COUNT(*) FROM sales_order_item soi WHERE soi.order_id = o.id), 0) as item_count,
                    COALESCE((SELECT SUM(soi.order_qty) FROM sales_order_item soi WHERE soi.order_id = o.id), o.order_qty, 0) as total_qty,
                    COALESCE((SELECT SUM(soi.total_price) FROM sales_order_item soi WHERE soi.order_id = o.id), o.total_price, 0) as total_price
                FROM sales_order o
                LEFT JOIN company c ON o.company_id = c.id
                WHERE {$whereSql}
                ORDER BY o.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            // 2. 각 수주별 세부 품목 목록 조회
            $orderIds = array_column($orders, 'id');
            $itemsByOrder = [];
            if (!empty($orderIds)) {
                $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
                $itemStmt = $pdo->prepare("
                    SELECT 
                        soi.*,
                        w.status as wo_status,
                        w.target_qty as wo_target_qty,
                        (SELECT COALESCE(SUM(b.status != 'WAIT'), 0) FROM barcode_master b WHERE b.wo_id = soi.wo_id) as wo_processed_qty,
                        (SELECT COALESCE(SUM(b.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING')), 0) FROM barcode_master b WHERE b.wo_id = soi.wo_id) as wo_good_qty
                    FROM sales_order_item soi
                    LEFT JOIN work_order w ON soi.wo_id = w.wo_id
                    WHERE soi.order_id IN ($inPlaceholders)
                    ORDER BY soi.id ASC
                ");
                $itemStmt->execute($orderIds);
                $allItems = $itemStmt->fetchAll();
                foreach ($allItems as $it) {
                    $itemsByOrder[$it['order_id']][] = $it;
                }
            }

            foreach ($orders as &$ord) {
                $ord['items'] = $itemsByOrder[$ord['id']] ?? [];
                // 만약 sales_order_item에 품목이 없으면 레거시 sales_order 단일 품목을 가상 품목으로 포팅
                if (empty($ord['items']) && !empty($ord['item_name'])) {
                    $ord['items'] = [[
                        'id' => 0,
                        'order_id' => $ord['id'],
                        'order_no' => $ord['order_no'],
                        'item_code' => $ord['item_code'] ?? '',
                        'item_name' => $ord['item_name'],
                        'order_qty' => (int)($ord['order_qty'] ?? 1),
                        'unit_price' => (float)($ord['unit_price'] ?? 0),
                        'total_price' => (float)($ord['total_price'] ?? 0),
                        'due_date' => $ord['due_date'],
                        'status' => $ord['status'],
                        'wo_id' => $ord['wo_id'] ?? null
                    ]];
                }
            }
            unset($ord);

            // 3. 수주 KPI 집계
            $stmtKpi = $pdo->query("
                SELECT 
                    COUNT(DISTINCT o.id) as total_orders,
                    COALESCE(SUM(soi.order_qty), 0) as total_qty,
                    COALESCE(SUM(soi.total_price), 0) as total_amount,
                    COUNT(DISTINCT soi.id) as total_items,
                    SUM(CASE WHEN o.status = 'RECEIVED' THEN 1 ELSE 0 END) as received_count,
                    SUM(CASE WHEN o.status = 'IN_PRODUCTION' THEN 1 ELSE 0 END) as in_prod_count,
                    SUM(CASE WHEN o.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_count
                FROM sales_order o
                LEFT JOIN sales_order_item soi ON o.id = soi.order_id
            ");
            $kpi = $stmtKpi->fetch();

            Response::json([
                "status" => "success",
                "data"   => [
                    "kpi"    => [
                        "total_orders"    => (int)($kpi['total_orders'] ?? 0),
                        "total_items"     => (int)($kpi['total_items'] ?? 0),
                        "total_qty"       => (int)($kpi['total_qty'] ?? 0),
                        "total_amount"    => (float)($kpi['total_amount'] ?? 0),
                        "received_count"  => (int)($kpi['received_count'] ?? 0),
                        "in_prod_count"   => (int)($kpi['in_prod_count'] ?? 0),
                        "completed_count" => (int)($kpi['completed_count'] ?? 0),
                    ],
                    "orders" => $orders
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 수주 등록 (복수 품목 지원)
     */
    public static function createOrder(): void {
        $pdo = Database::getConnection();
        try {
            self::ensureSchema($pdo);
            $input = Request::getBody();

            $company_id  = (int)($input['company_id'] ?? 0);
            $order_no    = trim($input['order_no'] ?? '');
            $order_date  = !empty($input['order_date']) ? $input['order_date'] : date('Y-m-d');
            $due_date    = !empty($input['due_date']) ? $input['due_date'] : null;
            $memo        = trim($input['memo'] ?? '');
            $items       = $input['items'] ?? [];

            if ($company_id <= 0 || empty($due_date)) {
                Response::error("발주 고객사 및 기본 납기일은 필수 입력값입니다.");
            }

            if (empty($items) || !is_array($items)) {
                // 단일 품목 레거시 입력 지원
                $legacyName = trim($input['item_name'] ?? '');
                $legacyQty  = (int)($input['order_qty'] ?? 0);
                if (!empty($legacyName) && $legacyQty > 0) {
                    $items = [[
                        'item_code'  => trim($input['item_code'] ?? ''),
                        'item_name'  => $legacyName,
                        'order_qty'  => $legacyQty,
                        'unit_price' => (float)($input['unit_price'] ?? 0),
                        'due_date'   => $due_date,
                        'memo'       => $memo
                    ]];
                } else {
                    Response::error("수주에 포함될 품목을 최소 1개 이상 등록해주세요.");
                }
            }

            if (empty($order_no)) {
                $order_no = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
            }

            // 중복 수주번호 검증
            $chk = $pdo->prepare("SELECT id FROM sales_order WHERE order_no = ?");
            $chk->execute([$order_no]);
            if ($chk->fetch()) {
                Response::error("이미 존재하는 수주 번호 [{$order_no}] 입니다.");
            }

            $pdo->beginTransaction();

            // 총 수량 및 총 공급가 계산
            $totalQty = 0;
            $totalAmount = 0;
            foreach ($items as $it) {
                $qty = max(1, (int)($it['order_qty'] ?? 1));
                $price = max(0, (float)($it['unit_price'] ?? 0));
                $totalQty += $qty;
                $totalAmount += ($qty * $price);
            }

            // 1. sales_order 마스터 생성
            $firstItem = $items[0];
            $stmt = $pdo->prepare("
                INSERT INTO sales_order (order_no, company_id, item_code, item_name, order_qty, unit_price, total_price, order_date, due_date, status, memo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RECEIVED', ?)
            ");
            $stmt->execute([
                $order_no, 
                $company_id, 
                $firstItem['item_code'] ?? null, 
                $firstItem['item_name'] ?? '복수 품목 수주', 
                $totalQty, 
                0, 
                $totalAmount, 
                $order_date, 
                $due_date, 
                $memo
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // 2. sales_order_item 세부 품목 일괄 등록
            $insItemStmt = $pdo->prepare("
                INSERT INTO sales_order_item (order_id, order_no, item_code, item_name, order_qty, unit_price, total_price, due_date, status, memo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'RECEIVED', ?)
            ");

            $chkItemMaster = $pdo->prepare("SELECT id, item_code FROM item WHERE item_name = ? LIMIT 1");
            $insItemMaster = $pdo->prepare("INSERT INTO item (company_id, item_code, item_name, unit_price, status) VALUES (?, ?, ?, ?, 'ACTIVE')");

            foreach ($items as $it) {
                $iName = trim($it['item_name'] ?? '');
                if (empty($iName)) continue;

                $iCode  = trim($it['item_code'] ?? '');
                $iQty   = max(1, (int)($it['order_qty'] ?? 1));
                $iPrice = max(0, (float)($it['unit_price'] ?? 0));
                $iTotal = $iQty * $iPrice;
                $iDue   = !empty($it['due_date']) ? $it['due_date'] : $due_date;
                $iMemo  = trim($it['memo'] ?? '');

                // 품목 마스터 자동 등록 및 item_code 보계
                $chkItemMaster->execute([$iName]);
                $matchedItem = $chkItemMaster->fetch();
                if ($matchedItem) {
                    if (empty($iCode)) {
                        $iCode = $matchedItem['item_code'] ?: '';
                    }
                } else {
                    if (empty($iCode)) {
                        $randHex = strtoupper(bin2hex(random_bytes(2)));
                        $iCode = 'ITM-' . date('Ymd') . '-' . $randHex;
                    }
                    $insItemMaster->execute([$company_id ?: null, $iCode, $iName, $iPrice ?: 0]);
                }

                $insItemStmt->execute([
                    $orderId,
                    $order_no,
                    $iCode ?: null,
                    $iName,
                    $iQty,
                    $iPrice,
                    $iTotal,
                    $iDue,
                    $iMemo ?: null
                ]);
            }

            $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('INFO', '📦 신규 수주 등록', ?, 'order')")
                ->execute(["신규 수주 {$order_no} (총 " . count($items) . "종 품목, " . number_format($totalQty) . "EA)가 접수되었습니다."]);

            $pdo->prepare("INSERT INTO system_log (username, action_type, description) VALUES ('admin', 'ORDER_CREATE', ?)")
                ->execute(["수주 등록: {$order_no} (품목 " . count($items) . "종, 총 수량: {$totalQty}, 납기일: {$due_date})"]);

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "수주 [{$order_no}] (총 " . count($items) . "개 품목)가 성공적으로 등록되었습니다.",
                "data"   => [
                    "id"       => $orderId,
                    "order_no" => $order_no
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 수주 수정 (복수 품목 지원)
     */
    public static function updateOrder(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            self::ensureSchema($pdo);
            $input = Request::getBody();

            $orderId     = (int)($id ?? ($input['id'] ?? 0));
            $company_id  = (int)($input['company_id'] ?? 0);
            $due_date    = !empty($input['due_date']) ? $input['due_date'] : null;
            $status      = trim($input['status'] ?? 'RECEIVED');
            $memo        = trim($input['memo'] ?? '');
            $items       = $input['items'] ?? [];

            if ($orderId <= 0 || $company_id <= 0 || empty($due_date)) {
                Response::error("필수 입력 항목이 누락되었습니다.");
            }

            $pdo->beginTransaction();

            $stmtOrder = $pdo->prepare("SELECT order_no FROM sales_order WHERE id = ? FOR UPDATE");
            $stmtOrder->execute([$orderId]);
            $ordRow = $stmtOrder->fetch();
            if (!$ordRow) {
                throw new Exception("존재하지 않는 수주입니다.");
            }
            $order_no = $ordRow['order_no'];

            // 품목 리스트가 전달된 경우 갱신
            if (!empty($items) && is_array($items)) {
                // 이미 작업지시(WO)가 발행된 품목은 삭제되지 않도록 보존
                $existingItems = $pdo->prepare("SELECT * FROM sales_order_item WHERE order_id = ?");
                $existingItems->execute([$orderId]);
                $oldItems = $existingItems->fetchAll();

                $totalQty = 0;
                $totalAmount = 0;

                // 기존 미발행 품목 삭제 후 재등록
                $delStmt = $pdo->prepare("DELETE FROM sales_order_item WHERE order_id = ? AND (wo_id IS NULL OR wo_id = '')");
                $delStmt->execute([$orderId]);

                $insStmt = $pdo->prepare("
                    INSERT INTO sales_order_item (order_id, order_no, item_code, item_name, order_qty, unit_price, total_price, due_date, status, memo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($items as $it) {
                    $iName = trim($it['item_name'] ?? '');
                    if (empty($iName)) continue;

                    $iQty   = max(1, (int)($it['order_qty'] ?? 1));
                    $iPrice = max(0, (float)($it['unit_price'] ?? 0));
                    $iTotal = $iQty * $iPrice;
                    $iDue   = !empty($it['due_date']) ? $it['due_date'] : $due_date;
                    $iMemo  = trim($it['memo'] ?? '');
                    $iCode  = trim($it['item_code'] ?? '');

                    // 기존에 WO가 발행되어 보존된 품목인지 확인
                    $alreadyAssigned = false;
                    foreach ($oldItems as $old) {
                        if (!empty($old['wo_id']) && $old['item_name'] === $iName && (int)$old['id'] === (int)($it['id'] ?? 0)) {
                            $alreadyAssigned = true;
                            $totalQty += (int)$old['order_qty'];
                            $totalAmount += (float)$old['total_price'];
                            break;
                        }
                    }

                    if (!$alreadyAssigned) {
                        $insStmt->execute([
                            $orderId,
                            $order_no,
                            $iCode ?: null,
                            $iName,
                            $iQty,
                            $iPrice,
                            $iTotal,
                            $iDue,
                            $status,
                            $iMemo ?: null
                        ]);
                        $totalQty += $iQty;
                        $totalAmount += $iTotal;
                    }
                }

                $stmtUpdate = $pdo->prepare("
                    UPDATE sales_order 
                    SET company_id = ?, total_price = ?, due_date = ?, status = ?, memo = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$company_id, $totalAmount, $due_date, $status, $memo, $orderId]);
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE sales_order 
                    SET company_id = ?, due_date = ?, status = ?, memo = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$company_id, $due_date, $status, $memo, $orderId]);
            }

            $pdo->commit();
            Response::json(["status" => "success", "message" => "수주 정보가 성공적으로 수정되었습니다."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 수주 삭제
     */
    public static function deleteOrder(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            self::ensureSchema($pdo);

            $input = Request::getBody();
            $orderId = (int)($id ?? ($input['id'] ?? 0));

            if ($orderId <= 0) {
                Response::error("유효하지 않은 ID입니다.");
            }

            $stmtCheck = $pdo->prepare("SELECT wo_id FROM sales_order_item WHERE order_id = ? AND wo_id IS NOT NULL AND wo_id != ''");
            $stmtCheck->execute([$orderId]);
            $activeWo = $stmtCheck->fetch();

            if ($activeWo) {
                Response::error("이미 작업지시({$activeWo['wo_id']})가 발행되어 생산 연계된 품목이 포함되어 있어 수주를 삭제할 수 없습니다.");
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sales_order_item WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM sales_order WHERE id = ?")->execute([$orderId]);
            $pdo->commit();

            Response::json(["status" => "success", "message" => "수주 및 하위 품목이 삭제되었습니다."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 단일 품목(sales_order_item) ➔ 작업지시(WO) 연계 발행
     */
    public static function convertOrderItemToWo(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            self::ensureSchema($pdo);
            $input = Request::getBody();
            $item_id = (int)($id ?? ($input['item_id'] ?? 0));

            if ($item_id <= 0) {
                Response::error("수주 품목 ID가 올바르지 않습니다.");
            }

            $pdo->beginTransaction();

            $stmtItem = $pdo->prepare("
                SELECT soi.*, o.company_id, c.code as company_code
                FROM sales_order_item soi
                JOIN sales_order o ON soi.order_id = o.id
                LEFT JOIN company c ON o.company_id = c.id
                WHERE soi.id = ?
            ");
            $stmtItem->execute([$item_id]);
            $item = $stmtItem->fetch();

            if (!$item) {
                throw new Exception("수주 품목 정보를 찾을 수 없습니다.");
            }
            if (!empty($item['wo_id'])) {
                throw new Exception("이미 작업지시({$item['wo_id']})가 발행된 품목입니다.");
            }

            $cCode = !empty($item['company_code']) ? $item['company_code'] : 'WO';
            $today = date('Ymd');
            $rand  = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
            $wo_id = "{$cCode}-{$today}-{$rand}";
            $target_qty = (int)$item['order_qty'];
            $due_date   = $item['due_date'] ?: date('Y-m-d', strtotime('+7 days'));
            $company_id = (int)$item['company_id'];

            // BOM 탐색: 품목명/코드 기준 매칭
            $bom_id = null;
            $item_name = trim($item['item_name']);
            $item_code = trim($item['item_code'] ?? '');

            $stmtItemMaster = $pdo->prepare("SELECT id FROM item WHERE (item_code != '' AND item_code = ?) OR item_name = ? ORDER BY (company_id = ?) DESC, id DESC LIMIT 1");
            $stmtItemMaster->execute([$item_code, $item_name, $company_id]);
            $itemRow = $stmtItemMaster->fetch();

            if ($itemRow) {
                $itemId = (int)$itemRow['id'];
                $stmtBm = $pdo->prepare("SELECT bom_id FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
                $stmtBm->execute([$itemId]);
                $bmRow = $stmtBm->fetch();
                if ($bmRow) {
                    $bom_id = (int)$bmRow['bom_id'];
                }
            }

            // 1. work_order 생성
            $stmtWo = $pdo->prepare("INSERT INTO work_order (wo_id, company_id, target_qty, status, due_date, bom_id) VALUES (?, ?, ?, 'READY', ?, ?)");
            $stmtWo->execute([$wo_id, $company_id, $target_qty, $due_date, $bom_id]);

            // 2. feeder_setup 생성 (BOM 있을 때)
            if ($bom_id) {
                $stmtDetails = $pdo->prepare("SELECT part_no, req_qty, location, feeder_slot FROM bom_detail WHERE bom_id = ?");
                $stmtDetails->execute([$bom_id]);
                $details = $stmtDetails->fetchAll();

                $insFeeder = $pdo->prepare("INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status) VALUES (?, ?, ?, ?, ?, 'PENDING')");
                $slotIdx = 1;
                foreach ($details as $d) {
                    $sNo = !empty($d['feeder_slot']) ? (int)$d['feeder_slot'] : $slotIdx++;
                    $insFeeder->execute([$wo_id, $sNo, $d['part_no'], $d['location'] ?? '', $d['req_qty'] ?? 1]);
                }
            }

            // 3. 바코드 일괄 생성
            $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
            for ($i = 1; $i <= $target_qty; $i++) {
                $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
                $barcodeStmt->execute([$barcode, $wo_id]);
            }

            // 4. sales_order_item & sales_order 상태 업데이트
            $pdo->prepare("UPDATE sales_order_item SET wo_id = ?, status = 'IN_PRODUCTION' WHERE id = ?")->execute([$wo_id, $item_id]);
            $pdo->prepare("UPDATE sales_order SET status = 'IN_PRODUCTION' WHERE id = ?")->execute([$item['order_id']]);

            // 5. 알림 및 로그
            $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('SUCCESS', '⚡ 작업지시 연계 발행', ?, 'wo')")
                ->execute(["수주({$item['order_no']})의 품목 [{$item_name}]에 대한 작업지시 {$wo_id} (" . number_format($target_qty) . "EA)가 발행되었습니다."]);

            $pdo->prepare("INSERT INTO system_log (username, action_type, description) VALUES ('admin', 'ORDER_ITEM_CONVERT_WO', ?)")
                ->execute(["수주 품목 연계: {$item['order_no']} [{$item_name}] -> {$wo_id} ({$target_qty}EA)"]);

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "품목 [{$item_name}]의 작업지시 [{$wo_id}]가 발행되고 생산 상태로 전환되었습니다.",
                "data" => [
                    "wo_id" => $wo_id,
                    "target_qty" => $target_qty
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 수주 내 모든 미발행 품목 ➔ 일괄 작업지시(WO) 발행
     */
    public static function convertOrderToWo(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            self::ensureSchema($pdo);
            $input = Request::getBody();
            $order_id = (int)($id ?? ($input['order_id'] ?? 0));

            if ($order_id <= 0) {
                Response::error("수주 ID가 올바르지 않습니다.");
            }

            $unassigned = $pdo->prepare("SELECT id FROM sales_order_item WHERE order_id = ? AND (wo_id IS NULL OR wo_id = '')");
            $unassigned->execute([$order_id]);
            $itemRows = $unassigned->fetchAll();

            if (empty($itemRows)) {
                Response::error("발행 가능한 대기 품목이 없습니다. (모든 품목에 이미 작업지시가 발행되었거나 품목이 없음)");
            }

            $issuedWos = [];
            foreach ($itemRows as $row) {
                // 각 품목별 작업지시 발행 처리
                $stmtItem = $pdo->prepare("
                    SELECT soi.*, o.company_id, c.code as company_code
                    FROM sales_order_item soi
                    JOIN sales_order o ON soi.order_id = o.id
                    LEFT JOIN company c ON o.company_id = c.id
                    WHERE soi.id = ?
                ");
                $stmtItem->execute([$row['id']]);
                $item = $stmtItem->fetch();

                $cCode = !empty($item['company_code']) ? $item['company_code'] : 'WO';
                $today = date('Ymd');
                $rand  = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
                $wo_id = "{$cCode}-{$today}-{$rand}";
                $target_qty = (int)$item['order_qty'];
                $due_date   = $item['due_date'] ?: date('Y-m-d', strtotime('+7 days'));
                $company_id = (int)$item['company_id'];

                $bom_id = null;
                $item_name = trim($item['item_name']);
                $item_code = trim($item['item_code'] ?? '');

                $stmtItemMaster = $pdo->prepare("SELECT id FROM item WHERE (item_code != '' AND item_code = ?) OR item_name = ? ORDER BY (company_id = ?) DESC, id DESC LIMIT 1");
                $stmtItemMaster->execute([$item_code, $item_name, $company_id]);
                $itemRow = $stmtItemMaster->fetch();

                if ($itemRow) {
                    $itemId = (int)$itemRow['id'];
                    $stmtBm = $pdo->prepare("SELECT bom_id FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
                    $stmtBm->execute([$itemId]);
                    $bmRow = $stmtBm->fetch();
                    if ($bmRow) {
                        $bom_id = (int)$bmRow['bom_id'];
                    }
                }

                $stmtWo = $pdo->prepare("INSERT INTO work_order (wo_id, company_id, target_qty, status, due_date, bom_id) VALUES (?, ?, ?, 'READY', ?, ?)");
                $stmtWo->execute([$wo_id, $company_id, $target_qty, $due_date, $bom_id]);

                if ($bom_id) {
                    $stmtDetails = $pdo->prepare("SELECT part_no, req_qty, location, feeder_slot FROM bom_detail WHERE bom_id = ?");
                    $stmtDetails->execute([$bom_id]);
                    $details = $stmtDetails->fetchAll();

                    $insFeeder = $pdo->prepare("INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status) VALUES (?, ?, ?, ?, ?, 'PENDING')");
                    $slotIdx = 1;
                    foreach ($details as $d) {
                        $sNo = !empty($d['feeder_slot']) ? (int)$d['feeder_slot'] : $slotIdx++;
                        $insFeeder->execute([$wo_id, $sNo, $d['part_no'], $d['location'] ?? '', $d['req_qty'] ?? 1]);
                    }
                }

                $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
                for ($i = 1; $i <= $target_qty; $i++) {
                    $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
                    $barcodeStmt->execute([$barcode, $wo_id]);
                }

                $pdo->prepare("UPDATE sales_order_item SET wo_id = ?, status = 'IN_PRODUCTION' WHERE id = ?")->execute([$wo_id, $row['id']]);
                $issuedWos[] = "{$item_name} (WO: {$wo_id})";
            }

            $pdo->prepare("UPDATE sales_order SET status = 'IN_PRODUCTION' WHERE id = ?")->execute([$order_id]);

            Response::json([
                "status" => "success",
                "message" => "총 " . count($issuedWos) . "개 품목에 대한 작업지시가 일괄 발행되었습니다.\n" . implode("\n", $issuedWos)
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
