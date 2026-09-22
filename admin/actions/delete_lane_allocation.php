<?php
// admin/actions/delete_lane_allocation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$allocId    = (int)($_POST['alloc_id'] ?? 0);
$eventRegId = (int)($_POST['event_reg_id'] ?? 0);

if ($allocId <= 0 && $eventRegId <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid allocation ID.']));
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    if ($allocId > 0) {
        $del = $pdo->prepare("DELETE FROM lane_allocations WHERE id = ?");
        $del->execute([$allocId]);
    } else {
        $del = $pdo->prepare("DELETE FROM lane_allocations WHERE event_reg_id = ?");
        $del->execute([$eventRegId]);
    }
    $affected = $del->rowCount();

    cleanupOrphanManualRegistrations($pdo);

    $pdo->commit();

    if ($affected > 0) {
        exit(json_encode(['success' => true, 'message' => 'Lane allocation removed successfully.']));
    } else {
        exit(json_encode(['success' => false, 'message' => 'Allocation not found or already removed.']));
    }

} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]));
}
