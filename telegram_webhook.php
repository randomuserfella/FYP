<?php
/**
 * telegram_webhook.php — ProcraTrack Telegram Bot
 * No changes to bot logic — only token source changed
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/includes/notifier.php'; // defines TELEGRAM_BOT_TOKEN

// ─── Constants ───────────────────────────────────────────────
// BOT_TOKEN comes from TELEGRAM_BOT_TOKEN in includes/notifier.php
if (!defined('BOT_TOKEN')) define('BOT_TOKEN', TELEGRAM_BOT_TOKEN);
if (!defined('TG_API'))    define('TG_API',    'https://api.telegram.org/bot' . BOT_TOKEN);
if (!defined('LOG_FILE'))  define('LOG_FILE',  __DIR__ . '/bot.log');

// ─── Read Telegram payload ────────────────────────────────────
$input  = file_get_contents('php://input');
$update = json_decode($input, true);

http_response_code(200);
header('Content-Type: application/json');
echo '{}';

if (!$update || empty($update['message'])) exit;

// ─── Dedup: skip replayed or already-processed updates ────────
// Telegram may resend the same update_id if it doesn't get a 200 fast enough.
// We track the last processed update_id in a small file to skip replays.
$update_id   = (int)($update['update_id'] ?? 0);
$dedup_file  = __DIR__ . '/logs/tg_last_update.txt';
if ($update_id > 0) {
    $last_id = (int)@file_get_contents($dedup_file);
    if ($update_id <= $last_id) exit;   // already handled — silently discard
    @file_put_contents($dedup_file, $update_id, LOCK_EX);
}

$msg     = $update['message'];
$chat_id = (string)($msg['chat']['id'] ?? '');
$text    = trim($msg['text'] ?? '');
$first   = $msg['from']['first_name'] ?? 'there';

if (!$chat_id || !$text) exit;

// ─── Logging ─────────────────────────────────────────────────
function blog(string $msg): void {
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

blog("IN  chat=$chat_id text=$text");

// ─── tg_send() ───────────────────────────────────────────────
// Local send for bot replies — separate from sendTelegram() in notifier.php
function tg_send(string $chat_id, string $text, array $kb = []): void {
    $payload = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($kb) {
        $payload['reply_markup'] = json_encode([
            'keyboard'          => $kb,
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ]);
    } else {
        $payload['reply_markup'] = json_encode(['remove_keyboard' => true]);
    }

    $ch = curl_init(TG_API . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) blog("CURL_ERR $err");
    else {
        $d = json_decode($res, true);
        if (empty($d['ok'])) blog("TG_FAIL " . ($d['description'] ?? $res));
        else blog("OUT chat=$chat_id text=" . mb_substr($text, 0, 60));
    }
}

// ─── Session helpers ─────────────────────────────────────────
function ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_sessions (
        chat_id    VARCHAR(20) PRIMARY KEY,
        step       VARCHAR(30) NOT NULL DEFAULT 'idle',
        data       TEXT        NOT NULL DEFAULT '{}',
        updated_at DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Fix existing tables that may have data column as nullable (from old telegram_handler.php)
    try {
        $pdo->exec("ALTER TABLE bot_sessions MODIFY COLUMN data TEXT NOT NULL DEFAULT '{}'");
    } catch (Exception $e) {}
}

function get_session(PDO $pdo, string $chat_id): array {
    $s = $pdo->prepare("SELECT step, data FROM bot_sessions WHERE chat_id=?");
    $s->execute([$chat_id]);
    $row = $s->fetch();
    return $row
        ? ['step' => $row['step'], 'data' => json_decode($row['data'] ?: '{}', true)]
        : ['step' => 'idle', 'data' => []];
}

function set_session(PDO $pdo, string $chat_id, string $step, array $data = []): void {
    try {
        $pdo->prepare("INSERT INTO bot_sessions (chat_id,step,data) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE step=VALUES(step),data=VALUES(data),updated_at=NOW()")
            ->execute([$chat_id, $step, json_encode($data)]);
    } catch (Exception $e) {
        file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " SET_SESSION_ERR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function clear_session(PDO $pdo, string $chat_id): void {
    set_session($pdo, $chat_id, 'idle', []);
}

function get_user_id(PDO $pdo, string $chat_id): ?int {
    $s = $pdo->prepare("SELECT id FROM users WHERE telegram_chat_id=?");
    $s->execute([$chat_id]);
    $row = $s->fetch();
    return $row ? (int)$row['id'] : null;
}

// ─── Date parser ─────────────────────────────────────────────
function parse_date(string $input): ?string {
    $input = trim(strtolower($input));
    $today = date('Y-m-d');

    if ($input === 'today')    return $today;
    if ($input === 'tomorrow') return date('Y-m-d', strtotime('+1 day'));

    // dd/mm/yyyy or dd-mm-yyyy
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $input, $m)) {
        if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return null;
        $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return ($date < $today) ? null : $date;   // reject past
    }

    // "21 June" or "21 June 2026"
    $titleInput = implode(' ', array_map('ucfirst', explode(' ', $input)));
    foreach (['d F Y', 'd F'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $titleInput);
        if ($d && $d->getLastErrors()['warning_count'] === 0 && $d->getLastErrors()['error_count'] === 0) {
            $date = $d->format('Y-m-d');
            if ($date < $today) return null;       // reject past
            return $date;
        }
    }

    // fallback via strtotime
    $ts = strtotime($input);
    if ($ts && $ts > 0) {
        $date = date('Y-m-d', $ts);
        return ($date < $today) ? null : $date;   // reject past
    }
    return null;
}

// ─── Boot ────────────────────────────────────────────────────

// ─── Time parser ─────────────────────────────────────────────
function parse_time(string $input): ?string {
    $input = trim(strtolower($input));
    if ($input === 'skip') return null;
    $input = str_replace('.', ':', $input);
    $input = preg_replace('/\s+/', '', $input);
    // Handle am/pm: "9am", "9:30am"
    if (preg_match('/^(\d{1,2}):?(\d{2})?\s*(am|pm)$/', $input, $m)) {
        $h   = (int)$m[1];
        $min = isset($m[2]) ? (int)$m[2] : 0;
        if ($m[3] === 'pm' && $h !== 12) $h += 12;
        if ($m[3] === 'am' && $h === 12) $h = 0;
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
        return sprintf('%02d:%02d:00', $h, $min);
    }
    // Handle 24h: "14:30", "1430"
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $input, $m)) {
        $h = (int)$m[1]; $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
        return sprintf('%02d:%02d:00', $h, $min);
    }
    if (preg_match('/^(\d{3,4})$/', $input, $m)) {
        $s   = str_pad($m[1], 4, '0', STR_PAD_LEFT);
        $h   = (int)substr($s, 0, 2);
        $min = (int)substr($s, 2, 2);
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
        return sprintf('%02d:%02d:00', $h, $min);
    }
    return null;
}

try { ensure_table($pdo); } catch (Exception $e) { blog("TABLE_ERR " . $e->getMessage()); exit; }

$sess = get_session($pdo, $chat_id);
$step = $sess['step'];
$data = $sess['data'];
$cmd  = strtolower($text);

blog("STEP=$step");

// ─── /cancel ───────────────────────────────────────────────────
if ($cmd === '/cancel' || $cmd === 'cancel') {
    clear_session($pdo, $chat_id);
    tg_send($chat_id, "❌ Cancelled. Type /addtask to start again or /help for commands.");
    exit;
}


// ─── /start ──────────────────────────────────────────────────
if (str_starts_with($cmd, '/start')) {
    clear_session($pdo, $chat_id);
    $uid = get_user_id($pdo, $chat_id);
    if ($uid) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $urow = $stmt->fetch();
        tg_send($chat_id,
            "👋 Welcome back, <b>" . htmlspecialchars($urow['name'] ?? $first) . "</b>!\n\n" .
            "✅ Your Telegram is already connected to ProcraTrack.\n\n" .
            "📝 /addtask — add a new task\n📋 /tasks — view pending tasks\n❓ /help — all commands"
        );
    } else {
        tg_send($chat_id,
            "👋 Hi <b>$first</b>! Welcome to <b>ProcraTrack Bot</b>.\n\n" .
            "Your <b>Chat ID</b> is:\n\n<code>$chat_id</code>\n\n" .
            "📋 Copy this and paste it into <b>ProcraTrack → Notifications</b> to connect.\n\n" .
            "Once connected:\n📝 /addtask — add a task\n📋 /tasks — view today's tasks\n❓ /help — all commands"
        );
    }
    exit;
}

// ─── /help ───────────────────────────────────────────────────
if ($cmd === '/help') {
    tg_send($chat_id,
        "📚 <b>ProcraTrack Bot Commands</b>\n\n" .
        "/start — get started or check your connection\n" .
        "/addtask — add a new task\n" .
        "/tasks — today's pending tasks\n" .
        "/alltasks — all tasks including completed\n" .
        "/deletetask — pick and delete a pending task\n" .
        "/cancel — cancel current action\n" .
        "/help — this message"
    );
    exit;
}

// ─── /tasks ──────────────────────────────────────────────────
if ($cmd === '/tasks') {
    $uid = get_user_id($pdo, $chat_id);
    if (!$uid) {
        tg_send($chat_id, "⚠️ Connect your account first.\n\nYour Chat ID: <code>$chat_id</code>\n\nPaste it into ProcraTrack → Notifications.");
        exit;
    }
    $today = date('Y-m-d');
    $stmt  = $pdo->prepare("SELECT task_name, due_date, priority, course, start_time, end_time FROM tasks WHERE user_id=? AND status='pending' AND due_date<=? ORDER BY due_date ASC LIMIT 10");
    $stmt->execute([$uid, $today]);
    $tasks = $stmt->fetchAll();

    if (empty($tasks)) {
        tg_send($chat_id, "✅ No tasks due today or overdue. Use /addtask to add one!");
        exit;
    }

    $reply = "📋 <b>Today's Tasks (" . count($tasks) . ")</b>\n\n";
    foreach ($tasks as $t) {
        $pri  = match($t['priority']) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢',default=>'⚪' };
        $due  = date('d M', strtotime($t['due_date']));
        $late = $t['due_date'] < $today ? " ⚠️ Overdue" : "";
        $reply .= "- <b>" . htmlspecialchars($t['task_name']) . "</b>\n";
        $reply .= "  $pri " . ucfirst($t['priority']) . " | 📅 $due$late\n";
        if (!empty($t['start_time'])) {
            $time = "⏰ " . date('g:i A', strtotime($t['start_time']));
            if (!empty($t['end_time'])) $time .= " → " . date('g:i A', strtotime($t['end_time']));
            $reply .= "  $time\n";
        }
        $reply .= "\n";
    }
    tg_send($chat_id, rtrim($reply));
    exit;
}

// ─── /alltasks ───────────────────────────────────────────────
if ($cmd === '/alltasks') {
    $uid = get_user_id($pdo, $chat_id);
    if (!$uid) {
        tg_send($chat_id, "⚠️ Connect your account first.\n\nYour Chat ID: <code>$chat_id</code>");
        exit;
    }
    $stmt = $pdo->prepare("SELECT task_name, due_date, priority, course, status, start_time, end_time FROM tasks WHERE user_id=? ORDER BY due_date ASC");
    $stmt->execute([$uid]);
    $tasks = $stmt->fetchAll();

    if (empty($tasks)) {
        tg_send($chat_id, "✅ You have no tasks yet. Use /addtask to create one!");
        exit;
    }

    $today   = date('Y-m-d');
    $pending = array_filter($tasks, fn($t) => $t['status'] === 'pending');
    $done    = array_filter($tasks, fn($t) => $t['status'] !== 'pending');
    $chunks  = [];
    $current = "📋 <b>All Your Tasks (" . count($tasks) . " total)</b>\n\n";

    if (!empty($pending)) {
        $current .= "⏳ <b>Pending (" . count($pending) . ")</b>\n";
        foreach ($pending as $t) {
            $pri  = match($t['priority']) { 'high'=>'🔴 High','medium'=>'🟡 Medium','low'=>'🟢 Low',default=>'⚪' };
            $due  = date('d M Y', strtotime($t['due_date']));
            $days = (int)ceil((strtotime($t['due_date']) - strtotime($today)) / 86400);
            $status = $t['due_date'] < $today ? "⚠️ Overdue " . abs($days) . "d"
                    : ($t['due_date'] === $today ? "🔥 Due today"
                    : ($days <= 3 ? "⚡ {$days}d left"
                    : ($days <= 7 ? "⏰ {$days}d left" : "📅 {$days}d left")));
            $line  = "\n- <b>" . htmlspecialchars($t['task_name']) . "</b>\n  | $pri\n  | 📅 $due ($status)";
            if (!empty($t['start_time'])) {
                $tline = "⏰ " . date('g:i A', strtotime($t['start_time']));
                if (!empty($t['end_time'])) $tline .= " → " . date('g:i A', strtotime($t['end_time']));
                $line .= "\n  | $tline";
            }
            if ($t['course']) $line .= "\n  | 📚 " . htmlspecialchars($t['course']);
            $line .= "\n";
            if (strlen($current) + strlen($line) > 3800) { $chunks[] = rtrim($current); $current = $line; }
            else $current .= $line;
        }
    }

    if (!empty($done)) {
        $sep = "\n\n✅ <b>Completed (" . count($done) . ")</b>\n";
        if (strlen($current) + strlen($sep) > 3800) { $chunks[] = rtrim($current); $current = $sep; }
        else $current .= $sep;
        foreach ($done as $t) {
            $due  = date('d M Y', strtotime($t['due_date']));
            $line = "\n- <s>" . htmlspecialchars($t['task_name']) . "</s>\n  | 📅 $due";
            if ($t['course']) $line .= "\n  | 📚 " . htmlspecialchars($t['course']);
            $line .= "\n";
            if (strlen($current) + strlen($line) > 3800) { $chunks[] = rtrim($current); $current = $line; }
            else $current .= $line;
        }
    }

    $chunks[] = rtrim($current);
    $total = count($chunks);
    foreach ($chunks as $i => $chunk) {
        $out = $chunk;
        if ($total > 1) $out .= "\n\n<i>Page " . ($i+1) . " of $total</i>";
        tg_send($chat_id, $out);
    }
    exit;
}

// ─── /addtask ────────────────────────────────────────────────
if ($cmd === '/addtask') {
    $uid = get_user_id($pdo, $chat_id);
    if (!$uid) {
        tg_send($chat_id, "⚠️ Connect your account first.\n\nYour Chat ID: <code>$chat_id</code>\n\nPaste it into ProcraTrack → Notifications.");
        exit;
    }
    set_session($pdo, $chat_id, 'awaiting_name', []);
    tg_send($chat_id, "📝 <b>New Task — Step 1 of 6</b>\n\nWhat is the <b>task name</b>?\n\n<i>e.g. Write Chapter 3, Submit Lab Report</i>\n\nType /cancel to stop.");
    exit;
}

// ─── Conversation steps ───────────────────────────────────────
if ($step === 'awaiting_name') {
    if (strlen($text) < 2) { tg_send($chat_id, "⚠️ Name too short. Please enter a valid task name."); exit; }
    $data['task_name'] = $text;
    set_session($pdo, $chat_id, 'awaiting_date', $data);
    tg_send($chat_id,
        "✅ Name: <b>" . htmlspecialchars($text) . "</b>\n\n📅 <b>Step 2 of 6 — Deadline</b>\n\nWhat is the deadline?\n\n" .
        "Accepted formats:\n• <code>25 June</code>\n• <code>25/06/2026</code>\n• <code>today</code> or <code>tomorrow</code>",
        [['today', 'tomorrow']]
    );
    exit;
}

if ($step === 'awaiting_date') {
    $date = parse_date($text);
    if (!$date) {
        tg_send($chat_id,
            "⚠️ Couldn't read that date, or it's already in the past. Try again:\n\n• <code>25 June</code>\n• <code>25/06/2026</code>\n• <code>today</code> or <code>tomorrow</code>",
            [['today', 'tomorrow']]
        );
        exit;
    }
    $data['due_date'] = $date;
    set_session($pdo, $chat_id, 'awaiting_start_time', $data);
    tg_send($chat_id,
        "✅ Deadline: <b>" . date('D, d M Y', strtotime($date)) . "</b>\n\n⏰ <b>Step 3 of 6 — Start Time</b>\n\nWhat time do you plan to <b>start</b>?\n\nAccepted formats:\n• <code>9am</code> or <code>9:30am</code>\n• <code>14:00</code> (24-hour)\n\nTap <b>Skip</b> to leave blank.",
        [['Skip']]
    );
    exit;
}

if ($step === 'awaiting_start_time') {
    $parsed = (strtolower(trim($text)) === 'skip') ? null : parse_time($text);
    if (strtolower(trim($text)) !== 'skip' && $parsed === null) {
        tg_send($chat_id,
            "⚠️ Couldn't read that time. Try again:\n\n• <code>9am</code> or <code>9:30pm</code>\n• <code>14:00</code> (24-hour)\n\nOr tap <b>Skip</b>.",
            [['Skip']]
        );
        exit;
    }
    $data['start_time'] = $parsed;
    set_session($pdo, $chat_id, 'awaiting_end_time', $data);
    $shown = $parsed ? date('g:i A', strtotime($parsed)) : 'Not set';
    tg_send($chat_id,
        "✅ Start Time: <b>$shown</b>\n\n🏁 <b>Step 4 of 6 — End Time</b>\n\nWhat time do you plan to <b>finish</b>?\n\nAccepted formats:\n• <code>9am</code> or <code>9:30pm</code>\n• <code>14:00</code> (24-hour)\n\nTap <b>Skip</b> to leave blank.",
        [['Skip']]
    );
    exit;
}

if ($step === 'awaiting_end_time') {
    $parsed = (strtolower(trim($text)) === 'skip') ? null : parse_time($text);
    if (strtolower(trim($text)) !== 'skip' && $parsed === null) {
        tg_send($chat_id,
            "⚠️ Couldn't read that time. Try again:\n\n• <code>9am</code> or <code>9:30pm</code>\n• <code>14:00</code> (24-hour)\n\nOr tap <b>Skip</b>.",
            [['Skip']]
        );
        exit;
    }
    $data['end_time'] = $parsed;
    set_session($pdo, $chat_id, 'awaiting_course', $data);
    $shown = $parsed ? date('g:i A', strtotime($parsed)) : 'Not set';
    tg_send($chat_id,
        "✅ End Time: <b>$shown</b>\n\n📚 <b>Step 5 of 6 — Course</b>\n\nWhat course is this for?\n<i>e.g. CS301, FYP, Mathematics</i>\n\nTap <b>Skip</b> if not applicable.",
        [['Skip']]
    );
    exit;
}

if ($step === 'awaiting_course') {
    $data['course'] = (strtolower($text) === 'skip') ? '' : $text;
    set_session($pdo, $chat_id, 'awaiting_priority', $data);
    $shown = empty($data['course']) ? 'None' : htmlspecialchars($data['course']);
    tg_send($chat_id,
        "✅ Course: <b>$shown</b>\n\n🎯 <b>Step 6 of 6 — Priority</b>\n\n🔴 High — urgent\n🟡 Medium — important\n🟢 Low — when time allows",
        [['🔴 High', '🟡 Medium', '🟢 Low']]
    );
    exit;
}

if ($step === 'awaiting_priority') {
    $map      = ['🔴 high'=>'high','high'=>'high','🟡 medium'=>'medium','medium'=>'medium','🟢 low'=>'low','low'=>'low'];
    $priority = $map[strtolower($text)] ?? 'medium';
    $uid      = get_user_id($pdo, $chat_id);
    if (!$uid) { clear_session($pdo, $chat_id); tg_send($chat_id, "❌ Could not find your account. Please re-link in ProcraTrack → Notifications."); exit; }
    try {
        // BUG FIX: also store end_time into due_time so send_reminders.php
        // time-based alerts (which query due_time IS NOT NULL) can fire.
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id,task_name,course,priority,due_date,start_time,end_time,due_time,status) VALUES (?,?,?,?,?,?,?,?,'pending')");
        $stmt->execute([$uid, $data['task_name'], $data['course'] ?? '', $priority, $data['due_date'], $data['start_time'] ?? null, $data['end_time'] ?? null, $data['end_time'] ?? null]);
        clear_session($pdo, $chat_id);
        $dot   = match($priority) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢' };
        $days  = (int)ceil((strtotime($data['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $count = match(true) { $days<=0=>"⚠️ Already overdue!",$days===1=>"⏰ Due tomorrow!",$days<=3=>"⚡ Only $days days away!",default=>"📅 $days days to go." };
        $reply = "✅ <b>Task saved!</b>\n\n📌 <b>" . htmlspecialchars($data['task_name']) . "</b>\n";
        if (!empty($data['course'])) $reply .= "📚 " . htmlspecialchars($data['course']) . "\n";
        $reply .= "$dot " . ucfirst($priority) . " priority\n📅 " . date('D, d M Y', strtotime($data['due_date']));
        if (!empty($data['start_time'])) $reply .= "\n⏰ " . date('g:i A', strtotime($data['start_time']));
        if (!empty($data['end_time']))   $reply .= " → " . date('g:i A', strtotime($data['end_time']));
        $reply .= "\n\n$count";
        tg_send($chat_id, $reply);
    } catch (Exception $e) {
        clear_session($pdo, $chat_id);
        blog("SAVE_ERR " . $e->getMessage());
        tg_send($chat_id, "❌ Failed to save task. Try /addtask again.");
    }
    exit;
}


// ─── /deletetask ─────────────────────────────────────────────
if ($cmd === '/deletetask') {
    $uid = get_user_id($pdo, $chat_id);
    if (!$uid) {
        tg_send($chat_id, "⚠️ Connect your account first.\n\nYour Chat ID: <code>$chat_id</code>");
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, task_name, due_date, priority, course FROM tasks WHERE user_id=? AND status='pending' ORDER BY due_date ASC LIMIT 20");
    $stmt->execute([$uid]);
    $tasks = $stmt->fetchAll();

    if (empty($tasks)) {
        tg_send($chat_id, "✅ You have no pending tasks to delete.");
        exit;
    }

    $reply = "🗑️ <b>Delete a Task</b>\n\nReply with the <b>number</b> or <b>name</b> of the task to delete:\n\n";
    foreach ($tasks as $i => $t) {
        $num  = $i + 1;
        $pri  = match($t['priority']) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢',default=>'⚪' };
        $due  = date('d M Y', strtotime($t['due_date']));
        $reply .= "<b>$num.</b> " . htmlspecialchars($t['task_name']) . " $pri | 📅 $due\n";
    }
    $reply .= "\nType /cancel to stop.";

    // Store task list in session for matching
    $task_index = array_map(fn($t) => ['id' => $t['id'], 'task_name' => $t['task_name'], 'due_date' => $t['due_date'], 'priority' => $t['priority'], 'course' => $t['course']], $tasks);
    set_session($pdo, $chat_id, 'awaiting_delete_select', ['task_index' => $task_index]);
    tg_send($chat_id, $reply);
    exit;
}

// ─── Delete: selection step ───────────────────────────────────
if ($step === 'awaiting_delete_select') {
    $tasks = $data['task_index'] ?? [];
    $match = null;

    // Match by number
    if (ctype_digit(trim($text))) {
        $idx = (int)trim($text) - 1;
        if (isset($tasks[$idx])) $match = $tasks[$idx];
    }

    // Match by name (case-insensitive, partial ok)
    if (!$match) {
        $lower = strtolower(trim($text));
        foreach ($tasks as $t) {
            if (strtolower($t['task_name']) === $lower || str_contains(strtolower($t['task_name']), $lower)) {
                $match = $t;
                break;
            }
        }
    }

    if (!$match) {
        tg_send($chat_id,
            "⚠️ Couldn't find that task. Reply with the number or name from the list.\n\nType /cancel to stop.",
            []
        );
        exit;
    }

    $pri  = match($match['priority']) { 'high'=>'🔴 High','medium'=>'🟡 Medium','low'=>'🟢 Low',default=>'⚪' };
    $due  = date('D, d M Y', strtotime($match['due_date']));
    $course_line = !empty($match['course']) ? "\n📚 " . htmlspecialchars($match['course']) : '';

    set_session($pdo, $chat_id, 'awaiting_delete_confirm', [
        'delete_task_id'   => $match['id'],
        'delete_task_name' => $match['task_name'],
    ]);

    tg_send($chat_id,
        "🗑️ <b>Delete this task?</b>\n\n📌 <b>" . htmlspecialchars($match['task_name']) . "</b>$course_line\n$pri | 📅 $due\n\n<b>This cannot be undone.</b>",
        [['✅ Yes, delete', '❌ No, keep it']]
    );
    exit;
}

// ─── Delete: confirm step ─────────────────────────────────────
if ($step === 'awaiting_delete_confirm') {
    $answer = strtolower(trim($text));
    $is_yes = in_array($answer, ['yes', '✅ yes, delete', 'y', 'delete']);

    if (!$is_yes) {
        set_session($pdo, $chat_id, 'idle', []);
        tg_send($chat_id, "❌ Cancelled. Task kept.");
        exit;
    }

    $uid = get_user_id($pdo, $chat_id);
    $del = $pdo->prepare("DELETE FROM tasks WHERE id=? AND user_id=?");
    $del->execute([$data['delete_task_id'], $uid]);

    if ($del->rowCount() > 0) {
        set_session($pdo, $chat_id, 'idle', []);
        tg_send($chat_id,
            "🗑️ <b>Deleted!</b>\n\n📌 <b>" . htmlspecialchars($data['delete_task_name']) . "</b>\n\nUse /deletetask to remove another, or /addtask to add a new one."
        );
    } else {
        set_session($pdo, $chat_id, 'idle', []);
        tg_send($chat_id, "⚠️ Task not found — it may have already been deleted.");
    }
    exit;
}

// ─── Unknown / idle ───────────────────────────────────────────
tg_send($chat_id,
    "👋 I'm ProcraTrack Bot!\n\n/addtask — add a task\n/tasks — today's tasks\n/alltasks — all tasks\n/deletetask — delete a task\n/help — all commands"
);
