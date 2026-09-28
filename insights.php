<?php
require_once __DIR__ . '/config/tab_session.php';
$pageTitle = 'Insights';
require_once 'config/db.php';
require_once 'includes/header.php';

$user_id = $_SESSION['user_id'];

// Task stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=?"); $stmt->execute([$user_id]); $total = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='done'"); $stmt->execute([$user_id]); $done = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='pending' AND due_date < CURDATE()"); $stmt->execute([$user_id]); $overdue = $stmt->fetchColumn();
$completion = $total > 0 ? round(($done/$total)*100) : 0;

// Total focus time
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes),0) FROM sessions WHERE user_id=?"); $stmt->execute([$user_id]); $total_mins = $stmt->fetchColumn();

// Weekly sessions
$stmt = $pdo->prepare("SELECT session_date, SUM(duration_minutes) as total FROM sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY session_date ORDER BY session_date");
$stmt->execute([$user_id]);
$weekly_raw = $stmt->fetchAll();
$weekly = []; for($i=6;$i>=0;$i--) $weekly[date('Y-m-d',strtotime("-$i days"))]=0;
foreach($weekly_raw as $r) $weekly[$r['session_date']] = round($r['total']/60,1);
$weekly_labels = array_map(fn($d)=>date('D',strtotime($d)), array_keys($weekly));
$weekly_data   = array_values($weekly);

// Habits completion
$stmt = $pdo->prepare("SELECT COUNT(*) FROM habits WHERE user_id=?"); $stmt->execute([$user_id]); $total_habits = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT h.id) FROM habit_logs hl JOIN habits h ON h.id=hl.habit_id WHERE h.user_id=? AND hl.log_date=CURDATE()"); $stmt->execute([$user_id]); $habits_today = $stmt->fetchColumn();

// Procrastination score
$focus_score = min(100, $completion + min(30, round($total_mins/60)));
$risk = $focus_score >= 70 ? 'Low' : ($focus_score >= 40 ? 'Medium' : 'High');
$risk_color = $focus_score >= 70 ? '#6af7b8' : ($focus_score >= 40 ? '#f7c46a' : '#f76a6a');

// High priority overdue
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id=? AND status='pending' AND due_date < CURDATE() AND priority='high' LIMIT 3");
$stmt->execute([$user_id]); $urgent = $stmt->fetchAll();

// Most productive day
$stmt = $pdo->prepare("SELECT DAYNAME(session_date) as day, SUM(duration_minutes) as total FROM sessions WHERE user_id=? GROUP BY DAYNAME(session_date) ORDER BY total DESC LIMIT 1");
$stmt->execute([$user_id]); $best_day = $stmt->fetch();

// ── XP / Points calculation ───────────────────────────────────────────
// +20 XP per completed task, +50 bonus for high-priority done on time
// +10 XP per habit log, +5 XP per focus session
$xp_tasks = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN priority='high' THEN 70 ELSE 20 END),0) FROM tasks WHERE user_id=? AND status='done'");
$xp_tasks->execute([$user_id]); $xp_from_tasks = (int)$xp_tasks->fetchColumn();

$xp_habits = $pdo->prepare("SELECT COALESCE(COUNT(*) * 10, 0) FROM habit_logs hl JOIN habits h ON h.id=hl.habit_id WHERE h.user_id=?");
$xp_habits->execute([$user_id]); $xp_from_habits = (int)$xp_habits->fetchColumn();

$xp_focus = $pdo->prepare("SELECT COALESCE(COUNT(*) * 5, 0) FROM sessions WHERE user_id=?");
$xp_focus->execute([$user_id]); $xp_from_focus = (int)$xp_focus->fetchColumn();

$total_xp  = $xp_from_tasks + $xp_from_habits + $xp_from_focus;
$xp_level  = max(1, (int)floor($total_xp / 100) + 1);
$xp_in_level = $total_xp % 100;
$xp_title  = $xp_level <= 2 ? 'Beginner' : ($xp_level <= 5 ? 'Focus Apprentice' : ($xp_level <= 10 ? 'Task Master' : 'Procrastination Slayer'));
?>

<div class="page-topbar">
<div class="page-title">Smart Insights</div>
<div style="display:flex;align-items:center;gap:10px;margin-left:auto">
    <a href="export_tasks.php"
       style="background:#7c6af7;border:none;border-radius:10px;color:#fff;
              padding:9px 18px;font-weight:600;font-size:14px;text-decoration:none;display:inline-flex;
              align-items:center;gap:6px">
        <i class="bi bi-download"></i> Export My Report
    </a>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
</div>
<div class="page-sub">Smart analysis of your study patterns and procrastination behaviour</div>

