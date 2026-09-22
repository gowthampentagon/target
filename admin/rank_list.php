<?php
// admin/rank_list.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
$pageTitle = 'Rank List';
require_once 'includes/header.php';

try {
    $pdo = getDB();

    // ── Build base groups matching lane_allocations.php and start_sheet.php ──
    $baseGroups = [];
    $addEventToBaseGroups = function (string $evt, string $cat = '') use (&$baseGroups, $EVENTS_MAPPING) {
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
            $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2|SUB|YOUTH|JUNIOR|SENIOR|MASTER|SUPER|PEEP|SIGHT)\b/i', '', $cleanName);
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

    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    // 1. Approved & Active event registrations
    $eventRegsStmt = $pdo->prepare("
        SELECT DISTINCT event_name, category 
        FROM event_registrations 
        WHERE championship_id = ? AND event_name IS NOT NULL AND event_name != ''
        ORDER BY event_name ASC, category ASC
    ");
    $eventRegsStmt->execute([$cid]);
    $eventRegsWithCat = $eventRegsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($eventRegsWithCat as $item) {
        $addEventToBaseGroups($item['event_name'], $item['category'] ?? 'nr');
    }

    // 2. Existing lane allocations
    $allocStmt = $pdo->prepare("
        SELECT DISTINCT er.event_name, er.category 
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        WHERE er.championship_id = ? AND er.event_name IS NOT NULL AND er.event_name != ''
    ");
    $allocStmt->execute([$cid]);
    $allocEvents = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allocEvents as $item) {
        $addEventToBaseGroups($item['event_name'], $item['category'] ?? 'nr');
    }

    // 3. Teams
    $teamEvents = $pdo->query("
        SELECT DISTINCT event_name, category FROM teams
        WHERE event_name IS NOT NULL AND event_name != ''
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teamEvents as $item) {
        $addEventToBaseGroups($item['event_name'], $item['category'] ?? 'nr');
    }

    // 4. Custom events
    $customEventsTable = $pdo->query("SHOW TABLES LIKE 'custom_event_names'")->fetchColumn();
    if ($customEventsTable) {
        $customEvents = $pdo->query("SELECT event_name FROM custom_event_names")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($customEvents as $evt) {
            $addEventToBaseGroups($evt, 'nr');
        }
    }

    // 5. Default competition events mapping if baseGroups is empty
    if (empty($baseGroups)) {
        foreach ($EVENTS_MAPPING as $code => $fullName) {
            $cat = (strpos(strtoupper($fullName), '(ISSF)') !== false) ? 'issf' : 'nr';
            $addEventToBaseGroups($code, $cat);
        }
    }

    $prelimIssfDropdown = [];
    $prelimNrDropdown   = [];

    foreach (array_keys($baseGroups) as $bgKey) {
        $label = formatBaseEventName($bgKey);
        if (str_ends_with(strtolower($bgKey), '_issf') || str_contains(strtolower($label), '(issf)')) {
            $prelimIssfDropdown[$bgKey] = $label;
        } else {
            $prelimNrDropdown[$bgKey]   = $label;
        }
    }
    asort($prelimIssfDropdown);
    asort($prelimNrDropdown);

    // ── Build Registered Events Dropdown for Team & Individual Overall (matching team_events.php) ──
    $teamIssfDropdown  = [];
    $teamNrDropdown    = [];
    $teamOtherDropdown = [];

    $registeredEventsQuery = "
        SELECT DISTINCT er.event_name, er.category
        FROM event_registrations er
        WHERE er.event_name IS NOT NULL AND er.event_name != ''
        UNION
        SELECT DISTINCT t.event_name, t.category
        FROM teams t
        WHERE t.event_name IS NOT NULL AND t.event_name != ''
    ";
    $registeredEvents = $pdo->query($registeredEventsQuery)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($registeredEvents as $ev) {
        $evt = $ev['event_name'];
        if (empty($evt)) continue;
        $cat = $ev['category'] ?? '';
        $fullName = $EVENTS_MAPPING[$evt] ?? $evt;
        $label = ($fullName !== $evt && isset($EVENTS_MAPPING[$evt])) ? "{$evt} - {$fullName}" : $fullName;
        if (!empty($cat) && strpos(strtoupper($label), strtoupper($cat)) === false) {
            $label .= " ({$cat})";
        }

        $optKey = !empty($cat) ? "{$evt}||{$cat}" : $evt;
        $catUpper = strtoupper($cat);
        $evtUpper = strtoupper($evt);

        if ($catUpper === 'ISSF' || strpos($evtUpper, '(ISSF)') !== false || strpos($evtUpper, 'ISSF') !== false) {
            $teamIssfDropdown[$optKey] = $label;
        } elseif ($catUpper === 'NR' || strpos($evtUpper, '(NR)') !== false || strpos($evtUpper, 'NATIONAL RULE') !== false) {
            $teamNrDropdown[$optKey] = $label;
        } else {
            $teamOtherDropdown[$optKey] = $label;
        }
    }
    asort($teamIssfDropdown);
    asort($teamNrDropdown);
    asort($teamOtherDropdown);

    // Build global regClubMap for fallback shooter & club name lookups
    $rawApprovedRegs = $pdo->query("
        SELECT r.reg_id, r.first_name, r.last_name, r.club_name, r.district
        FROM registrations r
        WHERE r.reg_id IS NOT NULL AND r.reg_id != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $regClubMap = [];
    foreach ($rawApprovedRegs as $r) {
        $club = trim($r['club_name'] ?? '');
        if (empty($club) || $club === '—' || $club === '-') {
            $club = trim($r['district'] ?? '');
        }
        if (!empty($club) && $club !== '—' && $club !== '-') {
            $fBib = formatBibNo($r['reg_id']);
            if ($fBib) $regClubMap[$fBib] = $club;
            if ($r['reg_id']) $regClubMap[$r['reg_id']] = $club;
            $uDigits = preg_replace('/\D/', '', (string)$r['reg_id']);
            if ($uDigits) $regClubMap[$uDigits] = $club;
            $fName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if (!empty($fName)) $regClubMap[strtoupper($fName)] = $club;
        }
    }

    // event_name carries composite key, base group key, or event code
    $selEventRaw  = trim($_GET['event_name'] ?? '');
    $rankType     = trim($_GET['rank_type']  ?? 'overall');
    $selDate      = trim($_GET['date']       ?? '');
    $selRelay     = (int)($_GET['relay']     ?? 0);
    $rankMode     = in_array(trim($_GET['rank_mode'] ?? 'individual'), ['individual','team'])
                     ? trim($_GET['rank_mode'] ?? 'individual')
                     : 'individual';

    // Parse event selection
    $selEventsList = [];
    $selBaseType   = '';
    $selCategory   = '';
    $selEvent      = '';

    if (!empty($selEventRaw)) {
        if (isset($baseGroups[$selEventRaw])) {
            $selBaseType   = $selEventRaw;
            $selEventsList = $baseGroups[$selEventRaw];
            $catClean      = (substr($selBaseType, -5) === '_issf') ? 'ISSF' : ((substr($selBaseType, -3) === '_nr') ? 'NR' : '');
            $selCategory   = $catClean ?: trim($_GET['category'] ?? '');
            $selEvent      = $selEventsList[0] ?? $selEventRaw;
            $eventLabel    = formatBaseEventName($selBaseType);
        } elseif (str_contains($selEventRaw, '||')) {
            [$selEvent, $selCategory] = explode('||', $selEventRaw, 2);
            $selEventsList = [$selEvent];
            $eventFull = $EVENTS_MAPPING[$selEvent] ?? $selEvent;
            $eventLabel = (strcasecmp($selEvent, $eventFull) === 0 || stripos($eventFull, $selEvent) !== false)
                ? $eventFull
                : ($selEvent . ' — ' . $eventFull);
            $baseT = getBackendEventBaseType($eventFull ?: $selEvent);
            if (!empty($baseT) && !empty($selCategory)) {
                $selBaseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $baseT) . '_' . strtolower($selCategory);
            }
        } else {
            $selEvent      = $selEventRaw;
            $selCategory   = trim($_GET['category'] ?? '');
            $selEventsList = [$selEvent];
            $eventFull     = $EVENTS_MAPPING[$selEvent] ?? $selEvent;
            $eventLabel    = (strcasecmp($selEvent, $eventFull) === 0 || stripos($eventFull, $selEvent) !== false)
                ? $eventFull
                : ($selEvent . ' — ' . $eventFull);
            $baseT = getBackendEventBaseType($eventFull ?: $selEvent);
            if (!empty($baseT)) {
                $catPart = !empty($selCategory) ? strtolower($selCategory) : ((strpos(strtoupper($eventFull), 'ISSF') !== false) ? 'issf' : 'nr');
                $selBaseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $baseT) . '_' . $catPart;
            }
        }
        $expandedEvts = $selEventsList;
        foreach ($selEventsList as $eKey) {
            if (isset($EVENTS_MAPPING[$eKey])) {
                $expandedEvts[] = $EVENTS_MAPPING[$eKey];
            }
            foreach ($EVENTS_MAPPING as $code => $fullName) {
                if (strcasecmp($fullName, $eKey) === 0 || strcasecmp($code, $eKey) === 0) {
                    $expandedEvts[] = $code;
                    $expandedEvts[] = $fullName;
                }
            }

            $targetBaseType = getBackendEventBaseType($EVENTS_MAPPING[$eKey] ?? $eKey);
            if (!empty($targetBaseType)) {
                foreach ($EVENTS_MAPPING as $id => $fullName) {
                    $baseType = getBackendEventBaseType($fullName);
                    if (!empty($baseType) && strcasecmp($baseType, $targetBaseType) === 0) {
                        $expandedEvts[] = $id;
                        $expandedEvts[] = $fullName;
                    }
                }
            }
            $expandedEvts[] = $eKey;
        }
        $selEventsList = array_values(array_unique(array_filter($expandedEvts)));
    } else {
        $eventLabel = '';
    }

    // Available dates for preliminary filter (only if event selected)
    $availDates  = [];
    $availRelays = [];

    if (!empty($selEventsList)) {
        $inEvts = implode(',', array_fill(0, count($selEventsList), '?'));
        $datesWhere  = ["ss.grand_total_val IS NOT NULL AND ss.grand_total_val != ''", "la.scheduled_date IS NOT NULL", "(er.event_name IN ($inEvts) OR er.event_code IN ($inEvts))"];
        $datesParams = array_merge($selEventsList, $selEventsList);
        if (!empty($selCategory)) {
            $datesWhere[]  = "(UPPER(er.category) = ? OR UPPER(ss.custom_category) = ? OR UPPER(er.event_name) LIKE ?)";
            $datesParams[] = strtoupper($selCategory);
            $datesParams[] = strtoupper($selCategory);
            $datesParams[] = '%' . strtoupper($selCategory) . '%';
        }
        $datesQ = $pdo->prepare("SELECT DISTINCT la.scheduled_date FROM lane_allocations la JOIN event_registrations er ON la.event_reg_id = er.id JOIN score_sheets ss ON ss.lane_alloc_id = la.id WHERE " . implode(" AND ", $datesWhere) . " ORDER BY la.scheduled_date ASC");
        $datesQ->execute($datesParams);
        $availDates = $datesQ->fetchAll(PDO::FETCH_COLUMN);

        // Available relays
        $relaysWhere  = ["ss.grand_total_val IS NOT NULL AND ss.grand_total_val != ''", "la.relay_no IS NOT NULL", "(er.event_name IN ($inEvts) OR er.event_code IN ($inEvts))"];
        $relaysParams = array_merge($selEventsList, $selEventsList);
        if (!empty($selCategory)) {
            $relaysWhere[]  = "(UPPER(er.category) = ? OR UPPER(ss.custom_category) = ? OR UPPER(er.event_name) LIKE ?)";
            $relaysParams[] = strtoupper($selCategory);
            $relaysParams[] = strtoupper($selCategory);
            $relaysParams[] = '%' . strtoupper($selCategory) . '%';
        }
        if (!empty($selDate)) {
            $relaysWhere[]  = "la.scheduled_date = ?";
            $relaysParams[] = $selDate;
        }
        $relaysQ = $pdo->prepare("SELECT DISTINCT la.relay_no FROM lane_allocations la JOIN event_registrations er ON la.event_reg_id = er.id JOIN score_sheets ss ON ss.lane_alloc_id = la.id WHERE " . implode(" AND ", $relaysWhere) . " ORDER BY la.relay_no ASC");
        $relaysQ->execute($relaysParams);
        $availRelays = array_map('intval', $relaysQ->fetchAll(PDO::FETCH_COLUMN));
    }

    // Build individual rank data
    $rankData = [];

    if ($rankMode === 'individual' && !empty($selEventsList)) {
        $whereClauses = ["er.status = 'approved'", "er.championship_id = ?"];
        $queryParams  = [$cid];

        $inEvts = implode(',', array_fill(0, count($selEventsList), '?'));
        $whereClauses[] = "(er.event_name IN ($inEvts) OR er.event_code IN ($inEvts))";
        foreach ($selEventsList as $eCode) { $queryParams[] = $eCode; }
        foreach ($selEventsList as $eCode) { $queryParams[] = $eCode; }

        if (!empty($selCategory)) {
            $whereClauses[] = "(UPPER(er.category) = ? OR UPPER(er.event_name) LIKE ?)";
            $queryParams[]  = strtoupper($selCategory);
            $queryParams[]  = '%' . strtoupper($selCategory) . '%';
        }

        $sqlWhere = implode(" AND ", $whereClauses);

        $q = $pdo->prepare("
            SELECT
                er.id AS event_reg_id,
                er.event_name,
                er.event_code,
                er.match_no,
                er.category,
                er.certificate_path,
                r.id AS user_id,
                r.first_name,
                r.last_name,
                r.club_name,
                r.district,
                r.reg_id,
                la_direct.id AS alloc_id,
                la_direct.relay_no,
                la_direct.lane_no,
                la_direct.scheduled_date,
                la_direct.bib_no,
                la_direct.custom_name,
                ss_direct.id AS score_sheet_id,
                ss_direct.custom_shooter_name,
                ss_direct.custom_bib,
                ss_direct.grand_total_val AS direct_grand_total,
                ss_direct.total_val AS direct_total_val,
                ss_direct.penalty_val AS direct_penalty_val,
                ss_direct.series_data AS direct_series_data,
                ss_direct.remarks AS direct_remarks,
                ss_direct.custom_category
            FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN lane_allocations la_direct ON la_direct.event_reg_id = er.id
            LEFT JOIN score_sheets ss_direct ON ss_direct.lane_alloc_id = la_direct.id
            WHERE $sqlWhere
        ");
        $q->execute($queryParams);
        $rawRegRows = $q->fetchAll(PDO::FETCH_ASSOC);

        // For each participant registration, resolve score via direct or category+discipline matched fallback score sheet
        $processedRows = [];
        $seenEntries   = [];

        foreach ($rawRegRows as $row) {
            $uId = (int)$row['user_id'];
            $evtName = $row['event_name'];
            $cat = $row['category'];
            
            $grandTotalVal = $row['direct_grand_total'] ?? null;
            $totalVal = $row['direct_total_val'] ?? null;
            $penaltyVal = $row['direct_penalty_val'] ?? null;
            $seriesData = $row['direct_series_data'] ?? null;
            $remarks = $row['direct_remarks'] ?? null;
            $allocId = $row['alloc_id'] ?? null;
            $relayNo = $row['relay_no'] ?? null;
            $laneNo = $row['lane_no'] ?? null;
            $schedDate = $row['scheduled_date'] ?? null;
            $bibNo = $row['bib_no'] ?? null;
            $customName = $row['custom_name'] ?? null;
            $scoreSheetId = $row['score_sheet_id'] ?? null;
            $customShooterName = $row['custom_shooter_name'] ?? null;
            $customBib = $row['custom_bib'] ?? null;
            $customCategory = $row['custom_category'] ?? null;

            if (empty($grandTotalVal)) {
                // Find fallback scored allocation matching category family and discipline base type
                $evtFullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
                $evtBaseType = getBackendEventBaseType($evtFullName);
                $evtCatFamily = (strpos(strtoupper($cat), 'ISSF') !== false || strpos(strtoupper($evtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                $fallbackStmt = $pdo->prepare("
                    SELECT la_f.id AS alloc_id, la_f.relay_no, la_f.lane_no, la_f.scheduled_date, la_f.bib_no, la_f.custom_name,
                           ss_f.id AS score_sheet_id, ss_f.custom_shooter_name, ss_f.custom_bib, ss_f.grand_total_val,
                           ss_f.total_val, ss_f.penalty_val, ss_f.series_data, ss_f.remarks, ss_f.custom_category,
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
                        $allocId = $us['alloc_id'];
                        $relayNo = $us['relay_no'];
                        $laneNo = $us['lane_no'];
                        $schedDate = $us['scheduled_date'];
                        $bibNo = $us['bib_no'];
                        $customName = $us['custom_name'];
                        $scoreSheetId = $us['score_sheet_id'];
                        $customShooterName = $us['custom_shooter_name'];
                        $customBib = $us['custom_bib'];
                        $customCategory = $us['custom_category'];
                        break;
                    }
                }
            }

            if (empty($grandTotalVal)) {
                continue;
            }

            if ($rankType === 'preliminary') {
                if (!empty($selDate) && $schedDate !== $selDate) {
                    continue;
                }
                if ($selRelay > 0 && (int)$relayNo !== $selRelay) {
                    continue;
                }
            }

            // Deduplication: prevent duplicate entries for the same competitor / score sheet / allocation
            $dedupKey = !empty($scoreSheetId)
                ? 'ss_' . $scoreSheetId
                : (!empty($allocId)
                    ? 'alloc_' . $allocId
                    : ($rankType === 'preliminary'
                        ? 'u_' . $uId . '_' . $schedDate . '_' . $relayNo . '_' . $laneNo
                        : 'u_' . $uId
                    )
                );

            if (isset($seenEntries[$dedupKey])) {
                continue;
            }
            $seenEntries[$dedupKey] = true;

            $row['alloc_id'] = $allocId;
            $row['relay_no'] = $relayNo;
            $row['lane_no'] = $laneNo;
            $row['scheduled_date'] = $schedDate;
            $row['bib_no'] = $bibNo;
            $row['custom_name'] = $customName;
            $row['score_sheet_id'] = $scoreSheetId;
            $row['custom_shooter_name'] = $customShooterName;
            $row['custom_bib'] = $customBib;
            $row['grand_total_val'] = $grandTotalVal;
            $row['total_val'] = $totalVal;
            $row['penalty_val'] = $penaltyVal;
            $row['series_data'] = $seriesData;
            $row['remarks'] = $remarks;
            $row['custom_category'] = $customCategory;

            $processedRows[] = $row;
        }

        // Sort rows by preliminary or overall rules
        if ($rankType === 'preliminary') {
            usort($processedRows, function($a, $b) {
                $d = strcmp((string)($a['scheduled_date'] ?? ''), (string)($b['scheduled_date'] ?? ''));
                if ($d !== 0) return $d;
                $r = ((int)($a['relay_no'] ?? 0)) <=> ((int)($b['relay_no'] ?? 0));
                if ($r !== 0) return $r;
                $l1 = ((int)($a['lane_no'] ?? 0) > 0) ? (int)$a['lane_no'] : 99999;
                $l2 = ((int)($b['lane_no'] ?? 0) > 0) ? (int)$b['lane_no'] : 99999;
                return $l1 <=> $l2;
            });
        } else {
            usort($processedRows, 'compareShooterScores');
        }

        $rows = $processedRows;

        // Fetch all registered Event Nos for each competitor under the same weapon category
        foreach ($rows as &$row) {
            $uId = (int)$row['user_id'];
            $uCat = strtoupper($row['category'] ?? '');
            
            $stmtEvs = $pdo->prepare("
                SELECT er.event_name, er.event_code, er.match_no
                FROM event_registrations er
                WHERE er.user_id = ? AND (
                    UPPER(er.category) = ? OR 
                    (UPPER(er.category) LIKE '%ISSF%' AND ? LIKE '%ISSF%') OR
                    (UPPER(er.category) LIKE '%NR%' AND ? LIKE '%NR%')
                )
                ORDER BY er.id ASC
            ");
            $stmtEvs->execute([$uId, $uCat, $uCat, $uCat]);
            $userRegEvts = $stmtEvs->fetchAll(PDO::FETCH_ASSOC);

            $cleanEvNos = [];
            foreach ($userRegEvts as $uEv) {
                $code = resolveEventCode($uEv['event_name'] ?? null, $uEv['event_code'] ?? null, $uEv['match_no'] ?? null);
                if (!empty($code) && !in_array($code, $cleanEvNos, true)) {
                    $cleanEvNos[] = $code;
                }
            }
            $row['all_event_nos'] = !empty($cleanEvNos) ? implode(', ', $cleanEvNos) : resolveEventCode($row['event_name'] ?? $selEvent, $row['event_code'] ?? null, $row['match_no'] ?? null);
        }
        unset($row);

        // Deduplicate per (event_reg_id, score_sheet_id) to ensure each registered event for the shooter shows its entry
        if ($rankType === 'overall') {
            $seen = [];
            $deduped = [];
            foreach ($rows as $row) {
                $ident = $row['event_reg_id'] . '_' . ($row['score_sheet_id'] ?? '0');
                if (!isset($seen[$ident])) {
                    $seen[$ident] = true;
                    $deduped[] = $row;
                }
            }
            $rows = $deduped;
        }

        // Add rank
        foreach ($rows as $idx => &$row) {
            $row['rank'] = $idx + 1;
            $row['display_name'] = !empty($row['custom_shooter_name'])
                ? $row['custom_shooter_name']
                : (!empty($row['custom_name']) ? $row['custom_name'] : (formatFullName($row['first_name'], $row['last_name']) ?: '—'));

            $row['display_bib'] = formatBibNo($row['reg_id'] ?? '', !empty($row['custom_bib']) ? $row['custom_bib'] : (!empty($row['bib_no']) ? $row['bib_no'] : null));
            if (empty($row['display_bib'])) {
                $row['display_bib'] = '—';
            }

            $formattedBibKey = formatBibNo($row['display_bib']);
            $nameUpperKey = strtoupper($row['display_name']);

            $resolvedClubVal = !empty($row['club_name']) ? $row['club_name'] : (!empty($row['district']) ? $row['district'] : '');
            if (empty($resolvedClubVal) || $resolvedClubVal === '—' || $resolvedClubVal === '-') {
                if (!empty($formattedBibKey) && isset($regClubMap[$formattedBibKey])) {
                    $resolvedClubVal = $regClubMap[$formattedBibKey];
                } elseif (!empty($nameUpperKey) && isset($regClubMap[$nameUpperKey])) {
                    $resolvedClubVal = $regClubMap[$nameUpperKey];
                }
            }
            $row['display_club'] = !empty($resolvedClubVal) ? $resolvedClubVal : '—';
        }
        unset($row);

        $rankData = $rows;
    }

    // ── TEAM RANK DATA ──────────────────────────────────────────────────────────
    // Fetch events that have at least one team configured
    $teamEvents = $pdo->query("
        SELECT DISTINCT event_name, category FROM teams
        ORDER BY event_name ASC, category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $teamRankData = [];

    if ($rankMode === 'team' && (!empty($selEventsList) || ($selEvent && $selCategory))) {
        $teamWhere = [];
        $teamParams = [];
        if (!empty($selEventsList)) {
            $inEvts = implode(',', array_fill(0, count($selEventsList), '?'));
            $teamWhere[] = "(t.event_name IN ($inEvts) OR t.event_name = ? OR t.event_name = ?)";
            $teamParams = array_merge($selEventsList, [$selEvent, $selEventRaw]);
        } elseif (!empty($selEvent)) {
            $teamWhere[] = "(t.event_name = ? OR t.event_name = ?)";
            $teamParams[] = $selEvent;
            $teamParams[] = $selEventRaw;
        }

        if (!empty($selCategory)) {
            $teamWhere[] = "(UPPER(t.category) = ? OR t.category IS NULL OR t.category = '')";
            $teamParams[] = strtoupper($selCategory);
        }

        $sqlTeamWhere = implode(" AND ", $teamWhere);
        $tStmt = $pdo->prepare("
            SELECT
                t.id        AS team_id,
                t.team_name,
                tm.enrollment_id,
                tm.shooter_name,
                tm.club_name,
                tm.score    AS stored_score,
                tm.lane_alloc_id
            FROM teams t
            JOIN team_members tm ON tm.team_id = t.id
            WHERE $sqlTeamWhere
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
            
            // Resolve team member score and lane allocation dynamically
            $targetEvt = !empty($selEvent) ? $selEvent : ($selEventsList[0] ?? $selEventRaw);
            $targetCat = !empty($selCategory) ? $selCategory : '';
            $scoreInfo = resolveTeamMemberScore($pdo, (string)$tr['enrollment_id'], $targetEvt, $targetCat, !empty($tr['lane_alloc_id']) ? (int)$tr['lane_alloc_id'] : null, (string)$tr['shooter_name']);

            $allocId = $scoreInfo['lane_alloc_id'];
            $liveScore = $scoreInfo['score'];
            if ($liveScore <= 0 && !empty($tr['stored_score'])) {
                $liveScore = (float)$tr['stored_score'];
            }

            // Resolve bib number (5 digit number) to display instead of default SSA-... number
            $displayId = formatBibNo($tr['enrollment_id']);
            if ($allocId) {
                $bibQ = $pdo->prepare("
                    SELECT COALESCE(ss.custom_bib, la.bib_no) AS resolved_bib
                    FROM lane_allocations la
                    LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
                    WHERE la.id = ?
                    LIMIT 1
                ");
                $bibQ->execute([$allocId]);
                $resolvedBib = $bibQ->fetchColumn();
                if (!empty($resolvedBib)) {
                    $displayId = $resolvedBib;
                }
            }

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
        $teamRankData = $grouped;
    }

    // Build a clean readable label for the selected event matching the dropdown name
    $selectedOptionLabel = '';
    if (!empty($selEventRaw)) {
        if (isset($teamIssfDropdown[$selEventRaw])) {
            $selectedOptionLabel = $teamIssfDropdown[$selEventRaw];
        } elseif (isset($teamNrDropdown[$selEventRaw])) {
            $selectedOptionLabel = $teamNrDropdown[$selEventRaw];
        } elseif (isset($teamOtherDropdown[$selEventRaw])) {
            $selectedOptionLabel = $teamOtherDropdown[$selEventRaw];
        } elseif (isset($prelimIssfDropdown[$selEventRaw])) {
            $selectedOptionLabel = $prelimIssfDropdown[$selEventRaw];
        } elseif (isset($prelimNrDropdown[$selEventRaw])) {
            $selectedOptionLabel = $prelimNrDropdown[$selEventRaw];
        }
    }
    if (empty($selectedOptionLabel)) {
        $fullEvtName = $EVENTS_MAPPING[$selEvent] ?? $selEvent;
        $selectedOptionLabel = !empty($fullEvtName) ? $fullEvtName : formatBaseEventName($selEventRaw ?: $selEvent);
    }
    if (str_contains($selectedOptionLabel, '||')) {
        $selectedOptionLabel = explode('||', $selectedOptionLabel, 2)[0];
    }
    $eventLabel = $selectedOptionLabel;

} catch (Throwable $e) {
    $scoredEvents = [];
    $rankData = [];
    $availDates = [];
    $availRelays = [];
    $selRelay = 0;
    $eventLabel = '';
    $teamEvents   = [];
    $teamRankData = [];
    $error = $e->getMessage();
}
?>

<style>
/* ── Certificate Full-Screen Preview Modal ── */
#certModal {
  display: none;
  position: fixed;
  z-index: 99999;
  inset: 0;
  background: rgba(0,0,0,0.92);
  flex-direction: column;
}
#certModal.open { display: flex; }
#certModalHeader {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  background: #1a1a1a;
  border-bottom: 2px solid #c9a84c;
  height: 52px;
  box-sizing: border-box;
}
#certModalTitle {
  font-family: 'Rajdhani', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #c9a84c;
  text-transform: uppercase;
  letter-spacing: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  margin-right: 16px;
}
#certModalActions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
#certModalDownload {
  padding: 5px 14px;
  font-size: 12px;
  font-weight: 700;
  background: #c9a84c;
  color: #000;
  border-radius: 4px;
  text-decoration: none;
  display: inline-block;
}
#certModalDownload[hidden] { display: none !important; }
#certModalClose {
  background: none;
  border: 2px solid rgba(255,255,255,0.2);
  color: #fff;
  font-size: 22px;
  width: 36px;
  height: 36px;
  border-radius: 6px;
  cursor: pointer;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s, border-color 0.2s;
}
#certModalClose:hover {
  background: #e74c3c;
  border-color: #e74c3c;
}
#certModalBody {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  background: #0d0d0d;
  padding: 20px;
  box-sizing: border-box;
}
#certModalBody iframe {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
}

