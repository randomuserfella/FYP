<?php
/**
 * config/remember.php — "Keep me logged in" persistent login
 *
 * Selector/validator pattern:
 *   - selector  : looked up directly in the DB (not secret)
 *   - validator : random secret, only ever stored as a hash, compared
 *                 with hash_equals() to avoid timing attacks
 *
 * The cookie holds "selector:validator" and is completely separate from
 * the per-tab pt_<tab_id> session cookies, so it survives sessionStorage
 * being cleared, the browser closing, or the local server restarting.
 */

const REMEMBER_COOKIE = 'remember_token';
const REMEMBER_DAYS    = 30;

function remember_issue(PDO $pdo, int $userId): void {
    $selector  = bin2hex(random_bytes(12));   // 24 hex chars
    $validator = bin2hex(random_bytes(32));   // 64 hex chars
    $hash      = hash('sha256', $validator);
    $expires   = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 24 * 3600);

    $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $selector, $hash, $expires]);

    setcookie(
        REMEMBER_COOKIE,
        $selector . ':' . $validator,
        [
            'expires'  => time() + REMEMBER_DAYS * 24 * 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

/**
 * Returns the user row if a valid remember cookie is present, else null.
 * Rotates the validator on every successful use (so a stolen cookie value
 * only works once before the legitimate user's next visit invalidates it).
 */
function remember_consume(PDO $pdo): ?array {
    if (empty($_COOKIE[REMEMBER_COOKIE])) return null;

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) return null;
    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ? LIMIT 1');
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || strtotime($row['expires_at']) < time()) {
        remember_clear($pdo);
        return null;
    }

    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        // Selector matched but validator didn't — possible theft.
        // Invalidate every token for this user as a precaution.
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$row['user_id']]);
        remember_clear($pdo);
        return null;
    }

    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$row['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        remember_clear($pdo);
        return null;
    }

    // Rotate: delete the used token, issue a fresh one.
    $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
    remember_issue($pdo, (int)$user['id']);

    return $user;
}

function remember_clear(?PDO $pdo = null): void {
    if ($pdo && !empty($_COOKIE[REMEMBER_COOKIE])) {
        $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
        if (count($parts) === 2) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$parts[0]]);
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
    ]);
}
