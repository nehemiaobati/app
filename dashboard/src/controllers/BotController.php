<?php
// src/controllers/BotController.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php'; // For BOT_SCRIPT_PATH

class BotController
{
    private static function getActiveBotConfigId(): ?int
    {
        try {
            $pdo = get_pdo();
            $stmt = $pdo->query("SELECT id FROM bot_configurations WHERE is_active = TRUE LIMIT 1");
            $config = $stmt->fetchColumn();
            return $config ?: null;
        } catch (\PDOException $e) {
            error_log("Error fetching active bot config ID: " . $e->getMessage());
            return null;
        }
    }

    public static function getStatus(): void
    {
        $configId = self::getActiveBotConfigId();
        if (!$configId) {
            http_response_code(404);
            echo json_encode(['status' => 'inactive', 'message' => 'No active bot configuration found.']);
            return;
        }

        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT status, last_heartbeat, process_id, error_message FROM bot_runtime_status WHERE bot_config_id = :config_id");
            $stmt->execute([':config_id' => $configId]);
            $status = $stmt->fetch();

            if ($status) {
                http_response_code(200);
                echo json_encode($status);
            } else {
                // If no entry in bot_runtime_status, assume it's stopped/never started
                http_response_code(200);
                echo json_encode(['status' => 'stopped', 'message' => 'Bot status not yet recorded or bot not running.']);
            }
        } catch (\PDOException $e) {
            error_log("Error fetching bot status: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching bot status.']);
        }
    }

    public static function getConfig(): void
    {
        $configId = self::getActiveBotConfigId();
        if (!$configId) {
            http_response_code(404);
            echo json_encode(['error' => 'No active bot configuration found.']);
            return;
        }

        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT * FROM bot_configurations WHERE id = :config_id");
            $stmt->execute([':config_id' => $configId]);
            $config = $stmt->fetch();

            if ($config) {
                http_response_code(200);
                echo json_encode($config);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Bot configuration not found.']);
            }
        } catch (\PDOException $e) {
            error_log("Error fetching bot config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error fetching bot configuration.']);
        }
    }

    public static function updateConfig(): void
    {
        $configId = self::getActiveBotConfigId();
        if (!$configId) {
            http_response_code(404);
            echo json_encode(['error' => 'No active bot configuration found to update.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // Basic validation - expand as needed
        $allowedFields = [
            'name', 'symbol', 'kline_interval', 'margin_asset',
            'default_leverage', 'order_check_interval_seconds', 'ai_update_interval_seconds',
            'initial_margin_target_usdt', 'take_profit_target_usdt', 'pending_entry_order_cancel_timeout_seconds',
            'use_testnet'
        ];
        $updateFields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $input[$field];
            }
        }

        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid fields provided for update.']);
            return;
        }

        $params[':config_id'] = $configId;

        try {
            $pdo = get_pdo();
            $sql = "UPDATE bot_configurations SET " . implode(', ', $updateFields) . " WHERE id = :config_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode(['message' => 'Bot configuration updated successfully. Restart bot for changes to take effect.']);
        } catch (\PDOException $e) {
            error_log("Error updating bot config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error updating bot configuration.']);
        }
    }

    public static function startBot(): void
    {
        // IMPORTANT: In a production environment, you should use a proper process manager like systemd, Supervisord,
        // or a similar service to manage long-running PHP scripts, rather than directly using exec().
        // This is a simplified example for demonstration.
        $command = "php " . escapeshellarg(BOT_SCRIPT_PATH) . " > /dev/null 2>&1 &"; // Runs in background

        // First, ensure status is "stopped" or "error" or not existing to prevent multiple starts
        $configId = self::getActiveBotConfigId();
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'No active bot configuration to start.']);
            return;
        }

        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT status FROM bot_runtime_status WHERE bot_config_id = :config_id");
        $stmt->execute([':config_id' => $configId]);
        $currentStatus = $stmt->fetchColumn();

        if ($currentStatus === 'running') {
            http_response_code(409); // Conflict
            echo json_encode(['message' => 'Bot is already running.']);
            return;
        }

        exec($command, $output, $returnVar);

        if ($returnVar === 0) {
            // Optimistically update status, actual status will be set by bot heartbeat
            $stmt = $pdo->prepare("INSERT INTO bot_runtime_status (bot_config_id, status, last_heartbeat) VALUES (:config_id, 'initializing', NOW()) ON DUPLICATE KEY UPDATE status = 'initializing', last_heartbeat = NOW()");
            $stmt->execute([':config_id' => $configId]);

            http_response_code(200);
            echo json_encode(['message' => 'Bot start command sent. Check status for confirmation.']);
        } else {
            error_log("Bot start command failed. Command: {$command}. Return: {$returnVar}. Output: " . implode("\n", $output));
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send bot start command.']);
        }
    }

    public static function stopBot(): void
    {
        // IMPORTANT: See notes in startBot() regarding exec() and process management.
        // This example sends a kill signal based on PID. Real systems handle this better.
        $configId = self::getActiveBotConfigId();
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'No active bot configuration to stop.']);
            return;
        }

        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT process_id, status FROM bot_runtime_status WHERE bot_config_id = :config_id");
            $stmt->execute([':config_id' => $configId]);
            $statusData = $stmt->fetch();

            if (!$statusData || $statusData['status'] !== 'running' || !$statusData['process_id']) {
                http_response_code(409); // Conflict
                echo json_encode(['message' => 'Bot is not running or no PID found.']);
                return;
            }

            $pid = (int)$statusData['process_id'];
            $command = "kill {$pid}"; // Sends SIGTERM (graceful shutdown)

            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                // Optimistically update status. Bot should set 'stopped' on graceful exit.
                $stmt = $pdo->prepare("UPDATE bot_runtime_status SET status = 'shutdown', process_id = NULL, error_message = NULL WHERE bot_config_id = :config_id");
                $stmt->execute([':config_id' => $configId]);

                http_response_code(200);
                echo json_encode(['message' => "Stop command sent to PID {$pid}. Check status for confirmation."]);
            } else {
                error_log("Bot stop command failed. Command: {$command}. Return: {$returnVar}. Output: " . implode("\n", $output));
                http_response_code(500);
                echo json_encode(['error' => 'Failed to send bot stop command.']);
            }
        } catch (\PDOException $e) {
            error_log("Error stopping bot: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during bot stop process.']);
        }
    }
}
