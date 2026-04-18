<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$command = isset($_GET['command']) ? $conn->real_escape_string($_GET['command']) : '';

if (!$command) {
    echo json_encode(['success' => false, 'error' => 'Command required']);
    exit;
}

$sql = "SELECT id, id_number, first_name, last_name, designation, command, role, phone_number 
        FROM members 
        WHERE command = '$command' AND is_active = 1 
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
