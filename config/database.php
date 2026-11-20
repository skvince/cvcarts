<?php

require_once __DIR__ . '/app.php';

$pdo = null;
$host = trim(cvcarts_env('DB_HOST'));
$dbName = trim(cvcarts_env('DB_NAME'));
$dbUser = trim(cvcarts_env('DB_USER'));
$dbPass = cvcarts_env('DB_PASSWORD');

if (CVCARTS_APP_ENV === 'local' || CVCARTS_APP_ENV === 'development') {
    $host = $host !== '' ? $host : '127.0.0.1';
    $dbName = $dbName !== '' ? $dbName : 'cscqc_ecommerce';
    $dbUser = $dbUser !== '' ? $dbUser : 'root';
}

$isDevelopment = CVCARTS_APP_ENV === 'local' || CVCARTS_APP_ENV === 'development';
if (!$isDevelopment && ($host === '' || $dbName === '' || $dbUser === '' || $dbPass === '')) {
    error_log('CVCarts database connection was not attempted because production database configuration is incomplete.');
} else {
    $dsn = 'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('CVCarts database connection failed: ' . $e->getMessage());
    }
}

function require_database_connection(): void {
    global $pdo;
    if ($pdo === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $iconUrl = htmlspecialchars(rtrim(CVCARTS_BASE_PATH, '/') . '/assets/images/logo.png', ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<link rel="icon" type="image/png" href="' . $iconUrl . '">'
            . '<link rel="shortcut icon" type="image/png" href="' . $iconUrl . '">'
            . '<link rel="apple-touch-icon" href="' . $iconUrl . '">'
            . '<title>Service unavailable</title></head><body><h1>Service temporarily unavailable</h1><p>Please try again later or contact the site administrator.</p></body></html>';
        exit;
    }
}
