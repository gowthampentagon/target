<?php
/**
 * admin/index.php
 * Admin Dashboard Overview
 */
require_once dirname(__DIR__) . '/config/db.php';
$pageTitle = 'Dashboard Overview';
require_once 'includes/header.php';

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);
    
    // Stats strictly scoped to currently ACTIVE championship
    $statsStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM registration_sessions WHERE championship_id = ?) AS total_sessions,
            (SELECT COUNT(*) FROM event_registrations WHERE championship_id = ? AND status = 'approved') AS approved_events,
            (SELECT COUNT(*) FROM event_registrations WHERE championship_id = ? AND status = 'pending') AS pending_events,
            (SELECT COUNT(DISTINCT user_id) FROM event_registrations WHERE championship_id = ?) AS total_participants
    ");
    $statsStmt->execute([$cid, $cid, $cid, $cid]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $totalReg   = $stats['total_sessions']     ?? 0;
    $activeReg  = $stats['approved_events']    ?? 0;
    $pendingReg = $stats['pending_events']     ?? 0;
    $verifiedReg= $stats['total_participants']  ?? 0;
} catch (Exception $e) {
    $totalReg = $activeReg = $pendingReg = $verifiedReg = 0;
}
?>



<!-- SSA Photo Strip -->
<div class="admin-photo-strip">
  <div class="astrip-img ai-1"><span class="astrip-label">TARGET</span></div>
  <div class="astrip-img ai-2"><span class="astrip-label">Inauguration</span></div>
  <div class="astrip-img ai-3"><span class="astrip-label">Reception</span></div>
  <div class="astrip-img ai-4"><span class="astrip-label">Shooting Range</span></div>
</div>

<!-- Championship Band -->
<div class="championship-band"><?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?> &bull; TARGET (Tournament Administration and Registration Gateway for Event Tracking) &bull; <?= htmlspecialchars($activeChampionship['venue'] ?? 'Chennai') ?></div>

<?php if (isset($_GET['error']) && $_GET['error'] === 'no_permission'): ?>
  <div style="color: #e74c3c; font-size:13.5px; text-align:center; padding: 12px; border: 1.5px solid rgba(231,76,60,0.25); background:rgba(231,76,60,0.08); border-radius:10px; margin: 20px 0; font-weight: 500;">
    Access Denied: You do not have permission to access the requested module.
  </div>
<?php endif; ?>

<?php if (!isSuperAdmin()): ?>
<div class="admin-page-header">
  <div class="admin-page-title">Dashboard Overview</div>
</div>
<?php endif; ?>

<div class="dash-grid" style="margin-bottom: 40px;">
  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-people-fill"></i>
    </div>
    <div class="stat-info">
      <h4>Total Registrations</h4>
      <div class="val"><?= number_format((float)$totalReg) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-person-check-fill"></i>
    </div>
    <div class="stat-info">
      <h4>Active</h4>
      <div class="val"><?= number_format((float)$activeReg) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-person-x-fill"></i>
    </div>
    <div class="stat-info">
      <h4>Inactive</h4>
      <div class="val"><?= number_format((float)$pendingReg) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-shield-fill-check"></i>
    </div>
    <div class="stat-info">
      <h4>Verified Profiles</h4>
      <div class="val"><?= number_format((float)$verifiedReg) ?></div>
    </div>
  </div>
</div>

<?php
// Expiration text/pill for standard admin
$expiryInfo = '';
if (!isSuperAdmin() && !empty($_SESSION['admin_id'])) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT expires_at FROM admin_passcodes WHERE admin_id = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $exp = $stmt->fetchColumn();
        if ($exp) {
            $expiryInfo = 'Passcode active until ' . date('d M Y H:i', strtotime($exp));
        }
    } catch (Exception $e) {}
}
?>

