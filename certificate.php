<?php
// certificate.php
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
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Fetch approved event results using standardized discipline and category matched results
    $userResults = getParticipantApprovedResults($pdo, (int)$_SESSION['user_id']);

    // Check if admin has released (uploaded) any certificate for this user
    $releasedCertStmt = $pdo->prepare(
        "SELECT er.id, er.event_name, er.category, er.certificate_path
         FROM event_registrations er
         WHERE er.user_id = ? AND er.status = 'approved'
           AND er.certificate_path IS NOT NULL AND er.certificate_path != ''
         ORDER BY er.id DESC LIMIT 1"
    );
    $releasedCertStmt->execute([$_SESSION['user_id']]);
    $releasedCert = $releasedCertStmt->fetch();

    $targetEvent = null;
    $scoreAndRank = ['score' => '', 'rank' => '', 'total' => 0];

    if (!empty($userResults)) {
        $firstRes = $userResults[0];
        $targetEvent = [
            'id' => $firstRes['event_reg_db_id'] ?? 1,
            'event_name' => $firstRes['event_name'],
            'category' => $firstRes['category'],
            'scheduled_date' => date('Y-m-d')
        ];
        $scoreAndRank = [
            'score' => (string)($firstRes['score'] ?? ''),
            'rank' => (string)($firstRes['rank'] ?? ''),
            'total' => (int)($firstRes['total'] ?? 0)
        ];
    }
} catch (Exception $e) {
    $user = null;
    $approvedEvents = [];
    $targetEvent = null;
    $releasedCert = null;
}

if (!$user) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

$pageTitle = 'Certificates';
// Certificate is only available if admin has uploaded/released it (certificate_path set in DB)
$adminReleasedCert = !empty($releasedCert) && !empty($releasedCert['certificate_path']);
$certificateAvailable = $adminReleasedCert && ($targetEvent !== null && !empty($scoreAndRank['score']));
$certificateType = $certificateAvailable ? 'Certificate of Merit' : 'Certificate Not Available Yet';

// Prepare variables for dynamic rendering
$variables = [];
$photoSrc = $user['photo'] ?? '';
$dynamicHTML = '';

if ($certificateAvailable && $targetEvent) {
    // Fetch all approved results for the table rendering
    $userResults = getParticipantApprovedResults($pdo, (int)$_SESSION['user_id']);
    $tableData = ['results' => $userResults];

    $variables = [
        'participant_name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'reg_id'           => $user['reg_id'] ?? 'TBD',
        'event_name'       => isset($targetEvent['event_name']) ? getCleanEventLabel($EVENTS_MAPPING[$targetEvent['event_name']] ?? $targetEvent['event_name'], $targetEvent['category'] ?? '') : '',
        'category'         => $targetEvent['category'] ?? '',
        'score'            => $scoreAndRank['score'] ?: '—',
        'rank'             => $scoreAndRank['rank'] ?: '—',
        'date'             => $targetEvent['scheduled_date'] ?? date('Y-m-d'),
        'cert_no'          => 'CERT-' . ($user['reg_id'] ?? 'TBD') . '-' . $targetEvent['id'],
        'club_name'        => $user['club_name'] ?? '—',
        'district'         => $user['district'] ?? '—',
        'association'      => $user['association'] ?? '—'
    ];
    $dynamicHTML = getDynamicDocumentHTML('certificate', $variables, $photoSrc, $tableData);
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
  <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=Cinzel:wght@400;600;700&display=swap" rel="stylesheet">
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
        /* Custom scaling helper for mobile layouts */
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
        <div class="ptcp-page-title">Certificates</div>
        <div class="ptcp-page-sub">Download your dynamically generated certificate of merit</div>
      </div>
    </div>

    <div class="ptcp-card">
      <div class="ptcp-card-header"><span class="ptcp-section-label">Certificate Details</span></div>
      <?php if ($certificateAvailable && $targetEvent): ?>
      <div class="ptcp-profile-rows">
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Event</span><span class="ptcp-pval"><?= htmlspecialchars($targetEvent['event_name'] ?? '—') ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Score Achieved</span><span class="ptcp-pval"><?= htmlspecialchars($scoreAndRank['score']) ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Final Placement</span><span class="ptcp-pval">Rank <?= htmlspecialchars($scoreAndRank['rank']) ?> of <?= htmlspecialchars((string)$scoreAndRank['total']) ?></span></div>
        <div class="ptcp-profile-row"><span class="ptcp-pkey">Certificate Type</span><span class="ptcp-pval"><?= htmlspecialchars($certificateType) ?></span></div>
        <div class="ptcp-profile-row">
          <span class="ptcp-pkey">Actions</span>
          <span class="ptcp-pval">
            <button onclick="downloadCertificate()" class="btn" style="display:inline-flex; align-items:center; gap:8px; padding: 10px 16px; font-size:13px; font-weight:700; background:var(--gold-500); color:#000; border:none; border-radius:6px; cursor:pointer; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; transition:all 0.2s;" onmouseover="this.style.transform='translateY(-1px)';" onmouseout="this.style.transform='none';">📥 Download Certificate (PDF)</button>
          </span>
        </div>
      </div>
      
      <!-- Live Interactive Viewer -->
      <div class="live-preview-wrap">
         <h4 style="margin: 0 0 15px 0; color: var(--gold-400); font-family: 'Rajdhani', sans-serif; text-transform: uppercase;">Live Layout Preview</h4>
         <div class="preview-canvas-container" id="certificate-render-container">
             <?= $dynamicHTML ?>
         </div>
      </div>

      <?php elseif ($targetEvent !== null && !empty($scoreAndRank['score']) && !$adminReleasedCert): ?>
      <div class="ptcp-empty" style="padding:50px 20px; text-align:center;">
        <div style="font-size:36px; margin-bottom:14px;">🎖️</div>
        <div style="font-weight:700; font-size:15px; color:var(--text-primary); margin-bottom:8px;">Certificate Not Yet Released</div>
        <div style="font-size:13px; color:var(--text-secondary);">Your certificate has been generated but not yet released by the Super Admin. Please check back later.</div>
      </div>
      <?php else: ?>
      <div class="ptcp-empty" style="padding:50px 20px; text-align:center;">
        <div style="font-size:36px; margin-bottom:14px;">🏆</div>
        <div style="font-weight:700; font-size:15px; color:var(--text-primary); margin-bottom:8px;">Certificate Not Available Yet</div>
        <div style="font-size:13px; color:var(--text-secondary);">Certificates will be issued once your event scores are finalized by the admin.</div>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
function downloadCertificate() {
    const element = document.querySelector('.document-canvas-render');
    if (!element) return;
    
    // Determine dimensions from template element
    const width = element.clientWidth;
    const height = element.clientHeight;
    const isLandscape = width > height;

    const opt = {
        margin:       0,
        filename:     'Certificate_<?= htmlspecialchars($user['reg_id'] ?? 'TBD') ?>.pdf',
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'px', format: [width, height], orientation: isLandscape ? 'landscape' : 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save();
}
</script>
</body>
</html>
