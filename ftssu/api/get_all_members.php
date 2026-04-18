<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, phone_number 
        FROM members 
        WHERE is_active = 1
        ORDER BY first_name ASC";

$result = $conn->query($sql);
$members = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    echo json_encode(['success' => true, 'members' => $members]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
