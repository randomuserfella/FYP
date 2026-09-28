<?php
/**
 * Copy this file to config/secrets.php and fill in your own values.
 * config/secrets.php is git-ignored -- never commit real credentials.
 * Every value is optional: leave blank to disable that integration.
 */
return [
    // Telegram bot token from @BotFather
    'TELEGRAM_BOT_TOKEN' => '',

    // Gmail SMTP (use a Google "App Password", not your real password)
    'MAIL_USERNAME'      => '',
    'MAIL_PASSWORD'      => '',
    'MAIL_FROM'          => 'ProcraTrack <your-address@gmail.com>',

    // Web Push (generate a pair by opening generate_vapid_keys.php once)
    'VAPID_PUBLIC_KEY'   => '',
    'VAPID_PRIVATE_KEY'  => '',
];
