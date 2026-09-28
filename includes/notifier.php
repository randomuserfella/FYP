<?php
// ============================================================
// includes/notifier.php — ProcraTrack Notification System
// ============================================================
// ONLY defines sendNotification(), sendTelegram(), sendEmail()
// Does NOT touch telegram_webhook.php constants
// ============================================================

if (!defined('TELEGRAM_BOT_TOKEN')) {
    require_once __DIR__ . '/../config/secrets_loader.php'; define('TELEGRAM_BOT_TOKEN', pt_secret('TELEGRAM_BOT_TOKEN'));
}
if (!defined('TELEGRAM_BOT_NAME')) {
    define('TELEGRAM_BOT_NAME', 'ProcraTrackBot'); // ← your bot @username without @
}

// ── sendNotification() ───────────────────────────────────────
// Main entry: tries Telegram first, falls back to email
function sendNotification($pdo, $user_id, $message, $subject = "ProcraTrack Alert") {
    $stmt = $pdo->prepare("SELECT email, telegram_chat_id, notify_telegram, notify_email FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;

    $sent = false;
    if (!empty($user['notify_telegram']) && !empty($user['telegram_chat_id'])) {
        $sent = sendTelegram($pdo, $user_id, $message, 'general');
    }
    if (!$sent && !empty($user['notify_email']) && !empty($user['email'])) {
        $sent = sendEmail($pdo, $user_id, $user['email'], $subject, $message, 'general');
    }
    return $sent;
}

// ── sendTelegram() ───────────────────────────────────────────
// sendTelegram($pdo, $user_id, $message, $type)
function sendTelegram($pdo, $user_id, $message, $type = 'general') {
    $stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $user    = $stmt->fetch(PDO::FETCH_ASSOC);
    $chat_id = $user['telegram_chat_id'] ?? null;
    if (empty($chat_id)) {
        file_put_contents(__DIR__ . '/../logs/notify_debug.log',
            date('Y-m-d H:i:s') . " [TELEGRAM SKIP] user_id={$user_id} type={$type} — telegram_chat_id is empty in DB\n",
            FILE_APPEND);
        return false;
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $chat_id,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ]),
    ]);
    $rawResponse = curl_exec($ch);
    $curlErr     = curl_error($ch);
    $response    = json_decode($rawResponse, true);
    curl_close($ch);

    $ok = !empty($response['ok']);

    // Diagnostic log: exact chat_id used + Telegram's raw response, so a
    // "sent but never received" case can be told apart from "not sent" —
    // e.g. Telegram returning ok:true with a chat_id you don't recognise
    // means the DB has a stale/wrong chat_id, not a code bug.
    if (!is_dir(__DIR__ . '/../logs')) mkdir(__DIR__ . '/../logs', 0755, true);
    file_put_contents(__DIR__ . '/../logs/notify_debug.log',
        date('Y-m-d H:i:s') . " [TELEGRAM] user_id={$user_id} type={$type} chat_id_used={$chat_id} "
        . "curl_error=" . ($curlErr ?: 'none') . " raw_response=" . ($rawResponse ?: 'empty') . "\n",
        FILE_APPEND);

    try {
        $pdo->prepare("INSERT INTO notification_log (user_id,channel,type,message,status) VALUES (?,'telegram',?,?,?)")
            ->execute([$user_id, $type, $message, $ok ? 'sent' : 'failed']);
    } catch (Exception $e) {}
    return $ok;
}

// ── sendEmail() ──────────────────────────────────────────────
// sendEmail($pdo, $user_id, $to, $subject, $body, $type)
function sendEmail($pdo, $user_id, $to, $subject, $body, $type = 'general') {
    $plain = strip_tags($body);
    $ok    = _sendRawEmail($to, $subject, $body);

    if (!is_dir(__DIR__ . '/../logs')) mkdir(__DIR__ . '/../logs', 0755, true);
    file_put_contents(__DIR__ . '/../logs/notify_debug.log',
        date('Y-m-d H:i:s') . " [EMAIL] user_id={$user_id} type={$type} to_address_used={$to} result=" . ($ok ? 'accepted by SMTP server' : 'FAILED — check email_config.php / server error_log') . "\n",
        FILE_APPEND);

    try {
        $pdo->prepare("INSERT INTO notification_log (user_id,channel,type,message,status) VALUES (?,'email',?,?,?)")
            ->execute([$user_id, $type, $plain, $ok ? 'sent' : 'failed']);
    } catch (Exception $e) {}
    return $ok;
}

