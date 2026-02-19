<?php
// modules/instructor/notifications/mark_all_read.php

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

$sql = "UPDATE notifications SET is_read = 1, read_at = NOW() 
        WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$success = $stmt->execute();
$stmt->close();

$conn->close();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
