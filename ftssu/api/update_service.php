<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

try {
    if (isset($data['id'])) {
        // If only status update (closing service)
        if (isset($data['is_active']) && !isset($data['service_name'])) {
            $stmt = $pdo->prepare("UPDATE services SET is_active = ? WHERE id = ?");
            $stmt->execute([$data['is_active'], $data['id']]);
            echo json_encode(['success' => true, 'message' => 'Service status updated']);
        }
        // Full update
        else if (isset($data['service_name'])) {
            $stmt = $pdo->prepare("UPDATE services SET service_name = ?, service_date = ?, start_time = ?, end_time = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $data['service_name'],
                $data['service_date'],
                $data['start_time'],
                $data['end_time'],
                $data['is_active'] ?? 1,
                $data['id']
            ]);
            echo json_encode(['success' => true, 'message' => 'Service updated']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Service ID required']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
