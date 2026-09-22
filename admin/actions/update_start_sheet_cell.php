<?php
// admin/actions/update_start_sheet_cell.php
declare(strict_types=1);
ob_start(); // Catch any stray PHP warnings before our JSON output
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

// Discard any stray output from includes (PHP warnings, notices)
$stray = ob_get_clean();
if (!empty($stray)) {
    $logMsg = '[' . date('Y-m-d H:i:s') . "] update_start_sheet_cell STRAY OUTPUT: " . substr($stray, 0, 500) . "\n";
    @file_put_contents(dirname(__DIR__, 2) . '/db_connection_error.log', $logMsg, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$allocId = (int)($_POST['alloc_id'] ?? 0);
$col     = trim($_POST['col'] ?? '');
$val     = trim($_POST['val'] ?? '');

if ($allocId <= 0 || empty($col)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

try {
    $pdo = getDB();
    
    if ($col === 'rly') {
        $stmtEv = $pdo->prepare("
            SELECT er.event_name, la.scheduled_date, la.relay_no, la.event_reg_id, la.lane_no
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE la.id = ?
            LIMIT 1
        ");
        $stmtEv->execute([$allocId]);
        $allocDet = $stmtEv->fetch();
        
        if ($allocDet) {
            $targetRelay = (int)$val;
            $baseType = getBackendEventBaseType((string)$allocDet['event_name']);
            $panelLabel = formatBaseEventName($baseType);
            $stmtLimit = $pdo->prepare("
                SELECT relay_limit 
                FROM relay_schedules 
                WHERE event_name = ? AND schedule_name = '' AND scheduled_date = ? AND relay_no = ?
                LIMIT 1
            ");
            $stmtLimit->execute([$panelLabel, $allocDet['scheduled_date'], $targetRelay]);
            $customLimit = $stmtLimit->fetchColumn();
            $capacity = ($customLimit !== false && $customLimit !== null) ? (int)$customLimit : getRangeCapacity((string)$allocDet['event_name']);
            
            $wt = getBackendEventBaseType((string)$allocDet['event_name']);
            if (empty($wt)) {
                $wt = strtolower(str_replace(' ', '_', (string)$allocDet['event_name']));
            }
            $categoryFilter = '';
            if (substr($wt, -5) === '_issf') {
                $categoryFilter = 'ISSF';
            } elseif (substr($wt, -3) === '_nr') {
                $categoryFilter = 'NR';
            } elseif (substr($wt, -10) === '_para_deaf') {
                $categoryFilter = 'PARA_DEAF';
            }
            
            $allEventNames = [];
            foreach ($EVENTS_MAPPING as $id => $fullName) {
                if (getBackendEventBaseType($fullName) === $wt) {
                    $allEventNames[] = $fullName;
                }
            }
            $allEventNames[] = (string)$allocDet['event_name'];
            $allEventNames = array_unique($allEventNames);
            $inClause = implode(',', array_fill(0, count($allEventNames), '?'));
            
            $queryParams = array_merge([$allocDet['scheduled_date'], $targetRelay, $allocId], $allEventNames);
            
            $catQuery = "";
            if (!empty($categoryFilter)) {
                if ($categoryFilter === 'NR') {
                    $catQuery = " AND er.category IN ('NR', 'NR_MQS') ";
                } elseif ($wt === '10m_air_pistol_issf') {
                    $catQuery = "";
                } else {
                    $catQuery = " AND er.category = ? ";
                    $queryParams[] = $categoryFilter;
                }
            }
            
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) 
                FROM lane_allocations la
                JOIN event_registrations er ON la.event_reg_id = er.id
                WHERE la.scheduled_date = ? AND la.relay_no = ? AND la.id != ? AND er.event_name IN ($inClause) $catQuery
            ");
            $stmtCount->execute($queryParams);
            $currentAllocatedCount = (int)$stmtCount->fetchColumn();
            
            if ($currentAllocatedCount >= $capacity) {
                exit(json_encode([
                    'success' => false,
                    'message' => "Relay R-{$targetRelay} on {$allocDet['scheduled_date']} is already full ({$currentAllocatedCount} shooters allocated, limit is {$capacity}). Please select another relay."
                ]));
            }

            // Check if current lane_no matches someone else in target relay on the same range type
            $currentLaneNo = (int)($allocDet['lane_no'] ?? 0);
            $resetLane = false;
            if ($currentLaneNo > 0) {
                $dupLane = $pdo->prepare("
                    SELECT la.id, er.event_name 
                    FROM lane_allocations la
                    JOIN event_registrations er ON la.event_reg_id = er.id
                    WHERE la.scheduled_date = ? AND la.relay_no = ? AND la.lane_no = ? AND la.id != ?
                ");
                $dupLane->execute([$allocDet['scheduled_date'], $targetRelay, $currentLaneNo, $allocId]);
                $clashes = $dupLane->fetchAll();
                
                $currentEventName = (string)$allocDet['event_name'];
                $currentRangeType = getRangeType($currentEventName);
                
                foreach ($clashes as $clash) {
                    if (getRangeType((string)$clash['event_name']) === $currentRangeType) {
                        $resetLane = true;
                        break;
                    }
                }
            }

            if ($resetLane) {
                $stmt = $pdo->prepare("UPDATE lane_allocations SET relay_no = ?, lane_no = 0 WHERE id = ?");
                $stmt->execute([$targetRelay, $allocId]);
            } else {
                $stmt = $pdo->prepare("UPDATE lane_allocations SET relay_no = ? WHERE id = ?");
                $stmt->execute([$targetRelay, $allocId]);
            }
        }
    } elseif ($col === 'fp') {
        $stmtEv = $pdo->prepare("
            SELECT er.event_name, la.scheduled_date, la.relay_no, la.event_reg_id
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE la.id = ?
            LIMIT 1
        ");
        $stmtEv->execute([$allocId]);
        $allocDet = $stmtEv->fetch();
        
        if ($allocDet) {
            $baseType = getBackendEventBaseType((string)$allocDet['event_name']);
            $panelLabel = formatBaseEventName($baseType);
            $stmtLimit = $pdo->prepare("
                SELECT relay_limit 
                FROM relay_schedules 
                WHERE event_name = ? AND schedule_name = '' AND scheduled_date = ? AND relay_no = ?
                LIMIT 1
            ");
            $stmtLimit->execute([$panelLabel, $allocDet['scheduled_date'], $allocDet['relay_no']]);
            $customLimit = $stmtLimit->fetchColumn();
            $capacity = ($customLimit !== false && $customLimit !== null) ? (int)$customLimit : getRangeCapacity((string)$allocDet['event_name']);
            $laneNo = (int)$val;
            
            if ($laneNo > $capacity) {
                exit(json_encode([
                    'success' => false, 
                    'message' => "Lane number (FP) {$laneNo} exceeds the maximum limit of {$capacity} firing points for this relay."
                ]));
            }
            
            if ($laneNo > 0) {
                $dupLane = $pdo->prepare("
                    SELECT la.id, r.first_name, r.last_name, er.event_name 
                    FROM lane_allocations la
                    JOIN event_registrations er ON la.event_reg_id = er.id
                    JOIN registrations r ON er.user_id = r.id
                    WHERE la.scheduled_date = ? AND la.relay_no = ? AND la.lane_no = ? AND la.id != ?
                ");
                $dupLane->execute([$allocDet['scheduled_date'], $allocDet['relay_no'], $laneNo, $allocId]);
                $clashes = $dupLane->fetchAll();
                
                $currentEventName = (string)$allocDet['event_name'];
                $currentRangeType = getRangeType($currentEventName);
                
                foreach ($clashes as $clash) {
                    if (getRangeType((string)$clash['event_name']) === $currentRangeType) {
                        $clashName = trim(($clash['first_name'] ?? '') . ' ' . ($clash['last_name'] ?? ''));
                        if ($clashName === '') {
                            $clashName = 'another competitor';
                        }
                        exit(json_encode([
                            'success' => false, 
                            'message' => "Lane {$laneNo} in Relay {$allocDet['relay_no']} on {$allocDet['scheduled_date']} is already allocated to " . htmlspecialchars($clashName) . "."
                        ]));
                    }
                }
            }
        }

        $stmt = $pdo->prepare("UPDATE lane_allocations SET lane_no = ? WHERE id = ?");
        $stmt->execute([(int)$val, $allocId]);
    } elseif ($col === 'st_time') {
        $stmt = $pdo->prepare("UPDATE lane_allocations SET start_time = ? WHERE id = ?");
        $stmt->execute([$val, $allocId]);
    } elseif ($col === 'bib_enroll_id') {
        $newBib = $val !== '' ? $val : null;
        
        $stmtInfo = $pdo->prepare("
            SELECT la.bib_no AS old_bib, la.scheduled_date, la.relay_no, er.event_name, er.category, r.club_name, r.reg_id AS official_reg_id,
                   ss.grand_total_val, ss.id AS score_sheet_id
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
            WHERE la.id = ?
            LIMIT 1
        ");
        $stmtInfo->execute([$allocId]);
        $allocInfo = $stmtInfo->fetch();
        
        if ($allocInfo) {
            $oldBib = $allocInfo['old_bib'];
            $eventName = $allocInfo['event_name'];
            $category = $allocInfo['category'];
            $officialRegId = $allocInfo['official_reg_id'];
            $scoreVal = $allocInfo['score_sheet_id'] ? (float)$allocInfo['grand_total_val'] : null;
            
            $clearedAllocId = null;
            $targetEventRegId = null;
            
            if (empty($newBib)) {
                // Reset event_reg_id to a vacant registration ID so the competitor is 100% unlinked from database!
                $vacantRegId = getOrCreateVacantEventRegId($pdo, $eventName, 'NR', 'MANUAL');
                $updVac = $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ?, bib_no = '', custom_name = '', custom_club_name = '', target_serial_no = '' WHERE id = ?");
                $updVac->execute([$vacantRegId, $allocId]);

                if ($allocInfo['score_sheet_id']) {
                    $updSS = $pdo->prepare("UPDATE score_sheets SET series_data = NULL, total_val = NULL, penalty_val = NULL, grand_total_val = NULL, remarks = NULL WHERE id = ?");
                    $updSS->execute([$allocInfo['score_sheet_id']]);
                }

                exit(json_encode([
                    'success' => true, 
                    'message' => 'Allocation cleared successfully in database.',
                    'resolved_bib' => '',
                    'resolved_name' => '',
                    'resolved_club' => ''
                ]));
            } else {
                // Find the base key of the current allocation's event
                $allocEventFullName = $EVENTS_MAPPING[$eventName] ?? $eventName;
                $baseKey = getBackendEventBaseType($allocEventFullName);
                if (empty($baseKey)) {
                    $baseKey = strtolower(str_replace(' ', '_', $eventName));
                }
                
                $cleanBaseKey = preg_replace('/[^a-zA-Z0-9]/', '', $baseKey);
                $matchingEventIds = [];
                foreach ($EVENTS_MAPPING as $id => $fullName) {
                    $cleanMapBase = preg_replace('/[^a-zA-Z0-9]/', '', getBackendEventBaseType($fullName));
                    if ($cleanMapBase === $cleanBaseKey) {
                        $matchingEventIds[] = $id;
                        $matchingEventIds[] = $fullName;
                    }
                }
                $matchingEventIds[] = $baseKey;
                $matchingEventIds[] = $eventName;
                $matchingEventIds[] = $allocEventFullName;
                $matchingEventIds = array_values(array_unique(array_filter($matchingEventIds)));
                
                $inClause = implode(',', array_fill(0, count($matchingEventIds), '?'));

                $formattedBib = !empty($newBib) ? formatBibNo($newBib) : '';
                $digitsBib = preg_replace('/\D/', '', (string)$newBib);
                $shortDigits = (strlen($digitsBib) === 5 && $digitsBib[0] === '5') ? substr($digitsBib, 1) : $digitsBib;

                $whereConds = [
                    "r.reg_id = ?",
                    "er.event_reg_id = ?",
                    "r.reg_id = ?",
                    "UPPER(TRIM(CONCAT(r.first_name, ' ', r.last_name))) = UPPER(?)"
                ];
                $whereParams = [$newBib, $newBib, $formattedBib, $newBib];

                if (!empty($digitsBib)) {
                    $whereConds[] = "r.reg_id LIKE ?";
                    $whereParams[] = '%' . $digitsBib;
                }
                if (!empty($shortDigits) && $shortDigits !== $digitsBib) {
                    $whereConds[] = "r.reg_id LIKE ?";
                    $whereParams[] = '%' . $shortDigits;
                }

                $whereClause = "(" . implode(" OR ", $whereConds) . ")";

                // Fetch all registration records for candidate competitor
                $stmtRegs = $pdo->prepare("
                    SELECT er.id AS event_reg_id, er.event_name, er.event_code, er.category,
                           TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS resolved_name, r.club_name, r.district, r.reg_id
                    FROM registrations r
                    JOIN event_registrations er ON r.id = er.user_id
                    WHERE r.reg_id NOT LIKE 'VACANT-%' AND r.reg_id NOT LIKE 'MANUAL-%'
                      AND {$whereClause}
                    ORDER BY er.id DESC
                ");
                $stmtRegs->execute($whereParams);
                $allUserRegs = $stmtRegs->fetchAll(PDO::FETCH_ASSOC);

                $realRow = null;
                foreach ($allUserRegs as $candidate) {
                    $candBase = getBackendEventBaseType((string)$candidate['event_name']);
                    $candClean = preg_replace('/[^a-zA-Z0-9]/', '', $candBase);
                    if ($candClean === $cleanBaseKey || (strpos($candClean, $cleanBaseKey) !== false || strpos($cleanBaseKey, $candClean) !== false)) {
                        $realRow = $candidate;
                        break;
                    }
                }

                if (!$realRow && !empty($allUserRegs)) {
                    $realRow = $allUserRegs[0];
                }

                $targetEventRegId = $realRow && !empty($realRow['event_reg_id']) ? (int)$realRow['event_reg_id'] : null;
                $resolvedName = $realRow && !empty($realRow['resolved_name']) ? trim($realRow['resolved_name']) : '';
                $resolvedClub = ($realRow && !empty($realRow['club_name']) && trim($realRow['club_name']) !== '' && trim($realRow['club_name']) !== '-') 
                    ? $realRow['club_name'] 
                    : ($realRow && !empty($realRow['district']) ? $realRow['district'] : '—');
                $resolvedBib = $realRow && !empty($realRow['reg_id']) ? formatBibNo($realRow['reg_id'], $newBib) : (!empty($newBib) ? formatBibNo($newBib) : '');

                // Fetch existing custom_name from lane_allocations to preserve any manually entered name
                $stmtCurName = $pdo->prepare("SELECT custom_name FROM lane_allocations WHERE id = ?");
                $stmtCurName->execute([$allocId]);
                $curCustomName = trim((string)$stmtCurName->fetchColumn());

                $savedBib  = !empty($resolvedBib) ? $resolvedBib : (!empty($formattedBib) ? $formattedBib : $newBib);
                $savedName = !empty($resolvedName) ? $resolvedName : (!empty($curCustomName) ? $curCustomName : '');

                // ALWAYS save bib_no and custom_name into lane_allocations table so data persists on refresh & page navigation
                $stmt = $pdo->prepare("UPDATE lane_allocations SET bib_no = ?, custom_name = ? WHERE id = ?");
                $stmt->execute([$savedBib, $savedName, $allocId]);

                if ($targetEventRegId) {
                    $stmtLink = $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ? WHERE id = ?");
                    $stmtLink->execute([$targetEventRegId, $allocId]);
                }
            }
            
            // Update score_sheets custom_bib if score sheet exists
            if ($allocInfo['score_sheet_id']) {
                $updSS = $pdo->prepare("UPDATE score_sheets SET custom_bib = ? WHERE id = ?");
                $updSS->execute([$newBib !== null ? $newBib : '', $allocInfo['score_sheet_id']]);
            }
            
            $idsToReset = [];
            $idToSync = null;
            
            if (!empty($oldBib)) {
                $idsToReset[] = $oldBib;
            }
            if (!empty($officialRegId) && $officialRegId !== $oldBib) {
                $idsToReset[] = $officialRegId;
            }
            
            if (!empty($newBib) && $scoreVal !== null) {
                $idToSync = $newBib;
                $idsToReset = array_filter($idsToReset, function($id) use ($idToSync) {
                    return $id !== $idToSync;
                });
            }
            
            if (!empty($idsToReset)) {
                $updResetMem = $pdo->prepare("
                    UPDATE team_members tm
                    JOIN teams t ON tm.team_id = t.id
                    SET tm.score = 0.00, tm.lane_alloc_id = NULL
                    WHERE tm.enrollment_id = ?
                      AND t.event_name = ?
                      AND t.category = ?
                ");
                
                $teamIdsStmt = $pdo->prepare("
                    SELECT DISTINCT t.id
                    FROM teams t
                    JOIN team_members tm ON t.id = tm.team_id
                    WHERE tm.enrollment_id = ?
                      AND t.event_name = ?
                      AND t.category = ?
                ");
                
                $updTeam = $pdo->prepare("
                    UPDATE teams t
                    SET t.total_score = (
                        SELECT COALESCE(SUM(tm.score), 0)
                        FROM team_members tm
                        WHERE tm.team_id = t.id
                    )
                    WHERE t.id = ?
                ");
                
                foreach ($idsToReset as $resetId) {
                    $updResetMem->execute([$resetId, $eventName, $category]);
                    
                    $teamIdsStmt->execute([$resetId, $eventName, $category]);
                    $teamIds = $teamIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($teamIds as $tId) {
                        $updTeam->execute([$tId]);
                    }
                }
            }
            
            if (!empty($idToSync)) {
                $updSyncMem = $pdo->prepare("
                    UPDATE team_members tm
                    JOIN teams t ON tm.team_id = t.id
                    SET tm.score = ?, tm.lane_alloc_id = ?
                    WHERE tm.enrollment_id = ?
                      AND t.event_name = ?
                      AND t.category = ?
                ");
                $updSyncMem->execute([$scoreVal, $allocId, $idToSync, $eventName, $category]);
                
                $teamIdsStmt = $pdo->prepare("
                    SELECT DISTINCT t.id
                    FROM teams t
                    JOIN team_members tm ON t.id = tm.team_id
                    WHERE tm.enrollment_id = ?
                      AND t.event_name = ?
                      AND t.category = ?
                ");
                $teamIdsStmt->execute([$idToSync, $eventName, $category]);
                $teamIds = $teamIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $updTeam = $pdo->prepare("
                    UPDATE teams t
                    SET t.total_score = (
                        SELECT COALESCE(SUM(tm.score), 0)
                        FROM team_members tm
                        WHERE tm.team_id = t.id
                    )
                    WHERE t.id = ?
                ");
                foreach ($teamIds as $tId) {
                    $updTeam->execute([$tId]);
                }
            }
        }
    } elseif ($col === 'target_serial_no') {
        $stmt = $pdo->prepare("UPDATE lane_allocations SET target_serial_no = ? WHERE id = ?");
        $stmt->execute([$val !== '' ? $val : null, $allocId]);
    } elseif ($col === 'name') {
        $stmt = $pdo->prepare("UPDATE lane_allocations SET custom_name = ? WHERE id = ?");
        $stmt->execute([$val !== '' ? $val : null, $allocId]);
    } elseif ($col === 'club_name') {
        $stmt = $pdo->prepare("UPDATE lane_allocations SET custom_club_name = ? WHERE id = ?");
        $stmt->execute([$val !== '' ? $val : null, $allocId]);
    } elseif ($col === 'score_sheet') {
        $stmt = $pdo->prepare("UPDATE lane_allocations SET score_sheet = ? WHERE id = ?");
        $stmt->execute([$val !== '' ? $val : null, $allocId]);
    }
    
    echo json_encode([
        'success'          => true, 
        'message'          => 'Updated successfully.', 
        'resolved_name'    => $resolvedName ?? '',
        'resolved_bib'     => $resolvedBib ?? '',
        'resolved_club'    => $resolvedClub ?? '',
        'cleared_alloc_id' => $clearedAllocId ?? null
    ]);
} catch (Exception $e) {
    $logMsg = '[' . date('Y-m-d H:i:s') . '] update_start_sheet_cell ERROR: col=' . ($col ?? '?') . ' alloc_id=' . ($allocId ?? '?') . ' => ' . $e->getMessage() . "\n";
    @file_put_contents(dirname(__DIR__, 2) . '/db_connection_error.log', $logMsg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
