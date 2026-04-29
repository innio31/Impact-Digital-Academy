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

require_once 'db_connect.php';
global $conn;

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . ($conn->connect_error ?? 'No connection')]);
    exit();
}

// Get input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log for debugging
error_log("Update announcement received: " . $input);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
$title = isset($data['title']) ? trim($data['title']) : '';
$content = isset($data['content']) ? trim($data['content']) : '';
$target_command = isset($data['target_command']) && $data['target_command'] !== '' ? $data['target_command'] : null;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (!$id || empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'ID, title, and content are required']);
    exit();
}

// Check if announcement exists
$checkStmt = $conn->prepare("SELECT id FROM announcements WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Announcement not found']);
    exit();
}
$checkStmt->close();

// Update the announcement
$stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, target_command = ?, is_pinned = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("sssii", $title, $content, $target_command, $is_pinned, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Announcement updated successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
