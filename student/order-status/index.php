<?php
require_once __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('student');
$ordersHistory = false;
require __DIR__ . '/../../includes/pages/orders.php';
