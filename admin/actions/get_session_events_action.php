<?php
// admin/actions/get_session_events_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) exit(json_encode(['success'=>false,'message'=>'Invalid ID.']));

try {
    $pdo = getDB();
    $evts = $pdo->prepare("SELECT event_reg_id, category, event_name, weapon_type, age_group, best_score, entry_fee, issf_number, certificate_path, status FROM event_registrations WHERE session_id=? ORDER BY id");
    $evts->execute([$id]);
    $events = $evts->fetchAll(PDO::FETCH_ASSOC);

    $ses = $pdo->prepare("SELECT payment_screenshot FROM registration_sessions WHERE id=? LIMIT 1");
    $ses->execute([$id]);
    $row = $ses->fetch();

    exit(json_encode(['success'=>true,'events'=>$events,'payment_screenshot'=>$row['payment_screenshot']??null]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'message'=>'Error fetching events.']));
}
