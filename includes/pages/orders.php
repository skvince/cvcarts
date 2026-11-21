<?php
require_once __DIR__ . '/../order_workflows.php';

$db = cvcarts_db();
$currentUser = cvcarts_user();
$isAdmin = strtolower((string)($currentUser['role'] ?? '')) === 'admin';
if ($isAdmin) {
    require_once __DIR__ . '/admin_chrome.php';
}
$ordersView = (string)($ordersView ?? ((bool)($ordersHistory ?? false) ? 'history' : 'active'));
$ordersHistory = $ordersView === 'history';
$ordersReady = $ordersView === 'ready';
$statusOptions = $ordersHistory
    ? ($isAdmin ? ['Claimed', 'Cancelled'] : ['Already Received', 'Cancelled', 'Rejected'])
    : ($ordersReady ? ['Ready to Claim'] : ($isAdmin ? ['Pending', 'Payment Verification', 'Payment Rejected', 'In Transit'] : ['Pending', 'Payment Verification', 'Payment Rejected', 'In Transit', 'Ready to Claim']));
$route = $isAdmin
    ? ($ordersHistory ? '/admin/order-history/index.php' : ($ordersReady ? '/admin/ready-to-claim/index.php' : '/admin/order-status/index.php'))
    : ($ordersHistory ? '/student/order-history/index.php' : '/student/order-status/index.php');
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '';
$paymentFilter = is_string($_GET['payment_status'] ?? null) ? $_GET['payment_status'] : '';
$methodFilter = is_string($_GET['payment_method'] ?? null) ? $_GET['payment_method'] : '';
$orderDate = is_string($_GET['order_date'] ?? null) ? $_GET['order_date'] : '';
$orderDateFrom = !$isAdmin && $ordersHistory && is_string($_GET['order_date_from'] ?? null) ? $_GET['order_date_from'] : '';
$orderDateTo = !$isAdmin && $ordersHistory && is_string($_GET['order_date_to'] ?? null) ? $_GET['order_date_to'] : '';
$verifiedDate = is_string($_GET['verified_date'] ?? null) ? $_GET['verified_date'] : '';
$receivedDate = is_string($_GET['received_date'] ?? null) ? $_GET['received_date'] : '';
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest';
$sortOptions = ['newest' => 'o.created_at DESC, o.id DESC', 'oldest' => 'o.created_at ASC, o.id ASC'];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}
$pageSizeInput = filter_var($_GET['per_page'] ?? 20, FILTER_VALIDATE_INT);
$pageSize = in_array($pageSizeInput, [10, 20, 50], true) ? $pageSizeInput : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$viewId = max(0, (int)($_GET['view'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($ordersHistory || $ordersReady) {
        http_response_code(405);
        exit('This order list is view-only.');
    }
    cvcarts_require_csrf();
    if (!$isAdmin) {
        if (strtolower((string)($currentUser['role'] ?? '')) !== 'student') {
            http_response_code(403);
            exit('Forbidden');
        }
        if (isset($_POST['cancel_order'])) {
            $orderId = filter_var($_POST['order_id'] ?? '', FILTER_VALIDATE_INT);
            $result = $orderId
                ? cvcarts_student_cancel_order($db, (int)$orderId, (int)$currentUser['id'])
                : ['success' => false, 'message' => 'Choose a valid order to cancel.'];
            cvcarts_flash($result['success'] ? 'success' : 'error', $result['message']);
            cvcarts_redirect(cvcarts_url($route));
        }
        if (!isset($_POST['resubmit_payment'])) {
            http_response_code(403);
            exit('Forbidden');
        }
        $orderId = filter_var($_POST['order_id'] ?? '', FILTER_VALIDATE_INT);
        $referenceInput = $_POST['transaction_reference'] ?? '';
        $upload = $_FILES['payment_proof'] ?? null;
        if (!$orderId || !is_string($referenceInput) || trim($referenceInput) === '' || strlen(trim($referenceInput)) > 150 || !is_array($upload)) {
            cvcarts_flash('error', 'Enter a transaction reference and attach valid payment proof.');
            cvcarts_redirect(cvcarts_url($route . '?view=' . max(0, (int)($orderId ?: 0))));
        }
        try {
            $proofFileName = cvcarts_store_payment_proof($upload);
        } catch (RuntimeException $e) {
            cvcarts_flash('error', $e->getMessage());
            cvcarts_redirect(cvcarts_url($route . '?view=' . (int)$orderId));
        }

        $proofTarget = __DIR__ . '/../../uploads/payment-proofs/' . $proofFileName;
        $db->beginTransaction();
        try {
            $orderQuery = $db->prepare('SELECT id, user_id, reference_number, total_amount, payment_method, payment_status, order_status FROM orders WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $orderQuery->execute(['id' => $orderId, 'user_id' => $currentUser['id']]);
            $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['payment_method'] === 'Cash' || $order['payment_status'] !== 'Rejected' || $order['order_status'] !== 'Payment Rejected') {
                $db->rollBack();
                unlink($proofTarget);
                cvcarts_flash('error', 'Only your rejected online payments can be resubmitted.');
                cvcarts_redirect(cvcarts_url($route . '?view=' . (int)$orderId));
            }

            $transactionReference = trim($referenceInput);
            $db->prepare("INSERT INTO payments (order_id, payment_method, amount, status, transaction_reference, proof_path, submitted_at) VALUES (:order_id, :method, :amount, 'Pending Verification', :reference, :proof_path, NOW())")
                ->execute([
                    'order_id' => $orderId,
                    'method' => $order['payment_method'],
                    'amount' => $order['total_amount'],
                    'reference' => $transactionReference,
                    'proof_path' => $proofFileName,
                ]);
            $db->prepare("UPDATE orders SET payment_status = 'Pending Verification', order_status = 'Payment Verification', payment_reference = :reference, payment_proof_path = :proof_path, updated_at = NOW() WHERE id = :id")
                ->execute(['reference' => $transactionReference, 'proof_path' => $proofFileName, 'id' => $orderId]);
            cvcarts_record_order_status($db, (int)$orderId, 'Payment Rejected', 'Payment Verification', (int)$currentUser['id'], 'Student resubmitted transaction reference and proof.');
            cvcarts_add_notification($db, (int)$currentUser['id'], 'Payment resubmitted', 'Your payment proof for order ' . $order['reference_number'] . ' is awaiting review.', (int)$orderId);
            cvcarts_emit_realtime_event($db, 'payment.updated', 'admin');
            cvcarts_emit_realtime_event($db, 'order.updated', 'student', (int)$currentUser['id']);
            $db->commit();
            cvcarts_flash('success', 'Your payment proof was resubmitted for Admin review.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_file($proofTarget)) {
                unlink($proofTarget);
            }
            throw $e;
        }
        cvcarts_redirect(cvcarts_url($route . '?view=' . (int)$orderId));
    }

    $orderId = max(0, (int)($_POST['order_id'] ?? 0));
    if (isset($_POST['payment_decision'])) {
        $result = cvcarts_admin_review_payment(
            $db,
            $orderId,
            (int)$currentUser['id'],
            (string)$_POST['payment_decision'],
            (string)($_POST['rejection_reason'] ?? '')
        );
    } elseif (isset($_POST['new_status'])) {
        $result = cvcarts_admin_update_order_status(
            $db,
            $orderId,
            (int)$currentUser['id'],
            (string)$_POST['new_status'],
            (string)($_POST['status_reason'] ?? '')
        );
    } else {
        $result = ['success' => false, 'message' => 'No valid order action was selected.'];
    }

    cvcarts_flash($result['success'] ? 'success' : 'error', $result['message']);
    $returnView = isset($_POST['return_view']) ? max(0, (int)$_POST['return_view']) : 0;
    cvcarts_redirect(cvcarts_url($route . ($returnView > 0 ? '?view=' . $returnView : '')));
}

$where = [];
$params = [];
if (!$isAdmin) {
    $where[] = 'o.user_id = :user_id';
    $params['user_id'] = (int)$currentUser['id'];
}
if ($ordersHistory) {
    $where[] = $isAdmin
        ? "o.order_status IN ('Claimed', 'Cancelled')"
        : "o.order_status IN ('Claimed', 'Cancelled', 'Payment Rejected')";
} elseif ($ordersReady) {
    $where[] = "o.payment_status = 'Paid' AND o.order_status = 'Ready to Claim' AND o.claim_date IS NULL";
} elseif ($isAdmin) {
    $where[] = "o.order_status NOT IN ('Ready to Claim', 'Claimed', 'Cancelled')";
} else {
    $where[] = "o.order_status NOT IN ('Claimed', 'Cancelled')";
}
if ($statusFilter !== '' && in_array($statusFilter, $statusOptions, true)) {
    if (!$isAdmin && $ordersHistory && in_array($statusFilter, ['Already Received', 'Rejected'], true)) {
        $where[] = 'o.order_status = :history_status';
        $params['history_status'] = $statusFilter === 'Already Received' ? 'Claimed' : 'Payment Rejected';
    } else {
        $where[] = 'o.order_status = :status';
        $params['status'] = $statusFilter;
    }
}
if (in_array($paymentFilter, ['Unpaid', 'Pending Verification', 'Paid', 'Rejected'], true)) {
    $where[] = 'o.payment_status = :payment_status';
    $params['payment_status'] = $paymentFilter;
}
if ($isAdmin && in_array($methodFilter, ['Cash', 'GCash', 'Maya', 'Bank Transfer'], true)) {
    $where[] = 'o.payment_method = :payment_method';
    $params['payment_method'] = $methodFilter;
}
$validDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
};
foreach (['order_date' => $orderDate, 'verified_date' => $verifiedDate, 'received_date' => $receivedDate] as $dateKey => $dateValue) {
    if ($dateValue !== '' && !$validDate($dateValue)) {
        cvcarts_flash('error', 'Choose a valid date filter.');
        if ($dateKey === 'order_date') {
            $orderDate = '';
        } elseif ($dateKey === 'verified_date') {
            $verifiedDate = '';
        } else {
            $receivedDate = '';
        }
    }
}
if (!$isAdmin && (
    ($orderDateFrom !== '' && !$validDate($orderDateFrom))
    || ($orderDateTo !== '' && !$validDate($orderDateTo))
    || ($orderDateFrom !== '' && $orderDateTo !== '' && $orderDateFrom > $orderDateTo)
)) {
    cvcarts_flash('error', 'Choose a valid order date range.');
    $orderDateFrom = $orderDateTo = '';
}
if ($isAdmin && $orderDate !== '') {
    $where[] = 'DATE(o.created_at) = :order_date';
    $params['order_date'] = $orderDate;
}
if (!$isAdmin && $orderDateFrom !== '') {
    $where[] = 'o.created_at >= :order_date_from';
    $params['order_date_from'] = $orderDateFrom . ' 00:00:00';
}
if (!$isAdmin && $orderDateTo !== '') {
    $where[] = 'o.created_at < DATE_ADD(:order_date_to, INTERVAL 1 DAY)';
    $params['order_date_to'] = $orderDateTo;
}
if ($isAdmin && $ordersReady && $verifiedDate !== '') {
    $where[] = 'DATE(o.verified_at) = :verified_date';
    $params['verified_date'] = $verifiedDate;
}
if ($isAdmin && $ordersHistory && $receivedDate !== '') {
    $where[] = "o.order_status = 'Claimed' AND DATE(o.claim_date) = :received_date";
    $params['received_date'] = $receivedDate;
}
if ($search !== '') {
    $searchFields = '(o.reference_number LIKE :search_reference OR u.first_name LIKE :search_first_name OR u.last_name LIKE :search_last_name';
    if (!$isAdmin || $ordersReady) {
        $searchFields .= ' OR u.email LIKE :search_email';
        $params['search_email'] = '%' . $search . '%';
    }
    $where[] = $searchFields . ')';
    $params['search_reference'] = '%' . $search . '%';
    $params['search_first_name'] = '%' . $search . '%';
    $params['search_last_name'] = '%' . $search . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = $db->prepare('SELECT COUNT(*) FROM orders o INNER JOIN users u ON u.id = o.user_id' . $whereSql);
$countQuery->execute($params);
$totalOrders = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalOrders / $pageSize));
$page = min($page, $totalPages);
$ordersQuery = $db->prepare(
    'SELECT o.*, u.first_name, u.last_name, u.email,
        (SELECT GROUP_CONCAT(CONCAT(p.name, " × ", oi.quantity, IF(oi.selected_size IS NULL OR oi.selected_size = "", "", CONCAT(" (", oi.selected_size, ")"))) ORDER BY oi.id SEPARATOR ", ")
         FROM order_items oi INNER JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id) AS ordered_items,
        p.status AS latest_payment_status, p.transaction_reference, p.proof_path, p.rejection_reason
     FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)'
     . $whereSql . ' ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $ordersQuery->bindValue(':' . $key, $value);
}
$ordersQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$ordersQuery->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$ordersQuery->execute();
$orders = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

