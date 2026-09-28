<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Tasks';
require_once 'config/db.php';

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// Add task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $task_name = trim($_POST['task_name']);
    $course    = trim($_POST['course']);
    $priority  = $_POST['priority'] ?? '';
    $due_date   = $_POST['due_date'];
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
    $due_time   = !empty($_POST['due_time'])   ? $_POST['due_time']   : null;
    $is_private = isset($_POST['is_private']) ? 1 : 0;
    if (!empty($task_name) && !empty($due_date) && !empty($priority)) {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, task_name, course, priority, due_date, start_date, start_time, due_time, is_private) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$user_id, $task_name, $course, $priority, $due_date, $start_date, $start_time, $due_time, $is_private]);
        $msg = 'Task added successfully!'; $msg_type = 'ok';
    } else { $msg = 'Please fill in task name, due date, and priority.'; $msg_type = 'danger'; }
}

// Mark task complete (AJAX — used by Timer's Auto-mark-done)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_complete') {
    $tid = (int)($_POST['id'] ?? 0);
    $ok = false;
    if ($tid > 0) {
        $stmt = $pdo->prepare("UPDATE tasks SET status='done' WHERE id=? AND user_id=? AND (status='pending' OR status IS NULL OR status='')");
        $stmt->execute([$tid, $user_id]);
        $ok = $stmt->rowCount() > 0;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

// Mark done
if (isset($_GET['toggle'])) {
    $tid = (int)$_GET['toggle'];
    // Only mark pending → done, never reverse
    $pdo->prepare("UPDATE tasks SET status='done' WHERE id=? AND user_id=? AND (status='pending' OR status IS NULL OR status='')")
        ->execute([$tid, $user_id]);
    $back = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    header('Location: ' . tab_url('tasks.php?filter=' . urlencode($back) . '&toast=done', $GLOBALS['tab_id'])); exit;
}

// Clear all completed tasks (bulk delete) — Done tasks remain visible under the
// "Done" filter as history until the user explicitly clears them here.
if (isset($_GET['clear_done'])) {
    $pdo->prepare("DELETE FROM tasks WHERE user_id=? AND status='done' AND archived_at IS NULL")->execute([$user_id]);
    header('Location: ' . tab_url('tasks.php?filter=done&toast=cleared', $GLOBALS['tab_id'])); exit;
}

// Archive a completed task — moves it out of the normal Done list into
// the Archived view. Preserves the row (unlike delete/clear_done).
if (isset($_GET['archive'])) {
    $tid = (int)$_GET['archive'];
    $pdo->prepare("UPDATE tasks SET archived_at=NOW() WHERE id=? AND user_id=? AND status='done'")
        ->execute([$tid, $user_id]);
    $back = isset($_GET['filter']) ? $_GET['filter'] : 'done';
    header('Location: ' . tab_url('tasks.php?filter=' . urlencode($back) . '&toast=archived', $GLOBALS['tab_id'])); exit;
}

// Restore an archived task back to the normal Done list
if (isset($_GET['unarchive'])) {
    $tid = (int)$_GET['unarchive'];
    $pdo->prepare("UPDATE tasks SET archived_at=NULL WHERE id=? AND user_id=?")
        ->execute([$tid, $user_id]);
    $back = isset($_GET['filter']) ? $_GET['filter'] : 'archived';
    header('Location: ' . tab_url('tasks.php?filter=' . urlencode($back) . '&toast=unarchived', $GLOBALS['tab_id'])); exit;
}

// Delete task
if (isset($_GET['delete'])) {
    $tid = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM tasks WHERE id=? AND user_id=?")->execute([$tid,$user_id]);
    $back = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    header('Location: ' . tab_url('tasks.php?filter=' . urlencode($back) . '&toast=deleted', $GLOBALS['tab_id'])); exit;
}

// ── Resolve corner-toast message for this page load ─────────────────────────
// Either a flash from a redirect (?toast=xxx) or the inline $msg set by the
// "add task" handler above (that path doesn't redirect, so $msg is used as-is).
$toast_map = [
    'done'       => ['Task marked as done!', 'success'],
    'deleted'    => ['Task deleted.', 'error'],
    'archived'   => ['Task archived.', 'success'],
    'unarchived' => ['Task restored.', 'success'],
    'cleared'    => ['Completed tasks cleared.', 'success'],
];
$toast_text = $toast_type = null;
if ($msg) {
    $toast_text = $msg;
    $toast_type = $msg_type === 'ok' ? 'success' : 'error';
} elseif (isset($_GET['toast']) && isset($toast_map[$_GET['toast']])) {
    [$toast_text, $toast_type] = $toast_map[$_GET['toast']];
}

// Fetch tasks
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$sql = "SELECT * FROM tasks WHERE user_id=?";
if ($filter === 'archived') {
    $sql .= " AND archived_at IS NOT NULL";
} else {
    $sql .= " AND archived_at IS NULL";
    if ($filter === 'pending')  $sql .= " AND status='pending'";
    if ($filter === 'done')     $sql .= " AND status='done'";
    if ($filter === 'overdue')  $sql .= " AND status='pending' AND due_date < CURDATE()";
    if ($filter === 'due_soon') $sql .= " AND status='pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
    if ($filter === 'private')  $sql .= " AND is_private=1";
}
$sql .= $filter === 'archived'
    ? " ORDER BY archived_at DESC"
    : " ORDER BY FIELD(priority,'high','medium','low'), due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$tasks = $stmt->fetchAll();

// Counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND archived_at IS NULL"); $stmt->execute([$user_id]); $total = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='done' AND archived_at IS NULL"); $stmt->execute([$user_id]); $done = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='pending' AND due_date < CURDATE() AND archived_at IS NULL"); $stmt->execute([$user_id]); $overdue = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='pending' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND archived_at IS NULL"); $stmt->execute([$user_id]); $due_soon = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND is_private=1 AND archived_at IS NULL"); $stmt->execute([$user_id]); $private_count = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND archived_at IS NOT NULL"); $stmt->execute([$user_id]); $archived_count = $stmt->fetchColumn();

require_once 'includes/header.php';
?>

<div class="page-topbar">
<div class="page-title">Task Manager</div>
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" style="margin-left:auto"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Track and manage all your assignments and tasks</div>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-sm-4 col-4">
        <div class="stat-card" style="border-left:4px solid #7c6af7;display:flex;align-items:center;gap:12px;">
            <div class="stat-icon" style="background:rgba(124,106,247,0.15);flex-shrink:0;">
                <i class="bi bi-list-task" style="font-size:20px;color:#7c6af7"></i>
            </div>
            <div style="min-width:0;flex:1;">
                <div class="stat-val" style="color:#7c6af7"><?= $total ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 col-4">
        <div class="stat-card" style="border-left:4px solid #6af7b8;display:flex;align-items:center;gap:12px;">
            <div class="stat-icon" style="background:rgba(106,247,184,0.15);flex-shrink:0;">
                <i class="bi bi-check-circle-fill" style="font-size:20px;color:#6af7b8"></i>
            </div>
            <div style="min-width:0;flex:1;">
                <div class="stat-val" style="color:#6af7b8"><?= $done ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 col-4">
        <div class="stat-card" style="border-left:4px solid #f76a6a;display:flex;align-items:center;gap:12px;">
            <div class="stat-icon" style="background:rgba(247,106,106,0.15);flex-shrink:0;">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:20px;color:#f76a6a"></i>
            </div>
            <div style="min-width:0;flex:1;">
                <div class="stat-val" style="color:#f76a6a"><?= $overdue ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>
    </div>
</div>

<!-- Add Task Form -->
<div class="card mb-4">
    <div class="card-title-sm">➕ Add New Task</div>
    <form method="POST" action="tasks.php">
        <input type="hidden" name="action" value="add">
        <div class="row g-2 mb-2">
            <div class="col-md-4">
                <input type="text" name="task_name" class="form-control" placeholder="Task name e.g. Write Chapter 3" required
                style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:10px 14px;">
            </div>
            <div class="col-md-2">
                <input type="text" name="course" class="form-control" placeholder="Course e.g. CS301"
                style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:10px 14px;">
            </div>
            <div class="col-md-2">
                <select name="priority" required style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:10px 14px;">
                    <option value="" disabled selected>Priority</option>
                    <option value="high">🔴 High Priority</option>
                    <option value="medium">🟡 Medium Priority</option>
                    <option value="low">🟢 Low Priority</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="due_date" id="due_date_input" required min="<?= date('Y-m-d') ?>"
                style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:10px 14px;">
            </div>
            <div class="col-md-2">
                <button type="submit" style="width:100%;background:#7c6af7;border:none;border-radius:10px;color:#fff;font-weight:600;padding:10px;cursor:pointer;">
                    + Add Task
                </button>
            </div>
        </div>

        <!-- Optional time range toggle -->
        <div style="margin-top:6px;">
            <button type="button" onclick="toggleTimeRange()"
                style="background:none;border:none;color:var(--text-muted);font-size:12px;cursor:pointer;padding:0;display:flex;align-items:center;gap:5px;">
                <i class="bi bi-clock" id="time-range-icon"></i>
                <span id="time-range-label">+ Set time range (optional)</span>
            </button>
            <div id="time-range-panel" style="display:none;margin-top:10px;padding:14px;background:rgba(124,106,247,0.06);border:1px solid rgba(124,106,247,0.2);border-radius:10px;">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;"><i class="bi bi-info-circle"></i> Set a start and/or end time. Leave blank to use date only.</div>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Start Date <span style="opacity:.6">(optional)</span></label>
                        <input type="date" name="start_date" id="start_date_input" min="<?= date('Y-m-d') ?>"
                            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div class="col-md-3">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Start Time <span style="opacity:.6">(optional)</span></label>
                        <input type="time" name="start_time"
                            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div class="col-md-3">
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">End Time <span style="opacity:.6">(optional)</span></label>
                        <input type="time" name="due_time"
                            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div class="col-md-3" style="display:flex;align-items:flex-end;">
                        <button type="button" onclick="clearTimeRange()"
                            style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border);background:none;color:var(--text-muted);font-size:12px;cursor:pointer;">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Private task toggle -->
        <div style="margin-top:10px;">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <input type="checkbox" name="is_private" value="1" id="privateToggle"
                    style="width:16px;height:16px;accent-color:#7c6af7;cursor:pointer;">
                <span style="font-size:13px;color:var(--text-muted);">
                    🔒 <strong style="color:var(--text-main)">Private task</strong> — only you can see this, hidden from your lecturer
                </span>
            </label>
        </div>
    </form>

