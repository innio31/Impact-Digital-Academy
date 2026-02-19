<?php
// includes/finance_functions.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Calculate total fee for a class including registration and course fees
 */
function calculateTotalFee($class_id, $program_type = 'online')
{
    $conn = getDBConnection();

    $sql = "SELECT cb.*, p.program_code, p.name as program_name,
                   p.base_fee, p.registration_fee,
                   pp.registration_fee as plan_registration_fee, 
                   pp.block1_percentage, pp.block2_percentage
            FROM class_batches cb
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            LEFT JOIN payment_plans pp ON pp.program_id = p.id AND pp.program_type = ?
            WHERE cb.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $program_type, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Get registration fee (use plan registration fee if available, otherwise program registration fee)
        $registration_fee = $row['plan_registration_fee'] ?? $row['registration_fee'] ?? 0;

        // Get program ID to fetch core courses and their fees
        $program_id = $row['id'];

        // Get all core courses for this program and their fees
        $core_courses_sql = "SELECT c.id, c.course_code, c.title, 
                                    cf.fee as course_fee, c.is_required
                             FROM courses c
                             LEFT JOIN course_fees cf ON cf.course_id = c.id 
                                   AND cf.program_id = ?
                             JOIN program_requirements pr ON pr.course_id = c.id 
                                   AND pr.program_id = ?
                             WHERE pr.course_type = 'core' 
                                   AND c.status = 'active'";

        $stmt2 = $conn->prepare($core_courses_sql);
        $stmt2->bind_param("ii", $program_id, $program_id);
        $stmt2->execute();
        $courses_result = $stmt2->get_result();

        $core_courses_fee = 0;
        $core_courses_count = 0;
        $course_fees = [];

        while ($course = $courses_result->fetch_assoc()) {
            $course_fee = $course['course_fee'] ?? 0;
            $core_courses_fee += $course_fee;
            $core_courses_count++;

            $course_fees[] = [
                'course_id' => $course['id'],
                'course_code' => $course['course_code'],
                'title' => $course['title'],
                'fee' => $course_fee,
                'is_required' => $course['is_required']
            ];
        }

        // Calculate total fee
        $total_course_fee = $core_courses_fee;
        $total_program_fee = $registration_fee + $total_course_fee;

        // For onsite programs (term-based), fee is per term
        // For online programs (block-based), fee is the full program fee
        if ($program_type == 'onsite') {
            // Assuming 3 terms per year, calculate per term fee
            $term_fee = $total_course_fee / 3;
            $term_total = $registration_fee + $term_fee;

            return [
                'registration_fee' => $registration_fee,
                'core_courses_count' => $core_courses_count,
                'total_course_fee' => $total_course_fee,
                'term_fee' => $term_fee,
                'total_fee' => $term_total,
                'course_fees' => $course_fees
            ];
        } else {
            // Online block-based
            $block1_amount = ($total_course_fee * ($row['block1_percentage'] ?? 70)) / 100;
            $block2_amount = ($total_course_fee * ($row['block2_percentage'] ?? 30)) / 100;

            return [
                'registration_fee' => $registration_fee,
                'core_courses_count' => $core_courses_count,
                'total_course_fee' => $total_course_fee,
                'block1_amount' => $block1_amount,
                'block2_amount' => $block2_amount,
                'total_fee' => $total_program_fee,
                'course_fees' => $course_fees
            ];
        }
    }

    return null;
}

/**
 * Set or update course fee for a program
 */
function setCourseFee($program_id, $course_id, $fee)
{
    $conn = getDBConnection();

    // Check if course belongs to program
    $check_sql = "SELECT id FROM courses WHERE id = ? AND program_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $course_id, $program_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        return ['success' => false, 'error' => 'Course does not belong to this program'];
    }

    // Insert or update course fee
    $sql = "INSERT INTO course_fees (program_id, course_id, fee, updated_by, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            fee = VALUES(fee), 
            updated_by = VALUES(updated_by),
            updated_at = NOW()";

    $user_id = $_SESSION['user_id'] ?? null;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iidi", $program_id, $course_id, $fee, $user_id);

    if ($stmt->execute()) {
        // Log activity
        logFinancialActivity(
            'course_fee_update',
            "Updated fee for course ID $course_id to " . formatCurrency($fee),
            null,
            null,
            null
        );

        return ['success' => true];
    }

    return ['success' => false, 'error' => 'Failed to set course fee'];
}

/**
 * Get all course fees for a program
 */
function getProgramCourseFees($program_id)
{
    $conn = getDBConnection();

    $sql = "SELECT c.id, c.course_code, c.title, c.description, c.is_required,
                   pr.course_type, cf.fee, cf.updated_at,
                   CONCAT(u.first_name, ' ', u.last_name) as updated_by_name
            FROM courses c
            JOIN program_requirements pr ON pr.course_id = c.id AND pr.program_id = ?
            LEFT JOIN course_fees cf ON cf.course_id = c.id AND cf.program_id = ?
            LEFT JOIN users u ON u.id = cf.updated_by
            WHERE c.program_id = ? AND c.status = 'active'
            ORDER BY c.order_number, c.course_code";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $program_id, $program_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $courses = [];
    $core_courses_total = 0;
    $elective_courses_total = 0;

    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;

        if ($row['course_type'] === 'core') {
            $core_courses_total += $row['fee'] ?? 0;
        } else {
            $elective_courses_total += $row['fee'] ?? 0;
        }
    }

    return [
        'courses' => $courses,
        'core_courses_total' => $core_courses_total,
        'elective_courses_total' => $elective_courses_total,
        'total_courses_fee' => $core_courses_total + $elective_courses_total
    ];
}

/**
 * Get student financial status for a class
 */
function getStudentFinancialStatus($student_id, $class_id)
{
    $conn = getDBConnection();

    $sql = "SELECT sfs.*, cb.program_type, p.program_code, p.name as program_name
            FROM student_financial_status sfs
            JOIN class_batches cb ON cb.id = sfs.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE sfs.student_id = ? AND sfs.class_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $status = $result->fetch_assoc();

    if (!$status) {
        // Create initial financial status if doesn't exist
        $fee_info = calculateTotalFee($class_id);
        if ($fee_info) {
            $sql = "INSERT INTO student_financial_status 
                    (student_id, class_id, total_fee, paid_amount, balance, next_payment_due)
                    VALUES (?, ?, ?, 0, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY))";

            $stmt = $conn->prepare($sql);
            $total_fee = $fee_info['total_fee'];
            $stmt->bind_param("iidd", $student_id, $class_id, $total_fee, $total_fee);
            $stmt->execute();

            // Fetch the newly created record
            return getStudentFinancialStatus($student_id, $class_id);
        }
    }

    return $status;
}

/**
 * Record a payment transaction
 */
function recordPaymentTransaction($student_id, $class_id, $amount, $payment_method, $transaction_type, $description = null)
{
    $conn = getDBConnection();

    // Generate transaction reference
    $reference = 'TRX' . date('YmdHis') . rand(1000, 9999);

    $sql = "INSERT INTO financial_transactions 
            (student_id, class_id, transaction_type, payment_method, amount, 
             gateway_reference, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iissdss",
        $student_id,
        $class_id,
        $transaction_type,
        $payment_method,
        $amount,
        $reference,
        $description
    );

    if ($stmt->execute()) {
        $transaction_id = $conn->insert_id;

        // Update student financial status
        updateFinancialStatusAfterPayment($student_id, $class_id, $amount, $transaction_type);

        // Generate receipt
        $receipt_url = generateReceipt($transaction_id);

        // Log financial activity
        logFinancialActivity(
            'payment_received',
            "Payment of " . formatCurrency($amount) .
                " received from student ID: $student_id",
            $student_id,
            $class_id,
            $transaction_id
        );

        return [
            'success' => true,
            'transaction_id' => $transaction_id,
            'reference' => $reference,
            'receipt_url' => $receipt_url
        ];
    }

    return ['success' => false, 'error' => 'Failed to record transaction'];
}
/**
 * Update program total fee based on course fees
 */
function updateProgramTotalFee($program_id)
{
    $conn = getDBConnection();

    // Get registration fee
    $sql = "SELECT registration_fee FROM programs WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $program = $result->fetch_assoc();

    if (!$program) return false;

    $registration_fee = $program['registration_fee'] ?? 0;

    // Get total core courses fee
    $sql = "SELECT SUM(cf.fee) as total_core_fee
            FROM courses c
            JOIN program_requirements pr ON pr.course_id = c.id AND pr.program_id = ?
            LEFT JOIN course_fees cf ON cf.course_id = c.id AND cf.program_id = ?
            WHERE pr.course_type = 'core' AND c.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $program_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $total_core_fee = $row['total_core_fee'] ?? 0;
    $total_fee = $registration_fee + $total_core_fee;

    // Update program fee
    $update_sql = "UPDATE programs SET fee = ?, base_fee = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ddi", $total_fee, $total_core_fee, $program_id);

    return $update_stmt->execute();
}

/**
 * Update financial status after payment
 */
function updateFinancialStatusAfterPayment($student_id, $class_id, $amount, $transaction_type)
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM student_financial_status 
            WHERE student_id = ? AND class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $status = $result->fetch_assoc();

    if ($status) {
        $new_paid = $status['paid_amount'] + $amount;
        $new_balance = $status['total_fee'] - $new_paid;

        // Determine which payment block this belongs to
        if ($transaction_type == 'registration') {
            $update_field = 'registration_paid = 1, registration_paid_date = CURDATE()';
        } elseif ($transaction_type == 'tuition') {
            // Check if this completes block1 or block2
            $fee_info = calculateTotalFee($class_id);

            if (!$status['block1_paid'] && $new_paid >= $fee_info['registration_fee'] + $fee_info['block1_amount']) {
                $update_field = 'block1_paid = 1, block1_paid_date = CURDATE(), current_block = 2';
                $next_due = date('Y-m-d', strtotime('+30 days'));
            } elseif ($status['block1_paid'] && !$status['block2_paid']) {
                $update_field = 'block2_paid = 1, block2_paid_date = CURDATE(), current_block = 3';
                $next_due = null;
            } else {
                $update_field = '';
            }
        }

        // Check if fully paid
        $is_cleared = ($new_balance <= 0) ? 1 : 0;

        $sql = "UPDATE student_financial_status 
                SET paid_amount = ?, balance = ?, is_cleared = ?, 
                    next_payment_due = ? $update_field
                WHERE student_id = ? AND class_id = ?";

        $stmt = $conn->prepare($sql);
        $next_due = isset($next_due) ? $next_due : $status['next_payment_due'];
        $stmt->bind_param("ddissi", $new_paid, $new_balance, $is_cleared, $next_due, $student_id, $class_id);
        $stmt->execute();

        // If cleared, send clearance notification
        if ($is_cleared) {
            sendPaymentNotification($student_id, $class_id, 'cleared');
        }
    }
}

/**
 * Generate invoice for student
 */
