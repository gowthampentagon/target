<?php
/**
 * admin/control_panel.php
 * Superadmin interface to configure form fields and manage temporary admin passcode access.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin

$pdo = getDB();

// Database schema self-update: add allow_edit column if not exists
try {
    $pdo->exec("ALTER TABLE `admin_passcodes` ADD COLUMN `allow_edit` TINYINT(1) DEFAULT 0 AFTER `allowed_modules`");
} catch (Exception $colEx) {
    // Suppress if column already exists
}
try {
    $pdo->exec("ALTER TABLE `score_sheets` ADD COLUMN `edit_count` INT NOT NULL DEFAULT 0 AFTER `remarks`");
} catch (Exception $e) {
    // Suppress if column already exists
}

$pageTitle = 'Superadmin Control Panel';
require_once 'includes/header.php';

// Fetch event info (championship settings)
try {
    $eventInfo = $pdo->query("SELECT * FROM event_info WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$eventInfo) {
        $pdo->exec("INSERT INTO event_info (id, event_code, event_title, org_name) VALUES (1, 'SSA51TN', '51st Tamil Nadu Shooting Championship', 'TARGET')");
        $eventInfo = $pdo->query("SELECT * FROM event_info WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $eventInfo = [];
}

// Fetch all field configurations
try {
    $regFields = $pdo->query("SELECT * FROM field_controls WHERE form_name = 'registration' ORDER BY is_custom ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $evtFields = $pdo->query("SELECT * FROM field_controls WHERE form_name = 'event_registration' ORDER BY is_custom ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $regFields = [];
    $evtFields = [];
}

// Fetch regular admins for passcode dropdown
try {
    $regularAdmins = $pdo->query("SELECT id, name, email FROM admins WHERE role != 'superadmin' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $regularAdmins = [];
}

// Fetch active passcodes
try {
    $passcodes = $pdo->query("
        SELECT ap.id, ap.passcode, ap.expires_at, ap.allowed_modules, ap.allow_edit, a.name AS admin_name 
        FROM admin_passcodes ap 
        JOIN admins a ON ap.admin_id = a.id 
        ORDER BY ap.expires_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $passcodes = [];
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Superadmin Control Panel</div>
  <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Manage form fields and grant temporary admin passcode access</div>
</div>

<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Toggle Switch CSS Injection -->
<style>
.switch-toggle {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}
.switch-toggle input {
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.15);
  transition: .3s;
  border-radius: 24px;
}
.slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 3px;
  bottom: 3px;
  background-color: var(--text-muted);
  transition: .3s;
  border-radius: 50%;
}
.switch-toggle input:checked + .slider {
  background-color: var(--gold-400);
  border-color: var(--gold-400);
}
.switch-toggle input:checked + .slider:before {
  transform: translateX(20px);
  background-color: #0c0e14;
}
</style>

<!-- Tab Navigation -->
<div style="display:flex; border-bottom:1px solid rgba(255,255,255,0.08); margin-bottom:24px; gap:8px;">
  <button class="tab-btn active" onclick="switchTab('tab-reg')" id="btn-tab-reg" style="background:none; border:none; border-bottom:2px solid var(--gold-400); color:var(--gold-400); padding:12px 20px; font-weight:700; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">Participant Registration Fields</button>
  <button class="tab-btn" onclick="switchTab('tab-evt')" id="btn-tab-evt" style="background:none; border:none; border-bottom:2px solid transparent; color:var(--text-muted); padding:12px 20px; font-weight:700; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">Event Registration Fields</button>
  <button class="tab-btn" onclick="switchTab('tab-passcode')" id="btn-tab-passcode" style="background:none; border:none; border-bottom:2px solid transparent; color:var(--text-muted); padding:12px 20px; font-weight:700; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">Admin Passcode Access</button>
  <button class="tab-btn" onclick="switchTab('tab-reg-control')" id="btn-tab-reg-control" style="background:none; border:none; border-bottom:2px solid transparent; color:var(--text-muted); padding:12px 20px; font-weight:700; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">Event Registration Control</button>
</div>

<!-- Tab 1: Participant Registration Fields -->
<div class="tab-content" id="tab-reg" style="display:block;">
  <div style="text-align:center; margin-bottom:24px;">
    <h3 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:18px; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px;">Field Configuration (New Registration)</h3>
  </div>

  <form onsubmit="saveFieldConfig(event, 'registration')">
    <input type="hidden" name="action" value="update_fields">
    <input type="hidden" name="form_name" value="registration">
    
    <div class="table-wrap" style="margin:0 auto 24px auto; max-width:600px; border:1px solid rgba(255,255,255,0.06); border-radius:8px; overflow:hidden; background:#0c0e14;">
      <table class="admin-table" style="margin:0;">
        <thead>
          <tr>
            <th style="padding:14px 20px;">Field Label</th>
            <th style="text-align:center; width:140px; padding:14px 20px;">Mandatory</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($regFields as $f): ?>
            <tr>
              <td style="font-weight:600; color:var(--text-primary); padding:14px 20px;">
                <?= htmlspecialchars($f['field_label']) ?>
                <input type="hidden" name="fields[<?= $f['field_id'] ?>][enabled]" value="1">
              </td>
              <td style="text-align:center; padding:14px 20px; vertical-align:middle;">
                <label class="switch-toggle">
                  <input type="checkbox" name="fields[<?= $f['field_id'] ?>][mandatory]" value="1" <?= $f['is_mandatory'] ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="text-align:center; margin-bottom:40px;">
      <button type="submit" class="btn btn-primary" style="width:auto; padding:12px 32px;">Save Configuration</button>
    </div>
  </form>
</div>

<!-- Tab 2: Event Registration Fields -->
<div class="tab-content" id="tab-evt" style="display:none;">
  <div style="text-align:center; margin-bottom:24px;">
    <h3 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:18px; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px;">Field Configuration (Event Details)</h3>
  </div>

  <form onsubmit="saveFieldConfig(event, 'event_registration')">
    <input type="hidden" name="action" value="update_fields">
    <input type="hidden" name="form_name" value="event_registration">
    
    <div class="table-wrap" style="margin:0 auto 24px auto; max-width:600px; border:1px solid rgba(255,255,255,0.06); border-radius:8px; overflow:hidden; background:#0c0e14;">
      <table class="admin-table" style="margin:0;">
        <thead>
          <tr>
            <th style="padding:14px 20px;">Field Label</th>
            <th style="text-align:center; width:140px; padding:14px 20px;">Mandatory</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($evtFields as $f): ?>
            <tr>
              <td style="font-weight:600; color:var(--text-primary); padding:14px 20px;">
                <?= htmlspecialchars($f['field_label']) ?>
                <input type="hidden" name="fields[<?= $f['field_id'] ?>][enabled]" value="1">
              </td>
              <td style="text-align:center; padding:14px 20px; vertical-align:middle;">
                <label class="switch-toggle">
                  <input type="checkbox" name="fields[<?= $f['field_id'] ?>][mandatory]" value="1" <?= $f['is_mandatory'] ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="text-align:center; margin-bottom:40px;">
      <button type="submit" class="btn btn-primary" style="width:auto; padding:12px 32px;">Save Configuration</button>
    </div>
  </form>
</div>

<!-- Tab 3: Admin Passcode Access -->
<div class="tab-content" id="tab-passcode" style="display:none;">
  <div style="display:grid; grid-template-columns:1.2fr 2fr; gap:24px; align-items:start;">
    
    <!-- Generate Form Box -->
      <div style="background:var(--grad-panel); border:var(--border-dark); border-radius:var(--radius-md); padding:24px;">
        <h3 style="margin-top:0; margin-bottom:16px; font-family:'Rajdhani',sans-serif; font-size:16px; color:var(--gold-400); text-transform:uppercase; letter-spacing:0.5px;">Generate Temporary Passcode</h3>
      
      <form id="passcodeForm" onsubmit="generatePasscode(event)">
        <input type="hidden" name="action" value="generate_passcode">
        
        <div class="form-group" style="margin-bottom:16px;">
          <label>Select Admin User <span class="req">*</span></label>
          <div class="input-wrap">
            <select name="admin_id" required>
              <option value="">-- SELECT ADMIN --</option>
              <?php foreach ($regularAdmins as $ra): ?>
                <option value="<?= $ra['id'] ?>"><?= htmlspecialchars($ra['name']) ?> (<?= htmlspecialchars($ra['email']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        
        <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
          <div class="form-group">
            <label>Expiry Date <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="date" name="expiry_date" required min="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Expiry Time</label>
            <div class="input-wrap">
              <input type="time" name="expiry_time" value="23:59">
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
          <label>Grant Module Permissions <span class="req">*</span></label>
          <div style="display:grid; grid-template-columns:1fr; gap:10px; margin-top:8px;">
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="registrations.php" checked>
              <span style="font-size:13px; color:var(--text-primary);">Registrations Management</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="event_registrations.php" checked>
              <span style="font-size:13px; color:var(--text-primary);">Event Registrations</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="clubs.php" checked>
              <span style="font-size:13px; color:var(--text-primary);">Clubs Management</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="payment_sessions.php" checked>
              <span style="font-size:13px; color:var(--text-primary);">Payment Verification</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="lane_allocations.php">
              <span style="font-size:13px; color:var(--text-primary);">Lane Allocations</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="start_sheet.php">
              <span style="font-size:13px; color:var(--text-primary);">Start Lists</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="team_events.php">
              <span style="font-size:13px; color:var(--text-primary);">Team Events</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="rank_list.php">
              <span style="font-size:13px; color:var(--text-primary);">Rank List</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="../ssa-dashboard/manage.php">
              <span style="font-size:13px; color:var(--text-primary);">Landing News/Events</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="manage_updates.php">
              <span style="font-size:13px; color:var(--text-primary);">Updates &amp; Results</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="profile_change_requests.php">
              <span style="font-size:13px; color:var(--text-primary);">Support Requests</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" value="document_editor.php">
              <span style="font-size:13px; color:var(--text-primary);">Document Designer</span>
            </label>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
          <label>Write/Edit Permissions</label>
          <div style="margin-top:8px; display:flex; flex-direction:column; gap:8px;">
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="0" class="perm-chk" onchange="selectPermission(this)" checked>
              <span style="font-size:13px; color:var(--text-primary);">View</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="1" class="perm-chk" onchange="selectPermission(this)">
              <span style="font-size:13px; color:var(--text-primary);">Input Once</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="2" class="perm-chk" onchange="selectPermission(this)">
              <span style="font-size:13px; color:var(--text-primary);">Edit</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="3" class="perm-chk" onchange="selectPermission(this)">
              <span style="font-size:13px; color:var(--text-primary); font-weight:600;">Dynamic Access</span>
            </label>
            <input type="hidden" name="allow_edit" id="allow_edit_value" value="0">
          </div>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width:100%;">Generate Passcode</button>
      </form>
      
      <!-- Passcode Result Display -->
      <div id="passcodeResult" style="display:none; background:rgba(255, 255, 255,0.06); border:1px solid rgba(255, 255, 255,0.25); border-radius:8px; padding:16px; margin-top:20px; text-align:center;">
        <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">Temporary Passcode</div>
        <div id="generatedCode" style="font-family:'Rajdhani',sans-serif; font-size:26px; font-weight:700; color:var(--gold-400); letter-spacing:2px; margin-bottom:6px;">SSA-000000</div>
        <div style="font-size:11px; color:var(--text-muted);">Share this passcode with the admin user. It will expire at the configured date &amp; time.</div>
      </div>
    </div> <!-- /Generate Form Box -->
  
  <!-- Active Passcodes Table -->
    <div>
      <h3 style="margin-top:0; margin-bottom:16px; font-family:'Rajdhani',sans-serif; font-size:16px; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px;">Active Temp Access Passcodes</h3>
      
      <div class="table-wrap">
        <?php
        $moduleMapping = [
            'registrations.php' => 'Registrations',
            'event_registrations.php' => 'Event Reg',
            'clubs.php' => 'Clubs',
            'payment_sessions.php' => 'Payments',
            'lane_allocations.php' => 'Lanes',
            'start_sheet.php' => 'Start Lists',
            'team_events.php' => 'Teams',
            'rank_list.php' => 'Rank List',
            '../ssa-dashboard/manage.php' => 'Landing News/Events',
            'manage_updates.php' => 'Updates & Results',
            'profile_change_requests.php' => 'Support Requests',
            'document_editor.php' => 'Document Designer'
        ];
        ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Admin Name</th>
              <th>Passcode</th>
              <th>Modules Access</th>
              <th style="text-align:center;">Allow Edit</th>
              <th>Expires At</th>
              <th>Status</th>
              <th style="text-align:right; width:100px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($passcodes)): ?>
              <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:30px 10px;">No temporary passcodes active.</td></tr>
            <?php else: foreach ($passcodes as $p): 
              $isExpired = strtotime($p['expires_at']) <= time();
              $allowedBasenames = explode(',', $p['allowed_modules'] ?? '');
              $displayNames = [];
              foreach ($allowedBasenames as $bn) {
                  if (isset($moduleMapping[$bn])) {
                      $displayNames[] = $moduleMapping[$bn];
                  }
              }
              $modulesDisplay = !empty($displayNames) ? implode(', ', $displayNames) : 'None';
            ?>
              <tr>
                <td style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($p['admin_name']) ?></td>
                <td style="font-family:monospace; color:var(--gold-400); font-weight:700; font-size:13px;"><?= htmlspecialchars($p['passcode']) ?></td>
                <td style="color:var(--text-secondary); font-size:12px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($modulesDisplay) ?>"><?= htmlspecialchars($modulesDisplay) ?></td>
                <td style="text-align:center;">
                  <?php
                    $badgeClass = match((int)$p['allow_edit']) {
                      0 => 'danger',
                      1 => 'warning',
                      2 => 'info',
                      3 => 'active',
                      default => 'danger'
                    };
                    $badgeLabel = match((int)$p['allow_edit']) {
                      0 => 'View',
                      1 => 'Input Once',
                      2 => 'Edit',
                      3 => 'Dynamic',
                      default => 'View'
                    };
                  ?>
                  <span class="status-badge <?= $badgeClass ?>" style="font-size:9.5px; padding: 4px 8px; border-radius:4px;">
                    <?= $badgeLabel ?>
                  </span>
                </td>
                <td style="color:var(--text-secondary); font-size:13px;"><?= date('d M Y, H:i', strtotime($p['expires_at'])) ?></td>
                <td>
                  <span class="status-badge <?= $isExpired ? 'danger' : 'active' ?>" style="font-size:9.5px;">
                    <?= $isExpired ? 'Expired' : 'Active' ?>
                  </span>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <button type="button" class="btn-icon edit" onclick="openEditAccessModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['admin_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($p['allowed_modules'], ENT_QUOTES, 'UTF-8') ?>', <?= $p['allow_edit'] ?>)" title="Edit Access Permissions" style="margin-right:4px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  </button>
                  <button type="button" class="btn-icon del" onclick="revokePasscode(<?= $p['id'] ?>)" title="Revoke Passcode">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Tab 4: Event Registration Control -->
<div class="tab-content" id="tab-reg-control" style="display:none;">
  <div style="text-align:center; margin-bottom:24px;">
    <h3 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:18px; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px;">Championship Registration Control</h3>
  </div>

  <form onsubmit="saveRegControl(event)">
    <input type="hidden" name="action" value="update_reg_control">
    
    <div style="max-width:600px; margin:0 auto 24px auto; background:#0c0e14; border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:20px;">
      
      <!-- 1. Start Event Registration -->
      <div style="border-bottom:1px solid rgba(255,255,255,0.06); padding-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div>
            <h4 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:16px; color:var(--gold-400); font-weight:700; text-transform:uppercase;">1. Start Event Registration</h4>
            <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Enable and set start date &amp; time for participant registrations.</div>
          </div>
          <label class="switch-toggle">
            <input type="checkbox" name="reg_start_active" value="1" <?= ($eventInfo['reg_start_active'] ?? 0) ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <div class="form-group" style="max-width:280px;">
          <label>Registration Start Date &amp; Time</label>
          <div class="input-wrap">
            <input type="datetime-local" name="reg_start_date" value="<?= !empty($eventInfo['reg_start_date']) ? date('Y-m-d\TH:i', strtotime($eventInfo['reg_start_date'])) : '' ?>" style="padding: 10px 12px; font-family: inherit;">
          </div>
        </div>
      </div>

      <!-- 2. Triple Entry (Late Entry Fine) -->
      <div style="border-bottom:1px solid rgba(255,255,255,0.06); padding-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div>
            <h4 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:16px; color:var(--gold-400); font-weight:700; text-transform:uppercase;">2. Triple Entry (Late Entry Fine)</h4>
            <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Turn on triple fees for registrations received on or after this date &amp; time.</div>
          </div>
          <label class="switch-toggle">
            <input type="checkbox" name="triple_entry_active" value="1" <?= ($eventInfo['triple_entry_active'] ?? 0) ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <div class="form-group" style="max-width:280px;">
          <label>Triple Entry Start Date &amp; Time</label>
          <div class="input-wrap">
            <input type="datetime-local" name="triple_entry_date" value="<?= !empty($eventInfo['triple_entry_date']) ? date('Y-m-d\TH:i', strtotime($eventInfo['triple_entry_date'])) : '' ?>" style="padding: 10px 12px; font-family: inherit;">
          </div>
        </div>
      </div>

      <!-- 3. End Event Registration -->
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div>
            <h4 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:16px; color:var(--gold-400); font-weight:700; text-transform:uppercase;">3. End Event Registration</h4>
            <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Close event registrations automatically on or after this date &amp; time.</div>
          </div>
          <label class="switch-toggle">
            <input type="checkbox" name="reg_end_active" value="1" <?= ($eventInfo['reg_end_active'] ?? 0) ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </div>
        <div class="form-group" style="max-width:280px;">
          <label>Registration End Date &amp; Time</label>
          <div class="input-wrap">
            <input type="datetime-local" name="reg_end_date" value="<?= !empty($eventInfo['reg_end_date']) ? date('Y-m-d\TH:i', strtotime($eventInfo['reg_end_date'])) : '' ?>" style="padding: 10px 12px; font-family: inherit;">
          </div>
        </div>
      </div>

    </div>

    <div style="text-align:center; margin-bottom:40px;">
      <button type="submit" class="btn btn-primary" style="width:auto; padding:12px 32px;">Save Registration Control Settings</button>
    </div>
  </form>
</div>

<!-- Add Custom Field Modal -->
<div class="modal-overlay" id="customFieldModal">
  <div class="modal" style="max-width:450px;">
    <div class="modal-header">
      <h3>Add Custom Field</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="customFieldForm" onsubmit="addCustomField(event)">
      <input type="hidden" name="action" value="add_custom_field">
      <input type="hidden" id="cf_form_name" name="form_name" value="">
      
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Field Label / Title <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" name="field_label" required placeholder="e.g. Passport Number, Club ID" maxlength="80">
          </div>
        </div>
        <div class="form-group">
          <label class="custom-checkbox" style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="is_mandatory" value="1">
            <span style="font-size:13px; color:var(--text-primary); font-weight:600;">Mark as Mandatory (Required)</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Add Field</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Passcode Access Modal -->
<div class="modal-overlay" id="editPasscodeModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h3>Edit Admin Passcode Access</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="editPasscodeForm" onsubmit="savePasscodePermissions(event)">
      <input type="hidden" name="action" value="update_passcode_permissions">
      <input type="hidden" id="edit_passcode_id" name="id">
      
      <div class="modal-body">
        <div style="font-size:14px; font-weight:600; margin-bottom:14px; color:var(--gold-400);">
          Admin User: <span id="edit_passcode_admin_name" style="color:var(--text-primary);"></span>
        </div>
        
        <div class="form-group" style="margin-bottom:20px;">
          <label style="margin-bottom:8px; display:block;">Grant Module Permissions <span class="req">*</span></label>
          <div style="display:grid; grid-template-columns:1fr; gap:10px;">
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_registrations" value="registrations.php">
              <span style="font-size:13px; color:var(--text-primary);">Registrations Management</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_event_registrations" value="event_registrations.php">
              <span style="font-size:13px; color:var(--text-primary);">Event Registrations</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_clubs" value="clubs.php">
              <span style="font-size:13px; color:var(--text-primary);">Clubs Management</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_payment_sessions" value="payment_sessions.php">
              <span style="font-size:13px; color:var(--text-primary);">Payment Verification</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_lane_allocations" value="lane_allocations.php">
              <span style="font-size:13px; color:var(--text-primary);">Lane Allocations</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_start_sheet" value="start_sheet.php">
              <span style="font-size:13px; color:var(--text-primary);">Start Lists</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_team_events" value="team_events.php">
              <span style="font-size:13px; color:var(--text-primary);">Team Events</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_rank_list" value="rank_list.php">
              <span style="font-size:13px; color:var(--text-primary);">Rank List</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_landing_news" value="../ssa-dashboard/manage.php">
              <span style="font-size:13px; color:var(--text-primary);">Landing News/Events</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_manage_updates" value="manage_updates.php">
              <span style="font-size:13px; color:var(--text-primary);">Updates &amp; Results</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_profile_change_requests" value="profile_change_requests.php">
              <span style="font-size:13px; color:var(--text-primary);">Support Requests</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="modules[]" id="edit_mod_document_editor" value="document_editor.php">
              <span style="font-size:13px; color:var(--text-primary);">Document Designer</span>
            </label>
          </div>
        </div>

        <div class="form-group">
          <label>Write/Edit Permissions</label>
          <div style="margin-top:8px; display:flex; flex-direction:column; gap:8px;">
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="0" class="edit-perm-chk" onchange="selectEditPermission(this)">
              <span style="font-size:13px; color:var(--text-primary);">View</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="1" class="edit-perm-chk" onchange="selectEditPermission(this)">
              <span style="font-size:13px; color:var(--text-primary);">Input Once</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="2" class="edit-perm-chk" onchange="selectEditPermission(this)">
              <span style="font-size:13px; color:var(--text-primary);">Edit</span>
            </label>
            <label class="custom-checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="allow_edit_opt" value="3" class="edit-perm-chk" onchange="selectEditPermission(this)">
              <span style="font-size:13px; color:var(--text-primary); font-weight:600;">Dynamic Access</span>
            </label>
            <input type="hidden" name="allow_edit" id="edit_passcode_allow_edit" value="0">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Save Permissions</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.style.borderColor = 'transparent';
        btn.style.color = 'var(--text-muted)';
    });
    
    document.getElementById(tabId).style.display = 'block';
    
    const activeBtn = document.getElementById('btn-' + tabId);
    activeBtn.style.borderColor = 'var(--gold-400)';
    activeBtn.style.color = 'var(--gold-400)';
}

function openCustomFieldModal(formName) {
    document.getElementById('cf_form_name').value = formName;
    document.getElementById('customFieldForm').reset();
    openModal('customFieldModal');
}

async function saveFieldConfig(event, formName) {
    event.preventDefault();
    const fd = new FormData(event.target);
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function addCustomField(event) {
    event.preventDefault();
    const fd = new FormData(event.target);
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeModal('customFieldModal');
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function deleteCustomField(id) {
    if (!confirm('Are you sure you want to delete this custom field? This will delete all filled values for all users.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_custom_field');
    fd.append('id', id.toString());
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function generatePasscode(event) {
    event.preventDefault();
    const fd = new FormData(event.target);
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('generatedCode').textContent = data.passcode;
            document.getElementById('passcodeResult').style.display = 'block';
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            // Reload list section after delay to show generated passcode in right panel
            setTimeout(() => window.location.reload(), 2500);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function revokePasscode(id) {
    if (!confirm('Revoke and delete this passcode? The admin will immediately lose temporary access.')) return;
    const fd = new FormData();
    fd.append('action', 'revoke_passcode');
    fd.append('id', id.toString());
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

function selectPermission(el) {
    if (el.checked) {
        document.querySelectorAll('.perm-chk').forEach(c => {
            if (c !== el) c.checked = false;
        });
        document.getElementById('allow_edit_value').value = el.value;
    } else {
        el.checked = false;
        const viewChk = document.querySelector('.perm-chk[value="0"]');
        if (viewChk) viewChk.checked = true;
        document.getElementById('allow_edit_value').value = "0";
    }
}

function selectEditPermission(el) {
    if (el.checked) {
        document.querySelectorAll('.edit-perm-chk').forEach(c => {
            if (c !== el) c.checked = false;
        });
        document.getElementById('edit_passcode_allow_edit').value = el.value;
    } else {
        el.checked = false;
        const viewChk = document.querySelector('.edit-perm-chk[value="0"]');
        if (viewChk) viewChk.checked = true;
        document.getElementById('edit_passcode_allow_edit').value = "0";
    }
}

function openEditAccessModal(id, adminName, allowedModulesCsv, allowEdit) {
    document.getElementById('edit_passcode_id').value = id;
    document.getElementById('edit_passcode_admin_name').textContent = adminName;
    
    const allowed = allowedModulesCsv.split(',');
    
    document.getElementById('edit_mod_registrations').checked = allowed.includes('registrations.php');
    document.getElementById('edit_mod_event_registrations').checked = allowed.includes('event_registrations.php');
    document.getElementById('edit_mod_clubs').checked = allowed.includes('clubs.php');
    document.getElementById('edit_mod_payment_sessions').checked = allowed.includes('payment_sessions.php');
    document.getElementById('edit_mod_lane_allocations').checked = allowed.includes('lane_allocations.php');
    document.getElementById('edit_mod_start_sheet').checked = allowed.includes('start_sheet.php');
    document.getElementById('edit_mod_team_events').checked = allowed.includes('team_events.php');
    document.getElementById('edit_mod_rank_list').checked = allowed.includes('rank_list.php');
    document.getElementById('edit_mod_landing_news').checked = allowed.includes('../ssa-dashboard/manage.php');
    document.getElementById('edit_mod_manage_updates').checked = allowed.includes('manage_updates.php');
    document.getElementById('edit_mod_profile_change_requests').checked = allowed.includes('profile_change_requests.php');
    document.getElementById('edit_mod_document_editor').checked = allowed.includes('document_editor.php');
    
    const pVal = parseInt(allowEdit) || 0;
    document.getElementById('edit_passcode_allow_edit').value = pVal.toString();
    document.querySelectorAll('.edit-perm-chk').forEach(c => {
        c.checked = (parseInt(c.value) === pVal);
    });
    
    openModal('editPasscodeModal');
}

async function savePasscodePermissions(event) {
    event.preventDefault();
    const fd = new FormData(event.target);
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeModal('editPasscodeModal');
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function saveRegControl(event) {
    event.preventDefault();
    const fd = new FormData(event.target);
    try {
        const resp = await fetch('actions/control_panel_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

// Vintage clock removed
</script>

<?php require_once 'includes/footer.php'; ?>
