<?php

/**
 * Cron job script to automatically update missed grades
 * This should be set to run daily via cron
 */

// Load the main configuration and functions
require_once dirname(__DIR__) . 'config.php';
require_once dirname(__DIR__) . '/../includes/functions.php';

// Prevent direct browser access - only allow CLI or authorized cron
if (php_sapi_name() !== 'cli' && (!isset($_GET['cron_key']) || $_GET['cron_key'] !== CRON_SECRET_KEY)) {
    die('Access denied');
}

// Set maximum execution time (might take a while)
set_time_limit(300); // 5 minutes

// Log start
error_log("Cron job: Starting autoUpdateMissedGrades() at " . date('Y-m-d H:i:s'));

try {
    // Run the grade update
    $updated_count = autoUpdateMissedGrades();

    // Log success
    $message = "Cron job completed: Updated {$updated_count} grade entries";
    error_log($message);

    // If called from web with valid key, show output
    if (isset($_GET['cron_key'])) {
        echo $message . "\n";
    }
} catch (Exception $e) {
    // Log error
    error_log("Cron job failed: " . $e->getMessage());

    if (isset($_GET['cron_key'])) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Optional: Send email alert if something went wrong
if (isset($updated_count) && $updated_count === 0) {
    // Maybe send notification if no grades were updated (might indicate issue)
    // sendAdminAlert("No grades were updated in cron job");
}
