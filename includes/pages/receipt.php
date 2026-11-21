<?php
require_once __DIR__ . '/../auth.php';
cvcarts_require_login();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$user = cvcarts_user();
$isAdmin = strtolower((string)$user['role']) === 'admin';
$orderIdInput = $_GET['order_id'] ?? '';
$orderId = filter_var(is_string($orderIdInput) || is_int($orderIdInput) ? (string)$orderIdInput : '', FILTER_VALIDATE_INT);
$receiptType = (string)($_GET['type'] ?? 'payment');
if (!$orderId || $orderId < 1 || !in_array($receiptType, ['payment', 'claiming'], true)) {
    http_response_code(400);
    exit('A valid order and receipt type are required.');
}

$db = cvcarts_db();
$orderQuery = $db->prepare(
    'SELECT o.*, u.first_name, u.last_name, u.email, admin.first_name AS admin_first_name, admin.last_name AS admin_last_name,
        p.status AS payment_record_status, p.transaction_reference, p.paid_at, p.proof_path
     FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     LEFT JOIN users admin ON admin.id = o.claimed_by_admin_id
     LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
     WHERE o.id = :order_id' . ($isAdmin ? '' : ' AND o.user_id = :user_id')
);
$params = ['order_id' => $orderId];
if (!$isAdmin) {
    $params['user_id'] = (int)$user['id'];
}
$orderQuery->execute($params);
$order = $orderQuery->fetch(PDO::FETCH_ASSOC);
if (!$order || ($receiptType === 'payment' && $order['payment_status'] !== 'Paid')) {
    http_response_code(404);
    exit($receiptType === 'payment' ? 'A paid order was not found.' : 'The requested order was not found.');
}

