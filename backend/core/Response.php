<?php
// backend/core/Response.php

class Response {
    public static function json(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit();
    }

    public static function success($data = null, string $message = ''): void {
        $res = ["status" => "success"];
        if ($message !== '') $res["message"] = $message;
        if ($data !== null) {
            if (is_array($data) && isset($data['status'])) {
                $res = $data;
            } else {
                $res["data"] = $data;
            }
        }
        self::json($res, 200);
    }

    public static function error(string $message, int $statusCode = 200, array $extra = []): void {
        $res = array_merge(["status" => "error", "message" => $message], $extra);
        self::json($res, $statusCode);
    }
}
