<?php
/**
 * edit_profile.php – Edit registered participant profile
 */
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?expired=1');
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    $user = null;
}

if (!$user) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeChampionship = getActiveChampionship();
$pageTitle = 'Edit Profile';
$pageDesc  = 'Update your registration details for the ' . ($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') . '.';
require_once 'includes/header.php';
?>
<style>
#first_name, #last_name, #father_guardian_name, #address, #club_name, #association, #membership_id, #district, #gender,
#modal_club_name_change_req, #modal_association_change_req, #modal_membership_id_change_req {
  text-transform: uppercase;
}
</style>
<?php
$districts = [
  "Ariyalur","Chengalpattu","Chennai","Coimbatore","Cuddalore",
  "Dharmapuri","Dindigul","Erode","Kallakurichi","Kancheepuram",
  "Kanyakumari","Karur","Krishnagiri","Madurai","Mayiladuthurai",
  "Nagapattinam","Namakkal","Nilgiris","Perambalur","Pudukkottai",
  "Ramanathapuram","Ranipet","Salem","Sivaganga","Tenkasi",
  "Thanjavur","Theni","Thoothukudi","Tiruchirappalli","Tirunelveli",
  "Tirupathur","Tiruppur","Tiruvallur","Tiruvannamalai","Tiruvarur",
  "Vellore","Viluppuram","Virudhunagar"
];
try {
    $pdo = getDB();
    $clubs = $pdo->query("SELECT club_name FROM clubs ORDER BY club_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($clubs)) {
        $clubs = require 'config/clubs.php';
    }
} catch (Exception $e) {
    $clubs = require 'config/clubs.php';
}
?>

