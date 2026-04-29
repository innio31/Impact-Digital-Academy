<?php
// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Log the request
$input = file_get_contents('php://input');
error_log("Test update received: " . $input);

// Try to require config
if (file_exists('config.php')) {
    require_once 'config.php';
    error_log("config.php found and loaded");
} else {
    error_log("config.php NOT FOUND");
    echo json_encode(['success' => false, 'error' => 'config.php not found']);
    exit();
}

global $conn;
if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'No connection'));
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$data = json_decode($input, true);

if (!$data) {
    error_log("Invalid JSON data received");
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data', 'received' => $input]);
    exit();
}

$id = $data['id'] ?? 0;
$title = $data['title'] ?? '';
$content = $data['content'] ?? '';
$target_command = $data['target_command'] ?? null;
$is_pinned = $data['is_pinned'] ?? 0;

error_log("Processing update - ID: $id, Title: $title");

if (!$id || empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Check if table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'announcements'");
if ($tableCheck->num_rows == 0) {
    error_log("announcements table does not exist");
    echo json_encode(['success' => false, 'error' => 'announcements table does not exist']);
    exit();
}

// Update the announcement
$stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, target_command = ?, is_pinned = ? WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("sssii", $title, $content, $target_command, $is_pinned, $id);

if ($stmt->execute()) {
    error_log("Update successful, affected rows: " . $stmt->affected_rows);
    echo json_encode(['success' => true, 'message' => 'Announcement updated successfully']);
} else {
    error_log("Execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