function generateInvoice($student_id, $class_id, $invoice_type = 'tuition_block1')
{
    $conn = getDBConnection();

    // Get student and class info
    $sql = "SELECT u.first_name, u.last_name, u.email, 
                   cb.batch_code, c.title as course_title, p.program_code,
                   sfs.total_fee, sfs.paid_amount, sfs.balance
            FROM users u
            JOIN student_financial_status sfs ON sfs.student_id = u.id
            JOIN class_batches cb ON cb.id = sfs.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE u.id = ? AND sfs.class_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $info = $result->fetch_assoc();

    if (!$info) return null;

    // Calculate invoice amount based on type
    $fee_info = calculateTotalFee($class_id);
    $amount = 0;

    switch ($invoice_type) {
        case 'registration':
            $amount = $fee_info['registration_fee'];
            $description = "Registration Fee for " . $info['course_title'];
            break;
        case 'tuition_block1':
            $amount = $fee_info['block1_amount'];
            $description = "Block 1 Tuition Fee for " . $info['course_title'];
            break;
        case 'tuition_block2':
            $amount = $fee_info['block2_amount'];
            $description = "Block 2 Tuition Fee for " . $info['course_title'];
            break;
        default:
            $amount = $fee_info['total_fee'];
            $description = "Program Fee for " . $info['course_title'];
    }

    // Generate invoice number
    $invoice_number = 'INV' . date('Ymd') . str_pad($student_id, 6, '0', STR_PAD_LEFT);

    // Set due date (30 days from now)
    $due_date = date('Y-m-d', strtotime('+30 days'));

    $sql = "INSERT INTO invoices 
            (invoice_number, student_id, class_id, invoice_type, amount, 
             due_date, status, balance)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "siissdd",
        $invoice_number,
        $student_id,
        $class_id,
        $invoice_type,
        $amount,
        $due_date,
        $amount
    );

    if ($stmt->execute()) {
        $invoice_id = $conn->insert_id;

        // Send notification to student
        sendInvoiceNotification($student_id, $invoice_id, $invoice_number, $amount);

        return [
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'amount' => $amount,
            'due_date' => $due_date
        ];
    }

    return null;
}

/**
 * Check for overdue payments and apply late fees
 */
function checkOverduePayments()
{
    $conn = getDBConnection();
    $today = date('Y-m-d');

    // Find overdue invoices
    $sql = "SELECT i.*, u.email, u.first_name, u.last_name, 
                   cb.batch_code, c.title as course_title
            FROM invoices i
            JOIN users u ON u.id = i.student_id
            JOIN class_batches cb ON cb.id = i.class_id
            JOIN courses c ON c.id = cb.course_id
            WHERE i.status = 'pending' 
            AND i.due_date < ? 
            AND i.due_date > DATE_SUB(?, INTERVAL 7 DAY)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $today, $today);
    $stmt->execute();
    $result = $stmt->get_result();

    $overdue_invoices = $result->fetch_all(MYSQLI_ASSOC);

    foreach ($overdue_invoices as $invoice) {
        // Apply late fee
        $late_fee = $invoice['amount'] * 0.05; // 5% late fee

        // Create late fee invoice
        $late_invoice_number = 'LATE' . $invoice['invoice_number'];

        $sql = "INSERT INTO invoices 
                (invoice_number, student_id, class_id, invoice_type, 
                 amount, due_date, status, balance, notes)
                VALUES (?, ?, ?, 'late_fee', ?, ?, 'pending', ?, 
                       'Late payment penalty for invoice {$invoice['invoice_number']}')";

        $stmt = $conn->prepare($sql);
        $new_due = date('Y-m-d', strtotime('+7 days'));
        $stmt->bind_param(
            "siisdd",
            $late_invoice_number,
            $invoice['student_id'],
            $invoice['class_id'],
            $late_fee,
            $new_due,
            $late_fee
        );
        $stmt->execute();

        // Send overdue notification
        sendOverdueNotification($invoice['student_id'], $invoice['invoice_number'], $late_fee);
    }

    // Check for auto-suspension (21 days overdue)
    $sql = "SELECT sfs.*, u.email, u.first_name, u.last_name, cb.batch_code
            FROM student_financial_status sfs
            JOIN users u ON u.id = sfs.student_id
            JOIN class_batches cb ON cb.id = sfs.class_id
            WHERE sfs.balance > 0 
            AND sfs.next_payment_due IS NOT NULL 
            AND sfs.next_payment_due < DATE_SUB(?, INTERVAL 21 DAY)
            AND sfs.is_suspended = 0";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($student = $result->fetch_assoc()) {
        // Suspend student
        $sql = "UPDATE student_financial_status 
                SET is_suspended = 1, suspended_at = NOW(),
                    suspension_reason = 'Automatic suspension due to overdue payment'
                WHERE student_id = ? AND class_id = ?";

        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param("ii", $student['student_id'], $student['class_id']);
        $stmt2->execute();

        // Update enrollment status
        $sql = "UPDATE enrollments 
                SET status = 'suspended' 
                WHERE student_id = ? AND class_id = ?";

        $stmt3 = $conn->prepare($sql);
        $stmt3->bind_param("ii", $student['student_id'], $student['class_id']);
        $stmt3->execute();

        sendSuspensionNotification($student['student_id'], $student['class_id']);
    }

    return count($overdue_invoices);
}

/**
 * Generate receipt for payment
 */
