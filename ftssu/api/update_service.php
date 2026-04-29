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

require_once 'config.php';
global $conn;

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

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

    // If reopening a service (setting is_active = 1)
    if (isset($data['is_active']) && $data['is_active'] == 1 && !isset($data['service_name'])) {
        // First, close all other active services
        $stmt = $conn->prepare("UPDATE services SET is_active = 0 WHERE id != ? AND is_active = 1");
        $stmt->bind_param("i", $service_id);
        $stmt->execute();
        $stmt->close();

        // Then activate the selected service
        $stmt = $conn->prepare("UPDATE services SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $service_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Service reopened and set as active']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to reopen service: ' . $stmt->error]);
        }
        $stmt->close();
    }
    // If just closing a service (setting is_active = 0)
    else if (isset($data['is_active']) && $data['is_active'] == 0 && !isset($data['service_name'])) {
        $stmt = $conn->prepare("UPDATE services SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $service_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Service closed']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to close service: ' . $stmt->error]);
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
