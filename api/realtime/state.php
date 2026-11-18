<?php
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = cvcarts_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['message' => 'Please sign in again.']);
    exit;
}

$db = cvcarts_db();
if ($user['role'] === 'admin') {
    require_once __DIR__ . '/../../includes/pages/admin_chrome.php';
    $alerts = cvcarts_admin_alerts($db);
    echo json_encode([
        'role' => 'admin',
        'notification_count' => (int)$alerts['low_stock_count'] + (int)$alerts['pending_payments'] + (int)$alerts['ready'],
        'pending_payments' => (int)$alerts['pending_payments'],
        'payment_verification' => (int)$alerts['payment_verification'],
        'ready_orders' => (int)$alerts['ready'],
        'low_stock_count' => (int)$alerts['low_stock_count'],
        'low_stock' => array_map(static function (array $item): array {
            return ['label' => (string)$item['label'], 'stock' => (int)$item['stock']];
        }, $alerts['low_stock']),
    ]);
    exit;
}

$unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$unread->execute(['user_id' => $user['id']]);
echo json_encode([
    'role' => 'student',
    'notification_count' => (int)$unread->fetchColumn(),
    'cart_count' => cvcarts_cart_count(),
]);
