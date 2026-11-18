<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/migrations.php';

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

cvcarts_migrate($pdo);

$email = CVCARTS_ADMIN_EMAIL;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Set a valid ADMIN_EMAIL in .env before initializing the administrator.\n");
    exit(1);
}

$existing = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
$existing->execute(['email' => $email]);
$account = $existing->fetch(PDO::FETCH_ASSOC);
if ($account) {
    if ($account['role'] === 'admin') {
        fwrite(STDOUT, "An administrator already exists for ADMIN_EMAIL. No account or password was changed.\n");
        exit(0);
    }
    fwrite(STDERR, "ADMIN_EMAIL is already used by a non-admin account. No account was changed.\n");
    exit(1);
}

$password = bin2hex(random_bytes(24));
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    throw new RuntimeException('Administrator password hashing failed.');
}

$username = 'admin';
$usernameExists = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
for ($suffix = 1; ; $suffix++) {
    $usernameExists->execute(['username' => $username]);
    if (!$usernameExists->fetchColumn()) {
        break;
    }
    $username = 'admin' . $suffix;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO users (username, first_name, last_name, email, password_hash, role, is_verified, is_active, must_change_password, account_status, registration_status, email_verified_at) VALUES (:username, 'CVCarts', 'Administrator', :email, :password_hash, 'admin', 1, 1, 1, 'active', 'completed', NOW())")
        ->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, "Administrator created for {$email}.\n");
fwrite(STDOUT, "One-time temporary password: {$password}\n");
fwrite(STDOUT, "Store it securely and change it immediately after signing in. It will not be shown again.\n");
