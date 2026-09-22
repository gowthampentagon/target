<?php
/**
 * admin/actions/search_shooters.php
 * Endpoint for live autocomplete searching of registered shooters.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

$q = trim($_GET['q'] ?? $_POST['q'] ?? '');

if (strlen($q) < 1) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

try {
    $pdo = getDB();
    $activeChampionship = getActiveChampionship($pdo);
    $cid = (int)($activeChampionship['id'] ?? 1);

    $searchTerm = '%' . mb_strtolower($q, 'UTF-8') . '%';

    $query = "
        SELECT er.id AS event_reg_id, er.event_reg_id AS enrollment_id, er.event_name, er.category,
               r.id AS user_id, r.first_name, r.last_name, r.club_name, r.district, r.reg_id,
               la.bib_no AS alloc_bib, la.relay_no, la.lane_no, la.scheduled_date
        FROM event_registrations er
        JOIN registrations r ON er.user_id = r.id
        LEFT JOIN lane_allocations la ON er.id = la.event_reg_id
        WHERE (er.championship_id = ? OR er.championship_id IS NULL OR er.championship_id = 1)
          AND (
            LOWER(r.first_name) LIKE ? OR
            LOWER(r.last_name) LIKE ? OR
            LOWER(CONCAT(r.first_name, ' ', r.last_name)) LIKE ? OR
            LOWER(er.event_reg_id) LIKE ? OR
            LOWER(r.reg_id) LIKE ? OR
            LOWER(la.bib_no) LIKE ?
          )
        ORDER BY r.first_name ASC, r.last_name ASC
        LIMIT 25
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$cid, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $r) {
        $fullName = trim($r['first_name'] . ' ' . $r['last_name']);
        $bib = !empty($r['alloc_bib']) ? formatBibNo($r['alloc_bib']) : (!empty($r['reg_id']) ? formatBibNo($r['reg_id']) : '');
        $club = (!empty($r['club_name']) && trim($a['club_name'] ?? '') !== '' && trim($r['club_name']) !== '-') 
            ? $r['club_name'] 
            : (!empty($r['district']) && trim($r['district']) !== '' && trim($r['district']) !== '-' ? $r['district'] : '—');

        $results[] = [
            'event_reg_id'  => $r['event_reg_id'],
            'enrollment_id' => $r['enrollment_id'],
            'user_id'       => $r['user_id'],
            'name'          => $fullName,
            'bib_no'        => $bib,
            'club_name'     => $club,
            'event_name'    => $r['event_name'],
            'category'      => $r['category'],
            'current_relay' => $r['relay_no'] ? (int)$r['relay_no'] : null,
            'current_date'  => $r['scheduled_date'] ?? null
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
}
