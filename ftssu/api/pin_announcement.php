<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

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

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$id = $data['id'] ?? 0;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Announcement ID required']);
    exit();
}

$stmt = $conn->prepare("UPDATE announcements SET is_pinned = ? WHERE id = ?");
$stmt->bind_param("ii", $is_pinned, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Pin status updated']);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
