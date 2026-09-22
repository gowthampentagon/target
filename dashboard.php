<?php
/**
 * dashboard.php – Participant Dashboard (Sidebar Layout)
 */
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) { header('Location: login.php?expired=1'); exit; }
if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_destroy(); header('Location: login.php?expired=1'); exit;
}
function getAttachmentUrl($path) {
    if (empty($path)) return '';
    $path = str_replace('\\', '/', trim($path));
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    if (strpos($path, '/') === 0) {
        return substr($path, 1);
    }
    return $path;
}

try {
    $pdo  = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $stmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    // Fetch notifications
    $notifCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_admin = 0");
    $notifCount->execute([$_SESSION['user_id']]);
    $unreadCount = (int)$notifCount->fetchColumn();

    $notifList = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_admin = 0 AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
    $notifList->execute([$_SESSION['user_id']]);
    $userNotifs = $notifList->fetchAll();

    $cid = (int)($activeChampionship['id'] ?? 1);
    $evtStmt = $pdo->prepare(
        "SELECT event_reg_id, category, event_name, weapon_type, age_group, best_score, entry_fee, status, match_no
         FROM event_registrations WHERE user_id = ? AND championship_id = ? ORDER BY created_at DESC"
    );
    $evtStmt->execute([$_SESSION['user_id'], $cid]);
    $evtRegs = $evtStmt->fetchAll();

    $sesStmt = $pdo->prepare(
        "SELECT session_id, total_amount, payment_status, approval_status, payment_screenshot, admin_remarks, created_at
         FROM registration_sessions WHERE user_id = ? AND championship_id = ? ORDER BY created_at DESC"
    );
    $sesStmt->execute([$_SESSION['user_id'], $cid]);
    $sessions = $sesStmt->fetchAll();

    $approvedSessionStmt = $pdo->prepare(
        "SELECT id, session_id, approval_status, payment_status, approved_at, created_at
         FROM registration_sessions
         WHERE user_id = ? AND approval_status = 'approved'
         ORDER BY approved_at DESC, created_at DESC LIMIT 1"
    );
    $approvedSessionStmt->execute([$_SESSION['user_id']]);
    $approvedSession = $approvedSessionStmt->fetch();

    $approvedEvtStmt = $pdo->prepare(
        "SELECT event_reg_id, category, event_name, weapon_type, age_group, best_score, entry_fee, status, match_no,
                issf_number, mqs_score, competition_name, shooting_year, certificate_path, created_at
         FROM event_registrations
         WHERE user_id = ? AND status = 'approved'
         ORDER BY created_at DESC"
    );
    $approvedEvtStmt->execute([$_SESSION['user_id']]);
    $approvedEvents = $approvedEvtStmt->fetchAll();

} catch (Exception $e) {
    $user = null; $evtRegs = []; $sessions = [];
}

if (!$user) { session_destroy(); header('Location: login.php?expired=1'); exit; }

// Active section from URL
$section = $_GET['tab'] ?? 'overview';
$allowed = ['overview','events','payments','profile','announcements'];
if (!in_array($section, $allowed)) $section = 'overview';

$profileUpdated = isset($_GET['updated']) && $_GET['updated'] === '1';
$pageTitle = 'Dashboard – ' . $user['first_name'];
$bodyClass = 'participant-dash';

function fmtPhone(?string $p): string {
    if (empty($p)) return '—';
    if (strlen($p) < 10) return htmlspecialchars($p);
    return '+91 ' . substr($p,0,5) . ' ' . substr($p,5);
}
function fmtAadhaar(?string $n): string {
    if (empty($n)) return '—';
    return implode('-', str_split($n, 4));
}
$catLabels = ['ISSF'=>'ISSF Events','NR'=>'NR Events','PARA_DEAF'=>'Para / Deaf','NR_MQS'=>'NR for MQS'];

// Counts for sidebar badges
$evtCount     = count($evtRegs);
$sesCount     = count($sessions);
$pendingSes   = count(array_filter($sessions, fn($s) => $s['approval_status'] === 'pending'));
$approvedSes  = count(array_filter($sessions, fn($s) => $s['approval_status'] === 'approved'));

$approvedCompetitor = !empty($approvedSession);
$approvedCertificateEvent = null;
$issfHistory = [];
foreach ($approvedEvents as $approvedEvent) {
    if ($approvedCertificateEvent === null && !empty($approvedEvent['certificate_path'])) {
        $approvedCertificateEvent = $approvedEvent;
    }
    if (($approvedEvent['category'] ?? '') === 'ISSF') {
        $issfHistory[] = $approvedEvent;
    }
}

