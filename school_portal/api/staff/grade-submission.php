<?php
require_once '../includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

if ($user['type'] !== 'staff') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$input = getJsonInput();
$submissionId = $input['submission_id'] ?? 0;
$grade = $input['grade'] ?? '';
$feedback = $input['feedback'] ?? '';

$stmt = $pdo->prepare("
    UPDATE assignment_submissions 
    SET status = 'graded', grade = ?, teacher_feedback = ?, graded_at = NOW()
    WHERE id = ?
");
$stmt->execute([$grade, $feedback, $submissionId]);

sendResponse(['success' => true, 'message' => 'Grade submitted']);
?>