<main>
  <div class="auth-card wide" role="main">
    <div class="card-title">Edit Profile</div>
    <p class="card-subtitle">Change your details and upload updated documents if needed.</p>

    <div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

    <form id="editProfileForm" method="post" action="actions/edit_profile_action.php" novalidate autocomplete="off" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['id']) ?>">

        <input type="hidden" name="club_name" value="<?= htmlspecialchars($user['club_name']) ?>">
        <input type="hidden" name="association" value="<?= htmlspecialchars($user['association']) ?>">
        <input type="hidden" name="membership_id" value="<?= htmlspecialchars($user['membership_id'] ?? '') ?>">
        <input type="hidden" name="membership_doc" id="membership_doc_hidden" value="">

        <div class="form-grid">

        <div class="section-divider col-full"><span>Profile & Identity</span></div>

        <div class="form-group col-half">
          <label for="first_name">First Name <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" placeholder="First name" data-rules="required|name" maxlength="100">
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label for="last_name">Last Name</label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" placeholder="Last name" data-rules="name1" maxlength="100">
          </div>
          <span class="field-error"></span>
        </div>

        <?php $minRegAge = getMinRegistrationAge(); ?>
        <script>window.MIN_REGISTRATION_AGE = <?= json_encode($minRegAge) ?>;</script>
        <div class="form-group col-half">
          <label for="dob">Date of Birth <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <input type="date" id="dob" name="dob" value="<?= htmlspecialchars($user['dob']) ?>" data-rules="required|dob" max="<?= date('Y-m-d', strtotime("-{$minRegAge} years")) ?>" min="<?= date('Y-m-d', strtotime('-100 years')) ?>">
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label for="gender">Gender <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/></svg>
            <select id="gender" name="gender" data-rules="required">
              <option value="">-- SELECT GENDER --</option>
              <option value="Male" <?= $user['gender'] === 'Male' ? 'selected' : '' ?>>MALE</option>
              <option value="Female" <?= $user['gender'] === 'Female' ? 'selected' : '' ?>>FEMALE</option>
              <option value="Transgender" <?= $user['gender'] === 'Transgender' ? 'selected' : '' ?>>TRANSGENDER</option>
            </select>
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-full">
          <label for="father_guardian_name">Father / Guardian Name <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <input type="text" id="father_guardian_name" name="father_guardian_name" value="<?= htmlspecialchars($user['father_guardian_name']) ?>" placeholder="Father / guardian full name" data-rules="required|name" maxlength="200">
          </div>
          <span class="field-error"></span>
        </div>

        <!-- 5a. Profile Photo -->
        <style>
        .avatar-preview-wrapper {
          position: relative;
          width: 100px;
          height: 100px;
          border-radius: 50%;
          border: 2px solid var(--gold-400);
          background: rgba(255,255,255,0.03);
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          overflow: hidden;
          transition: all 0.2s ease-in-out;
          box-shadow: 0 0 16px rgba(255, 255, 255,0.15);
        }
        .avatar-preview-wrapper:hover {
          border-color: var(--gold-300);
          box-shadow: 0 0 20px rgba(255, 255, 255,0.3);
        }
        .avatar-preview-wrapper:hover .avatar-overlay {
          opacity: 1 !important;
        }
        .form-group.has-error .avatar-preview-wrapper {
          border-color: #E74C3C !important;
          box-shadow: 0 0 16px rgba(231,76,60,0.3) !important;
        }
        .form-group.has-error .custom-file-upload-wrap {
          border-color: #E74C3C !important;
          background: rgba(231,76,60,0.02) !important;
        }
        </style>
        <div class="form-group col-full">
          <label for="photo">Profile Photo</label>
          <div class="profile-photo-redesign" style="display:flex; align-items:center; gap:20px; margin-top:8px; margin-bottom:8px; flex-wrap:wrap;">
            <!-- Circular Preview container -->
            <div class="avatar-preview-wrapper" onclick="document.getElementById('photo').click();">
              <!-- Image Preview -->
              <img id="avatarPreview" src="<?= !empty($user['photo']) ? htmlspecialchars($user['photo']) : '' ?>" style="width:100%; height:100%; object-fit:cover; display:<?= !empty($user['photo']) ? 'block' : 'none' ?>;" alt="Preview" />
              
              <!-- Placeholder SVG (if no photo) -->
              <div id="avatarPlaceholder" style="display:<?= !empty($user['photo']) ? 'none' : 'flex' ?>; align-items:center; justify-content:center; color:var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
              </div>
              
              <!-- Edit/Camera Overlay -->
              <div class="avatar-overlay" style="position:absolute; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.2s ease-in-out;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--gold-400);">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                </svg>
              </div>
            </div>
            
            <!-- Upload controls & text info -->
            <div style="display:flex; flex-direction:column; gap:6px; flex-grow:1; min-width:200px;">
              <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <button type="button" class="btn" onclick="document.getElementById('photo').click();" style="width:auto; padding:8px 18px; font-size:12px; font-weight:700; border:1px solid var(--gold-400); color:var(--gold-400); background:transparent; border-radius:6px; cursor:pointer; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.8px; transition:all 0.2s ease-in-out;" onmouseover="this.style.background='var(--grad-gold)'; this.style.color='#08090C'; this.style.borderColor='transparent'; this.style.boxShadow='0 0 12px rgba(255, 255, 255,0.3)';" onmouseout="this.style.background='transparent'; this.style.color='var(--gold-400)'; this.style.borderColor='var(--gold-400)'; this.style.boxShadow='none';">
                  Upload Photo
                </button>
                <span id="photoFileName" style="font-size:12px; color:var(--text-secondary); max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500;"><?= !empty($user['photo']) ? 'Current profile photo' : 'No file selected' ?></span>
              </div>
              <div style="font-size:11px; color:var(--text-muted);">Upload to replace existing photo. Supported formats: JPG, PNG, GIF, WebP (max 2MB)</div>
              
              <!-- Hidden input -->
              <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp" style="display:none;" onchange="handlePhotoPreview(this);">
            </div>
          </div>
          <span class="field-error"></span>
        </div>

        <div class="section-divider col-full"><span>Contact Details</span></div>

        <div class="form-group col-half">
          <label for="email">Email Address <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="you@example.com" data-rules="required|email" maxlength="180" autocomplete="email">
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label for="phone">Mobile Number <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.07 9.21 19.79 19.79 0 0 1 1 .82 2 2 0 0 1 2.82 0h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L6.91 7.91a16 16 0 0 0 9.09 9.09l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 24 18v.92z" transform="scale(0.92) translate(1,1)"/></svg>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" placeholder="10-digit mobile" data-rules="required|phone" maxlength="10" autocomplete="tel">
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-full">
          <label for="aadhaar_number">Aadhaar Card Number <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <input type="text" id="aadhaar_number" name="aadhaar_number" value="<?= chunk_split(htmlspecialchars($user['aadhaar_number']), 4, ' ') ?>" placeholder="XXXX XXXX XXXX" data-rules="required|aadhaar" maxlength="14" inputmode="numeric">
          </div>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-full">
          <label for="address">Full Address <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="top:14px;position:absolute;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <textarea id="address" name="address" placeholder="Door No., Street, City, PIN" data-rules="required|minLen:10" rows="3" maxlength="500" style="padding-left:42px;"><?= htmlspecialchars($user['address']) ?></textarea>
          </div>
          <span class="field-error"></span>
        </div>

        <div class="section-divider col-full"><span>Club & Association</span></div>

        <div class="col-full" style="display: flex; justify-content: flex-end; margin-top: -10px; margin-bottom: 8px;">
          <button type="button" onclick="openClubAssociationModal()" style="background: transparent; border: 1px solid var(--gold-400); color: var(--gold-400); padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(255, 255, 255, 0.15)'" onmouseout="this.style.background='transparent'">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit Club & Association
          </button>
        </div>

        <div class="form-group col-half">
          <label>Club Name <span class="req">*</span></label>
          <div class="input-wrap" style="background:rgba(100,100,100,0.05);border-color:rgba(100,100,100,0.1);cursor:not-allowed;">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <input type="text" value="<?= htmlspecialchars($user['club_name']) ?>" placeholder="Your shooting club" disabled style="cursor:not-allowed;background:transparent;">
          </div>
          <div style="font-size:11px;color:var(--gold-400);margin-top:6px;padding-left:4px;font-weight:600;">📌 Approved club name. Changes require Super Admin approval.</div>
          <?php if (!empty($user['club_name_change_pending']) && !empty($user['club_name_pending'])): ?>
          <div style="background:rgba(255, 255, 255,0.1);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:10px;margin-top:10px;">
            <div style="font-size:11px;color:var(--gold-400);font-weight:700;margin-bottom:4px;">⏳ Change Request Pending Approval</div>
            <div style="font-size:11px;color:var(--text-primary);">Requested new club name: <strong><?= htmlspecialchars($user['club_name_pending']) ?></strong></div>
          </div>
          <?php endif; ?>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label>Association Name <span class="req">*</span></label>
          <div class="input-wrap" style="background:rgba(100,100,100,0.05);border-color:rgba(100,100,100,0.1);cursor:not-allowed;">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <input type="text" value="<?= htmlspecialchars($user['association']) ?>" placeholder="District / State association" disabled style="cursor:not-allowed;background:transparent;">
          </div>
          <div style="font-size:11px;color:var(--gold-400);margin-top:6px;padding-left:4px;font-weight:600;">📌 Approved association. Changes require Super Admin approval.</div>
          <?php if (!empty($user['association_change_pending']) && !empty($user['association_pending'])): ?>
          <div style="background:rgba(255, 255, 255,0.1);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:10px;margin-top:10px;">
            <div style="font-size:11px;color:var(--gold-400);font-weight:700;margin-bottom:4px;">⏳ Change Request Pending Approval</div>
            <div style="font-size:11px;color:var(--text-primary);">Requested new association: <strong><?= htmlspecialchars($user['association_pending']) ?></strong></div>
          </div>
          <?php endif; ?>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label for="district">District <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <select id="district" name="district" data-rules="required">
              <option value="">-- SELECT DISTRICT --</option>
              <?php foreach ($districts as $d): ?>
                <option value="<?= htmlspecialchars(strtoupper($d)) ?>" <?= strtoupper($user['district'] ?? '') === strtoupper($d) ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($d)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <span class="field-error"></span>
        </div>

        <!-- ═════════════════════════════════════════════════════════ -->
        <!-- MEMBERSHIP ID - Change Request Workflow -->
        <!-- ═════════════════════════════════════════════════════════ -->
        <div class="form-group col-half">
          <label>Membership ID</label>
          
          <!-- Current Approved Value (Read-only) -->
          <div class="input-wrap" style="background:rgba(100,100,100,0.05);border-color:rgba(100,100,100,0.1);cursor:not-allowed;">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2" ry="2"/><path d="M3 11h18"/></svg>
            <input type="text" value="<?= htmlspecialchars($user['membership_id'] ?? '—') ?>" placeholder="Membership ID" disabled style="cursor:not-allowed;background:transparent;">
          </div>
          
          <!-- Pending Change Status -->
          <?php if (!empty($user['membership_id_change_pending']) && !empty($user['membership_id_pending'])): ?>
          <div style="background:rgba(255, 255, 255,0.1);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:8px;margin-top:8px;font-size:10px;color:var(--gold-400);">⏳ <strong><?= htmlspecialchars($user['membership_id_pending']) ?></strong> pending approval</div>
          <?php endif; ?>
          <span class="field-error"></span>
        </div>

        <div class="form-group col-half">
          <label>Membership Document</label>
          
          <!-- Current Approved Document -->
          <?php if (!empty($user['membership_doc'])): ?>
          <div style="margin-top: 8px;">
            <a href="<?= htmlspecialchars($user['membership_doc']) ?>" target="_blank" class="btn btn-outline" style="padding: 10px 16px; font-size: 12px; font-family: 'Rajdhani', sans-serif; display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.5px; border-color: rgba(255,255,255,0.12); color: var(--text-secondary); height: 46px; line-height: 24px; box-sizing: border-box;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
              View Document
            </a>
          </div>
          <?php else: ?>
          <div style="font-size:12px; color:var(--text-muted); font-style:italic; margin-top:8px;">No document uploaded yet</div>
          <?php endif; ?>
          
          <!-- Pending Document Status -->
          <?php if (!empty($user['membership_doc_change_pending']) && !empty($user['membership_doc_pending'])): ?>
          <div style="background:rgba(255, 255, 255,0.1);border:1px solid rgba(255, 255, 255,0.3);border-radius:8px;padding:8px;margin-bottom:10px;font-size:10px;color:var(--gold-400);">⏳ New document pending approval</div>
          <?php endif; ?>
          <span class="field-error"></span>
        </div>

        <div class="col-full" style="margin-top:24px; display:flex; justify-content:center;">
          <button type="submit" class="btn btn-primary" id="saveProfileBtn" style="padding:12px 36px; font-size:14px; font-weight:600;">
            <div class="spinner"></div>
            <span class="btn-label">Save Changes</span>
          </button>
        </div>

      </div>
    </form>
  </div>
