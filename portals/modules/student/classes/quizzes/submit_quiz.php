<?php
// modules/student/quizzes/submit_quiz.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get POST data
$attempt_id = isset($_POST['attempt_id']) ? (int)$_POST['attempt_id'] : 0;
$quiz_id = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
$time_taken = isset($_POST['time_taken']) ? (int)$_POST['time_taken'] : 0;

// Verify attempt belongs to student
$sql = "SELECT a.*, q.total_points, q.quiz_type, q.show_correct_answers, q.auto_submit 
        FROM quiz_attempts a 
        JOIN quizzes q ON a.quiz_id = q.id
        WHERE a.id = ? AND a.student_id = ? AND a.status = 'in_progress'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $attempt_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$attempt = $result->fetch_assoc();
$stmt->close();

// Update attempt end time and status
$sql = "UPDATE quiz_attempts SET 
        end_time = NOW(),
        time_taken = ?,
        status = 'submitted',
        auto_submitted = ?
        WHERE id = ?";
$stmt = $conn->prepare($sql);
$auto_submitted = isset($_POST['auto_submit']) ? 1 : 0;
$stmt->bind_param("iii", $time_taken, $auto_submitted, $attempt_id);
$stmt->execute();
$stmt->close();

// Process answers
$answers = $_POST['answers'] ?? [];
$file_answers = $_FILES['file_answers'] ?? [];
$total_score = 0;

