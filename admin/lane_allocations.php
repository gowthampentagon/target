<?php
// admin/lane_allocations.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/events.php';
$pageTitle = 'Lane Allocations';
require_once 'includes/header.php';

try {
  $pdo = getDB();
  require_once __DIR__ . '/actions/get_weapon_type.php';

  // Auto-create custom_event_names table
  $pdo->exec("
        CREATE TABLE IF NOT EXISTS `custom_event_names` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `event_name` VARCHAR(255) NOT NULL UNIQUE,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

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

  // 3. Custom events
  $customEvents = $pdo->query("SELECT event_name FROM custom_event_names")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($customEvents as $evt) {
    $addEventToBaseGroups($evt, 'nr');
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
  // Sort options alphabetically by label
  usort($dropdownOptions, function ($a, $b) {
    return strcmp($a['label'], $b['label']);
  });

  $selBaseType = trim($_GET['base_type'] ?? '');

  // Support fallback from old query parameters if present
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
  }

  $selEvent = '';
  $selCategory = '';
  $selectedEvent = '';

  if ($selBaseType !== '') {
    if (isset($baseGroups[$selBaseType])) {
      $selectedEvent = formatBaseEventName($selBaseType);
      // Populate variables for compatibility in HTML output
      $selEvent = $selBaseType;
      if (substr($selBaseType, -5) === '_issf') {
        $selCategory = 'ISSF';
      } elseif (substr($selBaseType, -10) === '_para_deaf') {
        $selCategory = 'PARA_DEAF';
      } else {
        $selCategory = 'NR';
      }
      $baseGroups = [$selBaseType => $baseGroups[$selBaseType]];
    } else {
      $baseGroups = [];
    }
  } else {
    $baseGroups = [];
  }
  $weaponLabels = [];
  $weaponKeys = [];
  foreach (array_keys($baseGroups) as $wt) {
    $key = preg_replace('/[^a-zA-Z0-9]/', '', $wt);
    $weaponKeys[$wt] = $key;
    $weaponLabels[$wt] = formatBaseEventName($wt);
  }

  // ── Build per-weapon-type panel data ──
  $weaponPanels = [];

  foreach ($baseGroups as $wt => $wtEvents) {
    $panel = [
      'events' => $wtEvents,
      'totalShooters' => 0,
      'allocatedShooters' => 0,
      'unallocatedShooters' => 0,
      'allocations' => [],
      'clubs' => [],
      'clubRegTotals' => [],
      'clubAllocTotals' => [],
      'cols' => [],
      'pivot' => [],
    ];

    if (!empty($wtEvents)) {
      $inClause = implode(',', array_fill(0, count($wtEvents), '?'));

      $categoryFilter = '';
      if (substr($wt, -5) === '_issf') {
        $categoryFilter = 'ISSF';
      } elseif (substr($wt, -3) === '_nr') {
        $categoryFilter = 'NR';
      } elseif (substr($wt, -10) === '_para_deaf') {
        $categoryFilter = 'PARA_DEAF';
      }

      $catQuery = "";
      $execParams = array_merge($wtEvents, $wtEvents);
      if (!empty($categoryFilter)) {
        if ($categoryFilter === 'NR') {
          $catQuery = " AND (er.category IS NULL OR er.category = '' OR UPPER(er.category) IN ('NR', 'NR_MQS', 'NR-MQS')) ";
        } elseif ($wt === '10m_air_pistol_issf') {
          $catQuery = " AND (UPPER(er.category) LIKE '%ISSF%' OR UPPER(er.category) LIKE '%PARA%' OR UPPER(er.category) LIKE '%DEAF%' OR er.category IS NULL OR er.category = '') ";
        } else {
          $catQuery = " AND UPPER(er.category) LIKE ? ";
          $execParams[] = '%' . strtoupper($categoryFilter) . '%';
        }
      }

      $statusQuery = " AND (er.status IS NULL OR er.status = '' OR LOWER(er.status) IN ('approved', 'active', 'pending')) ";

      // Total approved/active distinct physical shooters for weapon group
      $stmt = $pdo->prepare("SELECT COUNT(DISTINCT er.user_id) FROM event_registrations er JOIN registrations r ON er.user_id = r.id WHERE (er.event_name IN ($inClause) OR er.event_code IN ($inClause)) AND LOWER(r.club_name) != 'vacant' AND LOWER(r.first_name) != 'vacant' $catQuery $statusQuery");
      $stmt->execute($execParams);
      $panel['totalShooters'] = (int) $stmt->fetchColumn();

      // Allocated distinct physical shooters for weapon group
      $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT er.user_id) 
                FROM lane_allocations la
                JOIN event_registrations er ON la.event_reg_id = er.id
                JOIN registrations r ON er.user_id = r.id
                WHERE (er.event_name IN ($inClause) OR er.event_code IN ($inClause)) AND LOWER(r.club_name) != 'vacant' AND LOWER(r.first_name) != 'vacant' $catQuery $statusQuery
            ");
      $stmt->execute($execParams);
      $panel['allocatedShooters'] = (int) $stmt->fetchColumn();

      $panel['unallocatedShooters'] = max(0, $panel['totalShooters'] - $panel['allocatedShooters']);

      // Allocations list
      $stmt = $pdo->prepare("
                SELECT la.*, er.event_reg_id AS display_reg_id, er.category, er.age_group, 
                       r.first_name, r.last_name, r.club_name, r.district, er.event_reg_id AS enrollment_id,
                       er.event_name
                FROM lane_allocations la
                JOIN event_registrations er ON la.event_reg_id = er.id
                JOIN registrations r ON er.user_id = r.id
                WHERE (er.event_name IN ($inClause) OR er.event_code IN ($inClause)) AND LOWER(r.club_name) != 'vacant' AND LOWER(r.first_name) != 'vacant' $catQuery $statusQuery
                ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.lane_no ASC
            ");
      $stmt->execute($execParams);
      $panel['allocations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Registered distinct physical shooters count per club for this base group
      $stmt = $pdo->prepare("
                SELECT LOWER(r.club_name) as club_name, COUNT(DISTINCT er.user_id) as cnt
                FROM event_registrations er
                JOIN registrations r ON er.user_id = r.id
                WHERE (er.event_name IN ($inClause) OR er.event_code IN ($inClause)) AND LOWER(r.club_name) != 'vacant' AND LOWER(r.first_name) != 'vacant' $catQuery 
                  AND (er.status IS NULL OR er.status = '' OR LOWER(er.status) IN ('approved', 'active', 'pending'))
                GROUP BY LOWER(r.club_name)
                ORDER BY club_name ASC
            ");
      $stmt->execute($execParams);
      $clubCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

      $panel['clubs'] = array_keys($clubCounts);
      $panel['clubRegTotals'] = $clubCounts;

      // Build pivot grid columns and data from allocations
      $cols = [];
      $pivot = [];
      $clubAllocTotals = [];

      foreach ($panel['allocations'] as $alloc) {
        $club = strtolower($alloc['club_name']);
        $date = $alloc['scheduled_date'];
        $relay = $alloc['relay_no'];

        if (!isset($cols[$date]))
          $cols[$date] = [];
        if (!isset($cols[$date][$relay])) {
          $cols[$date][$relay] = [
            'rep' => substr($alloc['reporting_time'], 0, 5),
            'start' => substr($alloc['start_time'], 0, 5),
          ];
        }

        if (!isset($pivot[$club]))
          $pivot[$club] = [];
        if (!isset($pivot[$club][$date]))
          $pivot[$club][$date] = [];
        if (!isset($pivot[$club][$date][$relay]))
          $pivot[$club][$date][$relay] = 0;
        $pivot[$club][$date][$relay]++;

        if (!isset($clubAllocTotals[$club]))
          $clubAllocTotals[$club] = 0;
        $clubAllocTotals[$club]++;
      }

      ksort($cols);
      foreach ($cols as $d => &$relays) {
        ksort($relays);
      }
      unset($relays);

      $panel['cols'] = $cols;
      $panel['pivot'] = $pivot;
      $panel['clubAllocTotals'] = $clubAllocTotals;
    }

    $weaponPanels[$wt] = $panel;
  }

  // Unallocated shooters for selected event (for allocation modal)
  $unallocatedShootersList = [];
  if ($selectedEvent) {
    $selFullName = $EVENTS_MAPPING[$selectedEvent] ?? $selectedEvent;
    // Let's find what key in $baseGroups contains $selectedEvent!
    $selBase = '';
    foreach ($baseGroups as $bgKey => $bgEvents) {
      if (in_array($selectedEvent, $bgEvents, true)) {
        $selBase = $bgKey;
        break;
      }
    }
    if (empty($selBase)) {
      $selBase = getBackendEventBaseType($selFullName);
      if (empty($selBase)) {
        $selBase = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($selectedEvent)) . '_nr';
      }
    }

    $matchingIds = $baseGroups[$selBase] ?? [$selectedEvent];
    $selInClause = implode(',', array_fill(0, count($matchingIds), '?'));

    $selCatFilter = '';
    if (substr($selBase, -5) === '_issf') {
      $selCatFilter = 'ISSF';
    } elseif (substr($selBase, -3) === '_nr') {
      $selCatFilter = 'NR';
    } elseif (substr($selBase, -10) === '_para_deaf') {
      $selCatFilter = 'PARA_DEAF';
    }

    $selCatQuery = "";
    $execParams = array_merge($matchingIds, $matchingIds);
    if (!empty($selCatFilter)) {
      if ($selCatFilter === 'NR') {
        $selCatQuery = " AND (er.category IS NULL OR er.category = '' OR UPPER(er.category) IN ('NR', 'NR_MQS', 'NR-MQS')) ";
      } else {
        $selCatQuery = " AND UPPER(er.category) LIKE ? ";
        $execParams[] = '%' . strtoupper($selCatFilter) . '%';
      }
    }

    $stmt = $pdo->prepare("
            SELECT er.id, er.event_reg_id, r.first_name, r.last_name, r.club_name, r.district, er.event_name,
                   CASE WHEN la.lane_no > 0 THEN la.relay_no ELSE NULL END AS relay_no, la.lane_no, la.scheduled_date
            FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            LEFT JOIN lane_allocations la ON (la.event_reg_id = er.id AND la.lane_no > 0)
            WHERE (er.event_name IN ($selInClause) OR er.event_code IN ($selInClause)) $selCatQuery 
              AND (er.status IS NULL OR er.status = '' OR LOWER(er.status) IN ('approved', 'active', 'pending'))
            ORDER BY (la.relay_no IS NOT NULL AND la.lane_no > 0) ASC, r.first_name ASC, r.last_name ASC
        ");
    $stmt->execute($execParams);
    $unallocatedShootersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  die("Database error: " . $e->getMessage());
}
?>

<!-- Print Media CSS Overrides -->
<style>
  @page {
    size: A4 landscape;
    margin: 10mm;
  }

  @media print {

    body,
    body.admin-body {
      background: #fff !important;
      color: #000 !important;
      height: auto !important;
      max-height: none !important;
      overflow: visible !important;
    }

    .admin-wrapper {
      display: block !important;
      height: auto !important;
      max-height: none !important;
      overflow: visible !important;
    }

    .admin-sidebar,
    .admin-page-header,
    .toolbar,
    .dash-grid,
    .msg-box,
    .btn,
    .print-hide,
    .page-bg,
    .page-bg-noise {
      display: none !important;
    }

    table {
      page-break-inside: auto !important;
    }

    tr {
      page-break-inside: avoid !important;
      page-break-after: auto !important;
    }

    thead {
      display: table-header-group !important;
    }

    .admin-main {
      margin: 0 !important;
      padding: 0 !important;
      width: 100% !important;
      height: auto !important;
      max-height: none !important;
      overflow: visible !important;
    }

    .table-wrap,
    .lane-alloc-panel {
      box-shadow: none !important;
      background: #fff !important;
      border: none !important;
      margin-bottom: 30px !important;
      padding: 0 !important;
      overflow: visible !important;
      page-break-after: always !important;
      break-after: page !important;
    }

    .alloc-panel-heading {
      display: none !important;
    }

    .lane-alloc-panel div[style*="border-bottom"] {
      border-bottom-color: #ddd !important;
    }

    .lane-alloc-panel h2,
    .lane-alloc-panel h3 {
      color: #000 !important;
      text-shadow: none !important;
    }

    .lane-alloc-panel div[style*="background:rgba(39,174,96"] {
      background: transparent !important;
      border: 1px solid #27ae60 !important;
      color: #27ae60 !important;
    }

    table.alloc-summary-table {
      display: table !important;
      border-collapse: collapse !important;
      width: 100% !important;
      table-layout: auto !important;
      color: #000 !important;
      background: #fff !important;
      word-wrap: break-word !important;
      overflow-wrap: break-word !important;
    }

    table.alloc-summary-table th,
    table.alloc-summary-table td {
      display: table-cell !important;
      border: 1px solid #000 !important;
      color: #000 !important;
      background: #fff !important;
      padding: 5px 8px !important;
      font-size: 10pt !important;
      white-space: normal !important;
      word-break: normal !important;
      text-align: center !important;
    }

    table.alloc-summary-table th {
      background: #f0f0f0 !important;
      font-weight: bold !important;
    }

    table.alloc-summary-table th.cell-club,
    table.alloc-summary-table td.cell-club {
      text-align: left !important;
      padding-left: 6px !important;
    }

    .print-title {
      display: block !important;
      text-align: center;
      margin-bottom: 20px;
    }

    .print-title h2 {
      margin: 0;
      font-size: 22px;
      font-weight: 700;
    }

    .print-title h3 {
      margin: 5px 0 0 0;
      font-size: 15px;
      color: #555;
    }

    /* Per-panel printing: hide non-target panels and print-titles */
    .lane-alloc-panel[data-print="hide"] {
      display: none !important;
    }

    .print-title[data-print="hide"] {
      display: none !important;
    }

    .print-only-block,
    .alloc-print-container {
      display: block !important;
    }
  }

  .print-title,
  .print-only-block,
  .alloc-print-container {
    display: none !important;
  }

  .quick-fill-btn {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: var(--text-secondary);
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 11px;
    cursor: pointer;
    margin-right: 6px;
    margin-top: 6px;
    transition: all 0.2s ease;
  }

  .quick-fill-btn:hover {
    background: var(--gold-500);
    color: #fff;
    border-color: var(--gold-500);
  }

  /* ============================================================
   Lane Allocation Summary Table — Premium Theme
   ============================================================ */
  .lane-alloc-panel {
    background: var(--grad-panel);
    border: var(--border-dark);
    border-radius: var(--radius-md);
    padding: 28px;
    margin-bottom: 35px;
    box-shadow: var(--shadow-card);
    overflow-x: auto;
    position: relative;
  }

  .lane-alloc-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold-500) 20%, var(--gold-300) 50%, var(--gold-500) 80%, transparent);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    pointer-events: none;
  }

  .alloc-summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  .alloc-summary-table thead tr.alloc-header-row th {
    padding: 10px 12px;
    border: var(--border-dark);
    font-weight: 700;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-secondary);
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
    vertical-align: middle;
  }

  .alloc-summary-table thead tr.alloc-header-row th.label-cell {
    text-align: left;
    min-width: 170px;
  }

  .alloc-summary-table thead tr.alloc-header-row th.s-no-cell {
    width: 55px;
  }

  .alloc-summary-table thead tr.alloc-header-row th.total-col-header {
    width: 70px;
  }

  .alloc-summary-table thead tr.alloc-header-row th.total-shooters-header {
    width: 85px;
  }

  .alloc-summary-table thead tr.alloc-subheader th {
    padding: 8px 10px;
    border: var(--border-dark);
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
  }

  .alloc-summary-table thead tr.alloc-subheader th.date-header-cell {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    background: rgba(255, 255, 255, 0.15);
    color: var(--gold-400);
  }

  .alloc-summary-table thead tr.alloc-subheader th.relay-header-cell {
    font-size: 12px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-primary);
  }

  .alloc-summary-table thead tr.alloc-subheader th.time-header-cell {
    font-weight: 400;
    font-size: 11px;
    color: var(--text-muted);
    background: rgba(255, 255, 255, 0.08);
  }

  .alloc-summary-table thead tr.alloc-subheader th.match-start-header {
    font-weight: 600;
    font-size: 11px;
    color: var(--text-primary);
    background: rgba(255, 255, 255, 0.08);
  }

  .alloc-summary-table thead tr.alloc-subheader th.limit-header-cell {
    font-weight: 700;
    font-size: 11px;
    color: var(--gold-400);
    background: rgba(255, 255, 255, 0.08);
  }

  .alloc-summary-table tbody td {
    padding: 10px 12px;
    border: var(--border-dark);
    text-align: center;
    vertical-align: middle;
    font-size: 13px;
    transition: background 0.15s ease;
  }

  .alloc-summary-table tbody td.cell-sno {
    font-weight: 600;
    color: var(--text-muted);
  }

  .alloc-summary-table tbody td.cell-club {
    text-align: left;
    font-weight: 600;
    color: var(--text-primary);
  }

  .alloc-summary-table tbody td.cell-total-shooters {
    font-weight: 700;
    color: var(--gold-400);
    font-size: 14px;
  }

  .alloc-summary-table tbody td.cell-alloc-count {
    font-weight: 700;
    font-size: 14px;
  }

  .alloc-summary-table tbody td.cell-alloc-count.has-value {
    color: var(--text-primary);
  }

  .alloc-summary-table tbody td.cell-club-total {
    font-weight: 700;
    color: var(--success);
    font-size: 14px;
  }

  .alloc-summary-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.03);
  }

  .alloc-summary-table tfoot tr.footer-summary {
    background: rgba(255, 255, 255, 0.06);
    border-top: 2px solid rgba(255, 255, 255, 0.2);
  }

  .alloc-summary-table tfoot tr.footer-summary td {
    padding: 12px 14px;
    border: var(--border-dark);
    font-size: 14px;
    font-weight: 700;
    text-align: center;
  }

  .alloc-summary-table tfoot tr.footer-summary td.footer-label {
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gold-400);
  }

  .alloc-summary-table tfoot tr.footer-summary td.footer-total-registered {
    color: var(--gold-400);
    font-size: 15px;
  }

  .alloc-summary-table tfoot tr.footer-summary td.footer-relay-total {
    color: var(--text-primary);
  }

  .alloc-summary-table tfoot tr.footer-summary td.footer-grand-total {
    color: var(--success);
    font-size: 15px;
  }

  /* Empty state table */
  .alloc-summary-table thead tr.alloc-empty-header th {
    padding: 12px 14px;
    border: var(--border-dark);
    font-weight: 700;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-secondary);
    background: rgba(255, 255, 255, 0.03);
    text-align: center;
    vertical-align: middle;
  }

  .alloc-summary-table thead tr.alloc-empty-header th.empty-label-cell {
    text-align: left;
  }

  .alloc-summary-table tbody tr.alloc-empty-row td {
    padding: 10px 14px;
    border: var(--border-dark);
    font-size: 13px;
  }

  .alloc-summary-table tbody tr.alloc-empty-row td.empty-sno {
    text-align: center;
    font-weight: 600;
    color: var(--text-secondary);
  }

  .alloc-summary-table tbody tr.alloc-empty-row td.empty-club {
    text-align: left;
    font-weight: 600;
    color: var(--text-primary);
  }

  .alloc-summary-table tbody tr.alloc-empty-row td.empty-count {
    text-align: center;
    font-weight: 700;
    color: var(--gold-400);
    font-size: 15px;
  }

  .alloc-summary-table tbody tr.alloc-empty-row td.empty-dash {
    text-align: center;
    color: var(--text-muted);
  }

  /* Editable table styles */
  .alloc-summary-table td[contenteditable="true"],
  .alloc-summary-table th[contenteditable="true"] {
    cursor: text;
  }

  .alloc-summary-table td[contenteditable="true"]:hover,
  .alloc-summary-table th[contenteditable="true"]:hover {
    outline: 2px solid rgba(255, 255, 255, 0.3);
    outline-offset: -2px;
  }

  .alloc-summary-table td[contenteditable="true"]:focus,
  .alloc-summary-table th[contenteditable="true"]:focus {
    outline: 2px solid var(--gold-500);
    outline-offset: -2px;
    background: rgba(255, 255, 255, 0.08);
  }

  .alloc-summary-table td.cell-sno,
  .alloc-summary-table td.cell-club-total,
  .alloc-summary-table tfoot td {
    cursor: default;
  }

  .alloc-summary-table td.cell-sno:focus,
  .alloc-summary-table td.cell-club-total:focus,
  .alloc-summary-table tfoot td:focus {
    outline: none;
  }

  .table-toolbar {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    flex-wrap: wrap;
    justify-content: center;
  }

  .tbl-btn {
    font-size: 12px !important;
    padding: 6px 14px !important;
  }

  .tbl-btn.btn-danger {
    border-color: var(--danger) !important;
    color: var(--danger) !important;
  }

  .tbl-btn.btn-danger:hover {
    background: rgba(231, 76, 60, 0.1) !important;
  }

  #editStatus {
    text-align: center;
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 8px;
    font-style: italic;
  }

  <?php if (!canEdit()): ?>
    .alloc-summary-table td,
    .lane-cell,
    .alloc-cell,
    td[onclick],
    div[onclick] {
      pointer-events: none !important;
      cursor: default !important;
    }

  <?php endif; ?>

  /* Gold accent on panel heading */
  .alloc-panel-heading {
    text-align: center;
    position: relative;
    padding-bottom: 18px;
    margin-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
  }

  .alloc-panel-heading .updated-badge {
    position: absolute;
    right: 0;
    top: 0;
    color: var(--danger);
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.8;
  }

  .alloc-panel-heading h2 {
    font-family: 'Cinzel', serif;
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    color: var(--gold-400);
    letter-spacing: 2px;
    text-transform: uppercase;
    line-height: 1.3;
    text-shadow: 0 2px 10px rgba(255, 255, 255, 0.15);
  }

  .alloc-panel-heading h3 {
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 600;
    margin: 8px 0 0;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    line-height: 1.3;
  }

  .alloc-panel-heading .event-badge {
    display: inline-block;
    margin-top: 10px;
    margin-right: 6px;
    padding: 5px 18px;
    border-radius: 20px;
    background: rgba(39, 174, 96, 0.1);
    border: 1px solid rgba(39, 174, 96, 0.2);
    color: var(--success);
    font-weight: 700;
    font-size: 15px;
    letter-spacing: 0.3px;
  }

  select option {
    background-color: #1a1a24;
    color: #ffffff;
  }

  /* Master Start Sheet Overlay Print CSS Overrides */
  /* Only activates when body has .printing-modal class (set by JS when printing */
  /* the master start sheet overlay). printPanel() does NOT set this class, so   */
  /* the allocation summary print path is unaffected.                             */
  @media print {
    body.printing-modal>*:not(#masterStartSheetModal) {
      display: none !important;
    }

    body.printing-modal #masterStartSheetModal {
      position: absolute !important;
      left: 0 !important;
      top: 0 !important;
      width: 100% !important;
      height: auto !important;
      background: #fff !important;
      color: #000 !important;
      display: block !important;
      z-index: auto !important;
    }

    body.printing-modal #masterStartSheetModal .modal {
      max-width: 100% !important;
      width: 100% !important;
      height: auto !important;
      box-shadow: none !important;
      border: none !important;
      background: #fff !important;
      color: #000 !important;
      margin: 0 !important;
      padding: 0 !important;
    }

    body.printing-modal #masterStartSheetModal .modal-header,
    body.printing-modal #masterStartSheetModal .modal-close {
      display: none !important;
    }

    body.printing-modal #masterStartSheetModal .modal-body {
      background: #fff !important;
      color: #000 !important;
      padding: 0 !important;
      overflow: visible !important;
    }

    body.printing-modal .day-section {
      page-break-after: always;
    }

    body.printing-modal .day-section:last-child {
      page-break-after: avoid;
    }

    body.printing-modal .day-title {
      color: #000 !important;
      font-size: 18px !important;
      font-weight: bold !important;
      border-bottom: 2px solid #000 !important;
      margin-bottom: 15px !important;
      text-align: center !important;
    }

    body.printing-modal .relay-title {
      color: #000 !important;
      font-size: 14px !important;
      font-weight: bold !important;
      margin-bottom: 10px !important;
    }

    body.printing-modal .weapon-title {
      color: #000 !important;
      font-size: 12px !important;
      font-weight: bold !important;
      margin-bottom: 8px !important;
    }

    body.printing-modal table.start-sheet-tbl {
      width: 100% !important;
      border-collapse: collapse !important;
    }

    body.printing-modal table.start-sheet-tbl th,
    body.printing-modal table.start-sheet-tbl td {
      border: 1px solid #000 !important;
      color: #000 !important;
      padding: 6px 8px !important;
      font-size: 11px !important;
    }

    body.printing-modal table.start-sheet-tbl th {
      background: #f0f0f0 !important;
    }
  }

  .day-title {
    font-family: 'Cinzel', serif;
    font-size: 22px;
    color: var(--gold-400);
    border-bottom: 2px solid rgba(255, 255, 255, 0.3);
    padding-bottom: 8px;
    margin-bottom: 20px;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 2px;
  }

  .relay-title {
    font-family: 'Inter', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  .weapon-title {
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: #27ae60;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  table.start-sheet-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 15px;
    color: #fff;
    font-size: 13px;
  }

  table.start-sheet-tbl th {
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.02);
    color: var(--text-secondary);
    font-weight: 700;
    font-size: 11px;
    padding: 10px;
  }

  table.start-sheet-tbl td {
    border: 1px solid rgba(255, 255, 255, 0.05);
    padding: 10px;
  }

  table.start-sheet-tbl td.editable-cell {
    cursor: text;
    background: rgba(255, 255, 255, 0.01);
  }

  table.start-sheet-tbl td.editable-cell:hover {
    outline: 1px dashed var(--gold-500);
  }

  table.start-sheet-tbl td.editable-cell:focus {
    background: rgba(255, 255, 255, 0.08);
    outline: 2px solid var(--gold-500);
  }
