<?php
/**
 * register.php – New Participant Registration
 * Saragarhi Shooting Academy – 51st Tamil Nadu Shooting Championship
 *
 * Field order (per spec):
 *  1. First Name          2. Last Name
 *  3. Email               4. Phone
 *  5. Aadhaar Number      6. Club Name
 *  7. Date of Birth       8. Gender
 *  9. District           10. Association
 * 11. Reg ID (auto)      12. Father / Guardian Name
 * 13. Address            14. Password  15. Confirm Password
 */
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$fieldSettings = [];
$fieldsConfig = [];
try {
    $fieldsConfig = getFieldControls('registration');
    foreach ($fieldsConfig as $f) {
        $fieldSettings[$f['field_id']] = [
            'enabled' => (int)$f['is_enabled'],
            'mandatory' => (int)$f['is_mandatory'],
            'label' => $f['field_label']
        ];
    }
} catch (Exception $e) {}

$isFieldEnabled = function(string $fid) use ($fieldSettings): bool {
    return !isset($fieldSettings[$fid]) || $fieldSettings[$fid]['enabled'] === 1;
};
$isFieldMandatory = function(string $fid) use ($fieldSettings): bool {
    return !isset($fieldSettings[$fid]) || $fieldSettings[$fid]['mandatory'] === 1;
};

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeChampionship = getActiveChampionship();
$pageTitle = 'New Registration';
$pageDesc  = 'Register as a participant for the ' . ($activeChampionship['championship_name'] ?? 'Tamil Nadu Shooting Championship') . '.';
require_once 'includes/header.php';
?>

