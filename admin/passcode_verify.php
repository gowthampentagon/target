<?php
/**
 * admin/passcode_verify.php
 * Prompt admin for temporary access passcode.
 */
require_once dirname(__DIR__) . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id']) || $_SESSION['admin_role'] === 'superadmin') {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php';
// Sanitize the redirect target to prevent open redirect vulnerabilities
if (strpos($redirect, '/') !== false || strpos($redirect, '\\') !== false || strpos($redirect, ':') !== false) {
    $redirect = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passcode = trim($_POST['passcode'] ?? '');
    
    if (empty($passcode)) {
        $error = 'Please enter the passcode.';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("
                SELECT id, allowed_modules, allow_edit FROM admin_passcodes 
                WHERE admin_id = ? AND passcode = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$_SESSION['admin_id'], $passcode]);
            $row = $stmt->fetch();
            
            if ($row !== false) {
                $_SESSION['passcode_verified'] = true;
                $_SESSION['passcode_id'] = (int)$row['id'];
                $_SESSION['allowed_modules'] = explode(',', (string)$row['allowed_modules']);
                $_SESSION['allow_edit'] = (int)$row['allow_edit'];
                
                // If they had access to the redirect page, send them there, otherwise to index.php
                if ($redirect !== 'index.php' && in_array($redirect, $_SESSION['allowed_modules'], true)) {
                    header('Location: ' . $redirect);
                } else {
                    header('Location: index.php');
                }
                exit;
            } else {
                $error = 'Invalid or expired passcode. Access denied.';
            }
        } catch (Exception $e) {
            $error = 'Database error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Passcode Verification - TARGET Portal</title>
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Rajdhani:wght@600;700&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --dark-950: #05060a;
      --dark-900: #08090c;
      --dark-800: #0d0f14;
      --dark-700: #14171f;
      --gold-400: #f5f6f8;
      --gold-500: #343a40;
      --text-primary: #f5f6f8;
      --text-secondary: rgba(245, 246, 248, 0.7);
      --border-gold: 1.5px solid rgba(255, 255, 255, 0.12);
    }
    body {
      margin: 0;
      padding: 0;
      background: var(--dark-950);
      color: var(--text-primary);
      font-family: 'Outfit', sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background-image: radial-gradient(circle at center, rgba(255, 255, 255, 0.04) 0%, transparent 70%);
    }
    .verify-box {
      background: linear-gradient(135deg, rgba(16, 20, 30, 0.92) 0%, rgba(8, 10, 15, 0.88) 100%);
      border: var(--border-gold);
      border-radius: 16px;
      padding: 40px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      text-align: center;
      box-sizing: border-box;
    }
    h2 {
      font-family: 'Cinzel', serif;
      color: var(--gold-400);
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 22px;
      letter-spacing: 0.5px;
    }
    p {
      color: var(--text-secondary);
      font-size: 13px;
      margin-bottom: 24px;
      line-height: 1.5;
    }
    .input-group {
      margin-bottom: 20px;
      text-align: left;
    }
    label {
      display: block;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--gold-400);
      margin-bottom: 8px;
      font-weight: 600;
    }
    input {
      width: 100%;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 8px;
      padding: 12px 16px;
      font-size: 16px;
      color: var(--text-primary);
      box-sizing: border-box;
      outline: none;
      text-align: center;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      transition: all 0.2s;
    }
    input:focus {
      border-color: var(--text-primary);
      background: rgba(255, 255, 255, 0.06);
    }
    .btn {
      width: 100%;
      background: var(--gold-500);
      color: var(--text-primary);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 8px;
      padding: 12px;
      font-size: 14px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 1px;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn:hover {
      background: var(--gold-400);
      transform: translateY(-2px);
    }
    .error-msg {
      background: rgba(231, 76, 60, 0.08);
      border: 1px solid rgba(231, 76, 60, 0.2);
      color: #e74c3c;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 12px;
      margin-bottom: 20px;
      text-align: center;
    }
    .back-link {
      display: inline-block;
      margin-top: 16px;
      font-size: 12px;
      color: var(--text-secondary);
      text-decoration: none;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .back-link:hover {
      color: var(--gold-400);
    }
  </style>
</head>
<body>
  <div class="verify-box">
    <h2>Passcode Required</h2>
    <p>Your admin account is configured for temporary passcode access. Please enter the valid passcode to continue.</p>
    
    <?php if (!empty($error)): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" action="passcode_verify.php">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
      <div class="input-group">
        <label for="passcode">Access Passcode</label>
        <input type="text" id="passcode" name="passcode" maxlength="20" required autocomplete="off" autofocus>
      </div>
      <button type="submit" class="btn">Verify Access</button>
    </form>
    
    <a href="../logout.php" class="back-link">Logout &amp; Exit</a>
  </div>
  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
