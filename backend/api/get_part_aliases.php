<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    $search = trim($_GET['search'] ?? '');
    $params = [];
    $where = "";

    if (!empty($search)) {
        $where = "WHERE standard_name LIKE :s OR standard_code LIKE :s OR alias_part_no LIKE :s OR vendor_name LIKE :s";
        $params[':s'] = '%' . $search . '%';
    }

    // 1. All records
    $sql = "SELECT * FROM part_alias $where ORDER BY standard_name ASC, created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Summary stats
    $summarySql = "SELECT 
        COUNT(DISTINCT standard_name) as total_standards,
        COUNT(*) as total_aliases,
        COUNT(DISTINCT vendor_name) as total_vendors
    FROM part_alias";
    $summaryStmt = $pdo->query($summarySql);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // 3. Grouped by standard_name for grouped card view
    $grouped = [];
    foreach ($records as $r) {
        $key = $r['standard_name'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'standard_name' => $r['standard_name'],
                'standard_code' => $r['standard_code'],
                'description'   => $r['description'],
                'aliases'       => []
            ];
        }
        $grouped[$key]['aliases'][] = [
            'id'            => (int)$r['id'],
            'alias_part_no' => $r['alias_part_no'],
            'vendor_name'   => $r['vendor_name'],
            'description'   => $r['description'],
            'created_at'    => $r['created_at']
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "summary" => [
                "total_standards" => (int)($summary['total_standards'] ?? 0),
                "total_aliases"   => (int)($summary['total_aliases'] ?? 0),
                "total_vendors"   => (int)($summary['total_vendors'] ?? 0)
            ],
            "records" => $records,
            "grouped" => array_values($grouped)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
