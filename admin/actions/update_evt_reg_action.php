<?php
/**
 * admin/actions/update_evt_reg_action.php
 * Updates event registration status and admin remarks.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

$id      = (int)($_POST['id'] ?? 0);
$status  = $_POST['status'] ?? '';
$remarks = trim($_POST['admin_remarks'] ?? '');

if ($id <= 0 || !in_array($status, ['pending','approved','rejected'])) {
    exit(json_encode(['success'=>false,'message'=>'Invalid data.']));
}

try {
    $pdo = getDB();
    $pdo->prepare("UPDATE event_registrations SET status=?, admin_remarks=? WHERE id=?")
        ->execute([$status, $remarks ?: null, $id]);
    exit(json_encode(['success'=>true]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'message'=>'Server error.']));
}
