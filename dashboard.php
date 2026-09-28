<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Dashboard';
require_once 'config/db.php';
require_once 'includes/header.php';

$user_id = $_SESSION['user_id'];
$name    = $_SESSION['user_name'];

// --- Stats ---
// Total tasks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_tasks = $stmt->fetchColumn();

// Completed tasks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'done'");
$stmt->execute([$user_id]);
$done_tasks = $stmt->fetchColumn();

// Pending tasks
$pending_tasks = $total_tasks - $done_tasks;

// Overdue tasks (due_date < today and not done)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'pending' AND due_date < CURDATE()");
$stmt->execute([$user_id]);
$overdue_tasks = $stmt->fetchColumn();

// Total focus sessions today
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes),0) FROM sessions WHERE user_id = ? AND session_date = CURDATE()");
$stmt->execute([$user_id]);
$focus_today = $stmt->fetchColumn();

// Completion rate
$completion_rate = $total_tasks > 0 ? round(($done_tasks / $total_tasks) * 100) : 0;

// Focus score (simple formula)
$focus_score = min(100, $completion_rate + min(30, $focus_today));

// Weekly study hours (last 7 days)
$stmt = $pdo->prepare("
    SELECT session_date, COALESCE(SUM(duration_minutes),0) as total
    FROM sessions
    WHERE user_id = ? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY session_date
    ORDER BY session_date ASC
");
$stmt->execute([$user_id]);
$weekly_raw = $stmt->fetchAll();

// Fill all 7 days
$weekly = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $weekly[$date] = 0;
}
foreach ($weekly_raw as $row) {
    $weekly[$row['session_date']] = round($row['total'] / 60, 1);
}
$weekly_labels = array_map(fn($d) => date('D', strtotime($d)), array_keys($weekly));
$weekly_data   = array_values($weekly);

// Today's top pending tasks
$stmt = $pdo->prepare("
    SELECT * FROM tasks
    WHERE user_id = ? AND status = 'pending'
    ORDER BY FIELD(priority,'high','medium','low'), due_date ASC
    LIMIT 5
");
$stmt->execute([$user_id]);
$today_tasks = $stmt->fetchAll();

// Habit streak (count habits logged today)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM habit_logs hl
    JOIN habits h ON h.id = hl.habit_id
    WHERE h.user_id = ? AND hl.log_date = CURDATE()
");
$stmt->execute([$user_id]);
$habits_today = $stmt->fetchColumn();

// ── XP Points ────────────────────────────────────────────────────────
$xp_t = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN priority='high' THEN 70 ELSE 20 END),0) FROM tasks WHERE user_id=? AND status='done'");
$xp_t->execute([$user_id]); $xp_tasks = (int)$xp_t->fetchColumn();
$xp_h = $pdo->prepare("SELECT COALESCE(COUNT(*)*10,0) FROM habit_logs hl JOIN habits h ON h.id=hl.habit_id WHERE h.user_id=?");
$xp_h->execute([$user_id]); $xp_habits = (int)$xp_h->fetchColumn();
$xp_f = $pdo->prepare("SELECT COALESCE(COUNT(*)*5,0) FROM sessions WHERE user_id=?");
$xp_f->execute([$user_id]); $xp_focus = (int)$xp_f->fetchColumn();
$total_xp = $xp_tasks + $xp_habits + $xp_focus;
$xp_level = max(1, (int)floor($total_xp / 100) + 1);
?>

<div class="page-topbar">
<div class="page-title">Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars($name) ?></div>
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" style="margin-left:auto"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub"><?= date('l, d F Y') ?> — Here's your focus overview</div>

<!-- STAT CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-val" style="color:#7c6af7"><?= $focus_score ?></div>
            <div class="stat-label">Focus Score</div>
            <div class="stat-delta up">↑ out of 100</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-val" style="color:#6af7b8"><?= $focus_today ?>m</div>
            <div class="stat-label">Focus Time Today</div>
            <div class="stat-delta up">↑ minutes logged</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-val" style="color:#f76a6a"><?= $overdue_tasks ?></div>
            <div class="stat-label">Overdue Tasks</div>
            <div class="stat-delta <?= $overdue_tasks > 0 ? 'down' : 'up' ?>">
                <?= $overdue_tasks > 0 ? '⚠ Action needed' : '✓ All on track' ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-val" style="color:#f7c46a"><?= $habits_today ?>🔥</div>
            <div class="stat-label">Habits Done Today</div>
            <div class="stat-delta up">↑ keep going!</div>
        </div>
    </div>
