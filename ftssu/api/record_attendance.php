<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$member_id = isset($data['member_id']) ? (int)$data['member_id'] : 0;
$service_id = isset($data['service_id']) ? (int)$data['service_id'] : 0;
$attendance_method = isset($data['attendance_method']) ? $conn->real_escape_string($data['attendance_method']) : 'self_scan';

if (!$member_id || !$service_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Check if service exists and is active
$service_check = $conn->query("SELECT id, service_name FROM services WHERE id = $service_id AND is_active = 1");
if ($service_check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Service is not active or does not exist']);
    exit;
}

// Check if already checked in
$check = $conn->query("SELECT id FROM attendance WHERE member_id = $member_id AND service_id = $service_id");
if ($check->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'Already checked in for this service']);
    exit;
}

$sql = "INSERT INTO attendance (member_id, service_id, attendance_method, attendance_time) 
        VALUES ($member_id, $service_id, '$attendance_method', NOW())";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'Check-in successful']);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
$conn->close();
