<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$pageTitle = 'Focus Reports';
require_once 'config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/includes/notifier.php';

// ── Ensure access_requests table exists (shared with lecturer_students.php —
// cross-institution students only become viewable once the student approves) ──
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
                        "📋 {$lec_name} ({$lec_university}) has requested access to view your focus reports on ProcraTrack. Go to Notifications to approve or deny this request.",
                        "ProcraTrack — Access Request");
                } catch (Exception $e) {}
            }
        } elseif ($action === 'cancel') {
            $del = $pdo->prepare("UPDATE access_requests SET status='revoked', responded_at=NOW() WHERE lecturer_id=? AND student_id=? AND status='pending'");
            $del->execute([$lecturer_id, $target_sid]);
        }
    }

    $back = 'lecturer_focus.php?tab=' . urlencode($_POST['tab'] ?? ($GLOBALS['tab_id'] ?? ''));
    if (!empty($_POST['back_scope']))  $back .= '&scope='  . urlencode($_POST['back_scope']);
    if (!empty($_POST['back_search'])) $back .= '&search=' . urlencode($_POST['back_search']);
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
    // Letters, digits and spaces only — no special characters.
    $q = preg_replace('/[^\p{L}\d\s]/u', '', $q);
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

// ── Filters: institution scope + search ─────────────────────────────────
$search = trim($_GET['search'] ?? '');
// Letters, digits and spaces only — no special characters.
$search = preg_replace('/[^\p{L}\d\s]/u', '', $search);
$scope  = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine'; // 'all' = include other institutions

// ── Approved cross-institution students (lecturer has been granted access) ──
$approved_ids = [];
$ap = $pdo->prepare("SELECT student_id FROM access_requests WHERE lecturer_id=? AND status='approved'");
$ap->execute([$_SESSION['user_id']]);
foreach ($ap->fetchAll() as $row) { $approved_ids[] = (int)$row['student_id']; }

// ── per-student focus summary (scoped by institution + search) ─────────────
$sql = "
    SELECT u.id, u.name, u.email, u.university,
        COALESCE(SUM(s.duration_minutes),0) as total_mins,
        COALESCE(SUM(CASE WHEN s.session_date=CURDATE() THEN s.duration_minutes ELSE 0 END),0) as today_mins,
        COUNT(s.id) as total_sessions,
        MAX(s.session_date) as last_session
    FROM users u
    LEFT JOIN sessions s ON u.id = s.user_id
    WHERE u.role='student'";
