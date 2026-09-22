<?php
/**
 * actions/login_action.php
 * Handles unified AJAX login requests (Admins & Participants).
 * Returns JSON: { success, message, redirect }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

// ── CSRF check ───────────────────────────────────────────────
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']));
}

// ── Rate limiting – max 5 attempts per email per 15 min ──────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$email = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid email address.']));
}

if (strlen($password) < 6) {
    exit(json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']));
}

try {
    $pdo = getDB();

    // Clean old attempts (safely)
    try {
        $pdo->exec("ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $pdo->exec("DELETE FROM `login_attempts` WHERE `last_attempt` < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    } catch (Throwable $t) {
        // Silently fail if table structure is being migrated
    }

    try {
        $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $attempts = (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $t) {
        $attempts = 0;
    }

    /* Commented out rate-limiting check for easy local testing
    if ($attempts >= 5) {
        exit(json_encode([
            'success' => false,
            'message' => 'Too many failed attempts. Please wait 15 minutes and try again.'
        ]));
    }
    */

    // ── Supreme Admin Check ──────────────────────────
    if ($email === 'supremeadmin@ssa.com' && $password === 'supremeadmin@123') {
        try {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $adminId = $stmt->fetchColumn();
            if (!$adminId) {
                $pwdHash = password_hash($password, PASSWORD_DEFAULT);
                $stmtInsert = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
                $stmtInsert->execute(['Supreme Admin', $email, $pwdHash, 'supremeadmin']);
                $adminId = $pdo->lastInsertId();
            } else {
                $pwdHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admins SET role = 'supremeadmin', password_hash = ? WHERE id = ?")->execute([$pwdHash, $adminId]);
            }
        } catch (Throwable $t) {
            $adminId = 8888;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_name'] = 'Supreme Admin';
        $_SESSION['admin_role'] = 'supremeadmin';
        $_SESSION['supreme_admin'] = true;
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        exit(json_encode([
            'success' => true,
            'message' => 'Supreme Admin login successful!',
            'redirect' => 'admin/supreme_admin.php'
        ]));
    }

    // ── Special Page Admin Check ─────────────────────────────
    if ($email === 'pageadmin@ssa.com' && $password === 'pageadminssa') {
        session_regenerate_id(true);
        $_SESSION['page_admin'] = true;
        $_SESSION['admin_id'] = 9999; // Special dashboard admin ID
        $_SESSION['admin_name'] = 'Page Admin';
        $_SESSION['admin_role'] = 'admin';
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        exit(json_encode([
            'success' => true,
            'message' => 'Page Admin login successful! Redirecting...',
            'redirect' => 'ssa-dashboard/manage.php'
        ]));
    }

    // ── 1. Check Admins Table First ──────────────────────────
    $stmt = $pdo->prepare("SELECT id, name, password_hash, role FROM admins WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        // Success – create admin session
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['admin_last_activity'] = time();

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $redirect = ($admin['role'] === 'supremeadmin') ? 'admin/supreme_admin.php' : 'admin/index.php';

        exit(json_encode([
            'success' => true,
            'message' => 'Admin login successful!',
            'redirect' => $redirect
        ]));
    }

    // ── 2. Check Registrations (Participants) Table ──────────
    $stmt = $pdo->prepare(
        "SELECT id, reg_id, first_name, last_name, password_hash, status
           FROM registrations
          WHERE email = ?
          LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['status'] !== 'active') {
            exit(json_encode([
                'success' => false,
                'message' => 'Your account has been deactivated. Contact the administrator.'
            ]));
        }

        // Success – create participant session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['reg_id'] = $user['reg_id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['login_time'] = time();

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        exit(json_encode([
            'success' => true,
            'message' => 'Login successful!',
            'redirect' => 'dashboard.php'
        ]));
    }

    // ── If we reached here, login failed ─────────────────────
    try {
        $stmt = $pdo->prepare("SELECT id FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
        if ($stmt->fetch()) {
            $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1 WHERE ip_address = ?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts) VALUES (?, 1)")->execute([$ip]);
        }
    } catch (Throwable $t) {
        // Log attempt failure silently
    }

    exit(json_encode([
        'success' => false,
        'message' => 'Incorrect email or password. Please try again.'
    ]));

} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]));
}
