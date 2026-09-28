<?php
/**
 * push_test.php
 * Sends a test push notification to the current logged-in user.
 * Called via POST from notifications.php.
 */

require_once __DIR__ . '/config/tab_session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/push_sender.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Check they have at least one subscription
$stmt = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?");
$stmt->execute([$userId]);
if ((int)$stmt->fetchColumn() === 0) {
    echo json_encode(['ok' => false, 'error' => 'No subscription found. Enable notifications on this device first.']);
    exit;
}

// Send test push
$title = '🧪 ProcraTrack test';
$body  = 'Push notifications are working! You\'ll receive reminders like this for tasks and habits.';

$sent = sendPushToUser($pdo, $userId, $title, $body, '/notifications.php', 'test', 'test');

if ($sent > 0) {
    echo json_encode(['ok' => true, 'message' => "Test notification sent to $sent device(s)!"]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Send failed. Check that VAPID keys are set in config/push_config.php and that OpenSSL + cURL are enabled in XAMPP.']);
}
