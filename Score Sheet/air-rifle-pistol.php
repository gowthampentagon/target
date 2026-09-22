<?php
// Score Sheet/air-rifle-pistol.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

$allocId = (int)($_GET['alloc_id'] ?? 0);
if ($allocId <= 0) {
    die("Invalid allocation ID.");
}

$backUrl = '../admin/start_sheet.php';
$retParams = [];
if (!empty($_GET['event_select'])) $retParams['event_select'] = $_GET['event_select'];
if (!empty($_GET['date_select'])) $retParams['date_select'] = $_GET['date_select'];
if (!empty($_GET['relay_select'])) $retParams['relay_select'] = $_GET['relay_select'];
if (!empty($retParams)) {
    $backUrl .= '?' . http_build_query($retParams);
}

$pdo = getDB();

// Fetch allocation, event name, category, club name, user first and last name
$stmt = $pdo->prepare("
    SELECT la.*, er.user_id, er.event_name, er.category, r.club_name, r.reg_id AS enrollment_id, r.first_name, r.last_name,
           r.district, er.id AS event_reg_id, er.event_code, er.match_no
    FROM lane_allocations la
    LEFT JOIN event_registrations er ON la.event_reg_id = er.id
    LEFT JOIN registrations r ON er.user_id = r.id
    WHERE la.id = ?
    LIMIT 1
");
$stmt->execute([$allocId]);
$alloc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$alloc) {
    die("Allocation not found.");
}

$fullName = $EVENTS_MAPPING[$alloc['event_name']] ?? $alloc['event_name'];

// Fetch existing scores if any
$scoreStmt = $pdo->prepare("SELECT * FROM score_sheets WHERE lane_alloc_id = ? LIMIT 1");
$scoreStmt->execute([$allocId]);
$scoreRow = $scoreStmt->fetch(PDO::FETCH_ASSOC);

$seriesData = [];
$metaCard = '';
$metaMatch = '';
$totalVal = '0';
$penaltyVal = '0';
$grandTotalVal = '0';
$remarksVal = '';

// Reverse-lookup Event ID from EVENTS_MAPPING using keyword scoring
$getEventId = function(array $alloc, array $eventsMapping, string $defaultCode): string {
    $isLongSlug = function(string $s): bool {
        return strlen($s) > 15
            || preg_match('/\b(CHAMPIONSHIP|INDIVIDUAL|STANDARD|PISTOL|RIFLE|SENIOR|JUNIOR|WOMEN|MEN|AIR)\b/i', $s);
    };

    // 1. match_no — short, clean ID already stored
    $match = trim((string)($alloc['match_no'] ?? ''));
    if (!empty($match) && !preg_match('/^EVT-/i', $match) && !$isLongSlug($match)) {
        return $match;
    }

    // 2. event_code — only if short (not a slug)
    $code = trim((string)($alloc['event_code'] ?? ''));
    if (!empty($code) && !preg_match('/^EVT-/i', $code) && !$isLongSlug($code)) {
        return $code;
    }

    // 3. Keyword-score reverse lookup against EVENTS_MAPPING
    $evt = strtoupper(trim((string)($alloc['event_name'] ?? '')));
    if (!empty($evt)) {
        // Extract an inline ID if present (e.g. "IS-05" within the name)
        if (preg_match('/\b(IS[-_]?\d{1,3}|S[-_]?\d{1,3}|N[-_]?\d{1,3}|R[-_]?\d{1,3})\b/i', $evt, $m)) {
            return strtoupper(str_replace(['_', ' '], '-', $m[1]));
        }

        $keyWords = ['JUNIOR','YOUTH','SENIOR','MASTER','SUPER','MEN','WOMEN',
                     'ISSF','NR','AIR','PISTOL','RIFLE','STANDARD','10M','25M','50M',
                     'SH1','SH2','DEAF','PARA'];
        $evtWords = array_flip(array_filter(explode(' ', preg_replace('/[^A-Z0-9 ]/', ' ', $evt))));

        $best = ''; $bestScore = -999;
        foreach ($eventsMapping as $id => $label) {
            // Only check canonical dash-style IDs (skip duplicates like IS05)
            if (!preg_match('/^[A-Z]+-\d+$/', $id)) continue;
            $labelWords = array_flip(array_filter(explode(' ', strtoupper(preg_replace('/[^A-Z0-9 ]/', ' ', $label)))));
            $score = 0;
            foreach ($keyWords as $kw) {
                $inEvt   = isset($evtWords[$kw]);
                $inLabel = isset($labelWords[$kw]);
                if ($inEvt && $inLabel)   $score += 2;
                elseif ($inEvt !== $inLabel) $score -= 1;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $id; }
        }
        if (!empty($best) && $bestScore > 0) return $best;
    }

    // 4. Return empty — user fills it in manually
    return '';
};

$getWeaponCategoryKey = function(string $eventName) use ($EVENTS_MAPPING): string {
    $full = $EVENTS_MAPPING[$eventName] ?? $eventName;
    $base = getBackendEventBaseType($full);
    if (empty($base)) {
        $base = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($full));
    }
    $baseCore = preg_replace('/_(issf|nr|mqs|nr_mqs|issf_mqs|para|para_deaf)$/i', '', $base);
    return preg_replace('/[^a-zA-Z0-9]/', '', strtolower($baseCore));
};

$isMatchingCategory = function(string $evtName1, string $evtName2) use ($getWeaponCategoryKey, $EVENTS_MAPPING): bool {
    $k1 = $getWeaponCategoryKey($evtName1);
    $k2 = $getWeaponCategoryKey($evtName2);
    if (!empty($k1) && !empty($k2)) {
        if ($k1 === $k2 || strpos($k1, $k2) !== false || strpos($k2, $k1) !== false) {
            return true;
        }
    }
    $u1 = strtoupper($EVENTS_MAPPING[$evtName1] ?? $evtName1);
    $u2 = strtoupper($EVENTS_MAPPING[$evtName2] ?? $evtName2);
    $categories = [
        'STANDARD PISTOL', 'CENTRE FIRE', 'CENTER FIRE', 'SPORTS PISTOL', '25M PISTOL',
        'AIR PISTOL', '10M PISTOL', '50M PISTOL', 'FREE PISTOL',
        'AIR RIFLE', '10M RIFLE', 'RIFLE PRONE', '50M RIFLE', '3 POSITION', '3P'
    ];
    foreach ($categories as $cat) {
        if (strpos($u1, $cat) !== false && strpos($u2, $cat) !== false) {
            return true;
        }
    }
    return false;
};

$eventIdVal = $getEventId($alloc, $EVENTS_MAPPING, 'IS-01');

// Fetch all registered event IDs/codes for this shooter under the same weapon category
$userId = (int)($alloc['user_id'] ?? 0);
$currentEvtName = $alloc['event_name'] ?? '';
$allShooterEventIds = [];

