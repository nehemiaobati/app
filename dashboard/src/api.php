<?php
// src/api.php
session_start();

// Set CORS headers for local development (adjust for production)
header("Access-Control-Allow-Origin: *"); // Be specific with your frontend origin in production
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/BotController.php';
require_once __DIR__ . '/controllers/DataController.php';

// Parse the request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Assuming base path is `/api/`
$path = str_replace('/api/', '', $path);
$pathParts = explode('/', trim($path, '/'));
$endpoint = $pathParts[0] ?? null;
$action = $pathParts[1] ?? null; // For nested routes like /bot/status

$method = $_SERVER['REQUEST_METHOD'];

// Public endpoints (no authentication required)
$publicEndpoints = ['login', 'logout'];

// Authenticate all other requests
if (!in_array($endpoint, $publicEndpoints)) {
    Auth::requireLogin();
}

try {
    switch ($endpoint) {
        case 'login':
            if ($method === 'POST') AuthController::login();
            else http_response_code(405); // Method Not Allowed
            break;
        case 'logout':
            if ($method === 'POST') AuthController::logout();
            else http_response_code(405);
            break;
        case 'bot':
            switch ($action) {
                case 'status':
                    if ($method === 'GET') BotController::getStatus();
                    else http_response_code(405);
                    break;
                case 'config':
                    if ($method === 'GET') BotController::getConfig();
                    elseif ($method === 'PUT') BotController::updateConfig();
                    else http_response_code(405);
                    break;
                case 'start':
                    if ($method === 'POST') BotController::startBot();
                    else http_response_code(405);
                    break;
                case 'stop':
                    if ($method === 'POST') BotController::stopBot();
                    else http_response_code(405);
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Bot endpoint not found.']);
                    break;
            }
            break;
        case 'data':
            switch ($action) {
                case 'orders':
                    if ($method === 'GET') DataController::getRecentOrders();
                    else http_response_code(405);
                    break;
                case 'ai_logs':
                    if ($method === 'GET') DataController::getAiLogs();
                    else http_response_code(405);
                    break;
                case 'performance':
                    if ($method === 'GET') DataController::getPerformanceSummary();
                    else http_response_code(405);
                    break;
                case 'position':
                    if ($method === 'GET') DataController::getCurrentPosition();
                    else http_response_code(405);
                    break;
                // Add other data endpoints like klines if needed, or fetch directly in JS
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Data endpoint not found.']);
                    break;
            }
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'API endpoint not found.']);
            break;
    }
} catch (\Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred.', 'message' => $e->getMessage()]);
}