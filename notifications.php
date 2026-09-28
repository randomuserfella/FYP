<?php

require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Notifications';
require_once 'config/db.php';
require_once 'email_config.php';
require_once 'includes/notifier.php';
require_once __DIR__ . '/config/csrf.php';

$user_id = (int)$_SESSION['user_id'];
$__me = $pdo->prepare("SELECT name FROM users WHERE id=?");
$__me->execute([$user_id]);
$my_name = $__me->fetchColumn() ?: 'A student';

// ── Ensure access_requests table exists (cross-institution view requests) ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lecturer_id INT NOT NULL,
        student_id INT NOT NULL,
        status ENUM('pending','approved','denied','revoked') NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME DEFAULT NULL,
        INDEX idx_lecturer (lecturer_id),
        INDEX idx_student (student_id)
    )");
} catch (Exception $e) {}

// ── Handle access request decisions (approve / deny / revoke) ──────────────
$access_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_action'])) {
    csrf_verify();
    $req_id = (int)($_POST['request_id'] ?? 0);
    $act    = $_POST['access_action'];

    $req = $pdo->prepare("SELECT ar.*, u.name AS lecturer_name, u.university AS lecturer_university
                           FROM access_requests ar JOIN users u ON u.id = ar.lecturer_id
                           WHERE ar.id=? AND ar.student_id=?");
    $req->execute([$req_id, $user_id]);
    $req = $req->fetch();

    if ($req) {
        if ($act === 'approve' && $req['status'] === 'pending') {
            $pdo->prepare("UPDATE access_requests SET status='approved', responded_at=NOW() WHERE id=?")->execute([$req_id]);
            try {
                sendNotification($pdo, $req['lecturer_id'],
                    "✅ {$my_name} approved your access request. You can now view their tasks and habits under Students.",
                    "ProcraTrack — Access Request Approved");
            } catch (Exception $e) {}
            $access_msg = 'Access approved. ' . htmlspecialchars($req['lecturer_name']) . ' can now view your tasks and habits.';
        } elseif ($act === 'deny' && $req['status'] === 'pending') {
            $pdo->prepare("UPDATE access_requests SET status='denied', responded_at=NOW() WHERE id=?")->execute([$req_id]);
            try {
                sendNotification($pdo, $req['lecturer_id'],
                    "❌ {$my_name} denied your access request.",
                    "ProcraTrack — Access Request Denied");
            } catch (Exception $e) {}
            $access_msg = 'Request denied.';
        } elseif ($act === 'revoke' && $req['status'] === 'approved') {
            $pdo->prepare("UPDATE access_requests SET status='revoked', responded_at=NOW() WHERE id=?")->execute([$req_id]);
            try {
                sendNotification($pdo, $req['lecturer_id'],
                    "🔒 {$my_name} has revoked your access to their tasks and habits.",
                    "ProcraTrack — Access Revoked");
            } catch (Exception $e) {}
            $access_msg = 'Access revoked.';
        }
    }

    $tab = $_POST['tab'] ?? $_GET['tab'] ?? '';
    header('Location: notifications.php?tab=' . urlencode($tab) . '&access_msg=' . urlencode($access_msg)); exit;
}
if (isset($_GET['access_msg'])) { $access_msg = $_GET['access_msg']; }

