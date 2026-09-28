<?php
require_once __DIR__ . '/config/tab_session.php';
$pageTitle = 'Timer';
require_once 'config/db.php';
require_once 'includes/header.php';

$user_id = $_SESSION['user_id'];

// Save session
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save_session') {
    $mins = (int)$_POST['duration_minutes'];
    if ($mins > 0) {
        $pdo->prepare("INSERT INTO sessions (user_id, duration_minutes, session_date) VALUES (?,?,CURDATE())")->execute([$user_id, $mins]);
        echo json_encode(['success'=>true]); exit;
    }
    echo json_encode(['success'=>false]); exit;
}

// Sessions today
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes),0) FROM sessions WHERE user_id=? AND session_date=CURDATE()");
$stmt->execute([$user_id]);
$today_mins = $stmt->fetchColumn();

// Sessions this week
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$stmt->execute([$user_id]);
$week_sessions = $stmt->fetchColumn();

// Pending tasks for dropdown
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id=? AND status='pending' ORDER BY FIELD(priority,'high','medium','low')");
$stmt->execute([$user_id]);
$pending_tasks = $stmt->fetchAll();
?>

<div class="page-topbar">
<div class="page-title">Focus Timer</div>
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" style="margin-left:auto"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</div>
<div class="page-sub">Stay focused with Pomodoro, Deep Work, or Quick Sprint sessions</div>

