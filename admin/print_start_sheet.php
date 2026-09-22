<?php
// admin/print_start_sheet.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
require_once __DIR__ . '/includes/auth.php';
checkAdminAuth();

$pdo = getDB();

$selBaseType = trim($_GET['base_type'] ?? '');
$selDate     = trim($_GET['date'] ?? '');
$selRelayNo  = (int)($_GET['relay_no'] ?? 0);

$where = ["er.event_name IS NOT NULL AND er.event_name != ''"];
$params = [];

if (!empty($selBaseType)) {
    $allEvs = $pdo->query("SELECT DISTINCT event_name, category FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC);
    $matchedEvs = [];
    foreach ($allEvs as $evItem) {
        $evtName = $evItem['event_name'];
        $cat = strtolower($evItem['category'] ?? 'nr');
        if ($cat === 'nr_mqs') $cat = 'nr';
        
        $fullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
        $base = getBackendEventBaseType($fullName);
        if (empty($base)) {
            $cleanName = preg_replace('/\s*\([^)]*\)/', '', $fullName);
            $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2)\b/i', '', $cleanName);
            $base = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($cleanName)));
            $base = preg_replace('/_+/', '_', $base);
            $base = trim($base, '_');
        }
        $base = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $base);
        $key = $base . '_' . $cat;
        
        if ($key === $selBaseType || $evtName === $selBaseType) {
            $matchedEvs[] = $evtName;
        }
    }
    if (!empty($matchedEvs)) {
        $inClause = implode(',', array_fill(0, count($matchedEvs), '?'));
        $where[] = "er.event_name IN ($inClause)";
        $params = array_merge($params, $matchedEvs);
    }
}

if ($selDate !== '') {
    $where[] = "la.scheduled_date = ?";
    $params[] = $selDate;
}

