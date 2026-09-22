<?php
/**
 * admin/actions/check_club_quota.php
 * Endpoint to check if adding a participant exceeds the configured club quota for a relay/date.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$clubName      = trim($_REQUEST['club_name'] ?? '');
$scheduledDate = trim($_REQUEST['scheduled_date'] ?? '');
$relayNo       = (int)($_REQUEST['relay_no'] ?? 0);
$eventBaseType = trim($_REQUEST['event_base_type'] ?? '');

if (empty($clubName) || empty($scheduledDate)) {
    echo json_encode(['success' => true, 'quota' => 0, 'current_count' => 0, 'requires_warning' => false]);
    exit;
}

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    // 1. Get total configured quota for this club on this date across ALL relays
    $stmtQ = $pdo->prepare("
        SELECT COALESCE(SUM(quota), 0) 
        FROM relay_club_quotas 
        WHERE (championship_id = ? OR championship_id IS NULL OR championship_id = 1) 
          AND LOWER(club_name) = LOWER(?) 
          AND scheduled_date = ? 
    ");
    $stmtQ->execute([$cid, $clubName, $scheduledDate]);
    $totalDayQuota = (int)($stmtQ->fetchColumn() ?: 0);

    // 2. Count current allocations for this club on this date across ALL relays
    $stmtC = $pdo->prepare("
        SELECT COUNT(DISTINCT la.id) 
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        JOIN registrations r ON er.user_id = r.id
        WHERE (er.championship_id = ? OR er.championship_id IS NULL OR er.championship_id = 1)
          AND (LOWER(r.club_name) = LOWER(?) OR LOWER(r.district) = LOWER(?))
          AND la.scheduled_date = ?
    ");
    $stmtC->execute([$cid, $clubName, $clubName, $scheduledDate]);
    $currentDayCount = (int)$stmtC->fetchColumn();

    // Requires warning ONLY if the club has ZERO quota AND ZERO allocations on this day
    $requiresWarning = ($totalDayQuota === 0 && $currentDayCount === 0);

    echo json_encode([
        'success'          => true,
        'quota'            => $totalDayQuota,
        'current_count'    => $currentDayCount,
        'requires_warning' => $requiresWarning,
        'message'          => "Club {$clubName} has no allocations set for {$scheduledDate}."
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Quota check error: ' . $e->getMessage()]);
}
