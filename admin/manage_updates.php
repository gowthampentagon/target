<?php
/**
 * admin/manage_updates.php
 * Superadmin / Admin interface to post rank lists, certificate updates, and general notices.
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once 'includes/auth.php';

checkAdminAuth();

if (!canEdit()) {
    echo '<style>
      .col-md-5 form input,
      .col-md-5 form select,
      .col-md-5 form textarea,
      .col-md-5 form button,
      .col-md-7 form[onsubmit] button {
          pointer-events: none !important;
          opacity: 0.5 !important;
          cursor: not-allowed !important;
      }
    </style>';
}

$pdo = getDB();

// Dynamic self-initialization of updates table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `landing_updates` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `title` VARCHAR(255) NOT NULL,
      `update_type` ENUM('rank_list', 'certificate', 'general') NOT NULL,
      `file_path` VARCHAR(255) NULL,
      `cover_image` VARCHAR(255) NULL,
      `description` TEXT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Dynamic schema update: add cover_image column if it does not exist
    try {
        $pdo->exec("ALTER TABLE `landing_updates` ADD COLUMN `cover_image` VARCHAR(255) NULL AFTER `file_path`");
    } catch (Exception $colEx) {
        // Suppress if column already exists
    }
} catch (Exception $e) {
    // Silent fail if table creation fails, will be caught during select/inserts
}

$pageTitle = 'Rank Lists & Updates';
require_once 'includes/header.php';

$successMsg = '';
$errorMsg = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canEdit()) {
        $errorMsg = 'Access Denied: You do not have edit/write permissions.';
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'save_update') {
        $title = trim($_POST['title'] ?? '');
        $updateType = $_POST['update_type'] ?? 'general';
        $description = trim($_POST['description'] ?? '');
        $notifyAll = isset($_POST['notify_all']) && $_POST['notify_all'] === '1';

        if (empty($title)) {
            $errorMsg = 'Title is required.';
        } else {
            $uploadedPath = null;
            $coverImagePath = null;
            $uploadDir = dirname(__DIR__) . '/uploads/updates/';

            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // 1. Handle Attachment upload
            if (isset($_FILES['update_file']) && $_FILES['update_file']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['update_file']['tmp_name'];
                $origName = $_FILES['update_file']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                
                $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowedExtensions, true)) {
                    $errorMsg = 'Invalid attachment format. Only PDF, DOC/DOCX, and images are permitted.';
                } else {
                    $newName = time() . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
                        $uploadedPath = 'uploads/updates/' . $newName;
                    } else {
                        $errorMsg = 'Failed to move uploaded attachment.';
                    }
                }
            }

            // 2. Handle Cover Image upload
            if (empty($errorMsg) && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $cTmpName = $_FILES['cover_image']['tmp_name'];
                $cOrigName = $_FILES['cover_image']['name'];
                $cExt = strtolower(pathinfo($cOrigName, PATHINFO_EXTENSION));

                $allowedImgExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($cExt, $allowedImgExtensions, true)) {
                    $errorMsg = 'Invalid cover image format. Only JPG, JPEG, PNG, and WEBP images are allowed.';
                } else {
                    $cNewName = 'cover_' . time() . '_' . uniqid() . '.' . $cExt;
                    if (move_uploaded_file($cTmpName, $uploadDir . $cNewName)) {
                        $coverImagePath = 'uploads/updates/' . $cNewName;
                    } else {
                        $errorMsg = 'Failed to upload cover image.';
                    }
                }
            }

            if (empty($errorMsg)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO landing_updates (title, update_type, file_path, cover_image, description) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $updateType, $uploadedPath, $coverImagePath, $description]);
                    $updateId = $pdo->lastInsertId();

                    // Notify active participants if checked
                    if ($notifyAll) {
                        // Fetch active participants
                        $users = $pdo->query("SELECT id FROM registrations WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($users)) {
                            $notifTitle = '';
                            $notifMsg = '';
                            
                            if ($updateType === 'rank_list') {
                                $notifTitle = 'New Rank List Published';
                                $notifMsg = "The rank list \"$title\" has been published. Check updates to view.";
                            } elseif ($updateType === 'certificate') {
                                $notifTitle = 'Certificate Update';
                                $notifMsg = "A new certificate update \"$title\" has been posted. View details inside your portal.";
                            } else {
                                $notifTitle = 'Academy Announcement';
                                $notifMsg = "A new update has been posted: \"$title\". Click to view announcement details.";
                            }

                            $stmtNotif = $pdo->prepare("
                                INSERT INTO notifications (user_id, is_admin, title, message, redirect_url)
                                VALUES (?, 0, ?, ?, 'dashboard.php?tab=announcements')
                            ");
                            
                            foreach ($users as $userId) {
                                $stmtNotif->execute([$userId, $notifTitle, $notifMsg]);
                            }
                        }
                    }

                    $successMsg = 'Update posted successfully!';
                } catch (Exception $e) {
                    $errorMsg = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Fetch associated file paths
                $stmt = $pdo->prepare("SELECT file_path, cover_image FROM landing_updates WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                if ($row) {
                    if (!empty($row['file_path']) && file_exists(dirname(__DIR__) . '/' . $row['file_path'])) {
                        unlink(dirname(__DIR__) . '/' . $row['file_path']);
                    }
                    if (!empty($row['cover_image']) && file_exists(dirname(__DIR__) . '/' . $row['cover_image'])) {
                        unlink(dirname(__DIR__) . '/' . $row['cover_image']);
                    }
                }

                $stmtDel = $pdo->prepare("DELETE FROM landing_updates WHERE id = ?");
                $stmtDel->execute([$id]);

                $successMsg = 'Announcement deleted successfully.';
            } catch (Exception $e) {
                $errorMsg = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
    } // End of else (!canEdit)
}

// Fetch existing updates
try {
    $search = $_GET['search'] ?? '';
    $typeFilter = $_GET['type'] ?? '';

    $sql = "SELECT * FROM landing_updates WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (title LIKE ? OR description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($typeFilter)) {
        $sql .= " AND update_type = ?";
        $params[] = $typeFilter;
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updatesList = $stmt->fetchAll();
} catch (Exception $e) {
    $updatesList = [];
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Manage Rank Lists & Updates</div>
</div>

<?php if (!empty($successMsg)): ?>
  <div style="color: #2ecc71; font-size:13.5px; text-align:center; padding: 12px; border: 1.5px solid rgba(46,204,113,0.25); background:rgba(46,204,113,0.08); border-radius:10px; margin: 20px 0; font-weight: 500;">
    <?= htmlspecialchars($successMsg) ?>
  </div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
  <div style="color: #e74c3c; font-size:13.5px; text-align:center; padding: 12px; border: 1.5px solid rgba(231,76,60,0.25); background:rgba(231,76,60,0.08); border-radius:10px; margin: 20px 0; font-weight: 500;">
    <?= htmlspecialchars($errorMsg) ?>
  </div>
<?php endif; ?>

<div class="row" style="margin-top: 10px;">
  <!-- Left Side: Create announcement form -->
  <div class="col-md-5" style="margin-bottom: 24px;">
    <div style="background:var(--dark-800); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius-lg); padding:24px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
      <h3 style="font-family:'Cinzel', serif; font-size:18px; color:var(--ssa-text-1); text-transform:uppercase; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.06); padding-bottom:10px; letter-spacing:1px;">Post New Update</h3>
      
      <form method="POST" action="manage_updates.php" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
        <input type="hidden" name="action" value="save_update">
        
        <div class="form-group">
          <label style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:12.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px; display:block;">Title <span style="color:var(--danger)">*</span></label>
          <input type="text" name="title" required class="form-control" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:10px 12px;" placeholder="e.g. 51st TN Championship final rank list">
        </div>
        
        <div class="form-group">
          <label style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:12.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px; display:block;">Update Type</label>
          <select name="update_type" class="form-control" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:10px 12px; width:100%;">
            <option value="rank_list">🏆 Rank List</option>
            <option value="certificate">🎖️ Certificate Announcement</option>
            <option value="general">📢 General Update / Notice</option>
          </select>
        </div>

        <div class="form-group">
          <label style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:12.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px; display:block;">Description / Message</label>
          <textarea name="description" rows="4" class="form-control" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:10px 12px;" placeholder="Enter details or announcements notes..."></textarea>
        </div>

        <div class="form-group">
          <label style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:12.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px; display:block;">Attachment (Optional)</label>
          <input type="file" name="update_file" class="form-control" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:8px 10px;">
          <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">Permitted formats: PDF, DOC, DOCX, PNG, JPG, JPEG (Max 10MB)</small>
        </div>

        <div class="form-group">
          <label style="font-family:'Rajdhani', sans-serif; font-weight:700; font-size:12.5px; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px; display:block;">Cover Picture (Optional)</label>
          <input type="file" name="cover_image" accept="image/*" class="form-control" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:8px 10px;">
          <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">Permitted formats: JPG, JPEG, PNG, WEBP (Shows as a card preview)</small>
        </div>

        <div class="form-check" style="display:flex; align-items:center; gap:8px; margin-top:4px;">
          <input type="checkbox" name="notify_all" id="notifyAll" value="1" checked style="width:16px; height:16px; cursor:pointer;">
          <label for="notifyAll" style="font-family:'Rajdhani', sans-serif; font-size:13px; font-weight:600; color:var(--gold-400); cursor:pointer; user-select:none; margin:0;">🔔 Send portal notification to all active participants</label>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:10px; font-family:'Rajdhani', sans-serif; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:1px; padding:12px; border-radius:var(--radius-sm);">
          Publish & Send Update
        </button>
      </form>
    </div>
  </div>

  <!-- Right Side: Existing Announcements List -->
  <div class="col-md-7">
    <div style="background:var(--dark-800); border:1px solid rgba(255,255,255,0.06); border-radius:var(--radius-lg); padding:24px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); min-height:450px;">
      
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; border-bottom:1px solid rgba(255,255,255,0.06); padding-bottom:10px;">
        <h3 style="font-family:'Cinzel', serif; font-size:18px; color:var(--ssa-text-1); text-transform:uppercase; margin:0; letter-spacing:1px;">Posted Updates</h3>
        
        <!-- Search and Filter Form -->
        <form method="GET" action="manage_updates.php" style="display:flex; gap:10px;">
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:6px 10px; font-size:12px; width:150px;">
          <select name="type" onchange="this.form.submit()" style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.1); color:var(--text-primary); border-radius:var(--radius-sm); padding:6px 10px; font-size:12px;">
            <option value="">All Types</option>
            <option value="rank_list" <?= $typeFilter==='rank_list'?'selected':'' ?>>🏆 Rank List</option>
            <option value="certificate" <?= $typeFilter==='certificate'?'selected':'' ?>>🎖️ Certificates</option>
            <option value="general" <?= $typeFilter==='general'?'selected':'' ?>>📢 General</option>
          </select>
          <?php if (!empty($search) || !empty($typeFilter)): ?>
            <a href="manage_updates.php" class="btn btn-outline" style="padding: 6px 10px; font-size:12px;">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (empty($updatesList)): ?>
        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:300px; color:var(--text-muted);">
          <i class="bi bi-info-circle" style="font-size:32px; margin-bottom:10px;"></i>
          <span>No updates have been posted yet.</span>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:16px; max-height: 600px; overflow-y:auto; padding-right:6px;">
          <?php foreach ($updatesList as $u): ?>
            <div style="background:var(--dark-900); border:1px solid rgba(255,255,255,0.04); border-radius:var(--radius-md); padding:16px; position:relative; transition:border-color 0.2s;">
              
              <!-- Tag badge -->
              <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                  <?php if ($u['update_type'] === 'rank_list'): ?>
                    <span style="background:rgba(255, 255, 255,0.1); color:var(--gold-400); font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:20px; border:1px solid rgba(255, 255, 255,0.25); letter-spacing:0.5px; margin-right:6px;">🏆 Rank List</span>
                  <?php elseif ($u['update_type'] === 'certificate'): ?>
                    <span style="background:rgba(46,204,113,0.1); color:#2ecc71; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:20px; border:1px solid rgba(46,204,113,0.25); letter-spacing:0.5px; margin-right:6px;">🎖️ Certificate</span>
                  <?php else: ?>
                    <span style="background:rgba(52,152,219,0.1); color:#3498db; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:20px; border:1px solid rgba(52,152,219,0.25); letter-spacing:0.5px; margin-right:6px;">📢 Notice</span>
                  <?php endif; ?>
                  <span style="font-size:11px; color:var(--text-muted);"><?= date('d M Y, H:i', strtotime($u['created_at'])) ?></span>
                </div>
                
                <!-- Delete Form -->
                <form method="POST" action="manage_updates.php" onsubmit="return confirm('Are you sure you want to delete this update and any uploaded files?');">
                  <input type="hidden" name="action" value="delete_update">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" style="background:none; border:none; color:var(--danger); font-size:14px; cursor:pointer; padding:2px; display:flex; align-items:center;" title="Delete announcement">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>

              <h4 style="margin:12px 0 6px 0; font-family:'Rajdhani', sans-serif; font-weight:700; font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($u['title']) ?></h4>
              
              <?php if (!empty($u['description'])): ?>
                <p style="margin:0 0 12px 0; font-size:13px; line-height:1.5; color:var(--text-secondary); white-space:pre-wrap;"><?= htmlspecialchars($u['description']) ?></p>
              <?php endif; ?>

              <?php if (!empty($u['cover_image'])): ?>
                <div style="margin:8px 0 12px 0; max-width:200px; border-radius:var(--radius-sm); overflow:hidden; border:1px solid rgba(255,255,255,0.06);">
                  <img src="../<?= htmlspecialchars($u['cover_image']) ?>" alt="Cover image" style="width:100%; display:block; object-fit:cover; max-height:120px;">
                </div>
              <?php endif; ?>

              <?php if (!empty($u['file_path'])): ?>
                <div style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.02); padding:8px 12px; border-radius:var(--radius-sm); border:1px solid rgba(255,255,255,0.04);">
                  <i class="bi bi-file-earmark-arrow-down-fill" style="color:var(--gold-400); font-size:16px;"></i>
                  <span style="font-size:12px; font-weight:600; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1;">
                    <?= basename($u['file_path']) ?>
                  </span>
                  <a href="../<?= htmlspecialchars($u['file_path']) ?>" target="_blank" class="btn btn-outline" style="padding: 4px 10px; font-size:11px; width:auto; border-color:rgba(255,255,255,0.15);">
                    Open File
                  </a>
                </div>
              <?php endif; ?>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
