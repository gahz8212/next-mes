<?php
// backend/controllers/ProcessController.php

class ProcessController {
    /**
     * 1. 공정 스캔 결과 데이터 업데이트 (단일/일괄)
     */
    public static function updateProcess(): void {
        $pdo = Database::getConnection();
        try {
            $data = Request::getBody();
            if (!$data) {
                Response::error("잘못된 JSON 형식입니다.", 400);
            }

            $events = [];
            if (isset($data['events']) && is_array($data['events'])) {
                $events = $data['events'];
            } else if (isset($data['process_name'])) {
                $events = [$data];
            } else {
                Response::error("이벤트 데이터가 비어있습니다.", 400);
            }

            $pdo->beginTransaction();

            $processedCount = 0;
            $woId = $data['wo_id'] ?? null;

            foreach ($events as $ev) {
                $barcode = $ev['barcode'] ?? null;
                $processName = $ev['process_name'] ?? null;
                $resultStatus = $ev['result_status'] ?? 'PASS';
                $processData = $ev['process_data'] ?? null;

                if (empty($processName)) continue;

                // 1. 대기 이벤트
                if ($resultStatus === 'IDLE' || $resultStatus === 'WAIT' || empty($barcode) || $barcode === '-') {
                    $historyStmt = $pdo->prepare("
                        INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) 
                        VALUES (:barcode, :process_name, :result_status, :process_data, NOW())
                    ");
                    $historyStmt->execute([
                        ':barcode' => '-',
                        ':process_name' => $processName,
                        ':result_status' => 'IDLE',
                        ':process_data' => $processData ? json_encode($processData, JSON_UNESCAPED_UNICODE) : null
                    ]);
                    $processedCount++;
                    continue;
                }

                // 2. 바코드 마스터 확인
                $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
                $stmt->execute([':barcode' => $barcode]);
                $barcodeRow = $stmt->fetch();

                if (!$barcodeRow) {
                    $extractedWoId = $woId ?: substr($barcode, 0, strrpos($barcode, '-'));
                    $stmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (:barcode, (SELECT wo_id FROM work_order WHERE wo_id = :wo_id LIMIT 1), 'WAIT')");
                    $stmt->execute([':barcode' => $barcode, ':wo_id' => $extractedWoId]);

                    $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
                    $stmt->execute([':barcode' => $barcode]);
                    $barcodeRow = $stmt->fetch();

                    if (!$barcodeRow || empty($barcodeRow['wo_id'])) {
                        continue;
                    }
                }

                $bWoId = $barcodeRow["wo_id"];
                if (!$woId) $woId = $bWoId;
                $nextStatus = "";

                // 3. 상태 전이
                if (($barcodeRow['status'] ?? '') === 'DEFECT' || ($barcodeRow['status'] ?? '') === 'FAIL' || $resultStatus === 'FAIL') {
                    $nextStatus = 'DEFECT';
                } else {
                    switch ($processName) {
                        case 'LASER':
                        case 'SPI':
                            $nextStatus = 'IN_PROCESS';
                            break;
                        case 'MOUNTER':
                        case 'MOUNTER_1':
                        case 'MOUNTER_2':
                            $nextStatus = 'TOP_DONE';
                            break;
                        case 'REFLOW':
                            $nextStatus = 'BOTTOM_DONE';
                            break;
                        case 'DIP_AOI':
                            $nextStatus = 'TEST_PASS';
                            break;
                        case 'WAVE':
                        case 'ICT':
                        case 'COATING':
                        case 'FCT':
                            $nextStatus = 'SHIPPING';
                            break;
                        default:
                            continue 2;
                    }
                }

                // 4. 상태 업데이트
                $updateStmt = $pdo->prepare("UPDATE barcode_master SET status = :status WHERE barcode = :barcode");
                $updateStmt->execute([':status' => $nextStatus, ':barcode' => $barcode]);

                // 5. 이력 기록
                $historyStmt = $pdo->prepare("
                    INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) 
                    VALUES (:barcode, :process_name, :result_status, :process_data, NOW())
                ");
                $historyStmt->execute([
                    ':barcode' => $barcode,
                    ':process_name' => $processName,
                    ':result_status' => $resultStatus,
                    ':process_data' => $processData ? json_encode($processData, JSON_UNESCAPED_UNICODE) : null
                ]);

                $processedCount++;
            }

            // 6. 작업지시(WO) 완료 판정
            $isComplete = !empty($data['is_complete']);
            if ($woId && $isComplete) {
                $chkStmt = $pdo->prepare("SELECT target_qty, status FROM work_order WHERE wo_id = :wo_id");
                $chkStmt->execute([':wo_id' => $woId]);
                $woInfo = $chkStmt->fetch();

                if ($woInfo) {
                    $simMode = $data['sim_mode'] ?? 'SMT';
                    if ($simMode === 'SMT' && $woInfo['status'] === 'IN_PROGRESS') {
                        $pdo->prepare("UPDATE work_order SET status = 'SMT_DONE' WHERE wo_id = :wo_id")
                            ->execute([':wo_id' => $woId]);
                    } else if ($simMode === 'DIP' && ($woInfo['status'] === 'DIP_IN_PROGRESS' || $woInfo['status'] === 'SMT_DONE')) {
                        $pdo->prepare("UPDATE work_order SET status = 'DONE', completed_at = NOW() WHERE wo_id = :wo_id")
                            ->execute([':wo_id' => $woId]);
                    }
                }
            }

            $pdo->commit();
            Response::json(["status" => "success", "processed_events" => $processedCount]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage(), 409);
        }
    }

