<?php
/**
 * Database Configuration
 * Saragarhi Shooting Academy – 51st Tamil Nadu Shooting Championship
 */
date_default_timezone_set('Asia/Kolkata');

// Load Security Module
require_once __DIR__ . '/security.php';
setSecurityHeaders();
startSecureSession();

// Force no caching for all dynamic pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Load local database configuration override if it exists
if (file_exists(__DIR__ . '/db.local.php')) {
    require_once __DIR__ . '/db.local.php';
}

// Define defaults for database constants if not already defined by local override
if (!defined('DB_HOST'))    define('DB_HOST',    '127.0.0.1');
if (!defined('DB_PORT'))    define('DB_PORT',    '3307');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_NAME'))    define('DB_NAME',    'ssachampionship');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Site constants
if (!defined('SITE_NAME'))
    define('SITE_NAME', 'TARGET');
if (!defined('SITE_EXPANSION'))
    define('SITE_EXPANSION', 'Tournament Administration and Registration Gateway for Event Tracking');
if (!defined('EVENT_TITLE'))
    define('EVENT_TITLE', '51st Tamil Nadu Shooting Championship');
if (!defined('EVENT_CODE'))
    define('EVENT_CODE', 'SSA51TN');
if (!defined('BASE_URL'))
    define('BASE_URL', 'http://localhost/ssa/');

// Session config
define('SESSION_TIMEOUT', 1800); // 30 minutes

/**
 * Returns a PDO database connection (singleton).
 */