// Approved payment total
$approvedTotal = 0;
foreach ($sessions as $s) {
    if ($s['approval_status'] === 'approved') {
        $approvedTotal += (float)$s['total_amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') ?> – Participant Dashboard">
  <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'SSA Championship') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Cinzel:wght@400;600;700&family=Playfair+Display:ital,wght@0,700;1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/participant.css?v=<?php echo time(); ?>">
  <style>
    .btn-doc-view {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-size: 11.5px;
      padding: 7px 15px;
      text-decoration: none !important;
      border: 1px solid rgba(255, 255, 255, 0.35);
      background: rgba(255, 255, 255, 0.08);
      color: #ffffff !important;
      border-radius: 6px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      transition: all 0.2s ease-in-out;
      cursor: pointer;
    }
    .btn-doc-view:hover {
      background: #ffffff !important;
      color: #0d1117 !important;
      border-color: #ffffff !important;
      box-shadow: 0 4px 14px rgba(255, 255, 255, 0.3);
      transform: translateY(-1px);
    }
    .btn-doc-download {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-size: 11.5px;
      padding: 7px 15px;
      text-decoration: none !important;
      border: 1px solid #d4af37;
      background: rgba(212, 175, 55, 0.15);
      color: #ffd700 !important;
      border-radius: 6px;
      box-shadow: 0 2px 8px rgba(212, 175, 55, 0.15);
      transition: all 0.2s ease-in-out;
      cursor: pointer;
    }
    .btn-doc-download:hover {
      background: #d4af37 !important;
      color: #000000 !important;
      border-color: #ffd700 !important;
      box-shadow: 0 4px 14px rgba(212, 175, 55, 0.4);
      transform: translateY(-1px);
    }
    /* Centered content wrappers (No Sidebar/Topbar) */
    body.participant-dash { height: auto !important; max-height: none !important; overflow: visible !important; }
    .ptcp-wrapper { display: block !important; height: auto !important; max-height: none !important; overflow: visible !important; }
    .ptcp-main { margin-left: 0 !important; width: 100% !important; max-width: none !important; padding: 24px 36px 40px 36px !important; box-sizing: border-box; }
    
    /* Top Portal Header Row styling (Floating Card Upgrade) */
    .portal-header-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, rgba(16, 20, 30, 0.95) 0%, rgba(8, 10, 15, 0.98) 100%);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255, 255, 255, 0.18);
      border-radius: 14px;
      padding: 14px 24px;
      margin-bottom: 30px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45);
      flex-wrap: wrap;
      gap: 14px;
      position: sticky;
      top: 0;
      z-index: 1000;
    }
    .portal-brand-title { display: flex; align-items: center; gap: 12px; }
    .portal-emblem-icon {
      color: var(--gold-400);
      background: rgba(255, 255, 255, 0.08);
      padding: 8px;
      border-radius: 50%;
      border: 1.5px solid rgba(255, 255, 255, 0.25);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .brand-text-wrap { display: flex; flex-direction: column; }
    .portal-main-title { font-family: 'Cinzel', serif; font-size: 18px; font-weight: 700; color: var(--gold-400); letter-spacing: 1px; line-height: 1.2; }
    .portal-subtitle { font-size: 10px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    
    .portal-user-meta { display: flex; align-items: center; gap: 16px; }
    .user-greeting-pill {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 13px;
      color: var(--text-primary) !important;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .user-greeting-pill:hover {
      background: rgba(255, 255, 255, 0.08);
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateY(-1px);
    }
    .user-id-badge {
      background: var(--gold-400);
      color: var(--dark-950);
      font-size: 10px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      padding: 2px 8px;
      border-radius: 20px;
      letter-spacing: 0.5px;
    }
    .portal-logout-btn {
      background: linear-gradient(135deg, rgba(231, 76, 60, 0.15) 0%, rgba(192, 57, 43, 0.15) 100%);
      border: 1px solid rgba(231, 76, 60, 0.35);
      border-radius: 30px;
      padding: 8px 16px;
      font-size: 12px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #ff6b6b;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 2px 8px rgba(231, 76, 60, 0.1);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .portal-logout-btn:hover {
      background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
      color: white !important;
      border-color: #e74c3c;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
    }

    /* Premium Back Button styling */
    .btn-back-portal {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-size: 13px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--gold-400);
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 8px;
      padding: 12px 24px;
      text-decoration: none;
      transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .btn-back-portal:hover {
      background: var(--gold-400);
      color: var(--dark-950) !important;
      border-color: var(--gold-400);
      transform: translateX(-4px);
      box-shadow: 0 8px 24px rgba(255, 255, 255, 0.25);
    }

    /* Three BIG Navigation Boxes styling */
    .nav-boxes-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin: 80px auto; max-width: 1000px; }
    .nav-box { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 50px 30px; background: linear-gradient(145deg, rgba(20,24,36,0.95) 0%, rgba(10,12,18,0.98) 100%); border: 1.5px solid rgba(255,255,255,0.08); border-radius: 18px; text-decoration: none; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); box-shadow: 0 10px 30px rgba(0,0,0,0.4); text-align: center; min-height: 220px; }
    .nav-box:hover { transform: translateY(-8px) scale(1.02); border-color: var(--gold-400); box-shadow: 0 15px 35px rgba(255, 255, 255,0.2); background: linear-gradient(145deg, rgba(255, 255, 255,0.05) 0%, rgba(10,12,18,0.98) 100%); }
    .nav-box-icon { font-size: 54px; margin-bottom: 20px; width: 80px; height: 80px; background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.08); transition: all 0.3s ease; }
    .nav-box:hover .nav-box-icon { border-color: var(--gold-400); background: rgba(255, 255, 255,0.1); transform: scale(1.1); }
    .nav-box-content { display: flex; flex-direction: column; gap: 8px; }
    .nav-box-title { font-size: 18px; font-weight: 700; color: white; font-family: 'Rajdhani', sans-serif; text-transform: uppercase; letter-spacing: 1px; }
    .nav-box:hover .nav-box-title { color: var(--gold-400); }
    .nav-box-desc { font-size: 12px; color: var(--text-muted); line-height: 1.4; max-width: 240px; }

    /* Square Tiles Grid styling (Restored) */
    .tile-grid-container { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important; gap: 18px !important; padding: 0 !important; width: 100% !important; margin: 0 !important; }
    .tile-box { display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; gap: 10px !important; padding: 18px 14px !important; background: linear-gradient(145deg, rgba(12,14,22,0.90) 0%, rgba(8,10,16,0.94) 100%) !important; border: 1px solid rgba(255,255,255,0.07) !important; border-radius: 16px !important; aspect-ratio: 1 / 1 !important; text-decoration: none !important; color: var(--text-primary) !important; cursor: pointer !important; position: relative !important; overflow: hidden !important; box-shadow: 0 4px 12px rgba(0,0,0,0.25) !important; font-family: 'Segoe UI', 'Rajdhani', sans-serif !important; width: 100% !important; height: auto !important; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important; }
    .tile-box:hover { transform: translateY(-6px) scale(1.02) !important; border-color: rgba(255, 255, 255,0.6) !important; background: linear-gradient(145deg, rgba(255, 255, 255,0.08) 0%, rgba(8,10,16,0.96) 100%) !important; box-shadow: 0 12px 28px rgba(255, 255, 255,0.18), 0 4px 16px rgba(0,0,0,0.14) !important; }
    .tile-icon { font-size: 42px !important; line-height: 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; width: 60px !important; height: 60px !important; background: linear-gradient(135deg, rgba(255, 255, 255,0.12) 0%, rgba(255, 255, 255,0.06) 100%) !important; border: 1.5px solid rgba(255, 255, 255,0.3) !important; border-radius: 12px !important; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important; font-weight: 500 !important; flex-shrink: 0 !important; }
    .tile-box:hover .tile-icon { background: linear-gradient(135deg, rgba(255, 255, 255,0.25) 0%, rgba(255, 255, 255,0.15) 100%) !important; border-color: rgba(255, 255, 255,0.6) !important; transform: scale(1.15) rotate(5deg) !important; box-shadow: 0 4px 12px rgba(255, 255, 255,0.25) !important; }
    .tile-label { font-size: 12px !important; font-weight: 700 !important; text-align: center !important; color: var(--text-primary) !important; line-height: 1.35 !important; letter-spacing: 0.2px !important; word-break: break-word !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; font-family: 'Segoe UI', 'Rajdhani', sans-serif !important; }
    .tile-box:hover .tile-label { color: var(--gold-300) !important; font-weight: 800 !important; }
    .tile-sub { font-size: 9px !important; color: var(--text-muted) !important; text-align: center !important; text-transform: uppercase !important; letter-spacing: 0.4px !important; font-weight: 600 !important; margin: 0 !important; padding: 0 !important; }
    .tile-box:hover .tile-sub { color: rgba(255, 255, 255,0.95) !important; }

    @media (max-width: 768px) {
      .nav-boxes-grid { grid-template-columns: 1fr; gap: 16px; margin: 40px auto; }
      .nav-box { padding: 30px 20px; min-height: auto; }
      .portal-header-row { justify-content: center; text-align: center; }
      .tile-grid-container { grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)) !important; gap: 12px !important; }
    }
  </style>
