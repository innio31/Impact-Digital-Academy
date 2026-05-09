<?php
require_once '../includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$stmt = $pdo->prepare("
    SELECT r.*, s.full_name as student_name, e.exam_name, e.subject_id, sub.subject_name
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN exams e ON r.exam_id = e.id
    LEFT JOIN subjects sub ON e.subject_id = sub.id
    WHERE r.school_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 50
");
$stmt->execute([SCHOOL_ID]);
$results = $stmt->fetchAll();

sendResponse(['success' => true, 'results' => $results]);
?>