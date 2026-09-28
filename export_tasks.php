<?php
require_once __DIR__ . '/config/tab_session.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

// Security Check: Ensure the student is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Please log in first.");
}

require_once 'config/db.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Student';

// =========================================================================
// 1. FETCH & CALCULATE METRICS (Directly matching dashboard.php logic)
// =========================================================================

// Total tasks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_tasks = $stmt->fetchColumn();

// Completed tasks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'done'");
$stmt->execute([$user_id]);
$done_tasks = $stmt->fetchColumn();

// Total focus sessions today
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes),0) FROM sessions WHERE user_id = ? AND session_date = CURDATE()");
$stmt->execute([$user_id]);
$focus_today = $stmt->fetchColumn();

// Completion rate & Focus Score calculations
$completion_rate = $total_tasks > 0 ? round(($done_tasks / $total_tasks) * 100) : 0;
$focus_score = min(100, $completion_rate + min(30, $focus_today));

// =========================================================================
// 2. CONFIGURE CSV DOWNLOAD HEADERS
// =========================================================================
$filename = "My_Tasks_&_Performance_Report_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Write out the Overall Student Performance Summary Data Block at the top
fputcsv($output, array('STUDENT PERFORMANCE SUMMARY'));
fputcsv($output, array('Student Name:', $user_name));
fputcsv($output, array('Focus Score Performance:', $focus_score . ' / 100'));
fputcsv($output, array('Task Completion Rate:', $completion_rate . '%'));
fputcsv($output, array('Focus Time Today:', $focus_today . ' minutes'));
fputcsv($output, array('')); // Blank Spacer Row
fputcsv($output, array('DETAILED TASK TRACKER LIST'));

// Main data table column row layout
fputcsv($output, array('No', 'Task Name', 'Course / Subject', 'Priority', 'Status', 'Date Created', 'Due Date'));

// =========================================================================
// 3. FETCH DETAILED TASKS AND STREAM OUT rows
// =========================================================================
try {
    // Note: We select 'created_at' for the creation timeline data tracking.
    // If your table uses 'date_created' or 'created_date', swap the column name below.
    $stmt = $pdo->prepare("SELECT task_name, course, priority, status, due_date, created_at FROM tasks WHERE user_id = ? ORDER BY due_date ASC");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as $i => $task) {
        fputcsv($output, array(
            $i + 1,
            $task['task_name'],
            $task['course'],
            ucfirst($task['priority']),
            ucfirst($task['status'] ?? 'pending'),
            (!empty($task['created_at']) && $task['created_at'] !== '0000-00-00 00:00:00') ? date('d M Y', strtotime($task['created_at'])) : '—',
            $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—'
        ));
    }
} catch (Exception $e) {
    fputcsv($output, array('Error generating detailed row arrays: ' . $e->getMessage()));
}

fclose($output);
exit();
?>