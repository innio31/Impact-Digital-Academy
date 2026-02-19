<?php
// modules/student/finance/payments/make_payment.php

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
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Check payment type
$payment_type = isset($_GET['type']) ? $_GET['type'] : 'course';
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Initialize variables
$class_info = [];
$course_info = [];
$program_info = [];
$financial_status = [];
$student = [];
$payment_details = [];
$balance_due = $amount;

if ($payment_type === 'course') {
    // Course payment flow (existing code)
    if (!$class_id || !$course_id) {
        header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
        exit();
    }

    // Get class and course details
    $sql = "SELECT cb.*, c.title as course_title, c.course_code, 
                   p.name as program_name, cf.fee as course_fee,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM class_batches cb 
            JOIN courses c ON cb.course_id = c.id 
            JOIN programs p ON c.program_id = p.id 
            JOIN users u ON cb.instructor_id = u.id 
            LEFT JOIN course_fees cf ON c.id = cf.course_id
            WHERE cb.id = ? AND c.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $course_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
        exit();
    }

    $class_info = $result->fetch_assoc();
    $stmt->close();

    // Get student's financial status for this class
    $sql = "SELECT sfs.*, e.status as enrollment_status
            FROM student_financial_status sfs
            JOIN enrollments e ON sfs.student_id = e.student_id AND sfs.class_id = e.class_id
            WHERE sfs.student_id = ? AND sfs.class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $financial_status = $result->fetch_assoc();
    $stmt->close();

    // Calculate amount due for course
    $course_fee = $class_info['course_fee'] ?? 0;
    $amount_paid = $financial_status['paid_amount'] ?? 0;
    $balance_due = $financial_status['balance'] ?? $course_fee;

    $payment_details = [
        'title' => $class_info['course_title'],
        'code' => $class_info['batch_code'],
        'type' => 'Course Fee',
        'description' => "Payment for {$class_info['course_title']} - {$class_info['batch_code']}"
    ];
} elseif ($payment_type === 'registration') {
    // Registration payment flow
    if (!$program_id) {
        header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
        exit();
    }

    // Get program details
    $sql = "SELECT p.*, a.program_id as applied_program_id 
            FROM programs p
            JOIN applications a ON p.id = a.program_id
            WHERE a.user_id = ? AND a.status = 'approved' AND p.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
        exit();
    }

    $program_info = $result->fetch_assoc();
    $stmt->close();

    // Check if registration fee has already been paid
    $sql = "SELECT * FROM registration_fee_payments 
            WHERE student_id = ? AND program_id = ? AND status = 'completed'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Already paid, redirect back
        $stmt->close();
        $conn->close();
        header('Location: ' . BASE_URL . 'modules/student/program/available_periods.php?message=Registration fee already paid&type=info');
        exit();
    }

    $stmt->close();

    // Set payment details for registration
    $balance_due = $amount > 0 ? $amount : ($program_info['registration_fee'] ?? 0);

    $payment_details = [
        'title' => $program_info['name'],
        'code' => $program_info['program_code'],
        'type' => 'Registration Fee',
        'description' => "Registration fee for {$program_info['name']} program"
    ];
} else {
    // Invalid payment type
    $conn->close();
    header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
    exit();
}

// Get student details
$sql = "SELECT first_name, last_name, email, phone FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

// Generate payment reference based on type
if ($payment_type === 'registration') {
    $payment_reference = "REG" . date('Ymd') . str_pad($student_id, 5, '0', STR_PAD_LEFT) . rand(100, 999);
} else {
    $payment_reference = "COURSE" . date('Ymd') . str_pad($student_id, 5, '0', STR_PAD_LEFT) . rand(100, 999);
}

// REMOVED: We no longer create payment_verifications record here
// Instead, we'll create it when the user clicks "I have made payment"

// Generate WhatsApp message with payment details
$whatsapp_message = urlencode("PAYMENT PROOF - Impact Digital Academy\n\n" .
    "Student: {$student['first_name']} {$student['last_name']}\n" .
    "Student ID: {$student_id}\n" .
    ($payment_type === 'registration' ?
        "Program: {$program_info['name']}\n" .
        "Payment Type: Registration Fee\n" :
        "Course: {$class_info['course_title']}\n" .
        "Class: {$class_info['batch_code']}\n") .
    "Amount: ₦" . number_format($balance_due, 2) . "\n" .
    "Payment Ref: {$payment_reference}\n" .
    "Date: " . date('F j, Y'));

