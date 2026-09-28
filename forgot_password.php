<?php
require_once __DIR__ . '/config/db_credentials.php';
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/mailer.php';

// ── Dev/demo mode: on localhost the OTP is shown on-screen so no real
// email account is needed for the FYP demo. On any real deployed host
// this is automatically OFF and the OTP is only sent to the inbox.
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
            || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:');

// ── Manual override: a toggle switch lets the user explicitly pick
// Demo Mode (show code on screen) or Real Mode (send to actual inbox),
// instead of relying only on hostname detection. If the toggle isn't
// present in the request (e.g. first page load), we fall back to the
// existing auto-detection above — so default behavior is unchanged.
$demoMode = isset($_POST['demo_mode']) ? ($_POST['demo_mode'] === '1') : $isLocalhost;

// Page has three states:
//   'email'  — initial email form (default)
//   'otp'    — OTP shown/entered after email submitted
//   'error'  — something went wrong
$state   = 'email';
$error   = '';
$otp     = '';       // only populated in dev mode after generation
$email   = '';       // carried across states

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log('forgot_password.php db error: ' . $e->getMessage());
    $state = 'error';
    $error = "Database unavailable. Please try again later.";
}

// ── Step 1: email submitted → generate OTP ──────────────────────────────────
if ($state !== 'error' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && !isset($_POST['otp_code'])) {
    $email = trim($_POST['email'] ?? '');

    try {
        // Generate a 6-digit OTP for identity verification.
        // We store just the OTP in the token column during this step (6 chars,
        // well within VARCHAR(64)). Once the user verifies it, we replace it
        // with a proper full-length reset token in step 2.
        $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires  = gmdate('Y-m-d H:i:s', strtotime('+15 minutes'));

        $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
        $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
            ->execute([$email, $otp_code, $expires]);

        // Demo Mode: surface OTP on-screen. Real Mode: email it to the user.
        if ($demoMode) {
            $otp = $otp_code; // shown in the UI below
        } else {
            _sendRawEmail(
                $email,
                'ProcraTrack — Your Verification Code',
                "Your ProcraTrack password reset code is:\n\n  {$otp_code}\n\n" .
                "This code expires in 15 minutes. If you didn't request this, ignore this email."
            );
        }

        $state = 'otp';

    } catch (Exception $e) {
        error_log('forgot_password.php step1 error: ' . $e->getMessage());
        $state = 'error';
        $error = "Something went wrong. Please try again.";
    }
}

