<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const EVENT_SHARE_LIMIT = 5;

/**
 * @return array<string, mixed>|null
 */
function event_find_link(SQLite3 $db, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT * FROM post_event_links
         WHERE token = :token
           AND (
             role <> 'gift_shared'
             OR verified_at IS NOT NULL
             OR verify_token IS NULL
             OR TRIM(verify_token) = ''
           )
         LIMIT 1"
    );
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    return $row ?: null;
}

/**
 * @return array{is_open: bool, until_text: string}
 */
function event_link_window(array $link): array
{
    $createdAt = trim((string) ($link['created_at'] ?? ''));
    $sentAt = trim((string) ($link['sent_at'] ?? ''));
    $baseDate = $sentAt !== '' ? $sentAt : $createdAt;
    if ($baseDate === '') {
        return ['is_open' => false, 'until_text' => ''];
    }

    try {
        $start = new DateTimeImmutable($baseDate, new DateTimeZone(EVENT_TIMEZONE));
        $end = $start->modify('+' . (int) EVENT_GIFT_AVAILABILITY_DAYS . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone(EVENT_TIMEZONE));
        return [
            'is_open' => $now <= $end,
            'until_text' => $end->format('d/m/Y H:i') . ' (hora de Morelia)',
        ];
    } catch (Throwable $e) {
        return ['is_open' => false, 'until_text' => ''];
    }
}

function event_share_signature(string $token): string
{
    return hash_hmac('sha256', $token, EVENT_TOKEN_SALT);
}

function event_verify_share_signature(string $token, string $sig): bool
{
    if ($token === '' || $sig === '') {
        return false;
    }
    $expected = event_share_signature($token);
    return hash_equals($expected, $sig);
}

function event_send_share_verification_email(string $recipientName, string $email, string $verifyToken): bool
{
    if ($email === '' || $verifyToken === '') {
        return false;
    }

    $verifyUrl = EVENT_GIFT_PUBLIC_URL . '?verify=' . rawurlencode($verifyToken);
    $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'invitado', ENT_QUOTES, 'UTF-8');
    $safeVerifyUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
    $safeBookTitle = htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8');
    $safeFrom = htmlspecialchars(EVENT_FROM_NAME, ENT_QUOTES, 'UTF-8');
    $safeMain = htmlspecialchars(EVENT_MAIN_WEBSITE_URL, ENT_QUOTES, 'UTF-8');
    $safeSpotify = htmlspecialchars(EVENT_SPOTIFY_URL, ENT_QUOTES, 'UTF-8');

    $subject = 'Confirma tu acceso privado · ' . EVENT_BOOK_TITLE;

    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:24px;background:#0a0f17;color:#f8fafc;font-family:Arial,Helvetica,sans-serif;">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#111827;border:1px solid rgba(243,216,138,.3);border-radius:16px;overflow:hidden;">'
      . '<tr><td style="padding:22px 22px 8px;">'
      . '<p style="margin:0 0 8px;color:#f3d88a;font-weight:700;letter-spacing:.02em;">Acceso privado</p>'
      . '<h1 style="margin:0 0 12px;font-size:28px;line-height:1.25;color:#fff7de;">Hola ' . $safeName . '</h1>'
      . '<p style="margin:0 0 14px;color:#cdd5e1;line-height:1.7;">Recibimos tu solicitud para acceder al regalo musical de <strong>' . $safeBookTitle . '</strong>. Para activarlo, confirma tu correo con este botón:</p>'
      . '<p style="margin:16px 0 18px;"><a href="' . $safeVerifyUrl . '" style="display:inline-block;background:#f3d88a;color:#111827;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px;">Confirmar acceso</a></p>'
      . '<p style="margin:0;color:#93a0b5;font-size:14px;line-height:1.6;">Si el botón no abre, copia este enlace:<br><span style="word-break:break-all;color:#cdd5e1;">' . $safeVerifyUrl . '</span></p>'
      . '<hr style="border:none;border-top:1px solid rgba(243,216,138,.25);margin:18px 0;">'
      . '<p style="margin:0 0 6px;color:#93a0b5;font-size:13px;">' . $safeFrom . '</p>'
      . '<p style="margin:0;font-size:13px;"><a href="' . $safeMain . '" style="color:#f3d88a;text-decoration:none;">Web</a> · <a href="' . $safeSpotify . '" style="color:#f3d88a;text-decoration:none;">Spotify</a></p>'
      . '</td></tr></table></body></html>';

    return event_send_html_email($email, $subject, $html);
}

