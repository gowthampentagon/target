<?php
/**
 * config/mail.php
 * Email configuration for SSA Championship
 * Update SMTP settings for production use.
 */

// Load local mail configuration override if it exists
if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
}

// ── SMTP Settings ──
if (!defined('MAIL_HOST'))       define('MAIL_HOST',     'smtp.gmail.com');  // e.g. smtp.gmail.com, smtp.hostinger.com
if (!defined('MAIL_PORT'))       define('MAIL_PORT',     587);               // 587 for TLS, 465 for SSL
if (!defined('MAIL_USERNAME'))   define('MAIL_USERNAME', 'ssagnc16@gmail.com');  // Sender email
if (!defined('MAIL_PASSWORD'))   define('MAIL_PASSWORD', 'plafhfnovlhkglfu');      // Gmail App Password (not regular password)
if (!defined('MAIL_FROM'))       define('MAIL_FROM',     'ssagnc16@gmail.com');
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME','SSA – Championship Portal');
if (!defined('MAIL_ENCRYPTION')) define('MAIL_ENCRYPTION', 'tls');           // 'tls' or 'ssl'

/**
 * sendMail() – Simple SMTP mailer using PHP streams (no dependency)
 * Falls back to writing email to log if SMTP fails.
 *
 * @param string $to       Recipient email
 * @param string $subject  Email subject
 * @param string $htmlBody HTML body
 * @param string $toName   Recipient name
 * @return bool
 */
function sendMail(string $to, string $subject, string $htmlBody, string $toName = ''): bool {
    $host = MAIL_HOST;
    $port = MAIL_PORT;
    $username = MAIL_USERNAME;
    $password = MAIL_PASSWORD;
    $from = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;
    $encryption = strtolower(MAIL_ENCRYPTION);

    // If using gmail.com or regular credentials placeholder, bypass sending and log it
    if ($username === 'your_email@gmail.com' || empty($username) || empty($password)) {
        return logEmailFallback($to, $subject, $htmlBody, $toName);
    }

    try {
        $socketHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 15);
        if (!$socket) {
            throw new Exception("Could not connect to SMTP server: $errstr ($errno)");
        }
        stream_set_timeout($socket, 5);

        $read = function($socket, $expectedResponse) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') {
                    break;
                }
            }
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out']) {
                throw new Exception("SMTP socket read timed out.");
            }
            $code = (int)substr($response, 0, 3);
            if (is_array($expectedResponse)) {
                if (!in_array($code, $expectedResponse)) {
                    throw new Exception("Expected responses: " . implode(' or ', $expectedResponse) . ", got: " . $response);
                }
            } else {
                if ($code !== $expectedResponse) {
                    throw new Exception("Expected response code: $expectedResponse, got: " . $response);
                }
            }
            return $response;
        };

        // Welcome message
        $read($socket, 220);

        // EHLO
        fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
        $read($socket, 250);

        // STARTTLS if TLS
        if ($encryption === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $read($socket, 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Failed to start encryption (TLS)");
            }
            // Send EHLO again after STARTTLS
            fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
            $read($socket, 250);
        }

        // AUTH LOGIN
        if (!empty($username)) {
            fwrite($socket, "AUTH LOGIN\r\n");
            $read($socket, 334);
            fwrite($socket, base64_encode($username) . "\r\n");
            $read($socket, 334);
            fwrite($socket, base64_encode($password) . "\r\n");
            $read($socket, 235);
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM:<" . $from . ">\r\n");
        $read($socket, 250);

        // RCPT TO
        fwrite($socket, "RCPT TO:<" . $to . ">\r\n");
        $read($socket, [250, 251]);

        // DATA
        fwrite($socket, "DATA\r\n");
        $read($socket, 354);

        // Headers & Content
        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=UTF-8",
            "To: " . (!empty($toName) ? "=?UTF-8?B?" . base64_encode($toName) . "?= <" . $to . ">" : $to),
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <" . $from . ">",
            "Reply-To: " . $from,
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Message-ID: <" . md5(uniqid((string)time(), true)) . "@" . $host . ">",
            "X-Mailer: SSA-PHP-SMTP/1.0"
        ];

        // Body must have normalized CRLF and escaped leading dots
        $normalizedBody = str_replace(["\r\n", "\r", "\n"], "\r\n", $htmlBody);
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody);

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody . "\r\n.\r\n";
        fwrite($socket, $message);
        $read($socket, 250);

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return true;
    } catch (Exception $e) {
        error_log("SMTP Mailer Error: " . $e->getMessage());
        return logEmailFallback($to, $subject, $htmlBody, $toName);
    }
}

function logEmailFallback(string $to, string $subject, string $htmlBody, string $toName = ''): bool {
    $logDir  = dirname(__DIR__) . '/logs/emails/';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . date('Ymd') . '_emails.log';
    $logContent = "=== [" . date('Y-m-d H:i:s') . "] TO: {$toName} <{$to}> ===\n";
    $logContent .= "SUBJECT: {$subject}\n";
    $logContent .= "BODY:\n" . strip_tags($htmlBody) . "\n\n";
    @file_put_contents($logFile, $logContent, FILE_APPEND);
    return false;
}

