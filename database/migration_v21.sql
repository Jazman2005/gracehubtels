-- ============================================================
-- Gracehubtels v2.1 Migration — Schema Updates
-- Run this file once via phpMyAdmin or mysql CLI
-- ============================================================

-- -------------------------------------------------------
-- [10] FIX TIMEZONE — Set default timezone
-- -------------------------------------------------------
SET time_zone = '+01:00';

-- -------------------------------------------------------
-- [16] ADD user_type TO users table
-- -------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `user_type` ENUM('regular','vendor') NOT NULL DEFAULT 'regular' AFTER `email`;

-- -------------------------------------------------------
-- [16] ADD user_type TO service_pricing table
-- -------------------------------------------------------
ALTER TABLE `service_pricing`
  ADD COLUMN `user_type` ENUM('regular','vendor','both') NOT NULL DEFAULT 'both' AFTER `is_active`;

-- -------------------------------------------------------
-- [16] ADD user_type TO data_plans table (if exists)
-- -------------------------------------------------------
ALTER TABLE `data_plans`
  ADD COLUMN `user_type` ENUM('regular','vendor','both') NOT NULL DEFAULT 'both' AFTER `is_active`;

-- -------------------------------------------------------
-- [17] TRANSACTION LIMITS TABLE
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transaction_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `service` VARCHAR(50) NOT NULL,
  `min_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `max_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `daily_limit` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `user_type` ENUM('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `service_type` (`service`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default limits for regular users
INSERT IGNORE INTO `transaction_limits` (`service`, `min_amount`, `max_amount`, `daily_limit`, `user_type`) VALUES
('airtime', 50, 100000, 500000, 'regular'),
('data', 100, 50000, 300000, 'regular'),
('electricity', 500, 100000, 500000, 'regular'),
('cabletv', 500, 50000, 300000, 'regular'),
('bank_transfer', 100, 500000, 2000000, 'regular'),
('airtime_to_cash', 500, 50000, 200000, 'regular'),
('exam_pin', 500, 50000, 200000, 'regular'),
('recharge_card', 100, 5000, 200000, 'regular'),
('data_card', 100, 50000, 300000, 'regular');

-- Vendor limits (higher)
INSERT IGNORE INTO `transaction_limits` (`service`, `min_amount`, `max_amount`, `daily_limit`, `user_type`) VALUES
('airtime', 50, 500000, 2000000, 'vendor'),
('data', 100, 300000, 1000000, 'vendor'),
('electricity', 500, 500000, 2000000, 'vendor'),
('cabletv', 500, 200000, 1000000, 'vendor'),
('bank_transfer', 100, 2000000, 5000000, 'vendor'),
('airtime_to_cash', 500, 200000, 500000, 'vendor'),
('exam_pin', 500, 200000, 500000, 'vendor'),
('recharge_card', 100, 20000, 500000, 'vendor'),
('data_card', 100, 200000, 1000000, 'vendor');

-- -------------------------------------------------------
-- [17] PER-USER LIMIT OVERRIDES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `service` VARCHAR(50) NOT NULL,
  `max_amount` DECIMAL(12,2) DEFAULT NULL,
  `daily_limit` DECIMAL(12,2) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `user_service` (`user_id`, `service`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- [17] LOGIN TRACKER
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_tracker` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `login_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `user_id` (`user_id`),
  INDEX `login_at` (`login_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- [14] ADMIN ROLES TABLE
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(50) NOT NULL UNIQUE,
  `permissions` JSON NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default roles
INSERT IGNORE INTO `admin_roles` (`role_name`, `permissions`) VALUES
('superadmin', '["all"]'),
('admin', '["dashboard","users_view","users_edit","transactions_view","kyc_review","services","notifications","profit_view"]'),
('assistant', '["dashboard","users_view","transactions_view","kyc_review","notifications"]'),
('support', '["users_view","kyc_review","notifications"]');

-- -------------------------------------------------------
-- [14] ADD permissions + created_by TO admin table
-- -------------------------------------------------------
ALTER TABLE `admin`
  ADD COLUMN `permissions` JSON DEFAULT NULL AFTER `role`,
  ADD COLUMN `created_by` INT DEFAULT NULL AFTER `permissions`,
  ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `created_by`;

-- -------------------------------------------------------
-- [17] ADD login_tracker_id TO users for quick reference
-- -------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `last_login_id` INT DEFAULT NULL AFTER `last_login_ip`;

-- -------------------------------------------------------
-- Add user_type TO cable_plans for vendor/regular pricing
-- -------------------------------------------------------
ALTER TABLE `cable_plans`
  ADD COLUMN `user_type` ENUM('regular','vendor','both') NOT NULL DEFAULT 'both' AFTER `is_active`;

-- -------------------------------------------------------
-- Add user_type TO electricity_discos for vendor/regular fees
-- -------------------------------------------------------
ALTER TABLE `electricity_discos`
  ADD COLUMN `user_type` ENUM('regular','vendor','both') NOT NULL DEFAULT 'both' AFTER `is_active`;

-- -------------------------------------------------------
-- Add virtual cards API keys to site_settings (if not exist)
-- -------------------------------------------------------
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('cashwyre_api_key', ''),
('cashwyre_secret_key', ''),
('cashwyre_base_url', 'https://api.cashwyre.com');
