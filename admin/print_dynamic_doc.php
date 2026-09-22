<?php
// admin/print_dynamic_doc.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/document_renderer.php';
require_once __DIR__ . '/includes/auth.php';
checkAdminAuth();

if (!function_exists('getBase64Image')) {
    function getBase64Image(string $filePath): string {
        if (!file_exists($filePath)) {
            return '';
        }
        $mimeType = mime_content_type($filePath);
        if (!$mimeType) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = match($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
        }
        $data = file_get_contents($filePath);
        return 'data:' . $mimeType . ';base64,' . base64_encode($data);
    }
}

$docType = trim($_GET['document_type'] ?? '');
if (!in_array($docType, ['start_sheet', 'score_sheet', 'rank_list', 'print_summary'])) {
    die("Invalid document type requested.");
}

$pdo = getDB();
$variables = [];
$tableData = ['headers' => [], 'rows' => []];

try {
    if ($docType === 'start_sheet') {
        require_once dirname(__DIR__) . '/config/events.php';
        $selBaseType = trim($_GET['base_type'] ?? '');
        $selEvents = [];
        if (!empty($_GET['events'])) {
            $decoded = json_decode($_GET['events'], true);
            if (is_array($decoded)) {
                $selEvents = array_filter(array_map('trim', $decoded));
            }
        }
        if ($selBaseType !== '') {
            if (!in_array($selBaseType, $selEvents, true)) {
                $selEvents[] = $selBaseType;
            }
        } elseif (!empty($selEvents)) {
            $selBaseType = $selEvents[0];
        }
        
        // Resolve base_type from old parameters if passed
        if ($selBaseType === '' && !empty($_GET['event_name'])) {
            $evt = trim($_GET['event_name']);
            $cat = strtolower(trim($_GET['category'] ?? ''));
            if ($cat === 'nr_mqs') $cat = 'nr';
            if ($cat === 'para_deaf') {
                $fullName = $EVENTS_MAPPING[$evt] ?? $evt;
                if (strpos(strtoupper($fullName), '(ISSF)') !== false) {
                    $cat = 'issf';
                } else {
                    $cat = 'nr';
                }
            }
            $fullName = $EVENTS_MAPPING[$evt] ?? $evt;
            $base = getBackendEventBaseType($fullName);
            if (empty($base)) {
                $cleanName = preg_replace('/\s*\([^)]*\)/', '', $fullName);
                $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2)\b/i', '', $cleanName);
                $base = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($cleanName)));
                $base = preg_replace('/_+/', '_', $base);
                $base = trim($base, '_');
            }
            $base = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $base);
            $selBaseType = $base . '_' . $cat;
            if (!in_array($selBaseType, $selEvents, true)) {
                $selEvents[] = $selBaseType;
            }
        }

        $baseGroups = [];
        $addEventToBaseGroups = function(string $evt, string $cat = '') use (&$baseGroups, $EVENTS_MAPPING) {
            if (empty($evt)) return;
            $evtUpper = strtoupper($evt);
            $fullName = $EVENTS_MAPPING[$evt] ?? $evt;
            $nameUpper = strtoupper($fullName);

            $is10mPistolParaDeaf = (strpos($nameUpper, '10M AIR PISTOL') !== false || strpos($nameUpper, '10M PISTOL') !== false) && 
                (strpos($nameUpper, 'SH1') !== false || strpos($nameUpper, 'DEAF') !== false || strpos($nameUpper, 'PARA') !== false || in_array($evtUpper, ['DS12', 'DS13', 'R021', 'R022', 'R023', 'R024', 'R025', 'R026', 'R031', 'R032'], true));

            if ($is10mPistolParaDeaf || strpos($evtUpper, 'IS-') === 0 || strpos($evtUpper, 'IS') === 0 || strpos($nameUpper, '(ISSF)') !== false || strpos($nameUpper, 'ISSF') !== false) {
                $cat = 'issf';
            } elseif (strpos($evtUpper, 'NR-') === 0 || strpos($evtUpper, 'NR') === 0 || strpos($nameUpper, '(NR)') !== false || strpos($nameUpper, 'NATIONAL RULE') !== false) {
                $cat = 'nr';
            } else {
                $cat = strtolower($cat ?: 'nr');
                if ($cat === 'nr_mqs') $cat = 'nr';
            }

            $baseType = getBackendEventBaseType($fullName);
            if (empty($baseType) || $is10mPistolParaDeaf) {
                $baseType = $is10mPistolParaDeaf ? '10m_air_pistol_issf' : '';
            }
            if (empty($baseType)) {
                $cleanName = preg_replace('/\s*\([^)]*\)/', '', $fullName);
                $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2)\b/i', '', $cleanName);
                $baseType = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($cleanName)));
                $baseType = preg_replace('/_+/', '_', $baseType);
                $baseType = trim($baseType, '_');
            }
            $baseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $baseType);
            $baseType = $baseType . '_' . $cat;

            if (!isset($baseGroups[$baseType])) {
                $baseGroups[$baseType] = [];
            }
            if (!in_array($evt, $baseGroups[$baseType], true)) {
                $baseGroups[$baseType][] = $evt;
            }
        };

        $allocEvents = $pdo->query("
            SELECT DISTINCT er.event_name, er.category 
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            WHERE er.event_name IS NOT NULL AND er.event_name != ''
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allocEvents as $item) {
            $addEventToBaseGroups($item['event_name'], $item['category'] ?? 'nr');
        }

        $eventRegsWithCat = $pdo->query("
            SELECT DISTINCT event_name, category 
            FROM event_registrations 
            WHERE event_name IS NOT NULL AND event_name != ''
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eventRegsWithCat as $item) {
            $addEventToBaseGroups($item['event_name'], $item['category'] ?? 'nr');
        }

        if (empty($baseGroups)) {
            foreach ($EVENTS_MAPPING as $code => $fullName) {
                $cat = (strpos(strtoupper($fullName), '(ISSF)') !== false || strpos($code, 'IS-') === 0 || strpos($code, 'IS') === 0) ? 'issf' : 'nr';
                $addEventToBaseGroups($code, $cat);
            }
        }

        $allSelectedEventIds = [];
        if (!empty($selEvents)) {
            foreach ($selEvents as $evtKey) {
                if (isset($baseGroups[$evtKey])) {
                    $allSelectedEventIds = array_merge($allSelectedEventIds, $baseGroups[$evtKey]);
                } else {
                    $baseKey = preg_replace('/_(issf|nr)$/i', '', $evtKey);
                    $baseKeyClean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($baseKey));

                    foreach ($EVENTS_MAPPING as $id => $fullName) {
                        $evtBase = getBackendEventBaseType($fullName);
                        $evtBaseClean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(preg_replace('/_(issf|nr)$/i', '', $evtBase)));
                        
                        if ($evtBase === $evtKey || $evtBaseClean === $baseKeyClean || strcasecmp($id, $evtKey) === 0) {
                            $allSelectedEventIds[] = $id;
                        }
                    }
                    $allSelectedEventIds[] = $evtKey;
                }
            }
            $allSelectedEventIds = array_values(array_unique(array_filter($allSelectedEventIds)));
        }

        $whereClause = " 1=1 ";
        $queryParams = [];

        if (!empty($allSelectedEventIds)) {
            $inClause = implode(',', array_fill(0, count($allSelectedEventIds), '?'));
            $whereClause = " er.event_name IN ($inClause) ";
            $queryParams = $allSelectedEventIds;
        }

        $selRelayNo = (int)($_GET['relay_no'] ?? 0);
        if ($selRelayNo > 0) {
            $whereClause .= " AND la.relay_no = ? ";
            $queryParams[] = $selRelayNo;
        }

        $selDate = trim($_GET['date'] ?? '');
        if ($selDate !== '') {
            $whereClause .= " AND la.scheduled_date = ? ";
            $queryParams[] = $selDate;
        }

        $query = "
            SELECT la.*, r.reg_id, r.club_name, er.event_reg_id AS enrollment_id, r.first_name, r.last_name
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            JOIN registrations r ON er.user_id = r.id
            WHERE $whereClause
            ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.start_time ASC, CASE WHEN la.lane_no > 0 THEN la.lane_no ELSE 99999 END ASC, la.id ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($queryParams);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $regNameMap = [];
        $rawRegsForMap = $pdo->query("SELECT reg_id, first_name, last_name FROM registrations WHERE reg_id IS NOT NULL AND reg_id != ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawRegsForMap as $rr) {
            $fBib = formatBibNo($rr['reg_id'], $rr['reg_id'], $rr['first_name']);
            $fName = formatFullName($rr['first_name'], $rr['last_name']);
            if (!empty($fName)) {
                if (!empty($fBib)) $regNameMap[strtoupper($fBib)] = $fName;
                if (!empty($rr['reg_id'])) $regNameMap[strtoupper($rr['reg_id'])] = $fName;
            }
        }

        foreach ($allocations as &$a) {
            $rowBib = '';
            if (!empty($a['bib_no'])) {
                $rowBib = formatBibNo($a['bib_no'], $a['bib_no'], $a['first_name'] ?? null);
            } elseif (!empty($a['reg_id']) && strpos(strtoupper($a['reg_id']), 'MANUAL-') !== 0) {
                $rowBib = formatBibNo($a['reg_id'], $a['reg_id'], $a['first_name'] ?? null);
            }

            $rowFullName = '';
            if (!empty(trim((string)($a['custom_name'] ?? '')))) {
                $rowFullName = trim($a['custom_name']);
            } else {
                $fullNameFromReg = formatFullName($a['first_name'] ?? '', $a['last_name'] ?? '');
                if (!empty($fullNameFromReg)) {
                    $rowFullName = $fullNameFromReg;
                } elseif (!empty($rowBib) && isset($regNameMap[strtoupper($rowBib)])) {
                    $rowFullName = $regNameMap[strtoupper($rowBib)];
                }
            }

            $a['enrollment_id'] = $rowBib;
            $a['bib_no'] = $rowBib;
            $a['first_name'] = $rowFullName;
            $a['last_name'] = '';
            if (empty($a['club_name'])) {
                $a['club_name'] = '-';
            }
        }
        unset($a);

        $names = [];
        if (!empty($selEvents)) {
            foreach ($selEvents as $evtKey) {
                $names[] = formatBaseEventName($evtKey);
            }
            $fullEventName = implode(' & ', $names);
        } else {
            $fullEventName = 'ALL EVENTS START LIST';
        }
        $category = (!empty($selBaseType) && substr($selBaseType, -5) === '_issf') ? 'ISSF' : 'NR';

        // Find distinct relays and dates
        $relays = [];
        $dates = [];
        foreach ($allocations as $a) {
            if (!empty($a['relay_no'])) {
                $relays[] = $a['relay_no'];
            }
            if (!empty($a['scheduled_date'])) {
                $dates[] = date('d/m/Y', strtotime($a['scheduled_date']));
            }
        }
        $relays = array_unique($relays);
        sort($relays);
        $relayStr = implode(', ', $relays);

        $dates = array_unique($dates);
        sort($dates);
        $dateStr = implode(', ', $dates);

        // Format time in 24 Hrs format (e.g. 17:52)
        $formattedTime = date('H:i');

        $variables = [
            'event_name' => $fullEventName,
            'category'   => $category,
            'date'       => $dateStr ?: date('d/m/Y'),
            'time'       => $formattedTime,
            'relay'      => $relayStr
        ];

        $tableData = [
            'headers' => ['St time', 'Rly', 'FP', 'bib no / enroll ID', 'Name', 'Club Name', 'Target Serial No'],
            'results' => $allocations
        ];
        $dynamicHTML = getDynamicDocumentHTML('start_sheet', $variables, '', $tableData);
    } elseif ($docType === 'rank_list') {
        $eventName = trim($_GET['event_name'] ?? '');
        $category  = trim($_GET['category'] ?? '');
        $rankType  = trim($_GET['rank_type'] ?? 'overall');
        $rankMode  = trim($_GET['rank_mode'] ?? 'individual');
        $selDate   = trim($_GET['date'] ?? '');
        $selRelay  = (int)($_GET['relay'] ?? 0);

        if ($rankMode === 'team') {
            if (str_contains($eventName, '||')) {
                [$parsedEvent, $parsedCat] = explode('||', $eventName, 2);
                if (empty($category)) $category = $parsedCat;
                $eventName = $parsedEvent;
            }

            $teamWhere = [];
            $teamParams = [];
            if (!empty($eventName)) {
                $teamWhere[] = "(t.event_name = ? OR t.event_name LIKE ?)";
                $teamParams[] = $eventName;
                $teamParams[] = '%' . $eventName . '%';
            }
            if (!empty($category)) {
                $teamWhere[] = "(UPPER(t.category) = ? OR t.category IS NULL OR t.category = '')";
                $teamParams[] = strtoupper($category);
            }

            $sqlTeamWhere = !empty($teamWhere) ? ("WHERE " . implode(" AND ", $teamWhere)) : "";
            $tStmt = $pdo->prepare("
                SELECT
                    t.id        AS team_id,
                    t.team_name,
                    t.event_name,
                    t.category,
                    tm.enrollment_id,
                    tm.shooter_name,
                    tm.club_name,
                    tm.score    AS stored_score,
                    tm.lane_alloc_id
                FROM teams t
                JOIN team_members tm ON tm.team_id = t.id
                $sqlTeamWhere
                ORDER BY t.id ASC, tm.id ASC
            ");
            $tStmt->execute($teamParams);
            $tRows = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($tRows as $tr) {
                $tid = (int)$tr['team_id'];
                if (!isset($grouped[$tid])) {
                    $grouped[$tid] = [
                        'team_name'   => $tr['team_name'],
                        'members'     => [],
                        'total_score' => 0.0,
                    ];
                }

                $scoreInfo = resolveTeamMemberScore($pdo, (string)$tr['enrollment_id'], $eventName, $category, !empty($tr['lane_alloc_id']) ? (int)$tr['lane_alloc_id'] : null, (string)$tr['shooter_name']);
                $liveScore = $scoreInfo['score'];
                if ($liveScore <= 0 && !empty($tr['stored_score'])) {
                    $liveScore = (float)$tr['stored_score'];
                }
                $displayId = formatBibNo($tr['enrollment_id']);

                $grouped[$tid]['members'][] = [
                    'enrollment_id' => $displayId,
                    'shooter_name'  => $tr['shooter_name'],
                    'club_name'     => $tr['club_name'],
                    'score'         => $liveScore,
                ];
                $grouped[$tid]['total_score'] += $liveScore;
            }

            usort($grouped, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
            foreach ($grouped as $idx => &$team) {
                $team['rank'] = $idx + 1;
            }
            unset($team);

            $fullEventName = formatBaseEventName($eventName);
            $logoBase64 = getBase64Image(dirname(__DIR__) . '/images/logo.png');
            $dateStr = date('d/m/Y');
            $timeStr = date('H:i:s');

            ob_start();
            ?>
            <div class="document-canvas-render" style="width: 210mm; min-height: 297mm; padding: 12mm 15mm; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; box-sizing: border-box; margin: 0 auto; position: relative;">
              <!-- Header -->
              <div style="display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 12px;">
                <div style="width: 75px; flex-shrink: 0;">
                  <?php if (!empty($logoBase64)): ?>
                    <img src="<?= $logoBase64 ?>" style="width: 70px; height: 70px; object-fit: contain;" />
                  <?php endif; ?>
                </div>
                <div style="flex: 1; text-align: center; padding: 0 10px;">
                  <div style="font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #000; line-height: 1.3;">
                    51ST TAMILNADU STATE SHOOTING CHAMPIONSHIP 2026
                  </div>
                  <div style="font-size: 12.5px; font-weight: 700; color: #222; margin-top: 3px;">
                    CHENNAI TAMILNADU
                  </div>
                  <div style="font-size: 11.5px; font-weight: 700; color: #444; margin-top: 2px;">
                    23-07-2026 - 28-07-2026
                  </div>
                </div>
                <div style="width: 75px; flex-shrink: 0;"></div>
              </div>

              <!-- Result Sub-header -->
              <div style="text-align: center; margin-bottom: 16px;">
                <div style="font-size: 13.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #000;">
                  TEAM QUALIFICATION RESULT
                </div>
                <div style="font-size: 12.5px; font-weight: 800; margin-top: 5px; text-transform: uppercase; color: #111;">
                  <?= htmlspecialchars($fullEventName) ?> <?= !empty($category) ? '(' . htmlspecialchars($category) . ')' : '' ?>
                </div>
                <div style="font-size: 11px; font-weight: 600; color: #333; margin-top: 4px;">
                  Date : <?= $dateStr ?> &nbsp;&nbsp;&nbsp;&nbsp; Time : <?= $timeStr ?>
                </div>
              </div>

              <!-- Team Table -->
              <table style="width: 100%; border-collapse: collapse; font-size: 11px; color: #000; font-family: Arial, Helvetica, sans-serif;">
                <thead>
                  <tr style="border-top: 1.5px solid #000; border-bottom: 1.5px solid #000;">
                    <th style="padding: 6px 4px; text-align: center; font-weight: 700; width: 40px;">Rank</th>
                    <th style="padding: 6px 8px; text-align: left; font-weight: 700; width: 180px;">Team Name</th>
                    <th style="padding: 6px 8px; text-align: left; font-weight: 700;">Members</th>
                    <th style="padding: 6px 8px; text-align: right; font-weight: 700; width: 90px;">Total Score</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($grouped)): ?>
                  <tr>
                    <td colspan="4" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                      No team entries found for this event.
                    </td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($grouped as $team): ?>
                    <tr style="border-bottom: 1px solid #ccc; vertical-align: top;">
                      <td style="padding: 8px 4px; text-align: center; font-weight: 800; font-size: 12px;"><?= $team['rank'] ?></td>
                      <td style="padding: 8px 8px; text-align: left;">
                        <div style="font-weight: 800; font-size: 12px; text-transform: uppercase; color: #000;"><?= htmlspecialchars($team['team_name']) ?></div>
                      </td>
                      <td style="padding: 6px 8px; text-align: left;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
                          <?php foreach ($team['members'] as $m): ?>
                          <tr>
                            <td style="padding: 2px 4px; font-weight: 700; width: 140px;"><?= htmlspecialchars($m['shooter_name']) ?></td>
                            <td style="padding: 2px 4px; font-family: monospace; font-size: 10px; color: #333; width: 70px;"><?= htmlspecialchars($m['enrollment_id']) ?></td>
                            <td style="padding: 2px 4px; color: #555;"><?= htmlspecialchars($m['club_name']) ?></td>
                            <td style="padding: 2px 4px; text-align: right; font-weight: 700; width: 60px;"><?= number_format($m['score'], 2) ?></td>
                          </tr>
                          <?php endforeach; ?>
                        </table>
                      </td>
                      <td style="padding: 8px 8px; text-align: right; font-weight: 800; font-size: 13px; color: #000;">
                        <?= number_format($team['total_score'], 2) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>

              <!-- Footer Legend & Signature Area -->
              <div style="margin-top: 25px; border-top: 1px solid #000; padding-top: 10px; font-size: 10px; font-weight: 700; color: #000;">
                <div>Protest Time : 30 Minutes</div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 35px;">
                  <div>
                    <div style="border-bottom: 1px solid #000; width: 220px; height: 1px; margin-bottom: 4px;"></div>
                    <div style="font-weight: 700; font-size: 10.5px; color: #000;"><?= date('d/m/y') ?> &nbsp;&nbsp;&nbsp; <?= date('H:i') ?> Hrs</div>
                  </div>
                  <div style="font-size: 10.5px; font-weight: 700; color: #000;">Page 1 of 1</div>
                </div>
              </div>
            </div>
            <?php
            $rankListCustomHTML = ob_get_clean();
        } else {

        $whereClauses = ["er.status = 'approved'"];
        $queryParams  = [];

        if (!empty($eventName)) {
            $whereClauses[] = "er.event_name = ?";
            $queryParams[]  = $eventName;
        }
        if (!empty($category)) {
            $whereClauses[] = "er.category = ?";
            $queryParams[]  = $category;
        }

        $sqlWhere = implode(" AND ", $whereClauses);

        $sql = "
            SELECT
                er.id AS event_reg_id,
                er.event_reg_id AS reg_id,
                er.event_name,
                er.event_code,
                er.match_no,
                er.category,
                r.id AS user_id,
                r.first_name,
                r.last_name,
                r.club_name,
                r.district,
                r.association,
                la_direct.id AS alloc_id,
                la_direct.bib_no,
                la_direct.scheduled_date,
                la_direct.lane_no,
                la_direct.relay_no,
                la_direct.start_time,
                ss_direct.grand_total_val AS direct_grand_total,
                ss_direct.total_val AS direct_total_val,
                ss_direct.penalty_val AS direct_penalty_val,
                ss_direct.series_data AS direct_series_data,
                ss_direct.remarks AS direct_remarks
            FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN lane_allocations la_direct ON la_direct.event_reg_id = er.id
            LEFT JOIN score_sheets ss_direct ON ss_direct.lane_alloc_id = la_direct.id
            WHERE $sqlWhere
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($queryParams);
        $rawRegRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        global $EVENTS_MAPPING;
        $scores = [];

        foreach ($rawRegRows as $row) {
            $uId = (int)$row['user_id'];
            $eName = $row['event_name'];
            $cat = $row['category'];

            $grandTotalVal = $row['direct_grand_total'] ?? null;
            $totalVal = $row['direct_total_val'] ?? null;
            $penaltyVal = $row['direct_penalty_val'] ?? null;
            $seriesData = $row['direct_series_data'] ?? null;
            $remarks = $row['direct_remarks'] ?? null;
            $schedDate = $row['scheduled_date'] ?? null;
            $relayNo = $row['relay_no'] ?? null;

            if (empty($grandTotalVal)) {
                $evtFullName = $EVENTS_MAPPING[$eName] ?? $eName;
                $evtBaseType = getBackendEventBaseType($evtFullName);
                $evtCatFamily = (strpos(strtoupper($cat), 'ISSF') !== false || strpos(strtoupper($evtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                $fallbackStmt = $pdo->prepare("
                    SELECT la_f.scheduled_date, la_f.relay_no, la_f.lane_no, la_f.start_time, la_f.bib_no,
                           ss_f.grand_total_val, ss_f.total_val, ss_f.penalty_val, ss_f.series_data, ss_f.remarks,
                           er_f.event_name, er_f.category
                    FROM lane_allocations la_f
                    JOIN event_registrations er_f ON la_f.event_reg_id = er_f.id
                    JOIN score_sheets ss_f ON ss_f.lane_alloc_id = la_f.id
                    WHERE er_f.user_id = ? AND ss_f.grand_total_val IS NOT NULL AND ss_f.grand_total_val != ''
                    ORDER BY ss_f.id DESC
                ");
                $fallbackStmt->execute([$uId]);
                $userScores = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($userScores as $us) {
                    $saFullName = $EVENTS_MAPPING[$us['event_name']] ?? $us['event_name'];
                    $saBaseType = getBackendEventBaseType($saFullName);
                    $saCatFamily = (strpos(strtoupper($us['category']), 'ISSF') !== false || strpos(strtoupper($saFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                    if ($evtCatFamily === $saCatFamily && $evtBaseType === $saBaseType) {
                        $grandTotalVal = $us['grand_total_val'];
                        $totalVal = $us['total_val'];
                        $penaltyVal = $us['penalty_val'];
                        $seriesData = $us['series_data'];
                        $remarks = $us['remarks'];
                        $schedDate = $us['scheduled_date'];
                        $relayNo = $us['relay_no'];
                        if (empty($row['bib_no'])) $row['bib_no'] = $us['bib_no'];
                        if (empty($row['lane_no'])) $row['lane_no'] = $us['lane_no'];
                        if (empty($row['start_time'])) $row['start_time'] = $us['start_time'];
                        break;
                    }
                }
            }

            if (empty($grandTotalVal)) {
                continue;
            }

            if ($rankType === 'preliminary') {
                if (!empty($selDate) && $schedDate !== $selDate) continue;
                if ($selRelay > 0 && (int)$relayNo !== $selRelay) continue;
            }

            $row['grand_total_val'] = $grandTotalVal;
            $row['total_val'] = $totalVal;
            $row['penalty_val'] = $penaltyVal;
            $row['series_data'] = $seriesData;
            $row['remarks'] = $remarks;
            $row['scheduled_date'] = $schedDate;
            $row['relay_no'] = $relayNo;

            $scores[] = $row;
        }

        if ($rankType === 'preliminary') {
            usort($scores, function($a, $b) {
                $d = strcmp((string)($a['scheduled_date'] ?? ''), (string)($b['scheduled_date'] ?? ''));
                if ($d !== 0) return $d;
                $r = ((int)($a['relay_no'] ?? 0)) <=> ((int)($b['relay_no'] ?? 0));
                if ($r !== 0) return $r;
                $l1 = ((int)($a['lane_no'] ?? 0) > 0) ? (int)$a['lane_no'] : 99999;
                $l2 = ((int)($b['lane_no'] ?? 0) > 0) ? (int)$b['lane_no'] : 99999;
                return $l1 <=> $l2;
            });
        } else {
            usort($scores, 'compareShooterScores');
        }

        // Deduplicate for overall rank (keep the row with the best score per competitor)
        if ($rankType === 'overall') {
            $seen = [];
            $deduped = [];
            foreach ($scores as $row) {
                $ident = $row['reg_id'];
                if (!isset($seen[$ident])) {
                    $seen[$ident] = true;
                    $deduped[] = $row;
                }
            }
            $scores = $deduped;
        }



        $fullEventName = formatBaseEventName($eventName);
        $logoBase64 = getBase64Image(dirname(__DIR__) . '/images/logo.png');
        $dateStr = $selDate ? date('d/m/Y', strtotime($selDate)) : date('d/m/Y');
        $timeStr = date('H:i:s');
        $titleSub = ($rankType === 'preliminary' && $selRelay > 0) ? 'RELAYWISE QUALIFICATION RESULT' : 'QUALIFICATION RESULT';

        // Detect Centre Fire Pistol (has 12 series: 6 Precision + 6 Duelling)
        $isCFP = (
            stripos($fullEventName, 'centre fire pistol') !== false ||
            stripos($fullEventName, 'center fire pistol') !== false
        );

        // Parse series totals helper
        $getSeriesVals = function($seriesJson) use ($isCFP) {
            $decoded = [];
            if (!empty($seriesJson)) {
                $decoded = is_array($seriesJson) ? $seriesJson : json_decode((string)$seriesJson, true);
                if (!is_array($decoded)) $decoded = [];
            }
            if ($isCFP) {
                // Return 12 series totals + precision_total + duelling_total
                $arr = array_fill(0, 12, '0');
                for ($i = 1; $i <= 12; $i++) {
                    if (isset($decoded[$i])) {
                        $v = $decoded[$i];
                        $arr[$i-1] = is_array($v) ? (string)($v['total'] ?? '0') : (string)$v;
                    }
                }
                $arr['precision_total'] = $decoded['precision_total'] ?? '0';
                $arr['duelling_total']  = $decoded['duelling_total'] ?? '0';
                return $arr;
            } else {
                // Standard 6 series
                $arr = array_fill(0, 6, '0');
                $i = 0;
                foreach ($decoded as $k => $v) {
                    if (is_string($k) && !is_numeric($k)) continue; // skip precision_total etc.
                    if ($i >= 6) break;
                    if (is_scalar($v)) { $arr[$i] = (string)$v; $i++; }
                    elseif (is_array($v) && isset($v['total'])) { $arr[$i] = (string)$v['total']; $i++; }
                }
                return $arr;
            }
        };

        ob_start();
        ?>
        <div class="document-canvas-render" style="width: 210mm; min-height: 297mm; padding: 12mm 15mm; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; box-sizing: border-box; margin: 0 auto; position: relative;">
          
          <!-- Top Header with Logo & Championship Details -->
          <div style="display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 12px;">
            <div style="width: 75px; flex-shrink: 0;">
              <?php if (!empty($logoBase64)): ?>
                <img src="<?= $logoBase64 ?>" style="width: 70px; height: 70px; object-fit: contain;" />
              <?php endif; ?>
            </div>
            <div style="flex: 1; text-align: center; padding: 0 10px;">
              <div style="font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #000; line-height: 1.3;">
                51ST TAMILNADU STATE SHOOTING CHAMPIONSHIP 2026
              </div>
              <div style="font-size: 12.5px; font-weight: 700; color: #222; margin-top: 3px;">
                CHENNAI TAMILNADU
              </div>
              <div style="font-size: 11.5px; font-weight: 700; color: #444; margin-top: 2px;">
                23-07-2026 - 28-07-2026
              </div>
            </div>
            <div style="width: 75px; flex-shrink: 0;"></div>
          </div>

          <!-- Result Sub-header -->
          <div style="text-align: center; margin-bottom: 16px;">
            <div style="font-size: 13.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #000;">
              <?= $titleSub ?>
            </div>
            <div style="font-size: 12.5px; font-weight: 800; margin-top: 5px; text-transform: uppercase; color: #111;">
              <?= htmlspecialchars($fullEventName) ?> <?= !empty($category) ? '(' . htmlspecialchars($category) . ')' : '' ?>
            </div>
            <div style="font-size: 11px; font-weight: 600; color: #333; margin-top: 4px;">
              <?php if ($rankType === 'preliminary' && $selRelay > 0): ?>
                Detail No. : <?= $selRelay ?> &nbsp;&nbsp;&nbsp;&nbsp; Date : <?= $dateStr ?> &nbsp;&nbsp;&nbsp;&nbsp; Time : <?= $timeStr ?>
              <?php else: ?>
                Date : <?= $dateStr ?> &nbsp;&nbsp;&nbsp;&nbsp; Time : <?= $timeStr ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Official Table -->
          <table style="width: 100%; border-collapse: collapse; font-size: 10.5px; color: #000; font-family: Arial, Helvetica, sans-serif;">
            <thead>
              <tr style="border-top: 1.5px solid #000; border-bottom: 1px solid #000;">
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 35px;" rowspan="2">SrNo</th>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 35px;" rowspan="2">Lane</th>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 60px;" rowspan="2">Comp No</th>
                <th style="padding: 5px 5px; text-align: left; font-weight: 700;" rowspan="2">Shooter Name</th>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 60px;" rowspan="2">Event No</th>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 55px;" rowspan="2">State</th>
                <?php if ($isCFP): ?>
                <th style="padding: 3px; text-align: center; font-weight: 700; border-bottom: 1px solid #000; border-left: 1px solid #000;" colspan="6">Precision</th>
                <th style="padding: 5px 2px; text-align: center; font-weight: 700; width: 35px; border-left: 1px solid #000;" rowspan="2">Prec<br>Total</th>
                <th style="padding: 3px; text-align: center; font-weight: 700; border-bottom: 1px solid #000; border-left: 1px solid #000;" colspan="6">Duelling</th>
                <th style="padding: 5px 2px; text-align: center; font-weight: 700; width: 35px; border-left: 1px solid #000;" rowspan="2">Duel<br>Total</th>
                <?php else: ?>
                <th style="padding: 3px; text-align: center; font-weight: 700; border-bottom: 1px solid #000;" colspan="6">Series</th>
                <?php endif; ?>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 45px;" rowspan="2">Penalty</th>
                <th style="padding: 5px 4px; text-align: right; font-weight: 700; width: 55px;" rowspan="2">Total</th>
                <th style="padding: 5px 3px; text-align: center; font-weight: 700; width: 40px;" rowspan="2">Rem</th>
              </tr>
              <tr style="border-bottom: 1.5px solid #000;">
                <?php if ($isCFP): ?>
                <?php for ($si = 1; $si <= 12; $si++): ?>
                <th style="padding: 3px 1px; text-align: center; width: 22px; font-weight: 700; font-size: 9px;<?= $si === 1 || $si === 7 ? ' border-left: 1px solid #000;' : '' ?>"><?= $si ?></th>
                <?php endfor; ?>
                <?php else: ?>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">1</th>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">2</th>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">3</th>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">4</th>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">5</th>
                <th style="padding: 3px 2px; text-align: center; width: 26px; font-weight: 700;">6</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($scores as $idx => $sRow): 
                $rankNum = $idx + 1;
                $laneNo = $sRow['lane_no'] ?: '—';
                $compNo = formatBibNo($sRow['reg_id'] ?? '', $sRow['bib_no'] ?? '');
                $sName = !empty($sRow['custom_shooter_name']) ? $sRow['custom_shooter_name'] : formatFullName($sRow['first_name'] ?? '', $sRow['last_name'] ?? '');
                if (empty($sName)) $sName = '—';
                $regIdCode = !empty($sRow['reg_id']) ? $sRow['reg_id'] : '';
                $evtNo = resolveEventCode($sRow['event_name'] ?? $eventName, $sRow['event_code'] ?? null, $sRow['match_no'] ?? null);
                $stateClub = !empty($sRow['district']) ? $sRow['district'] : (!empty($sRow['club_name']) ? $sRow['club_name'] : 'T.N.');
                $seriesVals = $getSeriesVals($sRow['series_data'] ?? null);
                $penalty = !empty($sRow['penalty_val']) ? $sRow['penalty_val'] : '0';
                $totalScore = !empty($sRow['grand_total_val']) ? $sRow['grand_total_val'] : '0-0x';
                $remarks = !empty($sRow['remarks']) ? ($sRow['remarks'] === 'Completed' ? 'C' : $sRow['remarks']) : 'C';
              ?>
                <tr style="border-bottom: 1px solid #eee;">
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600;"><?= $rankNum ?></td>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600;"><?= $laneNo ?></td>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600;"><?= htmlspecialchars($compNo) ?></td>
                  <td style="padding: 5px 5px; text-align: left;">
                    <div style="font-weight: 700; text-transform: uppercase; font-size: 10.5px; color: #000;"><?= htmlspecialchars($sName) ?></div>
                    <?php if (!empty($regIdCode) && $sName !== '—'): ?>
                      <div style="font-size: 9px; color: #555; margin-top: 1px; font-family: monospace;">(<?= htmlspecialchars($regIdCode) ?>)</div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600; font-size: 10px;"><?= htmlspecialchars($evtNo) ?></td>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600; font-size: 10px;"><?= htmlspecialchars($stateClub) ?></td>
                  <?php if ($isCFP): ?>
                  <?php for ($si = 0; $si < 6; $si++): ?>
                  <td style="padding: 5px 1px; text-align: center; font-size: 10px;<?= $si === 0 ? ' border-left: 1px solid #ccc;' : '' ?>"><?= htmlspecialchars((string)$seriesVals[$si]) ?></td>
                  <?php endfor; ?>
                  <td style="padding: 5px 2px; text-align: center; font-weight: 700; font-size: 10px; border-left: 1px solid #ccc;"><?= htmlspecialchars((string)($seriesVals['precision_total'] ?? '0')) ?></td>
                  <?php for ($si = 6; $si < 12; $si++): ?>
                  <td style="padding: 5px 1px; text-align: center; font-size: 10px;<?= $si === 6 ? ' border-left: 1px solid #ccc;' : '' ?>"><?= htmlspecialchars((string)$seriesVals[$si]) ?></td>
                  <?php endfor; ?>
                  <td style="padding: 5px 2px; text-align: center; font-weight: 700; font-size: 10px; border-left: 1px solid #ccc;"><?= htmlspecialchars((string)($seriesVals['duelling_total'] ?? '0')) ?></td>
                  <?php else: ?>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[0]) ?></td>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[1]) ?></td>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[2]) ?></td>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[3]) ?></td>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[4]) ?></td>
                  <td style="padding: 5px 2px; text-align: center; font-size: 10px;"><?= htmlspecialchars((string)$seriesVals[5]) ?></td>
                  <?php endif; ?>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 600; font-size: 10px;"><?= htmlspecialchars((string)$penalty) ?></td>
                  <td style="padding: 5px 4px; text-align: right; font-weight: 700; font-size: 10.5px; color: #000;"><?= htmlspecialchars((string)$totalScore) ?></td>
                  <td style="padding: 5px 3px; text-align: center; font-weight: 700; font-size: 10.5px; color: #000;"><?= htmlspecialchars((string)$remarks) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Footer Legend & Signature Area -->
          <div style="margin-top: 25px; border-top: 1px solid #000; padding-top: 10px; font-size: 10px; font-weight: 700; color: #000;">
            <div>Protest Time : 30 Minutes &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Completed : C &nbsp;&nbsp;&nbsp;&nbsp; Did Not Start : DNS &nbsp;&nbsp;&nbsp;&nbsp; Did Not Finish : DNF &nbsp;&nbsp;&nbsp;&nbsp; Disqualified : DSQ</div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 35px;">
              <div>
                <div style="border-bottom: 1px solid #000; width: 220px; height: 1px; margin-bottom: 4px;"></div>
                <div style="font-weight: 700; font-size: 10.5px; color: #000;"><?= date('d/m/y') ?> &nbsp;&nbsp;&nbsp; <?= date('H:i') ?> Hrs</div>
              </div>
              <div style="font-size: 10.5px; font-weight: 700; color: #000;">Page 1 of 1</div>
            </div>
          </div>

            </div>
            <?php
            $rankListCustomHTML = ob_get_clean();
        }

    } elseif ($docType === 'score_sheet' || strpos($docType, 'score_sheet_') === 0) {
        $allocId = (int)($_GET['alloc_id'] ?? 0);
        if ($allocId <= 0) {
            die("Lane Allocation ID is required.");
        }

        $stmt = $pdo->prepare("
            SELECT la.id AS lane_alloc_id, la.relay_no, la.lane_no, la.scheduled_date, la.bib_no, 
                   r.first_name, r.last_name, r.club_name, r.district, r.association, er.event_name, er.category,
                   ss.id AS score_sheet_id, ss.meta_card, ss.meta_match, ss.series_data, ss.total_val, ss.penalty_val, ss.grand_total_val,
                   ss.custom_shooter_name, ss.custom_bib, ss.custom_category, ss.custom_detail, ss.custom_lane, ss.custom_date, ss.custom_time,
                   ss.shooter_signature, ss.official_signature
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN score_sheets ss ON ss.lane_alloc_id = la.id
            WHERE la.id = ? LIMIT 1
        ");
        $stmt->execute([$allocId]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            die("Lane allocation not found.");
        }

        $variables = [
            'participant_name' => !empty(trim((string)($s['custom_shooter_name'] ?? ''))) ? $s['custom_shooter_name'] : trim($s['first_name'] . ' ' . $s['last_name']),
            'reg_id'           => !empty(trim((string)($s['custom_bib'] ?? ''))) ? $s['custom_bib'] : ($s['bib_no'] ?: ($s['reg_id'] ?? 'TBD')),
            'event_name'       => $s['event_name'],
            'category'         => !empty(trim((string)($s['custom_category'] ?? ''))) ? $s['custom_category'] : $s['category'],
            'date'             => !empty(trim((string)($s['custom_date'] ?? ''))) ? $s['custom_date'] : $s['scheduled_date'],
            'lane'             => !empty(trim((string)($s['custom_lane'] ?? ''))) ? $s['custom_lane'] : (string)$s['lane_no'],
            'relay'            => !empty(trim((string)($s['custom_detail'] ?? ''))) ? $s['custom_detail'] : (string)$s['relay_no'],
            'card'             => !empty(trim((string)($s['meta_card'] ?? ''))) ? $s['meta_card'] : '',
            'match'            => !empty(trim((string)($s['meta_match'] ?? ''))) ? $s['meta_match'] : '',
            'time'             => !empty(trim((string)($s['custom_time'] ?? ''))) ? $s['custom_time'] : '',
            'total'            => $s['total_val'] ?: '0',
            'penalty'          => $s['penalty_val'] ?: '0',
            'grand_total'      => $s['grand_total_val'] ?: '0',
            'shooter_signature' => !empty(trim((string)($s['shooter_signature'] ?? ''))) ? '<img src="' . resolveDocPath(trim((string)$s['shooter_signature'])) . '" style="max-height:55px; max-width:180px; object-fit:contain; display:inline-block;" />' : '',
            'official_signature' => !empty(trim((string)($s['official_signature'] ?? ''))) ? '<img src="' . resolveDocPath(trim((string)$s['official_signature'])) . '" style="max-height:55px; max-width:180px; object-fit:contain; display:inline-block;" />' : '',
            'range_officer_signature' => !empty(trim((string)($s['official_signature'] ?? ''))) ? '<img src="' . resolveDocPath(trim((string)$s['official_signature'])) . '" style="max-height:55px; max-width:180px; object-fit:contain; display:inline-block;" />' : '',
            'target_officer_signature' => !empty(trim((string)($s['official_signature'] ?? ''))) ? '<img src="' . resolveDocPath(trim((string)$s['official_signature'])) . '" style="max-height:55px; max-width:180px; object-fit:contain; display:inline-block;" />' : ''
        ];

        // Format series table & calculate automatic totals
        $series = json_decode($s['series_data'] ?? '[]', true);
        if (!is_array($series)) $series = [];

        $computedSubTotal = 0;
        for ($sIdx = 1; $sIdx <= 12; $sIdx++) {
            $seriesSum = 0;
            $hasShots = false;
            for ($shot = 1; $shot <= 10; $shot++) {
                $rawVal = trim((string)($series[$sIdx][$shot] ?? ''));
                $variables["s{$sIdx}_{$shot}"] = ($rawVal !== '') ? $rawVal : '-';
                if ($rawVal !== '' && is_numeric($rawVal)) {
                    $seriesSum += (float)$rawVal;
                    $hasShots = true;
                }
            }
            
            $sTotalVal = trim((string)($series[$sIdx]['total'] ?? ''));
            if ($sTotalVal === '' && $hasShots) {
                $sTotalVal = (string)$seriesSum;
            }
            $variables["s{$sIdx}_total"] = ($sTotalVal !== '') ? $sTotalVal : '-';
            if ($sTotalVal !== '' && is_numeric($sTotalVal)) {
                $computedSubTotal += (float)$sTotalVal;
            }
        }

        $pVal = is_numeric($s['penalty_val'] ?? '') ? (float)$s['penalty_val'] : 0;
        $subTotalVal = (is_numeric($s['total_val'] ?? '') && (float)$s['total_val'] > 0) ? (float)$s['total_val'] : $computedSubTotal;
        $grandTotalVal = (is_numeric($s['grand_total_val'] ?? '') && (float)$s['grand_total_val'] > 0) ? (float)$s['grand_total_val'] : ($subTotalVal - $pVal);

        $variables['total'] = (string)$subTotalVal;
        $variables['penalty'] = (string)$pVal;
        $variables['grand_total'] = (string)$grandTotalVal;
        $variables['precision_total'] = (string)($series['precision_total'] ?? '-');
        $variables['duelling_total'] = (string)($series['duelling_total'] ?? '-');
        
        $labels = [];
        $eventNameLower = strtolower($s['event_name'] ?? '');
        if (strpos($eventNameLower, 'standard') !== false) {
            $labels = [
                1 => '150 sec (S1)', 2 => '150 sec (S2)', 
                3 => '20 sec (S1)',  4 => '20 sec (S2)', 
                5 => '10 sec (S1)',  6 => '10 sec (S2)'
            ];
        } elseif (strpos($eventNameLower, 'centre') !== false || strpos($eventNameLower, 'center') !== false || strpos($eventNameLower, '25m') !== false || strpos($eventNameLower, 'sports pistol') !== false || strpos($eventNameLower, 'sport pistol') !== false) {
            $labels = [
                1 => 'Precision 1', 2 => 'Precision 2', 3 => 'Precision 3', 4 => 'Precision 4', 5 => 'Precision 5', 6 => 'Precision 6',
                7 => 'Duelling 1',  8 => 'Duelling 2',  9 => 'Duelling 3',  10 => 'Duelling 4', 11 => 'Duelling 5', 12 => 'Duelling 6'
            ];
        } else {
            $labels = [1 => 'Series 1', 2 => 'Series 2', 3 => 'Series 3', 4 => 'Series 4', 5 => 'Series 5', 6 => 'Series 6'];
        }
        
        $tableData['headers'] = ['SERIES', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'TOTAL'];
        $tableData['rows'] = [];
        
        foreach ($labels as $idx => $label) {
            $row = [$label];
            for ($shot = 1; $shot <= 10; $shot++) {
                $row[] = (string)($series[$idx][$shot] ?? '-');
            }
            $row[] = (string)($series[$idx]['total'] ?? '-');
            $tableData['rows'][] = $row;
        }
        
        $tableData['rows'][] = ['Total', '', '', '', '', '', '', '', '', '', '', (string)($s['total_val'] ?: '0')];
        $tableData['rows'][] = ['Penalty', '', '', '', '', '', '', '', '', '', '', (string)($s['penalty_val'] ?: '0')];
        $tableData['rows'][] = ['Grand Total', '', '', '', '', '', '', '', '', '', '', (string)($s['grand_total_val'] ?: '0')];

    } elseif ($docType === 'print_summary') {
        require_once dirname(__DIR__) . '/config/events.php';
        $selBaseType = trim($_GET['base_type'] ?? '');
        $eventLabel  = !empty($selBaseType) ? formatBaseEventName($selBaseType) : 'All Events';

        $allEventIds = [];
        foreach ($EVENTS_MAPPING as $id => $fullName) {
            $cleanBase    = preg_replace('/[^a-zA-Z0-9]/', '', getBackendEventBaseType($fullName));
            $cleanSel     = preg_replace('/[^a-zA-Z0-9]/', '', $selBaseType);
            if ($cleanBase === $cleanSel) {
                $allEventIds[] = $id;
            }
        }
        $allEventIds[] = $selBaseType;
        $allEventIds   = array_values(array_unique(array_filter($allEventIds)));

        $catQuery = '';
        $queryParams = $allEventIds;
        if (substr($selBaseType, -5) === '_issf') {
            $catQuery      = " AND er.category = ? ";
            $queryParams[] = 'ISSF';
        } elseif (substr($selBaseType, -3) === '_nr') {
            $catQuery = " AND er.category IN ('NR', 'NR_MQS') ";
        }

        $inClause = implode(',', array_fill(0, count($allEventIds), '?'));

        $rows = $pdo->prepare("
            SELECT
                la.lane_no, la.relay_no, la.scheduled_date,
                la.bib_no,
                COALESCE(la.custom_name, TRIM(CONCAT(r.first_name, ' ', r.last_name))) AS shooter_name,
                r.club_name, r.district
            FROM lane_allocations la
            LEFT JOIN event_registrations er ON la.event_reg_id = er.id
            LEFT JOIN registrations r ON er.user_id = r.id
            WHERE (er.event_name IN ($inClause) OR la.event_reg_id IS NULL) $catQuery
            ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.lane_no ASC
        ");
        $rows->execute($queryParams);
        $allocs = $rows->fetchAll(PDO::FETCH_ASSOC);

        $tableData['headers'] = ['S.No', 'Club / District', 'Date', 'Relay', 'FP (Lane)', 'Shooter Name', 'Enrollment ID'];
        $sr = 1;
        foreach ($allocs as $a) {
            $enrollId    = $a['bib_no'] ?? '';
            $shooterName = (!empty($enrollId) && stripos($enrollId, 'MANUAL') === false && strtoupper($enrollId) !== 'TBD') ? trim($a['shooter_name'] ?? '') : '';
            if (stripos($shooterName, 'MANUAL SHOOTER') !== false) { $shooterName = '—'; }
            if (stripos($enrollId, 'MANUAL') !== false) { $enrollId = '—'; }
            $tableData['results'][] = [
                'sr'          => $sr++,
                'club_name'   => $a['club_name'] ?: ($a['district'] ?: '—'),
                'scheduled_date' => $a['scheduled_date'] ?? '',
                'relay_no'    => $a['relay_no'] ?? '—',
                'lane_no'     => $a['lane_no'] ?? '—',
                'first_name'  => $shooterName,
                'last_name'   => '',
                'bib_no'      => $enrollId,
                'reg_id'      => $enrollId,
                'district'    => $a['district'] ?? '—',
                'grand_total_val' => ''
            ];
        }

        $variables = [
            'event_name' => $eventLabel,
            'date'       => date('d/m/Y')
        ];

    } // end elseif print_summary

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

$dynamicHTML = ($docType === 'rank_list' && !empty($rankListCustomHTML)) ? $rankListCustomHTML : getDynamicDocumentHTML($docType, $variables, '', $tableData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Designed Template</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Cinzel:wght@600;700&display=swap" rel="stylesheet">
    <!-- html2pdf.js Bundle -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body {
            margin: 0;
            background: #181920;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            min-height: 100vh;
        }
        .print-toolbar {
            width: 100%;
            max-width: 800px;
            background: #232634;
            padding: 12px 24px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            box-sizing: border-box;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }
        .btn {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-transform: uppercase;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: #d4af37;
            color: #000000;
        }
        .btn-primary:hover {
            background: #f59e0b;
        }
        .btn-danger {
            background: #dc2626;
            color: #ffffff;
        }
        .doc-render-container {
            display: block !important;
            visibility: visible !important;
            width: 100% !important;
            margin: 0 auto !important;
        }
        .document-canvas-render {
            display: block !important;
            visibility: visible !important;
            width: 794px !important;
            min-height: 1122px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5) !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            margin: 0 auto 40px auto !important;
            position: relative !important;
        }
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            html, body {
                width: 100% !important;
                height: auto !important;
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            .print-toolbar {
                display: none !important;
            }
            .doc-render-container {
                gap: 0 !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                align-items: center !important;
                justify-content: flex-start !important;
                margin: 0 auto !important;
                padding: 0 !important;
                background: #ffffff !important;
            }
            .document-canvas-render {
                box-shadow: none !important;
                margin: 0 auto !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                background: #ffffff !important;
                border: none !important;
            }
            /* Dynamic Auto-Adjusting Tables */
            .document-canvas-render table {
                width: 100% !important;
                table-layout: auto !important;
                border-collapse: collapse !important;
                border: 1px solid #000000 !important;
                word-wrap: break-word !important;
                overflow-wrap: break-word !important;
            }
            .document-canvas-render th {
                background-color: #f0f0f0 !important;
                background: #f0f0f0 !important;
                color: #000000 !important;
                border: 1px solid #000000 !important;
                font-weight: bold !important;
                padding: 6px 8px !important;
                white-space: normal !important;
                word-break: normal !important;
                font-size: 10pt !important;
            }
            .document-canvas-render td {
                background-color: #ffffff !important;
                background: #ffffff !important;
                color: #000000 !important;
                border: 1px solid #000000 !important;
                padding: 6px 8px !important;
                white-space: normal !important;
                word-break: normal !important;
                overflow: visible !important;
                vertical-align: middle !important;
                font-size: 10pt !important;
            }
            .document-canvas-render tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .document-canvas-render thead {
                display: table-header-group !important;
            }
            /* High Contrast Black & White Text & Badges */
            .document-canvas-render div, 
            .document-canvas-render p, 
            .document-canvas-render h1, 
            .document-canvas-render h2, 
            .document-canvas-render h3, 
            .document-canvas-render span {
                color: #000000 !important;
                background-color: transparent !important;
            }
            /* Crisp Grayscale Logos and E-Signatures */
            .document-canvas-render img {
                filter: grayscale(100%) contrast(130%) !important;
            }
            .document-canvas-render:last-child {
                page-break-after: avoid !important;
            }
            .page-break {
                display: none !important;
            }
        }
    </head>
<?php
$isAutoPrint = !isset($_GET['autoprint']) || $_GET['autoprint'] === '1' || $_GET['autoprint'] === 'true';
$showPreviewToolbar = isset($_GET['preview']) && $_GET['preview'] === '1';
$docTypeTitle = match($docType) {
    'rank_list' => 'Rank List Document',
    'score_sheet' => 'Score Sheet Document',
    'print_summary' => 'Summary Document',
    default => 'Start List Document'
};
?>
<body>
    <div class="print-toolbar print-hide" style="width: 100%; max-width: 800px; margin: 0 auto 15px auto; background: #1a1c23; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.4);">
        <span style="color:#ffffff; font-weight:700; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;">🖨️ <?= htmlspecialchars($docTypeTitle) ?></span>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-primary" onclick="window.print()" style="background:#d4af37; color:#000; font-weight:700; border:none; padding:8px 18px; border-radius:4px; cursor:pointer;">🖨️ Print Now</button>
            <button class="btn btn-danger" onclick="window.close()" style="background:#dc2626; color:#fff; font-weight:700; border:none; padding:8px 18px; border-radius:4px; cursor:pointer;">✕ Close</button>
        </div>
    </div>

    <div class="doc-render-container">
        <?= $dynamicHTML ?>
    </div>

    <?php if ($isAutoPrint): ?>
    <script>
    window.addEventListener('load', function() {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        });
    });
    </script>
    <?php endif; ?>

    <script>
    function downloadPDF() {
        const element = document.querySelector('.document-canvas-render');
        if (!element) return;

        const width = element.clientWidth;
        const height = element.clientHeight;
        const isLandscape = width > height;
        
        // Get original designed height from computed min-height to slice A4 pages correctly
        const minHeightStyle = window.getComputedStyle(element).minHeight;
        const parsedMinHeight = parseFloat(minHeightStyle) || height;
        const originalHeight = parsedMinHeight > 0 ? parsedMinHeight : height;
        
        const container = document.querySelector('.doc-render-container');

        const opt = {
            margin:       0,
            filename:     '<?= htmlspecialchars($docType) ?>_<?= date("YmdHis") ?>.pdf',
            image:        { type: 'jpeg', quality: 1.0 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'px', format: [width, originalHeight], orientation: isLandscape ? 'landscape' : 'portrait' },
            pagebreak:    { mode: ['css', 'legacy'] }
        };

        html2pdf().set(opt).from(container).save();
    }
    </script>
</body>
</html>