</style>

<!-- Admin Page Header -->
<div class="admin-page-header print-hide"
  style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
  <div class="admin-page-title">Lane Allocations</div>
  <?php if (isSuperAdmin()): ?>
    <div style="display:flex; gap:12px; align-items:center;">
      <button type="button" class="btn" onclick="saveAllActivePanels()"
        style="background:#27ae60; color:#fff; font-weight:700; font-size:14px; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:6px;">
        💾 Save Layout & Allocations
      </button>
      <button type="button" class="btn" onclick="openModal('createWeaponModal')"
        style="background:transparent; border: 1px solid var(--gold-500); color:var(--gold-500); font-weight:600; font-size:14px; padding:10px 20px;">
        ➕ Create New Weapon Table
      </button>
      <button type="button" class="btn" onclick="openMasterStartSheet()"
        style="background:var(--gold-500); color:#fff; font-weight:700; font-size:14px; padding:10px 20px;">
        📋 Generate Master Start List
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- EVENT SELECTOR CARD -->
<div class="meta-card print-hide"
  style="margin-bottom: 24px; padding: 20px; background: var(--grad-panel); border: var(--border-dark); border-radius: var(--radius-md); box-shadow: var(--shadow-card);">
  <form method="GET" id="event-select-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    <div class="field" style="flex: 1; min-width: 250px;">
      <label for="event_select"
        style="font-weight: 600; font-size: 14px; color: var(--gold-500); margin-bottom: 6px; display: block;">Select
        Shooting Event</label>
      <select id="event_select" name="event_select" class="searchable-select" onchange="this.form.submit()"
        style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 14px; outline: none;">
        <option value="">— All Events —</option>
        <?php foreach ($dropdownOptions as $opt):
          $selected = ($selBaseType === $opt['value']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($opt['value']) ?>" <?= $selected ?>>
            <?= htmlspecialchars($opt['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($selBaseType !== '' && canEdit()): ?>
        <div style="margin-top: 15px;">
          <button type="button" class="btn btn-primary" onclick="openRelayWizardModal()"
            style="width: auto; background: var(--gold-500); color: #fff; font-weight: 700; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
            ⚙️ Configure Event Schedule & Relays
          </button>
        </div>
      <?php endif; ?>
    </div>
  </form>
</div>
<div class="msg-box print-hide" id="msgBox" role="alert" aria-live="polite"></div>

<script src="js/searchable_select.js"></script>
<script>
  // ─── DB club data for auto-populating editable tables ────────
  var activeWeaponKeys = [];
  var baseEventsList = <?= json_encode(array_values(array_map(function ($k, $v) use ($weaponKeys) {
    return ['key' => $weaponKeys[$k], 'label' => $v];
  }, array_keys($weaponLabels), $weaponLabels))) ?>;
  // Map of JS key → actual DB event IDs (e.g. 'IS-54', 'R004') for that weapon type
  var baseGroupsMap = <?= json_encode(array_combine(
    array_values(array_map(fn($k) => $weaponKeys[$k], array_keys($baseGroups))),
    array_values($baseGroups)
  )) ?>;
  var keyToWt = <?= json_encode(array_combine(
    array_values(array_map(fn($k) => $weaponKeys[$k], array_keys($baseGroups))),
    array_keys($baseGroups)
  )) ?>;

  // hasDbAllocs flags will be initialized inline during the PHP loop below

  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  var baseGroupsMap = {};
  <?php foreach (array_keys($baseGroups) as $wt):
    $key = $weaponKeys[$wt];

    // Build initial state allocs ONLY from DB relay_club_quotas so start sheet edits never alter lane allocations
    $dbAllocs = [];

    $panelLabel = $weaponLabels[$wt];
    $baseKeyCandidates = array_values(array_unique(array_filter([
      $panelLabel,
      $wt,
      getBackendEventBaseType($panelLabel),
      formatBaseEventName($wt),
      preg_replace('/_(issf|nr|para_deaf)$/i', '', $wt),
      ...($baseGroups[$wt] ?? [])
    ])));

    // Also load saved quotas from relay_club_quotas table checking all baseKeyCandidates
    $inQuotaPlaceholders = implode(',', array_fill(0, count($baseKeyCandidates), 'LOWER(?)'));
    $stmtQuota = $pdo->prepare("SELECT club_name, scheduled_date, relay_no, quota FROM relay_club_quotas WHERE (championship_id = ? OR championship_id IS NULL OR championship_id = 1) AND LOWER(event_base_type) IN ($inQuotaPlaceholders)");
    $stmtQuota->execute(array_merge([$cid], array_map('strtolower', $baseKeyCandidates)));
    $dbQuotas = $stmtQuota->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dbQuotas)) {
      foreach ($dbQuotas as $q) {
        $club = strtolower($q['club_name']);
        $date = date('d-m-Y', strtotime($q['scheduled_date']));
        $relay = (int)$q['relay_no'];
        $quotaVal = (int)$q['quota'];

        if (!isset($dbAllocs[$club])) $dbAllocs[$club] = [];
        if (!isset($dbAllocs[$club][$date])) $dbAllocs[$club][$date] = [];
        $dbAllocs[$club][$date][$relay] = $quotaVal;

        if (!in_array($q['club_name'], $weaponPanels[$wt]['clubs'], true)) {
          $weaponPanels[$wt]['clubs'][] = $q['club_name'];
        }
      }
    }

    // Build initial state dates from relay_schedules if exists, checking all event_name candidate variations
    $inPlaceholders = implode(',', array_fill(0, count($baseKeyCandidates), 'LOWER(?)'));
    $stmtSched = $pdo->prepare("SELECT * FROM relay_schedules WHERE LOWER(event_name) IN ($inPlaceholders) AND schedule_name = '' ORDER BY scheduled_date ASC, relay_no ASC");
    $stmtSched->execute(array_map('strtolower', $baseKeyCandidates));
    $dbSchedRows = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

    $dbDates = [];
    if (!empty($dbSchedRows)) {
      $schedGrouped = [];
      $seenRelaysPerDate = [];
      foreach ($dbSchedRows as $row) {
        $dateFormatted = date('d-m-Y', strtotime($row['scheduled_date']));
        $rNo = (int) $row['relay_no'];
        if (!isset($seenRelaysPerDate[$dateFormatted])) {
          $seenRelaysPerDate[$dateFormatted] = [];
        }
        if (in_array($rNo, $seenRelaysPerDate[$dateFormatted], true)) {
          continue; // Skip duplicate relay number for the same date
        }
        $seenRelaysPerDate[$dateFormatted][] = $rNo;

        $schedGrouped[$dateFormatted][] = [
          'r' => $rNo,
          'rep' => substr($row['reporting_time'], 0, 5),
          'st' => substr($row['start_time'], 0, 5),
          'limit' => $row['relay_limit'] !== null ? (int) $row['relay_limit'] : null
        ];
      }
      foreach ($schedGrouped as $d => $relays) {
        $dbDates[] = [
          'date' => $d,
          'relays' => $relays
        ];
      }
    } else {
      foreach ($weaponPanels[$wt]['cols'] as $d => $relays) {
        $formattedDate = date('d-m-Y', strtotime($d));
        $rel = [];
        foreach ($relays as $r => $times) {
          $rel[] = [
            'r' => (int) $r,
            'rep' => $times['rep'],
            'st' => $times['start'],
            'limit' => null
          ];
        }
        $dbDates[] = [
          'date' => $formattedDate,
          'relays' => $rel
        ];
      }
    }
    ?>
    activeWeaponKeys.push(<?= json_encode($key) ?>);
    baseGroupsMap[<?= json_encode($key) ?>] = <?= json_encode($baseGroups[$wt]) ?>;
    window['dbEvents_<?= $key ?>'] = <?= json_encode($weaponPanels[$wt]['clubs'] ?? []) ?>;
    window['dbRegCounts_<?= $key ?>'] = <?= json_encode($weaponPanels[$wt]['clubRegTotals'] ?? []) ?>;
    window['dbAllocs_<?= $key ?>'] = <?= json_encode($dbAllocs) ?>;
    window['dbDates_<?= $key ?>'] = <?= json_encode($dbDates) ?>;
    window['dbCapacity_<?= $key ?>'] = <?= getRangeCapacity($wt) ?>;
    window['hasDbAllocs_<?= $key ?>'] = <?= empty($dbAllocs) ? 'false' : 'true' ?>;
  <?php endforeach; ?>
  // Handle event selector dropdown change
  const eventSelect = document.getElementById('event_select');
  if (eventSelect) {
    eventSelect.addEventListener('change', () => {
      const val = eventSelect.value;
      if (!val) {
        window.location.href = 'lane_allocations.php';
      } else {
        window.location.href = `lane_allocations.php?base_type=${encodeURIComponent(val)}`;
      }
    });
    initSearchableSelect(eventSelect, { placeholder: '🔍 Search or choose event…', allLabel: '— All Events —' });
  }

  window.openMasterStartSheet = function() {
    const sel = document.getElementById('event_select');
    const val = sel ? sel.value : '';
    if (val) {
      window.location.href = 'start_sheet.php?base_type=' + encodeURIComponent(val);
    } else {
      window.location.href = 'start_sheet.php';
    }
  };


</script>
<?php if (empty($baseGroups)): ?>
  <div class="print-hide"
    style="background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.1); border-radius:8px; padding:40px; text-align:center; color:var(--text-muted); margin-bottom: 35px;">
    <h3>No Allocation Sheet Found</h3>
    <p style="margin-top: 10px;">No active lane allocation sheets were found.</p>
  </div>
<?php else: ?>
  <?php foreach (array_keys($baseGroups) as $wt):
    $key = $weaponKeys[$wt];
    $fullEventName = $weaponLabels[$wt];
    ?>
    <!-- ============================================================
         <?= htmlspecialchars($fullEventName) ?> TABLE
         ============================================================ -->
    <div class="print-title" data-for-panel="panel-<?= $key ?>">
      <h2><?= htmlspecialchars($fullEventName) ?></h2>
      <h3>Lane Allocation Summary</h3>
    </div>
    <div class="lane-alloc-panel" id="panel-<?= $key ?>">
      <div class="alloc-panel-heading">
        <h2><?= htmlspecialchars($fullEventName) ?></h2>
        <h3 style="margin-top: 8px;"><?= htmlspecialchars(EVENT_TITLE) ?></h3>
      </div>
      <div class="capacity-warning-box print-hide" id="warning-<?= $key ?>"
        style="display:none; margin: 12px 0; padding: 16px 20px; border-radius: 6px; background: rgba(255, 107, 107, 0.1); border: 1px solid #ff6b6b; color: #ff6b6b; font-weight: 500; font-size: 14px; line-height: 1.5; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
      </div>
      <table class="alloc-summary-table" id="table-<?= $key ?>" data-key="<?= $key ?>"></table>
      <div class="table-toolbar print-hide" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <?php if (canEdit()): ?>
          <button type="button" class="btn tbl-btn" onclick="savePanelState('<?= $key ?>')"
            style="background: #27ae60; color: #fff; border-color: #27ae60; font-weight: 700;">💾 Save Layout & Allocations</button>
          <button type="button" class="btn tbl-btn" onclick="addTableRow('<?= $key ?>')">+ Add Row</button>
          <button type="button" class="btn tbl-btn" onclick="openDeleteRowModal('<?= $key ?>')">− Delete Row</button>
          <button type="button" class="btn tbl-btn" onclick="resetLayout('<?= $key ?>')"
            style="color: #ff6b6b; border-color: rgba(255, 107, 107, 0.3);">↻ Reset Layout</button>
          <button type="button" class="btn tbl-btn" onclick="openAddDateModal('<?= $key ?>')">+ Add Date</button>
          <button type="button" class="btn tbl-btn" onclick="openDeleteDateModal('<?= $key ?>')">− Delete Date</button>
          <button type="button" class="btn tbl-btn" onclick="openDeleteColumnModal('<?= $key ?>')">− Delete Column</button>
          <button type="button" class="btn tbl-btn" onclick="setRelayCount('<?= $key ?>')">Set Relays</button>
          <button type="button" class="btn tbl-btn"
            onclick="triggerAutoAllocate('<?= $key ?>', '<?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>')">Allocate</button>
        <?php endif; ?>
        <button type="button" class="btn tbl-btn" onclick="printPanel('panel-<?= $key ?>')"
          style="background: rgba(52, 152, 219, 0.2); color: #3498db; border-color: rgba(52, 152, 219, 0.4); font-weight: 700;">
          🖨️ Print Summary
        </button>
        <a href="start_sheet.php?base_type=<?= urlencode($wt) ?>" class="btn tbl-btn"
          style="background: rgba(39, 174, 96, 0.15); color: #2ecc71; border-color: rgba(39, 174, 96, 0.3);">
          📋 Start List: <?= htmlspecialchars($fullEventName) ?>
        </a>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- Relay Wizard Modal -->
<div class="modal-overlay" id="relayWizardModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h3>Configure Event Schedule & Relays</h3>
      <button class="modal-close" onclick="closeModal('relayWizardModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <form id="relayWizardForm" onsubmit="applyRelayWizard(event)">
      <div class="modal-body">
        <!-- Event Name (Readonly) -->
        <div class="form-group">
          <label>Shooting Event</label>
          <input type="text" id="wizard_event_name" class="form-input" readonly
            style="background:rgba(255,255,255,0.03); color:var(--text-muted);">
          <input type="hidden" id="wizard_event_key">
        </div>
        <!-- From Date & To Date -->
        <div style="display:flex; gap:12px;">
          <div class="form-group" style="flex:1;">
            <label>From Date <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="date" id="wizard_from_date" required>
            </div>
          </div>
          <div class="form-group" style="flex:1;">
            <label>To Date <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="date" id="wizard_to_date" required>
            </div>
          </div>
        </div>
        <div style="display:flex; gap:12px;">
          <!-- First Relay Start Time -->
          <div class="form-group" style="flex:1;">
            <label>Start Time <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="time" id="wizard_start_time" value="09:00" required onchange="recalcWizardRelays()">
            </div>
          </div>
          <!-- End Time -->
          <div class="form-group" style="flex:1;">
            <label>End Time <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="time" id="wizard_end_time" value="17:00" required onchange="recalcWizardRelays()">
            </div>
          </div>
        </div>

        <div style="display:flex; gap:12px;">
          <!-- Relay Time (duration) -->
          <div class="form-group" style="flex:1;">
            <label>Relay Time (mins) <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" id="wizard_relay_time" min="1" value="90" required oninput="recalcWizardRelays()">
            </div>
          </div>
          <!-- Break Time -->
          <div class="form-group" style="flex:1;">
            <label>Break Time (mins) <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" id="wizard_break" min="0" value="15" required oninput="recalcWizardRelays()">
            </div>
          </div>
        </div>

        <!-- Relay per day -->
        <div class="form-group">
          <label>Relays Per Day <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="number" id="wizard_relay_per_day" min="1" value="2" required>
          </div>
        </div>
        <div style="display:flex; gap:12px;">
          <!-- Limit per relay -->
          <div class="form-group" style="flex:1;">
            <label>Limit Per Relay <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" id="wizard_limit_per_relay" min="1" required>
            </div>
          </div>
          <!-- Reporting Lead Time (mins) -->
          <div class="form-group" style="flex:1;">
            <label>Reporting Lead Time (mins) <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" id="wizard_reporting_offset" min="0" max="180" value="25" placeholder="e.g. 25"
                required>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('relayWizardModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="width:auto;">Generate Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- Create Weapon Table Modal -->
<div class="modal-overlay" id="createWeaponModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Create New Weapon Table</h3>
      <button class="modal-close" onclick="closeModal('createWeaponModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <form id="createWeaponForm">
      <div class="modal-body">
        <div class="form-group">
          <label>Event Title <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" name="weapon_type" id="cw_event_name" placeholder="e.g. 50m Rifle 3 Positions" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createWeaponModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnCreateWeapon" style="width:auto;">Create Table</button>
      </div>
    </form>
  </div>
</div>

<!-- Master Start Sheet Overlay -->
<div class="modal-overlay" id="masterStartSheetModal" style="z-index: 99999;">
  <div class="modal" style="max-width: 95%; width: 1200px; height: 90vh; display: flex; flex-direction: column;">
    <div class="modal-header" style="flex-shrink: 0; display:flex; justify-content:space-between; align-items:center;">
      <h3>Master Start List</h3>
      <div style="display: flex; gap: 12px; align-items: center;">
        <button class="modal-close" onclick="closeModal('masterStartSheetModal')"
          style="position:static; padding:0; background:transparent;"><svg xmlns="http://www.w3.org/2000/svg" width="24"
            height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg></button>
      </div>
    </div>
    <div class="modal-body" id="masterStartSheetContent"
      style="flex-grow: 1; overflow-y: auto; padding: 20px; background: #15151f; color: #fff;">
      <div style="text-align: center; padding: 40px; color: var(--text-muted);">
        <p>Loading master start list...</p>
      </div>
    </div>
  </div>
</div>

<!-- Lane Allocation Modal -->
<div class="modal-overlay" id="allocateModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h3 id="modalTitle">Assign Lane Allocation</h3>
      <button class="modal-close" onclick="closeModal('allocateModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <form id="allocateForm">
      <div class="modal-body">

        <!-- Shooter Selection -->
        <div class="form-group" id="shooter_select_group">
          <label>Select Shooter <span class="req">*</span></label>
          <div class="input-wrap">
            <select name="event_reg_id" id="alloc_event_reg_id" required style="text-transform: uppercase;">
              <option value="-1">— Manual Entry (Write-in competitor) —</option>
              <?php foreach ($unallocatedShootersList as $s): 
                $isAllocated = !empty($s['relay_no']);
                $allocInfoStr = '';
                if ($isAllocated) {
                  $laneStr = ((int)$s['lane_no'] > 0) ? ", FP {$s['lane_no']}" : "";
                  $allocInfoStr = " (Allocated in Rly {$s['relay_no']}{$laneStr})";
                }
                $shooterNameUpper = strtoupper($s['first_name'] . ' ' . $s['last_name']);
              ?>
                <option value="<?= $s['id'] ?>" data-allocated="<?= $isAllocated ? '1' : '0' ?>" data-relay-info="Rly <?= $s['relay_no'] ?><?= $laneStr ?>">
                  <?= htmlspecialchars($shooterNameUpper . $allocInfoStr . ($s['club_name'] ? ' — ' . $s['club_name'] : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div id="alloc_already_warning" style="display:none; margin-top:8px; color:#ff9f43; font-size:12px; font-weight:700; background:rgba(255, 159, 67, 0.12); border:1px solid rgba(255, 159, 67, 0.35); padding:8px 12px; border-radius:6px;">
              <i class="bi bi-exclamation-triangle-fill" style="margin-right:4px;"></i> Warning: Shooter is already allocated in <span id="alloc_already_relay_txt"></span> for this event!
            </div>
          </div>
        </div>

        <!-- Custom Manual Shooter inputs -->
        <div class="form-group" id="manual_shooter_group" style="display:none;">
          <label>Shooter Full Name <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" name="manual_shooter_name" id="alloc_manual_shooter_name" placeholder="e.g. John Doe"
              style="text-transform: uppercase;">
          </div>
        </div>
        <div class="form-group" id="manual_club_group" style="display:none;">
          <label>Club Name <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="text" name="manual_club_name" id="alloc_manual_club_name" placeholder="e.g. ACE Club"
              style="text-transform: uppercase;">
          </div>
        </div>
        <input type="hidden" name="active_event_name" id="alloc_active_event_name">
        <input type="hidden" name="alloc_id" id="alloc_id_hidden">

        <!-- Readonly shooter display for Edit mode -->
        <div class="form-group" id="shooter_read_group" style="display:none;">
          <label>Shooter</label>
          <input type="text" id="alloc_shooter_name_read" class="form-input" readonly
            style="background:rgba(255,255,255,0.03); color:var(--text-muted); text-transform: uppercase;">
          <input type="hidden" name="event_reg_id" id="alloc_event_reg_id_hidden">
        </div>

        <!-- Bib No -->
        <div class="form-group">
          <label>Bib Number</label>
          <div class="input-wrap">
            <input type="text" name="bib_no" id="alloc_bib_no" placeholder="e.g. 1121" <?= !isSuperAdmin() ? 'disabled style="opacity: 0.6; cursor: not-allowed;"' : '' ?>>
          </div>
        </div>

        <div style="display:flex; gap:12px;">
          <!-- Date -->
          <div class="form-group" style="flex:1;">
            <label>Scheduled Date <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="date" name="scheduled_date" id="alloc_scheduled_date" required>
            </div>
          </div>
          <!-- Relay No -->
          <div class="form-group" style="flex:1;">
            <label>Relay Number <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" name="relay_no" id="alloc_relay_no" min="1" placeholder="e.g. 1" required>
            </div>
          </div>
        </div>

        <div style="display:flex; gap:12px;">
          <!-- Lane No -->
          <div class="form-group" style="flex:1;">
            <label>Lane (FP) <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="number" name="lane_no" id="alloc_lane_no" min="1" placeholder="e.g. 5" required>
            </div>
          </div>
          <!-- Start Time -->
          <div class="form-group" style="flex:1;">
            <label>Start Time <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="time" name="start_time" id="alloc_start_time" required>
            </div>
          </div>
        </div>

        <!-- Reporting Time -->
        <div class="form-group">
          <label>Reporting Time <span class="req">*</span> <small
              style="color:var(--text-muted); font-size:10px;">(Auto-calculates 25m prior to start)</small></label>
          <div class="input-wrap">
            <input type="time" name="reporting_time" id="alloc_reporting_time" required>
          </div>
          <!-- Quick Shifts helpers -->
          <div style="margin-top: 4px;">
            <span style="font-size:10px; color:var(--text-muted); display:block; margin-bottom:2px;">Quick Shift
              Presets:</span>
            <button type="button" class="quick-fill-btn" onclick="setShift('08:35', '09:00')">🌅 Shift 1
              (09:00)</button>
            <button type="button" class="quick-fill-btn" onclick="setShift('11:05', '11:30')">☀️ Shift 2
              (11:30)</button>
            <button type="button" class="quick-fill-btn" onclick="setShift('13:35', '14:00')">🌤️ Shift 3
              (14:00)</button>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('allocateModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSaveAlloc" style="width:auto;">Save Allocation</button>
      </div>
    </form>
  </div>
</div>


<!-- Set Relay Modal -->
<div class="modal-overlay" id="setRelayModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Set Relays</h3>
      <button class="modal-close" onclick="closeModal('setRelayModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="sr_key">
      <div class="form-group">
        <label>Apply to: <span class="req">*</span></label>
        <select id="sr_apply_type" class="form-input" onchange="toggleSrDate()"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
          <option value="all">All Days</option>
          <option value="single">Particular Date</option>
        </select>
      </div>
      <div class="form-group" id="sr_date_group" style="display:none; margin-top:10px;">
        <label>Select Date: <span class="req">*</span></label>
        <select id="sr_date" class="form-input"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
        </select>
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label>How many relays per day? <span class="req">*</span></label>
        <input type="number" id="sr_count" class="form-input" min="1" value="2"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
      </div>
    </div>
    <div class="modal-footer" style="margin-top:20px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('setRelayModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applySetRelay()">Apply</button>
    </div>
  </div>
</div>

<!-- Delete Row Modal -->
<div class="modal-overlay" id="deleteRowModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Delete Row</h3>
      <button class="modal-close" onclick="closeModal('deleteRowModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="del_row_key">
      <div class="form-group">
        <label>Select Club to Delete: <span class="req">*</span></label>
        <select id="del_row_club" class="form-input"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
        </select>
      </div>
    </div>
    <div class="modal-footer" style="margin-top:20px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('deleteRowModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyDeleteRow()">Delete</button>
    </div>
  </div>
</div>

<!-- Delete Date Modal -->
<div class="modal-overlay" id="deleteDateModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Delete Date</h3>
      <button class="modal-close" onclick="closeModal('deleteDateModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="del_date_key">
      <div class="form-group">
        <label>Select Date to Delete: <span class="req">*</span></label>
        <select id="del_date_val" class="form-input"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
        </select>
      </div>
    </div>
    <div class="modal-footer" style="margin-top:20px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('deleteDateModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyDeleteDate()">Delete</button>
    </div>
  </div>
</div>

<!-- Delete Column Modal -->
<div class="modal-overlay" id="deleteColumnModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Delete Column</h3>
      <button class="modal-close" onclick="closeModal('deleteColumnModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="del_col_key">
      <div class="form-group">
        <label>Select Date: <span class="req">*</span></label>
        <select id="del_col_date" class="form-input" onchange="populateRelaysForDeleteColumn()"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
        </select>
      </div>
      <div class="form-group" style="margin-top:10px;">
        <label>Select Relay Column: <span class="req">*</span></label>
        <select id="del_col_relay" class="form-input"
          style="width:100%; padding:8px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:4px; color:var(--text-primary);">
        </select>
      </div>
    </div>
    <div class="modal-footer" style="margin-top:20px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('deleteColumnModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyDeleteColumn()">Delete</button>
    </div>
  </div>
</div>

<!-- Add Date Modal -->
<div class="modal-overlay" id="addDateModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h3>Add Date</h3>
      <button class="modal-close" onclick="closeModal('addDateModal')"><svg xmlns="http://www.w3.org/2000/svg"
          width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="add_date_key">

      <div class="form-group">
        <label>Date <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="date" id="add_date_val" required>
        </div>
      </div>

      <div style="display:flex; gap:12px;">
        <div class="form-group" style="flex:1;">
          <label>Number of Relays <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="number" id="add_date_relays" min="1" value="2" required>
          </div>
        </div>
        <div class="form-group" style="flex:1;">
          <label>First Start Time <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="time" id="add_date_start" value="09:00" required>
          </div>
        </div>
      </div>

      <div style="display:flex; gap:12px;">
        <div class="form-group" style="flex:1;">
          <label>Interval <span class="req">*</span></label>
          <div class="input-wrap">
            <select id="add_date_interval">
              <option value="60">1:00 h</option>
              <option value="90">1:30 h</option>
              <option value="120">2:00 h</option>
              <option value="150" selected>2:30 h</option>
              <option value="180">3:00 h</option>
              <option value="210">3:30 h</option>
              <option value="240">4:00 h</option>
            </select>
          </div>
        </div>
        <div class="form-group" style="flex:1;">
          <label>Reporting Lead Time (mins) <span class="req">*</span></label>
          <div class="input-wrap">
            <input type="number" id="add_date_reporting_offset" min="0" max="180" value="25" placeholder="e.g. 25"
              required>
          </div>
        </div>
      </div>

    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('addDateModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyAddDate()">Add Date</button>
    </div>
  </div>
</div>

<script>
  // Auto-calculate reporting time (configurable lead time prior to start time)
  document.getElementById('alloc_start_time').addEventListener('input', function () {
    const val = this.value;
    if (val && val.includes(':')) {
      const activeEvtName = document.getElementById('alloc_active_event_name').value || '';
      const key = activeEvtName.replace(/[^a-zA-Z0-9]/g, '');
      const data = (typeof loadData === 'function' && key) ? loadData(key) : {};
      const offset = (data && typeof data.reportingOffset === 'number' && data.reportingOffset >= 0) ? data.reportingOffset : 25;
      const parts = val.split(':');
      let hrs = parseInt(parts[0]);
      let mins = parseInt(parts[1]);
      mins -= offset;
      if (mins < 0) {
        mins += 60;
        hrs -= 1;
        if (hrs < 0) hrs += 24;
      }
      const repVal = String(hrs).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
      document.getElementById('alloc_reporting_time').value = repVal;
    }
  });

  function setShift(rep, start) {
    document.getElementById('alloc_reporting_time').value = rep;
    document.getElementById('alloc_start_time').value = start;
  }

  function openAllocateModal() {
    document.getElementById('modalTitle').textContent = "Assign Lane Allocation";
    document.getElementById('allocateForm').reset();

    // Set default scheduled date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('alloc_scheduled_date').value = today;

    document.getElementById('shooter_select_group').style.display = 'block';
    document.getElementById('alloc_event_reg_id').required = true;
    document.getElementById('shooter_read_group').style.display = 'none';
    document.getElementById('alloc_event_reg_id_hidden').value = '';
    document.getElementById('alloc_id_hidden').value = '';

    // Reset manual entry display states & warning box
    document.getElementById('manual_shooter_group').style.display = 'none';
    document.getElementById('manual_club_group').style.display = 'none';
    document.getElementById('alloc_manual_shooter_name').required = false;
    document.getElementById('alloc_manual_club_name').required = false;
    if (document.getElementById('alloc_already_warning')) {
      document.getElementById('alloc_already_warning').style.display = 'none';
    }

    // Set active event name
    const selBaseType = '<?= htmlspecialchars($selBaseType) ?>';
    const key = selBaseType.replace(/[^a-zA-Z0-9]/g, '');
    const eventObj = baseEventsList.find(item => item.key === key);
    const label = eventObj ? eventObj.label : selBaseType;
    document.getElementById('alloc_active_event_name').value = label;

    openModal('allocateModal');
  }

  // Bind manual selection toggle
  document.addEventListener('DOMContentLoaded', function () {
    var selectEl = document.getElementById('alloc_event_reg_id');
    if (selectEl) {
      selectEl.addEventListener('change', function () {
        const isManual = this.value === '-1';
        document.getElementById('manual_shooter_group').style.display = isManual ? 'block' : 'none';
        document.getElementById('manual_club_group').style.display = isManual ? 'block' : 'none';
        document.getElementById('alloc_manual_shooter_name').required = isManual;
        document.getElementById('alloc_manual_club_name').required = isManual;

        const selOpt = this.options[this.selectedIndex];
        const isAllocated = selOpt && selOpt.getAttribute('data-allocated') === '1';
        const warnBox = document.getElementById('alloc_already_warning');
        if (isAllocated && warnBox) {
          document.getElementById('alloc_already_relay_txt').textContent = selOpt.getAttribute('data-relay-info') || 'another relay';
          warnBox.style.display = 'block';
        } else if (warnBox) {
          warnBox.style.display = 'none';
        }
      });
    }
  });

  function editAllocation(data) {
    document.getElementById('modalTitle').textContent = "Edit Lane Allocation";
    document.getElementById('allocateForm').reset();

    document.getElementById('shooter_select_group').style.display = 'none';
    document.getElementById('alloc_event_reg_id').required = false;

    document.getElementById('shooter_read_group').style.display = 'block';
    document.getElementById('alloc_shooter_name_read').value = data.shooter_name;
    document.getElementById('alloc_event_reg_id_hidden').value = data.event_reg_id;
    document.getElementById('alloc_id_hidden').value = data.id || '';

    document.getElementById('alloc_bib_no').value = data.bib_no || '';
    document.getElementById('alloc_scheduled_date').value = data.scheduled_date;
    document.getElementById('alloc_relay_no').value = data.relay_no;
    document.getElementById('alloc_lane_no').value = data.lane_no;
    document.getElementById('alloc_reporting_time').value = data.reporting_time;
    document.getElementById('alloc_start_time').value = data.start_time;

    openModal('allocateModal');
  }

  async function silentRefreshPage() {
    try {
      const currentUrl = window.location.href;
      const response = await fetch(currentUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) return;
      const htmlText = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(htmlText, 'text/html');

      // Update shooter select dropdown options in allocation modal silently
      const newSelect = doc.getElementById('alloc_event_reg_id');
      const oldSelect = document.getElementById('alloc_event_reg_id');
      if (newSelect && oldSelect) {
        oldSelect.innerHTML = newSelect.innerHTML;
      }

      // Extract script snippets from response to update global JS state variables
      const scripts = doc.querySelectorAll('script');
      scripts.forEach(script => {
        const text = script.textContent || '';
        if (text.includes('dbAllocs_') || text.includes('dbEvents_')) {
          const lines = text.split('\n');
          lines.forEach(line => {
            const trimLine = line.trim();
            if (trimLine.startsWith('window[\'db') || trimLine.startsWith('var db') || trimLine.startsWith('window[\'hasDbAllocs_')) {
              try { 
                const globalCode = trimLine.replace(/^var\s+/, 'window.');
                window.eval(globalCode); 
              } catch (e) {}
            }
          });
        }
      });

      // Re-render summary tables silently
      if (typeof activeWeaponKeys !== 'undefined' && Array.isArray(activeWeaponKeys)) {
        activeWeaponKeys.forEach(k => {
          if (typeof renderEditableTable === 'function') {
            renderEditableTable(k);
          }
        });
      }
    } catch (err) {
      console.error('Silent refresh error:', err);
    }
  }

  document.getElementById('allocateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSaveAlloc');
    btn.disabled = true;

    const fd = new FormData(form);
    try {
      const resp = await fetch('actions/save_lane_allocation.php', { method: 'POST', body: fd });
      const data = await resp.json();
      const box = document.getElementById('msgBox');
      if (data.success) {
        showMsg(box, 'success', data.message);
        closeModal('allocateModal');
        await silentRefreshPage();
      } else {
        showMsg(box, 'error', data.message);
      }
    } catch {
      alert('Network error.');
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('createWeaponForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnCreateWeapon');
    btn.disabled = true;

    const fd = new FormData(form);
    try {
      const resp = await fetch('actions/create_weapon_type.php', { method: 'POST', body: fd });
      const data = await resp.json();
      const box = document.getElementById('msgBox');
      if (data.success) {
        showMsg(box, 'success', data.message);
        closeModal('createWeaponModal');
        const customName = document.getElementById('cw_event_name').value.trim();
        window.location.href = `lane_allocations.php?event_name=${encodeURIComponent(customName)}&category=NR`;
      } else {
        showMsg(box, 'error', data.message);
      }
    } catch {
      alert('Network error.');
    } finally {
      btn.disabled = false;
    }
  });

  async function deleteAllocation(eventRegId) {
    if (!confirm('Are you sure you want to remove this lane allocation?')) return;
    const fd = new FormData();
    fd.append('event_reg_id', eventRegId.toString());
    try {
      const resp = await fetch('actions/delete_lane_allocation.php', { method: 'POST', body: fd });
      const data = await resp.json();
      const box = document.getElementById('msgBox');
      if (data.success) {
        showMsg(box, 'success', data.message);
        const row = document.getElementById('allocrow_' + eventRegId);
        if (row) row.remove();
        await silentRefreshPage();
      } else {
        showMsg(box, 'error', data.message);
      }
    } catch {
      alert('Network error.');
    }
  }

  // ─── Per-panel Print ─────────────────────────────────────────

  function printPanel(panelId) {
    // Mark which panel to print and which to hide
    document.querySelectorAll('.lane-alloc-panel').forEach(el => {
      el.dataset.print = el.id === panelId ? 'target' : 'hide';
    });
    document.querySelectorAll('.print-title').forEach(el => {
      el.dataset.print = el.dataset.forPanel === panelId ? 'target' : 'hide';
    });
    // Do NOT set body.printing-modal — that class is only for the master start
    // sheet overlay. Without it, the first @media print block controls layout.
    window.print();
    // Clean up data-print attributes after the print dialog closes
    setTimeout(() => {
      document.querySelectorAll('[data-print]').forEach(el => delete el.dataset.print);
    }, 1000);
  }

  async function triggerAutoAllocate(key, weaponType) {
    saveTableState(key);
    var data = loadData(key);
    if (!data.dates || data.dates.length === 0) {
      const todayDate = new Date().toISOString().split('T')[0];
      const parts = todayDate.split('-');
      const formattedToday = `${parts[2]}-${parts[1]}-${parts[0]}`;
      data.dates = [{
        date: formattedToday,
        relays: [
          { r: 1, rep: '08:30', st: '09:00' },
          { r: 2, rep: '11:00', st: '11:30' },
          { r: 3, rep: '13:30', st: '14:00' },
          { r: 4, rep: '16:00', st: '16:30' }
        ]
      }];
    }

    var grandTotalReg = 0;
    for (var club in data.regCounts) {
      grandTotalReg += parseInt(data.regCounts[club]) || 0;
    }
    var limit = window['dbCapacity_' + key] || 40;
    var numRelays = 0;
    if (data.dates) {
      data.dates.forEach(function (d) {
        if (d.relays) numRelays += d.relays.length;
      });
    }
    var totalCapacity = numRelays * limit;

    if (grandTotalReg > totalCapacity) {
      Swal.fire({
        title: 'Capacity Exceeded',
        html: '<div style="text-align:left; font-size:14px; line-height:1.6;">More participants present (' + grandTotalReg + ' registered) than the total available capacity (' + totalCapacity + ' = ' + numRelays + ' relays * ' + limit + ' firing points).<br><br><span style="color:#ff6b6b; font-weight:600;">Please add extra dates or extra relays before allocating.</span></div>',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
      return;
    }

    const fd = new FormData();
    fd.append('weapon_type', weaponType);
    fd.append('schedule', JSON.stringify(data.dates));
    fd.append('event_ids', JSON.stringify(baseGroupsMap[key] || []));
    fd.append('action', 'generate');
    fd.append('force_auto', '1');
    fd.append('allocs', '{}');
    fd.append('manual_clubs', JSON.stringify(data.regCounts || {}));

    try {
      const resp = await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
      const resData = await resp.json();
      if (resData.success) {
        data.allocs = resData.allocs || {};
        window['dbAllocs_' + key] = data.allocs;
        window['hasDbAllocs_' + key] = true;
        saveData(key, data);
        renderEditableTable(key);
      } else {
        alert(resData.message || 'Error generating allocations.');
      }
    } catch (e) {
      console.error('Auto allocate error:', e);
      alert('Network error while saving allocations.');
    }
  }

  async function generateStartSheetForEvent(key) {
    var data = loadData(key);
    if (!data.dates || data.dates.length === 0) {
      alert('Cannot generate start list without dates. Please add a date and relays first.');
      return;
    }

    var exceeded = validateAllocations(key);
    if (exceeded.length > 0) {
      Swal.fire({
        title: 'Cannot Generate Start List',
        html: '<div style="text-align:left; font-size:14px; line-height:1.6;">Please fix the following relay capacity violations first:<br><br>' + exceeded.join('<br>') + '</div>',
        icon: 'error',
        background: '#1a1a1a',
        color: '#fff'
      });
      return;
    }

    if (!confirm('Are you sure you want to generate the start list for this event? This will allocate approved shooters to dates and relays in the database.')) {
      return;
    }

    const eventObj = baseEventsList.find(item => item.key === key);
    const wtName = eventObj ? eventObj.label : key;

    const fd = new FormData();
    fd.append('weapon_type', wtName);
    fd.append('action', 'generate');
    fd.append('event_ids', JSON.stringify(baseGroupsMap[key] || []));
    fd.append('schedule', JSON.stringify(data.dates));
    fd.append('allocs', JSON.stringify(data.allocs || {}));

    try {
      const resp = await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
      const resData = await resp.json();
      if (resData.success) {
        window['hasDbAllocs_' + key] = true;
        Swal.fire({
          text: 'Start List generated successfully! Redirecting...',
          icon: 'success',
          background: '#1a1a1a',
          color: '#fff',
          timer: 1500,
          showConfirmButton: false
        });
        setTimeout(() => {
          window.location.href = 'start_sheet.php?events=' + encodeURIComponent(JSON.stringify([key]));
        }, 1500);
      } else {
        alert(resData.message);
      }
    } catch (e) {
      alert('Network error.');
    }
  }

  window.savePanelState = async function (key) {
    saveTableState(key);
    var data = loadData(key);
    var eventObj = (typeof baseEventsList !== 'undefined') ? baseEventsList.find(function(item) { return item.key === key; }) : null;
    var wtName = (typeof keyToWt !== 'undefined' && keyToWt[key]) ? keyToWt[key] : key;
    var weaponType = eventObj ? eventObj.label : wtName;

    var fd = new FormData();
    fd.append('weapon_type', weaponType);
    fd.append('schedule', JSON.stringify(data.dates || []));
    fd.append('allocs', JSON.stringify(data.allocs || {}));
    fd.append('event_ids', JSON.stringify((typeof baseGroupsMap !== 'undefined' && baseGroupsMap[key]) ? baseGroupsMap[key] : []));
    fd.append('action', 'generate');
    fd.append('manual_clubs', JSON.stringify(data.regCounts || {}));

    try {
      const resp = await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
      const resData = await resp.json();
      if (resData.success) {
        window['hasDbAllocs_' + key] = true;
        showMsg(document.getElementById('msgBox'), 'success', 'Layout & allocations saved successfully to database.');
      } else {
        alert(resData.message || 'Error saving allocations.');
      }
    } catch (e) {
      alert('Network error while saving allocations.');
    }
  };

  window.saveAllActivePanels = async function () {
    if (typeof activeWeaponKeys === 'undefined' || activeWeaponKeys.length === 0) return;
    let savedCount = 0;
    for (const key of activeWeaponKeys) {
      saveTableState(key);
      var data = loadData(key);
      var eventObj = (typeof baseEventsList !== 'undefined') ? baseEventsList.find(function(item) { return item.key === key; }) : null;
      var wtName = (typeof keyToWt !== 'undefined' && keyToWt[key]) ? keyToWt[key] : key;
      var weaponType = eventObj ? eventObj.label : wtName;

      var fd = new FormData();
      fd.append('weapon_type', weaponType);
      fd.append('schedule', JSON.stringify(data.dates || []));
      fd.append('allocs', JSON.stringify(data.allocs || {}));
      fd.append('event_ids', JSON.stringify((typeof baseGroupsMap !== 'undefined' && baseGroupsMap[key]) ? baseGroupsMap[key] : []));
      fd.append('action', 'generate');
      fd.append('manual_clubs', JSON.stringify(data.regCounts || {}));

      try {
        const resp = await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
        const resData = await resp.json();
        if (resData.success) {
          window['hasDbAllocs_' + key] = true;
          savedCount++;
        }
      } catch (e) {}
    }

    if (savedCount > 0) {
      showMsg(document.getElementById('msgBox'), 'success', 'All layouts & allocations saved successfully to database!');
    }
  };

  // Master Start Sheet Overlay Functions
  window.currentStartSheetEvent = [];

  async function openMasterStartSheet() {
    const contentEl = document.getElementById('masterStartSheetContent');

    openModal('masterStartSheetModal');

    // Render Selector screen with checkboxes
    let selectHtml = `
    <div style="max-width: 480px; margin: 40px auto; text-align: left; background: rgba(255,255,255,0.02); padding: 35px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); box-shadow: 0 8px 32px rgba(0,0,0,0.3);">
      <h4 style="margin-bottom: 20px; font-family: 'Rajdhani', sans-serif; color: var(--gold-400); font-size: 22px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; text-align: center;">Generate Start Sheet</h4>
      
      <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px;">
        <input type="checkbox" id="selectAllEvents" onchange="toggleSelectAllEvents(this)" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--gold-500);">
        <label for="selectAllEvents" style="color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; user-select: none;">Select All Events</label>
      </div>
      
      <div style="border-top: 1px solid rgba(255,255,255,0.08); margin-bottom: 15px;"></div>
      
      <div id="startSheetEventsContainer" style="max-height: 250px; overflow-y: auto; margin-bottom: 20px; display: flex; flex-direction: column; gap: 10px; padding-right: 5px;">
  `;

    baseEventsList.forEach(function (item, idx) {
      var hasAlloc = window['hasDbAllocs_' + item.key];
      var badge = hasAlloc ? ' <span style="color:#2ec866; font-size:11px; font-weight:600; margin-left: 6px;">(Generated)</span>' : '';
      selectHtml += `
      <div style="display: flex; align-items: center; gap: 10px;">
        <input type="checkbox" class="start-sheet-event-chk" id="chk_evt_${idx}" value="${esc(item.key)}" style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--gold-500);">
        <label for="chk_evt_${idx}" style="color: var(--text-secondary); font-size: 13px; cursor: pointer; user-select: none;">${esc(item.label)}${badge}</label>
      </div>
    `;
    });

    selectHtml += `
      </div>
      <button type="button" class="btn" onclick="fetchStartSheetForSelected()" style="padding: 12px 24px; background:var(--gold-500); color:#fff; font-weight:700; width: 100%; font-size: 14px; cursor: pointer; border-radius: 4px; border: none; outline: none; transition: background 0.2s;">Generate Master Sheet</button>
    </div>
  `;

    contentEl.innerHTML = selectHtml;
  }

  window.toggleSelectAllEvents = function (master) {
    document.querySelectorAll('.start-sheet-event-chk').forEach(function (chk) {
      if (!chk.disabled) {
        chk.checked = master.checked;
      }
    });
  };

  async function fetchStartSheetForSelected(keysVal) {
    const contentEl = document.getElementById('masterStartSheetContent');

    let selectedKeys = [];
    if (keysVal) {
      selectedKeys = keysVal;
    } else {
      const chks = document.querySelectorAll('.start-sheet-event-chk:checked');
      if (chks.length === 0) {
        alert('Please select at least one event.');
        return;
      }
      selectedKeys = Array.from(chks).map(c => c.value);
    }

    // Validate all selected keys first
    for (const key of selectedKeys) {
      var exceeded = validateAllocations(key);
      if (exceeded.length > 0) {
        const eventObj = baseEventsList.find(item => item.key === key);
        const wtName = eventObj ? eventObj.label : key;
        Swal.fire({
          title: 'Cannot Generate Master Start Sheet',
          html: '<div style="text-align:left; font-size:14px; line-height:1.6;">Event "' + wtName + '" has relay capacity violations:<br><br>' + exceeded.join('<br>') + '</div>',
          icon: 'error',
          background: '#1a1a1a',
          color: '#fff'
        });
        openMasterStartSheet();
        return;
      }
    }

    window.currentStartSheetEvent = selectedKeys;

    contentEl.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><p>Assigning individual shooters to lanes in the database... Please wait.</p></div>';

    try {
      let successfullyGeneratedKeys = [];
      // 1. For each selected active weapon key, run the auto_allocate.php generate sequence to update the database
      for (const key of selectedKeys) {
        const data = loadData(key);
        const eventObj = baseEventsList.find(item => item.key === key);
        const wtName = eventObj ? eventObj.label : key;

        if (!data.dates || data.dates.length === 0) {
          console.warn(`Skipping "${wtName}" because it has no dates or relays configured.`);
          continue;
        }

        const fd = new FormData();
        fd.append('weapon_type', wtName);
        fd.append('action', 'generate');
        fd.append('event_ids', JSON.stringify(baseGroupsMap[key] || []));
        fd.append('schedule', JSON.stringify(data.dates));
        fd.append('allocs', JSON.stringify(data.allocs || {}));

        const resp = await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
        const resData = await resp.json();
        if (!resData.success) {
          alert(`Error for event "${wtName}": ${resData.message}`);
          continue;
        }

        window['hasDbAllocs_' + key] = true;
        successfullyGeneratedKeys.push(key);
      }

      if (successfullyGeneratedKeys.length === 0) {
        alert("No start sheets were generated. Please make sure the selected events have dates and relays configured with allocated participants first.");
        contentEl.innerHTML = '<div style="text-align: center; padding: 20px;">No start sheets generated.</div>';
        return;
      }

      contentEl.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><p>Start sheet generated successfully! Redirecting to Start Sheets area...</p></div>';

      // Redirect to the Start Sheets area page
      window.location.href = 'start_sheet.php?events=' + encodeURIComponent(JSON.stringify(successfullyGeneratedKeys));
    } catch (e) {
      contentEl.innerHTML = '<div style="color: #ff6b6b; padding: 20px;">Error generating start sheet.</div>';
    }
  }
  window.fetchStartSheetForSelected = fetchStartSheetForSelected;
</script>

<script>
  // ─── Editable Lane Allocation Tables ─────────────────────────

  (function () {
    // Clear any previously cached dummy data from localStorage
    (function clearOldDummyData() {
      var keys = ['laneAlloc_freeRifle', 'laneAlloc_airRifle', 'laneAlloc_pistol'];
      var dummyDates = ['23-07-2024', '24-07-2024', '25-07-2024'];
      keys.forEach(function (k) {
        var saved = localStorage.getItem(k);
        if (saved) {
          try {
            var parsed = JSON.parse(saved);
            if (parsed.dates && parsed.dates.some(function (d) { return dummyDates.indexOf(d.date) !== -1; })) {
              localStorage.removeItem(k);
            }
          } catch (e) { }
        }
      });
    })();

    // Build initial state from DB data (events + reg counts), empty dates/relays
    function getInitialState(key) {
      var eventsList = window['dbEvents_' + key] || [];
      var regCounts = window['dbRegCounts_' + key] || {};
      var dates = window['dbDates_' + key] || [];
      var allocs = window['dbAllocs_' + key] || {};
      return {
        dates: dates,
        eventsList: eventsList,
        regCounts: regCounts,
        allocs: allocs,
      };
    }

    const darkDateColors = ['rgba(255, 255, 255,0.08)', 'rgba(39,174,96,0.08)', 'rgba(41,128,185,0.08)', 'rgba(142,68,173,0.08)', 'rgba(230,126,34,0.08)'];
    const darkHeaderDateColors = ['rgba(255, 255, 255,0.15)', 'rgba(39,174,96,0.15)', 'rgba(41,128,185,0.15)', 'rgba(142,68,173,0.15)', 'rgba(230,126,34,0.15)'];
    const dateTextColors = ['#ADB5BD', '#27ae60', '#3498db', '#9b59b6', '#e67e22'];

    function getStorageKey(key) { return 'laneAlloc_' + key; }

    function loadData(key) {
      var storageKey = getStorageKey(key);
      var saved = null;
      try { saved = localStorage.getItem(storageKey); } catch (e) {}
      var localData = null;
      if (saved) {
        try { localData = JSON.parse(saved); } catch (e) {}
      }
      
      const data = getInitialState(key);
      if (localData && localData.dates && localData.dates.length > 0 && (!data.dates || data.dates.length === 0)) {
        data.dates = localData.dates;
      }
      if (localData && localData.manualClubs && localData.manualClubs.length > 0 && (!data.manualClubs || data.manualClubs.length === 0)) {
        data.manualClubs = localData.manualClubs;
      }
      if (localData && localData.deletedClubs) {
        data.deletedClubs = localData.deletedClubs;
      }
      if (!data.manualClubs) data.manualClubs = [];
      if (!data.deletedClubs) data.deletedClubs = [];
      if (!data.eventsList) data.eventsList = [];
      if (!data.regCounts) data.regCounts = {};
      if (!data.allocs) data.allocs = {};
      if (!data.dates) data.dates = [];
      return data;
    }
    window.loadData = loadData; // Fix: expose to global scope for triggerAutoAllocate
    window.validateAllocations = validateAllocations; // Fix: expose to global scope for start sheet generation

    function parseDateDMY(dStr) {
      if (!dStr) return new Date(0);
      var p = dStr.split('-');
      if (p.length === 3) {
        return new Date(parseInt(p[2]), parseInt(p[1]) - 1, parseInt(p[0]));
      }
      return new Date(dStr);
    }

    function getEventInterval(data, key) {
      for (var di = 0; di < data.dates.length; di++) {
        var relays = data.dates[di].relays;
        if (relays && relays.length >= 2) {
          var minDiff = Infinity;
          for (var ri = 0; ri < relays.length - 1; ri++) {
            var diff = timeToMin(relays[ri + 1].st) - timeToMin(relays[ri].st);
            if (diff > 0 && diff < minDiff) {
              minDiff = diff;
            }
          }
          if (minDiff !== Infinity) {
            return minDiff;
          }
        }
      }
      if (key) {
        const isIssf = key.toLowerCase().includes('issf');
        return isIssf ? 120 : 90;
      }
      return 150;
    }

    function reindexRelays(data, key) {
      if (data.dates) {
        data.dates.sort(function (a, b) {
          return parseDateDMY(a.date) - parseDateDMY(b.date);
        });
      }

      var newIdx = 1;
      var mapping = {};

      var interval = getEventInterval(data, key);
      var offset = (typeof data.reportingOffset === 'number' && data.reportingOffset >= 0) ? data.reportingOffset : 25;

      data.dates.forEach(function (d) {
        if (!d.relays || d.relays.length === 0) return;

        var firstStart = d.relays[0].st || '09:00';
        var firstStartMins = timeToMin(firstStart);

        d.relays.forEach(function (r, idx) {
          mapping[r.r] = newIdx;

          var rStartMins = firstStartMins + idx * interval;
          r.st = minToTime(rStartMins);
          r.rep = minToTime(rStartMins - offset);
          r.r = newIdx;

          newIdx++;
        });
      });

      if (data.allocs) {
        var newAllocs = {};
        for (var club in data.allocs) {
          newAllocs[club] = {};
          for (var dateStr in data.allocs[club]) {
            newAllocs[club][dateStr] = {};
            for (var oldR in data.allocs[club][dateStr]) {
              var val = data.allocs[club][dateStr][oldR];
              var newR = mapping[oldR];
              if (newR !== undefined) {
                newAllocs[club][dateStr][newR] = val;
              }
            }
          }
        }
        data.allocs = newAllocs;
      }
    }

    function saveData(key, data) {
      if (data) {
        var storageKey = getStorageKey(key);
        try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch (e) {}
        if (data.dates) window['dbDates_' + key] = data.dates;
        if (data.allocs) window['dbAllocs_' + key] = data.allocs;
      }
    }
    window.saveData = saveData;

    function saveTableState(key) {
      const table = document.getElementById('table-' + key);
      if (!table) return;
      const data = loadData(key);
      const rows = table.querySelectorAll('tbody tr');
      rows.forEach(tr => {
        const clubCell = tr.querySelector('.cell-club');
        if (!clubCell) return;
        const clubName = clubCell.textContent.trim();
        if (!clubName) return;

        if (!data.allocs[clubName]) data.allocs[clubName] = {};

        const countCells = tr.querySelectorAll('.cell-alloc-count');
        let cIdx = 0;
        (data.dates || []).forEach(d => {
          if (!data.allocs[clubName][d.date]) data.allocs[clubName][d.date] = {};
          (d.relays || []).forEach(r => {
            if (countCells[cIdx]) {
              const val = parseInt(countCells[cIdx].textContent.trim(), 10) || 0;
              data.allocs[clubName][d.date][r.r] = val;
            }
            cIdx++;
          });
        });
      });
      saveData(key, data);
    }
    window.saveTableState = saveTableState;

    // ─── Render table ──────────────────────────────────────────
    window.renderEditableTable = function (key) {
      const data = loadData(key);
      const table = document.getElementById('table-' + key);
      if (!table) return;

      const dates = data.dates || [];
      const eventsList = data.eventsList || [];
      const regCounts = data.regCounts || {};
      const allocs = data.allocs || {};

      let html = '';

      // ── THEAD ──
      html += '<thead>';
      // Row 1: Date headers
      html += '<tr class="alloc-header-row">';
      html += '<th rowspan="5" class="s-no-cell">S No</th>';
      html += '<th rowspan="5" class="label-cell">Club Name</th>';
      html += '<th class="total-shooters-header">Total<br>Shooters</th>';
      dates.forEach(function (d, di) {
        var ci = di % darkHeaderDateColors.length;
        html += '<th colspan="' + d.relays.length + '" class="date-header-cell" contenteditable="true" style="background:' + darkHeaderDateColors[ci] + ';color:' + dateTextColors[ci] + ';">' + esc(d.date) + '</th>';
      });
      html += '<th rowspan="5" class="total-col-header">Total</th>';
      html += '</tr>';

      // Row 2: Relay numbers
      html += '<tr class="alloc-subheader">';
      html += '<th>Relay</th>';
      dates.forEach(function (d, di) {
        var ci = di % darkDateColors.length;
        d.relays.forEach(function (r) {
          html += '<th class="relay-header-cell" contenteditable="true" style="background:' + darkDateColors[ci] + ';">R-' + r.r + '</th>';
        });
      });
      html += '</tr>';

      // Row 3: Reporting times
      html += '<tr class="alloc-subheader">';
      html += '<th>Reporting</th>';
      dates.forEach(function (d, di) {
        var ci = di % darkDateColors.length;
        d.relays.forEach(function (r) {
          html += '<th class="time-header-cell" contenteditable="true" style="background:' + darkDateColors[ci] + ';">' + esc(r.rep) + '</th>';
        });
      });
      html += '</tr>';

      // Row 4: Match Start times
      html += '<tr class="alloc-subheader">';
      html += '<th>Match Start</th>';
      dates.forEach(function (d, di) {
        var ci = di % darkDateColors.length;
        d.relays.forEach(function (r) {
          html += '<th class="match-start-header" contenteditable="true" style="background:' + darkDateColors[ci] + ';">' + esc(r.st) + '</th>';
        });
      });
      html += '</tr>';

      // Row 5: Limit
      html += '<tr class="alloc-subheader">';
      html += '<th>Limit</th>';
      dates.forEach(function (d, di) {
        var ci = di % darkDateColors.length;
        d.relays.forEach(function (r) {
          var rLimit = r.limit || window['dbCapacity_' + key] || 40;
          html += '<th class="limit-header-cell" contenteditable="true" style="background:' + darkDateColors[ci] + ';">' + rLimit + '</th>';
        });
      });
      html += '</tr>';
      html += '</thead>';

      // ── TBODY ──
      html += '<tbody>';
      var grandTotalReg = 0;
      var grandTotalAlloc = 0;
      var relayGT = {};

      eventsList.forEach(function (clubName, idx) {
        var sno = idx + 1;
        var clubKeyLower = clubName.toLowerCase();
        var foundRegKey = Object.keys(regCounts || {}).find(function (k) { return k.toLowerCase() === clubKeyLower; });
        var regCount = foundRegKey ? (parseInt(regCounts[foundRegKey], 10) || 0) : (parseInt(regCounts[clubName], 10) || 0);
        var allocTotal = 0;
        var rowCellsHtml = '';
        dates.forEach(function (d, di) {
          var ci = di % darkDateColors.length;
          d.relays.forEach(function (r) {
            var val = 0;
            var clubKeyLower = clubName.toLowerCase();
            var foundClubKey = Object.keys(allocs).find(function (k) { return k.toLowerCase() === clubKeyLower; });
            if (foundClubKey && allocs[foundClubKey] && allocs[foundClubKey][d.date] && allocs[foundClubKey][d.date][r.r] !== undefined) {
              val = allocs[foundClubKey][d.date][r.r];
            }
            allocTotal += val;

            if (!relayGT[d.date]) relayGT[d.date] = {};
            if (!relayGT[d.date][r.r]) relayGT[d.date][r.r] = 0;
            relayGT[d.date][r.r] += val;

            var cls = val > 0 ? 'cell-alloc-count has-value' : 'cell-alloc-count';
            rowCellsHtml += '<td class="' + cls + '" contenteditable="true" style="background:' + darkDateColors[ci] + ';">' + (val > 0 ? val : '') + '</td>';
          });
        });

        regCount = Math.max(regCount, allocTotal);
        grandTotalReg += regCount;

        html += '<tr>';
        html += '<td class="cell-sno">' + sno + '</td>';
        html += '<td class="cell-club" contenteditable="true">' + esc(clubName) + '</td>';
        html += '<td class="cell-total-shooters" contenteditable="true">' + regCount + '</td>';
        html += rowCellsHtml;
        html += '<td class="cell-club-total">' + allocTotal + '</td>';
        html += '</tr>';
        grandTotalAlloc += allocTotal;
      });

      html += '</tbody>';

      // ── TFOOT ──
      html += '<tfoot>';
      html += '<tr class="footer-summary">';
      html += '<td colspan="2" class="footer-label">Total</td>';
      html += '<td class="footer-total-registered">' + grandTotalReg + '</td>';

      dates.forEach(function (d) {
        d.relays.forEach(function (r) {
          var v = (relayGT[d.date] && relayGT[d.date][r.r]) ? relayGT[d.date][r.r] : 0;
          html += '<td class="footer-relay-total">' + v + '</td>';
        });
      });

      html += '<td class="footer-grand-total">' + grandTotalAlloc + '</td>';
      html += '</tr>';
      html += '</tfoot>';

      table.innerHTML = html;
      table.dataset.key = key;
      generatePrintSplitTables(key, dates, eventsList, regCounts, allocs, grandTotalReg, grandTotalAlloc);
    };

    function generatePrintSplitTables(key, dates, eventsList, regCounts, allocs, grandTotalReg, grandTotalAlloc) {
      // Flatten the relays list but keep their date context
      var flatRelays = [];
      dates.forEach(function (d) {
        d.relays.forEach(function (r) {
          flatRelays.push({
            date: d.date,
            relay: r
          });
        });
      });

      var chunkSize = 8;
      var html = '';
      var container = document.getElementById('print-container-' + key);
      if (!container) return;

      if (flatRelays.length === 0) {
        container.innerHTML = '';
        return;
      }

      for (var i = 0; i < flatRelays.length; i += chunkSize) {
        var chunk = flatRelays.slice(i, i + chunkSize);
        var isFirst = (i === 0);
        var isLast = (i + chunkSize >= flatRelays.length);

        html += '<div class="print-split-table-wrapper" style="margin-top: 20px; margin-bottom: 20px; page-break-inside: avoid; break-inside: avoid;">';

        var partNum = Math.floor(i / chunkSize) + 1;
        var totalParts = Math.ceil(flatRelays.length / chunkSize);
        html += '<h4 style="margin-bottom: 8px; font-size: 13px; text-transform: uppercase; font-family:\'Rajdhani\',sans-serif; font-weight:700; text-align: left; color:#000;">';
        html += 'Part ' + partNum + ' of ' + totalParts + ' (Relays ' + (i + 1) + ' - ' + Math.min(i + chunkSize, flatRelays.length) + ')';
        html += '</h4>';

        html += '<table class="alloc-summary-table" style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">';

        // THEAD
        html += '<thead>';

        // Row 1
        html += '<tr class="alloc-header-row">';
        html += '<th rowspan="5" style="width: 50px;">S No</th>';
        html += '<th rowspan="5" style="width: 250px; text-align: left; padding-left: 8px;">Club Name</th>';
        html += '<th rowspan="5" style="width: 80px;">Total<br>Shooters</th>';

        var chunkDates = [];
        chunk.forEach(function (item) {
          var lastDate = chunkDates[chunkDates.length - 1];
          if (lastDate && lastDate.date === item.date) {
            lastDate.colspan++;
          } else {
            chunkDates.push({ date: item.date, colspan: 1 });
          }
        });

        chunkDates.forEach(function (cd) {
          html += '<th colspan="' + cd.colspan + '">' + esc(cd.date) + '</th>';
        });

        html += '<th rowspan="5" style="width: 80px;">Total</th>';
        html += '</tr>';

        // Row 2: Relay names
        html += '<tr class="alloc-subheader">';
        chunk.forEach(function (item) {
          html += '<th>R-' + item.relay.r + '</th>';
        });
        html += '</tr>';

        // Row 3: Reporting times
        html += '<tr class="alloc-subheader">';
        chunk.forEach(function (item) {
          html += '<th>' + esc(item.relay.rep || '') + '</th>';
        });
        html += '</tr>';

        // Row 4: Match Start times
        html += '<tr class="alloc-subheader">';
        chunk.forEach(function (item) {
          html += '<th>' + esc(item.relay.st || '') + '</th>';
        });
        html += '</tr>';

        // Row 5: Limits
        html += '<tr class="alloc-subheader">';
        chunk.forEach(function (item) {
          var rLimit = item.relay.limit || window['dbCapacity_' + key] || 40;
          html += '<th>' + rLimit + '</th>';
        });
        html += '</tr>';
        html += '</thead>';

        // TBODY
        html += '<tbody>';
        var chunkRelayTotals = {};

        eventsList.forEach(function (clubName, idx) {
          var sno = idx + 1;
          var clubKeyLower = clubName.toLowerCase();
          var foundRegKey = Object.keys(regCounts || {}).find(function (k) { return k.toLowerCase() === clubKeyLower; });
          var regCount = foundRegKey ? (parseInt(regCounts[foundRegKey], 10) || 0) : (parseInt(regCounts[clubName], 10) || 0);
          var clubAllocTotal = 0;

          chunk.forEach(function (item) {
            var val = 0;
            var clubKeyLower = clubName.toLowerCase();
            var foundClubKey = Object.keys(allocs).find(function (k) { return k.toLowerCase() === clubKeyLower; });
            if (foundClubKey && allocs[foundClubKey] && allocs[foundClubKey][item.date] && allocs[foundClubKey][item.date][item.relay.r] !== undefined) {
              val = allocs[foundClubKey][item.date][item.relay.r];
            }
            clubAllocTotal += val;
          });

          html += '<tr>';
          html += '<td>' + sno + '</td>';
          html += '<td style="text-align: left; padding-left: 8px;">' + esc(clubName) + '</td>';
          html += '<td>' + regCount + '</td>';

          chunk.forEach(function (item) {
            var val = 0;
            var clubKeyLower = clubName.toLowerCase();
            var foundClubKey = Object.keys(allocs).find(function (k) { return k.toLowerCase() === clubKeyLower; });
            if (foundClubKey && allocs[foundClubKey] && allocs[foundClubKey][item.date] && allocs[foundClubKey][item.date][item.relay.r] !== undefined) {
              val = allocs[foundClubKey][item.date][item.relay.r];
            }

            if (!chunkRelayTotals[item.date]) chunkRelayTotals[item.date] = {};
            if (!chunkRelayTotals[item.date][item.relay.r]) chunkRelayTotals[item.date][item.relay.r] = 0;
            chunkRelayTotals[item.date][item.relay.r] += val;

            html += '<td>' + (val > 0 ? val : '') + '</td>';
          });

          html += '<td style="font-weight: bold;">' + clubAllocTotal + '</td>';
          html += '</tr>';
        });
        html += '</tbody>';

        // TFOOT
        html += '<tfoot>';
        html += '<tr style="font-weight: bold; background: rgba(0,0,0,0.02);">';
        html += '<td colspan="2">Total</td>';
        html += '<td>' + grandTotalReg + '</td>';

        var chunkGrandTotal = 0;
        chunk.forEach(function (item) {
          var v = (chunkRelayTotals[item.date] && chunkRelayTotals[item.date][item.relay.r]) ? chunkRelayTotals[item.date][item.relay.r] : 0;
          chunkGrandTotal += v;
          html += '<td>' + v + '</td>';
        });

        html += '<td>' + chunkGrandTotal + '</td>';
        html += '</tr>';
        html += '</tfoot>';

        html += '</table>';
        html += '</div>';

        // Force page break between parts
        if (!isLast) {
          html += '<div class="print-page-break" style="page-break-after: always; break-after: page;"></div>';
        }
      }

      container.innerHTML = html;
    }

    function esc(s) {
      if (s === null || s === undefined) return '';
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ─── Save table state to localStorage ──────────────────────
    window.saveTableState = function saveTableState(key) {
      var table = document.getElementById('table-' + key);
      if (!table) return;

      var existingData = loadData(key);
      var data = {
        dates: [],
        eventsList: [],
        regCounts: {},
        allocs: {},
        manualClubs: existingData.manualClubs || [],
        deletedClubs: existingData.deletedClubs || []  // preserve deleted clubs tracking
      };

      // Parse date headers
      var headerRow = table.querySelector('thead tr.alloc-header-row');
      if (headerRow) {
        var dateCells = headerRow.querySelectorAll('th.date-header-cell');
        var relayRows = table.querySelectorAll('thead tr.alloc-subheader');
        var relayNumRow = relayRows[0];
        var repRow = relayRows[1];
        var startRow = relayRows[2];
        var limitRow = relayRows[3];

        var relayCells = relayNumRow ? relayNumRow.querySelectorAll('th:not(:first-child)') : [];
        var repCells = repRow ? repRow.querySelectorAll('th:not(:first-child)') : [];
        var startCells = startRow ? startRow.querySelectorAll('th:not(:first-child)') : [];
        var limitCells = limitRow ? limitRow.querySelectorAll('th:not(:first-child)') : [];

        var cellIdx = 0;
        dateCells.forEach(function (dc) {
          var dateStr = dc.textContent.trim();
          var colspan = parseInt(dc.getAttribute('colspan')) || 1;
          var relays = [];
          for (var i = 0; i < colspan; i++) {
            var rText = relayCells[cellIdx] ? relayCells[cellIdx].textContent.trim().replace('R-', '') : '1';
            var repText = repCells[cellIdx] ? repCells[cellIdx].textContent.trim() : '08:35';
            var stText = startCells[cellIdx] ? startCells[cellIdx].textContent.trim() : '09:00';
            var defaultLimit = window['dbCapacity_' + key] || 40;
            var limitText = limitCells[cellIdx] ? limitCells[cellIdx].textContent.trim() : '';
            var rLimit = parseInt(limitText) || defaultLimit;
            if (rLimit > defaultLimit) {
              alert('Limit per relay cannot exceed the maximum capacity of ' + defaultLimit + ' for this range.');
              rLimit = defaultLimit;
              if (limitCells[cellIdx]) {
                limitCells[cellIdx].textContent = defaultLimit;
              }
            }
            relays.push({ r: parseInt(rText) || (i + 1), rep: repText, st: stText, limit: rLimit });
            cellIdx++;
          }
          data.dates.push({ date: dateStr, relays: relays });
        });
      }

      // Parse body rows
      var bodyRows = table.querySelectorAll('tbody tr');
      bodyRows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        if (cells.length < 3) return;
        var sno = cells[0].textContent.trim();
        var clubName = cells[1].textContent.trim();
        // Normalize alloc key to lowercase so it always matches auto-allocate backend output
        var clubKey = clubName.toLowerCase();
        var regCount = parseInt(cells[2].textContent.trim()) || 0;

        data.eventsList.push(clubName);
        data.regCounts[clubName] = regCount;

        // Parse allocation cells (using lowercase key for allocs)
        var allocIdx = 0;
        data.dates.forEach(function (d) {
          d.relays.forEach(function (r) {
            var cellIdxAlloc = 3 + allocIdx;
            if (cells[cellIdxAlloc]) {
              var val = parseInt(cells[cellIdxAlloc].textContent.trim()) || 0;
              if (!data.allocs[clubKey]) data.allocs[clubKey] = {};
              if (!data.allocs[clubKey][d.date]) data.allocs[clubKey][d.date] = {};
              data.allocs[clubKey][d.date][r.r] = val;
            }
            allocIdx++;
          });
        });
      });

      saveData(key, data);
      persistMatrixToDb(key);

      var exceeded = validateAllocations(key);
      if (exceeded.length > 0) {
        Swal.fire({
          title: 'Relay Limit Exceeded',
          html: '<div style="text-align:left; font-size:14px; line-height:1.6;">The following relays exceed capacity limits:<br><br>' + exceeded.join('<br>') + '<br><br><span style="color:#ff6b6b; font-weight:600;">Please reduce the allocation or add extra relays/dates before generating the start sheet.</span></div>',
          icon: 'warning',
          background: '#1a1a1a',
          color: '#fff'
        });
      }
    }

    var saveDebounceTimers = {};
    function persistMatrixToDb(key, immediate) {
      if (saveDebounceTimers[key]) clearTimeout(saveDebounceTimers[key]);
      var doSave = async function() {
        var data = loadData(key);
        var wtName = (typeof keyToWt !== 'undefined' && keyToWt[key]) ? keyToWt[key] : key;
        var eventObj = (typeof baseEventsList !== 'undefined') ? baseEventsList.find(function(item) { return item.key === key; }) : null;
        var weaponType = eventObj ? eventObj.label : wtName;

        var fd = new FormData();
        fd.append('weapon_type', weaponType);
        fd.append('schedule', JSON.stringify(data.dates || []));
        fd.append('allocs', JSON.stringify(data.allocs || {}));
        fd.append('event_ids', JSON.stringify((typeof baseGroupsMap !== 'undefined' && baseGroupsMap[key]) ? baseGroupsMap[key] : []));
        fd.append('action', 'generate');
        fd.append('manual_clubs', JSON.stringify(data.regCounts || {}));

        try {
          await fetch('actions/auto_allocate.php', { method: 'POST', body: fd });
          window['hasDbAllocs_' + key] = true;
        } catch (e) {
          console.error('Auto-save matrix error:', e);
        }
      };

      if (immediate) {
        doSave();
      } else {
        saveDebounceTimers[key] = setTimeout(doSave, 300);
      }
    }

    function validateAllocations(key) {
      var data = loadData(key);
      var defaultLimit = window['dbCapacity_' + key] || 40;
      var dates = data.dates || [];
      var allocs = data.allocs || {};
      var eventsList = data.eventsList || [];

      var exceeded = [];
      dates.forEach(function (d) {
        d.relays.forEach(function (r) {
          var total = 0;
          var rLimit = r.limit || defaultLimit;
          eventsList.forEach(function (clubName) {
            var clubKeyLower = clubName.toLowerCase();
            var foundClubKey = Object.keys(allocs).find(function (k) { return k.toLowerCase() === clubKeyLower; });
            if (foundClubKey && allocs[foundClubKey] && allocs[foundClubKey][d.date] && allocs[foundClubKey][d.date][r.r] !== undefined) {
              total += allocs[foundClubKey][d.date][r.r];
            }
          });
          if (total > rLimit) {
            exceeded.push('Relay R-' + r.r + ' on ' + d.date + ' has ' + total + ' allocated (Limit: ' + rLimit + ')');
          }
        });
      });
      return exceeded;
    }

    // ─── Recalculate totals ────────────────────────────────────
    function recalcTotals(key) {
      var table = document.getElementById('table-' + key);
      if (!table) return;

      var bodyRows = table.querySelectorAll('tbody tr');
      var grandTotalReg = 0;
      var grandTotalAlloc = 0;
      var dateCols = {};
      var dateRelayOrder = [];

      // Build column order from date headers
      var headerRow = table.querySelector('thead tr.alloc-header-row');
      if (!headerRow) return;
      var dateCells = headerRow.querySelectorAll('th.date-header-cell');
      var subheaders = table.querySelectorAll('thead tr.alloc-subheader');
      var relayNumRow = subheaders[0];
      var relayHeaderCells = relayNumRow ? relayNumRow.querySelectorAll('th:not(:first-child)') : [];
      var limitRow = subheaders[3];
      var limitCells = limitRow ? limitRow.querySelectorAll('th:not(:first-child)') : [];

      var cellIdx = 0;
      var totalCapacity = 0;
      dateCells.forEach(function (dc) {
        var dateStr = dc.textContent.trim();
        var colspan = parseInt(dc.getAttribute('colspan')) || 1;
        for (var i = 0; i < colspan; i++) {
          if (!dateCols[dateStr]) dateCols[dateStr] = [];
          dateCols[dateStr].push(cellIdx);
          var lVal = limitCells[cellIdx] ? parseInt(limitCells[cellIdx].textContent.trim()) : NaN;
          if (isNaN(lVal)) {
            lVal = window['dbCapacity_' + key] || 40;
          }
          totalCapacity += lVal;
          dateRelayOrder.push({ date: dateStr, idx: cellIdx, limit: lVal });
          cellIdx++;
        }
      });

      bodyRows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        var totalAlloc = 0;

        dateRelayOrder.forEach(function (dr) {
          var ci = 3 + dr.idx;
          if (cells[ci]) {
            var val = parseInt(cells[ci].textContent.trim()) || 0;
            totalAlloc += val;
            cells[ci].textContent = val > 0 ? val : '';
            cells[ci].className = val > 0 ? 'cell-alloc-count has-value' : 'cell-alloc-count';
          }
        });

        grandTotalAlloc += totalAlloc;
        var totalCell = cells[cells.length - 1];
        if (totalCell) totalCell.textContent = totalAlloc;

        var regCell = cells[2];
        if (regCell) grandTotalReg += parseInt(regCell.textContent.trim()) || 0;
      });

      var warnBox = document.getElementById('warning-' + key);
      if (warnBox) {
        if (grandTotalReg > totalCapacity) {
          warnBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning"></i> <strong>Capacity Exceeded Warning:</strong> More participants present (' + grandTotalReg + ' registered) than the total available capacity (' + totalCapacity + ' = sum of relay firing points). You need to add extra dates or extra relays to allocate everyone.';
          warnBox.style.display = 'block';
        } else {
          warnBox.style.display = 'none';
        }
      }

      // Update footer
      var footRow = table.querySelector('tfoot tr');
      if (footRow) {
        var footCells = footRow.querySelectorAll('td');
        var footIdx = 2;
        if (footCells[1]) footCells[1].textContent = grandTotalReg;

        dateRelayOrder.forEach(function (dr) {
          var totalVal = 0;
          bodyRows.forEach(function (row) {
            var cells = row.querySelectorAll('td');
            var ci = 3 + dr.idx;
            if (cells[ci]) {
              totalVal += parseInt(cells[ci].textContent.trim()) || 0;
            }
          });
          if (footCells[footIdx]) {
            footCells[footIdx].textContent = totalVal;
            if (totalVal > dr.limit) {
              footCells[footIdx].style.background = '#ff6b6b';
              footCells[footIdx].style.color = '#000';
              footCells[footIdx].style.fontWeight = 'bold';
              footCells[footIdx].title = 'Exceeds limit of ' + dr.limit;
            } else {
              footCells[footIdx].style.background = '';
              footCells[footIdx].style.color = '';
              footCells[footIdx].style.fontWeight = '';
              footCells[footIdx].title = '';
            }
          }
          footIdx++;
        });

        if (footCells[footIdx]) footCells[footIdx].textContent = grandTotalAlloc;
      }
    }

    // ─── ContentEditable handlers ──────────────────────────────
    function setupEditableListeners() {
      document.querySelectorAll('.alloc-summary-table').forEach(function (table) {
        ['blur', 'input', 'keyup'].forEach(function(evtName) {
          table.addEventListener(evtName, function (e) {
            var target = e.target;
            if (target.hasAttribute('contenteditable')) {
              var key = table.dataset.key;
              if (key) {
                recalcTotals(key);
                saveTableState(key);
                if (evtName === 'blur') {
                  persistMatrixToDb(key, true);
                }
              }
            }
          }, true);
        });

        table.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            e.target.blur();
          }
        });
      });
    }

    // ─── Add row ───────────────────────────────────────────────
    window.addTableRow = function (key) {
      var table = document.getElementById('table-' + key);
      if (!table) return;
      var tbody = table.querySelector('tbody');
      if (!tbody) return;

      var club = prompt("Enter Club Name:");
      if (!club) return;

      // Load raw saved data to manipulate deletedClubs without triggering reconstruct
      var storageKey = getStorageKey(key);
      var saved = localStorage.getItem(storageKey);
      var data;
      try { data = saved ? JSON.parse(saved) : {}; } catch (e) { data = {}; }
      if (!data.manualClubs) data.manualClubs = [];
      if (!data.deletedClubs) data.deletedClubs = [];
      if (!data.regCounts) data.regCounts = {};

      var clubLower = club.toLowerCase();
      // If this club was previously deleted, remove it from deletedClubs so it can show again
      data.deletedClubs = data.deletedClubs.filter(function (c) {
        return c.toLowerCase() !== clubLower;
      });

      // Add to manualClubs if not already present (case-insensitive check)
      var alreadyManual = data.manualClubs.some(function (c) { return c.toLowerCase() === clubLower; });
      if (!alreadyManual) {
        data.manualClubs.push(club);
      }
      data.regCounts[club] = 0;
      // Save raw, then call saveTableState so allocs/dates/relay data is fully captured
      // and the DB auto-sync fires if a start sheet already exists
      localStorage.setItem(storageKey, JSON.stringify(data));
      renderEditableTable(key);
      // Capture full table state (allocs, dates) and trigger auto-sync
      if (window.saveTableState) window.saveTableState(key);

      // Focus the new club name cell
      var rows = tbody.querySelectorAll('tr');
      var lastRow = rows[rows.length - 1];
      if (lastRow) {
        var clubCell = lastRow.querySelector('td.cell-club');
        if (clubCell) { clubCell.focus(); }
      }
    };

    // ─── Reset Layout ──────────────────────────────────────────
    window.resetLayout = async function (key) {
      if (!confirm('Are you sure you want to reset the layout for this event? This will clear all dates, relays, allocations, manual edits, and database start sheets for this event. The layout will reset back to default.')) return;

      const eventObj = baseEventsList.find(item => item.key === key);
      const wtName = eventObj ? eventObj.label : key;
      const dbKey = (typeof keyToWt !== 'undefined' && keyToWt[key]) ? keyToWt[key] : key;

      try {
        // 1. Delete DB start sheet and allocations
        const fd1 = new FormData();
        fd1.append('events', JSON.stringify([dbKey]));
        await fetch('actions/delete_start_sheet.php', { method: 'POST', body: fd1 });

        // 2. Clear manual inline edits in DB
        const fd2 = new FormData();
        fd2.append('events', JSON.stringify([dbKey]));
        await fetch('actions/clear_manual_edits.php', { method: 'POST', body: fd2 });

        // 3. Clear active schedule in relay_schedules table
        const fd3 = new FormData();
        fd3.append('action', 'clear');
        fd3.append('event_name', wtName);
        await fetch('actions/relay_schedule_action.php', { method: 'POST', body: fd3 });
      } catch (e) {
        console.error('Failed to clear database schedule/allocations:', e);
      }

      // Completely remove localStorage layout cache for this event key
      var storageKey = getStorageKey(key);
      localStorage.removeItem(storageKey);

      // Reset hasDbAllocs flag
      window['hasDbAllocs_' + key] = false;

      showMsg(document.getElementById('msgBox'), 'success', 'Layout reset successfully.');
      await silentRefreshPage();
    };

    // ─── Delete row ────────────────────────────────────────────
    window.openDeleteRowModal = function (key) {
      var data = loadData(key);
      if (!data.eventsList || data.eventsList.length === 0) { alert('No rows to delete.'); return; }

      var sel = document.getElementById('del_row_club');
      sel.innerHTML = '';
      data.eventsList.forEach(function (club) {
        var opt = document.createElement('option');
        opt.value = club;
        opt.textContent = club;
        sel.appendChild(opt);
      });

      document.getElementById('del_row_key').value = key;
      openModal('deleteRowModal');
    };

    window.applyDeleteRow = function () {
      var key = document.getElementById('del_row_key').value;
      var clubToRemove = document.getElementById('del_row_club').value;
      if (!clubToRemove) return;

      var clubToRemoveLower = clubToRemove.toLowerCase();
      // Load raw saved data WITHOUT going through loadData() reconstruct,
      // because loadData() would re-add DB clubs from fresh.eventsList.
      var storageKey = getStorageKey(key);
      var saved = localStorage.getItem(storageKey);
      var data;
      try { data = saved ? JSON.parse(saved) : {}; } catch (e) { data = {}; }
      if (!data.manualClubs) data.manualClubs = [];
      if (!data.deletedClubs) data.deletedClubs = [];
      if (!data.regCounts) data.regCounts = {};
      if (!data.allocs) data.allocs = {};
      if (!data.dates) data.dates = [];

      // Track this club as deleted so loadData() won't re-add it from fresh DB list
      if (data.deletedClubs.map(function (c) { return c.toLowerCase(); }).indexOf(clubToRemoveLower) === -1) {
        data.deletedClubs.push(clubToRemoveLower);
      }

      // Remove from manualClubs (case-insensitive)
      data.manualClubs = data.manualClubs.filter(function (c) {
        return c.toLowerCase() !== clubToRemoveLower;
      });

      // Remove from regCounts (try both original and lowercase key)
      delete data.regCounts[clubToRemove];
      delete data.regCounts[clubToRemoveLower];

      // Remove from allocs (alloc keys are normalized to lowercase)
      delete data.allocs[clubToRemoveLower];
      delete data.allocs[clubToRemove]; // fallback for any old non-normalized keys

      // Save raw (loadData will reconstruct eventsList correctly, excluding deletedClubs)
      localStorage.setItem(storageKey, JSON.stringify(data));
      renderEditableTable(key);
      // Capture full table state (allocs, dates) and trigger auto-sync for existing start sheets
      if (window.saveTableState) window.saveTableState(key);
      closeModal('deleteRowModal');
    };

    // ─── Delete date ───────────────────────────────────────────
    window.openDeleteDateModal = function (key) {
      var data = loadData(key);
      if (!data.dates || data.dates.length === 0) { alert('No dates exist to delete.'); return; }

      var sel = document.getElementById('del_date_val');
      sel.innerHTML = '';
      data.dates.forEach(function (d) {
        var opt = document.createElement('option');
        opt.value = d.date;
        opt.textContent = d.date;
        sel.appendChild(opt);
      });

      document.getElementById('del_date_key').value = key;
      openModal('deleteDateModal');
    };

    window.applyDeleteDate = function () {
      var key = document.getElementById('del_date_key').value;
      var dateToRemove = document.getElementById('del_date_val').value;
      if (!dateToRemove) return;

      var data = loadData(key);
      data.dates = data.dates.filter(d => d.date !== dateToRemove);

      if (data.allocs) {
        for (var club in data.allocs) {
          if (data.allocs[club][dateToRemove]) {
            delete data.allocs[club][dateToRemove];
          }
        }
      }

      reindexRelays(data, key);
      saveData(key, data);
      renderEditableTable(key);
      closeModal('deleteDateModal');
    };

    // ─── Delete column (relay) ──────────────────────────────────
    window.openDeleteColumnModal = function (key) {
      var data = loadData(key);
      if (!data.dates || data.dates.length === 0) { alert('No dates exist.'); return; }

      var selDate = document.getElementById('del_col_date');
      selDate.innerHTML = '';
      data.dates.forEach(function (d) {
        var opt = document.createElement('option');
        opt.value = d.date;
        opt.textContent = d.date;
        selDate.appendChild(opt);
      });

      document.getElementById('del_col_key').value = key;
      populateRelaysForDeleteColumn();
      openModal('deleteColumnModal');
    };

    window.populateRelaysForDeleteColumn = function () {
      var key = document.getElementById('del_col_key').value;
      var dateStr = document.getElementById('del_col_date').value;
      var selRelay = document.getElementById('del_col_relay');
      selRelay.innerHTML = '';

      if (!dateStr) return;

      var data = loadData(key);
      var dateObj = data.dates.find(d => d.date === dateStr);
      if (dateObj && dateObj.relays) {
        dateObj.relays.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.r;
          opt.textContent = 'R-' + r.r + ' (' + r.st + ')';
          selRelay.appendChild(opt);
        });
      }
    };

    window.applyDeleteColumn = function () {
      var key = document.getElementById('del_col_key').value;
      var dateStr = document.getElementById('del_col_date').value;
      var relayNumStr = document.getElementById('del_col_relay').value;
      if (!dateStr || !relayNumStr) { alert('Please select a date and relay column to delete.'); return; }

      var relayNum = parseInt(relayNumStr);
      var data = loadData(key);

      var dateObj = data.dates.find(d => d.date === dateStr);
      if (dateObj) {
        dateObj.relays = dateObj.relays.filter(r => r.r !== relayNum);
        // If no relays are left for this date, delete the date entirely
        if (dateObj.relays.length === 0) {
          data.dates = data.dates.filter(d => d.date !== dateStr);
        }
      }

      // Remove allocations for this relay on this date
      if (data.allocs) {
        for (var club in data.allocs) {
          if (data.allocs[club][dateStr] && data.allocs[club][dateStr][relayNum] !== undefined) {
            delete data.allocs[club][dateStr][relayNum];
          }
        }
      }

      reindexRelays(data, key);
      saveData(key, data);
      renderEditableTable(key);
      closeModal('deleteColumnModal');
    };

    // ─── Add date ──────────────────────────────────────────────
    window.openAddDateModal = function (key) {
      var data = loadData(key);
      var offset = (typeof data.reportingOffset === 'number' && data.reportingOffset >= 0) ? data.reportingOffset : 25;
      document.getElementById('add_date_key').value = key;
      document.getElementById('add_date_val').value = new Date().toISOString().split('T')[0];
      document.getElementById('add_date_relays').value = '2';
      document.getElementById('add_date_start').value = '09:00';
      document.getElementById('add_date_interval').value = '150';
      document.getElementById('add_date_reporting_offset').value = offset;
      openModal('addDateModal');
    };

    window.applyAddDate = function () {
      var key = document.getElementById('add_date_key').value;
      var dateStr = document.getElementById('add_date_val').value;
      var numRelays = parseInt(document.getElementById('add_date_relays').value) || 2;
      var firstStart = document.getElementById('add_date_start').value || '09:00';
      var interval = parseInt(document.getElementById('add_date_interval').value) || 150;
      var offsetVal = parseInt(document.getElementById('add_date_reporting_offset').value);
      var offset = (!isNaN(offsetVal) && offsetVal >= 0) ? offsetVal : 25;

      if (!dateStr) { alert('Please select a date.'); return; }

      var parts = dateStr.split('-');
      var formattedDate = parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : dateStr;

      var data = loadData(key);
      data.reportingOffset = offset;

      var highestR = 0;
      data.dates.forEach(function (d) {
        d.relays.forEach(function (r) {
          if (r.r > highestR) highestR = r.r;
        });
      });

      var relays = [];
      for (var i = 0; i < numRelays; i++) {
        var totalMins = timeToMin(firstStart) + i * interval;
        var st = minToTime(totalMins);
        var rep = minToTime(totalMins - offset);
        relays.push({ r: highestR + i + 1, rep: rep, st: st });
      }

      data.dates.push({ date: formattedDate, relays: relays });
      reindexRelays(data, key);
      saveData(key, data);
      renderEditableTable(key);
      closeModal('addDateModal');
    };

    // ─── Random Allocate ───────────────────────────────────────
    window.randomAllocate = function (key) {
      var data = loadData(key);
      if (!data.dates || data.dates.length === 0) {
        alert('Cannot allocate without dates.');
        return;
      }

      var allRelays = [];
      data.dates.forEach(function (d) {
        d.relays.forEach(function (r) {
          allRelays.push({ date: d.date, r: r.r });
        });
      });

      if (allRelays.length === 0) {
        alert('No relays available for allocation.');
        return;
      }

      if (!data.allocs) data.allocs = {};

      data.eventsList.forEach(function (club) {
        var total = parseInt(data.regCounts[club]) || 0;
        if (total > 0) {
          data.allocs[club] = {};
          while (total > 0) {
            var randIdx = Math.floor(Math.random() * allRelays.length);
            var chosen = allRelays[randIdx];

            if (!data.allocs[club][chosen.date]) {
              data.allocs[club][chosen.date] = {};
            }
            if (!data.allocs[club][chosen.date][chosen.r]) {
              data.allocs[club][chosen.date][chosen.r] = 0;
            }
            data.allocs[club][chosen.date][chosen.r]++;
            total--;
          }
        }
      });

      saveData(key, data);
      persistMatrixToDb(key);
      renderEditableTable(key);
    };

    function timeToMin(t) {
      if (!t || !t.includes(':')) return 0;
      var p = t.split(':');
      return parseInt(p[0]) * 60 + parseInt(p[1]);
    }

    function minToTime(m) {
      while (m < 0) m += 1440;
      while (m >= 1440) m -= 1440;
      var hrs = Math.floor(m / 60);
      var mins = m % 60;
      return String(hrs).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
    }

    window.setRelayCount = function (key) {
      var data = loadData(key);
      if (!data.dates || data.dates.length === 0) {
        alert('No dates exist yet. Add a date first.');
        return;
      }

      document.getElementById('sr_key').value = key;
      document.getElementById('sr_apply_type').value = 'all';
      document.getElementById('sr_date_group').style.display = 'none';

      var dateSelect = document.getElementById('sr_date');
      dateSelect.innerHTML = '';
      data.dates.forEach(function (d) {
        var opt = document.createElement('option');
        opt.value = d.date;
        opt.textContent = d.date;
        dateSelect.appendChild(opt);
      });

      openModal('setRelayModal');
    };

    window.toggleSrDate = function () {
      var type = document.getElementById('sr_apply_type').value;
      document.getElementById('sr_date_group').style.display = (type === 'single') ? 'block' : 'none';
    };

    window.applySetRelay = function () {
      var key = document.getElementById('sr_key').value;
      var applyType = document.getElementById('sr_apply_type').value;
      var count = parseInt(document.getElementById('sr_count').value);
      var targetDate = document.getElementById('sr_date').value;

      if (isNaN(count) || count < 1) { alert('Enter a valid number.'); return; }

      var data = loadData(key);
      var defaultStart = '09:00';
      var defaultLimit = window['dbCapacity_' + key] || 40;

      var interval = 90;
      if (key) {
        const isIssf = key.toLowerCase().includes('issf');
        interval = isIssf ? 120 : 90;
      }

      if (applyType === 'all') {
        var currentRelayNum = 1;
        data.dates.forEach(function (dateObj) {
          dateObj.relays = [];
          for (var i = 0; i < count; i++) {
            var totalMins = timeToMin(defaultStart) + i * interval;
            var st = minToTime(totalMins);
            var rep = minToTime(totalMins - 25);
            dateObj.relays.push({ r: currentRelayNum, rep: rep, st: st, limit: defaultLimit });
            currentRelayNum++;
          }
        });
        data.allocs = {};
      } else {
        var tempId = 10000;
        data.dates.forEach(function (dateObj) {
          if (dateObj.date === targetDate) {
            dateObj.relays = [];
            for (var i = 0; i < count; i++) {
              var totalMins = timeToMin(defaultStart) + i * interval;
              var st = minToTime(totalMins);
              var rep = minToTime(totalMins - 25);
              dateObj.relays.push({ r: tempId++, rep: rep, st: st, limit: defaultLimit });
            }
          }
        });
        reindexRelays(data, key);
      }

      saveData(key, data);
      renderEditableTable(key);
      closeModal('setRelayModal');
    };

    // ─── Reset table (Removed) ─────────────────────────────────

    // ─── Initialize all tables ────────────────────────────────
    function initTables() {
      activeWeaponKeys.forEach(function (key) {
        renderEditableTable(key);
      });
      setupEditableListeners();
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initTables);
    } else {
      initTables();
    }

    window.recalcWizardRelays = function () {
      const startVal = document.getElementById('wizard_start_time').value;
      const endVal = document.getElementById('wizard_end_time').value;
      const relayVal = parseInt(document.getElementById('wizard_relay_time').value) || 0;
      const breakVal = parseInt(document.getElementById('wizard_break').value) || 0;

      if (!startVal || !endVal || relayVal <= 0) return;

      const startMins = timeToMin(startVal);
      const endMins = timeToMin(endVal);

      let count = 0;
      let interval = relayVal + breakVal;

      if (interval <= 0) return;

      for (let i = 0; ; i++) {
        let rStart = startMins + i * interval;
        let rEnd = rStart + relayVal;
        if (rEnd <= endMins) {
          count++;
        } else {
          break;
        }
      }

      document.getElementById('wizard_relay_per_day').value = count;
    };

    window.openRelayWizardModal = function () {
      const selBaseType = '<?= htmlspecialchars($selBaseType) ?>';
      const key = selBaseType.replace(/[^a-zA-Z0-9]/g, '');
      const eventObj = baseEventsList.find(item => item.key === key);
      const label = eventObj ? eventObj.label : selBaseType;

      document.getElementById('wizard_event_key').value = key;
      document.getElementById('wizard_event_name').value = label;

      // Default dates
      document.getElementById('wizard_from_date').value = new Date().toISOString().split('T')[0];
      document.getElementById('wizard_to_date').value = new Date().toISOString().split('T')[0];
      document.getElementById('wizard_start_time').value = '09:00';
      document.getElementById('wizard_end_time').value = '17:00';

      const isIssf = key.toLowerCase().includes('issf');
      document.getElementById('wizard_relay_time').value = isIssf ? '105' : '75';
      document.getElementById('wizard_break').value = '15';

      const defaultLimit = window['dbCapacity_' + key] || 40;
      document.getElementById('wizard_limit_per_relay').value = defaultLimit;

      const evtData = loadData(key);
      const savedOffset = (typeof evtData.reportingOffset === 'number' && evtData.reportingOffset >= 0) ? evtData.reportingOffset : 25;
      document.getElementById('wizard_reporting_offset').value = savedOffset;

      recalcWizardRelays();
      openModal('relayWizardModal');
    };

    window.applyRelayWizard = function (e) {
      e.preventDefault();
      const key = document.getElementById('wizard_event_key').value;
      const fromDateStr = document.getElementById('wizard_from_date').value;
      const toDateStr = document.getElementById('wizard_to_date').value;
      const relaysPerDay = parseInt(document.getElementById('wizard_relay_per_day').value) || 1;
      const startTimeStr = document.getElementById('wizard_start_time').value;
      const endTimeStr = document.getElementById('wizard_end_time').value;
      const breakMins = parseInt(document.getElementById('wizard_break').value) || 0;
      const relayTime = parseInt(document.getElementById('wizard_relay_time').value) || 90;
      const limitPerRelay = parseInt(document.getElementById('wizard_limit_per_relay').value) || 40;
      const offsetVal = parseInt(document.getElementById('wizard_reporting_offset').value);
      const reportingOffset = (!isNaN(offsetVal) && offsetVal >= 0) ? offsetVal : 25;

      const defaultLimit = window['dbCapacity_' + key] || 40;
      if (isNaN(limitPerRelay) || limitPerRelay <= 0) {
        alert('Please enter a valid limit.');
        return;
      }
      if (limitPerRelay > defaultLimit) {
        alert('Limit per relay cannot exceed the maximum capacity of ' + defaultLimit + ' for this range.');
        return;
      }

      if (!fromDateStr || !toDateStr) {
        alert('Please select both From and To dates.');
        return;
      }

      const fromDate = new Date(fromDateStr);
      const toDate = new Date(toDateStr);

      if (toDate < fromDate) {
        alert('To Date cannot be earlier than From Date.');
        return;
      }

      const startMins = timeToMin(startTimeStr);
      const interval = relayTime + breakMins;

      // Generate dates list
      const datesList = [];
      let currentDate = new Date(fromDate);

      while (currentDate <= toDate) {
        const day = String(currentDate.getDate()).padStart(2, '0');
        const month = String(currentDate.getMonth() + 1).padStart(2, '0');
        const year = currentDate.getFullYear();
        const formattedDate = `${day}-${month}-${year}`;

        datesList.push(formattedDate);
        currentDate.setDate(currentDate.getDate() + 1);
      }

      // Load event data
      const data = loadData(key);
      data.dates = [];
      data.reportingOffset = reportingOffset;

      let tempRelayNum = 1;
      datesList.forEach(dStr => {
        const relays = [];
        for (let i = 0; i < relaysPerDay; i++) {
          const rStartMins = startMins + i * interval;
          const st = minToTime(rStartMins);
          const rep = minToTime(rStartMins - reportingOffset);
          relays.push({ r: tempRelayNum, rep: rep, st: st, limit: limitPerRelay });
          tempRelayNum++;
        }
        data.dates.push({ date: dStr, relays: relays });
      });

      // Consecutively reindex relays
      reindexRelays(data, key);

      // Save and render
      saveData(key, data);
      renderEditableTable(key);
      closeModal('relayWizardModal');
    };

  })();
