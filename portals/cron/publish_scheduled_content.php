<?php

/**
 * Cron Job Script to Publish Scheduled Content
 * Run this script every minute via cron: * * * * * php /path/to/cron/publish_scheduled_content.php
 */
// Define logMessage function FIRST
function logMessage($message)
{
    global $log_dir;
    $logFile = $log_dir . '/schedule_publisher.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Create logs directory if it doesn't exist
$log_dir = __DIR__ . '/../logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Now you can use logMessage
logMessage("=== CRON JOB STARTED ===");

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get current datetime
$now = date('Y-m-d H:i:s');

// Find all scheduled content that is due to be published
$sql = "SELECT ccs.*, cct.course_id, cct.content_type, cct.content_data, cct.file_references,
               cct.title, cct.description, cb.instructor_id, cb.id as class_id
        FROM class_content_schedules ccs
        JOIN course_content_templates cct ON ccs.template_id = cct.id
        JOIN class_batches cb ON ccs.class_id = cb.id
        WHERE ccs.scheduled_publish_date <= ?
          AND ccs.status = 'scheduled'
        ORDER BY ccs.scheduled_publish_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $now);
$stmt->execute();
$result = $stmt->get_result();

$published_count = 0;
$failed_count = 0;

while ($schedule = $result->fetch_assoc()) {
    $content_data = json_decode($schedule['content_data'], true) ?: [];
    $file_references = json_decode($schedule['file_references'], true) ?: [];

    $published_item_id = null;
    $error = null;

    // Start transaction for each item
    $conn->begin_transaction();

    try {
        switch ($schedule['content_type']) {
            case 'material':
                $published_item_id = publishMaterial($conn, $schedule, $content_data, $file_references);
                break;

            case 'assignment':
                $published_item_id = publishAssignment($conn, $schedule, $content_data);
                break;

            case 'quiz':
                $published_item_id = publishQuiz($conn, $schedule, $content_data);
                break;

            default:
                throw new Exception("Unknown content type: {$schedule['content_type']}");
        }

        // Update schedule status
        $update_sql = "UPDATE class_content_schedules 
                       SET status = 'published', 
                           actual_publish_date = NOW(), 
                           published_item_id = ?,
                           updated_at = NOW()
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $published_item_id, $schedule['id']);
        $update_stmt->execute();
        $update_stmt->close();

        $conn->commit();
        $published_count++;

        // Log activity
        logActivity(
            'content_published',
            "Published scheduled {$schedule['content_type']}: {$schedule['title']}",
            $schedule['content_type'] . 's',
            $published_item_id
        );
    } catch (Exception $e) {
        $conn->rollback();
        $failed_count++;

        // Update schedule with error
        $error_sql = "UPDATE class_content_schedules 
                      SET status = 'failed', 
                          error_message = ?,
                          updated_at = NOW()
                      WHERE id = ?";
        $error_stmt = $conn->prepare($error_sql);
        $error_msg = $e->getMessage();
        $error_stmt->bind_param("si", $error_msg, $schedule['id']);
        $error_stmt->execute();
        $error_stmt->close();

        // Log error
        error_log("Failed to publish scheduled content ID {$schedule['id']}: " . $e->getMessage());
    }
}

$stmt->close();
$conn->close();

echo "Published: $published_count, Failed: $failed_count\n";

/**
 * Publish a material and send notifications
 */
/**
 * Publish a material - FIXED VERSION
 */
/**
 * Publish a material - FIXED VERSION
 */
/**
 * Publish a material - FIXED to handle external links properly
 */
