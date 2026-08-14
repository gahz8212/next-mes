<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = $input['id'] ?? 'all'; // 'all' 이면 전체 읽음 처리

try {
    if ($id === 'all') {
        $pdo->query("UPDATE system_notification SET is_read = 1 WHERE is_read = 0");
    } else {
        $stmt = $pdo->prepare("UPDATE system_notification SET is_read = 1 WHERE id = ?");
        $stmt->execute([(int)$id]);
    }
    echo json_encode(["status" => "success", "message" => "알림을 확인 처리했습니다."], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
