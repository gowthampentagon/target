<?php
// admin/start_sheet.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
$pageTitle = 'Master Start Lists';
require_once 'includes/header.php';

function getScoreSheetUrl(int $allocId, string $evtName): string
{
  $evt = str_replace('_', ' ', strtolower($evtName));
  $url = '';

  // 1. Standard Pistol
  if (strpos($evt, 'standard pistol') !== false || strpos($evt, '25m standard') !== false) {
    $url = '../Score Sheet/standard-pistol.php?alloc_id=' . $allocId;
  }
  // 2. Centre Fire / Sport Pistol / 25M Pistol
  elseif (
    strpos($evt, 'centre fire') !== false ||
    strpos($evt, 'center fire') !== false ||
    strpos($evt, 'sports pistol') !== false ||
    strpos($evt, 'sport pistol') !== false ||
    strpos($evt, '25m pistol') !== false
  ) {
    $url = '../Score Sheet/centre-fire-pistol.php?alloc_id=' . $allocId;
  }
  // 3. Rifle Prone
  elseif (strpos($evt, 'prone') !== false) {
    $url = '../Score Sheet/rifle-prone.php?alloc_id=' . $allocId;
  }
  // 4. Default: Air Rifle / Air Pistol / 10M / 50M Free Pistol
  else {
    $url = '../Score Sheet/air-rifle-pistol.php?alloc_id=' . $allocId;
  }

  // Append return parameters if present in start sheet query
  $retParams = [];
  if (!empty($_GET['event_select']))
    $retParams['event_select'] = $_GET['event_select'];
  if (!empty($_GET['date_select']))
    $retParams['date_select'] = $_GET['date_select'];
  if (!empty($_GET['relay_select']))
    $retParams['relay_select'] = $_GET['relay_select'];
  if (!empty($retParams)) {
    $url .= '&' . http_build_query($retParams);
  }

  return $url;
}
try {
  $pdo = getDB();
  try {
    $pdo->exec("ALTER TABLE `lane_allocations` ADD `target_serial_no` VARCHAR(50) NULL DEFAULT NULL");
  } catch (Throwable $t) {
  }
  try {
    $pdo->exec("ALTER TABLE `lane_allocations` ADD `custom_name` VARCHAR(255) NULL DEFAULT NULL");
  } catch (Throwable $t) {
  }
  try {
    $pdo->exec("ALTER TABLE `lane_allocations` ADD `bib_no` VARCHAR(50) NULL DEFAULT NULL");
  } catch (Throwable $t) {
  }
  try {
    $pdo->exec("ALTER TABLE `lane_allocations` ADD `is_locked` TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $t) {
  }

  require_once dirname(__DIR__) . '/config/events.php';

  // ── Build base groups exactly like lane_allocations.php ──
  $baseGroups = [];

  $addEventToBaseGroups = function (string $evt, string $cat = '') use (&$baseGroups, $EVENTS_MAPPING) {
    if (empty($evt))
      return;
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
      if ($cat === 'nr_mqs')
        $cat = 'nr';
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
  $cid = (int) ($activeChampionship['id'] ?? 1);

  // 1. Event registrations
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

  // 3. Custom events
  $customEventsTable = $pdo->query("SHOW TABLES LIKE 'custom_event_names'")->fetchColumn();
  if ($customEventsTable) {
    $customEvents = $pdo->query("SELECT event_name FROM custom_event_names")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($customEvents as $evt) {
      $addEventToBaseGroups($evt, 'nr');
    }
  }

  // 4. Default competition events mapping if baseGroups is empty
  if (empty($baseGroups)) {
    foreach ($EVENTS_MAPPING as $code => $fullName) {
      $cat = (strpos(strtoupper($fullName), '(ISSF)') !== false) ? 'issf' : 'nr';
      $addEventToBaseGroups($code, $cat);
    }
  }

  // Populate dropdown options from the complete base groups list
  $dropdownOptions = [];
  foreach (array_keys($baseGroups) as $bgKey) {
    $dropdownOptions[] = [
      'label' => formatBaseEventName($bgKey),
      'value' => $bgKey
    ];
  }
  usort($dropdownOptions, function ($a, $b) {
    return strcmp($a['label'], $b['label']);
  });

  $selBaseType = trim($_GET['event_select'] ?? $_GET['base_type'] ?? '');
  $selRelayNo = (int) ($_GET['relay_select'] ?? $_GET['relay_no'] ?? 0);
  $selDate = trim($_GET['date_select'] ?? $_GET['date'] ?? '');
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
  } else {
    $selEvents = array_keys($baseGroups);
  }

  if ($selBaseType === '' && !empty($_GET['event_name'])) {
    $evt = trim($_GET['event_name']);
    $cat = strtolower(trim($_GET['category'] ?? ''));
    if ($cat === 'nr_mqs')
      $cat = 'nr';
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
      $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2|SUB|YOUTH|JUNIOR|SENIOR|MASTER|SUPER|PEEP|SIGHT)\b/i', '', $cleanName);
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

  // Resolve all individual event IDs from the selected base groups
  $allSelectedEventIds = [];
  foreach ($selEvents as $evtKey) {
    if (isset($baseGroups[$evtKey])) {
      $allSelectedEventIds = array_merge($allSelectedEventIds, $baseGroups[$evtKey]);
    }

    // Strip category suffix (_issf, _nr) to get core base key
    $coreKey = preg_replace('/_(issf|nr|nr_mqs|para_deaf)$/i', '', strtolower($evtKey));
    $cleanCoreKey = preg_replace('/[^a-z0-9]/', '', $coreKey);

    foreach ($EVENTS_MAPPING as $id => $fullName) {
      $baseType = getBackendEventBaseType($fullName);
      $cleanBase = preg_replace('/[^a-z0-9]/', '', strtolower($baseType));
      if (!empty($cleanBase) && !empty($cleanCoreKey) && ($cleanBase === $cleanCoreKey || strpos($cleanBase, $cleanCoreKey) !== false || strpos($cleanCoreKey, $cleanBase) !== false)) {
        $allSelectedEventIds[] = $id;
        $allSelectedEventIds[] = $fullName;
      }
    }
    $allSelectedEventIds[] = $evtKey;
    $allSelectedEventIds[] = formatBaseEventName($evtKey);
    $allSelectedEventIds[] = formatBaseEventName($coreKey);
  }
  $allSelectedEventIds = array_values(array_unique(array_filter($allSelectedEventIds)));

  // Fetch all registrations with their base event types for autocomplete scoping and club validation
  $rawApprovedRegs = $pdo->query("
        SELECT r.reg_id, r.first_name, r.last_name, r.club_name, er.event_name, er.category, la.custom_name, la.relay_no, la.lane_no
        FROM registrations r
        LEFT JOIN event_registrations er ON r.id = er.user_id
        LEFT JOIN lane_allocations la ON (er.id = la.event_reg_id AND la.lane_no > 0)
        WHERE r.reg_id IS NOT NULL AND r.reg_id != ''
        ORDER BY r.reg_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

  $regsMap = [];
  $regClubMap = [];
  foreach ($rawApprovedRegs as $r) {
    $regId = $r['reg_id'];
    $club = trim($r['club_name'] ?? '');
    if (!empty($club) && $club !== '—' && $club !== '-') {
      $fBib = formatBibNo($regId);
      if ($fBib)
        $regClubMap[$fBib] = $club;
      if ($regId)
        $regClubMap[$regId] = $club;
      $uDigits = preg_replace('/\D/', '', (string) $regId);
      if ($uDigits)
        $regClubMap[$uDigits] = $club;
      $fName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      if (!empty($fName))
        $regClubMap[strtoupper($fName)] = $club;
    }
    $evtName = $r['event_name'] ?? '';
    $evtFullName = !empty($evtName) ? ($EVENTS_MAPPING[$evtName] ?? $evtName) : '';
    $baseType = getBackendEventBaseType($evtFullName);
    if (empty($baseType)) {
      $cleanName = preg_replace('/\s*\([^)]*\)/', '', $evtFullName);
      $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2)\b/i', '', $cleanName);
      $baseType = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($cleanName)));
      $baseType = preg_replace('/_+/', '_', $baseType);
      $baseType = trim($baseType, '_');
    }
    $cat = strtolower($r['category'] ?? 'nr');
    if ($cat === 'nr_mqs')
      $cat = 'nr';
    if ($cat === 'para_deaf') {
      $cat = (strpos(strtoupper($evtFullName), '(ISSF)') !== false) ? 'issf' : 'nr';
    }
    $baseKey = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $baseType) . '_' . $cat;

    $shooterFullName = !empty(trim((string) ($r['custom_name'] ?? '')))
      ? trim($r['custom_name'])
      : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    if (!isset($regsMap[$regId])) {
      $regsMap[$regId] = [
        'reg_id' => $regId,
        'first_name' => $r['first_name'] ?? '',
        'last_name' => $r['last_name'] ?? '',
        'full_name' => $shooterFullName,
        'club_name' => $club,
        'relay_no' => (!empty($r['relay_no']) && (int) ($r['lane_no'] ?? 0) > 0) ? (int) $r['relay_no'] : null,
        'bases' => []
      ];
    } else {
      if (empty($regsMap[$regId]['full_name']) && !empty($shooterFullName)) {
        $regsMap[$regId]['full_name'] = $shooterFullName;
      }
      if (!empty($r['relay_no']) && (int) ($r['lane_no'] ?? 0) > 0) {
        $regsMap[$regId]['relay_no'] = (int) $r['relay_no'];
      }
    }
    if (!empty($baseKey) && !in_array($baseKey, $regsMap[$regId]['bases'], true)) {
      $regsMap[$regId]['bases'][] = $baseKey;
    }
    if (!empty($baseType) && !in_array($baseType, $regsMap[$regId]['bases'], true)) {
      $regsMap[$regId]['bases'][] = $baseType;
    }
    if (!empty($evtName) && !in_array($evtName, $regsMap[$regId]['bases'], true)) {
      $regsMap[$regId]['bases'][] = $evtName;
    }
  }
  $allRegs = array_values($regsMap);

  // Fetch all registrations and allocations for complete two-way map lookup
  $allActiveRegs = $pdo->query("
        SELECT r.reg_id, er.event_reg_id, la.bib_no, r.first_name, r.last_name, la.custom_name
        FROM registrations r
        LEFT JOIN event_registrations er ON r.id = er.user_id
        LEFT JOIN lane_allocations la ON er.id = la.event_reg_id
    ")->fetchAll(PDO::FETCH_ASSOC);
  $allocations = [];
  $grouped = [];
  $loadedBaseKeys = $selEvents;
  $allRelays = [];
  $allDates = [];

  if (!empty($allSelectedEventIds)) {
    $upperIds = array_values(array_unique(array_map('strtoupper', $allSelectedEventIds)));
    $allIds = array_values(array_unique(array_merge($allSelectedEventIds, $upperIds)));
    $inClause = implode(',', array_fill(0, count($allIds), '?'));

    $query = "
            SELECT la.*, er.event_name, er.category, r.club_name, er.event_reg_id AS enrollment_id, r.first_name, r.last_name, r.district, r.reg_id,
                   ss.grand_total_val, ss.id AS score_sheet_id, ss.remarks AS score_remarks
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            LEFT JOIN registrations r ON er.user_id = r.id
            LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
            WHERE (er.championship_id = ? OR er.championship_id IS NULL OR er.championship_id = 1)
              AND (UPPER(er.event_name) IN ($inClause) OR UPPER(er.event_code) IN ($inClause))
            GROUP BY la.id
            ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.start_time ASC, CASE WHEN la.lane_no > 0 THEN la.lane_no ELSE 99999 END ASC, la.id ASC
        ";

    $stmt = $pdo->prepare($query);
    $params = array_merge([$cid], $allIds, $allIds);
    $stmt->execute($params);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  if (empty($allSelectedEventIds)) {
    $query = "
            SELECT la.*, er.event_name, er.category, r.club_name, er.event_reg_id AS enrollment_id, r.first_name, r.last_name, r.district, r.reg_id,
                   ss.grand_total_val, ss.id AS score_sheet_id, ss.remarks AS score_remarks
            FROM lane_allocations la
            JOIN event_registrations er ON la.event_reg_id = er.id
            LEFT JOIN registrations r ON er.user_id = r.id
            LEFT JOIN score_sheets ss ON la.id = ss.lane_alloc_id
            WHERE (er.championship_id = ? OR er.championship_id IS NULL OR er.championship_id = 1)
            GROUP BY la.id
            ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.start_time ASC, CASE WHEN la.lane_no > 0 THEN la.lane_no ELSE 99999 END ASC, la.id ASC
        ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$cid]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  foreach ($allocations as $a) {
    if (!empty($a['relay_no'])) {
      if ($selDate === '' || $a['scheduled_date'] === $selDate) {
        $allRelays[] = (int) $a['relay_no'];
      }
    }
    if (!empty($a['scheduled_date'])) {
      $allDates[] = $a['scheduled_date'];
    }
  }

  $groupBaseKeys = [];

  $resolveCanonicalBaseGroupKey = function (string $evtName, string $category = '') use ($baseGroups, $EVENTS_MAPPING): string {
    if (empty($evtName)) return !empty($baseGroups) ? (string)array_key_first($baseGroups) : '10m_air_pistol_issf';

    if (isset($baseGroups[$evtName])) {
      return $evtName;
    }

    foreach ($baseGroups as $bgKey => $evtList) {
      if (in_array($evtName, $evtList, true)) {
        return $bgKey;
      }
    }

    $evtUpper = strtoupper($evtName);
    $fullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
    $nameUpper = strtoupper($fullName);

    $is10mPistolParaDeaf = (strpos($nameUpper, '10M AIR PISTOL') !== false || strpos($nameUpper, '10M PISTOL') !== false) && 
        (strpos($nameUpper, 'SH1') !== false || strpos($nameUpper, 'DEAF') !== false || strpos($nameUpper, 'PARA') !== false || in_array($evtUpper, ['DS12', 'DS13', 'R021', 'R022', 'R023', 'R024', 'R025', 'R026', 'R031', 'R032'], true));

    if ($is10mPistolParaDeaf || strpos($evtUpper, 'IS-') === 0 || strpos($evtUpper, 'IS') === 0 || strpos($nameUpper, '(ISSF)') !== false || strpos($nameUpper, 'ISSF') !== false) {
      $cat = 'issf';
    } elseif (strpos($evtUpper, 'NR-') === 0 || strpos($evtUpper, 'NR') === 0 || strpos($nameUpper, '(NR)') !== false || strpos($nameUpper, 'NATIONAL RULE') !== false) {
      $cat = 'nr';
    } else {
      $cat = strtolower($category ?: 'nr');
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
    $computedKey = $baseType . '_' . $cat;

    if (isset($baseGroups[$computedKey])) {
      return $computedKey;
    }

    $altKey = (str_ends_with($computedKey, '_nr')) ? preg_replace('/_nr$/', '_issf', $computedKey) : preg_replace('/_issf$/', '_nr', $computedKey);
    if (isset($baseGroups[$altKey])) {
      return $altKey;
    }

    return !empty($baseGroups) ? (string)array_key_first($baseGroups) : $computedKey;
  };

  foreach ($allocations as $a) {
    $evtId = $a['event_name'];
    $cat = $a['category'] ?? '';
    $baseType = $resolveCanonicalBaseGroupKey($evtId, $cat);

    $en = formatBaseEventName($baseType);

    $a['base_type'] = $baseType;
    $date = $a['scheduled_date'];
    $relay = (int) $a['relay_no'];

    $grouped[$en][$date][$relay][] = $a;
    if (empty($groupBaseKeys[$en])) {
      $groupBaseKeys[$en] = $baseType;
    }
  }

  // Also load configured relay schedules so empty start list tables and Add Row buttons appear
  try {
    $schedStmt = $pdo->query("SELECT * FROM relay_schedules WHERE schedule_name = '' ORDER BY scheduled_date ASC, relay_no ASC");
    $schedRows = $schedStmt ? $schedStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($schedRows as $s) {
      $sEvtName = $s['event_name'];
      $sBaseType = $resolveCanonicalBaseGroupKey($sEvtName);
      $en = formatBaseEventName($sBaseType);
      $sDate = $s['scheduled_date'];
      $sRelay = (int) $s['relay_no'];

      if (!empty($selEvents) && !in_array($sBaseType, $selEvents, true)) {
        continue;
      }

      if (empty($groupBaseKeys[$en])) {
        $groupBaseKeys[$en] = $sBaseType;
      }

      if (!isset($grouped[$en])) {
        $grouped[$en] = [];
      }
      if (!isset($grouped[$en][$sDate])) {
        $grouped[$en][$sDate] = [];
      }
      if (!isset($grouped[$en][$sDate][$sRelay])) {
        $grouped[$en][$sDate][$sRelay] = [];
      }
      if (!in_array($sRelay, $allRelays, true)) {
        $allRelays[] = $sRelay;
      }
      if (!in_array($sDate, $allDates, true)) {
        $allDates[] = $sDate;
      }
    }
  } catch (Throwable $stt) {
  }



  $allRelays = array_values(array_unique($allRelays));
  sort($allRelays);
  $allDates = array_values(array_unique($allDates));
  sort($allDates);

  if ($selRelayNo > 0 && !in_array($selRelayNo, $allRelays, true)) {
    $selRelayNo = 0;
  }

  ksort($grouped);
  $loadedBaseKeys = $selEvents;

} catch (Throwable $e) {
  error_log("Start Sheet Error: " . $e->getMessage());
  $grouped = [];
  $loadedBaseKeys = [];
  $dropdownOptions = [];
} ?>

<style>
  @page {
    size: A4;
    margin: 10mm;
  }

  @media print {
    body {
      background: #fff !important;
      color: #000 !important;
      font-family: Arial, sans-serif !important;
    }

    .admin-sidebar,
    .admin-page-header,
    .toolbar,
    .print-hide {
      display: none !important;
    }

    .admin-main {
      margin: 0 !important;
      padding: 0 !important;
      width: 100% !important;
    }

    .discipline-section {
      page-break-after: always;
    }

    .discipline-section:last-child {
      page-break-after: avoid;
    }

    .table-wrap {
      box-shadow: none !important;
      background: transparent !important;
      border: none !important;
      margin: 0 !important;
      padding: 0 !important;
    }

    table,
    .admin-table,
    .data-table {
      border-collapse: collapse !important;
      width: 100% !important;
      table-layout: auto !important;
      color: #000000 !important;
      word-wrap: break-word !important;
      overflow-wrap: break-word !important;
    }

    th,
    td,
    .admin-table th,
    .admin-table td {
      border: 1px solid #000000 !important;
      color: #000000 !important;
      background: transparent !important;
      padding: 6px 8px !important;
      font-size: 11px !important;
      text-align: center;
      white-space: normal !important;
      word-break: normal !important;
      box-sizing: border-box !important;
    }

    tr {
      page-break-inside: avoid !important;
      break-inside: avoid !important;
    }

    thead {
      display: table-header-group !important;
    }

    td.text-left,
    th.text-left {
      text-align: left;
    }

    th,
    .admin-table th {
      font-weight: bold !important;
      background: #f5f5f5 !important;
      border: 1px solid #000000 !important;
    }

    .day-title {
      color: #000000 !important;
      font-size: 14px !important;
      margin-top: 15px !important;
      margin-bottom: 8px !important;
      text-align: left !important;
      font-weight: bold !important;
    }

    .discipline-title {
      color: #000000 !important;
      font-size: 18px !important;
      margin-top: 20px !important;
      margin-bottom: 10px !important;
      text-align: center !important;
      font-weight: bold !important;
      border-bottom: 2px solid #000000 !important;
      padding-bottom: 5px !important;
    }

    .col-compact,
    th.col-compact,
    td.col-compact {
      width: 1% !important;
      white-space: nowrap !important;
    }

    th.name-cell,
    td.name-cell,
    .name-cell {
      width: 30% !important;
      min-width: 200px !important;
      text-align: left !important;
      white-space: normal !important;
      word-break: normal !important;
      font-weight: bold !important;
    }

    th.club-cell,
    td.club-cell,
    .club-cell {
      width: 32% !important;
      min-width: 180px !important;
      text-align: left !important;
      white-space: normal !important;
      word-break: normal !important;
    }

    .table-input,
    input.table-input,
    td input,
    input[type="text"] {
      color: #000000 !important;
      background: transparent !important;
      border: none !important;
      box-shadow: none !important;
      outline: none !important;
      appearance: none !important;
      -webkit-appearance: none !important;
      padding: 0 !important;
      margin: 0 !important;
      height: auto !important;
      line-height: normal !important;
      font-size: 11px !important;
    }
  }

  .discipline-title {
    font-family: 'Cinzel', serif;
    font-size: 24px;
    color: var(--gold-400);
    border-bottom: 2px solid rgba(255, 255, 255, 0.3);
    padding-bottom: 8px;
    margin-bottom: 20px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  .day-title {
    font-family: 'Inter', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    margin-top: 20px;
    margin-bottom: 10px;
    text-transform: uppercase;
  }

  .editable-cell {
    cursor: text;
    min-height: 20px;
    transition: all 0.2s ease;
  }

  .editable-cell:hover {
    background: rgba(255, 255, 255, 0.03);
    outline: 1px dashed var(--gold-500);
  }

  .editable-cell:focus {
    background: rgba(255, 255, 255, 0.08);
    outline: 2px solid var(--gold-500);
  }

  .table-input {
    transition: all 0.2s ease;
  }

  .table-input:hover {
    background: rgba(255, 255, 255, 0.03);
    outline: 1px dashed var(--gold-500);
  }

  .table-input:focus {
    background: rgba(255, 255, 255, 0.08);
    outline: 2px solid var(--gold-500);
  }
</style>

<div class="admin-page-header print-hide">
  <div class="admin-page-title">Master Start Lists</div>
</div>

<!-- EVENT SELECTOR CARD -->
<div class="meta-card print-hide" style="margin-bottom: 24px; padding: 20px;">
  <form method="GET" id="event-select-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    <div class="field" style="flex: 1; min-width: 250px;">
      <label for="event_select"
        style="font-weight: 600; font-size: 14px; color: var(--gold-500); margin-bottom: 6px;">Select Shooting
        Event</label>
      <select id="event_select" name="event_select" class="searchable-select" onchange="this.form.submit()"
        style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 14px;">
        <option value="">— All Events —</option>
        <?php foreach ($dropdownOptions as $opt):
          $selected = ($selBaseType === $opt['value']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $selected ?>>
            <?= htmlspecialchars($opt['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($selBaseType !== ''): ?>
      <div class="field" style="flex: 1; min-width: 200px;">
        <label for="date_select"
          style="font-weight: 600; font-size: 14px; color: var(--gold-500); margin-bottom: 6px;">Select Date/Day</label>
        <select id="date_select" name="date_select" class="searchable-select" onchange="this.form.submit()"
          style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 14px;">
          <option value="">— All Days —</option>
          <?php foreach ($allDates as $idx => $d):
            $dSelected = ($selDate === $d) ? 'selected' : '';
            $dayLabel = "Day " . ($idx + 1) . " (" . date('d-M-Y', strtotime($d)) . ")";
            ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= $dSelected ?>><?= htmlspecialchars($dayLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" style="flex: 1; min-width: 200px;">
        <label for="relay_select"
          style="font-weight: 600; font-size: 14px; color: var(--gold-500); margin-bottom: 6px;">Select Relay</label>
        <select id="relay_select" name="relay_select" class="searchable-select" onchange="this.form.submit()"
          style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 14px;">
          <option value="">— All Relays —</option>
          <?php foreach ($allRelays as $rNo):
            $rSelected = ($selRelayNo === $rNo) ? 'selected' : '';
            ?>
            <option value="<?= $rNo ?>" <?= $rSelected ?>>Relay <?= $rNo ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="toolbar print-hide"
  style="margin-bottom: 24px; display:flex; gap:12px; flex-wrap: wrap; justify-content: flex-end; align-items:center;">
  <?php if (!empty($grouped)): ?>
    <button type="button" class="btn btn-warning" id="btn-swap-toggle" onclick="toggleSwapMode()"
      style="padding: 10px 20px; font-size: 14px; background:#f39c12; border:none; color:#000; font-weight:700; cursor:pointer; border-radius:4px;">
      <i class="bi bi-arrow-left-right"></i> Swap Competitors
    </button>
    <button type="button" class="btn btn-danger" onclick="deleteSelectedStartSheets()"
      style="padding: 10px 20px; font-size: 14px; background:#e74c3c; border:none; color:#fff; font-weight:600; cursor:pointer; border-radius:4px;">
      <i class="bi bi-trash-fill"></i> Delete Event Start List
    </button>
    <button type="button" class="btn" onclick="clearStartSheetEdits()"
      style="padding: 10px 20px; font-size: 14px; background: #6c757d; color: #fff; border: none; font-weight: 600; cursor: pointer; border-radius: 4px;">
      <i class="bi bi-x-circle-fill"></i> Clear Manual Edits
    </button>
    <button type="button" onclick="window.print()" class="btn"
      style="padding: 10px 24px; background:var(--gold-500); color:#000; font-weight:700; font-size: 14px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; line-height: 1.2; vertical-align: middle; box-sizing: border-box; border-radius:4px;">
      🖨 Print Start List
    </button>
  <?php endif; ?>
</div>

<?php
$totalAllocationsCount = 0;
foreach ($grouped as $en => $dates) {
  foreach ($dates as $d => $rMap) {
    foreach ($rMap as $rNo => $aList) {
      $totalAllocationsCount += count($aList);
    }
  }
}
?>

<?php if (empty($grouped) || $totalAllocationsCount === 0): ?>
  <div class="print-hide"
    style="background:rgba(212,175,55,0.03); border:1px dashed rgba(212,175,55,0.25); border-radius:12px; padding:60px 20px; text-align:center; color:var(--text-muted); margin-top:20px;">
    <i class="bi bi-calendar-x"
      style="font-size: 3.5rem; color: var(--gold-400); opacity: 0.7; margin-bottom: 15px; display: inline-block;"></i>
    <h3 style="color: var(--gold-400); font-weight: 700; font-size: 22px; margin-bottom: 8px;">Start List Not Generated
    </h3>
    <p style="font-size: 14px; color: var(--text-secondary); max-width: 500px; margin: 0 auto;">No lane allocations or
      start lists have been generated for the selected event(s) yet.</p>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $en => $dates):
    ksort($dates);
    $eventAllocCount = 0;
    foreach ($dates as $d => $rMap) {
      foreach ($rMap as $rNo => $aList) {
        $eventAllocCount += count($aList);
      }
    }
    if ($eventAllocCount === 0) {
      continue;
    }
    $firstAllocInGroup = null;
    foreach ($dates as $d => $rMap) {
      foreach ($rMap as $rNo => $aList) {
        if (!empty($aList[0])) {
          $firstAllocInGroup = $aList[0];
          break 2;
        }
      }
    }

    $baseKeyForGroup = $groupBaseKeys[$en] ?? '';
    if (empty($baseKeyForGroup) && $firstAllocInGroup) {
      $baseKeyForGroup = !empty($firstAllocInGroup['event_name']) ? $firstAllocInGroup['event_name'] : ($firstAllocInGroup['base_type'] ?? '');
    }
    if (empty($baseKeyForGroup) && !empty($selBaseType)) {
      $baseKeyForGroup = $selBaseType;
    }
    if (empty($baseKeyForGroup)) {
      $baseKeyForGroup = $en;
    }
    ?>
    <div class="discipline-section" style="margin-bottom: 50px;">
      <div class="discipline-title"
        style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 10px;">
        <span><?= htmlspecialchars($en) ?></span>
      </div>

      <?php
      foreach ($dates as $date => $relays):
        ksort($relays);
        $formattedDate = date('d-M-Y', strtotime($date));
        $dayIndex = array_search($date, $allDates, true);
        $dayNum = ($dayIndex !== false) ? ($dayIndex + 1) : 1;

        if ($selDate !== '' && $date !== $selDate) {
          continue;
        }
        ?>
        <div class="day-section" style="margin-bottom: 30px; padding-left: 10px;">
          <div class="day-title">CHAMPIONSHIP DAY <?= $dayNum ?> - <?= strtoupper($formattedDate) ?></div>

          <?php foreach ($relays as $relay => $dayAllocs):
            if ($selRelayNo > 0 && (int) $relay !== $selRelayNo) {
              continue;
            }
            $firstAlloc = !empty($dayAllocs) ? $dayAllocs[0] : null;
            $startTimeStr = '09:00';
            if ($firstAlloc && !empty($firstAlloc['start_time'])) {
              $startTimeStr = date('H:i', strtotime($firstAlloc['start_time']));
            } else {
              $stmtSt = $pdo->prepare("SELECT start_time FROM relay_schedules WHERE scheduled_date = ? AND relay_no = ? AND schedule_name = '' LIMIT 1");
              $stmtSt->execute([$date, (int) $relay]);
              $dbSt = $stmtSt->fetchColumn();
              if ($dbSt) {
                $startTimeStr = date('H:i', strtotime((string) $dbSt));
              } else {
                $rNum = (int) $relay;
                $defHour = 9 + max(0, ($rNum - 1) * 2);
                $startTimeStr = sprintf('%02d:00', $defHour);
              }
            }
            ?>
            <div class="relay-section" style="margin-bottom: 20px; padding-left: 15px;">
              <div
                style="font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 600; color: #27ae60; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                <span>Relay <?= $relay ?> (Start: <?= $startTimeStr ?> Hrs)</span>
                <div class="print-hide" style="display: flex; align-items: center; gap: 10px;">
                  <button type="button" class="btn btn-sm print-hide"
                    onclick="addStartSheetRow(<?= (int) $relay ?>, '<?= htmlspecialchars($date) ?>', '<?= htmlspecialchars($startTimeStr) ?>', '<?= htmlspecialchars($baseKeyForGroup ?? '') ?>')"
                    style="font-size: 12px; font-weight: 600; padding: 5px 14px; background: var(--gold-500); border: none; color: #fff; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="bi bi-plus-lg"></i> Add Row
                  </button>
                  <span
                    style="font-size: 12px; font-weight: 700; color: var(--gold-400); background: rgba(212,175,55,0.12); padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(212,175,55,0.3);">
                    <span class="row-count-val" data-relay="<?= (int) $relay ?>"
                      data-date="<?= htmlspecialchars($date) ?>"><?= count($dayAllocs) ?></span>
                    <?= count($dayAllocs) === 1 ? 'Row' : 'Rows' ?>
                  </span>
                </div>
              </div>

              <div class="table-wrap" style="margin-bottom: 15px;">
                <table class="admin-table">
                  <thead>
                    <tr>
                      <th class="col-compact">St time</th>
                      <th class="col-compact">Rly</th>
                      <th class="col-compact">FP</th>
                      <th class="col-compact">bib no / enroll ID</th>
                      <th class="text-left name-cell">Name</th>
                      <th class="text-left club-cell">Club Name</th>
                      <th class="col-compact">Target Serial No</th>
                      <th style="width: 110px;" class="print-hide">Score Sheet</th>
                      <th class="col-compact">Status</th>
                      <th class="col-compact print-hide" style="width: 45px;">Action</th>
                    </tr>
                  </thead>
                  <tbody data-relay="<?= (int) $relay ?>" data-date="<?= htmlspecialchars($date) ?>">
                    <?php foreach ($dayAllocs as $a):
                      $rowEvt = $a['event_name'];
                      $rowFullName = $EVENTS_MAPPING[$rowEvt] ?? $rowEvt;
                      $rowBaseType = getBackendEventBaseType($rowFullName);
                      if (empty($rowBaseType)) {
                        $cleanName = preg_replace('/\s*\([^)]*\)/', '', $rowFullName);
                        $cleanName = preg_replace('/\b(CHAMPIONSHIP|MEN|WOMEN|INDIVIDUAL|TEAM|MIXED|SH1|SH2)\b/i', '', $cleanName);
                        $rowBaseType = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower(trim($cleanName)));
                        $rowBaseType = preg_replace('/_+/', '_', $rowBaseType);
                        $rowBaseType = trim($rowBaseType, '_');
                      }
                      $rowCat = strtolower($a['category'] ?? 'nr');
                      if ($rowCat === 'nr_mqs')
                        $rowCat = 'nr';
                      if ($rowCat === 'para_deaf') {
                        $rowCat = (strpos(strtoupper($rowFullName), '(ISSF)') !== false) ? 'issf' : 'nr';
                      }
                      $rowBaseKey = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $rowBaseType) . '_' . $rowCat;

                      $rowBib = !empty($a['bib_no']) ? formatBibNo($a['bib_no']) : '';

                      if ($a['custom_name'] !== null) {
                        $rowName = trim((string) $a['custom_name']);
                      } else {
                        $rowName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                      }
                      if (in_array(strtolower($rowName), ['vacant', 'manual competitor', 'manual', 'competitor', 'vacant competitor'], true)) {
                        $rowName = '';
                      }

                      // Club Name should ONLY display if explicitly entered via custom_club_name OR if a real shooter name/BIB is assigned to this row.
                      if (!empty(trim((string) ($a['custom_club_name'] ?? '')))) {
                        $rowClub = trim((string) $a['custom_club_name']);
                      } elseif (!empty($rowName) || !empty($rowBib)) {
                        $rowClub = (!empty($a['club_name']) && trim($a['club_name']) !== '' && trim($a['club_name']) !== '-' && trim($a['club_name']) !== '—')
                          ? $a['club_name']
                          : (!empty($a['district']) && trim($a['district']) !== '' && trim($a['district']) !== '-' ? $a['district'] : '');
                      } else {
                        $rowClub = '';
                      }

                      if (in_array(strtolower($rowClub), ['vacant', 'manual', '—', '-'], true)) {
                        $rowClub = '';
                      }
                      ?>
                      <tr data-alloc-id="<?= htmlspecialchars((string) $a['id']) ?>"
                        data-event-reg-id="<?= htmlspecialchars((string) $a['event_reg_id']) ?>"
                        data-base-type="<?= htmlspecialchars($rowBaseKey) ?>">
                        <td contenteditable="<?= canEdit() ? 'true' : 'false' ?>" class="editable-cell col-compact"
                          data-col="st_time"><?= htmlspecialchars((string) $a['start_time']) ?></td>
                        <td contenteditable="<?= canEdit() ? 'true' : 'false' ?>" class="editable-cell col-compact"
                          data-col="rly"><?= htmlspecialchars((string) $a['relay_no']) ?></td>
                        <td contenteditable="<?= canEdit() ? 'true' : 'false' ?>" class="editable-cell col-compact" data-col="fp">
                          <?= $a['lane_no'] > 0 ? htmlspecialchars((string) $a['lane_no']) : '' ?>
                        </td>
                        <td class="col-compact" style="padding: 0; position: relative;">
                          <input type="text" class="table-input" autocomplete="off" autocorrect="off" autocapitalize="off"
                            spellcheck="false" data-lpignore="true" data-col="bib_enroll_id"
                            value="<?= htmlspecialchars((string) $rowBib) ?>" placeholder="Type BIB / ID..."
                            style="width:100%; border:none; background:transparent; color:#fff; text-align:center; padding:10px; box-sizing:border-box; outline:none; font-family:inherit; font-size:inherit; <?= !canEdit() ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                            <?= !canEdit() ? 'disabled' : '' ?>>
                        </td>
                        <td class="name-cell" style="padding: 0; position: relative;">
                          <input type="text" class="table-input" autocomplete="off" autocorrect="off" autocapitalize="off"
                            spellcheck="false" data-lpignore="true" data-col="name"
                            value="<?= htmlspecialchars((string) $rowName) ?>" placeholder="Type Name..."
                            style="width:100%; border:none; background:transparent; color:#fff; text-align:left; padding:10px; box-sizing:border-box; outline:none; font-family:inherit; font-size:inherit; <?= !canEdit() ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                            <?= !canEdit() ? 'disabled' : '' ?>>
                        </td>
                        <td contenteditable="<?= canEdit() ? 'true' : 'false' ?>" class="editable-cell text-left club-cell"
                          data-col="club_name" style="font-weight: 500; color: var(--text-primary);">
                          <?= htmlspecialchars($rowClub) ?>
                        </td>
                        <td contenteditable="<?= canEdit() ? 'true' : 'false' ?>" class="editable-cell col-compact"
                          data-col="target_serial_no"><?= htmlspecialchars((string) ($a['target_serial_no'] ?? '')) ?></td>
                        <td style="padding: 6px; vertical-align: middle;" class="print-hide">
                          <div style="display: flex; flex-direction: column; align-items: center; gap: 4px; width: 100%;">
                            <?php if (!empty($a['score_sheet_id'])): ?>
                              <a href="<?= getScoreSheetUrl((int) $a['id'], $rowFullName) ?>" class="btn btn-sm print-hide"
                                style="padding: 5px 10px; font-size:11px; background:#2980b9; color:#fff; font-weight:700; text-decoration:none; border-radius:4px; display:inline-block; line-height: 1.2; white-space: nowrap;"><i
                                  class="bi bi-pencil-square"></i> Edit</a>
                            <?php else: ?>
                              <a href="<?= getScoreSheetUrl((int) $a['id'], $rowFullName) ?>" class="btn btn-sm print-hide"
                                style="padding: 5px 10px; font-size:11px; background:var(--gold-500); color:#fff; font-weight:700; text-decoration:none; border-radius:4px; display:inline-block; line-height: 1.2; white-space: nowrap;"><i
                                  class="bi bi-crosshair"></i> Score</a>
                            <?php endif; ?>
                            <div class="print-hide"
                              style="font-size: 11px; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                              <span style="color: var(--text-muted);">Total:</span>
                              <span
                                style="font-size: 11px; font-weight: 700; color: var(--gold-500);"><?= htmlspecialchars((string) ($a['grand_total_val'] ?? '—')) ?></span>
                            </div>
                          </div>
                        </td>
                        <td style="text-align: center; padding: 6px;">
                          <?php $statusVal = $a['score_remarks'] ?? ''; ?>
                          <?php if (!empty($statusVal)): ?>
                            <span
                              style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700;
                                background:<?= in_array($statusVal, ['DNS', 'DSQ', 'DNF', 'DQP']) ? 'rgba(231,76,60,0.15)' : (in_array($statusVal, ['C', 'Completed']) ? 'rgba(39,174,96,0.15)' : 'rgba(255, 255, 255,0.15)') ?>;
                                color:<?= in_array($statusVal, ['DNS', 'DSQ', 'DNF', 'DQP']) ? '#e74c3c' : (in_array($statusVal, ['C', 'Completed']) ? '#27ae60' : '#ADB5BD') ?>;">
                              <?= htmlspecialchars($statusVal === 'Completed' ? 'C' : $statusVal) ?>
                            </span>
                          <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 11px;">—</span>
                          <?php endif; ?>
                        </td>
                        <td style="text-align: center; padding: 6px;" class="print-hide">
                          <button type="button" class="btn btn-sm print-hide"
                            onclick="deleteStartSheetRow(<?= (int) $a['id'] ?>, <?= (int) $a['event_reg_id'] ?>, <?= (int) $relay ?>, '<?= htmlspecialchars($date) ?>')"
                            title="Delete Row"
                            style="padding: 4px 8px; font-size: 11px; background: #e74c3c; color: #fff; border: none; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                            <i class="bi bi-trash"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php
        $dayNum++;
      endforeach;
      ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>


<script src="js/searchable_select.js"></script>
<script>
  var loadedEventKeys = <?= json_encode($loadedBaseKeys) ?>;

  function formatBibNoJS(raw) {
    if (!raw) return '';
    raw = String(raw).trim();
    if (/^\d{5}$/.test(raw)) return raw;
    const m = raw.match(/(\d+)/);
    if (m) {
      let digits = m[1];
      if (digits.length >= 5) return digits.slice(-5);
      return '5' + digits.padStart(4, '0');
    }
    return '';
  }

  function cleanBaseKeyJS(str) {
    if (!str) return '';
    return String(str).toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  function cleanClubNameJS(str) {
    if (!str) return '';
    return String(str)
      .replace(/[\u2018\u2019'`"]/g, '')
      .replace(/&amp;/g, '&')
      .replace(/&#039;/g, '')
      .replace(/[^a-zA-Z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .toUpperCase();
  }

  // Array of all registrations for autocomplete and validation
  const allRegistrations = <?= json_encode($allRegs) ?>;
  const regClubMap = <?= json_encode($regClubMap) ?>;

  // Map of reg_id to full_name AND full_name to reg_id for two-way auto-population
  const regNameMap = {};
  const nameRegMap = {};
  <?php
  foreach ($allActiveRegs as $r) {
    $fullName = !empty($r['custom_name']) ? trim($r['custom_name']) : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    if ($fullName === '')
      continue;
    $nameUpper = strtoupper($fullName);

    $keys = array_filter([
      $r['reg_id'] ?? null,
      $r['event_reg_id'] ?? null,
      $r['bib_no'] ?? null
    ]);

    foreach ($keys as $k) {
      $kTrim = trim((string) $k);
      if ($kTrim === '')
        continue;
      $kUpper = strtoupper($kTrim);
      $digits = preg_replace('/\D/', '', $kTrim);

      echo "regNameMap[" . json_encode($kUpper) . "] = " . json_encode($fullName) . ";\n";
      echo "nameRegMap[" . json_encode($nameUpper) . "] = " . json_encode($kTrim) . ";\n";
      if (!empty($digits)) {
        echo "regNameMap[" . json_encode($digits) . "] = " . json_encode($fullName) . ";\n";
      }
    }
  }
  ?>
  allRegistrations.forEach(r => {
    if (r.reg_id) {
      const formattedBib = formatBibNoJS(r.reg_id);
      const k = r.reg_id.trim().toUpperCase();
      const digits = r.reg_id.trim().replace(/\D/g, '');
      const fn = (r.first_name + ' ' + r.last_name).trim();
      if (fn) {
        if (formattedBib) {
          regNameMap[formattedBib] = fn;
          nameRegMap[fn.toUpperCase()] = formattedBib;
        }
        regNameMap[k] = fn;
        if (digits) regNameMap[digits] = fn;
      }
    }
  });

  let activeSuggestionBox = null;

  function closeActiveSuggestionBox() {
    if (activeSuggestionBox) {
      if (typeof activeSuggestionBox.cleanupKeyboard === 'function') {
        activeSuggestionBox.cleanupKeyboard();
      }
      activeSuggestionBox.remove();
      activeSuggestionBox = null;
    }
  }

  document.addEventListener('click', (e) => {
    if (activeSuggestionBox && !activeSuggestionBox.contains(e.target) && !e.target.classList.contains('table-input')) {
      closeActiveSuggestionBox();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeActiveSuggestionBox();
  });

  window.addEventListener('scroll', (e) => {
    if (activeSuggestionBox && (e.target === activeSuggestionBox || activeSuggestionBox.contains(e.target))) {
      return;
    }
    closeActiveSuggestionBox();
  }, true);
  window.addEventListener('resize', closeActiveSuggestionBox);

  function showCustomSuggestionBox(input) {
    closeActiveSuggestionBox();

    const row = input.closest('tr');
    if (!row) return;
    const col = input.getAttribute('data-col');
    const parentTd = input.closest('td');
    if (!parentTd) return;

    const rowBaseType = row.getAttribute('data-base-type') || (typeof loadedEventKeys !== 'undefined' && loadedEventKeys.length === 1 ? loadedEventKeys[0] : '');
    const cleanRowBase = cleanBaseKeyJS(rowBaseType);

    const rlyCell = row.querySelector('[data-col="rly"]');
    const currentRowRelay = rlyCell ? rlyCell.textContent.trim() : '';

    const allocatedShooterRelays = {};
    document.querySelectorAll('tr[data-alloc-id]').forEach(tr => {
      const trBase = tr.getAttribute('data-base-type') || '';
      if (cleanRowBase && trBase) {
        const cleanTrBase = cleanBaseKeyJS(trBase);
        if (cleanTrBase && cleanTrBase !== cleanRowBase && !cleanTrBase.includes(cleanRowBase) && !cleanRowBase.includes(cleanTrBase)) {
          return;
        }
      }

      const trRlyCell = tr.querySelector('[data-col="rly"]');
      const trRly = trRlyCell ? trRlyCell.textContent.trim() : '';
      const bibInp = tr.querySelector('.table-input[data-col="bib_enroll_id"]');
      const nameInp = tr.querySelector('.table-input[data-col="name"]');

      if (bibInp) {
        const bVal = bibInp.value.trim().toUpperCase();
        if (bVal && trRly) allocatedShooterRelays[bVal] = trRly;
      }
      if (nameInp) {
        const nVal = nameInp.value.trim().toUpperCase();
        if (nVal && trRly) allocatedShooterRelays[nVal] = trRly;
      }
    });

    const query = input.value.trim().toLowerCase();

    // Filter eligible shooters by search query across all registrations
    let eligible = allRegistrations.filter(r => {
      const formattedBib = formatBibNoJS(r.reg_id);
      const fullName = (r.full_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || r.custom_name || regNameMap[formattedBib] || regNameMap[r.reg_id] || '').trim();

      if (!fullName) return false;

      if (query.length === 0) return true;

      const fnLower = fullName.toLowerCase();
      const bibLower = formattedBib.toLowerCase();
      const regLower = (r.reg_id || '').toLowerCase();
      return fnLower.includes(query) || bibLower.includes(query) || regLower.includes(query);
    });

    const rect = input.getBoundingClientRect();
    const box = document.createElement('div');
    box.className = 'custom-suggestion-box';

    const boxMaxHeight = 220;
    const spaceBelow = window.innerHeight - rect.bottom;
    const spaceAbove = rect.top;

    let stylePos;
    if (spaceBelow < boxMaxHeight && spaceAbove > spaceBelow) {
      stylePos = 'bottom: ' + (window.innerHeight - rect.top + 2) + 'px;';
    } else {
      stylePos = 'top: ' + (rect.bottom + 2) + 'px;';
    }

    const leftPos = rect.left;
    const widthVal = Math.max(rect.width, 380);

    box.style.cssText = 'position: fixed; ' + stylePos + ' left: ' + leftPos + 'px; width: ' + widthVal + 'px; max-height: ' + boxMaxHeight + 'px; overflow-y: auto; background: #181818; border: 1px solid var(--gold-500); border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.9); z-index: 100000; font-family: inherit; font-size: 13px;';

    if (eligible.length === 0) {
      const emptyMsg = document.createElement('div');
      emptyMsg.style.cssText = 'padding: 12px; color: #888; text-align: center; font-size: 12px; font-style: italic;';
      emptyMsg.textContent = 'No registered shooters found';
      box.appendChild(emptyMsg);
    } else {
      eligible.forEach(r => {
        const item = document.createElement('div');
        item.className = 'suggestion-item';
        item.style.cssText = 'padding: 9px 12px; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center; transition: background 0.15s; color: #fff;';

        const formattedBib = formatBibNoJS(r.reg_id);
        let fullName = (r.full_name || (r.first_name + ' ' + r.last_name).trim() || r.custom_name || regNameMap[formattedBib] || regNameMap[r.reg_id] || '').trim();
        const nameDisplay = fullName ? fullName : '<span style="color: #888; font-style: italic;">Name Not Specified</span>';

        const formattedBibUpper = formattedBib.toUpperCase();
        const rawBibUpper = (r.reg_id || '').toUpperCase();
        const fullNameUpper = fullName.toUpperCase();

        const targetRelay = allocatedShooterRelays[formattedBibUpper] || allocatedShooterRelays[rawBibUpper] || allocatedShooterRelays[fullNameUpper] || null;
        const allocBadge = targetRelay ? `<span style="font-size: 11px; color: #ff9f43; font-weight: 700; margin-left: 6px;">(Allocated in Rly ${targetRelay})</span>` : '';

        item.innerHTML = `
          <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
            <span style="font-weight:700; color:var(--gold-400); min-width:45px;">${formattedBib}</span>
            <span style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${nameDisplay}</span>
            ${allocBadge}
          </div>
          <div style="font-size:11px; color:#aaa; font-weight:500; text-align:right; margin-left:12px; white-space:nowrap;">
            ${r.club_name || '—'}
          </div>
        `;

        item.addEventListener('mouseenter', () => {
          item.style.background = 'rgba(212, 175, 55, 0.25)';
        });
        item.addEventListener('mouseleave', () => {
          item.style.background = 'transparent';
        });

        item.addEventListener('mousedown', async (e) => {
          e.preventDefault();

          if (targetRelay) {
            closeActiveSuggestionBox();
            Swal.fire({
              title: 'Duplicate Relay Allocation',
              text: `Competitor "${fullName}" (${formattedBib}) is already allocated in Relay ${targetRelay}.`,
              icon: 'warning',
              background: '#1a1a1a',
              color: '#fff'
            });
            return;
          }

          const tbody = row.closest('tbody');
          const scheduledDate = tbody ? tbody.getAttribute('data-date') : '';
          const relayNo = currentRowRelay;
          const shooterClub = r.club_name || r.district || '—';

          if (shooterClub && shooterClub !== '—' && shooterClub !== '-') {
            try {
              const qResp = await fetch(`actions/check_club_quota.php?club_name=${encodeURIComponent(shooterClub)}&scheduled_date=${encodeURIComponent(scheduledDate)}&relay_no=${encodeURIComponent(relayNo)}`);
              const qRes = await qResp.json();

              if (qRes.success && qRes.requires_warning) {
                closeActiveSuggestionBox();
                const confirmResult = await Swal.fire({
                  title: 'Confirm Allocation',
                  html: `Shooter <strong>${fullName}</strong>'s club (<strong>${shooterClub}</strong>) is not allocated in Relay ${relayNo}.<br><br>Are you sure you want to allocate this person to this relay?`,
                  icon: 'question',
                  showCancelButton: true,
                  confirmButtonColor: '#27ae60',
                  cancelButtonColor: '#7f8c8d',
                  confirmButtonText: 'Yes, Allocate',
                  cancelButtonText: 'Cancel',
                  background: '#1a1a1a',
                  color: '#fff'
                });

                if (!confirmResult.isConfirmed) {
                  return;
                }
              }
            } catch (qErr) {
              console.warn('Quota check error:', qErr);
            }
          }

          const bibInp = row.querySelector('.table-input[data-col="bib_enroll_id"]');
          const nameInp = row.querySelector('.table-input[data-col="name"]');
          const clubCell = row.querySelector('[data-col="club_name"]');

          if (bibInp) bibInp.value = formattedBib;
          if (nameInp) nameInp.value = fullName;
          if (clubCell) clubCell.textContent = shooterClub;

          closeActiveSuggestionBox();

          input.dispatchEvent(new Event('change', { bubbles: true }));
          input.blur();
        });

        box.appendChild(item);
      });
    }

    document.body.appendChild(box);
    activeSuggestionBox = box;

    let highlightedIndex = -1;
    const items = box.querySelectorAll('.suggestion-item');

    function updateHighlight() {
      items.forEach((item, idx) => {
        if (idx === highlightedIndex) {
          item.style.background = 'rgba(212, 175, 55, 0.35)';
          item.scrollIntoView({ block: 'nearest' });
        } else {
          item.style.background = 'transparent';
        }
      });
    }

    function handleKeydown(e) {
      if (!activeSuggestionBox) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = (highlightedIndex + 1) % items.length;
        updateHighlight();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = (highlightedIndex - 1 + items.length) % items.length;
        updateHighlight();
      } else if (e.key === 'Enter') {
        if (highlightedIndex >= 0 && highlightedIndex < items.length) {
          e.preventDefault();
          const item = items[highlightedIndex];
          item.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
        }
      } else if (e.key === 'Escape') {
        closeActiveSuggestionBox();
      }
    }

    input.addEventListener('keydown', handleKeydown);

    box.cleanupKeyboard = () => {
      input.removeEventListener('keydown', handleKeydown);
    };
  }

  function bindRowListeners(row) {
    if (!row || row._listenersBound) return;
    row._listenersBound = true;

    const allocId = row.getAttribute('data-alloc-id');
    if (!allocId) return;

    row.querySelectorAll('.editable-cell').forEach(cell => {
      const col = cell.getAttribute('data-col');
      if (!col) return;

      let oldValue = cell.textContent.trim();
      cell.addEventListener('focus', () => { oldValue = cell.textContent.trim(); });
      cell.addEventListener('blur', () => {
        const val = cell.textContent.trim();
        if (val === oldValue) return;
        const fd = new FormData();
        fd.append('alloc_id', allocId);
        fd.append('col', col);
        fd.append('val', val);
        fetch('actions/update_start_sheet_cell.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (!res.success) {
              Swal.fire({ title: 'Update Failed', text: res.message, icon: 'error', background: '#1a1a1a', color: '#fff' });
              cell.textContent = oldValue;
            } else {
              oldValue = val;
            }
          })
          .catch(err => { cell.textContent = oldValue; });
      });
      cell.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); cell.blur(); } });
    });

    row.querySelectorAll('.table-input').forEach(input => {
      const col = input.getAttribute('data-col');
      if (!col) return;

      let oldValue = input.value.trim();

      input.addEventListener('focus', () => {
        oldValue = input.value.trim();
        if (col === 'bib_enroll_id' || col === 'name') showCustomSuggestionBox(input);
      });
      input.addEventListener('click', () => {
        if (col === 'bib_enroll_id' || col === 'name') showCustomSuggestionBox(input);
      });
      input.addEventListener('input', () => {
        if (col === 'bib_enroll_id' || col === 'name') showCustomSuggestionBox(input);
      });

      input.addEventListener('blur', () => {
        const val = input.value.trim();
        if (val === oldValue) return;

        let saveCol = col;
        let saveVal = val;
        if (col === 'name') {
          const bibInput = row.querySelector('.table-input[data-col="bib_enroll_id"]');
          const clubCell = row.querySelector('[data-col="club_name"]');
          if (val === '') {
            saveCol = 'bib_enroll_id';
            saveVal = '';
            if (bibInput) bibInput.value = '';
            if (clubCell) clubCell.textContent = '';
          } else {
            const matchedBib = nameRegMap[val.toUpperCase()];
            if (matchedBib) {
              saveCol = 'bib_enroll_id';
              saveVal = matchedBib;
              if (bibInput) bibInput.value = matchedBib;
              if (clubCell && regClubMap[matchedBib]) clubCell.textContent = regClubMap[matchedBib];
            } else {
              saveCol = 'name';
              saveVal = val;
            }
          }
        } else if (col === 'bib_enroll_id') {
          const nameInput = row.querySelector('.table-input[data-col="name"]');
          const clubCell = row.querySelector('[data-col="club_name"]');
          const valUpper = val.toUpperCase();
          if (regNameMap[valUpper] && nameInput) {
            nameInput.value = regNameMap[valUpper];
          }
          if (regClubMap[valUpper] && clubCell) {
            clubCell.textContent = regClubMap[valUpper];
          }
        }

        const fd = new FormData();
        fd.append('alloc_id', allocId);
        fd.append('col', saveCol);
        fd.append('val', saveVal);

        fetch('actions/update_start_sheet_cell.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (!res.success) {
              const popupTitle = res.not_registered_event ? 'Not Registered for Event' : 'Update Failed';
              Swal.fire({ title: popupTitle, text: res.message, icon: 'error', background: '#1a1a1a', color: '#fff' });

              const bibInp = row.querySelector('.table-input[data-col="bib_enroll_id"]');
              const nameInp = row.querySelector('.table-input[data-col="name"]');
              const clubCell = row.querySelector('[data-col="club_name"]');

              if (bibInp) bibInp.value = '';
              if (nameInp) nameInp.value = '';
              if (clubCell) clubCell.textContent = '—';
            } else {
              oldValue = val;
              const bibInp = row.querySelector('.table-input[data-col="bib_enroll_id"]');
              const nameInp = row.querySelector('.table-input[data-col="name"]');
              const clubCell = row.querySelector('[data-col="club_name"]');

              if (res.resolved_bib && bibInp) bibInp.value = res.resolved_bib;
              if (res.resolved_name && nameInp) nameInp.value = res.resolved_name;
              if (res.resolved_club && clubCell) clubCell.textContent = res.resolved_club;
            }
          })
          .catch(err => { input.value = oldValue; });
      });

      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
    });
  }
  window.bindRowListeners = bindRowListeners;

  async function deleteSelectedStartSheets() {
    const eventsToDelete = (loadedEventKeys && loadedEventKeys.length > 0) ? loadedEventKeys : ['ALL'];
    const msg = eventsToDelete.includes('ALL')
      ? 'Are you sure you want to delete ALL start sheets across all events? This will permanently remove all database lane allocations and schedules.'
      : 'Are you sure you want to delete the start sheets for all currently loaded events? This will permanently remove all database lane allocations and schedules for these events.';

    const result = await Swal.fire({
      title: 'Delete Start Sheet?',
      text: msg,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete start sheet!',
      background: '#1a1a1a',
      color: '#fff'
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('events', JSON.stringify(eventsToDelete));

    try {
      const resp = await fetch('actions/delete_start_sheet.php', { method: 'POST', body: fd });
      const res = await resp.json();
      if (res.success) {
        await Swal.fire({
          title: 'Deleted!',
          text: res.message || 'Start sheet deleted successfully.',
          icon: 'success',
          timer: 1500,
          showConfirmButton: false,
          background: '#1a1a1a',
          color: '#fff'
        });
        window.location.href = 'start_sheet.php' + window.location.search;
      } else {
        Swal.fire({
          title: 'Error',
          text: res.message || 'Failed to delete start sheet.',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    } catch (e) {
      Swal.fire({
        title: 'Error',
        text: 'Network error while deleting start sheet.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
    }
  }

  async function deleteSpecificStartSheet(key) {
    const result = await Swal.fire({
      title: 'Delete Start Sheet?',
      text: 'Are you sure you want to delete the start sheet for this event? This will remove all database lane allocations and schedules for this event.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it!',
      background: '#1a1a1a',
      color: '#fff'
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('events', JSON.stringify([key]));

    try {
      const resp = await fetch('actions/delete_start_sheet.php', { method: 'POST', body: fd });
      const res = await resp.json();
      if (res.success) {
        localStorage.removeItem('laneAlloc_' + key);
        await Swal.fire({
          title: 'Deleted!',
          text: res.message || 'Start sheet deleted successfully.',
          icon: 'success',
          timer: 1500,
          showConfirmButton: false,
          background: '#1a1a1a',
          color: '#fff'
        });
        window.location.reload();
      } else {
        Swal.fire({
          title: 'Error',
          text: res.message || 'Failed to delete start sheet.',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    } catch (e) {
      Swal.fire({
        title: 'Error',
        text: 'Network error while deleting start sheet.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
    }
  }

  async function addStartSheetRow(relayNo, scheduledDate, startTime, baseKey) {
    const fd = new FormData();
    fd.append('relay_no', relayNo);
    fd.append('scheduled_date', scheduledDate);
    fd.append('start_time', startTime);
    fd.append('event_name', baseKey);

    try {
      const resp = await fetch('actions/add_start_sheet_row.php', { method: 'POST', body: fd });
      const res = await resp.json();
      if (res.success) {
        const dataObj = res.data || {};
        const allocId = res.alloc_id || dataObj.id;
        const eventRegId = dataObj.event_reg_id || '';
        const laneNo = (dataObj.lane_no && Number(dataObj.lane_no) > 0) ? dataObj.lane_no : '';
        const stTime = dataObj.start_time || startTime || '09:00:00';
        const rlyNo = dataObj.relay_no || relayNo;

        const tbody = document.querySelector(`tbody[data-relay="${relayNo}"][data-date="${scheduledDate}"]`);
        if (tbody) {
          const tr = document.createElement('tr');
          tr.setAttribute('data-alloc-id', String(allocId));
          tr.setAttribute('data-event-reg-id', String(eventRegId));
          tr.setAttribute('data-base-type', baseKey);

          tr.innerHTML = `
            <td contenteditable="true" class="editable-cell col-compact" data-col="st_time">${stTime}</td>
            <td contenteditable="true" class="editable-cell col-compact" data-col="rly">${rlyNo}</td>
            <td contenteditable="true" class="editable-cell col-compact" data-col="fp">${laneNo}</td>
            <td class="col-compact" style="padding: 0; position: relative;">
              <input type="text" class="table-input" autocomplete="chrome-off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-col="bib_enroll_id" value="" placeholder="Type BIB / ID..." style="width:100%; border:none; background:transparent; color:#fff; text-align:center; padding:10px; box-sizing:border-box; outline:none; font-family:inherit; font-size:inherit;">
            </td>
            <td class="name-cell" style="padding: 0; position: relative;">
              <input type="text" class="table-input" autocomplete="chrome-off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-col="name" value="" placeholder="Type Name..." style="width:100%; border:none; background:transparent; color:#fff; text-align:left; padding:10px; box-sizing:border-box; outline:none; font-family:inherit; font-size:inherit;">
            </td>
            <td contenteditable="true" class="editable-cell text-left club-cell" data-col="club_name" style="font-weight: 500; color: var(--text-primary);">—</td>
            <td contenteditable="true" class="editable-cell col-compact" data-col="target_serial_no"></td>
            <td style="padding: 6px; vertical-align: middle;" class="print-hide">
              <div style="display: flex; flex-direction: column; align-items: center; gap: 4px; width: 100%;">
                <a href="../Score Sheet/air-rifle-pistol.php?alloc_id=${allocId}" class="btn btn-sm print-hide" style="padding: 5px 10px; font-size:11px; background:var(--gold-500); color:#fff; font-weight:700; text-decoration:none; border-radius:4px; display:inline-block; line-height: 1.2; white-space: nowrap;"><i class="bi bi-crosshair"></i> Score</a>
                <div class="print-hide" style="font-size: 11px; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                  <span style="color: var(--text-muted);">Total:</span>
                  <span style="font-size: 11px; font-weight: 700; color: var(--gold-500);">—</span>
                </div>
              </div>
            </td>
            <td style="text-align: center; padding: 6px;">
              <span style="color: var(--text-muted); font-size: 11px;">—</span>
            </td>
            <td style="text-align: center; padding: 6px;" class="print-hide">
              <button type="button" class="btn btn-sm print-hide"
                      onclick="deleteStartSheetRow(${allocId}, ${eventRegId}, ${relayNo}, '${scheduledDate}')"
                      title="Delete Row"
                      style="padding: 4px 8px; font-size: 11px; background: #e74c3c; color: #fff; border: none; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          `;

          tbody.appendChild(tr);

          // Update count badge
          const countSpan = document.querySelector(`.row-count-val[data-relay="${relayNo}"][data-date="${scheduledDate}"]`);
          if (countSpan) {
            const count = tbody.querySelectorAll('tr[data-alloc-id]').length;
            countSpan.textContent = count;
          }

          if (typeof window.bindRowListeners === 'function') {
            window.bindRowListeners(tr);
          }
        }
      } else {
        Swal.fire({
          title: 'Error',
          text: res.message || 'Failed to add row.',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    } catch (e) {
      Swal.fire({
        title: 'Error',
        text: 'Network error while adding row.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
    }
  }

  async function deleteStartSheetRow(allocId, eventRegId, relayNo, scheduledDate) {
    const result = await Swal.fire({
      title: 'Delete Row?',
      text: 'Are you sure you want to remove this row?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it!',
      background: '#1a1a1a',
      color: '#fff'
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('alloc_id', allocId);
    fd.append('event_reg_id', eventRegId);

    try {
      const resp = await fetch('actions/delete_lane_allocation.php', { method: 'POST', body: fd });
      const res = await resp.json();
      if (res.success) {
        const tr = document.querySelector(`tr[data-alloc-id="${allocId}"]`);
        if (tr) {
          const tbody = tr.closest('tbody');
          tr.remove();
          if (tbody) {
            const countSpan = document.querySelector(`.row-count-val[data-relay="${relayNo}"][data-date="${scheduledDate}"]`);
            if (countSpan) {
              const count = tbody.querySelectorAll('tr[data-alloc-id]').length;
              countSpan.textContent = count;
            }
          }
        }
        Swal.fire({
          title: 'Deleted!',
          text: res.message || 'Row has been deleted.',
          icon: 'success',
          timer: 1500,
          showConfirmButton: false,
          background: '#1a1a1a',
          color: '#fff'
        });
      } else {
        Swal.fire({
          title: 'Error',
          text: res.message || 'Failed to delete row.',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    } catch (e) {
      Swal.fire({
        title: 'Error',
        text: 'Network error while deleting row.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
    }
  }

  async function clearStartSheetEdits() {
    const eventsToClear = (loadedEventKeys && loadedEventKeys.length > 0) ? loadedEventKeys : ['ALL'];
    const dateSel = document.getElementById('date_select');
    const relaySel = document.getElementById('relay_select');
    const curDate = dateSel ? dateSel.value : '';
    const curRelay = relaySel ? relaySel.value : '';

    let scopeMsg = 'for the currently loaded start list';
    if (curDate && curRelay) {
      scopeMsg = `for Date "${curDate}" and Relay ${curRelay}`;
    } else if (curDate) {
      scopeMsg = `for Date "${curDate}"`;
    } else if (curRelay) {
      scopeMsg = `for Relay ${curRelay}`;
    }

    const confirmResult = await Swal.fire({
      title: 'Clear Manual Edits?',
      text: `Are you sure you want to clear all manual edits ${scopeMsg}? This will reset typed competitor names, BIBs, custom clubs, target serial numbers, and scores back to empty inputs without deleting the table structure or rows.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e67e22',
      cancelButtonColor: '#7f8c8d',
      confirmButtonText: 'Yes, Clear Edits',
      cancelButtonText: 'Cancel',
      background: '#1a1a1a',
      color: '#fff'
    });

    if (!confirmResult.isConfirmed) return;

    const fd = new FormData();
    fd.append('events', JSON.stringify(eventsToClear));
    if (curDate) fd.append('date', curDate);
    if (curRelay) fd.append('relay_no', curRelay);

    try {
      const resp = await fetch('actions/clear_manual_edits.php', { method: 'POST', body: fd });
      const res = await resp.json();
      if (res.success) {
        Object.keys(localStorage).forEach(key => {
          if (key.startsWith('start_sheet_edit_')) {
            localStorage.removeItem(key);
          }
        });
        await Swal.fire({
          title: 'Cleared!',
          text: res.message || 'Manual cell edits cleared successfully.',
          icon: 'success',
          timer: 1500,
          showConfirmButton: false,
          background: '#1a1a1a',
          color: '#fff'
        });
        window.location.reload();
      } else {
        Swal.fire({
          title: 'Error',
          text: res.message || 'Failed to clear manual edits.',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    } catch (e) {
      Swal.fire({
        title: 'Error',
        text: 'Network error while clearing manual edits.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('tr[data-alloc-id]').forEach(tr => {
      if (typeof window.bindRowListeners === 'function') {
        window.bindRowListeners(tr);
      }
    });

    // Do NOT auto-populate empty name or bib inputs on load; allocation is managed manually by admin
    document.querySelectorAll('tr[data-alloc-id]').forEach(row => {
      const bibInp = row.querySelector('.table-input[data-col="bib_enroll_id"]');
      const nameInp = row.querySelector('.table-input[data-col="name"]');
      if (bibInp && nameInp) {
        const bVal = bibInp.value.trim().toUpperCase();
        const nVal = nameInp.value.trim().toUpperCase();
        if (bVal && !nVal && regNameMap[bVal]) {
          nameInp.value = regNameMap[bVal];
        }
      }
    });
  });

    // Handle event selector dropdown change
    const eventSelect = document.getElementById('event_select');
  if (eventSelect) {
    eventSelect.addEventListener('change', () => {
      const val = eventSelect.value;
      if (!val) {
        window.location.href = 'start_sheet.php';
      } else {
        window.location.href = `start_sheet.php?base_type=${encodeURIComponent(val)}`;
      }
    });

    if (typeof initSearchableSelect === 'function') {
      initSearchableSelect(eventSelect, { placeholder: '🔍 Search or choose event…', allLabel: '— All Events —' });
    }
  }

  const dateSelect = document.getElementById('date_select');
  const relaySelect = document.getElementById('relay_select');

  function reloadWithFilters() {
    const val = eventSelect.value;
    const rVal = relaySelect ? relaySelect.value : '';
    const dVal = dateSelect ? dateSelect.value : '';
    let url = `start_sheet.php?base_type=${encodeURIComponent(val)}`;
    if (dVal) {
      url += `&date=${encodeURIComponent(dVal)}`;
    }
    if (rVal) {
      url += `&relay_no=${encodeURIComponent(rVal)}`;
    }
    window.location.href = url;
  }

  if (dateSelect) {
    dateSelect.addEventListener('change', reloadWithFilters);
  }
  if (relaySelect) {
    relaySelect.addEventListener('change', reloadWithFilters);
  }

  // Competitor Swap Logic
  let swapModeActive = false;
  let swapInProgress = false;   // guard against concurrent swap clicks
  let selectedSwapAllocIds = [];

  // Helper: apply/remove the selected-row highlight
  function setRowSelected(row, selected) {
    if (selected) {
      row.style.outline = '2px solid #2ecc71';
      row.style.background = 'rgba(46, 204, 113, 0.18)';
      row.style.cursor = 'pointer';
    } else {
      row.style.outline = '';
      row.style.background = '';
      row.style.cursor = 'pointer'; // keep pointer while in swap mode
    }
  }

  // Helper: clear all row highlights
  function clearAllRowHighlights() {
    document.querySelectorAll('tr[data-alloc-id]').forEach(row => {
      row.style.outline = '';
      row.style.background = '';
      row.style.cursor = '';
      // Restore all contenteditable cells
      row.querySelectorAll('[contenteditable]').forEach(cell => {
        cell.setAttribute('contenteditable', 'true');
        cell.style.pointerEvents = '';
        cell.style.userSelect = '';
      });
    });
  }

  window.toggleSwapMode = function () {
    swapModeActive = !swapModeActive;
    const btn = document.getElementById('btn-swap-toggle');
    if (!btn) return;

    if (swapModeActive) {
      selectedSwapAllocIds = [];
      btn.textContent = '\u274C Cancel Swap';
      btn.style.background = '#e74c3c';
      btn.style.color = '#fff';

      // Make every data row look clickable and disable editing
      document.querySelectorAll('tr[data-alloc-id]').forEach(row => {
        row.style.cursor = 'pointer';
        // Disable contenteditable so clicks don't drop into edit mode
        row.querySelectorAll('[contenteditable]').forEach(cell => {
          cell.setAttribute('contenteditable', 'false');
          cell.style.pointerEvents = 'none';
          cell.style.userSelect = 'none';
        });
        // Also disable any inputs (bib field)
        row.querySelectorAll('input').forEach(inp => {
          inp.style.pointerEvents = 'none';
        });
      });

      Swal.fire({
        title: 'Swap Mode Active',
        text: 'Click on any row to select a competitor, then click a second row to swap them.',
        icon: 'info',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4000,
        background: '#1a1a1a',
        color: '#fff'
      });
    } else {
      selectedSwapAllocIds = [];
      btn.textContent = '\uD83D\uDD04 Swap Competitors';
      btn.style.background = '#f39c12';
      btn.style.color = '#000';
      clearAllRowHighlights();
    }
  };

  // Use event delegation on the document so ALL rows (including those inside
  // dynamically generated relay sections) are covered by a single listener.
  document.addEventListener('click', function (e) {
    if (!swapModeActive) return;

    // Walk up from the clicked element to find the data row
    const row = e.target.closest('tr[data-alloc-id]');
    if (!row) return;

    // Ignore clicks on buttons / links / action cells
    if (e.target.closest('a, button')) return;

    e.preventDefault();
    e.stopPropagation();

    const allocId = row.getAttribute('data-alloc-id');
    if (!allocId) return;

    if (selectedSwapAllocIds.includes(allocId)) {
      // Deselect this row
      selectedSwapAllocIds = selectedSwapAllocIds.filter(id => id !== allocId);
      setRowSelected(row, false);
    } else {
      if (swapInProgress) return;
      // Select this row
      selectedSwapAllocIds.push(allocId);
      setRowSelected(row, true);

      if (selectedSwapAllocIds.length === 2) {
        swapInProgress = true;
        performSwap(selectedSwapAllocIds[0], selectedSwapAllocIds[1]);
      }
    }
  });

  async function performSwap(id1, id2) {
    Swal.fire({
      title: 'Swapping...',
      text: 'Please wait while we swap the competitors.',
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
      background: '#1a1a1a',
      color: '#fff'
    });

    const fd = new FormData();
    fd.append('id1', id1);
    fd.append('id2', id2);

    try {
      const resp = await fetch('actions/swap_allocations.php', {
        method: 'POST',
        body: fd
      });
      const contentType = resp.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        // Likely a session-expired redirect returning HTML
        Swal.fire({
          title: 'Session Expired',
          text: 'Your session has expired. Please refresh the page and log in again.',
          icon: 'warning',
          background: '#1a1a1a',
          color: '#fff'
        });
        swapInProgress = false;
        toggleSwapMode();
        return;
      }
      const res = await resp.json();

      if (res.success) {
        Swal.fire({
          title: 'Success!',
          text: res.message,
          icon: 'success',
          background: '#1a1a1a',
          color: '#fff'
        }).then(() => {
          swapInProgress = false;
          toggleSwapMode();
          window.location.reload();
        });
      } else {
        Swal.fire({
          title: 'Swap Failed',
          text: res.message,
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
        swapInProgress = false;
        toggleSwapMode();
      }
    } catch (e) {
      console.error('Error swapping competitors:', e);
      Swal.fire({
        title: 'Error',
        text: 'A network error occurred. Please try again.',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
      swapInProgress = false;
      toggleSwapMode();
    }
  }
</script>
<script src="js/searchable_select.js"></script>

<?php
require_once 'includes/footer.php';
?>