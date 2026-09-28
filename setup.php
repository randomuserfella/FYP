<?php
// ============================================================
// setup.php — ProcraTrack One-Time Database Migration
// Run once: http://localhost/procratrack/setup.php
// DELETE this file after running it
// ============================================================

require_once __DIR__ . '/config/db.php';

$results = [];

function run(PDO $pdo, string $label, string $sql): void {
    global $results;
    try {
        $pdo->exec($sql);
        $results[] = ['ok', $label];
    } catch (Exception $e) {
        $results[] = ['err', $label . ' — ' . $e->getMessage()];
    }
}

// ── tasks table ──────────────────────────────────────────────
run($pdo, 'tasks: add start_date',  "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL");
run($pdo, 'tasks: add start_time',  "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS start_time TIME DEFAULT NULL");
run($pdo, 'tasks: add due_time',    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS due_time   TIME DEFAULT NULL");

// ── users table ──────────────────────────────────────────────
run($pdo, 'users: add telegram_chat_id', "ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(20) DEFAULT NULL");
run($pdo, 'users: add notify_telegram',  "ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_telegram  TINYINT(1) DEFAULT 0");
run($pdo, 'users: add notify_email',     "ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_email     TINYINT(1) DEFAULT 1");

// ── Per-tier reminder toggles ─────────────────────────────────────────────────
run($pdo, 'users: add remind_7day',    "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_7day    TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_3day',    "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_3day    TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_1day',    "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_1day    TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_today',   "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_today   TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_60min',   "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_60min   TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_30min',   "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_30min   TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_ondue',   "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_ondue   TINYINT(1) DEFAULT 1");
run($pdo, 'users: add remind_overdue', "ALTER TABLE users ADD COLUMN IF NOT EXISTS remind_overdue TINYINT(1) DEFAULT 1");

// ── notification_log table ───────────────────────────────────
run($pdo, 'create notification_log', "CREATE TABLE IF NOT EXISTS notification_log (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    channel ENUM('telegram','email') NOT NULL,
    type    VARCHAR(60) NOT NULL,
    message TEXT,
    status  ENUM('sent','failed') DEFAULT 'sent',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── bot_sessions table ───────────────────────────────────────
run($pdo, 'create bot_sessions', "CREATE TABLE IF NOT EXISTS bot_sessions (
    chat_id    VARCHAR(20) PRIMARY KEY,
    step       VARCHAR(30) NOT NULL DEFAULT 'idle',
    data       TEXT        NOT NULL DEFAULT '{}',
    updated_at DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Output ───────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>ProcraTrack Setup</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="apple-touch-icon" href="apple-touch-icon.png">
  <style>
    body{font-family:Arial,sans-serif;background:#0f0f1a;color:#eee;padding:40px;max-width:600px;margin:auto}
    h1{color:#7c6af7;margin-bottom:24px}
    .row{padding:10px 14px;border-radius:8px;margin-bottom:8px;font-size:14px;display:flex;align-items:center;gap:10px}
    .ok{background:rgba(106,247,184,0.08);border:1px solid rgba(106,247,184,0.2)}
    .err{background:rgba(247,106,106,0.08);border:1px solid rgba(247,106,106,0.2);color:#f76a6a}
    .icon{font-size:16px}
    .footer{margin-top:32px;padding:16px;background:rgba(247,196,106,0.08);border:1px solid rgba(247,196,106,0.2);border-radius:8px;font-size:13px;color:#f7c46a}
  </style>
</head>
<body>
  <h1>📦 ProcraTrack Setup</h1>
  <?php foreach ($results as [$status, $label]): ?>
  <div class="row <?= $status ?>">
    <span class="icon"><?= $status === 'ok' ? '✅' : '❌' ?></span>
    <?= htmlspecialchars($label) ?>
  </div>
  <?php endforeach; ?>
  <div class="footer">
    ⚠️ <strong>Delete this file after setup is complete.</strong><br>
    Remove <code>setup.php</code> from your project root — it has no auth protection.
  </div>
</body>
</html>
