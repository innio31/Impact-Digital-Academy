<?php
require_once 'cors.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);
$id_number = isset($data['id_number']) ? $conn->real_escape_string($data['id_number']) : '';
$password = isset($data['password']) ? $data['password'] : '';

if (empty($id_number)) {
    echo json_encode(['success' => false, 'message' => 'ID Number is required']);
    exit;
}

// Check if member exists
$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, gender, 
        phone_number, email, profile_picture, date_of_birth, date_joined, is_active, password
        FROM members WHERE id_number = '$id_number' AND is_active = 1";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $member = $result->fetch_assoc();
    $stored_password = $member['password'];
    $login_success = false;

    // If password is provided, verify it
    if (!empty($password)) {
        // Check if stored password is bcrypt hash (starts with $2y$)
        if (strpos($stored_password, '$2y$') === 0) {
            // BCrypt hash - use password_verify
            if (password_verify($password, $stored_password)) {
                $login_success = true;
            }
        } else {
            // Legacy MD5 hash or plain text
            $hashed_input = md5(strtolower($password));
            if ($stored_password === $hashed_input || $stored_password === $password) {
                $login_success = true;

                // Upgrade to bcrypt hash for future logins
                $new_hash = password_hash($password, PASSWORD_BCRYPT);
                $update_sql = "UPDATE members SET password = '$new_hash' WHERE id = {$member['id']}";
                $conn->query($update_sql);
            }
        }

        if (!$login_success) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid password'
            ]);
            exit;
        }
    }

    // Remove password from response
    unset($member['password']);

    echo json_encode([
        'success' => true,
        'member' => $member
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'ID Number not found or account is inactive'
    ]);
}

$conn->close();
