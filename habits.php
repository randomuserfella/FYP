<?php
require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/csrf.php';
date_default_timezone_set('Asia/Kuala_Lumpur'); // Malaysia time — change if needed
$pageTitle = 'Habits';
require_once 'config/db.php';

$user_id = $_SESSION['user_id'];

// Add habit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='add') {
    csrf_verify();
    $name       = trim($_POST['habit_name']);
    $is_private = isset($_POST['is_private']) ? 1 : 0;
    if (!empty($name)) {
        $pdo->prepare("INSERT INTO habits (user_id, habit_name, is_private) VALUES (?,?,?)")->execute([$user_id, $name, $is_private]);
    }
    header('Location: habits.php'); exit;
}

// Mark habit done today — one way only, cannot undo
if (isset($_GET['toggle'])) {
    $hid = (int)$_GET['toggle'];
    // Check ownership
    $stmt = $pdo->prepare("SELECT id FROM habits WHERE id=? AND user_id=?");
    $stmt->execute([$hid, $user_id]);
    if ($stmt->fetch()) {
        // Only insert if not already done today
        $stmt = $pdo->prepare("SELECT id FROM habit_logs WHERE habit_id=? AND log_date=CURDATE()");
        $stmt->execute([$hid]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO habit_logs (habit_id, log_date) VALUES (?,CURDATE())")->execute([$hid]);
        }
    }
    header('Location: habits.php'); exit;
}

// Delete habit
if (isset($_GET['delete'])) {
    $hid = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM habit_logs WHERE habit_id=?")->execute([$hid]);
    $pdo->prepare("DELETE FROM habits WHERE id=? AND user_id=?")->execute([$hid, $user_id]);
    header('Location: habits.php'); exit;
}

require_once 'includes/header.php';

// Fetch habits with last 7 days log
$stmt = $pdo->prepare("SELECT * FROM habits WHERE user_id=? ORDER BY id ASC");
$stmt->execute([$user_id]);
$habits = $stmt->fetchAll();

// For each habit get last 7 days
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime("-$i days"));
}