// ── _sendRawEmail() ──────────────────────────────────────────
// Internal SMTP sender — Gmail SSL port 465, no Composer needed
function _sendRawEmail($to, $subject, $body) {
    $host     = defined('MAIL_HOST')     ? MAIL_HOST     : 'ssl://smtp.gmail.com';
    $port     = defined('MAIL_PORT')     ? MAIL_PORT     : 465;
    $username = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';
    $password = defined('MAIL_PASSWORD') ? str_replace(' ', '', MAIL_PASSWORD) : '';
    $from     = defined('MAIL_FROM')     ? MAIL_FROM     : "ProcraTrack <{$username}>";

    if (empty($username) || empty($password)) {
        error_log("ProcraTrack mailer: MAIL_USERNAME or MAIL_PASSWORD not set in email_config.php");
        return false;
    }

    // ── Build HTML email ─────────────────────────────────────
    $year = date('Y');
    // If $body already contains HTML tags, render it directly.
    // Otherwise treat it as plain text and convert newlines to <br>.
    if (preg_match('/<[a-z][\s\S]*>/i', $body)) {
        $safeBody = $body; // already HTML — use as-is
    } else {
        $safeBody = nl2br(htmlspecialchars($body));
    }
    // NOTE: styling is inline on every element (not just in the <style> block).
    // Many email clients (Gmail app, Gmail "light mode" in particular) strip
    // <style> blocks and CSS gradients, which previously left the white
    // header text with no background — invisible. Inline styles + a solid
    // bgcolor fallback keep it readable everywhere.
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>body{margin:0;padding:0}</style></head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:40px 16px;">
<tr><td align="center">
<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background-color:#ffffff;border-radius:10px;overflow:hidden;">
<tr>
  <td align="center" bgcolor="#6C63FF" style="background-color:#6C63FF;padding:28px 24px;">
    <h1 style="color:#ffffff;margin:0;font-size:22px;font-family:Arial,Helvetica,sans-serif;">&#128218; ProcraTrack</h1>
    <p style="color:#ffffff;margin:6px 0 0;font-size:13px;font-family:Arial,Helvetica,sans-serif;">Student Productivity Tracker</p>
  </td>
</tr>
<tr>
  <td style="padding:28px 24px;color:#333333;font-size:15px;line-height:1.6;font-family:Arial,Helvetica,sans-serif;">
    <div>' . $safeBody . '</div>
    <p style="color:#999999;font-size:12px;margin-top:20px;padding-top:14px;border-top:1px solid #eeeeee;font-family:Arial,Helvetica,sans-serif;">This is an automated message. Please do not reply.</p>
  </td>
</tr>
<tr>
  <td align="center" bgcolor="#f9f9f9" style="background-color:#f9f9f9;border-top:1px solid #eeeeee;padding:14px 24px;font-size:12px;color:#999999;font-family:Arial,Helvetica,sans-serif;">
    &copy; ' . $year . ' ProcraTrack &nbsp;|&nbsp;<a href="mailto:' . $username . '" style="color:#6C63FF;text-decoration:none;">' . $username . '</a>
  </td>
</tr>
</table>
</td></tr>
</table>
</body></html>';

    // Convert block-level HTML tags to newlines BEFORE stripping,
    // so words don't run together in the plain-text fallback part.
    $bodyForPlain = preg_replace('/<\/?(p|br|div|li|h[1-6])[^>]*>/i', "\n", $body);
    $plainFull = trim(strip_tags($bodyForPlain)) . "\r\n\r\n---\r\nAutomated message. Do not reply.";
    $boundary  = 'PT_' . md5(uniqid(time(), true));

    $msgHeaders  = "MIME-Version: 1.0\r\n";
    $msgHeaders .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $msgHeaders .= "From: {$from}\r\n";
    $msgHeaders .= "To: {$to}\r\n";
    $msgHeaders .= "Subject: {$subject}\r\n";
    $msgHeaders .= "X-Mailer: ProcraTrack/1.0\r\n";

    $msgBody  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$plainFull}\r\n\r\n";
    $msgBody .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n";
    $msgBody .= "--{$boundary}--";

    // ── Open SSL socket ───────────────────────────────────────
    $socket = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) {
        error_log("ProcraTrack mailer: fsockopen failed — {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($socket, 15);

    // Helper: send a command and read response code
    $send = function(string $cmd) use ($socket): string {
        fwrite($socket, $cmd . "\r\n");
        $resp = '';
        while ($line = fgets($socket, 512)) {
            $resp .= $line;
            // Multi-line responses: "250-..." continues, "250 ..." ends
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $resp;
    };

    // Read server greeting
    $greeting = '';
    while ($line = fgets($socket, 512)) {
        $greeting .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket); error_log("ProcraTrack mailer: bad greeting — {$greeting}"); return false;
    }

    // EHLO
    $r = $send("EHLO localhost");
    if (substr($r, 0, 3) !== '250') {
        fclose($socket); error_log("ProcraTrack mailer: EHLO failed — {$r}"); return false;
    }

    // AUTH LOGIN
    $r = $send("AUTH LOGIN");
    if (substr($r, 0, 3) !== '334') {
        fclose($socket); error_log("ProcraTrack mailer: AUTH LOGIN failed — {$r}"); return false;
    }

    $r = $send(base64_encode($username));
    if (substr($r, 0, 3) !== '334') {
        fclose($socket); error_log("ProcraTrack mailer: username rejected — {$r}"); return false;
    }

    $r = $send(base64_encode($password));
    if (substr($r, 0, 3) !== '235') {
        fclose($socket); error_log("ProcraTrack mailer: password rejected — {$r}"); return false;
    }

    // MAIL FROM
    $r = $send("MAIL FROM:<{$username}>");
    if (substr($r, 0, 3) !== '250') {
        fclose($socket); error_log("ProcraTrack mailer: MAIL FROM failed — {$r}"); return false;
    }

    // RCPT TO
    $r = $send("RCPT TO:<{$to}>");
    if (substr($r, 0, 3) !== '250') {
        fclose($socket); error_log("ProcraTrack mailer: RCPT TO failed — {$r}"); return false;
    }

    // DATA
    $r = $send("DATA");
    if (substr($r, 0, 3) !== '354') {
        fclose($socket); error_log("ProcraTrack mailer: DATA failed — {$r}"); return false;
    }

    // Send headers + body + end-of-data marker
    fwrite($socket, $msgHeaders . "\r\n" . $msgBody . "\r\n.\r\n");
    $r = '';
    while ($line = fgets($socket, 512)) {
        $r .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    if (substr($r, 0, 3) !== '250') {
        fclose($socket); error_log("ProcraTrack mailer: message rejected — {$r}"); return false;
    }

    $send("QUIT");
    fclose($socket);
    return true;
}

// ── notifyUser() ─────────────────────────────────────────────
// Used by send_reminders.php — logs dedup tag, then routes to
// Telegram first, email as fallback.
function notifyUser(PDO $pdo, int $userId, string $tag, string $tgMsg, string $emailSubject, string $emailHtml): bool
{
    // Look up user preferences
    $stmt = $pdo->prepare("SELECT telegram_chat_id, notify_telegram, notify_email, email, notification_email FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;

    $telegramSent = false;
    $emailSent    = false;

    // Send Telegram if enabled
    if (!empty($user['notify_telegram']) && !empty($user['telegram_chat_id'])) {
        $telegramSent = sendTelegram($pdo, $userId, $tgMsg, $tag);
    }

    // Send email if enabled — independent of whether Telegram succeeded,
    // so users who opted into both channels actually get both.
    if (!empty($user['notify_email'])) {
        $sendTo = !empty($user['notification_email']) ? $user['notification_email'] : $user['email'];
        if (!empty($sendTo)) {
            $emailSent = sendEmail($pdo, $userId, $sendTo, $emailSubject, $emailHtml, $tag);
        }
    }

    return $telegramSent || $emailSent;
}
