<?php
// modules/auth/logout.php

// Start session
session_start();

// Include configuration
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Logout user
logoutUser();

// Set success message
$_SESSION['success'] = 'You have been logged out successfully.';

// Redirect to home page
redirect(BASE_URL . 'index.php');
?>