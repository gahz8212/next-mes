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

    $barcode = $data['barcode'] ?? null;
    $processName = $data['process_name'] ?? null;
    $resultStatus = $data['result_status'] ?? 'PASS'; // 기본값 추가
    $processData = $data['process_data'] ?? null;

    if (empty($barcode) || empty($processName)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "필수 데이터가 누락되었습니다."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 바코드 유효성 검증
    $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
    $stmt->execute([':barcode' => $barcode]);
    $barcodeRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$barcodeRow) {
        // 동적 재생산을 위해 등록되지 않은 바코드가 들어오면 자동 등록 (마지막 '-' 이전의 문자열을 wo_id로 추출)
        $extractedWoId = substr($barcode, 0, strrpos($barcode, '-'));
        $stmt = $pdo->prepare("INSERT INTO barcode_master (barcode, wo_id, status) VALUES (:barcode, (SELECT wo_id FROM work_order WHERE wo_id = :wo_id LIMIT 1), 'WAIT')");
        $stmt->execute([':barcode' => $barcode, ':wo_id' => $extractedWoId]);
        
        // 다시 조회
        $stmt = $pdo->prepare("SELECT status, wo_id FROM barcode_master WHERE barcode = :barcode");
        $stmt->execute([':barcode' => $barcode]);
        $barcodeRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$barcodeRow || empty($barcodeRow['wo_id'])) {
            throw new Exception("유효하지 않은 바코드 형식입니다: " . $barcode);
        }
    }
    
    $currentStatus = $barcodeRow['status'];
    $woId = $barcodeRow["wo_id"];
    $nextStatus = "";

    // 2. 라우팅 검증 (포카요케) - 설비 단위(Micro Tracking)
    switch ($processName) {
        case 'LASER':
            if ($currentStatus !== 'WAIT' && $currentStatus !== 'IN_PROCESS') {
                throw new Exception("공정 순서 오류: 바코드가 대기(WAIT) 상태가 아닙니다. 현재: " . $currentStatus);
            }
            $nextStatus = 'IN_PROCESS';
            break;
        case 'SPI':
            if ($currentStatus !== 'IN_PROCESS') {
                throw new Exception("공정 순서 오류: 이전 공정(LASER)이 완료되지 않았습니다.");
            }
            $nextStatus = 'IN_PROCESS'; // 편의상 묶음
            break;
        case 'MOUNTER':
            if ($currentStatus !== 'IN_PROCESS') {
                throw new Exception("공정 순서 오류: 이전 공정(SPI)이 완료되지 않았습니다.");
            }
            $nextStatus = 'TOP_DONE';
            break;
        case 'REFLOW':
            if ($currentStatus !== 'TOP_DONE') {
                throw new Exception("공정 순서 오류: 이전 공정(MOUNTER)이 완료되지 않았습니다.");
            }
            $nextStatus = 'BOTTOM_DONE';
            break;
        case 'DIP_AOI':
            if ($currentStatus !== 'BOTTOM_DONE') {
                throw new Exception("공정 순서 오류: 이전 공정(REFLOW)이 완료되지 않았습니다.");
            }
            $nextStatus = 'TEST_PASS';
            break;
        case 'WAVE':
            if ($currentStatus !== 'TEST_PASS') {
                throw new Exception("공정 순서 오류: 이전 공정(DIP_AOI)이 완료되지 않았습니다.");
            }
            $nextStatus = 'SHIPPING';
            break;
        default:
            throw new Exception("알 수 없는 공정명입니다: " . $processName);
    }

    if ($resultStatus === 'FAIL') {
        $nextStatus = 'FAIL';
    }

    // 3. 상태 업데이트
    $updateStmt = $pdo->prepare("UPDATE barcode_master SET status = :status WHERE barcode = :barcode");
    $updateStmt->execute([':status' => $nextStatus, ':barcode' => $barcode]);

    // 4. 이력 기록
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

    // 5. 작업지시(WO) 완료 체크
    $woStmt = $pdo->prepare("SELECT wo_id FROM barcode_master WHERE barcode = :barcode");
    $woStmt->execute([':barcode' => $barcode]);
    $woId = $woStmt->fetchColumn();

    if ($woId) {
        $chkStmt = $pdo->prepare("SELECT target_qty FROM work_order WHERE wo_id = :wo_id");
        $chkStmt->execute([':wo_id' => $woId]);
        $targetQty = $chkStmt->fetchColumn();

        if ($targetQty) {
            if ($nextStatus === 'BOTTOM_DONE' || $nextStatus === 'FAIL') {
                // 자삽(SMT) 완료 체크
                $smtStmt = $pdo->prepare("SELECT COUNT(*) FROM barcode_master WHERE wo_id = :wo_id AND status IN ('BOTTOM_DONE', 'TEST_PASS', 'SHIPPING', 'FAIL')");
                $smtStmt->execute([':wo_id' => $woId]);
                if ($smtStmt->fetchColumn() >= $targetQty) {
                    $pdo->prepare("UPDATE work_order SET status = 'SMT_DONE' WHERE wo_id = :wo_id AND status = 'IN_PROGRESS'")
                        ->execute([':wo_id' => $woId]);
                }
            }
            if ($nextStatus === 'SHIPPING' || $nextStatus === 'FAIL') {
                // 수삽(DIP) 완료 체크
                $dipStmt = $pdo->prepare("SELECT COUNT(*) FROM barcode_master WHERE wo_id = :wo_id AND status IN ('SHIPPING', 'FAIL')");
                $dipStmt->execute([':wo_id' => $woId]);
                if ($dipStmt->fetchColumn() >= $targetQty) {
                    $pdo->prepare("UPDATE work_order SET status = 'DONE', completed_at = NOW() WHERE wo_id = :wo_id")
                        ->execute([':wo_id' => $woId]);
                }
            }
        }
    }

    // 커밋
    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "공정 이력이 성공적으로 기록되었습니다.",
        "data" => [
            "barcode" => $barcode,
            "process_name" => $processName,
            "result_status" => $resultStatus,
            "new_status" => $nextStatus
        ]
    ], JSON_UNESCAPED_UNICODE);

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