$detailOrder = null;
$detailQrValue = null;
$detailItems = [];
$detailHistory = [];
if ($viewId > 0) {
    $detailSql = 'SELECT o.*, u.first_name, u.last_name, u.email,
        p.status AS latest_payment_status, p.transaction_reference, p.proof_path, p.rejection_reason
        FROM orders o INNER JOIN users u ON u.id = o.user_id
        LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
        WHERE o.id = :id' . (!$isAdmin ? ' AND o.user_id = :user_id' : '');
    $detailQuery = $db->prepare($detailSql);
    $detailParams = ['id' => $viewId];
    if (!$isAdmin) {
        $detailParams['user_id'] = (int)$currentUser['id'];
    }
    $detailQuery->execute($detailParams);
    $detailOrder = $detailQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailOrder) {
        $detailQrValue = (!$ordersHistory || $isAdmin) ? cvcarts_order_qr_display_value($detailOrder) : null;
        if ((!$ordersHistory || $isAdmin) && $detailOrder['payment_status'] === 'Paid' && $detailOrder['order_status'] !== 'Cancelled' && $detailQrValue === null) {
            error_log('CVCarts paid order details are missing a verifiable QR token for order ID ' . (int)$detailOrder['id'] . '.');
        }
        $itemsQuery = $db->prepare('SELECT oi.quantity, oi.selected_size, oi.unit_price, p.name FROM order_items oi INNER JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :order_id ORDER BY oi.id');
        $itemsQuery->execute(['order_id' => $viewId]);
        $detailItems = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        $historyQuery = $db->prepare('SELECT h.old_status, h.new_status, h.reason, h.created_at, u.first_name, u.last_name FROM order_status_history h LEFT JOIN users u ON u.id = h.changed_by_user_id WHERE h.order_id = :order_id ORDER BY h.created_at DESC, h.id DESC');
        $historyQuery->execute(['order_id' => $viewId]);
        $detailHistory = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
    }
}

