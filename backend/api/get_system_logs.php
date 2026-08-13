<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $search     = trim($_GET['search'] ?? '');
    $actionType = trim($_GET['action_type'] ?? '');
    $limit      = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
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
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 로그 카테고리 목록
    $stmtTypes = $pdo->query("SELECT DISTINCT action_type FROM system_log ORDER BY action_type ASC");
    $actionTypes = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "logs"         => $logs,
            "action_types" => $actionTypes
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
