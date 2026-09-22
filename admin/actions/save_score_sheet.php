<?php
// admin/actions/save_score_sheet.php
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$allocId = (int)($_POST['alloc_id'] ?? 0);
$metaCard = trim((string)($_POST['meta_card'] ?? ''));
$metaMatch = trim((string)($_POST['meta_match'] ?? ''));
$seriesData = trim((string)($_POST['series_data'] ?? ''));
$totalVal = trim((string)($_POST['total_val'] ?? ''));
$penaltyVal = trim((string)($_POST['penalty_val'] ?? ''));
$grandTotalVal = trim((string)($_POST['grand_total_val'] ?? ''));

$customShooterName = trim((string)($_POST['custom_shooter_name'] ?? ''));
$customBib = trim((string)($_POST['custom_bib'] ?? ''));
$customCategory = trim((string)($_POST['custom_category'] ?? ''));
$customDetail = trim((string)($_POST['custom_detail'] ?? ''));
$customLane = trim((string)($_POST['custom_lane'] ?? ''));
$customDate = trim((string)($_POST['custom_date'] ?? ''));
$customTime = trim((string)($_POST['custom_time'] ?? ''));
$remarksVal = trim((string)($_POST['remarks_val'] ?? ''));

$shooterSignature = trim((string)($_POST['shooter_signature'] ?? ''));
$officialSignature = trim((string)($_POST['official_signature'] ?? ''));

