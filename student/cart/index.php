<?php
require __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('student');
$db = cvcarts_db();
$user = cvcarts_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $itemId = (int)($_POST['item_id'] ?? 0);
    if (isset($_POST['start_checkout'])) {
        $requestedIds = $_POST['item_ids'] ?? null;
        if (!is_array($requestedIds) || !$requestedIds) {
            cvcarts_flash('error', 'Select at least one available item to check out.');
            cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
        }

        $selectedIds = [];
        foreach ($requestedIds as $requestedId) {
            $validatedId = filter_var($requestedId, FILTER_VALIDATE_INT);
            if ($validatedId === false || $validatedId < 1) {
                cvcarts_flash('error', 'One or more selected cart items are invalid. Please try again.');
                cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
            }
            $selectedIds[] = (int)$validatedId;
        }
        $selectedIds = array_values(array_unique($selectedIds));

        $placeholders = [];
        $params = ['user_id' => (int)$user['id']];
        foreach ($selectedIds as $index => $selectedId) {
            $key = 'item_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $selectedId;
        }
        $checkSelected = $db->prepare(
            'SELECT id FROM cart_items WHERE user_id = :user_id AND id IN (' . implode(', ', $placeholders) . ')'
        );
        $checkSelected->execute($params);
        $ownedIds = array_map('intval', $checkSelected->fetchAll(PDO::FETCH_COLUMN));
        if (count($ownedIds) !== count($selectedIds)) {
            cvcarts_flash('error', 'One or more selected items are no longer in your cart. Refresh and try again.');
            cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
        }

        $_SESSION['checkout_cart_item_ids'] = $selectedIds;
        cvcarts_redirect(cvcarts_url('/student/checkout/index.php'));
    }
    if (isset($_POST['remove_item'])) {
        $remove = $db->prepare('DELETE FROM cart_items WHERE id = :id AND user_id = :user_id');
        $remove->execute(['id' => $itemId, 'user_id' => $user['id']]);
        if ($remove->rowCount() === 1) {
            cvcarts_emit_realtime_event($db, 'cart.updated', 'student', (int)$user['id']);
        }
        cvcarts_flash($remove->rowCount() === 1 ? 'success' : 'error', $remove->rowCount() === 1
            ? 'Item removed from cart.'
            : 'This cart item is no longer available. Refresh your cart and try again.');
        cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
    }
    if (isset($_POST['update_item'])) {
        $quantity = filter_var(is_string($_POST['quantity'] ?? null) ? $_POST['quantity'] : '', FILTER_VALIDATE_INT);
        $sizeInput = $_POST['selected_size'] ?? '';
        $selectedSize = is_string($sizeInput) ? trim($sizeInput) : '';
        $check = $db->prepare('SELECT ci.id, ci.product_id, p.stock, p.has_sizes FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.id = :id AND ci.user_id = :user_id');
        $check->execute(['id' => $itemId, 'user_id' => $user['id']]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        $sizeIsActive = true;
        if ($row && (int)$row['has_sizes'] === 1) {
            $sizeQuery = $db->prepare('SELECT id FROM product_sizes WHERE product_id = :product_id AND size = :size AND is_active = 1');
            $sizeQuery->execute(['product_id' => $row['product_id'], 'size' => $selectedSize]);
            $sizeIsActive = $sizeQuery->fetchColumn() !== false;
        } elseif ($row) {
            $selectedSize = '';
        }

        if ($row && $quantity !== false && $quantity >= 1 && $sizeIsActive) {
            $db->beginTransaction();
            try {
                $duplicate = $db->prepare('SELECT id FROM cart_items WHERE user_id = :user_id AND product_id = :product_id AND selected_size = :selected_size AND id <> :id LIMIT 1 FOR UPDATE');
                $duplicate->execute([
                    'user_id' => $user['id'], 'product_id' => $row['product_id'],
                    'selected_size' => $selectedSize, 'id' => $itemId,
                ]);
                if ($duplicate->fetchColumn()) {
                    $db->rollBack();
                    cvcarts_flash('error', 'That product and size are already in your cart. Update the existing item instead.');
                    cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
                }
                $db->prepare('UPDATE cart_items SET selected_size = :selected_size, quantity = :quantity WHERE id = :id AND user_id = :user_id')
                    ->execute(['selected_size' => $selectedSize, 'quantity' => $quantity, 'id' => $itemId, 'user_id' => $user['id']]);
                cvcarts_emit_realtime_event($db, 'cart.updated', 'student', (int)$user['id']);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            cvcarts_flash('success', 'Cart updated.');
        } else {
            cvcarts_flash('error', 'Choose an active size and a valid quantity.');
        }
        cvcarts_redirect(cvcarts_url('/student/cart/index.php'));
    }
}

$stmt = $db->prepare('SELECT ci.*, p.name, p.price, p.image_url, p.stock, p.has_sizes, p.is_active FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.user_id = :user_id ORDER BY ci.created_at DESC');
$stmt->execute(['user_id' => $user['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sizeQuery = $db->query('SELECT product_id, size, stock FROM product_sizes WHERE is_active = 1 ORDER BY size');
$productSizes = [];
foreach ($sizeQuery->fetchAll(PDO::FETCH_ASSOC) as $sizeRow) {
    $productSizes[(int)$sizeRow['product_id']][$sizeRow['size']] = $sizeRow;
}
$total = 0.0;
foreach ($items as $item) {
    $total += (float)$item['price'] * (int)$item['quantity'];
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | My Cart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/student/cart.css')); ?>">
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
            <div class="page-heading"><div><h1>My Cart</h1><p>Review your items before checkout.</p></div><a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>" class="btn">Continue Shopping</a></div>
            <?php foreach ($flashes as $flash): ?><div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Please Note' : 'Success'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>

            <?php if (!$items): ?>
                <section class="panel"><h2 class="panel-heading">Your cart is empty</h2><p>Browse the shop to find school essentials.</p><a class="btn" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>">Visit Shop</a></section>
            <?php else: ?>
                <section class="panel">
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th><input type="checkbox" id="select-all-cart-items" aria-label="Select all available items"></th><th>Product</th><th>Size</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $quantityStock = (int)$item['stock'];
                                    if ((int)$item['has_sizes'] === 1) {
                                        $quantityStock = (int)($productSizes[(int)$item['product_id']][$item['selected_size'] ?? '']['stock'] ?? 0);
                                    }
                                    $itemUnavailable = (int)$item['is_active'] !== 1 || $quantityStock < (int)$item['quantity'];
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="cart-checkout-item" name="item_ids[]" value="<?php echo (int)$item['id']; ?>" form="checkout-selected-form" aria-label="Select <?php echo cvcarts_h($item['name']); ?> for checkout" <?php echo $itemUnavailable ? 'disabled' : ''; ?>></td>
                                        <td><?php echo cvcarts_h($item['name']); ?><?php if ($itemUnavailable): ?><br><small class="status status-unavailable"><?php echo (int)$item['is_active'] !== 1 ? 'Product unavailable' : 'Not enough stock to check out'; ?></small><?php endif; ?></td>
                                        <td><?php echo cvcarts_h($item['selected_size'] ?: '—'); ?></td>
                                        <td><?php echo cvcarts_format_money((float)$item['price']); ?></td>
                                        <td>
                                            <form method="post" action="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>" class="form-inline cart-update-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                                                <input type="hidden" name="update_item" value="1"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                                <?php if ((int)$item['has_sizes'] === 1): ?>
                                                    <select class="select-control cart-size" name="selected_size" aria-label="Size for <?php echo cvcarts_h($item['name']); ?>" required>
                                                        <?php if (!isset($productSizes[(int)$item['product_id']][$item['selected_size'] ?? ''])): ?>
                                                            <option value="<?php echo cvcarts_h((string)$item['selected_size']); ?>" selected disabled><?php echo cvcarts_h(($item['selected_size'] ?: 'Current size') . ' (unavailable)'); ?></option>
                                                        <?php endif; ?>
                                                        <?php foreach ($productSizes[(int)$item['product_id']] ?? [] as $sizeRow): ?>
                                                            <option value="<?php echo cvcarts_h($sizeRow['size']); ?>" data-stock="<?php echo (int)$sizeRow['stock']; ?>" <?php echo $item['selected_size'] === $sizeRow['size'] ? 'selected' : ''; ?>><?php echo cvcarts_h($sizeRow['size']); ?> — <?php echo (int)$sizeRow['stock']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="hidden" name="selected_size" value="">
                                                <?php endif; ?>
                                                <input class="form-control cart-quantity" type="number" name="quantity" value="<?php echo (int)$item['quantity']; ?>" min="1" aria-label="Quantity">
                                                <button class="btn btn-small" type="submit">Update</button>
                                            </form>
                                        </td>
                                        <td><?php echo cvcarts_format_money((float)$item['price'] * (int)$item['quantity']); ?></td>
                                        <td><form method="post" action="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="remove_item" value="1"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-small btn-danger cart-remove-button" type="submit" aria-label="Remove item" data-confirm="Remove this item from your cart?" data-confirm-title="Remove item?" data-confirm-label="Remove Item" data-confirm-type="error">Remove</button></form></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <div class="history-toolbar cart-checkout-toolbar">
                    <div class="page-date">All cart items: <strong><?php echo cvcarts_format_money($total); ?></strong></div>
                    <form id="checkout-selected-form" method="post" action="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                        <input type="hidden" name="start_checkout" value="1">
                        <button class="btn" id="checkout-selected-button" type="submit">Checkout Selected </button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
        (function () {
            const form = document.getElementById('checkout-selected-form');
            if (!form) return;

            const items = Array.from(document.querySelectorAll('.cart-checkout-item'));
            const selectAll = document.getElementById('select-all-cart-items');
            const submitButton = document.getElementById('checkout-selected-button');
            const selectedCount = document.getElementById('selected-item-count');

            function updateSelection() {
                const availableItems = items.filter(function (item) { return !item.disabled; });
                const selectedItems = availableItems.filter(function (item) { return item.checked; });
                selectedCount.textContent = String(selectedItems.length);
                submitButton.disabled = selectedItems.length === 0;
                selectAll.disabled = availableItems.length === 0;
                selectAll.checked = availableItems.length > 0 && selectedItems.length === availableItems.length;
                selectAll.indeterminate = selectedItems.length > 0 && selectedItems.length < availableItems.length;
            }

            items.forEach(function (item) {
                item.addEventListener('change', updateSelection);
            });
            selectAll.addEventListener('change', function () {
                items.forEach(function (item) {
                    if (!item.disabled) item.checked = selectAll.checked;
                });
                updateSelection();
            });
            updateSelection();
        }());
    </script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js?v=' . filemtime(__DIR__ . '/../../assets/js/modal.js'))); ?>"></script>
</body>
</html>