function publishMaterial($conn, $schedule, $content_data)
{
    logMessage("Starting publishMaterial for: " . $schedule['title']);

    // Get values with defaults
    $class_id = (int)$schedule['class_id'];
    $instructor_id = (int)$schedule['instructor_id'];
    $title = $schedule['title'] ?? '';
    $description = $schedule['description'] ?? '';
    $week_number = isset($schedule['week_number']) ? (int)$schedule['week_number'] : null;
    $topic = $content_data['topic'] ?? '';

    // IMPORTANT: Check if this is an external link
    $is_external_link = isset($content_data['is_external_link']) ? (int)$content_data['is_external_link'] : 0;
    $file_url = '';
    $external_url = '';
    $file_type = $content_data['file_type'] ?? 'document';
    $file_size = isset($content_data['file_size']) ? (int)$content_data['file_size'] : 0;

    if ($is_external_link == 1) {
        // This is an external link
        $external_url = $content_data['external_url'] ?? '';
        $file_url = ''; // No file URL for external links
        $file_type = $content_data['link_type'] ?? 'link'; // Use link_type if available

        logMessage("Processing external link: $external_url, type: $file_type");
    } else {
        // This is a file upload
        $file_url = $content_data['file_url'] ?? '';
        $external_url = ''; // No external URL for file uploads
        $file_type = $content_data['file_type'] ?? 'document';
        $file_size = isset($content_data['file_size']) ? (int)$content_data['file_size'] : 0;

        logMessage("Processing file upload: $file_url, type: $file_type");
    }

    // Handle null week_number
    if ($week_number === null) {
        $week_number = 0;
    }

    // Check the materials table structure to ensure we have the right columns
    // Based on your SQL dump, materials table has: 
    // id, class_id, instructor_id, title, description, file_url, external_url, 
    // is_external_link, file_type, file_size, week_number, topic, is_published, 
    // scheduled_publish_date, auto_publish, publish_date, downloads_count, views_count, 
    // created_at, updated_at

    // Insert material with proper handling of external links
    $sql = "INSERT INTO materials (
                class_id, 
                instructor_id, 
                title, 
                description, 
                file_url,
                external_url,
                is_external_link,
                file_type, 
                file_size, 
                week_number, 
                topic,
                is_published, 
                publish_date, 
                downloads_count,
                views_count,
                created_at, 
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), 0, 0, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Count the parameters: 11 placeholders (class_id, instructor_id, title, description, 
    // file_url, external_url, is_external_link, file_type, file_size, week_number, topic)
    $types = "iissssisiss"; // i,i,s,s,s,s,i,s,i,i,s

    logMessage("Binding with types: $types for 11 parameters");
    logMessage("External link flag: $is_external_link");

    $stmt->bind_param(
        $types,
        $class_id,
        $instructor_id,
        $title,
        $description,
        $file_url,
        $external_url,
        $is_external_link,
        $file_type,
        $file_size,
        $week_number,
        $topic
    );

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $material_id = $stmt->insert_id;
    $stmt->close();

    logMessage("Material created with ID: $material_id, is_external_link: $is_external_link");

    return $material_id;
}


/**
 * Publish an assignment - COMPLETELY REVISED with proper counting
 */
function publishAssignment($conn, $schedule, $content_data)
{
    logMessage("Starting publishAssignment for: " . $schedule['title']);

    // Calculate due date based on template settings (default 7 days from publish)
    $due_days = isset($content_data['due_days']) ? (int)$content_data['due_days'] : 7;
    $due_date = date('Y-m-d H:i:s', strtotime($schedule['scheduled_publish_date'] . " + $due_days days"));

    // Get all values with proper types
    $class_id = (int)$schedule['class_id'];
    $instructor_id = (int)$schedule['instructor_id'];
    $title = $schedule['title'] ?? '';
    $description = $schedule['description'] ?? '';
    $instructions = $content_data['instructions'] ?? '';
    $total_points = isset($content_data['total_points']) ? (float)$content_data['total_points'] : 100.00;
    $submission_type = $content_data['submission_type'] ?? 'file';
    $max_files = isset($content_data['max_files']) ? (int)$content_data['max_files'] : 1;
    $allowed_extensions = $content_data['allowed_extensions'] ?? '';
    $has_attachment = isset($content_data['has_attachment']) ? (int)$content_data['has_attachment'] : 0;
    $attachment_path = $content_data['attachment_path'] ?? '';
    $original_filename = $content_data['original_filename'] ?? '';

    logMessage("Assignment data - Title: $title, Due: $due_date, Points: $total_points");

    // FIRST, let's check the assignments table structure to ensure we have the right columns
    // Based on your SQL dump, the assignments table has these columns:
    // id, class_id, instructor_id, title, description, instructions, due_date, total_points, 
    // submission_type, max_files, allowed_extensions, is_published, has_attachment, 
    // attachment_path, original_filename, created_at, updated_at

    // Count the columns we're inserting: 14 columns (excluding id, created_at, updated_at which auto-populate)
    $sql = "INSERT INTO assignments (
                class_id, 
                instructor_id, 
                title, 
                description, 
                instructions,
                due_date, 
                total_points, 
                submission_type, 
                max_files, 
                allowed_extensions,
                has_attachment, 
                attachment_path, 
                original_filename, 
                is_published,
                created_at, 
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // We have 14 placeholders (?) and 14 variables to bind
    // Let's list them with their types:
    // 1. class_id - integer (i)
    // 2. instructor_id - integer (i)
    // 3. title - string (s)
    // 4. description - string (s)
    // 5. instructions - string (s)
    // 6. due_date - string (s)
    // 7. total_points - double (d)
    // 8. submission_type - string (s)
    // 9. max_files - integer (i)
    // 10. allowed_extensions - string (s)
    // 11. has_attachment - integer (i)
    // 12. attachment_path - string (s)
    // 13. original_filename - string (s)
    // 14. is_published - (set to 1 directly, not a placeholder)

    // So we have 13 placeholders (?) that need binding (is_published is set directly to 1)
    // Wait, I miscounted! Let me recount the placeholders in VALUES:
    // VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    // That's: 1,2,3,4,5,6,7,8,9,10,11,12,13, (then 1, NOW(), NOW())
    // So we have 13 placeholders!

    $types = "iissssdssisss"; // Let's count: i(1),i(2),s(3),s(4),s(5),s(6),d(7),s(8),s(9),i(10),s(11),s(12),s(13)
    // That's 13 characters for 13 placeholders

    logMessage("Binding with types: $types for 13 parameters");

    $stmt->bind_param(
        $types,
        $class_id,        // i
        $instructor_id,   // i
        $title,           // s
        $description,     // s
        $instructions,    // s
        $due_date,        // s
        $total_points,    // d
        $submission_type, // s
        $max_files,       // s (changing to s since max_files might be stored as string in some cases)
        $allowed_extensions, // s
        $has_attachment,  // i
        $attachment_path, // s
        $original_filename // s
    );

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $assignment_id = $stmt->insert_id;
    $stmt->close();

    logMessage("Assignment created with ID: $assignment_id");

    return $assignment_id;
}

