-- ============================================================
-- MsjPay v2.0 — Complete Database Schema
-- Nigerian VTU / Fintech Platform — Radnidev Technologies
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ----------------------------
-- Table: admin
-- ----------------------------
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','support') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default superadmin: admin@msjpay.com / Admin@1234
INSERT INTO `admin` (`username`,`email`,`password`,`role`,`is_active`) VALUES
('superadmin','admin@msjpay.com','$2b$10$/nSpZDhQSM6wTx3AH1.vEOiJOkGbOOzqVSc4yxOtew5Z/.D/QEP8O','superadmin',1);

-- ----------------------------
-- Table: users
-- ----------------------------
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(200) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `transaction_pin` varchar(255) DEFAULT NULL,
  `pin_attempts` tinyint(2) NOT NULL DEFAULT 0,
  `pin_locked_until` datetime DEFAULT NULL,
  `wallet_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cashback_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int(11) DEFAULT NULL,
  `kyc_level` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=none,1=NIN,2=BVN,3=full',
  `kyc_status` enum('pending','approved','rejected','under_review') NOT NULL DEFAULT 'pending',
  `bvn` varchar(20) DEFAULT NULL,
  `nin` varchar(20) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `suspension_reason` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verify_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  KEY `referred_by` (`referred_by`),
  KEY `kyc_level` (`kyc_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: virtual_accounts
-- ----------------------------
CREATE TABLE `virtual_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'paymentpoint',
  `account_number` varchar(20) NOT NULL,
  `account_name` varchar(200) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `account_number` (`account_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: transactions