if ($userId > 0 && !empty($currentEvtName)) {
    $stmtAllEvts = $pdo->prepare("
        SELECT er.event_name, er.event_code, er.match_no
        FROM event_registrations er
        WHERE er.user_id = ? AND (er.status IS NULL OR er.status = '' OR LOWER(er.status) IN ('approved', 'active', 'pending'))
        ORDER BY er.id ASC
    ");
    $stmtAllEvts->execute([$userId]);
    $userEvts = $stmtAllEvts->fetchAll(PDO::FETCH_ASSOC);

    foreach ($userEvts as $uEv) {
        $uEvName = $uEv['event_name'];
        if ($isMatchingCategory($currentEvtName, $uEvName)) {
            $eId = $getEventId($uEv, $EVENTS_MAPPING, '');
            if (empty($eId)) {
                $eId = !empty($uEv['event_code']) ? $uEv['event_code'] : (!empty($uEv['match_no']) ? $uEv['match_no'] : $uEvName);
            }
            if (!empty($eId) && !in_array($eId, $allShooterEventIds, true)) {
                $allShooterEventIds[] = $eId;
            }
        }
    }
}

if (!empty($allShooterEventIds)) {
    $eventIdVal = implode(', ', $allShooterEventIds);
}

if ($scoreRow) {
    $seriesData = json_decode($scoreRow['series_data'], true) ?? [];
    $metaCard = !empty($scoreRow['meta_card']) ? $scoreRow['meta_card'] : ($alloc['target_serial_no'] ?? '');
    
    $storedMatch = trim((string)($scoreRow['meta_match'] ?? ''));
    if (!empty($storedMatch) && !preg_match('/^EVT-/i', $storedMatch) && strlen($storedMatch) <= 50 && !preg_match('/\b(CHAMPIONSHIP|INDIVIDUAL|STANDARD|PISTOL|RIFLE)\b/i', $storedMatch)) {
        if (!empty($eventIdVal) && (empty($storedMatch) || in_array($storedMatch, $allShooterEventIds, true) || $storedMatch === ($alloc['event_code'] ?? '') || $storedMatch === ($alloc['match_no'] ?? ''))) {
            $metaMatch = $eventIdVal;
        } else {
            $metaMatch = $storedMatch;
        }
    } else {
        $metaMatch = !empty($eventIdVal) ? $eventIdVal : $eventIdVal;
    }
    $totalVal = !empty($scoreRow['total_val']) ? $scoreRow['total_val'] : '0';
    $penaltyVal = !empty($scoreRow['penalty_val']) ? $scoreRow['penalty_val'] : '0';
    $grandTotalVal = !empty($scoreRow['grand_total_val']) ? $scoreRow['grand_total_val'] : '0';
    $remarksVal = $scoreRow['remarks'] ?? '';
} else {
    $metaCard = $alloc['target_serial_no'] ?? '';
    $metaMatch = $eventIdVal;
}

// Fallback logic to retrieve details from start sheet registration if not overridden on score sheet
$_resolvedName = '';
if (!empty(trim((string)($scoreRow['custom_shooter_name'] ?? '')))) {
    $_resolvedName = $scoreRow['custom_shooter_name'];
} elseif (!empty(trim((string)($alloc['custom_name'] ?? '')))) {
    $_resolvedName = $alloc['custom_name'];
} elseif (!empty(trim(($alloc['first_name'] ?? '') . ($alloc['last_name'] ?? '')))) {
    $_resolvedName = trim(($alloc['first_name'] ?? '') . ' ' . ($alloc['last_name'] ?? ''));
} else {
    // Bib_no fallback: bib_no format is 5XXXX, maps to SSA-XXXX reg_id
    $_bibForLookup = trim((string)($alloc['bib_no'] ?? ''));
    if (!empty($_bibForLookup)) {
        try {
            // Direct lookup first
            $_nameStmt = $pdo->prepare("SELECT first_name, last_name FROM registrations WHERE reg_id = ? LIMIT 1");
            $_nameStmt->execute([$_bibForLookup]);
            $_nameRow = $_nameStmt->fetch(PDO::FETCH_ASSOC);
            if (!$_nameRow && preg_match('/^5(\d+)$/', $_bibForLookup, $_bm)) {
                // bib_no like 51068 -> SSA-1068
                $_nameStmt->execute(['SSA-' . $_bm[1]]);
                $_nameRow = $_nameStmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($_nameRow && !empty(trim(($_nameRow['first_name'] ?? '') . ($_nameRow['last_name'] ?? '')))) {
                $_resolvedName = trim(($_nameRow['first_name'] ?? '') . ' ' . ($_nameRow['last_name'] ?? ''));
            }
        } catch (Exception $e) { /* suppress */ }
    }
}
$valName = $_resolvedName;

$valBib = !empty(trim((string)($scoreRow['custom_bib'] ?? ''))) 
    ? $scoreRow['custom_bib'] 
    : (!empty(trim((string)($alloc['bib_no'] ?? ''))) ? $alloc['bib_no'] : '');

$valCat = !empty(trim((string)($scoreRow['custom_category'] ?? ''))) ? $scoreRow['custom_category'] : ($alloc['category'] ?? '');
$valDetail = !empty(trim((string)($scoreRow['custom_detail'] ?? ''))) ? $scoreRow['custom_detail'] : ($alloc['relay_no'] ?? '');
$valLane = !empty(trim((string)($scoreRow['custom_lane'] ?? ''))) ? $scoreRow['custom_lane'] : ($alloc['lane_no'] > 0 ? $alloc['lane_no'] : '');
$valDate = !empty(trim((string)($scoreRow['custom_date'] ?? ''))) ? $scoreRow['custom_date'] : (!empty($alloc['scheduled_date']) ? date('d-M-Y', strtotime($alloc['scheduled_date'])) : '');
$valTime = !empty(trim((string)($scoreRow['custom_time'] ?? ''))) ? $scoreRow['custom_time'] : (!empty($alloc['start_time']) ? substr($alloc['start_time'], 0, 5) : '');

// Fetch all registered participants for autocomplete suggestions & weapon category event IDs
$regEventsMap = [];
$allRegsStmt = $pdo->prepare("
    SELECT r.reg_id, r.first_name, r.last_name, r.club_name, er.category, er.user_id, er.event_name, er.event_code, er.match_no
    FROM event_registrations er
    LEFT JOIN registrations r ON er.user_id = r.id
    WHERE er.status = 'approved' AND r.reg_id IS NOT NULL AND r.reg_id != ''
    ORDER BY r.reg_id
");
$allRegsStmt->execute();
$allRawRegs = $allRegsStmt->fetchAll(PDO::FETCH_ASSOC);

$allRegs = [];
$seenRegs = [];

foreach ($allRawRegs as $r) {
    $regId = $r['reg_id'];
    if (!isset($seenRegs[$regId])) {
        $seenRegs[$regId] = true;
        $allRegs[] = [
            'reg_id' => $r['reg_id'],
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'club_name' => $r['club_name'],
            'category' => $r['category']
        ];
    }
    
    if ($isMatchingCategory($currentEvtName, $r['event_name'])) {
        $eId = $getEventId($r, $EVENTS_MAPPING, '');
        if (empty($eId)) {
            $eId = !empty($r['event_code']) ? $r['event_code'] : (!empty($r['match_no']) ? $r['match_no'] : $r['event_name']);
        }
        if (!empty($eId)) {
            if (!isset($regEventsMap[$regId])) {
                $regEventsMap[$regId] = [];
            }
            if (!in_array($eId, $regEventsMap[$regId], true)) {
                $regEventsMap[$regId][] = $eId;
            }
        }
    }
}

foreach ($regEventsMap as $k => $v) {
    $regEventsMap[$k] = implode(', ', $v);
}

// Fetch document template customization for score_sheet_air_rifle_pistol
$tplStmt = $pdo->prepare("SELECT canvas_data, background_path FROM document_templates WHERE document_type = 'score_sheet_air_rifle_pistol' LIMIT 1");
$tplStmt->execute();
$tplRow = $tplStmt->fetch(PDO::FETCH_ASSOC);

$tplLogo = 'images/logo.png';
$tplAcademyName = 'TARGET';
$tplTagline = 'Tournament Administration and Registration Gateway for Event Tracking';
$tplBackground = '#0d0e12';

$tplLogoStyle = 'width: 68px; height: 68px;';
$tplAcademyNameStyle = '';
$tplTaglineStyle = '';
$tplLabelStyle = '';

$tplTableStyle = '';
$tplTableHeaderStyle = '';
$tplTableCellStyle = '';
$tplTableInputStyle = '';

$tplNameInputStyle = '';
$tplNameLabelStyle = '';
$tplBibInputStyle = '';
$tplBibLabelStyle = '';
$tplCatInputStyle = '';
$tplCatLabelStyle = '';
$tplDetailInputStyle = '';
$tplDetailLabelStyle = '';
$tplLaneInputStyle = '';
$tplLaneLabelStyle = '';
$tplCardInputStyle = '';
$tplCardLabelStyle = '';
$tplMatchInputStyle = '';
$tplMatchLabelStyle = '';
$tplDateInputStyle = '';
$tplDateLabelStyle = '';
$tplTimeInputStyle = '';
$tplTimeLabelStyle = '';

$tplTableCellStylesCss = '';
$tplTableEl = null;

if ($tplRow) {
    $tplCanvas = json_decode($tplRow['canvas_data'], true);
    if ($tplCanvas) {
        if (!empty($tplCanvas['background'])) {
            $tplBackground = $tplCanvas['background'];
        }
        if (!empty($tplCanvas['elements'])) {
            foreach ($tplCanvas['elements'] as $el) {
                if (($el['type'] ?? '') === 'image') {
                    if (!empty($el['src'])) {
                        $tplLogo = $el['src'];
                    }
                    $w = isset($el['width']) ? $el['width'] . 'px' : '68px';
                    $h = isset($el['height']) ? $el['height'] . 'px' : '68px';
                    $tplLogoStyle = "width: {$w}; height: {$h};";
                }
                
                $buildStyle = function($e) {
                    $s = '';
                    if (!empty($e['fontSize'])) $s .= "font-size: " . $e['fontSize'] . "px !important;";
                    if (!empty($e['color'])) {
                        $c = $e['color'];
                        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c)) {
                            $hex = str_replace('#', '', $c);
                            $r = hexdec(strlen($hex) === 3 ? $hex[0].$hex[0] : substr($hex,0,2));
                            $g = hexdec(strlen($hex) === 3 ? $hex[1].$hex[1] : substr($hex,2,2));
                            $b = hexdec(strlen($hex) === 3 ? $hex[2].$hex[2] : substr($hex,4,2));
                            $lum = ($r * 299 + $g * 587 + $b * 114) / 1000;
                            if ($lum < 160) $c = '#ffffff';
                        }
                        $s .= "color: " . $c . " !important;";
                    }
                    if (!empty($e['fontWeight'])) $s .= "font-weight: " . $e['fontWeight'] . " !important;";
                    if (!empty($e['fontStyle'])) $s .= "font-style: " . $e['fontStyle'] . " !important;";
                    if (!empty($e['fontFamily'])) $s .= "font-family: '" . $e['fontFamily'] . "', sans-serif !important;";
                    if (!empty($e['backgroundColor'])) $s .= "background-color: " . $e['backgroundColor'] . " !important; background: " . $e['backgroundColor'] . " !important;";
                    return $s;
                };
                
                $buildLabelStyle = function($e) {
                    $s = '';
                    $c = $e['color'] ?? '';
                    if (!empty($c) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c)) {
                        $hex = str_replace('#', '', $c);
                        $r = hexdec(strlen($hex) === 3 ? $hex[0].$hex[0] : substr($hex,0,2));
                        $g = hexdec(strlen($hex) === 3 ? $hex[1].$hex[1] : substr($hex,2,2));
                        $b = hexdec(strlen($hex) === 3 ? $hex[2].$hex[2] : substr($hex,4,2));
                        $lum = ($r * 299 + $g * 587 + $b * 114) / 1000;
                        if ($lum < 160) $c = '#94a3b8';
                    }
                    if (!empty($c)) $s .= "color: " . $c . " !important;";
                    if (!empty($e['fontFamily'])) $s .= "font-family: '" . $e['fontFamily'] . "', sans-serif !important;";
                    return $s;
                };

                if (($el['id'] ?? '') === 'text_academy_name') {
                    if (isset($el['content'])) {
                        $tplAcademyName = $el['content'];
                    }
                    $tplAcademyNameStyle = $buildStyle($el);
                }
                if (($el['id'] ?? '') === 'text_tagline') {
                    if (isset($el['content'])) {
                        $tplTagline = $el['content'];
                    }
                    $tplTaglineStyle = $buildStyle($el);
                }
                if (($el['id'] ?? '') === 'text_event_label') {
                    $tplLabelStyle = $buildStyle($el);
                }

                if (($el['type'] ?? '') === 'text' && !empty($el['content'])) {
                    $content = $el['content'];
                    if (strpos($content, '{participant_name}') !== false) {
                        $tplNameInputStyle = $buildStyle($el);
                        $tplNameLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{reg_id}') !== false || strpos($content, '{competitor_no}') !== false) {
                        $tplBibInputStyle = $buildStyle($el);
                        $tplBibLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{category}') !== false) {
                        $tplCatInputStyle = $buildStyle($el);
                        $tplCatLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{relay}') !== false) {
                        $tplDetailInputStyle = $buildStyle($el);
                        $tplDetailLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{lane}') !== false) {
                        $tplLaneInputStyle = $buildStyle($el);
                        $tplLaneLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{card}') !== false) {
                        $tplCardInputStyle = $buildStyle($el);
                        $tplCardLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{match}') !== false) {
                        $tplMatchInputStyle = $buildStyle($el);
                        $tplMatchLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{date}') !== false) {
                        $tplDateInputStyle = $buildStyle($el);
                        $tplDateLabelStyle = $buildLabelStyle($el);
                    }
                    if (strpos($content, '{time}') !== false) {
                        $tplTimeInputStyle = $buildStyle($el);
                        $tplTimeLabelStyle = $buildLabelStyle($el);
                    }
                }

                if (($el['type'] ?? '') === 'table') {
                    $tplTableEl = $el;
                    $bgColor = $el['backgroundColor'] ?? '';
                    $color = $el['color'] ?? '';
                    $fontSize = $el['fontSize'] ?? '';
                    $fontFamily = $el['fontFamily'] ?? '';
                    $borderColor = $el['borderColor'] ?? '';
                    $borderWidth = $el['borderWidth'] ?? '';
                    $borderStyle = $el['borderStyle'] ?? '';
                    $gridStyle = $el['gridStyle'] ?? 'full';

                    $tableCss = '';
                    if (!empty($bgColor)) $tableCss .= "background-color: {$bgColor} !important; background: {$bgColor} !important;";
                    if (!empty($color)) $tableCss .= "color: {$color} !important;";
                    if (!empty($fontFamily)) $tableCss .= "font-family: '{$fontFamily}', sans-serif !important;";
                    if (!empty($fontSize)) $tableCss .= "font-size: {$fontSize}px !important;";

                    $cellBorder = 'border: 1px solid #cccccc;';
                    if ($borderStyle !== 'none' && $borderWidth !== '') {
                        $bw = (int)$borderWidth;
                        if ($gridStyle === 'full') {
                            $cellBorder = "border: {$bw}px {$borderStyle} {$borderColor} !important;";
                        } elseif ($gridStyle === 'horizontal') {
                            $cellBorder = "border-top: none !important; border-bottom: {$bw}px {$borderStyle} {$borderColor} !important; border-left: none !important; border-right: none !important;";
                        } elseif ($gridStyle === 'vertical') {
                            $cellBorder = "border-top: none !important; border-bottom: none !important; border-left: {$bw}px {$borderStyle} {$borderColor} !important; border-right: {$bw}px {$borderStyle} {$borderColor} !important;";
                        } elseif ($gridStyle === 'outer' || $gridStyle === 'none') {
                            $cellBorder = "border: none !important;";
                        }
                    }
                    
                    $tplTableStyle = $tableCss;
                    if ($gridStyle === 'outer' && $borderStyle !== 'none' && $borderWidth !== '') {
                        $bw = (int)$borderWidth;
                        $tplTableStyle .= " border: {$bw}px {$borderStyle} {$borderColor} !important;";
                    }
                    
                    $tplTableCellStyle = $cellBorder;
                    if (!empty($color)) $tplTableCellStyle .= " color: {$color} !important;";
                    if (!empty($fontFamily)) $tplTableCellStyle .= " font-family: '{$fontFamily}', sans-serif !important;";
                    if (!empty($fontSize)) $tplTableCellStyle .= " font-size: {$fontSize}px !important;";

                    $isDarkTheme = (strtolower(substr(trim($color), 0, 4)) === '#fff' || strtolower(trim($color)) === '#ffffff' || strtolower(trim($color)) === '#ddb25e' || strtolower(trim($color)) === '#c9a84c');
                    $headerBg = $isDarkTheme ? '#c9a84c' : '#f5f5f5';
                    $headerTextColor = $isDarkTheme ? '#000000' : $color;
                    
                    $tplTableHeaderStyle = "background-color: {$headerBg} !important; background: {$headerBg} !important; color: {$headerTextColor} !important; " . $cellBorder;
                    
                    if (!empty($bgColor)) $tplTableInputStyle .= "background-color: {$bgColor} !important; background: {$bgColor} !important;";
                    if (!empty($color)) $tplTableInputStyle .= "color: {$color} !important;";
                    if (!empty($fontFamily)) $tplTableInputStyle .= "font-family: '{$fontFamily}', sans-serif !important;";
                    if (!empty($fontSize)) $tplTableInputStyle .= "font-size: {$fontSize}px !important;";

                    if (!empty($el['rows'])) {
                        foreach ($el['rows'] as $rIdx => $row) {
                            foreach ($row as $cIdx => $cell) {
                                if (is_array($cell) && !empty($cell['style'])) {
                                    $cellCss = '';
                                    if (!empty($cell['style']['backgroundColor'])) {
                                        $color = $cell['style']['backgroundColor'];
                                        $cellCss .= "background-color: {$color} !important; background: {$color} !important;";
                                    }
                                    if (!empty($cell['style']['color'])) {
                                        $color = $cell['style']['color'];
                                        $cellCss .= "color: {$color} !important;";
                                    }
                                    if (!empty($cell['style']['fontFamily'])) {
                                        $ff = $cell['style']['fontFamily'];
                                        $cellCss .= "font-family: '{$ff}', sans-serif !important;";
                                    }
                                    if (!empty($cell['style']['fontSize'])) {
                                        $fs = $cell['style']['fontSize'];
                                        $cellCss .= "font-size: {$fs}px !important;";
                                    }
                                    if (!empty($cell['style']['fontWeight'])) {
                                        $fw = $cell['style']['fontWeight'];
                                        $cellCss .= "font-weight: {$fw} !important;";
                                    }
                                    if (!empty($cell['style']['fontStyle'])) {
                                        $fsVal = $cell['style']['fontStyle'];
                                        $cellCss .= "font-style: {$fsVal} !important;";
                                    }
                                    if (!empty($cellCss)) {
                                        $tplTableCellStylesCss .= ".score-table tbody tr:nth-child(" . ($rIdx + 1) . ") td:nth-child(" . ($cIdx + 1) . ") { {$cellCss} }\n";
                                        $tplTableCellStylesCss .= ".score-table tbody tr:nth-child(" . ($rIdx + 1) . ") td:nth-child(" . ($cIdx + 1) . ") input { {$cellCss} }\n";
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($el['headers'])) {
                        foreach ($el['headers'] as $cIdx => $header) {
                            if (is_array($header) && !empty($header['style'])) {
                                $headerCss = '';
                                if (!empty($header['style']['backgroundColor'])) {
                                    $color = $header['style']['backgroundColor'];
                                    $headerCss .= "background-color: {$color} !important; background: {$color} !important;";
                                }
                                if (!empty($header['style']['color'])) {
                                    $color = $header['style']['color'];
                                    $headerCss .= "color: {$color} !important;";
                                }
                                if (!empty($header['style']['fontFamily'])) {
                                    $ff = $header['style']['fontFamily'];
                                    $headerCss .= "font-family: '{$ff}', sans-serif !important;";
                                }
                                if (!empty($header['style']['fontSize'])) {
                                    $fs = $header['style']['fontSize'];
                                    $headerCss .= "font-size: {$fs}px !important;";
                                }
                                if (!empty($header['style']['fontWeight'])) {
                                    $fw = $header['style']['fontWeight'];
                                    $headerCss .= "font-weight: {$fw} !important;";
                                }
                                if (!empty($header['style']['fontStyle'])) {
                                    $fsVal = $header['style']['fontStyle'];
                                    $headerCss .= "font-style: {$fsVal} !important;";
                                }
                                if (!empty($headerCss)) {
                                    $tplTableCellStylesCss .= ".score-table thead th:nth-child(" . ($cIdx + 1) . ") { {$headerCss} }\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$bodyBgStyle = 'background-color: #0d0e12;';
if (!empty($tplBackground)) {
    if (strpos($tplBackground, '#') === 0 || strpos($tplBackground, 'rgb') === 0) {
        $bodyBgStyle = "background-color: " . $tplBackground . " !important;";
    } else {
        $bodyBgStyle = "background-image: url('../" . $tplBackground . "') !important; background-size: cover; background-position: center; background-attachment: fixed;";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>10M Air Rifle / Air Pistol — Saragarhi Shooting Academy</title>
  <link rel="stylesheet" href="sheet.css">
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body {
      <?= $bodyBgStyle ?>
    }
    <?php
    $isBgDark = true;
    if (!empty($tplBackground) && strpos($tplBackground, '#') === 0) {
        $hex = str_replace('#', '', $tplBackground);
        if (strlen($hex) === 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } elseif (strlen($hex) === 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        } else {
            $r = 13; $g = 14; $b = 18;
        }
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
        $isBgDark = ($brightness < 128);
    }
    
    if (!$isBgDark):
    ?>
    :root {
        --bg:            <?= htmlspecialchars((string)$tplBackground) ?>;
        --paper:         #ffffff;
        --navy:          #111111;
        --border:        rgba(0, 0, 0, 0.12);
        --white:         #f5f6fa;
        --muted:         #5f6c7d;
        --shadow:        0 2px 10px rgba(0,0,0,0.05);
    }
    .field input, .field select, .score-table input {
        color: #111111 !important;
        background: #ffffff !important;
        border-color: rgba(0, 0, 0, 0.2) !important;
    }
    <?php else: ?>
    :root {
        --bg:            <?= htmlspecialchars((string)$tplBackground) ?>;
        --paper:         #16171e;
        --navy:          #ffffff;
        --border:        rgba(255, 255, 255, 0.15);
        --white:         #1d1f27;
        --muted:         #a0aab8;
        --shadow:        none;
    }
    <?php endif; ?>

    <?php if (!empty($tplNameInputStyle)): ?>
    #name { <?= $tplNameInputStyle ?> }
    .field:has(#name) label { <?= $tplNameLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplBibInputStyle)): ?>
    #bib { <?= $tplBibInputStyle ?> }
    .field:has(#bib) label { <?= $tplBibLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplCatInputStyle)): ?>
    #cat { <?= $tplCatInputStyle ?> }
    .field:has(#cat) label { <?= $tplCatLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplDetailInputStyle)): ?>
    #detail { <?= $tplDetailInputStyle ?> }
    .field:has(#detail) label { <?= $tplDetailLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplLaneInputStyle)): ?>
    #lane { <?= $tplLaneInputStyle ?> }
    .field:has(#lane) label { <?= $tplLaneLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplCardInputStyle)): ?>
    #card { <?= $tplCardInputStyle ?> }
    .field:has(#card) label { <?= $tplCardLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplMatchInputStyle)): ?>
    #match { <?= $tplMatchInputStyle ?> }
    .field:has(#match) label { <?= $tplMatchLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplDateInputStyle)): ?>
    #date { <?= $tplDateInputStyle ?> }
    .field:has(#date) label { <?= $tplDateLabelStyle ?> }
    <?php endif; ?>
    <?php if (!empty($tplTimeInputStyle)): ?>
    #time { <?= $tplTimeInputStyle ?> }
    .field:has(#time) label { <?= $tplTimeLabelStyle ?> }
    <?php endif; ?>

    <?= $tplTableCellStylesCss ?>

    <?php if (!empty($tplTableStyle)): ?>
    .score-table {
      <?= $tplTableStyle ?>
    }
    <?php endif; ?>
    <?php if (!empty($tplTableHeaderStyle)): ?>
    .score-table th {
      <?= $tplTableHeaderStyle ?>
    }
    <?php endif; ?>
    <?php if (!empty($tplTableCellStyle)): ?>
    .score-table td {
      <?= $tplTableCellStyle ?>
    }
    <?php endif; ?>
    <?php if (!empty($tplTableInputStyle)): ?>
    .score-table input {
      <?= $tplTableInputStyle ?>
    }
    <?php endif; ?>

    /* High contrast text visibility overrides */
    .field label {
        color: #94a3b8 !important;
        font-weight: 700 !important;
        letter-spacing: 0.5px !important;
        text-transform: uppercase !important;
        opacity: 1 !important;
    }
    .field input, .field select {
        color: #ffffff !important;
        background: #191c28 !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        font-weight: 700 !important;
        opacity: 1 !important;
    }
    .header-text h1 {
        color: #ffffff !important;
        text-shadow: 0 2px 4px rgba(0,0,0,0.5) !important;
        opacity: 1 !important;
    }
    .header-text .tagline {
        color: #fbbf24 !important;
        font-weight: 700 !important;
        opacity: 1 !important;
    }
    .header-text .sheet-label {
        color: #cbd5e1 !important;
        font-weight: 600 !important;
        opacity: 1 !important;
    }
  </style>
  <!-- Include SweetAlert2 for modern UI popups -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="sheet-page">

  <!-- HEADER -->
  <header class="academy-header">
    <div class="crest" style="display: flex; align-items: center; justify-content: center; <?= $tplLogoStyle ?>">
      <img src="../<?= htmlspecialchars((string)$tplLogo) ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
    </div>
    <div class="header-text">
      <h1 contenteditable="true" style="<?= $tplAcademyNameStyle ?>"><?= $tplAcademyName ?></h1>
      <p class="tagline" contenteditable="true" style="<?= $tplTaglineStyle ?>"><?= $tplTagline ?></p>
      <p class="sheet-label" contenteditable="true" style="<?= $tplLabelStyle ?>"><?= htmlspecialchars((string)$fullName) ?> — Official Score Sheet</p>
    </div>
  </header>

  <!-- NAV -->
  <nav class="nav-bar print-hide">
    <a href="<?= htmlspecialchars($backUrl) ?>" onclick="if(document.referrer && document.referrer.includes('start_sheet.php')){event.preventDefault(); window.location.href=document.referrer;}" class="btn btn-back">← Back</a>
    <div style="display:flex; gap:10px;">
      <button class="btn btn-save" onclick="saveScores()" style="background:#27ae60; color:#fff; border:none; padding:10px 20px; font-weight:600; cursor:pointer; border-radius:4px;">💾 Save Scores</button>
      <button class="btn btn-print" onclick="window.print()" style="padding:10px 20px; border-radius:4px; cursor:pointer;">🖨️ Print Score Sheet</button>
      
      
    </div>
  </nav>

  <!-- META INFORMATION -->
  <section class="meta-card">
    <div class="meta-grid">
      <div class="field wide">
        <label for="name">Shooter's Name</label>
        <input type="text" id="name" value="<?= htmlspecialchars((string)$valName) ?>" placeholder="Full name">
      </div>
      <div class="field">
        <label for="bib">Bib No. / Comp ID</label>
        <input type="text" id="bib" list="bib-list" value="<?= htmlspecialchars((string)$valBib) ?>" placeholder="e.g. SSA-204">
        <datalist id="bib-list">
          <?php foreach ($allRegs as $r): ?>
            <option value="<?= htmlspecialchars($r['reg_id']) ?>"><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="field">
        <label for="cat">Category</label>
        <input type="text" id="cat" value="<?= htmlspecialchars((string)$valCat) ?>" placeholder="Category">
      </div>
      <div class="field">
        <label for="detail">Detail No.</label>
        <input type="text" id="detail" value="<?= htmlspecialchars((string)$valDetail) ?>" placeholder="e.g. 02">
      </div>
      <div class="field">
        <label for="lane">Lane No.</label>
        <input type="text" id="lane" value="<?= htmlspecialchars((string)$valLane) ?>" placeholder="e.g. 14">
      </div>
      <div class="field">
        <label for="card">Card Nos.</label>
        <input type="text" id="card" value="<?= htmlspecialchars((string)$metaCard) ?>" placeholder="e.g. 1–6">
      </div>
      <div class="field">
        <label for="match">Event No. / Event ID</label>
        <input type="text" id="match" value="<?= htmlspecialchars((string)$metaMatch) ?>" placeholder="e.g. IS-33">
      </div>
      <div class="field">
        <label for="date">Date</label>
        <input type="text" id="date" value="<?= htmlspecialchars((string)$valDate) ?>" placeholder="e.g. 09-Jul-2026">
      </div>
      <div class="field">
        <label for="time">Time</label>
        <input type="text" id="time" value="<?= htmlspecialchars((string)$valTime) ?>" placeholder="e.g. 10:00">
      </div>
    </div>
  </section>

  <!-- SCORE SHEET -->
  <section class="score-card">
    <div class="score-card-header">
      <h3 contenteditable="true"><?= htmlspecialchars(strtoupper($fullName)) ?></h3>
      <span class="badge" contenteditable="true">6 Series (60 Shots)</span>
    </div>

    <div class="table-wrap">
      <table class="score-table">
      <?php if (!empty($tplTableEl) && !empty($tplTableEl['rows'])): 
          $displayHeaders = $tplTableEl['headers'] ?? [];
          $displayRows = $tplTableEl['rows'] ?? [];
          $totalRows = count($displayRows);
          $totalCols = count($displayHeaders);
          $totalSeriesRows = $totalRows - 3;
          $occupied = array_fill(0, $totalRows, array_fill(0, $totalCols, false));
      ?>
        <thead>
          <tr>
            <?php foreach ($displayHeaders as $cIdx => $header):
                $hText = is_array($header) ? ($header['text'] ?? '') : (string)$header;
                $hColspan = is_array($header) ? ($header['colspan'] ?? 1) : 1;
                $hRowspan = is_array($header) ? ($header['rowspan'] ?? 1) : 1;
                if ($hColspan > 0 && $hRowspan > 0):
            ?>
              <th colspan="<?= $hColspan ?>" rowspan="<?= $hRowspan ?>"><?= htmlspecialchars((string)$hText) ?></th>
            <?php endif; endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($displayRows as $rIdx => $row): ?>
          <tr>
            <?php 
            $gridColIdx = 0;
            foreach ($row as $cell):
                while (isset($occupied[$rIdx][$gridColIdx]) && $occupied[$rIdx][$gridColIdx]) {
                    $gridColIdx++;
                }
                
                $cText = is_array($cell) ? ($cell['text'] ?? '') : (string)$cell;
                $cColspan = is_array($cell) ? ($cell['colspan'] ?? 1) : 1;
                $cRowspan = is_array($cell) ? ($cell['rowspan'] ?? 1) : 1;
                
                if ($cColspan > 0 && $cRowspan > 0):
                    for ($i = 0; $i < $cRowspan; $i++) {
                        for ($j = 0; $j < $cColspan; $j++) {
                            if (($rIdx + $i) < $totalRows && ($gridColIdx + $j) < $totalCols) {
                                if ($i > 0 || $j > 0) {
                                    $occupied[$rIdx + $i][$gridColIdx + $j] = true;
                                }
                            }
                        }
                    }
            ?>
              <td colspan="<?= $cColspan ?>" rowspan="<?= $cRowspan ?>" class="<?= ($gridColIdx === 0) ? 'row-label' : '' ?>">
                <?php if ($rIdx < $totalSeriesRows): ?>
                    <?php if ($gridColIdx === 0): ?>
                        <?= htmlspecialchars((string)$cText) ?>
                    <?php elseif ($gridColIdx === $totalCols - 1): 
                        $sIdx = $rIdx + 1;
                    ?>
                        <input class="summary-input total-series" type="text" readonly id="total-series-<?= $sIdx ?>" value="<?= htmlspecialchars((string)($seriesData[$sIdx]['total'] ?? '')) ?>">
                    <?php else: 
                        $sIdx = $rIdx + 1;
                        $shotIdx = $gridColIdx;
                        $val = htmlspecialchars((string)($seriesData[$sIdx][$shotIdx] ?? '0'));
                    ?>
                        <input class="score-input" type="text" maxlength="5" placeholder="0" data-series="<?= $sIdx ?>" data-shot="<?= $shotIdx ?>" value="<?= $val ?>">
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($gridColIdx === $totalCols - 1): ?>
                        <?php if ($rIdx === $totalSeriesRows): ?>
                            <input class="summary-input" type="text" readonly id="total-val" name="total_val" value="<?= htmlspecialchars((string)$totalVal) ?>">
                        <?php elseif ($rIdx === $totalSeriesRows + 1): ?>
                            <input class="summary-input score-calc" type="text" id="penalty-val" name="penalty_val" value="<?= htmlspecialchars((string)$penaltyVal) ?>">
                        <?php elseif ($rIdx === $totalSeriesRows + 2): ?>
                            <input class="summary-input" type="text" readonly id="grand-total-val" name="grand_total_val" value="<?= htmlspecialchars((string)$grandTotalVal) ?>">
                        <?php endif; ?>
                    <?php else: ?>
                        <?= htmlspecialchars((string)$cText) ?>
                    <?php endif; ?>
                <?php endif; ?>
              </td>
            <?php 
                    $gridColIdx += $cColspan;
                endif; 
            endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      <?php else: ?>
        <thead>
          <tr>
            <th style="width:90px;">Series</th>
            <th>1</th><th>2</th><th>3</th><th>4</th><th>5</th>
            <th>6</th><th>7</th><th>8</th><th>9</th><th>10</th>
            <th style="width:80px;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php for ($sIdx = 1; $sIdx <= 6; $sIdx++): ?>
          <tr>
            <td class="row-label">Series <?= $sIdx ?></td>
            <?php for ($shotIdx = 1; $shotIdx <= 10; $shotIdx++): 
                $val = htmlspecialchars((string)($seriesData[$sIdx][$shotIdx] ?? '0'));
            ?>
            <td><input class="score-input" type="text" maxlength="5" placeholder="0" data-series="<?= $sIdx ?>" data-shot="<?= $shotIdx ?>" value="<?= $val ?>"></td>
            <?php endfor; ?>
            <td><input class="summary-input total-series" type="text" readonly id="total-series-<?= $sIdx ?>" value="<?= htmlspecialchars((string)($seriesData[$sIdx]['total'] ?? '')) ?>"></td>
          </tr>
          <?php endfor; ?>
          <!-- TOTAL -->
          <tr class="total-row">
            <td class="row-label" colspan="11" style="text-align:right;padding-right:1rem;">Total</td>
            <td><input class="summary-input" type="text" readonly id="total-val" name="total_val" value="<?= htmlspecialchars((string)$totalVal) ?>"></td>
          </tr>
          <!-- PENALTY -->
          <tr class="penalty-row">
            <td class="row-label" colspan="11" style="text-align:right;padding-right:1rem;">Penalty</td>
            <td><input class="summary-input score-calc" type="text" id="penalty-val" name="penalty_val" value="<?= htmlspecialchars((string)$penaltyVal) ?>"></td>
          </tr>
          <!-- GRAND TOTAL -->
          <tr class="grand-row">
            <td class="row-label" colspan="11" style="text-align:right;padding-right:1rem;">Grand Total</td>
            <td><input class="summary-input" type="text" readonly id="grand-total-val" name="grand_total_val" value="<?= htmlspecialchars((string)$grandTotalVal) ?>"></td>
          </tr>
        </tbody>
      <?php endif; ?>
      <!-- REMARKS: always shown regardless of template -->
      <tbody>
        <tr class="remarks-row">
          <td class="row-label" colspan="11" style="text-align:right;padding-right:1rem;">Remarks</td>
          <td style="padding:4px;">
            <select id="remarks-val" name="remarks_val">
              <option value="" <?= $remarksVal === '' ? 'selected' : '' ?>>— Select —</option>
              <option value="C" <?= in_array($remarksVal, ['C', 'Completed'], true) ? 'selected' : '' ?>>Completed (C)</option>
              <option value="DNS" <?= $remarksVal === 'DNS' ? 'selected' : '' ?>>DNS (Did Not Start)</option>
              <option value="DSQ" <?= $remarksVal === 'DSQ' ? 'selected' : '' ?>>DSQ (Disqualified)</option>
              <option value="DNF" <?= $remarksVal === 'DNF' ? 'selected' : '' ?>>DNF (Did Not Finish)</option>
              <option value="DQP" <?= $remarksVal === 'DQP' ? 'selected' : '' ?>>DQP (Disqualified Penalty)</option>
            </select>
          </td>
        </tr>
      </tbody>
      </table>
      <!-- Hidden Fallback Inputs for Summary Calculations -->
      <div style="display:none;">
        <input type="hidden" id="total-val-fallback" value="<?= htmlspecialchars((string)$totalVal) ?>">
        <input type="hidden" id="penalty-val-fallback" value="<?= htmlspecialchars((string)$penaltyVal) ?>">
        <input type="hidden" id="grand-total-val-fallback" value="<?= htmlspecialchars((string)$grandTotalVal) ?>">
      </div>
    </div>

    <!-- SIGNATURES -->
    <div class="signatures">
      <div class="sig-block">
        <div class="sig-line"></div>
        <p class="sig-label" contenteditable="true">RTS Signature</p>
      </div>
      <div class="sig-block">
        <div class="sig-line"></div>
        <p class="sig-label" contenteditable="true">Official's Signature</p>
      </div>
    </div>
  </section>

  <footer>&copy; 2026 Saragarhi Shooting Academy. All rights reserved.</footer>
</div>

<script>

document.addEventListener('DOMContentLoaded', () => {
    const allRegistrations = <?= json_encode($allRegs) ?>;
    const sheetClub = <?= json_encode($alloc['club_name']) ?>;

    // Auto-populate Shooter's Name when selecting an Enrollment ID
    const bibInput = document.getElementById('bib');
    const nameInput = document.getElementById('name');
    if (bibInput && nameInput) {
        const regNameMap = {
            <?php
            foreach ($allRegs as $r) {
                $fullName = trim($r['first_name'] . ' ' . $r['last_name']);
                echo json_encode($r['reg_id']) . ": " . json_encode($fullName) . ",\n";
            }
            ?>
        };

        const regCatMap = {
            <?php
            foreach ($allRegs as $r) {
                echo json_encode($r['reg_id']) . ": " . json_encode($r['category'] ?? '') . ",\n";
            }
            ?>
        };
        const regEventsMap = <?= json_encode($regEventsMap) ?>;

        // Filter the datalist based on matching club name
        bibInput.addEventListener('focus', () => {
            const datalist = document.getElementById('bib-list');
            const clubName = (sheetClub || '').trim().toUpperCase();
            if (datalist) {
                datalist.innerHTML = '';
                allRegistrations.forEach(r => {
                    const rClub = (r.club_name || '').trim().toUpperCase();
                    if (!clubName || rClub === clubName) {
                        const opt = document.createElement('option');
                        opt.value = r.reg_id;
                        opt.textContent = (r.first_name + ' ' + r.last_name).trim();
                        datalist.appendChild(opt);
                    }
                });
            }
        });

        // Validate selected/typed enrollment ID belongs to correct club
        bibInput.addEventListener('change', () => {
            const val = bibInput.value.trim();
            if (val === '') return;
            const clubName = (sheetClub || '').trim().toUpperCase();
            if (clubName) {
                const reg = allRegistrations.find(r => r.reg_id === val);
                if (reg) {
                    const rClub = (reg.club_name || '').trim().toUpperCase();
                    if (rClub !== clubName) {
                        alert(`Error: Enrollment ID "${val}" belongs to "${reg.club_name}", but this score sheet is for club "${sheetClub}".`);
                        bibInput.value = '';
                        nameInput.value = '';
                    }
                }
            }
        });

        bibInput.addEventListener('input', () => {
            const val = bibInput.value.trim();
            if (regNameMap[val]) {
                nameInput.value = regNameMap[val];
            }
            if (regCatMap[val]) {
                const catInput = document.getElementById('cat');
                if (catInput) catInput.value = regCatMap[val];
            }
            if (regEventsMap[val]) {
                const matchInput = document.getElementById('match');
                if (matchInput) matchInput.value = regEventsMap[val];
            }
        });
    }

    const inputs = document.querySelectorAll('.score-input');
    const penaltyInput = document.getElementById('penalty-val') || document.getElementById('penalty-val-fallback');
    
    function calculateTotals() {
        let grandTotal = 0;
        const penaltyInput = document.getElementById('penalty-val') || document.getElementById('penalty-val-fallback');
        
        for (let s = 1; s <= 6; s++) {
            let seriesSum = 0;
            let hasAnyVal = false;
            
            const seriesInputs = document.querySelectorAll(`.score-input[data-series="${s}"]`);
            if (seriesInputs.length > 0) {
                seriesInputs.forEach(input => {
                    const raw = input.value.trim();
                    if (raw !== '') {
                        const val = parseFloat(raw);
                        if (!isNaN(val)) {
                            seriesSum += val;
                            hasAnyVal = true;
                        }
                    }
                });
            } else {
                for (let shot = 1; shot <= 10; shot++) {
                    const input = document.querySelector(`.score-input[data-series="${s}"][data-shot="${shot}"]`);
                    if (input && input.value.trim() !== '') {
                        const val = parseFloat(input.value);
                        if (!isNaN(val)) {
                            seriesSum += val;
                            hasAnyVal = true;
                        }
                    }
                }
            }
            
            seriesSum = Math.round(seriesSum * 100) / 100;
            const seriesTotalInput = document.getElementById(`total-series-${s}`);
            if (seriesTotalInput) {
                seriesTotalInput.value = hasAnyVal ? (seriesSum % 1 === 0 ? seriesSum.toString() : seriesSum.toFixed(1)) : '0';
            }
            if (hasAnyVal) {
                grandTotal += seriesSum;
            }
        }
        
        // Ultimate fallback: if series grouping yielded 0 but score-inputs have values, sum all score-inputs
        if (grandTotal === 0) {
            let allInputsSum = 0;
            let hasAnyScoreInput = false;
            document.querySelectorAll('.score-input').forEach(input => {
                const raw = input.value.trim();
                if (raw !== '') {
                    const val = parseFloat(raw);
                    if (!isNaN(val)) {
                        allInputsSum += val;
                        hasAnyScoreInput = true;
                    }
                }
            });
            if (hasAnyScoreInput) {
                grandTotal = Math.round(allInputsSum * 100) / 100;
            }
        }
        
        grandTotal = Math.round(grandTotal * 100) / 100;
        const totalValInput = document.getElementById('total-val') || document.getElementById('total-val-fallback');
        if (totalValInput) {
            totalValInput.value = (grandTotal % 1 === 0) ? grandTotal.toString() : grandTotal.toFixed(1);
        }
        
        let penVal = penaltyInput ? (Math.abs(parseFloat(penaltyInput.value)) || 0) : 0;
        let finalGrand = grandTotal - penVal;
        finalGrand = Math.round(finalGrand * 100) / 100;
        
        const grandTotalInput = document.getElementById('grand-total-val') || document.getElementById('grand-total-val-fallback');
        if (grandTotalInput) {
            grandTotalInput.value = (finalGrand % 1 === 0) ? finalGrand.toString() : finalGrand.toFixed(1);
        }
    }
    
    document.addEventListener('input', (e) => {
        if (e.target && (e.target.classList.contains('score-input') || e.target.id === 'penalty-val')) {
            calculateTotals();
        }
    });
    
    document.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('score-input')) {
            let input = e.target;
            let valStr = input.value.trim();
            if (valStr === '') {
                input.value = '0';
                calculateTotals();
                return;
            }
            let val = parseFloat(valStr);
            if (isNaN(val) || val < 0 || val > 10.9) {
                alert('Invalid score! Each cell must contain a value between 0 and 10.9 only.');
                input.value = '0';
                calculateTotals();
                setTimeout(() => { input.focus(); input.select(); }, 10);
            } else {
                input.value = val % 1 === 0 ? val.toString() : val.toFixed(1);
                calculateTotals();
            }
        } else if (e.target && e.target.id === 'penalty-val') {
            calculateTotals();
        }
    });

    document.querySelectorAll('.score-input').forEach(input => {
        input.addEventListener('focus', () => {
            input.select();
        });
    });
    
    window.saveScores = async function() {
        const getVal = (id) => {
            const el = document.getElementById(id);
            return el ? el.value.trim() : '';
        };

        const seriesData = {};
        for (let s = 1; s <= 6; s++) {
            seriesData[s] = {};
            for (let shot = 1; shot <= 10; shot++) {
                const input = document.querySelector(`.score-input[data-series="${s}"][data-shot="${shot}"]`);
                seriesData[s][shot] = input ? input.value.trim() : '';
            }
            const st = document.getElementById(`total-series-${s}`);
            seriesData[s]['total'] = st ? st.value : '';
        }
        
        const totElem = document.getElementById('total-val') || document.getElementById('total-val-fallback');
        const penElem = document.getElementById('penalty-val') || document.getElementById('penalty-val-fallback');
        const grandElem = document.getElementById('grand-total-val') || document.getElementById('grand-total-val-fallback');

        const fd = new FormData();
        fd.append('alloc_id', '<?= $allocId ?>');
        fd.append('meta_card', getVal('card'));
        fd.append('meta_match', getVal('match'));
        fd.append('series_data', JSON.stringify(seriesData));
        fd.append('total_val', totElem ? totElem.value : '0');
        fd.append('penalty_val', penElem ? penElem.value.trim() : '0');
        fd.append('grand_total_val', grandElem ? grandElem.value : '0');
        fd.append('remarks_val', getVal('remarks-val'));
        
        fd.append('custom_shooter_name', getVal('name'));
        fd.append('custom_bib', getVal('bib'));
        fd.append('custom_category', getVal('cat'));
        fd.append('custom_detail', getVal('detail'));
        fd.append('custom_lane', getVal('lane'));
        fd.append('custom_date', getVal('date'));
        fd.append('custom_time', getVal('time'));
        
        try {
            const resp = await fetch('../admin/actions/save_score_sheet.php', {
                method: 'POST',
                body: fd
            });
            const res = await resp.json();
            if (res.success) {
                Swal.fire({
                    text: 'Scores saved successfully!',
                    icon: 'success',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#c9a84c'
                }).then(() => {
                    if (document.referrer && document.referrer.includes('start_sheet.php')) {
                        window.location.href = document.referrer;
                    } else {
                        window.location.href = '<?= $backUrl ?>';
                    }
                });
            } else {
                Swal.fire({
                    text: 'Error saving scores: ' + (res.message || 'Unknown error'),
                    icon: 'error',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#c9a84c'
                });
            }
        } catch (e) {
            console.error('Save score error:', e);
            Swal.fire({
                text: 'Failed to save scores: ' + (e.message || 'Network error'),
                icon: 'error',
                background: '#1a1a1a',
                color: '#fff',
                confirmButtonColor: '#c9a84c'
            });
        }
    };
});
</script>
  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
