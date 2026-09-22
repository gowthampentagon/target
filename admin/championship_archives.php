<?php
/**
 * admin/championship_archives.php
 * Championship Archives & Historical Data Backup Vault for Supreme Admin.
 * Inspect, filter, and export backed-up historical records of ended championships.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin / supremeadmin access

$pageTitle = 'Championship Archives & Data Vault';
require_once 'includes/header.php';
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>



<!-- Championship Band -->
<div class="championship-band">Championship Archives Vault &bull; Saragarhi Shooting Academy &bull; Chennai</div>

<!-- Page Header & Championship Selector -->
<div class="admin-page-header" style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
  <div>
    <div class="admin-page-title" style="display:flex; align-items:center; gap:10px;">
      <i class="bi bi-box-seam-fill" style="color:var(--gold-400);"></i>
      <span>Championship Archives &amp; Backup Vault</span>
    </div>
    <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Browse, inspect, and export immutable historical data backups of ended championships</div>
  </div>

  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <!-- Championship Selector Dropdown -->
    <div style="display:flex; align-items:center; gap:8px;">
      <label for="championshipSelector" style="font-size:12px; font-weight:700; font-family:'Rajdhani',sans-serif; text-transform:uppercase; color:var(--gold-400); white-space:nowrap;">
        <i class="bi bi-clock-history"></i> Select Championship:
      </label>
      <select id="championshipSelector" onchange="loadArchiveData()" style="min-width:260px; font-weight:700; background:rgba(18,18,26,0.95); border:1.5px solid rgba(212,175,55,0.4); color:#fff; padding:8px 14px; border-radius:8px; outline:none; cursor:pointer;"></select>
    </div>

    <!-- Download Backup Archive Button -->
    <button type="button" class="btn btn-primary" onclick="exportCurrentArchive()" style="font-size:12.5px; padding:8px 18px; display:inline-flex; align-items:center; gap:6px;">
      <i class="bi bi-file-earmark-excel-fill"></i> Export Data Backup (Spreadsheet)
    </button>
  </div>
</div>

<!-- Historical Championship Metrics Grid -->
<div class="dash-grid" style="margin-bottom: 28px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); display:grid; gap:16px;">
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(212,175,55,0.15); color:var(--gold-400);"><i class="bi bi-people-fill"></i></div>
    <div class="stat-info">
      <h4>Total Registrations</h4>
      <div class="val" id="metricRegs">0</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(155,89,182,0.15); color:#9b59b6;"><i class="bi bi-trophy-fill"></i></div>
    <div class="stat-info">
      <h4>Score Sheets</h4>
      <div class="val" id="metricScores">0</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(52,152,219,0.15); color:#3498db;"><i class="bi bi-file-earmark-pdf-fill"></i></div>
    <div class="stat-info">
      <h4>Certificates Issued</h4>
      <div class="val" id="metricCerts">0</div>
    </div>
  </div>
</div>

<!-- Tab Navigation Bar -->
<div style="display:flex; border-bottom:1px solid rgba(255,255,255,0.1); margin-bottom:24px; gap:8px; flex-wrap:wrap;">
  <button type="button" class="tab-btn active" id="tab_regs" onclick="switchArchiveTab('regs')" style="padding:10px 18px; background:none; border:none; border-bottom:3px solid var(--gold-400); color:#fff; font-weight:700; font-family:'Rajdhani',sans-serif; font-size:14px; text-transform:uppercase; cursor:pointer;">
    <i class="bi bi-people-fill"></i> Registrations (<span id="count_regs">0</span>)
  </button>
  <button type="button" class="tab-btn" id="tab_scores" onclick="switchArchiveTab('scores')" style="padding:10px 18px; background:none; border:none; border-bottom:3px solid transparent; color:var(--text-muted); font-weight:700; font-family:'Rajdhani',sans-serif; font-size:14px; text-transform:uppercase; cursor:pointer;">
    <i class="bi bi-award-fill"></i> Scores &amp; Results (<span id="count_scores">0</span>)
  </button>
  <button type="button" class="tab-btn" id="tab_certs" onclick="switchArchiveTab('certs')" style="padding:10px 18px; background:none; border:none; border-bottom:3px solid transparent; color:var(--text-muted); font-weight:700; font-family:'Rajdhani',sans-serif; font-size:14px; text-transform:uppercase; cursor:pointer;">
    <i class="bi bi-file-earmark-pdf-fill"></i> Certificates (<span id="count_certs">0</span>)
  </button>
  <button type="button" class="tab-btn" id="tab_events" onclick="switchArchiveTab('events')" style="padding:10px 18px; background:none; border:none; border-bottom:3px solid transparent; color:var(--text-muted); font-weight:700; font-family:'Rajdhani',sans-serif; font-size:14px; text-transform:uppercase; cursor:pointer;">
    <i class="bi bi-list-stars"></i> Events Directory (<span id="count_events">0</span>)
  </button>
</div>

<!-- Tab 1: Registrations Backup Table -->
<div id="pane_regs" class="tab-pane" style="display:block;">
  <!-- Filter Bar for Registrations -->
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; background:rgba(22,22,32,0.85); padding:14px 18px; border-radius:12px; border:1px solid rgba(255,255,255,0.08);">
    <div style="flex:1; min-width:220px; position:relative;">
      <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px;"></i>
      <input type="text" id="search_regs" placeholder="Search by Bib, Name, Club, Event..." oninput="applyRegsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px 9px 36px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
    </div>
    <div style="width:170px;">
      <select id="filter_regs_category" onchange="applyRegsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Categories</option>
      </select>
    </div>
    <div style="width:200px;">
      <select id="filter_regs_club" onchange="applyRegsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Clubs / States</option>
      </select>
    </div>
    <div style="width:240px;">
      <select id="filter_regs_event" onchange="applyRegsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Events</option>
      </select>
    </div>
    <button type="button" onclick="resetRegsFilter()" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:var(--text-muted); padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
      <i class="bi bi-x-circle"></i> Reset
    </button>
  </div>

  <div class="table-wrap" style="background: rgba(18,18,26,0.75); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Shooter BIB / ID</th>
          <th>Shooter Name</th>
          <th>Club / State</th>
          <th>Event Code &amp; Name</th>
          <th style="text-align:center;">Category</th>
          <th style="text-align:right;">Fee</th>
          <th style="text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody id="tbl_regs_body">
        <tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">Loading registrations...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 2: Results & Score Sheets Backup Table -->
<div id="pane_scores" class="tab-pane" style="display:none;">
  <!-- Filter Bar for Scores -->
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; background:rgba(22,22,32,0.85); padding:14px 18px; border-radius:12px; border:1px solid rgba(255,255,255,0.08);">
    <div style="flex:1; min-width:220px; position:relative;">
      <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px;"></i>
      <input type="text" id="search_scores" placeholder="Search by Bib, Name, Relay, Event..." oninput="applyScoresFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px 9px 36px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
    </div>
    <div style="width:260px;">
      <select id="filter_scores_event" onchange="applyScoresFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Events</option>
      </select>
    </div>
    <div style="width:160px;">
      <select id="filter_scores_remarks" onchange="applyScoresFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Remarks</option>
      </select>
    </div>
    <button type="button" onclick="resetScoresFilter()" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:var(--text-muted); padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
      <i class="bi bi-x-circle"></i> Reset
    </button>
  </div>

  <div class="table-wrap" style="background: rgba(18,18,26,0.75); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Shooter BIB / ID</th>
          <th>Shooter Name</th>
          <th>Event Code &amp; Name</th>
          <th>Date &amp; Relay</th>
          <th style="text-align:center;">Lane No</th>
          <th style="text-align:right;">Grand Total Score</th>
          <th style="text-align:center;">Remarks</th>
        </tr>
      </thead>
      <tbody id="tbl_scores_body">
        <tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">Loading score sheets...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 5: Certificates Backup Table -->
<div id="pane_certs" class="tab-pane" style="display:none;">
  <!-- Filter Bar for Certificates -->
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; background:rgba(22,22,32,0.85); padding:14px 18px; border-radius:12px; border:1px solid rgba(255,255,255,0.08);">
    <div style="flex:1; min-width:220px; position:relative;">
      <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px;"></i>
      <input type="text" id="search_certs" placeholder="Search by Cert ID, Shooter Name, Event..." oninput="applyCertsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px 9px 36px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
    </div>
    <div style="width:200px;">
      <select id="filter_certs_category" onchange="applyCertsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Categories</option>
      </select>
    </div>
    <button type="button" onclick="resetCertsFilter()" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:var(--text-muted); padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
      <i class="bi bi-x-circle"></i> Reset
    </button>
  </div>

  <div class="table-wrap" style="background: rgba(18,18,26,0.75); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Cert ID</th>
          <th>Shooter Name</th>
          <th>Event Name</th>
          <th style="text-align:center;">Category</th>
          <th>Issued Date</th>
          <th style="text-align:right;">Document File</th>
        </tr>
      </thead>
      <tbody id="tbl_certs_body">
        <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--text-muted);">Loading certificates...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 6: Event Directory Snapshot Table -->
<div id="pane_events" class="tab-pane" style="display:none;">
  <!-- Filter Bar for Events -->
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; background:rgba(22,22,32,0.85); padding:14px 18px; border-radius:12px; border:1px solid rgba(255,255,255,0.08);">
    <div style="flex:1; min-width:220px; position:relative;">
      <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px;"></i>
      <input type="text" id="search_events" placeholder="Search by Event Code, Event Name..." oninput="applyEventsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px 9px 36px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
    </div>
    <div style="width:180px;">
      <select id="filter_events_category" onchange="applyEventsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Categories</option>
      </select>
    </div>
    <div style="width:180px;">
      <select id="filter_events_age" onchange="applyEventsFilter()" style="width:100%; background:#12121a; border:1px solid rgba(255,255,255,0.15); color:#fff; padding:9px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; box-sizing:border-box;">
        <option value="">All Age Groups</option>
      </select>
    </div>
    <button type="button" onclick="resetEventsFilter()" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:var(--text-muted); padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
      <i class="bi bi-x-circle"></i> Reset
    </button>
  </div>

  <div class="table-wrap" style="background: rgba(18,18,26,0.75); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden;">
    <table class="admin-table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Event ID</th>
          <th>Event Name</th>
          <th style="text-align:center;">Category</th>
          <th style="text-align:center;">Age Group</th>
          <th style="text-align:right;">Entry Fee</th>
          <th style="text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody id="tbl_events_body">
        <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--text-muted);">Loading events directory...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
let CURRENT_ARCHIVE_DATA = null;

document.addEventListener('DOMContentLoaded', function() {
    initChampionshipSelector();
});

function initChampionshipSelector() {
    fetch('actions/championship_archives_action.php?action=get_championships')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const sel = document.getElementById('championshipSelector');
            let html = '';
            (res.data || []).forEach(c => {
                html += `<option value="${c.id}">${escapeHtml(c.championship_name)} (${c.championship_year}) - ${c.status}</option>`;
            });
            sel.innerHTML = html;
            loadArchiveData();
        });
}

function loadArchiveData() {
    const cid = document.getElementById('championshipSelector').value || 1;
    
    fetch(`actions/championship_archives_action.php?action=get_archive_data&championship_id=${cid}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire('Error', res.message || 'Failed to load archive data.', 'error');
                return;
            }

            CURRENT_ARCHIVE_DATA = res;

            // Metrics Update
            const m = res.metrics || {};
            document.getElementById('metricRegs').innerText = m.total_registrations || 0;
            document.getElementById('metricScores').innerText = m.total_scores || 0;
            document.getElementById('metricCerts').innerText = m.total_certificates || 0;

            document.getElementById('count_regs').innerText = m.total_registrations || 0;
            document.getElementById('count_scores').innerText = m.total_scores || 0;
            document.getElementById('count_certs').innerText = m.total_certificates || 0;
            document.getElementById('count_events').innerText = m.total_events || 0;

            // Populate Dropdowns
            populateFilterDropdowns(res);

            // Render Tables
            applyRegsFilter();
            applyScoresFilter();
            applyCertsFilter();
            applyEventsFilter();
        });
}

function renderRegsTable(list) {
    const tbody = document.getElementById('tbl_regs_body');
    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">No registrations found in this archive.</td></tr>`;
        return;
    }
    let html = '';
    list.forEach(r => {
        let eventsHtml = '';
        if (r.events_list && r.events_list.length > 0) {
            eventsHtml = r.events_list.map(e => `
                <div style="margin-bottom:3px; font-size:12.5px;">
                    <strong style="color:var(--gold-400);">${escapeHtml(e.code || '')}</strong> &ndash; ${escapeHtml(e.name || '')}
                </div>
            `).join('');
        } else {
            eventsHtml = `<strong>${escapeHtml(r.event_code || '')}</strong> &ndash; ${escapeHtml(r.event_name || '')}`;
        }

        html += `
        <tr>
          <td style="font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--gold-400); font-size:14px;">${escapeHtml(r.shooter_bib || ('REG-' + r.user_id))}</td>
          <td style="font-weight:600; color:#fff;">${escapeHtml((r.first_name || '') + ' ' + (r.last_name || ''))}</td>
          <td>${escapeHtml(r.club_name || r.state || 'N/A')}</td>
          <td style="padding-top:8px; padding-bottom:8px;">${eventsHtml}</td>
          <td style="text-align:center;"><span style="font-size:11px; padding:3px 8px; border-radius:10px; background:rgba(212,175,55,0.15); color:var(--gold-400); font-weight:600;">${escapeHtml(r.category || 'NR')}</span></td>
          <td style="text-align:right; font-family:'Rajdhani',sans-serif; font-weight:700; color:#2ecc71; font-size:14px;">₹${parseFloat(r.entry_fee || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
          <td style="text-align:center;"><span class="status-badge active" style="font-size:10px;">${escapeHtml(r.status || 'approved')}</span></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function renderScoresTable(list) {
    const tbody = document.getElementById('tbl_scores_body');
    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">No score sheets found in this archive.</td></tr>`;
        return;
    }
    let html = '';
    list.forEach(s => {
        const dateStr = s.scheduled_date ? new Date(s.scheduled_date).toLocaleDateString('en-GB') : '—';
        const relayStr = s.relay_no ? `Relay ${s.relay_no}` : '—';
        const laneStr = (s.lane_no && parseInt(s.lane_no) > 0) ? `Lane ${s.lane_no}` : '—';
        const remarksBadge = (s.remarks && s.remarks !== 'Normal') ? `<span class="status-badge" style="font-size:10px; background:rgba(231,76,60,0.2); color:#e74c3c;">${escapeHtml(s.remarks)}</span>` : `<span style="font-size:11px; color:var(--text-muted);">Normal</span>`;
        
        let eventsHtml = '';
        if (s.events_list && s.events_list.length > 0) {
            eventsHtml = s.events_list.map(e => `
                <div style="margin-bottom:3px; font-size:12.5px;">
                    <strong style="color:var(--gold-400);">${escapeHtml(e.code || '')}</strong> &ndash; ${escapeHtml(e.name || '')}
                </div>
            `).join('');
        } else {
            eventsHtml = `<strong style="color:var(--gold-400);">${escapeHtml(s.resolved_code || s.event_code || '')}</strong> &ndash; ${escapeHtml(s.event_name || 'N/A')}`;
        }

        html += `
        <tr>
          <td style="font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--gold-400); font-size:14px;">${escapeHtml(s.shooter_bib || ('SCORE-' + s.id))}</td>
          <td style="font-weight:600; color:#fff;">${escapeHtml(s.shooter_name || 'N/A')}</td>
          <td style="padding-top:8px; padding-bottom:8px;">${eventsHtml}</td>
          <td style="font-size:13px;">${dateStr} <span style="color:var(--gold-400); font-weight:600;">(${relayStr})</span></td>
          <td style="text-align:center; font-weight:700; font-family:'Rajdhani',sans-serif;">${laneStr}</td>
          <td style="text-align:right; font-family:'Rajdhani',sans-serif; font-weight:800; font-size:16px; color:#2ecc71;">${parseFloat(s.grand_total_val || 0).toFixed(1)}</td>
          <td style="text-align:center;">${remarksBadge}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function renderCertsTable(list) {
    const tbody = document.getElementById('tbl_certs_body');
    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No certificates found in this archive.</td></tr>`;
        return;
    }
    let html = '';
    list.forEach(c => {
        html += `
        <tr>
          <td style="font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--gold-400);">CERT #${c.id}</td>
          <td style="font-weight:600; color:#fff;">${escapeHtml(c.first_name + ' ' + c.last_name)}</td>
          <td>${escapeHtml(c.event_name)}</td>
          <td style="text-align:center;">${escapeHtml(c.category)}</td>
          <td style="font-size:12px; color:var(--text-muted);">${escapeHtml(c.issued_at || 'N/A')}</td>
          <td style="text-align:right;">${c.certificate_path ? `<a href="../${escapeHtml(c.certificate_path)}" target="_blank" style="color:var(--gold-400); font-weight:700;">Download Certificate</a>` : 'N/A'}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function renderEventsTable(list) {
    const tbody = document.getElementById('tbl_events_body');
    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No events found in this snapshot.</td></tr>`;
        return;
    }
    let html = '';
    list.forEach(e => {
        html += `
        <tr>
          <td style="font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--gold-400);">${escapeHtml(e.event_code)}</td>
          <td style="font-weight:600; color:#fff;">${escapeHtml(e.event_name)}</td>
          <td style="text-align:center;">${escapeHtml(e.category)}</td>
          <td style="text-align:center;">${escapeHtml(e.age_group || 'Senior')}</td>
          <td style="text-align:right; font-family:'Rajdhani',sans-serif; font-weight:700;">₹${parseFloat(e.entry_fee || 0).toFixed(2)}</td>
          <td style="text-align:center;"><span class="status-badge active" style="font-size:10px;">${escapeHtml(e.status || 'active')}</span></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// ── Filter & Search Handlers ─────────────────────────────────────────

function populateFilterDropdowns(res) {
    // 1. Registrations Dropdowns
    const regs = res.registrations || [];
    const regCatSet = new Set();
    const regClubSet = new Set();
    const regEvtSet = new Set();

    regs.forEach(r => {
        if (r.category) regCatSet.add(r.category);
        const c = r.club_name || r.state;
        if (c && c !== 'N/A') regClubSet.add(c);
        (r.events_list || []).forEach(e => { if (e.name) regEvtSet.add(e.name); });
        if (r.event_name) regEvtSet.add(r.event_name);
    });

    populateSelect('filter_regs_category', Array.from(regCatSet).sort(), 'All Categories');
    populateSelect('filter_regs_club', Array.from(regClubSet).sort(), 'All Clubs / States');
    populateSelect('filter_regs_event', Array.from(regEvtSet).sort(), 'All Events');

    // 2. Scores Dropdowns
    const scores = res.score_sheets || [];
    const scoreEvtSet = new Set();
    const scoreRemSet = new Set();

    scores.forEach(s => {
        if (s.remarks) scoreRemSet.add(s.remarks);
        (s.events_list || []).forEach(e => { if (e.name) scoreEvtSet.add(e.name); });
        if (s.event_name) scoreEvtSet.add(s.event_name);
    });

    populateSelect('filter_scores_event', Array.from(scoreEvtSet).sort(), 'All Events');
    populateSelect('filter_scores_remarks', Array.from(scoreRemSet).sort(), 'All Remarks');

    // 3. Certs Dropdowns
    const certs = res.certificates || [];
    const certCatSet = new Set();
    certs.forEach(c => { if (c.category) certCatSet.add(c.category); });
    populateSelect('filter_certs_category', Array.from(certCatSet).sort(), 'All Categories');

    // 4. Events Dropdowns
    const evts = res.events || [];
    const evtCatSet = new Set();
    const evtAgeSet = new Set();
    evts.forEach(e => {
        if (e.category) evtCatSet.add(e.category);
        if (e.age_group) evtAgeSet.add(e.age_group);
    });
    populateSelect('filter_events_category', Array.from(evtCatSet).sort(), 'All Categories');
    populateSelect('filter_events_age', Array.from(evtAgeSet).sort(), 'All Age Groups');
}

function populateSelect(id, items, defaultLabel) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const currentVal = sel.value;
    let html = `<option value="">${defaultLabel}</option>`;
    items.forEach(item => {
        const selected = (item === currentVal) ? 'selected' : '';
        html += `<option value="${escapeHtml(item)}" ${selected}>${escapeHtml(item)}</option>`;
    });
    sel.innerHTML = html;
}

function applyRegsFilter() {
    if (!CURRENT_ARCHIVE_DATA || !CURRENT_ARCHIVE_DATA.registrations) return;
    const q = (document.getElementById('search_regs').value || '').toLowerCase().trim();
    const cat = document.getElementById('filter_regs_category').value;
    const club = document.getElementById('filter_regs_club').value;
    const evt = document.getElementById('filter_regs_event').value;

    const filtered = CURRENT_ARCHIVE_DATA.registrations.filter(r => {
        const fullName = ((r.first_name || '') + ' ' + (r.last_name || '')).toLowerCase();
        const bib = (r.shooter_bib || '').toLowerCase();
        const regId = (r.reg_id || '').toLowerCase();
        const cName = (r.club_name || r.state || '').toLowerCase();
        const eventsText = (r.events_list || []).map(e => e.code + ' ' + e.name).join(' ').toLowerCase();

        if (q && !fullName.includes(q) && !bib.includes(q) && !regId.includes(q) && !cName.includes(q) && !eventsText.includes(q)) {
            return false;
        }
        if (cat && (r.category || 'NR') !== cat) {
            return false;
        }
        if (club && (r.club_name || r.state || '') !== club) {
            return false;
        }
        if (evt) {
            const hasEvt = (r.events_list || []).some(e => e.name === evt || e.code === evt);
            if (!hasEvt && r.event_name !== evt && r.event_code !== evt) return false;
        }
        return true;
    });

    document.getElementById('count_regs').innerText = filtered.length;
    renderRegsTable(filtered);
}

function resetRegsFilter() {
    document.getElementById('search_regs').value = '';
    document.getElementById('filter_regs_category').value = '';
    document.getElementById('filter_regs_club').value = '';
    document.getElementById('filter_regs_event').value = '';
    applyRegsFilter();
}

function applyScoresFilter() {
    if (!CURRENT_ARCHIVE_DATA || !CURRENT_ARCHIVE_DATA.score_sheets) return;
    const q = (document.getElementById('search_scores').value || '').toLowerCase().trim();
    const evt = document.getElementById('filter_scores_event').value;
    const rem = document.getElementById('filter_scores_remarks').value;

    const filtered = CURRENT_ARCHIVE_DATA.score_sheets.filter(s => {
        const sName = (s.shooter_name || '').toLowerCase();
        const bib = (s.shooter_bib || '').toLowerCase();
        const relayStr = (s.relay_no ? 'relay ' + s.relay_no : '').toLowerCase();
        const eventsText = (s.events_list || []).map(e => e.code + ' ' + e.name).join(' ').toLowerCase();

        if (q && !sName.includes(q) && !bib.includes(q) && !relayStr.includes(q) && !eventsText.includes(q)) {
            return false;
        }
        if (evt) {
            const hasEvt = (s.events_list || []).some(e => e.name === evt || e.code === evt);
            if (!hasEvt && s.event_name !== evt && s.event_code !== evt) return false;
        }
        if (rem && (s.remarks || 'Normal') !== rem) {
            return false;
        }
        return true;
    });

    document.getElementById('count_scores').innerText = filtered.length;
    renderScoresTable(filtered);
}

function resetScoresFilter() {
    document.getElementById('search_scores').value = '';
    document.getElementById('filter_scores_event').value = '';
    document.getElementById('filter_scores_remarks').value = '';
    applyScoresFilter();
}

function applyCertsFilter() {
    if (!CURRENT_ARCHIVE_DATA || !CURRENT_ARCHIVE_DATA.certificates) return;
    const q = (document.getElementById('search_certs').value || '').toLowerCase().trim();
    const cat = document.getElementById('filter_certs_category').value;

    const filtered = CURRENT_ARCHIVE_DATA.certificates.filter(c => {
        const name = ((c.first_name || '') + ' ' + (c.last_name || '')).toLowerCase();
        const bib = (c.shooter_bib || '').toLowerCase();
        const certId = ('cert #' + c.id).toLowerCase();
        const evtName = (c.event_name || '').toLowerCase();

        if (q && !name.includes(q) && !bib.includes(q) && !certId.includes(q) && !evtName.includes(q)) {
            return false;
        }
        if (cat && (c.category || 'NR') !== cat) {
            return false;
        }
        return true;
    });

    document.getElementById('count_certs').innerText = filtered.length;
    renderCertsTable(filtered);
}

function resetCertsFilter() {
    document.getElementById('search_certs').value = '';
    document.getElementById('filter_certs_category').value = '';
    applyCertsFilter();
}

function applyEventsFilter() {
    if (!CURRENT_ARCHIVE_DATA || !CURRENT_ARCHIVE_DATA.events) return;
    const q = (document.getElementById('search_events').value || '').toLowerCase().trim();
    const cat = document.getElementById('filter_events_category').value;
    const age = document.getElementById('filter_events_age').value;

    const filtered = CURRENT_ARCHIVE_DATA.events.filter(e => {
        const code = (e.event_code || '').toLowerCase();
        const name = (e.event_name || '').toLowerCase();

        if (q && !code.includes(q) && !name.includes(q)) {
            return false;
        }
        if (cat && (e.category || '') !== cat) {
            return false;
        }
        if (age && (e.age_group || 'Senior') !== age) {
            return false;
        }
        return true;
    });

    document.getElementById('count_events').innerText = filtered.length;
    renderEventsTable(filtered);
}

function resetEventsFilter() {
    document.getElementById('search_events').value = '';
    document.getElementById('filter_events_category').value = '';
    document.getElementById('filter_events_age').value = '';
    applyEventsFilter();
}

function switchArchiveTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.style.borderBottomColor = 'transparent';
        btn.style.color = 'var(--text-muted)';
    });
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.style.display = 'none';
    });

    const activeBtn = document.getElementById(`tab_${tab}`);
    const activePane = document.getElementById(`pane_${tab}`);

    if (activeBtn && activePane) {
        activeBtn.style.borderBottomColor = 'var(--gold-400)';
        activeBtn.style.color = '#fff';
        activePane.style.display = 'block';
    }
}

function exportCurrentArchive() {
    const cid = document.getElementById('championshipSelector').value || 1;
    window.location.href = `actions/championship_action.php?action=backup&id=${cid}`;
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>

<?php require_once 'includes/footer.php'; ?>
