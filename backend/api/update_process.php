<?php
// backend/api/update_process.php
require_once __DIR__ . '/../config/db.php';

// 로깅 설정
$logFile = __DIR__ . '/../../logs/mes_' . date('Ymd') . '.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}
ini_set('error_log', $logFile);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "허용되지 않는 메서드입니다."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (!$data) {
        throw new Exception("잘못된 JSON 형식입니다.");
    }

    // 단일 이벤트 또는 일괄(Batch) 이벤트 정규화
    $events = [];
    if (isset($data['events']) && is_array($data['events'])) {
        $events = $data['events'];
    } else if (isset($data['process_name'])) {
        $events = [$data];
    } else {
        throw new Exception("이벤트 데이터가 비어있습니다.");
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

        // 1. 대기(IDLE/WAIT) 이벤트 처리
        if ($resultStatus === 'IDLE' || $resultStatus === 'WAIT' || empty($barcode) || $barcode === '-') {
            $historyStmt = $pdo->prepare("
                INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) 
                VALUES (:barcode, :process_name, :result_status, :process_data, NOW())
            ");
            $historyStmt->execute([
                ':barcode' => '-',
                ':process_name' => $processName,
                ':result_status' => 'IDLE',
                ':process_data' => $processData ? json_encode($processData) : null
            ]);
            $processedCount++;
            continue;
        }

        // 2. 바코드 마스터 검증 및 등록
        $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
        $stmt->execute([':barcode' => $barcode]);
        $barcodeRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$barcodeRow) {
            $extractedWoId = $woId ?: substr($barcode, 0, strrpos($barcode, '-'));
            $stmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (:barcode, (SELECT wo_id FROM work_order WHERE wo_id = :wo_id LIMIT 1), 'WAIT')");
            $stmt->execute([':barcode' => $barcode, ':wo_id' => $extractedWoId]);

            $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
            $stmt->execute([':barcode' => $barcode]);
            $barcodeRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$barcodeRow || empty($barcodeRow['wo_id'])) {
                continue;
            }
        }

        $currentStatus = $barcodeRow['status'];
        $bWoId = $barcodeRow["wo_id"];
        if (!$woId) $woId = $bWoId;
        $nextStatus = "";

        // 3. 라우팅 포카요케
        switch ($processName) {
            case 'LASER':
                $nextStatus = 'IN_PROCESS';
                break;
            case 'SPI':
                $nextStatus = 'IN_PROCESS';
                break;
            case 'MOUNTER':
                $nextStatus = 'TOP_DONE';
                break;
            case 'REFLOW':
                $nextStatus = 'BOTTOM_DONE';
                break;
            case 'DIP_AOI':
                $nextStatus = 'TEST_PASS';
                break;
            case 'WAVE':
                $nextStatus = 'SHIPPING';
                break;
            default:
                continue 2;
        }

        if ($resultStatus === 'FAIL') {
            $nextStatus = 'FAIL';
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
            ':process_data' => $processData ? json_encode($processData) : null
        ]);

        $processedCount++;
    }

    // 6. 작업지시(WO) 완료 판정
    // 마지막 바코드가 최종 설비에 진입하자마자가 아니라, 최종 설비 가공 완료 후 라인에서 완전히 배출(is_complete)되었을 때 완료 전환
    $isComplete = !empty($data['is_complete']);
    if ($woId && $isComplete) {
        $chkStmt = $pdo->prepare("SELECT target_qty, status FROM work_order WHERE wo_id = :wo_id");
        $chkStmt->execute([':wo_id' => $woId]);
        $woInfo = $chkStmt->fetch(PDO::FETCH_ASSOC);

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
    echo json_encode(["status" => "success", "processed_events" => $processedCount], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // 파일 로그 기록
    error_log("[ERROR] update_process: " . $e->getMessage());

    http_response_code(409); // Conflict
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