function generateReceipt($transaction_id)
{
    $conn = getDBConnection();

    $sql = "SELECT ft.*, u.first_name, u.last_name, u.email, u.phone,
                   cb.batch_code, c.title as course_title, p.name as program_name
            FROM financial_transactions ft
            JOIN users u ON u.id = ft.student_id
            JOIN class_batches cb ON cb.id = ft.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE ft.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaction = $result->fetch_assoc();

    if (!$transaction) return null;

    // Generate receipt HTML
    $receipt_html = "
        <!DOCTYPE html>
        <html>
        <head>
            <title>Payment Receipt - " . getSetting('site_name', 'Impact Digital Academy') . "</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .receipt { border: 2px solid #333; padding: 30px; max-width: 800px; margin: 0 auto; }
                .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
                .details { margin: 20px 0; }
                .row { display: flex; justify-content: space-between; margin: 10px 0; }
                .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
                .paid-stamp { color: green; font-weight: bold; font-size: 24px; text-align: center; }
            </style>
        </head>
        <body>
            <div class='receipt'>
                <div class='header'>
                    <h1>" . getSetting('site_name', 'Impact Digital Academy') . "</h1>
                    <h2>OFFICIAL PAYMENT RECEIPT</h2>
                    <p>Transaction #: {$transaction['gateway_reference']}</p>
                    <p>Date: " . formatDate($transaction['created_at'], 'F d, Y h:i A') . "</p>
                </div>
                
                <div class='paid-stamp'>PAID</div>
                
                <div class='details'>
                    <div class='row'>
                        <span><strong>Student:</strong></span>
                        <span>{$transaction['first_name']} {$transaction['last_name']}</span>
                    </div>
                    <div class='row'>
                        <span><strong>Program:</strong></span>
                        <span>{$transaction['program_name']} - {$transaction['course_title']}</span>
                    </div>
                    <div class='row'>
                        <span><strong>Batch:</strong></span>
                        <span>{$transaction['batch_code']}</span>
                    </div>
                    <div class='row'>
                        <span><strong>Payment Type:</strong></span>
                        <span>" . ucfirst(str_replace('_', ' ', $transaction['transaction_type'])) . "</span>
                    </div>
                    <div class='row'>
                        <span><strong>Payment Method:</strong></span>
                        <span>" . ucfirst(str_replace('_', ' ', $transaction['payment_method'])) . "</span>
                    </div>
                    <div class='row'>
                        <span><strong>Amount:</strong></span>
                        <span>" . formatCurrency($transaction['amount']) . "</span>
                    </div>
                    <div class='row'>
                        <span><strong>Description:</strong></span>
                        <span>{$transaction['description']}</span>
                    </div>
                </div>
                
                <div class='footer'>
                    <p>This is an official receipt from " . getSetting('site_name', 'Impact Digital Academy') . "</p>
                    <p>For any inquiries, contact: " . getSetting('site_email', 'info@impactacademy.edu') . "</p>
                    <p>Receipt generated on: " . date('F d, Y h:i A') . "</p>
                </div>
            </div>
        </body>
        </html>
    ";

    // Save receipt to file
    $filename = 'receipt_' . $transaction['gateway_reference'] . '.html';
    $filepath = dirname(__DIR__) . '/public/uploads/receipts/' . $filename;

    // Ensure directory exists
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($filepath, $receipt_html);

    // Update transaction with receipt URL
    $receipt_url = '/public/uploads/receipts/' . $filename;
    $sql = "UPDATE financial_transactions SET receipt_url = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $receipt_url, $transaction_id);
    $stmt->execute();

    return $receipt_url;
}

/**
 * Send payment notification
 */
function sendPaymentNotification($student_id, $class_id, $type)
{
    $conn = getDBConnection();

    $sql = "SELECT u.email, u.first_name, u.last_name, 
                   cb.batch_code, c.title as course_title,
                   sfs.total_fee, sfs.paid_amount, sfs.balance
            FROM users u
            JOIN student_financial_status sfs ON sfs.student_id = u.id
            JOIN class_batches cb ON cb.id = sfs.class_id
            JOIN courses c ON c.id = cb.course_id
            WHERE u.id = ? AND sfs.class_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $info = $result->fetch_assoc();

    if (!$info) return;

    $titles = [
        'payment_received' => 'Payment Received',
        'invoice_generated' => 'New Invoice Generated',
        'overdue' => 'Payment Overdue Notice',
        'suspended' => 'Account Suspension Notice',
        'cleared' => 'Payment Clearance Confirmation'
    ];

    $messages = [
        'payment_received' => "Your payment has been received and processed successfully.",
        'invoice_generated' => "A new invoice has been generated for your course enrollment.",
        'overdue' => "Your payment is overdue. Please settle your balance to avoid suspension.",
        'suspended' => "Your account has been suspended due to overdue payments.",
        'cleared' => "Congratulations! Your payments are now fully cleared."
    ];

    $title = $titles[$type] ?? 'Financial Notification';
    $message_content = $messages[$type] ?? "You have a new financial notification.";

    // Add financial details to message
    if (in_array($type, ['payment_received', 'overdue', 'cleared'])) {
        $message_content .= "\n\n";
        $message_content .= "Program: {$info['course_title']} - {$info['batch_code']}\n";
        $message_content .= "Total Fee: " . formatCurrency($info['total_fee']) . "\n";
        $message_content .= "Paid Amount: " . formatCurrency($info['paid_amount']) . "\n";
        $message_content .= "Balance: " . formatCurrency($info['balance']);
    }

    // Send internal message (sender_id = 0 for system messages)
    sendInternalMessage(
        0, // System sender
        $student_id,
        $message_content,
        $title,
        $type . '_notification',
        ['class_id' => $class_id]
    );

    // Also send email (implement email function separately)
    // sendEmail($info['email'], $title, $message_content);
}

/**
 * Log financial activity
 */
function logFinancialActivity($action, $description, $student_id = null, $class_id = null, $transaction_id = null)
{
    $conn = getDBConnection();

    $user_id = $_SESSION['user_id'] ?? null;

    $sql = "INSERT INTO financial_logs 
            (action, description, user_id, student_id, class_id, transaction_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt->bind_param(
        "ssiiiis",
        $action,
        $description,
        $user_id,
        $student_id,
        $class_id,
        $transaction_id,
        $ip_address
    );
    $stmt->execute();
}

/**
 * Get payment history for student
 */
function getStudentPaymentHistory($student_id, $limit = 50)
{
    $conn = getDBConnection();

    $sql = "SELECT ft.*, cb.batch_code, c.title as course_title
            FROM financial_transactions ft
            LEFT JOIN class_batches cb ON cb.id = ft.class_id
            LEFT JOIN courses c ON c.id = cb.course_id
            WHERE ft.student_id = ? AND ft.status = 'completed'
            ORDER BY ft.created_at DESC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get outstanding balances for student
 */
function getStudentOutstandingBalances($student_id)
{
    $conn = getDBConnection();

    $sql = "SELECT sfs.*, cb.batch_code, c.title as course_title, 
                   p.program_code, p.name as program_name,
                   DATEDIFF(sfs.next_payment_due, CURDATE()) as days_until_due
            FROM student_financial_status sfs
            JOIN class_batches cb ON cb.id = sfs.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE sfs.student_id = ? AND sfs.balance > 0
            ORDER BY sfs.next_payment_due ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Process online payment (PayStack/FlutterWave integration)
 */
function processOnlinePayment($student_id, $class_id, $amount, $payment_method, $payment_data)
{
    $conn = getDBConnection();

    // Initialize payment gateway based on selection
    if ($payment_method == 'paystack') {
        $result = processPaystackPayment($amount, $payment_data);
    } elseif ($payment_method == 'flutterwave') {
        $result = processFlutterwavePayment($amount, $payment_data);
    } else {
        return ['success' => false, 'error' => 'Invalid payment method'];
    }

    if ($result['success']) {
        // Record the transaction
        $transaction_result = recordPaymentTransaction(
            $student_id,
            $class_id,
            $amount,
            $payment_method,
            'tuition',
            "Online payment via " . ucfirst($payment_method) . " - Ref: " . $result['reference']
        );

        if ($transaction_result['success']) {
            return [
                'success' => true,
                'message' => 'Payment processed successfully',
                'transaction_id' => $transaction_result['transaction_id'],
                'receipt_url' => $transaction_result['receipt_url']
            ];
        }
    }

    return ['success' => false, 'error' => $result['error'] ?? 'Payment processing failed'];
}

/**
 * Process PayStack payment (mock implementation)
 */
function processPaystackPayment($amount, $payment_data)
{
    // This is a mock implementation
    // In production, integrate with PayStack API

    // Simulate API call
    $reference = 'PSK' . date('YmdHis') . rand(1000, 9999);

    // Mock successful payment
    return [
        'success' => true,
        'reference' => $reference,
        'gateway_response' => 'Payment successful'
    ];
}

/**
 * Process FlutterWave payment (mock implementation)
 */
function processFlutterwavePayment($amount, $payment_data)
{
    // This is a mock implementation
    // In production, integrate with FlutterWave API

    // Simulate API call
    $reference = 'FLW' . date('YmdHis') . rand(1000, 9999);

    // Mock successful payment
    return [
        'success' => true,
        'reference' => $reference,
        'gateway_response' => 'Payment successful'
    ];
}

/**
 * Verify payment with gateway
 */
function verifyPayment($gateway, $reference)
{
    $conn = getDBConnection();

    // Check if already verified
    $sql = "SELECT * FROM financial_transactions 
            WHERE gateway_reference = ? AND is_verified = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return ['success' => true, 'message' => 'Payment already verified'];
    }

    // Verify with gateway
    if ($gateway == 'paystack') {
        $verification = verifyPaystackPayment($reference);
    } elseif ($gateway == 'flutterwave') {
        $verification = verifyFlutterwavePayment($reference);
    } else {
        return ['success' => false, 'error' => 'Invalid gateway'];
    }

    if ($verification['success']) {
        // Update transaction as verified
        $sql = "UPDATE financial_transactions 
                SET is_verified = 1, verified_at = NOW(), verified_by = ?
                WHERE gateway_reference = ?";

        $stmt = $conn->prepare($sql);
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt->bind_param("is", $user_id, $reference);
        $stmt->execute();

        return ['success' => true, 'message' => 'Payment verified successfully'];
    }

    return ['success' => false, 'error' => $verification['error'] ?? 'Verification failed'];
}

/**
 * Get payment plan configuration
 */
function getPaymentPlan($program_id, $program_type = 'online')
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM payment_plans 
            WHERE program_id = ? AND program_type = ? AND is_active = 1
            ORDER BY created_at DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $program_id, $program_type);
    $stmt->execute();
    $result = $stmt->get_result();

    $plan = $result->fetch_assoc();

    if (!$plan) {
        // Return default payment plan
        return [
            'plan_name' => 'Default Payment Plan',
            'registration_fee' => 10000.00,
            'block1_percentage' => 70.00,
            'block1_due_days' => 30,
            'block2_percentage' => 30.00,
            'block2_due_days' => 60,
            'late_fee_percentage' => 5.00,
            'suspension_days' => 21,
            'refund_policy_days' => 14
        ];
    }

    return $plan;
}

/**
 * Export financial data
 */
function exportFinancialData($type, $start_date, $end_date)
{
    $conn = getDBConnection();

    $date_filter = "ft.created_at BETWEEN ? AND ?";
    $params = [$start_date, $end_date];

    switch ($type) {
        case 'transactions':
            $sql = "SELECT ft.*, u.first_name, u.last_name, u.email,
                           cb.batch_code, c.title as course_title,
                           p.name as program_name
                    FROM financial_transactions ft
                    JOIN users u ON u.id = ft.student_id
                    JOIN class_batches cb ON cb.id = ft.class_id
                    JOIN courses c ON c.id = cb.course_id
                    JOIN programs p ON p.program_code = c.program_id
                    WHERE $date_filter
                    ORDER BY ft.created_at DESC";
            break;

        case 'invoices':
            $sql = "SELECT i.*, u.first_name, u.last_name, u.email,
                           cb.batch_code, c.title as course_title
                    FROM invoices i
                    JOIN users u ON u.id = i.student_id
                    JOIN class_batches cb ON cb.id = i.class_id
                    JOIN courses c ON c.id = cb.course_id
                    WHERE $date_filter
                    ORDER BY i.due_date";
            break;

        case 'outstanding':
            $sql = "SELECT sfs.*, u.first_name, u.last_name, u.email,
                           cb.batch_code, c.title as course_title,
                           p.name as program_name,
                           DATEDIFF(sfs.next_payment_due, CURDATE()) as days_overdue
                    FROM student_financial_status sfs
                    JOIN users u ON u.id = sfs.student_id
                    JOIN class_batches cb ON cb.id = sfs.class_id
                    JOIN courses c ON c.id = cb.course_id
                    JOIN programs p ON p.program_code = c.program_id
                    WHERE sfs.balance > 0 AND sfs.created_at BETWEEN ? AND ?
                    ORDER BY sfs.next_payment_due";
            break;

        default:
            return null;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_all(MYSQLI_ASSOC);
}

// Mock functions for payment gateway verification
function verifyPaystackPayment($reference)
{
    // Mock implementation
    return ['success' => true, 'message' => 'Payment verified'];
}

function verifyFlutterwavePayment($reference)
{
    // Mock implementation
    return ['success' => true, 'message' => 'Payment verified'];
}

// Update sendInvoiceNotification, sendOverdueNotification, sendSuspensionNotification to use internal mail
function sendInvoiceNotification($student_id, $invoice_id, $invoice_number, $amount)
{
    $title = "New Invoice Generated";
    $message = "Invoice #$invoice_number for " . formatCurrency($amount) . " has been generated.\n\n";
    $message .= "Invoice Number: $invoice_number\n";
    $message .= "Amount: " . formatCurrency($amount) . "\n";
    $message .= "Please make payment before the due date to avoid late fees.";

    sendInternalMessage(
        0,
        $student_id,
        $message,
        $title,
        'invoice_notification',
        ['invoice_id' => $invoice_id]
    );
}

function sendOverdueNotification($student_id, $invoice_number, $late_fee)
{
    $title = "Payment Overdue Notice";
    $message = "Invoice #$invoice_number is overdue. A late fee of " .
        formatCurrency($late_fee) . " has been applied.\n\n";
    $message .= "Please settle this payment immediately to avoid account suspension.";

    sendInternalMessage(
        0,
        $student_id,
        $message,
        $title,
        'overdue_notification',
        ['invoice_number' => $invoice_number]
    );
}

function sendSuspensionNotification($student_id, $class_id)
{
    $title = "Account Suspension Notice";
    $message = "Your account has been suspended due to overdue payments.\n";
    $message .= "Please contact the finance department to restore access.\n\n";
    $message .= "Note: You will not be able to access course materials or submit assignments until your account is reinstated.";

    sendInternalMessage(
        0,
        $student_id,
        $message,
        $title,
        'suspension_notification',
        ['class_id' => $class_id]
    );
}

/**
 * Send invoice via SMS (mock implementation)
 */
function sendInvoiceSMS($student_id, $invoice_id, $invoice_number, $amount, $custom_message = '')
{
    // This is a mock implementation
    // In production, integrate with SMS gateway API

    $conn = getDBConnection();

    // Get student phone number
    $sql = "SELECT phone FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();

    if (!$student || empty($student['phone'])) {
        return false;
    }

    // Mock SMS sending
    logFinancialActivity(
        'sms_sent',
        "SMS sent for invoice #$invoice_number to student ID: $student_id",
        $student_id,
        null,
        $invoice_id
    );

    return true;
}

/**
 * Generate export data for various report types - ENHANCED VERSION
 */
function generateExport($report_type, $filters = [], $format = 'csv')
{
    $conn = getDBConnection();

    // Validate connection
    if (!$conn) {
        $conn = getDBConnection();
    }

    $data = [];
    $headers = [];

    // Build WHERE clause based on filters
    $where_conditions = [];
    $params = [];
    $param_types = '';

    // Date filters
    if (!empty($filters['date_from'])) {
        $where_conditions[] = "created_at >= ?";
        $params[] = $filters['date_from'];
        $param_types .= 's';
    }

    if (!empty($filters['date_to'])) {
        $where_conditions[] = "created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
        $param_types .= 's';
    }

    // Build queries for different report types
    switch ($report_type) {
        case 'revenue':
            $query = "SELECT 
                p.program_type,
                p.name as program_name,
                p.program_code,
                COUNT(ft.id) as transaction_count,
                SUM(ft.amount) as total_amount,
                AVG(ft.amount) as avg_amount,
                MIN(ft.amount) as min_amount,
                MAX(ft.amount) as max_amount
            FROM financial_transactions ft
            JOIN class_batches cb ON cb.id = ft.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id";

            // Add program type filter
            if (!empty($filters['program_type'])) {
                $where_conditions[] = "p.program_type = ?";
                $params[] = $filters['program_type'];
                $param_types .= 's';
            }

            // Add payment method filter
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $where_conditions[] = "ft.payment_method = ?";
                $params[] = $filters['payment_method'];
                $param_types .= 's';
            }

            // Add status filter
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where_conditions[] = "ft.status = ?";
                $params[] = $filters['status'];
                $param_types .= 's';
            }

            $query .= " WHERE ft.status = 'completed'";
            if (!empty($where_conditions)) {
                $query .= " AND " . implode(' AND ', $where_conditions);
            }
            $query .= " GROUP BY p.id, p.program_type ORDER BY total_amount DESC";

            $headers = ['Program Type', 'Program Code', 'Program Name', 'Transactions', 'Total Revenue', 'Average Amount', 'Min Amount', 'Max Amount'];
            break;

        case 'outstanding':
            $query = "SELECT 
                i.invoice_number,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email,
                p.name as program_name,
                p.program_type,
                cb.batch_code,
                c.title as course_title,
                i.amount,
                i.paid_amount,
                (i.amount - i.paid_amount) as balance,
                i.due_date,
                i.status,
                i.invoice_type,
                i.created_at,
                DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM invoices i
            JOIN users u ON u.id = i.student_id
            JOIN class_batches cb ON cb.id = i.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE i.status IN ('pending', 'overdue', 'partial')";

            // Add date filters for due date
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "i.due_date >= ?";
                $params[] = $filters['date_from'];
                $param_types .= 's';
            }

            if (!empty($filters['date_to'])) {
                $where_conditions[] = "i.due_date <= ?";
                $params[] = $filters['date_to'];
                $param_types .= 's';
            }

            // Add program type filter
            if (!empty($filters['program_type'])) {
                $where_conditions[] = "p.program_type = ?";
                $params[] = $filters['program_type'];
                $param_types .= 's';
            }

            // Add status filter
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where_conditions[] = "i.status = ?";
                $params[] = $filters['status'];
                $param_types .= 's';
            }

            if (!empty($where_conditions)) {
                $query .= " AND " . implode(' AND ', $where_conditions);
            }
            $query .= " ORDER BY i.due_date ASC, balance DESC";

            $headers = ['Invoice #', 'Student Name', 'Email', 'Program', 'Program Type', 'Batch', 'Course', 'Amount', 'Paid', 'Balance', 'Due Date', 'Status', 'Invoice Type', 'Created Date', 'Days Overdue'];
            break;

        case 'collection':
            $query = "SELECT 
                DATE_FORMAT(ft.created_at, '%Y-%m') as month,
                p.name as program_name,
                p.program_type,
                COUNT(DISTINCT ft.student_id) as active_students,
                COUNT(ft.id) as transactions,
                SUM(ft.amount) as collected_amount,
                AVG(ft.amount) as avg_collection
            FROM financial_transactions ft
            JOIN class_batches cb ON cb.id = ft.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE ft.status = 'completed'";

            // Add program type filter
            if (!empty($filters['program_type'])) {
                $where_conditions[] = "p.program_type = ?";
                $params[] = $filters['program_type'];
                $param_types .= 's';
            }

            if (!empty($where_conditions)) {
                $query .= " AND " . implode(' AND ', $where_conditions);
            }
            $query .= " GROUP BY DATE_FORMAT(ft.created_at, '%Y-%m'), p.id ORDER BY month DESC";

            $headers = ['Month', 'Program Name', 'Program Type', 'Active Students', 'Transactions', 'Collected Amount', 'Average Collection'];
            break;

        case 'transactions':
            $query = "SELECT 
                ft.id,
                ft.transaction_type,
                ft.payment_method,
                ft.amount,
                ft.currency,
                ft.status,
                ft.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email,
                p.name as program_name,
                p.program_type,
                cb.batch_code,
                c.title as course_title,
                ft.description,
                ft.gateway_reference
            FROM financial_transactions ft
            JOIN users u ON u.id = ft.student_id
            JOIN class_batches cb ON cb.id = ft.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE 1=1";

            // Add program type filter
            if (!empty($filters['program_type'])) {
                $where_conditions[] = "p.program_type = ?";
                $params[] = $filters['program_type'];
                $param_types .= 's';
            }

            // Add payment method filter
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $where_conditions[] = "ft.payment_method = ?";
                $params[] = $filters['payment_method'];
                $param_types .= 's';
            }

            // Add status filter
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where_conditions[] = "ft.status = ?";
                $params[] = $filters['status'];
                $param_types .= 's';
            }

            // Add student filter
            if (!empty($filters['student_id'])) {
                $where_conditions[] = "ft.student_id = ?";
                $params[] = $filters['student_id'];
                $param_types .= 's';
            }

            if (!empty($where_conditions)) {
                $query .= " AND " . implode(' AND ', $where_conditions);
            }
            $query .= " ORDER BY ft.created_at DESC";

            $headers = ['ID', 'Type', 'Payment Method', 'Amount', 'Currency', 'Status', 'Date', 'Student', 'Email', 'Program', 'Program Type', 'Batch', 'Course', 'Description', 'Gateway Reference'];
            break;

        case 'invoices':
            $query = "SELECT 
                i.invoice_number,
                i.invoice_type,
                i.amount,
                i.paid_amount,
                (i.amount - i.paid_amount) as balance,
                i.due_date,
                i.status,
                i.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as student_name,
                u.email,
                p.name as program_name,
                p.program_type,
                cb.batch_code,
                c.title as course_title
            FROM invoices i
            JOIN users u ON u.id = i.student_id
            JOIN class_batches cb ON cb.id = i.class_id
            JOIN courses c ON c.id = cb.course_id
            JOIN programs p ON p.program_code = c.program_id
            WHERE 1=1";

            // Add program type filter
            if (!empty($filters['program_type'])) {
                $where_conditions[] = "p.program_type = ?";
                $params[] = $filters['program_type'];
                $param_types .= 's';
            }

            // Add status filter
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where_conditions[] = "i.status = ?";
                $params[] = $filters['status'];
                $param_types .= 's';
            }

            // Add invoice type filter
            if (!empty($filters['invoice_type']) && $filters['invoice_type'] !== 'all') {
                $where_conditions[] = "i.invoice_type = ?";
                $params[] = $filters['invoice_type'];
                $param_types .= 's';
            }

            if (!empty($where_conditions)) {
                $query .= " AND " . implode(' AND ', $where_conditions);
            }
            $query .= " ORDER BY i.created_at DESC";

            $headers = ['Invoice #', 'Type', 'Amount', 'Paid', 'Balance', 'Due Date', 'Status', 'Created Date', 'Student', 'Email', 'Program', 'Program Type', 'Batch', 'Course'];
            break;

        default:
            return false;
    }

    // Execute query
    if ($stmt = $conn->prepare($query)) {
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Format data based on export format
    if ($format === 'csv') {
        return formatAsCSV($data, $headers);
    } elseif ($format === 'json') {
        return json_encode([
            'report_type' => $report_type,
            'filters' => $filters,
            'headers' => $headers,
            'data' => $data,
            'generated_at' => date('Y-m-d H:i:s'),
            'record_count' => count($data)
        ], JSON_PRETTY_PRINT);
    }

    return false;
}

