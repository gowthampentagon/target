<?php
/**
 * logout.php
 * Destroys any active session (admin or participant) and redirects to login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$isAdmin = !empty($_SESSION['admin_id']);
$expiredPasscode = isset($_GET['expired_passcode']);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

if ($expiredPasscode) {
    header('Location: login.php?error=passcode_expired');
} elseif ($isAdmin) {
    header('Location: login.php');
} else {
    header('Location: login.php?logout=1');
}
exit;
