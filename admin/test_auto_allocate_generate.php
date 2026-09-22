<?php
// Simulate auto_allocate POST request for a custom event with ONLY manual allocations
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'generate';
$_POST['weapon_type'] = 'Custom Event Name';
$_POST['event_ids'] = json_encode(["Custom Event Name"]);
$_POST['schedule'] = json_encode([
    [
        'date' => '2026-07-22',
        'relays' => [
            ['r' => 1, 'rep' => '09:00', 'st' => '09:15']
        ]
    ]
]);
$_POST['allocs'] = json_encode([
    'manually_added_club' => [
        '2026-07-22' => [
            '1' => 1
        ]
    ]
]);

// Bypass auth check
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_last_activity'] = time();
$_SESSION['admin_role'] = 'superadmin';

try {
    include 'actions/auto_allocate.php';
} catch (Exception $e) {
    echo "CAUGHT EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
?>