/**
 * Publish a quiz and send notifications
 */
/**
 * Publish a quiz - FIXED VERSION
 */
function publishQuiz($conn, $schedule, $content_data)
{
    logMessage("Starting publishQuiz for: " . $schedule['title']);

    // Calculate availability dates
    $available_from = $schedule['scheduled_publish_date'];
    $available_to = null;
    $due_date = null;

    if (!empty($content_data['available_days'])) {
        $available_to = date('Y-m-d H:i:s', strtotime($available_from . " + {$content_data['available_days']} days"));
    }

    if (!empty($content_data['due_days'])) {
        $due_date = date('Y-m-d H:i:s', strtotime($available_from . " + {$content_data['due_days']} days"));
    }

    // Get values with defaults
    $class_id = (int)$schedule['class_id'];
    $instructor_id = (int)$schedule['instructor_id'];
    $title = $schedule['title'] ?? '';
    $description = $schedule['description'] ?? '';
    $instructions = $content_data['instructions'] ?? '';
    $quiz_type = $content_data['quiz_type'] ?? 'graded';
    $total_points = isset($content_data['total_points']) ? (float)$content_data['total_points'] : 100;
    $time_limit = isset($content_data['time_limit']) ? (int)$content_data['time_limit'] : 0;
    $attempts_allowed = isset($content_data['attempts_allowed']) ? (int)$content_data['attempts_allowed'] : 1;
    $shuffle_questions = isset($content_data['shuffle_questions']) ? (int)$content_data['shuffle_questions'] : 0;
    $shuffle_options = isset($content_data['shuffle_options']) ? (int)$content_data['shuffle_options'] : 0;
    $show_correct_answers = isset($content_data['show_correct_answers']) ? (int)$content_data['show_correct_answers'] : 1;

    // COUNT the placeholders: 15 placeholders
    $sql = "INSERT INTO quizzes (
                class_id, instructor_id, title, description, instructions,
                quiz_type, total_points, time_limit, attempts_allowed,
                shuffle_questions, shuffle_options, show_correct_answers,
                available_from, available_to, due_date, is_published,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // 15 parameters for 15 placeholders
    $types = "iissssdisiiiisss"; // i,i,s,s,s,s,d,i,i,i,i,i,s,s,s

    $stmt->bind_param(
        $types,
        $class_id,
        $instructor_id,
        $title,
        $description,
        $instructions,
        $quiz_type,
        $total_points,
        $time_limit,
        $attempts_allowed,
        $shuffle_questions,
        $shuffle_options,
        $show_correct_answers,
        $available_from,
        $available_to,
        $due_date
    );

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $quiz_id = $stmt->insert_id;
    $stmt->close();

    logMessage("Quiz created with ID: $quiz_id");

    return $quiz_id;
}

