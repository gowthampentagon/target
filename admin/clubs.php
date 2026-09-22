<?php
/**
 * admin/clubs.php
 * Superadmin interface to manage clubs.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
checkModuleAccess('clubs.php'); // Enforce passcode permission

$pdo = getDB();

$pageTitle = 'Manage Clubs';
require_once 'includes/header.php';

// Search and filter
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? ''; // 'default', 'custom', or empty (all)

try {
    $query = "SELECT * FROM clubs WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $query .= " AND (club_name LIKE ? OR admin_name LIKE ? OR email LIKE ? OR mobile_no LIKE ?)";
        $sParam = "%$search%";
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
    }
    if ($type === 'default') {
        $query .= " AND is_default = 1";
    } elseif ($type === 'custom') {
        $query .= " AND is_default = 0";
    }
    $query .= " ORDER BY club_name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll();
} catch (Exception $e) {
    $clubs = [];
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Manage Clubs</div>
  <button class="btn btn-primary" onclick="openAddModal()" style="font-size:11px; padding: 6px 12px; width: auto;">+ Add Club</button>
</div>

<!-- Message Box -->
<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Toolbar -->
<div class="toolbar" style="margin-bottom:20px;">
  <form method="GET" action="clubs.php" style="display:flex; gap:16px; width:100%; flex-wrap:wrap;">
    <div class="input-wrap" style="flex:1;">
      <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Search by name, admin, mobile, email..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="input-wrap">
      <select name="type" onchange="this.form.submit()">
        <option value="">All Clubs</option>
        <option value="default" <?= $type==='default'?'selected':'' ?>>Default Clubs</option>
        <option value="custom" <?= $type==='custom'?'selected':'' ?>>Custom Clubs</option>
      </select>
    </div>
  </form>
</div>

<div class="table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th style="width:25%;">Club Name</th>
        <th style="width:15%;">Admin</th>
        <th style="width:15%;">Mobile No</th>
        <th style="width:20%;">Mail</th>
        <th style="width:15%;">TNSA Subscription Document</th>
        <th style="width:10%; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clubs)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No clubs found.</td></tr>
      <?php else: ?>
        <?php foreach ($clubs as $c): ?>
        <tr id="row_<?= $c['id'] ?>">
          <td style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($c['club_name']) ?></td>
          <td style="color:var(--text-secondary);"><?= htmlspecialchars($c['admin_name'] ?: '-') ?></td>
          <td style="color:var(--text-secondary);"><?= htmlspecialchars($c['mobile_no'] ?: '-') ?></td>
          <td style="color:var(--text-secondary);"><?= htmlspecialchars($c['email'] ?: '-') ?></td>
          <td>
            <?php if (!empty($c['tnsa_subscription_doc']) && $c['tnsa_subscription_doc'] !== '-'): ?>
              <a href="../<?= htmlspecialchars($c['tnsa_subscription_doc']) ?>" target="_blank" style="color:var(--gold-400); text-decoration:underline; font-size:12px; font-weight:600;">
                📄 View Doc
              </a>
            <?php else: ?>
              <span style="color:var(--text-muted); font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <div class="action-btns" style="justify-content:flex-end; gap:8px;">
              <button class="btn-icon edit" onclick='openEditModal(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Club">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <button class="btn-icon del" onclick="deleteClub(<?= $c['id'] ?>)" title="Delete Club">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add/Edit Club Modal -->
<div class="modal-overlay" id="clubModal">
  <div class="modal" style="max-width:550px;">
    <div class="modal-header">
      <h3 id="modalTitle">Add New Club</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="clubForm" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" id="club_id" name="id">
        <input type="hidden" id="form_action" name="action" value="add">
        
        <div class="form-group" style="margin-bottom:16px;">
          <label>Club Name <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" id="club_name" name="club_name" required style="text-transform:uppercase;">
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label class="custom-checkbox" style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" id="is_default" name="is_default" value="1" onchange="toggleDefaultFields()">
            <span style="font-size:13px; color:var(--text-primary); font-weight:600;">Default Club (No Admin / Doc required)</span>
          </label>
        </div>

        <div id="customFieldsGroup">
          <div class="form-group" style="margin-bottom:16px;">
            <label>Admin Name <span class="req" style="color:var(--danger); display:none;">*</span></label>
            <div class="input-wrap">
              <input type="text" id="admin_name" name="admin_name">
            </div>
          </div>
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div class="form-group">
              <label>Mobile No <span class="req" style="color:var(--danger); display:none;">*</span></label>
              <div class="input-wrap">
                <input type="text" id="mobile_no" name="mobile_no">
              </div>
            </div>
            <div class="form-group">
              <label>Email Address <span class="req" style="color:var(--danger); display:none;">*</span></label>
              <div class="input-wrap">
                <input type="email" id="email" name="email">
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>TNSA Subscription Document <span class="req" style="color:var(--danger); display:none;">*</span></label>
            <div class="input-wrap">
              <input type="file" id="tnsa_subscription_doc" name="tnsa_subscription_doc" accept=".jpg,.jpeg,.png,.pdf">
            </div>
            <small style="color:var(--text-muted);font-size:11px;margin-top:4px;display:block;">Leave blank to keep existing (if editing)</small>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Save Club</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleDefaultFields() {
    const isDefault = document.getElementById('is_default').checked;
    const isAdd = (document.getElementById('form_action').value === 'add');
    const customFields = ['admin_name', 'mobile_no', 'email', 'tnsa_subscription_doc'];
    
    customFields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = isDefault;
            if (isDefault) {
                el.required = false;
                if (el.type === 'file') {
                    el.value = '';
                } else {
                    el.value = '-';
                }
            } else {
                if (el.value === '-') {
                    el.value = '';
                }
                if (id === 'tnsa_subscription_doc') {
                    el.required = isAdd;
                } else {
                    el.required = true;
                }
            }
        }
    });

    const asterisks = document.querySelectorAll('#customFieldsGroup label .req');
    asterisks.forEach(ast => {
        ast.style.display = isDefault ? 'none' : 'inline';
    });
}

function openAddModal() {
    document.getElementById('clubForm').reset();
    document.getElementById('club_id').value = '';
    document.getElementById('form_action').value = 'add';
    document.getElementById('modalTitle').textContent = 'Add New Club';
    document.getElementById('is_default').checked = false;
    toggleDefaultFields();
    openModal('clubModal');
}

function openEditModal(club) {
    document.getElementById('clubForm').reset();
    document.getElementById('club_id').value = club.id;
    document.getElementById('form_action').value = 'edit';
    document.getElementById('modalTitle').textContent = 'Edit Club';
    document.getElementById('club_name').value = club.club_name;
    document.getElementById('is_default').checked = (parseInt(club.is_default) === 1);
    
    toggleDefaultFields();
    
    if (parseInt(club.is_default) === 0) {
        document.getElementById('admin_name').value = club.admin_name || '';
        document.getElementById('mobile_no').value = club.mobile_no || '';
        document.getElementById('email').value = club.email || '';
    }
    
    openModal('clubModal');
}

document.getElementById('clubForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const clubName = document.getElementById('club_name').value.trim();
    const isDefault = document.getElementById('is_default').checked;
    const action = document.getElementById('form_action').value;
    
    if (clubName.length < 3) {
        alert('Club Name must be at least 3 characters long.');
        return;
    }

    if (!isDefault) {
        const adminName = document.getElementById('admin_name').value.trim();
        const mobileNo = document.getElementById('mobile_no').value.trim();
        const email = document.getElementById('email').value.trim();
        const fileInput = document.getElementById('tnsa_subscription_doc');

        // Admin Name validation
        if (adminName.length < 3) {
            alert('Admin Name must be at least 3 characters long.');
            return;
        }
        if (!/^[a-zA-Z\s]+$/.test(adminName)) {
            alert('Admin Name can only contain letters and spaces.');
            return;
        }

        // Mobile No validation (exactly 10 digits)
        if (!/^\d{10}$/.test(mobileNo)) {
            alert('Mobile Number must be exactly 10 digits.');
            return;
        }

        // Email validation
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Please enter a valid email address.');
            return;
        }

        // TNSA Document validation
        if (action === 'add' && fileInput.files.length === 0) {
            alert('TNSA Subscription Document is required.');
            return;
        }
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExts.includes(ext)) {
                alert('Invalid file format. Only JPG, PNG, and PDF files are allowed.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('Document file size exceeds the 5MB limit.');
                return;
            }
        }
    }

    const fd = new FormData(e.target);
    try {
        const resp = await fetch('actions/clubs_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeModal('clubModal');
            showMsg(document.getElementById('msgBox'), 'success', data.message || 'Club saved successfully.');
            window.location.reload();
        } else {
            alert(data.message || 'Failed to save club.');
        }
    } catch (err) {
        alert('Network error.');
    }
});

async function deleteClub(id) {
    if (!confirm('Are you sure you want to remove this club? This action cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const resp = await fetch('actions/clubs_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            showMsg(document.getElementById('msgBox'), 'success', 'Club deleted successfully.');
            window.location.reload();
        } else {
            alert(data.message || 'Failed to delete club.');
        }
    } catch {
        alert('Network error.');
    }
}

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

<?php require_once 'includes/footer.php'; ?>
