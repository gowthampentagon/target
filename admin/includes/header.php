<?php
/**
 * admin/includes/header.php
 * Shared page shell for admin pages.
 */
require_once __DIR__ . '/auth.php';

checkAdminAuth();

// Prevent browser from caching authenticated admin pages.
// This stops the back-button exposing the panel after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

try {
  $pdo = getDB();
  $notifCount = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_admin = 1 AND is_read = 0");
  $unreadCount = (int) $notifCount->fetchColumn();

  $notifList = $pdo->query("SELECT * FROM notifications WHERE is_admin = 1 AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
  $adminNotifs = $notifList->fetchAll();

  // Fetch passcode expiration details for standard admin
  $passcodeExpiresAt = null;
  if (!isSuperAdmin() && !empty($_SESSION['admin_id'])) {
    $stmt = $pdo->prepare("SELECT expires_at FROM admin_passcodes WHERE admin_id = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$_SESSION['admin_id']]);
    $passcodeExpiresAt = $stmt->fetchColumn();
  }
} catch (Exception $e) {
  $unreadCount = 0;
  $adminNotifs = [];
  $passcodeExpiresAt = null;
}

$pageTitle = $pageTitle ?? 'Admin Panel';
$bodyClass = $bodyClass ?? 'admin-body';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="UTF-8">
  <meta name="csrf-token" content="<?= getCsrfToken() ?>">
  <script>
    (function () {
      const originalFetch = window.fetch;
      const csrfToken = "<?= getCsrfToken() ?>";
      window.fetch = function (url, options) {
        options = options || {};
        options.headers = options.headers || {};
        if (options.headers instanceof Headers) {
          if (!options.headers.has('X-CSRF-Token')) {
            options.headers.append('X-CSRF-Token', csrfToken);
          }
        } else if (Array.isArray(options.headers)) {
          options.headers.push(['X-CSRF-Token', csrfToken]);
        } else {
          if (!options.headers['X-CSRF-Token']) {
            options.headers['X-CSRF-Token'] = csrfToken;
          }
        }
        return originalFetch.call(this, url, options);
      };

      document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (form) {
          if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = csrfToken;
            form.appendChild(input);
          }
        });
      });
    })();
  </script>
  <title><?= htmlspecialchars($pageTitle) ?> | TARGET Admin</title>
  <link rel="icon" href="../images/logo.png?v=<?= time() ?>" type="image/png">
  <link rel="shortcut icon" href="../favicon.ico?v=<?= time() ?>" type="image/x-icon">
  <link rel="apple-touch-icon" href="../images/logo.png?v=<?= time() ?>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Cinzel:wght@400;600;700&family=Playfair+Display:ital,wght@0,700;1,600&display=swap"
    rel="stylesheet">

  <!-- Shared CSS (borrowing base tokens) -->
  <link rel="stylesheet" href="../css/style.css?v=<?= time() ?>">

  <!-- Bootstrap 5 CSS (local) -->
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <!-- Bootstrap Icons (local) -->
  <link rel="stylesheet" href="css/bootstrap-icons.min.css">
  <!-- Admin Custom CSS -->
  <link rel="stylesheet" href="css/admin_style.css?v=<?= time() ?>">

  <!-- SweetAlert2 (local) -->
  <script src="js/sweetalert2.all.min.js"></script>

  <style>
    <?php if (!canEdit()): ?>
      /* If read-only admin: disable and gray out edit buttons, action toggles, save actions */
      button.edit,
      button.del,
      button.add,
      .btn-icon.edit,
      .btn-icon.del,
      .btn-icon.add,
      button[onclick^="delete"],
      button[onclick^="approve"],
      button[onclick^="reject"],
      button[onclick^="openEditModal"],
      button[onclick^="openEvtModal"],
      button[onclick^="allocate"],
      button[onclick^="revoke"],
      button[onclick^="openAddModal"],
      button[onclick^="addTableRow"],
      button[onclick^="openDelete"],
      button[onclick^="resetLayout"],
      button[onclick^="openAddDate"],
      button[onclick^="setRelay"],
      button[onclick^="triggerAuto"],
      button[onclick^="deleteAll"],
      button[onclick^="deleteReg"],
      button[onclick^="deleteEvtReg"],
      button[onclick^="deleteClub"],
      button[onclick^="deletePayment"],
      button[onclick^="deleteTeam"],
      button[onclick*="auto_allocate"],
      button[onclick*="allocate"],
      button[onclick*="create"],
      button[onclick^="openRelay"],
      button.btn-danger,
      input[type="submit"]:not(.modal-close),
      form button[type="submit"]:not(.modal-close) {
        opacity: 0.45 !important;
        pointer-events: none !important;
        cursor: not-allowed !important;
      }

    <?php endif; ?>
    <?php
    $level = isset($_SESSION['allow_edit']) ? (int) $_SESSION['allow_edit'] : 0;
    if ($level === 1): // Input Once: hide all edit/delete/approve buttons
      ?>
      /* Hide edit and delete triggers for input once */
      button.edit,
      .btn-icon.edit,
      button[onclick*="edit"],
      button[onclick*="Edit"],
      button.del,
      .btn-icon.del,
      button[onclick*="delete"],
      button[onclick*="Delete"],
      button[onclick*="approve"],
      button[onclick*="reject"],
      button.btn-danger {
        display: none !important;
      }

    <?php endif; ?>

    <?php
    if ($level === 2): // Edit: hide all add/delete/approve buttons
      ?>
      /* Hide add and delete triggers for edit only */
      button.add,
      .btn-icon.add,
      button[onclick*="add"],
      button[onclick*="Add"],
      button[onclick*="allocate"],
      button[onclick*="Allocate"],
      button.del,
      .btn-icon.del,
      button[onclick*="delete"],
      button[onclick*="Delete"],
      button[onclick*="approve"],
      button[onclick*="reject"],
      button.btn-danger {
        display: none !important;
      }

    <?php endif; ?>

    /* Superadmin Header Row styling (Floating Card Upgrade) */
    .superadmin-header-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, rgba(16, 20, 30, 0.95) 0%, rgba(8, 10, 15, 0.98) 100%);
      border: 1.5px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      padding: 14px 24px;
      margin-bottom: 14px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45);
      flex-wrap: wrap;
      gap: 14px;
      position: relative;
      top: 0;
      z-index: 1000;
    }

    .portal-brand-title {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .portal-emblem-icon {
      color: var(--ssa-text-1);
      background: rgba(108, 117, 125, 0.12);
      padding: 8px;
      border-radius: 50%;
      border: 1.5px solid rgba(108, 117, 125, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .brand-text-wrap {
      display: flex;
      flex-direction: column;
    }

    .portal-main-title {
      font-family: 'Cinzel', serif;
      font-size: 18px;
      font-weight: 700;
      color: var(--ssa-text-1);
      letter-spacing: 1px;
      line-height: 1.2;
    }

    .portal-subtitle {
      font-size: 10px;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-top: 2px;
    }

    .portal-user-meta {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .user-greeting-pill {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 13px;
      color: var(--text-primary);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .user-id-badge {
      background: var(--ssa-text-1);
      color: var(--dark-950);
      font-size: 10px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      padding: 2px 8px;
      border-radius: 20px;
      letter-spacing: 0.5px;
    }

    .portal-logout-btn {
      background: rgba(231, 76, 60, 0.1);
      border: 1px solid rgba(231, 76, 60, 0.25);
      border-radius: 30px;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #E74C3C;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
    }

    .portal-logout-btn:hover {
      background: #E74C3C;
      color: white !important;
      border-color: #E74C3C;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(231, 76, 60, 0.2);
    }

    .btn-back-portal {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-size: 13px;
      font-weight: 700;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--ssa-text-1);
      background: rgba(108, 117, 125, 0.12);
      border: 1px solid rgba(108, 117, 125, 0.12);
      border-radius: 8px;
      padding: 12px 24px;
      text-decoration: none;
      transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .btn-back-portal:hover {
      background: var(--ssa-text-1);
      color: var(--dark-950) !important;
      border-color: var(--ssa-text-1);
      transform: translateX(-4px);
      box-shadow: 0 8px 24px rgba(108, 117, 125, 0.12);
    }

    /* Superadmin Tile Grid styling */
    .superadmin-tile-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
      gap: 12px;
      margin-top: 18px;
      margin-bottom: 30px;
    }

    .superadmin-tile {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 14px 10px;
      background: linear-gradient(145deg, rgba(20, 22, 32, 0.92) 0%, rgba(12, 14, 22, 0.96) 100%);
      border: 1.5px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      min-height: 105px;
      box-sizing: border-box;
      text-decoration: none;
      color: var(--text-primary);
      cursor: pointer;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
    }

    .superadmin-tile:hover {
      transform: translateY(-3px);
      border-color: rgba(212, 175, 55, 0.5) !important;
      background: linear-gradient(145deg, rgba(30, 32, 46, 0.96) 0%, rgba(16, 18, 28, 0.98) 100%);
      box-shadow: 0 10px 22px rgba(0, 0, 0, 0.45), 0 0 12px rgba(212, 175, 55, 0.15);
    }

    .superadmin-tile-icon {
      font-size: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 9px;
      color: var(--gold-400, #d4af37);
      transition: all 0.25s ease;
    }

    .superadmin-tile-icon i {
      font-size: 18px !important;
    }

    .superadmin-tile:hover .superadmin-tile-icon {
      background: rgba(212, 175, 55, 0.15);
      border-color: rgba(212, 175, 55, 0.4);
      transform: scale(1.08);
    }

    .superadmin-tile-label {
      font-family: 'Rajdhani', sans-serif;
      font-size: 13px;
      font-weight: 700;
      text-align: center;
      color: #ffffff;
      line-height: 1.2;
      letter-spacing: 0.3px;
      text-transform: uppercase;
    }

    .superadmin-tile:hover .superadmin-tile-label {
      color: var(--gold-400, #d4af37);
    }

    .superadmin-tile-sub {
      font-size: 9.5px;
      color: rgba(255, 255, 255, 0.55);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      font-weight: 600;
      text-align: center;
      line-height: 1.2;
    }

      /* Allowed Access Bar styling */
      .access-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        background: linear-gradient(135deg, rgba(16, 20, 30, 0.85) 0%, rgba(10, 12, 18, 0.8) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        padding: 8px 16px;
        margin-top: 0;
        margin-bottom: 24px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
      }

      .access-link,
      .access-link:visited,
      .access-link:link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 700;
        font-family: 'Rajdhani', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-primary, #F0EDE6) !important;
        background: rgba(255, 255, 255, 0.06);
        padding: 6px 14px;
        border-radius: 20px;
        transition: all 0.2s ease;
        border: 1px solid rgba(255, 255, 255, 0.12);
        text-decoration: none !important;
      }

      .access-link:hover {
        background: rgba(255, 255, 255, 0.14) !important;
        border-color: rgba(255, 255, 255, 0.3) !important;
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        text-decoration: none !important;
      }

      .access-link.active {
        background: rgba(255, 255, 255, 0.18) !important;
        border-color: rgba(255, 255, 255, 0.4) !important;
        color: #ffffff !important;
        box-shadow: 0 0 12px rgba(255, 255, 255, 0.15);
        text-decoration: none !important;
      }

      /* Responsive overrides for Superadmin Dashboard & Top Header */
      @media (max-width: 991.98px) {

        /* Floating Top Header Layout Alignments */
        .superadmin-header-row {
          flex-direction: column !important;
          align-items: center !important;
          text-align: center !important;
          padding: 16px 14px !important;
          gap: 12px !important;
        }

        .portal-brand-title {
          flex-direction: column !important;
          align-items: center !important;
          gap: 8px !important;
        }

        .brand-text-wrap {
          text-align: center !important;
          align-items: center !important;
        }

        .portal-subtitle {
          text-align: center !important;
        }

        .portal-user-meta {
          width: 100% !important;
          flex-wrap: wrap !important;
          justify-content: center !important;
          align-items: center !important;
          gap: 10px !important;
        }

        .user-greeting-pill {
          width: 100% !important;
          justify-content: center !important;
          box-sizing: border-box !important;
          font-size: 12px !important;
          padding: 6px 10px !important;
          white-space: normal !important;
          text-align: center !important;
        }

        .notif-wrapper {
          margin-right: 0 !important;
        }

        .portal-logout-btn {
          flex-grow: 1 !important;
          justify-content: center !important;
          box-sizing: border-box !important;
        }

        /* Photo strip columns layout for admin pages */
        .admin-photo-strip {
          grid-template-columns: 1fr 1fr !important;
          height: 100px !important;
        }

        .admin-photo-strip .ai-3,
        .admin-photo-strip .ai-4 {
          display: none !important;
        }

        .admin-photo-strip .astrip-label {
          font-size: 7.5px !important;
        }

        /* Championship Band wrapping */
        .championship-band {
          font-size: 11px !important;
          line-height: 1.5 !important;
          padding: 8px 12px !important;
          text-align: center !important;
        }

        /* Clean 2-column tiles menu on mobile devices */
        .superadmin-tile-grid {
          grid-template-columns: repeat(2, 1fr) !important;
          gap: 12px !important;
          margin-top: 15px !important;
          margin-bottom: 25px !important;
        }

        /* Make tile contents slightly more compact for mobile viewport */
        .superadmin-tile {
          height: 115px !important;
          padding: 16px 10px !important;
          gap: 8px !important;
          border-radius: 14px !important;
        }

        .superadmin-tile-icon {
          width: 44px !important;
          height: 44px !important;
          font-size: 22px !important;
          border-radius: 10px !important;
        }

        .superadmin-tile-label {
          font-size: 11.5px !important;
          letter-spacing: 0.1px !important;
        }

        .superadmin-tile-sub {
          font-size: 8px !important;
          margin-top: -2px !important;
        }

        .superadmin-tile .lock-badge {
          font-size: 9px !important;
          top: 6px !important;
          right: 6px !important;
        }

        /* Access sticky bar for modules on mobile */
        .access-bar {
          position: static !important;
          /* avoid overlapping flow issues on mobile */
          padding: 8px 12px !important;
          gap: 8px !important;
          margin-bottom: 16px !important;
        }

        .access-link {
          font-size: 11px !important;
          padding: 5px 10px !important;
        }
      }

      /* Custom Supreme Admin Dropdown Select Box Styling */
      select,
      .admin-main select,
      .table-wrap select,
      .modal select,
      .form-group select,
      .filter-group select {
        appearance: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        background-color: rgba(18, 18, 26, 0.95) !important;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23d4af37' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 12px center !important;
        background-size: 14px 14px !important;
        border: 1.5px solid rgba(212, 175, 55, 0.35) !important;
        border-radius: 8px !important;
        color: #ffffff !important;
        font-family: 'Inter', 'Rajdhani', sans-serif !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        padding: 8px 36px 8px 14px !important;
        outline: none !important;
        cursor: pointer !important;
        transition: all 0.25s ease-in-out !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35) !important;
      }

      select:hover,
      .admin-main select:hover,
      .modal select:hover {
        border-color: rgba(212, 175, 55, 0.75) !important;
        background-color: rgba(26, 26, 36, 0.98) !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.5), 0 0 12px rgba(212, 175, 55, 0.2) !important;
      }

      select:focus,
      .admin-main select:focus,
      .modal select:focus {
        border-color: var(--gold-400, #d4af37) !important;
        box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.3), 0 0 15px rgba(212, 175, 55, 0.25) !important;
      }

      select option,
      select optgroup {
        background-color: #12121a !important;
        color: #ffffff !important;
        padding: 12px 14px !important;
        font-weight: 500 !important;
      }
  </style>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">

  <!-- SSA Photo Background -->
  <div class="page-bg d-print-none" aria-hidden="true"></div>
  <div class="page-bg-noise d-print-none" aria-hidden="true"></div>

  <!-- Admin Layout Wrapper -->
  <div class="admin-wrapper">
    <!-- Main Content Area (Sidebar removed for all admins) -->
    <main class="admin-main">
      <!-- Top Header (Floating Card like Participant Portal) -->
      <div class="superadmin-header-row print-hide d-print-none" style="margin-bottom: 24px;">
        <div
          style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 14px;">
          <a href="<?= isSupremeAdmin() ? 'supreme_admin.php' : 'index.php' ?>" class="portal-brand-title" style="text-decoration:none; color:inherit;">
            <div class="portal-emblem-icon" style="background:transparent; border:none; display:flex; align-items:center;">
              <img src="../images/logo.png?v=<?= time() ?>" alt="TARGET Logo" style="width:34px; height:34px; object-fit:contain;">
            </div>
            <div class="brand-text-wrap">
              <span class="portal-main-title" style="letter-spacing:1.5px; font-weight:700;">TARGET PORTAL</span>
              <span class="portal-subtitle" style="display:block; font-size:11px; opacity:0.85;">TARGET &bull; Tournament Administration and Registration Gateway for Event Tracking &bull;
                <?= isSupremeAdmin() ? 'Supreme Admin Console' : (isSuperAdmin() ? 'Superadmin Console' : 'Admin Console') ?></span>
            </div>
          </a>
          <div class="portal-user-meta">
            <span class="user-greeting-pill">
              <i class="bi bi-person-fill" style="margin-right: 4px;"></i> Welcome,
              <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong>
              <span class="user-id-badge"><?= isSupremeAdmin() ? 'SUPREME ADMIN' : (isSuperAdmin() ? 'SUPERADMIN' : 'ADMIN') ?></span>
            </span>
            <!-- Notification Icon -->
            <?php if (isSuperAdmin()): ?>
              <div class="notif-wrapper" style="position:relative; display:inline-block; margin-right:4px;">
                <button class="notif-bell-btn" onclick="toggleNotifDropdown()"
                  style="background:none; border:none; color:var(--text-secondary); font-size:18px; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; padding:8px; border-radius:50%; transition:background 0.2s; outline:none;">
                  <i class="bi bi-bell-fill"></i>
                  <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge"
                      style="position:absolute; top:-2px; right:-2px; background:var(--danger); color:white; font-size:9px; font-weight:700; width:15px; height:15px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 0 6px var(--danger); line-height:1;"><?= $unreadCount ?></span>
                  <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown"
                  style="display:none; position:absolute; right:0; top:40px; width:320px; background:var(--dark-900); border:1px solid rgba(255,255,255,0.07); border-radius:var(--radius-md); box-shadow:0 10px 30px rgba(0,0,0,0.8); z-index:1100; overflow:hidden;">
                  <div
                    style="padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.06); font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--ssa-text-1); text-transform:uppercase; letter-spacing:1px; display:flex; justify-content:space-between; align-items:center;">
                    <span>Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                      <a href="javascript:void(0)" onclick="markAllNotifsRead()"
                        style="font-size:10px; color:var(--text-muted); text-transform:none; font-weight:400; text-decoration:underline;">Mark
                        all read</a>
                    <?php endif; ?>
                  </div>
                  <div style="max-height:280px; overflow-y:auto; font-size:12px;">
                    <?php if (empty($adminNotifs)): ?>
                      <div style="padding:20px; text-align:center; color:var(--text-muted);">No notifications yet.</div>
                    <?php else: ?>
                      <?php foreach ($adminNotifs as $n): ?>
                        <a href="javascript:void(0)"
                          onclick="readNotif(<?= $n['id'] ?>, '<?= htmlspecialchars($n['redirect_url']) ?>')"
                          style="display:block; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.04); color:<?= $n['is_read'] ? 'var(--text-muted)' : 'var(--text-primary)' ?>; background:<?= $n['is_read'] ? 'transparent' : 'rgba(255,255,255,0.01)' ?>; text-decoration:none; transition:background 0.2s; text-align:left;">
                          <div
                            style="font-weight:<?= $n['is_read'] ? '500' : '700' ?>; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                            <span
                              style="font-weight:700; color:var(--ssa-text-1);"><?= htmlspecialchars($n['title']) ?></span>
                            <span
                              style="font-size:9px; color:var(--text-muted); font-weight:400; white-space:nowrap;"><?= date('d M H:i', strtotime($n['created_at'])) ?></span>
                          </div>
                          <div style="margin-top:4px; font-size:11px; line-height:1.4; color:var(--text-secondary);">
                            <?= htmlspecialchars($n['message']) ?></div>
                        </a>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <a href="../logout.php" class="portal-logout-btn">
              <span>Logout</span>
              <i class="bi bi-box-arrow-right"></i>
            </a>
          </div>
        </div>

        <!-- Access Bar for standard admins embedded inside card -->
        <?php if (!isSuperAdmin()): ?>
          <div class="access-subrow"
            style="width:100%; border-top:1px solid rgba(255,255,255,0.08); margin-top:12px; padding-top:10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span
              style="font-size:11px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; color:var(--text-primary, #F0EDE6); font-weight:700; letter-spacing:1px; margin-right:8px; display:inline-flex; align-items:center; gap:6px;"><i
                class="bi bi-shield-lock-fill" style="color:var(--text-secondary, #A8A49C); font-size:13px;"></i> Allowed
              Access:</span>
            <?php
            $currentPage = basename($_SERVER['PHP_SELF']);
            $defaultStyle = 'display:inline-flex; align-items:center; justify-content:center; gap:5px; font-size:11.5px; font-weight:700; font-family:\'Rajdhani\',sans-serif; text-transform:uppercase; color:#F0EDE6 !important; background:rgba(255,255,255,0.06); height:28px; padding:0 12px; border-radius:20px; border:1px solid rgba(255,255,255,0.12); text-decoration:none !important;';
            $activeStyle = 'display:inline-flex; align-items:center; justify-content:center; gap:5px; font-size:11.5px; font-weight:700; font-family:\'Rajdhani\',sans-serif; text-transform:uppercase; color:#FFFFFF !important; background:rgba(255,255,255,0.20); height:28px; padding:0 12px; border-radius:20px; border:1px solid rgba(255,255,255,0.40); text-decoration:none !important; box-shadow:0 0 10px rgba(255,255,255,0.15);';
            ?>
            <a href="index.php" class="access-link <?= $currentPage === 'index.php' ? 'active' : '' ?>"
              style="<?= $currentPage === 'index.php' ? $activeStyle : $defaultStyle ?>">
              <i class="bi bi-speedometer2" style="font-size:12px;"></i> Dashboard
            </a>
            <?php
            $pagesList = [
              'registrations.php' => ['<i class="bi bi-people-fill" style="font-size:12px;"></i>', 'Registrations'],
              'event_registrations.php' => ['<i class="bi bi-crosshair" style="font-size:12px;"></i>', 'Events'],
              'payment_sessions.php' => ['<i class="bi bi-cash-stack" style="font-size:12px;"></i>', 'Payments'],
              'lane_allocations.php' => ['<i class="bi bi-calendar3" style="font-size:12px;"></i>', 'Lanes'],
              'start_sheet.php' => ['<i class="bi bi-file-earmark-text-fill" style="font-size:12px;"></i>', 'Start Lists'],
              'team_events.php' => ['<i class="bi bi-people" style="font-size:12px;"></i>', 'Teams'],
              'rank_list.php' => ['<i class="bi bi-trophy-fill" style="font-size:12px;"></i>', 'Rank List'],
              'clubs.php' => ['<i class="bi bi-building-fill" style="font-size:12px;"></i>', 'Clubs']
            ];
            $hasAnyLink = false;

            foreach ($pagesList as $url => $meta) {
              if (!empty($_SESSION['allowed_modules']) && in_array($url, $_SESSION['allowed_modules'], true)) {
                $isActive = ($currentPage === $url);
                $style = $isActive ? $activeStyle : $defaultStyle;
                $activeClass = $isActive ? 'active' : '';
                $hasAnyLink = true;
                echo '
                <a href="' . $url . '" class="access-link ' . $activeClass . '" style="' . $style . '">
                  <span>' . $meta[0] . '</span> ' . $meta[1] . '
                </a>';
              }
            }
            ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Back Button for Inner Pages -->
      <?php 
        $currentAdminPage = basename($_SERVER['PHP_SELF']);
        $homeDashboard = isSupremeAdmin() ? 'supreme_admin.php' : 'index.php';
        if ($currentAdminPage !== 'index.php' && $currentAdminPage !== 'supreme_admin.php'): 
      ?>
        <div style="margin-bottom:28px;" class="print-hide">
          <a href="<?= $homeDashboard ?>" class="btn-back-portal">
            ← Back to Dashboard
          </a>
        </div>
      <?php endif; ?>

      <script>
        function toggleNotifDropdown() {
          const d = document.getElementById('notifDropdown');
          const badge = document.querySelector('.notif-badge');
          const isOpening = (d.style.display === 'none' || !d.style.display);

          d.style.display = isOpening ? 'block' : 'none';

          if (isOpening) {
            if (badge) {
              badge.style.display = 'none';
            }
            try {
              const fd = new FormData();
              fd.append('is_admin', 1);
              fetch('../actions/read_all_notifications.php', { method: 'POST', body: fd });
            } catch (e) { }
          }
        }
        document.addEventListener('click', function (e) {
          const w = document.querySelector('.notif-wrapper');
          const d = document.getElementById('notifDropdown');
          if (w && d && !w.contains(e.target)) {
            d.style.display = 'none';
          }
        });
        window.passcodeExpiresAt = <?= $passcodeExpiresAt ? json_encode(date('c', strtotime($passcodeExpiresAt))) : 'null' ?>;

        if (window.passcodeExpiresAt) {
          const expireTime = new Date(window.passcodeExpiresAt).getTime();
          const checkInterval = setInterval(() => {
            const now = new Date().getTime();
            if (now >= expireTime) {
              clearInterval(checkInterval);
              showExpirationModal();
            }
          }, 1000);
        }

        function showExpirationModal() {
          const overlay = document.createElement('div');
          overlay.className = 'modal-overlay active';
          overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.85); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center;';

          const modal = document.createElement('div');
          modal.style.cssText = 'background:#0d0f14; border:2px solid #4A4A4A; border-radius:15px; padding:30px; max-width:400px; width:90%; text-align:center; box-shadow:0 0 30px rgba(255,255,255,0.07);';

          modal.innerHTML = `
          <div style="font-size:40px; margin-bottom:15px;">⏳</div>
          <h3 style="font-family:\'Cinzel\', serif; color:#4A4A4A; margin:0 0 10px 0; text-transform:uppercase; letter-spacing:1px;">Access Expired</h3>
          <p style="font-size:14px; color:#a8a49c; line-height:1.6; margin-bottom:25px;">Your temporary passcode access has expired. You will be redirected to the login page.</p>
          <button id="expireOkBtn" style="background:#4A4A4A; color:#05060a; border:none; padding:12px 30px; font-family:\'Rajdhani\', sans-serif; font-weight:700; text-transform:uppercase; letter-spacing:1px; border-radius:5px; cursor:pointer; width:100%; transition:background 0.2s;">Exit Now</button>
      `;

          overlay.appendChild(modal);
          document.body.appendChild(overlay);

          const handleExit = () => {
            window.location.href = '../logout.php?expired_passcode=1';
          };

          document.getElementById('expireOkBtn').addEventListener('click', handleExit);
          setTimeout(handleExit, 6000);
        }

        async function readNotif(id, redirectUrl) {
          try {
            const fd = new FormData();
            fd.append('id', id);
            await fetch('../actions/read_notification.php', { method: 'POST', body: fd });
          } catch (e) { }
          window.location.href = redirectUrl;
        }
        async function markAllNotifsRead() {
          try {
            const fd = new FormData();
            fd.append('is_admin', 1);
            const resp = await fetch('../actions/read_all_notifications.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
              window.location.reload();
            }
          } catch (e) { }
        }
      </script>