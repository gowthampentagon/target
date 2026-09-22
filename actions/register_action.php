<?php
/**
 * actions/register_action.php
 * Handles new participant registration via AJAX.
 * Returns JSON: { success, message, reg_id }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$pdo = getDB();

// ── Only POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

// ── CSRF ─────────────────────────────────────────────────────
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']));
}

// ── Load Field Configuration ─────────────────────────────────
$fieldSettings = [];
$fieldsConfig = [];
try {
    $fieldsConfig = getFieldControls('registration');
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

// ── Sanitize & collect inputs ────────────────────────────────
function clean(string $v): string {
    return trim(htmlspecialchars_decode(strip_tags(trim($v))));
}

$first_name          = mb_strtoupper(clean($_POST['first_name']          ?? ''), 'UTF-8');
$last_name           = mb_strtoupper(clean($_POST['last_name']           ?? ''), 'UTF-8');
$email               = strtolower(trim($_POST['email']     ?? ''));
$phone               = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$aadhaar             = $isFieldEnabled('aadhaar_number') ? preg_replace('/\s/', '', $_POST['aadhaar_number'] ?? '') : null;
$club_name           = mb_strtoupper(clean($_POST['club_name']           ?? ''), 'UTF-8');
$membership_id       = mb_strtoupper(clean($_POST['membership_id']       ?? ''), 'UTF-8');
$dobRaw              = clean($_POST['dob']                 ?? '');
$dob                 = '';
if ($dobRaw !== '') {
    $dobTs = strtotime(str_replace('/', '-', $dobRaw));
    if ($dobTs !== false) {
        $dob = date('Y-m-d', $dobTs);
    }
}
$gender              = ucfirst(strtolower(clean($_POST['gender']              ?? '')));
$district            = mb_strtoupper(clean($_POST['district']            ?? ''), 'UTF-8');
$association         = mb_strtoupper(clean($_POST['association']         ?? ''), 'UTF-8');
$father_guardian     = $isFieldEnabled('father_guardian_name') ? mb_strtoupper(clean($_POST['father_guardian_name'] ?? ''), 'UTF-8') : null;
$address             = $isFieldEnabled('address') ? mb_strtoupper(clean($_POST['address']             ?? ''), 'UTF-8') : null;
$is_para             = $isFieldEnabled('is_para') ? (int)($_POST['is_para'] ?? 0) : 0;
$is_deaf             = $isFieldEnabled('is_deaf') ? (int)($_POST['is_deaf'] ?? 0) : 0;
$password            = $_POST['password']                  ?? '';
$confirm_password    = $_POST['confirm_password']          ?? '';
$otp                 = clean($_POST['otp']                 ?? '');
$photoPath           = null;
$membershipDocPath   = null;

// ── Handle Photo Upload ────────────────────────────────────
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        exit(json_encode(['success' => false, 'message' => 'Profile photo must be JPG, PNG, GIF, or WebP.']));
    }

    if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
        exit(json_encode(['success' => false, 'message' => 'Profile photo must be under 2MB.']));
    }

    $uploadDir = dirname(__DIR__) . '/uploads/profile_photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $filename = 'profile_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        exit(json_encode(['success' => false, 'message' => 'Failed to upload profile photo.']));
    }

    $photoPath = 'uploads/profile_photos/' . $filename;
}

// ── Handle Membership Document Upload ───────────────────────
if (isset($_FILES['membership_doc']) && $_FILES['membership_doc']['error'] === UPLOAD_ERR_OK) {
    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['membership_doc']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        exit(json_encode(['success' => false, 'message' => 'Membership document must be JPG, PNG, or PDF.']));
    }

    if ($_FILES['membership_doc']['size'] > 2 * 1024 * 1024) {
        exit(json_encode(['success' => false, 'message' => 'Membership document must be under 2MB.']));
    }

    $uploadDir = dirname(__DIR__) . '/uploads/membership_docs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['membership_doc']['name'], PATHINFO_EXTENSION));
    $filename = 'membership_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['membership_doc']['tmp_name'], $dest)) {
        exit(json_encode(['success' => false, 'message' => 'Failed to upload membership document.']));
    }

    $membershipDocPath = 'uploads/membership_docs/' . $filename;
}

// ── Handle Disability Proof Upload ──────────────────────────
$disabilityProofPath = null;
if (($isFieldEnabled('is_para') && $is_para === 1) || ($isFieldEnabled('is_deaf') && $is_deaf === 1)) {
    if (!isset($_FILES['disability_proof']) || $_FILES['disability_proof']['error'] !== UPLOAD_ERR_OK) {
        exit(json_encode(['success' => false, 'message' => 'Disability certificate / proof document is mandatory for Para / Deaf athletes.']));
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['disability_proof']['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        exit(json_encode(['success' => false, 'message' => 'Disability proof document must be JPG, PNG, or PDF.']));
    }

    if ($_FILES['disability_proof']['size'] > 2 * 1024 * 1024) {
        exit(json_encode(['success' => false, 'message' => 'Disability proof document must be under 2MB.']));
    }

    $uploadDir = dirname(__DIR__) . '/uploads/disability_proofs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['disability_proof']['name'], PATHINFO_EXTENSION));
    $filename = 'disability_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['disability_proof']['tmp_name'], $dest)) {
        exit(json_encode(['success' => false, 'message' => 'Failed to upload disability proof document.']));
    }

    $disabilityProofPath = 'uploads/disability_proofs/' . $filename;
}

// ── Server-side validation ───────────────────────────────────
$errors = [];

if (!preg_match('/^[\p{L}\s.\'-]{2,100}$/u', $first_name))
    $errors[] = 'First name is invalid.';

if (!empty($last_name) && !preg_match('/^[\p{L}\s.\'-]{1,100}$/u', $last_name))
    $errors[] = 'Last name is invalid.';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180)
    $errors[] = 'Email address is invalid.';

if (!preg_match('/^[6-9]\d{9}$/', $phone))
    $errors[] = 'Mobile number must be a valid 10-digit Indian number.';

if ($isFieldEnabled('aadhaar_number')) {
    if ($isFieldMandatory('aadhaar_number') || !empty($aadhaar)) {
        if (!preg_match('/^\d{12}$/', $aadhaar)) {
            $errors[] = 'Aadhaar number must be exactly 12 digits.';
        }
    }
}

if (strlen($club_name) < 2 || strlen($club_name) > 200)
    $errors[] = 'Club name is required.';

// DOB validation
if (!$dob || !strtotime($dob)) {
    $errors[] = 'Date of birth is invalid.';
} else {
    $age = (int) floor((time() - strtotime($dob)) / (365.25 * 86400));
    $minRegAge = getMinRegistrationAge($pdo);
    if ($age < $minRegAge || $age > 100) {
        if ($minRegAge > 0) {
            $errors[] = "You must be at least {$minRegAge} years old to register.";
        } else {
            $errors[] = "Date of birth is invalid.";
        }
    }
}

$allowedGenders = ['Male', 'Female', 'Transgender'];
if (!in_array($gender, $allowedGenders, true))
    $errors[] = 'Gender selection is invalid.';

if (strlen($district) < 2)
    $errors[] = 'District is required.';

if (strlen($association) < 2)
    $errors[] = 'Association is required.';

if (empty($membership_id)) {
    $errors[] = 'Membership ID is required.';
} elseif (strlen($membership_id) > 50) {
    $errors[] = 'Membership ID cannot exceed 50 characters.';
}

if ($isFieldEnabled('father_guardian_name')) {
    if ($isFieldMandatory('father_guardian_name') || !empty($father_guardian)) {
        if (empty($father_guardian) || !preg_match('/^[A-Za-z\s.\'-]{2,200}$/', $father_guardian)) {
            $errors[] = 'Father/Guardian name is invalid.';
        }
    }
}

if ($isFieldEnabled('address')) {
    if ($isFieldMandatory('address') || !empty($address)) {
        if (empty($address) || strlen($address) < 10) {
            $errors[] = 'Address must be at least 10 characters.';
        }
    }
}

if (strlen($password) < 8)
    $errors[] = 'Password must be at least 8 characters.';

if ($password !== $confirm_password)
    $errors[] = 'Passwords do not match.';

// Required files validation
if ($isFieldEnabled('photo')) {
    if ($isFieldMandatory('photo') && !$photoPath) {
        $errors[] = 'Profile photo is required.';
    }
}
if (!$membershipDocPath) {
    $errors[] = 'Membership document is required.';
}
if ((($isFieldEnabled('is_para') && $is_para === 1) || ($isFieldEnabled('is_deaf') && $is_deaf === 1)) && !$disabilityProofPath) {
    $errors[] = 'Disability certificate / proof is required for Para or Deaf athletes.';
}

// Custom fields validation
$customFieldsInput = $_POST['custom_fields'] ?? [];
foreach ($fieldsConfig as $f) {
    if ($f['is_custom'] && $f['is_enabled']) {
        $val = trim($customFieldsInput[$f['field_id']] ?? '');
        if ($f['is_mandatory'] && empty($val)) {
            $errors[] = htmlspecialchars($f['field_label']) . ' is required.';
        }
    }
}

// OTP Validation
if ($isFieldEnabled('otp')) {
    if ($isFieldMandatory('otp') && empty($otp)) {
        $errors[] = 'Email verification OTP is required.';
    } elseif (!empty($otp)) {
        if (empty($_SESSION['register_otp'])) {
            $errors[] = 'Please request an OTP first.';
        } else {
            $otpSession = $_SESSION['register_otp'];
            if ($otpSession['email'] !== $email) {
                $errors[] = 'OTP email mismatch. Please request a new OTP.';
            } elseif (time() > $otpSession['expires']) {
                $errors[] = 'OTP has expired. Please request a new one.';
            } elseif ($otpSession['otp'] !== $otp) {
                $errors[] = 'Invalid OTP. Please try again.';
            }
        }
    }
}

if (!empty($errors)) {
    exit(json_encode(['success' => false, 'message' => implode(' ', $errors)]));
}

// ── Database Insert ──────────────────────────────────────────
try {
    $pdo = getDB();

    // Check uniqueness
    $stmt = $pdo->prepare("
        SELECT id FROM registrations 
        WHERE email = ? 
           OR phone = ? 
           OR (aadhaar_number = ? AND aadhaar_number IS NOT NULL AND aadhaar_number != '') 
        LIMIT 1
    ");
    $stmt->execute([$email, $phone, $aadhaar ?? '']);
    if ($stmt->rowCount() > 0) {
        $existing = $stmt->fetch();
        // Determine which field
        $dupCheck = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM registrations WHERE email = ?) AS em,
            (SELECT COUNT(*) FROM registrations WHERE phone = ?) AS ph,
            (SELECT COUNT(*) FROM registrations WHERE aadhaar_number = ? AND aadhaar_number IS NOT NULL AND aadhaar_number != '') AS ad");
        $dupCheck->execute([$email, $phone, $aadhaar ?? '']);
        $dups = $dupCheck->fetch();
        $dupMsg = 'Registration already exists: ';
        $parts  = [];
        if ($dups['em'] > 0) $parts[] = 'email already registered';
        if ($dups['ph'] > 0) $parts[] = 'phone already registered';
        if ($dups['ad'] > 0) $parts[] = 'Aadhaar already registered';
        exit(json_encode(['success' => false, 'message' => $dupMsg . implode(', ', $parts) . '.']));
    }

    // Hash password
    $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Generate enrollment ID (reg_id)
    $reg_id = generateRegId();

    // Insert
    $insert = $pdo->prepare("
        INSERT INTO registrations
            (reg_id, first_name, last_name, email, phone, aadhaar_number, is_para, is_deaf,
             club_name, membership_id, dob, gender, district, association,
             father_guardian_name, address, photo, membership_doc, disability_proof, password_hash)
        VALUES
            (:reg_id, :fn, :ln, :em, :ph, :ad, :is_para, :is_deaf,
             :cl, :mid, :dob, :ge, :di, :as_name,
              :fg, :addr, :photo, :mdoc, :dproof, :pw)
    ");
    $insert->execute([
        ':reg_id'   => $reg_id,
        ':fn'       => $first_name,
        ':ln'       => $last_name,
        ':em'       => $email,
        ':ph'       => $phone,
        ':ad'       => $aadhaar,
        ':is_para'  => $is_para,
        ':is_deaf'  => $is_deaf,
        ':cl'       => $club_name,
        ':mid'      => $membership_id,
        ':dob'      => $dob,
        ':ge'       => $gender,
        ':di'       => $district,
        ':as_name'  => $association,
        ':fg'       => $father_guardian,
        ':addr'     => $address,
        ':photo'    => $photoPath,
        ':mdoc'     => $membershipDocPath,
        ':dproof'   => $disabilityProofPath,
        ':pw'       => $hash,
    ]);

    $newUserId = (int)$pdo->lastInsertId();
    // Insert custom field values
    $customFieldsInput = $_POST['custom_fields'] ?? [];
    $valStmt = $pdo->prepare("
        INSERT INTO custom_field_values (entity_type, entity_id, field_id, field_value)
        VALUES ('user', ?, ?, ?)
    ");
    foreach ($fieldsConfig as $f) {
        if ($f['is_custom'] && $f['is_enabled']) {
            $val = trim($customFieldsInput[$f['field_id']] ?? '');
            $valStmt->execute([$newUserId, $f['field_id'], $val]);
        }
    }

    // Clear registration OTP
    unset($_SESSION['register_otp']);

    exit(json_encode([
        'success' => true,
        'reg_id'  => $reg_id,
        'message' => "Registration successful! Your Enrollment ID is " . $reg_id . "."
    ]));

} catch (PDOException $e) {
    error_log('Registration DB error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']));
} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']));
}
