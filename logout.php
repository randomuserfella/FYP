<?php
require_once __DIR__ . '/config/tab_session.php';

// Revoke the persistent "keep me logged in" token, if any, so logging out
// actually logs out instead of being silently re-authenticated next visit.
if (!empty($_COOKIE['remember_token'])) {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/config/remember.php';
    remember_clear($pdo);
}

session_unset();
session_destroy();

// Kill the current tab session cookie
setcookie(session_name(), '', time() - 3600, '/');

// Also kill any leftover legacy role-based cookies from old code
foreach (['pt_student', 'pt_lecturer', 'pt_default', 'PHPSESSID'] as $old) {
    setcookie($old, '', time() - 3600, '/');
}

// Redirect to login — no tab param so tab_inject.js assigns a fresh one
header('Location: index.php');
exit;