</main>

<script>
  function handlePhotoPreview(input) {
    const preview = document.getElementById('avatarPreview');
    const placeholder = document.getElementById('avatarPlaceholder');
    const fileName = document.getElementById('photoFileName');
    
    if (input.files && input.files[0]) {
      const file = input.files[0];
      
      // Check format and size limits
      const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      if (!allowed.includes(file.type)) {
        alert('Invalid file format. Please upload JPG, PNG, GIF, or WebP.');
        input.value = '';
        fileName.textContent = 'No file selected';
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
        if (window.validateInput) window.validateInput(input);
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        alert('File size exceeds 2MB limit.');
        input.value = '';
        fileName.textContent = 'No file selected';
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
        if (window.validateInput) window.validateInput(input);
        return;
      }
      
      fileName.textContent = file.name;
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        if (window.validateInput) window.validateInput(input);
      }
      reader.readAsDataURL(file);
    } else {
      fileName.textContent = 'No file selected';
      preview.style.display = 'none';
      placeholder.style.display = 'flex';
      if (window.validateInput) window.validateInput(input);
    }
  }

  function handleCustomFileUpload(input, labelId) {
    const lbl = document.getElementById(labelId);
    if (!lbl) return;
    
    if (input.files && input.files[0]) {
      const file = input.files[0];
      
      // Check size limit (2MB)
      if (file.size > 2 * 1024 * 1024) {
        alert('File size exceeds 2MB limit.');
        input.value = '';
        lbl.textContent = 'Upload File';
        if (window.validateInput) window.validateInput(input);
        return;
      }
      
      lbl.textContent = file.name;
      lbl.style.color = 'var(--gold-400)';
    } else {
      lbl.textContent = 'Upload File';
      lbl.style.color = 'var(--text-secondary)';
    }
    
    if (window.validateInput) window.validateInput(input);
  }

  // Auto-uppercase modal change request text fields as user types
  document.addEventListener('DOMContentLoaded', () => {
    const modalFields = ['modal_club_name_change_req', 'modal_association_change_req', 'modal_membership_id_change_req'];
    modalFields.forEach(id => {
      const inp = document.getElementById(id);
      if (inp) {
        inp.addEventListener('input', () => {
          const start = inp.selectionStart;
          const end = inp.selectionEnd;
          inp.value = inp.value.toUpperCase();
          if (start !== null && end !== null) {
            inp.setSelectionRange(start, end);
          }
        });
      }
    });
  });

  // Open the edit Club & Association modal
  function openClubAssociationModal() {
    const modal = document.getElementById('clubAssocModal');
    if (modal) {
      modal.style.display = 'flex';
      document.getElementById('modal_club_name_change_req').value = '';
      document.getElementById('modal_association_change_req').value = '';
      document.getElementById('modal_membership_id_change_req').value = '';
      document.getElementById('modal_membership_doc_change_req').value = '';
    }
  }

  // Close the edit Club & Association modal
  function closeClubAssociationModal() {
    const modal = document.getElementById('clubAssocModal');
    if (modal) {
      modal.style.display = 'none';
    }
  }

  // Submit change requests from the modal
  async function submitClubAssociationModalChanges() {
    const newClub = document.getElementById('modal_club_name_change_req').value.trim();
    const newAssoc = document.getElementById('modal_association_change_req').value.trim();
    const newMemberId = document.getElementById('modal_membership_id_change_req').value.trim();
    const docFileInput = document.getElementById('modal_membership_doc_change_req');
    const docFile = docFileInput?.files[0];
    const msgBox = document.getElementById('msgBox');
    
    let successCount = 0;
    let errorMsgs = [];
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    
    // Check if there are any changes proposed
    let pendingRequests = [];
    if (newClub) {
      pendingRequests.push({ name: 'club_name', label: 'Club Name', value: newClub });
    }
    if (newAssoc) {
      pendingRequests.push({ name: 'association', label: 'Association Name', value: newAssoc });
    }
    if (newMemberId) {
      pendingRequests.push({ name: 'membership_id', label: 'Membership ID', value: newMemberId });
    }
    if (docFile) {
      if (!['image/jpeg', 'image/png', 'application/pdf'].includes(docFile.type)) {
        errorMsgs.push('Membership Document Error: Only JPG, PNG, and PDF files are allowed');
      } else if (docFile.size > 2 * 1024 * 1024) {
        errorMsgs.push('Membership Document Error: File must be less than 2MB');
      } else {
        pendingRequests.push({ name: 'membership_doc', label: 'Membership Document', value: docFile });
      }
    }
    
    if (errorMsgs.length > 0) {
      closeClubAssociationModal();
      msgBox.className = 'msg-box error';
      msgBox.innerHTML = '<strong>✗ Error:</strong> ' + errorMsgs.join('<br>');
      msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    
    if (pendingRequests.length === 0) {
      alert('Please enter at least one change before submitting.');
      return;
    }
    
    async function sendRequest(fieldName, valueOrFile) {
      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'submit_change_request');
      formData.append('field_name', fieldName);
      if (fieldName === 'membership_doc') {
        formData.append('membership_doc_file', valueOrFile);
      } else {
        formData.append('new_value', valueOrFile);
      }
      
      const resp = await fetch('actions/profile_change_request.php', {
        method: 'POST',
        body: formData
      });
      return await resp.json();
    }
    
    const submitBtn = document.querySelector('#clubAssocModal .custom-modal-footer button:last-child');
    const originalText = submitBtn.innerText;
    submitBtn.disabled = true;
    submitBtn.innerText = 'Submitting...';
    
    try {
      for (const req of pendingRequests) {
        const res = await sendRequest(req.name, req.value);
        if (res.success) {
          successCount++;
        } else {
          errorMsgs.push(req.label + ' Error: ' + res.message);
        }
      }
      
      closeClubAssociationModal();
      
      if (errorMsgs.length > 0) {
        msgBox.className = 'msg-box error';
        msgBox.innerHTML = '<strong>✗ Error:</strong> ' + errorMsgs.join('<br>');
        msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      } else if (successCount > 0) {
        msgBox.className = 'msg-box success';
        msgBox.innerHTML = '<strong>✓ Success:</strong> Change request submitted successfully for approval.';
        msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => location.reload(), 1500);
      }
    } catch (e) {
      closeClubAssociationModal();
      msgBox.className = 'msg-box error';
      msgBox.innerHTML = '<strong>✗ Error:</strong> A network error occurred. Please try again.';
      msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerText = originalText;
    }
  }
