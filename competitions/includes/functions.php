<?php
// includes/functions.php

require_once 'config.php';

// Get current quiz state
function getQuizState()
{
    global $conn;
    $sql = "SELECT * FROM quiz_sessions ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Update quiz state
function updateQuizState($status, $question_id = null)
{
    global $conn;

    $sql = "UPDATE quiz_sessions SET status = '$status'";

    if ($status == 'countdown') {
        $sql .= ", countdown_start = NOW()";
        if ($question_id) {
            $sql .= ", current_question = $question_id";
        }
    } elseif ($status == 'question') {
        $end_time = date('Y-m-d H:i:s', strtotime('+' . QUESTION_TIME . ' seconds'));
        $sql .= ", current_question = $question_id, question_start = NOW(), question_end = '$end_time'";
    } elseif ($status == 'waiting') {
        $sql .= ", current_question = 0, countdown_start = NULL, question_start = NULL, question_end = NULL";
    }

    $sql .= " ORDER BY id DESC LIMIT 1";

    return $conn->query($sql);
}

// Get current question
function getCurrentQuestion()
{
    global $conn;
    $state = getQuizState();

    if ($state['current_question'] > 0) {
        $sql = "SELECT * FROM questions WHERE id = " . $state['current_question'];
        $result = $conn->query($sql);
        return $result->fetch_assoc();
    }
    return null;
}

// Calculate points based on submission time
// For 10-second question, points range from 100 to 0
// Formula: points = 100 - (time_taken * 10)
function calculatePoints($question_start, $submission_time, $max_points = MAX_POINTS)
{
    // Convert to timestamps with microseconds
    $start = strtotime($question_start);
    $submit = strtotime($submission_time);

    // Get microseconds for precise calculation
    $start_parts = explode('.', $question_start);
    $submit_parts = explode('.', $submission_time);

    $start_seconds = $start;
    $submit_seconds = $submit;

    // Add microseconds if available
    if (isset($start_parts[1])) {
        $start_seconds += floatval('0.' . $start_parts[1]);
    }
    if (isset($submit_parts[1])) {
        $submit_seconds += floatval('0.' . $submit_parts[1]);
    }

    // Calculate exact time taken
    $time_taken = $submit_seconds - $start_seconds;

    // Ensure time_taken is within valid range (0-10 seconds)
    $time_taken = max(0, min(10, $time_taken));

    // Calculate points: 100 - (time_taken * 10)
    // At 0s: 100 - 0 = 100
    // At 2.12s: 100 - 21.2 = 78.8
    // At 4.68s: 100 - 46.8 = 53.2
    // At 10s: 100 - 100 = 0
    $points = 100 - ($time_taken * 10);

    // Round to 2 decimal places
    return round($points, 2);
}

// Get results for current question
function getQuestionResults($question_id)
{
    global $conn;

    $sql = "SELECT s.name, sa.submission_time, sa.time_taken, sa.points_earned 
            FROM student_answers sa
            JOIN students s ON sa.student_id = s.id
            WHERE sa.question_id = $question_id AND sa.is_correct = 1
            ORDER BY sa.submission_time ASC";

    $result = $conn->query($sql);
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    return $results;
}

// Student login
function studentLogin($username, $password)
{
    global $conn;
    $password = md5($password); // In production, use password_hash()
    $sql = "SELECT * FROM students WHERE username = '$username' AND password = '$password'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $student = $result->fetch_assoc();
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['name'];
        return true;
    }
    return false;
}

// Check if student is logged in
function isStudentLoggedIn()
{
    return isset($_SESSION['student_id']);
}
