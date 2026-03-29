<?php
declare(strict_types=1);

require_once __DIR__ . '/../evento-soledad/bootstrap.php';

$shareCode = trim((string) ($_GET['c'] ?? ''));
if ($shareCode === '' || !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $shareCode)) {
    http_response_code(404);
    echo 'Enlace no válido.';
    exit;
}

$db = event_db();
$stmt = $db->prepare(
    "SELECT token
     FROM post_event_links
     WHERE share_slug = :share_slug
       AND (
         role <> 'gift_shared'
         OR verified_at IS NOT NULL
         OR verify_token IS NULL
         OR TRIM(verify_token) = ''
       )
     LIMIT 1"
);
$stmt->bindValue(':share_slug', $shareCode, SQLITE3_TEXT);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$row || trim((string) ($row['token'] ?? '')) === '') {
    http_response_code(404);
    echo 'Enlace no válido.';
    exit;
}

$token = (string) $row['token'];
$_GET['share_code'] = $shareCode;
$_GET['share_from'] = $token;
$_GET['s'] = hash_hmac('sha256', $token, EVENT_TOKEN_SALT);

require __DIR__ . '/../evento-soledad/privado.php';