-- ----------------------------
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `type` enum('credit','debit') NOT NULL,
  `category` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `charge` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(15,2) DEFAULT NULL,
  `profit` decimal(15,2) DEFAULT NULL,
  `cashback_earned` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_before` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `network` varchar(20) DEFAULT NULL,
  `network_type` varchar(50) DEFAULT NULL,
  `provider_ref` varchar(150) DEFAULT NULL,
  `external_ref` varchar(150) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `status` enum('pending','success','failed','reversed') NOT NULL DEFAULT 'pending',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `user_id` (`user_id`),
  KEY `category` (`category`),
  KEY `status` (`status`),
  KEY `external_ref` (`external_ref`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: api_providers (per service/network/data_type provider config)
-- ----------------------------
CREATE TABLE `api_providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `data_type` varchar(50) DEFAULT NULL,
  `provider_name` varchar(100) NOT NULL,
  `api_base_url` varchar(300) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `priority` int(3) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_network` (`service`,`network`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: data_plans
-- ----------------------------
CREATE TABLE `data_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `network` varchar(20) NOT NULL,
  `network_id` int(3) NOT NULL DEFAULT 1,
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `data_type` varchar(50) NOT NULL DEFAULT 'SME' COMMENT 'Internal/fixed - used for backend validation',
  `display_type_name` varchar(100) DEFAULT NULL COMMENT 'Cosmetic label shown to users only',
  `data_size` varchar(50) NOT NULL,
  `validity` varchar(50) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `network_type` (`network`,`data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: cable_plans
-- ----------------------------
CREATE TABLE `cable_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `cable_id` int(3) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: electricity_discos
-- ----------------------------
CREATE TABLE `electricity_discos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `disco_name` varchar(100) NOT NULL,
  `disco_id` int(3) NOT NULL,
  `logo_key` varchar(50) DEFAULT NULL,
  `charge` decimal(10,2) NOT NULL DEFAULT 100.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `disco_id` (`disco_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: service_pricing (airtime, A2C rates, exam pin, transfer charge, etc)
-- ----------------------------
CREATE TABLE `service_pricing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `plan_name` varchar(100) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_network` (`service`,`network`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: cashback_config
-- ----------------------------
CREATE TABLE `cashback_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `cashback_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `cashback_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_cashback` decimal(12,2) NOT NULL DEFAULT 9999.00,
  `label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_network` (`service`,`network`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: beneficiaries
-- ----------------------------
CREATE TABLE `beneficiaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `value` varchar(100) NOT NULL COMMENT 'phone/meter/IUC/account number',
  `network` varchar(20) DEFAULT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_name` varchar(200) DEFAULT NULL,
  `is_favourite` tinyint(1) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_service` (`user_id`,`service`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: airtime_to_cash
-- ----------------------------
CREATE TABLE `airtime_to_cash` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `network` varchar(20) NOT NULL,
  `airtime_amount` decimal(12,2) NOT NULL,
  `cash_amount` decimal(12,2) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sender_phone` varchar(20) NOT NULL,
  `our_number` varchar(20) DEFAULT NULL,
  `status` enum('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(255) DEFAULT NULL,
  `provider_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: virtual_cards
-- ----------------------------
CREATE TABLE `virtual_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `card_id` varchar(100) NOT NULL,
  `masked_number` varchar(40) DEFAULT NULL,
  `expiry` varchar(10) DEFAULT NULL,
  `brand` varchar(20) DEFAULT 'Visa',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','frozen','terminated') NOT NULL DEFAULT 'active',
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_id` (`card_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: kyc_requests
-- ----------------------------
CREATE TABLE `kyc_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tier` tinyint(1) NOT NULL,
  `nin` varchar(20) DEFAULT NULL,
  `bvn` varchar(20) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `name_match` varchar(50) DEFAULT NULL,
  `match_percent` decimal(5,2) DEFAULT NULL,
  `easeid_response` text DEFAULT NULL,
  `status` enum('pending','auto_approved','approved','rejected','under_review') NOT NULL DEFAULT 'pending',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: exam_pins
-- ----------------------------
CREATE TABLE `exam_pins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `exam_name` varchar(50) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `pins` text DEFAULT NULL COMMENT 'JSON array of PIN codes',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: recharge_cards
-- ----------------------------
CREATE TABLE `recharge_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `network_id` int(3) NOT NULL,
  `denomination` decimal(10,2) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `name_on_card` varchar(100) DEFAULT NULL,
  `pins` text DEFAULT NULL COMMENT 'JSON array of card data',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: data_cards
-- ----------------------------
CREATE TABLE `data_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `pins` text DEFAULT NULL COMMENT 'JSON array of card data',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: bulk_sms
-- ----------------------------
CREATE TABLE `bulk_sms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `sender_id` varchar(20) NOT NULL,
  `recipients` text NOT NULL,
  `message` text NOT NULL,
  `pages` tinyint(2) NOT NULL DEFAULT 1,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: notifications
-- ----------------------------
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = broadcast to all users',
  `title` varchar(200) NOT NULL,
  `message` varchar(500) NOT NULL,
  `type` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: webhook_logs
-- ----------------------------
CREATE TABLE `webhook_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `account_number` varchar(20) DEFAULT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `payload` text DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider` (`provider`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: email_campaigns
-- ----------------------------
CREATE TABLE `email_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target` varchar(50) NOT NULL DEFAULT 'all',
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `status` enum('queued','sending','completed','failed') NOT NULL DEFAULT 'queued',
  `sent_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: login_attempts
-- ----------------------------
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(100) NOT NULL COMMENT 'phone or IP',
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `identifier` (`identifier`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: blocked_ips
-- ----------------------------
CREATE TABLE `blocked_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = permanent',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: request_nonces (replay protection)
-- ----------------------------
CREATE TABLE `request_nonces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nonce` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nonce` (`nonce`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: api_request_logs
-- ----------------------------
CREATE TABLE `api_request_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` varchar(50) NOT NULL,
  `endpoint` varchar(300) DEFAULT NULL,
  `request_body` text DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `http_code` int(4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `channel` (`channel`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: referral_rewards
-- ----------------------------
CREATE TABLE `referral_rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referrer_id` int(11) NOT NULL,
  `referred_id` int(11) NOT NULL,
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `referrer_id` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table: site_settings
-- ----------------------------
CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Site settings
INSERT INTO `site_settings` (`setting_key`,`setting_value`) VALUES
('site_name','MsjPay'),
('site_tagline','Nigeria''s Smartest Fintech App'),
('site_email','support@msjpay.com'),
('site_phone','+234 800 0000 000'),
('site_address','Lagos, Nigeria'),
('maintenance_mode','0'),
('registration_enabled','1'),
('min_wallet_funding','100'),
('max_wallet_funding','1000000'),
('min_transfer_amount','100'),
('max_transfer_amount','500000'),
('transfer_charge','25'),
('min_a2c_amount','500'),
('max_a2c_amount','50000'),
('virtual_card_creation_fee','1000'),
('kyc_tier1_limit_single','20000'),
('kyc_tier1_limit_daily','50000'),
('kyc_tier2_limit_single','100000'),
('kyc_tier2_limit_daily','200000'),
('kyc_tier3_limit_single','1000000'),
('kyc_tier3_limit_daily','5000000'),
-- PaymentPoint
('paymentpoint_bearer',''),
('paymentpoint_api_key',''),
('paymentpoint_webhook_secret',''),
-- PalmPay
('palmpay_bearer',''),
('palmpay_secret',''),
('palmpay_sandbox','1'),
('palmpay_enabled','0'),
-- EaseID
('easeid_bearer',''),
('easeid_secret',''),
-- Autopilot NG
('autopilot_api_key',''),
('autopilot_mtn_number',''),
('autopilot_glo_number',''),
('autopilot_airtel_number',''),
('autopilot_9mobile_number',''),
-- Cashwyre
('cashwyre_api_key',''),
('cashwyre_secret_key',''),
('cashwyre_base_url','https://api.cashwyre.com'),
-- SMTP
('smtp_host',''),
('smtp_port','587'),
('smtp_user',''),
('smtp_pass',''),
('smtp_from_name','MsjPay'),
('smtp_from_email','noreply@msjpay.com');

-- API Providers (Gladtidings for VTU services, Autopilot for A2C)
INSERT INTO `api_providers` (`service`,`network`,`data_type`,`provider_name`,`api_base_url`,`api_key`,`priority`,`is_active`) VALUES
('airtime',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('data','MTN',NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('data','GLO',NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('data','AIRTEL',NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('data','9MOBILE',NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('electricity',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('cabletv',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('exam_pin',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('recharge_card',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('data_card',NULL,NULL,'Gladtidings','https://gladtidingsdata.com.ng/api',NULL,1,1),
('airtime_to_cash',NULL,NULL,'Autopilot NG','https://api.autopilotng.com',NULL,1,1);

-- Data plans (sample)
INSERT INTO `data_plans` (`network`,`network_id`,`plan_id`,`plan_name`,`display_name`,`data_type`,`display_type_name`,`data_size`,`validity`,`cost_price`,`selling_price`,`is_active`) VALUES
('MTN',1,101,'MTN 500MB SME','500MB Data','SME','SME',  '500MB','30 days',150.00,180.00,1),
('MTN',1,102,'MTN 1GB SME','1GB Data','SME','SME','1GB','30 days',280.00,320.00,1),
('MTN',1,103,'MTN 2GB SME','2GB Data','SME','SME','2GB','30 days',550.00,620.00,1),
('MTN',1,104,'MTN 5GB SME','5GB Data','SME','SME','5GB','30 days',1350.00,1500.00,1),
('MTN',1,201,'MTN 1GB Corporate Gifting','1GB Gifting','Corporate Gifting','Gifting','1GB','30 days',300.00,350.00,1),
('GLO',2,301,'GLO 1GB','1GB Data','Gifting','Gifting','1GB','30 days',250.00,300.00,1),
('GLO',2,302,'GLO 2GB','2GB Data','Gifting','Gifting','2GB','30 days',480.00,550.00,1),
('GLO',2,303,'GLO 5GB','5GB Data','Gifting','Gifting','5GB','30 days',1100.00,1250.00,1),
('AIRTEL',3,401,'AIRTEL 1GB','1GB Data','Corporate','Corporate','1GB','30 days',290.00,340.00,1),
('AIRTEL',3,402,'AIRTEL 2GB','2GB Data','Corporate','Corporate','2GB','30 days',560.00,630.00,1),
('AIRTEL',3,403,'AIRTEL 5GB','5GB Data','Corporate','Corporate','5GB','30 days',1380.00,1530.00,1),
('9MOBILE',4,501,'9MOBILE 1GB','1GB Data','SME','SME','1GB','30 days',270.00,320.00,1),
('9MOBILE',4,502,'9MOBILE 2GB','2GB Data','SME','SME','2GB','30 days',520.00,590.00,1);

-- Cable plans
INSERT INTO `cable_plans` (`provider`,`cable_id`,`plan_id`,`plan_name`,`display_name`,`cost_price`,`selling_price`,`is_active`) VALUES
('GOTV',1,1,'GOTV Smallie','GOtv Smallie - 1 Month',1100.00,1300.00,1),
('GOTV',1,2,'GOTV Jolli','GOtv Jolli - 1 Month',2800.00,3300.00,1),
('GOTV',1,3,'GOTV Max','GOtv Max - 1 Month',4150.00,4850.00,1),
('DSTV',2,10,'DStv Padi','DStv Padi - 1 Month',2150.00,2500.00,1),
('DSTV',2,11,'DStv Yanga','DStv Yanga - 1 Month',2950.00,3400.00,1),
('DSTV',2,12,'DStv Confam','DStv Confam - 1 Month',5300.00,6000.00,1),
('DSTV',2,13,'DStv Compact','DStv Compact - 1 Month',9000.00,10000.00,1),
('STARTIMES',3,20,'StarTimes Nova','StarTimes Nova - 1 Month',1300.00,1500.00,1),
('STARTIMES',3,21,'StarTimes Basic','StarTimes Basic - 1 Month',1900.00,2200.00,1);

-- Electricity discos
INSERT INTO `electricity_discos` (`disco_name`,`disco_id`,`logo_key`,`charge`,`is_active`) VALUES
('Ikeja Electric',1,'ikeja',100.00,1),
('Eko Electric',2,'eko',100.00,1),
('Ibadan Electric',3,'ibadan',100.00,1),
('Abuja Electric',4,'abuja',100.00,1),
('Kano Electric',5,'kano',100.00,1),
('Port Harcourt Electric',6,'portharcourt',100.00,1),
('Kaduna Electric',7,'kaduna',100.00,1),
('Enugu Electric',8,'enugu',100.00,1),
('Benin Electric',9,'benin',100.00,1),
('Jos Electric',10,'jos',100.00,1),
('Yola Electric',11,'yola',100.00,1);

-- Service pricing (airtime discount per network, exam pins, A2C rates)
INSERT INTO `service_pricing` (`service`,`network`,`plan_name`,`cost_price`,`selling_price`,`discount_percent`,`is_active`) VALUES
('airtime','MTN',NULL,97.00,98.00,2.00,1),
('airtime','GLO',NULL,96.00,97.00,3.00,1),
('airtime','AIRTEL',NULL,97.50,98.50,1.50,1),
('airtime','9MOBILE',NULL,96.50,97.50,2.50,1),
('exam_pin',NULL,'WAEC',900.00,1000.00,0.00,1),
('exam_pin',NULL,'NECO',700.00,800.00,0.00,1),
('exam_pin',NULL,'NABTEB',550.00,650.00,0.00,1),
('airtime_to_cash','MTN',NULL,0.00,0.00,65.00,1),
('airtime_to_cash','GLO',NULL,0.00,0.00,60.00,1),
('airtime_to_cash','AIRTEL',NULL,0.00,0.00,62.00,1),
('airtime_to_cash','9MOBILE',NULL,0.00,0.00,58.00,1);

-- Cashback config (sample defaults, inactive until admin configures)
INSERT INTO `cashback_config` (`service`,`network`,`cashback_type`,`cashback_value`,`min_amount`,`max_cashback`,`label`,`is_active`) VALUES
('airtime',NULL,'percent',0.50,100.00,50.00,'0.5% on all airtime',1),
('data',NULL,'percent',1.00,200.00,100.00,'1% on all data',1),
('bank_transfer',NULL,'fixed',5.00,1000.00,5.00,'₦5 flat on transfers ≥₦1000',0);
