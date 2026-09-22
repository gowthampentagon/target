<?php
/**
 * admin/actions/clear_manual_edits.php
 * Clears manual inline overrides (custom_name, bib_no, target_serial_no)
 * for selected event start lists in lane_allocations, scoped optionally by date and relay.
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
    $logMsg = '[' . date('Y-m-d H:i:s') . "] clear_manual_edits STRAY OUTPUT: " . substr($stray, 0, 500) . "\n";
    @file_put_contents(dirname(__DIR__, 2) . '/db_connection_error.log', $logMsg, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventsJson = $_POST['events'] ?? '';
$date       = trim($_POST['date'] ?? '');
$relayNo    = (int)($_POST['relay_no'] ?? 0);

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
        $stmtAllocList = $pdo->query("
            SELECT la.id, er.event_name, er.category
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $updVac = $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ?, bib_no = '', custom_name = '', custom_club_name = '', target_serial_no = '' WHERE id = ?");
        foreach ($stmtAllocList as $aRow) {
            $eName = $aRow['event_name'];
            $eCat  = !empty($aRow['category']) ? $aRow['category'] : 'NR';
            $vacantId = getOrCreateVacantEventRegId($pdo, $eName, $eCat, 'MANUAL');
            $updVac->execute([$vacantId, $aRow['id']]);
        }

        // Reset score sheets without deleting any table rows
        $scoreSql = "UPDATE score_sheets ss JOIN lane_allocations la ON ss.lane_alloc_id = la.id SET ss.series_data = NULL, ss.total_val = NULL, ss.penalty_val = NULL, ss.grand_total_val = NULL, ss.remarks = NULL WHERE 1=1";
        $scoreParams = [];
        if (!empty($date)) {
            $scoreSql .= " AND la.scheduled_date = ?";
            $scoreParams[] = $date;
        }
        if ($relayNo > 0) {
            $scoreSql .= " AND la.relay_no = ?";
            $scoreParams[] = $relayNo;
        }
        $stmtScore = $pdo->prepare($scoreSql);
        $stmtScore->execute($scoreParams);

        $pdo->commit();
        exit(json_encode(['success' => true, 'message' => 'Manual cell edits and score sheets cleared successfully without deleting table structure.']));
    }

    $allEr = $pdo->query("SELECT id, event_name, event_code, category FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC);

    $targetRegIds = [];
    foreach ($selectedKeys as $key) {
        $keyClean = trim((string)$key);
        if (empty($keyClean)) continue;

        $categoryFilter = '';
        if (substr($keyClean, -5) === '_issf') {
            $categoryFilter = 'ISSF';
        } elseif (substr($keyClean, -3) === '_nr') {
            $categoryFilter = 'NR';
        } elseif (substr($keyClean, -10) === '_para_deaf') {
            $categoryFilter = 'PARA_DEAF';
        }

        $baseKey = preg_replace('/_(issf|nr|para_deaf)$/i', '', $keyClean);
        $baseKeyClean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($baseKey));

        foreach ($allEr as $er) {
            $eName = (string)($er['event_name'] ?? '');
            $eCode = (string)($er['event_code'] ?? '');
            $erBase = getBackendEventBaseType($eName);
            $cleanErBase = preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/_(issf|nr|para_deaf)$/i', '', $erBase)));
            $cleanEName = preg_replace('/[^a-z0-9]/', '', strtolower($eName));
            $cleanECode = preg_replace('/[^a-z0-9]/', '', strtolower($eCode));

            $match = false;
            if ($cleanErBase === $baseKeyClean || $cleanEName === $baseKeyClean || $cleanECode === $baseKeyClean) {
                $match = true;
            } elseif (!empty($baseKeyClean) && (strpos($cleanErBase, $baseKeyClean) !== false || strpos($cleanEName, $baseKeyClean) !== false)) {
                $match = true;
            } elseif (strcasecmp($eName, $keyClean) === 0 || strcasecmp($eCode, $keyClean) === 0 || strcasecmp($eName, $baseKey) === 0 || strcasecmp($eCode, $baseKey) === 0) {
                $match = true;
            }

            if ($match) {
                if (!empty($categoryFilter)) {
                    $cat = strtoupper((string)($er['category'] ?? ''));
                    if ($categoryFilter === 'NR') {
                        if (!empty($cat) && !in_array($cat, ['NR', 'NR_MQS', 'NR-MQS'], true)) continue;
                    } elseif ($categoryFilter === 'ISSF') {
                        if (!empty($cat) && strpos($cat, 'ISSF') === false) continue;
                    }
                }
                $targetRegIds[] = (int)$er['id'];
            }
        }
    }

    $targetRegIds = array_values(array_unique($targetRegIds));

    if (!empty($targetRegIds)) {
        $inClause = implode(',', array_fill(0, count($targetRegIds), '?'));
        
        $stmtAllocList = $pdo->prepare("
            SELECT la.id, er.event_name, er.category
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE la.event_reg_id IN ($inClause)
        " . (!empty($date) ? " AND la.scheduled_date = '$date' " : "") . ($relayNo > 0 ? " AND la.relay_no = $relayNo " : ""));
        $stmtAllocList->execute($targetRegIds);
        $allocsToReset = $stmtAllocList->fetchAll(PDO::FETCH_ASSOC);

        $updVac = $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ?, bib_no = '', custom_name = '', custom_club_name = '', target_serial_no = '' WHERE id = ?");

        foreach ($allocsToReset as $aRow) {
            $eName = $aRow['event_name'];
            $eCat  = !empty($aRow['category']) ? $aRow['category'] : 'NR';
            $vacantId = getOrCreateVacantEventRegId($pdo, $eName, $eCat, 'MANUAL');
            $updVac->execute([$vacantId, $aRow['id']]);
        }

        // Reset score sheets without deleting any table rows
        $scoreSql = "
            UPDATE score_sheets ss
            JOIN lane_allocations la ON ss.lane_alloc_id = la.id
            SET ss.series_data = NULL, ss.total_val = NULL, ss.penalty_val = NULL, ss.grand_total_val = NULL, ss.remarks = NULL
            WHERE la.event_reg_id IN ($inClause)
        ";
        $scoreParams = $targetRegIds;
        if (!empty($date)) {
            $scoreSql .= " AND la.scheduled_date = ?";
            $scoreParams[] = $date;
        }
        if ($relayNo > 0) {
            $scoreSql .= " AND la.relay_no = ?";
            $scoreParams[] = $relayNo;
        }
        $stmtScore = $pdo->prepare($scoreSql);
        $stmtScore->execute($scoreParams);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Manual edits cleared successfully for the selected filter.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
