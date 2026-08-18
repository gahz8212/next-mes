<?php
// backend/core/Router.php

class Router {
    private static array $routes = [];

    public static function get(string $path, $handler): void {
        self::addRoute('GET', $path, $handler);
    }

    public static function post(string $path, $handler): void {
        self::addRoute('POST', $path, $handler);
    }

    public static function put(string $path, $handler): void {
        self::addRoute('PUT', $path, $handler);
    }

    public static function delete(string $path, $handler): void {
        self::addRoute('DELETE', $path, $handler);
    }

    public static function any(string $path, $handler): void {
        self::addRoute('ANY', $path, $handler);
    }

    private static function addRoute(string $method, string $path, $handler): void {
        self::$routes[] = [
            'method'  => $method,
            'path'    => '/' . trim($path, '/'),
            'handler' => $handler
        ];
    }

    public static function dispatch(?string $uri = null, ?string $method = null): void {
        if ($uri === null) {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        }
        if ($method === null) {
            $method = Request::getMethod();
        }

        // Normalize uri (remove trailing slash except root)
        $uri = '/' . trim($uri, '/');

        // Robust prefix stripping: extract path after /backend/api, /backend, /api or relative script
        $cleanUri = $uri;
        $prefixes = ['/backend/api', '/backend', '/api'];
        foreach ($prefixes as $prefix) {
            $pos = strpos($cleanUri, $prefix);
            if ($pos !== false) {
                $cleanUri = substr($cleanUri, $pos + strlen($prefix));
                $cleanUri = '/' . trim($cleanUri, '/');
                break;
            }
        }

        foreach (self::$routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }

            // Check exact or pattern match against both $uri and $cleanUri
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $regex = '#^' . $pattern . '$#';

            if (preg_match($regex, $cleanUri, $matches) || preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                self::invokeHandler($route['handler'], $params);
                return;
            }
        }

        Response::error("Endpoint not found: {$method} {$uri}", 404);
    }

    private static function invokeHandler($handler, array $params = []): void {
        if (is_callable($handler)) {
            call_user_func_array($handler, array_values($params));
            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (class_exists($class)) {
                $instance = new $class();
                call_user_func_array([$instance, $method], array_values($params));
                return;
            }
        }

        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$class, $method] = explode('@', $handler, 2);
            if (class_exists($class)) {
                $instance = new $class();
                call_user_func_array([$instance, $method], array_values($params));
                return;
            }
        }

        Response::error("Handler execution failed", 500);
    }
}
