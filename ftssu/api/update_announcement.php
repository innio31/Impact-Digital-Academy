<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connect.php';

// Use MySQLi connection
global $conn;

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . ($conn->connect_error ?? 'No connection')]);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data received']);
    exit();
}

// Debug log
error_log("Update announcement received: " . print_r($data, true));

$id = isset($data['id']) ? intval($data['id']) : 0;
$title = isset($data['title']) ? trim($data['title']) : '';
$content = isset($data['content']) ? trim($data['content']) : '';
$target_command = isset($data['target_command']) && $data['target_command'] !== '' ? $data['target_command'] : null;
$is_pinned = isset($data['is_pinned']) ? intval($data['is_pinned']) : 0;

if ($id <= 0 || empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'ID, title, and content are required']);
    exit();
}

// Check if table exists
$checkTable = $conn->query("SHOW TABLES LIKE 'announcements'");
if ($checkTable->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Announcements table does not exist']);
    exit();
}

$stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, target_command = ?, is_pinned = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("sssii", $title, $content, $target_command, $is_pinned, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Announcement updated']);
} else {
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
