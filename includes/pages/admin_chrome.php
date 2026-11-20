<?php

require_once __DIR__ . '/../functions.php';

function cvcarts_admin_alerts(PDO $db): array {
    $lowStock = cvcarts_low_stock_alerts($db);

    return [
        'low_stock' => $lowStock,
        'low_stock_count' => count($lowStock),
        'pending_payments' => (int)$db->query('SELECT COUNT(*) FROM orders WHERE payment_status IN ("Unpaid", "Pending Verification") AND order_status <> "Cancelled"')->fetchColumn(),
        'payment_verification' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'Pending Verification' AND order_status <> 'Cancelled'")->fetchColumn(),
        'ready' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'Paid' AND order_status = 'Ready to Claim' AND claim_date IS NULL")->fetchColumn(),
    ];
}

function cvcarts_admin_header(array $user, array $alerts = []): void {
    $alerts += [
        'low_stock' => [],
        'low_stock_count' => 0,
        'pending_payments' => 0,
        'payment_verification' => 0,
        'ready' => 0,
    ];
    $alertTotal = (int)$alerts['low_stock_count'] + (int)$alerts['pending_payments'] + (int)$alerts['ready'];
    $dashboardUrl = cvcarts_url('/admin/dashboard.php');
    ?>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a href="<?= cvcarts_h($dashboardUrl) ?>"><img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
            <details class="header-menu notification-menu">
                <summary class="header-icon" aria-label="Notifications" title="Notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <?php if ($alertTotal > 0): ?><span class="notification-badge"><?= (int)$alertTotal ?></span><?php endif; ?>
                </summary>
                <div class="header-dropdown notification-dropdown">
                    <div class="dropdown-title">
                        <strong>Notifications</strong>
                        <span><?= (int)$alertTotal ?> pending</span>
                    </div>
                    <?php if ((int)$alerts['pending_payments'] > 0): ?>
                        <a class="dropdown-item" href="<?= cvcarts_h(cvcarts_url('/admin/order-status/index.php')) ?>">
                            <span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-credit-card"></i></span>
                            <span><strong data-sync-count="pending-payments"><?= (int)$alerts['pending_payments'] ?> pending payment<?= (int)$alerts['pending_payments'] === 1 ? '' : 's' ?></strong>
                            <small><span data-sync-count="payment-verification"><?= (int)$alerts['payment_verification'] ?></span> awaiting verification</small></span>
                        </a>
                    <?php endif; ?>
                    <?php if ((int)$alerts['ready'] > 0): ?>
                        <a class="dropdown-item" href="<?= cvcarts_h(cvcarts_url('/admin/ready-to-claim/index.php')) ?>">
                            <span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-bag-check"></i></span>
                            <span><strong data-sync-count="ready-orders"><?= (int)$alerts['ready'] ?> ready to claim</strong>
                            <small>Scan the claiming QR code to release orders</small></span>
                        </a>
                    <?php endif; ?>
                    <?php foreach (array_slice($alerts['low_stock'], 0, 5) as $item): ?>
                        <a class="dropdown-item" href="<?= cvcarts_h(cvcarts_url('/admin/inventory/index.php')) ?>">
                            <span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                            <span><strong>Low stock: <?= cvcarts_h((string)$item['label']) ?></strong>
                            <small>Only <?= (int)$item['stock'] ?> left in stock</small></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($alertTotal === 0): ?>
                        <div class="dropdown-empty">You're all caught up. No low-stock or pending-payment alerts.</div>
                    <?php endif; ?>
                    <?php if ((int)$alerts['pending_payments'] > 0): ?>
                        <a class="dropdown-footer" href="<?= cvcarts_h(cvcarts_url('/admin/order-status/index.php')) ?>">Review payments →</a>
                    <?php elseif ((int)$alerts['ready'] > 0): ?>
                        <a class="dropdown-footer" href="<?= cvcarts_h(cvcarts_url('/admin/ready-to-claim/index.php')) ?>">Ready to Claim →</a>
                    <?php elseif ((int)$alerts['low_stock_count'] > 0): ?>
                        <a class="dropdown-footer" href="<?= cvcarts_h(cvcarts_url('/admin/inventory/index.php')) ?>">View inventory →</a>
                    <?php endif; ?>
                </div>
            </details>
            <details class="header-menu profile-menu">
                <summary class="admin-profile">
                    <img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="">
                    <span><?= cvcarts_h((string)$user['first_name']) ?><i class="profile-chevron bi bi-chevron-down" aria-hidden="true"></i></span>
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
    $items = [
        'dashboard' => ['bi-speedometer2', 'Dashboard', '/admin/dashboard.php'],
        'users' => ['bi-people', 'Users', '/admin/users/index.php'],
        'ready-to-claim' => ['bi-box-seam', 'Ready to Claim', '/admin/ready-to-claim/index.php'],
        'order-status' => ['bi-clipboard-check', 'Order Status', '/admin/order-status/index.php'],
        'qr-scanner' => ['bi-qr-code-scan', 'QR Scanner', '/admin/qr-scanner/index.php'],
        'order-history' => ['bi-clock-history', 'Order History', '/admin/order-history/index.php'],
        'inventory' => ['bi-boxes', 'Inventory', '/admin/inventory/index.php'],
        'sales' => ['bi-bar-chart-line', 'Sales', '/admin/sales/index.php'],
        'settings' => ['bi-gear', 'Settings', '/admin/settings/index.php'],
    ];
    ?>
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <?php foreach ($items as $key => $item): ?>
                <a href="<?= cvcarts_h(cvcarts_url($item[2])) ?>" <?= $key === $active ? 'class="active" aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true"><i class="bi <?= cvcarts_h($item[0]) ?>"></i></span> <?= cvcarts_h($item[1]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/modal.js')) ?>"></script>
    <?php
}