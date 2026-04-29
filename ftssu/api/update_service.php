<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your database configuration
require_once 'database.php';

// Use the global connection
global $conn;

// Check if connection exists
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Get input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Service ID required']);
    exit();
}

try {
    $service_id = $data['id'];

    // Check if we're just updating status (closing service)
    if (isset($data['is_active']) && !isset($data['service_name'])) {
        $is_active = $data['is_active'];
        $stmt = $conn->prepare("UPDATE services SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $is_active, $service_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Service status updated']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update service: ' . $stmt->error]);
        }
        $stmt->close();
    }
    // Full service update
    else if (isset($data['service_name'])) {
        $service_name = $data['service_name'];
        $service_date = $data['service_date'];
        $start_time = $data['start_time'];
        $end_time = $data['end_time'];
        $is_active = isset($data['is_active']) ? $data['is_active'] : 1;

        $stmt = $conn->prepare("UPDATE services SET service_name = ?, service_date = ?, start_time = ?, end_time = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("ssssii", $service_name, $service_date, $start_time, $end_time, $is_active, $service_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Service updated']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update service: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
}

$conn->close();
