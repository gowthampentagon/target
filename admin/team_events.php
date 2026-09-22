<?php
// admin/team_events.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
$pageTitle = 'Team Events Management';
require_once 'includes/header.php';

function getScoreSheetUrl(int $allocId, string $evtName): string {
    $evt = str_replace('_', ' ', strtolower($evtName));
    
    // 1. Standard Pistol
    if (strpos($evt, 'standard pistol') !== false || strpos($evt, '25m standard') !== false) {
        return '../Score Sheet/standard-pistol.php?alloc_id=' . $allocId;
    }
    
    // 2. Centre Fire / Sport Pistol / 25M Pistol
    if (strpos($evt, 'centre fire') !== false || 
        strpos($evt, 'center fire') !== false || 
        strpos($evt, 'sports pistol') !== false || 
        strpos($evt, 'sport pistol') !== false || 
        strpos($evt, '25m pistol') !== false) {
        return '../Score Sheet/centre-fire-pistol.php?alloc_id=' . $allocId;
    }
    
    // 3. Rifle Prone
    if (strpos($evt, 'prone') !== false) {
        return '../Score Sheet/rifle-prone.php?alloc_id=' . $allocId;
    }
    
    // 4. Default: Air Rifle / Air Pistol / 10M / 50M Free Pistol
    return '../Score Sheet/air-rifle-pistol.php?alloc_id=' . $allocId;
}

function getLiveMemberData(PDO $pdo, string $enrollId, string $eventName, string $category = ''): array {
    return resolveTeamMemberScore($pdo, $enrollId, $eventName, $category);
}

