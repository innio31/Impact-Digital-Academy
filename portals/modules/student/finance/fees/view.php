<?php
// modules/student/finance/fees/view.php

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

// Get program ID from URL
$program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
if ($program_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];

// Get program details
$program = [];
$sql = "SELECT 
            p.id,
            p.program_code,
            p.name,
            p.description,
            p.program_type,
            p.registration_fee,
            p.base_fee as course_fee,
            p.fee_description,
            p.currency,
            p.duration_weeks,
            p.installment_count,
            p.payment_plan_type,
            p.late_fee_percentage
        FROM programs p
        WHERE p.id = ? 
        AND p.status = 'active'";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $program = $result->fetch_assoc();
    } else {
        header('Location: index.php');
        exit();
    }
    $stmt->close();
}

// Get student's enrollments in this program
$enrollments = [];
$sql = "SELECT 
            e.id as enrollment_id,
            e.enrollment_date,
            e.status as enrollment_status,
            cb.batch_code,
            cb.start_date,
            cb.end_date,
            c.course_code,
            c.title as course_title,
            sfs.total_fee,
            sfs.paid_amount,
            sfs.balance,
            sfs.registration_paid,
            sfs.registration_paid_date,
            sfs.is_cleared,
            sfs.is_suspended,
            sfs.next_payment_due,
            sfs.payment_deadline
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.student_id = ? 
        AND c.program_id = ?
        AND e.status IN ('active', 'completed')
        ORDER BY cb.start_date DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $enrollments = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get payment history for this program
$payments = [];
$sql = "SELECT 
            ft.*,
            cb.batch_code,
            c.course_code,
            c.title as course_title,
            i.invoice_number
        FROM financial_transactions ft
        LEFT JOIN class_batches cb ON ft.class_id = cb.id
        LEFT JOIN courses c ON cb.course_id = c.id
        LEFT JOIN invoices i ON ft.invoice_id = i.id
        WHERE ft.student_id = ?
        AND c.program_id = ?
        AND ft.status = 'completed'
        ORDER BY ft.created_at DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $user_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $payments = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Calculate totals
$total_fee = floatval($program['registration_fee']) + floatval($program['course_fee']);
$total_paid = 0;
$total_balance = 0;
$registration_paid = false;
$is_cleared = false;
$is_suspended = false;

foreach ($enrollments as $enrollment) {
    $total_paid += floatval($enrollment['paid_amount']);
    $total_balance += floatval($enrollment['balance']);

    if ($enrollment['registration_paid']) {
        $registration_paid = true;
    }
    if ($enrollment['is_cleared']) {
        $is_cleared = true;
    }
    if ($enrollment['is_suspended']) {
        $is_suspended = true;
    }
}

$progress_percentage = $total_fee > 0 ? ($total_paid / $total_fee * 100) : 0;

// Get late fee settings
$late_fee_percentage = floatval($program['late_fee_percentage']);
$late_fee_amount = $total_balance > 0 ? ($total_balance * $late_fee_percentage / 100) : 0;

