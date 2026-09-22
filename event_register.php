<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

$fieldSettings = [];
$fieldsConfig = [];
try {
    $fieldsConfig = getFieldControls('event_registration');
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

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?expired=1');
    exit;
}

$isRegOpen = true;
$regMessage = '';
$eventInfo = null;

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    $eventInfo = $pdo->query("SELECT * FROM event_info WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $isTripleNow = false;
    if ($eventInfo && !empty($eventInfo['triple_entry_active'])) {
        if (!empty($eventInfo['triple_entry_date'])) {
            $now = date('Y-m-d H:i:s');
            if (strtotime($now) >= strtotime($eventInfo['triple_entry_date'])) {
                $isTripleNow = true;
            }
        } else {
            $isTripleNow = true;
        }
    }
    if ($eventInfo) {
        $now = date('Y-m-d H:i:s');
        if ($eventInfo['reg_start_active'] && !empty($eventInfo['reg_start_date'])) {
            if ($now < $eventInfo['reg_start_date']) {
                $isRegOpen = false;
                $regMessage = "Event registration is scheduled to open on " . date('d-M-Y H:i', strtotime($eventInfo['reg_start_date'])) . ".";
            }
        }
        if ($isRegOpen && $eventInfo['reg_end_active'] && !empty($eventInfo['reg_end_date'])) {
            if ($now > $eventInfo['reg_end_date']) {
                $isRegOpen = false;
                $regMessage = "Event registration closed on " . date('d-M-Y H:i', strtotime($eventInfo['reg_end_date'])) . ".";
            }
        }
    }
} catch (Exception $e) {
    $user = false;
}

if (!$user) {
    header('Location: login.php?expired=1');
    exit;
}

$activeChampionship = getActiveChampionship();
$pageTitle = 'Event Registration – ' . ($activeChampionship['championship_name'] ?? 'TNSC');
require_once 'includes/header.php';
?>
<script src="js/qrcode.min.js"></script>
<?php