</script>

<!-- Club & Association Edit Modal -->
<div id="clubAssocModal" class="custom-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px); transition: all 0.3s ease;">
  <div class="custom-modal" style="width:min(500px, 90%); max-height:90vh; display:flex; flex-direction:column; background: linear-gradient(145deg, rgba(20,24,36,0.95) 0%, rgba(10,12,18,0.98) 100%); border: 1px solid rgba(255, 255, 255,0.35); border-radius: 12px; box-shadow: 0 16px 40px rgba(0,0,0,0.6); overflow:hidden; animation: modalFadeIn 0.3s ease-out;">
    <div class="custom-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.08);">
      <h3 style="margin:0; font-family: 'Rajdhani', sans-serif; font-size:18px; font-weight:700; color:var(--gold-400); text-transform:uppercase; letter-spacing:0.5px;">Edit Club & Association Info</h3>
      <button type="button" onclick="closeClubAssociationModal()" style="background:transparent; border:none; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; justify-content:center; padding:4px; border-radius:50%; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'; this.style.color='white';" onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)';">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="custom-modal-body" style="padding:20px; display:flex; flex-direction:column; gap:16px; overflow-y:auto; max-height:calc(90vh - 130px);">
      <!-- Club Name Field Group -->
      <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--text-secondary);">Current Club Name</label>
        <div class="input-wrap" style="background:rgba(100,100,100,0.05); border-color:rgba(100,100,100,0.1); cursor:not-allowed; opacity:0.7;">
          <input type="text" value="<?= htmlspecialchars($user['club_name']) ?>" disabled style="cursor:not-allowed; background:transparent; width:100%; color:var(--text-secondary); border:none; font-size:13px; padding:8px;">
        </div>
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--gold-400); margin-top:4px;">New Club Name</label>
        <select id="modal_club_name_change_req" style="padding:8px 12px; border:1px solid rgba(255, 255, 255,0.25); border-radius:6px; background:#141824; color:white; font-size:13px; outline:none; transition:border-color 0.2s; width: 100%;" onfocus="this.style.borderColor='var(--gold-400)'" onblur="this.style.borderColor='rgba(255, 255, 255,0.25)'">
          <option value="">-- SELECT NEW CLUB --</option>
          <?php foreach ($clubs as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Association Name Field Group -->
      <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--text-secondary);">Current Association Name</label>
        <div class="input-wrap" style="background:rgba(100,100,100,0.05); border-color:rgba(100,100,100,0.1); cursor:not-allowed; opacity:0.7;">
          <input type="text" value="<?= htmlspecialchars($user['association']) ?>" disabled style="cursor:not-allowed; background:transparent; width:100%; color:var(--text-secondary); border:none; font-size:13px; padding:8px;">
        </div>
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--gold-400); margin-top:4px;">New Association Name</label>
        <input type="text" id="modal_association_change_req" placeholder="Enter new association name" maxlength="200" style="padding:8px 12px; border:1px solid rgba(255, 255, 255,0.25); border-radius:6px; background:rgba(255,255,255,0.05); color:white; font-size:13px; outline:none; transition:border-color 0.2s;" onfocus="this.style.borderColor='var(--gold-400)'" onblur="this.style.borderColor='rgba(255, 255, 255,0.25)'" />
      </div>

      <!-- Membership ID Field Group -->
      <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--text-secondary);">Current Membership ID</label>
        <div class="input-wrap" style="background:rgba(100,100,100,0.05); border-color:rgba(100,100,100,0.1); cursor:not-allowed; opacity:0.7;">
          <input type="text" value="<?= htmlspecialchars($user['membership_id'] ?? '—') ?>" disabled style="cursor:not-allowed; background:transparent; width:100%; color:var(--text-secondary); border:none; font-size:13px; padding:8px;">
        </div>
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--gold-400); margin-top:4px;">New Membership ID</label>
        <input type="text" id="modal_membership_id_change_req" placeholder="Enter new membership ID" maxlength="50" style="padding:8px 12px; border:1px solid rgba(255, 255, 255,0.25); border-radius:6px; background:rgba(255,255,255,0.05); color:white; font-size:13px; outline:none; transition:border-color 0.2s;" onfocus="this.style.borderColor='var(--gold-400)'" onblur="this.style.borderColor='rgba(255, 255, 255,0.25)'" />
      </div>

      <!-- Membership Document Field Group -->
      <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--text-secondary);">Current Membership Document</label>
        <div style="padding:8px; background:rgba(255,255,255,0.05); border-radius:6px; border:1px solid rgba(255,255,255,0.08); font-size:12px;">
          <?php if (!empty($user['membership_doc'])): ?>
            <a href="<?= htmlspecialchars($user['membership_doc']) ?>" target="_blank" style="color:var(--info); text-decoration:underline;">View Current Document</a>
          <?php else: ?>
            <span style="color:var(--text-muted);">No document uploaded yet</span>
          <?php endif; ?>
        </div>
        <label style="font-weight:600; font-size:12px; text-transform:uppercase; color:var(--gold-400); margin-top:4px;">Upload New Document</label>
        <div class="custom-file-upload-wrap" onclick="document.getElementById('modal_membership_doc_change_req').click();" style="position:relative; width:100%; height:40px; border:1px solid rgba(255, 255, 255,0.25); background:rgba(255,255,255,0.05); border-radius:6px; display:flex; align-items:center; padding:0 12px; cursor:pointer; transition:all 0.2s ease-in-out;" onmouseover="this.style.borderColor='var(--gold-400)'" onmouseout="this.style.borderColor='rgba(255, 255, 255,0.25)'">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--gold-400); margin-right:8px; flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
          </svg>
          <span id="modal_membership_doc_label" style="font-size:12px; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-grow:1; font-weight:500;">Upload File</span>
          <input type="file" id="modal_membership_doc_change_req" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleCustomFileUpload(this, 'modal_membership_doc_label')" />
        </div>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">JPG, PNG, PDF – max 2MB. Changes require Super Admin approval.</div>
      </div>

      <div style="font-size:11px; color:var(--text-muted); line-height:1.4;">
        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Note: Changing Club, Association, Membership ID, or Document requires Super Admin approval. The current approved values will remain active until approved.
      </div>
    </div>
    <div class="custom-modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid rgba(255,255,255,0.08); background:rgba(0,0,0,0.15);">
      <button type="button" onclick="closeClubAssociationModal()" style="padding:8px 16px; background:transparent; border:1px solid rgba(255,255,255,0.15); border-radius:6px; color:var(--text-secondary); font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.borderColor='white'; this.style.color='white';" onmouseout="this.style.borderColor='rgba(255,255,255,0.15)'; this.style.color='var(--text-secondary)';">Cancel</button>
      <button type="button" onclick="submitClubAssociationModalChanges()" style="padding:8px 16px; background:var(--gold-500); border:none; border-radius:6px; color:var(--dark-950); font-size:13px; font-weight:700; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='var(--gold-400)'" onmouseout="this.style.background='var(--gold-500)'">Submit Request</button>
    </div>
  </div>
</div>

<style>
@keyframes modalFadeIn {
  from { transform: translateY(-20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
