-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 21, 2026 at 03:22 AM
-- Server version: 11.4.10-MariaDB-cll-lve-log
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `msjdkjef_msjpay2`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','support') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `username`, `email`, `password`, `role`, `is_active`, `last_login`, `last_login_ip`, `created_at`) VALUES
(1, 'superadmin', 'admin@msjpay.com', '$2b$10$/nSpZDhQSM6wTx3AH1.vEOiJOkGbOOzqVSc4yxOtew5Z/.D/QEP8O', 'superadmin', 1, '2026-06-21 03:19:12', '102.91.77.145', '2026-06-19 10:05:57');

-- --------------------------------------------------------

--
-- Table structure for table `admin_roles`
--

CREATE TABLE `admin_roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_roles`
--

INSERT INTO `admin_roles` (`id`, `role_name`, `permissions`, `created_at`) VALUES
(1, 'superadmin', '[\"all\"]', '2026-06-20 15:22:28'),
(2, 'admin', '[\"dashboard\",\"users_view\",\"users_edit\",\"transactions_view\",\"kyc_review\",\"services\",\"notifications\",\"profit_view\"]', '2026-06-20 15:22:28'),
(3, 'assistant', '[\"dashboard\",\"users_view\",\"transactions_view\",\"kyc_review\",\"notifications\"]', '2026-06-20 15:22:28'),
(4, 'support', '[\"users_view\",\"kyc_review\",\"notifications\"]', '2026-06-20 15:22:28');

-- --------------------------------------------------------

--
-- Table structure for table `airtime_to_cash`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `api_providers`
--

CREATE TABLE `api_providers` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `api_providers`
--

