<?php
require __DIR__ . '/../auth.php';
cvcarts_require_login();
$db = cvcarts_db();
$user = cvcarts_user();
$isAdmin = $user['role'] === 'admin';
if ($isAdmin) {
    require_once __DIR__ . '/admin_chrome.php';
}
$settingsRoute = $isAdmin ? '/admin/settings/index.php' : '/student/settings/index.php';
$dashboardRoute = $isAdmin ? '/admin/dashboard.php' : '/student/dashboard.php';
$securityQuestions = [
    1 => 'What was the name of your first school?',
    2 => 'What was your childhood nickname?',
    3 => 'What city were you born in?',
    4 => 'What was the name of your favorite teacher?',
    5 => "What was your first pet's name?",
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();

    if (!$isAdmin && isset($_POST['upload_student_photo'])) {
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $upload = $_FILES['profile_photo'] ?? null;
        if (!is_array($upload)) {
            cvcarts_flash('error', 'Choose a profile image to upload.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            cvcarts_flash('error', 'Confirm this profile image change with your current password.');
        } else {
            $newImagePath = null;
            try {
                $newImagePath = cvcarts_store_profile_image($upload);
                $db->prepare('UPDATE users SET profile_image = :profile_image, updated_at = NOW() WHERE id = :id')
                    ->execute(['profile_image' => $newImagePath, 'id' => $user['id']]);
                cvcarts_remove_profile_image(is_string($user['profile_image'] ?? null) ? $user['profile_image'] : null);
                cvcarts_flash('success', 'Your profile photo was updated.');
            } catch (RuntimeException $e) {
                if ($newImagePath !== null) {
                    cvcarts_remove_profile_image($newImagePath);
                }
                cvcarts_flash('error', $e->getMessage());
            } catch (Throwable $e) {
                if ($newImagePath !== null) {
                    cvcarts_remove_profile_image($newImagePath);
                }
                error_log('CVCarts student profile photo update failed: ' . $e->getMessage());
                cvcarts_flash('error', 'Your profile photo could not be updated. Please try again.');
            }
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }

    if (!$isAdmin && isset($_POST['update_student_profile'])) {
        $firstName = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
        $middleName = is_string($_POST['middle_name'] ?? null) ? trim($_POST['middle_name']) : '';
        $lastName = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        if ($firstName === '' || strlen($firstName) > 100 || strlen($middleName) > 100 || $lastName === '' || strlen($lastName) > 100) {
            cvcarts_flash('error', 'Enter a valid first and last name.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            cvcarts_flash('error', 'Confirm profile changes with your current password.');
        } else {
            $db->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, updated_at = NOW() WHERE id = :id')
                ->execute([
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'last_name' => $lastName,
                    'id' => $user['id'],
                ]);
            cvcarts_flash('success', 'Your profile was updated.');
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }

    if ($isAdmin && isset($_POST['update_profile'])) {
        $firstName = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
        $lastName = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
        $username = is_string($_POST['username'] ?? null) ? strtolower(trim($_POST['username'])) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        if ($firstName === '' || $lastName === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@cscqc.edu.ph')) {
            cvcarts_flash('error', 'Enter a valid name, username, and @cscqc.edu.ph email address.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            cvcarts_flash('error', 'Confirm profile changes with your current password.');
        } else {
            try {
                $db->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, username = :username, email = :email, updated_at = NOW() WHERE id = :id')
                    ->execute(['first_name' => $firstName, 'last_name' => $lastName, 'username' => $username, 'email' => $email, 'id' => $user['id']]);
                $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                    ->execute(['admin_id' => $user['id'], 'action' => 'Admin profile updated', 'details' => 'Username, email, or display name updated.']);
                cvcarts_flash('success', 'Admin profile updated.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    cvcarts_flash('error', 'That username or email is already in use.');
                } else {
                    throw $e;
                }
            }
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }

    if (isset($_POST['change_password'])) {
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        if (!password_verify($current, $user['password_hash'])) {
            cvcarts_flash('error', 'Your current password is incorrect.');
        } elseif ($isAdmin ? !cvcarts_password_is_strong($new) : strlen($new) < 8) {
            cvcarts_flash('error', $isAdmin
                ? 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.'
                : 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            cvcarts_flash('error', 'New password and confirmation do not match.');
        } elseif (password_verify($new, $user['password_hash'])) {
            cvcarts_flash('error', 'Choose a password different from your current password.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, password_changed_at = NOW(), auth_version = auth_version + 1 WHERE id = :id')
                    ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['id']]);
                if ($isAdmin) {
                    $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                        ->execute(['admin_id' => $user['id'], 'action' => 'Admin password changed', 'details' => 'Password changed from Admin Settings.']);
                }
                $db->commit();
                $_SESSION['auth_version'] = (int)$user['auth_version'] + 1;
                cvcarts_flash('success', 'Password updated successfully.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }

    if ($isAdmin && isset($_POST['update_security'])) {
        $questionId = (int)($_POST['security_question_id'] ?? 0);
        $answerInput = is_string($_POST['security_answer'] ?? null) ? $_POST['security_answer'] : '';
        $confirmAnswerInput = is_string($_POST['confirm_security_answer'] ?? null) ? $_POST['confirm_security_answer'] : '';
        $answer = cvcarts_normalize_answer($answerInput);
        $confirmAnswer = cvcarts_normalize_answer($confirmAnswerInput);
        $currentPassword = is_string($_POST['security_current_password'] ?? null) ? $_POST['security_current_password'] : '';
        if (!isset($securityQuestions[$questionId]) || $answer === '' || $answer !== $confirmAnswer) {
            cvcarts_flash('error', 'Choose a question and enter matching security answers.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            cvcarts_flash('error', 'Confirm security information changes with your current password.');
        } else {
            $db->prepare('UPDATE users SET security_question_id = :question_id, security_question = :question, security_answer_hash = :answer_hash, security_updated_at = NOW(), updated_at = NOW() WHERE id = :id')
                ->execute([
                    'question_id' => $questionId,
                    'question' => $securityQuestions[$questionId],
                    'answer_hash' => password_hash($answer, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
            $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
                ->execute(['admin_id' => $user['id'], 'action' => 'Admin recovery information updated', 'details' => 'Security question and answer changed.']);
            cvcarts_flash('success', 'Recovery information updated.');
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }

    if (!$isAdmin && isset($_POST['update_student_security'])) {
        $questionId = (int)($_POST['security_question_id'] ?? 0);
        $answerInput = is_string($_POST['security_answer'] ?? null) ? $_POST['security_answer'] : '';
        $confirmAnswerInput = is_string($_POST['confirm_security_answer'] ?? null) ? $_POST['confirm_security_answer'] : '';
        $answer = cvcarts_normalize_answer($answerInput);
        $confirmAnswer = cvcarts_normalize_answer($confirmAnswerInput);
        $currentPassword = is_string($_POST['security_current_password'] ?? null) ? $_POST['security_current_password'] : '';
        if (!isset($securityQuestions[$questionId]) || $answer === '' || $answer !== $confirmAnswer) {
            cvcarts_flash('error', 'Choose a question and enter matching recovery answers.');
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            cvcarts_flash('error', 'Confirm recovery information changes with your current password.');
        } else {
            $db->prepare('UPDATE users SET security_question_id = :question_id, security_question = :question, security_answer_hash = :answer_hash, security_updated_at = NOW(), updated_at = NOW() WHERE id = :id')
                ->execute([
                    'question_id' => $questionId,
                    'question' => $securityQuestions[$questionId],
                    'answer_hash' => password_hash($answer, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
            cvcarts_flash('success', 'Your recovery information was updated.');
        }
        cvcarts_redirect(cvcarts_url($settingsRoute));
    }
}

$user = cvcarts_user();
$settingsCssDirectory = $isAdmin ? 'admin' : 'student';
$settingsPageClass = $isAdmin ? 'admin-settings-page' : 'student-settings-page';
$alerts = $isAdmin ? cvcarts_admin_alerts($db) : [];
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/' . $settingsCssDirectory . '/settings.css')); ?>">
</head>
<body class="<?php echo cvcarts_h($settingsPageClass); ?>">
    <?php if ($isAdmin): ?>
        <?php cvcarts_admin_header($user, $alerts); ?>
        <div class="app-layout">
            <?php cvcarts_admin_sidebar('settings'); ?>
            <main class="main-content">
    <?php else: ?>
    <div class="top-strip">CVCarts - Your Essentials, Just a Cart Away.</div>
    <header class="top-header">
        <a href="<?php echo cvcarts_h(cvcarts_url($dashboardRoute)); ?>"><img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="header-logo"></a>
        <div class="header-actions">
            <a class="header-icon" href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>" aria-label="Notifications" title="Notifications"><i class="bi bi-bell" aria-hidden="true"></i></a>
            <details class="header-menu profile-menu">
                <summary class="admin-profile" aria-label="Open account menu">
                    <img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="">
                    <span><?php echo cvcarts_h($user['first_name']); ?></span>
                </summary>
                <div class="header-dropdown profile-dropdown">
                    <div class="dropdown-title profile-title">
                        <strong><?php echo cvcarts_h(trim((string)$user['first_name'] . ' ' . (string)$user['last_name'])); ?></strong>
                        <span><?php echo cvcarts_h((string)$user['email']); ?></span>
                    </div>
                    <a class="dropdown-item" href="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>">
                        <span class="dropdown-item-icon" aria-hidden="true"><i class="bi bi-gear"></i></span>
                        <span><strong>Settings</strong><small>Profile, password, and recovery</small></span>
                    </a>
                </div>
            </details>
            <a class="header-logout" href="<?php echo cvcarts_h(cvcarts_url('/auth/logout.php')); ?>" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span></a>
        </div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/dashboard.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span> Dashboard</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/cart/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span> My Cart</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/shop/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag"></i></span> Shop</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-status/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span> Order Status</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/notifications/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bell"></i></span> Notifications</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/order-history/index.php')); ?>"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span> Order History</a>
                <a href="<?php echo cvcarts_h(cvcarts_url('/student/settings/index.php')); ?>" class="active" aria-current="page"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span> Settings</a>
            </nav>
        </aside>
        <main class="main-content">
    <?php endif; ?>
            <div class="page-heading"><div><h1><?php echo $isAdmin ? 'Admin Settings' : 'Settings'; ?></h1><p><?php echo $isAdmin ? 'Manage your administrator profile and account security.' : 'Manage your student profile, password, and recovery information.'; ?></p></div></div>
            <?php foreach ($flashes as $flash): ?><div class="flash-message <?php echo $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ''); ?>" data-app-alert="true" data-alert-type="<?php echo cvcarts_h($flash['type']); ?>" data-alert-title="<?php echo $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Warning' : 'Success'); ?>" role="status"><?php echo cvcarts_h($flash['message']); ?></div><?php endforeach; ?>

            <?php if ($isAdmin): ?>
                <section class="panel">
                    <div class="panel-heading"><h2>Admin Profile</h2></div>
                    <div class="info-list info-list-spaced">
                        <div class="info-row"><strong>Role</strong><span>Administrator</span></div>
                        <div class="info-row"><strong>Account Status</strong><span><?php echo cvcarts_h(ucfirst($user['account_status'])); ?></span></div>
                        <div class="info-row"><strong>Account Created</strong><span><?php echo cvcarts_h(date('M j, Y', strtotime($user['created_at']))); ?></span></div>
                        <div class="info-row"><strong>Last Login</strong><span><?php echo $user['last_login_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($user['last_login_at']))) : '—'; ?></span></div>
                    </div>
                    <button class="btn" type="button" data-modal-open="profile-edit-dialog">Edit Profile</button>
                    <dialog id="profile-edit-dialog" class="app-modal" aria-labelledby="profile-edit-title">
                        <div class="modal-shell">
                            <header class="modal-header"><div><h2 id="profile-edit-title">Edit Admin Profile</h2><p>Confirm profile changes with your current password.</p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                            <form method="post" action="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>" data-modal-form>
                                <div class="modal-body form-grid">
                                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="update_profile" value="1">
                                    <div class="form-field"><label class="form-label" for="first-name">First Name</label><input class="form-control" id="first-name" name="first_name" value="<?php echo cvcarts_h($user['first_name']); ?>" required></div>
                                    <div class="form-field"><label class="form-label" for="last-name">Last Name</label><input class="form-control" id="last-name" name="last_name" value="<?php echo cvcarts_h($user['last_name']); ?>" required></div>
                                    <div class="form-field"><label class="form-label" for="username">Admin Username</label><input class="form-control" id="username" name="username" value="<?php echo cvcarts_h($user['username']); ?>" pattern="[a-zA-Z0-9._-]{3,50}" required></div>
                                    <div class="form-field"><label class="form-label" for="admin-email">Admin Email</label><input class="form-control" type="email" id="admin-email" name="email" value="<?php echo cvcarts_h($user['email']); ?>" required></div>
                                    <div class="form-field"><label class="form-label" for="profile-password">Current Password</label><input class="form-control" type="password" id="profile-password" name="current_password" required autocomplete="current-password"></div>
                                </div>
                                <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-small" type="submit">Save Profile</button></footer>
                            </form>
                        </div>
                    </dialog>
                </section>
            <?php else: ?>
                <section class="panel panel-narrow">
                    <div class="panel-heading"><h2>Student Profile</h2></div>
                    <div class="student-photo-editor">
                        <img class="student-profile-avatar" src="<?php echo cvcarts_h(cvcarts_profile_image_url($user['profile_image'] ?? null)); ?>" alt="Your profile photo">
                        <form method="post" enctype="multipart/form-data" action="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>" class="student-photo-form">
                            <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                            <input type="hidden" name="upload_student_photo" value="1">
                            <div class="form-field"><label class="form-label" for="student-profile-photo">Profile Photo</label><input class="form-control" id="student-profile-photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required><p class="form-help">JPG, PNG, or WEBP up to 2 MB.</p></div>
                            <div class="form-field"><label class="form-label" for="student-photo-password">Current Password</label><input class="form-control" id="student-photo-password" type="password" name="current_password" autocomplete="current-password" required></div>
                            <button class="btn btn-small" type="submit">Save Profile Photo</button>
                        </form>
                    </div>
                    <div class="info-list info-list-spaced">
                        <div class="info-row"><strong>School Email</strong><span><?php echo cvcarts_h($user['email']); ?></span></div>
                        <div class="info-row"><strong>Account Status</strong><span><?php echo cvcarts_h(ucfirst($user['account_status'])); ?></span></div>
                        <div class="info-row"><strong>Email Verified</strong><span><?php echo $user['email_verified_at'] ? cvcarts_h(date('M j, Y', strtotime($user['email_verified_at']))) : 'Not verified'; ?></span></div>
                    </div>
                    <button class="btn" type="button" data-modal-open="profile-edit-dialog">Edit Profile</button>
                    <dialog id="profile-edit-dialog" class="app-modal" aria-labelledby="profile-edit-title">
                        <div class="modal-shell">
                            <header class="modal-header"><div><h2 id="profile-edit-title">Edit Student Profile</h2><p>Confirm profile changes with your current password.</p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                            <form method="post" action="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>" data-modal-form>
                                <div class="modal-body form-grid">
                                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="update_student_profile" value="1">
                                    <div class="form-field"><label class="form-label" for="student-first-name">First Name</label><input class="form-control" id="student-first-name" name="first_name" maxlength="100" value="<?php echo cvcarts_h($user['first_name']); ?>" required></div>
                                    <div class="form-field"><label class="form-label" for="student-middle-name">Middle Name</label><input class="form-control" id="student-middle-name" name="middle_name" maxlength="100" value="<?php echo cvcarts_h($user['middle_name'] ?? ''); ?>"></div>
                                    <div class="form-field"><label class="form-label" for="student-last-name">Last Name</label><input class="form-control" id="student-last-name" name="last_name" maxlength="100" value="<?php echo cvcarts_h($user['last_name']); ?>" required></div>
                                    <div class="form-field"><label class="form-label" for="student-profile-password">Current Password</label><input class="form-control" type="password" id="student-profile-password" name="current_password" required autocomplete="current-password"></div>
                                </div>
                                <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-small" type="submit">Save Profile</button></footer>
                            </form>
                        </div>
                    </dialog>
                </section>
            <?php endif; ?>

            <section class="panel panel-narrow">
                <div class="panel-heading"><h2>Account Password</h2></div>
                <?php if ($isAdmin): ?><p class="form-help form-help-gap">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p><?php endif; ?>
                <button class="btn" type="button" data-modal-open="password-edit-dialog">Change Password</button>
                <dialog id="password-edit-dialog" class="app-modal" aria-labelledby="password-edit-title">
                    <div class="modal-shell">
                        <header class="modal-header"><div><h2 id="password-edit-title">Change <?php echo $isAdmin ? 'Admin ' : ''; ?>Password</h2><p>Enter your current password and choose a new password.</p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                        <form method="post" action="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>" data-modal-form>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="change_password" value="1">
                                <div class="form-field"><label class="form-label" for="current-password">Current Password</label><input class="form-control" type="password" id="current-password" name="current_password" required autocomplete="current-password"></div>
                                <div class="form-field"><label class="form-label" for="new-password">New Password</label><input class="form-control" type="password" id="new-password" name="new_password" required autocomplete="new-password" minlength="<?php echo $isAdmin ? 12 : 8; ?>"></div>
                                <div class="form-field"><label class="form-label" for="confirm-password">Confirm New Password</label><input class="form-control" type="password" id="confirm-password" name="confirm_password" required autocomplete="new-password"></div>
                            </div>
                            <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-small" type="submit">Update Password</button></footer>
                        </form>
                    </div>
                </dialog>
            </section>

                <section class="panel panel-narrow panel-last">
                    <div class="panel-heading"><h2><?php echo $isAdmin ? 'Admin Recovery Security Question' : 'Account Recovery Security Question'; ?></h2></div>
                    <p class="form-help form-help-gap">Your answer is stored as a secure hash and will never be displayed.</p>
                    <button class="btn" type="button" data-modal-open="recovery-edit-dialog">Edit Recovery Information</button>
                    <dialog id="recovery-edit-dialog" class="app-modal" aria-labelledby="recovery-edit-title">
                        <div class="modal-shell">
                            <header class="modal-header"><div><h2 id="recovery-edit-title">Recovery Security Question</h2><p>Your recovery answer is stored as a secure hash and is never displayed.</p></div><button class="modal-close" type="button" data-modal-close aria-label="Close">×</button></header>
                            <form method="post" action="<?php echo cvcarts_h(cvcarts_url($settingsRoute)); ?>" data-modal-form>
                                <div class="modal-body">
                                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>"><input type="hidden" name="<?php echo $isAdmin ? 'update_security' : 'update_student_security'; ?>" value="1">
                                    <div class="form-field"><label class="form-label" for="security-question">Security Question</label>
                                        <select class="select-control" id="security-question" name="security_question_id" required>
                                            <option value="">Choose a recovery question</option>
                                            <?php foreach ($securityQuestions as $id => $question): ?>
                                                <option value="<?php echo $id; ?>" <?php echo (int)$user['security_question_id'] === $id ? 'selected' : ''; ?>><?php echo cvcarts_h($question); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-field"><label class="form-label" for="security-answer">Security Answer</label><input class="form-control" type="password" id="security-answer" name="security_answer" autocomplete="off" required></div>
                                    <div class="form-field"><label class="form-label" for="confirm-security-answer">Confirm Security Answer</label><input class="form-control" type="password" id="confirm-security-answer" name="confirm_security_answer" autocomplete="off" required></div>
                                    <div class="form-field"><label class="form-label" for="security-current-password">Current Password</label><input class="form-control" type="password" id="security-current-password" name="security_current_password" required autocomplete="current-password"></div>
                                </div>
                                <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-small" type="submit">Save Recovery Info</button></footer>
                            </form>
                        </div>
                    </dialog>
                </section>
        </main>
    </div>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/modal.js')); ?>"></script>
</body>
</html>
