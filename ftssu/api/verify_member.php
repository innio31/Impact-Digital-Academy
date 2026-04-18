<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

    // If password is provided, verify it
    if (!empty($password)) {
        $hashed_password = md5(strtolower($password));
        if ($member['password'] !== $hashed_password) {
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