// Log access
logActivity($user_id, 'program_fee_details', 'Viewed fee details for program: ' . $program['name'], $_SERVER['REMOTE_ADDR']);

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($program['name']); ?> - Fee Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        .fee-details-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #4361ee;
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .program-header {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .program-title {
            font-size: 28px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .program-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            color: #7f8c8d;
        }

        .program-type {
            background-color: rgba(67, 97, 238, 0.1);
            color: #4361ee;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .program-code {
            font-weight: 500;
        }

        .program-description {
            color: #5a6c7d;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .fee-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .summary-title {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }

        .summary-subtitle {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-paid {
            background-color: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: #f72585;
        }

        .fee-breakdown {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e6ed;
        }

        .fee-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .fee-item-icon {
            width: 40px;
            height: 40px;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4361ee;
        }

        .fee-item-details {
            flex: 1;
        }

        .fee-item-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .fee-item-description {
            font-size: 14px;
            color: #7f8c8d;
        }

        .fee-item-amount {
            font-weight: 700;
            color: #2c3e50;
            font-size: 18px;
        }

        .enrollment-list {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .enrollment-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #e0e6ed;
            align-items: center;
        }

        .enrollment-item:last-child {
            border-bottom: none;
        }

        .enrollment-info h4 {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .enrollment-info p {
            font-size: 14px;
            color: #7f8c8d;
        }

        .payment-progress {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .progress-bar {
            height: 10px;
            background-color: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin: 20px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .progress-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .progress-stat {
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background-color: #4361ee;
            color: white;
        }

        .btn-primary:hover {
            background-color: #3a56d4;
        }

        .btn-success {
            background-color: #4cc9f0;
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn-danger {
            background-color: #e63946;
            color: white;
        }

        .btn-danger:hover {
            background-color: #d62839;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .payment-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e0e6ed;
        }

        .payment-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e6ed;
        }

        .payment-table tr:hover {
            background: #f8f9fa;
        }

        @media (max-width: 768px) {
            .fee-details-container {
                padding: 15px;
            }

            .fee-summary-grid {
                grid-template-columns: 1fr;
            }

            .enrollment-item {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .progress-stats {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Include Student Dashboard Header -->
    <?php include __DIR__ . '/../dashboard_header.php'; ?>

    <div class="fee-details-container">
        <!-- Back Link -->
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Fee Structure
        </a>

        <!-- Program Header -->
        <div class="program-header">
            <h1 class="program-title"><?php echo htmlspecialchars($program['name']); ?></h1>
            <div class="program-meta">
                <span class="program-type"><?php echo ucfirst($program['program_type']); ?> Program</span>
                <span class="program-code"><?php echo htmlspecialchars($program['program_code']); ?></span>
                <span>Duration: <?php echo $program['duration_weeks']; ?> weeks</span>
            </div>
            <p class="program-description">
                <?php echo nl2br(htmlspecialchars($program['description'])); ?>
            </p>

            <div class="action-buttons">
                <?php if ($total_balance > 0): ?>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?program_id=<?php echo $program_id; ?>"
                        class="btn btn-primary">
                        <i class="fas fa-credit-card"></i> Make Payment
                    </a>
                <?php endif; ?>

                <?php if ($is_cleared): ?>
                    <span class="btn btn-success" style="cursor: default;">
                        <i class="fas fa-check-circle"></i> Cleared
                    </span>
                <?php elseif ($is_suspended): ?>
                    <span class="btn btn-danger" style="cursor: default;">
                        <i class="fas fa-ban"></i> Suspended
                    </span>
                <?php endif; ?>

                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php?program_id=<?php echo $program_id; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-history"></i> Payment History
                </a>

                <button onclick="printFeeDetails()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print Details
                </button>
            </div>
        </div>

        <!-- Fee Summary Grid -->
        <div class="fee-summary-grid">
            <div class="summary-card">
                <div class="summary-title">Total Fee</div>
                <div class="summary-value"><?php echo $program['currency']; ?> <?php echo number_format($total_fee, 2); ?></div>
                <div class="summary-subtitle">Registration + Course Fee</div>
            </div>

            <div class="summary-card">
                <div class="summary-title">Paid Amount</div>
                <div class="summary-value"><?php echo $program['currency']; ?> <?php echo number_format($total_paid, 2); ?></div>
                <div class="summary-subtitle">
                    <?php if ($registration_paid): ?>
                        <span style="color: #4cc9f0;">
                            <i class="fas fa-check-circle"></i> Registration Paid
                        </span>
                    <?php else: ?>
                        <span style="color: #f72585;">
                            <i class="fas fa-clock"></i> Registration Pending
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-title">Balance Due</div>
                <div class="summary-value"><?php echo $program['currency']; ?> <?php echo number_format($total_balance, 2); ?></div>
                <div class="summary-subtitle">
                    <?php if ($total_balance > 0 && $late_fee_amount > 0): ?>
                        <span style="color: #e63946;">
                            <i class="fas fa-exclamation-triangle"></i> Late Fee: <?php echo number_format($late_fee_amount, 2); ?>
                        </span>
                    <?php elseif ($total_balance > 0): ?>
                        <span style="color: #f72585;">
                            <i class="fas fa-clock"></i> Payment Due
                        </span>
                    <?php else: ?>
                        <span style="color: #4cc9f0;">
                            <i class="fas fa-check-circle"></i> No Balance
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fee Breakdown -->
        <div class="fee-breakdown">
            <h2 class="section-title">Fee Breakdown</h2>

            <div class="fee-item">
                <div class="fee-item-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="fee-item-details">
                    <div class="fee-item-name">Registration Fee</div>
                    <div class="fee-item-description">
                        One-time registration fee for the program
                        <?php if ($registration_paid): ?>
                            <span style="color: #4cc9f0; margin-left: 10px;">
                                <i class="fas fa-check-circle"></i> Paid
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="fee-item-amount">
                    <?php echo $program['currency']; ?> <?php echo number_format($program['registration_fee'], 2); ?>
                </div>
            </div>

            <div class="fee-item">
                <div class="fee-item-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="fee-item-details">
                    <div class="fee-item-name">Course Fee</div>
                    <div class="fee-item-description">
                        Complete course fee including all materials and assessments
                    </div>
                </div>
                <div class="fee-item-amount">
                    <?php echo $program['currency']; ?> <?php echo number_format($program['course_fee'], 2); ?>
                </div>
            </div>

            <?php if ($total_balance > 0 && $late_fee_amount > 0): ?>
                <div class="fee-item">
                    <div class="fee-item-icon" style="background: rgba(230, 57, 70, 0.1); color: #e63946;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="fee-item-details">
                        <div class="fee-item-name">Late Fee Penalty</div>
                        <div class="fee-item-description" style="color: #e63946;">
                            <?php echo $late_fee_percentage; ?>% penalty applied to overdue balance
                        </div>
                    </div>
                    <div class="fee-item-amount" style="color: #e63946;">
                        <?php echo $program['currency']; ?> <?php echo number_format($late_fee_amount, 2); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Progress -->
        <div class="payment-progress">
            <h2 class="section-title">Payment Progress</h2>

            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo min($progress_percentage, 100); ?>%"></div>
            </div>

            <div class="progress-stats">
                <div class="progress-stat">
                    <div class="stat-value"><?php echo number_format($progress_percentage, 1); ?>%</div>
                    <div class="stat-label">Payment Progress</div>
                </div>

                <div class="progress-stat">
                    <div class="stat-value"><?php echo $program['currency']; ?> <?php echo number_format($total_paid, 2); ?></div>
                    <div class="stat-label">Amount Paid</div>
                </div>

                <div class="progress-stat">
                    <div class="stat-value"><?php echo $program['currency']; ?> <?php echo number_format($total_balance, 2); ?></div>
                    <div class="stat-label">Balance Due</div>
                </div>
            </div>
        </div>

        <?php if (!empty($enrollments)): ?>
            <!-- Enrollments -->
            <div class="enrollment-list">
                <h2 class="section-title">Your Enrollments</h2>

                <?php foreach ($enrollments as $enrollment): ?>
                    <div class="enrollment-item">
                        <div class="enrollment-info">
                            <h4><?php echo htmlspecialchars($enrollment['course_title']); ?></h4>
                            <p>
                                <?php echo htmlspecialchars($enrollment['batch_code']); ?> •
                                <?php echo date('M d, Y', strtotime($enrollment['start_date'])); ?> to <?php echo date('M d, Y', strtotime($enrollment['end_date'])); ?>
                            </p>
                        </div>

                        <div>
                            <div style="font-size: 14px; color: #7f8c8d;">Status</div>
                            <span class="status-indicator status-<?php echo $enrollment['enrollment_status']; ?>">
                                <?php echo ucfirst($enrollment['enrollment_status']); ?>
                            </span>
                        </div>

                        <div>
                            <div style="font-size: 14px; color: #7f8c8d;">Balance</div>
                            <div style="font-weight: 600; color: <?php echo $enrollment['balance'] > 0 ? '#e63946' : '#4cc9f0'; ?>;">
                                <?php echo $program['currency']; ?> <?php echo number_format($enrollment['balance'], 2); ?>
                            </div>
                        </div>

                        <div>
                            <?php if ($enrollment['balance'] > 0): ?>
                                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?enrollment_id=<?php echo $enrollment['enrollment_id']; ?>"
                                    class="btn btn-primary btn-sm">
                                    Pay Now
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($payments)): ?>
            <!-- Recent Payments -->
            <div class="fee-breakdown">
                <h2 class="section-title">Recent Payments</h2>

                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['created_at'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($payment['description']); ?>
                                    <?php if (!empty($payment['course_title'])): ?>
                                        <br><small><?php echo htmlspecialchars($payment['course_title']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $program['currency']; ?> <?php echo number_format($payment['amount'], 2); ?></td>
                                <td>
                                    <span style="color: #4cc9f0;">
                                        <i class="fas fa-check-circle"></i> Completed
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php?program_id=<?php echo $program_id; ?>"
                        class="btn btn-secondary">
                        <i class="fas fa-history"></i> View All Payments
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Print fee details
        function printFeeDetails() {
            window.print();
        }

        // Calculate late fee
        function calculateLateFee(balance, percentage) {
            return balance * (percentage / 100);
        }

        // Format currency
        function formatCurrency(amount, currency) {
            return currency + ' ' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+P to print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printFeeDetails();
                }

                // Escape to go back
                if (e.key === 'Escape') {
                    window.history.back();
                }
            });

            // Calculate and display total with late fee if applicable
            const balance = <?php echo $total_balance; ?>;
            const lateFeePercentage = <?php echo $late_fee_percentage; ?>;

            if (balance > 0 && lateFeePercentage > 0) {
                const lateFee = calculateLateFee(balance, lateFeePercentage);
                const totalWithLateFee = balance + lateFee;

                // You can display this in a tooltip or additional section
                console.log('Total with late fee:', formatCurrency(totalWithLateFee, '<?php echo $program['currency']; ?>'));
            }
        });
    </script>
</body>

</html>