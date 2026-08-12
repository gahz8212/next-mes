<?php
// backend/config/db.php

// CORS 및 JSON 응답 헤더 설정
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// OPTIONS 요청(Preflight) 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = '127.0.0.1';
$db   = 'smt_mes_db'; // 본인의 DB 이름
$user = 'root';   // 본인의 DB 계정
$pass = 'your_password_here'; // 본인의 DB 비밀번호
$port = '3307';   // 도커 포트 또는 로컬 포트 (예: 3307)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // 에러 시 예외(Exception) 발생
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 연관 배열로 결과 반환
    PDO::ATTR_EMULATE_PREPARES   => false,                  // 진짜 Prepared Statement 사용
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // DB 연결 실패 시 깔끔한 JSON 에러 반환 후 중단
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "데이터베이스 연결 실패: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}