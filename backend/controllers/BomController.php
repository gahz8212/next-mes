<?php
// backend/controllers/BomController.php

class BomController {
    /**
     * 1. BOM 조회 (wo_id 또는 item_id 기준)
     */
    public static function getBom(): void {
        try {
            $pdo = Database::getConnection();
            $wo_id   = trim(Request::query('wo_id', Request::input('wo_id', '')));
            $item_id = Request::query('item_id', Request::input('item_id'));
            $item_id = !empty($item_id) ? (int)$item_id : null;

            if (!$wo_id && !$item_id) {
                Response::error("WO ID 또는 Item ID가 필요합니다.");
            }

            $bom_id = null;
            $version = 'v1.0';

            if (!empty($wo_id)) {
                $stmt = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
                $stmt->execute([$wo_id]);
                $wo = $stmt->fetch();
                if ($wo && !empty($wo['bom_id'])) {
                    $bom_id = (int)$wo['bom_id'];
                }
            }

            if (!$bom_id && !empty($item_id)) {
                $stmt = $pdo->prepare("SELECT bom_id, version FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
                $stmt->execute([$item_id]);
                $bm = $stmt->fetch();
                if ($bm) {
                    $bom_id = (int)$bm['bom_id'];
                    $version = $bm['version'] ?: 'v1.0';
                }
            }

            if (!$bom_id) {
                Response::json(["status" => "success", "data" => [], "version" => "v1.0"]);
            }

            $stmt = $pdo->prepare("SELECT part_no, COALESCE(part_name, '') as part_name, COALESCE(points, req_qty, 1) as points, COALESCE(provide_qty, req_qty) as provide_qty, req_qty, COALESCE(location, '') as location, feeder_slot FROM bom_detail WHERE bom_id = ?");
            $stmt->execute([$bom_id]);
            $details = $stmt->fetchAll();

            foreach ($details as &$d) {
                $d['points'] = (int)round((float)($d['points'] ?? 1));
                $d['req_qty'] = (int)round((float)($d['req_qty'] ?? 1));
                if ($d['provide_qty'] !== null && $d['provide_qty'] !== '') {
                    $d['provide_qty'] = (int)round((float)$d['provide_qty']);
                }
            }
            unset($d);

            Response::json(["status" => "success", "data" => $details, "bom_id" => $bom_id, "version" => $version]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. BOM 저장 및 버전 등록
     */
    public static function saveBom(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();

            $wo_id        = trim($input['wo_id'] ?? '');
            $bom_data     = $input['bom_data'] ?? [];
            $company_id   = $input['company_id'] ?? '';
            $mapping      = $input['mapping'] ?? [];
            $item_id      = !empty($input['item_id']) ? (int)$input['item_id'] : null;
            $version      = trim($input['version'] ?? 'v1.0');
            $auto_inbound = !empty($input['auto_inbound']);

            if ((empty($wo_id) && empty($item_id)) || empty($bom_data) || !is_array($bom_data)) {
                Response::error("품목(Item) 또는 작업지시(WO)와 BOM 데이터가 필요합니다.");
            }

            // 포인트(PCB 1장당 소요수량) 누락 및 0 이하 검증 (하나라도 빠지면 저장/입고 차단)
            $invalidItems = [];
            foreach ($bom_data as $idx => $row) {
                $pNo = trim($row['part_no'] ?? '');
                $qty = isset($row['req_qty']) ? (float)$row['req_qty'] : 0;
                if (empty($pNo)) {
                    Response::error("BOM 데이터의 " . ($idx + 1) . "번째 행에 파트번호가 누락되었습니다.");
                }
                if ($qty <= 0) {
                    $invalidItems[] = "행 " . ($idx + 1) . " (부품: {$pNo})";
                }
            }

            if (!empty($invalidItems)) {
                $itemStr = implode(', ', array_slice($invalidItems, 0, 5));
                if (count($invalidItems) > 5) {
                    $itemStr .= " 외 " . (count($invalidItems) - 5) . "건";
                }
                Response::error("포인트(PCB 1장당 소요수량)가 누락되거나 0 이하인 부품이 있습니다: [{$itemStr}]. 모든 부품의 수량을 1 이상으로 입력해야 저장 및 입고 처리가 가능합니다.");
            }

            $pdo->beginTransaction();

            // 1. item_id 자동 추적 및 자동 생성: wo_id 기준 수주 품목(sales_order_item)과 품목(item)을 매칭하여 item_id 확보
            if (!$item_id && !empty($wo_id)) {
                $stmtSoi = $pdo->prepare("
                    SELECT 
                        soi.item_code, 
                        soi.item_name, 
                        COALESCE(so.company_id, wo.company_id) as company_id 
                    FROM work_order wo
                    LEFT JOIN sales_order_item soi ON wo.wo_id = soi.wo_id
                    LEFT JOIN sales_order so ON soi.order_no = so.order_no
                    WHERE wo.wo_id = ?
                    LIMIT 1
                ");
                $stmtSoi->execute([$wo_id]);
                $soi = $stmtSoi->fetch();
                
                if ($soi && !empty($soi['item_name'])) {
                    $cId = (int)($soi['company_id'] ?: $company_id);
                    $iCode = trim($soi['item_code'] ?? '');
                    $iName = trim($soi['item_name'] ?? '');

                    $stmtIt = $pdo->prepare("
                        SELECT id FROM item 
                        WHERE ((item_code != '' AND item_code = ?) OR item_name = ?)
                        ORDER BY (company_id = ?) DESC, id DESC 
                        LIMIT 1
                    ");
                    $stmtIt->execute([$iCode, $iName, $cId]);
                    $itemRow = $stmtIt->fetch();

                    if ($itemRow) {
                        $item_id = (int)$itemRow['id'];
                    } else {
                        $insIt = $pdo->prepare("
                            INSERT INTO item (company_id, item_code, item_name, unit, description) 
                            VALUES (?, ?, ?, 'EA', '작업지시 BOM 연계 자동 생성 품목')
                        ");
                        $insIt->execute([$cId ?: null, $iCode ?: null, $iName]);
                        $item_id = (int)$pdo->lastInsertId();
                    }
                }
            }

            $edit_bom_id  = !empty($input['edit_bom_id']) ? (int)$input['edit_bom_id'] : null;

            // 2. product_master & bom_master 생성 또는 기존 버전 수정
            $product_id = !empty($wo_id) ? "PROD-" . $wo_id : "ITEM-PROD-" . $item_id;
            $stmt = $pdo->prepare("INSERT IGNORE INTO product_master (product_id, product_name) VALUES (?, ?)");
            $stmt->execute([$product_id, "Product for " . ($wo_id ?: "Item #{$item_id}")]);

            if ($edit_bom_id) {
                $stmtBm = $pdo->prepare("UPDATE bom_master SET version = ?, item_id = COALESCE(?, item_id) WHERE bom_id = ?");
                $stmtBm->execute([$version, $item_id, $edit_bom_id]);
                $bom_id = $edit_bom_id;

                // 기존 부품 내역 삭제 후 재등록
                $pdo->prepare("DELETE FROM bom_detail WHERE bom_id = ?")->execute([$bom_id]);
            } else {
                $stmtBm = $pdo->prepare("INSERT INTO bom_master (product_id, item_id, version) VALUES (?, ?, ?)");
                $stmtBm->execute([$product_id, $item_id, $version]);
                $bom_id = (int)$pdo->lastInsertId();
            }

            if ($item_id) {
                $pdo->prepare("UPDATE bom_master SET item_id = ? WHERE product_id = ? AND (item_id IS NULL OR item_id = 0)")
                    ->execute([$item_id, $product_id]);
            }

            // 3. work_order 업데이트
            if (!empty($wo_id)) {
                $stmt = $pdo->prepare("UPDATE work_order SET bom_id = ? WHERE wo_id = ?");
                $stmt->execute([$bom_id, $wo_id]);
            }

            // 3. bom_detail & feeder_setup 인서트
            $detailStmt = $pdo->prepare("INSERT INTO bom_detail (bom_id, part_no, part_name, req_qty, points, provide_qty, location, feeder_slot) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!empty($wo_id)) {
                $delFeeder = $pdo->prepare("DELETE FROM feeder_setup WHERE wo_id = ? AND status != 'VERIFIED'");
                $delFeeder->execute([$wo_id]);
                $insFeeder = $pdo->prepare("INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status) VALUES (?, ?, ?, ?, ?, 'PENDING') ON DUPLICATE KEY UPDATE part_no = VALUES(part_no), location = VALUES(location), req_qty = VALUES(req_qty)");
            }

            // WO 목표 수량 조회 (제공수량 미입력 시 자동 계산용)
            $woTargetQty = 1;
            $woOrderNo = null;
            if (!empty($wo_id)) {
                $stmtWoInfo = $pdo->prepare("
                    SELECT wo.target_qty, soi.order_no 
                    FROM work_order wo 
                    LEFT JOIN sales_order_item soi ON wo.wo_id = soi.wo_id 
                    WHERE wo.wo_id = ? 
                    LIMIT 1
                ");
                $stmtWoInfo->execute([$wo_id]);
                $woInfo = $stmtWoInfo->fetch();
                if ($woInfo) {
                    $woTargetQty = (float)($woInfo['target_qty'] ?: 1);
                    $woOrderNo = $woInfo['order_no'] ?: null;
                }
            }

            foreach ($bom_data as $row) {
                $pNo = trim($row['part_no'] ?? '');
                $pName = trim($row['part_name'] ?? '') ?: $pNo;
                $points = (int)round((float)($row['points'] ?? $row['req_qty'] ?? 1));
                $provQty = (!empty($row['provide_qty']) && (float)$row['provide_qty'] > 0) ? (int)round((float)$row['provide_qty']) : null;
                $loc = trim($row['location'] ?? '');
                $slotNo = !empty($row['feeder_slot']) ? trim((string)$row['feeder_slot']) : null;

                $detailStmt->execute([$bom_id, $pNo, $pName, $points, $points, $provQty, $loc, $slotNo]);
                if (!empty($wo_id) && !empty($slotNo) && isset($insFeeder)) {
                    $slots = array_map('trim', explode(',', $slotNo));
                    foreach ($slots as $sNo) {
                        if (!empty($sNo)) {
                            $insFeeder->execute([$wo_id, $sNo, $pNo, $loc, $points]);
                        }
                    }
                }
            }

            // 4. company bom_mapping 업데이트
            if ($company_id && !empty($mapping)) {
                $mappingJson = json_encode($mapping, JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("UPDATE company SET bom_mapping = ? WHERE id = ?");
                $stmt->execute([$mappingJson, $company_id]);
            }

            // 5. 사급 자재 자동 입고 연계 (제공수량/입고수량 우선, 없으면 목표수량 x 포인트)
            $inbound_count = 0;
            if ($auto_inbound) {
                $matStmt = $pdo->prepare("INSERT INTO material_inout (part_no, part_name, inout_type, supply_type, qty, unit, wo_id, order_no, bom_id, company_id, note) VALUES (?, ?, 'IN', 'CONSIGNED', ?, 'EA', ?, ?, ?, ?, ?)");
                $comp_id = !empty($company_id) ? (int)$company_id : null;
                if (!$comp_id && $item_id) {
                    $stmtC = $pdo->prepare("SELECT company_id FROM item WHERE id = ?");
                    $stmtC->execute([$item_id]);
                    $comp_id = (int)($stmtC->fetchColumn() ?: null);
                }
                if (!$woOrderNo && $item_id) {
                    $stmtO = $pdo->prepare("
                        SELECT order_no FROM sales_order_item 
                        WHERE item_code = (SELECT item_code FROM item WHERE id = ?) 
                           OR item_name = (SELECT item_name FROM item WHERE id = ?) 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtO->execute([$item_id, $item_id]);
                    $woOrderNo = $stmtO->fetchColumn() ?: null;
                }
                
                foreach ($bom_data as $row) {
                    $part_no = trim($row['part_no'] ?? '');
                    $part_name = trim($row['part_name'] ?? '') ?: $part_no;
                    $points = (float)($row['points'] ?? $row['req_qty'] ?? 1);
                    $provQty = !empty($row['provide_qty']) ? (float)$row['provide_qty'] : 0;
                    
                    // 제공/입고 수량이 명시되어 있으면 입고수량으로, 없으면 발주목표수량 * 포인트로 계산
                    $inboundQty = $provQty > 0 ? $provQty : ($points * $woTargetQty);

                    if (!empty($part_no) && $inboundQty > 0) {
                        $loc = !empty($row['location']) ? " [위치: {$row['location']}]" : "";
                        $note = "BOM 등록 자동 연계 사급 입고" . ($wo_id ? " (WO: {$wo_id})" : "") . "{$loc} [포인트: {$points}, 버전: {$version}]";
                        $matStmt->execute([$part_no, $part_name, $inboundQty, $wo_id ?: null, $woOrderNo, $bom_id, $comp_id, $note]);
                        $inbound_count++;
                    }
                }
            }

            $pdo->commit();
            $msg = "BOM 저장 완료 (버전: {$version})";
            if ($auto_inbound && $inbound_count > 0) {
                $msg .= " - 사급 자재 {$inbound_count}건 창고 자동 입고 완료";
            }

            Response::json([
                "status" => "success",
                "message" => $msg,
                "bom_id" => $bom_id,
                "version" => $version,
                "inbound_count" => $inbound_count
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. BOM 비교 및 Diff 분석
     */
    public static function compareBom(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $wo_id    = trim($input['wo_id'] ?? '');
            $item_id  = !empty($input['item_id']) ? (int)$input['item_id'] : null;
            $newItems = $input['new_items'] ?? [];

            if (empty($newItems) || !is_array($newItems)) {
                Response::error("비교할 신규 BOM 데이터가 없습니다.");
            }

            // 1. 기존 BOM ID 탐색
            $existingBomId = null;
            $existingVersion = 'v1.0';

            if (!empty($wo_id)) {
                $stmtWo = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
                $stmtWo->execute([$wo_id]);
                $wo = $stmtWo->fetch();
                if ($wo && !empty($wo['bom_id'])) {
                    $existingBomId = (int)$wo['bom_id'];
                }
            }

            if (!$existingBomId && !empty($item_id)) {
                $stmtItem = $pdo->prepare("SELECT bom_id, version FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
                $stmtItem->execute([$item_id]);
                $bm = $stmtItem->fetch();
                if ($bm) {
                    $existingBomId = (int)$bm['bom_id'];
                    $existingVersion = $bm['version'] ?: 'v1.0';
                }
            }

            $oldItems = [];
            if ($existingBomId) {
                $stmtDetail = $pdo->prepare("SELECT part_no, COALESCE(part_name, '') as part_name, req_qty, COALESCE(location, '') as location, feeder_slot FROM bom_detail WHERE bom_id = ?");
                $stmtDetail->execute([$existingBomId]);
                $oldItems = $stmtDetail->fetchAll();

                $stmtV = $pdo->prepare("SELECT version FROM bom_master WHERE bom_id = ?");
                $stmtV->execute([$existingBomId]);
                $vRow = $stmtV->fetch();
                if ($vRow && !empty($vRow['version'])) {
                    $existingVersion = $vRow['version'];
                }
            }

            if (empty($oldItems)) {
                Response::json([
                    "status" => "success",
                    "is_initial" => true,
                    "is_changed" => true,
                    "existing_version" => null,
                    "next_version" => "v1.0",
                    "summary" => [
                        "total_old" => 0,
                        "total_new" => count($newItems),
                        "added_count" => count($newItems),
                        "removed_count" => 0,
                        "modified_count" => 0,
                        "unchanged_count" => 0
                    ],
                    "diff_list" => array_map(function($item) {
                        return [
                            "status" => "ADDED",
                            "part_no_old" => null,
                            "part_no_new" => $item['part_no'] ?? '',
                            "part_name_old" => null,
                            "part_name_new" => $item['part_name'] ?? '',
                            "qty_old" => null,
                            "qty_new" => (float)($item['req_qty'] ?? 1),
                            "location_old" => null,
                            "location_new" => $item['location'] ?? '',
                            "feeder_slot" => $item['feeder_slot'] ?? null,
                            "change_desc" => "신규 부품 추가"
                        ];
                    }, $newItems)
                ]);
            }

            // 2. Diff 정밀 비교 알고리즘
            $oldMapByLoc = [];
            $oldMapByPn  = [];
            foreach ($oldItems as $o) {
                $locKey = strtoupper(trim($o['location']));
                $pnKey  = strtoupper(trim($o['part_no']));
                if ($locKey !== '') $oldMapByLoc[$locKey] = $o;
                if (!isset($oldMapByPn[$pnKey])) $oldMapByPn[$pnKey] = [];
                $oldMapByPn[$pnKey][] = $o;
            }

            $diffList = [];
            $matchedOldLocs = [];
            $matchedOldPns  = [];

            $addedCount = 0;
            $removedCount = 0;
            $modifiedCount = 0;
            $unchangedCount = 0;

            foreach ($newItems as $n) {
                $newLoc = strtoupper(trim($n['location'] ?? ''));
                $newPn  = strtoupper(trim($n['part_no'] ?? ''));
                $newQty = (float)($n['req_qty'] ?? 1);
                $newPName = trim($n['part_name'] ?? '');

                $matchedOld = null;

                if ($newLoc !== '' && isset($oldMapByLoc[$newLoc])) {
                    $matchedOld = $oldMapByLoc[$newLoc];
                    $matchedOldLocs[$newLoc] = true;
                } else if (isset($oldMapByPn[$newPn]) && count($oldMapByPn[$newPn]) > 0) {
                    $matchedOld = array_shift($oldMapByPn[$newPn]);
                    $matchedOldPns[] = $matchedOld;
                }

                if ($matchedOld) {
                    $oldPn = strtoupper(trim($matchedOld['part_no']));
                    $oldQty = (float)$matchedOld['req_qty'];
                    $oldLoc = trim($matchedOld['location']);
                    $oldPName = trim($matchedOld['part_name']);

                    $changes = [];
                    if ($oldPn !== $newPn) {
                        $changes[] = "품번 변경 ({$matchedOld['part_no']} → {$n['part_no']})";
                    }
                    if (abs($oldQty - $newQty) > 0.0001) {
                        $changes[] = "수량 변경 ({$oldQty} → {$newQty})";
                    }
                    if (strtoupper($oldLoc) !== $newLoc && $newLoc !== '') {
                        $changes[] = "위치 변경 ({$oldLoc} → {$n['location']})";
                    }

                    if (!empty($changes)) {
                        $modifiedCount++;
                        $diffList[] = [
                            "status" => "MODIFIED",
                            "part_no_old" => $matchedOld['part_no'],
                            "part_no_new" => $n['part_no'],
                            "part_name_old" => $oldPName,
                            "part_name_new" => $newPName ?: $oldPName,
                            "qty_old" => $oldQty,
                            "qty_new" => $newQty,
                            "location_old" => $oldLoc,
                            "location_new" => $n['location'] ?? '',
                            "feeder_slot" => $n['feeder_slot'] ?? $matchedOld['feeder_slot'],
                            "change_desc" => implode(', ', $changes)
                        ];
                    } else {
                        $unchangedCount++;
                        $diffList[] = [
                            "status" => "UNCHANGED",
                            "part_no_old" => $matchedOld['part_no'],
                            "part_no_new" => $n['part_no'],
                            "part_name_old" => $oldPName,
                            "part_name_new" => $newPName ?: $oldPName,
                            "qty_old" => $oldQty,
                            "qty_new" => $newQty,
                            "location_old" => $oldLoc,
                            "location_new" => $n['location'] ?? '',
                            "feeder_slot" => $n['feeder_slot'] ?? $matchedOld['feeder_slot'],
                            "change_desc" => "변동 없음"
                        ];
                    }
                } else {
                    $addedCount++;
                    $diffList[] = [
                        "status" => "ADDED",
                        "part_no_old" => null,
                        "part_no_new" => $n['part_no'],
                        "part_name_old" => null,
                        "part_name_new" => $newPName,
                        "qty_old" => null,
                        "qty_new" => $newQty,
                        "location_old" => null,
                        "location_new" => $n['location'] ?? '',
                        "feeder_slot" => $n['feeder_slot'] ?? null,
                        "change_desc" => "신규 부품 추가"
                    ];
                }
            }

            foreach ($oldItems as $o) {
                $locKey = strtoupper(trim($o['location']));
                $isMatched = false;
                if ($locKey !== '' && isset($matchedOldLocs[$locKey])) {
                    $isMatched = true;
                } else if (in_array($o, $matchedOldPns, true)) {
                    $isMatched = true;
                }

                if (!$isMatched) {
                    $removedCount++;
                    $diffList[] = [
                        "status" => "REMOVED",
                        "part_no_old" => $o['part_no'],
                        "part_no_new" => null,
                        "part_name_old" => $o['part_name'],
                        "part_name_new" => null,
                        "qty_old" => (float)$o['req_qty'],
                        "qty_new" => null,
                        "location_old" => $o['location'],
                        "location_new" => null,
                        "feeder_slot" => $o['feeder_slot'],
                        "change_desc" => "부품 삭제 (제외됨)"
                    ];
                }
            }

            $isChanged = ($addedCount > 0 || $removedCount > 0 || $modifiedCount > 0);

            $nextVersion = 'v1.1';
            if (preg_match('/v?([0-9]+)\.([0-9]+)/i', $existingVersion, $vm)) {
                $major = (int)$vm[1];
                $minor = (int)$vm[2];
                if ($removedCount > 0 || $modifiedCount >= 3) {
                    $nextVersion = 'v' . ($major + 1) . '.0';
                } else {
                    $nextVersion = 'v' . $major . '.' . ($minor + 1);
                }
            }

            Response::json([
                "status" => "success",
                "is_initial" => false,
                "is_changed" => $isChanged,
                "existing_version" => $existingVersion,
                "next_version" => $nextVersion,
                "summary" => [
                    "total_old" => count($oldItems),
                    "total_new" => count($newItems),
                    "added_count" => $addedCount,
                    "removed_count" => $removedCount,
                    "modified_count" => $modifiedCount,
                    "unchanged_count" => $unchangedCount
                ],
                "diff_list" => $diffList
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 품목별 BOM 마스터 및 버전 아코디언 목록 조회
     */
    public static function getBomMasters(): void {
        try {
            $pdo = Database::getConnection();
            $search = trim(Request::query('search', ''));
            $company_id = Request::query('company_id');

            $where = "WHERE 1=1";
            $params = [];

            if (!empty($search)) {
                $where .= " AND (i.item_name LIKE :s OR i.item_code LIKE :s OR c.name LIKE :s)";
                $params[':s'] = '%' . $search . '%';
            }
            if (!empty($company_id)) {
                $where .= " AND i.company_id = :cid";
                $params[':cid'] = (int)$company_id;
            }

            $sql = "
                SELECT 
                    i.id as item_id,
                    i.item_code,
                    i.item_name,
                    i.category,
                    COALESCE(i.unit_price, (SELECT soi.unit_price FROM sales_order_item soi WHERE soi.item_name = i.item_name ORDER BY soi.id DESC LIMIT 1), 0) as unit_price,
                    i.description,
                    i.company_id,
                    c.name as company_name,
                    bm.bom_id,
                    bm.version,
                    bm.created_at as version_created_at,
                    (SELECT COUNT(*) FROM bom_detail WHERE bom_id = bm.bom_id) as part_count,
                    (SELECT COALESCE(SUM(req_qty), 0) FROM bom_detail WHERE bom_id = bm.bom_id) as total_req_qty
                FROM item i
                LEFT JOIN company c ON i.company_id = c.id
                LEFT JOIN bom_master bm ON i.id = bm.item_id
                $where
                ORDER BY c.name ASC, i.item_name ASC, bm.bom_id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $grouped = [];
            foreach ($rows as $r) {
                $itemId = (int)$r['item_id'];
                if (!isset($grouped[$itemId])) {
                    $grouped[$itemId] = [
                        'item_id'        => $itemId,
                        'item_code'      => $r['item_code'],
                        'item_name'      => $r['item_name'],
                        'unit_price'     => (float)($r['unit_price'] ?? 0),
                        'specification'  => $r['specification'] ?? '',
                        'company_id'     => (int)$r['company_id'],
                        'company_name'   => $r['company_name'] ?: '미지정',
                        'latest_version' => null,
                        'latest_bom_id'  => null,
                        'total_parts'    => 0,
                        'versions'       => []
                    ];
                }

                if (!empty($r['bom_id'])) {
                    $versionInfo = [
                        'bom_id'        => (int)$r['bom_id'],
                        'version'       => $r['version'] ?: 'v1.0',
                        'created_at'    => $r['version_created_at'],
                        'part_count'    => (int)$r['part_count'],
                        'total_req_qty' => (float)$r['total_req_qty']
                    ];

                    if ($grouped[$itemId]['latest_version'] === null) {
                        $grouped[$itemId]['latest_version'] = $versionInfo['version'];
                        $grouped[$itemId]['latest_bom_id']  = $versionInfo['bom_id'];
                        $grouped[$itemId]['total_parts']    = $versionInfo['part_count'];
                    }

                    $grouped[$itemId]['versions'][] = $versionInfo;
                }
            }

            $totalItems = count($grouped);
            $totalBoms = 0;
            $totalParts = 0;
            foreach ($grouped as $g) {
                $totalBoms += count($g['versions']);
                $totalParts += $g['total_parts'];
            }

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_boms'  => $totalBoms,
                        'total_parts' => $totalParts
                    ],
                    'items' => array_values($grouped)
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 특정 BOM 버전의 부품 상세 리스트 조회
     */
    public static function getBomVersionDetails(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $bom_id = (int)($id ?? Request::query('bom_id', Request::input('bom_id', 0)));

            if ($bom_id <= 0) {
                Response::error("유효한 BOM ID가 필요합니다.");
            }

            $stmtBm = $pdo->prepare("
                SELECT bm.bom_id, bm.version, bm.created_at, bm.item_id, i.item_name, i.item_code, c.name as company_name
                FROM bom_master bm
                LEFT JOIN item i ON bm.item_id = i.id
                LEFT JOIN company c ON i.company_id = c.id
                WHERE bm.bom_id = ?
            ");
            $stmtBm->execute([$bom_id]);
            $master = $stmtBm->fetch();

            if (!$master) {
                Response::error("존재하지 않는 BOM 버전입니다.");
            }

            $stmtDetails = $pdo->prepare("SELECT detail_id, part_no, COALESCE(part_name, '') as part_name, COALESCE(points, req_qty, 1) as points, COALESCE(provide_qty, req_qty) as provide_qty, req_qty, COALESCE(location, '') as location, feeder_slot, COALESCE(is_nc, 0) as is_nc FROM bom_detail WHERE bom_id = ? ORDER BY detail_id ASC");
            $stmtDetails->execute([$bom_id]);
            $details = $stmtDetails->fetchAll();

            foreach ($details as &$d) {
                $d['points'] = (int)round((float)($d['points'] ?? 1));
                $d['req_qty'] = (int)round((float)($d['req_qty'] ?? 1));
                if ($d['provide_qty'] !== null && $d['provide_qty'] !== '') {
                    $d['provide_qty'] = (int)round((float)$d['provide_qty']);
                }
            }
            unset($d);

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

    /**
     * 6. 두 BOM 버전 간의 차이점(Diff) 비교
     */
    public static function compareBomVersions(): void {
        try {
            $pdo = Database::getConnection();
            $bom_id_a = (int)Request::query('bom_id_a', Request::input('bom_id_a', 0));
            $bom_id_b = (int)Request::query('bom_id_b', Request::input('bom_id_b', 0));

            if ($bom_id_a <= 0 || $bom_id_b <= 0) {
                Response::error("비교할 두 개의 BOM ID(bom_id_a, bom_id_b)가 필요합니다.");
            }

            $stmtA = $pdo->prepare("SELECT part_no, part_name, req_qty, location FROM bom_detail WHERE bom_id = ?");
            $stmtA->execute([$bom_id_a]);
            $itemsA = $stmtA->fetchAll();

            $stmtB = $pdo->prepare("SELECT part_no, part_name, req_qty, location FROM bom_detail WHERE bom_id = ?");
            $stmtB->execute([$bom_id_b]);
            $itemsB = $stmtB->fetchAll();

            $mapA = [];
            foreach ($itemsA as $r) {
                $p = trim($r['part_no']);
                $l = trim($r['location'] ?? '');
                $key = $p . '|' . $l;
                $mapA[$key] = $r;
            }

            $mapB = [];
            foreach ($itemsB as $r) {
                $p = trim($r['part_no']);
                $l = trim($r['location'] ?? '');
                $key = $p . '|' . $l;
                $mapB[$key] = $r;
            }

            $diffList = [];
            $added = 0; $removed = 0; $modified = 0; $unchanged = 0;

            foreach ($mapB as $k => $nb) {
                if (!isset($mapA[$k])) {
                    $added++;
                    $diffList[] = [
                        'status' => 'ADDED',
                        'part_no_old' => null, 'qty_old' => null, 'location_old' => null,
                        'part_no_new' => $nb['part_no'], 'part_name_new' => $nb['part_name'], 'qty_new' => (float)$nb['req_qty'], 'location_new' => $nb['location'],
                        'change_desc' => '신규 부품 추가'
                    ];
                } else {
                    $oa = $mapA[$k];
                    $qtyOld = (float)$oa['req_qty'];
                    $qtyNew = (float)$nb['req_qty'];
                    if ($qtyOld != $qtyNew) {
                        $modified++;
                        $diffList[] = [
                            'status' => 'MODIFIED',
                            'part_no_old' => $oa['part_no'], 'part_name_old' => $oa['part_name'], 'qty_old' => $qtyOld, 'location_old' => $oa['location'],
                            'part_no_new' => $nb['part_no'], 'part_name_new' => $nb['part_name'], 'qty_new' => $qtyNew, 'location_new' => $nb['location'],
                            'change_desc' => "수량 변경 ({$qtyOld} ➔ {$qtyNew})"
                        ];
                    } else {
                        $unchanged++;
                        $diffList[] = [
                            'status' => 'UNCHANGED',
                            'part_no_old' => $oa['part_no'], 'part_name_old' => $oa['part_name'], 'qty_old' => $qtyOld, 'location_old' => $oa['location'],
                            'part_no_new' => $nb['part_no'], 'part_name_new' => $nb['part_name'], 'qty_new' => $qtyNew, 'location_new' => $nb['location'],
                            'change_desc' => '변동 없음'
                        ];
                    }
                    unset($mapA[$k]);
                }
            }

            foreach ($mapA as $k => $oa) {
                $removed++;
                $diffList[] = [
                    'status' => 'REMOVED',
                    'part_no_old' => $oa['part_no'], 'part_name_old' => $oa['part_name'], 'qty_old' => (float)$oa['req_qty'], 'location_old' => $oa['location'],
                    'part_no_new' => null, 'part_name_new' => null, 'qty_new' => null, 'location_new' => null,
                    'change_desc' => '부품 삭제됨'
                ];
            }

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_a'   => count($itemsA),
                        'total_b'   => count($itemsB),
                        'added'     => $added,
                        'removed'   => $removed,
                        'modified'  => $modified,
                        'unchanged' => $unchanged
                    ],
                    'diff_list' => $diffList
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * BOM 버전 단일 삭제
     */
    public static function deleteBom(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $bom_id = Request::query('bom_id', $input['bom_id'] ?? Request::input('id'));
            $bom_id = !empty($bom_id) ? (int)$bom_id : 0;

            if (!$bom_id) {
                Response::error("삭제할 BOM ID가 지정되지 않았습니다.");
            }

            // Check if BOM exists
            $stmt = $pdo->prepare("SELECT bm.*, i.item_name FROM bom_master bm LEFT JOIN item i ON bm.item_id = i.id WHERE bm.bom_id = ?");
            $stmt->execute([$bom_id]);
            $bm = $stmt->fetch();
            if (!$bm) {
                Response::error("해당 BOM 버전을 찾을 수 없습니다.");
            }

            $pdo->beginTransaction();

            // 1. Delete bom_detail records
            $stmt = $pdo->prepare("DELETE FROM bom_detail WHERE bom_id = ?");
            $stmt->execute([$bom_id]);

            // 2. Unlink any work_order using this bom_id
            $stmt = $pdo->prepare("UPDATE work_order SET bom_id = NULL WHERE bom_id = ?");
            $stmt->execute([$bom_id]);

            // 3. Delete bom_master record
            $stmt = $pdo->prepare("DELETE FROM bom_master WHERE bom_id = ?");
            $stmt->execute([$bom_id]);

            $pdo->commit();

            Response::success([
                "bom_id" => $bom_id,
                "item_id" => $bm['item_id'],
                "version" => $bm['version']
            ], "[{$bm['item_name']}] {$bm['version']} BOM 버전이 삭제되었습니다.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error("BOM 삭제 중 오류: " . $e->getMessage());
        }
    }

    /**
     * BOM 개별 부품 수정 / 삭제 / 추가
     */
    public static function updateBomComponent(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $detail_id = !empty($input['detail_id']) ? (int)$input['detail_id'] : 0;
            $bom_id = !empty($input['bom_id']) ? (int)$input['bom_id'] : 0;
            $action = trim($input['action'] ?? 'update'); // 'update', 'delete', 'add'

            if ($action === 'delete') {
                if (!$detail_id) Response::error("삭제할 부품 ID가 필요합니다.");
                $stmt = $pdo->prepare("DELETE FROM bom_detail WHERE detail_id = ?");
                $stmt->execute([$detail_id]);
                Response::success([], "부품이 삭제되었습니다.");
                return;
            }

            if ($action === 'reset_all_slots') {
                if (!$bom_id) Response::error("BOM ID가 필요합니다.");
                $stmt = $pdo->prepare("UPDATE bom_detail SET feeder_slot = NULL WHERE bom_id = ?");
                $stmt->execute([$bom_id]);
                Response::success([], "현재 BOM의 모든 피더 슬롯이 초기화되었습니다.");
                return;
            }

            if ($action === 'update_slot') {
                if (!$detail_id) Response::error("부품 ID가 필요합니다.");
                $feeder_slot = isset($input['feeder_slot']) && trim((string)$input['feeder_slot']) !== '' ? trim((string)$input['feeder_slot']) : null;
                $bom_id = !empty($input['bom_id']) ? (int)$input['bom_id'] : 0;

                // If bom_id provided and assigning a slot, clear duplicate slot on any other detail in same BOM
                if ($bom_id > 0 && $feeder_slot !== null) {
                    $slotsToClear = array_map('trim', explode(',', $feeder_slot));
                    foreach ($slotsToClear as $s) {
                        if (!empty($s)) {
                            $stmtClear = $pdo->prepare("UPDATE bom_detail SET feeder_slot = NULL WHERE bom_id = ? AND feeder_slot = ? AND detail_id != ?");
                            $stmtClear->execute([$bom_id, $s, $detail_id]);
                        }
                    }
                }

                $stmt = $pdo->prepare("UPDATE bom_detail SET feeder_slot = ? WHERE detail_id = ?");
                $stmt->execute([$feeder_slot, $detail_id]);
                Response::success(["feeder_slot" => $feeder_slot], "피더 슬롯 번호가 변경되었습니다.");
                return;
            }

            if ($action === 'toggle_nc') {
                if (!$detail_id) Response::error("부품 ID가 필요합니다.");
                $stmt = $pdo->prepare("SELECT * FROM bom_detail WHERE detail_id = ?");
                $stmt->execute([$detail_id]);
                $item = $stmt->fetch();
                if (!$item) Response::error("부품을 찾을 수 없습니다.");

                $is_nc = (int)($item['is_nc'] ?? 0);
                if ($is_nc === 0 && (float)$item['req_qty'] > 0) {
                    $stmtUp = $pdo->prepare("UPDATE bom_detail SET is_nc = 1, req_qty = 0 WHERE detail_id = ?");
                    $stmtUp->execute([$detail_id]);
                    Response::success(["is_nc" => 1, "req_qty" => 0], "부품이 [NC 미실장] 처리되었습니다. (소요량 0, 피더 검사 제외)");
                } else {
                    $stmtUp = $pdo->prepare("UPDATE bom_detail SET is_nc = 0, req_qty = 1 WHERE detail_id = ?");
                    $stmtUp->execute([$detail_id]);
                    Response::success(["is_nc" => 0, "req_qty" => 1], "부품이 정상 실장 상태로 복원되었습니다.");
                }
                return;
            }

            if ($action === 'set_nc_by_pn') {
                $bom_id = !empty($input['bom_id']) ? (int)$input['bom_id'] : 0;
                $part_no = trim($input['part_no'] ?? '');
                $nc_state = !empty($input['is_nc']) ? 1 : 0;
                if (!$bom_id || !$part_no) Response::error("BOM ID와 파트번호가 필요합니다.");

                if ($nc_state === 1) {
                    $stmtUp = $pdo->prepare("UPDATE bom_detail SET is_nc = 1, req_qty = 0 WHERE bom_id = ? AND part_no = ?");
                    $stmtUp->execute([$bom_id, $part_no]);
                    Response::success(["is_nc" => 1], "해당 파트가 [NC 미실장]으로 지정되었습니다.");
                } else {
                    $stmtUp = $pdo->prepare("UPDATE bom_detail SET is_nc = 0, req_qty = 1 WHERE bom_id = ? AND part_no = ?");
                    $stmtUp->execute([$bom_id, $part_no]);
                    Response::success(["is_nc" => 0], "해당 파트가 정상 실장으로 복원되었습니다.");
                }
                return;
            }

            $part_no = trim($input['part_no'] ?? '');
            $part_name = trim($input['part_name'] ?? '') ?: $part_no;
            $req_qty = (int)round((float)($input['req_qty'] ?? 1));
            $location = trim($input['location'] ?? '');
            $feeder_slot = isset($input['feeder_slot']) && trim((string)$input['feeder_slot']) !== '' ? trim((string)$input['feeder_slot']) : null;

            if (empty($part_no)) {
                Response::error("파트번호는 필수입니다.");
            }

            if ($action === 'add') {
                if (!$bom_id) Response::error("BOM ID가 필요합니다.");
                $stmt = $pdo->prepare("INSERT INTO bom_detail (bom_id, part_no, part_name, req_qty, location, feeder_slot) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$bom_id, $part_no, $part_name, $req_qty, $location, $feeder_slot]);
                Response::success(["detail_id" => (int)$pdo->lastInsertId()], "부품이 추가되었습니다.");
                return;
            }

            // update existing
            if (!$detail_id) Response::error("수정할 부품 ID가 필요합니다.");
            $stmt = $pdo->prepare("UPDATE bom_detail SET part_no = ?, part_name = ?, req_qty = ?, location = ?, feeder_slot = ? WHERE detail_id = ?");
            $stmt->execute([$part_no, $part_name, $req_qty, $location, $feeder_slot, $detail_id]);
            Response::success([], "부품 정보가 수정되었습니다.");
        } catch (Exception $e) {
            Response::error("부품 처리 오류: " . $e->getMessage());
        }
    }
}
