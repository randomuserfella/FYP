<?php
/**
 * ngrok_setup.php — ProcraTrack auto-setup helper
 * Works both from browser AND from start_procratrack.bat
 */

require_once __DIR__ . '/config/secrets_loader.php';
define('TELEGRAM_BOT_TOKEN', pt_secret('TELEGRAM_BOT_TOKEN'));
define('PROCRATRACK_PATH',   'C:\xampp\htdocs\procratrack');

// ── Get ngrok URL ─────────────────────────────────────────────────────────────
// CLI mode: read from JSON file passed as argument
// Browser mode: fetch directly from ngrok local API
$public_url = null;

if (php_sapi_name() === 'cli' && !empty($argv[1]) && file_exists($argv[1])) {
    $raw  = file_get_contents($argv[1]);
    $data = json_decode($raw, true);
} else {
    // Browser fallback — hit ngrok's local API directly
    $raw  = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
    $data = $raw ? json_decode($raw, true) : null;
}

if (!$data || empty($data['tunnels'])) {
    $msg = "Could not read ngrok tunnel list. Make sure ngrok is running.";
    die(php_sapi_name() === 'cli' ? "\n  [ERROR] $msg\n\n" : "<b style='color:red'>[ERROR]</b> $msg");
}

foreach ($data['tunnels'] as $tunnel) {
    if (isset($tunnel['proto']) && $tunnel['proto'] === 'https') {
        $public_url = rtrim($tunnel['public_url'], '/');
        break;
    }
}

if (!$public_url && !empty($data['tunnels'][0]['public_url'])) {
    $public_url = rtrim($data['tunnels'][0]['public_url'], '/');
    $public_url = preg_replace('/^http:/', 'https:', $public_url);
}

if (!$public_url) {
    die("[ERROR] Could not extract a public URL from ngrok response.");
}

$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) echo "<pre>";

echo "\n  Tunnel URL: $public_url\n";

// ── Step 1: Register Telegram webhook ────────────────────────────────────────
$webhook_url = $public_url . '/procratrack/telegram_webhook.php';
$tg_api      = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/setWebhook';
$post_data   = http_build_query(['url' => $webhook_url]);

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content'       => $post_data,
    'timeout'       => 10,
    'ignore_errors' => true,
]]);

$response = @file_get_contents($tg_api, false, $ctx);
$result   = $response ? json_decode($response, true) : null;

if (!empty($result['ok'])) {
    echo "  [OK] Telegram webhook registered.\n";
    echo "       -> $webhook_url\n";
} else {
    $desc = $result['description'] ?? 'unknown error';
    echo "  [WARN] Telegram webhook failed: $desc\n";
    echo "         Try manually: https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/setWebhook?url=$webhook_url\n";
}

// ── Step 2: Update config/push_config.php ────────────────────────────────────
$config_file = PROCRATRACK_PATH . '/config/push_config.php';

if (!file_exists($config_file)) {
    echo "  [WARN] push_config.php not found at: $config_file\n";
} else {
    $content     = file_get_contents($config_file);
    $new_content = preg_replace(
        "/define\('APP_URL',\s*'[^']*'\);/",
        "define('APP_URL', '$public_url'); // auto-updated",
        $content
    );

    if ($new_content === $content) {
        echo "  [WARN] Could not auto-update push_config.php (APP_URL line not found).\n";
    } else {
        file_put_contents($config_file, $new_content);
        echo "  [OK] push_config.php updated with new APP_URL.\n";
    }
}

echo "\n  Setup complete! ProcraTrack is live at:\n";
echo "  $public_url/procratrack\n\n";

if (!$is_cli) echo "</pre>";
