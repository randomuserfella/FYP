<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once 'config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/includes/notifier.php';

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

// ── AJAX suggestion endpoint (name autocomplete, students only) ────────────
// Called as lecturer_students.php?suggest=1&q=...&scope=all|mine
// Reuses the same prefix > substring > per-word > soundex fuzzy algorithm the
// chat "find people" picker uses, but scoped strictly to role='student'.
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'lecturer') {
        echo json_encode(['ok'=>false,'error'=>'Not authorised']); exit;
    }
    $__s = $pdo->prepare("SELECT university FROM users WHERE id=?");
    $__s->execute([$_SESSION['user_id']]);
    $lec_university = $__s->fetchColumn() ?: '';

    $q     = trim($_GET['q'] ?? '');
    $scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
    // (Input restriction removed — accepts any character.)
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

    if (count($results) < 8) {
        $s = $pdo->prepare($base_sql . " AND SOUNDEX(name) LIKE CONCAT(SOUNDEX(?), '%') ORDER BY name LIMIT 8");
        $s->execute(array_merge($params, [$q]));
        foreach ($s->fetchAll() as $r) { if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; } }
    }

    echo json_encode(['ok'=>true,'suggestions'=>array_slice(array_values($results), 0, 8)]); exit;
}

// ── Handle access request actions (request / cancel) ───────────────────────
// NOTE: this must run BEFORE includes/lecturer_header.php, because that file
// streams <!DOCTYPE html> immediately (no ob_start()) — doing this after it
// would trigger "headers already sent" on the redirect below.
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
            // Only allow a new request if there is no existing pending/approved one
            $existing = $pdo->prepare("SELECT id, status FROM access_requests WHERE lecturer_id=? AND student_id=? ORDER BY id DESC LIMIT 1");
            $existing->execute([$lecturer_id, $target_sid]);
            $existing = $existing->fetch();

            if (!$existing || in_array($existing['status'], ['denied', 'revoked'])) {
                $ins = $pdo->prepare("INSERT INTO access_requests (lecturer_id, student_id, status) VALUES (?, ?, 'pending')");
                $ins->execute([$lecturer_id, $target_sid]);
                try {
                    sendNotification($pdo, $target_sid,
                        "📋 {$lec_name} ({$lec_university}) has requested access to view your tasks and habits on ProcraTrack. Go to Notifications to approve or deny this request.",
                        "ProcraTrack — Access Request");
                } catch (Exception $e) {}
            }
        } elseif ($action === 'cancel') {
            $del = $pdo->prepare("UPDATE access_requests SET status='revoked', responded_at=NOW() WHERE lecturer_id=? AND student_id=? AND status='pending'");
            $del->execute([$lecturer_id, $target_sid]);
        }
    }

    // PRG redirect back to the same listing to avoid resubmission on refresh
    $tab_for_redirect = $_POST['tab'] ?? ($GLOBALS['tab_id'] ?? '');
    $back = 'lecturer_students.php?tab=' . urlencode($tab_for_redirect);
    if (!empty($_POST['back_scope']))  $back .= '&scope='  . urlencode($_POST['back_scope']);
    if (!empty($_POST['back_search'])) $back .= '&search=' . urlencode($_POST['back_search']);
    header('Location: ' . $back); exit;
}

$pageTitle = 'Students';
require_once 'includes/lecturer_header.php';

