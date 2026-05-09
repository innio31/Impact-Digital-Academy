<?php
// api/includes/config.php - API Configuration
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'impactdi_school_portal');
define('DB_PASS', 'Innioluwa@1995');
define('DB_NAME', 'impactdi_school_portal');

// School identification
define('SCHOOL_ID', 1);
define('SCHOOL_CODE', 'GOS001');

// JWT Secret (for authentication tokens)
define('JWT_SECRET', 'your-secret-key-change-this');

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// Helper function to get JSON input
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

// Helper function to verify authentication
function verifyAuth() {
    $headers = getallheaders();
    $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    
    if (empty($token)) {
        sendResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
    
    // Simple token verification (you can expand this)
    $payload = json_decode(base64_decode(explode('.', $token)[1] ?? ''), true);
    
    if (!$payload || !isset($payload['user_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid token'], 401);
    }
    
    return $payload;
}
?>