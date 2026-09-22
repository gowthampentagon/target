<?php
// admin/actions/reject_session_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth(); checkModuleAccess('payment_sessions.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

$id      = (int)($_POST['session_id'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');
if ($id <= 0) exit(json_encode(['success'=>false,'message'=>'Session ID required.']));

try {
    $pdo = getDB();
    
    // Fetch session details
    $sesQ = $pdo->prepare("SELECT s.user_id, s.session_id, r.email, r.first_name, r.last_name FROM registration_sessions s JOIN registrations r ON r.id = s.user_id WHERE s.id = ?");
    $sesQ->execute([$id]);
    $session = $sesQ->fetch();

    $pdo->prepare("UPDATE registration_sessions SET approval_status='rejected', admin_remarks=?, approved_by=?, approved_at=NOW() WHERE id=?")->execute([$remarks, $_SESSION['admin_id'], $id]);
    $pdo->prepare("UPDATE event_registrations SET status='rejected', admin_remarks=? WHERE session_id=?")->execute([$remarks, $id]);
    
    $emailSent = false;
    if ($session) {
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, is_admin, title, message, redirect_url)
            VALUES (?, 0, 'Registration Rejected', ?, 'dashboard.php?tab=events')
        ");
        $notifStmt->execute([
            $session['user_id'],
            "Your event registration session " . $session['session_id'] . " was rejected. Reason: " . $remarks
        ]);

        $activeChampionship = getActiveChampionship($pdo);
        $champName = $activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship';
        $emailSent = sendMail($session['email'], '❌ Registration Rejected – ' . $champName, $body, $name);
    }
    
    exit(json_encode(['success'=>true, 'email_sent'=>$emailSent]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'message'=>'Server error.']));
}
