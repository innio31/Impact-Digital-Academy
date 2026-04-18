<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$id = isset($data['id']) ? (int)$data['id'] : 0;
$service_name = isset($data['service_name']) ? $conn->real_escape_string($data['service_name']) : '';
$service_date = isset($data['service_date']) ? $conn->real_escape_string($data['service_date']) : '';
$start_time = isset($data['start_time']) ? $conn->real_escape_string($data['start_time']) : '';
$end_time = isset($data['end_time']) ? $conn->real_escape_string($data['end_time']) : '';
$is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Service ID required']);
    exit;
}

$sql = "UPDATE services SET 
        service_name = '$service_name',
        service_date = '$service_date',
        start_time = '$start_time',
        end_time = '$end_time',
        is_active = $is_active
        WHERE id = $id";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
