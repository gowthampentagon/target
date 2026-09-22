<?php
/**
 * athlete_history.php – Dedicated Athlete History Page
 * Saragarhi Shooting Academy – 51st Tamil Nadu Shooting Championship
 */
require_once 'config/db.php';
$eventsList = require 'config/events.php';
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

    // Query all approved event registrations for this user, sorted by year and creation date
    $approvedEventsStmt = $pdo->prepare(
        "SELECT event_reg_id, category, event_name, mqs_score, competition_name, shooting_year, certificate_path, status, created_at
         FROM event_registrations
         WHERE user_id = ? AND status = 'approved'
         ORDER BY COALESCE(NULLIF(shooting_year, ''), YEAR(created_at)) DESC, created_at DESC"
    );
    $approvedEventsStmt->execute([$_SESSION['user_id']]);
    $historyItems = $approvedEventsStmt->fetchAll();
} catch (Exception $e) {
    $user = null;
    $historyItems = [];
}

if (!$user) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

$pageTitle = 'Athlete History';
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
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    /* Athlete History Specific Styles */
    .ptcp-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    .ptcp-table th {
      border-bottom: 1px solid rgba(255, 255, 255,0.25);
      color: var(--text-secondary);
      font-family: 'Rajdhani', sans-serif;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 14px 18px;
      text-align: left;
      font-size: 12px;
    }
    .ptcp-table td {
      padding: 16px 18px;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      color: var(--text-primary);
      font-size: 13px;
      vertical-align: middle;
    }
    .ptcp-table tr:hover {
      background: rgba(255,255,255,0.01);
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      inset: 0;
      background: rgba(5,6,10,0.94);
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(6px);
      animation: fadeIn 0.2s ease-out;
      padding: 16px;
    }
    .modal-content {
      background: var(--dark-900);
      border: 1px solid rgba(255, 255, 255,0.3);
      border-radius: 12px;
      width: 100%;
      max-width: 850px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      position: relative;
      box-shadow: 0 20px 50px rgba(0,0,0,0.9);
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal-header {
      padding: 16px 24px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: rgba(255,255,255,0.01);
    }
    .modal-title {
      font-family: 'Rajdhani', sans-serif;
      font-size: 15px;
      font-weight: 700;
      color: var(--gold-400);
      text-transform: uppercase;
      letter-spacing: 1px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 80%;
    }
    .modal-close {
      background: none;
      border: none;
      color: var(--text-secondary);
      font-size: 28px;
      cursor: pointer;
      line-height: 1;
      transition: color var(--trans-fast);
      display: flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
    }
    .modal-close:hover {
      color: var(--accent-red);
    }
    .modal-body {
      padding: 20px;
      flex-grow: 1;
      overflow-y: auto;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--dark-950);
      min-height: 400px;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes slideUp {
      from { transform: translateY(24px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    /* Action buttons styling */
    .btn-view {
      background: rgba(255, 255, 255,0.08);
      border: 1px solid rgba(255, 255, 255,0.3);
      color: var(--gold-400);
      padding: 7px 16px;
      font-size: 11px;
      border-radius: 6px;
      cursor: pointer;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      transition: all var(--trans-fast);
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn-view:hover {
      background: var(--grad-gold);
      color: #08090C;
      border-color: transparent;
      box-shadow: 0 0 12px rgba(255, 255, 255,0.35);
    }

    .btn-disabled {
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.04);
      color: var(--text-muted);
      padding: 7px 14px;
      font-size: 11px;
      border-radius: 6px;
      cursor: not-allowed;
      font-family: 'Rajdhani', sans-serif;
      font-weight: 500;
      white-space: nowrap;
    }
  </style>
</head>
<body class="participant-dash">
<div class="ptcp-wrapper">
  <main class="ptcp-main" style="padding:24px 24px 40px; overflow-y:auto; width:100%;">
    <a href="dashboard.php?tab=overview" class="btn btn-outline" style="width:auto;padding:10px 16px;font-size:13px;margin-bottom:18px; display:inline-flex; align-items:center; gap:6px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
      Back to Overview
    </a>

    <div class="ptcp-page-header" style="margin-bottom:24px;">
      <div>
        <div class="ptcp-page-title" style="font-family:'Cinzel',serif; color:var(--gold-400); letter-spacing:1px;">Athlete History</div>
        <div class="ptcp-page-sub">Your verified championship history and performance record</div>
      </div>
    </div>

    <div class="ptcp-card" style="border:1px solid rgba(255, 255, 255,0.15); background:rgba(13,15,20,0.6); backdrop-filter:blur(8px);">
      <div class="ptcp-card-header" style="border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:14px; margin-bottom:10px;">
        <span class="ptcp-section-label" style="font-family:'Rajdhani',sans-serif; font-size:14px; font-weight:700; color:var(--gold-400); letter-spacing:1px; text-transform:uppercase;">Championship Records</span>
      </div>
      
      <?php if (!empty($historyItems)): ?>
      <div style="overflow-x:auto;">
        <table class="ptcp-table">
          <thead>
            <tr>
              <th style="width: 22%;">Competition Name</th>
              <th style="width: 18%;">Event Name</th>
              <th style="width: 8%;">Year</th>
              <th style="width: 8%;">Category</th>
              <th style="width: 10%;">Score</th>
              <th style="width: 10%;">Position</th>
              <th style="width: 12%;">Achievement</th>
              <th style="width: 12%; text-align: center;">Certificate</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historyItems as $item): ?>
            <?php
              $evCode = $item['event_name'] ?? '';
              $evObj = $eventsList[$evCode] ?? null;
              $compName = $evObj ? ($evObj['group'] ?? '51st Tamil Nadu Shooting Championship') : '51st Tamil Nadu Shooting Championship';
              $eventNameResolved = $evObj ? ($evObj['label'] ?? $evCode) : $evCode;
              
              $scoreStmt = $pdo->prepare("
                  SELECT ss.grand_total_val
                  FROM lane_allocations la
                  JOIN score_sheets ss ON ss.lane_alloc_id = la.id
                  WHERE la.event_reg_id = ?
                  LIMIT 1
              ");
              $scoreStmt->execute([$item['event_reg_id']]);
              $scoreRow = $scoreStmt->fetch(PDO::FETCH_ASSOC);
              $achievedScore = $scoreRow ? $scoreRow['grand_total_val'] : null;

              if (empty($achievedScore)) {
                  $itemEvtFullName = $EVENTS_MAPPING[$item['event_name']] ?? $item['event_name'];
                  $itemBaseType = getBackendEventBaseType($itemEvtFullName);
                  $itemCatFamily = (strpos(strtoupper($item['category']), 'ISSF') !== false || strpos(strtoupper($itemEvtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                  $fbStmt = $pdo->prepare("
                      SELECT ss.grand_total_val, er.event_name, er.category
                      FROM lane_allocations la
                      JOIN event_registrations er ON la.event_reg_id = er.id
                      JOIN score_sheets ss ON ss.lane_alloc_id = la.id
                      WHERE er.user_id = ? AND ss.grand_total_val IS NOT NULL AND ss.grand_total_val != ''
                      ORDER BY ss.id DESC
                  ");
                  $fbStmt->execute([$item['user_id'] ?? $_SESSION['user_id']]);
                  $fbScores = $fbStmt->fetchAll(PDO::FETCH_ASSOC);

                  foreach ($fbScores as $fb) {
                      $fbFullName = $EVENTS_MAPPING[$fb['event_name']] ?? $fb['event_name'];
                      $fbBaseType = getBackendEventBaseType($fbFullName);
                      $fbCatFamily = (strpos(strtoupper($fb['category']), 'ISSF') !== false || strpos(strtoupper($fbFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                      if ($itemCatFamily === $fbCatFamily && $itemBaseType === $fbBaseType) {
                          $achievedScore = $fb['grand_total_val'];
                          break;
                      }
                  }
              }

              // Calculate dynamic position and medal if a score is recorded
              $position = '—';
              $medal = '—';
              if ($achievedScore !== null && $achievedScore !== '') {
                  // Fetch all approved registrations for this event to calculate rank accurately
                  $rankAllStmt = $pdo->prepare("
                      SELECT er2.user_id, ss2.grand_total_val AS direct_score, ss2.series_data AS direct_series_data, ss2.remarks AS direct_remarks
                      FROM event_registrations er2
                      LEFT JOIN lane_allocations la2 ON la2.event_reg_id = er2.id
                      LEFT JOIN score_sheets ss2 ON ss2.lane_alloc_id = la2.id
                      WHERE er2.event_name = ? AND er2.category = ? AND er2.status = 'approved'
                  ");
                  $rankAllStmt->execute([$item['event_name'], $item['category']]);
                  $allCompRows = $rankAllStmt->fetchAll(PDO::FETCH_ASSOC);

                  $compRows = [];
                  $seenUids = [];
                  foreach ($allCompRows as $cr) {
                      $cId = (int)$cr['user_id'];
                      if (isset($seenUids[$cId])) continue;

                      $cScore = $cr['direct_score'] ?? null;
                      $cSeriesData = $cr['direct_series_data'] ?? null;
                      $cRemarks = $cr['direct_remarks'] ?? null;

                      if (empty($cScore)) {
                          $cScoresStmt = $pdo->prepare("
                              SELECT ss_inner.grand_total_val, ss_inner.series_data, ss_inner.remarks, er_inner.event_name, er_inner.category
                              FROM lane_allocations la_inner
                              JOIN event_registrations er_inner ON la_inner.event_reg_id = er_inner.id
                              JOIN score_sheets ss_inner ON la_inner.id = ss_inner.lane_alloc_id
                              WHERE er_inner.user_id = ? AND ss_inner.grand_total_val IS NOT NULL AND ss_inner.grand_total_val != ''
                          ");
                          $cScoresStmt->execute([$cId]);
                          $cAllocs = $cScoresStmt->fetchAll(PDO::FETCH_ASSOC);

                          $itemEvtFullName = $EVENTS_MAPPING[$item['event_name']] ?? $item['event_name'];
                          $itemBaseType = getBackendEventBaseType($itemEvtFullName);
                          $itemCatFamily = (strpos(strtoupper($item['category']), 'ISSF') !== false || strpos(strtoupper($itemEvtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                          foreach ($cAllocs as $ca) {
                              $caFullName = $EVENTS_MAPPING[$ca['event_name']] ?? $ca['event_name'];
                              $caBaseType = getBackendEventBaseType($caFullName);
                              $caCatFamily = (strpos(strtoupper($ca['category']), 'ISSF') !== false || strpos(strtoupper($caFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                              if ($itemCatFamily === $caCatFamily && $itemBaseType === $caBaseType) {
                                  $cScore = $ca['grand_total_val'];
                                  $cSeriesData = $ca['series_data'];
                                  $cRemarks = $ca['remarks'];
                                  break;
                              }
                          }
                      }

                      if (!empty($cScore)) {
                          $seenUids[$cId] = true;
                          $compRows[] = [
                              'user_id' => $cId,
                              'grand_total_val' => $cScore,
                              'series_data' => $cSeriesData,
                              'remarks' => $cRemarks
                          ];
                      }
                  }

                  usort($compRows, 'compareShooterScores');
                  $totalCount = count($compRows);
                  $myRank = 1;
                  $rkCount = 0;
                  $targetUid = (int)($item['user_id'] ?? $_SESSION['user_id']);
                  foreach ($compRows as $cRow) {
                      $rkCount++;
                      if ((int)$cRow['user_id'] === $targetUid) {
                          $myRank = $rkCount;
                          break;
                      }
                  }

                  $position = "$myRank / $totalCount";

                  // Medal determination
                  if ($myRank === 1) {
                      $medal = 'GOLD';
                  } elseif ($myRank === 2) {
                      $medal = 'SILVER';
                  } elseif ($myRank === 3) {
                      $medal = 'BRONZE';
                  } else {
                      $medal = 'Participation';
                  }
              }

              $hasCert = !empty($item['certificate_path']) && (strpos($item['certificate_path'], 'uploads/certificates/') !== false);
              $displayYear = !empty($item['shooting_year']) ? $item['shooting_year'] : date('Y', strtotime($item['created_at']));
            ?>
            <tr>
              <td>
                <div style="font-weight:600; color:var(--gold-400); font-size:13.5px;"><?= htmlspecialchars($compName) ?></div>
              </td>
              <td>
                <div style="font-size:12.5px; color:var(--text-primary);"><?= htmlspecialchars($eventNameResolved) ?></div>
              </td>
              <td style="font-family:'Rajdhani',sans-serif; font-size:14px; font-weight:600; color:var(--text-primary);">
                <?= htmlspecialchars($displayYear) ?>
              </td>
              <td style="font-family:'Rajdhani',sans-serif; font-size:13px; font-weight:600; color:var(--text-secondary);">
                <?= htmlspecialchars($item['category'] ?? '—') ?>
              </td>
              <td style="font-family:'Rajdhani',sans-serif; font-size:14px; font-weight:700; color:var(--gold-400);">
                <?= $achievedScore !== null && $achievedScore !== '' ? htmlspecialchars((string)$achievedScore) : '—' ?>
              </td>
              <td style="font-family:'Rajdhani',sans-serif; font-size:14px; font-weight:700; color:var(--text-primary);">
                <?= $position ?>
              </td>
              <td>
                <?php if ($medal === 'GOLD'): ?>
                  <span class="text-secondary" style="font-weight:800; font-family:'Rajdhani',sans-serif;"><i class="bi bi-award-fill"></i> GOLD</span>
                <?php elseif ($medal === 'SILVER'): ?>
                  <span class="text-secondary" style="font-weight:800; font-family:'Rajdhani',sans-serif; opacity:0.85;"><i class="bi bi-award-fill"></i> SILVER</span>
                <?php elseif ($medal === 'BRONZE'): ?>
                  <span class="text-secondary" style="font-weight:800; font-family:'Rajdhani',sans-serif; opacity:0.7;"><i class="bi bi-award-fill"></i> BRONZE</span>
                <?php elseif ($medal === 'Participation'): ?>
                  <span class="text-muted" style="font-size:12px;">Participation</span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align: center;">
                <?php if ($hasCert): ?>
                  <button type="button" class="btn-view" onclick="viewCertificate('<?= htmlspecialchars($item['certificate_path']) ?>', '<?= htmlspecialchars(addslashes($compName)) ?> - <?= htmlspecialchars(addslashes($eventNameResolved)) ?>')">View</button>
                <?php else: ?>
                  <button type="button" class="btn-disabled" disabled>Not Available</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="ptcp-empty" style="padding:60px 20px; text-align:center; color:var(--text-secondary); font-size:13.5px;">
        <div class="text-secondary" style="font-size:28px; margin-bottom:12px;"><i class="bi bi-trophy-fill"></i></div>
        No competition history available yet.<br>
        <span style="font-size:11.5px; color:var(--text-muted); margin-top:6px; display:inline-block;">Only events with approved registrations are displayed.</span>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Preview Modal -->
<div id="certModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal-content">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">View Certificate</span>
      <button onclick="closeModal()" class="modal-close" aria-label="Close modal">&times;</button>
    </div>
    <div id="modalBody" class="modal-body">
      <!-- Loaded dynamically via JavaScript -->
    </div>
  </div>
</div>

<script>
function viewCertificate(path, eventInfo) {
  const modal = document.getElementById('certModal');
  const title = document.getElementById('modalTitle');
  const body = document.getElementById('modalBody');
  
  title.textContent = 'Certificate Preview — ' + eventInfo;
  body.innerHTML = '';
  
  // Resolve absolute/relative path correctly
  // If the path does not start with http or a slash, prepending the base directory if needed.
  let cleanPath = path;
  if (!path.startsWith('http') && !path.startsWith('/')) {
    // Relative paths in ssa project are relative to root, e.g. "uploads/..."
    cleanPath = path;
  }
  
  const ext = path.split('.').pop().toLowerCase();
  if (ext === 'pdf') {
    body.innerHTML = `<iframe src="${cleanPath}" style="width:100%; height:60vh; border:none; border-radius:6px; background:#fff;"></iframe>`;
  } else {
    body.innerHTML = `<img src="${cleanPath}" style="max-width:100%; max-height:60vh; object-fit:contain; border-radius:6px; box-shadow: 0 4px 12px rgba(0,0,0,0.5);" alt="Certificate" />`;
  }
  
  modal.style.display = 'flex';
}

function closeModal() {
  const modal = document.getElementById('certModal');
  modal.style.display = 'none';
}

// Close when clicking overlay background
window.onclick = function(event) {
  const modal = document.getElementById('certModal');
  if (event.target === modal) {
    modal.style.display = 'none';
  }
}
</script>
</body>
</html>
