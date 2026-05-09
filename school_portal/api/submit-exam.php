<?php
require_once 'includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'student') {
    sendResponse(['success' => false, 'message' => 'Only students can submit exams'], 403);
}

$input = getJsonInput();

$examId = $input['exam_id'] ?? 0;
$answers = $input['answers'] ?? [];
$score = $input['score'] ?? 0;
$percentage = $input['percentage'] ?? 0;

// Save result
$stmt = $pdo->prepare("
    INSERT INTO results (school_id, student_id, exam_id, objective_score, total_score, percentage, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([SCHOOL_ID, $user['id'], $examId, $score, $score, $percentage]);

// Save exam session
$stmt = $pdo->prepare("
    INSERT INTO exam_sessions (student_id, exam_id, exam_type, start_time, end_time, status, objective_answers, score)
    VALUES (?, ?, 'objective', NOW(), NOW(), 'completed', ?, ?)
");
$stmt->execute([$user['id'], $examId, json_encode($answers), $score]);

sendResponse(['success' => true, 'message' => 'Exam submitted successfully']);
?>