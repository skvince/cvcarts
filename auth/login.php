<?php
require __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $identifierInput = $_POST['identifier'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $identifier = is_string($identifierInput) ? strtolower(trim($identifierInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if ($identifier === '' || cvcarts_rate_limited('login', $identifier, 8, 20, 15, true)) {
        cvcarts_record_auth_attempt('login', $identifier, false);
        cvcarts_flash('error', 'Too many sign-in attempts. Please wait before trying again.');
        cvcarts_redirect(cvcarts_url('/auth/login.php'));
    }

    $db = cvcarts_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE (role = 'student' AND email = :student_email) OR (role = 'admin' AND (username = :admin_username OR email = :admin_email)) LIMIT 1");
    $stmt->execute([
        'student_email' => $identifier,
        'admin_username' => $identifier,
        'admin_email' => $identifier,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        cvcarts_record_auth_attempt('login', $identifier, false);
        cvcarts_flash('error', 'Invalid credentials. Students must use their school email; admins may use a username or email.');
        cvcarts_redirect(cvcarts_url('/auth/login.php'));
    }

    if ((int)$user['is_verified'] !== 1 || (int)$user['is_active'] !== 1 || $user['account_status'] !== 'active') {
        cvcarts_record_auth_attempt('login', $identifier, false);
        cvcarts_flash('error', $user['role'] === 'student' && $user['account_status'] === 'pending_verification'
            ? 'Please verify your school email before signing in.'
            : 'Your account is not active. Please contact support.');
        cvcarts_redirect(cvcarts_url('/auth/login.php'));
    }

    cvcarts_record_auth_attempt('login', $identifier, true);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['auth_version'] = (int)$user['auth_version'];
    $db->prepare('UPDATE users SET last_login = NOW(), last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

    if ((int)$user['must_change_password'] === 1) {
        cvcarts_flash('warning', 'Please change your temporary password before continuing.');
        cvcarts_redirect(cvcarts_url('/admin/settings/index.php?force=1'));
    }

    $target = $user['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php';
    cvcarts_redirect(cvcarts_url($target));
}

$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/signin.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/signin.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/auth-experience.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/auth-experience.css'))); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="ambient-glow glow-one"></div>
    <div class="ambient-glow glow-two"></div>
    <div class="ambient-glow glow-three"></div>

    <main class="login-container auth-layout">
        <?php require __DIR__ . '/../includes/auth-brand.php'; ?>

        <section class="login-card">
            <div class="card-glow"></div>
            <div class="card-content">
                <div class="card-header">
                    <p class="welcome-label">WELCOME BACK</p>
                    <h1>Login</h1>
                    <p class="subtitle">Sign in to your campus account.</p>
                </div>

                <?php foreach ($flashes as $flash): ?>
                    <div class="alert <?php echo $flash['type'] === 'error' ? 'alert-error' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-success'); ?>" role="alert"><?php echo cvcarts_h($flash['message']); ?></div>
                <?php endforeach; ?>

                <form method="POST" action="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                    <div class="input-group">
                        <label for="identifier">Email</label>
                        <input type="text" id="identifier" name="identifier" placeholder="name@cscqc.edu.ph" autocomplete="username" required>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-password-toggle="password">Show</button>
                        </div>
                    </div>

                    <div class="forgot-password">
                        <a href="<?php echo cvcarts_h(cvcarts_url('/auth/forgot-password.php')); ?>">Forgot Password?</a>
                    </div>

                    <button type="submit" class="login-btn">
                        <span>Sign In</span>
                        <span class="arrow">→</span>
                    </button>
                </form>

                <div class="divider"><span>OR</span></div>

                <p class="signup-text">Don't have an account? <a href="<?php echo cvcarts_h(cvcarts_url('/auth/register.php')); ?>">Create Account</a></p>
            </div>
        </section>
    </main>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.passwordToggle);
                var visible = input.type === 'password';
                input.type = visible ? 'text' : 'password';
                button.textContent = visible ? 'Hide' : 'Show';
                button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
                button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            });
        });
    </script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/auth-carousel.js?v=' . filemtime(__DIR__ . '/../assets/js/auth-carousel.js'))); ?>" defer></script>
</body>
</html>