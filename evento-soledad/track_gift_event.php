<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = event_db();
$token = trim((string) ($_POST['token'] ?? ''));
$track = basename((string) ($_POST['track'] ?? ''));
$eventType = trim((string) ($_POST['event'] ?? ''));
$allowedEvents = ['play', 'ended'];
$allowedExtensions = ['mp3', 'm4a', 'wav', 'ogg'];

if ($token === '' || $track === '' || !in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$extension = strtolower((string) pathinfo($track, PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_track_extension']);
    exit;
}

$audioStorageDir = realpath(__DIR__ . '/../../private_audio');
if ($audioStorageDir === false || !is_dir($audioStorageDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'storage_missing']);
    exit;
}
$filePath = $audioStorageDir . DIRECTORY_SEPARATOR . $track;
if (!is_file($filePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'track_not_found']);
    exit;
}

$linkStmt = $db->prepare('SELECT * FROM post_event_links WHERE token = :token LIMIT 1');
$linkStmt->bindValue(':token', $token, SQLITE3_TEXT);
$linkRes = $linkStmt->execute();
$link = $linkRes ? $linkRes->fetchArray(SQLITE3_ASSOC) : null;
if (!$link) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'token_not_found']);
    exit;
}

$createdAt = trim((string) ($link['created_at'] ?? ''));
$sentAt = trim((string) ($link['sent_at'] ?? ''));
$baseDate = $sentAt !== '' ? $sentAt : $createdAt;
if ($baseDate === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_window']);
    exit;
}

try {
    $start = new DateTimeImmutable($baseDate, new DateTimeZone(EVENT_TIMEZONE));
    $end = $start->modify('+' . (int) EVENT_GIFT_AVAILABILITY_DAYS . ' days');
    $now = new DateTimeImmutable('now', new DateTimeZone(EVENT_TIMEZONE));
    if ($now > $end) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'window_closed']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'window_check_failed']);
    exit;
}

$remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = hash('sha256', $remoteIp . '|' . EVENT_TOKEN_SALT);
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$nowIso = event_now_iso();

$ins = $db->prepare(
    'INSERT INTO post_event_audio_events
     (token, recipient_name, recipient_email, track_filename, event_type, created_at, ip_hash, user_agent)
     VALUES
     (:token, :recipient_name, :recipient_email, :track_filename, :event_type, :created_at, :ip_hash, :user_agent)'
);
$ins->bindValue(':token', $token, SQLITE3_TEXT);
$ins->bindValue(':recipient_name', (string) ($link['recipient_name'] ?? ''), SQLITE3_TEXT);
$ins->bindValue(':recipient_email', (string) ($link['recipient_email'] ?? ''), SQLITE3_TEXT);
$ins->bindValue(':track_filename', $track, SQLITE3_TEXT);
$ins->bindValue(':event_type', $eventType, SQLITE3_TEXT);
$ins->bindValue(':created_at', $nowIso, SQLITE3_TEXT);
$ins->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
$ins->bindValue(':user_agent', $userAgent, SQLITE3_TEXT);
$ok = $ins->execute();

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_insert_failed']);
    exit;
}

echo json_encode(['ok' => true]);
