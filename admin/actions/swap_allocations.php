<?php
// admin/actions/swap_allocations.php
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();

$stray = ob_get_clean();
if (!empty($stray)) {
    $logMsg = '[' . date('Y-m-d H:i:s') . "] swap_allocations STRAY OUTPUT: " . substr($stray, 0, 500) . "\n";
    @file_put_contents(dirname(__DIR__, 2) . '/db_connection_error.log', $logMsg, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

$id1 = (int)($_POST['id1'] ?? 0);
$id2 = (int)($_POST['id2'] ?? 0);

if ($id1 <= 0 || $id2 <= 0 || $id1 === $id2) {
    exit(json_encode(['success' => false, 'message' => 'Invalid allocation IDs for swapping.']));
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Lock both rows to prevent concurrent modifications
    $stmt = $pdo->prepare("SELECT * FROM lane_allocations WHERE id IN (?, ?) FOR UPDATE");
    $stmt->execute([$id1, $id2]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $la1 = null;
    $la2 = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id1) $la1 = $row;
        if ((int)$row['id'] === $id2) $la2 = $row;
    }

    if (!$la1 || !$la2) {
        $pdo->rollBack();
        exit(json_encode(['success' => false, 'message' => 'One or both allocations not found.']));
    }

    // 1. Swap competitor identities (event_reg_id) using the 3-step placeholder approach.
    // Disable FOREIGN_KEY_CHECKS temporarily to allow writing a placeholder ID.
    $maxRow = $pdo->query("SELECT MAX(event_reg_id) AS m FROM lane_allocations")->fetchColumn();
    $placeholder = ((int)$maxRow) * 10 + 99999;

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Step A: move la1's event_reg_id to placeholder
    $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ? WHERE id = ?")
        ->execute([$placeholder, $id1]);

    // Step B: move la1's original value to la2's slot (id1 -> la2)
    $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ? WHERE id = ?")
        ->execute([$la1['event_reg_id'], $id2]);

    // Step C: move la2's original value to la1's slot (la2 -> id1)
    $pdo->prepare("UPDATE lane_allocations SET event_reg_id = ? WHERE id = ?")
        ->execute([$la2['event_reg_id'], $id1]);

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 2. Swap remaining identity fields: bib_no and custom_name (positions stay on their rows).
    $pdo->prepare("
        UPDATE lane_allocations
        SET
            bib_no      = CASE id WHEN ? THEN ? WHEN ? THEN ? END,
            custom_name = CASE id WHEN ? THEN ? WHEN ? THEN ? END
        WHERE id IN (?, ?)
    ")->execute([
        $id1, $la2['bib_no'],       $id2, $la1['bib_no'],
        $id1, $la2['custom_name'],  $id2, $la1['custom_name'],
        $id1, $id2
    ]);

    // 3. Swap score_sheets.lane_alloc_id between id1 and id2
    $ssRows = $pdo->prepare("SELECT id, lane_alloc_id FROM score_sheets WHERE lane_alloc_id IN (?, ?)");
    $ssRows->execute([$id1, $id2]);
    $ssData = $ssRows->fetchAll(PDO::FETCH_ASSOC);

    if (count($ssData) === 2) {
        $ssMaxRow = $pdo->query("SELECT MAX(lane_alloc_id) AS m FROM score_sheets")->fetchColumn();
        $ssPlaceholder = ((int)$ssMaxRow) * 10 + 99999;

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Step A: move id1's score sheet to placeholder
        $pdo->prepare("UPDATE score_sheets SET lane_alloc_id = ? WHERE lane_alloc_id = ?")
            ->execute([$ssPlaceholder, $id1]);
        // Step B: move id2's score sheet to id1
        $pdo->prepare("UPDATE score_sheets SET lane_alloc_id = ? WHERE lane_alloc_id = ?")
            ->execute([$id1, $id2]);
        // Step C: move placeholder to id2
        $pdo->prepare("UPDATE score_sheets SET lane_alloc_id = ? WHERE lane_alloc_id = ?")
            ->execute([$id2, $ssPlaceholder]);

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } elseif (count($ssData) === 1) {
        // Only one side has a score sheet — just move it to the other lane_alloc_id
        $only = $ssData[0];
        $from = (int)$only['lane_alloc_id'];
        $to   = ($from === $id1) ? $id2 : $id1;
        $pdo->prepare("UPDATE score_sheets SET lane_alloc_id = ? WHERE lane_alloc_id = ?")
            ->execute([$to, $from]);
    }

    // 4. Swap team_members.lane_alloc_id (nullable, non-unique)
    $pdo->prepare("
        UPDATE team_members
        SET lane_alloc_id = CASE lane_alloc_id WHEN ? THEN ? WHEN ? THEN ? END
        WHERE lane_alloc_id IN (?, ?)
    ")->execute([$id1, $id2, $id2, $id1, $id1, $id2]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Competitors swapped successfully.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Restore FK checks in case they were left disabled
    try { if (isset($pdo)) $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $e2) {}
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error during swap: ' . $e->getMessage()]);
}
