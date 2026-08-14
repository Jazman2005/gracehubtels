-- Gracehubtels base schema
-- Structure only — extracted from a live-server export, no data rows.
-- Generated 2026-08-14, already includes everything through
-- migration_v31.sql (confirmed: re-applying v28-v31 against a fresh import
-- of this file fails with "duplicate column" errors, since those changes
-- are already present here). For a fresh install: import this file, then
-- apply only migration files numbered HIGHER than v31, if any exist.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `admin` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','support') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `current_session_token` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `admin_activity_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `admin_known_devices` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `device_hash` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(400) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_seen` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `admin_otps` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(400) DEFAULT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `admin_roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `airtime_to_cash` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `api_providers` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `data_type` varchar(50) DEFAULT NULL,
  `api_registry_id` int(11) DEFAULT NULL,
  `provider_name` varchar(100) NOT NULL,
  `api_base_url` varchar(300) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `api_pin` varchar(20) DEFAULT NULL,
  `priority` int(3) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `contract_code` varchar(100) DEFAULT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `network_id_map` text DEFAULT NULL,
  `value_map` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `api_registry` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `base_url` varchar(300) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `api_pin` varchar(20) DEFAULT NULL,
  `contract_code` varchar(100) DEFAULT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `network_id_map` text DEFAULT NULL,
  `value_map` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `plan_fetch_url` varchar(500) DEFAULT NULL,
  `plan_fetch_method` varchar(10) DEFAULT 'GET',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `api_request_logs` (
  `id` int(11) NOT NULL,
  `channel` varchar(50) NOT NULL,
  `endpoint` varchar(300) DEFAULT NULL,
  `request_body` text DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `http_code` int(4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `banners` (
  `id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `beneficiaries` (
  `id` int(11) NOT NULL,
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
  `last_used` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `betting_plans` (
  `id` int(11) NOT NULL,
  `platform_name` varchar(100) NOT NULL,
  `provider_code` varchar(100) NOT NULL,
  `charge` decimal(10,2) DEFAULT 0.00,
  `agent_charge` decimal(10,2) DEFAULT 0.00,
  `vendor_charge` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `blacklists` (
  `id` int(11) NOT NULL,
  `type` enum('ip','phone') NOT NULL,
  `value` varchar(255) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `blocked_ips` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = permanent',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bulk_pricing_presets` (
  `id` int(11) NOT NULL,
  `service` varchar(20) NOT NULL,
  `scope_key` varchar(150) NOT NULL,
  `markup_user` decimal(6,2) DEFAULT NULL,
  `markup_agent` decimal(6,2) DEFAULT NULL,
  `markup_vendor` decimal(6,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bulk_sms_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender_id` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `recipients` text NOT NULL,
  `recipient_count` int(11) NOT NULL,
  `page_count` int(11) NOT NULL,
  `total_cost` decimal(15,2) NOT NULL,
  `status` enum('pending','success','failed') DEFAULT 'pending',
  `provider_response` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cable_plans` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `cable_id` int(3) NOT NULL,
  `plan_id` varchar(100) NOT NULL,
  `plan_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `agent_price` decimal(10,2) DEFAULT NULL,
  `vendor_price` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `product_code` varchar(100) DEFAULT NULL COMMENT 'Provider product ID (e.g. autosyncng)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cashback_config` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `cashback_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `cashback_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_cashback` decimal(12,2) NOT NULL DEFAULT 9999.00,
  `label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `chatbot_sessions` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `step` varchar(50) DEFAULT 'menu',
  `temp_data` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `crm_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `campaign_type` varchar(50) NOT NULL,
  `campaign_reference` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `data_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `network` varchar(30) DEFAULT NULL,
  `plan_name` varchar(255) DEFAULT NULL,
  `plan_id` int(11) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `amount` decimal(12,2) DEFAULT 0.00,
  `pins` longtext DEFAULT NULL,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `data_plans` (
  `id` int(11) NOT NULL,
  `network` varchar(20) NOT NULL,
  `network_id` int(3) NOT NULL DEFAULT 1,
  `plan_id` varchar(100) NOT NULL,
  `plan_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `data_type` varchar(50) NOT NULL DEFAULT 'SME' COMMENT 'Internal/fixed - used for backend validation',
  `display_type_name` varchar(100) DEFAULT NULL COMMENT 'Cosmetic label shown to users only',
  `data_size` varchar(50) NOT NULL,
  `validity` varchar(50) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `agent_price` decimal(10,2) DEFAULT 0.00,
  `vendor_price` decimal(10,2) DEFAULT 0.00,
  `cashback_type` enum('percent','fixed') DEFAULT NULL,
  `cashback_value` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `display_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `electricity_discos` (
  `id` int(11) NOT NULL,
  `disco_name` varchar(100) NOT NULL,
  `disco_id` int(3) NOT NULL,
  `logo_key` varchar(50) DEFAULT NULL,
  `charge` decimal(10,2) NOT NULL DEFAULT 100.00,
  `postpaid_charge` decimal(10,2) DEFAULT NULL,
  `agent_charge` decimal(10,2) DEFAULT NULL,
  `vendor_charge` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `product_code` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `email_campaigns` (
  `id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target` varchar(50) NOT NULL DEFAULT 'all',
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `status` enum('queued','sending','completed','failed') NOT NULL DEFAULT 'queued',
  `sent_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `exam_pins` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `exam_name` varchar(50) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pins` text DEFAULT NULL COMMENT 'JSON array of PIN codes',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `pins_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `exam_pins_stock` (
  `id` int(11) NOT NULL,
  `exam_type` varchar(50) NOT NULL,
  `pin` varchar(100) NOT NULL,
  `serial` varchar(100) NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `used_by` int(11) DEFAULT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `feature_flags` (
  `feature_key` varchar(50) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `label` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `global_api_providers` (
  `id` int(11) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `api_base_url` varchar(300) NOT NULL,
  `api_token` varchar(300) DEFAULT NULL,
  `api_pin` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `kyc_requests` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `legacy_migration_log` (
  `id` int(11) NOT NULL,
  `old_user_id` bigint(20) NOT NULL,
  `action` varchar(30) NOT NULL,
  `new_user_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `migrated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `identifier` varchar(100) NOT NULL COMMENT 'phone or IP',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attempted_at` time NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_tracker` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `login_at` timestamp NULL DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `manual_funding_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `sender_name` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = broadcast to all users',
  `target_role` varchar(20) DEFAULT 'all',
  `title` varchar(200) NOT NULL,
  `message` varchar(500) NOT NULL,
  `type` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `display_type` varchar(20) DEFAULT 'marquee',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `plan_api_bindings` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `meter_type` varchar(20) NOT NULL DEFAULT '',
  `api_registry_id` int(11) NOT NULL,
  `priority` tinyint(1) NOT NULL DEFAULT 1,
  `product_code` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `promo_claims` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `promo_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `promo_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `usage_limit` int(11) NOT NULL DEFAULT 0,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `promo_usage` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `promo_id` int(11) NOT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `discount_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `push_notification_log` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target` varchar(50) NOT NULL,
  `recipients_count` int(11) DEFAULT 0,
  `tracking_id` varchar(255) DEFAULT NULL,
  `recipient_ids` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `recharge_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `network_id` int(3) NOT NULL,
  `denomination` decimal(10,2) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `name_on_card` varchar(100) DEFAULT NULL,
  `pins_data` text DEFAULT NULL,
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `referral_rewards` (
  `id` int(11) NOT NULL,
  `referrer_id` int(11) NOT NULL,
  `referred_id` int(11) NOT NULL,
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `request_nonces` (
  `id` int(11) NOT NULL,
  `nonce` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `service_pricing` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `plan_name` varchar(100) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `agent_price` decimal(10,2) DEFAULT NULL,
  `vendor_price` decimal(10,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `agent_discount` decimal(5,2) DEFAULT NULL,
  `vendor_discount` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('open','pending','resolved') DEFAULT 'open',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ticket_messages` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `sender_type` enum('user','admin') NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dedupe_signature` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transaction_limits` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `min_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `daily_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `user_type` enum('regular','agent','vendor') NOT NULL DEFAULT 'regular',
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `transaction_pin` varchar(255) DEFAULT NULL,
  `pin_attempts` tinyint(2) NOT NULL DEFAULT 0,
  `pin_locked_until` datetime DEFAULT NULL,
  `api_token` varchar(100) DEFAULT NULL,
  `push_token` varchar(255) DEFAULT NULL,
  `api_webhook_url` varchar(255) DEFAULT NULL,
  `wallet_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cashwyre_customer_code` varchar(100) DEFAULT NULL COMMENT 'Cashwyre customer code for virtual cards',
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
  `pin_reset_token` varchar(10) DEFAULT NULL,
  `pin_reset_token_expiry` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_daily_limit` decimal(15,2) DEFAULT NULL,
  `custom_airtime_limit` decimal(15,2) DEFAULT NULL,
  `custom_transfer_limit` decimal(15,2) DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `login_locked_until` datetime DEFAULT NULL,
  `notify_push` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user_limits` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL,
  `daily_limit` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `virtual_accounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'paymentpoint',
  `account_number` varchar(20) NOT NULL,
  `account_name` varchar(200) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_dynamic` tinyint(1) NOT NULL DEFAULT 0,
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `virtual_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `card_id` varchar(100) NOT NULL,
  `masked_number` varchar(40) DEFAULT NULL,
  `card_number` varchar(30) DEFAULT NULL,
  `expiry` varchar(10) DEFAULT NULL,
  `cvv` varchar(10) DEFAULT NULL,
  `brand` varchar(20) DEFAULT 'Visa',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','frozen','terminated') NOT NULL DEFAULT 'active',
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `webhook_idempotency` (
  `idem_key` varchar(191) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `webhook_logs` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `admin_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

ALTER TABLE `admin_known_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_admin_device` (`admin_id`,`device_hash`);

ALTER TABLE `admin_otps`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `admin_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

ALTER TABLE `airtime_to_cash`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_ref` (`transaction_ref`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

ALTER TABLE `api_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

ALTER TABLE `api_registry`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `api_request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `channel` (`channel`),
  ADD KEY `created_at` (`created_at`);

ALTER TABLE `banners`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `beneficiaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_service` (`user_id`,`service`);

ALTER TABLE `betting_plans`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `blacklists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type` (`type`,`value`);

ALTER TABLE `blocked_ips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`);

ALTER TABLE `bulk_pricing_presets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bulk_pricing_scope` (`service`,`scope_key`);

ALTER TABLE `bulk_sms_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `cable_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider` (`provider`);

ALTER TABLE `cashback_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

ALTER TABLE `chatbot_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`);

ALTER TABLE `crm_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `campaign_type` (`campaign_type`),
  ADD KEY `campaign_reference` (`campaign_reference`);

ALTER TABLE `data_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `data_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `network_type` (`network`,`data_type`);

ALTER TABLE `electricity_discos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `disco_id` (`disco_id`);

ALTER TABLE `email_campaigns`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `exam_pins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `exam_pins_stock`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `feature_flags`
  ADD PRIMARY KEY (`feature_key`);

ALTER TABLE `global_api_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_name` (`provider_name`);

ALTER TABLE `kyc_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `tier` (`tier`);

ALTER TABLE `legacy_migration_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_old_user` (`old_user_id`);

ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `identifier` (`identifier`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `ip_attempted` (`ip_address`,`attempted_at`);

ALTER TABLE `login_tracker`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `login_at` (`login_at`);

ALTER TABLE `manual_funding_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

ALTER TABLE `plan_api_bindings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_plan_api` (`service`,`plan_id`,`api_registry_id`,`meter_type`),
  ADD KEY `service_plan` (`service`,`plan_id`),
  ADD KEY `api_registry_id` (`api_registry_id`);

ALTER TABLE `promo_claims`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_promo` (`user_id`,`promo_id`),
  ADD KEY `promo_id` (`promo_id`);

ALTER TABLE `promo_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `promo_usage`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `push_notification_log`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `recharge_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `referral_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `referrer_id` (`referrer_id`);

ALTER TABLE `request_nonces`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nonce` (`nonce`),
  ADD KEY `expires_at` (`expires_at`);

ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `service_pricing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `ticket_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_ref` (`transaction_ref`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `category` (`category`),
  ADD KEY `status` (`status`),
  ADD KEY `external_ref` (`external_ref`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `user_created` (`user_id`,`created_at`),
  ADD KEY `idx_dedupe` (`user_id`,`status`,`dedupe_signature`);

ALTER TABLE `transaction_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_type` (`service`,`user_type`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `referred_by` (`referred_by`),
  ADD KEY `kyc_level` (`kyc_level`),
  ADD KEY `email` (`email`),
  ADD KEY `username` (`username`),
  ADD KEY `api_token` (`api_token`);

ALTER TABLE `user_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_service` (`user_id`,`service`);

ALTER TABLE `virtual_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `account_number` (`account_number`);

ALTER TABLE `virtual_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `card_id` (`card_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `webhook_idempotency`
  ADD PRIMARY KEY (`idem_key`);

ALTER TABLE `webhook_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider` (`provider`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `transaction_ref` (`transaction_ref`);

ALTER TABLE `admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `admin_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=388;

ALTER TABLE `admin_known_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

ALTER TABLE `admin_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

ALTER TABLE `admin_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `airtime_to_cash`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `api_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

ALTER TABLE `api_registry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `api_request_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `banners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

ALTER TABLE `beneficiaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `betting_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

ALTER TABLE `blacklists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `blocked_ips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bulk_pricing_presets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

ALTER TABLE `bulk_sms_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

ALTER TABLE `cable_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `cashback_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `chatbot_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=346;

ALTER TABLE `crm_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `data_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `data_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=209;

ALTER TABLE `electricity_discos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

ALTER TABLE `email_campaigns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `exam_pins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `exam_pins_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

ALTER TABLE `global_api_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `kyc_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `legacy_migration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4651;

ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

ALTER TABLE `login_tracker`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

ALTER TABLE `manual_funding_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=203;

ALTER TABLE `plan_api_bindings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

ALTER TABLE `promo_claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `promo_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `promo_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `push_notification_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `recharge_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `referral_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `request_nonces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `security_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `service_pricing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

ALTER TABLE `site_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2462;

ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `ticket_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

ALTER TABLE `transaction_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4449;

ALTER TABLE `user_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `virtual_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `virtual_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `webhook_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `admin_activity_logs`
  ADD CONSTRAINT `admin_activity_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE CASCADE;

ALTER TABLE `login_tracker`
  ADD CONSTRAINT `login_tracker_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `manual_funding_requests`
  ADD CONSTRAINT `fk_manual_funding_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `promo_claims`
  ADD CONSTRAINT `promo_claims_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `promo_claims_ibfk_2` FOREIGN KEY (`promo_id`) REFERENCES `promo_codes` (`id`) ON DELETE CASCADE;

ALTER TABLE `security_logs`
  ADD CONSTRAINT `security_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `ticket_messages`
  ADD CONSTRAINT `ticket_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_limits`
  ADD CONSTRAINT `user_limits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


COMMIT;
