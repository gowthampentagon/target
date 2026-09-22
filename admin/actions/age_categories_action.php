<?php
/**
 * admin/actions/age_categories_action.php
 * Backend AJAX handler for Age Categories & Eligibility Matrix configuration.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check: Must be logged in as admin
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized session. Please log in.']));
}

$pdo = getDB();

// ── Fetch Active Championship ──────────────────────────
$activeChampionship = getActiveChampionship($pdo);
$activeCid = (int)($activeChampionship['id'] ?? 1);

// ── Default Categories List ─────────────────────────────
$ALL_CATEGORIES = [
    'Sub Youth',
    'Youth',
    'Junior',
    'Senior',
    'Master',
    'Senior Master',
    'Super Master'
];

$DEFAULT_RULES = [
    'Sub Youth'     => ['allowed' => ['Sub Youth', 'Youth', 'Junior', 'Senior'], 'min_age' => 0,  'max_age' => 16],
    'Youth'         => ['allowed' => ['Youth', 'Junior', 'Senior'],             'min_age' => 17, 'max_age' => 19],
    'Junior'        => ['allowed' => ['Junior', 'Senior'],                    'min_age' => 20, 'max_age' => 21],
    'Senior'        => ['allowed' => ['Senior'],                              'min_age' => 22, 'max_age' => 44],
    'Master'        => ['allowed' => ['Master', 'Senior'],                    'min_age' => 45, 'max_age' => 59],
    'Senior Master' => ['allowed' => ['Senior Master', 'Master', 'Senior'],    'min_age' => 60, 'max_age' => 69],
    'Super Master'  => ['allowed' => ['Super Master', 'Senior Master', 'Master', 'Senior'], 'min_age' => 70, 'max_age' => 120]
];

// ── Ensure Table Exists & Is Seeded ──────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `age_category_rules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `championship_id` INT NOT NULL DEFAULT 1,
        `shooter_category` VARCHAR(50) NOT NULL,
        `min_age` INT DEFAULT 0,
        `max_age` INT DEFAULT 120,
        `set_age` INT DEFAULT NULL,
        `allowed_event_categories` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (`championship_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    try {
        $pdo->exec("ALTER TABLE `age_category_rules` ADD COLUMN `championship_id` INT NOT NULL DEFAULT 1 AFTER `id`");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE `age_category_rules` ADD COLUMN `min_age` INT DEFAULT 0 AFTER `shooter_category`");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE `age_category_rules` ADD COLUMN `max_age` INT DEFAULT 120 AFTER `min_age`");
    } catch (Throwable $e) {}

    // Drop legacy global unique index on shooter_category if exists
    try {
        $pdo->exec("ALTER TABLE `age_category_rules` DROP INDEX `shooter_category`");
    } catch (Throwable $e) {}

    // Ensure composite unique index on (championship_id, shooter_category)
    try {
        $pdo->exec("ALTER TABLE `age_category_rules` ADD UNIQUE KEY `champ_shooter_cat` (`championship_id`, `shooter_category`)");
    } catch (Throwable $e) {}

    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM `age_category_rules` WHERE `championship_id` = ?");
    $stmtCnt->execute([$activeCid]);
    $count = (int)$stmtCnt->fetchColumn();

    if ($count === 0) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO `age_category_rules` 
                (`championship_id`, `shooter_category`, `min_age`, `max_age`, `set_age`, `allowed_event_categories`) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                `min_age` = VALUES(`min_age`), 
                `max_age` = VALUES(`max_age`), 
                `set_age` = VALUES(`set_age`), 
                `allowed_event_categories` = VALUES(`allowed_event_categories`)
        ");
        foreach ($DEFAULT_RULES as $cat => $info) {
            $stmtInsert->execute([$activeCid, $cat, $info['min_age'], $info['max_age'], $info['max_age'], json_encode($info['allowed'])]);
        }
    }
} catch (Throwable $e) {
    error_log('Age category rules table initialization error: ' . $e->getMessage());
}

$action = trim($_REQUEST['action'] ?? 'get');

try {
    if ($action === 'get') {
        $stmt = $pdo->prepare("SELECT * FROM `age_category_rules` WHERE `championship_id` = ? ORDER BY id ASC");
        $stmt->execute([$activeCid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $rulesMap = [];
        foreach ($rows as $r) {
            $cat = $r['shooter_category'];
            $allowed = json_decode($r['allowed_event_categories'], true);
            if (!is_array($allowed)) {
                $allowed = array_map('trim', explode(',', $r['allowed_event_categories']));
            }
            $minAge = (isset($r['min_age']) && $r['min_age'] !== null && $r['min_age'] !== '') ? (int)$r['min_age'] : ($DEFAULT_RULES[$cat]['min_age'] ?? 0);
            $maxAge = (isset($r['max_age']) && $r['max_age'] !== null && $r['max_age'] !== '') ? (int)$r['max_age'] : ($DEFAULT_RULES[$cat]['max_age'] ?? 120);
            $rulesMap[$cat] = [
                'allowed' => $allowed,
                'min_age' => $minAge,
                'max_age' => $maxAge
            ];
        }

        // Fill missing defaults
        foreach ($ALL_CATEGORIES as $cat) {
            if (!isset($rulesMap[$cat])) {
                $rulesMap[$cat] = [
                    'allowed' => $DEFAULT_RULES[$cat]['allowed'] ?? ['Senior'],
                    'min_age' => $DEFAULT_RULES[$cat]['min_age'] ?? 0,
                    'max_age' => $DEFAULT_RULES[$cat]['max_age'] ?? 120
                ];
            }
        }

        exit(json_encode([
            'success' => true,
            'all_categories' => $ALL_CATEGORIES,
            'rules' => $rulesMap,
            'championship_id' => $activeCid
        ]));
    }

    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $rules = $input['rules'] ?? $_POST['rules'] ?? null;

        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid rule configuration payload.']));
        }

        foreach ($ALL_CATEGORIES as $cat) {
            $catData = $rules[$cat] ?? [];
            if (is_array($catData) && isset($catData['allowed'])) {
                $allowed = is_array($catData['allowed']) ? array_values($catData['allowed']) : [];
                $minAge = isset($catData['min_age']) ? (int)$catData['min_age'] : ($DEFAULT_RULES[$cat]['min_age'] ?? 0);
                $maxAge = isset($catData['max_age']) ? (int)$catData['max_age'] : ($DEFAULT_RULES[$cat]['max_age'] ?? 120);
            } else if (is_array($catData)) {
                $allowed = array_values($catData);
                $minAge = $DEFAULT_RULES[$cat]['min_age'] ?? 0;
                $maxAge = $DEFAULT_RULES[$cat]['max_age'] ?? 120;
            } else {
                $allowed = $DEFAULT_RULES[$cat]['allowed'] ?? ['Senior'];
                $minAge = $DEFAULT_RULES[$cat]['min_age'] ?? 0;
                $maxAge = $DEFAULT_RULES[$cat]['max_age'] ?? 120;
            }

            // Check if record exists for this championship & category
            $chk = $pdo->prepare("SELECT id FROM `age_category_rules` WHERE `championship_id` = ? AND `shooter_category` = ?");
            $chk->execute([$activeCid, $cat]);
            $existingId = $chk->fetchColumn();

            if ($existingId) {
                $stmtSave = $pdo->prepare("UPDATE `age_category_rules` SET `min_age` = ?, `max_age` = ?, `set_age` = ?, `allowed_event_categories` = ? WHERE `id` = ?");
                $stmtSave->execute([$minAge, $maxAge, $maxAge, json_encode($allowed), $existingId]);
            } else {
                $stmtSave = $pdo->prepare("INSERT INTO `age_category_rules` (`championship_id`, `shooter_category`, `min_age`, `max_age`, `set_age`, `allowed_event_categories`) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtSave->execute([$activeCid, $cat, $minAge, $maxAge, $maxAge, json_encode($allowed)]);
            }
        }

        exit(json_encode([
            'success' => true,
            'message' => 'Age category eligibility rules saved successfully for active championship!'
        ]));
    }

    if ($action === 'reset') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        foreach ($DEFAULT_RULES as $cat => $info) {
            $chk = $pdo->prepare("SELECT id FROM `age_category_rules` WHERE `championship_id` = ? AND `shooter_category` = ?");
            $chk->execute([$activeCid, $cat]);
            $existingId = $chk->fetchColumn();

            if ($existingId) {
                $stmtSave = $pdo->prepare("UPDATE `age_category_rules` SET `min_age` = ?, `max_age` = ?, `set_age` = ?, `allowed_event_categories` = ? WHERE `id` = ?");
                $stmtSave->execute([$info['min_age'], $info['max_age'], $info['max_age'], json_encode($info['allowed']), $existingId]);
            } else {
                $stmtSave = $pdo->prepare("INSERT INTO `age_category_rules` (`championship_id`, `shooter_category`, `min_age`, `max_age`, `set_age`, `allowed_event_categories`) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtSave->execute([$activeCid, $cat, $info['min_age'], $info['max_age'], $info['max_age'], json_encode($info['allowed'])]);
            }
        }

        exit(json_encode([
            'success' => true,
            'message' => 'Age category eligibility rules reset to standard ISSF/NRAI defaults for active championship!'
        ]));
    }

    exit(json_encode(['success' => false, 'message' => 'Unknown action.']));

} catch (Throwable $e) {
    error_log('Age categories action error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]));
}