</div>

<!-- XP Banner -->
<div style="background:rgba(124,106,247,.07);border:1px solid rgba(124,106,247,.2);border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="width:40px;height:40px;border-radius:50%;background:rgba(124,106,247,.2);border:2px solid #7c6af7;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#7c6af7;flex-shrink:0;">
        <?= $xp_level ?>
    </div>
    <div style="flex:1;min-width:160px;">
        <div style="font-size:13px;font-weight:600;color:var(--text-main)">Level <?= $xp_level ?> · <?= $total_xp ?> XP earned</div>
        <div style="height:6px;border-radius:3px;background:rgba(128,128,128,0.2);margin-top:5px;overflow:hidden;">
            <div style="height:100%;border-radius:3px;background:linear-gradient(90deg,#7c6af7,#6af7b8);width:<?= $total_xp % 100 ?>%;"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?= $total_xp % 100 ?>/100 XP to level <?= $xp_level + 1 ?></div>
    </div>
    <a href="insights.php" style="font-size:12px;color:#7c6af7;text-decoration:none;font-weight:600;flex-shrink:0;">View full insights →</a>
</div>

<div class="row g-3">
    <!-- LEFT COLUMN -->
    <div class="col-md-7">

        <!-- Overall completion banner -->
        <?php
        $mot_label = $completion_rate >= 80 ? "You're crushing it! 🏆 Keep this momentum going." :
                     ($completion_rate >= 50 ? "Good progress! Push to 80% to reach Low Risk." :
                     "Let's get started — every completed task raises your score.");
        $bar_color = $completion_rate >= 70 ? '#6af7b8' : ($completion_rate >= 40 ? '#f7c46a' : '#f76a6a');
        ?>
        <div style="margin-bottom:14px;background:var(--bg-surface);border:1px solid rgba(128,128,128,0.2);border-radius:12px;padding:14px 16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="font-size:13px;font-weight:600;color:var(--text-main)">Overall Task Completion</span>
                <span style="font-size:15px;font-weight:700;color:<?= $bar_color ?>"><?= $completion_rate ?>%</span>
            </div>
            <div style="height:10px;border-radius:5px;background:rgba(128,128,128,0.2);overflow:hidden;">
                <div style="height:100%;border-radius:5px;background:<?= $bar_color ?>;width:<?= $completion_rate ?>%;transition:width .6s ease;min-width:<?= $completion_rate > 0 ? '4px' : '0' ?>;"></div>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;"><?= $mot_label ?></div>
        </div>

        <!-- Procrastination Risk -->
        <div class="card mb-3">
            <div class="card-title-sm">Procrastination Risk Index</div>
            <div class="d-flex align-items-center gap-4">
                <div style="position:relative;width:110px;height:110px;flex-shrink:0">
                    <svg width="110" height="110" viewBox="0 0 110 110">
                        <circle cx="55" cy="55" r="44" fill="none" stroke="var(--border)" stroke-width="9"/>
                        <circle cx="55" cy="55" r="44" fill="none" stroke="#7c6af7" stroke-width="9"
                            stroke-dasharray="276.5"
                            stroke-dashoffset="<?= 276.5 - (276.5 * $focus_score / 100) ?>"
                            stroke-linecap="round"
                            transform="rotate(-90 55 55)"/>
                    </svg>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                        <div style="font-size:22px;font-weight:700;color:#f7c46a"><?= $focus_score ?></div>
                        <div style="font-size:10px;color:var(--text-muted)">SCORE</div>
                    </div>
                </div>
                <div style="flex:1">
                    <div style="margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:5px">
                            <span>Task completion</span><span style="color:var(--text-main)"><?= $completion_rate ?>%</span>
                        </div>
                        <div class="prog-bar"><div class="prog-fill" style="width:<?= $completion_rate ?>%;background:#7c6af7"></div></div>
                    </div>
                    <div style="margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:5px">
                            <span>Pending tasks</span><span style="color:var(--text-main)"><?= $pending_tasks ?></span>
                        </div>
                        <div class="prog-bar"><div class="prog-fill" style="width:<?= $total_tasks > 0 ? round(($pending_tasks/$total_tasks)*100) : 0 ?>%;background:#f7c46a"></div></div>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:5px">
                            <span>Overdue</span><span style="color:var(--text-main)"><?= $overdue_tasks ?></span>
                        </div>
                        <div class="prog-bar"><div class="prog-fill" style="width:<?= $total_tasks > 0 ? round(($overdue_tasks/$total_tasks)*100) : 0 ?>%;background:#f76a6a"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Chart -->
        <div class="card">
            <div class="card-title-sm">Study Hours This Week</div>
            <canvas id="weeklyChart" height="120"></canvas>
        </div>
    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-md-5">

        <!-- Alerts -->
        <?php if ($overdue_tasks > 0): ?>
        <div class="alert-danger mb-3">
            🚨 <b><?= $overdue_tasks ?> task(s) overdue!</b> Check your task list now.
        </div>
        <?php endif; ?>
        <?php if ($completion_rate >= 70): ?>
        <div class="alert-ok mb-3">
            ✅ Great job! You've completed <?= $completion_rate ?>% of your tasks.
        </div>
        <?php else: ?>
        <div class="alert-warn mb-3">
            ⚠️ Only <?= $completion_rate ?>% tasks done. Stay focused today!
        </div>
        <?php endif; ?>

        <!-- Today's Tasks -->
        <div class="card">
            <div class="card-title-sm">Today's Priority Tasks</div>
            <?php if (empty($today_tasks)): ?>
                <div style="color:#7a7a95;font-size:13px;text-align:center;padding:20px 0">
                    🎉 No pending tasks! <a href="tasks.php" style="color:#7c6af7">Add some</a>
                </div>
            <?php else: ?>
                <?php foreach ($today_tasks as $t): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #2a2a38">
                    <div style="flex:1">
                        <div style="font-size:13px;font-weight:500;color:var(--text-main)"><?= htmlspecialchars($t['task_name']) ?></div>
                        <div style="font-size:11px;color:#7a7a95"><?= htmlspecialchars($t['course']) ?> · Due <?= $t['due_date'] ?></div>
                    </div>
                    <?php
                        $pc = $t['priority']==='high' ? '#f76a6a' : ($t['priority']==='medium' ? '#f7c46a' : '#6af7b8');
                    ?>
                    <span style="padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600;background:<?= $pc ?>22;color:<?= $pc ?>">
                        <?= strtoupper($t['priority']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:12px;text-align:center">
                    <a href="tasks.php" style="color:#7c6af7;font-size:13px;text-decoration:none;font-weight:500">View all tasks →</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="card">
            <div class="card-title-sm">Quick Actions</div>
            <div class="d-flex flex-column gap-2">
                <a href="tasks.php"   style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-input);border-radius:10px;text-decoration:none;color:var(--text-main);font-size:13px;transition:background .2s" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--bg-input)'">
                    <i class="bi bi-plus-circle" style="color:#7c6af7;font-size:16px"></i> Add New Task
                </a>
                <a href="timer.php"   style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-input);border-radius:10px;text-decoration:none;color:var(--text-main);font-size:13px;transition:background .2s" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--bg-input)'">
                    <i class="bi bi-stopwatch" style="color:#6af7b8;font-size:16px"></i> Start Focus Timer
                </a>
                <a href="habits.php"  style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-input);border-radius:10px;text-decoration:none;color:var(--text-main);font-size:13px;transition:background .2s" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--bg-input)'">
                    <i class="bi bi-calendar2-check" style="color:#f7c46a;font-size:16px"></i> Log Habits
                </a>
                <a href="insights.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-input);border-radius:10px;text-decoration:none;color:var(--text-main);font-size:13px;transition:background .2s" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--bg-input)'">
                    <i class="bi bi-lightbulb" style="color:#f76a6a;font-size:16px"></i> View AI Insights
                </a>
            </div>
        </div>

    </div>
</div>

<script>
const ctx = document.getElementById('weeklyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($weekly_labels) ?>,
        datasets: [{
            label: 'Hours',
            data: <?= json_encode($weekly_data) ?>,
            backgroundColor: function(ctx) {
                return ctx.dataIndex === <?= count($weekly_data)-1 ?> ? '#7c6af7' : '#2a2a38';
            },
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: '#2a2a38' }, ticks: { color: '#7a7a95' } },
            y: { grid: { color: '#2a2a38' }, ticks: { color: '#7a7a95' }, beginAtZero: true }
        }
    }
});
</script>

<script>
fetch('reminder_service.php')
    .catch(console.error);
</script>

<?php require_once 'includes/footer.php'; ?>
