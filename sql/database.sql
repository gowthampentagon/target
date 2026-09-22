-- ============================================================
-- SSA Championship - COMPLETE CONSOLIDATED DATABASE INITIALIZATION SCRIPT
-- Safe to import at any time - DOES NOT drop existing tables or delete existing data.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Create and use database if not exists
CREATE DATABASE IF NOT EXISTS `ssachampionship` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ssachampionship`;

-- ============================================================
-- TABLE: registrations
-- ============================================================
CREATE TABLE IF NOT EXISTS `registrations` (
  `id`                          INT(11)       NOT NULL AUTO_INCREMENT,
  `reg_id`                      VARCHAR(20)   NULL DEFAULT NULL COMMENT 'Assigned when user registers for an event',
  `first_name`                  VARCHAR(100)  NOT NULL,
  `last_name`                   VARCHAR(100)  NOT NULL,
  `email`                       VARCHAR(180)  NOT NULL UNIQUE,
  `phone`                       VARCHAR(15)   NOT NULL,
  `aadhaar_number`              VARCHAR(12)   NOT NULL UNIQUE,
  `is_para`                     TINYINT(1)    NOT NULL DEFAULT 0,
  `is_deaf`                     TINYINT(1)    NOT NULL DEFAULT 0,
  `club_name`                   VARCHAR(200)  NOT NULL,
  `club_name_pending`           VARCHAR(200)  NULL DEFAULT NULL,
  `club_name_change_pending`    TINYINT(1)    DEFAULT 0,
  `club_name_change_approved_at` DATETIME     NULL DEFAULT NULL,
  `membership_id`               VARCHAR(100)  NULL DEFAULT NULL,
  `membership_id_pending`       VARCHAR(100)  NULL DEFAULT NULL,
  `membership_id_change_pending` TINYINT(1)  DEFAULT 0,
  `membership_id_change_approved_at` DATETIME NULL DEFAULT NULL,
  `membership_doc`              VARCHAR(500)  NULL DEFAULT NULL,
  `membership_doc_pending`      VARCHAR(500)  NULL DEFAULT NULL,
  `membership_doc_change_pending` TINYINT(1) DEFAULT 0,
  `membership_doc_change_approved_at` DATETIME NULL DEFAULT NULL,
  `dob`                         DATE          NOT NULL,
  `gender`                      ENUM('Male','Female','Transgender') NOT NULL,
  `district`                    VARCHAR(100)  NOT NULL,
  `association`                 VARCHAR(200)  NOT NULL,
  `association_pending`         VARCHAR(200)  NULL DEFAULT NULL,
  `association_change_pending`  TINYINT(1)    DEFAULT 0,
  `association_change_approved_at` DATETIME   NULL DEFAULT NULL,
  `father_guardian_name`        VARCHAR(200)  NOT NULL,
  `address`                     TEXT          NOT NULL,
  `photo`                       VARCHAR(500)  NULL DEFAULT NULL,
  `disability_proof`            VARCHAR(500)  NULL DEFAULT NULL COMMENT 'Disability certificate / proof path',
  `password_hash`               VARCHAR(255)  NOT NULL,
  `reset_token`                 VARCHAR(64)   NULL DEFAULT NULL,
  `reset_token_expiry`          DATETIME      NULL DEFAULT NULL,
  `is_verified`                 TINYINT(1)    NOT NULL DEFAULT 1,
  `status`                      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email`   (`email`),
  INDEX `idx_reg_id`  (`reg_id`),
  INDEX `idx_phone`   (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: login_attempts  (brute-force protection)
-- ============================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(180) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email_ip` (`email`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: championships
-- Holds multi-championship configurations controlled by Supreme Admin
-- ============================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `championships` (
  `id`, `championship_name`, `championship_year`, `status`, `registration_open`, `registration_close`, `event_start`, `event_end`, `theme`
) VALUES (
  1, '51st Tamil Nadu State Shooting Championship', '2026', 'ACTIVE', '2026-07-01', '2026-08-01', '2026-08-03', '2026-08-07', 'gold_dark'
) ON DUPLICATE KEY UPDATE `championship_name` = VALUES(`championship_name`);

-- ============================================================
-- TABLE: event_info
-- ============================================================
CREATE TABLE IF NOT EXISTS `event_info` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `event_code`   VARCHAR(20)  NOT NULL DEFAULT 'SSA51TN',
  `event_title`  VARCHAR(300) NOT NULL DEFAULT '51st Tamil Nadu Shooting Championship',
  `org_name`     VARCHAR(300) NOT NULL DEFAULT 'TARGET',
  `event_date`   DATE         NULL DEFAULT NULL,
  `venue`        VARCHAR(400) NULL DEFAULT NULL,
  `last_seq`     INT(11)      NOT NULL DEFAULT 0,
  `reg_start_active` TINYINT(1) DEFAULT 0,
  `reg_start_date` DATETIME DEFAULT NULL,
  `reg_end_active` TINYINT(1) DEFAULT 0,
  `reg_end_date` DATETIME DEFAULT NULL,
  `triple_entry_active` TINYINT(1) DEFAULT 0,
  `triple_entry_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: admins
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(180) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','superadmin') NOT NULL DEFAULT 'admin',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default superadmin: superadmin@ssa.com / SuperAdmin@123
INSERT INTO `admins` (`name`, `email`, `password_hash`, `role`)
SELECT 'System Superadmin', 'superadmin@ssa.com',
        '$2y$12$B.JKv3jNwBArcmDeZ2AkXOahB8I5W3rE9PUoEujdVyxdUMH/gY2Ne', 'superadmin'
WHERE NOT EXISTS (SELECT * FROM `admins` WHERE `email` = 'superadmin@ssa.com');

-- ============================================================
-- TABLE: custom_weapon_types
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_weapon_types` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `weapon_type` VARCHAR(100) NOT NULL UNIQUE,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: registration_sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `registration_sessions` (
  `id`                INT          NOT NULL AUTO_INCREMENT,
  `session_id`        VARCHAR(30)  NOT NULL UNIQUE,
  `user_id`           INT          NOT NULL,
  `total_amount`      DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `payment_screenshot` VARCHAR(500) NULL DEFAULT NULL,
  `payment_status`    ENUM('unpaid','uploaded','verified') NOT NULL DEFAULT 'unpaid',
  `approval_status`   ENUM('pending','approved','rejected','draft') NOT NULL DEFAULT 'pending',
  `admin_remarks`     TEXT         NULL DEFAULT NULL,
  `approved_by`       INT          NULL DEFAULT NULL,
  `approved_at`       DATETIME     NULL DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: event_registrations
-- ============================================================
CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `event_reg_id`     VARCHAR(30)   NOT NULL UNIQUE,
  `user_id`          INT(11)       NOT NULL,
  `session_id`       INT           NULL,
  `category`         ENUM('ISSF','NR','PARA_DEAF','NR_MQS') NOT NULL,
  `event_code`       VARCHAR(30)   NOT NULL,
  `event_name`       VARCHAR(200)  NOT NULL,
  `weapon_type`      VARCHAR(100)  NOT NULL,
  `age_group`        ENUM('Sub Youth','Youth','Junior','Senior','Master','Senior Master','Super Master') NOT NULL,
  `match_no`         VARCHAR(30)   NULL DEFAULT NULL,
  `competition_name` VARCHAR(200)  NULL DEFAULT NULL,
  `certificate_path` VARCHAR(500)  NULL DEFAULT NULL,
  `shooting_year`    VARCHAR(20)   NULL DEFAULT NULL,
  `issf_number`      VARCHAR(50)   NULL DEFAULT NULL,
  `mqs_score`        DECIMAL(6,2)  NULL DEFAULT NULL,
  `best_score`       DECIMAL(6,2)  NULL DEFAULT NULL,
  `disability_type`  VARCHAR(200)  NULL DEFAULT NULL,
  `classification`   VARCHAR(100)  NULL DEFAULT NULL,
  `entry_fee`        DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  `status`           ENUM('pending','approved','rejected','draft') NOT NULL DEFAULT 'pending',
  `admin_remarks`    TEXT          NULL DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
  INDEX `idx_evtreg_user`     (`user_id`),
  INDEX `idx_evtreg_category` (`category`),
  INDEX `idx_evtreg_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add FK constraint safely
DROP PROCEDURE IF EXISTS AddSessionConstraint;
DELIMITER $$
CREATE PROCEDURE AddSessionConstraint()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_evtreg_session'
    ) THEN
        ALTER TABLE `event_registrations`
          ADD CONSTRAINT `fk_evtreg_session`
          FOREIGN KEY (`session_id`) REFERENCES `registration_sessions`(`id`) ON DELETE CASCADE;
    END IF;
