<?php
// admin/actions/create_event_type.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventName = trim($_POST['weapon_type'] ?? '');
if (empty($eventName)) {
    exit(json_encode(['success' => false, 'message' => 'Event type name is required.']));
}

try {
    $pdo = getDB();
    
    // Auto-create table just in case
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `custom_event_names` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `event_name` VARCHAR(100) NOT NULL UNIQUE,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $stmt = $pdo->prepare("INSERT INTO custom_event_names (event_name) VALUES (?) ON DUPLICATE KEY UPDATE event_name=event_name");
    $stmt->execute([$eventName]);

    echo json_encode(['success' => true, 'message' => 'Event table created successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
