<?php
// admin/actions/delete_session_action.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth(); checkModuleAccess('payment_sessions.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

$id = (int)($_POST['session_id'] ?? 0);
if ($id <= 0) exit(json_encode(['success'=>false,'message'=>'Invalid ID.']));

try {
    $pdo = getDB();
    // Get screenshot to delete file
    $ses = $pdo->prepare("SELECT payment_screenshot FROM registration_sessions WHERE id=? LIMIT 1");
    $ses->execute([$id]);
    $row = $ses->fetch();
    if ($row && $row['payment_screenshot']) {
        $filePath = dirname(__DIR__, 2) . '/' . $row['payment_screenshot'];
        if (file_exists($filePath)) @unlink($filePath);
    }
    
    // Select and delete event certificates
    $certStmt = $pdo->prepare("SELECT certificate_path FROM event_registrations WHERE session_id=?");
    $certStmt->execute([$id]);
    $certs = $certStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($certs as $c) {
        if (!empty($c['certificate_path'])) {
            $cPath = dirname(__DIR__, 2) . '/' . $c['certificate_path'];
            if (file_exists($cPath)) @unlink($cPath);
        }
    }

    $pdo->prepare("DELETE FROM event_registrations WHERE session_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM registration_sessions WHERE id=?")->execute([$id]);
    exit(json_encode(['success'=>true]));
} catch (Exception $e) {
    exit(json_encode(['success'=>false,'message'=>'Server error.']));
}
