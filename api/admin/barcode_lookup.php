<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
cvcarts_require_login('admin');
$db = cvcarts_db();
$barcode = trim((string)($_GET['barcode'] ?? ''));
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $barcode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid barcode or SKU.']);
    exit;
}
$stmt = $db->prepare('SELECT id, name, category, barcode, price, stock, has_sizes, image_url FROM products WHERE barcode = :barcode AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
$stmt->execute(['barcode' => $barcode]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product Not Found']);
    exit;
}
$product['image_url'] = cvcarts_product_image_url($product['image_url']);
echo json_encode(['success' => true, 'product' => $product]);
