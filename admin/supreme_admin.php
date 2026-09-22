<?php
/**
 * admin/supreme_admin.php
 * Dedicated Supreme Admin Console
 * Saragarhi Shooting Academy
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin privileges

$pageTitle = 'Supreme Admin Console';
require_once 'includes/header.php';

$pdo = getDB();
$activeChampionship = getActiveChampionship($pdo);

// Fetch Active Championship Statistics
try {
    $cid = (int)($activeChampionship['id'] ?? 1);
    $statsStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM registration_sessions WHERE championship_id = ?) AS total_sessions,
            (SELECT COUNT(*) FROM event_registrations WHERE championship_id = ? AND status = 'approved') AS approved_events,
            (SELECT COUNT(*) FROM event_registrations WHERE championship_id = ? AND status = 'pending') AS pending_events,
            (SELECT COUNT(DISTINCT user_id) FROM event_registrations WHERE championship_id = ?) AS total_participants
    ");
    $statsStmt->execute([$cid, $cid, $cid, $cid]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $totalReg    = $stats['total_sessions']     ?? 0;
    $activeReg   = $stats['approved_events']    ?? 0;
    $pendingReg  = $stats['pending_events']     ?? 0;
    $verifiedReg = $stats['total_participants']  ?? 0;
} catch (Exception $e) {
    $totalReg = $activeReg = $pendingReg = $verifiedReg = 0;
}

// Fetch total admin count
try {
    $totalAdmins = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() ?: 0;
} catch (Exception $e) {
    $totalAdmins = 0;
}

// Fetch active temporary passcodes count
try {
    $activePasscodes = $pdo->query("SELECT COUNT(*) FROM admin_passcodes WHERE expires_at > NOW()")->fetchColumn() ?: 0;
} catch (Exception $e) {
    $activePasscodes = 0;
}
// Fetch all championships for dynamic switcher
$allChampionships = [];
try {
    $allChampionships = $pdo->query("SELECT * FROM championships ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>



<div class="championship-band"><?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?> &bull; TARGET (Tournament Administration and Registration Gateway for Event Tracking) &bull; Chennai</div>

<!-- Page Header -->
<div class="admin-page-header" style="margin-bottom: 24px;">
  <div class="admin-page-title" style="display:flex; align-items:center; gap:10px;">
    <span>Supreme Admin Console</span>
    <span class="user-id-badge" style="font-size:11px; vertical-align:middle;">SUPREME ACCESS</span>
  </div>
</div>

<!-- Stats Grid -->
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
      <h4>Active Athletes</h4>
      <div class="val"><?= number_format((float)$activeReg) ?></div>
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

  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-person-gear"></i>
    </div>
    <div class="stat-info">
      <h4>Admin Accounts</h4>
      <div class="val"><?= number_format((float)$totalAdmins) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="font-size:22px; color:var(--text-secondary); background:rgba(255,255,255,0.05);">
      <i class="bi bi-key-fill"></i>
    </div>
    <div class="stat-info">
      <h4>Active Passcodes</h4>
      <div class="val"><?= number_format((float)$activePasscodes) ?></div>
    </div>
  </div>
</div>

<!-- Active Championship Controller Hero Card -->
<div style="background: linear-gradient(135deg, rgba(20,20,30,0.95) 0%, rgba(32,28,18,0.95) 100%); border: 1.5px solid rgba(212,175,55,0.45); border-radius: 14px; padding: 24px; margin-bottom: 36px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <span class="user-id-badge" style="font-size:11px; background:var(--gold-400); color:#000; font-weight:800;">
        <i class="bi bi-star-fill"></i> ACTIVE SITE CHAMPIONSHIP VARIABLE
      </span>
      <h2 style="font-family:'Rajdhani',sans-serif; font-size: 24px; font-weight:700; color:#fff; margin:10px 0 6px;">
        <?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?>
      </h2>
      <div style="font-size:13px; color:rgba(255,255,255,0.75); display:flex; gap:20px; flex-wrap:wrap; margin-top:8px;">
        <span><i class="bi bi-calendar-event" style="color:var(--gold-400);"></i> Year: <strong><?= htmlspecialchars($activeChampionship['championship_year'] ?? '2026') ?></strong></span>
        <span><i class="bi bi-geo-alt-fill" style="color:var(--gold-400);"></i> Venue: <strong><?= htmlspecialchars($activeChampionship['venue'] ?? 'Chennai') ?></strong></span>
        <span><i class="bi bi-clock-history" style="color:var(--gold-400);"></i> Reg Window: <strong><?= htmlspecialchars($activeChampionship['registration_open'] ?? 'N/A') ?> to <?= htmlspecialchars($activeChampionship['registration_close'] ?? 'N/A') ?></strong></span>
      </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <?php if (!empty($allChampionships)): ?>
        <select id="heroChampSwitcher" class="form-select" onchange="switchActiveChampionship(this.value)" style="background:rgba(0,0,0,0.6); color:#fff; border:1px solid var(--gold-400); padding:10px 14px; border-radius:8px; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:13px; outline:none; cursor:pointer;">
          <?php foreach ($allChampionships as $ch): ?>
            <option value="<?= $ch['id'] ?>" <?= ((int)$ch['id'] === (int)$activeChampionship['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($ch['championship_name']) ?> (<?= htmlspecialchars($ch['championship_year']) ?>) <?= $ch['status'] === 'ACTIVE' ? '★ ACTIVE' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <a href="championship_manager.php" class="btn" style="font-size:12px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; padding:10px 18px; background:linear-gradient(135deg, #d4af37 0%, #aa882c 100%); color:#000; font-weight:800; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
        <i class="bi bi-gear-fill"></i> Championship Manager
      </a>
    </div>
  </div>
</div>

<!-- Console Menu Section -->
<div style="margin-top: 10px; margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div>
    <h2 style="font-size:18px;font-weight:700;color:rgba(244, 240, 240, 0.9);margin:0;letter-spacing:0.3px;">
      Supreme Admin Console Menu
    </h2>
    <p style="font-size:12px;color:rgba(253, 248, 248, 0.7);margin:6px 0 0 0;">Unrestricted access to all management controls and system modules</p>
  </div>
</div>

<!-- Supreme Admin Tile Grid -->
<div class="superadmin-tile-grid">
  <a href="championship_manager.php" class="superadmin-tile" style="border-color:var(--gold-400);">
    <div class="superadmin-tile-icon"><i class="bi bi-trophy-fill" style="color:var(--gold-400);"></i></div>
    <div class="superadmin-tile-label">Championship Manager</div>
    <div class="superadmin-tile-sub">Multi-championship &amp; cloning</div>
  </a>

  <a href="championship_archives.php" class="superadmin-tile" style="border-color:rgba(155,89,182,0.5);">
    <div class="superadmin-tile-icon"><i class="bi bi-box-seam-fill" style="color:#9b59b6;"></i></div>
    <div class="superadmin-tile-label">Championship Archives</div>
    <div class="superadmin-tile-sub">Ended championship backups</div>
  </a>

  <a href="manage_admins.php" class="superadmin-tile">
    <div class="superadmin-tile-icon"><i class="bi bi-shield-fill"></i></div>
    <div class="superadmin-tile-label">Manage Admins</div>
    <div class="superadmin-tile-sub">Control access &amp; accounts</div>
  </a>

  <a href="control_panel.php" class="superadmin-tile">
    <div class="superadmin-tile-icon"><i class="bi bi-toggles"></i></div>
    <div class="superadmin-tile-label">Control Panel</div>
    <div class="superadmin-tile-sub">Fields &amp; passcode access</div>
  </a>

  <a href="events.php" class="superadmin-tile">
    <div class="superadmin-tile-icon"><i class="bi bi-trophy-fill"></i></div>
    <div class="superadmin-tile-label">Events</div>
    <div class="superadmin-tile-sub">Manage &amp; import events</div>
  </a>

  <a href="age_categories.php" class="superadmin-tile">
    <div class="superadmin-tile-icon"><i class="bi bi-person-bounding-box"></i></div>
    <div class="superadmin-tile-label">Age Categories</div>
    <div class="superadmin-tile-sub">Category eligibility rules</div>
  </a>

  <a href="clubs.php" class="superadmin-tile">
    <div class="superadmin-tile-icon"><i class="bi bi-building-fill"></i></div>
    <div class="superadmin-tile-label">Clubs</div>
    <div class="superadmin-tile-sub">Manage shooting clubs</div>
  </a>
</div>

<script>
async function switchActiveChampionship(cid) {
    if (!cid) return;
    try {
        const fd = new FormData();
        fd.append('action', 'switch_active');
        fd.append('id', cid);
        const resp = await fetch('actions/championship_action.php', { method: 'POST', body: fd });
        const res = await resp.json();
        if (res.success) {
            window.location.reload();
        } else {
            alert(res.message || 'Failed to switch active championship.');
        }
    } catch (e) {
        alert('Network error while switching active championship.');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
