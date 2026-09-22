<?php
/**
 * admin/actions/update_reg_action.php
 * Updates registration status and verification state.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$is_verified = (int)($_POST['is_verified'] ?? 0);

if ($id <= 0 || !in_array($status, ['active', 'inactive'])) {
    exit(json_encode(['success' => false, 'message' => 'Invalid data provided.']));
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE registrations SET status = ?, is_verified = ? WHERE id = ?");
    $stmt->execute([$status, $is_verified, $id]);

    exit(json_encode(['success' => true]));
} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error.']));
}
