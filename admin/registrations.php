<?php
/**
 * admin/registrations.php
 * View and manage participant registrations.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
$pageTitle = 'Manage Registrations';
require_once 'includes/header.php';

try {
    $pdo = getDB();
    $search         = $_GET['search']         ?? '';
    $status         = $_GET['status']         ?? '';
    $participant_id = $_GET['participant_id'] ?? '';
    $club_filter    = $_GET['club_name']      ?? '';
    $event_filter   = $_GET['event_name']     ?? '';

    // Fetch lists for filter dropdowns
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    $participantsList = $pdo->prepare("SELECT DISTINCT r.id, r.reg_id, r.first_name, r.last_name FROM registrations r JOIN event_registrations er ON er.user_id = r.id WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' AND r.reg_id != 'SYS_MANUAL' AND (r.first_name != '' OR r.last_name != '') ORDER BY r.first_name ASC, r.last_name ASC");
    $participantsList->execute([$cid]);
    $participantsList = $participantsList->fetchAll();

    $clubsList = $pdo->prepare("SELECT DISTINCT r.club_name FROM registrations r JOIN event_registrations er ON er.user_id = r.id WHERE er.championship_id = ? AND r.club_name IS NOT NULL AND r.club_name != '' AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' AND r.reg_id != 'SYS_MANUAL' ORDER BY r.club_name ASC");
    $clubsList->execute([$cid]);
    $clubsList = $clubsList->fetchAll(PDO::FETCH_COLUMN);

    $eventsList = $pdo->prepare("SELECT DISTINCT er.event_name FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' AND r.reg_id != 'SYS_MANUAL' ORDER BY er.event_name ASC");
    $eventsList->execute([$cid]);
    $eventsList = $eventsList->fetchAll(PDO::FETCH_COLUMN);
    
    $query = "SELECT DISTINCT r.id, r.reg_id, r.first_name, r.last_name, r.email, r.phone, r.aadhaar_number, r.club_name, r.district, r.association, r.father_guardian_name, r.address, r.dob, r.gender, r.is_para, r.is_deaf, r.status, r.is_verified, r.created_at, r.club_name_change_pending, r.club_name_pending, (SELECT GROUP_CONCAT(field_name SEPARATOR ', ') FROM profile_change_requests WHERE user_id = r.id AND status = 'pending') AS pending_fields FROM registrations r JOIN event_registrations er ON er.user_id = r.id WHERE er.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' AND r.reg_id != 'SYS_MANUAL' AND (r.first_name != '' OR r.last_name != '')";
    $params = [$cid];

    if ($search) {
        $query .= " AND (r.reg_id LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
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
        $query .= " AND EXISTS (SELECT 1 FROM event_registrations er_flt WHERE er_flt.championship_id = ? AND er_flt.user_id = r.id AND er_flt.event_name = ?)";
        array_push($params, $cid, $event_filter);
    }
    
    if ($status) {
        $query .= " AND r.status = ?";
        $params[] = $status;
    }

    $query .= " ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
} catch (Exception $e) {
    $registrations = [];
    $participantsList = [];
    $clubsList = [];
    $eventsList = [];
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Manage Registrations</div>
  <div style="display: flex; gap: 10px; align-items: center;">
    <button type="button" class="btn" onclick="openImportModal()" style="font-size:13px; padding: 10px 20px; background-color: #27ae60; border: none; color: #fff; font-weight: 700; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
      <i class="bi bi-file-earmark-excel"></i> Import Registrations (Excel/CSV)
    </button>
    <?php if (isSuperAdmin()): ?>
      <button class="btn btn-danger" onclick="deleteAllRegistrations()" style="font-size:13px; padding: 10px 20px; background-color: var(--danger, #ff6b6b); border-color: var(--danger, #ff6b6b); color: #fff;"><i class="bi bi-trash-fill"></i> Delete All Registrations</button>
    <?php endif; ?>
  </div>
</div>

<!-- Message Box -->
<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Toolbar -->
<div class="toolbar">
  <form method="GET" action="registrations.php" style="display:flex; gap:12px; width:100%; flex-wrap:wrap; align-items:center;">
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

    <!-- Status Dropdown -->
    <div class="input-wrap" style="min-width:140px;">
      <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
    </div>

    <?php if ($participant_id || $club_filter || $event_filter || $status): ?>
      <a href="registrations.php" class="btn btn-outline" style="padding: 10px 14px; font-size:12px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
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
        <th>Reg ID</th>
        <th>Participant Name</th>
        <th>Email</th>
        <th>Phone No</th>
        <th>Club Name</th>
        <th class="print-hide">Status</th>
        <th style="text-align:right;" class="print-hide">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($registrations)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No registrations found.</td></tr>
      <?php else: ?>
        <?php foreach ($registrations as $r): ?>
        <tr id="row_<?= $r['id'] ?>">
          <td style="color:var(--gold-400);font-weight:600;"><?= htmlspecialchars(formatBibNo($r['reg_id'])) ?></td>
          <td class="td-name">
            <div style="font-weight:500;"><?= htmlspecialchars(strtoupper(formatFullName($r['first_name'], $r['last_name']))) ?></div>
            <?php if (!empty($r['club_name_change_pending']) && !empty($r['club_name_pending'])): ?>
            <div style="font-size:11px;color:var(--gold-400);margin-top:4px;">Pending club change: <?= htmlspecialchars($r['club_name_pending']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($r['email']) ?></td>
          <td><?= htmlspecialchars($r['phone']) ?></td>
          <td class="td-club"><?= htmlspecialchars(strtoupper($r['club_name'] ?? '')) ?: '&mdash;' ?></td>
          <td class="print-hide"><span class="status-badge <?= $r['status'] ?>" id="status_<?= $r['id'] ?>"><?= ucfirst($r['status']) ?></span></td>
          <td style="text-align:right;" class="print-hide">
            <div class="action-btns" style="justify-content:flex-end; gap:6px;">
              <!-- View Details Button -->
              <button class="btn-icon view" onclick='viewRegDetails(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="View Details" style="color: #3498db; background: rgba(52,152,219,0.08); border: 1px solid rgba(52,152,219,0.25);">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
              <!-- Edit Status Button -->
              <button class="btn-icon edit" onclick="openEditModal(<?= $r['id'] ?>, '<?= $r['status'] ?>', <?= $r['is_verified'] ?>)" title="Edit Status">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <?php if (!empty($r['club_name_change_pending']) && !empty($r['club_name_pending'])): ?>
              <button class="btn btn-outline" style="padding:6px 10px;font-size:11px;" onclick="approveClubName(<?= $r['id'] ?>)">Approve Club</button>
              <?php endif; ?>
              <!-- Delete Button -->
              <?php if (true): ?>
              <button class="btn-icon del" onclick="deleteReg(<?= $r['id'] ?>)" title="Delete">
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

<!-- View Participant Details Modal -->
<div class="modal-overlay" id="viewRegDetailsModal">
  <div class="modal" style="max-width:680px; width:90%; background:#1a1a1a; border:1px solid rgba(255,255,255,0.15); border-radius:12px; color:#fff; overflow:hidden;">
    <div class="modal-header" style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:17px; font-weight:700; color:var(--gold-400); display:flex; align-items:center; gap:8px;">
        <i class="bi bi-person-bounding-box"></i> Participant Profile Details
      </h3>
      <button type="button" class="modal-close" style="background:none; border:none; color:#aaa; font-size:22px; cursor:pointer;">&times;</button>
    </div>
    <div class="modal-body" style="padding:20px; max-height:75vh; overflow-y:auto;">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;" id="viewDetailsGrid">
        <!-- Rendered via JS -->
      </div>
    </div>
    <div class="modal-footer" style="padding:12px 20px; border-top:1px solid rgba(255,255,255,0.1); text-align:right;">
      <button type="button" class="btn btn-outline modal-close" style="padding:8px 18px; border-radius:6px;">Close</button>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Update Registration</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="updateRegForm">
      <div class="modal-body">
        <input type="hidden" id="edit_id" name="id">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Account Status</label>
          <div class="input-wrap">
            <select id="edit_status" name="status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Verified Status</label>
          <div class="input-wrap">
            <select id="edit_verified" name="is_verified">
              <option value="1">Yes - Verified</option>
              <option value="0">No - Pending</option>
            </select>
          </div>
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
function openEditModal(id, status, verified) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_verified').value = verified;
    openModal('editModal');
}

function viewRegDetails(r) {
    const modal = document.getElementById('viewRegDetailsModal');
    const grid = document.getElementById('viewDetailsGrid');
    if (!grid || !modal) return;

    const val = (v) => {
        if (v === null || v === undefined || v === '') return '-';
        const str = String(v).trim();
        if (str === '' || str === '0000-00-00' || str.includes('@ssa.import')) return '-';
        return str;
    };

    const items = [
        { label: 'Enrollment / Reg ID', value: val(r.reg_id) },
        { label: 'First Name', value: val(r.first_name) },
        { label: 'Last Name', value: val(r.last_name) },
        { label: 'Email Address', value: val(r.email) },
        { label: 'Phone Number', value: val(r.phone) },
        { label: 'Aadhaar / National ID', value: val(r.aadhaar_number) },
        { label: 'Club Name', value: val(r.club_name) },
        { label: 'District / City', value: val(r.district) },
        { label: 'State Association', value: val(r.association) },
        { label: 'Father / Guardian Name', value: val(r.father_guardian_name) },
        { label: 'Address', value: val(r.address) },
        { label: 'Date of Birth', value: val(r.dob) },
        { label: 'Gender', value: val(r.gender) },
        { label: 'Is Para Shooter', value: r.is_para == 1 ? 'Yes' : 'No' },
        { label: 'Is Deaf Shooter', value: r.is_deaf == 1 ? 'Yes' : 'No' },
        { label: 'Account Status', value: val(r.status) },
        { label: 'Verification Status', value: r.is_verified == 1 ? 'Verified' : 'Pending' },
        { label: 'Registration Date', value: val(r.created_at) }
    ];

    grid.innerHTML = items.map(item => `
        <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07); padding:10px 14px; border-radius:6px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#aaa; font-weight:600; margin-bottom:4px;">${item.label}</div>
            <div style="font-size:13px; font-weight:600; color:${item.value === '-' ? 'var(--text-muted, #777)' : '#fff'}; word-break:break-word;">${item.value}</div>
        </div>
    `).join('');

    modal.classList.add('active');
}

document.getElementById('updateRegForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        const resp = await fetch('actions/update_reg_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeModal('editModal');
            showMsg(document.getElementById('msgBox'), 'success', 'Registration updated successfully.');
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
});

// Admin functions for club name approval and registration deletion
async function approveClubName(id) {
    if (!confirm('Approve this pending club name change?')) return;
    try {
        const fd = new FormData();
        fd.append('id', id);
        const resp = await fetch('actions/approve_club_name_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', 'Club name updated successfully.');
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

async function deleteReg(id) {
    if (!confirm('Are you sure you want to delete this registration? This cannot be undone.')) return;
    try {
        const fd = new FormData();
        fd.append('id', id);
        const resp = await fetch('actions/delete_reg_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', 'Registration deleted.');
            setTimeout(() => window.location.reload(), 800);
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}

function deleteAllRegistrations() {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This is a highly destructive operation! It will permanently wipe all participants, event registrations, lane allocations, score sheets, team enrollments, custom field responses, and reset registration ID counters. This cannot be undone.',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Type DELETE to confirm',
        showCancelButton: true,
        confirmButtonColor: '#ff6b6b',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete all!',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (value !== 'DELETE') {
                return 'You must type DELETE to confirm!';
            }
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                text: 'Please wait while all registrations are being cleared.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            try {
                const resp = await fetch('actions/delete_all_registrations_action.php', { method: 'POST' });
                const data = await resp.json();
                if (data.success) {
                    // Clear all local storage and session storage so lane allocation UI and app state are completely reset
                    localStorage.clear();
                    sessionStorage.clear();
                    Swal.fire({
                        title: 'Success!',
                        text: 'All registrations have been deleted.',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete registrations.', 'error');
                }
            } catch {
                Swal.fire('Error', 'Network error occurred.', 'error');
            }
        }
    });
}
// End admin functions

// Import Modal Functions
let parsedImportRows = [];

function openImportModal() {
    parsedImportRows = [];
    document.getElementById('excelFileInput').value = '';
    document.getElementById('selectedFileName').textContent = '';
    document.getElementById('importPreviewContainer').style.display = 'none';
    document.getElementById('btnConfirmImport').disabled = true;
    document.getElementById('btnConfirmImport').style.opacity = '0.5';
    document.getElementById('importModal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    document.getElementById('selectedFileName').textContent = `📄 Selected File: ${file.name}`;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            
            const rawRows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });
            if (!rawRows || rawRows.length === 0) {
                Swal.fire('Empty File', 'No data rows could be read from this file.', 'warning');
                return;
            }

            parsedImportRows = rawRows;
            renderImportPreview(parsedImportRows);

            document.getElementById('btnConfirmImport').disabled = false;
            document.getElementById('btnConfirmImport').style.opacity = '1';
        } catch (err) {
            console.error('XLSX parsing error:', err);
            Swal.fire('File Error', 'Could not parse the file. Make sure it is a valid .xlsx, .xls, or .csv file.', 'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

function renderImportPreview(rows) {
    if (rows.length === 0) return;

    const headRow = document.getElementById('previewTableHead');
    const bodyContainer = document.getElementById('previewTableBody');
    const rowCountSpan = document.getElementById('parsedRowCount');

    headRow.innerHTML = '';
    bodyContainer.innerHTML = '';
    rowCountSpan.textContent = rows.length;

    const cols = Object.keys(rows[0]);
    cols.forEach(col => {
        const th = document.createElement('th');
        th.style.padding = '8px 12px';
        th.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
        th.style.color = '#2ecc71';
        th.textContent = col;
        headRow.appendChild(th);
    });

    const previewLimit = Math.min(rows.length, 10);
    for (let i = 0; i < previewLimit; i++) {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
        cols.forEach(col => {
            const td = document.createElement('td');
            td.style.padding = '6px 12px';
            td.style.color = '#ccc';
            td.textContent = rows[i][col] !== undefined ? String(rows[i][col]) : '';
            tr.appendChild(td);
        });
        bodyContainer.appendChild(tr);
    }

    document.getElementById('importPreviewContainer').style.display = 'block';
}

async function submitImportData() {
    if (parsedImportRows.length === 0) {
        Swal.fire('No Data', 'Please select a valid file with registration rows.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Importing Registrations...',
        text: `Processing ${parsedImportRows.length} rows... Please wait.`,
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    try {
        const resp = await fetch('actions/import_registrations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows: parsedImportRows })
        });
        
        let res;
        const text = await resp.text();
        try {
            res = JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON server response:', text);
            Swal.fire('Server Error', 'Server returned an invalid response. Output: ' + text.substring(0, 300), 'error');
            return;
        }

        if (res.success) {
            closeImportModal();
            const s = res.summary || {};
            const msg = `<strong>Users Added:</strong> ${s.users_inserted || 0} | <strong>Updated:</strong> ${s.users_updated || 0}<br><strong>Event Regs Added:</strong> ${s.events_created || 0} | <strong>Updated:</strong> ${s.events_updated || 0}<br><strong>Start Sheet Allocations Added:</strong> ${s.allocations_created || 0} | <strong>Updated:</strong> ${s.allocations_updated || 0}`;
            
            Swal.fire({
                title: 'Import Complete!',
                html: msg,
                icon: 'success',
                confirmButtonText: 'Great'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Import Failed', res.message || 'Error occurred while importing.', 'error');
        }
    } catch (err) {
        console.error('Import error:', err);
        Swal.fire('Network Error', err.message || 'Failed to connect to the import server.', 'error');
    }
}

// Debounced Auto-Search & Auto-Open Import Modal
document.addEventListener('DOMContentLoaded', () => {
    if (sessionStorage.getItem('open_import') === '1') {
        sessionStorage.removeItem('open_import');
        openImportModal();
    }

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

<!-- Import Modal HTML -->
<div id="importModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; justify-content:center; align-items:center; padding: 20px; box-sizing: border-box;">
  <div style="background:#1e1e1e; border: 1px solid rgba(255,255,255,0.15); width:100%; max-width:850px; max-height: 90vh; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color:#fff;">
    <div style="padding:18px 24px; background:rgba(255,255,255,0.03); border-bottom:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:18px; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="bi bi-file-earmark-excel-fill" style="color:#27ae60;"></i> Import Registrations via Excel / CSV
      </h3>
      <button onclick="closeImportModal()" style="background:none; border:none; color:#aaa; font-size:24px; cursor:pointer; line-height:1;">&times;</button>
    </div>
    
    <div style="padding:24px; overflow-y:auto; flex:1;">
      <div style="margin-bottom: 20px; background: rgba(255,255,255,0.03); border: 1px dashed rgba(255,255,255,0.2); border-radius: 8px; padding: 20px; text-align: center;">
        <i class="bi bi-cloud-arrow-up" style="font-size: 36px; color: var(--accent-color, #27ae60);"></i>
        <p style="margin: 10px 0 6px 0; font-weight: 600;">Choose an Excel file (.xlsx, .xls) or CSV file</p>
        <p style="margin: 0 0 16px 0; font-size: 12px; color: #aaa;">Supported columns: Reg ID, First Name, Last Name, Email, Phone, Club Name, District, Aadhaar Number, DOB, Gender, Event Name, Category</p>
        
        <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleFileSelect(event)">
        
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
          <button type="button" class="btn" onclick="document.getElementById('excelFileInput').click()" style="background: #27ae60; color:#fff; font-weight:700; padding:10px 20px; border:none; border-radius:6px; cursor:pointer;">
            <i class="bi bi-folder-check"></i> Select Excel / CSV File
          </button>
          <a href="actions/download_registration_template.php" class="btn" style="background: rgba(255,255,255,0.1); color:#fff; font-weight:600; padding:10px 20px; text-decoration:none; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">
            <i class="bi bi-download"></i> Download Sample Template
          </a>
        </div>
        <div id="selectedFileName" style="margin-top: 12px; font-weight: 700; color: #2ecc71;"></div>
      </div>

      <!-- Preview Table Container -->
      <div id="importPreviewContainer" style="display:none;">
        <h4 style="margin: 0 0 10px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; color: #aaa;">Data Preview (<span id="parsedRowCount">0</span> rows ready)</h4>
        <div style="max-height: 250px; overflow-y: auto; border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;">
          <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
            <thead style="background: rgba(255,255,255,0.05); position: sticky; top:0;">
              <tr id="previewTableHead"></tr>
            </thead>
            <tbody id="previewTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>
    
    <div style="padding:16px 24px; background:rgba(255,255,255,0.03); border-top:1px solid rgba(255,255,255,0.1); display:flex; justify-content:flex-end; gap:12px;">
      <button type="button" onclick="closeImportModal()" style="background:#444; color:#fff; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
      <button type="button" id="btnConfirmImport" onclick="submitImportData()" disabled style="background:#27ae60; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-weight:700; opacity:0.5;">
        <i class="bi bi-check-circle-fill"></i> Upload & Import
      </button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="js/searchable_select.js?v=<?= time() ?>"></script>
<?php require_once 'includes/footer.php'; ?>
