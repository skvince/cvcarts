<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
$db = cvcarts_db();
$recoveryUrl = cvcarts_url('/auth/forgot-password.php');

if (isset($_SESSION['admin_recovery_expires_at']) && (int)$_SESSION['admin_recovery_expires_at'] < time()) {
    unset($_SESSION['admin_recovery_user_id'], $_SESSION['admin_recovery_otp_sent'], $_SESSION['admin_recovery_verified_user_id'], $_SESSION['admin_recovery_expires_at']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'request_email') {
        $identifierInput = $_POST['identifier'] ?? '';
        $identifier = is_string($identifierInput) ? strtolower(trim($identifierInput)) : '';
        if ($identifier === '' || cvcarts_rate_limited('admin_recovery_email', $identifier, 3, 10, 60)) {
            cvcarts_record_auth_attempt('admin_recovery_email', $identifier ?: 'missing', false);
            cvcarts_flash('warning', 'Too many recovery requests. Please wait before trying again.');
            cvcarts_redirect($recoveryUrl);
        }

        $statement = $db->prepare("SELECT * FROM users WHERE is_active = 1 AND account_status = 'active' AND (
            (role = 'admin' AND (username = :username OR email = :admin_email))
            OR (role = 'student' AND is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph' AND email = :student_email)
        ) LIMIT 1");
        $statement->execute(['username' => $identifier, 'admin_email' => $identifier, 'student_email' => $identifier]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        $sent = false;
        if ($account) {
            $tokenType = $account['role'] === 'admin' ? 'admin_password_reset' : 'student_password_reset';
            $rawToken = cvcarts_issue_token((int)$account['id'], $tokenType, 15 * 60);
            $mailResult = $account['role'] === 'admin'
                ? cvcarts_send_admin_reset_email($account, $rawToken)
                : cvcarts_send_student_reset_email($account, $rawToken);
            $sent = $mailResult['sent'];
        }
        cvcarts_record_auth_attempt('admin_recovery_email', $identifier, $sent);
        cvcarts_flash('success', 'If an eligible account matches, a reset link will be sent. Check your email and spam folder or contact support if it does not arrive.');
        cvcarts_redirect($recoveryUrl);
    }

    if ($action === 'lookup_security') {
        $identifierInput = $_POST['identifier'] ?? '';
        $identifier = is_string($identifierInput) ? strtolower(trim($identifierInput)) : '';
        if ($identifier === '' || cvcarts_rate_limited('admin_recovery_lookup', $identifier, 5, 10, 15)) {
            cvcarts_record_auth_attempt('admin_recovery_lookup', $identifier ?: 'missing', false);
            cvcarts_flash('warning', 'Too many recovery attempts. Please wait and try again.');
            cvcarts_redirect($recoveryUrl);
        }
        $statement = $db->prepare("SELECT id, email, first_name, role, security_question, security_answer_hash FROM users WHERE role IN ('admin', 'student') AND is_active = 1 AND account_status = 'active' AND (
            (role = 'admin' AND (username = :admin_username OR email = :admin_email))
            OR (role = 'student' AND is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph' AND email = :student_email)
        ) LIMIT 1");
        $statement->execute([
            'admin_username' => $identifier,
            'admin_email' => $identifier,
            'student_email' => $identifier,
        ]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        cvcarts_record_auth_attempt('admin_recovery_lookup', $identifier, (bool)$account);
        if (!$account || !$account['security_question'] || !$account['security_answer_hash']) {
            unset($_SESSION['admin_recovery_user_id'], $_SESSION['admin_recovery_otp_sent'], $_SESSION['admin_recovery_verified_user_id']);
            cvcarts_flash('error', 'Security-question recovery is unavailable for that account. Use email recovery or contact support.');
            cvcarts_redirect($recoveryUrl);
        }
        $_SESSION['admin_recovery_user_id'] = (int)$account['id'];
        $_SESSION['admin_recovery_expires_at'] = time() + 15 * 60;
        unset($_SESSION['admin_recovery_otp_sent'], $_SESSION['admin_recovery_verified_user_id']);
        cvcarts_redirect($recoveryUrl . '?step=question');
    }

    if ($action === 'verify_answer') {
        $recoveryId = (int)($_SESSION['admin_recovery_user_id'] ?? 0);
        $statement = $db->prepare("SELECT id, email, first_name, role, security_answer_hash FROM users WHERE id = :id AND role IN ('admin', 'student') AND is_active = 1 AND account_status = 'active'
            AND (role = 'admin' OR (is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph')) LIMIT 1");
        $statement->execute(['id' => $recoveryId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        $answerInput = $_POST['security_answer'] ?? '';
        $answer = is_string($answerInput) ? cvcarts_normalize_answer($answerInput) : '';
        if (!$account) {
            unset($_SESSION['admin_recovery_user_id']);
            cvcarts_flash('error', 'Restart account recovery and try again.');
            cvcarts_redirect($recoveryUrl);
        }
        if (cvcarts_rate_limited('admin_security_answer', $account['email'], 5, 10, 15, true)) {
            cvcarts_record_auth_attempt('admin_security_answer', $account['email'], false);
            cvcarts_flash('warning', 'Too many incorrect answers. Please wait before trying again.');
            cvcarts_redirect($recoveryUrl . '?step=question');
        }
        if (!password_verify($answer, $account['security_answer_hash'])) {
            cvcarts_record_auth_attempt('admin_security_answer', $account['email'], false);
            cvcarts_flash('error', 'The security answer did not match.');
            cvcarts_redirect($recoveryUrl . '?step=question');
        }

        cvcarts_record_auth_attempt('admin_security_answer', $account['email'], true);
        if (cvcarts_rate_limited('admin_security_otp_send', $account['email'], 1, 1, 1)
            || cvcarts_rate_limited('admin_security_otp_send', $account['email'], 5, 10, 60)) {
            cvcarts_record_auth_attempt('admin_security_otp_send', $account['email'], false);
            cvcarts_flash('warning', 'A recovery code was sent recently. Check your email before requesting another.');
            cvcarts_redirect($recoveryUrl . '?step=question');
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        cvcarts_store_token((int)$account['id'], 'admin_security_otp', $otp, 10 * 60);
        $mailResult = $account['role'] === 'admin'
            ? cvcarts_send_admin_otp_email($account, $otp)
            : cvcarts_send_student_otp_email($account, $otp);
        cvcarts_record_auth_attempt('admin_security_otp_send', $account['email'], $mailResult['sent']);
        if (!$mailResult['sent']) {
            $db->prepare("UPDATE account_tokens SET used_at = NOW() WHERE user_id = :user_id AND token_type = 'admin_security_otp' AND used_at IS NULL")
                ->execute(['user_id' => $account['id']]);
            cvcarts_flash('error', 'The recovery code could not be delivered. Please try email recovery or contact support.');
            cvcarts_redirect($recoveryUrl . '?step=question');
        }
        $_SESSION['admin_recovery_otp_sent'] = (int)$account['id'];
        $_SESSION['admin_recovery_expires_at'] = time() + 10 * 60;
        cvcarts_redirect($recoveryUrl . '?step=otp');
    }

    if ($action === 'verify_otp') {
        $recoveryId = (int)($_SESSION['admin_recovery_otp_sent'] ?? 0);
        $otpInput = $_POST['otp'] ?? '';
        $otp = is_string($otpInput) ? trim($otpInput) : '';
        $statement = $db->prepare("SELECT id, email, role FROM users WHERE id = :id AND role IN ('admin', 'student') AND is_active = 1 AND account_status = 'active'
            AND (role = 'admin' OR (is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph')) LIMIT 1");
        $statement->execute(['id' => $recoveryId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            unset($_SESSION['admin_recovery_otp_sent']);
            cvcarts_flash('error', 'Restart account recovery and try again.');
            cvcarts_redirect($recoveryUrl);
        }
        if (cvcarts_rate_limited('admin_security_otp', $account['email'], 5, 10, 15, true)) {
            cvcarts_record_auth_attempt('admin_security_otp', $account['email'], false);
            cvcarts_flash('warning', 'Too many code attempts. Please wait before trying again.');
            cvcarts_redirect($recoveryUrl . '?step=otp');
        }
        $tokenStatement = $db->prepare("SELECT id FROM account_tokens WHERE user_id = :user_id AND token_type = 'admin_security_otp' AND token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $tokenStatement->execute(['user_id' => $account['id'], 'token_hash' => hash('sha256', $otp)]);
        $tokenId = $tokenStatement->fetchColumn();
        cvcarts_record_auth_attempt('admin_security_otp', $account['email'], (bool)$tokenId);
        if (!$tokenId || !preg_match('/^\d{6}$/', $otp)) {
            cvcarts_flash('error', 'The code is invalid or expired.');
            cvcarts_redirect($recoveryUrl . '?step=otp');
        }
        $db->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL')->execute(['id' => $tokenId]);
        $_SESSION['admin_recovery_verified_user_id'] = (int)$account['id'];
        $_SESSION['admin_recovery_expires_at'] = time() + 10 * 60;
        unset($_SESSION['admin_recovery_user_id'], $_SESSION['admin_recovery_otp_sent']);
        cvcarts_redirect($recoveryUrl . '?step=new-password');
    }

    if ($action === 'set_password') {
        $recoveryId = (int)($_SESSION['admin_recovery_verified_user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (!$recoveryId || cvcarts_rate_limited('admin_security_password_reset', (string)$recoveryId, 5, 10, 15, true)) {
            cvcarts_record_auth_attempt('admin_security_password_reset', (string)$recoveryId, false);
            cvcarts_flash('warning', 'Too many recovery attempts. Restart the recovery process and try again later.');
            cvcarts_redirect($recoveryUrl);
        }
        if (!is_string($newPassword) || !is_string($confirmPassword) || !cvcarts_password_is_strong($newPassword) || $newPassword !== $confirmPassword) {
            cvcarts_record_auth_attempt('admin_security_password_reset', (string)$recoveryId, false);
            cvcarts_flash('error', 'Use a matching password with at least 12 characters, uppercase, lowercase, a number, and a symbol.');
            cvcarts_redirect($recoveryUrl . '?step=new-password');
        }
        $db->beginTransaction();
        try {
            $passwordUpdate = $db->prepare("UPDATE users SET password_hash = :hash, must_change_password = 0, password_changed_at = NOW(), auth_version = auth_version + 1
                WHERE id = :id AND role IN ('admin', 'student') AND is_active = 1 AND account_status = 'active'
                AND (role = 'admin' OR (is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph'))");
            $passwordUpdate->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $recoveryId]);
            if ($passwordUpdate->rowCount() !== 1) {
                $db->rollBack();
                unset($_SESSION['admin_recovery_verified_user_id']);
                cvcarts_flash('error', 'This account is no longer eligible for password recovery.');
                cvcarts_redirect($recoveryUrl);
            }
            $db->prepare('UPDATE account_tokens SET used_at = COALESCE(used_at, NOW()) WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['user_id' => $recoveryId]);
            $accountStatement = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $accountStatement->execute(['id' => $recoveryId]);
            $accountRole = (string)$accountStatement->fetchColumn();
            if ($accountRole === 'admin') {
                $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                    ->execute(['admin_id' => $recoveryId, 'action' => 'Admin password recovered', 'details' => 'Password reset after security question and email code verification.']);
            }
            $db->commit();
            cvcarts_record_auth_attempt('admin_security_password_reset', (string)$recoveryId, true);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        unset($_SESSION['admin_recovery_verified_user_id']);
        cvcarts_flash('success', 'Your password has been reset. You can now sign in.');
        cvcarts_redirect(cvcarts_url('/auth/login.php'));
    }

    cvcarts_flash('error', 'Invalid recovery request.');
    cvcarts_redirect($recoveryUrl);
}

$step = $_GET['step'] ?? '';
$recoveryExpiresAt = (int)($_SESSION['admin_recovery_expires_at'] ?? 0);
if ($recoveryExpiresAt < time()) {
    unset($_SESSION['admin_recovery_user_id'], $_SESSION['admin_recovery_otp_sent'], $_SESSION['admin_recovery_verified_user_id'], $_SESSION['admin_recovery_expires_at']);
}
$recoveryUser = null;
$recoveryId = (int)($_SESSION['admin_recovery_user_id'] ?? $_SESSION['admin_recovery_otp_sent'] ?? $_SESSION['admin_recovery_verified_user_id'] ?? 0);
if ($recoveryId && $recoveryExpiresAt >= time()) {
    $statement = $db->prepare("SELECT id, first_name, email, role, security_question FROM users WHERE id = :id AND role IN ('admin', 'student') AND is_active = 1 AND account_status = 'active'
        AND (role = 'admin' OR (is_verified = 1 AND email_verified_at IS NOT NULL AND email LIKE '%@cscqc.edu.ph')) LIMIT 1");
    $statement->execute(['id' => $recoveryId]);
    $recoveryUser = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Account Recovery</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/forgot-password.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/auth/forgot-password.css')); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/auth-experience.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/auth-experience.css'))); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="ambient-glow glow-one"></div>
    <div class="ambient-glow glow-two"></div>
    <div class="ambient-glow glow-three"></div>
    <main class="forgot-container auth-layout">
        <?php require __DIR__ . '/../includes/auth-brand.php'; ?>
        <section class="forgot-card">
            <div class="card-glow"></div>
            <div class="card-content">
                <?php require __DIR__ . '/../includes/auth-mobile-logo.php'; ?>
                <div class="card-header">
                    <div class="welcome-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                    <p class="welcome-label">ACCOUNT RECOVERY</p>
                    <h1>Forgot Password?</h1>
                    <p class="subtitle">Reset access to your student or administrator account.</p>
                </div>
                <?php foreach ($flashes as $flash): ?><div class="form-alert form-alert-<?php echo cvcarts_h($flash['type']); ?>" role="alert"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>

                <?php if (!$recoveryUser || $step === ''): ?>
                    <form method="post" action="<?php echo cvcarts_h($recoveryUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                        <div class="input-group"><label for="email-identifier">Email</label><input id="email-identifier" type="text" name="identifier" placeholder="email@cscqc.edu.ph or admin" autocomplete="username" required></div>
                        <button class="reset-btn" type="submit" name="action" value="request_email"><span>Recover Through Email</span></button>
                        <div class="divider"><span>OR</span></div>
                        <button class="reset-btn" type="submit" name="action" value="lookup_security"><span>Use Security Question</span></button>
                    </form>
                <?php elseif (!empty($_SESSION['admin_recovery_verified_user_id'])): ?>
                    <form method="post" action="<?php echo cvcarts_h($recoveryUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="action" value="set_password">
                        <div class="input-group"><label for="new-password">New Password</label><input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="12" required></div>
                        <div class="input-group"><label for="confirm-password">Confirm New Password</label><input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required></div>
                        <p class="subtitle">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
                        <button class="reset-btn" type="submit"><span>Set New Password</span></button>
                    </form>
                <?php elseif ($step === 'otp' && !empty($_SESSION['admin_recovery_otp_sent'])): ?>
                    <p class="subtitle">Enter the 6-digit code sent to <?php echo cvcarts_h(cvcarts_mask_email($recoveryUser['email'])); ?>.</p>
                    <form method="post" action="<?php echo cvcarts_h($recoveryUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="action" value="verify_otp">
                        <div class="input-group"><label for="otp">Email Verification Code</label><input id="otp" type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
                        <button class="reset-btn" type="submit"><span>Verify Code</span></button>
                    </form>
                <?php else: ?>
                    <p class="recovery-question"><?php echo cvcarts_h($recoveryUser['security_question']); ?></p>
                    <form method="post" action="<?php echo cvcarts_h($recoveryUrl); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="action" value="verify_answer">
                        <div class="input-group"><label for="security-answer">Security Answer</label><input id="security-answer" type="password" name="security_answer" autocomplete="off" required></div>
                        <button class="reset-btn" type="submit"><span>Verify Answer</span></button>
                    </form>
                <?php endif; ?>
                <div class="divider"><span>OR</span></div>
                <p class="back-text"><a href="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>">Back to Login</a></p>
            </div>
        </section>
    </main>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/auth-carousel.js?v=' . filemtime(__DIR__ . '/../assets/js/auth-carousel.js'))); ?>" defer></script>
</body>
</html>
