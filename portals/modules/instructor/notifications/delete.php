<?php
// modules/instructor/notifications/delete.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get notification ID from URL or POST
$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0);

if ($notification_id > 0) {
    $conn = getDBConnection();

    $sql = "DELETE FROM notifications 
            WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $instructor_id);
    $stmt->execute();
    $stmt->close();

    $conn->close();

    $_SESSION['success'] = 'Notification deleted successfully.';
}

// Redirect back
$redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : BASE_URL . 'modules/instructor/notifications/view.php';
header('Location: ' . $redirect_url);
exit();
