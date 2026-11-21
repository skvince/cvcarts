<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/migrations.php';

function cvcarts_db(): PDO {
    static $migrated = false;
    global $pdo;
    require_database_connection();
    if (!$migrated) {
        cvcarts_migrate($pdo);
        $migrated = true;
    }
    return $pdo;
}

function cvcarts_redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function cvcarts_url(string $path): string {
    return CVCARTS_BASE_PATH . '/' . ltrim($path, '/');
}

function cvcarts_favicon_tags(): string {
    $iconUrl = cvcarts_h(cvcarts_url('/assets/images/logo.png'));
    return '<link rel="icon" type="image/png" href="' . $iconUrl . '">' . PHP_EOL
        . '<link rel="shortcut icon" type="image/png" href="' . $iconUrl . '">' . PHP_EOL
        . '<link rel="apple-touch-icon" href="' . $iconUrl . '">';
}

function cvcarts_student_profile_menu(array $user): string {
    $firstName = cvcarts_h((string)($user['first_name'] ?? ''));
    $fullName = cvcarts_h(trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')));
    $email = cvcarts_h((string)($user['email'] ?? ''));
    $profileImage = cvcarts_h(cvcarts_profile_image_url(
        is_string($user['profile_image'] ?? null) ? $user['profile_image'] : null
    ));
    $settingsUrl = cvcarts_h(cvcarts_url('/student/settings/index.php'));

    return '<details class="header-menu profile-menu">'
        . '<summary class="admin-profile" aria-label="Open account menu">'
        . '<img src="' . $profileImage . '" alt="">'
        . '<span>' . $firstName . '</span>'
        . '</summary>'
        . '<div class="header-dropdown profile-dropdown">'
        . '<div class="dropdown-title profile-title">'
        . '<strong>' . $fullName . '</strong>'
        . '<span>' . $email . '</span>'
        . '</div>'
        . '<a class="dropdown-item" href="' . $settingsUrl . '">'
        . '<span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-gear"></i></span>'
        . '<span><strong>Settings</strong><small>Profile, password, and recovery</small></span>'
        . '</a>'
        . '</div>'
        . '</details>';
}

function cvcarts_flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function cvcarts_get_flash(): array {
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function cvcarts_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function cvcarts_require_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(419);
        exit('The form expired. Please reload the page and try again.');
    }
}

function cvcarts_normalize_answer(string $answer): string {
    $answer = trim($answer);
    if (function_exists('mb_strtolower')) {
        $answer = mb_strtolower($answer, 'UTF-8');
    } else {
        $answer = strtolower($answer);
    }
    return preg_replace('/\s+/u', ' ', $answer) ?? $answer;
}

