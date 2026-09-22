<?php
// admin/actions/upload_certificate.php
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
$eventName = trim($_POST['event_name'] ?? '');
$category = trim($_POST['category'] ?? '');

if ($userId <= 0 || empty($_FILES['cert_pdf']) || empty($eventName) || empty($category)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

try {
    $pdo = getDB();
    
    $dir = dirname(__DIR__, 2) . '/uploads/certificates';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    // Create a safe, unique filename per user + event + category
    $safeEvent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $eventName);
    $safeCat = preg_replace('/[^a-zA-Z0-9_-]/', '_', $category);
    $fileName = 'certificate_' . $userId . '_' . $safeEvent . '_' . $safeCat . '.pdf';
    $destFile = $dir . '/' . $fileName;
    
    if (move_uploaded_file($_FILES['cert_pdf']['tmp_name'], $destFile)) {
        // Save relative path to DB
        $dbPath = 'uploads/certificates/' . $fileName;
        
        require_once dirname(__DIR__, 2) . '/config/events.php';

        $allEventIds = [];
        foreach ($EVENTS_MAPPING as $id => $fullName) {
            $cleanBase = preg_replace('/[^a-zA-Z0-9]/', '', getBackendEventBaseType($fullName));
            $cleanSelEvent = preg_replace('/[^a-zA-Z0-9]/', '', $eventName);
            if ($cleanBase === $cleanSelEvent) {
                $allEventIds[] = $id;
            }
        }
        $allEventIds[] = $eventName;
        $allEventIds = array_values(array_unique($allEventIds));

        $inClause = implode(',', array_fill(0, count($allEventIds), '?'));

        $categoryFilter = '';
        if (substr($eventName, -5) === '_issf') {
            $categoryFilter = 'ISSF';
        } elseif (substr($eventName, -3) === '_nr') {
            $categoryFilter = 'NR';
        }

        $catQuery = "";
        $queryParams = [$dbPath, $userId];
        foreach ($allEventIds as $eid) {
            $queryParams[] = $eid;
        }
        if (!empty($categoryFilter)) {
            if ($categoryFilter === 'NR') {
                $catQuery = " AND category IN ('NR', 'NR_MQS') ";
            } else {
                $catQuery = " AND category = ? ";
                $queryParams[] = $categoryFilter;
            }
        }

        $stmt = $pdo->prepare("
            UPDATE event_registrations
            SET certificate_path = ?
            WHERE user_id = ? AND status = 'approved'
        ");
        $stmt->execute([$dbPath, $userId]);
        
        exit(json_encode(['success' => true, 'message' => 'Certificate uploaded and linked successfully.']));
    } else {
        exit(json_encode(['success' => false, 'message' => 'Failed to save certificate PDF file.']));
    }
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
