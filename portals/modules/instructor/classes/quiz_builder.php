<?php
// modules/instructor/classes/quiz_builder.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get class ID and quiz ID from URL
if (
    !isset($_GET['class_id']) || !is_numeric($_GET['class_id']) ||
    !isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])
) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];
$quiz_id = (int)$_GET['quiz_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify instructor has access to this quiz
$sql = "SELECT q.*, cb.batch_code, cb.name as class_name
        FROM quizzes q 
        JOIN class_batches cb ON q.class_id = cb.id
        WHERE q.id = ? AND q.instructor_id = ? AND q.class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $quiz_id, $instructor_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: quizzes.php?class_id=' . $class_id);
    exit();
}

$quiz = $result->fetch_assoc();
$stmt->close();

// Variables for question editing
$edit_question_id = null;
$edit_question_data = null;
$edit_options_data = [];

// Variables for quiz settings update
$quiz_update_success = false;
$quiz_update_error = '';
$quiz_success_message = '';

// Handle quiz settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_quiz_settings') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $quiz_type = $_POST['quiz_type'] ?? 'graded';
    $total_points = floatval($_POST['total_points'] ?? 100);
    $time_limit = intval($_POST['time_limit'] ?? 0);
    $attempts_allowed = intval($_POST['attempts_allowed'] ?? 1);
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_options = isset($_POST['shuffle_options']) ? 1 : 0;
    $show_correct_answers = isset($_POST['show_correct_answers']) ? 1 : 0;
    $show_points = isset($_POST['show_points']) ? 1 : 0;
    $available_from = !empty($_POST['available_from']) ? $_POST['available_from'] : null;
    $available_to = !empty($_POST['available_to']) ? $_POST['available_to'] : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $auto_submit = isset($_POST['auto_submit']) ? 1 : 0;
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    // New question selection fields
    $question_selection_method = $_POST['question_selection_method'] ?? 'all';
    $questions_to_show = !empty($_POST['questions_to_show']) ? intval($_POST['questions_to_show']) : null;
    $questions_percentage = !empty($_POST['questions_percentage']) ? floatval($_POST['questions_percentage']) : null;
    $randomize_per_student = isset($_POST['randomize_per_student']) ? 1 : 0;

    // Validate input
    if (empty($title)) {
        $quiz_update_error = "Quiz title is required";
    } else {
        // Get total questions count for validation
        $count_sql = "SELECT COUNT(*) as total FROM quiz_questions WHERE quiz_id = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param("i", $quiz_id);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_questions = $count_result->fetch_assoc()['total'] ?? 0;
        $count_stmt->close();

        // Validate question selection based on total questions
        if ($question_selection_method === 'random_count' && $questions_to_show > $total_questions) {
            $quiz_update_error = "Number of questions to show cannot exceed total questions ($total_questions)";
        } elseif ($question_selection_method === 'random_count' && $questions_to_show < 1) {
            $quiz_update_error = "Number of questions to show must be at least 1";
        } elseif ($question_selection_method === 'random_percentage' && ($questions_percentage < 1 || $questions_percentage > 100)) {
            $quiz_update_error = "Percentage must be between 1 and 100";
        } else {
            // Update quiz settings with new fields
            $sql = "UPDATE quizzes SET 
                    title = ?, 
                    description = ?, 
                    instructions = ?, 
                    quiz_type = ?, 
                    total_points = ?, 
                    time_limit = ?, 
                    attempts_allowed = ?, 
                    shuffle_questions = ?, 
                    shuffle_options = ?, 
                    show_correct_answers = ?, 
                    show_points = ?, 
                    available_from = ?, 
                    available_to = ?, 
                    due_date = ?, 
                    auto_submit = ?, 
                    is_published = ?,
                    question_selection_method = ?,
                    questions_to_show = ?,
                    questions_percentage = ?,
                    randomize_per_student = ?,
                    updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ? AND instructor_id = ? AND class_id = ?";

            $stmt = $conn->prepare($sql);

            // Fix: Count the parameters correctly - we have 23 parameters (21 values + 3 conditions)
            $stmt->bind_param(
                "ssssdiiiisssssiiiisssiiii",
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
                $show_points,
                $available_from,
                $available_to,
                $due_date,
                $auto_submit,
                $is_published,
                $question_selection_method,
                $questions_to_show,
                $questions_percentage,
                $randomize_per_student,
                $quiz_id,
                $instructor_id,
                $class_id
            );

            if ($stmt->execute()) {
                $quiz_update_success = true;
                $quiz_success_message = "Quiz settings updated successfully!";

                // Update local quiz data
                $quiz['title'] = $title;
                $quiz['description'] = $description;
                $quiz['instructions'] = $instructions;
                $quiz['quiz_type'] = $quiz_type;
                $quiz['total_points'] = $total_points;
                $quiz['time_limit'] = $time_limit;
                $quiz['attempts_allowed'] = $attempts_allowed;
                $quiz['shuffle_questions'] = $shuffle_questions;
                $quiz['shuffle_options'] = $shuffle_options;
                $quiz['show_correct_answers'] = $show_correct_answers;
                $quiz['show_points'] = $show_points;
                $quiz['available_from'] = $available_from;
                $quiz['available_to'] = $available_to;
                $quiz['due_date'] = $due_date;
                $quiz['auto_submit'] = $auto_submit;
                $quiz['is_published'] = $is_published;
                $quiz['question_selection_method'] = $question_selection_method;
                $quiz['questions_to_show'] = $questions_to_show;
                $quiz['questions_percentage'] = $questions_percentage;
                $quiz['randomize_per_student'] = $randomize_per_student;

                // Log activity
                logActivity('quiz_settings_updated', "Updated settings for quiz: $title", 'quizzes', $quiz_id);
            } else {
                $quiz_update_error = "Failed to update quiz settings: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Handle question creation/editing
$question_success = false;
$question_error = '';
$question_success_message = '';

// First check if we're in edit mode (from GET parameter)
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_question_id = (int)$_GET['edit'];

    // Load question data
    $sql = "SELECT * FROM quiz_questions WHERE id = ? AND quiz_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $edit_question_id, $quiz_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $edit_question_data = $result->fetch_assoc();

        // Load options for this question
        $options_sql = "SELECT * FROM quiz_options WHERE question_id = ? ORDER BY order_number";
        $options_stmt = $conn->prepare($options_sql);
        $options_stmt->bind_param("i", $edit_question_id);
        $options_stmt->execute();
        $options_result = $options_stmt->get_result();
        $edit_options_data = $options_result->fetch_all(MYSQLI_ASSOC);
        $options_stmt->close();
    } else {
        $question_error = "Question not found or you don't have permission to edit it.";
    }
    $stmt->close();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Edit question
    if (isset($_POST['action']) && $_POST['action'] === 'edit_question') {
        $edit_question_id = intval($_POST['edit_question_id'] ?? 0);
        $question_type = $_POST['question_type'] ?? 'multiple_choice';
        $question_text = trim($_POST['question_text'] ?? '');
        $points = floatval($_POST['points'] ?? 1.0);
        $required = isset($_POST['required']) ? 1 : 0;
        $explanation = trim($_POST['explanation'] ?? '');

        // Validate
        if (empty($question_text)) {
            $question_error = "Question text is required";
        } elseif ($edit_question_id === 0) {
            $question_error = "Invalid question ID";
        } else {
            // Update question
            $sql = "UPDATE quiz_questions 
                    SET question_type = ?, question_text = ?, points = ?, required = ?, explanation = ? 
                    WHERE id = ? AND quiz_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssdisii", $question_type, $question_text, $points, $required, $explanation, $edit_question_id, $quiz_id);

            if ($stmt->execute()) {
                // Delete existing options
                $delete_sql = "DELETE FROM quiz_options WHERE question_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $edit_question_id);
                $delete_stmt->execute();
                $delete_stmt->close();

                // Insert new options based on question type
                if (in_array($question_type, ['multiple_choice', 'multiple_select', 'true_false', 'dropdown', 'matching', 'ordering'])) {
                    $options = $_POST['options'] ?? [];
                    $correct_options = $_POST['correct_options'] ?? [];

                    foreach ($options as $index => $option_text) {
                        if (!empty(trim($option_text))) {
                            $is_correct = 0;

                            if ($question_type === 'multiple_choice' || $question_type === 'true_false' || $question_type === 'dropdown') {
                                $is_correct = (isset($_POST['correct_option']) && $_POST['correct_option'] == $index) ? 1 : 0;
                            } elseif ($question_type === 'multiple_select') {
                                $is_correct = in_array($index, $correct_options) ? 1 : 0;
                            } elseif ($question_type === 'ordering') {
                                $is_correct = 1; // All options are correct in ordering
                            } elseif ($question_type === 'matching') {
                                $is_correct = 1; // All matching pairs are correct
                            }

                            $match_text = $_POST['match_texts'][$index] ?? null;

                            $option_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, match_text, order_number) 
                                          VALUES (?, ?, ?, ?, ?)";
                            $option_stmt = $conn->prepare($option_sql);
                            $option_stmt->bind_param("isisi", $edit_question_id, $option_text, $is_correct, $match_text, $index);
                            $option_stmt->execute();
                            $option_stmt->close();
                        }
                    }
                }

                // Handle fill in the blanks
                if ($question_type === 'fill_blanks') {
                    $blanks = $_POST['blanks'] ?? [];
                    foreach ($blanks as $index => $blank) {
                        if (!empty(trim($blank))) {
                            $option_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, order_number) 
                                          VALUES (?, ?, 1, ?)";
                            $option_stmt = $conn->prepare($option_sql);
                            $option_stmt->bind_param("isi", $edit_question_id, $blank, $index);
                            $option_stmt->execute();
                            $option_stmt->close();
                        }
                    }
                }

                $question_success = true;
                $question_success_message = "Question updated successfully!";
                logActivity('quiz_question_updated', "Updated question ID: $edit_question_id", 'quiz_questions', $edit_question_id);

                // Clear edit data after successful update
                $edit_question_id = null;
                $edit_question_data = null;
                $edit_options_data = [];

                // Redirect to clear POST data
                header("Location: ?class_id=$class_id&quiz_id=$quiz_id&success=1");
                exit();
            } else {
                $question_error = "Failed to update question: " . $conn->error;
            }
            $stmt->close();
        }
    }
    // Add new question
    elseif (isset($_POST['action']) && $_POST['action'] === 'add_question') {
        $question_type = $_POST['question_type'] ?? 'multiple_choice';
        $question_text = trim($_POST['question_text'] ?? '');
        $points = floatval($_POST['points'] ?? 1.0);
        $required = isset($_POST['required']) ? 1 : 0;
        $explanation = trim($_POST['explanation'] ?? '');

        if (empty($question_text)) {
            $question_error = "Question text is required";
        } else {
            // Insert question
            $sql = "INSERT INTO quiz_questions (quiz_id, question_type, question_text, points, required, explanation) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issdis", $quiz_id, $question_type, $question_text, $points, $required, $explanation);

            if ($stmt->execute()) {
                $question_id = $stmt->insert_id;
                $question_success = true;
                $question_success_message = "Question added successfully!";

                // Handle options based on question type
                if (in_array($question_type, ['multiple_choice', 'multiple_select', 'true_false', 'dropdown', 'matching', 'ordering'])) {
                    $options = $_POST['options'] ?? [];
                    $correct_options = $_POST['correct_options'] ?? [];

                    foreach ($options as $index => $option_text) {
                        if (!empty(trim($option_text))) {
                            $is_correct = 0;

                            if ($question_type === 'multiple_choice' || $question_type === 'true_false' || $question_type === 'dropdown') {
                                $is_correct = (isset($_POST['correct_option']) && $_POST['correct_option'] == $index) ? 1 : 0;
                            } elseif ($question_type === 'multiple_select') {
                                $is_correct = in_array($index, $correct_options) ? 1 : 0;
                            } elseif ($question_type === 'ordering') {
                                $is_correct = 1; // All options are correct in ordering
                            } elseif ($question_type === 'matching') {
                                $is_correct = 1; // All matching pairs are correct
                            }

                            $match_text = $_POST['match_texts'][$index] ?? null;

                            $option_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, match_text, order_number) 
                                          VALUES (?, ?, ?, ?, ?)";
                            $option_stmt = $conn->prepare($option_sql);
                            $option_stmt->bind_param("isisi", $question_id, $option_text, $is_correct, $match_text, $index);
                            $option_stmt->execute();
                            $option_stmt->close();
                        }
                    }
                }

                // Handle fill in the blanks
                if ($question_type === 'fill_blanks') {
                    $blanks = $_POST['blanks'] ?? [];
                    foreach ($blanks as $index => $blank) {
                        if (!empty(trim($blank))) {
                            $option_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, order_number) 
                                          VALUES (?, ?, 1, ?)";
                            $option_stmt = $conn->prepare($option_sql);
                            $option_stmt->bind_param("isi", $question_id, $blank, $index);
                            $option_stmt->execute();
                            $option_stmt->close();
                        }
                    }
                }

                logActivity('quiz_question_added', "Added question to quiz ID: $quiz_id", 'quiz_questions', $question_id);

                // Redirect to clear POST data
                header("Location: ?class_id=$class_id&quiz_id=$quiz_id&success=1");
                exit();
            } else {
                $question_error = "Failed to add question: " . $conn->error;
            }
            $stmt->close();
        }
    }
    // Handle bulk upload
    elseif (isset($_POST['action']) && $_POST['action'] === 'bulk_upload' && isset($_FILES['bulk_file'])) {
        $file = $_FILES['bulk_file'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $filename = basename($file['name']);
            $upload_path = __DIR__ . '/../../../uploads/quizzes/';

            // Create directory if it doesn't exist
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }

            $filepath = $upload_path . uniqid() . '_' . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Record upload
                $upload_sql = "INSERT INTO quiz_bulk_uploads (quiz_id, instructor_id, filename, file_path, format) 
                              VALUES (?, ?, ?, ?, ?)";
                $upload_stmt = $conn->prepare($upload_sql);
                $format = $_POST['format'] ?? 'auto';
                $upload_stmt->bind_param("iisss", $quiz_id, $instructor_id, $filename, $filepath, $format);
                $upload_stmt->execute();
                $upload_id = $upload_stmt->insert_id;
                $upload_stmt->close();

                // Process the file based on format
                $processed = processBulkUpload($filepath, $quiz_id, $format, $conn);

                if ($processed) {
                    $question_success = true;
                    $question_success_message = "Bulk upload completed successfully! " . $processed . " questions imported.";
                } else {
                    $question_error = "File uploaded but could not be processed. Please check the format.";
                }

                logActivity('quiz_bulk_upload', "Bulk upload for quiz ID: $quiz_id ($processed questions)", 'quiz_bulk_uploads', $upload_id);

                // Redirect to clear POST data
                header("Location: ?class_id=$class_id&quiz_id=$quiz_id&success=1");
                exit();
            } else {
                $question_error = "Failed to upload file";
            }
        } else {
            $question_error = "File upload error: " . $file['error'];
        }
    }
    // Handle question deletion
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_question') {
        $delete_question_id = intval($_POST['question_id'] ?? 0);

        $delete_sql = "DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $delete_question_id, $quiz_id);

        if ($delete_stmt->execute()) {
            $question_success = true;
            $question_success_message = "Question deleted successfully!";
            logActivity('quiz_question_deleted', "Deleted question ID: $delete_question_id", 'quiz_questions', $delete_question_id);

            // Redirect to clear POST data
            header("Location: ?class_id=$class_id&quiz_id=$quiz_id&success=1");
            exit();
        } else {
            $question_error = "Failed to delete question: " . $conn->error;
        }
        $delete_stmt->close();
    }
}

