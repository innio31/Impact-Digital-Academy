<?php
// modules/admin/finance/invoices/index.php

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

// Get filter parameters
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$student_id = $_GET['student_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$search = $_GET['search'] ?? '';
$program_type = $_GET['program_type'] ?? '';

// Pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$sql = "SELECT i.*, 
               u.first_name, u.last_name, u.email, u.phone,
               cb.batch_code, c.title as course_title,
               p.name as program_name, p.program_type,
               (i.amount - i.paid_amount) as outstanding_amount,
               DATEDIFF(i.due_date, CURDATE()) as days_until_due
        FROM invoices i
        JOIN users u ON u.id = i.student_id
        JOIN class_batches cb ON cb.id = i.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.id = c.program_id  -- FIXED: Use p.id = c.program_id
        WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total 
              FROM invoices i
              JOIN users u ON u.id = i.student_id
              JOIN class_batches cb ON cb.id = i.class_id
              JOIN courses c ON c.id = cb.course_id
              JOIN programs p ON p.id = c.program_id  -- FIXED: Use p.id = c.program_id
              WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total 
              FROM invoices i
              JOIN users u ON u.id = i.student_id
              JOIN class_batches cb ON cb.id = i.class_id
              JOIN courses c ON c.id = cb.course_id
              JOIN programs p ON p.program_code = c.program_id
              WHERE 1=1";

$params = [];
$types = '';

if ($status) {
    $sql .= " AND i.status = ?";
    $count_sql .= " AND i.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($date_from && $date_to) {
    $sql .= " AND DATE(i.created_at) BETWEEN ? AND ?";
    $count_sql .= " AND DATE(i.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
} elseif ($date_from) {
    $sql .= " AND DATE(i.created_at) >= ?";
    $count_sql .= " AND DATE(i.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
} elseif ($date_to) {
    $sql .= " AND DATE(i.created_at) <= ?";
    $count_sql .= " AND DATE(i.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($student_id && is_numeric($student_id)) {
    $sql .= " AND i.student_id = ?";
    $count_sql .= " AND i.student_id = ?";
    $params[] = $student_id;
    $types .= 'i';
}

if ($class_id && is_numeric($class_id)) {
    $sql .= " AND i.class_id = ?";
    $count_sql .= " AND i.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}

if ($program_type) {
    $sql .= " AND p.program_type = ?";
    $count_sql .= " AND p.program_type = ?";
    $params[] = $program_type;
    $types .= 's';
}

if ($search) {
    $sql .= " AND (i.invoice_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $count_sql .= " AND (i.invoice_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

// Order and pagination
$sql .= " ORDER BY i.due_date ASC, i.created_at DESC LIMIT ? OFFSET ?";

// Count query (without pagination parameters)
$count_stmt = $conn->prepare($count_sql);
if ($types && !empty($params)) {
    // Bind parameters for count query
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);

// Main query with pagination
$stmt = $conn->prepare($sql);

if ($types && !empty($params)) {
    // Add pagination parameters
    $all_params = array_merge($params, [$per_page, $offset]);
    $all_types = $types . 'ii';

    // Bind all parameters
    $stmt->bind_param($all_types, ...$all_params);
} else {
    // Only pagination parameters
    $stmt->bind_param('ii', $per_page, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$invoices = $result->fetch_all(MYSQLI_ASSOC);

// Get stats for dashboard
$stats_sql = "SELECT 
    COUNT(*) as total_invoices,
    SUM(amount) as total_amount,
    SUM(paid_amount) as total_paid,
    SUM(amount - paid_amount) as total_outstanding,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count
    FROM invoices";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity($_SESSION['user_id'], 'invoice_list_view', "Viewed invoices list with filters");

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $action = $_POST['action'];
    $selected_ids = $_POST['selected_ids'] ?? [];

    if (!empty($selected_ids)) {
        $ids_str = implode(',', array_map('intval', $selected_ids));

        switch ($action) {
            case 'mark_as_paid':
                $update_sql = "UPDATE invoices SET status = 'paid', paid_amount = amount, balance = 0 WHERE id IN ($ids_str)";
                $conn->query($update_sql);

                // Update student financial status
                foreach ($selected_ids as $invoice_id) {
                    $invoice_query = $conn->prepare("SELECT student_id, class_id, amount FROM invoices WHERE id = ?");
                    $invoice_query->bind_param("i", $invoice_id);
                    $invoice_query->execute();
                    $invoice_data = $invoice_query->get_result()->fetch_assoc();

                    if ($invoice_data) {
                        updateFinancialStatusAfterPayment($invoice_data['student_id'], $invoice_data['class_id'], $invoice_data['amount'], 'invoice_paid');
                    }
                }

                $_SESSION['flash_message'] = 'Selected invoices marked as paid';
                break;

            case 'send_reminders':
                // Send email reminders
                foreach ($selected_ids as $invoice_id) {
                    sendInvoiceNotification($invoice_details['student_id'], $invoice_id, $invoice_details['invoice_number'], $invoice_details['amount']);
                }
                $_SESSION['flash_message'] = 'Payment reminders sent';
                break;

            case 'delete':
                $delete_sql = "DELETE FROM invoices WHERE id IN ($ids_str) AND status != 'paid'";
                $conn->query($delete_sql);
                $_SESSION['flash_message'] = 'Selected invoices deleted';
                break;
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Invoices - Admin Portal</title>
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
            --dark-light: #334155;
            --sidebar: #1e293b;
            --revenue: #10b981;
            --pending: #f59e0b;
            --overdue: #ef4444;
            --issues: #8b5cf6;
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

        .sidebar {
            width: 250px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
            position: relative;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid var(--dark-light);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
            margin-bottom: 0.5rem;
        }

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .nav-section {
            padding: 0.5rem 1.5rem;
            color: #94a3b8;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 1rem;
        }

        .nav-section:first-child {
            margin-top: 0;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
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
            text-decoration: none;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.revenue,
        .stat-card.success {
            border-top-color: var(--success);
        }

        .stat-card.pending,
        .stat-card.warning {
            border-top-color: var(--warning);
        }

        .stat-card.overdue,
        .stat-card.danger {
            border-top-color: var(--danger);
        }

        .stat-card.issues {
            border-top-color: var(--issues);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            transform: rotate(45deg) translate(30px, -30px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.2;
            color: inherit;
        }

        /* Charts and Tables Container */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .content-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed,
        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-suspended {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
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

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .action-desc {
            color: #64748b;
            font-size: 0.85rem;
        }

        /* Chart Placeholder */
        .chart-placeholder {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border-radius: 8px;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .chart-placeholder i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

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

        /* Bulk Actions */
        .bulk-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Include sidebar -->
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Finance Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php" class="active">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <div class="nav-section">Financial Management</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/index.php">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/index.php">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/fees/index.php">
                            <i class="fas fa-calculator"></i> Fee Management</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/students/">
                            <i class="fas fa-users"></i> Student Finance</a></li>

                    <div class="nav-section">Reports & Analytics</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php">
                            <i class="fas fa-chart-bar"></i> Revenue Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

                    <div class="nav-section">System</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/">
                            <i class="fas fa-cog"></i> Finance Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/">
                            <i class="fas fa-sliders-h"></i> System Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-file-invoice-dollar"></i>
                    Manage Invoices
                </h1>
                <div>
                    <a href="generate.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Generate Invoice
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Finance
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php
                    echo $_SESSION['flash_message'];
                    unset($_SESSION['flash_message']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_invoices'] ?? 0; ?></div>
                    <div class="stat-label">Total Invoices</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo formatCurrency($stats['total_amount'] ?? 0); ?></div>
                    <div class="stat-label">Total Amount</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo formatCurrency($stats['total_paid'] ?? 0); ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo formatCurrency($stats['total_outstanding'] ?? 0); ?></div>
                    <div class="stat-label">Outstanding</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo $stats['pending_count'] ?? 0; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-number"><?php echo $stats['overdue_count'] ?? 0; ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                            <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>

                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>

                    <div class="form-group">
                        <label>Program Type</label>
                        <select name="program_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Invoice #, Student Name or Email" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="bulk-actions">
                    <select name="action" class="form-control" style="width: auto;" onchange="if(this.value) confirmBulkAction(this.value)">
                        <option value="">Bulk Actions</option>
                        <option value="mark_as_paid">Mark as Paid</option>
                        <option value="send_reminders">Send Payment Reminders</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="selectAllRows()">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                </div>

                <!-- Invoices Table -->
                <div class="table-container">
                    <?php if (!empty($invoices)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAll"></th>
                                    <th>Invoice #</th>
                                    <th>Student</th>
                                    <th>Program/Course</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo $invoice['id']; ?>" class="row-checkbox">
                                        </td>
                                        <td>
                                            <strong><?php echo $invoice['invoice_number']; ?></strong><br>
                                            <small style="color: #64748b;"><?php echo date('M j, Y', strtotime($invoice['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></div>
                                            <small style="color: #64748b;"><?php echo $invoice['email']; ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($invoice['program_name']); ?></div>
                                            <small style="color: #64748b;"><?php echo $invoice['course_title']; ?> - <?php echo $invoice['batch_code']; ?></small><br>
                                            <span style="font-size: 0.75rem; padding: 0.1rem 0.5rem; background: #e2e8f0; border-radius: 4px;">
                                                <?php echo $invoice['program_type']; ?>
                                            </span>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?php echo formatCurrency($invoice['amount']); ?>
                                        </td>
                                        <td>
                                            <?php echo formatCurrency($invoice['paid_amount']); ?>
                                            <?php if ($invoice['paid_amount'] > 0): ?>
                                                <br><small style="color: #64748b;">Balance: <?php echo formatCurrency($invoice['amount'] - $invoice['paid_amount']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?><br>
                                            <?php if ($invoice['days_until_due'] < 0): ?>
                                                <small style="color: var(--danger);">
                                                    <?php echo abs($invoice['days_until_due']); ?> days overdue
                                                </small>
                                            <?php elseif ($invoice['days_until_due'] <= 7): ?>
                                                <small style="color: var(--warning);">
                                                    Due in <?php echo $invoice['days_until_due']; ?> days
                                                </small>
                                            <?php else: ?>
                                                <small style="color: #64748b;">
                                                    Due in <?php echo $invoice['days_until_due']; ?> days
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $invoice['status']; ?>">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem;">
                                                <a href="view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-primary" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="send.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info" title="Send">
                                                    <i class="fas fa-paper-plane"></i>
                                                </a>
                                                <?php if ($invoice['status'] !== 'paid'): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?invoice_id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-success" title="Record Payment">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </a>
                                                    <a href="generate.php?id=<?php echo $invoice['id']; ?>&duplicate=1" class="btn btn-sm btn-secondary" title="Duplicate">
                                                        <i class="fas fa-copy"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <h3>No Invoices Found</h3>
                            <p>No invoices match your search criteria.</p>
                            <a href="generate.php" class="btn btn-primary mt-2">
                                <i class="fas fa-plus"></i> Generate First Invoice
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });

        function selectAllRows() {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            document.getElementById('selectAll').checked = true;
        }

        function confirmBulkAction(action) {
            const selected = document.querySelectorAll('.row-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one invoice.');
                return;
            }

            let confirmMessage = '';
            switch (action) {
                case 'mark_as_paid':
                    confirmMessage = `Mark ${selected.length} invoice(s) as paid?`;
                    break;
                case 'send_reminders':
                    confirmMessage = `Send payment reminders for ${selected.length} invoice(s)?`;
                    break;
                case 'delete':
                    confirmMessage = `Delete ${selected.length} selected invoice(s)? This action cannot be undone.`;
                    break;
            }

            if (confirm(confirmMessage)) {
                document.getElementById('bulkForm').submit();
            } else {
                document.querySelector('[name="action"]').value = '';
            }
        }

        // Auto-submit on checkbox change for bulk actions
        document.querySelectorAll('.row-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (!this.checked) {
                    document.getElementById('selectAll').checked = false;
                } else {
                    // Check if all are checked
                    const allChecked = Array.from(document.querySelectorAll('.row-checkbox')).every(cb => cb.checked);
                    document.getElementById('selectAll').checked = allChecked;
                }
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>