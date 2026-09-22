<?php
/**
 * actions/profile_change_request.php
 * Handles profile change requests for fields requiring Super Admin approval
 * Fields: association, membership_id, membership_doc
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';

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

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized.']));
}

$action = trim($_POST['action'] ?? '');
$fieldName = trim($_POST['field_name'] ?? '');

if ($action !== 'submit_change_request') {
    exit(json_encode(['success' => false, 'message' => 'Invalid action.']));
}

$allowedFields = ['club_name', 'association', 'membership_id', 'membership_doc'];
if (!in_array($fieldName, $allowedFields, true)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid field name.']));
}

try {
    $pdo = getDB();

    // Get current user
    $userStmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        exit(json_encode(['success' => false, 'message' => 'User not found.']));
    }

    if ($fieldName === 'membership_doc') {
        // Handle document upload
        if (!isset($_FILES['membership_doc_file'])) {
            exit(json_encode(['success' => false, 'message' => 'No file provided.']));
        }

        $file = $_FILES['membership_doc_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            exit(json_encode(['success' => false, 'message' => 'File upload error.']));
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes, true)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid file type.']));
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            exit(json_encode(['success' => false, 'message' => 'File size exceeds 2MB limit.']));
        }

        $uploadDir = dirname(__DIR__) . '/uploads/membership_docs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'membership_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            exit(json_encode(['success' => false, 'message' => 'Failed to upload file.']));
        }

        $newValue = 'uploads/membership_docs/' . $filename;
    } else {
        // Handle text fields
        $newValue = mb_strtoupper(trim(htmlspecialchars_decode(strip_tags(trim($_POST['new_value'] ?? '')))), 'UTF-8');

        if (strlen($newValue) === 0) {
            exit(json_encode(['success' => false, 'message' => 'Value cannot be empty.']));
        }

        if ($fieldName === 'club_name' && strlen($newValue) < 2) {
            exit(json_encode(['success' => false, 'message' => 'Club name must be at least 2 characters.']));
        }

        if ($fieldName === 'association' && strlen($newValue) < 2) {
            exit(json_encode(['success' => false, 'message' => 'Association name must be at least 2 characters.']));
        }

        if ($fieldName === 'membership_id' && strlen($newValue) < 2) {
            exit(json_encode(['success' => false, 'message' => 'Membership ID must be at least 2 characters.']));
        }
    }

    // Map field names to database columns
    $fieldMapping = [
        'club_name' => ['pending_col' => 'club_name_pending', 'flag_col' => 'club_name_change_pending'],
        'association' => ['pending_col' => 'association_pending', 'flag_col' => 'association_change_pending'],
        'membership_id' => ['pending_col' => 'membership_id_pending', 'flag_col' => 'membership_id_change_pending'],
        'membership_doc' => ['pending_col' => 'membership_doc_pending', 'flag_col' => 'membership_doc_change_pending'],
    ];

    $cols = $fieldMapping[$fieldName];
    $pendingCol = $cols['pending_col'];
    $flagCol = $cols['flag_col'];

    // Get current value
    $currentValue = $user[$fieldName] ?? null;

    // Check if the new value is identical to the current value
    if ($fieldName !== 'membership_doc' && strcasecmp(trim((string)$newValue), trim((string)$currentValue)) === 0) {
        exit(json_encode(['success' => false, 'message' => 'The new value is identical to your current approved value.']));
    }

    // Check if there's already a pending request
    $pendingCheckSql = "SELECT COUNT(*) as cnt FROM profile_change_requests 
                        WHERE user_id = ? AND field_name = ? AND status = 'pending' LIMIT 1";
    $pendingCheckStmt = $pdo->prepare($pendingCheckSql);
    $pendingCheckStmt->execute([$userId, $fieldName]);
    $pendingCheck = $pendingCheckStmt->fetch();

    if ($pendingCheck['cnt'] > 0) {
        exit(json_encode(['success' => false, 'message' => 'You already have a pending request for this field. Please wait for approval or rejection.']));
    }

    // Create change request record
    $createRequestSql = "INSERT INTO profile_change_requests (user_id, field_name, old_value, new_value, status) 
                         VALUES (:user_id, :field_name, :old_value, :new_value, 'pending')";
    $createStmt = $pdo->prepare($createRequestSql);
    $createStmt->execute([
        ':user_id' => $userId,
        ':field_name' => $fieldName,
        ':old_value' => $currentValue,
        ':new_value' => $newValue,
    ]);

    // Update pending columns in registrations table
    $updateSql = "UPDATE registrations SET $pendingCol = :new_value, $flagCol = 1 WHERE id = :id";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':new_value' => $newValue,
        ':id' => $userId,
    ]);
    // Insert Admin Notification
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (is_admin, title, message, redirect_url)
        VALUES (1, 'New Support Request', ?, 'profile_change_requests.php')
    ");
    $notifStmt->execute(["Participant " . $user['first_name'] . " " . $user['last_name'] . " requested a change for " . ($fieldName === 'club_name' ? 'Club Name' : ($fieldName === 'association' ? 'Association' : ($fieldName === 'membership_id' ? 'Membership ID' : 'Membership Doc'))) . "."]);

    // Log success
    error_log("Profile change request created: user_id=$userId, field=$fieldName, new_value=$newValue");

    $fieldLabels = [
        'club_name' => 'Club Name',
        'association' => 'Association name',
        'membership_id' => 'Membership ID',
        'membership_doc' => 'Membership document',
    ];

    exit(json_encode([
        'success' => true,
        'message' => 'Change request submitted for ' . $fieldLabels[$fieldName] . '. Awaiting Super Admin approval.',
    ]));
} catch (Exception $e) {
    error_log('Profile change request error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Database error. Please try again later.']));
}