    /**
     * 2. 불량 분석 현황 조회
     */
    public static function getDefects(): void {
        try {
            $pdo = Database::getConnection();
            $startDate   = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
            $endDate     = Request::query('end_date', date('Y-m-d'));
            $process     = trim(Request::query('process', ''));
            $companyName = trim(Request::query('company_name', ''));

            $params = [':start' => $startDate, ':end' => $endDate];

            // 1. Summary
            $sqlSummary = "
                SELECT 
                  COUNT(CASE WHEN bh.result_status='FAIL' THEN 1 END) as total_fail,
                  COUNT(CASE WHEN bh.result_status='PASS' THEN 1 END) as total_pass,
                  ROUND(COUNT(CASE WHEN bh.result_status='FAIL' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0),1) as fail_rate
                FROM barcode_history bh
                LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
                LEFT JOIN work_order w ON bm.wo_id = w.wo_id
                LEFT JOIN company c ON w.company_id = c.id
                WHERE DATE(bh.created_at) BETWEEN :start AND :end
            ";
            if (!empty($process))     $sqlSummary .= " AND bh.process_name = :process";
            if (!empty($companyName)) $sqlSummary .= " AND c.name = :company_name";

            $stmtSummary = $pdo->prepare($sqlSummary);
            $sumParams = $params;
            if (!empty($process))     $sumParams[':process'] = $process;
            if (!empty($companyName)) $sumParams[':company_name'] = $companyName;
            $stmtSummary->execute($sumParams);
            $summaryRow = $stmtSummary->fetch();

            $summary = [
                'total_fail' => $summaryRow ? (int)$summaryRow['total_fail'] : 0,
                'total_pass' => $summaryRow ? (int)$summaryRow['total_pass'] : 0,
                'fail_rate'  => ($summaryRow && $summaryRow['fail_rate'] !== null) ? (float)$summaryRow['fail_rate'] : 0.0
            ];

            // 2. 공정별
            $sqlProcess = "
                SELECT 
                  bh.process_name,
                  COUNT(*) as fail_count
                FROM barcode_history bh
                LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
                LEFT JOIN work_order w ON bm.wo_id = w.wo_id
                LEFT JOIN company c ON w.company_id = c.id
                WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
            ";
            $procParams = [':start' => $startDate, ':end' => $endDate];
            if (!empty($companyName)) {
                $sqlProcess .= " AND c.name = :company_name";
                $procParams[':company_name'] = $companyName;
            }
            $sqlProcess .= " GROUP BY bh.process_name ORDER BY fail_count DESC";

            $stmtProcess = $pdo->prepare($sqlProcess);
            $stmtProcess->execute($procParams);
            $byProcessRows = $stmtProcess->fetchAll();

            $totalProcFails = array_sum(array_column($byProcessRows, 'fail_count'));
            $by_process = [];
            foreach ($byProcessRows as $row) {
                $cnt = (int)$row['fail_count'];
                $by_process[] = [
                    'process_name' => $row['process_name'],
                    'fail_count'   => $cnt,
                    'ratio'        => $totalProcFails > 0 ? round($cnt * 100.0 / $totalProcFails, 1) : 0.0
                ];
            }

            // 3. 업체별
            $sqlCompany = "
                SELECT 
                  COALESCE(c.name, '기타') as company_name,
                  COUNT(bh.history_id) as fail_count,
                  COALESCE(SUM(CASE WHEN bm.status IN ('SHIPPING') THEN 1 ELSE 0 END), 0) as good_count
                FROM barcode_history bh
                LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
                LEFT JOIN work_order w ON bm.wo_id = w.wo_id
                LEFT JOIN company c ON w.company_id = c.id
                WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
            ";
            $compParams = [':start' => $startDate, ':end' => $endDate];
            if (!empty($process)) {
                $sqlCompany .= " AND bh.process_name = :process";
                $compParams[':process'] = $process;
            }
            $sqlCompany .= " GROUP BY c.id, c.name ORDER BY fail_count DESC";

            $stmtCompany = $pdo->prepare($sqlCompany);
            $stmtCompany->execute($compParams);
            $byCompanyRows = $stmtCompany->fetchAll();

            $totalCompFails = array_sum(array_column($byCompanyRows, 'fail_count'));
            $by_company = [];
            foreach ($byCompanyRows as $row) {
                $cnt = (int)$row['fail_count'];
                $by_company[] = [
                    'company_name' => $row['company_name'],
                    'fail_count'   => $cnt,
                    'good_count'   => (int)$row['good_count'],
                    'ratio'        => $totalCompFails > 0 ? round($cnt * 100.0 / $totalCompFails, 1) : 0.0
                ];
            }

            // 4. 최근 불량 이력
            $sqlRecent = "
                SELECT 
                  bh.barcode, bh.process_name, bh.created_at,
                  w.wo_id, COALESCE(c.name, '미지정') as company_name,
                  COALESCE(
                      (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.wo_id LIMIT 1),
                      (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                      (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.wo_id LIMIT 1),
                      (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                      (SELECT i.item_name FROM bom_master bm JOIN item i ON bm.item_id = i.id WHERE bm.bom_id = w.bom_id LIMIT 1),
                      (SELECT bm.product_id FROM bom_master bm WHERE bm.bom_id = w.bom_id LIMIT 1),
                      '—'
                  ) as item_name
                FROM barcode_history bh
                LEFT JOIN barcode_master bm ON bh.barcode = bm.barcode
                LEFT JOIN work_order w ON bm.wo_id = w.wo_id
                LEFT JOIN company c ON w.company_id = c.id
                WHERE bh.result_status = 'FAIL' AND DATE(bh.created_at) BETWEEN :start AND :end
            ";
            if (!empty($process))     $sqlRecent .= " AND bh.process_name = :process";
            if (!empty($companyName)) $sqlRecent .= " AND c.name = :company_name";
            $sqlRecent .= " ORDER BY DATE(bh.created_at) DESC, c.name ASC, bh.barcode ASC LIMIT 50";

            $stmtRecent = $pdo->prepare($sqlRecent);
            $stmtRecent->execute($sumParams);
            $recentRows = $stmtRecent->fetchAll();

            $recent = [];
            foreach ($recentRows as $row) {
                $recent[] = [
                    'barcode'      => $row['barcode'],
                    'process_name' => $row['process_name'],
                    'created_at'   => $row['created_at'],
                    'wo_id'        => $row['wo_id'],
                    'company_name' => $row['company_name'],
                    'item_name'    => $row['item_name']
                ];
            }

            Response::json([
                'status' => 'success',
                'data'   => [
                    'summary'    => $summary,
                    'by_process' => $by_process,
                    'by_company' => $by_company,
                    'recent'     => $recent
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 기판 수리 처리
     */
    public static function repairBoard(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $barcode = trim($input['barcode'] ?? '');

            if (!$barcode) {
                Response::error("바코드를 입력하세요.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT status FROM barcode_master WHERE barcode = ?");
            $stmt->execute([$barcode]);
            $target = $stmt->fetch();

            if (!$target) throw new Exception("존재하지 않는 바코드입니다.");
            if ($target['status'] !== 'TEST_FAIL' && $target['status'] !== 'FAIL') {
                throw new Exception("불량 판정을 받은 기판만 수리할 수 있습니다. (현재 상태: " . $target['status'] . ")");
            }

            $updateStmt = $pdo->prepare("UPDATE barcode_master SET status = 'REPAIRED' WHERE barcode = ?");
            $updateStmt->execute([$barcode]);

            $historyStmt = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status) VALUES (?, ?, ?)");
            $historyStmt->execute([$barcode, 'REPAIR_PROCESS', 'FIXED']);

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "기판 수리가 완료되었습니다. 재검사 라인으로 이동합니다.",
                "barcode" => $barcode
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(["status" => "fail", "message" => $e->getMessage()]);
        }
    }

    /**
     * 4. 라인 초기화
     */
    public static function resetLine(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $targetWoId = trim($input['wo_id'] ?? 'C1-20260813-2A6');

            $pdo->beginTransaction();

            $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
            $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;
            $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
            if ($fp) {
                $stopData = json_encode(["wo_id" => $targetWoId]);
                $out = "POST /stop-sim HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($stopData) . "\r\nConnection: Close\r\n\r\n" . $stopData;
                fwrite($fp, $out);
                fclose($fp);
            }

            $pdo->exec("DELETE FROM barcode_history WHERE barcode LIKE 'WO-DOCKER-TEST%'");
            $pdo->exec("DELETE FROM barcode_master WHERE wo_id LIKE 'WO-DOCKER-TEST%'");
            $pdo->exec("DELETE FROM work_order WHERE wo_id LIKE 'WO-DOCKER-TEST%'");

            $pdo->prepare("UPDATE work_order SET status = 'READY', completed_at = NULL WHERE wo_id = ?")->execute([$targetWoId]);
            $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ?")->execute([$targetWoId]);
            $pdo->prepare("DELETE FROM barcode_history WHERE barcode LIKE ?")->execute(["{$targetWoId}-%"]);

            $slotsStmt = $pdo->prepare("SELECT id, part_no, slot_no FROM feeder_setup WHERE wo_id = ?");
            $slotsStmt->execute([$targetWoId]);
            $slots = $slotsStmt->fetchAll();
            foreach ($slots as $s) {
                $reelStmt = $pdo->prepare("SELECT reel_barcode FROM reel_master WHERE part_no = ? LIMIT 1");
                $reelStmt->execute([$s['part_no']]);
                $reel = $reelStmt->fetch();
                $barcode = $reel ? $reel['reel_barcode'] : 'REEL-SLOT-' . $s['slot_no'];

                $pdo->prepare("UPDATE feeder_setup SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = 'SystemReset' WHERE id = ?")
                    ->execute([$barcode, $s['id']]);
            }

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "작업지시 [{$targetWoId}] 및 설비 라인이 대기(READY) 상태로 깨끗이 초기화되었습니다.",
                "wo_id" => $targetWoId
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 설비 예방정비(TPM) 완료 이력 기록 및 초기화
     */
    public static function resetMaintenance(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $processName = trim($input['process_name'] ?? '');
            $itemName    = trim($input['item_name'] ?? '정기 소모품 교체 및 클리닝');
            $operator    = trim($input['operator'] ?? 'Worker-OP');

            if (empty($processName)) {
                Response::error("설비 공정 코드(process_name)가 필요합니다.");
            }

            $clientIp = Request::getClientIp();
            $desc = "[설비 TPM 예방보전] {$processName} - {$itemName} 정비 작업 완료 및 수명/건전도 100% 회복 (담당: {$operator})";

            $pdo->beginTransaction();

            $logStmt = $pdo->prepare("
                INSERT INTO system_log (username, action_type, description, ip_address, created_at)
                VALUES (?, 'MAINTENANCE_RESET', ?, ?, NOW())
            ");
            $logStmt->execute([$operator, $desc, $clientIp]);

            $notifStmt = $pdo->prepare("
                INSERT INTO system_notification (type, title, message, is_read, link_url, created_at)
                VALUES ('SUCCESS', ?, ?, 0, ?, NOW())
            ");
            $notifTitle = "[설비 정비 완료] {$processName}";
            $notifMsg   = "{$itemName} 작업이 정상 완료되어 설비 종합 건전도가 100%로 갱신되었습니다.";
            $linkUrl    = "machine.html?eq={$processName}";
            $notifStmt->execute([$notifTitle, $notifMsg, $linkUrl]);

            $pdo->commit();

            Response::json([
                "status" => "success",
                "message" => "설비 [{$processName}]의 정비 이력이 성공적으로 등록되고 초기화되었습니다.",
                "data" => [
                    "process_name" => $processName,
                    "item_name" => $itemName,
                    "operator" => $operator,
                    "health_score" => 100,
                    "rul_percent" => 100,
                    "maintained_at" => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error("정비 이력 저장 실패: " . $e->getMessage(), 500);
        }
    }

    /**
     * 6. 시스템 초기화 (전체 초기화 'full' vs 기초정보 유지 초기화 'transactions')
     */
    public static function resetSystem(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $mode = trim($input['mode'] ?? ($_GET['mode'] ?? 'transactions')); // 'full' or 'transactions'

            $pdo->beginTransaction();

            // 1. Stop Node-RED simulator if running
            $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
            $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;
            $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
            if ($fp) {
                $stopData = json_encode(["wo_id" => "ALL"]);
                $out = "POST /stop-sim HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($stopData) . "\r\nConnection: Close\r\n\r\n" . $stopData;
                fwrite($fp, $out);
                fclose($fp);
            }

            // 2. Clear transactional logs & history
            $pdo->exec("DELETE FROM shipment");
            $pdo->exec("DELETE FROM barcode_history");
            $pdo->exec("DELETE FROM barcode_master");
            $pdo->exec("DELETE FROM feeder_setup");
            $pdo->exec("DELETE FROM consigned_return_detail");
            $pdo->exec("DELETE FROM consigned_return_master");
            $pdo->exec("DELETE FROM material_inout");
            $pdo->exec("DELETE FROM work_order");
            $pdo->exec("DELETE FROM sales_order_item");
            $pdo->exec("DELETE FROM sales_order");
            $pdo->exec("DELETE FROM system_notification");
            $pdo->exec("UPDATE line_status SET status = 'IDLE', current_wo_id = NULL");

            if ($mode === 'full') {
                // 3. Insert initial welcome notification
                $pdo->prepare("INSERT INTO system_notification (type, title, message, is_read, link_url, created_at) VALUES ('SUCCESS', '공장 전체 초기화 완료', '모든 수주, 작업지시, 사급 자재, 출하 데이터가 깨끗이 초기화(0건)되었습니다. 신규 수주부터 등록하여 테스트하실 수 있습니다.', 0, 'admin.html', NOW())")->execute();

                $msg = "⚡ 전체 공장 데이터(수주, 작업지시, 생산계획, 출하 등)가 깨끗이 초기화(0건)되었습니다.";
            } else {
                // 3. Insert notification
                $pdo->prepare("INSERT INTO system_notification (type, title, message, is_read, link_url, created_at) VALUES ('SUCCESS', '트랜잭션 실적 초기화 완료', '거래처/품목/BOM 기초정보는 안전하게 보존되고, 수주/작업지시/생산계획/출하 실적이 깨끗이 초기화(0건)되었습니다.', 0, 'admin.html', NOW())")->execute();

                $msg = "🔄 기초 마스터 정보(거래처, 품목, BOM 등)는 유지하고, 모든 수주/작업지시/생산계획/출하 데이터가 깨끗이 초기화(0건)되었습니다.";
            }

            $pdo->commit();

            Response::json([
                "status" => "success",
                "mode"   => $mode,
                "message" => $msg
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error("시스템 초기화 실패: " . $e->getMessage(), 500);
        }
    }
}
