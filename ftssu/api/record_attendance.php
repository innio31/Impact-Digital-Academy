<?php
// CORS headers - MUST be first
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db_connect.php';

// Get the raw input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$member_id = isset($data['member_id']) ? (int)$data['member_id'] : 0;
$service_id = isset($data['service_id']) ? (int)$data['service_id'] : 0;
$attendance_method = isset($data['attendance_method']) ? $conn->real_escape_string($data['attendance_method']) : 'manual_entry';
$taken_by = isset($data['taken_by']) ? (int)$data['taken_by'] : 0;

if (!$member_id || !$service_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Member ID and Service ID are required',
        'received' => ['member_id' => $member_id, 'service_id' => $service_id]
    ]);
    exit;
}

// Check if attendance table exists
$table_check = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($table_check->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Attendance table does not exist']);
    exit;
}

// Check if service exists
$service_check = $conn->query("SELECT id, service_name, is_active FROM services WHERE id = $service_id");
if (!$service_check) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

if ($service_check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Service does not exist']);
    exit;
}

$service = $service_check->fetch_assoc();
if ($service['is_active'] != 1) {
    echo json_encode(['success' => false, 'error' => 'Service is not active']);
    exit;
}

// Check if already checked in
$check = $conn->query("SELECT id FROM attendance WHERE member_id = $member_id AND service_id = $service_id");
if ($check && $check->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'Member already checked in for this service']);
    exit;
}

// Insert attendance
$sql = "INSERT INTO attendance (member_id, service_id, attendance_method, attendance_time) 
        VALUES ($member_id, $service_id, '$attendance_method', NOW())";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'Attendance recorded successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}

$conn->close();