<div class="row g-3">
    <!-- Timer -->
    <div class="col-md-6">
        <div class="card text-center">
            <!-- Mode selector -->
            <div id="mode-btn-row">
                <button class="mode-btn active" id="btn-pomo"   onclick="setMode(25,5,'Pomodoro',this,4)">🍅 Pomodoro</button>
                <button class="mode-btn"         id="btn-deep"   onclick="setMode(50,10,'Deep Work',this,4)">🧠 Deep Work</button>
                <button class="mode-btn"         id="btn-sprint" onclick="setMode(15,3,'Quick Sprint',this,4)">⚡ Sprint</button>
                <button class="mode-btn"         id="btn-custom" onclick="openCustom(this)">✏️ Custom</button>
            </div>

            <!-- Custom time panel -->
            <div id="custom-panel" class="custom-timer-box" style="display:none;margin-bottom:18px;background:rgba(124,106,247,0.07);
                 border:1px solid rgba(124,106,247,0.25);border-radius:12px;padding:18px;">
                <div style="font-size:13px;font-weight:700;color:#7c6af7;margin-bottom:16px;">
                    <i class="bi bi-sliders"></i> Custom Timer Settings
                </div>

                                <div id="focus-section">
                <!-- Focus Duration -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">
                        🎯 Focus Duration
                    </label>
                <!-- Focus steppers: H M S -->
                <div class="hms-row">
                    <div class="hms-field">
                        <div class="hms-label">Hours</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cw-h',-1,0,9999)" class="stepper-btn">−</button>
                            <input type="number" id="cw-h" min="0" max="9999" value="0"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cw-h',1,0,9999)" class="stepper-btn">+</button>
                        </div>
                    </div>
                    <div class="hms-colon">:</div>
                    <div class="hms-field">
                        <div class="hms-label">Minutes</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cw-m',-5,0,59)" class="stepper-btn">−</button>
                            <input type="number" id="cw-m" min="0" max="59" value="25"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cw-m',5,0,59)" class="stepper-btn">+</button>
                        </div>
                    </div>
                    <div class="hms-colon">:</div>
                    <div class="hms-field">
                        <div class="hms-label">Seconds</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cw-s',-5,0,59)" class="stepper-btn">−</button>
                            <input type="number" id="cw-s" min="0" max="59" value="0"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cw-s',5,0,59)" class="stepper-btn">+</button>
                        </div>
                    </div>
                </div>
                    <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                        <span style="font-size:11px;color:var(--text-muted);align-self:center;">Quick:</span>
                        <button onclick="setCustomWork(0,15,0)" class="quick-btn">15m</button>
                        <button onclick="setCustomWork(0,25,0)" class="quick-btn">25m</button>
                        <button onclick="setCustomWork(0,45,0)" class="quick-btn">45m</button>
                        <button onclick="setCustomWork(1,0,0)"  class="quick-btn">1h</button>
                        <button onclick="setCustomWork(1,30,0)" class="quick-btn">1h 30m</button>
                        <button onclick="setCustomWork(2,0,0)"  class="quick-btn">2h</button>
                    </div>
                </div>

                </div>
                <div id="break-section">
                <!-- Break Duration -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">
                        ☕ Break Duration
                    </label>
                <!-- Break steppers: H M S -->
                <div class="hms-row">
                    <div class="hms-field">
                        <div class="hms-label">Hours</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cb-h',-1,0,9999)" class="stepper-btn brk">−</button>
                            <input type="number" id="cb-h" min="0" max="9999" value="0"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cb-h',1,0,9999)" class="stepper-btn brk">+</button>
                        </div>
                    </div>
                    <div class="hms-colon">:</div>
                    <div class="hms-field">
                        <div class="hms-label">Minutes</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cb-m',-1,0,59)" class="stepper-btn brk">−</button>
                            <input type="number" id="cb-m" min="0" max="59" value="5"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cb-m',1,0,59)" class="stepper-btn brk">+</button>
                        </div>
                    </div>
                    <div class="hms-colon">:</div>
                    <div class="hms-field">
                        <div class="hms-label">Seconds</div>
                        <div class="stepper-wrap">
                            <button onclick="stepVal2('cb-s',-5,0,59)" class="stepper-btn brk">−</button>
                            <input type="number" id="cb-s" min="0" max="59" value="0"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cb-s',5,0,59)" class="stepper-btn brk">+</button>
                        </div>
                    </div>
                </div>
                    <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                        <span style="font-size:11px;color:var(--text-muted);align-self:center;">Quick:</span>
                        <button onclick="setCustomBreak(0,3,0)"  class="quick-btn brk">3m</button>
                        <button onclick="setCustomBreak(0,5,0)"  class="quick-btn brk">5m</button>
                        <button onclick="setCustomBreak(0,10,0)" class="quick-btn brk">10m</button>
                        <button onclick="setCustomBreak(0,15,0)" class="quick-btn brk">15m</button>
                        <button onclick="setCustomBreak(0,20,0)" class="quick-btn brk">20m</button>
                    </div>
                </div>

                </div>
                <!-- Number of sessions -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">
                        🔁 Number of Sessions
                    </label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="stepper-wrap" style="width:140px;">
                            <button onclick="stepVal2('cw-sessions',-1,1,99)" class="stepper-btn">−</button>
                            <input type="number" id="cw-sessions" min="1" max="99" value="4"
                                class="stepper-input" oninput="sanitiseCustomInputs()">
                            <button onclick="stepVal2('cw-sessions',1,1,99)" class="stepper-btn">+</button>
                        </div>
                        <span style="font-size:12px;color:var(--text-muted);">sessions (1–99)</span>
                    </div>
                </div>

                <!-- Options -->
                <div style="display:flex;gap:16px;margin-bottom:14px;padding:12px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-muted);user-select:none;">
                        <input type="checkbox" id="no-break" onchange="toggleSection('break-section','no-break','no-break');updateDotsVisibility()"
                               style="width:16px;height:16px;accent-color:#6af7b8;cursor:pointer;">
                        No Break
                    </label>
                </div>

                <button onclick="applyCustom()"
                    style="width:100%;background:#7c6af7;border:none;border-radius:10px;color:#fff;
                           font-weight:600;padding:11px;font-size:14px;cursor:pointer;transition:opacity .2s;"
                    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    ✅ Set Custom Timer
                </button>
            </div>

            <!-- Time display -->
            <div style="display:flex;align-items:flex-end;justify-content:center;gap:4px;margin-bottom:8px" id="timer-display-wrap">
                <!-- Hours -->
                <div style="text-align:center;">
                    <div id="disp-h" class="clock-digit" style="color:#7c6af7">00</div>
                    <div class="clock-label">hours</div>
                </div>
                <div class="clock-sep" style="color:#7c6af7" id="disp-sep1">:</div>
                <!-- Minutes -->
                <div style="text-align:center;">
                    <div id="disp-m" class="clock-digit" style="color:#7c6af7">25</div>
                    <div class="clock-label">minutes</div>
                </div>
                <div class="clock-sep" style="color:#7c6af7" id="disp-sep2">:</div>
                <!-- Seconds -->
                <div style="text-align:center;">
                    <div id="disp-s" class="clock-digit" style="color:#7c6af7">00</div>
                    <div class="clock-label">seconds</div>
                </div>
            </div>
            <div id="timer-mode-label" style="font-size:14px;color:var(--text-muted);margin-bottom:6px">Pomodoro · 25 min focus</div>
            <div id="timer-phase" style="font-size:13px;color:#6af7b8;margin-bottom:0"></div>

            <!-- Session progress bar (dynamic) -->
            <div id="pom-dots-wrap" style="display:flex;justify-content:center;width:100%">
                <div id="pom-dots" style="width:min(340px,90%);box-sizing:border-box">
                    <div style="height:8px;border-radius:999px;background:#2a2a38;overflow:hidden;margin-bottom:6px">
                        <div id="sess-bar-fill" style="height:100%;border-radius:999px;background:#7c6af7;width:0%;transition:width 0.4s ease"></div>
                    </div>
                    <div id="sess-bar-sub" style="font-size:11px;color:var(--text-muted);text-align:center">
                        <span id="sess-bar-label" style="font-weight:700;color:var(--text-main);">0 / 4</span> sessions complete
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div style="display:flex;gap:10px;justify-content:center;margin-bottom:16px">
                <button id="start-btn" onclick="toggleTimer()"
                    style="background:#7c6af7;border:none;border-radius:12px;color:#fff;font-weight:600;padding:13px 32px;font-size:15px;cursor:pointer;transition:opacity .2s">
                    ▶ Start
                </button>
                <button onclick="resetTimer()"
                    style="background:#2a2a38;border:none;border-radius:12px;color:#e8e8f0;font-weight:600;padding:13px 20px;font-size:15px;cursor:pointer">
                    ⟳ Reset
                </button>
                <button id="skip-break-btn" onclick="skipBreak()" style="display:none;background:transparent;border:1px solid #6af7b8;border-radius:12px;color:#6af7b8;font-weight:600;padding:13px 20px;font-size:15px;cursor:pointer;transition:opacity .2s"
                    onmouseover="this.style.opacity='.75'" onmouseout="this.style.opacity='1'">
                    ⏭ Skip Break
                </button>
            </div>

            <!-- Task selector -->
            <div style="text-align:left">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <label style="font-size:12px;color:#7a7a95;">Working on:</label>
                    <label id="auto-mark-label" style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:12px;color:var(--text-muted);user-select:none;opacity:0.45;" title="Auto-mark task as done when all sessions complete">
                        <input type="checkbox" id="auto-mark-done" onchange="saveState()" style="width:14px;height:14px;accent-color:#6af7b8;cursor:pointer;">
                        Auto-mark done
                    </label>
                </div>
                <select id="task-select" onchange="onTaskSelectChange()" style="width:100%;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);border-radius:10px;padding:10px 14px;font-size:13px">
                    <option value="">— Select a task —</option>
                    <?php foreach($pending_tasks as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['task_name']) ?><?= !empty($t['course']) ? ' (' . htmlspecialchars($t['course']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Distraction log -->
        <div class="card">
            <div class="card-title-sm">Distraction Log</div>

            <!-- Whitelist section -->
            <div id="whitelist-section" style="margin-bottom:14px;padding:12px;background:rgba(106,247,184,0.06);border:1px solid rgba(106,247,184,0.18);border-radius:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <div style="font-size:12px;font-weight:700;color:#6af7b8;"><i class="bi bi-shield-check"></i> Research Whitelist</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:11px;color:var(--text-muted);">Slots:</span>
                        <button type="button" onclick="changeSlots(-1)" class="stepper-btn" style="width:26px;height:26px;font-size:14px;">-</button>
                        <span id="slot-count" style="font-size:12px;font-weight:700;color:var(--text-main);min-width:18px;text-align:center;">3</span>
                        <button type="button" onclick="changeSlots(1)" class="stepper-btn" style="width:26px;height:26px;font-size:14px;">+</button>
                    </div>
                </div>
                <div style="font-size:11px;color:#7a7a95;margin-bottom:10px;">Tab switches to these sites are forgiven. Add as many as you need.</div>
                <div id="whitelist-slots"></div>
                <div style="font-size:11px;color:#7a7a95;margin-top:6px;"><i class="bi bi-info-circle"></i> Saved automatically. Works together with the grace period below.</div>
            </div>

            <!-- Grace period slider -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                    <span style="font-size:12px;color:#7a7a95;"><i class="bi bi-clock"></i> Auto-forgive if away less than</span>
                    <span id="grace-val-label" style="font-size:12px;font-weight:700;color:#7c6af7;">60s</span>
                </div>
                <input type="range" id="grace-slider" min="10" max="180" step="10" value="60"
                    oninput="updateGrace(this.value)"
                    style="width:100%;accent-color:#7c6af7;">
                <div style="display:flex;justify-content:space-between;font-size:10px;color:#7a7a95;"><span>10s</span><span>3 min</span></div>
            </div>

            <div id="distraction-list" style="max-height:160px;overflow-y:auto;margin-bottom:10px">
                <div style="color:#7a7a95;font-size:13px;text-align:center;padding:16px">No distractions logged yet 🎯</div>
            </div>
            <div style="font-size:11px;color:#7a7a95;text-align:center;padding:6px 0;opacity:.7;">
                <i class="bi bi-eye"></i> Whitelisted short switches are forgiven automatically
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="col-md-6">
        <div class="row g-3 mb-3">
            <div class="col-6">
                <div class="stat-card">
                    <div class="stat-val" style="color:#6af7b8" id="today-display">
                        <?= floor($today_mins/60) ?>h <?= $today_mins%60 ?>m
                    </div>
                    <div class="stat-label">Focus Time Today</div>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card">
                    <div class="stat-val" style="color:#f7c46a"><?= $week_sessions ?></div>
                    <div class="stat-label">Sessions This Week</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-title-sm">Daily Goal Progress</div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px">
                <span style="color:#7a7a95">Today's focus</span>
                <span><?= $today_mins ?> / 360 min</span>
            </div>
            <div class="prog-bar" style="height:10px;margin-bottom:6px">
                <div class="prog-fill" style="width:<?= min(100,round(($today_mins/360)*100)) ?>%;background:#7c6af7;height:100%"></div>
            </div>
            <div style="font-size:12px;color:#7a7a95"><?= min(100,round(($today_mins/360)*100)) ?>% of 6-hour daily goal</div>
        </div>

        <div class="card">
            <div class="card-title-sm">How to use the timer</div>
            <!-- Quick-start tip -->
            <div style="background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.2);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:var(--text-muted);line-height:1.6;">
                <span style="font-weight:700;color:#7c6af7;">💡 How it works:</span>
                Pick a mode, press <b>▶ Start</b>, then focus until the alarm rings. The progress bar below the clock tracks your sessions. When a session ends the timer switches to break automatically — just dismiss the alarm to continue.
            </div>
            <!-- Mode info rows -->
            <div style="display:flex;flex-direction:column;gap:10px">
                <!-- Pomodoro -->
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;border-radius:10px;background:var(--bg-surface);border:1px solid var(--border);">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(124,106,247,.15);color:#7c6af7;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px">🍅</div>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text-main);">Pomodoro</div>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Best for most tasks — bite-sized focus blocks.</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,.12);color:#7c6af7;font-weight:600;">🎯 25 min focus</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(106,247,184,.12);color:#6af7b8;font-weight:600;">☕ 5 min break</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.06);color:var(--text-muted);font-weight:600;">🔁 4 sessions</span>
                        </div>
                    </div>
                </div>
                <!-- Deep Work -->
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;border-radius:10px;background:var(--bg-surface);border:1px solid var(--border);">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(106,247,184,.15);color:#6af7b8;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px">🧠</div>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text-main);">Deep Work</div>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Long uninterrupted focus for complex assignments.</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,.12);color:#7c6af7;font-weight:600;">🎯 50 min focus</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(106,247,184,.12);color:#6af7b8;font-weight:600;">☕ 10 min break</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.06);color:var(--text-muted);font-weight:600;">🔁 4 sessions</span>
                        </div>
                    </div>
                </div>
                <!-- Sprint -->
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;border-radius:10px;background:var(--bg-surface);border:1px solid var(--border);">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(247,196,106,.15);color:#f7c46a;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px">⚡</div>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text-main);">Quick Sprint</div>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Fast bursts for quick tasks or when time is tight.</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,.12);color:#7c6af7;font-weight:600;">🎯 15 min focus</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(106,247,184,.12);color:#6af7b8;font-weight:600;">☕ 3 min break</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.06);color:var(--text-muted);font-weight:600;">🔁 4 sessions</span>
                        </div>
                    </div>
                </div>
                <!-- Custom -->
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;border-radius:10px;background:var(--bg-surface);border:1px solid var(--border);">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(247,196,106,.08);color:#f7c46a;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px">✏️</div>
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text-main);">Custom</div>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Set your own focus &amp; break durations, number of sessions, or tick <b>No Break</b> for pure focus.</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,.12);color:#7c6af7;font-weight:600;">🎯 any focus</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(106,247,184,.12);color:#6af7b8;font-weight:600;">☕ any break / none</span>
                            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.06);color:var(--text-muted);font-weight:600;">🔁 1–12 sessions</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root { --warn-accent: #f7a46a; }
