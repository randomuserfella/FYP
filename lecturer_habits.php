<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Habit Reports';
require_once 'config/db.php';
require_once 'includes/lecturer_header.php';

$hStmt = $pdo->prepare("
    SELECT h.id, h.habit_name, u.name as student_name,
        COUNT(hl.id) as total_logs,
        MAX(hl.log_date) as last_logged,
        SUM(CASE WHEN hl.log_date = CURDATE() THEN 1 ELSE 0 END) as done_today,
        (SELECT COUNT(DISTINCT hl2.habit_id) FROM habit_logs hl2 WHERE hl2.habit_id=h.id AND hl2.log_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) as active_days
    FROM habits h
    JOIN users u ON h.user_id = u.id
    LEFT JOIN habit_logs hl ON h.id = hl.habit_id
    WHERE u.role='student' AND u.university=? AND h.is_private = 0
    GROUP BY h.id, h.habit_name, u.name
    ORDER BY total_logs DESC
");
$hStmt->execute([$lec_university]);
$habits = $hStmt->fetchAll();

$total_habits = count($habits);
$done_today   = array_sum(array_column($habits, 'done_today'));
$total_logs   = array_sum(array_column($habits, 'total_logs'));
?>

<div class="page-topbar">
    <div class="page-title">Habit Reports</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Track which habits students are building and how consistently.</div>

<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #7c6af7">
        <div class="stat-val" style="color:#7c6af7"><?= $total_habits ?></div>
        <div class="stat-label">Total Habits</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #6af7b8">
        <div class="stat-val" style="color:#6af7b8"><?= $done_today ?></div>
        <div class="stat-label">Completed Today</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #f7c46a">
        <div class="stat-val" style="color:#f7c46a"><?= $total_logs ?></div>
        <div class="stat-label">Total Check-ins</div>
    </div></div>
</div>

<div class="card" style="padding:0 0 8px 0;overflow:hidden;">
    <?php if (empty($habits)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">No habits tracked yet.</div>
    <?php else: ?>
    <div class="table-scroll">
<table class="lec-table">
        <thead><tr>
            <th>Student</th><th>Habit</th><th>Total Check-ins</th><th>Active Days (7d)</th><th>Done Today</th><th>Last Logged</th>
        </tr></thead>
        <tbody>
        <?php foreach ($habits as $h): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($h['student_name']) ?></td>
            <td><?= htmlspecialchars($h['habit_name']) ?></td>
            <td><span class="pill pill-purple"><?= $h['total_logs'] ?>×</span></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:5px;border-radius:3px;background:var(--border);">
                        <div style="height:100%;border-radius:3px;background:#6af7b8;width:<?= min(100, $h['active_days']/7*100) ?>%"></div>
                    </div>
                    <span style="font-size:12px;color:#6af7b8;font-weight:600"><?= $h['active_days'] ?>/7</span>
                </div>
            </td>
            <td><?= $h['done_today'] ? '<span class="pill pill-green">✓ Done</span>' : '<span class="pill pill-yellow">Not yet</span>' ?></td>
            <td style="color:var(--text-muted)"><?= $h['last_logged'] ? date('d M Y', strtotime($h['last_logged'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
    <?php endif; ?>
</div>

<?php require_once 'includes/lecturer_footer.php'; ?>
