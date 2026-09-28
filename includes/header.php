<?php
if (!isset($_SESSION['user_id'])) { header('Location: ' . tab_url('index.php', $GLOBALS['tab_id'])); exit; }
if (($_SESSION['user_role'] ?? '') === 'lecturer') { header('Location: ' . tab_url('lecturer.php', $GLOBALS['tab_id'])); exit; }
// Ensure user_name is in session; re-query DB if missing or empty
if (empty($_SESSION['user_name']) && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/db.php';
    $__s = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $__s->execute([$_SESSION['user_id']]);
    $_SESSION['user_name'] = $__s->fetchColumn() ?: '';
}

// Pending cross-institution access requests awaiting this student's decision
$__pending_access_count = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $__pac = $pdo->prepare("SELECT COUNT(*) FROM access_requests WHERE student_id=? AND status='pending'");
        $__pac->execute([$_SESSION['user_id']]);
        $__pending_access_count = (int)$__pac->fetchColumn();
    } catch (Exception $e) {}
}

// This user's saved theme/background — tied to their account, not the browser,
// so switching between accounts on the same device no longer leaks one
// student's theme into another's.
$__user_theme    = 'dark';
$__user_bg_theme = 'default';
if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $__tstmt = $pdo->prepare("SELECT theme, bg_theme FROM users WHERE id = ?");
        $__tstmt->execute([$_SESSION['user_id']]);
        if ($__trow = $__tstmt->fetch()) {
            $__user_theme    = $__trow['theme'] ?: 'dark';
            $__user_bg_theme = $__trow['bg_theme'] ?: 'default';
        }
    } catch (Exception $e) {}
}

