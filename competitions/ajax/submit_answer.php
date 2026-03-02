<?php
// ajax/submit_answer.php - DEBUG VERSION
require_once '../includes/functions.php';

// Set header to return JSON
header('Content-Type: application/json');

// Debug: Collect all information
$debug = [
    'time' => date('Y-m-d H:i:s'),
    'post_data' => $_POST,
    'session' => $_SESSION,
    'session_status' => session_status(),
    'student_logged_in' => false,
    'steps' => []
];

$debug['steps'][] = 'Script started';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    $debug['steps'][] = 'Student not logged in';
    echo json_encode([
        'success' => false,
        'error' => 'Not logged in',
        'debug' => $debug
    ]);
    exit;
}

$debug['student_logged_in'] = true;
$debug['steps'][] = 'Student is logged in: ID=' . $_SESSION['student_id'];

// Get POST data
$question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
$answer = isset($_POST['answer']) ? strtoupper(trim($_POST['answer'])) : '';
$time_taken = isset($_POST['time_taken']) ? floatval($_POST['time_taken']) : 0;

$debug['steps'][] = "Received: question_id=$question_id, answer=$answer, time_taken=$time_taken";

// Validate inputs
if (!$question_id) {
    $debug['steps'][] = 'Error: No question ID';
    echo json_encode(['success' => false, 'error' => 'No question ID', 'debug' => $debug]);
    exit;
}

if (!in_array($answer, ['A', 'B', 'C', 'D'])) {
    $debug['steps'][] = 'Error: Invalid answer';
    echo json_encode(['success' => false, 'error' => 'Invalid answer', 'debug' => $debug]);
    exit;
}

$student_id = $_SESSION['student_id'];

// Check database connection
if (!$conn) {
    $debug['steps'][] = 'Error: Database connection failed';
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'debug' => $debug]);
    exit;
}

$debug['steps'][] = 'Database connected';

// Check if student exists
$student_check = $conn->query("SELECT id FROM students WHERE id = $student_id");
if ($student_check->num_rows == 0) {
    $debug['steps'][] = 'Error: Student not found in database';
    echo json_encode(['success' => false, 'error' => 'Student not found', 'debug' => $debug]);
    exit;
}

$debug['steps'][] = 'Student exists in database';

// Check if question exists
$question_sql = "SELECT * FROM questions WHERE id = $question_id";
$question_result = $conn->query($question_sql);

if ($question_result->num_rows == 0) {
    $debug['steps'][] = 'Error: Question not found';
    echo json_encode(['success' => false, 'error' => 'Question not found', 'debug' => $debug]);
    exit;
}

$question = $question_result->fetch_assoc();
$correct_answer = $question['correct_option'];
$debug['steps'][] = "Question found: correct_answer=$correct_answer";

// Check if student already answered this question
$check_sql = "SELECT id FROM student_answers WHERE student_id = $student_id AND question_id = $question_id";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    $debug['steps'][] = 'Student already answered this question';
    echo json_encode([
        'success' => false,
        'error' => 'You have already answered this question',
        'already_answered' => true,
        'debug' => $debug
    ]);
    exit;
}

$debug['steps'][] = 'Student has not answered this question yet';

// Determine if answer is correct
$is_correct = ($answer == $correct_answer) ? 1 : 0;
$debug['steps'][] = "Answer is " . ($is_correct ? "CORRECT" : "INCORRECT");

// Calculate points (simplified for now)
$points_earned = 0;
if ($is_correct) {
    // Simple calculation: faster = more points
    $points_earned = round(max(0, 100 - ($time_taken * 10)), 2);
    $debug['steps'][] = "Points calculated: $points_earned (time_taken=$time_taken)";
}

// Insert answer
$insert_sql = "INSERT INTO student_answers 
               (student_id, question_id, answer, is_correct, time_taken, points_earned, submission_time) 
               VALUES 
               ($student_id, $question_id, '$answer', $is_correct, $time_taken, $points_earned, NOW())";

$debug['steps'][] = "Insert SQL: $insert_sql";

if ($conn->query($insert_sql)) {
    $answer_id = $conn->insert_id;
    $debug['steps'][] = "INSERT SUCCESSFUL! Answer ID: $answer_id";

    // Update student's last activity
    $conn->query("UPDATE students SET last_activity = NOW() WHERE id = $student_id");
    $debug['steps'][] = "Student activity updated";

    echo json_encode([
        'success' => true,
        'correct' => $is_correct == 1,
        'correct_answer' => $correct_answer,
        'points_earned' => $points_earned,
        'time_taken' => $time_taken,
        'answer_id' => $answer_id,
        'debug' => $debug
    ]);
} else {
    $debug['steps'][] = "INSERT FAILED: " . $conn->error;
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $conn->error,
        'debug' => $debug
    ]);
}
