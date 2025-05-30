<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$dotenv->required(['BINANCE_API_KEY', 'BINANCE_API_SECRET', 'GEMINI_API_KEY', 'GEMINI_MODEL_NAME', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']);

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

// --- Environment Variable & DB Configuration Loading (for the bot itself) ---
$binanceApiKey = $_ENV['BINANCE_API_KEY'];
$binanceApiSecret = $_ENV['BINANCE_API_SECRET'];
$geminiApiKey = $_ENV['GEMINI_API_KEY'];
$geminiModelName = $_ENV['GEMINI_MODEL_NAME'];

$dbHost = $_ENV['DB_HOST'];
$dbPort = $_ENV['DB_PORT'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];

class AiTradingBotFutures
{
    // --- Constants ---
    private const BINANCE_FUTURES_PROD_REST_API_BASE_URL = 'https://fapi.binance.com';
    private const BINANCE_FUTURES_TEST_REST_API_BASE_URL = 'https://testnet.binancefuture.com';
    private const BINANCE_FUTURES_PROD_WS_BASE_URL = 'wss://fstream.binance.com';
    private const BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED = 'wss://stream.binancefuture.com';

    private const BINANCE_API_RECV_WINDOW = 5000;
    private const MAX_ORDER_LOG_ENTRIES_FOR_AI_CONTEXT = 10; // How many recent order logs to fetch from DB for AI
    private const MAX_AI_INTERACTIONS_FOR_AI_CONTEXT = 3; // How many recent AI interactions to fetch for AI
    private const LISTEN_KEY_REFRESH_INTERVAL = 30 * 60; // 30 minutes in seconds
    private const DEFAULT_TRADE_LOGIC_SOURCE_NAME = 'default_strategy_v1'; // Generic default strategy name
    private const BOT_HEARTBEAT_INTERVAL = 10; // Seconds between status updates in DB

    // --- Configuration Properties (Loaded from DB) ---
    private int $botConfigId;
    private string $tradingSymbol;
    private string $klineInterval; // Main kline interval for live price updates & bot's primary reaction timeframe
    private string $marginAsset;
    private int $defaultLeverage; // Fallback leverage if strategy doesn't specify or AI omits
    private int $orderCheckIntervalSeconds; // Fallback order status check
    private int $maxScriptRuntimeSeconds; // Max runtime specified in DB
    private int $aiUpdateIntervalSeconds; // How often to consult the AI
    private bool $useTestnet;
    private int $pendingEntryOrderCancelTimeoutSeconds;
    private float $initialMarginTargetUsdt; // AI uses this to calculate quantity for new positions
    private array $historicalKlineIntervalsAIArray; // Kline intervals for AI's historical analysis
    private string $primaryHistoricalKlineIntervalAI; // A "main" historical interval AI might focus on
    private float $takeProfitTargetUsdt; // PnL in USDT to trigger automatic position close by the bot
    private int $profitCheckIntervalSeconds; // How often bot checks PnL for the above target

    // --- Static API Keys (from environment variables) ---
    private string $binanceApiKey;
    private string $binanceApiSecret;
    private string $geminiApiKey;
    private string $geminiModelName;

    // --- Runtime Base URLs ---
    private string $currentRestApiBaseUrl;
    private string $currentWsBaseUrlCombined;

    // --- Database Properties ---
    private string $dbHost;
    private string $dbPort;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;
    private ?PDO $pdo = null;

    // --- Dependencies ---
    private LoopInterface $loop;
    private Browser $browser;
    private LoggerInterface $logger;
    private ?WebSocket $wsConnection = null;

    // --- State Properties ---
    private ?float $lastClosedKlinePrice = null; // Price from the main $klineInterval stream
    private ?string $activeEntryOrderId = null;
    private ?int $activeEntryOrderTimestamp = null; // Timestamp of when the entry order was placed
    private ?string $activeSlOrderId = null;
    private ?string $activeTpOrderId = null;
    private ?array $currentPositionDetails = null; // [symbol, side, entryPrice, quantity, leverage, unrealizedPnl, markPrice, etc.]
    private bool $isPlacingOrManagingOrder = false; // General lock for complex operations (placing entry, SL/TP, closing)
    private ?string $listenKey = null; // For Binance User Data Stream
    private ?\React\EventLoop\TimerInterface $listenKeyRefreshTimer = null;
    private ?\React\EventLoop\TimerInterface $heartbeatTimer = null;
    private bool $isMissingProtectiveOrder = false; // Flag if position open but SL/TP missing (critical state)
    private ?array $lastAIDecisionResult = null; // Stores outcome of last AI action for feedback loop
    private ?int $botStartTime = null; // Timestamp of when the bot started running

    // AI Suggested Parameters (set by AI, used by bot to open positions)
    private float $aiSuggestedEntryPrice;
    private float $aiSuggestedSlPrice;
    private float $aiSuggestedTpPrice;
    private float $aiSuggestedQuantity;
    private string $aiSuggestedSide; // BUY (long) or SELL (short)
    private int $aiSuggestedLeverage;

    // For AI interaction logging (data available at the time of AI call)
    private ?array $currentDataForAIForDBLog = null;
    private ?string $currentPromptMD5ForDBLog = null;
    private ?string $currentRawAIResponseForDBLog = null;
    private ?array $currentActiveTradeLogicSource = null; // Holds the currently active strategy directives from DB


    public function __construct(
        int $botConfigId,
        string $binanceApiKey, string $binanceApiSecret,
        string $geminiApiKey, string $geminiModelName,
        string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPassword
    ) {
        $this->botConfigId = $botConfigId;
        $this->binanceApiKey = $binanceApiKey;
        $this->binanceApiSecret = $binanceApiSecret;
        $this->geminiApiKey = $geminiApiKey;
        $this->geminiModelName = $geminiModelName;
        $this->dbHost = $dbHost; $this->dbPort = $dbPort; $this->dbName = $dbName;
        $this->dbUser = $dbUser; $this->dbPassword = $dbPassword;

        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop); // Pass $loop to Browser constructor for ReactPHP 1.x and higher
        $logFormat = "[%datetime%] [%level_name%] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG); // Log to STDOUT
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBotFutures');
        $this->logger->pushHandler($streamHandler);

        $this->initializeDatabaseConnection();
        $this->loadBotConfigurationFromDb($this->botConfigId);
        $this->loadActiveTradeLogicSource(); // Load initial strategy from DB or use default

        $this->currentRestApiBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_REST_API_BASE_URL : self::BINANCE_FUTURES_PROD_REST_API_BASE_URL;
        $this->currentWsBaseUrlCombined = $this->useTestnet ? self::BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED : self::BINANCE_FUTURES_PROD_WS_BASE_URL;

        $this->logger->info('AiTradingBotFutures instance created and configured.', [
            'bot_config_id' => $this->botConfigId,
            'symbol' => $this->tradingSymbol, 'kline_interval' => $this->klineInterval,
            'ai_model' => $this->geminiModelName, 'testnet' => $this->useTestnet,
            'db_status' => $this->pdo ? "Connected to '{$this->dbName}'" : 'NOT Connected to DB',
            'active_trade_logic_source' => $this->currentActiveTradeLogicSource ? ($this->currentActiveTradeLogicSource['source_name'] . ' v' . $this->currentActiveTradeLogicSource['version']) : 'None Loaded/Default Fallback',
        ]);
        $this->aiSuggestedLeverage = $this->defaultLeverage; // Initialize AI suggested leverage
    }

    private function initializeDatabaseConnection(): void
    {
        $dsn = "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch rows as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Disable emulation for real prepared statements
        ];
        try {
            $this->pdo = new PDO($dsn, $this->dbUser, $this->dbPassword, $options);
            $this->logger->info("Successfully connected to database '{$this->dbName}' on {$this->dbHost}.");
            $this->checkAndCreateTables(); // Ensure tables exist
        } catch (\PDOException $e) {
            $this->pdo = null; // Explicitly set to null on failure
            $this->logger->error("Database connection failed: " . $e->getMessage(), [
                'host' => $this->dbHost, 'dbname' => $this->dbName, 'user' => $this->dbUser, 'exception_code' => $e->getCode()
            ]);
            // If DB connection fails, the bot cannot run, so re-throw
            throw new \RuntimeException("Database connection failed at startup: " . $e->getMessage());
        }
    }

    // New: Load bot configuration from the database based on botConfigId
    private function loadBotConfigurationFromDb(int $configId): void
    {
        if (!$this->pdo) {
            throw new \RuntimeException("Cannot load bot configuration: Database not connected.");
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = :id AND is_active = TRUE");
            $stmt->execute([':id' => $configId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                throw new \RuntimeException("Bot configuration with ID {$configId} not found or is not active.");
            }

            // Assign fetched values to properties
            $this->tradingSymbol = strtoupper($config['symbol']);
            $this->klineInterval = $config['kline_interval'];
            $this->marginAsset = strtoupper($config['margin_asset']);
            $this->defaultLeverage = (int)$config['default_leverage'];
            $this->orderCheckIntervalSeconds = (int)$config['order_check_interval_seconds'];
            $this->aiUpdateIntervalSeconds = (int)$config['ai_update_interval_seconds'];
            $this->useTestnet = (bool)$config['use_testnet'];
            $this->pendingEntryOrderCancelTimeoutSeconds = (int)$config['pending_entry_order_cancel_timeout_seconds'];
            $this->initialMarginTargetUsdt = (float)$config['initial_margin_target_usdt'];
            $this->takeProfitTargetUsdt = (float)$config['take_profit_target_usdt'];
            $this->profitCheckIntervalSeconds = (int)$config['profit_check_interval_seconds'];
            $this->maxScriptRuntimeSeconds = 86400; // Hardcode for now, or add to config table if truly dynamic. For this plan, keep it fixed.

            $this->historicalKlineIntervalsAIArray = ['1m', '5m', '15m', '30m', '1h', '6h', '12h','1d']; // These are usually fixed
            $this->primaryHistoricalKlineIntervalAI = '5m'; // And this

            $this->logger->info("Bot configuration loaded from DB for ID {$configId}.", [
                'symbol' => $this->tradingSymbol,
                'testnet' => $this->useTestnet,
                'ai_update_interval' => $this->aiUpdateIntervalSeconds
            ]);

        } catch (\PDOException $e) {
            error_log("Error loading bot configuration from DB: " . $e->getMessage());
            throw new \RuntimeException("Failed to load bot configuration from DB: " . $e->getMessage());
        }
    }

    // Updates bot_runtime_status table
    private function updateBotStatus(string $status, ?string $errorMessage = null): bool
    {
        if (!$this->pdo) {
            $this->logger->error("DB not connected. Cannot update bot runtime status.");
            return false;
        }

        try {
            // Get current PID
            $pid = getmypid();

            $stmt = $this->pdo->prepare("
                INSERT INTO bot_runtime_status (bot_config_id, status, last_heartbeat, process_id, error_message)
                VALUES (:bot_config_id, :status, NOW(), :process_id, :error_message)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    last_heartbeat = VALUES(last_heartbeat),
                    process_id = VALUES(process_id),
                    error_message = VALUES(error_message)
            ");
            $stmt->execute([
                ':bot_config_id' => $this->botConfigId,
                ':status' => $status,
                ':process_id' => $pid,
                ':error_message' => $errorMessage
            ]);
            $this->logger->debug("Bot runtime status updated in DB.", ['status' => $status, 'config_id' => $this->botConfigId, 'pid' => $pid]);
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to update bot runtime status in DB: " . $e->getMessage(), ['status' => $status]);
            return false;
        }
    }


    private function checkAndCreateTables(): void
    {
        if (!$this->pdo) {
            $this->logger->warning("Cannot check/create DB tables: Database not connected.");
            return;
        }
        $sqls = [
            "orders_log" => "CREATE TABLE IF NOT EXISTS `orders_log` (
              `internal_id` INT AUTO_INCREMENT PRIMARY KEY,
              `order_id_binance` VARCHAR(50) NULL,
              `bot_event_timestamp_utc` DATETIME NOT NULL,
              `symbol` VARCHAR(20) NOT NULL,
              `side` VARCHAR(10) NOT NULL,
              `status_reason` VARCHAR(100) NOT NULL,
              `price_point` DECIMAL(20, 8) NULL,
              `quantity_involved` DECIMAL(20, 8) NULL,
              `margin_asset` VARCHAR(10) NULL,
              `realized_pnl_usdt` DECIMAL(20, 8) NULL,
              `commission_usdt` DECIMAL(20, 8) NULL,
              `created_at_db` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_symbol_timestamp` (`symbol`, `bot_event_timestamp_utc`),
              INDEX `idx_order_id_binance` (`order_id_binance`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "ai_interactions_log" => "CREATE TABLE IF NOT EXISTS `ai_interactions_log` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `log_timestamp_utc` DATETIME NOT NULL,
              `trading_symbol` VARCHAR(20) NOT NULL,
              `executed_action_by_bot` VARCHAR(100) NOT NULL,
              `ai_decision_params_json` TEXT NULL,
              `bot_feedback_json` TEXT NULL,
              `full_data_for_ai_json` MEDIUMTEXT NULL,
              `prompt_text_sent_to_ai_md5` CHAR(32) NULL,
              `raw_ai_response_json` TEXT NULL,
              `created_at_db` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_symbol_timestamp_action` (`trading_symbol`, `log_timestamp_utc`, `executed_action_by_bot`(20))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "trade_logic_source" => "CREATE TABLE IF NOT EXISTS `trade_logic_source` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `source_name` VARCHAR(100) NOT NULL UNIQUE,
              `is_active` BOOLEAN NOT NULL DEFAULT FALSE,
              `version` INT NOT NULL DEFAULT 1,
              `last_updated_by` VARCHAR(50) NULL,
              `last_updated_at_utc` DATETIME NULL,
              `strategy_directives_json` TEXT NOT NULL,
              `full_data_snapshot_at_last_update_json` MEDIUMTEXT NULL,
              `created_at_db` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_source_name_active` (`source_name`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Added tables from dashboard plan for completeness, though bot itself might not create them
            "users_table" => "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) UNIQUE NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `email` VARCHAR(100) UNIQUE NULL,
                `role` VARCHAR(20) DEFAULT 'user',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_login` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "bot_configurations_table" => "CREATE TABLE IF NOT EXISTS `bot_configurations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) UNIQUE NOT NULL,
                `symbol` VARCHAR(20) NOT NULL,
                `kline_interval` VARCHAR(10) NOT NULL,
                `margin_asset` VARCHAR(10) NOT NULL,
                `default_leverage` INT NOT NULL,
                `order_check_interval_seconds` INT NOT NULL,
                `ai_update_interval_seconds` INT NOT NULL,
                `use_testnet` BOOLEAN NOT NULL DEFAULT TRUE,
                `initial_margin_target_usdt` DECIMAL(20, 8) NOT NULL,
                `take_profit_target_usdt` DECIMAL(20, 8) NOT NULL,
                `pending_entry_order_cancel_timeout_seconds` INT NOT NULL,
                `profit_check_interval_seconds` INT NOT NULL, -- Added to config table
                `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `created_by_user_id` INT NULL,
                INDEX `idx_config_name` (`name`),
                INDEX `idx_config_symbol_active` (`symbol`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "bot_runtime_status_table" => "CREATE TABLE IF NOT EXISTS `bot_runtime_status` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `bot_config_id` INT NOT NULL,
                `status` VARCHAR(50) NOT NULL,
                `last_heartbeat` DATETIME NULL,
                `process_id` INT NULL,
                `error_message` TEXT NULL,
                `current_position_details_json` TEXT NULL, -- Added to store current position for dashboard
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE (`bot_config_id`),
                INDEX `idx_status` (`status`),
                FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];
        try {
            foreach ($sqls as $tableName => $sql) {
                $this->pdo->exec($sql);
                $this->logger->info("Table '{$tableName}' checked/created successfully.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Failed to check/create database tables: " . $e->getMessage());
        }
    }

    // Default strategy directives if no active source found or DB fails
    private function getDefaultStrategyDirectives(): array
    {
        return [
            'schema_version' => '1.0.0',
            'strategy_type' => 'GENERAL_TRADING', // Generic type
            'current_market_bias' => 'NEUTRAL',
            'preferred_timeframes_for_entry' => ['1m', '5m', '15m'], // Default preferred timeframes for AI to focus on
            'key_sr_levels_to_watch' => ['support' => [], 'resistance' => []], // AI should populate
            'risk_parameters' => [
                'target_risk_per_trade_usdt' => 5.0, // Default risk per trade
                'default_rr_ratio' => 1.5,           // Default Reward/Risk ratio
                'max_concurrent_positions' => 1,     // Max concurrent positions
            ],
            'entry_conditions_keywords' => ['momentum_confirm', 'breakout_consolidation'],
            'exit_conditions_keywords' => ['momentum_stall', 'target_profit_achieved'],
            'leverage_preference' => ['min' => 5, 'max' => 20, 'preferred' => 10], // Default leverage preference
            'ai_confidence_threshold_for_trade' => 0.7, // Hypothetical: AI might internally use this
            'ai_learnings_notes' => 'Initial default strategy directives. AI to adapt based on market and trade outcomes.',
            'allow_ai_to_update_self' => true, // Flag if AI is permitted to suggest updates to this source
            'emergency_hold_justification' => 'Wait for clear market signal or manual intervention.' // For CRITICAL state HOLD
        ];
    }

    private function loadActiveTradeLogicSource(): void
    {
        if (!$this->pdo) {
            $this->logger->warning("DB not connected. Using hardcoded default strategy directives for trade logic source.");
            $defaultDirectives = $this->getDefaultStrategyDirectives();
            $this->currentActiveTradeLogicSource = [
                'id' => 0, // Indicate not from DB
                'source_name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME . '_fallback',
                'is_active' => true, 'version' => 1, 'last_updated_by' => 'SYSTEM_FALLBACK',
                'last_updated_at_utc' => gmdate('Y-m-d H:i:s'),
                'strategy_directives_json' => json_encode($defaultDirectives), // Store raw JSON
                'strategy_directives' => $defaultDirectives, // Store decoded
                'full_data_snapshot_at_last_update_json' => null
            ];
            return;
        }

        $sql = "SELECT * FROM trade_logic_source WHERE is_active = TRUE ORDER BY last_updated_at_utc DESC LIMIT 1";
        try {
            $stmt = $this->pdo->query($sql);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($source) {
                $this->currentActiveTradeLogicSource = $source;
                $decodedDirectives = json_decode((string)$source['strategy_directives_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->currentActiveTradeLogicSource['strategy_directives'] = $decodedDirectives;
                } else {
                    $this->logger->error("Failed to decode strategy_directives_json from DB for source '{$source['source_name']}'. Using default content.", ['json_error' => json_last_error_msg()]);
                    $this->currentActiveTradeLogicSource['strategy_directives'] = $this->getDefaultStrategyDirectives();
                }
                $this->logger->info("Loaded active trade logic source '{$source['source_name']}' v{$source['version']} from DB.");
            } else {
                $this->logger->warning("No active trade logic source found in DB. Creating and activating a default one: " . self::DEFAULT_TRADE_LOGIC_SOURCE_NAME);
                $defaultDirectives = $this->getDefaultStrategyDirectives();
                $defaultJson = json_encode($defaultDirectives);
                $insertSql = "INSERT INTO trade_logic_source (source_name, is_active, version, last_updated_by, last_updated_at_utc, strategy_directives_json)
                              VALUES (:name, TRUE, 1, 'SYSTEM_DEFAULT', :now, :directives)
                              ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), version=GREATEST(version, VALUES(version)), last_updated_by='SYSTEM_RESET_OR_INSERT', last_updated_at_utc=VALUES(last_updated_at_utc), strategy_directives_json=VALUES(strategy_directives_json)";
                $insertStmt = $this->pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME,
                    ':now' => gmdate('Y-m-d H:i:s'),
                    ':directives' => $defaultJson
                ]);
                $this->loadActiveTradeLogicSource(); // Reload to get the newly inserted/updated one
            }
        } catch (\PDOException $e) {
            $this->logger->error("Failed to load active trade logic source from DB: " . $e->getMessage() . ". Using hardcoded default.", ['exception_code' => $e->getCode()]);
            $defaultDirectives = $this->getDefaultStrategyDirectives();
            $this->currentActiveTradeLogicSource = [
                'id' => 0, 'source_name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME . '_dberror_fallback', 'is_active' => true, 'version' => 1,
                'last_updated_by' => 'SYSTEM_DB_ERROR', 'last_updated_at_utc' => gmdate('Y-m-d H:i:s'),
                'strategy_directives_json' => json_encode($defaultDirectives),
                'strategy_directives' => $defaultDirectives,
                'full_data_snapshot_at_last_update_json' => null
            ];
        }
    }

    private function updateTradeLogicSourceInDb(array $updatedDirectives, string $reasonForUpdate, ?array $currentFullDataForAI): bool
    {
        if (!$this->pdo || !$this->currentActiveTradeLogicSource || !isset($this->currentActiveTradeLogicSource['id']) || $this->currentActiveTradeLogicSource['id'] === 0) {
            $this->logger->error("Cannot update trade logic source: DB not connected or no valid active source loaded from DB.", [
                'active_source_id' => $this->currentActiveTradeLogicSource['id'] ?? 'N/A',
                'active_source_name' => $this->currentActiveTradeLogicSource['source_name'] ?? 'N/A'
            ]);
            return false;
        }

        $sourceIdToUpdate = (int)$this->currentActiveTradeLogicSource['id'];
        $newVersion = (int)$this->currentActiveTradeLogicSource['version'] + 1;

        // Append update reason to AI learnings notes
        $timestampedReason = gmdate('Y-m-d H:i:s') . ' UTC - AI Update (v' . $newVersion . '): ' . $reasonForUpdate;
        if(isset($updatedDirectives['ai_learnings_notes']) && is_string($updatedDirectives['ai_learnings_notes'])) {
            $updatedDirectives['ai_learnings_notes'] = $timestampedReason . "\n" . $updatedDirectives['ai_learnings_notes'];
        } else {
            $updatedDirectives['ai_learnings_notes'] = $timestampedReason;
        }
        // Ensure schema_version is present
        $updatedDirectives['schema_version'] = $updatedDirectives['schema_version'] ?? ($this->currentActiveTradeLogicSource['strategy_directives']['schema_version'] ?? '1.0.0');


        $sql = "UPDATE trade_logic_source
                SET version = :version,
                    last_updated_by = 'AI',
                    last_updated_at_utc = :now,
                    strategy_directives_json = :directives,
                    full_data_snapshot_at_last_update_json = :snapshot
                WHERE id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':version' => $newVersion,
                ':now' => gmdate('Y-m-d H:i:s'),
                ':directives' => json_encode($updatedDirectives, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
                ':snapshot' => $currentFullDataForAI ? json_encode($currentFullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':id' => $sourceIdToUpdate
            ]);
            $this->logger->info("Trade logic source '{$this->currentActiveTradeLogicSource['source_name']}' updated to v{$newVersion} by AI.", ['reason' => $reasonForUpdate]);
            $this->loadActiveTradeLogicSource(); // Reload the updated source into memory
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to update trade logic source in DB: " . $e->getMessage(), ['source_id' => $sourceIdToUpdate]);
            return false;
        }
    }

    private function logOrderToDb(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt): bool
    {
        if (!$this->pdo) {
            $this->logger->warning("DB not connected. Cannot log order to DB.", compact('orderId', 'status'));
            return false;
        }
        $sql = "INSERT INTO orders_log (order_id_binance, bot_event_timestamp_utc, symbol, side, status_reason, price_point, quantity_involved, margin_asset, realized_pnl_usdt, commission_usdt)
                VALUES (:order_id_binance, :bot_event_timestamp_utc, :symbol, :side, :status_reason, :price_point, :quantity_involved, :margin_asset, :realized_pnl_usdt, :commission_usdt)";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':order_id_binance' => $orderId,
                ':bot_event_timestamp_utc' => gmdate('Y-m-d H:i:s', $timestamp), // Store in UTC
                ':symbol' => $assetPair, ':side' => $side, ':status_reason' => $status,
                ':price_point' => $price, ':quantity_involved' => $quantity,
                ':margin_asset' => $marginAsset, ':realized_pnl_usdt' => $realizedPnl,
                ':commission_usdt' => $commissionUsdt
            ]);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to log order to DB: " . $e->getMessage(), compact('orderId', 'status'));
            return false;
        }
    }

    private function getRecentOrderLogsFromDb(string $symbol, int $limit): array
    {
        if (!$this->pdo) {
            $this->logger->warning("DB not connected. Cannot fetch recent order logs.");
            return [['error' => 'Database not connected']];
        }
        $sql = "SELECT order_id_binance as orderId, status_reason as status, side, symbol as assetPair, price_point as price, quantity_involved as quantity, margin_asset as marginAsset, DATE_FORMAT(bot_event_timestamp_utc, '%Y-%m-%d %H:%i:%s UTC') as timestamp, realized_pnl_usdt as realizedPnl
                FROM orders_log WHERE symbol = :symbol ORDER BY bot_event_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':symbol', $symbol, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($log) { // Ensure numeric types for consistency
                $log['price'] = isset($log['price']) ? (float)$log['price'] : null;
                $log['quantity'] = isset($log['quantity']) ? (float)$log['quantity'] : null;
                $log['realizedPnl'] = isset($log['realizedPnl']) ? (float)$log['realizedPnl'] : 0.0;
                return $log;
            }, $logs);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to fetch recent order logs from DB: " . $e->getMessage(), compact('symbol'));
            return [['error' => 'Failed to fetch order logs: ' . $e->getMessage()]];
        }
    }

     private function logAIInteractionToDb(string $executedAction, ?array $aiDecisionParams, ?array $botFeedback, ?array $fullDataForAI, ?string $promptMd5 = null, ?string $rawAiResponse = null): bool
    {
        if (!$this->pdo) {
            $this->logger->warning("DB not connected. Cannot log AI interaction to DB.", compact('executedAction'));
            return false;
        }
        $sql = "INSERT INTO ai_interactions_log (log_timestamp_utc, trading_symbol, executed_action_by_bot, ai_decision_params_json, bot_feedback_json, full_data_for_ai_json, prompt_text_sent_to_ai_md5, raw_ai_response_json)
                VALUES (:log_timestamp_utc, :trading_symbol, :executed_action_by_bot, :ai_decision_params_json, :bot_feedback_json, :full_data_for_ai_json, :prompt_text_sent_to_ai_md5, :raw_ai_response_json)";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':log_timestamp_utc' => gmdate('Y-m-d H:i:s'),
                ':trading_symbol' => $this->tradingSymbol,
                ':executed_action_by_bot' => $executedAction,
                ':ai_decision_params_json' => $aiDecisionParams ? json_encode($aiDecisionParams, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':bot_feedback_json' => $botFeedback ? json_encode($botFeedback, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':full_data_for_ai_json' => $fullDataForAI ? json_encode($fullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':prompt_text_sent_to_ai_md5' => $promptMd5,
                ':raw_ai_response_json' => $rawAiResponse ? json_encode(json_decode($rawAiResponse, true), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null, // Try to re-encode to ensure valid JSON if AI returns raw string
            ]);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to log AI interaction to DB: " . $e->getMessage(), ['action' => $executedAction, 'error' => substr($e->getMessage(), 0, 200)]);
            return false;
        }
    }

    private function getRecentAIInteractionsFromDb(string $symbol, int $limit): array
    {
        if (!$this->pdo) {
            $this->logger->warning("DB not connected. Cannot fetch recent AI interactions.");
            return [['error' => 'Database not connected']];
        }
        $sql = "SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
                FROM ai_interactions_log WHERE trading_symbol = :symbol ORDER BY log_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':symbol', $symbol, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($interaction) { // Decode JSON fields
                $interaction['ai_decision_params'] = isset($interaction['ai_decision_params_json']) ? json_decode($interaction['ai_decision_params_json'], true) : null;
                $interaction['bot_feedback'] = isset($interaction['bot_feedback_json']) ? json_decode($interaction['bot_feedback_json'], true) : null;
                unset($interaction['ai_decision_params_json'], $interaction['bot_feedback_json']);
                return $interaction;
            }, $interactions);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to fetch recent AI interactions from DB: " . $e->getMessage(), compact('symbol'));
            return [['error' => 'Failed to fetch AI interactions: ' . $e->getMessage()]];
        }
    }

    public function run(): void
    {
        $this->botStartTime = time();
        $this->logger->info('Starting AI Trading Bot (Futures) initialization...');
        $this->updateBotStatus('initializing'); // Update status in DB

        \React\Promise\all([
            'initial_balance' => $this->getFuturesAccountBalance(),
            'initial_price' => $this->getLatestKlineClosePrice($this->tradingSymbol, $this->klineInterval),
            'initial_position' => $this->getPositionInformation($this->tradingSymbol),
            'listen_key' => $this->startUserDataStream(),
        ])->then(
            function ($results) {
                $initialBalance = $results['initial_balance'][$this->marginAsset] ?? ['availableBalance' => 0.0, 'balance' => 0.0];
                $this->lastClosedKlinePrice = (float)($results['initial_price']['price'] ?? 0);
                $this->currentPositionDetails = $this->formatPositionDetails($results['initial_position']);
                $this->listenKey = $results['listen_key']['listenKey'] ?? null;

                if ($this->lastClosedKlinePrice <= 0) {
                    throw new \RuntimeException("Failed to fetch a valid initial price for {$this->tradingSymbol} using {$this->klineInterval} interval.");
                }
                if (!$this->listenKey) {
                    throw new \RuntimeException("Failed to obtain a listenKey for User Data Stream.");
                }

                $this->logger->info('Initialization Success', [
                    'startup_' . $this->marginAsset . '_balance' => $initialBalance,
                    'initial_market_price_' . $this->tradingSymbol . '_' . $this->klineInterval => $this->lastClosedKlinePrice,
                    'initial_position' => $this->currentPositionDetails ?? 'No position',
                    'listen_key_obtained' => (bool)$this->listenKey,
                ]);

                $this->connectWebSocket();
                $this->setupTimers();
                $this->loop->addTimer(5, fn() => $this->triggerAIUpdate()); // Initial AI update shortly after start
                $this->updateBotStatus('running'); // Update status in DB
            },
            function (\Throwable $e) {
                $this->logger->error('Initialization failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(),0,1000)]);
                $this->updateBotStatus('error', $e->getMessage()); // Update status in DB
                $this->stop();
            }
        );
        $this->logger->info('Starting event loop...');
        $this->loop->run();
        $this->logger->info('Event loop finished.');
        $this->updateBotStatus('stopped'); // Update status in DB on graceful exit
    }

    private function stop(): void
    {
        $this->logger->info('Stopping event loop and resources...');
        if ($this->heartbeatTimer) {
             $this->loop->cancelTimer($this->heartbeatTimer);
             $this->heartbeatTimer = null;
        }
        if ($this->listenKeyRefreshTimer) {
            $this->loop->cancelTimer($this->listenKeyRefreshTimer);
            $this->listenKeyRefreshTimer = null;
        }
        if ($this->listenKey) {
            $this->closeUserDataStream($this->listenKey)->then(
                fn() => $this->logger->info("ListenKey closed successfully."),
                fn($e) => $this->logger->error("Failed to close ListenKey.", ['err' => $e->getMessage()])
            )->finally(function() { $this->listenKey = null; });
        }
        if ($this->wsConnection && method_exists($this->wsConnection, 'close')) {
             $this->logger->debug('Closing WebSocket connection...');
             try { $this->wsConnection->close(); } catch (\Exception $e) { $this->logger->warning("Exception while closing WebSocket: " . $e->getMessage()); }
             $this->wsConnection = null;
        }
        // No need to close PDO explicitly, PHP will handle it on script exit.
        // But ensures it's set to null so no new ops are attempted with a stale connection.
        $this->pdo = null;
        $this->logger->info("Database connection implicitly closed.");
        $this->loop->stop();
    }

    private function connectWebSocket(): void
    {
        if (!$this->listenKey) {
            $this->logger->error("Cannot connect WebSocket without a listenKey. Stopping.");
            $this->stop();
            return;
        }
        $klineStream = strtolower($this->tradingSymbol) . '@kline_' . $this->klineInterval;
        $wsUrl = $this->currentWsBaseUrlCombined . '/stream?streams=' . $klineStream . '/' . $this->listenKey;
        $this->logger->info('Connecting to Binance Futures Combined WebSocket', ['url' => $wsUrl]);
        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');
                $conn->on('message', fn($msg) => $this->handleWsMessage((string)$msg));
                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                    $this->updateBotStatus('error', "WS Error: " . $e->getMessage());
                    $this->stop(); // Stop the bot on critical WS error
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->wsConnection = null;
                    $this->updateBotStatus('error', "WS Closed: Code {$code}, Reason: {$reason}"); // Treat unexpected close as error
                    $this->stop(); // Stop the bot if WS closes unexpectedly
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                $this->updateBotStatus('error', "WS Connect Failed: " . $e->getMessage());
                $this->stop(); // Stop the bot if WS connection cannot be established
            }
        );
    }

    private function setupTimers(): void
    {
        // --- Heartbeat Timer ---
        $this->heartbeatTimer = $this->loop->addPeriodicTimer(self::BOT_HEARTBEAT_INTERVAL, function () {
            // Include current position details in the heartbeat for dashboard
            $positionDetailsJson = null;
            if ($this->currentPositionDetails) {
                 $positionDetailsJson = json_encode($this->currentPositionDetails, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
            }
            $this->updateBotStatus('running', null); // Clear any old error messages
            // Manually update current_position_details_json in bot_runtime_status.
            // This is a direct PDO call to avoid passing the whole position array to updateBotStatus
            if ($this->pdo) {
                 try {
                     $stmt = $this->pdo->prepare("UPDATE bot_runtime_status SET current_position_details_json = :json WHERE bot_config_id = :config_id");
                     $stmt->execute([':json' => $positionDetailsJson, ':config_id' => $this->botConfigId]);
                 } catch (\PDOException $e) {
                     $this->logger->error("Failed to update position details in heartbeat: " . $e->getMessage());
                 }
            }
        });
        $this->logger->info('Bot heartbeat timer started.', ['interval_seconds' => self::BOT_HEARTBEAT_INTERVAL]);

        // --- Fallback Order Check / Pending Entry Timeout Timer ---
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            // Check for timed-out pending entry order
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder && $this->activeEntryOrderTimestamp !== null) {
                $secondsPassed = time() - $this->activeEntryOrderTimestamp;
                if ($secondsPassed > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out ({$secondsPassed}s > {$this->pendingEntryOrderCancelTimeoutSeconds}s). Attempting cancellation.");
                    $this->isPlacingOrManagingOrder = true; // Lock
                    $orderIdToCancel = $this->activeEntryOrderId;
                    $timedOutOrderSide = $this->aiSuggestedSide ?? 'N/A'; // Use AI suggested side for log context
                    $timedOutOrderPrice = $this->aiSuggestedEntryPrice ?? 0;
                    $timedOutOrderQty = $this->aiSuggestedQuantity ?? 0;

                    // Reset intent state *before* cancel attempt in case of failure
                    $this->activeEntryOrderId = null;
                    $this->activeEntryOrderTimestamp = null;

                    $this->cancelFuturesOrder($this->tradingSymbol, $orderIdToCancel)
                        ->then(
                            function ($cancellationData) use ($orderIdToCancel, $timedOutOrderSide, $timedOutOrderPrice, $timedOutOrderQty) {
                                $this->logger->info("Pending entry order {$orderIdToCancel} successfully cancelled due to timeout.", ['response_status' => $cancellationData['status'] ?? 'N/A']);
                                $this->addOrderToLog($orderIdToCancel, 'CANCELED_TIMEOUT', $timedOutOrderSide, $this->tradingSymbol, $timedOutOrderPrice, $timedOutOrderQty, $this->marginAsset, time(), 0.0);
                                $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderIdToCancel} cancelled due to timeout.", 'decision' => null];
                            },
                            function (\Throwable $e) use ($orderIdToCancel) {
                                $this->logger->error("Failed attempt to cancel timed-out pending entry order {$orderIdToCancel}. State already reset.", ['exception' => $e->getMessage()]);
                                $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed cancellation attempt for timed-out order {$orderIdToCancel}.", 'decision' => null];
                            }
                        )->finally(function () {
                            $this->isPlacingOrManagingOrder = false; // Release lock
                        });
                    return; // Don't proceed to other checks this cycle if handling timeout
                }
            }

            // Fallback check for active *entry* order status (if WS missed something)
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder) {
                $this->checkActiveOrderStatus($this->activeEntryOrderId, 'ENTRY');
            }
        });
        $this->logger->info('Fallback order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds, 'entry_order_timeout' => $this->pendingEntryOrderCancelTimeoutSeconds]);

        // --- Max Runtime Timer ---
        // This is primarily for development/safety. In production, a process manager like systemd should manage uptime.
        if ($this->maxScriptRuntimeSeconds > 0) {
            $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
                $this->logger->warning('Maximum script runtime reached. Stopping.', ['max_runtime_seconds' => $this->maxScriptRuntimeSeconds]);
                $this->stop();
            });
            $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);
        }


        // --- AI Update Timer ---
        $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, function () {
            $this->triggerAIUpdate();
        });
        $this->logger->info('AI parameter update timer started', ['interval_seconds' => $this->aiUpdateIntervalSeconds]);

        // --- Listen Key Refresh Timer ---
        if ($this->listenKey) {
            $this->listenKeyRefreshTimer = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function () {
                if ($this->listenKey) {
                    $this->keepAliveUserDataStream($this->listenKey)->then(
                        fn() => $this->logger->info('ListenKey kept alive successfully.'),
                        fn($e) => $this->logger->error('Failed to keep ListenKey alive.', ['err' => $e->getMessage()])
                    );
                }
            });
             $this->logger->info('ListenKey refresh timer started.', ['interval_seconds' => self::LISTEN_KEY_REFRESH_INTERVAL]);
        }

        // --- Profit Check Timer ---
        if ($this->takeProfitTargetUsdt > 0 && $this->profitCheckIntervalSeconds > 0) {
            $this->loop->addPeriodicTimer($this->profitCheckIntervalSeconds, function () {
                $this->checkProfitTarget();
            });
            $this->logger->info('Profit check timer started.', [
                'interval_seconds' => $this->profitCheckIntervalSeconds,
                'target_usdt' => $this->takeProfitTargetUsdt
            ]);
        } else {
            $this->logger->info('Profit check timer disabled (target USDT <= 0).');
        }
    }

    private function handleWsMessage(string $msg): void
    {
        $decoded = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['stream'], $decoded['data'])) {
            $this->logger->debug('Received non-standard WebSocket message or decode error.', ['raw_msg_preview' => substr($msg,0,100)]);
            return;
        }

        $streamName = $decoded['stream'];
        $data = $decoded['data'];

        // --- Kline Updates ---
        if (str_ends_with($streamName, '@kline_' . $this->klineInterval)) {
            if (isset($data['e']) && $data['e'] === 'kline' && isset($data['k']['x']) && $data['k']['x'] === true && isset($data['k']['c'])) {
                $newPrice = (float)$data['k']['c'];
                $oldPrice = $this->lastClosedKlinePrice;
                if ($newPrice != $oldPrice) { // Check if price actually changed
                    $this->lastClosedKlinePrice = $newPrice;
                    $this->logger->debug('Kline update received (closed)', [
                        'symbol' => $data['k']['s'],
                        'interval' => $this->klineInterval,
                        'close_price' => $this->lastClosedKlinePrice,
                        'prev_price' => $oldPrice, // Log previous for easier debugging
                    ]);
                }
            }
        // --- User Data Stream Events ---
        } elseif ($streamName === $this->listenKey) {
            $this->handleUserDataStreamEvent($data);
        } else {
             $this->logger->debug('Received WS message on unhandled stream or mismatched listenKey path', ['stream_name' => $streamName, 'expected_listenkey_stream' => $this->listenKey]);
        }
    }

    private function handleUserDataStreamEvent(array $eventData): void
    {
        $eventType = $eventData['e'] ?? null;
        $this->logger->debug("User Data Stream Event Received", ['type' => $eventType, 'data_preview' => substr(json_encode($eventData), 0, 200)]);

        switch ($eventType) {
            case 'ACCOUNT_UPDATE':
                // Position Updates
                if (isset($eventData['a']['P'])) {
                    foreach($eventData['a']['P'] as $posData) {
                        if ($posData['s'] === $this->tradingSymbol) {
                            $oldPositionDetails = $this->currentPositionDetails;
                            $newPositionDetails = $this->formatPositionDetailsFromEvent($posData);

                            $oldQty = isset($oldPositionDetails['quantity']) ? (float)$oldPositionDetails['quantity'] : 0.0;
                            $newQty = isset($newPositionDetails['quantity']) ? (float)$newPositionDetails['quantity'] : 0.0;

                            if ($newQty != 0 && $oldQty == 0) { // Position Opened or increased from zero
                                $this->logger->info("Position opened/updated via ACCOUNT_UPDATE.", $newPositionDetails);
                                $this->currentPositionDetails = $newPositionDetails;
                            } elseif ($newQty == 0 && $oldQty != 0) { // Position Closed
                                $this->logger->info("Position for {$this->tradingSymbol} detected as closed via ACCOUNT_UPDATE.", ['reason_code' => $posData['cr'] ?? 'N/A', 'closed_details' => $oldPositionDetails]);
                                $this->handlePositionClosed(); // Trigger cleanup
                            } elseif ($newQty != 0 && $oldQty != 0) { // Position still open, check for changes
                                if ($newQty != $oldQty || (isset($newPositionDetails['entryPrice']) && isset($oldPositionDetails['entryPrice']) && (float)$newPositionDetails['entryPrice'] !== (float)$oldPositionDetails['entryPrice'])) {
                                    $this->logger->info("Position details changed via ACCOUNT_UPDATE.", ['old' => $oldPositionDetails, 'new' => $newPositionDetails]);
                                }
                                $this->currentPositionDetails = $newPositionDetails; // Always update with latest
                            } else { // Both old and new qty are zero
                                $this->currentPositionDetails = null; // Ensure state reflects no position
                            }
                        }
                    }
                }
                // Balance Updates (Optional detailed logging)
                if (isset($eventData['a']['B'])) {
                     foreach($eventData['a']['B'] as $balData) {
                         if ($balData['a'] === $this->marginAsset) {
                             $this->logger->debug("Balance update for {$this->marginAsset}", [
                                 'wallet_balance' => $balData['wb'], 'cross_un_pnl' => $balData['cw']
                             ]);
                         }
                     }
                }
                break;

            case 'ORDER_TRADE_UPDATE':
                $order = $eventData['o'];
                $this->logger->info("Order Update Event", [
                    'symbol' => $order['s'], 'orderId' => $order['i'], 'clientOrderId' => $order['c'],
                    'status' => $order['X'], 'type' => $order['o'], 'side' => $order['S'], 'origType' => $order['ot'] ?? 'N/A',
                    'price' => $order['p'], 'quantity' => $order['q'], 'filled_qty' => $order['z'], 'last_fill_qty' => $order['l'] ?? 'N/A',
                    'avg_fill_price' => $order['ap'], 'reduce_only' => $order['R'] ?? 'N/A',
                    'stop_price' => $order['sp'] ?? 'N/A', 'pnl' => $order['rp'] ?? 'N/A', 'execution_type' => $order['x'] ?? 'N/A'
                ]);

                if ($order['s'] !== $this->tradingSymbol) return; // Ignore other symbols

                $orderId = (string)$order['i'];
                $orderStatus = $order['X']; // NEW, PARTIALLY_FILLED, FILLED, CANCELED, EXPIRED, etc.
                $executionType = $order['x']; // NEW, CANCELED, TRADE, EXPIRED etc.

                // Extract commission details
                $commission = (float)($order['n'] ?? 0); // 'n' is commission amount
                $commissionAsset = $order['N'] ?? null; // 'N' is commission asset name

                // Asynchronously get USDT equivalent of commission
                $this->getUsdtEquivalent((string)$commissionAsset, $commission)->then(function ($commissionUsdt) use ($order, $orderId, $orderStatus, $executionType) {
                    // --- Handling Active Entry Order ---
                    if ($orderId === $this->activeEntryOrderId) {
                        if ($orderStatus === 'FILLED') {
                            $this->logger->info("Entry order FULLY FILLED: {$this->activeEntryOrderId}. Placing SL/TP orders.");
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)($order['ap'] ?? $order['p']), (float)$order['z'], $this->marginAsset, time(), (float)($order['rp'] ?? 0), $commissionUsdt);
                            $this->activeEntryOrderId = null; // Clear pending entry state
                            $this->activeEntryOrderTimestamp = null;
                            $this->isMissingProtectiveOrder = false; // Assume SL/TP will be placed now
                            $this->placeSlAndTpOrders(); // Initiate SL/TP placement
                        } elseif ($orderStatus === 'PARTIALLY_FILLED') {
                            $this->logger->info("Entry order PARTIALLY FILLED: {$this->activeEntryOrderId}. Waiting for full fill or timeout.", ['filled_qty' => $order['z'], 'total_qty' => $order['q']]);
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)($order['ap'] ?? $order['p']), (float)($order['l'] ?? $order['z']), $this->marginAsset, time(), (float)($order['rp'] ?? 0), $commissionUsdt); // Log fill price/qty
                        } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED', 'PENDING_CANCEL'])) {
                            $this->logger->warning("Active entry order {$this->activeEntryOrderId} ended without fill via WS: {$orderStatus}. Resetting trade state.");
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)($order['p'] ?? 0), (float)$order['q'], $this->marginAsset, time(), (float)($order['rp'] ?? 0), $commissionUsdt);
                            $this->resetTradeState(); // Full reset as entry failed
                            $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} ended without full fill: {$orderStatus}.", 'decision' => null];
                        }
                    }
                    // --- Handling Active SL/TP Orders ---
                    elseif ($orderId === $this->activeSlOrderId || $orderId === $this->activeTpOrderId) {
                        if ($orderStatus === 'FILLED' || ($orderStatus == 'PARTIALLY_FILLED' && $executionType == 'TRADE')) { // Use Execution Type TRADE to confirm fill
                            $isSlFill = ($orderId === $this->activeSlOrderId);
                            $logSide = $order['S']; // Side of the SL/TP order itself (e.g., SELL for closing LONG)
                            $pnl = (float)($order['rp'] ?? 0); // Realized PnL from the fill

                            $this->logger->info("Protective ({$order['ot']}) order {$orderId} filled (or partially). Position closing.", ['realized_pnl' => $pnl, 'order_status' => $orderStatus]);
                            $this->addOrderToLog(
                                $orderId, $orderStatus . ($executionType == 'TRADE' ? '_TRADE' : ''), $logSide,
                                $this->tradingSymbol, (float)($order['ap'] > 0 ? $order['ap'] : ($order['sp'] ?? 0)),
                                (float)($order['l'] ?? $order['z']), $this->marginAsset, time(), $pnl, $commissionUsdt
                            );

                            // If fully FILLED, proactively clear the filled order ID and cancel the other protective order.
                            if ($orderStatus === 'FILLED') {
                                $otherOrderId = null;
                                if ($isSlFill) { $this->activeSlOrderId = null; $otherOrderId = $this->activeTpOrderId; }
                                else { $this->activeTpOrderId = null; $otherOrderId = $this->activeSlOrderId; }
                                if ($otherOrderId) $this->cancelOrderAndLog($otherOrderId, "remaining SL/TP after primary closure fill");
                                $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position closed by " . ($isSlFill ? "SL" : "TP") . " order {$orderId}.", 'decision' => null];
                            }

                        } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED', 'PENDING_CANCEL'])) {
                            $this->logger->warning("SL/TP order {$orderId} ended without fill: {$orderStatus}. Possible external cancellation or issue.");
                            if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                            if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)($order['sp'] ?? 0), (float)$order['q'], $this->marginAsset, time(), 0.0, $commissionUsdt);

                            // Check if position still exists and is now unprotected
                            if ($this->currentPositionDetails && !$this->activeSlOrderId && !$this->activeTpOrderId) {
                                $this->logger->critical("Position open but BOTH SL/TP orders are now gone unexpectedly. Flagging critical state.");
                                $this->isMissingProtectiveOrder = true;
                                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Position unprotected: SL/TP order {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                                $this->triggerAIUpdate(true); // Trigger emergency AI consultation
                            } elseif ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId)) {
                                $this->logger->warning("Position open but ONE SL/TP order is now gone unexpectedly. Flagging potentially unsafe state.");
                                $this->isMissingProtectiveOrder = true; // Still unsafe
                                $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Position missing one protective order: {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                                $this->triggerAIUpdate(true); // Trigger emergency AI consultation
                            }
                        }
                    }
                    // --- Handling Market Orders (e.g., from profit-taking or AI close) ---
                    elseif (in_array($order['ot'], ['MARKET']) && ($order['R'] ?? false)) { // Check if original type was MARKET and it was ReduceOnly
                        if ($orderStatus === 'FILLED') {
                            $pnl = (float)($order['rp'] ?? 0);
                            $this->logger->info("Reduce-Only Market Order {$orderId} filled.", ['side' => $order['S'], 'avg_fill_price' => $order['ap'], 'pnl' => $pnl]);
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['ap'], (float)$order['z'], $this->marginAsset, time(), $pnl, $commissionUsdt);
                        }
                    }
                })->otherwise(function (\Throwable $e) use ($orderId) {
                    $this->logger->error("Failed to process commission for order {$orderId}: " . $e->getMessage());
                    // Log order without commission if conversion fails
                    $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)($order['ap'] ?? $order['p']), (float)$order['z'], $this->marginAsset, time(), (float)($order['rp'] ?? 0), 0.0);
                });

                break; // End ORDER_TRADE_UPDATE

            case 'listenKeyExpired':
                $this->logger->warning("ListenKey expired. Attempting to get a new one and reconnect WebSocket.");
                $this->listenKey = null;
                if ($this->wsConnection) { try { $this->wsConnection->close(); } catch (\Exception $_){}}
                $this->wsConnection = null;
                if ($this->listenKeyRefreshTimer) {
                     $this->loop->cancelTimer($this->listenKeyRefreshTimer);
                     $this->listenKeyRefreshTimer = null;
                }
                $this->startUserDataStream()->then(function ($data) {
                    $this->listenKey = $data['listenKey'] ?? null;
                    if ($this->listenKey) {
                        $this->logger->info("New ListenKey obtained after expiry. Reconnecting WebSocket and restarting refresh timer.");
                        $this->connectWebSocket();
                         $this->listenKeyRefreshTimer = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function () {
                            if ($this->listenKey) {
                                $this->keepAliveUserDataStream($this->listenKey)->then(
                                    fn() => $this->logger->info('ListenKey kept alive successfully.'),
                                    fn($e) => $this->logger->error('Failed to keep ListenKey alive.', ['err' => $e->getMessage()])
                                );
                            }
                        });
                    } else {
                        $this->logger->error("Failed to get new ListenKey after expiry. Stopping bot."); $this->stop();
                    }
                })->otherwise(function ($e) {
                     $this->logger->error("Error getting new ListenKey after expiry. Stopping bot.", ['err' => $e->getMessage()]); $this->stop();
                });
                break;

            case 'MARGIN_CALL':
                $this->logger->critical("MARGIN CALL RECEIVED! Immediate action advised.", $eventData);
                $this->isMissingProtectiveOrder = true; // Assume protection failed or is inadequate
                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "MARGIN CALL RECEIVED! Position is at high risk.", 'decision' => null];
                $this->triggerAIUpdate(true); // Emergency AI consult
                break;

            default:
                $this->logger->debug('Unhandled user data event type', ['type' => $eventType, 'data' => $eventData]);
        }
    }

    // Helper to format position details from WS ACCOUNT_UPDATE event
    private function formatPositionDetailsFromEvent(?array $posData): ?array {
        if (empty($posData) || !isset($posData['s']) || $posData['s'] !== $this->tradingSymbol) return null;
        $quantityVal = (float)($posData['pa'] ?? 0); // positionAmt
        if (abs($quantityVal) < 1e-9) return null; // Treat as no position if quantity is effectively zero

        $entryPriceVal = (float)($posData['ep'] ?? 0); // entryPrice
        $markPriceVal = (float)($posData['mp'] ?? ($this->lastClosedKlinePrice ?? 0)); // markPrice, fallback to last kline
        $unrealizedPnlVal = (float)($posData['up'] ?? 0); // unRealizedProfit
        $leverageVal = (int)($this->currentPositionDetails['leverage'] ?? $this->defaultLeverage); // Use current state or default
        $initialMarginVal = (float)($posData['iw'] ?? 0); // isolatedWallet / initialMargin
        $maintMarginVal = (float)($posData['mm'] ?? 0); // maintMargin

        $side = $quantityVal > 0 ? 'LONG' : 'SHORT';

        return [
            'symbol' => $this->tradingSymbol, 'side' => $side, 'entryPrice' => $entryPriceVal,
            'quantity' => abs($quantityVal), 'leverage' => $leverageVal, 'markPrice' => $markPriceVal,
            'unrealizedPnl' => $unrealizedPnlVal, 'initialMargin' => $initialMarginVal,
            'maintMargin' => $maintMarginVal, 'positionSideBinance' => $posData['ps'] ?? 'BOTH',
            // Add AI suggested prices for dashboard display, if available
            'aiSuggestedSlPrice' => $this->aiSuggestedSlPrice ?? null,
            'aiSuggestedTpPrice' => $this->aiSuggestedTpPrice ?? null,
            // Add active SL/TP order IDs for dashboard display
            'activeSlOrderId' => $this->activeSlOrderId,
            'activeTpOrderId' => $this->activeTpOrderId,
        ];
    }

    // Formats position details primarily from /fapi/v2/positionRisk API response
    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) return null;
        $positionData = null;
        $isSingleObject = !isset($positionsInput[0]) && isset($positionsInput['symbol']);

        if ($isSingleObject) {
            if ($positionsInput['symbol'] === $this->tradingSymbol) $positionData = $positionsInput;
        } elseif (is_array($positionsInput) && isset($positionsInput[0]['symbol'])) { // Standard v2 array response
            foreach ($positionsInput as $p) {
                if (isset($p['symbol']) && $p['symbol'] === $this->tradingSymbol) {
                     $currentQty = (float)($p['positionAmt'] ?? 0);
                     if (abs($currentQty) > 1e-9) { $positionData = $p; break; }
                }
            }
        }
        if (!$positionData) return null; // No position found for the symbol or quantity is zero

        $quantityVal = (float)($positionData['positionAmt'] ?? 0);
        if (abs($quantityVal) < 1e-9) return null; // No actual position

        $entryPriceVal = (float)($positionData['entryPrice'] ?? 0);
        $markPriceVal = (float)($positionData['markPrice'] ?? ($this->lastClosedKlinePrice ?? 0));
        $unrealizedPnlVal = (float)($positionData['unRealizedProfit'] ?? 0);
        $leverageVal = (int)($positionData['leverage'] ?? $this->defaultLeverage);
        $initialMarginVal = (float)($positionData['initialMargin'] ?? $positionData['isolatedMargin'] ?? 0);
        $maintMarginVal = (float)($positionData['maintMargin'] ?? 0);
        $isolatedWalletVal = (float)($positionData['isolatedWallet'] ?? 0);

        $side = $quantityVal > 0 ? 'LONG' : 'SHORT';

        return [
            'symbol' => $this->tradingSymbol, 'side' => $side, 'entryPrice' => $entryPriceVal,
            'quantity' => abs($quantityVal), 'leverage' => $leverageVal ?: $this->defaultLeverage,
            'markPrice' => $markPriceVal, 'unrealizedPnl' => $unrealizedPnlVal,
            'initialMargin' => $initialMarginVal, 'maintMargin' => $maintMarginVal,
            'isolatedWallet' => $isolatedWalletVal, 'positionSideBinance' => $positionData['positionSide'] ?? 'BOTH',
            // Add AI suggested prices for dashboard display, if available
            'aiSuggestedSlPrice' => $this->aiSuggestedSlPrice ?? null,
            'aiSuggestedTpPrice' => $this->aiSuggestedTpPrice ?? null,
            // Add active SL/TP order IDs for dashboard display
            'activeSlOrderId' => $this->activeSlOrderId,
            'activeTpOrderId' => $this->activeTpOrderId,
        ];
    }

    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->error("Attempted to place SL/TP orders without a current position detected.");
            return;
        }
         if ($this->isPlacingOrManagingOrder) {
             $this->logger->warning("SL/TP placement request skipped: Operation already in progress.");
             return;
         }
        $this->isPlacingOrManagingOrder = true;
        $this->isMissingProtectiveOrder = false; // Assume success initially

        $positionSide = $this->currentPositionDetails['side'];
        $quantity = (float)$this->currentPositionDetails['quantity'];
        $orderSideForSlTp = ($positionSide === 'LONG') ? 'SELL' : 'BUY';

        // --- Input Validation (AI should provide valid values, but bot must double-check) ---
        if ($this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0 || $quantity <= 0) {
             $this->logger->critical("Invalid parameters for SL/TP placement. Cannot place protective orders.", [
                 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice, 'qty' => $quantity
                ]);
             $this->isMissingProtectiveOrder = true; // Placement failed validation
             $this->isPlacingOrManagingOrder = false;
             $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Invalid SL/TP/Qty from AI. Position unprotected.", 'decision' => null];
             return;
        }
        $entryPrice = (float)$this->currentPositionDetails['entryPrice'];
        // Check for logical SL/TP placement relative to entry price
        if ($positionSide === 'LONG' && ($this->aiSuggestedSlPrice >= $entryPrice || $this->aiSuggestedTpPrice <= $entryPrice)) {
            $this->logger->critical("Illogical SL/TP for LONG position. Cannot place protective orders.", ['entry' => $entryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice]);
            $this->isMissingProtectiveOrder = true; $this->isPlacingOrManagingOrder = false;
            $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Illogical SL/TP for LONG. Position unprotected.", 'decision' => null]; return;
        }
        if ($positionSide === 'SHORT' && ($this->aiSuggestedSlPrice <= $entryPrice || $this->aiSuggestedTpPrice >= $entryPrice)) {
            $this->logger->critical("Illogical SL/TP for SHORT position. Cannot place protective orders.", ['entry' => $entryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice]);
            $this->isMissingProtectiveOrder = true; $this->isPlacingOrManagingOrder = false;
            $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Illogical SL/TP for SHORT. Position unprotected.", 'decision' => null]; return;
        }

        // --- Place Orders ---
        $slOrderPromise = $this->placeFuturesStopMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedSlPrice, true // reduceOnly = true
        )->then(function ($orderData) {
            $this->activeSlOrderId = (string)$orderData['orderId'];
            $this->logger->info("Stop Loss order placement request sent.", ['orderId' => $this->activeSlOrderId, 'stopPrice' => $this->aiSuggestedSlPrice, 'status_api' => $orderData['status'] ?? 'N/A']);
            return $orderData;
        })->otherwise(function (\Throwable $e) {
            $this->logger->error("Failed to place Stop Loss order.", ['error' => $e->getMessage()]);
            $this->isMissingProtectiveOrder = true; // Mark as missing protection if SL fails
            throw $e; // Re-throw to fail the Promise.all
        });

        $tpOrderPromise = $this->placeFuturesTakeProfitMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedTpPrice, true // reduceOnly = true
        )->then(function ($orderData) {
            $this->activeTpOrderId = (string)$orderData['orderId'];
            $this->logger->info("Take Profit order placement request sent.", ['orderId' => $this->activeTpOrderId, 'stopPrice' => $this->aiSuggestedTpPrice, 'status_api' => $orderData['status'] ?? 'N/A']);
            return $orderData;
         })->otherwise(function (\Throwable $e) {
            $this->logger->error("Failed to place Take Profit order.", ['error' => $e->getMessage()]);
            $this->isMissingProtectiveOrder = true; // Mark as missing protection if TP fails
            throw $e; // Re-throw to fail the Promise.all
         });

        // --- Handle Combined Results ---
        \React\Promise\all([$slOrderPromise, $tpOrderPromise])
            ->then(
                function (array $results) {
                    $slStatus = $results[0]['status'] ?? 'UNKNOWN'; $tpStatus = $results[1]['status'] ?? 'UNKNOWN';
                    $this->logger->info("SL and TP order placement requests processed by API.", [
                        'sl_order_id' => $this->activeSlOrderId, 'sl_status_api' => $slStatus,
                        'tp_order_id' => $this->activeTpOrderId, 'tp_status_api' => $tpStatus
                        ]);
                    if ($this->isMissingProtectiveOrder) { // If any failed during placement (marked isMissingProtectiveOrder=true)
                         $this->logger->critical("CRITICAL: At least one SL/TP order failed placement. Position may be unprotected!", [
                            'sl_id' => $this->activeSlOrderId, 'tp_id' => $this->activeTpOrderId,
                            'position' => $this->currentPositionDetails]);
                         $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "SL/TP placement partially failed. Position potentially unprotected.", 'decision' => null];
                    }
                    $this->isPlacingOrManagingOrder = false; // Release lock
                },
                function (\Throwable $e) {
                    $this->logger->critical("CRITICAL: Error during SL/TP order placement sequence. Position potentially unprotected!", [
                        'exception_class' => get_class($e), 'exception' => $e->getMessage(),
                        'current_sl_id_state' => $this->activeSlOrderId, 'current_tp_id_state' => $this->activeTpOrderId,
                        'position_details' => $this->currentPositionDetails
                    ]);
                    $this->isMissingProtectiveOrder = true; $this->isPlacingOrManagingOrder = false; // Release lock
                    $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Failed placing SL/TP orders (API error: ".$e->getMessage()."). Position potentially unprotected.", 'decision' => null];
                }
            );
    }

    // Called when WS ACCOUNT_UPDATE indicates positionAmt is zero or when an SL/TP fill is confirmed.
    private function handlePositionClosed(?string $otherOrderIdToCancel = null): void
    {
        $closedPositionDetails = $this->currentPositionDetails; // Capture details before reset
        $this->logger->info("Position closure detected/triggered for {$this->tradingSymbol}.", [
            'details_before_reset' => $closedPositionDetails, 'active_sl_before' => $this->activeSlOrderId,
            'active_tp_before' => $this->activeTpOrderId, 'specific_other_order_to_cancel' => $otherOrderIdToCancel
        ]);

        $symbol = $this->tradingSymbol; // Capture for use in closures
        $quantityClosed = $closedPositionDetails['quantity'] ?? 0;
        $closeSide = $closedPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY';
        $slOrderId = $this->activeSlOrderId;
        $tpOrderId = $this->activeTpOrderId;

        $this->getFuturesTradeHistory($this->tradingSymbol, 20)
            ->then(function ($tradeHistory) use ($symbol, $quantityClosed, $closeSide, $slOrderId, $tpOrderId) {
                $closingTrade = null;
                foreach ($tradeHistory as $trade) {
                    $isClosingTrade = false;
                    // Check if orderId matches SL/TP order ID (if either was filled)
                    if (($slOrderId && (string)$trade['orderId'] === $slOrderId) || ($tpOrderId && (string)$trade['orderId'] === $tpOrderId)) {
                        $isClosingTrade = true;
                    } elseif ($trade['reduceOnly'] && (float)$trade['qty'] == $quantityClosed && $trade['side'] === $closeSide) {
                        $isClosingTrade = true;
                    }

                    if ($isClosingTrade) {
                        $closingTrade = $trade;
                        break;
                    }
                }

                $realizedPnl = 0.0;
                if ($closingTrade) {
                    $realizedPnl = (float)($closingTrade['realizedPnl'] ?? 0.0);
                    $this->logger->info("Found closing trade in history. Logging PNL.", ['orderId' => $closingTrade['orderId'], 'pnl' => $realizedPnl]);
                } else {
                    $this->logger->warning("Could not find closing trade in history to extract PNL.");
                }

                // Log the position close with the extracted PNL
                $this->addOrderToLog(
                    $closingTrade['orderId'] ?? 'N/A',
                    'CLOSED',
                    $closeSide,
                    $symbol,
                    (float)($closingTrade['price'] ?? 0),
                    $quantityClosed,
                    $this->marginAsset,
                    time(),
                    $realizedPnl
                );
            })
            ->otherwise(function (\Throwable $e) {
                $this->logger->error("Failed to get trade history for PNL extraction: " . $e->getMessage());
            });

        $cancelPromises = [];
        if ($otherOrderIdToCancel && $otherOrderIdToCancel === $this->activeTpOrderId) {
             if ($this->activeTpOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "remaining TP during position close");
        } elseif ($otherOrderIdToCancel && $otherOrderIdToCancel === $this->activeSlOrderId) {
             if ($this->activeSlOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "remaining SL during position close");
        } else { // If no specific order, cancel both active SL and TP if they exist
            if ($this->activeSlOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "active SL on position close");
            if ($this->activeTpOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "active TP on position close");
        }

        $this->resetTradeState(); // Reset state *immediately* regardless of cancellation outcome.

        if (!empty($cancelPromises)) {
            \React\Promise\all($cancelPromises)->finally(function() {
                $this->logger->info("Trade state reset completed after position close and cancellation attempts finished.");
                $this->loop->addTimer(2, fn() => $this->triggerAIUpdate()); // Trigger AI update sooner after position close
            });
        } else {
            $this->logger->info("Trade state reset completed after position close (no active SL/TP orders needed cancellation).");
            $this->loop->addTimer(2, fn() => $this->triggerAIUpdate()); // Trigger AI update sooner
        }
    }

    // Helper to cancel an order and log, returns a promise
    private function cancelOrderAndLog(string $orderId, string $reasonForCancel): PromiseInterface {
        $deferred = new Deferred();
        $localSlId = $this->activeSlOrderId; // Capture state before async op
        $localTpId = $this->activeTpOrderId;

        $this->cancelFuturesOrder($this->tradingSymbol, $orderId)->then(
            function($data) use ($orderId, $reasonForCancel, $deferred, $localSlId, $localTpId) {
                $status = $data['status'] ?? 'UNKNOWN';
                $this->logger->info("Successfully cancelled order: {$orderId} ({$reasonForCancel}).", ['api_response_status' => $status]);
                // Clear the ID from state *if it hasn't changed* in the meantime
                if ($orderId === $localSlId && $this->activeSlOrderId === $orderId) $this->activeSlOrderId = null;
                if ($orderId === $localTpId && $this->activeTpOrderId === $orderId) $this->activeTpOrderId = null;
                $deferred->resolve($data);
            },
            function (\Throwable $e) use ($orderId, $reasonForCancel, $deferred, $localSlId, $localTpId) {
                 // Check for common "Order does not exist" errors
                 if (str_contains($e->getMessage(), '-2011') || stripos($e->getMessage(), "order does not exist") !== false || stripos($e->getMessage(), "Unknown order sent") !== false) {
                     $this->logger->info("Attempt to cancel order {$orderId} ({$reasonForCancel}) failed, likely already resolved/gone.", ['err_preview' => substr($e->getMessage(),0,100)]);
                 } else { // Log other errors more prominently
                     $this->logger->error("Failed to cancel order: {$orderId} ({$reasonForCancel}).", ['err' => $e->getMessage()]);
                 }
                 // Clear the ID from state *if it hasn't changed* even if cancellation failed (it's gone anyway)
                if ($orderId === $localSlId && $this->activeSlOrderId === $orderId) $this->activeSlOrderId = null;
                if ($orderId === $localTpId && $this->activeTpOrderId === $orderId) $this->activeTpOrderId = null;
                $deferred->resolve(['status' => 'ALREADY_RESOLVED_OR_ERROR', 'orderId' => $orderId, 'reason' => $e->getMessage()]);
            }
        );
        return $deferred->promise();
    }

    // Resets all active trade-related state variables
    private function resetTradeState(): void {
        $this->logger->info("Resetting trade state.", [
            'active_entry_before' => $this->activeEntryOrderId, 'active_sl_before' => $this->activeSlOrderId,
            'active_tp_before' => $this->activeTpOrderId, 'position_exists_before' => !is_null($this->currentPositionDetails),
            'is_managing_before' => $this->isPlacingOrManagingOrder, 'missing_protection_before' => $this->isMissingProtectiveOrder
        ]);
        $this->activeEntryOrderId = null; $this->activeEntryOrderTimestamp = null;
        $this->activeSlOrderId = null; $this->activeTpOrderId = null;
        $this->currentPositionDetails = null; $this->isPlacingOrManagingOrder = false;
        $this->isMissingProtectiveOrder = false;
    }

    // Adds an entry to the recent order log (now primarily sends to DB)
    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt = 0.0): void
    {
        $logEntry = [ // Log to console for immediate visibility
            'orderId' => $orderId, 'status' => $status, 'side' => $side, 'assetPair' => $assetPair,
            'price' => $price, 'quantity' => $quantity, 'marginAsset' => $marginAsset,
            'timestamp_iso' => gmdate('Y-m-d H:i:s', $timestamp) . ' UTC',
            'realizedPnl' => $realizedPnl ?? 0.0,
            'commissionUsdt' => $commissionUsdt,
        ];
        $this->logger->info('Trade/Order outcome logged:', $logEntry);
        $this->logOrderToDb($orderId, $status, $side, $assetPair, $price, $quantity, $marginAsset, $timestamp, $realizedPnl, $commissionUsdt);
    }

    // --- Core Trading Actions Triggered by AI ---

    // Attempts to place the initial LIMIT order for a new position
    private function attemptOpenPosition(): void
    {
        if ($this->currentPositionDetails || $this->activeEntryOrderId || $this->isPlacingOrManagingOrder) {
            $this->logger->info('Skipping position opening: Precondition not met.',[
                'has_pos' => !is_null($this->currentPositionDetails), 'has_entry_order' => !is_null($this->activeEntryOrderId), 'is_managing' => $this->isPlacingOrManagingOrder
            ]);
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped OPEN_POSITION: Pre-condition not met.', 'decision' => ['action' => 'OPEN_POSITION']];
            return;
        }

        // Parameters should have been validated by executeAIDecision already, re-check just in case
        if ($this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedSlPrice <=0 || $this->aiSuggestedTpPrice <= 0 || !in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) {
            $this->logger->error("CRITICAL INTERNAL ERROR: AttemptOpenPosition called with invalid AI parameters.", [
                 'entry' => $this->aiSuggestedEntryPrice, 'qty' => $this->aiSuggestedQuantity, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice, 'side' => $this->aiSuggestedSide
            ]);
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => 'Internal Error: OPEN_POSITION rejected due to invalid parameters at execution.', 'decision' => ['action' => 'OPEN_POSITION']];
            return;
        }

        $this->isPlacingOrManagingOrder = true; // Lock
        $aiParamsForLog = [
            'side' => $this->aiSuggestedSide, 'quantity' => $this->aiSuggestedQuantity,
            'entry' => $this->aiSuggestedEntryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice,
            'leverage' => $this->aiSuggestedLeverage
        ];
        $this->logger->info('Attempting to open new position based on AI.', $aiParamsForLog);

        // 1. Set Leverage
        $this->setLeverage($this->tradingSymbol, $this->aiSuggestedLeverage)
            ->then(function () use ($aiParamsForLog) {
                    $this->logger->debug("Leverage set successfully (or not modified). Proceeding to place limit order.");
                    // 2. Place Limit Order
                    return $this->placeFuturesLimitOrder(
                        $this->tradingSymbol, $this->aiSuggestedSide, $this->aiSuggestedQuantity, $this->aiSuggestedEntryPrice
                    );
            })
            ->then(function ($orderData) use ($aiParamsForLog) {
                // 3. Handle Successful Order Placement
                $this->activeEntryOrderId = (string)$orderData['orderId'];
                $this->activeEntryOrderTimestamp = time(); // Record placement time
                $this->logger->info("Entry limit order placed successfully via API. Waiting for fill event.", [
                    'orderId' => $this->activeEntryOrderId, 'status_api' => $orderData['status'] ?? 'N/A',
                    'placement_timestamp' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp)
                ]);
                 $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed entry order {$this->activeEntryOrderId}.", 'decision_executed' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                // DO NOT place SL/TP here, wait for the fill confirmation via WebSocket
                $this->isPlacingOrManagingOrder = false; // Release lock after successful placement
            })
            ->catch(function (\Throwable $e) use ($aiParamsForLog) {
                // 4. Handle Errors (Leverage or Order Placement)
                $this->logger->error('Failed to open position (set leverage or place limit order).', [
                    'exception_class' => get_class($e), 'exception' => $e->getMessage(), 'ai_params' => $aiParamsForLog
                ]);
                 $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed to place entry order: " . $e->getMessage(), 'decision_attempted' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                $this->resetTradeState(); // Reset state on failure
                $this->isPlacingOrManagingOrder = false; // Release lock
            });
    }

    // Attempts to close the current position via Market Order, triggered by AI
    private function attemptClosePositionByAI(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->info('Skipping AI close: No position exists.');
            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: No position exists.', 'decision' => ['action' => 'CLOSE_POSITION']];
            return;
        }
         if ($this->isPlacingOrManagingOrder){
             $this->logger->info('Skipping AI close: Operation already in progress.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: Operation in progress.', 'decision' => ['action' => 'CLOSE_POSITION']];
             return;
         }

        $this->isPlacingOrManagingOrder = true; // Lock
        $positionToClose = $this->currentPositionDetails; // Capture state
        $this->logger->info("AI requests to close current position for {$this->tradingSymbol} at market.", ['position' => $positionToClose]);

        // 1. Cancel Existing SL/TP Orders
        $slIdToCancel = $this->activeSlOrderId; $tpIdToCancel = $this->activeTpOrderId;
        $cancellationPromises = [];
        if ($slIdToCancel) $cancellationPromises[] = $this->cancelOrderAndLog($slIdToCancel, "SL for AI market close");
        if ($tpIdToCancel) $cancellationPromises[] = $this->cancelOrderAndLog($tpIdToCancel, "TP for AI market close");

        // 2. Wait for cancellations (best effort), then place market order
        \React\Promise\all($cancellationPromises)->finally(function() use ($positionToClose) {
            // Re-check if position still exists *after* cancellations attempted
            return $this->getPositionInformation($this->tradingSymbol)->then(function($refreshedPositionData){
                 $this->currentPositionDetails = $this->formatPositionDetails($refreshedPositionData); // Update state
                 return $this->currentPositionDetails; // Pass current state to next step
            })->then(function($currentPosAfterCancel) use ($positionToClose) {
                 if ($currentPosAfterCancel === null) {
                    $this->logger->info("Position already closed before AI market order could be placed (likely SL/TP filled during cancellation).", ['original_pos_to_close' => $positionToClose]);
                    $this->isPlacingOrManagingOrder = false;
                    $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position found already closed during AI close sequence.", 'decision_executed' => ['action' => 'CLOSE_POSITION', 'original_pos' => $positionToClose]];
                    return \React\Promise\resolve(['status' => 'ALREADY_CLOSED']); // Indicate closed before market order
                }

                 // Position still exists, proceed with market close
                 $closeSide = $currentPosAfterCancel['side'] === 'LONG' ? 'SELL' : 'BUY';
                 $quantityToClose = $currentPosAfterCancel['quantity'];
                 $this->logger->info("Attempting to place market order to close position (AI request).", ['side' => $closeSide, 'quantity' => $quantityToClose, 'current_pos_details' => $currentPosAfterCancel]);
                 return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true); // reduceOnly = true
            });
        })->then(function($closeOrderData) use ($positionToClose, $slIdToCancel, $tpIdToCancel) {
            if (($closeOrderData['status'] ?? '') === 'ALREADY_CLOSED') {
                 $this->logger->debug("AI Close: Confirmed already closed.");
            } elseif (isset($closeOrderData['orderId'])) {
                $this->logger->info("Market order placed by AI to close position. Waiting for fill confirmation.", [
                    'orderId' => $closeOrderData['orderId'], 'status_api' => $closeOrderData['status']
                ]);
                $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed market close order {$closeOrderData['orderId']} for AI request.", 'decision_executed' => ['action' => 'CLOSE_POSITION', 'cancelled_sl' => $slIdToCancel, 'cancelled_tp' => $tpIdToCancel]];
            } else {
                 $this->logger->error("AI Close: Market order placement resolved without orderId.", ['response_data' => $closeOrderData]);
                 $this->isMissingProtectiveOrder = true;
                 $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => 'AI Close: Market order placement response invalid.', 'decision_attempted' => ['action' => 'CLOSE_POSITION']];
            }
            if (($closeOrderData['status'] ?? '') !== 'ERROR') $this->isPlacingOrManagingOrder = false;

        })->catch(function(\Throwable $e) use ($positionToClose) {
            $this->logger->error("Error during AI-driven position close process.", ['exception' => $e->getMessage(), 'position_at_time_of_decision' => $positionToClose]);
            $this->isMissingProtectiveOrder = true;
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Error during AI close: " . $e->getMessage(), 'decision_attempted' => ['action' => 'CLOSE_POSITION']];
            $this->isPlacingOrManagingOrder = false;
        });
    }

    // Triggered by the profit check timer
    private function checkProfitTarget(): void {
        if ($this->takeProfitTargetUsdt <= 0) return; // Feature disabled
        if ($this->isPlacingOrManagingOrder) return; // Avoid conflict
        if ($this->currentPositionDetails === null) return; // No position

        $currentPnl = (float)($this->currentPositionDetails['unrealizedPnl'] ?? 0.0);
        if ($currentPnl >= $this->takeProfitTargetUsdt) {
            $this->logger->info("Profit target reached!", [
                'target_usdt' => $this->takeProfitTargetUsdt,
                'current_pnl_usdt' => $currentPnl,
                'position' => $this->currentPositionDetails
            ]);
            $this->triggerProfitTakingClose();
        }
    }

    private function triggerProfitTakingClose(): void {
        if ($this->isPlacingOrManagingOrder) { $this->logger->warning('Skipping profit-taking close: Operation already in progress.'); return; }
        if ($this->currentPositionDetails === null) { $this->logger->warning('Skipping profit-taking close: No position exists.'); return; }

        $this->isPlacingOrManagingOrder = true; // Acquire lock
        $positionToClose = $this->currentPositionDetails; // Capture state
        $this->logger->info("Initiating market close for profit target.", ['position' => $positionToClose]);

        // 1. Cancel Existing SL/TP Orders
        $slIdToCancel = $this->activeSlOrderId; $tpIdToCancel = $this->activeTpOrderId;
        $cancellationPromises = [];
        if ($slIdToCancel) $cancellationPromises[] = $this->cancelOrderAndLog($slIdToCancel, "SL for Profit Target Close");
        if ($tpIdToCancel) $cancellationPromises[] = $this->cancelOrderAndLog($tpIdToCancel, "TP for Profit Target Close");

        \React\Promise\all($cancellationPromises)
            ->then(fn() => $this->getPositionInformation($this->tradingSymbol))
            ->then(function($refreshedPositionData) use ($positionToClose) {
                $currentPosAfterCancel = $this->formatPositionDetails($refreshedPositionData);
                $this->currentPositionDetails = $currentPosAfterCancel;

                if ($currentPosAfterCancel === null) {
                    $this->logger->info("Position already closed before profit-target market order could be placed.", ['original_pos_to_close' => $positionToClose]);
                    if ($this->activeSlOrderId !== null || $this->activeTpOrderId !== null || $this->currentPositionDetails !== null) {
                         $this->resetTradeState();
                    }
                    return \React\Promise\resolve(['status' => 'ALREADY_CLOSED']); // Pass status forward
                }

                $closeSide = $currentPosAfterCancel['side'] === 'LONG' ? 'SELL' : 'BUY';
                $quantityToClose = $currentPosAfterCancel['quantity'];
                $this->logger->info("Attempting market order to close position (Profit Target).", ['side' => $closeSide, 'quantity' => $quantityToClose]);
                return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true); // reduceOnly = true
            })
            ->then(function($closeOrderData) {
                if (($closeOrderData['status'] ?? '') === 'ALREADY_CLOSED') {
                    $this->logger->debug("Profit Target Close: Confirmed already closed (step 3).");
                } elseif (isset($closeOrderData['orderId'])) {
                    $this->logger->info("Market order placed for Profit Target. Waiting for fill confirmation.", ['orderId' => $closeOrderData['orderId'], 'status_api' => $closeOrderData['status']]);
                } else {
                    $this->logger->error("Profit Target Close: Market order placement response invalid or unexpected.", ['response_data' => $closeOrderData]);
                    $this->isMissingProtectiveOrder = true;
                }
            })
            ->catch(function(\Throwable $e) use ($positionToClose) {
                $this->logger->error("Error during profit-taking close process.", ['exception_class' => get_class($e), 'exception' => $e->getMessage(), 'position_details_at_start' => $positionToClose]);
                $this->isMissingProtectiveOrder = true;
            })
            ->finally(function() {
                $this->isPlacingOrManagingOrder = false;
                $this->logger->debug("Profit taking close sequence finished, lock released.");
            });
    }

    // --- Binance API Interaction ---

    // Creates signature for authenticated requests
    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::BINANCE_API_RECV_WINDOW;

        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986); // Use RFC3986 for space encoding etc.

        $signature = hash_hmac('sha256', $queryString, $this->binanceApiSecret);

        $url = $this->currentRestApiBaseUrl . $endpoint;
        $body = null;

        if ($method === 'GET' || $method === 'DELETE') {
            $url .= '?' . $queryString . '&signature=' . $signature;
        } else { // POST, PUT
            $body = $queryString . '&signature=' . $signature;
        }
        return ['url' => $url, 'headers' => ['X-MBX-APIKEY' => $this->binanceApiKey], 'postData' => $body];
    }

    // Helper to convert any asset amount to its USDT equivalent
    private function getUsdtEquivalent(string $asset, float $amount): PromiseInterface
    {
        if (strtoupper($asset) === 'USDT') {
            return \React\Promise\resolve($amount);
        }

        $symbol = strtoupper($asset) . 'USDT';
        // Attempt to get the current price of the asset against USDT
        return $this->getLatestKlineClosePrice($symbol, '1m') // Use 1-minute kline for recent price
            ->then(function ($klineData) use ($amount, $symbol) {
                $price = (float)($klineData['price'] ?? 0);
                if ($price <= 0) {
                    $this->logger->warning("Could not get valid price for {$symbol} to convert commission. Assuming 0 USDT commission.", ['kline_data' => $klineData]);
                    return 0.0;
                }
                return $amount * $price;
            })
            ->otherwise(function (\Throwable $e) use ($asset, $amount) {
                $this->logger->error("Failed to convert commission asset {$asset} to USDT. Assuming 0 USDT commission.", ['amount' => $amount, 'error' => $e->getMessage()]);
                return 0.0; // Return 0 if conversion fails
            });
    }

    // Makes async HTTP request using ReactPHP Browser
    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null, bool $isPublic = false): PromiseInterface
    {
        $options = ['follow_redirects' => false, 'timeout' => 15.0]; // 15 seconds timeout
        $finalHeaders = $headers;
        if (!$isPublic && !isset($finalHeaders['X-MBX-APIKEY'])) {
            $finalHeaders['X-MBX-APIKEY'] = $this->binanceApiKey;
        }

        if (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body)) {
            $finalHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
            $requestPromise = $this->browser->request($method, $url, $finalHeaders, $body, $options);
        } else {
            $requestPromise = $this->browser->request($method, $url, $finalHeaders, '', $options);
        }

        return $requestPromise->then(
            function (ResponseInterface $response) use ($method, $url) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'status' => $statusCode];
                $data = json_decode($body, true);

                // Handle JSON decoding errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Failed to decode API JSON response', $logCtx + ['json_err' => json_last_error_msg(), 'body_preview' => substr($body, 0, 200)]);
                    throw new \RuntimeException("JSON decode error: " . json_last_error_msg() . " for " . $url . ". Response body: " . substr($body,0,200) );
                }
                // Handle Binance API errors within the JSON response body
                // -2011 "Order does not exist" is often not a critical error, just means it's gone
                if (isset($data['code']) && (int)$data['code'] < 0 && (int)$data['code'] !== -2011) {
                    $this->logger->error('Binance Futures API Error reported', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A', 'response_body' => substr($body,0,500)]);
                    throw new \RuntimeException("Binance Futures API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error') . " for " . $url, (int)$data['code']);
                }
                // Handle HTTP errors (e.g., 4xx, 5xx)
                if ($statusCode >= 300) {
                     $this->logger->error('HTTP Error Status received from API', $logCtx + ['api_code_in_body' => $data['code'] ?? 'N/A', 'api_msg_in_body' => $data['msg'] ?? 'N/A', 'response_body' => substr($body,0,500)]);
                     throw new \RuntimeException("HTTP error {$statusCode} for " . $url . ". Body: " . $body, $statusCode);
                }
                // Success
                return $data;
            },
            function (\Throwable $e) use ($method, $url) {
                // Handle client-side errors (network, timeouts, response exceptions)
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'err_type' => get_class($e), 'err_msg' => $e->getMessage()];
                $binanceCode = 0; // Default code for non-API errors
                $runtimeMsg = "API Request failure";

                if ($e instanceof \React\Http\Message\ResponseException) {
                    $response = $e->getResponse();
                    $logCtx['response_status_code'] = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                    $logCtx['response_body_preview'] = substr($responseBody, 0, 500);
                    $responseData = json_decode($responseBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($responseData['code'], $responseData['msg'])) {
                        $logCtx['binance_api_code_from_exception'] = $responseData['code'];
                        $logCtx['binance_api_msg_from_exception'] = $responseData['msg'];
                        $binanceCode = (int)$responseData['code'];
                        $runtimeMsg = "Binance API error via HTTP Exception ({$binanceCode}): {$responseData['msg']}";
                    } else {
                        $runtimeMsg = "HTTP error {$response->getStatusCode()} with unparseable/non-Binance-error body.";
                    }
                } else {
                    $runtimeMsg .= " (Network/Client Error)";
                }
                $this->logger->error($runtimeMsg, $logCtx);
                throw new \RuntimeException($runtimeMsg . " for {$method} " . parse_url($url, PHP_URL_PATH), $binanceCode, $e);
            }
        );
    }

    // --- Price/Quantity Precision Formatting (could be loaded from exchangeInfo for robustness) ---
    private function getPricePrecisionFormat(string $symbol): string {
        if (strtoupper($symbol) === 'BTCUSDT') return '%.1f';
        if (strtoupper($symbol) === 'ETHUSDT') return '%.2f';
        return '%.8f'; // High precision default
    }
    private function getQuantityPrecisionFormat(string $symbol): string {
         if (strtoupper($symbol) === 'BTCUSDT') return '%.3f';
         if (strtoupper($symbol) === 'ETHUSDT') return '%.3f';
        return '%.8f'; // High precision default
    }

    // --- Specific Binance Futures API Endpoint Methods ---

    private function getFuturesAccountBalance(): PromiseInterface {
        $endpoint = '/fapi/v2/balance';
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                 if (!is_array($data)) throw new \RuntimeException("Invalid response for getFuturesAccountBalance: not an array.");
                $balances = [];
                foreach ($data as $assetInfo) {
                    if (isset($assetInfo['asset'], $assetInfo['balance'], $assetInfo['availableBalance'])) {
                        $balances[strtoupper($assetInfo['asset'])] = [
                            'balance' => (float)$assetInfo['balance'],
                            'availableBalance' => (float)$assetInfo['availableBalance']
                        ];
                    }
                }
                $this->logger->debug("Fetched futures balances", ['margin_asset' => $this->marginAsset, 'balance_data_for_margin_asset' => $balances[$this->marginAsset] ?? 'N/A']);
                return $balances;
            });
    }

    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1];
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true) // Public endpoint
             ->then(function ($data) use ($interval, $symbol) {
                 if (!is_array($data) || empty($data) || !isset($data[0][4])) {
                    throw new \RuntimeException("Invalid klines response format for {$symbol}, interval {$interval}. Data: " . json_encode($data));
                 }
                $price = (float)$data[0][4]; // Index 4 is Close price
                if ($price <=0) throw new \RuntimeException("Invalid kline price: {$price} for {$symbol}, interval {$interval}");
                return ['price' => $price, 'timestamp' => (int)$data[0][0]]; // Index 0 is Open time
            });
    }

    private function getHistoricalKlines(string $symbol, string $interval, int $limit = 100): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => min($limit, 1500)]; // Max limit is 1500
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        $this->logger->debug("Fetching historical klines", ['symbol' => $symbol, 'interval' => $interval, 'limit' => $params['limit']]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true) // Public endpoint
            ->then(function ($data) use ($symbol, $interval){
                if (!is_array($data)) {
                    $this->logger->warning("Invalid historical klines response format, expected array.", ['symbol' => $symbol, 'interval' => $interval, 'response_type' => gettype($data)]);
                    throw new \RuntimeException("Invalid klines response format for {$symbol}, interval {$interval}. Expected array.");
                }
                // Format into more readable structure for AI
                $formattedKlines = array_map(function($kline) {
                    if (is_array($kline) && count($kline) >= 6) { // Need at least open time to volume
                        return [
                            'openTime' => (int)$kline[0], 'open' => (string)$kline[1], 'high' => (string)$kline[2],
                            'low' => (string)$kline[3], 'close' => (string)$kline[4], 'volume' => (string)$kline[5],
                            // 'closeTime' => (int)$kline[6], // Optional to save tokens
                        ];
                    } return null; // Invalid kline format
                }, $data);
                $formattedKlines = array_filter($formattedKlines); // Remove nulls from invalid entries
                $this->logger->debug("Fetched historical klines successfully", ['symbol' => $symbol, 'interval' => $interval, 'count_fetched' => count($formattedKlines)]);
                return $formattedKlines;
            });
    }

    // Uses /fapi/v2/positionRisk
    private function getPositionInformation(string $symbol): PromiseInterface {
        $endpoint = '/fapi/v2/positionRisk'; // Use v2 endpoint
        $params = ['symbol' => strtoupper($symbol)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) use ($symbol) {
                 if (!is_array($data)) throw new \RuntimeException("Invalid response for getPositionInformation (v2) for {$symbol}: not an array.");
                 $positionToReturn = null;
                 foreach ($data as $pos) {
                     if (isset($pos['symbol']) && $pos['symbol'] === strtoupper($symbol)) {
                         if (isset($pos['positionAmt']) && abs((float)$pos['positionAmt']) > 1e-9) {
                             $positionToReturn = $pos; break;
                         }
                     }
                 }
                 if ($positionToReturn) $this->logger->debug("Fetched position information (v2) for {$symbol}", ['position_data_preview' => substr(json_encode($positionToReturn),0,150)]);
                 else $this->logger->debug("No active position found for {$symbol} via getPositionInformation (v2).", ['raw_response_preview' => substr(json_encode($data),0,150)]);
                 return $positionToReturn;
            });
    }

    private function setLeverage(string $symbol, int $leverage): PromiseInterface {
        $endpoint = '/fapi/v1/leverage';
        $params = ['symbol' => strtoupper($symbol), 'leverage' => max(1, $leverage)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
             ->then(function ($data) use ($symbol, $leverage) {
                 $responseLeverage = $data['leverage'] ?? null;
                 $responseMsg = $data['msg'] ?? ($data['message'] ?? '');
                 $this->logger->info("Leverage set attempt result for {$symbol}", [
                     'requested' => $leverage, 'response_symbol' => $data['symbol'] ?? 'N/A',
                     'response_leverage' => $responseLeverage, 'response_msg' => $responseMsg
                     ]);
                 if ($responseLeverage == $leverage && stripos($responseMsg, "leverage not modified") !== false) {
                     $this->logger->info("Leverage for {$symbol} was already {$leverage}. Not modified.");
                 } elseif ($responseLeverage != $leverage && !empty($responseMsg)) {
                     $this->logger->warning("Leverage set response discrepancy for {$symbol}.", ['requested' => $leverage, 'responded' => $responseLeverage, 'message' => $responseMsg]);
                 }
                 return $data;
            });
    }

    // New: Get User Commission Rate for a symbol
    private function getFuturesCommissionRate(string $symbol): PromiseInterface {
        $endpoint = '/fapi/v1/commissionRate';
        $params = ['symbol' => strtoupper($symbol)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) use ($symbol) {
                if (!is_array($data) || !isset($data['symbol'], $data['makerCommissionRate'], $data['takerCommissionRate'])) {
                    throw new \RuntimeException("Invalid commissionRate response for {$symbol}. Data: " . json_encode($data));
                }
                $makerRate = (float)$data['makerCommissionRate'];
                $takerRate = (float)$data['takerCommissionRate'];
                $this->logger->debug("Fetched commission rates for {$symbol}", ['maker' => $makerRate, 'taker' => $takerRate]);
                return ['symbol' => $symbol, 'makerCommissionRate' => $makerRate, 'takerCommissionRate' => $takerRate];
            });
    }

    // --- Order Placement Methods ---

    private function placeFuturesLimitOrder(
        string $symbol, string $side, float $quantity, float $price,
        ?string $timeInForce = 'GTC', ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($price <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/quantity for limit order. P:{$price} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'LIMIT', 'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'price' => sprintf($this->getPricePrecisionFormat($symbol), $price), 'timeInForce' => $timeInForce,
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $this->logger->debug("Placing Limit Order", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesMarketOrder(
        string $symbol, string $side, float $quantity,
        ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid quantity for market order. Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $this->logger->debug("Placing Market Order", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesStopMarketOrder(
        string $symbol, string $side, float $quantity, float $stopPrice,
        bool $reduceOnly = true, ?string $positionSide = 'BOTH' // Default reduceOnly=true for SL
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for STOP_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'STOP_MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat($symbol), $stopPrice), 'reduceOnly' => $reduceOnly ? 'true' : 'false',
            'workingType' => 'MARK_PRICE' // Trigger based on Mark Price
        ];
        $this->logger->debug("Placing Stop Market Order (SL)", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesTakeProfitMarketOrder(
        string $symbol, string $side, float $quantity, float $stopPrice, // stopPrice acts as trigger price for TP
        bool $reduceOnly = true, ?string $positionSide = 'BOTH' // Default reduceOnly=true for TP
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
         if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for TAKE_PROFIT_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'TAKE_PROFIT_MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat($symbol), $stopPrice), // Trigger price
            'reduceOnly' => $reduceOnly ? 'true' : 'false',
            'workingType' => 'MARK_PRICE' // Trigger based on Mark Price
        ];
        $this->logger->debug("Placing Take Profit Market Order (TP)", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    // --- Order Status and Cancellation ---

    // Gets status of a specific order - Use primarily for fallback checks
    private function getFuturesOrderStatus(string $symbol, string $orderId): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        $params = ['symbol' => strtoupper($symbol), 'orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    // Fallback check triggered by timer
    private function checkActiveOrderStatus(string $orderId, string $orderTypeLabel): void {
        if ($this->isPlacingOrManagingOrder) {
             $this->logger->debug("Skipping status check for {$orderId} ({$orderTypeLabel}) as an operation is in progress.");
             return;
        }
        $this->logger->debug("Performing fallback status check for {$orderTypeLabel} order {$orderId}");

        $this->getFuturesOrderStatus($this->tradingSymbol, $orderId)
        ->then(function (array $orderStatusData) use ($orderId, $orderTypeLabel) {
            $status = $orderStatusData['status'] ?? 'UNKNOWN';
            $this->logger->debug("Fallback Check Result for {$orderTypeLabel} order {$orderId}", ['status' => $status, 'api_data_preview' => substr(json_encode($orderStatusData),0,200)]);

             // --- Specific Handling for ENTRY order fallback ---
             if ($orderTypeLabel === 'ENTRY' && $this->activeEntryOrderId === $orderId) {
                 if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                     $this->logger->warning("Fallback check found active entry order {$orderId} as {$status}. WS event likely missed. Resetting state.");
                     $this->addOrderToLog($orderId, $status . '_FALLBACK', $orderStatusData['side'] ?? 'N/A', $this->tradingSymbol, (float)($orderStatusData['price'] ?? 0), (float)($orderStatusData['origQty'] ?? 0), $this->marginAsset, time(), (float)($orderStatusData['realizedPnl'] ?? 0));
                     $this->resetTradeState();
                     $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} found {$status} via fallback. State reset.", 'decision' => null];
                 } elseif ($status === 'FILLED') {
                      $this->logger->critical("CRITICAL FALLBACK: Entry order {$orderId} found FILLED via fallback check. WS missed this! Attempting recovery.", ['order_data' => $orderStatusData]);

                      $filledQty = (float)($orderStatusData['executedQty'] ?? 0);
                      $avgFilledPrice = (float)($orderStatusData['avgPrice'] ?? 0);
                     if ($filledQty > 0 && $avgFilledPrice > 0) {
                          $this->currentPositionDetails = [
                             'symbol' => $this->tradingSymbol,
                             'side' => $orderStatusData['side'] === 'BUY' ? 'LONG' : 'SHORT',
                             'entryPrice' => $avgFilledPrice,
                             'quantity' => $filledQty,
                             'leverage' => (int)($this->currentPositionDetails['leverage'] ?? $this->aiSuggestedLeverage),
                             'markPrice' => $avgFilledPrice,
                             'unrealizedPnl' => 0,
                             'positionSideBinance' => $orderStatusData['positionSide'] ?? 'BOTH'
                         ];
                         $this->logger->info("Position details forced update from fallback FILL check.", $this->currentPositionDetails);
                         $this->addOrderToLog($orderId, $status . '_FALLBACK', $orderStatusData['side'] ?? 'N/A', $this->tradingSymbol, $avgFilledPrice, $filledQty, $this->marginAsset, time(), (float)($orderStatusData['realizedPnl'] ?? 0));

                         $this->activeEntryOrderId = null; $this->activeEntryOrderTimestamp = null;
                         $this->isMissingProtectiveOrder = false;
                         $this->placeSlAndTpOrders(); // Attempt SL/TP placement
                         $this->lastAIDecisionResult = ['status' => 'WARN_FALLBACK', 'message' => "Entry order {$orderId} FILLED via fallback. SL/TP placement initiated.", 'decision' => null];
                     } else {
                          $this->logger->error("Fallback FILL check for {$orderId} missing critical fill data (qty/price). Recovery failed.", ['order_data' => $orderStatusData]);
                          $this->lastAIDecisionResult = ['status' => 'ERROR_FALLBACK', 'message' => "Entry order {$orderId} FILLED via fallback, but fill data missing. State uncertain.", 'decision' => null];
                     }
                 }
             }
        })
        ->catch(function (\Throwable $e) use ($orderId, $orderTypeLabel) {
             if (str_contains($e->getMessage(), '-2013') || str_contains($e->getMessage(), '-2011') || stripos($e->getMessage(), 'Order does not exist') !== false || stripos($e->getMessage(), 'Unknown order sent') !== false) {
                $this->logger->info("Fallback check: {$orderTypeLabel} order {$orderId} not found on exchange. Likely resolved or never existed properly.", ['err_preview' => substr($e->getMessage(),0,100)]);
                 if ($orderTypeLabel === 'ENTRY' && $this->activeEntryOrderId === $orderId) {
                    $this->logger->warning("Active entry order {$orderId} disappeared from exchange according to fallback. Resetting state.");
                    $this->resetTradeState();
                    $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} not found via fallback and was active. State reset.", 'decision' => null];
                 }
                 if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                 if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
            } else {
                $this->logger->error("Failed to get {$orderTypeLabel} order status (fallback check).", ['orderId' => $orderId, 'exception_class' => get_class($e), 'exception' => $e->getMessage()]);
            }
        });
    }

    // Cancels a specific order by ID
    private function cancelFuturesOrder(string $symbol, string $orderId): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        $params = ['symbol' => strtoupper($symbol), 'orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function($data) use ($orderId, $symbol){
                $this->logger->info("Cancel order request processed for {$orderId} on {$symbol}", ['response_status' => $data['status'] ?? 'N/A', 'response_orderId' => $data['orderId'] ?? 'N/A']);
                return $data;
            })
            ->catch(function(\Throwable $e) use ($orderId, $symbol){
                if (str_contains($e->getMessage(), '-2011') || stripos($e->getMessage(), "order does not exist") !== false || stripos($e->getMessage(), 'Unknown order sent') !== false) {
                     $this->logger->info("Cancel order {$orderId} on {$symbol}: Order likely already resolved/gone.", ['error_code' => '-2011', 'message' => $e->getMessage()]);
                     return ['status' => 'ALREADY_RESOLVED', 'orderId' => $orderId, 'symbol' => $symbol, 'message' => $e->getMessage()];
                }
                $this->logger->error("Cancel order request failed for {$orderId} on {$symbol}", ['exception' => $e->getMessage()]);
                throw $e;
            });
    }

    // Gets recent trade history for context (less critical than order logs)
    private function getFuturesTradeHistory(string $symbol, int $limit = 10): PromiseInterface {
        $endpoint = '/fapi/v1/userTrades';
        $params = ['symbol' => strtoupper($symbol), 'limit' => min($limit, 1000)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function($data) use ($symbol) {
                $this->logger->debug("Fetched recent futures trades for {$symbol}", ['count' => is_array($data) ? count($data) : 'N/A']);
                // Ensure 'reduceOnly' is present in each trade
                $data = array_map(function ($trade) {
                    if (!isset($trade['reduceOnly'])) {
                        $trade['reduceOnly'] = false; // Default value if not present
                    }
                    return $trade;
                }, $data);
                return $data;
            });
    }

    // --- User Data Stream Management ---

    private function startUserDataStream(): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST'); // No parameters needed for POST
        $this->logger->info("Requesting new User Data Stream Listen Key...");
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function keepAliveUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'PUT'); // Needs listenKey parameter for PUT
        $this->logger->debug("Sending Keep-Alive for Listen Key...");
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function closeUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'DELETE'); // Needs listenKey parameter for DELETE
        $this->logger->info("Requesting closure of Listen Key {$listenKey}...");
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    // --- AI Interaction Logic ---

    // Gathers all necessary data for the AI prompt
    private function collectDataForAI(bool $isEmergency = false): PromiseInterface
    {
        $this->logger->debug("Collecting data for AI...", ['emergency' => $isEmergency, 'db_active' => (bool)$this->pdo]);
        $historicalKlineApiLimit = 20; // Max klines per interval for API fetch

        // API Data Collection Promises
        $promises = [
            'balance' => $this->getFuturesAccountBalance()
                             ->otherwise(fn($e) => ['error_fetch_balance' => substr($e->getMessage(),0,150), $this->marginAsset => ['availableBalance' => 'ERROR', 'balance' => 'ERROR']]),
            'position' => $this->getPositionInformation($this->tradingSymbol)
                              ->then(fn($rawPos) => ['raw_binance_position_data' => $rawPos])
                              ->otherwise(fn($e) => ['error_fetch_position' => substr($e->getMessage(),0,150), 'raw_binance_position_data' => null]),
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 20)
                                    ->then(fn($trades) => ['raw_binance_account_trades' => $trades])
                                    ->otherwise(fn($e) => ['error_fetch_trade_history' => substr($e->getMessage(),0,150), 'raw_binance_account_trades' => []]),
            'commission_rates' => $this->getFuturesCommissionRate($this->tradingSymbol)
                                      ->otherwise(fn($e) => ['error_fetch_commission_rates' => substr($e->getMessage(),0,150), 'makerCommissionRate' => 'ERROR', 'takerCommissionRate' => 'ERROR']),
        ];

        // Multi-Timeframe Kline Data Promises
        $multiTfKlinePromises = [];
        foreach ($this->historicalKlineIntervalsAIArray as $interval) {
            $multiTfKlinePromises[$interval] = $this->getHistoricalKlines($this->tradingSymbol, $interval, $historicalKlineApiLimit)
                                                 ->then(fn($kData) => ['data' => $kData])
                                                 ->otherwise(fn($e) => ['error_fetch_kline_' . $interval => substr($e->getMessage(),0,150), 'data' => []]);
        }
        $promises['historical_klines_all_intervals'] = \React\Promise\all($multiTfKlinePromises);

        // --- Database Data Collection (Synchronous calls for simplicity in this iteration) ---
        // Active Trade Logic Source (use the current in-memory version, loaded at startup/after AI update)
        $activeTradeLogicSourceForAI = $this->currentActiveTradeLogicSource['strategy_directives'] ?? $this->getDefaultStrategyDirectives();
        $activeTradeLogicSourceFullRecordForAI = $this->currentActiveTradeLogicSource; // Includes DB id, version, etc.

        $dbOrderLogs = $this->getRecentOrderLogsFromDb($this->tradingSymbol, self::MAX_ORDER_LOG_ENTRIES_FOR_AI_CONTEXT);
        $dbRecentAIInteractions = $this->getRecentAIInteractionsFromDb($this->tradingSymbol, self::MAX_AI_INTERACTIONS_FOR_AI_CONTEXT);


        return \React\Promise\all($promises)->then(function (array $results) use ($isEmergency, $activeTradeLogicSourceForAI, $activeTradeLogicSourceFullRecordForAI, $dbOrderLogs, $dbRecentAIInteractions) {
            // Process API Results
            $rawPositionData = $results['position']['raw_binance_position_data'] ?? null;
            $this->currentPositionDetails = $this->formatPositionDetails($rawPositionData); // Update internal state
            $currentPositionForAI = $this->currentPositionDetails;
            if(isset($results['position']['error_fetch_position'])) {
                $currentPositionForAI = ['error_fetch_position' => $results['position']['error_fetch_position']];
            }

            $balanceDetails = $results['balance'][$this->marginAsset] ?? ['availableBalance' => 0.0, 'balance' => 0.0];
            $balanceForAI = $balanceDetails;
             if(isset($results['balance']['error_fetch_balance'])) {
                 $balanceForAI = ['error_fetch_balance' => $results['balance']['error_fetch_balance']];
             }

            $rawAccountTrades = $results['trade_history']['raw_binance_account_trades'] ?? [];
             if(isset($results['trade_history']['error_fetch_trade_history'])) {
                 $rawAccountTrades = ['error_fetch_trade_history' => $results['trade_history']['error_fetch_trade_history']];
             }
            $historicalKlinesMultiTfData = $results['historical_klines_all_intervals'] ?? [];
            $commissionRates = $results['commission_rates'] ?? ['symbol' => $this->tradingSymbol, 'makerCommissionRate' => 'ERROR', 'takerCommissionRate' => 'ERROR'];
            if(isset($results['commission_rates']['error_fetch_commission_rates'])) {
                $commissionRates = ['error_fetch_commission_rates' => $results['commission_rates']['error_fetch_commission_rates']];
            }

            // Bot Operational State
            $activeEntryOrderDetails = null;
            if($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                 $secondsPassed = time() - $this->activeEntryOrderTimestamp;
                 $timeoutIn = max(0, $this->pendingEntryOrderCancelTimeoutSeconds - $secondsPassed);
                $activeEntryOrderDetails = [
                    'orderId' => $this->activeEntryOrderId, 'placedAt_iso_utc' => gmdate('Y-m-d H:i:s', $this->activeEntryOrderTimestamp) . ' UTC',
                    'side_intent' => $this->aiSuggestedSide ?? 'N/A', 'price_intent' => $this->aiSuggestedEntryPrice ?? 0,
                    'quantity_intent' => $this->aiSuggestedQuantity ?? 0, 'seconds_pending' => $secondsPassed, 'timeout_in_seconds' => $timeoutIn
                ];
            }
            $isMissingProtectionNow = ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId) && !$this->isPlacingOrManagingOrder);
            if ($isMissingProtectionNow && !$this->isMissingProtectiveOrder) { // Log when state *changes* to critical
                $this->logger->warning("CRITICAL STATE DETECTED (collectDataForAI): Open position missing one or both SL/TP orders.", [
                    'position' => $this->currentPositionDetails, 'sl_id_state' => $this->activeSlOrderId, 'tp_id_state' => $this->activeTpOrderId
                ]);
            }
            $this->isMissingProtectiveOrder = $isMissingProtectionNow; // Update internal state

            // Assemble Final Data Structure for AI (rich dataset)
            $dataForAI = [
                'bot_metadata' => [
                    'current_timestamp_iso_utc' => gmdate('Y-m-d H:i:s') . ' UTC', 'trading_symbol' => $this->tradingSymbol,
                    'margin_asset' => $this->marginAsset, 'main_kline_interval_for_live_price' => $this->klineInterval,
                    'is_emergency_update_request' => $isEmergency, 'db_connection_active' => (bool)$this->pdo,
                    'bot_id' => $this->botConfigId, 'bot_run_start_time_unix' => $this->botStartTime,
                ],
                'market_data' => [
                    'current_market_price_from_main_interval' => $this->lastClosedKlinePrice ?? 'N/A',
                    'historical_klines_multi_tf' => $historicalKlinesMultiTfData, // Full kline data
                    'commission_rates' => $commissionRates, // Add commission rates here
                ],
                'account_state' => [
                    'current_margin_asset_balance_details' => $balanceForAI,
                    'current_position_details' => $currentPositionForAI,
                    'raw_binance_position_data_v2' => $rawPositionData, // For AI's deeper inspection if needed
                    'recent_account_trades_from_api_raw' => $rawAccountTrades,
                ],
                'bot_operational_state' => [
                    'active_pending_entry_order_details' => $activeEntryOrderDetails,
                    'active_stop_loss_order_id' => $this->activeSlOrderId,
                    'active_take_profit_order_id' => $this->activeTpOrderId,
                    'is_placing_or_managing_order_lock' => $this->isPlacingOrManagingOrder,
                    'position_missing_protective_orders_FLAG' => $this->isMissingProtectiveOrder,
                ],
                'historical_bot_performance_and_decisions' => [
                    'last_ai_decision_bot_feedback' => $this->lastAIDecisionResult,
                    'recent_bot_order_log_outcomes_from_db' => $dbOrderLogs,
                    'recent_ai_interactions_from_db' => $dbRecentAIInteractions,
                ],
                // --- TRADE LOGIC SOURCE (from DB) ---
                'current_guiding_trade_logic_source' => [
                    'source_details_from_db' => $activeTradeLogicSourceFullRecordForAI ? [
                        'name' => $activeTradeLogicSourceFullRecordForAI['source_name'], 'version' => (int)$activeTradeLogicSourceFullRecordForAI['version'],
                        'last_updated_by' => $activeTradeLogicSourceFullRecordForAI['last_updated_by'],
                        'last_updated_at_utc' => $activeTradeLogicSourceFullRecordForAI['last_updated_at_utc']
                    ] : ['name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME . '_not_loaded', 'version'=>0, 'last_updated_by' => 'SYSTEM', 'last_updated_at_utc' => gmdate('Y-m-d H:i:s') . ' UTC'],
                    'strategy_directives' => $activeTradeLogicSourceForAI // Decoded directives for AI's consumption
                ],
                // --- BOT CONFIG (non-dynamic, but useful for AI context) ---
                'bot_configuration_summary_for_ai' => [
                    'primaryHistoricalKlineIntervalAI' => $this->primaryHistoricalKlineIntervalAI,
                    'allHistoricalKlineIntervalsAI' => $this->historicalKlineIntervalsAIArray,
                    'initialMarginTargetUsdtForAI' => $this->initialMarginTargetUsdt,
                    'profitTakingTargetUsdt_independent' => $this->takeProfitTargetUsdt > 0 ? $this->takeProfitTargetUsdt : 'Disabled',
                    'defaultLeverageIfAIOmits' => $this->defaultLeverage,
                    'aiUpdateIntervalSeconds_normal' => $this->aiUpdateIntervalSeconds,
                    'pendingEntryOrderTimeoutSeconds_bot_enforced' => $this->pendingEntryOrderCancelTimeoutSeconds,
                ],
                // Generic instruction for bot's fixed capabilities
                'bot_fixed_capabilities_summary' => "This bot trades {$this->tradingSymbol} futures. It handles API communication, WebSocket streams, risk flags, and an independent profit taker. AI's role is to provide specific trading decisions and strategy updates based on analysis. The bot will automatically close a position if PnL >= {$this->takeProfitTargetUsdt} USDT. AI can suggest an earlier close. The bot enforces a {$this->pendingEntryOrderCancelTimeoutSeconds}s timeout for pending entry orders.",
            ];

            $this->logger->debug("Data collected for AI (rich dataset)", [
                'market_price_main' => $dataForAI['market_data']['current_market_price_from_main_interval'],
                'balance_avail' => $balanceForAI['availableBalance'] ?? 'N/A',
                'position_exists' => !is_null($currentPositionForAI) && !isset($currentPositionForAI['error_fetch_position']),
                'db_order_logs_count' => is_array($dbOrderLogs) && !isset($dbOrderLogs[0]['error']) ? count($dbOrderLogs) : 0,
                'db_ai_interactions_count' => is_array($dbRecentAIInteractions) && !isset($dbRecentAIInteractions[0]['error']) ? count($dbRecentAIInteractions) : 0,
                'active_logic_source_bias' => $activeTradeLogicSourceForAI['current_market_bias'] ?? 'N/A',
            ]);
            return $dataForAI;
        })->catch(function (\Throwable $e) {
            $this->logger->error("CRITICAL FAILURE in collectDataForAI promise ALL. Bot might proceed with outdated data.", ['exception_class'=>get_class($e), 'exception' => $e->getMessage()]);
            $this->lastAIDecisionResult = ['status' => 'ERROR_DATA_COLLECTION', 'message' => "Data collection failed critically: " . $e->getMessage(), 'decision' => null];
            // Log this failure to DB
            $this->logAIInteractionToDb('ERROR_DATA_COLLECTION', null, $this->lastAIDecisionResult, ['error' => 'Data for AI was not available due to earlier failure in cycle: ' . $e->getMessage()], $this->currentPromptMD5ForDBLog, null);
            throw $e; // Re-throw to ensure the promise chain is marked as failed, preventing subsequent AI call with incomplete data
        });
    }

    // --- Unbiased constructAIPrompt ---
    // This method now serves as a presenter of data and a task definer, without embedding specific strategies.
    private function constructAIPrompt(array $fullDataForAI, bool $isEmergency): string
    {
        // Define how many recent klines to include per interval in the prompt text.
        // IMPORTANT: Increasing this significantly will increase prompt token count, API costs,
        // and potentially hit the AI model's input token limits.
        $PROMPT_KLINES_LIMIT = 10; // You can adjust this value (e.g., to 100, 200, etc.)

        // 1. Summarize complex data for the text prompt
        $summarizedDataForPromptText = [
             'bot_metadata' => $fullDataForAI['bot_metadata'],
             'market_data' => [ // Renamed from market_data_summary for clarity
                'current_market_price' => $fullDataForAI['market_data']['current_market_price_from_main_interval'],
                'commission_rates' => $fullDataForAI['market_data']['commission_rates'], // Include commission rates
                // This will now contain the actual kline arrays, limited to PROMPT_KLINES_LIMIT
                'historical_klines_for_ai_detailed' => [],
             ],
             'account_state_summary' => [
                'position_details' => $fullDataForAI['account_state']['current_position_details'],
                'balance_available_usdt' => $fullDataForAI['account_state']['current_margin_asset_balance_details']['availableBalance'] ?? 'N/A',
                'recent_account_trades_count' => count($fullDataForAI['account_state']['recent_account_trades_from_api_raw'] ?? []),
             ],
             'bot_operational_state_summary' => [
                'is_emergency_mode' => $isEmergency,
                'position_missing_protective_orders_FLAG' => $fullDataForAI['bot_operational_state']['position_missing_protective_orders_FLAG'],
                'active_pending_entry_order_id' => $fullDataForAI['bot_operational_state']['active_pending_entry_order_details']['orderId'] ?? null,
                'active_sl_order_id' => $fullDataForAI['bot_operational_state']['active_stop_loss_order_id'],
                'active_tp_order_id' => $fullDataForAI['bot_operational_state']['active_take_profit_order_id'],
             ],
             'historical_bot_performance_summary' => [
                'last_ai_decision_bot_feedback' => $fullDataForAI['historical_bot_performance_and_decisions']['last_ai_decision_bot_feedback'],
                'recent_bot_order_logs_count' => count($fullDataForAI['historical_bot_performance_and_decisions']['recent_bot_order_log_outcomes_from_db'] ?? []),
                'recent_ai_interactions_count' => count($fullDataForAI['historical_bot_performance_and_decisions']['recent_ai_interactions_from_db'] ?? []),
             ],
        ];

        // Populate historical_klines_for_ai_detailed with a subset of raw kline data
        if (isset($fullDataForAI['market_data']['historical_klines_multi_tf']) && is_array($fullDataForAI['market_data']['historical_klines_multi_tf'])) {
            foreach ($fullDataForAI['bot_configuration_summary_for_ai']['allHistoricalKlineIntervalsAI'] as $interval) {
                if (isset($fullDataForAI['market_data']['historical_klines_multi_tf'][$interval]['data']) && !empty($fullDataForAI['market_data']['historical_klines_multi_tf'][$interval]['data'])) {
                    // Take the last $PROMPT_KLINES_LIMIT klines for each interval
                    $klinesToInclude = array_slice($fullDataForAI['market_data']['historical_klines_multi_tf'][$interval]['data'], -$PROMPT_KLINES_LIMIT);
                    $summarizedDataForPromptText['market_data']['historical_klines_for_ai_detailed'][$interval] = $klinesToInclude;
                } elseif (isset($fullDataForAI['market_data']['historical_klines_multi_tf'][$interval]['error_fetch_kline_' . $interval])) {
                    $summarizedDataForPromptText['market_data']['historical_klines_for_ai_detailed'][$interval] = [
                        'status' => 'Error fetching',
                        'error_message' => $fullDataForAI['market_data']['historical_klines_multi_tf'][$interval]['error_fetch_kline_' . $interval]
                    ];
                } else {
                    $summarizedDataForPromptText['market_data']['historical_klines_for_ai_detailed'][$interval] = ['status' => 'No data'];
                }
            }
        }


        // --- PROMPT TEXT CONSTRUCTION START ---
        $promptText = "You are an autonomous trading AI for {$this->tradingSymbol} on Binance USDM Futures.\n";
        $promptText .= "Your role is to analyze current market conditions, historical data, and bot operational state, then decide on an optimal trading action. You MUST adhere to the active trading strategy directives provided.\n";
        $promptText .= "Your decisions will be logged and influence future actions. You can also suggest updates to your guiding strategy.\n\n";

        $promptText .= "**LIVE MARKET & BOT CONTEXT:**\n";
        // Directly embed the $summarizedDataForPromptText which now includes detailed klines
        $promptText .= json_encode($summarizedDataForPromptText, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n\n";

        $promptText .= "=== ACTIVE STRATEGY DIRECTIVES (Your Guiding Source from Database) ===\n";
        $currentDirectives = $fullDataForAI['current_guiding_trade_logic_source']['strategy_directives'] ?? $this->getDefaultStrategyDirectives();
        $sourceInfo = $fullDataForAI['current_guiding_trade_logic_source']['source_details_from_db'] ?? ['name'=>'fallback', 'version'=>0, 'last_updated_by'=>'system', 'last_updated_at_utc'=>'N/A'];

        $promptText .= "Source Name: {$sourceInfo['name']} (Version: {$sourceInfo['version']}, Last Updated By: {$sourceInfo['last_updated_by']} at {$sourceInfo['last_updated_at_utc']})\n";
        $promptText .= "These directives define your current trading strategy. You MUST strictly adhere to these parameters for your trading decisions.\n";
        $promptText .= json_encode($currentDirectives, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $promptText .= "AI Learnings/Notes (from source): " . substr($currentDirectives['ai_learnings_notes'] ?? 'N/A', 0, 200) . "...\n\n";

        $promptText .= "=== DECISION TASK ===\n";
        $promptText .= "Based on the `LIVE MARKET & BOT CONTEXT`, and strictly following the `ACTIVE STRATEGY DIRECTIVES`, you must provide ONE primary action:\n";

        $possibleTradeActions = [];
        // Logic to determine possible actions based on bot's operational state (unbiased, derived from flags)
        if ($fullDataForAI['bot_operational_state']['position_missing_protective_orders_FLAG']) {
            $promptText .= "CRITICAL STATE: Position is open but protective orders (SL/TP) are missing. Safety is paramount.\n";
            $possibleTradeActions = ['CLOSE_POSITION']; // Only safe action in critical state
            if (!empty(trim($currentDirectives['emergency_hold_justification'] ?? ''))) { // AI could pre-configure an emergency hold justification
                 $possibleTradeActions[] = 'HOLD_POSITION';
                 $promptText .= "If emergency HOLD is chosen, it must be justified by: '{$currentDirectives['emergency_hold_justification']}'.\n";
            }
        } elseif ($fullDataForAI['bot_operational_state']['active_pending_entry_order_details']) {
            $promptText .= "Currently waiting for a pending entry order to fill.\n";
            $possibleTradeActions = ['HOLD_POSITION'];
        } elseif ($fullDataForAI['account_state']['current_position_details'] && !isset($fullDataForAI['account_state']['current_position_details']['error_fetch_position'])) {
            $promptText .= "A position is currently open.\n";
            $possibleTradeActions = ['HOLD_POSITION', 'CLOSE_POSITION'];
        } else {
            $promptText .= "No position is currently open, and no entry order is pending.\n";
            $possibleTradeActions = ['OPEN_POSITION', 'DO_NOTHING'];
        }

        $promptText .= "\n**Choose ONE of the following primary actions:**\n";
        $actionList = []; $actionCounter = 1;

        if (in_array('OPEN_POSITION', $possibleTradeActions)) {
            $actionList[] = "{$actionCounter}. OPEN_POSITION: `{\"action\": \"OPEN_POSITION\", \"leverage\": <int>, \"side\": \"BUY\"|\"SELL\", \"entryPrice\": <float>, \"quantity\": <float>, \"stopLossPrice\": <float>, \"takeProfitPrice\": <float>, \"trade_rationale\": \"<brief_justification_max_150_chars>\"}`\n" .
                           "   - Parameters (leverage, risk/reward, preferred TFs) MUST adhere to `ACTIVE STRATEGY DIRECTIVES`. Calculate `quantity` using `initialMarginTargetUsdtForAI` and `leverage`.\n";
            $actionCounter++;
        }
        if (in_array('CLOSE_POSITION', $possibleTradeActions)) {
            $actionList[] = "{$actionCounter}. CLOSE_POSITION: `{\"action\": \"CLOSE_POSITION\", \"reason\": \"<brief_justification_max_100_chars>\"}`\n" .
                           "   - Use if strategy dictates exiting, momentum lost, or critical state.\n";
            $actionCounter++;
        }
        if (in_array('HOLD_POSITION', $possibleTradeActions)) {
            $actionList[] = "{$actionCounter}. HOLD_POSITION: `{\"action\": \"HOLD_POSITION\", \"reason\": \"<brief_justification_max_100_chars>\"}`\n" .
                           "   - Use if current state aligns with strategy to hold or wait for fill.\n";
            $actionCounter++;
        }
        if (in_array('DO_NOTHING', $possibleTradeActions)) {
            $actionList[] = "{$actionCounter}. DO_NOTHING: `{\"action\": \"DO_NOTHING\", \"reason\": \"<brief_justification_max_100_chars>\"}`\n" .
                           "   - Use if no suitable trade opportunity per strategy.\n";
            $actionCounter++;
        }
        $promptText .= implode("\n", $actionList);

        // Optional: Suggest strategy updates
        $allowAIUpdate = ($currentDirectives['allow_ai_to_update_self'] ?? false) === true;
        if ($allowAIUpdate) {
            $promptText .= "\n**OPTIONAL: STRATEGY UPDATE SUGGESTION** (Include this field in your JSON response if you wish to update the strategy directives):\n";
            $promptText .= "`\"suggested_strategy_directives_update\": {\"updated_directives\": {<ENTIRE_new_strategy_directives_json_object>}, \"reason_for_update\": \"<explanation_of_change_max_200_chars>\"}`\n";
            $promptText .= "   - `updated_directives` MUST be the COMPLETE JSON object for the new strategy. Do NOT omit unchanged fields.\n";
            $promptText .= "   - Justify why the current `ACTIVE STRATEGY DIRECTIVES` are suboptimal for the `LIVE CONTEXT` and how your `updated_directives` improve the strategy.\n";
        }

        $promptText .= "\n=== MANDATORY OUTPUT RULES ===\n";
        $promptText .= "- Your entire response MUST be a single JSON object. No markdown, no extra text.\n";
        $promptText .= "- It MUST contain a top-level `action` field with one of the primary actions defined above.\n";
        $promptText .= "- Parameters for `OPEN_POSITION` (leverage, quantities, prices) MUST be calculated to adhere to the `risk_parameters` (target USDT risk, R/R) and `leverage_preference` from the `ACTIVE STRATEGY DIRECTIVES`.\n";
        $promptText .= "- If `suggested_strategy_directives_update` is included, its `updated_directives` field MUST be the COMPLETE JSON structure of the new strategy directives. Do NOT provide partial updates.\n";
        $promptText .= "- Ensure numerical values are correctly formatted (floats).\n";
        $promptText .= "- Critical State (`position_missing_protective_orders_FLAG`): Prioritize capital preservation. If trade cannot be closed safely, justify HOLD_POSITION extremely cautiously.\n";
        $promptText .= "\nProvide ONLY the JSON object for your decision.";

        $generationConfig = [
            'temperature' => 1, // A bit higher for reasoning but still consistent
            'topK' => 1,
            'topP' => 0.95,
            'maxOutputTokens' => 8072, // Increased for potentially larger updated_directives
            'responseMimeType' => 'application/json', // Request JSON output directly
        ];

        return json_encode([
            'contents' => [['parts' => [['text' => $promptText]]]],
            'generationConfig' => $generationConfig
        ]);
    }

    // --- Triggers the full cycle: Collect Data -> Build Prompt -> Send to AI -> Process Response -> Execute Decision
    public function triggerAIUpdate(bool $isEmergency = false): void
    {
        if ($this->isPlacingOrManagingOrder && !$isEmergency) {
            $this->logger->debug("AI Update: Operation in progress, skipping non-emergency AI update cycle.");
            return;
        }
        if ($isEmergency) $this->logger->warning('*** EMERGENCY AI UPDATE TRIGGERED ***');
        else $this->logger->info('Starting AI parameter update cycle...');

        // Reset state for logging the current AI interaction
        $this->currentDataForAIForDBLog = null;
        $this->currentPromptMD5ForDBLog = null;
        $this->currentRawAIResponseForDBLog = null;

        // Ensure the latest trade logic source is loaded before collecting other data
        // This is important if a previous AI cycle updated it.
        $this->loadActiveTradeLogicSource();

        $this->collectDataForAI($isEmergency)
            ->then(function (array $dataForAI) use ($isEmergency) {
                $this->currentDataForAIForDBLog = $dataForAI; // Store full data for later DB logging
                $promptPayloadJson = $this->constructAIPrompt($dataForAI, $isEmergency);
                $this->currentPromptMD5ForDBLog = md5($promptPayloadJson); // Store hash of the generated prompt
                return $this->sendRequestToAI($promptPayloadJson);
            })
            ->then(
                function ($rawResponse) { // This is the raw JSON string from the AI
                    $this->currentRawAIResponseForDBLog = $rawResponse; // Store raw response
                    return $this->processAIResponse($rawResponse); // This calls executeAIDecision which will log to DB
                }
            )
            ->catch(function (\Throwable $e) {
                $status_code = 'ERROR_CYCLE';
                if ($e->getCode() === 429) { // Gemini API rate limit
                     $this->logger->warning("AI update cycle hit rate limit (429). Will retry on next scheduled interval.", ['exception' => $e->getMessage()]);
                     $status_code = 'ERROR_RATE_LIMIT';
                } else {
                    $this->logger->error('AI update cycle failed.', ['exception_class' => get_class($e), 'exception' => $e->getMessage(), 'trace_preview' => substr($e->getTraceAsString(),0,500)]);
                }
                $this->lastAIDecisionResult = ['status' => $status_code, 'message' => "AI update cycle failed: " . $e->getMessage(), 'decision' => null];
                // Log this failure to DB
                $this->logAIInteractionToDb(
                    $status_code, // executed action
                    null,          // ai decision params (none generated in error)
                    $this->lastAIDecisionResult, // bot feedback
                    $this->currentDataForAIForDBLog ?? ['error' => 'Data for AI was not available due to earlier failure in cycle: ' . $e->getMessage()],
                    $this->currentPromptMD5ForDBLog,
                    $this->currentRawAIResponseForDBLog ?? ($e instanceof \React\Http\Message\ResponseException ? (string) $e->getResponse()?->getBody() : null) // Get response body if possible
                );
            });
    }

    // Sends the prepared payload to the Gemini API
    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = ['Content-Type' => 'application/json'];
        $this->logger->debug('Sending request to Gemini AI', ['url_path' => 'models/' . $this->geminiModelName . ':generateContent', 'payload_length' => strlen($jsonPayload), 'model' => $this->geminiModelName]);

        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $this->logger->debug('Received response from Gemini AI', ['status' => $statusCode, 'body_preview' => substr($body, 0, 200) . '...']);

                if ($statusCode >= 300) {
                    if ($statusCode === 429) { // Rate limit
                        $this->logger->warning('Gemini API rate limit hit (429 Too Many Requests).', ['response_body' => substr($body,0,500)]);
                        throw new \RuntimeException("Gemini API rate limit hit (429).", 429);
                    }
                    $this->logger->error('Gemini API HTTP error.', ['status_code' => $statusCode, 'response_body' => $body]);
                    throw new \RuntimeException("Gemini API HTTP error: " . $statusCode . " Body: " . substr($body, 0, 500), $statusCode);
                }
                return $body; // Return the raw body string (expected to be JSON)
            },
            function (\Throwable $e) {
                $context = ['exception_class' => get_class($e), 'exception_msg' => $e->getMessage()];
                if ($e->getCode() === 429) {
                     $this->logger->warning('Gemini API request failed due to rate limit (429).', $context);
                } else {
                     $this->logger->error('Gemini AI request failed (Network/Client Error).', $context);
                }
                throw $e; // Re-throw to be caught by triggerAIUpdate's catch block
            }
        );
    }

    // Parses the AI's JSON response and prepares for execution
    private function processAIResponse(string $rawResponse): void
    {
        $this->logger->debug('Processing AI response.', ['raw_response_preview' => substr($rawResponse,0,500)]);
        $aiDecisionParams = null; // To store the actual action params from AI
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode AI JSON response: " . json_last_error_msg() . ". Raw: " . substr($rawResponse,0,200));
            }

            // Check for prompt blocking or safety issues
            if (isset($responseDecoded['promptFeedback']['blockReason'])) {
                $blockReason = $responseDecoded['promptFeedback']['blockReason'];
                $safetyRatings = $responseDecoded['promptFeedback']['safetyRatings'] ?? [];
                $this->logger->error("AI prompt was blocked by safety filters.", ['reason' => $blockReason, 'safety_ratings' => $safetyRatings, 'raw_response' => substr($rawResponse,0,500)]);
                throw new \InvalidArgumentException("AI prompt blocked: {$blockReason}. Safety: " . json_encode($safetyRatings));
            }

            // Extract the AI's generated text content (which should be JSON)
             if (!isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                 $finishReason = $responseDecoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                 $safetyRatingsContent = $responseDecoded['candidates'][0]['safetyRatings'] ?? [];
                 $this->logger->error("AI response missing expected text content.", ['finish_reason' => $finishReason, 'safety_ratings' => $safetyRatingsContent, 'response_structure_preview' => substr($rawResponse,0,500)]);
                 throw new \InvalidArgumentException("AI response missing text content. Finish Reason: {$finishReason}.");
             }
             $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];

            // Clean up potential markdown backticks if model didn't respect responseMimeType
            $paramsJson = trim($aiTextResponse);
            if (str_starts_with($paramsJson, '```json')) $paramsJson = substr($paramsJson, 7);
            if (str_starts_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 3); // Also handles cases like ```php
            if (str_ends_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 0, -3);
            $paramsJson = trim($paramsJson);

            $aiDecisionParams = json_decode($paramsJson, true); // This is the array of action parameters
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Failed to decode JSON parameters from AI's cleaned text response.", [
                    'cleaned_ai_text_preview' => substr($paramsJson, 0, 200), 'json_error' => json_last_error_msg()
                ]);
                throw new \InvalidArgumentException("Failed to decode JSON from AI text: " . json_last_error_msg() . " - AI Text: " . substr($aiTextResponse,0,200));
            }

            $this->executeAIDecision($aiDecisionParams); // Pass the decoded decision parameters

        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response or extracting decision.', [
                'exception_class' => get_class($e), 'exception_msg' => $e->getMessage(),
                'raw_ai_response_for_error' => substr($rawResponse, 0, 500)
            ]);
            $this->lastAIDecisionResult = ['status' => 'ERROR_PROCESSING', 'message' => "Failed processing AI response: " . $e->getMessage(), 'decision_content_preview' => substr($rawResponse, 0, 100)];
            // Log this processing error to DB
            $this->logAIInteractionToDb(
                'ERROR_PROCESSING_AI_RESPONSE', $aiDecisionParams, $this->lastAIDecisionResult,
                $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog
            );
        }
    }

    // Takes the validated AI decision and triggers the corresponding bot action
    private function executeAIDecision(array $decision): void
    {
        $actionToExecute = strtoupper($decision['action'] ?? 'UNKNOWN_ACTION');
        $this->logger->info("AI Decision Received by executeAIDecision", ['action' => $actionToExecute, 'full_decision_params' => $decision]);

        $originalDecisionForLog = $decision; // Keep original for logging AI's raw output
        $overrideReason = null; // Reason if bot overrides AI's decision

        // --- Bot Safety & Logic Overrides ---
        // This section ensures the bot doesn't execute illogical or unsafe actions,
        // even if the AI suggests them.
        $currentBotContext = [
            'isMissingProtectiveOrder' => $this->isMissingProtectiveOrder,
            'activeEntryOrderId' => $this->activeEntryOrderId,
            'currentPositionExists' => !is_null($this->currentPositionDetails),
            'isPlacingOrManagingOrder' => $this->isPlacingOrManagingOrder,
        ];

        // Rule 1: Critical State (Missing Protection) - Prioritize safety
        if ($this->isMissingProtectiveOrder) {
            // Allow strategy update even in critical state if AI explicitly wants to fix strategy
            if ($actionToExecute !== 'CLOSE_POSITION' && $actionToExecute !== 'UPDATE_TRADE_LOGIC_SOURCE') {
                $overrideReason = "AI chose '{$actionToExecute}' in CRITICAL state (missing SL/TP). Bot enforces CLOSE for safety.";
                $actionToExecute = 'CLOSE_POSITION';
            } elseif ($actionToExecute === 'HOLD_POSITION' && empty(trim($this->currentActiveTradeLogicSource['strategy_directives']['emergency_hold_justification'] ?? ''))) {
                // If AI chooses HOLD in critical state without specific pre-configured justification
                $overrideReason = "AI chose HOLD in CRITICAL state (missing SL/TP) without pre-configured justification. Bot enforces CLOSE.";
                $actionToExecute = 'CLOSE_POSITION';
            }
        }
        // Rule 2: Pending Entry Order Exists - Cannot open new or close non-existent position
        elseif ($this->activeEntryOrderId) {
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while an entry order ({$this->activeEntryOrderId}) is already pending. Bot enforces HOLD (waiting).";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'CLOSE_POSITION' && !$this->currentPositionDetails) {
                 $overrideReason = "AI chose CLOSE_POSITION while an entry order is pending (no position to close yet). Bot enforces HOLD.";
                 $actionToExecute = 'HOLD_POSITION';
            } // HOLD or DO_NOTHING are generally acceptable if entry pending
        }
        // Rule 3: Position Already Exists - Cannot open new or DO_NOTHING when position active
        elseif ($this->currentPositionDetails) {
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while a position already exists. Bot enforces HOLD.";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'DO_NOTHING') {
                 $overrideReason = "AI chose DO_NOTHING while a position exists. Bot enforces HOLD to maintain position.";
                 $actionToExecute = 'HOLD_POSITION';
            } // HOLD or CLOSE_POSITION are acceptable
        }
        // Rule 4: No Position, No Pending Order - Cannot close non-existent position
        else {
            if ($actionToExecute === 'CLOSE_POSITION') {
                $overrideReason = "AI chose CLOSE_POSITION when no position exists. Bot enforces DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            } elseif ($actionToExecute === 'HOLD_POSITION' && empty(trim($decision['reason'] ?? ''))) {
                 // HOLD without a clear reason when flat is interpreted as DO_NOTHING
                $overrideReason = "AI chose HOLD_POSITION (no reason given) when no position or pending order exists. Bot interprets as DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            } // OPEN_POSITION or DO_NOTHING (or justified HOLD) are acceptable
        }

        // Log if an override occurred
        if ($overrideReason) {
            $this->logger->warning("AI Action Overridden by Bot Logic: {$overrideReason}", [
                'original_ai_action' => $originalDecisionForLog['action'] ?? 'N/A',
                'forced_bot_action' => $actionToExecute,
                'original_ai_decision_details' => $originalDecisionForLog,
                'bot_context_at_override' => $currentBotContext
            ]);
            // Update last decision log to show override
            $this->lastAIDecisionResult = ['status' => 'WARN_OVERRIDE', 'message' => "Bot Override: " . $overrideReason, 'original_ai_decision' => $originalDecisionForLog, 'executed_action_by_bot' => $actionToExecute];
        }

        // The action to log to DB (includes override status)
        $logActionForDB = $actionToExecute . ($overrideReason ? '_BOT_OVERRIDE' : '_AI_DIRECT');

        // --- Execute Strategy Update Suggestion (if AI provided it and it's enabled) ---
        $aiUpdateSuggestion = $originalDecisionForLog['suggested_strategy_directives_update'] ?? null;
        $allowAIUpdate = ($this->currentActiveTradeLogicSource['strategy_directives']['allow_ai_to_update_self'] ?? false) === true;

        if ($allowAIUpdate && is_array($aiUpdateSuggestion) && isset($aiUpdateSuggestion['updated_directives']) && is_array($aiUpdateSuggestion['updated_directives'])) {
            $updateReason = $aiUpdateSuggestion['reason_for_update'] ?? 'AI suggested update (no specific reason given)';
            $this->logger->info("AI suggested strategy directives update. Attempting to apply.", ['reason' => $updateReason]);
            $updateSuccess = $this->updateTradeLogicSourceInDb($aiUpdateSuggestion['updated_directives'], $updateReason, $this->currentDataForAIForDBLog);
            if ($updateSuccess) {
                // Prepend to last AIDecisionResult message for visibility in current cycle
                $this->lastAIDecisionResult['message'] = "[Strategy Updated] " . ($this->lastAIDecisionResult['message'] ?? '');
                $this->lastAIDecisionResult['strategy_update_status'] = 'OK';
            } else {
                $this->lastAIDecisionResult['message'] = "[Strategy Update Failed] " . ($this->lastAIDecisionResult['message'] ?? '');
                $this->lastAIDecisionResult['strategy_update_status'] = 'FAILED';
            }
        }

        // --- Execute Final (Potentially Overridden) Trading Action ---
        $currentDirectives = $this->currentActiveTradeLogicSource['strategy_directives'] ?? $this->getDefaultStrategyDirectives(); // Ensure latest directives
        switch ($actionToExecute) {
            case 'OPEN_POSITION':
                // Use directives for default parameters if AI omits or for validation guidance
                $this->aiSuggestedLeverage = (int)($decision['leverage'] ?? ($currentDirectives['leverage_preference']['preferred'] ?? $this->defaultLeverage));
                $this->aiSuggestedSide = strtoupper($decision['side'] ?? '');
                $this->aiSuggestedEntryPrice = (float)($decision['entryPrice'] ?? 0);
                $this->aiSuggestedQuantity = (float)($decision['quantity'] ?? 0); // AI should calculate this based on margin target
                $this->aiSuggestedSlPrice = (float)($decision['stopLossPrice'] ?? 0); // AI must calculate this for target risk
                $this->aiSuggestedTpPrice = (float)($decision['takeProfitPrice'] ?? 0); // AI calculates based on R/R
                $tradeRationale = trim($decision['trade_rationale'] ?? '');

                $this->logger->info("AI requests OPEN_POSITION. Validating parameters...", [
                    'leverage' => $this->aiSuggestedLeverage, 'side' => $this->aiSuggestedSide,
                    'entry' => $this->aiSuggestedEntryPrice, 'quantity' => $this->aiSuggestedQuantity,
                    'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice,
                    'rationale_length' => strlen($tradeRationale)
                ]);

                // Perform Validation (example validation, expand as needed)
                $validationError = null;
                if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) $validationError = "Invalid side: '{$this->aiSuggestedSide}'.";
                elseif ($this->aiSuggestedLeverage <= 0 || $this->aiSuggestedLeverage > 125) $validationError = "Invalid leverage: {$this->aiSuggestedLeverage}.";
                elseif ($this->aiSuggestedEntryPrice <= 0) $validationError = "Invalid entryPrice <= 0.";
                elseif ($this->aiSuggestedQuantity <= 0) $validationError = "Invalid quantity <= 0.";
                elseif ($this->aiSuggestedSlPrice <= 0) $validationError = "Invalid stopLossPrice <= 0.";
                elseif ($this->aiSuggestedTpPrice <= 0) $validationError = "Invalid takeProfitPrice <= 0.";
                elseif (empty($tradeRationale) || strlen($tradeRationale) < 10) $validationError = "Missing or too short 'trade_rationale'.";
                // Add more complex validation based on directives, e.g., if AI's SL/TP ratios don't match target_risk_per_trade_usdt or default_rr_ratio from directives

                if ($validationError) {
                    $this->logger->error("AI OPEN_POSITION parameters FAILED BOT VALIDATION: {$validationError}", ['failed_decision_params' => $decision]);
                    $this->lastAIDecisionResult = ['status' => 'ERROR_VALIDATION', 'message' => "AI OPEN_POSITION params rejected by bot: " . $validationError, 'invalid_decision_by_ai' => $decision];
                    $logActionForDB = 'ERROR_VALIDATION_OPEN_POS';
                } else {
                    $this->attemptOpenPosition(); // This function handles its own locking and logging
                }
                break;

            case 'CLOSE_POSITION':
                $reason = $decision['reason'] ?? ($overrideReason ?: 'AI request');
                $this->logger->info("Bot to execute CLOSE_POSITION request.", ['reason' => $reason]);
                $this->attemptClosePositionByAI(); // This function handles its own locking and logging
                break;

            case 'HOLD_POSITION':
                 // Update last decision result only if not overridden (override log already happened)
                 if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'OK_ACTION', 'message' => 'Bot will HOLD as per AI.', 'executed_decision' => $originalDecisionForLog];
                 }
                 $reason = $decision['reason'] ?? ($overrideReason ?: 'AI request');
                 $this->logger->info("Bot action: HOLD / Wait.", ['reason' => $reason, 'final_decision_log_context' => $this->lastAIDecisionResult]);
                 break;

            case 'DO_NOTHING':
                 // Update last decision result only if not overridden
                 if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'OK_ACTION', 'message' => 'Bot will DO_NOTHING as per AI.', 'executed_decision' => $originalDecisionForLog];
                 }
                 $reason = $decision['reason'] ?? ($overrideReason ?: 'AI request');
                 $this->logger->info("Bot action: DO_NOTHING.", ['reason' => $reason, 'final_decision_log_context' => $this->lastAIDecisionResult]);
                 break;

            default: // Unknown action from AI
                if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'ERROR_ACTION', 'message' => "Bot received unknown AI action '{$actionToExecute}'. No action taken.", 'unknown_decision_by_ai' => $originalDecisionForLog];
                }
                $this->logger->error("Unknown or unhandled AI action received by bot.", ['action_received' => $actionToExecute, 'full_ai_decision' => $originalDecisionForLog, 'final_decision_log_context' => $this->lastAIDecisionResult]);
                $logActionForDB = 'ERROR_UNKNOWN_AI_ACTION';
        }

        // Log the final outcome of this AI interaction to DB
        $this->logAIInteractionToDb(
            $logActionForDB, $originalDecisionForLog, $this->lastAIDecisionResult,
            $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog
        );
    }
}


