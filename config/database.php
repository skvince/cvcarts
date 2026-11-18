<?php

require_once __DIR__ . '/app.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'cscqc_ecommerce';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';

$dsn = 'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    $pdo = null;
    error_log('CVCarts database connection failed: ' . $e->getMessage());
}

function require_database_connection(): void {
    global $pdo;
    if ($pdo === null) {
        http_response_code(503);
        echo '<!DOCTYPE html><html><body><h1>Database unavailable</h1><p>Please run php database\\setup.php to initialize the application database.</p></body></html>';
        exit;
    }
}