foreach ($answers as $question_id => $answer_data) {
    // Get question details
    $question_sql = "SELECT id, question_type, points FROM quiz_questions WHERE id = ?";
    $stmt = $conn->prepare($question_sql);
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $question_result = $stmt->get_result();

    if ($question_result->num_rows > 0) {
        $question = $question_result->fetch_assoc();
        $question_type = $question['question_type'];
        $max_points = $question['points'];
        $points_awarded = 0;
        $is_correct = 0;
        $answer_text = '';
        $file_path = null;

        // Process based on question type
        switch ($question_type) {
            case 'multiple_choice':
                $answer_text = is_array($answer_data) ? $answer_data[0] : $answer_data;
                if ($answer_text) {
                    // Check if selected option is correct
                    $option_sql = "SELECT id, is_correct FROM quiz_options 
                                  WHERE id = ? AND question_id = ?";
                    $option_stmt = $conn->prepare($option_sql);
                    $option_stmt->bind_param("ii", $answer_text, $question_id);
                    $option_stmt->execute();
                    $option_result = $option_stmt->get_result();

                    if ($option_result->num_rows > 0) {
                        $option = $option_result->fetch_assoc();
                        $is_correct = $option['is_correct'];
                        $points_awarded = $is_correct ? $max_points : 0;
                    }
                    $option_stmt->close();
                }
                break;

            case 'multiple_select':
                if (is_array($answer_data)) {
                    $selected_options = array_filter($answer_data);
                    $answer_text = implode(',', $selected_options);

                    // Get all correct options for this question
                    $correct_sql = "SELECT COUNT(*) as total_correct FROM quiz_options 
                                   WHERE question_id = ? AND is_correct = 1";
                    $correct_stmt = $conn->prepare($correct_sql);
                    $correct_stmt->bind_param("i", $question_id);
                    $correct_stmt->execute();
                    $correct_result = $correct_stmt->get_result();
                    $total_correct = $correct_result->fetch_assoc()['total_correct'];
                    $correct_stmt->close();

                    if ($total_correct > 0 && !empty($selected_options)) {
                        // Check each selected option
                        $correct_count = 0;
                        $wrong_count = 0;

                        foreach ($selected_options as $option_id) {
                            $option_sql = "SELECT is_correct FROM quiz_options WHERE id = ?";
                            $option_stmt = $conn->prepare($option_sql);
                            $option_stmt->bind_param("i", $option_id);
                            $option_stmt->execute();
                            $option_result = $option_stmt->get_result();

                            if ($option_result->num_rows > 0) {
                                $option = $option_result->fetch_assoc();
                                if ($option['is_correct']) {
                                    $correct_count++;
                                } else {
                                    $wrong_count++;
                                }
                            }
                            $option_stmt->close();
                        }

                        // Calculate points (partial credit for some correct)
                        $points_awarded = max(0, ($correct_count - $wrong_count) / $total_correct * $max_points);
                        $is_correct = $correct_count === $total_correct && $wrong_count === 0 ? 1 : 0;
                    }
                }
                break;

            case 'true_false':
                $answer_text = $answer_data;
                $is_correct = 0;

                // Get correct answer from options
                $correct_sql = "SELECT option_text FROM quiz_options 
                               WHERE question_id = ? AND is_correct = 1 LIMIT 1";
                $correct_stmt = $conn->prepare($correct_sql);
                $correct_stmt->bind_param("i", $question_id);
                $correct_stmt->execute();
                $correct_result = $correct_stmt->get_result();

                if ($correct_result->num_rows > 0) {
                    $correct_answer = $correct_result->fetch_assoc()['option_text'];
                    if ($answer_text === $correct_answer) {
                        $is_correct = 1;
                        $points_awarded = $max_points;
                    }
                }
                $correct_stmt->close();
                break;

            case 'short_answer':
            case 'essay':
                $answer_text = trim($answer_data);
                // For essay and short answer, auto-grading is not possible
                // Instructor will grade manually
                $points_awarded = 0;
                $is_correct = 0;
                break;

            case 'file_upload':
                $answer_text = 'file_uploaded';
                // Handle file upload
                if (isset($file_answers['name'][$question_id]) && $file_answers['error'][$question_id] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../../../uploads/quiz_submissions/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $filename = uniqid() . '_' . basename($file_answers['name'][$question_id]);
                    $file_path = $upload_dir . $filename;

                    if (move_uploaded_file($file_answers['tmp_name'][$question_id], $file_path)) {
                        $answer_text = $filename;
                    }
                }
                $points_awarded = 0; // Manual grading
                break;

            case 'dropdown':
                $answer_text = $answer_data;
                if ($answer_text) {
                    $option_sql = "SELECT is_correct FROM quiz_options WHERE id = ?";
                    $option_stmt = $conn->prepare($option_sql);
                    $option_stmt->bind_param("i", $answer_text);
                    $option_stmt->execute();
                    $option_result = $option_stmt->get_result();

                    if ($option_result->num_rows > 0) {
                        $option = $option_result->fetch_assoc();
                        $is_correct = $option['is_correct'];
                        $points_awarded = $is_correct ? $max_points : 0;
                    }
                    $option_stmt->close();
                }
                break;

            case 'fill_blanks':
                if (is_array($answer_data)) {
                    $answer_text = implode('|', array_map('trim', $answer_data));
                    $correct_count = 0;
                    $total_blanks = 0;

                    // Get correct answers
                    $correct_sql = "SELECT option_text FROM quiz_options WHERE question_id = ?";
                    $correct_stmt = $conn->prepare($correct_sql);
                    $correct_stmt->bind_param("i", $question_id);
                    $correct_stmt->execute();
                    $correct_result = $correct_stmt->get_result();
                    $correct_answers = [];

                    while ($row = $correct_result->fetch_assoc()) {
                        $correct_answers[] = strtolower(trim($row['option_text']));
                    }
                    $correct_stmt->close();

                    $total_blanks = count($correct_answers);

                    // Compare answers (case-insensitive)
                    for ($i = 0; $i < min(count($answer_data), $total_blanks); $i++) {
                        if (isset($correct_answers[$i]) && strtolower(trim($answer_data[$i])) === $correct_answers[$i]) {
                            $correct_count++;
                        }
                    }

                    if ($total_blanks > 0) {
                        $points_awarded = ($correct_count / $total_blanks) * $max_points;
                        $is_correct = $correct_count === $total_blanks ? 1 : 0;
                    }
                }
                break;

            case 'ordering':
                if (is_array($answer_data)) {
                    $answer_text = implode(',', $answer_data);
                    $correct_order_sql = "SELECT id FROM quiz_options WHERE question_id = ? ORDER BY order_number";
                    $order_stmt = $conn->prepare($correct_order_sql);
                    $order_stmt->bind_param("i", $question_id);
                    $order_stmt->execute();
                    $order_result = $order_stmt->get_result();
                    $correct_order = [];

                    while ($row = $order_result->fetch_assoc()) {
                        $correct_order[] = $row['id'];
                    }
                    $order_stmt->close();

                    // Check if order matches
                    $is_correct = 1;
                    for ($i = 0; $i < min(count($answer_data), count($correct_order)); $i++) {
                        if ($answer_data[$i] != $correct_order[$i]) {
                            $is_correct = 0;
                            break;
                        }
                    }

                    $points_awarded = $is_correct ? $max_points : 0;
                }
                break;

            case 'matching':
                if (is_array($answer_data)) {
                    $answer_text = json_encode($answer_data);
                    $correct_count = 0;
                    $total_matches = 0;

                    // Get all correct matches
                    $matches_sql = "SELECT id, match_text FROM quiz_options WHERE question_id = ? AND match_text IS NOT NULL";
                    $match_stmt = $conn->prepare($matches_sql);
                    $match_stmt->bind_param("i", $question_id);
                    $match_stmt->execute();
                    $match_result = $match_stmt->get_result();
                    $correct_matches = [];

                    while ($row = $match_result->fetch_assoc()) {
                        $correct_matches[$row['id']] = $row['match_text'];
                    }
                    $match_stmt->close();

                    $total_matches = count($correct_matches);

                    // Check each match
                    foreach ($answer_data as $item_id => $match_id) {
                        if ($match_id && isset($correct_matches[$match_id])) {
                            // Get the match text for this item
                            $item_sql = "SELECT match_text FROM quiz_options WHERE id = ?";
                            $item_stmt = $conn->prepare($item_sql);
                            $item_stmt->bind_param("i", $item_id);
                            $item_stmt->execute();
                            $item_result = $item_stmt->get_result();

                            if ($item_result->num_rows > 0) {
                                $item = $item_result->fetch_assoc();
                                if ($item['match_text'] === $correct_matches[$match_id]) {
                                    $correct_count++;
                                }
                            }
                            $item_stmt->close();
                        }
                    }

                    if ($total_matches > 0) {
                        $points_awarded = ($correct_count / $total_matches) * $max_points;
                        $is_correct = $correct_count === $total_matches ? 1 : 0;
                    }
                }
                break;
        }

        // Save answer
        $answer_sql = "INSERT INTO quiz_answers (attempt_id, question_id, answer_text, answer_file, 
                      points_awarded, max_points, is_correct) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        $answer_stmt = $conn->prepare($answer_sql);
        $answer_stmt->bind_param(
            "iissddi",
            $attempt_id,
            $question_id,
            $answer_text,
            $file_path,
            $points_awarded,
            $max_points,
            $is_correct
        );
        $answer_stmt->execute();
        $answer_id = $answer_stmt->insert_id;
        $answer_stmt->close();

        // Save selected options for multiple choice/select
        if (in_array($question_type, ['multiple_choice', 'multiple_select', 'dropdown']) && !empty($answer_text)) {
            $selected_options = is_array($answer_data) ? $answer_data : [$answer_data];
            foreach ($selected_options as $option_id) {
                if (!empty($option_id)) {
                    $option_sql = "INSERT INTO quiz_answer_options (answer_id, option_id) VALUES (?, ?)";
                    $option_stmt = $conn->prepare($option_sql);
                    $option_stmt->bind_param("ii", $answer_id, $option_id);
                    $option_stmt->execute();
                    $option_stmt->close();
                }
            }
        }

        $total_score += $points_awarded;
    }
    $stmt->close();
}

// Update total score
$percentage = $attempt['total_points'] > 0 ? ($total_score / $attempt['total_points'] * 100) : 0;

$update_sql = "UPDATE quiz_attempts SET 
               total_score = ?,
               max_score = ?,
               percentage = ?,
               status = 'graded'
               WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("dddi", $total_score, $attempt['total_points'], $percentage, $attempt_id);
$update_stmt->execute();
$update_stmt->close();

// Log activity
logActivity('quiz_submitted', "Submitted quiz attempt: {$attempt_id}", 'quiz_attempts', $attempt_id);

$conn->close();

// Redirect to results page
header("Location: quiz_results.php?attempt_id=" . $attempt_id);
exit();
