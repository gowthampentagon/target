<?php
/**
 * actions/event_register_action.php
 * Multi-event registration with session + payment screenshot upload.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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

// ── Auth ──
if (empty($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(['success'=>false,'message'=>'Not logged in.'])); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed.'])); }

// ── CSRF ──
if (!hash_equals($_SESSION['csrf_token'] ?? '', trim($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit(json_encode(['success'=>false,'message'=>'Invalid request. Refresh and try again.']));
}

// ── Load Field Configuration ──
$fieldSettings = [];
$fieldsConfig = [];
try {
    $fieldsConfig = getFieldControls('event_registration');
    foreach ($fieldsConfig as $f) {
        $fieldSettings[$f['field_id']] = [
            'enabled' => (int)$f['is_enabled'],
            'mandatory' => (int)$f['is_mandatory'],
            'label' => $f['field_label']
        ];
    }
} catch (Exception $e) {}

$isFieldEnabled = function(string $fid) use ($fieldSettings): bool {
    return !isset($fieldSettings[$fid]) || $fieldSettings[$fid]['enabled'] === 1;
};
$isFieldMandatory = function(string $fid) use ($fieldSettings): bool {
    return !isset($fieldSettings[$fid]) || $fieldSettings[$fid]['mandatory'] === 1;
};

$userId   = (int)$_SESSION['user_id'];
$cartJson = $_POST['cart_json'] ?? '[]';
$cart     = json_decode($cartJson, true);
$termsAccepted = trim($_POST['terms_accepted'] ?? '0');
$selectedWeapon = trim($_POST['selected_weapon'] ?? '');

$customFields = json_decode($_POST['custom_fields'] ?? '{}', true);
if (!is_array($customFields)) $customFields = [];

foreach ($fieldsConfig as $f) {
    if ($f['is_custom'] && $f['is_enabled']) {
        $val = trim((string)($customFields[$f['field_id']] ?? ''));
        if ($f['is_mandatory'] && empty($val)) {
            exit(json_encode(['success' => false, 'message' => htmlspecialchars($f['field_label']) . ' is required.']));
        }
    }
}

if ($termsAccepted !== '1') {
    exit(json_encode(['success'=>false,'message'=>'You must accept the terms and conditions before submitting.']));
}
if ($selectedWeapon === '') {
    exit(json_encode(['success'=>false,'message'=>'Please select a weapon before submitting.']));
}

if (!is_array($cart) || empty($cart)) {
    exit(json_encode(['success'=>false,'message'=>'Your cart is empty. Please add at least one event.']));
}

if (count($cart) > 10) {
    exit(json_encode(['success'=>false,'message'=>'Maximum 10 events per registration session.']));
}

// ── Validate each cart item ──
$validCats    = ['ISSF','NR'];
$validAges    = ['Sub Youth','Youth','Junior','Senior','Master','Senior Master','Super Master'];
$currentYear  = (int)date('Y');

// ── Lookup user's details and participant type status ──
$userStmt = getDB()->prepare("SELECT dob, gender, is_para, is_deaf, association, club_name FROM registrations WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$regUser = $userStmt->fetch();
$dobRaw = $regUser['dob'] ?? '';
$userGender = $regUser['gender'] ?? 'Male';
$isPara = (int)($regUser['is_para'] ?? 0);
$isDeaf = (int)($regUser['is_deaf'] ?? 0);
$isParaDeaf = ($isPara || $isDeaf) ? 1 : 0;
$association = $regUser['association'] ?? '';
$clubName = $regUser['club_name'] ?? '';

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

        $gst = round($base * 0.18);
        return (float)($base + $gst);
    }
}

$userAgeGroup = getPhpUserAgeGroup($age);
$eligibleGroups = getPhpEligibleAgeGroups($userAgeGroup);

// Load central events configuration
$eventsList = require dirname(__DIR__) . '/config/events.php';

// Validate each cart item and check duplicates within the cart itself
$seenEventsInCart = [];

// Define dob and backend validator functions from origin/main
$dob = $regUser['dob'] ?? null;

if (!function_exists('getBackendUserAgeGroup')) {
    function getBackendUserAgeGroup(int $age): string {
        if ($age < 16) return 'sub_youth';
        if ($age < 18) return 'youth';
        if ($age < 21) return 'junior';
        if ($age <= 44) return 'senior';
        if ($age <= 59) return 'master';
        if ($age <= 69) return 'senior_master';
        return 'super_master';
    }
}

if (!function_exists('getBackendEventAgeGroup')) {
    function getBackendEventAgeGroup(string $name): string {
        $u = strtoupper($name);
        if (strpos($u, 'SUB YOUTH') !== false) return 'sub_youth';
        if (strpos($u, 'YOUTH') !== false) return 'youth';
        if (strpos($u, 'JUNIOR') !== false) return 'junior';
        if (strpos($u, 'SUPER MASTER') !== false) return 'super_master';
        if (strpos($u, 'SENIOR MASTER') !== false) return 'senior_master';
        if (strpos($u, 'MASTER') !== false) return 'master';
        return 'senior';
    }
}

if (!function_exists('isBackendEligibleAgeGroup')) {
    function isBackendEligibleAgeGroup(string $userGroup, string $eventGroup): bool {
        if ($userGroup === $eventGroup) return true;

        if ($userGroup === 'sub_youth') {
            return in_array($eventGroup, ['youth', 'junior', 'senior'], true);
        }
        if ($userGroup === 'youth') {
            return in_array($eventGroup, ['junior', 'senior'], true);
        }
        if ($userGroup === 'junior') {
            return $eventGroup === 'senior';
        }
        if ($userGroup === 'master') {
            return $eventGroup === 'senior';
        }
        if ($userGroup === 'senior_master') {
            return in_array($eventGroup, ['master', 'senior'], true);
        }
        if ($userGroup === 'super_master') {
            return in_array($eventGroup, ['senior_master', 'master', 'senior'], true);
        }
        return false;
    }
}

if (!function_exists('getBackendEventBaseType')) {
    function getBackendEventBaseType(string $label): string {
        $u = strtoupper($label);

        $is10mPistolParaDeaf = (strpos($u, '10M AIR PISTOL') !== false || strpos($u, '10M PISTOL') !== false) && 
            (strpos($u, 'SH1') !== false || strpos($u, 'DEAF') !== false || strpos($u, 'PARA') !== false || strpos($u, 'DS12') !== false || strpos($u, 'DS13') !== false || strpos($u, 'R021') !== false || strpos($u, 'R022') !== false || strpos($u, 'R023') !== false || strpos($u, 'R024') !== false || strpos($u, 'R025') !== false || strpos($u, 'R026') !== false || strpos($u, 'R031') !== false || strpos($u, 'R032') !== false);

        if ($is10mPistolParaDeaf) return '10m_air_pistol_issf';

        if (strpos($u, '10M AIR RIFLE STANDING SH1') !== false) return '10m_air_rifle_standing_sh1';
        if (strpos($u, '10M AIR RIFLE PRONE SH1') !== false) return '10m_air_rifle_prone_sh1';
        if (strpos($u, '50M RIFLE PRONE SH1') !== false) return '50m_rifle_prone_sh1';
        if (strpos($u, '50M RIFLE 3 POSITIONS SH1') !== false || strpos($u, '50M RIFLE 3 POSITION SH1') !== false) return '50m_rifle_3_positions_sh1';
        if (strpos($u, '10M AIR RIFLE STANDING SH2') !== false) return '10m_air_rifle_standing_sh2';
        if (strpos($u, '10M AIR RIFLE PRONE SH2') !== false) return '10m_air_rifle_prone_sh2';
        if (strpos($u, '50M RIFLE PRONE SH2') !== false) return '50m_rifle_prone_sh2';
        if (strpos($u, '10M AIR PISTOL SH1') !== false) return '10m_air_pistol_sh1';
        if (strpos($u, '25M PISTOL SH1') !== false) return '25m_pistol_sh1';
        if (strpos($u, '50M PISTOL SH1') !== false) return '50m_pistol_sh1';
        if (strpos($u, '10M STANDARD AIR PISTOL SH1') !== false) return '10m_standard_air_pistol_sh1';

        if (strpos($u, '10M OPEN SIGHT AIR RIFLE') !== false || strpos($u, 'OPEN SIGHT') !== false) return '10m_open_sight_air_rifle';
        if (strpos($u, '10M PEEP SIGHT AIR RIFLE') !== false || strpos($u, 'PEEP RIFLE') !== false || strpos($u, 'PEEP SIGHT') !== false || strpos($u, '10M AIR RIFLE') !== false || strpos($u, '10M RIFLE') !== false) return 'peep_rifle';
        if (strpos($u, '50M OPEN SIGHT RIFLE PRONE') !== false) return '50m_open_sight_rifle_prone';
        if (strpos($u, '50M RIFLE PRONE') !== false) return '50m_rifle_prone';
        if (strpos($u, '50M OPEN SIGHT RIFLE 3 POSITIONS') !== false || strpos($u, '50M OPEN SIGHT RIFLE 3 POSITION') !== false) return '50m_open_sight_rifle_3_positions';
        if (strpos($u, '50M RIFLE 3 POSITIONS') !== false || strpos($u, '50M RIFLE 3 POSITION') !== false) return '50m_rifle_3_positions';
        
        if (strpos($u, '10M AIR PISTOL') !== false || strpos($u, '10M PISTOL') !== false) return '10m_air_pistol';
        if (strpos($u, '25M SPORTS PISTOL') !== false || strpos($u, '25M SPORT PISTOL') !== false || strpos($u, '25M PISTOL') !== false) return '25m_sport_pistol';
        if (strpos($u, '25M CENTRE FIRE PISTOL') !== false || strpos($u, '25M CENTER FIRE PISTOL') !== false || strpos($u, '25M CENTRE') !== false || strpos($u, '25M CENTER') !== false) return '25m_centre_fire_pistol';
        if (strpos($u, '25M STANDARD PISTOL') !== false || strpos($u, '25M STANDARD') !== false) return '25m_standard_pistol';
        if (strpos($u, '50M FREE PISTOL') !== false || strpos($u, '50M PISTOL') !== false || strpos($u, '50M FREE') !== false) return '50m_free_pistol';

        return '';
    }
}

if (!function_exists('getBackendAllowedAgeGroupsForEvent')) {
    function getBackendAllowedAgeGroupsForEvent(string $baseType, string $gender): array {
        $g = strtoupper($gender);
        
        if ($baseType === '10m_air_rifle' || $baseType === '10m_open_sight_air_rifle' || $baseType === '10m_air_pistol') {
            return ['sub_youth', 'youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
        }
        if ($baseType === '50m_rifle_prone' || $baseType === '50m_rifle_3_positions') {
            return ['youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
        }
        if ($baseType === '50m_open_sight_rifle_prone' || $baseType === '50m_open_sight_rifle_3_positions') {
            return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
        }
        if ($baseType === '25m_sport_pistol') {
            if (strpos($g, 'WOMEN') !== false || strpos($g, 'FEMALE') !== false) {
                return ['youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
            } else {
                return ['youth', 'junior', 'senior'];
            }
        }
        if ($baseType === '25m_centre_fire_pistol') {
            return ['senior', 'master', 'senior_master', 'super_master'];
        }
        if ($baseType === '25m_standard_pistol') {
            if (strpos($g, 'WOMEN') !== false || strpos($g, 'FEMALE') !== false) {
                return ['junior', 'senior'];
            } else {
                return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
            }
        }
        if ($baseType === '50m_free_pistol') {
            return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
        }
        
        // Para/Deaf SH1 / SH2 events
        if ($baseType === '10m_air_rifle_standing_sh1' || $baseType === '10m_air_pistol_sh1') {
            return ['youth', 'junior', 'senior'];
        }
        if ($baseType === '10m_air_rifle_prone_sh1' || $baseType === '50m_rifle_prone_sh1' || $baseType === '50m_rifle_3_positions_sh1' ||
            $baseType === '10m_air_rifle_standing_sh2' || $baseType === '10m_air_rifle_prone_sh2' || $baseType === '50m_rifle_prone_sh2' ||
            $baseType === '25m_pistol_sh1' || $baseType === '50m_pistol_sh1' || $baseType === '10m_standard_air_pistol_sh1') {
            return ['junior', 'senior'];
        }

        return ['sub_youth', 'youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
    }
}

if (!function_exists('isBackendEventAllowedForUser')) {
    function isBackendEventAllowedForUser(string $eventLabel, string $eventGender, string $userAgeGroup): bool {
        $eventAgeGroup = getBackendEventAgeGroup($eventLabel);
        if (!isBackendEligibleAgeGroup($userAgeGroup, $eventAgeGroup)) {
            return false;
        }
        $baseType = getBackendEventBaseType($eventLabel);
        $allowedGroups = getBackendAllowedAgeGroupsForEvent($baseType, $eventGender ?: 'Male');
        return in_array($eventAgeGroup, $allowedGroups, true);
    }
}

foreach ($cart as $idx => $item) {
    $finalCat = strtoupper(trim($item['final_cat'] ?? ''));
    if (!in_array($finalCat, $validCats, true))
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": invalid category."]));
    if (empty($item['event_name']))
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": event name missing."]));
    if (!in_array($item['age_group'] ?? '', $validAges, true))
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": invalid age group."]));
    if (!isset($item['best_score']) || (float)$item['best_score'] < 0 || (float)$item['best_score'] > 1200)
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": invalid score."]));
    if ($finalCat === 'ISSF') {
        if ($isFieldEnabled('issf_number') && $isFieldMandatory('issf_number') && empty($item['issf_number'])) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": ISSF number required."]));
        }
        if ($isFieldEnabled('mqs_score') && $isFieldMandatory('mqs_score')) {
            if (!isset($item['mqs_score']) || $item['mqs_score'] === '' || (float)$item['mqs_score'] <= 0) {
                exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Achieved score is required for ISSF."]));
            }
            if (empty($item['competition_name'])) {
                exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Competition name is required for ISSF."]));
            }
        }
        if ($isFieldEnabled('shooting_year') && $isFieldMandatory('shooting_year') && empty($item['shooting_year'])) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Year is required for ISSF."]));
        }
    }
    // Para/deaf users must provide disability & classification
    if ($isParaDeaf && $isFieldEnabled('disability_type') && $isFieldMandatory('disability_type')) {
        if (empty($item['disability_type']) || empty($item['classification'])) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Disability type and sports classification required for Para/Deaf athletes."]));
        }
    }

    // Check duplicates within the cart itself
    if (in_array($item['event_name'], $seenEventsInCart, true)) {
        exit(json_encode(['success'=>false,'message'=>"Duplicate event selected in your cart: " . htmlspecialchars($item['event_name'])]));
    }
    $seenEventsInCart[] = $item['event_name'];

    // Retrieve event details from the config
    $eventObj = $eventsList[$item['event_name']] ?? null;
    if (!$eventObj) {
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Event code not found in official event list."]));
    }

    // Weapon validation against the user's selected weapon
    if ($selectedWeapon) {
        $groupUpper = strtoupper($eventObj['group']);
        if ($selectedWeapon === 'Rifle' && strpos($groupUpper, 'RIFLE') === false) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Event does not match selected weapon: Rifle."]));
        }
        if ($selectedWeapon === 'Pistol' && strpos($groupUpper, 'PISTOL') === false) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Event does not match selected weapon: Pistol."]));
        }
        if ($selectedWeapon === 'Shotgun' && strpos($groupUpper, 'TRAP') === false && strpos($groupUpper, 'SKEET') === false && strpos($groupUpper, 'SHOTGUN') === false) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Event does not match selected weapon: Shotgun."]));
        }
    }

    // Gender validation
    if ($userGender === 'Male' && $eventObj['gender'] === 'Female') {
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Men's athletes can only register for Men's or Mixed events."]));
    }
    if ($userGender === 'Female' && $eventObj['gender'] === 'Male') {
        exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Women's athletes can only register for Women's or Mixed events."]));
    }

    // Validation of participant type and event isolation
    if ($isPara) {
        if (strpos($eventObj['no'], 'R') !== 0) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Registered Para athletes can only register for Para events."]));
        }
    } else if ($isDeaf || $isDefence) {
        $isDeafEv = (strpos($eventObj['no'], 'DS') === 0);
        $labelUpper = strtoupper($eventObj['label']);
        $isDefEv = (strpos($labelUpper, 'SERVICES') !== false || strpos($labelUpper, 'DEFENCE') !== false);
        if (!$isDeafEv && !$isDefEv) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Registered Deaf/Defence athletes can only register for Deaf or Defence events."]));
        }
    } else {
        // Standard athlete
        $isDeafEvent = (strpos($eventObj['no'], 'DS') === 0);
        $isParaEvent = (strpos($eventObj['no'], 'R') === 0);
        $labelUpper = strtoupper($eventObj['label']);
        $isDefenceEvent = (strpos($labelUpper, 'SERVICES') !== false || strpos($labelUpper, 'DEFENCE') !== false);
        if ($isDeafEvent || $isParaEvent || $isDefenceEvent) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Standard athletes cannot register for Para, Deaf, or Defence events."]));
        }
        
        // Age group validation for standard athletes
        $eventAgeGroup = getPhpEventAgeGroup($eventObj['label']);
        if (!in_array($eventAgeGroup, $eligibleGroups, true)) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Athlete is not eligible for event age category: " . htmlspecialchars($eventAgeGroup)]));
        }
    }

    // NRAI Rule 11 Age Group Eligibility Verification (2026)
    if ($dob) {
        $userAge = 2026 - (int)date('Y', strtotime($dob));
        $minRegAge = getMinRegistrationAge($pdo);
        if ($minRegAge > 0 && $userAge < $minRegAge) {
            exit(json_encode(['success'=>false,'message'=>"Cart item ".($idx+1).": Athletes below {$minRegAge} years of age are not permitted to participate."]));
        }
        $userGroup = getBackendUserAgeGroup($userAge);
        $userGender = $regUser['gender'] ?? 'Male';
        if (!isBackendEventAllowedForUser($item['event_name'], $userGender, $userGroup)) {
            exit(json_encode([
                'success' => false,
                'message' => "Cart item " . ($idx+1) . ": You are not eligible to participate in '{$item['event_name']}' based on your age category and the event regulations."
            ]));
        }
    }
}

// ── Payment Screenshot ──
if (!isset($_FILES['payment_screenshot']) || $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['success'=>false,'message'=>'Payment screenshot is required.']));
}

$payFile = $_FILES['payment_screenshot'];
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
$payMime = finfo_file($finfo, $payFile['tmp_name']);
finfo_close($finfo);

$allowedPayMimes = ['image/jpeg','image/png','application/pdf'];
if (!in_array($payMime, $allowedPayMimes))
    exit(json_encode(['success'=>false,'message'=>'Payment screenshot must be JPG, PNG, or PDF.']));
if ($payFile['size'] > 5 * 1024 * 1024)
    exit(json_encode(['success'=>false,'message'=>'Payment screenshot must not exceed 5MB.']));

// ── Organized File Directories ──
$userUploadDir = dirname(__DIR__) . '/uploads/user_' . $userId . '/';
$payDir = $userUploadDir . 'payments/';
$cfDir  = $userUploadDir . 'certificates/';
if (!is_dir($payDir)) mkdir($payDir, 0755, true);
if (!is_dir($cfDir)) mkdir($cfDir, 0755, true);

$payExt = match($payMime) { 'application/pdf'=>'pdf', 'image/png'=>'png', default=>'jpg' };
$payFilename = 'pmt_u'.$userId.'_'.time().'.'.$payExt;
$payDest     = $payDir . $payFilename;
$movedPay = (php_sapi_name() === 'cli') ? copy($payFile['tmp_name'], $payDest) : move_uploaded_file($payFile['tmp_name'], $payDest);
if (!$movedPay)
    exit(json_encode(['success'=>false,'message'=>'Could not save payment screenshot. Check folder permissions.']));
$payPath = 'uploads/user_'.$userId.'/payments/'.$payFilename;

// ── Handle certificate files ──
$certPaths = [];
foreach ($cart as $idx => $item) {
    $finalCat = strtoupper(trim($item['final_cat'] ?? ''));
    $fileKey  = 'cert_'.$idx;

    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $cf    = $_FILES[$fileKey];
        $cfInfo= finfo_open(FILEINFO_MIME_TYPE);
        $cfMime= finfo_file($cfInfo, $cf['tmp_name']); finfo_close($cfInfo);
        $cfAllowed = ['image/jpeg','image/png','application/pdf'];
        if (!in_array($cfMime, $cfAllowed) || $cf['size'] > 5*1024*1024) {
            $certPaths[$idx] = null;
            continue;
        }
        $cfExt  = match($cfMime) {'application/pdf'=>'pdf','image/png'=>'png',default=>'jpg'};
        $cfName = 'cert_u'.$userId.'_idx'.$idx.'_'.time().'.'.$cfExt;
        $movedCert = (php_sapi_name() === 'cli') ? copy($cf['tmp_name'], $cfDir.$cfName) : move_uploaded_file($cf['tmp_name'], $cfDir.$cfName);
        if ($movedCert)
            $certPaths[$idx] = 'uploads/user_'.$userId.'/certificates/'.$cfName;
        else
            $certPaths[$idx] = null;
    } else {
        // Support reusing existing certificate path if it exists
        $certPaths[$idx] = $item['certificate_path'] ?? null;
    }

    // ISSF requires a certificate if enabled and mandatory (either a newly uploaded one or an existing draft one)
    if ($finalCat === 'ISSF' && $isFieldEnabled('certificate_path') && $isFieldMandatory('certificate_path') && empty($certPaths[$idx])) {
        exit(json_encode(['success'=>false,'message'=>"Certificate upload is required for ISSF event: " . ($item['event_name'] ?? '')]));
    }
}

// ── Calculate total ──
$totalAmount = 0;
foreach ($cart as $item) { $totalAmount += calculateEventFeeBackend($item['event_name'], $eventsList); }

$draftSessionId = !empty($_POST['draft_session_id']) ? (int)$_POST['draft_session_id'] : null;

try {
    $pdo = getDB();

    // ── Check duplicates against database ──
    foreach ($cart as $idx => $item) {
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
            exit(json_encode(['success'=>false,'message'=>"You already registered for event '{$item['event_name']}' or have a pending registration."]));
        }
    }

    $pdo->beginTransaction();

    $sessionDbId = null;
    $sessionId = null;

    if ($draftSessionId) {
        // Validate draft session belongs to this user
        $chk = $pdo->prepare("SELECT id, session_id FROM registration_sessions WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$draftSessionId, $userId]);
        $existingSession = $chk->fetch();
        if ($existingSession) {
            $sessionDbId = (int)$existingSession['id'];
            $sessionId = $existingSession['session_id'];

            // Delete old certificates from disk that are no longer referenced in the submitted cart
            $oldEvtsStmt = $pdo->prepare("SELECT certificate_path FROM event_registrations WHERE session_id = ?");
            $oldEvtsStmt->execute([$sessionDbId]);
            $oldCerts = $oldEvtsStmt->fetchAll(PDO::FETCH_COLUMN);

            $newCertPaths = array_values(array_filter($certPaths));
            foreach ($oldCerts as $oldCert) {
                if (!empty($oldCert) && !in_array($oldCert, $newCertPaths, true)) {
                    $oldPath = dirname(__DIR__) . '/' . $oldCert;
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
            }

            // Remove previous draft registrations for this session
            $pdo->prepare("DELETE FROM event_registrations WHERE session_id = ?")->execute([$sessionDbId]);

            // Update session data and change approval status to pending
            $pdo->prepare(
                "UPDATE registration_sessions SET total_amount = ?, payment_screenshot = ?, payment_status = 'uploaded', approval_status = 'pending', updated_at = NOW() WHERE id = ?"
            )->execute([$totalAmount, $payPath, $sessionDbId]);
        }
    }

    if (!$sessionDbId) {
        $activeChampionship = getActiveChampionship($pdo);
        $activeChampionshipId = (int)($activeChampionship['id'] ?? 1);

        // ── Generate Session ID ──
        $sessionId = '';
        do {
            $candidate = 'PAY' . random_int(1000, 9999);
            $chk = $pdo->prepare("SELECT id FROM registration_sessions WHERE session_id = ? LIMIT 1");
            $chk->execute([$candidate]);
            if ($chk->rowCount() === 0) {
                $sessionId = $candidate;
            }
        } while ($sessionId === '');

        // ── Insert Session ──
        $pdo->prepare(
            "INSERT INTO registration_sessions (championship_id, session_id, user_id, total_amount, payment_screenshot, payment_status, approval_status)
             VALUES (?, ?, ?, ?, ?, 'uploaded', 'pending')"
        )->execute([$activeChampionshipId, $sessionId, $userId, $totalAmount, $payPath]);
        $sessionDbId = (int)$pdo->lastInsertId();
    } else {
        $activeChampionship = getActiveChampionship($pdo);
        $activeChampionshipId = (int)($activeChampionship['id'] ?? 1);
    }

    // Insert custom field values for this event registration session
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

    // ── Update user's reg_id if not set ──
    $firstEventRegId = null;

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

    // ── Insert each event ──
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
        $eventRegId = formatBibNo($regUser['reg_id'] ?? '');

        if ($firstEventRegId === null) {
            $firstEventRegId = $eventRegId;
        }

        $evtCode = strtoupper(preg_replace('/[^A-Z0-9]/', '-', $item['event_name'] ?? ''));

        $pdo->prepare(
            "INSERT INTO event_registrations
              (championship_id, session_id, event_reg_id, user_id, category, event_code, match_no, event_name, weapon_type,
               age_group, issf_number, mqs_score, best_score, shooting_year, competition_name, certificate_path,
               entry_fee, disability_type, classification, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $activeChampionshipId, $sessionDbId, $eventRegId, $userId, $finalCat, $evtCode,
            $matchNo, $item['event_name'], $weaponType, $item['age_group'] ?? 'Senior',
            $issfNum, $mqsScore, !empty($item['best_score']) ? (float)$item['best_score'] : 0,
            $shootYear, $compName, $certPath, $entryFee, $disType, $classif
        ]);
    }
    // Get user details
    $userQ = $pdo->prepare("SELECT first_name, last_name FROM registrations WHERE id = ?");
    $userQ->execute([$userId]);
    $uName = $userQ->fetch();
    $fullName = ($uName['first_name'] ?? '') . ' ' . ($uName['last_name'] ?? '');

    // Insert Admin Notification
    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (is_admin, title, message, redirect_url)
        VALUES (1, 'New Event Registration', ?, 'payment_sessions.php')
    ");
    $notifStmt->execute(["Participant " . $fullName . " submitted event registration session " . $sessionId . " (Total: ₹" . number_format($totalAmount, 2) . ")."]);

    $pdo->commit();

    // Send email to participant acknowledging payment proof submission
    try {
        require_once dirname(__DIR__) . '/config/mail.php';
        $userEmailQ = $pdo->prepare("SELECT email FROM registrations WHERE id = ?");
        $userEmailQ->execute([$userId]);
        $uEmail = $userEmailQ->fetchColumn();
        
        if (!empty($uEmail)) {
            $subject = "Payment Screenshot Received - Session " . $sessionId;
            $body = getPaymentSubmittedEmailBody($fullName, $sessionId, $totalAmount);
            // sendMail($uEmail, $subject, $body, $fullName);
        }
    } catch (Exception $e) {
        error_log('Event register email sending failed: ' . $e->getMessage());
    }

    // Refresh CSRF
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    exit(json_encode([
        'success'    => true,
        'session_id' => $sessionId,
        'total'      => $totalAmount,
        'message'    => 'Registration submitted successfully.'
    ]));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Cleanup uploaded files on failure
    if (file_exists($payDest)) @unlink($payDest);
    error_log('Event reg error: ' . $e->getMessage());
    exit(json_encode(['success'=>false,'message'=>'Server error. Please try again. ('.$e->getMessage().')']));
}
