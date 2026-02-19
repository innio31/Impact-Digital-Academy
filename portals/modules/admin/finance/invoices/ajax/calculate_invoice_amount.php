<?php
// modules/admin/finance/invoices/ajax/calculate_invoice_amount.php
require_once __DIR__ . '/../../../../../includes/config.php';
require_once __DIR__ . '/../../../../../includes/functions.php';

$conn = getDBConnection();

$student_id = $_POST['student_id'] ?? 0;
$class_id = $_POST['class_id'] ?? 0;
$invoice_type = $_POST['invoice_type'] ?? '';

if (!$student_id || !$class_id || !$invoice_type) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Get class fee information
$sql = "SELECT p.fee, p.program_type 
        FROM class_batches cb
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        WHERE cb.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$class_info = $stmt->get_result()->fetch_assoc();

if (!$class_info) {
    echo json_encode(['success' => false, 'error' => 'Class not found']);
    exit;
}

$amount = 0;

switch ($invoice_type) {
    case 'registration':
        $amount = 10000; // Default registration fee
        break;

    case 'tuition_block1':
        $amount = $class_info['fee'] * 0.7; // 70% of total fee
        break;

    case 'tuition_block2':
        $amount = $class_info['fee'] * 0.3; // 30% of total fee
        break;

    case 'late_fee':
        // Calculate 5% of total fee as late fee
        $amount = $class_info['fee'] * 0.05;
        break;

    case 'other':
        $amount = 0; // Let admin enter manually
        break;
}

echo json_encode([
    'success' => true,
    'data' => [
        'amount' => round($amount, 2),
        'currency' => 'NGN'
    ]
]);
