<?php
/**
 * actions/send_otp.php
 * Generates and emails a registration verification OTP code.
 * Returns JSON: { success, message }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/mail.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

// CSRF
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']));
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['success' => false, 'message' => 'Invalid email address.']));
}

try {
    $pdo = getDB();

    // Check if email already registered
    $stmt = $pdo->prepare("SELECT id FROM registrations WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        exit(json_encode(['success' => false, 'message' => 'This email address is already registered.']));
    }

    // Generate 6-digit numeric OTP
    $otp = (string)random_int(100000, 999999);
    $expiry = time() + 600; // valid for 10 minutes

    $_SESSION['register_otp'] = [
        'email'   => $email,
        'otp'     => $otp,
        'expires' => $expiry
    ];

    // Build premium email body
    $emailSubject = "Email Verification Code - SSA";
    $emailBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#08090C;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#08090C;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#0D0F14;border:1px solid rgba(255, 255, 255,0.3);border-radius:12px;overflow:hidden;">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#ADB5BD,#E9ECEF);padding:30px 40px;text-align:center;">
          <div style="font-size:36px;margin-bottom:8px;">🎯</div>
          <h1 style="margin:0;color:#0D0F14;font-size:22px;font-weight:800;letter-spacing:1px;">EMAIL VERIFICATION</h1>
          <p style="margin:8px 0 0;color:#0D0F14;font-size:13px;opacity:0.8;"><?= htmlspecialchars(getActiveChampionship()['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></p>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:36px 40px;text-align:left;">
          <p style="color:#A8A49C;font-size:14px;margin:0 0 20px;">Hello,</p>
          <p style="color:#A8A49C;font-size:14px;margin:0 0 24px;line-height:1.7;">
            Thank you for starting your registration. Please use the following 6-digit OTP verification code to confirm your email and submit your registration:
          </p>
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:20px;text-align:center;margin-bottom:24px;">
            <span style="font-size:32px;font-weight:800;letter-spacing:6px;color:#E9ECEF;font-family:monospace;">{$otp}</span>
          </div>
          <p style="color:#A8A49C;font-size:13px;margin:0 0 20px;line-height:1.6;opacity:0.8;">
            This OTP is valid for <strong>10 minutes</strong>. If you did not request this, you can safely ignore this email.
          </p>
          <hr style="border:0;border-top:1px solid rgba(255,255,255,0.06);margin:24px 0;">
          <p style="color:#A8A49C;font-size:12px;margin:0;line-height:1.6;opacity:0.6;text-align:center;">
            TARGET (Tournament Administration and Registration Gateway for Event Tracking) &copy; 2026. All rights reserved.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $mailSent = sendMail($email, $emailSubject, $emailBody, 'Participant');

    if ($mailSent) {
        exit(json_encode([
            'success' => true,
            'message' => 'Verification code sent to your email.'
        ]));
    } else {
        // Fallback when SMTP is not configured or fails
        exit(json_encode([
            'success' => true,
            'message' => 'Verification code sent to email (simulated/logged). [OTP: ' . $otp . ']'
        ]));
    }
} catch (Exception $e) {
    error_log('Send OTP error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'Server error. Please try again.']));
}
