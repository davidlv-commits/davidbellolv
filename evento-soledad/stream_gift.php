<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$db = event_db();
$token = trim((string) ($_GET['t'] ?? ''));
$filename = (string) ($_GET['f'] ?? '');
$expires = (int) ($_GET['e'] ?? 0);
$signature = (string) ($_GET['s'] ?? '');

if ($token === '' || $filename === '' || $expires <= 0 || $signature === '') {
    http_response_code(400);
    exit('Invalid request.');
}

if ($expires < time()) {
    http_response_code(403);
    exit('Link expired.');
}

$linkStmt = $db->prepare('SELECT * FROM post_event_links WHERE token = :token LIMIT 1');
$linkStmt->bindValue(':token', $token, SQLITE3_TEXT);
$linkRes = $linkStmt->execute();
$link = $linkRes ? $linkRes->fetchArray(SQLITE3_ASSOC) : null;
if (!$link) {
    http_response_code(404);
    exit('Access link not found.');
}

$createdAt = trim((string) ($link['created_at'] ?? ''));
$sentAt = trim((string) ($link['sent_at'] ?? ''));
$baseDate = $sentAt !== '' ? $sentAt : $createdAt;
if ($baseDate === '') {
    http_response_code(403);
    exit('Access window invalid.');
}

try {
    $start = new DateTimeImmutable($baseDate, new DateTimeZone(EVENT_TIMEZONE));
    $end = $start->modify('+' . (int) EVENT_GIFT_AVAILABILITY_DAYS . ' days');
    $now = new DateTimeImmutable('now', new DateTimeZone(EVENT_TIMEZONE));
    if ($now > $end) {
        http_response_code(403);
        exit('Access window closed.');
    }
} catch (Throwable $e) {
    http_response_code(403);
    exit('Access validation failed.');
}

$safeName = basename($filename);
$expected = hash_hmac('sha256', $token . '|' . $safeName . '|' . $expires, EVENT_GIFT_STREAM_SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Invalid signature.');
}

$allowedExtensions = ['mp3', 'm4a', 'wav', 'ogg'];
$extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(403);
    exit('File type not allowed.');
}

$audioStorageDir = realpath(__DIR__ . '/../../private_audio');
if ($audioStorageDir === false || !is_dir($audioStorageDir)) {
    http_response_code(500);
    exit('Audio storage folder not found.');
}

$filePath = $audioStorageDir . DIRECTORY_SEPARATOR . $safeName;
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Audio not found.');
}

$mime = match ($extension) {
    'm4a' => 'audio/mp4',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    default => 'audio/mpeg',
};

$size = filesize($filePath);
$start = 0;
$end = $size - 1;
$length = $size;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: inline; filename="' . rawurlencode($safeName) . '"');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches)) {
    $start = (int) $matches[1];
    $end = $matches[2] !== '' ? (int) $matches[2] : $end;
    $end = min($end, $size - 1);
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Length: ' . $length);

$chunkSize = 8192;
$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Cannot read file.');
}

fseek($handle, $start);
$bytesRemaining = $length;
while ($bytesRemaining > 0 && !feof($handle)) {
    $readLength = min($chunkSize, $bytesRemaining);
    $buffer = fread($handle, $readLength);
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
    $bytesRemaining -= strlen($buffer);
}
fclose($handle);
