<?php
/**
 * admin/actions/championship_action.php
 * Handles AJAX requests for Multi-Championship Management (List, Create, Clone, Activate, Archive, Delete).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check: Must be logged in as admin
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized session. Please log in.']));
}

$pdo = getDB();

$action = trim($_REQUEST['action'] ?? 'list');

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT * FROM `championships` ORDER BY id DESC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add registration & participant counts for each championship
        foreach ($list as &$c) {
            $cid = (int)$c['id'];
            try {
                $c['total_registrations'] = (int)$pdo->query("SELECT COUNT(*) FROM `event_registrations` WHERE `championship_id` = {$cid}")->fetchColumn();
                $c['total_payments'] = (int)$pdo->query("SELECT COUNT(*) FROM `payment_sessions` WHERE `championship_id` = {$cid}")->fetchColumn();
            } catch (Throwable $t) {
                $c['total_registrations'] = 0;
                $c['total_payments'] = 0;
            }
        }
        unset($c);

        $active = getActiveChampionship($pdo);

        exit(json_encode([
            'success' => true,
            'data' => $list,
            'active_championship' => $active
        ]));
    }

    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $name   = trim($_POST['championship_name'] ?? '');
        $year   = trim($_POST['championship_year'] ?? date('Y'));
        $status = strtoupper(trim($_POST['status'] ?? 'DRAFT'));
        $regOpen  = !empty($_POST['registration_open']) ? $_POST['registration_open'] : null;
        $regClose = !empty($_POST['registration_close']) ? $_POST['registration_close'] : null;
        $evtStart = !empty($_POST['event_start']) ? $_POST['event_start'] : null;
        $evtEnd   = !empty($_POST['event_end']) ? $_POST['event_end'] : null;
        $theme    = trim($_POST['theme'] ?? 'gold_dark');
        $logo     = trim($_POST['logo'] ?? '');
        $banner   = trim($_POST['banner'] ?? '');

        if (empty($name) || empty($year)) {
            exit(json_encode(['success' => false, 'message' => 'Championship Name and Year are required.']));
        }

        if (!in_array($status, ['ACTIVE', 'ARCHIVED', 'DRAFT'], true)) {
            $status = 'DRAFT';
        }

        // If creating as ACTIVE, set all existing ACTIVE championships to ARCHIVED
        if ($status === 'ACTIVE') {
            $pdo->exec("UPDATE `championships` SET `status` = 'ARCHIVED' WHERE `status` = 'ACTIVE'");
        }

        $stmt = $pdo->prepare("INSERT INTO `championships` (
            `championship_name`, `championship_year`, `status`, `registration_open`, `registration_close`, `event_start`, `event_end`, `theme`, `logo`, `banner`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([$name, $year, $status, $regOpen, $regClose, $evtStart, $evtEnd, $theme, $logo, $banner]);
        $newId = (int)$pdo->lastInsertId();

        exit(json_encode([
            'success' => true,
            'message' => "Championship '{$name}' created successfully!",
            'championship_id' => $newId
        ]));
    }

    if ($action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['championship_name'] ?? '');
        $year   = trim($_POST['championship_year'] ?? date('Y'));
        $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));
        $regOpen  = !empty($_POST['registration_open']) ? $_POST['registration_open'] : null;
        $regClose = !empty($_POST['registration_close']) ? $_POST['registration_close'] : null;
        $evtStart = !empty($_POST['event_start']) ? $_POST['event_start'] : null;
        $evtEnd   = !empty($_POST['event_end']) ? $_POST['event_end'] : null;
        $theme    = trim($_POST['theme'] ?? 'gold_dark');

        if ($id <= 0 || empty($name)) {
            exit(json_encode(['success' => false, 'message' => 'Valid Championship ID and Name are required.']));
        }

        $stmt = $pdo->prepare("UPDATE `championships` SET 
            `championship_name` = ?, `championship_year` = ?, `status` = ?,
            `registration_open` = ?, `registration_close` = ?, `event_start` = ?, `event_end` = ?, `theme` = ?
            WHERE `id` = ?");
        $stmt->execute([$name, $year, $status, $regOpen, $regClose, $evtStart, $evtEnd, $theme, $id]);

        // Keep event_info in sync for backward-compatibility
        try {
            $pdo->prepare("UPDATE `event_info` SET `event_title` = ? WHERE id = 1")->execute([$name]);
        } catch (Throwable $t) {}

        exit(json_encode([
            'success' => true,
            'message' => "Championship '{$name}' updated successfully!"
        ]));
    }

    if ($action === 'clone') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $sourceId = (int)($_POST['source_championship_id'] ?? 0);
        $name     = trim($_POST['championship_name'] ?? '');
        $year     = trim($_POST['championship_year'] ?? date('Y'));
        $regOpen  = !empty($_POST['registration_open']) ? $_POST['registration_open'] : null;
        $regClose = !empty($_POST['registration_close']) ? $_POST['registration_close'] : null;
        $evtStart = !empty($_POST['event_start']) ? $_POST['event_start'] : null;
        $evtEnd   = !empty($_POST['event_end']) ? $_POST['event_end'] : null;
        $theme    = trim($_POST['theme'] ?? 'gold_dark');

        if ($sourceId <= 0 || empty($name) || empty($year)) {
            exit(json_encode(['success' => false, 'message' => 'Source Championship, Name, and Year are required.']));
        }

        // Fetch source championship
        $source = getChampionshipById($sourceId, $pdo);
        if (!$source) {
            exit(json_encode(['success' => false, 'message' => 'Source championship not found.']));
        }

        // Create new championship record
        $stmtInsert = $pdo->prepare("INSERT INTO `championships` (
            `championship_name`, `championship_year`, `status`, `registration_open`, `registration_close`, `event_start`, `event_end`, `theme`, `logo`, `banner`
        ) VALUES (?, ?, 'DRAFT', ?, ?, ?, ?, ?, ?, ?)");

        $stmtInsert->execute([$name, $year, $regOpen, $regClose, $evtStart, $evtEnd, $theme, $source['logo'], $source['banner']]);
        $newId = (int)$pdo->lastInsertId();

        // 1. Copy Event Definitions ONLY (no participant registrations)
        try {
            $eventsStmt = $pdo->prepare("SELECT * FROM `events` WHERE `championship_id` = ?");
            $eventsStmt->execute([$sourceId]);
            $sourceEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback: if source has 0 events specifically tagged, copy all base events
            if (empty($sourceEvents)) {
                $sourceEvents = $pdo->query("SELECT * FROM `events`")->fetchAll(PDO::FETCH_ASSOC);
            }

            $stmtEvtCopy = $pdo->prepare("INSERT IGNORE INTO `events` (`championship_id`, `event_code`, `event_name`, `category`, `age_group`, `entry_fee`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($sourceEvents as $ev) {
                $stmtEvtCopy->execute([$newId, $ev['event_code'], $ev['event_name'], $ev['category'], $ev['age_group'] ?? 'Senior', $ev['entry_fee'], $ev['status'] ?? 'active']);
            }
        } catch (Throwable $t) {}

        // 2. Copy Document & Certificate Templates
        try {
            $docStmt = $pdo->prepare("SELECT * FROM `document_templates` WHERE `championship_id` = ?");
            $docStmt->execute([$sourceId]);
            $sourceDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($sourceDocs)) {
                $sourceDocs = $pdo->query("SELECT * FROM `document_templates`")->fetchAll(PDO::FETCH_ASSOC);
            }

            $stmtDocCopy = $pdo->prepare("INSERT INTO `document_templates` (`championship_id`, `document_type`, `background_path`, `canvas_data`) VALUES (?, ?, ?, ?)");
            foreach ($sourceDocs as $doc) {
                $stmtDocCopy->execute([$newId, $doc['document_type'], $doc['background_path'], $doc['canvas_data']]);
            }
        } catch (Throwable $t) {}

        exit(json_encode([
            'success' => true,
            'message' => "Successfully cloned definitions from '{$source['championship_name']}' into '{$name}'!",
            'new_championship_id' => $newId
        ]));
    }

    if ($action === 'activate' || $action === 'switch_active') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid Championship ID.']));
        }

        // Set all ACTIVE championships to ARCHIVED
        $pdo->exec("UPDATE `championships` SET `status` = 'ARCHIVED' WHERE `status` = 'ACTIVE'");

        // Set target championship to ACTIVE
        $stmt = $pdo->prepare("UPDATE `championships` SET `status` = 'ACTIVE' WHERE `id` = ?");
        $stmt->execute([$id]);

        $_SESSION['active_championship_id'] = $id;

        $active = getChampionshipById($id, $pdo);

        // Sync event_info title for backward-compatibility
        if ($active && !empty($active['championship_name'])) {
            try {
                $pdo->prepare("UPDATE `event_info` SET `event_title` = ? WHERE id = 1")->execute([$active['championship_name']]);
            } catch (Throwable $t) {}
        }

        exit(json_encode([
            'success' => true,
            'message' => "Championship '{$active['championship_name']}' is now the ACTIVE championship across the portal!",
            'active_championship' => $active
        ]));
    }

    if ($action === 'archive') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid Championship ID.']));
        }

        $stmt = $pdo->prepare("UPDATE `championships` SET `status` = 'ARCHIVED' WHERE `id` = ?");
        $stmt->execute([$id]);

        exit(json_encode([
            'success' => true,
            'message' => 'Championship moved to ARCHIVED status (read-only).'
        ]));
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid Championship ID.']));
        }

        // Validation: Verify 0 registrations, payments, scores, or certificates exist
        $regCount = 0;
        $payCount = 0;
        $scoreCount = 0;
        $certCount = 0;

        try { $regCount = (int)$pdo->query("SELECT COUNT(*) FROM `event_registrations` WHERE `championship_id` = {$id}")->fetchColumn(); } catch (Throwable $t) {}
        try { $payCount = (int)$pdo->query("SELECT COUNT(*) FROM `payment_sessions` WHERE `championship_id` = {$id}")->fetchColumn(); } catch (Throwable $t) {}
        try { $scoreCount = (int)$pdo->query("SELECT COUNT(*) FROM `score_sheets` WHERE `championship_id` = {$id}")->fetchColumn(); } catch (Throwable $t) {}
        try { $certCount = (int)$pdo->query("SELECT COUNT(*) FROM `certificates` WHERE `championship_id` = {$id}")->fetchColumn(); } catch (Throwable $t) {}

        $totalHistory = $regCount + $payCount + $scoreCount + $certCount;

        if ($totalHistory > 0) {
            exit(json_encode([
                'success' => false,
                'message' => "Cannot delete championship! It contains {$regCount} registrations, {$payCount} payment sessions, {$scoreCount} score records, and {$certCount} certificates. Historical data must be preserved. Try archiving instead."
            ]));
        }

        // Safe to delete empty draft/unregistered championship
        $stmt = $pdo->prepare("DELETE FROM `championships` WHERE `id` = ?");
        $stmt->execute([$id]);

        exit(json_encode([
            'success' => true,
            'message' => 'Empty championship deleted successfully!'
        ]));
    }

    if ($action === 'backup') {
        $id = (int)($_REQUEST['id'] ?? 0);
        $format = strtolower(trim($_REQUEST['format'] ?? 'excel'));
        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid Championship ID.']));
        }

        $championship = getChampionshipById($id, $pdo);
        if (!$championship) {
            exit(json_encode(['success' => false, 'message' => 'Championship not found.']));
        }

        $events = [];
        $registrations = [];
        $payments = [];
        $laneAllocations = [];
        $scores = [];
        $certificates = [];

        try {
            $stmt = $pdo->prepare("SELECT * FROM `events` WHERE `championship_id` = ?");
            $stmt->execute([$id]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        try {
            $stmt = $pdo->prepare("
                SELECT 
                    r.id AS user_id,
                    r.reg_id,
                    r.first_name,
                    r.last_name,
                    r.email,
                    r.phone,
                    r.club_name,
                    COALESCE(r.district, '-') AS state,
                    r.gender,
                    COUNT(er.id) AS total_events_count,
                    GROUP_CONCAT(DISTINCT er.event_name ORDER BY er.id ASC SEPARATOR ' ; ') AS raw_event_names,
                    GROUP_CONCAT(DISTINCT er.event_code ORDER BY er.id ASC SEPARATOR ', ') AS raw_event_codes,
                    GROUP_CONCAT(DISTINCT er.category ORDER BY er.id ASC SEPARATOR ', ') AS categories,
                    COALESCE(ps.total_amount, SUM(er.entry_fee), 0) AS real_amount_paid,
                    MAX(er.status) AS status,
                    MAX(la.bib_no) AS bib_no,
                    MAX(er.created_at) AS created_at
                FROM registrations r
                JOIN event_registrations er ON r.id = er.user_id
                LEFT JOIN (
                    SELECT user_id, MAX(total_amount) AS total_amount
                    FROM registration_sessions
                    GROUP BY user_id
                ) ps ON r.id = ps.user_id
                LEFT JOIN lane_allocations la ON er.id = la.event_reg_id
                WHERE er.championship_id = ?
                GROUP BY r.id
                ORDER BY r.id DESC
            ");
            $stmt->execute([$id]);
            $rawRegs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawRegs as $r) {
                $r['shooter_bib'] = formatBibNo($r['reg_id'], $r['bib_no'] ?? null);
                $r['event_name'] = $r['raw_event_names'] ?? '';
                $r['category'] = !empty($r['categories']) ? $r['categories'] : 'NR';
                $r['entry_fee'] = (float)$r['real_amount_paid'];
                $registrations[] = $r;
            }
        } catch (Throwable $t) {}

        try {
            $stmt = $pdo->prepare("SELECT * FROM `payment_sessions` WHERE `championship_id` = ?");
            $stmt->execute([$id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        try {
            $stmt = $pdo->prepare("SELECT la.*, er.event_name, r.first_name, r.last_name FROM `lane_allocations` la JOIN `event_registrations` er ON la.event_reg_id = er.id JOIN `registrations` r ON er.user_id = r.id WHERE er.`championship_id` = ?");
            $stmt->execute([$id]);
            $laneAllocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        try {
            $stmt = $pdo->prepare("SELECT ss.*, la.bib_no, er.event_name, r.first_name, r.last_name FROM `score_sheets` ss JOIN `lane_allocations` la ON ss.lane_alloc_id = la.id JOIN `event_registrations` er ON la.event_reg_id = er.id JOIN `registrations` r ON er.user_id = r.id WHERE er.`championship_id` = ?");
            $stmt->execute([$id]);
            $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        try {
            $stmt = $pdo->prepare("SELECT c.*, r.first_name, r.last_name, r.reg_id FROM `certificates` c JOIN `registrations` r ON c.user_id = r.id WHERE c.`championship_id` = ?");
            $stmt->execute([$id]);
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        if ($format === 'json') {
            $backupPackage = [
                'backup_created_at' => date('Y-m-d H:i:s'),
                'championship' => $championship,
                'summary' => [
                    'total_events' => count($events),
                    'total_registrations' => count($registrations),
                    'total_payments' => count($payments),
                    'total_lane_allocations' => count($laneAllocations),
                    'total_scores' => count($scores),
                    'total_certificates' => count($certificates)
                ],
                'events' => $events,
                'registrations' => $registrations,
                'payments' => $payments,
                'lane_allocations' => $laneAllocations,
                'score_sheets' => $scores,
                'certificates' => $certificates
            ];

            $filename = 'championship_backup_id_' . $id . '_' . date('Y-m-d_His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            exit(json_encode($backupPackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Export as Clean CSV Spreadsheet (.csv) with UTF-8 BOM for Excel compatibility
        $filename = 'championship_backup_id_' . $id . '_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        // 1. Championship Summary
        fputcsv($out, ['=== CHAMPIONSHIP SUMMARY ===']);
        fputcsv($out, ['Championship Name', $championship['championship_name'] ?? 'N/A']);
        fputcsv($out, ['Championship Year', $championship['championship_year'] ?? 'N/A']);
        fputcsv($out, ['Status', $championship['status'] ?? 'N/A']);
        fputcsv($out, ['Backup Created At', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Total Registrations', count($registrations)]);
        fputcsv($out, ['Total Events', count($events)]);
        fputcsv($out, ['Total Lane Allocations', count($laneAllocations)]);
        fputcsv($out, ['Total Score Sheets', count($scores)]);
        fputcsv($out, ['Total Payments', count($payments)]);
        fputcsv($out, ['Total Certificates', count($certificates)]);
        fputcsv($out, []);

        // 2. Registrations
        fputcsv($out, ['=== REGISTRATIONS ===']);
        fputcsv($out, ['User ID', 'Shooter Bib', 'First Name', 'Last Name', 'Email', 'Phone', 'Club / State', 'Participated Events', 'Category', 'Total Amount Paid (INR)', 'Status', 'Registered Date']);
        foreach ($registrations as $r) {
            fputcsv($out, [
                $r['user_id'] ?? '',
                $r['shooter_bib'] ?? '',
                $r['first_name'] ?? '',
                $r['last_name'] ?? '',
                $r['email'] ?? '',
                $r['phone'] ?? '',
                $r['club_name'] ?? ($r['state'] ?? ''),
                $r['event_name'] ?? '',
                $r['category'] ?? '',
                number_format((float)($r['entry_fee'] ?? 0), 2, '.', ''),
                $r['status'] ?? 'approved',
                $r['created_at'] ?? ''
            ]);
        }
        fputcsv($out, []);

        // 3. Events
        fputcsv($out, ['=== EVENTS ===']);
        fputcsv($out, ['Event Code', 'Event Name', 'Category', 'Age Group', 'Entry Fee', 'MQS Score', 'Status']);
        foreach ($events as $e) {
            fputcsv($out, [
                $e['event_code'] ?? '',
                $e['event_name'] ?? '',
                $e['category'] ?? '',
                $e['age_group'] ?? '',
                $e['entry_fee'] ?? '',
                $e['mqs_score'] ?? '',
                $e['status'] ?? ''
            ]);
        }
        fputcsv($out, []);

        // 4. Scores & Results
        fputcsv($out, ['=== SCORES & RESULTS ===']);
        fputcsv($out, ['Score Sheet ID', 'Lane Alloc ID', 'Bib No', 'Shooter Name', 'Event Name', 'Total Val', 'Penalty', 'Grand Total', 'Remarks', 'Updated At']);
        foreach ($scores as $s) {
            $shooterName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            fputcsv($out, [
                $s['id'] ?? '',
                $s['lane_alloc_id'] ?? '',
                $s['bib_no'] ?? '',
                $shooterName,
                $s['event_name'] ?? '',
                $s['total_val'] ?? '',
                $s['penalty_val'] ?? '',
                $s['grand_total_val'] ?? '',
                $s['remarks'] ?? '',
                $s['updated_at'] ?? ''
            ]);
        }
        fputcsv($out, []);

        // 5. Lane Allocations
        fputcsv($out, ['=== LANE ALLOCATIONS ===']);
        fputcsv($out, ['Alloc ID', 'Bib No', 'Shooter Name', 'Event Name', 'Relay No', 'Lane No', 'Scheduled Date', 'Start Time', 'Target Serial No']);
        foreach ($laneAllocations as $la) {
            $shooterName = trim(($la['first_name'] ?? '') . ' ' . ($la['last_name'] ?? ''));
            fputcsv($out, [
                $la['id'] ?? '',
                $la['bib_no'] ?? '',
                $shooterName,
                $la['event_name'] ?? '',
                $la['relay_no'] ?? '',
                $la['lane_no'] ?? '',
                $la['scheduled_date'] ?? '',
                $la['start_time'] ?? '',
                $la['target_serial_no'] ?? ''
            ]);
        }
        fputcsv($out, []);

        // 6. Payments
        fputcsv($out, ['=== PAYMENTS ===']);
        fputcsv($out, ['Session ID', 'User ID', 'Amount', 'Payment Method', 'Transaction ID', 'Status', 'Created At']);
        foreach ($payments as $p) {
            fputcsv($out, [
                $p['id'] ?? '',
                $p['user_id'] ?? '',
                $p['amount'] ?? '',
                $p['payment_method'] ?? '',
                $p['transaction_id'] ?? '',
                $p['status'] ?? '',
                $p['created_at'] ?? ''
            ]);
        }

        fclose($out);
        exit;
    }

    exit(json_encode(['success' => false, 'message' => 'Unknown action.']));

} catch (Throwable $e) {
    error_log('Championship action error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]));
}
