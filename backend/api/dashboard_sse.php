<?php
// backend/api/dashboard_sse.php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 출력 버퍼링 비활성화 및 즉시 전송을 위한 패딩
while (ob_get_level() > 0) {
    ob_end_flush();
}

// 세션이 닫혀서 스크립트가 죽지 않도록 방지
ignore_user_abort(true);
set_time_limit(0);

// 초기 연결 확인용 Ping 전송
echo ": ping\n\n";
flush();

// 가장 마지막으로 확인한 히스토리 ID
$lastId = isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? intval($_SERVER["HTTP_LAST_EVENT_ID"]) : 0;

if ($lastId === 0) {
    // 최초 접속 시 현재 최고 history_id를 구함
    $stmt = $pdo->query("SELECT MAX(history_id) as max_id FROM barcode_history");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastId = $row['max_id'] ?? 0;
}

while (true) {
    // 연결이 끊겼으면 스크립트 종료
    if (connection_aborted()) {
        break;
    }

    // 새로운 이력이 있는지 확인
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
            $lastId = $record['history_id'];
        }
        if (ob_get_level() > 0) ob_flush();
        flush();
    } else {
        // 클라이언트 연결 끊김 감지를 위해 아무 일도 없더라도 더미(ping) 데이터를 보냄
        echo ": keepalive\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    // 1초 대기 후 다시 폴링
    sleep(1);
}