/* ─── Rank List Page Styles ─── */
.rl-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 28px;
    padding: 16px 20px;
    background: rgba(13,15,20,0.6);
    border: 1px solid rgba(201,168,76,0.15);
    border-radius: 10px;
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1000;
    overflow: visible !important;
}
.rl-toolbar .input-wrap {
    margin: 0;
    min-width: 180px;
    position: relative;
    z-index: 1001;
}
.rl-type-tabs {
    display: flex;
    gap: 0;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 6px;
    overflow: hidden;
}
.rl-type-tab {
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    cursor: pointer;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    transition: all 0.2s;
}
.rl-type-tab.active {
    background: var(--gold-500);
    color: #000;
}
.rl-type-tab:hover:not(.active) {
    background: rgba(255,255,255,0.04);
    color: var(--text-primary);
}

/* Table */
.rl-card {
    background: rgba(13,15,20,0.6);
    border: 1px solid rgba(201,168,76,0.15);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.35);
    backdrop-filter: blur(8px);
}
.rl-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.rl-card-title {
    font-family: 'Cinzel', serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--gold-400);
    letter-spacing: 0.5px;
}
.rl-table {
    width: 100%;
    border-collapse: collapse;
}
.rl-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    font-family: 'Rajdhani', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-secondary);
    border-bottom: 1px solid rgba(201,168,76,0.2);
    background: rgba(255,255,255,0.01);
}
.rl-table td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 13px;
    color: var(--text-primary);
    vertical-align: middle;
}
.rl-table tr:hover td {
    background: rgba(255,255,255,0.015);
}
.rl-table tr:last-child td {
    border-bottom: none;
}

