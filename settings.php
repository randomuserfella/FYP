<?php 
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/csrf.php';
$pageTitle = 'Settings';
require_once 'config/db.php';
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/includes/notifier.php';

$user_id = (int)$_SESSION['user_id'];

// ── Ensure extra columns exist ───────────────────────────────────────────────
foreach ([
    "ADD COLUMN IF NOT EXISTS notification_email VARCHAR(255) DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) DEFAULT NULL",
] as $col) { try { $pdo->exec("ALTER TABLE users $col"); } catch (Exception $e) {} }

// ── Ensure avatar upload directory exists ────────────────────────────────────
$avatar_dir = __DIR__ . '/uploads/avatars/';
if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0755, true);

// ── Load user ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$msg = $err = '';

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── Upload avatar ────────────────────────────────────────────────────────
    if ($action === 'upload_avatar') {
        $file = $_FILES['avatar_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $err = 'Upload failed. Please try again.';
        } else {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed)) {
                $err = 'Only JPG, PNG, GIF or WEBP images are allowed.';
            } elseif (!@getimagesize($file['tmp_name'])) {
                $err = 'Uploaded file does not appear to be a valid image.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $err = 'Image must be under 2 MB.';
            } else {
                // Delete old avatar if exists
                if (!empty($user['avatar'])) {
                    $old = $avatar_dir . basename($user['avatar']);
                    if (file_exists($old)) unlink($old);
                }
                $ext      = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $avatar_dir . $filename)) {
                    $pdo->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$filename, $user_id]);
                    $msg = 'Profile picture updated.';
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                } else {
                    $err = 'Could not save image. Check folder permissions.';
                }
            }
        }
    }

    // ── Remove avatar ────────────────────────────────────────────────────────
    if ($action === 'remove_avatar') {
        if (!empty($user['avatar'])) {
            $old = $avatar_dir . basename($user['avatar']);
            if (file_exists($old)) unlink($old);
        }
        $pdo->prepare("UPDATE users SET avatar=NULL WHERE id=?")->execute([$user_id]);
        $msg = 'Profile picture removed.';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    }


    if ($action === 'save_account') {
        $name  = trim($_POST['name'] ?? '');
        $notif_email = trim($_POST['notification_email'] ?? '');

        if (!$name) {
            $err = 'Name cannot be empty.';
        } elseif ($notif_email && !filter_var($notif_email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Notification email is not a valid email address.';
        } else {
            $pdo->prepare("UPDATE users SET name=?, notification_email=? WHERE id=?")
                ->execute([$name, $notif_email ?: null, $user_id]);
            $_SESSION['user_name'] = $name;
            $msg = 'Account details saved.';
            // Reload
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }
    }

    // ── Save institution details ─────────────────────────────────────────────
    if ($action === 'save_institution') {
        $new_university = trim($_POST['university'] ?? '');
        $new_semester   = (int)($_POST['semester'] ?? 0);

        if (!$new_university) {
            $err = 'Institution name cannot be empty.';
        } elseif (!preg_match("/^[A-Za-z .,&'()\-]+$/", $new_university)) {
            $err = 'Institution name can only contain letters, spaces, and basic punctuation ( . , & \' - ( ) ) — no numbers or other special characters.';
        } elseif ($user['role'] === 'student' && ($new_semester < 1 || $new_semester > 12)) {
            $err = 'Please select a valid semester.';
        } else {
            if ($user['role'] === 'student') {
                $pdo->prepare("UPDATE users SET university=?, semester=? WHERE id=?")
                    ->execute([$new_university, $new_semester, $user_id]);
            } else {
                $pdo->prepare("UPDATE users SET university=? WHERE id=?")
                    ->execute([$new_university, $user_id]);
            }
            $msg = 'Institution details updated.';
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user     = $stmt->fetch();
            $university = htmlspecialchars($user['university'] ?? '—');
            $semester   = htmlspecialchars($user['semester'] ?? '');
        }
    }

    // ── Cancel email change ──────────────────────────────────────────────────
    if ($action === 'cancel_email_change') {
        unset($_SESSION['email_change_code'], $_SESSION['email_change_pending'], $_SESSION['email_change_expires'], $_SESSION['email_change_real_mode']);
        $msg = 'Email change cancelled.';
    }

    // ── Request email change (fake send verification code) ───────────────────
    if ($action === 'request_email_change') {
        $new_email  = trim($_POST['new_email'] ?? '');
        $new_email2 = trim($_POST['new_email2'] ?? '');
        $cur_pw     = $_POST['email_current_password'] ?? '';

        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } elseif ($new_email !== $new_email2) {
            $err = 'Email addresses do not match.';
        } elseif (strtolower($new_email) === strtolower($user['email'])) {
            $err = 'New email is the same as your current email.';
        } elseif (!password_verify($cur_pw, $user['password'])) {
            $err = 'Current password is incorrect.';
        } else {
            // Check email not already taken
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$new_email, $user_id]);
            if ($chk->fetch()) {
                $err = 'That email is already registered to another account.';
            } else {
                // Generate 6-digit verification code
                $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $real_mode = !empty($_POST['real_mode']);

                $_SESSION['email_change_code']      = $code;
                $_SESSION['email_change_pending']   = $new_email;
                $_SESSION['email_change_expires']   = time() + 300; // 5 min
                $_SESSION['email_change_real_mode'] = $real_mode;

                if ($real_mode) {
                    // Real mode: actually send the code via SMTP to the new address
                    $sent = sendEmail(
                        $pdo, $user_id, $new_email,
                        'Your ProcraTrack Verification Code',
                        "Your email verification code is: <b style='font-size:20px;letter-spacing:4px'>{$code}</b><br><br>This code expires in 5 minutes.",
                        'email_verify'
                    );
                    if ($sent) {
                        $msg = "✅ Verification code sent to <b>{$new_email}</b>. Check that inbox (and spam folder) for the code.";
                    } else {
                        // SMTP failed — fall back to showing the code so testing isn't blocked
                        $err = "⚠️ Could not send the real email (SMTP failed) — check <code>email_config.php</code> / server logs.
                                <br><span style='font-size:13px;color:var(--text-muted)'>
                                Fallback code: <b style='color:var(--accent);font-size:16px;letter-spacing:3px'>{$code}</b>
                                </span>";
                    }
                } else {
                    // Demo mode — show the code directly on the card, no real email sent
                    $msg = "✅ Verification code sent to <b>{$new_email}</b>. 
                            <br><span style='font-size:13px;color:var(--text-muted)'>
                            (Demo mode — your code is: <b style='color:var(--accent);font-size:16px;letter-spacing:3px'>{$code}</b>)
                            </span>";
                }
            }
        }
    }

    // ── Confirm email change with code ───────────────────────────────────────
    if ($action === 'verify_email_change') {
        $entered  = trim($_POST['verify_code'] ?? '');
        $stored   = $_SESSION['email_change_code']    ?? '';
        $pending  = $_SESSION['email_change_pending'] ?? '';
        $expires  = $_SESSION['email_change_expires'] ?? 0;

        if (!$stored || !$pending) {
            $err = 'No pending email change. Please request a new code.';
        } elseif (time() > $expires) {
            unset($_SESSION['email_change_code'], $_SESSION['email_change_pending'], $_SESSION['email_change_expires'], $_SESSION['email_change_real_mode']);
            $err = 'Verification code expired. Please try again.';
        } elseif ($entered !== $stored) {
            $err = 'Incorrect verification code.';
        } else {
            // Apply the email change
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")
                ->execute([$pending, $user_id]);
            unset($_SESSION['email_change_code'], $_SESSION['email_change_pending'], $_SESSION['email_change_expires'], $_SESSION['email_change_real_mode']);
            $msg = "✅ Email successfully changed to <b>{$pending}</b>.";
            // Reload user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $login_email_val = htmlspecialchars($user['email'] ?? '');
        }
    }

    // ── Change password ──────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            $err = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $err = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $err = 'New passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                ->execute([password_hash($new, PASSWORD_BCRYPT), $user_id]);
            $msg = 'Password changed successfully.';
        }
    }

    // ── Save reminder timing preferences ─────────────────────────────────────
    if ($action === 'save_remind_prefs') {
        $cols = ['remind_7day','remind_3day','remind_1day','remind_today',
                 'remind_60min','remind_30min','remind_ondue','remind_overdue'];
        $vals = array_map(fn($c) => isset($_POST[$c]) ? 1 : 0, $cols);
        $vals[] = $user_id;
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $pdo->prepare("UPDATE users SET $set WHERE id = ?")->execute($vals);
        $msg = 'Reminder preferences saved.';
        // Refresh user row so toggles reflect new state immediately
        $user = $pdo->prepare("SELECT * FROM users WHERE id = ?")->execute([$user_id])
            ? $pdo->prepare("SELECT * FROM users WHERE id = ?")->execute([$user_id]) && false
            : $user;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch() ?: $user;
    }
}

