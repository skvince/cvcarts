<?php

function cvcarts_smtp_config(): array {
    return [
        'host' => trim(cvcarts_env('SMTP_HOST')),
        'port' => (int)(cvcarts_env('SMTP_PORT', '587') ?: 587),
        'auth' => filter_var(cvcarts_env('SMTP_AUTH', 'true') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'username' => trim(cvcarts_env('SMTP_USERNAME')),
        'password' => preg_replace('/\s+/', '', cvcarts_env('SMTP_PASSWORD')) ?? '',
        'encryption' => strtolower(trim(cvcarts_env('SMTP_ENCRYPTION', 'tls') ?: 'tls')),
        'from_address' => trim(cvcarts_env('MAIL_FROM_ADDRESS')),
        'from_name' => trim(trim(cvcarts_env('MAIL_FROM_NAME', 'CVCarts E-Commerce Team') ?: 'CVCarts E-Commerce Team', "\"'")),
    ];
}

function cvcarts_smtp_configuration_error(array $config): ?string {
    if ($config['host'] === '') {
        return 'SMTP_HOST is not configured.';
    }
    if ($config['port'] < 1 || $config['port'] > 65535) {
        return 'SMTP_PORT must be between 1 and 65535.';
    }
    if (!in_array($config['encryption'], ['tls', 'ssl', 'none'], true)) {
        return 'SMTP_ENCRYPTION must be tls, ssl, or none.';
    }
    if ($config['auth'] && ($config['username'] === '' || $config['password'] === '')) {
        return 'SMTP_USERNAME and SMTP_PASSWORD are required when SMTP_AUTH=true.';
    }
    if (!filter_var($config['from_address'], FILTER_VALIDATE_EMAIL)) {
        return 'MAIL_FROM_ADDRESS must be a valid email address.';
    }
    return null;
}

function cvcarts_test_smtp_connection(): array {
    try {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPMailer is not installed. Run composer install in the project directory.');
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('PHPMailer could not be loaded from Composer dependencies.');
        }

        $config = cvcarts_smtp_config();
        $configurationError = cvcarts_smtp_configuration_error($config);
        if ($configurationError !== null) {
            return ['ok' => false, 'message' => $configurationError];
        }

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->Port = $config['port'];
        $mailer->Timeout = 10;
        $mailer->SMTPAuth = $config['auth'];
        $mailer->Username = $config['username'];
        $mailer->Password = $config['password'];

        if ($config['encryption'] === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config['encryption'] === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure = '';
        }

        $mailer->SMTPAutoTLS = $config['encryption'] !== 'none';
        $mailer->SMTPDebug = 0;
        $mailer->smtpConnect();
        $mailer->smtpClose();

        return ['ok' => true, 'message' => 'SMTP connection test succeeded.'];
    } catch (Throwable $e) {
        $message = cvcarts_mail_error_detail($e, isset($mailer) ? $mailer : null, isset($config) ? $config : []);
        error_log('CVCarts SMTP connectivity test failed: ' . $message);
        return ['ok' => false, 'message' => 'SMTP connection failed. Check the server mail log for details.'];
    }
}

function cvcarts_mail_error_detail(Throwable $exception, ?\PHPMailer\PHPMailer\PHPMailer $mailer = null, array $config = []): string {
    $details = trim(($mailer ? $mailer->ErrorInfo . ' ' : '') . $exception->getMessage());
    foreach (['password', 'username'] as $sensitiveKey) {
        if (isset($config[$sensitiveKey]) && $config[$sensitiveKey] !== '') {
            $details = str_replace($config[$sensitiveKey], '[redacted]', $details);
        }
    }
    $details = preg_replace('/[\r\n\t]+/', ' ', $details) ?? $details;
    return function_exists('mb_substr')
        ? mb_substr($details, 0, 500, 'UTF-8')
        : substr($details, 0, 500);
}

function cvcarts_send_mail(string $recipient, string $recipientName, string $subject, string $htmlBody, string $purpose, ?string $plainTextBody = null): array {
    $db = cvcarts_db();
    $recipientHash = hash('sha256', strtolower(trim($recipient)));
    $status = 'failed';
    $errorMessage = null;
    $mailer = null;
    $config = [];

    try {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPMailer is not installed. Run composer install in the project directory.');
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('PHPMailer could not be loaded from Composer dependencies.');
        }

        $config = cvcarts_smtp_config();
        $configurationError = cvcarts_smtp_configuration_error($config);
        if ($configurationError !== null) {
            throw new RuntimeException($configurationError);
        }

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->Port = $config['port'];
        $mailer->SMTPAuth = $config['auth'];
        $mailer->Username = $config['username'];
        $mailer->Password = $config['password'];

        if ($config['encryption'] === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config['encryption'] === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure = '';
        }

        $mailer->Timeout = 15;
        $mailer->CharSet = 'UTF-8';
        $mailer->SMTPAutoTLS = $config['encryption'] !== 'none';
        $mailer->setFrom($config['from_address'], $config['from_name']);
        $mailer->addAddress($recipient, $recipientName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $plainTextBody
            ?? trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $htmlBody))) ?? '');
        $mailer->send();
        $status = 'accepted';
    } catch (Throwable $e) {
        $errorMessage = cvcarts_mail_error_detail($e, $mailer, $config);
        error_log('CVCarts mail delivery failed (' . $purpose . '): ' . $errorMessage);
    }

    $db->prepare('INSERT INTO email_delivery_log (purpose, recipient_hash, delivery_status, error_message) VALUES (:purpose, :recipient_hash, :delivery_status, :error_message)')
        ->execute([
            'purpose' => $purpose,
            'recipient_hash' => $recipientHash,
            'delivery_status' => $status,
            'error_message' => $errorMessage,
        ]);

    return ['sent' => $status === 'accepted', 'error' => $status === 'failed' ? 'Mail delivery failed.' : null];
}

