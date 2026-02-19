<?php
// modules/admin/schools/edit.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Check if school ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = 'School ID is required';
    header('Location: manage.php');
    exit();
}

$school_id = (int)$_GET['id'];

// Get school details
$sql = "SELECT * FROM schools WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
$school = $result->fetch_assoc();

if (!$school) {
    $_SESSION['error'] = 'School not found';
    header('Location: manage.php');
    exit();
}

// Redirect to the create.php edit mode
header("Location: create.php?edit=" . $school_id);
exit();