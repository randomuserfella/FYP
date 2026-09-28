<?php
/**
 * tab_session.php — per-tab session isolation
 *
 * THE KEY FIX: removed the 'pt_student' fallback.
 * Previously if ?tab= was missing, PHP opened session 'pt_student'
 * which is shared across all tabs → wrong role on refresh.
 *
 * Now: no tab_id = no real session. A blank throwaway is opened so
 * $_SESSION exists but holds nothing. JS will redirect with ?tab=
 * within milliseconds and the real session opens on the next request.
 */

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure',    '1');
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite',  'Lax');
}

$tab_id = '';

if (!empty($_GET['tab'])  && preg_match('/^[a-z0-9]{8,32}$/', $_GET['tab'])) {
    $tab_id = $_GET['tab'];
} elseif (!empty($_POST['tab']) && preg_match('/^[a-z0-9]{8,32}$/', $_POST['tab'])) {
    $tab_id = $_POST['tab'];
}

$GLOBALS['tab_id'] = $tab_id;

if ($tab_id) {
    $cookieName = 'pt_' . $tab_id;
    session_name($cookieName);
    $incomingCookie = $_COOKIE[$cookieName] ?? '(none sent)';
    session_start();
    $resultingId = session_id();

    // Fresh tab (new sessionStorage tab_id => brand new session, no user_id
    // yet) but a valid "keep me logged in" cookie exists: re-authenticate
    // without asking for a password again.
    if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/remember.php';
        $rememberedUser = remember_consume($pdo);
        if ($rememberedUser) {
            $_SESSION['user_id']   = $rememberedUser['id'];
            $_SESSION['user_name'] = $rememberedUser['name'];
            $_SESSION['user_role'] = $rememberedUser['role'] ?? 'student';
        }
    }
    // Debug logging disabled for demo
    // file_put_contents(
    //     __DIR__ . '/../debug_session.log',
    //     date('H:i:s.') . substr(microtime(), 2, 3) . " | URI=" . $_SERVER['REQUEST_URI']
    //     . " | method=" . $_SERVER['REQUEST_METHOD']
    //     . " | cookieName={$cookieName}"
    //     . " | incomingCookieVal={$incomingCookie}"
    //     . " | resultingSessionId={$resultingId}"
    //     . " | user_id=" . ($_SESSION['user_id'] ?? '(unset)')
    //     . "\n",
    //     FILE_APPEND
    // );
} else {
    // No tab ID yet — JS will redirect with one in <100ms.
    // Open a throwaway in-memory session so $_SESSION doesn't crash pages.
    ini_set('session.use_cookies',      '0');
    ini_set('session.use_only_cookies', '0');
    session_name('pt_tmp');
    session_start();
    $_SESSION = []; // wipe — nothing should read this
    // Debug logging disabled for demo
    // file_put_contents(
    //     __DIR__ . '/../debug_session.log',
    //     date('H:i:s.') . substr(microtime(), 2, 3) . " | URI=" . $_SERVER['REQUEST_URI']
    //     . " | method=" . $_SERVER['REQUEST_METHOD']
    //     . " | NO TAB ID — throwaway session opened\n",
    //     FILE_APPEND
    // );
}

function tab_url(string $url, string $tab_id): string {
    if (!$tab_id) return $url;
    if (preg_match('/[?&]tab=/', $url)) return $url;
    $sep = strpos($url, '?') === false ? '?' : '&';
    return $url . $sep . 'tab=' . urlencode($tab_id);
}