try {
    $pdo = getDB();
    require_once dirname(__DIR__) . '/config/events.php';

    // Fetch all registered participants for autocomplete suggestions in the table
    $allRegs = $pdo->query("SELECT reg_id, first_name, last_name, club_name FROM registrations WHERE reg_id IS NOT NULL AND reg_id != '' ORDER BY reg_id")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all unique events from event_registrations and teams
    $eventsQuery = "
        SELECT DISTINCT er.event_name, er.category
        FROM event_registrations er
        WHERE er.event_name IS NOT NULL AND er.event_name != ''
        UNION
        SELECT DISTINCT t.event_name, t.category
        FROM teams t
        WHERE t.event_name IS NOT NULL AND t.event_name != ''
    ";
    $registeredEvents = $pdo->query($eventsQuery)->fetchAll(PDO::FETCH_ASSOC);

    $registeredEventsDropdown = [];
    foreach ($registeredEvents as $ev) {
        $evt = $ev['event_name'];
        if (empty($evt)) continue;
        $cat = $ev['category'] ?? '';
        $fullName = $EVENTS_MAPPING[$evt] ?? $evt;
        $label = ($fullName !== $evt) ? "{$evt} - {$fullName}" : $fullName;
        if (!empty($cat) && strpos(strtoupper($label), strtoupper($cat)) === false) {
            $label .= " ({$cat})";
        }
        $registeredEventsDropdown[$evt] = [
            'label' => $label,
            'category' => $cat
        ];
    }

    $selEvent = trim($_GET['event_select'] ?? $_GET['event_name'] ?? $_GET['base_type'] ?? '');
    $selCategory = trim($_GET['category'] ?? '');
    if (!empty($selEvent) && empty($selCategory) && isset($registeredEventsDropdown[$selEvent])) {
        $selCategory = $registeredEventsDropdown[$selEvent]['category'];
    }

    $participants = [];
    $existingTeams = [];
    $allTeamsData = [];

    if ($selEvent !== '') {
        $partParams = [$selEvent];
        $partCatQuery = "";
        if (!empty($selCategory)) {
            $partCatQuery = " AND er.category = ? ";
            $partParams[] = $selCategory;
        }

        // Fetch eligible participants for the dropdowns
        $partStmt = $pdo->prepare("
            SELECT r.reg_id AS enrollment_id,
                   TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS shooter_name,
                   r.club_name
            FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            WHERE er.event_name = ? $partCatQuery
              AND er.status = 'approved'
              AND r.reg_id IS NOT NULL
              AND r.reg_id != ''
            ORDER BY r.reg_id ASC
        ");
        $partStmt->execute($partParams);
        $participants = $partStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($participants as &$p) {
            $p['bib_no'] = formatBibNo($p['enrollment_id'], '', $p['shooter_name']);
            $scoreRes = resolveTeamMemberScore($pdo, $p['enrollment_id'], $selEvent, $selCategory, null, $p['shooter_name']);
            $p['score'] = $scoreRes['score'];
        }
        unset($p);

        // Fetch existing teams
        $teamParams = [$selEvent];
        $teamCatQuery = "";
        if (!empty($selCategory)) {
            $teamCatQuery = " AND t.category = ? ";
            $teamParams[] = $selCategory;
        }

        $teamStmt = $pdo->prepare("
            SELECT t.id AS team_id, t.team_name, t.total_score,
                   tm.id AS member_id, tm.enrollment_id, tm.shooter_name, tm.club_name, tm.score AS stored_score
            FROM teams t
            JOIN team_members tm ON t.id = tm.team_id
            WHERE t.event_name = ? $teamCatQuery
            ORDER BY t.created_at ASC, t.id ASC, tm.id ASC
        ");
        $teamStmt->execute($teamParams);
        $dbTeams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group team members by team and resolve live allocations and scores
        foreach ($dbTeams as $row) {
            $tId = (int)$row['team_id'];
            if (!isset($existingTeams[$tId])) {
                $existingTeams[$tId] = [
                    'team_name'   => $row['team_name'],
                    'total_score' => 0.0,
                    'members'     => []
                ];
            }
            
            $liveData = getLiveMemberData($pdo, $row['enrollment_id'], $selEvent, $selCategory);
            $liveScore = $liveData['score'] > 0 ? $liveData['score'] : (float)$row['stored_score'];
            
            $existingTeams[$tId]['members'][] = [
                'member_id'    => (int)$row['member_id'],
                'enrollment_id'=> $row['enrollment_id'],
                'bib_no'       => formatBibNo($row['enrollment_id'], '', $row['shooter_name']),
                'shooter_name' => $row['shooter_name'],
                'club_name'    => $row['club_name'],
                'score'        => $liveScore,
                'lane_alloc_id'=> $liveData['lane_alloc_id']
            ];
            $existingTeams[$tId]['total_score'] += $liveScore;
        }

        // Fetch already assigned members for validation
        $assignedQuery = $pdo->prepare("
            SELECT tm.enrollment_id, t.id AS team_id
            FROM team_members tm
            JOIN teams t ON tm.team_id = t.id
            WHERE t.event_name = ? AND t.category = ?
        ");
        $assignedQuery->execute([$selEvent, $selCategory]);
        $assignedMembers = $assignedQuery->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        // Query all configured teams across all events
        $stmt = $pdo->prepare("
            SELECT t.id AS team_id, t.team_name, t.event_name, t.category
            FROM teams t
            ORDER BY t.event_name ASC, t.category ASC, t.id ASC
        ");
        $stmt->execute();
        $allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($allTeams as $t) {
            $tid = (int)$t['team_id'];
            // Fetch members
            $mStmt = $pdo->prepare("
                SELECT tm.*, r.club_name 
                FROM team_members tm
                JOIN registrations r ON tm.enrollment_id = r.reg_id
                WHERE tm.team_id = ?
                ORDER BY tm.id ASC
            ");
            $mStmt->execute([$tid]);
            $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Resolve live score
            foreach ($members as &$m) {
                $live = getLiveMemberData($pdo, $m['enrollment_id'], $t['event_name'], $t['category'] ?? '');
                $m['lane_alloc_id'] = $live['lane_alloc_id'];
                $m['score'] = $live['score'];
            }
            unset($m);
            
            $t['members'] = $members;
            $allTeamsData[] = $t;
        }
    }
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>

<div class="admin-page-header">
  <div class="admin-page-title">Team Events Management</div>
</div>

<div class="meta-card" style="margin-bottom: 24px; padding: 20px; position: relative; z-index: 1000; overflow: visible !important;">
  <form method="GET" id="event-select-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
    <div class="field" style="flex: 1; min-width: 250px; position: relative; z-index: 1001;">
      <label for="event_select" style="font-weight: 600; font-size: 14px; color: var(--gold-500); margin-bottom: 6px;">Select Shooting Event</label>
      <select id="event_select" name="event_select" class="searchable-select" onchange="const opt = this.options[this.selectedIndex]; document.getElementById('event_category_hidden').value = opt.getAttribute('data-cat') || ''; this.form.submit()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 14px;">
        <option value="">— All Events —</option>
        <?php foreach ($registeredEventsDropdown as $optVal => $optData): 
            $selected = ($selEvent === (string)$optVal) ? 'selected' : '';
            $optLabel = is_array($optData) ? $optData['label'] : $optData;
            $optCat   = is_array($optData) ? $optData['category'] : '';
        ?>
          <option value="<?= htmlspecialchars((string)$optVal) ?>" data-cat="<?= htmlspecialchars($optCat) ?>" <?= $selected ?>>
            <?= htmlspecialchars($optLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="category" id="event_category_hidden" value="<?= htmlspecialchars($selCategory) ?>">
    </div>
  </form>
</div>

<?php if ($selEvent !== ''): ?>
  <div style="display: grid; grid-template-columns: 1fr; gap: 24px; margin-bottom: 30px;">
    
    <!-- TEAM FORMATION CARD -->
    <div class="meta-card" style="padding: 24px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px;">
      <h3 style="font-family: 'Rajdhani', sans-serif; font-size: 20px; font-weight: 700; color: var(--gold-500); margin-bottom: 20px; text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 8px;">
        Form New Team
      </h3>
      
      <form id="team-form">
        <input type="hidden" name="event_name" value="<?= htmlspecialchars($selEvent) ?>">
        <input type="hidden" name="category" value="<?= htmlspecialchars($selCategory) ?>">
        <input type="hidden" name="team_id" id="team_id" value="">
        
        <div class="field" style="margin-bottom: 20px; max-width: 400px;">
          <label for="team_name" style="font-weight: 600; font-size: 14px; margin-bottom: 6px; display: block;">Team Name</label>
          <input type="text" id="team_name" name="team_name" placeholder="e.g. Saragarhi Team Alpha" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.15); color:#fff; border-radius:4px; font-size:14px;">
        </div>

        <div class="field" style="margin-bottom: 20px; max-width: 400px;">
          <label for="team_club_filter" style="font-weight: 600; font-size: 14px; margin-bottom: 6px; display: block;">Club Filter (Filter Shooter List)</label>
          <select id="team_club_filter" class="searchable-select" style="width: 100%; padding: 10px; background: #1a2234; border: 1px solid rgba(255,255,255,0.15); color:#fff; border-radius:4px; font-size:14px; outline:none; font-family: inherit;">
            <option value="">— All Clubs —</option>
            <?php
            $clubNames = [];
            foreach ($participants as $p) {
                if (!empty($p['club_name'])) {
                    $clubNames[] = trim($p['club_name']);
                }
            }
            $clubNames = array_unique($clubNames);
            sort($clubNames);
            foreach ($clubNames as $cName):
            ?>
              <option value="<?= htmlspecialchars($cName) ?>"><?= htmlspecialchars($cName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="table-wrap" style="margin-bottom: 20px;">
          <table class="admin-table">
            <thead>
              <tr>
                <th style="width: 250px;">Member Slot</th>
                <th style="width: 200px;">Competitor Number</th>
                <th class="text-left">Club Name</th>
                <th class="text-left">Shooter Name</th>
                <th style="width: 100px; text-align: center;">Score</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($i = 1; $i <= 3; $i++): ?>
                <tr>
                  <td style="font-weight: 600; color: var(--gold-500); text-align: center;">Member <?= $i ?></td>
                  <td style="padding: 6px;">
                    <input type="text" name="member_<?= $i ?>" id="member_<?= $i ?>_select" class="member-input member-select" list="member-list" placeholder="Type or Select ID" required autocomplete="off" style="width: 100%; padding: 8px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #fff; border-radius: 4px; font-family: inherit; font-size: 13px; box-sizing: border-box; outline: none;">
                  </td>
                  <td id="member_<?= $i ?>_club" style="text-align: left; color: var(--text-muted);">—</td>
                  <td id="member_<?= $i ?>_name" style="text-align: left; font-weight: 500;">—</td>
                  <td id="member_<?= $i ?>_score" style="text-align: center; font-weight: 700; color: var(--gold-500);">—</td>
                </tr>
              <?php endfor; ?>
              <tr style="background: rgba(255, 255, 255, 0.08); font-weight: 700;">
                <td colspan="4" style="text-align: right; text-transform: uppercase; letter-spacing: 0.5px; font-size: 13px; color: #fff; padding: 12px;">Team Total Score:</td>
                <td id="team-total-display" style="text-align: center; color: var(--gold-500); font-size: 16px; padding: 12px;">0.00</td>
              </tr>
            </tbody>
          </table>
        </div>

        <button type="submit" class="btn btn-save" style="background:#27ae60; color:#fff; border:none; padding:10px 24px; font-weight:600; cursor:pointer; border-radius:4px; font-size:14px;">
          💾 Form &amp; Save Team
        </button>
        <button type="button" id="btn-cancel-edit" class="btn btn-outline" style="display: none; padding:10px 24px; font-size:14px; margin-left: 10px;" onclick="cancelTeamEdit()">
          Cancel Edit
        </button>
      </form>
    </div>

    <!-- CURRENT TEAMS CARD -->
    <div class="meta-card" style="padding: 24px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px;">
      <h3 style="font-family: 'Rajdhani', sans-serif; font-size: 20px; font-weight: 700; color: var(--gold-500); margin-bottom: 20px; text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 8px;">
        Current Teams
      </h3>
      
      <?php if (empty($existingTeams)): ?>
        <p style="color: var(--text-muted); font-style: italic;">No teams have been formed for this event yet.</p>
      <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 20px;">
          <?php foreach ($existingTeams as $tId => $tData): ?>
            <div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); border-radius: 6px; padding: 16px;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                <h4 contenteditable="true" class="editable-team-title" data-team-id="<?= $tId ?>" style="font-family: 'Rajdhani', sans-serif; font-size: 16px; font-weight: 700; color: #fff; margin: 0; text-transform: uppercase; outline: none; border-bottom: 1px dashed rgba(255,255,255,0.2); padding-bottom: 2px;">
                  <?= htmlspecialchars($tData['team_name']) ?>
                </h4>
                <div style="display: flex; gap: 10px; align-items: center;">
                  <span style="font-size: 13px; color: var(--text-muted); margin-right: 5px;">
                    Team Score: <strong id="team-total-score-<?= $tId ?>" style="color: var(--gold-500); font-size: 14px;"><?= number_format($tData['total_score'], 2) ?></strong>
                  </span>
                  <button type="button" class="btn btn-sm btn-danger" onclick="deleteTeam(<?= $tId ?>, '<?= htmlspecialchars(addslashes($tData['team_name'])) ?>')" style="padding: 4px 10px; font-size: 11px; background:#e74c3c; color:#fff; border:none; border-radius:4px; cursor:pointer;">
                    <i class="bi bi-trash-fill"></i> Delete
                  </button>
                </div>
              </div>
              <div class="table-wrap">
                <table class="admin-table" style="margin-bottom: 0;">
                  <thead>
                    <tr>
                      <th style="min-width: 210px;">Enrollment ID</th>
                      <th class="text-left">Shooter's Name</th>
                      <th class="text-left">Club Name</th>
                      <th style="width: 100px; text-align: center;">Score</th>
                      <th style="width: 120px; text-align: center;">Score Sheet</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($tData['members'] as $m): ?>
                      <tr>
                        <td style="padding: 0; position: relative;">
                          <input type="text" class="table-input editable-team-member-cell" list="bib-list" data-col="enrollment_id" data-member-id="<?= $m['member_id'] ?>" data-team-id="<?= $tId ?>" value="<?= htmlspecialchars($m['bib_no'] ?? formatBibNo($m['enrollment_id'], '', $m['shooter_name'])) ?>" autocomplete="off" style="width:100%; border:none; background:transparent; color:#fff; text-align:center; padding:10px; box-sizing:border-box; outline:none; font-family:inherit; font-size:inherit; font-weight: 600;">
                        </td>
                        <td contenteditable="true" class="editable-team-member-cell text-left" data-col="shooter_name" data-member-id="<?= $m['member_id'] ?>" data-team-id="<?= $tId ?>" style="font-weight: 500; outline: none; border-bottom: 1px dashed rgba(255,255,255,0.1);"><?= htmlspecialchars($m['shooter_name']) ?></td>
                        <td contenteditable="true" class="editable-team-member-cell text-left" data-col="club_name" data-member-id="<?= $m['member_id'] ?>" data-team-id="<?= $tId ?>" style="color: var(--text-muted); outline: none; border-bottom: 1px dashed rgba(255,255,255,0.1);"><?= htmlspecialchars($m['club_name']) ?></td>
                        <td contenteditable="true" class="editable-team-member-cell" data-col="score" data-member-id="<?= $m['member_id'] ?>" data-team-id="<?= $tId ?>" style="text-align: center; font-weight: 700; color: var(--gold-500); outline: none; border-bottom: 1px dashed rgba(255,255,255,0.1);"><?= $m['score'] > 0 ? number_format($m['score'], 2) : '—' ?></td>
                        <td style="text-align: center;" id="action-cell-<?= $m['member_id'] ?>">
                          <?php if (!empty($m['lane_alloc_id'])): ?>
                            <a href="<?= getScoreSheetUrl((int)$m['lane_alloc_id'], $selEvent) ?>" class="btn btn-sm" style="padding: 4px 10px; font-size: 11px; background: #2980b9; color: #fff; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: 600;"><i class="bi bi-eye-fill"></i> View Sheet</a>
                          <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 11px;">Not Allocated</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
<?php else: ?>
  <?php if (!empty($allTeamsData)): ?>
    <div style="background: rgba(30, 41, 59, 0.2); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 24px; margin-bottom: 30px;">
      <h3 style="font-family: 'Rajdhani', sans-serif; font-size: 22px; font-weight: 700; color: var(--gold-500); margin-bottom: 20px; text-transform: uppercase; border-bottom: 2px solid rgba(255,255,255,0.05); padding-bottom: 8px;">
        All Configured Teams
      </h3>
      
      <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
        <?php foreach ($allTeamsData as $team): 
          $tId = $team['team_id'];
          $eventLabel = formatBaseEventName($team['event_name']);
          
          // Compute total score from resolved member live scores
          $resolvedTotalScore = 0.0;
          foreach ($team['members'] as $m) {
              $resolvedTotalScore += (float)($m['score'] ?? 0.0);
          }
        ?>
          <div class="meta-card" style="padding: 20px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255,255,255,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 10px;">
              <div>
                <h4 style="font-size: 18px; font-weight: 700; color: #fff; margin: 0;"><?= htmlspecialchars($team['team_name']) ?></h4>
                <span style="font-size: 12px; color: var(--gold-400); font-weight: 600;"><?= htmlspecialchars($eventLabel) ?> &bull; <?= htmlspecialchars($team['category']) ?></span>
              </div>
              <div style="font-weight: 700; color: var(--gold-500); font-size: 16px;">
                Total Score: <?= number_format($resolvedTotalScore, 2) ?>
              </div>
            </div>
            
            <div style="overflow-x: auto;">
              <table class="admin-table" style="width: 100%;">
                <thead>
                  <tr>
                    <th>Competitor Number</th>
                    <th class="text-left">Shooter Name</th>
                    <th class="text-left">Club Name</th>
                    <th style="width: 100px; text-align: center;">Score</th>
                    <th style="width: 120px; text-align: center;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($team['members'] as $m): ?>
                    <tr>
                      <td style="font-weight: 700; font-family: monospace; color: #ffffff !important;"><?= htmlspecialchars(formatBibNo($m['enrollment_id'], '', $m['shooter_name'])) ?></td>
                      <td class="text-left" style="font-weight: 600;"><?= htmlspecialchars($m['shooter_name']) ?></td>
                      <td class="text-left" style="color: var(--text-muted);"><?= htmlspecialchars($m['club_name']) ?></td>
                      <td style="text-align: center; font-weight: 700; color: var(--gold-500);"><?= $m['score'] > 0 ? number_format($m['score'], 2) : '—' ?></td>
                      <td style="text-align: center;">
                        <?php if (!empty($m['lane_alloc_id'])): ?>
                          <a href="<?= getScoreSheetUrl((int)$m['lane_alloc_id'], $team['event_name']) ?>" class="btn btn-sm" style="padding: 4px 10px; font-size: 11px; background: #2980b9; color: #fff; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: 600;"><i class="bi bi-eye-fill"></i> View Sheet</a>
                        <?php else: ?>
                          <span style="color: var(--text-muted); font-size: 11px;">Not Allocated</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="meta-card" style="padding: 40px; text-align: center; color: var(--text-muted); border: 1px dashed rgba(255,255,255,0.15);">
      <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 12px; color: var(--gold-500); opacity: 0.7;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
      </svg>
      <p style="font-size: 16px; font-weight: 500; margin: 0;">No teams configured across any events yet.</p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
// Autocomplete data map and assignment tracking
const eligibleParticipants = <?= json_encode(array_column($participants, null, 'enrollment_id')) ?>;
const assignedMembers = <?= json_encode($assignedMembers ?? []) ?>;

document.addEventListener('DOMContentLoaded', () => {
    const eventSelect = document.getElementById('event_select');
    if (eventSelect) {
        eventSelect.addEventListener('change', () => {
            const val = eventSelect.value;
            if (val === '') {
                window.location.href = 'team_events.php';
            } else {
                window.location.href = `team_events.php?base_type=${encodeURIComponent(val)}`;
            }
        });

        initSearchableSelect(eventSelect, { placeholder: '🔍 Search or choose event…', allLabel: '— All Events —' });
    }

    const memberSelects = document.querySelectorAll('.member-select');

    const clubFilter = document.getElementById('team_club_filter');
    const memberDatalist = document.getElementById('member-list');

    function updateMemberListOptions(e) {
        if (!memberDatalist) return;
        const selectedClub = clubFilter ? clubFilter.value.trim().toUpperCase() : '';
        const focusedSelect = (e && e.target) ? e.target : null;
        
        // Collect IDs selected in OTHER fields to exclude them
        const otherSelected = new Set();
        if (focusedSelect) {
            memberSelects.forEach(select => {
                if (select !== focusedSelect) {
                    const val = select.value.trim();
                    if (val) otherSelected.add(val);
                }
            });
        }
        
        memberDatalist.innerHTML = '';
        
        Object.values(eligibleParticipants).forEach(p => {
            const pClub = (p.club_name || '').trim().toUpperCase();
            if (!selectedClub || pClub === selectedClub) {
                const bibVal = p.bib_no || p.enrollment_id;
                if (otherSelected.has(bibVal) || otherSelected.has(p.enrollment_id)) {
                    return; // Exclude if already selected in another slot
                }
                const opt = document.createElement('option');
                const nameStr = (p.shooter_name || '').trim() || '[Name Not Specified]';
                const labelText = `${bibVal} - ${nameStr}` + (p.club_name ? ` (${p.club_name})` : '');
                opt.value = bibVal;
                opt.textContent = labelText;
                opt.setAttribute('label', labelText);
                memberDatalist.appendChild(opt);
            }
        });
    }

    if (clubFilter) {
        clubFilter.addEventListener('change', () => {
            updateMemberListOptions();
            
            // Clear inputs that do not belong to the selected club
            const selectedClub = clubFilter.value.trim().toUpperCase();
            if (selectedClub) {
                memberSelects.forEach((select, index) => {
                    const val = select.value.trim();
                    if (val) {
                        const p = Object.values(eligibleParticipants).find(item => item.enrollment_id === val || item.bib_no === val);
                        if (p && (p.club_name || '').trim().toUpperCase() !== selectedClub) {
                            select.value = '';
                            const i = index + 1;
                            const clubTd = document.getElementById(`member_${i}_club`);
                            const nameTd = document.getElementById(`member_${i}_name`);
                            const scoreTd = document.getElementById(`member_${i}_score`);
                            if (clubTd) clubTd.textContent = '—';
                            if (nameTd) nameTd.textContent = '—';
                            if (scoreTd) scoreTd.textContent = '—';
                        }
                    }
                });
                calculateTotals();
            }
        });
    }

    memberSelects.forEach(select => {
        select.addEventListener('focus', updateMemberListOptions);
    });

    // Run once on load
    updateMemberListOptions();
    
    function calculateTotals() {
        let total = 0.0;
        memberSelects.forEach(select => {
            const val = select.value.trim();
            const eligibleInfo = Object.values(eligibleParticipants).find(item => item.enrollment_id === val || item.bib_no === val);
            if (eligibleInfo) {
                total += parseFloat(eligibleInfo.score) || 0.0;
            }
        });
        const display = document.getElementById('team-total-display');
        if (display) {
            display.textContent = total.toFixed(2);
        }
    }

    memberSelects.forEach((select, index) => {
        const i = index + 1;
        
        const handleSelection = () => {
            const val = select.value.trim();
            const clubTd = document.getElementById(`member_${i}_club`);
            const nameTd = document.getElementById(`member_${i}_name`);
            const scoreTd = document.getElementById(`member_${i}_score`);

            if (val === '') {
                clubTd.textContent = '—';
                nameTd.textContent = '—';
                scoreTd.textContent = '—';
                calculateTotals();
                return;
            }

            const eligibleInfo = Object.values(eligibleParticipants).find(item => item.enrollment_id === val || item.bib_no === val);
            if (eligibleInfo) {
                // Check 1: Duplicate selected on this form
                let duplicateForm = false;
                memberSelects.forEach((otherSelect, otherIndex) => {
                    if (index !== otherIndex && otherSelect.value.trim() === val) {
                        duplicateForm = true;
                    }
                });
                if (duplicateForm) {
                    Swal.fire({
                        text: `Participant '${val}' is already selected for this team.`,
                        icon: 'warning',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    });
                    select.value = '';
                    clubTd.textContent = '—';
                    nameTd.textContent = '—';
                    scoreTd.textContent = '—';
                    calculateTotals();
                    return;
                }

                // Check 2: Already assigned to another team
                const editingTeamId = document.getElementById('team_id').value;
                const assignedTeamId = assignedMembers[val];
                if (assignedTeamId && String(assignedTeamId) !== String(editingTeamId)) {
                    Swal.fire({
                        text: `Participant '${val}' is already on another team for this event/category.`,
                        icon: 'warning',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    });
                    select.value = '';
                    clubTd.textContent = '—';
                    nameTd.textContent = '—';
                    scoreTd.textContent = '—';
                    calculateTotals();
                    return;
                }

                // Check 3: Same club as other already-selected members
                const newClub = (eligibleInfo.club_name || '').trim().toUpperCase();
                let clubMismatch = false;
                let mismatchClub = '';
                memberSelects.forEach((otherSelect, otherIndex) => {
                    if (index !== otherIndex) {
                        const otherVal = otherSelect.value.trim();
                        if (otherVal && eligibleParticipants[otherVal]) {
                            const otherClub = (eligibleParticipants[otherVal].club_name || '').trim().toUpperCase();
                            if (otherClub && otherClub !== newClub) {
                                clubMismatch = true;
                                mismatchClub = eligibleParticipants[otherVal].club_name;
                            }
                        }
                    }
                });
                if (clubMismatch) {
                    Swal.fire({
                        title: 'Club Mismatch',
                        text: `All team members must be from the same club. '${val}' is from '${eligibleInfo.club_name}', but another member is from '${mismatchClub}'.`,
                        icon: 'warning',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    });
                    select.value = '';
                    clubTd.textContent = '—';
                    nameTd.textContent = '—';
                    scoreTd.textContent = '—';
                    calculateTotals();
                    return;
                }

                clubTd.textContent = eligibleInfo.club_name;
                nameTd.textContent = eligibleInfo.shooter_name;
                scoreTd.textContent = parseFloat(eligibleInfo.score).toFixed(2);
            } else {
                clubTd.textContent = '—';
                nameTd.textContent = '—';
                scoreTd.textContent = '—';
            }
            calculateTotals();
        };

        select.addEventListener('input', handleSelection);
        select.addEventListener('change', handleSelection);
    });

    const teamForm = document.getElementById('team-form');
    if (teamForm) {
        teamForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Validate that exactly 3 distinct members are chosen
            const m1 = document.getElementById('member_1_select').value;
            const m2 = document.getElementById('member_2_select').value;
            const m3 = document.getElementById('member_3_select').value;

            if (!m1 || !m2 || !m3) {
                Swal.fire({
                    text: 'Please select exactly three team members.',
                    icon: 'warning',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#ADB5BD'
                });
                return;
            }

            if (m1 === m2 || m1 === m3 || m2 === m3) {
                Swal.fire({
                    text: 'Duplicate members selected. Each team member must be a unique participant.',
                    icon: 'warning',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#ADB5BD'
                });
                return;
            }

            // Final same-club check before saving
            const clubs = [m1, m2, m3].map(mid => {
                const info = eligibleParticipants[mid];
                return info ? (info.club_name || '').trim().toUpperCase() : '';
            }).filter(c => c !== '');
            const uniqueClubs = [...new Set(clubs)];
            if (uniqueClubs.length > 1) {
                Swal.fire({
                    title: 'Club Mismatch',
                    text: 'All three team members must be from the same club. Please select members from the same club.',
                    icon: 'warning',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#ADB5BD'
                });
                return;
            }

            const fd = new FormData(teamForm);
            try {
                const resp = await fetch('actions/save_team.php', {
                    method: 'POST',
                    body: fd
                });
                const res = await resp.json();
                if (res.success) {
                    Swal.fire({
                        text: res.message,
                        icon: 'success',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        text: res.message,
                        icon: 'error',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    });
                }
            } catch(e) {
                Swal.fire({
                    text: 'Failed to save team: Network error.',
                    icon: 'error',
                    background: '#1a1a1a',
                    color: '#fff',
                    confirmButtonColor: '#ADB5BD'
                });
            }
        });
    }
});

function editTeam(teamId, teamName, m1, m2, m3) {
    const form = document.getElementById('team-form');
    if (!form) return;

    form.scrollIntoView({ behavior: 'smooth' });

    const titleEl = form.closest('.meta-card').querySelector('h3');
    if (titleEl) {
        titleEl.textContent = `Edit Team: ${teamName}`;
    }

    document.getElementById('team_id').value = teamId;
    document.getElementById('team_name').value = teamName;

    // Set the club filter based on the first member's club
    const m1Info = eligibleParticipants[m1];
    const m1Club = m1Info ? (m1Info.club_name || '') : '';
    const clubFilter = document.getElementById('team_club_filter');
    if (clubFilter) {
        clubFilter.value = m1Club;
    }
    if (typeof updateMemberListOptions === 'function') {
        updateMemberListOptions();
    }

    document.getElementById('member_1_select').value = m1;
    document.getElementById('member_2_select').value = m2;
    document.getElementById('member_3_select').value = m3;

    ['member_1_select', 'member_2_select', 'member_3_select'].forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.dispatchEvent(new Event('change'));
        }
    });

    document.getElementById('btn-cancel-edit').style.display = 'inline-block';
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.innerHTML = '💾 Update &amp; Save Team';
    }
}