if (!$isRegOpen) {
    ?>
    <div style="max-width: 600px; margin: 80px auto; padding: 40px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.4); backdrop-filter: blur(5px);">
      <div style="font-size: 52px; color: var(--gold-400); margin-bottom: 20px; text-shadow: 0 0 10px rgba(255, 255, 255,0.3);">
        <i class="bi bi-calendar-x-fill"></i>
      </div>
      <h2 style="font-family: 'Rajdhani', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #fff; margin-bottom: 12px;">Registration Closed</h2>
      <p style="color: var(--text-secondary); font-size: 15px; line-height: 1.6; margin-bottom: 24px;"><?= htmlspecialchars($regMessage) ?></p>
      <a href="dashboard.php" class="btn btn-primary" style="display: inline-block; width: auto; padding: 12px 30px; font-weight: 600;">Back to Dashboard</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

// Ensure $user is available and compute defaults
$userGender   = $user['gender'] ?? 'Male';
$currentYear  = 2025;
$prevYear     = 2024;
$firstName    = $user['first_name'] ?? '';
$lastName     = $user['last_name'] ?? '';
$fullName     = trim($firstName . ' ' . $lastName);
$dobRaw       = $user['dob'] ?? '';
$dobFormatted = !empty($dobRaw) ? strtoupper(date('d F Y', strtotime($dobRaw))) : '';
$age = !empty($dobRaw) ? (2026 - (int)date('Y', strtotime($dobRaw))) : 0;

$assocUpper = strtoupper($user['association'] ?? '');
$clubUpper = strtoupper($user['club_name'] ?? '');
$isDefence = (strpos($assocUpper, 'DEFENCE') !== false || strpos($assocUpper, 'SERVICES') !== false ||
              strpos($clubUpper, 'DEFENCE') !== false || strpos($clubUpper, 'SERVICES') !== false);

$ageRulesConfig = [];
try {
    $activeCid = (int)($activeChampionship['id'] ?? 1);
    $stmtRules = $pdo->prepare("SELECT shooter_category, min_age, max_age, set_age, allowed_event_categories FROM age_category_rules WHERE championship_id = ? ORDER BY id ASC");
    $stmtRules->execute([$activeCid]);
    while ($r = $stmtRules->fetch()) {
        $allowed = json_decode($r['allowed_event_categories'], true);
        if (!is_array($allowed)) $allowed = array_map('trim', explode(',', $r['allowed_event_categories']));
        $ageRulesConfig[$r['shooter_category']] = [
            'min_age' => isset($r['min_age']) ? (int)$r['min_age'] : 0,
            'max_age' => isset($r['max_age']) ? (int)$r['max_age'] : 120,
            'set_age' => isset($r['max_age']) ? (int)$r['max_age'] : 120,
            'allowed' => $allowed
        ];
    }
    if (empty($ageRulesConfig)) {
        $stmtRulesFallback = $pdo->query("SELECT shooter_category, min_age, max_age, set_age, allowed_event_categories FROM age_category_rules ORDER BY id ASC");
        while ($r = $stmtRulesFallback->fetch()) {
            $allowed = json_decode($r['allowed_event_categories'], true);
            if (!is_array($allowed)) $allowed = array_map('trim', explode(',', $r['allowed_event_categories']));
            $ageRulesConfig[$r['shooter_category']] = [
                'min_age' => isset($r['min_age']) ? (int)$r['min_age'] : 0,
                'max_age' => isset($r['max_age']) ? (int)$r['max_age'] : 120,
                'set_age' => isset($r['max_age']) ? (int)$r['max_age'] : 120,
                'allowed' => $allowed
            ];
        }
    }
} catch (Throwable $e) {}

function getPhpAgeGroup(int $age, array $rules = []): string {
    $order = ['Sub Youth', 'Youth', 'Junior', 'Senior', 'Master', 'Senior Master', 'Super Master'];
    foreach ($order as $cat) {
        if (isset($rules[$cat])) {
            $min = (int)($rules[$cat]['min_age'] ?? 0);
            $max = (int)($rules[$cat]['max_age'] ?? 120);
            if ($age >= $min && $age <= $max) {
                return $cat;
            }
        }
    }
    if ($age <= 16) return 'Sub Youth';
    if ($age <= 19) return 'Youth';
    if ($age <= 21) return 'Junior';
    if ($age <= 44) return 'Senior';
    if ($age <= 59) return 'Master';
    if ($age <= 69) return 'Senior Master';
    return 'Super Master';
}

$autoAgeGroup = getPhpAgeGroup($age, $ageRulesConfig);

// Load draft and past sessions data
$activeDraft = false;
$draftEvents = [];
$draftEventsJson = '[]';
$draftIssfNum = '';
$draftDisType = '';
$draftClassif = '';
$pastSessions = [];
$previousCategory = null;

try {
    // 1. Fetch active draft session
    $draftStmt = $pdo->prepare("SELECT * FROM registration_sessions WHERE user_id = ? AND approval_status = 'draft' LIMIT 1");
    $draftStmt->execute([$user['id']]);
    $activeDraft = $draftStmt->fetch();

    $draftCustomFields = [];
    if ($activeDraft) {
        // Fetch events for this draft session
        $eventsStmt = $pdo->prepare("SELECT * FROM event_registrations WHERE session_id = ?");
        $eventsStmt->execute([$activeDraft['id']]);
        $draftEvents = $eventsStmt->fetchAll();
        $draftEventsJson = json_encode($draftEvents);

        // Fetch global fields from the first event (all events in a session share these)
        if (!empty($draftEvents)) {
            $draftIssfNum = $draftEvents[0]['issf_number'] ?? '';
            $draftDisType = $draftEvents[0]['disability_type'] ?? '';
            $draftClassif = $draftEvents[0]['classification'] ?? '';
        }

        // Fetch custom fields
        $draftCustomFields = getCustomFieldValues('event_reg', (int)$activeDraft['id']);
    }

    // 2. Fetch past sessions (non-draft) with event count
    $pastStmt = $pdo->prepare("
        SELECT s.*, COUNT(e.id) as evt_count 
        FROM registration_sessions s
        LEFT JOIN event_registrations e ON s.id = e.session_id
        WHERE s.user_id = ? AND s.approval_status != 'draft'
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ");
    $pastStmt->execute([$user['id']]);
    $pastSessions = $pastStmt->fetchAll();

    // 3. Fetch previously registered category (non-draft)
    $prevCatStmt = $pdo->prepare("SELECT category FROM event_registrations WHERE user_id = ? AND status != 'draft' LIMIT 1");
    $prevCatStmt->execute([$user['id']]);
    $prevCatRow = $prevCatStmt->fetch();
    $previousCategory = $prevCatRow ? $prevCatRow['category'] : null;

    // 4. Fetch all non-rejected registered events for this user (except for the active draft session if any)
    $registeredEvents = [];
    $regEvtsStmt = $pdo->prepare("
        SELECT event_name 
        FROM event_registrations 
        WHERE user_id = ? 
          AND status != 'rejected'
          " . ($activeDraft ? "AND (session_id IS NULL OR session_id != " . (int)$activeDraft['id'] . ")" : "") . "
    ");
    $regEvtsStmt->execute([$user['id']]);
    $registeredEvents = $regEvtsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignore database fetch issues gracefully
}
?>
    <style>
/* ── Progress Steps ── */
.progress-steps{display:none !important;}

/* ── Panel Section (Single Page Layout) ── */
#masterForm {
  display: flex;
  flex-direction: column;
  gap: 0;
}
#masterForm > .wizard-step[data-step="1"] { order: 1; }
#masterForm > .wizard-step[data-step="2"] { order: 2; }
#masterForm > .wizard-step[data-step="3"] { order: 3; }
#masterForm > .wizard-step[data-step="4"] { order: 4; }
#masterForm > .wizard-step[data-step="5"] { order: 5; }
#masterForm > .wizard-step[data-step="6"] { order: 6; }
.panel-section {
  background: var(--grad-panel);
  border: var(--border-dark);
  border-radius: var(--radius-md);
  padding: 28px;
  margin-bottom: 24px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.panel-section-title {
  font-family: 'Cinzel', serif;
  font-size: 18px;
  font-weight: 600;
  color: var(--gold-400);
  border-bottom: 1px solid rgba(255, 255, 255,0.2);
  padding-bottom: 8px;
  margin-bottom: 20px;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* ── Category Cards ── */
.cat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:16px 0 24px;}
.cat-card{background:rgba(255,255,255,0.02);border:var(--border-dark);border-radius:var(--radius-md);padding:20px 16px;cursor:pointer;text-align:left;transition:all .25s;position:relative;}
.cat-card:hover{border-color:var(--gold-500);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3);}
.cat-card.selected{border-color:var(--gold-500);background:rgba(255, 255, 255,.07);box-shadow: 0 0 15px rgba(255, 255, 255,0.15);}
.cc-check{position:absolute;top:10px;right:10px;width:20px;height:20px;border-radius:50%;border:2px solid var(--dark-500);display:flex;align-items:center;justify-content:center;transition:all .2s;}
.cat-card.selected .cc-check{border-color:var(--gold-500);background:var(--gold-500);color:var(--dark-900);}
.cc-icon{font-size:26px;margin-bottom:8px;}
.cc-title{font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:var(--text-primary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.cc-desc{font-size:11px;color:var(--text-muted);line-height:1.5;margin-bottom:8px;}
.cc-badge{display:inline-block;padding:2px 9px;background:rgba(255, 255, 255,.15);color:var(--gold-400);border-radius:20px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}

/* ── Enhanced Event Registration Table ── */
.evt-reg-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  margin-top: 10px;
}
.evt-reg-table th {
  background: rgba(255, 255, 255,0.06);
  color: var(--gold-400);
  font-family: 'Rajdhani', sans-serif;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  padding: 12px 10px;
  border-bottom: 2px solid rgba(255, 255, 255,0.3);
  text-align: left;
}
.evt-reg-table td {
  padding: 12px 10px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  vertical-align: middle;
  transition: background-color 0.2s;
}
.evt-reg-table tr:hover td {
  background: rgba(255,255,255,0.02);
}
.evt-reg-table .tbl-input {
  background: var(--dark-800);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 4px;
  padding: 6px 8px;
  color: var(--text-primary);
  font-size: 12px;
  width: 100%;
  box-sizing: border-box;
  transition: all 0.2s ease;
}
.evt-reg-table .tbl-input:focus {
  border-color: var(--gold-500);
  background: var(--dark-900);
  box-shadow: 0 0 8px rgba(255, 255, 255,0.2);
  outline: none;
}
.evt-reg-table select.tbl-input {
  appearance: auto;
}

/* ── Custom Certificate Upload Button ── */
.cert-upload-btn-wrapper {
  position: relative;
  display: inline-block;
  width: 100%;
  max-width: 130px;
}
.cert-upload-label {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: rgba(255, 255, 255,0.08);
  border: 1px dashed rgba(255, 255, 255,0.35);
  color: var(--gold-400);
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  text-align: center;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: all 0.2s ease;
}
.cert-upload-label:hover {
  background: rgba(255, 255, 255,0.15);
  border-color: var(--gold-500);
}
.cert-upload-btn-wrapper input[type=file] {
  position: absolute;
  left: 0;
  top: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

/* ── MPS Banners & Results ── */
.mps-banner{background:rgba(255, 255, 255,.06);border:1px solid rgba(255, 255, 255,.25);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:var(--text-secondary);margin-top:8px;display:none;gap:10px;align-items:center;}
.mps-banner.show{display:flex;}
.mps-val{color:var(--gold-400);font-weight:700;font-size:15px;}
.mps-result{border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;font-weight:600;margin-top:10px;display:none;gap:10px;align-items:center;}
.mps-result.show{display:flex;}
.mps-result.pass{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:#5EDD8E;}
.mps-result.fail{background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.25);color:#FF8C82;}

/* ── File Upload Area (Payment) ── */
.file-upload-area{border:2px dashed rgba(255, 255, 255,.3);border-radius:var(--radius-md);padding:28px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.file-upload-area:hover,.file-upload-area.dragover{border-color:var(--gold-500);background:rgba(255, 255, 255,.04);}
.file-upload-area input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}

.file-upload-area-small{border:1.5px dashed rgba(255, 255, 255,.35);border-radius:var(--radius-md);padding:16px 20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;background:rgba(255,255,255,0.01);}
.file-upload-area-small:hover,.file-upload-area-small.dragover{border-color:var(--gold-500);background:rgba(255, 255, 255,.04);}

/* ── Custom Checkbox ── */
.custom-checkbox {
  display: inline-flex;
  align-items: flex-start;
  gap: 12px;
  font-size: 13px;
  cursor: pointer;
  color: var(--text-secondary);
  user-select: none;
}
.custom-checkbox input[type="checkbox"] {
  appearance: none;
  -webkit-appearance: none;
  width: 18px;
  height: 18px;
  border: 1.5px solid rgba(255, 255, 255, 0.4);
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.25);
  cursor: pointer;
  position: relative;
  flex-shrink: 0;
  margin-top: 1px;
  transition: all 0.2s ease;
}
.custom-checkbox input[type="checkbox"]:hover {
  border-color: var(--gold-500);
}
.custom-checkbox input[type="checkbox"]:checked {
  background: var(--gold-500);
  border-color: var(--gold-500);
}
.custom-checkbox input[type="checkbox"]:checked::after {
  content: "";
  position: absolute;
  left: 5px;
  top: 1px;
  width: 5px;
  height: 10px;
  border: solid var(--dark-900);
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
}

/* ── Payment Box ── */
.payment-detail-box{background:rgba(255, 255, 255,.03);border:1px solid rgba(255, 255, 255,.15);border-radius:var(--radius-md);padding:20px;}
.pay-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.pay-row:last-child{border-bottom:none;}
.pay-key{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;}
.pay-val{font-size:14px;font-weight:600;color:var(--text-primary);}

.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.f-err{color:var(--danger);font-size:11px;margin-top:3px;min-height:14px;}
.nrmqs-alert{background:rgba(243,156,18,.1);border:1px solid rgba(243,156,18,.3);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:#F9CA72;display:none;margin-bottom:18px;gap:10px;align-items:flex-start;}
.nrmqs-alert.show{display:flex;}

/* ── Secondary Button ── */
.btn-secondary{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:var(--text-primary);padding:10px 18px;border-radius:var(--radius-sm);font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .2s;text-decoration:none;white-space:nowrap;}
.btn-secondary:hover{background:rgba(255,255,255,0.1);border-color:var(--gold-500);}

@media(max-width:900px){
  .wizard-progress{
    display:flex !important;
    overflow-x:auto !important;
    gap:8px !important;
    padding-bottom:8px;
    -webkit-overflow-scrolling:touch;
    white-space:nowrap;
    grid-template-columns:none !important;
  }
  .wizard-step-indicator{
    flex:0 0 auto !important;
    padding:8px 12px !important;
    font-size:11px !important;
  }
}

.wizard-progress{
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:10px;
  margin-bottom:24px;
}

.wizard-step-indicator{
  background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.12);
  border-radius:999px;
  padding:10px 12px;
  font-size:12px;
  font-weight:700;
  color:var(--text-muted);
  text-align:center;
  transition:all .2s ease;
}

.wizard-step-indicator.active{
  background:rgba(255, 255, 255,0.14);
  border-color:rgba(255, 255, 255,0.35);
  color:var(--gold-400);
}

.wizard-step-indicator.completed{
  background:rgba(255,255,255,0.05);
  border-color:rgba(255,255,255,0.15);
  color:var(--text-primary);
}

.form-container-card {
  background:var(--grad-panel);
  border:var(--border-gold);
  border-radius:var(--radius-lg);
  padding:34px 38px;
  box-shadow:var(--shadow-card);
}

@media(max-width:600px){
  .cat-grid,.form-row-2,.form-row-3{grid-template-columns:1fr;}
  .evt-reg-table{font-size:10px;}
  .evt-reg-table th, .evt-reg-table td{padding:8px 6px;}
  .evt-reg-table .tbl-input{font-size:10px;padding:5px 6px;}
  .form-container-card {
    padding: 16px 14px !important;
    border-radius: var(--radius-md) !important;
  }
  .dashboard-wrap {
    padding: 10px 5px !important;
  }
}
  input[type=number]::-webkit-inner-spin-button, 
  input[type=number]::-webkit-outer-spin-button { 
    -webkit-appearance: none; 
    margin: 0; 
  }
  input[type=number] {
    -moz-appearance: textfield;
  }
  .btn-back-portal {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--gold-400);
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 8px;
    padding: 12px 24px;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  }
  .btn-back-portal:hover {
    background: var(--gold-400);
    color: var(--dark-950) !important;
    border-color: var(--gold-400);
    transform: translateX(-4px);
    box-shadow: 0 8px 24px rgba(255, 255, 255, 0.25);
  }
</style>

<main>
<div class="dashboard-wrap" style="max-width:840px;padding:20px 0;">

  <!-- Back to Dashboard on the Left -->
  <div style="margin-bottom: 24px; text-align: left;">
    <a href="dashboard.php" class="btn-back-portal">
      ← Back to Dashboard
    </a>
  </div>

  <!-- Header -->
  <div class="dash-header">
    <div>
      <div class="welcome">Event Registration</div>
      <div class="user-name"><?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></div>
      <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
        <span class="status-badge pending" style="font-size:11px;"><?= htmlspecialchars($fullName ?: 'Guest') ?></span>
      </div>
    </div>
  </div>

  <!-- Past Sessions -->
  <?php if (!empty($pastSessions)): ?>
  <div style="background:var(--grad-panel);border:var(--border-dark);border-radius:var(--radius-md);padding:20px;margin-bottom:22px;">
    <div class="section-divider" style="margin-bottom:14px;"><span>Your Registration History</span></div>
    <?php foreach ($pastSessions as $s):
      $payBadge   = ['unpaid'=>'pending','uploaded'=>'pending','verified'=>'active'];
      $apprBadge  = ['pending'=>'pending','approved'=>'approved','rejected'=>'rejected'];
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.04);">
      <div style="flex:1;min-width:180px;">
        <div style="color:var(--gold-400);font-weight:700;font-size:13px;"><?= htmlspecialchars($s['session_id']) ?></div>
        <div style="color:var(--text-muted);font-size:11px;"><?= $s['evt_count'] ?> event(s) &bull; <?= strtoupper(date('d M Y', strtotime($s['created_at']))) ?></div>
      </div>
      <div style="font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--gold-400);">
        ₹<?= number_format((float)$s['total_amount'], 2) ?>
      </div>
      <span class="status-badge <?= $apprBadge[$s['approval_status']] ?>" style="font-size:11px;">
        <?= ucfirst($s['approval_status']) ?>
      </span>
      <span class="status-badge <?= $payBadge[$s['payment_status']] ?>" style="font-size:10px;">
        Pmt: <?= ucfirst($s['payment_status']) ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
      if ($isTripleNow) {
          $catBadgeIssf = "10M: ₹3,000 + GST (₹3,540) | 25M/50M: ₹4,500 + GST (₹5,310) ⚡ 3x TRIPLE ENTRY";
          $catBadgeNr   = "10M: ₹3,000 + GST (₹3,540) | 25M/50M: ₹4,500 + GST (₹5,310) ⚡ 3x TRIPLE ENTRY";
      } else {
          $catBadgeIssf = "10M: ₹1,000 + GST (₹1,180) | 25M/50M: ₹1,500 + GST (₹1,770)";
          $catBadgeNr   = "10M: ₹1,000 + GST (₹1,180) | 25M/50M: ₹1,500 + GST (₹1,770)";
      }
  ?>
  <!-- Triple Entry Notice Banner -->
  <?php if ($eventInfo && !empty($eventInfo['triple_entry_active'])): ?>
  <div id="tripleEntryBanner" style="background:rgba(231,76,60,0.08); border:1px solid rgba(231,76,60,0.25); border-left:4px solid #e74c3c; border-radius:var(--radius-md); padding:16px 20px; margin-bottom:24px; display:<?= $isTripleNow ? 'flex' : 'none' ?>; align-items:center; gap:12px;">
    <div style="font-size:24px; color:#e74c3c; flex-shrink:0;"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div>
      <div style="font-weight:700; color:#e74c3c; font-size:13.5px; text-transform:uppercase; letter-spacing:0.5px;">Triple Entry (Late Entry Fine) Active</div>
      <div style="font-size:12px; color:var(--text-secondary); margin-top:2px; line-height:1.5;">
        The regular event registration period has ended. The late entry period is now active, and a **3x multiplier** is applied to all event entry fees.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Message Box -->
  <div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

  <!-- Draft Notice Banner -->
  <?php if (!empty($activeDraft)): ?>
  <div id="draftNoticeBanner" style="background:rgba(255, 255, 255,.1);border:1px solid rgba(255, 255, 255,.4);border-radius:var(--radius-md);padding:16px 20px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="font-weight:700;color:var(--gold-400);font-size:13px;text-transform:uppercase;letter-spacing:.5px;">📂 Saved Draft Found</div>
      <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">You have a saved draft from <?= strtoupper(date('d M Y, H:i', strtotime($activeDraft['created_at'] ?? 'now'))) ?> (<?= isset($draftEvents) ? count($draftEvents) : 0 ?> events).</div>
    </div>
    <div style="display:flex;gap:10px;">
      <button type="button" class="btn btn-primary" onclick="resumeDraft()" style="font-size:11px;padding:8px 16px;">Resume Draft</button>
      <button type="button" class="btn btn-danger" onclick="discardDraft(<?= isset($activeDraft['id']) ? (int)$activeDraft['id'] : 0 ?>)" style="font-size:11px;padding:8px 16px;background:rgba(231,76,60,.15);color:var(--danger);border-color:rgba(231,76,60,.3);">Discard</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ FORM ══ -->
  <div class="form-container-card">
  <form id="masterForm" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" id="cartJson" name="cart_json" value="[]">
    <input type="hidden" id="draftSessionId" name="draft_session_id" value="">
    <input type="hidden" id="selectedWeapon" name="selected_weapon" value="">
    <input type="hidden" id="termsAccepted" name="terms_accepted" value="0">

    <div class="wizard-progress" aria-label="Registration steps">
      <div class="wizard-step-indicator active" data-step="1">1. Weapon</div>
      <div class="wizard-step-indicator" data-step="2">2. Details</div>
      <div class="wizard-step-indicator" data-step="3">3. Category</div>
      <div class="wizard-step-indicator" data-step="4">4. Events</div>
      <div class="wizard-step-indicator" data-step="5">5. Terms</div>
      <div class="wizard-step-indicator" data-step="6">6. Payment</div>
    </div>

    <!-- ═════════════════════════════=
         STEP 4 – Events & Qualifications
    ══════════════════════════════ -->
    <div class="panel-section wizard-step" id="eventsSection" data-step="4" style="display:none;">
      <div class="panel-section-title">4. Events &amp; Qualifications</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;">Manage events for this session. Add rows and fill details below.</p>

      <!-- Category & Age Group Banner info -->
      <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">
        <div id="s3CatInfo" class="status-badge active" style="font-size:11px;"></div>
        <span id="s3AgeInfo" class="status-badge" style="font-size:11px;background:rgba(255, 255, 255,.1);color:var(--gold-400);border:1px solid rgba(255, 255, 255,.2);padding:3px 10px;border-radius:20px;"></span>
      </div>

      <!-- ISSF Number field (for ISSF & NR_MQS categories) -->
      <div id="sec_issf_num" style="display:none;margin-bottom:20px; <?= !$isFieldEnabled('issf_number') ? 'display:none !important;' : '' ?>">
        <div class="form-group" style="max-width:400px;">
          <label for="inp_issf_global">Certificate No. <span class="req" style="display: <?= $isFieldMandatory('issf_number') ? 'inline' : 'none' ?>;">*</span></label>
          <div class="input-wrap">
            <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            <input type="text" id="inp_issf_global" placeholder="e.g. IND-2024-00123" maxlength="50" oninput="document.querySelectorAll('.issf-num-val').forEach(el=>el.textContent=this.value)">
          </div>
          <div class="f-err" id="err_issf_g"></div>
        </div>
      </div>

      <!-- Dynamic Custom Fields for Event Registration -->
      <div id="sec_custom_evt_fields" style="margin-bottom:20px;">
        <?php foreach ($fieldsConfig as $f): ?>
          <?php if ($f['is_custom'] && $f['is_enabled']): ?>
            <div class="form-group" style="max-width:400px; margin-bottom:16px;">
              <label for="<?= htmlspecialchars($f['field_id']) ?>">
                <?= htmlspecialchars($f['field_label']) ?>
                <span class="req" style="display: <?= $f['is_mandatory'] ? 'inline' : 'none' ?>;">*</span>
              </label>
              <div class="input-wrap">
                <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>
                <input type="text" id="<?= htmlspecialchars($f['field_id']) ?>" placeholder="Enter <?= htmlspecialchars($f['field_label']) ?>" class="custom-evt-field" data-fid="<?= htmlspecialchars($f['field_id']) ?>" data-label="<?= htmlspecialchars($f['field_label']) ?>" data-req="<?= $f['is_mandatory'] ? '1' : '0' ?>" value="<?= htmlspecialchars($draftCustomFields[$f['field_id']] ?? '') ?>" maxlength="255">
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Para/Deaf fields -->
      <div id="sec_para_fields" style="display:none;margin-bottom:20px;">
        <div class="form-row-2">
          <div class="form-group">
            <label for="sel_dis">Disability Type <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
              <select id="sel_dis">
                <option value="">— Select —</option>
                <option>Physical Impairment – Upper Limb</option>
                <option>Physical Impairment – Lower Limb</option>
                <option>Physical Impairment – Both Limbs</option>
                <option>Visual Impairment</option>
                <option>Hearing Impairment (Deaf)</option>
                <option>Intellectual Impairment</option>
                <option>Other</option>
              </select>
            </div>
            <div class="f-err" id="err_dis"></div>
          </div>
          <div class="form-group">
            <label for="sel_cls">Sports Classification <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
              <select id="sel_cls">
                <option value="">— Select —</option>
                <option>SH1 – Pistol/Rifle (no stand)</option>
                <option>SH2 – Rifle (stand required)</option>
                <option>VI B1 – No light perception</option>
                <option>VI B2 – Some vision</option>
                <option>VI B3 – Best eye 6/60</option>
                <option>Deaf – Hearing Impaired</option>
              </select>
            </div>
            <div class="f-err" id="err_cls"></div>
          </div>
        </div>
      </div>

      <div class="nrmqs-alert" id="nrmqsAlert">
        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:16px;"></i>
        <span>Your score did not meet the ISSF Minimum Performance Score (MPS). <a href="javascript:void(0)" onclick="switchToNrCategory()" style="color:var(--gold-400);text-decoration:underline;font-weight:700;">click here to move to nr events</a>.</span>
      </div>

      <!-- Enhanced Event Table -->
      <div style="overflow-x:auto;">
      <table class="evt-reg-table" id="evtRegTable">
        <thead>
          <tr id="evtTblHeader">
            <th style="width:30px;">S.No</th>
            <th style="width:100px;">Event No.</th>
            <th>Event Name</th>
            <th style="width:100px; display: <?= $isFieldEnabled('issf_number') ? 'table-cell' : 'none' ?>;" class="issf-col">MQS Score</th>
            <th style="width:120px; display: <?= $isFieldEnabled('mqs_score') ? 'table-cell' : 'none' ?>;" class="issf-col mqs-col">Achieved Score <span class="req" style="display: <?= $isFieldMandatory('mqs_score') ? 'inline' : 'none' ?>;">*</span></th>
            <th style="width:160px; display: <?= $isFieldEnabled('mqs_score') ? 'table-cell' : 'none' ?>;" class="issf-col">Comp. Name <span class="req" style="display: <?= $isFieldMandatory('mqs_score') ? 'inline' : 'none' ?>;">*</span></th>
            <th style="width:100px; display: <?= $isFieldEnabled('shooting_year') ? 'table-cell' : 'none' ?>;" class="issf-col">Year <span class="req" style="display: <?= $isFieldMandatory('shooting_year') ? 'inline' : 'none' ?>;">*</span></th>
            <th style="width:150px; display: <?= $isFieldEnabled('certificate_path') ? 'table-cell' : 'none' ?>;" class="issf-col">Certificate <span class="req" style="display: <?= $isFieldMandatory('certificate_path') ? 'inline' : 'none' ?>;">*</span></th>
            <th style="width:140px;text-align:right;">Fee (incl. 18% GST)</th>
            <th style="width:40px;text-align:center;"></th>
          </tr>
        </thead>
        <tbody id="evtTblBody">
          <!-- Rows added dynamically -->
        </tbody>
      </table>
      </div>

      <div class="f-err" id="err_tbl" style="font-size:13px;margin-top:10px;"></div>

      <div style="margin-top:18px;">
        <button type="button" class="btn btn-secondary" id="addRowBtn" onclick="addTableRow()" style="gap:6px;border-color:var(--gold-500);color:var(--gold-400);">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          Add Event Row
        </button>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="gotoStep(3)" style="height:42px;padding:0 24px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">Previous</button>
        <div style="display:flex;gap:10px;align-items:center;">
          <button type="button" class="btn btn-secondary" onclick="saveAsDraft()" style="height:42px;padding:0 18px;font-size:13px;font-weight:700;letter-spacing:0.5px;white-space:nowrap;width:auto;flex-shrink:0;overflow:visible;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.18);color:#E9ECEF;"><i class="bi bi-bookmark-fill" style="font-size:14px;flex-shrink:0;"></i><span>Save as Draft</span></button>
          <button type="button" class="btn btn-primary" onclick="gotoNextStep()" style="height:42px;padding:0 24px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">Next: Terms</button>
        </div>
      </div>
    </div>
    <div class="panel-section wizard-step" data-step="1" style="display:none;">
      <div class="panel-section-title" style="text-align:center;">1. Weapon</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;text-align:center;">Select your primary weapon type before continuing.</p>
      <div style="max-width: 500px; margin: 0 auto;">
        <div class="cat-grid">
          <button type="button" class="cat-card" onclick="selectWeapon('Rifle', true)" id="weapon_Rifle">
            <div class="cc-check" id="weapon_chk_Rifle"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
            <div class="cc-icon"><i class="bi bi-bullseye"></i></div>
            <div class="cc-title">Rifle</div>
            <div class="cc-desc">Filter events to rifle categories first.</div>
          </button>
          <button type="button" class="cat-card" onclick="selectWeapon('Pistol', true)" id="weapon_Pistol">
            <div class="cc-check" id="weapon_chk_Pistol"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
            <div class="cc-icon"><i class="bi bi-crosshair"></i></div>
            <div class="cc-title">Pistol</div>
            <div class="cc-desc">Filter events to pistol categories first.</div>
          </button>
        </div>
        <div class="f-err" id="err_weapon" style="margin-bottom:12px;text-align:center;"></div>
      </div>
    </div>

    <!-- ══════════════════════════════
         PANEL 1 – Personal Details (Read-only)
    ══════════════════════════════ -->
    <div class="panel-section wizard-step" data-step="2" style="display:none;">
      <div class="panel-section-title" style="text-align:center;">2. Personal Details</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;text-align:center;">Pre-filled from your registered profile.</p>
      <div style="max-width: 500px; margin: 0 auto;">
        <div class="form-row-2" style="margin-bottom:16px;">
          <div class="form-group"><label>Full Name</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" value="<?= htmlspecialchars($fullName) ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
          <div class="form-group"><label>Date of Birth</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><input type="text" value="<?= htmlspecialchars($dobFormatted) ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
        </div>
        <div class="form-row-2" style="margin-bottom:16px;">
          <div class="form-group"><label>Age / Gender</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg><input type="text" value="<?= htmlspecialchars($age) ?> yrs / <?= htmlspecialchars($user['gender'] ?? '') ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
          <div class="form-group"><label>District</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><input type="text" value="<?= htmlspecialchars($user['district'] ?? '') ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
        </div>
        <div class="form-row-2" style="margin-bottom:24px;">
          <div class="form-group"><label>Club</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg><input type="text" value="<?= htmlspecialchars($user['club_name'] ?? '') ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
          <div class="form-group"><label>Association</label><div class="input-wrap"><svg class="i-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg><input type="text" value="<?= htmlspecialchars($user['association'] ?? '') ?>" readonly style="background:rgba(255,255,255,0.02);color:var(--text-muted);"></div></div>
        </div>
        <div style="display:flex;justify-content:space-between;gap:12px;">
          <button type="button" class="btn btn-secondary" onclick="gotoStep(1)" style="padding:12px 24px;font-size:13px;">Previous</button>
          <button type="button" class="btn btn-primary" onclick="gotoNextStep()" style="padding:12px 24px;font-size:13px;">Next: Category</button>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════
         PANEL 2 – Registration Category
    ══════════════════════════════ -->
    <div class="panel-section wizard-step" data-step="3" style="display:none;">
      <div class="panel-section-title" style="text-align:center;">3. Select Category</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;text-align:center;">Choose your registration category. You can register for <strong>one category per session</strong>.</p>
      <div style="max-width: 500px; margin: 0 auto;">
        <div class="cat-grid">
          <?php $hasSpecial = (($user['is_para'] ?? 0) || ($user['is_deaf'] ?? 0)); ?>
          <button type="button" class="cat-card" onclick="selectCat('ISSF', true)" id="cat_ISSF" style="<?= ($isDefence) ? 'display:none;' : '' ?>">
            <div class="cc-check" id="chk_ISSF"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
            <div class="cc-icon"><i class="bi bi-award-fill"></i></div>
            <div class="cc-title">ISSF Events</div>
            <div class="cc-desc"><?= $hasSpecial ? 'Para/Deaf athletes competing at national level. ISSF number required.' : 'Valid ISSF number required. MPS score validation &amp; certificate upload.' ?></div>
            <div class="cc-badge"><?= $catBadgeIssf ?></div>
          </button>
          <button type="button" class="cat-card" onclick="selectCat('NR', true)" id="cat_NR">
            <div class="cc-check" id="chk_NR"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
            <div class="cc-icon"><?= $hasSpecial ? '<i class="bi bi-universal-access"></i>' : '<i class="bi bi-crosshair"></i>' ?></div>
            <div class="cc-title">NR Events</div>
            <div class="cc-desc"><?= $hasSpecial ? 'Para/Deaf athletes competing at state level.' : 'State/non-national athletes under Tamil Nadu shooting associations.' ?></div>
            <div class="cc-badge" style="background:rgba(41,128,185,.15);color:#5dade2;"><?= $catBadgeNr ?></div>
          </button>
        </div>
        <div class="f-err" id="err_cat" style="margin-bottom:10px;font-size:13px;"></div>
        <input type="hidden" id="selCategory" name="sel_category" value="">
        <input type="hidden" id="genRegId" name="gen_reg_id" value="">
        
        <div style="display:flex;justify-content:flex-start;gap:12px;margin-top:20px;">
          <button type="button" class="btn btn-secondary" onclick="gotoStep(2)" style="padding:12px 24px;font-size:13px;">Previous</button>
        </div>
      </div>
    </div>

    <div class="panel-section wizard-step" data-step="5" style="display:none;">
      <div class="panel-section-title" style="text-align:center;">5. Terms and Conditions</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;text-align:center;">Please review and accept the terms before you proceed to payment.</p>
      <div style="max-width: 500px; margin: 0 auto;">
        <div id="termsAndConditionsContent" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:18px; margin-bottom:18px; line-height:1.6; font-size:13px; color:var(--text-muted); max-height:350px; overflow-y:auto;">
          <strong style="color:var(--gold-400); font-size:15px; display:block; margin-bottom:12px;">Terms &amp; Conditions</strong>
          <div style="display:flex; flex-direction:column; gap:12px;">
            <p><strong>1. Individual &amp; Team Events:</strong> The Competition will be conducted in individual and team events. Team events will be conducted in Peep Sight Rifle, Open Sight Rifle &amp; Pistol except Masters, Senior Master and Super Master category of matches. Team Entry Fee will be Rs.3,000/- (Rupees Three Thousand Only) plus 18% GST per event. Team entry must be submitted by 17:00 Hrs. on the previous day of the start of the match/event. The competition will be conducted in the respective events as per the match list.</p>
            
            <p><strong>2. Weapon Entry Restriction:</strong> Athletes entering in Peep Sight Rifle matches cannot enter in Open Sight Rifle matches and vice versa. Rifle shooter can participate in Pistol events and vice versa.</p>
            
            <p><strong>3. Category Restriction:</strong> Athletes taking part in ISSF category matches cannot take part in NR category matches in that event and vice versa. Athletes who have attended the Nationals can only take part in the ISSF category of that event. (e.g., Athlete qualified in ISSF Air Event Sub-Youth Category cannot take part in NR Air Event Junior or Senior Category).</p>
            
            <div>
              <strong>4. Age Limit &amp; Match Book Rules:</strong> Athletes completing the age below in 2026 will be allowed to participate under age categories matches as per Rule 11 of NRAI Match Book:
              <table style="width:100%; border-collapse:collapse; margin-top:8px; margin-bottom:8px; font-size:12px; background:rgba(0,0,0,0.2); border-radius:6px; overflow:hidden;">
                <thead>
                  <tr style="background:rgba(255,255,255,0.05); text-align:left;">
                    <th style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Category</th>
                    <th style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Age Criteria</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Seniors</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Above the age of 21 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Juniors</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 21 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Youth</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 18 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Sub Youth</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 16 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Masters</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Between age of 45 years to 59 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Senior Masters</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Between age of 60 years to 69 years</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Super Masters</td>
                    <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Age of 70 years and above</td>
                  </tr>
                </tbody>
              </table>
              <ul style="margin:6px 0 0 16px; padding-left:0; list-style:circle; font-size:12px;">
                <li>Sub Youth can participate in Youth, Junior and Senior Category also.</li>
                <li>Youth can participate in Junior and Senior Category.</li>
                <li>Junior and Masters can participate in Senior Category also.</li>
                <li>Senior Master can participate in Senior and Masters Category.</li>
                <li>Super Master can participate in Senior Master, Master &amp; Senior Category.</li>
              </ul>
              <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">* Participation in various categories requires additional entry fee.</div>
            </div>
            
            <p><strong>5. Firearm Age Restriction:</strong> Athletes below the age of 12 years are not allowed to participate in the firearm matches during the championship.</p>
            
            <p><strong>6. Age Proof:</strong> Proof of age / original certificate for Sub-Youth, Youth and Junior category must be kept in person for verification. It is mandatory to produce a valid Photo ID Card (NRAI ID, Aadhaar, School ID, or Government Photo ID) on demand by the Range Officer.</p>
            
            <p><strong>7. Online Entries Only:</strong> Entries are accepted only through respective NRAI's affiliated State Rifle Associations / Units on the NRAI online system (www.thenrai.org). Only online payment is accepted. Single entry fee entries are open till 09:30 PM on 21st July 2026. Late entries with triple entry fee are accepted till 09:30 PM on 23rd July 2026.</p>
            
            <p><strong>8. Competitor Cards:</strong> Competitor cards for NR events will be available online after approval. Athletes must print their competitor card and produce them to Range Officers before the match starts.</p>
            
            <p><strong>9. Arms &amp; Ammunition:</strong> Organizers will not supply arms or ammunition. Athletes/Units must bring their own arms and ammunition.</p>
            
            <p><strong>10. Weapon Sharing:</strong> Weapon sharing is limited to the number of details allotted to the affiliated unit for that match. Weapon sharing details must be informed to range officers before the start of the event.</p>
            
            <p><strong>11. Specifications:</strong> All weapons must be as per the NRAI and ISSF Specification.</p>
            
            <p><strong>12. MQS Penalties:</strong> Participants who do not secure the Minimum Qualifying Score of 40% in Open Sight Air Rifle/Open Sight Rifle Events and 50% in Pistol and Peep Sight Rifle Events will pay a penalty fee of Rs. 2,000/- per event to the Tamilnadu Shooting Association. Penalty is not applicable for Youth, Sub Youth, Senior Master, and Super Masters.</p>
            
            <p><strong>13. Dress Code &amp; Uniform:</strong> Athletes must appear as sports persons and wear proper uniform. The following are strictly prohibited on the range:
              <ul style="margin:4px 0 0 16px; padding-left:0; list-style:square; font-size:12px;">
                <li>Chappals and sandals</li>
                <li>Jeans</li>
                <li>Sleeveless tops / miniskirts</li>
                <li>All types of pants and shorts with external pockets (e.g. Cargo, Camouflage, Khaki, Olive, Brown).</li>
              </ul>
            </p>
            
            <p><strong>14. Parental Consent:</strong> All female shooters under the age of 18 must submit the Parental Consent form to the organizer before the start of the event.</p>
            
            <p><strong>15. Internal Committee (POSH):</strong> In terms of the provisions of the POSH Act 2013, the Nominated Internal Committee members of the TNSA are:
              <ul style="margin:4px 0 0 16px; padding-left:0; list-style:none; font-size:12px;">
                <li>• <strong>Chairperson:</strong> Dr. Mahalakshmi Winfred</li>
                <li>• <strong>Member:</strong> Ms. Similal Singh</li>
                <li>• <strong>Member:</strong> Ms. Sulthania Exan Mariym</li>
              </ul>
            </p>
            
            <p><strong>16. Photography &amp; Phones:</strong> Photography is strictly prohibited inside the range, except by authorized press photographers. Cell phones are strictly banned inside the shooting range.</p>
            
            <p><strong>17. Protest Fee:</strong> Protest fee is Rs. 200/-. Appeal to the Jury of Appeal is Rs. 500/-. The fee will be refunded if the protest is upheld and retained if lost.</p>
            
            <p><strong>18. Discipline:</strong> Organizing Committee decisions are final. Participants must abide by all rules and regulations. Discipline on and off the field is essential. Shooting practice in hotels is strictly prohibited.</p>
            
            <p><strong>19. Rules Book:</strong> All matches will be conducted as per the NRAI Match Book.</p>
            
            <p><strong>20. Spotting Scope:</strong> Athletes must bring their own spotting scope for 25M and 50M ranges.</p>
          </div>
        </div>
        <label class="custom-checkbox">
          <input type="checkbox" id="termsCheckbox" onchange="toggleTerms()">
          <span>I agree to the terms and conditions.</span>
        </label>
        <div class="f-err" id="err_terms" style="margin-top:10px;"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap;">
          <button type="button" class="btn btn-secondary" onclick="gotoStep(4)" style="height:42px;padding:0 24px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">Previous</button>
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" class="btn btn-secondary" onclick="saveAsDraft()" style="height:42px;padding:0 18px;font-size:13px;font-weight:700;letter-spacing:0.5px;white-space:nowrap;width:auto;flex-shrink:0;overflow:visible;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.18);color:#E9ECEF;"><i class="bi bi-bookmark-fill" style="font-size:14px;flex-shrink:0;"></i><span>Save as Draft</span></button>
            <button type="button" class="btn btn-primary" onclick="gotoNextStep()" style="height:42px;padding:0 24px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">Next: Payment</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════
         PANEL 6 – Payment & Verification Proof
    ══════════════════════════════ -->
    <div class="panel-section wizard-step" id="paymentSection" data-step="6" style="display:none;">
      <div class="panel-section-title" style="text-align:center;">6. Payment Verification</div>
      <p style="color:var(--text-secondary);font-size:12px;margin-top:-14px;margin-bottom:20px;text-align:center;">Pay the total event fee and upload the bank transaction receipt screenshot.</p>
      
      <div style="max-width: 500px; margin: 0 auto;">
        <!-- Total Due Badge -->
        <div style="background:rgba(255, 255, 255,.05);border:1px solid rgba(255, 255, 255,0.25);border-radius:var(--radius-md);padding:14px 20px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Total Amount Payable</div>
            <div style="font-size:11px;color:var(--text-muted);" id="payEvtCount">0 event(s)</div>
          </div>
          <div style="font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:800;color:var(--gold-400);" id="payAmtDisplay">₹0</div>
        </div>

        <!-- ⚠️ Non-Refundable Notice -->
        <div style="display:flex;align-items:flex-start;gap:10px;background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.4);border-left:4px solid #e74c3c;border-radius:8px;padding:12px 16px;margin-bottom:18px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="#e74c3c" stroke-width="2.2" style="flex-shrink:0;margin-top:1px;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          </svg>
          <div>
            <div style="font-family:'Rajdhani',sans-serif;font-size:13px;font-weight:700;color:#e74c3c;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:2px;">⚠ Amount is Non-Refundable</div>
            <div style="font-size:11px;color:rgba(255,255,255,0.65);line-height:1.6;">The above amount is <strong style="color:rgba(255,255,255,0.9);">strictly non-refundable</strong> once payment is made. Please verify your event selections before proceeding.</div>
          </div>
        </div>

        <!-- Triple Entry Multiplier Notice -->
        <div id="paymentTripleNotice" style="display:none; align-items:flex-start; gap:10px; background:rgba(255, 255, 255,0.1); border:1px solid rgba(255, 255, 255,0.4); border-left:4px solid var(--gold-400); border-radius:8px; padding:12px 16px; margin-bottom:18px;">
          <div style="font-size:18px; color:var(--gold-400); margin-top:1px; flex-shrink:0;"><i class="bi bi-clock-fill"></i></div>
          <div>
            <div style="font-family:'Rajdhani',sans-serif;font-size:13px;font-weight:700;color:var(--gold-300);letter-spacing:0.5px;text-transform:uppercase;margin-bottom:2px;">⚠ Triple Entry Multiplier Applied</div>
            <div style="font-size:11px;color:rgba(255,255,255,0.65);line-height:1.6;">As registrations are currently in the late entry period, all event entry fees have been multiplied by **3** (Triple Entry).</div>
          </div>
        </div>

        <!-- Fee Breakdown Container -->
        <div id="feeBreakdownContainer" style="background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,0.08);border-radius:var(--radius-md);padding:16px;margin-bottom:18px;display:none;">
          <div style="font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:var(--gold-400);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;border-bottom:1px solid rgba(255,255,255,0.06);padding-bottom:6px;">
            Fee Breakdown
          </div>
          <div id="feeBreakdownList" style="display:flex;flex-direction:column;gap:8px;font-size:12px;margin-bottom:12px;">
            <!-- Dynamic items go here -->
          </div>
          <div style="display:flex;justify-content:space-between;border-top:1px solid rgba(255,255,255,0.06);margin-top:10px;padding-top:8px;font-weight:600;font-size:13px;">
            <span style="color:var(--text-secondary);">Subtotal (Excl. GST)</span>
            <span id="bdSubtotal" style="color:var(--text-primary);">₹0</span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:4px;">
            <span>Total GST (18%)</span>
            <span id="bdGst">₹0</span>
          </div>
        </div>

        <!-- Bank Details Box (Vertical Key-Value Rows) -->
        <div style="background:rgba(255, 255, 255,.03);border:1px solid rgba(255, 255, 255,0.15);border-radius:var(--radius-md);padding:20px;margin-bottom:18px;">
          <div style="font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:var(--gold-400);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid rgba(255, 255, 255,0.15);padding-bottom:10px;margin-bottom:12px;text-align:center;">
            Bank Transfer Information
          </div>
          <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;">
            <div style="display:flex;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.04);">
              <span style="color:var(--text-muted);">Bank Name</span>
              <span style="color:var(--text-primary);font-weight:600;">State Bank of India</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.04);">
              <span style="color:var(--text-muted);">Account Name</span>
              <span style="color:var(--text-primary);font-weight:600;">TARGET</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.04);">
              <span style="color:var(--text-muted);">Account Number</span>
              <span style="color:var(--text-primary);font-weight:600;font-family:'Rajdhani',sans-serif;letter-spacing:0.5px;">1234567890</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.04);">
              <span style="color:var(--text-muted);">IFSC Code</span>
              <span style="color:var(--text-primary);font-weight:600;">SBIN0001234</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding-top:2px;">
              <span style="color:var(--text-muted);">UPI ID</span>
              <span style="display:flex;align-items:center;gap:6px;color:var(--text-primary);font-weight:600;">
                Vyapar.169929914091@hdfcbank
                <button type="button" onclick="copyUPI()" style="background:rgba(255, 255, 255,.1);border:1px solid rgba(255, 255, 255,.3);color:var(--gold-400);border-radius:4px;padding:2px 6px;font-size:10px;cursor:pointer;">Copy</button>
              </span>
            </div>

            <!-- QR Code — clean, UI-native widget -->
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.06);display:flex;flex-direction:column;align-items:center;gap:8px;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:10px;font-weight:700;color:var(--gold-400);text-transform:uppercase;letter-spacing:1px;">📱 Scan &amp; Pay</span>
                <span style="font-size:9px;color:var(--text-muted);background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:3px;padding:1px 6px;font-weight:600;">UPI</span>
                <span style="font-size:9px;color:var(--text-muted);background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:3px;padding:1px 6px;font-weight:600;">Cards</span>
              </div>
              <div id="payment_qr_container" style="background:#fff;border-radius:10px;padding:10px;width:158px;height:158px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 16px rgba(0,0,0,0.45);overflow:hidden;">
                <img id="payment_qr_img" src="images/payment_qr.png" alt="UPI QR Code" style="width:138px;height:138px;display:block;image-rendering:crisp-edges;" onerror="this.onerror=null;this.src='images/payment_qr.png';">
              </div>
              <p style="margin:0;font-size:10px;color:var(--text-muted);text-align:center;line-height:1.5;">
                PhonePe &nbsp;·&nbsp; GPay &nbsp;·&nbsp; Paytm &nbsp;·&nbsp; BHIM &nbsp;·&nbsp; Any UPI app
              </p>
            </div>

          </div>
        </div>

        <!-- Medium-sized screenshot upload row -->
        <div class="form-group" style="margin-bottom:18px;">
          <label style="text-align:center;display:block;margin-bottom:8px;font-size:12px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Payment Receipt Screenshot <span class="req">*</span></label>
          <div class="file-upload-area-small" id="payDropArea" style="position:relative;display:flex;align-items:center;justify-content:center;gap:12px;padding:12px 18px;border:1.5px dashed rgba(255, 255, 255,.35);border-radius:6px;background:rgba(255,255,255,0.01);max-width:320px;margin:0 auto;cursor:pointer;">
            <input type="file" id="payScreenshot" name="payment_screenshot" accept=".jpg,.jpeg,.png,.pdf" onchange="showPayName(this)" style="position:absolute;left:0;top:0;opacity:0;width:100%;height:100%;cursor:pointer;">
            <span style="font-size:20px;">🧾</span>
            <div style="text-align:left;">
              <div style="font-size:12px;color:var(--text-secondary);font-weight:600;">Upload payment screenshot</div>
              <div style="font-size:10px;color:var(--text-muted);margin-top:1px;">JPG, PNG, PDF – max 5MB</div>
            </div>
          </div>
          <div id="payName" style="font-size:12px;color:var(--gold-400);margin-top:6px;display:none;font-weight:600;text-align:center;"></div>
          <div class="f-err" id="err_pay" style="text-align:center;"></div>
        </div>

        <!-- Verification Proof Information -->
        <div style="background:rgba(41,128,185,.08);border:1px solid rgba(41,128,185,.25);border-radius:var(--radius-sm);padding:12px;margin-bottom:20px;text-align:center;">
          <p style="font-size:11px;color:#7EC8F0;margin:0;line-height:1.5;">
            ℹ️ Your uploaded payment details will be verified by the Superadmin. You will receive an email notification when your entries are approved.
          </p>
        </div>

        <!-- Buttons Container (Back bottom left, Save Draft & Submit bottom right) -->
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:20px;flex-wrap:wrap;">
          <button type="button" class="btn btn-secondary" onclick="gotoStep(5)" style="height:42px;padding:0 24px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">Previous</button>
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" class="btn btn-secondary" onclick="saveAsDraft()" style="height:42px;padding:0 18px;font-size:13px;font-weight:700;letter-spacing:0.5px;white-space:nowrap;width:auto;flex-shrink:0;overflow:visible;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.18);color:#E9ECEF;"><i class="bi bi-bookmark-fill" style="font-size:14px;flex-shrink:0;"></i><span>Save as Draft</span></button>
            <button type="submit" class="btn btn-primary" id="submitBtn" style="height:42px;padding:0 28px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
              <span class="btn-label">Submit Registration</span>
              <span class="spinner" style="display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,0.6);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;"></span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 5 – Submit -->
    <!-- Final actions moved into Payment step (submit happens after uploading payment screenshot) -->

  </form>
  </div><!-- /.card -->
