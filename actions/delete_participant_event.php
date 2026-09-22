<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Not logged in.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$eventRegId = trim($_POST['event_reg_id'] ?? '');
if (empty($eventRegId)) {
    exit(json_encode(['success' => false, 'message' => 'Event registration ID required.']));
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, status, session_id, certificate_path FROM event_registrations WHERE event_reg_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$eventRegId, $_SESSION['user_id']]);
    $reg = $stmt->fetch();

    if (!$reg) {
        exit(json_encode(['success' => false, 'message' => 'Event registration not found.']));
    }

    if ($reg['status'] !== 'pending' && $reg['status'] !== 'draft') {
        exit(json_encode(['success' => false, 'message' => 'Only pending or draft registrations can be deleted.']));
    }

    $pdo->beginTransaction();

    // Delete certificate from disk
    if (!empty($reg['certificate_path'])) {
        $certFile = dirname(__DIR__) . '/' . $reg['certificate_path'];
        if (file_exists($certFile)) @unlink($certFile);
    }

    $pdo->prepare("DELETE FROM event_registrations WHERE id = ? AND user_id = ?")
        ->execute([$reg['id'], $_SESSION['user_id']]);

    $sessionId = $reg['session_id'];
    if ($sessionId) {
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE session_id = ?");
        $remaining->execute([$sessionId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $sesInfo = $pdo->prepare("SELECT payment_screenshot FROM registration_sessions WHERE id = ?");
            $sesInfo->execute([$sessionId]);
            $ses = $sesInfo->fetch();
            if ($ses && !empty($ses['payment_screenshot'])) {
                $filePath = dirname(__DIR__) . '/' . $ses['payment_screenshot'];
                if (file_exists($filePath)) @unlink($filePath);
            }
            $pdo->prepare("DELETE FROM registration_sessions WHERE id = ?")->execute([$sessionId]);
        }
    }

    $pdo->commit();

    exit(json_encode(['success' => true, 'message' => 'Event registration deleted successfully.']));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Delete event error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server error. Please try again.']));
}
