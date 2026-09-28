<?php
/**
 * telegram_handler.php — ProcraTrack Telegram Bot (HANDLER)
 *
 * Called as a background CLI process by telegram_webhook.php:
 *   php telegram_handler.php "<base64-encoded-json>"
 *
 * Commands:
 *   /start    — show Chat ID to connect account
 *   /addtask  — interactive task creation (name → deadline → course → priority)
 *   /tasks    — list today's pending tasks
 *   /help     — show available commands
 *   /id       — re-show Chat ID
 */

// Decode the payload passed from telegram_webhook.php
$input  = base64_decode($argv[1] ?? '');
$update = json_decode($input, true);

if (!$update || empty($update['message'])) exit;

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/notifier.php';

$msg     = $update['message'];
$chat_id = (string)$msg['chat']['id'];
$text    = trim($msg['text'] ?? '');
$first   = $msg['chat']['first_name'] ?? 'there';

// ── Ensure bot_sessions table exists ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_sessions (
        chat_id    VARCHAR(20) PRIMARY KEY,
        step       VARCHAR(30) NOT NULL DEFAULT 'idle',
        data       TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/webhook_debug.log',
        date('H:i:s') . " TABLE ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

// ── Debug: log every incoming message ────────────────────────────────────────
file_put_contents(__DIR__ . '/webhook_debug.log',
    date('H:i:s') . " CMD: $text | STEP: " . (getSession($pdo, $chat_id)['step'] ?? '?') . "\n",
    FILE_APPEND
);

// ── Helpers ───────────────────────────────────────────────────────────────────
function tg_send(string $chat_id, string $text, array $keyboard = []): void {
    $url     = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) {
        $payload['reply_markup'] = json_encode([
            'keyboard'          => $keyboard,
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ]);
    } else {
        $payload['reply_markup'] = json_encode(['remove_keyboard' => true]);
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query($payload),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result) {
        $decoded = json_decode($result, true);
        if (empty($decoded['ok'])) {
            file_put_contents(__DIR__ . '/webhook_debug.log',
                date('H:i:s') . " TG_SEND FAIL: " . ($decoded['description'] ?? $result) . "\n",
                FILE_APPEND
            );
        }
    }
}

function getSession(PDO $pdo, string $chat_id): array {
    $s = $pdo->prepare("SELECT step, data FROM bot_sessions WHERE chat_id=?");
    $s->execute([$chat_id]);
    $row = $s->fetch();
    return $row
        ? ['step' => $row['step'], 'data' => json_decode($row['data'] ?? '{}', true)]
        : ['step' => 'idle', 'data' => []];
}

function setSession(PDO $pdo, string $chat_id, string $step, array $data = []): void {
    $pdo->prepare("INSERT INTO bot_sessions (chat_id, step, data) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE step=VALUES(step), data=VALUES(data), updated_at=NOW()")
        ->execute([$chat_id, $step, json_encode($data)]);
}

function clearSession(PDO $pdo, string $chat_id): void {
    $pdo->prepare("INSERT INTO bot_sessions (chat_id, step, data) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE step='idle', data='{}', updated_at=NOW()")
        ->execute([$chat_id, 'idle', '{}']);
}

function getUserId(PDO $pdo, string $chat_id): ?int {
    $s = $pdo->prepare("SELECT id FROM users WHERE telegram_chat_id=?");
    $s->execute([$chat_id]);
    $row = $s->fetch();
    return $row ? (int)$row['id'] : null;
}

