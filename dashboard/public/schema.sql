-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 28, 2025 at 01:48 PM
-- Server version: 8.0.42-0ubuntu0.24.04.1
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `server_bot`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions_log`
--

CREATE TABLE `ai_interactions_log` (
  `id` int NOT NULL,
  `log_timestamp_utc` datetime NOT NULL,
  `trading_symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executed_action_by_bot` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ai_decision_params_json` text COLLATE utf8mb4_unicode_ci,
  `bot_feedback_json` text COLLATE utf8mb4_unicode_ci,
  `full_data_for_ai_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `prompt_text_sent_to_ai_md5` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_ai_response_json` text COLLATE utf8mb4_unicode_ci,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_configurations`
--

CREATE TABLE `bot_configurations` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., BTCUSDT_Main_Config',
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BTCUSDT',
  `kline_interval` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1m',
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USDT',
  `default_leverage` int NOT NULL DEFAULT '10',
  `order_check_interval_seconds` int NOT NULL DEFAULT '45',
  `ai_update_interval_seconds` int NOT NULL DEFAULT '60',
  `use_testnet` tinyint(1) NOT NULL DEFAULT '1',
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT '10.50000000',
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `pending_entry_order_cancel_timeout_seconds` int NOT NULL DEFAULT '180',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Only one config can be active per symbol for simple setup',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_runtime_status`
--

CREATE TABLE `bot_runtime_status` (
  `id` int NOT NULL,
  `bot_config_id` int NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., running, stopped, error, initializing, shutdown',
  `last_heartbeat` datetime DEFAULT NULL COMMENT 'Bot updates this periodically',
  `process_id` int DEFAULT NULL COMMENT 'PID of the running bot process',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'Last known error from bot',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
) ;

-- --------------------------------------------------------

--
-- Table structure for table `orders_log`
--

CREATE TABLE `orders_log` (
  `internal_id` int NOT NULL,
  `order_id_binance` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `side` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_logic_source`
--

CREATE TABLE `trade_logic_source` (
  `id` int NOT NULL,
  `source_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `version` int NOT NULL DEFAULT '1',
  `last_updated_by` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_data_snapshot_at_last_update_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'user' COMMENT 'e.g., admin, viewer',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_interactions_log`
--
ALTER TABLE `ai_interactions_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_symbol_timestamp_action` (`trading_symbol`,`log_timestamp_utc`,`executed_action_by_bot`(20)),
  ADD KEY `idx_log_timestamp_utc` (`log_timestamp_utc`);

--
-- Indexes for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_config_name` (`name`),
  ADD KEY `idx_config_symbol_active` (`symbol`,`is_active`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bot_config_id` (`bot_config_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `orders_log`
--
ALTER TABLE `orders_log`
  ADD PRIMARY KEY (`internal_id`),
  ADD KEY `idx_symbol_timestamp` (`symbol`,`bot_event_timestamp_utc`),
  ADD KEY `idx_order_id_binance` (`order_id_binance`),
  ADD KEY `idx_bot_event_timestamp_utc` (`bot_event_timestamp_utc`);

--
-- Indexes for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `source_name` (`source_name`),
  ADD KEY `idx_source_name_active` (`source_name`,`is_active`),
  ADD KEY `idx_is_active_last_updated_at_utc` (`is_active`,`last_updated_at_utc` DESC);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_interactions_log`
--
ALTER TABLE `ai_interactions_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders_log`
--
ALTER TABLE `orders_log`
  MODIFY `internal_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
