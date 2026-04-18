<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
$is_pinned = isset($data['is_pinned']) ? (int)$data['is_pinned'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Announcement ID required']);
    exit;
}

$sql = "UPDATE announcements SET is_pinned = $is_pinned WHERE id = $id";
if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
$conn->close();
