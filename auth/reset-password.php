<?php
require __DIR__ . '/../includes/auth.php';
$db = cvcarts_db();
$token = $_POST['token'] ?? $_GET['token'] ?? '';
$tokenValid = false;
$accountId = 0;
$accountRole = '';
$tokenType = '';

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $statement = $db->prepare("SELECT t.user_id, t.token_type, u.role FROM account_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :token_hash AND t.token_type IN ('admin_password_reset', 'student_password_reset')
        AND t.used_at IS NULL AND t.expires_at > NOW() AND u.role IN ('admin', 'student')
        AND u.is_active = 1 AND u.account_status = 'active'
        AND (u.role <> 'student' OR (u.is_verified = 1 AND u.email_verified_at IS NOT NULL)) LIMIT 1");
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        $accountId = (int)$account['user_id'];
        $accountRole = $account['role'];
        $tokenType = $account['token_type'];
        $tokenValid = true;
    }
}

$error = $tokenValid ? '' : 'This password reset link is invalid, expired, or already used.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    if (!$tokenValid || cvcarts_rate_limited('account_password_reset', (string)$accountId, 5, 10, 15, true)) {
        cvcarts_record_auth_attempt('account_password_reset', (string)$accountId, false);
        $error = $tokenValid ? 'Too many reset attempts. Please wait before trying again.' : 'This password reset link is invalid, expired, or already used.';
    } else {
        $password = $_POST['new_password'] ?? '';
        $confirmation = $_POST['confirm_password'] ?? '';
        if (!is_string($password) || !cvcarts_password_is_strong($password)) {
            cvcarts_record_auth_attempt('account_password_reset', (string)$accountId, false);
            $error = 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.';
        } elseif (!is_string($confirmation) || $password !== $confirmation) {
            cvcarts_record_auth_attempt('account_password_reset', (string)$accountId, false);
            $error = 'New password and confirmation do not match.';
        } else {
            $db->beginTransaction();
            try {
                $lock = $db->prepare('SELECT id FROM account_tokens WHERE token_hash = :token_hash AND token_type = :token_type AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
                $lock->execute(['token_hash' => hash('sha256', $token), 'token_type' => $tokenType]);
                $tokenId = $lock->fetchColumn();
                if (!$tokenId) {
                    $db->rollBack();
                    cvcarts_record_auth_attempt('account_password_reset', (string)$accountId, false);
                    $error = 'This password reset link is invalid, expired, or already used.';
                } else {
                    $db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0, password_changed_at = NOW(), auth_version = auth_version + 1 WHERE id = :id AND role = :role')
                        ->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $accountId, 'role' => $accountRole]);
                    $db->prepare('UPDATE account_tokens SET used_at = COALESCE(used_at, NOW()) WHERE user_id = :user_id AND used_at IS NULL')
                        ->execute(['user_id' => $accountId]);
                    if ($accountRole === 'admin') {
                        $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                            ->execute(['admin_id' => $accountId, 'action' => 'Admin password recovered', 'details' => 'Password reset through registered email.']);
                    }
                    $db->commit();
                    cvcarts_record_auth_attempt('account_password_reset', (string)$accountId, true);
                    cvcarts_flash('success', 'Your password has been reset. You can now sign in.');
                    cvcarts_redirect(cvcarts_url('/auth/login.php'));
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }
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
    <title>CVCarts | Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/reset-password.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/auth/reset-password.css')); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/auth-experience.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/auth-experience.css'))); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="ambient-glow glow-one"></div><div class="ambient-glow glow-two"></div><div class="ambient-glow glow-three"></div>
    <main class="forgot-container auth-layout">
        <?php require __DIR__ . '/../includes/auth-brand.php'; ?>
        <section class="forgot-card">
            <div class="card-glow"></div><div class="card-content">
                <div class="card-header"><div class="welcome-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div><p class="welcome-label">PASSWORD RESET</p><h1>Create New Password</h1><p class="subtitle">Your reset link is valid for 15 minutes and can only be used once.</p></div>
                <?php foreach ($flashes as $flash): ?><div class="form-alert form-alert-success" role="status"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>
                <?php if ($error !== ''): ?><div class="form-alert" role="alert"><?php echo cvcarts_h($error); ?></div><?php endif; ?>
                <?php if ($tokenValid): ?>
                    <form method="post" action="<?php echo cvcarts_h(cvcarts_url('/auth/reset-password.php')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                        <input type="hidden" name="token" value="<?php echo cvcarts_h($token); ?>">
                        <div class="input-group"><label for="new-password">New Password</label><input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="12" required></div>
                        <div class="input-group"><label for="confirm-password">Confirm New Password</label><input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required></div>
                        <button class="reset-btn" type="submit"><span>Reset Password</span><span class="arrow">→</span></button>
                    </form>
                <?php else: ?>
                    <p class="subtitle">Request a new reset link or return to sign in.</p>
                    <a class="reset-btn" href="<?php echo cvcarts_h(cvcarts_url('/auth/forgot-password.php')); ?>"><span>Request Another Link</span><span class="arrow">→</span></a>
                <?php endif; ?>
                <div class="divider"><span>OR</span></div><p class="back-text"><a href="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>">Back to Login</a></p>
            </div>
        </section>
    </main>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/auth-carousel.js?v=' . filemtime(__DIR__ . '/../assets/js/auth-carousel.js'))); ?>" defer></script>
</body>
</html>
