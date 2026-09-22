<?php
/**
 * forgot_password.php – Password Recovery
 * Step 1: Enter registered email → generate token
 * Step 2: Enter token + new password → reset
 */
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeChampionship = getActiveChampionship();
$pageTitle = 'Forgot Password';
$pageDesc  = 'Reset your password for the ' . ($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') . ' portal.';
require_once 'includes/header.php';
?>

  <main>
    <div class="auth-card" role="main">
      <div class="card-title">Password Recovery</div>
      <p class="card-subtitle">Recover access to your registration account</p>

      <!-- Step Indicator -->
      <div class="step-indicator" role="list" aria-label="Steps">
        <div class="step active" role="listitem">
          <div class="step-circle">1</div>
          <div class="step-label">Verify Email</div>
        </div>
        <div class="connector"></div>
        <div class="step" role="listitem">
          <div class="step-circle">2</div>
          <div class="step-label">Reset Password</div>
        </div>
      </div>

      <!-- Message Box -->
      <div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

      <!-- ── Step 1: Email + Token Request ─────────────────── -->
      <form id="fpStep1" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="step" value="1">

        <div class="form-group" style="margin-bottom:22px;">
          <label for="fp_email">Registered Email <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <input type="email" id="fp_email" name="email" placeholder="Enter your registered email" data-rules="required|email" maxlength="180" autocomplete="email">
          </div>
          <span class="field-error"></span>
        </div>

        <button type="submit" class="btn btn-primary">
          <div class="spinner"></div>
          <span class="btn-label">Send Reset Token</span>
        </button>

        <div class="link-row" style="justify-content:center;margin-top:18px;">
          <a href="login.php">&#8592; Back to Login</a>
        </div>
      </form>

      <!-- ── Step 2: Token + New Password ──────────────────── -->
      <form id="fpStep2" novalidate style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="step" value="2">

        <!-- Email (Auto-fetched & Read-only) -->
        <div class="form-group" style="margin-bottom:18px;">
          <label for="fp_email_step2">Email Address</label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <input type="email" id="fp_email_step2" name="email" readonly style="background:rgba(255,255,255,0.05);color:var(--text-muted);cursor:not-allowed;">
          </div>
        </div>

        <!-- Token -->
        <div class="form-group" style="margin-bottom:18px;">
          <label for="fp_token">Reset Token <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-1 4-4 1-2 2 5 5 2-2 1-4z"/><path d="M3.9 20.1L9 15l-4-4-5.1 5.1a2 2 0 0 0 0 2.83l.17.17a2 2 0 0 0 2.83 0z"/></svg>
            <input type="text" id="fp_token" name="token" placeholder="6-character token" data-rules="required|minLen:6" maxlength="6" autocomplete="one-time-code" style="letter-spacing:4px;font-weight:700;font-size:18px;text-transform:uppercase;">
          </div>
          <span class="field-error"></span>
          <small style="color:var(--text-muted);font-size:11.5px;margin-top:4px;display:block;">
            Check your registered email for the 6-character token.
          </small>
        </div>

        <!-- New Password -->
        <div class="form-group" style="margin-bottom:18px;">
          <label for="fp_new_password">New Password <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="fp_new_password" name="new_password" placeholder="Min 8 characters" data-rules="required|minLen:8" maxlength="100" autocomplete="new-password">
            <button type="button" class="toggle-pw" aria-label="Toggle password visibility">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>
            </button>
          </div>
          <div class="pw-strength" aria-live="polite">
            <div class="bar"><div class="fill"></div></div>
            <span class="label-text"></span>
          </div>
          <span class="field-error"></span>
        </div>

        <!-- Confirm Password -->
        <div class="form-group" style="margin-bottom:22px;">
          <label for="fp_confirm_password">Confirm New Password <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="fp_confirm_password" name="confirm_password" placeholder="Re-enter new password" data-rules="required" maxlength="100" autocomplete="new-password">
            <button type="button" class="toggle-pw" aria-label="Toggle confirm password visibility">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>
            </button>
          </div>
          <span class="field-error"></span>
        </div>

        <button type="submit" class="btn btn-primary">
          <div class="spinner"></div>
          <span class="btn-label">Reset Password</span>
        </button>
      </form>
    </div>
  </main>

<?php require_once 'includes/footer.php'; ?>
