<?php
// includes/config.php

// Database configuration for HostAfrica
define('DB_HOST', 'localhost'); // HostAfrica uses localhost for database connections
define('DB_USER', 'impactdi_competitions');
define('DB_PASS', '34GSPE3R4EbFnmENLJsj');
define('DB_NAME', 'impactdi_competitions');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone to Nigeria
date_default_timezone_set('Africa/Lagos'); // Nigeria timezone (West Africa Time)

// Start session
session_start();

// Quiz settings
define('COUNTDOWN_TIME', 10); // seconds
define('QUESTION_TIME', 10); // seconds
define('MAX_POINTS', 100);
