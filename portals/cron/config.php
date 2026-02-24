<?php
// cron/config.php
// Configuration for cron jobs

// Maximum execution time (30 minutes)
ini_set('max_execution_time', 1800);
ini_set('memory_limit', '256M');

// Error reporting for cron (log errors, don't display)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Timezone
date_default_timezone_set('Africa/Lagos');

// Lock file to prevent multiple instances
define('CRON_LOCK_FILE', __DIR__ . '/locks/publisher.lock');

// Check if another instance is running
function checkLock()
{
    if (file_exists(CRON_LOCK_FILE)) {
        $lock_time = filemtime(CRON_LOCK_FILE);
        // If lock is older than 2 hours, assume it's stale
        if (time() - $lock_time < 7200) {
            die("Another instance is already running. Lock file: " . CRON_LOCK_FILE . "\n");
        } else {
            // Remove stale lock
            unlink(CRON_LOCK_FILE);
        }
    }

    // Create lock file
    file_put_contents(CRON_LOCK_FILE, getmypid());
}

// Remove lock file
function releaseLock()
{
    if (file_exists(CRON_LOCK_FILE)) {
        unlink(CRON_LOCK_FILE);
    }
}

// Register shutdown function to release lock
register_shutdown_function('releaseLock');

// Cron job security key - generate a random string
// You can generate one at: https://www.random.org/strings/
define('CRON_SECRET_KEY', 'Impact2026'); // REPLACE THIS WITH A REAL RANDOM KEY