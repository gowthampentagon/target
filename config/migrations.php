<?php
/**
 * config/migrations.php
 * Automatic schema migration system for SSA Championship.
 * 
 * HOW IT WORKS:
 * - Called from getDB() on every request (very fast — just checks a version number).
 * - Maintains a `schema_version` column in `event_info` to track the latest applied migration.
 * - Each migration is a callable that receives the PDO instance.
 * - Only NEW migrations (version > current) are applied.
 * - All migrations use IF NOT EXISTS patterns so they're safe to re-run.
 * 
 * HOW TO ADD A NEW MIGRATION:
 * 1. Increment $latestVersion by 1.
 * 2. Add a new case in runMigration() with the ALTER TABLE statements.
 * 3. That's it — it will auto-apply on the next page load.
 */

function getSchemaVersion(PDO $pdo): int {
    try {
        $stmt = $pdo->query("SELECT schema_version FROM event_info WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int)$row['schema_version'] : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function setSchemaVersion(PDO $pdo, int $version): void {
    try {
        $pdo->exec("UPDATE event_info SET schema_version = $version WHERE id = 1");
    } catch (\Throwable $e) {
        // Silently fail if event_info doesn't exist yet
    }
}

/**
 * Run all pending migrations.
 * This is called from getDB() after successful connection.
 */
function runPendingMigrations(PDO $pdo): void {
    // *** INCREMENT THIS when adding a new migration case ***
    $latestVersion = 6;
    
    // Ensure schema_version column exists
    try {
        $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `schema_version` INT NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {
        return;
    }
    
    $currentVersion = getSchemaVersion($pdo);
    
    if ($currentVersion >= $latestVersion) {
        return; // Already up to date
    }
    
    for ($v = $currentVersion + 1; $v <= $latestVersion; $v++) {
        try {
            runMigration($pdo, $v);
            setSchemaVersion($pdo, $v);
        } catch (\Throwable $e) {
            $logMsg = '[' . date('Y-m-d H:i:s') . "] Migration v$v FAILED: " . $e->getMessage() . "\n";
            @file_put_contents(dirname(__DIR__) . '/db_connection_error.log', $logMsg, FILE_APPEND);
            break;
        }
    }
}

function runMigration(PDO $pdo, int $version): void {
    switch ($version) {
        case 1:
            // ── Create admin_passcodes table ──
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `admin_passcodes` (
                  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
                  `admin_id`        INT(11)       NOT NULL,
                  `passcode`        VARCHAR(50)   NOT NULL,
                  `allowed_modules` TEXT          NULL DEFAULT NULL,
                  `allow_edit`      TINYINT(1)    NOT NULL DEFAULT 0,
                  `expires_at`      DATETIME      NOT NULL,
                  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  INDEX `idx_passcode_admin` (`admin_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            break;
            
        case 2:
            // ── Add all missing columns to existing tables ──
            
            // lane_allocations
            $pdo->exec("ALTER TABLE `lane_allocations` ADD COLUMN IF NOT EXISTS `target_serial_no` VARCHAR(50) NULL DEFAULT NULL AFTER `bib_no`");
            $pdo->exec("ALTER TABLE `lane_allocations` ADD COLUMN IF NOT EXISTS `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `score_sheet`");
            
            // custom_weapon_types
            $pdo->exec("ALTER TABLE `custom_weapon_types` ADD COLUMN IF NOT EXISTS `weapon_label` VARCHAR(200) NULL DEFAULT NULL AFTER `weapon_type`");
            
            // teams
            $pdo->exec("ALTER TABLE `teams` ADD COLUMN IF NOT EXISTS `club_name` VARCHAR(200) NULL DEFAULT NULL AFTER `team_name`");
            $pdo->exec("ALTER TABLE `teams` ADD COLUMN IF NOT EXISTS `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `total_score`");
            
            // team_members
            $pdo->exec("ALTER TABLE `team_members` ADD COLUMN IF NOT EXISTS `user_id` INT(11) NULL DEFAULT NULL AFTER `team_id`");
            $pdo->exec("ALTER TABLE `team_members` ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) NULL DEFAULT NULL AFTER `user_id`");
            $pdo->exec("ALTER TABLE `team_members` ADD COLUMN IF NOT EXISTS `bib_no` VARCHAR(30) NULL DEFAULT NULL AFTER `role`");
            
            // registrations
            $pdo->exec("ALTER TABLE `registrations` ADD COLUMN IF NOT EXISTS `date_of_birth` DATE NULL DEFAULT NULL AFTER `dob`");
            
            // login_attempts
            $pdo->exec("ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `identifier` VARCHAR(200) NULL DEFAULT NULL AFTER `id`");
            $pdo->exec("ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `attempts` INT(11) NOT NULL DEFAULT 1 AFTER `identifier`");
            $pdo->exec("ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `last_attempt` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `attempts`");
            break;
            
        case 3:
            // ── Event registration control columns on event_info ──
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `reg_start_active` TINYINT(1) DEFAULT 0 AFTER `last_seq`");
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `reg_start_date` DATETIME NULL DEFAULT NULL AFTER `reg_start_active`");
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `reg_end_active` TINYINT(1) DEFAULT 0 AFTER `reg_start_date`");
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `reg_end_date` DATETIME NULL DEFAULT NULL AFTER `reg_end_active`");
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `triple_entry_active` TINYINT(1) DEFAULT 0 AFTER `reg_end_date`");
            $pdo->exec("ALTER TABLE `event_info` ADD COLUMN IF NOT EXISTS `triple_entry_date` DATETIME NULL DEFAULT NULL AFTER `triple_entry_active`");
            break;

        case 4:
            // ── Ensure relay_schedules table exists & has relay_limit column ──
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `relay_schedules` (
                  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
                  `schedule_name`    VARCHAR(200) NOT NULL DEFAULT '',
                  `event_name`       VARCHAR(200) NOT NULL DEFAULT '',
                  `scheduled_date`   DATE         NOT NULL,
                  `relay_no`         INT(11)      NOT NULL,
                  `reporting_time`   TIME         NOT NULL,
                  `start_time`       TIME         NOT NULL,
                  `relay_limit`      INT(11)      NULL DEFAULT NULL,
                  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  INDEX `idx_event` (`event_name`, `schedule_name`),
                  INDEX `idx_template` (`schedule_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            try {
                $pdo->exec("ALTER TABLE `relay_schedules` ADD COLUMN `relay_limit` INT(11) NULL DEFAULT NULL AFTER `start_time`");
            } catch (\Throwable $e) {
                // Ignore if column already exists
            }
            break;

        case 5:
            // ── Drop UNIQUE constraint and foreign key on lane_allocations.event_reg_id ──
            try {
                $stmtFk = $pdo->query("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'lane_allocations' 
                      AND COLUMN_NAME = 'event_reg_id' 
                      AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                $fks = $stmtFk->fetchAll(PDO::FETCH_COLUMN);
                foreach ($fks as $fkName) {
                    $pdo->exec("ALTER TABLE `lane_allocations` DROP FOREIGN KEY `$fkName`");
                }
            } catch (\Throwable $e) {}

            try {
                $stmtIdx = $pdo->query("
                    SELECT INDEX_NAME 
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'lane_allocations' 
                      AND COLUMN_NAME = 'event_reg_id' 
                      AND NON_UNIQUE = 0
                ");
                $uniqueIndexes = $stmtIdx->fetchAll(PDO::FETCH_COLUMN);
                foreach ($uniqueIndexes as $idxName) {
                    if ($idxName !== 'PRIMARY') {
                        $pdo->exec("ALTER TABLE `lane_allocations` DROP INDEX `$idxName`");
                    }
                }
            } catch (\Throwable $e) {}

            try {
                $pdo->exec("ALTER TABLE `lane_allocations` ADD INDEX `idx_event_reg_id` (`event_reg_id`)");
            } catch (\Throwable $e) {}

            try {
                $pdo->exec("ALTER TABLE `lane_allocations` ADD CONSTRAINT `fk_lane_allocations_event_reg` FOREIGN KEY (`event_reg_id`) REFERENCES `event_registrations` (`id`) ON DELETE CASCADE");
            } catch (\Throwable $e) {}
            break;

        case 6:
            // ── Ensure team_members table has lane_alloc_id column ──
            try {
                $pdo->exec("ALTER TABLE `team_members` ADD COLUMN `lane_alloc_id` INT NULL DEFAULT NULL");
            } catch (\Throwable $e) {}
            break;
    }
}
