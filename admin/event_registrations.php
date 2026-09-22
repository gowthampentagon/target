<?php
/**
 * admin/event_registrations.php
 * View and manage event registrations (Admin & Superadmin)
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
$pageTitle = 'Event Registrations';
require_once 'includes/header.php';

try {
    $pdo = getDB();

    // Auto-heal: Ensure event registrations with SH1, SH2, SH3, PARA, DEAF in event_name or event_code are categorized as PARA_DEAF
    $pdo->exec("
        UPDATE event_registrations 
        SET category = 'PARA_DEAF' 
        WHERE (UPPER(event_name) LIKE '%SH1%' 
            OR UPPER(event_name) LIKE '%SH2%' 
            OR UPPER(event_name) LIKE '%SH3%' 
            OR UPPER(event_name) LIKE '%SH-1%' 
            OR UPPER(event_name) LIKE '%SH-2%' 
            OR UPPER(event_name) LIKE '%SH-3%' 
            OR UPPER(event_name) LIKE '%PARA%' 
            OR UPPER(event_name) LIKE '%DEAF%' 
            OR UPPER(event_name) LIKE '%DISABLED%' 
            OR UPPER(event_name) LIKE '%HANDICAPPED%'
            OR UPPER(event_code) LIKE '%SH1%'
            OR UPPER(event_code) LIKE '%SH2%'
            OR UPPER(event_code) LIKE '%SH3%'
            OR UPPER(event_code) LIKE '%SH-1%'
            OR UPPER(event_code) LIKE '%SH-2%'
            OR UPPER(event_code) LIKE '%SH-3%'
            OR UPPER(event_code) LIKE '%PARA%'
            OR UPPER(event_code) LIKE '%DEAF%')
          AND category != 'PARA_DEAF'
    ");

    // Auto-heal: Ensure event registrations with ISSF in event_name or event_code are categorized as ISSF
    $pdo->exec("
        UPDATE event_registrations 
        SET category = 'ISSF' 
        WHERE (UPPER(event_name) LIKE '%ISSF%' OR UPPER(event_code) LIKE 'IS-%' OR UPPER(event_code) LIKE 'IS%') 
          AND category = 'NR'
    ");

    $search         = $_GET['search']         ?? '';
    $category       = $_GET['category']       ?? '';
    $status         = $_GET['status']         ?? '';
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    $participant_id = $_GET['participant_id'] ?? '';
    $club_filter    = $_GET['club_name']      ?? '';
    $event_filter   = $_GET['event_name']     ?? '';

    // Fetch lists for filter dropdowns
    $participantsList = $pdo->prepare("SELECT DISTINCT r.id, r.reg_id, r.first_name, r.last_name FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY r.first_name ASC, r.last_name ASC");
    $participantsList->execute([$cid]);
    $participantsList = $participantsList->fetchAll();

    $clubsList = $pdo->prepare("SELECT DISTINCT r.club_name FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.championship_id = ? AND r.club_name IS NOT NULL AND r.club_name != '' AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY r.club_name ASC");
    $clubsList->execute([$cid]);
    $clubsList = $clubsList->fetchAll(PDO::FETCH_COLUMN);

    $eventsList = $pdo->prepare("SELECT DISTINCT er.event_name FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY er.event_name ASC");
    $eventsList->execute([$cid]);
    $eventsList = $eventsList->fetchAll(PDO::FETCH_COLUMN);
 
    $query = "SELECT er.id, er.event_reg_id, er.category, er.event_name, er.weapon_type,
                     er.age_group, er.best_score, er.issf_number, er.mqs_score, er.entry_fee,
                     er.disability_type, er.classification, er.status, er.admin_remarks,
                     er.created_at, er.match_no, er.competition_name, er.shooting_year,
                     r.first_name, r.last_name, r.email, r.phone, r.aadhaar_number, r.district, r.association, r.father_guardian_name, r.address, r.dob, r.gender, r.is_para, r.is_deaf, r.reg_id, r.club_name,
                     la.bib_no, la.custom_name
              FROM event_registrations er
              JOIN registrations r ON er.user_id = r.id
              LEFT JOIN lane_allocations la ON la.event_reg_id = er.id
              WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%'";
    $params = [$cid];

    if ($search) {
        $query .= " AND (er.event_reg_id LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ? OR er.event_name LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s, $s);
    }
    if ($participant_id) {
        $query .= " AND (r.id = ? OR r.reg_id = ?)";
        array_push($params, $participant_id, $participant_id);
    }
    if ($club_filter) {
        $query .= " AND r.club_name = ?";
        $params[] = $club_filter;
    }
    if ($event_filter) {
        $query .= " AND er.event_name = ?";
        $params[] = $event_filter;
    }
    if ($category) {
        $query .= " AND er.category = ?";
        $params[] = $category;
    }
    if ($status) {
        $query .= " AND er.status = ?";
        $params[] = $status;
    }

    $query .= " ORDER BY er.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $regs = $stmt->fetchAll();

    // Stats per category
    $catStats = $pdo->query("SELECT er.category, COUNT(*) as cnt FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' GROUP BY er.category")->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalEvt = $pdo->query("SELECT COUNT(*) FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%'")->fetchColumn();
    $pendingEvt  = $pdo->query("SELECT COUNT(*) FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.status='pending' AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%'")->fetchColumn();
    $approvedEvt = $pdo->query("SELECT COUNT(*) FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.status='approved' AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%'")->fetchColumn();

} catch (Exception $e) {
    $regs = [];
    $catStats = [];
    $totalEvt = $pendingEvt = $approvedEvt = 0;
    $participantsList = [];
    $clubsList = [];
    $eventsList = [];
}

$catLabels = ['ISSF'=>'ISSF Events','NR'=>'NR Events','PARA_DEAF'=>'Para / Deaf','NR_MQS'=>'NR for MQS'];
?>

<div class="admin-page-header">
  <div class="admin-page-title">Event Registrations</div>
  <div>
    <a href="registrations.php" class="btn" onclick="sessionStorage.setItem('open_import', '1')" style="font-size:13px; padding: 10px 20px; background-color: #27ae60; border: none; color: #fff; font-weight: 700; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
      <i class="bi bi-file-earmark-excel"></i> Import Registrations (Excel/CSV)
    </a>
  </div>
</div>

<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Stats Cards -->
<div class="dash-grid" style="margin-bottom:30px;">
  <div class="stat-card">
    <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg></div>
    <div class="stat-info"><h4>Total Event Regs</h4><div class="val"><?= number_format((float)$totalEvt) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#f39c12;background:rgba(243,156,18,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
    <div class="stat-info"><h4>Pending</h4><div class="val"><?= number_format((float)$pendingEvt) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:var(--success);background:rgba(39,174,96,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
    <div class="stat-info"><h4>Approved</h4><div class="val"><?= number_format((float)$approvedEvt) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:var(--info);background:rgba(41,128,185,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8h.01M12 12v4"/></svg></div>
    <div class="stat-info"><h4>ISSF Registrations</h4><div class="val"><?= number_format((float)($catStats['ISSF'] ?? 0)) ?></div></div>
  </div>
</div>

<!-- Filters -->
<div class="toolbar">
  <form method="GET" action="event_registrations.php" style="display:flex; gap:12px; width:100%; flex-wrap:wrap; align-items:center;">
    <!-- Participant Search Dropdown -->
    <div class="input-wrap" style="flex:1; min-width:220px;">
      <select name="participant_id" class="searchable-select" onchange="this.form.submit()">
        <option value="">— All Participants —</option>
        <?php foreach ($participantsList as $p): ?>
          <option value="<?= $p['id'] ?>" <?= (string)$participant_id === (string)$p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars((formatBibNo($p['reg_id']) ? formatBibNo($p['reg_id']) . ' - ' : '') . formatFullName($p['first_name'], $p['last_name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Club Dropdown -->
    <div class="input-wrap" style="min-width:180px;">
      <select name="club_name" class="searchable-select" onchange="this.form.submit()">
        <option value="">— All Clubs —</option>
        <?php foreach ($clubsList as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $club_filter === $c ? 'selected' : '' ?>>
            <?= htmlspecialchars($c) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Event Dropdown -->
    <div class="input-wrap" style="min-width:220px;">
      <select name="event_name" class="searchable-select" onchange="this.form.submit()">
        <option value="">— All Events —</option>
        <?php foreach ($eventsList as $ev): ?>
          <?php $evLabel = $EVENTS_MAPPING[$ev] ?? $ev; ?>
          <option value="<?= htmlspecialchars($ev) ?>" <?= $event_filter === $ev ? 'selected' : '' ?>>
            <?= htmlspecialchars($evLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="input-wrap" style="min-width:140px;">
      <select name="category" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($catLabels as $k => $v): ?>
        <option value="<?= $k ?>" <?= $category===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="input-wrap" style="min-width:130px;">
      <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Rejected</option>
      </select>
    </div>

    <?php if ($participant_id || $club_filter || $event_filter || $category || $status): ?>
      <a href="event_registrations.php" class="btn btn-outline" style="padding: 10px 14px; font-size:12px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
        <i class="bi bi-x-circle"></i> Clear Filters
      </a>
    <?php endif; ?>

    <button type="button" class="btn btn-outline" onclick="window.print()" style="padding: 10px 16px; font-size:12px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; background:rgba(255,255,255,0.05); color:#fff; border:1px solid rgba(255,255,255,0.2);">
      <i class="bi bi-printer-fill"></i> Print List
    </button>
  </form>
</div>

<!-- Data Table -->
<div class="table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Enrollment ID</th>
        <th>Participant Name</th>
        <th>Club Name</th>
        <th>Category</th>
        <th>Event Name</th>
        <th class="print-hide">Status</th>
        <th style="text-align:right;" class="print-hide">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($regs)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No event registrations found.</td></tr>
      <?php else: ?>
        <?php foreach ($regs as $r): 
          $rawReg = $r['reg_id'] ?? '';
          $cleanReg = str_replace('MANUAL-', '', $rawReg);
          $bib = !empty($r['bib_no']) 
              ? formatBibNo($r['bib_no']) 
              : (!empty($cleanReg) ? formatBibNo($cleanReg, $cleanReg, $r['first_name'] ?? '') : '—');
          if (empty($bib) || $bib === '—') {
              $bib = !empty($cleanReg) ? $cleanReg : (!empty($r['event_reg_id']) ? str_replace('MANUAL-EVT-', '', $r['event_reg_id']) : '—');
          }

          $fullName = formatFullName($r['first_name'] ?? '', $r['last_name'] ?? '');
          if (empty($fullName) || $fullName === '—' || strtoupper($fullName) === 'MANUAL') {
              if (!empty(trim((string)($r['custom_name'] ?? '')))) {
                  $fullName = trim($r['custom_name']);
              } elseif (!empty(trim((string)($r['father_guardian_name'] ?? ''))) && $r['father_guardian_name'] !== '-') {
                  $fullName = trim($r['father_guardian_name']);
              } else {
                  $fullName = !empty($cleanReg) ? 'Competitor ' . $cleanReg : '—';
              }
          }

          $club = !empty(trim((string)($r['club_name'] ?? ''))) && $r['club_name'] !== '-' ? trim($r['club_name']) : '—';
          
          $emailDisplay = $r['email'] ?? '';
          if (strpos($emailDisplay, 'manual_') === 0 && (strpos($emailDisplay, '@ssa.com') !== false || strpos($emailDisplay, '@ssa.import') !== false)) {
              $emailDisplay = '—';
          }
        ?>
        <tr id="evtrow_<?= $r['id'] ?>">
          <td style="color:var(--gold-400);font-weight:600;"><?= htmlspecialchars($bib) ?></td>
          <td class="td-name">
            <div style="font-weight:500;"><?= htmlspecialchars(strtoupper($fullName)) ?></div>
          </td>
          <td class="td-club">
            <div style="font-weight:500;"><?= htmlspecialchars(strtoupper($club)) ?></div>
          </td>
          <td>
            <span class="status-badge <?= $r['category']==='ISSF' ? 'active' : ($r['category']==='PARA_DEAF' ? 'pending' : '') ?>" style="font-size:10px;">
              <?= htmlspecialchars($catLabels[$r['category']] ?? $r['category']) ?>
            </span>
          </td>
          <td>
            <div style="font-weight:500; color:var(--text-secondary);"><?= htmlspecialchars($EVENTS_MAPPING[$r['event_name']] ?? $r['event_name']) ?></div>
          </td>
          <td class="print-hide">
            <span class="status-badge <?= $r['status']==='approved'?'active':($r['status']==='rejected'?'danger':'pending') ?>" id="evtstatus_<?= $r['id'] ?>">
              <?= ucfirst($r['status']) ?>
            </span>
            <?php if ($r['admin_remarks']): ?>
            <div style="font-size:10px;color:var(--text-muted);margin-top:3px;" title="<?= htmlspecialchars($r['admin_remarks']) ?>">
              <?= htmlspecialchars(substr($r['admin_remarks'], 0, 25)) ?>...
            </div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;" class="print-hide">
            <div class="action-btns" style="justify-content:flex-end;">
              <button class="btn-icon edit" onclick="openEvtModal(<?= $r['id'] ?>, '<?= $r['status'] ?>', <?= htmlspecialchars(json_encode($r['admin_remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" title="Update Status">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <?php if (true): ?>
              <button class="btn-icon del" onclick="deleteEvtReg(<?= $r['id'] ?>)" title="Delete">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Update Status Modal -->
<div class="modal-overlay" id="evtModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Update Event Registration</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="updateEvtForm">
      <div class="modal-body">
        <input type="hidden" id="evt_id" name="id">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Status</label>
          <div class="input-wrap">
            <select id="evt_status" name="status">
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Admin Remarks</label>
          <div class="input-wrap">
            <textarea id="evt_remarks" name="admin_remarks" rows="3" style="min-height:80px;"></textarea>
          </div>
          <small style="color:var(--text-muted);font-size:11px;">Visible to admin only. Helps track approval reasoning.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Update</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEvtModal(id, status, remarks) {
  document.getElementById('evt_id').value = id;
  document.getElementById('evt_status').value = status;
  document.getElementById('evt_remarks').value = remarks || '';
  openModal('evtModal');
}

document.getElementById('updateEvtForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const resp = await fetch('actions/update_evt_reg_action.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      closeModal('evtModal');
      showMsg(document.getElementById('msgBox'), 'success', 'Event registration updated successfully.');
      window.location.reload();
    } else {
      alert(data.message);
    }
  } catch { alert('Network error.'); }
});

// Admin functions for deleting event registrations
async function deleteEvtReg(id) {
  if (!confirm('Delete this event registration? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('id', id);
  try {
    const resp = await fetch('actions/delete_evt_reg_action.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      showMsg(document.getElementById('msgBox'), 'success', 'Deleted.');
      setTimeout(() => window.location.reload(), 800);
    } else { alert(data.message); }
  } catch { alert('Network error.'); }
}
// End admin functions

// Debounced Auto-Search
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        let timeout = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                searchInput.form.submit();
            }, 600);
        });
        
        // Restore focus and cursor position at the end of text
        if (searchInput.value !== '') {
            const val = searchInput.value;
            searchInput.value = '';
            searchInput.focus();
            searchInput.value = val;
        }
    }
});
</script>

<script src="js/searchable_select.js?v=<?= time() ?>"></script>
<?php require_once 'includes/footer.php'; ?>
