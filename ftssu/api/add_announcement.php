<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connect.php';

$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

$title = isset($data['title']) ? trim($data['title']) : '';
$content = isset($data['content']) ? trim($data['content']) : '';
$author = isset($data['author']) ? trim($data['author']) : '';
$author_role = isset($data['author_role']) ? trim($data['author_role']) : '';
$target_command = isset($data['target_command']) && $data['target_command'] !== '' ? trim($data['target_command']) : null;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Title and content are required']);
    exit();
}

// Use 'target_commands' instead of 'target_command'
$sql = "INSERT INTO announcements (title, content, author, author_role, target_commands, is_pinned, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssi", $title, $content, $author, $author_role, $target_command, $is_pinned);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
