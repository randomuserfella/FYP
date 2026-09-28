<?php
ignore_user_abort(true);
set_time_limit(0);

require_once 'config.php';
require_once 'includes/notifier.php';

$lastRun = [];

while (true) {

    // ----- Paste the code from send_reminders.php here -----
    // Keep your existing reminder query and notification logic.
    // -------------------------------------------------------

    sleep(1); // Check every second
}

/**
 * send_reminders.php — ProcraTrack deadline reminders via Telegram + Email
 *
 * Reminder schedule (Google Calendar-style):
 *   📅 7 days before  — "coming up next week"
 *   ⏳ 3 days before  — "due in 3 days"
 *   ⏰ 1 day before   — "due tomorrow"
 *   🔴 Same day       — "due today"
 *   ⏰ 60 min before end time — "due in 1 hour"
 *   🔔 30 min before end time — "due in 30 minutes"
 *   🚨 At end time    — "deadline reached"
 *   🚨 Overdue        — "you missed a deadline" (fires 10 min after end time)
 *   🌱 Daily habit nudge — if habits not logged yet today
 *
 * NOTE: "End Time" in the task form is stored as `due_time` in the DB.
 *       Both 24-hour (23:12) and 12-hour (11:12 PM) formats are accepted
 *       and normalised automatically by normaliseTime().
 */

// ── Security token (leave empty for local XAMPP testing) ─────────────────────
define('CRON_SECRET', 'procratrack_demo_2026');
if (!empty(CRON_SECRET)) {
    $isCli = php_sapi_name() === 'cli';
    if (!$isCli && ($_GET['token'] ?? '') !== CRON_SECRET) {
        http_response_code(403); die('Forbidden');
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/includes/notifier.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$today     = date('Y-m-d');
$timeNow   = date('H:i');        // always 24-hour, e.g. "23:12"
$log       = [];
$totalSent = 0;

// ── normaliseTime() ───────────────────────────────────────────────────────────
// Accepts any time string from the DB and returns "HH:MM" in 24-hour format.
// Handles: "23:12", "23:12:00", "11:12 PM", "11:12 pm", "11:12PM", "1:05 AM"
function normaliseTime(string $raw): string
{
    $raw = trim($raw);
    // Already 24-hour with optional seconds: "23:12" or "23:12:00"
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $raw)) {
        [$h, $m] = explode(':', $raw);
        return sprintf('%02d:%02d', (int)$h, (int)$m);
    }
    // 12-hour with AM/PM: "11:12 PM", "1:05AM", "12:00 am"
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(am|pm)$/i', $raw, $matches)) {
        $h   = (int)$matches[1];
        $m   = (int)$matches[2];
        $mer = strtolower($matches[3]);
        if ($mer === 'am') {
            if ($h === 12) $h = 0;           // 12:xx AM → 00:xx
        } else {
            if ($h !== 12) $h += 12;         // x:xx PM  → (x+12):xx; 12:xx PM stays 12
        }
        return sprintf('%02d:%02d', $h, $m);
    }
    // Fallback — return as-is and let PHP handle it
    return $raw;
}

// ── timeToMinutes() ───────────────────────────────────────────────────────────
// Converts a normalised "HH:MM" string to total minutes since midnight.
function timeToMinutes(string $hhmm): int
{
    [$h, $m] = explode(':', $hhmm);
    return (int)$h * 60 + (int)$m;
}

// ── Dedup: skip if already sent same reminder today ───────────────────────────
function alreadySentToday(PDO $pdo, int $userId, string $tag): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM notification_log
            WHERE user_id = ? AND type = ? AND DATE(sent_at) = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$userId, $tag]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) { return false; }
}

