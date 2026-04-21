<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

class Database
{
    private $host = "localhost";
    private $db_name = "impactdi_school_management";
    private $username = "impactdi_school_management";
    private $password = "innioluwa1995";
    public $conn;

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch (PDOException $exception) {
            echo json_encode(["error" => "Connection error: " . $exception->getMessage()]);
            exit();
        }
        return $this->conn;
    }
}

// Helper function to send JSON response
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data,
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    exit();
}

// Helper function to validate JWT token
function validateToken()
{
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        sendResponse(false, "Authorization token required", null, 401);
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);

    // Simple token validation (in production, use proper JWT library)
    $payload = base64_decode($token);
    $data = json_decode($payload, true);

    if (!$data || !isset($data['user_id']) || !isset($data['expires'])) {
        sendResponse(false, "Invalid token", null, 401);
    }

    if ($data['expires'] < time()) {
        sendResponse(false, "Token expired", null, 401);
    }

    return $data;
}
