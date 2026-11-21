<?php

require_once __DIR__ . '/../functions.php';

function cvcarts_admin_alerts(PDO $db): array {
    cvcarts_sync_stock_notifications($db);
    $lowStock = cvcarts_low_stock_alerts($db);

    return [
        'low_stock' => $lowStock,
        'low_stock_count' => count($lowStock),
        'pending_payments' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE payment_status IN ("Unpaid", "Pending Verification") AND order_status <> "Cancelled"')->fetchColumn(),
        'payment_verification' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'Pending Verification' AND order_status <> 'Cancelled'")->fetchColumn(),
        'ready' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'Paid' AND order_status = 'Ready to Claim' AND claim_date IS NULL")->fetchColumn(),
        'notification_count' => cvcarts_admin_notification_count($db, (int)($_SESSION['user_id'] ?? 0)),
    ];
}

function cvcarts_admin_header(array $user, array $alerts = []): void {
    $alerts += [
        'low_stock' => [],
        'low_stock_count' => 0,
        'pending_payments' => 0,
        'payment_verification' => 0,
        'ready' => 0,
        'notification_count' => 0,
    ];
    $alertTotal = (int)$alerts['low_stock_count'] + (int)$alerts['pending_payments'] + (int)$alerts['ready'];
    $dashboardUrl = cvcarts_url('/admin/dashboard.php');
    ?>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a class="header-brand" href="<?= cvcarts_h($dashboardUrl) ?>"><img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
            <a class="header-icon" href="<?= cvcarts_h(cvcarts_url('/admin/notifications/index.php')) ?>" aria-label="Notifications" title="Notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if ((int)$alerts['notification_count'] > 0): ?><span class="notification-badge"><?= (int)$alerts['notification_count'] ?></span><?php endif; ?>
            </a>
            <details class="header-menu profile-menu">
                <summary class="admin-profile" aria-label="Open account menu">
                    <img src="<?= cvcarts_h(cvcarts_profile_image_url(is_string($user['profile_image'] ?? null) ? $user['profile_image'] : null)) ?>" alt="">
                    <span><?= cvcarts_h((string)$user['first_name']) ?></span>
                </summary>
                <div class="header-dropdown profile-dropdown">
                    <div class="dropdown-title profile-title">
                        <strong><?= cvcarts_h(trim((string)$user['first_name'] . ' ' . (string)$user['last_name'])) ?></strong>
                        <span><?= cvcarts_h((string)$user['email']) ?></span>
                    </div>
                    <a class="dropdown-item" href="<?= cvcarts_h(cvcarts_url('/admin/settings/index.php')) ?>">
                        <span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-gear"></i></span>
                        <span><strong>Settings</strong><small>Profile, password, and recovery</small></span>
                    </a>
                </div>
            </details>
            <a class="header-logout" href="<?= cvcarts_h(cvcarts_url('/auth/logout.php')) ?>" title="Log out" aria-label="Log out">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span>
            </a>
        </div>
    </header>
    <script>
        (function () {
            if (window.__cvcartsHeaderBound) return;
            window.__cvcartsHeaderBound = true;

            function closeMenus(except) {
                document.querySelectorAll('.header-menu[open]').forEach(function (menu) {
                    if (menu !== except) menu.open = false;
                });
            }

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.header-menu')) closeMenus(null);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeMenus(null);
            });

            document.querySelectorAll('.header-menu').forEach(function (menu) {
                menu.addEventListener('toggle', function () {
                    if (menu.open) closeMenus(menu);
                });
            });
        }());
    </script>
    <?php
}

function cvcarts_admin_sidebar(string $active): void {
    $notificationCount = 0;
    $currentUser = cvcarts_user();
    if ($currentUser && $currentUser['role'] === 'admin') {
        $notificationCount = cvcarts_admin_notification_count(cvcarts_db(), (int)$currentUser['id']);
    }
    $items = [
        'dashboard' => ['bi-speedometer2', 'Dashboard', '/admin/dashboard.php'],
        'users' => ['bi-people', 'Users', '/admin/users/index.php'],
        'ready-to-claim' => ['bi-box-seam', 'Ready to Claim', '/admin/ready-to-claim/index.php'],
        'order-status' => ['bi-clipboard-check', 'Order Status', '/admin/order-status/index.php'],
        'qr-scanner' => ['bi-qr-code-scan', 'QR Scanner', '/admin/qr-scanner/index.php'],
        'order-history' => ['bi-clock-history', 'Order History', '/admin/order-history/index.php'],
        'inventory' => ['bi-boxes', 'Inventory', '/admin/inventory/index.php'],
        'notifications' => ['bi-bell', 'Notifications', '/admin/notifications/index.php'],
        'sales' => ['bi-bar-chart-line', 'Sales', '/admin/sales/index.php'],
        'settings' => ['bi-gear', 'Settings', '/admin/settings/index.php'],
    ];
    ?>
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <?php foreach ($items as $key => $item): ?>
                <a href="<?= cvcarts_h(cvcarts_url($item[2])) ?>" <?= $key === $active ? 'class="active" aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true"><i class="bi <?= cvcarts_h($item[0]) ?>"></i></span> <?= cvcarts_h($item[1]) ?><?php if ($key === 'notifications' && $notificationCount > 0): ?> <span class="sidebar-notification-badge"><?= (int)$notificationCount ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/modal.js')) ?>"></script>
    <?php
}