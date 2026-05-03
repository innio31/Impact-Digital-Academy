<?php
// api/auth/login.php - Debug version
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';

// Test if files are loading
echo "Debug: Files loaded successfully<br>";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email']) || !isset($data['password'])) {
    sendError("Email and password are required", 400);
}

echo "Debug: Email received: " . $data['email'] . "<br>";

// Continue with rest of code...

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email']) || !isset($data['password'])) {
    sendError("Email and password are required", 400);
}

$email = $data['email'];
$password = $data['password'];

$database = new Database();
$db = $database->getConnection();

// Check users table
$query = "SELECT id, first_name, last_name, email, password_hash, role, school_id 
          FROM users 
          WHERE email = :email AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':email', $email);
$stmt->execute();

if ($stmt->rowCount() === 0) {
    sendError("Invalid credentials", 401);
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!verifyPassword($password, $user['password_hash'])) {
    sendError("Invalid credentials", 401);
}

// Generate token (simple JWT-like token - in production use proper JWT library)
$token = bin2hex(random_bytes(64)) . '_' . time();

// Store token in database
$insert_query = "INSERT INTO auth_tokens (user_id, token, expires_at) 
                 VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 7 DAY))";
$insert_stmt = $db->prepare($insert_query);
$insert_stmt->bindParam(':user_id', $user['id']);
$insert_stmt->bindParam(':token', $token);
$insert_stmt->execute();

// Remove password hash from response
unset($user['password_hash']);

sendSuccess([
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'school_id' => $user['school_id']
    ]
], "Login successful");
