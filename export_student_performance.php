<?php
// Load your existing session management system
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

// Load your database connection configuration
require_once 'config/db.php';

// ── Security: only logged-in lecturers may export class data ───────────────
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'lecturer') {
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Please log in as a lecturer first.");
}

$lecturer_id = $_SESSION['user_id'];

// Always fetch this lecturer's own university fresh from the DB — never trust
// a client-supplied value, and never store it in the session.
$lecStmt = $pdo->prepare("SELECT university FROM users WHERE id = ? AND role = 'lecturer'");
$lecStmt->execute([$lecturer_id]);
$lec_university = $lecStmt->fetchColumn();

if (!$lec_university) {
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. No institution is set on your account.");
}

// Optional: respect the same search box used on lecturer_students.php so the
// export can match whatever the lecturer is currently looking at.
$search = trim($_GET['search'] ?? '');

// Force file to download instantly as a spreadsheet CSV file
$filename = "Class_Performance_Report_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $lec_university) . "_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Open output write stream buffer
$output = fopen('php://output', 'w');

// Report header block (mirrors export_tasks.php's style)
fputcsv($output, array('CLASS PERFORMANCE REPORT'));
fputcsv($output, array('Institution:', $lec_university));
fputcsv($output, array('Generated:', date('d M Y, H:i')));
if ($search) fputcsv($output, array('Filtered by:', $search));
fputcsv($output, array('')); // Blank spacer row
fputcsv($output, array('No', 'Student Name', 'Email Address', 'Total Tasks', 'Completed Tasks', 'Overdue Tasks', 'Completion Rate (%)', 'Total Focus Time (mins)', 'Habits Completed Today'));

try {
    // Scoped to this lecturer's own university only — matches every other
    // lecturer-facing page (lecturer_students.php, lecturer_report.php).
    $sql = "SELECT u.id, u.name, u.email,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done_tasks,
            SUM(CASE WHEN t.status='pending' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_tasks,
            COALESCE((SELECT SUM(s.duration_minutes) FROM sessions s WHERE s.user_id=u.id),0) as focus_mins,
            COALESCE((SELECT COUNT(DISTINCT hl.habit_id) FROM habit_logs hl JOIN habits h ON h.id=hl.habit_id WHERE h.user_id=u.id AND hl.log_date=CURDATE() AND h.is_private=0),0) as habits_today
            FROM users u
            LEFT JOIN tasks t ON u.id = t.user_id
            WHERE u.role='student' AND u.university = ?";
    $params = [$lec_university];

    if ($search) {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " GROUP BY u.id, u.name, u.email ORDER BY u.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    foreach ($students as $i => $s) {
        $rate = $s['total_tasks'] > 0 ? round($s['done_tasks'] / $s['total_tasks'] * 100) : 0;

        fputcsv($output, array(
            $i + 1,
            $s['name'],
            $s['email'],
            $s['total_tasks'],
            $s['done_tasks'],
            $s['overdue_tasks'],
            $rate . '%',
            $s['focus_mins'],
            $s['habits_today']
        ));
    }

    if (!$students) {
        fputcsv($output, array('No students found for your institution.'));
    }
} catch (Exception $e) {
    // Don't leak DB internals into the downloaded file — log server-side instead.
    error_log('export_student_performance.php error: ' . $e->getMessage());
    fputcsv($output, array('An error occurred while generating this report. Please try again.'));
}

// Close writing handle and terminate
fclose($output);
exit();
