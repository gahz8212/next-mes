<?php
// backend/api/dashboard_sse.php - Non-blocking EventSource Stream
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 0.8초 후 브라우저 자동 재연결 지시
echo "retry: 800\n\n";

$lastId = isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? intval($_SERVER["HTTP_LAST_EVENT_ID"]) : 0;

if ($lastId === 0) {
    // 최초 접속 시 현재 최고 history_id를 기준점으로 잡음
    $stmt = $pdo->query("SELECT MAX(history_id) as max_id FROM barcode_history");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastId = $row['max_id'] ?? 0;
}

// 새로운 센서/바코드 이력 조회
$stmt = $pdo->prepare("
    SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, b.status, w.target_qty, w.status AS wo_status 
    FROM barcode_history h
    JOIN barcode_master b ON h.barcode = b.barcode
    JOIN work_order w ON b.wo_id = w.wo_id
    WHERE h.history_id > :last_id 
    ORDER BY h.history_id ASC
");
$stmt->execute([':last_id' => $lastId]);
$newRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
?>