if ($allocId <= 0 || empty($seriesData)) {
    if (ob_get_length()) ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

if (!canEdit($allocId)) {
    if (ob_get_length()) ob_clean();
    exit(json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to write or edit this score sheet.']));
}

try {
    $pdo = getDB();

    // Verify lane allocation exists before executing child queries
    $chkAlloc = $pdo->prepare("SELECT id FROM lane_allocations WHERE id = ?");
    $chkAlloc->execute([$allocId]);
    if (!$chkAlloc->fetch()) {
        if (ob_get_length()) ob_clean();
        exit(json_encode(['success' => false, 'message' => 'Allocation record #' . $allocId . ' not found in database.']));
    }

    // Server-side score validation & calculation to prevent 0 / stale total values
    $calcTotal = 0.0;
    $hasAnyScore = false;
    $parsedSeries = json_decode($seriesData, true);
    if (is_array($parsedSeries)) {
        foreach ($parsedSeries as $sKey => $sVal) {
            if (is_numeric($sKey) && is_array($sVal)) {
                foreach ($sVal as $shotKey => $shotVal) {
                    if (is_numeric($shotKey) && $shotVal !== '' && $shotVal !== null) {
                        $v = (float)$shotVal;
                        $calcTotal += $v;
                        $hasAnyScore = true;
                    }
                }
            }
        }
    }
    $calcTotal = round($calcTotal, 2);
    $penaltyFloat = abs((float)$penaltyVal);
    $calcGrandTotal = round($calcTotal - $penaltyFloat, 2);

    $formatScore = function(float $num): string {
        return (fmod($num, 1.0) == 0.0) ? (string)(int)$num : (string)$num;
    };

    if ($hasAnyScore) {
        if (empty($totalVal) || $totalVal === '0' || abs((float)$totalVal - $calcTotal) > 0.01) {
            $totalVal = $formatScore($calcTotal);
        }
        if (empty($grandTotalVal) || $grandTotalVal === '0' || abs((float)$grandTotalVal - $calcGrandTotal) > 0.01) {
            $grandTotalVal = $formatScore($calcGrandTotal);
        }
    }

    // Ensure edit_count, shooter_signature, and official_signature columns exist in score_sheets table
    try {
        $pdo->exec("ALTER TABLE `score_sheets` ADD COLUMN `edit_count` INT NOT NULL DEFAULT 0 AFTER `remarks`");
    } catch (Throwable $colEx) {}
    try {
        $pdo->exec("ALTER TABLE `score_sheets` ADD COLUMN `shooter_signature` LONGTEXT NULL AFTER `edit_count`, ADD COLUMN `official_signature` LONGTEXT NULL AFTER `shooter_signature`");
    } catch (Throwable $colEx) {}

    $pdo->beginTransaction();

    // Fetch current lane allocation details to see if we can save custom values as NULL when they match live values
    $stmtAlloc = $pdo->prepare("
        SELECT la.relay_no, la.lane_no, la.scheduled_date, la.start_time, la.bib_no, la.target_serial_no, la.custom_name,
               r.first_name, r.last_name, er.category, er.match_no, er.event_code
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        JOIN registrations r ON er.user_id = r.id
        WHERE la.id = ?
        LIMIT 1
    ");
    $stmtAlloc->execute([$allocId]);
    $alloc = $stmtAlloc->fetch();

    if ($alloc) {
        $officialName = !empty(trim((string)$alloc['custom_name'])) ? $alloc['custom_name'] : trim(($alloc['first_name'] ?? '') . ' ' . ($alloc['last_name'] ?? ''));
        $officialBib = (string)($alloc['bib_no'] ?? '');
        $officialCategory = (string)($alloc['category'] ?? '');
        $officialDetail = (string)($alloc['relay_no'] ?? '');
        $officialLane = (string)($alloc['lane_no'] ?? '');
        $officialDate = !empty($alloc['scheduled_date']) ? date('d-M-Y', strtotime((string)$alloc['scheduled_date'])) : '';
        $officialTime = !empty($alloc['start_time']) ? substr((string)$alloc['start_time'], 0, 5) : '';
        $officialCard = (string)($alloc['target_serial_no'] ?? '');
        $officialMatch = !empty($alloc['match_no']) ? (string)$alloc['match_no'] : ((string)($alloc['event_code'] ?? ''));

        if (strcasecmp($customShooterName, $officialName) === 0) $customShooterName = null;
        if (strcasecmp($customBib, $officialBib) === 0) $customBib = null;
        if (strcasecmp($customCategory, $officialCategory) === 0) $customCategory = null;
        if (strcasecmp($customDetail, $officialDetail) === 0) $customDetail = null;
        if (strcasecmp($customLane, $officialLane) === 0) $customLane = null;
        if (strcasecmp(str_replace(' ', '', $customDate), str_replace(' ', '', $officialDate)) === 0) $customDate = null;
        if (strcasecmp($customTime, $officialTime) === 0) $customTime = null;
        if (strcasecmp($metaCard, $officialCard) === 0) $metaCard = null;
        if (strcasecmp($metaMatch, $officialMatch) === 0) $metaMatch = null;
    }
    
    // Check if score sheet already exists
    $stmt = $pdo->prepare("SELECT id, custom_bib FROM score_sheets WHERE lane_alloc_id = ?");
    $stmt->execute([$allocId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $up = $pdo->prepare("
            UPDATE score_sheets 
            SET meta_card = ?, meta_match = ?, series_data = ?, total_val = ?, penalty_val = ?, grand_total_val = ?,
                custom_shooter_name = ?, custom_bib = ?, custom_category = ?, custom_detail = ?, custom_lane = ?, custom_date = ?, custom_time = ?,
                remarks = ?, shooter_signature = ?, official_signature = ?, edit_count = edit_count + 1
            WHERE lane_alloc_id = ?
        ");
        $up->execute([
            $metaCard !== '' ? $metaCard : null,
            $metaMatch !== '' ? $metaMatch : null,
            $seriesData, $totalVal, $penaltyVal, $grandTotalVal,
            $customShooterName, $customBib, $customCategory, $customDetail, $customLane, $customDate, $customTime,
            $remarksVal !== '' ? $remarksVal : null,
            $shooterSignature !== '' ? $shooterSignature : null,
            $officialSignature !== '' ? $officialSignature : null,
            $allocId
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO score_sheets (lane_alloc_id, meta_card, meta_match, series_data, total_val, penalty_val, grand_total_val,
                                      custom_shooter_name, custom_bib, custom_category, custom_detail, custom_lane, custom_date, custom_time, remarks, shooter_signature, official_signature, edit_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $ins->execute([
            $allocId,
            $metaCard !== '' ? $metaCard : null,
            $metaMatch !== '' ? $metaMatch : null,
            $seriesData, $totalVal, $penaltyVal, $grandTotalVal,
            $customShooterName, $customBib, $customCategory, $customDetail, $customLane, $customDate, $customTime,
            $remarksVal !== '' ? $remarksVal : null,
            $shooterSignature !== '' ? $shooterSignature : null,
            $officialSignature !== '' ? $officialSignature : null
        ]);
    }

    // Sync score to team_events if shooter is part of a team
    $stmtInfo = $pdo->prepare("
        SELECT r.reg_id, er.event_name, er.category
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        JOIN registrations r ON er.user_id = r.id
        WHERE la.id = ?
        LIMIT 1
    ");
    $stmtInfo->execute([$allocId]);
    $allocInfo = $stmtInfo->fetch();

    if ($allocInfo) {
        $eventName = $allocInfo['event_name'];
        $category = $allocInfo['category'];
        $officialRegId = $allocInfo['reg_id'];
        $prevCustomBib = $existing ? ($existing['custom_bib'] ?? '') : '';

        $idsToReset = [];
        $idToSync = null;

        if (empty($customBib)) {
            $idToSync = $officialRegId;
            if (!empty($prevCustomBib) && $prevCustomBib !== $officialRegId) {
                $idsToReset[] = $prevCustomBib;
            }
        } else {
            $resolvedRegId = resolveRealRegId($pdo, $customBib);
            $stmtCheck = $pdo->prepare("
                SELECT r.reg_id
                FROM event_registrations er
                JOIN registrations r ON er.user_id = r.id
                WHERE r.reg_id = ? AND er.event_name = ? AND er.category = ? AND LOWER(er.status) IN ('approved', 'active', 'pending')
                LIMIT 1
            ");
            $stmtCheck->execute([$resolvedRegId, $eventName, $category]);
            $checkedInfo = $stmtCheck->fetch();

            if ($checkedInfo) {
                $idToSync = $checkedInfo['reg_id'];
                if (!empty($officialRegId) && $officialRegId !== $idToSync) {
                    $idsToReset[] = $officialRegId;
                }
                if (!empty($prevCustomBib) && $prevCustomBib !== $idToSync && $prevCustomBib !== $officialRegId) {
                    $idsToReset[] = $prevCustomBib;
                }
            } else {
                if (!empty($officialRegId)) {
                    $idsToReset[] = $officialRegId;
                }
                if (!empty($prevCustomBib) && $prevCustomBib !== $officialRegId) {
                    $idsToReset[] = $prevCustomBib;
                }
            }
        }

        if (!empty($idsToReset)) {
            $updResetMem = $pdo->prepare("
                UPDATE team_members tm
                JOIN teams t ON tm.team_id = t.id
                SET tm.score = 0.00
                WHERE tm.enrollment_id = ?
                  AND t.event_name = ?
                  AND t.category = ?
            ");
            foreach ($idsToReset as $resetId) {
                $updResetMem->execute([$resetId, $eventName, $category]);
                
                $teamIdsStmt = $pdo->prepare("
                    SELECT DISTINCT t.id
                    FROM teams t
                    JOIN team_members tm ON t.id = tm.team_id
                    WHERE tm.enrollment_id = ?
                      AND t.event_name = ?
                      AND t.category = ?
                ");
                $teamIdsStmt->execute([$resetId, $eventName, $category]);
                $teamIds = $teamIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($teamIds)) {
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
        }

        if (!empty($idToSync)) {
            $scoreFloat = (float)$grandTotalVal;
            $bibNoToSync = function_exists('formatBibNo') ? formatBibNo($idToSync) : $idToSync;

            $updSyncMem = $pdo->prepare("
                UPDATE team_members tm
                JOIN teams t ON tm.team_id = t.id
                SET tm.score = ?
                WHERE (tm.enrollment_id = ? OR tm.enrollment_id = ?)
                  AND (t.event_name = ? OR t.event_name = ?)
            ");
            $updSyncMem->execute([$scoreFloat, $idToSync, $bibNoToSync, $eventName, $eventName]);

            $teamIdsStmt = $pdo->prepare("
                SELECT DISTINCT t.id
                FROM teams t
                JOIN team_members tm ON t.id = tm.team_id
                WHERE (tm.enrollment_id = ? OR tm.enrollment_id = ?)
                  AND (t.event_name = ? OR t.event_name = ?)
            ");
            $teamIdsStmt->execute([$idToSync, $bibNoToSync, $eventName, $eventName]);
            $teamIds = $teamIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($teamIds)) {
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
    }
    
    $pdo->commit();
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => true, 'message' => 'Scores saved successfully.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

