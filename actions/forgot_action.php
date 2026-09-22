<?php
/**
 * actions/forgot_action.php
 * Two-step password recovery handler.
 * Step 1: Generate + store a 6-char token, display it to user.
 * Step 2: Validate token, reset password.
 *
 * NOTE: In production replace the "token display" with actual email delivery.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

// ── CSRF ─────────────────────────────────────────────────────
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Security token mismatch.']));
}

$step = (int) ($_POST['step'] ?? 1);

try {
    $pdo = getDB();

    // ────────────────────────────────────────────────────────
    // STEP 1 – Generate token
    // ────────────────────────────────────────────────────────
    if ($step === 1) {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            exit(json_encode(['success' => false, 'message' => 'Invalid email address.']));
        }

        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM registrations WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            exit(json_encode([
                'success' => false,
                'message' => 'This email address is not registered or active.'
            ]));
        }

        // Generate 6-char alphanumeric token
        $token  = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $expiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $pdo->prepare("UPDATE registrations SET reset_token = ?, reset_token_expiry = ? WHERE id = ?")
            ->execute([$token, $expiry, $user['id']]);

        // Store in session for step 2 verification
        $_SESSION['fp_email'] = $email;
        $_SESSION['fp_token'] = $token;

        // Build premium email body
        $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
        $emailSubject = "Password Reset Token - SSA";
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
          <h1 style="margin:0;color:#0D0F14;font-size:22px;font-weight:800;letter-spacing:1px;">PASSWORD RECOVERY</h1>
          <p style="margin:8px 0 0;color:#0D0F14;font-size:13px;opacity:0.8;"><?= htmlspecialchars(getActiveChampionship()['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></p>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:36px 40px;text-align:left;">
          <p style="color:#A8A49C;font-size:14px;margin:0 0 20px;">Dear <strong style="color:#E9ECEF;">{$fullName}</strong>,</p>
          <p style="color:#A8A49C;font-size:14px;margin:0 0 24px;line-height:1.7;">
            We received a request to reset your password for the championship portal. Please use the following 6-character security token to complete your request:
          </p>
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:20px;text-align:center;margin-bottom:24px;">
            <span style="font-size:32px;font-weight:800;letter-spacing:6px;color:#E9ECEF;font-family:monospace;">{$token}</span>
          </div>
          <p style="color:#A8A49C;font-size:13px;margin:0 0 20px;line-height:1.6;opacity:0.8;">
            This token is valid for <strong>30 minutes</strong>. If you did not make this request, you can safely ignore this email.
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

        require_once dirname(__DIR__) . '/config/mail.php';
        $mailSent = sendMail($email, $emailSubject, $emailBody, $fullName);

        if ($mailSent) {
            exit(json_encode([
                'success' => true,
                'message' => 'A password reset token has been sent to your registered email address.'
            ]));
        } else {
            exit(json_encode([
                'success' => false,
                'message' => 'Failed to send recovery email. Please check your SMTP settings.'
            ]));
        }
    }

    // ────────────────────────────────────────────────────────
    // STEP 2 – Reset password
    // ────────────────────────────────────────────────────────
    if ($step === 2) {
        $email        = strtolower(trim($_POST['email']        ?? ''));
        $token        = strtoupper(trim($_POST['token']        ?? ''));
        $newPassword  = $_POST['new_password']                 ?? '';
        $confirmPw    = $_POST['confirm_password']             ?? '';

        // Basic validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            exit(json_encode(['success' => false, 'message' => 'Invalid email.']));

        if (strlen($token) !== 6)
            exit(json_encode(['success' => false, 'message' => 'Token must be 6 characters.']));

        if (strlen($newPassword) < 8)
            exit(json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']));

        if ($newPassword !== $confirmPw)
            exit(json_encode(['success' => false, 'message' => 'Passwords do not match.']));

        // Fetch user with valid token
        $stmt = $pdo->prepare("
            SELECT id FROM registrations
             WHERE email = ?
               AND reset_token = ?
               AND reset_token_expiry > ?
               AND status = 'active'
             LIMIT 1
        ");
        $stmt->execute([$email, $token, date('Y-m-d H:i:s')]);
        $user = $stmt->fetch();

        if (!$user) {
            exit(json_encode([
                'success' => false,
                'message' => 'Invalid or expired token. Please request a new one.'
            ]));
        }

        // Update password and clear token
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("
            UPDATE registrations
               SET password_hash = ?,
                   reset_token   = NULL,
                   reset_token_expiry = NULL
             WHERE id = ?
        ")->execute([$hash, $user['id']]);

        // Clear session data
        unset($_SESSION['fp_email'], $_SESSION['fp_token']);

        exit(json_encode([
            'success' => true,
            'message' => 'Password reset successfully! You can now log in.'
        ]));
    }

    exit(json_encode(['success' => false, 'message' => 'Invalid step.']));

} catch (Exception $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']));
}
