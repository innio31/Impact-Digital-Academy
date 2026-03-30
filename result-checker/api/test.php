<?php
// api/test.php - Debug script to test API availability
header('Content-Type: application/json');

// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

$response = [
    'status' => 'ok',
    'message' => 'API endpoint is reachable',
    'php_version' => phpversion(),
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['SERVER_NAME']
];

// Try to include config and test database connection
try {
    require_once '../config/config.php';

    if (class_exists('Database')) {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        $response['database'] = 'Connected successfully';
        $response['database_version'] = $conn->getAttribute(PDO::ATTR_SERVER_VERSION);
    } else {
        $response['database'] = 'Database class not found';
    }
} catch (Exception $e) {
    $response['database'] = 'Error: ' . $e->getMessage();
    $response['status'] = 'warning';
}

echo json_encode($response, JSON_PRETTY_PRINT);
