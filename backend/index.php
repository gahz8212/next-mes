<?php
// backend/index.php
// Unified Entry Point & Front Controller for Backend API

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/routes/api.php';

// Dispatch request
Router::dispatch();