[data-theme="light"] { --warn-accent: #b56a1e; }

/* ── Mode buttons ── */
.mode-btn {
    padding: 8px 14px; border-radius: 20px; border: 1px solid #2a2a38;
    background: transparent; color: #7a7a95; font-size: 13px; cursor: pointer;
    transition: all .2s; font-family: 'Segoe UI', sans-serif; white-space: nowrap;
}
.mode-btn.active { border-color: #7c6af7; color: #7c6af7; background: rgba(124,106,247,.12); }

/* Mode button row — wraps cleanly */
#mode-btn-row {
    display: flex; gap: 6px; justify-content: center;
    flex-wrap: wrap; margin-bottom: 20px;
}

/* ── Session progress bar ── */
.sess-bar-wrap { width: 100%; max-width: 340px; margin: 0 auto; }

/* ── Custom panel stepper rows ── */
.hms-row {
    display: flex; align-items: flex-start; gap: 6px;
}
.hms-colon {
    font-size: 28px; font-weight: 700; color: var(--text-muted);
    padding-top: 22px; flex-shrink: 0; line-height: 1;
}
.hms-field { flex: 1; text-align: center; min-width: 0; }
.hms-label {
    font-size: 11px; color: var(--text-muted); font-weight: 600;
    text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px;
}
.quick-btn {
    padding: 3px 10px; border-radius: 20px;
    border: 1px solid var(--border); background: var(--bg-surface);
    color: var(--text-muted); font-size: 11px; cursor: pointer;
}
.quick-btn:hover { border-color: #7c6af7; color: #7c6af7; }
.quick-btn.brk:hover { border-color: #6af7b8; color: #6af7b8; }

@media (max-width: 520px) {
    .hms-colon { font-size: 20px; padding-top: 18px; }
    .stepper-btn { width: 26px; font-size: 15px; }
    .hms-label { font-size: 10px; letter-spacing: 0; }
}
@media (max-width: 400px) {
    .hms-row { gap: 3px; }
    .hms-colon { font-size: 16px; padding-top: 16px; }
    .stepper-btn { width: 22px; }
}

/* ── Clock digits — fluid font ── */
.clock-digit {
    font-weight: 700; letter-spacing: 2px;
    font-variant-numeric: tabular-nums; line-height: 1;
    font-size: clamp(32px, 6vw, 64px);
}
.clock-sep {
    font-weight: 700; line-height: 1; padding-bottom: 18px;
    font-size: clamp(28px, 5vw, 56px);
}
.clock-label {
    font-size: 11px; color: var(--text-muted);
    font-weight: 600; text-transform: uppercase;
    letter-spacing: .8px; margin-top: 4px;
}

/* ── Light-mode: darken timer digits so they aren't washed-out ── */
[data-theme="light"] .clock-digit,
[data-theme="light"] .clock-sep {
    filter: brightness(0.65) saturate(1.2);
}
/* Break-time phase text also needs better light-mode contrast */
[data-theme="light"] #timer-phase {
    filter: brightness(0.6) saturate(1.3);
}

/* ── Custom panel steppers ── */
.stepper-wrap {
    display: flex; align-items: center;
    border: 1px solid var(--border); border-radius: 10px;
    overflow: hidden; background: var(--bg-input);
    min-width: 90px; /* prevents full collapse in split-tab */
}
.stepper-btn {
    width: 32px; height: 48px; border: none; background: transparent;
    color: var(--text-muted); font-size: 18px; cursor: pointer; flex-shrink: 0;
}
.stepper-btn:hover { background: rgba(124,106,247,0.15); color: #7c6af7; }
.stepper-btn.brk:hover { background: rgba(106,247,184,0.15); color: #6af7b8; }
.stepper-input {
    flex: 1; border: none; background: transparent;
    color: var(--text-main); font-weight: 700; text-align: center;
    outline: none; padding: 6px 2px; min-width: 28px; width: 0;
    font-size: clamp(16px, 3vw, 26px);
}

/* ── Container query: compact steppers when the custom panel is narrow ── */
.custom-timer-box { container-type: inline-size; }
@container (max-width: 360px) {
    .stepper-btn   { width: 26px; height: 42px; font-size: 15px; }
    .stepper-input { font-size: 16px; padding: 4px 1px; }
    .hms-label     { font-size: 9px; letter-spacing: 0; }
    .hms-colon     { font-size: 20px; padding-top: 18px; }
    .hms-row       { gap: 4px; }
    .stepper-wrap  { min-width: 76px; }
}
@container (max-width: 280px) {
    .stepper-btn   { width: 22px; height: 38px; font-size: 13px; }
    .stepper-input { font-size: 14px; }
    .hms-colon     { font-size: 15px; padding-top: 14px; }
    .hms-row       { gap: 2px; }
    .stepper-wrap  { min-width: 64px; }
    .hms-label     { font-size: 8px; }
}

/* ── Responsive breakpoints ── */

/* Narrow sidebar+content (roughly 768–900px window) */
@media (max-width: 900px) {
    .clock-digit { font-size: clamp(28px, 7vw, 52px); }
    .clock-sep   { font-size: clamp(24px, 6vw, 44px); }
}

/* Stack columns on small screens */
@media (max-width: 768px) {
    .clock-digit { font-size: clamp(36px, 11vw, 56px); }
    .clock-sep   { font-size: clamp(30px, 9vw, 48px); }
    .stepper-btn { width: 28px; height: 44px; font-size: 16px; }
}

/* Very narrow */
@media (max-width: 480px) {
    .clock-digit { font-size: clamp(28px, 13vw, 48px); }
    .clock-sep   { font-size: clamp(24px, 11vw, 40px); }
    .mode-btn    { padding: 6px 10px; font-size: 12px; }
}

@keyframes toastIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
</style>

<script>
// ── Persistent state via sessionStorage ─────────────────────────────
const SS_KEY = 'procratrack_timer';

function saveState() {
    const sel = document.getElementById('task-select');
    const amd = document.getElementById('auto-mark-done');
    sessionStorage.setItem(SS_KEY, JSON.stringify({
        workMins, breakMins, totalSecs, onBreak, pomCount, totalSessions, noBreakMode,
        modeLabel: document.getElementById('timer-mode-label').textContent,
        phaseText: document.getElementById('timer-phase').textContent,
        activeBtn: document.querySelector('.mode-btn.active') ? document.querySelector('.mode-btn.active').id : 'btn-pomo',
        taskVal:   sel ? sel.value : '',
        autoMark:  amd ? amd.checked : false,
        savedCustom
    }));
}

function onTaskSelectChange() {
    const sel = document.getElementById('task-select');
    const lbl = document.getElementById('auto-mark-label');
    // Enable checkbox only when a task is selected
    if (lbl) lbl.style.opacity = sel && sel.value ? '1' : '0.45';
    saveState();
}

function autoMarkTaskDone() {
    const sel = document.getElementById('task-select');
    const amd = document.getElementById('auto-mark-done');
    if (!amd || !amd.checked || !sel || !sel.value) return;
    const taskId = sel.value;
    fetch('tasks.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=mark_complete&id=' + encodeURIComponent(taskId)
    }).then(r => r.json()).then(d => {
        if (!d.ok) return; // server didn't confirm — leave the task in the list
        // Visual feedback — strikethrough the selected option
        const opt = sel.options[sel.selectedIndex];
        if (opt) opt.text = '✓ ' + opt.text;
        sel.style.textDecoration = 'line-through';
        sel.style.color = '#6af7b8';
        setTimeout(() => {
            // Remove completed task from dropdown after 2s
            sel.remove(sel.selectedIndex);
            sel.value = '';
            sel.style.textDecoration = '';
            sel.style.color = 'var(--text-main)';
            onTaskSelectChange();
        }, 2000);
    }).catch(() => {});
}

function loadState() {
    try {
        const raw = sessionStorage.getItem(SS_KEY);
        if (!raw) return false;
        const s = JSON.parse(raw);
        workMins    = s.workMins    ?? 25;
        breakMins   = s.breakMins   ?? 5;
        totalSecs   = s.totalSecs   ?? workMins * 60;
        onBreak     = s.onBreak     ?? false;
        pomCount      = s.pomCount      ?? 0;
        totalSessions = s.totalSessions ?? 4;
        noBreakMode   = s.noBreakMode   ?? false;
        savedCustom   = s.savedCustom   ?? savedCustom;

        // Restore UI labels
        if (s.modeLabel) document.getElementById('timer-mode-label').textContent = s.modeLabel;
        if (s.phaseText) document.getElementById('timer-phase').textContent = s.phaseText;

        // Restore active mode button
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        const ab = document.getElementById(s.activeBtn || 'btn-pomo');
        if (ab) ab.classList.add('active');

        // Restore task selection
        const sel = document.getElementById('task-select');
        if (sel && s.taskVal) sel.value = s.taskVal;

        // Restore auto-mark checkbox
        const amd = document.getElementById('auto-mark-done');
        const lbl = document.getElementById('auto-mark-label');
        if (amd) amd.checked = s.autoMark ?? false;
        if (lbl) lbl.style.opacity = (sel && sel.value) ? '1' : '0.45';

        return true;
    } catch(e) { return false; }
}

let workMins = 25, breakMins = 5;
let totalSecs = 25 * 60;
let running = false, onBreak = false;
let interval = null;
let pomCount = 0;
let sessionMins = 0;
let distractions = [];
let savedCustom = { wh:0, wm:25, ws:0, bh:0, bm:5, bs:0, noBreak:false, sessions:4 };
let totalSessions = 4;   // how many dots to show
let noBreakMode   = false; // hides dots when no break

function setMode(work, brk, label, btn, sessions) {
    if (running) return;
    // Close custom panel if open (without wiping savedCustom)
    document.getElementById('custom-panel').style.display = 'none';
    workMins = work; breakMins = brk;
    totalSecs = work * 60; onBreak = false;
    totalSessions = sessions || 4;
    noBreakMode = false;
    pomCount = 0;
    updateDisplay();
    renderDots();
    document.getElementById('timer-mode-label').textContent = label + ' · ' + work + ' min focus';
    document.getElementById('timer-phase').textContent = '';
    document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    updateSkipBreakVisibility();
    saveState();
}


// ── Looping alarm using Web Audio API ───────────────────────────────
let _alarmCtx  = null;   // shared AudioContext kept alive while alarm rings
let _alarmLoop = null;   // setInterval handle for the repeating ring
let _alarmType = null;   // 'session' | 'break'

function _ringOnce(ctx, type) {
    function beep(freq, start, dur, vol) {
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
        gain.gain.setValueAtTime(0, ctx.currentTime + start);
        gain.gain.linearRampToValueAtTime(vol, ctx.currentTime + start + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + dur);
        osc.start(ctx.currentTime + start);
        osc.stop(ctx.currentTime + start + dur + 0.05);
    }
    if (type === 'session') {
        beep(880,  0.0, 0.5, 0.6);
        beep(1100, 0.5, 0.5, 0.6);
        beep(1320, 1.0, 0.8, 0.7);
    } else {
        beep(660, 0.0, 0.4, 0.4);
        beep(880, 0.4, 0.6, 0.5);
    }
}

function playRing(type) {
    stopAlarm();   // clear any previous alarm first
    try {
        _alarmCtx  = new (window.AudioContext || window.webkitAudioContext)();
        _alarmType = type;
        _ringOnce(_alarmCtx, type);                        // ring immediately
        _alarmLoop = setInterval(() => {                   // then loop every 2.5 s
            if (_alarmCtx) _ringOnce(_alarmCtx, _alarmType);
        }, 2500);
    } catch(e) { console.warn('Audio not supported:', e); }
}

function stopAlarm() {
    if (_alarmLoop) { clearInterval(_alarmLoop); _alarmLoop = null; }
    if (_alarmCtx)  { try { _alarmCtx.close(); } catch(e){} _alarmCtx = null; }
    _alarmType = null;
}

function updateDisplay() {
    const h  = Math.floor(totalSecs / 3600);
    const m  = Math.floor((totalSecs % 3600) / 60);
    const s  = totalSecs % 60;
    const col = onBreak ? '#6af7b8' : '#7c6af7';

    document.getElementById('disp-h').textContent  = String(h).padStart(2,'0');
    document.getElementById('disp-m').textContent  = String(m).padStart(2,'0');
    document.getElementById('disp-s').textContent  = String(s).padStart(2,'0');
    document.getElementById('disp-h').style.color  = col;
    document.getElementById('disp-m').style.color  = col;
    document.getElementById('disp-s').style.color  = col;
    document.getElementById('disp-sep1').style.color = col;
    document.getElementById('disp-sep2').style.color = col;
    // Keep phase spacing in sync
    const ph = document.getElementById('timer-phase');
    if (ph) ph.style.marginBottom = ph.textContent.trim() ? '20px' : '0';
}

function toggleTimer() {
    if (running) {
        pauseTimer();
    } else {
        startTimer();
    }
}

function pauseTimer() {
    if (onBreak) return; // breaks can't be paused — use Skip Break instead
    clearInterval(interval); running = false;
    document.getElementById('start-btn').textContent = '▶ Start';
    // Auto-log a pause distraction during focus (not break)
    logDistraction('⏸️ Timer paused', 'pause', 0);
    saveState();
}

function startTimer() {
    if (running) return;
    running = true;
    document.getElementById('start-btn').textContent = '⏸ Pause';

    requestNotifPermission(); // ask once for browser notification permission
    // Register session in focus_api so distractions get linked to it
    if (!onBreak && !activeSessionId) {
        const taskSel = document.getElementById('task-select');
        const modeBtn = document.querySelector('.mode-btn.active');
        const modeMap = {'btn-pomo':'pomodoro','btn-deep':'deep_work','btn-sprint':'sprint','btn-custom':'custom'};
        fetch('focus_api.php?action=start_session', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action: 'start_session',
                task_id: taskSel ? (taskSel.value || null) : null,
                session_type: modeBtn ? (modeMap[modeBtn.id] || 'pomodoro') : 'pomodoro',
                duration_minutes: workMins
            })
        }).then(r => r.json()).then(d => {
            if (d.ok) activeSessionId = d.session_id;
        }).catch(() => {});
    }

    interval = setInterval(() => {
        totalSecs--;
        updateDisplay();
        if (totalSecs % 5 === 0) saveState(); // save every 5 sec
        if (!onBreak) sessionMins = (workMins * 60 - totalSecs) / 60;
        if (totalSecs <= 0) {
            clearInterval(interval); running = false;
            document.getElementById('start-btn').textContent = '▶ Start';
            if (!onBreak) {
                // Save session
                const mins = workMins;
                fetch('timer.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=save_session&duration_minutes=' + mins
                });
                pomCount++;
                updateDots();
                const allSessionsDone = (pomCount % totalSessions === 0);
                // Auto-mark task done when all sessions are finished
                if (allSessionsDone) {
                    autoMarkTaskDone();
                }
                if (allSessionsDone) {
                    // Last session of the set — no break, just wrap up
                    onBreak = false;
                    totalSecs = workMins * 60;
                    document.getElementById('timer-phase').textContent = '';
                    updateDisplay();
                    updateSkipBreakVisibility();
                    playRing('session');
                    showAlarmModal('🎉 All Sessions Complete!', 'Amazing work! You finished all ' + totalSessions + ' sessions — no break needed, you\'re done.', 'complete');
                } else {
                    onBreak = true;
                    totalSecs = breakMins * 60;
                    const brkSecs = Math.round(breakMins * 60);
                    const brkLabel = fmtDuration(brkSecs);
                    document.getElementById('timer-phase').textContent = '☕ Break time! Relax for ' + brkLabel;
                    updateDisplay();
                    updateSkipBreakVisibility();
                    playRing('session');
                    showAlarmModal('✅ Session Complete!', '🎉 Great work! Take a ' + brkLabel + ' break.', 'session');
                }
            } else {
                onBreak = false;
                totalSecs = workMins * 60;
                document.getElementById('timer-phase').textContent = '';
                updateDisplay();
                updateSkipBreakVisibility();
                playRing('break');
                showAlarmModal('☕ Break Over!', '💪 Ready for the next session?', 'break');
            }
            saveState();
        }
    }, 1000);
}

