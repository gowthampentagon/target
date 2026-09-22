<?php
/**
 * admin/actions/clubs_action.php
 * Handle club additions, edits, and deletions.
 */
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
checkAdminAuth();
checkModuleAccess('clubs.php'); // Enforce passcode permission

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id = !empty($_POST['id']) ? (int)$_POST['id'] : null;

try {
    $pdo = getDB();

    if ($action === 'delete') {
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Missing club ID.']);
            exit;
        }

        // Optional: delete associated file if it exists and is not '-'
        $stmt = $pdo->prepare("SELECT tnsa_subscription_doc FROM clubs WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetchColumn();
        if ($doc && $doc !== '-' && file_exists(dirname(dirname(__DIR__)) . '/' . $doc)) {
            @unlink(dirname(dirname(__DIR__)) . '/' . $doc);
        }

        $stmt = $pdo->prepare("DELETE FROM clubs WHERE id = ?");
        $stmt->execute([$id]);
        syncClubsToConfigFile($pdo);
        echo json_encode(['success' => true, 'message' => 'Club deleted successfully.']);
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $clubName = strtoupper(trim($_POST['club_name'] ?? ''));
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($clubName)) {
            echo json_encode(['success' => false, 'message' => 'Club Name is required.']);
            exit;
        }

        $adminName = $isDefault ? '-' : trim($_POST['admin_name'] ?? '');
        $mobileNo = $isDefault ? '-' : trim($_POST['mobile_no'] ?? '');
        $email = $isDefault ? '-' : trim($_POST['email'] ?? '');
        
        if (!$isDefault) {
            if (empty($adminName) || $adminName === '-') {
                echo json_encode(['success' => false, 'message' => 'Admin Name is required for custom clubs.']);
                exit;
            }
            if (empty($mobileNo) || $mobileNo === '-') {
                echo json_encode(['success' => false, 'message' => 'Mobile No is required for custom clubs.']);
                exit;
            }
            if (empty($email) || $email === '-') {
                echo json_encode(['success' => false, 'message' => 'Email Address is required for custom clubs.']);
                exit;
            }
        }

        // Check duplicate name
        if ($action === 'add') {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM clubs WHERE club_name = ?");
            $chk->execute([$clubName]);
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM clubs WHERE club_name = ? AND id != ?");
            $chk->execute([$clubName, $id]);
        }
        if ($chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Club name already exists.']);
            exit;
        }

        // Handle document upload
        $docPath = '-';
        if ($action === 'edit') {
            // Get existing doc path
            $stmt = $pdo->prepare("SELECT tnsa_subscription_doc FROM clubs WHERE id = ?");
            $stmt->execute([$id]);
            $docPath = $stmt->fetchColumn() ?: '-';
        }

        if (!$isDefault) {
            if ($action === 'add' && (!isset($_FILES['tnsa_subscription_doc']) || $_FILES['tnsa_subscription_doc']['error'] !== UPLOAD_ERR_OK)) {
                echo json_encode(['success' => false, 'message' => 'TNSA Subscription Document is required for new custom clubs.']);
                exit;
            }
        }

        if (!$isDefault && isset($_FILES['tnsa_subscription_doc']) && $_FILES['tnsa_subscription_doc']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['tnsa_subscription_doc'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid document format. Only JPG, PNG, PDF are allowed.']);
                exit;
            }
            
            // Limit size to 5MB
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Document size exceeds 5MB.']);
                exit;
            }

            $uploadDir = dirname(dirname(__DIR__)) . '/uploads/clubs';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // If editing, delete old file first
            if ($action === 'edit' && $docPath !== '-' && file_exists(dirname(dirname(__DIR__)) . '/' . $docPath)) {
                @unlink(dirname(dirname(__DIR__)) . '/' . $docPath);
            }

            $fileName = 'tnsa_sub_' . uniqid() . '.' . $ext;
            $dest = $uploadDir . '/' . $fileName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $docPath = 'uploads/clubs/' . $fileName;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded document.']);
                exit;
            }
        } elseif ($isDefault) {
            // If it is changed to default club, delete old doc if any
            if ($action === 'edit' && $docPath !== '-' && file_exists(dirname(dirname(__DIR__)) . '/' . $docPath)) {
                @unlink(dirname(dirname(__DIR__)) . '/' . $docPath);
            }
            $docPath = '-';
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO clubs (club_name, admin_name, mobile_no, email, tnsa_subscription_doc, is_default)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$clubName, $adminName, $mobileNo, $email, $docPath, $isDefault]);
            syncClubsToConfigFile($pdo);
            echo json_encode(['success' => true, 'message' => 'Club added successfully.']);
        } else {
            $stmt = $pdo->prepare("
                UPDATE clubs
                SET club_name = ?, admin_name = ?, mobile_no = ?, email = ?, tnsa_subscription_doc = ?, is_default = ?
                WHERE id = ?
            ");
            $stmt->execute([$clubName, $adminName, $mobileNo, $email, $docPath, $isDefault, $id]);
            syncClubsToConfigFile($pdo);
            echo json_encode(['success' => true, 'message' => 'Club updated successfully.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
