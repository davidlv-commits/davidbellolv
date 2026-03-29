<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = event_db();
$publicLink = EVENT_PUBLIC_BASE_URL . '/';
$code = trim((string) ($_GET['code'] ?? ''));
$message = '';
$messageType = '';
$submission = null;
$nowIso = event_now_iso();
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$ipRaw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ipRaw !== '' ? hash('sha256', $ipRaw . '|' . EVENT_TOKEN_SALT) : '';
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

$tracking = [
    'source' => trim((string) ($_GET['utm_source'] ?? $_POST['utm_source'] ?? 'direct')),
    'medium' => trim((string) ($_GET['utm_medium'] ?? $_POST['utm_medium'] ?? 'none')),
    'campaign' => trim((string) ($_GET['utm_campaign'] ?? $_POST['utm_campaign'] ?? '')),
    'landing_path' => $requestPath,
    'referrer' => $referrer,
    'ip_hash' => $ipHash,
    'user_agent' => $ua,
    'created_at' => $nowIso,
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    event_track_analytics($db, array_merge($tracking, [
        'event_type' => 'page_view',
        'status' => 'view',
    ]));
}

function event_generate_public_code(SQLite3 $db): string
{
    for ($i = 0; $i < 10; $i++) {
        $candidate = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $stmt = $db->prepare('SELECT COUNT(*) FROM rsvps WHERE public_code = :code');
        $stmt->bindValue(':code', $candidate, SQLITE3_TEXT);
        $exists = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
        if ($exists === 0) {
            return $candidate;
        }
    }
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}

