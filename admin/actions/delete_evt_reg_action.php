<?php
/**
 * admin/actions/delete_evt_reg_action.php
 * Deletes an event registration (Superadmin only).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();
checkModuleAccess('event_registrations.php'); // Enforce passcode permission

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { exit(json_encode(['success'=>false,'message'=>'Invalid ID.'])); }

try {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM event_registrations WHERE id=?")->execute([$id]);
    cleanupOrphanManualRegistrations($pdo);
    exit(json_encode(['success'=>true]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'message'=>'Server error.']));
}
