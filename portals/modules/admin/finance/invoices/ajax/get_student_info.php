<?php
// modules/admin/finance/invoices/ajax/get_student_info.php
require_once __DIR__ . '/../../../../../includes/config.php';
require_once __DIR__ . '/../../../../../includes/functions.php';

$conn = getDBConnection();

$student_id = $_POST['student_id'] ?? 0;

$sql = "SELECT u.*, 
               (SELECT COUNT(*) FROM enrollments WHERE student_id = u.id AND status = 'active') as active_classes,
               (SELECT COUNT(*) FROM invoices WHERE student_id = u.id AND status IN ('pending', 'overdue')) as pending_invoices
        FROM users u 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($student = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'data' => $student
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Student not found'
    ]);
}
