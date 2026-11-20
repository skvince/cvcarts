<?php
require __DIR__ . '/../includes/auth.php';
if (empty($_SESSION['registration_complete'])) {
    cvcarts_redirect(cvcarts_url('/auth/login.php'));
}
$firstName = (string)($_SESSION['registration_complete_name'] ?? 'Student');
unset($_SESSION['registration_complete'], $_SESSION['registration_complete_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Registration Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/auth/verification.css')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="ambient-glow glow-one"></div>
    <div class="ambient-glow glow-two"></div>
    <div class="ambient-glow glow-three"></div>
    <main class="auth-container">
        <section class="brand-section">
            <div class="brand-top"><span class="brand-indicator"></span><span>CAMPUS VIA CART</span></div>
            <img src="<?php echo cvcarts_h(cvcarts_url('/assets/images/logo.png')); ?>" alt="CVCarts Logo" class="brand-logo">
            <div class="brand-content">
                <p class="brand-eyebrow">WELCOME TO CVCARTS</p>
                <h2>Shop Smarter,<br><span>Starting Today.</span></h2>
                <p class="brand-description">Your student account is ready to use.</p>
            </div>
            <div class="brand-bottom"><div class="bottom-line"></div><span>SHOP SMART. LIVE EASY.</span></div>
        </section>
        <section class="auth-card">
            <div class="card-glow"></div>
            <div class="card-content">
                <div class="card-header">
                    <div class="welcome-icon">✓</div>
                    <p class="welcome-label">REGISTRATION COMPLETE</p>
                    <h1>Email Verified</h1>
                    <p class="subtitle">Thank you, <?php echo cvcarts_h($firstName); ?>. Your school email has been verified and your account is active.</p>
                </div>
                <a class="auth-btn" href="<?php echo cvcarts_h(cvcarts_url('/auth/login.php')); ?>"><span>Go to Login</span><span class="arrow">→</span></a>
            </div>
        </section>
    </main>
</body>
</html>
