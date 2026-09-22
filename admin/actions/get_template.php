<?php
// admin/actions/get_template.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$documentType = trim($_GET['document_type'] ?? '');
if (empty($documentType)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Document type is required.']));
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

    $stmt = $pdo->prepare("SELECT canvas_data, background_path FROM document_templates WHERE document_type = ? AND championship_id = ? LIMIT 1");
    $stmt->execute([$documentType, $activeCid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $data = json_decode($row['canvas_data'], true);
        echo json_encode([
            'success' => true,
            'document_type' => $documentType,
            'background_path' => $row['background_path'],
            'canvas_data' => $data
        ]);
    } else {
        // Fallback: check config/document_templates.json
        $jsonFile = dirname(__DIR__, 2) . '/config/document_templates.json';
        if (file_exists($jsonFile)) {
            $templates = json_decode(file_get_contents($jsonFile), true);
            if (is_array($templates) && isset($templates[$documentType])) {
                $tmplData = $templates[$documentType];
                $bgPath = $tmplData['background_path'] ?? null;
                $cData = $tmplData['canvas_data'] ?? [];
                
                // Sync to DB
                try {
                    $ins = $pdo->prepare("INSERT INTO document_templates (document_type, background_path, canvas_data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE background_path = VALUES(background_path), canvas_data = VALUES(canvas_data)");
                    $ins->execute([$documentType, $bgPath, json_encode($cData)]);
                } catch (Exception $migEx) {}

                echo json_encode([
                    'success' => true,
                    'document_type' => $documentType,
                    'background_path' => $bgPath,
                    'canvas_data' => $cData
                ]);
                exit;
            }
        }

        echo json_encode([
            'success' => false,
            'message' => 'No template found. Initializing new template.',
            'canvas_data' => [
                'width' => 794,
                'height' => 1122,
                'orientation' => 'portrait',
                'elements' => []
            ]
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
