<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    // 1. 자동 트리거 체크: D-3 이내 미완료 작업지시 알림 자동 생성
    $today = date('Y-m-d');
    $d3 = date('Y-m-d', strtotime('+3 days'));

    $stmtUrgent = $pdo->prepare("
        SELECT wo_id, due_date, target_qty 
        FROM work_order 
        WHERE status NOT IN ('DONE') 
          AND due_date IS NOT NULL 
          AND due_date BETWEEN :today AND :d3
    ");
    $stmtUrgent->execute([':today' => $today, ':d3' => $d3]);
    $urgentOrders = $stmtUrgent->fetchAll(PDO::FETCH_ASSOC);

    foreach ($urgentOrders as $uo) {
        $msg = "작업지시 [{$uo['wo_id']}]의 납기일({$uo['due_date']})이 3일 이내로 임박했습니다.";
        // 중복 방지 (오늘 이미 생성된 동일 제목 알림 확인)
        $stmtDup = $pdo->prepare("SELECT id FROM system_notification WHERE title LIKE :title AND DATE(created_at) = CURDATE()");
        $stmtDup->execute([':title' => "%{$uo['wo_id']}%"]);
        if (!$stmtDup->fetch()) {
            $pdo->prepare("INSERT INTO system_notification (type, title, message, link_url) VALUES ('DANGER', ?, ?, 'wo')")
                ->execute(["🚨 납기 임박 [{$uo['wo_id']}]", $msg]);
        }
    }

    // 2. 알림 목록 조회 (최신 30개)
    $stmtList = $pdo->query("SELECT * FROM system_notification ORDER BY is_read ASC, id DESC LIMIT 30");
    $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // 3. 미확인 알림 수
    $stmtUnread = $pdo->query("SELECT COUNT(*) as unread FROM system_notification WHERE is_read = 0");
    $unreadCount = (int)($stmtUnread->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0);

    echo json_encode([
        "status" => "success",
        "data"   => [
            "unread_count"  => $unreadCount,
            "notifications" => $list
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
