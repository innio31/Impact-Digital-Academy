<?php

/**
 * Cron script to send scheduled notifications
 * Run this script every hour via cron job
 * 
 * Example cron entry:
 * 0 * * * * /usr/bin/php /path/to/your/site/cron/send_notifications.php
 */

// Include required files
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Start logging
$log_file = __DIR__ . '/logs/cron_' . date('Y-m-d') . '.log';
$timestamp = date('Y-m-d H:i:s');

// Function to write to log
function writeLog($message)
{
    global $log_file, $timestamp;
    $log_message = "[{$timestamp}] {$message}\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
    echo $log_message;
}

writeLog("Starting notification cron job");

// 1. Send weekly reminders (run on Mondays only)
if (date('N') == 1) { // 1 = Monday
    writeLog("Sending weekly reminders to online students...");
    $weekly_count = sendWeeklyReminderToOnlineStudents();
    writeLog("Sent {$weekly_count} weekly reminders");
} else {
    writeLog("Skipping weekly reminders (not Monday)");
}

// 2. Check for upcoming deadlines and send reminders
writeLog("Checking for upcoming deadlines...");
$deadline_count = checkAndSendDeadlineReminders();
writeLog("Sent {$deadline_count} deadline reminders");

writeLog("Notification cron job completed");
