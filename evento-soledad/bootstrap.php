<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(EVENT_TIMEZONE);

$GLOBALS['event_last_error'] = '';

function event_set_last_error(string $message): void
{
    $GLOBALS['event_last_error'] = $message;
}

function event_get_last_error(): string
{
    return (string) ($GLOBALS['event_last_error'] ?? '');
}

function event_db(): SQLite3
{
    $dbDir = dirname(EVENT_DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $db = new SQLite3(EVENT_DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');

    $db->exec(
        'CREATE TABLE IF NOT EXISTS invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT "pending",
            invitee_email TEXT,
            first_name TEXT,
            last_name TEXT,
            email TEXT,
            created_at TEXT NOT NULL,
            responded_at TEXT
        )'
    );

    // Schema drift protection for existing DBs.
    $columns = [];
    $colResult = $db->query('PRAGMA table_info(invitations)');
    while ($row = $colResult->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = (string) ($row['name'] ?? '');
    }
    if (!in_array('invitee_email', $columns, true)) {
        $db->exec('ALTER TABLE invitations ADD COLUMN invitee_email TEXT');
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS rsvps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_code TEXT NOT NULL UNIQUE,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT,
            phone TEXT,
            attendees_count INTEGER NOT NULL DEFAULT 1,
            companions TEXT,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS post_event_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rsvp_id INTEGER NOT NULL,
            recipient_name TEXT NOT NULL,
            recipient_email TEXT NOT NULL,
            role TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            parent_token TEXT,
            sent_at TEXT,
            created_at TEXT NOT NULL
        )'
    );

    // Schema drift protection for post_event_links.
    $linkColumns = [];
    $linkColResult = $db->query('PRAGMA table_info(post_event_links)');
    while ($row = $linkColResult->fetchArray(SQLITE3_ASSOC)) {
        $linkColumns[] = (string) ($row['name'] ?? '');
    }
    if (!in_array('parent_token', $linkColumns, true)) {
        $db->exec('ALTER TABLE post_event_links ADD COLUMN parent_token TEXT');
    }
    if (!in_array('verify_token', $linkColumns, true)) {
        $db->exec('ALTER TABLE post_event_links ADD COLUMN verify_token TEXT');
    }
    if (!in_array('verified_at', $linkColumns, true)) {
        $db->exec('ALTER TABLE post_event_links ADD COLUMN verified_at TEXT');
    }
    if (!in_array('verification_sent_at', $linkColumns, true)) {
        $db->exec('ALTER TABLE post_event_links ADD COLUMN verification_sent_at TEXT');
    }

    // Backward compatibility: old shared links without verify_token are treated as already verified.
    $db->exec(
        "UPDATE post_event_links
         SET verified_at = COALESCE(sent_at, created_at)
         WHERE role = 'gift_shared'
           AND (verify_token IS NULL OR TRIM(verify_token) = '')
           AND (verified_at IS NULL OR TRIM(verified_at) = '')"
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS post_event_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL,
            visited_at TEXT NOT NULL,
            ip_hash TEXT,
            user_agent TEXT
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS post_event_audio_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL,
            recipient_name TEXT,
            recipient_email TEXT,
            track_filename TEXT NOT NULL,
            event_type TEXT NOT NULL,
            created_at TEXT NOT NULL,
            ip_hash TEXT,
            user_agent TEXT
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS event_analytics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            status TEXT,
            created_at TEXT NOT NULL,
            source TEXT,
            medium TEXT,
            campaign TEXT,
            referrer TEXT,
            landing_path TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            attendees_requested INTEGER,
            email_hash TEXT
        )'
    );

    return $db;
}

function event_generate_token(int $length = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
}

function event_available_spots(SQLite3 $db): int
{
    $confirmed = (int) $db->querySingle("SELECT COUNT(*) FROM invitations WHERE status='confirmed'");
    return max(0, EVENT_CAPACITY - $confirmed);
}

