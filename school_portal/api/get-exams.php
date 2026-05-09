<?php
require_once 'includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

$class = $_GET['class'] ?? '';

if (empty($class)) {
    sendResponse(['success' => false, 'message' => 'Class required'], 400);
}

$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name 
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.class = ? 
    AND e.school_id = ?
    AND e.is_active = 1
    ORDER BY e.created_at DESC
");
$stmt->execute([$class, SCHOOL_ID]);
$exams = $stmt->fetchAll();

sendResponse(['success' => true, 'exams' => $exams]);
?>