/* Rank medal badges */
.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    font-weight: 800;
    font-size: 14px;
    font-family: 'Rajdhani', sans-serif;
    flex-shrink: 0;
}
.rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; box-shadow: 0 0 12px rgba(255,215,0,0.4); }
.rank-2 { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); color: #000; box-shadow: 0 0 8px rgba(192,192,192,0.3); }
.rank-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); color: #fff; box-shadow: 0 0 8px rgba(205,127,50,0.3); }
.rank-other { background: rgba(255,255,255,0.06); color: var(--text-secondary); border: 1px solid rgba(255,255,255,0.1); }

.score-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 800;
    font-family: 'Rajdhani', sans-serif;
    background: rgba(201,168,76,0.12);
    color: var(--gold-400);
    border: 1px solid rgba(201,168,76,0.25);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state svg {
    opacity: 0.3;
    margin-bottom: 16px;
}
.empty-state p {
    font-size: 14px;
    margin: 0;
}

/* Team member rows */
.team-members-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 0;
}
.team-member-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 10px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 6px;
    font-size: 12px;
}
.team-member-row:not(:last-child) {
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.team-member-name {
    font-weight: 700;
    color: var(--text-primary);
    min-width: 120px;
}
.team-member-id {
    font-family: monospace;
    font-size: 11px;
    color: var(--gold-400);
    font-weight: 700;
    background: rgba(201,168,76,0.08);
    padding: 2px 6px;
    border-radius: 3px;
    border: 1px solid rgba(201,168,76,0.18);
}
.team-member-club {
    color: var(--text-secondary);
    flex: 1;
    font-size: 11px;
}
.team-member-score {
    font-weight: 800;
    color: var(--gold-300, #f0c866);
    font-family: 'Rajdhani', sans-serif;
    font-size: 13px;
    min-width: 50px;
    text-align: right;
}

.print-only-header {
  display: none;
}

/* Print overrides */
@media print {
  .admin-sidebar, .admin-header, .admin-page-header, .rl-toolbar, .print-hide { display: none !important; }
  .admin-main { margin: 0 !important; padding: 0 !important; }
  .admin-wrapper { display: block !important; }
  .print-only-header { display: block !important; margin-bottom: 16px !important; text-align: center !important; }
  .rl-card { box-shadow: none !important; border: 1px solid #000000 !important; border-radius: 0 !important; }
  .rl-table { width: 100% !important; table-layout: auto !important; border-collapse: collapse !important; word-wrap: break-word !important; }
  .rl-table th, .rl-table td { color: #000000 !important; border: 1px solid #000000 !important; border-color: #000000 !important; white-space: normal !important; word-break: normal !important; padding: 6px 8px !important; }
  .rl-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
  .rl-table thead { display: table-header-group !important; }
  .rank-badge { border: 1px solid #000000 !important; }
  .score-pill { background: transparent !important; color: #000000 !important; border: 1px solid #000000 !important; }
  body { background: #ffffff !important; }
}
</style>

<!-- Page Header -->
<div class="admin-page-header print-hide">
  <div>
    <h1>Rank List</h1>
    <p>Individual and team event rankings based on entered scores</p>
  </div>
  <?php if (!empty($error)): ?>
  <div class="alert alert-danger print-hide" style="background:#e74c3c; color:#fff; padding:12px 18px; border-radius:6px; margin-bottom:15px; font-weight:600; font-size:14px; width:100%;">
    ⚠️ Error loading rank list: <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($rankData) && $rankMode === 'individual'): ?>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <?php if ($selEvent && $selCategory && $rankType !== 'preliminary'): ?>
      <button onclick="generateCertificates()" class="btn" style="width:auto;padding:10px 20px;font-size:13px;background:var(--gold-500);color:#000;font-weight:700;display:inline-flex;align-items:center;gap:6px;border:none;">
        🎓 Generate Certificates
      </button>
      <button onclick="deleteCertificates()" class="btn btn-danger" style="width:auto;padding:10px 20px;font-size:13px;background:#e74c3c;color:#fff;font-weight:700;display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer;">
        🗑️ Delete Certificates
      </button>
    <?php endif; ?>
    <button onclick="window.print()" class="btn" style="width:auto;padding:10px 20px;font-size:13px;display:inline-flex;align-items:center;gap:8px;background:var(--gold-500);color:#000;font-weight:700;border:none;cursor:pointer;border-radius:4px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="13" width="12" height="9"/></svg>
      Print Rank List
    </button>
  </div>
  <?php elseif (!empty($teamRankData) && $rankMode === 'team'): ?>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="window.print()" class="btn" style="width:auto;padding:10px 20px;font-size:13px;display:inline-flex;align-items:center;gap:8px;background:var(--gold-500);color:#000;font-weight:700;border:none;cursor:pointer;border-radius:4px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="13" width="12" height="9"/></svg>
      Print Rank List
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- Toolbar -->
<form method="GET" action="rank_list.php" class="rl-toolbar print-hide" id="filterForm">

  <!-- Individual / Team Mode Toggle -->
  <div class="rl-type-tabs">
    <button type="button"
      class="rl-type-tab <?= $rankMode === 'individual' ? 'active' : '' ?>"
      onclick="submitRankMode('individual')">
      🎯 Individual
    </button>
    <button type="button"
      class="rl-type-tab <?= $rankMode === 'team' ? 'active' : '' ?>"
      onclick="submitRankMode('team')">
      👥 Team
    </button>
  </div>

  <?php if ($rankMode === 'individual'): ?>
  <!-- Preliminary / Overall Toggle — Individual only -->
  <div class="rl-type-tabs">
    <button type="button"
      class="rl-type-tab <?= $rankType === 'preliminary' ? 'active' : '' ?>"
      onclick="submitRankType('preliminary')">
      📋 Preliminary
    </button>
    <button type="button"
      class="rl-type-tab <?= $rankType === 'overall' ? 'active' : '' ?>"
      onclick="submitRankType('overall')">
      🏆 Overall
    </button>
  </div>
  <?php endif; ?>

  <div class="input-wrap" style="min-width:320px;">
    <select name="event_name" id="eventDropdown" style="height:38px;padding-top:0;padding-bottom:0;" onchange="this.form.submit()">
      <option value="">— Select Event —</option>

      <?php
        $matchedAnySelected = false;
        $checkOptionSelected = function(string $compKey, string $optEvt, string $optCat, string $optBaseType = '') use (&$matchedAnySelected, $selEventRaw, $selEvent, $selCategory, $selBaseType): string {
            if ($matchedAnySelected) return '';

            $isMatch = false;
            // 1. Exact match on raw selection (e.g. 'M-02||ISSF')
            if (!empty($selEventRaw) && ($selEventRaw === $compKey || $selEventRaw === $optEvt)) {
                $isMatch = true;
            }
            // 2. Exact match on $selEvent . '||' . $selCategory
            elseif (!empty($selEvent) && !empty($selCategory) && ($selEvent . '||' . $selCategory) === $compKey) {
                $isMatch = true;
            }
            // 3. Exact match on event code or full name AND category
            elseif (!empty($selEvent) && strcasecmp($optEvt, $selEvent) === 0) {
                if (empty($selCategory) || empty($optCat) || strcasecmp($optCat, $selCategory) === 0) {
                    $isMatch = true;
                }
            }
            // 4. Exact match on $compKey matching $selEvent
            elseif (!empty($selEvent) && strcasecmp($compKey, $selEvent) === 0) {
                $isMatch = true;
            }
            // 5. Fallback match on base type
            elseif (!empty($selBaseType) && !empty($optBaseType) && $optBaseType === $selBaseType) {
                $isMatch = true;
            }

            if ($isMatch) {
                $matchedAnySelected = true;
                return 'selected';
            }
            return '';
        };
      ?>

      <?php if ($rankMode === 'individual' && $rankType === 'preliminary'): ?>
        <!-- Preliminary Mode Dropdown (matches lane_allocations.php and start_sheet.php) -->
        <?php if (!empty($prelimIssfDropdown)): ?>
          <optgroup label="🏆 ISSF Category">
            <?php foreach ($prelimIssfDropdown as $compKey => $label):
              $selected = (!$matchedAnySelected && ($selEventRaw === $compKey || $selBaseType === $compKey)) ? 'selected' : '';
              if ($selected === 'selected') $matchedAnySelected = true;
            ?>
              <option value="<?= htmlspecialchars($compKey) ?>" data-cat="ISSF" <?= $selected ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if (!empty($prelimNrDropdown)): ?>
          <optgroup label="🎯 National Rule (NR) Category">
            <?php foreach ($prelimNrDropdown as $compKey => $label):
              $selected = (!$matchedAnySelected && ($selEventRaw === $compKey || $selBaseType === $compKey)) ? 'selected' : '';
              if ($selected === 'selected') $matchedAnySelected = true;
            ?>
              <option value="<?= htmlspecialchars($compKey) ?>" data-cat="NR" <?= $selected ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>

      <?php else: ?>
        <!-- Team Rank & Individual Overall Mode Dropdown (matches team_events.php) -->
        <?php if (!empty($teamIssfDropdown)): ?>
          <optgroup label="🏆 ISSF Category">
            <?php foreach ($teamIssfDropdown as $compKey => $label):
              $optEvt = str_contains($compKey, '||') ? explode('||', $compKey, 2)[0] : $compKey;
              $optCat = str_contains($compKey, '||') ? explode('||', $compKey, 2)[1] : '';
              $optBaseType = getBackendEventBaseType($optEvt);
              if (!empty($optBaseType) && !empty($optCat)) {
                  $optBaseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $optBaseType) . '_' . strtolower($optCat);
              }
              $selected = $checkOptionSelected($compKey, $optEvt, $optCat, $optBaseType);
            ?>
              <option value="<?= htmlspecialchars($compKey) ?>" data-cat="<?= htmlspecialchars($optCat) ?>" <?= $selected ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if (!empty($teamNrDropdown)): ?>
          <optgroup label="🎯 National Rule (NR) Category">
            <?php foreach ($teamNrDropdown as $compKey => $label):
              $optEvt = str_contains($compKey, '||') ? explode('||', $compKey, 2)[0] : $compKey;
              $optCat = str_contains($compKey, '||') ? explode('||', $compKey, 2)[1] : '';
              $optBaseType = getBackendEventBaseType($optEvt);
              if (!empty($optBaseType) && !empty($optCat)) {
                  $optBaseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $optBaseType) . '_' . strtolower($optCat);
              }
              $selected = $checkOptionSelected($compKey, $optEvt, $optCat, $optBaseType);
            ?>
              <option value="<?= htmlspecialchars($compKey) ?>" data-cat="<?= htmlspecialchars($optCat) ?>" <?= $selected ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if (!empty($teamOtherDropdown)): ?>
          <optgroup label="♿ Para / Deaf / Other Category">
            <?php foreach ($teamOtherDropdown as $compKey => $label):
              $optEvt = str_contains($compKey, '||') ? explode('||', $compKey, 2)[0] : $compKey;
              $optCat = str_contains($compKey, '||') ? explode('||', $compKey, 2)[1] : '';
              $optBaseType = getBackendEventBaseType($optEvt);
              if (!empty($optBaseType) && !empty($optCat)) {
                  $optBaseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $optBaseType) . '_' . strtolower($optCat);
              }
              $selected = $checkOptionSelected($compKey, $optEvt, $optCat, $optBaseType);
            ?>
              <option value="<?= htmlspecialchars($compKey) ?>" data-cat="<?= htmlspecialchars($optCat) ?>" <?= $selected ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
      <?php endif; ?>
    </select>
  </div>

  <!-- Hidden fields -->
  <input type="hidden" name="rank_type" id="hiddenRankType"  value="<?= htmlspecialchars($rankType) ?>">
  <input type="hidden" name="rank_mode" id="hiddenRankMode"  value="<?= htmlspecialchars($rankMode) ?>">
  <input type="hidden" name="relay"     id="hiddenRelay"     value="<?= htmlspecialchars((string)$selRelay) ?>">
  <input type="hidden" name="category"  id="hiddenCategory"  value="<?= htmlspecialchars($selCategory) ?>">

  <!-- Date filter — Individual Preliminary only -->
  <?php if ($rankMode === 'individual' && $rankType === 'preliminary' && !empty($availDates)): ?>
  <div class="input-wrap" style="min-width:170px;">
    <select name="date" class="searchable-select" style="height:38px;padding-top:0;padding-bottom:0;" onchange="document.getElementById('hiddenRelay').value = '0'; this.form.submit()">
      <option value="">— All Dates —</option>
      <?php foreach ($availDates as $d): ?>
        <option value="<?= $d ?>" <?= $selDate === $d ? 'selected' : '' ?>>
          <?= date('d M Y', strtotime($d)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php elseif ($rankMode === 'individual' && $rankType === 'preliminary'): ?>
    <input type="hidden" name="date" value="">
  <?php endif; ?>

  <!-- Relay selector dropdown — Individual Preliminary only -->
  <?php if ($rankMode === 'individual' && $rankType === 'preliminary' && !empty($selDate) && !empty($availRelays)): ?>
  <div class="input-wrap" style="min-width:160px;">
    <select name="relay_select" class="searchable-select" style="height:38px;padding-top:0;padding-bottom:0;" onchange="submitRelay(parseInt(this.value))">
      <option value="0" <?= $selRelay === 0 ? 'selected' : '' ?>>— All Relays —</option>
      <?php foreach ($availRelays as $rNo): ?>
        <option value="<?= (int)$rNo ?>" <?= $selRelay === (int)$rNo ? 'selected' : '' ?>>
          Relay <?= htmlspecialchars((string)$rNo) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

</form>

<!-- ── INDIVIDUAL RANK TABLE ── -->
<?php if ($rankMode === 'individual'): ?>
<?php if (!empty($rankData)): ?>
  <?php
    $typeLabel = $rankType === 'preliminary'
      ? (($selRelay > 0 ? '📋 Preliminary Lane List — ' : '📋 Preliminary Rank — ') . ($selDate ? date('d M Y', strtotime($selDate)) : '') . ($selRelay > 0 ? ' (Relay ' . $selRelay . ')' : ''))
      : '🏆 Overall Rank List';
  ?>
  <div class="print-only-header">
    <div style="font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: #000; font-family: 'Cinzel', 'Rajdhani', sans-serif;">
      Saragarhi Shooting Academy
    </div>
    <div style="font-size: 15px; font-weight: 700; color: #222; margin-top: 4px; font-family: 'Inter', sans-serif;">
      <?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?>
    </div>
    <div style="width: 100%; border-bottom: 2px solid #000; margin-top: 10px; margin-bottom: 12px;"></div>
  </div>
  <div class="rl-card">
    <div class="rl-card-header">
      <span class="rl-card-title"><?= $typeLabel ?></span>
      <span style="font-size:12px; color:var(--text-secondary);">
        <?= htmlspecialchars($eventLabel) ?> &bull; <?= count($rankData) ?> competitor(s)
      </span>
    </div>
  <?php
    // Detect Centre Fire Pistol (has 12 series: 6 Precision + 6 Duelling)
    $isCFP = !empty($selEvent) && (
        stripos($EVENTS_MAPPING[$selEvent] ?? '', 'centre fire pistol') !== false ||
        stripos($EVENTS_MAPPING[$selEvent] ?? '', 'center fire pistol') !== false
    );

    $getSeriesValsScreen = function($seriesJson) use ($isCFP) {
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
  ?>
    <div style="overflow-x:auto;">
      <table class="rl-table">
        <thead>
          <tr>
            <th style="width:45px; text-align:center;" rowspan="2">SrNo</th>
            <th style="width:45px; text-align:center;" rowspan="2">Lane</th>
            <th style="width:75px; text-align:center;" rowspan="2">Comp No</th>
            <th rowspan="2">Shooter Name</th>
<?php if ($rankType === 'preliminary'): ?>
            <th style="width:115px; text-align:center;" rowspan="2">Event No</th>
            <?php endif; ?>
            <th style="width:85px; text-align:center;" rowspan="2">State / Club</th>
            <?php if ($isCFP): ?>
            <th style="text-align:center;" colspan="6">Precision</th>
            <th style="text-align:center; width:50px;" rowspan="2">Prec<br>Total</th>
            <th style="text-align:center;" colspan="6">Duelling</th>
            <th style="text-align:center; width:50px;" rowspan="2">Duel<br>Total</th>
            <?php else: ?>
            <th style="text-align:center;" colspan="6">Series</th>
            <?php endif; ?>
            <th style="width:55px; text-align:center;" rowspan="2">Penalty</th>
            <th style="width:75px; text-align:right;" rowspan="2">Total</th>
            <th style="width:45px; text-align:center;" rowspan="2">Rem</th>
            <?php if ($rankType !== 'preliminary'): ?>
            <th style="width:130px; text-align:center;" class="print-hide" rowspan="2">Certificate</th>
            <?php endif; ?>
          </tr>
          <tr>
            <?php if ($isCFP): ?>
            <?php for ($si = 1; $si <= 12; $si++): ?>
            <th style="width:26px; text-align:center; padding:3px 1px; font-size:10px;"><?= $si ?></th>
            <?php if ($si === 6): ?><th style="width:1px;"></th><?php endif; ?>
            <?php endfor; ?>
            <?php else: ?>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">1</th>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">2</th>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">3</th>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">4</th>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">5</th>
            <th style="width:28px; text-align:center; padding:3px 2px; font-size:11px;">6</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rankData as $r):
            $rankClass = ($rankType === 'preliminary')
              ? 'rank-other'
              : match($r['rank']) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
            $badgeText = (string)$r['rank'];
            $laneText = $r['lane_no'] ? (string)$r['lane_no'] : '—';
            $bibText = formatBibNo($r['reg_id'] ?? '', $r['display_bib'] ?? '');
            $evtNo = resolveEventCode($r['event_name'] ?? $selEvent, $r['event_code'] ?? null, $r['match_no'] ?? null);
            
            $clubVal = !empty(trim((string)($r['club_name'] ?? ''))) ? trim($r['club_name']) : (!empty(trim((string)($r['display_club'] ?? ''))) ? trim($r['display_club']) : '');
            if ($clubVal === '—' || $clubVal === '-') $clubVal = '';

            $distVal = !empty(trim((string)($r['district'] ?? ''))) ? trim($r['district']) : '';
            if ($distVal === '—' || $distVal === '-') $distVal = '';

            $stateText = !empty($clubVal) ? $clubVal : (!empty($distVal) ? $distVal : '—');
            $sVals = $getSeriesValsScreen($r['series_data'] ?? null);
            $penaltyText = !empty($r['penalty_val']) ? $r['penalty_val'] : '0';
            $totalText = !empty($r['grand_total_val']) ? $r['grand_total_val'] : '0-0x';
            $remarksText = !empty($r['remarks']) ? ($r['remarks'] === 'Completed' ? 'C' : $r['remarks']) : 'C';
          ?>
          <tr>
            <td style="text-align:center;">
              <span class="rank-badge <?= $rankClass ?>"><?= $badgeText ?></span>
            </td>
            <td style="text-align:center; font-weight:700;">
              <?= htmlspecialchars($laneText) ?>
            </td>
            <td style="color:#ffffff !important; font-weight:700; font-family:monospace; font-size:13px; text-align:center;">
              <?= htmlspecialchars($bibText) ?>
            </td>
            <td>
              <?php if (!empty($r['display_name']) && $r['display_name'] !== '—'): ?>
                <div style="font-weight:700; font-size:13px; text-transform:uppercase;"><?= htmlspecialchars(strtoupper($r['display_name'])) ?></div>
                <?php 
                  $displaySubId = formatBibNo((string)($r['reg_id'] ?? ''));
                  if (!empty($displaySubId) && $displaySubId !== '—' && $displaySubId !== $r['display_bib']): 
                ?>
                  <div style="font-size:11px; color:var(--text-muted); font-family:monospace;">(<?= htmlspecialchars($displaySubId) ?>)</div>
                <?php endif; ?>
              <?php else: ?>
                <div style="color:var(--text-muted); font-style:italic;">—</div>
              <?php endif; ?>
            </td>
            <?php if ($rankType === 'preliminary'): ?>
            <td style="text-align:center; font-weight:700; font-size:12px; color:var(--gold-400);">
              <?= htmlspecialchars((string)($r['all_event_nos'] ?? $evtNo ?? '—')) ?>
            </td>
            <?php endif; ?>
            <td style="text-align:center; color:var(--text-secondary); font-size:12px;">
              <?= htmlspecialchars(strtoupper($stateText)) ?>
            </td>
            <?php if ($isCFP): ?>
            <?php for ($si = 0; $si < 6; $si++): ?>
            <td style="text-align:center; font-size:11px;"><?= htmlspecialchars((string)$sVals[$si]) ?></td>
            <?php endfor; ?>
            <td style="text-align:center; font-weight:700; font-size:11px; color:var(--gold-400); border-left:1px solid rgba(255,255,255,0.15);"><?= htmlspecialchars((string)($sVals['precision_total'] ?? '0')) ?></td>
            <?php for ($si = 6; $si < 12; $si++): ?>
            <td style="text-align:center; font-size:11px;<?= $si === 6 ? ' border-left:1px solid rgba(255,255,255,0.15);' : '' ?>"><?= htmlspecialchars((string)$sVals[$si]) ?></td>
            <?php endfor; ?>
            <td style="text-align:center; font-weight:700; font-size:11px; color:var(--gold-400); border-left:1px solid rgba(255,255,255,0.15);"><?= htmlspecialchars((string)($sVals['duelling_total'] ?? '0')) ?></td>
            <?php else: ?>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[0]) ?></td>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[1]) ?></td>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[2]) ?></td>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[3]) ?></td>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[4]) ?></td>
            <td style="text-align:center; font-size:12px;"><?= htmlspecialchars((string)$sVals[5]) ?></td>
            <?php endif; ?>
            <td style="text-align:center; font-weight:600; font-size:12px;">
              <?= htmlspecialchars((string)$penaltyText) ?>
            </td>
            <td style="text-align:right; font-weight:800; font-size:13px; color:var(--gold-300);">
              <?= htmlspecialchars((string)$totalText) ?>
            </td>
            <td style="text-align:center; font-weight:700; font-size:12px;">
              <?= htmlspecialchars((string)$remarksText) ?>
            </td>
            <?php if ($rankType !== 'preliminary'): ?>
            <td style="text-align:center; white-space:nowrap;" class="print-hide">
              <?php if ($r['user_id'] !== null): ?>
                <button type="button" onclick="viewCertificate('generate_certificate.php?event_name=<?= urlencode($selEvent) ?>&category=<?= urlencode($selCategory) ?>&user_id=<?= $r['user_id'] ?>&preview=1', '<?= htmlspecialchars(addslashes($r['display_name'])) ?>')" class="btn btn-sm" style="padding: 4px 10px; font-size: 11px; background: #2980b9; color: #fff; border: none; border-radius: 4px; display: inline-block; font-weight: 600; cursor: pointer;">👁️ View</button>
                <?php 
                  $isGenerated = (!empty($r['certificate_path']) && strtolower(pathinfo($r['certificate_path'], PATHINFO_EXTENSION)) === 'pdf');
                  if ($isGenerated): 
                ?>
                  <a href="../<?= htmlspecialchars($r['certificate_path']) ?>" download class="btn btn-sm" style="padding: 3px 10px; font-size: 11px; background: #27ae60; color: #fff; border: 1px solid #27ae60; border-radius: 4px; display: inline-block; font-weight: 600; text-decoration: none; margin-left: 5px;">📥 Download</a>
                <?php else: ?>
                  <a href="generate_certificate.php?event_name=<?= urlencode($selEvent) ?>&category=<?= urlencode($selCategory) ?>&user_id=<?= $r['user_id'] ?>&download=1" target="_blank" class="btn btn-sm" style="padding: 3px 10px; font-size: 11px; background: #27ae60; color: #fff; border: 1px solid #27ae60; border-radius: 4px; display: inline-block; font-weight: 600; text-decoration: none; margin-left: 5px;" title="Generates and downloads PDF on the fly">📥 Download</a>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($selEvent && $selCategory): ?>
  <div class="rl-card">
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>
      </svg>
      <p>
        <?php if ($rankType === 'preliminary' && !$selDate): ?>
          Please select a date to view the preliminary rank list.
        <?php elseif ($rankType === 'preliminary' && $selRelay === 0): ?>
          Please select a relay to view the preliminary list.
        <?php else: ?>
          No scored entries found for this event yet.
        <?php endif; ?>
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="rl-card">
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>
      </svg>
      <p>Select an event above to view the individual rank list.</p>
    </div>
  </div>
