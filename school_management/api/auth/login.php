<?php
// api/login.php
require_once __DIR__ . '/../includes/cors.php';  // Add this at the very top
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/response.php';

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

if (!password_verify($password, $user['password_hash'])) {
    sendError("Invalid credentials", 401);
}

// Generate token
$token = bin2hex(random_bytes(64)) . '_' . time();

// Create auth_tokens table if not exists
$create_table = "CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int(10) UNSIGNED NOT NULL,
    `token` varchar(255) NOT NULL,
    `expires_at` timestamp NOT NULL,
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$db->exec($create_table);

// Delete old tokens for this user
$delete_query = "DELETE FROM auth_tokens WHERE user_id = :user_id AND expires_at < NOW()";
$delete_stmt = $db->prepare($delete_query);
$delete_stmt->bindParam(':user_id', $user['id']);
$delete_stmt->execute();

// Store new token
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
