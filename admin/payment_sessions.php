<?php
/**
 * admin/payment_sessions.php
 * Superadmin: Verify payments & approve event registrations
 */
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/config/events.php';
$pageTitle = 'Payment Verification';
require_once 'includes/header.php';

try {
    $pdo = getDB();

    // Auto-heal: Ensure every user with event registrations has a payment session and linked events
    $pdo->exec("
        INSERT IGNORE INTO registration_sessions (session_id, user_id, total_amount, payment_status, approval_status, created_at)
        SELECT CONCAT('SES-IMP-', r.id), r.id, COALESCE(SUM(er.entry_fee), 0), 'verified', 'approved', NOW()
        FROM registrations r
        JOIN event_registrations er ON r.id = er.user_id
        LEFT JOIN registration_sessions s ON r.id = s.user_id
        WHERE s.id IS NULL
        GROUP BY r.id
    ");

    $pdo->exec("
        UPDATE event_registrations er
        JOIN registration_sessions s ON er.user_id = s.user_id
        SET er.session_id = s.id
        WHERE er.session_id IS NULL OR er.session_id = 0
    ");

    // Recalculate total_amount for all payment sessions based on actual sum of event_registrations.entry_fee
    $pdo->exec("
        UPDATE registration_sessions s
        JOIN (
            SELECT user_id, COALESCE(SUM(entry_fee), 0) AS calc_total
            FROM event_registrations
            GROUP BY user_id
        ) er_sum ON s.user_id = er_sum.user_id
        SET s.total_amount = er_sum.calc_total
        WHERE s.total_amount = 0 OR s.total_amount IS NULL OR s.total_amount != er_sum.calc_total
    ");

    $filterStatus   = $_GET['status']         ?? '';
    $filterPay      = $_GET['pay']            ?? '';
    $search         = $_GET['search']         ?? '';
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    $participant_id = $_GET['participant_id'] ?? '';
    $club_filter    = $_GET['club_name']      ?? '';
    $event_filter   = $_GET['event_name']     ?? '';

    // Fetch lists for filter dropdowns
    $participantsList = $pdo->prepare("SELECT DISTINCT r.id, r.reg_id, r.first_name, r.last_name FROM registration_sessions s JOIN registrations r ON r.id = s.user_id WHERE s.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY r.first_name ASC, r.last_name ASC");
    $participantsList->execute([$cid]);
    $participantsList = $participantsList->fetchAll();

    $clubsList = $pdo->prepare("SELECT DISTINCT r.club_name FROM registration_sessions s JOIN registrations r ON r.id = s.user_id WHERE s.championship_id = ? AND r.club_name IS NOT NULL AND r.club_name != '' AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY r.club_name ASC");
    $clubsList->execute([$cid]);
    $clubsList = $clubsList->fetchAll(PDO::FETCH_COLUMN);

    $eventsList = $pdo->prepare("SELECT DISTINCT e.event_name FROM event_registrations e JOIN registration_sessions s ON e.session_id = s.id JOIN registrations r ON r.id = s.user_id WHERE s.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%' ORDER BY e.event_name ASC");
    $eventsList->execute([$cid]);
    $eventsList = $eventsList->fetchAll(PDO::FETCH_COLUMN);
 
    $q = "SELECT s.id, s.session_id, s.user_id, s.total_amount, s.payment_screenshot,
                 s.payment_status, s.approval_status, s.admin_remarks, s.created_at,
                 r.first_name, r.last_name, r.email, r.reg_id, r.phone, r.district, r.club_name,
                 COUNT(e.id) AS evt_count,
                 GROUP_CONCAT(e.event_name SEPARATOR '<br>') AS event_names,
                 COALESCE(a.name,'—') AS approved_by_name, s.approved_at
          FROM registration_sessions s
          JOIN registrations r ON r.id = s.user_id
          LEFT JOIN event_registrations e ON (e.session_id = s.id OR (e.session_id IS NULL AND e.user_id = s.user_id))
          LEFT JOIN admins a ON a.id = s.approved_by
          WHERE s.championship_id = ? AND r.reg_id NOT LIKE 'MANUAL-%' AND r.reg_id NOT LIKE 'VACANT-%'";
    $params = [$cid];

    if ($search) { 
        $q .= " AND (s.session_id LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ?)"; 
        $s = "%$search%"; 
        array_push($params, $s, $s, $s, $s); 
    }
    if ($participant_id) {
        $q .= " AND (r.id = ? OR r.reg_id = ?)";
        array_push($params, $participant_id, $participant_id);
    }
    if ($club_filter) {
        $q .= " AND r.club_name = ?";
        $params[] = $club_filter;
    }
    if ($event_filter) {
        $q .= " AND EXISTS (SELECT 1 FROM event_registrations e_flt WHERE e_flt.session_id = s.id AND e_flt.event_name = ?)";
        $params[] = $event_filter;
    }
    if ($filterStatus) { 
        $q .= " AND s.approval_status=?"; 
        $params[] = $filterStatus; 
    }
    if ($filterPay)    { 
        $q .= " AND s.payment_status=?";  
        $params[] = $filterPay; 
    }

    $q .= " GROUP BY s.id ORDER BY s.created_at DESC";
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    // Stats
    $totSes   = $pdo->query("SELECT COUNT(*) FROM registration_sessions")->fetchColumn();
    $pending  = $pdo->query("SELECT COUNT(*) FROM registration_sessions WHERE approval_status='pending'")->fetchColumn();
    $approved = $pdo->query("SELECT COUNT(*) FROM registration_sessions WHERE approval_status='approved'")->fetchColumn();
    $totalRev = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM registration_sessions WHERE approval_status='approved'")->fetchColumn();

} catch (Exception $e) { 
    $sessions = []; 
    $totSes = $pending = $approved = $totalRev = 0; 
    $participantsList = [];
    $clubsList = [];
    $eventsList = [];
}
?>

<style>
/* Clean Premium Dark Theme Styles */
.pm-card {
    background: rgba(13, 15, 20, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    margin-bottom: 28px;
}

/* Styled Table with whitespace and subtle borders */
.pm-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.pm-table th {
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    text-align: left;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-family: 'Rajdhani', sans-serif;
    font-weight: 600;
    letter-spacing: 0.8px;
    padding: 14px 16px;
    font-size: 11.5px;
    background: rgba(255, 255, 255, 0.01);
}
.pm-table td {
    padding: 16px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    color: var(--text-primary);
    font-size: 13px;
    vertical-align: middle;
}
.pm-table tr {
    transition: background var(--trans-fast) ease-in-out;
}
.pm-table tr:hover {
    background: rgba(255, 255, 255, 0.015);
}

/* User avatar */
.cust-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold-400);
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    font-size: 13px;
    flex-shrink: 0;
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
    white-space: nowrap;
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
.status-badge.inactive {
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-muted);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

/* Actions block spacing */
.act-btn-container {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}
.act-btn-container .btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    border: 1px solid rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.02);
}
.act-btn-container .btn-icon:hover {
    border-color: rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.06);
}
.act-btn-container .btn-icon.edit:hover {
    color: var(--info);
    border-color: rgba(52, 152, 219, 0.3);
    background: rgba(52, 152, 219, 0.08);
}
.act-btn-container .btn-icon.del:hover {
    color: var(--danger);
    border-color: rgba(231, 76, 60, 0.3);
    background: rgba(231, 76, 60, 0.08);
}
</style>

