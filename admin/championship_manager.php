<?php
/**
 * admin/championship_manager.php
 * Championship Manager Module for Supreme Admin.
 * Handles Multi-Championship Management, Wizards, Cloning, Activation, Archiving, and History.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin / supremeadmin access

$pageTitle = 'Championship Manager';
require_once 'includes/header.php';

$pdo = getDB();
$activeChampionship = getActiveChampionship($pdo);
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>



<!-- Championship Band -->
<div class="championship-band">
  <?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?> &bull; TARGET (Tournament Administration and Registration Gateway for Event Tracking) &bull; Chennai
</div>

<!-- Page Header & Action Controls -->
<div class="admin-page-header" style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
  <div>
    <div class="admin-page-title" style="display:flex; align-items:center; gap:10px;">
      <i class="bi bi-trophy-fill" style="color:var(--gold-400);"></i>
      <span>Championship Manager</span>
    </div>
    <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Manage multi-championship competitions, active portal context, cloning, and archiving</div>
  </div>

  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <!-- Create Championship Button -->
    <button type="button" class="btn" onclick="openCreateWizardModal()" style="font-size:13px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; padding:10px 22px; background:linear-gradient(135deg, #d4af37 0%, #aa882c 100%); border:none; color:#000; font-weight:800; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 16px rgba(212,175,55,0.35); transition:all 0.25s ease;">
      <i class="bi bi-plus-circle-fill" style="font-size:15px;"></i> Create Championship
    </button>
  </div>
</div>

<!-- Active Championship Hero Card -->
<div style="background: linear-gradient(135deg, rgba(20,20,28,0.95) 0%, rgba(30,26,18,0.95) 100%); border: 1px solid rgba(212,175,55,0.4); border-radius: 14px; padding: 24px; margin-bottom: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position:relative; overflow:hidden;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <span class="user-id-badge" style="font-size:11px; background:var(--gold-400); color:#000; font-weight:800;">
        <i class="bi bi-star-fill"></i> CURRENT ACTIVE CHAMPIONSHIP
      </span>
      <h2 style="font-family:'Rajdhani',sans-serif; font-size: 26px; font-weight:700; color:#fff; margin:10px 0 6px;">
        <span id="activeName"><?= htmlspecialchars($activeChampionship['championship_name']) ?></span>
      </h2>
      <div style="font-size:13px; color:rgba(255,255,255,0.7); display:flex; gap:16px; flex-wrap:wrap; margin-top:8px;">
        <span><i class="bi bi-calendar-check" style="color:var(--gold-400);"></i> Year: <strong><?= htmlspecialchars($activeChampionship['championship_year']) ?></strong></span>
        <span><i class="bi bi-clock-history" style="color:var(--gold-400);"></i> Registration: <strong><?= htmlspecialchars($activeChampionship['registration_open'] ?? 'N/A') ?> to <?= htmlspecialchars($activeChampionship['registration_close'] ?? 'N/A') ?></strong></span>
        <span><i class="bi bi-bullseye" style="color:var(--gold-400);"></i> Competition: <strong><?= htmlspecialchars($activeChampionship['event_start'] ?? 'N/A') ?> to <?= htmlspecialchars($activeChampionship['event_end'] ?? 'N/A') ?></strong></span>
      </div>
    </div>
    <div style="text-align:right;">
      <span class="status-badge active" style="font-size:12px; padding:6px 14px;">ACTIVE</span>
      <div style="font-size:11px; color:var(--text-muted); margin-top:8px;">All public pages automatically display this championship</div>
    </div>
  </div>
</div>

<!-- Message Alert Box -->
<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Championship History Section Title -->
<div style="margin-bottom: 16px; display:flex; justify-content:space-between; align-items:center;">
  <h3 style="font-family:'Rajdhani',sans-serif; font-size:20px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:0.5px;">
    <i class="bi bi-clock-history" style="color:var(--gold-400); margin-right:8px;"></i> Championship History &amp; Operations
  </h3>
  <span id="historyCount" style="font-size:12px; color:var(--text-muted);">Loading championships...</span>
</div>

<!-- Championship Table -->
<div class="table-wrap" style="background: rgba(18,18,26,0.75); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; margin-bottom: 32px;">
  <table class="admin-table" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="width:50px;">ID</th>
        <th>Championship Name</th>
        <th style="width:70px; text-align:center;">Year</th>
        <th style="width:100px; text-align:center;">Status</th>
        <th style="width:150px;">Registration Dates</th>
        <th style="width:150px;">Competition Dates</th>
        <th style="width:90px; text-align:center;">Entries</th>
        <th style="width:120px; text-align:center;">State Action</th>
        <th style="width:125px; text-align:center;">Backup</th>
        <th style="width:100px; text-align:center;">Clone</th>
        <th style="width:60px; text-align:center;">Delete</th>
      </tr>
    </thead>
    <tbody id="championshipsTableBody">
      <tr>
        <td colspan="11" style="text-align:center; padding:40px; color:var(--text-muted);">
          <div class="spinner-border text-warning" role="status" style="width:2rem; height:2rem; margin-bottom:10px;"></div>
          <div>Loading Championship History...</div>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Create Championship Wizard Modal -->
<div class="modal-overlay" id="createModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1200; backdrop-filter:blur(4px); justify-content:center; align-items:center;">
  <div class="modal" style="background:var(--dark-900, #14141c); border:1px solid rgba(212,175,55,0.3); width:100%; max-width:620px; border-radius:12px; overflow:hidden; box-shadow:0 15px 40px rgba(0,0,0,0.8);">
    <div class="modal-header" style="padding:16px 20px; background:rgba(255,255,255,0.03); border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:18px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
        <i class="bi bi-plus-circle-fill" style="color:var(--gold-400);"></i> Create New Championship Wizard
      </h3>
      <button type="button" onclick="closeCreateModal()" style="background:none; border:none; color:#aaa; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
    </div>
    <form id="createForm" onsubmit="saveNewChampionship(event)">
      <div class="modal-body" style="padding:20px;">
        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Championship Name <span style="color:#e74c3c;">*</span></label>
          <input type="text" name="championship_name" required placeholder="e.g. 52nd Tamil Nadu State Shooting Championship" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Year <span style="color:#e74c3c;">*</span></label>
            <input type="text" name="championship_year" required value="<?= date('Y') + 1 ?>" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Initial Status</label>
            <select name="status" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
              <option value="DRAFT">DRAFT</option>
              <option value="ACTIVE">ACTIVE</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Registration Open Date</label>
            <input type="date" name="registration_open" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Registration Close Date</label>
            <input type="date" name="registration_close" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Competition Start Date</label>
            <input type="date" name="event_start" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Competition End Date</label>
            <input type="date" name="event_end" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
        </div>

        <div class="form-group">
          <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Portal Theme</label>
          <select name="theme" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
            <option value="gold_dark">Gold &amp; Dark (SSA Standard)</option>
            <option value="navy_gold">Navy &amp; Gold</option>
            <option value="emerald_dark">Emerald Dark</option>
          </select>
        </div>
      </div>
      <div style="padding:14px 20px; background:rgba(0,0,0,0.3); border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeCreateModal()" style="background:#444; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">Cancel</button>
        <button type="submit" class="btn btn-primary" style="font-size:13px; padding:8px 22px;">Create Championship</button>
      </div>
    </form>
  </div>
</div>

<!-- Clone Championship Wizard Modal -->
<div class="modal-overlay" id="cloneModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1200; backdrop-filter:blur(4px); justify-content:center; align-items:center;">
  <div class="modal" style="background:var(--dark-900, #14141c); border:1px solid rgba(155,89,182,0.4); width:100%; max-width:640px; border-radius:12px; overflow:hidden; box-shadow:0 15px 40px rgba(0,0,0,0.8);">
    <div class="modal-header" style="padding:16px 20px; background:rgba(255,255,255,0.03); border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-family:'Rajdhani',sans-serif; font-size:18px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
        <i class="bi bi-copy" style="color:#9b59b6;"></i> Clone Previous Championship Wizard
      </h3>
      <button type="button" onclick="closeCloneModal()" style="background:none; border:none; color:#aaa; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
    </div>
    <form id="cloneForm" onsubmit="saveClonedChampionship(event)">
      <div class="modal-body" style="padding:20px;">
        <div style="background:rgba(155,89,182,0.12); border:1px solid rgba(155,89,182,0.3); padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:12px; color:#e0b0ff; line-height:1.4;">
          <i class="bi bi-info-circle-fill"></i> <strong>Cloning Rule:</strong> Copies structural definitions ONLY (Events, Age Categories, Fees, Document Templates, Settings, Theme). <span style="text-decoration:underline;">DO NOT COPY</span> Participants, Registrations, Payments, Lane Allocations, or Results.
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Select Source Championship to Clone From <span style="color:#e74c3c;">*</span></label>
          <select id="cloneSourceSelect" name="source_championship_id" required style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;"></select>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">New Championship Name <span style="color:#e74c3c;">*</span></label>
          <input type="text" name="championship_name" required placeholder="e.g. 52nd Tamil Nadu State Shooting Championship" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">New Year <span style="color:#e74c3c;">*</span></label>
            <input type="text" name="championship_year" required value="<?= date('Y') + 1 ?>" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Theme</label>
            <select name="theme" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
              <option value="gold_dark">Gold &amp; Dark (SSA Standard)</option>
              <option value="navy_gold">Navy &amp; Gold</option>
              <option value="emerald_dark">Emerald Dark</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Registration Open</label>
            <input type="date" name="registration_open" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Registration Close</label>
            <input type="date" name="registration_close" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Competition Start</label>
            <input type="date" name="event_start" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
          <div class="form-group">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px;">Competition End</label>
            <input type="date" name="event_end" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 12px; border-radius:6px; font-size:13px; outline:none;">
          </div>
        </div>
      </div>
      <div style="padding:14px 20px; background:rgba(0,0,0,0.3); border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeCloneModal()" style="background:#444; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">Cancel</button>
        <button type="submit" class="btn" style="background:#9b59b6; color:#fff; border:none; padding:8px 22px; border-radius:6px; cursor:pointer; font-weight:700; font-size:13px;">
          <i class="bi bi-copy"></i> Clone Championship Definitions
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let ALL_CHAMPIONSHIPS = [];

document.addEventListener('DOMContentLoaded', function() {
    loadChampionships();
});

function loadChampionships() {
    fetch('actions/championship_action.php?action=list')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                document.getElementById('championshipsTableBody').innerHTML = `<tr><td colspan="8" style="text-align:center; padding:30px; color:#e74c3c;">${res.message || 'Error loading championships.'}</td></tr>`;
                return;
            }

            ALL_CHAMPIONSHIPS = res.data || [];
            document.getElementById('historyCount').innerText = `Total Championships: ${ALL_CHAMPIONSHIPS.length}`;
            renderChampionshipsTable(ALL_CHAMPIONSHIPS);
            populateCloneSelect(ALL_CHAMPIONSHIPS);

            if (res.active_championship) {
                document.getElementById('activeName').innerText = res.active_championship.championship_name;
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            document.getElementById('championshipsTableBody').innerHTML = `<tr><td colspan="8" style="text-align:center; padding:30px; color:#e74c3c;">Server connection error. Please try again.</td></tr>`;
        });
}

function renderChampionshipsTable(list) {
    const tbody = document.getElementById('championshipsTableBody');
    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:40px; color:var(--text-muted);">No championships found.</td></tr>`;
        return;
    }

    let html = '';
    list.forEach(c => {
        let statusBadge = '';
        if (c.status === 'ACTIVE') {
            statusBadge = '<span class="status-badge active" style="font-size:11px; padding:4px 12px;">ACTIVE</span>';
        } else if (c.status === 'ARCHIVED') {
            statusBadge = '<span class="status-badge inactive" style="font-size:11px; padding:4px 12px; background:rgba(255,255,255,0.08); color:var(--text-muted);">ARCHIVED</span>';
        } else {
            statusBadge = '<span class="status-badge pending" style="font-size:11px; padding:4px 12px;">DRAFT</span>';
        }

        let regDates = (c.registration_open || c.registration_close) ? `${c.registration_open || 'N/A'} &rarr; ${c.registration_close || 'N/A'}` : 'Not set';
        let evtDates = (c.event_start || c.event_end) ? `${c.event_start || 'N/A'} &rarr; ${c.event_end || 'N/A'}` : 'Not set';

        html += `
        <tr id="c_row_${c.id}">
          <td style="font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; color:var(--gold-400);">${c.id}</td>
          <td style="font-weight:700; color:#fff;">
            ${escapeHtml(c.championship_name)}
            ${c.status === 'ACTIVE' ? ' <i class="bi bi-star-fill" style="color:var(--gold-400); font-size:12px;" title="Current Active Championship"></i>' : ''}
          </td>
          <td style="text-align:center; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px;">${escapeHtml(c.championship_year)}</td>
          <td style="text-align:center;">${statusBadge}</td>
          <td style="font-size:12px; color:var(--text-secondary);">${regDates}</td>
          <td style="font-size:12px; color:var(--text-secondary);">${evtDates}</td>
          <td style="text-align:center; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; color:var(--gold-400);">${c.total_registrations || 0}</td>
          
          <!-- Column 8: State Action (Activate / Archive) -->
          <td style="text-align:center;">
            ${c.status !== 'ACTIVE' ? `
              <button type="button" class="btn" onclick="activateChampionship(${c.id}, '${escapeJs(c.championship_name)}')" style="font-size:11.5px; font-family:'Rajdhani',sans-serif; padding:5px 12px; background:linear-gradient(135deg, #27ae60 0%, #1e8449 100%); color:#fff; border:none; font-weight:700; border-radius:6px; cursor:pointer; box-shadow:0 3px 10px rgba(39,174,96,0.3);" title="Set as Active Championship">
                <i class="bi bi-play-circle-fill"></i> Activate
              </button>
            ` : `
              <button type="button" class="btn" onclick="archiveChampionship(${c.id})" style="font-size:11.5px; font-family:'Rajdhani',sans-serif; padding:5px 12px; background:rgba(255,255,255,0.08); color:var(--text-muted); border:1px solid rgba(255,255,255,0.15); font-weight:600; border-radius:6px; cursor:pointer;" title="Move to Archive">
                <i class="bi bi-archive-fill"></i> Archive
              </button>
            `}
          </td>

          <!-- Column 9: Backup Data -->
          <td style="text-align:center;">
            <button type="button" class="btn" onclick="downloadBackup(${c.id})" style="font-size:11.5px; font-family:'Rajdhani',sans-serif; padding:5px 12px; background:rgba(212,175,55,0.15); color:var(--gold-400); border:1px solid rgba(212,175,55,0.4); font-weight:700; border-radius:6px; cursor:pointer; white-space:nowrap;" title="Download Championship Data Backup">
              <i class="bi bi-download"></i> Backup Data
            </button>
          </td>

          <!-- Column 10: Clone Definitions -->
          <td style="text-align:center;">
            <button type="button" class="btn" onclick="openCloneWizardModal(${c.id})" style="font-size:11.5px; font-family:'Rajdhani',sans-serif; padding:5px 12px; background:rgba(155,89,182,0.18); color:#e0b0ff; border:1px solid rgba(155,89,182,0.4); font-weight:700; border-radius:6px; cursor:pointer;" title="Clone Events & Rules">
              <i class="bi bi-copy"></i> Clone
            </button>
          </td>

          <!-- Column 11: Delete Empty Championship -->
          <td style="text-align:center;">
            <button type="button" class="btn-icon del" onclick="deleteChampionship(${c.id}, '${escapeJs(c.championship_name)}')" style="background:rgba(231,76,60,0.15); border:1px solid rgba(231,76,60,0.35); color:#e74c3c; width:30px; height:30px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer;" title="Delete Empty Championship">
              <i class="bi bi-trash-fill"></i>
            </button>
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function downloadBackup(id) {
    window.location.href = `actions/championship_action.php?action=backup&id=${id}`;
}

function populateCloneSelect(list) {
    const sel = document.getElementById('cloneSourceSelect');
    if (!sel) return;
    let html = '';
    list.forEach(c => {
        html += `<option value="${c.id}">${escapeHtml(c.championship_name)} (${c.championship_year}) - ${c.status}</option>`;
    });
    sel.innerHTML = html;
}

// ── Create Wizard Modal ──────────────────────────────────────
function openCreateWizardModal() {
    document.getElementById('createForm').reset();
    const m = document.getElementById('createModal');
    if (m) {
        m.classList.add('active');
        m.style.display = 'flex';
    }
}
function closeCreateModal() {
    const m = document.getElementById('createModal');
    if (m) {
        m.classList.remove('active');
        m.style.display = 'none';
    }
}
function saveNewChampionship(evt) {
    evt.preventDefault();
    const formData = new FormData(document.getElementById('createForm'));
    formData.append('action', 'create');

    fetch('actions/championship_action.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeCreateModal();
            Swal.fire({ icon: 'success', title: 'Created!', text: res.message, timer: 1500, showConfirmButton: false });
            loadChampionships();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    })
    .catch(err => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to create championship.' });
    });
}

// ── Clone Wizard Modal ───────────────────────────────────────
function openCloneWizardModal(sourceId = null) {
    const m = document.getElementById('cloneModal');
    if (sourceId) {
        document.getElementById('cloneSourceSelect').value = sourceId;
    }
    if (m) {
        m.classList.add('active');
        m.style.display = 'flex';
    }
}
function closeCloneModal() {
    const m = document.getElementById('cloneModal');
    if (m) {
        m.classList.remove('active');
        m.style.display = 'none';
    }
}
function saveClonedChampionship(evt) {
    evt.preventDefault();
    const formData = new FormData(document.getElementById('cloneForm'));
    formData.append('action', 'clone');

    Swal.fire({
        title: 'Cloning Championship...',
        text: 'Duplicating events, rules, and document templates...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch('actions/championship_action.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeCloneModal();
            Swal.fire('Cloned!', res.message, 'success');
            loadChampionships();
        } else {
            Swal.fire('Cloning Error', res.message, 'error');
        }
    })
    .catch(err => {
        Swal.fire('Error', 'Server communication failure.', 'error');
    });
}

// ── Activate Championship ─────────────────────────────────────
function activateChampionship(id, name) {
    Swal.fire({
        title: 'Activate Championship?',
        text: `Set '${name}' as the current ACTIVE championship across the entire portal?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#444',
        confirmButtonText: 'Yes, Set Active'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'activate');
            formData.append('id', id);

            fetch('actions/championship_action.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Activated!', text: res.message, timer: 1500, showConfirmButton: false });
                    loadChampionships();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            });
        }
    });
}

// ── Archive Championship ──────────────────────────────────────
function archiveChampionship(id) {
    const formData = new FormData();
    formData.append('action', 'archive');
    formData.append('id', id);

    fetch('actions/championship_action.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Archived', text: res.message, timer: 1200, showConfirmButton: false });
            loadChampionships();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    });
}

// ── Delete Championship ───────────────────────────────────────
function deleteChampionship(id, name) {
    Swal.fire({
        title: 'Delete Championship?',
        text: `Are you sure you want to delete '${name}'? Only championships with 0 registrations/results can be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#444',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('actions/championship_action.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1200, showConfirmButton: false });
                    loadChampionships();
                } else {
                    Swal.fire({ icon: 'error', title: 'Deletion Blocked', text: res.message });
                }
            });
        }
    });
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function escapeJs(str) {
    return String(str || '').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
</script>

<?php require_once 'includes/footer.php'; ?>
