<?php
// modules/shared/notifications/check_updates.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['new_notifications' => 0, 'notifications' => []]);
    exit();
}

// Get user ID
$user_id = $_SESSION['user_id'];

// Get last check time from session or default to now minus 5 minutes
$last_check = $_SESSION['last_notification_check'] ?? date('Y-m-d H:i:s', strtotime('-5 minutes'));

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['new_notifications' => 0, 'notifications' => []]);
    exit();
}

// Get new notifications since last check
$sql = "SELECT id, title, message, type, created_at 
        FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = 0 
        AND created_at > ? 
        ORDER BY created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $user_id, $last_check);
$stmt->execute();
$result = $stmt->get_result();

$new_notifications = [];
while ($row = $result->fetch_assoc()) {
    $new_notifications[] = $row;
}

$stmt->close();

// Update last check time
$_SESSION['last_notification_check'] = date('Y-m-d H:i:s');

// Close connection
$conn->close();

// Send JSON response
header('Content-Type: application/json');
echo json_encode([
    'new_notifications' => count($new_notifications),
    'notifications' => $new_notifications
]);
