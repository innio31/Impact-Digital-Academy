<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'start_countdown':
        // First, ensure we have a question selected
        $question_id = $_POST['question_id'] ?? 0;

        // Update the session to countdown state
        $sql = "UPDATE quiz_sessions SET 
                status = 'countdown', 
                countdown_start = NOW(),
                current_question = $question_id
                ORDER BY id DESC LIMIT 1";

        if ($conn->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;

    case 'show_question':
        $question_id = $_POST['question_id'] ?? 0;
        if ($question_id) {
            // Calculate end time (10 seconds from now)
            $end_time = date('Y-m-d H:i:s', strtotime('+' . QUESTION_TIME . ' seconds'));

            $sql = "UPDATE quiz_sessions SET 
                    status = 'question', 
                    current_question = $question_id,
                    question_start = NOW(), 
                    question_end = '$end_time'
                    ORDER BY id DESC LIMIT 1";

            if ($conn->query($sql)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'No question selected']);
        }
        break;

    case 'show_results':
        $sql = "UPDATE quiz_sessions SET status = 'results' ORDER BY id DESC LIMIT 1";
        $conn->query($sql);
        echo json_encode(['success' => true]);
        break;

    case 'next_question':
        $sql = "UPDATE quiz_sessions SET 
                status = 'waiting', 
                current_question = 0,
                countdown_start = NULL,
                question_start = NULL,
                question_end = NULL
                ORDER BY id DESC LIMIT 1";
        $conn->query($sql);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
