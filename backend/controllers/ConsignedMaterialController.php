<?php
// backend/controllers/ConsignedMaterialController.php

class ConsignedMaterialController {
    /**
     * 1. 사급 자재 요약 통계
     */
    public static function getSummary(): void {
        try {
            $pdo = Database::getConnection();

            // Total Consigned In, Out, Stock
            $stmt = $pdo->query("
                SELECT
                    COALESCE(SUM(CASE WHEN inout_type = 'IN' THEN qty ELSE 0 END), 0) as total_in,
                    COALESCE(SUM(CASE WHEN inout_type = 'OUT' THEN qty ELSE 0 END), 0) as total_out,
                    COUNT(DISTINCT part_no) as part_count,
                    COUNT(DISTINCT order_no) as po_count
                FROM material_inout
                WHERE supply_type = 'CONSIGNED'
            ");
            $sum = $stmt->fetch();

            // Completed Returns count
            $stmtRet = $pdo->query("SELECT COUNT(*) as return_count FROM consigned_return_master WHERE status = 'COMPLETED'");
            $retCount = (int)$stmtRet->fetchColumn();

            // Pending Reconciliation Orders (Orders with consigned parts where not yet returned)
            $stmtPending = $pdo->query("
                SELECT COUNT(DISTINCT m.order_no)
                FROM material_inout m
                WHERE m.supply_type = 'CONSIGNED' AND m.order_no IS NOT NULL AND m.order_no != ''
                  AND m.order_no NOT IN (SELECT order_no FROM consigned_return_master WHERE status = 'COMPLETED' AND order_no IS NOT NULL)
            ");
            $pendingPoCount = (int)$stmtPending->fetchColumn();

            Response::json([
                "status" => "success",
                "data"   => [
                    "total_in"         => (float)$sum['total_in'],
                    "total_out"        => (float)$sum['total_out'],
                    "current_stock"    => (float)($sum['total_in'] - $sum['total_out']),
                    "part_count"       => (int)$sum['part_count'],
                    "po_count"         => (int)$sum['po_count'],
                    "return_count"     => $retCount,
                    "pending_po_count" => $pendingPoCount
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 사급 자재 재고/수불 목록 (거래처 / 수주별 그룹화 지원)
     */
    public static function getStockList(): void {
        try {
            $pdo = Database::getConnection();

            // Auto-heal existing material_inout records where company_id is NULL
            try {
                $pdo->query("
                    UPDATE material_inout m
                    JOIN sales_order so ON m.order_no = so.order_no
                    SET m.company_id = so.company_id
                    WHERE m.company_id IS NULL AND m.order_no IS NOT NULL AND m.order_no != ''
                ");
                $pdo->query("
                    UPDATE material_inout m
                    JOIN work_order wo ON m.wo_id = wo.wo_id
                    SET m.company_id = wo.company_id
                    WHERE m.company_id IS NULL AND m.wo_id IS NOT NULL AND m.wo_id != ''
                ");
            } catch (\Throwable $e) {}

            $companyId = Request::query('company_id');
            $orderNo   = trim(Request::query('order_no', ''));
            $search    = trim(Request::query('search', ''));

            $where = ["m.supply_type = 'CONSIGNED'"];
            $params = [];

            if (!empty($companyId)) {
                $where[] = "COALESCE(m.company_id, so.company_id, wo.company_id) = :company_id";
                $params[':company_id'] = (int)$companyId;
            }
            if (!empty($orderNo)) {
                $where[] = "m.order_no = :order_no";
                $params[':order_no'] = $orderNo;
            }
            if (!empty($search)) {
                $where[] = "(m.part_no LIKE :search OR m.part_name LIKE :search OR m.order_no LIKE :search OR c.name LIKE :search OR so_c.name LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "
                SELECT
                    m.part_no,
                    MAX(m.part_name) as part_name,
                    COALESCE(m.company_id, so.company_id, wo.company_id) as company_id,
                    COALESCE(c.name, so_c.name, wo_c.name, '기타 거래처') as company_name,
                    COALESCE(NULLIF(m.order_no, ''), '공용 자재(Pool)') as order_no,
                    COALESCE(
                        (SELECT GROUP_CONCAT(DISTINCT item_name SEPARATOR ', ') FROM sales_order_item WHERE order_no = m.order_no),
                        (SELECT product_id FROM bom_master WHERE bom_id = MAX(m.bom_id) LIMIT 1),
                        '사급 자재'
                    ) as project_name,
                    (
                        SELECT JSON_ARRAYAGG(
                            JSON_OBJECT(
                                'item_name', soi.item_name,
                                'has_bom', IF((SELECT bom_id FROM work_order WHERE wo_id = soi.wo_id) IS NOT NULL, 1, 0)
                            )
                        )
                        FROM sales_order_item soi
                        WHERE soi.order_no = m.order_no
                    ) as order_items_json,
                    MAX(m.bom_id) as bom_id,
                    MAX(m.unit) as unit,
                    COALESCE(SUM(CASE WHEN m.inout_type = 'IN' THEN m.qty ELSE 0 END), 0) as total_in,
                    COALESCE(SUM(CASE WHEN m.inout_type = 'OUT' THEN m.qty ELSE 0 END), 0) as total_out,
                    MAX(m.created_at) as last_inout_at
                FROM material_inout m
                LEFT JOIN company c ON m.company_id = c.id
                LEFT JOIN sales_order so ON m.order_no = so.order_no
                LEFT JOIN company so_c ON so.company_id = so_c.id
                LEFT JOIN work_order wo ON m.wo_id = wo.wo_id
                LEFT JOIN company wo_c ON wo.company_id = wo_c.id
                {$whereClause}
                GROUP BY m.part_no, 
                         COALESCE(m.company_id, so.company_id, wo.company_id), 
                         COALESCE(c.name, so_c.name, wo_c.name, '기타 거래처'), 
                         COALESCE(NULLIF(m.order_no, ''), '공용 자재(Pool)')
                ORDER BY company_name ASC, order_no ASC, m.part_no ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $list = [];
            foreach ($rows as $r) {
                $inQty = (float)$r['total_in'];
                $outQty = (float)$r['total_out'];
                $stock = $inQty - $outQty;
                $itemsSummary = !empty($r['order_items_json']) ? json_decode($r['order_items_json'], true) : [];

                $list[] = [
                    'part_no'        => $r['part_no'],
                    'part_name'      => $r['part_name'] ?: $r['part_no'],
                    'company_id'     => $r['company_id'],
                    'company_name'   => $r['company_name'],
                    'order_no'       => $r['order_no'],
                    'project_name'   => $r['project_name'],
                    'items_summary'  => $itemsSummary ?: [],
                    'bom_id'         => $r['bom_id'],
                    'unit'           => $r['unit'] ?: 'EA',
                    'total_in'       => $inQty,
                    'total_out'      => $outQty,
                    'current_stock'  => $stock,
                    'last_inout_at'  => $r['last_inout_at']
                ];
            }

            Response::json([
                "status" => "success",
                "data"   => $list
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 수주(PO) 또는 제품 선택 시 BOM 부품 목록 불러오기 (원클릭 사급 입고용)
     */
    public static function getBomPartsForOrder(): void {
        try {
            $pdo = Database::getConnection();
            $orderNo   = trim(Request::query('order_no', ''));
            $companyId = (int)Request::query('company_id', 0);
            $bomId     = (int)Request::query('bom_id', 0);

            // If order_no provided, lookup product & company & all item BOMs
            $orderInfo = null;
            $parts = [];

            if (!empty($orderNo)) {
                $stmt = $pdo->prepare("
                    SELECT soi.*, so.company_id, c.name as company_name,
                           (SELECT bm.bom_id FROM bom_master bm 
                            WHERE bm.product_id = soi.item_name 
                               OR bm.product_id = soi.item_code
                               OR bm.product_id = CONCAT('PROD-', soi.wo_id)
                               OR bm.product_id = soi.wo_id
                               OR bm.item_id IN (SELECT im.id FROM item im WHERE im.item_name = soi.item_name OR im.item_code = soi.item_code)
                               OR bm.bom_id IN (SELECT wo.bom_id FROM work_order wo WHERE wo.wo_id = soi.wo_id)
                            ORDER BY bm.bom_id DESC LIMIT 1) as auto_bom_id
                    FROM sales_order_item soi
                    LEFT JOIN sales_order so ON soi.order_no = so.order_no
                    LEFT JOIN company c ON so.company_id = c.id
                    WHERE soi.order_no = ?
                    ORDER BY soi.id ASC
                ");
                $stmt->execute([$orderNo]);
                $orderItems = $stmt->fetchAll();

                if (!empty($orderItems)) {
                    $first = $orderItems[0];
                    if (!$companyId && !empty($first['company_id'])) {
                        $companyId = (int)$first['company_id'];
                    }
                    $itemNames = array_unique(array_filter(array_column($orderItems, 'item_name')));
                    $totalOrderQty = array_sum(array_column($orderItems, 'order_qty'));

                    $orderInfo = [
                        'order_no'     => $orderNo,
                        'company_id'   => $companyId,
                        'company_name' => $first['company_name'] ?: '미지정',
                        'item_name'    => implode(', ', $itemNames),
                        'order_qty'    => $totalOrderQty,
                        'items'        => $orderItems
                    ];

                    $partsMap = [];
                    foreach ($orderItems as $it) {
                        $itBomId = (int)($it['auto_bom_id'] ?? 0);
                        $itQty = (float)($it['order_qty'] ?? 0);
                        $itName = $it['item_name'] ?? '';

                        if ($itBomId > 0) {
                            $stmtParts = $pdo->prepare("
                                SELECT
                                    bd.detail_id, bd.part_no, bd.part_name, bd.req_qty, bd.location,
                                    (SELECT COALESCE(SUM(CASE WHEN inout_type='IN' THEN qty ELSE -qty END), 0)
                                     FROM material_inout m
                                     WHERE m.part_no = bd.part_no AND m.supply_type = 'CONSIGNED'
                                       AND m.order_no = " . $pdo->quote($orderNo) . "
                                    ) as current_received_qty
                                FROM bom_detail bd
                                WHERE bd.bom_id = ?
                                ORDER BY bd.detail_id ASC
                            ");
                            $stmtParts->execute([$itBomId]);
                            $bomParts = $stmtParts->fetchAll();

                            foreach ($bomParts as $bp) {
                                $pNo = $bp['part_no'];
                                $reqPerUnit = (float)$bp['req_qty'];
                                $partReqTotal = $itQty > 0 ? ceil($reqPerUnit * $itQty) : 0;

                                if (!isset($partsMap[$pNo])) {
                                    $partsMap[$pNo] = [
                                        'detail_id'            => $bp['detail_id'],
                                        'part_no'              => $pNo,
                                        'part_name'            => $bp['part_name'] ?: $pNo,
                                        'req_qty'              => $reqPerUnit,
                                        'calc_total_req'       => $partReqTotal,
                                        'location'             => $bp['location'] ?: '',
                                        'current_received_qty' => (float)$bp['current_received_qty'],
                                        'items'                => [$itName]
                                    ];
                                } else {
                                    $partsMap[$pNo]['calc_total_req'] += $partReqTotal;
                                    $partsMap[$pNo]['req_qty'] += $reqPerUnit;
                                    if (!in_array($itName, $partsMap[$pNo]['items'])) {
                                        $partsMap[$pNo]['items'][] = $itName;
                                    }
                                }
                            }
                        }
                    }
                    $parts = array_values($partsMap);
                }
            }

            // Fallback: If single bomId was given or product_id given directly
            if (empty($parts)) {
                $productId = trim(Request::query('product_id', ''));
                if (!$bomId && !empty($productId)) {
                    $stmtBom = $pdo->prepare("SELECT bom_id FROM bom_master WHERE product_id = ? ORDER BY bom_id DESC LIMIT 1");
                    $stmtBom->execute([$productId]);
                    $bomId = (int)$stmtBom->fetchColumn();
                }

                if ($bomId > 0) {
                    $stmtParts = $pdo->prepare("
                        SELECT
                            bd.detail_id, bd.part_no, bd.part_name, bd.req_qty, bd.location,
                            (SELECT COALESCE(SUM(CASE WHEN inout_type='IN' THEN qty ELSE -qty END), 0)
                             FROM material_inout m
                             WHERE m.part_no = bd.part_no AND m.supply_type = 'CONSIGNED'
                               " . (!empty($orderNo) ? "AND m.order_no = " . $pdo->quote($orderNo) : (!empty($companyId) ? "AND m.company_id = {$companyId}" : "")) . "
                            ) as current_received_qty
                        FROM bom_detail bd
                        WHERE bd.bom_id = ?
                        ORDER BY bd.detail_id ASC
                    ");
                    $stmtParts->execute([$bomId]);
                    $parts = $stmtParts->fetchAll();
                }
            }

            Response::json([
                "status" => "success",
                "data"   => [
                    "order_info" => $orderInfo,
                    "bom_id"     => $bomId,
                    "company_id" => $companyId,
                    "parts"      => $parts
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 사급 자재 일괄 입고 (BOM 원클릭 / 엑셀 / 개별)
     */
    public static function receiveBatch(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $companyId = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $orderNo   = trim($input['order_no'] ?? '') ?: null;
            $bomId     = !empty($input['bom_id']) ? (int)$input['bom_id'] : null;
            $note      = trim($input['note'] ?? '사급 자재 입고');
            $items     = $input['items'] ?? [];

            if (empty($items) || !is_array($items)) {
                Response::error("입고할 부품 목록이 비어있습니다.");
            }

            $pdo->beginTransaction();

            $woId = null;
            if ($orderNo) {
                $stmtFind = $pdo->prepare("
                    SELECT soi.wo_id, so.company_id,
                           (SELECT bm.bom_id FROM bom_master bm 
                            WHERE bm.product_id = soi.item_name 
                               OR bm.product_id = soi.item_code
                               OR bm.product_id = CONCAT('PROD-', soi.wo_id)
                               OR bm.product_id = soi.wo_id
                               OR bm.item_id IN (SELECT im.id FROM item im WHERE im.item_name = soi.item_name OR im.item_code = soi.item_code)
                               OR bm.bom_id IN (SELECT wo.bom_id FROM work_order wo WHERE wo.wo_id = soi.wo_id)
                            ORDER BY bm.bom_id DESC LIMIT 1) as auto_bom_id
                    FROM sales_order_item soi
                    LEFT JOIN sales_order so ON soi.order_no = so.order_no
                    WHERE soi.order_no = ? LIMIT 1
                ");
                $stmtFind->execute([$orderNo]);
                $found = $stmtFind->fetch();
                if ($found) {
                    if (!$companyId && !empty($found['company_id'])) $companyId = (int)$found['company_id'];
                    if (!$bomId && !empty($found['auto_bom_id'])) $bomId = (int)$found['auto_bom_id'];
                    if (!empty($found['wo_id'])) $woId = $found['wo_id'];
                }
            }

            $insStmt = $pdo->prepare("
                INSERT INTO material_inout 
                (part_no, part_name, inout_type, supply_type, qty, unit, wo_id, company_id, order_no, bom_id, note)
                VALUES (?, ?, 'IN', 'CONSIGNED', ?, ?, ?, ?, ?, ?, ?)
            ");

            $updBomDetail = $bomId ? $pdo->prepare("UPDATE bom_detail SET provide_qty = ? WHERE bom_id = ? AND part_no = ?") : null;

            $savedCount = 0;
            $totalQty = 0;

            foreach ($items as $item) {
                $partNo   = trim($item['part_no'] ?? '');
                $partName = trim($item['part_name'] ?? '') ?: $partNo;
                $qty      = (float)($item['qty'] ?? 0);
                $unit     = trim($item['unit'] ?? 'EA') ?: 'EA';

                if (empty($partNo) || $qty <= 0) continue;

                $insStmt->execute([
                    $partNo,
                    $partName,
                    $qty,
                    $unit,
                    $woId,
                    $companyId,
                    $orderNo,
                    $bomId,
                    $note
                ]);

                if ($updBomDetail && $bomId) {
                    $updBomDetail->execute([$qty, $bomId, $partNo]);
                }

                $savedCount++;
                $totalQty += $qty;
            }

            if ($savedCount === 0) {
                throw new Exception("유효한 입고 수량(0 초과)을 가진 부품이 없습니다.");
            }

            $pdo->commit();

            Response::json([
                "status"  => "success",
                "message" => "총 {$savedCount}종 (합계 " . number_format($totalQty) . " EA)의 사급 자재가 성공적으로 입고되었습니다."
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 수주별 사급 자재 수불 및 반납 정산 데이터 계산
     */
    public static function getReconciliation(): void {
        try {
            $pdo = Database::getConnection();
            $orderNo   = trim(Request::query('order_no', ''));
            $companyId = (int)Request::query('company_id', 0);

            if (empty($orderNo) && empty($companyId)) {
                Response::error("수주번호(PO) 또는 거래처를 선택해주세요.");
            }

            // Get order / WO progress info
            $orderInfo = null;
            $parts = [];
            $totalProducedQty = 0;

            if (!empty($orderNo)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        soi.id, soi.order_no, soi.item_name, soi.item_code, soi.order_qty, soi.due_date, soi.status, soi.wo_id,
                        so.company_id, c.name as company_name,
                        COALESCE(
                            (SELECT SUM(CASE 
                                WHEN bm.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING','DONE','DEFECT','FAIL') 
                                     OR EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.result_status IN ('PASS','FAIL'))
                                THEN 1 ELSE 0 END)
                             FROM barcode_master bm JOIN work_order wo ON bm.wo_id = wo.wo_id
                             WHERE wo.wo_id = soi.wo_id OR wo.parent_wo_id = soi.wo_id),
                            0
                        ) as processed_qty,
                        COALESCE(
                            (SELECT SUM(CASE 
                                WHEN bm.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING','DONE') 
                                     AND NOT EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.result_status = 'FAIL')
                                THEN 1 ELSE 0 END)
                             FROM barcode_master bm JOIN work_order wo ON bm.wo_id = wo.wo_id
                             WHERE wo.wo_id = soi.wo_id OR wo.parent_wo_id = soi.wo_id),
                            0
                        ) as good_qty,
                        COALESCE(
                            (SELECT SUM(CASE 
                                WHEN (bm.status IN ('DEFECT','FAIL') OR EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.result_status = 'FAIL'))
                                     AND EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.process_name IN ('MOUNTER','MOUNTER_1','MOUNTER_2','REFLOW','DIP_AOI','WAVE','ICT','COATING','FCT'))
                                THEN 1 ELSE 0 END)
                             FROM barcode_master bm JOIN work_order wo ON bm.wo_id = wo.wo_id
                             WHERE wo.wo_id = soi.wo_id OR wo.parent_wo_id = soi.wo_id),
                            0
                        ) as mounted_fail_qty,
                        COALESCE(
                            (SELECT SUM(CASE 
                                WHEN (bm.status IN ('DEFECT','FAIL') OR EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.result_status = 'FAIL'))
                                     AND NOT EXISTS (SELECT 1 FROM barcode_history bh WHERE bh.barcode = bm.barcode AND bh.process_name IN ('MOUNTER','MOUNTER_1','MOUNTER_2','REFLOW','DIP_AOI','WAVE','ICT','COATING','FCT'))
                                THEN 1 ELSE 0 END)
                             FROM barcode_master bm JOIN work_order wo ON bm.wo_id = wo.wo_id
                             WHERE wo.wo_id = soi.wo_id OR wo.parent_wo_id = soi.wo_id),
                            0
                        ) as premount_fail_qty,
                        (SELECT bm.bom_id FROM bom_master bm 
                         WHERE bm.product_id = soi.item_name 
                            OR bm.product_id = soi.item_code
                            OR bm.product_id = CONCAT('PROD-', soi.wo_id)
                            OR bm.product_id = soi.wo_id
                            OR bm.item_id IN (SELECT im.id FROM item im WHERE im.item_name = soi.item_name OR im.item_code = soi.item_code)
                            OR bm.bom_id IN (SELECT wo.bom_id FROM work_order wo WHERE wo.wo_id = soi.wo_id)
                         ORDER BY bm.bom_id DESC LIMIT 1) as bom_id
                    FROM sales_order_item soi
                    LEFT JOIN sales_order so ON soi.order_no = so.order_no
                    LEFT JOIN company c ON so.company_id = c.id
                    WHERE soi.order_no = ?
                    ORDER BY soi.id ASC
                ");
                $stmt->execute([$orderNo]);
                $orderItems = $stmt->fetchAll();

                if (!empty($orderItems)) {
                    $first = $orderItems[0];
                    $companyId = (int)($first['company_id'] ?: $companyId);
                    $itemNames = array_unique(array_filter(array_column($orderItems, 'item_name')));
                    $totalOrderQty = array_sum(array_column($orderItems, 'order_qty'));

                    $partsMap = [];
                    foreach ($orderItems as $it) {
                        $itOrderQty        = (float)($it['order_qty'] ?? 0);
                        $itGoodQty         = (float)($it['good_qty'] ?? 0);
                        $itMountedFailQty  = (float)($it['mounted_fail_qty'] ?? 0);
                        $itPremountFailQty = (float)($it['premount_fail_qty'] ?? 0);
                        $itProcessedQty    = (float)($it['processed_qty'] ?? 0);

                        // 공정별 정밀 자재 소요량 계산:
                        // - 레이저/SPI 등 부품 실장 전(Pre-mount) 불량은 부품 소모가 0이므로 소요량에서 제외
                        // - 마운터 실장 이후(Mounted) 불량은 실장된 부품이 동반 손실되므로 실소모량에 포함
                        // - 목표 100% 양품을 채우기 위해 필요한 총 부품 소요 기판 수 = 목표수량 + 실장 후 불량수량
                        $itConsumedBoards = ($itProcessedQty > 0) ? ($itOrderQty + $itMountedFailQty) : $itOrderQty;
                        $totalProducedQty += $itOrderQty;
                        $itBomId = (int)($it['bom_id'] ?? 0);
                        $itName = $it['item_name'] ?? '';

                        if ($itBomId > 0) {
                            $stmtBom = $pdo->prepare("
                                SELECT bd.part_no, bd.part_name, bd.req_qty, bd.location
                                FROM bom_detail bd
                                WHERE bd.bom_id = ?
                                ORDER BY bd.detail_id ASC
                            ");
                            $stmtBom->execute([$itBomId]);
                            $bomParts = $stmtBom->fetchAll();

                            foreach ($bomParts as $bp) {
                                $pNo = $bp['part_no'];
                                $reqPerUnit = (float)$bp['req_qty'];
                                $usedQty = $reqPerUnit * $itConsumedBoards;

                                if (!isset($partsMap[$pNo])) {
                                    $partsMap[$pNo] = [
                                        'part_no'          => $pNo,
                                        'part_name'        => $bp['part_name'] ?: $pNo,
                                        'location'         => $bp['location'] ?: '',
                                        'req_qty_per_unit' => $reqPerUnit,
                                        'used_qty'         => $usedQty,
                                        'items'            => [$itName]
                                    ];
                                } else {
                                    $partsMap[$pNo]['used_qty'] += $usedQty;
                                    $partsMap[$pNo]['req_qty_per_unit'] += $reqPerUnit;
                                    if (!in_array($itName, $partsMap[$pNo]['items'])) {
                                        $partsMap[$pNo]['items'][] = $itName;
                                    }
                                }
                            }
                        }
                    }

                    foreach ($partsMap as $pNo => $pData) {
                        $sumStmt = $pdo->prepare("
                            SELECT 
                                COALESCE(SUM(CASE WHEN inout_type='IN' THEN qty ELSE 0 END), 0) as supplied_qty,
                                COALESCE(SUM(CASE WHEN inout_type='OUT' THEN qty ELSE 0 END), 0) as out_qty
                            FROM material_inout
                            WHERE part_no = ? AND supply_type = 'CONSIGNED'
                              AND (order_no = ? OR (order_no IS NULL AND company_id = ?))
                        ");
                        $sumStmt->execute([$pNo, $orderNo, $companyId]);
                        $sRow = $sumStmt->fetch();
                        $supplied = (float)$sRow['supplied_qty'];
                        $out = (float)$sRow['out_qty'];
                        $expectedReturn = max(0, $supplied - $pData['used_qty']);

                        $parts[] = [
                            'part_no'             => $pNo,
                            'part_name'           => $pData['part_name'],
                            'location'            => $pData['location'],
                            'req_qty_per_unit'    => $pData['req_qty_per_unit'],
                            'supplied_qty'        => $supplied,
                            'used_qty'            => $pData['used_qty'],
                            'expected_return_qty' => $expectedReturn,
                            'actual_return_qty'   => $expectedReturn
                        ];
                    }

                    $orderInfo = [
                        'order_no'     => $orderNo,
                        'company_id'   => $companyId,
                        'company_name' => $first['company_name'] ?: '미지정',
                        'item_name'    => implode(', ', $itemNames),
                        'order_qty'    => $totalOrderQty,
                        'good_qty'     => $totalOrderQty
                    ];
                }
            }

            // Fallback: If no BOM parts found, list all supplied parts directly for this order
            if (empty($parts)) {
                $stmtSupplied = $pdo->prepare("
                    SELECT part_no, MAX(part_name) as part_name,
                           COALESCE(SUM(CASE WHEN inout_type='IN' THEN qty ELSE 0 END), 0) as supplied_qty,
                           COALESCE(SUM(CASE WHEN inout_type='OUT' THEN qty ELSE 0 END), 0) as out_qty
                    FROM material_inout
                    WHERE supply_type = 'CONSIGNED' " . (!empty($orderNo) ? "AND order_no = ?" : "AND company_id = ?") . "
                    GROUP BY part_no
                ");
                $stmtSupplied->execute([!empty($orderNo) ? $orderNo : $companyId]);
                $sParts = $stmtSupplied->fetchAll();
                foreach ($sParts as $sp) {
                    $supplied = (float)$sp['supplied_qty'];
                    $parts[] = [
                        'part_no'             => $sp['part_no'],
                        'part_name'           => $sp['part_name'] ?: $sp['part_no'],
                        'location'            => '',
                        'req_qty_per_unit'    => 0,
                        'supplied_qty'        => $supplied,
                        'used_qty'            => 0,
                        'expected_return_qty' => $supplied,
                        'actual_return_qty'   => $supplied
                    ];
                }
            }

            // Check if return statement is already issued for this order
            $existingReturn = null;
            if (!empty($orderNo)) {
                $stmtRetCheck = $pdo->prepare("SELECT id, return_no, return_date, memo, status FROM consigned_return_master WHERE order_no = ? AND status = 'COMPLETED' ORDER BY id DESC LIMIT 1");
                $stmtRetCheck->execute([$orderNo]);
                $existingReturn = $stmtRetCheck->fetch();
            }

            Response::json([
                "status" => "success",
                "data"   => [
                    "order_info"       => $orderInfo,
                    "produced_qty"     => $totalProducedQty,
                    "parts"            => $parts,
                    "existing_return"  => $existingReturn
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 사급 자재 반납 정산서 생성 및 출고 마감
     */
    public static function createReturn(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $orderNo      = trim($input['order_no'] ?? '');
            $companyId    = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $itemName     = trim($input['item_name'] ?? '');
            $shippedQty   = (int)($input['shipped_qty'] ?? 0);
            $returnDate   = trim($input['return_date'] ?? date('Y-m-d'));
            $receiverName = trim($input['receiver_name'] ?? '');
            $memo         = trim($input['memo'] ?? '');
            $details      = $input['details'] ?? [];

            if (empty($details) || !is_array($details)) {
                Response::error("반납할 부품 정산 내역이 없습니다.");
            }

            // Auto-resolve company_id if not explicitly provided
            if (!$companyId && !empty($orderNo)) {
                $stmtC = $pdo->prepare("SELECT company_id FROM sales_order WHERE order_no = ? LIMIT 1");
                $stmtC->execute([$orderNo]);
                $cId = (int)$stmtC->fetchColumn();
                if ($cId > 0) {
                    $companyId = $cId;
                } else {
                    $stmtC2 = $pdo->prepare("SELECT so.company_id FROM sales_order_item soi JOIN sales_order so ON soi.order_no = so.order_no WHERE soi.order_no = ? LIMIT 1");
                    $stmtC2->execute([$orderNo]);
                    $cId2 = (int)$stmtC2->fetchColumn();
                    if ($cId2 > 0) $companyId = $cId2;
                }
            }

            // Prevent duplicate return statement issuance for the same order
            if (!empty($orderNo)) {
                $stmtCheck = $pdo->prepare("SELECT return_no FROM consigned_return_master WHERE order_no = ? AND status = 'COMPLETED' LIMIT 1");
                $stmtCheck->execute([$orderNo]);
                $existingReturnNo = $stmtCheck->fetchColumn();
                if ($existingReturnNo) {
                    Response::error("수주 [{$orderNo}]는 이미 반납 명세서 [{$existingReturnNo}]가 발행되어 정산 완료되었습니다.");
                }
            }

            // Generate Return Doc No: RET-YYYYMMDD-XXX
            $datePrefix = 'RET-' . date('Ymd');
            $stmtSeq = $pdo->prepare("SELECT COUNT(*) FROM consigned_return_master WHERE return_no LIKE ?");
            $stmtSeq->execute([$datePrefix . '%']);
            $seq = (int)$stmtSeq->fetchColumn() + 1;
            $returnNo = sprintf("%s-%03d", $datePrefix, $seq);

            $pdo->beginTransaction();

            // 1. Insert Master
            $insMaster = $pdo->prepare("
                INSERT INTO consigned_return_master 
                (return_no, order_no, company_id, item_name, shipped_qty, return_date, status, receiver_name, memo)
                VALUES (?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?)
            ");
            $insMaster->execute([
                $returnNo,
                $orderNo ?: null,
                $companyId,
                $itemName ?: null,
                $shippedQty,
                $returnDate,
                $receiverName ?: null,
                $memo ?: null
            ]);
            $returnId = (int)$pdo->lastInsertId();

            // 2. Insert Details & Register OUT in material_inout
            $insDetail = $pdo->prepare("
                INSERT INTO consigned_return_detail 
                (return_id, part_no, part_name, req_qty_per_unit, supplied_qty, used_qty, expected_return_qty, actual_return_qty, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insOut = $pdo->prepare("
                INSERT INTO material_inout 
                (part_no, part_name, inout_type, supply_type, qty, unit, company_id, order_no, note)
                VALUES (?, ?, 'OUT', 'CONSIGNED', ?, 'EA', ?, ?, ?)
            ");

            $totalReturnQty = 0;
            foreach ($details as $d) {
                $pNo = trim($d['part_no'] ?? '');
                $pName = trim($d['part_name'] ?? '') ?: $pNo;
                $reqPerUnit = (float)($d['req_qty_per_unit'] ?? 0);
                $supplied = (float)($d['supplied_qty'] ?? 0);
                $used = (float)($d['used_qty'] ?? 0);
                $expReturn = (float)($d['expected_return_qty'] ?? 0);
                $actReturn = (float)($d['actual_return_qty'] ?? 0);
                $remark = trim($d['remark'] ?? '') ?: null;

                if (empty($pNo)) continue;

                $insDetail->execute([
                    $returnId,
                    $pNo,
                    $pName,
                    $reqPerUnit,
                    $supplied,
                    $used,
                    $expReturn,
                    $actReturn,
                    $remark
                ]);

                // Record OUT transaction for the returned material
                if ($actReturn > 0) {
                    $insOut->execute([
                        $pNo,
                        $pName,
                        $actReturn,
                        $companyId,
                        $orderNo ?: null,
                        "사급 자재 반납 출고 ({$returnNo})"
                    ]);
                    $totalReturnQty += $actReturn;
                }
            }

            $pdo->commit();

            Response::json([
                "status"  => "success",
                "message" => "사급 자재 반납 명세서 [{$returnNo}]가 성공적으로 등록 및 반납 출고되었습니다.",
                "data"    => [
                    "return_id" => $returnId,
                    "return_no" => $returnNo
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 7. 반납 명세서 목록 조회
     */
    public static function getReturnList(): void {
        try {
            $pdo = Database::getConnection();
            $companyId = Request::query('company_id');
            $search    = trim(Request::query('search', ''));

            $where = ["1=1"];
            $params = [];

            if (!empty($companyId)) {
                $where[] = "rm.company_id = :company_id";
                $params[':company_id'] = (int)$companyId;
            }
            if (!empty($search)) {
                $where[] = "(rm.return_no LIKE :search OR rm.order_no LIKE :search OR rm.item_name LIKE :search OR c.name LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereClause = "WHERE " . implode(" AND ", $where);

            $sql = "
                SELECT 
                    rm.*, 
                    COALESCE(c.name, (SELECT name FROM company WHERE id = (SELECT company_id FROM sales_order WHERE order_no = rm.order_no LIMIT 1)), '미지정') as company_name,
                    COALESCE(
                        NULLIF(rm.item_name, ''),
                        (SELECT GROUP_CONCAT(DISTINCT item_name SEPARATOR ', ') FROM sales_order_item WHERE order_no = rm.order_no),
                        '품목 미지정'
                    ) as item_name,
                    (SELECT COUNT(*) FROM consigned_return_detail rd WHERE rd.return_id = rm.id) as part_count,
                    (SELECT COALESCE(SUM(actual_return_qty), 0) FROM consigned_return_detail rd WHERE rd.return_id = rm.id) as total_return_qty
                FROM consigned_return_master rm
                LEFT JOIN company c ON rm.company_id = c.id
                {$whereClause}
                ORDER BY rm.return_date DESC, rm.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll();

            Response::json([
                "status" => "success",
                "data"   => $list
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 8. 반납 명세서 단건 상세 조회 (A4 출력 및 확인용)
     */
    public static function getReturnDetail(): void {
        try {
            $pdo = Database::getConnection();
            $returnId = (int)Request::query('return_id', 0);
            $returnNo = trim(Request::query('return_no', ''));

            if ($returnId <= 0 && empty($returnNo)) {
                Response::error("명세서 ID 또는 번호가 필요합니다.");
            }

            $stmt = $pdo->prepare("
                SELECT rm.*, 
                       COALESCE(c.name, (SELECT name FROM company WHERE id = (SELECT company_id FROM sales_order WHERE order_no = rm.order_no LIMIT 1)), '미지정') as company_name,
                       COALESCE(
                           NULLIF(rm.item_name, ''),
                           (SELECT GROUP_CONCAT(DISTINCT item_name SEPARATOR ', ') FROM sales_order_item WHERE order_no = rm.order_no),
                           '품목 미지정'
                       ) as item_name,
                       c.code, c.email, c.tel
                FROM consigned_return_master rm
                LEFT JOIN company c ON rm.company_id = c.id
                WHERE " . ($returnId > 0 ? "rm.id = ?" : "rm.return_no = ?") . "
                LIMIT 1
            ");
            $stmt->execute([$returnId > 0 ? $returnId : $returnNo]);
            $master = $stmt->fetch();

            if (!$master) {
                Response::error("반납 명세서를 찾을 수 없습니다.");
            }

            $stmtDet = $pdo->prepare("
                SELECT * FROM consigned_return_detail WHERE return_id = ? ORDER BY id ASC
            ");
            $stmtDet->execute([$master['id']]);
            $details = $stmtDet->fetchAll();

            Response::json([
                "status" => "success",
                "data"   => [
                    "master"  => $master,
                    "details" => $details
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
