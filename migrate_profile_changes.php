<?php
/**
 * Migration runner for profile change requests schema
 * Run this file once to create the database schema
 */
require_once 'config/db.php';

try {
    $pdo = getDB();
    $sql = file_get_contents('sql/add_profile_change_requests.sql');
    if ($sql === false) {
        throw new Exception("Failed to read sql/add_profile_change_requests.sql");
    }
    
    // Remove comment lines first
    $lines = explode("\n", $sql);
    $cleanLines = array_filter($lines, function($line) {
        $trimmed = trim($line);
        return strpos($trimmed, '--') !== 0 && strpos($trimmed, '#') !== 0;
    });
    $cleanSql = implode("\n", $cleanLines);

    // Split by semicolon
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)), 'strlen');

    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignore error 1060 (Duplicate column name) and 1061 (Duplicate key name) and 1050 (Table already exists)
                $errorCode = $e->errorInfo[1] ?? 0;
                if ($errorCode !== 1060 && $errorCode !== 1061 && $errorCode !== 1050) {
                    throw $e;
                }
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Database schema migrated successfully.'], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT);
}
