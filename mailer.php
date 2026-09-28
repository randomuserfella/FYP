<?php
// ============================================================
// mailer.php — ProcraTrack Raw SMTP Email Sender
// Called internally by sendEmail() in includes/notifier.php
// Do NOT call this directly — use sendEmail() instead
// ============================================================

require_once __DIR__ . '/email_config.php';

// ============================================================
// _sendRawEmail()
// Internal function — sends a branded HTML email via Gmail SMTP
// $to      : recipient address
// $subject : email subject
// $body    : plain text (auto-wrapped in HTML template)
// ============================================================
function _sendRawEmail($to, $subject, $body) {
    $host     = MAIL_HOST;
    $port     = MAIL_PORT;
    $username = MAIL_USERNAME;
    $password = MAIL_PASSWORD;
    $from     = MAIL_FROM;

    $year     = date('Y');
    $safeBody = nl2br(htmlspecialchars($body));

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
    <h1 style="color:#ffffff;margin:0;font-size:22px;letter-spacing:1px;font-family:Arial,Helvetica,sans-serif;">&#128218; ProcraTrack</h1>
    <p style="color:#ffffff;margin:6px 0 0;font-size:13px;font-family:Arial,Helvetica,sans-serif;">Student Productivity Tracker</p>
  </td>
</tr>
<tr>
  <td style="padding:28px 24px;color:#333333;font-size:15px;line-height:1.6;font-family:Arial,Helvetica,sans-serif;">
    <div>' . $safeBody . '</div>
    <p style="color:#999999;font-size:12px;margin-top:24px;padding-top:16px;border-top:1px solid #eeeeee;font-family:Arial,Helvetica,sans-serif;">This is an automated message from ProcraTrack.<br>Please do not reply to this email.</p>
  </td>
</tr>
<tr>
  <td align="center" bgcolor="#f9f9f9" style="background-color:#f9f9f9;border-top:1px solid #eeeeee;padding:16px 24px;font-size:12px;color:#999999;font-family:Arial,Helvetica,sans-serif;">
    &copy; ' . $year . ' ProcraTrack &nbsp;|&nbsp;<a href="mailto:' . $username . '" style="color:#6C63FF;text-decoration:none;">' . $username . '</a>
  </td>
</tr>
</table>
</td></tr>
</table>
</body></html>';

    $plain     = $body . "\r\n\r\n---\r\nThis is an automated message. Please do not reply.";
    $boundary  = 'PT_' . md5(uniqid(time(), true));

    $headers   = "MIME-Version: 1.0\r\n";
    $headers  .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers  .= "From: {$from}\r\n";
    $headers  .= "To: {$to}\r\n";
    $headers  .= "Subject: {$subject}\r\n";
    $headers  .= "X-Mailer: ProcraTrack/1.0\r\n";

    $emailBody  = "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$plain}\r\n\r\n";
    $emailBody .= "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n";
    $emailBody .= "--{$boundary}--";

    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        error_log("ProcraTrack mailer: fsockopen failed — $errstr ($errno)");
        return false;
    }

    $steps = [
        null,
        "EHLO localhost",
        "AUTH LOGIN",
        base64_encode($username),
        base64_encode($password),
        "MAIL FROM:<{$username}>",
        "RCPT TO:<{$to}>",
        "DATA",
        $headers . "\r\n" . $emailBody . "\r\n.",
        "QUIT"
    ];

    foreach ($steps as $cmd) {
        if ($cmd !== null) fwrite($socket, "{$cmd}\r\n");
        fgets($socket, 512);
    }

    fclose($socket);
    return true;
}
