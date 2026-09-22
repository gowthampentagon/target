<?php
/**
 * actions/read_all_notifications.php
 * Marks all notifications as read for current user/admin.
 */
require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = isset($_POST['is_admin']) && $_POST['is_admin'] == 1 ? 1 : 0;
$userId = $_SESSION['user_id'] ?? null;

try {
    $pdo = getDB();
    if ($isAdmin) {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_admin = 1");
    } elseif ($userId) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_admin = 0")->execute([$userId]);
    }
    echo json_encode(['success' => true]);
    exit;
} catch(Exception $e) {}

echo json_encode(['success' => false]);
