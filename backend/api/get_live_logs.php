<?php
// backend/api/get_live_logs.php - 초고속 실시간 센서 로그 폴링 API
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

try {
    // 1. 현재 활성/최신 작업지시 상태 조회
    $stmtWo = $pdo->query("
        SELECT wo_id, status, target_qty 
        FROM work_order 
        WHERE status IN ('IN_PROGRESS', 'DIP_IN_PROGRESS', 'SMT_DONE', 'READY', 'DONE') 
        ORDER BY FIELD(status, 'IN_PROGRESS', 'DIP_IN_PROGRESS', 'SMT_DONE', 'READY', 'DONE'), wo_id DESC 
        LIMIT 1
    ");
    $activeWo = $stmtWo->fetch(PDO::FETCH_ASSOC);

    if ($last_id > 0) {
        // last_id 이후에 발생한 신규 센서 로그 실시간 조회
        $stmt = $pdo->prepare("
            SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                   COALESCE(b.status, 'ING') AS barcode_status, 
                   COALESCE(b.wo_id, :active_wo_id) AS wo_id,
                   :target_qty AS target_qty, 
                   :wo_status AS wo_status
            FROM barcode_history h
            LEFT JOIN barcode_master b ON h.barcode = b.barcode
            WHERE h.history_id > :last_id
            ORDER BY h.history_id ASC
            LIMIT 100
        ");
        $stmt->execute([
            ':last_id' => $last_id,
            ':active_wo_id' => $activeWo['wo_id'] ?? null,
            ':target_qty' => $activeWo['target_qty'] ?? 0,
            ':wo_status' => $activeWo['status'] ?? 'READY'
        ]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $max_id = !empty($logs) ? (int)end($logs)['history_id'] : $last_id;
    } else {
        // last_id == 0 일 때 (최초 접속 시): 현재 DB의 기준점 MAX ID 조회
        $stmtMax = $pdo->query("SELECT COALESCE(MAX(history_id), 0) FROM barcode_history");
        $currentMax = (int)$stmtMax->fetchColumn();

        if ($activeWo && ($activeWo['status'] === 'IN_PROGRESS' || $activeWo['status'] === 'DIP_IN_PROGRESS')) {
            $stmt = $pdo->prepare("
                SELECT h.history_id, h.barcode, h.process_name, h.result_status, h.process_data, h.created_at, 
                       COALESCE(b.status, 'ING') AS barcode_status, b.wo_id, 
                       :target_qty AS target_qty, :wo_status AS wo_status
                FROM barcode_history h
                LEFT JOIN barcode_master b ON h.barcode = b.barcode
                WHERE b.wo_id = :wo_id
                ORDER BY h.history_id DESC
                LIMIT 5
            ");
            $stmt->execute([
                ':wo_id' => $activeWo['wo_id'],
                ':target_qty' => $activeWo['target_qty'] ?? 0,
                ':wo_status' => $activeWo['status']
            ]);
            $logs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $logs = [];
        }

        $max_id = $currentMax;
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "logs"      => $logs,
            "max_id"    => $max_id,
            "active_wo" => $activeWo
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
