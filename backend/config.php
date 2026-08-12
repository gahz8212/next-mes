<?php
// Docker 컨테이너의 3306 포트가 호스트의 3307 포트로 매핑되어 있습니다.
$dsn = "mysql:host=127.0.0.1;port=3307;dbname=smt_mes_db;charset=utf8mb4";
try {
$pdo = new PDO($dsn, 'root', 'your_password_here', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

catch (\PDOException $e) {
// echo json_encode(["status" => "error", "message" => "DB Connection Failed"]);
echo json_encode(["status" => "error", "message" => "DB Connection Failed: " . $e->getMessage()]);
exit;
}
?>
