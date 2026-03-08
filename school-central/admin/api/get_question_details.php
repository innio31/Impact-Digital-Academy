<?php
require_once '../../api/config.php';
require_once '../../api/auth.php';

// Authenticate (but allow admin access from session as well)
session_start();
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (!$is_admin) {
    // Try API authentication for school access
    try {
        authenticateSchool();
    } catch (Exception $e) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized']));
    }
}

$type = $_GET['type'] ?? '';
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    die(json_encode(['error' => 'Invalid question ID']));
}

if ($type === 'objective') {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               s.subject_name, 
               t.topic_name,
               (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'objective' AND question_id = q.id) as download_count
        FROM central_objective_questions q
        LEFT JOIN master_subjects s ON q.subject_id = s.id
        LEFT JOIN master_topics t ON q.topic_id = t.id
        WHERE q.id = ?
    ");
    $stmt->execute([$id]);
    $question = $stmt->fetch();

    if ($question) {
        // Format for display
        $question['question_text'] = nl2br(htmlspecialchars($question['question_text']));
        $question['option_a'] = nl2br(htmlspecialchars($question['option_a']));
        $question['option_b'] = nl2br(htmlspecialchars($question['option_b']));
        $question['option_c'] = nl2br(htmlspecialchars($question['option_c']));
        $question['option_d'] = nl2br(htmlspecialchars($question['option_d']));
        $question['explanation'] = $question['explanation'] ? nl2br(htmlspecialchars($question['explanation'])) : null;

        echo json_encode($question);
    } else {
        echo json_encode(['error' => 'Question not found']);
    }
} elseif ($type === 'theory') {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               s.subject_name, 
               t.topic_name,
               (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'theory' AND question_id = q.id) as download_count
        FROM central_theory_questions q
        LEFT JOIN master_subjects s ON q.subject_id = s.id
        LEFT JOIN master_topics t ON q.topic_id = t.id
        WHERE q.id = ?
    ");
    $stmt->execute([$id]);
    $question = $stmt->fetch();

    if ($question) {
        // Format for display
        $question['question_text'] = $question['question_text'] ? nl2br(htmlspecialchars($question['question_text'])) : null;
        $question['model_answer'] = $question['model_answer'] ? nl2br(htmlspecialchars($question['model_answer'])) : null;

        echo json_encode($question);
    } else {
        echo json_encode(['error' => 'Question not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid question type']);
}