function cvcarts_password_is_strong(string $password): bool {
    return strlen($password) >= 12
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function cvcarts_rate_limit_key(): string {
    $appKey = cvcarts_env('APP_KEY');
    if (is_string($appKey) && strlen($appKey) >= 32) {
        return $appKey;
    }
    if (defined('CVCARTS_APP_ENV') && CVCARTS_APP_ENV !== 'local' && CVCARTS_APP_ENV !== 'development') {
        throw new RuntimeException('APP_KEY must contain at least 32 characters in production.');
    }
    return 'cvcarts-local-development';
}

function cvcarts_rate_limited(string $action, string $identifier, int $identifierLimit, int $ipLimit, int $windowMinutes, bool $failuresOnly = false): bool {
    $db = cvcarts_db();
    $identifierHash = hash('sha256', strtolower(trim($identifier)));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ipHash = hash_hmac('sha256', $ip, cvcarts_rate_limit_key());
    $statusFilter = $failuresOnly ? ' AND succeeded = 0' : '';
    $sql = 'SELECT COUNT(*) FROM auth_attempts WHERE action = :action AND created_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)';
    $byIdentifier = $db->prepare($sql . ' AND identifier_hash = :key_hash' . $statusFilter);
    $byIdentifier->bindValue('action', $action);
    $byIdentifier->bindValue('window', $windowMinutes, PDO::PARAM_INT);
    $byIdentifier->bindValue('key_hash', $identifierHash);
    $byIdentifier->execute();
    if ((int)$byIdentifier->fetchColumn() >= $identifierLimit) {
        return true;
    }

    $byIp = $db->prepare($sql . ' AND ip_hash = :key_hash' . $statusFilter);
    $byIp->bindValue('action', $action);
    $byIp->bindValue('window', $windowMinutes, PDO::PARAM_INT);
    $byIp->bindValue('key_hash', $ipHash);
    $byIp->execute();
    return (int)$byIp->fetchColumn() >= $ipLimit;
}

function cvcarts_record_auth_attempt(string $action, string $identifier, bool $succeeded): int {
    $db = cvcarts_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db->prepare('INSERT INTO auth_attempts (action, identifier_hash, ip_hash, succeeded) VALUES (:action, :identifier_hash, :ip_hash, :succeeded)')
        ->execute([
            'action' => $action,
            'identifier_hash' => hash('sha256', strtolower(trim($identifier))),
            'ip_hash' => hash_hmac('sha256', $ip, cvcarts_rate_limit_key()),
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    return (int)$db->lastInsertId();
}

function cvcarts_issue_token(int $userId, string $type, int $lifetimeSeconds): string {
    $db = cvcarts_db();
    $rawToken = bin2hex(random_bytes(32));
    cvcarts_store_token($userId, $type, $rawToken, $lifetimeSeconds);
    return $rawToken;
}

function cvcarts_store_token(int $userId, string $type, string $rawToken, int $lifetimeSeconds): void {
    $db = cvcarts_db();
    $tokenHash = hash('sha256', $rawToken);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE account_tokens SET used_at = COALESCE(used_at, NOW()) WHERE user_id = :user_id AND token_type = :token_type AND used_at IS NULL')
            ->execute(['user_id' => $userId, 'token_type' => $type]);
        $db->prepare('INSERT INTO account_tokens (user_id, token_type, token_hash, expires_at) VALUES (:user_id, :token_type, :token_hash, DATE_ADD(NOW(), INTERVAL :lifetime SECOND))')
            ->execute(['user_id' => $userId, 'token_type' => $type, 'token_hash' => $tokenHash, 'lifetime' => $lifetimeSeconds]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function cvcarts_app_url(string $path): string {
    return CVCARTS_BASE_URL . '/' . ltrim($path, '/');
}

function cvcarts_mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return '***';
    }
    $local = $parts[0];
    $visible = function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : substr($local, 0, 1);
    return $visible . str_repeat('*', max(3, strlen($local) - 1)) . '@' . $parts[1];
}

function cvcarts_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cvcarts_format_money(float $amount): string {
    return '₱' . number_format($amount, 2, '.', ',');
}

function cvcarts_emit_realtime_event(PDO $db, string $eventType, string $audience, ?int $recipientUserId = null): void {
    if (!preg_match('/^[a-z][a-z0-9.]{1,63}$/', $eventType)
        || !in_array($audience, ['admin', 'student', 'public'], true)
        || ($audience === 'student' && (!$recipientUserId || $recipientUserId < 1))) {
        throw new InvalidArgumentException('Invalid real-time event metadata.');
    }

    $insert = $db->prepare('INSERT INTO realtime_events (event_type, audience, recipient_user_id) VALUES (:event_type, :audience, :recipient_user_id)');
    $insert->execute([
        'event_type' => $eventType,
        'audience' => $audience,
        'recipient_user_id' => $audience === 'student' ? $recipientUserId : null,
    ]);
    $eventId = (int)$db->lastInsertId();
    if ($eventId > 0 && $eventId % 1000 === 0) {
        $db->exec('DELETE FROM realtime_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 5000');
    }
}

function cvcarts_add_admin_notification(PDO $db, string $title, string $message, ?int $orderId = null): void {
    $adminQuery = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND account_status = 'active'");
    $admins = $adminQuery->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        $check = $db->prepare('SELECT id FROM notifications WHERE user_id = :user_id AND title = :title AND message = :message AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
        $check->execute(['user_id' => (int)$adminId, 'title' => $title, 'message' => $message]);
        if ($check->fetchColumn() !== false) {
            continue;
        }
        $db->prepare('INSERT INTO notifications (user_id, title, message, related_order_id) VALUES (:user_id, :title, :message, :order_id)')
            ->execute(['user_id' => (int)$adminId, 'title' => $title, 'message' => $message, 'order_id' => $orderId]);
    }
    if ($admins) {
        cvcarts_emit_realtime_event($db, 'notifications.updated', 'admin');
    }
}

function cvcarts_admin_notification_count(PDO $db, ?int $adminId = null): int {
    if ($adminId !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute(['user_id' => $adminId]);
        return (int)$stmt->fetchColumn();
    }
    $stmt = $db->query("SELECT COUNT(*) FROM notifications n INNER JOIN users u ON u.id = n.user_id WHERE u.role = 'admin' AND n.is_read = 0");
    return (int)$stmt->fetchColumn();
}

function cvcarts_sync_stock_notifications(PDO $db): void {
    $query = $db->query(
        "SELECT p.id, p.name, p.barcode, p.stock, p.has_sizes, p.deleted_at,
                (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id AND is_active = 1) AS sized_stock
         FROM products p WHERE p.is_active = 1 AND p.deleted_at IS NULL"
    );
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $stock = (int)$product['has_sizes'] === 1 ? (int)$product['sized_stock'] : (int)$product['stock'];
        $name = trim((string)$product['name']);
        $sku = trim((string)($product['barcode'] ?? ''));
        $identity = $name . ($sku !== '' ? ' (SKU: ' . $sku . ')' : '');
        if ($stock === 0) {
            cvcarts_add_admin_notification($db, 'Out of Stock', $identity . ' is now out of stock (0 remaining).');
        } elseif ($stock < 10) {
            cvcarts_add_admin_notification($db, 'Low Stock Alert', $identity . ' has ' . $stock . ' remaining and is below the 10-unit threshold.');
        }
    }
}

function cvcarts_store_payment_proof(array $upload): string {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($upload['tmp_name'], $upload['size'])
        || !is_string($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])
        || (int)$upload['size'] < 1
        || (int)$upload['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Payment proof must be a valid file no larger than 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $allowedMimeTypes = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'application/pdf' => '.pdf'];
    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Payment proof must be a JPG, JPEG, PNG, WEBP, or PDF file.');
    }

    $directory = __DIR__ . '/../uploads/payment-proofs';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Payment proof storage is not available. Please try again later.');
    }

    $filename = bin2hex(random_bytes(16)) . $allowedMimeTypes[$mimeType];
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $target)) {
        throw new RuntimeException('Payment proof could not be saved. Please try again.');
    }
    return $filename;
}