INSERT INTO `api_providers` (`id`, `service`, `network`, `data_type`, `provider_name`, `api_base_url`, `api_key`, `api_secret`, `api_token`, `priority`, `is_active`, `created_at`) VALUES
(1, 'airtime', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(2, 'data', 'MTN', NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(3, 'data', 'GLO', NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(4, 'data', 'AIRTEL', NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(5, 'data', '9MOBILE', NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(6, 'electricity', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(7, 'cabletv', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(8, 'exam_pin', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(9, 'recharge_card', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 1, '2026-06-19 10:05:58'),
(10, 'data_card', NULL, NULL, 'Gladtidings', 'https://gladtidingsdata.com.ng/api', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(11, 'airtime_to_cash', NULL, NULL, 'Autopilot NG', 'https://api.autopilotng.com', NULL, NULL, NULL, 1, 0, '2026-06-19 10:05:58'),
(12, 'data', NULL, 'SME', 'Autosyncng', 'https://autosyncng.com/api/msorg/', '', '', '', 1, 1, '2026-06-20 06:21:02');

-- --------------------------------------------------------

--
-- Table structure for table `api_request_logs`
--

CREATE TABLE `api_request_logs` (
  `id` int(11) NOT NULL,
  `channel` varchar(50) NOT NULL,
  `endpoint` varchar(300) DEFAULT NULL,
  `request_body` text DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `http_code` int(4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beneficiaries`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `blocked_ips`
--

CREATE TABLE `blocked_ips` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = permanent',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cable_plans`
--

CREATE TABLE `cable_plans` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `cable_id` int(3) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(200) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cable_plans`
--

INSERT INTO `cable_plans` (`id`, `provider`, `cable_id`, `plan_id`, `plan_name`, `display_name`, `cost_price`, `selling_price`, `is_active`, `created_at`) VALUES
(1, 'GOTV', 1, 1, 'GOTV Smallie', 'GOtv Smallie - 1 Month', 1100.00, 1300.00, 1, '2026-06-19 10:05:58'),
(2, 'GOTV', 1, 2, 'GOTV Jolli', 'GOtv Jolli - 1 Month', 2800.00, 3300.00, 1, '2026-06-19 10:05:58'),
(3, 'GOTV', 1, 3, 'GOTV Max', 'GOtv Max - 1 Month', 4150.00, 4850.00, 1, '2026-06-19 10:05:58'),
(4, 'DSTV', 2, 10, 'DStv Padi', 'DStv Padi - 1 Month', 2150.00, 2500.00, 1, '2026-06-19 10:05:58'),
(5, 'DSTV', 2, 11, 'DStv Yanga', 'DStv Yanga - 1 Month', 2950.00, 3400.00, 1, '2026-06-19 10:05:58'),
(6, 'DSTV', 2, 12, 'DStv Confam', 'DStv Confam - 1 Month', 5300.00, 6000.00, 1, '2026-06-19 10:05:58'),
(7, 'DSTV', 2, 13, 'DStv Compact', 'DStv Compact - 1 Month', 9000.00, 10000.00, 1, '2026-06-19 10:05:58'),
(8, 'STARTIMES', 3, 20, 'StarTimes Nova', 'StarTimes Nova - 1 Month', 1300.00, 1500.00, 1, '2026-06-19 10:05:58'),
(9, 'STARTIMES', 3, 21, 'StarTimes Basic', 'StarTimes Basic - 1 Month', 1900.00, 2200.00, 1, '2026-06-19 10:05:58');

-- --------------------------------------------------------

--
-- Table structure for table `cashback_config`
--

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

--
-- Dumping data for table `cashback_config`
--

INSERT INTO `cashback_config` (`id`, `service`, `network`, `cashback_type`, `cashback_value`, `min_amount`, `max_cashback`, `label`, `is_active`, `created_at`) VALUES
(1, 'airtime', NULL, 'percent', 0.50, 100.00, 50.00, '0.5% on all airtime', 1, '2026-06-19 10:05:58'),
(2, 'data', NULL, 'percent', 1.00, 200.00, 100.00, '1% on all data', 1, '2026-06-19 10:05:58'),
(3, 'bank_transfer', NULL, 'fixed', 5.00, 1000.00, 5.00, '₦5 flat on transfers ≥₦1000', 0, '2026-06-19 10:05:58');

-- --------------------------------------------------------

--
-- Table structure for table `data_cards`
--

CREATE TABLE `data_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `pins` text DEFAULT NULL COMMENT 'JSON array of card data',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `data_plans`
--

CREATE TABLE `data_plans` (
  `id` int(11) NOT NULL,
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
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `data_plans`
--

INSERT INTO `data_plans` (`id`, `network`, `network_id`, `plan_id`, `plan_name`, `display_name`, `data_type`, `display_type_name`, `data_size`, `validity`, `cost_price`, `selling_price`, `is_active`, `user_type`, `created_at`) VALUES
(3, 'MTN', 1, 103, 'MTN 2GB SME', '2GB Data', 'SME', 'SME', '2GB', '30 days', 550.00, 620.00, 1, 'both', '2026-06-19 10:05:58');

-- --------------------------------------------------------

--
-- Table structure for table `electricity_discos`
--

CREATE TABLE `electricity_discos` (
  `id` int(11) NOT NULL,
  `disco_name` varchar(100) NOT NULL,
  `disco_id` int(3) NOT NULL,
  `logo_key` varchar(50) DEFAULT NULL,
  `charge` decimal(10,2) NOT NULL DEFAULT 100.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `electricity_discos`
--

INSERT INTO `electricity_discos` (`id`, `disco_name`, `disco_id`, `logo_key`, `charge`, `is_active`, `created_at`) VALUES
(1, 'Ikeja Electric', 1, 'ikeja', 100.00, 1, '2026-06-19 10:05:58'),
(2, 'Eko Electric', 2, 'eko', 100.00, 1, '2026-06-19 10:05:58'),
(3, 'Ibadan Electric', 3, 'ibadan', 100.00, 1, '2026-06-19 10:05:58'),
(4, 'Abuja Electric', 4, 'abuja', 100.00, 1, '2026-06-19 10:05:58'),
(5, 'Kano Electric', 5, 'kano', 100.00, 1, '2026-06-19 10:05:58'),
(6, 'Port Harcourt Electric', 6, 'portharcourt', 100.00, 1, '2026-06-19 10:05:58'),
(7, 'Kaduna Electric', 7, 'kaduna', 100.00, 1, '2026-06-19 10:05:58'),
(8, 'Enugu Electric', 8, 'enugu', 100.00, 1, '2026-06-19 10:05:58'),
(9, 'Benin Electric', 9, 'benin', 100.00, 1, '2026-06-19 10:05:58'),
(10, 'Jos Electric', 10, 'jos', 100.00, 1, '2026-06-19 10:05:58'),
(11, 'Yola Electric', 11, 'yola', 100.00, 1, '2026-06-19 10:05:58');

-- --------------------------------------------------------

--
-- Table structure for table `email_campaigns`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `exam_pins`
--

CREATE TABLE `exam_pins` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `exam_name` varchar(50) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `pins` text DEFAULT NULL COMMENT 'JSON array of PIN codes',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kyc_requests`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `identifier` varchar(100) NOT NULL COMMENT 'phone or IP',
  `ip_address` varchar(45) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attempted_at` time NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_tracker`
--

CREATE TABLE `login_tracker` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `login_at` timestamp NULL DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_tracker`
--

INSERT INTO `login_tracker` (`id`, `user_id`, `ip_address`, `user_agent`, `login_at`, `success`) VALUES
(1, 2, '102.91.78.13', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/147.0.7727.47 Mobile/15E148 Safari/604.1', '2026-06-20 15:53:18', 1),
(2, 1, '102.90.102.36', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-20 15:57:16', 1),
(3, 1, '102.90.102.36', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-20 16:00:54', 1),
(4, 2, '102.91.78.13', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-20 16:04:50', 1),
(5, 2, '102.91.77.162', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-20 17:19:00', 1),
(6, 2, '102.91.104.177', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-20 20:06:25', 1),
(7, 2, '105.112.96.41', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36', '2026-06-20 20:07:55', 1),
(8, 2, '102.91.77.145', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-21 06:57:56', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL = broadcast to all users',
  `title` varchar(200) NOT NULL,
  `message` varchar(500) NOT NULL,
  `type` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `action_url`, `is_read`, `created_at`) VALUES
(1, NULL, 'HI', 'Testing', 'success', NULL, 1, '2026-06-20 05:32:40'),
(2, NULL, 'Info', 'Info', 'info', NULL, 0, '2026-06-20 16:20:09');

-- --------------------------------------------------------

--
-- Table structure for table `recharge_cards`
--

CREATE TABLE `recharge_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_ref` varchar(60) NOT NULL,
  `network_id` int(3) NOT NULL,
  `denomination` decimal(10,2) NOT NULL,
  `quantity` tinyint(2) NOT NULL DEFAULT 1,
  `name_on_card` varchar(100) DEFAULT NULL,
  `pins` text DEFAULT NULL COMMENT 'JSON array of card data',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `referral_rewards`
--

CREATE TABLE `referral_rewards` (
  `id` int(11) NOT NULL,
  `referrer_id` int(11) NOT NULL,
  `referred_id` int(11) NOT NULL,
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_nonces`
--

CREATE TABLE `request_nonces` (
  `id` int(11) NOT NULL,
  `nonce` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_pricing`
--

CREATE TABLE `service_pricing` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `network` varchar(20) DEFAULT NULL,
  `plan_name` varchar(100) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_pricing`
--

INSERT INTO `service_pricing` (`id`, `service`, `network`, `plan_name`, `cost_price`, `selling_price`, `discount_percent`, `is_active`, `user_type`, `created_at`) VALUES
(1, 'airtime', 'MTN', NULL, 97.00, 98.00, 2.00, 1, 'both', '2026-06-19 10:05:58'),
(2, 'airtime', 'GLO', NULL, 96.00, 97.00, 3.00, 1, 'both', '2026-06-19 10:05:58'),
(3, 'airtime', 'AIRTEL', NULL, 97.50, 98.50, 1.50, 1, 'both', '2026-06-19 10:05:58'),
(4, 'airtime', '9MOBILE', NULL, 96.50, 97.50, 2.50, 1, 'both', '2026-06-19 10:05:58'),
(5, 'exam_pin', NULL, 'WAEC', 900.00, 1000.00, 0.00, 1, 'both', '2026-06-19 10:05:58'),
(6, 'exam_pin', NULL, 'NECO', 700.00, 800.00, 0.00, 1, 'both', '2026-06-19 10:05:58'),
(7, 'exam_pin', NULL, 'NABTEB', 550.00, 650.00, 0.00, 1, 'both', '2026-06-19 10:05:58'),
(8, 'airtime_to_cash', 'MTN', NULL, 0.00, 0.00, 65.00, 1, 'both', '2026-06-19 10:05:58'),
(9, 'airtime_to_cash', 'GLO', NULL, 0.00, 0.00, 60.00, 1, 'both', '2026-06-19 10:05:58'),
(10, 'airtime_to_cash', 'AIRTEL', NULL, 0.00, 0.00, 62.00, 1, 'both', '2026-06-19 10:05:58'),
(11, 'airtime_to_cash', '9MOBILE', NULL, 0.00, 0.00, 58.00, 1, 'both', '2026-06-19 10:05:58');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'site_name', 'MsjPay'),
(2, 'site_tagline', 'Nigeria\'s Smartest Fintech App'),
(3, 'site_email', 'support@msjpay.com'),
(4, 'site_phone', '+234 800 0000 000'),
(5, 'site_address', 'Lagos, Nigeria'),
(6, 'maintenance_mode', '0'),
(7, 'registration_enabled', '1'),
(8, 'min_wallet_funding', '100'),
(9, 'max_wallet_funding', '1000000'),
(10, 'min_transfer_amount', '100'),
(11, 'max_transfer_amount', '500000'),
(12, 'transfer_charge', '25'),
(13, 'min_a2c_amount', '500'),
(14, 'max_a2c_amount', '50000'),
(15, 'virtual_card_creation_fee', '1000'),
(16, 'kyc_tier1_limit_single', '20000'),
(17, 'kyc_tier1_limit_daily', '50000'),
(18, 'kyc_tier2_limit_single', '100000'),
(19, 'kyc_tier2_limit_daily', '200000'),
(20, 'kyc_tier3_limit_single', '1000000'),
(21, 'kyc_tier3_limit_daily', '5000000'),
(22, 'paymentpoint_bearer', ''),
(23, 'paymentpoint_api_key', ''),
(24, 'paymentpoint_webhook_secret', ''),
(25, 'palmpay_bearer', ''),
(26, 'palmpay_secret', ''),
(27, 'palmpay_sandbox', '1'),
(28, 'palmpay_enabled', '0'),
(29, 'easeid_bearer', ''),
(30, 'easeid_secret', ''),
(31, 'autopilot_api_key', ''),
(32, 'autopilot_mtn_number', '09060393166'),
(33, 'autopilot_glo_number', ''),
(34, 'autopilot_airtel_number', '08126368886'),
(35, 'autopilot_9mobile_number', ''),
(36, 'cashwyre_api_key', ''),
(37, 'cashwyre_secret_key', ''),
(38, 'cashwyre_base_url', 'https://api.cashwyre.com'),
(39, 'smtp_host', ''),
(40, 'smtp_port', '587'),
(41, 'smtp_user', ''),
(42, 'smtp_pass', ''),
(43, 'smtp_from_name', 'MsjPay'),
(44, 'smtp_from_email', 'noreply@msjpay.com');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `transaction_ref`, `idempotency_key`, `type`, `category`, `amount`, `charge`, `cost_price`, `profit`, `cashback_earned`, `balance_before`, `balance_after`, `description`, `phone`, `network`, `network_type`, `provider_ref`, `external_ref`, `provider`, `meta`, `status`, `ip_address`, `user_agent`, `created_at`, `updated_at`) VALUES
(1, 2, 'MSJ2988762A542B1781948114', NULL, 'credit', 'admin_credit', 2000.00, 0.00, NULL, NULL, 0.00, 0.00, 2000.00, 'Admin credit: Testing', NULL, NULL, NULL, NULL, NULL, NULL, '[]', 'success', '102.91.71.67', NULL, '2026-06-20 05:35:14', '2026-06-20 05:35:14'),
(2, 2, 'MSJ573D03C5909E1781949083', NULL, 'credit', 'admin_credit', 1000.00, 0.00, NULL, NULL, 0.00, 2000.00, 3000.00, 'Admin credit: Test', NULL, NULL, NULL, NULL, NULL, NULL, '[]', 'success', '102.91.71.67', NULL, '2026-06-20 05:51:23', '2026-06-20 05:51:23');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_limits`
--

CREATE TABLE `transaction_limits` (
  `id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `min_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `daily_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_type` enum('regular','vendor','both') NOT NULL DEFAULT 'both',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_limits`
--

INSERT INTO `transaction_limits` (`id`, `service`, `min_amount`, `max_amount`, `daily_limit`, `user_type`, `created_at`) VALUES
(1, 'airtime', 50.00, 100000.00, 500000.00, 'regular', '2026-06-20 15:22:28'),
(2, 'data', 100.00, 50000.00, 300000.00, 'regular', '2026-06-20 15:22:28'),
(3, 'electricity', 500.00, 100000.00, 500000.00, 'regular', '2026-06-20 15:22:28'),
(4, 'cabletv', 500.00, 50000.00, 300000.00, 'regular', '2026-06-20 15:22:28'),
(5, 'bank_transfer', 100.00, 500000.00, 2000000.00, 'regular', '2026-06-20 15:22:28'),
(6, 'airtime_to_cash', 500.00, 50000.00, 200000.00, 'regular', '2026-06-20 15:22:28'),
(7, 'exam_pin', 500.00, 50000.00, 200000.00, 'regular', '2026-06-20 15:22:28'),
(8, 'recharge_card', 100.00, 5000.00, 200000.00, 'regular', '2026-06-20 15:22:28'),
(9, 'data_card', 100.00, 50000.00, 300000.00, 'regular', '2026-06-20 15:22:28'),
(10, 'airtime', 50.00, 500000.00, 2000000.00, 'vendor', '2026-06-20 15:22:28'),
(11, 'data', 100.00, 300000.00, 1000000.00, 'vendor', '2026-06-20 15:22:28'),
(12, 'electricity', 500.00, 500000.00, 2000000.00, 'vendor', '2026-06-20 15:22:28'),
(13, 'cabletv', 500.00, 200000.00, 1000000.00, 'vendor', '2026-06-20 15:22:28'),
(14, 'bank_transfer', 100.00, 2000000.00, 5000000.00, 'vendor', '2026-06-20 15:22:28'),
(15, 'airtime_to_cash', 500.00, 200000.00, 500000.00, 'vendor', '2026-06-20 15:22:28'),
(16, 'exam_pin', 500.00, 200000.00, 500000.00, 'vendor', '2026-06-20 15:22:28'),
(17, 'recharge_card', 100.00, 20000.00, 500000.00, 'vendor', '2026-06-20 15:22:28'),
(18, 'data_card', 100.00, 200000.00, 1000000.00, 'vendor', '2026-06-20 15:22:28');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `user_type` enum('regular','vendor') NOT NULL DEFAULT 'regular',
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `user_type`, `phone`, `password`, `transaction_pin`, `pin_attempts`, `pin_locked_until`, `wallet_balance`, `cashback_balance`, `referral_code`, `referred_by`, `kyc_level`, `kyc_status`, `bvn`, `nin`, `profile_photo`, `is_active`, `is_suspended`, `suspension_reason`, `email_verified`, `email_verify_token`, `reset_token`, `reset_token_expiry`, `last_login`, `last_login_ip`, `created_at`, `updated_at`) VALUES
(1, 'gamaliel chigekwu', 'gamalielchig5925', NULL, 'regular', '07034398627', '$2y$12$AyeWD1QevcXNC9sh1nFyhup18HrCrI5cMMB8GwOIAOfdb/xnsAaDm', NULL, 0, NULL, 0.00, 0.00, '84D56548', NULL, 3, 'approved', NULL, NULL, NULL, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-06-20 12:00:54', '102.90.102.36', '2026-06-19 10:06:47', '2026-06-20 12:02:18'),
(2, 'Jazman Olawale', 'jazmanolawal5490', NULL, 'regular', '09161218511', '$2y$12$GB/EE7WcTuKXIPEKgnkEu.vVVnJdBMKDGkSUTI8lZtTptLtEjTSdi', NULL, 0, NULL, 3000.00, 0.00, 'AB33CB0D', NULL, 0, 'pending', NULL, NULL, NULL, 1, 0, NULL, 0, NULL, NULL, NULL, '2026-06-21 02:57:56', '102.91.77.145', '2026-06-19 12:34:29', '2026-06-21 02:57:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_limits`
--

CREATE TABLE `user_limits` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service` varchar(50) NOT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL,
  `daily_limit` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `virtual_accounts`
--

CREATE TABLE `virtual_accounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'paymentpoint',
  `account_number` varchar(20) NOT NULL,
  `account_name` varchar(200) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `virtual_cards`
--

CREATE TABLE `virtual_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `card_id` varchar(100) NOT NULL,
  `masked_number` varchar(40) DEFAULT NULL,
  `expiry` varchar(10) DEFAULT NULL,
  `brand` varchar(20) DEFAULT 'Visa',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','frozen','terminated') NOT NULL DEFAULT 'active',
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `webhook_logs`
--

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

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_roles`
--
ALTER TABLE `admin_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `airtime_to_cash`
--
ALTER TABLE `airtime_to_cash`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_ref` (`transaction_ref`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `api_providers`
--
ALTER TABLE `api_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

--
-- Indexes for table `api_request_logs`
--
ALTER TABLE `api_request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `channel` (`channel`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_service` (`user_id`,`service`);

--
-- Indexes for table `blocked_ips`
--
ALTER TABLE `blocked_ips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`);

--
-- Indexes for table `cable_plans`
--
ALTER TABLE `cable_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider` (`provider`);

--
-- Indexes for table `cashback_config`
--
ALTER TABLE `cashback_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

--
-- Indexes for table `data_cards`
--
ALTER TABLE `data_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `data_plans`
--
ALTER TABLE `data_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `network_type` (`network`,`data_type`);

--
-- Indexes for table `electricity_discos`
--
ALTER TABLE `electricity_discos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `disco_id` (`disco_id`);

--
-- Indexes for table `email_campaigns`
--
ALTER TABLE `email_campaigns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_pins`
--
ALTER TABLE `exam_pins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `kyc_requests`
--
ALTER TABLE `kyc_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `tier` (`tier`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `identifier` (`identifier`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `login_tracker`
--
ALTER TABLE `login_tracker`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `login_at` (`login_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `recharge_cards`
--
ALTER TABLE `recharge_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `referral_rewards`
--
ALTER TABLE `referral_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `referrer_id` (`referrer_id`);

--
-- Indexes for table `request_nonces`
--
ALTER TABLE `request_nonces`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nonce` (`nonce`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `service_pricing`
--
ALTER TABLE `service_pricing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `service_network` (`service`,`network`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_ref` (`transaction_ref`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `category` (`category`),
  ADD KEY `status` (`status`),
  ADD KEY `external_ref` (`external_ref`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `transaction_limits`
--
ALTER TABLE `transaction_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_type` (`service`,`user_type`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `referred_by` (`referred_by`),
  ADD KEY `kyc_level` (`kyc_level`);

--
-- Indexes for table `user_limits`
--
ALTER TABLE `user_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_service` (`user_id`,`service`);

--
-- Indexes for table `virtual_accounts`
--
ALTER TABLE `virtual_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `account_number` (`account_number`);

--
-- Indexes for table `virtual_cards`
--
ALTER TABLE `virtual_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `card_id` (`card_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `webhook_logs`
--
ALTER TABLE `webhook_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider` (`provider`),
  ADD KEY `created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_roles`
--
ALTER TABLE `admin_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `airtime_to_cash`
--
ALTER TABLE `airtime_to_cash`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_providers`
--
ALTER TABLE `api_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `api_request_logs`
--
ALTER TABLE `api_request_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blocked_ips`
--
ALTER TABLE `blocked_ips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cable_plans`
--
ALTER TABLE `cable_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `cashback_config`
--
ALTER TABLE `cashback_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `data_cards`
--
ALTER TABLE `data_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `data_plans`
--
ALTER TABLE `data_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `electricity_discos`
--
ALTER TABLE `electricity_discos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `email_campaigns`
--
ALTER TABLE `email_campaigns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_pins`
--
ALTER TABLE `exam_pins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kyc_requests`
--
ALTER TABLE `kyc_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_tracker`
--
ALTER TABLE `login_tracker`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `recharge_cards`
--
ALTER TABLE `recharge_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `referral_rewards`
--
ALTER TABLE `referral_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_nonces`
--
ALTER TABLE `request_nonces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_pricing`
--
ALTER TABLE `service_pricing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transaction_limits`
--
ALTER TABLE `transaction_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_limits`
--
ALTER TABLE `user_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `virtual_accounts`
--
ALTER TABLE `virtual_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `virtual_cards`
--
ALTER TABLE `virtual_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhook_logs`
--
ALTER TABLE `webhook_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `login_tracker`
--
ALTER TABLE `login_tracker`
  ADD CONSTRAINT `login_tracker_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_limits`
--
ALTER TABLE `user_limits`
  ADD CONSTRAINT `user_limits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
