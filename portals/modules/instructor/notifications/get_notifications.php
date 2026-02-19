<?php
// modules/instructor/notifications/get_notifications.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$conn = getDBConnection();
$instructor_id = $_SESSION['user_id'];

// Get notifications
$notifications = [];
$sql = "SELECT n.*, 
               DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted
        FROM notifications n 
        WHERE n.user_id = ? 
        ORDER BY n.is_read ASC, n.created_at DESC 
        LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

// Get unread count
$unread_count = 0;
$sql = "SELECT COUNT(*) as count FROM notifications 
        WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$unread_count = $row['count'];
$stmt->close();

$conn->close();

header('Content-Type: application/json');
echo json_encode([
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);