<div style="margin-top: 10px; margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div>
    <h2 style="font-size:18px;font-weight:700;color:rgba(244, 240, 240, 0.9);margin:0;letter-spacing:0.3px;">
      <?= isSupremeAdmin() ? 'Supreme Admin Console Menu' : (isSuperAdmin() ? 'Superadmin Console Menu' : 'Admin Console Menu') ?>
    </h2>
    <p style="font-size:12px;color:rgba(253, 248, 248, 0.7);margin:6px 0 0 0;">Quick access to all management sections</p>
  </div>
  <?php if (!empty($expiryInfo)): ?>
    <span class="user-greeting-pill" style="font-size:11px; border-color:var(--gold-400); color:var(--gold-300);">
      <i class="bi bi-key-fill"></i> <?= htmlspecialchars($expiryInfo) ?>
    </span>
  <?php endif; ?>
</div>

<?php
// Helper function to render tiles with active / locked states
if (!function_exists('renderAdminTile')) {
    function renderAdminTile(string $url, string $icon, string $label, string $subText) {
        $isSuper = isSuperAdmin();
        $isAllowed = $isSuper || (!empty($_SESSION['allowed_modules']) && in_array($url, $_SESSION['allowed_modules'], true));
        
        // Pages that are ONLY for superadmins
        $superOnlyPages = ['manage_admins.php', 'control_panel.php', 'supreme_admin.php', 'events.php', 'age_categories.php', 'championship_manager.php', 'championship_archives.php'];
        
        if (in_array($url, $superOnlyPages, true) && !$isSuper) {
            // Hide superadmin pages from standard admins completely
            return;
        }
        
        if ($isAllowed) {
            echo '
            <a href="' . $url . '" class="superadmin-tile">
              <div class="superadmin-tile-icon">' . $icon . '</div>
              <div class="superadmin-tile-label">' . $label . '</div>
              <div class="superadmin-tile-sub">' . $subText . '</div>
            </a>';
        } else {
            // Locked state – hide completely as requested
            return;
        }
    }
}
?>

<div class="superadmin-tile-grid">
  <?php
  if (isSupremeAdmin()) {
      renderAdminTile('supreme_admin.php', '<i class="bi bi-shield-lock-fill"></i>', 'Supreme Portal', 'Master Control');
      renderAdminTile('championship_manager.php', '<i class="bi bi-trophy-fill"></i>', 'Championship Manager', 'Multi-championship');
      renderAdminTile('championship_archives.php', '<i class="bi bi-box-seam-fill"></i>', 'Championship Archives', 'Backup data vault');
  }
  renderAdminTile('registrations.php', '<i class="bi bi-people-fill"></i>', 'Registrations', 'Manage profiles');
  renderAdminTile('event_registrations.php', '<i class="bi bi-crosshair"></i>', 'Event Registrations', 'Approve events');
  renderAdminTile('payment_sessions.php', '<i class="bi bi-cash-stack"></i>', 'Payments', 'Verify receipts');
  renderAdminTile('lane_allocations.php', '<i class="bi bi-calendar3"></i>', 'Lane Allocations', 'Schedule relay');
  renderAdminTile('start_sheet.php', '<i class="bi bi-file-earmark-text-fill"></i>', 'Start Lists', 'Print logs');
  renderAdminTile('team_events.php', '<i class="bi bi-diagram-3-fill"></i>', 'Team Events', 'Manage teams');
  renderAdminTile('rank_list.php', '<i class="bi bi-trophy-fill"></i>', 'Rank List', 'Leaderboard');
  renderAdminTile('../ssa-dashboard/manage.php', '<i class="bi bi-newspaper"></i>', 'Landing News/Events', 'Edit sections');
  renderAdminTile('manage_updates.php', '<i class="bi bi-megaphone-fill"></i>', 'Updates & Results', 'Post files & notices');
  renderAdminTile('manage_admins.php', '<i class="bi bi-shield-fill"></i>', 'Manage Admins', 'Control access');
  renderAdminTile('profile_change_requests.php', '<i class="bi bi-wrench-adjustable-circle-fill"></i>', 'Support Requests', 'Profile edits');
  renderAdminTile('clubs.php', '<i class="bi bi-building-fill"></i>', 'Clubs', 'Manage clubs &amp; sub');
  renderAdminTile('document_editor.php', '<i class="bi bi-palette-fill"></i>', 'Document Designer', 'Edit templates');
  renderAdminTile('control_panel.php', '<i class="bi bi-toggles"></i>', 'Control Panel', 'Fields &amp; access');
  ?>
</div>



<?php require_once 'includes/footer.php'; ?>
