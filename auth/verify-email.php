<?php
require __DIR__ . '/../includes/auth.php';
$db = cvcarts_db();
cvcarts_cleanup_expired_student_registrations($db);
$token = $_GET['token'] ?? '';
$verified = false;
$expiredUserId = 0;

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $db->beginTransaction();
    try {
        $tokenHash = hash('sha256', $token);
        $tokenLookup = $db->prepare("SELECT id, user_id FROM account_tokens WHERE token_hash = :token_hash AND token_type = 'student_verification' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $tokenLookup->execute(['token_hash' => $tokenHash]);
        $tokenCandidate = $tokenLookup->fetch(PDO::FETCH_ASSOC);
        $row = false;
        if ($tokenCandidate) {
            $userLookup = $db->prepare("SELECT id, first_name FROM users WHERE id = :user_id AND role = 'student' AND is_verified = 0 AND email_verified_at IS NULL AND account_status = 'pending_verification' AND registration_status = 'pending' FOR UPDATE");
            $userLookup->execute(['user_id' => $tokenCandidate['user_id']]);
            $pendingUser = $userLookup->fetch(PDO::FETCH_ASSOC);
            if ($pendingUser) {
                $lockedToken = $db->prepare("SELECT id FROM account_tokens WHERE id = :token_id AND user_id = :user_id AND token_type = 'student_verification' AND used_at IS NULL AND expires_at > NOW() FOR UPDATE");
                $lockedToken->execute([
                    'token_id' => $tokenCandidate['id'],
                    'user_id' => $pendingUser['id'],
                ]);
                if ($lockedToken->fetchColumn()) {
                    $row = [
                        'token_id' => $tokenCandidate['id'],
                        'user_id' => $pendingUser['id'],
                        'first_name' => $pendingUser['first_name'],
                    ];
                }
            }
        }
        if ($row) {
            $db->prepare("UPDATE users SET is_verified = 1, is_active = 1, account_status = 'active', registration_status = 'completed', email_verified_at = NOW(), updated_at = NOW() WHERE id = :user_id")
                ->execute(['user_id' => $row['user_id']]);
            $db->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $row['token_id']]);
            $db->prepare("UPDATE account_tokens SET used_at = COALESCE(used_at, NOW()) WHERE user_id = :user_id AND token_type = 'student_verification' AND id <> :token_id")
                ->execute(['user_id' => $row['user_id'], 'token_id' => $row['token_id']]);
            cvcarts_add_admin_notification($db, 'New Customer Registration', 'Customer ' . trim((string)$row['first_name']) . ' successfully verified and registered.');
            cvcarts_emit_realtime_event($db, 'users.updated', 'admin');
            cvcarts_emit_realtime_event($db, 'user.updated', 'student', (int)$row['user_id']);
            $db->commit();
            $_SESSION['registration_complete'] = true;
            $_SESSION['registration_complete_name'] = $row['first_name'];
            unset($_SESSION['pending_verification_user_id']);
            $verified = true;
            error_log('CVCarts student email verified for user id ' . (int)$row['user_id'] . '.');
        } else {
            $expired = $db->prepare("SELECT t.user_id FROM account_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.token_type = 'student_verification' AND t.used_at IS NULL AND t.expires_at <= NOW() AND u.role = 'student' AND u.account_status = 'pending_verification' LIMIT 1");
            $expired->execute(['token_hash' => $tokenHash]);
            $expiredUserId = (int)$expired->fetchColumn();
            $db->rollBack();
            error_log('CVCarts email verification rejected: token invalid, expired, reused, or account no longer pending.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

if ($verified) {
    cvcarts_redirect(cvcarts_url('/auth/registration-success.php'));
}
if ($expiredUserId > 0) {
    $_SESSION['pending_verification_user_id'] = $expiredUserId;
    cvcarts_flash('warning', 'This verification link has expired. Request a new verification email to continue.');
    cvcarts_redirect(cvcarts_url('/auth/verification-pending.php'));
}
cvcarts_flash('error', 'This verification link is invalid, expired, or has already been used.');
cvcarts_redirect(isset($_SESSION['pending_verification_user_id'])
    ? cvcarts_url('/auth/verification-pending.php')
    : cvcarts_url('/auth/register.php'));
