<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$title = isset($data['title']) ? $conn->real_escape_string($data['title']) : '';
$content = isset($data['content']) ? $conn->real_escape_string($data['content']) : '';
$author = isset($data['author']) ? $conn->real_escape_string($data['author']) : '';
$author_role = isset($data['author_role']) ? $conn->real_escape_string($data['author_role']) : '';

if (!$title || !$content) {
    echo json_encode(['success' => false, 'error' => 'Title and content required']);
    exit;
}

$sql = "INSERT INTO announcements (title, content, author, author_role, created_at) 
        VALUES ('$title', '$content', '$author', '$author_role', NOW())";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
