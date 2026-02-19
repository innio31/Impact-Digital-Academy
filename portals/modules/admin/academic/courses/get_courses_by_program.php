<?php
// modules/admin/academic/courses/get_courses_by_program.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get parameters
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

if (!$program_id) {
    echo json_encode([]);
    exit();
}

// Get database connection
$conn = getDBConnection();

// Prepare query
$sql = "SELECT id, course_code, title FROM courses 
        WHERE program_id = ? AND status = 'active'";

if ($exclude_id) {
    $sql .= " AND id != ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $program_id, $exclude_id);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
}

$stmt->execute();
$result = $stmt->get_result();

$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($courses);

$conn->close();
