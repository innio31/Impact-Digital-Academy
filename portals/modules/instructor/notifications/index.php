<?php
// modules/instructor/notifications/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Redirect to view.php
header('Location: ' . BASE_URL . 'modules/instructor/notifications/view.php');
exit();