END$$
DELIMITER ;
CALL AddSessionConstraint();
DROP PROCEDURE AddSessionConstraint;

-- ============================================================
-- TABLE: lane_allocations
-- ============================================================
CREATE TABLE IF NOT EXISTS `lane_allocations` (
  `id`             INT(11)     NOT NULL AUTO_INCREMENT,
  `event_reg_id`   INT(11)     NOT NULL UNIQUE,
  `bib_no`         VARCHAR(30) NULL DEFAULT NULL,
  `custom_name`    VARCHAR(255) NULL DEFAULT NULL,
  `relay_no`       INT(11)     NOT NULL,
  `lane_no`        INT(11)     NOT NULL,
  `scheduled_date` DATE        NOT NULL,
  `reporting_time` TIME        NOT NULL,
  `start_time`     TIME        NOT NULL,
  `score_sheet`    VARCHAR(255) NULL DEFAULT NULL,
  `created_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_reg_id`) REFERENCES `event_registrations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: score_sheets
-- ============================================================
CREATE TABLE IF NOT EXISTS `score_sheets` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `lane_alloc_id`       INT(11)       NOT NULL,
  `meta_card`           VARCHAR(255)  NULL DEFAULT NULL,
  `meta_match`          VARCHAR(255)  NULL DEFAULT NULL,
  `series_data`         TEXT          NULL DEFAULT NULL,
  `total_val`           VARCHAR(50)   NULL DEFAULT NULL,
  `penalty_val`         VARCHAR(50)   NULL DEFAULT NULL,
  `grand_total_val`     VARCHAR(50)   NULL DEFAULT NULL,
  `custom_shooter_name` VARCHAR(255)  NULL DEFAULT NULL,
  `custom_bib`          VARCHAR(50)   NULL DEFAULT NULL,
  `custom_category`     VARCHAR(50)   NULL DEFAULT NULL,
  `custom_detail`       VARCHAR(100)  NULL DEFAULT NULL,
  `custom_lane`         VARCHAR(50)   NULL DEFAULT NULL,
  `custom_date`         VARCHAR(50)   NULL DEFAULT NULL,
  `custom_time`         VARCHAR(50)   NULL DEFAULT NULL,
  `remarks`             VARCHAR(255)  NULL DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_score_lane` (`lane_alloc_id`),
  CONSTRAINT `fk_scoresheets_lane` FOREIGN KEY (`lane_alloc_id`) REFERENCES `lane_allocations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: relay_schedules
-- ============================================================
CREATE TABLE IF NOT EXISTS `relay_schedules` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `schedule_name`  VARCHAR(200) NOT NULL DEFAULT '',
  `event_name`     VARCHAR(200) NOT NULL DEFAULT '',
  `scheduled_date` DATE         NOT NULL,
  `relay_no`       INT(11)      NOT NULL,
  `reporting_time` TIME         NOT NULL,
  `start_time`     TIME         NOT NULL,
  `relay_limit`    INT(11)      NULL DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_event`    (`event_name`, `schedule_name`),
  INDEX `idx_template` (`schedule_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: profile_change_requests
-- ============================================================
CREATE TABLE IF NOT EXISTS `profile_change_requests` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)      NOT NULL,
  `field_name`       VARCHAR(50)  NOT NULL,
  `old_value`        TEXT         NULL DEFAULT NULL,
  `new_value`        TEXT         NULL DEFAULT NULL,
  `status`           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by`      INT(11)      NULL DEFAULT NULL,
  `approved_at`      DATETIME     NULL DEFAULT NULL,
  `rejection_reason` TEXT         NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_status`  (`user_id`, `status`),
  INDEX `idx_field_status` (`field_name`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: teams
-- ============================================================
CREATE TABLE IF NOT EXISTS `teams` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `event_name`  VARCHAR(200) NOT NULL,
  `category`    VARCHAR(30)  NOT NULL,
  `team_name`   VARCHAR(100) NOT NULL,
  `total_score` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_team_event` (`event_name`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: team_members
-- ============================================================
CREATE TABLE IF NOT EXISTS `team_members` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `team_id`       INT(11)      NOT NULL,
  `enrollment_id` VARCHAR(50)  NOT NULL,
  `shooter_name`  VARCHAR(255) NOT NULL,
  `club_name`     VARCHAR(255) NOT NULL,
  `score`         DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `lane_alloc_id` INT(11)      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lane_alloc_id`) REFERENCES `lane_allocations`(`id`) ON DELETE SET NULL,
  INDEX `idx_member_enroll` (`enrollment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: document_templates
-- ============================================================
CREATE TABLE IF NOT EXISTS `document_templates` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `document_type`   VARCHAR(50)   NOT NULL UNIQUE COMMENT 'competitor_card, certificate, start_sheet, score_sheet, rank_list',
  `background_path` VARCHAR(500)  NULL DEFAULT NULL,
  `canvas_data`     LONGTEXT      NOT NULL COMMENT 'JSON string containing canvas elements, styling, fields',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: clubs
-- ============================================================
CREATE TABLE IF NOT EXISTS `clubs` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `club_name`             VARCHAR(255) NOT NULL UNIQUE,
  `admin_name`            VARCHAR(255) DEFAULT '-',
  `mobile_no`             VARCHAR(50) DEFAULT '-',
  `email`                 VARCHAR(255) DEFAULT '-',
  `tnsa_subscription_doc` VARCHAR(255) DEFAULT '-',
  `is_default`            TINYINT(1) DEFAULT 0,
  `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: field_controls
-- ============================================================
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

-- ============================================================
-- TABLE: notifications
-- ============================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: custom_field_values
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_field_values` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `entity_type`  VARCHAR(50)  NOT NULL,
  `entity_id`    INT(11)      NOT NULL,
  `field_id`     VARCHAR(50)  NOT NULL,
  `field_value`  TEXT         NULL DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entity_field` (`entity_type`, `entity_id`, `field_id`),
  INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delimiter procedure sp_generate_reg_id
DROP PROCEDURE IF EXISTS `sp_generate_reg_id`;
DELIMITER $$
CREATE PROCEDURE `sp_generate_reg_id`(OUT p_reg_id VARCHAR(20))
BEGIN
  DECLARE v_exists INT DEFAULT 1;
  DECLARE v_rand_id VARCHAR(20);

  WHILE v_exists > 0 DO
    SET v_rand_id = CAST(FLOOR(510000 + (RAND() * 10000)) AS CHAR);
    SELECT COUNT(*) INTO v_exists FROM `registrations` WHERE `reg_id` = v_rand_id;
  END WHILE;

  SET p_reg_id = v_rand_id;
END$$
DELIMITER ;

-- Delimiter procedure sp_generate_event_reg_id
DROP PROCEDURE IF EXISTS `sp_generate_event_reg_id`;
DELIMITER $$
CREATE PROCEDURE `sp_generate_event_reg_id`(
  IN  p_category VARCHAR(20),
  OUT p_reg_id   VARCHAR(30)
)
BEGIN
  DECLARE v_seq INT;
  SELECT COALESCE(MAX(id), 0) + 1 INTO v_seq
    FROM event_registrations WHERE category = p_category;
  SET p_reg_id = CONCAT('SSA51TN-', p_category, '-', LPAD(v_seq, 4, '0'));
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
-- Dynamic Active Seeds (Dumped from local configuration)

-- Event Info Seeding
INSERT INTO `event_info` (`id`, `event_code`, `event_title`, `org_name`, `event_date`, `venue`, `last_seq`) VALUES (1, 'SSA51TN', '51st Tamil Nadu Shooting Championship', 'TARGET', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `event_title` = VALUES(`event_title`), `org_name` = VALUES(`org_name`), `event_date` = VALUES(`event_date`), `venue` = VALUES(`venue`);

-- Document Templates Seeding (Your custom canvas designs & background assets)
INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('competitor_card', NULL, '{"width":794,"height":1122,"orientation":"portrait","pageBorderStyle":"solid","pageBorderWidth":1,"pageBorderColor":"#000000","pageBorderRadius":0,"pageBorderInset":15,"elements":[{"id":"header_text","type":"text","content":"TAMILNADU shooting ASSOCIATION","fontFamily":"Cinzel","fontSize":22,"color":"#000000","fontWeight":"bold","textAlign":"center","x":150,"y":60,"width":500,"height":40,"zIndex":10},{"id":"header_line","type":"line","backgroundColor":"#000000","x":60,"y":125,"width":674,"height":2,"zIndex":10},{"id":"card_title","type":"text","content":"Competitor Card","fontFamily":"Cinzel","fontSize":24,"color":"#000000","fontWeight":"bold","textAlign":"center","x":84,"y":158,"width":594,"height":35,"zIndex":10},{"id":"competitor_no_text","type":"text","content":"COMPETITOR NO. {competitor_no}","fontFamily":"Inter","fontSize":11,"color":"#000000","fontWeight":"bold","textAlign":"left","x":41.33333333333333,"y":220.33333333333334,"width":250,"height":25,"zIndex":10},{"id":"photo","type":"photo","x":605.3333333333333,"y":166,"width":110,"height":135,"borderRadius":4,"borderWidth":1,"borderColor":"#cccccc","borderStyle":"solid","zIndex":10},{"id":"reg_id_text","type":"text","content":"{reg_id}","fontFamily":"Inter","fontSize":11,"color":"#000000","fontWeight":"normal","textAlign":"center","x":605.3333333333334,"y":310,"width":110,"height":20,"zIndex":10},{"id":"body_text","type":"text","content":"Certified that <strong>{participant_name}</strong> of<br><strong>({club_name})</strong><br>is competitor competing in the<br><strong>51ST TAMILNADU STATE SHOOTING CHAMPIONSHIP COMPETITION 2026 (RIFLE/PISTOL) NR EVENTS</strong><br>to be held at <strong>Chennai Rifle Club</strong> from <strong>03-08-2026 to 07-08-2026</strong><br>and is competing in following Events","fontFamily":"Inter","fontSize":13,"color":"#222222","fontWeight":"normal","textAlign":"center","x":166.66666666666669,"y":274.6666666666667,"width":450,"height":140,"zIndex":10,"borderStyle":"none","borderWidth":0,"borderColor":"#000000","borderRadius":0,"lockRatio":false,"backgroundColor":"transparent"},{"id":"events_table","type":"table","x":60,"y":465.33333333333337,"width":700.6666666666666,"height":114.66666666666664,"headers":[{"text":"Sr. No.","style":{"backgroundColor":"#0d7cf2","color":"#f4f5f6","fontWeight":"bold","fontFamily":"Inter","fontSize":12},"rowspan":1,"colspan":1},{"text":"Event No.","style":{"backgroundColor":"#0d7cf2","color":"#f8fafc","fontWeight":"bold","fontFamily":"Inter","fontSize":12},"rowspan":1,"colspan":1},{"text":"Event Name","style":{"backgroundColor":"#0d7cf2","color":"#f9fafa","fontWeight":"bold","fontFamily":"Inter","fontSize":12},"rowspan":1,"colspan":1}],"fontFamily":"Inter","fontSize":12,"color":"#000000","gridStyle":"horizontal","borderColor":"#de0d0d","backgroundColor":"#ffffff","zIndex":10,"rows":[[{"text":"101","rowspan":1,"colspan":1,"style":[]},{"text":"Competitor Alpha","rowspan":1,"colspan":1,"style":[]},{"text":"95","rowspan":1,"colspan":1,"style":[]},{"text":"195","rowspan":1,"colspan":1,"style":[]}]],"borderStyle":"solid","borderWidth":0,"borderRadius":0,"lockRatio":false},{"id":"qr_code","type":"image","src":"https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={reg_id}","x":72,"y":781.3333333333333,"width":140,"height":140,"zIndex":10},{"id":"sig_line","type":"line","backgroundColor":"#aaaaaa","x":560,"y":810,"width":170,"height":1,"zIndex":10},{"id":"sig_text","type":"text","content":"<span style=\\"font-family:''Dancing Script'', cursive; font-size:16px;\\"></span><br><span style=\\"font-size:11px; font-weight:bold;\\">Gowtham K<br>SECRETARY</span>","fontFamily":"Inter","fontSize":11,"color":"#000000","fontWeight":"normal","textAlign":"center","x":545.3333333333334,"y":823,"width":170,"height":55,"zIndex":10,"borderStyle":"none","borderWidth":0,"borderColor":"#000000","borderRadius":0,"lockRatio":false,"backgroundColor":"transparent"},{"id":"image_1784429483540","type":"image","x":65.33333333333333,"y":44,"width":70.66666666666667,"height":70.66666666666667,"zIndex":22,"borderWidth":0,"borderColor":"#000000","borderStyle":"none","borderRadius":0,"lockRatio":true,"src":"uploads/logos/logo_1784429483_571.jpg"}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('certificate', NULL, '{"width":794,"height":1122,"orientation":"portrait","pageBorderStyle":"double","pageBorderWidth":8,"pageBorderColor":"#c9a84c","pageBorderRadius":0,"pageBorderInset":15,"elements":[{"id":"title","type":"text","content":"TAMILNADU SHOOTING ASSOCIATION","fontFamily":"Inter","fontSize":28,"color":"#000000","fontWeight":"bold","textAlign":"center","x":-50.66666666666667,"y":54,"width":922,"height":40,"zIndex":10},{"id":"logo","type":"image","src":"images/logo.png","x":355.66666666666663,"y":103.33333333333333,"width":80,"height":80,"zIndex":10},{"id":"cert_no","type":"text","content":"No. TRG/CERT/T.N. - {cert_no}","fontFamily":"Inter","fontSize":13,"fontWeight":"bold","color":"#000000","x":49.333333333333336,"y":146.66666666666666,"width":350,"height":25,"zIndex":10},{"id":"date","type":"text","content":"Date: {date}","fontFamily":"Inter","fontSize":13,"fontWeight":"bold","color":"#000000","textAlign":"right","x":822,"y":140,"width":200,"height":25,"zIndex":10},{"id":"cert_large","type":"text","content":"Certificate","fontFamily":"Cinzel","fontSize":48,"color":"#111111","textAlign":"center","x":-76.00000000000001,"y":198.66666666666666,"width":922,"height":60,"zIndex":10},{"id":"certified_that","type":"text","content":"CERTIFIED THAT","fontFamily":"Inter","fontSize":11,"color":"#c9a84c","fontWeight":"bold","textAlign":"center","x":-77.33333333333333,"y":267.6666666666667,"width":922,"height":20,"zIndex":10},{"id":"name","type":"text","content":"<span style="font-family:''Dancing Script'', cursive; font-size:32px;">{participant_name}</span>","fontFamily":"Inter","fontSize":24,"color":"#111111","textAlign":"center","x":-78.66666666666666,"y":301.00000000000006,"width":922,"height":45,"zIndex":10},{"id":"photo","type":"photo","x":603.3333333333333,"y":143.33333333333334,"width":80,"height":100,"borderRadius":2,"borderWidth":1,"borderColor":"#cccccc","borderStyle":"solid","zIndex":10},{"id":"body","type":"text","content":"(Competitor No. {competitor_no}) of<br><strong>{club_name}</strong><br>affiliated to<br><span style="color:#c9a84c; font-weight:bold;">TAMILNADU SHOOTING ASSOCIATION, TAMILNADU</span><br>has participated in the<br><strong>50TH TAMILNADU STATE SHOOTING CHAMPIONSHIP COMPETITION 2025 (RIFLE/PISTOL) NR EVENTS</strong><br>held at <strong>CHENNAI RIFLE CLUB</strong><br>From <strong>6TH SEPTEMBER To 14TH SEPTEMBER 2025</strong> and obtained the following results","fontFamily":"Inter","fontSize":13,"color":"#222222","textAlign":"center","x":-62.66666666666668,"y":365.3333333333333,"width":922,"height":150,"zIndex":10},{"id":"results_table","type":"table","x":93.33333333333333,"y":581.3333333333334,"width":645.9999999999999,"height":95.99999999999997,"headers":[{"text":"SR.NO.","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1},{"text":"EVENT","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1},{"text":"MQS","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1},{"text":"SCORE","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1},{"text":"POSITION","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1},{"text":"REMARKS","style":{"fontWeight":"bold","backgroundColor":"#a19b9b"},"rowspan":1,"colspan":1}],"fontFamily":"Inter","fontSize":11,"color":"#000000","gridStyle":"horizontal","borderColor":"#e0e0e0","backgroundColor":"#ffffff","zIndex":10,"rows":[[{"text":"101","rowspan":1,"colspan":1,"style":{"backgroundColor":"#fcf7f7"}},{"text":"Competitor Alpha","rowspan":1,"colspan":1,"style":[]},{"text":"95","rowspan":1,"colspan":1,"style":[]},{"text":"195","rowspan":1,"colspan":1,"style":[]}]]},{"id":"bottom_logo","type":"image","src":"images/logo.png","x":609.3333333333334,"y":859.3333333333334,"width":60,"height":60,"zIndex":10},{"id":"sig_line","type":"line","backgroundColor":"#aaaaaa","x":562,"y":933.3333333333334,"width":170,"height":1,"zIndex":10},{"id":"sig_text","type":"text","content":"<span style="font-family:''Dancing Script'', cursive; font-size:16px;">S. Velshankar</span><br><span style="font-size:11px; font-weight:bold;">S VELSHANKAR<br>SECRETARY</span>","fontFamily":"Inter","fontSize":11,"color":"#000000","fontWeight":"normal","textAlign":"center","x":559.3333333333334,"y":913,"width":170,"height":55,"zIndex":10},{"id":"footer_address","type":"text","content":"TAMILNADU SHOOTING ASSOCIATION, C/O MADURAI RIFLE CLUB, SHOOTING SPORTS COMPLEX, RACE COURSE ROAD, MADURAI - 625007.","fontFamily":"Inter","fontSize":9,"color":"#555555","textAlign":"center","x":-50.66666666666668,"y":1042.3333333333335,"width":922,"height":20,"zIndex":10}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('start_sheet', NULL, '{"width":794,"height":1122,"orientation":"portrait","elements":[{"id":"title","type":"text","x":50,"y":40,"width":694,"height":50,"content":"MASTER START SHEET","fontFamily":"Cinzel","fontSize":28,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"table","type":"table","x":50,"y":120,"width":694,"height":800,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#000000"}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('score_sheet', NULL, '{"width":794,"height":1122,"orientation":"portrait","elements":[{"id":"title","type":"text","x":50,"y":40,"width":694,"height":50,"content":"OFFICIAL SCORE SHEET","fontFamily":"Cinzel","fontSize":28,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"table","type":"table","x":50,"y":150,"width":694,"height":700,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#000000"}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('rank_list', NULL, '{"width":794,"height":1122,"orientation":"portrait","elements":[{"id":"title","type":"text","x":50,"y":40,"width":694,"height":50,"content":"OFFICIAL RANK LIST","fontFamily":"Cinzel","fontSize":28,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"table","type":"table","x":50,"y":120,"width":694,"height":850,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#000000"}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('score_sheet_air_rifle_pistol', NULL, '{"width":794,"height":1122,"orientation":"portrait","background":"#0d0e12","elements":[{"id":"logo","type":"image","x":50,"y":30,"width":68,"height":68,"src":"images/logo.png","zIndex":10},{"id":"text_academy_name","type":"text","x":130,"y":30,"width":614,"height":35,"content":"SARAGARHI SHOOTING ACADEMY","fontFamily":"Cinzel","fontSize":22,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_tagline","type":"text","x":130,"y":65,"width":614,"height":20,"content":"PRECISION u2022 VALOUR u2022 DISCIPLINE","fontFamily":"Inter","fontSize":12,"color":"#aaaaaa","fontWeight":"normal","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_event_label","type":"text","x":130,"y":85,"width":614,"height":25,"content":"10m Air Rifle (ISSF) u2014 Official Score Sheet","fontFamily":"Inter","fontSize":14,"color":"#ffffff","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_name","type":"text","x":50,"y":130,"width":250,"height":30,"content":"Name: {participant_name}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_bib","type":"text","x":310,"y":130,"width":150,"height":30,"content":"Bib: {reg_id}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_category","type":"text","x":470,"y":130,"width":274,"height":30,"content":"Category: {category}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"table","type":"table","x":50,"y":180,"width":694,"height":700,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#ffffff","backgroundColor":"#16171e","borderColor":"#cccccc","borderWidth":1,"borderStyle":"solid","gridStyle":"full","headers":[{"text":"SERIES","rowspan":1,"colspan":1,"style":[]},{"text":"1","rowspan":1,"colspan":1,"style":[]},{"text":"2","rowspan":1,"colspan":1,"style":[]},{"text":"3","rowspan":1,"colspan":1,"style":[]},{"text":"4","rowspan":1,"colspan":1,"style":[]},{"text":"5","rowspan":1,"colspan":1,"style":[]},{"text":"6","rowspan":1,"colspan":1,"style":[]},{"text":"7","rowspan":1,"colspan":1,"style":[]},{"text":"8","rowspan":1,"colspan":1,"style":[]},{"text":"9","rowspan":1,"colspan":1,"style":[]},{"text":"10","rowspan":1,"colspan":1,"style":[]},{"text":"TOTAL","rowspan":1,"colspan":1,"style":[]}],"rows":[[{"text":"Series 1","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 2","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 3","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 4","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 5","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 6","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Penalty","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Grand Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}]]}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('score_sheet_centre_fire_pistol', NULL, '{"width":794,"height":1122,"orientation":"portrait","background":"#0d0e12","elements":[{"id":"logo","type":"image","x":50,"y":30,"width":68,"height":68,"src":"images/logo.png","zIndex":10},{"id":"text_academy_name","type":"text","x":130,"y":30,"width":614,"height":35,"content":"SARAGARHI SHOOTING ACADEMY","fontFamily":"Cinzel","fontSize":22,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_tagline","type":"text","x":130,"y":65,"width":614,"height":20,"content":"PRECISION u2022 VALOUR u2022 DISCIPLINE","fontFamily":"Inter","fontSize":12,"color":"#aaaaaa","fontWeight":"normal","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_event_label","type":"text","x":130,"y":85,"width":614,"height":25,"content":"25m Centre Fire Pistol u2014 Official Score Sheet","fontFamily":"Inter","fontSize":14,"color":"#ffffff","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_name","type":"text","x":50,"y":130,"width":250,"height":30,"content":"Name: {participant_name}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_bib","type":"text","x":310,"y":130,"width":150,"height":30,"content":"Bib: {reg_id}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_category","type":"text","x":470,"y":130,"width":274,"height":30,"content":"Category: {category}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"table","type":"table","x":50,"y":180,"width":694,"height":500,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#ffffff","backgroundColor":"#16171e","borderColor":"#cccccc","borderWidth":1,"borderStyle":"solid","gridStyle":"full","headers":[{"text":"SERIES","rowspan":1,"colspan":1,"style":[]},{"text":"1","rowspan":1,"colspan":1,"style":[]},{"text":"2","rowspan":1,"colspan":1,"style":[]},{"text":"3","rowspan":1,"colspan":1,"style":[]},{"text":"4","rowspan":1,"colspan":1,"style":[]},{"text":"5","rowspan":1,"colspan":1,"style":[]},{"text":"6","rowspan":1,"colspan":1,"style":[]},{"text":"7","rowspan":1,"colspan":1,"style":[]},{"text":"8","rowspan":1,"colspan":1,"style":[]},{"text":"9","rowspan":1,"colspan":1,"style":[]},{"text":"10","rowspan":1,"colspan":1,"style":[]},{"text":"TOTAL","rowspan":1,"colspan":1,"style":[]}],"rows":[[{"text":"Precision","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Duelling","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Penalty","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Grand Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}]]}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('score_sheet_rifle_prone', NULL, '{"width":794,"height":1122,"orientation":"portrait","background":"#0d0e12","elements":[{"id":"logo","type":"image","x":50,"y":30,"width":68,"height":68,"src":"images/logo.png","zIndex":10},{"id":"text_academy_name","type":"text","x":130,"y":30,"width":614,"height":35,"content":"SARAGARHI SHOOTING ACADEMY","fontFamily":"Cinzel","fontSize":22,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_tagline","type":"text","x":130,"y":65,"width":614,"height":20,"content":"PRECISION u2022 VALOUR u2022 DISCIPLINE","fontFamily":"Inter","fontSize":12,"color":"#aaaaaa","fontWeight":"normal","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_event_label","type":"text","x":130,"y":85,"width":614,"height":25,"content":"50m Rifle Prone u2014 Official Score Sheet","fontFamily":"Inter","fontSize":14,"color":"#ffffff","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_name","type":"text","x":50,"y":130,"width":250,"height":30,"content":"Name: {participant_name}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_bib","type":"text","x":310,"y":130,"width":150,"height":30,"content":"Bib: {reg_id}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_category","type":"text","x":470,"y":130,"width":274,"height":30,"content":"Category: {category}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"table","type":"table","x":50,"y":180,"width":694,"height":700,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#ffffff","backgroundColor":"#16171e","borderColor":"#cccccc","borderWidth":1,"borderStyle":"solid","gridStyle":"full","headers":[{"text":"SERIES","rowspan":1,"colspan":1,"style":[]},{"text":"1","rowspan":1,"colspan":1,"style":[]},{"text":"2","rowspan":1,"colspan":1,"style":[]},{"text":"3","rowspan":1,"colspan":1,"style":[]},{"text":"4","rowspan":1,"colspan":1,"style":[]},{"text":"5","rowspan":1,"colspan":1,"style":[]},{"text":"6","rowspan":1,"colspan":1,"style":[]},{"text":"7","rowspan":1,"colspan":1,"style":[]},{"text":"8","rowspan":1,"colspan":1,"style":[]},{"text":"9","rowspan":1,"colspan":1,"style":[]},{"text":"10","rowspan":1,"colspan":1,"style":[]},{"text":"TOTAL","rowspan":1,"colspan":1,"style":[]}],"rows":[[{"text":"Series 1","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 2","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 3","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 4","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 5","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Series 6","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Penalty","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Grand Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}]]}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);

INSERT INTO `document_templates` (`document_type`, `background_path`, `canvas_data`) VALUES ('score_sheet_standard_pistol', NULL, '{"width":794,"height":1122,"orientation":"portrait","background":"#0d0e12","elements":[{"id":"logo","type":"image","x":50,"y":30,"width":68,"height":68,"src":"images/logo.png","zIndex":10},{"id":"text_academy_name","type":"text","x":130,"y":30,"width":614,"height":35,"content":"SARAGARHI SHOOTING ACADEMY","fontFamily":"Cinzel","fontSize":22,"color":"#c9a84c","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_tagline","type":"text","x":130,"y":65,"width":614,"height":20,"content":"PRECISION u2022 VALOUR u2022 DISCIPLINE","fontFamily":"Inter","fontSize":12,"color":"#aaaaaa","fontWeight":"normal","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_event_label","type":"text","x":130,"y":85,"width":614,"height":25,"content":"25m Standard Pistol u2014 Official Score Sheet","fontFamily":"Inter","fontSize":14,"color":"#ffffff","fontWeight":"bold","fontStyle":"normal","textAlign":"center","zIndex":10},{"id":"text_name","type":"text","x":50,"y":130,"width":250,"height":30,"content":"Name: {participant_name}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_bib","type":"text","x":310,"y":130,"width":150,"height":30,"content":"Bib: {reg_id}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"text_category","type":"text","x":470,"y":130,"width":274,"height":30,"content":"Category: {category}","fontFamily":"Inter","fontSize":12,"color":"#ffffff","fontWeight":"normal","fontStyle":"normal","textAlign":"left","zIndex":10},{"id":"table","type":"table","x":50,"y":180,"width":694,"height":550,"zIndex":10,"fontFamily":"Inter","fontSize":12,"color":"#ffffff","backgroundColor":"#16171e","borderColor":"#cccccc","borderWidth":1,"borderStyle":"solid","gridStyle":"full","headers":[{"text":"SERIES","rowspan":1,"colspan":1,"style":[]},{"text":"1","rowspan":1,"colspan":1,"style":[]},{"text":"2","rowspan":1,"colspan":1,"style":[]},{"text":"3","rowspan":1,"colspan":1,"style":[]},{"text":"4","rowspan":1,"colspan":1,"style":[]},{"text":"5","rowspan":1,"colspan":1,"style":[]},{"text":"6","rowspan":1,"colspan":1,"style":[]},{"text":"7","rowspan":1,"colspan":1,"style":[]},{"text":"8","rowspan":1,"colspan":1,"style":[]},{"text":"9","rowspan":1,"colspan":1,"style":[]},{"text":"10","rowspan":1,"colspan":1,"style":[]},{"text":"TOTAL","rowspan":1,"colspan":1,"style":[]}],"rows":[[{"text":"150 sec","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"20 sec","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"10 sec","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]},{"text":"u2014","rowspan":1,"colspan":1,"style":[]}],[{"text":"Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Penalty","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}],[{"text":"Grand Total","rowspan":1,"colspan":11,"style":[]},{"text":"-","rowspan":1,"colspan":1,"style":[]}]]}]}')
ON DUPLICATE KEY UPDATE `background_path` = VALUES(`background_path`), `canvas_data` = VALUES(`canvas_data`);


-- Field Controls Seeding
INSERT INTO `field_controls` (`id`, `form_name`, `field_id`, `field_label`, `is_enabled`, `is_mandatory`, `is_custom`) VALUES
(1, 'registration', 'father_guardian_name', 'Father / Guardian Name', 1, 1, 0),
(2, 'registration', 'photo', 'Profile Photo', 1, 1, 0),
(3, 'registration', 'aadhaar_number', 'Aadhaar Card Number', 1, 1, 0),
(4, 'registration', 'address', 'Full Address', 1, 1, 0),
(5, 'registration', 'is_para', 'Para Athlete Toggle', 1, 1, 0),
(6, 'registration', 'is_deaf', 'Deaf Athlete Toggle', 1, 1, 0),
(7, 'registration', 'otp', 'Email Verification OTP', 1, 0, 0),
(8, 'event_registration', 'issf_number', 'Certificate / ISSF Number', 1, 1, 0),
(9, 'event_registration', 'mqs_score', 'MQS Score', 1, 1, 0),
(10, 'event_registration', 'shooting_year', 'Shooting Year', 1, 1, 0),
(911, 'event_registration', 'certificate_path', 'Certificate Upload', 1, 1, 0)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`), `is_mandatory` = VALUES(`is_mandatory`), `field_label` = VALUES(`field_label`);

-- Clubs Seeding
INSERT INTO `clubs` (`id`, `club_name`, `admin_name`, `mobile_no`, `email`, `tnsa_subscription_doc`, `is_default`, `created_at`) VALUES
(1, 'ACE SHOOTERS CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(2, 'ANUGRAHAA SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(3, 'ARUN''S AIR GUN SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(4, 'BELL RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(5, 'BORN SHOOTERS ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(6, 'BRIGADE SHOOTERS CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(7, 'BT SPORTS ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(8, 'BULLSEYE BREAK CLUB MADURAI NORTH', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(9, 'BULLSEYE SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(10, 'CHAKRA VYUGAM RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(11, 'CHENGALPATTU RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(12, 'CHENNAI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(13, 'CHERA SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(14, 'COIMBATORE RIFLE ASSOCIATION', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(15, 'DELIGHT RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(16, 'DHEERAM SHOOT & SPORT ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(17, 'DINDIGUL DISTRICT SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(18, 'EAST COAST SPORTS', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(19, 'ELITZUR ACADEMY OF SPORTS', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(20, 'ERODE DISTRICT MARKSMAN ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(21, 'ERODE RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(22, 'GC SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(23, 'GOBICHETTIPALAYAM RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(24, 'GOLDEN TRIGGER RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(25, 'GOOD SHEPHERD RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(26, 'H.V.F SPORTS CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(27, 'HEAD QUARTERS, NCC DIRECTORATE', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(28, 'HOSUR RIFLE COACHING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(29, 'IDAPPADI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(30, 'INSIGHT SPORTS ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(31, 'KANYAKUMARI DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(32, 'KONGUNADU RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(33, 'KPR AIR RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(34, 'KUMBAKONAM RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(35, 'MADURAI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(36, 'MAYILAI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(37, 'MISSION ACADEMY OF SHOOTING SPORTS', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(38, 'NAGAPATTINAM DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(39, 'NAMAKKAL SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(40, 'NAMBIYUR RIFLE CLUB ASSOCIATION', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(41, 'NANDHU SPORTS ACADEMY, DHARMAPURI', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(42, 'NEHRU RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(43, 'NEYVELI AIR RIFLE SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(44, 'ORDNANCE FACTORY TIRUCHIRAPALLI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(45, 'PERAMBALUR DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(46, 'PLATO''S RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(47, 'PRABHAKAR SHOOTING RANGE CHENNAI', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(48, 'QUEEN MIRA INTERNATIONAL SCHOOL', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(49, 'RAJAPALAYAM RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(50, 'RAMANATHAPURAM RIFLE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(51, 'RIFLE SHOOTING ASSOCIATION KARUR', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(52, 'RIVER VIEW SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(53, 'ROYAL PUDUKKOTAI SPORTS CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(54, 'SACHIDANANDA JOTHI NIKETHAN INTERNATIONAL SCHOOL', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(55, 'SALEM DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(56, 'SARAGARHI SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(57, 'SARVODAYA SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(58, 'SDAT SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(59, 'SPIDER AIR RIFLE SOCIETY KARUR', '-', '-', '-', '-', 1, '2026-07-18 13:38:22'),
(60, 'SPIDER SHOOTING SOCEITY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(61, 'SRI RAMACHANDRA AIR RIFLE & PISTOL SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(62, 'STUDENTS SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(63, 'SUMEET''S AIR GUN SPORTS ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(64, 'TAMBARAM AMATEUR SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(65, 'TAMILNADU POLICE ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(66, 'TAMILNADU POLICE SPORTS CONTROL BOARD', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(67, 'TENKASI DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(68, 'TENKASI SPORTS RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(69, 'THANJAVUR AIR GUN SHOOTING ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(70, 'THANJAVUR RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(71, 'THE CADET ACADEMY', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(72, 'THE MEPCO SCHLENK ENGINEERING COLLEGE AIR RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(73, 'THE NILGIRIS RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(74, 'THE TIRUPPUR DISTRICT AIR RIFLE SHOOTING CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(75, 'THENI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(76, 'THIRUVARUR DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(77, 'THULLIYAM RIFLE CLUB, CHENNAI', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(78, 'TIRUCHIRAPALLI DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(79, 'TIRUNELVELI DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(80, 'TNPL RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(81, 'TRICHY RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(82, 'TUTICORIN RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(83, 'V SQUARE SPORTS CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(84, 'VELLORE RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(85, 'VICTORY NELLAI RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(86, 'VIRUDHANAGAR DISTRICT RIFLE CLUB', '-', '-', '-', '-', 1, '2026-07-18 13:38:23'),
(87, 'YMCA INDOOR SHOOTING RANGE', '-', '-', '-', '-', 1, '2026-07-18 13:38:23')
ON DUPLICATE KEY UPDATE `admin_name` = VALUES(`admin_name`), `mobile_no` = VALUES(`mobile_no`), `email` = VALUES(`email`), `tnsa_subscription_doc` = VALUES(`tnsa_subscription_doc`);

-- ============================================================
-- TABLE: events
-- ============================================================
CREATE TABLE IF NOT EXISTS `events` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `event_code`  VARCHAR(50) NOT NULL UNIQUE,
  `event_name`  VARCHAR(255) NOT NULL,
  `category`    VARCHAR(50) NOT NULL DEFAULT 'NR',
  `age_group`   VARCHAR(50) DEFAULT 'Senior',
  `entry_fee`   DECIMAL(10,2) DEFAULT 0.00,
  `mqs_score`   DECIMAL(6,2) DEFAULT NULL,
  `status`      VARCHAR(20) DEFAULT 'active',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