ob_start()
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t="<?= htmlspecialchars($__user_theme, ENT_QUOTES) ?>";var b="<?= htmlspecialchars($__user_bg_theme, ENT_QUOTES) ?>";document.documentElement.setAttribute("data-theme",t);document.documentElement.setAttribute("data-bg",b);localStorage.setItem("pt_student_theme",t);localStorage.setItem("pt_student_bg",b);})();</script>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProcraTrack — <?= $pageTitle ?? 'Dashboard' ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ── THEME VARIABLES ── */
        :root {
            --bg-base:    #1a1a22;
            --bg-surface: #26262f;
            --card-bg:    #26262f;
            --bg-input:   #20202a;
            --card-alt-bg: #20202a;
            --border:     #34343f;
            --text-main:  #ececf2;
            --text-muted: #9494ad;
            --text-dim:   #3a3a52;
            --accent:     #7c6af7;
        }
        [data-theme="light"] {
            --bg-base:    #f2f2f8;
            --bg-surface: #ffffff;
            --card-bg:    #ffffff;
            --bg-input:   #ffffff;
            --card-alt-bg: #f5f5fa;
            --border:     #d0d0e0;
            --text-main:  #111122;
            --text-muted: #4a4a6a;
            --text-dim:   #8888aa;
            --accent:     #5b49e0;
        }

        /* ── SMOOTH THEME TRANSITION ── */
        /* Target only key structural elements — fast but visible, no full-page repaint */
        body,
        .sidebar, .main, .card, .stat-card,
        .nav-item, .sidebar-user, .page-topbar,
        .mobile-topnav, .alert-warn, .alert-ok, .alert-danger {
            transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease;
        }

        @media (max-width: 768px) {
            /* Hide per-page theme toggle on mobile — topnav handles it */
            .page-topbar .theme-toggle { display: none; }
        }

        /* force Bootstrap overrides in light mode */
        [data-theme="light"] .form-control,
        [data-theme="light"] .form-select {
            background-color: #ffffff !important;
            color: #111122 !important;
            border-color: #d0d0e0 !important;
        }
        [data-theme="light"] .form-control::placeholder,
        [data-theme="light"] .form-select::placeholder { color: #8888aa !important; }
        [data-theme="light"] .form-control:focus,
        [data-theme="light"] .form-select:focus {
            background-color: #ffffff !important;
            border-color: #5b49e0 !important;
            box-shadow: 0 0 0 3px rgba(91,73,224,0.15) !important;
        }
        .theme-toggle {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 8px;
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg-base);
            color: var(--text-main);
            font-family: 'Segoe UI', sans-serif;
            min-height: 100vh;
            background-attachment: fixed;
        }

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

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 220px;
            height: 100vh;
            background: var(--bg-input);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 0;
            z-index: 100;
            overflow: visible;
        }
        .sidebar-logo {
            font-size: 17px;
            font-weight: 700;
            color: var(--accent);
            padding: 20px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .sidebar-logo span { color: var(--text-main); }
        .sidebar-logo-icon { display: inline; }
        .sidebar-nav { padding: 12px 10px; flex: 1; overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; }
        .sidebar-nav::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        .sidebar-nav { scrollbar-color: var(--border) transparent; scrollbar-width: thin; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
            transition: all 0.2s;
        }
        .nav-item:hover { background: var(--border); color: var(--text-main); }
        .nav-item.active { background: rgba(124,106,247,0.15); color: #7c6af7; }
        .nav-item i { font-size: 16px; width: 20px; text-align: center; }
        /* Nav badge dots (chat unread / pending invites) */
        .nav-icon-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 20px; }
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
        .sidebar-user {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--text-muted);
            overflow: hidden;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .sidebar-user b { display: block; color: var(--text-main); margin-bottom: 4px; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
        .sidebar-user-avatar { width: 32px; height: 32px; border-radius: 50%; background: rgba(124,106,247,0.2); display: flex; align-items: center; justify-content: center; font-size: 15px; margin-bottom: 6px; flex-shrink: 0; overflow: hidden; }
        .sidebar-user-info { overflow: hidden; max-height: 80px; opacity: 1; transition: opacity 0.15s ease, max-height 0.22s ease; }
        .btn-logout {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #e05555;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(247,106,106,0.1); color: #f76a6a; }
        /* MAIN CONTENT */
        .main { margin-left: 220px; padding: 30px; background: transparent; min-height: 100vh; overflow-x: hidden; }
        .page-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--text-main); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .page-sub { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }
        /* CARDS */
        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-title-sm {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 14px;
        }
        /* STAT CARDS */
        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            text-align: center;
            height: 100%;
            box-sizing: border-box;
        }
        /* icon box inside stat-card scales down on small screens */
        .stat-card .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-val { font-size: clamp(20px, 3vw, 30px); font-weight: 700; margin-bottom: 4px; }
        .stat-label { font-size: clamp(10px, 1.2vw, 12px); color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stat-delta { font-size: 11px; margin-top: 5px; }
        /* Responsive: stack content vertically on narrow windows */
        @media (max-width: 768px) {
            .stat-card { padding: 12px 10px; }
            .stat-card .stat-icon { width: 34px; height: 34px; border-radius: 9px; }
            .stat-card .stat-icon i { font-size: 16px !important; }
            .stat-val { font-size: 22px; }
            .stat-label { font-size: 10px; }
        }
        @media (max-width: 480px) {
            .stat-card { flex-direction: column !important; align-items: center !important; text-align: center; gap: 8px !important; padding: 10px 6px; }
            .stat-card .stat-icon { margin: 0 auto; }
        }
        .up { color: #6af7b8; } .down { color: #f76a6a; }
        /* PROGRESS */
        .prog-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 4px; }
        .prog-fill { height: 100%; border-radius: 3px; }
        /* ALERTS */
        .alert-warn  { background: rgba(247,196,106,.08); border: 1px solid rgba(247,196,106,.25); color: #f7c46a; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }
        .alert-ok    { background: rgba(106,247,184,.08); border: 1px solid rgba(106,247,184,.25); color: #6af7b8; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }
        .alert-danger{ background: rgba(247,106,106,.08); border: 1px solid rgba(247,106,106,.25); color: #f76a6a; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 10px; }

        /* ── LIGHT MODE OVERRIDES for inline styles ── */
        [data-theme="light"] body { background: var(--bg-base) !important; color: var(--text-main) !important; }

        /* Cards & surfaces */
        [data-theme="light"] .card,
        [data-theme="light"] .stat-card { background: var(--bg-surface) !important; border-top-color: var(--border) !important; border-right-color: var(--border) !important; border-bottom-color: var(--border) !important; }

        /* All inline-styled inputs, selects, date pickers */
        [data-theme="light"] input[style],
        [data-theme="light"] select[style],
        [data-theme="light"] textarea[style] {
            background: #ffffff !important;
            border-color: var(--border) !important;
            color: var(--text-main) !important;
        }
        [data-theme="light"] input[type="date"] { color-scheme: light; }

        /* All inline-styled buttons (add/submit) */
        [data-theme="light"] button[style*="background:#7c6af7"],
        [data-theme="light"] button[style*="background: #7c6af7"] {
            background: var(--accent) !important;
            color: #fff !important;
        }
        [data-theme="light"] button[style*="background:#2a2a38"],
        [data-theme="light"] button[style*="background: #2a2a38"] {
            background: var(--border) !important;
            color: var(--text-main) !important;
        }

        /* Task rows & habit rows border */
        [data-theme="light"] [style*="border-bottom:1px solid #2a2a38"],
        [data-theme="light"] [style*="border-bottom: 1px solid #2a2a38"] {
            border-bottom-color: var(--border) !important;
        }

        /* Muted text (color:#7a7a95) */
        [data-theme="light"] [style*="color:#7a7a95"],
        [data-theme="light"] [style*="color: #7a7a95"] { color: var(--text-muted) !important; }

        /* Dim text (color:#3a3a52) */
        [data-theme="light"] [style*="color:#3a3a52"],
        [data-theme="light"] [style*="color: #3a3a52"] { color: var(--text-dim) !important; }

        /* Main text (color:#e8e8f0) */
        [data-theme="light"] [style*="color:#e8e8f0"],
        [data-theme="light"] [style*="color: #e8e8f0"] { color: var(--text-main) !important; }

        /* Accent text (color:#7c6af7) stays readable in light — just darken slightly */
        [data-theme="light"] [style*="color:#7c6af7"],
        [data-theme="light"] [style*="color: #7c6af7"] { color: var(--accent) !important; }

        /* Green (#6af7b8) — too light on white, darken for light mode */
        [data-theme="light"] [style*="color:#6af7b8"],
        [data-theme="light"] [style*="color: #6af7b8"] { color: #0a9e6a !important; }
        [data-theme="light"] .alert-ok,
        [data-theme="light"] .up { color: #0a9e6a !important; }

        /* Yellow (#f7c46a) — darken for light mode */
        [data-theme="light"] [style*="color:#f7c46a"],
        [data-theme="light"] [style*="color: #f7c46a"] { color: #b07800 !important; }

        /* Red (#f76a6a) — darken for light mode */
        [data-theme="light"] [style*="color:#f76a6a"],
        [data-theme="light"] [style*="color: #f76a6a"] { color: #cc2222 !important; }
        [data-theme="light"] .alert-danger,
        [data-theme="light"] .down { color: #cc2222 !important; }
        [data-theme="light"] .alert-warn { color: #b07800 !important; }

        /* Filter tab links (tasks page) */
        [data-theme="light"] a[style*="background:#1c1c26"] {
            background: var(--bg-surface) !important;
            color: var(--text-muted) !important;
            border-color: var(--border) !important;
        }

        /* Transparent bg elements (timer distraction buttons etc) */
        [data-theme="light"] button[style*="background:transparent"],
        [data-theme="light"] button[style*="background: transparent"] {
            background: var(--bg-input) !important;
            color: var(--text-muted) !important;
            border-color: var(--border) !important;
        }

        /* Sidebar full repaint */
        [data-theme="light"] .sidebar {
            background: #f0f0fa !important;
            border-right-color: var(--border) !important;
        }
        [data-theme="light"] .sidebar-logo { border-bottom-color: var(--border) !important; }
        [data-theme="light"] .sidebar-user { border-top-color: var(--border) !important; }
        [data-theme="light"] .nav-item.active { background: rgba(91,73,224,0.12) !important; color: var(--accent) !important; }

        /* Page top-bar */
        [data-theme="light"] .page-topbar { border-bottom-color: var(--border) !important; }

        /* ── PAGE TOP BAR ── */
        .page-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .page-topbar .page-title { margin-bottom: 0; }

        /* ── DARK MODE: force inline-styled inputs to show text ── */
        [data-theme="dark"] input[style*="background:#16161d"],
        [data-theme="dark"] select[style*="background:#16161d"],
        [data-theme="dark"] textarea[style*="background:#16161d"] {
            background: #16161d !important;
            color: #e8e8f0 !important;
            border-color: #2a2a38 !important;
        }
        [data-theme="dark"] input[style*="background:#16161d"]::placeholder,
        [data-theme="dark"] textarea[style*="background:#16161d"]::placeholder {
            color: #3a3a52 !important;
        }
        [data-theme="dark"] select[style*="background:#16161d"] option {
            background: #1c1c26;
            color: #e8e8f0;
        }
        /* date picker dark */
        [data-theme="dark"] input[type="date"][style] {
            color-scheme: dark;
            color: #e8e8f0 !important;
        }

        /* ── LIGHT MODE: darken neon accent colours for readability ── */
        [data-theme="light"] [style*="color:#6af7b8"],
        [data-theme="light"] [style*="color: #6af7b8"] { color: #0a8f5e !important; }

        [data-theme="light"] [style*="color:#f76a6a"],
        [data-theme="light"] [style*="color: #f76a6a"] { color: #c0392b !important; }

        [data-theme="light"] [style*="color:#7c6af7"],
        [data-theme="light"] [style*="color: #7c6af7"] { color: #5b49e0 !important; }

        [data-theme="light"] [style*="color:#f7c46a"],
        [data-theme="light"] [style*="color: #f7c46a"] { color: #b07d00 !important; }

        /* icon backgrounds — keep them but slightly more opaque */
        [data-theme="light"] [style*="background:rgba(106,247,184,0.15)"] { background: rgba(10,143,94,0.12) !important; }
        [data-theme="light"] [style*="background:rgba(247,106,106,0.15)"] { background: rgba(192,57,43,0.10) !important; }
        [data-theme="light"] [style*="background:rgba(124,106,247,0.15)"] { background: rgba(91,73,224,0.10) !important; }
        [data-theme="light"] [style*="background:rgba(247,196,106,0.15)"] { background: rgba(176,125,0,0.10) !important; }

        /* stat-val numbers */
        [data-theme="light"] .stat-val { color: var(--text-main); }
        [data-theme="light"] .stat-label { color: var(--text-muted); }

        /* border-left accent strips — keep their colour but ensure card bg is white */
        

        /* ── Sidebar toggle button ── */
        .sidebar {
            transition: width 0.22s ease;
        }
        .sidebar-toggle {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            left: calc(220px - 14px); /* updated by JS on collapse */
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
            cursor: pointer;
            z-index: 200;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .sidebar-toggle:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        /* Collapsed sidebar — icon-only mode */
        .sidebar.collapsed .sidebar-logo-text,
        .sidebar.collapsed .nav-label {
            display: none;
        }
        .sidebar.collapsed .sidebar-user-info { max-height: 0; opacity: 0; pointer-events: none; }
        .sidebar.collapsed .sidebar-user { padding: 12px 0; display: flex; flex-direction: column; align-items: center; }
        .sidebar.collapsed .sidebar-logo { justify-content: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .sidebar.collapsed .sidebar-nav { padding: 8px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed .nav-item { justify-content: center; padding: 11px 0; }
        .sidebar.collapsed .nav-item i { width: auto; }
        .sidebar.collapsed .btn-logout { justify-content: center; padding: 8px 0; width: 100%; }
        .sidebar.collapsed .btn-logout .nav-label { display: none; }
        .main { transition: margin-left 0.22s ease; }

        /* ── MOBILE RESPONSIVE ── */
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
            display: none; /* shown only inside mobile-topnav on mobile */
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 22px;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        /* Overlay behind sidebar on mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 99;
        }
        @media (max-width: 768px) {
            /* Show mobile topnav and hamburger inside it */
            .mobile-topnav    { display: flex; }
            .mobile-menu-btn  { display: block; }
            /* Hide desktop sidebar toggle button */
            .sidebar-toggle   { display: none; }

            /* Sidebar fully off-screen, slides in as overlay */
            .sidebar {
                width: 220px !important;
                transform: translateX(-100%);
                transition: transform 0.25s ease !important;
                z-index: 200;
            }
            .sidebar.mobile-open  { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }

            /* Main content full width, padded below topnav */
            .main {
                margin-left: 0 !important;
                padding: 66px 14px 20px !important;
            }

            /* Page title smaller on mobile */
            .page-title { font-size: 18px !important; }

            /* Cards full width */
            .card { padding: 14px !important; }

            /* Stat cards — 2 per row */
            .row .col-6 { flex: 0 0 50%; max-width: 50%; }

            /* Table overflow */
            table { min-width: 500px; }
            .table-wrap, .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

            /* Forms full width */
            .row .col-md-6,
            .row .col-md-4,
            .row .col-md-3 { flex: 0 0 100%; max-width: 100%; }
        }
        @media (max-width: 480px) {
            .main { padding: 62px 10px 16px !important; }
            .stat-card { padding: 10px 8px !important; }
            .page-title { font-size: 16px !important; }
        }
    </style>
<script>
function saveThemeToServer(payload) {
    fetch('save_theme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).catch(function(){ /* best-effort; localStorage still has the local copy */ });
}
function toggleTheme() {
    var html = document.documentElement;
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    // Instant swap — no per-element transitions, just update CSS variables
    html.setAttribute('data-theme', next);
    localStorage.setItem('pt_student_theme', next);
    saveThemeToServer({ theme: next });
    document.querySelectorAll('.theme-icon').forEach(function(el) {
        el.className = 'bi bi-' + (next === 'dark' ? 'moon-stars-fill' : 'sun-fill') + ' theme-icon';
    });
}
function setBgPreset(name) {
    document.documentElement.setAttribute('data-bg', name);
    localStorage.setItem('pt_student_bg', name);
    saveThemeToServer({ bg_theme: name });
    document.querySelectorAll('.bg-preset-option').forEach(function(el){
        el.classList.toggle('active', el.dataset.bg === name);
    });
}
document.addEventListener('DOMContentLoaded', function(){
    var t = document.documentElement.getAttribute('data-theme') || 'dark';
    document.querySelectorAll('.theme-icon').forEach(function(el) {
        el.className = 'bi bi-' + (t === 'dark' ? 'moon-stars-fill' : 'sun-fill') + ' theme-icon';
    });
});
</script>
    <script src="includes/tab_inject.js"></script>
</head>
<body>
<!-- Mobile top navbar (hidden on desktop) -->
<nav class="mobile-topnav">
    <button class="mobile-menu-btn" id="mobileMenuBtn" title="Open menu"><i class="bi bi-list"></i></button>
    <div class="mobile-topnav-brand">🧠 ProcraTrack</div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme"><i class="bi bi-moon-stars-fill theme-icon"></i></button>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar">
    <button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar"><i class="bi bi-chevron-left" id="sidebarToggleIcon"></i></button>
    <div class="sidebar-logo"><span class="sidebar-logo-icon">🧠</span><span class="sidebar-logo-text"> Procra<span>Track</span></span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?= ($pageTitle==='Dashboard')?'active':'' ?>"><i class="bi bi-grid-1x2"></i><span class="nav-label"> Dashboard</span></a>
        <a href="tasks.php"     class="nav-item <?= ($pageTitle==='Tasks')?'active':'' ?>"><i class="bi bi-check2-square"></i><span class="nav-label"> Tasks</span></a>
        <a href="timer.php"     class="nav-item <?= ($pageTitle==='Timer')?'active':'' ?>"><i class="bi bi-stopwatch"></i><span class="nav-label"> Focus Timer</span></a>
        <a href="habits.php"    class="nav-item <?= ($pageTitle==='Habits')?'active':'' ?>"><i class="bi bi-calendar2-check"></i><span class="nav-label"> Habits</span></a>
        <a href="insights.php"       class="nav-item <?= ($pageTitle==='Insights')?'active':'' ?>"><i class="bi bi-lightbulb"></i><span class="nav-label"> Insights</span></a>
        <a href="calendar.php"       class="nav-item <?= ($pageTitle==='Calendar')?'active':'' ?>"><i class="bi bi-calendar3"></i><span class="nav-label"> Calendar</span></a>
        <a href="notifications.php"  class="nav-item <?= ($pageTitle==='Notifications')?'active':'' ?>">
            <span class="nav-icon-wrap">
                <i class="bi bi-bell"></i>
                <?php if ($__pending_access_count > 0): ?>
                <span class="nav-dot count" style="display:flex"><?= $__pending_access_count > 9 ? '9+' : $__pending_access_count ?></span>
                <?php endif; ?>
            </span>
            <span class="nav-label"> Notifications</span>
        </a>
        <a href="chat.php"           class="nav-item <?= ($pageTitle==='Chat')?'active':'' ?>">
            <span class="nav-icon-wrap">
                <i class="bi bi-chat-dots"></i>
                <span class="nav-dot count" id="navUnreadDot"></span>
                <span class="nav-dot invite-dot count" id="navInviteDot"></span>
            </span>
            <span class="nav-label"> Chat</span>
        </a>
        <a href="settings.php"       class="nav-item <?= ($pageTitle==='Settings')?'active':'' ?>"><i class="bi bi-gear"></i><span class="nav-label"> Settings</span></a>
    </nav>
    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?php
            // Show avatar if set
            $__avatar = '';
            if (!empty($_SESSION['user_id'])) {
                try {
                    if (!isset($pdo)) require_once __DIR__ . '/../config/db.php';
                    $__av = $pdo->prepare("SELECT avatar FROM users WHERE id=?");
                    $__av->execute([$_SESSION['user_id']]);
                    $__avatar = $__av->fetchColumn();
                } catch(Exception $e) {}
            }
            if ($__avatar && file_exists(__DIR__ . '/../uploads/avatars/' . basename($__avatar))):
            ?>
                <img src="uploads/avatars/<?= htmlspecialchars(basename($__avatar)) ?>?v=<?= filemtime(__DIR__ . '/../uploads/avatars/' . basename($__avatar)) ?>"
                     alt="avatar" style="width:32px;height:32px;border-radius:50%;object-fit:cover;display:block">
            <?php else: ?>
                👤
            <?php endif; ?>
        </div>
        <div class="sidebar-user-info">
            <div style="font-size:10px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;"><?= ucfirst(htmlspecialchars($_SESSION['user_role'] ?? 'Student')) ?></div>
            <b><?= htmlspecialchars($_SESSION['user_name']) ?></b>
        </div>
        <a href="logout.php" class="btn-logout"><i class="bi bi-box-arrow-left"></i><span class="nav-label"> Logout</span></a>
    </div>
</div>
<script>
(function(){
    var STORE_KEY = 'pt_student_sidebar_collapsed';
    var EXPANDED_W = 220, COLLAPSED_W = 60;

    function applyState(collapsed, animate) {
        var sidebar = document.querySelector('.sidebar');
        var main    = document.querySelector('.main');
        var icon    = document.getElementById('sidebarToggleIcon');
        if (!sidebar || !main) return;

        // Disable transitions on initial load to prevent flash
        if (!animate) {
            sidebar.style.transition = 'none';
            main.style.transition    = 'none';
        }

        sidebar.classList.toggle('collapsed', collapsed);
        var w = collapsed ? COLLAPSED_W : EXPANDED_W;
        sidebar.style.width = w + 'px';
        var btn = document.getElementById('sidebarToggle');
        if (btn) btn.style.left = (w - 14) + 'px';  // fixed position: sits on sidebar edge
        main.style.marginLeft = (collapsed ? COLLAPSED_W : EXPANDED_W) + 'px';
        if (icon) icon.className = collapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
        localStorage.setItem(STORE_KEY, collapsed ? '1' : '0');

        // Re-enable transitions after paint
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
        // On mobile, always show sidebar expanded (never apply desktop collapsed state)
        var collapsed = window.innerWidth > 768 && localStorage.getItem(STORE_KEY) === '1';
        applyState(collapsed, false); // no animation on load

        var btn = document.getElementById('sidebarToggle');
        if (btn) btn.addEventListener('click', function() {
            var sidebar = document.querySelector('.sidebar');
            applyState(!sidebar.classList.contains('collapsed'), true); // animate on click
        });
    });
})();
</script>
<script>
// Mobile sidebar toggle
(function(){
    function isMobile(){ return window.innerWidth <= 768; }
    var menuBtn  = document.getElementById('mobileMenuBtn');
    var overlay  = document.getElementById('sidebarOverlay');
    var sidebar  = document.querySelector('.sidebar');
    function openMobile(){
        sidebar.classList.add('mobile-open');
        overlay.classList.add('active');
    }
    function closeMobile(){
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }
    if(menuBtn)  menuBtn.addEventListener('click', openMobile);
    if(overlay)  overlay.addEventListener('click', closeMobile);
    // Close sidebar when a nav link is tapped on mobile
    document.querySelectorAll('.sidebar .nav-item').forEach(function(a){
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
<div class="main">
