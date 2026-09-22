<?php
// admin/actions/update_team_cell.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$type = trim($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$col = trim($_POST['col'] ?? '');
$val = trim($_POST['val'] ?? '');

if ($id <= 0 || $col === '') {
    exit(json_encode(['success' => false, 'message' => 'Invalid parameters.']));
}

try {
    $pdo = getDB();
    
    if ($type === 'team') {
        if ($col === 'team_name') {
            $stmt = $pdo->prepare("UPDATE teams SET team_name = ? WHERE id = ?");
            $stmt->execute([$val, $id]);
            exit(json_encode(['success' => true, 'message' => 'Team updated successfully.']));
        }
    } elseif ($type === 'member') {
        $newEnroll = null;
        if ($col === 'bulk') {
            $newEnroll = trim($_POST['enrollment_id'] ?? '');
        } elseif ($col === 'enrollment_id') {
            $newEnroll = $val;
        }

        if ($newEnroll !== null && $newEnroll !== '') {
            // Find team details of this member
            $teamQuery = $pdo->prepare("
                SELECT t.id AS team_id, t.event_name, t.category 
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.id = ?
            ");
            $teamQuery->execute([$id]);
            $teamInfo = $teamQuery->fetch(PDO::FETCH_ASSOC);

            if ($teamInfo) {
                // Check if they are on another team for the same event and category
                $checkOther = $pdo->prepare("
                    SELECT t.team_name 
                    FROM team_members tm
                    JOIN teams t ON tm.team_id = t.id
                    WHERE t.event_name = ? 
                      AND t.category = ? 
                      AND tm.enrollment_id = ?
                      AND t.id != ?
                ");
                $checkOther->execute([$teamInfo['event_name'], $teamInfo['category'], $newEnroll, $teamInfo['team_id']]);
                $otherTeamName = $checkOther->fetchColumn();
                if ($otherTeamName !== false) {
                    exit(json_encode(['success' => false, 'message' => "Participant '$newEnroll' is already on another team ('$otherTeamName')."]));
                }

                // Check if they are already on the same team
                $checkSame = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM team_members
                    WHERE team_id = ? 
                      AND enrollment_id = ?
                      AND id != ?
                ");
                $checkSame->execute([$teamInfo['team_id'], $newEnroll, $id]);
                if ((int)$checkSame->fetchColumn() > 0) {
                    exit(json_encode(['success' => false, 'message' => "Participant '$newEnroll' is already selected on this team."]));
                }
            }
        }

        if ($col === 'bulk') {
            $enrollmentId = trim($_POST['enrollment_id'] ?? '');
            $shooterName = trim($_POST['shooter_name'] ?? '');
            $clubName = trim($_POST['club_name'] ?? '');
            $score = (float)($_POST['score'] ?? 0.0);
            
            $stmt = $pdo->prepare("
                UPDATE team_members 
                SET enrollment_id = ?, shooter_name = ?, club_name = ?, score = ?
                WHERE id = ?
            ");
            $stmt->execute([$enrollmentId, $shooterName, $clubName, $score, $id]);
        } else {
            $allowedCols = ['enrollment_id', 'shooter_name', 'club_name', 'score'];
            if (!in_array($col, $allowedCols, true)) {
                exit(json_encode(['success' => false, 'message' => 'Invalid column.']));
            }
            
            if ($col === 'score') {
                $valFloat = (float)$val;
                $stmt = $pdo->prepare("UPDATE team_members SET score = ? WHERE id = ?");
                $stmt->execute([$valFloat, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE team_members SET $col = ? WHERE id = ?");
                $stmt->execute([$val, $id]);
            }
        }
        
        // Fetch the team ID for this member to recalculate team total score
        $teamIdStmt = $pdo->prepare("SELECT team_id FROM team_members WHERE id = ?");
        $teamIdStmt->execute([$id]);
        $teamId = (int)$teamIdStmt->fetchColumn();
        
        if ($teamId > 0) {
            // Update team total score
            $updTeam = $pdo->prepare("
                UPDATE teams t
                SET t.total_score = (
                    SELECT COALESCE(SUM(tm.score), 0)
                    FROM team_members tm
                    WHERE tm.team_id = t.id
                )
                WHERE t.id = ?
            ");
            $updTeam->execute([$teamId]);
            
            // Get new total score
            $totalStmt = $pdo->prepare("SELECT total_score FROM teams WHERE id = ?");
            $totalStmt->execute([$teamId]);
            $newTotal = (float)$totalStmt->fetchColumn();
            
            // Re-fetch lane_alloc_id for the updated enrollment_id
            // This event and category matches the team event name and category
            $teamInfoStmt = $pdo->prepare("SELECT event_name, category FROM teams WHERE id = ?");
            $teamInfoStmt->execute([$teamId]);
            $teamInfo = $teamInfoStmt->fetch(PDO::FETCH_ASSOC);
            
            $laneAllocId = null;
            if ($teamInfo) {
                $mId = trim($_POST['enrollment_id'] ?? $val);
                $eventName = $teamInfo['event_name'];
                $category = $teamInfo['category'];

                // Match by explicit bib_no in start sheet or fallback to official reg_id
                $laStmt1 = $pdo->prepare("
                    SELECT la.id FROM lane_allocations la
                    JOIN event_registrations er ON la.event_reg_id = er.id
                    JOIN registrations r ON er.user_id = r.id
                    WHERE (la.bib_no = ? OR ((la.bib_no IS NULL OR la.bib_no = '') AND r.reg_id = ?))
                      AND er.event_name = ? AND er.category = ?
                    LIMIT 1
                ");
                $laStmt1->execute([$mId, $mId, $eventName, $category]);
                $resLa = $laStmt1->fetchColumn();

                if ($resLa !== false) {
                    $laneAllocId = (int)$resLa;
                }
            }

            exit(json_encode([
                'success' => true, 
                'message' => 'Member updated.',
                'team_id' => $teamId,
                'new_total' => number_format($newTotal, 2),
                'lane_alloc_id' => $laneAllocId
            ]));
        }
        
        exit(json_encode(['success' => true, 'message' => 'Member updated successfully.']));
    }
    
    exit(json_encode(['success' => false, 'message' => 'Invalid update operation.']));
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
