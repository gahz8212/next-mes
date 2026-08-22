<?php
// .env 파일에서 환경변수 로드
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if (!getenv($key)) putenv("$key=$val");
    }
}

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'smt_mes_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASSWORD') ?: '';

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch (\PDOException $e) {
    echo json_encode(["status" => "error", "message" => "DB Connection Failed: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Node-RED 설정
if (!defined('NODERED_HOST')) define('NODERED_HOST', getenv('NODERED_HOST') ?: '127.0.0.1');
if (!defined('NODERED_PORT')) define('NODERED_PORT', intval(getenv('NODERED_PORT') ?: 1881));
?>

