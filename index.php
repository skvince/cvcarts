<?php
require __DIR__ . '/includes/auth.php';

$user = cvcarts_user();
if ($user) {
    $target = $user['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php';
    cvcarts_redirect(cvcarts_url($target));
}

cvcarts_redirect(cvcarts_url('/auth/login.php'));