/**
 * Format data as CSV
 */
function formatAsCSV($data, $headers)
{
    $output = fopen('php://temp', 'w');

    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, $headers);

    // Write data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    return $csv;
}

/**
 * Format currency for display
 */
/**function formatCurrency($amount, $currency = 'NGN')
{
    if ($currency === 'NGN') {
        return '₦' . number_format($amount, 2);
    }
    return number_format($amount, 2) . ' ' . $currency;
}*/

/**
 * Get financial dashboard statistics - ENHANCED VERSION
 * This version is kept as it's more comprehensive than the simple version
 */
function getFinanceDashboardStats($period = 'month')
{
    $conn = getDBConnection();

    $date_from = '';
    $date_to = date('Y-m-d');

    // Set date range based on period
    switch ($period) {
        case 'today':
            $date_from = $date_to;
            break;
        case 'week':
            $date_from = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $date_from = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'year':
            $date_from = date('Y-m-d', strtotime('-365 days'));
            break;
        default:
            $date_from = date('Y-m-d', strtotime('-30 days'));
    }

    $stats = [
        'total_revenue' => 0,
        'pending_amount' => 0,
        'overdue_amount' => 0,
        'pending_payments_count' => 0,
        'overdue_count' => 0,
        'financial_issues_count' => 0,
        'recent_transactions' => [],
        'payment_methods' => []
    ];

// Get total revenue from registration_fee_payments, course_payments, and service_revenue
$revenue_sql = "SELECT 
                    COALESCE(SUM(CASE WHEN source = 'registration' THEN amount END), 0) as registration_revenue,
                    COALESCE(SUM(CASE WHEN source = 'tuition' THEN amount END), 0) as tuition_revenue,
                    COALESCE(SUM(CASE WHEN source = 'service' THEN amount END), 0) as service_revenue,
                    COALESCE(SUM(amount), 0) as total_revenue,
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN source = 'registration' THEN 1 ELSE 0 END) as registration_count,
                    SUM(CASE WHEN source = 'tuition' THEN 1 ELSE 0 END) as tuition_count,
                    SUM(CASE WHEN source = 'service' THEN 1 ELSE 0 END) as service_count
                FROM (
                    -- Registration payments
                    SELECT amount, 'registration' as source, created_at
                    FROM registration_fee_payments 
                    WHERE status = 'completed' 
                    AND DATE(created_at) BETWEEN ? AND ?
                    
                    UNION ALL
                    
                    -- Course/tuition payments
                    SELECT amount, 'tuition' as source, created_at
                    FROM course_payments 
                    WHERE status = 'completed' 
                    AND DATE(created_at) BETWEEN ? AND ?
                    
                    UNION ALL
                    
                    -- Service revenue
                    SELECT amount, 'service' as source, created_at
                    FROM service_revenue 
                    WHERE status = 'completed' 
                    AND DATE(created_at) BETWEEN ? AND ?
                ) combined_payments";
    if ($stmt = $conn->prepare($revenue_sql)) {
    // Bind 6 parameters now (2 for each revenue source: registration, tuition, service)
    $stmt->bind_param('ssssss', $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['total_revenue'] = $row['total_revenue'] ?? 0;
        $stats['registration_revenue'] = $row['registration_revenue'] ?? 0;
        $stats['tuition_revenue'] = $row['tuition_revenue'] ?? 0;
        $stats['service_revenue'] = $row['service_revenue'] ?? 0; // Added this
        $stats['total_transactions'] = $row['total_transactions'] ?? 0;
        $stats['registration_count'] = $row['registration_count'] ?? 0;
        $stats['tuition_count'] = $row['tuition_count'] ?? 0;
        $stats['service_count'] = $row['service_count'] ?? 0; // Added this
    }
    $stmt->close();
}

    // Get pending payments
    $pending_sql = "SELECT SUM(amount - paid_amount) as total, COUNT(*) as count 
                   FROM invoices WHERE status = 'pending'";
    if ($stmt = $conn->prepare($pending_sql)) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['pending_amount'] = $row['total'] ?? 0;
            $stats['pending_payments_count'] = $row['count'] ?? 0;
        }
        $stmt->close();
    }

    // Get overdue payments
    $overdue_sql = "SELECT SUM(amount - paid_amount) as total, COUNT(*) as count 
                   FROM invoices WHERE status = 'overdue'";
    if ($stmt = $conn->prepare($overdue_sql)) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['overdue_amount'] = $row['total'] ?? 0;
            $stats['overdue_count'] = $row['count'] ?? 0;
        }
        $stmt->close();
    }

    // Get financial issues (suspended students)
    $issues_sql = "SELECT COUNT(*) as count FROM student_financial_status 
                   WHERE is_suspended = 1";
    if ($stmt = $conn->prepare($issues_sql)) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['financial_issues_count'] = $row['count'] ?? 0;
        }
        $stmt->close();
    }

    // Recent transactions (from original function)
    $recent_sql = "SELECT ft.*, u.first_name, u.last_name, cb.batch_code 
            FROM financial_transactions ft
            JOIN users u ON u.id = ft.student_id
            JOIN class_batches cb ON cb.id = ft.class_id
            WHERE ft.status = 'completed' 
            ORDER BY ft.created_at DESC LIMIT 10";
    if ($stmt = $conn->prepare($recent_sql)) {
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['recent_transactions'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Payment method distribution (from original function)
    $payment_method_sql = "SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
            FROM financial_transactions 
            WHERE status = 'completed' AND created_at BETWEEN ? AND ?
            GROUP BY payment_method";
    if ($stmt = $conn->prepare($payment_method_sql)) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['payment_methods'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    return $stats;
}

// Process PaymentVerification function with this corrected version:
function processPaymentVerification($payment_id, $conn)
{
    error_log("=== Starting processPaymentVerification for ID: $payment_id ===");

    // Get payment details
    $sql = "SELECT pv.*, u.email, u.first_name, u.last_name 
            FROM payment_verifications pv
            JOIN users u ON pv.student_id = u.id
            WHERE pv.id = ?";

    error_log("SQL Query: $sql");

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    $stmt->close();

    if (!$payment) {
        error_log("ERROR: Payment not found for ID: $payment_id");
        return false;
    }

    error_log("Payment status: " . ($payment['status'] ?? 'not set'));
    error_log("Payment type: " . ($payment['payment_type'] ?? 'not set'));

    // Check if already fully processed (has financial transaction)
    $check_processed_sql = "SELECT ft.id FROM financial_transactions ft 
                           WHERE ft.gateway_reference = ? 
                           OR ft.gateway_reference LIKE CONCAT('MANUAL-', ?)";
    $check_stmt = $conn->prepare($check_processed_sql);
    $check_stmt->bind_param("ss", $payment['payment_reference'], $payment['payment_reference']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $transaction = $check_result->fetch_assoc();
        error_log("Payment already processed with financial transaction ID: " . $transaction['id']);
        $check_stmt->close();
        return true; // Already processed
    }
    $check_stmt->close();

    // Also check registration_fee_payments for registration payments
    if ($payment['payment_type'] === 'registration') {
        $check_reg_sql = "SELECT id FROM registration_fee_payments 
                         WHERE transaction_reference = ? 
                         OR transaction_reference LIKE CONCAT('MANUAL-', ?)";
        $check_reg_stmt = $conn->prepare($check_reg_sql);
        $check_reg_stmt->bind_param("ss", $payment['payment_reference'], $payment['payment_reference']);
        $check_reg_stmt->execute();
        $check_reg_result = $check_reg_stmt->get_result();

        if ($check_reg_result->num_rows > 0) {
            $reg_payment = $check_reg_result->fetch_assoc();
            error_log("Registration payment already exists with ID: " . $reg_payment['id']);
            $check_reg_stmt->close();
            return true; // Already processed
        }
        $check_reg_stmt->close();
    }

    // Ensure required fields are set
    if (!isset($payment['payment_reference']) || empty($payment['payment_reference'])) {
        error_log("ERROR: Payment reference missing for payment ID: $payment_id");
        return false;
    }

    // Start transaction
    $conn->begin_transaction();
    error_log("Transaction started");

    try {
        if ($payment['payment_type'] === 'registration') {
            error_log("Processing registration payment");
            if (!isset($payment['program_id'])) {
                throw new Exception("Program ID is required for registration payment");
            }
            $success = processRegistrationPayment($payment, $conn);
        } else if ($payment['payment_type'] === 'course') {
            error_log("Processing course payment");
            if (!isset($payment['course_id']) || !isset($payment['class_id'])) {
                error_log("Missing course_id or class_id. Course ID: " . ($payment['course_id'] ?? 'not set') .
                    ", Class ID: " . ($payment['class_id'] ?? 'not set'));
                throw new Exception("Course ID and Class ID are required for course payment");
            }
            $success = processCoursePayment($payment, $conn);
        } else {
            error_log("ERROR: Unknown payment type: " . $payment['payment_type']);
            throw new Exception("Unknown payment type: " . $payment['payment_type']);
        }

        error_log("Payment processing result: " . ($success ? "SUCCESS" : "FAILED"));

        if ($success) {
            // Create financial transaction record
            error_log("Creating financial transaction...");
            $transaction_id = createFinancialTransaction($payment, $conn);

            if (!$transaction_id) {
                error_log("ERROR: Failed to create financial transaction");
                throw new Exception("Failed to create financial transaction");
            }
            error_log("Financial transaction created with ID: $transaction_id");

            // Update student financial status
            error_log("Updating student financial status...");
            $status_updated = updateStudentFinancialStatus($payment, $transaction_id, $conn);
            error_log("Student status update result: " . ($status_updated ? "SUCCESS" : "FAILED"));

            // Generate receipt
            error_log("Generating receipt...");
            $receipt_url = generatePaymentReceipt($payment, $transaction_id, $conn);
            error_log("Receipt URL: $receipt_url");

            // Send confirmation notification
            error_log("Sending confirmation...");
            $confirmation_sent = sendPaymentConfirmationNotification($payment, $receipt_url, $conn);
            error_log("Confirmation sent: " . ($confirmation_sent ? "YES" : "NO"));

            // Log financial activity
            error_log("Logging financial activity...");
            if (function_exists('logFinancialActivity')) {
                logFinancialActivity(
                    'payment_verified',
                    "Payment verified: {$payment['payment_reference']} - ₦" . number_format($payment['amount'], 2),
                    $payment['student_id'],
                    $payment['class_id'] ?? null,
                    $transaction_id
                );
                error_log("Financial activity logged");
            } else {
                error_log("WARNING: logFinancialActivity function not found");
            }

            $conn->commit();
            error_log("Transaction committed successfully!");
            return true;
        } else {
            $conn->rollback();
            error_log("Payment processing failed, rolling back transaction");
            return false;
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("EXCEPTION in processPaymentVerification: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}



// Process registration payment - UPDATED VERSION
function processRegistrationPayment($payment, $conn)
{
    error_log("=== Starting processRegistrationPayment ===");

    // 1. Insert into registration_fee_payments
    $sql = "INSERT INTO registration_fee_payments 
            (student_id, program_id, amount, payment_method, 
             transaction_reference, status, payment_date, created_at)
            VALUES (?, ?, ?, ?, ?, 'completed', CURDATE(), NOW())";

    $stmt = $conn->prepare($sql);
    $transaction_ref = $payment['payment_reference'];
    $payment_method = $payment['payment_method'] ?? 'bank_transfer';

    $stmt->bind_param(
        "iidss",
        $payment['student_id'],
        $payment['program_id'],
        $payment['amount'],
        $payment_method,
        $transaction_ref
    );

    if ($stmt->execute()) {
        $payment_id = $stmt->insert_id;
        error_log("✓ Registration payment recorded with ID: $payment_id");
        $stmt->close();

        // 2. Update applications table
        $update_sql = "UPDATE applications 
                      SET registration_fee_paid = 1,
                          registration_paid_date = NOW(),
                          updated_at = NOW()
                      WHERE user_id = ? AND program_id = ? AND status = 'approved'";

        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $payment['student_id'], $payment['program_id']);
        $update_stmt->execute();
        $update_stmt->close();

        // 3. Create enrollment if not exists
        $enroll_sql = "INSERT INTO enrollments 
                      (student_id, program_id, status, enrollment_date, created_at)
                      VALUES (?, ?, 'active', CURDATE(), NOW())
                      ON DUPLICATE KEY UPDATE 
                      status = VALUES(status), updated_at = NOW()";

        $enroll_stmt = $conn->prepare($enroll_sql);
        $enroll_stmt->bind_param("ii", $payment['student_id'], $payment['program_id']);
        $enroll_stmt->execute();
        $enroll_stmt->close();

        return $payment_id;
    } else {
        error_log("✗ ERROR inserting registration payment: " . $conn->error);
        $stmt->close();
        return false;
    }
}

// Process course payment
function processCoursePayment($payment, $conn)
{
    error_log("=== Starting processCoursePayment ===");

    // 1. Insert into course_payments
    $sql = "INSERT INTO course_payments 
            (student_id, course_id, class_id, amount, payment_method,
             transaction_reference, status, payment_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'completed', CURDATE(), NOW())";

    $stmt = $conn->prepare($sql);
    $transaction_ref = $payment['payment_reference'];
    $payment_method = $payment['payment_method'] ?? 'bank_transfer';

    $stmt->bind_param(
        "iiidss",
        $payment['student_id'],
        $payment['course_id'],
        $payment['class_id'],
        $payment['amount'],
        $payment_method,
        $transaction_ref
    );

    if ($stmt->execute()) {
        $payment_id = $stmt->insert_id;
        error_log("✓ Course payment recorded with ID: $payment_id");
        $stmt->close();

        // 2. Create or update student_financial_status
        $check_sql = "SELECT * FROM student_financial_status 
                     WHERE student_id = ? AND class_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $payment['student_id'], $payment['class_id']);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $existing = $result->fetch_assoc();
        $check_stmt->close();

        if ($existing) {
            // Update existing
            $new_paid = $existing['paid_amount'] + $payment['amount'];
            $new_balance = $existing['total_fee'] - $new_paid;
            $is_cleared = ($new_balance <= 0) ? 1 : 0;

            $update_sql = "UPDATE student_financial_status 
                          SET paid_amount = ?, 
                              balance = ?,
                              is_cleared = ?,
                              updated_at = NOW()
                          WHERE student_id = ? AND class_id = ?";

            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param(
                "ddiii",
                $new_paid,
                $new_balance,
                $is_cleared,
                $payment['student_id'],
                $payment['class_id']
            );
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            // Create new - need to get total fee first
            $fee_sql = "SELECT p.fee as total_fee 
                       FROM class_batches cb
                       JOIN courses c ON c.id = cb.course_id
                       JOIN programs p ON p.program_code = c.program_id
                       WHERE cb.id = ?";

            $fee_stmt = $conn->prepare($fee_sql);
            $fee_stmt->bind_param("i", $payment['class_id']);
            $fee_stmt->execute();
            $fee_result = $fee_stmt->get_result();
            $fee_data = $fee_result->fetch_assoc();
            $fee_stmt->close();

            $total_fee = $fee_data['total_fee'] ?? $payment['amount'] * 2; // Default estimate
            $balance = $total_fee - $payment['amount'];
            $is_cleared = ($balance <= 0) ? 1 : 0;

            $insert_sql = "INSERT INTO student_financial_status 
                          (student_id, class_id, total_fee, paid_amount, balance, 
                           is_cleared, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iidddi",
                $payment['student_id'],
                $payment['class_id'],
                $total_fee,
                $payment['amount'],
                $balance,
                $is_cleared
            );
            $insert_stmt->execute();
            $insert_stmt->close();
        }

        return $payment_id;
    } else {
        error_log("✗ ERROR inserting course payment: " . $conn->error);
        $stmt->close();
        return false;
    }
}

// Create financial transaction - FIXED VERSION
function createFinancialTransaction($payment, $conn)
{
    error_log("=== Starting createFinancialTransaction ===");

    $transaction_type = $payment['payment_type'] === 'registration' ? 'registration' : 'tuition';
    $description = $payment['payment_type'] === 'registration'
        ? "Registration fee payment for program"
        : "Course fee payment";

    // For registration payments, class_id might be NULL
    $class_id = null;
    if ($payment['payment_type'] === 'course' && isset($payment['class_id'])) {
        $class_id = $payment['class_id'];
    }

    // Create a unique reference
    $reference = 'FTX-' . date('YmdHis') . rand(1000, 9999);

    $sql = "INSERT INTO financial_transactions 
            (student_id, class_id, transaction_type, payment_method, amount, 
             gateway_reference, description, status, is_verified, verified_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 1, NOW(), NOW())";

    error_log("Inserting financial transaction...");
    $stmt = $conn->prepare($sql);

    $payment_method = $payment['payment_method'] ?? 'manual';
    $gateway_ref = $payment['payment_reference'] ?? $reference;

    $stmt->bind_param(
        "iisdsss",
        $payment['student_id'],
        $class_id,
        $transaction_type,
        $payment_method,
        $payment['amount'],
        $gateway_ref,
        $description
    );

    if ($stmt->execute()) {
        $transaction_id = $stmt->insert_id;
        error_log("✓ Financial transaction created with ID: $transaction_id");
        $stmt->close();
        return $transaction_id;
    } else {
        error_log("✗ ERROR creating financial transaction: " . $conn->error);
        $stmt->close();
        return false;
    }
}

// Update student financial status
function updateStudentFinancialStatus($payment, $transaction_id, $conn)
{
    error_log("=== Starting updateStudentFinancialStatus ===");

    // For registration payments, we don't need to update student_financial_status
    // because that table is for class-based fees, not registration fees

    if ($payment['payment_type'] === 'registration') {
        error_log("Registration payment - no need to update student_financial_status");
        return true;
    }

    // Only for course payments, update student_financial_status
    if ($payment['payment_type'] === 'course' && !empty($payment['class_id'])) {
        // Check if record exists
        $check_sql = "SELECT * FROM student_financial_status 
                     WHERE student_id = ? AND class_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $payment['student_id'], $payment['class_id']);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $existing = $result->fetch_assoc();
        $check_stmt->close();

        if ($existing) {
            // Update existing
            $new_paid = $existing['paid_amount'] + $payment['amount'];
            $new_balance = $existing['total_fee'] - $new_paid;

            $update_sql = "UPDATE student_financial_status 
                          SET paid_amount = ?, balance = ?, updated_at = NOW()
                          WHERE student_id = ? AND class_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param(
                "ddii",
                $new_paid,
                $new_balance,
                $payment['student_id'],
                $payment['class_id']
            );
            $update_stmt->execute();
            $update_stmt->close();
            error_log("✓ Updated student_financial_status");
        } else {
            // Create new - we need to get the total fee first
            $fee_sql = "SELECT p.fee FROM class_batches cb
                       JOIN courses c ON c.id = cb.course_id
                       JOIN programs p ON p.program_code = c.program_id
                       WHERE cb.id = ?";
            $fee_stmt = $conn->prepare($fee_sql);
            $fee_stmt->bind_param("i", $payment['class_id']);
            $fee_stmt->execute();
            $fee_result = $fee_stmt->get_result();
            $fee_data = $fee_result->fetch_assoc();
            $fee_stmt->close();

            $total_fee = $fee_data['fee'] ?? 0;
            $balance = $total_fee - $payment['amount'];

            $insert_sql = "INSERT INTO student_financial_status 
                          (student_id, class_id, total_fee, paid_amount, balance, created_at)
                          VALUES (?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iiddd",
                $payment['student_id'],
                $payment['class_id'],
                $total_fee,
                $payment['amount'],
                $balance
            );
            $insert_stmt->execute();
            $insert_stmt->close();
            error_log("✓ Created new student_financial_status record");
        }
    }

    // Log the activity
    logFinancialActivity(
        'payment_processed',
        "{$payment['payment_type']} payment processed: " . $payment['payment_reference'],
        $payment['student_id'],
        $payment['class_id'] ?? null,
        $transaction_id
    );

    return true;
}

// Generate payment receipt
function generatePaymentReceipt($payment, $transaction_id, $conn)
{
    // For now, return a placeholder URL
    // In production, you'd generate a PDF receipt
    $receipt_number = "RCPT-" . date('Ymd') . "-" . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);

    // Store receipt info in database
    $sql = "UPDATE financial_transactions 
            SET receipt_url = ?
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $receipt_url = BASE_URL . "modules/student/finance/payments/receipt.php?id=" . $transaction_id;
    $stmt->bind_param("si", $receipt_url, $transaction_id);
    $stmt->execute();
    $stmt->close();

    return $receipt_url;
}

// Send payment confirmation notification
function sendPaymentConfirmationNotification($payment, $receipt_url, $conn)
{
    // Send email
    $to = $payment['email'];
    $subject = "Payment Confirmed - Impact Digital Academy";
    $message = "Dear " . $payment['first_name'] . ",\n\n";
    $message .= "Your payment of ₦" . number_format($payment['amount'], 2) . " has been confirmed!\n";
    $message .= "Payment Reference: " . $payment['payment_reference'] . "\n";
    $message .= "Date: " . date('F j, Y') . "\n\n";

    if ($payment['payment_type'] === 'registration') {
        $message .= "Your registration is now complete. You can proceed to register for courses in available periods.\n";
    } else {
        $message .= "Your course fee has been recorded. You can now access all class materials.\n";
    }

    $message .= "\nYou can view your receipt at: " . $receipt_url . "\n\n";
    $message .= "Thank you for your payment!\n";
    $message .= "Impact Digital Academy\n";

    // Send email (implement your email function)
    // sendEmail($to, $subject, $message);

    // Create internal notification
    $sql = "INSERT INTO internal_messages 
            (sender_id, receiver_id, message_type, subject, message, created_at)
            VALUES (1, ?, 'payment_notification', 'Payment Confirmed', ?, NOW())";
    $stmt = $conn->prepare($sql);
    $notification_message = "Your payment of ₦" . number_format($payment['amount'], 2) . " has been verified.";
    $stmt->bind_param("is", $payment['student_id'], $notification_message);
    $stmt->execute();
    $stmt->close();
}

// Send payment rejection notification
function sendPaymentRejectionNotification($student_id, $payment_reference, $reason, $conn)
{
    // Get student details
    $sql = "SELECT email, first_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) return;

    // Send email
    $to = $student['email'];
    $subject = "Payment Rejected - Impact Digital Academy";
    $message = "Dear " . $student['first_name'] . ",\n\n";
    $message .= "Your payment with reference " . $payment_reference . " has been rejected.\n";
    $message .= "Reason: " . $reason . "\n\n";
    $message .= "Please contact the finance department for assistance or make a new payment.\n\n";
    $message .= "Thank you,\n";
    $message .= "Impact Digital Academy Finance Department\n";

    // Send email (implement your email function)
    // sendEmail($to, $subject, $message);

    // Create internal notification
    $sql = "INSERT INTO internal_messages 
            (sender_id, receiver_id, message_type, subject, message, created_at)
            VALUES (1, ?, 'payment_notification', 'Payment Rejected', ?, NOW())";
    $stmt = $conn->prepare($sql);
    $notification_message = "Your payment with reference " . $payment_reference . " was rejected. Reason: " . $reason;
    $stmt->bind_param("is", $student_id, $notification_message);
    $stmt->execute();
    $stmt->close();
}

// Find the existing processManualPayment function (around line 1237) and REPLACE it with this:

/**
 * Process a manual payment entry - ENHANCED VERSION
 * Enhanced to handle all payment types: registration, tuition blocks, course, and other
 */
function processManualPayment($manual_id, $conn = null)
{
    // Use provided connection or create new one
    if (!$conn) {
        $conn = getDBConnection();
    }

    // Get the manual payment details
    $sql = "SELECT * FROM manual_payment_entries WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $manual_id);
    $stmt->execute();
    $manual_payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$manual_payment) {
        return ['success' => false, 'error' => 'Manual payment not found'];
    }

    // Check if already processed
    if ($manual_payment['status'] === 'verified') {
        return ['success' => false, 'error' => 'Payment already processed'];
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        $payment_record_id = null;
        $transaction_id = null;

        // Process based on payment type
        switch ($manual_payment['payment_type']) {
            case 'registration':
                $payment_record_id = processRegistrationManualPayment($manual_payment, $conn);
                break;

            case 'course':
                $payment_record_id = processCourseManualPayment($manual_payment, $conn);
                break;

            default:
                throw new Exception('Invalid payment type: ' . $manual_payment['payment_type']);
        }

        if (!$payment_record_id) {
            throw new Exception('Failed to create payment record');
        }

        // Create financial transaction record
        $transaction_id = createManualFinancialTransaction($manual_payment, $conn);

        if (!$transaction_id) {
            throw new Exception('Failed to create financial transaction');
        }

        // Update student financial status for class-based payments
        if (!empty($manual_payment['class_id'])) {
            updateStudentStatusForManualPayment($manual_payment, $conn);
        }

        // Update manual payment entry
        $update_sql = "UPDATE manual_payment_entries 
                      SET status = 'verified', 
                          processed_at = NOW(),
                          transaction_id = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $transaction_id, $manual_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Send confirmation
        sendManualPaymentConfirmation($manual_payment, $transaction_id, $conn);

        // Log activity
        logFinancialActivity(
            'manual_payment_processed',
            "Manual payment processed: {$manual_payment['transaction_reference']} - " .
                formatCurrency($manual_payment['amount']),
            $manual_payment['student_id'],
            $manual_payment['class_id'] ?? null,
            $transaction_id
        );

        $conn->commit();

        return [
            'success' => true,
            'transaction_id' => $transaction_id,
            'payment_id' => $payment_record_id,
            'reference' => $manual_payment['transaction_reference']
        ];
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error processing manual payment: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


/**
 * Process manual registration payment
 */
function processRegistrationManualPayment($payment, $conn)
{
    // Create registration payment record
    $sql = "INSERT INTO registration_fee_payments (
                student_id, program_id, amount, payment_method,
                status, payment_date, transaction_reference, created_at
            ) VALUES (?, ?, ?, ?, 'completed', ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $transaction_ref = "MANUAL-" . $payment['transaction_reference'];

    $stmt->bind_param(
        "iidsss",
        $payment['student_id'],
        $payment['program_id'],
        $payment['amount'],
        $payment['payment_method'],
        $payment['payment_date'],
        $transaction_ref
    );

    $stmt->execute();
    $payment_id = $stmt->insert_id;
    $stmt->close();

    // Update application status
    $update_sql = "UPDATE applications 
                  SET status = 'approved', 
                      registration_fee_paid = 1,
                      registration_paid_date = NOW(),
                      reviewed_by = ?,
                      reviewed_at = NOW()
                  WHERE user_id = ? AND program_id = ? AND status = 'pending'";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "iii",
        $payment['verified_by'],
        $payment['student_id'],
        $payment['program_id']
    );
    $update_stmt->execute();
    $update_stmt->close();

    // Create enrollment record
    $enroll_sql = "INSERT INTO enrollments 
                  (student_id, program_id, status, enrollment_date, created_at)
                  VALUES (?, ?, 'pending', NOW(), NOW())
                  ON DUPLICATE KEY UPDATE 
                  status = VALUES(status), updated_at = NOW()";
    $enroll_stmt = $conn->prepare($enroll_sql);
    $enroll_stmt->bind_param(
        "ii",
        $payment['student_id'],
        $payment['program_id']
    );
    $enroll_stmt->execute();
    $enroll_stmt->close();

    return $payment_id;
}

/**
 * Process manual course payment
 */
function processCourseManualPayment($payment, $conn)
{
    $sql = "INSERT INTO course_payments (
                student_id, course_id, class_id, amount, payment_method,
                status, payment_date, transaction_reference, created_at
            ) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $transaction_ref = "MANUAL-" . $payment['transaction_reference'];

    $stmt->bind_param(
        "iiidsss",
        $payment['student_id'],
        $payment['course_id'],
        $payment['class_id'],
        $payment['amount'],
        $payment['payment_method'],
        $payment['payment_date'],
        $transaction_ref
    );

    $stmt->execute();
    $payment_id = $stmt->insert_id;
    $stmt->close();

    return $payment_id;
}

/**
 * Create financial transaction for manual payment
 */
function createManualFinancialTransaction($payment, $conn)
{
    // Map payment type to transaction type
    $transaction_type = match ($payment['payment_type']) {
        'registration' => 'registration',
        'tuition_block1', 'tuition_block2', 'tuition_block3' => 'tuition',
        'course' => 'course',
        default => 'other'
    };

    $description = "Manual payment: " . ($payment['description'] ??
        ucfirst(str_replace('_', ' ', $payment['payment_type'])) . " payment");

    // Use existing recordPaymentTransaction if available
    if (function_exists('recordPaymentTransaction')) {
        $result = recordPaymentTransaction(
            $payment['student_id'],
            $payment['class_id'] ?? null,
            $payment['amount'],
            $payment['payment_method'],
            $transaction_type,
            $description
        );

        if ($result['success']) {
            return $result['transaction_id'];
        }
    }

    // Fallback: create transaction directly
    $reference = 'MAN-' . date('YmdHis') . rand(1000, 9999);

    $sql = "INSERT INTO financial_transactions (
                student_id, class_id, transaction_type, payment_method,
                amount, gateway_reference, description, status,
                is_verified, verified_by, verified_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed',
                      1, ?, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iissdssi",
        $payment['student_id'],
        $payment['class_id'] ?? null,
        $transaction_type,
        $payment['payment_method'],
        $payment['amount'],
        $reference,
        $description,
        $payment['verified_by']
    );

    $stmt->execute();
    $transaction_id = $stmt->insert_id;
    $stmt->close();

    return $transaction_id;
}