// Fetch ALL habit logs in ONE query (last 60 days covers any realistic streak)
// This replaces the old N+1 loop that ran 300+ DB queries for 10 habits.
$allLogsStmt = $pdo->prepare("
    SELECT habit_id, log_date FROM habit_logs
    WHERE habit_id IN (SELECT id FROM habits WHERE user_id=?)
      AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    ORDER BY log_date DESC
");
$allLogsStmt->execute([$user_id]);
$logsByHabit = [];
foreach ($allLogsStmt->fetchAll() as $row) {
    $logsByHabit[$row['habit_id']][] = $row['log_date'];
}

$done_today = 0;
$habit_data = [];
$today = date('Y-m-d');

foreach ($habits as $h) {
    $hid    = $h['id'];
    $logs   = $logsByHabit[$hid] ?? [];   // sorted DESC by query
    $logSet = array_flip($logs);           // fast O(1) lookup

    // Streak: count consecutive days backwards from today (no DB calls)
    $streak   = 0;
    $checkDay = $today;
    while (isset($logSet[$checkDay])) {
        $streak++;
        $checkDay = date('Y-m-d', strtotime($checkDay . ' -1 day'));
    }

    $today_done = isset($logSet[$today]);
    if ($today_done) $done_today++;

    // Grid logs: only last 7 days (for the dot-grid display)
    $week_start = $days[0];
    $grid_logs  = array_values(array_filter($logs, fn($d) => $d >= $week_start));

    $habit_data[] = ['habit' => $h, 'logs' => $grid_logs, 'streak' => $streak, 'today' => $today_done];
}

$total_habits = count($habits);
$completion = $total_habits > 0 ? round(($done_today / $total_habits) * 100) : 0;
?>

<div class="page-topbar">
<div class="page-title">Habit Tracker</div>
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" style="margin-left:auto"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Build consistent study habits — track your daily streaks</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card"><div class="stat-val" style="color:#7c6af7"><?= $total_habits ?></div><div class="stat-label">Total Habits</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-val" style="color:#6af7b8"><?= $done_today ?></div><div class="stat-label">Done Today</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-val" style="color:#f7c46a"><?= $completion ?>%</div><div class="stat-label">Today's Rate</div></div></div>
</div>

<!-- Add Habit -->
<div class="card mb-4">
    <div class="card-title-sm">➕ Add New Habit</div>
    <form method="POST" action="habits.php">
        <input type="hidden" name="action" value="add">
        <?= csrf_field() ?>
        <div style="display:flex;gap:10px">
            <input type="text" name="habit_name" class="form-control" placeholder="e.g. Review lecture notes for 20 min"
                style="flex:1;background:#16161d;border:1px solid #2a2a38;color:#e8e8f0;border-radius:10px;padding:10px 14px;" required>
            <button type="submit" style="background:#7c6af7;border:none;border-radius:10px;color:#fff;font-weight:600;padding:10px 20px;cursor:pointer;white-space:nowrap">
                + Add Habit
            </button>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="habitPrivate" name="is_private" value="1">
            <label class="form-check-label" for="habitPrivate" style="font-size:12px;color:var(--text-muted);cursor:pointer;">
                <i class="bi bi-lock-fill"></i> Keep this habit private (hidden from your lecturer's reports)
            </label>
        </div>
    </form>
</div>

<!-- Habit List -->
<div class="card">
    <div class="card-title-sm">Your Habits &mdash; <?= date('D, d M', strtotime('-6 days')) ?> &rarr; <?= date('D, d M Y') ?></div>

    <!-- Day headers -->
    <div style="display:grid;grid-template-columns:1fr repeat(7,40px) 60px 40px;gap:6px;align-items:center;padding:0 0 10px;border-bottom:1px solid var(--border);margin-bottom:4px">
        <div style="font-size:12px;color:var(--text-muted)">Habit</div>
        <?php foreach($days as $d):
            $isHdrToday = $d === date('Y-m-d');
        ?>
        <div style="font-size:11px;text-align:center;<?= $isHdrToday ? 'color:#7c6af7;font-weight:700' : 'color:var(--text-muted)' ?>">
            <?= date('D', strtotime($d)) ?><br>
            <span style="font-size:11px;<?= $isHdrToday ? 'background:#7c6af7;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;margin-top:2px' : 'color:var(--text-muted)' ?>">
                <?= date('j', strtotime($d)) ?>
            </span>
        </div>
        <?php endforeach; ?>
        <div style="font-size:11px;color:var(--text-muted);text-align:center">Streak</div>
        <div></div>
    </div>

    <?php if (empty($habit_data)): ?>
        <div style="text-align:center;padding:40px;color:#7a7a95">
            <i class="bi bi-calendar2-check" style="font-size:36px;display:block;margin-bottom:10px"></i>
            No habits yet. Add one above!
        </div>
    <?php else: ?>
        <?php foreach ($habit_data as $hd): $h=$hd['habit']; ?>
        <div style="display:grid;grid-template-columns:1fr repeat(7,40px) 60px 40px;gap:6px;align-items:center;padding:12px 0;border-bottom:1px solid #2a2a38">
            <div style="font-size:13px;font-weight:500;color:var(--text-main)">
                <?= htmlspecialchars($h['habit_name']) ?>
                <?php if (!empty($h['is_private'])): ?>
                    <i class="bi bi-lock-fill" style="font-size:10px;color:var(--text-muted);margin-left:4px" title="Private — hidden from lecturer"></i>
                <?php endif; ?>
            </div>
            <?php foreach($days as $d):
                $isToday = $d === $today;
                // Use pre-loaded data — no DB queries here
                $isDone  = $isToday ? $hd['today'] : in_array($d, $hd['logs']);
            ?>
            <div style="text-align:center">
                <?php if ($isToday && !$isDone): ?>
                <!-- Pending today — clickable -->
                <a href="habits.php?toggle=<?= $h['id'] ?>" style="text-decoration:none"
                   onclick="return confirm('Mark this habit as done for today? This cannot be undone.')">
                    <div style="width:28px;height:28px;border-radius:7px;margin:0 auto;display:flex;align-items:center;justify-content:center;
                        background:transparent;border:2px solid var(--text-muted);cursor:pointer;transition:all .2s"
                        onmouseover="this.style.borderColor='#6af7b8'" onmouseout="this.style.borderColor='var(--text-muted)'">
                    </div>
                </a>
                <?php elseif ($isToday && $isDone): ?>
                <!-- Done today — locked, no link -->
                <div style="width:28px;height:28px;border-radius:7px;margin:0 auto;display:flex;align-items:center;justify-content:center;
                    background:#6af7b8;border:2px solid #6af7b8;cursor:default;"
                    title="Done today! ✓ Cannot undo — stays locked until tomorrow">
                    <i class="bi bi-check-lg" style="color:#000;font-size:14px;font-weight:900;"></i>
                </div>
                <?php else: ?>
                <!-- Past day -->
                <div style="width:28px;height:28px;border-radius:7px;margin:0 auto;display:flex;align-items:center;justify-content:center;
                    background:<?= $isDone?'rgba(106,247,184,.15)':'transparent' ?>;border:1px solid <?= $isDone?'#6af7b8':'var(--border)' ?>">
                    <?php if($isDone): ?><i class="bi bi-check-lg" style="color:#6af7b8;font-size:13px;"></i><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div style="text-align:center;font-size:13px;font-weight:600;color:#f7c46a">
                🔥 <?= $hd['streak'] ?>d
            </div>
            <a href="habits.php?delete=<?= $h['id'] ?>" onclick="return confirm('Delete this habit?')"
               style="text-align:center;color:#f76a6a;text-decoration:none;font-size:13px">
                <i class="bi bi-trash"></i>
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
