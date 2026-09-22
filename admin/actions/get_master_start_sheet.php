<?php
// admin/actions/get_master_start_sheet.php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

try {
    $pdo = getDB();
    
    $selectedEventsJson = $_GET['events'] ?? '';
    
    $query = "
        SELECT la.*, er.event_name, er.category, r.club_name, r.reg_id, er.event_reg_id AS enrollment_id, r.first_name, r.last_name, r.district
        FROM lane_allocations la
        JOIN event_registrations er ON la.event_reg_id = er.id
        LEFT JOIN registrations r ON er.user_id = r.id
    ";
    
    $params = [];
    if (!empty($selectedEventsJson)) {
        $selectedBases = json_decode($selectedEventsJson, true);
        if (is_array($selectedBases) && !empty($selectedBases)) {
            $matchingIds = [];
            foreach ($selectedBases as $selectedBase) {
                foreach ($EVENTS_MAPPING as $id => $fullName) {
                    $cleanBase = preg_replace('/[^a-zA-Z0-9]/', '', getBackendEventBaseType($fullName));
                    $cleanSelectedBase = preg_replace('/[^a-zA-Z0-9]/', '', $selectedBase);
                    if ($cleanBase === $cleanSelectedBase) {
                        $matchingIds[] = $id;
                    }
                }
                $matchingIds[] = $selectedBase;
            }
            $matchingIds = array_values(array_unique($matchingIds));
            
            $inClause = implode(',', array_fill(0, count($matchingIds), '?'));
            $query .= " WHERE er.event_name IN ($inClause) ";
            $params = $matchingIds;
        }
    }
    
    $query .= " ORDER BY la.scheduled_date ASC, la.relay_no ASC, la.start_time ASC, la.lane_no ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $allDates = [];
    foreach ($allocations as $a) {
        if (!empty($a['scheduled_date']) && !in_array($a['scheduled_date'], $allDates, true)) {
            $allDates[] = $a['scheduled_date'];
        }
    }
    sort($allDates);

    $grouped = [];
    foreach ($allocations as $a) {
        $evtId = $a['event_name'];
        $cat = strtolower($a['category'] ?? 'nr');
        if ($cat === 'nr_mqs') $cat = 'nr';
        $fullName = $EVENTS_MAPPING[$evtId] ?? $evtId;
        $baseType = getBackendEventBaseType($fullName);
        if (empty($baseType)) {
            $baseType = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($evtId));
        }
        $baseType = preg_replace('/_(issf|nr|nr_mqs)$/i', '', $baseType);
        if (strpos(strtoupper($fullName), '(ISSF)') !== false || strpos(strtoupper($evtId), 'IS-') === 0) {
            $cat = 'issf';
        }
        $baseType = $baseType . '_' . $cat;
        $en = formatBaseEventName($baseType);
        
        $a['base_type'] = $baseType;
        
        $date = $a['scheduled_date'];
        $relay = $a['relay_no'];
        
        $grouped[$en][$date][$relay][] = $a;
    }
    ksort($grouped);

    if (empty($grouped)) {
        echo '<div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <h3>No Allocations Found</h3>
                <p style="margin-top: 10px;">Please allocate shooters under the Lane Allocations workspace first.</p>
              </div>';
        exit;
    }

    foreach ($grouped as $en => $dates):
        ksort($dates);
    ?>
        <div class="discipline-section" style="margin-bottom: 50px;">
            <div class="discipline-title" style="font-family: 'Cinzel', serif; font-size: 20px; color: var(--gold-400); border-bottom: 2px solid rgba(255, 255, 255,0.3); padding-bottom: 8px; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px;">
                <?= htmlspecialchars($en) ?>
            </div>

            <?php 
            foreach ($dates as $date => $relays):
                ksort($relays);
                $formattedDate = date('d-M-Y', strtotime($date));
                $dayIndex = array_search($date, $allDates, true);
                $dayNum = ($dayIndex !== false) ? ($dayIndex + 1) : 1;
            ?>
                <div class="day-section" style="margin-bottom: 30px; padding-left: 10px;">
                    <div class="day-title" style="font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 15px; text-transform: uppercase;">
                        DAY <?= $dayNum ?> - <?= $formattedDate ?>
                    </div>

                    <?php foreach ($relays as $relay => $dayAllocs): 
                        // Find start time from first allocation in this relay
                        $firstAlloc = $dayAllocs[0];
                        $startTimeStr = date('H:i', strtotime($firstAlloc['start_time']));
                    ?>
                        <div class="relay-section" style="margin-bottom: 30px; padding-left: 10px;">
                            <div class="relay-title" style="font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; color: #27ae60; margin-bottom: 10px;">
                                Relay <?= $relay ?> (Start: <?= $startTimeStr ?> Hrs)
                            </div>
                            
                            <table class="admin-table start-sheet-tbl" style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 13px;">
                                <thead>
                                    <tr>
                                        <th style="width: 250px; text-align: center; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">Start Time / Relay / Firing Point</th>
                                        <th style="width: 150px; text-align: center; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">Enrollment ID</th>
                                        <th style="text-align: left; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">Club Name</th>
                                        <th style="text-align: left; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">Participant Name</th>
                                        <th style="width: 150px; text-align: center; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">State</th>
                                        <th style="width: 120px; text-align: center; padding: 10px; text-transform: uppercase; font-size: 11px; font-weight: 700;">Score Sheet</th>
                                    </tr>
                                </thead>
                                <tbody>
                                      <?php foreach ($dayAllocs as $item): 
                                          $dispBib = !empty($item['bib_no']) ? formatBibNo($item['bib_no']) : '';
                                          $dispName = !empty($item['custom_name']) ? $item['custom_name'] : '';
                                      ?>
                                          <tr data-alloc-id="<?= htmlspecialchars((string)$item['id']) ?>" data-event-reg-id="<?= htmlspecialchars((string)$item['event_reg_id']) ?>">
                                             <td contenteditable="true" class="editable-cell" data-col="time_relay_fp" style="padding: 10px; text-align: center; min-height: 20px;"></td>
                                             <td contenteditable="true" class="editable-cell" data-col="enroll_id" style="padding: 10px; text-align: center; min-height: 20px;"><?= htmlspecialchars($dispBib) ?></td>
                                             <td class="readonly-cell" style="padding: 10px; text-align: left; font-weight: 500;">
                                                 <?= htmlspecialchars(strtoupper($item['club_name'])) ?>
                                                 <div style="font-size: 10px; color: var(--text-muted); font-weight: 400; margin-top: 2px;">
                                                     Category: <?= htmlspecialchars(extractEventCategory($EVENTS_MAPPING[$item['event_name']] ?? $item['event_name'], $item['base_type'])) ?>
                                                 </div>
                                             </td>
                                             <td contenteditable="true" class="editable-cell" data-col="name" style="padding: 10px; text-align: left; min-height: 20px;"><?= htmlspecialchars(strtoupper($dispName)) ?></td>
                                             <td contenteditable="true" class="editable-cell" data-col="state" style="padding: 10px; text-align: center; min-height: 20px;"></td>
                                             <td class="readonly-cell" style="padding: 10px; text-align: center;"></td>
                                         </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php 
            $dayNum++;
            endforeach; 
            ?>
        </div>
    <?php
    endforeach;

} catch (Exception $e) {
    http_response_code(500);
    echo '<div style="color: #ff6b6b; padding: 20px;">Error generating start sheet: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