<?php endif; ?>
<?php endif; /* end individual mode */ ?>


<!-- ── TEAM RANK TABLE ── -->
<?php if ($rankMode === 'team'): ?>
<?php if (!empty($teamRankData)): ?>
  <div class="print-only-header">
    <div style="font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: #000; font-family: 'Cinzel', 'Rajdhani', sans-serif;">
      Saragarhi Shooting Academy
    </div>
    <div style="font-size: 15px; font-weight: 700; color: #222; margin-top: 4px; font-family: 'Inter', sans-serif;">
      <?= htmlspecialchars($activeChampionship['championship_name'] ?? '51st Tamil Nadu State Shooting Championship') ?>
    </div>
    <div style="width: 100%; border-bottom: 2px solid #000; margin-top: 10px; margin-bottom: 12px;"></div>
  </div>
  <div class="rl-card">
    <div class="rl-card-header">
      <span class="rl-card-title">👥 Team Overall Rank List</span>
      <span style="font-size:12px; color:var(--text-secondary);">
        <?= htmlspecialchars($eventLabel) ?> &bull; <?= count($teamRankData) ?> team(s)
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table class="rl-table">
        <thead>
          <tr>
            <th style="width:60px; text-align:center;">Rank</th>
            <th>Team Name</th>
            <th>Members</th>
            <th style="text-align:center; min-width:110px;">Total Score</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teamRankData as $team):
            $rankClass = match($team['rank']) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
          ?>
          <tr>
            <td style="text-align:center; vertical-align:top; padding-top:18px;">
              <span class="rank-badge <?= $rankClass ?>"><?= $team['rank'] ?></span>
            </td>
            <td style="vertical-align:top; padding-top:14px;">
              <div style="font-weight:700; font-size:15px; color:var(--gold-400); letter-spacing:0.5px;">
                <?= htmlspecialchars($team['team_name']) ?>
              </div>
              <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                <?= count($team['members']) ?> member<?= count($team['members']) !== 1 ? 's' : '' ?>
              </div>
            </td>
            <td>
              <div class="team-members-list">
                <?php foreach ($team['members'] as $m): ?>
                <div class="team-member-row">
                  <span class="team-member-name"><?= htmlspecialchars($m['shooter_name']) ?></span>
                  <span class="team-member-id"><?= htmlspecialchars($m['enrollment_id']) ?></span>
                  <span class="team-member-club"><?= htmlspecialchars($m['club_name']) ?></span>
                  <span class="team-member-score"><?= number_format($m['score'], 2) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td style="text-align:center; vertical-align:top; padding-top:18px;">
              <span class="score-pill" style="font-size:15px; padding: 6px 14px;">
                <?= number_format($team['total_score'], 2) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif (!empty($selEvent)): ?>
  <div class="rl-card">
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
      </svg>
      <p>No teams have been configured for this event yet.<br>
        <a href="team_events.php?event_name=<?= urlencode($selEvent) ?>&category=<?= urlencode($selCategory) ?>" style="color:var(--gold-400);">Go to Team Events →</a>
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="rl-card">
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
      </svg>
      <p>Select an event above to view the team rank list.</p>
    </div>
  </div>
