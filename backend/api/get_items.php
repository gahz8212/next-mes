<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $sql = "
        SELECT 
            i.*,
            COALESCE(c.name, '-') as company_name,
            c.code as company_code,
            bm.bom_id,
            COALESCE(bm.version, 'v1.0') as bom_version,
            COALESCE(bd_cnt.part_count, 0) as bom_part_count
        FROM item i
        LEFT JOIN company c ON i.company_id = c.id
        LEFT JOIN (
            SELECT bom_id, item_id, version
            FROM bom_master bm1
            WHERE bom_id = (
                SELECT MAX(bom_id) FROM bom_master bm2 WHERE bm2.item_id = bm1.item_id
            )
        ) bm ON i.id = bm.item_id
        LEFT JOIN (
            SELECT bom_id, COUNT(*) as part_count
            FROM bom_detail
            GROUP BY bom_id
        ) bd_cnt ON bm.bom_id = bd_cnt.bom_id
        ORDER BY i.item_code ASC
    ";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        "status" => "success",
        "data" => $items
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
