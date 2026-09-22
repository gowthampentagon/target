<?php
/**
 * actions/edit_profile_action.php
 * Handles participant profile updates.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']));
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized.']));
}

function clean(string $v): string {
    return trim(htmlspecialchars_decode(strip_tags(trim($v))));
}

$first_name        = mb_strtoupper(clean($_POST['first_name']          ?? ''), 'UTF-8');
$last_name         = mb_strtoupper(clean($_POST['last_name']           ?? ''), 'UTF-8');
$email             = strtolower(trim($_POST['email']     ?? ''));
$phone             = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$aadhaar           = preg_replace('/\s/', '', $_POST['aadhaar_number'] ?? '');
$club_name         = mb_strtoupper(clean($_POST['club_name']           ?? ''), 'UTF-8');
$membership_id     = mb_strtoupper(clean($_POST['membership_id']      ?? ''), 'UTF-8');
$dobRaw            = clean($_POST['dob']                 ?? '');
$dob               = '';
if ($dobRaw !== '') {
    $dobTs = strtotime(str_replace('/', '-', $dobRaw));
    if ($dobTs !== false) {
        $dob = date('Y-m-d', $dobTs);
    }
}
$gender            = ucfirst(strtolower(clean($_POST['gender']              ?? '')));
$district          = mb_strtoupper(clean($_POST['district']            ?? ''), 'UTF-8');
$association       = mb_strtoupper(clean($_POST['association']         ?? ''), 'UTF-8');
$father_guardian   = mb_strtoupper(clean($_POST['father_guardian_name'] ?? ''), 'UTF-8');
$address           = mb_strtoupper(clean($_POST['address']             ?? ''), 'UTF-8');
$password          = $_POST['password']                  ?? '';
$confirm_password  = $_POST['confirm_password']          ?? '';
$photoPath         = null;
$membershipDocPath = null;

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

$errors = [];
if (!preg_match('/^[\p{L}\s.\'-]{2,100}$/u', $first_name)) {
    $errors[] = 'First name is invalid.';
}
if (!empty($last_name) && !preg_match('/^[\p{L}\s.\'-]{1,100}$/u', $last_name)) {
    $errors[] = 'Last name is invalid.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
    $errors[] = 'Email address is invalid.';
}
if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
    $errors[] = 'Mobile number must be a valid 10-digit Indian number.';
}
if (!preg_match('/^\d{12}$/', $aadhaar)) {
    $errors[] = 'Aadhaar number must be exactly 12 digits.';
}
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
if (!in_array($gender, $allowedGenders, true)) {
    $errors[] = 'Gender selection is invalid.';
}
if (strlen($district) < 2) {
    $errors[] = 'District is required.';
}
if (!preg_match('/^[A-Za-z\s.\'-]{2,200}$/', $father_guardian)) {
    $errors[] = 'Father/Guardian name is invalid.';
}
if (strlen($address) < 10) {
    $errors[] = 'Address must be at least 10 characters.';
}
if ($password !== '' && strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}
if ($password !== '' && $password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
}

try {
    $pdo = getDB();
    // Check if profile exists
    $currentUserStmt = $pdo->prepare("SELECT id FROM registrations WHERE id = ? LIMIT 1");
    $currentUserStmt->execute([$userId]);
    $currentUser = $currentUserStmt->fetch();
    if (!$currentUser) {
        exit(json_encode(['success' => false, 'message' => 'Profile not found.']));
    }

    $stmt = $pdo->prepare("SELECT id FROM registrations WHERE (email = ? OR aadhaar_number = ? OR phone = ?) AND id != ? LIMIT 1");
    $stmt->execute([$email, $aadhaar, $phone, $userId]);
    if ($stmt->fetch()) {
        $dupCheck = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM registrations WHERE email = ? AND id != ?) AS em,
            (SELECT COUNT(*) FROM registrations WHERE aadhaar_number = ? AND id != ?) AS ad,
            (SELECT COUNT(*) FROM registrations WHERE phone = ? AND id != ?) AS ph");
        $dupCheck->execute([$email, $userId, $aadhaar, $userId, $phone, $userId]);
        $dups = $dupCheck->fetch();
        $parts = [];
        if ($dups['em'] > 0) $parts[] = 'email already in use';
        if ($dups['ad'] > 0) $parts[] = 'Aadhaar already in use';
        if ($dups['ph'] > 0) $parts[] = 'phone already in use';
        exit(json_encode(['success' => false, 'message' => 'Update failed: ' . implode(', ', $parts) . '.']));
    }
} catch (Exception $e) {
    error_log('Update profile duplicate check error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']));
}

if (!empty($errors)) {
    exit(json_encode(['success' => false, 'message' => implode(' ', $errors)]));
}

try {
    $fields = [
        'first_name'          => $first_name,
        'last_name'           => $last_name,
        'email'               => $email,
        'phone'               => $phone,
        'aadhaar_number'      => $aadhaar,
        'dob'                 => $dob,
        'gender'              => $gender,
        'district'            => $district,
        'father_guardian_name'=> $father_guardian,
        'address'             => $address,
    ];

    if ($photoPath !== null) {
        $fields['photo'] = $photoPath;
    }

    if ($password !== '') {
        $fields['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    $sql = 'UPDATE registrations SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields))) . ' WHERE id = :id';
    $fields['id'] = $userId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($fields);

    // Refresh session name values if changed
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;
    }

    exit(json_encode([
        'success'  => true,
        'message'  => 'Profile updated successfully.',
        'redirect' => 'dashboard.php?tab=profile&updated=1',
    ]));
} catch (Exception $e) {
    error_log('Update profile error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']));
}