<style>
.form-group.has-error .custom-file-upload-wrap {
  border-color: #E74C3C !important;
  background: rgba(231,76,60,0.02) !important;
}
#first_name, #last_name, #father_guardian_name, #address, #club_name, #association, #membership_id, #district, #gender {
  text-transform: uppercase;
}
.btn-outline .spinner {
  border: 2px solid rgba(255, 255, 255,0.3);
  border-top-color: var(--gold-400);
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

  <!-- REGISTRATION CARD -->
  <main>
    <div style="max-width: 680px; margin: 0 auto 12px; text-align: left;">
      <a href="index.php" style="color: var(--gold-400, #ADB5BD); text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s;">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
          <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
        </svg>
        Return to Main Portal
      </a>
    </div>
    <div class="auth-card wide" role="main">
      <div class="card-title">Participant Registration</div>
      <p class="card-subtitle">Fill all fields carefully — your details will appear on your entry card.</p>

      <!-- Message Box -->
      <div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

      <form id="registerForm" novalidate autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <div class="form-grid">

          <!-- ── SECTION: Profile & Identity ─────────────────── -->
          <div class="section-divider col-full">
            <span>Profile &amp; Identity</span>
          </div>

          <!-- 1. First Name -->
          <div class="form-group col-half">
            <label for="first_name">First Name <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
              <input type="text" id="first_name" name="first_name" placeholder="First name" data-rules="required|name" maxlength="100">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 2. Last Name (Optional) -->
          <div class="form-group col-half">
            <label for="last_name">Last Name</label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
              <input type="text" id="last_name" name="last_name" placeholder="Last name (optional)" data-rules="name1" maxlength="100">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 3. Date of Birth -->
          <?php $minRegAge = getMinRegistrationAge(); ?>
          <script>window.MIN_REGISTRATION_AGE = <?= json_encode($minRegAge) ?>;</script>
          <div class="form-group col-half">
            <label for="dob">Date of Birth <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <input type="date" id="dob" name="dob" data-rules="required|dob" max="<?= date('Y-m-d', strtotime("-{$minRegAge} years")) ?>" min="<?= date('Y-m-d', strtotime('-100 years')) ?>">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 4. Gender -->
          <div class="form-group col-half">
            <label for="gender">Gender <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/></svg>
              <select id="gender" name="gender" data-rules="required">
                <option value="">-- SELECT GENDER --</option>
                <option value="Male">MALE</option>
                <option value="Female">FEMALE</option>
                <option value="Transgender">TRANSGENDER</option>
              </select>
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 5. Father / Guardian Name -->
          <?php if ($isFieldEnabled('father_guardian_name')): ?>
          <div class="form-group col-half">
            <label for="father_guardian_name">Father / Guardian Name <span class="req" style="display: <?= $isFieldMandatory('father_guardian_name') ? 'inline' : 'none' ?>;">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
              <input type="text" id="father_guardian_name" name="father_guardian_name" placeholder="Father / guardian full name" data-rules="<?= $isFieldMandatory('father_guardian_name') ? 'required|' : '' ?>name" maxlength="200">
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>

          <!-- 5a. Profile Photo -->
          <?php if ($isFieldEnabled('photo')): ?>
          <div class="form-group col-half">
            <label for="photo">Profile Photo <span class="req" style="display: <?= $isFieldMandatory('photo') ? 'inline' : 'none' ?>;">*</span></label>
            <div class="upload-container">
              <div class="upload-box" onclick="document.getElementById('photo').click();">
                <div class="upload-box-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <div class="upload-box-text">Add Profile Photo</div>
                <div class="upload-box-desc">JPG, PNG, GIF, WebP up to 2MB</div>
                <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp" <?= $isFieldMandatory('photo') ? 'data-rules="required"' : '' ?> style="display:none;">
              </div>
              <div class="upload-file-info" id="photo_info" style="display:none;">
                <span class="file-name"></span>
                <button type="button" class="file-remove" onclick="clearUpload('photo'); event.stopPropagation();">✖</button>
              </div>
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>

          <!-- ── SECTION: Contact Details ────────────────────── -->
          <div class="section-divider col-full">
            <span>Contact Details</span>
          </div>

          <!-- 6. Email -->
          <div class="form-group col-half">
            <label for="email">Email Address <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
              <input type="email" id="email" name="email" placeholder="you@example.com" data-rules="required|email" maxlength="180" autocomplete="email">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 7. Phone -->
          <div class="form-group col-half">
            <label for="phone">Mobile Number <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.07 9.21 19.79 19.79 0 0 1 1 .82 2 2 0 0 1 2.82 0h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L6.91 7.91a16 16 0 0 0 9.09 9.09l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 24 18v.92z" transform="scale(0.92) translate(1,1)"/></svg>
              <input type="tel" id="phone" name="phone" placeholder="10-digit mobile" data-rules="required|phone" maxlength="10" autocomplete="tel">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 8. Aadhaar Number -->
          <?php if ($isFieldEnabled('aadhaar_number')): ?>
          <div class="form-group col-full">
            <label for="aadhaar_number">Aadhaar Card Number <span class="req" style="display: <?= $isFieldMandatory('aadhaar_number') ? 'inline' : 'none' ?>;">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <input type="text" id="aadhaar_number" name="aadhaar_number" placeholder="XXXX XXXX XXXX" data-rules="<?= $isFieldMandatory('aadhaar_number') ? 'required|' : '' ?>aadhaar" maxlength="14" inputmode="numeric">
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>

          <!-- 9. Address -->
          <?php if ($isFieldEnabled('address')): ?>
          <div class="form-group col-full">
            <label for="address">Full Address <span class="req" style="display: <?= $isFieldMandatory('address') ? 'inline' : 'none' ?>;">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="top:14px;position:absolute;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
              <textarea id="address" name="address" placeholder="Door No., Street, City, PIN" data-rules="<?= $isFieldMandatory('address') ? 'required|minLen:10' : '' ?>" rows="3" maxlength="500" style="padding-left:42px;"></textarea>
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>

          <!-- ── SECTION: Special Category ──────────────────── -->
          <?php if ($isFieldEnabled('is_para') || $isFieldEnabled('is_deaf')): ?>
          <div class="section-divider col-full">
            <span>Special Category</span>
          </div>

          <!-- Para/Deaf Split Yes/No Toggle Buttons -->
          <?php if ($isFieldEnabled('is_para')): ?>
          <div class="form-group col-half">
            <label style="color:var(--text-secondary);font-weight:600;margin-bottom:8px;display:block;font-size:13px;">Are you a Para Athlete? <span class="req" style="display: <?= $isFieldMandatory('is_para') ? 'inline' : 'none' ?>;">*</span></label>
            <div style="display:flex;gap:0;border:1px solid rgba(255,255,255,0.12);border-radius:8px;overflow:hidden;">
              <label id="lbl_para_yes" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;padding:11px 16px;transition:all .2s;background:rgba(255,255,255,0.03);color:var(--text-muted);border-right:1px solid rgba(255,255,255,0.08);">
                <input type="radio" name="is_para" id="is_para_yes" value="1" style="display:none;" onchange="toggleSpecialLabel('para',true)"> ✓ Yes
              </label>
              <label id="lbl_para_no" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;padding:11px 16px;transition:all .2s;background:rgba(255, 255, 255,0.12);color:var(--gold-400);">
                <input type="radio" name="is_para" id="is_para_no" value="0" checked style="display:none;" onchange="toggleSpecialLabel('para',false)"> ✗ No
              </label>
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>

          <?php if ($isFieldEnabled('is_deaf')): ?>
          <div class="form-group col-half">
            <label style="color:var(--text-secondary);font-weight:600;margin-bottom:8px;display:block;font-size:13px;">Are you a Deaf Athlete? <span class="req" style="display: <?= $isFieldMandatory('is_deaf') ? 'inline' : 'none' ?>;">*</span></label>
            <div style="display:flex;gap:0;border:1px solid rgba(255,255,255,0.12);border-radius:8px;overflow:hidden;">
              <label id="lbl_deaf_yes" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;padding:11px 16px;transition:all .2s;background:rgba(255,255,255,0.03);color:var(--text-muted);border-right:1px solid rgba(255,255,255,0.08);">
                <input type="radio" name="is_deaf" id="is_deaf_yes" value="1" style="display:none;" onchange="toggleSpecialLabel('deaf',true)"> ✓ Yes
              </label>
              <label id="lbl_deaf_no" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;padding:11px 16px;transition:all .2s;background:rgba(255, 255, 255,0.12);color:var(--gold-400);">
                <input type="radio" name="is_deaf" id="is_deaf_no" value="0" checked style="display:none;" onchange="toggleSpecialLabel('deaf',false)"> ✗ No
              </label>
            </div>
            <span class="field-error"></span>
          </div>
          <?php endif; ?>
          <?php endif; ?>

          <!-- Disability Proof Certificate (Visible/Required only if is_para or is_deaf is Yes) -->
          <div class="form-group col-full" id="disability_proof_group" style="display:none;">
            <label for="disability_proof">Disability Certificate / Proof <span class="req">*</span></label>
            <div class="custom-file-upload-wrap" onclick="document.getElementById('disability_proof').click();" style="position:relative; width:100%; height:46px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.03); border-radius:8px; display:flex; align-items:center; padding:0 14px; cursor:pointer; transition:all 0.2s ease-in-out;" onmouseover="this.style.borderColor='rgba(255, 255, 255,0.5)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.12)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--gold-400); margin-right:10px; flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
              </svg>
              <span id="disability_proof_label" style="font-size:13px; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-grow:1; font-weight:500;">Upload File</span>
              <input type="file" id="disability_proof" name="disability_proof" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handleCustomFileUpload(this, 'disability_proof_label')">
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;padding-left:4px;">JPG, PNG or PDF up to 2MB</div>
            <span class="field-error"></span>
          </div>

          <!-- ── SECTION: Club & Affiliation ─────────────────── -->
          <div class="section-divider col-full">
            <span>Club &amp; Association</span>
          </div>

          <!-- 10. Club Name -->
          <div class="form-group col-half">
            <label for="club_name">Club Name <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              <select id="club_name" name="club_name" data-rules="required">
                <option value="">-- SELECT CLUB --</option>
                <?php foreach ($clubs as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 11. District -->
          <div class="form-group col-half">
            <label for="district">District <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <select id="district" name="district" data-rules="required">
                <option value="">-- SELECT DISTRICT --</option>
                <?php foreach ($districts as $d): ?>
                  <option value="<?= htmlspecialchars(strtoupper($d)) ?>"><?= htmlspecialchars(strtoupper($d)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 12. Association -->
          <div class="form-group col-full">
            <label for="association">Association Name <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <input type="text" id="association" name="association" placeholder="District / State association" data-rules="required" maxlength="200">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 13. Membership ID -->
          <div class="form-group col-half">
            <label for="membership_id">Membership ID <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2" ry="2"/><path d="M3 11h18"/></svg>
              <input type="text" id="membership_id" name="membership_id" placeholder="Membership ID" data-rules="required" maxlength="50">
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 14. Membership Photo / PDF -->
          <div class="form-group col-half">
            <label for="membership_doc">Membership Photo / PDF <span class="req">*</span></label>
            <div class="custom-file-upload-wrap" onclick="document.getElementById('membership_doc').click();" style="position:relative; width:100%; height:46px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.03); border-radius:8px; display:flex; align-items:center; padding:0 14px; cursor:pointer; transition:all 0.2s ease-in-out;" onmouseover="this.style.borderColor='rgba(255, 255, 255,0.5)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.12)'">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--gold-400); margin-right:10px; flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
              </svg>
              <span id="membership_doc_label" style="font-size:13px; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-grow:1; font-weight:500;">Upload File</span>
              <input type="file" id="membership_doc" name="membership_doc" accept=".jpg,.jpeg,.png,.pdf" data-rules="required" style="display:none;" onchange="handleCustomFileUpload(this, 'membership_doc_label')">
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;padding-left:4px;">JPG, PNG or PDF – max 2MB</div>
            <span class="field-error"></span>
          </div>

          <!-- Dynamic Custom Fields -->
          <?php foreach ($fieldsConfig as $f): ?>
            <?php if ($f['is_custom'] && $f['is_enabled']): ?>
              <div class="form-group col-full">
                <label for="<?= htmlspecialchars($f['field_id']) ?>">
                  <?= htmlspecialchars($f['field_label']) ?>
                  <span class="req" style="display: <?= $f['is_mandatory'] ? 'inline' : 'none' ?>;">*</span>
                </label>
                <div class="input-wrap">
                  <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>
                  <input type="text" id="<?= htmlspecialchars($f['field_id']) ?>" name="custom_fields[<?= htmlspecialchars($f['field_id']) ?>]" placeholder="Enter <?= htmlspecialchars($f['field_label']) ?>" <?= $f['is_mandatory'] ? 'data-rules="required"' : '' ?> maxlength="255">
                </div>
                <span class="field-error"></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- ── SECTION: Account Security ─────────────────── -->
          <div class="section-divider col-full">
            <span>Account Security</span>
          </div>

          <!-- 13. Password -->
          <div class="form-group col-half">
            <label for="password">Password <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" id="password" name="password" placeholder="Min 8 chars" data-rules="required|minLen:8" maxlength="100" autocomplete="new-password">
              <button type="button" class="toggle-pw" aria-label="Toggle password visibility">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>
              </button>
            </div>
            <!-- Password strength meter -->
            <div class="pw-strength" aria-live="polite">
              <div class="bar"><div class="fill"></div></div>
              <span class="label-text"></span>
            </div>
            <span class="field-error"></span>
          </div>

          <!-- 14. Confirm Password -->
          <div class="form-group col-half">
            <label for="confirm_password">Confirm Password <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" data-rules="required" maxlength="100" autocomplete="new-password">
              <button type="button" class="toggle-pw" aria-label="Toggle confirm password visibility">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>
              </button>
            </div>
            <span class="field-error"></span>
          </div>

          <!-- Email Verification OTP -->
          <?php if ($isFieldEnabled('otp')): ?>
            <div class="form-group col-full" id="otp_group" style="margin-top: 10px;">
              <label for="otp">Email Verification OTP <span class="req" style="display: <?= $isFieldMandatory('otp') ? 'inline' : 'none' ?>;">*</span></label>
              <div style="display:flex; gap:12px;">
                <div class="input-wrap" style="flex-grow:1; position:relative;">
                  <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <input type="text" id="otp" name="otp" placeholder="Enter 6-digit OTP" <?= $isFieldMandatory('otp') ? 'data-rules="required"' : '' ?> maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                </div>
                <button type="button" class="btn btn-outline" id="sendOtpBtn" style="flex-shrink:0; height:46px; min-width:130px; font-size:13px; padding:0 16px;">
                  <div class="spinner"></div>
                  <span class="btn-label">Send OTP</span>
                </button>
              </div>
              <span class="field-error"></span>
            </div>
          <?php endif; ?>

          <!-- Submit Row -->
          <div class="col-full" style="margin-top:8px;">
            <button type="submit" class="btn btn-primary" id="registerBtn">
              <div class="spinner"></div>
              <span class="btn-label">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="vertical-align:-3px;margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Submit Registration
              </span>
            </button>
          </div>

          <!-- Back to Login -->
          <div class="col-full link-row" style="justify-content:center;margin-top:0;">
            <span>Already registered? <a href="login.php">Sign In here</a></span>
          </div>

        </div><!-- /.form-grid -->
      </form>
    </div>
  </main>

<?php require_once 'includes/footer.php'; ?>
<script>
// Para/Deaf Yes/No toggle visual state
function toggleSpecialLabel(type, isYes) {
  const yesLbl = document.getElementById('lbl_' + type + '_yes');
  const noLbl  = document.getElementById('lbl_' + type + '_no');
  if (!yesLbl || !noLbl) return;
  if (isYes) {
    yesLbl.style.background = 'rgba(39,174,96,0.15)';
    yesLbl.style.color      = '#5EDD8E';
    noLbl.style.background  = 'rgba(255,255,255,0.03)';
    noLbl.style.color       = 'var(--text-muted)';
  } else {
    noLbl.style.background  = 'rgba(255, 255, 255,0.12)';
    noLbl.style.color       = 'var(--gold-400)';
    yesLbl.style.background = 'rgba(255,255,255,0.03)';
    yesLbl.style.color      = 'var(--text-muted)';
  }

  // Toggle disability proof container visibility and validation rules
  const paraYes = document.getElementById('is_para_yes').checked;
  const deafYes = document.getElementById('is_deaf_yes').checked;
  const proofGroup = document.getElementById('disability_proof_group');
  const proofInput = document.getElementById('disability_proof');

  if (paraYes || deafYes) {
    proofGroup.style.display = 'flex';
    proofInput.setAttribute('data-rules', 'required');
  } else {
    proofGroup.style.display = 'none';
    proofInput.removeAttribute('data-rules');
    const lbl = document.getElementById('disability_proof_label');
    if (lbl) {
      lbl.textContent = 'Upload File';
      lbl.style.color = 'var(--text-secondary)';
    }
    proofInput.value = '';
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
</script>

