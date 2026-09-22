<?php
// admin/actions/import_registrations.php
declare(strict_types=1);
@set_time_limit(300);
@ini_set('memory_limit', '512M');
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('calculateEventFeeBackend')) {
    function calculateEventFeeBackend(string $eventName, array $eventsList = []): float {
        global $EVENTS_LIST;
        if (empty($eventsList) && !empty($EVENTS_LIST)) {
            $eventsList = $EVENTS_LIST;
        }
        $eventObj = $eventsList[$eventName] ?? null;
        if (!$eventObj) {
            $upperName = strtoupper($eventName);
            $isTeam = (strpos($upperName, 'TEAM') !== false);
            $is25m = (strpos($upperName, '25M') !== false);
            $is50m = (strpos($upperName, '50M') !== false);
            if ($isTeam) return 3540.00;
            if ($is25m || $is50m) return 1770.00;
            return 1180.00;
        }
        $label = strtoupper($eventObj['label'] ?? '');
        $group = strtoupper($eventObj['group'] ?? '');

        $isTeam = (strpos($label, 'TEAM') !== false || strpos($group, 'TEAM') !== false);
        $is10m = (strpos($label, '10M') !== false || strpos($group, '10M') !== false);
        $is25m = (strpos($label, '25M') !== false || strpos($group, '25M') !== false);
        $is50m = (strpos($label, '50M') !== false || strpos($group, '50M') !== false);

        $base = 1000.00;
        if ($isTeam) {
            $base = 3000.00;
        } else if ($is10m) {
            $base = 1000.00;
        } else if ($is25m || $is50m) {
            $base = 1500.00;
        } else {
            $base = 1000.00;
        }
        return round($base * 1.18, 2);
    }
}