$params = [];
if ($scope === 'mine') { $sql .= " AND u.university=?"; $params[] = $lec_university; }
if ($search) { $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " GROUP BY u.id, u.name, u.email, u.university ORDER BY total_mins DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$focus_all = $stmt->fetchAll();

// Only aggregate the totals/cards from students the lecturer is actually
// authorised to see (own institution, or approved cross-institution access) —
// otherwise the summary cards would leak activity volume from locked rows.
$focus = array_filter($focus_all, function($f) use ($lec_university, $approved_ids) {
    return $f['university'] === $lec_university || in_array((int)$f['id'], $approved_ids, true);
});

$total_mins    = array_sum(array_column($focus, 'total_mins'));
$today_total   = array_sum(array_column($focus, 'today_mins'));
$total_sessions = array_sum(array_column($focus, 'total_sessions'));

// ── Access request status per student (for locked cross-institution rows) ──
$access_status = [];
$ar = $pdo->prepare("SELECT student_id, status FROM access_requests
                      WHERE lecturer_id=? AND id IN (
                          SELECT MAX(id) FROM access_requests WHERE lecturer_id=? GROUP BY student_id
                      )");
$ar->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
foreach ($ar->fetchAll() as $row) { $access_status[$row['student_id']] = $row['status']; }

// distraction log — always scoped to own institution + approved access,
// regardless of the search/scope toggle above, since this is a summary
// widget rather than something the lecturer is actively browsing.
$distractions = [];
try {
    $distSql = "
        SELECT d.type, COUNT(*) as count, u.name as student_name
        FROM distraction_events d JOIN users u ON d.user_id = u.id
        WHERE u.role='student' AND (u.university = ?" .
        (!empty($approved_ids) ? " OR u.id IN (" . implode(',', array_fill(0, count($approved_ids), '?')) . ")" : "") .
        ")
        GROUP BY d.type, u.name
        ORDER BY count DESC LIMIT 10
    ";
    $distParams = array_merge([$lec_university], $approved_ids);
    $distStmt = $pdo->prepare($distSql);
    $distStmt->execute($distParams);
    $distractions = $distStmt->fetchAll();
} catch (PDOException $e) {
    // distractions table not yet created — show empty state
}
?>

<div class="page-topbar">
    <div class="page-title">Focus Reports</div>
    <button class="theme-toggle" onclick="toggleTheme()"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Monitor how much time students spend in focused study sessions.</div>

<!-- Search + institution scope -->
<form method="GET" id="focusSearchForm" style="margin-bottom:16px;display:flex;gap:10px;position:relative;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($GLOBALS['tab_id'] ?? '') ?>">
    <input type="hidden" name="scope" id="scopeInput" value="<?= htmlspecialchars($scope) ?>">
    <div style="position:relative;flex:1;min-width:220px">
        <input type="text" name="search" id="focusSearchInput" value="<?= htmlspecialchars($search) ?>"
            placeholder="Search by name or email..." autocomplete="off"
            style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
                   border-radius:10px;padding:10px 14px;font-size:14px;">
        <div id="focusSearchSuggestions" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:20;
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
    <a href="lecturer_focus.php?tab=<?= urlencode($GLOBALS['tab_id'] ?? '') ?>"
        style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-muted);
               border-radius:10px;padding:10px 16px;text-decoration:none;font-size:14px;display:flex;align-items:center;">
        Clear
    </a>
    <?php endif; ?>
</form>
<?php if ($scope === 'all'): ?>
<div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
    <i class="bi bi-info-circle"></i> Showing students from all institutions. Focus data for students outside your institution stays locked until they approve your access request.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #7c6af7">
        <div class="stat-val" style="color:#7c6af7"><?= round($total_mins/60,1) ?>h</div>
        <div class="stat-label">Total Focus Time</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #6af7b8">
        <div class="stat-val" style="color:#6af7b8"><?= $today_total ?>m</div>
        <div class="stat-label">Focus Today</div>
    </div></div>
    <div class="col-4"><div class="stat-card" style="border-left:4px solid #f7c46a">
        <div class="stat-val" style="color:#f7c46a"><?= $total_sessions ?></div>
        <div class="stat-label">Total Sessions</div>
    </div></div>
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card" style="padding:0 0 8px 0;overflow:hidden;">
            <div style="padding:16px 20px 0;"><div class="card-title-sm">⏱ Per-Student Focus Time</div></div>
            <?php if (empty($focus_all)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);">No students found.</div>
            <?php else: ?>
            <div class="table-scroll">
<table class="lec-table">
                <thead><tr>
                    <th>#</th><th>Student</th>
                    <?php if ($scope === 'all'): ?><th>University</th><?php endif; ?>
                    <th>Total (h)</th><th>Today (min)</th><th>Sessions</th><th>Last Session</th>
                </tr></thead>
                <tbody>
                <?php foreach ($focus_all as $i => $f):
                    $same_uni  = $f['university'] === $lec_university;
                    $unlocked  = $same_uni || in_array((int)$f['id'], $approved_ids, true);
                ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($f['name']) ?></td>
                    <?php if ($scope === 'all'): ?>
                    <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($f['university']) ?></td>
                    <?php endif; ?>
                    <?php if ($unlocked): ?>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="width:60px;height:5px;border-radius:3px;background:var(--border);">
                                <div style="height:100%;border-radius:3px;background:#7c6af7;width:<?= $total_mins > 0 ? min(100,round($f['total_mins']/$total_mins*100)) : 0 ?>%"></div>
                            </div>
                            <span style="color:#7c6af7;font-weight:600"><?= round($f['total_mins']/60,1) ?>h</span>
                        </div>
                    </td>
                    <td><?= $f['today_mins'] ?>m</td>
                    <td><span class="pill pill-purple"><?= $f['total_sessions'] ?></span></td>
                    <td style="color:var(--text-muted)"><?= $f['last_session'] ? date('d M Y', strtotime($f['last_session'])) : '—' ?></td>
                    <?php else:
                        $req_status = $access_status[$f['id']] ?? null;
                    ?>
                    <td colspan="4" style="color:var(--text-muted);font-size:12px;">
                        <i class="bi bi-lock"></i> Locked — different institution.
                        <?php if ($req_status === 'pending'): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this access request?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="access_action" value="cancel">
                            <input type="hidden" name="student_id" value="<?= $f['id'] ?>">
                            <input type="hidden" name="back_scope" value="<?= htmlspecialchars($scope) ?>">
                            <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" title="Waiting for the student to approve — click to cancel"
                                style="background:none;border:none;color:#e6a23c;font-size:12px;font-weight:600;cursor:pointer;padding:0;margin-left:6px;">
                                <i class="bi bi-hourglass-split"></i> Requested
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="access_action" value="request">
                            <input type="hidden" name="student_id" value="<?= $f['id'] ?>">
                            <input type="hidden" name="back_scope" value="<?= htmlspecialchars($scope) ?>">
                            <input type="hidden" name="back_search" value="<?= htmlspecialchars($search) ?>">
                            <button type="submit"
                                title="<?= $req_status === 'denied' ? 'The student previously denied this — send a new request' : 'Send this student a request to view their focus reports' ?>"
                                style="background:none;border:none;color:#7c6af7;font-size:12px;font-weight:600;cursor:pointer;padding:0;margin-left:6px;">
                                <i class="bi bi-send"></i> <?= $req_status === 'denied' ? 'Request Again' : 'Request Access' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card">
            <div class="card-title-sm">🚫 Top Distractions Logged</div>
            <?php if (empty($distractions)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted);">No distractions logged.</div>
            <?php else: ?>
            <?php foreach ($distractions as $d): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                <div>
                    <span style="font-size:13px;font-weight:600;color:var(--text-main)"><?= htmlspecialchars($d['type']) ?></span>
                    <span style="font-size:12px;color:var(--text-muted);margin-left:6px">— <?= htmlspecialchars($d['student_name']) ?></span>
                </div>
                <span class="pill pill-red"><?= $d['count'] ?>×</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
    var input = document.getElementById('focusSearchInput');
    var box   = document.getElementById('focusSearchSuggestions');
    var scopeToggle = document.getElementById('scopeToggle');
    var scopeInput  = document.getElementById('scopeInput');
    var timer = null;

    scopeToggle.addEventListener('change', function(){
        scopeInput.value = scopeToggle.checked ? 'all' : 'mine';
    });

    input.addEventListener('input', function(){
        var cleaned = this.value.replace(/[^\p{L}\d\s]/gu, '');
        if (cleaned !== this.value) this.value = cleaned;
        clearTimeout(timer);
        var q = input.value;
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        timer = setTimeout(function(){
            var scope = scopeToggle.checked ? 'all' : 'mine';
            fetch('lecturer_focus.php?suggest=1&q=' + encodeURIComponent(q) + '&scope=' + scope)
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
                            document.getElementById('focusSearchForm').submit();
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