</div><!-- /.dashboard-wrap -->
</main>

<script>
const REG_CONTROL = <?= json_encode([
  'reg_start_active' => (int)($eventInfo['reg_start_active'] ?? 0),
  'reg_start_date' => $eventInfo['reg_start_date'] ?? '',
  'reg_end_active' => (int)($eventInfo['reg_end_active'] ?? 0),
  'reg_end_date' => $eventInfo['reg_end_date'] ?? '',
  'triple_entry_active' => (int)($eventInfo['triple_entry_active'] ?? 0),
  'triple_entry_date' => $eventInfo['triple_entry_date'] ?? '',
  'triple_entry_now' => $isTripleNow ? 1 : 0
]) ?>;
// ── Constants ──
const USER_GENDER = '<?= $userGender ?>';
const USER_AGE    = <?= $age ?>;
const AUTO_AGE_GROUP = '<?= $autoAgeGroup ?>';
const IS_PARA      = <?= (int)($user['is_para'] ?? 0) ?>;
const IS_DEAF      = <?= (int)($user['is_deaf'] ?? 0) ?>;
const IS_PARA_DEAF = IS_PARA || IS_DEAF;
const IS_DEFENCE   = <?= $isDefence ? 'true' : 'false' ?>;
const CURRENT_YEAR = <?= $currentYear ?>;
const PREV_YEAR    = <?= $prevYear ?>;
const PREVIOUS_CATEGORY = <?= json_encode($previousCategory) ?>;
const REGISTERED_EVENTS = <?= json_encode($registeredEvents ?? []) ?>;

