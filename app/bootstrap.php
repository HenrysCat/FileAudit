<?php

declare(strict_types=1);

session_start();

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_PATH', APP_ROOT . '/config/config.php');

if (!file_exists(CONFIG_PATH)) {
    http_response_code(500);
    $message = 'FileAudit is not configured. Copy config/config.example.php to config/config.php and update the settings.';

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}

$config = require CONFIG_PATH;

if (!is_array($config)) {
    http_response_code(500);
    echo 'Invalid configuration file.';
    exit;
}

require_once APP_ROOT . '/app/functions.php';
require_once APP_ROOT . '/app/db.php';
require_once APP_ROOT . '/app/auth.php';
