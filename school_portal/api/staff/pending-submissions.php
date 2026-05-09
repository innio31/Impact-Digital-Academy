<?php
require_once '../includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

if ($user['type'] !== 'staff') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$stmt = $pdo->prepare("
    SELECT s.*, a.title as assignment_title, stu.full_name as student_name
    FROM assignment_submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN students stu ON s.student_id = stu.id
    WHERE a.staff_id = ? AND s.status = 'submitted'
    ORDER BY s.submitted_at ASC
");
$stmt->execute([$user['staff_id']]);
$submissions = $stmt->fetchAll();

sendResponse(['success' => true, 'submissions' => $submissions]);
?>