function cvcarts_product_image_url(?string $imageUrl): string {
    $value = trim((string)($imageUrl ?? ''));
    if ($value === '') {
        return cvcarts_url('/assets/images/default-product.svg');
    }

    if (preg_match('/^https?:\/\//i', $value) === 1) {
        return $value;
    }

    return cvcarts_url($value);
}

function cvcarts_store_product_image(array $upload, ?string $existingImage = null): string {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($upload['tmp_name'], $upload['size'])
        || !is_string($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])
        || (int)$upload['size'] < 1
        || (int)$upload['size'] > 3584 * 1024) {
        throw new RuntimeException('Product image must be a valid file no larger than 3.5 MB (3584 KB).');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $allowedMimeTypes = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
    $imageInfo = @getimagesize($upload['tmp_name']);
    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])
        || !is_array($imageInfo) || !isset($imageInfo['mime']) || $imageInfo['mime'] !== $mimeType) {
        throw new RuntimeException('Product image must be a JPG, JPEG, PNG, or WEBP file.');
    }

    $directory = __DIR__ . '/../uploads/products';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Product upload directory is not writable.');
    }

    $filename = bin2hex(random_bytes(16)) . $allowedMimeTypes[$mimeType];
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $target)) {
        throw new RuntimeException('The product image could not be saved.');
    }

    return '/uploads/products/' . $filename;
}

