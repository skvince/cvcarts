<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    require_once __DIR__ . '/../config/app.php';
    $sessionPath = defined('CVCARTS_BASE_PATH') && CVCARTS_BASE_PATH !== '' ? CVCARTS_BASE_PATH : '/';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => $sessionPath,
    ]);
    session_start();
}
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['flash'])) {
    $_SESSION['flash'] = [];
}
