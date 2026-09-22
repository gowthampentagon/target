<?php
/**
 * login.php – Login Page
 * TARGET – Tournament Administration and Registration Gateway for Event Tracking
 */
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Prevent this page from being cached so back-button never serves a stale copy
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// Already logged in as participant → bounce to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Already logged in as admin → bounce to admin panel
if (!empty($_SESSION['admin_id'])) {
    header('Location: admin/index.php');
    exit;
}

$activeChampionship = getActiveChampionship();
$pageTitle = 'Login';
$pageDesc  = 'Login to the ' . ($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') . ' portal.';
$bodyClass = 'login-page';   // Enables all .login-page CSS rules
require_once 'includes/header.php';
?>

  <!-- LOGIN CARD -->
  <main>
    <div style="max-width: 440px; margin: 0 auto 12px; text-align: left;">
      <a href="index.php" style="color: var(--gold-400, #ADB5BD); text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s;">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
          <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
        </svg>
        Return to Main Portal
      </a>
    </div>
    <div class="auth-card" role="main">
      <div class="card-title">Portal Login</div>
      <p class="card-subtitle">Sign in to access your dashboard</p>

      <!-- Message Box -->
      <div class="msg-box" id="msgBox" role="alert" aria-live="polite">
        <?php if (isset($_GET['error']) && $_GET['error'] === 'passcode_expired'): ?>
          <div style="color: #e74c3c; font-size:13px; text-align:center; padding: 10px; border: 1px solid rgba(231,76,60,0.2); background:rgba(231,76,60,0.08); border-radius:6px; margin-bottom:15px;">
            Access Denied: Passcode access is not active or has expired.
          </div>
        <?php endif; ?>
      </div>

      <form id="loginForm" novalidate autocomplete="off">
        <!-- CSRF Token -->
        <?php
          if (empty($_SESSION['csrf_token'])) {
              $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
          }
        ?>
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <!-- Email / Username -->
        <div class="form-group" style="margin-bottom:18px;">
          <label for="email">Registered Email <span class="req">*</span></label>
          <div class="input-wrap">
            <!-- Email icon -->
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
            <input
              type="email"
              id="email"
              name="email"
              placeholder="yourname@email.com"
              autocomplete="email"
              data-rules="required|email"
              maxlength="180"
            >
          </div>
          <span class="field-error"></span>
        </div>

        <!-- Password -->
        <div class="form-group" style="margin-bottom:8px;">
          <label for="password">Password <span class="req">*</span></label>
          <div class="input-wrap">
            <!-- Lock icon -->
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Enter your password"
              autocomplete="current-password"
              data-rules="required|minLen:6"
              maxlength="100"
            >
            <button type="button" class="toggle-pw" aria-label="Toggle password visibility" title="Show/Hide password">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/>
                <path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
              </svg>
            </button>
          </div>
          <span class="field-error"></span>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn btn-primary" id="loginBtn" style="margin-top:24px;">
          <div class="spinner"></div>
          <span class="btn-label">Sign In</span>
        </button>

        <!-- Links row -->
        <div class="link-row" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
          <a href="register.php" id="registerLink">
            &#43; New Registration
          </a>
          <a href="forgot_password.php" id="forgotLink">
            Forgot Password?
          </a>
        </div>
      </form>
    </div>
  </main>

<?php require_once 'includes/footer.php'; ?>

<script>
// Remove the login page from browser history so back-button
// from dashboard never returns here while logged in.
// If the user was redirected here after login (i.e. URL has no
// login params and we are just loading the empty form), we replace
// the current history entry with itself — this is a no-op.
// The real guard is on the dashboard/admin side via Cache-Control: no-store.
(function() {
  // When a successful login JS redirect happens, the login action
  // will call window.location.replace() which already skips history.
  // This listener ensures any programmatic navigation away from this
  // page also uses replace instead of push.
  window.addEventListener('ssa:login-success', function(e) {
    var dest = e.detail && e.detail.redirect ? e.detail.redirect : 'dashboard.php';
    window.location.replace(dest);
  });
})();
</script>
