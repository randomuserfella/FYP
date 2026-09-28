<?php
require_once __DIR__ . '/config/db_credentials.php';

$token       = trim($_GET['token'] ?? '');
$error       = '';
$tokenValid  = false;
$userEmail   = '';
$userId      = null;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($token) {
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > UTC_TIMESTAMP()");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tokenValid = true;
            $userEmail  = $row['email'];

            $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $userStmt->execute([$userEmail]);
            $userId = $userStmt->fetchColumn();
        } else {
            $error = "This reset link is invalid or has expired. Please request a new one.";
        }
    } else {
        $error = "No reset token provided.";
    }
} catch (Exception $e) {
    error_log('reset_password.php error: ' . $e->getMessage());
    $error = "Database error. Please try again.";
}

// Handle form submission
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPass     = $_POST['password']         ?? '';
    $confirmPass = $_POST['password_confirm'] ?? '';

    if (strlen($newPass) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($newPass !== $confirmPass) {
        $error = "Passwords do not match.";
    } else {
        try {
            $hashed = password_hash($newPass, PASSWORD_BCRYPT);

            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashed, $userId]);

            if ($updateStmt->rowCount() === 0) {
                $error = "Could not update password. Please request a new reset link.";
            } else {
                // Delete used token
                $pdo->prepare("DELETE FROM password_resets WHERE email = ?")
                    ->execute([$userEmail]);

                // Redirect to login with success message
                header("Location: index.php?reset=done");
                exit;
            }
        } catch (Exception $e) {
            error_log('reset_password.php update error: ' . $e->getMessage());
            $error = "Could not update password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<script>(function(){var t=localStorage.getItem("pt_student_theme")||"dark";document.documentElement.setAttribute("data-theme",t);})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — Reset Password</title>
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
        .pass-wrap { position: relative; }
        .pass-wrap .form-control { padding-right: 44px; }
        .toggle-pass {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 16px; padding: 0;
        }
        .toggle-pass:hover { color: var(--accent); }
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
        .password-strength { margin-top: 6px; height: 4px; border-radius: 2px; background: var(--border); overflow: hidden; }
        .strength-bar { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0%; }
        .strength-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .icon-wrap {
            width: 56px; height: 56px; border-radius: 14px;
            background: rgba(124,106,247,0.15); display: flex;
            align-items: center; justify-content: center;
            font-size: 24px; margin: 0 auto 16px;
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
    <div class="icon-wrap">🔒</div>
    <div class="subtitle">Enter your new password below.</div>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <?php if (!$tokenValid): ?>
                <div class="mt-2"><a href="forgot_password.php" style="color:#f76a6a;font-weight:600;">Request a new link →</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($tokenValid && !$success): ?>
    <form method="POST" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
        <div class="mb-3">
            <label class="form-label">New Password</label>
            <div class="pass-wrap">
                <input type="password" name="password" id="newPass"
                       class="form-control" placeholder="Min. 8 characters"
                       oninput="checkStrength(this.value)" required>
                <button type="button" class="toggle-pass" onclick="togglePass('newPass','eye1')">
                    <i class="bi bi-eye" id="eye1"></i>
                </button>
            </div>
            <div class="password-strength"><div class="strength-bar" id="strengthBar"></div></div>
            <div class="strength-label" id="strengthLabel"></div>
        </div>
        <div class="mb-4">
            <label class="form-label">Confirm New Password</label>
            <div class="pass-wrap">
                <input type="password" name="password_confirm" id="confirmPass"
                       class="form-control" placeholder="Repeat your password" required>
                <button type="button" class="toggle-pass" onclick="togglePass('confirmPass','eye2')">
                    <i class="bi bi-eye" id="eye2"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary-custom">
            <i class="bi bi-shield-check me-2"></i>Reset Password
        </button>
    </form>
    <?php endif; ?>

    <div class="back-link">
        <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
    </div>
</div>
<script>
function togglePass(fieldId, iconId) {
    const f = document.getElementById(fieldId);
    const i = document.getElementById(iconId);
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}
function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)  score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct: '0%',   color: '#2a2a38', text: '' },
        { pct: '25%',  color: '#f76a6a', text: 'Weak' },
        { pct: '50%',  color: '#f7a06a', text: 'Fair' },
        { pct: '75%',  color: '#f7e06a', text: 'Good' },
        { pct: '100%', color: '#6acf6a', text: 'Strong' },
    ];
    bar.style.width      = levels[score].pct;
    bar.style.background = levels[score].color;
    label.textContent    = levels[score].text;
}
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