$availableStatuses = static function (array $order) use ($isAdmin): array {
    if (!$isAdmin) {
        return $order['payment_status'] === 'Unpaid' && $order['order_status'] === 'Pending'
            ? ['Cancelled']
            : [];
    }
    if ($order['payment_status'] !== 'Paid' && !in_array($order['order_status'], ['Claimed', 'Cancelled'], true)) {
        return ['Cancelled'];
    }
    return [];
};
$dashboardRoute = $isAdmin ? '/admin/dashboard.php' : '/student/dashboard.php';
$alerts = $isAdmin ? cvcarts_admin_alerts($db) : [];
$ordersCssDirectory = $isAdmin ? 'admin' : 'student';
$ordersCssPage = $isAdmin && $ordersReady ? 'ready-to-claim' : ($ordersHistory ? 'order-history' : 'order-status');
$ordersPageClass = ($isAdmin ? 'admin' : 'student') . '-' . $ordersCssPage;
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | <?= $ordersHistory ? 'Order History' : ($ordersReady ? 'Ready to Claim' : 'Order Status') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/' . $ordersCssDirectory . '/' . $ordersCssPage . '.css')) ?>">
    <?php if ($isAdmin): ?>
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/admin/order-details-modal.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= cvcarts_h($ordersPageClass) ?>">
    <?php if ($isAdmin): ?>
        <?php cvcarts_admin_header($currentUser, $alerts); ?>
        <div class="app-layout">
            <?php cvcarts_admin_sidebar($ordersHistory ? 'order-history' : ($ordersReady ? 'ready-to-claim' : 'order-status')); ?>
            <main class="main-content">
    <?php else: ?>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a href="<?= cvcarts_h(cvcarts_url($dashboardRoute)) ?>"><img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
            <a class="header-icon" href="<?= cvcarts_h(cvcarts_url('/student/notifications/index.php')) ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a>
            <div class="admin-profile"><img src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt=""><span><?= cvcarts_h($currentUser['first_name']) ?><i class="bi bi-chevron-down profile-chevron" aria-hidden="true"></i></span></div>
            <a class="header-logout" href="<?= cvcarts_h(cvcarts_url('/auth/logout.php')) ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a>
        </div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?= cvcarts_h(cvcarts_url('/student/dashboard.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/cart/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/shop/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/order-status/index.php')) ?>" <?= !$ordersHistory ? 'class="active" aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/notifications/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/order-history/index.php')) ?>" <?= $ordersHistory ? 'class="active" aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?= cvcarts_h(cvcarts_url('/student/settings/index.php')) ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>
        <main class="main-content">
    <?php endif; ?>
            <?php foreach ($flashes as $flash): ?>
                <div class="flash-message <?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : '') ?>" data-app-alert="true" data-alert-type="<?= cvcarts_h($flash['type']) ?>" data-alert-title="<?= $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Warning' : 'Success') ?>" role="status"><?= cvcarts_h($flash['message']) ?></div>
            <?php endforeach; ?>
