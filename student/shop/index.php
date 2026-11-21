<?php
require __DIR__ . '/../../includes/auth.php';
cvcarts_require_login();

$db = cvcarts_db();
$user = cvcarts_user();
if ($user['role'] !== 'student') {
    cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_to_cart']) || isset($_POST['buy_now']))) {
    cvcarts_require_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = filter_var(is_string($_POST['quantity'] ?? null) ? $_POST['quantity'] : '', FILTER_VALIDATE_INT);
    $sizeInput = $_POST['size'] ?? '';
    $size = is_string($sizeInput) ? trim($sizeInput) : '';
    $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product || $quantity === false || $quantity < 1) {
        cvcarts_flash('error', 'The product is unavailable or the requested quantity is invalid.');
        cvcarts_redirect(cvcarts_url('/student/shop/index.php'));
    }
    if ((int)$product['has_sizes'] === 1) {
        $sizeQuery = $db->prepare('SELECT stock FROM product_sizes WHERE product_id = :product_id AND size = :size AND is_active = 1 LIMIT 1');
        $sizeQuery->execute(['product_id' => $productId, 'size' => $size]);
        $availableStock = $sizeQuery->fetchColumn();
        if ($size === '' || $availableStock === false) {
            cvcarts_flash('error', 'Choose an active size for this product.');
            cvcarts_redirect(cvcarts_url('/student/shop/index.php'));
        }
    } else {
        $size = '';
        $availableStock = (int)$product['stock'];
    }

    if (isset($_POST['buy_now'])) {
        if ($quantity > (int)$availableStock) {
            cvcarts_flash('error', 'Buy Now is only available for quantities currently in stock. Add the item to your cart instead.');
            cvcarts_redirect(cvcarts_url('/student/shop/index.php'));
        }
        $_SESSION['buy_now'] = ['product_id' => $productId, 'quantity' => $quantity, 'size' => $size];
        $_SESSION['buy_now_expires_at'] = time() + 1800;
        cvcarts_redirect(cvcarts_url('/student/checkout/index.php?buy_now=1'));
    } else {
        unset($_SESSION['buy_now'], $_SESSION['buy_now_expires_at']);
        $existing = $db->prepare('SELECT id, quantity FROM cart_items WHERE user_id = :user_id AND product_id = :product_id AND selected_size = :selected_size LIMIT 1');
        $existing->execute(['user_id' => $user['id'], 'product_id' => $productId, 'selected_size' => $size]);
        $cartItem = $existing->fetch(PDO::FETCH_ASSOC);
        if ($cartItem) {
            $newQuantity = (int)$cartItem['quantity'] + $quantity;
            $db->prepare('UPDATE cart_items SET quantity = :quantity WHERE id = :id')->execute(['quantity' => $newQuantity, 'id' => $cartItem['id']]);
        } else {
            $db->prepare('INSERT INTO cart_items (user_id, product_id, selected_size, quantity) VALUES (:user_id, :product_id, :selected_size, :quantity)')->execute([
                'user_id' => $user['id'], 'product_id' => $productId, 'selected_size' => $size, 'quantity' => $quantity,
            ]);
        }
        cvcarts_emit_realtime_event($db, 'cart.updated', 'student', (int)$user['id']);
        cvcarts_flash('success', 'Item added to cart.');
        cvcarts_redirect(cvcarts_url('/student/shop/index.php'));
    }
}

