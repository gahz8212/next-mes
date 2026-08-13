<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

try {
    $status    = $_GET['status'] ?? '';
    $companyId = $_GET['company_id'] ?? '';
    $search    = trim($_GET['search'] ?? '');

    $where = ["1=1"];
    $params = [];

    if ($status !== '') {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    if ($companyId !== '') {
        $where[] = "o.company_id = :company_id";
        $params[':company_id'] = (int)$companyId;
    }
    if ($search !== '') {
        $where[] = "(o.order_no LIKE :search OR o.item_name LIKE :search OR o.item_code LIKE :search OR c.name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $whereSql = implode(" AND ", $where);

    // 1. 수주 목록 조회
    $sql = "
        SELECT 
            o.*, 
            c.name as company_name,
            w.status as wo_status,
            w.target_qty as wo_target_qty,
            (SELECT SUM(b.status != 'WAIT') FROM barcode_master b WHERE b.wo_id = o.wo_id) as wo_processed_qty,
            (SELECT SUM(b.status IN ('SHIPPING')) FROM barcode_master b WHERE b.wo_id = o.wo_id) as wo_good_qty
        FROM sales_order o
        LEFT JOIN company c ON o.company_id = c.id
        LEFT JOIN work_order w ON o.wo_id = w.wo_id
        WHERE {$whereSql}
        ORDER BY o.due_date ASC, o.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. 수주 KPI 집계
    $stmtKpi = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(order_qty) as total_qty,
            SUM(total_price) as total_amount,
            SUM(CASE WHEN status = 'RECEIVED' THEN 1 ELSE 0 END) as received_count,
            SUM(CASE WHEN status = 'IN_PRODUCTION' THEN 1 ELSE 0 END) as in_prod_count,
            SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_count
        FROM sales_order
    ");
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "kpi"    => [
                "total_orders"    => (int)($kpi['total_orders'] ?? 0),
                "total_qty"       => (int)($kpi['total_qty'] ?? 0),
                "total_amount"    => (float)($kpi['total_amount'] ?? 0),
                "received_count"  => (int)($kpi['received_count'] ?? 0),
                "in_prod_count"   => (int)($kpi['in_prod_count'] ?? 0),
                "completed_count" => (int)($kpi['completed_count'] ?? 0),
            ],
            "orders" => $orders
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
