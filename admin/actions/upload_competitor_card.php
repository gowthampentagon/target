<?php
// admin/actions/upload_competitor_card.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0 || empty($_FILES['card_pdf'])) {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

$dir = dirname(__DIR__, 2) . '/uploads/competitor_cards';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$destFile = $dir . '/competitor_card_' . $userId . '.pdf';
if (move_uploaded_file($_FILES['card_pdf']['tmp_name'], $destFile)) {
    echo json_encode(['success' => true, 'message' => 'Competitor card uploaded successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save competitor card PDF.']);
}
