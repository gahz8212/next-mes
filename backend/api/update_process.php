<?php
// backend/api/update_process.php
require_once __DIR__ . '/../config/db.php';

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "허용되지 않는 메서드입니다."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // 1. Raw JSON 데이터 수신
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (!$data) {
        throw new Exception("잘못된 JSON 형식입니다.");
    }

    $barcode = $data['barcode'] ?? null;
    $processName = $data['process_name'] ?? null;
    $resultStatus = $data['result_status'] ?? null;

    // 2. 포카요케 (필수 데이터 방어)
    if (empty($barcode) || empty($processName) || empty($resultStatus)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "필수 데이터(barcode, process_name, result_status)가 누락되었습니다."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 3. Prepared Statement를 통한 안전한 DB 삽입 (SQL Injection 방어)
    $stmt = $pdo->prepare("
        INSERT INTO barcode_history (barcode, process_name, result_status, created_at) 
        VALUES (:barcode, :process_name, :result_status, NOW())
    ");
    
    $stmt->execute([
        ':barcode' => $barcode,
        ':process_name' => $processName,
        ':result_status' => $resultStatus
    ]);

    // 4. 성공 응답 반환
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "공정 이력이 성공적으로 기록되었습니다.",
        "data" => [
            "barcode" => $barcode,
            "process_name" => $processName,
            "result_status" => $resultStatus
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // 5. 전역 예외 처리
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "서버 내부 오류: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}