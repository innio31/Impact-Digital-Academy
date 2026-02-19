<?php
// modules/student/finance/receipt.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];
$user_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($user_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

// Check if transaction ID is provided
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$download = isset($_GET['download']) ? true : false;
$print = isset($_GET['print']) ? true : false;

if ($transaction_id <= 0) {
    die("Invalid transaction ID.");
}

// Get transaction details
$transaction_sql = "SELECT ft.*, 
                    cb.batch_code, cb.name as class_name,
                    c.title as course_title, c.course_code,
                    p.name as program_name, p.program_code,
                    i.invoice_number,
                    u.first_name, u.last_name, u.email, u.phone,
                    up.address, up.city, up.state, up.country,
                    admin.first_name as verified_by_firstname,
                    admin.last_name as verified_by_lastname,
                    CASE 
                        WHEN ft.transaction_type = 'registration' THEN 'Registration Fee'
                        WHEN ft.transaction_type = 'tuition' THEN 'Tuition Payment'
                        WHEN ft.transaction_type = 'late_fee' THEN 'Late Fee'
                        WHEN ft.transaction_type = 'refund' THEN 'Refund'
                        ELSE 'Other Payment'
                    END as type_label,
                    CASE 
                        WHEN ft.payment_method = 'online' THEN 'Online Payment'
                        WHEN ft.payment_method = 'bank_transfer' THEN 'Bank Transfer'
                        WHEN ft.payment_method = 'cash' THEN 'Cash'
                        WHEN ft.payment_method = 'cheque' THEN 'Cheque'
                        WHEN ft.payment_method = 'pos' THEN 'POS'
                        ELSE 'Other'
                    END as payment_method_label
            FROM financial_transactions ft
            LEFT JOIN class_batches cb ON ft.class_id = cb.id
            LEFT JOIN courses c ON cb.course_id = c.id
            LEFT JOIN programs p ON c.program_id = p.id
            LEFT JOIN invoices i ON ft.invoice_id = i.id
            LEFT JOIN users u ON ft.student_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            LEFT JOIN users admin ON ft.verified_by = admin.id
            WHERE ft.id = ? AND ft.student_id = ?";

$stmt = $conn->prepare($transaction_sql);
$transaction = [];
if ($stmt) {
    $stmt->bind_param("ii", $transaction_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $transaction = $result->fetch_assoc();
    } else {
        die("Transaction not found or you don't have permission to view this receipt.");
    }
    $stmt->close();
}

// Get system settings for receipt information
$settings_sql = "SELECT setting_key, setting_value FROM system_settings 
                 WHERE setting_group IN ('company', 'receipt', 'payment')";
$settings_result = $conn->query($settings_sql);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get school/academy information
$company_name = $settings['company_name'] ?? 'Impact Digital Academy';
$company_address = $settings['company_address'] ?? '123 Education Street, Lagos, Nigeria';
$company_phone = $settings['company_phone'] ?? '+234 800 123 4567';
$company_email = $settings['company_email'] ?? 'info@impactdigitalacademy.com';
$company_website = $settings['company_website'] ?? 'https://www.impactdigitalacademy.com';
$receipt_prefix = $settings['receipt_prefix'] ?? 'RCT';
$receipt_footer = $settings['receipt_footer'] ?? 'Thank you for your payment. This is an official receipt.';

// Generate receipt number
$receipt_number = $receipt_prefix . '-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);

// Log access
logActivity($user_id, 'receipt_viewed', "Viewed receipt for transaction #$transaction_id", $_SERVER['REMOTE_ADDR']);

// Close connection
$conn->close();

// Set headers for download
if ($download) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Receipt-' . $receipt_number . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

// If printing, use simple HTML
if ($print) {
    header('Content-Type: text/html');
} else {
    // For normal view
    header('Content-Type: text/html');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo $receipt_number; ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .logo {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .academy-name {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .academy-tagline {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .receipt-title {
            text-align: center;
            padding: 2rem;
            border-bottom: 2px dashed #e9ecef;
        }

        .receipt-title h1 {
            font-size: 2rem;
            color: #212529;
            margin-bottom: 0.5rem;
        }

        .receipt-title .receipt-number {
            font-size: 1.2rem;
            color: #6c757d;
            font-weight: 600;
        }

        .content {
            padding: 2rem;
        }

        .info-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #4361ee;
        }

        .info-card h3 {
            color: #4361ee;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            min-width: 150px;
        }

        .info-value {
            color: #212529;
            text-align: right;
            word-break: break-word;
        }

        .payment-details {
            background: #e7f5ff;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 2rem 0;
            border: 2px solid #4cc9f0;
        }

        .payment-details h3 {
            color: #1098ad;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .amount-display {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            margin: 2rem 0;
            border: 2px dashed #adb5bd;
        }

        .amount-label {
            font-size: 1rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .amount-value {
            font-size: 3rem;
            font-weight: 700;
            color: #212529;
        }

        .amount-words {
            font-size: 1rem;
            color: #6c757d;
            margin-top: 1rem;
            font-style: italic;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background-color: rgba(76, 201, 240, 0.2);
            color: #0c8599;
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.2);
            color: #c2255c;
        }

        .status-refunded {
            background-color: rgba(72, 149, 239, 0.2);
            color: #1c7ed6;
        }

        .verification-info {
            background: #fff3bf;
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
            border-left: 4px solid #ffd43b;
        }

        .footer {
            padding: 2rem;
            background: #f8f9fa;
            border-top: 2px dashed #e9ecef;
            text-align: center;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .footer-section h4 {
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .footer-section p {
            color: #6c757d;
            font-size: 0.8rem;
        }

        .disclaimer {
            font-size: 0.75rem;
            color: #868e96;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #dee2e6;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #4361ee;
            color: white;
        }

        .btn-primary:hover {
            background: #3a56d4;
        }

        .btn-secondary {
            background: #f1f3f5;
            color: #495057;
        }

        .btn-secondary:hover {
            background: #e9ecef;
        }

        .btn-success {
            background: #4cc9f0;
            color: white;
        }

        .btn-success:hover {
            background: #3da8d5;
        }

        @media print {
            body {
                background: white;
            }

            .container {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }

            .action-buttons {
                display: none;
            }

            .header {
                padding: 1rem;
            }

            .logo {
                font-size: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
            }

            .info-section {
                grid-template-columns: 1fr;
            }

            .amount-value {
                font-size: 2.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* Watermark for original copy */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 6rem;
            color: rgba(0, 0, 0, 0.1);
            font-weight: bold;
            pointer-events: none;
            z-index: -1;
            opacity: 0.3;
        }

        .original-copy .watermark {
            content: "ORIGINAL COPY";
        }

        .duplicate-copy .watermark {
            content: "DUPLICATE COPY";
        }

        .certificate-seal {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(76, 201, 240, 0.1) 0%, rgba(67, 97, 238, 0.2) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #4361ee;
        }

        .certificate-seal i {
            font-size: 2rem;
            color: #4361ee;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Watermark -->
        <div class="watermark">ORIGINAL COPY</div>

        <!-- Certificate Seal -->
        <div class="certificate-seal">
            <i class="fas fa-stamp"></i>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="academy-name">Impact Digital Academy</div>
            <div class="academy-tagline">Empowering Digital Excellence</div>
        </div>

        <!-- Receipt Title -->
        <div class="receipt-title">
            <h1>OFFICIAL PAYMENT RECEIPT</h1>
            <div class="receipt-number">Receipt No: <?php echo $receipt_number; ?></div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Student Information -->
            <div class="info-section">
                <div class="info-card">
                    <h3>Student Information</h3>
                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-value">
                            <?php
                            $address = [];
                            if (!empty($transaction['address'])) $address[] = $transaction['address'];
                            if (!empty($transaction['city'])) $address[] = $transaction['city'];
                            if (!empty($transaction['state'])) $address[] = $transaction['state'];
                            if (!empty($transaction['country'])) $address[] = $transaction['country'];
                            echo htmlspecialchars(implode(', ', $address));
                            ?>
                        </span>
                    </div>
                </div>

                <div class="info-card">
                    <h3>Program Information</h3>
                    <div class="info-row">
                        <span class="info-label">Program:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['program_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Course:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['course_title'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Class Batch:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['batch_code'] ?? 'N/A'); ?></span>
                    </div>
                    <?php if (!empty($transaction['invoice_number'])): ?>
                        <div class="info-row">
                            <span class="info-label">Invoice No:</span>
                            <span class="info-value"><?php echo htmlspecialchars($transaction['invoice_number']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="payment-details">
                <h3>Payment Details</h3>
                <div class="info-row">
                    <span class="info-label">Transaction ID:</span>
                    <span class="info-value"><?php echo !empty($transaction['transaction_id']) ? htmlspecialchars($transaction['transaction_id']) : 'TRX-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Type:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transaction['type_label']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Method:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transaction['payment_method_label']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Transaction Date:</span>
                    <span class="info-value"><?php echo date('F j, Y, g:i A', strtotime($transaction['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Gateway:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transaction['payment_gateway'] ?? 'N/A'); ?></span>
                </div>
                <?php if (!empty($transaction['gateway_reference'])): ?>
                    <div class="info-row">
                        <span class="info-label">Gateway Reference:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['gateway_reference']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                            <?php echo ucfirst($transaction['status']); ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Amount Display -->
            <div class="amount-display">
                <div class="amount-label">Amount Paid</div>
                <div class="amount-value">
                    ₦<?php echo number_format($transaction['amount'], 2); ?>
                </div>
                <div class="amount-words">
                    <?php echo amountToWords($transaction['amount']); ?> Naira Only
                </div>
            </div>

            <!-- Description -->
            <?php if (!empty($transaction['description'])): ?>
                <div class="info-card">
                    <h3>Description / Notes</h3>
                    <p style="color: #495057; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($transaction['description'])); ?></p>
                </div>
            <?php endif; ?>

            <!-- Verification Information -->
            <?php if ($transaction['is_verified']): ?>
                <div class="verification-info">
                    <h3><i class="fas fa-check-circle"></i> Payment Verified</h3>
                    <div class="info-row">
                        <span class="info-label">Verified By:</span>
                        <span class="info-value"><?php echo htmlspecialchars($transaction['verified_by_firstname'] . ' ' . $transaction['verified_by_lastname']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Verified On:</span>
                        <span class="info-value"><?php echo date('F j, Y, g:i A', strtotime($transaction['verified_at'])); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p><?php echo htmlspecialchars($company_address); ?></p>
                    <p>Phone: <?php echo htmlspecialchars($company_phone); ?></p>
                    <p>Email: <?php echo htmlspecialchars($company_email); ?></p>
                </div>
                <div class="footer-section">
                    <h4>Website</h4>
                    <p><?php echo htmlspecialchars($company_website); ?></p>
                </div>
                <div class="footer-section">
                    <h4>Receipt Information</h4>
                    <p>Generated: <?php echo date('F j, Y, g:i A'); ?></p>
                    <p>Student ID: STU-<?php echo str_pad($user_id, 6, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>

            <div class="disclaimer">
                <p><strong>Disclaimer:</strong> This is an official receipt from Impact Digital Academy.
                    Please keep this receipt for your records. For any queries regarding this payment,
                    please contact our finance department with the receipt number.</p>
                <p><?php echo htmlspecialchars($receipt_footer); ?></p>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <button onclick="downloadReceipt()" class="btn btn-success">
                    <i class="fas fa-download"></i> Download PDF
                </button>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to History
                </a>
                <button onclick="shareReceipt()" class="btn" style="background: #25D366; color: white;">
                    <i class="fab fa-whatsapp"></i> Share via WhatsApp
                </button>
            </div>
        </div>
    </div>

    <script>
        // Function to download receipt as PDF
        function downloadReceipt() {
            const url = new URL(window.location.href);
            url.searchParams.set('download', 'true');
            window.location.href = url.toString();
        }

        // Function to share receipt via WhatsApp
        function shareReceipt() {
            const receiptNo = "<?php echo $receipt_number; ?>";
            const amount = "₦<?php echo number_format($transaction['amount'], 2); ?>";
            const date = "<?php echo date('F j, Y', strtotime($transaction['created_at'])); ?>";
            const type = "<?php echo $transaction['type_label']; ?>";

            const message = `Payment Receipt Details:\n\n` +
                `Receipt No: ${receiptNo}\n` +
                `Amount: ${amount}\n` +
                `Date: ${date}\n` +
                `Type: ${type}\n` +
                `Status: <?php echo ucfirst($transaction['status']); ?>\n\n` +
                `Thank you for your payment to Impact Digital Academy.`;

            const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(message)}`;
            window.open(whatsappUrl, '_blank');
        }

        // Function to validate receipt on page load
        function validateReceipt() {
            const receiptNumber = "<?php echo $receipt_number; ?>";
            const transactionId = "<?php echo $transaction_id; ?>";
            const studentId = "<?php echo $user_id; ?>";

            // Create a simple validation hash
            const validationHash = btoa(`${receiptNumber}:${transactionId}:${studentId}`);

            // Store validation info in localStorage for future reference
            localStorage.setItem('receipt_validation', validationHash);
            localStorage.setItem('last_receipt', receiptNumber);

            console.log('Receipt validated and stored locally');
        }

        // Auto-run validation on page load
        window.addEventListener('DOMContentLoaded', validateReceipt);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }

            // Ctrl+D to download
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                downloadReceipt();
            }

            // Escape to close (if in iframe)
            if (e.key === 'Escape') {
                if (window !== window.top) {
                    window.parent.postMessage('closeReceipt', '*');
                }
            }
        });

        // Auto-print if print parameter is set
        <?php if ($print): ?>
            window.addEventListener('load', function() {
                window.print();
                setTimeout(function() {
                    window.close();
                }, 1000);
            });
        <?php endif; ?>
    </script>
</body>

</html>

<?php
/**
 * Convert amount to words (Nigerian Naira)
 * This is a basic implementation - you might want to enhance it
 */
function amountToWords($number)
{
    $ones = array(
        1 => "one",
        2 => "two",
        3 => "three",
        4 => "four",
        5 => "five",
        6 => "six",
        7 => "seven",
        8 => "eight",
        9 => "nine",
        10 => "ten",
        11 => "eleven",
        12 => "twelve",
        13 => "thirteen",
        14 => "fourteen",
        15 => "fifteen",
        16 => "sixteen",
        17 => "seventeen",
        18 => "eighteen",
        19 => "nineteen"
    );

    $tens = array(
        2 => "twenty",
        3 => "thirty",
        4 => "forty",
        5 => "fifty",
        6 => "sixty",
        7 => "seventy",
        8 => "eighty",
        9 => "ninety"
    );

    $hundreds = array(
        "hundred",
        "thousand",
        "million",
        "billion",
        "trillion"
    );

    // Separate whole number and decimal
    $parts = explode(".", number_format($number, 2, '.', ''));
    $whole = $parts[0];
    $decimal = isset($parts[1]) ? $parts[1] : "00";

    // Convert whole number to words
    $words = "";

    if ($whole >= 1000) {
        $thousands = floor($whole / 1000);
        $words .= amountToWords($thousands) . " thousand ";
        $whole %= 1000;
    }

    if ($whole >= 100) {
        $hundred = floor($whole / 100);
        $words .= $ones[$hundred] . " hundred ";
        $whole %= 100;
    }

    if ($whole >= 20) {
        $ten = floor($whole / 10);
        $words .= $tens[$ten] . " ";
        $whole %= 10;
    }

    if ($whole > 0) {
        $words .= $ones[$whole] . " ";
    }

    // Handle decimal (kobo)
    if ($decimal > 0) {
        $words .= "and " . amountToWords(intval($decimal)) . " kobo";
    }

    return ucfirst(trim($words));
}
?>