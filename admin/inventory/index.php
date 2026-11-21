<?php
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');
$db = cvcarts_db();
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();

    if (isset($_POST['add_product'])) {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $category = is_string($_POST['category'] ?? null) ? trim($_POST['category']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $imageUrl = '';
        $imageFile = $_FILES['image_file'] ?? null;
        $price = filter_var(is_string($_POST['price'] ?? null) ? $_POST['price'] : '', FILTER_VALIDATE_FLOAT);
        $hasSizes = ($_POST['has_sizes'] ?? '0') === '1';
        $stockInput = is_string($_POST['stock'] ?? null) ? $_POST['stock'] : '';
        $stock = $hasSizes && $stockInput === '' ? 0 : filter_var($stockInput, FILTER_VALIDATE_INT);
        $sizes = [];
        $sizeError = null;
        try {
            $sizes = cvcarts_parse_size_stock_list(is_string($_POST['sizes'] ?? null) ? $_POST['sizes'] : '');
        } catch (InvalidArgumentException $e) {
            $sizeError = $e->getMessage();
        }
        $imageIsValid = true;
        if ($imageFile && is_array($imageFile) && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $imageUrl = cvcarts_store_product_image($imageFile);
                $imageIsValid = true;
            } catch (RuntimeException $e) {
                $imageIsValid = false;
                cvcarts_flash('error', $e->getMessage());
            }
        }

        if ($name === '' || strlen($name) > 150 || $category === '' || strlen($category) > 100 || strlen($description) > 5000
            || $price === false || !is_finite((float)$price) || $price < 0 || $stock === false || $stock < 0 || !$imageIsValid || $sizeError !== null || ($hasSizes && !$sizes)) {
            cvcarts_flash('error', $sizeError ?? ($hasSizes && !$sizes
                ? 'Add at least one size and its stock for a sized product.'
                : 'Enter valid product details and a non-negative price and stock, and upload a valid image if desired.'));
        } else {
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO products (name, description, category, price, stock, has_sizes, image_url, is_active) VALUES (:name, :description, :category, :price, 0, 0, :image_url, 1)')->execute([
                    'name' => $name, 'description' => $description ?: null, 'category' => $category, 'price' => $price,
                    'image_url' => $imageUrl ?: null,
                ]);
                $productId = (int)$db->lastInsertId();
                cvcarts_update_product_inventory($db, $productId, $hasSizes, (int)$stock, $sizes, (int)$user['id']);
                cvcarts_emit_realtime_event($db, 'product.updated', 'admin');
                cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
                $db->commit();
                cvcarts_flash('success', 'Product added to inventory.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('CVCarts product creation failed: ' . $e->getMessage());
                cvcarts_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Product could not be added. Please try again.');
            }
        }
        cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
    }

    if (isset($_POST['update_product'])) {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $category = is_string($_POST['category'] ?? null) ? trim($_POST['category']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $imageUrl = '';
        $imageFile = $_FILES['image_file'] ?? null;
        $price = filter_var(is_string($_POST['price'] ?? null) ? $_POST['price'] : '', FILTER_VALIDATE_FLOAT);
        $hasSizes = ($_POST['has_sizes'] ?? '0') === '1';
        $stockInput = is_string($_POST['stock'] ?? null) ? $_POST['stock'] : '';
        $stock = $hasSizes && $stockInput === '' ? 0 : filter_var($stockInput, FILTER_VALIDATE_INT);
        $sizes = [];
        $sizeError = null;
        try {
            $sizes = cvcarts_parse_size_stock_list(is_string($_POST['sizes'] ?? null) ? $_POST['sizes'] : '');
        } catch (InvalidArgumentException $e) {
            $sizeError = $e->getMessage();
        }
        $currentProduct = $db->prepare('SELECT image_url FROM products WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $currentProduct->execute(['id' => $productId]);
        $currentProductRow = $currentProduct->fetch(PDO::FETCH_ASSOC);
        if (!$productId || !$currentProductRow) {
            cvcarts_flash('error', 'This product is no longer available for editing.');
            cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
        }
        $existingImage = $currentProductRow['image_url'];
        $imageUrl = is_string($existingImage) ? $existingImage : '';
        $imageIsValid = true;
        if ($imageFile && is_array($imageFile) && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $imageUrl = cvcarts_store_product_image($imageFile, $existingImage);
                $imageIsValid = true;
            } catch (RuntimeException $e) {
                $imageIsValid = false;
                cvcarts_flash('error', $e->getMessage());
            }
        }

        if (!$productId || $name === '' || strlen($name) > 150 || $category === '' || strlen($category) > 100 || strlen($description) > 5000
            || $price === false || !is_finite((float)$price) || $price < 0 || $stock === false || $stock < 0 || !$imageIsValid || $sizeError !== null || ($hasSizes && !$sizes)) {
            cvcarts_flash('error', $sizeError ?? ($hasSizes && !$sizes
                ? 'Add at least one size and its stock for a sized product.'
                : 'Enter valid product details and a non-negative price and stock, and upload a valid image if desired.'));
        } else {
            $db->beginTransaction();
            try {
                $previousImage = $imageUrl === $existingImage ? null : $existingImage;
                $editLock = $db->prepare('SELECT id FROM products WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
                $editLock->execute(['id' => $productId]);
                if ($editLock->fetchColumn() === false) {
                    throw new RuntimeException('This product is no longer available for editing.');
                }
                $db->prepare('UPDATE products SET name = :name, description = :description, category = :category, price = :price, image_url = :image_url WHERE id = :id AND deleted_at IS NULL')
                    ->execute([
                        'name' => $name, 'description' => $description ?: null, 'category' => $category, 'price' => $price,
                        'image_url' => $imageUrl ?: null, 'id' => $productId,
                    ]);
                cvcarts_update_product_inventory($db, (int)$productId, $hasSizes, (int)$stock, $sizes, (int)$user['id']);
                cvcarts_emit_realtime_event($db, 'product.updated', 'admin');
                cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
                $db->commit();
                if (is_string($previousImage) && $previousImage !== '') {
                    cvcarts_remove_product_image($previousImage);
                }
                cvcarts_flash('success', 'Product details updated.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('CVCarts product update failed: ' . $e->getMessage());
                cvcarts_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Product details could not be updated. Please try again.');
            }
        }
        cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
    }

    if (isset($_POST['restore_product'])) {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$productId) {
            cvcarts_flash('error', 'Choose a valid product.');
            cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
        }
        $db->beginTransaction();
        try {
            $productQuery = $db->prepare('SELECT is_active, deleted_at FROM products WHERE id = :id FOR UPDATE');
            $productQuery->execute(['id' => $productId]);
            $product = $productQuery->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('The product could not be found.');
            }
            if ($product['deleted_at'] !== null) {
                throw new RuntimeException('This product has already been deleted.');
            }
            if ((int)$product['is_active'] === 1) {
                throw new RuntimeException('This product is already active.');
            }
            $db->prepare('UPDATE products SET is_active = 1 WHERE id = :id')->execute(['id' => $productId]);
            $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                ->execute([
                    'admin_id' => $user['id'],
                    'action' => 'Product restored',
                    'details' => 'Product ID ' . $productId . ' restored to the shop.',
                ]);
            cvcarts_emit_realtime_event($db, 'product.updated', 'admin');
            cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
            $db->commit();
            cvcarts_flash('success', 'Product restored to the shop.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            cvcarts_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The product availability could not be updated.');
        }
        cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
    }

    if (isset($_POST['delete_product'])) {
        $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$productId) {
            cvcarts_flash('error', 'Choose a valid product.');
            cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
        }
        $db->beginTransaction();
        try {
            $productQuery = $db->prepare('SELECT is_active, deleted_at FROM products WHERE id = :id FOR UPDATE');
            $productQuery->execute(['id' => $productId]);
            $product = $productQuery->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('The product could not be found.');
            }
            if ($product['deleted_at'] !== null) {
                throw new RuntimeException('This product has already been deleted.');
            }
            $cartQuery = $db->prepare('SELECT id FROM cart_items WHERE product_id = :id LIMIT 1 FOR UPDATE');
            $cartQuery->execute(['id' => $productId]);
            $hasCartItems = $cartQuery->fetchColumn() !== false;
            if ((int)$product['is_active'] !== 1 && !$hasCartItems) {
                throw new RuntimeException('Only products in the Shop or a student cart can be deleted.');
            }
            $cartDelete = $db->prepare('DELETE FROM cart_items WHERE product_id = :id');
            $cartDelete->execute(['id' => $productId]);
            $removedCartItems = $cartDelete->rowCount();
            $delete = $db->prepare('UPDATE products SET is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL');
            $delete->execute(['id' => $productId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The product could not be found.');
            }
            $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                ->execute(['admin_id' => $user['id'], 'action' => 'Product deleted', 'details' => 'Product ID ' . $productId . ' removed from inventory.']);
            cvcarts_emit_realtime_event($db, 'product.updated', 'admin');
            cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
            $db->commit();
            cvcarts_flash('success', $removedCartItems > 0
                ? 'Product removed from the Shop and active carts. Existing order and inventory history is preserved.'
                : 'Product removed from the Shop. Existing order and inventory history is preserved.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof PDOException || !($e instanceof RuntimeException)) {
                error_log('CVCarts product deletion failed: ' . $e->getMessage());
                cvcarts_flash('error', 'The product could not be deleted. Please try again.');
            } else {
                cvcarts_flash('error', $e->getMessage());
            }
        }
        cvcarts_redirect(cvcarts_url('/admin/inventory/index.php'));
    }
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$categoryFilter = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$stockFilter = is_string($_GET['stock_status'] ?? null) ? $_GET['stock_status'] : '';
$sizeFilter = is_string($_GET['size_type'] ?? null) ? $_GET['size_type'] : '';
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name_asc';
$pageSizeInput = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT);
$pageSize = in_array($pageSizeInput, [10, 25, 50], true) ? $pageSizeInput : 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$sortOptions = [
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'price_asc' => 'price ASC, name ASC',
    'price_desc' => 'price DESC, name ASC',
    'stock_asc' => 'stock ASC, name ASC',
    'stock_desc' => 'stock DESC, name ASC',
    'newest' => 'created_at DESC, name ASC',
    'oldest' => 'created_at ASC, name ASC',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'name_asc';
}
$where = ['deleted_at IS NULL'];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :search_name OR category LIKE :search_category OR description LIKE :search_description)';
    $searchTerm = '%' . $search . '%';
    $params += ['search_name' => $searchTerm, 'search_category' => $searchTerm, 'search_description' => $searchTerm];
}
if ($categoryFilter !== '') {
    $where[] = 'category = :category';
    $params['category'] = $categoryFilter;
}
if (in_array($stockFilter, ['in_stock', 'low_stock'], true)) {
    $where[] = $stockFilter === 'in_stock' ? 'stock >= 10' : 'stock < 10';
}
if (in_array($sizeFilter, ['with_sizes', 'without_sizes'], true)) {
    $where[] = 'has_sizes = ' . ($sizeFilter === 'with_sizes' ? '1' : '0');
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = $db->prepare('SELECT COUNT(*) FROM products' . $whereSql);
$countQuery->execute($params);
$totalProducts = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $pageSize));
$page = min($page, $totalPages);
$stmt = $db->prepare(
    'SELECT products.*,
        (SELECT COUNT(*) FROM cart_items ci WHERE ci.product_id = products.id) AS cart_item_count
     FROM products' . $whereSql . ' ORDER BY is_active DESC, ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query('SELECT DISTINCT category FROM products WHERE deleted_at IS NULL AND category <> "" ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$sizeQuery = $db->query('SELECT product_id, id, size, stock, is_active FROM product_sizes ORDER BY size');
$productSizes = [];
foreach ($sizeQuery->fetchAll(PDO::FETCH_ASSOC) as $sizeRow) {
    $productSizes[(int)$sizeRow['product_id']][] = $sizeRow;
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/admin/inventory.css?v=' . filemtime(__DIR__ . '/../../assets/css/admin/inventory.css'))); ?>">
</head>
<body>
    <?php cvcarts_admin_header($user, $alerts); ?>
    <div class="app-layout">
        <?php cvcarts_admin_sidebar('inventory'); ?>
        <main class="main-content">
            <div class="page-heading"><div><h1>Inventory</h1><p>Manage products, prices, images, stock, and availability.</p></div><button class="btn" type="button" data-modal-open="add-product-dialog"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Item</button></div>
            <?php foreach ($flashes as $flash): ?><div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Warning' : 'Success'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>

            <section class="panel inventory-filter-panel" aria-label="Inventory search and filters">
                <form class="filter-form" data-filter-form method="get" action="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>">
                    <div class="inventory-filter-field inventory-search-field">
                        <label class="form-label" for="toolbar-search">Search</label>
                        <div class="search-input-wrap">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input class="form-control" id="toolbar-search" type="search" name="search" value="<?php echo cvcarts_h($search); ?>" placeholder="Search">
                        </div>
                    </div>
                    <div class="inventory-filter-field">
                        <label class="form-label" for="toolbar-category">Category</label>
                        <select class="form-select" id="toolbar-category" name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?><option value="<?php echo cvcarts_h($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo cvcarts_h($category); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="inventory-filter-field">
                        <label class="form-label" for="toolbar-stock">Stock status</label>
                        <select class="form-select" id="toolbar-stock" name="stock_status">
                            <option value="" <?php echo $stockFilter === '' ? 'selected' : ''; ?>>All stock</option>
                            <option value="in_stock" <?php echo $stockFilter === 'in_stock' ? 'selected' : ''; ?>>In stock</option>
                            <option value="low_stock" <?php echo $stockFilter === 'low_stock' ? 'selected' : ''; ?>>Low stock</option>
                        </select>
                    </div>
                    <div class="inventory-filter-field">
                        <label class="form-label" for="toolbar-size-type">Product type</label>
                        <select class="form-select" id="toolbar-size-type" name="size_type">
                            <option value="">All products</option>
                            <option value="with_sizes" <?php echo $sizeFilter === 'with_sizes' ? 'selected' : ''; ?>>Size-based</option>
                            <option value="without_sizes" <?php echo $sizeFilter === 'without_sizes' ? 'selected' : ''; ?>>Regular stock</option>
                        </select>
                    </div>
                    <div class="inventory-filter-field">
                        <label class="form-label" for="toolbar-sort">Sort by</label>
                        <select class="form-select" id="toolbar-sort" name="sort">
                            <?php foreach (['name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'stock_asc' => 'Stock: low to high', 'stock_desc' => 'Stock: high to low', 'newest' => 'Newest first', 'oldest' => 'Oldest first'] as $value => $label): ?>
                                <option value="<?php echo cvcarts_h($value); ?>" <?php echo $sort === $value ? 'selected' : ''; ?>><?php echo cvcarts_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                 
                    <div class="filter-actions inventory-filter-actions">
                        <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
                        <a class="btn btn-secondary" data-filter-reset href="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>">Reset Filters</a>
                    </div>
                </form>
            </section>

            <dialog id="add-product-dialog" class="app-modal" aria-labelledby="add-product-title">
                <div class="modal-shell">
                    <header class="modal-header">
                        <div>
                            <h2 id="add-product-title">Add Inventory Item</h2>
                            <p>Enter product details and inventory settings.</p>
                        </div>
                        <button class="modal-close" type="button" data-modal-close aria-label="Close">×</button>
                    </header>
                    <form method="post" enctype="multipart/form-data" action="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>" data-modal-form>
                        <div class="modal-body form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                            <input type="hidden" name="add_product" value="1">
                            <div class="form-field"><label class="form-label" for="product-name">Item</label><input class="form-control" id="product-name" name="name" required></div>
                            <div class="form-field"><label class="form-label" for="product-category">Category</label><input class="form-control" id="product-category" name="category" required></div>
                            <div class="form-field"><label class="form-label" for="product-price">Price</label><input class="form-control" id="product-price" type="number" step="0.01" min="0" name="price" required></div>
                            <div class="form-field"><label class="form-label" for="product-has-sizes">Does this product have sizes?</label><select class="form-select" id="product-has-sizes" name="has_sizes"><option value="0">No</option><option value="1">Yes</option></select></div>
                            <div class="form-field regular-stock-field"><label class="form-label" for="product-stock">Regular stock</label><input class="form-control" id="product-stock" type="number" min="0" name="stock" value="0" required></div>
                            <div class="form-field size-stock-field" hidden><input type="hidden" class="size-stock-input" id="product-sizes" name="sizes"><button class="btn btn-small" type="button" data-modal-open="size-manager-dialog" data-size-manager="product-sizes">Manage Sizes and Stock</button><p class="form-help" data-size-summary="product-sizes">No size inventory configured.</p></div>
                            <div class="form-field"><label class="form-label" for="product-description">Description</label><textarea class="form-control" id="product-description" name="description" rows="3"></textarea></div>
                            <div class="form-field"><label class="form-label" for="product-image-upload">Upload Image</label><input class="form-control" id="product-image-upload" type="file" name="image_file" accept="image/*" data-image-preview="#product-image-preview" data-image-preview-initially-hidden="true" data-image-resize="true"><p class="form-help">Choose a photo from your device. Large photos are resized before upload; maximum processed size is 3.5 MB.</p><img id="product-image-preview" class="inventory-current-thumb" src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="" hidden></div>
                        </div>
                        <footer class="modal-footer"><button class="btn btn-small" type="button" data-modal-close>Cancel</button><button class="btn" type="submit">Save Product</button></footer>
                    </form>
                </div>
            </dialog>

            <section class="panel">
                <div class="panel-heading"><h2>All Products</h2><span><?php echo number_format($totalProducts); ?> products</span></div>
                <div class="table-wrapper">
                    <table class="table inventory-table">
                        <colgroup>
                            <col class="inventory-col-product">
                            <col class="inventory-col-category">
                            <col class="inventory-col-price">
                            <col class="inventory-col-stock">
                            <col class="inventory-col-status">
                            <col class="inventory-col-actions">
                        </colgroup>
                        <thead><tr><th scope="col">Item / details</th><th scope="col">Category</th><th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (!$products): ?><tr class="inventory-empty-row"><td colspan="6">No inventory items found. Adjust your filters or add a new product.</td></tr><?php endif; ?>
                            <?php foreach ($products as $product): ?>
                                <tr class="<?php echo (int)$product['is_active'] === 1 ? '' : 'is-archived'; ?>">
                                    <td>
                                        <div class="inventory-product">
                                            <img class="inventory-product-image" src="<?php echo cvcarts_h(cvcarts_product_image_url($product['image_url'] ?? '')); ?>" alt="<?php echo cvcarts_h($product['name']); ?>" onerror="this.onerror=null;this.src='<?php echo cvcarts_h(cvcarts_url('/assets/images/default-product.svg')); ?>'">
                                            <div class="inventory-product-copy">
                                                <strong><?php echo cvcarts_h($product['name']); ?></strong>
                                                <?php if (!empty($product['description'])): ?><small><?php echo cvcarts_h($product['description']); ?></small><?php endif; ?>
                                            </div>
                                        </div>
                                        <?php
                                        $sizeLines = [];
                                        foreach ($productSizes[(int)$product['id']] ?? [] as $sizeRow) {
                                            if ((int)$sizeRow['is_active'] === 1) {
                                                $sizeLines[] = $sizeRow['size'] . ':' . (int)$sizeRow['stock'];
                                            }
                                        }
                                        ?>
                                        <dialog id="edit-product-<?php echo (int)$product['id']; ?>" class="app-modal" aria-labelledby="edit-product-title-<?php echo (int)$product['id']; ?>">
                                            <div class="modal-shell">
                                                <header class="modal-header">
                                                    <div>
                                                        <h2 id="edit-product-title-<?php echo (int)$product['id']; ?>">Edit <?php echo cvcarts_h($product['name']); ?></h2>
                                                        <p>Update product details and inventory settings.</p>
                                                    </div>
                                                    <button class="modal-close" type="button" data-modal-close aria-label="Close">×</button>
                                                </header>
                                                <form method="post" enctype="multipart/form-data" action="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>" data-modal-form data-original-sized="<?php echo (int)$product['has_sizes']; ?>">
                                                    <div class="modal-body form-grid">
                                                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                                                        <input type="hidden" name="update_product" value="1"><input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                        <div class="form-field"><label class="form-label" for="product-name-<?php echo (int)$product['id']; ?>">Name</label><input class="form-control" id="product-name-<?php echo (int)$product['id']; ?>" name="name" maxlength="150" value="<?php echo cvcarts_h($product['name']); ?>" required></div>
                                                        <div class="form-field"><label class="form-label" for="product-category-<?php echo (int)$product['id']; ?>">Category</label><input class="form-control" id="product-category-<?php echo (int)$product['id']; ?>" name="category" maxlength="100" value="<?php echo cvcarts_h($product['category']); ?>" required></div>
                                                        <div class="form-field"><label class="form-label" for="product-description-<?php echo (int)$product['id']; ?>">Description</label><textarea class="form-control" id="product-description-<?php echo (int)$product['id']; ?>" name="description" rows="3"><?php echo cvcarts_h($product['description'] ?? ''); ?></textarea></div>
                                                        <div class="form-field"><label class="form-label" for="product-upload-<?php echo (int)$product['id']; ?>">Upload Image</label><input class="form-control" id="product-upload-<?php echo (int)$product['id']; ?>" type="file" name="image_file" accept="image/*" data-image-preview="#product-current-image-<?php echo (int)$product['id']; ?>" data-image-resize="true"><p class="form-help">Choose a photo from your device. Large photos are resized before upload; maximum processed size is 3.5 MB.</p><img id="product-current-image-<?php echo (int)$product['id']; ?>" src="<?php echo cvcarts_h(cvcarts_product_image_url($product['image_url'] ?? '')); ?>" alt="Current <?php echo cvcarts_h($product['name']); ?> image" class="inventory-current-thumb" onerror="this.onerror=null;this.src='<?php echo cvcarts_h(cvcarts_url('/assets/images/default-product.svg')); ?>'"></div>
                                                        <div class="form-field"><label class="form-label" for="product-price-<?php echo (int)$product['id']; ?>">Price</label><input class="form-control" id="product-price-<?php echo (int)$product['id']; ?>" type="number" min="0" step="0.01" name="price" value="<?php echo cvcarts_h((string)$product['price']); ?>" required></div>
                                                        <?php $sizeSelectorId = 'product-has-sizes-' . (int)$product['id']; ?>
                                                        <div class="form-field"><label class="form-label" for="<?php echo $sizeSelectorId; ?>">Does this product have sizes?</label><select class="form-select product-has-sizes" id="<?php echo $sizeSelectorId; ?>" name="has_sizes" data-original-sized="<?php echo (int)$product['has_sizes']; ?>"><option value="0" <?php echo (int)$product['has_sizes'] !== 1 ? 'selected' : ''; ?>>No</option><option value="1" <?php echo (int)$product['has_sizes'] === 1 ? 'selected' : ''; ?>>Yes</option></select></div>
                                                        <div class="form-field regular-stock-field"><label class="form-label" for="product-stock-<?php echo (int)$product['id']; ?>">Regular stock</label><input class="form-control" id="product-stock-<?php echo (int)$product['id']; ?>" type="number" min="0" name="stock" value="<?php echo (int)$product['stock']; ?>" required></div>
                                                        <div class="form-field size-stock-field" hidden><input type="hidden" class="size-stock-input" id="product-sizes-<?php echo (int)$product['id']; ?>" name="sizes" value="<?php echo cvcarts_h(implode("\n", $sizeLines)); ?>"><button class="btn btn-small" type="button" data-modal-open="size-manager-dialog" data-size-manager="product-sizes-<?php echo (int)$product['id']; ?>">Manage Sizes and Stock</button><p class="form-help" data-size-summary="product-sizes-<?php echo (int)$product['id']; ?>"></p></div>
                                                    </div>
                                                     <footer class="modal-footer"><button class="btn btn-small" type="button" data-modal-close>Cancel</button><button class="btn" type="submit">Update Product</button></footer>
                                                </form>
                                            </div>
                                        </dialog>
                                        <dialog id="view-product-<?php echo (int)$product['id']; ?>" class="app-modal" aria-labelledby="view-product-title-<?php echo (int)$product['id']; ?>">
                                            <div class="modal-shell">
                                                <header class="modal-header">
                                                    <div><h2 id="view-product-title-<?php echo (int)$product['id']; ?>"><?php echo cvcarts_h($product['name']); ?></h2><p>Product and inventory details.</p></div>
                                                    <button class="modal-close" type="button" data-modal-close aria-label="Close">×</button>
                                                </header>
                                                <div class="modal-body">
                                                    <div class="summary-grid">
                                                        <div><span>Category</span><strong><?php echo cvcarts_h($product['category']); ?></strong></div>
                                                        <div><span>Price</span><strong><?php echo cvcarts_format_money((float)$product['price']); ?></strong></div>
                                                        <div><span>Availability</span><strong><?php echo (int)$product['is_active'] === 1 ? 'Active' : 'Archived'; ?></strong></div>
                                                        <div><span>Total stock</span><strong><?php echo (int)$product['stock']; ?></strong></div>
                                                    </div>
                                                    <h3>Description</h3>
                                                    <p><?php echo nl2br(cvcarts_h($product['description'] ?: 'No description provided.')); ?></p>
                                                    <?php if ((int)$product['has_sizes'] === 1): ?>
                                                        <h3>Stock by size</h3>
                                                        <div class="table-wrapper"><table><thead><tr><th>Size</th><th>Available quantity</th></tr></thead><tbody>
                                                            <?php foreach ($productSizes[(int)$product['id']] ?? [] as $sizeRow): ?>
                                                                <?php if ((int)$sizeRow['is_active'] === 1): ?><tr><td><?php echo cvcarts_h($sizeRow['size']); ?></td><td><?php echo (int)$sizeRow['stock']; ?></td></tr><?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </tbody></table></div>
                                                    <?php endif; ?>
                                                </div>
                                                <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Close</button></footer>
                                            </div>
                                        </dialog>
                                    </td>
                                    <td><?php echo cvcarts_h($product['category']); ?></td>
                                    <td class="currency-cell"><?php echo cvcarts_format_money((float)$product['price']); ?></td>
                                    <td class="numeric-cell">
                                        <?php if ((int)$product['has_sizes'] === 1): ?>
                                            <details class="inventory-size-details"><summary><?php echo (int)$product['stock']; ?> total stock by size</summary>
                                                <table><thead><tr><th>Size</th><th>Available stock</th></tr></thead><tbody>
                                                <?php foreach ($productSizes[(int)$product['id']] ?? [] as $sizeRow): ?>
                                                    <?php if ((int)$sizeRow['is_active'] === 1): ?>
                                                        <tr><td><?php echo cvcarts_h($sizeRow['size']); ?></td><td><?php echo (int)$sizeRow['stock']; ?></td></tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                </tbody></table>
                                            </details>
                                        <?php else: ?>
                                            <?php echo (int)$product['stock']; ?>
                                        <?php endif; ?>
                                    </td>
                                     <td><?php echo (int)$product['is_active'] !== 1 ? cvcarts_status_badge('Archived') : cvcarts_status_badge((int)$product['stock'] < 10 ? 'Low Stock' : 'In Stock'); ?></td>
                                    <td>
                                        <div class="inventory-row-actions">
                                             <button class="btn btn-small btn-secondary" type="button" data-modal-open="view-product-<?php echo (int)$product['id']; ?>">View Details</button>
                                             <button class="btn btn-small btn-secondary" type="button" data-modal-open="edit-product-<?php echo (int)$product['id']; ?>">Edit</button>
                                             <?php if ((int)$product['is_active'] !== 1): ?>
                                                 <form method="post" action="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>">
                                                     <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                     <button class="btn btn-small" name="restore_product" value="1" type="submit" data-confirm="Restore <?php echo cvcarts_h($product['name']); ?> to the student shop?">Restore</button>
                                                 </form>
                                             <?php endif; ?>
                                             <?php if ((int)$product['is_active'] === 1 || (int)$product['cart_item_count'] > 0): ?>
                                                 <form method="post" action="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php')); ?>">
                                                     <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                     <button class="btn btn-small btn-danger" name="delete_product" value="1" type="submit" data-confirm="Remove this item from the Shop and Cart? Existing orders and inventory history will be preserved.<?php echo (int)$product['cart_item_count'] > 0 ? ' It will be removed from ' . (int)$product['cart_item_count'] . ' active student cart(s).' : ''; ?>" data-confirm-title="Delete product?" data-confirm-label="Delete Product" data-confirm-type="error">Delete</button>
                                                 </form>
                                             <?php endif; ?>
                                         </div>
                                     </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $inventoryParams = [
                    'search' => $search,
                    'category' => $categoryFilter,
                    'stock_status' => $stockFilter,
                    'size_type' => $sizeFilter,
                    'sort' => $sort,
                    'per_page' => $pageSize,
                ];
                ?>
                <nav class="pagination" aria-label="Inventory pages">
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?> · <?php echo number_format($totalProducts); ?> records</span>
                    <div class="pagination-pages">
                        <?php if ($page > 1): ?><a class="btn btn-small" href="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php?' . http_build_query($inventoryParams + ['page' => $page - 1]))); ?>">Previous</a><?php endif; ?>
                        <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
                            <a class="btn btn-small <?php echo $pageNumber === $page ? 'active' : ''; ?>" href="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php?' . http_build_query($inventoryParams + ['page' => $pageNumber]))); ?>" <?php echo $pageNumber === $page ? 'aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a class="btn btn-small" href="<?php echo cvcarts_h(cvcarts_url('/admin/inventory/index.php?' . http_build_query($inventoryParams + ['page' => $page + 1]))); ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            </section>
            <dialog id="size-manager-dialog" class="app-modal" aria-labelledby="size-manager-title">
                <div class="modal-shell">
                    <header class="modal-header">
                        <div>
                            <h2 id="size-manager-title">Product Size and Inventory Configuration</h2>
                            <p>Add standard or custom sizes and set each size's available stock.</p>
                        </div>
                        <button class="modal-close" type="button" data-modal-close aria-label="Close">×</button>
                    </header>
                    <div class="modal-body form-grid">
                        <div class="form-field"><label class="form-label" for="standard-size">Standard size</label><select class="form-select" id="standard-size"><option value="">Choose a size</option><?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'] as $standardSize): ?><option value="<?php echo $standardSize; ?>"><?php echo $standardSize; ?></option><?php endforeach; ?></select></div>
                        <div class="form-field"><label class="form-label" for="standard-stock">Initial stock</label><input class="form-control" id="standard-stock" type="number" min="0" value="0"></div>
                        <div><button class="btn btn-small" id="add-standard-size" type="button">Add Standard Size</button></div>
                        <div class="form-field"><label class="form-label" for="custom-size">Custom size</label><input class="form-control" id="custom-size" maxlength="50" placeholder="e.g. One Size"></div>
                        <div class="form-field"><label class="form-label" for="custom-stock">Initial stock</label><input class="form-control" id="custom-stock" type="number" min="0" value="0"></div>
                        <div><button class="btn btn-small" id="add-custom-size" type="button">Add Custom Size</button></div>
                    </div>
                    <div id="size-manager-error" class="flash-message error" role="alert" hidden></div>
                    <div class="table-wrapper"><table><thead><tr><th>Size</th><th>Quantity</th><th>Action</th></tr></thead><tbody id="size-config-rows"></tbody></table></div>
                    <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn" id="save-size-config" type="button" data-modal-close>Apply Sizes</button></footer>
                </div>
            </dialog>
        </main>
    </div>

    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/image-preview.js?v=' . filemtime(__DIR__ . '/../../assets/js/image-preview.js'))); ?>" defer></script>
    <script>
        window.cvcartsInitInventory = function () {
        const sizeDialog = document.getElementById('size-manager-dialog');
        const sizeRows = document.getElementById('size-config-rows');
        const sizeError = document.getElementById('size-manager-error');
        let activeSizeInput = null;

        function updateSizeSummary(input) {
            const summary = document.querySelector('[data-size-summary="' + input.id + '"]');
            if (!summary) return;
            const count = input.value.split(/\r?\n/).filter(function (line) { return line.trim() !== ''; }).length;
            summary.textContent = count ? count + ' size' + (count === 1 ? '' : 's') + ' configured.' : 'No size inventory configured.';
        }

        function currentSizeConfiguration() {
            return Array.from(sizeRows.querySelectorAll('tr')).map(function (row) {
                const fields = row.querySelectorAll('input');
                return fields[0].value.trim() + ':' + fields[1].value;
            }).join('\n');
        }

        function updateSizeConfigurationSnapshot() {
            sizeDialog.dataset.currentSnapshot = currentSizeConfiguration();
        }

        function addSizeRow(size, stock) {
            const row = document.createElement('tr');
            const sizeCell = document.createElement('td');
            const sizeInput = document.createElement('input');
            sizeInput.className = 'form-control';
            sizeInput.maxLength = 50;
            sizeInput.value = size || '';
            sizeInput.setAttribute('aria-label', 'Size name');
            sizeCell.appendChild(sizeInput);

            const stockCell = document.createElement('td');
            const stockInput = document.createElement('input');
            stockInput.className = 'form-control';
            stockInput.type = 'number';
            stockInput.min = '0';
            stockInput.step = '1';
            stockInput.value = stock === undefined ? '0' : String(stock);
            stockInput.setAttribute('aria-label', 'Stock quantity');
            sizeInput.addEventListener('input', updateSizeConfigurationSnapshot);
            stockInput.addEventListener('input', updateSizeConfigurationSnapshot);
            stockCell.appendChild(stockInput);

            const actionCell = document.createElement('td');
            const removeButton = document.createElement('button');
            removeButton.className = 'btn btn-small';
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.addEventListener('click', function () {
                row.remove();
                updateSizeConfigurationSnapshot();
            });
            actionCell.appendChild(removeButton);
            row.appendChild(sizeCell);
            row.appendChild(stockCell);
            row.appendChild(actionCell);
            sizeRows.appendChild(row);
        }

        function openSizeManager(button) {
            activeSizeInput = document.getElementById(button.dataset.sizeManager);
            if (!activeSizeInput) return;
            sizeDialog.dataset.initialSnapshot = activeSizeInput.value;
            sizeDialog.dataset.currentSnapshot = activeSizeInput.value;
            sizeRows.replaceChildren();
            sizeError.hidden = true;
            activeSizeInput.value.split(/\r?\n/).forEach(function (line) {
                const separator = line.lastIndexOf(':');
                if (separator > 0) addSizeRow(line.slice(0, separator).trim(), line.slice(separator + 1).trim());
            });
        }

        document.querySelectorAll('[data-size-manager]').forEach(function (button) {
            button.addEventListener('click', function () { openSizeManager(button); });
        });
        document.getElementById('add-standard-size').addEventListener('click', function () {
            const select = document.getElementById('standard-size');
            if (!select.value) {
                sizeError.textContent = 'Choose a standard size first.';
                sizeError.hidden = false;
                select.focus();
                return;
            }
            addSizeRow(select.value, document.getElementById('standard-stock').value);
            updateSizeConfigurationSnapshot();
            sizeError.hidden = true;
            select.value = '';
        });
        document.getElementById('add-custom-size').addEventListener('click', function () {
            const input = document.getElementById('custom-size');
            if (!input.value.trim()) {
                sizeError.textContent = 'Enter a custom size name first.';
                sizeError.hidden = false;
                input.focus();
                return;
            }
            addSizeRow(input.value.trim(), document.getElementById('custom-stock').value);
            updateSizeConfigurationSnapshot();
            sizeError.hidden = true;
            input.value = '';
        });
        document.getElementById('save-size-config').addEventListener('click', function () {
            const entries = [];
            const seen = new Set();
            for (const row of sizeRows.querySelectorAll('tr')) {
                const fields = row.querySelectorAll('input');
                const name = fields[0].value.trim();
                const stock = fields[1].value;
                const key = name.toLocaleLowerCase();
                if (!name || name.length > 50 || !/^\d+$/.test(stock) || seen.has(key)) {
                    sizeError.textContent = 'Enter unique size names and non-negative whole-number quantities.';
                    sizeError.hidden = false;
                    return;
                }
                seen.add(key);
                entries.push(name + ':' + stock);
            }
            if (!entries.length) {
                sizeError.textContent = 'Add at least one size before saving the configuration.';
                sizeError.hidden = false;
                return;
            }
            if (entries.length > 40) {
                sizeError.textContent = 'A product may have no more than 40 sizes.';
                sizeError.hidden = false;
                return;
            }
            if (activeSizeInput) {
                activeSizeInput.value = entries.join('\n');
                updateSizeSummary(activeSizeInput);
                sizeDialog.dataset.initialSnapshot = activeSizeInput.value;
                sizeDialog.dataset.currentSnapshot = activeSizeInput.value;
            }
        });

        document.querySelectorAll('select[name="has_sizes"]').forEach(function (select) {
            const form = select.form;
            const sizeField = form.querySelector('.size-stock-field');
            const regularStockField = form.querySelector('.regular-stock-field');
            const stockInput = regularStockField.querySelector('input[name="stock"]');
            const originalSized = select.dataset.originalSized || form.dataset.originalSized || '0';
            function updateSizeFields() {
                const hasSizes = select.value === '1';
                sizeField.hidden = !hasSizes;
                regularStockField.hidden = hasSizes;
                stockInput.disabled = hasSizes;
                stockInput.required = !hasSizes;
                if (originalSized === '1' && !hasSizes) {
                    form.dataset.confirm = 'Changing this product to regular inventory will deactivate its size options. Historical order sizes will be retained. Continue?';
                } else {
                    delete form.dataset.confirm;
                }
            }
            select.addEventListener('change', updateSizeFields);
            updateSizeFields();
        });
        document.querySelectorAll('.size-stock-input').forEach(updateSizeSummary);
        };
        window.cvcartsInitInventory();
        document.addEventListener('cvcarts:content-updated', window.cvcartsInitInventory);
    </script>
</body>
</html>