function cvcarts_remove_product_image(?string $imageUrl): void {
    $path = trim((string)($imageUrl ?? ''));
    if (!preg_match('~^/uploads/products/[a-f0-9]{32}\.(?:jpg|png|webp)$~i', $path)) {
        return;
    }
    $filePath = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

function cvcarts_store_profile_image(array $upload): string {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($upload['tmp_name'], $upload['size'])
        || !is_string($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])
        || (int)$upload['size'] < 1
        || (int)$upload['size'] > 3584 * 1024) {
        throw new RuntimeException('Choose a valid profile image no larger than 3.5 MB (3584 KB).');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $allowedMimeTypes = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
    $imageInfo = @getimagesize($upload['tmp_name']);
    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])
        || !is_array($imageInfo) || !isset($imageInfo['mime']) || $imageInfo['mime'] !== $mimeType) {
        throw new RuntimeException('Profile image must be a JPG, PNG, or WEBP image.');
    }

    $directory = __DIR__ . '/../uploads/profile-photos';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Profile image storage is not available. Please try again later.');
    }

    $filename = bin2hex(random_bytes(16)) . $allowedMimeTypes[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Your profile image could not be saved. Please try again.');
    }

    return '/uploads/profile-photos/' . $filename;
}

function cvcarts_profile_image_url(?string $imagePath): string {
    $path = trim((string)($imagePath ?? ''));
    if (preg_match('~^/uploads/profile-photos/[a-f0-9]{32}\.(?:jpg|png|webp)$~i', $path) !== 1) {
        return cvcarts_url('/assets/images/default-student-avatar.svg');
    }

    return cvcarts_url($path);
}

function cvcarts_remove_profile_image(?string $imagePath): void {
    $path = trim((string)($imagePath ?? ''));
    if (preg_match('~^/uploads/profile-photos/[a-f0-9]{32}\.(?:jpg|png|webp)$~i', $path) !== 1) {
        return;
    }

    $filePath = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('CVCarts could not remove a replaced profile image.');
    }
}

function cvcarts_user(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $db = cvcarts_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && !isset($_SESSION['auth_version'])) {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        return null;
    }
    if ($user && (int)$_SESSION['auth_version'] !== (int)$user['auth_version']) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['auth_version']);
        return null;
    }
    if ($user && ((int)$user['is_active'] !== 1 || $user['account_status'] !== 'active')) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['auth_version']);
        return null;
    }
    return $user ?: null;
}

function cvcarts_require_login(?string $role = null): void {
    $user = cvcarts_user();
    if (!$user) {
        cvcarts_flash('error', 'Please log in to continue.');
        cvcarts_redirect(cvcarts_url('/index.php'));
    }

    if ($role && $user['role'] !== $role) {
        cvcarts_flash('error', 'You do not have permission to view that page.');
        cvcarts_redirect(cvcarts_url('/index.php'));
    }

    $currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isAllowedDuringPasswordChange = preg_match('~/(?:admin/settings/index\.php|auth/logout\.php)$~', $currentScript) === 1;
    if ($user['role'] === 'admin' && (int)$user['must_change_password'] === 1 && !$isAllowedDuringPasswordChange) {
        cvcarts_flash('warning', 'Change the temporary admin password to continue.');
        cvcarts_redirect(cvcarts_url('/admin/settings/index.php?force=1'));
    }
}