function parseDate(string $input): ?string {
    $input = trim(strtolower($input));

    if ($input === 'today')    return date('Y-m-d');
    if ($input === 'tomorrow') return date('Y-m-d', strtotime('+1 day'));

    // DD/MM/YYYY or DD-MM-YYYY (must check before strtotime — PHP reads slashes as MM/DD)
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $input, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    // "20 June" or "20 June 2026"
    $d = DateTime::createFromFormat('d F Y', $input);
    if (!$d) $d = DateTime::createFromFormat('d F', $input);
    if ($d) {
        $date = $d->format('Y-m-d');
        if ($date < date('Y-m-d')) {
            $d->modify('+1 year');
            $date = $d->format('Y-m-d');
        }
        return $date;
    }

    // Fallback: strtotime for formats like "June 20"
    $ts = strtotime($input);
    if ($ts && $ts > 0) {
        $date = date('Y-m-d', $ts);
        if ($date < date('Y-m-d')) {
            $date = date('Y', strtotime('+1 year')) . substr($date, 4);
        }
        return $date;
    }

    return null;
}

// ── Load session ──────────────────────────────────────────────────────────────
$session = getSession($pdo, $chat_id);
$step    = $session['step'];
$data    = $session['data'];

// ── /cancel — works at any point ─────────────────────────────────────────────
if (strtolower($text) === '/cancel' || strtolower($text) === 'cancel') {
    clearSession($pdo, $chat_id);
    tg_send($chat_id, "❌ Cancelled. Type /addtask to start again or /help for commands.");
    exit;
}

