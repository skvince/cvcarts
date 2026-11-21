<?php
require __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('student');
$db = cvcarts_db();
$user = cvcarts_user();
$checkoutOldInput = is_array($_SESSION['checkout_old_input'] ?? null) ? $_SESSION['checkout_old_input'] : [];
$paymentMethods = ['Cash', 'GCash', 'Maya', 'Bank Transfer'];
$selectedPaymentMethod = $checkoutOldInput['payment_method'] ?? 'Cash';
if (!in_array($selectedPaymentMethod, $paymentMethods, true)) {
    $selectedPaymentMethod = 'Cash';
}
unset($_SESSION['checkout_old_input']);
$rememberCheckoutInput = static function (): void {
    $methods = ['Cash', 'GCash', 'Maya', 'Bank Transfer'];
    $method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
    $reference = $method !== 'Cash' && is_string($_POST['transaction_reference'] ?? null)
        ? trim($_POST['transaction_reference'])
        : '';
    $_SESSION['checkout_old_input'] = [
        'payment_method' => in_array($method, $methods, true) ? $method : 'Cash',
        'transaction_reference' => substr($reference, 0, 150),
    ];
};

$isBuyNow = (($_GET['buy_now'] ?? '') === '1')
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['buy_now_checkout'] ?? '') === '1');
$selectedCartItemIds = [];
if ($isBuyNow) {
    unset($_SESSION['checkout_cart_item_ids']);
    $buyNow = $_SESSION['buy_now'] ?? null;
    if (!is_array($buyNow) || (int)($_SESSION['buy_now_expires_at'] ?? 0) < time()) {
        unset($_SESSION['buy_now'], $_SESSION['buy_now_expires_at']);
        cvcarts_flash('error', 'Your Buy Now session expired. Please select the product again.');
        cvcarts_redirect(cvcarts_url('/student/shop/index.php'));
    }
    $directProduct = $db->prepare('SELECT p.id AS product_id, p.name, p.price, p.stock, p.has_sizes, p.is_active, (SELECT ps.stock FROM product_sizes ps WHERE ps.product_id = p.id AND ps.size = :size AND ps.is_active = 1 LIMIT 1) AS selected_size_stock FROM products p WHERE p.id = :id AND p.is_active = 1 LIMIT 1');
    $directProduct->execute(['id' => (int)$buyNow['product_id'], 'size' => (string)($buyNow['size'] ?? '')]);
    $product = $directProduct->fetch(PDO::FETCH_ASSOC);
    $items = $product ? [[
        'id' => 0,
        'product_id' => (int)$product['product_id'],
        'name' => $product['name'],
        'price' => $product['price'],
        'stock' => $product['stock'],
        'has_sizes' => $product['has_sizes'],
        'is_active' => $product['is_active'],
        'selected_size_stock' => $product['selected_size_stock'],
        'selected_size' => (string)($buyNow['size'] ?? ''),
        'quantity' => (int)($buyNow['quantity'] ?? 0),
    ]] : [];
} else {
    $storedIds = $_SESSION['checkout_cart_item_ids'] ?? null;
    if (!is_array($storedIds) || !$storedIds) {
        unset($_SESSION['checkout_cart_item_ids']);
        cvcarts_flash('error', 'Select the cart items you want to check out.');
        cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
    }

    foreach ($storedIds as $storedId) {
        $validatedId = filter_var($storedId, FILTER_VALIDATE_INT);
        if ($validatedId === false || $validatedId < 1) {
            unset($_SESSION['checkout_cart_item_ids']);
            cvcarts_flash('error', 'Your selected cart items are invalid. Please select them again.');
            cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
        }
        $selectedCartItemIds[] = (int)$validatedId;
    }
    $selectedCartItemIds = array_values(array_unique($selectedCartItemIds));
    if (!$selectedCartItemIds) {
        unset($_SESSION['checkout_cart_item_ids']);
        cvcarts_flash('error', 'Select the cart items you want to check out.');
        cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
    }

    $placeholders = [];
    $params = ['user_id' => (int)$user['id']];
    foreach ($selectedCartItemIds as $index => $selectedId) {
        $key = 'item_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $selectedId;
    }
    $stmt = $db->prepare(
        'SELECT ci.*, p.name, p.price, p.stock, p.has_sizes, p.is_active, (SELECT ps.stock FROM product_sizes ps WHERE ps.product_id = p.id AND ps.size = ci.selected_size AND ps.is_active = 1 LIMIT 1) AS selected_size_stock FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.user_id = :user_id AND ci.id IN (' . implode(', ', $placeholders) . ') ORDER BY ci.created_at DESC'
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($items) !== count($selectedCartItemIds)) {
        unset($_SESSION['checkout_cart_item_ids']);
        cvcarts_flash('error', 'One or more selected items changed in your cart. Please select your items again.');
        cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
    }
}
$checkoutUrl = cvcarts_url('/student/checkout/index.php' . ($isBuyNow ? '?buy_now=1' : ''));
$total = 0.0;
$unavailableItems = [];
foreach ($items as $item) {
    $total += (float)$item['price'] * (int)$item['quantity'];
    $availableStock = (int)$item['stock'];
    if ((int)$item['has_sizes'] === 1) {
        $availableStock = $item['selected_size_stock'] === null ? 0 : (int)$item['selected_size_stock'];
    }
    if ((int)$item['is_active'] !== 1 || $availableStock < (int)$item['quantity']) {
        $unavailableItems[] = $item['name'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_checkout'])) {
    cvcarts_require_csrf();
    if (!$items) {
        cvcarts_flash('error', 'Your cart is empty.');
        cvcarts_redirect($isBuyNow ? cvcarts_url('/student/shop/index.php') : cvcarts_url('/student/cart/index.php'));
    }
    $paymentMethod = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
    $paymentReference = is_string($_POST['transaction_reference'] ?? null) ? trim($_POST['transaction_reference']) : '';
    $validPaymentMethods = ['Cash', 'GCash', 'Maya', 'Bank Transfer'];
    if (!in_array($paymentMethod, $validPaymentMethods, true)) {
        $rememberCheckoutInput();
        cvcarts_flash('error', 'Choose a supported payment method.');
        cvcarts_redirect($checkoutUrl);
    }
    $isCash = strtolower($paymentMethod) === 'cash';
    if ($isCash) {
        $paymentReference = '';
    }
    $proofFileName = null;
    $proofTarget = null;
    if (!$isCash) {
        $upload = $_FILES['payment_proof'] ?? null;
        if ($paymentReference === '' || strlen($paymentReference) > 150 || !is_array($upload)) {
            $rememberCheckoutInput();
            cvcarts_flash('error', 'Online payments require a transaction reference and payment proof.');
            cvcarts_redirect($checkoutUrl);
        }
        try {
            $proofFileName = cvcarts_store_payment_proof($upload);
        } catch (RuntimeException $e) {
            $rememberCheckoutInput();
            cvcarts_flash('error', $e->getMessage());
            cvcarts_redirect($checkoutUrl);
        }
        $proofTarget = __DIR__ . '/../../uploads/payment-proofs/' . $proofFileName;
    }

    $orderReference = cvcarts_order_reference();
    $paymentStatus = $isCash ? 'Unpaid' : 'Pending Verification';
    $orderStatus = $isCash ? 'Pending' : 'Payment Verification';

    $db->beginTransaction();
    try {
        $lockedPrices = [];
        $lockedSizes = [];
        $checkoutTotal = 0.0;
        foreach ($items as $item) {
            if ((int)$item['quantity'] < 1) {
                throw new RuntimeException('Cart quantities must be at least one.');
            }
            $product = $db->prepare('SELECT stock, price, is_active, has_sizes FROM products WHERE id = :id FOR UPDATE');
            $product->execute(['id' => $item['product_id']]);
            $lockedProduct = $product->fetch(PDO::FETCH_ASSOC);
            if (!$lockedProduct || (int)$lockedProduct['is_active'] !== 1) {
                throw new RuntimeException('One or more products are no longer available.');
            }
            if ((int)$lockedProduct['has_sizes'] === 1) {
                $sizeQuery = $db->prepare('SELECT id, stock FROM product_sizes WHERE product_id = :product_id AND size = :size AND is_active = 1 FOR UPDATE');
                $sizeQuery->execute(['product_id' => $item['product_id'], 'size' => $item['selected_size']]);
                $sizeRow = $sizeQuery->fetch(PDO::FETCH_ASSOC);
                if (!$sizeRow || (int)$sizeRow['stock'] < (int)$item['quantity']) {
                    throw new RuntimeException('A selected size is no longer available in the requested quantity.');
                }
                $lockedSizes[] = (string)$item['selected_size'];
                $db->prepare('UPDATE product_sizes SET stock = stock - :quantity WHERE id = :id')
                    ->execute(['quantity' => $item['quantity'], 'id' => $sizeRow['id']]);
            } elseif ((int)$lockedProduct['stock'] < (int)$item['quantity']) {
                throw new RuntimeException('One or more cart quantities are no longer available.');
            } else {
                $lockedSizes[] = '';
            }
            $lockedPrices[] = (float)$lockedProduct['price'];
            $checkoutTotal += (float)$lockedProduct['price'] * (int)$item['quantity'];
        }
        $total = $checkoutTotal;
        $insert = $db->prepare('INSERT INTO orders (user_id, reference_number, total_amount, payment_method, payment_status, order_status, payment_reference, payment_proof_path) VALUES (:user_id, :reference_number, :total_amount, :payment_method, :payment_status, :order_status, :payment_reference, :payment_proof_path)');
        $insert->execute([
            'user_id' => $user['id'], 'reference_number' => $orderReference, 'total_amount' => $total,
            'payment_method' => $paymentMethod, 'payment_status' => $paymentStatus, 'order_status' => $orderStatus,
            'payment_reference' => $paymentReference ?: null, 'payment_proof_path' => $proofFileName,
        ]);
        $orderId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO payments (order_id, payment_method, amount, status, transaction_reference, proof_path, submitted_at) VALUES (:order_id, :method, :amount, :status, :reference, :proof_path, NOW())')
            ->execute([
                'order_id' => $orderId,
                'method' => $paymentMethod,
                'amount' => $total,
                'status' => $paymentStatus,
                'reference' => $paymentReference !== '' ? $paymentReference : null,
                'proof_path' => $proofFileName,
            ]);
        $db->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by_user_id, reason) VALUES (:order_id, NULL, :new_status, :user_id, :reason)')
            ->execute([
                'order_id' => $orderId, 'new_status' => $orderStatus, 'user_id' => $user['id'],
                'reason' => 'Order placed by student.',
            ]);
        foreach ($items as $index => $item) {
            $db->prepare('INSERT INTO order_items (order_id, product_id, quantity, selected_size, unit_price) VALUES (:order_id, :product_id, :quantity, :selected_size, :unit_price)')->execute([
                'order_id' => $orderId, 'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
                'selected_size' => $lockedSizes[$index] !== '' ? $lockedSizes[$index] : null, 'unit_price' => $lockedPrices[$index],
            ]);
            if ((int)$item['has_sizes'] === 1) {
                $db->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = :size_product_id AND is_active = 1) WHERE id = :product_id')
                    ->execute(['size_product_id' => $item['product_id'], 'product_id' => $item['product_id']]);
            } else {
                $db->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id')
                    ->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
            }
            cvcarts_record_inventory_movement($db, (int)$item['product_id'], $lockedSizes[$index] ?: null, $orderId, null, -(int)$item['quantity'], 'order_placed');
        }
        if (!$isBuyNow) {
            $deleteParams = ['user_id' => (int)$user['id']];
            $deletePlaceholders = [];
            foreach ($selectedCartItemIds as $index => $selectedId) {
                $key = 'item_' . $index;
                $deletePlaceholders[] = ':' . $key;
                $deleteParams[$key] = $selectedId;
            }
            $db->prepare(
                'DELETE FROM cart_items WHERE user_id = :user_id AND id IN (' . implode(', ', $deletePlaceholders) . ')'
            )->execute($deleteParams);
            cvcarts_emit_realtime_event($db, 'cart.updated', 'student', (int)$user['id']);
            unset($_SESSION['checkout_cart_item_ids']);
        }
        $db->prepare('INSERT INTO notifications (user_id, title, message, related_order_id) VALUES (:user_id, :title, :message, :order_id)')->execute([
            'user_id' => $user['id'], 'title' => 'Order placed', 'message' => 'Your order ' . $orderReference . ' has been created.', 'order_id' => $orderId,
        ]);
        cvcarts_emit_realtime_event($db, 'order.created', 'admin');
        cvcarts_emit_realtime_event($db, 'order.updated', 'student', (int)$user['id']);
        cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
        $db->commit();
        if ($isBuyNow) {
            unset($_SESSION['buy_now'], $_SESSION['buy_now_expires_at']);
        }
        cvcarts_flash('success', 'Checkout successful. Order reference: ' . $orderReference . '.');
        cvcarts_redirect(cvcarts_url('/student/receipt.php?order_id=' . $orderId . '&type=claiming'));
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($proofTarget !== null && is_file($proofTarget)) {
            unlink($proofTarget);
        }
        $rememberCheckoutInput();
        cvcarts_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Checkout could not be completed. Please try again.');
        cvcarts_redirect($checkoutUrl);
    }
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Checkout</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/student/checkout.css')); ?>">
</head>
<body>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a class="header-brand" href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions"><a class="header-icon" href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a><?php echo cvcarts_student_profile_menu($user); ?><a class="header-logout" href="<?php echo cvcarts_h(cvcarts_url('/auth/logout.php')); ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a></div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>" class="active" aria-current="page"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-history/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/settings/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>
        <main class="main-content">
            <div class="page-heading"><div><h1>Checkout</h1><p><?php echo $isBuyNow ? 'Complete your selected purchase.' : 'Confirm your order and payment method.'; ?></p></div><a href="<?php echo cvcarts_h($isBuyNow ? cvcarts_url('/student/shop/index.php') : cvcarts_url('/student/cart/index.php')); ?>" class="btn"><?php echo $isBuyNow ? 'Back to Shop' : 'Back to Cart'; ?></a></div>
            <?php foreach ($flashes as $flash): ?><div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Please Note' : 'Order Placed'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>
            <?php if (!$items): ?>
                <section class="panel"><p><?php echo $isBuyNow ? 'This product is no longer available.' : 'Your cart is empty.'; ?></p><a class="btn" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>">Visit Shop</a></section>
            <?php else: ?>
                <section class="dashboard-grid">
                    <article class="panel wide-panel">
                        <div class="panel-heading"><h2>Order Summary</h2></div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>Product</th><th>Size</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $availableStock = (int)$item['stock'];
                                        if ((int)$item['has_sizes'] === 1) {
                                            $availableStock = $item['selected_size_stock'] === null ? 0 : (int)$item['selected_size_stock'];
                                        }
                                        $itemUnavailable = (int)$item['is_active'] !== 1 || $availableStock < (int)$item['quantity'];
                                        ?>
                                        <tr><td><?php echo cvcarts_h($item['name']); ?><?php if ($itemUnavailable): ?><br><small class="status status-unavailable">Not enough stock to check out</small><?php endif; ?></td><td><?php echo cvcarts_h($item['selected_size'] ?: '—'); ?></td><td><?php echo (int)$item['quantity']; ?></td><td><?php echo cvcarts_format_money((float)$item['price']); ?></td><td><?php echo cvcarts_format_money((float)$item['price'] * (int)$item['quantity']); ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="history-toolbar"><div class="page-date">Total: <strong><?php echo cvcarts_format_money($total); ?></strong></div></div>
                        <?php if ($unavailableItems): ?><div class="flash-message error" role="status">Some items are out of stock or unavailable. Return to your cart to adjust quantities before checkout.</div><?php endif; ?>
                    </article>
                    <article class="panel">
                        <div class="panel-heading"><h2>Payment Details</h2></div>
                        <form method="post" enctype="multipart/form-data" action="<?php echo cvcarts_h($checkoutUrl); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                            <input type="hidden" name="confirm_checkout" value="1">
                            <?php if ($isBuyNow): ?><input type="hidden" name="buy_now_checkout" value="1"><?php endif; ?>
                            <div class="form-field"><label class="form-label" for="payment-method">Payment Method</label><select class="select-control" id="payment-method" name="payment_method"><?php foreach ($paymentMethods as $method): ?><option value="<?php echo cvcarts_h($method); ?>" <?php echo $selectedPaymentMethod === $method ? 'selected' : ''; ?>><?php echo cvcarts_h($method); ?></option><?php endforeach; ?></select></div>
                            <div class="note-box" id="online-payment-note" <?php echo $selectedPaymentMethod === 'Cash' ? 'hidden' : ''; ?>><div class="note-icon">!</div><div><h3>Manual payment verification</h3><p>Complete payment using the school's official instructions, then provide the transaction reference and proof. An Admin must review it; this system does not confirm payments automatically.</p></div></div>
                            <div class="checkout-conditional-fields" id="online-payment-fields" <?php echo $selectedPaymentMethod === 'Cash' ? 'hidden' : ''; ?>>
                                <div class="form-field"><label class="form-label" for="transaction-reference">Transaction Reference</label><input class="form-control" id="transaction-reference" type="text" name="transaction_reference" maxlength="150" value="<?php echo cvcarts_h((string)($checkoutOldInput['transaction_reference'] ?? '')); ?>" placeholder="Reference number (online payment)" <?php echo $selectedPaymentMethod === 'Cash' ? 'disabled' : 'required'; ?>></div>
                                <div class="form-field"><label class="form-label" for="payment-proof">Payment Proof</label><input class="form-control" id="payment-proof" type="file" name="payment_proof" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp,application/pdf" data-image-preview="#payment-proof-preview" data-image-preview-initially-hidden="true" <?php echo $selectedPaymentMethod === 'Cash' ? 'disabled' : 'required'; ?>><img id="payment-proof-preview" class="form-image-preview" src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="" hidden><p class="form-help">Online payments must be reviewed by Admin. Upload a JPG, JPEG, PNG, WEBP image or PDF up to 5 MB.</p></div>
                            </div>
                            <div class="note-box"><div class="note-icon">₱</div><div><h3>Payment status</h3><p>Cash orders remain unpaid until Admin confirms payment. Online payments require verification. After approval, your receipt includes a QR code to present when collecting your order.</p></div></div>
                            <button type="submit" class="btn checkout-submit" data-confirm="Place this order for <?php echo cvcarts_h(cvcarts_format_money($total)); ?>? Verify your items and payment details before confirming." data-confirm-title="Place your order?" data-confirm-label="Place Order" <?php echo $unavailableItems ? 'disabled' : ''; ?>>Place Order</button>
                        </form>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>
<script>
    (function () {
        const paymentMethod = document.getElementById('payment-method');
        const paymentReference = document.getElementById('transaction-reference');
        const paymentProof = document.getElementById('payment-proof');
        const onlinePaymentFields = document.getElementById('online-payment-fields');
        const onlinePaymentNote = document.getElementById('online-payment-note');
        if (!paymentMethod || !paymentReference || !paymentProof || !onlinePaymentFields || !onlinePaymentNote) return;

        function updatePaymentRequirements() {
            const online = paymentMethod.value !== 'Cash';
            paymentReference.required = online;
            paymentProof.required = online;
            paymentReference.disabled = !online;
            paymentProof.disabled = !online;
            onlinePaymentFields.hidden = !online;
            onlinePaymentNote.hidden = !online;

            if (!online) {
                paymentReference.value = '';
                paymentProof.value = '';
            }
        }

        paymentMethod.addEventListener('change', updatePaymentRequirements);
        updatePaymentRequirements();
    }());
</script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js')); ?>"></script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/image-preview.js?v=' . filemtime(__DIR__ . '/../../assets/js/image-preview.js'))); ?>" defer></script>
</body>
</html>
