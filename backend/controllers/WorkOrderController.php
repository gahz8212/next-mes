<?php
// backend/controllers/WorkOrderController.php

class WorkOrderController {
    /**
     * 헬퍼: Node-RED 논블로킹 HTTP 요청
     */
    private static function triggerNodeRed(string $path, array $data): void {
        $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
        $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
        if ($fp) {
            $out = "POST {$path} HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: Close\r\n\r\n" . $json;
            fwrite($fp, $out);
            fclose($fp);
        }
    }

    /**
     * 1. 작업지시 목록 조회 (피더 검증 상태 포함)
     */
    public static function getWoList(): void {
        try {
            $pdo = Database::getConnection();
            // SMT 실적 목표 수량 도달 시 SMT_DONE 자동 동기화
            $pdo->query("
                UPDATE work_order w
                JOIN (
                    SELECT wo_id, count(*) as proc_cnt 
                    FROM barcode_master 
                    WHERE status != 'WAIT' 
                    GROUP BY wo_id
                ) b ON w.wo_id = b.wo_id
                SET w.status = 'SMT_DONE'
                WHERE w.status = 'IN_PROGRESS' AND b.proc_cnt >= w.target_qty AND w.target_qty > 0
            ");
            $stmt = $pdo->query("
                SELECT 
                    w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id,
                    c.name as company_name,
                    (SELECT count(*) FROM feeder_setup fs WHERE fs.wo_id = w.wo_id) as feeder_total,
                    (SELECT COALESCE(SUM(CASE WHEN fs.status = 'VERIFIED' THEN 1 ELSE 0 END), 0) FROM feeder_setup fs WHERE fs.wo_id = w.wo_id) as feeder_verified
                FROM work_order w
                LEFT JOIN company c ON w.company_id = c.id
                WHERE w.status IN ('READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS') 
                ORDER BY w.due_date ASC, w.wo_id ASC
            ");
            $list = $stmt->fetchAll();

            foreach ($list as &$item) {
                $total = (int)($item['feeder_total'] ?? 0);
                $verified = (int)($item['feeder_verified'] ?? 0);
                $item['target_qty'] = (int)$item['target_qty'];
                $item['feeder_total'] = $total;
                $item['feeder_verified'] = $verified;
                $item['feeder_ready'] = ($total > 0 && $verified === $total);
            }
            unset($item);

            Response::json(["status" => "success", "data" => $list]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 관리자용 작업지시 상세 목록 및 KPI 집계
     */
    public static function getAdminWoList(): void {
        try {
            $pdo = Database::getConnection();
            // SMT 실적 목표 수량 도달 시 SMT_DONE 자동 동기화
            $pdo->query("
                UPDATE work_order w
                JOIN (
                    SELECT wo_id, count(*) as proc_cnt 
                    FROM barcode_master 
                    WHERE status != 'WAIT' 
                    GROUP BY wo_id
                ) b ON w.wo_id = b.wo_id
                SET w.status = 'SMT_DONE'
                WHERE w.status = 'IN_PROGRESS' AND b.proc_cnt >= w.target_qty AND w.target_qty > 0
            ");
            $stmt = $pdo->query("
                SELECT
                    w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id, w.company_id,
                    w.completed_at, w.shipped, w.shipped_at, w.remark, w.parent_wo_id,
                    c.name as company_name, c.bom_mapping,
                    COALESCE(
                        (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.wo_id LIMIT 1),
                        (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                        (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.wo_id LIMIT 1),
                        (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                        (SELECT i.item_name FROM bom_master bm JOIN item i ON bm.item_id = i.id WHERE bm.bom_id = w.bom_id LIMIT 1),
                        (SELECT bm.product_id FROM bom_master bm WHERE bm.bom_id = w.bom_id LIMIT 1),
                        '—'
                    ) as item_name,
                    COALESCE(
                        (SELECT soi.order_no FROM sales_order_item soi WHERE soi.wo_id = w.wo_id LIMIT 1),
                        (SELECT soi.order_no FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                        (SELECT so.order_no FROM sales_order so WHERE so.wo_id = w.wo_id LIMIT 1),
                        (SELECT so.order_no FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                        ''
                    ) as order_no,
                    COALESCE(SUM(CASE WHEN b.status != 'WAIT' THEN 1 ELSE 0 END), 0) as processed_qty,
                    COALESCE(SUM(CASE WHEN b.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING') THEN 1 ELSE 0 END), 0) as good_qty,
                    COALESCE(SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END), 0) as fail_qty,
                    COALESCE(SUM(CASE WHEN b.status IN ('SHIPPING','FAIL') THEN 1 ELSE 0 END), 0) as dip_qty
                FROM work_order w
                LEFT JOIN company c ON w.company_id = c.id
                LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
                GROUP BY w.wo_id, w.target_qty, w.due_date, w.status, w.bom_id, w.company_id,
                         w.completed_at, w.shipped, w.shipped_at, w.remark, w.parent_wo_id, c.name, c.bom_mapping
                ORDER BY (CASE WHEN w.status = 'HOLD' THEN 1 ELSE 0 END) ASC, w.due_date ASC
            ");
            $list = $stmt->fetchAll();

            $ready = [];
            $completed = [];

            $in_progress_count = 0;
            $today_done_count  = 0;
            $urgent_count      = 0;
            $hold_count        = 0;
            $total_good        = 0;
            $total_actual      = 0;
            $today = date('Y-m-d');

            foreach ($list as &$wo) {
                $wo['has_bom']       = !empty($wo['bom_id']);
                $wo['target_qty']    = (int)$wo['target_qty'];
                $wo['processed_qty'] = (int)$wo['processed_qty'];
                $wo['good_qty']      = (int)$wo['good_qty'];
                $wo['fail_qty']      = (int)$wo['fail_qty'];
                $wo['dip_qty']       = (int)$wo['dip_qty'];
                $wo['remark']        = $wo['remark'] ?? '';
                if (empty($wo['parent_wo_id'])) {
                    if (preg_match('/^(.*)-(?:B|S\d+)$/', $wo['wo_id'], $m)) {
                        $wo['parent_wo_id'] = $m[1];
                    }
                }

                $wo['dday'] = null;
                if ($wo['due_date']) {
                    $diff = (strtotime($wo['due_date']) - strtotime($today)) / 86400;
                    $wo['dday'] = (int)$diff;
                }

                if (in_array($wo['status'], ['READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS', 'HOLD'])) {
                    $ready[] = $wo;
                    if ($wo['status'] === 'HOLD') {
                        $hold_count++;
                    } else if (in_array($wo['status'], ['IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS'])) {
                        $in_progress_count++;
                    }
                    if ($wo['status'] !== 'HOLD' && $wo['dday'] !== null && $wo['dday'] <= 7) {
                        $urgent_count++;
                    }
                } else {
                    $completed[] = $wo;
                    if ($wo['completed_at'] && substr($wo['completed_at'], 0, 10) === $today) {
                        $today_done_count++;
                    }
                    $total_good   += $wo['good_qty'];
                    $total_actual += $wo['processed_qty'];
                }
            }
            unset($wo);

            $overall_yield = $total_actual > 0 ? round($total_good / $total_actual * 100, 1) : null;

            Response::json([
                "status" => "success",
                "data"   => [
                    "ready"        => $ready,
                    "completed"    => $completed,
                    "summary"      => [
                        "in_progress"   => $in_progress_count,
                        "today_done"    => $today_done_count,
                        "overall_yield" => $overall_yield,
                        "urgent"        => $urgent_count,
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 작업지시 등록
     */
    public static function createWo(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $target_qty = (int)($input['target_qty'] ?? 0);
            $due_date = $input['due_date'] ?? null;
            $company_id = !empty($input['company_id']) ? (int)$input['company_id'] : null;

            if (!$wo_id || $target_qty <= 0) {
                Response::error("WO ID와 수량을 입력하세요.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO work_order (wo_id, company_id, target_qty, status, due_date) VALUES (?, ?, ?, 'READY', ?)");
            $stmt->execute([$wo_id, $company_id, $target_qty, $due_date]);

            $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
            for ($i = 1; $i <= $target_qty; $i++) {
                $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
                $barcodeStmt->execute([$barcode, $wo_id]);
            }

            $pdo->commit();
            Response::json(["status" => "success", "message" => "{$target_qty}개 바코드 발행 완료"]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(["status" => "fail", "message" => $e->getMessage()]);
        }
    }

    /**
     * 4. 작업지시 수정
     */
    public static function updateWo(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($id ?? ($input['wo_id'] ?? ''));
            $target_qty = (int)($input['target_qty'] ?? 0);
            $due_date = $input['due_date'] ?? null;

            if (empty($wo_id) || $target_qty <= 0) {
                Response::error("필수 데이터가 누락되었거나 수량이 잘못되었습니다.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status FROM work_order WHERE wo_id = ? FOR UPDATE");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();

            if (!$wo) {
                throw new Exception("존재하지 않는 작업지시입니다.");
            }
            if ($wo['status'] !== 'READY') {
                throw new Exception("대기중(READY)인 작업지시만 수정할 수 있습니다.");
            }

            $stmt = $pdo->prepare("UPDATE work_order SET target_qty = ?, due_date = ? WHERE wo_id = ?");
            $stmt->execute([$target_qty, $due_date, $wo_id]);

            $stmt = $pdo->prepare("DELETE FROM barcode_master WHERE wo_id = ?");
            $stmt->execute([$wo_id]);

            $barcodeStmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')");
            for ($i = 1; $i <= $target_qty; $i++) {
                $barcode = "{$wo_id}-" . str_pad($i, 4, '0', STR_PAD_LEFT);
                $barcodeStmt->execute([$barcode, $wo_id]);
            }

            $pdo->commit();
            Response::json(["status" => "success", "message" => "작업지시가 성공적으로 수정되었습니다."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 작업지시 삭제 (분할된 작업지시인 경우 원본 지시로 수량 합산 복원)
     */
    public static function deleteWo(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($id ?? ($input['wo_id'] ?? ''));

            if (empty($wo_id)) {
                Response::error("작업지시 번호가 누락되었습니다.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT wo_id, status, target_qty, parent_wo_id, remark FROM work_order WHERE wo_id = ? FOR UPDATE");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();

            if (!$wo) {
                throw new Exception("존재하지 않는 작업지시입니다.");
            }
            if ($wo['status'] !== 'READY' && $wo['status'] !== 'HOLD') {
                throw new Exception("대기(READY) 또는 보류(HOLD) 상태인 작업지시만 삭제할 수 있습니다.");
            }

            // Check if barcodes were already scanned/processed
            $chkProcessed = $pdo->prepare("SELECT count(*) FROM barcode_master WHERE wo_id = ? AND status != 'WAIT'");
            $chkProcessed->execute([$wo_id]);
            if ((int)$chkProcessed->fetchColumn() > 0) {
                throw new Exception("이미 생산 공정이 진행된 작업지시는 삭제할 수 없습니다.");
            }

            // Identify parent WO if this is a split WO
            $parent_wo_id = $wo['parent_wo_id'] ?? null;
            if (!$parent_wo_id && !empty($wo['remark'])) {
                if (preg_match('/\(원지시:\s*([^\)]+)\)/u', $wo['remark'], $m)) {
                    $parent_wo_id = trim($m[1]);
                }
            }
            if (!$parent_wo_id) {
                if (preg_match('/^(.*)-(?:B|S\d+)$/', $wo_id, $m)) {
                    $possibleParent = trim($m[1]);
                    $chk = $pdo->prepare("SELECT count(*) FROM work_order WHERE wo_id = ?");
                    $chk->execute([$possibleParent]);
                    if ((int)$chk->fetchColumn() > 0) {
                        $parent_wo_id = $possibleParent;
                    }
                }
            }

            $splitRestored = false;
            $split_qty = (int)$wo['target_qty'];

            if (!empty($parent_wo_id)) {
                // Check if parent WO exists
                $chkParent = $pdo->prepare("SELECT wo_id, target_qty, remark FROM work_order WHERE wo_id = ? FOR UPDATE");
                $chkParent->execute([$parent_wo_id]);
                $parentWo = $chkParent->fetch();

                if ($parentWo) {
                    // Restore target_qty to parent WO
                    $newParentQty = (int)$parentWo['target_qty'] + $split_qty;
                    // Clean up split note from parent remark if any
                    $cleanParentRemark = preg_replace('/\s*\[분할\s*1차:\s*\d+대\]/u', '', $parentWo['remark'] ?? '');
                    $cleanParentRemark = trim($cleanParentRemark);

                    $upParent = $pdo->prepare("UPDATE work_order SET target_qty = ?, remark = ? WHERE wo_id = ?");
                    $upParent->execute([$newParentQty, $cleanParentRemark, $parent_wo_id]);
                    $splitRestored = true;
                }
            }

            // Reset sales_order_item if directly linked to this WO
            $pdo->prepare("UPDATE sales_order_item SET wo_id = NULL, status = 'RECEIVED' WHERE wo_id = ?")->execute([$wo_id]);

            // Delete current WO and its barcodes/feeder setups
            $pdo->prepare("DELETE FROM barcode_master WHERE wo_id = ?")->execute([$wo_id]);
            $pdo->prepare("DELETE FROM feeder_setup WHERE wo_id = ?")->execute([$wo_id]);
            $pdo->prepare("DELETE FROM work_order WHERE wo_id = ?")->execute([$wo_id]);

            $pdo->commit();

            if ($splitRestored) {
                Response::json([
                    "status"  => "success",
                    "message" => "분할된 작업지시 [{$wo_id}]가 삭제(취소)되어, 분할 수량({$split_qty} EA)이 원본 지시 [{$parent_wo_id}]로 합산 복원되었습니다."
                ]);
            } else {
                Response::json(["status" => "success", "message" => "작업지시가 성공적으로 삭제되었습니다."]);
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 작업지시 시작 (SMT 인터록 검증 & 시작)
     */
    public static function startWo(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $force = !empty($input['force']);

            if (!$wo_id) {
                Response::error("WO ID가 필요합니다.");
            }

            $pdo->beginTransaction();

            // 1. SMT 인터록 검사
            if (!$force) {
                $checkFeeder = $pdo->prepare("
                    SELECT 
                        count(*) as total,
                        COALESCE(SUM(CASE WHEN status = 'VERIFIED' THEN 1 ELSE 0 END), 0) as verified
                    FROM feeder_setup 
                    WHERE wo_id = ?
                ");
                $checkFeeder->execute([$wo_id]);
                $feederStat = $checkFeeder->fetch();

                if ($feederStat && $feederStat['total'] > 0 && $feederStat['verified'] < $feederStat['total']) {
                    $unverified = $feederStat['total'] - $feederStat['verified'];
                    throw new Exception("SMT 라인 인터록 잠김(LOCK): 자재 피킹 및 피더 검증이 완료되지 않았습니다. (미검증 피더: {$unverified}개) 먼저 피더 셋업을 완료해 주세요.");
                }
            }

            $stmt = $pdo->prepare("SELECT status, target_qty FROM work_order WHERE wo_id = ? FOR UPDATE");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();

            if (!$wo) throw new Exception("해당 작업지시를 찾을 수 없습니다: " . $wo_id);
            if ($wo['status'] !== 'READY' && $wo['status'] !== 'IN_PROGRESS') {
                throw new Exception("해당 작업지시를 시작할 수 없습니다. (현재 상태: " . $wo['status'] . ")");
            }

            $pdo->prepare("UPDATE work_order SET status = 'IN_PROGRESS' WHERE wo_id = ?")->execute([$wo_id]);
            $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ?")->execute([$wo_id]);

            $pdo->commit();

            self::triggerNodeRed('/start-sim', [
                "wo_id" => $wo_id,
                "target_qty" => (int)$wo['target_qty']
            ]);

            Response::json(["status" => "success", "message" => "SMT 라인 인터록 해제 및 자삽 공정이 시작되었습니다."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 7. 수삽(DIP) 공정 시작
     */
    public static function startDipWo(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');

            if (!$wo_id) {
                Response::error("WO ID가 필요합니다.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status, target_qty FROM work_order WHERE wo_id = ? FOR UPDATE");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();

            if (!$wo) throw new Exception("해당 작업지시를 찾을 수 없습니다: " . $wo_id);
            if (!in_array($wo['status'], ['SMT_DONE', 'DIP_IN_PROGRESS', 'IN_PROGRESS'])) {
                throw new Exception("해당 작업지시를 수삽 시작할 수 없습니다. (현재 상태: " . $wo['status'] . ")");
            }

            $pdo->prepare("UPDATE work_order SET status = 'DIP_IN_PROGRESS' WHERE wo_id = ?")->execute([$wo_id]);
            $pdo->prepare("UPDATE barcode_master SET status = 'BOTTOM_DONE' WHERE wo_id = ? AND status != 'FAIL'")->execute([$wo_id]);

            $pdo->commit();

            self::triggerNodeRed('/start-dip-sim', [
                "wo_id" => $wo_id,
                "target_qty" => (int)$wo['target_qty']
            ]);

            Response::json(["status" => "success", "message" => "수삽 공정이 시작되었습니다. 시뮬레이션이 가동됩니다."]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 8. 작업지시 가동 중단
     */
    public static function stopWo(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');

            if (!$wo_id) {
                Response::error("wo_id가 필요합니다.");
            }

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE work_order SET status = 'READY' WHERE wo_id = ?")->execute([$wo_id]);
            $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ? AND status = 'IN_PROGRESS'")->execute([$wo_id]);

            $pdo->commit();

            self::triggerNodeRed('/stop-sim', ["wo_id" => $wo_id]);

            Response::json([
                "status" => "success",
                "message" => "작업지시 [{$wo_id}] 가동이 안전하게 중단되고 대기(READY) 상태로 전환되었습니다."
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 9. 라인 작업지시 긴급 스위칭
     */
    public static function switchLineWo(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $line_id = trim($input['line_id'] ?? '');
            $new_wo_id = trim($input['new_wo_id'] ?? '');

            if (!$line_id || !$new_wo_id) {
                Response::error("라인 ID와 새 작업지시 번호를 입력하세요.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT bom_id, status FROM work_order WHERE wo_id = ?");
            $stmt->execute([$new_wo_id]);
            $new_wo = $stmt->fetch();

            if (!$new_wo) throw new Exception("존재하지 않는 작업지시입니다.");
            if ($new_wo['status'] === 'DONE') throw new Exception("이미 완료된 작업지시입니다.");

            $pdo->prepare("UPDATE line_status SET current_wo_id = ?, status = 'RUN' WHERE line_id = ?")->execute([$new_wo_id, $line_id]);
            $pdo->prepare("UPDATE work_order SET status = 'IN_PROGRESS' WHERE wo_id = ?")->execute([$new_wo_id]);

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "긴급 오더({$new_wo_id})로 라인 작업이 교체되었습니다. 기준 BOM이 즉시 변경됩니다."
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(["status" => "fail", "message" => $e->getMessage()]);
        }
    }

    /**
     * 10. 납품 처리 (Ship WO)
     */
    public static function shipWo(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');

            if (!$wo_id) {
                Response::error("WO ID가 필요합니다.");
            }

            $stmt = $pdo->prepare("UPDATE work_order SET shipped = 1, shipped_at = NOW() WHERE wo_id = ? AND status = 'DONE'");
            $stmt->execute([$wo_id]);

            if ($stmt->rowCount() > 0) {
                // Auto-restore any temporary NC components in BOM for future production runs
                $woStmt = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
                $woStmt->execute([$wo_id]);
                $woRow = $woStmt->fetch();
                $restoredCount = 0;
                if ($woRow && !empty($woRow['bom_id'])) {
                    $restoreStmt = $pdo->prepare("UPDATE bom_detail SET is_nc = 0, req_qty = 1 WHERE bom_id = ? AND is_nc = 1");
                    $restoreStmt->execute([$woRow['bom_id']]);
                    $restoredCount = $restoreStmt->rowCount();
                }

                $msg = "납품 처리되었습니다.";
                if ($restoredCount > 0) {
                    $msg .= "\n(임시 NC 처리되었던 부품 {$restoredCount}건이 다음 생산을 위해 정상 실장 상태로 자동 복원되었습니다.)";
                }
                Response::json(["status" => "success", "message" => $msg]);
            } else {
                Response::error("완료된 작업지시만 납품 처리 가능합니다.");
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 11. 생산 계획 달력 조회
     */
    public static function getProductionPlan(): void {
        try {
            $pdo = Database::getConnection();
            $year  = Request::query('year') !== null && Request::query('year') !== '' ? (int)Request::query('year') : (int)date('Y');
            $month = Request::query('month') !== null && Request::query('month') !== '' ? (int)Request::query('month') : (int)date('m');

            $whereSql = "((YEAR(w.due_date) = :year1";
            $params = [':year1' => $year, ':year2' => $year];
            if ($month > 0) {
                $whereSql .= " AND MONTH(w.due_date) = :month1)";
                $whereSql .= " OR (w.delivery_date IS NOT NULL AND YEAR(w.delivery_date) = :year2 AND MONTH(w.delivery_date) = :month2))";
                $params[':month1'] = $month;
                $params[':month2'] = $month;
            } else {
                $whereSql .= ") OR (w.delivery_date IS NOT NULL AND YEAR(w.delivery_date) = :year2))";
            }

            $stmt = $pdo->prepare("
                SELECT 
                  w.wo_id, w.target_qty, w.status, w.due_date, w.completed_at, w.delivery_date,
                  w.shipped, w.shipped_at, w.remark, w.parent_wo_id, w.company_id,
                  c.name as company_name,
                  COALESCE(
                      (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.wo_id LIMIT 1),
                      (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                      (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.wo_id LIMIT 1),
                      (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                      (SELECT i.item_name FROM bom_master bm JOIN item i ON bm.item_id = i.id WHERE bm.bom_id = w.bom_id LIMIT 1),
                      (SELECT bm.product_id FROM bom_master bm WHERE bm.bom_id = w.bom_id LIMIT 1),
                      '—'
                  ) as item_name,
                  COALESCE(
                      (SELECT soi.order_no FROM sales_order_item soi WHERE soi.wo_id = w.wo_id LIMIT 1),
                      (SELECT soi.order_no FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                      (SELECT so.order_no FROM sales_order so WHERE so.wo_id = w.wo_id LIMIT 1),
                      (SELECT so.order_no FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                      ''
                  ) as order_no,
                  COALESCE(SUM(CASE WHEN b.status != 'WAIT' THEN 1 ELSE 0 END), 0) as processed_qty,
                  COALESCE(SUM(CASE WHEN b.status IN ('SHIPPING') THEN 1 ELSE 0 END), 0) as good_qty,
                  COALESCE(SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END), 0) as fail_qty
                FROM work_order w
                LEFT JOIN company c ON w.company_id = c.id
                LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
                WHERE {$whereSql}
                GROUP BY w.wo_id, w.target_qty, w.status, w.due_date, w.completed_at, w.delivery_date, w.shipped, w.shipped_at, w.remark, w.parent_wo_id, w.company_id, c.name, w.bom_id
                ORDER BY COALESCE(w.delivery_date, w.due_date) ASC
            ");
            $stmt->execute($params);

            $rows = $stmt->fetchAll();
            $orders = [];
            foreach ($rows as $row) {
                $orders[] = [
                    'wo_id'         => $row['wo_id'],
                    'target_qty'    => (int)$row['target_qty'],
                    'status'        => $row['status'],
                    'due_date'      => $row['due_date'],
                    'completed_at'  => $row['completed_at'],
                    'delivery_date' => $row['delivery_date'],
                    'shipped'       => (int)$row['shipped'],
                    'shipped_at'    => $row['shipped_at'],
                    'remark'        => $row['remark'],
                    'parent_wo_id'  => $row['parent_wo_id'],
                    'company_id'    => $row['company_id'],
                    'company_name'  => $row['company_name'],
                    'item_name'     => $row['item_name'],
                    'order_no'      => $row['order_no'],
                    'processed_qty' => (int)$row['processed_qty'],
                    'good_qty'      => (int)$row['good_qty'],
                    'fail_qty'      => (int)$row['fail_qty']
                ];
            }

            Response::json([
                'status' => 'success',
                'data'   => [
                    'year'   => $year,
                    'month'  => $month,
                    'orders' => $orders
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 12. 완료된 작업지시의 납품(출하) 일정 등록/수정
     */
    public static function updateDeliveryDate(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $delivery_date = trim($input['delivery_date'] ?? '');
            $remark = trim($input['remark'] ?? '');

            if (!$wo_id) {
                Response::error("작업지시 ID(wo_id)가 필요합니다.");
            }

            $stmt = $pdo->prepare("SELECT wo_id, status, target_qty, company_id FROM work_order WHERE wo_id = ?");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();
            if (!$wo) {
                Response::error("해당 작업지시를 찾을 수 없습니다: " . $wo_id);
            }

            $deliveryVal = !empty($delivery_date) ? $delivery_date : null;

            $upStmt = $pdo->prepare("UPDATE work_order SET delivery_date = ?, remark = COALESCE(NULLIF(?, ''), remark) WHERE wo_id = ?");
            $upStmt->execute([$deliveryVal, $remark, $wo_id]);

            // shipment 테이블 연계 (출하 대기 레코드 자동 연동)
            if ($deliveryVal) {
                $chkShip = $pdo->prepare("SELECT id FROM shipment WHERE wo_id = ? LIMIT 1");
                $chkShip->execute([$wo_id]);
                $shipRow = $chkShip->fetch();
                if ($shipRow) {
                    $pdo->prepare("UPDATE shipment SET ship_date = ? WHERE id = ?")->execute([$deliveryVal, $shipRow['id']]);
                } else {
                    $pdo->prepare("INSERT INTO shipment (wo_id, ship_qty, ship_date, company_id, status) VALUES (?, ?, ?, ?, 'PENDING')")
                        ->execute([$wo_id, (int)$wo['target_qty'], $deliveryVal, $wo['company_id']]);
                }
            }

            // 감사 로그
            $pdo->prepare("INSERT INTO system_log (username, action_type, description) VALUES ('admin', 'WO_DELIVERY_DATE_SET', ?)")
                ->execute(["작업지시 [{$wo_id}] 납품 일정 지정: " . ($deliveryVal ?: '미지정')]);

            Response::success([
                'wo_id' => $wo_id,
                'delivery_date' => $deliveryVal
            ], "납품 일정이 저장되었습니다.");

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }           $orders = [];
            foreach ($rows as $row) {
                $orders[] = [
                    'wo_id'         => $row['wo_id'],
                    'target_qty'    => (int)$row['target_qty'],
                    'status'        => $row['status'],
                    'due_date'      => $row['due_date'],
                    'completed_at'  => $row['completed_at'],
                    'shipped'       => (int)$row['shipped'],
                    'shipped_at'    => $row['shipped_at'],
                    'remark'        => $row['remark'],
                    'parent_wo_id'  => $row['parent_wo_id'],
                    'company_id'    => $row['company_id'],
                    'company_name'  => $row['company_name'],
                    'item_name'     => $row['item_name'],
                    'order_no'      => $row['order_no'],
                    'processed_qty' => (int)$row['processed_qty'],
                    'good_qty'      => (int)$row['good_qty'],
                    'fail_qty'      => (int)$row['fail_qty']
                ];
            }

            Response::json([
                'status' => 'success',
                'data'   => [
                    'year'   => $year,
                    'month'  => $month,
                    'orders' => $orders
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 12. 작업지시 분할 (Lot Split - 부족분 분할 생산)
     */
    public static function splitWorkOrder(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $current_qty = (int)($input['current_qty'] ?? 0);
            $split_qty = (int)($input['split_qty'] ?? 0);
            $reason = trim($input['reason'] ?? '결품으로 인한 분할 생산');

            if (empty($wo_id) || $current_qty <= 0 || $split_qty <= 0) {
                Response::error("작업지시 번호와 분할 수량(1차, 2차)이 올바르지 않습니다.");
            }

            $stmt = $pdo->prepare("SELECT * FROM work_order WHERE wo_id = ?");
            $stmt->execute([$wo_id]);
            $orig = $stmt->fetch();
            if (!$orig) {
                Response::error("해당 작업지시를 찾을 수 없습니다.");
            }

            // Generate unique split WO ID (e.g. WO-20260818-001-B or WO-20260818-001-S1)
            $new_wo_id = $wo_id . '-B';
            $seq = 2;
            while (true) {
                $check = $pdo->prepare("SELECT count(*) FROM work_order WHERE wo_id = ?");
                $check->execute([$new_wo_id]);
                if ((int)$check->fetchColumn() === 0) break;
                $new_wo_id = $wo_id . "-S{$seq}";
                $seq++;
            }

            $pdo->beginTransaction();

            // 1. Update original WO target qty
            $upStmt = $pdo->prepare("UPDATE work_order SET target_qty = ?, remark = CONCAT(COALESCE(remark, ''), ' [분할 1차: {$current_qty}대]') WHERE wo_id = ?");
            $upStmt->execute([$current_qty, $wo_id]);

            // 2. Insert new split WO as HOLD with parent_wo_id
            $insStmt = $pdo->prepare("
                INSERT INTO work_order (wo_id, bom_id, target_qty, status, due_date, company_id, remark, parent_wo_id)
                VALUES (?, ?, ?, 'HOLD', ?, ?, ?, ?)
            ");
            $splitRemark = "분할 잔여 대기: {$reason} (원지시: {$wo_id})";
            $insStmt->execute([$new_wo_id, $orig['bom_id'], $split_qty, $orig['due_date'], $orig['company_id'], $splitRemark, $wo_id]);

            $pdo->commit();

            Response::json([
                "status"  => "success",
                "message" => "작업지시가 성공적으로 분할되었습니다.\n• 1차 생산: {$wo_id} ({$current_qty}대)\n• 잔여 대기: {$new_wo_id} ({$split_qty}대 - 보류)",
                "data"    => [
                    "original_wo_id" => $wo_id,
                    "original_qty"   => $current_qty,
                    "split_wo_id"    => $new_wo_id,
                    "split_qty"      => $split_qty
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error("지시 분할 실패: " . $e->getMessage());
        }
    }

    /**
     * 13. 작업지시 보류(HOLD) 및 해제
     */
    public static function setWorkOrderHold(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $hold = !empty($input['hold']);
            $reason = trim($input['reason'] ?? '');

            if (empty($wo_id)) {
                Response::error("작업지시 번호가 필요합니다.");
            }

            if ($hold) {
                $stmt = $pdo->prepare("UPDATE work_order SET status = 'HOLD', remark = ? WHERE wo_id = ?");
                $stmt->execute([$reason ?: '자재 결품/대기로 인한 생산 보류', $wo_id]);
                Response::json(["status" => "success", "message" => "작업지시 [{$wo_id}] 가 '생산 보류(HOLD)' 상태로 전환되었습니다."]);
            } else {
                $stmt = $pdo->prepare("UPDATE work_order SET status = 'READY', remark = CONCAT(COALESCE(remark, ''), ' [보류 해제]') WHERE wo_id = ?");
                $stmt->execute([$wo_id]);
                Response::json(["status" => "success", "message" => "작업지시 [{$wo_id}] 가 '대기(READY)' 상태로 재개되었습니다."]);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