// ── Shorthand view vars ──────────────────────────────────────────────────────
$notif_email_val = htmlspecialchars($user['notification_email'] ?? '');
$login_email_val = htmlspecialchars($user['email'] ?? '');
$name_val        = htmlspecialchars($user['name'] ?? '');
$role            = ucfirst($user['role'] ?? 'student');
$university      = htmlspecialchars($user['university'] ?? '—');
$semester        = htmlspecialchars($user['semester'] ?? '');
$staff_id        = htmlspecialchars($user['staff_id'] ?? '');
$department      = htmlspecialchars($user['department'] ?? '');
$joined          = $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—';
$avatar_url      = !empty($user['avatar']) ? 'uploads/avatars/' . htmlspecialchars(basename($user['avatar'])) : '';

require_once 'includes/header.php';
?>
<style>
.settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 768px) { .settings-grid { grid-template-columns: 1fr; } }

.s-section { margin-bottom: 22px; }
.s-section:last-child { margin-bottom: 0; }
.s-divider {
    height: 1px; background: var(--border);
    margin: 20px 0;
}
.s-label {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .5px;
    display: block; margin-bottom: 6px;
}
.s-input {
    width: 100%; padding: 10px 13px;
    border-radius: 10px; border: 1px solid var(--border);
    background: var(--bg-input); color: var(--text-main);
    font-size: 14px; outline: none; transition: border-color .15s;
    box-sizing: border-box;
}
.s-input:focus { border-color: var(--accent); }
.s-input[readonly] {
    opacity: .55; cursor: not-allowed;
}
.s-hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

