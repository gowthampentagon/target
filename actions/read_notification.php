<?php
/**
 * actions/read_notification.php
 * Marks a notification as read.
 */
require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    } catch(Exception $e) {}
}
echo json_encode(['success' => false]);
