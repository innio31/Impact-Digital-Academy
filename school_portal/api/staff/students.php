<?php
require_once '../includes/config.php';
$user = verifyAuth();

if ($user['type'] !== 'staff') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

// Get staff assigned classes
$stmt = $pdo->prepare("SELECT class FROM staff_classes WHERE staff_id = ?");
$stmt->execute([$user['staff_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$classPlaceholders = str_repeat('?,', count($classes) - 1) . '?';
$params = array_merge([SCHOOL_ID], $classes);

$stmt = $pdo->prepare("SELECT id, admission_number, full_name, class FROM students WHERE school_id = ? AND class IN ($classPlaceholders) ORDER BY full_name");
$stmt->execute($params);
$students = $stmt->fetchAll();

sendResponse(['success' => true, 'students' => $students]);
?>