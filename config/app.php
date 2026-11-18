<?php

$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
$isProduction = $appEnv !== 'development' && $appEnv !== 'local';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
error_reporting($isProduction ? (E_ALL & ~E_DEPRECATED & ~E_STRICT) : E_ALL);

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
    $configuredBaseUrl = trim((string)(getenv('APP_BASE_URL') ?: ''));
    $configuredBaseUrl = rtrim($configuredBaseUrl, '/');
    if ($configuredBaseUrl === '' || preg_match('/^https?:\/\/(?:localhost|127\.0\.0\.1)(?:[:\/]|$)/i', $configuredBaseUrl) === 1) {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/[^A-Za-z0-9.\-\[\]:]/', '', $host) ?: 'localhost';
        $documentRoot = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
        $projectDirectory = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
        $basePath = '';
        if ($documentRoot !== '' && stripos($projectDirectory, $documentRoot) === 0) {
            $basePath = substr($projectDirectory, strlen($documentRoot));
            $basePath = $basePath === false ? '' : '/' . ltrim(str_replace('\\', '/', $basePath), '/');
            $basePath = rtrim($basePath, '/');
        }
        $configuredBaseUrl = ($isHttps ? 'https://' : 'http://') . $host . $basePath;
    }
    define('CVCARTS_BASE_URL', rtrim($configuredBaseUrl, '/'));
}

if (!defined('CVCARTS_ADMIN_EMAIL')) {
    define('CVCARTS_ADMIN_EMAIL', strtolower(trim((string)(getenv('ADMIN_EMAIL') ?: ''))));
}

if (!defined('CVCARTS_BASE_PATH')) {
    $basePath = parse_url(CVCARTS_BASE_URL, PHP_URL_PATH);
    define('CVCARTS_BASE_PATH', is_string($basePath) ? rtrim($basePath, '/') : '');
}
