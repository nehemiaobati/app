-- app/dashboard/setup_db.sql

-- Create users table (assuming it exists for login to work)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create bot_configurations table
CREATE TABLE IF NOT EXISTS bot_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    symbol VARCHAR(20) NOT NULL,
    kline_interval VARCHAR(10) NOT NULL DEFAULT '1m',
    margin_asset VARCHAR(10) NOT NULL DEFAULT 'USDT',
    default_leverage INT NOT NULL DEFAULT 1,
    order_check_interval_seconds INT NOT NULL DEFAULT 5,
    ai_update_interval_seconds INT NOT NULL DEFAULT 60,
    use_testnet BOOLEAN NOT NULL DEFAULT FALSE,
    initial_margin_target_usdt DECIMAL(18, 8) NOT NULL DEFAULT 10.00,
    take_profit_target_usdt DECIMAL(18, 8) NOT NULL DEFAULT 0.50,
    pending_entry_order_cancel_timeout_seconds INT NOT NULL DEFAULT 300,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create bot_runtime_status table
CREATE TABLE IF NOT EXISTS bot_runtime_status (
    bot_config_id INT PRIMARY KEY,
    status VARCHAR(50) NOT NULL DEFAULT 'stopped',
    process_id INT NULL,
    last_heartbeat TIMESTAMP NULL,
    error_message TEXT NULL,
    current_position_details_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (bot_config_id) REFERENCES bot_configurations(id) ON DELETE CASCADE
);

-- Create orders_log table
CREATE TABLE IF NOT EXISTS orders_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_config_id INT NOT NULL,
    order_id_binance VARCHAR(255) NULL,
    bot_event_timestamp_utc DATETIME NOT NULL,
    symbol VARCHAR(20) NOT NULL,
    side VARCHAR(10) NOT NULL,
    status_reason VARCHAR(255) NULL,
    price_point DECIMAL(18, 8) NULL,
    quantity_involved DECIMAL(18, 8) NULL,
    realized_pnl_usdt DECIMAL(18, 8) NULL,
    FOREIGN KEY (bot_config_id) REFERENCES bot_configurations(id) ON DELETE CASCADE
);

-- Create ai_interactions_log table
CREATE TABLE IF NOT EXISTS ai_interactions_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_config_id INT NOT NULL,
    log_timestamp_utc DATETIME NOT NULL,
    executed_action_by_bot TEXT NOT NULL,
    ai_decision_params_json JSON NULL,
    bot_feedback_json JSON NULL,
    full_data_for_ai_json JSON NULL,
    raw_ai_response_json JSON NULL,
    FOREIGN KEY (bot_config_id) REFERENCES bot_configurations(id) ON DELETE CASCADE
);

-- Insert a default user (for testing, remove in production)
INSERT IGNORE INTO users (username, password_hash) VALUES ('testuser', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/lK.'); -- password is 'password'

-- Insert a default bot configuration (for testing)
INSERT IGNORE INTO bot_configurations (id, name, symbol, is_active) VALUES (1, 'Default Bot Config', 'BTCUSDT', TRUE);

-- Insert a default runtime status for the default bot config
INSERT IGNORE INTO bot_runtime_status (bot_config_id, status, current_position_details_json) VALUES (1, 'stopped', NULL);