/**
 * getApprovalEmailBody() – HTML email template for event approval
 */
function getApprovalEmailBody(array $user, array $session, array $events): string {
    $evtRows = '';
    foreach ($events as $e) {
        $catMap = ['ISSF'=>'ISSF Events','NR'=>'NR Events','PARA_DEAF'=>'Para/Deaf','NR_MQS'=>'NR for MQS'];
        $cat    = $catMap[$e['category']] ?? $e['category'];
        $evtRows .= "
        <tr>
          <td style='padding:10px 14px; border-bottom:1px solid #333; color:#E9ECEF; font-weight:600;'>{$e['event_reg_id']}</td>
          <td style='padding:10px 14px; border-bottom:1px solid #333; color:#F0EDE6;'>{$cat}</td>
          <td style='padding:10px 14px; border-bottom:1px solid #333; color:#F0EDE6;'>{$e['event_name']}</td>
          <td style='padding:10px 14px; border-bottom:1px solid #333; color:#F0EDE6;'>₹" . number_format((float)$e['entry_fee'], 2) . "</td>
        </tr>";
    }

    $name  = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
    $regId = htmlspecialchars($user['reg_id'] ?? 'TBD');
    $total = '₹' . number_format((float)$session['total_amount'], 2);
    $activeTitle = htmlspecialchars(getActiveChampionship()['championship_name'] ?? 'State Shooting Championship');

    return <<<HTML
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
          <h1 style="margin:0;color:#0D0F14;font-size:22px;font-weight:800;letter-spacing:1px;">REGISTRATION APPROVED</h1>
          <p style="margin:8px 0 0;color:#0D0F14;font-size:13px;opacity:0.8;">{$activeTitle}</p>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:36px 40px;">
          <p style="color:#A8A49C;font-size:14px;margin:0 0 20px;">Dear <strong style="color:#E9ECEF;">{$name}</strong>,</p>
          <p style="color:#A8A49C;font-size:14px;margin:0 0 24px;line-height:1.7;">
            We are delighted to inform you that your event registration for the <strong style="color:#F0EDE6;">{$activeTitle}</strong>
            has been <strong style="color:#27AE60;">APPROVED</strong> by the administration after successful payment verification.
          </p>

          <!-- Reg ID -->
          <div style="background:rgba(255, 255, 255,0.08);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:16px;text-align:center;margin:0 0 24px;">
            <div style="color:#A8A49C;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Your Registration ID</div>
            <div style="color:#E9ECEF;font-size:24px;font-weight:800;letter-spacing:3px;">{$regId}</div>
          </div>

          <!-- Events Table -->
          <h3 style="color:#E9ECEF;font-size:13px;text-transform:uppercase;letter-spacing:1.5px;margin:0 0 12px;border-bottom:1px solid rgba(255, 255, 255,0.2);padding-bottom:8px;">
            Approved Events
          </h3>
          <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;margin-bottom:24px;">
            <thead>
              <tr style="background:rgba(255,255,255,0.03);">
                <th style="padding:10px 14px; text-align:left; color:#A8A49C; font-size:11px; text-transform:uppercase; letter-spacing:1px;">Event Reg ID</th>
                <th style="padding:10px 14px; text-align:left; color:#A8A49C; font-size:11px; text-transform:uppercase; letter-spacing:1px;">Category</th>
                <th style="padding:10px 14px; text-align:left; color:#A8A49C; font-size:11px; text-transform:uppercase; letter-spacing:1px;">Match / Event</th>
                <th style="padding:10px 14px; text-align:left; color:#A8A49C; font-size:11px; text-transform:uppercase; letter-spacing:1px;">Fee</th>
              </tr>
            </thead>
            <tbody>
              {$evtRows}
            </tbody>
          </table>

          <p style="color:#A8A49C;font-size:13px;line-height:1.7;margin:0 0 16px;">
            Please carry a printed or digital copy of this email along with a valid photo ID and your Aadhaar card on the day of the event.
          </p>

          <div style="background:rgba(39,174,96,0.08);border:1px solid rgba(39,174,96,0.3);border-radius:8px;padding:16px;margin:0 0 24px;">
            <p style="margin:0;color:#5EDD8E;font-size:13px;line-height:1.6;">
              ✅ Payment of <strong>{$total}</strong> has been verified and confirmed by the TARGET (Tournament Administration and Registration Gateway for Event Tracking) administration.
            </p>
          </div>

          <p style="color:#6B6762;font-size:12px;margin:0;">
            For any queries, contact us at the SSA office. We look forward to your participation!
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#0A0C10;padding:20px 40px;text-align:center;border-top:1px solid rgba(255,255,255,0.05);">
          <p style="margin:0;color:#6B6762;font-size:11px;">
            TARGET (Tournament Administration and Registration Gateway for Event Tracking) &nbsp;|&nbsp; {$activeTitle}<br>
            This is an automated email. Please do not reply.
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * getPaymentSubmittedEmailBody() – HTML email template for event registration & payment proof upload
 */
function getPaymentSubmittedEmailBody(string $name, string $sessionId, float $totalAmount): string {
    $total = '₹' . number_format($totalAmount, 2);
    $activeTitle = htmlspecialchars(getActiveChampionship()['championship_name'] ?? 'State Shooting Championship');
    return <<<HTML
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
          <h1 style="margin:0;color:#0D0F14;font-size:22px;font-weight:800;letter-spacing:1px;">PAYMENT PROOF RECEIVED</h1>
          <p style="margin:8px 0 0;color:#0D0F14;font-size:13px;opacity:0.8;">{$activeTitle}</p>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:36px 40px;">
          <p style="color:#A8A49C;font-size:14px;margin:0 0 20px;">Dear <strong style="color:#E9ECEF;">{$name}</strong>,</p>
          <p style="color:#A8A49C;font-size:14px;margin:0 0 24px;line-height:1.7;">
            We have received your event registration submission and payment proof for the <strong style="color:#F0EDE6;">{$activeTitle}</strong>.
          </p>

          <!-- Session Info -->
          <div style="background:rgba(255, 255, 255,0.08);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:16px;text-align:center;margin:0 0 24px;">
            <div style="color:#A8A49C;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Session ID</div>
            <div style="color:#E9ECEF;font-size:20px;font-weight:800;letter-spacing:1px;margin-bottom:10px;">{$sessionId}</div>
            <div style="color:#A8A49C;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Total Paid (Screenshot)</div>
            <div style="color:#E9ECEF;font-size:24px;font-weight:800;">{$total}</div>
          </div>

          <div style="background:rgba(241,196,15,0.08);border:1px solid rgba(241,196,15,0.3);border-radius:8px;padding:16px;margin:0 0 24px;">
            <p style="margin:0;color:#F1C40F;font-size:13px;line-height:1.6;">
              ⏳ Your payment screenshot is under verification by the TARGET (Tournament Administration and Registration Gateway for Event Tracking) administration. You will receive a separate confirmation email with your official Registration ID once your payment is verified.
            </p>
          </div>

          <p style="color:#6B6762;font-size:12px;margin:0;">
            For any queries or concerns, please contact the SSA office.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#0A0C10;padding:20px 40px;text-align:center;border-top:1px solid rgba(255,255,255,0.05);">
          <p style="margin:0;color:#6B6762;font-size:11px;">
            TARGET (Tournament Administration and Registration Gateway for Event Tracking) &nbsp;|&nbsp; {$activeTitle}<br>
            This is an automated email. Please do not reply.
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}


/**
 * getRejectionEmailBody() – HTML email template for event rejection
 */
function getRejectionEmailBody(string $name, string $sessionId, string $remarks): string {
    $reason = !empty(trim($remarks)) ? htmlspecialchars(trim($remarks)) : 'Your payment could not be verified. Please check the details and try again, or contact the administration.';
    $activeTitle = htmlspecialchars(getActiveChampionship()['championship_name'] ?? 'State Shooting Championship');
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#08090C;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#08090C;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#0D0F14;border:1px solid rgba(255, 255, 255,0.3);border-radius:12px;overflow:hidden;">

        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#E74C3C,#C0392B);padding:30px 40px;text-align:center;">
          <div style="font-size:36px;margin-bottom:8px;">❌</div>
          <h1 style="margin:0;color:#FFF;font-size:22px;font-weight:800;letter-spacing:1px;">REGISTRATION REJECTED</h1>
          <p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:13px;">{$activeTitle}</p>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:36px 40px;">
          <p style="color:#A8A49C;font-size:14px;margin:0 0 20px;">Dear <strong style="color:#E9ECEF;">{$name}</strong>,</p>
          <p style="color:#A8A49C;font-size:14px;margin:0 0 24px;line-height:1.7;">
            We regret to inform you that your event registration session (<strong style="color:#F0EDE6;">{$sessionId}</strong>) has been <strong style="color:#E74C3C;">REJECTED</strong>.
          </p>

          <div style="background:rgba(231,76,60,0.08);border:1px solid rgba(231,76,60,0.3);border-radius:8px;padding:16px;margin:0 0 24px;">
            <p style="margin:0;color:#E74C3C;font-size:13px;line-height:1.6;font-weight:600;">
              Reason for Rejection:
            </p>
            <p style="margin:8px 0 0;color:#A8A49C;font-size:13px;line-height:1.6;">
              {$reason}
            </p>
          </div>

          <p style="color:#6B6762;font-size:12px;margin:0;">
            If you believe this was an error, please contact the SSA office or try registering again with the correct payment proof.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#0A0C10;padding:20px 40px;text-align:center;border-top:1px solid rgba(255,255,255,0.05);">
          <p style="margin:0;color:#6B6762;font-size:11px;">
            TARGET (Tournament Administration and Registration Gateway for Event Tracking) &nbsp;|&nbsp; {$activeTitle}<br>
            This is an automated email. Please do not reply.
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}