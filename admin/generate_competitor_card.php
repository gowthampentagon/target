<?php
// admin/generate_competitor_card.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/document_renderer.php';
require_once __DIR__ . '/includes/auth.php';
checkAdminAuth();

try {
    $pdo = getDB();
    
    $bulk = (int)($_GET['bulk'] ?? 0);
    $sessionId = (int)($_GET['session_id'] ?? 0);
    
    $sessions = [];
    if ($bulk === 1) {
        $stmt = $pdo->query("
            SELECT s.id, s.user_id, r.first_name, r.last_name, r.reg_id, r.club_name, r.photo, r.district, r.association
            FROM registration_sessions s
            JOIN registrations r ON s.user_id = r.id
            WHERE s.approval_status = 'approved'
            ORDER BY s.id ASC
        ");
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($sessionId > 0) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.user_id, r.first_name, r.last_name, r.reg_id, r.club_name, r.photo, r.district, r.association
            FROM registration_sessions s
            JOIN registrations r ON s.user_id = r.id
            WHERE s.id = ? AND s.approval_status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $single = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($single) {
            $sessions[] = $single;
        }
    }
    
    if (empty($sessions)) {
        die("No approved registration sessions found to generate competitor cards.");
    }
    
    // Fetch all events for each session
    foreach ($sessions as &$s) {
        $evtStmt = $pdo->prepare("
            SELECT event_name, category, match_no, weapon_type, event_code
            FROM event_registrations
            WHERE session_id = ? AND status = 'approved'
            ORDER BY id ASC
        ");
        $evtStmt->execute([$s['id']]);
        $s['events'] = $evtStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($s);
    
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Generate Competitor Cards</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Rajdhani:wght@500;600;700&family=Cinzel:wght@600;700;800&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
  
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <!-- html2pdf.js Bundle -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  
  <style>
    body {
        margin: 0;
        background: #111;
        color: #fff;
        font-family: 'Inter', sans-serif;
        padding: 20px;
    }
    .card-wrapper {
        background: #fff;
        color: #000;
        width: 794px; /* A4 width in pixels at 96 DPI */
        height: 1122px; /* A4 height in pixels at 96 DPI */
        padding: 50px;
        box-sizing: border-box;
        margin: 0 auto 30px auto;
        position: relative;
        display: flex;
        flex-direction: column;
        border: 1px solid #ccc;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    }
    .header {
        display: flex;
        align-items: center;
        border-bottom: 3px solid #000;
        padding-bottom: 15px;
        margin-bottom: 25px;
    }
    .header-logo {
        width: 80px;
        height: 80px;
        margin-right: 20px;
        flex-shrink: 0;
    }
    .header-title {
        font-family: 'Inter', sans-serif;
        font-weight: 800;
        font-size: 24px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        line-height: 1.2;
    }
    .card-title {
        text-align: center;
        font-family: 'Rajdhani', sans-serif;
        font-weight: 700;
        font-size: 32px;
        text-transform: uppercase;
        margin: 0 0 30px 0;
        letter-spacing: 1px;
    }
    .profile-section {
        display: flex;
        justify-content: space-between;
        margin-bottom: 30px;
    }
    .competitor-info {
        font-size: 16px;
        line-height: 1.6;
        flex: 1;
        padding-right: 20px;
    }
    .competitor-no {
        font-family: 'Rajdhani', sans-serif;
        font-weight: 700;
        font-size: 18px;
        margin-bottom: 15px;
    }
    .photo-box {
        width: 130px;
        height: 160px;
        border: 2px solid #000;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: #f9f9f9;
        text-align: center;
        box-sizing: border-box;
    }
    .photo-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .photo-placeholder {
        font-size: 12px;
        color: #777;
        padding: 10px;
    }
    .photo-reg-id {
        font-size: 11px;
        font-weight: 700;
        margin-top: 5px;
        text-align: center;
        font-family: monospace;
    }
    .certified-text {
        text-align: center;
        font-size: 16px;
        line-height: 1.8;
        margin-bottom: 30px;
    }
    .highlight-name {
        font-weight: 700;
        font-size: 20px;
        text-transform: uppercase;
    }
    .highlight-club {
        font-weight: 700;
        font-size: 18px;
        text-transform: uppercase;
    }
    .highlight-championship {
        font-weight: 800;
        font-size: 16px;
        margin: 10px 0;
        display: block;
    }
    .event-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        margin-bottom: auto;
    }
    .event-table th {
        background: #a6b9d0;
        color: #000;
        font-weight: 700;
        font-size: 14px;
        text-transform: uppercase;
        border: 1px solid #000;
        padding: 10px;
        text-align: left;
    }
    .event-table td {
        border: 1px solid #ccc;
        padding: 10px;
        font-size: 13.5px;
    }
    .event-table tr:nth-child(even) td {
        background: #f2f5f9;
    }
    .footer-section {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-top: 40px;
        padding-top: 20px;
    }
    .signature-area {
        text-align: center;
        width: 200px;
        font-family: 'Rajdhani', sans-serif;
        font-weight: 600;
        font-size: 14px;
    }
    .signature-line {
        border-top: 1px solid #000;
        margin-top: 60px;
        padding-top: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .page-indicator {
        border-top: 1px solid #ccc;
        padding-top: 10px;
        font-size: 12px;
        color: #555;
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
    }
    @page {
        size: A4;
        margin: 0;
    }
    @media print {
        html, body {
            width: 210mm;
            background: #fff !important;
            color: #000 !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .no-print {
            display: none !important;
        }
        .card-wrapper-outer {
            box-shadow: none !important;
            margin: 0 auto !important;
            max-width: 210mm !important;
            page-break-after: always;
            page-break-inside: avoid;
        }
        .card-wrapper-outer:last-child {
            page-break-after: avoid;
        }
    }
  </style>
</head>
<body>

  <?php
    $btnSendText = ($bulk === 1) ? "📤 Send All to Logins" : "📤 Send to Competitor Login";
    $btnDownloadText = ($bulk === 1) ? "📥 Download All PDFs" : "📥 Download PDF";
  ?>
  <div style="max-width: 800px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center; background: #222; padding: 15px 20px; border-radius: 8px; border: 1px solid #333;" class="no-print">
    <div>
        <h3 style="margin:0; color:#fff; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.5px; font-size: 16px;">Competitor Card Preview</h3>
        <p style="margin:5px 0 0 0; color:#aaa; font-size:12px;">Verify details and send to athlete login, or download the PDF.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button onclick="saveAndSend()" style="background:#ADB5BD; color:#000; border:none; padding:10px 18px; font-weight:700; cursor:pointer; border-radius:4px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:6px;"><?= $btnSendText ?></button>
        <button onclick="downloadOnly()" style="background:#27ae60; color:#fff; border:none; padding:10px 18px; font-weight:700; cursor:pointer; border-radius:4px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:6px;"><?= $btnDownloadText ?></button>
        <button onclick="window.print()" style="background:#3498db; color:#fff; border:none; padding:10px 18px; font-weight:700; cursor:pointer; border-radius:4px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; display:inline-flex; align-items:center; gap:6px;">🖨️ Print Cards</button>
        <button onclick="window.close()" style="background:#e74c3c; color:#fff; border:none; padding:10px 18px; font-weight:700; cursor:pointer; border-radius:4px; font-family:'Rajdhani',sans-serif; text-transform:uppercase; font-size:12px; letter-spacing:0.5px;">Close</button>
    </div>
  </div>

  <div id="cards-container">
    <?php foreach ($sessions as $index => $s): 
        $fullName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $photoSrc = !empty($s['photo']) ? $s['photo'] : '';
        $cardResults = [];
        $cleanEvtNames = [];
        if (!empty($s['events'])) {
            foreach ($s['events'] as $evt) {
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
            }
        }
        $hasRifle = false;
        $hasPistol = false;
        $cats = [];
        if (!empty($s['events'])) {
            foreach ($s['events'] as $evt) {
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

        $bibNo = formatBibNo($s['reg_id'] ?? '');
        if (empty($bibNo)) {
            $bibNo = (string)($s['user_id'] ?? 'TBD');
        }

        $tableData = ['results' => $cardResults];

        $vars = [
            'participant_name' => $fullName,
            'reg_id'           => $bibNo,
            'bib_no'           => $bibNo,
            'competitor_no'    => $bibNo,
            'club_name'        => $s['club_name'] ?? '—',
            'district'         => $s['district'] ?? '—',
            'association'      => $s['association'] ?? '—',
            'event_name'       => $eventSummary
        ];
    ?>
      <div class="card-wrapper-outer" id="card-<?= $s['user_id'] ?>" style="margin: 0 auto 30px auto; width: max-content; display: block; background: #fff; color: #000; box-shadow: 0 4px 20px rgba(0,0,0,0.5);">
          <?= getDynamicDocumentHTML('competitor_card', $vars, $photoSrc, $tableData) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
    const sessions = <?= json_encode($sessions) ?>;
    
    function getExportOptions(cardEl, filename) {
        const canvasEl = cardEl.querySelector('.document-canvas-render');
        const width = canvasEl ? canvasEl.clientWidth : 794;
        const height = canvasEl ? canvasEl.clientHeight : 1122;
        const isLandscape = width > height;

        return {
            margin: 0,
            filename: filename,
            image: { type: 'jpeg', quality: 1.0 },
            html2canvas: { scale: 2, useCORS: true, logging: false },
            jsPDF: { unit: 'px', format: [width, height], orientation: isLandscape ? 'landscape' : 'portrait' }
        };
    }

    async function saveAndSend() {
        Swal.fire({
            title: 'Sending Competitor Cards',
            text: 'Processing... please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        let successCount = 0;
        for (const s of sessions) {
            const cardEl = document.getElementById('card-' + s.user_id);
            if (!cardEl) continue;
            
            const opt = getExportOptions(cardEl, 'competitor_card_' + s.reg_id + '.pdf');
            
            try {
                const pdfBlob = await html2pdf().from(cardEl).set(opt).outputPdf('blob');
                
                const fd = new FormData();
                fd.append('card_pdf', pdfBlob, 'competitor_card_' + s.user_id + '.pdf');
                fd.append('user_id', s.user_id);
                
                const res = await fetch('actions/upload_competitor_card.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    successCount++;
                }
            } catch (err) {
                console.error("Error generating card for user " + s.user_id + ":", err);
            }
        }
        
        Swal.fire({
            title: 'Sent Successfully!',
            text: successCount + ' competitor card(s) have been generated and sent to competitor login(s).',
            icon: 'success',
            background: '#1a1a1a',
            color: '#fff',
            confirmButtonColor: '#ADB5BD'
        }).then(() => {
            window.opener && window.opener.location.reload();
            window.close();
        });
    }
    
    async function downloadOnly() {
        Swal.fire({
            title: 'Downloading PDFs',
            text: 'Preparing files... please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        for (const s of sessions) {
            const cardEl = document.getElementById('card-' + s.user_id);
            if (!cardEl) continue;
            
            const opt = getExportOptions(cardEl, 'competitor_card_' + s.reg_id + '.pdf');
            
            try {
                await html2pdf().from(cardEl).set(opt).save();
            } catch (err) {
                console.error("Error downloading card for user " + s.user_id + ":", err);
            }
        }
        
        Swal.close();
    }
  </script>
</body>
</html>