// Get quiz questions
$questions_sql = "SELECT qq.*, COUNT(qo.id) as option_count
                 FROM quiz_questions qq 
                 LEFT JOIN quiz_options qo ON qq.id = qo.question_id
                 WHERE qq.quiz_id = ?
                 GROUP BY qq.id
                 ORDER BY qq.order_number, qq.created_at";
$stmt = $conn->prepare($questions_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions_result = $stmt->get_result();
$questions = $questions_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total points
$total_points_sql = "SELECT SUM(points) as total FROM quiz_questions WHERE quiz_id = ?";
$stmt = $conn->prepare($total_points_sql);
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$total_points_result = $stmt->get_result();
$total_points = $total_points_result->fetch_assoc()['total'] ?? 0;
$stmt->close();

$conn->close();

// Check for success parameter in URL
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $question_success = true;
    $question_success_message = "Operation completed successfully!";
}

// Format dates for HTML input
function formatDateForInput($date)
{
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '';
    }
    return date('Y-m-d\TH:i', strtotime($date));
}

// Function to get question type label
function getQuestionTypeLabel($type)
{
    $labels = [
        'multiple_choice' => 'Multiple Choice',
        'multiple_select' => 'Multiple Select',
        'true_false' => 'True/False',
        'short_answer' => 'Short Answer',
        'essay' => 'Essay',
        'file_upload' => 'File Upload',
        'matching' => 'Matching',
        'ordering' => 'Ordering',
        'dropdown' => 'Dropdown',
        'fill_blanks' => 'Fill in Blanks'
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

// Function to process bulk upload
function processBulkUpload($filepath, $quiz_id, $format, $conn)
{
    $questions_imported = 0;

    try {
        if ($format === 'json' || (pathinfo($filepath, PATHINFO_EXTENSION) === 'json' && $format === 'auto')) {
            // Process JSON file
            $json_content = file_get_contents($filepath);
            $data = json_decode($json_content, true);

            if (isset($data['questions']) && is_array($data['questions'])) {
                foreach ($data['questions'] as $question_data) {
                    if (insertQuestionFromArray($question_data, $quiz_id, $conn)) {
                        $questions_imported++;
                    }
                }
            }
        } elseif ($format === 'csv' || (pathinfo($filepath, PATHINFO_EXTENSION) === 'csv' && $format === 'auto')) {
            // Process CSV file
            if (($handle = fopen($filepath, "r")) !== FALSE) {
                $headers = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== FALSE) {
                    $question_data = array_combine($headers, $row);
                    if (insertQuestionFromArray($question_data, $quiz_id, $conn)) {
                        $questions_imported++;
                    }
                }
                fclose($handle);
            }
        }
    } catch (Exception $e) {
        error_log("Bulk upload error: " . $e->getMessage());
        return false;
    }

    return $questions_imported;
}

// Helper function to insert question from array
function insertQuestionFromArray($data, $quiz_id, $conn)
{
    // Extract data with defaults
    $question_type = $data['question_type'] ?? 'multiple_choice';
    $question_text = trim($data['question_text'] ?? '');
    $points = floatval($data['points'] ?? 1.0);
    $required = isset($data['required']) ? (bool)$data['required'] : true;
    $explanation = trim($data['explanation'] ?? '');
    $options = $data['options'] ?? [];
    $correct_options = $data['correct_options'] ?? [];

    if (empty($question_text)) {
        return false;
    }

    // Insert question
    $sql = "INSERT INTO quiz_questions (quiz_id, question_type, question_text, points, required, explanation) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $required_int = $required ? 1 : 0;
    $stmt->bind_param("issdis", $quiz_id, $question_type, $question_text, $points, $required_int, $explanation);

    if ($stmt->execute()) {
        $question_id = $stmt->insert_id;
        $stmt->close();

        // Handle options if present
        if (!empty($options) && is_array($options)) {
            foreach ($options as $index => $option_text) {
                if (!empty(trim($option_text))) {
                    $is_correct = 0;

                    // Determine if this option is correct
                    if (in_array($index, $correct_options)) {
                        $is_correct = 1;
                    } elseif ($question_type === 'true_false' && $index === 0) {
                        // For true/false, first option (True) is correct by default
                        $is_correct = 1;
                    }

                    $option_sql = "INSERT INTO quiz_options (question_id, option_text, is_correct, order_number) 
                                  VALUES (?, ?, ?, ?)";
                    $option_stmt = $conn->prepare($option_sql);
                    $option_stmt->bind_param("isii", $question_id, $option_text, $is_correct, $index);
                    $option_stmt->execute();
                    $option_stmt->close();
                }
            }
        }

        return true;
    }

    $stmt->close();
    return false;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Builder - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 6px solid var(--primary);
        }

        .header h1 {
            font-size: 1.75rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .class-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-upcoming {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Settings Container */
        .settings-container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 2rem;
        }

        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .settings-tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 2rem;
            padding-bottom: 0.5rem;
        }

        .settings-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--gray);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .settings-tab:hover {
            color: var(--primary);
        }

        .settings-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .settings-section {
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .settings-section:last-child {
            border-bottom: none;
        }

        .settings-section h3 {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .settings-section h3 i {
            color: var(--primary);
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .settings-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        /* Builder Container */
        .builder-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            height: fit-content;
            position: sticky;
            top: 1rem;
        }

        .quiz-info-card {
            background: #f0f9ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #bae6fd;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0d9c6e;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: white;
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-group label.required::after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-check input {
            width: auto;
        }

        .form-check label {
            margin-bottom: 0;
        }

        .form-help {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Question Tabs */
        .question-tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 2rem;
            padding-bottom: 0.5rem;
        }

        .question-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--gray);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .question-tab:hover {
            color: var(--primary);
        }

        .question-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Questions List */
        .questions-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .question-preview {
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .question-preview:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .question-preview.active {
            background: #eff6ff;
            border-color: var(--primary);
        }

        .question-type-icon {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            margin-right: 0.5rem;
        }

        .builder-main {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .option-input-group {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            align-items: center;
        }

        .bulk-upload-section {
            margin-top: 2rem;
        }

        .modal-footer {
            padding: 1rem 0;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .tab-content {
            display: block;
        }

        .section-content {
            display: block;
        }

        /* Drop Zone */
        .drop-zone {
            border: 2px dashed #e2e8f0;
            border-radius: 8px;
            padding: 3rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .drop-zone:hover {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .drop-zone.dragover {
            border-color: var(--success);
            background: #f0fdf4;
        }

        .file-info {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 1rem;
            display: none;
        }

        .upload-progress {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: #f1f5f9;
            border-radius: 6px;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Template Links */
        .template-links {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .template-links h4 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        /* Edit mode styling */
        .edit-mode {
            border-left: 4px solid var(--warning);
            background: #fef3c7 !important;
        }

        .edit-mode-header {
            background: var(--warning);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px 6px 0 0;
            margin: -2rem -2rem 1rem -2rem;
        }

        .question-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .edit-btn {
            background: #fef3c7;
            color: #92400e;
            border-color: #f59e0b;
        }

        .edit-btn:hover {
            background: #fcd34d;
        }

        .delete-btn {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
        }

        .delete-btn:hover {
            background: #fecaca;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($quiz['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <a href="quizzes.php?class_id=<?php echo $class_id; ?>">
                Quizzes
            </a>
            <span class="separator">/</span>
            <span>Builder: <?php echo htmlspecialchars($quiz['title']); ?></span>
        </div>

        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-tools"></i> Quiz Builder
            </h1>
            <p>Manage questions and settings for <?php echo htmlspecialchars($quiz['title']); ?></p>

            <div class="class-info">
                <div>
                    <span class="status-badge <?php echo $quiz['is_published'] ? 'status-upcoming' : 'status-draft'; ?>">
                        <?php echo $quiz['is_published'] ? 'Published' : 'Draft'; ?>
                    </span>
                    <span style="margin-left: 1rem; color: var(--gray);">
                        <i class="fas fa-star"></i> <?php echo $total_points; ?>/<?php echo $quiz['total_points']; ?> points
                    </span>
                    <span style="margin-left: 1rem; color: var(--gray);">
                        <i class="fas fa-list-ol"></i> <?php echo count($questions); ?> questions
                    </span>
                </div>
                <a href="#quizSettings" class="btn btn-secondary" onclick="showSection('settings'); return false;">
                    <i class="fas fa-cog"></i> Quiz Settings
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($quiz_update_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong><?php echo $quiz_success_message; ?></strong>
                </div>
            </div>
        <?php elseif ($quiz_update_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Quiz Settings Error!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($quiz_update_error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($question_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong><?php echo $question_success_message; ?></strong>
                </div>
            </div>
        <?php elseif ($question_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Question Error!</strong>
                    <p style="margin-top: 0.25rem;"><?php echo htmlspecialchars($question_error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <button type="button" class="settings-tab active" onclick="showSection('questions')">
                <i class="fas fa-question-circle"></i> Questions
            </button>
            <button type="button" class="settings-tab" onclick="showSection('settings')">
                <i class="fas fa-cog"></i> Quiz Settings
            </button>
        </div>

        <!-- Questions Section -->
        <div id="questionsSection" class="section-content">
            <div class="builder-container">
                <!-- Sidebar -->
                <div class="sidebar">
                    <div class="quiz-info-card">
                        <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                        <div style="font-size: 0.875rem; color: var(--gray); margin-bottom: 0.5rem;">
                            <i class="fas fa-list-ol"></i> <?php echo count($questions); ?> questions
                        </div>
                        <div style="font-size: 0.875rem; color: var(--gray);">
                            <i class="fas fa-star"></i> <?php echo $total_points; ?>/<?php echo $quiz['total_points']; ?> points
                        </div>
                    </div>

                    <h3 style="margin-bottom: 1rem; font-size: 1rem;">Questions</h3>
                    <div class="questions-list">
                        <?php if (empty($questions)): ?>
                            <div class="empty-state" style="padding: 1rem;">
                                <i class="fas fa-question-circle"></i>
                                <p style="font-size: 0.875rem;">No questions yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-preview <?php echo ($edit_question_id === $question['id']) ? 'active edit-mode' : ''; ?>"
                                    onclick="loadQuestionForEdit(<?php echo $question['id']; ?>)">
                                    <div style="display: flex; align-items: center; margin-bottom: 0.25rem;">
                                        <span class="question-type-icon">
                                            <?php echo substr(strtoupper(getQuestionTypeLabel($question['question_type'])), 0, 1); ?>
                                        </span>
                                        <span style="font-weight: 500; font-size: 0.875rem;">
                                            Q<?php echo $index + 1; ?>
                                        </span>
                                        <span style="margin-left: auto; font-size: 0.75rem; color: var(--success);">
                                            <?php echo $question['points']; ?> pts
                                        </span>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo htmlspecialchars(substr($question['question_text'], 0, 50)); ?>
                                        <?php if (strlen($question['question_text']) > 50): ?>...<?php endif; ?>
                                    </div>
                                    <div class="question-actions">
                                        <a href="?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>&edit=<?php echo $question['id']; ?>"
                                            class="action-btn edit-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" action="" style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="action" value="delete_question">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Main Builder Area -->
                <div class="builder-main <?php echo $edit_question_id ? 'edit-mode' : ''; ?>">
                    <?php if ($edit_question_id && $edit_question_data): ?>
                        <div class="edit-mode-header">
                            <i class="fas fa-edit"></i> Editing Question <?php echo array_search($edit_question_id, array_column($questions, 'id')) + 1; ?>
                        </div>
                    <?php endif; ?>

                    <div class="question-tabs">
                        <button type="button" class="question-tab active" onclick="showTab('manual')">
                            <i class="fas fa-keyboard"></i>
                            <?php echo $edit_question_id ? 'Edit Question' : 'Manual Entry'; ?>
                        </button>
                        <?php if (!$edit_question_id): ?>
                            <button type="button" class="question-tab" onclick="showTab('bulk')">
                                <i class="fas fa-file-upload"></i> Bulk Upload
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Manual Question Entry/Edit -->
                    <div id="manualTab" class="tab-content">
                        <form method="POST" action="" id="questionForm">
                            <input type="hidden" name="action" value="<?php echo $edit_question_id ? 'edit_question' : 'add_question'; ?>">
                            <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                            <?php if ($edit_question_id): ?>
                                <input type="hidden" name="edit_question_id" value="<?php echo $edit_question_id; ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="question_type" class="required">Question Type</label>
                                <select id="question_type" name="question_type" class="form-control" required onchange="updateQuestionForm()">
                                    <option value="multiple_choice" <?php echo ($edit_question_data['question_type'] ?? 'multiple_choice') === 'multiple_choice' ? 'selected' : ''; ?>>Multiple Choice</option>
                                    <option value="multiple_select" <?php echo ($edit_question_data['question_type'] ?? '') === 'multiple_select' ? 'selected' : ''; ?>>Multiple Select (Checkboxes)</option>
                                    <option value="true_false" <?php echo ($edit_question_data['question_type'] ?? '') === 'true_false' ? 'selected' : ''; ?>>True/False</option>
                                    <option value="short_answer" <?php echo ($edit_question_data['question_type'] ?? '') === 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                                    <option value="essay" <?php echo ($edit_question_data['question_type'] ?? '') === 'essay' ? 'selected' : ''; ?>>Essay</option>
                                    <option value="file_upload" <?php echo ($edit_question_data['question_type'] ?? '') === 'file_upload' ? 'selected' : ''; ?>>File Upload</option>
                                    <option value="matching" <?php echo ($edit_question_data['question_type'] ?? '') === 'matching' ? 'selected' : ''; ?>>Matching</option>
                                    <option value="dropdown" <?php echo ($edit_question_data['question_type'] ?? '') === 'dropdown' ? 'selected' : ''; ?>>Dropdown</option>
                                    <option value="fill_blanks" <?php echo ($edit_question_data['question_type'] ?? '') === 'fill_blanks' ? 'selected' : ''; ?>>Fill in the Blanks</option>
                                    <option value="ordering" <?php echo ($edit_question_data['question_type'] ?? '') === 'ordering' ? 'selected' : ''; ?>>Ordering</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="question_text" class="required">Question Text</label>
                                <textarea id="question_text" name="question_text" class="form-control" rows="4" required
                                    placeholder="Enter your question here"><?php echo htmlspecialchars($edit_question_data['question_text'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="points">Points</label>
                                <input type="number" id="points" name="points" class="form-control"
                                    value="<?php echo $edit_question_data['points'] ?? '1.0'; ?>" min="0.1" max="100" step="0.1">
                            </div>

                            <!-- Options Container -->
                            <div id="optionsContainer" style="margin-bottom: 1.5rem; display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                    <label>Options</label>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="addOption()">
                                        <i class="fas fa-plus"></i> Add Option
                                    </button>
                                </div>
                                <div id="optionsList">
                                    <!-- Options will be added here dynamically -->
                                </div>
                            </div>

                            <!-- Fill Blanks Container -->
                            <div id="blanksContainer" style="display: none; margin-bottom: 1.5rem;">
                                <label>Correct Answers (one per blank)</label>
                                <div id="blanksList">
                                    <!-- Blanks will be added here dynamically -->
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="addBlank()" style="margin-top: 0.5rem;">
                                    <i class="fas fa-plus"></i> Add Answer
                                </button>
                            </div>

                            <!-- Matching Container -->
                            <div id="matchingContainer" style="display: none; margin-bottom: 1.5rem;">
                                <label>Matching Pairs</label>
                                <div id="matchingList">
                                    <!-- Matching pairs will be added here dynamically -->
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="addMatchingPair()" style="margin-top: 0.5rem;">
                                    <i class="fas fa-plus"></i> Add Pair
                                </button>
                            </div>

                            <div class="form-group">
                                <div class="form-check">
                                    <input type="checkbox" id="required" name="required" value="1" <?php echo ($edit_question_data['required'] ?? 1) ? 'checked' : ''; ?>>
                                    <label for="required">Required question</label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="explanation">Explanation/Feedback</label>
                                <textarea id="explanation" name="explanation" class="form-control" rows="3"
                                    placeholder="Explanation for correct answer (shown after quiz)"><?php echo htmlspecialchars($edit_question_data['explanation'] ?? ''); ?></textarea>
                            </div>

                            <div class="modal-footer" style="padding: 1rem 0; border-top: none;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?php echo $edit_question_id ? 'Update Question' : 'Add Question'; ?>
                                </button>
                                <?php if ($edit_question_id): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel Edit
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                        <i class="fas fa-redo"></i> Clear
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Bulk Upload Tab -->
                    <?php if (!$edit_question_id): ?>
                        <div id="bulkTab" class="tab-content" style="display: none;">
                            <div class="bulk-upload-section">
                                <form method="POST" action="" enctype="multipart/form-data" id="bulkForm">
                                    <input type="hidden" name="action" value="bulk_upload">
                                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">

                                    <div class="drop-zone" id="dropZone" onclick="document.getElementById('bulk_file').click()">
                                        <div style="font-size: 3rem; color: var(--gray); margin-bottom: 1rem;">
                                            <i class="fas fa-file-import"></i>
                                        </div>
                                        <h3 style="margin-bottom: 0.5rem;">Upload Questions File</h3>
                                        <p style="color: var(--gray); margin-bottom: 1rem;">
                                            Click to select or drag & drop your file here
                                        </p>
                                        <input type="file" id="bulk_file" name="bulk_file"
                                            accept=".csv,.json,.xml,.txt"
                                            style="display: none;"
                                            onchange="handleFileSelect(this)">
                                    </div>

                                    <div id="fileInfo" class="file-info">
                                        <i class="fas fa-file"></i>
                                        <span id="fileName"></span>
                                        (<span id="fileSize"></span>)
                                        <button type="button" onclick="clearFile()" style="margin-left: 1rem; color: var(--danger); background: none; border: none; cursor: pointer;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <div id="uploadProgress" class="upload-progress">
                                        <div>Uploading...</div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" id="progressFill"></div>
                                        </div>
                                    </div>

                                    <div style="font-size: 0.875rem; color: var(--gray); margin-top: 1rem; text-align: center;">
                                        <p><strong>Supported formats:</strong> CSV, JSON, AQXML</p>
                                        <p><strong>Max file size:</strong> 10MB</p>
                                    </div>

                                    <div class="form-group" style="margin-top: 2rem; display: none;" id="formatOptions">
                                        <label>File Format</label>
                                        <select name="format" class="form-control" id="formatSelect">
                                            <option value="auto">Auto-detect</option>
                                            <option value="csv">CSV</option>
                                            <option value="json">JSON</option>
                                            <option value="aqxml">AQXML</option>
                                        </select>
                                    </div>

                                    <div class="modal-footer" style="padding: 1rem 0; border-top: none; display: none;" id="submitSection">
                                        <button type="submit" class="btn btn-primary" id="submitBtn">
                                            <i class="fas fa-file-import"></i> Import Questions
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="clearFile()">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </form>

                                <div class="template-links">
                                    <h4>Download Templates:</h4>
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                        <a href="javascript:void(0)" onclick="downloadTemplate('csv')" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i> CSV Template
                                        </a>
                                        <a href="javascript:void(0)" onclick="downloadTemplate('json')" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i> JSON Template
                                        </a>
                                        <a href="javascript:void(0)" onclick="downloadTemplate('xml')" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i> XML Template
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quiz Settings Section -->
        <div id="settingsSection" class="section-content" style="display: none;">
            <div class="settings-container">
                <div class="settings-header">
                    <h2 style="font-size: 1.5rem;">
                        <i class="fas fa-cog"></i> Quiz Settings
                    </h2>
                    <div class="status-badge <?php echo $quiz['is_published'] ? 'status-upcoming' : 'status-draft'; ?>">
                        <?php echo $quiz['is_published'] ? 'Published' : 'Draft'; ?>
                    </div>
                </div>

                <form method="POST" action="" id="quizSettingsForm">
                    <input type="hidden" name="action" value="update_quiz_settings">
                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                    <!-- Basic Information Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="title" class="required">Quiz Title</label>
                                <input type="text" id="title" name="title" class="form-control"
                                    value="<?php echo htmlspecialchars($quiz['title']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="quiz_type">Quiz Type</label>
                                <select id="quiz_type" name="quiz_type" class="form-control">
                                    <option value="graded" <?php echo $quiz['quiz_type'] === 'graded' ? 'selected' : ''; ?>>Graded Quiz</option>
                                    <option value="practice" <?php echo $quiz['quiz_type'] === 'practice' ? 'selected' : ''; ?>>Practice Quiz</option>
                                    <option value="survey" <?php echo $quiz['quiz_type'] === 'survey' ? 'selected' : ''; ?>>Survey</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"
                                placeholder="Describe the quiz objectives and topics covered"><?php echo htmlspecialchars($quiz['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="instructions">Instructions</label>
                            <textarea id="instructions" name="instructions" class="form-control" rows="4"
                                placeholder="Instructions for students taking the quiz"><?php echo htmlspecialchars($quiz['instructions'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Points & Attempts Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-star"></i> Points & Attempts</h3>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="total_points">Total Points</label>
                                <input type="number" id="total_points" name="total_points" class="form-control"
                                    value="<?php echo $quiz['total_points']; ?>" min="1" max="1000" step="0.5">
                            </div>

                            <div class="form-group">
                                <label for="attempts_allowed">Attempts Allowed</label>
                                <input type="number" id="attempts_allowed" name="attempts_allowed" class="form-control"
                                    value="<?php echo $quiz['attempts_allowed']; ?>" min="1" max="10">
                                <div class="form-help">Set to 1 for single attempt only</div>
                            </div>

                            <div class="form-group">
                                <label for="time_limit">Time Limit (minutes)</label>
                                <input type="number" id="time_limit" name="time_limit" class="form-control"
                                    value="<?php echo $quiz['time_limit']; ?>" min="0" max="300">
                                <div class="form-help">0 = no time limit</div>
                            </div>
                        </div>
                    </div>

                    <!-- Availability & Dates Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-calendar-alt"></i> Availability & Dates</h3>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="available_from">Available From</label>
                                <input type="datetime-local" id="available_from" name="available_from"
                                    class="form-control" value="<?php echo formatDateForInput($quiz['available_from']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="available_to">Available To</label>
                                <input type="datetime-local" id="available_to" name="available_to"
                                    class="form-control" value="<?php echo formatDateForInput($quiz['available_to']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="due_date">Due Date</label>
                                <input type="datetime-local" id="due_date" name="due_date"
                                    class="form-control" value="<?php echo formatDateForInput($quiz['due_date']); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Quiz Behavior Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-sliders-h"></i> Quiz Behavior</h3>
                        <div class="settings-checkbox-grid">
                            <div class="form-check">
                                <input type="checkbox" id="shuffle_questions" name="shuffle_questions" value="1"
                                    <?php echo $quiz['shuffle_questions'] ? 'checked' : ''; ?>>
                                <label for="shuffle_questions">Shuffle Questions</label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="shuffle_options" name="shuffle_options" value="1"
                                    <?php echo $quiz['shuffle_options'] ? 'checked' : ''; ?>>
                                <label for="shuffle_options">Shuffle Options</label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="show_correct_answers" name="show_correct_answers" value="1"
                                    <?php echo $quiz['show_correct_answers'] ? 'checked' : ''; ?>>
                                <label for="show_correct_answers">Show Correct Answers</label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="show_points" name="show_points" value="1"
                                    <?php echo $quiz['show_points'] ? 'checked' : ''; ?>>
                                <label for="show_points">Show Points</label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="auto_submit" name="auto_submit" value="1"
                                    <?php echo $quiz['auto_submit'] ? 'checked' : ''; ?>>
                                <label for="auto_submit">Auto-submit when time expires</label>
                            </div>
                        </div>
                    </div>

                    <!-- Question Selection Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-random"></i> Question Selection</h3>

                        <div class="form-group">
                            <label for="question_selection_method">How many questions should students see?</label>
                            <select id="question_selection_method" name="question_selection_method" class="form-control" onchange="toggleQuestionSelection()">
                                <option value="all" <?php echo ($quiz['question_selection_method'] ?? 'all') === 'all' ? 'selected' : ''; ?>>Show all questions</option>
                                <option value="random_count" <?php echo ($quiz['question_selection_method'] ?? '') === 'random_count' ? 'selected' : ''; ?>>Show random number of questions</option>
                                <option value="random_percentage" <?php echo ($quiz['question_selection_method'] ?? '') === 'random_percentage' ? 'selected' : ''; ?>>Show random percentage of questions</option>
                            </select>
                            <div class="form-help">
                                Total questions available: <strong><?php echo count($questions); ?></strong>
                            </div>
                        </div>

                        <div id="random_count_fields" style="display: <?php echo ($quiz['question_selection_method'] ?? '') === 'random_count' ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="questions_to_show">Number of questions to show</label>
                                <input type="number" id="questions_to_show" name="questions_to_show"
                                    class="form-control" min="1" max="<?php echo count($questions); ?>"
                                    value="<?php echo $quiz['questions_to_show'] ?? min(20, count($questions)); ?>">
                                <div class="form-help">
                                    Enter the number of random questions each student will see (max: <?php echo count($questions); ?>)
                                </div>
                            </div>
                        </div>

                        <div id="random_percentage_fields" style="display: <?php echo ($quiz['question_selection_method'] ?? '') === 'random_percentage' ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="questions_percentage">Percentage of questions to show</label>
                                <input type="number" id="questions_percentage" name="questions_percentage"
                                    class="form-control" min="1" max="100" step="1"
                                    value="<?php echo $quiz['questions_percentage'] ?? 50; ?>">
                                <div class="form-help">
                                    This will show approximately <?php echo round(count($questions) * (($quiz['questions_percentage'] ?? 50) / 100)); ?> questions (<?php echo count($questions); ?> total)
                                </div>
                            </div>
                        </div>

                        <div class="form-check" style="margin-top: 1rem;">
                            <input type="checkbox" id="randomize_per_student" name="randomize_per_student" value="1"
                                <?php echo ($quiz['randomize_per_student'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="randomize_per_student">
                                Different random questions for each student
                            </label>
                            <div class="form-help">
                                If checked, each student will get a different random set of questions. If unchecked, all students will see the same random set.
                            </div>
                        </div>
                    </div>

                    <!-- Publishing Section -->
                    <div class="settings-section">
                        <h3><i class="fas fa-globe"></i> Publishing</h3>
                        <div class="form-check">
                            <input type="checkbox" id="is_published" name="is_published" value="1"
                                <?php echo $quiz['is_published'] ? 'checked' : ''; ?>>
                            <label for="is_published" style="font-weight: 600;">
                                Publish this quiz (make it available to students)
                            </label>
                            <div class="form-help">
                                Unpublished quizzes are only visible to instructors
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="modal-footer" style="padding: 2rem 0 0 0; border-top: 2px solid #f1f5f9;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Quiz Settings
                        </button>
                        <a href="#questions" class="btn btn-secondary" onclick="showSection('questions'); return false;">
                            <i class="fas fa-arrow-left"></i> Back to Questions
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Section navigation
        function showSection(section) {
            // Hide all sections
            document.querySelectorAll('.section-content').forEach(s => {
                s.style.display = 'none';
            });

            // Remove active class from all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected section
            document.getElementById(section + 'Section').style.display = 'block';

            // Add active class to clicked tab
            if (event) {
                event.target.classList.add('active');
            } else {
                // Find the correct tab and activate it
                const tabs = document.querySelectorAll('.settings-tab');
                tabs.forEach(tab => {
                    if (tab.textContent.includes(section === 'settings' ? 'Settings' : 'Questions')) {
                        tab.classList.add('active');
                    }
                });
            }

            // Scroll to top
            window.scrollTo(0, 0);

            // Update hash
            window.location.hash = section === 'settings' ? '#quizSettings' : '';
        }

        // Toggle question selection fields
        function toggleQuestionSelection() {
            const method = document.getElementById('question_selection_method').value;
            const randomCountFields = document.getElementById('random_count_fields');
            const randomPercentageFields = document.getElementById('random_percentage_fields');

            randomCountFields.style.display = 'none';
            randomPercentageFields.style.display = 'none';

            if (method === 'random_count') {
                randomCountFields.style.display = 'block';
            } else if (method === 'random_percentage') {
                randomPercentageFields.style.display = 'block';
            }
        }

        // Hash navigation
        window.addEventListener('DOMContentLoaded', function() {
            if (window.location.hash === '#quizSettings') {
                showSection('settings');
            }

            // Initialize question selection fields
            toggleQuestionSelection();

            // Update percentage preview when input changes
            const percentageInput = document.getElementById('questions_percentage');
            if (percentageInput) {
                percentageInput.addEventListener('input', function() {
                    const totalQuestions = <?php echo count($questions); ?>;
                    const percentage = this.value || 50;
                    const approxQuestions = Math.round(totalQuestions * (percentage / 100));
                    const helpText = this.closest('.form-group').querySelector('.form-help');
                    if (helpText) {
                        helpText.textContent = `This will show approximately ${approxQuestions} questions (${totalQuestions} total)`;
                    }
                });
            }

            const countInput = document.getElementById('questions_to_show');
            if (countInput) {
                countInput.addEventListener('input', function() {
                    const max = <?php echo count($questions); ?>;
                    if (this.value > max) {
                        this.value = max;
                    }
                });
            }
        });

        // Question form functions
        let optionCount = 0;
        let blankCount = 0;
        let matchingCount = 0;

        function showTab(tabName) {
            // Update tabs
            document.querySelectorAll('.question-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'none';
            });

            // Show selected tab
            if (event) event.target.classList.add('active');
            document.getElementById(tabName + 'Tab').style.display = 'block';
        }

        function updateQuestionForm() {
            const questionType = document.getElementById('question_type').value;
            const optionsContainer = document.getElementById('optionsContainer');
            const blanksContainer = document.getElementById('blanksContainer');
            const matchingContainer = document.getElementById('matchingContainer');

            // Reset all containers
            optionsContainer.style.display = 'none';
            blanksContainer.style.display = 'none';
            matchingContainer.style.display = 'none';

            switch (questionType) {
                case 'multiple_choice':
                case 'multiple_select':
                case 'true_false':
                case 'dropdown':
                case 'ordering':
                    optionsContainer.style.display = 'block';
                    if (<?php echo $edit_question_id ? 'false' : 'true'; ?> || optionCount === 0) {
                        initializeOptions();
                    }
                    break;
                case 'fill_blanks':
                    blanksContainer.style.display = 'block';
                    if (<?php echo $edit_question_id ? 'false' : 'true'; ?> || blankCount === 0) {
                        initializeBlanks();
                    }
                    break;
                case 'matching':
                    matchingContainer.style.display = 'block';
                    if (<?php echo $edit_question_id ? 'false' : 'true'; ?> || matchingCount === 0) {
                        initializeMatching();
                    }
                    break;
                default:
                    // For short_answer, essay, file_upload - no special container needed
                    break;
            }
        }

        function initializeOptions() {
            const optionsList = document.getElementById('optionsList');
            optionsList.innerHTML = '';
            optionCount = 0;

            <?php if ($edit_question_id && !empty($edit_options_data)): ?>
                <?php foreach ($edit_options_data as $option): ?>
                    addOption('<?php echo addslashes($option['option_text']); ?>', <?php echo $option['is_correct']; ?>, '<?php echo addslashes($option['match_text'] ?? ''); ?>');
                <?php endforeach; ?>
            <?php else: ?>
                // Add initial options for new questions
                addOption();
                addOption();
                if (document.getElementById('question_type').value === 'true_false') {
                    // For true/false, add both options
                    const optionsList = document.getElementById('optionsList');
                    optionsList.innerHTML = '';
                    addOption('True', 1);
                    addOption('False', 0);
                }
            <?php endif; ?>
        }

        function addOption(optionText = '', isCorrect = 0, matchText = '') {
            const optionsList = document.getElementById('optionsList');
            const questionType = document.getElementById('question_type').value;

            const optionDiv = document.createElement('div');
            optionDiv.className = 'option-input-group';

            let inputType = 'radio';
            let inputName = 'correct_option';
            let isChecked = isCorrect ? 'checked' : '';

            if (questionType === 'multiple_select') {
                inputType = 'checkbox';
                inputName = 'correct_options[]';
            } else if (questionType === 'true_false') {
                // For true/false, the first option (True) is correct by default
                if (optionCount === 0) {
                    isChecked = 'checked';
                }
            } else if (questionType === 'ordering' || questionType === 'matching') {
                // No correct/incorrect input for ordering/matching
                inputType = 'hidden';
            }

            let inputHtml = '';
            if (inputType !== 'hidden') {
                inputHtml = `<input type="${inputType}" name="${inputName}" value="${optionCount}" class="option-checkbox" ${isChecked}>`;
            }

            let matchInputHtml = '';
            if (questionType === 'matching') {
                matchInputHtml = `<input type="text" name="match_texts[]" class="form-control" placeholder="Match text" value="${matchText}">`;
            }

            optionDiv.innerHTML = `
                <input type="text" name="options[]" class="form-control" placeholder="Option ${optionCount + 1}" value="${optionText}" required>
                ${inputHtml}
                ${matchInputHtml}
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOption(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;

            optionsList.appendChild(optionDiv);
            optionCount++;
        }

        function removeOption(button) {
            if (optionCount > 1) {
                button.parentElement.remove();
                optionCount--;
            }
        }

        function initializeBlanks() {
            const blanksList = document.getElementById('blanksList');
            blanksList.innerHTML = '';
            blankCount = 0;

            <?php if ($edit_question_id && !empty($edit_options_data)): ?>
                <?php foreach ($edit_options_data as $blank): ?>
                    addBlank('<?php echo addslashes($blank['option_text']); ?>');
                <?php endforeach; ?>
            <?php else: ?>
                // Add initial blank for new questions
                addBlank();
            <?php endif; ?>
        }

        function addBlank(blankText = '') {
            const blanksList = document.getElementById('blanksList');

            const blankDiv = document.createElement('div');
            blankDiv.className = 'option-input-group';
            blankDiv.innerHTML = `
                <input type="text" name="blanks[]" class="form-control" placeholder="Correct answer ${blankCount + 1}" value="${blankText}" required>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeBlank(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;

            blanksList.appendChild(blankDiv);
            blankCount++;
        }

        function removeBlank(button) {
            if (blankCount > 1) {
                button.parentElement.remove();
                blankCount--;
            }
        }

        function initializeMatching() {
            const matchingList = document.getElementById('matchingList');
            matchingList.innerHTML = '';
            matchingCount = 0;

            <?php if ($edit_question_id && !empty($edit_options_data)): ?>
                <?php foreach ($edit_options_data as $pair): ?>
                    addMatchingPair('<?php echo addslashes($pair['option_text']); ?>', '<?php echo addslashes($pair['match_text'] ?? ''); ?>');
                <?php endforeach; ?>
            <?php else: ?>
                // Add initial pair for new questions
                addMatchingPair();
            <?php endif; ?>
        }

        function addMatchingPair(optionText = '', matchText = '') {
            const matchingList = document.getElementById('matchingList');

            const pairDiv = document.createElement('div');
            pairDiv.className = 'option-input-group';
            pairDiv.innerHTML = `
                <input type="text" name="options[]" class="form-control" placeholder="Item ${matchingCount + 1}" value="${optionText}" required>
                <input type="text" name="match_texts[]" class="form-control" placeholder="Match ${matchingCount + 1}" value="${matchText}" required>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeMatchingPair(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;

            matchingList.appendChild(pairDiv);
            matchingCount++;
        }

        function removeMatchingPair(button) {
            if (matchingCount > 1) {
                button.parentElement.remove();
                matchingCount--;
            }
        }

        function resetForm() {
            document.getElementById('questionForm').reset();
            optionCount = 0;
            blankCount = 0;
            matchingCount = 0;
            updateQuestionForm();
        }

        function loadQuestionForEdit(questionId) {
            window.location.href = `?class_id=<?php echo $class_id; ?>&quiz_id=<?php echo $quiz_id; ?>&edit=${questionId}`;
        }

        // Bulk Upload Functions
        function handleFileSelect(input) {
            const file = input.files[0];
            if (!file) return;

            // Show file info
            document.getElementById('fileInfo').style.display = 'block';
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = formatFileSize(file.size);

            // Show format options and submit button
            document.getElementById('formatOptions').style.display = 'block';
            document.getElementById('submitSection').style.display = 'flex';

            // Auto-detect format
            const ext = file.name.split('.').pop().toLowerCase();
            const formatSelect = document.getElementById('formatSelect');

            if (ext === 'csv') {
                formatSelect.value = 'csv';
            } else if (ext === 'json') {
                formatSelect.value = 'json';
            } else if (ext === 'xml' || ext === 'aqxml') {
                formatSelect.value = 'aqxml';
            } else {
                formatSelect.value = 'auto';
            }

            // Change drop zone style
            document.getElementById('dropZone').classList.add('dragover');
        }

        function clearFile() {
            document.getElementById('bulk_file').value = '';
            document.getElementById('fileInfo').style.display = 'none';
            document.getElementById('formatOptions').style.display = 'none';
            document.getElementById('submitSection').style.display = 'none';
            document.getElementById('dropZone').classList.remove('dragover');
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Drag and drop support
        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('dropZone');
            if (dropZone) {
                dropZone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });

                dropZone.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });

                dropZone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        document.getElementById('bulk_file').files = files;
                        handleFileSelect(document.getElementById('bulk_file'));
                    }
                });
            }

            // Handle form submission with progress
            const bulkForm = document.getElementById('bulkForm');
            if (bulkForm) {
                bulkForm.addEventListener('submit', function(e) {
                    // Show progress bar
                    document.getElementById('uploadProgress').style.display = 'block';
                    const progressFill = document.getElementById('progressFill');

                    // Simulate progress
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 10;
                        progressFill.style.width = progress + '%';
                        if (progress >= 90) clearInterval(interval);
                    }, 100);

                    // Form will submit normally
                });
            }
        });

        // Template downloads
        function downloadTemplate(format) {
            let content = '';
            let filename = '';
            let mimeType = '';

            if (format === 'json') {
                content = JSON.stringify({
                    questions: [{
                            "question_type": "multiple_choice",
                            "question_text": "What is the default file extension for Microsoft Word documents?",
                            "points": 1.0,
                            "options": [".docx", ".txt", ".pdf", ".xlsx"],
                            "correct_options": [0],
                            "explanation": ".docx is the default extension for Word documents since Word 2007.",
                            "required": true
                        },
                        {
                            "question_type": "true_false",
                            "question_text": "In MS Word, you can only undo the last action you performed.",
                            "points": 1.0,
                            "options": ["True", "False"],
                            "correct_options": [1],
                            "explanation": "Word allows multiple undos using Ctrl+Z, typically up to 100 previous actions.",
                            "required": true
                        }
                    ]
                }, null, 2);
                filename = 'word_quiz_template.json';
                mimeType = 'application/json';
            } else if (format === 'xml') {
                content = '<' + '?xml version="1.0" encoding="UTF-8"?>\n' +
                    '<assessment>\n' +
                    '  <question type="multiple_choice">\n' +
                    '    <text>What is the default file extension for Microsoft Word documents?</text>\n' +
                    '    <points>1.0</points>\n' +
                    '    <options>\n' +
                    '      <option correct="true">.docx</option>\n' +
                    '      <option correct="false">.txt</option>\n' +
                    '      <option correct="false">.pdf</option>\n' +
                    '      <option correct="false">.xlsx</option>\n' +
                    '    </options>\n' +
                    '    <feedback>.docx is the default extension for Word documents since Word 2007.</feedback>\n' +
                    '  </question>\n' +
                    '</assessment>';
                filename = 'word_quiz_template.xml';
                mimeType = 'application/xml';
            }

            // Create download link
            const blob = new Blob([content], {
                type: mimeType
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Initialize question form
        document.addEventListener('DOMContentLoaded', function() {
            updateQuestionForm();
        });
    </script>
</body>

</html>