<?php
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'lecturer') {
    header('Location: ' . tab_url('index.php', $GLOBALS['tab_id'])); exit;
}
$lec_name = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : '';
// Always fetch university from DB (not stored in session)
if (!isset($pdo)) require_once __DIR__ . '/../config/db.php';
$__lec = $pdo->prepare("SELECT name, university FROM users WHERE id = ?");
$__lec->execute([$_SESSION['user_id']]);
$__lec_row = $__lec->fetch(PDO::FETCH_ASSOC);
if (empty($lec_name)) {
    $lec_name = $__lec_row['name'] ?? 'Lecturer';
    $_SESSION['user_name'] = $lec_name;
}
if (empty($lec_name)) $lec_name = 'Lecturer';
// $lec_university is available to all lecturer pages for data scoping
$lec_university = $__lec_row['university'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<script>(function(){var t=localStorage.getItem("pt_lecturer_theme")||"dark";document.documentElement.setAttribute("data-theme",t);var b=localStorage.getItem("pt_lecturer_bg")||"default";document.documentElement.setAttribute("data-bg",b);})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — <?= htmlspecialchars($pageTitle ?? 'Lecturer') ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-base:    #1a1a22;
            --bg-surface: #26262f;
            --card-bg:    #26262f;
            --bg-input:   #20202a;
            --card-alt-bg: #20202a;
            --border:     #34343f;
            --text-main:  #ececf2;
            --text-muted: #9494ad;
            --accent:     #7c6af7;
            --accent2:    #6af7b8;
        }
        [data-theme="light"] {
            --bg-base:    #f2f2f8;
            --bg-surface: #ffffff;
            --card-bg:    #ffffff;
            --bg-input:   #ffffff;
            --border:     #d0d0e0;
            --text-main:  #111122;
            --text-muted: #4a4a6a;
            --accent:     #5b49e0;
            --accent2:    #0a9e6a;
        }

        /* ── SMOOTH THEME TRANSITION ── */
        body,
        .lec-sidebar, .lec-main, .card,
        .lec-nav a, .lec-user, .page-topbar,
        .mobile-topnav {
            transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease;
        }
        @media (max-width: 768px) {
            .page-topbar .theme-toggle { display: none; }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg-base); color: var(--text-main); font-family: 'Segoe UI', sans-serif; min-height: 100vh; background-attachment: fixed; }

        /* ── BACKGROUND PRESETS (dark) ── */
        [data-theme="dark"][data-bg="purple"] body { background: linear-gradient(160deg, #1a1a22 0%, #2e2150 45%, #1a1a22 100%) !important; }
        [data-theme="dark"][data-bg="ocean"] body { background: linear-gradient(160deg, #0f1f2c 0%, #1a4a63 45%, #1a1a22 100%) !important; }
        [data-theme="dark"][data-bg="sunset"] body { background: linear-gradient(160deg, #2e1c20 0%, #4a2a18 45%, #1a1a22 100%) !important; }
        [data-theme="dark"][data-bg="forest"] body { background: linear-gradient(160deg, #142018 0%, #1c3a2a 45%, #1a1a22 100%) !important; }
        [data-theme="dark"][data-bg="rose"] body { background: linear-gradient(160deg, #2a1820 0%, #4a2038 45%, #1a1a22 100%) !important; }

        /* ── BACKGROUND PRESETS (light) ── */
        [data-theme="light"][data-bg="purple"] body { background: linear-gradient(160deg, #f2f2f8 0%, #ddd4fb 45%, #f2f2f8 100%) !important; }
        [data-theme="light"][data-bg="ocean"] body { background: linear-gradient(160deg, #eef6fa 0%, #b8e2f2 45%, #f2f2f8 100%) !important; }
        [data-theme="light"][data-bg="sunset"] body { background: linear-gradient(160deg, #fdf0ee 0%, #fbe0cf 45%, #f2f2f8 100%) !important; }
        [data-theme="light"][data-bg="forest"] body { background: linear-gradient(160deg, #eef6f0 0%, #cfe9d8 45%, #f2f2f8 100%) !important; }
        [data-theme="light"][data-bg="rose"] body { background: linear-gradient(160deg, #fdeef3 0%, #fbd6e6 45%, #f2f2f8 100%) !important; }

        .bg-preset-option.active > div:first-child { border-color: var(--accent) !important; box-shadow: 0 0 0 2px rgba(124,106,247,0.25); }
        .bg-preset-option { transition: transform 0.15s ease; }
        .bg-preset-option:hover { transform: translateY(-2px); }
        .bg-preset-option .bg-check {
            position: absolute; top: 4px; right: 4px;
            width: 18px; height: 18px; border-radius: 50%;
            background: var(--accent); color: #fff;
            display: none; align-items: center; justify-content: center;
            font-size: 11px;
        }
        .bg-preset-option.active .bg-check { display: flex; }

        /* ── Sidebar ── */
        .lec-sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: 230px;
            background: var(--bg-input); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 100; padding: 0;
            overflow: visible;
            transition: width 0.22s ease;
        }
        .lec-logo {
            padding: 20px 18px; font-size: 17px; font-weight: 700;
            color: var(--accent); border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            flex-shrink: 0;
        }
        .lec-logo span { color: var(--text-main); }
        .lec-logo-icon { display: inline; }
        .lec-badge {
            font-size: 10px; font-weight: 700; background: rgba(124,106,247,.2);
            color: var(--accent); border-radius: 20px; padding: 2px 8px;
            letter-spacing: .4px; margin-left: 8px; flex-shrink: 0;
        }
        .lec-nav { flex: 1; padding: 12px 10px; overflow-y: auto; overflow-x: hidden; flex-shrink: 1; min-height: 0; }
        .lec-nav::-webkit-scrollbar { width: 6px; }
        .lec-nav::-webkit-scrollbar-track { background: transparent; }
        .lec-nav::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; }
        .lec-nav::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        .lec-nav { scrollbar-color: var(--border) transparent; scrollbar-width: thin; }
        .lec-nav a {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 500;
            color: var(--text-muted); margin-bottom: 2px; transition: background 0.2s, color 0.2s;
        }
        .lec-nav a:hover  { background: var(--border); color: var(--text-main); }
        .lec-nav a.active { background: rgba(124,106,247,.15); color: var(--accent); }
        .lec-nav a i      { font-size: 18px; width: 22px; text-align: center; flex-shrink: 0; }
        /* Nav badge dots (chat unread / pending invites) */
        .nav-icon-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 22px; flex-shrink: 0; }
        .nav-icon-wrap i { width: auto; }
        .nav-dot {
            position: absolute; top: -3px; right: -2px;
            min-width: 8px; height: 8px; border-radius: 50%;
            background: #7c6af7; border: 1.5px solid var(--bg-input);
            display: none;
        }
        .nav-dot.invite-dot { background: #f76a6a; right: 6px; }
        .nav-dot.show { display: block; }
        .nav-dot.count {
            min-width: 14px; height: 14px; right: -6px; top: -5px;
            font-size: 8px; font-weight: 700; color: #fff;
            display: none; align-items: center; justify-content: center; line-height: 1;
        }
        .nav-dot.invite-dot.count { right: -1px; }
        .nav-dot.count.show { display: flex; }
        .lec-user {
            padding: 14px 16px; border-top: 1px solid var(--border);
            font-size: 13px; color: var(--text-muted);
            flex-shrink: 0;
        }
        .lec-user b { display: block; color: var(--text-main); font-size: 13px; margin-bottom: 2px; }
        .lec-user .role-tag {
            display: inline-block; font-size: 10px; font-weight: 700;
            background: rgba(106,247,184,.15); color: var(--accent2);
            border-radius: 20px; padding: 2px 8px; margin-bottom: 8px;
        }
        .lec-user-info { overflow: hidden; max-height: 80px; opacity: 1; transition: opacity 0.15s ease, max-height 0.22s ease; }

        /* ── Main ── */
        .lec-main { margin-left: 230px; padding: 28px 30px; min-height: 100vh; transition: margin-left 0.22s ease; overflow-x: hidden; }
        .page-topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .page-title  { font-size: 22px; font-weight: 700; color: var(--text-main); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 0; }
        .page-sub    { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }

        /* ── Cards ── */
        .card {
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: 14px; padding: 20px; margin-bottom: 20px;
        }
        .card-title-sm {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 14px;
        }
        .stat-card {
            background: var(--bg-surface); border: 1px solid var(--border);
            border-radius: 14px; padding: 16px; height: 100%; box-sizing: border-box;
        }
        .stat-val   { font-size: clamp(20px,3vw,28px); font-weight: 700; margin-bottom: 4px; }
        .stat-label { font-size: 12px; color: var(--text-muted); }

        /* ── Table ── */
        .lec-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .lec-table th {
            text-align: left; padding: 10px 12px; color: var(--text-muted);
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; border-bottom: 1px solid var(--border);
        }
        .lec-table td {
            padding: 11px 12px; color: var(--text-main);
            border-bottom: 1px solid var(--border); vertical-align: middle;
        }
        .lec-table tr:last-child td { border-bottom: none; }
        .lec-table tr:hover td { background: rgba(124,106,247,.04); }

        /* ── Table scroll wrapper ── */
        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 8px;
        }
        .table-scroll::-webkit-scrollbar { height: 10px; }
        .table-scroll::-webkit-scrollbar-track {
            background: var(--bg-input);
            border-radius: 6px;
        }
        .table-scroll::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 6px;
            border: 2px solid var(--bg-surface);
        }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        .table-scroll { scrollbar-color: var(--border) var(--bg-input); scrollbar-width: thin; }

        /* ── Badge pills ── */
        .pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .pill-green  { background: rgba(106,247,184,.15); color: #6af7b8; }
        .pill-yellow { background: rgba(247,196,106,.15); color: #f7c46a; }
        .pill-red    { background: rgba(247,106,106,.15); color: #f76a6a; }
        .pill-purple { background: rgba(124,106,247,.15); color: #7c6af7; }

        /* ── Theme toggle ── */
        .theme-toggle {
            background: var(--bg-input); border: 1px solid var(--border);
            color: var(--text-muted); border-radius: 8px; width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px; flex-shrink: 0; transition: all 0.2s;
        }
        .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }

        /* ── Light mode overrides ── */
        [data-theme="light"] .lec-sidebar { background: #f0f0fa; }
        [data-theme="light"] .lec-table tr:hover td { background: rgba(91,73,224,.04); }
        [data-theme="light"] .page-topbar { border-bottom-color: var(--border) !important; }

        /* Badge pills — neon colors are unreadable on white, darken for light mode */
        [data-theme="light"] .pill-green  { color: #0a8f5e !important; }
        [data-theme="light"] .pill-yellow { color: #b07d00 !important; }
        [data-theme="light"] .pill-red    { color: #c0392b !important; }
        [data-theme="light"] .pill-purple { color: #5b49e0 !important; }

        /* Alert boxes — same treatment */
        [data-theme="light"] .alert-ok     { color: #0a8f5e !important; }
        [data-theme="light"] .alert-warn   { color: #b07d00 !important; }
        [data-theme="light"] .alert-danger { color: #c0392b !important; }

        /* Any remaining inline-styled green/yellow/red/purple text on lecturer pages */
        [data-theme="light"] [style*="color:#6af7b8"],
        [data-theme="light"] [style*="color: #6af7b8"] { color: #0a8f5e !important; }
        [data-theme="light"] [style*="color:#f7c46a"],
        [data-theme="light"] [style*="color: #f7c46a"] { color: #b07d00 !important; }
        [data-theme="light"] [style*="color:#f76a6a"],
        [data-theme="light"] [style*="color: #f76a6a"] { color: #c0392b !important; }
        [data-theme="light"] [style*="color:#7c6af7"],
        [data-theme="light"] [style*="color: #7c6af7"] { color: #5b49e0 !important; }

        /* ── Alerts ── */
        .alert-warn  { background: rgba(247,196,106,.08); border: 1px solid rgba(247,196,106,.25); color: #f7c46a; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }
        .alert-ok    { background: rgba(106,247,184,.08); border: 1px solid rgba(106,247,184,.25); color: #6af7b8; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }
        .alert-danger{ background: rgba(247,106,106,.08); border: 1px solid rgba(247,106,106,.25); color: #f76a6a; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }

        /* ── Collapsed sidebar — icon only ── */
        .lec-sidebar.collapsed .lec-logo-text,
        .lec-sidebar.collapsed .nav-label,
        .lec-sidebar.collapsed .lec-badge,
        .lec-sidebar.collapsed .lec-user-info { display: none; }
        .lec-sidebar.collapsed .lec-logo { justify-content: center; padding: 10px 0; }
        .lec-sidebar.collapsed .lec-nav { padding: 8px 0; overflow-y: auto; overflow-x: hidden; }
        .lec-sidebar.collapsed .lec-nav a { justify-content: center; padding: 11px 0; }
        .lec-sidebar.collapsed .lec-nav a i { width: auto; }
        .lec-sidebar.collapsed .lec-user { padding: 12px 0; display: flex; flex-direction: column; align-items: center; }

        /* ── Sidebar toggle button — fixed so overflow never clips it ── */
        .sidebar-toggle {
            position: fixed; top: 50%;
            transform: translateY(-50%);
            left: calc(230px - 14px);
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--bg-surface); border: 1px solid var(--border);
            color: var(--text-muted); cursor: pointer; z-index: 200;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            transition: left 0.22s ease, background 0.2s, border-color 0.2s, color 0.2s;
        }
        .sidebar-toggle:hover { background: var(--accent); border-color: var(--accent); color: #fff; }

        /* ── MOBILE TOP NAVBAR ── */
        .mobile-topnav {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 52px;
            background: var(--bg-input);
            border-bottom: 1px solid var(--border);
            align-items: center;
            padding: 0 14px;
            gap: 12px;
            z-index: 150;
        }
        .mobile-topnav-brand {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent);
            flex: 1;
        }
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 22px;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 99;
        }
        @media (max-width: 768px) {
            .mobile-topnav   { display: flex; }
            .mobile-menu-btn { display: block; }
            .sidebar-toggle  { display: none; }
            .lec-sidebar {
                width: 220px !important;
                transform: translateX(-100%);
                transition: transform 0.25s ease !important;
                z-index: 200;
            }
            .lec-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-overlay.active  { display: block; }
            .lec-main {
                margin-left: 0 !important;
                padding: 66px 14px 20px !important;
            }
            .page-title { font-size: 18px !important; }
            table { min-width: 500px; }
            .table-wrap, .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .row .col-md-6,
            .row .col-md-4,
            .row .col-md-3 { flex: 0 0 100%; max-width: 100%; }
        }
        @media (max-width: 480px) {
            .lec-main { padding: 62px 10px 16px !important; }
        }
    </style>

    <script>
    function toggleTheme() {
        var html = document.documentElement;
        var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        // Instant swap — CSS variables update immediately, no per-element transitions
        html.setAttribute('data-theme', next);
        localStorage.setItem('pt_lecturer_theme', next);
        document.querySelectorAll('.theme-icon').forEach(function(el) {
            el.className = 'bi bi-' + (next === 'dark' ? 'moon-stars-fill' : 'sun-fill') + ' theme-icon';
        });
    }
    function setBgPreset(name) {
        document.documentElement.setAttribute('data-bg', name);
        localStorage.setItem('pt_lecturer_bg', name);
        document.querySelectorAll('.bg-preset-option').forEach(function(el){
            el.classList.toggle('active', el.dataset.bg === name);
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        var t = localStorage.getItem('pt_lecturer_theme') || 'dark';
        document.querySelectorAll('.theme-icon').forEach(function(el) {
            el.className = 'bi bi-' + (t === 'dark' ? 'moon-stars-fill' : 'sun-fill') + ' theme-icon';
        });
        var b = localStorage.getItem('pt_lecturer_bg') || 'default';
        document.querySelectorAll('.bg-preset-option').forEach(function(el){
            el.classList.toggle('active', el.dataset.bg === b);
        });
    });
    </script>
    <script src="includes/tab_inject.js"></script>
</head>
<body>

<!-- Mobile top navbar (hidden on desktop) -->
<nav class="mobile-topnav">
    <button class="mobile-menu-btn" id="mobileMenuBtn" title="Open menu"><i class="bi bi-list"></i></button>
    <div class="mobile-topnav-brand">🧠 ProcraTrack <span style="font-size:11px;font-weight:600;background:rgba(124,106,247,0.15);color:var(--accent);padding:2px 7px;border-radius:20px;vertical-align:middle">LECTURER</span></div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="lec-sidebar">
    <button class="sidebar-toggle" id="lecSidebarToggle" title="Toggle sidebar"><i class="bi bi-chevron-left" id="lecSidebarToggleIcon"></i></button>
    <div class="lec-logo">
        <span class="lec-logo-icon">🧠</span><span class="lec-logo-text"> Procra<span>Track</span></span>
        <span class="lec-badge nav-label">LECTURER</span>
    </div>
    <nav class="lec-nav">
        <a href="lecturer.php"          class="<?= basename($_SERVER['PHP_SELF'])==='lecturer.php'?'active':'' ?>">
            <i class="bi bi-speedometer2"></i><span class="nav-label"> Overview</span>
        </a>
        <a href="lecturer_students.php" class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_students.php'?'active':'' ?>">
            <i class="bi bi-people"></i><span class="nav-label"> Students</span>
        </a>
        <a href="lecturer_tasks.php"    class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_tasks.php'?'active':'' ?>">
            <i class="bi bi-list-task"></i><span class="nav-label"> Task Reports</span>
        </a>
        <a href="lecturer_habits.php"   class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_habits.php'?'active':'' ?>">
            <i class="bi bi-calendar-check"></i><span class="nav-label"> Habit Reports</span>
        </a>
        <a href="lecturer_focus.php"    class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_focus.php'?'active':'' ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label"> Focus Reports</span>
        </a>
        <a href="lecturer_report.php"   class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_report.php'?'active':'' ?>">
            <i class="bi bi-bar-chart-line"></i><span class="nav-label"> Weekly Report</span>
        </a>
        <a href="lecturer_chat.php"     class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_chat.php'?'active':'' ?>">
            <span class="nav-icon-wrap">
                <i class="bi bi-chat-dots"></i>
                <span class="nav-dot count" id="navUnreadDot"></span>
                <span class="nav-dot invite-dot count" id="navInviteDot"></span>
            </span>
            <span class="nav-label"> Chat</span>
        </a>
        <a href="lecturer_settings.php" class="<?= basename($_SERVER['PHP_SELF'])==='lecturer_settings.php'?'active':'' ?>">
            <i class="bi bi-gear"></i><span class="nav-label"> Settings</span>
        </a>
    </nav>
    <div class="lec-user">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <div style="width:34px;height:34px;border-radius:50%;background:rgba(106,247,184,0.15);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;overflow:hidden;">
                <?php
                $__avatar = '';
                if (!empty($_SESSION['user_id'])) {
                    try {
                        if (!isset($pdo)) require_once __DIR__ . '/../config/db.php';
                        $__av = $pdo->prepare("SELECT avatar FROM users WHERE id=?");
                        $__av->execute([$_SESSION['user_id']]);
                        $__avatar = $__av->fetchColumn();
                    } catch(Exception $e) {}
                }
                if ($__avatar && file_exists(__DIR__ . '/../uploads/avatars/' . basename($__avatar))): ?>
                    <img src="uploads/avatars/<?= htmlspecialchars(basename($__avatar)) ?>?v=<?= filemtime(__DIR__ . '/../uploads/avatars/' . basename($__avatar)) ?>"
                         alt="avatar" style="width:34px;height:34px;object-fit:cover;border-radius:50%;display:block">
                <?php else: ?>
                    👤
                <?php endif; ?>
            </div>
            <div class="lec-user-info">
                <div style="font-size:10px;font-weight:700;color:var(--accent2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">Lecturer</div>
                <div style="font-size:13px;font-weight:600;color:var(--text-main);word-break:break-word;"><?= htmlspecialchars($lec_name) ?></div>
            </div>
        </div>
        <a href="logout.php" style="color:#e05555;text-decoration:none;font-size:13px;font-weight:500;
           padding:6px 10px;border-radius:8px;display:flex;align-items:center;gap:6px;
           border:1px solid rgba(224,85,85,0.25);width:100%;box-sizing:border-box;">
            <i class="bi bi-box-arrow-right"></i><span class="nav-label"> Logout</span>
        </a>
    </div>
</div>

<script>
(function(){
    var STORE_KEY   = 'pt_lecturer_sidebar_collapsed';
    var EXPANDED_W  = 230, COLLAPSED_W = 60;

    function applyState(collapsed, animate) {
        var sidebar = document.querySelector('.lec-sidebar');
        var main    = document.querySelector('.lec-main');
        var icon    = document.getElementById('lecSidebarToggleIcon');
        if (!sidebar || !main) return;

        if (!animate) {
            sidebar.style.transition = 'none';
            main.style.transition    = 'none';
        }

        sidebar.classList.toggle('collapsed', collapsed);
        var w = collapsed ? COLLAPSED_W : EXPANDED_W;
        sidebar.style.width = w + 'px';
        var btn = document.getElementById('lecSidebarToggle');
        if (btn) btn.style.left = (w - 14) + 'px';
        main.style.marginLeft = w + 'px';
        if (icon) icon.className = collapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
        localStorage.setItem(STORE_KEY, collapsed ? '1' : '0');

        if (!animate) {
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    sidebar.style.transition = '';
                    main.style.transition    = '';
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // On mobile, always show sidebar expanded
        var collapsed = window.innerWidth > 768 && localStorage.getItem(STORE_KEY) === '1';
        applyState(collapsed, false);

        var btn = document.getElementById('lecSidebarToggle');
        if (btn) btn.addEventListener('click', function() {
            var sidebar = document.querySelector('.lec-sidebar');
            applyState(!sidebar.classList.contains('collapsed'), true);
        });
    });
})();
</script>
<script>
(function(){
    function isMobile(){ return window.innerWidth <= 768; }
    var menuBtn = document.getElementById('mobileMenuBtn');
    var overlay = document.getElementById('sidebarOverlay');
    var sidebar = document.querySelector('.lec-sidebar');
    function openMobile(){ sidebar.classList.add('mobile-open'); overlay.classList.add('active'); }
    function closeMobile(){ sidebar.classList.remove('mobile-open'); overlay.classList.remove('active'); }
    if(menuBtn) menuBtn.addEventListener('click', openMobile);
    if(overlay) overlay.addEventListener('click', closeMobile);
    document.querySelectorAll('.lec-sidebar .lec-nav a').forEach(function(a){
        a.addEventListener('click', function(){ if(isMobile()) closeMobile(); });
    });
})();
</script>
<script>
// Chat nav badges (unread messages + pending invites) — polls on every page
(function(){
    function refresh(){
        fetch('chat_api.php?action=nav_badges')
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok) return;
                var u = document.getElementById('navUnreadDot');
                var i = document.getElementById('navInviteDot');
                if (u) {
                    if (d.unread > 0) { u.textContent = d.unread > 9 ? '9+' : d.unread; u.classList.add('show'); }
                    else { u.classList.remove('show'); }
                }
                if (i) {
                    if (d.invites > 0) { i.textContent = d.invites > 9 ? '9+' : d.invites; i.classList.add('show'); }
                    else { i.classList.remove('show'); }
                }
            })
            .catch(function(){ /* silent — badges just stay as-is */ });
    }
    refresh();
    setInterval(refresh, 4000);
    // Re-check the moment the tab regains focus/visibility, so switching
    // back to this tab shows the latest badge instead of waiting for the
    // next interval tick.
    document.addEventListener('visibilitychange', function(){
        if (document.visibilityState === 'visible') refresh();
    });
    window.addEventListener('focus', refresh);
})();
</script>
<div class="lec-main">
