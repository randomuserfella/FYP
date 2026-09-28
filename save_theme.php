<?php
/**
 * save_theme.php — Persists the logged-in user's theme/background choice
 * so it's tied to their account instead of the browser's localStorage
 * (which was shared across every account on the same device).
 */

require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$raw     = json_decode(file_get_contents('php://input'), true) ?? [];

$allowed_themes = ['dark', 'light'];
$allowed_bgs    = ['default', 'purple', 'ocean', 'sunset', 'forest', 'rose'];

$theme    = in_array($raw['theme'] ?? '', $allowed_themes, true) ? $raw['theme'] : null;
$bg_theme = in_array($raw['bg_theme'] ?? '', $allowed_bgs, true) ? $raw['bg_theme'] : null;

if ($theme === null && $bg_theme === null) {
    echo json_encode(['ok' => false, 'error' => 'Nothing valid to save']);
    exit;
}

try {
    if ($theme !== null && $bg_theme !== null) {
        $stmt = $pdo->prepare("UPDATE users SET theme = ?, bg_theme = ? WHERE id = ?");
        $stmt->execute([$theme, $bg_theme, $user_id]);
    } elseif ($theme !== null) {
        $stmt = $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?");
        $stmt->execute([$theme, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET bg_theme = ? WHERE id = ?");
        $stmt->execute([$bg_theme, $user_id]);
    }
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
