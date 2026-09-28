<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Weekly Report';
require_once 'config/db.php';
require_once 'includes/lecturer_header.php';

// ── All data scoped to this lecturer's university ─────────────────────

// Students with ZERO tasks this week (procrastination flag)
$zeroTasksStmt = $pdo->prepare("
    SELECT u.name, u.email
    FROM users u
    LEFT JOIN tasks t ON t.user_id = u.id
        AND t.due_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    WHERE u.role='student' AND u.university=?
    GROUP BY u.id, u.name, u.email
    HAVING COUNT(t.id) = 0
    ORDER BY u.name ASC
");
$zeroTasksStmt->execute([$lec_university]);
$zero_task_students = $zeroTasksStmt->fetchAll();

// Top 5 most overdue students
$mostOverdueStmt = $pdo->prepare("
    SELECT u.name,
        COUNT(t.id) as overdue_count
    FROM users u
    JOIN tasks t ON t.user_id = u.id
    WHERE u.role='student' AND u.university=?
      AND t.status='pending' AND t.due_date < CURDATE()
    GROUP BY u.id, u.name
    ORDER BY overdue_count DESC
    LIMIT 5
");
$mostOverdueStmt->execute([$lec_university]);
$most_overdue = $mostOverdueStmt->fetchAll();

// Average habit completion rate across all students this week
$habitRateStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT h.id) as total_habits,
        COUNT(DISTINCT CASE WHEN hl.log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN hl.id END) as weekly_logs
    FROM habits h
    JOIN users u ON h.user_id = u.id
    LEFT JOIN habit_logs hl ON hl.habit_id = h.id
    WHERE u.role='student' AND u.university=? AND h.is_private=0
");
$habitRateStmt->execute([$lec_university]);
$habit_rate_row = $habitRateStmt->fetch();
$habit_completion_pct = ($habit_rate_row['total_habits'] > 0 && $habit_rate_row['weekly_logs'] > 0)
    ? min(100, round(($habit_rate_row['weekly_logs'] / ($habit_rate_row['total_habits'] * 7)) * 100))
    : 0;

// Daily focus minutes this week (for chart)
$focusWeekStmt = $pdo->prepare("
    SELECT DATE(s.session_date) as day, COALESCE(SUM(s.duration_minutes),0) as total_mins
    FROM sessions s
    JOIN users u ON s.user_id = u.id
    WHERE u.university=? AND s.session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(s.session_date)
    ORDER BY day ASC
");
$focusWeekStmt->execute([$lec_university]);
$focus_week_raw = $focusWeekStmt->fetchAll();

$focus_week = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $focus_week[$d] = 0;
}
foreach ($focus_week_raw as $r) {
    $focus_week[$r['day']] = round($r['total_mins'] / 60, 1);
}
$focus_labels = array_map(fn($d) => date('D d/M', strtotime($d)), array_keys($focus_week));
$focus_data   = array_values($focus_week);

// Summary stats
$s1 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND university=?");
$s1->execute([$lec_university]); $total_students = $s1->fetchColumn();

$s2 = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.university=? AND t.due_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$s2->execute([$lec_university]); $tasks_this_week = $s2->fetchColumn();

$s3 = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.university=? AND t.status='done' AND t.due_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$s3->execute([$lec_university]); $completed_this_week = $s3->fetchColumn();

$s4 = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.university=? AND t.status='pending' AND t.due_date < CURDATE()");
$s4->execute([$lec_university]); $total_overdue = $s4->fetchColumn();
?>

<div class="page-topbar">
    <div class="page-title">Weekly Report</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">
    7-day summary for <?= htmlspecialchars($lec_university ?: 'your institution') ?> · Week of <?= date('d M', strtotime('-6 days')) ?> – <?= date('d M Y') ?>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #7c6af7">
            <div class="stat-val" style="color:#7c6af7"><?= $total_students ?></div>
            <div class="stat-label">Total Students</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #6af7b8">
            <div class="stat-val" style="color:#6af7b8"><?= $completed_this_week ?></div>
            <div class="stat-label">Tasks Completed This Week</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #f76a6a">
            <div class="stat-val" style="color:#f76a6a"><?= $total_overdue ?></div>
            <div class="stat-label">Total Overdue Tasks</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #f7c46a">
            <div class="stat-val" style="color:#f7c46a"><?= $habit_completion_pct ?>%</div>
            <div class="stat-label">Avg Habit Completion</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Focus chart -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-title-sm">📊 Total Focus Hours Per Day (All Students)</div>
            <canvas id="focusChart" height="120"></canvas>
        </div>
    </div>

    <!-- At-risk students: no tasks this week -->
    <div class="col-md-5">
        <div class="card" style="height:100%">
            <div class="card-title-sm">⚠ At Risk — No Tasks Added This Week</div>
            <?php if (empty($zero_task_students)): ?>
                <div style="text-align:center;padding:30px;color:var(--text-muted)">
                    <i class="bi bi-check-circle" style="font-size:28px;display:block;margin-bottom:8px;color:#6af7b8"></i>
                    All students added tasks this week!
                </div>
            <?php else: ?>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                    <?= count($zero_task_students) ?> student(s) with no activity — consider following up.
                </div>
                <?php foreach ($zero_task_students as $s): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(247,106,106,.06);border:1px solid rgba(247,106,106,.15);border-radius:8px;margin-bottom:6px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:rgba(247,106,106,.15);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#f76a6a;flex-shrink:0;">
                        <?= strtoupper(substr($s['name'],0,1)) ?>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($s['name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($s['email']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Most overdue + habit rate -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-title-sm">🔴 Students With Most Overdue Tasks</div>
            <?php if (empty($most_overdue)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)">No overdue tasks — great!</div>
            <?php else: ?>
            <div class="table-scroll">
            <table class="lec-table">
                <thead><tr><th>#</th><th>Student</th><th>Overdue Tasks</th></tr></thead>
                <tbody>
                <?php foreach ($most_overdue as $i => $s): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($s['name']) ?></td>
                    <td><span class="pill pill-red"><?= $s['overdue_count'] ?> overdue</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-title-sm">🌿 Habit Completion Summary (This Week)</div>
            <div style="text-align:center;padding:20px 0">
                <div style="position:relative;width:120px;height:120px;margin:0 auto 12px;">
                    <svg width="120" height="120" viewBox="0 0 120 120">
                        <circle cx="60" cy="60" r="48" fill="none" stroke="var(--border)" stroke-width="10"/>
                        <circle cx="60" cy="60" r="48" fill="none"
                            stroke="<?= $habit_completion_pct >= 70 ? '#6af7b8' : ($habit_completion_pct >= 40 ? '#f7c46a' : '#f76a6a') ?>"
                            stroke-width="10"
                            stroke-dasharray="301.6"
                            stroke-dashoffset="<?= 301.6 - (301.6 * $habit_completion_pct / 100) ?>"
                            stroke-linecap="round"
                            transform="rotate(-90 60 60)"/>
                    </svg>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                        <div style="font-size:24px;font-weight:700;color:var(--text-main)"><?= $habit_completion_pct ?>%</div>
                        <div style="font-size:11px;color:var(--text-muted)">this week</div>
                    </div>
                </div>
                <div style="font-size:13px;color:var(--text-muted);">
                    <?= $habit_rate_row['weekly_logs'] ?> habit check-ins logged across <?= $habit_rate_row['total_habits'] ?> tracked habits
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const focusCtx = document.getElementById('focusChart').getContext('2d');
new Chart(focusCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($focus_labels) ?>,
        datasets: [{
            label: 'Focus Hours',
            data: <?= json_encode($focus_data) ?>,
            backgroundColor: 'rgba(124,106,247,.45)',
            borderColor: '#7c6af7',
            borderWidth: 1,
            borderRadius: 5
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#7a7a95', font: { size: 11 } },
                grid: { color: 'rgba(255,255,255,.05)' }
            },
            x: {
                ticks: { color: '#7a7a95', font: { size: 11 } },
                grid: { display: false }
            }
        }
    }
});
</script>

<?php require_once 'includes/lecturer_footer.php'; ?>