// Skip the current break and go straight back to a ready focus session
function skipBreak() {
    if (!onBreak) return;
    clearInterval(interval); running = false;
    onBreak = false;
    totalSecs = workMins * 60;
    document.getElementById('start-btn').textContent = '▶ Start';
    document.getElementById('timer-phase').textContent = '';
    updateDisplay();
    updateSkipBreakVisibility();
    saveState();
}

// Show/hide the Skip Break button and lock the Start/Pause button — a break can't be paused,
// Skip Break is the only way to interrupt one
function updateSkipBreakVisibility() {
    const isBreak = onBreak && !noBreakMode;
    const btn = document.getElementById('skip-break-btn');
    if (btn) btn.style.display = isBreak ? 'inline-flex' : 'none';
    const startBtn = document.getElementById('start-btn');
    if (startBtn) {
        startBtn.disabled = isBreak;
        startBtn.style.opacity = isBreak ? '0.4' : '1';
        startBtn.style.cursor = isBreak ? 'not-allowed' : 'pointer';
        if (isBreak) startBtn.title = "Breaks can't be paused — use Skip Break if you need to stop early";
        else startBtn.removeAttribute('title');
    }
}

function resetTimer() {
    clearInterval(interval); running = false; onBreak = false;
    totalSecs = workMins * 60;
    pomCount = 0;
    updateDisplay();
    renderDots();
    document.getElementById('start-btn').textContent = '▶ Start';
    document.getElementById('timer-phase').textContent = '';
    updateSkipBreakVisibility();
    saveState();
}

