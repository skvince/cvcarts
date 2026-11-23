<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';

$db = cvcarts_db();
$deleted = cvcarts_cleanup_expired_student_registrations($db);
fwrite(STDOUT, 'Deleted ' . $deleted . " expired student registration(s).\n");