<!-- Risk Alert -->
<?php if ($overdue > 0): ?>
<div class="alert-danger mb-3">🚨 <b><?= $overdue ?> overdue task(s) detected!</b> High procrastination risk. Take action now.</div>
<?php elseif ($completion < 50): ?>
<div class="alert-warn mb-3">⚠️ <b>Task completion is low (<?= $completion ?>%).</b> Try breaking tasks into smaller steps.</div>
<?php else: ?>
<div class="alert-ok mb-3">✅ <b>You're doing great!</b> Keep maintaining your study habits.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <!-- Score ring -->
    <div class="col-md-4">
        <div class="card text-center" style="height:100%">
            <div class="card-title-sm">Procrastination Risk Score</div>
            <div style="position:relative;width:130px;height:130px;margin:10px auto">
                <svg width="130" height="130" viewBox="0 0 130 130">
                    <circle cx="65" cy="65" r="52" fill="none" stroke="#2a2a38" stroke-width="10"/>
                    <circle cx="65" cy="65" r="52" fill="none" stroke="<?= $risk_color ?>" stroke-width="10"
                        stroke-dasharray="326.7"
                        stroke-dashoffset="<?= 326.7 - (326.7 * $focus_score / 100) ?>"
                        stroke-linecap="round" transform="rotate(-90 65 65)"/>
                </svg>
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <div style="font-size:26px;font-weight:700;color:<?= $risk_color ?>"><?= $focus_score ?></div>
                    <div style="font-size:11px;color:var(--text-muted)">/ 100</div>
                </div>
            </div>
            <div style="font-size:14px;font-weight:700;color:<?= $risk_color ?>;margin-bottom:4px"><?= $risk ?> Risk</div>
            <div style="font-size:12px;color:var(--text-muted)">Based on your task &amp; focus data</div>
            <!-- Score formula explanation -->
            <details style="margin-top:10px;text-align:left;cursor:pointer;">
                <summary style="font-size:11px;color:var(--text-muted);list-style:none;display:flex;align-items:center;justify-content:center;gap:4px;">
                    <i class="bi bi-info-circle"></i> How is this calculated?
                </summary>
                <div style="font-size:11px;color:var(--text-muted);margin-top:8px;line-height:1.6;background:var(--bg-input);border-radius:8px;padding:8px 10px;text-align:left;">
                    <b>Score = Task Completion % + Focus Bonus (max 30 pts)</b><br>
                    Your task completion rate contributes up to 70 points. Daily focus minutes add up to 30 bonus points. Score ≥ 70 = Low Risk · 40–69 = Medium · below 40 = High.
                </div>
            </details>
        </div>
    </div>

    <!-- Key metrics -->
    <div class="col-md-8">
        <div class="card" style="height:100%">
            <div class="card-title-sm">Your Performance Metrics</div>
            <div style="display:flex;flex-direction:column;gap:14px">
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                        <span style="color:var(--text-muted)">Task Completion Rate</span>
                        <span style="font-weight:600;color:<?= $completion>=70?'#6af7b8':($completion>=40?'#f7c46a':'#f76a6a') ?>"><?= $completion ?>%</span>
                    </div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= $completion ?>%;background:#7c6af7"></div></div>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                        <span style="color:var(--text-muted)">Total Focus Time</span>
                        <span style="font-weight:600"><?= floor($total_mins/60) ?>h <?= $total_mins%60 ?>m</span>
                    </div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= min(100,round($total_mins/360*10)) ?>%;background:#6af7b8"></div></div>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                        <span style="color:var(--text-muted)">Habits Done Today</span>
                        <span style="font-weight:600"><?= $habits_today ?> / <?= $total_habits ?></span>
                    </div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= $total_habits>0?round(($habits_today/$total_habits)*100):0 ?>%;background:#f7c46a"></div></div>
                </div>
                <div style="display:flex;gap:20px;margin-top:4px">
                    <div style="text-align:center">
                        <div style="font-size:20px;font-weight:700;color:#7c6af7"><?= $total ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">Total Tasks</div>
                    </div>
                    <div style="text-align:center">
                        <div style="font-size:20px;font-weight:700;color:#6af7b8"><?= $done ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">Completed</div>
                    </div>
                    <div style="text-align:center">
                        <div style="font-size:20px;font-weight:700;color:#f76a6a"><?= $overdue ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">Overdue</div>
                    </div>
                    <div style="text-align:center">
                        <div style="font-size:20px;font-weight:700;color:#f7c46a"><?= $best_day ? $best_day['day'] : 'N/A' ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">Best Day</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Weekly chart -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-title-sm">Study Hours This Week</div>
            <canvas id="weeklyChart" height="160"></canvas>
        </div>
    </div>

    <!-- Urgent tasks -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-title-sm">⚠ Urgent — Overdue High Priority</div>
            <?php if (empty($urgent)): ?>
                <div style="text-align:center;padding:30px;color:#7a7a95"><i class="bi bi-check-circle" style="font-size:30px;display:block;margin-bottom:8px;color:#6af7b8"></i>No overdue high-priority tasks! 🎉</div>
            <?php else: ?>
                <?php foreach($urgent as $t): ?>
                <div style="padding:12px;background:var(--card-alt-bg,#16161d);border-radius:10px;margin-bottom:8px;border-left:3px solid #f76a6a">
                    <div style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($t['task_name']) ?></div>
                    <div style="font-size:12px;color:#f76a6a;margin-top:3px">⚠ Due <?= date('d M Y', strtotime($t['due_date'])) ?> · <?= $t['course'] ?></div>
                </div>
                <?php endforeach; ?>
                <a href="tasks.php" style="display:block;text-align:center;color:#7c6af7;font-size:13px;text-decoration:none;margin-top:8px;font-weight:500">View all tasks →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- AI Recommendations -->