function cancelTeamEdit() {
    const form = document.getElementById('team-form');
    if (!form) return;

    document.getElementById('team_id').value = '';
    form.reset();

    const clubFilter = document.getElementById('team_club_filter');
    if (clubFilter) {
        clubFilter.value = '';
    }
    if (typeof updateMemberListOptions === 'function') {
        updateMemberListOptions();
    }

    const titleEl = form.closest('.meta-card').querySelector('h3');
    if (titleEl) {
        titleEl.textContent = 'Form New Team';
    }

    document.getElementById('btn-cancel-edit').style.display = 'none';
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.innerHTML = '💾 Form &amp; Save Team';
    }

    for (let i = 1; i <= 3; i++) {
        document.getElementById(`member_${i}_club`).textContent = '—';
        document.getElementById(`member_${i}_name`).textContent = '—';
        document.getElementById(`member_${i}_score`).textContent = '—';
    }
    document.getElementById('team-total-display').textContent = '0.00';
}

async function deleteTeam(teamId, teamName) {
    const result = await Swal.fire({
        text: `Are you sure you want to delete "${teamName}"? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        background: '#1a1a1a',
        color: '#fff',
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#aaa'
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('team_id', String(teamId));

    try {
        const resp = await fetch('actions/delete_team.php', {
            method: 'POST',
            body: fd
        });
        const res = await resp.json();
        if (res.success) {
            Swal.fire({
                text: res.message,
                icon: 'success',
                background: '#1a1a1a',
                color: '#fff',
                confirmButtonColor: '#ADB5BD'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire({
                text: res.message,
                icon: 'error',
                background: '#1a1a1a',
                color: '#fff',
                confirmButtonColor: '#ADB5BD'
            });
        }
    } catch(e) {
        Swal.fire({
            text: 'Failed to delete team: Network error.',
            icon: 'error',
            background: '#1a1a1a',
            color: '#fff',
            confirmButtonColor: '#ADB5BD'
        });
    }
}

function getScoreSheetUrl(allocId, eventName) {
    const evt = eventName.toLowerCase().replace(/_/g, ' ');
    
    // 1. Standard Pistol
    if (evt.includes('standard pistol') || evt.includes('25m standard')) {
        return `../Score Sheet/standard-pistol.php?alloc_id=${allocId}`;
    }
    
    // 2. Centre Fire / Sport Pistol / 25M Pistol
    if (evt.includes('centre fire') || 
        evt.includes('center fire') || 
        evt.includes('sports pistol') || 
        evt.includes('sport pistol') || 
        evt.includes('25m pistol')) {
        return `../Score Sheet/centre-fire-pistol.php?alloc_id=${allocId}`;
    }
    
    // 3. Rifle Prone
    if (evt.includes('prone')) {
        return `../Score Sheet/rifle-prone.php?alloc_id=${allocId}`;
    }
    
    // 4. Default: Air Rifle / Air Pistol / 10M / 50M Free Pistol
    return `../Score Sheet/air-rifle-pistol.php?alloc_id=${allocId}`;
}

document.addEventListener('DOMContentLoaded', () => {
    // Inline Team Name editing
    document.querySelectorAll('.editable-team-title').forEach(el => {
        el.addEventListener('blur', () => {
            const teamId = el.getAttribute('data-team-id');
            const newName = el.textContent.trim();
            if (newName === '') return;

            const fd = new FormData();
            fd.append('type', 'team');
            fd.append('id', teamId);
            fd.append('col', 'team_name');
            fd.append('val', newName);

            fetch('actions/update_team_cell.php', { method: 'POST', body: fd, keepalive: true })
            .then(r => r.json())
            .then(res => {
                if (!res.success) console.error('Failed to update team name:', res.message);
            })
            .catch(err => console.error(err));
        });
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                el.blur();
            }
        });
    });

    // Event participants data map for autocomplete lookup
    const regNameMap = {};
    const regClubMap = {};
    const regScoreMap = {};
    <?php foreach ($allRegs as $r): ?>
        regNameMap[<?= json_encode($r['reg_id']) ?>] = <?= json_encode(trim($r['first_name'] . ' ' . $r['last_name'])) ?>;
        regClubMap[<?= json_encode($r['reg_id']) ?>] = <?= json_encode($r['club_name'] ?? '') ?>;
    <?php endforeach; ?>
    <?php foreach ($participants as $p): ?>
        regScoreMap[<?= json_encode($p['enrollment_id']) ?>] = <?= json_encode((string)$p['score']) ?>;
    <?php endforeach; ?>

    // Inline Team Member cell editing
    document.querySelectorAll('.editable-team-member-cell').forEach(el => {
        const isInput = el.tagName === 'INPUT';
        
        // Save the original value on focus to enable reversion on validation failure
        el.addEventListener('focus', () => {
            el.dataset.origVal = (isInput ? el.value : el.textContent).trim();
        });
        
        const saveCell = () => {
            const memberId = el.getAttribute('data-member-id');
            const teamId = el.getAttribute('data-team-id');
            const col = el.getAttribute('data-col');
            const val = (isInput ? el.value : el.textContent).trim();

            if (val === (el.dataset.origVal || '').trim()) {
                return; // No change, skip database call
            }

            const fd = new FormData();
            fd.append('type', 'member');
            fd.append('id', memberId);

            if (col === 'enrollment_id') {
                if (regNameMap[val]) {
                    const row = el.closest('tr');
                    const nameTd = row.querySelector('[data-col="shooter_name"]');
                    const clubTd = row.querySelector('[data-col="club_name"]');
                    const scoreTd = row.querySelector('[data-col="score"]');
                    
                    const nameVal = regNameMap[val];
                    const clubVal = regClubMap[val] || '';
                    const scoreVal = regScoreMap[val] || '0.00';
                    
                    if (nameTd) nameTd.textContent = nameVal;
                    if (clubTd) clubTd.textContent = clubVal;
                    if (scoreTd) scoreTd.textContent = parseFloat(scoreVal).toFixed(2);
                    
                    fd.append('col', 'bulk');
                    fd.append('enrollment_id', val);
                    fd.append('shooter_name', nameVal);
                    fd.append('club_name', clubVal);
                    fd.append('score', scoreVal);
                } else {
                    fd.append('col', col);
                    fd.append('val', val);
                }
            } else {
                fd.append('col', col);
                fd.append('val', val);
            }

            fetch('actions/update_team_cell.php', { method: 'POST', body: fd, keepalive: true })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    if (res.team_id && res.new_total) {
                        const totalEl = document.getElementById(`team-total-score-${res.team_id}`);
                        if (totalEl) totalEl.textContent = res.new_total;
                    }
                    if (col === 'enrollment_id') {
                        const actionCell = document.getElementById(`action-cell-${memberId}`);
                        if (actionCell) {
                            if (res.lane_alloc_id) {
                                const url = getScoreSheetUrl(res.lane_alloc_id, '<?= htmlspecialchars(addslashes($selEvent)) ?>');
                                actionCell.innerHTML = `<a href="${url}" class="btn btn-sm" style="padding: 4px 10px; font-size: 11px; background: #2980b9; color: #fff; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: 600;"><i class="bi bi-eye-fill"></i> View Sheet</a>`;
                            } else {
                                actionCell.innerHTML = `<span style="color: var(--text-muted); font-size: 11px;">Not Allocated</span>`;
                            }
                        }
                    }
                    // Update origVal since update was successful
                    el.dataset.origVal = val;
                } else {
                    Swal.fire({
                        text: res.message,
                        icon: 'error',
                        background: '#1a1a1a',
                        color: '#fff',
                        confirmButtonColor: '#ADB5BD'
                    });
                    // Revert values
                    const origVal = el.dataset.origVal || '';
                    if (isInput) {
                        el.value = origVal;
                    } else {
                        el.textContent = origVal;
                    }
                    if (col === 'enrollment_id') {
                        const row = el.closest('tr');
                        const nameTd = row.querySelector('[data-col="shooter_name"]');
                        const clubTd = row.querySelector('[data-col="club_name"]');
                        const scoreTd = row.querySelector('[data-col="score"]');
                        if (nameTd) nameTd.textContent = regNameMap[origVal] || '—';
                        if (clubTd) clubTd.textContent = regClubMap[origVal] || '—';
                        if (scoreTd) scoreTd.textContent = regScoreMap[origVal] ? parseFloat(regScoreMap[origVal]).toFixed(2) : '—';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                const origVal = el.dataset.origVal || '';
                if (isInput) el.value = origVal;
                else el.textContent = origVal;
            });
        };

        if (isInput) {
            el.addEventListener('blur', saveCell);
            el.addEventListener('input', () => {
                const val = el.value.trim();
                if (regNameMap[val]) {
                    const row = el.closest('tr');
                    const nameTd = row.querySelector('[data-col="shooter_name"]');
                    const clubTd = row.querySelector('[data-col="club_name"]');
                    const scoreTd = row.querySelector('[data-col="score"]');
                    if (nameTd) nameTd.textContent = regNameMap[val];
                    if (clubTd) clubTd.textContent = regClubMap[val] || '';
                    if (scoreTd) {
                        const scoreVal = regScoreMap[val] || '0.00';
                        scoreTd.textContent = parseFloat(scoreVal).toFixed(2);
                    }
                }
            });
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    el.blur();
                }
            });
        } else {
            el.addEventListener('blur', saveCell);
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    el.blur();
                }
            });
        }
    });
});
</script>

<datalist id="bib-list">
  <?php foreach ($allRegs as $r): ?>
    <option value="<?= htmlspecialchars(formatBibNo($r['reg_id'])) ?>"><?= htmlspecialchars(formatBibNo($r['reg_id']) . ' - ' . trim($r['first_name'] . ' ' . $r['last_name'])) ?></option>
  <?php endforeach; ?>
</datalist>

<datalist id="member-list">
  <?php foreach ($participants as $p): ?>
    <option value="<?= htmlspecialchars($p['enrollment_id']) ?>"><?= htmlspecialchars($p['shooter_name']) ?> (<?= htmlspecialchars($p['club_name']) ?>)</option>
  <?php endforeach; ?>
</datalist>
 
<?php
require_once 'includes/footer.php';
?>
<script src="js/searchable_select.js"></script>