</script>

<?php require_once 'includes/footer.php'; ?>
<?php exit; ?>


var data = loadData(key);
var defaultStart = '09:00';
var defaultLimit = window['dbCapacity_' + key] || 40;

var interval = 90;
if (key) {
const isIssf = key.toLowerCase().includes('issf');
interval = isIssf ? 120 : 90;
}

if (applyType === 'all') {
var currentRelayNum = 1;
data.dates.forEach(function(dateObj) {
dateObj.relays = [];
for (var i = 0; i < count; i++) { var totalMins=timeToMin(defaultStart) + i * interval; var st=minToTime(totalMins); var
  rep=minToTime(totalMins - 25); dateObj.relays.push({ r: currentRelayNum, rep: rep, st: st, limit: defaultLimit });
  currentRelayNum++; } }); data.allocs={}; } else { var tempId=10000; data.dates.forEach(function(dateObj) { if
  (dateObj.date===targetDate) { dateObj.relays=[]; for (var i=0; i < count; i++) { var totalMins=timeToMin(defaultStart)
  + i * interval; var st=minToTime(totalMins); var rep=minToTime(totalMins - 25); dateObj.relays.push({ r: tempId++,
  rep: rep, st: st, limit: defaultLimit }); } } }); reindexRelays(data, key); } saveData(key, data);
  renderEditableTable(key); closeModal('setRelayModal'); }; // ─── Reset table (Removed)
  ───────────────────────────────── // ─── Initialize all tables ──────────────────────────────── function initTables()
  { activeWeaponKeys.forEach(function(key) { renderEditableTable(key); }); setupEditableListeners(); } // Run on DOM
  ready if (document.readyState==='loading' ) { document.addEventListener('DOMContentLoaded', initTables); } else { initTables();
  }

  window.recalcWizardRelays = function() {
    const startVal = document.getElementById('wizard_start_time').value;
    const endVal = document.getElementById('wizard_end_time').value;
    const relayVal = parseInt(document.getElementById('wizard_relay_time').value) || 0;
    const breakVal = parseInt(document.getElementById('wizard_break').value) || 0;
    
    if (!startVal || !endVal || relayVal <= 0) return;
    
    const startMins = timeToMin(startVal);
    const endMins = timeToMin(endVal);
    
    let count = 0;
    let interval = relayVal + breakVal;
    
    if (interval <= 0) return;
    
    for (let i = 0; ; i++) {
      let rStart = startMins + i * interval;
      let rEnd = rStart + relayVal;
      if (rEnd <= endMins) {
        count++;
      } else {
        break;
      }
    }
    
    document.getElementById('wizard_relay_per_day').value = count;
  };

  window.openRelayWizardModal = function() {
      const selBaseType = '<?= htmlspecialchars($selBaseType) ?>';
      const key = selBaseType.replace(/[^a-zA-Z0-9]/g, '');
      const eventObj = baseEventsList.find(item => item.key === key);
      const label = eventObj ? eventObj.label : selBaseType;
      
      document.getElementById('wizard_event_key').value = key;
      document.getElementById('wizard_event_name').value = label;
      
      // Default dates
      document.getElementById('wizard_from_date').value = new Date().toISOString().split('T')[0];
      document.getElementById('wizard_to_date').value = new Date().toISOString().split('T')[0];
      document.getElementById('wizard_start_time').value = '09:00';
      document.getElementById('wizard_end_time').value = '17:00';
      
      const isIssf = key.toLowerCase().includes('issf');
      document.getElementById('wizard_relay_time').value = isIssf ? '105' : '75';
      document.getElementById('wizard_break').value = '15';
      
      const defaultLimit = window['dbCapacity_' + key] || 40;
      document.getElementById('wizard_limit_per_relay').value = defaultLimit;
      
      const evtData = loadData(key);
      const savedOffset = (typeof evtData.reportingOffset === 'number' && evtData.reportingOffset >= 0) ? evtData.reportingOffset : 25;
      document.getElementById('wizard_reporting_offset').value = savedOffset;
      
      recalcWizardRelays();
      openModal('relayWizardModal');
  };

  window.applyRelayWizard = function(e) {
      e.preventDefault();
      const key = document.getElementById('wizard_event_key').value;
      const fromDateStr = document.getElementById('wizard_from_date').value;
      const toDateStr = document.getElementById('wizard_to_date').value;
      const relaysPerDay = parseInt(document.getElementById('wizard_relay_per_day').value) || 1;
      const startTimeStr = document.getElementById('wizard_start_time').value;
      const endTimeStr = document.getElementById('wizard_end_time').value;
      const breakMins = parseInt(document.getElementById('wizard_break').value) || 0;
      const relayTime = parseInt(document.getElementById('wizard_relay_time').value) || 90;
      const limitPerRelay = parseInt(document.getElementById('wizard_limit_per_relay').value) || 40;
      const offsetVal = parseInt(document.getElementById('wizard_reporting_offset').value);
      const reportingOffset = (!isNaN(offsetVal) && offsetVal >= 0) ? offsetVal : 25;
      
      const defaultLimit = window['dbCapacity_' + key] || 40;
      if (isNaN(limitPerRelay) || limitPerRelay <= 0) {
          alert('Please enter a valid limit.');
          return;
      }
      if (limitPerRelay > defaultLimit) {
          alert('Limit per relay cannot exceed the maximum capacity of ' + defaultLimit + ' for this range.');
          return;
      }
      
      if (!fromDateStr || !toDateStr) {
          alert('Please select both From and To dates.');
          return;
      }
      
      const fromDate = new Date(fromDateStr);
      const toDate = new Date(toDateStr);
      
      if (toDate < fromDate) {
          alert('To Date cannot be earlier than From Date.');
          return;
      }
      
      const startMins = timeToMin(startTimeStr);
      const interval = relayTime + breakMins;
      
      // Generate dates list
      const datesList = [];
      let currentDate = new Date(fromDate);
      
      while (currentDate <= toDate) {
          const day = String(currentDate.getDate()).padStart(2, '0');
          const month = String(currentDate.getMonth() + 1).padStart(2, '0');
          const year = currentDate.getFullYear();
          const formattedDate = `${day}-${month}-${year}`;
          
          datesList.push(formattedDate);
          currentDate.setDate(currentDate.getDate() + 1);
      }
      
      // Load event data
      const data = loadData(key);
      data.dates = [];
      data.reportingOffset = reportingOffset;
      
      let tempRelayNum = 1;
      datesList.forEach(dStr => {
          const relays = [];
          for (let i = 0; i < relaysPerDay; i++) {
              const rStartMins = startMins + i * interval;
              const st = minToTime(rStartMins);
              const rep = minToTime(rStartMins - reportingOffset);
              relays.push({ r: tempRelayNum, rep: rep, st: st, limit: limitPerRelay });
              tempRelayNum++;
          }
          data.dates.push({ date: dStr, relays: relays });
      });
      
      // Consecutively reindex relays
      reindexRelays(data, key);
      
      // Save and render
      saveData(key, data);
      renderEditableTable(key);
      closeModal('relayWizardModal');
  };

})();
</script>

<?php require_once 'includes/footer.php'; ?>

