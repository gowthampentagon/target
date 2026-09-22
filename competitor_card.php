<?php
// competitor_card.php
declare(strict_types=1);
require_once 'config/db.php';
require_once 'includes/document_renderer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?expired=1');
    exit;
}

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    $approvedSessionStmt = $pdo->prepare(
        "SELECT id, session_id, approval_status, payment_status, approved_at, created_at
         FROM registration_sessions
         WHERE user_id = ? AND approval_status = 'approved'
         ORDER BY approved_at DESC, created_at DESC LIMIT 1"
    );
    $approvedSessionStmt->execute([$_SESSION['user_id']]);
    $approvedSession = $approvedSessionStmt->fetch();
} catch (Exception $e) {
    $user = null;
    $approvedSession = null;
}

if (!$user) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

$approvedCompetitor = !empty($approvedSession);
$pageTitle = 'Competitor Card';

// Check if admin has sent the competitor card (file must exist on disk)
$cardSent = false;
if ($approvedCompetitor) {
    $cardFilePath = __DIR__ . '/uploads/competitor_cards/competitor_card_' . (int)$_SESSION['user_id'] . '.pdf';
    $cardSent = file_exists($cardFilePath);
}

// Prepare variables for dynamic rendering
$variables = [];
$photoSrc = $user['photo'] ?? '';
$dynamicHTML = '';

