<?php
// src/config.php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Database Configuration
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_PORT', $_ENV['DB_PORT']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASSWORD', $_ENV['DB_PASSWORD']);

// Binance Public API Base URL (for Klines from Dashboard directly)
define('BINANCE_FUTURES_PROD_REST_API_BASE_URL', 'https://fapi.binance.com');
define('BINANCE_FUTURES_TEST_REST_API_BASE_URL', 'https://testnet.binancefuture.com');

// Path to the bot script for process manager
define('BOT_SCRIPT_PATH', $_ENV['BOT_SCRIPT_PATH']);

// Define session timeout (e.g., 1 hour)
define('SESSION_TIMEOUT', 3600);