.s-btn {
    background: var(--accent); color: #fff; border: none;
    border-radius: 10px; padding: 10px 22px; font-size: 13px;
    font-weight: 600; cursor: pointer; display: inline-flex;
    align-items: center; gap: 7px; transition: opacity .15s;
}
.s-btn:hover { opacity: .85; }
.s-btn.secondary {
    background: var(--bg-input); color: var(--text-muted);
    border: 1px solid var(--border);
}
.s-btn.secondary:hover { border-color: var(--accent); color: var(--accent); }
.s-btn.danger { background: rgba(247,106,106,0.12); color: #f76a6a; border: 1px solid rgba(247,106,106,0.25); }
.s-btn.danger:hover { background: rgba(247,106,106,0.2); opacity: 1; }

.info-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 0; border-bottom: 1px solid var(--border);
    font-size: 13px;
}
.info-row:last-child { border-bottom: none; }
.info-key { color: var(--text-muted); }
.info-val { color: var(--text-main); font-weight: 500; }

.role-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
}
.role-student  { background: rgba(124,106,247,0.15); color: #7c6af7; }
.role-lecturer { background: rgba(106,247,184,0.15); color: #4caf82; }

.alert-ok  { background: rgba(76,175,130,0.1); border: 1px solid rgba(76,175,130,0.3); color: #4caf82; border-radius: 10px; padding: 11px 15px; font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.alert-err { background: rgba(247,106,106,0.1); border: 1px solid rgba(247,106,106,0.3); color: #f76a6a; border-radius: 10px; padding: 11px 15px; font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }

/* Password strength bar */
#pw-strength-bar { height: 4px; border-radius: 2px; background: var(--border); margin-top: 6px; overflow: hidden; }
#pw-strength-fill { height: 100%; width: 0; border-radius: 2px; transition: width .3s, background .3s; }
#pw-strength-text { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

/* Avatar */
.user-avatar-lg {
    width: 80px; height: 80px; border-radius: 50%;
    background: rgba(124,106,247,0.15); border: 2px solid rgba(124,106,247,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; flex-shrink: 0; overflow: hidden; position: relative;
}
.user-avatar-lg img {
    width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
}
.avatar-upload-btn {
    position: absolute; bottom: 0; right: 0;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--accent); border: 2px solid var(--bg-surface);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 12px; color: #fff;
    transition: opacity .15s;
}
.avatar-upload-btn:hover { opacity: .85; }
#avatar-file-input { display: none; }
</style>

<div class="page-topbar">
    <div class="page-title">⚙️ Settings</div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode">
        <i class="bi bi-moon-stars-fill theme-icon"></i>
    </button>
</div>
<div class="page-sub">Manage your account, notification email, and preferences</div>

<?php if ($msg): ?>
<div class="alert-ok"><i class="bi bi-check-circle-fill"></i> <?= $msg ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert-err"><i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="settings-grid">

    <!-- ── LEFT COLUMN ── -->
    <div>

        <!-- Account Details -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-person"></i> Account Details</div>

            <!-- Avatar + identity -->
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:14px;background:var(--bg-input);border-radius:12px;border:1px solid var(--border)">
                <!-- Avatar with upload overlay -->
                <div style="position:relative;flex-shrink:0">
                    <div class="user-avatar-lg" id="avatar-wrap">
                        <?php if ($avatar_url): ?>
                            <img src="<?= $avatar_url ?>?v=<?= time() ?>" alt="avatar" id="avatar-preview">
                        <?php else: ?>
                            <span id="avatar-placeholder">👤</span>
                        <?php endif; ?>
                    </div>
                    <label for="avatar-file-input" class="avatar-upload-btn" title="Change photo">
                        <i class="bi bi-camera-fill"></i>
                    </label>
                    <!-- Hidden upload form — submits on file pick -->
                    <form method="POST" enctype="multipart/form-data" id="avatar-form" style="display:none">
                        <input type="hidden" name="action" value="upload_avatar">
                        <?= csrf_field() ?>
                        <input type="file" name="avatar_file" id="avatar-file-input"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               onchange="document.getElementById('avatar-form').submit()">
                    </form>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:16px;font-weight:700;color:var(--text-main)"><?= $name_val ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= $login_email_val ?></div>
                    <span class="role-badge role-<?= $user['role'] ?>" style="margin-top:6px;display:inline-block"><?= $role ?></span>
                    <?php if ($avatar_url): ?>
                    <form method="POST" style="display:inline;margin-left:8px">
                        <input type="hidden" name="action" value="remove_avatar">
                        <?= csrf_field() ?>
                        <button type="submit" style="background:none;border:none;color:#f76a6a;font-size:11px;cursor:pointer;padding:0;text-decoration:underline;vertical-align:middle">Remove photo</button>
                    </form>
                    <?php endif; ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">JPG, PNG, GIF or WEBP · max 2 MB</div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_account">
                <?= csrf_field() ?>

                <div class="s-section">
                    <label class="s-label">Display Name</label>
                    <input type="text" name="name" class="s-input" value="<?= $name_val ?>" required placeholder="Your full name">
                </div>

                <div class="s-section">
                    <label class="s-label">Login Email <span style="color:var(--text-muted);text-transform:none;font-weight:400">(change below)</span></label>
                    <input type="email" class="s-input" value="<?= $login_email_val ?>" readonly>
                </div>

                <div class="s-section">
                    <label class="s-label">Notification Email <span style="color:var(--accent);text-transform:none;font-weight:500">(optional)</span></label>
                    <input type="email" name="notification_email" class="s-input"
                           value="<?= $notif_email_val ?>"
                           placeholder="e.g. personal@gmail.com">
                    <div class="s-hint">
                        <i class="bi bi-info-circle"></i>
                        If set, notifications go here instead of your login email. Leave blank to use login email.
                    </div>
                </div>

                <button type="submit" class="s-btn" style="width:100%;justify-content:center">
                    <i class="bi bi-save"></i> Save Account Details
                </button>
            </form>
        </div>

        <!-- Change Email -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-envelope-at"></i> Change Login Email</div>

            <?php
            $pending_email   = $_SESSION['email_change_pending'] ?? '';
            $code_expires    = $_SESSION['email_change_expires'] ?? 0;
            $code_still_live = $pending_email && time() < $code_expires;
            ?>

            <?php if (!$code_still_live): ?>
            <!-- Step 1: Request code -->
            <form method="POST">
                <input type="hidden" name="action" value="request_email_change">
                <?= csrf_field() ?>

                <div class="s-section">
                    <label class="s-label">Current Email</label>
                    <input type="email" class="s-input" value="<?= $login_email_val ?>" readonly>
                </div>

                <div class="s-section">
                    <label class="s-label">New Email Address</label>
                    <input type="email" name="new_email" class="s-input"
                           placeholder="e.g. newaddress@university.edu.my" required
                           value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>">
                </div>

                <div class="s-section">
                    <label class="s-label">Confirm New Email</label>
                    <input type="email" name="new_email2" class="s-input"
                           placeholder="Repeat new email" required>
                </div>

                <div class="s-section">
                    <label class="s-label">Current Password <span style="color:var(--text-muted);font-weight:400;text-transform:none">(to authorise change)</span></label>
                    <div style="position:relative">
                        <input type="password" name="email_current_password" id="email-cur-pw" class="s-input"
                               placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" onclick="togglePw('email-cur-pw','eye-email')"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px">
                            <i class="bi bi-eye" id="eye-email"></i>
                        </button>
                    </div>
                </div>

                <div class="s-section" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:var(--bg-hover,rgba(124,106,247,0.06));border:1px solid rgba(124,106,247,0.18);border-radius:10px;margin-bottom:14px">
                    <div>
                        <label class="s-label" style="margin-bottom:2px" for="real-mode-toggle">
                            <i class="bi bi-envelope-paper"></i> Real Email Mode
                        </label>
                        <div style="font-size:12px;color:var(--text-muted);text-transform:none;font-weight:400">
                            Off = Demo (code shown on this card). On = actually emails the code.
                        </div>
                    </div>
                    <label class="form-switch" style="position:relative;display:inline-block;width:44px;height:24px;flex:none">
                        <input type="checkbox" name="real_mode" id="real-mode-toggle" value="1"
                               style="opacity:0;width:0;height:0"
                               onchange="document.getElementById('rmt-track').style.background=this.checked?'var(--accent)':'#ccc'; document.getElementById('rmt-knob').style.transform=this.checked?'translateX(20px)':'translateX(0)';">
                        <span id="rmt-track" style="position:absolute;inset:0;background:#ccc;border-radius:24px;transition:.2s;pointer-events:none">
                            <span id="rmt-knob" style="position:absolute;left:2px;top:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></span>
                        </span>
                    </label>
                </div>

                <div class="s-hint" style="margin-bottom:14px">
                    <i class="bi bi-info-circle"></i>
                    A 6-digit verification code will be sent to your new email address.
                </div>

                <button type="submit" class="s-btn" style="width:100%;justify-content:center">
                    <i class="bi bi-send"></i> Send Verification Code
                </button>
            </form>

            <?php else: ?>
            <!-- Step 2: Enter code -->
            <div style="padding:12px;background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.2);border-radius:10px;margin-bottom:16px;font-size:13px;color:var(--text-main)">
                <i class="bi bi-envelope-check" style="color:var(--accent)"></i>
                Code sent to <b><?= htmlspecialchars($pending_email) ?></b>.
                Expires in <b id="code-countdown"></b>.
                <?php if (!empty($_SESSION['email_change_real_mode'])): ?>
                    <br><span style="font-size:12px;color:var(--text-muted)"><i class="bi bi-envelope-paper"></i> Real Email Mode — check the inbox.</span>
                <?php else: ?>
                    <br><span style="font-size:12px;color:var(--text-muted)"><i class="bi bi-eye"></i> Demo Mode — code was shown on screen, no email sent.</span>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="verify_email_change">
                <?= csrf_field() ?>

                <div class="s-section">
                    <label class="s-label">Enter 6-Digit Code</label>
                    <input type="text" name="verify_code" class="s-input"
                           placeholder="e.g. 483920" maxlength="6"
                           style="letter-spacing:6px;font-size:20px;font-weight:700;text-align:center"
                           autofocus required>
                </div>
                <button type="submit" class="s-btn" style="width:100%;justify-content:center;margin-bottom:10px">
                    <i class="bi bi-check-circle"></i> Confirm Email Change
                </button>
            </form>

            <!-- Cancel -->
            <form method="POST">
                <input type="hidden" name="action" value="cancel_email_change">
                <?= csrf_field() ?>

                <button type="button" onclick="cancelEmailChange()" class="s-btn secondary" style="width:100%;justify-content:center">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </form>

            <script>
            // Countdown timer
            (function(){
                var expires = <?= (int)$code_expires ?>;
                function tick(){
                    var left = expires - Math.floor(Date.now()/1000);
                    var el = document.getElementById('code-countdown');
                    if (!el) return;
                    if (left <= 0) { el.textContent = 'expired'; return; }
                    var m = Math.floor(left/60), s = left%60;
                    el.textContent = m + ':' + (s<10?'0':'') + s;
                    setTimeout(tick, 1000);
                }
                tick();
            })();
            function cancelEmailChange(){
                // POST a cancel action
                var f = document.createElement('form');
                f.method = 'POST';
                var i = document.createElement('input');
                i.type='hidden'; i.name='action'; i.value='cancel_email_change';
                f.appendChild(i); document.body.appendChild(f); f.submit();
            }
            </script>
            <?php endif; ?>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-lock"></i> Change Password</div>
            <form method="POST" id="pw-form">
                <input type="hidden" name="action" value="change_password">
                <?= csrf_field() ?>


                <div class="s-section">
                    <label class="s-label">Current Password</label>
                    <div style="position:relative">
                        <input type="password" name="current_password" id="cur-pw" class="s-input" placeholder="Enter current password" autocomplete="current-password">
                        <button type="button" onclick="togglePw('cur-pw','eye-cur')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px">
                            <i class="bi bi-eye" id="eye-cur"></i>
                        </button>
                    </div>
                </div>

                <div class="s-section">
                    <label class="s-label">New Password</label>
                    <div style="position:relative">
                        <input type="password" name="new_password" id="new-pw" class="s-input" placeholder="At least 8 characters" oninput="checkStrength(this.value)" autocomplete="new-password">
                        <button type="button" onclick="togglePw('new-pw','eye-new')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px">
                            <i class="bi bi-eye" id="eye-new"></i>
                        </button>
                    </div>
                    <div id="pw-strength-bar"><div id="pw-strength-fill"></div></div>
                    <div id="pw-strength-text"></div>
                </div>

                <div class="s-section">
                    <label class="s-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="conf-pw" class="s-input" placeholder="Repeat new password" autocomplete="new-password">
                </div>

                <button type="submit" class="s-btn" style="width:100%;justify-content:center">
                    <i class="bi bi-shield-lock"></i> Change Password
                </button>
            </form>
        </div>

    </div>

    <!-- ── RIGHT COLUMN ── -->
    <div>

        <!-- Profile Info (read-only) -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-id-card"></i> Profile Info</div>
            <div class="info-row">
                <span class="info-key">Role</span>
                <span class="info-val"><span class="role-badge role-<?= $user['role'] ?>"><?= $role ?></span></span>
            </div>
            <div class="info-row">
                <span class="info-key">University</span>
                <span class="info-val"><?= htmlspecialchars($user['university'] ?? '—') ?></span>
            </div>
            <?php if ($user['role'] === 'student' && !empty($user['semester'])): ?>
            <div class="info-row">
                <span class="info-key">Semester</span>
                <span class="info-val">Semester <?= htmlspecialchars($user['semester']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($user['role'] === 'lecturer'): ?>
            <?php if ($staff_id): ?>
            <div class="info-row">
                <span class="info-key">Staff ID</span>
                <span class="info-val"><?= $staff_id ?></span>
            </div>
            <?php endif; ?>
            <?php if ($department): ?>
            <div class="info-row">
                <span class="info-key">Department</span>
                <span class="info-val"><?= $department ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-key">Account status</span>
                <span class="info-val" style="color:<?= $user['status'] === 'active' ? '#4caf82' : '#f7c46a' ?>">
                    <?= ucfirst($user['status'] ?? 'active') ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-key">Member since</span>
                <span class="info-val"><?= $joined ?></span>
            </div>
            <div style="margin-top:12px;font-size:11px;color:var(--text-muted)">
                <i class="bi bi-info-circle"></i> Role cannot be changed here. Email can be changed under <b>Change Login Email</b>. Institution details can be updated below.
            </div>
        </div>

        <!-- Institution Details -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-building"></i> Institution Details</div>
            <form method="POST">
                <input type="hidden" name="action" value="save_institution">
                <?= csrf_field() ?>


                <div class="s-section">
                    <label class="s-label">University / College Name</label>
                    <div id="uniInputWrap" style="position:relative">
                        <input type="text" name="university" id="universityInput" class="s-input"
                               value="<?= $university !== '—' ? $university : '' ?>"
                               placeholder="e.g. SEGi College Subang Jaya"
                               autocomplete="off"
                               oninput="sanitizeUniInput(this); filterUniSuggestions()"
                               onkeydown="handleUniSearchKeydown(event)"
                               required>
                        <div id="uniSuggestions"
                             style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);
                                    background:var(--bg-surface);border:1px solid var(--border);
                                    border-radius:10px;z-index:200;max-height:180px;overflow-y:auto;
                                    box-shadow:0 4px 16px rgba(0,0,0,0.15)"></div>
                    </div>
                    <div class="s-hint" style="margin-top:6px">
                        <i class="bi bi-info-circle"></i>
                        Start typing to see suggestions, or enter your institution's name directly. Letters, spaces and basic punctuation only — no numbers or special characters.
                    </div>
                </div>

                <?php if ($user['role'] === 'student'): ?>
                <div class="s-section">
                    <label class="s-label">Current Semester</label>
                    <select name="semester" class="s-input" required>
                        <option value="">— Select semester —</option>
                        <?php for ($s = 1; $s <= 12; $s++): ?>
                        <option value="<?= $s ?>" <?= (int)($user['semester'] ?? 0) === $s ? 'selected' : '' ?>>
                            Semester <?= $s ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="s-hint" style="margin-bottom:14px">
                    <i class="bi bi-info-circle"></i>
                    <?= $user['role'] === 'student'
                        ? 'Update if you transferred institutions or progressed to a new semester.'
                        : 'Update if you have moved to a different institution.' ?>
                </div>

                <button type="submit" class="s-btn" style="width:100%;justify-content:center">
                    <i class="bi bi-building-check"></i> Save Institution Details
                </button>
            </form>
        </div>

        <!-- Appearance -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-palette"></i> Appearance</div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--bg-input);border-radius:10px;border:1px solid var(--border)">
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--text-main)">Theme</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Dark or light mode</div>
                </div>
                <button onclick="toggleTheme()" class="s-btn secondary" id="theme-btn" style="gap:6px;padding:8px 16px">
                    <i class="bi bi-moon-stars-fill theme-icon"></i>
                    <span id="theme-label">Dark</span>
                </button>
            </div>

            <div style="padding:12px;background:var(--bg-input);border-radius:10px;border:1px solid var(--border);margin-top:10px">
                <div style="font-size:13px;font-weight:600;color:var(--text-main)">Background</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;margin-bottom:12px">Choose a background style</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:12px">
                    <div class="bg-preset-option" data-bg="default" onclick="setBgPreset('default')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:var(--bg-base)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Default</div>
                    </div>
                    <div class="bg-preset-option" data-bg="purple" onclick="setBgPreset('purple')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:linear-gradient(160deg,#1a1a22,#2e2150)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Purple</div>
                    </div>
                    <div class="bg-preset-option" data-bg="ocean" onclick="setBgPreset('ocean')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:linear-gradient(160deg,#0f1f2c,#1a4a63)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Ocean</div>
                    </div>
                    <div class="bg-preset-option" data-bg="sunset" onclick="setBgPreset('sunset')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:linear-gradient(160deg,#2e1c20,#4a2a18)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Sunset</div>
                    </div>
                    <div class="bg-preset-option" data-bg="forest" onclick="setBgPreset('forest')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:linear-gradient(160deg,#142018,#1c3a2a)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Forest</div>
                    </div>
                    <div class="bg-preset-option" data-bg="rose" onclick="setBgPreset('rose')" style="cursor:pointer;text-align:center">
                        <div style="position:relative;width:100%;height:48px;border-radius:10px;border:2px solid var(--border);background:linear-gradient(160deg,#2a1820,#4a2038)">
                            <div class="bg-check"><i class="bi bi-check-lg"></i></div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Rose</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reminder Timing Preferences -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-alarm"></i> Reminder Timing</div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
                Choose which reminders you want to receive. Changes apply to all future notifications.
            </p>
            <form method="POST" id="remind-prefs-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_remind_prefs">

                <?php
                $remindPrefs = [
                    'remind_7day'    => ['label' => '7 days before deadline',        'icon' => 'bi-calendar-week'],
                    'remind_3day'    => ['label' => '3 days before deadline',        'icon' => 'bi-calendar3'],
                    'remind_1day'    => ['label' => '1 day before deadline',         'icon' => 'bi-calendar-day'],
                    'remind_today'   => ['label' => 'On the day (no specific time)', 'icon' => 'bi-calendar-check'],
                    'remind_60min'   => ['label' => '60 minutes before end time',    'icon' => 'bi-clock-history'],
                    'remind_30min'   => ['label' => '30 minutes before end time',    'icon' => 'bi-clock'],
                    'remind_ondue'   => ['label' => 'At deadline (end time reached)','icon' => 'bi-bell-fill'],
                    'remind_overdue' => ['label' => 'When task becomes overdue',     'icon' => 'bi-exclamation-circle'],
                ];
                foreach ($remindPrefs as $col => $meta):
                    $checked = !isset($user[$col]) || $user[$col] ? 'checked' : '';
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:10px">
                        <i class="bi <?= $meta['icon'] ?>" style="color:var(--accent);font-size:16px;width:18px;text-align:center"></i>
                        <span style="font-size:13px;color:var(--text-main)"><?= $meta['label'] ?></span>
                        <span class="remind-saved-tick" data-for="<?= $col ?>" style="display:none;color:#2e7d32;font-size:12px"><i class="bi bi-check-lg"></i></span>
                    </div>
                    <label style="position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0">
                        <input type="checkbox" name="<?= $col ?>" value="1" <?= $checked ?>
                            style="opacity:0;width:0;height:0;position:absolute"
                            onchange="saveReminderPref(this, '<?= $col ?>')">
                        <span style="
                            position:absolute;inset:0;border-radius:22px;cursor:pointer;transition:.2s;
                            background:<?= $checked ? 'var(--accent)' : 'var(--border)' ?>;
                        " class="remind-toggle-track-<?= $col ?>">
                            <span style="
                                position:absolute;height:16px;width:16px;left:<?= $checked ? '20px' : '3px' ?>;
                                bottom:3px;border-radius:50%;background:#fff;transition:.2s;
                            "></span>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>

                <div id="remind-save-status" style="font-size:12px;color:#2e7d32;margin-top:10px;min-height:16px"></div>
            </form>
            <script>
            function saveReminderPref(checkbox, col) {
                var track = checkbox.parentElement.querySelector('.remind-toggle-track-' + col);
                var knob  = track.querySelector('span');
                var on    = checkbox.checked;

                // Instant visual feedback — no waiting on the network
                track.style.background = on ? 'var(--accent)' : 'var(--border)';
                knob.style.left = on ? '20px' : '3px';

                var statusEl = document.getElementById('remind-save-status');
                statusEl.textContent = 'Saving…';

                fetch('save_remind_pref.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: document.querySelector('#remind-prefs-form input[name="csrf_token"]').value,
                        col: col,
                        value: on ? 1 : 0
                    })
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.ok) {
                        statusEl.textContent = 'Saved ✓';
                    } else {
                        statusEl.textContent = 'Could not save — try again.';
                        // revert the toggle visually since the save failed
                        checkbox.checked = !on;
                        track.style.background = !on ? 'var(--accent)' : 'var(--border)';
                        knob.style.left = !on ? '20px' : '3px';
                    }
                    setTimeout(function(){ statusEl.textContent = ''; }, 2000);
                })
                .catch(function(){
                    statusEl.textContent = 'Could not save — check your connection.';
                });
            }
            </script>
        </div>

        <!-- Notification quick-links -->
        <div class="card">
            <div class="card-title-sm"><i class="bi bi-bell"></i> Notifications</div>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
                Connect Telegram and manage which alerts you receive on the Notifications page.
            </p>

            <!-- Current notification email at a glance -->
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-input);border-radius:10px;border:1px solid var(--border);margin-bottom:10px">
                <i class="bi bi-envelope" style="color:var(--accent);font-size:18px;flex-shrink:0"></i>
                <div style="min-width:0">
                    <div style="font-size:12px;color:var(--text-muted)">Email alerts go to</div>
                    <div style="font-size:13px;font-weight:600;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= $notif_email_val ?: $login_email_val ?>
                    </div>
                    <?php if ($notif_email_val): ?>
                    <div style="font-size:11px;color:#4caf82;margin-top:2px"><i class="bi bi-check-circle"></i> Custom notification email set</div>
                    <?php else: ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Using login email (set one above to override)</div>
                    <?php endif; ?>
                </div>
            </div>

            <a href="notifications.php" class="s-btn secondary" style="width:100%;justify-content:center;text-decoration:none">
                <i class="bi bi-gear"></i> Manage Notification Settings
            </a>
        </div>

        <!-- Danger zone -->
        <div class="card" style="border-color:rgba(247,106,106,0.2)">
            <div class="card-title-sm" style="color:#f76a6a"><i class="bi bi-exclamation-triangle"></i> Account Actions</div>
            <a href="logout.php" class="s-btn danger" style="width:100%;justify-content:center;text-decoration:none">
                <i class="bi bi-box-arrow-left"></i> Log Out
            </a>
        </div>

    </div>
