<?php
require __DIR__ . '/../includes/auth.php';
cvcarts_require_login('student');

$db = cvcarts_db();
$user = cvcarts_user();
$userId = (int)$user['id'];
$stats = [
    'orders' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId)->fetchColumn(),
    'cart' => (int)$db->query('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ' . $userId)->fetchColumn(),
    'notifications' => (int)$db->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . $userId . ' AND is_read = 0')->fetchColumn(),
    'pending' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId . ' AND order_status IN ("Pending", "Payment Verification")')->fetchColumn(),
    'pending_payments' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId . ' AND payment_status IN ("Unpaid", "Pending Verification") AND order_status <> "Cancelled"')->fetchColumn(),
    'approved' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId . ' AND payment_status = "Paid" AND order_status <> "Cancelled"')->fetchColumn(),
    'ready' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId . ' AND order_status = "Ready to Claim"')->fetchColumn(),
    'completed' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId . ' AND order_status = "Claimed"')->fetchColumn(),
];
$stmt = $db->prepare('SELECT * FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5');
$stmt->execute(['user_id' => $userId]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$products = $db->query('SELECT id, name, category, price, stock FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
$flashes = cvcarts_get_flash();
$statCards = [
    ['label' => 'Total Orders', 'value' => number_format($stats['orders']), 'icon' => 'bi-bag-check', 'tone' => 'neutral'],
    ['label' => 'Pending Payments', 'value' => number_format($stats['pending_payments']), 'icon' => 'bi-hourglass-split', 'tone' => 'amber'],
    ['label' => 'Approved Payments', 'value' => number_format($stats['approved']), 'icon' => 'bi-check-circle', 'tone' => 'emerald'],
    ['label' => 'Ready to Claim', 'value' => number_format($stats['ready']), 'icon' => 'bi-box2-heart', 'tone' => 'blue'],
    ['label' => 'Claimed Orders', 'value' => number_format($stats['completed']), 'icon' => 'bi-bag-check-fill', 'tone' => 'emerald'],
    ['label' => 'Cart Items', 'value' => number_format($stats['cart']), 'icon' => 'bi-cart3', 'tone' => 'neutral'],
    ['label' => 'Unread Notifications', 'value' => number_format($stats['notifications']), 'icon' => 'bi-bell', 'tone' => 'blue'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/student/dashboard.css')); ?>">
</head>
<body>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a class="header-brand" href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
        <a class="header-icon" href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" aria-label="Notifications" title="Notifications">
            <i class="bi bi-bell" aria-hidden="true"></i>
        </a>
            <?php echo cvcarts_student_profile_menu($user); ?>
            <a class="header-logout" href="<?php echo cvcarts_h(cvcarts_url('/auth/logout.php')); ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a>
        </div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>" class="active" aria-current="page"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications<?php if ($stats['notifications']): ?> (<?php echo $stats['notifications']; ?>)<?php endif; ?></a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-history/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/settings/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>

        <main class="main-content student-dashboard">
            <div class="page-heading">
                <div class="dashboard-heading-copy">
                    <h1>Student Dashboard</h1>
                    <p>Welcome back, <?php echo cvcarts_h($user['first_name']); ?>. Here’s your shopping and order overview.</p>
                </div>
                <div class="student-dashboard-actions">
                    <a class="btn btn-small" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>"><i class="bi bi-bag" aria-hidden="true"></i> Shop Now</a>
                    <a class="btn btn-small student-cart-action" href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><i class="bi bi-cart3" aria-hidden="true"></i> My Cart <span><?php echo $stats['cart']; ?></span></a>
                    <div class="page-date"><i class="bi bi-calendar3" aria-hidden="true"></i> &nbsp; <?php echo date('F j, Y'); ?></div>
                </div>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Please Note' : 'Success'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div>
            <?php endforeach; ?>

            <section class="dashboard-statistics" aria-label="Account activity summary">
                <div class="stats-grid student-stats">
                    <?php foreach ($statCards as $card): ?>
                        <article class="stat-card stat-card-<?php echo cvcarts_h($card['tone']); ?>">
                            <div class="stat-card-heading">
                                <p><?php echo cvcarts_h($card['label']); ?></p>
                                <span class="stat-card-icon" aria-hidden="true"><i class="bi <?php echo cvcarts_h($card['icon']); ?>"></i></span>
                            </div>
                            <div class="stat-card-value"><h3><?php echo cvcarts_h($card['value']); ?></h3></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="student-dashboard-grid">
                <div class="student-dashboard-left-column">
                    <section class="panel student-dashboard-panel" aria-labelledby="student-products-title">
                        <div class="panel-heading student-panel-heading">
                            <div>
                                <h2 id="student-products-title">Recently Added</h2>
                                <p>New products available in the shop</p>
                            </div>
                            <span class="student-panel-icon" aria-hidden="true"><i class="bi bi-stars"></i></span>
                        </div>
                        <div class="info-list">
                            <?php if (!$products): ?>
                                <div class="info-row"><strong>No products available yet.</strong></div>
                            <?php endif; ?>
                            <?php foreach ($products as $product): ?>
                                <div class="info-row">
                                    <div>
                                        <strong><?php echo cvcarts_h($product['name']); ?></strong>
                                        <small><?php echo cvcarts_h($product['category']); ?> · <?php echo cvcarts_format_money((float)$product['price']); ?></small>
                                    </div>
                                    <a class="stock-pill" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>">Shop</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                </div>

                <div class="student-dashboard-right-column">
                    <section class="panel student-dashboard-panel" aria-labelledby="student-orders-title">
                        <div class="panel-heading student-panel-heading">
                            <div>
                                <h2 id="student-orders-title">Recent Orders</h2>
                                <p><?php echo $stats['pending']; ?> pending · <?php echo $stats['completed']; ?> completed</p>
                            </div>
                            <span class="student-panel-icon" aria-hidden="true"><i class="bi bi-bag-check"></i></span>
                        </div>
                        <div class="recent-orders-scroll">
                            <table class="recent-orders-table student-recent-orders">
                                <thead>
                                    <tr><th>Order Code</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th>Details</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (!$recentOrders): ?>
                                        <tr><td class="recent-orders-empty" colspan="6">You have no orders yet. <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>">Visit the shop</a>.</td></tr>
                                    <?php else: foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td><strong><?php echo cvcarts_h($order['reference_number']); ?></strong></td>
                                            <td class="currency-cell"><?php echo cvcarts_format_money((float)$order['total_amount']); ?></td>
                                            <td><?php echo cvcarts_h($order['payment_status']); ?></td>
                                            <td class="status-cell"><span class="status <?php echo $order['order_status'] === 'Ready to Claim' ? 'status-info' : ($order['order_status'] === 'Claimed' ? 'status-done' : 'status-pending'); ?>"><?php echo cvcarts_h($order['order_status']); ?></span></td>
                                            <td><?php echo cvcarts_h(date('M j, Y', strtotime($order['created_at']))); ?></td>
                                            <td class="action-cell"><a class="recent-order-action" href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php?view=' . (int)$order['id'])); ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <a class="student-panel-link" href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>">View all orders</a>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js')); ?>"></script>
</body>
</html>
