<?php
require_once __DIR__ . '/config/tab_session.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
try { require_once __DIR__ . '/config/db.php'; } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$raw       = json_decode(file_get_contents('php://input'), true) ?? [];
$action    = $_GET['action'] ?? $raw['action'] ?? 'rooms';

try {
    // ── Ensure tables ────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(120) NOT NULL,
        type       ENUM('group','direct','class') DEFAULT 'group',
        university VARCHAR(120) DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_members (
        room_id  INT UNSIGNED NOT NULL,
        user_id  INT UNSIGNED NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id    INT UNSIGNED NOT NULL,
        user_id    INT UNSIGNED NOT NULL,
        message    TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_room (room_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Cross-institution chat invites: a pending request that must be accepted
    // before a direct-message room is created between users at different
    // institutions. Same-institution DMs skip this entirely (see create_direct).
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_invites (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id           INT UNSIGNED NOT NULL,
        sender_university   VARCHAR(120) DEFAULT NULL,
        recipient_id        INT UNSIGNED NOT NULL,
        recipient_university VARCHAR(120) DEFAULT NULL,
        status              ENUM('pending','accepted','denied') NOT NULL DEFAULT 'pending',
        message             VARCHAR(255) DEFAULT NULL,
        room_id             INT UNSIGNED DEFAULT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at        TIMESTAMP NULL DEFAULT NULL,
        KEY idx_recipient (recipient_id, status),
        KEY idx_sender (sender_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_read_at (
        room_id      INT UNSIGNED NOT NULL,
        user_id      INT UNSIGNED NOT NULL,
        last_read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 'Delete for me' — hides a message from one user's view only; the
    // message itself is untouched so everyone else still sees it normally.
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_message_hidden (
        message_id INT UNSIGNED NOT NULL,
        user_id    INT UNSIGNED NOT NULL,
        hidden_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Patch existing chat_rooms tables that were created before created_by / type='note' were added
    try {
        $pdo->exec("ALTER TABLE chat_rooms ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED DEFAULT NULL");
        $pdo->exec("ALTER TABLE chat_rooms MODIFY COLUMN type ENUM('group','direct','class','note') DEFAULT 'group'");
    } catch (Exception $e) { /* column already exists or MySQL < 8 — ignore */ }

    // 'seen' tracks whether a member has noticed being added to a room (by
    // someone else) — powers the "..." menu notification badge alongside
    // pending invites. Defaults to 1 (seen) so self-initiated memberships
    // (creating your own room, joining a class yourself) never notify.
    try {
        $pdo->exec("ALTER TABLE chat_members ADD COLUMN IF NOT EXISTS seen TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Exception $e) { /* already exists — ignore */ }

    // One-time cleanup: merge any duplicate class rooms that already exist
    // (keep the oldest per university, move members/messages over, delete the rest)
    try {
        $dupCheck = $pdo->query("
            SELECT university, MIN(id) AS keep_id, GROUP_CONCAT(id) AS all_ids, COUNT(*) AS cnt
            FROM chat_rooms WHERE type='class' AND university IS NOT NULL
            GROUP BY university HAVING cnt > 1
        ")->fetchAll();
        foreach ($dupCheck as $dup) {
            $ids = array_map('intval', explode(',', $dup['all_ids']));
            $keepId = (int)$dup['keep_id'];
            $dropIds = array_filter($ids, fn($id) => $id !== $keepId);
            if ($dropIds) {
                $placeholders = implode(',', array_fill(0, count($dropIds), '?'));
                $pdo->prepare("UPDATE IGNORE chat_messages SET room_id=? WHERE room_id IN ($placeholders)")
                    ->execute(array_merge([$keepId], $dropIds));
                $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) SELECT ?, user_id FROM chat_members WHERE room_id IN ($placeholders)")
                    ->execute(array_merge([$keepId], $dropIds));
                $pdo->prepare("DELETE FROM chat_members WHERE room_id IN ($placeholders)")->execute($dropIds);
                $pdo->prepare("DELETE FROM chat_rooms WHERE id IN ($placeholders)")->execute($dropIds);
            }
        }
    } catch (Exception $e) { /* best-effort cleanup, ignore failures */ }

    // Enforce one class room per university at the DB level so concurrent
    // requests can never race their way into creating duplicates again.
    // MySQL/MariaDB don't support partial unique indexes, so we use a
    // generated column that's only non-null for type='class' rows — this
    // way group/direct rooms (which legitimately allow multiples per
    // university) are completely unaffected by the constraint.
    try {
        $pdo->exec("ALTER TABLE chat_rooms ADD COLUMN class_uniq_key VARCHAR(150)
            GENERATED ALWAYS AS (CASE WHEN type='class' THEN university ELSE NULL END) STORED");
        $pdo->exec("ALTER TABLE chat_rooms ADD UNIQUE KEY uq_class_university (class_uniq_key)");
    } catch (Exception $e) { /* already exists — ignore */ }

    // Current user info
    $uStmt = $pdo->prepare("SELECT id, name, university, role FROM users WHERE id = ?");
    $uStmt->execute([$user_id]);
    $me = $uStmt->fetch();

    // Auto-create class room per university and add user.
    // INSERT IGNORE + re-fetch (instead of check-then-insert) means even if
    // two requests hit this at the exact same time, the unique key above
    // guarantees only one row ever survives — the "losing" request just
    // re-selects the row the other one created.
    if ($me['university']) {
        $pdo->prepare("INSERT IGNORE INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
            ->execute([$me['university'].' — Class Chat','class',$me['university'],$user_id]);
        $rStmt = $pdo->prepare("SELECT id FROM chat_rooms WHERE university=? AND type='class' LIMIT 1");
        $rStmt->execute([$me['university']]);
        $classRoomId = (int)$rStmt->fetchColumn();
        // Ensure user is a member of class room
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")
            ->execute([$classRoomId, $user_id]);
    }

    // ── ROOMS ────────────────────────────────────────────────────────────────
    if ($action === 'rooms') {
        $rooms = $pdo->prepare("
            SELECT r.id, r.type, r.created_by,
                CASE
                    WHEN r.type = 'direct' THEN (
                        SELECT u.name FROM chat_members cm
                        JOIN users u ON u.id = cm.user_id
                        WHERE cm.room_id = r.id AND cm.user_id != ?
                        LIMIT 1
                    )
                    ELSE r.name
                END AS name,
                CASE
                    WHEN r.type = 'direct' THEN (
                        SELECT u.role FROM chat_members cm
                        JOIN users u ON u.id = cm.user_id
                        WHERE cm.room_id = r.id AND cm.user_id != ?
                        LIMIT 1
                    )
                    ELSE NULL
                END AS other_role,
                CASE
                    WHEN r.type = 'direct' THEN (
                        SELECT u.avatar FROM chat_members cm
                        JOIN users u ON u.id = cm.user_id
                        WHERE cm.room_id = r.id AND cm.user_id != ?
                        LIMIT 1
                    )
                    ELSE NULL
                END AS other_avatar,
                (SELECT m.message FROM chat_messages m WHERE m.room_id = r.id ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
                (SELECT m.created_at FROM chat_messages m WHERE m.room_id = r.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM chat_messages m3
                    LEFT JOIN chat_read_at ra3 ON ra3.room_id = r.id AND ra3.user_id = ?
                    WHERE m3.room_id = r.id AND m3.user_id != ?
                    AND m3.created_at > COALESCE(ra3.last_read_at, cm.joined_at)
                ) AS unread_count,
                cm.joined_at,
                (NOT EXISTS(SELECT 1 FROM chat_read_at ra5 WHERE ra5.room_id = r.id AND ra5.user_id = ?)) AS is_new
            FROM chat_rooms r
            JOIN chat_members cm ON cm.room_id = r.id AND cm.user_id = ?
            ORDER BY last_at DESC, r.created_at DESC
        ");
        $rooms->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
        echo json_encode(['ok'=>true,'rooms'=>$rooms->fetchAll()]); exit;
    }

    // ── MESSAGES ─────────────────────────────────────────────────────────────
    if ($action === 'messages') {
        $room_id = (int)($raw['room_id'] ?? $_GET['room_id'] ?? 0);
        $after   = $raw['after'] ?? $_GET['after'] ?? null;
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'No room']); exit; }
        // Check membership
        $mem = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
        $mem->execute([$room_id,$user_id]);
        if (!$mem->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not a member']); exit; }

        // Mark this room as read up to now (powers the nav-badge unread count)
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_read_at (
            room_id      INT UNSIGNED NOT NULL,
            user_id      INT UNSIGNED NOT NULL,
            last_read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO chat_read_at (room_id,user_id,last_read_at) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()")->execute([$room_id, $user_id]);

        $sql = "SELECT m.id, m.message, m.created_at, u.name AS sender, u.role AS sender_role, u.id AS sender_id, u.avatar AS sender_avatar
                FROM chat_messages m JOIN users u ON u.id=m.user_id
                LEFT JOIN chat_message_hidden h ON h.message_id=m.id AND h.user_id=?
                WHERE m.room_id=? AND h.message_id IS NULL";
        $params = [$user_id, $room_id];
        if ($after) { $sql .= " AND m.id>?"; $params[] = (int)$after; }
        $sql .= " ORDER BY m.created_at ASC LIMIT 100";
        $msgs = $pdo->prepare($sql); $msgs->execute($params);

        // Room info + members
        $ri = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM chat_members WHERE room_id=r.id) AS member_count FROM chat_rooms r WHERE r.id=?");
        $ri->execute([$room_id]);
        $room = $ri->fetch();

        $ml = $pdo->prepare("SELECT u.id, u.name, u.role, u.university, u.avatar FROM chat_members cm JOIN users u ON u.id=cm.user_id WHERE cm.room_id=?");
        $ml->execute([$room_id]);

        echo json_encode(['ok'=>true,'messages'=>$msgs->fetchAll(),'room'=>$room,'members'=>$ml->fetchAll(),
                          'me'=>$user_id,'my_name'=>$me['name'],'my_role'=>$user_role]); exit;
    }

    // ── SEND ─────────────────────────────────────────────────────────────────
    if ($action === 'send') {
        $room_id = (int)($raw['room_id'] ?? 0);
        $message = trim($raw['message'] ?? '');
        if (!$room_id || !$message) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
        $mem = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
        $mem->execute([$room_id,$user_id]);
        if (!$mem->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not a member']); exit; }
        $pdo->prepare("INSERT INTO chat_messages (room_id,user_id,message) VALUES (?,?,?)")
            ->execute([$room_id,$user_id,$message]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]); exit;
    }

    // ── CREATE GROUP ─────────────────────────────────────────────────────────
    if ($action === 'create_group') {
        $name    = trim($raw['name'] ?? '');
        $members = $raw['members'] ?? []; // array of user ids
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Group name required']); exit; }
        $pdo->prepare("INSERT INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
            ->execute([$name,'group',$me['university'],$user_id]);
        $rid = (int)$pdo->lastInsertId();
        // Add creator
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$rid,$user_id]);
        // Add selected members
        foreach ($members as $mid) {
            $mid = (int)$mid;
            if ($mid && $mid !== $user_id)
                $pdo->prepare("INSERT INTO chat_members (room_id,user_id,seen) VALUES (?,?,0)
                    ON DUPLICATE KEY UPDATE seen=0")->execute([$rid,$mid]);
        }
        echo json_encode(['ok'=>true,'room_id'=>$rid]); exit;
    }

    // ── CREATE DIRECT ─────────────────────────────────────────────────────────
    if ($action === 'create_direct') {
        $other_id = (int)($raw['other_id'] ?? 0);
        if (!$other_id || $other_id === $user_id) { echo json_encode(['ok'=>false,'error'=>'Invalid user']); exit; }

        // Check if DM already exists
        $existing = $pdo->prepare("
            SELECT r.id FROM chat_rooms r
            JOIN chat_members a ON a.room_id=r.id AND a.user_id=?
            JOIN chat_members b ON b.room_id=r.id AND b.user_id=?
            WHERE r.type='direct'
            AND (SELECT COUNT(*) FROM chat_members WHERE room_id=r.id)=2
            LIMIT 1
        ");
        $existing->execute([$user_id,$other_id]);
        $row = $existing->fetch();
        if ($row) { echo json_encode(['ok'=>true,'room_id'=>(int)$row['id'],'existing'=>true]); exit; }

        $other = $pdo->prepare("SELECT name, university FROM users WHERE id=?"); $other->execute([$other_id]);
        $otherUser = $other->fetch();
        if (!$otherUser) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }
        $otherName = $otherUser['name'];
        $otherUniv = $otherUser['university'];
        $myUniv    = $me['university'] ?? '';

        // Cross-institution: gate behind an invite instead of creating the room
        // immediately. Applies to everyone (students and lecturers).
        $isCrossInstitution = $myUniv && $otherUniv && $myUniv !== $otherUniv;
        if ($isCrossInstitution) {
            // Don't spam duplicate pending invites between the same pair
            $dupe = $pdo->prepare("SELECT id, status FROM chat_invites
                WHERE sender_id=? AND recipient_id=? AND status='pending' LIMIT 1");
            $dupe->execute([$user_id, $other_id]);
            if ($dupe->fetch()) { echo json_encode(['ok'=>true,'invite_sent'=>true,'already_pending'=>true]); exit; }

            $msg = trim($raw['message'] ?? '');
            if (mb_strlen($msg) > 255) { $msg = mb_substr($msg, 0, 255); }
            $pdo->prepare("INSERT INTO chat_invites
                (sender_id, sender_university, recipient_id, recipient_university, message)
                VALUES (?,?,?,?,?)")
                ->execute([$user_id, $myUniv, $other_id, $otherUniv, $msg ?: null]);
            echo json_encode(['ok'=>true,'invite_sent'=>true,'invite_id'=>(int)$pdo->lastInsertId()]); exit;
        }

        // Same institution (or one side has no university on file): instant room, as before
        $pdo->prepare("INSERT INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
            ->execute([$otherName,'direct',$myUniv,$user_id]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$rid,$user_id]);
        $pdo->prepare("INSERT INTO chat_members (room_id,user_id,seen) VALUES (?,?,0)
            ON DUPLICATE KEY UPDATE seen=0")->execute([$rid,$other_id]);
        echo json_encode(['ok'=>true,'room_id'=>$rid,'existing'=>false]); exit;
    }

    // ── LIST MY INVITES (received) ──────────────────────────────────────────
    if ($action === 'list_invites') {
        $inv = $pdo->prepare("
            SELECT i.id, i.message, i.created_at, i.sender_university,
                   u.id AS sender_id, u.name AS sender_name, u.role AS sender_role
            FROM chat_invites i
            JOIN users u ON u.id = i.sender_id
            WHERE i.recipient_id = ? AND i.status = 'pending'
            ORDER BY i.created_at DESC
        ");
        $inv->execute([$user_id]);
        echo json_encode(['ok'=>true,'invites'=>$inv->fetchAll()]); exit;
    }

    // ── RESPOND TO INVITE (accept / deny) ───────────────────────────────────
    if ($action === 'respond_invite') {
        $invite_id = (int)($raw['invite_id'] ?? 0);
        $decision  = $raw['decision'] ?? '';
        if (!$invite_id || !in_array($decision, ['accept','deny'], true)) {
            echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit;
        }
        $iv = $pdo->prepare("SELECT * FROM chat_invites WHERE id=? AND recipient_id=? AND status='pending'");
        $iv->execute([$invite_id, $user_id]);
        $invite = $iv->fetch();
        if (!$invite) { echo json_encode(['ok'=>false,'error'=>'Invite not found or already handled']); exit; }

        if ($decision === 'deny') {
            $pdo->prepare("UPDATE chat_invites SET status='denied', responded_at=NOW() WHERE id=?")->execute([$invite_id]);
            echo json_encode(['ok'=>true,'status'=>'denied']); exit;
        }

        // Accept: create the room immediately (or reuse one if it somehow already exists)
        $sender_id = (int)$invite['sender_id'];
        $existing = $pdo->prepare("
            SELECT r.id FROM chat_rooms r
            JOIN chat_members a ON a.room_id=r.id AND a.user_id=?
            JOIN chat_members b ON b.room_id=r.id AND b.user_id=?
            WHERE r.type='direct'
            AND (SELECT COUNT(*) FROM chat_members WHERE room_id=r.id)=2
            LIMIT 1
        ");
        $existing->execute([$sender_id, $user_id]);
        $row = $existing->fetch();
        if ($row) {
            $rid = (int)$row['id'];
        } else {
            $pdo->prepare("INSERT INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
                ->execute([$me['name'], 'direct', $invite['sender_university'], $sender_id]);
            $rid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO chat_members (room_id,user_id,seen) VALUES (?,?,0)
                ON DUPLICATE KEY UPDATE seen=0")->execute([$rid,$sender_id]);
            $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$rid,$user_id]);
        }
        $pdo->prepare("UPDATE chat_invites SET status='accepted', responded_at=NOW(), room_id=? WHERE id=?")
            ->execute([$rid, $invite_id]);
        echo json_encode(['ok'=>true,'status'=>'accepted','room_id'=>$rid]); exit;
    }

    // ── PENDING INVITE COUNT (for sidebar badge polling) ────────────────────
    if ($action === 'invite_count') {
        $c = $pdo->prepare("SELECT COUNT(*) FROM chat_invites WHERE recipient_id=? AND status='pending'");
        $c->execute([$user_id]);
        echo json_encode(['ok'=>true,'count'=>(int)$c->fetchColumn()]); exit;
    }

    // ── NAV BADGES (unread messages + pending invites, for side-menu dots) ──
    if ($action === 'nav_badges') {
        // Self-sufficient: creates its own read-tracking table if one
        // doesn't already exist, so this works standalone either way.
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_read_at (
            room_id      INT UNSIGNED NOT NULL,
            user_id      INT UNSIGNED NOT NULL,
            last_read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (room_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Unread = messages in my rooms, sent by someone else, after my last_read_at
        // for that room (or after I joined, if I've never opened it).
        $unreadStmt = $pdo->prepare("
            SELECT COUNT(*) FROM chat_messages m
            JOIN chat_members cm ON cm.room_id = m.room_id AND cm.user_id = ?
            LEFT JOIN chat_read_at ra ON ra.room_id = m.room_id AND ra.user_id = ?
            WHERE m.user_id != ?
            AND m.created_at > COALESCE(ra.last_read_at, cm.joined_at)
        ");
        $unreadStmt->execute([$user_id, $user_id, $user_id]);
        $unread = (int)$unreadStmt->fetchColumn();

        $inviteStmt = $pdo->prepare("SELECT COUNT(*) FROM chat_invites WHERE recipient_id=? AND status='pending'");
        $inviteStmt->execute([$user_id]);
        $invites = (int)$inviteStmt->fetchColumn();

        echo json_encode(['ok'=>true,'unread'=>$unread,'invites'=>$invites]); exit;
    }

    // ── CREATE NOTE (private self-chat) ─────────────────────────────────────
    if ($action === 'create_note') {
        // Find existing note room for this user
        $existing = $pdo->prepare("
            SELECT r.id FROM chat_rooms r
            JOIN chat_members cm ON cm.room_id = r.id AND cm.user_id = ?
            WHERE r.type = 'note' AND r.created_by = ?
            LIMIT 1
        ");
        $existing->execute([$user_id, $user_id]);
        $row = $existing->fetch();
        if ($row) { echo json_encode(['ok'=>true,'room_id'=>(int)$row['id'],'existing'=>true]); exit; }

        $pdo->prepare("INSERT INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
            ->execute([$me['name']." — My Notes", 'note', $me['university'], $user_id]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$rid, $user_id]);
        echo json_encode(['ok'=>true,'room_id'=>$rid,'existing'=>false]); exit;
    }

    // ── RECOMMENDED USERS (same university, most active in DMs) ─────────────
    if ($action === 'recommended_users') {
        $my_univ = $me['university'] ?? '';
        $sql = "
            SELECT u.id, u.name, u.role, u.university,
                   COUNT(m.id) AS msg_count
            FROM users u
            LEFT JOIN chat_members cm ON cm.user_id = u.id
            LEFT JOIN chat_rooms r    ON r.id = cm.room_id AND r.type IN ('direct','group')
            LEFT JOIN chat_messages m ON m.room_id = r.id
            WHERE u.id != ?
        ";
        $params = [$user_id];
        if ($my_univ) {
            $sql     .= " AND u.university = ?";
            $params[] = $my_univ;
        }
        $sql .= " GROUP BY u.id ORDER BY msg_count DESC, u.name LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok'=>true,'users'=>$stmt->fetchAll()]); exit;
    }

    // ── CLEAR MESSAGES ───────────────────────────────────────────────────────
    if ($action === 'clear_messages') {
        $room_id = (int)($raw['room_id'] ?? 0);
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'Missing room_id']); exit; }
        // Only room owner or lecturer may clear
        $room = $pdo->prepare("SELECT created_by, type FROM chat_rooms WHERE id=?");
        $room->execute([$room_id]);
        $r = $room->fetch();
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }
        $is_member = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
        $is_member->execute([$room_id, $user_id]);
        if (!$is_member->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not authorised']); exit; }
        if ($r['created_by'] != $user_id && $me['role'] !== 'lecturer') {
            echo json_encode(['ok'=>false,'error'=>'Only the room owner or a lecturer can clear messages']); exit;
        }
        $pdo->prepare("DELETE FROM chat_message_hidden WHERE message_id IN (SELECT id FROM chat_messages WHERE room_id=?)")->execute([$room_id]);
        $pdo->prepare("DELETE FROM chat_messages WHERE room_id=?")->execute([$room_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── ADD MEMBER ───────────────────────────────────────────────────────────
    if ($action === 'add_member') {
        $room_id  = (int)($raw['room_id'] ?? 0);
        $add_id   = (int)($raw['user_id'] ?? 0);
        // Only creator or lecturer can add
        $r = $pdo->prepare("SELECT created_by,type FROM chat_rooms WHERE id=?"); $r->execute([$room_id]);
        $room = $r->fetch();
        if (!$room || ($room['created_by'] != $user_id && $user_role !== 'lecturer'))
            { echo json_encode(['ok'=>false,'error'=>'Not authorized']); exit; }
        if ($room['type'] === 'direct') { echo json_encode(['ok'=>false,'error'=>'Cannot add to DM']); exit; }
        $pdo->prepare("INSERT INTO chat_members (room_id,user_id,seen) VALUES (?,?,0)
            ON DUPLICATE KEY UPDATE seen=0")->execute([$room_id,$add_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── REMOVE MEMBER ────────────────────────────────────────────────────────
    if ($action === 'remove_member') {
        $room_id = (int)($raw['room_id'] ?? 0);
        $rem_id  = (int)($raw['user_id'] ?? 0);
        $r = $pdo->prepare("SELECT created_by,type FROM chat_rooms WHERE id=?"); $r->execute([$room_id]);
        $room = $r->fetch();
        if (!$room || ($room['created_by'] != $user_id && $user_role !== 'lecturer'))
            { echo json_encode(['ok'=>false,'error'=>'Not authorized']); exit; }
        if ($rem_id == $room['created_by']) { echo json_encode(['ok'=>false,'error'=>'Cannot remove creator']); exit; }
        $pdo->prepare("DELETE FROM chat_members WHERE room_id=? AND user_id=?")->execute([$room_id,$rem_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── DELETE ROOM (DM, group, or class) ───────────────────────────────────
    if ($action === 'delete_room') {
        $room_id = (int)($raw['room_id'] ?? 0);
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'Missing room_id']); exit; }
        $r = $pdo->prepare("SELECT created_by, type FROM chat_rooms WHERE id=?");
        $r->execute([$room_id]);
        $room = $r->fetch();
        if (!$room) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }
        $is_member = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
        $is_member->execute([$room_id, $user_id]);
        if (!$is_member->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not authorised']); exit; }

        // DMs and personal notes: any member/owner may delete.
        // Group / class rooms: only the creator or a lecturer may delete.
        if (!in_array($room['type'], ['direct','note'], true)) {
            if ($room['created_by'] != $user_id && $user_role !== 'lecturer') {
                echo json_encode(['ok'=>false,'error'=>'Only the room creator or a lecturer can delete this room']); exit;
            }
        }

        $pdo->prepare("DELETE FROM chat_message_hidden WHERE message_id IN (SELECT id FROM chat_messages WHERE room_id=?)")->execute([$room_id]);
        $pdo->prepare("DELETE FROM chat_messages WHERE room_id=?")->execute([$room_id]);
        $pdo->prepare("DELETE FROM chat_members WHERE room_id=?")->execute([$room_id]);
        $pdo->prepare("DELETE FROM chat_read_at WHERE room_id=?")->execute([$room_id]);
        $pdo->prepare("DELETE FROM chat_rooms WHERE id=?")->execute([$room_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── DELETE MESSAGES (select-mode bulk delete) ───────────────────────────
    // scope='me'       -> hide the selected messages from my view only (anyone can do this to any message)
    // scope='everyone' -> actually remove them, but ONLY messages the requester sent themselves —
    //                     no moderator override, so a lecturer can't remove a student's message or vice versa
    if ($action === 'delete_messages') {
        $room_id = (int)($raw['room_id'] ?? 0);
        $ids     = $raw['ids'] ?? [];
        $scope   = ($raw['scope'] ?? 'everyone') === 'me' ? 'me' : 'everyone';
        if (!$room_id || !is_array($ids)) { echo json_encode(['ok'=>false,'error'=>'Nothing to delete']); exit; }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!count($ids)) { echo json_encode(['ok'=>false,'error'=>'Nothing to delete']); exit; }

        $is_member = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
        $is_member->execute([$room_id, $user_id]);
        if (!$is_member->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not authorised']); exit; }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($scope === 'me') {
            // Delete for me: just hide them from my own view, regardless of sender.
            $verify = $pdo->prepare("SELECT id FROM chat_messages WHERE room_id=? AND id IN ($placeholders)");
            $verify->execute(array_merge([$room_id], $ids));
            $validIds = $verify->fetchAll(PDO::FETCH_COLUMN);
            $ins = $pdo->prepare("INSERT IGNORE INTO chat_message_hidden (message_id,user_id) VALUES (?,?)");
            foreach ($validIds as $mid) { $ins->execute([$mid, $user_id]); }
            echo json_encode(['ok'=>true,'scope'=>'me']); exit;
        }

        // scope === 'everyone' — only the sender of a message may remove it for
        // everyone. No moderator override: a lecturer can't delete a student's
        // message and vice versa, even if they own/manage the room.
        $sql    = "DELETE FROM chat_messages WHERE room_id=? AND user_id=? AND id IN ($placeholders)";
        $params = array_merge([$room_id, $user_id], $ids);
        $pdo->prepare($sql)->execute($params);
        $pdo->prepare("DELETE FROM chat_message_hidden WHERE message_id IN ($placeholders)")->execute($ids);
        echo json_encode(['ok'=>true,'scope'=>'everyone']); exit;
    }

    // ── LEAVE ROOM (class / group) ───────────────────────────────────────────
    if ($action === 'leave_room') {
        $room_id = (int)($raw['room_id'] ?? 0);
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'Missing room_id']); exit; }
        $r = $pdo->prepare("SELECT type FROM chat_rooms WHERE id=?"); $r->execute([$room_id]);
        $room = $r->fetch();
        if (!$room) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }
        if (!in_array($room['type'], ['class','group'], true)) {
            echo json_encode(['ok'=>false,'error'=>'You cannot leave this conversation']); exit;
        }
        $pdo->prepare("DELETE FROM chat_members WHERE room_id=? AND user_id=?")->execute([$room_id, $user_id]);
        $pdo->prepare("DELETE FROM chat_read_at WHERE room_id=? AND user_id=?")->execute([$room_id, $user_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── JOIN CLASS (from class browser) ──────────────────────────────────────
    if ($action === 'join_class') {
        $room_id = (int)($raw['room_id'] ?? 0);
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'Missing room_id']); exit; }
        $r = $pdo->prepare("SELECT type FROM chat_rooms WHERE id=?"); $r->execute([$room_id]);
        $room = $r->fetch();
        if (!$room || $room['type'] !== 'class') { echo json_encode(['ok'=>false,'error'=>'Not a class room']); exit; }
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$room_id, $user_id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── LIST CLASS ROOMS (class browser) ─────────────────────────────────────
    if ($action === 'list_class_rooms') {
        $rows = $pdo->prepare("
            SELECT r.id, r.name, r.university,
                (SELECT COUNT(*) FROM chat_members cm2 WHERE cm2.room_id=r.id) AS member_count,
                EXISTS(SELECT 1 FROM chat_members cm3 WHERE cm3.room_id=r.id AND cm3.user_id=?) AS is_member
            FROM chat_rooms r
            WHERE r.type='class'
            ORDER BY r.name
        ");
        $rows->execute([$user_id]);
        echo json_encode(['ok'=>true,'rooms'=>$rows->fetchAll()]); exit;
    }

    // ── CREATE CLASS (lecturer only) ─────────────────────────────────────────
    if ($action === 'create_class') {
        if ($user_role !== 'lecturer') { echo json_encode(['ok'=>false,'error'=>'Only lecturers can create class rooms']); exit; }
        $name    = trim($raw['name'] ?? '');
        $univ    = trim($raw['university'] ?? '') ?: $me['university'];
        $members = $raw['members'] ?? [];
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Class name required']); exit; }
        try {
            $pdo->prepare("INSERT INTO chat_rooms (name,type,university,created_by) VALUES (?,?,?,?)")
                ->execute([$name, 'class', $univ, $user_id]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'error'=>'A class room for this institution already exists']); exit;
        }
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO chat_members (room_id,user_id) VALUES (?,?)")->execute([$rid, $user_id]);
        foreach ($members as $mid) {
            $mid = (int)$mid;
            if ($mid && $mid !== $user_id)
                $pdo->prepare("INSERT INTO chat_members (room_id,user_id,seen) VALUES (?,?,0)
                    ON DUPLICATE KEY UPDATE seen=0")->execute([$rid, $mid]);
        }
        echo json_encode(['ok'=>true,'room_id'=>$rid]); exit;
    }

    // ── LIST USERS (for DM / add member picker) ──────────────────────────────
    if ($action === 'users') {
        $room_id    = (int)($_GET['room_id'] ?? 0);
        $search     = trim($_GET['q'] ?? '');
        $filter_univ = trim($_GET['univ'] ?? '');  // specific institution chosen from picker

        // Cross-institution mode: only allowed when a specific institution has
        // been selected from the picker (univ param set), OR a name query of 3+
        // chars is active. Prevents browsing the full user base with an empty query.
        $cross = !empty($_GET['cross']) && ($filter_univ !== '' || mb_strlen($search) >= 3);

        $my_univ     = $me['university'] ?? '';
        $base_sql    = "SELECT u.id, u.name, u.role, u.university FROM users u WHERE u.id != ?";
        $base_params = [$user_id];

        if ($cross && $filter_univ !== '') {
            // Filter to the specific institution the user picked in the picker
            $base_sql     .= " AND u.university = ?";
            $base_params[] = $filter_univ;
        } elseif (!$cross && $my_univ) {
            // Default: everyone (students and lecturers) see own university only
            $base_sql     .= " AND u.university = ?";
            $base_params[] = $my_univ;
        }
        // If cross=1 with no univ filter (name search mode), no university WHERE
        // clause is added — name-based fuzzy search runs across all institutions.

        if ($room_id) {
            $base_sql     .= " AND u.id NOT IN (SELECT user_id FROM chat_members WHERE room_id=?)";
            $base_params[] = $room_id;
        }

        if ($search === '') {
            $us = $pdo->prepare($base_sql . " ORDER BY FIELD(u.role,'lecturer','student'), u.name LIMIT 100");
            $us->execute($base_params);
            echo json_encode(['ok' => true, 'users' => $us->fetchAll()]); exit;
        }

        // Fuzzy search: prefix > substring > per-word > soundex
        $results = [];
        $seen    = [];

        // 1. Name starts with query
        $s = $pdo->prepare($base_sql . " AND u.name LIKE ? ORDER BY u.name LIMIT 20");
        $s->execute(array_merge($base_params, [$search . '%']));
        foreach ($s->fetchAll() as $r) { $seen[$r['id']] = true; $results[] = $r; }

        // 2. Name contains query anywhere
        $s = $pdo->prepare($base_sql . " AND u.name LIKE ? ORDER BY u.name LIMIT 20");
        $s->execute(array_merge($base_params, ['%' . $search . '%']));
        foreach ($s->fetchAll() as $r) {
            if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; }
        }

        // 3. Any word in the name matches any word in the query
        foreach (preg_split('/\s+/', $search) as $w) {
            if (strlen($w) < 2) continue;
            $s = $pdo->prepare($base_sql . " AND u.name LIKE ? ORDER BY u.name LIMIT 20");
            $s->execute(array_merge($base_params, ['%' . $w . '%']));
            foreach ($s->fetchAll() as $r) {
                if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; }
            }
        }

        // 4. Soundex — catches "James" when typing "Jim", "John" when typing "Jon", etc.
        $s = $pdo->prepare($base_sql . " AND SOUNDEX(u.name) LIKE CONCAT(SOUNDEX(?), '%') ORDER BY u.name LIMIT 10");
        $s->execute(array_merge($base_params, [$search]));
        foreach ($s->fetchAll() as $r) {
            if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; }
        }

        usort($results, function($a, $b) {
            if ($a['role'] !== $b['role']) return $a['role'] === 'lecturer' ? -1 : 1;
            return strcmp($a['name'], $b['name']);
        });

        echo json_encode(['ok' => true, 'users' => array_values($results)]); exit;
    }

    // ── ROOM MEMBERS (for members panel) ────────────────────────────────────
    if ($action === 'room_members') {
        $room_id = (int)($_GET['room_id'] ?? 0);
        if (!$room_id) { echo json_encode(['ok'=>false,'error'=>'No room']); exit; }
        $ml = $pdo->prepare("
            SELECT u.id, u.name, u.role, u.university,
                   r.created_by,
                   (cm.user_id = r.created_by) AS is_creator
            FROM chat_members cm
            JOIN users u ON u.id = cm.user_id
            JOIN chat_rooms r ON r.id = cm.room_id
            WHERE cm.room_id = ?
            ORDER BY FIELD(u.role,'lecturer','student'), u.name
        ");
        $ml->execute([$room_id]);
        $room = $pdo->prepare("SELECT * FROM chat_rooms WHERE id=?"); $room->execute([$room_id]);
        echo json_encode(['ok'=>true,'members'=>$ml->fetchAll(),'room'=>$room->fetch(),'me'=>$user_id,'my_role'=>$user_role]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
