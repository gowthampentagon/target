<?php
/**
 * admin/actions/delete_reg_action.php
 * Deletes a registration (Superadmin only).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();
checkModuleAccess('registrations.php'); // Enforce passcode permission

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    exit(json_encode(['success' => false, 'message' => 'Invalid data provided.']));
}

try {
    $pdo = getDB();

    // 1. Fetch file paths from DB before deleting the record
    $stmtSelect = $pdo->prepare("SELECT photo, membership_doc, disability_proof FROM registrations WHERE id = ? LIMIT 1");
    $stmtSelect->execute([$id]);
    $reg = $stmtSelect->fetch(PDO::FETCH_ASSOC);

    if ($reg) {
        $baseDir = dirname(__DIR__, 2) . '/';
        
        // Delete photo
        if (!empty($reg['photo'])) {
            $photoPath = $baseDir . $reg['photo'];
            if (file_exists($photoPath) && is_file($photoPath)) {
                @unlink($photoPath);
            }
        }
        
        // Delete membership document
        if (!empty($reg['membership_doc'])) {
            $memberDocPath = $baseDir . $reg['membership_doc'];
            if (file_exists($memberDocPath) && is_file($memberDocPath)) {
                @unlink($memberDocPath);
            }
        }
        
        // Delete disability proof
        if (!empty($reg['disability_proof'])) {
            $disProofPath = $baseDir . $reg['disability_proof'];
            if (file_exists($disProofPath) && is_file($disProofPath)) {
                @unlink($disProofPath);
            }
        }
    }

    // 2. Delete user folder recursively
    $userDir = dirname(__DIR__, 2) . '/uploads/user_' . $id;
    if (is_dir($userDir)) {
        deleteDirectory($userDir);
    }
    
    // 3. Delete competitor card PDF
    $cardPath = dirname(__DIR__, 2) . '/uploads/competitor_cards/competitor_card_' . $id . '.pdf';
    if (file_exists($cardPath) && is_file($cardPath)) {
        @unlink($cardPath);
    }
    
    // 4. Delete user certificates from uploads/certificates/
    $certDir = dirname(__DIR__, 2) . '/uploads/certificates';
    if (is_dir($certDir)) {
        $files = scandir($certDir);
        foreach ($files as $file) {
            if (strpos($file, 'certificate_' . $id . '_') === 0) {
                @unlink($certDir . '/' . $file);
            }
        }
    }

    // 5. Delete the registration record from DB
    $stmt = $pdo->prepare("DELETE FROM registrations WHERE id = ?");
    $stmt->execute([$id]);

    exit(json_encode(['success' => true]));
} catch (Exception $e) {
    exit(json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]));
}

/**
 * Recursively deletes a directory and its contents.
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        (is_dir($path)) ? deleteDirectory($path) : @unlink($path);
    }
    return @rmdir($dir);
}
