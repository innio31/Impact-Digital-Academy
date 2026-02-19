<?php
// modules/student/finance/fees/index.php

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

// Get student's programs and their fees
$program_fees = [];
$sql = "SELECT 
            p.id as program_id,
            p.program_code,
            p.name as program_name,
            p.description as program_description,
            p.program_type,
            p.registration_fee,
            p.base_fee as course_fee,
            p.fee_description,
            p.currency,
            e.id as enrollment_id,
            e.status as enrollment_status,
            sfs.total_fee,
            sfs.paid_amount,
            sfs.balance,
            sfs.registration_paid,
            sfs.registration_paid_date,
            sfs.is_cleared,
            sfs.is_suspended
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.student_id = ? 
        AND e.status IN ('active', 'completed')
        ORDER BY p.name, cb.start_date DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $program_fees = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Group fees by program
$grouped_fees = [];
foreach ($program_fees as $fee) {
    $program_id = $fee['program_id'];
    if (!isset($grouped_fees[$program_id])) {
        $grouped_fees[$program_id] = [
            'program_info' => [
                'id' => $fee['program_id'],
                'code' => $fee['program_code'],
                'name' => $fee['program_name'],
                'type' => $fee['program_type'],
                'description' => $fee['program_description'],
                'currency' => $fee['currency']
            ],
            'fees' => [
                'registration' => floatval($fee['registration_fee']),
                'course' => floatval($fee['course_fee'])
            ],
            'enrollments' => [],
            'total' => [
                'registration_fee' => 0,
                'course_fee' => 0,
                'total_fee' => 0,
                'paid_amount' => 0,
                'balance' => 0
            ],
            'payment_status' => [
                'registration_paid' => false,
                'registration_paid_date' => null,
                'is_cleared' => false,
                'is_suspended' => false
            ]
        ];
    }

    // Add enrollment
    $grouped_fees[$program_id]['enrollments'][] = [
        'id' => $fee['enrollment_id'],
        'status' => $fee['enrollment_status'],
        'total_fee' => floatval($fee['total_fee']),
        'paid_amount' => floatval($fee['paid_amount']),
        'balance' => floatval($fee['balance'])
    ];

    // Update totals
    $grouped_fees[$program_id]['total']['total_fee'] += floatval($fee['total_fee']);
    $grouped_fees[$program_id]['total']['paid_amount'] += floatval($fee['paid_amount']);
    $grouped_fees[$program_id]['total']['balance'] += floatval($fee['balance']);

    // Update payment status (use latest enrollment status)
    if (!$grouped_fees[$program_id]['payment_status']['registration_paid'] && $fee['registration_paid']) {
        $grouped_fees[$program_id]['payment_status']['registration_paid'] = true;
        $grouped_fees[$program_id]['payment_status']['registration_paid_date'] = $fee['registration_paid_date'];
    }
    if (!$grouped_fees[$program_id]['payment_status']['is_cleared'] && $fee['is_cleared']) {
        $grouped_fees[$program_id]['payment_status']['is_cleared'] = true;
    }
    if (!$grouped_fees[$program_id]['payment_status']['is_suspended'] && $fee['is_suspended']) {
        $grouped_fees[$program_id]['payment_status']['is_suspended'] = true;
    }
}

// Calculate registration and course fee totals
foreach ($grouped_fees as $program_id => $data) {
    $registration_fee = $data['fees']['registration'];
    $course_fee = $data['fees']['course'];
    $total_fee = $registration_fee + $course_fee;

    $grouped_fees[$program_id]['total']['registration_fee'] = $registration_fee;
    $grouped_fees[$program_id]['total']['course_fee'] = $course_fee;
}

