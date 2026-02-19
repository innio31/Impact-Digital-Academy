<?php
// modules/admin/finance/invoices/ajax/get_class_info.php
require_once __DIR__ . '/../../../../../includes/config.php';
require_once __DIR__ . '/../../../../../includes/functions.php';

$conn = getDBConnection();

$class_id = $_POST['class_id'] ?? 0;

$sql = "SELECT cb.*, c.title as course_title, p.name as program_name, p.fee, p.program_type,
               (SELECT COUNT(*) FROM enrollments WHERE class_id = cb.id AND status = 'active') as enrolled_students,
               (SELECT COUNT(*) FROM invoices WHERE class_id = cb.id AND status IN ('pending', 'overdue')) as pending_invoices
        FROM class_batches cb
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        WHERE cb.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();

if ($class = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'data' => $class
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Class not found'
    ]);
}
