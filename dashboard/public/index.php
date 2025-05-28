<?php
// dashboard/public/index.php

$requestUri = $_SERVER['REQUEST_URI'];

// API routing
if (strpos($requestUri, '/api/') === 0) {
    include __DIR__ . '/../src/api.php'; // Include the API
    exit();
}

// Serve static files or default content
$filePath = __DIR__ . $requestUri;

if (file_exists($filePath) && !is_dir($filePath)) {
    // Serve the file directly
    return false; // Let Apache handle static files
} else {
    // Serve index.html or a default page
    include __DIR__ . '/index.html'; // Or echo "Welcome";
    exit();
}
