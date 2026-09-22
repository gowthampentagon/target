<?php
/**
 * admin/actions/add_start_sheet_row.php
 * Creates a new manual row in lane_allocations for a relay.
 */
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$stray = ob_get_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$relayNo       = (int)($_POST['relay_no'] ?? 0);
$scheduledDate = trim($_POST['scheduled_date'] ?? '');
$startTime     = trim($_POST['start_time'] ?? '09:00:00');
$eventRegId    = (int)($_POST['event_reg_id'] ?? 0);
$eventName     = trim($_POST['event_name'] ?? '');

if ($relayNo <= 0 || empty($scheduledDate)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid relay number or scheduled date.']));
}

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    // Formulate default reporting time (25 mins prior to start_time)
    $stTs = strtotime($startTime);
    $repTime = ($stTs !== false) ? date('H:i:s', $stTs - (25 * 60)) : '08:35:00';
    $stTime  = ($stTs !== false) ? date('H:i:s', $stTs) : '09:00:00';

    // Resolve or create an event_registration record strictly bound to the target event_name
    $targetEventName = !empty($eventName) ? $eventName : '';
    $officialEventName = $EVENTS_MAPPING[$targetEventName] ?? $targetEventName;

    if ($eventRegId <= 0 && !empty($targetEventName)) {
        // Look for existing event_registration under this event or base type
        $stmtAllEr = $pdo->prepare("
            SELECT id, event_name, event_code 
            FROM event_registrations 
            WHERE (championship_id = ? OR championship_id IS NULL OR championship_id = 1)
            ORDER BY id ASC
        ");
        $stmtAllEr->execute([$cid]);
        $erRows = $stmtAllEr->fetchAll(PDO::FETCH_ASSOC);

        $cleanTarget = preg_replace('/[^a-z0-9]/', '', strtolower($targetEventName));
        foreach ($erRows as $r) {
            $erEvtName = $r['event_name'];
            $erFullName = $EVENTS_MAPPING[$erEvtName] ?? $erEvtName;
            $erBase = getBackendEventBaseType($erFullName);
            $cleanErBase = preg_replace('/[^a-z0-9]/', '', strtolower($erBase ?: $erEvtName));

            if ($cleanErBase === $cleanTarget || strcasecmp($r['event_name'], $officialEventName) === 0 || strcasecmp($r['event_name'], $targetEventName) === 0) {
                $eventRegId = (int)$r['id'];
                $officialEventName = $r['event_name'];
                break;
            }
        }
    }

    if ($eventRegId <= 0) {
        $evtNameVal = (!empty($officialEventName) && $officialEventName !== '_nr') ? $officialEventName : ((!empty($targetEventName) && $targetEventName !== '_nr') ? $targetEventName : 'Manual Event');
        $eventRegId = getOrCreateVacantEventRegId($pdo, $evtNameVal, 'NR', 'MANUAL');
    }

    // Set lane_no (FP) to 0 so it must be manually entered
    $nextLaneNo = 0;

    // Insert new blank manual row into lane_allocations (bib_no and custom_name empty)
    $ins = $pdo->prepare("
        INSERT INTO lane_allocations 
            (event_reg_id, bib_no, custom_name, relay_no, lane_no, scheduled_date, reporting_time, start_time)
        VALUES (?, '', '', ?, ?, ?, ?, ?)
    ");
    $ins->execute([$eventRegId, $relayNo, $nextLaneNo, $scheduledDate, $repTime, $stTime]);
    $newAllocId = (int)$pdo->lastInsertId();

    // Fetch details of the newly created allocation
    $stmtDetail = $pdo->prepare("
        SELECT la.*, er.event_name, er.category, r.first_name, r.last_name, r.club_name, r.district, r.reg_id
        FROM lane_allocations la
        LEFT JOIN event_registrations er ON la.event_reg_id = er.id
        LEFT JOIN registrations r ON er.user_id = r.id
        WHERE la.id = ?
        LIMIT 1
    ");
    $stmtDetail->execute([$newAllocId]);
    $allocData = $stmtDetail->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $newAllocId,
        'event_reg_id' => $eventRegId,
        'relay_no' => $relayNo,
        'lane_no' => $nextLaneNo,
        'scheduled_date' => $scheduledDate,
        'start_time' => $stTime
    ];

    echo json_encode([
        'success'  => true,
        'message'  => 'New allocation row added.',
        'alloc_id' => $newAllocId,
        'data'     => $allocData
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to add row: ' . $e->getMessage()]);
}
