<?php
require_once __DIR__ . '/../../includes/auth.php';

$user = cvcarts_user();
if (!$user) {
    http_response_code(401);
    exit;
}
if (!in_array($user['role'], ['admin', 'student'], true)) {
    http_response_code(403);
    exit;
}

$db = cvcarts_db();
$userId = (int)$user['id'];
$role = $user['role'];
$authVersion = (int)$user['auth_version'];
session_write_close();

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['last_event_id'] ?? '0');
$cursor = is_string($lastEventId) && ctype_digit($lastEventId) ? (int)$lastEventId : 0;

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
set_time_limit(25);
ignore_user_abort(false);

while (ob_get_level() > 0) {
    ob_end_flush();
}
echo "retry: 3000\n\n";
flush();

$events = $db->prepare(
    "SELECT id, event_type, audience, recipient_user_id
     FROM realtime_events
     WHERE id > :cursor
       AND (audience = 'public'
            OR (:role = 'admin' AND audience = 'admin')
            OR (audience = 'student' AND recipient_user_id = :user_id))
     ORDER BY id ASC
     LIMIT 50"
);
$latestId = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM realtime_events');
$stillActive = $db->prepare('SELECT is_active, account_status, auth_version FROM users WHERE id = :id');

$expiresAt = microtime(true) + 18;
while (microtime(true) < $expiresAt && !connection_aborted()) {
    $stillActive->execute(['id' => $userId]);
    $account = $stillActive->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int)$account['is_active'] !== 1 || $account['account_status'] !== 'active'
        || (int)$account['auth_version'] !== $authVersion) {
        echo "event: auth.expired\ndata: {}\n\n";
        flush();
        break;
    }

    $events->execute(['cursor' => $cursor, 'role' => $role, 'user_id' => $userId]);
    $batch = $events->fetchAll(PDO::FETCH_ASSOC);
    foreach ($batch as $event) {
        $cursor = (int)$event['id'];
        echo 'id: ' . $cursor . "\n";
        echo "event: cvcarts\n";
        echo 'data: ' . json_encode(['type' => $event['event_type']], JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    if (!$batch) {
        $latestId->execute();
        $latest = (int)$latestId->fetchColumn();
        if ($latest > $cursor) {
            $cursor = $latest;
            echo 'id: ' . $cursor . "\n";
        }
        echo ": keep-alive\n\n";
    } elseif (count($batch) < 50) {
        $latestId->execute();
        $latest = (int)$latestId->fetchColumn();
        if ($latest > $cursor) {
            $cursor = $latest;
            echo 'id: ' . $cursor . "\n\n";
        }
    }
    flush();
    sleep(1);
}
