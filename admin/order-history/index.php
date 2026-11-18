<?php
require_once __DIR__ . '/../../includes/auth.php';
cvcarts_require_login('admin');
$ordersView = 'history';
require __DIR__ . '/../../includes/pages/orders.php';
