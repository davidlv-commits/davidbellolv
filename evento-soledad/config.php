<?php
declare(strict_types=1);

// Optional local overrides (do not commit secrets).
$eventLocalConfig = __DIR__ . '/config.local.php';
if (is_file($eventLocalConfig)) {
    require $eventLocalConfig;
}

// Event configuration
const EVENT_NAME = 'Presentacion del libro';
const EVENT_BOOK_TITLE = 'Tú de qué vas';
const EVENT_LOCATION = 'Hotel de la Soledad, Morelia';
const EVENT_TIME_TEXT = 'Hoy, 7:00 PM (hora de Morelia)';
const EVENT_TIMEZONE = 'America/Mexico_City';
const EVENT_CAPACITY = 35;

// Public base URL for generated invitation links
const EVENT_PUBLIC_BASE_URL = 'https://davidbellolv.com/evento-soledad';
const EVENT_MAIN_WEBSITE_URL = 'https://davidbellolv.com/';
const EVENT_SPOTIFY_URL = 'https://open.spotify.com/album/2XVm8EkY7tadPJmRhMOX0f?si=fr6c1PNrS_Kpb-eO2f9QDQ';
const EVENT_YOUTUBE_URL = 'https://youtube.com/@davidbellolopez-valeiras24?si=11OPPW9efofUbCS4';
const EVENT_BOOK_AMAZON_URL = 'https://amzn.eu/d/hEMYm7o';
const EVENT_BOOK_COVER_URL = '../img/portada-novela.jpg';
const EVENT_THANKS_LANDING_URL = EVENT_PUBLIC_BASE_URL . '/privado.php';
const EVENT_GIFT_PUBLIC_URL = EVENT_PUBLIC_BASE_URL . '/privado.php';
const EVENT_GIFT_AVAILABILITY_DAYS = 15;
const EVENT_TOKEN_SALT = 'dblv-evento-privado-2026';
const EVENT_GIFT_STREAM_SECRET = 'dblv-gift-stream-2026';
const EVENT_SHARE_ABUSE_MAX_FAILS = 10;
const EVENT_SHARE_ABUSE_WINDOW_SECONDS = 900;
const EVENT_SHARE_ABUSE_LOCK_SECONDS = 1800;

// SQLite DB is stored outside public_html.
const EVENT_DB_PATH = __DIR__ . '/../../private_events/invitaciones_libro.sqlite';

// Admin credentials for invitation generation panel.
const EVENT_ADMIN_USER = 'eventadmin';
const EVENT_ADMIN_PASS = 'Presentacion40';

// SMTP configuration (Google Workspace recommended).
if (!defined('EVENT_SMTP_ENABLED')) {
    define('EVENT_SMTP_ENABLED', false);
}
if (!defined('EVENT_SMTP_HOST')) {
    define('EVENT_SMTP_HOST', 'smtp.gmail.com');
}
if (!defined('EVENT_SMTP_PORT')) {
    define('EVENT_SMTP_PORT', 587);
}
if (!defined('EVENT_SMTP_USERNAME')) {
    define('EVENT_SMTP_USERNAME', 'davidbello@dblvglobal.com');
}
if (!defined('EVENT_SMTP_PASSWORD')) {
    define('EVENT_SMTP_PASSWORD', '');
}
if (!defined('EVENT_SMTP_FROM_EMAIL')) {
    define('EVENT_SMTP_FROM_EMAIL', 'davidbello@dblvglobal.com');
}
if (!defined('EVENT_SMTP_FROM_NAME')) {
    define('EVENT_SMTP_FROM_NAME', 'David Bello');
}
if (!defined('EVENT_CRON_TOKEN')) {
    define('EVENT_CRON_TOKEN', '');
}
