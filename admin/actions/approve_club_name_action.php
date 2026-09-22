<?php
// admin/actions/approve_club_name_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();
checkModuleAccess('registrations.php'); // Enforce passcode permission

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$userId = (int)($_POST['id'] ?? 0);
if ($userId <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid participant.']));
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT club_name, club_name_pending, club_name_change_pending FROM registrations WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        exit(json_encode(['success' => false, 'message' => 'Participant not found.']));
    }

    if (empty($user['club_name_change_pending']) || empty($user['club_name_pending'])) {
        exit(json_encode(['success' => false, 'message' => 'No pending club name change found.']));
    }

    $pdo->prepare(
        "UPDATE registrations SET club_name = ?, club_name_pending = NULL, club_name_change_pending = 0, club_name_change_approved_at = NOW() WHERE id = ?"
    )->execute([$user['club_name_pending'], $userId]);

    exit(json_encode(['success' => true, 'message' => 'Club name approved successfully.']));
} catch (Exception $e) {
    error_log('Approve club name error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Unable to approve club name change right now.']));
}