function event_build_email_html(
    string $recipientName,
    string $statusLabel,
    string $publicCode,
    int $attendeesCount,
    string $groupOwner
): string {
    $safeRecipient = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($publicCode, ENT_QUOTES, 'UTF-8');
    $safeOwner = htmlspecialchars($groupOwner, ENT_QUOTES, 'UTF-8');
    $safeEvent = htmlspecialchars(EVENT_NAME, ENT_QUOTES, 'UTF-8');
    $safeLocation = htmlspecialchars(EVENT_LOCATION, ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars(EVENT_TIME_TEXT, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars(EVENT_PUBLIC_BASE_URL . '/?code=' . rawurlencode($publicCode), ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;background:#0f1115;font-family:Segoe UI,Arial,sans-serif;color:#111827;">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:22px 0;"><tr><td align="center">'
      . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">'
      . '<tr><td style="background:#111827;color:#f5d28e;padding:22px 26px;">'
      . '<h1 style="margin:0;font-size:24px;line-height:1.2;">Tu invitación está registrada</h1>'
      . '<p style="margin:8px 0 0;font-size:14px;color:#f3e8c8;">Presentación de libro · Salón Bóveda</p>'
      . '</td></tr>'
      . '<tr><td style="padding:24px 26px;">'
      . '<p style="margin:0 0 10px;">Hola <strong>' . $safeRecipient . '</strong>,</p>'
      . '<p style="margin:0 0 14px;">Tu registro para <strong>' . $safeEvent . '</strong> quedó en estado: <strong>' . $safeStatus . '</strong>.</p>'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">'
      . '<tr><td style="padding:14px;">'
      . '<p style="margin:0 0 6px;"><strong>Evento:</strong> ' . $safeEvent . '</p>'
      . '<p style="margin:0 0 6px;"><strong>Lugar:</strong> ' . $safeLocation . ' (Salón Bóveda)</p>'
      . '<p style="margin:0 0 6px;"><strong>Hora:</strong> ' . $safeTime . '</p>'
      . '<p style="margin:0 0 6px;"><strong>Código:</strong> ' . $safeCode . '</p>'
      . '<p style="margin:0;"><strong>Grupo:</strong> ' . $safeOwner . ' · ' . $attendeesCount . ' asistente(s)</p>'
      . '</td></tr></table>'
      . '<p style="margin:14px 0 8px;">Tu enlace privado de referencia:</p>'
      . '<p style="margin:0 0 14px;word-break:break-all;"><a href="' . $safeLink . '">' . $safeLink . '</a></p>'
      . '<p style="margin:0;color:#4b5563;font-size:13px;">Gracias por acompañarnos en esta noche especial en Morelia.</p>'
      . '</td></tr>'
      . '</table></td></tr></table></body></html>';
}

function event_recent_duplicate_rsvp(
    SQLite3 $db,
    string $firstName,
    string $lastName,
    string $email,
    string $phone,
    int $attendeesCount,
    string $companionsJson,
    int $windowSeconds = 21600
): ?array {
    $stmt = $db->prepare(
        'SELECT *
         FROM rsvps
         WHERE first_name = :first_name
           AND last_name = :last_name
           AND email = :email
           AND phone = :phone
           AND attendees_count = :attendees_count
           AND companions = :companions
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->bindValue(':first_name', $firstName, SQLITE3_TEXT);
    $stmt->bindValue(':last_name', $lastName, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
    $stmt->bindValue(':attendees_count', $attendeesCount, SQLITE3_INTEGER);
    $stmt->bindValue(':companions', $companionsJson, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$row) {
        return null;
    }

    $createdRaw = trim((string) ($row['created_at'] ?? ''));
    if ($createdRaw === '') {
        return null;
    }

    try {
        $createdAt = new DateTimeImmutable($createdRaw, new DateTimeZone(EVENT_TIMEZONE));
        $now = new DateTimeImmutable('now', new DateTimeZone(EVENT_TIMEZONE));
        $diff = abs($now->getTimestamp() - $createdAt->getTimestamp());
        if ($diff > $windowSeconds) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }

    return $row;
}

$formToken = (string) ($_SESSION['event_form_token'] ?? '');
if ($formToken === '') {
    $formToken = bin2hex(random_bytes(16));
    $_SESSION['event_form_token'] = $formToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedFormToken = trim((string) ($_POST['form_token'] ?? ''));
    $sessionFormToken = (string) ($_SESSION['event_form_token'] ?? '');
    $usedTokens = $_SESSION['event_used_form_tokens'] ?? [];
    if (!is_array($usedTokens)) {
        $usedTokens = [];
    }

    if (
        $postedFormToken === '' ||
        $sessionFormToken === '' ||
        !hash_equals($sessionFormToken, $postedFormToken) ||
        in_array($postedFormToken, $usedTokens, true)
    ) {
        $message = 'Este formulario ya fue enviado o caducó. Actualiza la página antes de reenviar.';
        $messageType = 'warn';
        event_track_analytics($db, array_merge($tracking, [
            'event_type' => 'submit_invalid',
            'status' => 'invalid_or_reused_form_token',
        ]));
    } else {
        $usedTokens[] = $postedFormToken;
        if (count($usedTokens) > 20) {
            $usedTokens = array_slice($usedTokens, -20);
        }
        $_SESSION['event_used_form_tokens'] = $usedTokens;
        $_SESSION['event_form_token'] = bin2hex(random_bytes(16));
        $formToken = (string) $_SESSION['event_form_token'];
    }

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $attendeesCount = (int) ($_POST['attendees_count'] ?? 1);
    $attendeesCount = max(1, min(6, $attendeesCount));

    event_track_analytics($db, array_merge($tracking, [
        'event_type' => 'submit_attempt',
        'status' => 'attempt',
        'attendees_requested' => $attendeesCount,
        'email_hash' => event_email_hash($email),
    ]));

    $firstName = preg_replace('/\s+/', ' ', $firstName) ?? $firstName;
    $lastName = preg_replace('/\s+/', ' ', $lastName) ?? $lastName;

    $companionsList = [];
    for ($i = 1; $i <= ($attendeesCount - 1); $i++) {
        $cFirst = trim((string) ($_POST['companion_first_name_' . $i] ?? ''));
        $cLast = trim((string) ($_POST['companion_last_name_' . $i] ?? ''));
        $cEmail = trim((string) ($_POST['companion_email_' . $i] ?? ''));
        $cPhone = trim((string) ($_POST['companion_phone_' . $i] ?? ''));

        $cFirst = preg_replace('/\s+/', ' ', $cFirst) ?? $cFirst;
        $cLast = preg_replace('/\s+/', ' ', $cLast) ?? $cLast;

        if ($cFirst === '' || $cLast === '') {
            $message = 'Completa nombre y apellidos de todos los acompañantes.';
            $messageType = 'err';
            break;
        }
        if ($cEmail !== '' && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Uno de los correos de acompañante no es válido.';
            $messageType = 'err';
            break;
        }

        $companionsList[] = [
            'first_name' => $cFirst,
            'last_name' => $cLast,
            'email' => $cEmail,
            'phone' => $cPhone,
        ];
    }

    if ($message === '') {
        if ($firstName === '' || $lastName === '') {
            $message = 'Nombre y apellidos son obligatorios.';
            $messageType = 'err';
            event_track_analytics($db, array_merge($tracking, [
                'event_type' => 'submit_invalid',
                'status' => 'invalid_name',
                'attendees_requested' => $attendeesCount,
                'email_hash' => event_email_hash($email),
            ]));
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'El correo no tiene un formato válido.';
            $messageType = 'err';
            event_track_analytics($db, array_merge($tracking, [
                'event_type' => 'submit_invalid',
                'status' => 'invalid_email',
                'attendees_requested' => $attendeesCount,
            ]));
        } else {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $createdAt = event_now_iso();
                $companionsJson = json_encode($companionsList, JSON_UNESCAPED_UNICODE);
                $companionsJson = $companionsJson === false ? '[]' : $companionsJson;

                $duplicate = event_recent_duplicate_rsvp(
                    $db,
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $attendeesCount,
                    (string) $companionsJson
                );

                if ($duplicate) {
                    $db->exec('ROLLBACK');
                    $code = (string) ($duplicate['public_code'] ?? '');
                    $submission = $duplicate;
                    $groupOwner = $firstName . ' ' . $lastName;
                    $message = 'Detectamos un envío repetido y conservamos tu registro original. No se descontaron plazas adicionales.';
                    $messageType = 'dup';
                    event_track_analytics($db, array_merge($tracking, [
                        'event_type' => 'submit_result',
                        'status' => 'duplicate_replay_blocked',
                        'attendees_requested' => $attendeesCount,
                        'email_hash' => event_email_hash($email),
                    ]));
                    throw new RuntimeException('duplicate_replay_blocked');
                }

                $availableSeats = event_available_seats($db);
                $status = $attendeesCount <= $availableSeats ? 'confirmed' : 'full';
                $publicCode = event_generate_public_code($db);

                $insert = $db->prepare(
                    'INSERT INTO rsvps
                     (public_code, first_name, last_name, email, phone, attendees_count, companions, status, created_at)
                     VALUES
                     (:public_code, :first_name, :last_name, :email, :phone, :attendees_count, :companions, :status, :created_at)'
                );
                $insert->bindValue(':public_code', $publicCode, SQLITE3_TEXT);
                $insert->bindValue(':first_name', $firstName, SQLITE3_TEXT);
                $insert->bindValue(':last_name', $lastName, SQLITE3_TEXT);
                $insert->bindValue(':email', $email, SQLITE3_TEXT);
                $insert->bindValue(':phone', $phone, SQLITE3_TEXT);
                $insert->bindValue(':attendees_count', $attendeesCount, SQLITE3_INTEGER);
                $insert->bindValue(':companions', (string) $companionsJson, SQLITE3_TEXT);
                $insert->bindValue(':status', $status, SQLITE3_TEXT);
                $insert->bindValue(':created_at', $createdAt, SQLITE3_TEXT);
                $ok = $insert->execute();
                if ($ok === false) {
                    throw new RuntimeException('No se pudo guardar el registro');
                }
                $db->exec('COMMIT');
                $code = $publicCode;

                $statusLabel = $status === 'confirmed' ? 'Confirmado' : 'Lista de espera';
                $groupOwner = $firstName . ' ' . $lastName;
                $subject = ($status === 'confirmed' ? 'Confirmación' : 'Lista de espera') . ' · Presentación del libro';

                if ($email !== '') {
                    event_send_html_email(
                        $email,
                        $subject,
                        event_build_email_html($groupOwner, $statusLabel, $publicCode, $attendeesCount, $groupOwner)
                    );
                }
                foreach ($companionsList as $companion) {
                    $companionEmail = trim((string) ($companion['email'] ?? ''));
                    if ($companionEmail === '') {
                        continue;
                    }
                    $companionName = trim((string) ($companion['first_name'] ?? '') . ' ' . (string) ($companion['last_name'] ?? ''));
                    event_send_html_email(
                        $companionEmail,
                        $subject,
                        event_build_email_html($companionName, $statusLabel, $publicCode, $attendeesCount, $groupOwner)
                    );
                }

                if ($status === 'confirmed') {
                    $message = 'Gracias, ' . $groupOwner . '. Tu reserva está confirmada y nos alegra profundamente contar contigo en esta presentación tan especial. Te hemos enviado la invitación al/los correos indicados.';
                    $messageType = 'ok';
                    event_track_analytics($db, array_merge($tracking, [
                        'event_type' => 'submit_result',
                        'status' => 'confirmed',
                        'attendees_requested' => $attendeesCount,
                        'email_hash' => event_email_hash($email),
                    ]));
                } else {
                    $message = 'Gracias de corazón, ' . $groupOwner . '. El aforo está completo en este momento y tu solicitud quedó en lista de espera prioritaria. Valoramos muchísimo tu interés y te notificaremos por correo en cuanto se libere un espacio.';
                    $messageType = 'warn';
                    event_track_analytics($db, array_merge($tracking, [
                        'event_type' => 'submit_result',
                        'status' => 'full',
                        'attendees_requested' => $attendeesCount,
                        'email_hash' => event_email_hash($email),
                    ]));
                }
            } catch (Throwable $e) {
                if ($e->getMessage() !== 'duplicate_replay_blocked') {
                    $db->exec('ROLLBACK');
                    $message = 'No se pudo completar el registro. Inténtalo de nuevo en unos segundos.';
                    $messageType = 'err';
                    event_track_analytics($db, array_merge($tracking, [
                        'event_type' => 'submit_error',
                        'status' => 'exception',
                        'attendees_requested' => $attendeesCount,
                        'email_hash' => event_email_hash($email),
                    ]));
                }
            }
        }
    }
}

if ($code !== '') {
    $stmt = $db->prepare('SELECT * FROM rsvps WHERE public_code = :code LIMIT 1');
    $stmt->bindValue(':code', strtoupper($code), SQLITE3_TEXT);
    $res = $stmt->execute();
    $submission = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
}

$availableSeats = event_available_seats($db);
$companionsData = [];
if ($submission && trim((string) ($submission['companions'] ?? '')) !== '') {
    $decoded = json_decode((string) $submission['companions'], true);
    if (is_array($decoded)) {
        $companionsData = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow,noarchive" />
    <title>Invitación | Presentación del libro</title>
    <style>
      :root {
        --bg: #0e1014;
        --surface: #171b22;
        --surface-soft: #1d222b;
        --line: rgba(212, 175, 55, 0.2);
        --text: #f3f1eb;
        --muted: #c2b8a3;
        --gold: #d4af37;
        --gold-soft: #f3d88a;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: "Avenir Next", "Segoe UI", sans-serif;
        color: var(--text);
        background:
          radial-gradient(circle at 15% 0%, rgba(212,175,55,0.12), transparent 45%),
          radial-gradient(circle at 85% 20%, rgba(212,175,55,0.08), transparent 35%),
          var(--bg);
      }
      .hero {
        min-height: 560px;
        background-image: linear-gradient(130deg, rgba(9,11,15,.72), rgba(9,11,15,.48)), url('./salon-boveda.jpeg');
        background-size: cover;
        background-position: center;
        display: flex;
        align-items: flex-end;
      }
      .inner {
        width: min(1060px, calc(100% - 28px));
        margin: 0 auto;
      }
      .hero .inner { padding: 42px 0; }
      h1 {
        margin: 0;
        color: #fff;
        font-size: clamp(2.1rem, 5vw, 3.5rem);
        letter-spacing: 0.01em;
      }
      .hero p {
        margin: 10px 0 0;
        color: #ece5d3;
        font-size: 1.04rem;
      }
      .main {
        padding: 24px 0 52px;
      }
      .card {
        background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 22px;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.34);
        margin-bottom: 14px;
      }
      h2 {
        margin: 0 0 10px;
        color: var(--gold-soft);
        font-size: 1.4rem;
        font-weight: 600;
      }
      .grid {
        display: grid;
        gap: 12px;
        grid-template-columns: 1fr 1fr;
      }
      .muted {
        color: var(--muted);
        margin: 0;
        line-height: 1.62;
      }
      .hero-links {
        margin-top: 14px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
      }
      .hero-links a {
        text-decoration: none;
        color: #f7edcf;
        border: 1px solid rgba(243, 216, 138, 0.35);
        background: rgba(243, 216, 138, 0.1);
        border-radius: 10px;
        padding: 8px 11px;
        font-size: 0.9rem;
      }
      .artists {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: 12px;
      }
      .artist {
        border: 1px solid rgba(243, 216, 138, 0.25);
        border-radius: 12px;
        padding: 10px;
        background: rgba(255, 255, 255, 0.02);
      }
      .artist strong { display: block; color: #f5e8bf; }
      .author-box {
        display: grid;
        grid-template-columns: 230px 1fr;
        gap: 16px;
        align-items: center;
      }
      .author-box img {
        width: 100%;
        border-radius: 14px;
        border: 1px solid rgba(243, 216, 138, 0.35);
        display: block;
      }
      .book-box {
        display: grid;
        grid-template-columns: 190px 1fr;
        gap: 18px;
        align-items: start;
      }
      .book-cover {
        width: 100%;
        border-radius: 14px;
        border: 1px solid rgba(243, 216, 138, 0.35);
        display: block;
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.28);
      }
      .book-cta {
        margin-top: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        border: 1px solid rgba(243, 216, 138, 0.4);
        background: linear-gradient(180deg, rgba(212,175,55,0.22), rgba(212,175,55,0.14));
        color: #fff4d2;
        border-radius: 10px;
        min-height: 42px;
        padding: 0 14px;
        font-weight: 700;
      }
      .review-grid {
        margin-top: 12px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
      }
      .review {
        border: 1px solid rgba(243, 216, 138, 0.2);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.02);
        padding: 12px;
      }
      .review p {
        margin: 0;
        color: #e8dcc0;
        line-height: 1.6;
        font-style: italic;
      }
      .review span {
        display: block;
        margin-top: 8px;
        font-size: 0.85rem;
        color: #f3d88a;
      }
      .mini-note {
        margin-top: 12px;
        color: #d8cfbd;
        font-size: 0.94rem;
      }
      .counter {
        margin: 0 0 12px;
        color: #e8dcc0;
      }
      .status {
        margin: 10px 0;
        padding: 11px 13px;
        border-radius: 12px;
        font-size: 0.95rem;
      }
      .ok { background: rgba(6,95,70,0.24); border: 1px solid rgba(110,231,183,0.45); color: #b7f2dc; }
      .warn { background: rgba(154,52,18,0.25); border: 1px solid rgba(253,186,116,0.45); color: #ffd6a1; }
      .dup { background: rgba(154,52,18,0.25); border: 1px solid rgba(253,186,116,0.45); color: #ffd6a1; }
      .err { background: rgba(153,27,27,0.25); border: 1px solid rgba(252,165,165,0.45); color: #ffc9c9; }
      form { display: grid; gap: 12px; }
      label { display: grid; gap: 6px; color: #e9ddc2; font-size: 0.92rem; }
      input, select {
        min-height: 42px;
        border: 1px solid rgba(243,216,138,0.32);
        border-radius: 10px;
        padding: 10px 12px;
        font: inherit;
        background: rgba(0,0,0,0.22);
        color: #fff;
      }
      input::placeholder { color: #b3b3b3; }
      .companions-wrap {
        border: 1px solid rgba(243,216,138,0.28);
        border-radius: 12px;
        padding: 12px;
        background: rgba(0,0,0,0.18);
      }
      .companion-card {
        border: 1px solid rgba(243,216,138,0.2);
        border-radius: 10px;
        background: rgba(255,255,255,0.02);
        padding: 10px;
        margin-bottom: 10px;
      }
      .companion-card:last-child { margin-bottom: 0; }
      .btn {
        border: 0;
        border-radius: 12px;
        min-height: 46px;
        background: linear-gradient(180deg, #d4af37, #ad8621);
        color: #17130a;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
      }
      .btn[disabled] {
        opacity: 0.65;
        cursor: not-allowed;
      }
      .invite-card {
        margin-top: 14px;
        border: 1px dashed rgba(243,216,138,0.6);
        background: rgba(212,175,55,0.08);
        border-radius: 14px;
        padding: 14px;
      }
      .confirm-overlay {
        position: fixed;
        inset: 0;
        background: rgba(5, 7, 10, 0.68);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px;
        z-index: 1200;
      }
      .confirm-overlay[hidden] { display: none; }
      .confirm-modal {
        width: min(700px, 100%);
        border-radius: 18px;
        border: 1px solid rgba(243, 216, 138, 0.55);
        background: linear-gradient(180deg, #171b22 0%, #10141b 100%);
        color: #f3f1eb;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        padding: 22px;
      }
      .confirm-modal h3 {
        margin: 0 0 8px;
        font-size: clamp(1.4rem, 4vw, 2rem);
        color: #f5df9f;
      }
      .confirm-modal p {
        margin: 0;
        color: #e6ddcb;
        line-height: 1.6;
      }
      .confirm-actions {
        margin-top: 16px;
        display: flex;
        justify-content: flex-end;
      }
      .confirm-btn {
        border: 0;
        border-radius: 10px;
        min-height: 40px;
        padding: 0 14px;
        background: linear-gradient(180deg, #d4af37, #ad8621);
        color: #17130a;
        font-weight: 700;
        cursor: pointer;
      }
      .invite-card h3 { margin: 0 0 8px; color: #f5df9f; }
      .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
      .quiet-link {
        margin-top: 8px;
        font-size: 0.92rem;
      }
      .quiet-link a { color: #f3d88a; }
      @media (max-width: 920px) {
        .artists { grid-template-columns: 1fr; }
        .author-box { grid-template-columns: 1fr; }
        .book-box { grid-template-columns: 1fr; }
      }
      @media (max-width: 740px) {
        .grid { grid-template-columns: 1fr; }
        .review-grid { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body>
    <?php if ($message !== ''): ?>
      <div class="confirm-overlay" id="confirm_overlay">
        <div class="confirm-modal">
          <h3><?php echo $messageType === 'ok' ? 'Registro confirmado' : ($messageType === 'warn' ? 'Aforo completo' : ($messageType === 'dup' ? 'Registro ya recibido' : 'Atención')); ?></h3>
          <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
          <div class="confirm-actions">
            <button class="confirm-btn" type="button" id="confirm_close">Entendido</button>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <header class="hero">
      <div class="inner">
        <h1>Presentación del libro en vivo</h1>
        <p>Hotel de la Soledad · Salón Bóveda · Morelia · Hoy 7:00 PM</p>
      </div>
    </header>

    <main class="main">
      <div class="inner">
        <section class="card author-box">
          <img src="./autor-david.jpeg" alt="David Bello López-Valeiras" />
          <div>
            <h2>David Bello López-Valeiras · “<?php echo htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8'); ?>”</h2>
            <p class="muted">
              Esta presentación de <strong>“<?php echo htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8'); ?>”</strong> será una noche muy personal:
              palabras, música en vivo y gratitud compartida en una ciudad, <strong>Morelia</strong>, que ya forma parte de mi historia.
              El acceso es por registro previo y aforo limitado.
            </p>
            <p class="mini-note">Durante el evento habrá consumo libre.</p>
            <div class="hero-links">
              <a href="<?php echo htmlspecialchars(EVENT_MAIN_WEBSITE_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Web principal</a>
              <a href="<?php echo htmlspecialchars(EVENT_SPOTIFY_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Música en Spotify</a>
            </div>
          </div>
        </section>

        <section class="card">
          <h2>Música en directo y agradecimientos</h2>
          <p class="muted">Como invitadas del <a href="https://www.cenart.gob.mx/" target="_blank" rel="noopener" style="color:#f3d88a;">Centro Nacional de las Artes</a>, nos acompañará un trío de cuerdas:</p>
          <div class="artists">
            <article class="artist"><strong>Vania Méndez</strong><span class="muted">Violín</span></article>
            <article class="artist"><strong>Sahori Velázquez</strong><span class="muted">Viola</span></article>
            <article class="artist"><strong>Valeria Sánchez</strong><span class="muted">Violonchelo</span></article>
          </div>
          <p class="muted" style="margin-top:12px;">
            Agradecimiento especial al Pianista y Compositor <strong>Gabriel Domínguez</strong> por su apoyo al proyecto.
          </p>
          <p class="muted" style="margin-top:8px;">
            Gracias a <strong>Red Global Creativa</strong> y a sus cofundadoras y CEOs, <strong>Cecilia Álvarez</strong> y <strong>Clarissa Cuevas</strong>,
            por impulsar iniciativas culturales y creativas, y apoyarme en esta presentación, y a todos los grandes artistas y escritores que tuve oportunidad de conocer en estos días:
            <a href="https://www.glamourartandbooks.com/red-global-creativa/membresia-anual" target="_blank" rel="noopener" style="color:#f3d88a;">sitio de Red Global Creativa</a>.
          </p>
          <p class="muted" style="margin-top:8px;">
            Muy especialmente, gracias a <strong>Gaby Coronado</strong>, mi ya eterna amiga, y a
            <strong>Roberto Monroy García, Secretario de Turismo del Estado de Michoacán</strong>,
            por su enorme apoyo y por ser un gran anfitrión de su tierra
            (<a href="https://sectur.michoacan.gob.mx/" target="_blank" rel="noopener" style="color:#f3d88a;">SECTUR Michoacán</a>).
          </p>
          <p class="muted" style="margin-top:8px;">
            Un agradecimiento muy especial al maravilloso <a href="https://www.hoteldelasoledad.com/" target="_blank" rel="noopener" style="color:#f3d88a;"><strong>Hotel de la Soledad</strong></a> y a su entrañable dueña, <strong>Leticia</strong>, por abrirme su casa con tanto cariño; por muchos besos más.
          </p>
          <p class="muted" style="margin-top:8px;">
            Y a Morelia, ciudad de luz, cantera y alma viva: gracias por abrazarme con su belleza serena, su historia y la calidez irrepetible de su gente.
            Millones de gracias, me llevo grandes amigos de este paso por esta tierra y amenazo con volver pronto, GRACIAS &#10084;
          </p>
        </section>

        <section class="card">
          <h2>Registro de asistencia</h2>
          <p class="counter">Aforo máximo: <?php echo EVENT_CAPACITY; ?> personas | Plazas disponibles ahora: <strong><?php echo $availableSeats; ?></strong></p>

          <?php if ($message !== ''): ?>
            <div class="status <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>

          <?php if ($submission): ?>
            <?php $stateLabel = (string) $submission['status'] === 'confirmed' ? 'Confirmado' : 'Lista de espera'; ?>
            <article class="invite-card">
              <h3>Gracias por registrarte, <?php echo htmlspecialchars((string) $submission['first_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
              <p><strong>Nombre:</strong> <?php echo htmlspecialchars((string) $submission['first_name'] . ' ' . (string) $submission['last_name'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p><strong>Asistentes:</strong> <?php echo (int) $submission['attendees_count']; ?></p>
              <p><strong>Estado:</strong> <?php echo htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8'); ?></p>
              <p><strong>Código:</strong> <span class="mono"><?php echo htmlspecialchars((string) $submission['public_code'], ENT_QUOTES, 'UTF-8'); ?></span></p>
              <p class="quiet-link">Enlace del evento: <a href="<?php echo htmlspecialchars($publicLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($publicLink, ENT_QUOTES, 'UTF-8'); ?></a></p>
              <?php if (count($companionsData) > 0): ?>
                <p class="muted" style="margin-top:8px;">Acompañantes registrados:
                  <?php
                  $names = [];
                  foreach ($companionsData as $item) {
                      $n = trim((string) ($item['first_name'] ?? '') . ' ' . (string) ($item['last_name'] ?? ''));
                      if ($n !== '') {
                          $names[] = $n;
                      }
                  }
                  echo htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8');
                  ?>
                </p>
              <?php endif; ?>
            </article>
          <?php endif; ?>

          <form method="post" action="index.php">
            <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="utm_source" value="<?php echo htmlspecialchars((string) $tracking['source'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="utm_medium" value="<?php echo htmlspecialchars((string) $tracking['medium'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="utm_campaign" value="<?php echo htmlspecialchars((string) $tracking['campaign'], ENT_QUOTES, 'UTF-8'); ?>" />
            <div class="grid">
              <label>Nombre<input type="text" name="first_name" maxlength="80" required /></label>
              <label>Apellidos<input type="text" name="last_name" maxlength="120" required /></label>
            </div>
            <div class="grid">
              <label>Correo (recomendado para autorespuesta)<input type="email" name="email" maxlength="160" /></label>
              <label>Teléfono de contacto (opcional)<input type="text" name="phone" maxlength="30" placeholder="Ejemplo: +52 443 123 4567" /></label>
            </div>
            <div class="grid">
              <label>Número total de asistentes (incluyéndote)
                <select name="attendees_count" id="attendees_count">
                  <option value="1">1</option><option value="2">2</option><option value="3">3</option>
                  <option value="4">4</option><option value="5">5</option><option value="6">6</option>
                </select>
              </label>
              <div></div>
            </div>
            <div class="companions-wrap" id="companions_wrap" hidden>
              <strong>Datos de acompañantes</strong>
              <div id="companions_fields" style="margin-top:10px;"></div>
            </div>
            <button class="btn" id="submit_btn" type="submit">Confirmar asistencia</button>
          </form>
        </section>

        <section class="card book-box">
          <img class="book-cover" src="<?php echo htmlspecialchars(EVENT_BOOK_COVER_URL, ENT_QUOTES, 'UTF-8'); ?>" alt="Portada del libro <?php echo htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8'); ?>" />
          <div>
            <h2>Reseñas de “<?php echo htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8'); ?>”</h2>
            <p class="muted">
              La novela ya está recibiendo reseñas muy potentes. Si quieres leerla antes o después de la presentación, aquí tienes el acceso directo a la tienda.
            </p>
            <a class="book-cta" href="<?php echo htmlspecialchars(EVENT_BOOK_AMAZON_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Ver libro en Amazon</a>
            <div class="review-grid">
              <article class="review">
                <p>“Hay libros que cuentan una historia y otros que te hacen vivirla. ‘Tú de qué vas’ te sacude y te deja pensando.”</p>
                <span>Mariana · Guadalajara</span>
              </article>
              <article class="review">
                <p>“Me atrapó desde la primera página. Es una historia emocional y honesta que pega fuerte.”</p>
                <span>Fernanda · Puebla</span>
              </article>
            </div>
          </div>
        </section>
      </div>
    </main>

    <script>
      (function () {
        const overlay = document.getElementById("confirm_overlay");
        const closeBtn = document.getElementById("confirm_close");
        if (overlay && closeBtn) {
          closeBtn.addEventListener("click", function () {
            overlay.setAttribute("hidden", "hidden");
          });
          overlay.addEventListener("click", function (event) {
            if (event.target === overlay) {
              overlay.setAttribute("hidden", "hidden");
            }
          });
          document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
              overlay.setAttribute("hidden", "hidden");
            }
          });
        }

        const attendees = document.getElementById("attendees_count");
        const wrap = document.getElementById("companions_wrap");
        const fields = document.getElementById("companions_fields");

        function renderCompanions() {
          const total = parseInt(attendees.value, 10) || 1;
          const companions = Math.max(0, total - 1);
          fields.innerHTML = "";
          wrap.hidden = companions === 0;
          for (let i = 1; i <= companions; i += 1) {
            const card = document.createElement("div");
            card.className = "companion-card";
            card.innerHTML =
              '<div class="grid">' +
                '<label>Nombre acompañante ' + i + '<input type="text" name="companion_first_name_' + i + '" maxlength="80" required></label>' +
                '<label>Apellidos acompañante ' + i + '<input type="text" name="companion_last_name_' + i + '" maxlength="120" required></label>' +
              '</div>' +
              '<div class="grid" style="margin-top:10px;">' +
                '<label>Correo acompañante ' + i + ' (opcional)<input type="email" name="companion_email_' + i + '" maxlength="160"></label>' +
                '<label>Teléfono acompañante ' + i + ' (opcional)<input type="text" name="companion_phone_' + i + '" maxlength="30"></label>' +
              '</div>';
            fields.appendChild(card);
          }
        }

        attendees.addEventListener("change", renderCompanions);
        renderCompanions();

        const form = document.querySelector('form[method="post"][action="index.php"]');
        const submitBtn = document.getElementById("submit_btn");
        if (form && submitBtn) {
          form.addEventListener("submit", function () {
            if (submitBtn.disabled) {
              return;
            }
            submitBtn.disabled = true;
            submitBtn.textContent = "Enviando...";
          });
        }
      })();
    </script>
  </body>
</html>