/**
 * Update student financial status for manual payment
 */
function updateStudentStatusForManualPayment($payment, $conn)
{
    // Get or create financial status
    $check_sql = "SELECT * FROM student_financial_status 
                  WHERE student_id = ? AND class_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $payment['student_id'], $payment['class_id']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing = $check_result->fetch_assoc();
    $check_stmt->close();

    if ($existing) {
        // Update existing record
        $update_fields = [];
        $params = [];
        $param_types = "";

        // Set block paid status for tuition payments
        if (str_starts_with($payment['payment_type'], 'tuition_block')) {
            $block_num = (int)str_replace('tuition_block', '', $payment['payment_type']);
            $update_fields[] = "block{$block_num}_paid = 1";
            $update_fields[] = "block{$block_num}_paid_date = ?";
            $params[] = $payment['payment_date'];
            $param_types .= "s";
        } elseif ($payment['payment_type'] === 'registration') {
            $update_fields[] = "registration_paid = 1";
            $update_fields[] = "registration_paid_date = ?";
            $params[] = $payment['payment_date'];
            $param_types .= "s";
        }

        // Update amounts
        $new_paid = $existing['paid_amount'] + $payment['amount'];
        $new_balance = $existing['total_fee'] - $new_paid;

        $update_fields[] = "paid_amount = ?";
        $update_fields[] = "balance = ?";
        $update_fields[] = "is_cleared = ?";
        $params[] = $new_paid;
        $params[] = $new_balance;
        $params[] = ($new_balance <= 0) ? 1 : 0;
        $param_types .= "ddi";

        // Remove suspension if paid
        if ($new_balance <= 0) {
            $update_fields[] = "is_suspended = 0";
            $update_fields[] = "suspended_at = NULL";
            $update_fields[] = "suspension_reason = NULL";
        }

        $update_fields[] = "updated_at = NOW()";

        $params[] = $payment['student_id'];
        $params[] = $payment['class_id'];
        $param_types .= "ii";

        $sql = "UPDATE student_financial_status 
                SET " . implode(", ", $update_fields) . "
                WHERE student_id = ? AND class_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $stmt->close();
    } else {
        // Create new record
        $fee_sql = "SELECT p.fee as total_fee
                   FROM class_batches cb
                   JOIN courses c ON c.id = cb.course_id
                   JOIN programs p ON p.program_code = c.program_id
                   WHERE cb.id = ?";
        $fee_stmt = $conn->prepare($fee_sql);
        $fee_stmt->bind_param("i", $payment['class_id']);
        $fee_stmt->execute();
        $fee_result = $fee_stmt->get_result();
        $fee_data = $fee_result->fetch_assoc();
        $fee_stmt->close();

        $total_fee = $fee_data['total_fee'] ?? 0;
        $paid_amount = $payment['amount'];
        $balance = $total_fee - $paid_amount;

        // Set initial flags
        $registration_paid = ($payment['payment_type'] === 'registration') ? 1 : 0;
        $registration_date = ($payment['payment_type'] === 'registration') ? $payment['payment_date'] : null;
        $block_paid = 0;
        $block_date = null;

        if (str_starts_with($payment['payment_type'], 'tuition_block')) {
            $block_paid = 1;
            $block_date = $payment['payment_date'];
        }

        $sql = "INSERT INTO student_financial_status (
                    student_id, class_id, total_fee, paid_amount, balance,
                    registration_paid, registration_paid_date,
                    block1_paid, block1_paid_date,
                    current_block, is_cleared, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";

        $stmt = $conn->prepare($sql);
        $is_cleared = ($balance <= 0) ? 1 : 0;
        $stmt->bind_param(
            "iidddissis",
            $payment['student_id'],
            $payment['class_id'],
            $total_fee,
            $paid_amount,
            $balance,
            $registration_paid,
            $registration_date,
            $block_paid,
            $block_date,
            $is_cleared
        );

        $stmt->execute();
        $stmt->close();
    }

    return true;
}