// Log activity
if ($payment_type === 'course') {
    logActivity('view_payment_page', "Viewed payment page for class: {$class_info['batch_code']}", 'class_batches', $class_id);
} else {
    logActivity('view_registration_payment', "Viewed registration payment page for program: {$program_info['name']}", 'programs', $program_id);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            padding: 1rem;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            opacity: 0.9;
        }

        .back-button {
            position: absolute;
            top: 1rem;
            left: 1rem;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Content */
        .content {
            padding: 2rem;
        }

        /* Payment Steps */
        .payment-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3rem;
            position: relative;
        }

        .payment-steps::before {
            content: '';
            position: absolute;
            top: 1.25rem;
            left: 5%;
            right: 5%;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }

        .step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .step-number {
            width: 2.5rem;
            height: 2.5rem;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            color: var(--gray);
        }

        .step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .step.completed .step-number {
            background: var(--success);
            color: white;
        }

        .step-label {
            font-size: 0.875rem;
            color: var(--gray);
            font-weight: 500;
        }

        .step.active .step-label {
            color: var(--primary);
        }

        /* Payment Info Cards */
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .info-card h3 {
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card h3 i {
            color: var(--primary);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.75rem;
        }

        .info-label {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
        }

        .info-value.amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Bank Details */
        .bank-details {
            background: #f0f9ff;
            border: 2px solid #bae6fd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .bank-details h3 {
            color: #0369a1;
            margin-bottom: 1rem;
        }

        .bank-detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e0f2fe;
        }

        .bank-detail-item:last-child {
            border-bottom: none;
        }

        .bank-label {
            font-weight: 600;
            color: #0c4a6e;
        }

        .bank-value {
            color: #0369a1;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        /* Important Notes */
        .important-notes {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .important-notes h3 {
            color: #d97706;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .important-notes ul {
            padding-left: 1.5rem;
            color: #92400e;
        }

        .important-notes li {
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        /* I Have Made Payment Button */
        .payment-confirm-button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-bottom: 1.5rem;
        }

        .payment-confirm-button:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .payment-confirm-button i {
            font-size: 1.25rem;
        }

        /* WhatsApp Button */
        .whatsapp-button {
            background: #25D366;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-bottom: 1.5rem;
            display: none;
            /* Initially hidden */
        }

        .whatsapp-button:hover {
            background: #128C7E;
            transform: translateY(-2px);
        }

        .whatsapp-button i {
            font-size: 1.25rem;
        }

        /* Status Message */
        .status-message {
            text-align: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: #f0f9ff;
            border-radius: 8px;
            border: 2px solid #bae6fd;
        }

        .status-message i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .status-message h3 {
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .status-message p {
            color: var(--gray);
            line-height: 1.6;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        /* Copy to clipboard */
        .copy-btn {
            background: #e2e8f0;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            margin-left: 0.5rem;
            transition: background 0.2s ease;
        }

        .copy-btn:hover {
            background: #cbd5e1;
        }

        .copy-btn.copied {
            background: var(--success);
            color: white;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--dark);
            font-size: 1.25rem;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content {
                padding: 1rem;
            }

            .payment-steps {
                flex-direction: column;
                gap: 2rem;
            }

            .payment-steps::before {
                display: none;
            }

            .step {
                display: flex;
                align-items: center;
                gap: 1rem;
            }

            .step-number {
                margin: 0;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <?php if ($payment_type === 'course'): ?>
                <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?id=<?php echo $class_id; ?>" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Class
                </a>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>modules/student/program/available_periods.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Periods
                </a>
            <?php endif; ?>
            <h1><i class="fas fa-credit-card"></i> Make Payment</h1>
            <p>Complete your payment for <?php echo htmlspecialchars($payment_details['title']); ?></p>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Payment Steps -->
            <div class="payment-steps">
                <div class="step active">
                    <div class="step-number">1</div>
                    <div class="step-label">Payment Details</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-label">Transfer Funds</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Payment</div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-label">Send Proof</div>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="info-card">
                <h3><i class="fas fa-receipt"></i> Payment Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Payment Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($payment_details['type']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><?php echo $payment_type === 'registration' ? 'Program' : 'Course'; ?></div>
                        <div class="info-value"><?php echo htmlspecialchars($payment_details['title']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($payment_details['code']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Amount Due</div>
                        <div class="info-value amount">₦<?php echo number_format($balance_due, 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Payment Reference</div>
                        <div class="info-value">
                            <?php echo $payment_reference; ?>
                            <button class="copy-btn" data-text="<?php echo $payment_reference; ?>">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank Details -->
            <div class="bank-details">
                <h3><i class="fas fa-university"></i> Bank Account Details</h3>
                <div class="bank-detail-item">
                    <span class="bank-label">Bank Name:</span>
                    <span class="bank-value">Monie Point</span>
                </div>
                <div class="bank-detail-item">
                    <span class="bank-label">Account Name:</span>
                    <span class="bank-value">Impact Digital Academy</span>
                </div>
                <div class="bank-detail-item">
                    <span class="bank-label">Account Number:</span>
                    <span class="bank-value">6658393500</span>
                    <button class="copy-btn" data-text="6658393500">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
            </div>

            <!-- Important Notes -->
            <div class="important-notes">
                <h3><i class="fas fa-exclamation-circle"></i> Important Payment Instructions</h3>
                <ul>
                    <li>Transfer the exact amount of <strong>₦<?php echo number_format($balance_due, 2); ?></strong> to the bank account above</li>
                    <li>Use your payment reference <strong><?php echo $payment_reference; ?></strong> as the transfer narration</li>
                    <li>After completing the transfer, click "I have made payment" to record your payment</li>
                    <li>Then send proof of payment via WhatsApp using the button that appears</li>
                    <li>Keep your transfer receipt for reference</li>
                    <li>Payment verification usually takes less than 24 hours</li>
                    <?php if ($payment_type === 'registration'): ?>
                        <li>Once verified, you'll be able to register for courses in available periods</li>
                    <?php else: ?>
                        <li>Once verified, your financial hold will be cleared and class access restored</li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- I Have Made Payment Button -->
            <button id="confirmPaymentBtn" class="payment-confirm-button">
                <i class="fas fa-check-circle"></i> I Have Made Payment
            </button>

            <!-- WhatsApp Button (initially hidden) -->
            <a href="https://wa.me/2349051586024?text=<?php echo $whatsapp_message; ?>"
                target="_blank"
                id="whatsappBtn"
                class="whatsapp-button">
                <i class="fab fa-whatsapp"></i> Send Proof via WhatsApp
            </a>

            <!-- Status Message -->
            <div class="status-message">
                <i class="fas fa-clock"></i>
                <h3>Payment Processing</h3>
                <p>Your payment will be processed once confirmed by our finance team. Response time is usually less than 24 hours during business days.</p>
                <?php if ($payment_type === 'registration'): ?>
                    <p>Once verified, your registration fee payment will be recorded and you can proceed to register for courses in available periods.</p>
                <?php else: ?>
                    <p>You will receive an email notification and your class access will be restored automatically once payment is verified.</p>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if ($payment_type === 'course'): ?>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Class
                    </a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/available_periods.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Periods
                    </a>
                <?php endif; ?>
                <button id="printBtn" class="btn btn-success">
                    <i class="fas fa-print"></i> Print Instructions
                </button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmationModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Payment Confirmation</h3>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success);"></i>
                    <h3 style="color: var(--success); margin-top: 1rem;">Payment Recorded Successfully!</h3>
                </div>
                <p>Your payment has been recorded with reference: <strong><?php echo $payment_reference; ?></strong></p>
                <p>Please send proof of payment via WhatsApp to complete the verification process.</p>
                <div class="important-notes" style="margin-top: 1rem;">
                    <p><strong>Next Steps:</strong></p>
                    <ol>
                        <li>Click "Send Proof via WhatsApp" button</li>
                        <li>Send your payment receipt/screenshot</li>
                        <li>Include your payment reference: <?php echo $payment_reference; ?></li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" onclick="showWhatsAppButton()">OK, Got It</button>
            </div>
        </div>
    </div>

    <script>
        // Copy to clipboard functionality
        document.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const text = this.getAttribute('data-text');
                navigator.clipboard.writeText(text).then(() => {
                    // Show copied state
                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    this.classList.add('copied');

                    // Reset after 2 seconds
                    setTimeout(() => {
                        this.innerHTML = originalHTML;
                        this.classList.remove('copied');
                    }, 2000);
                });
            });
        });

        // Print functionality
        document.getElementById('printBtn').addEventListener('click', function() {
            const printContent = `
                <html>
                <head>
                    <title>Payment Instructions - Impact Digital Academy</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h1 { color: #1d4ed8; }
                        .section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
                        .bank-details { background: #f0f9ff; padding: 15px; }
                        .important { background: #fef3c7; padding: 15px; }
                        .amount { font-size: 24px; color: #3b82f6; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <h1>Payment Instructions</h1>
                    <p><strong>Payment Type:</strong> <?php echo htmlspecialchars($payment_details['type']); ?></p>
                    <p><strong>Description:</strong> <?php echo htmlspecialchars($payment_details['description']); ?></p>
                    
                    <div class="section">
                        <h3>Payment Summary</h3>
                        <p class="amount">Amount Due: ₦<?php echo number_format($balance_due, 2); ?></p>
                        <p><strong>Reference:</strong> <?php echo $payment_reference; ?></p>
                    </div>
                    
                    <div class="section bank-details">
                        <h3>Bank Details</h3>
                        <p><strong>Bank Name:</strong> Monie Point</p>
                        <p><strong>Account Name:</strong> Impact Digital Academy</p>
                        <p><strong>Account Number:</strong> 6658393500</p>
                    </div>
                    
                    <div class="section important">
                        <h3>Important Instructions</h3>
                        <ul>
                            <li>Transfer exactly ₦<?php echo number_format($balance_due, 2); ?></li>
                            <li>Use reference: <?php echo $payment_reference; ?></li>
                            <li>Send proof to WhatsApp: +2349051586024</li>
                            <li>Verification takes less than 24 hours</li>
                        </ul>
                    </div>
                    
                    <p><em>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></em></p>
                </body>
                </html>
            `;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        });

        // I Have Made Payment button click handler
        document.getElementById('confirmPaymentBtn').addEventListener('click', function() {
            // Disable button and show loading
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording Payment...';

            // Create payment verification record via AJAX
            fetch('record_payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'payment_type': '<?php echo $payment_type; ?>',
                        'payment_reference': '<?php echo $payment_reference; ?>',
                        'amount': '<?php echo $balance_due; ?>',
                        'program_id': '<?php echo $program_id; ?>',
                        'class_id': '<?php echo $class_id; ?>',
                        'course_id': '<?php echo $course_id; ?>',
                        'student_id': '<?php echo $student_id; ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show confirmation modal
                        document.getElementById('confirmationModal').classList.add('active');

                        // Update steps
                        document.querySelectorAll('.step')[1].classList.add('completed');
                        document.querySelectorAll('.step')[2].classList.add('active');
                    } else {
                        alert('Error: ' + data.message);
                        // Reset button
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-check-circle"></i> I Have Made Payment';
                    }
                })
                .catch(error => {
                    alert('Error recording payment. Please try again.');
                    // Reset button
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-check-circle"></i> I Have Made Payment';
                });
        });

        // Show WhatsApp button after confirmation
        function showWhatsAppButton() {
            closeModal();
            document.getElementById('whatsappBtn').style.display = 'flex';
            document.getElementById('confirmPaymentBtn').style.display = 'none';

            // Update steps
            document.querySelectorAll('.step')[2].classList.add('completed');
            document.querySelectorAll('.step')[3].classList.add('active');
        }

        // Close modal
        function closeModal() {
            document.getElementById('confirmationModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('confirmationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Auto-update steps based on time (simulated progress)
        let currentStep = 1;

        function updateSteps() {
            const steps = document.querySelectorAll('.step');
            steps.forEach((step, index) => {
                step.classList.remove('active', 'completed');
                if (index + 1 < currentStep) {
                    step.classList.add('completed');
                } else if (index + 1 === currentStep) {
                    step.classList.add('active');
                }
            });
        }

        // Start step animation
        setTimeout(updateSteps, 1000);
    </script>
</body>

</html>