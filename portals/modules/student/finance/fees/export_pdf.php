<?php
// modules/student/finance/fees/export_pdf.php

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
    die("Unauthorized access");
}

// Get parameters
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : $_SESSION['user_id'];

// Validate student can only view their own data
if ($student_id != $_SESSION['user_id']) {
    die("Unauthorized access");
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed");
}

// Get program details
$program = [];
$sql = "SELECT * FROM programs WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $program = $result->fetch_assoc();
}
$stmt->close();

// Get user details
$user = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Get fee structure
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
}
$stmt->close();

// Get financial status
$financial_status = [];
$sql = "SELECT sfs.* 
        FROM student_financial_status sfs
        JOIN class_batches cb ON sfs.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        WHERE sfs.student_id = ? AND c.program_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $program_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $financial_status = $result->fetch_assoc();
}
$stmt->close();

// Include TCPDF library
require_once __DIR__ . '/../../../includes/tcpdf/tcpdf.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Impact Digital Academy');
$pdf->SetAuthor('Impact Digital Academy');
$pdf->SetTitle('Fee Structure - ' . ($program['name'] ?? ''));
$pdf->SetSubject('Fee Structure Document');
$pdf->SetKeywords('Fee, Structure, Student, Payment');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 10);

// Header
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'FEE STRUCTURE DOCUMENT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Impact Digital Academy', 0, 1, 'C');
$pdf->Cell(0, 5, 'Generated on: ' . date('F j, Y'), 0, 1, 'C');
$pdf->Ln(10);

// Student Information
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'STUDENT INFORMATION', 0, 1);
$pdf->SetFont('helvetica', '', 10);

$student_info = [
    'Name' => htmlspecialchars($user['first_name'] . ' ' . $user['last_name']),
    'Email' => htmlspecialchars($user['email']),
    'Phone' => htmlspecialchars($user['phone'] ?? 'N/A'),
    'Student ID' => 'STU-' . str_pad($student_id, 6, '0', STR_PAD_LEFT),
];

foreach ($student_info as $label => $value) {
    $pdf->Cell(50, 6, $label . ':', 0, 0);
    $pdf->Cell(0, 6, $value, 0, 1);
}
$pdf->Ln(10);

// Program Information
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'PROGRAM INFORMATION', 0, 1);
$pdf->SetFont('helvetica', '', 10);

$program_info = [
    'Program Name' => htmlspecialchars($program['name'] ?? ''),
    'Program Code' => htmlspecialchars($program['program_code'] ?? ''),
    'Program Type' => htmlspecialchars(ucfirst($program['program_type'] ?? '')),
    'Duration' => ($program['duration_weeks'] ?? 0) . ' weeks',
];

foreach ($program_info as $label => $value) {
    $pdf->Cell(50, 6, $label . ':', 0, 0);
    $pdf->Cell(0, 6, $value, 0, 1);
}
$pdf->Ln(10);

// Fee Structure
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'FEE STRUCTURE DETAILS', 0, 1);
$pdf->SetFont('helvetica', 'B', 10);

// Table header
$pdf->Cell(100, 8, 'Fee Item', 1, 0, 'L');
$pdf->Cell(40, 8, 'Amount (₦)', 1, 0, 'R');
$pdf->Cell(40, 8, 'Status', 1, 1, 'C');

$pdf->SetFont('helvetica', '', 10);

// Fee items
$fee_items = [
    'Registration Fee' => [
        'amount' => $fee_structure['registration_fee'] ?? 0,
        'paid' => $financial_status['registration_paid'] ?? false
    ],
    'Block 1 Tuition' => [
        'amount' => $fee_structure['block1_amount'] ?? 0,
        'paid' => $financial_status['block1_paid'] ?? false
    ],
    'Block 2 Tuition' => [
        'amount' => $fee_structure['block2_amount'] ?? 0,
        'paid' => $financial_status['block2_paid'] ?? false
    ],
];

if (($fee_structure['block3_amount'] ?? 0) > 0) {
    $fee_items['Block 3 Tuition'] = [
        'amount' => $fee_structure['block3_amount'] ?? 0,
        'paid' => false
    ];
}

$total_amount = 0;
foreach ($fee_items as $item => $data) {
    $pdf->Cell(100, 8, $item, 1, 0, 'L');
    $pdf->Cell(40, 8, number_format($data['amount'], 2), 1, 0, 'R');
    $pdf->Cell(40, 8, $data['paid'] ? 'Paid' : 'Pending', 1, 1, 'C');
    $total_amount += $data['amount'];
}

// Total row
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(100, 8, 'TOTAL PROGRAM FEE', 1, 0, 'L');
$pdf->Cell(40, 8, number_format($total_amount, 2), 1, 0, 'R');
$pdf->Cell(40, 8, '', 1, 1, 'C');
$pdf->Ln(10);

// Payment Summary
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'PAYMENT SUMMARY', 0, 1);
$pdf->SetFont('helvetica', '', 10);

$paid_amount = $financial_status['paid_amount'] ?? 0;
$balance = $financial_status['balance'] ?? 0;

$payment_info = [
    'Total Fee' => '₦' . number_format($total_amount, 2),
    'Amount Paid' => '₦' . number_format($paid_amount, 2),
    'Current Balance' => '₦' . number_format($balance, 2),
    'Payment Progress' => round(($paid_amount / $total_amount) * 100, 1) . '%',
    'Current Block' => 'Block ' . ($financial_status['current_block'] ?? 1),
    'Clearance Status' => ($financial_status['is_cleared'] ?? false) ? 'Cleared' : 'Not Cleared',
];

if ($financial_status['next_payment_due'] ?? false) {
    $payment_info['Next Payment Due'] = date('F j, Y', strtotime($financial_status['next_payment_due']));
}

foreach ($payment_info as $label => $value) {
    $pdf->Cell(60, 6, $label . ':', 0, 0);
    $pdf->Cell(0, 6, $value, 0, 1);
}
$pdf->Ln(15);

// Payment Instructions
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'PAYMENT INSTRUCTIONS', 0, 1);
$pdf->SetFont('helvetica', '', 10);

$instructions = [
    '1. All payments should be made through the official payment channels',
    '2. Keep your transaction reference for verification',
    '3. Payments are processed within 24-48 hours',
    '4. Contact finance@impactdigitalacademy.com for payment issues',
    '5. Late payments may incur penalties as per academy policy',
];

foreach ($instructions as $instruction) {
    $pdf->MultiCell(0, 6, $instruction, 0, 'L');
}
$pdf->Ln(10);

// Footer
$pdf->SetY(-30);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'This is an official document from Impact Digital Academy', 0, 1, 'C');
$pdf->Cell(0, 5, 'For verification, contact: admin@impactdigitalacademy.com', 0, 1, 'C');
$pdf->Cell(0, 5, 'Document ID: FEE-' . date('Ymd') . '-' . str_pad($student_id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');

// Log activity
logActivity($student_id, 'export_fee_pdf', "Exported fee structure PDF for program: {$program['name']}", $_SERVER['REMOTE_ADDR']);

// Close connection
$conn->close();

// Output PDF
$pdf->Output('fee_structure_' . date('Ymd') . '.pdf', 'I');
