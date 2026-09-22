<?php
// admin/actions/delete_competitor_card.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

try {
    $userId = (int)($_POST['user_id'] ?? 0);
    $all = (int)($_POST['all'] ?? 0);
    
    $dir = dirname(__DIR__, 2) . '/uploads/competitor_cards';
    
    if ($all === 1) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*.pdf');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        echo json_encode(['success' => true, 'message' => 'All competitor cards deleted successfully.']);
        exit;
    }
    
    if ($userId <= 0) {
        exit(json_encode(['success' => false, 'message' => 'Invalid user ID.']));
    }
    
    $filePath = $dir . '/competitor_card_' . $userId . '.pdf';
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            echo json_encode(['success' => true, 'message' => 'Competitor card deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete competitor card file.']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'Competitor card was not found or already deleted.']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
