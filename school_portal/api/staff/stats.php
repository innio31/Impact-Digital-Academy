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

// Total students in assigned classes
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND class IN ($classPlaceholders)");
$stmt->execute($params);
$totalStudents = $stmt->fetch()['total'];

// Total exams
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM exams WHERE school_id = ? AND created_by = ?");
$stmt->execute([SCHOOL_ID, $user['id']]);
$totalExams = $stmt->fetch()['total'];

// Pending grading
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total FROM assignment_submissions 
    WHERE status = 'submitted' AND assignment_id IN (
        SELECT id FROM assignments WHERE staff_id = ?
    )
");
$stmt->execute([$user['staff_id']]);
$pendingGrading = $stmt->fetch()['total'];

sendResponse(['success' => true, 'totalStudents' => $totalStudents, 'totalExams' => $totalExams, 'pendingGrading' => $pendingGrading]);
?>