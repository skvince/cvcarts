<?php
require __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('student');
$db = cvcarts_db();
$user = cvcarts_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    if (isset($_POST['mark_all_read'])) {
        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
            $update->execute(['user_id' => $user['id']]);
            if ($update->rowCount() > 0) {
                cvcarts_emit_realtime_event($db, 'notifications.updated', 'student', (int)$user['id']);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $filter = ($_POST['filter'] ?? '') === 'unread' ? 'unread' : 'all';
        cvcarts_redirect(cvcarts_url('/student/notifications/index.php?filter=' . $filter));
    }

    if (isset($_POST['mark_read'])) {
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $notificationId = filter_var($_POST['notification_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Choose a valid notification.']);
                exit;
            }
            cvcarts_flash('error', 'Choose a valid notification.');
            cvcarts_redirect(cvcarts_url('/student/notifications/index.php'));
        }

        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id AND is_read = 0');
            $update->execute(['id' => (int)$notificationId, 'user_id' => $user['id']]);
            if ($update->rowCount() > 0) {
                cvcarts_emit_realtime_event($db, 'notifications.updated', 'student', (int)$user['id']);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        }
    }
    cvcarts_redirect(cvcarts_url('/student/notifications/index.php'));
}

$notificationFilter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';
$totalQuery = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id');
$totalQuery->execute(['user_id' => $user['id']]);
$notificationTotal = (int)$totalQuery->fetchColumn();
$unreadQuery = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$unreadQuery->execute(['user_id' => $user['id']]);
$unreadCount = (int)$unreadQuery->fetchColumn();
$notificationFilterSql = $notificationFilter === 'unread' ? ' AND n.is_read = 0' : '';
$stmt = $db->prepare('SELECT n.*, o.reference_number FROM notifications n LEFT JOIN orders o ON o.id = n.related_order_id WHERE n.user_id = :user_id' . $notificationFilterSql . ' ORDER BY n.created_at DESC LIMIT 100');
$stmt->execute(['user_id' => $user['id']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$flashes = cvcarts_get_flash();

$notificationsCssPath = __DIR__ . '/../../assets/css/student/notifications.css';
$notificationsCssVersion = file_exists($notificationsCssPath) ? (string)filemtime($notificationsCssPath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
    <title>CVCarts | Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/student/notifications.css?v=' . $notificationsCssVersion)); ?>">
</head>
<body>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions"><a class="header-icon" href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a><div class="admin-profile"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt=""><span><?php echo cvcarts_h($user['first_name']); ?></span></div><a class="header-logout" href="<?php echo cvcarts_h(cvcarts_url('/auth/logout.php')); ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a></div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" class="active" aria-current="page"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-history/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/settings/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>
        <main class="main-content">
            <div class="notifications-content">
                <section class="notification-toolbar">
                    <div class="notification-heading">
                        <p class="eyebrow">ACTIVITY</p>
                        <h1>Notifications <span class="notification-unread-count" id="notification-unread-count" data-count="<?php echo $unreadCount; ?>"><?php echo $unreadCount; ?> unread</span></h1>
                        <p>Order and payment updates for your account.</p>
                    </div>
                    <form method="post" class="notification-mark-all" action="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                        <input type="hidden" name="filter" value="<?php echo cvcarts_h($notificationFilter); ?>">
                        <button class="btn btn-secondary" type="submit" name="mark_all_read" value="1" <?php echo $unreadCount === 0 ? 'disabled' : ''; ?>>Mark all as read</button>
                    </form>
                    <nav class="notification-filters" aria-label="Notification filters">
                        <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php?filter=all')); ?>" class="<?php echo $notificationFilter === 'all' ? 'active' : ''; ?>" <?php echo $notificationFilter === 'all' ? 'aria-current="page"' : ''; ?>>
                            All <span><?php echo $notificationTotal; ?></span>
                        </a>
                        <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php?filter=unread')); ?>" class="<?php echo $notificationFilter === 'unread' ? 'active' : ''; ?>" <?php echo $notificationFilter === 'unread' ? 'aria-current="page"' : ''; ?>>
                            Unread <span id="notification-unread-tab-count" data-count="<?php echo $unreadCount; ?>"><?php echo $unreadCount; ?></span>
                        </a>
                    </nav>
                </section>

                <?php foreach ($flashes as $flash): ?>
                    <div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Please Note' : 'Success'); ?>" role="status">
                        <?php echo cvcarts_h($flash['message']); ?>
                    </div>
                <?php endforeach; ?>

                <section class="panel notification-panel">
                    <div class="notification-list">
                        <div class="notification-empty" id="notification-empty-state" <?php echo $notifications ? 'hidden' : ''; ?>>
                            <span class="notification-empty-icon" aria-hidden="true">♧</span>
                            <strong><?php echo $notificationFilter === 'unread' ? 'You’re all caught up' : 'No notifications yet'; ?></strong>
                            <p><?php echo $notificationFilter === 'unread' ? 'You have no unread notifications.' : 'Order and payment updates will appear here.'; ?></p>
                        </div>

                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $notificationTitle = (string)$notification['title'];
                            $notificationReference = (string)($notification['reference_number'] ?? '');
                            $notificationMessage = (string)$notification['message'];
                            if ($notificationReference !== '') {
                                $notificationMessage = str_replace($notificationReference, '', $notificationMessage);
                                $notificationMessage = preg_replace('/[ \t]{2,}/', ' ', $notificationMessage) ?? $notificationMessage;
                                $notificationMessage = preg_replace('/(?:\s*[·,;:|–—-]\s*)+$/u', '', trim($notificationMessage)) ?? trim($notificationMessage);
                            }
                            $notificationType = stripos($notificationTitle, 'claimed') !== false
                                ? 'claimed'
                                : (stripos($notificationTitle, 'pickup') !== false ? 'pickup' : 'updated');
                            $notificationDate = date('M j, Y g:i A', strtotime($notification['created_at']));
                            ?>
                            <button
                                class="notification-card<?php echo !(int)$notification['is_read'] ? ' is-unread' : ''; ?>"
                                type="button"
                                data-modal-open="notification-details-modal"
                                data-notification-id="<?php echo (int)$notification['id']; ?>"
                                data-notification-title="<?php echo cvcarts_h($notificationTitle); ?>"
                                data-notification-message="<?php echo cvcarts_h($notificationMessage); ?>"
                                data-notification-reference="<?php echo cvcarts_h($notificationReference); ?>"
                                data-notification-date="<?php echo cvcarts_h($notificationDate); ?>"
                                data-notification-unread="<?php echo !(int)$notification['is_read'] ? 'true' : 'false'; ?>"
                                aria-haspopup="dialog"
                                aria-controls="notification-details-modal"
                                aria-label="View notification: <?php echo cvcarts_h($notificationTitle); ?>"
                            >
                                <span class="notification-icon notification-icon-<?php echo $notificationType; ?>" aria-hidden="true">
                                    <?php if ($notificationType === 'claimed'): ?>
                                        <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16 9"></path></svg>
                                    <?php elseif ($notificationType === 'pickup'): ?>
                                        <svg viewBox="0 0 24 24" focusable="false"><rect x="3.5" y="5" width="17" height="16" rx="2"></rect><path d="M7.5 3v4M16.5 3v4M3.5 10h17M8 14h3M8 17h6"></path></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" focusable="false"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"></path><path d="m4.5 7.5 7.5 4 7.5-4M12 12v9"></path></svg>
                                    <?php endif; ?>
                                </span>
                                <span class="notification-summary">
                                    <span class="notification-title">
                                        <?php echo cvcarts_h($notificationTitle); ?>
                                        <?php if (!(int)$notification['is_read']): ?>
                                            <span class="notification-new">New</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="notification-message"><?php echo cvcarts_h($notificationMessage); ?></span>
                                    <span class="notification-meta">
                                        <?php if ($notificationReference !== ''): ?>
                                            <span class="notification-reference"><?php echo cvcarts_h($notificationReference); ?></span>
                                        <?php endif; ?>
                                        <span class="notification-date"><?php echo cvcarts_h($notificationDate); ?></span>
                                    </span>
                                </span>
                                <span class="notification-view-hint">View <span aria-hidden="true">›</span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>

                <dialog class="app-modal notification-details-modal" id="notification-details-modal" aria-labelledby="notification-modal-title" aria-describedby="notification-modal-message">
                    <div class="modal-shell">
                        <header class="modal-header">
                            <div>
                                <p class="eyebrow mb-1">NOTIFICATION</p>
                                <h2 id="notification-modal-title"></h2>
                                <p id="notification-modal-date"></p>
                            </div>
                            <button class="modal-close" type="button" data-modal-close aria-label="Close notification">×</button>
                        </header>
                        <div class="modal-body">
                            <p id="notification-modal-message" class="notification-modal-message"></p>
                            <p id="notification-modal-reference" class="notification-modal-reference" hidden></p>
                        </div>
                        <footer class="modal-footer">
                            <button class="btn" type="button" data-modal-close>Close</button>
                        </footer>
                    </div>
                </dialog>
            </div>
        </main>
    </div>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js')); ?>"></script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/student/notifications.js')); ?>"></script>
</body>
</html>