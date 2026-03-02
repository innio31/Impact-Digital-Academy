<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isStudentLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$student_id = $_SESSION['student_id'];
$question_id = $_POST['question_id'] ?? 0;
$answer = $_POST['answer'] ?? '';
$time_taken = floatval($_POST['time_taken'] ?? 0);

// Get question details
$sql = "SELECT * FROM questions WHERE id = $question_id";
$result = $conn->query($sql);
$question = $result->fetch_assoc();

if (!$question) {
    echo json_encode(['success' => false, 'error' => 'Invalid question']);
    exit;
}

// Check if answer is correct
$is_correct = ($answer == $question['correct_option']);

// Calculate points if correct
$points_earned = 0;
if ($is_correct) {
    $quiz_state = getQuizState();

    // Get the exact question start time from the session
    $question_start = $quiz_state['question_start'];

    // Get current time with microseconds for precise calculation
    $submission_time = date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 6);

    // Calculate points using the corrected formula
    $points_earned = calculatePoints($question_start, $submission_time);

    // Log for debugging (remove in production)
    error_log("Student $student_id - Time: {$time_taken}s, Points: $points_earned");
}

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

// Save answer
$sql = "INSERT INTO student_answers (student_id, question_id, answer, is_correct, time_taken, points_earned) 
        VALUES ($student_id, $question_id, '$answer', " . ($is_correct ? 1 : 0) . ", $time_taken, $points_earned)";

if ($conn->query($sql)) {
    // Update student total score
    if ($is_correct && $points_earned > 0) {
        $conn->query("INSERT INTO student_scores (student_id, total_points) 
                      VALUES ($student_id, $points_earned)
                      ON DUPLICATE KEY UPDATE total_points = total_points + $points_earned");
    }

    echo json_encode([
        'success' => true,
        'correct' => $is_correct,
        'points_earned' => $points_earned,
        'correct_answer' => $question['correct_option'],
        'time_taken' => round($time_taken, 3)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $conn->error
    ]);
}