</head>
<body class="participant-dash">

<!-- SSA Photo Background -->
<div class="page-bg" aria-hidden="true"></div>
<div class="page-bg-noise" aria-hidden="true"></div>

<!-- ═══════════════════════════════════════════════════ -->
<!--   PARTICIPANT SHELL                                 -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="ptcp-wrapper">

  <!-- Centered Main Branding Header & Logout -->
  <div class="portal-header-row">
    <div class="portal-brand-title">
      <div class="portal-emblem-icon" style="background:transparent; border:none; display:flex; align-items:center;">
        <img src="images/logo.png?v=<?= time() ?>" alt="TARGET Logo" style="width:34px; height:34px; object-fit:contain;">
      </div>
      <div class="brand-text-wrap">
        <span class="portal-main-title">TARGET PORTAL</span>
        <span class="portal-subtitle">TARGET &bull; Tournament Administration and Registration Gateway for Event Tracking &bull; <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></span>
      </div>
    </div>
    <div class="portal-user-meta">
      <a href="?tab=profile" class="user-greeting-pill">
        <?php if (!empty($user['photo'])): ?>
          <img src="<?= htmlspecialchars($user['photo']) ?>" alt="Avatar" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--gold-400);">
        <?php else: ?>
          <span style="font-size: 16px;"><i class="bi bi-person-fill"></i></span>
        <?php endif; ?>
        <strong><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></strong> 
      </a>
      <!-- Notification Icon -->
      <div class="notif-wrapper" style="position:relative; display:inline-block; margin-right:4px;">
        <button class="notif-bell-btn" onclick="toggleNotifDropdown()" style="background:none; border:none; color:var(--text-secondary); font-size:18px; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; padding:8px; border-radius:50%; transition:background 0.2s; outline:none;">
          <i class="bi bi-bell-fill"></i>
          <?php if ($unreadCount > 0): ?>
            <span class="notif-badge" style="position:absolute; top:-2px; right:-2px; background:var(--danger); color:white; font-size:9px; font-weight:700; width:15px; height:15px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 0 6px var(--danger); line-height:1;"><?= $unreadCount ?></span>
          <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown" style="display:none; position:absolute; right:0; top:40px; width:320px; background:var(--dark-900); border:1px solid rgba(255, 255, 255,0.25); border-radius:var(--radius-md); box-shadow:0 10px 30px rgba(0,0,0,0.8); z-index:1100; overflow:hidden;">
          <div style="padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.06); font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--gold-400); text-transform:uppercase; letter-spacing:1px; display:flex; justify-content:space-between; align-items:center;">
            <span>Notifications</span>
            <?php if ($unreadCount > 0): ?>
              <a href="javascript:void(0)" onclick="markAllNotifsRead()" style="font-size:10px; color:var(--text-muted); text-transform:none; font-weight:400; text-decoration:underline;">Mark all read</a>
            <?php endif; ?>
          </div>
          <div style="max-height:280px; overflow-y:auto; font-size:12px;">
            <?php if (empty($userNotifs)): ?>
              <div style="padding:20px; text-align:center; color:var(--text-muted);">No notifications yet.</div>
            <?php else: ?>
              <?php foreach ($userNotifs as $n): ?>
                <a href="javascript:void(0)" onclick="readNotif(<?= $n['id'] ?>, '<?= htmlspecialchars($n['redirect_url']) ?>')" style="display:block; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.04); color:<?= $n['is_read'] ? 'var(--text-muted)' : 'var(--text-primary)' ?>; background:<?= $n['is_read'] ? 'transparent' : 'rgba(255, 255, 255,0.02)' ?>; text-decoration:none; transition:background 0.2s; text-align:left;">
                  <div style="font-weight:<?= $n['is_read'] ? '500' : '700' ?>; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                    <span style="font-weight:700; color:var(--gold-400);"><?= htmlspecialchars($n['title']) ?></span>
                    <span style="font-size:9px; color:var(--text-muted); font-weight:400; white-space:nowrap;"><?= date('d M H:i', strtotime($n['created_at'])) ?></span>
                  </div>
                  <div style="margin-top:4px; font-size:11px; line-height:1.4; color:var(--text-secondary);"><?= htmlspecialchars($n['message']) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <a href="logout.php" class="portal-logout-btn">
        <span>Logout</span>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      </a>
    </div>
  </div>

  <!-- Three Navigation Boxes (Visible only at first) -->
  <?php if (empty($section)): ?>
  <div class="nav-boxes-grid">
    <!-- Box 1: Overview -->
    <a href="dashboard.php?tab=overview" class="nav-box">
      <div class="nav-box-icon"><i class="bi bi-grid-3x3-gap-fill"></i></div>
      <div class="nav-box-content">
        <div class="nav-box-title">Overview</div>
        <div class="nav-box-desc">Stats, registrations overview & quick links</div>
      </div>
    </a>
    <!-- Box 2: Event Registration -->
    <a href="event_register.php" class="nav-box">
      <div class="nav-box-icon"><i class="bi bi-crosshair"></i></div>
      <div class="nav-box-content">
        <div class="nav-box-title">Event Registration</div>
        <div class="nav-box-desc">Register for new ISSF or NR shooting events</div>
      </div>
    </a>
    <!-- Box 3: My Registrations -->
    <a href="dashboard.php?tab=events" class="nav-box">
      <div class="nav-box-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
      <div class="nav-box-content">
        <div class="nav-box-title">My Registrations</div>
        <div class="nav-box-desc">View registered events & approval status</div>
      </div>
    </a>
  </div>
  <?php endif; ?>

  <!-- ──── MAIN CONTENT ──── -->
  <main class="ptcp-main">

    <!-- Back Button to Main Page (Visible when a tab is open) -->
    <?php if (!empty($section) && $section !== 'overview'): ?>
    <div style="margin-bottom:28px;">
      <a href="dashboard.php" class="btn-back-portal">
        ← Back to Dashboard
      </a>
    </div>
    <?php endif; ?>



    <!-- ─────────────────────────────────── -->
    <!--   OVERVIEW TAB                      -->
    <!-- ─────────────────────────────────── -->
    <?php if ($section === 'overview'): ?>

    <div class="ptcp-page-header">
      <div>
        <div class="ptcp-page-title"><?= htmlspecialchars($user['first_name']) ?></div>
        <div class="ptcp-page-sub"><?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?> — Participant Portal</div>
      </div>
    </div>

    <!-- Photo Strip -->
    <div class="ssa-photo-strip" style="margin-bottom:24px;">
      <div class="strip-img strip-img-1"><span class="strip-label">TARGET</span></div>
      <div class="strip-img strip-img-2"><span class="strip-label">Reception &amp; Museum</span></div>
      <div class="strip-img strip-img-3"><span class="strip-label">Shooting Range</span></div>
    </div>

    <!-- Championship Band -->
    <div class="championship-band"><?= htmlspecialchars(($activeChampionship['org_name'] ?? '') === 'Saragarhi Shooting Academy' ? 'TARGET' : ($activeChampionship['org_name'] ?? 'TARGET')) ?> &bull; Tournament Administration and Registration Gateway for Event Tracking &bull; <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></div>

    <!-- Overview Stats -->
    <div class="ptcp-stats-grid">
      <div class="ptcp-stat">
        <div class="ptcp-stat-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
        </div>
        <div class="ptcp-stat-info">
          <div class="ptcp-stat-val"><?= $evtCount ?></div>
          <div class="ptcp-stat-label">Events Registered</div>
        </div>
      </div>
      <div class="ptcp-stat">
        <div class="ptcp-stat-icon" style="color:var(--success);background:rgba(39,174,96,0.12);border-color:rgba(39,174,96,0.2);">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div class="ptcp-stat-info">
          <div class="ptcp-stat-val" style="color:var(--success);"><?= $approvedSes ?></div>
          <div class="ptcp-stat-label">Approved Sessions</div>
        </div>
      </div>
      <div class="ptcp-stat">
        <div class="ptcp-stat-icon" style="color:var(--gold-400);background:rgba(255, 255, 255,0.12);border-color:rgba(255, 255, 255,0.2);">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        </div>
        <div class="ptcp-stat-info">
          <div class="ptcp-stat-val" style="color:var(--gold-400);"><?= $pendingSes ?></div>
          <div class="ptcp-stat-label">Pending Approval</div>
        </div>
      </div>
      <div class="ptcp-stat">
        <div class="ptcp-stat-icon" style="color:var(--info);background:rgba(41,128,185,0.12);border-color:rgba(41,128,185,0.2);">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg>
        </div>
        <div class="ptcp-stat-info">
          <div class="ptcp-stat-val" style="color:var(--info);">₹<?= number_format($approvedTotal, 0) ?></div>
          <div class="ptcp-stat-label">Total Paid</div>
        </div>
      </div>
    </div>

    <!-- Overview Section Title (Restored Grid of 7 Squares) -->
    <div style="margin-bottom:24px; margin-top: 32px;">
      <h2 style="font-size:16px;font-weight:700;color:rgba(244, 240, 240, 0.9);margin:0;letter-spacing:0.3px;">Dashboard Overview</h2>
      <p style="font-size:12px;color:rgba(253, 248, 248, 0.7);margin:6px 0 0 0;">Quick access to your competition journey</p>
    </div>

    <!-- Square Tile Grid (Restored) -->
    <div class="tile-grid-container" style="margin-bottom:40px;">
      <!-- Register for Events Tile -->
      <a href="event_register.php" class="tile-box">
        <div class="tile-icon"><i class="bi bi-plus-lg"></i></div>
        <div class="tile-label">Register for Events</div>
      </a>

      <!-- My Registrations Tile -->
      <a href="?tab=events" class="tile-box">
        <div class="tile-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
        <div class="tile-label">My Registrations</div>
        <div class="tile-sub"><?= $evtCount ?> Event(s)</div>
      </a>

      <!-- Payment Status Tile -->
      <a href="?tab=payments" class="tile-box">
        <div class="tile-icon"><i class="bi bi-cash-stack"></i></div>
        <div class="tile-label">Payment Status</div>
        <div class="tile-sub"><?= $pendingSes ?> Pending</div>
      </a>

      <!-- Edit Profile Tile -->
      <a href="?tab=profile" class="tile-box">
        <div class="tile-icon"><i class="bi bi-pencil-square"></i></div>
        <div class="tile-label">My Profile</div>
      </a>

      <!-- Competitor Card Tile -->
      <a href="competitor_card.php" class="tile-box">
        <div class="tile-icon"><i class="bi bi-person-badge-fill"></i></div>
        <div class="tile-label">Competitor Card</div>
      </a>

      <!-- Athlete History Tile -->
      <a href="athlete_history.php" class="tile-box">
        <div class="tile-icon"><i class="bi bi-award-fill"></i></div>
        <div class="tile-label">Athlete History</div>
        <div class="tile-sub"><?= count($approvedEvents) ?> Item(s)</div>
      </a>

      <!-- Certificate Tile -->
      <a href="certificate.php" class="tile-box">
        <div class="tile-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div class="tile-label">Certificates</div>
      </a>

      <!-- Updates & Results Tile -->
      <a href="?tab=announcements" class="tile-box">
        <div class="tile-icon"><i class="bi bi-megaphone-fill"></i></div>
        <div class="tile-label">Updates &amp; Results</div>
      </a>
    </div>


    <!-- Latest 3 events preview -->
    <?php if (!empty($evtRegs)): ?>
    <div class="ptcp-section-title">Recent Event Registrations</div>
    <div class="ptcp-card" style="margin-bottom:20px;">
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="border-bottom:1px solid rgba(255,255,255,0.07);">
            <th class="ptcp-th">Enrollment ID</th><th class="ptcp-th">Event</th><th class="ptcp-th">Category</th><th class="ptcp-th">Status</th>
          </tr></thead>
          <tbody>
            <?php foreach(array_slice($evtRegs,0,3) as $er): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
              <td class="ptcp-td" style="color:var(--gold-400);font-family:'Rajdhani',sans-serif;font-weight:700;"><?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?></td>
              <td class="ptcp-td"><?= htmlspecialchars($er['event_name']) ?></td>
              <td class="ptcp-td"><span class="ptcp-cat-badge"><?= htmlspecialchars($catLabels[$er['category']]??$er['category']) ?></span></td>
              <td class="ptcp-td"><span class="status-badge <?= $er['status']==='approved'?'approved':($er['status']==='rejected'?'rejected':'pending') ?>" style="font-size:10px;"><?= ucfirst($er['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if($evtCount > 3): ?>
      <div style="padding:12px 16px;border-top:1px solid rgba(255,255,255,0.06);"><a href="?tab=events" style="font-size:12px;color:var(--gold-400);">View all <?= $evtCount ?> registrations →</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ─────────────────────────────────── -->
    <!--   EVENTS TAB                        -->
    <!-- ─────────────────────────────────── -->
    <?php elseif ($section === 'events'): ?>

    <div class="ptcp-page-header">
      <div>
        <div class="ptcp-page-title">My Event Registrations</div>
        <div class="ptcp-page-sub">All events you have registered for in the active championship</div>
      </div>
      <a href="event_register.php" class="btn btn-primary" style="width:auto;padding:10px 20px;font-size:13px;">+ Register New</a>
    </div>

    <?php if (empty($evtRegs)): ?>
    <div class="ptcp-empty">
      <div class="ptcp-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg></div>
      <div>You haven't registered for any events yet.</div>
      <a href="event_register.php" class="btn btn-primary" style="width:auto;margin-top:16px;">Register Now</a>
    </div>
    <?php else: ?>

    <!-- Category groups -->
    <?php
    $grouped = [];
    foreach ($evtRegs as $er) {
        $grouped[$er['category']][] = $er;
    }
    $catColors = [
        'ISSF'      => ['color'=>'#60A8E0','bg'=>'rgba(41,128,185,0.12)','border'=>'rgba(41,128,185,0.25)'],
        'NR'        => ['color'=>'#A3D977','bg'=>'rgba(106,176,76,0.12)','border'=>'rgba(106,176,76,0.25)'],
        'NR_MQS'    => ['color'=>'#F7B731','bg'=>'rgba(247,183,49,0.12)','border'=>'rgba(247,183,49,0.25)'],
        'PARA_DEAF' => ['color'=>'#FC5C7D','bg'=>'rgba(252,92,125,0.12)','border'=>'rgba(252,92,125,0.25)'],
    ];
    foreach ($grouped as $cat => $items):
        $col = $catColors[$cat] ?? ['color'=>'var(--gold-400)','bg'=>'rgba(255, 255, 255,0.1)','border'=>'rgba(255, 255, 255,0.2)'];
    ?>
    <div class="ptcp-card" style="margin-bottom:20px;">
      <!-- Category header -->
      <div class="ptcp-card-header" style="border-left:3px solid <?= $col['color'] ?>;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:<?= $col['color'] ?>;letter-spacing:1px;text-transform:uppercase;"><?= htmlspecialchars($catLabels[$cat]??$cat) ?></span>
          <span style="font-size:11px;background:<?= $col['bg'] ?>;border:1px solid <?= $col['border'] ?>;color:<?= $col['color'] ?>;padding:2px 8px;border-radius:100px;"><?= count($items) ?> event(s)</span>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
            <th class="ptcp-th">Enrollment ID</th>
            <th class="ptcp-th">Event No</th>
            <th class="ptcp-th">Event Name</th>
            <th class="ptcp-th">Weapon</th>
            <th class="ptcp-th">Age Group</th>
            <th class="ptcp-th" style="text-align:right;">Best Score</th>
            <th class="ptcp-th" style="text-align:right;">Entry Fee</th>
            <th class="ptcp-th">Status</th>
            <th class="ptcp-th" style="text-align:center;">Action</th>
          </tr></thead>
          <tbody>
            <?php foreach ($items as $er): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.04);transition:background 0.15s;" onmouseover="this.style.background='rgba(255, 255, 255,0.03)'" onmouseout="this.style.background=''">
              <td class="ptcp-td" style="color:var(--gold-400);font-family:'Rajdhani',sans-serif;font-weight:700;font-size:13px;letter-spacing:0.8px;"><?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?></td>
              <td class="ptcp-td" style="color:var(--text-secondary);font-family:'Rajdhani',sans-serif;font-weight:600;"><?= htmlspecialchars($er['match_no']??'—') ?></td>
              <td class="ptcp-td" style="font-weight:500;"><?= htmlspecialchars($er['event_name']) ?></td>
              <td class="ptcp-td" style="color:var(--text-secondary);"><?= htmlspecialchars($er['weapon_type']??'—') ?></td>
              <td class="ptcp-td" style="color:var(--text-secondary);"><?= htmlspecialchars($er['age_group']??'—') ?></td>
              <td class="ptcp-td" style="text-align:right;font-family:'Rajdhani',sans-serif;font-weight:600;"><?= number_format((float)($er['best_score']??0),2) ?></td>
              <td class="ptcp-td" style="text-align:right;color:var(--gold-400);font-family:'Rajdhani',sans-serif;font-weight:600;">₹<?= number_format((float)($er['entry_fee']??0)) ?></td>
              <td class="ptcp-td">
                <span class="status-badge <?= $er['status']==='approved'?'approved':($er['status']==='rejected'?'rejected':'pending') ?>" style="font-size:10px;"><?= ucfirst($er['status']) ?></span>
              </td>
              <td class="ptcp-td" style="text-align:center;">
                <?php if ($er['status'] === 'pending'): ?>
                <button type="button" class="btn-del-event" data-reg-id="<?= htmlspecialchars($er['event_reg_id']) ?>" onclick="deleteEvent(this)" style="background:none;border:1px solid rgba(231,76,60,0.3);color:#E74C3C;border-radius:4px;padding:3px 8px;font-size:10px;cursor:pointer;">Delete</button>
                <?php else: ?>
                <span style="color:var(--text-muted);font-size:10px;">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ─────────────────────────────────── -->
    <!--   PAYMENTS TAB                      -->
    <!-- ─────────────────────────────────── -->
    <?php elseif ($section === 'payments'): ?>

    <div class="ptcp-page-header">
      <div>
        <div class="ptcp-page-title">Payment Status</div>
        <div class="ptcp-page-sub">Track your registration payment sessions and approval status</div>
      </div>
    </div>

    <!-- ⚠️ Non-Refundable Notice -->
    <div style="display:flex;align-items:flex-start;gap:12px;background:rgba(231,76,60,0.08);border:1px solid rgba(231,76,60,0.35);border-left:4px solid #e74c3c;border-radius:8px;padding:14px 18px;margin-bottom:22px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#e74c3c" stroke-width="2" style="flex-shrink:0;margin-top:2px;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      </svg>
      <div>
        <div style="font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:#e74c3c;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:3px;">Payment is Non-Refundable</div>
        <div style="font-size:12px;color:rgba(255,255,255,0.6);line-height:1.6;">All registration payments are <strong style="color:rgba(255,255,255,0.85);">strictly non-refundable</strong> once submitted. Please review your event selections carefully before making payment. For queries, contact the event organiser.</div>
      </div>
    </div>

    <?php if (empty($sessions)): ?>
    <div class="ptcp-empty">
      <div class="ptcp-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9 8h6m-5 0a3 3 0 110 6H9l3 3m-3-6h6m6 1a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
      <div>No payment sessions found. Register for events first.</div>
      <a href="event_register.php" class="btn btn-primary" style="width:auto;margin-top:16px;">Register Now</a>
    </div>
    <?php else: ?>
      <?php foreach ($sessions as $ses): ?>
      <div class="ptcp-payment-card" style="margin-bottom:18px;">
        <div class="ptcp-payment-header">
          <div>
            <div style="font-family:'Rajdhani',sans-serif;font-size:16px;font-weight:700;color:var(--gold-400);letter-spacing:1px;"><?= htmlspecialchars($ses['session_id']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?= strtoupper(date('d M Y, h:i A', strtotime($ses['created_at']))) ?></div>
          </div>
          <div style="text-align:right;">
            <div style="font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:var(--gold-400);">₹<?= number_format((float)$ses['total_amount'],2) ?></div>
            <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:6px;">
              <span class="status-badge <?= $ses['payment_status']==='verified'?'approved':($ses['payment_status']==='uploaded'?'pending':'inactive') ?>" style="font-size:10px;"><?= ucfirst($ses['payment_status']) ?></span>
              <span class="status-badge <?= $ses['approval_status'] ?>" style="font-size:10px;"><?= ucfirst($ses['approval_status']) ?></span>
            </div>
          </div>
        </div>
        <!-- Approval message -->
        <?php if ($ses['approval_status'] === 'approved'): ?>
        <div class="ptcp-payment-notice success">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Payment verified and registration approved. Check your email for the confirmation.
        </div>
        <?php elseif ($ses['approval_status'] === 'rejected'): ?>
        <div class="ptcp-payment-notice error">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          Rejected: <?= htmlspecialchars($ses['admin_remarks'] ?? 'No reason specified') ?>
        </div>
        <?php elseif ($ses['payment_status'] === 'uploaded'): ?>
        <div class="ptcp-payment-notice info">
          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
          Payment screenshot uploaded. Awaiting superadmin verification.
        </div>
        <?php endif; ?>
        <!-- Screenshot link -->
        <?php if (!empty($ses['payment_screenshot'])): ?>
        <div style="padding:12px 16px;border-top:1px solid rgba(255,255,255,0.05);">
          <a href="<?= htmlspecialchars($ses['payment_screenshot']) ?>" target="_blank" style="font-size:12px;color:var(--info);">🧾 View Payment Screenshot ↗</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- ─────────────────────────────────── -->
    <!--   ANNOUNCEMENTS & RESULTS TAB       -->
    <!-- ─────────────────────────────────── -->
    <?php elseif ($section === 'announcements'): 
      // Fetch latest updates
      try {
          $stmtLU = $pdo->query("SELECT * FROM landing_updates ORDER BY created_at DESC");
          $luList = $stmtLU->fetchAll();
      } catch (Exception $e) {
          $luList = [];
      }
    ?>

    <div class="ptcp-page-header">
      <div>
        <div class="ptcp-page-title">Official Announcements &amp; Results</div>
        <div class="ptcp-page-sub">View recent rank lists, certificates, and notice updates from superadmin</div>
      </div>
    </div>

    <?php if (empty($luList)): ?>
      <div class="ptcp-empty" style="padding:40px 20px; text-align:center; border:1px solid rgba(255,255,255,0.05); border-radius:10px; background:rgba(255,255,255,0.01);">
        <div class="ptcp-empty-icon" style="font-size:36px; color:var(--text-muted); margin-bottom:12px;"><i class="bi bi-bell-slash"></i></div>
        <div style="font-size:14px; color:var(--text-muted);">No announcements or results posted yet.</div>
      </div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:20px; max-width: 900px; margin: 0 auto;">
        <?php foreach ($luList as $lu): ?>
          <div class="ptcp-card" style="padding:24px; position:relative; border: 1.5px solid rgba(255, 255, 255,0.15); background: linear-gradient(145deg, rgba(20,24,36,0.95) 0%, rgba(10,12,18,0.98) 100%); display: flex; align-items: stretch; gap: 20px; flex-wrap: wrap;">
            
            <?php if (!empty($lu['cover_image'])): ?>
              <div style="width: 150px; min-width: 150px; height: 100px; border-radius: 8px; overflow: hidden; border: 1.5px solid rgba(255, 255, 255,0.15); background: rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;">
                <img src="<?= htmlspecialchars($lu['cover_image']) ?>" alt="Cover image" style="width: 100%; height: 100%; object-fit: cover;">
              </div>
            <?php endif; ?>

            <div style="flex: 1; min-width: 280px; display: flex; flex-direction: column; justify-content: center;">
              <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <div>
                  <?php if ($lu['update_type'] === 'rank_list'): ?>
                    <span style="background: rgba(255, 255, 255,0.12); color: var(--gold-400); font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(255, 255, 255,0.3); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">🏆 Rank List</span>
                  <?php elseif ($lu['update_type'] === 'certificate'): ?>
                    <span style="background: rgba(46,204,113,0.12); color: #2ecc71; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(46,204,113,0.3); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">🎖️ Certificate</span>
                  <?php else: ?>
                    <span style="background: rgba(52,152,219,0.12); color: #3498db; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; border: 1px solid rgba(52,152,219,0.3); letter-spacing: 0.5px; font-family:'Rajdhani', sans-serif;">📢 Academy Notice</span>
                  <?php endif; ?>
                  <span style="font-size:11.5px; color:var(--text-muted); font-family:'Rajdhani', sans-serif; font-weight: 600; margin-left:8px;"><?= date('d M Y, H:i', strtotime($lu['created_at'])) ?></span>
                </div>
              </div>

              <h3 style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:17px; color:var(--ssa-text-1); margin: 0 0 10px 0; letter-spacing: 0.5px;"><?= htmlspecialchars($lu['title']) ?></h3>
              
              <?php if (!empty($lu['description'])): ?>
                <p style="font-size:13px; line-height:1.6; color:var(--text-secondary); margin:0 0 16px 0; white-space:pre-wrap; font-family:'Inter', sans-serif;"><?= htmlspecialchars($lu['description']) ?></p>
              <?php endif; ?>

              <?php if (!empty($lu['file_path'])): 
                $fileUrl = htmlspecialchars(getAttachmentUrl($lu['file_path']));
                $fileTitle = htmlspecialchars($lu['title']);
              ?>
                <div style="display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.02); padding:10px 16px; border-radius:8px; border:1px solid rgba(255,255,255,0.05); margin-top:10px; flex-wrap:wrap;">
                  <i class="bi bi-file-earmark-arrow-down-fill" style="color:var(--gold-400); font-size:20px;"></i>
                  <span style="font-size:12px; font-weight:600; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; min-width: 150px;">
                    <?= htmlspecialchars(basename($lu['file_path'])) ?>
                  </span>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <a href="javascript:void(0);" data-filepath="<?= $fileUrl ?>" data-title="<?= $fileTitle ?>" onclick="triggerAttachmentModal(this)" class="btn-doc-view" style="padding: 7px 14px; font-size:11px;">
                      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16" style="vertical-align: middle; margin-right: 2px;">
                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                        <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                      </svg>
                      View Document
                    </a>
                    <a href="<?= $fileUrl ?>" download target="_blank" class="btn-doc-download" style="padding: 7px 14px; font-size:11px;">
                      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16" style="vertical-align: middle; margin-right: 2px;">
                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                        <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                      </svg>
                      Download Doc
                    </a>
                  </div>
                </div>
              <?php endif; ?>
            </div>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ─────────────────────────────────── -->
    <!--   PROFILE TAB                       -->
    <!-- ─────────────────────────────────── -->
    <?php elseif ($section === 'profile'): ?>

    <?php if ($profileUpdated): ?>
      <div class="ptcp-page-alert" style="margin-bottom:18px;padding:14px 18px;border:1px solid rgba(46, 204, 113, 0.25);background:rgba(46, 204, 113, 0.08);color:#2ecc71;border-radius:10px;">
        Profile updated successfully. Your latest information is now visible.
      </div>
    <?php endif; ?>

    <div class="ptcp-page-header" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <div class="ptcp-page-title">My Profile</div>
        <div class="ptcp-page-sub">Your registration details for <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') ?></div>
      </div>
      <div>
        <a href="edit_profile.php" class="btn btn-outline" style="font-size:13px;padding:10px 16px;">Edit Profile</a>
      </div>
    </div>

    <!-- Profile ID Card -->
    <div class="ptcp-id-card">
      <div class="ptcp-id-avatar" style="overflow:hidden;">
        <?php if (!empty($user['photo'])): ?>
          <img src="<?= htmlspecialchars($user['photo']) ?>" alt="Profile Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
        <?php else: ?>
          <?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?>
        <?php endif; ?>
      </div>
      <div class="ptcp-id-info">
        <div class="ptcp-id-name"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></div>
        <div class="ptcp-id-reg"><?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?></div>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
          <span class="status-badge active" style="font-size:10px;"><?= ucfirst($user['status']) ?></span>
          <span style="font-size:11px;color:var(--text-secondary);"><?= htmlspecialchars($user['club_name']) ?></span>
          <span style="font-size:11px;color:var(--text-secondary);">&bull; <?= htmlspecialchars($user['association']) ?></span>
        </div>
      </div>
      <div class="ptcp-id-badge">
        <div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Championship</div>
        <div style="font-family:'Cinzel',serif;font-size:11px;font-weight:600;color:var(--gold-400);">51st TN State</div>
        <div style="font-family:'Cinzel',serif;font-size:10px;color:var(--text-secondary);">Shooting Championship</div>
      </div>
    </div>

    <!-- Profile Sections -->
    <div class="ptcp-profile-grid">

      <!-- Personal -->
      <div class="ptcp-card">
        <div class="ptcp-card-header"><span class="ptcp-section-label">Personal Information</span></div>
        <div class="ptcp-profile-rows">
          <div class="ptcp-profile-row"><span class="ptcp-pkey">First Name</span><span class="ptcp-pval"><?= htmlspecialchars($user['first_name']) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Last Name</span><span class="ptcp-pval"><?= htmlspecialchars($user['last_name']) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Date of Birth</span><span class="ptcp-pval"><?= date('d F Y', strtotime($user['dob'])) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Gender</span><span class="ptcp-pval"><?= htmlspecialchars($user['gender']) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Father / Guardian</span><span class="ptcp-pval"><?= htmlspecialchars($user['father_guardian_name']) ?></span></div>
          <?php if (!empty($user['photo'])): ?>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Profile Photo</span><span class="ptcp-pval"><img src="<?= htmlspecialchars($user['photo']) ?>" alt="Profile Photo" style="max-width:120px;border-radius:8px;border:1px solid rgba(255, 255, 255,0.3);"></span></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Contact -->
      <div class="ptcp-card">
        <div class="ptcp-card-header"><span class="ptcp-section-label">Contact Details</span></div>
        <div class="ptcp-profile-rows">
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Email</span><span class="ptcp-pval" style="text-transform: none;"><?= htmlspecialchars($user['email']) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Mobile</span><span class="ptcp-pval"><?= htmlspecialchars(fmtPhone($user['phone'])) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Aadhaar</span><span class="ptcp-pval" style="font-family:'Rajdhani',sans-serif;letter-spacing:2px;"><?= htmlspecialchars(fmtAadhaar($user['aadhaar_number'])) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">District</span><span class="ptcp-pval"><?= htmlspecialchars($user['district']) ?></span></div>
          <div class="ptcp-profile-row" style="align-items:flex-start;"><span class="ptcp-pkey">Address</span><span class="ptcp-pval"><?= nl2br(htmlspecialchars($user['address'])) ?></span></div>
        </div>
      </div>

      <!-- Club & Association -->
      <div class="ptcp-card">
        <div class="ptcp-card-header"><span class="ptcp-section-label">Club &amp; Association</span></div>
        <div class="ptcp-profile-rows">
            <div class="ptcp-profile-row"><span class="ptcp-pkey">Club Name</span><span class="ptcp-pval"><?= htmlspecialchars($user['club_name']) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Association</span><span class="ptcp-pval"><?= htmlspecialchars($user['association']) ?></span></div>
          <?php if (!empty($user['membership_id'])): ?>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Membership ID</span><span class="ptcp-pval"><?= htmlspecialchars($user['membership_id']) ?></span></div>
          <?php endif; ?>
          <?php if (!empty($user['membership_doc'])): ?>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Membership Document</span><span class="ptcp-pval"><a href="<?= htmlspecialchars($user['membership_doc']) ?>" target="_blank" style="color:var(--info);">View document</a></span></div>
          <?php endif; ?>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Registered On</span><span class="ptcp-pval"><?= date('d M Y', strtotime($user['created_at'])) ?></span></div>
          <div class="ptcp-profile-row"><span class="ptcp-pkey">Account Status</span><span class="ptcp-pval"><span class="status-badge <?= $user['status'] ?>" style="font-size:10px;"><?= ucfirst($user['status']) ?></span></span></div>
        </div>
      </div>

      <!-- Registration ID -->
      <div class="ptcp-card" style="border-color:rgba(255, 255, 255,0.2);background:linear-gradient(145deg,rgba(255, 255, 255,0.05),rgba(255, 255, 255,0.02));">
        <div class="ptcp-card-header"><span class="ptcp-section-label" style="color:var(--gold-400);">Championship ID</span></div>
        <div style="padding:20px 20px;">
          <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Registration ID</div>
          <div style="font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--gold-400);letter-spacing:3px;"><?= htmlspecialchars($user['reg_id'] ?? 'TBD — Register for an event') ?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:8px;"><?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></div>
          <div style="font-size:11px;color:var(--text-muted);">Saragarhi Shooting Academy, Guru Nanak College</div>
        </div>
      </div>

    </div><!-- /.ptcp-profile-grid -->

    <?php endif; ?>

  </main><!-- /ptcp-main -->
</div><!-- /ptcp-wrapper -->

<!-- Mobile sidebar overlay -->
<div class="ptcp-overlay" id="ptcpOverlay" onclick="document.getElementById('ptcpSidebar').classList.remove('open');this.classList.remove('show');"></div>

<script>


// Notifications Drawer
function toggleNotifDropdown() {
  const d = document.getElementById('notifDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const w = document.querySelector('.notif-wrapper');
  const d = document.getElementById('notifDropdown');
  if (w && d && !w.contains(e.target)) {
    d.style.display = 'none';
  }
});
async function readNotif(id, redirectUrl) {
  try {
    const fd = new FormData();
    fd.append('id', id);
    await fetch('actions/read_notification.php', { method: 'POST', body: fd });
  } catch(e) {}
  window.location.href = redirectUrl;
}
async function markAllNotifsRead() {
  try {
    const fd = new FormData();
    fd.append('is_admin', 0);
    const resp = await fetch('actions/read_all_notifications.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      window.location.reload();
    }
  } catch(e) {}
}

// Delete event registration
function deleteEvent(btn) {
  if (!confirm('Are you sure you want to delete this event registration? This action cannot be undone.')) return;
  const regId = btn.dataset.regId;
  btn.disabled = true;
  btn.textContent = 'Deleting...';
  fetch('actions/delete_participant_event.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'event_reg_id=' + encodeURIComponent(regId)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const row = btn.closest('tr');
      row.style.transition = 'opacity 0.3s';
      row.style.opacity = '0';
      setTimeout(() => {
        row.remove();
        window.location.reload();
      }, 300);
    } else {
      alert(data.message || 'Delete failed.');
      btn.disabled = false;
      btn.textContent = 'Delete';
    }
  })
  .catch(() => {
    alert('Network error. Please try again.');
    btn.disabled = false;
    btn.textContent = 'Delete';
  });
}
</script>

