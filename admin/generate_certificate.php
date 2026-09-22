<?php
// admin/generate_certificate.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/document_renderer.php';
require_once __DIR__ . '/includes/auth.php';
checkAdminAuth();

function getBase64Image(string $filePath): string {
    if (file_exists($filePath) && is_file($filePath)) {
        $data = file_get_contents($filePath);
        $type = mime_content_type($filePath) ?: 'image/png';
        return 'data:' . $type . ';base64,' . base64_encode($data);
    }
    return '';
}

try {
    $pdo = getDB();
    $logoBase64 = getBase64Image(dirname(__DIR__) . '/images/logo.png');

    $selEventRaw = trim($_GET['event_name'] ?? '');
    $selCategory = trim($_GET['category']   ?? '');

    // Handle composite event_name format e.g. "EVENT_NAME||CATEGORY"
    if (strpos($selEventRaw, '||') !== false) {
        [$selEvent, $catFromEvent] = explode('||', $selEventRaw, 2);
        $selEvent = trim($selEvent);
        if (empty($selCategory)) {
            $selCategory = trim($catFromEvent);
        }
    } else {
        $selEvent = $selEventRaw;
    }

    if (empty($selEvent)) {
        die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>body{background:#0d0d0d;color:#fff;font-family:"Inter",sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.box{background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:30px 40px;text-align:center;max-width:450px;box-shadow:0 10px 30px rgba(0,0,0,0.5);}.icon{font-size:48px;margin-bottom:15px;}.title{font-size:18px;font-weight:700;color:#e74c3c;margin-bottom:10px;}.msg{font-size:14px;color:#aaa;line-height:1.5;}</style></head><body><div class="box"><div class="icon">⚠️</div><div class="title">Invalid Parameters</div><div class="msg">Event name is required to view or generate certificates.</div></div></body></html>');
    }

    $userId = (int)($_GET['user_id'] ?? 0);

    // Build event candidate list matching rank_list.php expansion
    $selEventsList = [$selEvent];
    $expandedEvts  = [$selEvent];

    if (isset($EVENTS_MAPPING[$selEvent])) {
        $expandedEvts[] = $EVENTS_MAPPING[$selEvent];
    }
    foreach ($EVENTS_MAPPING as $code => $fullName) {
        if (strcasecmp($fullName, $selEvent) === 0 || strcasecmp($code, $selEvent) === 0) {
            $expandedEvts[] = $code;
            $expandedEvts[] = $fullName;
        }
    }
    $targetBaseType = getBackendEventBaseType($EVENTS_MAPPING[$selEvent] ?? $selEvent);
    if (!empty($targetBaseType)) {
        foreach ($EVENTS_MAPPING as $id => $fullName) {
            $baseType = getBackendEventBaseType($fullName);
            if (!empty($baseType) && strcasecmp($baseType, $targetBaseType) === 0) {
                $expandedEvts[] = $id;
                $expandedEvts[] = $fullName;
            }
        }
    }
    $selEventsList = array_values(array_unique(array_filter($expandedEvts)));

    $whereClauses = [
        "ss.grand_total_val IS NOT NULL AND ss.grand_total_val != ''",
        "(ss.remarks IS NULL OR ss.remarks = '' OR ss.remarks IN ('C', 'Completed', 'COMPLETED'))"
    ];
    $queryParams = [];

    // Match event_name or event_code against expanded candidate events
    $inEvts = implode(',', array_fill(0, count($selEventsList), '?'));
    $whereClauses[] = "(er.event_name IN ($inEvts) OR er.event_code IN ($inEvts))";
    foreach ($selEventsList as $eCode) { $queryParams[] = $eCode; }
    foreach ($selEventsList as $eCode) { $queryParams[] = $eCode; }

    if (!empty($selCategory)) {
        $whereClauses[] = "(UPPER(er.category) = ? OR UPPER(ss.custom_category) = ? OR UPPER(er.event_name) LIKE ?)";
        $queryParams[]  = strtoupper($selCategory);
        $queryParams[]  = strtoupper($selCategory);
        $queryParams[]  = '%' . strtoupper($selCategory) . '%';
    }

    if ($userId > 0) {
        $whereClauses[] = "r.id = ?";
        $queryParams[]  = $userId;
    }

    $sqlWhere = implode(" AND ", $whereClauses);

    $sql = "
        SELECT
            la.id AS alloc_id,
            la.relay_no,
            la.lane_no,
            la.scheduled_date,
            la.bib_no,
            la.custom_name,
            ss.custom_shooter_name,
            ss.custom_bib,
            ss.grand_total_val,
            ss.total_val,
            ss.penalty_val,
            ss.custom_category,
            r.first_name,
            r.last_name,
            r.club_name,
            r.district,
            r.association,
            r.reg_id,
            r.id AS user_id,
            r.photo,
            er.event_code,
            er.match_no,
            er.category AS event_category
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        JOIN registrations r ON er.user_id = r.id
        LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
        WHERE $sqlWhere
        ORDER BY CAST(ss.grand_total_val AS DECIMAL(10,2)) DESC, la.scheduled_date ASC, la.relay_no ASC
    ";

    $q = $pdo->prepare($sql);
    $q->execute($queryParams);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: If querying for a specific user returned no rows with strict filters, query any completed entry for user
    if ($userId > 0 && empty($rows)) {
        $fallbackSql = "
            SELECT
                la.id AS alloc_id,
                la.relay_no,
                la.lane_no,
                la.scheduled_date,
                la.bib_no,
                la.custom_name,
                ss.custom_shooter_name,
                ss.custom_bib,
                ss.grand_total_val,
                ss.total_val,
                ss.penalty_val,
                ss.custom_category,
                r.first_name,
                r.last_name,
                r.club_name,
                r.district,
                r.association,
                r.reg_id,
                r.id AS user_id,
                r.photo,
                er.event_code,
                er.match_no,
                er.category AS event_category
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
            WHERE r.id = ? AND ss.grand_total_val IS NOT NULL AND ss.grand_total_val != ''
            ORDER BY CAST(ss.grand_total_val AS DECIMAL(10,2)) DESC, la.scheduled_date ASC
        ";
        $qFb = $pdo->prepare($fallbackSql);
        $qFb->execute([$userId]);
        $rows = $qFb->fetchAll(PDO::FETCH_ASSOC);
    }

    // Deduplicate by registrations.reg_id
    $seen = [];
    $deduped = [];
    foreach ($rows as $row) {
        $ident = $row['reg_id'];
        if (!isset($seen[$ident])) {
            $seen[$ident] = true;
            $deduped[] = $row;
        }
    }
    $rows = $deduped;

    $totalCompetitors = count($rows);
    $certificates = [];

    foreach ($rows as $idx => $r) {
        $rank = $idx + 1;
        $fullName = !empty($r['custom_shooter_name'])
            ? $r['custom_shooter_name']
            : (formatFullName($r['first_name'], $r['last_name']) ?: '—');
        
        $clubName = !empty($r['club_name']) ? $r['club_name'] : 'Saragarhi Shooting Academy';
        
        $regId = !empty($r['custom_bib'])
            ? $r['custom_bib']
            : (!empty($r['bib_no']) ? $r['bib_no'] : ($r['reg_id'] ?? 'TBD'));

        // Determine Medal / Remarks
        $medal = '---';
        if ($rank === 1) {
            $medal = 'GOLD';
        } elseif ($rank === 2) {
            $medal = 'SILVER';
        } elseif ($rank === 3) {
            $medal = 'BRONZE';
        }

        // Fetch photo if exists and convert to base64
        $photoSrc = '';
        if (!empty($r['photo'])) {
            $realPhotoPath = dirname(__DIR__) . '/' . $r['photo'];
            $photoSrc = getBase64Image($realPhotoPath);
        }

        // Event name display
        $eventLabelFull = $EVENTS_MAPPING[$selEvent] ?? $selEvent;
        if (empty($eventLabelFull) || $eventLabelFull === $selEvent) {
            $eventLabelFull = formatBaseEventName($selEvent);
        }

        $bibNo = formatBibNo($r['reg_id'], !empty($r['custom_bib']) ? $r['custom_bib'] : (!empty($r['bib_no']) ? $r['bib_no'] : null));
        $evtNo = resolveEventCode($selEvent, $r['event_code'] ?? null, $r['match_no'] ?? null);

        $certificates[] = [
            'user_id' => (int)$r['user_id'],
            'reg_id' => $r['reg_id'],
            'bib_no' => $bibNo,
            'event_id' => $evtNo,
            'fullName' => $fullName,
            'clubName' => $clubName,
            'photoSrc' => $photoSrc,
            'rank' => $rank,
            'total' => $totalCompetitors,
            'score' => $r['grand_total_val'],
            'remarks' => $medal,
            'eventLabel' => $eventLabelFull,
            'category' => $selCategory,
            'district' => $r['district'] ?? '—',
            'association' => $r['association'] ?? '—'
        ];
    }

    if (empty($certificates)) {
        die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>body{background:#0d0d0d;color:#fff;font-family:"Inter",sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.box{background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:30px 40px;text-align:center;max-width:450px;box-shadow:0 10px 30px rgba(0,0,0,0.5);}.icon{font-size:48px;margin-bottom:15px;}.title{font-size:18px;font-weight:700;color:#c9a84c;margin-bottom:10px;}.msg{font-size:14px;color:#aaa;line-height:1.5;}</style></head><body><div class="box"><div class="icon">📜</div><div class="title">No Certificate Found</div><div class="msg">No completed score entry found for this competitor/event to generate a certificate.</div></div></body></html>');
    }

} catch (Exception $e) {
    die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>body{background:#0d0d0d;color:#fff;font-family:"Inter",sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.box{background:#1a1a1a;border:1px solid #e74c3c;border-radius:12px;padding:30px 40px;text-align:center;max-width:450px;box-shadow:0 10px 30px rgba(0,0,0,0.5);}.icon{font-size:48px;margin-bottom:15px;}.title{font-size:18px;font-weight:700;color:#e74c3c;margin-bottom:10px;}.msg{font-size:14px;color:#aaa;line-height:1.5;}</style></head><body><div class="box"><div class="icon">⚠️</div><div class="title">Database Error</div><div class="msg">' . htmlspecialchars($e->getMessage()) . '</div></div></body></html>');
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Generate Certificates</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Rajdhani:wght@500;600;700&family=Cinzel:wght@600;700;800&family=Playfair+Display:ital,wght@1,500;1,700&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
  
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
    .cert-wrapper {
        background: #fff;
        color: #000;
        width: 794px; /* A4 width in pixels at 96 DPI */
        height: 1122px; /* A4 height in pixels at 96 DPI */
        padding: 24px;
        box-sizing: border-box;
        margin: 0 auto 30px auto;
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    }
    .cert-border-inner {
        border: 8px double #ADB5BD;
        height: 100%;
        width: 100%;
        box-sizing: border-box;
        padding: 35px 40px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
    }
    .header {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        margin-top: 5px;
    }
    .header-logo {
        width: 80px;
        height: 80px;
        margin-bottom: 8px;
    }
    .header-title {
        font-family: 'Cinzel', serif;
        font-weight: 800;
        font-size: 20px;
        color: #111;
        letter-spacing: 1px;
        margin: 0;
    }
    .cert-meta-row {
        display: flex;
        justify-content: space-between;
        width: 100%;
        font-size: 13px;
        font-weight: 600;
        color: #555;
        margin-top: 25px;
        padding: 0 10px;
    }
    .cert-title-section {
        text-align: center;
        margin-top: 10px;
    }
    .cert-main-title {
        font-family: 'Playfair Display', serif;
        font-size: 55px;
        font-style: italic;
        font-weight: 700;
        color: #111;
        margin: 0;
    }
    .cert-sub-title {
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 3px;
        color: #ADB5BD;
        margin-top: 5px;
        margin-bottom: 15px;
    }
    .cert-body-text {
        text-align: center;
        font-size: 15px;
        line-height: 1.8;
        color: #222;
        padding: 0 15px;
    }
    .highlight-name {
        font-family: 'Playfair Display', serif;
        font-style: italic;
        font-weight: 700;
        font-size: 28px;
        color: #000;
        display: block;
        margin: 8px 0;
    }
    .highlight-bold {
        font-weight: 700;
        color: #000;
    }
    .results-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 25px;
        margin-bottom: 25px;
    }
    .results-table th {
        font-family: 'Rajdhani', sans-serif;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        color: #444;
        border-bottom: 1px solid #ADB5BD;
        padding: 8px;
        text-align: left;
    }
    .results-table td {
        font-size: 13px;
        color: #111;
        padding: 10px 8px;
        border-bottom: 1px solid #eee;
    }
    .results-table tr:last-child td {
        border-bottom: none;
    }
    .footer-section {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 0 10px;
        margin-bottom: 15px;
    }
    .footer-logo {
        width: 70px;
        height: 70px;
        opacity: 0.85;
    }
    .signature-area {
        text-align: center;
    }
    .signature-line {
        border-top: 1px solid #111;
        width: 180px;
        margin-top: 40px;
        padding-top: 8px;
        font-family: 'Inter', sans-serif;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.4;
        color: #111;
    }
    .bottom-disclaimer {
        text-align: center;
        font-size: 9px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-top: 1px solid #eee;
        padding-top: 10px;
        margin-top: 15px;
        font-weight: 500;
    }
    .photo-badge {
        position: absolute;
        top: 220px;
        right: 30px;
        width: 100px;
        height: 120px;
        border: 1px solid #ADB5BD;
        background: #fafafa;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    }
    .photo-badge img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .photo-badge span {
        font-size: 9px;
        color: #aaa;
        text-align: center;
        padding: 5px;
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
        .print-hide {
            display: none !important;
        }
        .cert-wrapper-outer {
            box-shadow: none !important;
            margin: 0 auto !important;
            max-width: 210mm !important;
            page-break-after: always;
            page-break-inside: avoid;
        }
        .cert-wrapper-outer:last-child {
            page-break-after: avoid;
        }
    }
  </style>
</head>
<body>

  <!-- Floating Controls -->
  <?php if (empty($_GET['preview']) && empty($_GET['user_id'])): ?>
  <div style="position: fixed; top: 20px; left: 20px; z-index: 9999; display: flex; gap: 10px;" class="print-hide">
      <button onclick="saveAndUpload()" style="background:#27ae60; color:#fff; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; text-transform:uppercase; border:none; padding:12px 24px; border-radius:6px; cursor:pointer; box-shadow:0 4px 15px rgba(0,0,0,0.3); transition:all 0.2s;">💾 Save &amp; Upload Certificates</button>
      <button onclick="downloadOnly()" style="background:rgba(255,255,255,0.15); color:#fff; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; text-transform:uppercase; border:1px solid rgba(255,255,255,0.3); padding:12px 24px; border-radius:6px; cursor:pointer; box-shadow:0 4px 15px rgba(0,0,0,0.3); transition:all 0.2s;">📥 Download PDFs Only</button>
      <button onclick="window.print()" style="background:#3498db; color:#fff; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; text-transform:uppercase; border:none; padding:12px 24px; border-radius:6px; cursor:pointer; box-shadow:0 4px 15px rgba(0,0,0,0.3); transition:all 0.2s;">🖨️ Print Certificates</button>
      <button onclick="window.close()" style="background:#e74c3c; color:#fff; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:14px; text-transform:uppercase; border:none; padding:12px 24px; border-radius:6px; cursor:pointer; box-shadow:0 4px 15px rgba(0,0,0,0.3); transition:all 0.2s;">Close</button>
  </div>
  <?php endif; ?>

  <div style="margin-top: <?= (empty($_GET['preview']) && empty($_GET['user_id'])) ? '60px' : '0px' ?>;">
    <?php foreach ($certificates as $c): 
      // Fetch all approved results for this competitor
      $userResults = getParticipantApprovedResults($pdo, $c['user_id']);
      $tableData = ['results' => $userResults];

      $vars = [
          'participant_name' => $c['fullName'],
          'reg_id'           => $c['bib_no'], // Map reg_id to the 5-digit bib number so no SSA- number is ever printed
          'bib_no'           => $c['bib_no'],
          'event_id'         => $c['event_id'],
          'event_code'       => $c['event_id'],
          'event_name'       => $c['eventLabel'],
          'category'         => $c['category'],
          'score'            => $c['score'] ?: '—',
          'rank'             => $c['rank'] ?: '—',
          'date'             => date('Y-m-d'),
          'cert_no'          => 'CERT-' . $c['bib_no'] . '-' . $c['user_id'],
          'club_name'        => $c['clubName'],
          'district'         => $c['district'],
          'association'      => $c['association']
      ];
      $photoPath = $c['photoSrc'] ? $c['photoSrc'] : '';
    ?>
      <div class="cert-wrapper-outer" id="cert-<?= $c['user_id'] ?>" style="margin: 0 auto 30px auto; width: max-content; display: block; background: #fff; color: #000; box-shadow: 0 4px 20px rgba(0,0,0,0.5);">
          <?= getDynamicDocumentHTML('certificate', $vars, $photoPath, $tableData) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
    const certs = <?= json_encode($certificates) ?>;
    const eventName = <?= json_encode($selEvent) ?>;
    const category = <?= json_encode($selCategory) ?>;
    
    function getExportOptions(certEl, filename) {
        const canvasEl = certEl.querySelector('.document-canvas-render');
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

    async function saveAndUpload() {
        Swal.fire({
            title: 'Uploading Certificates',
            text: 'Processing... please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        let successCount = 0;
        for (const c of certs) {
            const certEl = document.getElementById('cert-' + c.user_id);
            if (!certEl) continue;
            
            const opt = getExportOptions(certEl, 'certificate_' + c.reg_id + '.pdf');
            
            try {
                const pdfBlob = await html2pdf().from(certEl).set(opt).outputPdf('blob');
                
                const fd = new FormData();
                fd.append('cert_pdf', pdfBlob, 'certificate_' + c.user_id + '.pdf');
                fd.append('user_id', c.user_id);
                fd.append('event_name', eventName);
                fd.append('category', category);
                
                const res = await fetch('actions/upload_certificate.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    successCount++;
                }
            } catch (err) {
                console.error("Error generating certificate for user " + c.user_id + ":", err);
            }
        }
        
        Swal.fire({
            title: 'Generated Successfully!',
            text: successCount + ' certificate(s) have been generated, uploaded, and linked to competitor login(s).',
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
        
        for (const c of certs) {
            const certEl = document.getElementById('cert-' + c.user_id);
            if (!certEl) continue;
            
            const opt = getExportOptions(certEl, 'certificate_' + c.reg_id + '.pdf');
            
            try {
                await html2pdf().from(certEl).set(opt).save();
            } catch (err) {
                console.error("Error downloading certificate for user " + c.user_id + ":", err);
            }
        }
        
        Swal.close();
    }

    // Automatic download trigger for direct on-the-fly downloads
    window.addEventListener('DOMContentLoaded', async () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('download')) {
            if (certs.length > 0) {
                const c = certs[0];
                const certEl = document.getElementById('cert-' + c.user_id);
                if (certEl) {
                    const opt = getExportOptions(certEl, 'certificate_' + c.reg_id + '.pdf');
                    try {
                        await html2pdf().from(certEl).set(opt).save();
                        // Wait a second for download to begin, then close the tab
                        setTimeout(() => { window.close(); }, 1500);
                    } catch (e) {
                        console.error(e);
                        window.close();
                    }
                } else {
                    window.close();
                }
            } else {
                window.close();
            }
        }
    });
  </script>
</body>
</html>
