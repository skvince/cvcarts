<?php
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['expired' => true]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['expired' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['expired' => false, 'error' => 'Invalid session token']);
    exit;
}

$_SESSION['last_activity'] = time();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'last_activity' => $_SESSION['last_activity']]);