<?php endif; ?>
<?php endif; /* end team mode */ ?>
<script src="js/searchable_select.js?v=<?= time() ?>"></script>
<script>
function submitRankMode(mode) {
    document.getElementById('hiddenRankMode').value = mode;
    // When switching to team, reset rank_type to overall (teams only have overall)
    document.getElementById('hiddenRankType').value = 'overall';
    document.getElementById('hiddenRelay').value = '0';
    document.getElementById('filterForm').submit();
}

function submitRankType(type) {
    document.getElementById('hiddenRankType').value = type;
    document.getElementById('hiddenRelay').value = '0';
    document.getElementById('filterForm').submit();
}

function submitRelay(relay) {
    document.getElementById('hiddenRelay').value = relay;
    document.getElementById('filterForm').submit();
}

// Event dropdown: populate hidden category on change
const eventDropdown = document.getElementById('eventDropdown');
if (eventDropdown) {
    eventDropdown.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const hiddenCat = document.getElementById('hiddenCategory');
        if (hiddenCat) {
            hiddenCat.value = opt ? (opt.getAttribute('data-cat') || '') : '';
        }
        const dateSelect = document.querySelector('select[name="date"]');
        if (dateSelect) dateSelect.value = '';
        const hiddenRelay = document.getElementById('hiddenRelay');
        if (hiddenRelay) hiddenRelay.value = '0';
        this.form.submit();
    });

    if (typeof autoInitSearchableSelects === 'function') {
        autoInitSearchableSelects();
    } else if (typeof initSearchableSelect === 'function' && typeof eventDropdown !== 'undefined' && eventDropdown) {
        initSearchableSelect(eventDropdown, { placeholder: '🔍 Search or choose event…', allLabel: '— Select Event —' });
    }
}

