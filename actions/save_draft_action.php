<?php
/**
 * actions/save_draft_action.php
 * Save event registration details as a draft.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth Check ──
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Not logged in.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

// Check timeline controls
$eventInfo = null;
try {
    $pdo = getDB();
    $eventInfo = $pdo->query("SELECT * FROM event_info WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($eventInfo) {
        $now = date('Y-m-d H:i:s');
        if ($eventInfo['reg_start_active'] && !empty($eventInfo['reg_start_date'])) {
            if ($now < $eventInfo['reg_start_date']) {
                exit(json_encode(['success' => false, 'message' => 'Event registration has not started yet.']));
            }
        }
        if ($eventInfo['reg_end_active'] && !empty($eventInfo['reg_end_date'])) {
            if ($now > $eventInfo['reg_end_date']) {
                exit(json_encode(['success' => false, 'message' => 'Event registration has closed.']));
            }
        }
    }
} catch (Exception $e) {}

// ── CSRF Check ──
if (!hash_equals($_SESSION['csrf_token'] ?? '', trim($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid request. Refresh and try again.']));
}

$userId = (int)$_SESSION['user_id'];
$cartJson = $_POST['cart_json'] ?? '[]';
$cart = json_decode($cartJson, true);
$selectedWeapon = trim($_POST['selected_weapon'] ?? '');

if (!is_array($cart) || empty($cart)) {
    exit(json_encode(['success' => false, 'message' => 'Your cart is empty. Add at least one event to save draft.']));
}

$draftSessionId = !empty($_POST['draft_session_id']) ? (int)$_POST['draft_session_id'] : null;

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $fieldsConfig = [];
    try {
        $fieldsConfig = getFieldControls('event_registration');
    } catch (Exception $e) {}

    // Load events config early for fee calculation
    $eventsList = require dirname(__DIR__) . '/config/events.php';

    if (!function_exists('calculateEventFeeBackend')) {
        function calculateEventFeeBackend(string $eventName, array $eventsList): float {
            global $eventInfo;
            $eventObj = $eventsList[$eventName] ?? null;
            if (!$eventObj) {
                return 1180.00; // Fallback
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
                $base = 1000.00; // default/fallback
            }

            // Apply triple entry late fee multiplier
            $multiplier = 1;
            if ($eventInfo && $eventInfo['triple_entry_active']) {
                if (!empty($eventInfo['triple_entry_date'])) {
                    $now = date('Y-m-d H:i:s');
                    if ($now >= $eventInfo['triple_entry_date']) {
                        $multiplier = 3;
                    }
                } else {
                    $multiplier = 3;
                }
            }
            $base = $base * $multiplier;

            $gst = 0;
            return (float)($base + $gst);
        }
    }

    // ── Organized File Directories ──
    $userUploadDir = dirname(__DIR__) . '/uploads/user_' . $userId . '/';
    $cfDir = $userUploadDir . 'certificates/';
    if (!is_dir($cfDir)) mkdir($cfDir, 0755, true);

    // Handle certificate files uploaded in this request
    $certPaths = [];
    foreach ($cart as $idx => $item) {
        $fileKey = 'cert_' . $idx;
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $cf = $_FILES[$fileKey];
            $cfInfo = new finfo(FILEINFO_MIME_TYPE);
            $cfMime = $cfInfo->file($cf['tmp_name']);
            $cfAllowed = ['image/jpeg', 'image/png', 'application/pdf'];
            if (!in_array($cfMime, $cfAllowed) || $cf['size'] > 5 * 1024 * 1024) {
                $certPaths[$idx] = null;
                continue;
            }
            $cfExt = match($cfMime) { 'application/pdf'=>'pdf', 'image/png'=>'png', default=>'jpg' };
            $cfName = 'cert_u'.$userId.'_idx'.$idx.'_'.time().'.'.$cfExt;
            $moved = (php_sapi_name() === 'cli') ? copy($cf['tmp_name'], $cfDir . $cfName) : move_uploaded_file($cf['tmp_name'], $cfDir . $cfName);
            if ($moved) {
                $certPaths[$idx] = 'uploads/user_' . $userId . '/certificates/' . $cfName;
            } else {
                $certPaths[$idx] = null;
            }
        } else {
            // Reuse existing certificate path if passed and valid
            $certPaths[$idx] = $item['certificate_path'] ?? null;
        }
    }

    // Calculate total amount
    $totalAmount = 0;
    foreach ($cart as $item) {
        $totalAmount += calculateEventFeeBackend($item['event_name'], $eventsList);
    }

    $sessionDbId = null;
    $sessionId = null;

    if ($draftSessionId) {
        // Check if session exists and belongs to user
        $chk = $pdo->prepare("SELECT id, session_id FROM registration_sessions WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$draftSessionId, $userId]);
        $existingSession = $chk->fetch();
        if ($existingSession) {
            $sessionDbId = (int)$existingSession['id'];
            $sessionId = $existingSession['session_id'];

            // Delete old draft certificates from disk if they are replaced or removed
            $oldEvtsStmt = $pdo->prepare("SELECT certificate_path FROM event_registrations WHERE session_id = ?");
            $oldEvtsStmt->execute([$sessionDbId]);
            $oldCerts = $oldEvtsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($oldCerts as $oldCert) {
                if (!empty($oldCert) && !in_array($oldCert, $certPaths, true)) {
                    $oldPath = dirname(__DIR__) . '/' . $oldCert;
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
            }

            // Delete old event registrations for this draft session
            $pdo->prepare("DELETE FROM event_registrations WHERE session_id = ?")->execute([$sessionDbId]);

            // Update session amount and updated timestamp
            $pdo->prepare("UPDATE registration_sessions SET total_amount = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$totalAmount, $sessionDbId]);
        }
    }

    if (!$sessionDbId) {
        // Generate a new payment session ID
        $sessionId = '';
        do {
            $candidate = 'PAY' . random_int(1000, 9999);
            $chk = $pdo->prepare("SELECT id FROM registration_sessions WHERE session_id = ? LIMIT 1");
            $chk->execute([$candidate]);
            if ($chk->rowCount() === 0) {
                $sessionId = $candidate;
            }
        } while ($sessionId === '');

        $activeChampionship = getActiveChampionship($pdo);
        $activeChampionshipId = (int)($activeChampionship['id'] ?? 1);

        // Insert session
        $pdo->prepare(
            "INSERT INTO registration_sessions (championship_id, session_id, user_id, total_amount, payment_screenshot, payment_status, approval_status)
             VALUES (?, ?, ?, ?, NULL, 'unpaid', 'draft')"
        )->execute([$activeChampionshipId, $sessionId, $userId, $totalAmount]);
        $sessionDbId = (int)$pdo->lastInsertId();
    }

    // Helper function to resolve weapon type dynamically if missing
    $getWeaponType = function(string $event) use ($eventsList): string {
        $eventObj = $eventsList[$event] ?? null;
        if ($eventObj) {
            $g = strtoupper($eventObj['group']);
            if (strpos($g, 'RIFLE') !== false) return 'Air Rifle';
            if (strpos($g, 'PISTOL') !== false) return 'Air Pistol';
            if (strpos($g, 'TRAP') !== false || strpos($g, 'SKEET') !== false) return 'Trap Gun';
        }
        return 'Air Rifle';
    };

    // Validate participant type category access & requirements
    $uStmt = $pdo->prepare("SELECT dob, gender, is_para, is_deaf, association, club_name FROM registrations WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $regU = $uStmt->fetch();
    $dobRaw = $regU['dob'] ?? '';
    $userGender = $regU['gender'] ?? 'Male';
    $isPara = (int)($regU['is_para'] ?? 0);
    $isDeaf = (int)($regU['is_deaf'] ?? 0);
    $isPd = ($isPara || $isDeaf) ? 1 : 0;
    $association = $regU['association'] ?? '';
    $clubName = $regU['club_name'] ?? '';

    // Determine if the user is registered under Defence/Services
    $isDefence = (
        stripos($association, 'DEFENCE') !== false ||
        stripos($association, 'SERVICES') !== false ||
        stripos($clubName, 'DEFENCE') !== false ||
        stripos($clubName, 'SERVICES') !== false
    );

    // Calculate user's age and eligible age categories based on year difference (2026 - birth year)
    $age = 0;
    if (!empty($dobRaw)) {
        $birthYear = (int)date('Y', strtotime($dobRaw));
        $age = 2026 - $birthYear;
    }

    // Helper functions for age group calculations
    if (!function_exists('getPhpUserAgeGroup')) {
        function getPhpUserAgeGroup(int $age): string {
            if ($age <= 16) return 'Sub Youth';
            if ($age <= 19) return 'Youth';
            if ($age <= 21) return 'Junior';
            if ($age <= 44) return 'Senior';
            if ($age <= 59) return 'Master';
            if ($age <= 69) return 'Senior Master';
            return 'Super Master';
        }
    }

    if (!function_exists('getPhpEligibleAgeGroups')) {
        function getPhpEligibleAgeGroups(string $primary): array {
            switch ($primary) {
                case 'Sub Youth':
                    return ['Sub Youth', 'Youth', 'Junior', 'Senior'];
                case 'Youth':
                    return ['Youth', 'Junior', 'Senior'];
                case 'Junior':
                    return ['Junior', 'Senior'];
                case 'Senior':
                    return ['Senior'];
                case 'Master':
                    return ['Master', 'Senior'];
                case 'Senior Master':
                    return ['Senior Master', 'Master', 'Senior'];
                case 'Super Master':
                    return ['Super Master', 'Senior Master', 'Master', 'Senior'];
                default:
                    return ['Senior'];
            }
        }
    }

    if (!function_exists('getPhpEventAgeGroup')) {
        function getPhpEventAgeGroup(string $label): string {
            $u = strtoupper($label);
            if (strpos($u, 'SUB YOUTH') !== false) return 'Sub Youth';
            if (strpos($u, 'YOUTH') !== false) return 'Youth';
            if (strpos($u, 'JUNIOR') !== false) return 'Junior';
            if (strpos($u, 'SUPER MASTER') !== false) return 'Super Master';
            if (strpos($u, 'SENIOR MASTER') !== false) return 'Senior Master';
            if (strpos($u, 'MASTER') !== false) return 'Master';
            return 'Senior';
        }
    }

    $userAgeGroup = getPhpUserAgeGroup($age);
    $eligibleGroups = getPhpEligibleAgeGroups($userAgeGroup);

    // Load central events configuration
    $eventsList = require dirname(__DIR__) . '/config/events.php';

    // Validate each cart item and check duplicates within the cart itself
    $seenEventsInCart = [];

    foreach ($cart as $idx => $item) {
        $fc = strtoupper(trim($item['final_cat'] ?? ''));
        if (!$isPd && $fc === 'PARA_DEAF') exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Para/Deaf category is only for Para/Deaf athletes."]));
        if (($isPd || $isDefence) && $fc === 'NR_MQS') exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": NR for MQS category is not applicable for Para/Deaf/Defence athletes."]));
        if ($isPd && (empty($item['disability_type']) || empty($item['classification'])))
            exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Disability type and sports classification required for Para/Deaf athletes."]));

        // Check duplicates within the cart itself
        if (in_array($item['event_name'], $seenEventsInCart, true)) {
            exit(json_encode(['success'=>false,'message'=>"Duplicate event selected in your cart: " . htmlspecialchars($item['event_name'])]));
        }
        $seenEventsInCart[] = $item['event_name'];

        // Check if user already registered for this event (approved/pending/other draft session)
        $dupQuery = "SELECT id FROM event_registrations WHERE user_id=? AND event_name=? AND status != 'rejected'";
        $dupParams = [$userId, $item['event_name']];
        if ($draftSessionId) {
            $dupQuery .= " AND session_id != ?";
            $dupParams[] = $draftSessionId;
        }
        $dupQuery .= " LIMIT 1";
        $dup = $pdo->prepare($dupQuery);
        $dup->execute($dupParams);
        if ($dup->fetch()) {
            exit(json_encode(['success' => false, 'message' => "You already registered for event '{$item['event_name']}' or have a pending registration."]));
        }

        // Retrieve event details from the config
        $eventObj = $eventsList[$item['event_name']] ?? null;
        if (!$eventObj) {
            exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Event code not found in official event list."]));
        }

        // Weapon validation against the user's selected weapon
        if ($selectedWeapon) {
            $groupUpper = strtoupper($eventObj['group']);
            if ($selectedWeapon === 'Rifle' && strpos($groupUpper, 'RIFLE') === false) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Event does not match selected weapon: Rifle."]));
            }
            if ($selectedWeapon === 'Pistol' && strpos($groupUpper, 'PISTOL') === false) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Event does not match selected weapon: Pistol."]));
            }
            if ($selectedWeapon === 'Shotgun' && strpos($groupUpper, 'TRAP') === false && strpos($groupUpper, 'SKEET') === false && strpos($groupUpper, 'SHOTGUN') === false) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Event does not match selected weapon: Shotgun."]));
            }
        }

        // Gender validation
        if ($userGender === 'Male' && $eventObj['gender'] === 'Female') {
            exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Men's athletes can only register for Men's or Mixed events."]));
        }
        if ($userGender === 'Female' && $eventObj['gender'] === 'Male') {
            exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Women's athletes can only register for Women's or Mixed events."]));
        }

        // Validation of participant type and event isolation
        if ($isPara) {
            if (strpos($eventObj['no'], 'R') !== 0) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Registered Para athletes can only register for Para events."]));
            }
        } else if ($isDeaf || $isDefence) {
            $isDeafEv = (strpos($eventObj['no'], 'DS') === 0);
            $labelUpper = strtoupper($eventObj['label']);
            $isDefEv = (strpos($labelUpper, 'SERVICES') !== false || strpos($labelUpper, 'DEFENCE') !== false);
            if (!$isDeafEv && !$isDefEv) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Registered Deaf/Defence athletes can only register for Deaf or Defence events."]));
            }
        } else {
            // Standard athlete
            $isDeafEvent = (strpos($eventObj['no'], 'DS') === 0);
            $isParaEvent = (strpos($eventObj['no'], 'R') === 0);
            $labelUpper = strtoupper($eventObj['label']);
            $isDefenceEvent = (strpos($labelUpper, 'SERVICES') !== false || strpos($labelUpper, 'DEFENCE') !== false);
            if ($isDeafEvent || $isParaEvent || $isDefenceEvent) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Standard athletes cannot register for Para, Deaf, or Defence events."]));
            }
            
            // Age group validation for standard athletes
            $eventAgeGroup = getPhpEventAgeGroup($eventObj['label']);
            if (!in_array($eventAgeGroup, $eligibleGroups, true)) {
                exit(json_encode(['success'=>false,'message'=>"Item ".($idx+1).": Athlete is not eligible for event age category: " . htmlspecialchars($eventAgeGroup)]));
            }
        }
    }    // Insert new draft event registrations
    $generatedSeqs = [];
    foreach ($cart as $idx => $item) {
        $finalCat    = strtoupper(trim($item['final_cat'] ?? ''));
        $checkEvtStr = strtoupper(($item['event_name'] ?? '') . ' ' . $finalCat);
        if (preg_match('/(SH1|SH2|SH3|SH-1|SH-2|SH-3|PARA|DEAF|DISABLED|HANDICAPPED)/i', $checkEvtStr)) {
            $finalCat = 'PARA_DEAF';
        }
        $issfNum     = !empty($item['issf_number']) ? $item['issf_number'] : null;
        $mqsScore    = !empty($item['mqs_score']) ? (float)$item['mqs_score'] : null;
        $disType     = !empty($item['disability_type']) ? $item['disability_type'] : null;
        $classif     = !empty($item['classification']) ? $item['classification'] : null;
        $certPath    = $certPaths[$idx] ?? null;
        $entryFee    = calculateEventFeeBackend($item['event_name'], $eventsList);
        $matchNo     = !empty($item['match_no']) ? $item['match_no'] : null;
        $compName    = !empty($item['competition_name']) ? $item['competition_name'] : null;
        $shootYear   = !empty($item['shooting_year']) ? $item['shooting_year'] : null;
        $weaponType  = !empty($item['weapon_type']) ? $item['weapon_type'] : $getWeaponType($item['event_name'] ?? '');

        // Generate event reg ID
        if (!isset($generatedSeqs[$finalCat])) {
            $evSeq = 1;
            $maxEvStmt = $pdo->prepare("SELECT event_reg_id FROM event_registrations WHERE category=? FOR UPDATE");
            $maxEvStmt->execute([$finalCat]);
            while ($row = $maxEvStmt->fetch()) {
                if (preg_match('/-([0-9]+)$/', $row['event_reg_id'], $matches)) {
                    $num = (int)$matches[1];
                    if ($num >= $evSeq) {
                        $evSeq = $num + 1;
                    }
                }
            }
            $generatedSeqs[$finalCat] = $evSeq;
        } else {
            $generatedSeqs[$finalCat]++;
        }
        $eventRegId = 'SSA51TN-'.$finalCat.'-'.str_pad((string)$generatedSeqs[$finalCat], 4, '0', STR_PAD_LEFT);

        $evtCode = strtoupper(preg_replace('/[^A-Z0-9]/', '-', $item['event_name'] ?? ''));

        $activeChampionship = getActiveChampionship($pdo);
        $activeChampionshipId = (int)($activeChampionship['id'] ?? 1);

        $pdo->prepare(
            "INSERT INTO event_registrations
              (championship_id, session_id, event_reg_id, user_id, category, event_code, match_no, event_name, weapon_type,
               age_group, issf_number, mqs_score, best_score, shooting_year, competition_name, certificate_path,
               entry_fee, disability_type, classification, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
        )->execute([
            $activeChampionshipId, $sessionDbId, $eventRegId, $userId, $finalCat, $evtCode,
            $matchNo, $item['event_name'], $weaponType, $item['age_group'] ?? 'Senior',
            $issfNum, $mqsScore, !empty($item['best_score']) ? (float)$item['best_score'] : 0,
            $shootYear, $compName, $certPath, $entryFee, $disType, $classif
        ]);
    }

    // Insert/update custom field values for this draft session
    $customFields = json_decode($_POST['custom_fields'] ?? '{}', true);
    if (is_array($customFields)) {
        $valStmt = $pdo->prepare("
            INSERT INTO custom_field_values (entity_type, entity_id, field_id, field_value)
            VALUES ('event_reg', ?, ?, ?)
            ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)
        ");
        foreach ($fieldsConfig as $f) {
            if ($f['is_custom'] && $f['is_enabled']) {
                $val = trim((string)($customFields[$f['field_id']] ?? ''));
                $valStmt->execute([$sessionDbId, $f['field_id'], $val]);
            }
        }
    }

    $pdo->commit();
    exit(json_encode([
        'success' => true,
        'draft_session_id' => $sessionDbId,
        'session_id' => $sessionId,
        'message' => 'Draft saved successfully.'
    ]));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Save draft error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server error. Please try again.']));
}
