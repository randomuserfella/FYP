<?php
/**
 * config/push_config.php
 * Web Push settings. Keys come from config/secrets.php (git-ignored).
 * APP_URL is rewritten automatically by ngrok_setup.php when you use a tunnel.
 */
require_once __DIR__ . '/secrets_loader.php';

// -- Your live URL (auto-updated by ngrok_setup.php) --------------------------
define('APP_URL', 'http://localhost');

// -- VAPID keys ---------------------------------------------------------------
define('VAPID_PUBLIC_KEY',  pt_secret('VAPID_PUBLIC_KEY'));
define('VAPID_PRIVATE_KEY', pt_secret('VAPID_PRIVATE_KEY'));

// -- Auto-derived from APP_URL ------------------------------------------------
define('VAPID_SUBJECT', APP_URL);
define('PUSH_ICON',     APP_URL . '/icon-192.png');
define('PUSH_BADGE',    APP_URL . '/icon-72.png');

// -- Reminder timing ----------------------------------------------------------
define('REMIND_DAYS_BEFORE', 1);
