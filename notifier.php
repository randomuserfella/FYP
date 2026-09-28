<?php
/**
 * includes/notifier.php — ProcraTrack notification sender
 *
 * Telegram : Bot API via cURL
 * Email    : Gmail SMTP via PHP's built-in mail() with SMTP settings,
 *            OR direct socket SMTP (no library needed)
 *
 * ── SETUP ────────────────────────────────────────────────────────────────────
 * Gmail:
 *   1. Go to myaccount.google.com → Security → 2-Step Verification → ON
 *   2. Then: myaccount.google.com → Security → App passwords
 *   3. Create app password → name it "ProcraTrack" → copy the 16-char password
 *   4. Paste into GMAIL_APP_PASSWORD below (remove spaces)
 *
 * Telegram:
 *   Already set up — token below is correct.
 */

// ── Telegram ──────────────────────────────────────────────────────────────────
if (!defined('BOT_TOKEN')) require_once __DIR__ . '/config/secrets_loader.php'; define('BOT_TOKEN', pt_secret('TELEGRAM_BOT_TOKEN'));

// ── Gmail SMTP settings ───────────────────────────────────────────────────────
if (!defined('GMAIL_USER'))         define('GMAIL_USER', pt_secret('MAIL_USERNAME'));   // ← your Gmail address
if (!defined('GMAIL_APP_PASSWORD')) define('GMAIL_APP_PASSWORD', pt_secret('MAIL_PASSWORD'));    // ← 16-char App Password
if (!defined('MAIL_FROM_NAME'))     define('MAIL_FROM_NAME',     'ProcraTrack');

// ─── cURL helper (works from Apache on Windows) ───────────────────────────────
function _curl_post(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        file_put_contents(__DIR__ . '/../bot.log',
            date('H:i:s') . " cURL ERR: $err\n", FILE_APPEND);
    }
    return $res ?: '';
}

