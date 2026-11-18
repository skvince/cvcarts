<?php

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $environment = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($environment === false) {
        throw new RuntimeException('Unable to parse the application environment file.');
    }

    foreach ($environment as $name => $value) {
        $existing = getenv($name);
        if ($existing === false || $existing === '') {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!defined('CVCARTS_BASE_URL')) {
    define('CVCARTS_BASE_URL', rtrim((string)(getenv('APP_BASE_URL') ?: 'http://localhost/Cvcarts'), '/'));
}

if (!defined('CVCARTS_ADMIN_EMAIL')) {
    define('CVCARTS_ADMIN_EMAIL', strtolower(trim((string)(getenv('ADMIN_EMAIL') ?: ''))));
}

if (!defined('CVCARTS_BASE_PATH')) {
    $basePath = parse_url(CVCARTS_BASE_URL, PHP_URL_PATH);
    define('CVCARTS_BASE_PATH', is_string($basePath) ? rtrim($basePath, '/') : '');
}
