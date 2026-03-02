<?php
// ajax/submit_answer.php
require_once '../includes/functions.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if student is logged in
if (!isStudentLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// Get POST data
$question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
$answer = isset($_POST['answer']) ? strtoupper(trim($_POST['answer'])) : '';
$time_taken = isset($_POST['time_taken']) ? floatval($_POST['time_taken']) : 0;

// Validate inputs
if (!$question_id) {
    echo json_encode(['success' => false, 'error' => 'No question ID']);
    exit;
}

if (!in_array($answer, ['A', 'B', 'C', 'D'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid answer']);
    exit;
}

$student_id = $_SESSION['student_id'];

// Check if student already answered this question
$check_sql = "SELECT id FROM student_answers WHERE student_id = $student_id AND question_id = $question_id";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    echo json_encode([
        'success' => false,
        'error' => 'You have already answered this question',
        'already_answered' => true
    ]);
    exit;
}

// Get question details to check correct answer
$question_sql = "SELECT * FROM questions WHERE id = $question_id";
$question_result = $conn->query($question_sql);

if ($question_result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Question not found']);
    exit;
}

$question = $question_result->fetch_assoc();
$correct_answer = $question['correct_option'];
$is_correct = ($answer == $correct_answer) ? 1 : 0;

// Get question start time from quiz session
$state_sql = "SELECT question_start FROM quiz_sessions ORDER BY id DESC LIMIT 1";
$state_result = $conn->query($state_sql);
$state = $state_result->fetch_assoc();

if (!$state || !$state['question_start']) {
    // If no question_start in session, use current time minus time_taken
    $question_start = date('Y-m-d H:i:s', time() - $time_taken);
} else {
    $question_start = $state['question_start'];
}

// Calculate points using the function from functions.php
$points_earned = 0;
if ($is_correct) {
    $points_earned = calculatePoints($question_start, date('Y-m-d H:i:s'), 100);
}

// Insert answer
$insert_sql = "INSERT INTO student_answers 
               (student_id, question_id, answer, is_correct, time_taken, points_earned, submission_time) 
               VALUES 
               ($student_id, $question_id, '$answer', $is_correct, $time_taken, $points_earned, NOW())";

if ($conn->query($insert_sql)) {
    // Update student's last activity
    $conn->query("UPDATE students SET last_activity = NOW() WHERE id = $student_id");

    // Get the inserted answer ID
    $answer_id = $conn->insert_id;

    echo json_encode([
        'success' => true,
        'correct' => $is_correct == 1,
        'correct_answer' => $correct_answer,
        'points_earned' => $points_earned,
        'time_taken' => $time_taken,
        'answer_id' => $answer_id
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $conn->error
    ]);
}
?><?php
    // ajax/submit_answer.php
    require_once '../includes/functions.php';

    // Set header to return JSON
    header('Content-Type: application/json');

    // Check if student is logged in
    if (!isStudentLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }

    // Get POST data
    $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
    $answer = isset($_POST['answer']) ? strtoupper(trim($_POST['answer'])) : '';
    $time_taken = isset($_POST['time_taken']) ? floatval($_POST['time_taken']) : 0;

    // Validate inputs
    if (!$question_id) {
        echo json_encode(['success' => false, 'error' => 'No question ID']);
        exit;
    }

    if (!in_array($answer, ['A', 'B', 'C', 'D'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid answer']);
        exit;
    }

    $student_id = $_SESSION['student_id'];

    // Check if student already answered this question
    $check_sql = "SELECT id FROM student_answers WHERE student_id = $student_id AND question_id = $question_id";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'You have already answered this question',
            'already_answered' => true
        ]);
        exit;
    }

    // Get question details to check correct answer
    $question_sql = "SELECT * FROM questions WHERE id = $question_id";
    $question_result = $conn->query($question_sql);

    if ($question_result->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Question not found']);
        exit;
    }

    $question = $question_result->fetch_assoc();
    $correct_answer = $question['correct_option'];
    $is_correct = ($answer == $correct_answer) ? 1 : 0;

    // Get question start time from quiz session
    $state_sql = "SELECT question_start FROM quiz_sessions ORDER BY id DESC LIMIT 1";
    $state_result = $conn->query($state_sql);
    $state = $state_result->fetch_assoc();

    if (!$state || !$state['question_start']) {
        // If no question_start in session, use current time minus time_taken
        $question_start = date('Y-m-d H:i:s', time() - $time_taken);
    } else {
        $question_start = $state['question_start'];
    }

    // Calculate points using the function from functions.php
    $points_earned = 0;
    if ($is_correct) {
        $points_earned = calculatePoints($question_start, date('Y-m-d H:i:s'), 100);
    }

    // Insert answer
    $insert_sql = "INSERT INTO student_answers 
               (student_id, question_id, answer, is_correct, time_taken, points_earned, submission_time) 
               VALUES 
               ($student_id, $question_id, '$answer', $is_correct, $time_taken, $points_earned, NOW())";

    if ($conn->query($insert_sql)) {
        // Update student's last activity
        $conn->query("UPDATE students SET last_activity = NOW() WHERE id = $student_id");

        // Get the inserted answer ID
        $answer_id = $conn->insert_id;

        echo json_encode([
            'success' => true,
            'correct' => $is_correct == 1,
            'correct_answer' => $correct_answer,
            'points_earned' => $points_earned,
            'time_taken' => $time_taken,
            'answer_id' => $answer_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $conn->error
        ]);
    }
    ?>