// Get available programs for registration (not yet enrolled)
$available_programs = [];
$sql = "SELECT 
            p.id,
            p.program_code,
            p.name,
            p.description,
            p.program_type,
            p.registration_fee,
            p.base_fee as course_fee,
            p.fee_description,
            p.currency
        FROM programs p
        WHERE p.status = 'active'
        AND p.id NOT IN (
            SELECT DISTINCT c.program_id 
            FROM enrollments e
            JOIN class_batches cb ON e.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            WHERE e.student_id = ? 
            AND e.status IN ('active', 'completed')
        )
        ORDER BY p.name";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $available_programs = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Log access
logActivity($user_id, 'finance_fees_access', 'Student accessed fee structure page', $_SERVER['REMOTE_ADDR']);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Structure - Student Finance</title>
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
            --purple: #8b5cf6;
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            color: var(--gray);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .page-info h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .page-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 12px;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .tabs-header {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
        }

        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            color: var(--primary);
            background: var(--light);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .card-title {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: var(--primary);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .enrollment-badge {
            background: var(--success);
            color: white;
        }

        .completed-badge {
            background: var(--gray);
            color: white;
        }

        .payment-required-badge {
            background: var(--danger);
            color: white;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            gap: 0.5rem;
        }

        .status-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-overdue {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Fee Breakdown */
        .fee-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .fee-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .fee-label {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fee-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .fee-description {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.75rem;
            line-height: 1.5;
        }

        /* Payment Progress */
        .payment-progress {
            margin: 2rem 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .progress-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
        }

        .progress-stat {
            color: var(--gray);
        }

        .progress-stat strong {
            color: var(--dark);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
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

        /* Program Grid */
        .program-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .program-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .program-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .program-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .program-card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .program-card-code {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .program-card-description {
            color: var(--gray);
            font-size: 0.875rem;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        /* Program Badge */
        .program-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-online {
            background: rgba(67, 97, 238, 0.1);
            color: #4361ee;
        }

        .badge-onsite {
            background: rgba(114, 9, 183, 0.1);
            color: #7209b7;
        }

        /* Fee Summary */
        .fee-summary {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .fee-summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .fee-summary-label {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .fee-summary-value {
            font-weight: 600;
            color: var(--dark);
        }

        .total-fee {
            border-top: 1px solid #e2e8f0;
            padding-top: 0.75rem;
            margin-top: 0.75rem;
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: #e2e8f0;
            margin-bottom: 1rem;
        }

        .empty-state-title {
            font-size: 1.25rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .empty-state-description {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        /* Enrollment Status */
        .enrollment-status {
            margin-top: 1rem;
        }

        .enrollment-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 1.5rem;
            }

            .page-info h1 {
                font-size: 1.5rem;
            }

            .program-grid {
                grid-template-columns: 1fr;
            }

            .fee-breakdown {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .tabs-header {
                flex-wrap: nowrap;
                overflow-x: auto;
            }

            .tab {
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="<?php echo BASE_URL; ?>modules/student/finance/">
                <i class="fas fa-wallet"></i> Finance
            </a>
            <span class="separator">/</span>
            <span>Fee Structure</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="page-info">
                    <h1>Fee Structure</h1>
                    <p>View and manage your program fees</p>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs-header">
                <div class="tab active" onclick="switchTab('my-programs')">
                    <i class="fas fa-graduation-cap"></i> My Programs
                </div>
                <div class="tab" onclick="switchTab('available-programs')">
                    <i class="fas fa-search"></i> Available Programs
                </div>
                <div class="tab" onclick="switchTab('payment-history')">
                    <i class="fas fa-history"></i> Payment History
                </div>
            </div>

            <!-- My Programs Tab -->
            <div id="my-programs" class="tab-content active">
                <?php if (empty($grouped_fees)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h3 class="empty-state-title">No Programs Enrolled</h3>
                        <p class="empty-state-description">You haven't enrolled in any programs yet.</p>
                        <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-primary">
                            <i class="fas fa-search"></i> Browse Programs
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped_fees as $program_id => $program_data): ?>
                        <?php
                        $program = $program_data['program_info'];
                        $fees = $program_data['fees'];
                        $total = $program_data['total'];
                        $payment_status = $program_data['payment_status'];
                        $enrollments = $program_data['enrollments'];

                        $total_fee = $total['registration_fee'] + $total['course_fee'];
                        $progress_percentage = $total_fee > 0 ? ($total['paid_amount'] / $total_fee * 100) : 0;
                        ?>

                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title">
                                        <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($program['name']); ?>
                                    </h2>
                                    <div style="display: flex; align-items: center; gap: 1rem; margin-top: 0.5rem;">
                                        <span class="program-badge badge-<?php echo $program['type']; ?>">
                                            <?php echo ucfirst($program['type']); ?>
                                        </span>
                                        <span style="color: var(--gray); font-size: 0.875rem;">
                                            <?php echo htmlspecialchars($program['code']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($payment_status['is_cleared']): ?>
                                        <span class="status-indicator status-paid">
                                            <i class="fas fa-check-circle"></i> Cleared
                                        </span>
                                    <?php elseif ($total['balance'] > 0): ?>
                                        <span class="status-indicator status-overdue">
                                            <i class="fas fa-exclamation-circle"></i> Balance Due
                                        </span>
                                    <?php else: ?>
                                        <span class="status-indicator status-paid">
                                            <i class="fas fa-check-circle"></i> Paid in Full
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Fee Breakdown -->
                            <div class="fee-breakdown">
                                <div class="fee-item">
                                    <div class="fee-label">Registration Fee</div>
                                    <div class="fee-value"><?php echo $program['currency']; ?> <?php echo number_format($fees['registration'], 2); ?></div>
                                    <div class="fee-description">
                                        <?php if ($payment_status['registration_paid']): ?>
                                            <span style="color: var(--success);">
                                                <i class="fas fa-check-circle"></i> Paid on <?php echo date('M d, Y', strtotime($payment_status['registration_paid_date'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--warning);">
                                                <i class="fas fa-clock"></i> Payment Required
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="fee-item">
                                    <div class="fee-label">Course Fee</div>
                                    <div class="fee-value"><?php echo $program['currency']; ?> <?php echo number_format($fees['course'], 2); ?></div>
                                    <div class="fee-description">
                                        Complete fee for the entire program
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Progress -->
                            <div class="payment-progress">
                                <div class="progress-label">
                                    <span>Payment Progress</span>
                                    <span><?php echo number_format($progress_percentage, 1); ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
                                </div>
                                <div class="progress-stats">
                                    <div class="progress-stat">
                                        Paid: <strong><?php echo $program['currency']; ?> <?php echo number_format($total['paid_amount'], 2); ?></strong>
                                    </div>
                                    <div class="progress-stat">
                                        Balance: <strong><?php echo $program['currency']; ?> <?php echo number_format($total['balance'], 2); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <!-- Enrollment Status -->
                            <div class="enrollment-status">
                                <div style="font-size: 0.875rem; color: var(--gray); margin-bottom: 0.5rem;">Enrollments:</div>
                                <div class="enrollment-badges">
                                    <?php foreach ($enrollments as $enrollment): ?>
                                        <span class="status-badge <?php echo $enrollment['status']; ?>-badge">
                                            <?php echo ucfirst($enrollment['status']); ?> •
                                            <?php echo $program['currency']; ?> <?php echo number_format($enrollment['paid_amount'], 2); ?> paid
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <?php if ($total['balance'] > 0): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?program_id=<?php echo $program_id; ?>"
                                        class="btn btn-primary">
                                        <i class="fas fa-credit-card"></i> Make Payment
                                    </a>
                                <?php endif; ?>

                                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php?program_id=<?php echo $program_id; ?>"
                                    class="btn btn-secondary">
                                    <i class="fas fa-history"></i> Payment History
                                </a>

                                <?php if ($payment_status['is_suspended']): ?>
                                    <span class="btn btn-secondary" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); cursor: not-allowed;">
                                        <i class="fas fa-ban"></i> Account Suspended
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Available Programs Tab -->
            <div id="available-programs" class="tab-content">
                <?php if (empty($available_programs)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3 class="empty-state-title">All Programs Enrolled</h3>
                        <p class="empty-state-description">You're enrolled in all available programs.</p>
                    </div>
                <?php else: ?>
                    <div class="program-grid">
                        <?php foreach ($available_programs as $program): ?>
                            <div class="program-card">
                                <div class="program-card-header">
                                    <div>
                                        <h3 class="program-card-title"><?php echo htmlspecialchars($program['name']); ?></h3>
                                        <div class="program-card-code"><?php echo htmlspecialchars($program['program_code']); ?></div>
                                    </div>
                                    <span class="program-badge badge-<?php echo $program['program_type']; ?>">
                                        <?php echo ucfirst($program['program_type']); ?>
                                    </span>
                                </div>

                                <p class="program-card-description">
                                    <?php echo htmlspecialchars(substr($program['description'], 0, 150)); ?>
                                    <?php echo strlen($program['description']) > 150 ? '...' : ''; ?>
                                </p>

                                <div class="fee-summary">
                                    <div class="fee-summary-item">
                                        <span class="fee-summary-label">Registration Fee:</span>
                                        <span class="fee-summary-value"><?php echo $program['currency']; ?> <?php echo number_format($program['registration_fee'], 2); ?></span>
                                    </div>
                                    <div class="fee-summary-item">
                                        <span class="fee-summary-label">Course Fee:</span>
                                        <span class="fee-summary-value"><?php echo $program['currency']; ?> <?php echo number_format($program['course_fee'], 2); ?></span>
                                    </div>
                                    <div class="fee-summary-item total-fee">
                                        <span>Total Fee:</span>
                                        <span><?php echo $program['currency']; ?> <?php echo number_format($program['registration_fee'] + $program['course_fee'], 2); ?></span>
                                    </div>
                                </div>

                                <?php if (!empty($program['fee_description'])): ?>
                                    <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 6px; font-size: 0.875rem;">
                                        <strong>Fee Includes:</strong>
                                        <p style="margin-top: 0.5rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($program['fee_description'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 1.5rem;">
                                    <a href="<?php echo BASE_URL; ?>modules/student/classes/register.php?program_id=<?php echo $program['id']; ?>"
                                        class="btn btn-primary" style="width: 100%;">
                                        <i class="fas fa-plus-circle"></i> Register for Program
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment History Tab -->
            <div id="payment-history" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-history"></i> Payment History
                        </h2>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php"
                            class="btn btn-secondary">
                            <i class="fas fa-external-link-alt"></i> View Full History
                        </a>
                    </div>

                    <div class="empty-state">
                        <i class="fas fa-history empty-state-icon"></i>
                        <h3 class="empty-state-title">View Complete Payment History</h3>
                        <p class="empty-state-description">
                            View all your payments, invoices, and transaction history in detail
                        </p>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php"
                            class="btn btn-primary">
                            <i class="fas fa-history"></i> Go to Payment History
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabId).classList.add('active');

            // Add active class to clicked tab
            event.currentTarget.classList.add('active');

            // Save active tab to localStorage
            localStorage.setItem('activeFeeTab', tabId);
        }

        // Load active tab from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = localStorage.getItem('activeFeeTab') || 'my-programs';
            const tabElement = document.querySelector(`.tab[onclick*="${activeTab}"]`);
            if (tabElement) {
                tabElement.click();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+1 for My Programs
            if (e.ctrlKey && e.key === '1') {
                e.preventDefault();
                switchTab('my-programs');
            }

            // Ctrl+2 for Available Programs
            if (e.ctrlKey && e.key === '2') {
                e.preventDefault();
                switchTab('available-programs');
            }

            // Ctrl+3 for Payment History
            if (e.ctrlKey && e.key === '3') {
                e.preventDefault();
                switchTab('payment-history');
            }
        });
    </script>
</body>

</html>