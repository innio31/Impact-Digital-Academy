<?php
session_start();
require_once '../api/config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Get admin info
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
