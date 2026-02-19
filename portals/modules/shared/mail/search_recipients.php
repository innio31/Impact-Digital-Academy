<?php
// modules/shared/mail/search_recipients.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode([]);
    exit();
}

// Get search term
$search_term = isset($_GET['q']) ? sanitize($_GET['q']) : '';

if (empty($search_term)) {
    echo json_encode([]);
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Search for users
$users = [];
$exclude_user_id = $_SESSION['user_id'];

$sql = "SELECT id, first_name, last_name, email, role, profile_image 
        FROM users 
        WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) 
        AND id != ? 
        AND status = 'active'
        LIMIT 20";

$search_term_like = "%$search_term%";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssi", $search_term_like, $search_term_like, $search_term_like, $exclude_user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($users);
