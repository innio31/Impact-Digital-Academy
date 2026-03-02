<?php
require_once '../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ready_students':
        // Get online students (active in last 30 seconds)
        $sql = "SELECT id, name FROM students WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 SECOND)";
        $result = $conn->query($sql);

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        echo json_encode([
            'count' => count($students),
            'students' => $students
        ]);
        break;

    case 'state':
        // Get current quiz state
        $sql = "SELECT * FROM quiz_sessions ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);
        $state = $result->fetch_assoc();

        if (!$state) {
            // Create default state if none exists
            $conn->query("INSERT INTO quiz_sessions (status) VALUES ('waiting')");
            $result = $conn->query("SELECT * FROM quiz_sessions ORDER BY id DESC LIMIT 1");
            $state = $result->fetch_assoc();
        }

        $response = [
            'status' => $state['status'],
            'current_question_id' => $state['current_question'],
            'time_left' => 0,
            'next_action' => null
        ];

        // Get current question if any
        if ($state['current_question'] > 0) {
            $q_sql = "SELECT * FROM questions WHERE id = " . $state['current_question'];
            $q_result = $conn->query($q_sql);
            $response['question'] = $q_result->fetch_assoc();
        } else {
            $response['question'] = null;
        }

        // Calculate time left based on current status
        $now = time();

        if ($state['status'] == 'countdown' && $state['countdown_start']) {
            $start = strtotime($state['countdown_start']);
            $elapsed = $now - $start;
            $response['time_left'] = max(0, COUNTDOWN_TIME - $elapsed);

            // Auto transition to question when countdown reaches 0
            if ($response['time_left'] <= 0 && $state['current_question'] > 0) {
                // Calculate end time for question (10 seconds from now)
                $end_time = date('Y-m-d H:i:s', strtotime('+' . QUESTION_TIME . ' seconds'));

                // Automatically show question
                $update_sql = "UPDATE quiz_sessions SET 
                             status = 'question',
                             question_start = NOW(),
                             question_end = '$end_time'
                             WHERE id = " . $state['id'];
                $conn->query($update_sql);

                $response['status'] = 'question';
                $response['time_left'] = QUESTION_TIME;
                $response['next_action'] = 'showing_question';
            }
        }

        if ($state['status'] == 'question' && $state['question_end']) {
            $end = strtotime($state['question_end']);
            $response['time_left'] = max(0, $end - $now);

            // Auto transition to results when time is up
            if ($response['time_left'] <= 0) {
                $conn->query("UPDATE quiz_sessions SET status = 'results' WHERE id = " . $state['id']);
                $response['status'] = 'results';
                $response['time_left'] = 0;
                $response['next_action'] = 'showing_results';
            }
        }

        echo json_encode($response);
        break;

    case 'results':
        $question_id = $_GET['question_id'] ?? 0;
        if ($question_id) {
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
            echo json_encode($results);
        } else {
            echo json_encode([]);
        }
        break;

    case 'student_results':
        $student_id = $_GET['student_id'] ?? 0;
        if ($student_id) {
            // Get student's total score
            $sql = "SELECT total_points FROM student_scores WHERE student_id = $student_id";
            $result = $conn->query($sql);
            $score = $result->fetch_assoc();
            $total_points = $score['total_points'] ?? 0;

            // Get student's rank
            $sql = "SELECT student_id, total_points,
                    FIND_IN_SET(total_points, (SELECT GROUP_CONCAT(total_points ORDER BY total_points DESC) 
                    FROM student_scores)) as rank
                    FROM student_scores
                    WHERE student_id = $student_id";
            $result = $conn->query($sql);
            $rank_data = $result->fetch_assoc();
            $rank = $rank_data['rank'] ?? 1;

            // Get last question result
            $sql = "SELECT q.correct_option, sa.is_correct, sa.answer, sa.points_earned
                    FROM student_answers sa
                    JOIN questions q ON sa.question_id = q.id
                    WHERE sa.student_id = $student_id
                    ORDER BY sa.id DESC LIMIT 1";
            $result = $conn->query($sql);
            $last = $result->fetch_assoc();

            echo json_encode([
                'total_points' => $total_points,
                'rank' => $rank,
                'last_answer_correct' => $last ? $last['is_correct'] : false,
                'correct_answer' => $last ? $last['correct_option'] : '',
                'points_earned' => $last ? $last['points_earned'] : 0
            ]);
        } else {
            echo json_encode(['total_points' => 0, 'rank' => 0]);
        }
        break;

    case 'all_students':
        // Get current question ID from quiz state
        $state_sql = "SELECT current_question FROM quiz_sessions ORDER BY id DESC LIMIT 1";
        $state_result = $conn->query($state_sql);
        $state = $state_result->fetch_assoc();
        $current_question_id = $state['current_question'] ?? 0;

        $sql = "SELECT s.id, s.name, COALESCE(ss.total_points, 0) as total_points,
                (SELECT is_correct FROM student_answers 
                 WHERE student_id = s.id AND question_id = " . $current_question_id . " 
                 ORDER BY id DESC LIMIT 1) as last_answer_correct
                FROM students s
                LEFT JOIN student_scores ss ON s.id = ss.student_id
                ORDER BY s.name ASC";
        $result = $conn->query($sql);

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        echo json_encode($students);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
