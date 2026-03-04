<?php
// Database configuration
define('DB_HOST', 'localhost');  // or your HostAfrica host
define('DB_NAME', 'central_cbt');
define('DB_USER', 'impactdi_central_cbt');
define('DB_PASS', 'ZaZQ6cRqXwVkWaUyZ5KT');

// API settings
define('MAX_QUESTIONS_PER_BATCH', 500);
define('API_VERSION', '1.0');

// Error reporting (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Log error and return JSON response
    error_log("Database connection failed: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}
