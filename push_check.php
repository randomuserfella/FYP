<?php
putenv('OPENSSL_CONF=C:/xampp/apache/bin/openssl.cnf');
/**
 * push_check.php
 * Open this in your browser to verify your XAMPP setup supports push notifications.
 * DELETE after confirming everything is green.
 */

$all_ok = true;

function check(string $label, bool $pass, string $detail = '', string $fix = ''): void {
    global $all_ok;
    if (!$pass) $all_ok = false;
    $status = $pass ? '<span class="ok">&#10003; OK</span>' : '<span class="fail">&#10007; FAIL</span>';
    echo "<div class='row'><span><strong>" . htmlspecialchars($label) . "</strong>" . ($detail ? " &mdash; <small>" . htmlspecialchars($detail) . "</small>" : '') . "</span>$status</div>";
    if (!$pass && $fix) echo "<div class='fix'>&#128161; " . htmlspecialchars($fix) . "</div>";
}

function checkWarn(string $label, bool $pass, string $detail = '', string $fix = ''): void {
    $status = $pass ? '<span class="ok">&#10003; OK</span>' : '<span class="warn">&#9888; WARNING</span>';
    echo "<div class='row'><span><strong>" . htmlspecialchars($label) . "</strong>" . ($detail ? " &mdash; <small>" . htmlspecialchars($detail) . "</small>" : '') . "</span>$status</div>";
    if (!$pass && $fix) echo "<div class='fix'>&#128161; " . htmlspecialchars($fix) . "</div>";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>ProcraTrack &ndash; Push Notification Checker</title>
<style>
body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f5f5f5}
.card{background:#fff;border-radius:10px;padding:20px;margin-bottom:16px;border:1px solid #ddd}
.ok{color:#2e7d32;font-weight:bold}
.fail{color:#c62828;font-weight:bold}
.warn{color:#e65100;font-weight:bold}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0}
.row:last-child{border:none}
.fix{background:#fff3e0;padding:8px 12px;border-radius:6px;font-size:13px;margin:4px 0 8px}
pre{background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;font-size:12px;overflow-x:auto}
</style>
</head>
<body>
<h2>&#128276; ProcraTrack Push Notification Checker</h2>

<div class="card">
<h3 style="margin-top:0">PHP Extensions</h3>
<?php
check('OpenSSL extension', extension_loaded('openssl'), phpversion('openssl'), 'Enable extension=openssl in php.ini');
check('cURL extension',    extension_loaded('curl'),    phpversion('curl'),    'Enable extension=curl in php.ini');
check('JSON extension',    extension_loaded('json'),    phpversion('json'),    'Should be built-in');

// OPENSSL_CONF check — putenv already set at top, just verify it
$cnfPath   = getenv('OPENSSL_CONF') ?: 'not set';
$cnfExists = ($cnfPath !== 'not set') && file_exists($cnfPath);
checkWarn('openssl.cnf found', $cnfExists, $cnfPath,
    'Add putenv(\'OPENSSL_CONF=C:/xampp/apache/bin/openssl.cnf\') as the very first line after <?php in this file and in includes/WebPush.php');

// Test EC private key loading using SEC1 format (BEGIN EC PRIVATE KEY)
// This is the correct format for P-256 keys on XAMPP OpenSSL
$testPem =
    "-----BEGIN EC PRIVATE KEY-----\r\n" .
    "MHcCAQEEIFCCmK4SOI5T911/xroy7TJ2T4wVe3uV0EzSbXzBqWMkoAoGCCqGSM49\r\n" .
    "AwEHoUQDQgAECDPrPTmGKRvTgnXk0jtQx3Udp/jA7bBBxdujw4l+rIk9Zpq5pL60\r\n" .
    "DwnnoooZd77dfx9uXWp+E1VKflaFPmp7pg==\r\n" .
    "-----END EC PRIVATE KEY-----\r\n";


$key = openssl_pkey_get_private($testPem);
$ecError = openssl_error_string();
check(
    'Load EC private key (SEC1 format)',
    (bool)$key,
    $key ? 'EC PEM loading works' : ($ecError ?: 'unknown error'),
    'Your OpenSSL may not support EC keys. Try updating XAMPP or PHP.'
);

// Test AES-128-GCM
$gcmSupported = in_array('aes-128-gcm', openssl_get_cipher_methods());
check('AES-128-GCM cipher', $gcmSupported, '', 'Update OpenSSL — XAMPP 8.x should have this');

// Test openssl_pkey_derive (PHP 8+)
$canDerive = function_exists('openssl_pkey_derive');
check('openssl_pkey_derive (PHP 8+)', $canDerive, 'PHP ' . PHP_VERSION,
    'Upgrade to PHP 8.0+ in XAMPP.');
?>
</div>

<div class="card">
<h3 style="margin-top:0">Configuration</h3>
<?php
require_once __DIR__ . '/config/push_config.php';
$pubKeyOk  = defined('VAPID_PUBLIC_KEY')  && strlen(VAPID_PUBLIC_KEY)  > 50;
$privKeyOk = defined('VAPID_PRIVATE_KEY') && strlen(VAPID_PRIVATE_KEY) > 30;
check('VAPID public key set',  $pubKeyOk,
    $pubKeyOk  ? substr(VAPID_PUBLIC_KEY, 0, 20)  . '&hellip;' : 'Not set',
    'Copy keys from generate_vapid_keys.php into config/push_config.php');
check('VAPID private key set', $privKeyOk,
    $privKeyOk ? substr(VAPID_PRIVATE_KEY, 0, 10) . '&hellip;' : 'Not set',
    'Same as above');
?>
</div>

<div class="card">
<h3 style="margin-top:0">Database</h3>
<?php
try {
    require_once __DIR__ . '/config/db.php';
    $t1 = $pdo->query("SHOW TABLES LIKE 'push_subscriptions'")->rowCount();
    $t2 = $pdo->query("SHOW TABLES LIKE 'push_log'")->rowCount();
    check('push_subscriptions table', $t1 > 0, '', 'Run config/push_schema.sql in phpMyAdmin');
    check('push_log table',           $t2 > 0, '', 'Run config/push_schema.sql in phpMyAdmin');
    if ($t1) {
        $count = $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
        echo "<div class='row'><span>Active subscriptions</span><span>$count device(s)</span></div>";
    }
} catch (Exception $e) {
    echo "<div class='row'><span>DB connection</span><span class='fail'>&#10007; " . htmlspecialchars($e->getMessage()) . "</span></div>";
}
?>
</div>

<?php if ($all_ok): ?>
<div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:10px;padding:20px;text-align:center;">
    <h3 style="color:#2e7d32;margin:0 0 8px">&#127881; All checks passed!</h3>
    <p style="margin:0;color:#388e3c">Your XAMPP setup supports push notifications.<br>Go to <a href="notifications.php">notifications.php</a> to test.</p>
</div>
<?php else: ?>
<div style="background:#ffebee;border:1px solid #ef9a9a;border-radius:10px;padding:20px;">
    <h3 style="color:#c62828;margin:0 0 8px">&#9888; Some checks failed</h3>
    <p style="margin:0;color:#b71c1c">Fix the items marked &#10007; above, then refresh this page.</p>
</div>
<?php endif; ?>

<p style="font-size:12px;color:#999;margin-top:20px">Delete this file before deploying. It reveals configuration info.</p>
</body>
</html>
