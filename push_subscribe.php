<?php
/**
 * push_subscribe.php
 * AJAX endpoint — called by the browser after the user grants notification permission.
 * Saves or removes the Web Push subscription for the logged-in user.
 */

require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Must be POST with JSON body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$action = $data['action'] ?? 'subscribe';   // 'subscribe' | 'unsubscribe'

$userId   = (int) $_SESSION['user_id'];
$endpoint = $data['endpoint'] ?? '';
$p256dh   = $data['keys']['p256dh'] ?? '';
$auth     = $data['keys']['auth']   ?? '';
$ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing endpoint']);
    exit;
}

try {
    if ($action === 'unsubscribe') {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?")
            ->execute([$userId, $endpoint]);
        echo json_encode(['ok' => true, 'message' => 'Unsubscribed']);
    } else {
        // Upsert — update keys if endpoint already exists for this user
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                p256dh     = VALUES(p256dh),
                auth       = VALUES(auth),
                user_agent = VALUES(user_agent),
                updated_at = NOW()
        ");
        $stmt->execute([$userId, $endpoint, $p256dh, $auth, $ua]);
        echo json_encode(['ok' => true, 'message' => 'Subscribed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
}