<div class="msg-box" id="msgBox" role="alert" aria-live="polite"></div>

<!-- Left aligned Header and Right aligned Filters/Search Bar -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; flex-wrap:wrap; gap:16px;">
  <div>
    <h1 style="margin:0; font-size:24px; font-weight:700; font-family:'Cinzel',serif; color:var(--gold-400); letter-spacing:0.5px;">Payment Verification</h1>
    <p style="margin:6px 0 0 0; font-size:13px; color:var(--text-secondary);">Verify payments &amp; approve event registrations</p>
  </div>
  
  <div class="print-hide" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; width:100%;">
    <form method="GET" action="payment_sessions.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0; width:100%;">
      <!-- Participant Search Dropdown -->
      <div class="input-wrap" style="flex:1; min-width:200px; margin:0;">
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
      <div class="input-wrap" style="min-width:170px; margin:0;">
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
      <div class="input-wrap" style="min-width:200px; margin:0;">
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

      <div class="input-wrap" style="width:130px; margin:0;">
        <select name="status" onchange="this.form.submit()" style="height:38px; padding-top:0; padding-bottom:0;">
          <option value="">All Approvals</option>
          <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending</option>
          <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
          <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>>Rejected</option>
        </select>
      </div>
      <div class="input-wrap" style="width:130px; margin:0;">
        <select name="pay" onchange="this.form.submit()" style="height:38px; padding-top:0; padding-bottom:0;">
          <option value="">All Payments</option>
          <option value="unpaid" <?= $filterPay==='unpaid'?'selected':'' ?>>Unpaid</option>
          <option value="uploaded" <?= $filterPay==='uploaded'?'selected':'' ?>>Uploaded</option>
          <option value="verified" <?= $filterPay==='verified'?'selected':'' ?>>Verified</option>
        </select>
      </div>

      <?php if ($participant_id || $club_filter || $event_filter || $filterStatus || $filterPay): ?>
        <a href="payment_sessions.php" class="btn btn-outline" style="padding: 0 12px; height:38px; font-size:12px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
          <i class="bi bi-x-circle"></i> Clear Filters
        </a>
      <?php endif; ?>

      <button type="button" class="btn btn-outline" onclick="window.print()" style="padding: 0 16px; height:38px; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; cursor:pointer; background:rgba(255,255,255,0.05); color:#fff; border:1px solid rgba(255,255,255,0.2);">
        <i class="bi bi-printer-fill"></i> Print List
      </button>

      <button type="button" class="btn" onclick="generateAllCompetitorCards()" style="padding:0 16px; height:38px; font-size:12px; font-weight:700; background:var(--gold-500); color:#fff; border:none; border-radius:6px; cursor:pointer; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; margin-left:auto; display:inline-flex; align-items:center; gap:6px;"><i class="bi bi-person-badge-fill"></i> Generate Competitor Cards</button>
      <button type="button" class="btn btn-danger" onclick="deleteAllCompetitorCards()" style="padding:0 16px; height:38px; font-size:12px; font-weight:700; border:none; border-radius:6px; cursor:pointer; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:6px; background:#e74c3c; color:#fff;"><i class="bi bi-trash-fill"></i> Delete All Cards</button>
    </form>
  </div>