</div>

<script>
// ── Theme label sync ─────────────────────────────────────────────────────────
function syncThemeLabel() {
    var t = document.documentElement.getAttribute('data-theme') || 'dark';
    var lbl = document.getElementById('theme-label');
    if (lbl) lbl.textContent = t === 'dark' ? 'Dark' : 'Light';
}
document.addEventListener('DOMContentLoaded', syncThemeLabel);
// Wrap existing toggleTheme to also update label
var _origToggle = window.toggleTheme;
window.toggleTheme = function() { _origToggle && _origToggle(); setTimeout(syncThemeLabel, 50); };

// ── Background preset sync ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
    var b = localStorage.getItem('pt_student_bg') || 'default';
    document.querySelectorAll('.bg-preset-option').forEach(function(el){
        el.classList.toggle('active', el.dataset.bg === b);
    });
});

// ── Show/hide password ───────────────────────────────────────────────────────
function togglePw(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp) return;
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Password strength ────────────────────────────────────────────────────────
function checkStrength(pw) {
    var bar  = document.getElementById('pw-strength-fill');
    var text = document.getElementById('pw-strength-text');
    if (!bar || !text) return;

    var score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    var labels = ['', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
    var colors = ['', '#f76a6a', '#f7c46a', '#f7c46a', '#6af7b8', '#4caf82'];
    var pct    = score ? (score / 5) * 100 : 0;

    bar.style.width      = pct + '%';
    bar.style.background = colors[score] || colors[1];
    text.textContent     = pw.length ? labels[score] || 'Very strong' : '';
    text.style.color     = colors[score] || colors[1];
}

// ── Institution name: character restriction + typo-tolerant suggestions ──────
var _allInstitutions = [
    'SEGi University', 'SEGi College Kota Damansara', 'SEGi College Kuala Lumpur',
    'SEGi College Subang Jaya', 'SEGi College Penang', 'SEGi College Sarawak', 'SEGi University Online',
    'New Era University College', 'Linton University College', 'Management & Science University (MSU)',
    'Limkokwing University of Creative Technology', 'British American College (BAC)', 'Raffles University',
    'Monash University Malaysia', 'University of Nottingham Malaysia', 'Curtin University Malaysia',
    'Swinburne University of Technology Sarawak', 'Manipal International University', 'Xiamen University Malaysia',
    'Perdana University', 'Albukhary International University', 'Wawasan Open University (WOU)',
    'Open University Malaysia (OUM)', 'Universiti Widad Malaysia', 'Al-Madinah International University (MEDIU)',
    'KL Infrastructure University College (KLIUC)', 'Nilai University',
    'Tunku Abdul Rahman University of Management & Technology (TAR UMT)'
];
var _uniMatches = [];
// Letters, spaces, and basic punctuation used in institution names — no digits or other symbols.
var UNI_ALLOWED_RE = /[^A-Za-z .,&'()\-]/g;

function sanitizeUniInput(input) {
    var cleaned = input.value.replace(UNI_ALLOWED_RE, '');
    if (cleaned !== input.value) input.value = cleaned;
}

function _levenshtein(a, b) {
    if (a === b) return 0;
    var al = a.length, bl = b.length;
    if (!al) return bl;
    if (!bl) return al;
    var prev = []; for (var i = 0; i <= bl; i++) prev[i] = i;
    for (var x = 1; x <= al; x++) {
        var cur = [x];
        for (var y = 1; y <= bl; y++) {
            var cost = a[x-1] === b[y-1] ? 0 : 1;
            cur[y] = Math.min(prev[y] + 1, cur[y-1] + 1, prev[y-1] + cost);
        }
        prev = cur;
    }
    return prev[bl];
}
function _normalizeInst(s) {
    return s.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
}
function _instMatchScore(query, inst) {
    var qNorm = _normalizeInst(query);
    var iNorm = _normalizeInst(inst);
    if (!qNorm) return 0;
    if (iNorm.indexOf(qNorm) !== -1) return 100;
    var qWords = qNorm.split(' ');
    var iWords = iNorm.split(' ');
    var matched = 0;
    for (var i = 0; i < qWords.length; i++) {
        var qw = qWords[i], hit = false;
        for (var j = 0; j < iWords.length; j++) {
            var iw = iWords[j];
            if (iw.indexOf(qw) !== -1 || qw.indexOf(iw) !== -1) { hit = true; break; }
            var tolerance = Math.max(1, Math.floor(iw.length * 0.34));
            if (_levenshtein(qw, iw) <= tolerance) { hit = true; break; }
        }
        if (hit) matched++;
    }
    if (matched === 0) return 0;
    return Math.round((matched / qWords.length) * 90);
}

function filterUniSuggestions() {
    var q   = document.getElementById('universityInput').value.trim();
    var box = document.getElementById('uniSuggestions');
    if (!q) { box.style.display = 'none'; _uniMatches = []; return; }

    var scored = _allInstitutions
        .map(function(inst) { return { inst: inst, score: _instMatchScore(q, inst) }; })
        .filter(function(m) { return m.score > 0; })
        .sort(function(a, b) { return b.score - a.score; });
    _uniMatches = scored.map(function(m) { return m.inst; });

    if (!scored.length) { box.style.display = 'none'; return; }

    box.innerHTML = '';
    scored.slice(0, 8).forEach(function(m) {
        var inst = m.inst;
        var row = document.createElement('div');
        row.style.cssText = 'padding:9px 12px;font-size:13px;color:var(--text-main);cursor:pointer;border-bottom:1px solid var(--border);transition:background 0.1s';
        row.textContent = inst;
        row.onmouseover = function() { row.style.background = 'rgba(124,106,247,0.08)'; };
        row.onmouseout  = function() { row.style.background = ''; };
        row.onclick     = function() { pickUniSuggestion(inst); };
        box.appendChild(row);
    });
    if (box.lastChild) box.lastChild.style.borderBottom = 'none';
    box.style.display = 'block';
}

function pickUniSuggestion(inst) {
    document.getElementById('universityInput').value = inst;
    document.getElementById('uniSuggestions').style.display = 'none';
    _uniMatches = [];
}

// Pressing Enter jumps to the best-ranked suggestion (if any); otherwise the
// field simply keeps whatever custom name was typed.
function handleUniSearchKeydown(e) {
    if (e.key === 'Enter' && _uniMatches.length) {
        e.preventDefault();
        pickUniSuggestion(_uniMatches[0]);
    }
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('uniInputWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('uniSuggestions').style.display = 'none';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
