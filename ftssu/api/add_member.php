<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$id_number = $conn->real_escape_string($data['id_number'] ?? '');
$first_name = $conn->real_escape_string($data['first_name'] ?? '');
$last_name = $conn->real_escape_string($data['last_name'] ?? '');
$designation = $conn->real_escape_string($data['designation'] ?? 'Brother');
$command = $conn->real_escape_string($data['command'] ?? '');
$role = $conn->real_escape_string($data['role'] ?? 'Member');
$gender = $conn->real_escape_string($data['gender'] ?? 'Male');
$phone_number = $conn->real_escape_string($data['phone_number'] ?? '');
$email = $conn->real_escape_string($data['email'] ?? '');
$date_of_birth = $conn->real_escape_string($data['date_of_birth'] ?? '');
$date_joined = $conn->real_escape_string($data['date_joined'] ?? date('Y-m-d'));

if (!$id_number || !$first_name || !$last_name || !$command) {
    echo json_encode(['success' => false, 'error' => 'Required fields missing']);
    exit;
}

// Check if ID number already exists
$check = $conn->query("SELECT id FROM members WHERE id_number = '$id_number'");
if ($check->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'ID number already exists']);
    exit;
}

$sql = "INSERT INTO members (id_number, first_name, last_name, designation, command, role, gender, phone_number, email, date_of_birth, date_joined) 
        VALUES ('$id_number', '$first_name', '$last_name', '$designation', '$command', '$role', '$gender', '$phone_number', '$email', '$date_of_birth', '$date_joined')";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