<section class="page-heading">
    <div><p class="eyebrow"><?= $isAdmin ? 'FULFILLMENT' : 'PURCHASES' ?></p><h1><?= $ordersHistory ? 'Order History' : ($ordersReady ? 'Ready to Claim' : 'Order Status') ?></h1><p><?= $isAdmin ? ($ordersHistory ? 'View claimed and cancelled orders.' : ($ordersReady ? 'Paid and verified orders awaiting student collection.' : 'Review submitted payments and verify orders.')) : ($ordersHistory ? 'Review rejected, cancelled, or already received orders.' : 'Review your orders and payment details.') ?></p></div>
</section>

<?php if ($detailOrder): ?>
    <div class="modal fade order-details-modal" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content order-details-modal-content">
                <div class="modal-header">
                    <div>
                        <p class="eyebrow mb-1">ORDER DETAILS</p>
                        <h2 class="modal-title fs-5" id="orderDetailsModalLabel"><?= cvcarts_h($detailOrder['reference_number']) ?></h2>
                        <p class="mb-0 small text-muted">Review the student's order, payment, and status history.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body order-detail-panel">
        <div class="summary-grid">
            <div><span>Student</span><strong><?= cvcarts_h($detailOrder['first_name'] . ' ' . $detailOrder['last_name']) ?><small class="detail-email"><?= cvcarts_h($detailOrder['email']) ?></small></strong></div>
            <div><span>Order status</span><strong><?= cvcarts_h(!$isAdmin && $ordersHistory && $detailOrder['order_status'] === 'Claimed' ? 'Already Received' : (!$isAdmin && $detailOrder['order_status'] === 'Payment Rejected' ? 'Rejected' : $detailOrder['order_status'])) ?></strong></div>
            <div><span>Payment status</span><strong><?= cvcarts_h($detailOrder['payment_status']) ?></strong></div>
            <div><span>Method / total</span><strong><?= cvcarts_h($detailOrder['payment_method']) ?> · <?= cvcarts_format_money((float)$detailOrder['total_amount']) ?></strong></div>
            <div><span>Date verified</span><strong><?= $detailOrder['verified_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($detailOrder['verified_at']))) : '—' ?></strong></div>
            <?php if ($detailOrder['order_status'] === 'Claimed'): ?><div><span>Date received</span><strong><?= $detailOrder['claim_date'] ? cvcarts_h(date('M j, Y g:i A', strtotime($detailOrder['claim_date']))) : '—' ?></strong></div><?php elseif ($detailOrder['order_status'] === 'Cancelled'): ?><div><span>Cancellation date</span><strong><?= $detailOrder['cancelled_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($detailOrder['cancelled_at']))) : '—' ?></strong></div><?php endif; ?>
            <?php if ($detailOrder['order_status'] === 'Cancelled' && $detailOrder['cancellation_reason']): ?><div><span>Cancellation reason</span><strong><?= cvcarts_h($detailOrder['cancellation_reason']) ?></strong></div><?php endif; ?>
        </div>

        <h3>Items</h3>
        <div class="table-wrapper"><table><thead><tr><th>Product</th><th>Size</th><th>Quantity</th><th>Unit price</th><th>Subtotal</th></tr></thead><tbody>
            <?php foreach ($detailItems as $item): ?>
                <tr><td><?= cvcarts_h($item['name']) ?></td><td><?= cvcarts_h($item['selected_size'] ?: '—') ?></td><td><?= (int)$item['quantity'] ?></td><td><?= cvcarts_format_money((float)$item['unit_price']) ?></td><td><?= cvcarts_format_money((float)$item['unit_price'] * (int)$item['quantity']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>

        <div class="summary-grid">
            <div><span>Transaction reference</span><strong><?= cvcarts_h($detailOrder['transaction_reference'] ?: $detailOrder['payment_reference'] ?: 'Not provided') ?></strong></div>
            <div><span>Payment proof</span><strong><?php if ($detailOrder['proof_path']): ?><a href="<?= cvcarts_h(cvcarts_url('/api/payments/proof.php?order_id=' . (int)$detailOrder['id'])) ?>">View proof</a><?php else: ?>Not provided<?php endif; ?></strong></div>
            <?php if ($detailOrder['rejection_reason']): ?><div><span>Rejection reason</span><strong><?= cvcarts_h($detailOrder['rejection_reason']) ?></strong></div><?php endif; ?>
        </div>

        <?php if (!$ordersHistory && $detailQrValue !== null): ?>
            <div class="order-qr">
                <h3>Order Claim QR Code</h3>
                <p><?= $detailOrder['order_status'] === 'Claimed' ? 'This order has already been received.' : 'Present this code to an Admin when collecting the order.' ?></p>
                <div class="qr-code" data-qr-value="<?= cvcarts_h($detailQrValue) ?>"></div>
            </div>
        <?php elseif (!$ordersHistory && !$isAdmin && $detailOrder['order_status'] === 'Cancelled'): ?>
            <div class="note-box">
                <div class="note-icon">!</div>
                <div><h3>Order cancelled</h3><p>This order cannot be claimed.</p></div>
            </div>
        <?php elseif (!$ordersHistory && !$isAdmin): ?>
            <div class="note-box">
                <div class="note-icon"><?= $detailOrder['payment_status'] === 'Paid' ? '!' : '⌛' ?></div>
                <div><h3><?= $detailOrder['payment_status'] === 'Paid' ? 'QR code unavailable' : 'Claiming QR code' ?></h3><p><?= $detailOrder['payment_status'] === 'Paid' ? 'A valid claiming QR code is unavailable. Please contact support before pickup.' : 'Your claiming QR code will appear here after Admin approves your payment.' ?></p></div>
            </div>
        <?php endif; ?>

        <?php if (!$isAdmin && $detailOrder['payment_status'] === 'Rejected' && $detailOrder['payment_method'] !== 'Cash' && $detailOrder['order_status'] === 'Payment Rejected'): ?>
            <section class="payment-resubmit">
                <h3>Resubmit payment proof</h3>
                <p>Upload updated proof and a transaction reference for Admin review.</p>
                <form method="post" enctype="multipart/form-data" action="<?= cvcarts_h(cvcarts_url($route . '?view=' . (int)$detailOrder['id'])) ?>">
                    <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>">
                    <input type="hidden" name="resubmit_payment" value="1">
                    <input type="hidden" name="order_id" value="<?= (int)$detailOrder['id'] ?>">
                    <div class="form-field"><label class="form-label" for="resubmit-reference">Transaction Reference</label><input class="form-control" id="resubmit-reference" name="transaction_reference" maxlength="150" required></div>
                    <div class="form-field"><label class="form-label" for="resubmit-proof">New Payment Proof</label><input class="form-control" id="resubmit-proof" type="file" name="payment_proof" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp,application/pdf" data-image-preview="#resubmit-proof-preview" data-image-preview-initially-hidden="true" required><img id="resubmit-proof-preview" class="form-image-preview" src="<?= cvcarts_h(cvcarts_url('/assets/images/logo.png')) ?>" alt="" hidden></div>
                    <button class="btn" type="submit">Resubmit for Review</button>
                </form>
            </section>
        <?php endif; ?>

        <div class="action-row">
            <?php if ($ordersHistory): ?>
                <?php if ($isAdmin): ?><a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= cvcarts_h(cvcarts_url('/admin/receipt.php?order_id=' . (int)$detailOrder['id'] . '&type=claiming')) ?>">View Receipt</a><?php endif; ?>
            <?php elseif ($detailQrValue !== null): ?>
                <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= cvcarts_h(cvcarts_url(($isAdmin ? '/admin' : '/student') . '/receipt.php?order_id=' . (int)$detailOrder['id'] . '&type=claiming')) ?>">View Receipt</a>
            <?php endif; ?>
        </div>

        <h3>Status history</h3>
        <?php if ($detailHistory): ?>
            <div class="table-wrapper"><table><thead><tr><th>Change</th><th>By</th><th>Reason</th><th>Date</th></tr></thead><tbody>
                <?php foreach ($detailHistory as $history): ?>
                    <tr><td><?= cvcarts_h(($history['old_status'] ?: 'Created') . ' → ' . $history['new_status']) ?></td><td><?= cvcarts_h(trim(($history['first_name'] ?? '') . ' ' . ($history['last_name'] ?? '')) ?: 'System') ?></td><td><?= cvcarts_h($history['reason'] ?: '—') ?></td><td><?= cvcarts_h($history['created_at']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="muted">No status changes recorded yet.</p><?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($viewId > 0): ?>
    <div class="flash-message error" role="status">The requested order could not be found.</div>
<?php endif; ?>

<section class="panel">
    <form class="filter-form" data-filter-form method="get" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
        <label>Search<span class="search-input-wrap"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" type="search" name="q" value="<?= cvcarts_h($search) ?>" placeholder="Search"></span></label>
        <label><?= $ordersHistory ? 'Final order status' : 'Order status' ?><select class="select-control" name="status"><option value="">All statuses</option>
            <?php foreach ($statusOptions as $status): ?>
                <option value="<?= cvcarts_h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= cvcarts_h($status) ?></option>
            <?php endforeach; ?>
        </select></label>
        <?php if (!$ordersHistory && !$ordersReady): ?>
            <label>Payment status<select class="select-control" name="payment_status"><option value="">All payment states</option><?php foreach (['Unpaid', 'Pending Verification', 'Paid', 'Rejected'] as $paymentStatus): ?><option value="<?= cvcarts_h($paymentStatus) ?>" <?= $paymentFilter === $paymentStatus ? 'selected' : '' ?>><?= cvcarts_h($paymentStatus) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <label>Payment method<select class="select-control" name="payment_method"><option value="">All methods</option><?php foreach (['Cash', 'GCash', 'Maya', 'Bank Transfer'] as $method): ?><option value="<?= cvcarts_h($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>><?= cvcarts_h($method) ?></option><?php endforeach; ?></select></label>
            <?php if ($ordersReady): ?>
                <label>Verification date<input class="form-control" type="date" name="verified_date" value="<?= cvcarts_h($verifiedDate) ?>"></label>
            <?php elseif ($ordersHistory): ?>
                <label>Order date<input class="form-control" type="date" name="order_date" value="<?= cvcarts_h($orderDate) ?>"></label>
                <label>Received date<input class="form-control" type="date" name="received_date" value="<?= cvcarts_h($receivedDate) ?>"></label>
            <?php else: ?>
                <label>Order date<input class="form-control" type="date" name="order_date" value="<?= cvcarts_h($orderDate) ?>"></label>
            <?php endif; ?>
        <?php elseif ($ordersHistory): ?>
            <label>Order date from<input class="form-control" type="date" name="order_date_from" value="<?= cvcarts_h($orderDateFrom) ?>"></label>
            <label>Order date to<input class="form-control" type="date" name="order_date_to" value="<?= cvcarts_h($orderDateTo) ?>"></label>
        <?php endif; ?>
        <label>Sort<select class="select-control" name="sort">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        </select></label>
        <div class="filter-actions">
            <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
            <a class="btn btn-secondary" data-filter-reset href="<?= cvcarts_h(cvcarts_url($route)) ?>">Reset Filters</a>
        </div>
    </form>

    <div class="table-wrapper"><table class="orders-table">
        <?php if ($isAdmin && $ordersReady): ?>
            <thead><tr><th>Order Number</th><th>Student Name</th><th>Student Email</th><th>Ordered Items</th><th class="currency-cell">Total Amount</th><th>Payment Method</th><th class="status-cell">Payment Status</th><th class="status-cell">Order Status</th><th>Date Verified</th><th>QR Code Status</th><th class="action-cell">Action</th></tr></thead>
        <?php elseif ($ordersHistory): ?>
            <thead><tr><th>Order Number</th><?php if ($isAdmin): ?><th>Student</th><?php endif; ?><th>Ordered Items</th><th>Payment Method</th><th class="currency-cell">Total Amount</th><th class="status-cell">Payment Status</th><th class="status-cell">Final Order Status</th><th>Order Date</th><th><?= $isAdmin ? 'Date Received / Cancelled' : 'Date Received / Cancelled / Rejected' ?></th><?php if ($isAdmin): ?><th class="action-cell">Actions</th><?php endif; ?></tr></thead>
        <?php else: ?>
            <thead><tr><th>Order</th><?php if ($isAdmin): ?><th>Student</th><?php endif; ?><th>Method</th><th class="currency-cell">Total</th><th class="status-cell">Payment</th><th class="status-cell">Status</th><th>Order Date</th><th class="action-cell"><?= $isAdmin ? 'Details' : 'Details / Actions' ?></th><?php if ($isAdmin): ?><th class="action-cell">Actions</th><?php endif; ?></tr></thead>
        <?php endif; ?>
        <tbody>
        <?php
        $hasAdminActions = $isAdmin && !$ordersHistory && !$ordersReady;
        $columnCount = $isAdmin && $ordersReady ? 11 : ($ordersHistory ? 8 + ($isAdmin ? 2 : 0) : (7 + ($isAdmin ? 1 : 0) + ($hasAdminActions ? 1 : 0)));
        if (!$orders): ?><tr><td colspan="<?= $columnCount ?>" class="empty-state"><?= $ordersReady ? 'No paid and verified orders are waiting to be claimed.' : ($ordersHistory ? ($isAdmin ? 'No completed or cancelled orders match these filters.' : 'No rejected, completed, or cancelled orders match these filters.') : 'No orders found.') ?></td></tr><?php endif; ?>
        <?php foreach ($orders as $order): ?>
            <?php
            $transitions = $availableStatuses($order);
            $displayOrderStatus = !$isAdmin && $ordersHistory && $order['order_status'] === 'Claimed'
                ? 'Already Received'
                : (!$isAdmin && $order['order_status'] === 'Payment Rejected' ? 'Rejected' : (string)$order['order_status']);
            ?>
            <tr>
                <td><strong><?= cvcarts_h($order['reference_number']) ?></strong></td>
                <?php if ($isAdmin && $ordersReady): ?><td><?= cvcarts_h($order['first_name'] . ' ' . $order['last_name']) ?></td><td><?= cvcarts_h($order['email']) ?></td>
                <?php elseif ($isAdmin): ?><td><?= cvcarts_h($order['first_name'] . ' ' . $order['last_name']) ?><br><small><?= cvcarts_h($order['email']) ?></small></td><?php endif; ?>
                <?php if ($ordersReady || $ordersHistory): ?><td><?= cvcarts_h($order['ordered_items'] ?: 'No items recorded') ?></td><?php endif; ?>
                <?php if (!$ordersReady && !$ordersHistory): ?>
                    <td><?= cvcarts_h($order['payment_method']) ?></td>
                    <td class="currency-cell"><?= cvcarts_format_money((float)$order['total_amount']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge((string)$order['payment_status']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge((string)$order['order_status']) ?></td>
                    <td><?= cvcarts_h(date('M j, Y', strtotime($order['created_at']))) ?></td>
                    <td class="action-cell">
                        <?php if (!$isAdmin): ?>
                            <?php if ($order['order_status'] === 'Payment Rejected'): ?>
                                <a class="btn btn-small btn-secondary" href="<?= cvcarts_h(cvcarts_url($route . '?view=' . (int)$order['id'])) ?>">Resubmit Payment</a>
                            <?php else: ?>
                                <a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= cvcarts_h(cvcarts_url('/student/receipt.php?order_id=' . (int)$order['id'] . '&type=claiming')) ?>">View Receipt</a>
                            <?php endif; ?>
                            <?php if (in_array('Cancelled', $transitions, true)): ?>
                                <form method="post" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>">
                                    <input type="hidden" name="cancel_order" value="1">
                                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                    <button class="btn btn-small btn-danger" type="submit" data-confirm="Cancel unpaid order <?= cvcarts_h($order['reference_number']) ?>? Its inventory will be returned to stock.">Cancel Order</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-small btn-secondary" href="<?= cvcarts_h(cvcarts_url($route . '?view=' . (int)$order['id'])) ?>">View Details</a>
                        <?php endif; ?>
                    </td>
                <?php elseif ($isAdmin && $ordersReady): ?>
                    <td class="currency-cell"><?= cvcarts_format_money((float)$order['total_amount']) ?></td>
                    <td><?= cvcarts_h($order['payment_method']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge((string)$order['payment_status']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge((string)$order['order_status']) ?></td>
                    <td><?= $order['verified_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($order['verified_at']))) : '—' ?></td>
                    <td><?= !empty($order['qr_token_hash']) ? 'Available' : 'Unavailable' ?></td>
                    <td class="action-cell"><a class="btn btn-small btn-secondary" href="<?= cvcarts_h(cvcarts_url($route . '?view=' . (int)$order['id'])) ?>">View Details</a></td>
                <?php elseif ($ordersHistory): ?>
                    <td><?= cvcarts_h($order['payment_method']) ?></td>
                    <td class="currency-cell"><?= cvcarts_format_money((float)$order['total_amount']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge((string)$order['payment_status']) ?></td>
                    <td class="status-cell"><?= cvcarts_status_badge($displayOrderStatus) ?></td>
                    <td><?= cvcarts_h(date('M j, Y', strtotime($order['created_at']))) ?></td>
                    <td><?= $order['order_status'] === 'Claimed' ? ($order['claim_date'] ? cvcarts_h(date('M j, Y g:i A', strtotime($order['claim_date']))) : '—') : ($order['order_status'] === 'Cancelled' ? ($order['cancelled_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($order['cancelled_at']))) : '—') : cvcarts_h(date('M j, Y g:i A', strtotime($order['updated_at'])))) ?></td>
                    <?php if ($isAdmin): ?>
                        <td class="action-cell">
                            <a class="btn btn-small btn-secondary" href="<?= cvcarts_h(cvcarts_url($route . '?view=' . (int)$order['id'])) ?>">View Details</a>
                            <a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= cvcarts_h(cvcarts_url('/admin/receipt.php?order_id=' . (int)$order['id'] . '&type=claiming')) ?>">View Receipt</a>
                        </td>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($hasAdminActions): ?>
                    <td class="order-actions">
                        <?php if ($order['latest_payment_status'] === 'Pending Verification' && $order['payment_status'] === 'Pending Verification' && $order['order_status'] === 'Payment Verification' && $order['payment_method'] !== 'Cash'): ?>
                            <?php if ($order['transaction_reference']): ?><small>Ref: <?= cvcarts_h($order['transaction_reference']) ?></small><?php endif; ?>
                            <?php if ($order['proof_path']): ?><a href="<?= cvcarts_h(cvcarts_url('/api/payments/proof.php?order_id=' . (int)$order['id'])) ?>">View proof</a><?php endif; ?>
                            <?php if ($order['transaction_reference'] && $order['proof_path']): ?>
                                <form method="post" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="return_view" value="0"><input type="hidden" name="payment_decision" value="approve">
                                    <button class="btn btn-small" type="submit" data-confirm="Approve payment for order <?= cvcarts_h($order['reference_number']) ?> after reviewing the transaction reference and uploaded proof? The order will move to Ready to Claim." data-confirm-type="success">Verify Payment</button>
                                </form>
                            <?php else: ?><small class="status-note">Cannot approve until transaction reference and uploaded proof are available.</small><?php endif; ?>
                            <button class="btn btn-small btn-danger" type="button" data-modal-open="reject-payment-<?= (int)$order['id'] ?>">Reject Payment</button>
                            <dialog id="reject-payment-<?= (int)$order['id'] ?>" class="app-modal confirm-dialog" aria-labelledby="reject-payment-title-<?= (int)$order['id'] ?>">
                                <div class="modal-shell">
                                    <header class="modal-header"><div><h2 id="reject-payment-title-<?= (int)$order['id'] ?>">Reject Payment</h2><p>Order <?= cvcarts_h($order['reference_number']) ?> · Add a reason for the student.</p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                                    <form method="post" action="<?= cvcarts_h(cvcarts_url($route)) ?>" data-modal-form>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="return_view" value="0"><input type="hidden" name="payment_decision" value="reject">
                                            <div class="form-field"><label class="form-label" for="rejection-reason-<?= (int)$order['id'] ?>">Rejection reason</label><textarea class="form-control" id="rejection-reason-<?= (int)$order['id'] ?>" name="rejection_reason" maxlength="500" rows="4" required></textarea></div>
                                        </div>
                                        <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-small btn-danger" type="submit" data-confirm="Reject payment for order <?= cvcarts_h($order['reference_number']) ?> and send this reason to the student?" data-confirm-title="Reject payment?" data-confirm-type="error">Reject Payment</button></footer>
                                    </form>
                                </div>
                            </dialog>
                        <?php elseif ($order['payment_method'] === 'Cash' && $order['latest_payment_status'] === 'Unpaid' && $order['payment_status'] === 'Unpaid' && $order['order_status'] === 'Pending'): ?>
                            <form method="post" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                                <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="return_view" value="0"><input type="hidden" name="payment_decision" value="collect_cash">
                                <button class="btn btn-small" type="submit" data-confirm="Confirm the cash payment for order <?= cvcarts_h($order['reference_number']) ?>? The order will move to Ready to Claim." data-confirm-type="success">Approve Cash</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($transitions): ?>
                            <form method="post" class="order-status-form" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                                <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="return_view" value="0">
                                <input class="form-control" name="status_reason" maxlength="500" placeholder="<?= in_array('Cancelled', $transitions, true) ? 'Cancellation reason' : 'Optional note' ?>" <?= in_array('Cancelled', $transitions, true) ? 'required' : '' ?>>
                                <div class="order-status-buttons">
                                    <?php foreach ($transitions as $transition): ?>
                                        <?php $label = $transition === 'Cancelled' ? ($order['payment_status'] === 'Rejected' ? 'Cancel Rejected Order' : 'Cancel Order') : $transition; ?>
                                        <?php $confirmText = $transition === 'Cancelled'
                                            ? 'Cancel order ' . $order['reference_number'] . '? Ordered stock will be restored to inventory.'
                                            : $label . ' for order ' . $order['reference_number'] . '?'; ?>
                                        <button class="btn btn-small btn-danger" type="submit" name="new_status" value="<?= cvcarts_h($transition) ?>" data-confirm="<?= cvcarts_h($confirmText) ?>" data-confirm-type="<?= $transition === 'Cancelled' ? 'error' : 'warning' ?>"><?= cvcarts_h($label) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <?php
    $orderParams = [
        'q' => $search,
        'status' => $statusFilter,
        'payment_status' => $paymentFilter,
        'payment_method' => $methodFilter,
        'order_date' => $orderDate,
        'order_date_from' => $orderDateFrom,
        'order_date_to' => $orderDateTo,
        'verified_date' => $verifiedDate,
        'received_date' => $receivedDate,
        'sort' => $sort,
        'per_page' => $pageSize,
    ];
    ?>
    <nav class="pagination" aria-label="Order pages">
        <span>Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($totalOrders) ?> orders</span>
        <div class="pagination-pages">
            <?php if ($page > 1): ?><a class="btn btn-small" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($orderParams + ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
            <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?><a class="btn btn-small <?= $pageNumber === $page ? 'active' : '' ?>" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($orderParams + ['page' => $pageNumber]))) ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a><?php endfor; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-small" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($orderParams + ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
        </div>
    </nav>
</section>

<?php if ($detailQrValue !== null): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        function renderOrderQrCodes() {
            document.querySelectorAll('.qr-code[data-qr-value]:empty').forEach(function (element) {
                new QRCode(element, { text: element.getAttribute('data-qr-value'), width: 168, height: 168 });
            });
        }
        renderOrderQrCodes();
        document.addEventListener('cvcarts:content-updated', renderOrderQrCodes);
    </script>
<?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/modal.js')) ?>"></script>
    <script src="<?= cvcarts_h(cvcarts_url('/assets/js/image-preview.js?v=' . filemtime(__DIR__ . '/../../assets/js/image-preview.js'))) ?>" defer></script>
    <?php if ($detailOrder): ?>
    <script>
        function initializeOrderDetailsModal() {
            const modalElement = document.getElementById('orderDetailsModal');
            if (modalElement && window.bootstrap) {
                if (!bootstrap.Modal.getInstance(modalElement)) {
                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
                if (new URL(window.location.href).searchParams.get('show_qr') === '1') {
                    window.setTimeout(function () {
                        const qrCode = modalElement.querySelector('.order-qr');
                        if (qrCode) qrCode.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }, 250);
                }
                if (modalElement.dataset.cleanupBound !== 'true') {
                    modalElement.dataset.cleanupBound = 'true';
                    modalElement.addEventListener('hidden.bs.modal', function () {
                        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                            backdrop.remove();
                        });
                        document.body.classList.remove('modal-open');
                        document.body.style.removeProperty('overflow');
                        document.body.style.removeProperty('padding-right');
                        document.body.style.removeProperty('margin-right');

                        const url = new URL(window.location.href);
                        url.searchParams.delete('view');
                        window.history.replaceState({}, '', url.toString());
                    });
                }
            }
        }
        initializeOrderDetailsModal();
        document.addEventListener('cvcarts:content-updated', initializeOrderDetailsModal);
    </script>
    <?php endif; ?>
</body>
</html>
