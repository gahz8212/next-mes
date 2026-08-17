<?php
// backend/api/reset_maintenance.php - 설비 예방정비(TPM) 완료 이력 기록 및 수치 초기화 API
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "허용되지 않는 메서드입니다."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (!$data) {
        throw new Exception("잘못된 JSON 데이터입니다.");
    }

    $processName = trim($data['process_name'] ?? '');
    $itemName    = trim($data['item_name'] ?? '정기 소모품 교체 및 클리닝');
    $operator    = trim($data['operator'] ?? 'Worker-OP');
    $actionName  = trim($data['action_name'] ?? 'TPM_MAINTENANCE_RESET');

    if (empty($processName)) {
        throw new Exception("설비 공정 코드(process_name)가 필요합니다.");
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $desc = "[설비 TPM 예방보전] {$processName} - {$itemName} 정비 작업 완료 및 수명/건전도 100% 회복 (담당: {$operator})";

    $pdo->beginTransaction();

    // 1. system_log에 정비 감사 로그 기록
    $logStmt = $pdo->prepare("
        INSERT INTO system_log (username, action_type, description, ip_address, created_at)
        VALUES (?, 'MAINTENANCE_RESET', ?, ?, NOW())
    ");
    $logStmt->execute([$operator, $desc, $clientIp]);

    // 2. system_notification에 알림 등록
    $notifStmt = $pdo->prepare("
        INSERT INTO system_notification (type, title, message, is_read, link_url, created_at)
        VALUES ('SUCCESS', ?, ?, 0, ?, NOW())
    ");
    $notifTitle = "[설비 정비 완료] {$processName}";
    $notifMsg   = "{$itemName} 작업이 정상 완료되어 설비 종합 건전도가 100%로 갱신되었습니다.";
    $linkUrl    = "machine.html?eq={$processName}";
    $notifStmt->execute([$notifTitle, $notifMsg, $linkUrl]);

    $pdo->commit();

    echo json_encode([
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
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "정비 이력 저장 실패: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