<!-- Attachment Preview Modal (Open and see only) -->
<div id="attachmentModal" class="modal attachment-modal-custom" role="dialog" aria-modal="true" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:999999; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition: opacity 0.25s ease;">
  <div style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(8px);" onclick="closeAttachmentModal()"></div>
  <div style="position:relative; width:90%; max-width:1000px; height:85vh; background:#0c0e14; border-radius:12px; border:1px solid rgba(255, 255, 255,0.3); display:flex; flex-direction:column; overflow:hidden; z-index:2001; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
    <!-- Modal Header -->
    <div style="padding:14px 20px; border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center; background:#10121a; flex-wrap:wrap; gap:10px;">
      <h3 id="attachmentTitle" style="font-family:'Rajdhani',sans-serif; font-size:17px; font-weight:700; color:var(--gold-400); margin:0; text-transform:uppercase; letter-spacing:1px; flex:1; min-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Document Preview</h3>
      <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
        <a id="attachmentDirectLink" href="#" target="_blank" style="color:var(--gold-400); font-size:11.5px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:1px solid rgba(212,175,55,0.3); border-radius:6px; background:rgba(212,175,55,0.08);">
          ↗️ Open Tab
        </a>
        <a id="attachmentDownloadBtn" href="#" download target="_blank" style="color:#ffffff; font-size:11.5px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:1px solid rgba(255,255,255,0.25); border-radius:6px; background:rgba(255,255,255,0.1);">
          📥 Download
        </a>
        <button onclick="closeAttachmentModal()" style="background:transparent; border:none; color:var(--text-muted); cursor:pointer; font-size:24px; line-height:1; transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">&times;</button>
      </div>
    </div>
    <!-- Modal Body (Open only, right-click disabled) -->
    <div id="attachmentBody" style="flex:1; background:#08090d; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;" oncontextmenu="return false;">
      <!-- Embedded viewer loaded by JS -->
    </div>
  </div>
