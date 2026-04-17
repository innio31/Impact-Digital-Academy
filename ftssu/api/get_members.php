<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

include 'db_connect.php';

$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, gender, phone_number, email, date_of_birth, date_joined, is_active 
        FROM members 
        ORDER BY created_at DESC";

$result = $conn->query($sql);
$members = [];

while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}

echo json_encode(['success' => true, 'members' => $members]);
$conn->close();