<div class="card">
    <div class="card-title-sm">💡 Smart Recommendations For You</div>
    <div class="row g-3">
        <div class="col-md-6">
            <div style="background:linear-gradient(135deg,rgba(124,106,247,.08),rgba(106,247,184,.05));border:1px solid rgba(124,106,247,.2);border-radius:12px;padding:16px">
                <div style="font-size:18px;margin-bottom:8px">🧩</div>
                <div style="font-size:13px;line-height:1.6;color:var(--text-main)">
                    <?php if ($overdue > 0): ?>
                    You have <b><?= $overdue ?> overdue task(s)</b>. Break them into smaller 30-minute chunks. Start with the highest priority one right now.
                    <?php else: ?>
                    Great job staying on top of tasks! Try scheduling your hardest task first thing in the morning when focus is highest.
                    <?php endif; ?>
                </div>
                <span style="display:inline-block;margin-top:8px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(124,106,247,.15);color:#7c6af7">Task Strategy</span>
            </div>
        </div>
        <div class="col-md-6">
            <div style="background:linear-gradient(135deg,rgba(106,247,184,.06),rgba(124,106,247,.05));border:1px solid rgba(106,247,184,.2);border-radius:12px;padding:16px">
                <div style="font-size:18px;margin-bottom:8px">⏰</div>
                <div style="font-size:13px;line-height:1.6;color:var(--text-main)">
                    <?php if ($total_mins < 60): ?>
                    You've logged less than 1 hour of focus time. Try a <b>25-minute Pomodoro session</b> right now to build momentum.
                    <?php else: ?>
                    You've logged <b><?= floor($total_mins/60) ?> hours</b> of focus time. Keep scheduling daily sessions to maintain consistency.
                    <?php endif; ?>
                </div>
                <span style="display:inline-block;margin-top:8px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(106,247,184,.15);color:#6af7b8">Focus Tips</span>
            </div>
        </div>
        <div class="col-md-6">
            <div style="background:linear-gradient(135deg,rgba(247,196,106,.06),rgba(247,106,106,.04));border:1px solid rgba(247,196,106,.2);border-radius:12px;padding:16px">
                <div style="font-size:18px;margin-bottom:8px">📊</div>
                <div style="font-size:13px;line-height:1.6;color:var(--text-main)">
                    <?php if ($completion < 50): ?>
                    Your completion rate is <b><?= $completion ?>%</b>. Try the <b>"2-minute rule"</b> — if a task takes less than 2 minutes, do it immediately.
                    <?php else: ?>
                    Your completion rate is <b><?= $completion ?>%</b>. You're performing well! Challenge yourself to reach 80% this week.
                    <?php endif; ?>
                </div>
                <span style="display:inline-block;margin-top:8px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(247,196,106,.15);color:#f7c46a">Productivity</span>
            </div>
        </div>
        <div class="col-md-6">
            <div style="background:linear-gradient(135deg,rgba(247,106,106,.06),rgba(124,106,247,.04));border:1px solid rgba(247,106,106,.2);border-radius:12px;padding:16px">
                <div style="font-size:18px;margin-bottom:8px">🤝</div>
                <div style="font-size:13px;line-height:1.6;color:var(--text-main)">
                    <?php if ($habits_today < $total_habits && $total_habits > 0): ?>
                    You've only completed <b><?= $habits_today ?>/<?= $total_habits ?> habits</b> today. Tick off your remaining habits before the day ends!
                    <?php else: ?>
                    <b>All habits done today!</b> 🎉 Consistent habits reduce procrastination by up to 40%. Keep this streak going!
                    <?php endif; ?>
                </div>
                <span style="display:inline-block;margin-top:8px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(247,106,106,.15);color:#f76a6a">Habit Building</span>
            </div>
        </div>
    </div>
