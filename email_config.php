<?php
// email_config.php -- Gmail SMTP configuration.
// Real values live in config/secrets.php (git-ignored). See config/secrets.example.php.
require_once __DIR__ . '/config/secrets_loader.php';

define('MAIL_HOST',     'ssl://smtp.gmail.com');
define('MAIL_PORT',     465);
define('MAIL_USERNAME', pt_secret('MAIL_USERNAME'));
define('MAIL_PASSWORD', pt_secret('MAIL_PASSWORD'));
define('MAIL_FROM',     pt_secret('MAIL_FROM', 'ProcraTrack <no-reply@example.com>'));
