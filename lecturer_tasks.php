<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Task Reports';
require_once 'config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/includes/notifier.php';

// ── Ensure access_requests table exists (shared with lecturer_students.php /
// lecturer_focus.php — cross-institution students only become viewable once
// the student approves) ──
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

// ── Handle access request actions (request / cancel) — must run before the
// header include streams output, so the redirect below works. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_action'])) {
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'lecturer') {
        header('Location: index.php'); exit;
    }
    csrf_verify();
    $lecturer_id = (int)$_SESSION['user_id'];
    $target_sid  = (int)($_POST['student_id'] ?? 0);
    $action      = $_POST['access_action'];

    $__lec = $pdo->prepare("SELECT name, university FROM users WHERE id=?");
    $__lec->execute([$lecturer_id]);
    $__lec = $__lec->fetch();
    $lec_name       = $__lec['name'] ?? 'A lecturer';
    $lec_university = $__lec['university'] ?? '';

    $stu_check = $pdo->prepare("SELECT id, name, university FROM users WHERE id=? AND role='student'");
    $stu_check->execute([$target_sid]);
    $target_student = $stu_check->fetch();

    if ($target_student) {
        if ($action === 'request' && $target_student['university'] !== $lec_university) {
            $existing = $pdo->prepare("SELECT id, status FROM access_requests WHERE lecturer_id=? AND student_id=? ORDER BY id DESC LIMIT 1");
            $existing->execute([$lecturer_id, $target_sid]);
            $existing = $existing->fetch();

            if (!$existing || in_array($existing['status'], ['denied', 'revoked'])) {
                $ins = $pdo->prepare("INSERT INTO access_requests (lecturer_id, student_id, status) VALUES (?, ?, 'pending')");
                $ins->execute([$lecturer_id, $target_sid]);
                try {
                    sendNotification($pdo, $target_sid,
                        "📋 {$lec_name} ({$lec_university}) has requested access to view your task reports on ProcraTrack. Go to Notifications to approve or deny this request.",
                        "ProcraTrack — Access Request");
                } catch (Exception $e) {}
            }
        } elseif ($action === 'cancel') {
            $del = $pdo->prepare("UPDATE access_requests SET status='revoked', responded_at=NOW() WHERE lecturer_id=? AND student_id=? AND status='pending'");
            $del->execute([$lecturer_id, $target_sid]);
        }
    }

    $back = 'lecturer_tasks.php?tab=' . urlencode($_POST['tab'] ?? ($GLOBALS['tab_id'] ?? ''));
    if (!empty($_POST['back_filter']))   $back .= '&filter='   . urlencode($_POST['back_filter']);
    if (!empty($_POST['back_priority'])) $back .= '&priority=' . urlencode($_POST['back_priority']);
    if (!empty($_POST['back_scope']))    $back .= '&scope='    . urlencode($_POST['back_scope']);
    if (!empty($_POST['back_search']))   $back .= '&search='   . urlencode($_POST['back_search']);
    header('Location: ' . $back); exit;
}

require_once 'includes/lecturer_header.php';
// $lec_university is set by the header include above.

// ── AJAX suggestion endpoint (name autocomplete, students only) ────────────
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'lecturer') {
        echo json_encode(['ok'=>false,'error'=>'Not authorised']); exit;
    }
    $q     = trim($_GET['q'] ?? '');
    $scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
    $q = preg_replace('/[^\p{L}\s\'\-]/u', '', $q);
    if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'suggestions'=>[]]); exit; }

    $base_sql = "SELECT id, name, email, university FROM users WHERE role='student'";
    $params   = [];
    if ($scope === 'mine') { $base_sql .= " AND university=?"; $params[] = $lec_university; }

    $results = []; $seen = [];
    $s = $pdo->prepare($base_sql . " AND name LIKE ? ORDER BY name LIMIT 8");
    $s->execute(array_merge($params, [$q . '%']));
    foreach ($s->fetchAll() as $r) { $seen[$r['id']] = true; $results[] = $r; }

    if (count($results) < 8) {
        $s = $pdo->prepare($base_sql . " AND name LIKE ? ORDER BY name LIMIT 8");
        $s->execute(array_merge($params, ['%' . $q . '%']));
        foreach ($s->fetchAll() as $r) { if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; } }
    }

    echo json_encode(['ok'=>true,'suggestions'=>array_slice(array_values($results), 0, 8)]); exit;
}