// --- Script Execution ---
// Get bot_config_id from command line arguments
$botConfigId = (int)($argv[1] ?? 0);

if ($botConfigId === 0) {
    echo "Usage: php p2profitdba.php <bot_config_id>\n";
    // Attempt to find an active config if no ID is provided, for simpler local dev/testing
    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $stmt = $pdo->query("SELECT id FROM bot_configurations WHERE is_active = TRUE LIMIT 1");
        $foundId = $stmt->fetchColumn();
        if ($foundId) {
            $botConfigId = (int)$foundId;
            echo "No config ID provided. Found active config ID: {$botConfigId}. Using this.\n";
        } else {
            die("Error: No bot_config_id provided and no active configuration found in database.\n");
        }
    } catch (\PDOException $e) {
        die("Error: Failed to connect to DB to find active config: " . $e->getMessage() . "\n");
    }
}

try {
    $bot = new AiTradingBotFutures(
        botConfigId: $botConfigId,
        binanceApiKey: $binanceApiKey,
        binanceApiSecret: $binanceApiSecret,
        geminiApiKey: $geminiApiKey,
        geminiModelName: $geminiModelName,
        dbHost: $dbHost,
        dbPort: $dbPort,
        dbName: $dbName,
        dbUser: $dbUser,
        dbPassword: $dbPassword
    );

    $bot->run();
} catch (\Throwable $e) {
    // This catches any uncaught exceptions from the bot's runtime
    error_log("CRITICAL: Bot stopped due to unhandled exception for config ID {$botConfigId}: " . $e->getMessage());
    // Attempt to update status to 'error' if PDO is still available
    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $stmt = $pdo->prepare("
            INSERT INTO bot_runtime_status (bot_config_id, status, last_heartbeat, error_message, process_id)
            VALUES (:bot_config_id, 'error', NOW(), :error_message, :process_id)
            ON DUPLICATE KEY UPDATE
                status = 'error',
                last_heartbeat = NOW(),
                error_message = VALUES(error_message),
                process_id = VALUES(process_id)
        ");
        $stmt->execute([
            ':bot_config_id' => $botConfigId,
            ':error_message' => "Unhandled exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine(),
            ':process_id' => getmypid()
        ]);
        error_log("Bot runtime status updated to 'error' in DB for config ID {$botConfigId}.");
    } catch (\PDOException $db_e) {
        error_log("Further DB error when trying to log bot shutdown error: " . $db_e->getMessage());
    }
    exit(1); // Exit with a non-zero status code to indicate an error
}

?>