function cvcarts_send_verification_email(array $user, string $rawToken): array {
    $url = cvcarts_app_url('/auth/verify-email.php?token=' . rawurlencode($rawToken));
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="en"><body style="margin:0;background:#f3f6f4;font-family:Arial,sans-serif;color:#20352b;">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:14px;overflow:hidden;">'
        . '<div style="padding:24px 32px;background:#103d2b;color:#fff;font-size:20px;font-weight:700;">CVCarts</div>'
        . '<div style="padding:32px;"><p>Hello ' . $name . ',</p>'
        . '<p>Thanks for creating your CVCarts account. Please verify the email address registered to your account:</p>'
        . '<p style="font-weight:700;">' . $email . '</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:14px 22px;border-radius:8px;background:#168653;color:#fff;text-decoration:none;font-weight:700;">VERIFY MY EMAIL</a></p>'
        . '<p>This secure link expires in 15 minutes. If the button does not work, copy and paste this link into your browser:<br><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
        . '<p>If you did not create this account, you can ignore this message.</p>'
        . '<p>Thank you,<br>CVCarts Team</p></div></div></body></html>';
    $text = "Hello {$user['first_name']},\n\n"
        . "Thanks for creating your CVCarts account. Verify this registered email address: {$user['email']}\n\n"
        . "Verify your email: {$url}\n\n"
        . "This secure link expires in 15 minutes. If you did not create this account, you can ignore this message.\n\n"
        . "CVCarts Team";
    return cvcarts_send_mail($user['email'], $user['first_name'], 'Verify Your CSCQC E-Commerce Account', $html, 'student_verification', $text);
}

function cvcarts_send_admin_reset_email(array $user, string $rawToken): array {
    $url = cvcarts_app_url('/auth/reset-password.php?token=' . rawurlencode($rawToken));
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello ' . $name . ',</p>'
        . '<p>A password reset was requested for your CSCQC E-Commerce administrator account.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#168653;color:#fff;text-decoration:none;font-weight:700;">RESET ADMIN PASSWORD</a></p>'
        . '<p>This link expires in 15 minutes and can be used once. If you did not request this, you may ignore this email.</p>'
        . '<p>CSCQC E-Commerce Team</p>';
    return cvcarts_send_mail($user['email'], $user['first_name'], 'CSCQC E-Commerce Admin Password Reset', $html, 'admin_password_reset');
}

function cvcarts_send_student_reset_email(array $user, string $rawToken): array {
    $url = cvcarts_app_url('/auth/reset-password.php?token=' . rawurlencode($rawToken));
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello ' . $name . ',</p>'
        . '<p>A password reset was requested for your CSCQC E-Commerce account.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#168653;color:#fff;text-decoration:none;font-weight:700;">RESET MY PASSWORD</a></p>'
        . '<p>This link expires in 15 minutes and can be used once. If you did not request this, you may ignore this email.</p>'
        . '<p>CSCQC E-Commerce Team</p>';
    return cvcarts_send_mail($user['email'], $user['first_name'], 'CSCQC E-Commerce Password Reset', $html, 'student_password_reset');
}

function cvcarts_send_admin_otp_email(array $user, string $otp): array {
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello ' . $name . ',</p>'
        . '<p>Use this one-time code to verify your administrator account recovery request:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;color:#103d2b;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 10 minutes and can be used once. If you did not request this, please ignore this email.</p>'
        . '<p>CSCQC E-Commerce Team</p>';
    return cvcarts_send_mail($user['email'], $user['first_name'], 'CSCQC E-Commerce Admin Recovery Code', $html, 'admin_security_otp');
}

function cvcarts_send_student_otp_email(array $user, string $otp): array {
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $html = '<p>Hello ' . $name . ',</p>'
        . '<p>Use this one-time code to verify your student account recovery request:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;color:#103d2b;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 10 minutes and can be used once. If you did not request this, please ignore this email.</p>'
        . '<p>CSCQC E-Commerce Team</p>';
    return cvcarts_send_mail($user['email'], $user['first_name'], 'CSCQC E-Commerce Student Recovery Code', $html, 'student_security_otp');
}
