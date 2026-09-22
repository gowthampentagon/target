<?php
/**
 * admin/profile_change_requests.php
 * Super Admin page to review and approve/reject profile change requests
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();

if (!canEdit()) {
    echo '<style>
      button[id^="btn-approve"],
      button[id^="btn-reject"],
      button[onclick*="approve"],
      button[onclick*="reject"] {
          pointer-events: none !important;
          opacity: 0.5 !important;
          cursor: not-allowed !important;
      }
    </style>';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Profile Change Requests';
require_once 'includes/header.php';

try {
    $pdo = getDB();
    // Get pending change requests with user details
    $pendingStmt = $pdo->prepare("
        SELECT 
            pcr.id, pcr.user_id, pcr.field_name, pcr.old_value, pcr.new_value, 
            pcr.status, pcr.requested_at, pcr.rejection_reason,
            r.first_name, r.last_name, r.email, r.phone
        FROM profile_change_requests pcr
        JOIN registrations r ON pcr.user_id = r.id
        WHERE pcr.status IN ('pending', 'approved', 'rejected')
        ORDER BY 
            CASE WHEN pcr.status = 'pending' THEN 0 
                 WHEN pcr.status = 'approved' THEN 1 
                 ELSE 2 END,
            pcr.requested_at DESC
        LIMIT 100
    ");
    $pendingStmt->execute();
    $requests = $pendingStmt->fetchAll();
} catch (Exception $e) {
    $requests = [];
}

$groupedRequests = [];
foreach ($requests as $req) {
    $uid = $req['user_id'];
    if (!isset($groupedRequests[$uid])) {
        $groupedRequests[$uid] = [
            'first_name' => $req['first_name'],
            'last_name' => $req['last_name'],
            'email' => $req['email'],
            'phone' => $req['phone'],
            'fields' => []
        ];
    }
    $groupedRequests[$uid]['fields'][] = $req;
}

$fieldLabels = [
    'club_name' => 'Club Name',
    'association' => 'Association Name',
    'membership_id' => 'Membership ID',
    'membership_doc' => 'Membership Document',
];
?>

<style>
/* Modern Dark Mode Styles for Profile Change Requests */
.pcr-card {
    background: rgba(13, 15, 20, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    max-width: 900px;
    margin-bottom: 20px;
    transition: all 0.2s ease-in-out;
}
.pcr-card:hover {
    border-color: rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px rgba(255, 255, 255, 0.05);
}

/* User Header Styling */
.pcr-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    padding-bottom: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.pcr-user-title {
    font-family: 'Rajdhani', sans-serif;
    font-weight: 700;
    font-size: 15px;
    color: var(--gold-400);
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.pcr-user-icon {
    background: rgba(255, 255, 255, 0.1);
    color: var(--gold-400);
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
}
.pcr-user-id {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 12px;
}
.pcr-user-contact {
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 500;
}

/* Table Styling */
.pcr-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8px;
    font-size: 12px;
    min-width: 500px;
}
.pcr-table th {
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    text-align: left;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-family: 'Rajdhani', sans-serif;
    font-weight: 600;
    letter-spacing: 0.5px;
    padding: 10px 12px;
    font-size: 11.5px;
}
.pcr-table td {
    padding: 12px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    vertical-align: middle;
}
.pcr-table tbody tr {
    transition: background 0.2s ease;
}
.pcr-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.015);
}

/* Value Columns */
.pcr-field-name {
    font-weight: 600;
    color: var(--text-primary);
}
.pcr-val-old {
    color: var(--text-muted);
    font-family: monospace;
    font-size: 11.5px;
    word-break: break-all;
}
.pcr-val-new {
    color: #3498db;
    font-weight: 600;
    font-family: monospace;
    font-size: 11.5px;
    word-break: break-all;
}

/* Pill Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: 'Rajdhani', sans-serif;
}
.status-badge.pending {
    background: rgba(243, 156, 18, 0.12);
    color: #f39c12;
    border: 1px solid rgba(243, 156, 18, 0.25);
}
.status-badge.approved {
    background: rgba(39, 174, 96, 0.12);
    color: #2ecc71;
    border: 1px solid rgba(39, 174, 96, 0.25);
}
.status-badge.rejected {
    background: rgba(231, 76, 60, 0.12);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.25);
}

/* Action Controls */
.pcr-actions-row {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    align-items: center;
    border-top: 1px dashed rgba(255, 255, 255, 0.08);
    padding-top: 14px;
    margin-top: 8px;
    flex-wrap: wrap;
}
.pcr-actions-count {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-right: auto;
    font-family: 'Rajdhani', sans-serif;
    font-weight: 500;
}
.btn-pcr-approve {
    padding: 6px 14px;
    background: rgba(39, 174, 96, 0.15);
    border: 1px solid rgba(39, 174, 96, 0.4);
    color: #2ecc71;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-pcr-approve:hover:not(:disabled) {
    background: #2ecc71;
    color: #08090C;
    border-color: transparent;
    box-shadow: 0 0 12px rgba(46, 204, 113, 0.35);
}
.btn-pcr-reject {
    padding: 6px 14px;
    background: rgba(231, 76, 60, 0.15);
    border: 1px solid rgba(231, 76, 60, 0.4);
    color: #e74c3c;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-pcr-reject:hover:not(:disabled) {
    background: #e74c3c;
    color: #08090C;
    border-color: transparent;
    box-shadow: 0 0 12px rgba(231, 76, 60, 0.35);
}
</style>

