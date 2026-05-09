<?php
require_once 'cors.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');
define('SCHOOL_ID', 1);
define('SCHOOL_CODE', 'GOS001');

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true);
}

function verifyAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    
    if (empty($token)) {
        sendResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
    
    $payload = json_decode(base64_decode($token), true);
    if (!$payload || !isset($payload['user_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid token'], 401);
    }
    
    return $payload;
}
?>