/**
 * Send confirmation for manual payment
 */
function sendManualPaymentConfirmation($payment, $transaction_id, $conn)
{
    $student_id = $payment['student_id'];
    $amount = formatCurrency($payment['amount']);
    $payment_type = ucfirst(str_replace('_', ' ', $payment['payment_type']));
    $reference = $payment['transaction_reference'];

    // Create notification
    $notification_sql = "INSERT INTO notifications (
                user_id, title, message, type, created_at
            ) VALUES (?, 'Payment Confirmed', 
                     CONCAT('Your ', ?, ' payment of ', ?, ' (Ref: ', ?, ') has been confirmed.'), 
                     'payment_notification', NOW())";

    $notification_stmt = $conn->prepare($notification_sql);
    $notification_stmt->bind_param(
        "isss",
        $student_id,
        $payment_type,
        $amount,
        $reference
    );
    $notification_stmt->execute();
    $notification_stmt->close();

    // Send internal message if function exists
    if (function_exists('sendInternalMessage')) {
        $title = 'Payment Confirmation';
        $message = "Your {$payment_type} payment of {$amount} has been verified and processed.\n\n";
        $message .= "Transaction Reference: {$reference}\n";
        $message .= "Payment Date: " . date('F j, Y', strtotime($payment['payment_date'])) . "\n";
        $message .= "Transaction ID: {$transaction_id}";

        sendInternalMessage(
            $payment['verified_by'] ?? 1, // Default to admin if not specified
            $student_id,
            $message,
            $title,
            'payment_confirmation',
            ['transaction_id' => $transaction_id]
        );
    }
}

