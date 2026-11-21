<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    require_once __DIR__ . '/../config/app.php';
    $sessionPath = defined('CVCARTS_BASE_PATH') && CVCARTS_BASE_PATH !== '' ? CVCARTS_BASE_PATH : '/';
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || parse_url(CVCARTS_BASE_URL, PHP_URL_SCHEME) === 'https';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => $sessionPath,
    ]);
    session_start();
}
require_once __DIR__ . '/functions.php';

    // Enforce server-side inactivity on every request made by an authenticated session.
    if (isset($_SESSION['user_id']) && !cvcarts_enforce_session_timeout()) {
        cvcarts_session_expired_response();
    }

if (!isset($_SESSION['flash'])) {
    $_SESSION['flash'] = [];
}