</div>

<script>
window.triggerAttachmentModal = function(btn) {
  if (!btn) return;
  const filePath = btn.getAttribute('data-filepath');
  const title = btn.getAttribute('data-title');
  window.openAttachmentModal(filePath, title);
};

window.openAttachmentModal = function(filePath, title) {
  const modal = document.getElementById('attachmentModal');
  const body = document.getElementById('attachmentBody');
  const titleEl = document.getElementById('attachmentTitle');
  const directLink = document.getElementById('attachmentDirectLink');
  const downloadBtn = document.getElementById('attachmentDownloadBtn');
  
  if (!filePath) {
    alert('Attachment file is not available.');
    return;
  }

  titleEl.innerText = title || 'Document Preview';
  body.innerHTML = '';

  let cleanPath = filePath.trim();
  if (cleanPath.startsWith('/')) {
    cleanPath = cleanPath.substring(1);
  }
  
  if (!cleanPath.startsWith('http://') && !cleanPath.startsWith('https://')) {
    const isSubdir = window.location.pathname.includes('/admin/');
    cleanPath = (isSubdir ? '../' : './') + cleanPath;
  }

  if (directLink) directLink.href = cleanPath;
  if (downloadBtn) downloadBtn.href = cleanPath;

  const ext = cleanPath.split('?')[0].split('.').pop().toLowerCase();

  if (ext === 'pdf') {
    body.innerHTML = `<iframe src="${cleanPath}" style="width:100%; height:100%; border:none; background:#ffffff;"></iframe>`;
  } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) {
    body.innerHTML = `<img src="${cleanPath}" alt="${title}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
  } else {
    body.innerHTML = `
      <div style="text-align:center; padding:40px; color:#ffffff; font-family:'Rajdhani', sans-serif;">
        <div style="font-size:48px; margin-bottom:16px;">📄</div>
        <h4 style="margin:0 0 10px 0; font-size:20px; font-weight:700;">${title || 'Document'}</h4>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom:24px;">This file format (.${ext.toUpperCase()}) cannot be rendered directly inside the embedded viewer.</p>
        <a href="${cleanPath}" target="_blank" download class="btn-portal" style="padding:12px 24px; background:var(--gold-400); color:#000; font-weight:700; text-decoration:none; border-radius:6px; display:inline-block; text-transform:uppercase;">
          📥 Open / Download Document File
        </a>
      </div>
    `;
  }

  modal.style.display = 'flex';
  modal.style.opacity = '1';
  modal.style.pointerEvents = 'auto';
  modal.classList.add('active');
  document.body.style.overflow = 'hidden';
};

window.closeAttachmentModal = function() {
  const modal = document.getElementById('attachmentModal');
  if (!modal) return;
  modal.style.display = 'none';
  modal.style.opacity = '0';
  modal.style.pointerEvents = 'none';
  modal.classList.remove('active');
  document.body.style.overflow = '';
  const body = document.getElementById('attachmentBody');
  if (body) body.innerHTML = '';
};

// Escape key to close attachment modal
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeAttachmentModal();
  }
});
</script>

</body>
</html>