$category = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$availability = is_string($_GET['availability'] ?? null) ? $_GET['availability'] : '';
$sizeFilter = is_string($_GET['size_type'] ?? null) ? $_GET['size_type'] : '';
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest';
$sortOptions = ['newest' => 'created_at DESC, name ASC', 'oldest' => 'created_at ASC, name ASC', 'name_asc' => 'name ASC', 'name_desc' => 'name DESC', 'price_asc' => 'price ASC, name ASC', 'price_desc' => 'price DESC, name ASC'];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}
$pageSizeInput = filter_var($_GET['per_page'] ?? 9, FILTER_VALIDATE_INT);
$pageSize = in_array($pageSizeInput, [9, 18, 36], true) ? $pageSizeInput : 9;
$page = max(1, (int)($_GET['page'] ?? 1));
$minPriceInput = is_string($_GET['min_price'] ?? null) ? $_GET['min_price'] : '';
$maxPriceInput = is_string($_GET['max_price'] ?? null) ? $_GET['max_price'] : '';
$minPrice = $minPriceInput === '' ? null : filter_var($minPriceInput, FILTER_VALIDATE_FLOAT);
$maxPrice = $maxPriceInput === '' ? null : filter_var($maxPriceInput, FILTER_VALIDATE_FLOAT);
$filterErrors = ($minPriceInput !== '' && ($minPrice === false || $minPrice < 0))
    || ($maxPriceInput !== '' && ($maxPrice === false || $maxPrice < 0))
    || ($minPrice !== null && $minPrice !== false && $maxPrice !== null && $maxPrice !== false && $minPrice > $maxPrice);
