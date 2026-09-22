<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Not logged in.']));
}

$category = strtoupper(trim($_POST['category'] ?? ''));
$validCats = ['ISSF', 'NR', 'PARA_DEAF'];
if (!in_array($category, $validCats, true)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid category.']));
}

try {
    $pdo = getDB();

    $pdo->beginTransaction();

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE category = ? FOR UPDATE");
    $cntStmt->execute([$category]);
    $seq = (int)$cntStmt->fetchColumn() + 1;

    $regId = 'SSA51TN-' . $category . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $pdo->commit();

    exit(json_encode([
        'success' => true,
        'reg_id'  => $regId
    ]));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Generate reg_id error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server error.']));
}
