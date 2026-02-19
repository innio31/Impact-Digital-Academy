<?php
// modules/admin/finance/payments/ajax/get_fee_info.php

require_once __DIR__ . '/../../../../../includes/config.php';
require_once __DIR__ . '/../../../../../includes/functions.php';
require_once __DIR__ . '/../../../../../includes/finance_functions.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$class_id = $_GET['class_id'] ?? 0;
if (!$class_id) {
    echo json_encode(['error' => 'Class ID required']);
    exit();
}

$conn = getDBConnection();

// Get program type
$sql = "SELECT cb.*, p.program_type 
        FROM class_batches cb
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        WHERE cb.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class_info = $result->fetch_assoc();

if (!$class_info) {
    echo json_encode(['error' => 'Class not found']);
    exit();
}

// Calculate fee information
$fee_info = calculateTotalFee($class_id, $class_info['program_type']);

if ($fee_info) {
    echo json_encode([
        'success' => true,
        'program_type' => $class_info['program_type'],
        ...$fee_info
    ]);
} else {
    echo json_encode(['error' => 'Unable to calculate fees']);
}
?>