if ($approvedCompetitor && $cardSent) {
    // Query approved events for the competitor card table
    $userEventsStmt = $pdo->prepare("
        SELECT event_name, category, match_no, weapon_type, event_code
        FROM event_registrations
        WHERE user_id = ? AND status = 'approved'
        ORDER BY id ASC
    ");
    $userEventsStmt->execute([(int)$_SESSION['user_id']]);
    $userEvents = $userEventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $cardResults = [];
    $cleanEvtNames = [];
    foreach ($userEvents as $evt) {
        $evtCode = resolveEventCode($evt['event_name'] ?? null, $evt['event_code'] ?? null, $evt['match_no'] ?? null);
        if (empty($evtCode)) {
            $evtCode = !empty($evt['event_code']) ? $evt['event_code'] : (!empty($evt['match_no']) ? $evt['match_no'] : '—');
        }
        $cardResults[] = [
            'is_team'    => false,
            'event_name' => $evt['event_name'],
            'category'   => $evt['category'],
            'mqs'        => '',
            'score'      => '',
            'rank'       => '',
            'total'      => 0,
            'match_no'   => $evtCode,
            'event_code' => $evtCode,
            'event_no'   => $evtCode,
            'weapon_type'=> $evt['weapon_type'] ?? ''
        ];
        $cleanEvtNames[] = getCleanEventLabel($EVENTS_MAPPING[$evt['event_name']] ?? $evt['event_name'], $evt['category'] ?? '');
    }
    
    $hasRifle = false;
    $hasPistol = false;
    $cats = [];
    foreach ($userEvents as $evt) {
        $evtName = $EVENTS_MAPPING[$evt['event_name']] ?? $evt['event_name'];
        $wType = $evt['weapon_type'] ?? '';
        
        if (stripos($wType, 'pistol') !== false || stripos($evtName, 'pistol') !== false) {
            $hasPistol = true;
        }
        if (stripos($wType, 'rifle') !== false || stripos($evtName, 'rifle') !== false) {
            $hasRifle = true;
        }
        
        $c = strtoupper($evt['category'] ?? '');
        if (strpos($c, 'ISSF') !== false) {
            $cats['ISSF'] = true;
        } elseif (strpos($c, 'NR') !== false) {
            $cats['NR'] = true;
        } elseif (strpos($c, 'PARA') !== false) {
            $cats['PARA/DEAF'] = true;
        } else if (!empty($c)) {
            $cats[$c] = true;
        }
    }
    
    $weaponsStr = '';
    if ($hasRifle && $hasPistol) {
        $weaponsStr = '(RIFLE/PISTOL)';
    } elseif ($hasRifle) {
        $weaponsStr = '(RIFLE)';
    } elseif ($hasPistol) {
        $weaponsStr = '(PISTOL)';
    }
    
    $catsStr = implode('/', array_keys($cats));
    $eventSummary = '';
    if ($hasRifle || $hasPistol) {
        $eventSummary = trim($weaponsStr . ' ' . $catsStr . ' EVENTS');
    } else {
        $eventSummary = 'NO EVENTS';
    }
    
    $bibNo = formatBibNo($user['reg_id'] ?? '');
    if (empty($bibNo)) {
        $bibNo = (string)($user['id'] ?? 'TBD');
    }

    $tableData = ['results' => $cardResults];
 
    $variables = [
        'participant_name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'reg_id'           => $bibNo,
        'bib_no'           => $bibNo,
        'competitor_no'    => $bibNo,
        'club_name'        => $user['club_name'] ?? '—',
        'district'         => $user['district'] ?? '—',
        'association'      => $user['association'] ?? '—',
        'date'             => $approvedSession['approved_at'] ? date('Y-m-d', strtotime($approvedSession['approved_at'])) : date('Y-m-d'),
        'event_name'       => $eventSummary
    ];
    $dynamicHTML = getDynamicDocumentHTML('competitor_card', $variables, $photoSrc, $tableData);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> | SSA 51st TN Championship</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Cinzel:wght@400;600;700&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/participant.css">
  <!-- html2pdf.js Bundle -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <style>
    .live-preview-wrap {
        margin-top: 20px;
        background: #181818;
        border: 1px solid #333;
        border-radius: 8px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        overflow-x: auto;
    }
    .preview-canvas-container {
        box-shadow: 0 4px 20px rgba(0,0,0,0.8);
        border: 1px solid #444;
        background: #fff;
        color: #000;
        transform-origin: top center;
    }
  </style>
</head>
<body class="participant-dash">
<div class="ptcp-wrapper">
  <main class="ptcp-main" style="padding:24px 24px 40px;">
    <a href="dashboard.php?tab=overview" class="btn btn-outline" style="width:auto;padding:10px 16px;font-size:13px;margin-bottom:18px;">← Back to Overview</a>

    <div class="ptcp-page-header">
      <div>
        <div class="ptcp-page-title">Competitor Card</div>
        <div class="ptcp-page-sub">Your approved competitor profile for the <?= htmlspecialchars($activeChampionship['championship_name'] ?? 'Tamil Nadu State Shooting Championship') ?></div>
      </div>
    </div>

    <div class="ptcp-card">
      <div class="ptcp-card-header"><span class="ptcp-section-label">Approved Competitor Details</span></div>
      <?php if ($approvedCompetitor && $cardSent): ?>
      <div class="ptcp-profile-rows">
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Athlete Name</span><span class="ptcp-pval"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Registration ID</span><span class="ptcp-pval"><?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">District</span><span class="ptcp-pval"><?= htmlspecialchars($user['district'] ?? '—') ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Club</span><span class="ptcp-pval"><?= htmlspecialchars($user['club_name'] ?? '—') ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Association</span><span class="ptcp-pval"><?= htmlspecialchars($user['association'] ?? '—') ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Approval</span><span class="status-badge approved" style="font-size:10px;">Approved</span></div>
        <div class="ptcp-profile-row" style="margin-top: 15px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 15px;">
          <span class="ptcp-pkey" style="font-weight: 700; color: var(--gold-400);">Actions</span>
          <span class="ptcp-pval">
            <button onclick="downloadCompetitorCard()" class="btn" style="display:inline-flex; align-items:center; gap:8px; padding: 10px 16px; font-size:13px; font-weight:700; background:var(--gold-500); color:#000; border:none; border-radius:6px; cursor:pointer; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; transition:all 0.2s;" onmouseover="this.style.transform='translateY(-1px)';" onmouseout="this.style.transform='none';">📥 Download Competitor Card (PDF)</button>
          </span>
        </div>
      </div>

      <!-- Live Interactive Viewer -->
      <div class="live-preview-wrap">
         <h4 style="margin: 0 0 15px 0; color: var(--gold-400); font-family: 'Rajdhani', sans-serif; text-transform: uppercase;">Live Layout Preview</h4>
         <div class="preview-canvas-container" id="card-render-container">
             <?= $dynamicHTML ?>
         </div>
      </div>

      <?php elseif ($approvedCompetitor && !$cardSent): ?>
      <div class="ptcp-empty" style="padding:50px 20px; text-align:center;">
        <div style="font-size:36px; margin-bottom:14px;">🪪</div>
        <div style="font-weight:700; font-size:15px; color:var(--text-primary); margin-bottom:8px;">Competitor Card Not Yet Released</div>
        <div style="font-size:13px; color:var(--text-secondary);">Your competitor card will appear here once the Super Admin releases it. Please check back later.</div>
      </div>
      <?php else: ?>
      <div class="ptcp-empty" style="padding:40px 20px;">Waiting for Super Admin Approval</div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
function downloadCompetitorCard() {
    const element = document.querySelector('.document-canvas-render');
    if (!element) return;

    const width = element.clientWidth;
    const height = element.clientHeight;
    const isLandscape = width > height;

    const opt = {
        margin:       0,
        filename:     'CompetitorCard_<?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?>.pdf',
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'px', format: [width, height], orientation: isLandscape ? 'landscape' : 'portrait' }
    };

    html2pdf().set(opt).from(element).save();
}
</script>
</body>
</html>
