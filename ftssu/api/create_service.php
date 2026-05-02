<?php

/**
 * create_service.php
 * Creates a new service. Expects service_type: Sunday | All Night | Special
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$service_type = trim($data['service_type'] ?? '');
$service_name = trim($data['service_name'] ?? '');
$service_date = trim($data['service_date'] ?? '');
$start_time   = trim($data['start_time']   ?? '');
$end_time     = trim($data['end_time']     ?? '') ?: null;
$description  = trim($data['description']  ?? '');
$is_active    = intval($data['is_active']  ?? 1);

$valid_types = ['Sunday', 'All Night', 'Special'];

if (!in_array($service_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid service type. Must be: Sunday, All Night, or Special']);
    exit;
}
if (!$service_name) {
    echo json_encode(['success' => false, 'message' => 'Service name is required']);
    exit;
}
if (!$service_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $service_date)) {
    echo json_encode(['success' => false, 'message' => 'Valid service date is required']);
    exit;
}
if (!$start_time) {
    echo json_encode(['success' => false, 'message' => 'Start time is required']);
    exit;
}

// Check if services table has service_type column; if not, add it
$colCheck = $conn->query("SHOW COLUMNS FROM services LIKE 'service_type'");
if ($colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE services ADD COLUMN `service_type` ENUM('Sunday','All Night','Special') DEFAULT 'Sunday' AFTER `service_name`");
}

$stmt = $conn->prepare("
    INSERT INTO services (service_name, service_type, service_date, start_time, end_time, description, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "ssssssi",
    $service_name,
    $service_type,
    $service_date,
    $start_time,
    $end_time,
    $description,
    $is_active
);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    $stmt->close();

    // Return the created service
    $fetchStmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $fetchStmt->bind_param("i", $new_id);
    $fetchStmt->execute();
    $service = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => 'Service created successfully',
        'service' => $service
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create service: ' . $conn->error
    ]);
}
