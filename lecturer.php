<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Overview';
require_once 'config/db.php';
require_once 'includes/lecturer_header.php';

// ── Stats (scoped to this lecturer's own university — never trust GET/POST,
//    $lec_university is fetched fresh from the DB in lecturer_header.php) ──
$total_students = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND university=?");
$total_students->execute([$lec_university]);
$total_students = $total_students->fetchColumn();

$total_tasks = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.role='student' AND u.university=? AND t.is_private=0");
$total_tasks->execute([$lec_university]);
$total_tasks = $total_tasks->fetchColumn();

$done_tasks = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.role='student' AND u.university=? AND t.is_private=0 AND t.status='done'");
$done_tasks->execute([$lec_university]);
$done_tasks = $done_tasks->fetchColumn();

$overdue_tasks = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN users u ON t.user_id=u.id WHERE u.role='student' AND u.university=? AND t.is_private=0 AND t.status='pending' AND t.due_date < CURDATE()");
$overdue_tasks->execute([$lec_university]);
$overdue_tasks = $overdue_tasks->fetchColumn();

$total_habits = $pdo->prepare("SELECT COUNT(*) FROM habits h JOIN users u ON h.user_id=u.id WHERE u.role='student' AND u.university=? AND h.is_private=0");
$total_habits->execute([$lec_university]);
$total_habits = $total_habits->fetchColumn();

$habits_today = $pdo->prepare("SELECT COUNT(DISTINCT hl.habit_id) FROM habit_logs hl JOIN habits h ON hl.habit_id=h.id JOIN users u ON h.user_id=u.id WHERE u.role='student' AND u.university=? AND hl.log_date=CURDATE() AND h.is_private=0");
$habits_today->execute([$lec_university]);
$habits_today = $habits_today->fetchColumn();

$focus_today = $pdo->prepare("SELECT COALESCE(SUM(s.duration_minutes),0) FROM sessions s JOIN users u ON s.user_id=u.id WHERE u.role='student' AND u.university=? AND s.session_date=CURDATE()");
$focus_today->execute([$lec_university]);
$focus_today = $focus_today->fetchColumn();

$completion_rate = $total_tasks > 0 ? round(($done_tasks / $total_tasks) * 100) : 0;

// ── Recent activity (last 8 tasks — private excluded, own university only) ─
$recent = $pdo->prepare("
    SELECT t.task_name, t.priority, t.status, t.due_date, u.name as student_name
    FROM tasks t JOIN users u ON t.user_id = u.id
    WHERE u.role='student' AND u.university=? AND t.is_private=0
    ORDER BY t.id DESC LIMIT 8
");
$recent->execute([$lec_university]);
$recent = $recent->fetchAll();

// ── Top students by completion (private tasks excluded, own university only) ─
$top_students = $pdo->prepare("
    SELECT u.name,
        COUNT(t.id) as total,
        SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done,
        ROUND(SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) / COUNT(t.id) * 100) as rate
    FROM users u
    LEFT JOIN tasks t ON u.id = t.user_id AND t.is_private=0
    WHERE u.role='student' AND u.university=?
    GROUP BY u.id, u.name
    HAVING total > 0
    ORDER BY rate DESC
    LIMIT 5
");
$top_students->execute([$lec_university]);
$top_students = $top_students->fetchAll();
?>

<div class="page-topbar">
    <div class="page-title">Overview</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Welcome back, <?= htmlspecialchars($lec_name) ?> 👋 — Here's what your students are up to.</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #7c6af7">
            <div class="stat-val" style="color:#7c6af7"><?= $total_students ?></div>
            <div class="stat-label">Total Students</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #6af7b8">
            <div class="stat-val" style="color:#6af7b8"><?= $completion_rate ?>%</div>
            <div class="stat-label">Avg Task Completion</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #f76a6a">
            <div class="stat-val" style="color:#f76a6a"><?= $overdue_tasks ?></div>
            <div class="stat-label">Overdue Tasks</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #f7c46a">
            <div class="stat-val" style="color:#f7c46a"><?= round($focus_today) ?>m</div>
            <div class="stat-label">Focus Time Today</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent tasks -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-title-sm">📋 Recent Task Activity</div>
            <?php if (empty($recent)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)">No tasks yet.</div>
            <?php else: ?>
            <div class="table-scroll">
<table class="lec-table">
                <thead><tr>
                    <th>Student</th><th>Task</th><th>Priority</th><th>Status</th><th>Due</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent as $r):
                    $pc = $r['priority']==='high'?'pill-red':($r['priority']==='medium'?'pill-yellow':'pill-green');
                    $sc = $r['status']==='done'?'pill-green':'pill-yellow';
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['student_name']) ?></td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['task_name']) ?></td>
                    <td><span class="pill <?= $pc ?>"><?= ucfirst($r['priority']) ?></span></td>
                    <td><span class="pill <?= $sc ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td style="color:var(--text-muted)"><?= $r['due_date'] ? date('d M', strtotime($r['due_date'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
</div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Top students -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-title-sm">🏆 Top Students by Completion</div>
            <?php if (empty($top_students)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)">No data yet.</div>
            <?php else: ?>
            <?php foreach ($top_students as $i => $s): ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:26px;height:26px;border-radius:50%;background:rgba(124,106,247,.15);
                    color:#7c6af7;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <?= $i+1 ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= htmlspecialchars($s['name']) ?>
                    </div>
                    <div style="height:5px;border-radius:3px;background:var(--border);margin-top:5px;">
                        <div style="height:100%;border-radius:3px;background:#7c6af7;width:<?= $s['rate'] ?>%"></div>
                    </div>
                </div>
                <div style="font-size:13px;font-weight:700;color:#7c6af7;flex-shrink:0"><?= $s['rate'] ?>%</div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/lecturer_footer.php'; ?>
