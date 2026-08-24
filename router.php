<?php
// router.php
// Local development server router for PHP Built-in Server:
// Run command: php -S 0.0.0.0:8000 router.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1. If physical file exists (HTML, CSS, JS, Images, etc.), serve directly
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // let PHP built-in server serve the static file directly
}

// 2. Default document (login.html if root requested)
if ($uri === '/') {
    if (file_exists(__DIR__ . '/login.html')) {
        require __DIR__ . '/login.html';
        exit();
    } else if (file_exists(__DIR__ . '/index.html')) {
        require __DIR__ . '/index.html';
        exit();
    }
}

// 3. If request is an API call (/backend/api/*, /api/*, /backend/*) or any unmatched route -> backend/index.php
require __DIR__ . '/backend/index.php';
