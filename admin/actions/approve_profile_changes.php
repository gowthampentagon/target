<?php
/**
 * admin/actions/approve_profile_changes.php
 * Super Admin endpoint to approve/reject profile change requests
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Security token mismatch.']));
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0 || !canEdit()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Access Denied: You do not have edit/write permissions.']));
}

try {
    $pdo = getDB();

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');

    if (!in_array($action, ['approve', 'reject'], true)) {
        exit(json_encode(['success' => false, 'message' => 'Invalid action.']));
    }

    if ($action === 'reject' && strlen($rejectionReason) === 0) {
        exit(json_encode(['success' => false, 'message' => 'Rejection reason is required.']));
    }

    // Get the change request
    $requestStmt = $pdo->prepare("SELECT * FROM profile_change_requests WHERE id = ? LIMIT 1");
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch();

    if (!$request) {
        exit(json_encode(['success' => false, 'message' => 'Request not found.']));
    }

    if ($request['status'] !== 'pending') {
        exit(json_encode(['success' => false, 'message' => 'Request has already been processed.']));
    }

    if ($action === 'approve') {
        // Update the main value and clear pending flag
        $fieldName = $request['field_name'];
        $newValue = $request['new_value'];
        $userId = $request['user_id'];

        $fieldMapping = [
            'club_name' => ['col' => 'club_name', 'pending_col' => 'club_name_pending', 'flag_col' => 'club_name_change_pending'],
            'association' => ['col' => 'association', 'pending_col' => 'association_pending', 'flag_col' => 'association_change_pending'],
            'membership_id' => ['col' => 'membership_id', 'pending_col' => 'membership_id_pending', 'flag_col' => 'membership_id_change_pending'],
            'membership_doc' => ['col' => 'membership_doc', 'pending_col' => 'membership_doc_pending', 'flag_col' => 'membership_doc_change_pending'],
        ];

        if (!isset($fieldMapping[$fieldName])) {
            exit(json_encode(['success' => false, 'message' => 'Invalid field name.']));
        }

        $cols = $fieldMapping[$fieldName];
        $mainCol = $cols['col'];
        $pendingCol = $cols['pending_col'];
        $flagCol = $cols['flag_col'];

        // Update registrations table - approve the change
        $approveCol = str_replace('_change_pending', '_change_approved_at', $flagCol);
        $updateUserSql = "UPDATE registrations SET 
                          $mainCol = :new_value,
                          $pendingCol = NULL,
                          $flagCol = 0,
                          $approveCol = NOW()
                          WHERE id = :user_id";
        $updateUserStmt = $pdo->prepare($updateUserSql);
        $updateUserStmt->execute([
            ':new_value' => $newValue,
            ':user_id' => $userId,
        ]);

        // Update the request record
        $updateRequestSql = "UPDATE profile_change_requests 
                             SET status = 'approved', approved_by = ?, approved_at = NOW() 
                             WHERE id = ?";
        $updateRequestStmt = $pdo->prepare($updateRequestSql);
        $updateRequestStmt->execute([$adminId, $requestId]);

        // Insert User Notification
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, is_admin, title, message, redirect_url)
            VALUES (?, 0, 'Profile Request Approved', ?, 'dashboard.php?tab=profile')
        ");
        $notifStmt->execute([
            $userId,
            "Your request to update " . ($fieldName === 'club_name' ? 'Club Name' : ($fieldName === 'association' ? 'Association' : ($fieldName === 'membership_id' ? 'Membership ID' : 'Membership Doc'))) . " has been approved."
        ]);

        exit(json_encode([
            'success' => true,
            'message' => 'Change approved and applied successfully.',
        ]));
    } else {
        // Reject the request
        $userId = $request['user_id'];
        $fieldName = $request['field_name'];

        $fieldMapping = [
            'club_name' => ['pending_col' => 'club_name_pending', 'flag_col' => 'club_name_change_pending'],
            'association' => ['pending_col' => 'association_pending', 'flag_col' => 'association_change_pending'],
            'membership_id' => ['pending_col' => 'membership_id_pending', 'flag_col' => 'membership_id_change_pending'],
            'membership_doc' => ['pending_col' => 'membership_doc_pending', 'flag_col' => 'membership_doc_change_pending'],
        ];

        $cols = $fieldMapping[$fieldName];
        $pendingCol = $cols['pending_col'];
        $flagCol = $cols['flag_col'];

        // Clear pending flags without applying the change
        $rejectUserSql = "UPDATE registrations SET 
                          $pendingCol = NULL,
                          $flagCol = 0
                          WHERE id = :user_id";
        $rejectUserStmt = $pdo->prepare($rejectUserSql);
        $rejectUserStmt->execute([':user_id' => $userId]);

        // Update the request record
        $updateRequestSql = "UPDATE profile_change_requests 
                             SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? 
                             WHERE id = ?";
        $updateRequestStmt = $pdo->prepare($updateRequestSql);
        $updateRequestStmt->execute([$adminId, $rejectionReason, $requestId]);

        // Insert User Notification
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, is_admin, title, message, redirect_url)
            VALUES (?, 0, 'Profile Request Rejected', ?, 'dashboard.php?tab=profile')
        ");
        $notifStmt->execute([
            $userId,
            "Your request to update " . ($fieldName === 'club_name' ? 'Club Name' : ($fieldName === 'association' ? 'Association' : ($fieldName === 'membership_id' ? 'Membership ID' : 'Membership Doc'))) . " was rejected. Reason: " . $rejectionReason
        ]);

        exit(json_encode([
            'success' => true,
            'message' => 'Change request rejected.',
        ]));
    }
} catch (Exception $e) {
    error_log('Approve profile changes error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Database error. Please try again later.']));
}
