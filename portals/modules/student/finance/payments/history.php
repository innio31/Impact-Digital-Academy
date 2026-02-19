<?php
// modules/student/finance/payments/index.php

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

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query with filters
$where_conditions = ["ft.student_id = ?"];
$params = [$user_id];
$param_types = "i";

// Filter by status
if ($status !== 'all') {
    $where_conditions[] = "ft.status = ?";
    $params[] = $status;
    $param_types .= "s";
}

// Filter by transaction type
if ($type !== 'all') {
    $where_conditions[] = "ft.transaction_type = ?";
    $params[] = $type;
    $param_types .= "s";
}

// Filter by date range
if (!empty($start_date)) {
    $where_conditions[] = "DATE(ft.created_at) >= ?";
    $params[] = $start_date;
    $param_types .= "s";
}

if (!empty($end_date)) {
    $where_conditions[] = "DATE(ft.created_at) <= ?";
    $params[] = $end_date;
    $param_types .= "s";
}

// Filter by search
if (!empty($search)) {
    $where_conditions[] = "(ft.transaction_id LIKE ? OR ft.gateway_reference LIKE ? OR ft.description LIKE ? OR c.title LIKE ? OR p.name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sssss";
}

// Special filters
switch ($filter) {
    case 'last_30_days':
        $where_conditions[] = "ft.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        break;
    case 'last_90_days':
        $where_conditions[] = "ft.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        break;
    case 'this_year':
        $where_conditions[] = "YEAR(ft.created_at) = YEAR(CURDATE())";
        break;
    case 'last_year':
        $where_conditions[] = "YEAR(ft.created_at) = YEAR(CURDATE()) - 1";
        break;
    case 'successful':
        $where_conditions[] = "ft.status = 'completed'";
        break;
    case 'failed':
        $where_conditions[] = "ft.status = 'failed'";
        break;
    case 'pending':
        $where_conditions[] = "ft.status = 'pending'";
        break;
    case 'refunded':
        $where_conditions[] = "ft.status = 'refunded'";
        break;
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM financial_transactions ft
              LEFT JOIN class_batches cb ON ft.class_id = cb.id
              LEFT JOIN courses c ON cb.course_id = c.id
              LEFT JOIN programs p ON c.program_id = p.id
              $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();
} else {
    $total_count = 0;
}

// Pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_count / $per_page);

// Get transactions
$sql = "SELECT ft.*, 
               cb.batch_code, 
               c.title as course_title, c.course_code,
               p.name as program_name, p.program_code,
               i.invoice_number,
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
        $where_clause
        ORDER BY ft.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
$transactions = [];
if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
}

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completed_amount,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending_amount,
                SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as total_refunded_amount
                FROM financial_transactions
                WHERE student_id = ?";

if (!empty($where_conditions_no_student)) {
    $where_conditions_no_student = array_filter($where_conditions, function ($condition) {
        return !str_contains($condition, 'student_id');
    });
    if (!empty($where_conditions_no_student)) {
        $summary_sql .= " AND " . implode(' AND ', $where_conditions_no_student);
    }
}

$summary_stmt = $conn->prepare($summary_sql);
$summary = [
    'total_transactions' => 0,
    'completed_count' => 0,
    'pending_count' => 0,
    'failed_count' => 0,
    'refunded_count' => 0,
    'total_completed_amount' => 0,
    'total_pending_amount' => 0,
    'total_refunded_amount' => 0
];

if ($summary_stmt) {
    $summary_stmt->bind_param("i", $user_id);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    if ($summary_row = $summary_result->fetch_assoc()) {
        $summary = $summary_row;
    }
    $summary_stmt->close();
}

// Get payment methods distribution
$methods_sql = "SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(amount) as total_amount
                FROM financial_transactions
                WHERE student_id = ? AND status = 'completed'
                GROUP BY payment_method
                ORDER BY total_amount DESC";
$methods_stmt = $conn->prepare($methods_sql);
$methods_stmt->bind_param("i", $user_id);
$methods_stmt->execute();
$methods_result = $methods_stmt->get_result();
$payment_methods = [];
while ($method = $methods_result->fetch_assoc()) {
    $payment_methods[] = $method;
}
$methods_stmt->close();