</div>

<!-- XP & Level Progress -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-title-sm">⭐ Your Study XP &amp; Level</div>
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px">
                <div style="width:56px;height:56px;border-radius:50%;background:rgba(124,106,247,.15);border:2px solid #7c6af7;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;">
                    <div style="font-size:18px;font-weight:700;color:#7c6af7;line-height:1"><?= $xp_level ?></div>
                    <div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Level</div>
                </div>
                <div style="flex:1">
                    <div style="font-size:14px;font-weight:600;color:var(--text-main)"><?= $xp_title ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px"><?= $total_xp ?> XP total</div>
                    <div style="height:7px;border-radius:4px;background:var(--border);">
                        <div style="height:100%;border-radius:4px;background:linear-gradient(90deg,#7c6af7,#6af7b8);width:<?= $xp_in_level ?>%;transition:width .6s ease;"></div>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $xp_in_level ?>/100 XP to next level</div>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <div style="flex:1;min-width:80px;background:var(--bg-input,#16161d);border-radius:8px;padding:8px 10px;text-align:center">
                    <div style="font-size:16px;font-weight:700;color:#7c6af7"><?= $xp_from_tasks ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">From Tasks</div>
                </div>
                <div style="flex:1;min-width:80px;background:var(--bg-input,#16161d);border-radius:8px;padding:8px 10px;text-align:center">
                    <div style="font-size:16px;font-weight:700;color:#6af7b8"><?= $xp_from_habits ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">From Habits</div>
                </div>
                <div style="flex:1;min-width:80px;background:var(--bg-input,#16161d);border-radius:8px;padding:8px 10px;text-align:center">
                    <div style="font-size:16px;font-weight:700;color:#f7c46a"><?= $xp_from_focus ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">From Focus</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contextual Procrastination Tips -->
    <div class="col-md-6">
        <div class="card" style="height:100%">
            <div class="card-title-sm">🧠 Procrastination Tips for You</div>
            <?php
            // Contextual tips based on current risk level
            $tips = [];
            if ($risk === 'High') {
                $tips = [
                    ['icon'=>'🔴','tip'=>'Start with the <b>smallest possible step</b> — even 5 minutes counts. Momentum beats perfection.'],
                    ['icon'=>'📢','tip'=>'<b>Tell a friend your deadline</b>. Social accountability doubles follow-through rates.'],
                    ['icon'=>'🚫','tip'=>'Remove one digital distraction right now — put your phone in another room for 30 minutes.'],
                ];
            } elseif ($risk === 'Medium') {
                $tips = [
                    ['icon'=>'🍅','tip'=>'Try a <b>Pomodoro session</b> (25 min focus, 5 min break) to push your score into the low-risk zone.'],
                    ['icon'=>'📋','tip'=>'<b>Break your next task</b> into 3 sub-steps and write them down before starting.'],
                    ['icon'=>'⏰','tip'=>'Schedule your most important task for <b>tomorrow morning</b> before checking any messages.'],
                ];
            } else {
                $tips = [
                    ['icon'=>'✅','tip'=>'<b>Excellent momentum!</b> Challenge yourself to hit <?= min(100, $completion + 10) ?>% task completion this week.'],
                    ['icon'=>'🔥','tip'=>'Keep your habit streak alive — <b>consistency compounds</b>. Missing one day doubles your chance of missing the next.'],
                    ['icon'=>'🎯','tip'=>'Consider adding a <b>stretch goal</b> this week — high performers grow by pushing just beyond their comfort zone.'],
                ];
            }
            foreach ($tips as $t): ?>
            <div style="display:flex;gap:10px;align-items:flex-start;padding:10px;background:var(--bg-input,#16161d);border-radius:8px;margin-bottom:8px">
                <span style="font-size:18px;flex-shrink:0"><?= $t['icon'] ?></span>
                <div style="font-size:12.5px;color:var(--text-main);line-height:1.6"><?= $t['tip'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('weeklyChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($weekly_labels) ?>,
        datasets: [{
            label: 'Hours',
            data: <?= json_encode($weekly_data) ?>,
            borderColor: '#7c6af7',
            backgroundColor: 'rgba(124,106,247,0.1)',
            borderWidth: 2,
            pointBackgroundColor: '#7c6af7',
            pointRadius: 5,
            tension: 0.4,
            fill: true
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

<?php require_once 'includes/footer.php'; ?>