<script>
function toggleTimeRange() {
    const panel = document.getElementById('time-range-panel');
    const label = document.getElementById('time-range-label');
    const icon  = document.getElementById('time-range-icon');
    const open  = panel.style.display === 'none';
    panel.style.display = open ? 'block' : 'none';
    label.textContent = open ? '- Hide time range' : '+ Set time range (optional)';
    icon.className = open ? 'bi bi-clock-fill' : 'bi bi-clock';
}
function clearTimeRange() {
    const sd = document.getElementById('start_date_input');
    sd.value = '';
    sd.min   = new Date().toISOString().split('T')[0]; // reset to today
    sd.max   = '';
    document.querySelector('[name=start_time]').value = '';
    document.querySelector('[name=due_time]').value = '';
}
// Sync due_date → start_date in the time range panel
const dueDateInput  = document.getElementById('due_date_input');
const startDateInput = document.getElementById('start_date_input');
const today = new Date().toISOString().split('T')[0];

dueDateInput.addEventListener('change', function () {
    const chosen = this.value;
    if (!chosen) return;

    // Always update start_date to match the due date
    startDateInput.value = chosen;

    // Clamp start_date min: must be >= today AND <= due_date
    startDateInput.min = today;
    startDateInput.max = chosen;
});

// Also clamp on page load in case due_date already has a value (e.g. browser autofill)
(function () {
    if (dueDateInput.value) {
        startDateInput.min = today;
        startDateInput.max = dueDateInput.value;
        if (!startDateInput.value) startDateInput.value = dueDateInput.value;
    } else {
        startDateInput.min = today;
    }
})();
</script>
</div>