function generateCertificates() {
    const eventName = <?= json_encode($selEvent) ?>;
    const category = <?= json_encode($selCategory) ?>;
    if (!eventName || !category) {
        alert('Please select an event and category first.');
        return;
    }
    window.open(`generate_certificate.php?event_name=${encodeURIComponent(eventName)}&category=${encodeURIComponent(category)}`, '_blank', 'width=950,height=800');
}

async function deleteCertificates() {
    if (!confirm('Are you sure you want to delete all generated certificates for this event and category?')) {
        return;
    }
    const eventName = <?= json_encode($selEvent) ?>;
    const category = <?= json_encode($selCategory) ?>;
    
    const fd = new FormData();
    fd.append('event_name', eventName);
    fd.append('category', category);
    
    try {
        const resp = await fetch('actions/delete_certificate.php', { method: 'POST', body: fd });
        const res = await resp.json();
        alert(res.message);
        if (res.success) {
            window.location.reload();
        }
    } catch (e) {
        alert('Network error.');
    }
}



function viewCertificate(path, name) {
    const modal    = document.getElementById('certModal');
    const title    = document.getElementById('certModalTitle');
    const body     = document.getElementById('certModalBody');
    const download = document.getElementById('certModalDownload');
    const external = document.getElementById('certModalExternal');

    title.textContent = '📜  ' + name;
    if (external) {
        external.href = path;
    }

    if (path.includes('generate_certificate.php')) {
        download.hidden = true;
        download.href   = '#';
    } else {
        download.hidden = false;
        download.href   = path;
    }

    body.innerHTML = '<div style="color:#c9a84c; text-align:center; padding:60px; font-family:\'Rajdhani\',sans-serif; font-size:18px; font-weight:700; width:100%;">⏳ Loading Certificate...</div>';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (path.includes('generate_certificate.php')) {
        fetch(path)
            .then(response => {
                if (!response.ok) throw new Error('HTTP status ' + response.status);
                return response.text();
            })
            .then(html => {
                body.innerHTML = html;
            })
            .catch(err => {
                body.innerHTML = `
                    <div style="text-align:center; padding:40px; color:#fff; font-family:'Inter',sans-serif; width:100%;">
                        <div style="font-size:40px; margin-bottom:10px;">⚠️</div>
                        <h3 style="color:#e74c3c; margin-bottom:10px;">Unable to inline preview</h3>
                        <p style="color:#aaa; font-size:14px; margin-bottom:20px;">${err.message}</p>
                        <a href="${path}" target="_blank" style="display:inline-block; padding:10px 20px; background:#3498db; color:#fff; border-radius:6px; text-decoration:none; font-weight:bold;">Open Certificate in New Window ↗️</a>
                    </div>`;
            });
    } else {
        body.innerHTML = '';
        const iframe   = document.createElement('iframe');
        iframe.src     = path;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        body.appendChild(iframe);
    }
}

function closeCertModal() {
    const modal = document.getElementById('certModal');
    modal.classList.remove('open');
    document.getElementById('certModalBody').innerHTML = ''; // stop content/iframe
    document.body.style.overflow = '';
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCertModal();
});
</script>

<!-- Certificate Full-Screen Preview Modal -->
<div id="certModal" role="dialog" aria-modal="true" aria-labelledby="certModalTitle">
  <div id="certModalHeader">
    <span id="certModalTitle">Certificate Preview</span>
    <div id="certModalActions">
      <a id="certModalExternal" href="#" target="_blank" class="btn btn-sm" style="padding: 4px 12px; font-size: 12px; background: #2980b9; color: #fff; border-radius: 4px; text-decoration: none; font-weight: 600; display: inline-block;">↗️ New Window</a>
      <a id="certModalDownload" href="#" download hidden style="padding: 4px 12px; font-size: 12px; background: #27ae60; color: #fff; border-radius: 4px; text-decoration: none; font-weight: 600; display: inline-block;">📥 Download File</a>
      <button id="certModalClose" onclick="closeCertModal()" aria-label="Close" title="Close (Esc)">&times;</button>
    </div>
  </div>
  <div id="certModalBody"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
