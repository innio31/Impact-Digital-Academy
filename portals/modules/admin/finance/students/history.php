<?php
// modules/admin/finance/students/history.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get parameters
$student_id = $_GET['student_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// If specific student/class is provided, get their details
$student_info = null;
$class_info = null;
if ($student_id && $class_id) {
    // Get student information
    $student_sql = "SELECT u.*, up.* 
                    FROM users u 
                    LEFT JOIN user_profiles up ON up.user_id = u.id
                    WHERE u.id = ?";
    $student_stmt = $conn->prepare($student_sql);
    $student_stmt->bind_param('i', $student_id);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    $student_info = $student_result->fetch_assoc();

    // Get class information
    $class_sql = "SELECT cb.*, c.title as course_title, p.name as program_name, p.program_type
                  FROM class_batches cb
                  JOIN courses c ON c.id = cb.course_id
                  JOIN programs p ON p.program_code = c.program_id
                  WHERE cb.id = ?";
    $class_stmt = $conn->prepare($class_sql);
    $class_stmt->bind_param('i', $class_id);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    $class_info = $class_result->fetch_assoc();
}

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($student_id) {
    $where_conditions[] = "ft.student_id = ?";
    $params[] = $student_id;
    $types .= 'i';
}

if ($class_id) {
    $where_conditions[] = "ft.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}

if ($search) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR ft.gateway_reference LIKE ? OR ft.description LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssssss';
}

if ($date_from) {
    $where_conditions[] = "DATE(ft.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(ft.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($payment_method) {
    $where_conditions[] = "ft.payment_method = ?";
    $params[] = $payment_method;
    $types .= 's';
}

if ($status) {
    $where_conditions[] = "ft.status = ?";
    $params[] = $status;
    $types .= 's';
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "SELECT COUNT(DISTINCT ft.id) as total
              FROM financial_transactions ft
              JOIN users u ON u.id = ft.student_id
              JOIN class_batches cb ON cb.id = ft.class_id
              JOIN courses c ON c.id = cb.course_id
              JOIN programs p ON p.program_code = c.program_id
              $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);

// Get payment history
$history_sql = "SELECT 
                    ft.*,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    cb.batch_code,
                    c.title as course_title,
                    p.name as program_name,
                    p.program_type,
                    p.program_code,
                    i.invoice_number,
                    i.invoice_type,
                    CASE 
                        WHEN ft.verified_at IS NOT NULL THEN 'Verified'
                        WHEN ft.status = 'completed' THEN 'Pending Verification'
                        ELSE ft.status
                    END as verification_status,
                    DATEDIFF(NOW(), ft.created_at) as days_ago
                 FROM financial_transactions ft
                 JOIN users u ON u.id = ft.student_id
                 JOIN class_batches cb ON cb.id = ft.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 LEFT JOIN invoices i ON i.student_id = ft.student_id 
                    AND i.class_id = ft.class_id 
                    AND i.status = 'paid'
                    AND ABS(i.paid_amount - ft.amount) < 0.01
                 $where_clause
                 ORDER BY ft.created_at DESC
                 LIMIT ? OFFSET ?";

$limit_param = $limit;
$offset_param = $offset;
$types_with_limit = $types . 'ii';
$params_with_limit = array_merge($params, [$limit_param, $offset_param]);

$history_stmt = $conn->prepare($history_sql);
if ($params_with_limit) {
    $history_stmt->bind_param($types_with_limit, ...$params_with_limit);
}
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$payments = $history_result->fetch_all(MYSQLI_ASSOC);

// Get summary statistics
$summary_sql = "SELECT 
                    COUNT(DISTINCT ft.id) as total_transactions,
                    SUM(ft.amount) as total_amount,
                    COUNT(DISTINCT ft.student_id) as total_students,
                    COUNT(DISTINCT ft.class_id) as total_classes,
                    AVG(ft.amount) as avg_payment_amount,
                    MIN(ft.amount) as min_payment_amount,
                    MAX(ft.amount) as max_payment_amount,
                    COUNT(CASE WHEN ft.status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN ft.status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN ft.status = 'failed' THEN 1 END) as failed_count,
                    COUNT(CASE WHEN ft.verified_at IS NOT NULL THEN 1 END) as verified_count
                 FROM financial_transactions ft
                 JOIN users u ON u.id = ft.student_id
                 JOIN class_batches cb ON cb.id = ft.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 $where_clause";

$summary_stmt = $conn->prepare($summary_sql);
if ($params) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();

// Get payment method distribution
$method_sql = "SELECT 
                    ft.payment_method,
                    COUNT(*) as transaction_count,
                    SUM(ft.amount) as total_amount,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM financial_transactions ft2 
                          JOIN users u2 ON u2.id = ft2.student_id
                          JOIN class_batches cb2 ON cb2.id = ft2.class_id
                          JOIN courses c2 ON c2.id = cb2.course_id
                          JOIN programs p2 ON p2.program_code = c2.program_id
                          $where_clause), 1) as percentage
                 FROM financial_transactions ft
                 JOIN users u ON u.id = ft.student_id
                 JOIN class_batches cb ON cb.id = ft.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 $where_clause
                 GROUP BY ft.payment_method
                 ORDER BY total_amount DESC";

