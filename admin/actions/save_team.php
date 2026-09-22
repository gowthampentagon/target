<?php
// admin/actions/save_team.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/events.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$teamId    = (int)($_POST['team_id'] ?? 0);
$eventName = trim($_POST['event_name'] ?? '');
$category  = trim($_POST['category'] ?? '');
$teamName  = trim($_POST['team_name'] ?? '');
$member1   = trim($_POST['member_1'] ?? '');
$member2   = trim($_POST['member_2'] ?? '');
$member3   = trim($_POST['member_3'] ?? '');

if ($eventName === '' || $category === '' || $teamName === '' || $member1 === '' || $member2 === '' || $member3 === '') {
    exit(json_encode(['success' => false, 'message' => 'All fields are required, including exactly 3 team members.']));
}

if ($member1 === $member2 || $member1 === $member3 || $member2 === $member3) {
    exit(json_encode(['success' => false, 'message' => 'Each team member must be a unique participant.']));
}

/**
 * Find the most appropriate lane_alloc_id and live score for an enrollment ID.
 *
 * Returns: ['lane_alloc_id' => int|null, 'score' => float]
 */
function findMemberLane(PDO $pdo, string $enrollId, string $eventName, string $category): array {
    return resolveTeamMemberScore($pdo, $enrollId, $eventName, $category);
}


try {
    $pdo = getDB();

    $member1 = resolveRealRegId($pdo, $member1);
    $member2 = resolveRealRegId($pdo, $member2);
    $member3 = resolveRealRegId($pdo, $member3);

    // Ensure lane_alloc_id column exists (safe no-op if already present)
    try {
        $pdo->exec("ALTER TABLE team_members ADD COLUMN lane_alloc_id INT NULL DEFAULT NULL");
    } catch (Exception $colEx) { /* already exists */ }

    // Server-side validation: Ensure none of the members are already assigned to another team for this event
    $chkParams = [$eventName];
    $chkCatQuery = "";
    if (!empty($category)) {
        $chkCatQuery = " AND t.category = ? ";
        $chkParams[] = $category;
    }
    $chkParams[] = $member1;
    $chkParams[] = $member2;
    $chkParams[] = $member3;
    $chkParams[] = $teamId;

    $chkStmt = $pdo->prepare("
        SELECT tm.enrollment_id, t.team_name, t.id AS team_id
        FROM team_members tm
        JOIN teams t ON tm.team_id = t.id
        WHERE t.event_name = ? $chkCatQuery 
          AND tm.enrollment_id IN (?, ?, ?)
          AND t.id != ?
    ");
    $chkStmt->execute($chkParams);
    $existingAssignments = $chkStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($existingAssignments)) {
        $details = [];
        foreach ($existingAssignments as $ea) {
            $details[] = "{$ea['enrollment_id']} is already on \"{$ea['team_name']}\"";
        }
        exit(json_encode(['success' => false, 'message' => 'Validation error: ' . implode(', ', $details)]));
    }

    $members = [$member1, $member2, $member3];
    $memberDetails = [];
    $totalScore = 0.0;

    // Server-side same-club validation: all members must belong to the same club
    $clubNames = [];
    foreach ($members as $enrollId) {
        $clubStmt = $pdo->prepare("SELECT club_name FROM registrations WHERE reg_id = ? LIMIT 1");
        $clubStmt->execute([$enrollId]);
        $clubRow = $clubStmt->fetch(PDO::FETCH_ASSOC);
        if ($clubRow) {
            $clubNames[] = strtoupper(trim($clubRow['club_name']));
        }
    }
    $uniqueClubNames = array_unique($clubNames);
    if (count($uniqueClubNames) > 1) {
        exit(json_encode(['success' => false, 'message' => 'All team members must be from the same club. Found members from different clubs: ' . implode(', ', array_map('ucwords', array_map('strtolower', $uniqueClubNames)))]));
    }

    foreach ($members as $enrollId) {
        // Get basic registration info
        $stmt = $pdo->prepare("
            SELECT r.reg_id AS enrollment_id,
                   TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS shooter_name,
                   r.club_name
            FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            WHERE r.reg_id = ?
            LIMIT 1
        ");
        $stmt->execute([$enrollId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            exit(json_encode(['success' => false, 'message' => "Participant with Enrollment ID '{$enrollId}' not found in registrations."]));
        }

        // Find the correct lane and score using bib-priority matching
        $laneInfo = findMemberLane($pdo, $enrollId, $eventName, $category);

        $row['score']         = $laneInfo['score'];
        $row['lane_alloc_id'] = $laneInfo['lane_alloc_id'];

        $memberDetails[] = $row;
        $totalScore += $laneInfo['score'];
    }

    $pdo->beginTransaction();

    if ($teamId > 0) {
        $pdo->prepare("UPDATE teams SET event_name=?, category=?, team_name=?, total_score=? WHERE id=?")
            ->execute([$eventName, $category, $teamName, $totalScore, $teamId]);
        $pdo->prepare("DELETE FROM team_members WHERE team_id=?")
            ->execute([$teamId]);
    } else {
        $pdo->prepare("INSERT INTO teams (event_name, category, team_name, total_score) VALUES (?,?,?,?)")
            ->execute([$eventName, $category, $teamName, $totalScore]);
        $teamId = (int)$pdo->lastInsertId();
    }

    $insMem = $pdo->prepare("
        INSERT INTO team_members (team_id, enrollment_id, shooter_name, club_name, score, lane_alloc_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($memberDetails as $d) {
        $insMem->execute([$teamId, $d['enrollment_id'], $d['shooter_name'], $d['club_name'], $d['score'], $d['lane_alloc_id']]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $teamId > 0 ? 'Team updated successfully.' : 'Team formed and saved successfully.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