function renderDots() {
    const wrap  = document.getElementById('pom-dots-wrap');
    const phase = document.getElementById('timer-phase');
    const hide  = noBreakMode || totalSessions < 1;
    wrap.style.display = hide ? 'none' : 'flex';
    wrap.style.margin  = hide ? '0' : '0 0 20px 0';
    phase.style.marginBottom = phase.textContent.trim() ? '20px' : '0';
    if (hide) return;
    const done    = pomCount % totalSessions;
    const pct     = totalSessions > 0 ? Math.round((done / totalSessions) * 100) : 0;
    const fill    = document.getElementById('sess-bar-fill');
    const label   = document.getElementById('sess-bar-label');
    const sub     = document.getElementById('sess-bar-sub');
    if (fill)  fill.style.width = pct + '%';
    // Colour: green when all done, purple otherwise
    if (fill)  fill.style.background = (done >= totalSessions) ? '#6af7b8' : '#7c6af7';
    if (label) label.textContent = done + ' / ' + totalSessions;
    if (label) label.style.color = (done >= totalSessions) ? '#6af7b8' : 'var(--text-main)';
    if (sub)   sub.innerHTML = done >= totalSessions
        ? '<span id="sess-bar-label" style="font-weight:700;color:#6af7b8;">' + done + ' / ' + totalSessions + '</span> ✓ all sessions complete'
        : '<span id="sess-bar-label" style="font-weight:700;color:var(--text-main);">' + done + ' / ' + totalSessions + '</span> sessions complete';
}
function updateDots() { renderDots(); }
function updateDotsVisibility() {
    noBreakMode = document.getElementById('no-break').checked;
    renderDots();
}

