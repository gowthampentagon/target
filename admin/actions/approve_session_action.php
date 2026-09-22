<?php
// admin/actions/approve_session_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth(); checkModuleAccess('payment_sessions.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

$sessionDbId = (int)($_POST['session_id'] ?? 0);
if ($sessionDbId <= 0) exit(json_encode(['success'=>false,'message'=>'Invalid session.']));

try {
    $pdo = getDB();

    // Fetch session + user
    $ses = $pdo->prepare(
        "SELECT s.*, r.email, r.first_name, r.last_name, r.reg_id
         FROM registration_sessions s JOIN registrations r ON r.id = s.user_id
         WHERE s.id = ? LIMIT 1"
    );
    $ses->execute([$sessionDbId]);
    $session = $ses->fetch();
    if (!$session) exit(json_encode(['success'=>false,'message'=>'Session not found.']));
    if ($session['approval_status'] === 'approved') exit(json_encode(['success'=>false,'message'=>'Already approved.']));

    // Fetch events
    $evtStmt = $pdo->prepare("SELECT * FROM event_registrations WHERE session_id = ?");
    $evtStmt->execute([$sessionDbId]);
    $events = $evtStmt->fetchAll();

    // Update session
    $pdo->prepare(
        "UPDATE registration_sessions SET approval_status='approved', payment_status='verified', approved_by=?, approved_at=NOW() WHERE id=?"
    )->execute([$_SESSION['admin_id'], $sessionDbId]);

    // Update all events in session
    $pdo->prepare("UPDATE event_registrations SET status='approved' WHERE session_id=?")->execute([$sessionDbId]);

    // Send approval email
    $user  = ['first_name'=>$session['first_name'],'last_name'=>$session['last_name'],'reg_id'=>$session['reg_id'],'email'=>$session['email']];
    $body  = getApprovalEmailBody($user, $session, $events);
    $activeChampionship = getActiveChampionship($pdo);
    $champName = $activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship';
    $emailSent = sendMail($session['email'], '✅ Registration Approved – ' . $champName, $body, $session['first_name'].' '.$session['last_name']);

    // Insert User Notification
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, is_admin, title, message, redirect_url)
        VALUES (?, 0, 'Registration Approved', ?, 'dashboard.php?tab=events')
    ");
    $notifStmt->execute([
        $session['user_id'],
        "Your event registration session " . $session['session_id'] . " has been approved successfully."
    ]);

    exit(json_encode(['success'=>true,'message'=>'Session approved successfully.','email_sent'=>$emailSent]));
} catch (Exception $e) {
    error_log('Approve session error: '.$e->getMessage());
    exit(json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]));
}
