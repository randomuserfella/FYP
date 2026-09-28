<?php
/**
 * focus_api.php — ProcraTrack Focus Session + Distraction Tracker API
 *
 * Actions (POST JSON unless noted):
 *   start_session   — record session start, return session_id
 *   end_session     — record end time, final duration, distraction count
 *   log_distraction — tab_switch / manual / idle event
 *   get_stats       — today's focus + distraction summary (GET)
 *   get_history     — recent sessions list (GET)
 */

require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/db.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit;
}

$user_id = (int)$_SESSION['user_id'];
$raw     = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $_GET['action'] ?? $raw['action'] ?? '';

// ── Auto-create tables on first run ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS distraction_events (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED NOT NULL,
        session_id    INT UNSIGNED DEFAULT NULL,
        event_type    ENUM('tab_switch','manual','idle') DEFAULT 'tab_switch',
        occurred_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_away INT UNSIGNED DEFAULT 0,
        note          VARCHAR(100) DEFAULT NULL,
        KEY idx_user  (user_id),
        KEY idx_sess  (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_log (
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id   INT UNSIGNED NOT NULL,
        channel   ENUM('whatsapp','email') NOT NULL,
        type      VARCHAR(60) NOT NULL,
        message   TEXT,
        status    ENUM('sent','failed') DEFAULT 'sent',
        sent_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ADD COLUMN IF NOT EXISTS start_time        DATETIME    DEFAULT NULL",
        "ADD COLUMN IF NOT EXISTS end_time          DATETIME    DEFAULT NULL",
        "ADD COLUMN IF NOT EXISTS distraction_count INT UNSIGNED DEFAULT 0",
        "ADD COLUMN IF NOT EXISTS task_id           INT UNSIGNED DEFAULT NULL",
        "ADD COLUMN IF NOT EXISTS session_type      VARCHAR(20) DEFAULT 'pomodoro'",
    ] as $col) { try { $pdo->exec("ALTER TABLE sessions $col"); } catch (Exception $e) {} }

    foreach ([
        "ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(20) DEFAULT NULL",
        "ADD COLUMN IF NOT EXISTS notify_telegram   TINYINT(1) DEFAULT 0",
        "ADD COLUMN IF NOT EXISTS notify_email      TINYINT(1) DEFAULT 1",
    ] as $col) { try { $pdo->exec("ALTER TABLE users $col"); } catch (Exception $e) {} }
} catch (Exception $e) {}