<main style="padding: 30px;">
    <div style="max-width:1200px;margin:0 auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="margin:0;font-size:24px;font-weight:700;font-family:'Cinzel',serif;color:var(--gold-400);letter-spacing:0.5px;">Profile Change Requests</h1>
                <p style="margin:6px 0 0 0;font-size:13px;color:var(--text-secondary);">Review and approve athlete profile changes</p>
            </div>
            <div style="font-size:13px;color:var(--text-muted);font-family:'Rajdhani',sans-serif;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                Total: <strong style="color:var(--gold-400);"><?= count($requests) ?></strong> requests
            </div>
        </div>

        <?php if (empty($requests)): ?>
        <div style="background:rgba(39,174,96,0.1);border:1px solid rgba(39,174,96,0.3);border-radius:12px;padding:24px;text-align:center;backdrop-filter:blur(6px);">
            <div style="font-size:15px;color:#2ecc71;font-weight:700;margin-bottom:6px;font-family:'Rajdhani',sans-serif;text-transform:uppercase;letter-spacing:0.5px;">✓ No pending requests</div>
            <p style="font-size:12px;color:var(--text-muted);margin:0;">All profile changes have been processed</p>
        </div>
        <?php else: ?>

        <div style="display:grid;gap:16px;">
            <?php foreach ($groupedRequests as $userId => $userGroup): 
                $pendingIds = [];
                $hasPending = false;
                foreach ($userGroup['fields'] as $f) {
                    if ($f['status'] === 'pending') {
                        $pendingIds[] = (int)$f['id'];
                        $hasPending = true;
                    }
                }
                $pendingIdsJson = json_encode($pendingIds);
            ?>
            <div class="pcr-card">
                <!-- User Header Row -->
                <div class="pcr-header">
                    <div class="pcr-user-title">
                        <span class="pcr-user-icon"><i class="bi bi-person-fill"></i></span>
                        <?= htmlspecialchars($userGroup['first_name']) ?> <?= htmlspecialchars($userGroup['last_name']) ?>
                        <span class="pcr-user-id">(ID: <?= $userId ?>)</span>
                    </div>
                    <div class="pcr-user-contact">
                        <?= htmlspecialchars($userGroup['email']) ?> &bull; <?= htmlspecialchars($userGroup['phone']) ?>
                    </div>
                </div>

                <!-- Fields Table -->
                <div style="overflow-x:auto;">
                    <table class="pcr-table">
                        <thead>
                            <tr>
                                <th style="width:180px;">Field</th>
                                <th>Old Value</th>
                                <th>New Value</th>
                                <th style="width:130px;text-align:right;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userGroup['fields'] as $req): ?>
                            <tr>
                                <td class="pcr-field-name">
                                    <?= htmlspecialchars($fieldLabels[$req['field_name']] ?? $req['field_name']) ?>
                                </td>
                                <td class="pcr-val-old">
                                    <?php if ($req['field_name'] === 'membership_doc' && !empty($req['old_value'])): ?>
                                        <a href="<?= BASE_URL . '/' . htmlspecialchars($req['old_value']) ?>" target="_blank" style="color:var(--info);text-decoration:underline;">View Old</a>
                                    <?php else: ?>
                                        <?= empty($req['old_value']) ? '<span style="color:rgba(150,150,150,0.3);font-style:italic;">empty</span>' : htmlspecialchars(substr($req['old_value'], 0, 40)) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="pcr-val-new">
                                    <?php if ($req['field_name'] === 'membership_doc'): ?>
                                        <a href="<?= BASE_URL . '/' . htmlspecialchars($req['new_value']) ?>" target="_blank" style="color:#3498db;text-decoration:underline;font-weight:700;">View New</a>
                                    <?php else: ?>
                                        <?= htmlspecialchars(substr($req['new_value'], 0, 50)) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <span class="status-badge <?= $req['status'] ?>">
                                        <?= $req['status'] ?>
                                    </span>
                                    <?php if ($req['status'] === 'rejected' && !empty($req['rejection_reason'])): ?>
                                        <div style="font-size:9.5px;color:rgba(231,76,60,0.85);margin-top:4px;" title="<?= htmlspecialchars($req['rejection_reason']) ?>">
                                            Reason: <?= htmlspecialchars(substr($req['rejection_reason'], 0, 20)) ?><?= strlen($req['rejection_reason']) > 20 ? '...' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Group Actions Row (Only visible if there is at least one pending request) -->
                <?php if ($hasPending): ?>
                <div class="pcr-actions-row">
                    <span class="pcr-actions-count">
                        Awaiting decision for <strong><?= count($pendingIds) ?></strong> changes
                    </span>
                    <button type="button" onclick="approveGroupRequests(<?= htmlspecialchars($pendingIdsJson) ?>, <?= $userId ?>)" id="btn-approve-<?= $userId ?>" class="btn-pcr-approve">
                        ✓ Approve All
                    </button>
                    <button type="button" onclick="showGroupRejectForm(<?= $userId ?>)" id="btn-reject-<?= $userId ?>" class="btn-pcr-reject">
                        ✕ Reject All
                    </button>
                </div>

                <!-- Rejection reason input for the group -->
                <div id="reject-group-form-<?= $userId ?>" style="display:none;margin-top:12px;padding:12px;background:rgba(231,76,60,0.03);border-radius:8px;border:1px solid rgba(231,76,60,0.15);">
                    <textarea id="reject-group-reason-<?= $userId ?>" placeholder="Enter rejection reason for all requests..." style="width:100%;padding:8px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);color:white;border-radius:6px;font-size:12px;outline:none;resize:vertical;" rows="2"></textarea>
                    <div style="display:flex;gap:8px;margin-top:8px;justify-content:flex-end;">
                        <button type="button" onclick="rejectGroupRequests(<?= htmlspecialchars($pendingIdsJson) ?>, <?= $userId ?>)" style="padding:5px 12px;background:#e74c3c;color:white;border:none;border-radius:4px;font-size:11px;font-weight:600;font-family:'Rajdhani',sans-serif;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">
                            Confirm Rejection
                        </button>
                        <button type="button" onclick="hideGroupRejectForm(<?= $userId ?>)" style="padding:5px 12px;background:rgba(255,255,255,0.08);color:var(--text-secondary);border:none;border-radius:4px;font-size:11px;font-weight:600;font-family:'Rajdhani',sans-serif;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">
                            Cancel
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</main>