/**
 * Copy template files to class-specific location
 */
function copyTemplateFiles($file_references, $class_id, $type, $item_id)
{
    // Implementation depends on your file storage structure
    // This would copy files from template location to class location
    // Example:
    foreach ($file_references as $file) {
        $source = __DIR__ . '/../uploads/templates/' . $file['path'];
        $destination = __DIR__ . "/../uploads/{$type}/{$class_id}/{$item_id}/" . basename($file['path']);

        if (file_exists($source)) {
            $dest_dir = dirname($destination);
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0755, true);
            }
            copy($source, $destination);
        }
    }
}

/**
 * Import quiz questions from template
 */
function importQuizQuestions($conn, $quiz_id, $questions)
{
    foreach ($questions as $question_data) {
        // Insert question
        $sql = "INSERT INTO quiz_questions (
                    quiz_id, question_type, question_text, points, order_number
                ) VALUES (?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "issdi",
            $quiz_id,
            $question_data['question_type'],
            $question_data['question_text'],
            $question_data['points'] ?? 1,
            $question_data['order_number'] ?? 0
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert quiz question: " . $stmt->error);
        }

        $question_id = $stmt->insert_id;
        $stmt->close();

        // Insert options if multiple choice
        if (!empty($question_data['options'])) {
            $opt_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, order_number) 
                        VALUES (?, ?, ?, ?)";
            $opt_stmt = $conn->prepare($opt_sql);

            foreach ($question_data['options'] as $option) {
                $opt_stmt->bind_param(
                    "isii",
                    $question_id,
                    $option['option_text'],
                    $option['is_correct'],
                    $option['order_number'] ?? 0
                );
                $opt_stmt->execute();
            }
            $opt_stmt->close();
        }
    }
}

/**
 * Send notifications for new material
 */
/**
 * Send notifications for published content using existing email functions
 */
function sendContentNotifications($conn, $schedule, $published_item_id)
{
    logMessage("Sending notifications for {$schedule['content_type']} ID: $published_item_id");

    $notification_count = 0;

    switch ($schedule['content_type']) {
        case 'material':
            // Use the existing material notification function or create one
            $notification_count = sendNewMaterialNotification($conn, $published_item_id, $schedule);
            break;

        case 'assignment':
            // Use the existing assignment notification function
            $notification_count = sendAssignmentNotificationEmail($published_item_id, $conn);
            if ($notification_count > 0) {
                logMessage("Sent assignment notifications to $notification_count students");
            }
            break;

        case 'quiz':
            // You'll need to create a quiz notification function similar to assignments
            $notification_count = sendNewQuizNotification($conn, $published_item_id, $schedule);
            break;
    }

    // Also notify the instructor
    sendInstructorNotification($conn, $schedule, $published_item_id);

    return $notification_count;
}

/**
 * Send notification for new material
 */