const ISSF_MPS_MATRIX = {
  // Pistol
  '50m_free_pistol': {
    senior: { Male: 500, Female: 490 },
    junior: { Male: 490, Female: 480 },
    master: { Male: 490, Female: 480 },
    senior_master: { Male: 480, Female: 470 },
    super_master: { Male: 470, Female: 460 }
  },
  '25m_rapid_fire_pistol': {
    senior: { Male: 525 },
    junior: { Male: 515 },
    master: { Male: 515 },
    senior_master: { Male: 500 },
    super_master: { Male: 490 }
  },
  '25m_centre_fire_pistol': {
    senior: { Male: 545 },
    master: { Male: 535 },
    senior_master: { Male: 515 },
    super_master: { Male: 505 }
  },
  '25m_standard_pistol': {
    senior: { Male: 530 },
    junior: { Male: 520, Female: 505 },
    master: { Male: 520 },
    senior_master: { Male: 490 },
    super_master: { Male: 485 }
  },
  '25m_sport_pistol': {
    senior: { Female: 530 },
    junior: { Male: 530, Female: 520 },
    master: { Female: 520 },
    senior_master: { Female: 510 },
    super_master: { Female: 490 }
  },
  '10m_air_pistol': {
    senior: { Male: 550, Female: 535 },
    junior: { Male: 545, Female: 525 },
    master: { Male: 540, Female: 525 },
    senior_master: { Male: 530, Female: 515 },
    super_master: { Male: 505, Female: 495 },
    youth: { Male: 535, Female: 515 },
    sub_youth: { Male: 520, Female: 505 }
  },
  // Rifle
  '50m_rifle_prone': {
    senior: { Male: 567, Female: 560 },
    junior: { Male: 560, Female: 550 },
    master: { Male: 560, Female: 550 },
    senior_master: { Male: 550, Female: 540 },
    super_master: { Male: 540, Female: 530 }
  },
  '50m_rifle_3_positions': {
    senior: { Male: 540, Female: 530 },
    junior: { Male: 520, Female: 515 },
    master: { Male: 530, Female: 525 },
    senior_master: { Male: 520, Female: 515 },
    super_master: { Male: 510, Female: 505 }
  },
  '10m_air_rifle': {
    senior: { Male: 570, Female: 570 },
    junior: { Male: 560, Female: 560 },
    master: { Male: 570, Female: 560 },
    senior_master: { Male: 560, Female: 550 },
    super_master: { Male: 550, Female: 540 },
    youth: { Male: 555, Female: 555 },
    sub_youth: { Male: 545, Female: 545 }
  },
  // Shotgun
  'trap': {
    senior: { Male: 95, Female: 66 },
    junior: { Male: 75, Female: 60 },
    master: { Male: 85, Female: 61 },
    senior_master: { Male: 75, Female: 60 },
    super_master: { Male: 60, Female: 50 }
  },
  'double_trap': {
    senior: { Male: 85, Female: 45 },
    junior: { Male: 58, Female: 40 },
    master: { Male: 73, Female: 40 },
    senior_master: { Male: 58, Female: 40 },
    super_master: { Male: 46, Female: 35 }
  },
  'skeet': {
    senior: { Male: 95, Female: 66 },
    junior: { Male: 75, Female: 60 },
    master: { Male: 85, Female: 61 },
    senior_master: { Male: 75, Female: 60 },
    super_master: { Male: 60, Female: 50 }
  },
  // Big Bore
  '300m_rifle_prone': {
    senior: { Male: 540, Female: 540 },
    junior: { Male: 515, Female: 500 },
    master: { Male: 525, Female: 520 },
    senior_master: { Male: 515, Female: 500 },
    super_master: { Male: 500, Female: 485 }
  },
  '300m_rifle_3_positions': {
    senior: { Male: 525, Female: 500 },
    junior: { Male: 475, Female: 450 },
    master: { Male: 500, Female: 470 },
    senior_master: { Male: 475, Female: 450 },
    super_master: { Male: 435, Female: 425 }
  },
  '300m_std_rifle_3_positions': {
    senior: { Male: 500 },
    junior: { Male: 450 },
    master: { Male: 475 },
    senior_master: { Male: 450 },
    super_master: { Male: 430 }
  }
};

function getMpsForEvent(eventValue) {
  const ev = ALL_EVENTS.find(e => e.value === eventValue);
  if (!ev) return null;

  // 1. Get Base Type
  let baseType = getEventBaseType(ev.label);
  
  // Custom adjustments for Shotgun & Big Bore base types to match keys
  const u = ev.label.toUpperCase();
  if (u.includes('DOUBLE TRAP')) baseType = 'double_trap';
  else if (u.includes('TRAP')) baseType = 'trap';
  else if (u.includes('SKEET')) baseType = 'skeet';
  else if (u.includes('300M RIFLE PRONE')) baseType = '300m_rifle_prone';
  else if (u.includes('300M RIFLE 3 POSITIONS') || u.includes('300M RIFLE 3 POSITION')) baseType = '300m_rifle_3_positions';
  else if (u.includes('300M STD R') || u.includes('300M STANDARD')) baseType = '300m_std_rifle_3_positions';

  if (!baseType || !ISSF_MPS_MATRIX[baseType]) return null;

  // 2. Get Age Group
  const ageGroup = getEventAgeGroup(ev.label); // 'sub_youth', 'youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'

  // 3. Get Gender
  let gender = ev.gender || 'Male';
  if (gender === 'All') {
    // Fall back to USER_GENDER or parsed gender from label
    gender = (u.includes('WOMEN') || u.includes('FEMALE')) ? 'Female' : 'Male';
  }

  // 4. Retrieve score from matrix
  const groupScores = ISSF_MPS_MATRIX[baseType][ageGroup];
  if (!groupScores) return null;

  return groupScores[gender] ?? null;
}

