<?php
// modules/admin/users/action.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Validate CSRF token
if (!isset($_GET['token']) || !validateCSRFToken($_GET['token'])) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

// Validate action and ID
if (!isset($_GET['action']) || !isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid request parameters.';
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

$action = $_GET['action'];
$user_id = (int)$_GET['id'];

// Allowed actions
$allowed_actions = ['activate', 'suspend'];
if (!in_array($action, $allowed_actions)) {
    $_SESSION['error'] = 'Invalid action.';
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

$conn = getDBConnection();

// Check if user exists and is not admin (prevent self-modification)
$check_sql = "SELECT id, role FROM users WHERE id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $_SESSION['error'] = 'User not found.';
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

$user = $check_result->fetch_assoc();

// Prevent modifying admin users (optional, for security)
if ($user['role'] === 'admin') {
    $_SESSION['error'] = 'Cannot modify administrator accounts.';
    header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
    exit();
}

// Determine new status based on action
$new_status = ($action === 'activate') ? 'active' : 'suspended';

// Update user status
$update_sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("si", $new_status, $user_id);

if ($update_stmt->execute()) {
    // Log the activity
    logActivity($_SESSION['user_id'], 'user_update', 
        "User #$user_id status changed to $new_status via action.php", 
        'users', $user_id);
    
    $_SESSION['success'] = "User has been " . ($action === 'activate' ? 'activated' : 'suspended') . " successfully.";
} else {
    $_SESSION['error'] = 'Failed to update user status.';
}

$conn->close();

// Redirect back to manage page
header('Location: ' . BASE_URL . 'modules/admin/users/manage.php');
exit();
?>