<?php
/**
 * actions/discard_draft_action.php
 * Delete a user's saved draft and its associated certificate files from disk.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth Check ──
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Not logged in.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$userId = (int)$_SESSION['user_id'];
$draftSessionId = (int)($_POST['draft_session_id'] ?? 0);

if ($draftSessionId <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid draft ID.']));
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Verify session belongs to the user and is a draft
    $stmt = $pdo->prepare("SELECT id FROM registration_sessions WHERE id = ? AND user_id = ? AND approval_status = 'draft' LIMIT 1");
    $stmt->execute([$draftSessionId, $userId]);
    $session = $stmt->fetch();

    if (!$session) {
        exit(json_encode(['success' => false, 'message' => 'Draft session not found.']));
    }

    // Fetch and delete all certificates from disk
    $certStmt = $pdo->prepare("SELECT certificate_path FROM event_registrations WHERE session_id = ?");
    $certStmt->execute([$draftSessionId]);
    $certs = $certStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($certs as $c) {
        if (!empty($c)) {
            $filePath = dirname(__DIR__) . '/' . $c;
            if (file_exists($filePath)) @unlink($filePath);
        }
    }

    // Delete database rows
    $pdo->prepare("DELETE FROM event_registrations WHERE session_id = ?")->execute([$draftSessionId]);
    $pdo->prepare("DELETE FROM registration_sessions WHERE id = ?")->execute([$draftSessionId]);

    $pdo->commit();
    exit(json_encode(['success' => true, 'message' => 'Draft discarded successfully.']));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Discard draft error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server error. Please try again.']));
}