// ── Send Telegram message ─────────────────────────────────────────────────────
function sendTelegram(PDO $pdo, int $user_id, string $message, string $type): bool
{
    $stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $row     = $stmt->fetch();
    $chat_id = $row['telegram_chat_id'] ?? '';

    if (!$chat_id) {
        logNotification($pdo, $user_id, 'telegram', $type, $message, 'failed');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $res = _curl_post(
        $url,
        http_build_query(['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML']),
        ['Content-Type: application/x-www-form-urlencoded']
    );

    $ok = !empty(json_decode($res, true)['ok']);
    if (!$ok) {
        file_put_contents(__DIR__ . '/../bot.log',
            date('H:i:s') . " TG_FAIL user=$user_id: $res\n", FILE_APPEND);
    }
    logNotification($pdo, $user_id, 'telegram', $type, $message, $ok ? 'sent' : 'failed');
    return $ok;
}

// ── Send email via Gmail SMTP (socket, no library needed) ─────────────────────
function sendEmail(PDO $pdo, int $user_id, string $to_email, string $subject, string $body_html, string $type): bool
{
    $user = GMAIL_USER;
    $pass = GMAIL_APP_PASSWORD;
    $from = MAIL_FROM_NAME . ' <' . $user . '>';

    // Build raw email
    $boundary = md5(uniqid());
    $headers  = implode("\r\n", [
        "From: $from",
        "To: $to_email",
        "Subject: $subject",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ]);

    $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />','<p>','</p>'], "\n", $body_html));
    $html  = emailTemplate($subject, $body_html);

    $body = "--$boundary\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n\r\n$plain\r\n\r\n"
          . "--$boundary\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n"
          . "--$boundary--";

    // SMTP over SSL to Gmail (port 465)
    $errno = 0; $errstr = '';
    $sock = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 10);

    if (!$sock) {
        file_put_contents(__DIR__ . '/../bot.log',
            date('H:i:s') . " SMTP_CONNECT_FAIL: $errstr\n", FILE_APPEND);
        logNotification($pdo, $user_id, 'email', $type, $subject, 'failed');
        return false;
    }

    $ok = false;
    try {
        $read = fn() => fgets($sock, 512);
        $send = function(string $cmd) use ($sock, &$read): string {
            fwrite($sock, $cmd . "\r\n");
            return $read();
        };

        $read(); // 220 greeting
        $send("EHLO localhost");
        $read(); $read(); $read(); $read(); $read(); // multi-line EHLO response

        $send("AUTH LOGIN");
        $read(); // 334 username
        $send(base64_encode($user));
        $read(); // 334 password
        $send(base64_encode($pass));
        $resp = $read(); // 235 ok or 535 fail

        if (strpos($resp, '235') === false) {
            file_put_contents(__DIR__ . '/../bot.log',
                date('H:i:s') . " SMTP_AUTH_FAIL: $resp\n", FILE_APPEND);
            fclose($sock);
            logNotification($pdo, $user_id, 'email', $type, $subject, 'failed');
            return false;
        }

        $send("MAIL FROM:<$user>");      $read();
        $send("RCPT TO:<$to_email>");   $read();
        $send("DATA");                  $read();
        fwrite($sock, "$headers\r\n\r\n$body\r\n.\r\n");
        $resp2 = $read(); // 250 ok
        $send("QUIT");

        $ok = strpos($resp2, '250') !== false;
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../bot.log',
            date('H:i:s') . " SMTP_ERR: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    fclose($sock);
    logNotification($pdo, $user_id, 'email', $type, $subject, $ok ? 'sent' : 'failed');
    return $ok;
}

// ── Notify via all enabled channels ──────────────────────────────────────────
function notifyUser(PDO $pdo, int $user_id, string $type, string $tg_msg, string $email_subject, string $email_body): void
{
    $stmt = $pdo->prepare("SELECT email, notification_email, telegram_chat_id, notify_telegram, notify_email FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) return;

    $send_to = !empty($u['notification_email']) ? $u['notification_email'] : $u['email'];

    $tg_sent = false;
    if (!empty($u['notify_telegram']) && !empty($u['telegram_chat_id'])) {
        $tg_sent = sendTelegram($pdo, $user_id, $tg_msg, $type);
    }

    if (!empty($u['notify_email']) && !empty($send_to)) {
        sendEmail($pdo, $user_id, $send_to, $email_subject, $email_body, $type);
    } elseif (!$tg_sent && !empty($send_to)) {
        // Fallback: send email even if notify_email is off, when Telegram failed
        sendEmail($pdo, $user_id, $send_to, $email_subject, $email_body, $type);
    }
}

// ── Email HTML wrapper ────────────────────────────────────────────────────────
function emailTemplate(string $title, string $body): string
{
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f8;margin:0;padding:20px}
.wrap{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e0e0e8}
.head{background:#7c6af7;padding:24px;text-align:center}.head h1{color:#fff;margin:0;font-size:20px}
.body{padding:24px;color:#333;line-height:1.6;font-size:14px}
.footer{padding:16px 24px;background:#f9f9fb;text-align:center;font-size:11px;color:#999}
.btn{display:inline-block;background:#7c6af7;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:12px}
</style></head><body>
<div class="wrap">
  <div class="head"><h1>🎯 ProcraTrack</h1></div>
  <div class="body"><h2 style="margin-top:0;font-size:16px">$title</h2>$body
    <p style="margin-top:20px"><a class="btn" href="#">Open ProcraTrack</a></p>
  </div>
  <div class="footer">You received this because notifications are enabled in ProcraTrack.<br>Manage settings on your Notifications page.</div>
</div></body></html>
HTML;
}

// ── Log to notification_log ───────────────────────────────────────────────────
function logNotification(PDO $pdo, int $user_id, string $channel, string $type, string $message, string $status): void
{
    try {
        $pdo->prepare("INSERT INTO notification_log (user_id, channel, type, message, status) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $channel, $type, substr($message, 0, 500), $status]);
    } catch (Exception $e) {}
}