function openCustom(btn) {
    var panel = document.getElementById('custom-panel');
    var isOpen = panel.style.display !== 'none';
    panel.style.display = isOpen ? 'none' : 'block';
    document.querySelectorAll('.mode-btn').forEach(function(b){ b.classList.remove('active'); });
    if (!isOpen) {
        btn.classList.add('active');
        // Restore previously saved custom values
        document.getElementById('cw-h').value = savedCustom.wh;
        document.getElementById('cw-m').value = savedCustom.wm;
        document.getElementById('cw-s').value = savedCustom.ws;
        document.getElementById('cb-h').value = savedCustom.bh;
        document.getElementById('cb-m').value = savedCustom.bm;
        document.getElementById('cb-s').value = savedCustom.bs;
        document.getElementById('no-break').checked = savedCustom.noBreak;
        document.getElementById('cw-sessions').value = savedCustom.sessions || 4;
        toggleSection('break-section','no-break','no-break');
        updateDotsVisibility();
    }
}

function stepVal2(id, delta, min, max) {
    var el = document.getElementById(id);
    var val = parseInt(el.value) || 0;
    val = Math.min(max, Math.max(min, val + delta));
    el.value = val;
}

// ── Hard limits — 4 digits max per field ────────────────────────────
const HOURS_MAX   = 9999;   // focus & break hours: 0–9999
const SESSION_MIN = 1;
const SESSION_MAX = 99;

