<?php
// backend/core/Request.php

class Request {
    private static ?array $jsonData = null;

    /**
     * Get parsed JSON input or $_POST data
     */
    public static function getBody(): array {
        if (self::$jsonData === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                self::$jsonData = $decoded;
            } else {
                self::$jsonData = $_POST;
            }
        }
        return self::$jsonData;
    }

    /**
     * Get single value from Body or default
     */
    public static function input(string $key, $default = null) {
        $body = self::getBody();
        return $body[$key] ?? $default;
    }

    /**
     * Get query string parameter from $_GET or REQUEST_URI
     */
    public static function query(string $key, $default = null) {
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        $queryStr = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($queryStr) {
            parse_str($queryStr, $parsed);
            if (isset($parsed[$key])) {
                return $parsed[$key];
            }
        }
        return $default;
    }

    /**
     * Get HTTP Method
     */
    public static function getMethod(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Get client IP
     */
    public static function getClientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
