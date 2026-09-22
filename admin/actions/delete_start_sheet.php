<?php
/**
 * admin/actions/delete_start_sheet.php
 * Handles deleting lane allocations for selected weapon base groups.
 */
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$stray = ob_get_clean();
if (!empty($stray)) {
    $logMsg = '[' . date('Y-m-d H:i:s') . "] delete_start_sheet STRAY OUTPUT: " . substr($stray, 0, 500) . "\n";
    @file_put_contents(dirname(__DIR__, 2) . '/db_connection_error.log', $logMsg, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventsJson = $_POST['events'] ?? '';
if (empty($eventsJson)) {
    exit(json_encode(['success' => false, 'message' => 'No events selected.']));
}

$selectedKeys = json_decode($eventsJson, true);
if (!is_array($selectedKeys) || empty($selectedKeys)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid events format.']));
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    if (in_array('ALL', $selectedKeys, true)) {
        $pdo->exec("DELETE FROM score_sheets");
        $pdo->exec("DELETE FROM lane_allocations");
        $pdo->exec("UPDATE event_registrations SET certificate_path = NULL");
        if (function_exists('cleanupOrphanManualRegistrations')) {
            cleanupOrphanManualRegistrations($pdo);
        }
        $pdo->commit();
        exit(json_encode(['success' => true, 'message' => 'All start sheets deleted successfully.']));
    }

    $allSelectedEventIds = [];
    foreach ($selectedKeys as $evtKey) {
        $evtKey = trim((string)$evtKey);
        if (empty($evtKey) || $evtKey === 'ALL') continue;
        $allSelectedEventIds[] = $evtKey;
        $coreKey = preg_replace('/_(issf|nr|para_deaf)$/i', '', $evtKey);
        $allSelectedEventIds[] = $coreKey;
        $allSelectedEventIds[] = formatBaseEventName($evtKey);
        $allSelectedEventIds[] = formatBaseEventName($coreKey);
        if (isset($baseGroups[$evtKey])) {
            foreach ($baseGroups[$evtKey] as $code) {
                $allSelectedEventIds[] = $code;
                if (isset($EVENTS_MAPPING[$code])) {
                    $allSelectedEventIds[] = $EVENTS_MAPPING[$code];
                }
            }
        }
    }
    $allSelectedEventIds = array_values(array_unique(array_filter($allSelectedEventIds)));

    $targetRegIds = [];
    $targetAllocIds = [];

    if (!empty($allSelectedEventIds)) {
        $upperIds = array_values(array_unique(array_map('strtoupper', $allSelectedEventIds)));
        $allIds   = array_values(array_unique(array_merge($allSelectedEventIds, $upperIds)));
        $inClause = implode(',', array_fill(0, count($allIds), '?'));

        $stmtAlloc = $pdo->prepare("
            SELECT la.id AS alloc_id, er.id AS event_reg_id
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE (UPPER(er.event_name) IN ($inClause) OR UPPER(er.event_code) IN ($inClause))
        ");
        $stmtAlloc->execute(array_merge($allIds, $allIds));
        $allocRows = $stmtAlloc->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allocRows as $ar) {
            $targetAllocIds[] = (int)$ar['alloc_id'];
            $targetRegIds[]   = (int)$ar['event_reg_id'];
        }
    }

    // Fallback search across event_registrations
    $allEr = $pdo->query("SELECT id, event_name, event_code, category FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($selectedKeys as $key) {
        $keyClean = trim((string)$key);
        if (empty($keyClean)) continue;

        $baseKey = preg_replace('/_(issf|nr|para_deaf)$/i', '', $keyClean);
        $baseKeyClean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($baseKey));

        foreach ($allEr as $er) {
            $eName = (string)($er['event_name'] ?? '');
            $eCode = (string)($er['event_code'] ?? '');
            $erBase = getBackendEventBaseType($eName);
            $cleanErBase = preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/_(issf|nr|para_deaf)$/i', '', $erBase)));
            $cleanEName = preg_replace('/[^a-z0-9]/', '', strtolower($eName));
            $cleanECode = preg_replace('/[^a-z0-9]/', '', strtolower($eCode));

            if ($cleanErBase === $baseKeyClean || $cleanEName === $baseKeyClean || $cleanECode === $baseKeyClean || strcasecmp($eName, $keyClean) === 0 || strcasecmp($eCode, $keyClean) === 0) {
                $targetRegIds[] = (int)$er['id'];
            }
        }
    }

    $targetRegIds   = array_values(array_unique($targetRegIds));
    $targetAllocIds = array_values(array_unique($targetAllocIds));

    if (!empty($targetAllocIds) || !empty($targetRegIds)) {
        // Find affected shooters to reset team scores
        if (!empty($targetRegIds)) {
            $inRegClause = implode(',', array_fill(0, count($targetRegIds), '?'));
            $stmtFindRegs = $pdo->prepare("
                SELECT DISTINCT r.reg_id, er.event_name, er.category
                FROM lane_allocations la
                JOIN event_registrations er ON la.event_reg_id = er.id
                JOIN registrations r ON er.user_id = r.id
                WHERE er.id IN ($inRegClause)
            ");
            $stmtFindRegs->execute($targetRegIds);
            $affectedShooters = $stmtFindRegs->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($affectedShooters)) {
                $updMem = $pdo->prepare("
                    UPDATE team_members tm
                    JOIN teams t ON tm.team_id = t.id
                    SET tm.score = 0.00
                    WHERE tm.enrollment_id = ? AND t.event_name = ? AND t.category = ?
                ");
                $teamIdsStmt = $pdo->prepare("
                    SELECT DISTINCT t.id FROM teams t JOIN team_members tm ON t.id = tm.team_id WHERE tm.enrollment_id = ? AND t.event_name = ? AND t.category = ?
                ");
                $updTeam = $pdo->prepare("
                    UPDATE teams t SET t.total_score = (SELECT COALESCE(SUM(tm.score), 0) FROM team_members tm WHERE tm.team_id = t.id) WHERE t.id = ?
                ");
                foreach ($affectedShooters as $shooter) {
                    if (!empty($shooter['reg_id'])) {
                        $updMem->execute([$shooter['reg_id'], $shooter['event_name'], $shooter['category']]);
                        $teamIdsStmt->execute([$shooter['reg_id'], $shooter['event_name'], $shooter['category']]);
                        $teamIds = $teamIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($teamIds as $tId) { $updTeam->execute([$tId]); }
                    }
                }
            }

            // Reset certificate_path
            $stmtClearCert = $pdo->prepare("UPDATE event_registrations er SET er.certificate_path = NULL WHERE er.id IN ($inRegClause)");
            $stmtClearCert->execute($targetRegIds);
        }

        // Delete from score_sheets
        if (!empty($targetAllocIds)) {
            $inAllocClause = implode(',', array_fill(0, count($targetAllocIds), '?'));
            $stmtDelScores = $pdo->prepare("DELETE FROM score_sheets WHERE lane_alloc_id IN ($inAllocClause)");
            $stmtDelScores->execute($targetAllocIds);

            $stmtDelAllocs = $pdo->prepare("DELETE FROM lane_allocations WHERE id IN ($inAllocClause)");
            $stmtDelAllocs->execute($targetAllocIds);
        } elseif (!empty($targetRegIds)) {
            $inRegClause = implode(',', array_fill(0, count($targetRegIds), '?'));
            $stmtDelScores = $pdo->prepare("DELETE ss FROM score_sheets ss JOIN lane_allocations la ON ss.lane_alloc_id = la.id WHERE la.event_reg_id IN ($inRegClause)");
            $stmtDelScores->execute($targetRegIds);

            $stmtDelAllocs = $pdo->prepare("DELETE FROM lane_allocations WHERE event_reg_id IN ($inRegClause)");
            $stmtDelAllocs->execute($targetRegIds);
        }

        if (function_exists('cleanupOrphanManualRegistrations')) {
            cleanupOrphanManualRegistrations($pdo);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Start sheets deleted successfully.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
