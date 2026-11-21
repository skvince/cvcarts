<?php
require __DIR__ . '/../includes/auth.php';
$db = cvcarts_db();
$token = $_GET['token'] ?? '';
$verified = false;
$expiredUserId = 0;

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $db->beginTransaction();
    try {
        $statement = $db->prepare("SELECT t.id AS token_id, t.user_id, u.first_name FROM account_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.token_type = 'student_verification' AND t.used_at IS NULL AND t.expires_at > NOW() AND u.role = 'student' AND u.account_status = 'pending_verification' FOR UPDATE");
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
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
            $expired->execute(['token_hash' => hash('sha256', $token)]);
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