function cvcarts_cart_count(): int {
    if (!isset($_SESSION['user_id'])) {
        return 0;
    }

    $db = cvcarts_db();
    $stmt = $db->prepare('SELECT SUM(quantity) AS total FROM cart_items WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0);
}

function cvcarts_order_reference(): string {
    return 'CC-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('Ymd');
}

function cvcarts_order_qr_nonce(): string {
    return bin2hex(random_bytes(16));
}

function cvcarts_order_qr_token(int $orderId, string $nonce): string {
    $appKey = cvcarts_env('APP_KEY');
    if ($orderId < 1 || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
        throw new InvalidArgumentException('A valid order ID and QR nonce are required.');
    }
    if (!is_string($appKey) || strlen($appKey) < 32) {
        throw new RuntimeException('APP_KEY must contain at least 32 characters to secure order QR codes.');
    }
    return hash_hmac('sha256', 'cvcarts-order-claim-v1:' . $orderId . ':' . $nonce, $appKey);
}

function cvcarts_order_qr_value(array $order): string {
    $legacyToken = strtolower(trim((string)($order['qr_token'] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/', $legacyToken)) {
        return $legacyToken;
    }
    return cvcarts_order_qr_token((int)($order['id'] ?? 0), (string)($order['qr_token_nonce'] ?? ''));
}

function cvcarts_order_qr_display_value(array $order): ?string {
    if (($order['payment_status'] ?? null) !== 'Paid' || ($order['order_status'] ?? null) === 'Cancelled') {
        return null;
    }

    $legacyToken = strtolower(trim((string)($order['qr_token'] ?? '')));
    if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $legacyToken)) {
        return $legacyToken;
    }

    $nonce = (string)($order['qr_token_nonce'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
        return null;
    }

    $token = cvcarts_order_qr_token((int)($order['id'] ?? 0), $nonce);
    $tokenHash = $order['qr_token_hash'] ?? null;
    return is_string($tokenHash) && hash_equals($tokenHash, hash('sha256', $token)) ? $token : null;
}

function cvcarts_ensure_order_qr_token(PDO $db, int $orderId): void {
    $query = $db->prepare('SELECT id, payment_status, qr_token, qr_token_hash, qr_token_nonce FROM orders WHERE id = :id FOR UPDATE');
    $query->execute(['id' => $orderId]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['payment_status'] !== 'Paid') {
        throw new RuntimeException('A paid order is required before issuing a claiming QR code.');
    }

    $legacyToken = strtolower(trim((string)($order['qr_token'] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/', $legacyToken)) {
        $legacyHash = hash('sha256', $legacyToken);
        if (!is_string($order['qr_token_hash']) || !hash_equals($order['qr_token_hash'], $legacyHash)) {
            $db->prepare('UPDATE orders SET qr_token_hash = :token_hash WHERE id = :id')
                ->execute(['token_hash' => $legacyHash, 'id' => $orderId]);
        }
        return;
    }

    $nonce = (string)($order['qr_token_nonce'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $nonce)) {
        $expectedHash = hash('sha256', cvcarts_order_qr_token($orderId, $nonce));
        if (is_string($order['qr_token_hash']) && hash_equals($order['qr_token_hash'], $expectedHash)) {
            return;
        }
    } else {
        $nonce = cvcarts_order_qr_nonce();
    }

    $token = cvcarts_order_qr_token($orderId, $nonce);
    $db->prepare('UPDATE orders SET qr_token_nonce = :nonce, qr_token_hash = :token_hash WHERE id = :id')
        ->execute(['nonce' => $nonce, 'token_hash' => hash('sha256', $token), 'id' => $orderId]);
}

function cvcarts_parse_size_stock_list(string $input): array {
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    $entries = [];
    $seen = [];
    foreach (preg_split('/\R/u', $input) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^(.{1,50}?)\s*:\s*(\d+)$/u', $line, $matches)) {
            throw new InvalidArgumentException('Enter each size and stock on its own line as Size:Stock.');
        }
        $size = trim($matches[1]);
        $stock = filter_var($matches[2], FILTER_VALIDATE_INT);
        $sizeKey = function_exists('mb_strtolower') ? mb_strtolower($size, 'UTF-8') : strtolower($size);
        if ($size === '' || $stock === false || $stock < 0 || isset($seen[$sizeKey])) {
            throw new InvalidArgumentException('Sizes must be unique, non-empty names with non-negative whole-number stock.');
        }
        $seen[$sizeKey] = true;
        $entries[] = ['size' => $size, 'stock' => $stock];
        if (count($entries) > 40) {
            throw new InvalidArgumentException('A product may have no more than 40 sizes.');
        }
    }
    return $entries;
}

function cvcarts_record_inventory_movement(PDO $db, int $productId, ?string $size, ?int $orderId, ?int $adminId, int $change, string $reason): void {
    if ($change === 0) {
        return;
    }
    $db->prepare('INSERT INTO inventory_movements (product_id, selected_size, order_id, admin_id, quantity_change, reason) VALUES (:product_id, :size, :order_id, :admin_id, :quantity_change, :reason)')
        ->execute([
            'product_id' => $productId,
            'size' => $size,
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'quantity_change' => $change,
            'reason' => $reason,
        ]);
}

function cvcarts_status_class(string $status): string {
    static $map = [
        'active' => 'status-ready',
        'paid' => 'status-ready',
        'verified' => 'status-done',
        'completed' => 'status-done',
        'ready to claim' => 'status-info',
        'in transit' => 'status-info',
        'claimed' => 'status-done',
        'already received' => 'status-done',
        'pending' => 'status-pending',
        'unpaid' => 'status-pending',
        'pending verification' => 'status-pending',
        'payment verification' => 'status-pending',
        'pending_verification' => 'status-pending',
        'rejected' => 'status-unavailable',
        'payment rejected' => 'status-unavailable',
        'cancelled' => 'status-unavailable',
        'suspended' => 'status-unavailable',
        'out of stock' => 'status-alert',
        'archived' => 'status-unavailable',
        'low stock' => 'status-alert',
        'in stock' => 'status-ready',
    ];

    return $map[strtolower(trim($status))] ?? 'status-pending';
}

function cvcarts_status_badge(string $status): string {
    return '<span class="status ' . cvcarts_status_class($status) . '">' . cvcarts_h($status) . '</span>';
}

function cvcarts_low_stock_alerts(PDO $db): array {
    $query = $db->query(
        "SELECT p.name AS product_name, p.barcode AS barcode, NULL AS size, p.stock AS stock
         FROM products p
         WHERE p.is_active = 1 AND p.has_sizes = 0 AND p.stock < 10
         UNION ALL
         SELECT p.name AS product_name, p.barcode AS barcode, ps.size AS size, ps.stock AS stock
         FROM product_sizes ps
         INNER JOIN products p ON p.id = ps.product_id
         WHERE p.is_active = 1 AND ps.is_active = 1 AND ps.stock < 10
         ORDER BY stock ASC, product_name ASC"
    );

    return array_map(static function (array $row): array {
        $row['label'] = trim(($row['product_name'] ?? '') . (isset($row['size']) && $row['size'] !== null ? ' - ' . $row['size'] : ''));
        return $row;
    }, $query->fetchAll(PDO::FETCH_ASSOC));
}

function cvcarts_sales_series(PDO $db, string $period): array {
    $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'month';
    $buckets = [];
    $labels = [];

    if ($period === 'week') {
        for ($index = 6; $index >= 0; $index--) {
            $day = new DateTimeImmutable('-' . $index . ' days');
            $key = $day->format('Y-m-d');
            $buckets[$key] = 0.0;
            $labels[$key] = $day->format('D');
        }
        $sql = "SELECT DATE(created_at) AS bucket, COALESCE(SUM(total_amount), 0) AS amount
                FROM orders
                WHERE payment_status = 'Paid' AND order_status <> 'Cancelled'
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(created_at)";
    } else {
        $months = $period === 'year' ? 12 : 6;
        for ($index = $months - 1; $index >= 0; $index--) {
            $month = new DateTimeImmutable('first day of this month -' . $index . ' months');
            $key = $month->format('Y-m');
            $buckets[$key] = 0.0;
            $labels[$key] = $month->format('M');
        }
        $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(total_amount), 0) AS amount
                FROM orders
                WHERE payment_status = 'Paid' AND order_status <> 'Cancelled'
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . $months . " MONTH)
                GROUP BY YEAR(created_at), MONTH(created_at)";
    }

    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['bucket'];
        if (array_key_exists($key, $buckets)) {
            $buckets[$key] = (float)$row['amount'];
        }
    }

    $seriesLabels = [];
    $seriesValues = [];
    $total = 0.0;
    foreach ($buckets as $key => $amount) {
        $seriesLabels[] = $labels[$key];
        $seriesValues[] = round($amount, 2);
        $total += $amount;
    }

    return [
        'period' => $period,
        'labels' => $seriesLabels,
        'values' => $seriesValues,
        'total' => round($total, 2),
    ];
}

function cvcarts_update_product_inventory(PDO $db, int $productId, bool $hasSizes, int $regularStock, array $sizes, int $adminId): void {
    if ($hasSizes && !$sizes) {
        throw new InvalidArgumentException('Add at least one size and its stock for a sized product.');
    }

    $productQuery = $db->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
    $productQuery->execute(['id' => $productId]);
    $currentStock = $productQuery->fetchColumn();
    if ($currentStock === false) {
        throw new RuntimeException('The product no longer exists.');
    }

    if (!$hasSizes) {
        $db->prepare('UPDATE product_sizes SET is_active = 0 WHERE product_id = :product_id')
            ->execute(['product_id' => $productId]);
        $db->prepare('UPDATE products SET has_sizes = 0, stock = :stock WHERE id = :id')
            ->execute(['stock' => $regularStock, 'id' => $productId]);
        cvcarts_record_inventory_movement($db, $productId, null, null, $adminId, $regularStock - (int)$currentStock, 'admin_adjustment');
        $nameQuery = $db->prepare('SELECT name FROM products WHERE id = :id');
        $nameQuery->execute(['id' => $productId]);
        $productName = (string)$nameQuery->fetchColumn();
        $change = $regularStock - (int)$currentStock;
        if ($change !== 0) {
            cvcarts_add_admin_notification($db, 'Inventory Updated', $productName . ' stock was adjusted by ' . ($change > 0 ? '+' : '') . $change . ' to ' . $regularStock . ' remaining.');
        }
        cvcarts_sync_stock_notifications($db);
        return;
    }

    $db->prepare('UPDATE product_sizes SET is_active = 0 WHERE product_id = :product_id')
        ->execute(['product_id' => $productId]);
    foreach ($sizes as $entry) {
        $sizeQuery = $db->prepare('SELECT id, stock FROM product_sizes WHERE product_id = :product_id AND size = :size LIMIT 1 FOR UPDATE');
        $sizeQuery->execute(['product_id' => $productId, 'size' => $entry['size']]);
        $existing = $sizeQuery->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $db->prepare('UPDATE product_sizes SET stock = :stock, is_active = 1 WHERE id = :id')
                ->execute(['stock' => $entry['stock'], 'id' => $existing['id']]);
            cvcarts_record_inventory_movement(
                $db,
                $productId,
                $entry['size'],
                null,
                $adminId,
                (int)$entry['stock'] - (int)$existing['stock'],
                'admin_adjustment'
            );
        } else {
            $db->prepare('INSERT INTO product_sizes (product_id, size, stock, is_active) VALUES (:product_id, :size, :stock, 1)')
                ->execute(['product_id' => $productId, 'size' => $entry['size'], 'stock' => $entry['stock']]);
            cvcarts_record_inventory_movement($db, $productId, $entry['size'], null, $adminId, (int)$entry['stock'], 'initial_stock');
        }
    }

    $db->prepare('UPDATE products SET has_sizes = 1, stock = (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = :size_product_id AND is_active = 1) WHERE id = :product_id')
        ->execute(['size_product_id' => $productId, 'product_id' => $productId]);
    $nameQuery = $db->prepare('SELECT name FROM products WHERE id = :id');
    $nameQuery->execute(['id' => $productId]);
    $productName = (string)$nameQuery->fetchColumn();
    $stockQuery = $db->prepare('SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = :product_id AND is_active = 1');
    $stockQuery->execute(['product_id' => $productId]);
    cvcarts_add_admin_notification($db, 'Inventory Updated', $productName . ' size inventory was adjusted; total stock is now ' . (int)$stockQuery->fetchColumn() . ' remaining.');
    cvcarts_sync_stock_notifications($db);
}