function ensureRegistrationColumns(PDO $pdo): void
{
    // Auto-create certificates table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `certificates` (
              `id`             INT AUTO_INCREMENT PRIMARY KEY,
              `user_id`        INT NOT NULL,
              `event_name`     VARCHAR(255) NOT NULL,
              `category`       VARCHAR(50) NOT NULL DEFAULT 'NR',
              `certificate_path` VARCHAR(500) DEFAULT NULL,
              `issued_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_cert_user_event_cat` (`user_id`, `event_name`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}

    // Auto-create score_sheets table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `score_sheets` (
              `id`              INT AUTO_INCREMENT PRIMARY KEY,
              `lane_alloc_id`   INT NOT NULL,
              `grand_total_val` DECIMAL(8,2) DEFAULT NULL,
              `remarks`         VARCHAR(500) DEFAULT NULL,
              `sheet_data`      LONGTEXT DEFAULT NULL,
              `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_score_lane` (`lane_alloc_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}

    // Auto-create relay_schedules table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `relay_schedules` (
              `id`              INT AUTO_INCREMENT PRIMARY KEY,
              `event_name`      VARCHAR(255) NOT NULL,
              `schedule_name`   VARCHAR(255) DEFAULT '',
              `scheduled_date`  DATE NOT NULL,
              `relay_no`        INT NOT NULL,
              `reporting_time`  TIME NOT NULL,
              `start_time`      TIME NOT NULL,
              `relay_limit`     INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}    // Ensure lane_allocations & team_members columns exist
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `target_serial_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `custom_name` VARCHAR(255) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `bib_no` VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD `is_locked` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `registrations` ADD `disability_proof` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Disability certificate / proof path'"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `team_members` ADD COLUMN `lane_alloc_id` INT NULL DEFAULT NULL"); } catch (Throwable $t) {}{}

    // Auto-create championships table if missing
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `championships` (
              `id`                 INT AUTO_INCREMENT PRIMARY KEY,
              `championship_name`  VARCHAR(255) NOT NULL,
              `championship_year`  VARCHAR(10) NOT NULL,
              `status`             ENUM('ACTIVE', 'ARCHIVED', 'DRAFT') NOT NULL DEFAULT 'DRAFT',
              `registration_open`  DATE DEFAULT NULL,
              `registration_close` DATE DEFAULT NULL,
              `event_start`        DATE DEFAULT NULL,
              `event_end`          DATE DEFAULT NULL,
              `theme`              VARCHAR(100) DEFAULT 'gold_dark',
              `logo`               VARCHAR(255) DEFAULT NULL,
              `banner`             VARCHAR(255) DEFAULT NULL,
              `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM `championships`")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO `championships` (
                `id`, `championship_name`, `championship_year`, `status`, `registration_open`, `registration_close`, `event_start`, `event_end`, `theme`
            ) VALUES (
                1, '51st Tamil Nadu State Shooting Championship', '2026', 'ACTIVE', '2026-07-01', '2026-08-01', '2026-08-03', '2026-08-07', 'gold_dark'
            )");
        }
    } catch (Throwable $t) {}

    // Ensure championship_id column exists on dependent tables
    $tblsWithChampionship = [
        'event_registrations',
        'registration_sessions',
        'lane_allocations',
        'score_sheets',
        'certificates',
        'events',
        'manage_updates',
        'document_templates',
        'profile_change_requests'
    ];

    foreach ($tblsWithChampionship as $tbl) {
        try {
            $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `championship_id` INT NOT NULL DEFAULT 1 AFTER `id`");
        } catch (Throwable $t) {}
    }
    
    // Drop unique constraint on event_reg_id if it exists, and replace with a regular index to allow multiple relays
    try { $pdo->exec("ALTER TABLE `lane_allocations` DROP INDEX `event_reg_id`"); } catch (Throwable $t) {}
    try { $pdo->exec("ALTER TABLE `lane_allocations` ADD INDEX `idx_lane_allocations_event_reg_id` (`event_reg_id`)"); } catch (Throwable $t) {}
    // Auto-create admin_passcodes table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admin_passcodes` (
              `id`              INT AUTO_INCREMENT PRIMARY KEY,
              `admin_id`        INT NOT NULL,
              `passcode`        VARCHAR(50) NOT NULL,
              `allowed_modules` TEXT DEFAULT NULL,
              `allow_edit`      TINYINT(1) NOT NULL DEFAULT 0,
              `used_writes`     INT NOT NULL DEFAULT 0,
              `expires_at`      DATETIME NOT NULL,
              `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_passcode_admin` (`admin_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}

    // Self-healing: repair score sheets linked to dummy registrations if the real shooter has an active lane allocation
    try {
        $pdo->exec("
            UPDATE score_sheets ss
            JOIN lane_allocations la_old ON ss.lane_alloc_id = la_old.id
            JOIN event_registrations er_old ON la_old.event_reg_id = er_old.id
            JOIN registrations r_old ON er_old.user_id = r_old.id
            JOIN registrations r_real ON (
                r_real.reg_id = ss.custom_bib 
                OR CONCAT('5', LPAD(SUBSTRING_INDEX(r_real.reg_id, '-', -1), 4, '0')) = ss.custom_bib
                OR r_real.reg_id = r_old.reg_id
                OR (ss.custom_shooter_name IS NOT NULL AND ss.custom_shooter_name != '' AND TRIM(CONCAT(r_real.first_name, ' ', r_real.last_name)) = ss.custom_shooter_name)
            )
            JOIN event_registrations er_real ON er_real.user_id = r_real.id AND er_real.event_name = er_old.event_name AND er_real.category = er_old.category
            JOIN lane_allocations la_real ON la_real.event_reg_id = er_real.id
            SET ss.lane_alloc_id = la_real.id
            WHERE r_old.email LIKE 'manual_%'
              AND la_real.id != la_old.id
        ");
    } catch (Throwable $t) {}
    // Self-healing: clean up any orphaned virtual/manual registrations that aren't linked to allocations
    try {
        $pdo->exec("
            DELETE er FROM event_registrations er
            WHERE (er.event_reg_id LIKE 'MANUAL-EVT-%' OR er.event_reg_id LIKE 'VACANT-EVT-%')
              AND NOT EXISTS (SELECT 1 FROM lane_allocations la WHERE la.event_reg_id = er.id)
        ");
        $pdo->exec("
            DELETE r FROM registrations r
            WHERE (r.reg_id LIKE 'MANUAL-%' OR r.reg_id LIKE 'VACANT-%')
              AND NOT EXISTS (SELECT 1 FROM event_registrations er WHERE er.user_id = r.id)
        ");
    } catch (Throwable $t) {}{}

    // Auto-create competitor_cards table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `competitor_cards` (
              `id`           INT AUTO_INCREMENT PRIMARY KEY,
              `user_id`      INT NOT NULL,
              `card_path`    VARCHAR(500) DEFAULT NULL,
              `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_competitor_card_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}

    // Auto-create custom_field_values table if missing on VPS
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `custom_field_values` (
              `id`           INT AUTO_INCREMENT PRIMARY KEY,
              `user_id`      INT NOT NULL,
              `field_id`     VARCHAR(100) NOT NULL,
              `field_value`  TEXT DEFAULT NULL,
              `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_cfv_user_field` (`user_id`, `field_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t) {}
}

function ensureDocumentTemplatesTable(PDO $pdo): void
{
    // 1. Create table if not exists
    $pdo->exec("
            CREATE TABLE IF NOT EXISTS `document_templates` (
          `id`              INT(11)       NOT NULL AUTO_INCREMENT,
          `document_type`   VARCHAR(50)   NOT NULL UNIQUE,
          `background_path` VARCHAR(500)  NULL DEFAULT NULL,
          `canvas_data`     LONGTEXT      NOT NULL,
          `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Sync from config/document_templates.json if it exists
    $jsonFile = __DIR__ . '/document_templates.json';
    if (file_exists($jsonFile)) {
        $templates = json_decode(file_get_contents($jsonFile), true);
        if (is_array($templates)) {
            $stmt = $pdo->prepare("
                INSERT INTO document_templates (document_type, background_path, canvas_data)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE background_path = VALUES(background_path), canvas_data = VALUES(canvas_data)
            ");
            foreach ($templates as $docType => $data) {
                $bg = $data['background_path'] ?? null;
                $canvas = json_encode($data['canvas_data'] ?? []);
                $stmt->execute([$docType, $bg, $canvas]);
            }
        }
    }
}

function writeDocumentTemplateToJson(string $documentType, ?string $backgroundPath, array $canvasData): void
{
    $jsonFile = __DIR__ . '/document_templates.json';
    $templates = [];
    if (file_exists($jsonFile)) {
        $templates = json_decode(file_get_contents($jsonFile), true);
        if (!is_array($templates)) {
            $templates = [];
        }
    }
    $templates[$documentType] = [
        'background_path' => $backgroundPath,
        'canvas_data' => $canvasData
    ];
    file_put_contents($jsonFile, json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function ensureClubsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `clubs` (
          `id`                    INT AUTO_INCREMENT PRIMARY KEY,
          `club_name`             VARCHAR(255) NOT NULL UNIQUE,
          `admin_name`            VARCHAR(255) DEFAULT '-',
          `mobile_no`             VARCHAR(50) DEFAULT '-',
          `email`                 VARCHAR(255) DEFAULT '-',
          `tnsa_subscription_doc` VARCHAR(255) DEFAULT '-',
          `is_default`            TINYINT(1) DEFAULT 0,
          `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $jsonFile = __DIR__ . '/clubs.php';
    if (file_exists($jsonFile)) {
        $defaultClubs = require $jsonFile;
        if (is_array($defaultClubs)) {
            // INSERT IGNORE — only seeds clubs that don't already exist in DB.
            // This ensures clubs added via the admin panel and synced to clubs.php
            // via syncClubsToConfigFile() are preserved when another admin pulls
            // the repo. The DB is the source of truth; clubs.php is the seed file.
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO clubs (club_name, admin_name, mobile_no, email, tnsa_subscription_doc, is_default)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($defaultClubs as $club) {
                if (is_array($club)) {
                    $stmt->execute([
                        strtoupper($club['club_name']),
                        $club['admin_name'] ?? '-',
                        $club['mobile_no'] ?? '-',
                        $club['email'] ?? '-',
                        $club['tnsa_subscription_doc'] ?? '-',
                        $club['is_default'] ?? 0
                    ]);
                } else {
                    $stmt->execute([
                        strtoupper($club),
                        '-',
                        '-',
                        '-',
                        '-',
                        1
                    ]);
                }
            }
        }
    }
}

function syncClubsToConfigFile(PDO $pdo): void
{
    try {
        $clubs = $pdo->query("SELECT club_name, admin_name, mobile_no, email, tnsa_subscription_doc, is_default FROM clubs ORDER BY id ASC")->fetchAll();
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * config/clubs.php\n";
        $code .= " * Official registered clubs list for 51st Tamil Nadu Shooting Championship.\n";
        $code .= " */\n";
        $code .= "return [\n";
        foreach ($clubs as $c) {
            $code .= "  [\n";
            $code .= "    'club_name' => " . var_export($c['club_name'], true) . ",\n";
            $code .= "    'admin_name' => " . var_export($c['admin_name'], true) . ",\n";
            $code .= "    'mobile_no' => " . var_export($c['mobile_no'], true) . ",\n";
            $code .= "    'email' => " . var_export($c['email'], true) . ",\n";
            $code .= "    'tnsa_subscription_doc' => " . var_export($c['tnsa_subscription_doc'], true) . ",\n";
            $code .= "    'is_default' => " . var_export((int) $c['is_default'], true) . "\n";
            $code .= "  ],\n";
        }
        $code .= "];\n";
        file_put_contents(__DIR__ . '/clubs.php', $code);
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../db_connection_error.log', date('[Y-m-d H:i:s] ') . 'Clubs sync error: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function ensureFieldControlsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `field_controls` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `form_name` VARCHAR(50) NOT NULL,
          `field_id` VARCHAR(50) NOT NULL,
          `field_label` VARCHAR(255) NOT NULL,
          `is_enabled` TINYINT(1) DEFAULT 1,
          `is_mandatory` TINYINT(1) DEFAULT 1,
          `is_custom` TINYINT(1) DEFAULT 0,
          UNIQUE KEY `uk_form_field` (`form_name`, `field_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $nativeFields = [
        // Participant registration form
        ['registration', 'father_guardian_name', 'Father / Guardian Name', 1, 1, 0],
        ['registration', 'photo', 'Profile Photo', 1, 1, 0],
        ['registration', 'aadhaar_number', 'Aadhaar Card Number', 1, 1, 0],
        ['registration', 'address', 'Full Address', 1, 1, 0],
        ['registration', 'is_para', 'Para Athlete Toggle', 1, 1, 0],
        ['registration', 'is_deaf', 'Deaf Athlete Toggle', 1, 1, 0],
        ['registration', 'otp', 'Email Verification OTP', 1, 1, 0],
        // Event registration form
        ['event_registration', 'issf_number', 'Certificate / ISSF Number', 1, 1, 0],
        ['event_registration', 'mqs_score', 'MQS Score', 1, 1, 0],
        ['event_registration', 'certificate_path', 'Certificate Upload', 1, 1, 0],
        ['event_registration', 'shooting_year', 'Shooting Year', 1, 1, 0],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO field_controls (form_name, field_id, field_label, is_enabled, is_mandatory, is_custom)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($nativeFields as $field) {
        $stmt->execute($field);
    }
}

function ensureNotificationsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
          `id`           INT AUTO_INCREMENT PRIMARY KEY,
          `user_id`      INT NULL,
          `is_admin`     TINYINT(1) DEFAULT 0,
          `title`        VARCHAR(255) NOT NULL,
          `message`      TEXT NOT NULL,
          `redirect_url` VARCHAR(255) NOT NULL,
          `is_read`      TINYINT(1) DEFAULT 0,
          `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_notif_admin_read_created` (`is_admin`, `is_read`, `created_at` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $portPart = defined('DB_PORT') && DB_PORT ? ';port=' . DB_PORT : '';
        $dsn = sprintf(
            'mysql:host=%s%s;dbname=%s;charset=%s',
            DB_HOST,
            $portPart,
            DB_NAME,
            DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $initializedFile = __DIR__ . '/.db_initialized';
        $needsInit = !file_exists($initializedFile);
        $clubsFile = __DIR__ . '/clubs.php';
        $needsClubsSync = false;
        if (!$needsInit && file_exists($clubsFile) && file_exists($initializedFile)) {
            if (filemtime($clubsFile) > filemtime($initializedFile)) {
                $needsClubsSync = true;
            }
        }

        $templatesFile = __DIR__ . '/document_templates.json';
        $needsTemplatesSync = false;
        if (!$needsInit && file_exists($templatesFile) && file_exists($initializedFile)) {
            if (filemtime($templatesFile) > filemtime($initializedFile)) {
                $needsTemplatesSync = true;
            }
        }

        $retries = 3;
        while ($retries > 0) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                // Always run this — it's fully idempotent (CREATE TABLE IF NOT EXISTS, try/catch ALTERs)
                ensureRegistrationColumns($pdo);
                if ($needsInit) {
                    ensureDocumentTemplatesTable($pdo);
                    ensureClubsTable($pdo);
                    ensureFieldControlsTable($pdo);
                    ensureNotificationsTable($pdo);
                    @file_put_contents($initializedFile, '1');
                } else {
                    if ($needsClubsSync) {
                        ensureClubsTable($pdo);
                        @touch($initializedFile);
                    }
                    if ($needsTemplatesSync) {
                        ensureDocumentTemplatesTable($pdo);
                        @touch($initializedFile);
                    }
                }

                // Run automatic schema migrations (very fast — checks version number)
                require_once __DIR__ . '/migrations.php';
                runPendingMigrations($pdo);
                cleanupOrphanManualRegistrations($pdo);

                break;
            } catch (PDOException $e) {
                $retries--;
                file_put_contents(__DIR__ . '/../db_connection_error.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
                if ($retries === 0) {
                    die(json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']));
                }
                usleep(50000);
            }
        }
    }
    return $pdo;
}

/**
 * Generates a unique registration ID using the stored procedure.
 * Runs inside a transaction for concurrency safety.
 */
function generateRegId(): string
{
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->exec("CALL sp_generate_reg_id(@reg_id)");
        $row = $pdo->query("SELECT @reg_id AS reg_id")->fetch();
        $pdo->commit();
        if (!empty($row['reg_id'])) {
            return $row['reg_id'];
        }
    } catch (Exception $e) {
        $pdo->rollBack();
    }
    return '51' . str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
}

function getFieldControls(string $formName): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM field_controls WHERE form_name = ?");
        $stmt->execute([$formName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getCustomFieldValues(string $entityType, int $entityId): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT field_id, field_value FROM custom_field_values WHERE entity_type = ? AND entity_id = ?");
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Automatically cleans up any orphan manual/on-spot competitor records 
 * and orphan payment/registration sessions that no longer have active event registrations.
 */
function cleanupOrphanManualRegistrations(?PDO $pdo = null): void
{
    try {
        if ($pdo === null) {
            $pdo = getDB();
        }

        // 1. Delete manual/vacant event_registrations that are NOT linked to any lane_allocations
        $pdo->exec("
            DELETE er FROM event_registrations er
            JOIN registrations r ON er.user_id = r.id
            WHERE (r.reg_id LIKE 'MANUAL-%' OR r.email LIKE 'manual_%@ssa.com' OR er.event_reg_id LIKE 'MANUAL-EVT-%' OR r.reg_id LIKE 'VACANT-%' OR er.event_reg_id LIKE 'VACANT-EVT-%')
              AND NOT EXISTS (SELECT 1 FROM lane_allocations la WHERE la.event_reg_id = er.id)
        ");

        // 2. Delete manual/vacant registrations that have NO remaining event_registrations
        $pdo->exec("
            DELETE r FROM registrations r
            WHERE (r.reg_id LIKE 'MANUAL-%' OR r.email LIKE 'manual_%@ssa.com' OR r.father_guardian_name = 'MANUAL' OR r.reg_id LIKE 'VACANT-%')
              AND NOT EXISTS (SELECT 1 FROM event_registrations er WHERE er.user_id = r.id)
        ");

        // 3. Delete orphan registration_sessions that have NO remaining event_registrations
        $pdo->exec("
            DELETE rs FROM registration_sessions rs
            WHERE NOT EXISTS (
                SELECT 1 FROM event_registrations er 
                WHERE er.session_id = rs.session_id 
                   OR er.session_id = CAST(rs.id AS CHAR)
            )
        ");
    } catch (Exception $e) {
        // Silently ignore if tables do not exist yet
    }
}

/**
 * Safely formats a participant's full name, avoiding trailing spaces or dummy last names.
 */
function formatFullName(?string $firstName, ?string $lastName): string {
    $fn = trim($firstName ?? '');
    $ln = trim($lastName ?? '');
    if ($ln === '(Manual)' || $ln === '-') {
        $ln = '';
    }
    if (!empty($fn) && !empty($ln)) {
        return $fn . ' ' . $ln;
    }
    return !empty($fn) ? $fn : $ln;
}

/**
 * Resolves any input competitor ID (e.g. 51294, SSA-1294, 1294) to the canonical reg_id in database.
 */
function resolveRealRegId(PDO $pdo, string $input): string {
    $input = trim($input);
    if (empty($input)) return '';

    $stmt = $pdo->prepare("SELECT reg_id FROM registrations WHERE reg_id = ? LIMIT 1");
    $stmt->execute([$input]);
    $res = $stmt->fetchColumn();
    if ($res) return (string)$res;

    $digits = preg_replace('/[^0-9]/', '', $input);
    if (!empty($digits)) {
        $numPart = (strlen($digits) > 4 && str_starts_with($digits, '5')) ? substr($digits, 1) : $digits;
        $possibleIds = ['SSA-' . $numPart, 'SSA-' . $digits, $numPart, $digits];
        
        $inClause = implode(',', array_fill(0, count($possibleIds), '?'));
        $stmt = $pdo->prepare("SELECT reg_id FROM registrations WHERE reg_id IN ($inClause) LIMIT 1");
        $stmt->execute($possibleIds);
        $res = $stmt->fetchColumn();
        if ($res) return (string)$res;
    }

    return $input;
}

/**
 * Safely formats and returns the numeric 5-digit Bib / Registration number (e.g. 517745, 510001).
 * Returns '' (empty string) for manual / unassigned virtual slots or when no participant name is assigned.
 */
function formatBibNo(?string $regId, ?string $bibNo = null, ?string $firstName = null): string {
    if ($firstName !== null && trim($firstName) === '') {
        return '';
    }
    $raw = trim((string)($bibNo ?: $regId));
    if (empty($raw) || strpos($raw, 'MANUAL-') === 0 || strpos($raw, 'MANUAL') === 0) {
        return '';
    }
    if (preg_match('/^\d{5}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^SSA51TN-(?:[A-Z]+-)?(\d+)$/i', $raw, $m)) {
        $num = (int)$m[1];
        return '5' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
    if (preg_match('/(\d+)/', $raw, $m)) {
        $digits = $m[1];
        if (strlen($digits) >= 5) {
            return substr($digits, -5);
        }
        return '5' . str_pad($digits, 4, '0', STR_PAD_LEFT);
    }
    return '';
}

/**
 * Resolves the official match / event code (e.g. S017, S020, IS-18, S-30) for a given event name.
 */
function resolveEventCode(?string $eventName, ?string $eventCode = null, ?string $matchNo = null): string {
    $isLongSlug = function(string $s): bool {
        return strlen($s) > 15
            || preg_match('/\b(CHAMPIONSHIP|INDIVIDUAL|STANDARD|PISTOL|RIFLE|SENIOR|JUNIOR|WOMEN|MEN|AIR)\b/i', $s);
    };

    // 1. match_no — use if it's a short, clean ID
    $cleanMatch = trim((string)$matchNo);
    if (!empty($cleanMatch) && strpos(strtoupper($cleanMatch), 'EVT-') !== 0 && !$isLongSlug($cleanMatch)) {
        return $cleanMatch;
    }

    // 2. event_code — only if it's a short ID, not a long slug
    $cleanCode = trim((string)$eventCode);
    if (!empty($cleanCode) && strpos(strtoupper($cleanCode), 'EVT-') !== 0 && !$isLongSlug($cleanCode)) {
        return $cleanCode;
    }

    if (empty($eventName)) return '';

    $cleanEvt = strtoupper(trim($eventName));

    global $EVENTS_MAPPING;
    if (empty($EVENTS_MAPPING) && file_exists(__DIR__ . '/events.php')) {
        require_once __DIR__ . '/events.php';
    }

    if (!empty($EVENTS_MAPPING)) {
        // 3a. Exact match against key or value
        foreach ($EVENTS_MAPPING as $code => $fullName) {
            if (!preg_match('/^[A-Z]+-\d+$/', $code)) continue;
            if (strcasecmp($code, $cleanEvt) === 0 || strcasecmp($fullName, $cleanEvt) === 0) {
                return $code;
            }
        }

        // 3b. Inline IS-/S-/N-/R- style ID embedded in event name
        if (preg_match('/\b(IS[-_]?\d{1,3}|S[-_]?\d{1,3}|N[-_]?\d{1,3}|R[-_]?\d{1,3})\b/i', $cleanEvt, $m)) {
            return strtoupper(str_replace(['_', ' '], '-', $m[1]));
        }

        // 3c. Keyword-score reverse lookup
        $keyWords = ['JUNIOR','YOUTH','SENIOR','MASTER','SUPER','MEN','WOMEN',
                     'ISSF','NR','AIR','PISTOL','RIFLE','STANDARD','10M','25M','50M',
                     'SH1','SH2','DEAF','PARA'];
        $evtWords = array_flip(array_filter(explode(' ', preg_replace('/[^A-Z0-9 ]/', ' ', $cleanEvt))));
        $best = ''; $bestScore = -999;
        foreach ($EVENTS_MAPPING as $code => $fullName) {
            if (!preg_match('/^[A-Z]+-\d+$/', $code)) continue;
            $labelWords = array_flip(array_filter(explode(' ', strtoupper(preg_replace('/[^A-Z0-9 ]/', ' ', $fullName)))));
            $score = 0;
            foreach ($keyWords as $kw) {
                $inEvt   = isset($evtWords[$kw]);
                $inLabel = isset($labelWords[$kw]);
                if ($inEvt && $inLabel)   $score += 2;
                elseif ($inEvt !== $inLabel) $score -= 1;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $code; }
        }
        if (!empty($best) && $bestScore > 0) return $best;
    }

    return '';
}

/**
 * Gets or creates a shared vacant competitor and event registration for empty/placeholder slots.
 */
function getOrCreateVacantEventRegId(PDO $pdo, string $eventName, string $category, string $clubName = 'VACANT'): int {
    $clubClean = trim($clubName);
    if ($clubClean === '') {
        $clubClean = 'VACANT';
    }
    
    // Create a unique, deterministic reg_id for this club placeholder (max 20 characters)
    $clubSlug = substr(preg_replace('/[^a-zA-Z0-9]/', '', strtolower($clubClean)), 0, 12);
    $regId = 'VACANT-' . $clubSlug;
    if (strlen($regId) > 20) {
        $regId = substr($regId, 0, 20);
    }
    
    // Check if registration exists
    $stmt = $pdo->prepare("SELECT id FROM registrations WHERE reg_id = ? LIMIT 1");
    $stmt->execute([$regId]);
    $user = $stmt->fetch();
    if (!$user) {
        // Generate a deterministic unique Aadhaar number from the club name to satisfy NOT NULL & UNIQUE constraints
        $hash = md5($clubClean);
        $aadhaar = '99' . substr(preg_replace('/[^0-9]/', '', $hash), 0, 10);
        if (strlen($aadhaar) < 12) {
            $aadhaar = str_pad($aadhaar, 12, '0');
        } else {
            $aadhaar = substr($aadhaar, 0, 12);
        }
        
        $email = 'vacant_' . $clubSlug . '@ssa.com';
        
        $stmtReg = $pdo->prepare("
            INSERT INTO registrations (reg_id, first_name, last_name, email, phone, aadhaar_number, club_name, district, association, father_guardian_name, address, status, is_verified, dob, gender, password_hash)
            VALUES (?, 'VACANT', '', ?, '0000000000', ?, ?, 'CHENNAI', 'TNSA', 'VACANT', 'VACANT', 'active', 1, '2000-01-01', 'Male', 'dummy')
        ");
        $stmtReg->execute([$regId, $email, $aadhaar, $clubClean]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['id'];
    }

    // Get or create event registration for this user, event, and category
    $stmtEv = $pdo->prepare("SELECT id FROM event_registrations WHERE user_id = ? AND event_name = ? AND category = ? LIMIT 1");
    $stmtEv->execute([$userId, $eventName, $category]);
    $ev = $stmtEv->fetch();
    if ($ev) {
        return (int)$ev['id'];
    }

    $dummyEvRegId = 'VACANT-EVT-' . bin2hex(random_bytes(6));
    $evtCode = substr(strtoupper(preg_replace('/[^A-Z0-9]/', '-', $eventName)), 0, 15);
    $dummyWeaponType = (stripos($eventName, 'pistol') !== false) ? 'Pistol' : 'Rifle';
    
    $stmtEvReg = $pdo->prepare("
        INSERT INTO event_registrations (user_id, event_reg_id, category, event_code, event_name, status, entry_fee, weapon_type, age_group)
        VALUES (?, ?, ?, ?, ?, 'approved', 0, ?, 'Senior')
    ");
    $stmtEvReg->execute([$userId, $dummyEvRegId, $category, $evtCode, $eventName, $dummyWeaponType]);
    return (int)$pdo->lastInsertId();
}

/**
 * Returns the currently ACTIVE championship details array.
 */
if (!function_exists('getActiveChampionship')) {
    function getActiveChampionship(?PDO $pdo = null): array {
        if (!$pdo) {
            try {
                $pdo = getDB();
            } catch (Throwable $t) {
                $pdo = null;
            }
        }
        
        $row = null;
        if ($pdo) {
            try {
                if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_championship_id'])) {
                    $stmtSess = $pdo->prepare("SELECT * FROM `championships` WHERE `id` = ? LIMIT 1");
                    $stmtSess->execute([(int)$_SESSION['active_championship_id']]);
                    $row = $stmtSess->fetch(PDO::FETCH_ASSOC);
                }

                if (!$row) {
                    $stmt = $pdo->query("SELECT * FROM `championships` WHERE `status` = 'ACTIVE' LIMIT 1");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if (!$row) {
                    // Fallback to first record
                    $stmtFallback = $pdo->query("SELECT * FROM `championships` ORDER BY id ASC LIMIT 1");
                    $row = $stmtFallback->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $t) {}

            // Merge with event_info details if available
            try {
                $evStmt = $pdo->query("SELECT * FROM `event_info` WHERE id = 1 LIMIT 1");
                $evRow = $evStmt->fetch(PDO::FETCH_ASSOC);
                if ($evRow) {
                    if ($row) {
                        $row = array_merge($evRow, $row); // Championship properties override event_info defaults
                        if (empty($row['org_name'])) $row['org_name'] = $evRow['org_name'] ?? 'TARGET';
                        if (empty($row['event_code'])) $row['event_code'] = $evRow['event_code'] ?? 'SSA51TN';
                        if (empty($row['venue'])) $row['venue'] = $evRow['venue'] ?? 'Chennai';
                    } else {
                        $row = $evRow;
                    }
                }
            } catch (Throwable $t) {}
        }

        if ($row) {
            // Ensure essential defaults exist
            if (empty($row['championship_name'])) $row['championship_name'] = $row['event_title'] ?? 'Tamil Nadu State Shooting Championship';
            if (empty($row['event_code'])) $row['event_code'] = 'SSA51TN';
            if (empty($row['org_name']) || $row['org_name'] === 'Saragarhi Shooting Academy') $row['org_name'] = 'TARGET';
            if (empty($row['championship_year'])) $row['championship_year'] = date('Y');
            return $row;
        }

        return [
            'id' => 1,
            'championship_name' => '51st Tamil Nadu State Shooting Championship',
            'event_code' => 'SSA51TN',
            'org_name' => 'TARGET',
            'championship_year' => '2026',
            'status' => 'ACTIVE',
            'registration_open' => '2026-07-01',
            'registration_close' => '2026-08-01',
            'event_start' => '2026-08-03',
            'event_end' => '2026-08-07',
            'theme' => 'gold_dark',
            'logo' => null,
            'banner' => null,
            'venue' => 'Chennai'
        ];
    }
}

/**
 * Returns a championship details array by ID.
 */
if (!function_exists('getChampionshipById')) {
    function getChampionshipById(int $id, ?PDO $pdo = null): ?array {
        if (!$pdo) {
            try {
                $pdo = getDB();
            } catch (Throwable $t) {
                return null;
            }
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM `championships` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $t) {
            return null;
        }
    }
}

/**
 * Returns minimum registration age based on age category rules for the active championship.
 */
if (!function_exists('getMinRegistrationAge')) {
    function getMinRegistrationAge(?PDO $pdo = null): int {
        if (!$pdo) {
            try { $pdo = getDB(); } catch (Throwable $t) { return 0; }
        }
        $activeChampionship = getActiveChampionship($pdo);
        $activeCid = (int)($activeChampionship['id'] ?? 1);
        try {
            $stmt = $pdo->prepare("SELECT MIN(min_age) FROM age_category_rules WHERE championship_id = ? AND min_age IS NOT NULL");
            $stmt->execute([$activeCid]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return max(0, (int)$val);
            }
            // Fallback query if none found for activeCid specifically
            $stmtFallback = $pdo->query("SELECT MIN(min_age) FROM age_category_rules WHERE min_age IS NOT NULL");
            $valFb = $stmtFallback->fetchColumn();
            if ($valFb !== false && $valFb !== null) {
                return max(0, (int)$valFb);
            }
        } catch (Throwable $t) {}
        return 0;
    }
}