<!-- Filter Tabs -->

<!-- ── How to use info box ── -->
<div style="margin-bottom:16px;">
    <button onclick="var b=document.getElementById('taskHelp');b.style.display=b.style.display==='none'?'block':'none'"
        style="background:rgba(124,106,247,0.1);border:1px solid rgba(124,106,247,0.3);color:#7c6af7;
               border-radius:10px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-info-circle-fill"></i> How to use Task Manager
        <i class="bi bi-chevron-down" style="font-size:11px;margin-left:2px"></i>
    </button>
    <div id="taskHelp" style="display:none;margin-top:10px;background:rgba(124,106,247,0.07);
         border:1px solid rgba(124,106,247,0.2);border-radius:12px;padding:18px 20px;">
        <div style="font-size:13px;font-weight:700;color:#7c6af7;margin-bottom:12px;">
            <i class="bi bi-lightbulb-fill"></i> Quick Guide
        </div>
        <!-- Filters -->
        <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Filter Buttons</div>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:20px;background:#7c6af7;color:#fff;font-size:12px;font-weight:600;min-width:60px;text-align:center">All</span>
                    <span style="color:var(--text-muted)">Shows every task regardless of status</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:20px;background:rgba(247,196,106,0.15);color:#f7c46a;border:1px solid rgba(247,196,106,0.3);font-size:12px;font-weight:600;min-width:60px;text-align:center">Pending</span>
                    <span style="color:var(--text-muted)">Tasks not yet completed</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:20px;background:rgba(106,247,184,0.15);color:#6af7b8;border:1px solid rgba(106,247,184,0.3);font-size:12px;font-weight:600;min-width:60px;text-align:center">Done</span>
                    <span style="color:var(--text-muted)">Tasks you have already completed</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:20px;background:rgba(247,106,106,0.15);color:#f76a6a;border:1px solid rgba(247,106,106,0.3);font-size:12px;font-weight:600;min-width:60px;text-align:center">⚠ Overdue</span>
                    <span style="color:var(--text-muted)">Pending tasks past their due date</span>
                </div>
            </div>
        </div>
        <!-- Divider -->
        <div style="border-top:1px solid rgba(124,106,247,0.15);margin:12px 0"></div>
        <!-- Priority colours -->
        <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Priority Colours</div>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:6px;background:rgba(247,106,106,0.15);color:#f76a6a;font-size:12px;font-weight:700;min-width:60px;text-align:center">🔴 HIGH</span>
                    <span style="color:var(--text-muted)">Urgent — do this first</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:6px;background:rgba(247,196,106,0.15);color:#f7c46a;font-size:12px;font-weight:700;min-width:60px;text-align:center">🟡 MED</span>
                    <span style="color:var(--text-muted)">Important but not urgent</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="padding:3px 10px;border-radius:6px;background:rgba(106,247,184,0.15);color:#6af7b8;font-size:12px;font-weight:700;min-width:60px;text-align:center">🟢 LOW</span>
                    <span style="color:var(--text-muted)">Do when you have spare time</span>
                </div>
            </div>
        </div>
        <!-- Divider -->
        <div style="border-top:1px solid rgba(124,106,247,0.15);margin:12px 0"></div>
        <!-- Sorting note -->
        <div style="font-size:13px;color:var(--text-muted);display:flex;align-items:flex-start;gap:8px;">
            <i class="bi bi-sort-down" style="color:#7c6af7;margin-top:1px;flex-shrink:0"></i>
            <span>Tasks are automatically sorted <b style="color:var(--text-main)">High → Medium → Low</b> priority, then by nearest due date first.</span>
        </div>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach(['all'=>'All','pending'=>'Pending','done'=>'Done','due_soon'=>'🔔 Due Soon','overdue'=>'⚠ Overdue','private'=>'🔒 Private','archived'=>'📦 Archived'] as $k=>$v): ?>
        <a href="tasks.php?filter=<?= $k ?>" style="padding:7px 16px;border-radius:20px;font-size:13px;text-decoration:none;font-weight:500;
            background:<?= $filter===$k?'#7c6af7':'#1c1c26' ?>;color:<?= $filter===$k?'#fff':'#7a7a95' ?>;border:1px solid <?= $filter===$k?'#7c6af7':'#2a2a38' ?>;display:flex;align-items:center;gap:5px">
            <?= $v ?>
            <?php if ($k==='overdue' && $overdue > 0): ?>
                <span style="background:#f76a6a;color:#fff;border-radius:20px;padding:0 6px;font-size:10px;font-weight:700"><?= $overdue ?></span>
            <?php elseif ($k==='due_soon' && $due_soon > 0): ?>
                <span style="background:#f7c46a;color:#1a1a26;border-radius:20px;padding:0 6px;font-size:10px;font-weight:700"><?= $due_soon ?></span>
            <?php elseif ($k==='private' && $private_count > 0): ?>
                <span style="background:#7c6af7;color:#fff;border-radius:20px;padding:0 6px;font-size:10px;font-weight:700"><?= $private_count ?></span>
            <?php elseif ($k==='done' && $done > 0): ?>
                <span style="background:#6af7b8;color:#0a2e22;border-radius:20px;padding:0 6px;font-size:10px;font-weight:700"><?= $done ?></span>
            <?php elseif ($k==='archived' && $archived_count > 0): ?>
                <span style="background:#7a7a95;color:#fff;border-radius:20px;padding:0 6px;font-size:10px;font-weight:700"><?= $archived_count ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if ($done > 0): ?>
    <a href="tasks.php?clear_done=1" onclick="return confirm('Clear all <?= $done ?> completed task<?= $done===1?'':'s' ?>? This cannot be undone.')"
       style="padding:7px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
              color:#f76a6a;background:rgba(247,106,106,.1);border:1px solid rgba(247,106,106,.25);display:flex;align-items:center;gap:6px;white-space:nowrap">
        <i class="bi bi-trash3"></i> Clear Completed
    </a>
    <?php endif; ?>