if ($filterErrors) {
    cvcarts_flash('error', 'Enter a valid non-negative price range with the minimum no greater than the maximum.');
    $minPrice = null;
    $maxPrice = null;
}
$where = ['is_active = 1', 'deleted_at IS NULL'];
$params = [];
if ($category !== '') {
    $where[] = 'category = :category';
    $params['category'] = $category;
}
if ($search !== '') {
    $where[] = '(name LIKE :search_name OR description LIKE :search_description OR category LIKE :search_category)';
    $term = '%' . $search . '%';
    $params += ['search_name' => $term, 'search_description' => $term, 'search_category' => $term];
}
if ($minPrice !== null && $minPrice !== false) {
    $where[] = 'price >= :min_price';
    $params['min_price'] = $minPrice;
}
if ($maxPrice !== null && $maxPrice !== false) {
    $where[] = 'price <= :max_price';
    $params['max_price'] = $maxPrice;
}
if (in_array($sizeFilter, ['with_sizes', 'without_sizes'], true)) {
    $where[] = 'has_sizes = :has_sizes';
    $params['has_sizes'] = $sizeFilter === 'with_sizes' ? 1 : 0;
}
if ($availability === 'available') {
    $where[] = 'stock > 0';
} elseif ($availability === 'out_of_stock') {
    $where[] = 'stock = 0';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$countQuery = $db->prepare('SELECT COUNT(*) FROM products' . $whereSql);
$countQuery->execute($params);
$totalProducts = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $pageSize));
$page = min($page, $totalPages);
$stmt = $db->prepare('SELECT * FROM products' . $whereSql . ' ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sizeQuery = $db->query('SELECT product_id, size, stock FROM product_sizes WHERE is_active = 1 ORDER BY size');
$productSizes = [];
foreach ($sizeQuery->fetchAll(PDO::FETCH_ASSOC) as $sizeRow) {
    $productSizes[(int)$sizeRow['product_id']][] = $sizeRow;
}
$categories = $db->query('SELECT DISTINCT category FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Shop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/student/shop.css')); ?>">
</head>
<body>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a class="header-brand" href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
            <a class="header-icon" href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a>
            <?php echo cvcarts_student_profile_menu($user); ?>
            <a class="header-logout" href="<?php echo cvcarts_h(cvcarts_url('/auth/logout.php')); ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a>
        </div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>" class="active" aria-current="page"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-history/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/settings/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>
        <main class="main-content">
            <div class="page-heading">
                <div><h1>Shop</h1><p>Browse school essentials and add items to your cart.</p></div>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>" class="btn"><i class="bi bi-cart3" aria-hidden="true"></i> My Cart (<?php echo cvcarts_cart_count(); ?>)</a>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Please Note' : 'Success'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div>
            <?php endforeach; ?>

            <form method="get" class="filter-form" data-filter-form>
                <label>Search<span class="search-input-wrap"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" type="search" name="search" value="<?php echo cvcarts_h($search); ?>" placeholder="Search"></span></label>
                <label>Category<select class="select-control" name="category"><option value="">All categories</option><?php foreach ($categories as $availableCategory): ?><option value="<?php echo cvcarts_h($availableCategory); ?>" <?php echo $category === $availableCategory ? 'selected' : ''; ?>><?php echo cvcarts_h($availableCategory); ?></option><?php endforeach; ?></select></label>
                <label>Availability<select class="select-control" name="availability"><option value="">All</option><option value="available" <?php echo $availability === 'available' ? 'selected' : ''; ?>>In stock</option><option value="out_of_stock" <?php echo $availability === 'out_of_stock' ? 'selected' : ''; ?>>Out of stock</option></select></label>
                <label>Product type<select class="select-control" name="size_type"><option value="">All product types</option><option value="with_sizes" <?php echo $sizeFilter === 'with_sizes' ? 'selected' : ''; ?>>Size-based</option><option value="without_sizes" <?php echo $sizeFilter === 'without_sizes' ? 'selected' : ''; ?>>Regular stock</option></select></label>
                <label>Minimum price<input class="form-control" type="number" min="0" step="0.01" name="min_price" value="<?php echo cvcarts_h($minPriceInput); ?>"></label>
                <label>Maximum price<input class="form-control" type="number" min="0" step="0.01" name="max_price" value="<?php echo cvcarts_h($maxPriceInput); ?>"></label>
                <label>Sort<select class="select-control" name="sort"><?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'] as $value => $label): ?><option value="<?php echo cvcarts_h($value); ?>" <?php echo $sort === $value ? 'selected' : ''; ?>><?php echo cvcarts_h($label); ?></option><?php endforeach; ?></select></label>
                <label>Show<select class="select-control" name="per_page"><option value="9" <?php echo $pageSize === 9 ? 'selected' : ''; ?>>9</option><option value="18" <?php echo $pageSize === 18 ? 'selected' : ''; ?>>18</option><option value="36" <?php echo $pageSize === 36 ? 'selected' : ''; ?>>36</option></select></label>
                <div class="filter-actions">
                    <button type="submit" class="btn" data-loading-label="Applying…">Apply Filters</button>
                    <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>" class="btn btn-secondary" data-filter-reset>Reset Filters</a>
                </div>
            </form>

            <?php if (!$products): ?>
                <section class="panel"><h2>No matching products</h2><p class="muted">Try changing or clearing the search and filters.</p></section>
            <?php else: ?>
                <section class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="product-card">
                            <img src="<?php echo cvcarts_h(cvcarts_product_image_url($product['image_url'] ?? '')); ?>" alt="<?php echo cvcarts_h($product['name']); ?>">
                            <div class="product-card-body">
                                <h2><?php echo cvcarts_h($product['name']); ?></h2>
                                <p><?php echo cvcarts_h($product['category']); ?> &nbsp;·&nbsp; <?php echo (int)$product['has_sizes'] === 1 ? 'Stock varies by size' : 'Stock: ' . (int)$product['stock']; ?></p>
                                <strong><?php echo cvcarts_format_money((float)$product['price']); ?></strong>
                                <button class="btn btn-small" type="button" data-modal-open="product-details-<?php echo (int)$product['id']; ?>">View Details</button>
                            </div>
                            <dialog id="product-details-<?php echo (int)$product['id']; ?>" class="app-modal" aria-labelledby="product-title-<?php echo (int)$product['id']; ?>">
                                <div class="modal-shell">
                                    <header class="modal-header"><div><h2 id="product-title-<?php echo (int)$product['id']; ?>"><?php echo cvcarts_h($product['name']); ?></h2><p><?php echo cvcarts_h($product['category']); ?> · <?php echo cvcarts_format_money((float)$product['price']); ?></p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                                    <div class="modal-body">
                                        <div class="product-details-layout">
                                            <img class="product-details-image" src="<?php echo cvcarts_h(cvcarts_product_image_url($product['image_url'] ?? '')); ?>" alt="<?php echo cvcarts_h($product['name']); ?>">
                                            <div class="product-details-copy">
                                                <p><?php echo cvcarts_h($product['description'] ?? ''); ?></p>
                                                <p class="form-help"><?php echo (int)$product['has_sizes'] === 1 ? 'Choose a size to see its available stock.' : 'Available stock: ' . (int)$product['stock']; ?></p>
                                                <?php if ((int)$product['has_sizes'] !== 1 || !empty($productSizes[(int)$product['id']])): ?>
                                                    <form method="post" action="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>" class="product-order-form" data-modal-form>
                                                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                                                        <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                        <div class="form-grid">
                                                            <div class="form-field"><label class="form-label" for="quantity-<?php echo (int)$product['id']; ?>">Quantity</label><input id="quantity-<?php echo (int)$product['id']; ?>" class="form-control product-quantity" type="number" name="quantity" min="1" value="1" aria-label="Quantity"></div>
                                                            <?php if ((int)$product['has_sizes'] === 1): ?>
                                                                <div class="form-field"><label class="form-label" for="size-<?php echo (int)$product['id']; ?>">Size</label><select class="select-control product-size" id="size-<?php echo (int)$product['id']; ?>" name="size" aria-label="Select product size" required><option value="">Select size</option><?php foreach ($productSizes[(int)$product['id']] as $sizeRow): ?><option value="<?php echo cvcarts_h($sizeRow['size']); ?>" data-stock="<?php echo (int)$sizeRow['stock']; ?>"><?php echo cvcarts_h($sizeRow['size']); ?> — <?php echo (int)$sizeRow['stock']; ?> available</option><?php endforeach; ?></select></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="action-row"><button class="btn btn-small" type="submit" name="add_to_cart" value="1">Add to Cart</button><button class="btn btn-small" type="submit" name="buy_now" value="1" data-stock="<?php echo (int)$product['stock']; ?>" <?php echo (int)$product['has_sizes'] !== 1 && (int)$product['stock'] < 1 ? 'disabled' : ''; ?>>Buy Now</button></div>
                                                    </form>
                                                <?php else: ?><span class="status status-unavailable">No active sizes are available for this product.</span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <footer class="modal-footer"><button class="btn btn-small" type="button" data-modal-close>Close</button></footer>
                                </div>
                            </dialog>
                        </article>
                    <?php endforeach; ?>
                </section>
                <?php
                $shopParams = [
                    'search' => $search,
                    'category' => $category,
                    'availability' => $availability,
                    'size_type' => $sizeFilter,
                    'min_price' => $minPriceInput,
                    'max_price' => $maxPriceInput,
                    'sort' => $sort,
                    'per_page' => $pageSize,
                ];
                ?>
                <nav class="pagination" aria-label="Product pages">
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?> · <?php echo number_format($totalProducts); ?> products</span>
                    <div class="pagination-pages">
                        <?php if ($page > 1): ?><a class="btn btn-small" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php?' . http_build_query($shopParams + ['page' => $page - 1]))); ?>">Previous</a><?php endif; ?>
                        <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?><a class="btn btn-small <?php echo $pageNumber === $page ? 'active' : ''; ?>" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php?' . http_build_query($shopParams + ['page' => $pageNumber]))); ?>" <?php echo $pageNumber === $page ? 'aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a><?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a class="btn btn-small" href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php?' . http_build_query($shopParams + ['page' => $page + 1]))); ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </main>
    </div>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js')); ?>"></script>
    <script>
        function bindProductOrderForms() {
            document.querySelectorAll('.product-order-form').forEach(function (form) {
                if (form.dataset.stockHandlerBound === 'true') return;
                form.dataset.stockHandlerBound = 'true';
                const size = form.querySelector('.product-size');
                const quantity = form.querySelector('.product-quantity');
                const buyNow = form.querySelector('button[name="buy_now"]');
                if (!size) {
                    function updateRegularStock() {
                        buyNow.disabled = Number(buyNow.dataset.stock || 0) < Number(quantity.value);
                    }
                    quantity.addEventListener('input', updateRegularStock);
                    updateRegularStock();
                    return;
                }
                function updateSizeStock() {
                    const option = size.options[size.selectedIndex];
                    const available = Number(option.dataset.stock || 0);
                    buyNow.disabled = available < Number(quantity.value);
                }
                quantity.addEventListener('input', updateSizeStock);
                size.addEventListener('change', updateSizeStock);
                updateSizeStock();
            });
        }
        bindProductOrderForms();
        document.addEventListener('cvcarts:content-updated', bindProductOrderForms);
    </script>
</body>
</html>
