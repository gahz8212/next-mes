<?php
// backend/controllers/DashboardController.php

class DashboardController {
    /**
     * 1. 대시보드 통계 조회
     */
    public static function getDashboard(): void {
        try {
            $pdo = Database::getConnection();

            $woStmt = $pdo->query("
                SELECT wo.wo_id, wo.target_qty,
                       (SELECT COUNT(*) FROM barcode_master WHERE wo_id = wo.wo_id AND status = 'TEST_PASS') as pass_qty,
                       (SELECT COUNT(*) FROM barcode_master WHERE wo_id = wo.wo_id AND status = 'TEST_FAIL') as fail_qty
                FROM line_status ls
                JOIN work_order wo ON ls.current_wo_id = wo.wo_id
                WHERE ls.line_id = 'LINE_01'
            ");
            $woData = $woStmt->fetch();

            $logStmt = $pdo->query("
                SELECT barcode, process_name, result_status, DATE_FORMAT(created_at, '%H:%i:%s') as created_at 
                FROM barcode_history 
                ORDER BY created_at DESC LIMIT 8
            ");
            $logs = $logStmt->fetchAll();

            $countStmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_count,
                    COALESCE(SUM(CASE WHEN result_status = 'PASS' THEN 1 ELSE 0 END), 0) as pass_count
                FROM barcode_history
            ");
            $counts = $countStmt->fetch();

            Response::json([
                "status" => "success",
                "data" => [
                    "total_count" => (int)($counts['total_count'] ?? 0),
                    "pass_count"  => (int)($counts['pass_count'] ?? 0),
                    "history"     => $logs
                ]
            ]);

        } catch (Exception $e) {
            Response::json(["status" => "fail", "message" => $e->getMessage()]);
        }
    }

    /**
     * 2. 활성 작업지시 KPI 조회
     */
    public static function getKpi(): void {
        try {
            $pdo = Database::getConnection();
            $targetWoId = trim($_GET['wo_id'] ?? '');

            // 활성 작업지시 확인 및 실시간 컨베이어 틱 실행
            $activeStmt = $pdo->prepare("
                SELECT wo_id, status, target_qty 
                FROM work_order 
                WHERE status IN ('IN_PROGRESS', 'DIP_IN_PROGRESS') 
                " . ($targetWoId ? "AND wo_id = ?" : "") . "
                ORDER BY due_date ASC
                LIMIT 1
            ");
            if ($targetWoId) {
                $activeStmt->execute([$targetWoId]);
            } else {
                $activeStmt->execute();
            }
            $activeWo = $activeStmt->fetch();
            if ($activeWo) {
                self::autoTickConveyorPipeline($pdo, $activeWo);
            }

            if ($targetWoId) {
                $stmt = $pdo->prepare("
                    SELECT
                        wo.wo_id,
                        wo.target_qty,
                        wo.status,
                        COALESCE(SUM(CASE WHEN bm.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'DONE') THEN 1 ELSE 0 END), 0) as good_qty,
                        COALESCE(SUM(CASE WHEN bm.status IN ('DEFECT', 'FAIL') THEN 1 ELSE 0 END), 0) as fail_qty
                    FROM work_order wo
                    LEFT JOIN barcode_master bm ON wo.wo_id = bm.wo_id
                    WHERE wo.wo_id = ?
                    GROUP BY wo.wo_id, wo.target_qty, wo.status
                ");
                $stmt->execute([$targetWoId]);
            } else {
                $stmt = $pdo->query("
                    SELECT
                        wo.wo_id,
                        wo.target_qty,
                        wo.status,
                        COALESCE(SUM(CASE WHEN bm.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'DONE') THEN 1 ELSE 0 END), 0) as good_qty,
                        COALESCE(SUM(CASE WHEN bm.status IN ('DEFECT', 'FAIL') THEN 1 ELSE 0 END), 0) as fail_qty
                    FROM work_order wo
                    LEFT JOIN barcode_master bm ON wo.wo_id = bm.wo_id
                    WHERE wo.status IN ('IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS')
                    GROUP BY wo.wo_id, wo.target_qty, wo.status
                    ORDER BY wo.due_date ASC
                    LIMIT 1
                ");
            }
            $data = $stmt->fetch();

            if ($data) {
                $good  = (int)$data['good_qty'];
                $fail  = (int)$data['fail_qty'];
                $total = $good + $fail;
                $yield = $total > 0 ? number_format(($good / $total) * 100, 1) : '100.0';

                // 투입 및 실장 진행 수량 집계 (LASER 투입 기준)
                $stmtIn = $pdo->prepare("SELECT count(*) FROM barcode_history WHERE barcode LIKE ? AND process_name = 'LASER' AND result_status != 'IDLE'");
                $stmtIn->execute([$data['wo_id'] . '-%']);
                $inputQty = (int)$stmtIn->fetchColumn();

                Response::json([
                    "status" => "success",
                    "data" => [
                        "wo_id"      => $data['wo_id'],
                        "target_qty" => (int)$data['target_qty'],
                        "actual_qty" => $total,
                        "input_qty"  => $inputQty,
                        "good_qty"   => $good,
                        "fail_qty"   => $fail,
                        "wo_status"  => $data['status'],
                        "yield_rate" => $yield . '%'
                    ]
                ]);
            } else {
                Response::json([
                    "status" => "success",
                    "data" => [
                        "wo_id"      => null,
                        "target_qty" => 0,
                        "actual_qty" => 0,
                        "input_qty"  => 0,
                        "good_qty"   => 0,
                        "fail_qty"   => 0,
                        "wo_status"  => 'READY',
                        "yield_rate" => "100.0%"
                    ]
                ]);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. KPI 심층 분석 (Analytics)
     */
    public static function getKpiAnalytics(): void {
        try {
            $pdo = Database::getConnection();
            $days = Request::query('days') ? (int)Request::query('days') : 14;
            if ($days <= 0 || $days > 90) $days = 14;

            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            $endDate   = date('Y-m-d');

            // 1. 종합 누적 통계
            $stmtOverall = $pdo->query("
                SELECT
                    COUNT(DISTINCT w.wo_id) as total_wo,
                    COALESCE(SUM(w.target_qty), 0) as total_target_qty,
                    COALESCE(SUM(CASE WHEN b.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'DONE', 'DEFECT', 'FAIL') THEN 1 ELSE 0 END), 0) as total_processed,
                    COALESCE(SUM(CASE WHEN b.status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING') THEN 1 ELSE 0 END), 0) as total_good,
                    COALESCE(SUM(CASE WHEN b.status = 'FAIL' THEN 1 ELSE 0 END), 0) as total_fail
                FROM work_order w
                LEFT JOIN barcode_master b ON w.wo_id = b.wo_id
            ");
            $overall = $stmtOverall->fetch();

            $totalGood = (int)($overall['total_good'] ?? 0);
            $totalFail = (int)($overall['total_fail'] ?? 0);
            $totalProcessed = (int)($overall['total_processed'] ?? 0);
            $overallYield = $totalProcessed > 0 ? round(($totalGood / $totalProcessed) * 100, 1) : 100.0;

            // 2. 납기 준수율
            $stmtDelivery = $pdo->query("
                SELECT 
                    COUNT(*) as completed_total,
                    COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND completed_at IS NOT NULL AND DATE(completed_at) <= due_date THEN 1 ELSE 0 END), 0) as on_time_count
                FROM work_order
                WHERE status = 'DONE'
            ");
            $delivery = $stmtDelivery->fetch();
            $completedTotal = (int)($delivery['completed_total'] ?? 0);
            $onTimeCount    = (int)($delivery['on_time_count'] ?? 0);
            $onTimeRate     = $completedTotal > 0 ? round(($onTimeCount / $completedTotal) * 100, 1) : 100.0;

            // 3. 일별 추이
            $stmtDaily = $pdo->prepare("
                SELECT 
                    DATE(created_at) as log_date,
                    COALESCE(SUM(CASE WHEN result_status = 'PASS' THEN 1 ELSE 0 END), 0) as pass_count,
                    COALESCE(SUM(CASE WHEN result_status = 'FAIL' THEN 1 ELSE 0 END), 0) as fail_count,
                    COUNT(*) as total_count
                FROM barcode_history
                WHERE DATE(created_at) BETWEEN :start AND :end
                GROUP BY DATE(created_at)
                ORDER BY log_date ASC
            ");
            $stmtDaily->execute([':start' => $startDate, ':end' => $endDate]);
            $dailyRows = $stmtDaily->fetchAll();

            $dailyTrend = [];
            foreach ($dailyRows as $row) {
                $pass = (int)$row['pass_count'];
                $fail = (int)$row['fail_count'];
                $tot  = (int)$row['total_count'];
                $yield = $tot > 0 ? round(($pass / $tot) * 100, 1) : 100.0;
                $dailyTrend[] = [
                    'date'  => $row['log_date'],
                    'pass'  => $pass,
                    'fail'  => $fail,
                    'total' => $tot,
                    'yield' => $yield
                ];
            }

            // 4. 라인 상태
            $stmtLine = $pdo->query("SELECT * FROM line_status ORDER BY line_id ASC");
            $lines = $stmtLine->fetchAll();

            // 5. 공정 통계
            $stmtProcess = $pdo->prepare("
                SELECT 
                    process_name,
                    COUNT(*) as count,
                    COALESCE(SUM(CASE WHEN result_status = 'FAIL' THEN 1 ELSE 0 END), 0) as fail_count
                FROM barcode_history
                WHERE DATE(created_at) BETWEEN :start AND :end
                GROUP BY process_name
                ORDER BY count DESC
            ");
            $stmtProcess->execute([':start' => $startDate, ':end' => $endDate]);
            $processStats = $stmtProcess->fetchAll();

            Response::json([
                "status" => "success",
                "data"   => [
                    "summary" => [
                        "total_wo"        => (int)($overall['total_wo'] ?? 0),
                        "total_target"    => (int)($overall['total_target_qty'] ?? 0),
                        "total_processed" => $totalProcessed,
                        "total_good"      => $totalGood,
                        "total_fail"      => $totalFail,
                        "overall_yield"   => $overallYield,
                        "on_time_rate"    => $onTimeRate,
                        "completed_total" => $completedTotal,
                        "on_time_count"   => $onTimeCount
                    ],
                    "daily_trend"    => $dailyTrend,
                    "lines"          => $lines,
                    "process_stats"  => $processStats,
                    "period"         => [
                        "start" => $startDate,
                        "end"   => $endDate,
                        "days"  => $days
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 초고속 실시간 센서 로그 폴링 API
     */
    public static function getLiveLogs(): void {
        try {
            $pdo = Database::getConnection();
            $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

            $stmtWo = $pdo->query("
                SELECT wo_id, status, target_qty 
                FROM work_order 
                WHERE status IN ('IN_PROGRESS', 'DIP_IN_PROGRESS', 'SMT_DONE', 'READY', 'DONE') 
                ORDER BY FIELD(status, 'IN_PROGRESS', 'DIP_IN_PROGRESS', 'SMT_DONE', 'READY', 'DONE'), wo_id DESC 
                LIMIT 1
            ");
            $activeWo = $stmtWo->fetch();

            $activeStatus = $activeWo['status'] ?? 'READY';
            if ($activeWo && ($activeStatus === 'IN_PROGRESS' || $activeStatus === 'DIP_IN_PROGRESS')) {
                self::autoTickConveyorPipeline($pdo, $activeWo);
            }

            $processFilterSql = "";
            if ($activeStatus === 'DIP_IN_PROGRESS') {
                $processFilterSql = " AND h.process_name IN ('DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT') ";
            } else if ($activeStatus === 'IN_PROGRESS') {
                $processFilterSql = " AND h.process_name IN ('LASER', 'SPI', 'MOUNTER', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW') ";
            }

            if ($last_id > 0) {
                $stmt = $pdo->prepare("
                    SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                           COALESCE(b.status, 'ING') AS barcode_status, 
                           COALESCE(b.wo_id, :active_wo_id) AS wo_id,
                           :target_qty AS target_qty, 
                           :wo_status AS wo_status
                    FROM barcode_history h
                    LEFT JOIN barcode_master b ON h.barcode = b.barcode
                    WHERE h.history_id > :last_id
                    {$processFilterSql}
                    ORDER BY h.history_id ASC
                    LIMIT 100
                ");
                $stmt->execute([
                    ':last_id'      => $last_id,
                    ':active_wo_id' => $activeWo['wo_id'] ?? null,
                    ':target_qty'   => $activeWo['target_qty'] ?? 0,
                    ':wo_status'    => $activeStatus
                ]);
                $logs = $stmt->fetchAll();
                $max_id = !empty($logs) ? (int)end($logs)['history_id'] : $last_id;
            } else {
                $stmtMax = $pdo->query("SELECT COALESCE(MAX(history_id), 0) FROM barcode_history");
                $currentMax = (int)$stmtMax->fetchColumn();

                if ($activeWo && ($activeStatus === 'IN_PROGRESS' || $activeStatus === 'DIP_IN_PROGRESS')) {
                    $stmt = $pdo->prepare("
                        SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                               COALESCE(b.status, 'ING') AS barcode_status, b.wo_id, 
                               :target_qty AS target_qty, :wo_status AS wo_status
                        FROM barcode_history h
                        LEFT JOIN barcode_master b ON h.barcode = b.barcode
                        WHERE b.wo_id = :wo_id
                        {$processFilterSql}
                        ORDER BY h.history_id DESC
                        LIMIT 5
                    ");
                    $stmt->execute([
                        ':wo_id'      => $activeWo['wo_id'],
                        ':target_qty' => $activeWo['target_qty'] ?? 0,
                        ':wo_status'  => $activeStatus
                    ]);
                    $logs = array_reverse($stmt->fetchAll());
                } else {
                    $logs = [];
                }

                $max_id = $currentMax;
            }

            Response::json([
                "status" => "success",
                "data" => [
                    "logs"      => $logs,
                    "max_id"    => $max_id,
                    "active_wo" => $activeWo
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 자율 컨베이어 파이프라인 시뮬레이션 틱 생성 (Node-RED 미구동 시 자동 동작)
     */
    private static function autoTickConveyorPipeline(PDO $pdo, array $activeWo): void {
        try {
            $woId = $activeWo['wo_id'] ?? '';
            $status = $activeWo['status'] ?? '';
            $targetQty = (int)($activeWo['target_qty'] ?? 0);
            if (!$woId || ($status !== 'IN_PROGRESS' && $status !== 'DIP_IN_PROGRESS')) return;

            // 1.0초 이내에 생성된 이력이 있으면 중복 틱 방지
            $stmtLast = $pdo->query("SELECT created_at FROM barcode_history ORDER BY history_id DESC LIMIT 1");
            $lastRow = $stmtLast->fetch();
            if ($lastRow && (time() - strtotime($lastRow['created_at'])) < 1) {
                return;
            }

            $mode = ($status === 'DIP_IN_PROGRESS') ? 'DIP' : 'SMT';
            $processList = ($mode === 'SMT')
                ? ["LASER", "SPI", "MOUNTER_1", "MOUNTER_2", "REFLOW"]
                : ["DIP_AOI", "WAVE", "ICT", "COATING", "FCT"];

            $firstProc = $processList[0];
            $lastProc  = $processList[4];

            // 1. 투입 수량(inCount) 및 완제품 배출 수량(outCount) 집계
            $stmtIn = $pdo->prepare("SELECT count(*) FROM barcode_history WHERE barcode LIKE ? AND process_name = ? AND result_status != 'IDLE'");
            $stmtIn->execute([$woId . '-%', $firstProc]);
            $inCount = (int)$stmtIn->fetchColumn();

            $stmtOut = $pdo->prepare("SELECT count(*) FROM barcode_history WHERE barcode LIKE ? AND process_name = ? AND result_status != 'IDLE'");
            $stmtOut->execute([$woId . '-%', $lastProc]);
            $outCount = (int)$stmtOut->fetchColumn();

            // 2. 전체 목표 수량이 공정 파이프라인을 완전히 빠져나왔는지 확인
            if ($outCount >= $targetQty && $targetQty > 0) {
                if ($mode === 'SMT') {
                    $pdo->prepare("UPDATE work_order SET status = 'SMT_DONE' WHERE wo_id = ? AND status = 'IN_PROGRESS'")->execute([$woId]);
                } else {
                    $pdo->prepare("UPDATE work_order SET status = 'DONE', completed_at = NOW() WHERE wo_id = ? AND status = 'DIP_IN_PROGRESS'")->execute([$woId]);
                }
                return;
            }

            // 3. 현재 컨베이어 슬롯 전진 스텝(currentStep) 계산
            if ($inCount < $targetQty) {
                $currentStep = $inCount + 1;
            } else {
                $currentStep = $targetQty + ($outCount - max(0, $targetQty - 4)) + 1;
            }

            // 4. 5개 설비 슬롯별 기판 이동 및 텔레메트리 생성 (Pipeline Shift)
            foreach ($processList as $pIdx => $proc) {
                $pcbNum = $currentStep - $pIdx;

                if ($pcbNum >= 1 && $pcbNum <= $targetQty) {
                    $barcode = sprintf('%s-%04d', $woId, $pcbNum);

                    // 기판 바코드 마스터 등록 및 상태 갱신
                    $pdo->prepare("INSERT IGNORE INTO barcode_master (barcode, wo_id, status) VALUES (?, ?, 'WAIT')")->execute([$barcode, $woId]);

                    $isFail = (mt_rand(1, 100) <= 2);
                    $pdmHealth = $isFail ? 82 : mt_rand(95, 99);

                    $pdata = [
                        'pcb_no' => $pcbNum,
                        'metric_name' => ($proc === 'LASER' ? '레이저 출력' : ($proc === 'SPI' ? '납 도포 체적율' : ($proc === 'MOUNTER_1' ? '노즐 진공압' : ($proc === 'MOUNTER_2' ? '부품 가압력' : ($proc === 'REFLOW' ? '피크 프로파일 온도' : '공정 검사값'))))),
                        'metric_val' => ($proc === 'LASER' ? round(15.2 + (mt_rand(-3, 3) / 10), 2) : ($proc === 'SPI' ? round(102.5 + (mt_rand(-5, 5) / 10), 1) : ($proc === 'MOUNTER_1' ? round(-84.2 + (mt_rand(-15, 15) / 10), 1) : ($proc === 'MOUNTER_2' ? round(1.85 + (mt_rand(-1, 1) / 10), 2) : 245.5)))),
                        'metric_unit' => ($proc === 'MOUNTER_1' ? 'kPa' : ($proc === 'MOUNTER_2' ? 'N' : ($proc === 'REFLOW' ? '℃' : ($proc === 'SPI' ? '%' : 'W')))),
                        'pdm_health' => $pdmHealth,
                        'pdm_status' => $isFail ? 'WARNING' : 'NORMAL',
                        'recommendation' => $isFail ? "PCB #{$pcbNum} 공정 검사 이상 ➔ 점검 권장" : "라인 컨베이어 파이프라인 정상 가동 중 (PCB #{$pcbNum})"
                    ];

                    $stmtHist = $pdo->prepare("
                        INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmtHist->execute([$barcode, $proc, $isFail ? 'FAIL' : 'PASS', json_encode($pdata, JSON_UNESCAPED_UNICODE)]);

                    // 마지막 공정(REFLOW 또는 FCT)을 통과한 기판은 최종 양품(또는 불량) 완료 상태로 전이
                    if ($pIdx === 4) {
                        $nextBmStatus = $isFail ? 'DEFECT' : ($mode === 'SMT' ? 'TEST_PASS' : 'SHIPPING');
                        $pdo->prepare("UPDATE barcode_master SET status = ? WHERE barcode = ?")->execute([$nextBmStatus, $barcode]);
                    } else {
                        // 공정 진행 중인 기판 상태
                        $pdo->prepare("UPDATE barcode_master SET status = 'IN_PROCESS' WHERE barcode = ? AND status = 'WAIT'")->execute([$barcode]);
                    }
                } else {
                    // 현재 슬롯에 기판이 없는 경우 (대기/IDLE 텔레메트리 기록)
                    $idleData = [
                        'pdm_health' => 99,
                        'pdm_status' => 'NORMAL',
                        'recommendation' => '설비 대기(IDLE) 정상'
                    ];
                    $stmtHist = $pdo->prepare("
                        INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at)
                        VALUES ('-', ?, 'IDLE', ?, NOW())
                    ");
                    $stmtHist->execute([$proc, json_encode($idleData, JSON_UNESCAPED_UNICODE)]);
                }
            }

        } catch (\Throwable $e) {
            // Ignore background tick exceptions
        }
    }

    /**
     * 5. 시스템 로그 조회
     */
    public static function getSystemLogs(): void {
        try {
            $pdo = Database::getConnection();
            $search     = trim(Request::query('search', ''));
            $actionType = trim(Request::query('action_type', ''));
            $limit      = Request::query('limit') ? (int)Request::query('limit') : 100;
            if ($limit <= 0 || $limit > 500) $limit = 100;

            $where = ["1=1"];
            $params = [];

            if ($actionType !== '') {
                $where[] = "action_type = :action_type";
                $params[':action_type'] = $actionType;
            }
            if ($search !== '') {
                $where[] = "(description LIKE :search OR username LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(" AND ", $where);

            $stmt = $pdo->prepare("
                SELECT * FROM system_log 
                WHERE {$whereSql} 
                ORDER BY id DESC 
                LIMIT {$limit}
            ");
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            $stmtTypes = $pdo->query("SELECT DISTINCT action_type FROM system_log ORDER BY action_type ASC");
            $actionTypes = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);

            Response::json([
                "status" => "success",
                "data"   => [
                    "logs"         => $logs,
                    "action_types" => $actionTypes
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 알림 목록 조회
     */
    public static function getNotifications(): void {
        try {
            $pdo = Database::getConnection();
            $today = date('Y-m-d');
            $d3 = date('Y-m-d', strtotime('+3 days'));

            $stmtUrgent = $pdo->prepare("
                SELECT wo_id, due_date, target_qty 
                FROM work_order 
                WHERE status NOT IN ('DONE') 
                  AND due_date IS NOT NULL 
                  AND due_date BETWEEN :today AND :d3
            ");
            $stmtUrgent->execute([':today' => $today, ':d3' => $d3]);
            $urgentOrders = $stmtUrgent->fetchAll();

            foreach ($urgentOrders as $uo) {
                $msg = "작업지시 [{$uo['wo_id']}]의 납기일({$uo['due_date']})이 3일 이내로 임박했습니다.";
                $stmtDup = $pdo->prepare("SELECT id FROM system_notification WHERE title LIKE :title AND DATE(created_at) = CURDATE()");
                $stmtDup->execute([':title' => "%{$uo['wo_id']}%"]);
                if (!$stmtDup->fetch()) {
                    $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('DANGER', ?, ?, 'wo')")
                        ->execute(["🚨 납기 임박 [{$uo['wo_id']}]", $msg]);
                }
            }

            $stmtList = $pdo->query("SELECT * FROM system_notification ORDER BY is_read ASC, id DESC LIMIT 30");
            $list = $stmtList->fetchAll();

            $stmtUnread = $pdo->query("SELECT COUNT(*) as unread FROM system_notification WHERE is_read = 0");
            $unreadCount = (int)($stmtUnread->fetch()['unread'] ?? 0);

            Response::json([
                "status" => "success",
                "data"   => [
                    "unread_count"  => $unreadCount,
                    "notifications" => $list
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 7. 알림 확인(읽음) 처리
     */
    public static function readNotification(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $id = $input['id'] ?? 'all';

            if ($id === 'all') {
                $pdo->query("UPDATE system_notification SET is_read = 1 WHERE is_read = 0");
            } else {
                $stmt = $pdo->prepare("UPDATE system_notification SET is_read = 1 WHERE id = ?");
                $stmt->execute([(int)$id]);
            }
            Response::json(["status" => "success", "message" => "알림을 확인 처리했습니다."]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 8. Server-Sent Events (SSE) 실시간 스트림
     */
    public static function dashboardSse(): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        echo "retry: 800\n\n";

        $pdo = Database::getConnection();
        $lastId = isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? intval($_SERVER["HTTP_LAST_EVENT_ID"]) : 0;

        if ($lastId === 0) {
            $stmt = $pdo->query("SELECT MAX(history_id) as max_id FROM barcode_history");
            $row = $stmt->fetch();
            $lastId = $row['max_id'] ?? 0;
        }

        $stmt = $pdo->prepare("
            SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, b.status, w.target_qty, w.status AS wo_status 
            FROM barcode_history h
            JOIN barcode_master b ON h.barcode = b.barcode
            JOIN work_order w ON b.wo_id = w.wo_id
            WHERE h.history_id > :last_id 
            ORDER BY h.history_id ASC
        ");
        $stmt->execute([':last_id' => $lastId]);
        $newRecords = $stmt->fetchAll();

        if (count($newRecords) > 0) {
            foreach ($newRecords as $record) {
                echo "id: " . $record['history_id'] . "\n";
                echo "data: " . json_encode($record, JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        } else {
            echo ": keepalive\n\n";
        }

        flush();
        exit();
    }
}