</div>

<!-- Task List -->
<div class="card">
    <?php if (empty($tasks)): ?>
        <?php if ($filter === 'overdue'): ?>
        <div style="text-align:center;padding:40px;">
            <i class="bi bi-shield-check" style="font-size:44px;display:block;margin-bottom:12px;color:#6af7b8"></i>
            <div style="font-size:16px;font-weight:700;color:#6af7b8;margin-bottom:6px">No Overdue Tasks!</div>
            <div style="font-size:13px;color:var(--text-muted)">Great job — all tasks have been completed</div>
        </div>
        <?php elseif ($filter === 'done'): ?>
        <div style="text-align:center;padding:40px;">
            <i class="bi bi-hourglass" style="font-size:44px;display:block;margin-bottom:12px;color:#7c6af7"></i>
            <div style="font-size:16px;font-weight:700;color:var(--text-main);margin-bottom:6px">No Completed Tasks Yet</div>
            <div style="font-size:13px;color:var(--text-muted)">Start ticking off your tasks to see them here.</div>
        </div>
        <?php elseif ($filter === 'pending'): ?>
        <div style="text-align:center;padding:40px;">
            <i class="bi bi-check2-all" style="font-size:44px;display:block;margin-bottom:12px;color:#6af7b8"></i>
            <div style="font-size:16px;font-weight:700;color:#6af7b8;margin-bottom:6px">All Tasks Completed!</div>
            <div style="font-size:13px;color:var(--text-muted)">Nothing pending — you're on fire! 🔥</div>
        </div>
        <?php elseif ($filter === 'archived'): ?>
        <div style="text-align:center;padding:40px;">
            <i class="bi bi-archive" style="font-size:44px;display:block;margin-bottom:12px;color:#7a7a95"></i>
            <div style="font-size:16px;font-weight:700;color:var(--text-main);margin-bottom:6px">No Archived Tasks</div>
            <div style="font-size:13px;color:var(--text-muted)">Completed tasks you archive will be saved here instead of the main list.</div>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;">
            <i class="bi bi-journal-plus" style="font-size:44px;display:block;margin-bottom:12px;color:#7c6af7"></i>
            <div style="font-size:16px;font-weight:700;color:var(--text-main);margin-bottom:6px">No Tasks Yet</div>
            <div style="font-size:13px;color:var(--text-muted)">Add your first task using the form above!</div>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($tasks as $t):
            $pc = $t['priority']==='high' ? '#f76a6a' : ($t['priority']==='medium' ? '#f7c46a' : '#6af7b8');
            $raw_status = isset($t['status']) ? trim((string)$t['status']) : '';
            $status     = ($raw_status === 'done') ? 'done' : 'pending';
            $now_time   = date('H:i:s');
            $today_date = date('Y-m-d');
            // Overdue = past days, OR today with a due_time that has already passed
            $is_overdue = $status === 'pending' && !empty($t['due_date']) && (
                $t['due_date'] < $today_date ||
                ($t['due_date'] === $today_date && !empty($t['due_time']) && $t['due_time'] < $now_time)
            );
            $is_done    = $status==='done';
            $is_archived = !empty($t['archived_at']);
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid #2a2a38">
            <!-- Checkbox -->
            <?php if (!$is_done): ?><a href="tasks.php?toggle=<?= $t['id'] ?>&filter=<?= urlencode($filter) ?>" style="text-decoration:none" onclick="return confirm('Mark this task as done? This cannot be undone.')"><?php else: ?><span style="cursor:default"><?php endif; ?>
                <div style="width:24px;height:24px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                    <?php if ($is_done): ?>
                    background:#6af7b8;border:2px solid #6af7b8;
                    <?php else: ?>
                    background:transparent;border:2px solid var(--text-muted);transition:border-color .2s;
                    <?php endif; ?>">
                    <?php if ($is_done): ?>
                        <i class="bi bi-check-lg" style="color:#000000;font-size:14px;font-weight:900;line-height:1"></i>
                    <?php else: ?>
                        <i class="bi bi-check-lg" style="color:transparent;font-size:14px"></i>
                    <?php endif; ?>
                </div>
            <?php if (!$is_done): ?></a><?php else: ?></span><?php endif; ?>
            <!-- Task info -->
            <div style="flex:1">
                <div style="font-size:14px;font-weight:500;color:var(--text-main);<?= $is_done?'text-decoration:line-through;opacity:0.45':'' ?>">
                    <?php if (!empty($t['is_private'])): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:20px;background:rgba(124,106,247,.12);color:#a89cf7;font-size:10px;font-weight:600;margin-right:5px;vertical-align:middle;">🔒 Private</span>
                    <?php endif; ?>
                    <?= htmlspecialchars($t['task_name']) ?>
                </div>
                <div style="font-size:12px;color:#7a7a95;margin-top:3px;display:flex;align-items:center;flex-wrap:wrap;gap:6px">
                    <?php if (!empty($t['course'])): ?>
                    <span><?= htmlspecialchars($t['course']) ?></span>
                    <span style="opacity:.4">·</span>
                    <?php endif; ?>
                    <?php
                    // Show time range if set
                    $has_start = !empty($t['start_date']) || !empty($t['start_time']);
                    $has_end   = !empty($t['due_time']);
                    if ($has_start || $has_end):
                        $range_parts = [];
                        if (!empty($t['start_date']) && $t['start_date'] !== $t['due_date'])
                            $range_parts[] = date('d M', strtotime($t['start_date']));
                        if (!empty($t['start_time']))
                            $range_parts[] = date('g:ia', strtotime($t['start_time']));
                        $start_str = implode(' ', $range_parts);
                        $end_str   = !empty($t['due_time']) ? date('g:ia', strtotime($t['due_time'])) : '';
                    ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,0.1);color:#a89cf7;font-size:11px;">
                        <i class="bi bi-clock"></i>
                        <?php if ($start_str && $end_str): ?>
                            <?= $start_str ?> → <?= $end_str ?>
                        <?php elseif ($start_str): ?>
                            From <?= $start_str ?>
                        <?php else: ?>
                            Until <?= $end_str ?>
                        <?php endif; ?>
                    </span>
                    <span style="opacity:.4">·</span>
                    <?php endif; ?>
                    <?php
                    $due_label = date('d M Y', strtotime($t['due_date']));
                    $today     = new DateTime(date('Y-m-d'));
                    $due_dt    = new DateTime($t['due_date']);
                    $diff      = (int)$today->diff($due_dt)->days;
                    $past      = $due_dt < $today;

                    if ($is_done):
                    ?>
                        <span style="color:#6af7b8"><i class="bi bi-calendar-check"></i> Deadline: <?= $due_label ?></span>
                    <?php elseif ($is_overdue): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(247,106,106,0.12);color:#f76a6a;font-weight:600;font-size:11px">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?= $diff === 0 ? 'Due today' : $diff . ' day' . ($diff===1?'':'s') . ' overdue' ?>
                            &nbsp;·&nbsp; <?= $due_label ?>
                        </span>
                    <?php elseif ($diff === 0): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(247,106,106,0.12);color:#f76a6a;font-weight:600;font-size:11px">
                            <i class="bi bi-alarm-fill"></i> Due TODAY &nbsp;·&nbsp; <?= $due_label ?>
                        </span>
                    <?php elseif ($diff === 1): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(247,196,106,0.12);color:#f7c46a;font-weight:600;font-size:11px">
                            <i class="bi bi-clock-fill"></i> Due tomorrow &nbsp;·&nbsp; <?= $due_label ?>
                        </span>
                    <?php elseif ($diff <= 3): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(247,196,106,0.08);color:#f7c46a;font-size:11px">
                            <i class="bi bi-calendar-event"></i> <?= $diff ?> days left &nbsp;·&nbsp; <?= $due_label ?>
                        </span>
                    <?php elseif ($diff <= 7): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,0.08);color:#a89cf7;font-size:11px">
                            <i class="bi bi-calendar-week"></i> <?= $diff ?> days left &nbsp;·&nbsp; <?= $due_label ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#7a7a95"><i class="bi bi-calendar3"></i> Deadline: <?= $due_label ?> &nbsp;(<?= $diff ?> days)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Priority -->
            <span style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;background:<?= $pc ?>22;color:<?= $pc ?>">
                <?= strtoupper($t['priority']) ?>
            </span>
            <!-- Status -->
            <span style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;
                background:<?= $is_archived ? 'rgba(122,122,149,.15)' : ($is_done?'rgba(106,247,184,.1)':'rgba(247,196,106,.1)') ?>;
                color:<?= $is_archived ? '#7a7a95' : ($is_done?'#6af7b8':'#f7c46a') ?>">
                <?= $is_archived ? '📦 Archived' : ($is_done ? '✓ Done' : '⏳ Pending') ?>
            </span>
            <?php if ($is_archived): ?>
            <!-- Restore -->
            <a href="tasks.php?unarchive=<?= $t['id'] ?>&filter=<?= urlencode($filter) ?>"
               style="color:#7c6af7;text-decoration:none;padding:6px 10px;border-radius:8px;background:rgba(124,106,247,.1);font-size:13px" title="Restore to Done">
                <i class="bi bi-arrow-counterclockwise"></i>
            </a>
            <?php elseif ($is_done): ?>
            <!-- Archive -->
            <a href="tasks.php?archive=<?= $t['id'] ?>&filter=<?= urlencode($filter) ?>"
               style="color:#7a7a95;text-decoration:none;padding:6px 10px;border-radius:8px;background:rgba(122,122,149,.12);font-size:13px" title="Archive (save without deleting)">
                <i class="bi bi-archive"></i>
            </a>
            <?php endif; ?>
            <!-- Delete -->
            <a href="tasks.php?delete=<?= $t['id'] ?>&filter=<?= urlencode($filter) ?>" onclick="return confirm('Delete this task?')"
               style="color:#f76a6a;text-decoration:none;padding:6px 10px;border-radius:8px;background:rgba(247,106,106,.1);font-size:13px">
                <i class="bi bi-trash"></i>
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Keep scroll position across task actions (done/archive/undo/delete) instead of
// snapping back to the top of the page after the redirect.
document.querySelectorAll('a[href*="toggle="], a[href*="archive="], a[href*="unarchive="], a[href*="delete="]')
    .forEach(a => a.addEventListener('click', () => {
        sessionStorage.setItem('tasks_scroll', window.scrollY);
    }));
(function () {
    const y = sessionStorage.getItem('tasks_scroll');
    if (y !== null) {
        sessionStorage.removeItem('tasks_scroll');
        requestAnimationFrame(() => window.scrollTo(0, parseInt(y, 10)));
    }
})();
</script>

<?php if ($toast_text): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    showToast(<?= json_encode($toast_text) ?>, <?= json_encode($toast_type) ?>);
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
