<?php
// admin/actions/auto_allocate.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$action = $_POST['action'] ?? 'calculate';

$eventName    = trim($_POST['weapon_type'] ?? '');
$scheduleJson  = trim($_POST['schedule'] ?? '');
$eventIdsJson  = trim($_POST['event_ids'] ?? ''); // direct DB event codes from lane_allocations.php

if (empty($eventName)) {
    exit(json_encode(['success' => false, 'message' => 'Event type is required.']));
}

$schedule = json_decode($scheduleJson, true);
if (!is_array($schedule) || empty($schedule)) {
    exit(json_encode(['success' => false, 'message' => 'No active relays or schedule configured. Please set them up in the summary table first.']));
}

try {
    $pdo = getDB();

    // Ensure relay_schedules table & lane_allocations columns exist
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `relay_schedules` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `event_name` VARCHAR(255) NOT NULL,
              `schedule_name` VARCHAR(255) DEFAULT '',
              `scheduled_date` DATE NOT NULL,
              `relay_no` INT NOT NULL,
              `reporting_time` TIME NOT NULL,
              `start_time` TIME NOT NULL,
              `relay_limit` INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->exec("ALTER TABLE `relay_schedules` ADD `relay_limit` INT DEFAULT NULL");
    } catch (Throwable $t) {}

    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `target_serial_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `custom_name` VARCHAR(255) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `bib_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `is_locked` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `score_sheet` LONGTEXT NULL DEFAULT NULL"); } catch (Throwable $t) {}

    // --- Determine the list of DB event IDs to query ---
    // Prefer the directly-supplied event_ids (exact DB codes: IS-54, R004, etc.)
    $directEventIds = [];
    if (!empty($eventIdsJson)) {
        $decoded = json_decode($eventIdsJson, true);
        if (is_array($decoded)) {
            $directEventIds = array_values(array_filter(array_unique($decoded)));
        }
    }

    // Fallback: reverse-map via EVENTS_MAPPING if no direct IDs supplied
    if (empty($directEventIds)) {
        $baseKey = getBackendEventBaseType($eventName);
        if (empty($baseKey)) {
            $baseKey = strtolower(str_replace(' ', '_', $eventName));
        }

        $allEventIds = [];
        foreach ($EVENTS_MAPPING as $id => $fullName) {
            if (getBackendEventBaseType($fullName) === $baseKey) {
                $allEventIds[] = $id;
            }
        }
        $allEventIds[] = $eventName;
        $allEventIds[] = $baseKey;
        $allEventIds = array_values(array_unique($allEventIds));
    } else {
        // Use the direct IDs; also derive baseKey for category detection
        $baseKey = getBackendEventBaseType($eventName);
        if (empty($baseKey)) {
            $baseKey = strtolower(str_replace(' ', '_', $eventName));
        }
        $allEventIds = $directEventIds;
    }

    $inClause = implode(',', array_fill(0, count($allEventIds), '?'));

    // Determine category filter from baseKey suffix
    // When direct event IDs are supplied, we still filter by category so we don't mix ISSF/NR
    $categoryFilter = '';
    if (substr($baseKey, -5) === '_issf') {
        $categoryFilter = 'ISSF';
    } elseif (substr($baseKey, -3) === '_nr') {
        $categoryFilter = 'NR';
    } elseif (substr($baseKey, -10) === '_para_deaf') {
        $categoryFilter = 'PARA_DEAF';
    }

    $catQuery = "";
    $queryParams = $allEventIds;
    if (!empty($categoryFilter)) {
        if ($categoryFilter === 'NR') {
            $catQuery = " AND (er.category IS NULL OR er.category = '' OR UPPER(er.category) IN ('NR', 'NR_MQS', 'NR-MQS')) ";
        } elseif ($baseKey === '10m_air_pistol_issf') {
            $catQuery = " AND (UPPER(er.category) LIKE '%ISSF%' OR UPPER(er.category) LIKE '%PARA%' OR UPPER(er.category) LIKE '%DEAF%' OR er.category IS NULL OR er.category = '') ";
        } else {
            $catQuery = " AND UPPER(er.category) LIKE ? ";
            $queryParams[] = '%' . strtoupper($categoryFilter) . '%';
        }
    }

    if ($action === 'generate') {
        // 1. Clear ALL existing lane allocations for matching events (widen: ignore category filter here)
        $stmt = $pdo->prepare("
            DELETE la FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE UPPER(er.event_name) IN ($inClause)
        ");
        $stmt->execute($allEventIds);
        // Also delete by event_code in case event_name differs
        $stmt2 = $pdo->prepare("
            DELETE la FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE UPPER(er.event_code) IN ($inClause)
        ");
        $stmt2->execute($allEventIds);
        cleanupOrphanManualRegistrations($pdo);
    }

    // 2. Fetch all approved/active shooters for matching event IDs and category
    $stmt = $pdo->prepare("
        SELECT er.id AS event_reg_id, er.user_id, er.event_name, r.club_name
        FROM event_registrations er
        JOIN registrations r ON er.user_id = r.id
        WHERE er.event_name IN ($inClause) $catQuery 
          AND (er.status IS NULL OR er.status = '' OR LOWER(er.status) IN ('approved', 'active', 'pending'))
        ORDER BY er.event_name ASC, r.club_name ASC
    ");
    $stmt->execute($queryParams);
    $rawShooters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate by user_id so a shooter participating in multiple events under the same weapon group is treated as 1 participant
    $shooters = [];
    $seenUsers = [];
    foreach ($rawShooters as $s) {
        $uId = (int)($s['user_id'] ?? 0);
        if ($uId > 0) {
            if (isset($seenUsers[$uId])) continue;
            $seenUsers[$uId] = true;
        }
        $shooters[] = $s;
    }

    // Gather manual club counts from POST (calculate action) or from allocs (generate action)
    $manualClubTotals = [];

    // 1. From manual_clubs json
    $manualClubsJson = trim($_POST['manual_clubs'] ?? '');
    if (!empty($manualClubsJson)) {
        $decoded = json_decode($manualClubsJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $clubName => $count) {
                $clubLower = strtolower($clubName);
                $manualClubTotals[$clubLower] = [
                    'name' => $clubName,
                    'count' => (int)$count
                ];
            }
        }
    }

    // 2. From allocs grid json
    $allocsInput = json_decode($_POST['allocs'] ?? '{}', true);
    if (is_array($allocsInput)) {
        foreach ($allocsInput as $clubName => $dates) {
            $clubLower = strtolower($clubName);
            $totalAlloc = 0;
            if (is_array($dates)) {
                foreach ($dates as $date => $relays) {
                    if (is_array($relays)) {
                        foreach ($relays as $rNo => $count) {
                            $totalAlloc += (int)$count;
                        }
                    }
                }
            }
            if ($totalAlloc > 0) {
                if (!isset($manualClubTotals[$clubLower]) || $manualClubTotals[$clubLower]['count'] < $totalAlloc) {
                    $manualClubTotals[$clubLower] = [
                        'name' => $clubName,
                        'count' => $totalAlloc
                    ];
                }
            }
        }
    }

    // Group real database shooters by club name
    $clubRealShooters = [];
    foreach ($shooters as $s) {
        $clubRealShooters[strtolower($s['club_name'])][] = $s;
    }

    // Create empty/vacant slots for manual entry without pre-populating shooter data
    $clubShooters = [];
    $evtCodeToUse = !empty($allEventIds) ? $allEventIds[0] : $eventName;

    if (!empty($manualClubTotals)) {
        foreach ($manualClubTotals as $clubLower => $meta) {
            $requiredCount = (int)$meta['count'];
            $pool = [];
            for ($i = 0; $i < $requiredCount; $i++) {
                $pool[] = [
                    'event_reg_id' => null,
                    'event_name'   => $evtCodeToUse,
                    'club_name'    => $meta['name'],
                ];
            }
            $clubShooters[$clubLower] = $pool;
        }
    } else {
        // Fallback: generate empty slots for requested relay capacities
        $pool = [];
        $totalSlots = 0;
        foreach ($schedule as $d) {
            if (isset($d['relays']) && is_array($d['relays'])) {
                foreach ($d['relays'] as $r) {
                    $rLimit = isset($r['limit']) && $r['limit'] !== '' ? (int)$r['limit'] : 1;
                    $totalSlots += max(1, $rLimit);
                }
            }
        }
        for ($i = 0; $i < max(1, $totalSlots); $i++) {
            $pool[] = [
                'event_reg_id' => null,
                'event_name'   => $evtCodeToUse,
                'club_name'    => '',
            ];
        }
        $clubShooters['vacant'] = $pool;
    }

    // Flatten to reconstruct the $shooters list
    $shooters = [];
    foreach ($clubShooters as $pool) {
        $shooters = array_merge($shooters, $pool);
    }

    if (empty($shooters)) {
        exit(json_encode(['success' => false, 'message' => "No approved registrations found for event type '{$eventName}'."]));
    }

    // 3. Parse active relays from the schedule payload
    $relaysList = [];
    foreach ($schedule as $d) {
        $dateStr = trim($d['date'] ?? '');
        if (empty($dateStr)) continue;
        
        $normalizedDate = str_replace(['/', '.'], '-', $dateStr);
        $timestamp = strtotime($normalizedDate);
        
        $dbDate = $dateStr;
        if ($timestamp !== false) {
            $dbDate = date('Y-m-d', $timestamp);
        }
        
        if (isset($d['relays']) && is_array($d['relays'])) {
            foreach ($d['relays'] as $r) {
                $relaysList[] = [
                    'date'     => $dbDate,
                    'original_date' => $dateStr,
                    'relay_no' => (int)($r['r'] ?? 0),
                    'rep'      => trim($r['rep'] ?? ''),
                    'st'       => trim($r['st'] ?? ''),
                    'limit'    => isset($r['limit']) && $r['limit'] !== '' ? (int)$r['limit'] : null
                ];
            }
        }
    }

    if (empty($relaysList)) {
        exit(json_encode(['success' => false, 'message' => 'No active relays or dates configured in the summary schedule.']));
    }

    $capacity = getRangeCapacity($eventName);
    $totalCapacity = 0;
    foreach ($relaysList as &$relayInfo) {
        if ($relayInfo['limit'] === null) {
            $relayInfo['limit'] = $capacity;
        }
        if ($relayInfo['limit'] > $capacity) {
            exit(json_encode([
                'success' => false,
                'message' => "Relay limit ({$relayInfo['limit']}) cannot exceed the maximum range capacity of {$capacity} firing points."
            ]));
        }
        $totalCapacity += $relayInfo['limit'];
    }
    unset($relayInfo);

    $totalShooters = count($shooters);
    $numRelays = count($relaysList);

    if ($totalShooters > $totalCapacity) {
        exit(json_encode([
            'success' => false,
            'message' => "More participants present ({$totalShooters} registered) than the total available capacity ({$totalCapacity} = sum of relay capacities). Please add extra dates or extra relays."
        ]));
    }

    // 4. Distribute shooters to relays
    $relayPools = array_fill(0, $numRelays, []);

    $allocsInput = json_decode($_POST['allocs'] ?? '{}', true);
    $allocsInputCount = 0;
    if (is_array($allocsInput)) {
        foreach ($allocsInput as $club => $dates) {
            if (is_array($dates)) {
                foreach ($dates as $dDate => $relays) {
                    if (is_array($relays)) {
                        foreach ($relays as $rNo => $c) {
                            $allocsInputCount += (int)$c;
                        }
                    }
                }
            }
        }
    }

    $forceAuto = !empty($_POST['force_auto']) && $_POST['force_auto'] === '1';

    if ($action === 'generate' && $allocsInputCount > 0 && !$forceAuto) {
        // Validate each relay sum in $allocsInput
        foreach ($relaysList as $relayInfo) {
            $dDate = $relayInfo['original_date'];
            $rNo = (int)$relayInfo['relay_no'];
            
            $relaySum = 0;
            foreach ($allocsInput as $club => $dates) {
                if (isset($dates[$dDate]) && isset($dates[$dDate][$rNo])) {
                    $relaySum += (int)$dates[$dDate][$rNo];
                }
            }
            if ($relaySum > $relayInfo['limit']) {
                exit(json_encode([
                    'success' => false,
                    'message' => "Relay R-{$rNo} on {$dDate} has {$relaySum} allocated shooters, which exceeds the limit of {$relayInfo['limit']} firing points."
                ]));
            }
        }
        
        $clubShooters = [];
        foreach ($shooters as $s) {
            $clubShooters[strtolower($s['club_name'])][] = $s;
        }
        
        foreach ($relaysList as $rIdx => $relayInfo) {
            $dDate = $relayInfo['original_date'];
            $rNo = (int)$relayInfo['relay_no'];
            
            $pool = [];
            foreach ($allocsInput as $club => $dates) {
                $clubLower = strtolower($club);
                if (isset($dates[$dDate]) && isset($dates[$dDate][$rNo])) {
                    $count = (int)$dates[$dDate][$rNo];
                    if ($count > 0 && isset($clubShooters[$clubLower])) {
                        for ($i = 0; $i < $count; $i++) {
                            if (!empty($clubShooters[$clubLower])) {
                                $pool[] = array_shift($clubShooters[$clubLower]);
                            }
                        }
                    }
                }
            }
            $relayPools[$rIdx] = $pool;
        }
    } else {
        // Random assignment that respects capacity limits across relays
        shuffle($shooters);
        $relayCounts = array_fill(0, $numRelays, 0);
        foreach ($shooters as $s) {
            $availableRelays = [];
            for ($r = 0; $r < $numRelays; $r++) {
                if ($relayCounts[$r] < $relaysList[$r]['limit']) {
                    $availableRelays[] = $r;
                }
            }
            if (!empty($availableRelays)) {
                $targetR = $availableRelays[array_rand($availableRelays)];
                $relayPools[$targetR][] = $s;
                $relayCounts[$targetR]++;
            } else {
                $relayPools[0][] = $s;
            }
        }
    }

    $allocsMatrix = [];
    foreach ($relayPools as $rIdx => $pool) {
        if (empty($pool)) continue;
        $relayInfo = $relaysList[$rIdx];
        $dDate = $relayInfo['original_date'];
        $rNo = $relayInfo['relay_no'];

        foreach ($pool as $s) {
            $club = strtolower($s['club_name']);

            if (!isset($allocsMatrix[$club])) $allocsMatrix[$club] = [];
            if (!isset($allocsMatrix[$club][$dDate])) $allocsMatrix[$club][$dDate] = [];
            if (!isset($allocsMatrix[$club][$dDate][$rNo])) $allocsMatrix[$club][$dDate][$rNo] = 0;
            $allocsMatrix[$club][$dDate][$rNo]++;
        }
    }

    if ($action === 'calculate') {
        echo json_encode(['success' => true, 'message' => 'Allocation matrix calculated successfully.', 'allocs' => $allocsMatrix]);
        exit;
    }

    // 5. Order each relay pool and allocate lanes (GENERATE DB)
    // Use INSERT IGNORE to skip any duplicate event_reg_id entries gracefully
    $ins = $pdo->prepare("
        INSERT IGNORE INTO lane_allocations (event_reg_id, relay_no, lane_no, scheduled_date, reporting_time, start_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    // Ensure relay_club_quotas table exists BEFORE transaction (DDL causes implicit commit in MySQL)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `relay_club_quotas` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `championship_id` INT NOT NULL DEFAULT 1,
              `event_base_type` VARCHAR(100) NOT NULL,
              `club_name` VARCHAR(255) NOT NULL,
              `scheduled_date` DATE NOT NULL,
              `relay_no` INT NOT NULL,
              `quota` INT NOT NULL DEFAULT 0,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uniq_quota` (`championship_id`, `event_base_type`, `club_name`, `scheduled_date`, `relay_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}

    $pdo->beginTransaction();
    try {
        // Clear existing lane allocations and active schedule for this weapon panel
        if (!empty($directEventIds)) {
            $inEvt = implode(',', array_fill(0, count($directEventIds), '?'));
            $delOldAlloc = $pdo->prepare("
                DELETE la FROM lane_allocations la
                JOIN event_registrations er ON la.event_reg_id = er.id
                WHERE er.event_name IN ($inEvt) OR er.event_code IN ($inEvt)
            ");
            $delOldAlloc->execute(array_merge($directEventIds, $directEventIds));
        }

        $delSched = $pdo->prepare("DELETE FROM relay_schedules WHERE (LOWER(event_name) = LOWER(?) OR LOWER(event_name) = LOWER(?)) AND schedule_name = ''");
        $delSched->execute([$eventName, $baseKey]);

        // Insert new schedule in relay_schedules
        $insSched = $pdo->prepare("INSERT INTO relay_schedules (event_name, schedule_name, scheduled_date, relay_no, reporting_time, start_time, relay_limit) VALUES (?, '', ?, ?, ?, ?, ?)");
        foreach ($schedule as $d) {
            $dateStr = trim($d['date'] ?? '');
            if (empty($dateStr)) continue;
            $normalizedDate = str_replace(['/', '.'], '-', $dateStr);
            $timestamp = strtotime($normalizedDate);
            $dbDate = $dateStr;
            if ($timestamp !== false) {
                $dbDate = date('Y-m-d', $timestamp);
            }
            if (isset($d['relays']) && is_array($d['relays'])) {
                foreach ($d['relays'] as $r) {
                    $rNo = (int)($r['r'] ?? 0);
                    $rep = trim($r['rep'] ?? '');
                    $st = trim($r['st'] ?? '');
                    $rLimit = isset($r['limit']) && $r['limit'] !== '' ? (int)$r['limit'] : null;
                    if ($rNo > 0 && !empty($rep) && !empty($st)) {
                        $insSched->execute([$eventName, $dbDate, $rNo, $rep, $st, $rLimit]);
                    }
                }
            }
        }

        // Insert new lane_allocations for all allocated shooters in relay pools
        foreach ($relayPools as $rIdx => $pool) {
            if (empty($pool)) continue;
            $relayInfo = $relaysList[$rIdx];
            $dDate = $relayInfo['date'];
            $rNo = (int)$relayInfo['relay_no'];
            $repTime = $relayInfo['rep'];
            $stTime = $relayInfo['st'];

            $laneNo = 1;
            foreach ($pool as $shooter) {
                $evRegId = (int)($shooter['event_reg_id'] ?? 0);
                if ($evRegId <= 0) {
                    $cName = !empty($shooter['club_name']) ? $shooter['club_name'] : 'MANUAL';
                    $eNameVal = !empty($shooter['event_name']) ? $shooter['event_name'] : $eventName;
                    $evRegId = getOrCreateVacantEventRegId($pdo, $eNameVal, 'NR', $cName);
                }

                if ($evRegId > 0) {
                    $ins->execute([
                        $evRegId,
                        $rNo,
                        $laneNo,
                        $dDate,
                        $repTime,
                        $stTime
                    ]);
                    $laneNo++;
                }
            }
        }

        $activeChampionship = getActiveChampionship($pdo);
        $activeCid = (int)($activeChampionship['id'] ?? 1);

        // Delete previous quotas for this event/championship
        $delQuotas = $pdo->prepare("DELETE FROM relay_club_quotas WHERE championship_id = ? AND (LOWER(event_base_type) = LOWER(?) OR LOWER(event_base_type) = LOWER(?) OR LOWER(event_base_type) = LOWER(?))");
        $delQuotas->execute([$activeCid, $eventName, $baseKey, formatBaseEventName($baseKey)]);

        // Save new quotas: prioritize manual edits ($allocsInput) unless force_auto is requested
        $quotasToSave = (!empty($allocsInput) && !$forceAuto) ? $allocsInput : (!empty($allocsMatrix) ? $allocsMatrix : $allocsInput);
        $insQuota = $pdo->prepare("
            INSERT INTO relay_club_quotas 
                (championship_id, event_base_type, club_name, scheduled_date, relay_no, quota)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quota = VALUES(quota)
        ");

        foreach ($quotasToSave as $club => $dates) {
            $clubTrimmed = trim((string)$club);
            if (empty($clubTrimmed)) continue;
            if (is_array($dates)) {
                foreach ($dates as $dDate => $relays) {
                    $normDate = str_replace(['/', '.'], '-', (string)$dDate);
                    $ts = strtotime($normDate);
                    $dbDateQuota = ($ts !== false) ? date('Y-m-d', $ts) : $dDate;

                    if (is_array($relays)) {
                        foreach ($relays as $rNo => $qVal) {
                            $qInt = (int)$qVal;
                            if ($qInt > 0) {
                                $insQuota->execute([$activeCid, $eventName, $clubTrimmed, $dbDateQuota, (int)$rNo, $qInt]);
                            }
                        }
                    }
                }
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $respAllocs = !empty($allocsMatrix) ? $allocsMatrix : $allocsInput;
        echo json_encode(['success' => true, 'message' => 'Relay schedule and club quotas saved successfully.', 'allocs' => $respAllocs]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