const EVENT_FEE = {
  '10m Air Rifle Men':500,'10m Air Rifle Women':500,
  '50m Rifle 3 Positions Men':600,'50m Rifle 3 Positions Women':600,'50m Rifle Prone Men':600,
  '10m Air Pistol Men':500,'10m Air Pistol Women':500,
  '25m Rapid Fire Pistol Men':550,'25m Pistol Women':550,'50m Pistol Men':550,
  'Trap Men':700,'Trap Women':700,'Double Trap Men':700,'Skeet Men':700,'Skeet Women':700
};

const CAT_FEE  = {ISSF:0,NR:0,PARA_DEAF:0};
const CAT_LABELS = {ISSF:'ISSF Events',NR:'NR Events',PARA_DEAF:'Para / Deaf'};
const AGE_RULES_CONFIG = <?= json_encode($ageRulesConfig ?? []) ?>;

// Age group helpers
const AGE_GROUP_LABELS = {
  sub_youth: 'Sub Youth',
  youth: 'Youth',
  junior: 'Junior',
  senior: 'Senior',
  master: 'Master',
  senior_master: 'Senior Master',
  super_master: 'Super Master'
};

function getEventAgeGroup(label) {
  const u = label.toUpperCase();
  if (u.includes('SUB YOUTH')) return 'sub_youth';
  if (u.includes('YOUTH')) return 'youth';
  if (u.includes('JUNIOR')) return 'junior';
  if (u.includes('SUPER MASTER')) return 'super_master';
  if (u.includes('SENIOR MASTER')) return 'senior_master';
  if (u.includes('MASTER')) return 'master';
  return 'senior';
}

function getUserAgeGroup(age) {
  if (typeof AGE_RULES_CONFIG !== 'undefined' && Object.keys(AGE_RULES_CONFIG).length > 0) {
    const mapping = {
      'Sub Youth': 'sub_youth',
      'Youth': 'youth',
      'Junior': 'junior',
      'Senior': 'senior',
      'Master': 'master',
      'Senior Master': 'senior_master',
      'Super Master': 'super_master'
    };
    for (const [catName, catKey] of Object.entries(mapping)) {
      const cfg = AGE_RULES_CONFIG[catName];
      if (cfg) {
        const min = cfg.min_age !== undefined ? parseInt(cfg.min_age) : 0;
        const max = cfg.max_age !== undefined ? parseInt(cfg.max_age) : 120;
        if (age >= min && age <= max) {
          return catKey;
        }
      }
    }
  }

  if (age <= 16) return 'sub_youth';
  if (age <= 19) return 'youth';
  if (age <= 21) return 'junior';
  if (age <= 44) return 'senior';
  if (age <= 59) return 'master';
  if (age <= 69) return 'senior_master';
  return 'super_master';
}

function isEligibleAgeGroup(userGroup, eventGroup) {
  if (userGroup === eventGroup) return true;

  if (typeof AGE_RULES_CONFIG !== 'undefined' && Object.keys(AGE_RULES_CONFIG).length > 0) {
    const revMap = {
      'sub_youth': 'Sub Youth',
      'youth': 'Youth',
      'junior': 'Junior',
      'senior': 'Senior',
      'master': 'Master',
      'senior_master': 'Senior Master',
      'super_master': 'Super Master'
    };
    const userCatName = revMap[userGroup];
    const eventCatName = revMap[eventGroup];
    if (userCatName && eventCatName && AGE_RULES_CONFIG[userCatName] && Array.isArray(AGE_RULES_CONFIG[userCatName].allowed)) {
      return AGE_RULES_CONFIG[userCatName].allowed.includes(eventCatName);
    }
  }

  if (userGroup === 'sub_youth') {
    return ['youth', 'junior', 'senior'].includes(eventGroup);
  }
  if (userGroup === 'youth') {
    return ['junior', 'senior'].includes(eventGroup);
  }
  if (userGroup === 'junior') {
    return ['senior'].includes(eventGroup);
  }
  if (userGroup === 'master') {
    return ['senior'].includes(eventGroup);
  }
  if (userGroup === 'senior_master') {
    return ['master', 'senior'].includes(eventGroup);
  }
  if (userGroup === 'super_master') {
    return ['senior_master', 'master', 'senior'].includes(eventGroup);
  }
  return false;
}

function getEventBaseType(label) {
  const u = label.toUpperCase();
  if (u.includes('10M AIR RIFLE STANDING SH1')) return '10m_air_rifle_standing_sh1';
  if (u.includes('10M AIR RIFLE PRONE SH1')) return '10m_air_rifle_prone_sh1';
  if (u.includes('50M RIFLE PRONE SH1')) return '50m_rifle_prone_sh1';
  if (u.includes('50M RIFLE 3 POSITIONS SH1') || u.includes('50M RIFLE 3 POSITION SH1')) return '50m_rifle_3_positions_sh1';
  if (u.includes('10M AIR RIFLE STANDING SH2')) return '10m_air_rifle_standing_sh2';
  if (u.includes('10M AIR RIFLE PRONE SH2')) return '10m_air_rifle_prone_sh2';
  if (u.includes('50M RIFLE PRONE SH2')) return '50m_rifle_prone_sh2';
  if (u.includes('10M AIR PISTOL SH1')) return '10m_air_pistol_sh1';
  if (u.includes('25M PISTOL SH1')) return '25m_pistol_sh1';
  if (u.includes('50M PISTOL SH1')) return '50m_pistol_sh1';
  if (u.includes('10M STANDARD AIR PISTOL SH1')) return '10m_standard_air_pistol_sh1';

  if (u.includes('10M OPEN SIGHT AIR RIFLE')) return '10m_open_sight_air_rifle';
  if (u.includes('10M PEEP SIGHT AIR RIFLE') || u.includes('PEEP RIFLE') || u.includes('PEEP SIGHT') || u.includes('10M AIR RIFLE') || u.includes('10M RIFLE')) return '10m_air_rifle';
  if (u.includes('50M OPEN SIGHT RIFLE PRONE')) return '50m_open_sight_rifle_prone';
  if (u.includes('50M RIFLE PRONE')) return '50m_rifle_prone';
  if (u.includes('50M OPEN SIGHT RIFLE 3 POSITIONS') || u.includes('50M OPEN SIGHT RIFLE 3 POSITION')) return '50m_open_sight_rifle_3_positions';
  if (u.includes('50M RIFLE 3 POSITIONS') || u.includes('50M RIFLE 3 POSITION')) return '50m_rifle_3_positions';
  
  if (u.includes('10M AIR PISTOL') || u.includes('10M PISTOL')) return '10m_air_pistol';
  if (u.includes('25M SPORTS PISTOL') || u.includes('25M SPORT PISTOL') || u.includes('25M PISTOL')) return '25m_sport_pistol';
  if (u.includes('25M CENTRE FIRE PISTOL') || u.includes('25M CENTER FIRE PISTOL') || u.includes('25M CENTRE') || u.includes('25M CENTER')) return '25m_centre_fire_pistol';
  if (u.includes('25M STANDARD PISTOL') || u.includes('25M STANDARD')) return '25m_standard_pistol';
  if (u.includes('50M FREE PISTOL') || u.includes('50M PISTOL') || u.includes('50M FREE')) return '50m_free_pistol';

  return '';
}

