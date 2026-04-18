<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

// Enable error reporting for debugging (remove in production)
error_reporting(0);

$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, gender, 
        phone_number, email, profile_picture, date_of_birth, date_joined, is_active 
        FROM members 
        WHERE is_active = 1
        ORDER BY first_name ASC";

$result = $conn->query($sql);

if (!$result) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

$members = [];

while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}

echo json_encode(['success' => true, 'members' => $members]);
$conn->close();