// Clamp all custom fields to their hard limits; returns true if any value was changed
function sanitiseCustomInputs() {
    var changed = false;
    function clampField(id, lo, hi) {
        var el  = document.getElementById(id);
        // Hours/sessions get digit truncation before parsing
        var maxLen = (id === 'cw-sessions') ? 2 : 4;
        if (el.value.length > maxLen) el.value = el.value.slice(0, maxLen);
        var raw = parseInt(el.value);
        var val = isNaN(raw) ? lo : Math.min(hi, Math.max(lo, raw));
        if (el.value != val) { el.value = val; changed = true; }
    }
    clampField('cw-h', 0, HOURS_MAX);
    clampField('cw-m', 0, 59);
    clampField('cw-s', 0, 59);
    clampField('cb-h', 0, HOURS_MAX);
    clampField('cb-m', 0, 59);
    clampField('cb-s', 0, 59);
    clampField('cw-sessions', SESSION_MIN, SESSION_MAX);
    return changed;
}
function setCustomWork(h, m, s) {
    document.getElementById('cw-h').value = h;
    document.getElementById('cw-m').value = m;
    document.getElementById('cw-s').value = s;
}
function setCustomBreak(h, m, s) {
    document.getElementById('cb-h').value = h;
    document.getElementById('cb-m').value = m;
    document.getElementById('cb-s').value = s;
}
function getFieldSecs(h_id, m_id, s_id) {
    var h = parseInt(document.getElementById(h_id).value) || 0;
    var m = parseInt(document.getElementById(m_id).value) || 0;
    var s = parseInt(document.getElementById(s_id).value) || 0;
    return h * 3600 + m * 60 + s;
}
function fmtDuration(secs) {
    var h = Math.floor(secs / 3600);
    var m = Math.floor((secs % 3600) / 60);
    var s = secs % 60;
    var parts = [];
    if (h > 0) parts.push(h + 'h');
    if (m > 0) parts.push(m + 'm');
    if (s > 0) parts.push(s + 's');
    return parts.length ? parts.join(' ') : '0s';
}
function toggleSection(sectionId, thisId, otherId) {
    var checked = document.getElementById(thisId).checked;
    var section = document.getElementById(sectionId);
    section.style.opacity = checked ? '0.35' : '1';
    section.style.pointerEvents = checked ? 'none' : '';
}
function applyCustom() {
    if (running) { alert('Please stop the timer before changing settings.'); return; }
    sanitiseCustomInputs();   // clamp any typed-in out-of-range values first
    var noBreak  = document.getElementById('no-break').checked;
    var sessions = parseInt(document.getElementById('cw-sessions').value) || SESSION_MIN;
    sessions = Math.min(SESSION_MAX, Math.max(SESSION_MIN, sessions));
    // Save values so they persist when panel is reopened
    savedCustom = {
        wh: parseInt(document.getElementById('cw-h').value)||0,
        wm: parseInt(document.getElementById('cw-m').value)||0,
        ws: parseInt(document.getElementById('cw-s').value)||0,
        bh: parseInt(document.getElementById('cb-h').value)||0,
        bm: parseInt(document.getElementById('cb-m').value)||0,
        bs: parseInt(document.getElementById('cb-s').value)||0,
        noBreak: noBreak,
        sessions: sessions
    };
    var workSecs = getFieldSecs('cw-h','cw-m','cw-s');
    var brkSecs  = noBreak ? 0 : getFieldSecs('cb-h','cb-m','cb-s');
    if (workSecs < 1) { alert('Focus duration must be at least 1 second.'); return; }
    if (!noBreak && brkSecs < 1)  { alert('Break duration must be at least 1 second, or tick "No Break".'); return; }
    workMins      = workSecs / 60;
    breakMins     = brkSecs  / 60;
    totalSecs     = workSecs;
    onBreak       = false;
    noBreakMode   = noBreak;
    totalSessions = sessions;  // always keep count; noBreakMode flag hides dots
    pomCount      = 0;
    renderDots();
    updateDisplay();
    var breakLabel = noBreak ? 'No Break' : fmtDuration(brkSecs) + ' break';
    document.getElementById('timer-mode-label').textContent = 'Custom · ' + fmtDuration(workSecs) + ' focus / ' + breakLabel;
    document.getElementById('timer-phase').textContent = '';
    document.getElementById('custom-panel').style.display = 'none';
    document.querySelectorAll('.mode-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('btn-custom').classList.add('active');
    updateSkipBreakVisibility();
    saveState();
}

// ── Alarm dismiss modal ──────────────────────────────────────────────
function showAlarmModal(title, msg, type) {
    // Remove any existing modal
    const old = document.getElementById('alarm-modal');
    if (old) old.remove();

    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
    const accent = type === 'session' ? '#7c6af7' : (type === 'complete' ? '#6af7b8' : '#6af7b8');
    const icon   = type === 'session' ? '🔔' : (type === 'complete' ? '🏆' : '☕');

    const modal = document.createElement('div');
    modal.id = 'alarm-modal';
    modal.style.cssText = `
        position:fixed;inset:0;z-index:9999;
        display:flex;align-items:center;justify-content:center;
        background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);
        animation:fadeInBg .2s ease;
    `;
    // Read actual computed theme colours so modal follows light/dark mode
    const docStyle  = getComputedStyle(document.documentElement);
    const bgCard    = docStyle.getPropertyValue('--bg-surface').trim() || (isLight ? '#ffffff' : '#1c1c26');
    const textMain  = docStyle.getPropertyValue('--text-main').trim()  || (isLight ? '#111122' : '#e8e8f0');
    const textMuted = docStyle.getPropertyValue('--text-muted').trim() || (isLight ? '#4a4a6a' : '#7a7a95');

    modal.innerHTML = `
        <div style="
            background:${bgCard};border:2px solid ${accent};
            border-radius:20px;padding:36px 40px;text-align:center;
            max-width:340px;width:90%;box-shadow:0 0 40px ${accent}55;
            animation:popIn .25s cubic-bezier(.34,1.56,.64,1);
        ">
            <div style="font-size:52px;margin-bottom:12px;animation:ringBell 0.5s ease infinite alternate;">${icon}</div>
            <div style="font-size:20px;font-weight:700;color:${textMain};margin-bottom:8px;">${title}</div>
            <div style="font-size:14px;color:${textMuted};margin-bottom:28px;">${msg}</div>
            <button onclick="dismissAlarm('${type}')" style="
                background:${accent};border:none;border-radius:12px;
                color:#fff;font-weight:700;font-size:15px;
                padding:12px 32px;cursor:pointer;
                box-shadow:0 4px 16px ${accent}66;
                transition:opacity .2s;
            " onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                Dismiss Alarm
            </button>
        </div>
        <style>
            @keyframes fadeInBg { from{opacity:0} to{opacity:1} }
            @keyframes popIn    { from{transform:scale(.8);opacity:0} to{transform:scale(1);opacity:1} }
            @keyframes ringBell { from{transform:rotate(-15deg)} to{transform:rotate(15deg)} }
        </style>
    `;
    document.body.appendChild(modal);
}

function dismissAlarm(type) {
    stopAlarm();
    const modal = document.getElementById('alarm-modal');
    if (modal) modal.remove();
    // Auto-start the break the moment the "session complete" alarm is closed —
    // stops the break from being skipped just by not pressing Start.
    if (type === 'session' && onBreak && !noBreakMode) {
        startTimer();
    }
}

// ── Restore state on page load ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (loadState()) {
        updateDisplay();
        renderDots();
        updateSkipBreakVisibility();
    } else {
        renderDots(); // render default 4 dots on fresh load
    }
});

// ── Active session ID (set when timer starts via focus_api) ──────────
let activeSessionId = null;
let tabHiddenAt     = null;   // timestamp when tab was hidden

// ── Whitelist slot list ───────────────────────────────────────────────
function getWhitelist() {
    const slots = parseInt(localStorage.getItem('pt_wl_slots') || '3', 10);
    const list = [];
    for (let i = 0; i < slots; i++) {
        const v = (localStorage.getItem('pt_wl_' + i) || '').trim().toLowerCase();
        if (v) list.push(v);
    }
    return list;
}

function saveSlot(i) {
    const input = document.getElementById('wl-slot-' + i);
    if (input) localStorage.setItem('pt_wl_' + i, input.value.trim().toLowerCase());
}

function renderWhitelistSlots() {
    const slots = parseInt(localStorage.getItem('pt_wl_slots') || '3', 10);
    document.getElementById('slot-count').textContent = slots;
    const container = document.getElementById('whitelist-slots');
    container.innerHTML = '';
    for (let i = 0; i < slots; i++) {
        const saved = localStorage.getItem('pt_wl_' + i) || '';
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:7px;';
        row.innerHTML = `
            <span style="font-size:11px;color:#7a7a95;min-width:16px;text-align:right;">${i + 1}.</span>
            <input type="text" id="wl-slot-${i}"
                value="${saved}"
                placeholder="e.g. docs.google.com"
                oninput="saveSlot(${i})"
                style="flex:1;background:var(--bg-input);border:1px solid var(--border);color:var(--text-main);
                       border-radius:8px;padding:7px 10px;font-size:12px;box-sizing:border-box;">
        `;
        container.appendChild(row);
    }
}

function changeSlots(delta) {
    let slots = parseInt(localStorage.getItem('pt_wl_slots') || '3', 10);
    slots = Math.max(1, Math.min(10, slots + delta));
    localStorage.setItem('pt_wl_slots', slots);
    renderWhitelistSlots();
}

function getGrace() {
    return parseInt(localStorage.getItem('pt_grace') || '60', 10);
}
function updateGrace(val) {
    localStorage.setItem('pt_grace', val);
    document.getElementById('grace-val-label').textContent = val + 's';
}

// Load saved whitelist + grace on page load
document.addEventListener('DOMContentLoaded', () => {
    renderWhitelistSlots();
    const grace = getGrace();
    document.getElementById('grace-slider').value = grace;
    document.getElementById('grace-val-label').textContent = grace + 's';
});

// ── Browser notification permission request ───────────────────────────
function requestNotifPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

// ── Fire a browser notification while student is on another tab ──────
let awayNotifTimer = null;
function scheduleAwayNotif(graceSecs) {
    clearAwayNotif();
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    awayNotifTimer = setTimeout(() => {
        const n = new Notification('⏱️ ProcraTrack — Still away?', {
            body: "You've been away from your focus session. Come back when you're ready.",
            icon: 'assets/icon-192.png',
            tag:  'pt-away',
            requireInteraction: true
        });
        n.onclick = () => { window.focus(); n.close(); };
    }, graceSecs * 1000);
}
function clearAwayNotif() {
    if (awayNotifTimer) { clearTimeout(awayNotifTimer); awayNotifTimer = null; }
    if ('Notification' in window) {
        // Close any existing away notification
        const dummy = new Notification('', {tag: 'pt-away', silent: true});
        dummy.close();
    }
}