// ── Get pending tasks due in exactly N days, for users with notifications on ──
function getTasksDueInDays(PDO $pdo, int $days): array
{
    $target = date('Y-m-d', strtotime("+$days day"));
    $stmt   = $pdo->prepare("
        SELECT t.*, u.name AS user_name, u.id AS uid,
               u.remind_7day, u.remind_3day, u.remind_1day,
               u.remind_today, u.remind_60min, u.remind_30min,
               u.remind_ondue, u.remind_overdue
        FROM tasks t
        JOIN users u ON u.id = t.user_id
        WHERE t.due_date = ?
          AND t.status   = 'pending'
          AND (u.notify_telegram = 1 OR u.notify_email = 1)
    ");
    $stmt->execute([$target]);
    return $stmt->fetchAll();
}

// ── Priority emoji helper ──────────────────────────────────────────────────────
function priorityEmoji(string $p): string {
    return match($p) { 'high' => '🔴', 'medium' => '🟡', 'low' => '🟢', default => '⚪' };
}

// ── Build a clean Telegram message ────────────────────────────────────────────
function buildTgMsg(string $header, array $task, string $footer = ''): string
{
    $name    = htmlspecialchars($task['task_name']);
    $course  = !empty($task['course']) ? htmlspecialchars($task['course']) : null;
    $pEmoji  = priorityEmoji($task['priority'] ?? 'medium');
    $due     = date('D, d M Y', strtotime($task['due_date']));

    $msg  = "$header\n\n";
    $msg .= "📌 <b>$name</b>\n";
    if ($course) $msg .= "📚 Course: $course\n";
    $msg .= "$pEmoji Priority: " . ucfirst($task['priority'] ?? 'medium') . "\n";
    $msg .= "📅 Deadline: $due\n";
    if ($footer) $msg .= "\n$footer";
    $msg .= "\n\n<i>— ProcraTrack</i>";
    return $msg;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. 7-DAY REMINDER
// ─────────────────────────────────────────────────────────────────────────────
foreach (getTasksDueInDays($pdo, 7) as $task) {
    if (empty($task['remind_7day'])) continue;   // user opted out
    $tag = 'task_7day_' . $task['id'];
    if (alreadySentToday($pdo, (int)$task['uid'], $tag)) continue;

    $tg  = buildTgMsg(
        "📅 <b>Task coming up next week</b>",
        $task,
        "You have 7 days. Plan ahead and make a start early! ✅"
    );
    $sub  = "📅 Reminder: \"{$task['task_name']}\" due in 7 days";
    $html = "<p>Your task <strong>{$task['task_name']}</strong>" .
            (!empty($task['course']) ? " ({$task['course']})" : "") .
            " is due in <strong>7 days</strong> on " . date('D, d M Y', strtotime($task['due_date'])) . ".</p>" .
            "<p>Plan ahead — make a start early! 📅</p>";

    notifyUser($pdo, (int)$task['uid'], $tag, $tg, $sub, $html);
    $log[] = "[7-DAY] uid={$task['uid']} task=\"{$task['task_name']}\"";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. 3-DAY REMINDER
// ─────────────────────────────────────────────────────────────────────────────
foreach (getTasksDueInDays($pdo, 3) as $task) {
    if (empty($task['remind_3day'])) continue;   // user opted out
    $tag = 'task_3day_' . $task['id'];
    if (alreadySentToday($pdo, (int)$task['uid'], $tag)) continue;

    $tg  = buildTgMsg(
        "⏳ <b>Task due in 3 days</b>",
        $task,
        "Don't leave it too late — get started now! ⚡"
    );
    $sub  = "⏳ Reminder: \"{$task['task_name']}\" due in 3 days";
    $html = "<p>Your task <strong>{$task['task_name']}</strong>" .
            (!empty($task['course']) ? " ({$task['course']})" : "") .
            " is due in <strong>3 days</strong> on " . date('D, d M Y', strtotime($task['due_date'])) . ".</p>" .
            "<p>Don't leave it too late — get started now! ⚡</p>";

    notifyUser($pdo, (int)$task['uid'], $tag, $tg, $sub, $html);
    $log[] = "[3-DAY] uid={$task['uid']} task=\"{$task['task_name']}\"";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. 1-DAY REMINDER (due tomorrow)
// ─────────────────────────────────────────────────────────────────────────────
foreach (getTasksDueInDays($pdo, 1) as $task) {
    if (empty($task['remind_1day'])) continue;   // user opted out
    $tag = 'task_1day_' . $task['id'];
    if (alreadySentToday($pdo, (int)$task['uid'], $tag)) continue;

    $tg  = buildTgMsg(
        "⏰ <b>Task due TOMORROW!</b>",
        $task,
        "Submit before midnight tonight. You've got this! 💪"
    );
    $sub  = "⏰ Reminder: \"{$task['task_name']}\" is due TOMORROW";
    $html = "<p>Your task <strong>{$task['task_name']}</strong>" .
            (!empty($task['course']) ? " ({$task['course']})" : "") .
            " is due <strong>tomorrow</strong>, " . date('D, d M Y', strtotime($task['due_date'])) . ".</p>" .
            "<p>Submit before midnight tonight. You've got this! 💪</p>";

    notifyUser($pdo, (int)$task['uid'], $tag, $tg, $sub, $html);
    $log[] = "[1-DAY] uid={$task['uid']} task=\"{$task['task_name']}\"";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. SAME-DAY REMINDER (due today, no specific time set)
// Only fires for tasks WITHOUT a due_time — tasks with due_time get the
// time-based alerts in Section 5 instead.
// ─────────────────────────────────────────────────────────────────────────────
foreach (getTasksDueInDays($pdo, 0) as $task) {
    // Skip tasks that have a specific end time — Section 5 handles them
    if (!empty($task['due_time'])) continue;
    if (empty($task['remind_today'])) continue;  // user opted out

    $tag = 'task_today_' . $task['id'];
    if (alreadySentToday($pdo, (int)$task['uid'], $tag)) continue;

    $pEmoji = priorityEmoji($task['priority'] ?? 'medium');
    $tg     = buildTgMsg(
        "$pEmoji <b>Task due TODAY!</b>",
        $task,
        "This is your final reminder. Complete and submit it now! 🚀"
    );
    $sub  = "$pEmoji \"{$task['task_name']}\" is due TODAY — " . date('d M Y');
    $html = "<p>$pEmoji Your task <strong>{$task['task_name']}</strong>" .
            (!empty($task['course']) ? " ({$task['course']})" : "") .
            " is due <strong>today</strong>, " . date('D, d M Y') . ".</p>" .
            "<p>This is your final reminder. Complete and submit it now! 🚀</p>";

    notifyUser($pdo, (int)$task['uid'], $tag, $tg, $sub, $html);
    $log[] = "[TODAY] uid={$task['uid']} task=\"{$task['task_name']}\" priority={$task['priority']}";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. TIME-BASED REMINDERS (tasks with a specific end time set today)
// ─────────────────────────────────────────────────────────────────────────────
// Alert timeline relative to due_time (the "End Time" field):
//   60 min before → "Task due in 1 hour"
//   30 min before → "Task due in 30 minutes"
//   At due_time   → "Deadline reached — submit now!"
//
// Overdue fires separately in Section 6, only after due_time + 10 min grace.
//
// Both 24-hour ("23:12") and 12-hour ("11:12 PM") formats in the DB are
// accepted — normaliseTime() converts them before any comparison.
//
// Window logic: each alert fires if the script runs within 0–59 minutes
// AFTER the target trigger time (designed for hourly cron). For manual
// testing, run the script at or just after the target time.
// ─────────────────────────────────────────────────────────────────────────────
// remind_col maps each tier to its user preference column
$timeAlerts = [
    60 => ['tag_suffix' => 'time_60min', 'remind_col' => 'remind_60min', 'header' => '⏰ <b>Task due in 1 hour!</b>',     'footer' => 'Wrap up and get it submitted! ⚡'],
    30 => ['tag_suffix' => 'time_30min', 'remind_col' => 'remind_30min', 'header' => '🔔 <b>Task due in 30 minutes!</b>',  'footer' => "Final push — you're almost there! 💪"],
     0 => ['tag_suffix' => 'time_now',   'remind_col' => 'remind_ondue', 'header' => '🚨 <b>Task end time reached!</b>',   'footer' => 'Submit now if you haven\'t already!'],
];

$stmtTimed = $pdo->prepare("
    SELECT t.*, u.name AS user_name, u.id AS uid,
           u.remind_60min, u.remind_30min, u.remind_ondue
    FROM tasks t
    JOIN users u ON u.id = t.user_id
    WHERE t.due_date = ?
      AND t.due_time IS NOT NULL
      AND t.due_time != ''
      AND t.status   = 'pending'
      AND (u.notify_telegram = 1 OR u.notify_email = 1)
");
$stmtTimed->execute([$today]);
$timedTasks = $stmtTimed->fetchAll();

$nowMinutes = timeToMinutes($timeNow);   // already 24-hour from date('H:i')

foreach ($timedTasks as $task) {
    // Normalise whatever format is stored in DB → "HH:MM" 24-hour
    $normalisedDueTime = normaliseTime($task['due_time']);
    $dueMinutes        = timeToMinutes($normalisedDueTime);
    $dueTimeFormatted  = date('g:ia', strtotime($normalisedDueTime)); // display as 12-hour

    foreach ($timeAlerts as $minsAhead => $cfg) {
        // Check user's per-tier preference
        if (empty($task[$cfg['remind_col']])) continue;  // user opted out

        $targetMinute = $dueMinutes - $minsAhead;

        // Fire if script runs within 0–59 minutes after the target trigger time
        $diff = $nowMinutes - $targetMinute;
        if ($diff < 0 || $diff >= 60) continue;

        $tag = 'task_' . $cfg['tag_suffix'] . '_' . $task['id'];
        if (alreadySentToday($pdo, (int)$task['uid'], $tag)) continue;

        $tg  = buildTgMsg(
            $cfg['header'] . " ({$dueTimeFormatted})",
            $task,
            $cfg['footer']
        );
        $sub  = strip_tags($cfg['header']) . ": \"{$task['task_name']}\"";
        $html = "<p>" . strip_tags($cfg['header']) . "</p>" .
                "<p>Task: <strong>{$task['task_name']}</strong>" .
                (!empty($task['course']) ? " ({$task['course']})" : "") . "</p>" .
                "<p>End time: <strong>{$dueTimeFormatted}</strong></p>" .
                "<p>{$cfg['footer']}</p>";

        notifyUser($pdo, (int)$task['uid'], $tag, $tg, $sub, $html);
        $log[] = "[TIME-{$minsAhead}min] uid={$task['uid']} task=\"{$task['task_name']}\" due={$normalisedDueTime} (raw: {$task['due_time']})";
        $totalSent++;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. OVERDUE ALERT (once per day per user)
// ─────────────────────────────────────────────────────────────────────────────
// Overdue = past days, OR today with end time that passed 10+ minutes ago.
// The 10-minute grace gives Section 5's "end time reached" alert room to fire
// before overdue kicks in — prevents both alerts colliding on the same run.
//
// Due_time comparison uses TIME() so MySQL normalises both stored formats.
// ─────────────────────────────────────────────────────────────────────────────
$graceCutoff = date('H:i', strtotime("-10 minutes"));   // now minus 10 min, 24-hour

$stmtOv = $pdo->prepare("
    SELECT t.user_id AS uid, u.name AS user_name, u.remind_overdue,
           COUNT(*) AS cnt,
           GROUP_CONCAT(t.task_name ORDER BY t.due_date ASC SEPARATOR '\n• ') AS task_list
    FROM tasks t
    JOIN users u ON u.id = t.user_id
    WHERE t.status = 'pending'
      AND (u.notify_telegram = 1 OR u.notify_email = 1)
      AND u.remind_overdue = 1
      AND (
          t.due_date < ?
          OR (
              t.due_date = ?
              AND t.due_time IS NOT NULL
              AND t.due_time != ''
              AND TIME(t.due_time) <= TIME(?)
          )
      )
    GROUP BY t.user_id
");
// Pass graceCutoff (10 min ago) so tasks are only overdue after the grace window
$stmtOv->execute([$today, $today, $graceCutoff . ':00']);

foreach ($stmtOv->fetchAll() as $row) {
    $tag = 'task_overdue_summary';
    if (alreadySentToday($pdo, (int)$row['uid'], $tag)) continue;

    $n      = (int)$row['cnt'];
    $plural = $n === 1 ? 'task' : 'tasks';
    $list   = $row['task_list'];

    $tg  = "🚨 <b>You have $n overdue $plural!</b>\n\n";
    $tg .= "• " . str_replace("\n", "\n• ", htmlspecialchars($list)) . "\n\n";
    $tg .= "⚠️ Complete " . ($n === 1 ? 'it' : 'them') . " now before the list grows!\n\n";
    $tg .= "<i>— ProcraTrack</i>";

    $sub   = "🚨 You have $n overdue $plural in ProcraTrack";
    $items = array_map(fn($t) => "<li>$t</li>", explode("\n", htmlspecialchars($list)));
    $html  = "<p>🚨 You have <strong>$n overdue $plural</strong>:</p>" .
             "<ul>" . implode('', $items) . "</ul>" .
             "<p>Complete " . ($n === 1 ? 'it' : 'them') . " now before the list grows!</p>";

    notifyUser($pdo, (int)$row['uid'], $tag, $tg, $sub, $html);
    $log[] = "[OVERDUE] uid={$row['uid']} count=$n (grace cutoff: {$graceCutoff})";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. DAILY HABIT NUDGE (if habits exist but none logged today)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $stmtHabit = $pdo->prepare("
        SELECT DISTINCT u.id AS uid
        FROM users u
        JOIN habits h ON h.user_id = u.id
        WHERE (u.notify_telegram = 1 OR u.notify_email = 1)
          AND u.id NOT IN (
              SELECT user_id FROM habit_logs WHERE log_date = ?
          )
    ");
    $stmtHabit->execute([$today]);
    $habitUsers = $stmtHabit->fetchAll();
} catch (PDOException $e) { $habitUsers = []; }

foreach ($habitUsers as $row) {
    $tag = 'habit_daily_nudge';
    if (alreadySentToday($pdo, (int)$row['uid'], $tag)) continue;

    $tg  = "🌱 <b>Daily habit check-in</b>\n\n";
    $tg .= "Have you logged your habits today?\n";
    $tg .= "Keep your streak alive — it only takes a minute! ✅\n\n";
    $tg .= "<i>— ProcraTrack</i>";

    $sub  = "🌱 Daily habit check-in — don't break your streak!";
    $html = "<p>🌱 Have you logged your habits today?</p>" .
            "<p>Keep your streak alive — it only takes a minute! ✅</p>";

    notifyUser($pdo, (int)$row['uid'], $tag, $tg, $sub, $html);
    $log[] = "[HABIT] uid={$row['uid']}";
    $totalSent++;
}

// ─────────────────────────────────────────────────────────────────────────────
// Output + log file
// ─────────────────────────────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
$output    = "[$timestamp] ProcraTrack Reminders — $timeNow MYT\n"
           . implode("\n", $log ?: ['No notifications sent.'])
           . "\nTotal sent: $totalSent\nDone.\n";

echo nl2br(htmlspecialchars($output));

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
file_put_contents($logDir . '/reminders.log', $output . "\n", FILE_APPEND);
