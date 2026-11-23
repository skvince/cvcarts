<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/order_workflows.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvcarts_qr_response(int $status, string $message, array $data = []): void {
    http_response_code($status);
    echo json_encode(['message' => $message] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

function cvcarts_qr_order_query(PDO $db, string $token): ?array {
    $query = $db->prepare(
        'SELECT o.id, o.user_id, o.reference_number, o.total_amount, o.payment_method, o.payment_status,
            o.order_status, o.created_at, o.verified_at, o.claim_date, o.claimed_by_admin_id,
            u.first_name AS student_first_name, u.last_name AS student_last_name, u.email AS student_email,
            admin.first_name AS admin_first_name, admin.last_name AS admin_last_name
         FROM orders o
         INNER JOIN users u ON u.id = o.user_id
         LEFT JOIN users admin ON admin.id = o.claimed_by_admin_id
         WHERE o.qr_token_hash = :token_hash OR o.qr_token = :legacy_token
         LIMIT 1' . ( $db->inTransaction() ? ' FOR UPDATE' : '')
    );
    $query->execute([
        'token_hash' => hash('sha256', $token),
        'legacy_token' => $token,
    ]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function cvcarts_qr_manual_order_query(PDO $db, string $identifier): ?array {
    $idCondition = ctype_digit($identifier) ? ' OR o.id = :order_id' : '';
    $query = $db->prepare(
        'SELECT o.id, o.user_id, o.reference_number, o.total_amount, o.payment_method, o.payment_status,
            o.order_status, o.created_at, o.verified_at, o.claim_date, o.claimed_by_admin_id,
            u.first_name AS student_first_name, u.last_name AS student_last_name, u.email AS student_email,
            admin.first_name AS admin_first_name, admin.last_name AS admin_last_name
         FROM orders o
         INNER JOIN users u ON u.id = o.user_id
         LEFT JOIN users admin ON admin.id = o.claimed_by_admin_id
         WHERE o.reference_number = :order_reference' . $idCondition . '
         LIMIT 1' . ($db->inTransaction() ? ' FOR UPDATE' : '')
    );
    $parameters = ['order_reference' => $identifier];
    if (ctype_digit($identifier)) {
        $parameters['order_id'] = (int)$identifier;
    }
    $query->execute($parameters);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function cvcarts_qr_manual_unavailable_message(array $order): string {
    if ($order['order_status'] !== 'Ready to Claim') {
        return "Cannot verify Order #{$order['reference_number']}. Current status is '{$order['order_status']}' — only 'Ready to Claim' orders can be claimed.";
    }
    if ($order['payment_status'] !== 'Paid') {
        return "Cannot verify Order #{$order['reference_number']}. Payment is not verified; only paid 'Ready to Claim' orders can be claimed.";
    }
    return "Cannot verify Order #{$order['reference_number']}. This order has already been claimed.";
}

function cvcarts_qr_order_details(PDO $db, array $order): array {
    $itemsQuery = $db->prepare(
        'SELECT oi.quantity, oi.selected_size, oi.unit_price, p.name
         FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = :order_id ORDER BY oi.id'
    );
    $itemsQuery->execute(['order_id' => $order['id']]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
    return [
        'id' => (int)$order['id'],
        'reference_number' => $order['reference_number'],
        'student_name' => trim($order['student_first_name'] . ' ' . $order['student_last_name']),
        'student_email' => $order['student_email'],
        'items' => array_map(static function (array $item): array {
            return [
                'name' => $item['name'],
                'size' => $item['selected_size'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
            ];
        }, $items),
        'total_amount' => (float)$order['total_amount'],
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status'],
        'order_status' => $order['order_status'],
        'order_date' => $order['created_at'],
        'verified_at' => $order['verified_at'],
        'claim_date' => $order['claim_date'],
        'claimed_by_admin' => trim((string)($order['admin_first_name'] ?? '') . ' ' . (string)($order['admin_last_name'] ?? '')) ?: null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    cvcarts_qr_response(405, 'Only POST requests are allowed.');
}

try {
    $user = cvcarts_user();
} catch (Throwable $e) {
    error_log('CVCarts order QR authentication lookup failed: ' . $e->getMessage());
    cvcarts_qr_response(503, 'Order information is temporarily unavailable. Please try again.');
}
if (!$user) {
    cvcarts_qr_response(401, 'Please sign in again.');
}
if ($user['role'] !== 'admin') {
    cvcarts_qr_response(403, 'Administrator access is required.');
}
if ((int)$user['must_change_password'] === 1) {
    cvcarts_qr_response(403, 'Change the temporary administrator password before scanning orders.');
}

$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($submittedCsrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
    cvcarts_qr_response(419, 'The scanner session expired. Reload the page and try again.');
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    cvcarts_qr_response(400, 'The request body must be valid JSON.');
}
$action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : 'verify';
$manualAction = in_array($action, ['verify_manual', 'claim_manual'], true);
$claimAction = in_array($action, ['claim', 'claim_manual'], true);
$qrToken = isset($payload['qr_token']) && is_string($payload['qr_token'])
    ? strtolower(trim($payload['qr_token']))
    : '';
if (!in_array($action, ['verify', 'claim', 'verify_manual', 'claim_manual'], true)) {
    cvcarts_qr_response(400, 'Choose a valid QR scanner action.');
}
if ($claimAction && ($payload['confirm_handover'] ?? null) !== true) {
    cvcarts_qr_response(422, 'Administrator confirmation of physical handover is required.');
}
if ($manualAction) {
    $orderIdentifier = isset($payload['order_identifier']) && is_string($payload['order_identifier'])
        ? strtoupper(trim($payload['order_identifier']))
        : '';
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/', $orderIdentifier)) {
        cvcarts_qr_response(422, 'Enter a valid Order Number or Order ID.');
    }
} elseif (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $qrToken)) {
    cvcarts_qr_response(422, 'INVALID QR CODE');
}

try {
    $db = cvcarts_db();
} catch (Throwable $e) {
    error_log('CVCarts order QR database connection failed: ' . $e->getMessage());
    cvcarts_qr_response(503, 'Order information is temporarily unavailable. Please try again.');
}
if (in_array($action, ['verify', 'verify_manual'], true)) {
    try {
        $order = $manualAction
            ? cvcarts_qr_manual_order_query($db, $orderIdentifier)
            : cvcarts_qr_order_query($db, $qrToken);
        if (!$order) {
            cvcarts_qr_response(404, $manualAction
                ? 'Order not found. Please check the Order Number and try again.'
                : 'INVALID QR CODE');
        }
        $state = cvcarts_order_claim_state($order);
        if ($manualAction && !$state['claimable']) {
            cvcarts_qr_response(409, cvcarts_qr_manual_unavailable_message($order), [
                'order' => cvcarts_qr_order_details($db, $order),
                'claimable' => false,
            ]);
        }
        if ($manualAction) {
            $state['message'] = "Order #{$order['reference_number']} verified and ready for release.";
        }
        cvcarts_qr_response(200, $state['message'], [
            'order' => cvcarts_qr_order_details($db, $order),
            'claimable' => $state['claimable'],
        ]);
    } catch (Throwable $e) {
        error_log('CVCarts order QR verification failed: ' . $e->getMessage());
        cvcarts_qr_response(500, 'The order could not be verified. Please try again.');
    }
}

try {
    $db->beginTransaction();
    $order = $manualAction
        ? cvcarts_qr_manual_order_query($db, $orderIdentifier)
        : cvcarts_qr_order_query($db, $qrToken);
    if (!$order) {
        $db->rollBack();
        cvcarts_qr_response(404, $manualAction
            ? 'Order not found. Please check the Order Number and try again.'
            : 'INVALID QR CODE');
    }
    $state = cvcarts_order_claim_state($order);
    if (!$state['claimable']) {
        $details = cvcarts_qr_order_details($db, $order);
        $db->rollBack();
        $message = $manualAction ? cvcarts_qr_manual_unavailable_message($order) : $state['message'];
        cvcarts_qr_response(409, $message, ['order' => $details, 'claimable' => false]);
    }

    $claim = $db->prepare("UPDATE orders SET order_status = 'Claimed', claim_date = NOW(), claimed_by_admin_id = :admin_id, updated_at = NOW() WHERE id = :id AND payment_status = 'Paid' AND order_status = 'Ready to Claim' AND claim_date IS NULL AND cancelled_at IS NULL");
    $claim->execute(['admin_id' => $user['id'], 'id' => $order['id']]);
    if ($claim->rowCount() !== 1) {
        $db->rollBack();
        cvcarts_qr_response(409, 'The order was claimed by another Admin or is no longer ready.');
    }

    cvcarts_record_order_status($db, (int)$order['id'], 'Ready to Claim', 'Claimed', (int)$user['id'], 'Admin confirmed physical handover.');
    cvcarts_add_notification($db, (int)$order['user_id'], 'Order claimed', 'Your order ' . $order['reference_number'] . ' has been marked as claimed.', (int)$order['id']);
    $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
        ->execute([
            'admin_id' => $user['id'],
            'action' => 'Order marked as claimed',
            'details' => 'Order ' . $order['reference_number'] . ' marked as claimed.',
        ]);
    cvcarts_emit_realtime_event($db, 'order.updated', 'admin');
    cvcarts_emit_realtime_event($db, 'order.updated', 'student', (int)$order['user_id']);
    $order['order_status'] = 'Claimed';
    $claimDate = $db->prepare('SELECT claim_date FROM orders WHERE id = :id');
    $claimDate->execute(['id' => $order['id']]);
    $order['claim_date'] = $claimDate->fetchColumn();
    $order['admin_first_name'] = $user['first_name'];
    $order['admin_last_name'] = $user['last_name'];
    $details = cvcarts_qr_order_details($db, $order);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('CVCarts order QR claim failed: ' . $e->getMessage());
    cvcarts_qr_response(500, 'The order could not be claimed. Please try again or contact support.');
}

cvcarts_qr_response(200, 'Order ' . $order['reference_number'] . ' marked as claimed.', [
    'order' => $details,
    'claimable' => false,
]);
