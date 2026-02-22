<?php

/**
 * Cron Job Script to Publish Scheduled Content
 * Run this script every minute via cron: * * * * * php /path/to/cron/publish_scheduled_content.php
 */

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
 * Publish a material
 */
function publishMaterial($conn, $schedule, $content_data, $file_references)
{
    // Insert material
    $sql = "INSERT INTO materials (
                class_id, instructor_id, title, description, 
                file_url, file_type, file_size, week_number, topic,
                is_published, publish_date, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())";

    $stmt = $conn->prepare($sql);

    // Get file info from content_data or file_references
    $file_url = $content_data['file_url'] ?? '';
    $file_type = $content_data['file_type'] ?? 'document';
    $file_size = $content_data['file_size'] ?? 0;
    $week_number = $schedule['week_number'] ?? null;
    $topic = $content_data['topic'] ?? '';

    $stmt->bind_param(
        "iissssiss",
        $schedule['class_id'],
        $schedule['instructor_id'],
        $schedule['title'],
        $schedule['description'],
        $file_url,
        $file_type,
        $file_size,
        $week_number,
        $topic
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert material: " . $stmt->error);
    }

    $material_id = $stmt->insert_id;
    $stmt->close();

    // Handle file copying if needed (if files need to be duplicated from template)
    if (!empty($file_references)) {
        copyTemplateFiles($file_references, $schedule['class_id'], 'materials', $material_id);
    }

    return $material_id;
}

/**
 * Publish an assignment
 */
function publishAssignment($conn, $schedule, $content_data)
{
    // Calculate due date based on template settings
    $due_days = $content_data['due_days'] ?? 7;
    $due_date = date('Y-m-d H:i:s', strtotime($schedule['scheduled_publish_date'] . " + $due_days days"));

    // Insert assignment
    $sql = "INSERT INTO assignments (
                class_id, instructor_id, title, description, instructions,
                due_date, total_points, submission_type, max_files, allowed_extensions,
                has_attachment, attachment_path, original_filename, is_published,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

    $stmt = $conn->prepare($sql);

    $instructions = $content_data['instructions'] ?? '';
    $submission_type = $content_data['submission_type'] ?? 'file';
    $max_files = $content_data['max_files'] ?? 1;
    $allowed_extensions = $content_data['allowed_extensions'] ?? '';
    $has_attachment = $content_data['has_attachment'] ?? 0;
    $attachment_path = $content_data['attachment_path'] ?? '';
    $original_filename = $content_data['original_filename'] ?? '';

    $stmt->bind_param(
        "iissssdsssiss",
        $schedule['class_id'],
        $schedule['instructor_id'],
        $schedule['title'],
        $schedule['description'],
        $instructions,
        $due_date,
        $content_data['total_points'] ?? 100,
        $submission_type,
        $max_files,
        $allowed_extensions,
        $has_attachment,
        $attachment_path,
        $original_filename
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert assignment: " . $stmt->error);
    }

    $assignment_id = $stmt->insert_id;
    $stmt->close();

    return $assignment_id;
}

/**
 * Publish a quiz
 */
function publishQuiz($conn, $schedule, $content_data)
{
    // Calculate due date if specified
    $due_date = null;
    if (!empty($content_data['due_days'])) {
        $due_date = date('Y-m-d H:i:s', strtotime($schedule['scheduled_publish_date'] . " + {$content_data['due_days']} days"));
    }

    // Insert quiz
    $sql = "INSERT INTO quizzes (
                class_id, instructor_id, title, description, instructions,
                quiz_type, total_points, time_limit, attempts_allowed,
                shuffle_questions, shuffle_options, show_correct_answers,
                available_from, due_date, is_published, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

    $stmt = $conn->prepare($sql);

    $quiz_type = $content_data['quiz_type'] ?? 'graded';
    $time_limit = $content_data['time_limit'] ?? 0;
    $attempts_allowed = $content_data['attempts_allowed'] ?? 1;
    $shuffle_questions = $content_data['shuffle_questions'] ?? 0;
    $shuffle_options = $content_data['shuffle_options'] ?? 0;
    $show_correct_answers = $content_data['show_correct_answers'] ?? 1;

    $stmt->bind_param(
        "iissssdisiiiss",
        $schedule['class_id'],
        $schedule['instructor_id'],
        $schedule['title'],
        $schedule['description'],
        $content_data['instructions'] ?? '',
        $quiz_type,
        $content_data['total_points'] ?? 100,
        $time_limit,
        $attempts_allowed,
        $shuffle_questions,
        $shuffle_options,
        $show_correct_answers,
        $schedule['scheduled_publish_date'],
        $due_date
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert quiz: " . $stmt->error);
    }

    $quiz_id = $stmt->insert_id;
    $stmt->close();

    // If quiz has questions in content_data, insert them
    if (!empty($content_data['questions'])) {
        importQuizQuestions($conn, $quiz_id, $content_data['questions']);
    }

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