function event_self_action_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '';
    }
    return $path;
}

function event_generate_share_slug(SQLite3 $db): string
{
    for ($i = 0; $i < 10; $i++) {
        $raw = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
        $slug = substr($raw, 0, 12);
        if ($slug === '') {
            continue;
        }
        $stmt = $db->prepare(
            "SELECT id FROM post_event_links WHERE share_slug = :share_slug LIMIT 1"
        );
        $stmt->bindValue(':share_slug', $slug, SQLITE3_TEXT);
        $exists = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$exists) {
            return $slug;
        }
    }

    return event_generate_token(8);
}

function event_get_or_create_share_slug(SQLite3 $db, array $link): string
{
    $existing = trim((string) ($link['share_slug'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $id = (int) ($link['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    $slug = event_generate_share_slug($db);
    $upd = $db->prepare(
        "UPDATE post_event_links
         SET share_slug = :share_slug
         WHERE id = :id
           AND (share_slug IS NULL OR TRIM(share_slug) = '')"
    );
    $upd->bindValue(':share_slug', $slug, SQLITE3_TEXT);
    $upd->bindValue(':id', $id, SQLITE3_INTEGER);
    $upd->execute();

    $check = $db->prepare("SELECT share_slug FROM post_event_links WHERE id = :id LIMIT 1");
    $check->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $check->execute()->fetchArray(SQLITE3_ASSOC);

    return trim((string) ($row['share_slug'] ?? ''));
}

/**
 * @return array<int, array{filename: string, label: string, stream_url: string}>
 */
function event_private_audio_tracks(string $token): array
{
    $audioStorageDir = realpath(__DIR__ . '/../../private_audio');
    if ($audioStorageDir === false || !is_dir($audioStorageDir)) {
        return [];
    }

    $allowedExtensions = ['mp3', 'm4a', 'wav', 'ogg'];
    $tracks = [];
    $items = scandir($audioStorageDir);
    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $filePath = $audioStorageDir . DIRECTORY_SEPARATOR . $item;
        if (!is_file($filePath)) {
            continue;
        }
        $ext = strtolower((string) pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            continue;
        }

        $label = pathinfo($item, PATHINFO_FILENAME);
        $label = str_replace(['_', '-'], ' ', (string) $label);
        $label = trim((string) preg_replace('/\s+/', ' ', $label));
        if ($label === '') {
            $label = $item;
        }

        $expires = time() + 900;
        $signature = hash_hmac('sha256', $token . '|' . $item . '|' . $expires, EVENT_GIFT_STREAM_SECRET);
        $streamUrl = EVENT_PUBLIC_BASE_URL . '/stream_gift.php?t=' . rawurlencode($token)
            . '&f=' . rawurlencode($item)
            . '&e=' . $expires
            . '&s=' . rawurlencode($signature);

        $tracks[] = [
            'filename' => $item,
            'label' => $label,
            'stream_url' => $streamUrl,
        ];
    }

    usort($tracks, static fn(array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));
    return array_slice($tracks, 0, 2);
}

$db = event_db();
$token = trim((string) ($_GET['t'] ?? ''));
$verifyToken = trim((string) ($_GET['verify'] ?? ''));
$verifiedNow = ((string) ($_GET['verified'] ?? '') === '1');
$shareCode = trim((string) ($_GET['share_code'] ?? ''));
$shareFrom = trim((string) ($_GET['share_from'] ?? ''));
$shareSig = trim((string) ($_GET['s'] ?? ''));
$shareError = '';
$shareNotice = '';
$shareNameInput = '';
$shareEmailInput = '';

if ($shareFrom === '' && $shareCode !== '') {
    $slugStmt = $db->prepare(
        "SELECT token FROM post_event_links
         WHERE share_slug = :share_slug
           AND (
             role <> 'gift_shared'
             OR verified_at IS NOT NULL
             OR verify_token IS NULL
             OR TRIM(verify_token) = ''
           )
         LIMIT 1"
    );
    $slugStmt->bindValue(':share_slug', $shareCode, SQLITE3_TEXT);
    $slugRow = $slugStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($slugRow && trim((string) ($slugRow['token'] ?? '')) !== '') {
        $shareFrom = trim((string) $slugRow['token']);
        $shareSig = event_share_signature($shareFrom);
    } else {
        $shareError = 'El enlace corto no es válido.';
    }
}

if ($verifyToken !== '') {
    $verifyStmt = $db->prepare(
        "SELECT * FROM post_event_links
         WHERE role = 'gift_shared'
           AND verify_token = :verify_token
         LIMIT 1"
    );
    $verifyStmt->bindValue(':verify_token', $verifyToken, SQLITE3_TEXT);
    $pending = $verifyStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$pending) {
        $shareError = 'El enlace de confirmación no es válido o ya fue usado.';
    } else {
        $inviterToken = trim((string) ($pending['parent_token'] ?? ''));
        $inviter = event_find_link($db, $inviterToken);
        if (!$inviter) {
            $shareError = 'La invitación original ya no está disponible.';
        } else {
            $window = event_link_window($inviter);
            if (!$window['is_open']) {
                $shareError = 'La invitación original venció y no se puede activar este acceso.';
            } else {
                $countStmt = $db->prepare(
                    "SELECT COUNT(*) FROM post_event_links
                     WHERE parent_token = :parent_token
                       AND role = 'gift_shared'
                       AND verified_at IS NOT NULL"
                );
                $countStmt->bindValue(':parent_token', $inviterToken, SQLITE3_TEXT);
                $usedSlots = (int) $countStmt->execute()->fetchArray(SQLITE3_NUM)[0];

                if ($usedSlots >= EVENT_SHARE_LIMIT) {
                    $shareError = 'El cupo de invitaciones ya se completó antes de confirmar este acceso.';
                } else {
                    $nowIso = event_now_iso();
                    $upd = $db->prepare(
                        "UPDATE post_event_links
                         SET verified_at = :verified_at,
                             sent_at = COALESCE(sent_at, :sent_at),
                             verify_token = NULL
                         WHERE id = :id"
                    );
                    $upd->bindValue(':verified_at', $nowIso, SQLITE3_TEXT);
                    $upd->bindValue(':sent_at', $nowIso, SQLITE3_TEXT);
                    $upd->bindValue(':id', (int) ($pending['id'] ?? 0), SQLITE3_INTEGER);
                    $ok = $upd->execute();
                    if ($ok === false) {
                        $shareError = 'No se pudo confirmar tu acceso. Inténtalo de nuevo.';
                    } else {
                        $token = (string) ($pending['token'] ?? '');
                        header('Location: privado.php?t=' . rawurlencode($token) . '&verified=1');
                        exit;
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'claim_share') {
    $shareFrom = trim((string) ($_POST['share_from'] ?? ''));
    $shareSig = trim((string) ($_POST['share_sig'] ?? ''));
    $shareNameInput = trim((string) ($_POST['share_name'] ?? ''));
    $shareEmailInput = strtolower(trim((string) ($_POST['share_email'] ?? '')));

    if (!event_verify_share_signature($shareFrom, $shareSig)) {
        $shareError = 'Enlace de invitación inválido.';
    } elseif ($shareNameInput === '' || $shareEmailInput === '' || !filter_var($shareEmailInput, FILTER_VALIDATE_EMAIL)) {
        $shareError = 'Nombre y correo válido son obligatorios.';
    } else {
        $inviter = event_find_link($db, $shareFrom);
        if (!$inviter) {
            $shareError = 'El enlace de invitación ya no está disponible.';
        } else {
            $window = event_link_window($inviter);
            if (!$window['is_open']) {
                $shareError = 'Este acceso ya venció.';
            } else {
                $countStmt = $db->prepare(
                    "SELECT COUNT(*) FROM post_event_links
                     WHERE parent_token = :parent_token
                       AND role = 'gift_shared'
                       AND verified_at IS NOT NULL"
                );
                $countStmt->bindValue(':parent_token', $shareFrom, SQLITE3_TEXT);
                $usedSlots = (int) $countStmt->execute()->fetchArray(SQLITE3_NUM)[0];

                if ($usedSlots >= EVENT_SHARE_LIMIT) {
                    $shareError = 'Este acceso ya alcanzó su límite de 5 invitaciones.';
                } else {
                    $existingStmt = $db->prepare(
                        "SELECT token FROM post_event_links
                         WHERE parent_token = :parent_token
                           AND role = 'gift_shared'
                           AND verified_at IS NOT NULL
                           AND LOWER(recipient_email) = :recipient_email
                         LIMIT 1"
                    );
                    $existingStmt->bindValue(':parent_token', $shareFrom, SQLITE3_TEXT);
                    $existingStmt->bindValue(':recipient_email', $shareEmailInput, SQLITE3_TEXT);
                    $existing = $existingStmt->execute()->fetchArray(SQLITE3_ASSOC);

                    if ($existing && trim((string) ($existing['token'] ?? '')) !== '') {
                        header('Location: privado.php?t=' . rawurlencode((string) $existing['token']));
                        exit;
                    }

                    $pendingStmt = $db->prepare(
                        "SELECT id, token, verify_token FROM post_event_links
                         WHERE parent_token = :parent_token
                           AND role = 'gift_shared'
                           AND verified_at IS NULL
                           AND LOWER(recipient_email) = :recipient_email
                         LIMIT 1"
                    );
                    $pendingStmt->bindValue(':parent_token', $shareFrom, SQLITE3_TEXT);
                    $pendingStmt->bindValue(':recipient_email', $shareEmailInput, SQLITE3_TEXT);
                    $pending = $pendingStmt->execute()->fetchArray(SQLITE3_ASSOC);

                    $verify = event_generate_token(32);
                    $accessToken = '';
                    $pendingId = 0;
                    $nowIso = event_now_iso();

                    if ($pending && (int) ($pending['id'] ?? 0) > 0) {
                        $pendingId = (int) $pending['id'];
                        $accessToken = trim((string) ($pending['token'] ?? ''));
                        $updPending = $db->prepare(
                            "UPDATE post_event_links
                             SET recipient_name = :recipient_name,
                                 verify_token = :verify_token,
                                 verification_sent_at = :verification_sent_at
                             WHERE id = :id"
                        );
                        $updPending->bindValue(':recipient_name', substr($shareNameInput, 0, 120), SQLITE3_TEXT);
                        $updPending->bindValue(':verify_token', $verify, SQLITE3_TEXT);
                        $updPending->bindValue(':verification_sent_at', $nowIso, SQLITE3_TEXT);
                        $updPending->bindValue(':id', $pendingId, SQLITE3_INTEGER);
                        $ok = $updPending->execute();
                        if ($ok === false) {
                            $shareError = 'No se pudo actualizar la solicitud pendiente. Inténtalo de nuevo.';
                        }
                    } else {
                        $accessToken = event_generate_token(24);
                        $insert = $db->prepare(
                            'INSERT INTO post_event_links
                             (rsvp_id, recipient_name, recipient_email, role, token, parent_token, sent_at, created_at, verify_token, verified_at, verification_sent_at)
                             VALUES
                             (:rsvp_id, :recipient_name, :recipient_email, :role, :token, :parent_token, NULL, :created_at, :verify_token, NULL, :verification_sent_at)'
                        );
                        $insert->bindValue(':rsvp_id', (int) ($inviter['rsvp_id'] ?? 0), SQLITE3_INTEGER);
                        $insert->bindValue(':recipient_name', substr($shareNameInput, 0, 120), SQLITE3_TEXT);
                        $insert->bindValue(':recipient_email', $shareEmailInput, SQLITE3_TEXT);
                        $insert->bindValue(':role', 'gift_shared', SQLITE3_TEXT);
                        $insert->bindValue(':token', $accessToken, SQLITE3_TEXT);
                        $insert->bindValue(':parent_token', $shareFrom, SQLITE3_TEXT);
                        $insert->bindValue(':created_at', $nowIso, SQLITE3_TEXT);
                        $insert->bindValue(':verify_token', $verify, SQLITE3_TEXT);
                        $insert->bindValue(':verification_sent_at', $nowIso, SQLITE3_TEXT);
                        $ok = $insert->execute();
                        if ($ok === false) {
                            $shareError = 'No se pudo iniciar la verificación. Inténtalo de nuevo.';
                        } else {
                            $pendingId = (int) $db->lastInsertRowID();
                        }
                    }

                    if ($shareError === '') {
                        $mailOk = event_send_share_verification_email(substr($shareNameInput, 0, 120), $shareEmailInput, $verify);
                        if (!$mailOk) {
                            $shareError = 'No se pudo enviar el correo de confirmación. Revisa el email y vuelve a intentar.';
                            if ($pendingId > 0) {
                                $deleteStmt = $db->prepare('DELETE FROM post_event_links WHERE id = :id AND verified_at IS NULL');
                                $deleteStmt->bindValue(':id', $pendingId, SQLITE3_INTEGER);
                                $deleteStmt->execute();
                            }
                        } else {
                            $shareNotice = 'Te enviamos un correo de verificación a ' . $shareEmailInput . '. Confírmalo para activar tu acceso.';
                            $shareNameInput = '';
                            $shareEmailInput = '';
                            $shareError = '';
                            $shareSig = trim((string) ($_POST['share_sig'] ?? ''));
                            $shareFrom = trim((string) ($_POST['share_from'] ?? ''));
                            $isShareMode = true;
                            $shareInviter = $inviter;
                            $token = '';
                            $activeLink = null;
                        }
                    }
                }
            }
        }
    }
}

$activeLink = event_find_link($db, $token);
$isShareMode = false;
$shareInviter = null;
$showVerifyState = ($verifyToken !== '' && $activeLink === null);

if (!$activeLink && $shareFrom !== '' && event_verify_share_signature($shareFrom, $shareSig)) {
    $shareInviter = event_find_link($db, $shareFrom);
    if ($shareInviter) {
        $window = event_link_window($shareInviter);
        $isShareMode = $window['is_open'];
        if (!$isShareMode && $shareError === '') {
            $shareError = 'Este acceso ya venció.';
        }
    } elseif ($shareError === '') {
        $shareError = 'El enlace de invitación no existe.';
    }
}

if (!$activeLink && !$isShareMode && !$showVerifyState) {
    http_response_code(404);
}

$recipientName = '';
$accessUntilText = '';
$isAccessOpen = false;
$tracks = [];
$shareUrl = '';
$usedShares = 0;
$remainingShares = EVENT_SHARE_LIMIT;
$selfActionUrl = event_self_action_url();

if ($activeLink) {
    $recipientName = trim((string) ($activeLink['recipient_name'] ?? '')) ?: 'invitado';
    $window = event_link_window($activeLink);
    $isAccessOpen = $window['is_open'];
    $accessUntilText = $window['until_text'];

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ipHash = $ip !== '' ? hash('sha256', $ip . '|' . EVENT_TOKEN_SALT) : '';
    $ins = $db->prepare(
        'INSERT INTO post_event_visits (token, visited_at, ip_hash, user_agent)
         VALUES (:token, :visited_at, :ip_hash, :user_agent)'
    );
    $ins->bindValue(':token', $token, SQLITE3_TEXT);
    $ins->bindValue(':visited_at', event_now_iso(), SQLITE3_TEXT);
    $ins->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $ins->bindValue(':user_agent', $ua, SQLITE3_TEXT);
    $ins->execute();

    if ($isAccessOpen) {
        $tracks = event_private_audio_tracks($token);
    }

    $shareSlug = event_get_or_create_share_slug($db, $activeLink);
    if ($shareSlug !== '') {
        $shareUrl = rtrim(EVENT_MAIN_WEBSITE_URL, '/') . '/s/' . rawurlencode($shareSlug);
    } else {
        $shareUrl = EVENT_GIFT_PUBLIC_URL . '?share_from=' . rawurlencode($token) . '&s=' . rawurlencode(event_share_signature($token));
    }
    $shareCountStmt = $db->prepare(
        "SELECT COUNT(*) FROM post_event_links
         WHERE parent_token = :parent_token
           AND role = 'gift_shared'
           AND verified_at IS NOT NULL"
    );
    $shareCountStmt->bindValue(':parent_token', $token, SQLITE3_TEXT);
    $usedShares = (int) $shareCountStmt->execute()->fetchArray(SQLITE3_NUM)[0];
    $remainingShares = max(0, EVENT_SHARE_LIMIT - $usedShares);
}
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow,noarchive" />
    <title>Acceso privado · Regalo especial</title>
    <style>
      :root { --line: rgba(243,216,138,.28); --line-soft: rgba(243,216,138,.16); --gold:#f3d88a; --ink:#f8fafc; --muted:#cdd5e1; }
      * { box-sizing: border-box; }
      body { margin:0; font-family:"Avenir Next","Segoe UI",sans-serif; background:radial-gradient(1100px 480px at 8% -8%, rgba(243,216,138,.12), transparent 60%), radial-gradient(1000px 440px at 100% 0%, rgba(200,170,90,.1), transparent 58%), linear-gradient(180deg,#0a0f17 0%,#090d14 100%); color:var(--ink); }
      .wrap { width:min(1060px, calc(100% - 28px)); margin:30px auto 44px; }
      .card { border:1px solid var(--line); border-radius:18px; background:rgba(11,17,27,.9); padding:0; box-shadow:0 24px 48px rgba(0,0,0,.35); overflow:hidden; }
      .top { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(0,1fr); }
      .top-image { min-height:320px; background-image:linear-gradient(20deg, rgba(9,13,20,.88), rgba(9,13,20,.24)), url("salon-boveda.jpeg"); background-size:cover; background-position:center; }
      .top-copy { padding:28px 24px; background:linear-gradient(150deg, rgba(17,24,39,.9), rgba(10,14,22,.98)); }
      .content { padding:26px 24px 28px; }
      h1 { margin:0 0 12px; color:var(--gold); font-size:clamp(1.55rem,2.8vw,2.1rem); line-height:1.2; }
      h2 { margin:0 0 8px; color:var(--ink); font-size:1.08rem; }
      p { margin:0 0 11px; color:var(--muted); line-height:1.66; }
      a { display:inline-block; margin-top:10px; text-decoration:none; color:#111827; background:var(--gold); border-radius:10px; padding:10px 14px; font-weight:700; }
      .ghost { margin-left:10px; background:transparent; color:var(--gold); border:1px solid rgba(243,216,138,.45); }
      .grid { margin-top:18px; display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
      .tile { border-radius:12px; border:1px solid var(--line-soft); background:rgba(17,24,39,.74); padding:15px; }
      .pill,.badge { display:inline-flex; font-size:.78rem; letter-spacing:.03em; text-transform:uppercase; color:var(--gold); border:1px solid rgba(243,216,138,.45); border-radius:999px; padding:4px 9px; margin-bottom:10px; }
      .badge { font-size:.76rem; letter-spacing:.04em; border-color:rgba(243,216,138,.4); }
      .tracks { margin-top:18px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
      .track { border:1px dashed rgba(243,216,138,.3); border-radius:12px; padding:14px; background:rgba(8,12,20,.6); }
      .track strong { display:block; margin-bottom:8px; color:#fff7de; }
      .small { font-size:.93rem; color:#aeb8c7; }
      .empty { padding:24px; }
      .closed { margin-top:16px; border-radius:12px; border:1px solid rgba(253,186,116,.45); background:rgba(154,52,18,.25); color:#ffd8ad; padding:14px; }
      .warn { margin-top:12px; border-radius:12px; border:1px solid rgba(252,165,165,.45); background:rgba(153,27,27,.25); color:#ffd0d0; padding:12px; }
      .ok { margin-top:12px; border-radius:12px; border:1px solid rgba(134,239,172,.45); background:rgba(20,83,45,.35); color:#d1fae5; padding:12px; }
      .share-form { margin-top:12px; display:grid; gap:10px; max-width:560px; }
      .share-form .row { display:grid; gap:10px; grid-template-columns:1fr 1fr; }
      .share-form label { display:grid; gap:6px; font-size:.92rem; color:#e8dcbf; }
      .share-form input { min-height:42px; border-radius:10px; border:1px solid rgba(243,216,138,.32); padding:0 12px; background:rgba(0,0,0,.22); color:#fff; }
      .btn { border:0; border-radius:10px; min-height:42px; padding:0 12px; background:linear-gradient(180deg,#d4af37,#ad8621); color:#17130a; font-weight:700; cursor:pointer; }
      .share-actions { margin-top:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
      .share-btn { border:1px solid rgba(243,216,138,.45); border-radius:10px; min-height:42px; padding:0 14px; background:rgba(243,216,138,.08); color:#fff7de; font-weight:700; cursor:pointer; }
      .share-btn.primary { border:0; background:linear-gradient(180deg,#d4af37,#ad8621); color:#17130a; }
      .share-status { margin:0; font-size:.9rem; color:#cfd7e5; }
      .share-link-wrap { display:none; margin-top:10px; }
      .share-link-input { width:100%; min-height:40px; border-radius:10px; border:1px solid rgba(243,216,138,.3); padding:0 12px; background:rgba(0,0,0,.2); color:#f8fafc; font-size:.88rem; }
      audio { width:100%; }
      @media (max-width:760px){ .top{grid-template-columns:1fr;} .top-image{min-height:220px;} .grid,.tracks,.share-form .row{grid-template-columns:1fr;} .ghost{margin-left:0;margin-top:10px;} .content{padding:22px 16px 24px;} .top-copy{padding:22px 16px;} }
    </style>
  </head>
  <body>
    <main class="wrap">
      <section class="card">
        <?php if (!$activeLink && $isShareMode): ?>
          <div class="top">
            <div class="top-image"></div>
            <div class="top-copy">
              <span class="badge">Invitación compartida</span>
              <h1>Acceso limitado</h1>
              <p>Este acceso se abre por invitación privada y tiene cupo reducido.</p>
              <p>Completa tus datos y confirma tu correo para activar la entrada.</p>
            </div>
          </div>
          <div class="content">
            <?php if ($shareError !== ''): ?>
              <div class="warn"><?php echo htmlspecialchars($shareError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($shareNotice !== ''): ?>
              <div class="ok"><?php echo htmlspecialchars($shareNotice, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form class="share-form" method="post" action="<?php echo htmlspecialchars($selfActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="claim_share" />
              <input type="hidden" name="share_from" value="<?php echo htmlspecialchars($shareFrom, ENT_QUOTES, 'UTF-8'); ?>" />
              <input type="hidden" name="share_sig" value="<?php echo htmlspecialchars($shareSig, ENT_QUOTES, 'UTF-8'); ?>" />
              <div class="row">
                <label>Nombre y apellidos
                  <input type="text" name="share_name" maxlength="120" required value="<?php echo htmlspecialchars($shareNameInput, ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>Correo electrónico
                  <input type="email" name="share_email" maxlength="160" required value="<?php echo htmlspecialchars($shareEmailInput, ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
              </div>
              <button class="btn" type="submit">Activar acceso</button>
            </form>
          </div>
        <?php elseif ($showVerifyState): ?>
          <div class="empty">
            <h1>Confirmación de acceso</h1>
            <?php if ($shareError !== ''): ?>
              <div class="warn"><?php echo htmlspecialchars($shareError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
              <p>No se pudo validar el acceso con este enlace.</p>
            <?php endif; ?>
          </div>
        <?php elseif (!$activeLink): ?>
          <div class="empty">
            <h1>Acceso no válido</h1>
            <p>Este enlace privado no existe o ha caducado.</p>
          </div>
        <?php else: ?>
          <?php $recipientName = trim((string) ($activeLink['recipient_name'] ?? '')) ?: 'invitado'; ?>
          <div class="top">
            <div class="top-image"></div>
            <div class="top-copy">
              <span class="badge">Acceso privado</span>
              <h1>Gracias, <?php echo htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'); ?></h1>
              <p>Esta página es tu acceso personal al regalo musical de <strong><?php echo htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
              <p>Tu enlace está activo y asociado a tu registro.</p>
              <?php if ($verifiedNow): ?>
                <div class="ok">Correo confirmado correctamente. Tu acceso ya está activo.</div>
              <?php endif; ?>
              <?php if ($accessUntilText !== ''): ?>
                <p><strong style="color:#f6de9f;">Disponible hasta:</strong> <?php echo htmlspecialchars($accessUntilText, ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
              <a href="<?php echo htmlspecialchars(EVENT_MAIN_WEBSITE_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Web principal</a>
              <a class="ghost" href="<?php echo htmlspecialchars(EVENT_SPOTIFY_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Spotify</a>
            </div>
          </div>

          <div class="content">
            <div class="grid">
              <article class="tile"><span class="pill">Homenaje</span><h2>México</h2><p>Dos canciones inéditas, compartidas en privado.</p></article>
              <article class="tile"><span class="pill">Acceso</span><h2>Personal e intransferible</h2><p>Este enlace identifica tu acceso y la escucha por pista.</p></article>
              <article class="tile"><span class="pill">Duración</span><h2>Ventana de 15 días</h2><p>Al finalizar el plazo, la reproducción se cierra automáticamente.</p></article>
            </div>

            <article class="tile" style="margin-top:16px;">
              <span class="pill">Compartir acceso</span>
              <h2>Invita hasta <?php echo EVENT_SHARE_LIMIT; ?> personas</h2>
              <p>Ya usaste <strong><?php echo $usedShares; ?></strong> de <?php echo EVENT_SHARE_LIMIT; ?> invitaciones. Restantes: <strong><?php echo $remainingShares; ?></strong>.</p>
              <?php if ($remainingShares > 0): ?>
                <p>Compártelo con un botón: la persona deja su correo y activa su acceso en segundos.</p>
                <div class="share-actions" data-share-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>">
                  <button class="share-btn primary" type="button" data-action="native-share">Compartir acceso</button>
                  <button class="share-btn" type="button" data-action="copy-link">Copiar enlace</button>
                  <button class="share-btn" type="button" data-action="whatsapp">WhatsApp</button>
                  <p class="share-status" data-role="share-status" aria-live="polite"></p>
                </div>
                <div class="share-link-wrap" data-role="share-link-wrap">
                  <input class="share-link-input" type="text" readonly value="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>" />
                </div>
              <?php else: ?>
                <div class="closed">Ya alcanzaste el límite de invitaciones compartidas para este acceso.</div>
              <?php endif; ?>
            </article>

            <?php if (!$isAccessOpen): ?>
              <div class="closed">Este acceso ha finalizado por vencimiento del plazo privado de escucha.</div>
            <?php elseif (count($tracks) === 0): ?>
              <div class="closed">Aún no hay pistas disponibles en el repositorio privado de audio.</div>
            <?php else: ?>
              <div class="tracks">
                <?php foreach ($tracks as $index => $track): ?>
                  <article class="track">
                    <strong>Pista <?php echo $index + 1; ?> · <?php echo htmlspecialchars($track['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <audio controls controlsList="nodownload noplaybackrate" preload="none" disablePictureInPicture data-track="<?php echo htmlspecialchars($track['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                      <source src="<?php echo htmlspecialchars($track['stream_url'], ENT_QUOTES, 'UTF-8'); ?>" type="audio/mpeg" />
                      Tu navegador no soporta reproducción de audio.
                    </audio>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <?php if ($activeLink): ?>
      <script>
        (function () {
          const token = <?php echo json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
          const shareBox = document.querySelector("[data-share-url]");

          if (shareBox) {
            const shareUrl = shareBox.getAttribute("data-share-url") || "";
            const shareStatus = shareBox.querySelector("[data-role='share-status']");
            const linkWrap = document.querySelector("[data-role='share-link-wrap']");
            const linkInput = linkWrap ? linkWrap.querySelector("input") : null;

            const setStatus = (text) => {
              if (shareStatus) shareStatus.textContent = text;
            };

            const copyLink = async () => {
              if (!shareUrl) return false;
              try {
                await navigator.clipboard.writeText(shareUrl);
                setStatus("Enlace copiado.");
                return true;
              } catch (err) {
                if (linkWrap) linkWrap.style.display = "block";
                if (linkInput) {
                  linkInput.focus();
                  linkInput.select();
                }
                setStatus("No se pudo copiar automáticamente. Copia manualmente el enlace.");
                return false;
              }
            };

            shareBox.addEventListener("click", async (event) => {
              const trigger = event.target.closest("button[data-action]");
              if (!trigger) return;
              const action = trigger.getAttribute("data-action");

              if (action === "native-share") {
                if (navigator.share) {
                  try {
                    await navigator.share({
                      title: "Acceso privado · Tú de qué vas",
                      text: "Te comparto mi acceso privado para escuchar las canciones.",
                      url: shareUrl
                    });
                    setStatus("Enlace compartido.");
                    return;
                  } catch (err) {
                    if (err && err.name === "AbortError") return;
                  }
                }
                await copyLink();
                return;
              }

              if (action === "copy-link") {
                await copyLink();
                return;
              }

              if (action === "whatsapp") {
                const message = "Te comparto mi acceso privado para escuchar las canciones: " + shareUrl;
                window.open("https://wa.me/?text=" + encodeURIComponent(message), "_blank", "noopener");
                setStatus("WhatsApp abierto.");
              }
            });
          }

          <?php if ($isAccessOpen && count($tracks) > 0): ?>
          const sendEvent = (track, eventType) => {
            const payload = new URLSearchParams();
            payload.set("token", token);
            payload.set("track", track);
            payload.set("event", eventType);
            navigator.sendBeacon(<?php echo json_encode(EVENT_PUBLIC_BASE_URL . '/track_gift_event.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, payload);
          };
          document.querySelectorAll("audio[data-track]").forEach((audio) => {
            const track = audio.dataset.track;
            if (!track) return;
            audio.addEventListener("play", () => { if (audio.currentTime < 1) sendEvent(track, "play"); });
            audio.addEventListener("ended", () => sendEvent(track, "ended"));
          });
          <?php endif; ?>
        })();
      </script>
    <?php endif; ?>
  </body>
</html>
