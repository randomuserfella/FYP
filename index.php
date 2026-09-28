<?php
require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/csrf.php';

if (isset($_SESSION['user_id'])) {
    $dest = ($_SESSION['user_role'] ?? 'student') === 'lecturer' ? 'lecturer.php' : 'dashboard.php';
    header('Location: ' . tab_url($dest, $GLOBALS['tab_id'])); exit;
}

$error = '';

// Guard: a login POST without a real tab_id means the form was submitted
// before tab_inject.js stamped the hidden tab field. Processing it here
// would set $_SESSION on the throwaway 'pt_tmp' session, which gets wiped
// on the next page load — causing an immediate bounce back to login.
// Instead, re-render the page so JS can attach a real tab_id and the
// person can submit again with a session that will actually persist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($GLOBALS['tab_id'])) {
    $error = 'Session not ready yet — please click Sign in again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email     = trim($_POST['email']    ?? '');
    $loginPass = trim($_POST['password'] ?? '');
    $remember  = !empty($_POST['remember']);

    try {
        require_once __DIR__ . '/config/db.php';
    } catch (Exception $e) {
        $error = 'DB Error: ' . $e->getMessage();
    }

    if (!$error) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "No account found for that email.";
        } elseif (!password_verify($loginPass, $user['password'])) {
            $error = "Wrong password. Please try again.";
        } else {
            $role = $user['role'] ?? 'student';

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $role;

            if ($remember) {
                // Issue a persistent "keep me logged in" token (separate
                // long-lived cookie, decoupled from the per-tab pt_<tab_id>
                // session cookie so it survives sessionStorage being
                // cleared, the browser closing, or the local server
                // restarting). tab_session.php will pick this cookie up
                // and re-populate $_SESSION on any future tab/session.
                require_once __DIR__ . '/config/remember.php';
                remember_issue($pdo, (int)$user['id']);
            }

            $dest = ($role === 'lecturer') ? 'lecturer.php' : 'dashboard.php';
            header('Location: ' . tab_url($dest, $GLOBALS['tab_id'])); exit;
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
    <title>ProcraTrack — Sign In</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bg-base:#0f0f13;--bg-surface:#1c1c26;--bg-input:#16161d;--border:#2a2a38;--text-main:#e8e8f0;--text-muted:#7a7a95;--text-dim:#3a3a52;--accent:#7c6af7;--accent-glow:rgba(124,106,247,.15); }
        [data-theme="light"] { --bg-base:#f2f2f8;--bg-surface:#ffffff;--bg-input:#ffffff;--border:#d0d0e0;--text-main:#111122;--text-muted:#4a4a6a;--text-dim:#8888aa;--accent:#5b49e0;--accent-glow:rgba(91,73,224,.12); }
        *, *::before, *::after { transition: background-color .3s ease, border-color .3s ease, color .3s ease, box-shadow .3s ease !important; }
        button, a, input { transition: opacity .2s ease, transform .15s ease !important; }
        [data-theme="light"] .form-control { background-color:#fff !important; color:#111122 !important; border-color:#d0d0e0 !important; }
        [data-theme="light"] .form-control::placeholder { color:#8888aa !important; }
        [data-theme="light"] .form-control:focus { background-color:#fff !important; box-shadow:0 0 0 3px var(--accent-glow) !important; }
        body { background:var(--bg-base); min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',sans-serif; }
        .theme-float { position:fixed; top:18px; right:18px; z-index:9999; }
        .theme-toggle { background:var(--bg-input); border:1px solid var(--border); color:var(--text-muted); border-radius:8px; width:34px; height:34px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; }
        .theme-toggle:hover { border-color:var(--accent); color:var(--accent); }
        .login-card { background:var(--bg-surface); border:1px solid var(--border); border-radius:18px; padding:40px; width:100%; max-width:420px; }
        .brand { text-align:center; margin-bottom:30px; }
        .brand-name { font-size:22px; font-weight:700; color:var(--accent); }
        .brand-name span { color:var(--text-main); }
        .brand-sub { font-size:13px; color:var(--text-muted); margin-top:4px; }
        .form-label { color:var(--text-muted); font-size:13px; margin-bottom:6px; }
        .form-control { background:var(--bg-input); border:1px solid var(--border); color:var(--text-main); border-radius:10px; padding:12px 16px; font-size:14px; }
        .form-control:focus { background:var(--bg-input); border-color:var(--accent); color:var(--text-main); box-shadow:0 0 0 3px var(--accent-glow); }
        .form-control::placeholder { color:var(--text-dim); }
        .pass-wrap { position:relative; }
        .pass-wrap .form-control { padding-right:44px; }
        .toggle-pass { position:absolute; right:14px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:16px; padding:0; }
        .toggle-pass:hover { color:var(--accent); }
        .remember-row { display:flex; align-items:center; justify-content:space-between; margin-top:12px; margin-bottom:4px; }
        .remember-row label { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text-muted); cursor:pointer; }
        .remember-row input[type=checkbox] { accent-color:var(--accent); width:15px; height:15px; cursor:pointer; }
        .forgot-link { font-size:13px; color:var(--accent); text-decoration:none; }
        .forgot-link:hover { text-decoration:underline; }
        .btn-signin { width:100%; padding:12px; border:none; border-radius:10px; background:var(--accent); color:#fff; font-size:15px; font-weight:600; cursor:pointer; margin-top:18px; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-signin:hover { opacity:.87; }
        .divider { border:none; border-top:1px solid var(--border); margin:24px 0; }
        .bottom-links { text-align:center; font-size:13px; color:var(--text-muted); }
        .bottom-links a { color:var(--accent); text-decoration:none; font-weight:600; }
        .bottom-links a:hover { text-decoration:underline; }
        .alert-error { background:rgba(247,106,106,.1); border:1px solid rgba(247,106,106,.3); color:#f76a6a; border-radius:10px; padding:12px 16px; font-size:13px; margin-bottom:20px; }
        .alert-success { background:rgba(106,247,184,.1); border:1px solid rgba(106,247,184,.3); color:#6af7b8; border-radius:10px; padding:12px 16px; font-size:13px; margin-bottom:20px; }
    </style>
    <script src="includes/tab_inject.js"></script>
</head>
<body>
<div class="theme-float">
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
        <i class="bi bi-moon-stars-fill theme-icon"></i>
    </button>
</div>

<div class="login-card">
    <div class="brand">
        <div class="brand-name">🧠 Procra<span>Track</span></div>
        <div class="brand-sub">Sign in to your account</div>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['reset']) && $_GET['reset'] === 'done'): ?>
        <div class="alert-success"><i class="bi bi-check-circle me-2"></i>Password reset successfully. Sign in below.</div>
    <?php endif; ?>
    <?php if (isset($_GET['registered']) && $_GET['registered'] !== 'lecturer'): ?>
        <div class="alert-success"><i class="bi bi-check-circle me-2"></i>Account created! Sign in to get started.</div>
    <?php endif; ?>
    <?php if (isset($_GET['registered']) && $_GET['registered'] === 'lecturer'): ?>
        <div class="alert-success" style="background:rgba(0,137,123,.1);border-color:rgba(0,137,123,.3);color:#4db6ac;">
            <i class="bi bi-clock-history me-2"></i>
            <strong>Lecturer registration submitted.</strong> Your account is pending admin approval — you'll receive an email once it's active.
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php<?= $GLOBALS['tab_id'] ? '?tab=' . htmlspecialchars($GLOBALS['tab_id']) : '' ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label">Email address</label>
            <input type="email" name="email" class="form-control"
                   placeholder="you@university.edu.my"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="mb-1">
            <label class="form-label">Password</label>
            <div class="pass-wrap">
                <input type="password" name="password" id="password"
                       class="form-control" placeholder="Enter your password" required>
                <button type="button" class="toggle-pass" onclick="togglePass()" aria-label="Show/hide password">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        <div class="remember-row">
            <label>
                <input type="checkbox" name="remember">
                Keep me logged in
            </label>
            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
        </div>
        <button type="submit" class="btn-signin">
            <i class="bi bi-box-arrow-in-right"></i> Sign in
        </button>
    </form>

    <hr class="divider">
    <div class="bottom-links">
        Don't have an account? <a href="register.php">Create one</a>
    </div>
</div>

<script>
function togglePass() {
    var f = document.getElementById('password'), i = document.getElementById('eyeIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
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
document.addEventListener('DOMContentLoaded', function() {
    var t = localStorage.getItem('pt_student_theme') || 'dark';
    document.querySelectorAll('.theme-icon').forEach(function(i) {
        i.className = 'bi ' + (t === 'dark' ? 'bi-moon-stars-fill' : 'bi-sun-fill') + ' theme-icon';
    });
});

// Guard: block the login submit unless the URL has a real ?tab= right now.
// tab_inject.js stamps a hidden tab field onto the form, but if the page
// itself was rendered before a tab_id existed, the form's action attribute
// has no ?tab= baked in — this catches that case at submit time instead of
// after a round trip to the server.
(function () {
    var form = document.querySelector('form[method="POST"]');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (!tab || !/^[a-z0-9]{8,32}$/.test(tab)) {
            e.preventDefault();
            // Force a clean reload so tab_inject.js can attach a real tab_id,
            // then the form (and its action URL) will be correct on next render.
            window.location.reload();
            return false;
        }
        // Make sure the action URL itself carries the tab param, not just
        // the hidden input — belt and suspenders against stale server-rendered markup.
        if (form.action.indexOf('tab=') === -1) {
            form.action += (form.action.indexOf('?') === -1 ? '?' : '&') + 'tab=' + tab;
        }
    });
})();
</script>
</body>
</html>
