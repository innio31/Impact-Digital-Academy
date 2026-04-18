<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$service_name = isset($data['service_name']) ? $conn->real_escape_string($data['service_name']) : '';
$service_date = isset($data['service_date']) ? $conn->real_escape_string($data['service_date']) : '';
$start_time = isset($data['start_time']) ? $conn->real_escape_string($data['start_time']) : '';
$end_time = isset($data['end_time']) ? $conn->real_escape_string($data['end_time']) : '';
$created_by = isset($data['created_by']) ? $conn->real_escape_string($data['created_by']) : '';

if (!$service_name || !$service_date || !$start_time) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Generate QR code data
$qr_data = json_encode([
    'service_id' => 'new',
    'service_name' => $service_name,
    'service_date' => $service_date
]);

$sql = "INSERT INTO services (service_name, service_date, start_time, end_time, qr_code, created_by, is_active, created_at) 
        VALUES ('$service_name', '$service_date', '$start_time', '$end_time', '$qr_data', '$created_by', 1, NOW())";

if ($conn->query($sql)) {
    $new_id = $conn->insert_id;
    // Update QR code with actual ID
    $updated_qr = json_encode([
        'service_id' => $new_id,
        'service_name' => $service_name,
        'service_date' => $service_date
    ]);
    $conn->query("UPDATE services SET qr_code = '$updated_qr' WHERE id = $new_id");

    echo json_encode(['success' => true, 'id' => $new_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
