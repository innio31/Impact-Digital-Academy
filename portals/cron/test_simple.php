<?php
// Simple test file - save as cron/test_simple.php

// Define the logs path
$log_file = __DIR__ . '/../logs/simple_test.log';

// Write to log file (it will create the file if it doesn't exist)
$message = date('Y-m-d H:i:s') . " - Simple cron test is working!\n";
file_put_contents($log_file, $message, FILE_APPEND);

// Also display output
echo "Test ran at " . date('Y-m-d H:i:s');
