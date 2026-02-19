<?php
// modules/shared/notifications/send_bulk_notifications.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in and is admin/instructor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'instructor'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get POST data
$title = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');
$type = $_POST['type'] ?? 'system';
$priority = $_POST['priority'] ?? 'normal';
$recipient_type = $_POST['recipient_type'] ?? 'all'; // all, class, program, specific
$recipient_ids = $_POST['recipient_ids'] ?? [];
$class_id = $_POST['class_id'] ?? null;
$program_id = $_POST['program_id'] ?? null;

// Validate input
if (empty($title) || empty($message)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Title and message are required']);
    exit();
}

// Get recipient user IDs based on recipient type
$user_ids = [];

switch ($recipient_type) {
    case 'all':
        // Get all active students
        $sql = "SELECT id FROM users WHERE role = 'student' AND status = 'active'";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = $row['id'];
        }
        break;

    case 'class':
        if (empty($class_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Class ID is required']);
            exit();
        }

        // Get all students in a specific class
        $sql = "SELECT DISTINCT e.student_id 
                FROM enrollments e 
                WHERE e.class_id = ? AND e.status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = $row['student_id'];
        }
        $stmt->close();
        break;

    case 'program':
        if (empty($program_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Program ID is required']);
            exit();
        }

        // Get all students in a specific program
        $sql = "SELECT DISTINCT u.id 
                FROM users u 
                JOIN enrollments e ON u.id = e.student_id 
                JOIN class_batches cb ON e.class_id = cb.id 
                JOIN courses c ON cb.course_id = c.id 
                WHERE c.program_id = ? AND u.role = 'student' AND u.status = 'active' AND e.status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $program_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_ids[] = $row['id'];
        }
        $stmt->close();
        break;

    case 'specific':
        if (empty($recipient_ids)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Recipient IDs are required']);
            exit();
        }
        $user_ids = array_map('intval', $recipient_ids);
        break;

    default:
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid recipient type']);
        exit();
}

// Send notifications to each user
$success_count = 0;
$error_count = 0;

foreach ($user_ids as $user_id) {
    $sql = "INSERT INTO notifications (user_id, title, message, type, priority, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("issss", $user_id, $title, $message, $type, $priority);
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
        }
        $stmt->close();
    } else {
        $error_count++;
    }
}

// Close connection
$conn->close();

// Log activity
logActivity(
    $_SESSION['user_id'],
    'send_bulk_notifications',
    "Sent bulk notifications to $success_count users",
    $_SERVER['REMOTE_ADDR']
);

// Send response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Notifications sent successfully",
    'stats' => [
        'total_recipients' => count($user_ids),
        'sent_successfully' => $success_count,
        'failed' => $error_count
    ]
]);