if ($selRelayNo > 0) {
    $where[] = "la.relay_no = ?";
    $params[] = $selRelayNo;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT la.*, 
           r.reg_id, 
           r.club_name, 
           r.district,
           er.event_reg_id AS enrollment_id, 
           er.event_name,
           er.category,
           r.first_name, 
           r.last_name
    FROM lane_allocations la
    JOIN event_registrations er ON la.event_reg_id = er.id
    LEFT JOIN registrations r ON er.user_id = r.id
    WHERE $whereSql
    ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.start_time ASC, CASE WHEN la.lane_no > 0 THEN la.lane_no ELSE 99999 END ASC, la.id ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($allocations as $row) {
    $evtName = $row['event_name'] ?? 'General Event';
    $fullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
    $sDate = !empty($row['scheduled_date']) ? date('d/m/Y', strtotime($row['scheduled_date'])) : 'Unscheduled';
    $grouped[$fullName][$sDate][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Start Sheet - Print</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: #181920;
            color: #ffffff;
            padding: 20px;
        }
        .no-print-bar {
            width: 100%;
            max-width: 850px;
            margin: 0 auto 20px auto;
            background: #232634;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }
        .btn-print {
            background: #d4af37;
            color: #000000;
            font-weight: 700;
            border: none;
            padding: 10px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-transform: uppercase;
        }
        .btn-close {
            background: #dc2626;
            color: #ffffff;
            font-weight: 700;
            border: none;
            padding: 10px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-transform: uppercase;
        }
        .print-container {
            width: 100%;
            max-width: 850px;
            margin: 0 auto;
        }
        .sheet-card {
            background: #ffffff;
            color: #000000;
            padding: 35px 40px;
            margin-bottom: 30px;
            border-radius: 4px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.5);
            page-break-after: always;
        }
        .sheet-card:last-child {
            page-break-after: avoid;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .header-title {
            text-align: center;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 1px;
            font-family: 'Georgia', serif;
            text-transform: uppercase;
            color: #000000;
        }
        .event-title {
            text-align: center;
            font-size: 16px;
            font-weight: 600;
            margin-top: 5px;
            margin-bottom: 15px;
            border-bottom: 2px solid #000000;
            padding-bottom: 8px;
            color: #000000;
        }
        .date-header {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            color: #000000;
        }
        .data-table {
            width: 100%;
            table-layout: auto;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 20px;
            color: #000000;
        }
        .data-table th, .data-table td {
            border: 1px solid #000000;
            padding: 6px 8px;
            text-align: center;
            color: #000000;
            vertical-align: middle;
        }
        .data-table th {
            background: #f0f0f0;
            font-weight: 700;
            text-transform: uppercase;
        }
        .col-compact, th.col-compact, td.col-compact {
            width: 1%;
            white-space: nowrap;
        }
        .name-cell, .club-cell, th.name-cell, td.name-cell, th.club-cell, td.club-cell {
            text-align: left !important;
            width: auto;
            white-space: normal;
            word-break: normal;
        }
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .sheet-card {
                box-shadow: none !important;
                padding: 10px !important;
                margin-bottom: 0 !important;
                border: none !important;
            }
            .data-table th, .data-table td {
                border: 1px solid #000000 !important;
            }
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <span style="font-weight:700; font-size:15px; color:#ffffff;">🖨️ MASTER START SHEET</span>
        <div style="display:flex; gap:10px;">
            <button class="btn-print" onclick="window.print()">🖨️ Print Now</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>

    <div class="print-container">
        <?php if (empty($grouped)): ?>
            <div class="sheet-card" style="text-align:center; padding:50px;">
                <h2>No Lane Allocations Found</h2>
                <p style="margin-top:10px; color:#666;">There are no start list records matching your filter selection.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $evtTitle => $dateGroup): ?>
                <?php foreach ($dateGroup as $dateStr => $rows): ?>
                    <div class="sheet-card">
                        <table class="header-table">
                            <tr>
                                <td style="width:110px; text-align:left; vertical-align:middle;">
                                    <img src="../images/logo.png" style="width:70px; height:auto;" onerror="this.style.display='none'">
                                </td>
                                <td style="text-align:center; vertical-align:middle;">
                                    <div class="header-title">MASTER START SHEET</div>
                                    <div class="event-title"><?= htmlspecialchars($evtTitle) ?></div>
                                </td>
                                <td style="width:110px;"></td>
                            </tr>
                        </table>

                        <div class="date-header">CHAMPIONSHIP DATE: <?= htmlspecialchars($dateStr) ?></div>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="col-compact">St time</th>
                                    <th class="col-compact">Rly</th>
                                    <th class="col-compact">FP</th>
                                    <th class="col-compact">bib no / enroll ID</th>
                                    <th class="name-cell">Name</th>
                                    <th class="club-cell">Club Name</th>
                                    <th class="col-compact">Target Serial No</th>
                                    <th class="col-compact">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $bib = !empty($r['bib_no']) ? formatBibNo($r['bib_no'], $r['bib_no'], $r['first_name'] ?? '') : (!empty($r['reg_id']) ? formatBibNo($r['reg_id'], '', $r['first_name'] ?? '') : '—');
                                    $name = !empty($r['custom_name']) ? $r['custom_name'] : formatFullName($r['first_name'] ?? '', $r['last_name'] ?? '');
                                    if (empty($name)) $name = '—';
                                    $club = !empty(trim((string)($r['club_name'] ?? ''))) ? trim($r['club_name']) : (!empty(trim((string)($r['district'] ?? ''))) ? trim($r['district']) : 'T.N.');
                                    if ($club === '—' || $club === '-') $club = 'T.N.';
                                    $stTime = !empty($r['start_time']) ? date('H:i:s', strtotime($r['start_time'])) : '09:00:00';
                                    $targetSerial = !empty($r['target_serial_no']) ? $r['target_serial_no'] : '—';
                                    $statusVal = !empty($r['score_remarks']) ? ($r['score_remarks'] === 'Completed' ? 'C' : $r['score_remarks']) : (!empty($r['grand_total_val']) ? 'C' : '—');
                                ?>
                                    <tr>
                                        <td class="col-compact"><?= htmlspecialchars($stTime) ?></td>
                                        <td class="col-compact"><?= htmlspecialchars((string)($r['relay_no'] ?? '1')) ?></td>
                                        <td class="col-compact"><?= htmlspecialchars(($r['lane_no'] > 0 ? (string)$r['lane_no'] : '—')) ?></td>
                                        <td class="col-compact"><?= htmlspecialchars($bib) ?></td>
                                        <td class="name-cell"><strong><?= htmlspecialchars(strtoupper($name)) ?></strong></td>
                                        <td class="club-cell"><?= htmlspecialchars(strtoupper($club)) ?></td>
                                        <td class="col-compact"><?= htmlspecialchars($targetSerial) ?></td>
                                        <td class="col-compact" style="font-weight:700;"><?= htmlspecialchars($statusVal) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 300);
        });
    </script>
</body>
</html>