// Get monthly totals for chart
$monthly_sql = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount
                FROM financial_transactions
                WHERE student_id = ? AND status = 'completed'
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12";
$monthly_stmt = $conn->prepare($monthly_sql);
$monthly_stmt->bind_param("i", $user_id);
$monthly_stmt->execute();
$monthly_result = $monthly_stmt->get_result();
$monthly_data = [];
while ($month = $monthly_result->fetch_assoc()) {
    $monthly_data[] = $month;
}
$monthly_stmt->close();

// Log access
logActivity($user_id, 'payment_history_access', 'Student accessed payment history', $_SERVER['REMOTE_ADDR']);

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            text-decoration: none;
            color: var(--primary);
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-select,
        .filter-input {
            padding: 0.625rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background-color: #e01475;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #d62839;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border-top: 4px solid var(--primary);
        }

        .stat-card.success {
            border-top-color: var(--success);
        }

        .stat-card.warning {
            border-top-color: var(--warning);
        }

        .stat-card.danger {
            border-top-color: var(--danger);
        }

        .stat-card.info {
            border-top-color: var(--info);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: rgba(67, 97, 238, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .stat-card.success .stat-icon {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-subtext {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .content-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .card-actions {
            display: flex;
            gap: 0.5rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table thead {
            background-color: #f8f9fa;
        }

        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
            vertical-align: top;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-completed {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-failed {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .status-refunded {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .type-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .type-registration {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .type-tuition {
            background-color: rgba(114, 9, 183, 0.1);
            color: var(--secondary);
        }

        .type-late_fee {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .type-refund {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            background: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .pagination-btn:hover:not(:disabled) {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-btn.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .transaction-details {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 1rem;
            padding: 0.5rem 0;
            font-size: 0.875rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray);
        }

        .detail-value {
            color: var(--dark);
            word-break: break-word;
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .export-options {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .chart-container {
            height: 300px;
            margin-top: 1.5rem;
            position: relative;
        }

        .method-distribution {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .method-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .method-icon {
            width: 32px;
            height: 32px;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .method-name {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .method-stats {
            text-align: right;
        }

        .method-count {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .method-amount {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .filter-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: #f1f5f9;
            border-radius: 20px;
            font-size: 0.75rem;
            color: var(--gray);
        }

        .filter-tag button {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0;
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .card-actions {
                width: 100%;
                justify-content: flex-start;
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
            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
            <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php">
                Financial Overview
            </a>
            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
            <span>Payment History</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Payment History</h1>
            <p>View and manage your payment transactions</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-title">Total Completed</div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($summary['total_completed_amount'], 2); ?>
                </div>
                <div class="stat-subtext">
                    <?php echo $summary['completed_count']; ?> successful transactions
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-title">Pending Payments</div>
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($summary['total_pending_amount'], 2); ?>
                </div>
                <div class="stat-subtext">
                    <?php echo $summary['pending_count']; ?> pending transactions
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-title">Total Transactions</div>
                    <div class="stat-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                </div>
                <div class="stat-value">
                    <?php echo $summary['total_transactions']; ?>
                </div>
                <div class="stat-subtext">
                    All payment records
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Refunded Amount</div>
                    <div class="stat-icon">
                        <i class="fas fa-undo"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($summary['total_refunded_amount'], 2); ?>
                </div>
                <div class="stat-subtext">
                    <?php echo $summary['refunded_count']; ?> refunded transactions
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <h2 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Filter Transactions</h2>

            <form method="GET" action="" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="filter">Time Period</label>
                        <select name="filter" id="filter" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="last_30_days" <?php echo $filter === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="last_90_days" <?php echo $filter === 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                            <option value="this_year" <?php echo $filter === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="last_year" <?php echo $filter === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                            <option value="successful" <?php echo $filter === 'successful' ? 'selected' : ''; ?>>Successful Only</option>
                            <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                            <option value="failed" <?php echo $filter === 'failed' ? 'selected' : ''; ?>>Failed Only</option>
                            <option value="refunded" <?php echo $filter === 'refunded' ? 'selected' : ''; ?>>Refunded Only</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="type">Transaction Type</label>
                        <select name="type" id="type" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="registration" <?php echo $type === 'registration' ? 'selected' : ''; ?>>Registration</option>
                            <option value="tuition" <?php echo $type === 'tuition' ? 'selected' : ''; ?>>Tuition</option>
                            <option value="late_fee" <?php echo $type === 'late_fee' ? 'selected' : ''; ?>>Late Fee</option>
                            <option value="refund" <?php echo $type === 'refund' ? 'selected' : ''; ?>>Refund</option>
                            <option value="other" <?php echo $type === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" class="filter-input"
                            placeholder="Transaction ID, Reference, Description..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label for="start_date">From Date</label>
                        <input type="date" name="start_date" id="start_date" class="filter-input"
                            value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="end_date">To Date</label>
                        <input type="date" name="end_date" id="end_date" class="filter-input"
                            value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>

                    <div class="filter-group" style="grid-column: span 2;">
                        <label style="visibility: hidden;">Actions</label>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Active Filter Tags -->
            <?php if ($filter !== 'all' || $type !== 'all' || $status !== 'all' || !empty($search) || !empty($start_date) || !empty($end_date)): ?>
                <div class="filter-tags">
                    <span style="font-size: 0.75rem; color: var(--gray); margin-right: 0.5rem;">Active Filters:</span>
                    <?php if ($filter !== 'all'): ?>
                        <div class="filter-tag">
                            Time: <?php echo ucfirst(str_replace('_', ' ', $filter)); ?>
                            <a href="<?php echo removeQueryParam('filter'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($type !== 'all'): ?>
                        <div class="filter-tag">
                            Type: <?php echo ucfirst($type); ?>
                            <a href="<?php echo removeQueryParam('type'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($status !== 'all'): ?>
                        <div class="filter-tag">
                            Status: <?php echo ucfirst($status); ?>
                            <a href="<?php echo removeQueryParam('status'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($search)): ?>
                        <div class="filter-tag">
                            Search: "<?php echo htmlspecialchars($search); ?>"
                            <a href="<?php echo removeQueryParam('search'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($start_date)): ?>
                        <div class="filter-tag">
                            From: <?php echo date('M d, Y', strtotime($start_date)); ?>
                            <a href="<?php echo removeQueryParam('start_date'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($end_date)): ?>
                        <div class="filter-tag">
                            To: <?php echo date('M d, Y', strtotime($end_date)); ?>
                            <a href="<?php echo removeQueryParam('end_date'); ?>" title="Remove filter">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Methods Distribution -->
        <?php if (!empty($payment_methods)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">Payment Methods</h2>
                </div>

                <div class="method-distribution">
                    <?php foreach ($payment_methods as $method): ?>
                        <div class="method-item">
                            <div class="method-info">
                                <div class="method-icon">
                                    <?php if ($method['payment_method'] == 'online'): ?>
                                        <i class="fas fa-globe"></i>
                                    <?php elseif ($method['payment_method'] == 'bank_transfer'): ?>
                                        <i class="fas fa-university"></i>
                                    <?php elseif ($method['payment_method'] == 'cash'): ?>
                                        <i class="fas fa-money-bill-wave"></i>
                                    <?php elseif ($method['payment_method'] == 'cheque'): ?>
                                        <i class="fas fa-file-invoice"></i>
                                    <?php elseif ($method['payment_method'] == 'pos'): ?>
                                        <i class="fas fa-credit-card"></i>
                                    <?php else: ?>
                                        <i class="fas fa-wallet"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="method-name">
                                    <?php
                                    $method_labels = [
                                        'online' => 'Online Payment',
                                        'bank_transfer' => 'Bank Transfer',
                                        'cash' => 'Cash',
                                        'cheque' => 'Cheque',
                                        'pos' => 'POS'
                                    ];
                                    echo $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']);
                                    ?>
                                </div>
                            </div>
                            <div class="method-stats">
                                <div class="method-count"><?php echo $method['count']; ?> transactions</div>
                                <div class="method-amount">₦<?php echo number_format($method['total_amount'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Transactions Table -->
        <div class="content-card">
            <div class="card-header">
                <h2 class="card-title">Payment Transactions</h2>
                <div class="card-actions">
                    <button type="button" onclick="exportTransactions()" class="btn btn-success btn-sm">
                        <i class="fas fa-file-export"></i> Export
                    </button>
                    <button type="button" onclick="printTransactions()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="no-data">
                    <i class="fas fa-exchange-alt"></i>
                    <p>No payment transactions found</p>
                    <?php if ($filter !== 'all' || $type !== 'all' || $status !== 'all' || !empty($search) || !empty($start_date) || !empty($end_date)): ?>
                        <p style="margin-top: 0.5rem;">Try adjusting your filters</p>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/" class="btn btn-primary" style="margin-top: 1rem;">
                            Clear All Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction Details</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo date('M d, Y', strtotime($transaction['created_at'])); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--gray);">
                                            <?php echo date('g:i A', strtotime($transaction['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                            <?php if (!empty($transaction['transaction_id'])): ?>
                                                ID: <?php echo htmlspecialchars($transaction['transaction_id']); ?>
                                            <?php else: ?>
                                                ID: TRX-<?php echo str_pad($transaction['id'], 6, '0', STR_PAD_LEFT); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($transaction['gateway_reference'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--gray); margin-bottom: 0.25rem;">
                                                Ref: <?php echo htmlspecialchars($transaction['gateway_reference']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($transaction['course_title'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--dark);">
                                                <?php echo htmlspecialchars($transaction['course_title']); ?>
                                                <?php if (!empty($transaction['batch_code'])): ?>
                                                    • <?php echo htmlspecialchars($transaction['batch_code']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($transaction['description'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($transaction['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; font-size: 1rem; 
                                             color: <?php echo $transaction['transaction_type'] == 'refund' ? 'var(--success)' : 'var(--dark)'; ?>;">
                                            <?php echo $transaction['transaction_type'] == 'refund' ? '-' : ''; ?>
                                            ₦<?php echo number_format($transaction['amount'], 2); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                            <?php echo htmlspecialchars($transaction['payment_method_label']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo $transaction['transaction_type']; ?>">
                                            <?php echo htmlspecialchars($transaction['type_label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($transaction['status'] == 'completed'): ?>
                                            <span class="status-badge status-completed">Completed</span>
                                        <?php elseif ($transaction['status'] == 'pending'): ?>
                                            <span class="status-badge status-pending">Pending</span>
                                        <?php elseif ($transaction['status'] == 'failed'): ?>
                                            <span class="status-badge status-failed">Failed</span>
                                        <?php elseif ($transaction['status'] == 'refunded'): ?>
                                            <span class="status-badge status-refunded">Refunded</span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background-color: #f1f5f9; color: var(--gray);">
                                                <?php echo ucfirst($transaction['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($transaction['is_verified']): ?>
                                            <div style="font-size: 0.7rem; color: var(--success); margin-top: 0.25rem;">
                                                <i class="fas fa-check-circle"></i> Verified
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" onclick="viewTransaction(<?php echo $transaction['id']; ?>)"
                                                class="btn btn-sm" style="background: #f1f5f9; color: var(--dark);" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($transaction['receipt_url']): ?>
                                                <a href="<?php echo htmlspecialchars($transaction['receipt_url']); ?>"
                                                    target="_blank" class="btn btn-sm btn-success" title="View Receipt">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($transaction['status'] == 'completed' && $transaction['receipt_url']): ?>
                                                <button type="button" onclick="downloadReceipt(<?php echo $transaction['id']; ?>)"
                                                    class="btn btn-sm btn-primary" title="Download Receipt">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Details Panel (Initially Hidden) -->
                                <tr id="details-<?php echo $transaction['id']; ?>" style="display: none;">
                                    <td colspan="6">
                                        <div class="transaction-details">
                                            <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem;">Transaction Details</h4>
                                            <div class="detail-row">
                                                <div class="detail-label">Transaction ID:</div>
                                                <div class="detail-value">
                                                    <?php echo !empty($transaction['transaction_id']) ?
                                                        htmlspecialchars($transaction['transaction_id']) :
                                                        'TRX-' . str_pad($transaction['id'], 6, '0', STR_PAD_LEFT); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($transaction['gateway_reference'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Gateway Reference:</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($transaction['gateway_reference']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['invoice_number'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Invoice Number:</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($transaction['invoice_number']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['course_title'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Course:</div>
                                                    <div class="detail-value">
                                                        <?php echo htmlspecialchars($transaction['course_title']); ?>
                                                        <?php if (!empty($transaction['batch_code'])): ?>
                                                            (<?php echo htmlspecialchars($transaction['batch_code']); ?>)
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['program_name'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Program:</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($transaction['program_name']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="detail-row">
                                                <div class="detail-label">Payment Method:</div>
                                                <div class="detail-value"><?php echo htmlspecialchars($transaction['payment_method_label']); ?></div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="detail-label">Amount:</div>
                                                <div class="detail-value" style="font-weight: 700;">
                                                    ₦<?php echo number_format($transaction['amount'], 2); ?>
                                                    (<?php echo $transaction['currency'] ?? 'NGN'; ?>)
                                                </div>
                                            </div>
                                            <div class="detail-row">
                                                <div class="detail-label">Transaction Date:</div>
                                                <div class="detail-value">
                                                    <?php echo date('F j, Y, g:i A', strtotime($transaction['created_at'])); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($transaction['verified_at'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Verified At:</div>
                                                    <div class="detail-value">
                                                        <?php echo date('F j, Y, g:i A', strtotime($transaction['verified_at'])); ?>
                                                        <?php if (!empty($transaction['verified_by'])): ?>
                                                            (By Administrator)
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['description'])): ?>
                                                <div class="detail-row">
                                                    <div class="detail-label">Description:</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 1rem;">
                                                <button type="button" onclick="closeDetails(<?php echo $transaction['id']; ?>)"
                                                    class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-times"></i> Close Details
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <button class="pagination-btn" onclick="goToPage(1)" <?php echo $page == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-left"></i>
                        </button>
                        <button class="pagination-btn" onclick="goToPage(<?php echo $page - 1; ?>)" <?php echo $page == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-left"></i>
                        </button>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <button class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>" onclick="goToPage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>

                        <button class="pagination-btn" onclick="goToPage(<?php echo $page + 1; ?>)" <?php echo $page == $total_pages ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-right"></i>
                        </button>
                        <button class="pagination-btn" onclick="goToPage(<?php echo $total_pages; ?>)" <?php echo $page == $total_pages ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-right"></i>
                        </button>

                        <div class="page-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> •
                            Showing <?php echo min($per_page, count($transactions)); ?> of <?php echo $total_count; ?> transactions
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Export Options -->
        <div class="content-card">
            <div class="card-header">
                <h2 class="card-title">Export & Reports</h2>
            </div>

            <div class="export-options">
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export_transactions.php?format=pdf&student_id=<?php echo $user_id; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-file-pdf"></i> Export as PDF
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export_transactions.php?format=excel&student_id=<?php echo $user_id; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-file-excel"></i> Export as Excel
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export_transactions.php?format=csv&student_id=<?php echo $user_id; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-file-csv"></i> Export as CSV
                </a>
                <button type="button" onclick="printReceipts()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print All Receipts
                </button>
                <button type="button" onclick="downloadAllReceipts()" class="btn btn-success">
                    <i class="fas fa-download"></i> Download All Receipts
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("#start_date", {
            dateFormat: "Y-m-d",
            maxDate: new Date()
        });

        flatpickr("#end_date", {
            dateFormat: "Y-m-d",
            maxDate: new Date()
        });

        // View transaction details
        function viewTransaction(transactionId) {
            const detailsRow = document.getElementById('details-' + transactionId);
            const isVisible = detailsRow.style.display === 'table-row';

            // Hide all other detail rows
            document.querySelectorAll('[id^="details-"]').forEach(row => {
                row.style.display = 'none';
            });

            // Toggle current row
            detailsRow.style.display = isVisible ? 'none' : 'table-row';

            // Scroll to details if showing
            if (!isVisible) {
                detailsRow.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }

        // Close transaction details
        function closeDetails(transactionId) {
            document.getElementById('details-' + transactionId).style.display = 'none';
        }

        // Pagination function
        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Export transactions
        function exportTransactions() {
            const url = new URL(window.location.href);
            url.pathname = '<?php echo BASE_URL; ?>modules/student/finance/reports/export_transactions.php';
            url.searchParams.set('format', 'excel');
            url.searchParams.set('student_id', '<?php echo $user_id; ?>');
            window.open(url.toString(), '_blank');
        }

        // Print transactions
        function printTransactions() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Payment History - <?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #333; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; }
                        .total { font-weight: bold; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <h1>Payment History</h1>
                    <p><strong>Student:</strong> <?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></p>
                    <p><strong>Printed:</strong> ${new Date().toLocaleString()}</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction ID</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td>${new Date('<?php echo $t['created_at']; ?>').toLocaleDateString()}</td>
                                <td>${'<?php echo !empty($t['transaction_id']) ? $t['transaction_id'] : 'TRX-' . str_pad($t['id'], 6, '0', STR_PAD_LEFT); ?>'}</td>
                                <td>
                                    ${'<?php echo htmlspecialchars($t['course_title'] ?? $t['description'] ?? ''); ?>'}
                                    ${'<?php echo !empty($t['batch_code']) ? ' (' . $t['batch_code'] . ')' : ''; ?>'}
                                </td>
                                <td>₦${parseFloat('<?php echo $t['amount']; ?>').toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                <td>${'<?php echo $t['type_label']; ?>'}</td>
                                <td>${'<?php echo ucfirst($t['status']); ?>'}</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="total">
                        Total: ₦${parseFloat('<?php echo $summary['total_completed_amount']; ?>').toLocaleString('en-US', {minimumFractionDigits: 2})}
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Download receipt
        function downloadReceipt(transactionId) {
            window.open('<?php echo BASE_URL; ?>modules/student/finance/reports/download_receipt.php?transaction_id=' + transactionId, '_blank');
        }

        // Print all receipts
        function printReceipts() {
            if (confirm('Print receipts for all completed transactions?')) {
                const transactionIds = [
                    <?php
                    $completed_ids = [];
                    foreach ($transactions as $t) {
                        if ($t['status'] == 'completed') {
                            $completed_ids[] = $t['id'];
                        }
                    }
                    echo implode(', ', $completed_ids);
                    ?>
                ];

                if (transactionIds.length > 0) {
                    const url = '<?php echo BASE_URL; ?>modules/student/finance/reports/print_receipts.php?ids=' + transactionIds.join(',');
                    window.open(url, '_blank');
                } else {
                    alert('No completed transactions to print.');
                }
            }
        }

        // Download all receipts
        function downloadAllReceipts() {
            if (confirm('Download receipts for all completed transactions as ZIP file?')) {
                const transactionIds = [
                    <?php
                    $completed_ids = [];
                    foreach ($transactions as $t) {
                        if ($t['status'] == 'completed' && $t['receipt_url']) {
                            $completed_ids[] = $t['id'];
                        }
                    }
                    echo implode(', ', $completed_ids);
                    ?>
                ];

                if (transactionIds.length > 0) {
                    const url = '<?php echo BASE_URL; ?>modules/student/finance/reports/download_all_receipts.php?ids=' + transactionIds.join(',');
                    window.location.href = url;
                } else {
                    alert('No receipts available for download.');
                }
            }
        }

        // Auto-submit form on filter change
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                if (this.getAttribute('data-auto-submit') !== 'false') {
                    document.getElementById('filterForm').submit();
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Esc to clear search
            if (e.key === 'Escape') {
                document.getElementById('search').value = '';
                document.getElementById('filterForm').submit();
            }

            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printTransactions();
            }

            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportTransactions();
            }
        });

        // Refresh data every 5 minutes
        setInterval(() => {
            fetch('<?php echo BASE_URL; ?>modules/student/finance/ajax/refresh_transactions.php?student_id=<?php echo $user_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.refresh_needed) {
                        if (confirm('New transactions detected. Refresh page?')) {
                            location.reload();
                        }
                    }
                })
                .catch(error => console.error('Error checking for updates:', error));
        }, 5 * 60 * 1000);

        // Show loading indicator during exports
        document.querySelectorAll('.export-options a, .export-options button').forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.href || this.onclick) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    this.disabled = true;

                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }, 3000);
                }
            });
        });
    </script>
</body>

</html>

<?php
// Helper function to remove query parameters
function removeQueryParam($param)
{
    $url = parse_url($_SERVER['REQUEST_URI']);
    $query = [];
    if (isset($url['query'])) {
        parse_str($url['query'], $query);
    }
    unset($query[$param]);

    $new_query = http_build_query($query);
    $path = $url['path'];

    return $path . ($new_query ? '?' . $new_query : '');
}
?>