function sendNewMaterialNotification($conn, $material_id, $schedule)
{
    // Get material details
    $sql = "SELECT m.*, cb.batch_code, c.title as course_title, 
                   c.course_code, u.email as instructor_email,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM materials m 
            JOIN class_batches cb ON m.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            JOIN users u ON cb.instructor_id = u.id 
            WHERE m.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $material_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $material = $result->fetch_assoc();
    $stmt->close();

    if (!$material) {
        logMessage("Material not found for ID: $material_id");
        return 0;
    }

    // Get enrolled students with valid emails
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name 
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            WHERE e.class_id = ? AND e.status = 'active' 
            AND u.email IS NOT NULL AND u.email != '' AND u.email LIKE '%@%.%'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $material['class_id']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        logMessage("No students found for class ID: " . $material['class_id']);
        return 0;
    }

    $notification_count = 0;
    $class_link = BASE_URL . "modules/student/classes/materials.php?class_id=" . $material['class_id'];

    foreach ($students as $student) {
        if (empty($student['email'])) continue;

        $subject = "📚 New Material: " . $material['title'] . " - " . $material['course_title'];

        // Determine if it's an external link or file
        $is_external = $material['is_external_link'] ?? 0;
        $material_type = $is_external ? 'External Link' : 'Document';
        $material_icon = $is_external ? '🔗' : '📄';

        $body = "
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
                .material-box { background: white; border-left: 4px solid #3b82f6; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .button { display: inline-block; background: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .meta { color: #64748b; font-size: 14px; margin-top: 10px; }
                .external-badge { background: #e0f2fe; color: #0369a1; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>{$material_icon} New Learning Material</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                    
                    <p>New learning material has been posted for your course: <strong>" . htmlspecialchars($material['course_title']) . " (" . htmlspecialchars($material['batch_code']) . ")</strong></p>
                    
                    <div class='material-box'>
                        <h2 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($material['title']) . "</h2>
                        
                        " . (!empty($material['description']) ? "<p style='color: #4b5563;'>" . nl2br(htmlspecialchars($material['description'])) . "</p>" : "") . "
                        
                        <div class='meta'>
                            <p>
                                <strong>Type:</strong> " . $material_type . "
                                " . ($is_external ? "<span class='external-badge'>External Link</span>" : "") . "<br>
                                <strong>Week:</strong> " . ($material['week_number'] ? 'Week ' . $material['week_number'] : 'Current') . "<br>
                                <strong>Posted by:</strong> " . htmlspecialchars($material['instructor_name']) . "
                            </p>
                        </div>
                    </div>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='{$class_link}' class='button'>View Material</a>
                    </p>
                    
                    <p>Check your class dashboard to access this and other learning materials.</p>
                    
                    <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                    
                    <p style='color: #666; font-size: 13px;'>
                        This is an automated notification from your learning portal. Please do not reply to this email.
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($student['email'], $subject, $body)) {
            $notification_count++;

            // Create in-app notification
            $notif_sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                         VALUES (?, ?, ?, 'new_material', ?, NOW())";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_title = "New Material: " . $material['title'];
            $notif_message = "New learning material posted in " . $material['course_title'];
            $notif_stmt->bind_param("issi", $student['id'], $notif_title, $notif_message, $material_id);
            $notif_stmt->execute();
            $notif_stmt->close();
        }
    }

    logMessage("Sent $notification_count email notifications for material #$material_id");
    return $notification_count;
}

/**
 * Send notification for new quiz
 */
function sendNewQuizNotification($conn, $quiz_id, $schedule)
{
    // Get quiz details
    $sql = "SELECT q.*, cb.batch_code, c.title as course_title, 
                   c.course_code, u.email as instructor_email,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM quizzes q 
            JOIN class_batches cb ON q.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            JOIN users u ON cb.instructor_id = u.id 
            WHERE q.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quiz = $result->fetch_assoc();
    $stmt->close();

    if (!$quiz) {
        logMessage("Quiz not found for ID: $quiz_id");
        return 0;
    }

    // Get enrolled students with valid emails
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name 
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            WHERE e.class_id = ? AND e.status = 'active' 
            AND u.email IS NOT NULL AND u.email != '' AND u.email LIKE '%@%.%'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $quiz['class_id']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        logMessage("No students found for class ID: " . $quiz['class_id']);
        return 0;
    }

    $notification_count = 0;
    $class_link = BASE_URL . "modules/student/classes/quizzes.php?class_id=" . $quiz['class_id'];
    $available_from = date('F j, Y g:i A', strtotime($quiz['available_from']));
    $available_to = $quiz['available_to'] ? date('F j, Y g:i A', strtotime($quiz['available_to'])) : 'No end date';
    $time_limit = $quiz['time_limit'] ? $quiz['time_limit'] . ' minutes' : 'No time limit';

    foreach ($students as $student) {
        if (empty($student['email'])) continue;

        $subject = "❓ New Quiz: " . $quiz['title'] . " - " . $quiz['course_title'];

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #10b981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .quiz-box { background: white; border-left: 4px solid #10b981; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .button { display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .meta { color: #64748b; font-size: 14px; margin-top: 10px; }
                .deadline { color: #dc2626; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>❓ New Quiz Available</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                    
                    <p>A new quiz is now available for your course: <strong>" . htmlspecialchars($quiz['course_title']) . " (" . htmlspecialchars($quiz['batch_code']) . ")</strong></p>
                    
                    <div class='quiz-box'>
                        <h2 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($quiz['title']) . "</h2>
                        
                        " . (!empty($quiz['description']) ? "<p style='color: #4b5563;'>" . nl2br(htmlspecialchars($quiz['description'])) . "</p>" : "") . "
                        
                        <div class='meta'>
                            <p>
                                <strong>Available From:</strong> {$available_from}<br>
                                <strong>Available Until:</strong> " . ($quiz['available_to'] ? "<span class='deadline'>{$available_to}</span>" : $available_to) . "<br>
                                <strong>Time Limit:</strong> {$time_limit}<br>
                                <strong>Attempts Allowed:</strong> " . $quiz['attempts_allowed'] . "<br>
                                <strong>Points:</strong> " . $quiz['total_points'] . "<br>
                                <strong>Posted by:</strong> " . htmlspecialchars($quiz['instructor_name']) . "
                            </p>
                        </div>
                    </div>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='{$class_link}' class='button'>Take Quiz</a>
                    </p>
                    
                    <p>Complete the quiz before it closes. Good luck!</p>
                    
                    <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                    
                    <p style='color: #666; font-size: 13px;'>
                        This is an automated notification from your learning portal. Please do not reply to this email.
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($student['email'], $subject, $body)) {
            $notification_count++;

            // Create in-app notification
            $notif_sql = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                         VALUES (?, ?, ?, 'new_quiz', ?, NOW())";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_title = "New Quiz: " . $quiz['title'];
            $notif_message = "New quiz available in " . $quiz['course_title'];
            $notif_stmt->bind_param("issi", $student['id'], $notif_title, $notif_message, $quiz_id);
            $notif_stmt->execute();
            $notif_stmt->close();
        }
    }

    logMessage("Sent $notification_count email notifications for quiz #$quiz_id");
    return $notification_count;
}

