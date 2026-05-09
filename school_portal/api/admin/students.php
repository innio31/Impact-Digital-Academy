<?php
require_once '../includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'admin') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$stmt = $pdo->prepare("SELECT id, admission_number, full_name, class, status, created_at FROM students WHERE school_id = ? ORDER BY created_at DESC");
$stmt->execute([SCHOOL_ID]);
$students = $stmt->fetchAll();

sendResponse(['success' => true, 'students' => $students]);
?>