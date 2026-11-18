<?php
require_once __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('admin');
cvcarts_redirect(cvcarts_url('/admin/ready-to-claim/index.php'));