$method_stmt = $conn->prepare($method_sql);
if ($params) {
    $method_stmt->bind_param($types, ...$params);
}
$method_stmt->execute();
$method_result = $method_stmt->get_result();
$payment_methods = $method_result->fetch_all(MYSQLI_ASSOC);

// Get monthly revenue trend
$monthly_sql = "SELECT 
                    DATE_FORMAT(ft.created_at, '%Y-%m') as month,
                    COUNT(*) as transaction_count,
                    SUM(ft.amount) as total_amount,
                    AVG(ft.amount) as avg_amount
                 FROM financial_transactions ft
                 JOIN users u ON u.id = ft.student_id
                 JOIN class_batches cb ON cb.id = ft.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 $where_clause
                 GROUP BY DATE_FORMAT(ft.created_at, '%Y-%m')
                 ORDER BY month DESC
                 LIMIT 12";

$monthly_stmt = $conn->prepare($monthly_sql);
if ($params) {
    $monthly_stmt->bind_param($types, ...$params);
}
$monthly_stmt->execute();
$monthly_result = $monthly_stmt->get_result();
$monthly_trend = $monthly_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_payment_history', "Viewed payment history with filters: " . json_encode($_GET));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        /* Student/Class Info */
        .info-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .info-section {
            padding: 1rem;
            border-radius: 8px;
            background: #f8fafc;
        }

        .info-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .info-label {
            color: #64748b;
            font-size: 0.85rem;
        }

        /* Summary Statistics */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .summary-card.primary {
            border-top: 4px solid var(--primary);
        }

        .summary-card.success {
            border-top: 4px solid var(--success);
        }

        .summary-card.warning {
            border-top: 4px solid var(--warning);
        }

        .summary-card.info {
            border-top: 4px solid var(--info);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .summary-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        .chart-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .method-list {
            list-style: none;
            padding: 0;
        }

        .method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .method-item:last-child {
            border-bottom: none;
        }

        .method-name {
            font-weight: 500;
        }

        .method-stats {
            text-align: right;
        }

        .method-percentage {
            display: block;
            font-size: 0.85rem;
            color: #64748b;
        }

        .method-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.25rem;
        }

        .method-fill {
            height: 100%;
            background: var(--primary);
        }

        /* Monthly Trend */
        .monthly-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .monthly-item:last-child {
            border-bottom: none;
        }

        .monthly-date {
            font-weight: 500;
            min-width: 100px;
        }

        .monthly-amount {
            font-weight: 600;
            color: var(--success);
        }

        .monthly-count {
            font-size: 0.85rem;
            color: #64748b;
        }

        /* Filters */
        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-refunded {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-verified {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-unverified {
            background: #fef3c7;
            color: #92400e;
        }

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-online {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-onsite {
            background: #dcfce7;
            color: #166534;
        }

        /* Time Indicator */
        .time-ago {
            font-size: 0.85rem;
            color: #64748b;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-history"></i>
                    Payment History
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> All Students
                    </a>
                </div>
            </div>

            <!-- Student/Class Info (if specific student/class) -->
            <?php if ($student_info && $class_info): ?>
                <div class="info-card">
                    <div class="info-grid">
                        <div class="info-section">
                            <div class="info-title">
                                <i class="fas fa-user"></i> Student Information
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']); ?></div>
                            <div class="info-label">Email: <?php echo htmlspecialchars($student_info['email']); ?></div>
                            <div class="info-label">Phone: <?php echo htmlspecialchars($student_info['phone'] ?? 'N/A'); ?></div>
                        </div>

                        <div class="info-section">
                            <div class="info-title">
                                <i class="fas fa-book"></i> Program Information
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($class_info['program_name']); ?></div>
                            <div class="info-label">
                                <span class="program-badge badge-<?php echo $class_info['program_type']; ?>">
                                    <?php echo $class_info['program_type']; ?>
                                </span>
                                | <?php echo htmlspecialchars($class_info['course_title']); ?>
                            </div>
                            <div class="info-label">Batch: <?php echo htmlspecialchars($class_info['batch_code']); ?></div>
                        </div>

                        <div class="info-section">
                            <div class="info-title">
                                <i class="fas fa-calendar"></i> Class Schedule
                            </div>
                            <div class="info-value">
                                <?php echo date('M j, Y', strtotime($class_info['start_date'])); ?> -
                                <?php echo date('M j, Y', strtotime($class_info['end_date'])); ?>
                            </div>
                            <div class="info-label">
                                Status:
                                <span class="status-badge status-<?php echo strtolower($class_info['status']); ?>">
                                    <?php echo ucfirst($class_info['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <div class="summary-grid">
                <div class="summary-card primary">
                    <div class="summary-value"><?php echo $summary['total_transactions'] ?? 0; ?></div>
                    <div class="summary-label">Total Transactions</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></div>
                    <div class="summary-label">Total Amount</div>
                </div>
                <div class="summary-card info">
                    <div class="summary-value"><?php echo $summary['completed_count'] ?? 0; ?></div>
                    <div class="summary-label">Completed</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-value"><?php echo $summary['verified_count'] ?? 0; ?></div>
                    <div class="summary-label">Verified</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['avg_payment_amount'] ?? 0); ?></div>
                    <div class="summary-label">Avg. Payment</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo $summary['total_students'] ?? 0; ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-container">
                <!-- Payment Methods -->
                <div class="chart-card">
                    <h3><i class="fas fa-credit-card"></i> Payment Methods</h3>
                    <?php if (!empty($payment_methods)): ?>
                        <ul class="method-list">
                            <?php
                            foreach ($payment_methods as $method):
                            ?>
                                <li class="method-item">
                                    <div>
                                        <div class="method-name"><?php echo ucfirst($method['payment_method']); ?></div>
                                        <div class="method-bar">
                                            <div class="method-fill" style="width: <?php echo $method['percentage']; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="method-stats">
                                        <div><?php echo formatCurrency($method['total_amount']); ?></div>
                                        <div class="method-percentage">
                                            <?php echo $method['transaction_count']; ?> transactions (<?php echo $method['percentage']; ?>%)
                                        </div>
                                    </div>
                                </li>
                            <?php
                            endforeach;
                            ?>
                        </ul>
                    <?php else: ?>
                        <div style="text-align: center; color: #64748b; padding: 2rem;">
                            <i class="fas fa-chart-pie fa-2x"></i>
                            <p>No payment method data available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Monthly Trend -->
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Monthly Revenue</h3>
                    <?php if (!empty($monthly_trend)): ?>
                        <?php
                        foreach ($monthly_trend as $month):
                        ?>
                            <div class="monthly-item">
                                <div class="monthly-date">
                                    <?php echo date('M Y', strtotime($month['month'] . '-01')); ?>
                                </div>
                                <div class="monthly-amount">
                                    <?php echo formatCurrency($month['total_amount']); ?>
                                </div>
                                <div class="monthly-count">
                                    <?php echo $month['transaction_count']; ?> payments
                                </div>
                            </div>
                        <?php
                        endforeach;
                        ?>
                    <?php else: ?>
                        <div style="text-align: center; color: #64748b; padding: 2rem;">
                            <i class="fas fa-chart-line fa-2x"></i>
                            <p>No monthly trend data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Payment History</h3>
                <form method="GET" class="filter-form">
                    <?php if (!$student_id): ?>
                        <div class="form-group">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control"
                                placeholder="Name, email, reference, or description..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="form-control"
                            value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="form-control"
                            value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="">All Methods</option>
                            <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="cheque" <?php echo $payment_method === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="pos" <?php echo $payment_method === 'pos' ? 'selected' : ''; ?>>POS</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <?php if (!$student_id): ?>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?export=csv" class="btn btn-success">
                                <i class="fas fa-file-export"></i> Export CSV
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Payment History Table -->
            <div class="table-container">
                <?php if (!empty($payments)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Payment Details</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Verification</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($payments as $payment):
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></div>
                                        <div style="color: #64748b; font-size: 0.85rem;">
                                            <?php echo date('g:i A', strtotime($payment['created_at'])); ?>
                                            <span class="time-ago">(<?php echo $payment['days_ago']; ?> days ago)</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($payment['email']); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($payment['program_name']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($payment['batch_code']); ?></small>
                                        <div>
                                            <span class="program-badge badge-<?php echo $payment['program_type']; ?>">
                                                <?php echo $payment['program_type']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="amount" style="font-weight: 600; color: var(--success);">
                                            <?php echo formatCurrency($payment['amount']); ?>
                                        </div>
                                        <?php if ($payment['invoice_number']): ?>
                                            <div style="font-size: 0.85rem; color: #64748b;">
                                                Invoice: <?php echo $payment['invoice_number']; ?>
                                                <small>(<?php echo str_replace('_', ' ', $payment['invoice_type']); ?>)</small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($payment['description']): ?>
                                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($payment['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($payment['gateway_reference']): ?>
                                            <div style="font-size: 0.85rem; color: #64748b;">
                                                Ref: <?php echo $payment['gateway_reference']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="text-transform: capitalize;"><?php echo $payment['payment_method']; ?></div>
                                        <small style="color: #64748b;">
                                            <?php if ($payment['payment_gateway']): ?>
                                                via <?php echo $payment['payment_gateway']; ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . $payment['status'];
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                        <?php if ($payment['status'] === 'completed' && $payment['receipt_url']): ?>
                                            <div style="margin-top: 0.25rem;">
                                                <a href="<?php echo BASE_URL . $payment['receipt_url']; ?>"
                                                    target="_blank" style="font-size: 0.85rem; color: var(--primary);">
                                                    <i class="fas fa-receipt"></i> Receipt
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $verification_class = $payment['verification_status'] === 'Verified' ? 'status-verified' : 'status-unverified';
                                        ?>
                                        <span class="status-badge <?php echo $verification_class; ?>">
                                            <?php echo $payment['verification_status']; ?>
                                        </span>
                                        <?php if ($payment['verified_by']): ?>
                                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                                by Admin
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/view.php?id=<?php echo $payment['id']; ?>"
                                                class="btn btn-sm btn-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($payment['status'] === 'completed' && !$payment['is_verified']): ?>
                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyPayment(<?php echo $payment['id']; ?>)"
                                                    title="Verify Payment">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($payment['status'] === 'completed' && $payment['is_verified']): ?>
                                                <button type="button" class="btn btn-sm btn-warning"
                                                    onclick="unverifyPayment(<?php echo $payment['id']; ?>)"
                                                    title="Unverify Payment">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($payment['status'] === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-info"
                                                    onclick="markAsCompleted(<?php echo $payment['id']; ?>)"
                                                    title="Mark as Completed">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($payment['status'] === 'completed'): ?>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/receipt.php?id=<?php echo $payment['id']; ?>"
                                                    class="btn btn-sm btn-secondary" title="Print Receipt" target="_blank">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php
                            endforeach;
                            ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                    class="page-link">
                                    <i class="fas fa-chevron-left"></i> Prev
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                                    class="page-link">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Payment History</h3>
                        <p>No payment records found matching your filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Payment verification functions
        function verifyPayment(paymentId) {
            if (confirm('Verify this payment? This will mark it as confirmed and update student financial status.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/payments/verify.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `payment_id=${paymentId}&action=verify&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Payment verified successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to verify payment'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        function unverifyPayment(paymentId) {
            if (confirm('Unverify this payment? This will mark it as pending verification.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/payments/verify.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `payment_id=${paymentId}&action=unverify&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Payment unverified successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to unverify payment'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        function markAsCompleted(paymentId) {
            if (confirm('Mark this payment as completed? This will update the payment status.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/payments/update_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `payment_id=${paymentId}&status=completed&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Payment marked as completed successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to update payment status'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        // Export functionality
        document.querySelector('a[href*="export=csv"]')?.addEventListener('click', function(e) {
            e.preventDefault();
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.open('<?php echo BASE_URL; ?>modules/admin/finance/reports/export_payments.php?' + params.toString(), '_blank');
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>