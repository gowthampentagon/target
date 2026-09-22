<?php
// admin/actions/lookup_competitor.php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

header('Content-Type: application/json; charset=utf-8');

$query = trim($_GET['q'] ?? '');
$type  = trim($_GET['type'] ?? 'bib'); // 'bib' or 'name'

if (empty($query)) {
    echo json_encode(['success' => false, 'result' => null]);
    exit;
}

try {
    $pdo = getDB();
    $digits = preg_replace('/\D/', '', $query);

    if ($type === 'bib') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT TRIM(CONCAT(r.first_name, ' ', r.last_name)) AS full_name
            FROM registrations r
            LEFT JOIN event_registrations er ON r.id = er.user_id
            LEFT JOIN lane_allocations la ON er.id = la.event_reg_id
            WHERE UPPER(r.reg_id) = UPPER(?) 
               OR UPPER(er.event_reg_id) = UPPER(?)
               OR UPPER(la.bib_no) = UPPER(?)
               " . (!empty($digits) ? "OR r.reg_id LIKE ? OR er.event_reg_id LIKE ? OR la.bib_no LIKE ?" : "") . "
            LIMIT 1
        ");
        $params = [$query, $query, $query];
        if (!empty($digits)) {
            $params[] = "%$digits%";
            $params[] = "%$digits%";
            $params[] = "%$digits%";
        }
        $stmt->execute($params);
        $res = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'result' => $res ?: null]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT r.reg_id
            FROM registrations r
            WHERE UPPER(TRIM(CONCAT(r.first_name, ' ', r.last_name))) = UPPER(?)
               OR UPPER(r.first_name) = UPPER(?)
               OR UPPER(r.last_name) = UPPER(?)
            LIMIT 1
        ");
        $stmt->execute([$query, $query, $query]);
        $res = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'result' => $res ?: null]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