$filter   = $_GET['filter']   ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$search   = trim($_GET['search'] ?? '');
$search   = preg_replace('/[^\p{L}\s\'\-@.]/u', '', $search);
$scope    = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine'; // 'all' = include other institutions

// ── Approved cross-institution students (lecturer has been granted access) ──
$approved_ids = [];
$ap = $pdo->prepare("SELECT student_id FROM access_requests WHERE lecturer_id=? AND status='approved'");
$ap->execute([$_SESSION['user_id']]);
foreach ($ap->fetchAll() as $row) { $approved_ids[] = (int)$row['student_id']; }

// ── Build the visible-students clause: own institution OR approved access ──
$visibility_sql = "(u.university = ?" .
    (!empty($approved_ids) ? " OR u.id IN (" . implode(',', array_fill(0, count($approved_ids), '?')) . ")" : "") .
    ")";
$visibility_params = array_merge([$lec_university], $approved_ids);

$sql = "SELECT t.*, u.name as student_name, u.university as student_university
        FROM tasks t JOIN users u ON t.user_id = u.id
        WHERE u.role='student' AND t.is_private = 0 AND $visibility_sql";

$params = $visibility_params;
if ($scope === 'mine')             { $sql .= " AND u.university = ?"; $params[] = $lec_university; }
if ($filter === 'done')            { $sql .= " AND t.status='done'"; }
if ($filter === 'pending')         { $sql .= " AND t.status='pending'"; }
if ($filter === 'overdue')         { $sql .= " AND t.status='pending' AND t.due_date < CURDATE()"; }
if ($priority !== 'all')           { $sql .= " AND t.priority=?"; $params[] = $priority; }
if ($search)                       { $sql .= " AND (t.task_name LIKE ? OR u.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY FIELD(t.priority,'high','medium','low'), t.due_date ASC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$tasks = $stmt->fetchAll();

// summary counts — scoped the same way (own institution + approved access only)
$sumSql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN t.status='pending' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
        FROM tasks t JOIN users u ON t.user_id=u.id
        WHERE u.role='student' AND t.is_private=0 AND $visibility_sql";
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($visibility_params);
$sums = $sumStmt->fetch();
$total   = $sums['total']   ?? 0;
$done    = $sums['done']    ?? 0;
$overdue = $sums['overdue'] ?? 0;

// Which students (within the visible set) are cross-institution, for badges
$access_status = [];
$ar = $pdo->prepare("SELECT student_id, status FROM access_requests
                      WHERE lecturer_id=? AND id IN (
                          SELECT MAX(id) FROM access_requests WHERE lecturer_id=? GROUP BY student_id
                      )");
$ar->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
foreach ($ar->fetchAll() as $row) { $access_status[$row['student_id']] = $row['status']; }
?>

<div class="page-topbar">
    <div class="page-title">Task Reports</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">All tasks submitted by your students — private tasks are hidden for student privacy.</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #7c6af7">
        <div class="stat-val" style="color:#7c6af7"><?= $total ?></div>
        <div class="stat-label">Total Tasks</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #6af7b8">
        <div class="stat-val" style="color:#6af7b8"><?= $done ?></div>
        <div class="stat-label">Completed</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #f76a6a">
        <div class="stat-val" style="color:#f76a6a"><?= $overdue ?></div>
        <div class="stat-label">Overdue</div>
    </div></div>
</div>

<!-- Filters -->
<form method="GET" id="taskSearchForm" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;position:relative;">
    <input type="hidden" name="scope" id="scopeInput" value="<?= htmlspecialchars($scope) ?>">
    <div style="position:relative;flex:1;min-width:160px">
        <input type="text" name="search" id="taskSearchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Search task or student..."
            autocomplete="off"
            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
                   border-radius:10px;padding:9px 14px;font-size:13px;">
        <div id="taskSearchSuggestions" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:20;
             background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;
             box-shadow:0 8px 24px rgba(0,0,0,.25)"></div>
    </div>
    <?php foreach (['all'=>'All','pending'=>'Pending','done'=>'Done','overdue'=>'⚠ Overdue'] as $k=>$v): ?>
    <a href="?filter=<?= $k ?>&priority=<?= $priority ?>&search=<?= urlencode($search) ?>&scope=<?= $scope ?>"
        style="padding:8px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;
               background:<?= $filter===$k ? '#7c6af7' : 'var(--bg-input)' ?>;
               color:<?= $filter===$k ? '#fff' : 'var(--text-muted)' ?>;
               border:1px solid <?= $filter===$k ? '#7c6af7' : 'var(--border)' ?>;">
        <?= $v ?>
    </a>
    <?php endforeach; ?>
    <select name="priority" onchange="this.form.submit()"
        style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
               border-radius:10px;padding:9px 12px;font-size:13px;">
        <option value="all"   <?= $priority==='all'?'selected':'' ?>>All Priorities</option>
        <option value="high"  <?= $priority==='high'?'selected':'' ?>>🔴 High</option>
        <option value="medium"<?= $priority==='medium'?'selected':'' ?>>🟡 Medium</option>
        <option value="low"   <?= $priority==='low'?'selected':'' ?>>🟢 Low</option>
    </select>
    <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text-muted);cursor:pointer;white-space:nowrap;
                   background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:0 14px;height:37px">
        <input type="checkbox" id="scopeToggle" <?= $scope==='all' ? 'checked' : '' ?> style="accent-color:#7c6af7">
        🌐 Other institutions
    </label>
    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
    <button type="submit" style="background:#7c6af7;border:none;border-radius:10px;color:#fff;padding:9px 16px;font-weight:600;cursor:pointer;">
        <i class="bi bi-search"></i>
    </button>
</form>
<?php if ($scope === 'all'): ?>
<div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
    <i class="bi bi-info-circle"></i> Showing tasks from all institutions. Students outside your institution need to approve an access request before their tasks appear here.
</div>
<?php else: ?>
<div style="margin-bottom:16px"></div>
<?php endif; ?>

<div class="card" style="padding:0 0 8px 0;overflow:hidden;">
    <?php if (empty($tasks)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">No tasks match your filters.</div>
    <?php else: ?>
    <div class="table-scroll">
<table class="lec-table">
        <thead><tr>
            <th>Student</th><?php if ($scope === 'all'): ?><th>University</th><?php endif; ?>
            <th>Task</th><th>Course</th><th>Priority</th><th>Status</th><th>Due Date</th>
        </tr></thead>
        <tbody>
        <?php foreach ($tasks as $t):
            $pc = $t['priority']==='high'?'pill-red':($t['priority']==='medium'?'pill-yellow':'pill-green');
            $sc = ($t['status'] ?? 'pending')==='done'?'pill-green':'pill-yellow';
            $is_overdue = ($t['status'] ?? 'pending')==='pending' && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d');
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($t['student_name']) ?></td>
            <?php if ($scope === 'all'): ?>
            <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($t['student_university']) ?></td>
            <?php endif; ?>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-main)"><?= htmlspecialchars($t['task_name']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($t['course'] ?? '—') ?></td>
            <td><span class="pill <?= $pc ?>"><?= ucfirst($t['priority']) ?> Priority</span></td>
            <td>
                <span class="pill <?= $sc ?>"><?= ucfirst($t['status'] ?? 'pending') ?></span>
                <?php if ($is_overdue): ?><span class="pill pill-red" style="margin-left:4px">⚠ Overdue</span><?php endif; ?>
            </td>
            <td style="color:<?= $is_overdue ? '#f76a6a' : 'var(--text-muted)' ?>">
                <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
    <?php endif; ?>
</div>

<?php if ($scope === 'all'): ?>
<div class="card" style="margin-top:18px;">
    <div class="card-title-sm">🔒 Locked — pending your access request</div>
    <?php
    $locked = $pdo->prepare("SELECT id, name, university FROM users
        WHERE role='student' AND university != ?" .
        (!empty($approved_ids) ? " AND id NOT IN (" . implode(',', array_fill(0, count($approved_ids), '?')) . ")" : "") .
        ($search ? " AND name LIKE ?" : "") .
        " ORDER BY name ASC");
    $lockedParams = array_merge([$lec_university], $approved_ids);
    if ($search) $lockedParams[] = "%$search%";
    $locked->execute($lockedParams);
    $locked = $locked->fetchAll();
    ?>
    <?php if (empty($locked)): ?>
        <div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">No locked cross-institution students match this search.</div>
    <?php else: ?>
    <?php foreach ($locked as $l):
        $req_status = $access_status[$l['id']] ?? null;
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
        <div>
            <span style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($l['name']) ?></span>
            <span style="font-size:12px;color:var(--text-muted);margin-left:6px">— <?= htmlspecialchars($l['university']) ?></span>
        </div>
        <?php if ($req_status === 'pending'): ?>
        <form method="POST" onsubmit="return confirm('Cancel this access request?');">
            <?= csrf_field() ?>
            <input type="hidden" name="access_action" value="cancel">
            <input type="hidden" name="student_id" value="<?= $l['id'] ?>">
            <input type="hidden" name="back_scope" value="all">
            <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" style="background:none;border:none;color:#e6a23c;font-size:12px;font-weight:600;cursor:pointer;">
                <i class="bi bi-hourglass-split"></i> Requested
            </button>
        </form>
        <?php else: ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="access_action" value="request">
            <input type="hidden" name="student_id" value="<?= $l['id'] ?>">
            <input type="hidden" name="back_scope" value="all">
            <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
            <button type="submit" style="background:none;border:none;color:#7c6af7;font-size:12px;font-weight:600;cursor:pointer;">
                <i class="bi bi-send"></i> <?= $req_status === 'denied' ? 'Request Again' : 'Request Access' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function(){
    var input = document.getElementById('taskSearchInput');
    var box   = document.getElementById('taskSearchSuggestions');
    var scopeToggle = document.getElementById('scopeToggle');
    var scopeInput  = document.getElementById('scopeInput');
    var timer = null;

    scopeToggle.addEventListener('change', function(){
        scopeInput.value = scopeToggle.checked ? 'all' : 'mine';
        document.getElementById('taskSearchForm').submit();
    });

    input.addEventListener('input', function(){
        clearTimeout(timer);
        var q = input.value;
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        timer = setTimeout(function(){
            var scope = scopeToggle.checked ? 'all' : 'mine';
            fetch('lecturer_tasks.php?suggest=1&q=' + encodeURIComponent(q) + '&scope=' + scope)
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.ok || !d.suggestions.length) { box.style.display = 'none'; box.innerHTML=''; return; }
                    box.innerHTML = d.suggestions.map(function(s){
                        return '<div class="suggestion-item" data-name="' + s.name.replace(/"/g,'&quot;') + '" ' +
                               'style="padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);">' +
                               '<strong>' + s.name + '</strong> <span style="color:var(--text-muted);font-size:11px">' + s.email + '</span></div>';
                    }).join('');
                    box.style.display = 'block';
                    box.querySelectorAll('.suggestion-item').forEach(function(el){
                        el.addEventListener('click', function(){
                            input.value = el.getAttribute('data-name');
                            box.style.display = 'none';
                            document.getElementById('taskSearchForm').submit();
                        });
                    });
                })
                .catch(function(){ box.style.display = 'none'; });
        }, 250);
    });

    document.addEventListener('click', function(e){
        if (!box.contains(e.target) && e.target !== input) box.style.display = 'none';
    });
})();
</script>

<?php require_once 'includes/lecturer_footer.php'; ?>
