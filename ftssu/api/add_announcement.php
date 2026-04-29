<?php
// Turn on error reporting
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

// Include database connection
require_once 'db_connect.php';

// Get the raw input
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Create a log file to see what's happening
$log = date('Y-m-d H:i:s') . " - Raw input: " . $raw_input . "\n";
file_put_contents('announcement_debug.log', $log, FILE_APPEND);

if (!$data) {
    $error = ['success' => false, 'error' => 'No data received', 'raw' => $raw_input];
    file_put_contents('announcement_debug.log', json_encode($error) . "\n", FILE_APPEND);
    echo json_encode($error);
    exit();
}

// Extract data
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

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'announcements'");
if ($table_check->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'announcements table does not exist']);
    exit();
}

// Insert using simple query first to test
$sql = "INSERT INTO announcements (title, content, author, author_role, target_command, is_pinned, created_at) 
        VALUES ('$title', '$content', '$author', '$author_role', " . ($target_command ? "'$target_command'" : "NULL") . ", $is_pinned, NOW())";

file_put_contents('announcement_debug.log', "SQL: $sql\n", FILE_APPEND);

if ($conn->query($sql) === TRUE) {
    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