/**
 * Send rejection notification for manual payment
 */
function sendManualPaymentRejectionNotification($student_id, $reference, $reason, $conn)
{
    // Send notification to student
    $notification_sql = "INSERT INTO notifications (user_id, title, message, type, created_at)
                         VALUES (?, 'Payment Rejected', 
                                 CONCAT('Your payment (Ref: ', ?, ') was rejected. Reason: ', ?), 
                                 'system', NOW())";

    $notify_stmt = $conn->prepare($notification_sql);
    $notify_stmt->bind_param("iss", $student_id, $reference, $reason);
    $notify_stmt->execute();
    $notify_stmt->close();

    // Log the rejection
    $log_sql = "INSERT INTO financial_logs (action, description, student_id, ip_address, created_at)
                VALUES ('manual_payment_rejected', 
                        CONCAT('Manual payment rejected: ', ?, ' - Reason: ', ?),
                        ?, ?, NOW())";

    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("ssis", $reference, $reason, $student_id, $_SERVER['REMOTE_ADDR']);
    $log_stmt->execute();
    $log_stmt->close();
}
// Add this function to finance_functions.php

/**
 * Create payment verification from manual payment entry
 */
function createVerificationFromManualEntry($manual_payment_id, $conn = null)
{
    if (!$conn) {
        $conn = getDBConnection();
    }

    // Get manual payment details
    $sql = "SELECT * FROM manual_payment_entries WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $manual_payment_id);
    $stmt->execute();
    $manual_payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$manual_payment) {
        return false;
    }

    // Check if verification already exists
    if ($manual_payment['verification_id']) {
        return $manual_payment['verification_id'];
    }

    // Create payment verification
    $verification_sql = "INSERT INTO payment_verifications (
                            student_id, payment_type, program_id, course_id, class_id,
                            payment_reference, amount, payment_method, bank_name, account_name,
                            account_number, payment_date, description, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";

    $verification_stmt = $conn->prepare($verification_sql);

    // Prepare variables for binding
    $payment_reference = "MANUAL-" . $manual_payment['transaction_reference'];

    $verification_stmt->bind_param(
        "issiiisdsssss",
        $manual_payment['student_id'],
        $manual_payment['payment_type'],
        $manual_payment['program_id'],
        $manual_payment['course_id'],
        $manual_payment['class_id'],
        $payment_reference,
        $manual_payment['amount'],
        $manual_payment['payment_method'],
        $manual_payment['bank_name'],
        $manual_payment['account_name'],
        $manual_payment['account_number'],
        $manual_payment['payment_date'],
        $manual_payment['description']
    );

    if ($verification_stmt->execute()) {
        $verification_id = $verification_stmt->insert_id;

        // Update manual entry with verification ID
        $update_sql = "UPDATE manual_payment_entries 
                       SET verification_id = ? 
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $verification_id, $manual_payment_id);
        $update_stmt->execute();
        $update_stmt->close();

        $verification_stmt->close();
        return $verification_id;
    }

    $verification_stmt->close();
    return false;
}

// modules/admin/finance/finance_functions.php

/**
 * Get revenue for specific period from registration_fee_payments and course_payments
 */
function getRevenueForPeriod($period = 'month', $date_from = null, $date_to = null) {
    global $conn;
    
    // Build date conditions
    $date_conditions_reg = "WHERE rfp.status = 'completed'";
    $date_conditions_course = "WHERE cp.status = 'completed'";
    $params = [];
    $param_types = '';
    
    if ($period === 'today') {
        $date_conditions_reg .= " AND DATE(rfp.payment_date) = CURDATE()";
        $date_conditions_course .= " AND DATE(cp.payment_date) = CURDATE()";
    } elseif ($period === 'week') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === 'year') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        $date_conditions_reg .= " AND rfp.payment_date BETWEEN ? AND ?";
        $date_conditions_course .= " AND cp.payment_date BETWEEN ? AND ?";
        $param_types = 'ssss';
        $params = [$date_from, $date_to, $date_from, $date_to];
    }
    
    // Calculate total revenue from both sources
    $sql = "SELECT 
                COALESCE((
                    SELECT SUM(rfp.amount) 
                    FROM registration_fee_payments rfp 
                    {$date_conditions_reg}
                ), 0) as registration_revenue,
                COALESCE((
                    SELECT SUM(cp.amount) 
                    FROM course_payments cp 
                    {$date_conditions_course}
                ), 0) as course_revenue";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    $total_revenue = ($data['registration_revenue'] ?? 0) + ($data['course_revenue'] ?? 0);
    
    return $total_revenue;
}

/**
 * Get detailed revenue breakdown
 */
