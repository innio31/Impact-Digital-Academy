<?php
// modules/instructor/notifications/mark_as_read.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$conn = getDBConnection();
$instructor_id = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
$notification_id = $data['notification_id'] ?? null;

if ($notification_id) {
    // Mark specific notification as read
    $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $instructor_id);
    $success = $stmt->execute();
    $stmt->close();
} else {
    // Mark all notifications as read
    $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $success = $stmt->execute();
    $stmt->close();
}

$conn->close();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
