<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pages/admin_chrome.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = cvcarts_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['message' => 'Please sign in again.']);
    exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['message' => 'Administrator access is required.']);
    exit;
}

$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'month';
$series = cvcarts_sales_series(cvcarts_db(), $period);

echo json_encode([
    'period' => $series['period'],
    'labels' => $series['labels'],
    'values' => $series['values'],
    'total' => $series['total'],
], JSON_UNESCAPED_SLASHES);
exit;