function getRevenueBreakdown($period = 'month', $date_from = null, $date_to = null) {
    global $conn;
    
    // Build date conditions
    $date_conditions_reg = "WHERE rfp.status = 'completed'";
    $date_conditions_course = "WHERE cp.status = 'completed'";
    $params = [];
    $param_types = '';
    
    if ($period === 'today') {
        $date_conditions_reg .= " AND DATE(rfp.payment_date) = CURDATE()";
        $date_conditions_course .= " AND DATE(cp.payment_date) = CURDATE()";
    } elseif ($period === 'week') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === 'year') {
        $date_conditions_reg .= " AND rfp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        $date_conditions_course .= " AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        $date_conditions_reg .= " AND rfp.payment_date BETWEEN ? AND ?";
        $date_conditions_course .= " AND cp.payment_date BETWEEN ? AND ?";
        $param_types = 'ssss';
        $params = [$date_from, $date_to, $date_from, $date_to];
    }
    
    // Get registration fee revenue with program details
    $registration_sql = "SELECT 
                            rfp.id,
                            rfp.amount,
                            rfp.payment_date,
                            rfp.payment_method,
                            rfp.transaction_reference,
                            p.name as program_name,
                            p.program_type,
                            u.first_name,
                            u.last_name,
                            u.email,
                            'registration' as revenue_type
                         FROM registration_fee_payments rfp
                         JOIN programs p ON p.id = rfp.program_id
                         JOIN users u ON u.id = rfp.student_id
                         {$date_conditions_reg}
                         ORDER BY rfp.payment_date DESC";
    
    $registration_stmt = $conn->prepare($registration_sql);
    
    if ($period === 'custom' && $date_from && $date_to) {
        $registration_stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $registration_stmt->execute();
    $registration_revenue = $registration_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get course payment revenue with details
    $course_sql = "SELECT 
                      cp.id,
                      cp.amount,
                      cp.payment_date,
                      cp.payment_method,
                      cp.transaction_reference,
                      p.name as program_name,
                      p.program_type,
                      c.title as course_title,
                      cb.batch_code,
                      u.first_name,
                      u.last_name,
                      u.email,
                      'course' as revenue_type
                   FROM course_payments cp
                   JOIN class_batches cb ON cb.id = cp.class_id
                   JOIN courses c ON c.id = cp.course_id
                   JOIN programs p ON p.program_code = c.program_id
                   JOIN users u ON u.id = cp.student_id
                   {$date_conditions_course}
                   ORDER BY cp.payment_date DESC";
    
    $course_stmt = $conn->prepare($course_sql);
    
    if ($period === 'custom' && $date_from && $date_to) {
        $course_stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $course_stmt->execute();
    $course_revenue = $course_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate totals
    $total_registration = array_sum(array_column($registration_revenue, 'amount'));
    $total_course = array_sum(array_column($course_revenue, 'amount'));
    
    return [
        'registration_revenue' => $registration_revenue,
        'course_revenue' => $course_revenue,
        'total_registration' => $total_registration,
        'total_course' => $total_course,
        'total_revenue' => $total_registration + $total_course
    ];
}

/**
 * Calculate and create automated deductions (tithe and reserve) based on actual revenue
 */
function calculateAutomatedDeductions($period = 'month', $date_from = null, $date_to = null) {
    global $conn;
    
    // Get actual revenue for the period
    $revenue = getRevenueForPeriod($period, $date_from, $date_to);
    
    // Get automated deduction settings
    $deductions_sql = "SELECT * FROM automated_deductions WHERE is_active = 1";
    $deductions_result = $conn->query($deductions_sql);
    $deductions = $deductions_result->fetch_all(MYSQLI_ASSOC);
    
    $created_expenses = [];
    
    foreach ($deductions as $deduction) {
        $amount = ($revenue * $deduction['percentage']) / 100;
        
        if ($amount > 0 && $deduction['auto_generate'] == 1) {
            // Generate a unique period identifier
            $period_identifier = date('Y-m');
            if ($period === 'custom' && $date_from) {
                $period_identifier = date('Y-m', strtotime($date_from));
            }
            
            // Check if already generated for this period
            $check_sql = "SELECT COUNT(*) as count FROM expenses 
                          WHERE description LIKE ? 
                          AND DATE_FORMAT(payment_date, '%Y-%m') = ?";
            $check_stmt = $conn->prepare($check_sql);
            $search_term = "%{$deduction['deduction_type']}%{$period}%";
            $check_stmt->bind_param("ss", $search_term, $period_identifier);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $count = $check_result->fetch_assoc()['count'];
            
            if ($count == 0) {
                // Generate expense record
                $expense_number = 'EXP-' . $period_identifier . '-' . 
                                  strtoupper(substr($deduction['deduction_type'], 0, 3)) . 
                                  str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Get category ID
                $category_sql = "SELECT id FROM expense_categories WHERE category_type = ?";
                $category_stmt = $conn->prepare($category_sql);
                $category_stmt->bind_param("s", $deduction['deduction_type']);
                $category_stmt->execute();
                $category_result = $category_stmt->get_result();
                $category = $category_result->fetch_assoc();
                
                if ($category) {
                    $period_label = $period === 'custom' ? 
                        date('M Y', strtotime($date_from)) : 
                        ucfirst($period) . ' ' . date('M Y');
                    
                    $description = ucfirst($deduction['deduction_type']) . " for " . $period_label . 
                                   " (Automated - Based on Revenue: " . formatCurrency($revenue) . ")";
                    
                    // Set payment date to end of period
                    $payment_date = date('Y-m-t'); // Last day of current month
                    if ($period === 'custom' && $date_to) {
                        $payment_date = $date_to;
                    }
                    
                    $insert_sql = "INSERT INTO expenses 
                                   (expense_number, category_id, description, amount, payment_method, 
                                   payment_date, vendor_name, notes, status, approved_by, paid_by, created_by) 
                                   VALUES (?, ?, ?, ?, 'bank_transfer', ?, ?, ?, 'approved', ?, ?, ?)";
                    
                    $insert_stmt = $conn->prepare($insert_sql);
                    
                    // Get admin user ID for created_by (you might want to use session user)
                    $admin_user_id = $_SESSION['user_id'] ?? 1;
                    $vendor_name = $deduction['deduction_type'] == 'tithe' ? 'Church Account' : 'Reserve Savings Account';
                    $notes = "Automated {$deduction['deduction_type']} deduction. 
                             Revenue: " . formatCurrency($revenue) . "
                             Percentage: {$deduction['percentage']}%
                             Calculated Amount: " . formatCurrency($amount);
                    
                    $insert_stmt->bind_param("sissdssiii", 
                        $expense_number,
                        $category['id'],
                        $description,
                        $amount,
                        $payment_date,
                        $vendor_name,
                        $notes,
                        $admin_user_id, // approved_by (admin)
                        $admin_user_id, // paid_by (admin)
                        $admin_user_id  // created_by (admin)
                    );
                    
                    if ($insert_stmt->execute()) {
                        $created_expenses[] = [
                            'type' => $deduction['deduction_type'],
                            'amount' => $amount,
                            'expense_number' => $expense_number,
                            'based_on_revenue' => $revenue
                        ];
                        
                        // Update deduction tracking
                        $update_sql = "UPDATE automated_deductions 
                                      SET last_calculated_date = CURDATE(), 
                                          total_deducted = total_deducted + ? 
                                      WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("di", $amount, $deduction['id']);
                        $update_stmt->execute();
                        
                        // Log this activity
                        logActivity($admin_user_id, 'auto_deduction', 
                            "Generated automated {$deduction['deduction_type']} expense of " . 
                            formatCurrency($amount) . " based on revenue of " . formatCurrency($revenue));
                    }
                }
            }
        }
    }
    
    return $created_expenses;
}

// Add this function to modules/includes/finance_functions.php

/**
 * Get expense dashboard statistics
 */
function getExpenseDashboardStats($period = 'month', $date_from = null, $date_to = null) {
    global $conn;
    
    // Set date conditions for expenses
    $date_conditions = "WHERE e.status IN ('approved', 'paid')";
    
    if ($period === 'today') {
        $date_conditions .= " AND DATE(e.payment_date) = CURDATE()";
    } elseif ($period === 'week') {
        $date_conditions .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $date_conditions .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === 'year') {
        $date_conditions .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        $date_conditions .= " AND e.payment_date BETWEEN ? AND ?";
    }
    
    $sql = "SELECT 
                COALESCE(SUM(e.amount), 0) as total_expenses,
                COUNT(e.id) as expense_count,
                COALESCE(AVG(e.amount), 0) as avg_expense,
                COALESCE((SELECT SUM(amount) FROM expenses WHERE status IN ('approved', 'paid') AND category_id IN 
                    (SELECT id FROM expense_categories WHERE category_type = 'tithe')), 0) as total_tithe,
                COALESCE((SELECT SUM(amount) FROM expenses WHERE status IN ('approved', 'paid') AND category_id IN 
                    (SELECT id FROM expense_categories WHERE category_type = 'reserve')), 0) as total_reserve,
                COALESCE((SELECT SUM(amount) FROM expenses WHERE status = 'pending'), 0) as pending_expenses
            FROM expenses e
            {$date_conditions}";
    
    $stmt = $conn->prepare($sql);
    
    if ($period === 'custom' && $date_from && $date_to) {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    // Get expense breakdown by category
    $breakdown_sql = "SELECT 
                        ec.name,
                        ec.category_type,
                        ec.color_code,
                        COALESCE(SUM(e.amount), 0) as total_amount,
                        COUNT(e.id) as expense_count
                     FROM expenses e
                     JOIN expense_categories ec ON ec.id = e.category_id
                     WHERE e.status IN ('approved', 'paid')";
    
    if ($period === 'today') {
        $breakdown_sql .= " AND DATE(e.payment_date) = CURDATE()";
    } elseif ($period === 'week') {
        $breakdown_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $breakdown_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === 'year') {
        $breakdown_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        $breakdown_sql .= " AND e.payment_date BETWEEN ? AND ?";
    }
    
    $breakdown_sql .= " GROUP BY ec.id ORDER BY total_amount DESC";
    
    $breakdown_stmt = $conn->prepare($breakdown_sql);
    
    if ($period === 'custom' && $date_from && $date_to) {
        $breakdown_stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $breakdown_stmt->execute();
    $breakdown_result = $breakdown_stmt->get_result();
    $stats['breakdown'] = $breakdown_result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate percentages
    $total_expenses = $stats['total_expenses'] ?? 0;
    foreach ($stats['breakdown'] as &$category) {
        $category['percentage'] = $total_expenses > 0 ? 
            round(($category['total_amount'] / $total_expenses) * 100, 1) : 0;
    }
    
    return $stats;
}
// Example PHP function for registration fee payment
function recordRegistrationPayment($studentId, $programId, $amount, $paymentMethod, $transactionRef, $status) {
    global $pdo;
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // 1. Insert into registration_fee_payments
        $stmt1 = $pdo->prepare("
            INSERT INTO registration_fee_payments 
            (student_id, program_id, amount, payment_method, transaction_reference, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, CURDATE())
        ");
        $stmt1->execute([$studentId, $programId, $amount, $paymentMethod, $transactionRef, $status]);
        
        // 2. Insert into financial_transactions
        $stmt2 = $pdo->prepare("
            INSERT INTO financial_transactions 
            (student_id, transaction_type, payment_method, amount, currency, 
             gateway_reference, description, status, is_verified)
            VALUES (?, 'registration', ?, ?, 'NGN', ?, ?, ?, ?)
        ");
        
        $description = "Registration fee payment for program #$programId";
        $isVerified = ($status == 'completed') ? 1 : 0;
        
        $stmt2->execute([
            $studentId, $paymentMethod, $amount, $transactionRef, 
            $description, $status, $isVerified
        ]);
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Example PHP function for course payment
function recordCoursePayment($studentId, $courseId, $classId, $amount, $paymentMethod, $transactionRef, $status) {
    global $pdo;
    
    $pdo->beginTransaction();
    
    try {
        // 1. Insert into course_payments
        $stmt1 = $pdo->prepare("
            INSERT INTO course_payments 
            (student_id, course_id, class_id, amount, payment_method, transaction_reference, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt1->execute([$studentId, $courseId, $classId, $amount, $paymentMethod, $transactionRef, $status]);
        
        // 2. Insert into financial_transactions
        $stmt2 = $pdo->prepare("
            INSERT INTO financial_transactions 
            (student_id, class_id, transaction_type, payment_method, amount, currency, 
             gateway_reference, description, status, is_verified)
            VALUES (?, ?, 'tuition', ?, ?, 'NGN', ?, ?, ?, ?)
        ");
        
        $description = "Course payment for course #$courseId";
        if ($classId) {
            $description .= " in class #$classId";
        }
        
        $isVerified = ($status == 'completed') ? 1 : 0;
        
        $stmt2->execute([
            $studentId, $classId, $paymentMethod, $amount, $transactionRef, 
            $description, $status, $isVerified
        ]);
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}