</div>

<!-- Stats Dashboard Grid -->
<div class="dash-grid" style="margin-bottom:28px;">
  <div class="stat-card">
    <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg></div>
    <div class="stat-info"><h4>Total Sessions</h4><div class="val"><?= number_format((float)$totSes) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#f39c12;background:rgba(243,156,18,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
    <div class="stat-info"><h4>Pending Approval</h4><div class="val"><?= number_format((float)$pending) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:var(--success);background:rgba(39,174,96,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
    <div class="stat-info"><h4>Approved</h4><div class="val"><?= number_format((float)$approved) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:var(--gold-400);background:rgba(255, 255, 255,0.1);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg></div>
    <div class="stat-info"><h4>Revenue Confirmed</h4><div class="val">₹<?= number_format((float)$totalRev, 2) ?></div></div>
  </div>
</div>

<!-- Structured Table with Rounded Card Backdrop -->
<div class="pm-card" style="overflow-x:auto;">
  <table class="pm-table">
    <thead>
      <tr>
        <th style="width:120px;">Enrollment ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Event Name</th>
        <th style="width:110px;">Amount</th>
        <th style="width:130px;" class="print-hide">Screenshot</th>
        <th style="width:110px;">Status</th>
        <th style="text-align:right; width:130px;" class="print-hide">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if(empty($sessions)): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:40px 10px;">No payment sessions found.</td></tr>
      <?php else: foreach($sessions as $s): ?>
      <tr id="sesrow_<?= $s['id'] ?>">
        <!-- Enrollment ID -->
        <td style="color:var(--gold-400);font-weight:700;font-family:monospace;font-size:12.5px;letter-spacing:0.5px;">
          <?= !empty($s['reg_id']) ? htmlspecialchars(formatBibNo($s['reg_id'])) : '-' ?>
        </td>
        
        <!-- Name -->
        <td>
          <div style="font-weight:600; color:var(--text-primary); font-size:13px;">
            <?= !empty(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ? htmlspecialchars(formatFullName($s['first_name'], $s['last_name'])) : '-' ?>
          </div>
          <div style="font-size:11px; color:var(--gold-400); margin-top:2px;">
            <?= !empty($s['club_name']) ? htmlspecialchars($s['club_name']) : '-' ?>
          </div>
        </td>

        <!-- Email -->
        <td><?= (!empty($s['email']) && strpos($s['email'], '@ssa.import') === false) ? htmlspecialchars($s['email']) : '-' ?></td>

        <!-- Event Name -->
        <td>
          <?php
          $eventCodes = explode('<br>', $s['event_names'] ?? '');
          $resolvedNames = [];
          foreach ($eventCodes as $code) {
              $code = trim($code);
              if ($code !== '') {
                  $resolvedNames[] = $EVENTS_MAPPING[$code] ?? $code;
              }
          }
          $fullEventNames = implode('<br>', $resolvedNames);
          ?>
          <div style="font-size:12px; line-height:1.4; color:var(--text-secondary);"><?= !empty($fullEventNames) ? $fullEventNames : '-' ?></div>
        </td>
        
        <!-- Amount -->
        <td style="color:var(--gold-400);font-family:'Rajdhani',sans-serif;font-size:16px;font-weight:700;">
          ₹<?= number_format((float)$s['total_amount'], 2) ?>
        </td>
        
        <!-- Screenshot -->
        <td class="print-hide">
          <?php if(!empty($s['payment_screenshot'])): ?>
            <a href="../<?= htmlspecialchars($s['payment_screenshot']) ?>" target="_blank" class="btn btn-outline" style="padding:4px 8px; font-size:10px; font-family:'Rajdhani',sans-serif; display:inline-flex; align-items:center; gap:4px; text-transform:uppercase; letter-spacing:0.5px; border-color:rgba(255,255,255,0.12); color:var(--text-secondary); height:auto; line-height:1;">
              Screenshot
              <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            </a>
          <?php else: ?>
            <span style="color:var(--text-muted); font-size:11px; font-style:italic;">-</span>
          <?php endif; ?>
        </td>
        
        <!-- Status -->
        <td>
          <span class="status-badge <?= $s['approval_status'] ?>" id="appr_<?= $s['id'] ?>" style="font-size:9.5px;">
            <?= ucfirst($s['approval_status']) ?>
          </span>
        </td>
        
        <!-- Action Column -->
        <td style="text-align:right;" class="print-hide">
          <div class="act-btn-container">
            <?php if($s['approval_status']==='approved'): 
              $cardFile = dirname(__DIR__) . '/uploads/competitor_cards/competitor_card_' . $s['user_id'] . '.pdf';
              $hasCard = file_exists($cardFile);
            ?>
              <button class="btn-icon" onclick="generateCompetitorCard(<?= $s['id'] ?>)" title="Generate Competitor Card" style="color:var(--gold-400); background:rgba(255, 255, 255,0.08); border-color:rgba(255, 255, 255,0.2);">
                <i class="bi bi-person-badge-fill"></i>
              </button>
              <?php if($hasCard): ?>
                <button class="btn-icon del" onclick="deleteCompetitorCard(<?= $s['user_id'] ?>, '<?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>')" title="Delete Competitor Card" style="color:var(--danger); background:rgba(231,76,60,0.08); border-color:rgba(231,76,60,0.2);">
                  <i class="bi bi-trash-fill"></i>
                </button>
              <?php endif; ?>
            <?php endif; ?>
            <button class="btn-icon edit" onclick="viewSession(<?= $s['id'] ?>)" title="View Events">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <?php if (true): ?>
              <?php if($s['approval_status']==='pending'): ?>
                <button class="btn-icon" onclick="approveSession(<?= $s['id'] ?>, '<?= $s['session_id'] ?>', '<?= htmlspecialchars($s['email']) ?>')" title="Approve" style="color:var(--success);background:rgba(39,174,96,0.08);border-color:rgba(39,174,96,0.2);">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </button>
                <button class="btn-icon" onclick="rejectSession(<?= $s['id'] ?>)" title="Reject" style="color:var(--danger);background:rgba(231,76,60,0.08);border-color:rgba(231,76,60,0.2);">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
              <?php endif; ?>
              <button class="btn-icon del" onclick="deleteSession(<?= $s['id'] ?>)" title="Delete">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- View Events Modal -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header"><h3>Session Events</h3><button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="modal-body" id="viewModalBody" style="max-height:450px;overflow-y:auto;background:var(--dark-950);border-radius:6px;padding:16px;">Loading...</div>
    <div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Close</button></div>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal">
    <div class="modal-header"><h3>Reject Registration</h3><button class="modal-close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <form id="rejectForm">
      <div class="modal-body">
        <input type="hidden" id="reject_id" name="session_id">
        <div class="form-group">
          <label>Reason for Rejection</label>
          <div class="input-wrap"><textarea id="reject_reason" name="remarks" rows="3" placeholder="e.g. Payment amount mismatch, screenshot unclear... (optional)" style="min-height:90px;"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline modal-close">Cancel</button>
        <button type="submit" class="btn btn-danger" style="width:auto;">Reject</button>
      </div>
    </form>
  </div>
</div>

<!-- Preview Modal (Open Document in the Same Page) -->
<div class="modal-overlay" id="previewModal" style="z-index: 10005;">
  <div class="modal" style="max-width:800px; width:85%;">
    <div class="modal-header">
      <h3 id="previewModalTitle">Document Preview</h3>
      <button class="modal-close" onclick="closeModal('previewModal')">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <div class="modal-body" id="previewModalBody" style="background:#11141d; padding:12px; height:500px; display:flex; justify-content:center; align-items:center; overflow:hidden;">
      <div style="color:var(--text-muted);">Loading preview...</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('previewModal')">Close</button>
    </div>
  </div>
</div>

<script>
function previewDoc(path, title) {
  const modalBody = document.getElementById('previewModalBody');
  const modalTitle = document.getElementById('previewModalTitle');
  modalTitle.textContent = title;
  
  modalBody.innerHTML = '<div style="color:var(--text-muted);">Loading preview...</div>';
  openModal('previewModal');
  
  const ext = path.split('.').pop().toLowerCase();
  if (ext === 'pdf') {
    modalBody.innerHTML = `<iframe src="${path}" style="width:100%; height:100%; border:none; border-radius:4px; background:#fff;"></iframe>`;
  } else {
    modalBody.innerHTML = `<img src="${path}" style="max-width:100%; max-height:100%; object-fit:contain; border-radius:4px;" alt="${title}">`;
  }
}

function viewSession(id) {
  document.getElementById('viewModalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">Loading...</div>';
  openModal('viewModal');
  fetch('actions/get_session_events_action.php?id='+id)
    .then(r=>r.json())
    .then(data=>{
      if(data.success){
        let html='<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        html+='<thead><tr style="border-bottom:1px solid rgba(255, 255, 255,0.25);">';
        ['Reg ID','Category','Event','Weapon','Score','Fee','Proof Cert','Status'].forEach(h=>{
          html+=`<th style="padding:10px;color:var(--text-secondary);text-align:left;font-size:11px;text-transform:uppercase;font-family:'Rajdhani',sans-serif;font-weight:600;letter-spacing:0.5px;">${h}</th>`;
        });
        html+='</tr></thead><tbody>';
        const catMap={ISSF:'ISSF',NR:'NR',NR_MQS:'NR/MQS',PARA_DEAF:'Para'};
        data.events.forEach(e=>{
          const sBadge=e.status==='approved'?'approved':(e.status==='rejected'?'rejected':'pending');
          let certHtml = '—';
          if (e.certificate_path) {
            certHtml = `<a href="javascript:void(0)" onclick="previewDoc('../${e.certificate_path}', 'Qualification Certificate')" style="color:var(--info);text-decoration:underline;">View Cert</a>`;
          }
          html+=`<tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
            <td style="padding:10px;color:var(--gold-400);font-weight:700;">${e.event_reg_id}</td>
            <td style="padding:10px;">${catMap[e.category]||e.category}</td>
            <td style="padding:10px;">${e.event_name}</td>
            <td style="padding:10px;">${e.weapon_type}</td>
            <td style="padding:10px;">${e.best_score}</td>
            <td style="padding:10px;color:var(--gold-400);font-weight:600;font-family:'Rajdhani',sans-serif;">₹${parseFloat(e.entry_fee).toFixed(2)}</td>
            <td style="padding:10px;">${certHtml}</td>
            <td style="padding:10px;"><span class="status-badge ${sBadge}" style="font-size:9px;">${e.status}</span></td>
          </tr>`;
        });
        html+='</tbody></table>';
        if(data.payment_screenshot){
          html+=`<div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.06);display:flex;gap:12px;flex-wrap:wrap;">
            <a href="javascript:void(0)" onclick="previewDoc('../${data.payment_screenshot}', 'Payment Receipt')" class="btn btn-outline" style="font-size:11px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;">🧾 View Payment Receipt</a>`;
          html+=`</div>`;
        }
        document.getElementById('viewModalBody').innerHTML=html;
      }else{
        document.getElementById('viewModalBody').innerHTML='<p style="color:var(--danger);">'+data.message+'</p>';
      }
    }).catch(()=>{ document.getElementById('viewModalBody').innerHTML='<p style="color:var(--danger);">Network error.</p>'; });
}

// Superadmin & authorized admin functions
async function approveSession(id, sessionId, email) {
  if(!confirm('Approve session '+sessionId+'?\n\nThis will:\n• Mark payment as verified\n• Approve all events in this session\n• Send approval email to: '+email)) return;
  const fd=new FormData();
  fd.append('session_id',id);
  try{
    const r=await fetch('actions/approve_session_action.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){
      document.getElementById('appr_'+id).className='status-badge approved';
      document.getElementById('appr_'+id).textContent='Approved';
      showMsg(document.getElementById('msgBox'),'success',d.message+'  Email sent: '+(d.email_sent?'✔ Yes':'✖ Failed (logged)'));
      setTimeout(() => location.reload(), 1500);
    }else{
      showMsg(document.getElementById('msgBox'),'error',d.message);
    }
  }catch{showMsg(document.getElementById('msgBox'),'error','Network error.');}
}

function rejectSession(id) {
  document.getElementById('reject_id').value=id;
  document.getElementById('reject_reason').value='';
  openModal('rejectModal');
}

document.getElementById('rejectForm').addEventListener('submit',async(e)=>{
  e.preventDefault();
  const fd=new FormData(e.target);
  try{
    const r=await fetch('actions/reject_session_action.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){
      closeModal('rejectModal');
      const id=fd.get('session_id');
      document.getElementById('appr_'+id).className='status-badge rejected';
      document.getElementById('appr_'+id).textContent='Rejected';
      showMsg(document.getElementById('msgBox'),'success','Session rejected successfully.');
      setTimeout(() => location.reload(), 1500);
    }else{showMsg(document.getElementById('msgBox'),'error',d.message);}
  }catch{showMsg(document.getElementById('msgBox'),'error','Network error.');}
});

async function deleteSession(id) {
  if(!confirm('Permanently delete this session and all its event registrations?')) return;
  const fd=new FormData(); fd.append('session_id',id);
  try{
    const r=await fetch('actions/delete_session_action.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){
      document.getElementById('sesrow_'+id).remove();
      showMsg(document.getElementById('msgBox'),'success','Deleted.');
      setTimeout(() => location.reload(), 1500);
    }
    else{showMsg(document.getElementById('msgBox'),'error',d.message);}
  }catch{showMsg(document.getElementById('msgBox'),'error','Network error.');}
}
// End admin functions

function generateAllCompetitorCards() {
    window.open('generate_competitor_card.php?bulk=1', '_blank', 'width=950,height=800');
}
function generateCompetitorCard(sessionId) {
    window.open('generate_competitor_card.php?session_id=' + sessionId, '_blank', 'width=950,height=800');
}

async function deleteCompetitorCard(userId, name) {
  if (!confirm('Are you sure you want to delete the competitor card for ' + name + '? This will remove it from their login.')) return;
  const fd = new FormData();
  fd.append('user_id', userId);
  try {
    const r = await fetch('actions/delete_competitor_card.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      alert(d.message);
      location.reload();
    } else {
      alert(d.message);
    }
  } catch(e) {
    alert('Network error.');
  }
}

async function deleteAllCompetitorCards() {
  if (!confirm('Are you sure you want to delete ALL competitor cards from all competitor logins? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('all', '1');
  try {
    const r = await fetch('actions/delete_competitor_card.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      alert(d.message);
      location.reload();
    } else {
      alert(d.message);
    }
  } catch(e) {
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

<script src="js/searchable_select.js?v=<?= time() ?>"></script>
<?php require_once 'includes/footer.php'; ?>

