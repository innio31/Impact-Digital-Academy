<?php
// modules/student/finance/payments/record_payment.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get POST data
$payment_type = $_POST['payment_type'] ?? '';
$payment_reference = $_POST['payment_reference'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$program_id = intval($_POST['program_id'] ?? 0);
$class_id = intval($_POST['class_id'] ?? 0);
$course_id = intval($_POST['course_id'] ?? 0);
$student_id = intval($_POST['student_id'] ?? $_SESSION['user_id']);

// Validate required fields
if (empty($payment_type) || empty($payment_reference) || $amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if payment reference already exists
$check_sql = "SELECT id FROM payment_verifications WHERE payment_reference = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("s", $payment_reference);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    $check_stmt->close();
    $conn->close();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Payment reference already exists']);
    exit();
}
$check_stmt->close();

// Insert into payment_verifications table
$sql = "INSERT INTO payment_verifications 
        (payment_reference, student_id, payment_type, class_id, course_id, program_id, amount, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
$stmt = $conn->prepare($sql);

// Handle NULL values based on payment type
if ($payment_type === 'registration') {
    $nullClassId = NULL;
    $nullCourseId = NULL;
    $stmt->bind_param("sissiid", $payment_reference, $student_id, $payment_type, $nullClassId, $nullCourseId, $program_id, $amount);
} else {
    $nullProgramId = NULL;
    $stmt->bind_param("sissiid", $payment_reference, $student_id, $payment_type, $class_id, $course_id, $nullProgramId, $amount);
}

if ($stmt->execute()) {
    $payment_verification_id = $stmt->insert_id;

    // Also insert into registration_fee_payments if it's a registration payment
    if ($payment_type === 'registration' && $program_id > 0) {
        $reg_sql = "INSERT INTO registration_fee_payments 
                   (student_id, program_id, amount, payment_method, transaction_reference, status, payment_date, created_at) 
                   VALUES (?, ?, ?, 'bank_transfer', ?, 'pending', CURDATE(), NOW())";
        $reg_stmt = $conn->prepare($reg_sql);
        $reg_stmt->bind_param("iids", $student_id, $program_id, $amount, $payment_reference);
        $reg_stmt->execute();
        $reg_stmt->close();

        // Update applications table to mark registration fee as paid
        $update_sql = "UPDATE applications 
                      SET registration_fee_paid = 1, registration_paid_date = CURDATE() 
                      WHERE user_id = ? AND program_id = ? AND status = 'approved'";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $student_id, $program_id);
        $update_stmt->execute();
        $update_stmt->close();
    }

    // Log activity
    if ($payment_type === 'registration') {
        logActivity('payment_recorded', "Recorded registration payment with reference: {$payment_reference}", 'payment_verifications', $payment_verification_id);
    } else {
        logActivity('course_payment_recorded', "Recorded course payment with reference: {$payment_reference}", 'payment_verifications', $payment_verification_id);
    }

    $stmt->close();
    $conn->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'reference' => $payment_reference]);
} else {
    $stmt->close();
    $conn->close();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to record payment: ' . $conn->error]);
}
