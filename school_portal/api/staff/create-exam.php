<?php
require_once '../includes/config.php';
require_once '/../includes/cors.php';
$user = verifyAuth();

if ($user['type'] !== 'staff') {
    sendResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$input = getJsonInput();
$examName = $input['exam_name'] ?? '';
$subject = $input['subject'] ?? '';
$class = $input['class'] ?? '';
$duration = $input['duration_minutes'] ?? 60;

if (empty($examName) || empty($subject) || empty($class)) {
    sendResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Get or create subject
$stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND school_id = ?");
$stmt->execute([$subject, SCHOOL_ID]);
$subjectId = $stmt->fetchColumn();

if (!$subjectId) {
    $stmt = $pdo->prepare("INSERT INTO subjects (school_id, subject_name) VALUES (?, ?)");
    $stmt->execute([SCHOOL_ID, $subject]);
    $subjectId = $pdo->lastInsertId();
}

$stmt = $pdo->prepare("
    INSERT INTO exams (school_id, exam_name, class, subject_id, duration_minutes, is_active, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
");
$stmt->execute([SCHOOL_ID, $examName, $class, $subjectId, $duration, $user['id']]);

sendResponse(['success' => true, 'message' => 'Exam created successfully']);
?>