$itemsQuery = $db->prepare('SELECT oi.quantity, oi.unit_price, oi.selected_size, p.name FROM order_items oi INNER JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :order_id ORDER BY oi.id');
$itemsQuery->execute(['order_id' => $orderId]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
$title = $receiptType === 'payment' ? 'Payment Receipt' : 'Order Receipt';
$receiptCssDirectory = $isAdmin ? 'admin' : 'student';
$dashboardUrl = cvcarts_url($isAdmin ? '/admin/dashboard.php' : '/student/dashboard.php');
$qrValue = cvcarts_order_qr_display_value($order);
$showClaimQr = is_string($qrValue);
$adminAlerts = [];
if ($isAdmin) {
    require_once __DIR__ . '/admin_chrome.php';
    $adminAlerts = cvcarts_admin_alerts($db);
}
if ($receiptType === 'claiming' && $order['payment_status'] === 'Paid' && $order['order_status'] !== 'Cancelled' && !$showClaimQr) {
    error_log('CVCarts paid order receipt is missing a verifiable QR token for order ID ' . (int)$order['id'] . '.');
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | <?= cvcarts_h($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/' . $receiptCssDirectory . '/receipt.css')) ?>">
</head>
<body>
    <?php if ($isAdmin): ?>
        <?php cvcarts_admin_header($user, $adminAlerts); ?>
    <?php else: ?>
        <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
        <header class="top-header">
            <a class="header-brand" href="<?= cvcarts_h($dashboardUrl) ?>"><img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="CVCarts Logo" class="header-logo"></a>
            <div class="header-actions">
                <a class="header-icon" href="<?= cvcarts_h(cvcarts_url('/student/notifications/index.php')) ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a>
                <?= cvcarts_student_profile_menu($user) ?>
                <a class="header-logout" href="<?= cvcarts_h(cvcarts_url('/auth/logout.php')) ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a>
            </div>
        </header>
    <?php endif; ?>
    <div class="app-layout">
        <?php if ($isAdmin): ?>
            <?php cvcarts_admin_sidebar('dashboard'); ?>
        <?php else: ?>
            <aside class="sidebar">
                <nav class="sidebar-nav">
                    <a href="<?= cvcarts_h(cvcarts_url('/student/dashboard.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/cart/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/shop/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/order-status/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/notifications/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/order-history/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                    <a href="<?= cvcarts_h(cvcarts_url('/student/settings/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
                </nav>
            </aside>
        <?php endif; ?>
        <main class="main-content">
        <div class="receipt-page">
        <section class="panel">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash-message receipt-alert <?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : '') ?>" data-app-alert="true" data-alert-type="<?= cvcarts_h($flash['type']) ?>" data-alert-title="<?= $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Warning' : 'Success') ?>" role="status"><?= cvcarts_h($flash['message']) ?></div>
            <?php endforeach; ?>
            <div class="receipt-top">
                <img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="CVCarts Logo">
                <div><p class="eyebrow"><?= cvcarts_h($title) ?></p><h1><?= cvcarts_h($order['reference_number']) ?></h1></div>
            </div>
            <div class="receipt-meta">
                <div><span>Student</span><strong><?= cvcarts_h($order['first_name'] . ' ' . $order['last_name']) ?></strong></div>
                <div><span>Registered email</span><strong><?= cvcarts_h($order['email']) ?></strong></div>
                <div><span>Order date</span><strong><?= cvcarts_h(date('M j, Y g:i A', strtotime($order['created_at']))) ?></strong></div>
                <div><span>Payment method</span><strong><?= cvcarts_h($order['payment_method']) ?></strong></div>
                <div><span>Payment status</span><strong><?= cvcarts_h($order['payment_status']) ?></strong></div>
                <?php if ($receiptType === 'payment'): ?>
                    <div><span>Transaction reference</span><strong><?= cvcarts_h($order['transaction_reference'] ?: $order['payment_reference'] ?: 'Not provided') ?></strong></div>
                    <div><span>Date verified</span><strong><?= $order['verified_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($order['verified_at']))) : ($order['paid_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($order['paid_at']))) : '—') ?></strong></div>
                <?php else: ?>
                    <div><span>Order status</span><strong><?= cvcarts_h($order['order_status']) ?></strong></div>
                    <?php if ($order['verified_at']): ?><div><span>Date verified</span><strong><?= cvcarts_h(date('M j, Y g:i A', strtotime($order['verified_at']))) ?></strong></div><?php endif; ?>
                    <?php if ($order['claim_date']): ?><div><span>Date received</span><strong><?= cvcarts_h(date('M j, Y g:i A', strtotime($order['claim_date']))) ?></strong></div><?php endif; ?>
                    <?php if ($order['cancelled_at']): ?><div><span>Cancellation date</span><strong><?= cvcarts_h(date('M j, Y g:i A', strtotime($order['cancelled_at']))) ?></strong></div><?php endif; ?>
                    <?php if ($order['admin_first_name']): ?><div><span>Released by</span><strong><?= cvcarts_h($order['admin_first_name'] . ' ' . $order['admin_last_name']) ?></strong></div><?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="table-wrapper"><table>
                <thead><tr><th>Item</th><th>Size</th><th>Quantity</th><th>Unit price</th><th>Subtotal</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr><td><?= cvcarts_h($item['name']) ?></td><td><?= cvcarts_h($item['selected_size'] ?: '—') ?></td><td><?= (int)$item['quantity'] ?></td><td><?= cvcarts_format_money((float)$item['unit_price']) ?></td><td><?= cvcarts_format_money((float)$item['unit_price'] * (int)$item['quantity']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td colspan="4"><strong>Total</strong></td><td><strong><?= cvcarts_format_money((float)$order['total_amount']) ?></strong></td></tr>
                </tbody>
            </table></div>
            <?php if ($showClaimQr): ?>
                <div class="receipt-qr"><h2>Order Claim QR Code</h2><p>
                    <?php if ($order['order_status'] === 'Claimed'): ?>
                        This order was already received. The code is retained for verification.
                    <?php else: ?>
                        Present this code to an Admin when collecting your order.
                    <?php endif; ?>
                </p><div id="claim-qr" data-qr-value="<?= cvcarts_h($qrValue) ?>"></div></div>
            <?php elseif ($receiptType === 'claiming'): ?>
                <?php if ($order['order_status'] === 'Cancelled'): ?>
                    <div class="note-box"><div class="note-icon">!</div><div><h3>Order cancelled</h3><p>This order cannot be claimed.</p></div></div>
                <?php elseif ($order['payment_status'] !== 'Paid'): ?>
                    <div class="note-box"><div class="note-icon">⌛</div><div><h3>Claiming QR code pending</h3><p>The QR code will be available after Admin approves your payment.</p></div></div>
                <?php else: ?>
                    <div class="flash-message error" role="status">A claiming QR code is unavailable. Please contact support before pickup.</div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="action-row receipt-actions">
                <a class="btn" href="<?= cvcarts_h($dashboardUrl) ?>">Dashboard</a>
                <button class="btn" type="button" onclick="window.print()">Print Receipt</button>
            </div>
        </section>
        </div>
        </main>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        function renderClaimQrCode() {
            const element = document.getElementById('claim-qr');
            if (element && element.childElementCount === 0 && window.QRCode) {
                new QRCode(element, { text: element.getAttribute('data-qr-value'), width: 220, height: 220 });
            }
        }
        renderClaimQrCode();
        document.addEventListener('cvcarts:content-updated', renderClaimQrCode);
    </script>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/modal.js')) ?>"></script>
</body>
</html>
