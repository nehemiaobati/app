-- phpMyAdmin SQL Dump
-- version 5.2.2deb1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 23, 2025 at 01:45 PM
-- Server version: 11.8.1-MariaDB-2
-- PHP Version: 8.4.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `p2profit_bot_db1`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions_log`
--

CREATE TABLE `ai_interactions_log` (
  `id` int(11) NOT NULL,
  `log_timestamp_utc` datetime NOT NULL,
  `trading_symbol` varchar(20) NOT NULL,
  `executed_action_by_bot` varchar(100) NOT NULL,
  `ai_decision_params_json` text DEFAULT NULL,
  `bot_feedback_json` text DEFAULT NULL,
  `full_data_for_ai_json` mediumtext DEFAULT NULL,
  `prompt_text_sent_to_ai_md5` char(32) DEFAULT NULL,
  `raw_ai_response_json` text DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_configurations`
--

CREATE TABLE `bot_configurations` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'e.g., BTCUSDT_Main_Config',
  `symbol` varchar(20) NOT NULL DEFAULT 'BTCUSDT',
  `kline_interval` varchar(10) NOT NULL DEFAULT '1m',
  `margin_asset` varchar(10) NOT NULL DEFAULT 'USDT',
  `default_leverage` int(11) NOT NULL DEFAULT 10,
  `order_check_interval_seconds` int(11) NOT NULL DEFAULT 45,
  `ai_update_interval_seconds` int(11) NOT NULL DEFAULT 60,
  `use_testnet` tinyint(1) NOT NULL DEFAULT 1,
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT 10.50000000,
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `pending_entry_order_cancel_timeout_seconds` int(11) NOT NULL DEFAULT 180,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Only one config can be active per symbol for simple setup',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_runtime_status`
--

CREATE TABLE `bot_runtime_status` (
  `id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL COMMENT 'e.g., running, stopped, error, initializing, shutdown',
  `last_heartbeat` datetime DEFAULT NULL COMMENT 'Bot updates this periodically',
  `process_id` int(11) DEFAULT NULL COMMENT 'PID of the running bot process',
  `error_message` text DEFAULT NULL COMMENT 'Last known error from bot',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`current_position_details_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders_log`
--

CREATE TABLE `orders_log` (
  `internal_id` int(11) NOT NULL,
  `order_id_binance` varchar(50) DEFAULT NULL,
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `side` varchar(10) NOT NULL,
  `status_reason` varchar(100) NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_logic_source`
--

CREATE TABLE `trade_logic_source` (
  `id` int(11) NOT NULL,
  `source_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(11) NOT NULL DEFAULT 1,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text NOT NULL DEFAULT '{"schema_version": "1.0.0", "strategy_type": "GENERAL_TRADING", "current_market_bias": "NEUTRAL", "preferred_timeframes_for_entry": ["1m", "5m", "15m"], "key_sr_levels_to_watch": {"support": [], "resistance": []}, "risk_parameters": {"target_risk_per_trade_usdt": 5.0, "default_rr_ratio": 1.5, "max_concurrent_positions": 1}, "entry_conditions_keywords": ["momentum_confirm", "breakout_consolidation"], "exit_conditions_keywords": ["momentum_stall", "target_profit_achieved"], "leverage_preference": {"min": 5, "max": 20, "preferred": 10}, "ai_confidence_threshold_for_trade": 0.7, "ai_learnings_notes": "Initial default strategy directives. AI to adapt based on market and trade outcomes.", "allow_ai_to_update_self": true, "emergency_hold_justification": "Wait for clear market signal or manual intervention."}',
  `full_data_snapshot_at_last_update_json` mediumtext DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user' COMMENT 'e.g., admin, viewer',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
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
  ADD KEY `idx_symbol_timestamp_action` (`trading_symbol`,`log_timestamp_utc`,`executed_action_by_bot`(20));

--
-- Indexes for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_config_name` (`name`),
  ADD KEY `idx_config_symbol_active` (`symbol`,`is_active`);

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
  ADD KEY `idx_order_id_binance` (`order_id_binance`);

--
-- Indexes for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `source_name` (`source_name`),
  ADD KEY `idx_source_name_active` (`source_name`,`is_active`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders_log`
--
ALTER TABLE `orders_log`
  MODIFY `internal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
