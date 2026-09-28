<?php
/**
 * save_remind_pref.php — saves one reminder-timing toggle instantly (AJAX),
 * so settings.php doesn't need a full page reload (which was resetting
 * scroll position back to the top every time a toggle was flipped).
 *
 * POST JSON: { "csrf_token": "...", "col": "remind_30min", "value": 1|0 }
 */

require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$raw = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF check (token arrives in the JSON body here, not $_POST)
$token = $raw['csrf_token'] ?? '';
if (!hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security check failed']);
    exit;
}

// Whitelist of columns this endpoint is allowed to touch
$allowedCols = [
    'remind_7day', 'remind_3day', 'remind_1day', 'remind_today',
    'remind_60min', 'remind_30min', 'remind_ondue', 'remind_overdue',
];

$col   = $raw['col'] ?? '';
$value = !empty($raw['value']) ? 1 : 0;

if (!in_array($col, $allowedCols, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid field']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?");
    $stmt->execute([$value, (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
