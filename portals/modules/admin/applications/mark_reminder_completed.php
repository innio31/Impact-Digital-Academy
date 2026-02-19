<?php
// modules/admin/applications/mark_reminder_completed.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Validate CSRF token
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

// Clear the recently approved applications from session
if (isset($_SESSION['recently_approved'])) {
    unset($_SESSION['recently_approved']);
}

// Log activity
logActivity($_SESSION['user_id'], 'reminder_completed', "Marked approval reminder as completed");

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Reminder marked as completed']);
?>