$search = trim($_GET['search'] ?? '');
// (Input restriction removed — accepts any character.)
$scope  = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine'; // 'all' = include other institutions
$sql = "SELECT u.id, u.name, u.email, u.university,
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done_tasks,
        SUM(CASE WHEN t.status='pending' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_tasks,
        COALESCE((SELECT SUM(s.duration_minutes) FROM sessions s WHERE s.user_id=u.id),0) as focus_mins,
        COALESCE((SELECT COUNT(DISTINCT hl.habit_id) FROM habit_logs hl JOIN habits h ON h.id=hl.habit_id WHERE h.user_id=u.id AND hl.log_date=CURDATE() AND h.is_private=0),0) as habits_today
        FROM users u
        LEFT JOIN tasks t ON u.id = t.user_id AND t.is_private = 0
        WHERE u.role='student'";
$params = [];
if ($scope === 'mine') { $sql .= " AND u.university=?"; $params[] = $lec_university; }
if ($search) { $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " GROUP BY u.id, u.name, u.email, u.university ORDER BY u.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// ── Access request status per student (for the "🌐 other institutions" rows) ──
// Latest request this lecturer has made for each student id.
$access_status = [];
$ar = $pdo->prepare("SELECT student_id, status FROM access_requests
                      WHERE lecturer_id=? AND id IN (
                          SELECT MAX(id) FROM access_requests WHERE lecturer_id=? GROUP BY student_id
                      )");
$ar->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
foreach ($ar->fetchAll() as $row) { $access_status[$row['student_id']] = $row['status']; }
?>

<div class="page-topbar">
    <div class="page-title">Students</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">View all registered students and their performance at a glance.</div>

<!-- Search -->
<form method="GET" id="studentSearchForm" style="margin-bottom:10px;display:flex;gap:10px;position:relative;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($GLOBALS['tab_id']) ?>">
    <input type="hidden" name="scope" id="scopeInput" value="<?= htmlspecialchars($scope) ?>">
    <div style="position:relative;flex:1;min-width:220px">
        <input type="text" name="search" id="studentSearchInputV2" value="<?= htmlspecialchars($search) ?>"
            placeholder="Search by name or email... [v2]" autocomplete="off"
            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
                   border-radius:10px;padding:10px 14px;font-size:14px;">
        <div id="searchSuggestions" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:20;
             background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;
             box-shadow:0 8px 24px rgba(0,0,0,.25)"></div>
    </div>
    <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text-muted);cursor:pointer;white-space:nowrap;
                   background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:0 14px">
        <input type="checkbox" id="scopeToggle" <?= $scope==='all' ? 'checked' : '' ?> style="accent-color:#7c6af7">
        🌐 Include other institutions
    </label>
    <button type="submit"
        style="background:#7c6af7;border:none;border-radius:10px;color:#fff;padding:10px 20px;font-weight:600;cursor:pointer;">
        <i class="bi bi-search"></i> Search
    </button>
    <?php if ($search || $scope==='all'): ?>
    <a href="lecturer_students.php?tab=<?= urlencode($GLOBALS['tab_id']) ?>"
        style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-muted);
               border-radius:10px;padding:10px 16px;text-decoration:none;font-size:14px;display:flex;align-items:center;">
        Clear
    </a>
    <?php endif; ?>
    <a href="export_student_performance.php?<?= $search ? 'search=' . urlencode($search) . '&' : '' ?>tab=<?= urlencode($GLOBALS['tab_id']) ?>"
        style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
               border-radius:10px;padding:10px 16px;text-decoration:none;font-size:14px;font-weight:600;
               display:flex;align-items:center;gap:6px;white-space:nowrap;">
        <i class="bi bi-download"></i> Export CSV
    </a>
</form>
<?php if ($scope === 'all'): ?>
<div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
    <i class="bi bi-info-circle"></i> Showing students from all institutions. For students outside your institution, send an access request — they'll need to approve it before you can view their tasks and habits.
</div>
<?php else: ?>
<div style="margin-bottom:16px"></div>
<?php endif; ?>

<div class="card" style="padding:0 0 8px 0;overflow:hidden;">
    <?php if (empty($students)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">
            <i class="bi bi-people" style="font-size:36px;display:block;margin-bottom:10px;opacity:.4"></i>
            No students found.
        </div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="lec-table" style="min-width:700px">
        <thead><tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <?php if ($scope === 'all'): ?><th>University</th><?php endif; ?>
            <th>Tasks</th>
            <th>Done</th>
            <th>Overdue</th>
            <th>Completion</th>
            <th>Focus (min)</th>
            <th>Habits Today</th>
            <th>Details</th>
        </tr></thead>
        <tbody>
        <?php foreach ($students as $i => $s):
            $rate = $s['total_tasks'] > 0 ? round($s['done_tasks'] / $s['total_tasks'] * 100) : 0;
            $risk = $s['overdue_tasks'] > 2 ? 'pill-red' : ($s['overdue_tasks'] > 0 ? 'pill-yellow' : 'pill-green');
            $same_uni = $s['university'] === $lec_university;
        ?>
        <tr>
            <td style="color:var(--text-muted)"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($s['name']) ?></td>
            <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($s['email']) ?></td>
            <?php if ($scope === 'all'): ?>
            <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($s['university']) ?></td>
            <?php endif; ?>
            <td><?= $s['total_tasks'] ?></td>
            <td><span class="pill pill-green"><?= $s['done_tasks'] ?></span></td>
            <td><span class="pill <?= $risk ?>"><?= $s['overdue_tasks'] ?></span></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:5px;border-radius:3px;background:var(--border);">
                        <div style="height:100%;border-radius:3px;background:#7c6af7;width:<?= $rate ?>%"></div>
                    </div>
                    <span style="font-size:12px;font-weight:600;color:#7c6af7;min-width:32px"><?= $rate ?>%</span>
                </div>
            </td>
            <td><?= $s['focus_mins'] ?></td>
            <td><?= $s['habits_today'] > 0 ? '<span class="pill pill-green">'.$s['habits_today'].'</span>' : '<span class="pill pill-yellow">0</span>' ?></td>
            <td>
                <?php if ($same_uni): ?>
                <a href="lecturer_students.php?view=<?= $s['id'] ?>&tab=<?= urlencode($GLOBALS['tab_id']) ?>"
                    style="color:#7c6af7;text-decoration:none;font-size:12px;font-weight:600;">
                    View →
                </a>
                <?php else:
                    $req_status = $access_status[$s['id']] ?? null;
                    if ($req_status === 'approved'): ?>
                <a href="lecturer_students.php?view=<?= $s['id'] ?>&tab=<?= urlencode($GLOBALS['tab_id']) ?>"
                    style="color:#2ecc71;text-decoration:none;font-size:12px;font-weight:600;">
                    <i class="bi bi-unlock"></i> View →
                </a>
                <?php elseif ($req_status === 'pending'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this access request?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="access_action" value="cancel">
                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="back_scope" value="<?= htmlspecialchars($scope) ?>">
                    <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" title="Waiting for the student to approve — click to cancel"
                        style="background:none;border:none;color:#e6a23c;font-size:12px;font-weight:600;cursor:pointer;padding:0;display:flex;align-items:center;gap:4px;">
                        <i class="bi bi-hourglass-split"></i> Requested
                    </button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="access_action" value="request">
                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="back_scope" value="<?= htmlspecialchars($scope) ?>">
                    <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
                    <button type="submit"
                        title="<?= $req_status === 'denied' ? 'The student previously denied this — send a new request' : "Send this student a request to view their tasks and habits" ?>"
                        style="background:none;border:none;color:#7c6af7;font-size:12px;font-weight:600;cursor:pointer;padding:0;display:flex;align-items:center;gap:4px;">
                        <i class="bi bi-send"></i> <?= $req_status === 'denied' ? 'Request Again' : 'Request Access' ?>
                    </button>
                </form>
                <?php endif; endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
// ── Individual student view ──────────────────────────────────────────
if (isset($_GET['view'])):
    $sid = (int)$_GET['view'];
    $stu = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='student'");
    $stu->execute([$sid]);
    $stu = $stu->fetch(PDO::FETCH_ASSOC);

    // Allow the view if the student is at the lecturer's own institution, OR
    // the lecturer has an approved access request for this specific student.
    $cross_inst_view = false;
    if ($stu && $stu['university'] !== $lec_university) {
        $chk = $pdo->prepare("SELECT status FROM access_requests WHERE lecturer_id=? AND student_id=? ORDER BY id DESC LIMIT 1");
        $chk->execute([$_SESSION['user_id'], $sid]);
        $cross_inst_view = ($chk->fetchColumn() === 'approved');
        if (!$cross_inst_view) $stu = false; // not authorised — treat as not found
    }

    if ($stu):
        $tasks   = $pdo->prepare("SELECT * FROM tasks WHERE user_id=? AND is_private=0 ORDER BY due_date ASC");
        $tasks->execute([$sid]); $tasks = $tasks->fetchAll();
        $habits  = $pdo->prepare("SELECT h.habit_name,
            (SELECT COUNT(*) FROM habit_logs hl WHERE hl.habit_id=h.id) as total_logs
            FROM habits h WHERE h.user_id=? AND h.is_private=0");
        $habits->execute([$sid]); $habits = $habits->fetchAll();
?>
<div class="card" style="border:2px solid rgba(124,106,247,.3);margin-top:24px">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
        <div style="width:50px;height:50px;border-radius:50%;background:rgba(124,106,247,.15);
            display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;overflow:hidden;">
            <?php if (!empty($stu['avatar']) && file_exists(__DIR__ . '/uploads/avatars/' . basename($stu['avatar']))): ?>
                <img src="uploads/avatars/<?= htmlspecialchars(basename($stu['avatar'])) ?>" alt=""
                     style="width:100%;height:100%;border-radius:50%;object-fit:cover;display:block">
            <?php else: ?>
                👤
            <?php endif; ?>
        </div>
        <div>
            <div style="font-size:17px;font-weight:700;color:var(--text-main)">
                <?= htmlspecialchars($stu['name']) ?>
                <?php if ($cross_inst_view): ?>
                <span class="pill pill-green" style="font-size:10px;vertical-align:middle;margin-left:6px"><i class="bi bi-unlock"></i> Approved access — <?= htmlspecialchars($stu['university']) ?></span>
                <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($stu['email']) ?></div>
        </div>
        <a href="lecturer_students.php?tab=<?= urlencode($GLOBALS['tab_id']) ?>" style="margin-left:auto;color:var(--text-muted);text-decoration:none;font-size:13px;">← Back</a>
    </div>

    <div class="card-title-sm">📋 Tasks</div>
    <?php if (empty($tasks)): ?>
        <p style="color:var(--text-muted)">No tasks yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="lec-table" style="margin-bottom:20px;min-width:500px">
        <thead><tr><th>Task</th><th>Course</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t):
            $pc = $t['priority']==='high'?'pill-red':($t['priority']==='medium'?'pill-yellow':'pill-green');
            $sc = $t['status']==='done'?'pill-green':'pill-yellow';
            $is_overdue = ($t['status'] ?? 'pending')==='pending' && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d');
        ?>
        <tr>
            <td style="font-weight:500;color:var(--text-main)"><?= htmlspecialchars($t['task_name']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($t['course']) ?></td>
            <td><span class="pill <?= $pc ?>"><?= ucfirst($t['priority']) ?></span></td>
            <td>
                <span class="pill <?= $sc ?>"><?= ucfirst($t['status'] ?? 'pending') ?></span>
                <?php if ($is_overdue): ?><span class="pill pill-red" style="margin-left:4px">⚠ Overdue</span><?php endif; ?>
            </td>
            <td style="color:var(--text-muted)"><?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- /overflow tasks -->
    <?php endif; ?>

    <div class="card-title-sm">🌿 Habits</div>
    <?php if (empty($habits)): ?>
        <p style="color:var(--text-muted)">No habits tracked yet.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
    <?php foreach ($habits as $hb): ?>
        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:13px;">
            🌱 <?= htmlspecialchars($hb['habit_name']) ?>
            <span style="color:#7c6af7;font-weight:700;margin-left:8px"><?= $hb['total_logs'] ?>× logged</span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; endif; ?>

<script>
console.log('ProcraTrack search box v2 script running');
(function () {
    const input   = document.getElementById('studentSearchInputV2');
    const box     = document.getElementById('searchSuggestions');
    const form    = document.getElementById('studentSearchForm');
    const scopeIn = document.getElementById('scopeInput');
    const scopeCb = document.getElementById('scopeToggle');
    if (!input) { console.log('ProcraTrack search: input not found'); return; }

    // No character restriction of any kind — every keystroke passes through untouched.

    scopeCb.addEventListener('change', function () {
        scopeIn.value = this.checked ? 'all' : 'mine';
    });

    let debounceTimer;
    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        debounceTimer = setTimeout(() => fetchSuggestions(q), 250);
    });

    function fetchSuggestions(q) {
        const scope = scopeCb.checked ? 'all' : 'mine';
        fetch('lecturer_students.php?suggest=1&q=' + encodeURIComponent(q) + '&scope=' + scope + '&tab=<?= urlencode($GLOBALS['tab_id']) ?>')
            .then(r => r.json())
            .then(d => {
                if (!d.ok || !d.suggestions.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
                box.innerHTML = d.suggestions.map(s => `
                    <div class="sugg-item" data-name="${s.name.replace(/"/g,'&quot;')}"
                         style="padding:10px 14px;cursor:pointer;font-size:13px;color:var(--text-main);
                                border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:10px">
                        <span>${escapeHtml(s.name)}</span>
                        <span style="color:var(--text-muted);font-size:11px">${escapeHtml(s.university || '')}</span>
                    </div>
                `).join('');
                box.style.display = 'block';
                box.querySelectorAll('.sugg-item').forEach(el => {
                    el.addEventListener('mouseenter', () => el.style.background = 'rgba(124,106,247,.08)');
                    el.addEventListener('mouseleave', () => el.style.background = '');
                    el.addEventListener('click', () => {
                        input.value = el.dataset.name;
                        box.style.display = 'none';
                        form.submit();
                    });
                });
            })
            .catch(() => { box.style.display = 'none'; });
    }

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target) && e.target !== input) { box.style.display = 'none'; }
    });
})();
</script>

<?php require_once 'includes/lecturer_footer.php'; ?>
