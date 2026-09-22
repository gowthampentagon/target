<?php
// admin/actions/delete_certificate.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventName = trim($_POST['event_name'] ?? '');
$category = trim($_POST['category'] ?? '');

if (empty($eventName) || empty($category)) {
    exit(json_encode(['success' => false, 'message' => 'Event name and category are required.']));
}

try {
    $pdo = getDB();
    
    // Find all certificates for this event and category
    $stmt = $pdo->prepare("
        SELECT id, user_id, certificate_path 
        FROM event_registrations 
        WHERE event_name = ? AND category = ? AND certificate_path IS NOT NULL AND certificate_path != ''
    ");
    $stmt->execute([$eventName, $category]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deletedCount = 0;
    foreach ($rows as $r) {
        $path = $r['certificate_path'];
        $fullPath = dirname(__DIR__, 2) . '/' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        
        // Reset path in DB
        $up = $pdo->prepare("UPDATE event_registrations SET certificate_path = NULL WHERE id = ?");
        $up->execute([$r['id']]);
        $deletedCount++;
    }
    
    exit(json_encode(['success' => true, 'message' => "$deletedCount certificate(s) deleted successfully."]));
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
