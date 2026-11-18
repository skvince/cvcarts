<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $securityQuestion = trim($_POST['security_question'] ?? '');
    $securityAnswer = trim($_POST['security_answer'] ?? '');
    $agreeTerms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === '1';

    if ($email === '' || cvcarts_rate_limited('student_signup', $email, 3, 10, 60)) {
        cvcarts_record_auth_attempt('student_signup', $email ?: 'missing-email', false);
        cvcarts_flash('error', 'Too many registration attempts. Please wait before trying again.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    cvcarts_record_auth_attempt('student_signup', $email, false);

    if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || $securityQuestion === '' || $securityAnswer === '') {
        cvcarts_flash('error', 'Please complete all required fields.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    if (!$agreeTerms) {
        cvcarts_flash('error', 'Please agree to the Terms and Conditions before creating your account.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9._%+-]+@cscqc\.edu\.ph$/', $email)) {
        cvcarts_flash('error', 'Only school email addresses ending in @cscqc.edu.ph are allowed.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    if (strlen($password) < 8 || $password !== $confirmPassword) {
        cvcarts_flash('error', 'Password must be at least 8 characters and match the confirmation field.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    $db = cvcarts_db();
    $stmt = $db->prepare('SELECT id, role, account_status FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser && $existingUser['role'] === 'student' && $existingUser['account_status'] === 'pending_verification') {
        $_SESSION['pending_verification_user_id'] = (int)$existingUser['id'];
        cvcarts_flash('warning', 'This account is awaiting email verification. You can resend its verification email below.');
        cvcarts_redirect(cvcarts_url('/auth/verification-pending.php'));
    }
    if ($existingUser) {
        cvcarts_flash('error', 'This school email is already registered.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $answerHash = password_hash(cvcarts_normalize_answer($securityAnswer), PASSWORD_DEFAULT);
    $db->beginTransaction();
    try {
        $insert = $db->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password_hash, security_question, security_answer_hash, role, is_verified, is_active, account_status, registration_status) VALUES (:first_name, :middle_name, :last_name, :email, :password_hash, :security_question, :security_answer_hash, 'student', 0, 0, 'pending_verification', 'pending')");
        $insert->execute([
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $hash,
            'security_question' => $securityQuestion,
            'security_answer_hash' => $answerHash,
        ]);
        $userId = (int)$db->lastInsertId();
        $rawToken = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO account_tokens (user_id, token_type, token_hash, expires_at) VALUES (:user_id, :token_type, :token_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE))')
            ->execute(['user_id' => $userId, 'token_type' => 'student_verification', 'token_hash' => hash('sha256', $rawToken)]);
        cvcarts_emit_realtime_event($db, 'users.updated', 'admin');
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            cvcarts_flash('error', 'This school email is already registered.');
            cvcarts_redirect(cvcarts_url('/auth/register.php'));
        }
        throw $e;
    }

    $db->prepare("UPDATE auth_attempts SET succeeded = 1 WHERE action = 'student_signup' AND identifier_hash = :identifier_hash AND succeeded = 0 ORDER BY id DESC LIMIT 1")
        ->execute(['identifier_hash' => hash('sha256', strtolower(trim($email)))]);
    $_SESSION['pending_verification_user_id'] = $userId;
    error_log('CVCarts verification token issued for newly registered user id ' . $userId . ' (token omitted).');
    $mailUserStatement = $db->prepare("SELECT id, first_name, email FROM users WHERE id = :id AND role = 'student' AND account_status = 'pending_verification' LIMIT 1");
    $mailUserStatement->execute(['id' => $userId]);
    $mailUser = $mailUserStatement->fetch(PDO::FETCH_ASSOC);
    $mailResult = $mailUser
        ? cvcarts_send_verification_email($mailUser, $rawToken)
        : ['sent' => false, 'error' => 'The newly registered account could not be reloaded for email delivery.'];
    if (!$mailUser) {
        error_log('CVCarts verification email was not sent: registered user id ' . $userId . ' could not be reloaded.');
    }
    cvcarts_record_auth_attempt('verification_send', $email, $mailResult['sent']);
    if ($mailResult['sent']) {
        cvcarts_flash('success', 'Your account is pending. Check your school email to complete registration.');
    } else {
        cvcarts_flash('error', 'Your account remains pending, but the verification email could not be delivered. Check SMTP configuration and use Resend Verification Email.');
    }
    cvcarts_redirect(cvcarts_url('/auth/verification-pending.php'));
}

$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Create Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/signup.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/auth/signup.css')); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/auth-experience.css?v=' . filemtime(__DIR__ . '/../assets/css/auth/auth-experience.css'))); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page auth-page--signup">
    <div class="ambient-glow glow-one"></div>
    <div class="ambient-glow glow-two"></div>
    <div class="ambient-glow glow-three"></div>

    <main class="auth-container auth-layout">
        <?php require __DIR__ . '/../includes/auth-brand.php'; ?>

        <section class="auth-card">
            <div class="card-glow"></div>
            <div class="card-content">
                <div class="card-header">
                    <p class="welcome-label">CREATE YOUR ACCOUNT</p>
                    <h1>Sign Up</h1>
                    <p class="subtitle">Fill in your details to get started.</p>
                </div>

                <?php foreach ($flashes as $flash): ?>
                    <div class="form-alert form-alert-<?php echo cvcarts_h($flash['type']); ?>" role="alert"><?php echo cvcarts_h($flash['message']); ?></div>
                <?php endforeach; ?>

                <form class="signup-form" action="<?php echo cvcarts_h(cvcarts_url('/auth/register.php')); ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo cvcarts_h(cvcarts_csrf_token()); ?>">
                    <div class="name-fields">
                        <div class="input-group">
                            <label for="first-name">First Name</label>
                            <input type="text" id="first-name" name="first_name" placeholder="First name" autocomplete="given-name" required>
                        </div>
                        <div class="input-group">
                            <label for="middle-name">Middle Name</label>
                            <input type="text" id="middle-name" name="middle_name" placeholder="Optional" autocomplete="additional-name">
                        </div>
                        <div class="input-group">
                            <label for="last-name">Last Name</label>
                            <input type="text" id="last-name" name="last_name" placeholder="Last name" autocomplete="family-name" required>
                        </div>
                    </div>

                    <div class="input-group form-field-wide">
                        <label for="email">School Email Address</label>
                        <input type="email" id="email" name="email" placeholder="name@cscqc.edu.ph" autocomplete="email" pattern="[A-Za-z0-9._%+-]+@cscqc\.edu\.ph" title="Use your official @cscqc.edu.ph school email address." required>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" autocomplete="new-password" required>
                    </div>

                    <div class="input-group">
                        <label for="confirm-password">Confirm Password</label>
                        <input type="password" id="confirm-password" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password" required>
                    </div>

                    <div class="input-group">
                        <label for="security-question">Security Question</label>
                        <select id="security-question" name="security_question" required>
                            <option value="">Choose a security question</option>
                            <option>What is your mother's maiden name?</option>
                            <option>What was the name of your first school?</option>
                            <option>What is the name of your favorite teacher?</option>
                            <option>What city were you born in?</option>
                            <option>What was your childhood nickname?</option>
                        </select>
                    </div>

                    <div class="input-group">
                        <label for="security-answer">Security Answer</label>
                        <input type="text" id="security-answer" name="security_answer" placeholder="Enter your answer" required>
                    </div>

                    <div class="terms-group">
                        <div class="terms-row">
                            <input type="checkbox" id="agree-terms" name="agree_terms" value="1" required>
                            <label for="agree-terms" class="terms-text">I have read and agree to the</label>
                            <a href="#" class="terms-link" data-open-terms>Terms and Conditions</a>
                            <label for="agree-terms" class="terms-text">of CVCarts.</label>
                        </div>
                    </div>

                    <button type="submit" class="auth-btn">
                        <span>Create Account</span>
                        <span class="arrow">→</span>
                    </button>
                </form>

                <div class="divider"><span>OR</span></div>
                <p class="switch-text">Already have an account? <a href="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>">Sign In</a></p>
            </div>
        </section>
    </main>

    <dialog id="terms-modal" class="terms-dialog" aria-labelledby="terms-title">
        <div class="terms-modal-card">
            <header class="terms-modal-header">
                <div>
                    <h2 id="terms-title">CVCARTS – Terms and Conditions</h2>
                </div>
                <button type="button" class="terms-close" data-close-terms aria-label="Close terms and conditions">×</button>
            </header>

            <div class="terms-modal-body">
                <p>By using CVCarts, customers agree to follow the terms and conditions stated below.</p>

                <ol>
                    <li><strong>Account Responsibility</strong><p>Users must provide accurate information when registering and keep their login credentials confidential. Accounts must not be shared or accessed without authorization.</p></li>
                    <li><strong>Product Information</strong><p>CVCarts only offers school-related products managed by authorized school personnel, including uniforms, PE uniforms, and ID laces. Prices, sizes, and product availability depend on the school's updated inventory.</p></li>
                    <li><strong>Order Placement</strong><p>Customers must review their selected products, sizes, quantities, and order details before submitting an order. Customers are responsible for the accuracy of their submitted information.</p></li>
                    <li><strong>Payment and Receipts</strong><p>Customers must follow the school's approved payment procedures. Receipts and transaction records should be kept as proof of purchase and for payment verification.</p></li>
                    <li><strong>Order Pickup</strong><p>Customers must monitor their order status and follow the school's instructions for claiming purchased products at the designated campus distribution counter.</p></li>
                    <li><strong>Cancellations and Exchanges</strong><p>Requests for order cancellation, returns, or size exchanges must be coordinated with authorized school personnel and are subject to the school's policies.</p></li>
                    <li><strong>Proper Use of the System</strong><p>Users must not provide false information, access another person's account, or modify product, inventory, and order records without authorization.</p></li>
                    <li><strong>Privacy and Data Protection</strong><p>Customer account details and transaction records will only be used for purposes related to account management and order processing, in accordance with the Data Privacy Act of 2012 (RA 10173).</p></li>
                    <li><strong>Agreement</strong><p>By registering and using CVCarts, the customers confirm that they have read, understood, and agreed to these Terms and Conditions.</p></li>
                </ol>
            </div>

            <footer class="terms-modal-footer">
                <button type="button" class="terms-confirm-btn" data-close-terms>Agree &amp; Continue</button>
            </footer>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('terms-modal');
            var openLink = document.querySelector('[data-open-terms]');
            var closeButtons = document.querySelectorAll('[data-close-terms]');

            if (openLink && modal) {
                openLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    modal.showModal();
                });
            }

            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (modal && modal.open) {
                        modal.close();
                    }
                });
            });

            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.close();
                    }
                });
            }
        });
    </script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/auth-carousel.js?v=' . filemtime(__DIR__ . '/../assets/js/auth-carousel.js'))); ?>" defer></script>
</body>
</html>
