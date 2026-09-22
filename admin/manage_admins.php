<?php
/**
 * admin/manage_admins.php
 * Superadmin interface to manage admin users.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin

$pageTitle = 'Manage Admins';
require_once 'includes/header.php';

try {
    $pdo = getDB();
    $admins = $pdo->query("SELECT id, name, email, role, created_at FROM admins ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $admins = [];
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Manage Admin Users</div>
  <button class="btn btn-primary" onclick="openAddModal()" style="font-size:12px; padding:7px 14px; display:inline-flex; align-items:center; gap:6px;"><i class="bi bi-person-plus-fill"></i> Add New Admin</button>
</div>

<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<div class="table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Date Added</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($admins as $a): ?>
      <tr id="row_<?= $a['id'] ?>">
        <td><?= $a['id'] ?></td>
        <td><?= htmlspecialchars($a['name']) ?></td>
        <td><?= htmlspecialchars($a['email']) ?></td>
        <td>
          <span class="status-badge <?= $a['role'] === 'superadmin' ? 'active' : 'pending' ?>">
            <?= ucfirst($a['role']) ?>
          </span>
        </td>
        <td><?= date('d M Y', strtotime($a['created_at'])) ?></td>
        <td style="text-align:right;">
          <div class="action-btns" style="justify-content:flex-end;">
            <?php if ($a['id'] != $_SESSION['admin_id']): ?>
              <button class="btn-icon del" onclick="deleteAdmin(<?= $a['id'] ?>)" title="Delete Admin">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            <?php else: ?>
              <span style="font-size:11px;color:var(--text-muted);">Current</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add Admin Modal -->
<div class="modal-overlay" id="addAdminModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Add New Admin</h3>
      <button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <form id="addAdminForm">
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Full Name <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" name="name" required>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Email Address <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="email" name="email" required>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Role <span class="req">*</span></label>
          <div class="input-wrap">
            <select name="role" required>
              <option value="admin">Admin</option>
              <option value="superadmin">Superadmin</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Password <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="password" name="password" required minlength="8">
          </div>
          <small style="color:var(--text-muted);font-size:11px;">Minimum 8 characters</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Add Admin</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addAdminForm').reset();
    openModal('addAdminModal');
}

document.getElementById('addAdminForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'add');
    
    try {
        const resp = await fetch('actions/admin_users_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeModal('addAdminModal');
            showMsg(document.getElementById('msgBox'), 'success', 'Admin added successfully.');
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
});

async function deleteAdmin(id) {
    if (!confirm('Are you sure you want to remove this admin? This action cannot be undone.')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        const resp = await fetch('actions/admin_users_action.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('row_' + id).remove();
            showMsg(document.getElementById('msgBox'), 'success', 'Admin deleted.');
        } else {
            alert(data.message);
        }
    } catch {
        alert('Network error.');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
