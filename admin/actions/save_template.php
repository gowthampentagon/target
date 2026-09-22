<?php
// admin/actions/save_template.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$documentType = trim($_POST['document_type'] ?? '');
$canvasDataRaw = trim($_POST['canvas_data'] ?? '');

if (empty($documentType) || empty($canvasDataRaw)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

// Decode canvas data to manipulate background/logos if needed
$canvasData = json_decode($canvasDataRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit(json_encode(['success' => false, 'message' => 'Invalid JSON in canvas data.']));
}

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $activeCid = (int)($activeChampionship['id'] ?? 1);

    // ── Ensure document_templates Table Exists ──
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `document_templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `championship_id` INT NOT NULL DEFAULT 1,
            `document_type` VARCHAR(50) NOT NULL,
            `background_path` VARCHAR(255) DEFAULT NULL,
            `canvas_data` LONGTEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`championship_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        try {
            $pdo->exec("ALTER TABLE `document_templates` ADD COLUMN `championship_id` INT NOT NULL DEFAULT 1 AFTER `id`");
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}

    // 1. Handle Background Image Upload
    if (!empty($_FILES['background_image']) && $_FILES['background_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = dirname(__DIR__, 2) . '/uploads/templates';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['background_image']['name'], PATHINFO_EXTENSION);
        $filename = 'bg_' . $documentType . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;
        if (move_uploaded_file($_FILES['background_image']['tmp_name'], $destPath)) {
            $bgPath = 'uploads/templates/' . $filename;
            $canvasData['background'] = $bgPath;
            
            // Save in DB per active championship
            $chk = $pdo->prepare("SELECT id FROM document_templates WHERE document_type = ? AND championship_id = ?");
            $chk->execute([$documentType, $activeCid]);
            $existingId = $chk->fetchColumn();

            $jsonStr = json_encode($canvasData);
            if ($existingId) {
                $stmt = $pdo->prepare("UPDATE document_templates SET background_path = ?, canvas_data = ? WHERE id = ?");
                $stmt->execute([$bgPath, $jsonStr, $existingId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO document_templates (championship_id, document_type, background_path, canvas_data) VALUES (?, ?, ?, ?)");
                $stmt->execute([$activeCid, $documentType, $bgPath, $jsonStr]);
            }

            // Write to tracking config file config/document_templates.json
            writeDocumentTemplateToJson($documentType, $bgPath, $canvasData);
            exit(json_encode([
                'success' => true, 
                'message' => 'Background and template saved.',
                'background_path' => $bgPath,
                'canvas_data' => $canvasData
            ]));
        } else {
            exit(json_encode(['success' => false, 'message' => 'Failed to save background image.']));
        }
    }

    // 2. Handle Logo Upload
    if (!empty($_FILES['logo_image']) && $_FILES['logo_image']['error'] === UPLOAD_ERR_OK) {
        $logoDir = dirname(__DIR__, 2) . '/uploads/logos';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0777, true);
        }
        $ext = pathinfo($_FILES['logo_image']['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . time() . '_' . rand(100, 999) . '.' . $ext;
        $destPath = $logoDir . '/' . $filename;
        if (move_uploaded_file($_FILES['logo_image']['tmp_name'], $destPath)) {
            $logoPath = 'uploads/logos/' . $filename;
            exit(json_encode([
                'success' => true,
                'message' => 'Logo uploaded successfully.',
                'logo_path' => $logoPath
            ]));
        } else {
            exit(json_encode(['success' => false, 'message' => 'Failed to save logo image.']));
        }
    }

    // 3. Handle E-Signature Image Upload
    if (!empty($_FILES['esign_image']) && $_FILES['esign_image']['error'] === UPLOAD_ERR_OK) {
        $esignDir = dirname(__DIR__, 2) . '/uploads/signatures';
        if (!is_dir($esignDir)) {
            mkdir($esignDir, 0777, true);
        }
        $ext = pathinfo($_FILES['esign_image']['name'], PATHINFO_EXTENSION);
        $filename = 'esign_' . time() . '_' . rand(100, 999) . '.' . $ext;
        $destPath = $esignDir . '/' . $filename;
        if (move_uploaded_file($_FILES['esign_image']['tmp_name'], $destPath)) {
            $esignPath = 'uploads/signatures/' . $filename;
            exit(json_encode([
                'success' => true,
                'message' => 'Signature image uploaded successfully.',
                'esign_path' => $esignPath
            ]));
        } else {
            exit(json_encode(['success' => false, 'message' => 'Failed to save signature image.']));
        }
    }

    // 4. Regular layout save without files
    $jsonStr = json_encode($canvasData);
    $chk = $pdo->prepare("SELECT id, background_path FROM document_templates WHERE document_type = ? AND championship_id = ?");
    $chk->execute([$documentType, $activeCid]);
    $existingRow = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existingRow) {
        $stmt = $pdo->prepare("UPDATE document_templates SET canvas_data = ? WHERE id = ?");
        $stmt->execute([$jsonStr, $existingRow['id']]);
        $bgPathVal = $existingRow['background_path'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO document_templates (championship_id, document_type, canvas_data) VALUES (?, ?, ?)");
        $stmt->execute([$activeCid, $documentType, $jsonStr]);
        $bgPathVal = null;
    }

    // Write to tracking config file config/document_templates.json
    writeDocumentTemplateToJson($documentType, $bgPathVal, $canvasData);
    echo json_encode([
        'success' => true,
        'message' => 'Template saved successfully.',
        'canvas_data' => $canvasData
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
