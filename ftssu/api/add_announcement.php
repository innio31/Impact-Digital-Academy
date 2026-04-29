<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your database connection
require_once 'db_connect.php';

// Get the raw input
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

// Extract data
$title = $data['title'] ?? '';
$content = $data['content'] ?? '';
$author = $data['author'] ?? '';
$author_role = $data['author_role'] ?? '';
$target_command = $data['target_command'] ?? null;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Title and content are required']);
    exit();
}

// Use your database connection (assuming it's $conn from db_connect.php)
global $conn;

$stmt = $conn->prepare("INSERT INTO announcements (title, content, author, author_role, target_command, is_pinned, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("sssssi", $title, $content, $author, $author_role, $target_command, $is_pinned);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