function getAllowedAgeGroupsForEvent(baseType, gender) {
  const g = gender.toUpperCase();
  
  if (baseType === '10m_air_rifle' || baseType === '10m_open_sight_air_rifle' || baseType === '10m_air_pistol') {
    return ['sub_youth', 'youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
  }
  if (baseType === '50m_rifle_prone' || baseType === '50m_rifle_3_positions') {
    return ['youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
  }
  if (baseType === '50m_open_sight_rifle_prone' || baseType === '50m_open_sight_rifle_3_positions') {
    return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
  }
  if (baseType === '25m_sport_pistol') {
    if (g.includes('WOMEN') || g.includes('FEMALE')) {
      return ['youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
    } else {
      return ['youth', 'junior', 'senior'];
    }
  }
  if (baseType === '25m_centre_fire_pistol') {
    return ['senior', 'master', 'senior_master', 'super_master'];
  }
  if (baseType === '25m_standard_pistol') {
    if (g.includes('WOMEN') || g.includes('FEMALE')) {
      return ['junior', 'senior'];
    } else {
      return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
    }
  }
  if (baseType === '50m_free_pistol') {
    return ['junior', 'senior', 'master', 'senior_master', 'super_master'];
  }
  
  // Para/Deaf SH1 / SH2 events
  if (baseType === '10m_air_rifle_standing_sh1' || baseType === '10m_air_pistol_sh1') {
    return ['youth', 'junior', 'senior'];
  }
  if (baseType === '10m_air_rifle_prone_sh1' || baseType === '50m_rifle_prone_sh1' || baseType === '50m_rifle_3_positions_sh1' ||
      baseType === '10m_air_rifle_standing_sh2' || baseType === '10m_air_rifle_prone_sh2' || baseType === '50m_rifle_prone_sh2' ||
      baseType === '25m_pistol_sh1' || baseType === '50m_pistol_sh1' || baseType === '10m_standard_air_pistol_sh1') {
    return ['junior', 'senior'];
  }

  return ['sub_youth', 'youth', 'junior', 'senior', 'master', 'senior_master', 'super_master'];
}

function isEventAllowedForUser(eventLabel, eventGender, userAgeGroup) {
  const eventAgeGroup = getEventAgeGroup(eventLabel);
  if (!isEligibleAgeGroup(userAgeGroup, eventAgeGroup)) {
    return false;
  }
  const baseType = getEventBaseType(eventLabel);
  const allowedGroups = getAllowedAgeGroupsForEvent(baseType, eventGender || 'Male');
  return allowedGroups.includes(eventAgeGroup);
}

const USER_AGE_GROUP = getUserAgeGroup(USER_AGE);

const EVENT_FIELD_SETTINGS = <?= json_encode($fieldSettings) ?>;
function isFieldEnabled(fid) {
    return !EVENT_FIELD_SETTINGS[fid] || EVENT_FIELD_SETTINGS[fid].enabled === 1;
}
function isFieldMandatory(fid) {
    return !EVENT_FIELD_SETTINGS[fid] || EVENT_FIELD_SETTINGS[fid].mandatory === 1;
}

// All 253 events from the official 51st TN State Shooting Championship 2026 event list
const ALL_EVENTS = <?= json_encode(array_values(require 'config/events.php')) ?>;

// Get events filtered by category, gender, age group, and para/deaf status
function getFilteredEvents() {
  const cat = finalCat;
  const weapon = document.getElementById('selectedWeapon')?.value || '';
  
  const rawFiltered = ALL_EVENTS.filter(e => {
    // 0) Skip if the event has already been registered and NOT rejected (outside current draft)
    if (typeof REGISTERED_EVENTS !== 'undefined' && REGISTERED_EVENTS.includes(e.value)) {
      return false;
    }

    // 1) Participant Type check and event isolation
    if (IS_PARA) {
      // Para athlete: Show only Para events
      if (!e.no.startsWith('R')) return false;
    } else if (IS_DEAF || IS_DEFENCE) {
      // Deaf/Defence athlete: Show only Deaf/Defence events
      const isDeafEvent = e.no.startsWith('DS');
      const labelUpper = e.label.toUpperCase();
      const isDefenceEvent = labelUpper.includes('SERVICES') || labelUpper.includes('DEFENCE');
      if (!isDeafEvent && !isDefenceEvent) return false;
    } else {
      // Standard athlete:
      // Category match
      if (cat && e.cats && !e.cats.includes(cat)) return false;
      
      // Do not show special/deaf/para/defence events to standard athletes
      const isDeafEvent = e.no.startsWith('DS');
      const isParaEvent = e.no.startsWith('R');
      const labelUpper = e.label.toUpperCase();
      const isDefenceEvent = labelUpper.includes('SERVICES') || labelUpper.includes('DEFENCE');
      if (isDeafEvent || isParaEvent || isDefenceEvent) return false;
    }

    // 2) Weapon match
    if (weapon) {
      const group = e.group.toUpperCase();
      if (weapon === 'Rifle' && !group.includes('RIFLE')) return false;
      if (weapon === 'Pistol' && !group.includes('PISTOL')) return false;
      if (weapon === 'Shotgun' && !group.includes('TRAP') && !group.includes('SKEET') && !group.includes('SHOTGUN')) return false;
    }

    // 3) Gender filter
    if (USER_GENDER === 'Male') {
      if (e.gender === 'Female') return false;
    } else if (USER_GENDER === 'Female') {
      if (e.gender === 'Male') return false;
    }

    // 4) Gender filter
    if (e.gender === 'All' || USER_GENDER === 'Transgender') return true;
    if (e.gender !== USER_GENDER) return false;

    // 5) Age group filter (applied to both NR and ISSF events, validating allowed classes per event type)
    if (!isEventAllowedForUser(e.label, e.gender, USER_AGE_GROUP)) return false;

    return true;
  });

  // 5) Deduplicate final list of events
  const uniqueFiltered = [];
  const seen = new Set();
  for (const e of rawFiltered) {
    if (!seen.has(e.value)) {
      seen.add(e.value);
      uniqueFiltered.push(e);
    }
  }

  return uniqueFiltered;
}

// Validate that an event matches the user's age group
function validateEventAgeGroup(eventValue) {
  const ev = ALL_EVENTS.find(e => e.value === eventValue);
  if (!ev) return true;
  return isEventAllowedForUser(ev.label, ev.gender, USER_AGE_GROUP);
}

// Weapon type from event name
function getWeaponType(eventVal) {
  const ev = ALL_EVENTS.find(e => e.value === eventVal);
  if (!ev) return 'Air Rifle';
  const g = ev.group.toUpperCase();
  if (g.includes('RIFLE')) return 'Air Rifle';
  if (g.includes('PISTOL')) return 'Air Pistol';
  if (g.includes('TRAP') || g.includes('SKEET') || g.includes('SHOTGUN')) return 'Trap Gun';
  return 'Air Rifle';
}

// Event fee based on event group
function getEventFeeDetails(eventVal) {
  if (!eventVal) {
    let base = 1000;
    if (typeof REG_CONTROL !== 'undefined' && (REG_CONTROL.triple_entry_now == 1 || REG_CONTROL.triple_entry_now === true)) base *= 3;
    const gst = Math.round(base * 0.18);
    return { base, gst, total: base + gst, label: '', group: '' };
  }
  const strVal = String(eventVal).toUpperCase();
  const ev = ALL_EVENTS.find(e => e.value === eventVal || e.no === eventVal || e.label === eventVal);
  const label = ev ? ev.label.toUpperCase() : strVal;
  const group = ev ? ev.group.toUpperCase() : '';

  let base = 1000;
  if (label.includes('TEAM') || group.includes('TEAM') || strVal.includes('TEAM')) {
    base = 3000;
  } else if (label.includes('10M') || group.includes('10M') || strVal.includes('10M')) {
    base = 1000;
  } else if (label.includes('25M') || group.includes('25M') || label.includes('50M') || group.includes('50M') || strVal.includes('25M') || strVal.includes('50M')) {
    base = 1500;
  } else {
    base = 1000;
  }

  // Triple Entry late fee multiplier check
  let multiplier = 1;
  if (typeof REG_CONTROL !== 'undefined' && (REG_CONTROL.triple_entry_now == 1 || REG_CONTROL.triple_entry_now === true)) {
    multiplier = 3;
  }
  base = base * multiplier;

  const gst = Math.round(base * 0.18);
  const total = base + gst;
  return { base, gst, total, label: ev ? ev.label : eventVal, group: ev ? ev.group : '' };
}

function getEventFee(eventVal) {
  return getEventFeeDetails(eventVal).total;
}

// ── State ──
let selCat     = '';
let finalCat   = '';
let evtRowCount= 0;
let generatedRegId = '';

// ── Terms & Conditions Helper ──
function getTermsHTML(cat) {
  const isIssf = (cat === 'ISSF');
  
  const rule1 = isIssf 
    ? "<strong>1. Individual &amp; Team Events:</strong> The Competition will be conducted in individual and team events. Team events will be conducted in Peep Sight Rifle &amp; Pistol except Masters, Senior Master and Super Master category of matches. Team Entry Fee will be Rs.3,000/- (Rupees Three Thousand Only) plus 18% GST per event. Team entry must be submitted by 17:00 Hrs. on the previous day of the start of the match/event. The competition will be conducted in the respective events as per the match list."
    : "<strong>1. Individual &amp; Team Events:</strong> The Competition will be conducted in individual and team events. Team events will be conducted in Peep Sight Rifle, Open Sight Rifle &amp; Pistol except Masters, Senior Master and Super Master category of matches. Team Entry Fee will be Rs.3,000/- (Rupees Three Thousand Only) plus 18% GST per event. Team entry must be submitted by 17:00 Hrs. on the previous day of the start of the match/event. The competition will be conducted in the respective events as per the match list.";

  const rule2 = isIssf
    ? "<strong>2. Weapon Entry Restriction:</strong> Rifle shooter can participate in Pistol events and vice versa."
    : "<strong>2. Weapon Entry Restriction:</strong> Athletes entering in Peep Sight Rifle matches cannot enter in Open Sight Rifle matches and vice versa. Rifle shooter can participate in Pistol events and vice versa.";

  const rule7 = isIssf
    ? "<strong>7. Online Entries Only:</strong> The ISSF entries will be accepted from TNSA affiliated units/clubs and Pondicherry Shooting Association through the following <a href=\"https://www.tendotnine.com\" target=\"_blank\" style=\"color:var(--gold-400);text-decoration:underline;\">WWW.TENDOTNINE.COM</a> and Payment will be accepted via G-Pay/UPI/QR Code and Bank Transfer (Bank Details) given in the above-mentioned Web Link. Last date with Single entry fee will be accepted till 09:30 PM on 21st July 2026. Late entries with triple entry fee will be accepted till 09:30 PM on 23rd July 2026."
    : "<strong>7. Online Entries Only:</strong> Entries will be accepted through respective NRAI's affiliated State Rifle Associations / Units only. THE ENTRIES WILL BE ACCEPTED ONLY \"ON THE NRAI's ONLINE SYSTEM\" and no paper entry will be accepted. The Athletes are requested to visit NRAI's website <a href=\"https://www.thenrai.org\" target=\"_blank\" style=\"color:var(--gold-400);text-decoration:underline;\">www.thenrai.org</a> to file their entries. Only online payment will be accepted. Single entry fee entries are open till 09:30 PM on 21st July 2026. Late entries with triple entry fee are accepted till 09:30 PM on 23rd July 2026.";

  const rule8 = isIssf
    ? "<strong>8. Competitor Cards:</strong> Competitor card for ISSF events will be made available by mail, only after the verification &amp; approval of the entries. Athletes can print their competitor card and produce them before the match starts to the respective Range Officers."
    : "<strong>8. Competitor Cards:</strong> Competitor cards for NR events will be available online after approval. Athletes must print their competitor card and produce them to Range Officers before the match starts.";

  const rule12 = isIssf
    ? "<strong>12. MQS Penalties:</strong> Participants who do not secure the Minimum Qualifying Score of 50% in Pistol and Peep Sight Rifle Events will have to pay a penalty fee of Rs. 2,000/- per event to Tamilnadu Shooting Association. The responsibility of collecting this penalty lies with the respective Clubs / Units. However, this penalty will not be applicable for Youth Categories, Sub Youth Categories, Senior Master and Super Masters."
    : "<strong>12. MQS Penalties:</strong> Participants who do not secure the Minimum Qualifying Score of 40% in Open Sight Air Rifle/Open Sight Rifle Events and 50% in Pistol and Peep Sight Rifle Events will pay a penalty fee of Rs. 2,000/- per event to the Tamilnadu Shooting Association. The responsibility of collecting this penalty lies with the respective Clubs / Units. However, this penalty will not be applicable for Youth Categories, Sub Youth Categories, Senior Master and Super Masters.";

  return `
    <strong style="color:var(--gold-400); font-size:15px; display:block; margin-bottom:12px;">Terms &amp; Conditions (${isIssf ? 'ISSF - National' : 'NR - State Level'})</strong>
    <div style="display:flex; flex-direction:column; gap:12px;">
      <p>${rule1}</p>
      <p>${rule2}</p>
      <p><strong>3. Category Restriction:</strong> Athletes taking part in ISSF category matches cannot take part in NR category matches in that event and vice versa. Athletes who have attended the Nationals can only take part in the ISSF category of that event. (e.g., Athlete qualified in ISSF Air Event Sub-Youth Category cannot take part in NR Air Event Junior or Senior Category).</p>
      
      <div>
        <strong>4. Age Limit &amp; Match Book Rules:</strong> Athletes completing the age below in 2026 will be allowed to participate under age categories matches as per Rule 11 of NRAI Match Book:
        <table style="width:100%; border-collapse:collapse; margin-top:8px; margin-bottom:8px; font-size:12px; background:rgba(0,0,0,0.2); border-radius:6px; overflow:hidden;">
          <thead>
            <tr style="background:rgba(255,255,255,0.05); text-align:left;">
              <th style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Category</th>
              <th style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Age Criteria</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Seniors</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Above the age of 21 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Juniors</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 21 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Youth</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 18 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Sub Youth</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Below the age of 16 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Masters</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Between age of 45 years to 59 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Senior Masters</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Between age of 60 years to 69 years</td>
            </tr>
            <tr>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08); font-weight:600;">Super Masters</td>
              <td style="padding:6px 10px; border:1px solid rgba(255,255,255,0.08);">Age of 70 years and above</td>
            </tr>
          </tbody>
        </table>
        <ul style="margin:6px 0 0 16px; padding-left:0; list-style:circle; font-size:12px;">
          <li>Sub Youth can participate in Youth, Junior and Senior Category also.</li>
          <li>Youth can participate in Junior and Senior Category.</li>
          <li>Junior and Masters can participate in Senior Category also.</li>
          <li>Senior Master can participate in Senior and Masters Category.</li>
          <li>Super Master can participate in Senior Master, Master &amp; Senior Category.</li>
        </ul>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">* Participation in various categories requires additional entry fee.</div>
      </div>
      
      <p><strong>5. Firearm Age Restriction:</strong> Athletes below the age of 12 years are not allowed to participate in the firearm matches during the championship.</p>
      
      <p><strong>6. Age Proof:</strong> Proof of age / original certificate for Sub-Youth, Youth and Junior category must be kept in person for verification. It is mandatory to produce a valid Photo ID Card (NRAI ID, Aadhaar, School ID, or Government Photo ID) on demand by the Range Officer.</p>
      
      <p>${rule7}</p>
      
      <p>${rule8}</p>
      
      <p><strong>9. Arms &amp; Ammunition:</strong> Organizers will not supply arms or ammunition. Athletes/Units must bring their own arms and ammunition.</p>
      
      <p><strong>10. Weapon Sharing:</strong> Weapon sharing is limited to the number of details allotted to the affiliated unit for that match. Weapon sharing details must be informed to range officers before the start of the event.</p>
      
      <p><strong>11. Specifications:</strong> All weapons must be as per the NRAI and ISSF Specification.</p>
      
      <p>${rule12}</p>
      
      <p><strong>13. Dress Code &amp; Uniform:</strong> Athletes must appear as sports persons and wear proper uniform. The following are strictly prohibited on the range:
        <ul style="margin:4px 0 0 16px; padding-left:0; list-style:square; font-size:12px;">
          <li>Chappals and sandals</li>
          <li>Jeans</li>
          <li>Sleeveless tops / miniskirts</li>
          <li>All types of pants and shorts with external pockets (e.g. Cargo, Camouflage, Khaki, Olive, Brown).</li>
        </ul>
      </p>
      
      <p><strong>14. Parental Consent:</strong> All female shooters under the age of 18 must submit the Parental Consent form to the organizer before the start of the event.</p>
      
      <p><strong>15. Internal Committee (POSH):</strong> In terms of the provisions of the POSH Act 2013, the Nominated Internal Committee members of the TNSA are:
        <ul style="margin:4px 0 0 16px; padding-left:0; list-style:none; font-size:12px;">
          <li>• <strong>Chairperson:</strong> Dr. Mahalakshmi Winfred</li>
          <li>• <strong>Member:</strong> Ms. Similal Singh</li>
          <li>• <strong>Member:</strong> Ms. Sulthania Exan Mariym</li>
        </ul>
      </p>
      
      <p><strong>16. Photography &amp; Phones:</strong> Photography is strictly prohibited inside the range, except by authorized press photographers. Cell phones are strictly banned inside the shooting range.</p>
      
      <p><strong>17. Protest Fee:</strong> Protest fee is Rs. 200/-. Appeal to the Jury of Appeal is Rs. 500/-. The fee will be refunded if the protest is upheld and retained if lost.</p>
      
      <p><strong>18. Discipline:</strong> Organizing Committee decisions are final. Participants must abide by all rules and regulations. Discipline on and off the field is essential. Shooting practice in hotels is strictly prohibited.</p>
      
      <p><strong>19. Rules Book:</strong> All matches will be conducted as per the NRAI Match Book.</p>
      
      <p><strong>20. Spotting Scope:</strong> Athletes must bring their own spotting scope for 25M and 50M ranges.</p>
    </div>
  `;
}

function switchToNrCategory() {
  selectCat('NR', false);
}

// ── Category Selection ──
function selectCat(cat, isUserClick = false) {
  selCat=cat; finalCat=cat;
  document.querySelectorAll('.cat-card').forEach(c=>c.classList.remove('selected'));
  const card = document.getElementById('cat_'+cat);
  if (card) card.classList.add('selected');
  document.getElementById('err_cat').textContent='';
  document.getElementById('selCategory').value=cat;

  // Dynamically update Terms & Conditions content based on selected category
  const termsBox = document.getElementById('termsAndConditionsContent');
  if (termsBox) {
    termsBox.innerHTML = getTermsHTML(cat);
  }

  // Show category & age info
  document.getElementById('s3CatInfo').textContent = CAT_LABELS[cat] || cat;
  document.getElementById('s3AgeInfo').textContent = 'Age: '+USER_AGE+' yrs — '+AUTO_AGE_GROUP;

  // Show/hide columns based on category in the table header & body
  const isIssf = (cat === 'ISSF');
  const isIssfOrMqs = (cat === 'ISSF');

  document.querySelectorAll('.issf-col').forEach(c => {
    c.style.display = isIssf ? '' : 'none';
  });
  document.querySelectorAll('.mqs-col').forEach(c => {
    c.style.display = isIssfOrMqs ? '' : 'none';
  });

  // Show/hide global fields
  document.getElementById('sec_issf_num').style.display = isIssfOrMqs ? 'block' : 'none';
  // Para fields: always visible for special athletes
  document.getElementById('sec_para_fields').style.display = IS_PARA_DEAF ? 'block' : 'none';
  // Reset MPS alert when changing category
  document.getElementById('nrmqsAlert').classList.remove('show');

  // Update existing row inputs display state & repopulate dropdowns
  const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
  const filtered = getFilteredEvents();
  const groups = {};
  filtered.forEach(e => {
    if (!groups[e.group]) groups[e.group] = [];
    groups[e.group].push(e);
  });
  let optHtml = '<option value="">— Select —</option>';
  Object.keys(groups).forEach(g => {
    optHtml += '<optgroup label="'+g+'">';
    groups[g].forEach(e => {
      optHtml += '<option value="'+e.value+'">'+e.label+'</option>';
    });
    optHtml += '</optgroup>';
  });

  rows.forEach(row => {
    const idMatch = row.id.match(/\d+/);
    if (!idMatch) return;
    const idx = parseInt(idMatch[0]);

    row.querySelectorAll('.issf-col').forEach(c => {
      c.style.display = isIssf ? '' : 'none';
    });
    row.querySelectorAll('.mqs-col').forEach(c => {
      c.style.display = isIssfOrMqs ? '' : 'none';
    });

    const sel = row.querySelector('.evt-select');
    if (sel) { sel.innerHTML = optHtml; sel.value = ''; }

    updateRowFee(idx);
    checkRowMPS(idx);
  });

  updateSelectedEventOptions();

  // Calculate total amount
  calculateTotalAmount();

  // Generate reg_id via AJAX
  fetch('actions/generate_reg_id.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'category='+encodeURIComponent(cat)
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.success){
      generatedRegId = data.reg_id;
      document.getElementById('genRegId').value = generatedRegId;
    }
  })
  .catch(()=>{});

  if (isUserClick) {
    gotoNextStep();
  }
}

function updateSelectedEventOptions() {
  const selects = Array.from(document.querySelectorAll('.evt-select'));
  const selectedValues = selects.map(sel => sel.value).filter(val => val !== "");
  
  selects.forEach(sel => {
    const curVal = sel.value;
    Array.from(sel.options).forEach(opt => {
      if (opt.value && opt.value !== curVal) {
        opt.disabled = selectedValues.includes(opt.value);
      } else {
        opt.disabled = false;
      }
    });
  });
}

// ── Table Row Management ──
function addTableRow() {
  const tbody = document.getElementById('evtTblBody');
  const idx = evtRowCount++;
  const filteredEvents = getFilteredEvents();
  const cat = finalCat;

  // Build event options grouped
  let optHtml = '<option value="">— Select —</option>';
  const groups = {};
  filteredEvents.forEach(e => {
    if(!groups[e.group]) groups[e.group] = [];
    groups[e.group].push(e);
  });
  Object.keys(groups).forEach(g => {
    optHtml += '<optgroup label="'+g+'">';
    groups[g].forEach(e => {
      optHtml += '<option value="'+e.value+'">'+e.label+'</option>';
    });
    optHtml += '</optgroup>';
  });

  const isIssf = (cat === 'ISSF');
  const isIssfOrMqs = (cat === 'ISSF');
  const showIssfNum = isIssf && isFieldEnabled('issf_number');
  const showMqsScore = isIssfOrMqs && isFieldEnabled('mqs_score');
  const showShootYear = isIssf && isFieldEnabled('shooting_year');
  const showCertificate = isIssfOrMqs && isFieldEnabled('certificate_path');

  let rowHtml = `<tr id="evtRow_${idx}">
    <td style="text-align:center;color:var(--text-muted);font-weight:600;">${idx+1}</td>
    <td>
      <input type="text" id="mn_${idx}" class="tbl-input" readonly style="background:rgba(255,255,255,0.03);color:var(--text-muted);" placeholder="Auto">
    </td>
    <td>
      <select id="ev_${idx}" class="tbl-input evt-select" onchange="onEventChange(${idx})">
        ${optHtml}
      </select>
    </td>
    <td class="issf-col" style="display:${showIssfNum?'':'none'};">
      <span id="req_${idx}" style="color:var(--text-muted);font-size:12px;">—</span>
    </td>
    <td class="issf-col mqs-col" style="display:${showMqsScore?'':'none'};">
      <input type="number" id="ms_${idx}" class="tbl-input mqs-input" min="0" max="1200" step="0.1" placeholder="Score" oninput="checkRowMPS(${idx})">
    </td>
    <td class="issf-col" style="display:${showMqsScore?'':'none'};">
      <input type="text" id="cn_${idx}" class="tbl-input" placeholder="Comp. name">
    </td>
    <td class="issf-col" style="display:${showShootYear?'':'none'};">
      <select id="yr_${idx}" class="tbl-input">
        <option value="">Year</option>
        <option value="${CURRENT_YEAR}">${CURRENT_YEAR}</option>
        <option value="${PREV_YEAR}">${PREV_YEAR}</option>
      </select>
    </td>
    <td class="issf-col" style="display:${showCertificate?'':'none'};">
      <div class="cert-upload-btn-wrapper">
        <label class="cert-upload-label" for="cf_${idx}">
          <span id="cflbl_${idx}">📂 Upload</span>
        </label>
        <input type="file" id="cf_${idx}" accept=".pdf,.jpg,.jpeg,.png" onchange="updateCertLabel(${idx}, this)">
      </div>
    </td>
    <td style="text-align:right;">
      <span id="fee_${idx}" class="row-fee" style="color:var(--gold-400);font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;">₹0</span>
    </td>
    <td style="text-align:center;">
      <button type="button" onclick="removeTableRow(${idx})" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:18px;padding:2px;line-height:1;">&times;</button>
    </td>
  </tr>`;

  tbody.insertAdjacentHTML('beforeend', rowHtml);
  
  // MPS result row
  const mpsRow = document.createElement('tr');
  mpsRow.id = 'mpsRow_'+idx;
  mpsRow.style.display = 'none';
  mpsRow.innerHTML = `<td colspan="10" style="padding:4px 10px;"><div id="mpsResult_${idx}" class="mps-result" style="margin:0;"></div></td>`;
  tbody.insertAdjacentHTML('beforeend', mpsRow.outerHTML);
  
  updateRowFee(idx);
  updateSelectedEventOptions();
}

function updateCertLabel(idx, input) {
  const lbl = document.getElementById('cflbl_' + idx);
  if (!lbl) return;
  if (input.files && input.files[0]) {
    lbl.textContent = '✓ ' + input.files[0].name.substring(0, 10) + (input.files[0].name.length > 10 ? '...' : '');
    lbl.style.borderColor = 'var(--success)';
    lbl.style.color = 'var(--success)';
  } else {
    lbl.textContent = '📂 Upload';
    lbl.style.borderColor = 'rgba(255, 255, 255,0.35)';
    lbl.style.color = 'var(--gold-400)';
  }
}

function removeTableRow(idx) {
  const row = document.getElementById('evtRow_'+idx);
  const mpsRow = document.getElementById('mpsRow_'+idx);
  if(row) row.remove();
  if(mpsRow) mpsRow.remove();
  renumberRows();
  calculateTotalAmount();
  updateSelectedEventOptions();
}

function renumberRows() {
  const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
  rows.forEach((row, i) => {
    row.querySelector('td:first-child').textContent = (i+1);
  });
}

function onEventChange(idx) {
  const ev = document.getElementById('ev_'+idx).value;
  const mnField = document.getElementById('mn_'+idx);
  const reqSpan = document.getElementById('req_'+idx);
  if (ev) {
    const evObj = ALL_EVENTS.find(e => e.value === ev);
    mnField.value = evObj ? evObj.no : '';
    reqSpan.textContent = getMpsForEvent(ev) ?? '—';
  } else {
    mnField.value = '';
    reqSpan.textContent = '—';
  }
  updateRowFee(idx);
  checkRowMPS(idx);
  calculateTotalAmount();
  updateSelectedEventOptions();
}

function updateRowFee(idx) {
  const ev = document.getElementById('ev_'+idx).value;
  const evFee = ev ? getEventFee(ev) : 0;
  const catFee = CAT_FEE[finalCat] || 0;
  document.getElementById('fee_'+idx).textContent = '₹' + (evFee + catFee);
}

function checkRowMPS(idx) {
  const resultDiv = document.getElementById('mpsResult_'+idx);
  const mpsRow = document.getElementById('mpsRow_'+idx);
  if (!resultDiv || !mpsRow) return;
  resultDiv.classList.remove('show','pass','fail');

  if (finalCat !== 'ISSF') { mpsRow.style.display = 'none'; return; }

  const ev = document.getElementById('ev_'+idx).value;
  const mqsInput = document.getElementById('ms_'+idx);
  const sc = parseFloat(mqsInput ? mqsInput.value : '');
  const mpsVal = getMpsForEvent(ev);

  if (!ev || mpsVal === null) { mpsRow.style.display = 'none'; return; }
  if (isNaN(sc)) { mpsRow.style.display = 'none'; return; }

  mpsRow.style.display = '';
  resultDiv.classList.add('show');

  if (sc >= mpsVal) {
    resultDiv.className = 'mps-result show pass';
    resultDiv.innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size:14px; margin-right:4px;"></i><span style="font-size:12px;">Score meets MPS ('+mpsVal+'). Certificate required.</span>';
  } else {
    resultDiv.className = 'mps-result show fail';
    resultDiv.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:14px; margin-right:4px;"></i><span style="font-size:12px;">Score <strong>'+sc+'</strong> below MPS <strong>'+mpsVal+'</strong>. ISSF not allowed — choose <strong>NR</strong> category instead.</span>';
  }

  updateMpsAlertVisibility();
}

function updateMpsAlertVisibility() {
  const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
  let anyBelow = false;
  rows.forEach(row => {
    const idMatch = row.id.match(/\d+/);
    if (!idMatch) return;
    const idx = parseInt(idMatch[0]);
    const ev = document.getElementById('ev_'+idx).value;
    const mqsInput = document.getElementById('ms_'+idx);
    const sc = parseFloat(mqsInput ? mqsInput.value : '');
    const mpsVal = getMpsForEvent(ev);
    if (ev && mpsVal !== null && !isNaN(sc) && sc < mpsVal) anyBelow = true;
  });
  const alertEl = document.getElementById('nrmqsAlert');
  if (anyBelow) {
    alertEl.classList.add('show');
  } else {
    alertEl.classList.remove('show');
  }
}

function calculateTotalAmount() {
  const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
  let total = 0;
  let subtotal = 0;
  let totalGst = 0;
  let breakdownHtml = '';

  let isTripleActive = !!(typeof REG_CONTROL !== 'undefined' && REG_CONTROL.triple_entry_now);

  rows.forEach(row => {
    const idMatch = row.id.match(/\d+/);
    if (!idMatch) return;
    const idx = parseInt(idMatch[0]);
    const ev = document.getElementById('ev_' + idx).value;
    if (ev) {
      const details = getEventFeeDetails(ev);
      const rowCatFee = CAT_FEE[finalCat] || 0; // CAT_FEE is 0 now
      total += details.total + rowCatFee;
      subtotal += details.base;
      totalGst += details.gst + rowCatFee; // if any category fee was added
      breakdownHtml += `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:6px;border-bottom:1px dashed rgba(255,255,255,0.06);margin-bottom:6px;">
          <div style="max-width:70%;">
            <span style="color:var(--text-primary);font-weight:600;">${details.label}</span>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Base: ₹${details.base.toLocaleString('en-IN')} + 18% GST: ₹${details.gst.toLocaleString('en-IN')}</div>
            ${isTripleActive ? `<div style="font-size:10px;color:var(--gold-400);font-weight:600;margin-top:2px;">⚡ Triple Entry (3x multiplier applied)</div>` : ''}
          </div>
          <div style="text-align:right;font-family:'Rajdhani',sans-serif;font-weight:600;display:flex;align-items:center;">
            <div style="color:var(--gold-400);font-size:15px;font-weight:700;">₹${(details.total + rowCatFee).toLocaleString('en-IN')}</div>
          </div>
        </div>
      `;
    }
  });

  const tripleNoticeEl = document.getElementById('paymentTripleNotice');
  if (tripleNoticeEl) {
    tripleNoticeEl.style.display = isTripleActive ? 'flex' : 'none';
  }

  document.getElementById('payAmtDisplay').textContent = '₹' + total.toLocaleString('en-IN');
  document.getElementById('payEvtCount').textContent = rows.length + ' event(s)';

  const qrContainer = document.getElementById('payment_qr_container');
  if (qrContainer) {
    if (total > 0) {
      const upiUri = `upi://pay?pa=Vyapar.169929914091@hdfcbank&pn=TARGET&mc=8999&tr=STQU169929914091&am=${total}&cu=INR`;
      try {
        if (typeof QRCode !== 'undefined') {
          qrContainer.innerHTML = '';
          new QRCode(qrContainer, {
            text: upiUri,
            width: 138,
            height: 138,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
          });
        } else {
          qrContainer.innerHTML = `<img id="payment_qr_img" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=10&data=${encodeURIComponent(upiUri)}" alt="UPI QR Code" style="width:138px;height:138px;display:block;image-rendering:crisp-edges;" onerror="this.onerror=null;this.src='images/payment_qr.png';">`;
        }
      } catch (err) {
        qrContainer.innerHTML = `<img id="payment_qr_img" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=10&data=${encodeURIComponent(upiUri)}" alt="UPI QR Code" style="width:138px;height:138px;display:block;image-rendering:crisp-edges;" onerror="this.onerror=null;this.src='images/payment_qr.png';">`;
      }
    } else {
      qrContainer.innerHTML = `<img id="payment_qr_img" src="images/payment_qr.png" alt="UPI QR Code" style="width:138px;height:138px;display:block;image-rendering:crisp-edges;">`;
    }
  }

  const container = document.getElementById('feeBreakdownContainer');
  const list = document.getElementById('feeBreakdownList');
  const bdSubtotal = document.getElementById('bdSubtotal');
  const bdGst = document.getElementById('bdGst');

  if (container && list) {
    if (rows.length > 0 && total > 0) {
      list.innerHTML = breakdownHtml;
      if (bdSubtotal) bdSubtotal.textContent = '₹' + subtotal.toLocaleString('en-IN');
      if (bdGst) bdGst.textContent = '₹' + totalGst.toLocaleString('en-IN');
      container.style.display = 'block';
    } else {
      container.style.display = 'none';
    }
  }
}

function getTableData() {
  const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
  let items = [];
  let filesToUpload = {};
  let hasError = false;
  let errMessage = '';

  rows.forEach((row, cartIdx) => {
    const idMatch = row.id.match(/\d+/);
    if (!idMatch) return;
    const idx = parseInt(idMatch[0]);

    const ev = document.getElementById('ev_'+idx).value;
    if (!ev) { hasError = true; errMessage = 'Please select an event for all rows.'; return; }

    // Check duplicates
    if (items.some(it => it.event_name === ev)) {
      hasError = true;
      errMessage = 'Duplicate event "' + ev + '" selected.';
      return;
    }

    const mn = document.getElementById('mn_'+idx).value || '';
    const mqsScore = parseFloat(document.getElementById('ms_'+idx)?.value) || null;
    const compName = document.getElementById('cn_'+idx)?.value || '';
    const year = document.getElementById('yr_'+idx)?.value || '';
    const certFile = document.getElementById('cf_'+idx)?.files?.[0] || null;
    const existingCert = row.dataset.certificatePath || null;
    const evFee = ev ? getEventFee(ev) : 0;
    const catFee = CAT_FEE[finalCat] || 0;

    if (finalCat === 'ISSF') {
      if (isFieldEnabled('issf_number') && isFieldMandatory('issf_number')) {
        if (!document.getElementById('inp_issf_global')?.value?.trim()) {
          hasError = true;
          errMessage = 'ISSF registration number is required for this category.';
          return;
        }
      }
      if (isFieldEnabled('mqs_score') && isFieldMandatory('mqs_score')) {
        if (mqsScore === null || isNaN(mqsScore) || mqsScore <= 0) {
          hasError = true;
          errMessage = 'Achieved Score is required for all ISSF events.';
          return;
        }
      }
      if (isFieldEnabled('mqs_score') && isFieldMandatory('mqs_score')) {
        if (!compName.trim()) {
          hasError = true;
          errMessage = 'Competition Name is required for all ISSF events.';
          return;
        }
      }
      if (isFieldEnabled('shooting_year') && isFieldMandatory('shooting_year')) {
        if (!year) {
          hasError = true;
          errMessage = 'Year is required for all ISSF events.';
          return;
        }
      }
      if (isFieldEnabled('certificate_path') && isFieldMandatory('certificate_path')) {
        if (!existingCert && !certFile) {
          hasError = true;
          errMessage = 'Certificate upload is required for ISSF event: ' + ev + '.';
          return;
        }
      }
    }



    const evObj = ALL_EVENTS.find(e => e.value === ev);
    const itemAgeGroup = (evObj && !IS_PARA && !IS_DEAF && !IS_DEFENCE) ? AGE_GROUP_LABELS[getEventAgeGroup(evObj.label)] : AUTO_AGE_GROUP;

    items.push({
      match_no:         mn,
      event_name:       ev,
      weapon_type:      getWeaponType(ev),
      age_group:        itemAgeGroup,
      best_score:       mqsScore || 0,
      category:         selCat,
      final_cat:        finalCat,
      issf_number:      document.getElementById('inp_issf_global')?.value?.trim() || null,
      mqs_score:        mqsScore,
      competition_name: compName,
      shooting_year:    year,
      disability_type:  document.getElementById('sel_dis')?.value || null,
      classification:   document.getElementById('sel_cls')?.value || null,
      certificate_path: existingCert,
      entry_fee:        evFee + catFee
    });

    if (certFile) {
      filesToUpload[cartIdx] = certFile;
    }
  });

  return { items, filesToUpload, hasError, errMessage };
}

// ── Draft Loading & Management ──
const draftEvents = <?= $draftEventsJson ?? '[]' ?>;
const draftSessionId = <?= (isset($activeDraft) && $activeDraft) ? (int)$activeDraft['id'] : 'null' ?>;
const draftCategory = <?= json_encode($draftEvents[0]['category'] ?? '') ?>;
const draftIssfNum = <?= json_encode($draftIssfNum ?? '') ?>;
const draftDisType = <?= json_encode($draftDisType ?? '') ?>;
const draftClassif = <?= json_encode($draftClassif ?? '') ?>;

function resumeDraft() {
  if (!draftSessionId || !draftEvents.length) return;
  document.getElementById('draftSessionId').value = draftSessionId;
  
  // Select category
  selectCat(draftCategory);

  // Fill category specific fields
  if (draftCategory === 'ISSF') {
    const field = document.getElementById('inp_issf_global');
    if (field) field.value = draftIssfNum;
  }

  // Populate event table
  const tbody = document.getElementById('evtTblBody');
  tbody.innerHTML = '';
  evtRowCount = 0;

  draftEvents.forEach((e, idx) => {
    addTableRow();
    // Set event dropdown
    const evSel = document.getElementById('ev_' + idx);
    if (evSel) {
      const targetVal = e.event_code || e.event_name;
      let opt = Array.from(evSel.options).find(o => o.value === targetVal || o.value === e.event_code || o.text === e.event_name);
      if (opt) {
        evSel.value = opt.value;
      } else {
        evSel.value = targetVal;
      }
      onEventChange(idx);
    }
    
    // Set match number (if saved)
    if (e.match_no) {
      document.getElementById('mn_' + idx).value = e.match_no;
    }

    // Set MQS/best score
    const scoreField = document.getElementById('ms_' + idx);
    if (scoreField) {
      scoreField.value = e.best_score || '';
      checkRowMPS(idx);
    }

    // Set competition name
    const compField = document.getElementById('cn_' + idx);
    if (compField) compField.value = e.competition_name || '';

    // Set year
    const yrField = document.getElementById('yr_' + idx);
    if (yrField) yrField.value = e.shooting_year || '';

    // Set certificate display
    if (e.certificate_path) {
      const row = document.getElementById('evtRow_' + idx);
      if (row) {
        row.dataset.certificatePath = e.certificate_path;
      }
      const lbl = document.getElementById('cflbl_' + idx);
      if (lbl) {
        lbl.textContent = '✓ Saved Cert';
        lbl.style.borderColor = 'var(--success)';
        lbl.style.color = 'var(--success)';
        // Add view link
        const parent = lbl.parentNode;
        const oldLink = parent.querySelector('.existing-cert-link');
        if (oldLink) oldLink.remove();
        
        const viewLink = document.createElement('a');
        viewLink.className = 'existing-cert-link';
        viewLink.href = e.certificate_path;
        viewLink.target = '_blank';
        viewLink.textContent = '👁';
        viewLink.style.marginLeft = '6px';
        viewLink.style.color = 'var(--info)';
        viewLink.style.textDecoration = 'none';
        viewLink.title = 'View existing certificate';
        parent.appendChild(viewLink);
      }
    }
  });

  // Calculate total
  calculateTotalAmount();
  
  // Hide notice banner once resumed
  const banner = document.getElementById('draftNoticeBanner');
  if (banner) banner.style.display = 'none';

  // Scroll to form category/events section
  document.getElementById('eventsSection').scrollIntoView({ behavior: 'smooth' });
}

async function discardDraft(draftId) {
  if (!confirm('Are you sure you want to discard this draft? This will delete all saved details and certificates.')) return;
  const fd = new FormData();
  fd.append('draft_session_id', draftId);
  try {
    const r = await fetch('actions/discard_draft_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      alert('Draft discarded.');
      window.location.reload();
    } else {
      alert(d.message);
    }
  } catch {
    alert('Network error.');
  }
}

async function saveAsDraft() {
  const mb = document.getElementById('msgBox');
  mb.className = 'msg-box';
  mb.innerHTML = '';

  if (!selCat) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Please select a category first.</span>';
    mb.scrollIntoView({behavior:'smooth',block:'nearest'});
    return;
  }

  const { items, filesToUpload, hasError, errMessage } = getTableData();
  if (hasError) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>' + errMessage + '</span>';
    mb.scrollIntoView({behavior:'smooth',block:'nearest'});
    return;
  }

  if (items.length === 0) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Add at least one event row to save draft.</span>';
    mb.scrollIntoView({behavior:'smooth',block:'nearest'});
    return;
  }

  const btn = document.getElementById('btnSaveDraft') || document.querySelector('button[onclick="saveAsDraft()"]');
  btn.disabled = true;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = 'Saving...';

  const fd = new FormData();
  fd.set('cart_json', JSON.stringify(items));
  fd.set('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');
  fd.set('sel_category', selCat);
  fd.set('selected_weapon', document.getElementById('selectedWeapon').value);
  const draftSessVal = document.getElementById('draftSessionId').value;
  if (draftSessVal) {
    fd.set('draft_session_id', draftSessVal);
  }

  // Collect custom fields
  let customFields = {};
  document.querySelectorAll('.custom-evt-field').forEach(input => {
      customFields[input.dataset.fid] = input.value.trim();
  });
  fd.set('custom_fields', JSON.stringify(customFields));

  // Append draft files
  Object.keys(filesToUpload).forEach(key => {
    fd.append('cert_' + key, filesToUpload[key]);
  });

  try {
    const resp = await fetch('actions/save_draft_action.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      mb.className = 'msg-box show success';
      mb.innerHTML = '<span>✔</span><span>Draft saved successfully. Session ID: <strong>' + data.session_id + '</strong>. Reloading...</span>';
      mb.scrollIntoView({behavior:'smooth',block:'nearest'});
      document.getElementById('draftSessionId').value = data.draft_session_id;
      setTimeout(() => window.location.reload(), 1200);
    } else {
      mb.className = 'msg-box show error';
      mb.innerHTML = '<span>✖</span><span>' + data.message + '</span>';
      mb.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
  } catch {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Network error. Please try again.</span>';
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
}

// Payment file name
function showPayName(input){
  const el=document.getElementById('payName');
  if(input.files&&input.files[0]){el.textContent='📎 '+input.files[0].name;el.style.display='block';}
}

// Copy UPI
function copyUPI(){
  navigator.clipboard.writeText('Vyapar.169929914091@hdfcbank').then(()=>alert('UPI ID copied!'));
}

function selectWeapon(weapon, isUserClick = false) {
  document.getElementById('selectedWeapon').value = weapon;
  document.getElementById('err_weapon').textContent = '';
  document.querySelectorAll('[id^="weapon_"]').forEach(btn => btn.classList.remove('selected'));
  const card = document.getElementById('weapon_' + weapon);
  if (card) card.classList.add('selected');
  // Refresh event dropdowns to reflect the selected weapon
  refreshEventSelects();
  if (isUserClick) {
    gotoNextStep();
  }
}

function refreshEventSelects(){
  const filtered = getFilteredEvents();
  // build option HTML
  let optHtml = '<option value="">— Select —</option>';
  const groups = {};
  filtered.forEach(e=>{ if(!groups[e.group]) groups[e.group]=[]; groups[e.group].push(e); });
  Object.keys(groups).forEach(g=>{ optHtml += '<optgroup label="'+g+'">'; groups[g].forEach(e=>{ optHtml += '<option value="'+e.value+'">'+e.label+'</option>'; }); optHtml += '</optgroup>'; });
  document.querySelectorAll('.evt-select').forEach(sel=>{
    const cur = sel.value;
    sel.innerHTML = optHtml;
    if (cur) sel.value = cur; // preserve current selection if still available
  });
  updateSelectedEventOptions();
}

function gotoStep(step) {
  document.querySelectorAll('.wizard-step').forEach(el => {
    el.style.display = Number(el.dataset.step) === step ? '' : 'none';
  });
  document.querySelectorAll('.wizard-step-indicator').forEach(ind => {
    const idx = Number(ind.dataset.step);
    ind.classList.toggle('active', idx === step);
    ind.classList.toggle('completed', idx < step);
  });
}

function gotoNextStep() {
  const activeStep = Number(document.querySelector('.wizard-step-indicator.active')?.dataset.step || 1);
  const nextStep = activeStep + 1;

  if (activeStep === 1) {
    const weapon = document.getElementById('selectedWeapon').value;
    if (!weapon) {
      document.getElementById('err_weapon').textContent = 'Please choose a weapon to continue.';
      return;
    }
  }
  if (activeStep === 3) {
    if (!selCat) {
      document.getElementById('err_cat').textContent = 'Please choose a category before continuing.';
      return;
    }
  }

  if (activeStep === 4) {
    const rows = document.querySelectorAll('#evtTblBody tr[id^="evtRow_"]');
    if (!rows.length) {
      document.getElementById('err_tbl').textContent = 'Add at least one event row before continuing.';
      return;
    }
    const { items, hasError, errMessage } = getTableData();
    if (hasError) {
      document.getElementById('err_tbl').textContent = errMessage;
      return;
    }
    document.getElementById('err_tbl').textContent = '';
  }

  if (activeStep === 5) {
    const accepted = document.getElementById('termsCheckbox').checked;
    if (!accepted) {
      document.getElementById('err_terms').textContent = 'You must accept the terms to continue.';
      return;
    }
    document.getElementById('termsAccepted').value = '1';
  }

  if (nextStep > 6) return;
  gotoStep(nextStep);
}

function toggleTerms() {
  document.getElementById('err_terms').textContent = '';
  document.getElementById('termsAccepted').value = document.getElementById('termsCheckbox').checked ? '1' : '0';
}

// Initialize wizard at step 1
gotoStep(1);

// ── Form Submit ──
document.getElementById('masterForm').addEventListener('submit',async(e)=>{
  e.preventDefault();
  const mb = document.getElementById('msgBox');
  mb.className = 'msg-box';
  mb.innerHTML = '';

  if (!selCat) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Please select a category.</span>';
    return;
  }

  const { items, filesToUpload, hasError, errMessage } = getTableData();
  if (hasError) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>' + errMessage + '</span>';
    return;
  }

  if (items.length === 0) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Please add at least one event.</span>';
    return;
  }

  // Enforce validation for ISSF & NR_MQS global fields
  if (selCat === 'ISSF') {
    const globalIssf = document.getElementById('inp_issf_global').value.trim();
    if (!globalIssf) {
      mb.className = 'msg-box show error';
      mb.innerHTML = '<span>✖</span><span>ISSF Registration Number is required.</span>';
      document.getElementById('err_issf_g').textContent = 'Certificate number is required.';
      document.getElementById('sec_issf_num').scrollIntoView({behavior:'smooth'});
      return;
    }
  }

  // Ensure terms accepted (server also enforces this)
  const termsVal = document.getElementById('termsAccepted').value;
  if (termsVal !== '1') {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>You must accept the terms and conditions before submitting.</span>';
    document.getElementById('termsCheckbox')?.scrollIntoView({behavior:'smooth'});
    return;
  }

  // Validate custom fields
  let customFields = {};
  let customFieldsValid = true;
  document.querySelectorAll('.custom-evt-field').forEach(input => {
      const fid = input.dataset.fid;
      const label = input.dataset.label;
      const isReq = input.dataset.req === '1';
      const val = input.value.trim();
      if (isReq && !val) {
          mb.className = 'msg-box show error';
          mb.innerHTML = '<span>✖</span><span>Field "' + label + '" is required.</span>';
          customFieldsValid = false;
      }
      customFields[fid] = val;
  });
  if (!customFieldsValid) return;

  // Validate ISSF individual fields
  let validationError = false;
  items.forEach((item, idx) => {
    if (selCat === 'ISSF') {
      // Age group validation
      if (!validateEventAgeGroup(item.event_name)) {
        const ev = ALL_EVENTS.find(e => e.value === item.event_name);
        const evAgeGroup = ev ? AGE_GROUP_LABELS[getEventAgeGroup(ev.label)] : 'unknown';
        mb.className = 'msg-box show error';
        mb.innerHTML = '<span>✖</span><span>Event "' + (ev ? ev.label : item.event_name) + '" is for age group <strong>' + evAgeGroup + '</strong>, which you are not eligible for (your age category is <strong>' + AUTO_AGE_GROUP + '</strong>).</span>';
        validationError = true;
        return;
      }
      // Best score must meet MPS
      const mpsVal = getMpsForEvent(item.event_name);
      if (mpsVal !== null && item.best_score < mpsVal) {
        mb.className = 'msg-box show error';
        mb.innerHTML = '<span>✖</span><span>Event "' + item.event_name + '" score does not meet MPS (' + mpsVal + '). Choose NR instead.</span>';
        validationError = true;
        return;
      }
      // Certificate is mandatory
      if (!item.certificate_path && !filesToUpload[idx]) {
        mb.className = 'msg-box show error';
        mb.innerHTML = '<span>✖</span><span>Certificate upload is required for ISSF event: ' + item.event_name + '.</span>';
        validationError = true;
        return;
      }
    }
  });

  if (validationError) return;

  // Validate payment screenshot is selected
  const payInput = document.getElementById('payScreenshot');
  if (!payInput.files || !payInput.files[0]) {
    mb.className = 'msg-box show error';
    mb.innerHTML = '<span>✖</span><span>Payment receipt screenshot is required.</span>';
    document.getElementById('err_pay').textContent = 'Payment screenshot is required.';
    document.getElementById('paymentSection').scrollIntoView({behavior:'smooth'});
    return;
  }

  const btn=document.getElementById('submitBtn');
  const sp=btn?.querySelector('.spinner');
  const lb=btn?.querySelector('.btn-label');
  if (btn) btn.disabled=true;
  if (sp) sp.style.display='inline-block';
  if (lb) lb.style.display='none';

  const fd=new FormData(e.target);
  fd.set('cart_json', JSON.stringify(items));
  fd.set('sel_category', selCat);
  fd.set('custom_fields', JSON.stringify(customFields));
  const draftSessVal = document.getElementById('draftSessionId').value;
  if (draftSessVal) {
    fd.set('draft_session_id', draftSessVal);
  }

  // Attach certificate files by index
  Object.keys(filesToUpload).forEach(key => {
    fd.append('cert_' + key, filesToUpload[key]);
  });

  try{
    const resp=await fetch('actions/event_register_action.php',{method:'POST',body:fd});
    const data=await resp.json();
    if(data.success){
      mb.className='msg-box show success';
      mb.innerHTML='<span>✔</span><span>Registration submitted! Session ID: <strong>'+data.session_id+'</strong>. Your payment is under verification. You\'ll receive an email upon approval.</span>';
      mb.scrollIntoView({behavior:'smooth',block:'nearest'});
      window.location.href='dashboard.php';
    }else{
      mb.className='msg-box show error';
      mb.innerHTML='<span>✖</span><span>'+data.message+'</span>';
      mb.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
  }catch{
    mb.className='msg-box show error';
    mb.innerHTML='<span>✖</span><span>Network error. Please try again.</span>';
  }finally{
    if (btn) btn.disabled=false;
    if (sp) sp.style.display='none';
    if (lb) lb.style.display='inline-block';
  }
});

