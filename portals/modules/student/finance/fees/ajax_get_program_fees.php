<?php
// modules/student/finance/fees/ajax_get_program_fees.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get parameters
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : $_SESSION['user_id'];

// Validate student can only view their own data
if ($student_id != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get program details
$program = [];
$sql = "SELECT * FROM programs WHERE id = ? AND status = 'active'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $program = $result->fetch_assoc();
}
$stmt->close();

if (empty($program)) {
    echo json_encode(['success' => false, 'message' => 'Program not found']);
    exit();
}

// Get active fee structure for this program
$fee_structure = [];
$sql = "SELECT * FROM fee_structures 
        WHERE program_id = ? AND is_active = 1 
        ORDER BY created_at DESC 
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $fee_structure = $result->fetch_assoc();
} else {
    // If no fee structure exists, create a default one
    $fee_structure = [
        'name' => 'Standard Fee Structure',
        'total_amount' => $program['fee'] ?? 0,
        'registration_fee' => $program['registration_fee'] ?? 0,
        'block1_amount' => $program['fee'] * 0.7, // 70% for block 1
        'block2_amount' => $program['fee'] * 0.3, // 30% for block 2
        'block3_amount' => 0,
    ];
}
$stmt->close();

// Get student's financial status for this program
$financial_status = [
    'total_fee' => 0,
    'paid_amount' => 0,
    'balance' => 0,
    'registration_paid' => false,
    'block1_paid' => false,
    'block2_paid' => false,
    'block3_paid' => false,
    'current_block' => 1,
    'is_cleared' => false,
    'is_suspended' => false,
    'next_payment_due' => null,
];

$sql = "SELECT sfs.* 
        FROM student_financial_status sfs
        JOIN class_batches cb ON sfs.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        WHERE sfs.student_id = ? 
          AND c.program_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $status_data = $result->fetch_assoc();
    $financial_status = array_merge($financial_status, $status_data);
}
$stmt->close();

// Calculate days left for next payment
$days_left = null;
if ($financial_status['next_payment_due']) {
    $due_date = new DateTime($financial_status['next_payment_due']);
    $today = new DateTime();
    $interval = $today->diff($due_date);
    $days_left = $interval->invert ? -$interval->days : $interval->days;
}

// Get any waivers for this program
$waivers = [];
$sql = "SELECT fw.* 
        FROM fee_waivers fw
        JOIN fee_structures fs ON fw.fee_structure_id = fs.id
        WHERE fw.student_id = ? 
          AND fs.program_id = ?
          AND fw.status = 'approved'
          AND (fw.expiry_date IS NULL OR fw.expiry_date >= CURDATE())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $waivers[] = $row;
    }
}
$stmt->close();

// Apply waivers to fee structure
$adjusted_fee_structure = $fee_structure;
foreach ($waivers as $waiver) {
    if ($waiver['waiver_type'] === 'percentage') {
        $percentage = $waiver['waiver_value'] / 100;
        $adjusted_fee_structure['registration_fee'] *= (1 - $percentage);
        $adjusted_fee_structure['block1_amount'] *= (1 - $percentage);
        $adjusted_fee_structure['block2_amount'] *= (1 - $percentage);
        $adjusted_fee_structure['block3_amount'] *= (1 - $percentage);
        $adjusted_fee_structure['total_amount'] *= (1 - $percentage);
    } else if ($waiver['waiver_type'] === 'fixed_amount') {
        $adjusted_fee_structure['total_amount'] -= $waiver['waiver_value'];
    } else if ($waiver['waiver_type'] === 'full') {
        $adjusted_fee_structure['total_amount'] = 0;
        $adjusted_fee_structure['registration_fee'] = 0;
        $adjusted_fee_structure['block1_amount'] = 0;
        $adjusted_fee_structure['block2_amount'] = 0;
        $adjusted_fee_structure['block3_amount'] = 0;
    }
}

// Get payment plan for this program
$payment_plan = [];
$sql = "SELECT * FROM payment_plans 
        WHERE program_id = ? AND is_active = 1
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $payment_plan = $result->fetch_assoc();
}
$stmt->close();

// Get penalty settings
$penalty_settings = [];
$sql = "SELECT * FROM penalty_settings 
        WHERE program_type = ? AND is_active = 1
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $program['program_type']);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $penalty_settings = $result->fetch_assoc();
}
$stmt->close();

// Close connection
$conn->close();

// Prepare response
$response = [
    'success' => true,
    'program' => $program,
    'fee_structure' => $adjusted_fee_structure,
    'financial_status' => $financial_status,
    'waivers' => $waivers,
    'payment_plan' => $payment_plan,
    'penalty_settings' => $penalty_settings,
    'days_left' => $days_left,
    'original_fee_structure' => $fee_structure,
];

// Log activity
logActivity($student_id, 'view_program_fees', "Viewed fee structure for program: {$program['name']}", $_SERVER['REMOTE_ADDR']);

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