function event_send_email(string $to, string $subject, string $message): bool
{
    if (trim($to) === '') {
        return false;
    }
    if (defined('EVENT_SMTP_ENABLED') && EVENT_SMTP_ENABLED) {
        return event_send_smtp_mail($to, $subject, nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')), true);
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: ' . EVENT_SMTP_FROM_NAME . ' <' . EVENT_SMTP_FROM_EMAIL . '>',
        'Reply-To: ' . EVENT_SMTP_FROM_EMAIL,
    ];
    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function event_send_html_email(string $to, string $subject, string $htmlBody): bool
{
    if (trim($to) === '') {
        return false;
    }
    if (defined('EVENT_SMTP_ENABLED') && EVENT_SMTP_ENABLED) {
        return event_send_smtp_mail($to, $subject, $htmlBody, true);
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . EVENT_SMTP_FROM_NAME . ' <' . EVENT_SMTP_FROM_EMAIL . '>',
        'Reply-To: ' . EVENT_SMTP_FROM_EMAIL,
        'X-Mailer: PHP/' . phpversion(),
    ];
    return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}

function event_encode_header_utf8(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function event_smtp_expect($socket, array $expectedCodes): bool
{
    $line = '';
    $code = null;
    while (($chunk = fgets($socket, 515)) !== false) {
        $line = rtrim($chunk, "\r\n");
        if (strlen($line) >= 3 && ctype_digit(substr($line, 0, 3))) {
            $code = (int) substr($line, 0, 3);
            if (isset($line[3]) && $line[3] === '-') {
                continue;
            }
            break;
        }
    }
    return $code !== null && in_array($code, $expectedCodes, true);
}

function event_smtp_send($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function event_send_smtp_mail(string $to, string $subject, string $body, bool $isHtml): bool
{
    event_set_last_error('');
    $host = (string) EVENT_SMTP_HOST;
    $port = (int) EVENT_SMTP_PORT;
    $username = (string) EVENT_SMTP_USERNAME;
    $password = (string) EVENT_SMTP_PASSWORD;
    $fromEmail = (string) EVENT_SMTP_FROM_EMAIL;
    $fromName = (string) EVENT_SMTP_FROM_NAME;

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
        return false;
    }

    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    if ($socket === false) {
        event_set_last_error("SMTP connection failed: {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, 20);
    if (!event_smtp_expect($socket, [220])) {
        event_set_last_error('SMTP greeting failed (expected 220).');
        fclose($socket);
        return false;
    }

    event_smtp_send($socket, 'EHLO davidbellolv.com');
    if (!event_smtp_expect($socket, [250])) {
        event_set_last_error('SMTP EHLO failed before STARTTLS (expected 250).');
        fclose($socket);
        return false;
    }

    if ($port === 587) {
        event_smtp_send($socket, 'STARTTLS');
        if (!event_smtp_expect($socket, [220])) {
            event_set_last_error('SMTP STARTTLS failed (expected 220).');
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            event_set_last_error('SMTP TLS negotiation failed.');
            fclose($socket);
            return false;
        }
        event_smtp_send($socket, 'EHLO davidbellolv.com');
        if (!event_smtp_expect($socket, [250])) {
            event_set_last_error('SMTP EHLO failed after STARTTLS (expected 250).');
            fclose($socket);
            return false;
        }
    }

    event_smtp_send($socket, 'AUTH LOGIN');
    if (!event_smtp_expect($socket, [334])) {
        event_set_last_error('SMTP AUTH step 1 failed (expected 334).');
        fclose($socket);
        return false;
    }
    event_smtp_send($socket, base64_encode($username));
    if (!event_smtp_expect($socket, [334])) {
        event_set_last_error('SMTP AUTH username rejected (expected 334).');
        fclose($socket);
        return false;
    }
    event_smtp_send($socket, base64_encode($password));
    if (!event_smtp_expect($socket, [235])) {
        event_set_last_error('SMTP AUTH password rejected (expected 235). Check App Password / policy.');
        fclose($socket);
        return false;
    }

    event_smtp_send($socket, 'MAIL FROM:<' . $fromEmail . '>');
    if (!event_smtp_expect($socket, [250])) {
        event_set_last_error('SMTP MAIL FROM rejected.');
        fclose($socket);
        return false;
    }
    event_smtp_send($socket, 'RCPT TO:<' . $to . '>');
    if (!event_smtp_expect($socket, [250, 251])) {
        event_set_last_error('SMTP RCPT TO rejected.');
        fclose($socket);
        return false;
    }
    event_smtp_send($socket, 'DATA');
    if (!event_smtp_expect($socket, [354])) {
        event_set_last_error('SMTP DATA rejected.');
        fclose($socket);
        return false;
    }

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . event_encode_header_utf8($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Reply-To: <' . $fromEmail . '>',
        'Subject: ' . event_encode_header_utf8($subject),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@dblvglobal.com>',
        'MIME-Version: 1.0',
        'X-Mailer: DBLV-Mailer/1.0',
    ];

    $safeBody = preg_replace('/(?m)^\./', '..', $body) ?? $body;
    if ($isHtml) {
        $boundary = '=_dblv_' . bin2hex(random_bytes(8));
        $plainText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)), ENT_QUOTES, 'UTF-8'));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $plainText !== '' ? $plainText : 'Mensaje en HTML.';
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $safeBody;
        $parts[] = '--' . $boundary . '--';
        $finalBody = implode("\r\n", $parts);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $finalBody = $safeBody;
    }

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $finalBody . "\r\n.";
    fwrite($socket, $data . "\r\n");

    $ok = event_smtp_expect($socket, [250]);
    if (!$ok) {
        event_set_last_error('SMTP message body rejected on final send.');
    }
    event_smtp_send($socket, 'QUIT');
    event_smtp_expect($socket, [221]);
    fclose($socket);
    return $ok;
}

function event_now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(EVENT_TIMEZONE)))->format(DATE_ATOM);
}

