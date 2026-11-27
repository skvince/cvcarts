<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = cvcarts_db();
cvcarts_cleanup_expired_student_registrations($db);
$validSecurityQuestions = [
    "What is your mother's maiden name?",
    'What was the name of your first school?',
    'What is the name of your favorite teacher?',
    'What city were you born in?',
    'What was your childhood nickname?',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $firstName = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
    $middleName = is_string($_POST['middle_name'] ?? null) ? trim($_POST['middle_name']) : '';
    $lastName = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $securityQuestion = is_string($_POST['security_question'] ?? null) ? trim($_POST['security_question']) : '';
    $securityAnswer = is_string($_POST['security_answer'] ?? null) ? trim($_POST['security_answer']) : '';
    $agreeTerms = isset($_POST['agree_terms']) && is_string($_POST['agree_terms']) && $_POST['agree_terms'] === '1';

    if ($email === '' || cvcarts_rate_limited('student_signup', $email, 3, 10, 60)) {
        cvcarts_record_auth_attempt('student_signup', $email ?: 'missing-email', false);
        cvcarts_flash('error', 'Too many registration attempts. Please wait before trying again.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

    cvcarts_record_auth_attempt('student_signup', $email, false);

    if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || $confirmPassword === '' || !in_array($securityQuestion, $validSecurityQuestions, true) || $securityAnswer === '') {
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

    $passwordHasMinimumLength = preg_match('/^.{8,}$/us', $password) === 1;
    $passwordStartsWithUppercase = preg_match('/^[A-Z]/', $password) === 1;
    $passwordHasNumber = preg_match('/[0-9]/', $password) === 1;
    $passwordHasSymbol = preg_match('/[^\p{L}\p{N}\s]/u', $password) === 1;
    if (!$passwordHasMinimumLength || !$passwordStartsWithUppercase || !$passwordHasNumber || !$passwordHasSymbol || $password !== $confirmPassword) {
        cvcarts_flash('error', 'Password must be at least 8 characters, start with an uppercase letter, include a number and symbol, and match the confirmation field.');
        cvcarts_redirect(cvcarts_url('/auth/register.php'));
    }

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
        $insert = $db->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password_hash, security_question, security_answer_hash, role, is_verified, is_active, account_status, registration_status, created_at) VALUES (:first_name, :middle_name, :last_name, :email, :password_hash, :security_question, :security_answer_hash, 'student', 0, 0, 'pending_verification', 'pending', NOW())");
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
        $db->prepare("INSERT INTO account_tokens (user_id, token_type, token_hash, expires_at)
            SELECT id, :token_type, :token_hash, DATE_ADD(created_at, INTERVAL 15 MINUTE)
            FROM users WHERE id = :user_id")
            ->execute(['token_type' => 'student_verification', 'token_hash' => hash('sha256', $rawToken), 'user_id' => $userId]);
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
    <?= cvcarts_favicon_tags() ?>

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
                <?php require __DIR__ . '/../includes/auth-mobile-logo.php'; ?>
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
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="email@cscqc.edu.ph" autocomplete="email" pattern="[A-Za-z0-9._%+\-]+@cscqc\.edu\.ph" title="Use your official @cscqc.edu.ph school email address." required>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" autocomplete="new-password" minlength="8" required>
                        <small class="password-hint">At least 8 characters, starts with an uppercase letter, and includes a number and symbol.</small>
                    </div>

                    <div class="input-group">
                        <label for="confirm-password">Confirm Password</label>
                        <input type="password" id="confirm-password" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password" minlength="8" required>
                    </div>

                    <div class="input-group">
                        <label for="security-question">Security Question</label>
                        <select id="security-question" name="security_question" required>
                            <option value="">Choose a security question</option>
                            <?php foreach ($validSecurityQuestions as $question): ?>
                                <option value="<?php echo cvcarts_h($question); ?>"><?php echo cvcarts_h($question); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-group">
                        <label for="security-answer">Security Answer</label>
                        <input type="text" id="security-answer" name="security_answer" placeholder="Enter your answer" required>
                    </div>

                    <div class="terms-group">
                        <div class="terms-row">
                            <input type="checkbox" id="agree-terms" name="agree_terms" value="1" required disabled>
                            <label for="agree-terms" class="terms-text">I have read and agree to the</label>
                            <a href="#" class="terms-link" data-open-terms aria-disabled="true">Terms and Conditions</a>
                            <label for="agree-terms" class="terms-text">of CVCarts.</label>
                        </div>
                        <p class="terms-status" id="terms-status" aria-live="polite">Complete all required fields to unlock the Terms &amp; Conditions.</p>
                    </div>

                    <button type="submit" class="auth-btn" disabled>
                        <span>Create Account</span>
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
                <p class="terms-modal-status" id="terms-modal-status" aria-live="polite">Please scroll down to read all Terms &amp; Conditions.</p>
                <button type="button" class="terms-confirm-btn" data-close-terms>Close</button>
            </footer>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('terms-modal');
            var openLink = document.querySelector('[data-open-terms]');
            var closeButtons = document.querySelectorAll('[data-close-terms]');
            var form = document.querySelector('.signup-form');
            var agreement = document.getElementById('agree-terms');
            var registerButton = form ? form.querySelector('button[type="submit"]') : null;
            var termsBody = modal ? modal.querySelector('.terms-modal-body') : null;
            var termsStatus = document.getElementById('terms-status');
            var modalStatus = document.getElementById('terms-modal-status');
            var requiredFields = form ? Array.prototype.slice.call(form.querySelectorAll('input[required]:not([type="checkbox"]), select[required], textarea[required]')) : [];
            var fieldsAreValid = false;
            var termsHaveBeenRead = false;
            var fieldStatusMessage = 'Complete all required fields to unlock the Terms & Conditions.';
            var scrollStatusMessage = 'Please scroll down to read all Terms & Conditions.';
            var readyStatusMessage = 'You can now agree to the Terms & Conditions.';
            var passwordRequirementsMessage = 'Use at least 8 characters, start with an uppercase letter, and include a number and symbol.';

            function updateStatus(message) {
                if (termsStatus) {
                    termsStatus.textContent = message;
                }
                if (modalStatus) {
                    modalStatus.textContent = message;
                }
            }

            function updateFormState() {
                requiredFields.forEach(function (field) {
                    if (field.type !== 'password' && field.value.trim() === '') {
                        field.setCustomValidity('Please complete this required field.');
                    } else {
                        field.setCustomValidity('');
                    }
                });

                var password = document.getElementById('password');
                var confirmPassword = document.getElementById('confirm-password');
                var passwordIsValid = password
                    && Array.from(password.value).length >= 8
                    && /^[A-Z]/.test(password.value)
                    && /[0-9]/.test(password.value)
                    && /[^\p{L}\p{N}\s]/u.test(password.value);
                if (password) {
                    password.setCustomValidity(password.value && !passwordIsValid ? passwordRequirementsMessage : '');
                }
                if (confirmPassword) {
                    confirmPassword.setCustomValidity(
                        confirmPassword.value && password && confirmPassword.value !== password.value
                            ? 'Passwords must match.'
                            : ''
                    );
                }

                var wasValid = fieldsAreValid;
                fieldsAreValid = requiredFields.every(function (field) {
                    return field.validity.valid;
                });

                if (wasValid && !fieldsAreValid && agreement.checked) {
                    agreement.checked = false;
                }

                var canAgree = fieldsAreValid && termsHaveBeenRead;
                agreement.disabled = !canAgree;
                if (openLink) {
                    openLink.setAttribute('aria-disabled', fieldsAreValid ? 'false' : 'true');
                }

                if (registerButton) {
                    registerButton.disabled = !(canAgree && agreement.checked);
                }

                updateStatus(!fieldsAreValid ? fieldStatusMessage : (termsHaveBeenRead ? readyStatusMessage : scrollStatusMessage));
            }

            if (openLink && modal) {
                openLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (fieldsAreValid) {
                        modal.showModal();
                    }
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

                modal.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        modal.close();
                    }
                });
            }

            requiredFields.forEach(function (field) {
                field.addEventListener('input', updateFormState);
                field.addEventListener('change', updateFormState);
            });

            if (termsBody) {
                termsBody.addEventListener('scroll', function () {
                    if (fieldsAreValid && termsBody.scrollTop + termsBody.clientHeight >= termsBody.scrollHeight - 1) {
                        termsHaveBeenRead = true;
                        updateFormState();
                    }
                }, { passive: true });
            }

            if (agreement) {
                agreement.addEventListener('change', updateFormState);
            }

            if (form) {
                form.addEventListener('submit', function (event) {
                    updateFormState();
                    if (!fieldsAreValid) {
                        event.preventDefault();
                        form.reportValidity();
                    } else if (!termsHaveBeenRead || !agreement.checked) {
                        event.preventDefault();
                    }
                });
            }

            updateFormState();
        });
    </script>
    <script src="<?php echo cvcarts_h(cvcarts_url('/assets/js/auth-carousel.js?v=' . filemtime(__DIR__ . '/../assets/js/auth-carousel.js'))); ?>" defer></script>
</body>
</html>
