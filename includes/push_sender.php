<?php
/**
 * includes/push_sender.php
 * Helper functions for sending push notifications.
 * Used by cron_push.php and push_test.php.
 *
 * Uses the minishlink/web-push library if installed via Composer,
 * otherwise uses our built-in WebPush class.
 */

require_once __DIR__ . '/../config/push_config.php';
require_once __DIR__ . '/WebPush.php';

/**
 * Send a push notification to a single subscription row from the DB.
 *
 * @param array  $sub     Row from push_subscriptions table
 * @param string $title   Notification title
 * @param string $body    Notification body text
 * @param string $url     URL to open when notification is clicked
 * @param string $tag     Notification tag (deduplication key)
 * @return array          ['success'=>bool, 'code'=>int, 'error'=>string|null]
 */
function sendPush(array $sub, string $title, string $body, string $url = '/tasks.php', string $tag = 'procratrack'): array
{
    $pusher = new WebPush(VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT);

    $payload = [
        'title' => $title,
        'body'  => $body,
        'icon'  => PUSH_ICON,
        'badge' => PUSH_BADGE,
        'url'   => $url,
        'tag'   => $tag,
    ];

    $subscription = [
        'endpoint' => $sub['endpoint'],
        'p256dh'   => $sub['p256dh'],
        'auth'     => $sub['auth'],
    ];

    return $pusher->send($subscription, $payload);
}

/**
 * Log a push notification attempt to the push_log table.
 */
function logPush(PDO $pdo, int $userId, string $type, string $title, string $body, string $status): void
{
    try {
        $pdo->prepare("
            INSERT INTO push_log (user_id, type, title, body, status)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$userId, $type, $title, $body, $status]);
    } catch (PDOException $e) {
        // Non-fatal — just log to PHP error log
        error_log('ProcraTrack push_log error: ' . $e->getMessage());
    }
}

/**
 * Send to ALL subscriptions for a given user.
 * Returns count of successful sends.
 */
function sendPushToUser(PDO $pdo, int $userId, string $title, string $body, string $url = '/tasks.php', string $tag = 'procratrack', string $type = 'general'): int
{
    $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $subs = $stmt->fetchAll();

    $sent = 0;
    foreach ($subs as $sub) {
        $result = sendPush($sub, $title, $body, $url, $tag);
        $status = $result['success'] ? 'sent' : 'failed';
        logPush($pdo, $userId, $type, $title, $body, $status);

        if ($result['success']) {
            $sent++;
        } elseif (in_array($result['code'], [404, 410])) {
            // Subscription expired — remove it
            $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")
                ->execute([$sub['id']]);
        }
    }
    return $sent;
}
