<?php
require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/csrf.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (isset($_SESSION['user_id'])) {
    $dest = ($_SESSION['user_role'] ?? 'student') === 'lecturer' ? 'lecturer.php' : 'dashboard.php';
    header('Location: ' . tab_url($dest, $GLOBALS['tab_id']));
    exit;
}

// Demo invite codes (valid codes — institution name is entered by the user)
$invite_codes = [
    'UTM-2025'  => true,
    'BETA-2025' => true,
    'GAMMA-25'  => true,
    'DELTA-25'  => true,
    'EPS-2025'  => true,
];

$departments = [
    'Faculty of Computing',
    'Faculty of Engineering',
    'Faculty of Science',
    'Faculty of Management',
    'Faculty of Built Environment',
    'Faculty of Medicine',
    'Faculty of Law',
    'Other',
];

// ── Institutional email validation (shared by AJAX send-code + final submit) ─
function pt_is_valid_lecturer_email(string $email): bool {
    $email_domain = strtolower(substr($email, strpos($email, '@') + 1));
    $edu_suffixes = ['edu.my', 'ac.my', 'edu.sg', 'ac.uk', 'edu.au', 'ac.nz'];
    // Some institutions issue email on domains that don't follow the standard
    // .edu.my / .ac.my suffix pattern — whitelist those exactly.
    $edu_exact = ['segi4u.my', 'segi.edu.my', 'taylors.edu.my', 'sd.taylors.edu.my'];
    if (in_array($email_domain, $edu_exact, true)) return true;
    foreach ($edu_suffixes as $suffix) {
        if ($email_domain === $suffix || str_ends_with($email_domain, '.' . $suffix)) return true;
    }
    // Also accept bare .edu (US)
    if ($email_domain === 'edu' || str_ends_with($email_domain, '.edu')) return true;
    return false;
}

