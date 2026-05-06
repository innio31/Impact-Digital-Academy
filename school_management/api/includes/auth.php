<?php
// api/includes/cors.php
header('Access-Control-Allow-Origin: https://portal.mightyschoolforvalours.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
require_once __DIR__ . '/../../config/database.php';

function validateToken()
{
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

    if (empty($auth_header)) {
        $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    }

    // Remove 'Bearer ' prefix
    $token = str_replace('Bearer ', '', $auth_header);
    $token = str_replace('bearer ', '', $token);

    if (empty($token)) {
        sendError("Unauthorized - No token provided", 401);
        return false;
    }

    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT user_id FROM auth_tokens WHERE token = :token AND expires_at > NOW()";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendError("Invalid or expired token", 401);
        return false;
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['user_id'];
}
