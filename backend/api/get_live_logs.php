<?php
// backend/api/get_live_logs.php - 초고속 실시간 센서 로그 폴링 API
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

try {
    if ($last_id > 0) {
        // last_id 이후에 발생한 신규 센서 로그 실시간 조회
        $stmt = $pdo->prepare("
            SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                   COALESCE(b.status, 'ING') AS barcode_status, b.wo_id, w.target_qty, w.status AS wo_status
            FROM barcode_history h
            LEFT JOIN barcode_master b ON h.barcode = b.barcode
            LEFT JOIN work_order w ON b.wo_id = w.wo_id
            WHERE h.history_id > ?
            ORDER BY h.history_id ASC
            LIMIT 100
        ");
        $stmt->execute([$last_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $max_id = !empty($logs) ? (int)end($logs)['history_id'] : $last_id;
    } else {
        // last_id == 0 일 때 (최초 접속 시): 현재 DB의 기준점 MAX ID 조회
        $stmtMax = $pdo->query("SELECT COALESCE(MAX(history_id), 0) FROM barcode_history");
        $currentMax = (int)$stmtMax->fetchColumn();

        // 만약 현재 진행 중인 작업지시가 있다면 최근 5개 이력을 즉시 반환
        $chkActive = $pdo->query("SELECT wo_id FROM work_order WHERE status IN ('IN_PROGRESS', 'DIP_IN_PROGRESS') LIMIT 1");
        $activeWo = $chkActive->fetchColumn();

        if ($activeWo) {
            $stmt = $pdo->prepare("
                SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                       COALESCE(b.status, 'ING') AS barcode_status, b.wo_id, w.target_qty, w.status AS wo_status
                FROM barcode_history h
                LEFT JOIN barcode_master b ON h.barcode = b.barcode
                LEFT JOIN work_order w ON b.wo_id = w.wo_id
                WHERE b.wo_id = ?
                ORDER BY h.history_id DESC
                LIMIT 5
            ");
            $stmt->execute([$activeWo]);
            $logs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $logs = [];
        }

        $max_id = $currentMax;
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "logs"   => $logs,
            "max_id" => $max_id
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
