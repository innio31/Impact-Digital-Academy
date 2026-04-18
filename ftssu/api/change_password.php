<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

include 'db_connect.php';

$data = json_decode(file_get_contents('php://input'), true);

$member_id = isset($data['member_id']) ? (int)$data['member_id'] : 0;
$current_password = isset($data['current_password']) ? $data['current_password'] : '';
$new_password = isset($data['new_password']) ? $data['new_password'] : '';

if (!$member_id) {
    echo json_encode(['success' => false, 'error' => 'Member ID required']);
    exit;
}

if (empty($current_password)) {
    echo json_encode(['success' => false, 'error' => 'Current password required']);
    exit;
}

if (empty($new_password) || strlen($new_password) < 4) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 4 characters']);
    exit;
}

// Get current hashed password
$sql = "SELECT password FROM members WHERE id = $member_id";
$result = $conn->query($sql);
$member = $result->fetch_assoc();

if (!$member) {
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit;
}

// Verify current password
$hashed_current = md5(strtolower($current_password));
if ($member['password'] !== $hashed_current) {
    echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
    exit;
}

// Update to new password
$hashed_new = md5(strtolower($new_password));
$update_sql = "UPDATE members SET password = '$hashed_new' WHERE id = $member_id";

if ($conn->query($update_sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