/**
 * Send notification to instructor when content is published
 */
function sendInstructorNotification($conn, $schedule, $published_item_id)
{
    // Get instructor details
    $sql = "SELECT u.email, u.first_name, u.last_name,
                   cb.batch_code, c.title as course_title
            FROM users u
            JOIN class_batches cb ON cb.instructor_id = u.id
            JOIN courses c ON cb.course_id = c.id
            WHERE u.id = ? AND cb.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $schedule['instructor_id'], $schedule['class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $instructor = $result->fetch_assoc();
    $stmt->close();

    if (!$instructor || empty($instructor['email'])) {
        logMessage("Instructor not found for ID: " . $schedule['instructor_id']);
        return false;
    }

    $content_type = ucfirst($schedule['content_type']);
    $subject = "✅ {$content_type} Published: " . $schedule['title'] . " - " . $instructor['course_title'];

    // Get student count
    $count_sql = "SELECT COUNT(*) as student_count FROM enrollments 
                  WHERE class_id = ? AND status = 'active'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $schedule['class_id']);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $student_count = $count_row['student_count'] ?? 0;
    $count_stmt->close();

    $class_link = BASE_URL . "modules/instructor/classes/" .
        ($schedule['content_type'] == 'material' ? 'materials.php' : ($schedule['content_type'] == 'assignment' ? 'assignments.php' : 'quizzes.php')) .
        "?class_id=" . $schedule['class_id'];

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #10b981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #10b981; }
            .button { display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .stats { display: flex; gap: 20px; margin: 20px 0; }
            .stat-item { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; flex: 1; }
            .stat-number { font-size: 24px; font-weight: bold; color: #10b981; }
            .stat-label { font-size: 12px; color: #64748b; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>✅ {$content_type} Published Successfully</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($instructor['first_name']) . ",</p>
                
                <p>Your {$schedule['content_type']} has been published successfully for class: <strong>" . htmlspecialchars($instructor['course_title']) . " (" . htmlspecialchars($instructor['batch_code']) . ")</strong></p>
                
                <div class='info-box'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>Published Content:</h3>
                    <p><strong>Title:</strong> " . htmlspecialchars($schedule['title']) . "</p>
                    <p><strong>Type:</strong> {$content_type}</p>
                    <p><strong>Published at:</strong> " . date('F j, Y g:i A') . "</p>
                </div>
                
                <div class='stats'>
                    <div class='stat-item'>
                        <div class='stat-number'>{$student_count}</div>
                        <div class='stat-label'>Students Notified</div>
                    </div>
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$class_link}' class='button'>View in Class</a>
                </p>
                
                <p>Students have been notified via email and in-app notifications.</p>
                
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                
                <p style='color: #666; font-size: 13px;'>
                    This is an automated confirmation from your learning portal.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    $result = sendEmail($instructor['email'], $subject, $body);

    if ($result) {
        logMessage("Instructor notification sent to: " . $instructor['email']);
    } else {
        logMessage("Failed to send instructor notification");
    }

    return $result;
}
