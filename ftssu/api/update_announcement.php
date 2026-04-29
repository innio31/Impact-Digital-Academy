<?php
// Simple update_announcement.php - no fancy stuff
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get the raw input
$raw_input = file_get_contents('php://input');

// For debugging - log to file
file_put_contents('update_log.txt', date('Y-m-d H:i:s') . " - Received: " . $raw_input . "\n", FILE_APPEND);

// Parse JSON
$data = json_decode($raw_input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received', 'raw' => $raw_input]);
    exit();
}

// Check required fields
if (!isset($data['id']) || !isset($data['title']) || !isset($data['content'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields', 'received' => array_keys($data)]);
    exit();
}

// Database connection
$host = 'localhost';
$user = 'impactdi_result-checker';
$password = 'Innioluwa@1995';
$database = 'impactdi_result-checker';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

$id = (int)$data['id'];
$title = $conn->real_escape_string($data['title']);
$content = $conn->real_escape_string($data['content']);
$target_command = isset($data['target_command']) && $data['target_command'] ? "'" . $conn->real_escape_string($data['target_command']) . "'" : "NULL";
$is_pinned = (int)($data['is_pinned'] ?? 0);

$sql = "UPDATE announcements SET title = '$title', content = '$content', target_command = $target_command, is_pinned = $is_pinned WHERE id = $id";

file_put_contents('update_log.txt', date('Y-m-d H:i:s') . " - SQL: " . $sql . "\n", FILE_APPEND);

if ($conn->query($sql) === TRUE) {
    echo json_encode(['success' => true, 'message' => 'Announcement updated']);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
