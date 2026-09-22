<?php
/**
 * admin/actions/delete_all_registrations_action.php
 * Wipes all registrations and resets sequence (Superadmin only).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

checkAdminAuth();
requireSuperAdmin(); // Enforce superadmin privileges

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = [
        'certificates', 'competitor_cards', 'custom_field_values',
        'score_sheets', 'lane_allocations', 'relay_schedules',
        'team_members', 'teams', 'profile_change_requests',
        'registration_sessions', 'event_registrations',
        'registrations', 'notifications', 'login_attempts',
        'admin_activity_log', 'custom_weapon_types'
    ];
    foreach ($tables as $tbl) {
        try {
            $pdo->exec("DELETE FROM `{$tbl}`");
            $pdo->exec("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
        } catch (Throwable $t) {
            // Table may not exist on this environment — skip it
        }
    }

    try {
        $pdo->exec("UPDATE `event_info` SET `last_seq` = 0");
    } catch (Throwable $t) {
        // Ignore if column doesn't exist
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Clear competitor uploaded files and user folders from storage
    $uploadsDir = dirname(__DIR__, 2) . '/uploads';
    
    // Clear specific subfolders
    clearFolder($uploadsDir . '/profile_photos');
    clearFolder($uploadsDir . '/membership_docs');
    clearFolder($uploadsDir . '/disability_proofs');
    clearFolder($uploadsDir . '/competitor_cards');
    clearFolder($uploadsDir . '/certificates');
    clearFolder($uploadsDir . '/payments');
    clearFolder($uploadsDir . '/signatures');
    clearFolder($uploadsDir . '/score_sheets');
    
    // Scan and delete user_xxx directories and temp files
    if (is_dir($uploadsDir)) {
        $dirs = array_diff(scandir($uploadsDir), ['.', '..']);
        foreach ($dirs as $d) {
            $path = $uploadsDir . '/' . $d;
            if (is_dir($path) && strpos($d, 'user_') === 0) {
                deleteDirectory($path);
            }
        }
    }

    $pdo->commit();
    exit(json_encode(['success' => true]));
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit(json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]));
}

/**
 * Deletes all files and directories inside a folder, leaving the folder itself.
 */
function clearFolder(string $dir): void {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        (is_dir($path)) ? deleteDirectory($path) : @unlink($path);
    }
}

/**
 * Recursively deletes a directory and its contents.
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        (is_dir($path)) ? deleteDirectory($path) : @unlink($path);
    }
    return @rmdir($dir);
}
