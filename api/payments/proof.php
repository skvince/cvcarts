<?php
require_once __DIR__ . '/../../includes/auth.php';
cvcarts_require_login();

$user = cvcarts_user();
$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    http_response_code(400);
    exit('A valid order ID is required.');
}

$db = cvcarts_db();
$query = $db->prepare(
    'SELECT o.user_id, p.proof_path
     FROM orders o
     INNER JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
     WHERE o.id = :order_id'
);
$query->execute(['order_id' => $orderId]);
$payment = $query->fetch(PDO::FETCH_ASSOC);
if (!$payment || (!$user || ($user['role'] !== 'admin' && (int)$payment['user_id'] !== (int)$user['id']))) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$storedPath = (string)($payment['proof_path'] ?? '');
$filename = basename(str_replace('\\', '/', $storedPath));
if ($filename === '' || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|pdf)$/', $filename)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$proofDirectory = realpath(__DIR__ . '/../../uploads/payment-proofs');
$proofPath = $proofDirectory === false ? false : realpath($proofDirectory . DIRECTORY_SEPARATOR . $filename);
if ($proofDirectory === false || $proofPath === false || dirname($proofPath) !== $proofDirectory || !is_file($proofPath)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($proofPath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
    http_response_code(415);
    exit('Unsupported payment proof format.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($proofPath));
header('Content-Disposition: inline; filename="payment-proof.' . ($mimeType === 'application/pdf' ? 'pdf' : ($mimeType === 'image/png' ? 'png' : 'jpg')) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($proofPath);
