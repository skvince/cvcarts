<?php
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvcarts_barcode_response(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    cvcarts_barcode_response(405, ['success' => false, 'message' => 'Only GET requests are allowed.']);
}

try {
    $user = cvcarts_user();
    if (!$user) {
        cvcarts_barcode_response(401, ['success' => false, 'message' => 'Please sign in again.']);
    }
    if ($user['role'] !== 'admin') {
        cvcarts_barcode_response(403, ['success' => false, 'message' => 'Administrator access is required.']);
    }
    if ((int)$user['must_change_password'] === 1) {
        cvcarts_barcode_response(403, ['success' => false, 'message' => 'Change the temporary administrator password before scanning inventory.']);
    }

    $barcode = $_GET['barcode'] ?? null;
    if (!is_string($barcode)) {
        cvcarts_barcode_response(400, ['success' => false, 'message' => 'Enter a valid barcode or SKU.']);
    }
    $barcode = trim($barcode);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $barcode)) {
        cvcarts_barcode_response(400, ['success' => false, 'message' => 'Enter a valid barcode or SKU.']);
    }

    $db = cvcarts_db();
    $stmt = $db->prepare('SELECT id, name, category, barcode, price, stock, has_sizes, image_url FROM products WHERE barcode = :barcode AND is_active = 1 AND deleted_at IS NULL LIMIT 2');
    $stmt->execute(['barcode' => $barcode]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($products) > 1) {
        cvcarts_barcode_response(409, [
            'success' => false,
            'message' => 'This barcode is assigned to multiple products. Correct the inventory barcodes before scanning again.',
        ]);
    }
    if (!$products) {
        cvcarts_barcode_response(404, ['success' => false, 'message' => 'Product Not Found']);
    }

    $product = $products[0];
    $product['image_url'] = cvcarts_product_image_url($product['image_url']);
    $product['sizes'] = [];
    if ((int)$product['has_sizes'] === 1) {
        $sizeQuery = $db->prepare('SELECT size, stock FROM product_sizes WHERE product_id = :product_id AND is_active = 1 ORDER BY size');
        $sizeQuery->execute(['product_id' => $product['id']]);
        $product['sizes'] = $sizeQuery->fetchAll(PDO::FETCH_ASSOC);
    }
    cvcarts_barcode_response(200, ['success' => true, 'product' => $product]);
} catch (Throwable $e) {
    error_log('CVCarts admin barcode lookup failed: ' . $e->getMessage());
    cvcarts_barcode_response(500, ['success' => false, 'message' => 'Inventory could not be checked. Please try again.']);
}