// ── AJAX: send / verify the email verification code ──────────────────────────
if (isset($_POST['ajax_action']) && in_array($_POST['ajax_action'], ['send_code', 'verify_code'], true)) {
    header('Content-Type: application/json');
    csrf_verify();

    if ($_POST['ajax_action'] === 'send_code') {
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $real_mode = !empty($_POST['real_mode']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
            exit;
        }
        if (!pt_is_valid_lecturer_email($email)) {
            echo json_encode(['ok' => false, 'error' => 'Please use your institutional email (e.g. name@university.edu.my).']);
            exit;
        }

        require_once __DIR__ . '/config/db.php';
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'That email is already registered.']);
            exit;
        }

        $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['lec_reg_email_code']      = $code;
        $_SESSION['lec_reg_email_pending']   = $email;
        $_SESSION['lec_reg_email_expires']   = time() + 300; // 5 min
        $_SESSION['lec_reg_email_real_mode'] = $real_mode;
        $_SESSION['lec_reg_email_verified']  = false;

        if ($real_mode) {
            require_once __DIR__ . '/email_config.php';
            require_once __DIR__ . '/includes/notifier.php';
            $sent = sendEmail(
                $pdo, 0, $email,
                'Your ProcraTrack Verification Code',
                "Your email verification code is: <b style='font-size:20px;letter-spacing:4px'>{$code}</b><br><br>This code expires in 5 minutes.",
                'lec_reg_email_verify'
            );
            if ($sent) {
                echo json_encode(['ok' => true, 'mode' => 'real']);
            } else {
                echo json_encode(['ok' => true, 'mode' => 'demo', 'code' => $code, 'smtp_failed' => true]);
            }
        } else {
            echo json_encode(['ok' => true, 'mode' => 'demo', 'code' => $code]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'verify_code') {
        $entered = preg_replace('/\s+/', '', trim($_POST['code'] ?? ''));
        $stored  = $_SESSION['lec_reg_email_code']    ?? '';
        $pending = $_SESSION['lec_reg_email_pending'] ?? '';
        $expires = $_SESSION['lec_reg_email_expires'] ?? 0;

        if (!$stored || !$pending) {
            echo json_encode(['ok' => false, 'error' => 'No code was sent yet. Please request a new code.']);
        } elseif (time() > $expires) {
            unset($_SESSION['lec_reg_email_code'], $_SESSION['lec_reg_email_pending'], $_SESSION['lec_reg_email_expires'], $_SESSION['lec_reg_email_real_mode']);
            echo json_encode(['ok' => false, 'error' => 'Code expired. Please request a new one.']);
        } elseif ($entered !== $stored) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect code. Please try again.']);
        } else {
            $_SESSION['lec_reg_email_verified'] = true;
            echo json_encode(['ok' => true]);
        }
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_once __DIR__ . '/config/db.php';

    $invite      = strtoupper(trim($_POST['invite_code']   ?? ''));
    $email       = strtolower(trim($_POST['email']         ?? ''));
    $staff_id    = trim($_POST['staff_id']                 ?? '');
    $department  = trim($_POST['department']               ?? '');
    $name        = trim($_POST['name']                     ?? '');
    $password    = $_POST['password']                      ?? '';
    $confirm     = $_POST['confirm_password']              ?? '';
    $university  = trim($_POST['institution_name']         ?? '');
    $code_valid  = isset($invite_codes[$invite]);

    // Email domain validation — must be an institutional email
    $email_valid_domain = pt_is_valid_lecturer_email($email);

    if (!$code_valid) {
        $error = 'Invalid invite code.';
    } elseif (empty($university)) {
        $error = 'Please enter your institution name.';
    } elseif (!preg_match("/^[A-Za-z\s'&.,()\/-]+$/", $university)) {
        $error = "Institution name can only contain letters, spaces, and basic punctuation ( ' & . , ( ) / - ) — no numbers or other symbols.";
    } elseif (empty($email) || empty($staff_id) || empty($department) || empty($name) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$email_valid_domain) {
        $error = 'Please use your institutional email (e.g. name@university.edu.my). Personal emails are not accepted.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (empty($_SESSION['lec_reg_email_verified']) || ($_SESSION['lec_reg_email_pending'] ?? '') !== $email) {
        $error = 'Please verify your email address first.';
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'That email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // Store staff_id and department in university field (concatenated) until schema is updated
                // OR use the university field + a note. Adjust to match your actual schema.
                $ins = $pdo->prepare(
                    "INSERT INTO users (name, email, password, role, university, semester)
                     VALUES (?, ?, ?, 'lecturer', ?, ?)"
                );
                $ins->execute([$name, $email, $hash, $university, null]);
                unset($_SESSION['lec_reg_email_code'], $_SESSION['lec_reg_email_pending'], $_SESSION['lec_reg_email_expires'], $_SESSION['lec_reg_email_real_mode'], $_SESSION['lec_reg_email_verified']);

                // Redirect to pending page rather than auto-login
                header('Location: ' . tab_url('index.php?registered=lecturer', $GLOBALS['tab_id']));
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$invite_json = json_encode(array_keys($invite_codes));
$dept_json   = json_encode($departments, JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="en">
<script>(function(){var t=localStorage.getItem("pt_student_theme")||"dark";document.documentElement.setAttribute("data-theme",t);})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — Lecturer Registration</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--bg-base:#0f0f13;--bg-surface:#1c1c26;--bg-input:#16161d;--border:#2a2a38;--text-main:#e8e8f0;--text-muted:#a8a8c0;--text-dim:#5a5a78;--teal:#00897b;--teal-glow:rgba(0,137,123,.15);--teal-border:#00695c;--success:#6af7b8}
        [data-theme="light"]{--bg-base:#f2f2f8;--bg-surface:#ffffff;--bg-input:#f7f7fb;--border:#d0d0e0;--text-main:#111122;--text-muted:#4a4a6a;--text-dim:#8888aa;--teal:#00574f;--teal-glow:rgba(0,87,79,.12);--teal-border:#004d45;--success:#177a43}
        *,*::before,*::after{box-sizing:border-box;transition:background-color .3s,border-color .3s,color .3s,box-shadow .3s !important}
        button,a,input,select{transition:opacity .2s,transform .15s !important}
        [data-theme="light"] .fc{background:#fff !important;color:#111122 !important;border-color:#d0d0e0 !important}
        [data-theme="light"] .fc::placeholder{color:#8888aa !important}
        [data-theme="light"] .fc:focus{background:#fff !important;box-shadow:0 0 0 3px var(--teal-glow) !important}
        body{background:var(--bg-base);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;font-family:'Segoe UI',sans-serif;padding:40px 16px}
        .theme-float{position:fixed;top:18px;right:18px;z-index:9999}
        .tt{background:var(--bg-input);border:1px solid var(--border);color:var(--text-muted);border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
        .tt:hover{border-color:var(--teal);color:var(--teal)}
        .cw{background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;padding:36px 40px 32px;width:100%;max-width:460px}
        .brand{text-align:center;margin-bottom:6px}
        .bn{font-size:19px;font-weight:700;color:var(--teal)}
        .bn span{color:var(--text-main)}
        .bs{font-size:12px;color:var(--text-muted);margin-top:3px}
        .step-row{display:flex;align-items:center;justify-content:center;gap:6px;margin:18px 0 4px}
        .sd{width:7px;height:7px;border-radius:50%;background:var(--border);transition:background .25s !important}
        .sd.done{background:var(--teal)}
        .sl{font-size:11px;color:var(--text-muted);text-align:center;margin-bottom:20px}
        .fl{font-size:12px;color:var(--text-muted);margin-bottom:5px;display:block}
        .fg{margin-bottom:14px}
        .fc{width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:11px 16px;font-size:14px}
        .fc:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-glow);background:var(--bg-input);color:var(--text-main)}
        .fc::placeholder{color:var(--text-dim)}
        .pw{position:relative}
        .pw .fc{padding-right:44px}
        .eb{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:0}
        .eb:hover{color:var(--teal)}
        .invite-wrap{background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px}
        .invite-wrap p{font-size:12px;color:var(--text-muted);line-height:1.5;margin-bottom:10px}
        .invite-row{display:flex;gap:8px}
        .invite-row .fc{flex:1}
        .btn-verify-code{padding:11px 18px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0}
        .btn-verify-code:hover{opacity:.87}
        .btn-verify-code:disabled{opacity:.4;cursor:not-allowed}
        .code-msg{font-size:12px;margin-top:8px;min-height:16px;font-weight:600}
        .ok{color:var(--success)}.err{color:#f76a6a}
        .inst-bar{display:flex;align-items:center;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:12px;color:var(--text-muted);margin-bottom:14px}
        .inst-bar i{color:var(--teal);font-size:15px;flex-shrink:0}
        .inst-ac-wrap{position:relative}
        .inst-ac-dd{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:20;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.18)}
        .inst-ac-dd.show{display:block}
        .inst-ac-item{padding:10px 14px;font-size:13px;color:var(--text-main);cursor:pointer}
        .inst-ac-item:hover,.inst-ac-item.hi{background:var(--teal-glow)}
        .inst-ac-empty{padding:10px 14px;font-size:12px;color:var(--text-muted)}
        .bm{width:100%;padding:11px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;margin-top:4px}
        .bm:hover{opacity:.87}
        .bm:disabled{opacity:.4;cursor:not-allowed}
        .bb{width:100%;padding:10px;border:1px solid var(--border);border-radius:10px;background:none;color:var(--text-muted);font-size:13px;cursor:pointer;margin-top:8px}
        .bb:hover{border-color:var(--teal);color:var(--teal)}
        .sr{display:flex;gap:5px;margin-top:8px}
        .ss{flex:1;height:4px;border-radius:2px;background:var(--border)}
        .slb{font-size:11px;margin-top:4px;min-height:14px;font-weight:600}
        .sw{background:#f76a6a}.sf{background:#f7c46a}.sg{background:var(--success)}.sstr{background:var(--teal)}
        .rl{background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:7px;margin-top:8px}
        .ri{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--text-muted)}
        .rd{width:16px;height:16px;border-radius:50%;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;color:var(--text-dim)}
        .ri.ok{color:var(--success)}.ri.ok .rd{background:rgba(106,247,184,.15);color:var(--success)}
        .ri.bad{color:#f76a6a}.ri.bad .rd{background:rgba(247,106,106,.15);color:#f76a6a}
        .mm{font-size:12px;min-height:16px;margin-top:5px;font-weight:600}
        .cok{color:var(--success)}.cbad{color:#f76a6a}
        .aerr{background:rgba(247,106,106,.1);border:1px solid rgba(247,106,106,.3);color:#f76a6a;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:18px}
        .pending-box{text-align:center;padding:10px 0}
        .picon{width:52px;height:52px;border-radius:50%;background:var(--teal-glow);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;color:var(--teal)}
        .ptitle{font-size:16px;font-weight:700;color:var(--text-main);margin-bottom:8px}
        .psub{font-size:13px;color:var(--text-muted);line-height:1.6}
        .info-bar{display:flex;align-items:flex-start;gap:8px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text-muted);margin-top:14px;line-height:1.5}
        .info-bar i{color:var(--teal);font-size:15px;flex-shrink:0;margin-top:1px}
        .fl2{text-align:center;font-size:13px;color:var(--text-muted);margin-top:16px}
        .fl2 a{color:var(--teal);text-decoration:none;font-weight:600}
        .fl2 a:hover{text-decoration:underline}
        hr.dv{border:none;border-top:1px solid var(--border);margin:18px 0}
    </style>
    <script src="/includes/tab_inject.js"></script>
</head>
<body>
<div class="theme-float">
    <button class="tt" onclick="toggleTheme()" title="Toggle theme">
        <i class="bi bi-moon-stars-fill theme-icon"></i>
    </button>
</div>

<div class="cw">
    <div class="brand">
        <div class="bn">🧠 Procra<span>Track</span></div>
        <div class="bs">Set up your lecturer account</div>
    </div>

    <?php if ($error): ?>
        <div class="aerr" style="margin-top:14px">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="step-row">
        <div class="sd done" id="sd0"></div>
        <div class="sd"      id="sd1"></div>
        <div class="sd"      id="sd2"></div>
        <div class="sd"      id="sd3"></div>
    </div>
    <div class="sl" id="step-lbl">Step 1 — Institution invite code</div>

    <form method="POST" action="lecturer_register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>" id="lecForm">
        <?= csrf_field() ?>
        <input type="hidden" name="tab"              value="<?= htmlspecialchars($GLOBALS['tab_id']) ?>">
        <input type="hidden" name="invite_code"      id="invite_hidden"       value="">
        <input type="hidden" name="institution_name" id="institution_name_hidden" value="">

        <!-- STEP 1: Invite code -->
        <div id="s1">
            <div class="invite-wrap">
                <p>Your institution admin provides a unique invite code. Check your staff email or contact your IT department.</p>
                <div class="invite-row">
                    <input type="text" id="code_inp" class="fc"
                           placeholder="e.g. UTM-2025"
                           maxlength="12"
                           style="text-transform:uppercase;letter-spacing:2px;font-weight:600"
                           oninput="this.value=this.value.toUpperCase()">
                    <button type="button" class="btn-verify-code" onclick="verifyCode()">Verify</button>
                </div>
                <div class="code-msg" id="code-msg"></div>
            </div>

            <!-- Institution name — revealed after code verified -->
            <div id="inst-name-wrap" style="display:none;margin-bottom:14px">
                <label class="fl" for="inst_name_inp">Institution name</label>
                <div class="inst-ac-wrap">
                    <input type="text" id="inst_name_inp" class="fc"
                           placeholder="e.g. Universiti Teknologi Malaysia"
                           value="<?= htmlspecialchars($_POST['institution_name'] ?? '') ?>"
                           autocomplete="off"
                           oninput="onInstNameInput()" onfocus="renderInstSuggestions()">
                    <div class="inst-ac-dd" id="inst-ac-dd"></div>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:5px;line-height:1.5">
                    <i class="bi bi-info-circle me-1"></i>Enter your institution's full official name. Letters only — no numbers or symbols.
                </div>
            </div>
            <button type="button" class="bm" id="btn-s1" disabled onclick="goTo(2)">
                <i class="bi bi-arrow-right"></i> Continue
            </button>
            <hr class="dv">
            <div class="fl2">Already have an account? <a href="<?= tab_url('index.php', $GLOBALS['tab_id']) ?>">Sign in</a></div>
            <div class="fl2" style="margin-top:8px">Student? <a href="<?= tab_url('register.php', $GLOBALS['tab_id']) ?>">Register here</a></div>
        </div>

        <!-- STEP 2: Staff details -->
        <div id="s2" style="display:none">
            <div class="inst-bar">
                <i class="bi bi-building-check"></i>
                <span id="inst-label">Institution — verified</span>
            </div>

            <div class="fg">
                <label class="fl" for="email_inp">Staff email</label>
                <input type="email" id="email_inp" name="email" class="fc"
                       placeholder="name@university.edu.my"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       oninput="checkEmailHint()">
                <div id="email-hint" style="font-size:11px;min-height:15px;margin-top:5px;font-weight:600"></div>
            </div>

            <div class="fg">
                <label class="fl" for="staff_id_inp">Staff ID</label>
                <input type="text" id="staff_id_inp" name="staff_id" class="fc"
                       placeholder="e.g. UTM-STF-00123"
                       value="<?= htmlspecialchars($_POST['staff_id'] ?? '') ?>">
            </div>

            <div class="fg">
                <label class="fl" for="dept_inp">Department</label>
                <select id="dept_inp" name="department" class="fc">
                    <option value="">Select department…</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"
                            <?= ($_POST['department'] ?? '') === $d ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px">
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--text-main)"><i class="bi bi-envelope-paper"></i> Real Email Mode</div>
                    <div style="font-size:11px;color:var(--text-muted)">Off = Demo (code shown here). On = actually emails the code.</div>
                </div>
                <label style="position:relative;display:inline-block;width:44px;height:24px;flex:none">
                    <input type="checkbox" id="real-mode-toggle" style="opacity:0;width:0;height:0"
                           onchange="document.getElementById('rmt-track').style.background=this.checked?'var(--teal)':'#ccc'; document.getElementById('rmt-knob').style.transform=this.checked?'translateX(20px)':'translateX(0)';">
                    <span id="rmt-track" style="position:absolute;inset:0;background:#ccc;border-radius:24px;transition:.2s;pointer-events:none">
                        <span id="rmt-knob" style="position:absolute;left:2px;top:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></span>
                    </span>
                </label>
            </div>

            <button type="button" class="bm" id="btn-s2" onclick="toStepVerify()">
                <i class="bi bi-send"></i> Send verification code
            </button>
            <div class="code-msg" id="send-err" style="color:#f76a6a"></div>
            <button type="button" class="bb" onclick="goTo(1)">← Back</button>
        </div>

        <!-- STEP 3: Verify email -->
        <div id="s3" style="display:none">
            <div class="invite-wrap" style="text-align:center">
                <i class="bi bi-envelope-check" style="font-size:26px;color:var(--teal);margin-bottom:8px;display:block"></i>
                <p id="verify-lead">We sent a 6-digit code to<br>
                   <strong id="email-show" style="color:var(--text-main)">you@university.edu.my</strong>
                </p>
                <div style="font-size:26px;font-weight:700;letter-spacing:8px;color:var(--text-main);margin:10px 0 4px;display:none" id="code-display">847 291</div>
                <div style="font-size:11px;color:var(--text-dim)" id="code-hint">(demo — code shown above)</div>
            </div>
            <div class="fg">
                <label class="fl" for="verify_code_inp">Enter the code</label>
                <input type="text" id="verify_code_inp" class="fc"
                       placeholder="_ _ _ _ _ _" maxlength="7"
                       style="text-align:center;letter-spacing:6px;font-weight:700;font-size:18px"
                       oninput="checkLecCode()">
            </div>
            <div class="code-msg" id="verify-err" style="color:#f76a6a"></div>
            <button type="button" class="bm" id="btn-verify" disabled onclick="doVerifyLecturer()">
                <i class="bi bi-patch-check"></i> Verify email
            </button>
            <button type="button" class="bb" onclick="goTo(2)">← Back</button>
        </div>

        <!-- STEP 4: Name + Password -->
        <div id="s4" style="display:none">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.5">
                One last step — set your password. Your admin will configure classes and student access after approval.
            </p>

            <div class="fg">
                <label class="fl" for="name_inp">Full name</label>
                <input type="text" id="name_inp" name="name" class="fc"
                       placeholder="e.g. Dr. Ahmad / Prof. Sarah"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       oninput="checkSubmit()">
            </div>

            <div class="fg">
                <label class="fl" for="pass_inp">Password</label>
                <div class="pw">
                    <input type="password" id="pass_inp" name="password" class="fc"
                           placeholder="At least 8 characters" oninput="checkStrength()">
                    <button type="button" class="eb" onclick="toggleEye('pass_inp','eye1')" tabindex="-1">
                        <i class="bi bi-eye" id="eye1"></i>
                    </button>
                </div>
                <div class="sr">
                    <div class="ss" id="ss1"></div><div class="ss" id="ss2"></div>
                    <div class="ss" id="ss3"></div><div class="ss" id="ss4"></div>
                </div>
                <div class="slb" id="str-lbl"></div>
                <div class="rl">
                    <div class="ri" id="req-len">  <div class="rd"><i class="bi bi-dash"></i></div> At least 8 characters</div>
                    <div class="ri" id="req-upper"><div class="rd"><i class="bi bi-dash"></i></div> One uppercase letter (A–Z)</div>
                    <div class="ri" id="req-lower"><div class="rd"><i class="bi bi-dash"></i></div> One lowercase letter (a–z)</div>
                    <div class="ri" id="req-num">  <div class="rd"><i class="bi bi-dash"></i></div> One number (0–9)</div>
                    <div class="ri" id="req-spec"> <div class="rd"><i class="bi bi-dash"></i></div> One special character</div>
                </div>
            </div>

            <div class="fg">
                <label class="fl" for="conf_inp">Confirm password</label>
                <div class="pw">
                    <input type="password" id="conf_inp" name="confirm_password" class="fc"
                           placeholder="Repeat password" oninput="checkSubmit()">
                    <button type="button" class="eb" onclick="toggleEye('conf_inp','eye2')" tabindex="-1">
                        <i class="bi bi-eye" id="eye2"></i>
                    </button>
                </div>
                <div class="mm" id="match-msg"></div>
            </div>

            <button type="submit" class="bm" id="btn-submit" disabled>
                <i class="bi bi-send"></i> Submit for approval
            </button>
            <button type="button" class="bb" onclick="goTo(3)">← Back</button>
        </div>

        <!-- STEP 5: Pending (shown after POST redirect via ?registered=lecturer) -->
        <div id="s5" style="display:none">
            <div class="pending-box">
                <div class="picon"><i class="bi bi-clock-history"></i></div>
                <div class="ptitle">Request submitted</div>
                <div class="psub">
                    Your account is pending admin approval. You'll receive an email once it's activated — usually within 1 working day.
                </div>
                <div class="info-bar">
                    <i class="bi bi-info-circle"></i>
                    Your classes and student lists will be configured by your institution admin once approved.
                </div>
            </div>
        </div>

    </form>
</div>

<script>
var VALID_CODES = <?= $invite_json ?>;
var verifiedInst = '';

var LBLS = ['','Step 1 — Institution invite code','Step 2 — Your details','Step 3 — Verify your email','Step 4 — Set your password','Pending approval'];

function goTo(n){
    ['s1','s2','s3','s4','s5'].forEach(function(id,i){ document.getElementById(id).style.display=i+1===n?'block':'none'; });
    for(var i=0;i<4;i++) document.getElementById('sd'+i).className='sd'+(i<n?' done':'');
    document.getElementById('step-lbl').textContent=LBLS[n];
    window.scrollTo(0,0);
}

function verifyCode(){
    var code=document.getElementById('code_inp').value.trim().toUpperCase();
    var msg=document.getElementById('code-msg');
    var btn=document.getElementById('btn-s1');
    var nameWrap=document.getElementById('inst-name-wrap');
    if(!code){ msg.textContent=''; btn.disabled=true; nameWrap.style.display='none'; return; }
    if(VALID_CODES.indexOf(code)!==-1){
        msg.textContent='✓ Valid invite code — please enter your institution name below';
        msg.className='code-msg ok';
        document.getElementById('invite_hidden').value=code;
        nameWrap.style.display='block';
        setTimeout(function(){ document.getElementById('inst_name_inp').focus(); },100);
        // Keep button disabled until institution name is filled
        onInstNameInput();
    } else {
        msg.textContent='Invalid code. Ask your admin or try: UTM-2025';
        msg.className='code-msg err';
        btn.disabled=true;
        nameWrap.style.display='none';
    }
}

var INST_NAMES_L = ["Universiti Malaya (UM)","Universiti Putra Malaysia (UPM)","Universiti Kebangsaan Malaysia (UKM)","Universiti Teknologi Malaysia (UTM)","Universiti Sains Malaysia (USM)","Universiti Teknologi MARA (UiTM)","International Islamic University Malaysia (IIUM)","Universiti Utara Malaysia (UUM)","Universiti Teknikal Malaysia Melaka (UTeM)","Universiti Malaysia Sarawak (UNIMAS)","Universiti Malaysia Sabah (UMS)","Universiti Pendidikan Sultan Idris (UPSI)","Universiti Tun Hussein Onn Malaysia (UTHM)","Universiti Malaysia Perlis (UniMAP)","Universiti Malaysia Pahang (UMP)","Universiti Sultan Zainal Abidin (UniSZA)","Universiti Tun Abdul Razak (UNIRAZAK)","Universiti Pertahanan Nasional Malaysia (UPNM)","Universiti Kuala Lumpur (UniKL)","Taylor's University","Sunway University","Multimedia University (MMU)","UCSI University","Asia Pacific University (APU)","INTI International University","KDU University College","HELP University","MAHSA University","SEGi University","SEGi College Kota Damansara","SEGi College Kuala Lumpur","SEGi College Subang Jaya","SEGi College Penang","SEGi College Sarawak","SEGi University Online","New Era University College","Linton University College","Management & Science University (MSU)","Limkokwing University of Creative Technology","British American College (BAC)","Raffles University","Monash University Malaysia","University of Nottingham Malaysia","Curtin University Malaysia","Swinburne University of Technology Sarawak","Manipal International University","Xiamen University Malaysia","Perdana University","Albukhary International University","Wawasan Open University (WOU)","Open University Malaysia (OUM)","Universiti Widad Malaysia","Al-Madinah International University (MEDIU)","KL Infrastructure University College (KLIUC)","Nilai University","Tunku Abdul Rahman University of Management & Technology (TAR UMT)"].sort();

// Institution names use letters, spaces, and a small set of punctuation
// that legitimately appears in official names (apostrophes, ampersands,
// hyphens, periods, commas, parentheses, slashes) — never digits or
// other symbols.
var INST_NAME_ALLOWED = /[^A-Za-z\s'&.,()\/-]/g;

function renderInstSuggestions(){
    var inp = document.getElementById('inst_name_inp');
    var dd = document.getElementById('inst-ac-dd');
    var q = inp.value.trim().toLowerCase();
    if(!q){ dd.classList.remove('show'); dd.innerHTML=''; return; }
    var matches = INST_NAMES_L.filter(function(n){ return n.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
    if(matches.length === 0){
        dd.innerHTML = '<div class="inst-ac-empty">No matches — you can still type your institution\'s full name manually.</div>';
    } else {
        dd.innerHTML = matches.map(function(n){
            return '<div class="inst-ac-item" onmousedown="selectInstSuggestion(this)">' + n.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</div>';
        }).join('');
    }
    dd.classList.add('show');
}

function selectInstSuggestion(el){
    var inp = document.getElementById('inst_name_inp');
    inp.value = el.textContent;
    syncInstName();
    var dd = document.getElementById('inst-ac-dd');
    dd.classList.remove('show');
    dd.innerHTML = '';
}

document.addEventListener('click', function(e){
    if(!e.target.closest('.inst-ac-wrap')){
        var dd = document.getElementById('inst-ac-dd');
        if(dd) dd.classList.remove('show');
    }
});

function syncInstName(){
    var inp=document.getElementById('inst_name_inp');
    var cleaned = inp.value.replace(INST_NAME_ALLOWED, '');
    if(cleaned !== inp.value) inp.value = cleaned;
    var val=inp.value.trim();
    document.getElementById('institution_name_hidden').value=val;
    // Also update the inst-label preview for Step 2
    document.getElementById('inst-label').textContent=(val||'Your institution')+' — verified';
    document.getElementById('btn-s1').disabled=(val.length===0);
}

function onInstNameInput(){
    syncInstName();
    renderInstSuggestions();
}

var EDU_SUFFIXES_L=['edu.my','ac.my','edu.sg','ac.uk','edu.au','ac.nz'];
var EDU_BARE_L=['edu.my','ac.my','edu.sg'];
var EDU_EXACT_L=['segi4u.my','segi.edu.my','taylors.edu.my','sd.taylors.edu.my'];
function isEduEmailL(email){
    var at=email.indexOf('@');
    if(at<1) return false;
    var domain=email.slice(at+1).toLowerCase();
    if(!domain||domain.indexOf('.')<0) return false;
    // Accept bare edu.my / ac.my / edu.sg directly
    if(EDU_BARE_L.indexOf(domain)>=0) return true;
    if(EDU_EXACT_L.indexOf(domain)>=0) return true;
    for(var i=0;i<EDU_SUFFIXES_L.length;i++){
        var s=EDU_SUFFIXES_L[i];
        if(domain.slice(-(s.length+1))==='.'+s) return true;
    }
    if(domain==='edu'||domain.slice(-4)==='.edu') return true;
    return false;
}
var PERSONAL_L=['gmail.com','yahoo.com','hotmail.com','outlook.com','live.com','icloud.com','me.com','mail.com','proton.me','protonmail.com'];
function checkEmailHint(){
    var v=document.getElementById('email_inp').value.trim();
    var hint=document.getElementById('email-hint');
    if(!v){hint.textContent='';return;}
    var at=v.indexOf('@');
    var domain=at>=0?v.slice(at+1).toLowerCase():'';
    if(PERSONAL_L.indexOf(domain)>=0){
        hint.textContent='✗ Personal emails not accepted — use your institutional email';
        hint.style.color='#f76a6a';return;
    }
    if(at>=1&&isEduEmailL(v)){
        hint.textContent='✓ Looks like a valid institutional email';
        hint.style.color='var(--success)';
    } else if(at>=1&&domain.length>3){
        hint.textContent='✗ Must be an institutional email (e.g. @university.edu.my)';
        hint.style.color='#f76a6a';
    } else {
        hint.textContent='';
    }
}

function toStepVerify(){
    var email=document.getElementById('email_inp').value.trim();
    var errBox=document.getElementById('send-err');
    errBox.textContent='';
    if(!email || !isEduEmailL(email)){
        errBox.textContent='Please enter a valid institutional email above first.';
        return;
    }
    sendLecturerCode(email);
}

function sendLecturerCode(email){
    var realMode=document.getElementById('real-mode-toggle').checked;
    var errBox=document.getElementById('send-err');
    var btn=document.getElementById('btn-s2');
    var csrf=document.querySelector('#lecForm [name=csrf_token]').value;
    btn.disabled=true;
    var origHtml=btn.innerHTML;
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Sending…';

    fetch('lecturer_register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_action=send_code&email='+encodeURIComponent(email)+'&real_mode='+(realMode?'1':'')+'&csrf_token='+encodeURIComponent(csrf)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.innerHTML=origHtml;
        btn.disabled=false;
        if(!data.ok){ errBox.textContent=data.error||'Could not send code.'; return; }

        document.getElementById('email-show').textContent=email;
        var codeDisplay=document.getElementById('code-display');
        var codeHint=document.getElementById('code-hint');
        if(data.mode==='real'){
            codeDisplay.style.display='none';
            codeHint.textContent='Real Email Mode — check that inbox for the code.';
        } else {
            codeDisplay.style.display='block';
            codeDisplay.textContent=data.code.slice(0,3)+' '+data.code.slice(3);
            codeHint.textContent=data.smtp_failed
                ? '(real send failed — showing code as fallback)'
                : '(demo mode — code shown here, no email sent)';
        }
        document.getElementById('verify_code_inp').value='';
        checkLecCode();
        goTo(3);
    })
    .catch(function(){
        btn.innerHTML=origHtml;
        btn.disabled=false;
        errBox.textContent='Network error — please try again.';
    });
}

function checkLecCode(){
    var raw=document.getElementById('verify_code_inp').value.replace(/\s/g,'');
    document.getElementById('btn-verify').disabled=raw.length<6;
}

function doVerifyLecturer(){
    var btn=document.getElementById('btn-verify');
    var errBox=document.getElementById('verify-err');
    var csrf=document.querySelector('#lecForm [name=csrf_token]').value;
    var code=document.getElementById('verify_code_inp').value.trim();

    errBox.textContent='';
    btn.disabled=true;
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Verifying…';

    fetch('lecturer_register.php?tab=<?= htmlspecialchars($GLOBALS['tab_id']) ?>', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_action=verify_code&code='+encodeURIComponent(code)+'&csrf_token='+encodeURIComponent(csrf)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.innerHTML='<i class="bi bi-patch-check me-2"></i>Verify email';
        if(!data.ok){
            errBox.textContent=data.error||'Incorrect code.';
            btn.disabled=false;
            return;
        }
        goTo(4);
    })
    .catch(function(){
        btn.innerHTML='<i class="bi bi-patch-check me-2"></i>Verify email';
        btn.disabled=false;
        errBox.textContent='Network error — please try again.';
    });
}

function toggleEye(fid,iid){
    var f=document.getElementById(fid),i=document.getElementById(iid);
    f.type=f.type==='password'?'text':'password';
    i.className=f.type==='password'?'bi bi-eye':'bi bi-eye-slash';
}

function setReq(id,pass){
    var el=document.getElementById(id);
    el.className='ri '+(pass?'ok':'bad');
    el.querySelector('.rd').innerHTML=pass?'<i class="bi bi-check"></i>':'<i class="bi bi-x"></i>';
}
function resetReq(id){
    var el=document.getElementById(id);
    el.className='ri';
    el.querySelector('.rd').innerHTML='<i class="bi bi-dash"></i>';
}

function checkStrength(){
    var v=document.getElementById('pass_inp').value;
    var segs=['ss1','ss2','ss3','ss4'];
    segs.forEach(function(id){ document.getElementById(id).className='ss'; });
    var lbl=document.getElementById('str-lbl');
    if(!v){ ['req-len','req-upper','req-lower','req-num','req-spec'].forEach(resetReq); lbl.textContent=''; checkSubmit(); return; }
    var c={'req-len':v.length>=8,'req-upper':/[A-Z]/.test(v),'req-lower':/[a-z]/.test(v),'req-num':/[0-9]/.test(v),'req-spec':/[^A-Za-z0-9]/.test(v)};
    Object.keys(c).forEach(function(k){ setReq(k,c[k]); });
    var score=Object.values(c).filter(Boolean).length;
    var cfg=[null,{n:1,cls:'sw',txt:'Very weak',col:'#f76a6a'},{n:2,cls:'sf',txt:'Fair',col:'#f7c46a'},{n:3,cls:'sg',txt:'Good',col:'var(--success)'},{n:4,cls:'sg',txt:'Strong',col:'var(--success)'},{n:4,cls:'sstr',txt:'Very strong',col:'var(--teal)'}];
    var x=cfg[score];
    if(x){ for(var i=0;i<x.n;i++) document.getElementById(segs[i]).classList.add(x.cls); lbl.textContent=x.txt; lbl.style.color=x.col; }
    checkSubmit();
}

function checkSubmit(){
    var name=document.getElementById('name_inp').value.trim();
    var pass=document.getElementById('pass_inp').value;
    var conf=document.getElementById('conf_inp').value;
    var msg=document.getElementById('match-msg');
    if(conf){ if(pass===conf&&pass.length>=8){msg.textContent='Passwords match';msg.className='mm cok';}else{msg.textContent='Passwords do not match';msg.className='mm cbad';} }else{msg.textContent='';}
    var reqs=pass.length>=8&&/[A-Z]/.test(pass)&&/[a-z]/.test(pass)&&/[0-9]/.test(pass)&&/[^A-Za-z0-9]/.test(pass);
    document.getElementById('btn-submit').disabled=!(name&&reqs&&pass===conf);
}

function toggleTheme(){
    var next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
    localStorage.setItem('pt_student_theme',next);
    document.querySelectorAll('.theme-icon').forEach(function(i){ i.className='bi '+(next==='dark'?'bi-moon-stars-fill':'bi-sun-fill')+' theme-icon'; });
}
document.addEventListener('DOMContentLoaded',function(){
    var t=localStorage.getItem('pt_student_theme')||'dark';
    document.querySelectorAll('.theme-icon').forEach(function(i){ i.className='bi '+(t==='dark'?'bi-moon-stars-fill':'bi-sun-fill')+' theme-icon'; });
    <?php if ($error): ?>goTo(4);<?php endif; ?>
    if(window.location.search.indexOf('registered=lecturer')!==-1) goTo(5);
});
</script>
</body>
</html>
