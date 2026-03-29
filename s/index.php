<?php
declare(strict_types=1);

require_once __DIR__ . '/../evento-soledad/bootstrap.php';

function share_ip_hash(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        $ip = 'unknown';
    }
    return hash('sha256', $ip . '|' . EVENT_TOKEN_SALT);
}

/**
 * @return array{failed_count:int,window_started_at:int,locked_until:int}|null
 */
function share_attempts_get(SQLite3 $db, string $ipHash): ?array
{
    $stmt = $db->prepare(
        "SELECT failed_count, window_started_at, locked_until
         FROM share_access_attempts
         WHERE ip_hash = :ip_hash
         LIMIT 1"
    );
    $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'failed_count' => (int) ($row['failed_count'] ?? 0),
        'window_started_at' => (int) ($row['window_started_at'] ?? 0),
        'locked_until' => (int) ($row['locked_until'] ?? 0),
    ];
}

function share_attempts_record_failure(SQLite3 $db, string $ipHash): void
{
    $now = time();
    $state = share_attempts_get($db, $ipHash);
    $window = (int) EVENT_SHARE_ABUSE_WINDOW_SECONDS;
    $maxFails = (int) EVENT_SHARE_ABUSE_MAX_FAILS;
    $lockFor = (int) EVENT_SHARE_ABUSE_LOCK_SECONDS;

    $failedCount = 1;
    $windowStarted = $now;
    $lockedUntil = 0;

    if ($state) {
        $withinWindow = ($state['window_started_at'] > 0 && ($now - $state['window_started_at']) <= $window);
        if ($withinWindow) {
            $failedCount = $state['failed_count'] + 1;
            $windowStarted = $state['window_started_at'];
        }
    }

    if ($failedCount >= $maxFails) {
        $lockedUntil = $now + $lockFor;
    }

    $upsert = $db->prepare(
        "INSERT INTO share_access_attempts (ip_hash, failed_count, window_started_at, locked_until, updated_at)
         VALUES (:ip_hash, :failed_count, :window_started_at, :locked_until, :updated_at)
         ON CONFLICT(ip_hash) DO UPDATE SET
           failed_count = :failed_count_u,
           window_started_at = :window_started_at_u,
           locked_until = :locked_until_u,
           updated_at = :updated_at_u"
    );
    $nowIso = event_now_iso();
    $upsert->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $upsert->bindValue(':failed_count', $failedCount, SQLITE3_INTEGER);
    $upsert->bindValue(':window_started_at', $windowStarted, SQLITE3_INTEGER);
    $upsert->bindValue(':locked_until', $lockedUntil, SQLITE3_INTEGER);
    $upsert->bindValue(':updated_at', $nowIso, SQLITE3_TEXT);
    $upsert->bindValue(':failed_count_u', $failedCount, SQLITE3_INTEGER);
    $upsert->bindValue(':window_started_at_u', $windowStarted, SQLITE3_INTEGER);
    $upsert->bindValue(':locked_until_u', $lockedUntil, SQLITE3_INTEGER);
    $upsert->bindValue(':updated_at_u', $nowIso, SQLITE3_TEXT);
    $upsert->execute();
}

function share_attempts_clear(SQLite3 $db, string $ipHash): void
{
    $stmt = $db->prepare('DELETE FROM share_access_attempts WHERE ip_hash = :ip_hash');
    $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $stmt->execute();
}

$db = event_db();
$ipHash = share_ip_hash();
$attemptState = share_attempts_get($db, $ipHash);
$nowTs = time();

if ($attemptState && $attemptState['locked_until'] > $nowTs) {
    $retryAfter = max(60, $attemptState['locked_until'] - $nowTs);
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    echo 'Demasiados intentos. Vuelve a intentarlo más tarde.';
    exit;
}

$shareCode = trim((string) ($_GET['c'] ?? ''));
if ($shareCode === '' || !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $shareCode)) {
    share_attempts_record_failure($db, $ipHash);
    http_response_code(404);
    echo 'Enlace no válido.';
    exit;
}

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
    share_attempts_record_failure($db, $ipHash);
    http_response_code(404);
    echo 'Enlace no válido.';
    exit;
}

share_attempts_clear($db, $ipHash);

$token = (string) $row['token'];
$_GET['share_code'] = $shareCode;
$_GET['share_from'] = $token;
$_GET['s'] = hash_hmac('sha256', $token, EVENT_TOKEN_SALT);

require __DIR__ . '/../evento-soledad/privado.php';
