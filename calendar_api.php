<?php
require_once __DIR__ . '/config/tab_session.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
$user_id = (int)$_SESSION['user_id'];

try {
    require_once __DIR__ . '/config/db.php';
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'DB connection failed: '.$e->getMessage()]); exit;
}

$raw    = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $raw['action'] ?? 'fetch';

try {

function eventColor(string $priority, string $custom_color = ''): string {
    if ($custom_color) return $custom_color;
    return match($priority) { 'high'=>'#f76a6a','medium'=>'#f7c46a','low'=>'#6af7b8', default=>'#7c6af7' };
}

// ── FETCH ──────────────────────────────────────────────────────────────────
if ($action === 'fetch') {
    $start = substr($_GET['start'] ?? date('Y-m-01'), 0, 10);
    $end   = substr($_GET['end']   ?? date('Y-m-t'),  0, 10);

    // Fetch normal tasks
    $stmt = $pdo->prepare("
        SELECT id, task_name, course, priority, due_date, start_time, end_time,
               status, notes, custom_color, recur_type, recur_end
        FROM tasks
        WHERE user_id = ? AND due_date BETWEEN ? AND ?
        ORDER BY due_date, FIELD(priority,'high','medium','low')
    ");
    $stmt->execute([$user_id, $start, $end]);
    $tasks  = $stmt->fetchAll();
    $events = [];

    foreach ($tasks as $t) {
        $color  = eventColor($t['priority'], $t['custom_color'] ?? '');
        $isDone = $t['status'] === 'done';
        if (!empty($t['start_time'])) {
            $startDt = $t['due_date'].'T'.$t['start_time'];
            $endDt   = !empty($t['end_time'])
                ? $t['due_date'].'T'.$t['end_time']
                : $t['due_date'].'T'.date('H:i', strtotime($t['start_time'].' +1 hour'));
            $allDay = false;
        } else { $startDt = $t['due_date']; $endDt = null; $allDay = true; }

        $events[] = [
            'id'              => $t['id'],
            'title'           => $t['task_name'],
            'start'           => $startDt,
            'end'             => $endDt,
            'allDay'          => $allDay,
            'backgroundColor' => $isDone ? '#444' : $color,
            'borderColor'     => $isDone ? '#444' : $color,
            'textColor'       => '#fff',
            'extendedProps'   => [
                'priority'     => $t['priority'],
                'course'       => $t['course'],
                'status'       => $t['status'],
                'notes'        => $t['notes'] ?? '',
                'custom_color' => $t['custom_color'] ?? '',
                'recur_type'   => $t['recur_type'] ?? '',
                'recur_end'    => $t['recur_end'] ?? '',
            ],
        ];

        // Generate recurring instances
        if (!empty($t['recur_type']) && $t['recur_type'] !== 'none') {
            $recurEnd  = !empty($t['recur_end']) ? $t['recur_end'] : $end;
            $curDate   = new DateTime($t['due_date']);
            $limitDate = new DateTime(min($recurEnd, $end));
            $startTime = $t['start_time'] ? 'T'.$t['start_time'] : '';
            $endTime   = $t['end_time']   ? 'T'.$t['end_time']   : '';
            $step = match($t['recur_type']) { 'daily'=>'1 day','weekly'=>'1 week','monthly'=>'1 month', default=>'1 week' };
            $curDate->modify('+' . $step);
            $count = 0;
            while ($curDate <= $limitDate && $count++ < 60) {
                $d = $curDate->format('Y-m-d');
                $events[] = [
                    'id'              => 'r_'.$t['id'].'_'.$d,
                    'title'           => $t['task_name'],
                    'start'           => $d.$startTime,
                    'end'             => $endTime ? $d.$endTime : null,
                    'allDay'          => $allDay,
                    'backgroundColor' => $isDone ? '#444' : $color,
                    'borderColor'     => $isDone ? '#444' : $color,
                    'textColor'       => '#fff',
                    'extendedProps'   => array_merge($events[count($events)-1]['extendedProps'], ['is_recur'=>true,'parent_id'=>$t['id']]),
                ];
                $curDate->modify('+' . $step);
            }
        }
    }
    echo json_encode($events); exit;
}

// ── ADD ────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $taskName   = trim($raw['task_name'] ?? '');
    if (!$taskName) { echo json_encode(['ok'=>false,'error'=>'Task name required']); exit; }
    $course      = trim($raw['course']       ?? '');
    $priority    = $raw['priority']           ?? 'medium';
    $dueDate     = $raw['due_date']           ?? date('Y-m-d');
    $startTime   = $raw['start_time']         ?? null;
    $endTime     = $raw['end_time']           ?? null;
    $notes       = trim($raw['notes']        ?? '');
    $customColor = $raw['custom_color']       ?? '';
    $recurType   = $raw['recur_type']         ?? 'none';
    $recurEnd    = $raw['recur_end']          ?? null;
    $pdo->prepare("INSERT INTO tasks (user_id,task_name,course,priority,due_date,start_time,end_time,notes,custom_color,recur_type,recur_end) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$user_id,$taskName,$course,$priority,$dueDate,$startTime?:null,$endTime?:null,$notes,$customColor,$recurType,$recurEnd?:null]);
    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
}

// ── UPDATE ─────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'No ID']); exit; }
    $own = $pdo->prepare("SELECT id FROM tasks WHERE id=? AND user_id=?");
    $own->execute([$id, $user_id]);
    if (!$own->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $newStart  = $raw['start']      ?? null;
    $allDay    = $raw['allDay']     ?? false;
    $dueDate   = $newStart ? substr($newStart, 0, 10) : ($raw['due_date'] ?? null);
    $startTime = (!$allDay && $newStart && strlen($newStart) > 10) ? substr($newStart, 11, 5) : ($raw['start_time'] ?? null);
    $endDt     = $raw['end']        ?? null;
    $endTime   = ($endDt && !$allDay && strlen($endDt) > 10) ? substr($endDt, 11, 5) : ($raw['end_time'] ?? null);
    $taskName  = trim($raw['task_name']    ?? '');
    $course    = trim($raw['course']       ?? '');
    $priority  = $raw['priority']          ?? null;
    $notes     = trim($raw['notes']       ?? '');
    $customColor = $raw['custom_color']    ?? null;
    $recurType = $raw['recur_type']        ?? null;
    $recurEnd  = $raw['recur_end']         ?? null;

    $pdo->prepare("UPDATE tasks SET
        due_date=COALESCE(?,due_date), start_time=?, end_time=?,
        task_name=IF(?='',task_name,?), course=COALESCE(?,course),
        priority=COALESCE(?,priority), notes=COALESCE(?,notes),
        custom_color=COALESCE(?,custom_color),
        recur_type=COALESCE(?,recur_type), recur_end=COALESCE(?,recur_end)
        WHERE id=? AND user_id=?")
        ->execute([$dueDate,$startTime?:null,$endTime?:null,
                   $taskName,$taskName,$course,$priority,
                   $notes?:null,$customColor,$recurType,$recurEnd,
                   $id,$user_id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── DUPLICATE ──────────────────────────────────────────────────────────────
if ($action === 'duplicate') {
    $id   = (int)($raw['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id=? AND user_id=?");
    $stmt->execute([$id, $user_id]);
    $t = $stmt->fetch();
    if (!$t) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    $newDate = $raw['due_date'] ?? $t['due_date'];
    $pdo->prepare("INSERT INTO tasks (user_id,task_name,course,priority,due_date,start_time,end_time,notes,custom_color,recur_type,recur_end)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$user_id,$t['task_name'].' (Copy)',$t['course'],$t['priority'],$newDate,$t['start_time'],$t['end_time'],$t['notes'],$t['custom_color'],$t['recur_type'],$t['recur_end']]);
    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
}

// ── DELETE ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare("DELETE FROM tasks WHERE id=? AND user_id=?")->execute([$id,$user_id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── TOGGLE done/pending ────────────────────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare("UPDATE tasks SET status=IF(status='done','pending','done') WHERE id=? AND user_id=?")->execute([$id,$user_id]);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