// ── Load access requests (pending + previously approved, for management) ──
$pending_requests = $approved_requests = [];
try {
    $pr = $pdo->prepare("SELECT ar.id, ar.requested_at, u.name, u.email, u.university
                          FROM access_requests ar JOIN users u ON u.id = ar.lecturer_id
                          WHERE ar.student_id=? AND ar.status='pending' ORDER BY ar.requested_at DESC");
    $pr->execute([$user_id]); $pending_requests = $pr->fetchAll();

    $ap = $pdo->prepare("SELECT ar.id, ar.responded_at, u.name, u.email, u.university
                          FROM access_requests ar JOIN users u ON u.id = ar.lecturer_id
                          WHERE ar.student_id=? AND ar.status='approved' ORDER BY ar.responded_at DESC");
    $ap->execute([$user_id]); $approved_requests = $ap->fetchAll();
} catch (Exception $e) {}

// ── Save settings ─────────────────────────────────────────────────────────────
$save_msg = $save_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $tg_chat_id = preg_replace('/[^0-9\-]/', '', $_POST['telegram_chat_id'] ?? '');
    $notify_tg  = isset($_POST['notify_telegram']) ? 1 : 0;
    $notify_em  = isset($_POST['notify_email'])    ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE users SET telegram_chat_id=?, notify_telegram=?, notify_email=? WHERE id=?");
    $stmt->execute([$tg_chat_id ?: null, $notify_tg, $notify_em, $user_id]);

    $tab = $_POST['tab'] ?? $_GET['tab'] ?? '';

    // If a test button was clicked, redirect to the test handler after saving
    if (!empty($_POST['test_tg'])) {
        header('Location: notifications.php?tab=' . urlencode($tab) . '&test_tg=1'); exit;
    }
    if (!empty($_POST['test_email'])) {
        header('Location: notifications.php?tab=' . urlencode($tab) . '&test_email=1'); exit;
    }

    $save_msg = 'Settings saved successfully!';
}

// ── Test Telegram ─────────────────────────────────────────────────────────────
if (isset($_GET['test_tg'])) {
    $u = $pdo->prepare("SELECT telegram_chat_id, notify_telegram FROM users WHERE id=?");
    $u->execute([$user_id]); $usr = $u->fetch();

    $ok = false;
    if (!empty($usr['telegram_chat_id'])) {
        $ok = sendTelegram($pdo, $user_id,
            "🎯 <b>ProcraTrack</b>\n\nTelegram notifications are working! ✅",
            'test');
    }
    $tab = $_GET['tab'] ?? '';
    header('Location: notifications.php?tab=' . urlencode($tab) . '&tg_test=' . ($ok ? 'ok' : 'fail')); exit;
}

// ── Test Email ────────────────────────────────────────────────────────────────
if (isset($_GET['test_email'])) {
    $u = $pdo->prepare("SELECT email, notification_email FROM users WHERE id=?");
    $u->execute([$user_id]); $usr = $u->fetch();
    // Use notification_email if set, else login email
    $send_to = !empty($usr['notification_email']) ? $usr['notification_email'] : $usr['email'];
    $ok = false;
    if (!empty($send_to)) {
        $ok = sendEmail($pdo, $user_id, $send_to,
            '🎯 ProcraTrack — Email test',
            '<p>Your email notifications are working correctly! 🎉</p>
             <p>You will receive task reminders, focus session summaries and habit nudges here.</p>',
            'test');
    }
    $tab = $_GET['tab'] ?? '';
    header('Location: notifications.php?tab=' . urlencode($tab) . '&em_test=' . ($ok ? 'ok' : 'fail')); exit;
}

// ── Load user settings ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT telegram_chat_id, notify_telegram, notify_email, email, notification_email,
    remind_7day, remind_3day, remind_1day, remind_today,
    remind_60min, remind_30min, remind_ondue, remind_overdue
    FROM users WHERE id=?");
$stmt->execute([$user_id]); $us = $stmt->fetch();

// ── Load next pending task (for countdown + next-fire calc) ───────────────────
$nextTask = null;
try {
    // A task with no due_time is treated as due at 23:59 (end of day) when the
    // countdown/deadline is actually computed further down. The ORDER BY must
    // use the same fallback, otherwise MySQL sorts NULL due_time as the
    // *earliest* possible value (ASC), so a same-day task with no time set
    // gets picked as "next" over a task with an explicit near-term time.
    $nt = $pdo->prepare("SELECT task_name, due_date, due_time FROM tasks
        WHERE user_id=? AND status='pending' AND due_date >= CURDATE()
        ORDER BY due_date ASC, COALESCE(due_time, '23:59:59') ASC LIMIT 1");
    $nt->execute([$user_id]);
    $nextTask = $nt->fetch();
} catch(Exception $e){}

// ── Countdown + per-tier next fire times ──────────────────────────────────────
$countdownSeconds = null;
$nextFireTimes    = [];
if ($nextTask) {
    $dueStr  = $nextTask['due_date'] . ' ' . ($nextTask['due_time'] ?: '23:59');
    $dueTs   = strtotime($dueStr);
    $nowTs   = time();
    $countdownSeconds = max(0, $dueTs - $nowTs);
    $offsets = [
        'remind_7day'    => ['offset' => 7*86400,  'type' => 'before'],
        'remind_3day'    => ['offset' => 3*86400,  'type' => 'before'],
        'remind_1day'    => ['offset' => 1*86400,  'type' => 'before'],
        'remind_today'   => ['offset' => 0,         'type' => 'morning'],
        'remind_60min'   => ['offset' => 3600,      'type' => 'before'],
        'remind_30min'   => ['offset' => 1800,      'type' => 'before'],
        'remind_ondue'   => ['offset' => 0,         'type' => 'at'],
        'remind_overdue' => ['offset' => 600,       'type' => 'after'],
    ];
    foreach ($offsets as $col => $cfg) {
        if ($cfg['type'] === 'morning') {
            $fireTs = strtotime($nextTask['due_date'] . ' 08:00');
        } elseif ($cfg['type'] === 'after') {
            $fireTs = $dueTs + $cfg['offset'];
        } else {
            $fireTs = $dueTs - $cfg['offset'];
        }
        $diff = $fireTs - $nowTs;
        $nextFireTimes[$col] = [
            'fired' => $diff < 0,
            'label' => $diff < 0   ? 'Already passed'
                : ($diff < 3600   ? 'In ' . round($diff/60) . ' min'
                : ($diff < 86400  ? 'In ' . round($diff/3600,1) . 'h'
                :                   'In ' . round($diff/86400,1) . ' days')),
        ];
    }
}

// ── Demo fire handler ─────────────────────────────────────────────────────────
$demo_msg = $demo_err = '';
if (isset($_GET['demo_fire'])) {
    $tier    = $_GET['demo_fire'];
    $allowed = ['7day','3day','1day','today','60min','30min','ondue','overdue'];
    if (in_array($tier, $allowed)) {
        $taskLabel = $nextTask ? $nextTask['task_name'] : 'Demo Task';
        $dueLabel  = $nextTask ? $nextTask['due_date']  : date('Y-m-d');
        $labels    = [
            '7day'    => '📅 Demo: Task due in 7 days',
            '3day'    => '⏳ Demo: Task due in 3 days',
            '1day'    => '⏰ Demo: Task due tomorrow',
            'today'   => '🔴 Demo: Task due today',
            '60min'   => '⏰ Demo: Task due in 60 minutes',
            '30min'   => '🔔 Demo: Task due in 30 minutes',
            'ondue'   => '🚨 Demo: Deadline reached',
            'overdue' => '🚨 Demo: Task is overdue',
        ];
        $tgMsg       = $labels[$tier] . "\n\n📌 <b>" . htmlspecialchars($taskLabel) . "</b>\n📅 Due: " . $dueLabel . "\n\n<i>This is a demo notification from ProcraTrack.</i>";
        $emailSub    = '🔔 ProcraTrack Demo — ' . $labels[$tier];
        $emailHtml   = '<p><b>' . htmlspecialchars($labels[$tier]) . '</b></p><p>📌 Task: <b>' . htmlspecialchars($taskLabel) . '</b></p><p>📅 Due: ' . htmlspecialchars($dueLabel) . '</p><p><i>This is a demo notification from ProcraTrack.</i></p>';
        $ok = notifyUser($pdo, $user_id, 'demo_' . $tier, $tgMsg, $emailSub, $emailHtml);
        $demo_msg = $ok ? "Demo reminder fired for <b>$tier</b> tier — check your Telegram / email." : '';
        $demo_err = !$ok ? "Could not send demo — make sure Telegram or Email is enabled above." : '';
    }
}

// ── renderTierRow helper ──────────────────────────────────────────────────────
function renderTierRow(array $tier, array $us, array $nextFireTimes, string $tab): void {
    $col       = $tier['col'];
    $on        = !isset($us[$col]) || $us[$col];
    $fire      = $nextFireTimes[$col] ?? null;
    $fired     = $fire && $fire['fired'];
    $nextLabel = $fire ? $fire['label'] : '—';
    $nextColor = $fired ? '#d9534f' : ($on ? '#4caf82' : 'var(--text-muted)');
    echo '<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;margin-bottom:6px;'
       . 'background:' . ($on ? 'rgba(76,175,130,0.06)' : 'var(--bg-input)') . ';'
       . 'border:1px solid ' . ($on ? 'rgba(76,175,130,0.22)' : 'var(--border)') . '">';

    echo '<div style="width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;'
       . 'background:' . ($on ? $tier['color'] : '#555') . ';flex-shrink:0">'
       . '<span style="font-size:10px;font-weight:800;color:#fff">' . $tier['badge'] . '</span></div>';

    echo '<div style="flex:1;min-width:0">'
       . '<div style="font-size:13px;font-weight:600;color:var(--text-main)">' . $tier['label'] . '</div>'
       . '<div style="font-size:11px;color:' . $nextColor . '">'
       . '<i class="bi ' . $tier['icon'] . '" style="font-size:10px"></i> '
       . ($on ? ($fired ? '⚠ ' . $nextLabel : '🕐 Next: ' . $nextLabel) : 'Currently disabled')
       . '</div></div>';

    echo '<div style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;flex-shrink:0;'
       . 'background:' . ($on ? 'rgba(76,175,130,0.15)' : 'rgba(150,150,150,0.12)') . ';'
       . 'color:' . ($on ? '#4caf82' : 'var(--text-muted)') . '">'
       . ($on ? 'ON' : 'OFF') . '</div>';

    $disabled = !$on ? 'opacity:.45;pointer-events:none' : '';
    echo '<span class="demo-only" style="display:none">'
       . '<a href="notifications.php?tab=' . urlencode($tab) . '&demo_fire=' . $tier['slug'] . '"'
       . ' style="flex-shrink:0;font-size:10px;font-weight:600;padding:4px 9px;border-radius:8px;'
       . 'background:rgba(124,106,247,0.1);color:var(--accent);text-decoration:none;'
       . 'border:1px solid rgba(124,106,247,0.2);white-space:nowrap;' . $disabled . '">'
       . '<i class="bi bi-send-fill" style="font-size:9px"></i> Fire</a></span>';

    echo '</div>';
}

// ── Load notification log ─────────────────────────────────────────────────────
$logs = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM notification_log WHERE user_id=? ORDER BY sent_at DESC LIMIT 20");
    $stmt->execute([$user_id]); $logs = $stmt->fetchAll();
} catch(Exception $e){}

// ── Shorthand vars for view ───────────────────────────────────────────────────
$tg_id         = htmlspecialchars($us['telegram_chat_id'] ?? '');
$tg_on         = !empty($us['notify_telegram']) ? 'checked' : '';
$em_on         = !empty($us['notify_email'])    ? 'checked' : '';
$em_login      = htmlspecialchars($us['email'] ?? '');
$em_notif      = htmlspecialchars($us['notification_email'] ?? '');
$em_addr       = $em_notif ?: $em_login;   // effective send address
$bot_name      = defined('TELEGRAM_BOT_NAME') ? TELEGRAM_BOT_NAME : 'ProcraTrackBot';

$tg_feedback = isset($_GET['tg_test'])
    ? ($_GET['tg_test'] === 'ok'
        ? '<span class="fb-ok"><i class="bi bi-check-circle-fill"></i> Message sent! Check Telegram.</span>'
        : '<span class="fb-err"><i class="bi bi-x-circle-fill"></i> Failed — check your Chat ID and bot token.</span>')
    : '';
$em_feedback = isset($_GET['em_test'])
    ? ($_GET['em_test'] === 'ok'
        ? '<span class="fb-ok"><i class="bi bi-check-circle-fill"></i> Email sent! Check your inbox (and spam).</span>'
        : '<span class="fb-err"><i class="bi bi-x-circle-fill"></i> Failed — check MAIL_USERNAME and MAIL_PASSWORD in email_config.php.</span>')
    : '';

require_once 'includes/header.php';
?>
<style>
/* ── Layout ── */
.notif-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
@media(max-width:640px){.notif-grid{grid-template-columns:1fr}}
.section-title{font-size:14px;font-weight:700;color:var(--text-main);margin:0 0 14px;display:flex;align-items:center;gap:8px}

/* ── Cards ── */
.n-card{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:22px}

/* ── Channel blocks ── */
.channel-block{background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px}
.channel-block:last-child{margin-bottom:0}
.channel-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.channel-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.channel-title{font-size:14px;font-weight:700;color:var(--text-main)}
.channel-badge{font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:6px}
.badge-primary{background:rgba(124,106,247,0.15);color:#7c6af7}
.badge-backup{background:var(--bg-input);color:var(--text-muted);border:1px solid var(--border)}

/* ── Form controls ── */
.f-label{font-size:12px;color:var(--text-muted);display:block;margin-bottom:5px;font-weight:500}
.f-input{width:100%;padding:9px 13px;border-radius:9px;border:1px solid var(--border);background:var(--bg-base);color:var(--text-main);font-size:13px;box-sizing:border-box;outline:none;transition:border-color .15s}
.f-input:focus{border-color:var(--accent)}
.f-static{padding:9px 13px;border-radius:9px;border:1px solid var(--border);background:var(--bg-base);color:var(--text-muted);font-size:13px;margin-bottom:4px}

/* ── Telegram setup hint ── */
.tg-steps{background:rgba(34,158,217,0.07);border:1px solid rgba(34,158,217,0.2);border-radius:9px;padding:11px 13px;margin-bottom:13px;font-size:12px;color:var(--text-main);line-height:1.8}
.tg-steps b{color:#229ED9}

/* ── Toggle ── */
.toggle-row{display:flex;align-items:center;justify-content:space-between;margin:12px 0 10px}
.toggle-label{font-size:13px;color:var(--text-main)}
.toggle-switch{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#444;border-radius:22px;cursor:pointer;transition:.2s}
.toggle-slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
input:checked+.toggle-slider{background:var(--accent)}
input:checked+.toggle-slider:before{transform:translateX(18px)}

/* ── Buttons ── */
.btn-primary{background:var(--accent);color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:opacity .15s}
.btn-primary:hover{opacity:.85}
.btn-test{background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.btn-test:hover{border-color:var(--accent);color:var(--accent)}

/* ── Feedback ── */
.fb-ok{font-size:12px;color:#4caf82;display:inline-flex;align-items:center;gap:5px}
.fb-err{font-size:12px;color:#d9534f;display:inline-flex;align-items:center;gap:5px}
.save-ok{background:rgba(76,175,130,0.1);border:1px solid rgba(76,175,130,0.3);color:#4caf82;border-radius:9px;padding:10px 14px;font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* ── Notification types ── */
.notif-type{display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:10px;border:1px solid var(--border);background:var(--card-bg);margin-bottom:8px}
.notif-type:last-child{margin-bottom:0}
.notif-type-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.notif-type-title{font-size:13px;font-weight:600;color:var(--text-main)}
.notif-type-desc{font-size:12px;color:var(--text-muted);margin-top:2px}

/* ── Log table ── */
.log-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)}
.log-row:last-child{border-bottom:none}
.log-channel{width:90px;font-size:12px;font-weight:600;flex-shrink:0}
.log-type{flex:1;font-size:12px;color:var(--text-muted)}
.log-status{font-size:11px;font-weight:700;width:48px;text-align:right;flex-shrink:0}
.log-time{font-size:11px;color:var(--text-muted);width:80px;text-align:right;flex-shrink:0}
</style>

<div class="page-topbar">
    <div class="page-title">🔔 Notifications</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Connect Telegram or email to receive task reminders, focus alerts and habit nudges.</div>

<?php if ($save_msg): ?>
<div class="save-ok"><i class="bi bi-check-circle-fill"></i> <?= $save_msg ?></div>
<?php endif; ?>
<?php if ($access_msg): ?>
<div class="save-ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($access_msg) ?></div>
<?php endif; ?>

<?php if ($pending_requests || $approved_requests): ?>
<div class="n-card" style="margin-bottom:20px">
    <div class="section-title"><i class="bi bi-shield-lock"></i> Cross-institution access requests</div>

    <?php if ($pending_requests): ?>
    <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:.06em;margin-bottom:8px;text-transform:uppercase">Pending your decision</div>
    <?php foreach ($pending_requests as $r): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:10px;border:1px solid rgba(245,158,11,0.3);background:rgba(245,158,11,0.06);margin-bottom:8px;flex-wrap:wrap">
        <div style="width:36px;height:36px;border-radius:9px;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-person-badge" style="color:#f59e0b"></i>
        </div>
        <div style="flex:1;min-width:180px">
            <div style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($r['name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['university']) ?> · requested <?= date('d M Y', strtotime($r['requested_at'])) ?></div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);flex-basis:100%;max-width:260px">
            Wants to view your tasks and habits on ProcraTrack.
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
            <form method="POST" action="notifications.php?tab=<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="access_action" value="approve">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
                <button type="submit" class="btn-test" style="border-color:#4caf82;color:#4caf82"><i class="bi bi-check-lg"></i> Approve</button>
            </form>
            <form method="POST" action="notifications.php?tab=<?= htmlspecialchars($_GET['tab'] ?? '') ?>" onsubmit="return confirm('Deny this access request?');">
                <?= csrf_field() ?>
                <input type="hidden" name="access_action" value="deny">
                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
                <button type="submit" class="btn-test" style="border-color:#d9534f;color:#d9534f"><i class="bi bi-x-lg"></i> Deny</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($approved_requests): ?>
    <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:.06em;margin:<?= $pending_requests ? '16px' : '0' ?> 0 8px;text-transform:uppercase">Lecturers with approved access</div>
    <?php foreach ($approved_requests as $r): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:9px 13px;border-radius:10px;border:1px solid var(--border);background:var(--bg-input);margin-bottom:6px">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(76,175,130,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-unlock" style="color:#4caf82;font-size:14px"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($r['name']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['university']) ?></div>
        </div>
        <form method="POST" action="notifications.php?tab=<?= htmlspecialchars($_GET['tab'] ?? '') ?>" onsubmit="return confirm('Revoke this lecturer\'s access to your tasks and habits?');">
            <?= csrf_field() ?>
            <input type="hidden" name="access_action" value="revoke">
            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
            <button type="submit" class="btn-test" style="border-color:#d9534f;color:#d9534f;font-size:11px;padding:5px 11px"><i class="bi bi-slash-circle"></i> Revoke</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="notif-grid">

    <!-- ── Left: Channel setup ── -->
    <div class="n-card">
        <div class="section-title"><i class="bi bi-bell"></i> Notification channels</div>

        <form method="POST" action="notifications.php?tab=<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
        <input type="hidden" name="save_settings" value="1">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? '') ?>">

        <!-- Telegram -->
        <div class="channel-block">
            <div class="channel-head">
                <div class="channel-icon" style="background:rgba(34,158,217,0.12)"><i class="bi bi-telegram" style="color:#229ED9"></i></div>
                <div>
                    <span class="channel-title">Telegram</span>
                    <span class="channel-badge badge-primary">Recommended</span>
                </div>
            </div>

            <div class="tg-steps">
                <b>How to connect:</b><br>
                1. Open Telegram → search <b>@<?= htmlspecialchars($bot_name) ?></b><br>
                2. Tap <b>Start</b> — bot replies with your Chat ID<br>
                3. Paste the number below and save
            </div>

            <label class="f-label">Your Telegram Chat ID</label>
            <input type="text" name="telegram_chat_id" class="f-input" value="<?= $tg_id ?>"
                   placeholder="e.g. 123456789" style="margin-bottom:4px">

            <div class="toggle-row">
                <span class="toggle-label">Enable Telegram alerts</span>
                <label class="toggle-switch">
                    <input type="checkbox" name="notify_telegram" <?= $tg_on ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
                <button type="submit" name="save_settings" value="1" class="btn-test"><i class="bi bi-save"></i> Save</button>
                <button type="submit" name="test_tg" value="1" class="btn-test"><i class="bi bi-send"></i> Send test</button>
                <?= $tg_feedback ?>
            </div>
        </div>

        <!-- Email -->
        <div class="channel-block">
            <div class="channel-head">
                <div class="channel-icon" style="background:rgba(124,106,247,0.12)"><i class="bi bi-envelope" style="color:var(--accent)"></i></div>
                <div>
                    <span class="channel-title">Email</span>
                    <span class="channel-badge badge-backup">Backup</span>
                </div>
            </div>

            <label class="f-label">Notifications will be sent to</label>
            <div class="f-static"><?= $em_addr ?></div>
            <?php if ($em_notif): ?>
            <div style="font-size:11px;color:#4caf82;margin-bottom:10px"><i class="bi bi-check-circle"></i> Using custom notification email</div>
            <?php else: ?>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
                Using login email. <a href="settings.php" style="color:var(--accent);text-decoration:none">Set a different email in Settings →</a>
            </div>
            <?php endif; ?>

            <div class="toggle-row">
                <span class="toggle-label">Enable email notifications</span>
                <label class="toggle-switch">
                    <input type="checkbox" name="notify_email" <?= $em_on ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
                <button type="submit" name="save_settings" value="1" class="btn-test"><i class="bi bi-save"></i> Save</button>
                <button type="submit" name="test_email" value="1" class="btn-test"><i class="bi bi-send"></i> Send test</button>
                <?= $em_feedback ?>
            </div>
        </div>

        <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:4px">
            <i class="bi bi-save"></i> Save Settings
        </button>
        </form>
    </div>

    <!-- ── Right: What you'll receive + Schedule ── -->
    <div>
        <div class="n-card" style="margin-bottom:16px">
            <div class="section-title"><i class="bi bi-list-check"></i> What you'll receive</div>
            <?php
            $types = [
                ['⏰','rgba(124,106,247,0.15)','Task due tomorrow',     'Sent the day before a pending task is due'],
                ['🚨','rgba(247,106,106,0.15)','Overdue task alert',    'Fires when a task passes its deadline'],
                ['✅','rgba(106,247,184,0.15)','Focus session complete', 'Sent when you finish a Pomodoro session'],
                ['📊','rgba(247,196,106,0.15)','Procrastination alert', 'Fires when your risk score rises above 70'],
                ['🌱','rgba(106,247,184,0.12)','Daily habit check-in',  'Morning nudge to keep your habit streak'],
            ];
            foreach ($types as [$icon,$bg,$title,$desc]): ?>
            <div class="notif-type">
                <div class="notif-type-icon" style="background:<?= $bg ?>"><?= $icon ?></div>
                <div>
                    <div class="notif-type-title"><?= $title ?></div>
                    <div class="notif-type-desc"><?= $desc ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Connection status -->
        <div class="n-card" style="margin-bottom:16px">
            <div class="section-title"><i class="bi bi-activity"></i> Connection status</div>
            <?php if ($tg_id): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:rgba(34,158,217,0.08);border:1px solid rgba(34,158,217,0.2);border-radius:10px;margin-bottom:10px">
                <i class="bi bi-telegram" style="color:#229ED9;font-size:20px"></i>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--text-main)">Telegram connected</div>
                    <div style="font-size:12px;color:var(--text-muted)">Chat ID: <?= $tg_id ?></div>
                </div>
                <i class="bi bi-check-circle-fill" style="color:#4caf82;margin-left:auto"></i>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;margin-bottom:10px">
                <i class="bi bi-telegram" style="color:var(--text-muted);font-size:20px"></i>
                <div style="font-size:13px;color:var(--text-muted)">Telegram not connected yet</div>
            </div>
            <?php endif; ?>
            <?php if ($em_on === 'checked'): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:rgba(124,106,247,0.07);border:1px solid rgba(124,106,247,0.2);border-radius:10px">
                <i class="bi bi-envelope" style="color:var(--accent);font-size:18px"></i>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--text-main)">Email enabled</div>
                    <div style="font-size:12px;color:var(--text-muted)"><?= $em_addr ?></div>
                </div>
                <i class="bi bi-check-circle-fill" style="color:#4caf82;margin-left:auto"></i>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px">
                <i class="bi bi-envelope" style="color:var(--text-muted);font-size:18px"></i>
                <div style="font-size:13px;color:var(--text-muted)">Email notifications off</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Reminder schedule card -->
        <div class="n-card">
            <div class="section-title" style="justify-content:space-between">
                <span><i class="bi bi-alarm"></i> Reminder schedule</span>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0">
                    <span style="font-size:11px;font-weight:600;color:var(--text-muted)" id="demo-mode-label">Demo mode</span>
                    <span style="position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0">
                        <input type="checkbox" id="demo-mode-toggle" style="opacity:0;width:0;height:0;position:absolute"
                            onchange="toggleDemoMode(this.checked)">
                        <span id="demo-mode-slider" style="position:absolute;inset:0;background:#444;border-radius:22px;cursor:pointer;transition:.2s">
                            <span id="demo-mode-knob" style="position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;display:block"></span>
                        </span>
                    </span>
                </label>
            </div>

            <?php if ($demo_msg): ?>
            <div style="padding:10px 13px;background:rgba(76,175,130,0.1);border:1px solid rgba(76,175,130,0.3);border-radius:9px;font-size:12px;color:#4caf82;margin-bottom:14px">
                <i class="bi bi-check-circle-fill"></i> <?= $demo_msg ?>
            </div>
            <?php elseif ($demo_err): ?>
            <div style="padding:10px 13px;background:rgba(217,83,79,0.08);border:1px solid rgba(217,83,79,0.2);border-radius:9px;font-size:12px;color:#d9534f;margin-bottom:14px">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= $demo_err ?>
            </div>
            <?php endif; ?>

            <div class="demo-only" style="display:none">
            <?php if ($nextTask): ?>
            <div style="padding:13px;background:rgba(124,106,247,0.07);border:1px solid rgba(124,106,247,0.2);border-radius:12px;margin-bottom:16px">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
                    <i class="bi bi-clock-fill" style="color:var(--accent)"></i> Next task deadline
                </div>
                <div style="font-size:13px;font-weight:700;color:var(--text-main);margin-bottom:6px">
                    📌 <?= htmlspecialchars($nextTask['task_name']) ?>
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:6px">
                        <?= $nextTask['due_date'] ?><?= $nextTask['due_time'] ? ' at ' . $nextTask['due_time'] : '' ?>
                    </span>
                </div>
                <div style="font-size:26px;font-weight:800;color:var(--accent);font-variant-numeric:tabular-nums" id="live-countdown">
                    <?php
                    $s = $countdownSeconds;
                    $d = floor($s/86400); $s %= 86400;
                    $h = floor($s/3600);  $s %= 3600;
                    $m = floor($s/60);    $s %= 60;
                    echo ($d > 0 ? "{$d}d " : '') . sprintf('%02d:%02d:%02d', $h, $m, $s);
                    ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Updates every second</div>
            </div>
            <?php else: ?>
            <div style="padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:10px;margin-bottom:16px;font-size:13px;color:var(--text-muted)">
                <i class="bi bi-calendar-x"></i> No upcoming pending tasks with a due date found.
            </div>
            <?php endif; ?>
            </div>

            <p class="demo-only" style="display:none;font-size:12px;color:var(--text-muted);margin:0 0 14px">
                Click <b>Fire</b> to send a demo notification instantly via your enabled channels.
            </p>
            <p class="normal-only" style="font-size:12px;color:var(--text-muted);margin:0 0 14px">
                Toggle tiers in <a href="settings.php" style="color:var(--accent);text-decoration:none;font-weight:600">Settings → Reminder Timing</a>.
                Switch to <b>Demo mode</b> to test fire any tier instantly.
            </p>

            <?php
            $dayTiers = [
                ['col'=>'remind_7day',  'icon'=>'bi-calendar-week',  'label'=>'7 days before',        'badge'=>'7D',  'color'=>'#7c6af7','slug'=>'7day'],
                ['col'=>'remind_3day',  'icon'=>'bi-calendar3',      'label'=>'3 days before',        'badge'=>'3D',  'color'=>'#3a9bd5','slug'=>'3day'],
                ['col'=>'remind_1day',  'icon'=>'bi-calendar-day',   'label'=>'1 day before',         'badge'=>'1D',  'color'=>'#00a896','slug'=>'1day'],
                ['col'=>'remind_today', 'icon'=>'bi-calendar-check', 'label'=>'On the day',           'badge'=>'AM',  'color'=>'#f59e0b','slug'=>'today'],
            ];
            $timeTiers = [
                ['col'=>'remind_60min',  'icon'=>'bi-clock-history',     'label'=>'60 min before end time','badge'=>'60m','color'=>'#7c6af7','slug'=>'60min'],
                ['col'=>'remind_30min',  'icon'=>'bi-clock',             'label'=>'30 min before end time','badge'=>'30m','color'=>'#3a9bd5','slug'=>'30min'],
                ['col'=>'remind_ondue',  'icon'=>'bi-bell-fill',         'label'=>'At deadline',           'badge'=>'0m', 'color'=>'#e05c5c','slug'=>'ondue'],
                ['col'=>'remind_overdue','icon'=>'bi-exclamation-circle','label'=>'When overdue',          'badge'=>'OD', 'color'=>'#d9534f','slug'=>'overdue'],
            ];
            $anyEnabled = false;
            $currentTab = $_GET['tab'] ?? '';
            ?>

            <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:.06em;margin-bottom:8px;text-transform:uppercase">📅 Day-based reminders</div>
            <?php foreach ($dayTiers as $tier):
                if (!isset($us[$tier['col']]) || $us[$tier['col']]) $anyEnabled = true;
                renderTierRow($tier, $us, $nextFireTimes, $currentTab);
            endforeach; ?>

            <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:.06em;margin:14px 0 8px;text-transform:uppercase">
                ⏱ Time-based reminders <span style="font-weight:400;text-transform:none;font-size:10px">(requires due time on task)</span>
            </div>
            <?php foreach ($timeTiers as $tier):
                if (!isset($us[$tier['col']]) || $us[$tier['col']]) $anyEnabled = true;
                renderTierRow($tier, $us, $nextFireTimes, $currentTab);
            endforeach; ?>

            <?php if (!$anyEnabled): ?>
            <div style="margin-top:10px;padding:10px 13px;background:rgba(229,83,83,0.08);border:1px solid rgba(229,83,83,0.2);border-radius:9px;font-size:12px;color:#d9534f">
                <i class="bi bi-exclamation-triangle-fill"></i> All reminders are off.
                Enable at least one in <a href="settings.php" style="color:#d9534f;font-weight:700">Settings → Reminder Timing</a>.
            </div>
            <?php else: ?>
            <div style="margin-top:10px;padding:10px 13px;background:rgba(76,175,130,0.07);border:1px solid rgba(76,175,130,0.2);border-radius:9px;font-size:12px;color:var(--text-muted)">
                <i class="bi bi-info-circle" style="color:#4caf82"></i>
                Reminders fire automatically when <code>send_reminders.php</code> runs.
                Use <b>Fire</b> buttons above to simulate any tier instantly during demo.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($nextTask && $countdownSeconds !== null): ?>
<script>
(function(){
    // Anchor to the real deadline (ms since epoch), not a counter we manually
    // decrement. Deriving remaining time from the clock on every tick means
    // the display can never drift out of sync, even if setInterval fires
    // late/early or the tab was backgrounded and throttled by the browser.
    var deadlineMs = Date.now() + (<?= (int)$countdownSeconds ?> * 1000);
    var el = document.getElementById('live-countdown');
    if (!el) return;
    function fmt() {
        var s = Math.round((deadlineMs - Date.now()) / 1000);
        if (s <= 0) { el.textContent = '⏰ DEADLINE REACHED'; el.style.color = '#e05c5c'; return; }
        var d = Math.floor(s/86400); s %= 86400;
        var h = Math.floor(s/3600);  s %= 3600;
        var m = Math.floor(s/60);    s %= 60;
        el.textContent = (d > 0 ? d + 'd ' : '')
            + String(h).padStart(2,'0') + ':'
            + String(m).padStart(2,'0') + ':'
            + String(s).padStart(2,'0');
    }
    fmt();
    setInterval(fmt, 1000);
})();
</script>
<?php endif; ?>

<script>
function toggleDemoMode(on) {
    // Show/hide demo-only and normal-only elements
    document.querySelectorAll('.demo-only').forEach(function(el) {
        el.style.display = on ? '' : 'none';
    });
    document.querySelectorAll('.normal-only').forEach(function(el) {
        el.style.display = on ? 'none' : '';
    });

    // Update toggle slider colour
    var slider = document.getElementById('demo-mode-slider');
    var knob   = document.getElementById('demo-mode-knob');
    var label  = document.getElementById('demo-mode-label');
    if (slider) slider.style.background = on ? 'var(--accent)' : '#444';
    if (knob)   knob.style.left         = on ? '21px' : '3px';
    if (label)  label.style.color       = on ? 'var(--accent)' : 'var(--text-muted)';

    // Persist state in sessionStorage
    sessionStorage.setItem('procratrack_demo_mode', on ? '1' : '0');
}

// Restore state on page load
(function(){
    var saved  = sessionStorage.getItem('procratrack_demo_mode');
    var active = saved === '1' || <?= isset($_GET['demo_fire']) ? 'true' : 'false' ?>;
    if (active) {
        var toggle = document.getElementById('demo-mode-toggle');
        if (toggle) { toggle.checked = true; toggleDemoMode(true); }
    }
})();
</script>

<!-- ── Notification log ── -->
<div class="n-card">
    <div class="section-title"><i class="bi bi-clock-history"></i> Recent notifications
        <span style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:4px">(last <?= count($logs) ?>)</span>
    </div>

    <?php if (empty($logs)): ?>
        <p style="font-size:13px;color:var(--text-muted);margin:0">No notifications sent yet. Save your settings and send a test above.</p>
    <?php else: ?>
        <?php foreach ($logs as $log):
            $ch_icon  = $log['channel'] === 'telegram' ? '<i class="bi bi-telegram" style="color:#229ED9"></i>' : '<i class="bi bi-envelope" style="color:var(--accent)"></i>';
            $ch_label = ucfirst($log['channel']);
            $status_color = $log['status'] === 'sent' ? '#4caf82' : '#d9534f';
            $type_icons = ['task_due'=>'⏰','overdue'=>'🚨','focus_complete'=>'✅','procrastination'=>'📊','habit'=>'🌱','test'=>'🧪'];
            $t_icon = $type_icons[$log['type']] ?? '🔔';
        ?>
        <div class="log-row">
            <div class="log-channel"><?= $ch_icon ?> <?= $ch_label ?></div>
            <div class="log-type"><?= $t_icon ?> <?= htmlspecialchars($log['type']) ?></div>
            <div class="log-status" style="color:<?= $status_color ?>"><?= strtoupper($log['status']) ?></div>
            <div class="log-time"><?= date('d M H:i', strtotime($log['sent_at'])) ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
