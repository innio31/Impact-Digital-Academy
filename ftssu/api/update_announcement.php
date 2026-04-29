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

$log = date('Y-m-d H:i:s') . " - Update received: " . $raw_input . "\n";
file_put_contents('announcement_debug.log', $log, FILE_APPEND);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
$title = isset($data['title']) ? trim($data['title']) : '';
$content = isset($data['content']) ? trim($data['content']) : '';
$target_command = isset($data['target_command']) && $data['target_command'] !== '' ? trim($data['target_command']) : null;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (!$id || empty($title) || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'ID, title, and content required']);
    exit();
}

$sql = "UPDATE announcements SET 
        title = '$title', 
        content = '$content', 
        target_command = " . ($target_command ? "'$target_command'" : "NULL") . ", 
        is_pinned = $is_pinned 
        WHERE id = $id";

file_put_contents('announcement_debug.log', "Update SQL: $sql\n", FILE_APPEND);

if ($conn->query($sql) === TRUE) {
    echo json_encode(['success' => true, 'message' => 'Announcement updated']);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
