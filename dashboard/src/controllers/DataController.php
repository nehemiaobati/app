<?php
// src/controllers/DataController.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php'; // For Binance API URL

class DataController
{
    // Helper for making external HTTP requests (e.g., to Binance API)
    private static function makeHttpRequest(string $url, string $method = 'GET', array $headers = []): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($headers)); // Assuming headers act as POST fields
        }

        // Apply custom headers
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("HTTP request failed: " . $error);
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to decode JSON response from {$url}: " . json_last_error_msg() . " | Raw: " . substr($response, 0, 200));
            return null;
        }

        if ($httpCode >= 400) {
            error_log("HTTP request to {$url} returned status {$httpCode}: " . ($decoded['msg'] ?? 'Unknown error'));
            return null;
        }

        return $decoded;
    }

    public static function getRecentOrders(): void
    {
        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT order_id_binance, bot_event_timestamp_utc, symbol, side, status_reason, price_point, quantity_involved, realized_pnl_usdt FROM orders_log ORDER BY bot_event_timestamp_utc DESC LIMIT 10");
            $stmt->execute();
            $orders = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode($orders);
        } catch (\PDOException $e) {
            error_log("Error fetching recent orders: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching recent orders.']);
        }
    }

    public static function getAiLogs(): void
    {
        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json FROM ai_interactions_log ORDER BY log_timestamp_utc DESC LIMIT 10");
            $stmt->execute();
            $logs = $stmt->fetchAll();

            // Decode JSON fields for frontend consumption
            $formattedLogs = array_map(function ($log) {
                $log['ai_decision_params'] = json_decode($log['ai_decision_params_json'], true);
                $log['bot_feedback'] = json_decode($log['bot_feedback_json'], true);
                unset($log['ai_decision_params_json'], $log['bot_feedback_json']);
                return $log;
            }, $logs);

            http_response_code(200);
            echo json_encode($formattedLogs);
        } catch (\PDOException $e) {
            error_log("Error fetching AI logs: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching AI interaction logs.']);
        }
    }

    public static function getPerformanceSummary(): void
    {
        try {
            $pdo = get_pdo();
            // Total PnL
            $stmt = $pdo->query("
                SELECT
                    SUM(realized_pnl_usdt) AS total_pnl,
                    COUNT(CASE WHEN status_reason LIKE '%FILLED%' THEN 1 ELSE NULL END) AS total_trades,
                    SUM(CASE WHEN realized_pnl_usdt > 0 THEN 1 ELSE 0 END) AS winning_trades,
                    SUM(CASE WHEN realized_pnl_usdt < 0 THEN 1 ELSE 0 END) AS losing_trades,
                    MAX(bot_event_timestamp_utc) AS last_trade_timestamp
                FROM orders_log
            ");
            $summaryData = $stmt->fetch();

            $totalPnl = $summaryData['total_pnl'] ?? 0;
            $totalTrades = (int)($summaryData['total_trades'] ?? 0);
            $winningTrades = (int)($summaryData['winning_trades'] ?? 0);
            $losingTrades = (int)($summaryData['losing_trades'] ?? 0);
            $lastTradeTimestamp = $summaryData['last_trade_timestamp'];

            $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
            $lastTradeAgo = null;
            if ($lastTradeTimestamp) {
                $diff = (new DateTime($lastTradeTimestamp))->diff(new DateTime('now'));
                $lastTradeAgo = $diff->format('%h h %i m ago');
            }


            http_response_code(200);
            echo json_encode([
                'total_pnl' => (float)$totalPnl,
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => round($winRate, 2),
                'last_trade_ago' => $lastTradeAgo,
                // Add more performance metrics as needed (e.g., max drawdown, average PnL per trade)
            ]);
        } catch (\PDOException $e) {
            error_log("Error fetching performance summary: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching performance summary.']);
        }
    }

    public static function getCurrentPosition(): void
    {
        // This method combines current kline price with position info from DB
        try {
            $pdo = get_pdo();
            $stmt = $pdo->query("SELECT symbol FROM bot_configurations WHERE is_active = TRUE LIMIT 1");
            $tradingSymbol = $stmt->fetchColumn();
            if (!$tradingSymbol) {
                http_response_code(200); // Not an error, just no active bot/symbol
                echo json_encode(['current_position_details' => null, 'error_message' => 'No active trading symbol configured.']);
                return;
            }

            // Fetch current position details from the bot's runtime status (if bot updates this)
            // For now, we'll assume the bot is storing this in a simplified way or you'd get it directly from Binance API
            // For simplicity, let's pretend bot writes its currentPositionDetails to bot_runtime_status.error_message (bad, but for example)
            // A better way would be a dedicated 'bot_state' table or calling Binance API from here.
            // Let's call Binance API for a fresh status here for robustness, as the bot's state might be stale
            $isTestnet = false; // This would come from bot_configurations table
            $stmtTestnet = $pdo->prepare("SELECT use_testnet FROM bot_configurations WHERE is_active = TRUE AND symbol = :symbol");
            $stmtTestnet->execute([':symbol' => $tradingSymbol]);
            $isTestnet = (bool)$stmtTestnet->fetchColumn();

            $binanceApiBaseUrl = $isTestnet ? BINANCE_FUTURES_TEST_REST_API_BASE_URL : BINANCE_FUTURES_PROD_REST_API_BASE_URL;

            // Fetch current position from Binance API directly (authenticated call)
            // This requires the dashboard backend to have Binance API keys, which is generally NOT recommended
            // if the bot also has them. Better to let the bot manage its position state.
            // For this example, let's fake it or assume the bot updates a 'current_position_json' field in bot_runtime_status.
            // Since we can't query Binance with keys directly from this *public* API endpoint without exposing them,
            // I'll return a placeholder or assume data from DB.

            // Alternative: Assume bot constantly updates a 'current_position_details_json' field in bot_runtime_status
            $stmtPosition = $pdo->prepare("SELECT current_position_details_json FROM bot_runtime_status WHERE bot_config_id = (SELECT id FROM bot_configurations WHERE is_active = TRUE LIMIT 1)");
            $stmtPosition->execute();
            $positionJson = $stmtPosition->fetchColumn();
            $currentPositionDetails = $positionJson ? json_decode($positionJson, true) : null;
            if (json_last_error() !== JSON_ERROR_NONE && $positionJson) {
                error_log("Failed to decode current_position_details_json from DB: " . json_last_error_msg());
                $currentPositionDetails = null;
            }

            // Fetch current kline price for Mark Price
            $klineUrl = $binanceApiBaseUrl . "/fapi/v1/klines?symbol={$tradingSymbol}&interval=1m&limit=1";
            $klineData = self::makeHttpRequest($klineUrl);
            $currentMarketPrice = null;
            if ($klineData && isset($klineData[0][4])) { // Index 4 is Close price
                $currentMarketPrice = (float)$klineData[0][4];
            }

            // If a position exists, update its markPrice and PnL if possible
            if ($currentPositionDetails && $currentMarketPrice) {
                $currentPositionDetails['markPrice'] = $currentMarketPrice;
                // Recalculate unrealized PnL if needed, or trust bot's update.
                // Assuming Binance API is primary source for unrealizedPnl for simplicity here.
            }

            http_response_code(200);
            echo json_encode([
                'current_position_details' => $currentPositionDetails,
                'current_market_price' => $currentMarketPrice,
                'trading_symbol' => $tradingSymbol
            ]);

        } catch (\PDOException $e) {
            error_log("Error fetching current position: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching current position details.', 'details' => $e->getMessage()]);
        } catch (\Exception $e) {
            error_log("Error in getCurrentPosition: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error fetching position details.', 'details' => $e->getMessage()]);
        }
    }
}
