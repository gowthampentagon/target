<?php
/**
 * admin/actions/admin_users_action.php
 * Handles adding and deleting admin users (Superadmin only).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin access

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$action = $_POST['action'] ?? '';

try {
    $pdo = getDB();

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'admin';
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($name) || strlen($password) < 8) {
            exit(json_encode(['success' => false, 'message' => 'Invalid data provided. Password must be 8+ chars.']));
        }
        if (!in_array($role, ['admin', 'superadmin'])) {
            exit(json_encode(['success' => false, 'message' => 'Invalid role.']));
        }

        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            exit(json_encode(['success' => false, 'message' => 'Email is already registered.']));
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO admins (name, email, role, password_hash) VALUES (?, ?, ?, ?)")
            ->execute([$name, $email, $role, $hash]);

        exit(json_encode(['success' => true]));

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid ID.']));
        }

        if ($id == $_SESSION['admin_id']) {
            exit(json_encode(['success' => false, 'message' => 'You cannot delete yourself.']));
        }

        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
        exit(json_encode(['success' => true]));
    }

    exit(json_encode(['success' => false, 'message' => 'Invalid action.']));

} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error.']));
}
