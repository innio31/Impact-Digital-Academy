<?php
// modules/shared/notifications/delete.php

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
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get user ID
$user_id = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Initialize response
$response = ['success' => false, 'message' => 'Invalid request'];

if (!empty($input['all_read'])) {
    // Delete all read notifications
    $sql = "DELETE FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL) AND is_read = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'All read notifications deleted'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete notifications'];
        }
        $stmt->close();
    }
} elseif (!empty($input['notification_ids'])) {
    // Delete multiple notifications
    $ids = array_map('intval', $input['notification_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "DELETE FROM notifications 
            WHERE id IN ($placeholders) AND (user_id = ? OR user_id IS NULL)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = str_repeat('i', count($ids)) . 'i';
        $params = array_merge($ids, [$user_id]);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Notifications deleted'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete notifications'];
        }
        $stmt->close();
    }
} elseif (!empty($input['notification_id'])) {
    // Delete single notification
    $notification_id = intval($input['notification_id']);

    $sql = "DELETE FROM notifications 
            WHERE id = ? AND (user_id = ? OR user_id IS NULL)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $notification_id, $user_id);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Notification deleted'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete notification'];
        }
        $stmt->close();
    }
}

// Close connection
$conn->close();

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
