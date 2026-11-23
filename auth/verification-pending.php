<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = cvcarts_db();
cvcarts_cleanup_expired_student_registrations($db);
$userId = (int)($_SESSION['pending_verification_user_id'] ?? 0);
$statement = $db->prepare("SELECT id, first_name, email FROM users WHERE id = :id AND role = 'student' AND is_verified = 0 AND email_verified_at IS NULL AND account_status = 'pending_verification' AND registration_status = 'pending' LIMIT 1");
$statement->execute(['id' => $userId]);
$pendingUser = $statement->fetch(PDO::FETCH_ASSOC);
if (!$pendingUser) {
    cvcarts_flash('error', 'There is no pending email verification in this session. Please sign up or log in.');
    cvcarts_redirect(cvcarts_url('/auth/register.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verification'])) {
    cvcarts_require_csrf();
    $resendAction = 'verification_resend_delivery';
    $rawToken = '';
    $db->beginTransaction();
    try {
        $statement = $db->prepare("SELECT id, first_name, email FROM users WHERE id = :id AND role = 'student' AND account_status = 'pending_verification' AND registration_status = 'pending' AND is_verified = 0 AND email_verified_at IS NULL LIMIT 1 FOR UPDATE");
        $statement->execute(['id' => $pendingUser['id']]);
        $pendingUser = $statement->fetch(PDO::FETCH_ASSOC);
        if ($pendingUser
            && (cvcarts_rate_limited($resendAction, $pendingUser['email'], 1, 1, 1)
                || cvcarts_rate_limited($resendAction, $pendingUser['email'], 5, 10, 60))) {
            $db->commit();
            cvcarts_flash('warning', 'Please wait before requesting another verification email.');
            cvcarts_redirect(cvcarts_url('/auth/verification-pending.php'));
        }
        if ($pendingUser) {
            $attemptId = cvcarts_record_auth_attempt($resendAction, $pendingUser['email'], false);
            $rawToken = cvcarts_issue_token((int)$pendingUser['id'], 'student_verification', 15 * 60);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if (!$pendingUser) {
        cvcarts_flash('warning', 'This account is no longer awaiting email verification. Please sign in or contact support.');
        cvcarts_redirect(cvcarts_url('/auth/login.php'));
    }

    try {
        error_log('CVCarts verification token issued for pending user id ' . (int)$pendingUser['id'] . ' (token omitted).');
        $mailResult = cvcarts_send_verification_email($pendingUser, $rawToken);
    } catch (Throwable $e) {
        error_log('CVCarts verification resend failed before delivery for user id ' . (int)$pendingUser['id'] . ': ' . $e->getMessage());
        $mailResult = ['sent' => false, 'error' => 'Verification token could not be issued.'];
    }
    $db->prepare('UPDATE auth_attempts SET succeeded = :succeeded WHERE id = :id')
        ->execute(['succeeded' => $mailResult['sent'] ? 1 : 0, 'id' => $attemptId]);
    if ($mailResult['sent']) {
        cvcarts_flash('success', 'A new verification email was accepted for delivery. Check your inbox and spam folder.');
    } else {
        cvcarts_flash('error', 'The verification email could not be delivered. Please try again later or contact support.');
    }
    cvcarts_redirect(cvcarts_url('/auth/verification-pending.php'));
}
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Verify Your Email</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/verification.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/verification.css'))); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="verification-pending-page">
    <div class="ambient-glow glow-one"></div>
    <div class="ambient-glow glow-two"></div>
    <div class="ambient-glow glow-three"></div>
    <main class="auth-container">
        <section class="auth-card">
            <div class="card-glow"></div>
            <div class="card-content">
                <?php require __DIR__ . '/../includes/auth-mobile-logo.php'; ?>
                <div class="card-header">
                    <div class="welcome-icon">✉</div>
                    <p class="welcome-label">EMAIL VERIFICATION</p>
                    <h1>Please verify your email address.</h1>
                    <p class="subtitle">We sent a verification link to <strong><?php echo cvcarts_h(cvcarts_mask_email($pendingUser['email'])); ?></strong>.</p>
                </div>
                <?php foreach ($flashes as $flash): ?>
                    <div class="form-alert form-alert-<?php echo cvcarts_h($flash['type']); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div>
                <?php endforeach; ?>
                <p class="subtitle">Open the message and click “VERIFY MY EMAIL” within 15 minutes. Check your spam folder if it is not in your inbox.</p>
                <form action="<?php echo cvcarts_h(cvcarts_url('/auth/verification-pending.php')); ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                    <button type="submit" class="auth-btn" name="resend_verification" value="1"><span>Resend Verification Email</span></button>
                </form>
                <div class="divider"><span>OR</span></div>
                <p class="switch-text"><a href="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>">Return to Login</a></p>
            </div>
        </section>
    </main>
</body>
</html>