// Simple spinner animation
const style = document.createElement('style');
style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
document.head.appendChild(style);

</script>

<?php require_once 'includes/footer.php'; ?>
<script>
// On page load: auto-show para/deaf fields and show a notice for special athletes
document.addEventListener('DOMContentLoaded', function() {
  if (typeof PREVIOUS_CATEGORY !== 'undefined' && PREVIOUS_CATEGORY) {
    selectCat(PREVIOUS_CATEGORY);
  }
  if (IS_PARA_DEAF) {
    // Immediately show the disability/classification fields
    const pf = document.getElementById('sec_para_fields');
    if (pf) pf.style.display = 'block';

    // Show a special category notice above the category cards
    const catGrid = document.querySelector('.cat-grid');
    if (catGrid) {
      const notice = document.createElement('div');
      notice.style.cssText = 'background:rgba(155,89,182,.1);border:1px solid rgba(155,89,182,.3);border-radius:8px;padding:12px 16px;font-size:12px;color:#bb8fce;margin-bottom:14px;display:flex;align-items:center;gap:10px;';
      notice.innerHTML = '<i class="bi bi-universal-access" style="font-size:18px;"></i><div><strong>Special Category Athlete</strong>' +
        (IS_PARA ? ' – Para' : '') + (IS_DEAF ? ' – Deaf' : '') +
        '<br><span style="opacity:.8;">Events below are filtered to your special category only. Choose ISSF Events or NR Events.</span></div>';
      catGrid.parentNode.insertBefore(notice, catGrid);
    }
  }
});
</script>

