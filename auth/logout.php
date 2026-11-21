<?php
require __DIR__ . '/../includes/auth.php';

$role = $_SESSION['user_role'] ?? '';
cvcarts_destroy_authenticated_session();

cvcarts_redirect(cvcarts_url('/auth/login.php?logged_out=1&role=' . urlencode((string)$role)));
