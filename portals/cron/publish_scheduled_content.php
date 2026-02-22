<?php

/**
 * Automated Content Publisher Cron Script
 * 
 * This script should be run every hour via cron job
 * Example cron entry (runs at minute 0 of every hour):
 * 0 * * * * /usr/bin/php /path/to/your/site/cron/publish_scheduled_content.php >> /path/to/your/site/cron/logs/publisher.log 2>&1
 * 
 * For testing, you can also run it manually:
 * php /path/to/your/site/cron/publish_scheduled_content.php
 */

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Include required files
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/email_functions.php';

// Create logs directory if it doesn't exist
$log_dir = BASE_PATH . '/cron/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Setup logging
$log_file = $log_dir . '/publisher_' . date('Y-m-d') . '.log';
$run_id = uniqid();

/**
 * Write to log file
 */
function writeLog($message, $type = 'INFO')
{
    global $log_file, $run_id;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [{$run_id}] [{$type}] {$message}" . PHP_EOL;

    // Write to file
    file_put_contents($log_file, $log_message, FILE_APPEND);

    // Also output to console if running manually
    echo $log_message;
}

/**
 * Send notification to students about new content
 */
function notifyStudentsNewContent($class_id, $content_type, $content_id, $title, $conn)
{
    try {
        // Get all enrolled students
        $sql = "SELECT u.id, u.email, u.first_name, u.last_name 
                FROM enrollments e 
                JOIN users u ON e.student_id = u.id 
                WHERE e.class_id = ? AND e.status = 'active'";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) {
            writeLog("No active students found for class ID: {$class_id}", 'WARNING');
            return 0;
        }

        $notification_count = 0;
        $email_count = 0;

        // Get class details for email
        $class_sql = "SELECT cb.*, c.title as course_title 
                     FROM class_batches cb 
                     JOIN courses c ON cb.course_id = c.id 
                     WHERE cb.id = ?";
        $class_stmt = $conn->prepare($class_sql);
        $class_stmt->bind_param("i", $class_id);
        $class_stmt->execute();
        $class_result = $class_stmt->get_result();
        $class_details = $class_result->fetch_assoc();
        $class_stmt->close();

        $content_links = [
            'material' => BASE_URL . 'modules/student/classes/materials.php?class_id=' . $class_id,
            'assignment' => BASE_URL . 'modules/student/classes/assignments.php?class_id=' . $class_id,
            'quiz' => BASE_URL . 'modules/student/classes/quizzes.php?class_id=' . $class_id
        ];

        $content_icons = [
            'material' => '📄',
            'assignment' => '📝',
            'quiz' => '❓'
        ];

        $type_labels = [
            'material' => 'Learning Material',
            'assignment' => 'Assignment',
            'quiz' => 'Quiz'
        ];

        foreach ($students as $student) {
            // Create in-app notification
            $notif_sql = "INSERT INTO notifications 
                         (user_id, title, message, type, related_id, action_url, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, NOW())";

            $notif_stmt = $conn->prepare($notif_sql);

            $notif_title = "New {$type_labels[$content_type]}: {$title}";
            $notif_message = "A new {$content_type} has been published for {$class_details['course_title']}.";
            $action_url = $content_links[$content_type];

            $notif_stmt->bind_param(
                "ississ",
                $student['id'],
                $notif_title,
                $notif_message,
                $content_type,
                $content_id,
                $action_url
            );

            if ($notif_stmt->execute()) {
                $notification_count++;
            }
            $notif_stmt->close();

            // Send email notification (optional - you can enable/disable this)
            if (!empty($student['email']) && filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
                $email_subject = "{$content_icons[$content_type]} New {$type_labels[$content_type]}: {$title}";

                $email_body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #3b82f6; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                        .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                        .button { display: inline-block; background: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1 style='margin: 0;'>New Content Available!</h1>
                        </div>
                        <div class='content'>
                            <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                            <p>A new <strong>{$type_labels[$content_type]}</strong> has been published for your course:</p>
                            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h3 style='margin-top: 0; color: #3b82f6;'>{$title}</h3>
                                <p><strong>Course:</strong> " . htmlspecialchars($class_details['course_title']) . "</p>
                                <p><strong>Class:</strong> " . htmlspecialchars($class_details['batch_code']) . "</p>
                            </div>
                            <p style='text-align: center; margin: 30px 0;'>
                                <a href='{$action_url}' class='button'>View Content</a>
                            </p>
                        </div>
                        <div class='footer'>
                            <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                        </div>
                    </div>
                </body>
                </html>";

                if (sendEmail($student['email'], $email_subject, $email_body)) {
                    $email_count++;
                }
            }
        }

        writeLog("Sent {$notification_count} notifications and {$email_count} emails for {$content_type} ID: {$content_id}");
        return $notification_count;
    } catch (Exception $e) {
        writeLog("Error sending notifications: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// Start the publishing process
writeLog("========== STARTING SCHEDULED CONTENT PUBLISHER ==========");
writeLog("Server time: " . date('Y-m-d H:i:s'));

try {
    // Get database connection
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception("Failed to connect to database");
    }

    $now = date('Y-m-d H:i:s');
    $published_count = 0;
    $failed_count = 0;
    $skipped_count = 0;

    // Find all scheduled content due for publishing
    $sql = "SELECT 
                ccs.*, 
                cct.course_id,
                cct.content_type, 
                cct.title, 
                cct.content_data, 
                cct.file_references,
                cb.instructor_id,
                cb.batch_code
            FROM class_content_schedules ccs
            JOIN course_content_templates cct ON ccs.template_id = cct.id
            JOIN class_batches cb ON ccs.class_id = cb.id
            WHERE ccs.status = 'scheduled' 
            AND ccs.scheduled_publish_date <= ?
            ORDER BY ccs.scheduled_publish_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $now);
    $stmt->execute();
    $result = $stmt->get_result();
    $scheduled_items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    writeLog("Found " . count($scheduled_items) . " items to process");

    foreach ($scheduled_items as $item) {
        writeLog("Processing item ID: {$item['id']} - {$item['title']} ({$item['content_type']})");

        // Start transaction for each item
        $conn->begin_transaction();

        try {
            $content_data = json_decode($item['content_data'], true);
            $published_item_id = null;

            switch ($item['content_type']) {
                case 'material':
                    // Publish material
                    $insert_sql = "INSERT INTO materials 
                                  (class_id, instructor_id, title, description, file_url, 
                                   file_type, file_size, week_number, is_published, publish_date, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

                    $stmt = $conn->prepare($insert_sql);
                    $stmt->bind_param(
                        "iissssiis",
                        $item['class_id'],
                        $item['instructor_id'],
                        $item['title'],
                        $content_data['description'] ?? '',
                        $content_data['file_url'] ?? null,
                        $content_data['file_type'] ?? 'document',
                        $content_data['file_size'] ?? 0,
                        $content_data['week_number'] ?? 1
                    );

                    if ($stmt->execute()) {
                        $published_item_id = $stmt->insert_id;
                        writeLog("Published material ID: {$published_item_id}");
                    }
                    break;

                case 'assignment':
                    // Calculate due date based on template settings
                    $due_days = $content_data['due_days'] ?? 7;
                    $due_datetime = date('Y-m-d H:i:s', strtotime("+{$due_days} days", strtotime($item['scheduled_publish_date'])));

                    $insert_sql = "INSERT INTO assignments 
                                  (class_id, instructor_id, title, description, instructions, 
                                   due_date, total_points, submission_type, max_files, allowed_extensions,
                                   has_attachment, attachment_path, original_filename, is_published, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

                    $stmt = $conn->prepare($insert_sql);

                    $description = $content_data['description'] ?? '';
                    $instructions = $content_data['instructions'] ?? '';
                    $total_points = $content_data['total_points'] ?? 100;
                    $submission_type = $content_data['submission_type'] ?? 'file';
                    $max_files = $content_data['max_files'] ?? 1;
                    $allowed_extensions = $content_data['allowed_extensions'] ?? 'pdf,doc,docx';
                    $has_attachment = isset($content_data['has_attachment']) ? 1 : 0;
                    $attachment_path = $content_data['attachment_path'] ?? null;
                    $original_filename = $content_data['original_filename'] ?? null;

                    $stmt->bind_param(
                        "iissssdsssiss",
                        $item['class_id'],
                        $item['instructor_id'],
                        $item['title'],
                        $description,
                        $instructions,
                        $due_datetime,
                        $total_points,
                        $submission_type,
                        $max_files,
                        $allowed_extensions,
                        $has_attachment,
                        $attachment_path,
                        $original_filename
                    );

                    if ($stmt->execute()) {
                        $published_item_id = $stmt->insert_id;
                        writeLog("Published assignment ID: {$published_item_id}, due date: {$due_datetime}");
                    }
                    break;

                case 'quiz':
                    // Calculate availability dates
                    $available_days = $content_data['available_days'] ?? 7;
                    $available_from = $item['scheduled_publish_date'];
                    $available_to = date('Y-m-d H:i:s', strtotime("+{$available_days} days", strtotime($available_from)));

                    $insert_sql = "INSERT INTO quizzes 
                                  (class_id, instructor_id, title, description, instructions,
                                   quiz_type, total_points, time_limit, attempts_allowed,
                                   shuffle_questions, shuffle_options, show_correct_answers,
                                   available_from, available_to, is_published, created_at)
                                  VALUES (?, ?, ?, ?, ?, 'graded', ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

                    $stmt = $conn->prepare($insert_sql);

                    $description = $content_data['description'] ?? '';
                    $instructions = $content_data['instructions'] ?? '';
                    $total_points = $content_data['total_points'] ?? 100;
                    $time_limit = $content_data['time_limit'] ?? 30;
                    $attempts_allowed = $content_data['attempts_allowed'] ?? 1;
                    $shuffle_questions = $content_data['shuffle_questions'] ?? 0;
                    $shuffle_options = $content_data['shuffle_options'] ?? 0;
                    $show_correct_answers = $content_data['show_correct_answers'] ?? 1;

                    $stmt->bind_param(
                        "iissssiiiiss",
                        $item['class_id'],
                        $item['instructor_id'],
                        $item['title'],
                        $description,
                        $instructions,
                        $total_points,
                        $time_limit,
                        $attempts_allowed,
                        $shuffle_questions,
                        $shuffle_options,
                        $show_correct_answers,
                        $available_from,
                        $available_to
                    );

                    if ($stmt->execute()) {
                        $published_item_id = $stmt->insert_id;
                        writeLog("Published quiz ID: {$published_item_id}, available: {$available_from} to {$available_to}");
                    }
                    break;

                default:
                    writeLog("Unknown content type: {$item['content_type']}", 'WARNING');
                    $skipped_count++;
                    $conn->rollback();
                    continue 2;
            }

            $stmt->close();

            if ($published_item_id) {
                // Update schedule status
                $update_sql = "UPDATE class_content_schedules 
                              SET status = 'published', actual_publish_date = NOW(), published_item_id = ? 
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $published_item_id, $item['id']);
                $update_stmt->execute();
                $update_stmt->close();

                // Commit transaction
                $conn->commit();
                $published_count++;

                writeLog("Successfully published and committed item ID: {$item['id']}");

                // Send notifications to students
                notifyStudentsNewContent(
                    $item['class_id'],
                    $item['content_type'],
                    $published_item_id,
                    $item['title'],
                    $conn
                );

                // Log activity
                logActivity(
                    'content_auto_published',
                    "Auto-published {$item['content_type']}: {$item['title']} for class {$item['batch_code']}",
                    $item['content_type'] . 's',
                    $published_item_id
                );
            } else {
                throw new Exception("Failed to insert {$item['content_type']}");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $failed_count++;

            // Log the error
            writeLog("ERROR publishing item ID {$item['id']}: " . $e->getMessage(), 'ERROR');

            // Update schedule with error
            $error_sql = "UPDATE class_content_schedules 
                         SET status = 'failed', error_message = ? 
                         WHERE id = ?";
            $error_stmt = $conn->prepare($error_sql);
            $error_msg = substr($e->getMessage(), 0, 255);
            $error_stmt->bind_param("si", $error_msg, $item['id']);
            $error_stmt->execute();
            $error_stmt->close();

            // Log activity
            logActivity(
                'content_publish_failed',
                "Failed to auto-publish {$item['content_type']}: {$item['title']} - " . $e->getMessage(),
                'class_content_schedules',
                $item['id']
            );
        }
    }

    // Summary
    writeLog("========== PUBLISHING SUMMARY ==========");
    writeLog("Total processed: " . count($scheduled_items));
    writeLog("Successfully published: {$published_count}");
    writeLog("Failed: {$failed_count}");
    writeLog("Skipped: {$skipped_count}");

    // Optional: Send summary email to admin
    if ($published_count > 0 || $failed_count > 0) {
        $admin_email = getSetting('admin_email', 'admin@impactdigitalacademy.com.ng');
        $subject = "Content Publisher Summary - " . date('Y-m-d');
        $body = "
        <h2>Automated Content Publisher Summary</h2>
        <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Items Processed:</strong> " . count($scheduled_items) . "</p>
        <p><strong>Successfully Published:</strong> {$published_count}</p>
        <p><strong>Failed:</strong> {$failed_count}</p>
        <p><strong>Skipped:</strong> {$skipped_count}</p>
        <p>Check the log file for details: cron/logs/publisher_" . date('Y-m-d') . ".log</p>
        ";

        // Uncomment to enable admin summary emails
        // sendEmail($admin_email, $subject, $body);
    }
} catch (Exception $e) {
    writeLog("CRITICAL ERROR: " . $e->getMessage(), 'CRITICAL');

    // Try to log to database if possible
    if (isset($conn) && $conn) {
        logActivity('publisher_critical_error', $e->getMessage(), 'cron', 0);
    }
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}

writeLog("========== PUBLISHER COMPLETED ==========");
writeLog(""); // Empty line for separation