<?php
/**
 * admin/actions/championship_archives_action.php
 * Handles AJAX requests for inspecting and fetching ended championship backup archives.
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

$action = trim($_REQUEST['action'] ?? 'get_archive_data');

try {
    if ($action === 'get_championships') {
        $stmt = $pdo->query("SELECT id, championship_name, championship_year, status FROM `championships` ORDER BY id DESC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        exit(json_encode([
            'success' => true,
            'data' => $list
        ]));
    }

    if ($action === 'get_archive_data') {
        $cid = (int)($_REQUEST['championship_id'] ?? 0);
        if ($cid <= 0) {
            $active = getActiveChampionship($pdo);
            $cid = (int)($active['id'] ?? 1);
        }

        $championship = getChampionshipById($cid, $pdo);
        if (!$championship) {
            exit(json_encode(['success' => false, 'message' => 'Championship record not found.']));
        }

        // Fetch Registrations Backup (Grouped by Competitor)
        $registrations = [];
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
                    GROUP_CONCAT(DISTINCT er.event_name ORDER BY er.id ASC SEPARATOR '||') AS raw_event_names,
                    GROUP_CONCAT(DISTINCT er.event_code ORDER BY er.id ASC SEPARATOR '||') AS raw_event_codes,
                    GROUP_CONCAT(DISTINCT er.category ORDER BY er.id ASC SEPARATOR ', ') AS categories,
                    COALESCE(ps.total_amount, SUM(er.entry_fee), 0) AS real_amount_paid,
                    MAX(er.status) AS status,
                    MAX(la.bib_no) AS bib_no
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
            $stmt->execute([$cid]);
            $rawRegs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawRegs as $r) {
                $r['shooter_bib'] = formatBibNo($r['reg_id'], $r['bib_no'] ?? null);
                
                $evtNames = explode('||', $r['raw_event_names'] ?? '');
                $evtCodes = explode('||', $r['raw_event_codes'] ?? '');
                
                $eventsList = [];
                for ($i = 0; $i < count($evtNames); $i++) {
                    $eName = trim($evtNames[$i] ?? '');
                    $eCode = trim($evtCodes[$i] ?? '');
                    if (empty($eName)) continue;
                    $resolvedCode = resolveEventCode($eName, $eCode);
                    $eventsList[] = [
                        'code' => $resolvedCode,
                        'name' => $eName
                    ];
                }
                $r['events_list'] = $eventsList;
                $r['category'] = !empty($r['categories']) ? $r['categories'] : 'NR';
                $r['entry_fee'] = (float)$r['real_amount_paid'];
                
                $registrations[] = $r;
            }
        } catch (Throwable $t) {}

        // Fetch Score Sheets & Results Backup
        $scores = [];
        try {
            $stmt = $pdo->prepare("
                SELECT ss.*, 
                       la.relay_no, la.lane_no, la.scheduled_date, la.bib_no AS alloc_bib, la.custom_name,
                       r.first_name, r.last_name, r.reg_id, r.id AS user_id,
                       er.event_name, er.event_code, er.match_no,
                       GROUP_CONCAT(DISTINCT er_all.event_name ORDER BY er_all.id ASC SEPARATOR '||') AS all_event_names,
                       GROUP_CONCAT(DISTINCT er_all.event_code ORDER BY er_all.id ASC SEPARATOR '||') AS all_event_codes
                FROM score_sheets ss
                JOIN lane_allocations la ON ss.lane_alloc_id = la.id
                JOIN event_registrations er ON la.event_reg_id = er.id
                JOIN registrations r ON er.user_id = r.id
                LEFT JOIN event_registrations er_all ON (er_all.user_id = r.id AND (er_all.championship_id = ? OR er_all.championship_id IS NULL OR er_all.championship_id = 1))
                WHERE ss.championship_id = ? OR er.championship_id = ?
                GROUP BY ss.id
                ORDER BY ss.id DESC
            ");
            $stmt->execute([$cid, $cid, $cid]);
            $rawScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawScores as $s) {
                $s['shooter_bib'] = formatBibNo($s['reg_id'], $s['alloc_bib'] ?? null);
                $s['shooter_name'] = !empty($s['custom_name']) ? $s['custom_name'] : trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                
                $evtNames = explode('||', $s['all_event_names'] ?? '');
                $evtCodes = explode('||', $s['all_event_codes'] ?? '');
                
                $eventsList = [];
                for ($i = 0; $i < count($evtNames); $i++) {
                    $eName = trim($evtNames[$i] ?? '');
                    $eCode = trim($evtCodes[$i] ?? '');
                    if (empty($eName)) continue;
                    $resolvedCode = resolveEventCode($eName, $eCode);
                    $eventsList[] = [
                        'code' => $resolvedCode,
                        'name' => $eName
                    ];
                }
                if (empty($eventsList)) {
                    $eventsList[] = [
                        'code' => resolveEventCode($s['event_name'] ?? '', $s['event_code'] ?? '', $s['match_no'] ?? ''),
                        'name' => $s['event_name'] ?? ''
                    ];
                }
                $s['events_list'] = $eventsList;
                $scores[] = $s;
            }
        } catch (Throwable $t) {}

        // Fetch Certificates Backup
        $certificates = [];
        try {
            $stmt = $pdo->prepare("SELECT c.*, r.first_name, r.last_name, r.reg_id AS shooter_bib FROM `certificates` c JOIN `registrations` r ON c.user_id = r.id WHERE c.`championship_id` = ? ORDER BY c.id DESC LIMIT 500");
            $stmt->execute([$cid]);
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t) {}

        // Fetch Event Directory Snapshot
        $events = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM `events` WHERE `championship_id` = ? ORDER BY id ASC");
            $stmt->execute([$cid]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($events)) {
                $stmtFb = $pdo->query("SELECT * FROM `events` ORDER BY id ASC");
                $events = $stmtFb->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $t) {}

        exit(json_encode([
            'success' => true,
            'championship' => $championship,
            'metrics' => [
                'total_registrations' => count($registrations),
                'total_scores' => count($scores),
                'total_certificates' => count($certificates),
                'total_events' => count($events)
            ],
            'registrations' => $registrations,
            'score_sheets' => $scores,
            'certificates' => $certificates,
            'events' => $events
        ]));
    }

    exit(json_encode(['success' => false, 'message' => 'Unknown action.']));

} catch (Throwable $e) {
    error_log('Championship archives action error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]));
}
