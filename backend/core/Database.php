<?php
// backend/core/Database.php

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            self::init();
        }
        return self::$pdo;
    }

    private static function init(): void {
        // .env 우선, 없으면 .env.local fallback
        $baseDir = dirname(__DIR__, 2);
        $envFile = file_exists($baseDir . '/.env')
            ? $baseDir . '/.env'
            : $baseDir . '/.env.local';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                [$name, $value] = explode('=', $line, 2) + [null, null];
                if ($name !== null && $value !== null) {
                    putenv(trim($name) . '=' . trim($value));
                    $_ENV[trim($name)] = trim($value);
                }
            }
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_NAME') ?: 'smt_mes_db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "DB Connection Failed: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if (!defined('NODERED_HOST')) define('NODERED_HOST', getenv('NODERED_HOST') ?: '127.0.0.1');
        if (!defined('NODERED_PORT')) define('NODERED_PORT', intval(getenv('NODERED_PORT') ?: 1881));
    }
}
