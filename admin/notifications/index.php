<?php
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');
$db = cvcarts_db();
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    if (isset($_POST['mark_all_read'])) {
        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE notifications n JOIN users u ON u.id = n.user_id SET n.is_read = 1 WHERE u.role = "admin" AND n.user_id = :user_id AND n.is_read = 0');
            $update->execute(['user_id' => $user['id']]);
            if ($update->rowCount() > 0) {
                cvcarts_emit_realtime_event($db, 'notifications.updated', 'admin');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $filter = ($_POST['filter'] ?? '') === 'unread' ? 'unread' : 'all';
        cvcarts_redirect(cvcarts_url('/admin/notifications/index.php?filter=' . $filter));
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
            cvcarts_redirect(cvcarts_url('/admin/notifications/index.php'));
        }
        $update = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id AND is_read = 0');
        $update->execute(['id' => (int)$notificationId, 'user_id' => $user['id']]);
        if ($update->rowCount() > 0) cvcarts_emit_realtime_event($db, 'notifications.updated', 'admin');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        }
        cvcarts_redirect(cvcarts_url('/admin/notifications/index.php'));
    }
}

$filter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';
$totalStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id');
$totalStmt->execute(['user_id' => $user['id']]);
$total = (int)$totalStmt->fetchColumn();
$unread = cvcarts_admin_notification_count($db, (int)$user['id']);
$sqlFilter = $filter === 'unread' ? ' AND n.is_read = 0' : '';
$stmt = $db->prepare('SELECT n.*, o.reference_number FROM notifications n LEFT JOIN orders o ON o.id = n.related_order_id WHERE n.user_id = :user_id' . $sqlFilter . ' ORDER BY n.created_at DESC LIMIT 100');
$stmt->execute(['user_id' => $user['id']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$flashes = cvcarts_get_flash();
$cssVersion = filemtime(__DIR__ . '/../../assets/css/student/notifications.css');
?>
<!DOCTYPE html>
<html lang="en" class="admin-responsive">
<head>
    <?= cvcarts_favicon_tags() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= cvcarts_h(cvcarts_csrf_token()) ?>">
    <title>CVCarts | Admin Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/student/notifications.css?v=' . $cssVersion)) ?>">
</head>
<body>
    <?php cvcarts_admin_header($user, $alerts); ?>
    <div class="app-layout">
        <?php cvcarts_admin_sidebar('notifications'); ?>
        <main class="main-content">
            <div class="notifications-content">
                <section class="notification-toolbar">
                    <div class="notification-heading">
                        <p class="eyebrow">ACTIVITY</p>
                        <h1>Notifications <span class="notification-unread-count" id="notification-unread-count" data-count="<?= $unread ?>"><?= $unread ?> unread</span></h1>
                        <p>Inventory, order, customer, and system updates.</p>
                    </div>
                    <form method="post" class="notification-mark-all" action="<?= cvcarts_h(cvcarts_url('/admin/notifications/index.php')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>">
                        <input type="hidden" name="filter" value="<?= cvcarts_h($filter) ?>">
                        <button class="btn btn-secondary" type="submit" name="mark_all_read" value="1" <?= $unread === 0 ? 'disabled' : '' ?>>Mark all as read</button>
                    </form>
                    <nav class="notification-filters" aria-label="Notification filters">
                        <a href="<?= cvcarts_h(cvcarts_url('/admin/notifications/index.php?filter=all')) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All <span><?= $total ?></span></a>
                        <a href="<?= cvcarts_h(cvcarts_url('/admin/notifications/index.php?filter=unread')) ?>" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread <span id="notification-unread-tab-count" data-count="<?= $unread ?>"><?= $unread ?></span></a>
                    </nav>
                </section>
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash-message <?= $flash['type'] === 'error' ? 'error' : '' ?>" role="status"><?= cvcarts_h($flash['message']) ?></div>
                <?php endforeach; ?>
                <section class="panel notification-panel">
                    <div class="notification-list">
                        <div class="notification-empty" id="notification-empty-state" <?= $notifications ? 'hidden' : '' ?>>
                            <span class="notification-empty-icon" aria-hidden="true">♧</span>
                            <strong><?= $filter === 'unread' ? 'You’re all caught up' : 'No notifications yet' ?></strong>
                            <p><?= $filter === 'unread' ? 'You have no unread notifications.' : 'Admin activity updates will appear here.' ?></p>
                        </div>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $title = (string)$notification['title'];
                            $message = (string)$notification['message'];
                            $reference = (string)($notification['reference_number'] ?? '');
                            $type = stripos($title, 'stock') !== false ? 'updated' : (stripos($title, 'order') !== false ? 'pickup' : 'claimed');
                            $date = date('M j, Y g:i A', strtotime($notification['created_at']));
                            ?>
                            <button class="notification-card<?= !(int)$notification['is_read'] ? ' is-unread' : '' ?>" type="button" data-modal-open="notification-details-modal"
                                data-notification-id="<?= (int)$notification['id'] ?>" data-notification-title="<?= cvcarts_h($title) ?>" data-notification-message="<?= cvcarts_h($message) ?>"
                                data-notification-reference="<?= cvcarts_h($reference) ?>" data-notification-date="<?= cvcarts_h($date) ?>" data-notification-unread="<?= !(int)$notification['is_read'] ? 'true' : 'false' ?>">
                                <span class="notification-icon notification-icon-<?= $type ?>" aria-hidden="true"><i class="bi <?= stripos($title, 'stock') !== false ? 'bi-box-seam' : (stripos($title, 'order') !== false ? 'bi-clipboard-check' : 'bi-bell') ?>"></i></span>
                                <span class="notification-summary">
                                    <span class="notification-title"><?= cvcarts_h($title) ?><?php if (!(int)$notification['is_read']): ?> <span class="notification-new">New</span><?php endif; ?></span>
                                    <span class="notification-message"><?= cvcarts_h($message) ?></span>
                                    <span class="notification-meta"><?php if ($reference): ?><span class="notification-reference"><?= cvcarts_h($reference) ?></span><?php endif; ?><span class="notification-date"><?= cvcarts_h($date) ?></span></span>
                                </span>
                                <span class="notification-view-hint">View</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <dialog class="app-modal notification-details-modal" id="notification-details-modal" aria-labelledby="notification-modal-title">
                    <div class="modal-shell">
                        <header class="modal-header"><div><p class="eyebrow mb-1">NOTIFICATION</p><h2 id="notification-modal-title"></h2><p id="notification-modal-date"></p></div><button class="modal-close" type="button" data-modal-close aria-label="Close notification">×</button></header>
                        <div class="modal-body"><p id="notification-modal-message" class="notification-modal-message"></p><p id="notification-modal-reference" class="notification-modal-reference" hidden></p></div>
                        <footer class="modal-footer"><button class="btn" type="button" data-modal-close>Close</button></footer>
                    </div>
                </dialog>
            </div>
        </main>
    </div>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/modal.js?v=' . filemtime(__DIR__ . '/../../assets/js/modal.js'))) ?>"></script>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/student/notifications.js')) ?>"></script>
</body>
</html>
