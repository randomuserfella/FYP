<?php
/**
 * cron_push.php
 * Run this daily via XAMPP's task scheduler or a cron job.
 *
 * What it does:
 *  1. Sends "due tomorrow" reminders for pending tasks
 *  2. Sends "overdue" alerts for tasks past their due date
 *  3. Sends daily habit check-in nudge to all users with subscriptions
 *
 * XAMPP Task Scheduler setup (Windows):
 *   Action: php C:\xampp\htdocs\procratrack\cron_push.php
 *   Schedule: Daily at 08:00
 *
 * Linux cron:
 *   0 8 * * * php /var/www/html/procratrack/cron_push.php
 *
 * You can also trigger it manually in the browser from push_test.php (for demo).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/push_sender.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$log = [];

// ── 1. Due Tomorrow Reminders ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.*, u.name AS user_name
    FROM tasks t
    JOIN users u ON u.id = t.user_id
    WHERE t.due_date = ?
      AND (t.status = 'pending' OR t.status IS NULL OR t.status = '')
      AND t.user_id IN (SELECT DISTINCT user_id FROM push_subscriptions)
");
$stmt->execute([$tomorrow]);
$dueTomorrow = $stmt->fetchAll();

foreach ($dueTomorrow as $task) {
    $title  = '⏰ Task due tomorrow!';
    $body   = "{$task['task_name']}" . ($task['course'] ? " — {$task['course']}" : '');
    $sent   = sendPushToUser($pdo, (int)$task['user_id'], $title, $body,
                '/tasks.php', 'task-due-' . $task['id'], 'task_due');
    $log[]  = "[DUE TOMORROW] user={$task['user_id']} task={$task['task_name']} sent=$sent";
}

// ── 2. Overdue Alerts ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.user_id, COUNT(*) AS overdue_count
    FROM tasks t
    WHERE t.due_date < ?
      AND (t.status = 'pending' OR t.status IS NULL OR t.status = '')
      AND t.user_id IN (SELECT DISTINCT user_id FROM push_subscriptions)
    GROUP BY t.user_id
");
$stmt->execute([$today]);
$overdueGroups = $stmt->fetchAll();

foreach ($overdueGroups as $row) {
    $n     = (int) $row['overdue_count'];
    $title = '🚨 Overdue ' . ($n === 1 ? 'task' : 'tasks') . '!';
    $body  = "You have $n overdue " . ($n === 1 ? 'task' : 'tasks') . '. Don\'t let it pile up!';
    $sent  = sendPushToUser($pdo, (int)$row['user_id'], $title, $body,
               '/tasks.php?filter=overdue', 'overdue-tasks', 'overdue');
    $log[] = "[OVERDUE] user={$row['user_id']} count=$n sent=$sent";
}

// ── 3. Daily Habit Nudge ────────────────────────────────────────────────────
// Only send if user has habits defined but hasn't logged any today
$stmt = $pdo->prepare("
    SELECT DISTINCT ps.user_id
    FROM push_subscriptions ps
    JOIN habits h ON h.user_id = ps.user_id
    WHERE ps.user_id NOT IN (
        SELECT user_id FROM habit_logs WHERE log_date = ? 
    )
");
// Note: if your DB doesn't have habit_logs, comment out the NOT IN clause
try {
    $stmt->execute([$today]);
    $habitUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    // habit_logs table might not exist yet — just get all subscribed users
    $stmt = $pdo->query("SELECT DISTINCT user_id FROM push_subscriptions");
    $habitUsers = $stmt->fetchAll();
}

foreach ($habitUsers as $row) {
    $title = '🌱 Daily habit check-in';
    $body  = 'Have you completed your habits today? Keep your streak alive!';
    $sent  = sendPushToUser($pdo, (int)$row['user_id'], $title, $body,
               '/habits.php', 'daily-habit', 'habit');
    $log[] = "[HABIT] user={$row['user_id']} sent=$sent";
}

// ── Output log ──────────────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] ProcraTrack Cron Push\n";
echo implode("\n", $log) ?: 'No notifications sent.';
echo "\nDone.\n";

// Also write to a log file
$logPath = __DIR__ . '/logs/push_cron.log';
if (!is_dir(dirname($logPath))) mkdir(dirname($logPath), 0755, true);
file_put_contents($logPath,
    "[$timestamp]\n" . implode("\n", $log) . "\n\n",
    FILE_APPEND
);