// ── start_session ─────────────────────────────────────────────────────────────
if ($action === 'start_session') {
    $task_id      = !empty($raw['task_id']) ? (int)$raw['task_id'] : null;
    $session_type = in_array($raw['session_type'] ?? '', ['pomodoro','deep_work','sprint','custom'])
                    ? $raw['session_type'] : 'pomodoro';
    $duration_min = !empty($raw['duration_minutes']) ? (int)$raw['duration_minutes'] : 25;

    $stmt = $pdo->prepare("INSERT INTO sessions
        (user_id, duration_minutes, session_date, start_time, task_id, session_type, distraction_count)
        VALUES (?,?,CURDATE(),NOW(),?,?,0)");
    $stmt->execute([$user_id, $duration_min, $task_id, $session_type]);
    echo json_encode(['ok' => true, 'session_id' => (int)$pdo->lastInsertId()]); exit;
}

// ── end_session ───────────────────────────────────────────────────────────────
if ($action === 'end_session') {
    $session_id     = (int)($raw['session_id'] ?? 0);
    $actual_minutes = max(0, (int)($raw['actual_minutes'] ?? 0));
    $dist_count     = (int)($raw['distraction_count'] ?? 0);

    if (!$session_id) { echo json_encode(['ok'=>false,'error'=>'Missing session_id']); exit; }

    $pdo->prepare("UPDATE sessions SET end_time=NOW(), duration_minutes=?, distraction_count=?
                   WHERE id=? AND user_id=?")
        ->execute([$actual_minutes, $dist_count, $session_id, $user_id]);

    // Telegram notification on session complete
    if (file_exists(__DIR__ . '/includes/notifier.php')) {
        require_once __DIR__ . '/includes/notifier.php';
        $u = $pdo->prepare("SELECT name, notify_telegram, telegram_chat_id FROM users WHERE id=?");
        $u->execute([$user_id]); $usr = $u->fetch();
        if ($usr && $usr['notify_telegram'] && $usr['telegram_chat_id'] && $actual_minutes > 0) {
            $emoji = $dist_count === 0 ? '🎯' : '✅';
            $msg   = "$emoji <b>ProcraTrack</b>\n\nGreat job, <b>{$usr['name']}</b>!\n"
                   . "You completed a <b>{$actual_minutes}-min</b> focus session"
                   . ($dist_count > 0 ? " with <b>{$dist_count}</b> distraction(s)." : " with <b>zero distractions!</b> 🔥")
                   . "\n\nKeep up the momentum!";
            sendTelegram($pdo, $user_id, $msg, 'focus_complete');
        }
    }
    echo json_encode(['ok' => true]); exit;
}

// ── log_distraction ───────────────────────────────────────────────────────────
if ($action === 'log_distraction') {
    $session_id = !empty($raw['session_id']) ? (int)$raw['session_id'] : null;
    $event_type = in_array($raw['event_type'] ?? '', ['tab_switch','manual','idle'])
                  ? $raw['event_type'] : 'tab_switch';
    $duration_away = (int)($raw['duration_away'] ?? 0);
    $note = isset($raw['note']) ? substr(trim($raw['note']), 0, 100) : null;

    $pdo->prepare("INSERT INTO distraction_events
        (user_id, session_id, event_type, duration_away, note)
        VALUES (?,?,?,?,?)")
        ->execute([$user_id, $session_id, $event_type, $duration_away, $note]);

    if ($session_id) {
        $pdo->prepare("UPDATE sessions SET distraction_count = distraction_count + 1
                       WHERE id=? AND user_id=?")
            ->execute([$session_id, $user_id]);
    }
    echo json_encode(['ok' => true]); exit;
}

// ── get_stats (GET) ───────────────────────────────────────────────────────────
if ($action === 'get_stats') {
    $s = $pdo->prepare("SELECT COUNT(*) as sessions,
        COALESCE(SUM(duration_minutes),0) as total_mins,
        COALESCE(SUM(distraction_count),0) as total_distractions
        FROM sessions WHERE user_id=? AND session_date=CURDATE()");
    $s->execute([$user_id]); $today = $s->fetch();

    $d = $pdo->prepare("SELECT event_type, COUNT(*) as cnt
        FROM distraction_events WHERE user_id=? AND DATE(occurred_at)=CURDATE()
        GROUP BY event_type");
    $d->execute([$user_id]); $breakdown = $d->fetchAll();

    $w = $pdo->prepare("SELECT DATE(occurred_at) as day, COUNT(*) as cnt
        FROM distraction_events
        WHERE user_id=? AND occurred_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
        GROUP BY DATE(occurred_at) ORDER BY day ASC");
    $w->execute([$user_id]); $weekly = $w->fetchAll();

    echo json_encode(['ok'=>true,'today'=>$today,'breakdown'=>$breakdown,'weekly'=>$weekly]); exit;
}

// ── get_history (GET) ─────────────────────────────────────────────────────────
if ($action === 'get_history') {
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    $s = $pdo->prepare("SELECT s.id, s.session_date, s.start_time, s.end_time,
        s.duration_minutes, s.distraction_count, s.session_type, t.task_name
        FROM sessions s LEFT JOIN tasks t ON t.id=s.task_id
        WHERE s.user_id=? ORDER BY s.id DESC LIMIT ?");
    $s->execute([$user_id, $limit]);
    echo json_encode(['ok'=>true,'sessions'=>$s->fetchAll()]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
