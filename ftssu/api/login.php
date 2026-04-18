<?php
header('Content-Type: application/json');
include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);
$id_number = $data['id_number'];
$password = $data['password'];

// Query to check both ID number and password
$query = "SELECT * FROM members WHERE id_number = '$id_number'";
$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) > 0) {
    $member = mysqli_fetch_assoc($result);

    // Verify password (assuming passwords are stored as MD5 or hashed)
    // If using plain text (temporary):
    if ($member['password'] === $password) {
        // Remove password from response for security
        unset($member['password']);
        echo json_encode(['success' => true, 'member' => $member]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid ID Number']);
}
