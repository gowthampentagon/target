-- ============================================================
-- Saragarhi Shooting Academy (SSA) Master Consolidated Schema
-- Database Name: ssachampionship
-- Complete, Single SQL Dump for Production / Cloud Import
-- Compatible with MySQL 5.7+, MySQL 8.0, MariaDB, AWS RDS, GCP, cPanel, phpMyAdmin
-- ============================================================

CREATE DATABASE IF NOT EXISTS `ssachampionship` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ssachampionship`;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- 1. Table structure for `admins`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(180) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin') NOT NULL DEFAULT 'admin',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Data for `admins`
INSERT INTO `admins` (`id`, `name`, `email`, `password_hash`, `role`) VALUES
(1, 'System Superadmin', 'superadmin@ssa.com', '$2y$12$B.JKv3jNwBArcmDeZ2AkXOahB8I5W3rE9PUoEujdVyxdUMH/gY2Ne', 'superadmin')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- --------------------------------------------------------
-- 2. Table structure for `admin_activity_log`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `admin_activity_log`;
CREATE TABLE `admin_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  CONSTRAINT `fk_admin_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Table structure for `admin_passcodes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `admin_passcodes`;
CREATE TABLE `admin_passcodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `passcode_hash` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Table structure for `login_attempts`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_attempt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. Table structure for `registrations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `registrations`;
CREATE TABLE `registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reg_id` varchar(20) DEFAULT NULL COMMENT 'Auto-generated Registration ID',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(180) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `aadhaar_number` varchar(12) NOT NULL,
  `is_para` tinyint(1) NOT NULL DEFAULT 0,
  `is_deaf` tinyint(1) NOT NULL DEFAULT 0,
  `club_name` varchar(200) NOT NULL,
  `club_name_pending` varchar(200) DEFAULT NULL,
  `club_name_change_pending` tinyint(1) DEFAULT 0,
  `club_name_change_approved_at` datetime DEFAULT NULL,
  `membership_id` varchar(100) DEFAULT NULL,
  `membership_id_pending` varchar(100) DEFAULT NULL,
  `membership_id_change_pending` tinyint(1) DEFAULT 0,
  `membership_id_change_approved_at` datetime DEFAULT NULL,
  `membership_doc` varchar(500) DEFAULT NULL,
  `membership_doc_pending` varchar(500) DEFAULT NULL,
  `membership_doc_change_pending` tinyint(1) DEFAULT 0,
  `membership_doc_change_approved_at` datetime DEFAULT NULL,
  `dob` date NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('Male','Female','Transgender') NOT NULL,
  `district` varchar(100) NOT NULL,
  `association` varchar(200) NOT NULL,
  `association_pending` varchar(200) DEFAULT NULL,
  `association_change_pending` tinyint(1) DEFAULT 0,
  `association_change_approved_at` datetime DEFAULT NULL,
  `father_guardian_name` varchar(200) NOT NULL,
  `address` text NOT NULL,
  `photo` varchar(500) DEFAULT NULL,
  `disability_proof` varchar(500) DEFAULT NULL COMMENT 'Disability certificate path',
  `password_hash` varchar(255) NOT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `aadhaar_number` (`aadhaar_number`),
  KEY `idx_email` (`email`),
  KEY `idx_reg_id` (`reg_id`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Table structure for `registration_sessions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `registration_sessions`;
CREATE TABLE `registration_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `payment_screenshot` varchar(500) DEFAULT NULL,
  `payment_status` enum('unpaid','uploaded','verified') NOT NULL DEFAULT 'unpaid',
  `approval_status` enum('pending','approved','rejected','draft') NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `registration_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `registration_sessions_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. Table structure for `event_registrations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `event_registrations`;
CREATE TABLE `event_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) DEFAULT NULL,
  `event_reg_id` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category` enum('ISSF','NR','PARA_DEAF','NR_MQS') NOT NULL DEFAULT 'NR',
  `event_code` varchar(100) NOT NULL,
  `match_no` varchar(50) DEFAULT NULL,
  `event_name` varchar(255) NOT NULL,
  `weapon_type` varchar(100) NOT NULL,
  `age_group` varchar(50) NOT NULL,
  `issf_number` varchar(50) DEFAULT NULL,
  `mqs_score` decimal(6,2) DEFAULT NULL,
  `best_score` decimal(6,2) DEFAULT NULL,
  `shooting_year` int(11) DEFAULT NULL,
  `competition_name` varchar(255) DEFAULT NULL,
  `certificate_path` varchar(500) DEFAULT NULL,
  `entry_fee` decimal(8,2) NOT NULL DEFAULT 500.00,
  `disability_type` varchar(100) DEFAULT NULL,
  `classification` varchar(100) DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_reg_id` (`event_reg_id`),
  KEY `user_id` (`user_id`),
  KEY `session_id` (`session_id`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_category` (`category`),
  KEY `idx_event_name` (`event_name`),
  CONSTRAINT `event_registrations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_registrations_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `registration_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_registrations_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. Table structure for `lane_allocations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `lane_allocations`;
CREATE TABLE `lane_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_reg_id` int(11) NOT NULL,
  `bib_no` varchar(20) NOT NULL,
  `target_serial_no` int(11) DEFAULT NULL,
  `relay_no` int(11) NOT NULL,
  `lane_no` int(11) NOT NULL,
  `scheduled_date` date NOT NULL,
  `reporting_time` time NOT NULL,
  `start_time` time NOT NULL,
  `custom_name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_reg_id` (`event_reg_id`),
  KEY `idx_schedule` (`scheduled_date`,`relay_no`,`lane_no`),
  CONSTRAINT `lane_allocations_ibfk_1` FOREIGN KEY (`event_reg_id`) REFERENCES `event_registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 9. Table structure for `score_sheets`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `score_sheets`;
CREATE TABLE `score_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lane_alloc_id` int(11) NOT NULL,
  `meta_card` varchar(255) DEFAULT NULL,
  `meta_match` varchar(255) DEFAULT NULL,
  `series_data` text DEFAULT NULL,
  `total_val` varchar(50) DEFAULT NULL,
  `penalty_val` varchar(50) DEFAULT NULL,
  `grand_total_val` varchar(50) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `edit_count` int(11) NOT NULL DEFAULT 0,
  `shooter_signature` longtext DEFAULT NULL,
  `official_signature` longtext DEFAULT NULL,
  `custom_shooter_name` varchar(255) DEFAULT NULL,
  `custom_bib` varchar(50) DEFAULT NULL,
  `custom_category` varchar(50) DEFAULT NULL,
  `custom_detail` varchar(100) DEFAULT NULL,
  `custom_lane` varchar(50) DEFAULT NULL,
  `custom_date` varchar(50) DEFAULT NULL,
  `custom_time` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_score_lane` (`lane_alloc_id`),
  CONSTRAINT `fk_scoresheets_lane` FOREIGN KEY (`lane_alloc_id`) REFERENCES `lane_allocations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 10. Table structure for `relay_schedules`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `relay_schedules`;
CREATE TABLE `relay_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_name` varchar(200) NOT NULL DEFAULT '',
  `event_name` varchar(200) NOT NULL DEFAULT '',
  `scheduled_date` date NOT NULL,
  `relay_no` int(11) NOT NULL,
  `reporting_time` time NOT NULL,
  `start_time` time NOT NULL,
  `relay_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_name`,`schedule_name`),
  KEY `idx_template` (`schedule_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. Table structure for `teams`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(200) NOT NULL,
  `category` varchar(30) NOT NULL,
  `team_name` varchar(100) NOT NULL,
  `club_name` varchar(200) DEFAULT NULL,
  `total_score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team_event` (`event_name`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 12. Table structure for `team_members`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `team_members`;
CREATE TABLE `team_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `bib_no` varchar(30) DEFAULT NULL,
  `enrollment_id` varchar(50) NOT NULL,
  `shooter_name` varchar(255) NOT NULL,
  `club_name` varchar(255) NOT NULL,
  `score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `lane_alloc_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  KEY `lane_alloc_id` (`lane_alloc_id`),
  KEY `idx_member_enroll` (`enrollment_id`),
  CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`lane_alloc_id`) REFERENCES `lane_allocations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 13. Table structure for `profile_change_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `profile_change_requests`;
CREATE TABLE `profile_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `field_name` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_field_status` (`field_name`,`status`),
  CONSTRAINT `profile_change_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profile_change_requests_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 14. Table structure for `document_templates`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `document_templates`;
CREATE TABLE `document_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_type` varchar(50) NOT NULL,
  `background_path` varchar(255) DEFAULT NULL,
  `canvas_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type` (`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 15. Table structure for `certificates`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `certificates`;
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_reg_id` int(11) NOT NULL,
  `certificate_no` varchar(50) NOT NULL,
  `issued_date` date NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificate_no` (`certificate_no`),
  KEY `user_id` (`user_id`),
  KEY `event_reg_id` (`event_reg_id`),
  CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`event_reg_id`) REFERENCES `event_registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 16. Table structure for `competitor_cards`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `competitor_cards`;
CREATE TABLE `competitor_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `card_no` varchar(50) NOT NULL,
  `issued_date` date NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_no` (`card_no`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `competitor_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 17. Table structure for `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `redirect_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_admin` (`is_admin`,`is_read`),
  KEY `idx_notif_user` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 18. Table structure for `custom_weapon_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `custom_weapon_types`;
CREATE TABLE `custom_weapon_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_weapon_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 19. Table structure for `event_info`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `event_info`;
CREATE TABLE `event_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_code` varchar(50) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `weapon_type` varchar(100) DEFAULT NULL,
  `fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_event_info_code` (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;