<script>
    async function approveGroupRequests(requestIds, userId) {
        if (!confirm('Are you sure you want to approve all pending requests for this user?')) return;

        const approveBtn = document.getElementById('btn-approve-' + userId);
        const rejectBtn = document.getElementById('btn-reject-' + userId);
        if (approveBtn) approveBtn.disabled = true;
        if (rejectBtn) rejectBtn.disabled = true;

        try {
            for (const id of requestIds) {
                const formData = new FormData();
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                formData.append('request_id', id);
                formData.append('action', 'approve');

                const resp = await fetch('actions/approve_profile_changes.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (!data.success) {
                    alert('Error approving request: ' + data.message);
                    location.reload();
                    return;
                }
            }
            alert('✓ All requests approved successfully.');
            location.reload();
        } catch (e) {
            alert('Network error during approvals');
            location.reload();
        }
    }

    function showGroupRejectForm(userId) {
        document.getElementById('reject-group-form-' + userId).style.display = 'block';
    }

    // Bind autofocus/scroll
    function hideGroupRejectForm(userId) {
        document.getElementById('reject-group-form-' + userId).style.display = 'none';
    }

    async function rejectGroupRequests(requestIds, userId) {
        const reason = document.getElementById('reject-group-reason-' + userId).value.trim();
        if (!reason) {
            alert('Please enter a rejection reason.');
            return;
        }

        const approveBtn = document.getElementById('btn-approve-' + userId);
        const rejectBtn = document.getElementById('btn-reject-' + userId);
        if (approveBtn) approveBtn.disabled = true;
        if (rejectBtn) rejectBtn.disabled = true;

        try {
            for (const id of requestIds) {
                const formData = new FormData();
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                formData.append('request_id', id);
                formData.append('action', 'reject');
                formData.append('rejection_reason', reason);

                const resp = await fetch('actions/approve_profile_changes.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (!data.success) {
                    alert('Error rejecting request: ' + data.message);
                    location.reload();
                    return;
                }
            }
            alert('✓ All requests rejected.');
            location.reload();
        } catch (e) {
            alert('Network error during rejection');
            location.reload();
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>
