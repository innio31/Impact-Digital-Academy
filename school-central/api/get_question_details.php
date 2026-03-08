<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require_once 'config.php';

    if (!isset($_GET['type']) || !isset($_GET['id'])) {
        throw new Exception('Missing parameters: type and id required');
    }

    $type = $_GET['type'];
    $id = intval($_GET['id']);

    if ($id <= 0) {
        throw new Exception('Invalid question ID');
    }

    if (!in_array($type, ['objective', 'theory'])) {
        throw new Exception('Invalid question type');
    }

    if ($type === 'objective') {
        $sql = "SELECT q.*, 
                       s.subject_name, 
                       t.topic_name,
                       (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'objective' AND question_id = q.id) as download_count
                FROM central_objective_questions q
                LEFT JOIN master_subjects s ON q.subject_id = s.id
                LEFT JOIN master_topics t ON q.topic_id = t.id
                WHERE q.id = ?";
    } else {
        $sql = "SELECT q.*, 
                       s.subject_name, 
                       t.topic_name,
                       (SELECT COUNT(*) FROM question_downloads WHERE question_type = 'theory' AND question_id = q.id) as download_count
                FROM central_theory_questions q
                LEFT JOIN master_subjects s ON q.subject_id = s.id
                LEFT JOIN master_topics t ON q.topic_id = t.id
                WHERE q.id = ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($question) {
        echo json_encode($question);
    } else {
        throw new Exception('Question not found');
    }
} catch (Exception $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}