// ── Save distraction to DB + update UI ───────────────────────────────
function showDistractionToast(label, isWarning = true) {
    const old = document.getElementById('distraction-toast');
    if (old) old.remove();
    const toast = document.createElement('div');
    toast.id = 'distraction-toast';
    const color = isWarning ? 'var(--warn-accent)' : '#0a8f5e';
    const border = isWarning ? 'var(--warn-accent)' : '#0a8f5e';
    toast.style.cssText = `
        position:fixed;bottom:24px;right:24px;z-index:9998;
        background:var(--bg-surface);border:1px solid ${border};border-radius:12px;
        padding:10px 16px;font-size:13px;color:${color};
        box-shadow:0 4px 20px rgba(0,0,0,.25);
        animation:toastIn .2s ease;pointer-events:none;
    `;
    toast.innerHTML = isWarning ? `⚠️ Logged: <b>${label}</b>` : `✅ <b>${label}</b>`;
    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 3000);
}

function logDistraction(type, eventType = 'manual', durationAway = 0, reason = '') {
    const now = new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    const label = reason ? type + ' — ' + reason : type;
    distractions.unshift({type: label, time: now});

    const list = document.getElementById('distraction-list');
    list.innerHTML = distractions.map(d =>
        `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span style="color:var(--text-main)">${d.type}</span>
            <span style="color:var(--text-muted)">${d.time}</span>
        </div>`
    ).join('');

    if (eventType !== 'manual') {
        showDistractionToast(label, true);
    }

    fetch('focus_api.php?action=log_distraction', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action:        'log_distraction',
            session_id:    activeSessionId,
            event_type:    eventType,
            duration_away: durationAway,
            note:          label
        })
    }).catch(() => {});
}

// ── "What were you doing?" popup ─────────────────────────────────────
function showDistractionPopup(away, onConfirm, onForgive) {
    const existing = document.getElementById('pt-distract-popup');
    if (existing) existing.remove();

    const mins = Math.floor(away / 60);
    const secs = away % 60;
    const awayStr = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;

    const overlay = document.createElement('div');
    overlay.id = 'pt-distract-popup';
    overlay.style.cssText = `
        position:fixed;inset:0;z-index:10000;
        background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);
        display:flex;align-items:center;justify-content:center;padding:20px;
    `;
    overlay.innerHTML = `
        <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:16px;
                    padding:24px;max-width:380px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,.4);">
            <div style="font-size:22px;margin-bottom:8px;">👀</div>
            <div style="font-size:15px;font-weight:700;color:var(--text-main);margin-bottom:6px;">
                You were away for <span style="color:var(--warn-accent);">${awayStr}</span>
            </div>
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">
                Was that productive or a distraction?
            </div>

            <button id="popup-yes" style="width:100%;padding:11px;border-radius:10px;border:none;
                background:rgba(106,247,184,0.12);color:#0a8f5e;font-size:13px;font-weight:600;
                cursor:pointer;margin-bottom:10px;">
                ✅ Productive — I was doing research
            </button>

            <div style="position:relative;margin-bottom:10px;">
                <button id="popup-no-btn" style="width:100%;padding:11px;border-radius:10px;border:none;
                    background:rgba(247,164,106,0.12);color:var(--warn-accent);font-size:13px;font-weight:600;
                    cursor:pointer;" onclick="document.getElementById('popup-reason-area').style.display='block';this.style.display='none';">
                    ❌ Distraction — I got sidetracked
                </button>
                <div id="popup-reason-area" style="display:none;">
                    <input type="text" id="popup-reason" maxlength="60"
                        placeholder="What were you doing? (e.g. YouTube, game, social media)"
                        style="width:100%;background:var(--bg-input);border:1px solid var(--warn-accent);color:var(--text-main);
                               border-radius:8px;padding:9px 10px;font-size:12px;box-sizing:border-box;margin-bottom:8px;"
                        autofocus>
                    <button id="popup-confirm" style="width:100%;padding:10px;border-radius:10px;border:none;
                        background:var(--warn-accent);color:#16161d;font-size:13px;font-weight:700;cursor:pointer;">
                        Log it
                    </button>
                </div>
            </div>

            <div style="font-size:11px;color:var(--text-muted);text-align:center;">
                Be honest — only you can see this 👤
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('#popup-yes').onclick = () => { overlay.remove(); onForgive(); };
    overlay.querySelector('#popup-confirm').onclick = () => {
        const reason = overlay.querySelector('#popup-reason').value.trim() || 'unspecified';
        overlay.remove();
        onConfirm(reason);
    };
    // Allow Enter key to submit reason
    overlay.querySelector('#popup-reason').addEventListener('keydown', e => {
        if (e.key === 'Enter') overlay.querySelector('#popup-confirm').click();
    });
}

// ── Core switch handler ───────────────────────────────────────────────
function handleReturn(away, source) {
    clearAwayNotif();
    if (away < 3) return; // flicker — ignore

    const grace       = getGrace();
    const withinGrace = away < grace;
    const whitelist   = getWhitelist();

    // Within grace AND site is whitelisted (or whitelist is empty) → fully forgiven
    if (withinGrace && whitelist.length === 0) {
        showDistractionToast('Back in ' + away + 's — forgiven ✓', false);
        return;
    }

    if (withinGrace && whitelist.length > 0) {
        // Short switch but whitelist is set — still forgive (they pre-approved these sites)
        showDistractionToast('Back in ' + away + 's — forgiven ✓', false);
        return;
    }

    // Over grace period — show popup
    const label = source === 'window'
        ? `🖥️ Left window (${away}s away)`
        : `🌐 Tab switch (${away}s away)`;

    showDistractionPopup(away,
        (reason) => { logDistraction(label, 'tab_switch', away, reason); },
        ()       => { showDistractionToast('Marked as productive ✓', false); }
    );
}

// ── Auto-detect tab switch / window blur ─────────────────────────────
document.addEventListener('visibilitychange', () => {
    if (!running || onBreak) return;
    if (document.hidden) {
        tabHiddenAt = Date.now();
        scheduleAwayNotif(getGrace()); // fire browser notif after grace period
    } else {
        clearAwayNotif();
        const away = tabHiddenAt ? Math.round((Date.now() - tabHiddenAt) / 1000) : 0;
        tabHiddenAt = null;
        handleReturn(away, 'tab');
    }
});

window.addEventListener('blur', () => {
    if (!running || onBreak) return;
    if (!tabHiddenAt) {
        tabHiddenAt = Date.now();
        scheduleAwayNotif(getGrace());
    }
});

window.addEventListener('focus', () => {
    if (!running || onBreak || !tabHiddenAt) return;
    clearAwayNotif();
    const away = Math.round((Date.now() - tabHiddenAt) / 1000);
    tabHiddenAt = null;
    handleReturn(away, 'window');
});
</script>

<?php require_once 'includes/footer.php'; ?>
