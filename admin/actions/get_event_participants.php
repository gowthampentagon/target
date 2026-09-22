<?php
// admin/actions/get_event_participants.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$eventName = trim($_GET['event_name'] ?? '');
$category = trim($_GET['category'] ?? '');

if ($eventName === '' || $category === '') {
    exit(json_encode(['success' => false, 'message' => 'Missing event_name or category.']));
}

try {
    $pdo = getDB();
    
    // Fetch all approved event registrations for the selected event and category
    // Also left join lane allocations and score sheets to fetch their scores if they have completed scoring
    $stmt = $pdo->prepare("
        SELECT er.event_reg_id AS enrollment_id, 
               TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS shooter_name, 
               r.club_name, 
               COALESCE(ss.grand_total_val, 0) AS score
        FROM event_registrations er
        JOIN registrations r ON er.user_id = r.id
        LEFT JOIN lane_allocations la ON la.event_reg_id = er.id
        LEFT JOIN score_sheets ss ON ss.lane_alloc_id = la.id AND ss.custom_bib = er.event_reg_id
        WHERE er.event_name = ? 
          AND er.category = ?
          AND er.status = 'approved'
          AND er.event_reg_id IS NOT NULL 
          AND er.event_reg_id != ''
        ORDER BY er.event_reg_id ASC
    ");
    $stmt->execute([$eventName, $category]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'participants' => $participants]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
