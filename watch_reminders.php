<?php
/**
 * watch_reminders.php
 * ---------------------------------------------------------------
 * Live "watch mode" for testing deadline reminders.
 *
 * send_reminders.php only does anything when something calls it —
 * there's no background scheduler running yet. This page calls it
 * automatically every 30 seconds (via JS) so you can leave it open
 * and watch reminders fire in real time as a task's due_time hits
 * the 60min / 30min / on-due / overdue windows, without needing to
 * click refresh or set up Task Scheduler.
 *
 * DELETE THIS FILE (and the poll endpoint below) before submitting —
 * it's a dev-only tool.
 *
 * Usage: log in, open this page, leave the tab open. Each poll's
 * result appears in the log below, newest on top.
 */

require_once __DIR__ . '/config/tab_session.php';

if (empty($_SESSION['user_id'])) {
    die('Please <a href="index.php">log in</a> first, then reopen this page.');
}

// ── AJAX endpoint: this same file, called with ?poll=1, runs the script ────
if (isset($_GET['poll'])) {
    header('Content-Type: text/plain');
    ob_start();
    require __DIR__ . '/send_reminders.php';
    echo ob_get_clean();
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Watch Reminders Live</title>
<style>
body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px;background:#f5f5f5}
.card{background:#fff;border-radius:10px;padding:20px;margin-bottom:16px;border:1px solid #ddd}
.status{display:flex;align-items:center;gap:10px;font-size:14px;color:#555}
.dot{width:10px;height:10px;border-radius:50%;background:#4caf50;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.entry{border-left:3px solid #ccc;padding:8px 12px;margin-bottom:8px;font-size:13px;background:#fafafa;border-radius:4px}
.entry.fired{border-left-color:#2e7d32;background:#e8f5e9}
.entry.empty{border-left-color:#bbb;color:#888}
.entry .time{font-weight:bold;color:#333}
pre{white-space:pre-wrap;margin:6px 0 0;font-size:12px}
button{padding:8px 16px;border-radius:6px;border:1px solid #ccc;background:#fff;cursor:pointer;margin-right:8px}
button.active{background:#3730a3;color:#fff;border-color:#3730a3}
</style>
</head>
<body>
<h2>👀 Watch Reminders Live</h2>

<div class="card status">
  <span class="dot" id="dot"></span>
  <span id="statusText">Polling every 30s&hellip;</span>
  <button onclick="toggle()" id="toggleBtn" style="margin-left:auto">Pause</button>
  <button onclick="pollNow()">Poll now</button>
</div>

<div class="card">
  <p style="margin:0;font-size:13px;color:#666">
    Make sure you have a task with <code>due_date</code> = today and a <code>due_time</code>
    approaching (or use <code>test_reminder.php</code> to create one). Each poll below runs
    <code>send_reminders.php</code> once — a green entry means something actually fired and
    was sent to Telegram/email.
  </p>
</div>

<div class="card" id="log"></div>

<script>
let polling = true;
let timer = null;

function fmtTime() {
  return new Date().toLocaleTimeString();
}

async function pollNow() {
  const logEl = document.getElementById('log');
  let text = '';
  try {
    const res = await fetch('watch_reminders.php?poll=1');
    text = await res.text();
  } catch (e) {
    text = 'Request failed: ' + e.message;
  }

  const fired = /\[TIME-|\[OVERDUE|\[HABIT|sent=[1-9]/.test(text) && !/No notifications sent/i.test(text);
  const entry = document.createElement('div');
  entry.className = 'entry ' + (fired ? 'fired' : 'empty');
  entry.innerHTML = '<span class="time">' + fmtTime() + '</span> ' +
    (fired ? '— 🔔 fired!' : '— nothing due yet') +
    '<pre>' + text.replace(/</g, '&lt;') + '</pre>';
  logEl.prepend(entry);
}

function schedule() {
  timer = setTimeout(async () => {
    if (polling) {
      await pollNow();
      schedule();
    }
  }, 30000);
}

function toggle() {
  polling = !polling;
  document.getElementById('toggleBtn').textContent = polling ? 'Pause' : 'Resume';
  document.getElementById('toggleBtn').classList.toggle('active', !polling);
  document.getElementById('statusText').textContent = polling ? 'Polling every 30s…' : 'Paused';
  document.getElementById('dot').style.animationPlayState = polling ? 'running' : 'paused';
  if (polling) schedule();
}

// Run once immediately, then start the loop
pollNow();
schedule();
</script>

</body>
</html>
