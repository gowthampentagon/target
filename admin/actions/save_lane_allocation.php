<?php
// admin/actions/save_lane_allocation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventRegId    = (int)($_POST['event_reg_id'] ?? 0);
$bibNo         = trim($_POST['bib_no'] ?? '');
$relayNo       = (int)($_POST['relay_no'] ?? 0);
$laneNo        = (int)($_POST['lane_no'] ?? 0);
$scheduledDate = trim($_POST['scheduled_date'] ?? '');
$reportingTime = trim($_POST['reporting_time'] ?? '');
$startTime     = trim($_POST['start_time'] ?? '');
$allocId       = (int)($_POST['alloc_id'] ?? 0);

$manualShooterName = trim($_POST['manual_shooter_name'] ?? '');
$manualClubName    = trim($_POST['manual_club_name'] ?? '');
$activeEventName   = trim($_POST['active_event_name'] ?? '');

// Validation
if ($eventRegId === 0) {
    exit(json_encode(['success' => false, 'message' => 'Shooter selection is required.']));
}
if ($relayNo <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Relay number must be greater than 0.']));
}
if ($laneNo <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Lane (FP) number must be greater than 0.']));
}
if (empty($scheduledDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
    exit(json_encode(['success' => false, 'message' => 'Valid scheduled date (YYYY-MM-DD) is required.']));
}
if (empty($reportingTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $reportingTime)) {
    exit(json_encode(['success' => false, 'message' => 'Valid reporting time (HH:MM) is required.']));
}
if (empty($startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
    exit(json_encode(['success' => false, 'message' => 'Valid start time (HH:MM) is required.']));
}

try {
    $pdo = getDB();

    if ($eventRegId === -1) {
        if ($manualShooterName === '' || $manualClubName === '') {
            exit(json_encode(['success' => false, 'message' => 'Shooter name and club name are required for manual write-in.']));
        }
        if ($activeEventName === '') {
            exit(json_encode(['success' => false, 'message' => 'Active event context not found.']));
        }

        // Generate a new MANUAL competitor record
        $dummyEmail = 'manual_' . bin2hex(random_bytes(6)) . '@ssa.com';
        $dummyPhone = '99' . random_int(10000000, 99999999);
        $dummyRegId = 'MANUAL-' . random_int(1000, 9999);
        $dummyAadhaar = '99' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT) . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        $stmtReg = $pdo->prepare("
            INSERT INTO registrations (reg_id, first_name, last_name, email, phone, aadhaar_number, club_name, district, association, father_guardian_name, address, status, is_verified, dob, gender, password_hash)
            VALUES (?, ?, '', ?, ?, ?, ?, 'CHENNAI', 'TNSA', 'MANUAL', 'MANUAL', 'active', 1, '2000-01-01', 'Male', 'dummy')
        ");
        $stmtReg->execute([$dummyRegId, $manualShooterName, $dummyEmail, $dummyPhone, $dummyAadhaar, $manualClubName]);
        $userId = (int)$pdo->lastInsertId();

        $dummyEvRegId = 'MANUAL-EVT-' . bin2hex(random_bytes(6));
        $evtCategory = (stripos($activeEventName, 'issf') !== false) ? 'ISSF' : 'NR';
        $evtCode = strtoupper(preg_replace('/[^A-Z0-9]/', '-', $activeEventName));
        $dummyWeaponType = (stripos($activeEventName, 'pistol') !== false) ? 'Pistol' : 'Rifle';
        $dummyAgeGroup = 'Senior';

        $stmtEvReg = $pdo->prepare("
            INSERT INTO event_registrations (user_id, event_reg_id, category, event_code, event_name, status, entry_fee, weapon_type, age_group)
            VALUES (?, ?, ?, ?, ?, 'approved', 0, ?, ?)
        ");
        $stmtEvReg->execute([$userId, $dummyEvRegId, $evtCategory, $evtCode, $activeEventName, $dummyWeaponType, $dummyAgeGroup]);
        $eventRegId = (int)$pdo->lastInsertId();

        $evReg = ['status' => 'approved'];
        $evName = $activeEventName;
    } else {
        // Check if the event registration is valid and approved
        $stmt = $pdo->prepare("SELECT id, status FROM event_registrations WHERE id = ? LIMIT 1");
        $stmt->execute([$eventRegId]);
        $evReg = $stmt->fetch();
        if (!$evReg) {
            exit(json_encode(['success' => false, 'message' => 'Event registration not found.']));
        }
        $statusClean = strtolower((string)($evReg['status'] ?? ''));
        if (!in_array($statusClean, ['approved', 'active', 'pending', ''], true)) {
            exit(json_encode(['success' => false, 'message' => 'Only active or approved event registrations can be allocated a lane.']));
        }

        $stmtEvent = $pdo->prepare("SELECT event_name FROM event_registrations WHERE id = ? LIMIT 1");
        $stmtEvent->execute([$eventRegId]);
        $evName = $stmtEvent->fetchColumn();
    }

    $baseType = getBackendEventBaseType((string)$evName);
    $panelLabel = formatBaseEventName($baseType);
    $stmtLimit = $pdo->prepare("
        SELECT relay_limit 
        FROM relay_schedules 
        WHERE event_name = ? AND schedule_name = '' AND scheduled_date = ? AND relay_no = ?
        LIMIT 1
    ");
    $stmtLimit->execute([$panelLabel, $scheduledDate, $relayNo]);
    $customLimit = $stmtLimit->fetchColumn();
    $capacity = ($customLimit !== false && $customLimit !== null) ? (int)$customLimit : getRangeCapacity((string)$evName);

    if ($laneNo > $capacity) {
        exit(json_encode([
            'success' => false, 
            'message' => "Lane number (FP) {$laneNo} exceeds the maximum limit of {$capacity} firing points for this relay."
        ]));
    }

    // Check if the Firing Point/Lane is already allocated to someone else for the same scheduled date, relay, and range type
    $dupLane = $pdo->prepare("
        SELECT la.id, r.first_name, r.last_name, er.event_name 
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        JOIN registrations r ON er.user_id = r.id
        WHERE la.scheduled_date = ? AND la.relay_no = ? AND la.lane_no = ? AND la.id != ?
    ");
    $dupLane->execute([$scheduledDate, $relayNo, $laneNo, $allocId]);
    $clashes = $dupLane->fetchAll();
    
    $currentRangeType = getRangeType((string)$evName);
    foreach ($clashes as $clash) {
        if (getRangeType((string)$clash['event_name']) === $currentRangeType) {
            $clashName = trim(($clash['first_name'] ?? '') . ' ' . ($clash['last_name'] ?? ''));
            if ($clashName === '') {
                $clashName = 'another competitor';
            }
            exit(json_encode([
                'success' => false, 
                'message' => "Lane {$laneNo} in Relay {$relayNo} on {$scheduledDate} is already allocated to " . htmlspecialchars($clashName) . "."
            ]));
        }
    }

    // Check if this competitor is already allocated to any relay under this weapon type
    if ($eventRegId > 0) {
        $stmtUser = $pdo->prepare("SELECT user_id, event_name FROM event_registrations WHERE id = ? LIMIT 1");
        $stmtUser->execute([$eventRegId]);
        $uInfo = $stmtUser->fetch();
        if ($uInfo) {
            $uId = (int)$uInfo['user_id'];
            $uEvt = (string)$uInfo['event_name'];
            $uBase = getBackendEventBaseType($uEvt);

            $stmtCheck = $pdo->prepare("
                SELECT la.id 
                FROM lane_allocations la 
                JOIN event_registrations er ON la.event_reg_id = er.id 
                WHERE er.user_id = ? AND la.lane_no > 0
                LIMIT 1
            ");
            $stmtCheck->execute([$uId]);
            $existingAllocId = $stmtCheck->fetchColumn();

            if ($existingAllocId !== false && $existingAllocId !== null) {
                $allocId = (int)$existingAllocId;
            }
        }
    }

    // Determine the bib number to use
    $chkExist = $pdo->prepare("SELECT bib_no FROM lane_allocations WHERE event_reg_id = ? LIMIT 1");
    $chkExist->execute([$eventRegId]);
    $existingBib = $chkExist->fetchColumn();

    if (!isSuperAdmin()) {
        if ($existingBib !== false && $existingBib !== null) {
            $bibNo = $existingBib; // Retain existing bib_no
        } else {
            $bibNo = null; // Set to null for new allocation
        }
    } else {
        $bibNo = $bibNo !== '' ? $bibNo : null;
    }

    if ($allocId > 0) {
        // Update existing allocation
        $up = $pdo->prepare("
            UPDATE lane_allocations 
            SET event_reg_id = ?, bib_no = ?, relay_no = ?, lane_no = ?, scheduled_date = ?, reporting_time = ?, start_time = ?
            WHERE id = ?
        ");
        $up->execute([$eventRegId, $bibNo, $relayNo, $laneNo, $scheduledDate, $reportingTime, $startTime, $allocId]);
        exit(json_encode(['success' => true, 'message' => 'Lane allocation updated successfully.']));
    } else {
        // Insert new allocation
        $ins = $pdo->prepare("
            INSERT INTO lane_allocations (event_reg_id, bib_no, relay_no, lane_no, scheduled_date, reporting_time, start_time)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$eventRegId, $bibNo, $relayNo, $laneNo, $scheduledDate, $reportingTime, $startTime]);
        exit(json_encode(['success' => true, 'message' => 'Lane allocation saved successfully.']));
    }

} catch (Throwable $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]));
}
