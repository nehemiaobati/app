<?php
// src/process_manager.php
// WARNING: This is a simplified process manager using exec().
// For production, use systemd or Supervisord for robust process management.

require_once __DIR__ . '/config.php';

$command = $argv[1] ?? 'help';
$botScriptPath = BOT_SCRIPT_PATH; // Defined in config.php

function findBotProcessId(string $scriptPath): ?int
{
    // Grep for the process, exclude grep itself
    $output = [];
    exec("pgrep -f " . escapeshellarg($scriptPath), $output);
    return empty($output) ? null : (int)$output[0]; // Assuming only one instance
}

switch ($command) {
    case 'start':
        $pid = findBotProcessId($botScriptPath);
        if ($pid) {
            echo "Bot is already running with PID: {$pid}\n";
            exit(1);
        }
        // Use nohup to detach and redirect output to /dev/null
        // This makes the script run in the background after the web server request finishes
        $startCmd = "nohup php " . escapeshellarg($botScriptPath) . " > /dev/null 2>&1 & echo $!\n";
        exec($startCmd, $output, $returnVar);
        if ($returnVar === 0 && !empty($output[0])) {
            $newPid = (int)$output[0];
            echo "Bot started with PID: {$newPid}\n";
            exit(0);
        } else {
            echo "Failed to start bot. Return code: {$returnVar}. Output: " . implode("\n", $output) . "\n";
            exit(1);
        }
    case 'stop':
        $pid = findBotProcessId($botScriptPath);
        if (!$pid) {
            echo "Bot is not running.\n";
            exit(1);
        }
        // Send SIGTERM for graceful shutdown
        exec("kill {$pid}", $output, $returnVar);
        if ($returnVar === 0) {
            echo "Stop command sent to PID: {$pid}\n";
            exit(0);
        } else {
            echo "Failed to send stop command to PID: {$pid}. Return code: {$returnVar}. Output: " . implode("\n", $output) . "\n";
            exit(1);
        }
    case 'status':
        $pid = findBotProcessId($botScriptPath);
        if ($pid) {
            echo "Bot is running with PID: {$pid}\n";
            exit(0);
        } else {
            echo "Bot is not running.\n";
            exit(1);
        }
    case 'help':
    default:
        echo "Usage: php process_manager.php [start|stop|status]\n";
        exit(0);
}