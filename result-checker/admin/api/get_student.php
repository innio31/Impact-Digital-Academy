<?php
// api/get_student.php - Get student details for modal view
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
    exit();
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT s.*, sc.school_name, sc.school_code 
        FROM students s
        JOIN schools sc ON s.school_id = sc.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        echo json_encode(['success' => true, 'student' => $student]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
    }
} catch (PDOException $e) {
    error_log("get_student.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
