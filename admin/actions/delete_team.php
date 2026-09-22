<?php
// admin/actions/delete_team.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$teamId = (int)($_POST['team_id'] ?? 0);

if ($teamId <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

try {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    
    echo json_encode(['success' => true, 'message' => 'Team deleted successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