function event_total_confirmed(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM invitations WHERE status='confirmed'");
}

function event_total_pending(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM invitations WHERE status='pending'");
}

function event_total_full(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM invitations WHERE status='full'");
}

function event_total_confirmed_registrations(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM rsvps WHERE status='confirmed'");
}

function event_total_confirmed_seats(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COALESCE(SUM(attendees_count), 0) FROM rsvps WHERE status='confirmed'");
}

function event_total_waitlist_registrations(SQLite3 $db): int
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM rsvps WHERE status='full'");
}

function event_available_seats(SQLite3 $db): int
{
    return max(0, EVENT_CAPACITY - event_total_confirmed_seats($db));
}

function event_require_admin_auth(): void
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user !== EVENT_ADMIN_USER || $pass !== EVENT_ADMIN_PASS) {
        header('WWW-Authenticate: Basic realm="Invitaciones Evento - Admin"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Unauthorized';
        exit;
    }
}

function event_decode_companions(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function event_collect_rsvp_recipients(array $rsvp): array
{
    $recipients = [];
    $ownerEmail = trim((string) ($rsvp['email'] ?? ''));
    $ownerName = trim((string) ($rsvp['first_name'] ?? '') . ' ' . (string) ($rsvp['last_name'] ?? ''));

    if ($ownerEmail !== '' && $ownerName !== '') {
        $recipients[] = [
            'name' => $ownerName,
            'email' => $ownerEmail,
            'role' => 'owner',
        ];
    }

    $companions = event_decode_companions((string) ($rsvp['companions'] ?? ''));
    foreach ($companions as $companion) {
        if (!is_array($companion)) {
            continue;
        }
        $email = trim((string) ($companion['email'] ?? ''));
        $name = trim((string) ($companion['first_name'] ?? '') . ' ' . (string) ($companion['last_name'] ?? ''));
        if ($email === '' || $name === '') {
            continue;
        }
        $recipients[] = [
            'name' => $name,
            'email' => $email,
            'role' => 'companion',
        ];
    }

    return $recipients;
}

function event_build_private_token(array $rsvp, string $email, string $role): string
{
    $base = (string) ($rsvp['public_code'] ?? '') . '|' . strtolower($email) . '|' . $role . '|' . EVENT_TOKEN_SALT;
    return substr(hash('sha256', $base), 0, 32);
}

function event_build_private_url(string $token): string
{
    return EVENT_THANKS_LANDING_URL . '?t=' . rawurlencode($token);
}

function event_track_analytics(SQLite3 $db, array $payload): void
{
    $stmt = $db->prepare(
        'INSERT INTO event_analytics
         (event_type, status, created_at, source, medium, campaign, referrer, landing_path, ip_hash, user_agent, attendees_requested, email_hash)
         VALUES
         (:event_type, :status, :created_at, :source, :medium, :campaign, :referrer, :landing_path, :ip_hash, :user_agent, :attendees_requested, :email_hash)'
    );

    $stmt->bindValue(':event_type', (string) ($payload['event_type'] ?? 'unknown'), SQLITE3_TEXT);
    $stmt->bindValue(':status', (string) ($payload['status'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', (string) ($payload['created_at'] ?? event_now_iso()), SQLITE3_TEXT);
    $stmt->bindValue(':source', (string) ($payload['source'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':medium', (string) ($payload['medium'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':campaign', (string) ($payload['campaign'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':referrer', (string) ($payload['referrer'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':landing_path', (string) ($payload['landing_path'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':ip_hash', (string) ($payload['ip_hash'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':user_agent', (string) ($payload['user_agent'] ?? ''), SQLITE3_TEXT);
    $attendees = isset($payload['attendees_requested']) ? (int) $payload['attendees_requested'] : null;
    $stmt->bindValue(':attendees_requested', $attendees, $attendees === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':email_hash', (string) ($payload['email_hash'] ?? ''), SQLITE3_TEXT);
    $stmt->execute();
}

function event_email_hash(string $email): string
{
    $normalized = strtolower(trim($email));
    if ($normalized === '') {
        return '';
    }
    return hash('sha256', $normalized . '|' . EVENT_TOKEN_SALT);
}

function event_build_gift_email_html(string $name, string $privateUrl, string $status): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars(EVENT_BOOK_TITLE, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($privateUrl, ENT_QUOTES, 'UTF-8');
    $safeDays = (int) EVENT_GIFT_AVAILABILITY_DAYS;
    $heroImage = htmlspecialchars(EVENT_PUBLIC_BASE_URL . '/autor-david.jpeg', ENT_QUOTES, 'UTF-8');
    $statusCopy = $status === 'confirmed'
        ? 'Gracias por haberme acompañado en la presentación. Tu presencia hizo la noche aún más especial.'
        : 'Gracias por tu interés y por querer acompañarme. Aunque no se pudo por aforo, valoro muchísimo tu apoyo.';

    return '<!doctype html><html><body style="margin:0;background:#ffffff;font-family:Avenir Next,Segoe UI,Arial,sans-serif;">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;padding:0;border-collapse:collapse;background:#ffffff;">'
      . '<tr><td style="padding:0;margin:0;">'
      . '<img src="' . $heroImage . '?v=2" alt="David Bello" style="display:block;width:100% !important;max-width:100% !important;height:auto !important;border:0;outline:none;text-decoration:none;" />'
      . '</td></tr>'
      . '<tr><td style="background:#111827;color:#f3d88a;padding:24px 26px 18px;">'
      . '<h1 style="margin:0;font-size:30px;line-height:1.2;">Un regalo para ti</h1>'
      . '<p style="margin:10px 0 0;color:#f8e7bc;font-size:18px;line-height:1.45;">Después de “' . $safeTitle . '”, quiero seguir compartiendo contigo.</p>'
      . '</td></tr>'
      . '<tr><td style="padding:22px 18px;color:#111827;">'
      . '<p style="margin:0 0 14px;font-size:20px;line-height:1.45;">Hola <strong>' . $safeName . '</strong>,</p>'
      . '<p style="margin:0 0 14px;font-size:18px;line-height:1.6;">' . htmlspecialchars($statusCopy, ENT_QUOTES, 'UTF-8') . '</p>'
      . '<p style="margin:0 0 14px;font-size:18px;line-height:1.6;">Como agradecimiento, he preparado un acceso privado para escuchar las canciones inéditas de homenaje a México.</p>'
      . '<p style="margin:0 0 20px;font-size:17px;line-height:1.6;">El acceso estará disponible durante <strong>' . $safeDays . ' días</strong>. Después, una de las canciones se publicará en plataformas.</p>'
      . '<p style="margin:0 0 20px;"><a href="' . $safeUrl . '" style="display:inline-block;padding:14px 18px;background:#1f2937;color:#f3d88a;text-decoration:none;border-radius:10px;font-size:18px;font-weight:700;">Abrir acceso privado</a></p>'
      . '<p style="margin:0 0 8px;color:#4b5563;font-size:14px;line-height:1.5;">Si tu correo bloquea el botón, usa este enlace directo:</p>'
      . '<p style="margin:0 0 14px;word-break:break-all;font-size:14px;line-height:1.5;"><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
      . '<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.6;">Gracias de corazón por estar ahí.</p>'
      . '</td></tr>'
      . '</table></body></html>';
}

function event_dispatch_gift_emails(SQLite3 $db): array
{
    $attempted = 0;
    $sent = 0;
    $noEmail = 0;
    $now = event_now_iso();

    $res = $db->query('SELECT * FROM rsvps ORDER BY id ASC');
    while ($rsvp = $res->fetchArray(SQLITE3_ASSOC)) {
        $status = (string) ($rsvp['status'] ?? 'unknown');
        $recipients = event_collect_rsvp_recipients($rsvp);
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $name = trim((string) ($recipient['name'] ?? ''));
            $role = trim((string) ($recipient['role'] ?? 'recipient'));
            if ($email === '' || $name === '') {
                $noEmail++;
                continue;
            }
            $attempted++;

            $giftRole = 'gift_' . $role;
            $token = event_build_private_token($rsvp, $email, $giftRole);
            $privateUrl = event_build_private_url($token);
            $emailHtml = event_build_gift_email_html($name, $privateUrl, $status);
            $subject = 'Un regalo para ti · ' . EVENT_BOOK_TITLE;
            $ok = event_send_html_email($email, $subject, $emailHtml);

            $check = $db->prepare('SELECT id FROM post_event_links WHERE token = :token LIMIT 1');
            $check->bindValue(':token', $token, SQLITE3_TEXT);
            $exists = $check->execute()->fetchArray(SQLITE3_ASSOC);

            if ($exists) {
                $upd = $db->prepare('UPDATE post_event_links SET sent_at = :sent_at, role = :role WHERE id = :id');
                $upd->bindValue(':sent_at', $ok ? $now : null, $ok ? SQLITE3_TEXT : SQLITE3_NULL);
                $upd->bindValue(':role', $giftRole, SQLITE3_TEXT);
                $upd->bindValue(':id', (int) $exists['id'], SQLITE3_INTEGER);
                $upd->execute();
            } else {
                $ins = $db->prepare(
                    'INSERT INTO post_event_links
                     (rsvp_id, recipient_name, recipient_email, role, token, sent_at, created_at)
                     VALUES
                     (:rsvp_id, :recipient_name, :recipient_email, :role, :token, :sent_at, :created_at)'
                );
                $ins->bindValue(':rsvp_id', (int) ($rsvp['id'] ?? 0), SQLITE3_INTEGER);
                $ins->bindValue(':recipient_name', $name, SQLITE3_TEXT);
                $ins->bindValue(':recipient_email', $email, SQLITE3_TEXT);
                $ins->bindValue(':role', $giftRole, SQLITE3_TEXT);
                $ins->bindValue(':token', $token, SQLITE3_TEXT);
                $ins->bindValue(':sent_at', $ok ? $now : null, $ok ? SQLITE3_TEXT : SQLITE3_NULL);
                $ins->bindValue(':created_at', $now, SQLITE3_TEXT);
                $ins->execute();
            }

            if ($ok) {
                $sent++;
            }
        }
    }

    return [
        'attempted' => $attempted,
        'sent' => $sent,
        'no_email' => $noEmail,
        'last_error' => event_get_last_error(),
    ];
}