// ── /restart — clears session and re-greets (useful if bot seems stuck) ───────────
if (strtolower($text) === '/restart') {
    clearSession($pdo, $chat_id);
    $user_id = getUserId($pdo, $chat_id);

    tg_send($chat_id, "🔄 Restarting bot...");

    if ($user_id) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id=?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $name = $user['name'] ?? $first;

        tg_send($chat_id,
            "✅ All clear, <b>{$name}</b>! Bot has been reset.\n\n"
          . "Your account is still connected to ProcraTrack. Ready to go!\n\n"
          . "📝 /addtask — add a new task\n"
          . "📋 /tasks — view pending tasks\n"
          . "❓ /help — show all commands"
        );
    } else {
        tg_send($chat_id,
            "✅ Bot has been reset.\n\n"
          . "Your <b>Telegram Chat ID</b> is:\n\n"
          . "<code>{$chat_id}</code>\n\n"
          . "📋 Paste this into <b>ProcraTrack → Notifications</b> to connect your account."
        );
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// COMMANDS
// ════════════════════════════════════════════════════════════════════════════

// ── /start ────────────────────────────────────────────────────────────────────
if (str_starts_with($text, '/start')) {
    clearSession($pdo, $chat_id);
    $user_id = getUserId($pdo, $chat_id);

    if ($user_id) {
        // Account already linked — show welcome back message
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $name = $user['full_name'] ?? $first;

        tg_send($chat_id,
            "👋 Welcome back, <b>{$name}</b>!\n\n"
          . "✅ Your Telegram is already connected to <b>ProcraTrack</b>.\n\n"
          . "What would you like to do?\n\n"
          . "📝 /addtask — add a new task with deadline\n"
          . "📋 /tasks — view your pending tasks\n"
          . "🆔 /id — show your Chat ID\n"
          . "❓ /help — show all commands"
        );
    } else {
        // Not linked yet — show Chat ID to copy
        tg_send($chat_id,
            "👋 Hi <b>{$first}</b>! Welcome to <b>ProcraTrack Bot</b>.\n\n"
          . "Your <b>Telegram Chat ID</b> is:\n\n"
          . "<code>{$chat_id}</code>\n\n"
          . "📋 Copy this and paste it into <b>ProcraTrack → Notifications</b> to connect your account.\n\n"
          . "Once connected you can use:\n"
          . "📝 /addtask — add a new task with deadline\n"
          . "📋 /tasks — view today's pending tasks\n"
          . "❓ /help — show all commands"
        );
    }
    exit;
}

// ── /id ───────────────────────────────────────────────────────────────────────
if ($text === '/id') {
    tg_send($chat_id, "Your Chat ID: <code>{$chat_id}</code>");
    exit;
}

// ── /help ─────────────────────────────────────────────────────────────────────
if ($text === '/help') {
    clearSession($pdo, $chat_id);
    tg_send($chat_id,
        "🤖 <b>ProcraTrack Bot — Commands</b>\n\n"
      . "/addtask — Create a new task with deadline\n"
      . "/tasks — View your pending tasks for today\n"
      . "/id — Show your Chat ID\n"
      . "/cancel — Cancel any current action\n"
      . "/restart — Reset bot if it seems stuck\n"
      . "/help — Show this message\n\n"
      . "💡 Tip: After connecting your Chat ID in ProcraTrack, you'll automatically receive deadline reminders and focus alerts here."
    );
    exit;
}

// ── /tasks — list today's + overdue tasks ─────────────────────────────────────
if ($text === '/tasks') {
    clearSession($pdo, $chat_id);
    $user_id = getUserId($pdo, $chat_id);
    if (!$user_id) {
        tg_send($chat_id,
            "⚠️ Your Telegram is not connected to a ProcraTrack account yet.\n\n"
          . "Copy your Chat ID <code>{$chat_id}</code> and paste it in ProcraTrack → Notifications."
        );
        exit;
    }

    $nowTime = date('H:i:s');
    $todayDate = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT task_name, course, priority, due_date, due_time, start_time
        FROM tasks
        WHERE user_id=? AND status='pending' AND due_date <= CURDATE()
        ORDER BY due_date ASC, COALESCE(due_time, '23:59:59') ASC, FIELD(priority,'high','medium','low')
        LIMIT 15
    ");
    $stmt->execute([$user_id]);
    $allTasks = $stmt->fetchAll();

    // Separate truly overdue from still-pending
    $pending  = [];
    $overdue  = [];
    foreach ($allTasks as $t) {
        if ($t['due_date'] < $todayDate) {
            $overdue[] = $t;
        } elseif ($t['due_date'] === $todayDate) {
            // If due_time is set, only overdue after that time has passed
            if (!empty($t['due_time']) && $t['due_time'] < $nowTime) {
                $overdue[] = $t;
            } else {
                $pending[] = $t;
            }
        }
    }

    if (empty($pending) && empty($overdue)) {
        tg_send($chat_id, "✅ No pending or overdue tasks today! Great job.");
    } else {
        $reply = "📋 <b>Your tasks:</b>\n\n";

        foreach ($overdue as $t) {
            $pe  = match($t['priority']) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢',default=>'⚪' };
            $due = date('d M Y', strtotime($t['due_date']));
            $timeStr = !empty($t['due_time']) ? ' at ' . date('g:ia', strtotime($t['due_time'])) : '';
            $reply .= "$pe <b>{$t['task_name']}</b>";
            if ($t['course']) $reply .= " <i>({$t['course']})</i>";
            $reply .= "\n📅 $due{$timeStr} ⚠️ <i>overdue</i>\n\n";
        }

        foreach ($pending as $t) {
            $pe  = match($t['priority']) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢',default=>'⚪' };
            $due = date('d M Y', strtotime($t['due_date']));
            $timeStr = '';
            if (!empty($t['start_time']) && !empty($t['due_time'])) {
                $timeStr = '\n🕐 ' . date('g:ia', strtotime($t['start_time'])) . ' → ' . date('g:ia', strtotime($t['due_time']));
            } elseif (!empty($t['due_time'])) {
                $timeStr = '\n🕐 Due at ' . date('g:ia', strtotime($t['due_time']));
            }
            $reply .= "$pe <b>{$t['task_name']}</b>";
            if ($t['course']) $reply .= " <i>({$t['course']})</i>";
            $reply .= "\n📅 $due — <b>due today</b>{$timeStr}\n\n";
        }

        tg_send($chat_id, rtrim($reply));
    }
    exit;
}

// ── /addtask — start conversation ─────────────────────────────────────────────
if ($text === '/addtask') {
    try {
        $user_id = getUserId($pdo, $chat_id);
        file_put_contents(__DIR__ . '/webhook_debug.log',
            date('H:i:s') . " ADDTASK: user_id=$user_id chat_id=$chat_id\n", FILE_APPEND);

        if (!$user_id) {
            tg_send($chat_id,
                "⚠️ Your Telegram is not linked to ProcraTrack yet.\n\n"
              . "Copy your Chat ID:\n<code>{$chat_id}</code>\n"
              . "and paste it in <b>ProcraTrack → Notifications</b> first."
            );
            exit;
        }

        setSession($pdo, $chat_id, 'awaiting_name', []);

        tg_send($chat_id,
            "📝 <b>Add a New Task</b>\n"
          . "━━━━━━━━━━━━━━━━━━━━━\n\n"
          . "⚠️ <b>Important:</b> Tasks created here are <b>one-time only</b>. You <b>cannot edit or delete</b> from this bot. To make changes, go to <b>ProcraTrack → Tasks</b>.\n\n"
          . "📌 <b>Priority guide:</b>\n"
          . "🔴 <b>High</b> — urgent/high stakes\n"
          . "<i>e.g. Final exam, FYP submission</i>\n\n"
          . "🟡 <b>Medium</b> — important, not urgent\n"
          . "<i>e.g. Assignment due next week</i>\n\n"
          . "🟢 <b>Low</b> — do when time allows\n"
          . "<i>e.g. Reading, optional tasks</i>\n\n"
          . "Type /cancel at any time to stop."
        );

        tg_send($chat_id,
            "▶️ <b>Step 1 of 4 — Task Name</b>\n\n"
          . "What is the <b>task name</b>?\n\n"
          . "<i>e.g. Write Chapter 3, Submit Lab Report</i>"
        );
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/webhook_debug.log',
            date('H:i:s') . " ADDTASK ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        tg_send($chat_id, "❌ Something went wrong. Please try again.");
    }
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// CONVERSATION STEPS
// ════════════════════════════════════════════════════════════════════════════

// ── Step 1: got task name, ask deadline ──────────────────────────────────────
if ($step === 'awaiting_name') {
    if (strlen($text) < 2) {
        tg_send($chat_id, "⚠️ Task name is too short. Please enter a valid task name.");
        exit;
    }
    $data['task_name'] = $text;
    setSession($pdo, $chat_id, 'awaiting_date', $data);
    tg_send($chat_id,
        "✅ Task name saved: <b>" . htmlspecialchars($text) . "</b>\n\n"
      . "▶️ <b>Step 2 of 4 — Deadline</b>\n\n"
      . "📅 What is the <b>deadline date</b>?\n\n"
      . "<i>Accepted formats:</i>\n"
      . "• <code>20 June</code>\n"
      . "• <code>20/06/2026</code>\n"
      . "• <code>tomorrow</code> or <code>today</code>",
        [['today', 'tomorrow']]
    );
    exit;
}

// ── Step 2: got date, ask course ─────────────────────────────────────────────
if ($step === 'awaiting_date') {
    $date = parseDate($text);
    if (!$date) {
        tg_send($chat_id,
            "⚠️ Couldn't understand that date. Please try again.\n\n"
          . "<i>Accepted formats:</i>\n"
          . "• <code>20 June</code>\n"
          . "• <code>20/06/2026</code>\n"
          . "• <code>tomorrow</code> or <code>today</code>",
            [['today', 'tomorrow']]
        );
        exit;
    }
    $data['due_date'] = $date;
    setSession($pdo, $chat_id, 'awaiting_course', $data);
    $formatted = date('D, d M Y', strtotime($date));
    tg_send($chat_id,
        "✅ Deadline saved: <b>$formatted</b>\n\n"
      . "▶️ <b>Step 3 of 4 — Course / Subject</b>\n\n"
      . "📚 What is the <b>course or subject</b>?\n"
      . "<i>e.g. CS301, Mathematics, FYP</i>\n\n"
      . "Tap <b>Skip</b> if not applicable.",
        [['Skip']]
    );
    exit;
}

// ── Step 3: got course, ask priority ─────────────────────────────────────────
if ($step === 'awaiting_course') {
    $data['course'] = (strtolower($text) === 'skip' || $text === '') ? '' : $text;
    setSession($pdo, $chat_id, 'awaiting_priority', $data);
    tg_send($chat_id,
        "✅ Course saved: <b>" . (empty($data['course']) ? 'None' : htmlspecialchars($data['course'])) . "</b>\n\n"
      . "▶️ <b>Step 4 of 4 — Priority</b>\n\n"
      . "🎯 What is the <b>priority level</b>?\n\n"
      . "🔴 <b>High</b> — urgent or high stakes\n"
      . "      <i>e.g. Final exam, FYP submission</i>\n"
      . "🟡 <b>Medium</b> — important, not immediately urgent\n"
      . "      <i>e.g. Assignment due next week</i>\n"
      . "🟢 <b>Low</b> — do when time allows\n"
      . "      <i>e.g. Reading, optional exercises</i>",
        [['🔴 High', '🟡 Medium', '🟢 Low']]
    );
    exit;
}

// ── Step 4: got priority, save task ──────────────────────────────────────────
if ($step === 'awaiting_priority') {
    $p_map = [
        '🔴 high' => 'high',     'high' => 'high',
        '🟡 medium' => 'medium', 'medium' => 'medium',
        '🟢 low' => 'low',       'low' => 'low',
    ];
    $priority = $p_map[strtolower($text)] ?? 'medium';

    $user_id = getUserId($pdo, $chat_id);
    if (!$user_id) {
        clearSession($pdo, $chat_id);
        tg_send($chat_id, "❌ Could not find your account. Please re-link your Chat ID in ProcraTrack → Notifications.");
        exit;
    }

    try {
        $pdo->prepare("INSERT INTO tasks (user_id, task_name, course, priority, due_date, status)
                       VALUES (?, ?, ?, ?, ?, 'pending')")
            ->execute([$user_id, $data['task_name'], $data['course'] ?? '', $priority, $data['due_date']]);

        clearSession($pdo, $chat_id);

        $pe        = match($priority) { 'high'=>'🔴','medium'=>'🟡','low'=>'🟢' };
        $formatted = date('D, d M Y', strtotime($data['due_date']));
        $today     = date('Y-m-d');
        $diff      = (int)ceil((strtotime($data['due_date']) - strtotime($today)) / 86400);
        $countdown = match(true) {
            $diff <= 0  => "⚠️ This task is already overdue!",
            $diff === 1 => "⏰ Due tomorrow — start now!",
            $diff <= 3  => "⚡ Only $diff days away — get started soon!",
            $diff <= 7  => "📌 $diff days until deadline.",
            default     => "📅 $diff days until deadline.",
        };

        $reply = "✅ <b>Task created successfully!</b>\n\n"
               . "━━━━━━━━━━━━━━━━━━━━━\n"
               . "📌 <b>{$data['task_name']}</b>\n";
        if (!empty($data['course'])) $reply .= "📚 Course: {$data['course']}\n";
        $reply .= "$pe Priority: " . ucfirst($priority) . "\n"
               . "📅 Deadline: $formatted\n\n"
               . "$countdown\n\n"
               . "━━━━━━━━━━━━━━━━━━━━━\n"
               . "⚠️ <b>Reminder:</b> This task cannot be edited or deleted from this bot.\n"
               . "To make changes, visit <b>ProcraTrack → Tasks</b> on the website.\n\n"
               . "Type /addtask to add another task.";

        tg_send($chat_id, $reply);

    } catch (Exception $e) {
        clearSession($pdo, $chat_id);
        file_put_contents(__DIR__ . '/webhook_debug.log',
            date('H:i:s') . " SAVE TASK ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        tg_send($chat_id, "❌ Failed to save task. Please try again via /addtask.");
    }
    exit;
}

// ── Unknown message when idle ─────────────────────────────────────────────────
if ($step === 'idle') {
    tg_send($chat_id,
        "👋 Hi! I'm ProcraTrack Bot.\n\n"
      . "Type /addtask to create a new task\n"
      . "Type /tasks to see your pending tasks\n"
      . "Type /help for all commands"
    );
}