// ── Step 2: OTP submitted → verify and redirect to reset form ───────────────
if ($state !== 'error' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
    $email        = trim($_POST['email'] ?? '');
    $entered_otp  = trim($_POST['otp_code'] ?? '');
    // Carry the mode chosen on the previous step forward, so the retry-on-error
    // display stays consistent with what the user picked.
    $demoMode     = isset($_POST['demo_mode_carry']) ? ($_POST['demo_mode_carry'] === '1') : $isLocalhost;

    try {
        $stmt = $pdo->prepare("SELECT token FROM password_resets WHERE email = ? AND expires_at > UTC_TIMESTAMP()");
        $stmt->execute([$email]);
        $stored_otp = $stmt->fetchColumn();

        if ($stored_otp === false) {
            $state = 'otp';
            $error = "Your code has expired. Please go back and try again.";
        } elseif (!hash_equals((string)$stored_otp, (string)$entered_otp)) {
            $state = 'otp';
            $error = "Incorrect code. Please try again.";
            // Show the OTP again in Demo Mode so the demo isn't blocked
            if ($demoMode) $otp = $stored_otp;
        } else {
            // OTP correct — replace with a proper reset token (1 hour lifetime)
            // and redirect to the password reset form.
            $real_token = bin2hex(random_bytes(32));
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$email, $real_token, gmdate('Y-m-d H:i:s', strtotime('+1 hour'))]);

            header('Location: reset_password.php?token=' . urlencode($real_token));
            exit;
        }
    } catch (Exception $e) {
        error_log('forgot_password.php step2 error: ' . $e->getMessage());
        $state = 'error';
        $error = "Something went wrong. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<script>(function(){var t=localStorage.getItem("pt_student_theme")||"dark";document.documentElement.setAttribute("data-theme",t);})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — Forgot Password</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-base:    #0f0f13;
            --bg-surface: #1c1c26;
            --bg-input:   #16161d;
            --border:     #2a2a38;
            --text-main:  #e8e8f0;
            --text-muted: #7a7a95;
            --text-dim:   #3a3a52;
            --accent:     #7c6af7;
        }
        [data-theme="light"] {
            --bg-base:    #f2f2f8;
            --bg-surface: #ffffff;
            --bg-input:   #ffffff;
            --border:     #d0d0e0;
            --text-main:  #111122;
            --text-muted: #4a4a6a;
            --text-dim:   #8888aa;
            --accent:     #5b49e0;
        }
        *, *::before, *::after {
            transition: background-color 0.4s ease, border-color 0.4s ease,
                color 0.4s ease, box-shadow 0.4s ease !important;
        }
        [data-theme="light"] .form-control {
            background-color: #ffffff !important;
            color: #111122 !important;
            border-color: #d0d0e0 !important;
        }
        [data-theme="light"] .form-control:focus {
            border-color: #5b49e0 !important;
            box-shadow: 0 0 0 3px rgba(91,73,224,0.15) !important;
        }
        .theme-toggle {
            background: var(--bg-input); border: 1px solid var(--border);
            color: var(--text-muted); border-radius: 8px;
            width: 34px; height: 34px; display: flex; align-items: center;
            justify-content: center; cursor: pointer; font-size: 16px;
        }
        .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }
        .theme-float { position: fixed; top: 18px; right: 18px; z-index: 9999; }
        body {
            background: var(--bg-base); min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .card-box {
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: 16px; padding: 40px; width: 100%; max-width: 440px;
        }
        .logo { font-size: 24px; font-weight: 700; color: #7c6af7; text-align: center; margin-bottom: 8px; }
        .logo span { color: var(--text-main); }
        .subtitle { text-align: center; color: var(--text-muted); font-size: 14px; margin-bottom: 24px; }
        .form-label { color: var(--text-muted); font-size: 13px; margin-bottom: 6px; }
        .form-control {
            background: var(--bg-input); border: 1px solid var(--border);
            color: var(--text-main); border-radius: 10px; padding: 12px 16px;
        }
        .form-control:focus {
            background: var(--bg-input); border-color: var(--accent); color: var(--text-main);
            box-shadow: 0 0 0 3px rgba(124,106,247,0.15);
        }
        .form-control::placeholder { color: var(--text-dim); }
        .btn-primary-custom {
            background: #7c6af7; border: none; border-radius: 10px; color: #fff;
            font-weight: 600; padding: 12px; width: 100%; font-size: 15px;
        }
        .btn-primary-custom:hover { opacity: 0.88; color: #fff; }
        .back-link { text-align: center; font-size: 14px; color: var(--text-muted); margin-top: 20px; }
        .back-link a { color: #7c6af7; text-decoration: none; font-weight: 600; }
        .alert-error {
            background: rgba(247,106,106,0.1); border: 1px solid rgba(247,106,106,0.3);
            color: #f76a6a; border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-bottom: 16px;
        }
        .icon-wrap {
            width: 56px; height: 56px; border-radius: 14px;
            background: rgba(124,106,247,0.15); display: flex;
            align-items: center; justify-content: center;
            font-size: 24px; margin: 0 auto 16px;
        }
        /* OTP display box — demo mode only */
        .otp-demo-box {
            background: rgba(124,106,247,0.08);
            border: 1px dashed var(--accent);
            border-radius: 12px; padding: 16px 20px;
            margin-bottom: 20px; text-align: center;
        }
        .otp-demo-box .otp-label {
            font-size: 12px; color: var(--text-muted); margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.07em;
        }
        .otp-demo-box .otp-code {
            font-size: 36px; font-weight: 800; letter-spacing: 0.18em;
            color: var(--accent); font-family: monospace;
        }
        .otp-demo-box .otp-hint {
            font-size: 12px; color: var(--text-muted); margin-top: 6px;
        }
        /* OTP input — big digits */
        .otp-input {
            text-align: center; font-size: 28px; font-weight: 700;
            letter-spacing: 0.3em; font-family: monospace;
        }
    </style>
</head>
<body>
<div class="theme-float">
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
        <i class="bi bi-moon-stars-fill theme-icon"></i>
    </button>
</div>
<div class="card-box">
    <div class="logo">🧠 Procra<span>Track</span></div>

    <?php if ($state === 'email'): ?>
    <!-- ── State 1: Email form ─────────────────────────────────────── -->
    <div class="icon-wrap">🔑</div>
    <div class="subtitle">Enter your email and we'll send you a verification code to reset your password.</div>
    <form method="POST" action="forgot_password.php">
        <div class="mb-4">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control"
                   placeholder="you@university.edu" required
                   value="<?= htmlspecialchars($email) ?>">
        </div>
        <div class="form-check form-switch mb-4" style="padding-left:2.8em;">
            <input type="hidden" name="demo_mode" value="0">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="demoModeSwitch" name="demo_mode" value="1"
                   <?= $isLocalhost ? 'checked' : '' ?>
                   style="width:2.4em;height:1.3em;cursor:pointer;">
            <label class="form-check-label" for="demoModeSwitch"
                   style="font-size:13px;color:var(--text-muted);cursor:pointer;">
                Demo mode — show code on screen instead of emailing it
            </label>
        </div>
        <button type="submit" class="btn btn-primary-custom">
            <i class="bi bi-shield-lock me-2"></i>Send Verification Code
        </button>
    </form>

    <?php elseif ($state === 'otp'): ?>
    <!-- ── State 2: OTP verify form ───────────────────────────────── -->
    <div class="icon-wrap">🔐</div>
    <div class="subtitle">
        <?php if ($demoMode): ?>
            Use the code below to verify your identity.
        <?php else: ?>
            We sent a 6-digit code to <strong><?= htmlspecialchars($email) ?></strong>. Enter it below.
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($demoMode && $otp): ?>
    <!-- Demo OTP display -->
    <div class="otp-demo-box">
        <div class="otp-label"><i class="bi bi-display me-1"></i>Demo mode — your code</div>
        <div class="otp-code"><?= htmlspecialchars($otp) ?></div>
        <div class="otp-hint">Enter this code in the field below &darr;</div>
    </div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
        <input type="hidden" name="demo_mode_carry" value="<?= $demoMode ? '1' : '0' ?>">
        <div class="mb-4">
            <label class="form-label">Verification Code</label>
            <input type="text" name="otp_code" class="form-control otp-input"
                   placeholder="000000" maxlength="6" pattern="\d{6}"
                   inputmode="numeric" autocomplete="one-time-code"
                   required autofocus>
        </div>
        <button type="submit" class="btn btn-primary-custom">
            <i class="bi bi-check-circle me-2"></i>Verify Code
        </button>
    </form>
    <div style="text-align:center;margin-top:14px;font-size:13px;color:var(--text-muted)">
        Wrong email? <a href="forgot_password.php" style="color:var(--accent);font-weight:600">Start over</a>
    </div>

    <?php else: ?>
    <!-- ── State 3: Error (DB down etc.) ──────────────────────────── -->
    <div class="icon-wrap">⚠️</div>
    <div class="alert-error">
        <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
    </div>
</div>
<script>
function toggleTheme() {
    var html = document.documentElement;
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('pt_student_theme', next);
    document.querySelectorAll('.theme-icon').forEach(function(i) {
        i.className = 'bi ' + (next === 'dark' ? 'bi-moon-stars-fill' : 'bi-sun-fill') + ' theme-icon';
    });
}
document.addEventListener('DOMContentLoaded', function(){
    var t = localStorage.getItem('pt_student_theme') || 'dark';
    document.querySelectorAll('.theme-icon').forEach(function(i) {
        i.className = 'bi ' + (t === 'dark' ? 'bi-moon-stars-fill' : 'bi-sun-fill') + ' theme-icon';
    });
});
</script>
</body>
</html>