try {
    $pdo = getDB();
    $eventsList = $EVENTS_LIST ?? [];
    $rows = $GLOBALS['import_test_rows'] ?? [];

    // 1. Handle JSON Payload (from client-side parsed XLSX/CSV)
    if (empty($rows)) {
        $inputJSON = file_get_contents('php://input');
        if (!empty($inputJSON)) {
            $decoded = json_decode($inputJSON, true);
            if (is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])) {
                $rows = $decoded['rows'];
            }
        }
    }

    // 2. Handle File Upload Fallback (.csv)
    if (empty($rows) && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['import_file']['tmp_name'];
        $handle = fopen($fileTmp, 'r');
        if ($handle !== false) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $header = null;
            while (($data = fgetcsv($handle, 2000, ',')) !== false) {
                if (!$header) {
                    $header = array_map('trim', $data);
                } else {
                    if (count($data) === count($header)) {
                        $rows[] = array_combine($header, $data);
                    }
                }
            }
            fclose($handle);
        }
    }

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'No valid data rows found in the imported file.']);
        exit;
    }

    $insertedUsers = 0;
    $updatedUsers = 0;
    $insertedEvents = 0;
    $updatedEvents = 0;
    $insertedAllocations = 0;
    $updatedAllocations = 0;
    $errors = [];

    // Auto-ensure required columns exist in lane_allocations
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `target_serial_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `custom_name` VARCHAR(255) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `bib_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}

    // Helper: Map flexible column names to standard keys
    $normalizeRow = function(array $row) {
        $norm = [];
        $eventsList = [];

        foreach ($row as $key => $val) {
            $k = strtolower(trim((string)$key));
            $kNorm = str_replace(['_', '-'], ' ', $k);
            $v = trim((string)$val);

            $matchKey = function(array $aliases) use ($k, $kNorm) {
                return in_array($k, $aliases) || in_array($kNorm, $aliases);
            };

            // Registration Fields
            if ($matchKey(['reg id', 'enrollment id', 'enrollment no', 'bib no', 'bib', 'shooter id', 'competitor id', 'comp no', 'id'])) {
                $norm['reg_id'] = $v;
            } elseif ($matchKey(['participant name', 'shooter name', 'competitor name', 'full name', 'name', 'participant', 'shooter', 'competitor', 'name of shooter', 'name of the shooter', 'name of competitor', 'name of the competitor'])) {
                $norm['full_name'] = $v;
            } elseif ($matchKey(['first name', 'fname'])) {
                $norm['first_name'] = $v;
            } elseif ($matchKey(['last name', 'lname'])) {
                $norm['last_name'] = $v;
            } elseif ($matchKey(['email', 'email address'])) {
                $norm['email'] = $v;
            } elseif ($matchKey(['phone', 'mobile', 'contact', 'phone number', 'mobile number'])) {
                $norm['phone'] = $v;
            } elseif ($matchKey(['aadhaar number', 'aadhaar', 'national id', 'aadhaar no'])) {
                $norm['aadhaar_number'] = $v;
            } elseif ($matchKey(['club name', 'club', 'unit', 'association name'])) {
                $norm['club_name'] = $v;
            } elseif ($matchKey(['district', 'city'])) {
                $norm['district'] = $v;
            } elseif ($matchKey(['state association', 'association', 'state'])) {
                $norm['association'] = $v;
            } elseif ($matchKey(['father name', 'father guardian name', 'guardian name'])) {
                $norm['father_guardian_name'] = $v;
            } elseif ($matchKey(['address'])) {
                $norm['address'] = $v;
            } elseif ($matchKey(['dob', 'date of birth', 'birthdate'])) {
                $norm['dob'] = $v;
            } elseif ($matchKey(['gender', 'sex'])) {
                $norm['gender'] = ucfirst(strtolower($v));
            } elseif ($matchKey(['is para'])) {
                $norm['is_para'] = in_array(strtolower($v), ['1', 'true', 'yes']) ? 1 : 0;
            } elseif ($matchKey(['is deaf'])) {
                $norm['is_deaf'] = in_array(strtolower($v), ['1', 'true', 'yes']) ? 1 : 0;
            }
            
            // Event Registration Fields
            elseif ($matchKey(['event name', 'event', 'events', 'discipline', 'match name', 'match', 'matches', 'registered events', 'competition'])) {
                $norm['event_name'] = $v;
            } elseif (preg_match('/^event\s*\d+$/i', $k)) {
                if ($v !== '') $eventsList[] = $v;
            } elseif ($matchKey(['category', 'cat', 'match type', 'event category'])) {
                $norm['category'] = $v;
            } elseif ($matchKey(['weapon type', 'weapon'])) {
                $norm['weapon_type'] = $v;
            } elseif ($matchKey(['age group', 'age', 'age category'])) {
                $norm['age_group'] = $v;
            } elseif ($matchKey(['match no', 'match #', 'match number'])) {
                $norm['match_no'] = $v;
            } elseif ($matchKey(['issf number', 'issf no', 'issf id'])) {
                $norm['issf_number'] = $v;
            } elseif ($matchKey(['mqs score', 'mqs'])) {
                $norm['mqs_score'] = is_numeric($v) ? (float)$v : null;
            } elseif ($matchKey(['best score', 'score'])) {
                $norm['best_score'] = is_numeric($v) ? (float)$v : null;
            } elseif ($matchKey(['entry fee', 'fee', 'amount', 'paid amount', 'amount paid', 'total amount', 'total fee', 'registration fee', 'fee paid', 'paid', 'price', 'total', 'paid fee', 'fee amount'])) {
                $cleanFeeStr = preg_replace('/[^\d.]/', '', (string)$v);
                if (is_numeric($cleanFeeStr) && (float)$cleanFeeStr >= 0) {
                    $norm['entry_fee'] = (float)$cleanFeeStr;
                    $norm['paid_amount'] = (float)$cleanFeeStr;
                }
            }
            
            // Lane Allocation Fields
            elseif ($matchKey(['relay no', 'relay', 'rly', 'relay number'])) {
                $norm['relay_no'] = is_numeric($v) ? (int)$v : null;
            } elseif ($matchKey(['lane no', 'lane', 'firing point', 'fp', 'lane number'])) {
                $norm['lane_no'] = is_numeric($v) ? (int)$v : null;
            } elseif ($matchKey(['scheduled date', 'date', 'match date'])) {
                $norm['scheduled_date'] = $v;
            } elseif ($matchKey(['start time', 'st time', 'time'])) {
                $norm['start_time'] = $v;
            } elseif ($matchKey(['reporting time'])) {
                $norm['reporting_time'] = $v;
            } elseif ($matchKey(['target serial no', 'serial no', 'target', 'target no'])) {
                $norm['target_serial_no'] = $v;
            }
        }

        if (!empty($eventsList)) {
            $norm['events_list'] = $eventsList;
        }

        return $norm;
    };

    // Prepared statements for registrations
    $findUserStmt = $pdo->prepare("
        SELECT id, reg_id FROM registrations 
        WHERE (? != '' AND reg_id IS NOT NULL AND reg_id != '' AND reg_id = ?)
           OR (? != '' AND email IS NOT NULL AND email != '' AND email = ?)
           OR (? != '' AND aadhaar_number IS NOT NULL AND aadhaar_number != '' AND aadhaar_number = ?)
        LIMIT 1
    ");

    $updateUserStmt = $pdo->prepare("
        UPDATE registrations 
        SET first_name = ?, last_name = ?, phone = ?, club_name = ?, district = ?, dob = ?, gender = ?,
            association = COALESCE(NULLIF(?, ''), association),
            father_guardian_name = COALESCE(NULLIF(?, ''), father_guardian_name),
            address = COALESCE(NULLIF(?, ''), address),
            is_para = COALESCE(?, is_para),
            is_deaf = COALESCE(?, is_deaf),
            reg_id = COALESCE(NULLIF(?, ''), reg_id)
        WHERE id = ?
    ");

    $insertUserStmt = $pdo->prepare("
        INSERT INTO registrations 
        (reg_id, first_name, last_name, email, phone, aadhaar_number, club_name, district, dob, gender, association, father_guardian_name, address, is_para, is_deaf, status, is_verified, password_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?)
    ");

    // Prepared statements for event_registrations
    $findEventRegStmt = $pdo->prepare("
        SELECT id, category FROM event_registrations 
        WHERE user_id = ? AND (UPPER(event_name) = UPPER(?) OR UPPER(event_code) = UPPER(?))
        LIMIT 1
    ");

    $updateEventRegStmt = $pdo->prepare("
        UPDATE event_registrations 
        SET category = ?,
            session_id = COALESCE(?, session_id),
            weapon_type = COALESCE(NULLIF(?, ''), weapon_type),
            age_group = COALESCE(NULLIF(?, ''), age_group),
            match_no = COALESCE(NULLIF(?, ''), match_no),
            issf_number = COALESCE(NULLIF(?, ''), issf_number),
            mqs_score = COALESCE(?, mqs_score),
            best_score = COALESCE(?, best_score),
            entry_fee = COALESCE(NULLIF(?, 0), entry_fee),
            status = 'approved'
        WHERE id = ?
    ");

    $insertEventRegStmt = $pdo->prepare("
        INSERT INTO event_registrations 
        (event_reg_id, user_id, session_id, category, event_code, event_name, weapon_type, age_group, match_no, issf_number, mqs_score, best_score, entry_fee, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
    ");

    // Prepared statements for lane_allocations
    $findAllocStmt = $pdo->prepare("
        SELECT id FROM lane_allocations WHERE event_reg_id = ? LIMIT 1
    ");

    $insertAllocStmt = $pdo->prepare("
        INSERT INTO lane_allocations 
        (event_reg_id, bib_no, target_serial_no, relay_no, lane_no, scheduled_date, reporting_time, start_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateAllocStmt = $pdo->prepare("
        UPDATE lane_allocations 
        SET bib_no = COALESCE(NULLIF(?, ''), bib_no),
            target_serial_no = COALESCE(NULLIF(?, ''), target_serial_no),
            relay_no = COALESCE(?, relay_no),
            lane_no = COALESCE(?, lane_no),
            scheduled_date = COALESCE(NULLIF(?, ''), scheduled_date),
            reporting_time = COALESCE(NULLIF(?, ''), reporting_time),
            start_time = COALESCE(NULLIF(?, ''), start_time)
        WHERE id = ?
    ");

    // Prepared statements for registration_sessions
    $findSessionStmt = $pdo->prepare("
        SELECT id FROM registration_sessions WHERE user_id = ? LIMIT 1
    ");

    $insertSessionStmt = $pdo->prepare("
        INSERT INTO registration_sessions 
        (session_id, user_id, total_amount, payment_status, approval_status, approved_at)
        VALUES (?, ?, ?, 'verified', 'approved', NOW())
    ");

    $updateSessionStmt = $pdo->prepare("
        UPDATE registration_sessions 
        SET total_amount = ?, payment_status = 'verified', approval_status = 'approved', approved_at = NOW()
        WHERE id = ?
    ");

    // Pre-compute expensive operations ONCE outside the loop (100x performance boost)
    $defaultPassHash = password_hash('SSA@2026', PASSWORD_DEFAULT);
    $currentMaxId = (int)($pdo->query("SELECT MAX(id) FROM registrations")->fetchColumn() ?: 0);

    $pdo->beginTransaction();

    foreach ($rows as $index => $rawRow) {
        $r = $normalizeRow((array)$rawRow);
        
        $firstName = $r['first_name'] ?? '';
        $lastName = $r['last_name'] ?? '';

        if (empty($firstName) && !empty($r['full_name'])) {
            $parts = preg_split('/\s+/', trim($r['full_name']), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '.';
        }

        if (empty($firstName)) {
            $errors[] = "Row #" . ($index + 1) . ": Skipped due to missing Name.";
            continue;
        }
        if (empty($lastName)) {
            $lastName = '.';
        }

        $regId = $r['reg_id'] ?? '';
        $email = $r['email'] ?? '';
        $phone = preg_replace('/\D/', '', $r['phone'] ?? '');
        if (strlen($phone) < 10) $phone = '9' . str_pad((string)rand(100000001, 999999999), 9, '0', STR_PAD_LEFT);

        $aadhaar = preg_replace('/\D/', '', $r['aadhaar_number'] ?? '');
        if (strlen($aadhaar) !== 12) $aadhaar = str_pad((string)rand(100000000001, 999999999999), 12, '0', STR_PAD_LEFT);

        if (empty($email)) {
            $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . $lastName)) . rand(100, 999) . '@ssa.import';
        }

        $clubName = !empty($r['club_name']) ? $r['club_name'] : '-';
        $district = !empty($r['district']) ? $r['district'] : '-';
        $association = !empty($r['association']) ? $r['association'] : '-';
        $fatherName = !empty($r['father_guardian_name']) ? $r['father_guardian_name'] : '-';
        $address = !empty($r['address']) ? $r['address'] : '-';
        
        $dob = !empty($r['dob']) ? date('Y-m-d', strtotime($r['dob'])) : '2000-01-01';
        if ($dob === '1970-01-01' || !$dob) $dob = '2000-01-01';

        $gender = in_array($r['gender'] ?? '', ['Male', 'Female', 'Transgender']) ? $r['gender'] : 'Male';
        $isPara = $r['is_para'] ?? 0;
        $isDeaf = $r['is_deaf'] ?? 0;

        // Check if user exists
        $findUserStmt->execute([$regId, $regId, $email, $email, $aadhaar, $aadhaar]);
        $existingUser = $findUserStmt->fetch(PDO::FETCH_ASSOC);

        $userId = null;

        if ($existingUser) {
            $userId = (int)$existingUser['id'];
            $updateUserStmt->execute([
                $firstName, $lastName, $phone, $clubName, $district, $dob, $gender,
                $association, $fatherName, $address, $isPara, $isDeaf, $regId, $userId
            ]);
            $updatedUsers++;
        } else {
            if (empty($regId)) {
                $currentMaxId++;
                $regId = 'SSA-' . (1000 + $currentMaxId);
            }
            $insertUserStmt->execute([
                $regId, $firstName, $lastName, $email, $phone, $aadhaar,
                $clubName, $district, $dob, $gender, $association, $fatherName, $address,
                $isPara, $isDeaf, $defaultPassHash
            ]);
            $userId = (int)$pdo->lastInsertId();
            if ($userId > $currentMaxId) {
                $currentMaxId = $userId;
            }
            $insertedUsers++;
        }

        // Create or update registration_sessions entry for payment verification page
        $findSessionStmt->execute([$userId]);
        $sessionId = $findSessionStmt->fetchColumn();

        if ($sessionId) {
            $updateSessionStmt->execute([0.00, $sessionId]);
        } else {
            $sessionCode = 'SES-IMP-' . $userId . '-' . strtoupper(substr(md5(uniqid((string)$userId . '_' . microtime(true), true)), 0, 6));
            $insertSessionStmt->execute([$sessionCode, $userId, 0.00]);
            $sessionId = (int)$pdo->lastInsertId();
        }

        // Collect all events for this competitor
        $eventsToProcess = [];
        if (!empty($r['event_name'])) {
            $splitEvts = array_map('trim', explode(',', $r['event_name']));
            foreach ($splitEvts as $se) {
                if (!empty($se)) $eventsToProcess[] = $se;
            }
        }
        if (!empty($r['events_list'])) {
            foreach ($r['events_list'] as $el) {
                if (!empty($el) && !in_array($el, $eventsToProcess)) {
                    $eventsToProcess[] = $el;
                }
            }
        }

        // Process each event registration & lane allocation
        foreach ($eventsToProcess as $eventName) {
            if (empty($eventName) || $userId <= 0) continue;

            // Resolve mapped event name if code or short title provided
            if (isset($EVENTS_MAPPING[$eventName])) {
                $eventName = $EVENTS_MAPPING[$eventName];
            }

            // Comprehensive category parsing (SH1, SH2, SH3, PARA, DEAF, ISSF, MQS, NR)
            $rawCat = strtoupper(trim((string)($r['category'] ?? '')));
            $checkString = strtoupper($rawCat . ' ' . $eventName . ' ' . ($EVENTS_MAPPING[$eventName] ?? '') . ' ' . ($r['event_code'] ?? ''));

            if (preg_match('/(SH1|SH2|SH3|SH-1|SH-2|SH-3|PARA|DEAF|DISABLED|HANDICAPPED)/i', $checkString)) {
                $category = 'PARA_DEAF';
            } elseif (strpos($rawCat, 'ISSF') !== false || (empty($rawCat) && (strpos(strtoupper($eventName), 'ISSF') !== false || preg_match('/^IS-?\d+/i', $eventName)))) {
                $category = 'ISSF';
            } elseif (strpos($rawCat, 'MQS') !== false || (empty($rawCat) && strpos(strtoupper($eventName), 'MQS') !== false)) {
                $category = 'NR_MQS';
            } else {
                $category = 'NR';
            }

            $weaponType = !empty($r['weapon_type']) ? $r['weapon_type'] : 'Air Rifle / Pistol';
            $ageGroup = in_array($r['age_group'] ?? '', ['Sub Youth', 'Youth', 'Junior', 'Senior', 'Master', 'Senior Master', 'Super Master']) ? $r['age_group'] : 'Senior';
            $matchNo = $r['match_no'] ?? null;
            $issfNumber = $r['issf_number'] ?? null;
            $mqsScore = $r['mqs_score'] ?? null;
            $bestScore = $r['best_score'] ?? null;
            $entryFee = isset($r['entry_fee']) && (float)$r['entry_fee'] > 0 
                ? (float)$r['entry_fee'] 
                : calculateEventFeeBackend($eventName, $eventsList);

            $eventCode = 'EVT-' . substr(md5($eventName), 0, 6);

            $findEventRegStmt->execute([$userId, $eventName, $eventCode]);
            $existingEvtReg = $findEventRegStmt->fetch(PDO::FETCH_ASSOC);

            $eventRegDbId = null;

            if ($existingEvtReg) {
                $eventRegDbId = (int)$existingEvtReg['id'];
                $updateEventRegStmt->execute([
                    $category, $sessionId, $weaponType, $ageGroup, $matchNo, $issfNumber, $mqsScore, $bestScore, $entryFee, $eventRegDbId
                ]);
                $updatedEvents++;
            } else {
                $eventRegIdStr = 'EVT-' . $userId . '-' . strtoupper(substr(md5(uniqid((string)$userId . '_' . $eventName . '_' . microtime(true) . '_' . mt_rand(10000, 99999), true)), 0, 8));

                $insertEventRegStmt->execute([
                    $eventRegIdStr,
                    $userId,
                    $sessionId,
                    $category,
                    $eventCode,
                    $eventName,
                    $weaponType,
                    $ageGroup,
                    $matchNo,
                    $issfNumber,
                    $mqsScore,
                    $bestScore,
                    $entryFee
                ]);
                $eventRegDbId = (int)$pdo->lastInsertId();
                $insertedEvents++;
            }

            // Lane Allocation processing ONLY if explicit non-empty relay_no, lane_no and scheduled_date are provided
            $hasRelay = isset($r['relay_no']) && is_numeric($r['relay_no']) && (int)$r['relay_no'] > 0;
            $hasLane  = isset($r['lane_no']) && is_numeric($r['lane_no']) && (int)$r['lane_no'] > 0;
            $hasDate  = !empty($r['scheduled_date']) && strtotime((string)$r['scheduled_date']) !== false;

            if ($eventRegDbId && $hasRelay && $hasLane && $hasDate) {
                $relayNo = (int)$r['relay_no'];
                $laneNo  = (int)$r['lane_no'];
                $scheduledDate = date('Y-m-d', strtotime((string)$r['scheduled_date']));
                $startTime = !empty($r['start_time']) ? date('H:i:s', strtotime((string)$r['start_time'])) : '09:00:00';
                $reportingTime = !empty($r['reporting_time']) ? date('H:i:s', strtotime((string)$r['reporting_time'])) : '08:30:00';
                $targetSerialNo = !empty($r['target_serial_no']) ? trim((string)$r['target_serial_no']) : null;

                $findAllocStmt->execute([$eventRegDbId]);
                $allocId = $findAllocStmt->fetchColumn();

                if ($allocId) {
                    $updateAllocStmt->execute([
                        $regId, $targetSerialNo, $relayNo, $laneNo, $scheduledDate, $reportingTime, $startTime, $allocId
                    ]);
                    $updatedAllocations++;
                } else {
                    $insertAllocStmt->execute([
                        $eventRegDbId, $regId, $targetSerialNo, $relayNo, $laneNo, $scheduledDate, $reportingTime, $startTime
                    ]);
                    $insertedAllocations++;
                }
            }
        }

        // Recalculate and update total_amount for this user's registration session based on actual event entry fees or imported paid amount
        if ($userId > 0) {
            if (isset($r['paid_amount']) && (float)$r['paid_amount'] > 0) {
                $pdo->prepare("
                    UPDATE registration_sessions s
                    SET total_amount = ?
                    WHERE s.user_id = ?
                ")->execute([(float)$r['paid_amount'], $userId]);
            } else {
                $pdo->prepare("
                    UPDATE registration_sessions s
                    SET total_amount = (
                        SELECT COALESCE(SUM(entry_fee), 0)
                        FROM event_registrations er
                        WHERE er.user_id = s.user_id
                    )
                    WHERE s.user_id = ?
                ")->execute([$userId]);
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Import completed successfully!",
        'summary' => [
            'users_inserted'     => $insertedUsers,
            'users_updated'      => $updatedUsers,
            'events_created'     => $insertedEvents,
            'events_updated'     => $updatedEvents,
            'allocations_created'=> $insertedAllocations,
            'allocations_updated'=> $updatedAllocations,
            'errors'             => $errors
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Import Error: ' . $e->getMessage()]);
}
