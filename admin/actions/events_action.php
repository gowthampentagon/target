<?php
/**
 * admin/actions/events_action.php
 * Handles AJAX requests for Events Management (List, Add, Edit, Delete, Import).
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

// ── Helper Functions for Event Fee and MQS Score ──────────────
function getFeeForEvent(string $code, string $name): float {
    $uCode = strtoupper($code);
    $uName = strtoupper($name);
    if (strpos($uCode, 'TEAM') !== false || strpos($uName, 'TEAM') !== false) {
        return 3000.00;
    }
    if (strpos($uCode, '25M') !== false || strpos($uName, '25M') !== false || strpos($uCode, '50M') !== false || strpos($uName, '50M') !== false) {
        return 1500.00;
    }
    return 1000.00;
}

function getMqsForEvent(string $code, string $name, string $category): ?float {
    $uCode = strtoupper($code);
    $uName = strtoupper($name);

    $isMqsCategory = ($category === 'ISSF' || $category === 'NR for MQS' || strpos($uCode, 'MQS') !== false || strpos($uName, 'MQS') !== false);
    if (!$isMqsCategory) {
        return null;
    }

    if (strpos($uName, 'RIFLE') !== false) {
        if (strpos($uName, 'OPEN SIGHT') !== false) {
            return 160.00;
        }
        if (strpos($uName, '50M') !== false || strpos($uName, '3P') !== false || strpos($uName, 'PRONE') !== false) {
            return 560.00;
        }
        if (strpos($uName, 'SUB YOUTH') !== false || strpos($uName, 'YOUTH') !== false) {
            return 560.00;
        }
        if (strpos($uName, 'JUNIOR') !== false) {
            return 565.00;
        }
        return 570.00;
    }

    if (strpos($uName, 'PISTOL') !== false) {
        if (strpos($uName, '25M') !== false || strpos($uName, '50M') !== false || strpos($uName, 'CENTER') !== false || strpos($uName, 'CENTRE') !== false || strpos($uName, 'STANDARD') !== false) {
            return 540.00;
        }
        if (strpos($uName, 'SUB YOUTH') !== false || strpos($uName, 'YOUTH') !== false) {
            return 540.00;
        }
        return 560.00;
    }

    return 560.00;
}

// ── Fetch Active Championship ──────────────────────────
$activeChampionship = getActiveChampionship($pdo);
$activeCid = (int)($activeChampionship['id'] ?? 1);

// ── Ensure `events` Table Exists & Is Seeded ──────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `events` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `championship_id` INT NOT NULL DEFAULT 1,
        `event_code` VARCHAR(50) NOT NULL,
        `event_name` VARCHAR(255) NOT NULL,
        `category` VARCHAR(50) NOT NULL DEFAULT 'NR',
        `age_group` VARCHAR(50) DEFAULT 'Senior',
        `entry_fee` DECIMAL(10,2) DEFAULT 0.00,
        `mqs_score` DECIMAL(6,2) DEFAULT NULL,
        `status` VARCHAR(20) DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (`championship_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    try {
        $pdo->exec("ALTER TABLE `events` ADD COLUMN `championship_id` INT NOT NULL DEFAULT 1 AFTER `id`");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE `events` ADD COLUMN `age_group` VARCHAR(50) DEFAULT 'Senior' AFTER `category`");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE `events` ADD COLUMN `mqs_score` DECIMAL(6,2) DEFAULT NULL AFTER `entry_fee`");
    } catch (Throwable $e) {}

    // 1. Auto-fill entry_fee for existing events if entry_fee is 0
    try {
        $pdo->exec("UPDATE `events` SET `entry_fee` = 3000.00 WHERE (`entry_fee` IS NULL OR `entry_fee` = 0) AND (UPPER(`event_code`) LIKE '%TEAM%' OR UPPER(`event_name`) LIKE '%TEAM%')");
        $pdo->exec("UPDATE `events` SET `entry_fee` = 1500.00 WHERE (`entry_fee` IS NULL OR `entry_fee` = 0) AND (UPPER(`event_code`) LIKE '%25M%' OR UPPER(`event_name`) LIKE '%25M%' OR UPPER(`event_code`) LIKE '%50M%' OR UPPER(`event_name`) LIKE '%50M%')");
        $pdo->exec("UPDATE `events` SET `entry_fee` = 1000.00 WHERE `entry_fee` IS NULL OR `entry_fee` = 0");
    } catch (Throwable $e) {}

    // 2. Auto-fill mqs_score from event_registrations if existing registrations have mqs_score
    try {
        $pdo->exec("UPDATE `events` e 
            JOIN (
                SELECT event_code, event_name, MAX(mqs_score) as mqs_val 
                FROM `event_registrations` 
                WHERE mqs_score IS NOT NULL AND mqs_score > 0 
                GROUP BY event_code, event_name
            ) er ON (
                (er.event_code IS NOT NULL AND er.event_code != '' AND UPPER(e.event_code) = UPPER(er.event_code)) 
                OR UPPER(e.event_name) = UPPER(er.event_name)
            ) 
            SET e.mqs_score = er.mqs_val 
            WHERE e.mqs_score IS NULL");
    } catch (Throwable $e) {}

    // 3. Auto-fill default mqs_score for ISSF / NR for MQS / MQS events where mqs_score is still NULL
    try {
        $stmtMqsNull = $pdo->query("SELECT id, event_code, event_name, category FROM `events` WHERE `mqs_score` IS NULL AND (`category` IN ('ISSF', 'NR for MQS') OR UPPER(`event_code`) LIKE '%MQS%' OR UPPER(`event_name`) LIKE '%MQS%')");
        $nullMqsEvents = $stmtMqsNull->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($nullMqsEvents)) {
            $stmtUpdMqs = $pdo->prepare("UPDATE `events` SET `mqs_score` = ? WHERE `id` = ?");
            foreach ($nullMqsEvents as $evRow) {
                $calcMqs = getMqsForEvent($evRow['event_code'], $evRow['event_name'], $evRow['category']);
                if ($calcMqs !== null) {
                    $stmtUpdMqs->execute([$calcMqs, $evRow['id']]);
                }
            }
        }
    } catch (Throwable $e) {}

    // Auto-seed for current active championship if empty
    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM `events` WHERE `championship_id` = ?");
    $stmtCnt->execute([$activeCid]);
    $count = (int)$stmtCnt->fetchColumn();

    if ($count === 0) {
        // Try associating unassigned events (from initial schema or default championship 1) to active championship
        try {
            $pdo->prepare("UPDATE `events` SET `championship_id` = ?")->execute([$activeCid]);
        } catch (Throwable $e) {}

        // Re-check count
        $stmtCnt->execute([$activeCid]);
        $count = (int)$stmtCnt->fetchColumn();
    }

    if ($count === 0) {
        $eventsFile = dirname(__DIR__, 2) . '/config/events.php';
        if (file_exists($eventsFile)) {
            require $eventsFile;
            if (isset($EVENTS_MAPPING) && is_array($EVENTS_MAPPING)) {
                $stmtInsert = $pdo->prepare("INSERT IGNORE INTO `events` (`championship_id`, `event_code`, `event_name`, `category`, `age_group`, `entry_fee`, `mqs_score`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                
                $seenCodes = [];
                foreach ($EVENTS_MAPPING as $code => $name) {
                    $upperCode = strtoupper(trim((string)$code));
                    if (isset($seenCodes[$upperCode])) continue;
                    $seenCodes[$upperCode] = true;

                    // Categorize
                    $category = 'NR';
                    $upperName = strtoupper($name);
                    if (strpos($upperCode, 'IS') === 0 || strpos($upperName, 'ISSF') !== false) {
                        $category = 'ISSF';
                    } elseif (strpos($upperCode, 'MQS') !== false || strpos($upperName, 'MQS') !== false) {
                        $category = 'NR for MQS';
                    } elseif (strpos($upperCode, 'PARA') !== false || strpos($upperCode, 'DEAF') !== false || strpos($upperName, 'PARA') !== false || strpos($upperName, 'DEAF') !== false) {
                        $category = 'Para/Deaf';
                    }

                    // Detect Age Group
                    $ageGroup = 'Senior';
                    if (strpos($upperName, 'SUB YOUTH') !== false) $ageGroup = 'Sub Youth';
                    elseif (strpos($upperName, 'YOUTH') !== false) $ageGroup = 'Youth';
                    elseif (strpos($upperName, 'JUNIOR') !== false) $ageGroup = 'Junior';
                    elseif (strpos($upperName, 'SUPER MASTER') !== false) $ageGroup = 'Super Master';
                    elseif (strpos($upperName, 'SENIOR MASTER') !== false) $ageGroup = 'Senior Master';
                    elseif (strpos($upperName, 'MASTER') !== false) $ageGroup = 'Master';

                    $fee = getFeeForEvent($upperCode, $name);
                    $mqs = getMqsForEvent($upperCode, $name, $category);

                    $stmtInsert->execute([$activeCid, $upperCode, trim($name), $category, $ageGroup, $fee, $mqs]);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Events table initialization error: ' . $e->getMessage());
}

// Handle Requests
$action = trim($_REQUEST['action'] ?? 'list');

try {
    if ($action === 'list') {
        $category = trim($_GET['category'] ?? 'all');
        $ageGroup = trim($_GET['age_group'] ?? 'all');
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(10, min(500, (int)($_GET['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;

        $where = ["`championship_id` = ?"];
        $params = [$activeCid];

        if ($category !== '' && $category !== 'all') {
            $where[] = "`category` = ?";
            $params[] = $category;
        }

        if ($ageGroup !== '' && $ageGroup !== 'all') {
            if ($ageGroup === 'Senior') {
                $where[] = "(`age_group` = 'Senior' OR (`event_name` LIKE '%SENIOR%' OR (`event_name` NOT LIKE '%SUB YOUTH%' AND `event_name` NOT LIKE '%YOUTH%' AND `event_name` NOT LIKE '%JUNIOR%' AND `event_name` NOT LIKE '%MASTER%')))";
            } else {
                $where[] = "(`age_group` = ? OR `event_name` LIKE ?)";
                $params[] = $ageGroup;
                $params[] = "%{$ageGroup}%";
            }
        }

        if ($search !== '') {
            $where[] = "(`event_code` LIKE ? OR `event_name` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `events` {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        // Fetch data
        $stmt = $pdo->prepare("SELECT * FROM `events` {$whereSql} ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        exit(json_encode([
            'success' => true,
            'data' => $events,
            'total' => $totalRecords,
            'page' => $page,
            'pages' => ceil($totalRecords / $limit)
        ]));
    }

    if ($action === 'add') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $code = strtoupper(trim($_POST['event_code'] ?? ''));
        $name = trim($_POST['event_name'] ?? '');
        $category = trim($_POST['category'] ?? 'NR');
        $fee = (float)($_POST['entry_fee'] ?? 0.00);
        $mqsRaw = trim($_POST['mqs_score'] ?? '');
        $mqsScore = (is_numeric($mqsRaw) && (float)$mqsRaw > 0) ? (float)$mqsRaw : null;
        $status = trim($_POST['status'] ?? 'active');

        if (empty($code) || empty($name)) {
            exit(json_encode(['success' => false, 'message' => 'Event Code and Event Name are required.']));
        }

        // Check duplicate code for this championship
        $chk = $pdo->prepare("SELECT id FROM `events` WHERE `championship_id` = ? AND `event_code` = ? LIMIT 1");
        $chk->execute([$activeCid, $code]);
        if ($chk->fetch()) {
            exit(json_encode(['success' => false, 'message' => "Event Code '{$code}' already exists for the active championship."]));
        }

        $stmt = $pdo->prepare("INSERT INTO `events` (`championship_id`, `event_code`, `event_name`, `category`, `entry_fee`, `mqs_score`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$activeCid, $code, $name, $category, $fee, $mqsScore, $status]);

        exit(json_encode(['success' => true, 'message' => 'Event added successfully to active championship!']));
    }

    if ($action === 'edit') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim($_POST['event_code'] ?? ''));
        $name = trim($_POST['event_name'] ?? '');
        $category = trim($_POST['category'] ?? 'NR');
        $fee = (float)($_POST['entry_fee'] ?? 0.00);
        $mqsRaw = trim($_POST['mqs_score'] ?? '');
        $mqsScore = (is_numeric($mqsRaw) && (float)$mqsRaw > 0) ? (float)$mqsRaw : null;
        $status = trim($_POST['status'] ?? 'active');

        if ($id <= 0 || empty($code) || empty($name)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid event ID or missing parameters.']));
        }

        // Check duplicate code for other records in this championship
        $chk = $pdo->prepare("SELECT id FROM `events` WHERE `championship_id` = ? AND `event_code` = ? AND id != ? LIMIT 1");
        $chk->execute([$activeCid, $code, $id]);
        if ($chk->fetch()) {
            exit(json_encode(['success' => false, 'message' => "Event Code '{$code}' is already used by another event in this championship."]));
        }

        $stmt = $pdo->prepare("UPDATE `events` SET `event_code` = ?, `event_name` = ?, `category` = ?, `entry_fee` = ?, `mqs_score` = ?, `status` = ? WHERE `id` = ? AND `championship_id` = ?");
        $stmt->execute([$code, $name, $category, $fee, $mqsScore, $status, $id, $activeCid]);

        exit(json_encode(['success' => true, 'message' => 'Event updated successfully!']));
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            exit(json_encode(['success' => false, 'message' => 'Invalid Event ID.']));
        }

        $stmt = $pdo->prepare("DELETE FROM `events` WHERE id = ? AND championship_id = ?");
        $stmt->execute([$id, $activeCid]);

        exit(json_encode(['success' => true, 'message' => 'Event deleted successfully!']));
    }

    if ($action === 'import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['success' => false, 'message' => 'Invalid request method.']));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $rows = $input['rows'] ?? [];

        if (!is_array($rows) || empty($rows)) {
            exit(json_encode(['success' => false, 'message' => 'No event rows received for import.']));
        }

        $stmtInsert = $pdo->prepare("INSERT INTO `events` (`championship_id`, `event_code`, `event_name`, `category`, `entry_fee`, `mqs_score`, `status`) 
            VALUES (?, ?, ?, ?, ?, ?, 'active') 
            ON DUPLICATE KEY UPDATE `event_name` = VALUES(`event_name`), `category` = VALUES(`category`), `entry_fee` = VALUES(`entry_fee`), `mqs_score` = VALUES(`mqs_score`)");

        $importedCount = 0;
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['event_code'] ?? $row['code'] ?? $row['Event ID'] ?? $row['Event Code'] ?? '')));
            $name = trim((string)($row['event_name'] ?? $row['name'] ?? $row['Event Name'] ?? ''));
            $category = trim((string)($row['category'] ?? $row['Category'] ?? 'NR'));
            $fee = (float)($row['entry_fee'] ?? $row['fee'] ?? $row['Entry Fee'] ?? 0.00);
            $mqsRaw = trim((string)($row['mqs_score'] ?? $row['mqs'] ?? $row['MQS Score'] ?? $row['MQS'] ?? ''));
            $mqsScore = (is_numeric($mqsRaw) && (float)$mqsRaw > 0) ? (float)$mqsRaw : null;

            if (empty($code) || empty($name)) continue;

            // Normalize category
            if (!in_array($category, ['NR', 'ISSF', 'NR for MQS', 'Para/Deaf'], true)) {
                $upperName = strtoupper($name);
                if (strpos($code, 'IS') === 0 || strpos($upperName, 'ISSF') !== false) {
                    $category = 'ISSF';
                } elseif (strpos($code, 'MQS') !== false || strpos($upperName, 'MQS') !== false) {
                    $category = 'NR for MQS';
                } elseif (strpos($code, 'PARA') !== false || strpos($code, 'DEAF') !== false || strpos($upperName, 'PARA') !== false || strpos($upperName, 'DEAF') !== false) {
                    $category = 'Para/Deaf';
                } else {
                    $category = 'NR';
                }
            }

            $stmtInsert->execute([$activeCid, $code, $name, $category, $fee, $mqsScore]);
            $importedCount++;
        }

        exit(json_encode(['success' => true, 'message' => "Successfully imported/updated {$importedCount} events for active championship!"]));
    }

    exit(json_encode(['success' => false, 'message' => 'Unknown action.']));

} catch (Throwable $e) {
    error_log('Events action error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]));
}
