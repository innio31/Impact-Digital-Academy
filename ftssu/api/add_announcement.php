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

$stmt = $conn->prepare("INSERT INTO announcements (title, content, author, author_role, target_command, is_pinned, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("sssssi", $title, $content, $author, $author_role, $target_command, $is_pinned);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
