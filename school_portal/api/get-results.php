<?php
require_once 'includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

$stmt = $pdo->prepare("
    SELECT r.*, e.exam_name, s.subject_name
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    JOIN subjects s ON e.subject_id = s.id
    WHERE r.student_id = ? AND r.school_id = ?
    ORDER BY r.submitted_at DESC
");
$stmt->execute([$user['id'], SCHOOL_ID]);
$results = $stmt->fetchAll();

sendResponse(['success' => true, 'results' => $results]);
?>