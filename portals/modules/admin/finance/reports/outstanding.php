<?php
// modules/admin/finance/reports/outstanding.php

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
$period = $_GET['period'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$program_type = $_GET['program_type'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$export_type = $_GET['export'] ?? '';

// Set default dates based on period
if ($period === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
} elseif ($period === 'quarter') {
    $date_from = date('Y-m-d', strtotime('-3 months'));
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
} elseif ($period === 'all' || empty($date_from)) {
    $date_from = '2024-01-01';
}

// Build WHERE clause
$where_conditions = ["i.due_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$param_types = 'ss';

if (!empty($program_type)) {
    $where_conditions[] = "p.program_type = ?";
    $params[] = $program_type;
    $param_types .= 's';
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
} else {
    // Default to pending and overdue
    $where_conditions[] = "i.status IN ('pending', 'overdue')";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get outstanding invoices
$outstanding_sql = "SELECT 
    i.id,
    i.invoice_number,
    i.invoice_type,
    i.amount,
    i.paid_amount,
    (i.amount - i.paid_amount) as balance,
    i.due_date,
    i.status,
    i.created_at,
    u.id as student_id,
    u.first_name,
    u.last_name,
    u.email,
    u.phone,
    cb.batch_code,
    c.title as course_title,
    p.name as program_name,
    p.program_type,
    p.program_code,
    DATEDIFF(i.due_date, CURDATE()) as days_until_due,
    CASE 
        WHEN i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date)
        ELSE 0 
    END as days_overdue,
    sfs.is_suspended,
    sfs.suspended_at
FROM invoices i
JOIN users u ON u.id = i.student_id
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
LEFT JOIN student_financial_status sfs ON sfs.student_id = i.student_id AND sfs.class_id = i.class_id
$where_clause
ORDER BY 
    CASE 
        WHEN i.due_date < CURDATE() THEN 0 
        ELSE 1 
    END,
    i.due_date ASC,
    i.amount DESC";

$outstanding_data = [];
try {
    if ($stmt = $conn->prepare($outstanding_sql)) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $outstanding_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Get summary statistics
$summary_sql = "SELECT 
    COUNT(i.id) as total_invoices,
    SUM(i.amount) as total_amount,
    SUM(i.paid_amount) as total_paid,
    SUM(i.amount - i.paid_amount) as total_balance,
    AVG(i.amount - i.paid_amount) as avg_balance,
    MIN(i.due_date) as earliest_due,
    MAX(i.due_date) as latest_due,
    SUM(CASE WHEN i.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
    SUM(CASE WHEN i.due_date < CURDATE() THEN (i.amount - i.paid_amount) ELSE 0 END) as overdue_amount,
    SUM(CASE WHEN DATEDIFF(i.due_date, CURDATE()) BETWEEN 0 AND 7 THEN 1 ELSE 0 END) as due_soon_count,
    SUM(CASE WHEN DATEDIFF(i.due_date, CURDATE()) BETWEEN 0 AND 7 THEN (i.amount - i.paid_amount) ELSE 0 END) as due_soon_amount
FROM invoices i
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
$where_clause";

$summary_data = [];
if ($stmt = $conn->prepare($summary_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary_data = $result->fetch_assoc();
    $stmt->close();
}

// Get program-wise breakdown
$program_breakdown_sql = "SELECT 
    p.program_type,
    p.name as program_name,
    p.program_code,
    COUNT(i.id) as invoice_count,
    SUM(i.amount) as total_amount,
    SUM(i.paid_amount) as total_paid,
    SUM(i.amount - i.paid_amount) as total_balance,
    AVG(i.amount - i.paid_amount) as avg_balance
FROM invoices i
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
$where_clause
GROUP BY p.id
ORDER BY total_balance DESC";

$program_breakdown = [];
if ($stmt = $conn->prepare($program_breakdown_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $program_breakdown = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get aging analysis
$aging_sql = "SELECT 
    CASE 
        WHEN i.due_date < CURDATE() THEN 
            CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN '1-30 days'
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 60 THEN '31-60 days'
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 90 THEN '61-90 days'
                ELSE 'Over 90 days'
            END
        WHEN DATEDIFF(i.due_date, CURDATE()) <= 7 THEN 'Due in 7 days'
        WHEN DATEDIFF(i.due_date, CURDATE()) <= 30 THEN 'Due in 30 days'
        ELSE 'Due after 30 days'
    END as aging_bucket,
    COUNT(i.id) as invoice_count,
    SUM(i.amount - i.paid_amount) as total_balance
FROM invoices i
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
$where_clause
GROUP BY aging_bucket
ORDER BY 
    CASE aging_bucket
        WHEN 'Over 90 days' THEN 1
        WHEN '61-90 days' THEN 2
        WHEN '31-60 days' THEN 3
        WHEN '1-30 days' THEN 4
        WHEN 'Due in 7 days' THEN 5
        WHEN 'Due in 30 days' THEN 6
        ELSE 7
    END";

$aging_analysis = [];
if ($stmt = $conn->prepare($aging_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $aging_analysis = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Export to CSV if requested
if ($export_type === 'csv') {
    $export_filename = "outstanding_payments_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export_filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, [
        'Invoice #',
        'Student Name',
        'Email',
        'Program',
        'Course',
        'Amount',
        'Paid',
        'Balance',
        'Due Date',
        'Days Overdue',
        'Status',
        'Invoice Type',
        'Created Date'
    ]);

    // Write data
    foreach ($outstanding_data as $row) {
        fputcsv($output, [
            $row['invoice_number'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['email'],
            $row['program_name'],
            $row['course_title'],
            $row['amount'],
            $row['paid_amount'],
            $row['balance'],
            $row['due_date'],
            $row['days_overdue'],
            $row['status'],
            $row['invoice_type'],
            $row['created_at']
        ]);
    }

    fclose($output);
    exit();
}

// Log activity
logActivity($_SESSION['user_id'], 'outstanding_report_view', "Viewed outstanding payments report");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Payments Report - Finance Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        /* Header styles */
        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
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

        .page-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
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

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .summary-card.warning {
            border-top-color: var(--warning);
        }

        .summary-card.danger {
            border-top-color: var(--danger);
        }

        .summary-card.success {
            border-top-color: var(--success);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .summary-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .summary-subtext {
            color: #94a3b8;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        /* Charts Container */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
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
            height: 300px;
        }

        /* Data Tables */
        .data-table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
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

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
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

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .days-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .days-overdue {
            background: #fee2e2;
            color: #dc2626;
        }

        .days-due-soon {
            background: #fef3c7;
            color: #d97706;
        }

        .days-future {
            background: #dbeafe;
            color: #2563eb;
        }

        /* Sidebar navigation styles */
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

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .charts-grid {
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

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .page-actions {
                flex-direction: column;
                align-items: flex-start;
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
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header" style="padding: 1.5rem; border-bottom: 1px solid var(--dark-light);">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Finance Reports</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <div class="nav-section">Financial Reports</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php">
                            <i class="fas fa-chart-bar"></i> Revenue Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php" class="active">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

                    <div class="nav-section">Back to Finance</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/students/overdue.php">
                            <i class="fas fa-users"></i> Overdue Students</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-exclamation-triangle"></i>
                    Outstanding Payments Report
                </h1>
                <p style="color: #64748b; margin-top: 0.5rem;">
                    Track and manage overdue and pending payments across all programs
                </p>
                <div class="page-actions">
                    <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&program_type=<?php echo $program_type; ?>&status=<?php echo $status_filter; ?>"
                        class="btn btn-success">
                        <i class="fas fa-file-export"></i> Export to CSV
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/notifications/send_reminders.php"
                        class="btn btn-warning">
                        <i class="fas fa-bell"></i> Send Reminders
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/generate.php"
                        class="btn btn-primary">
                        <i class="fas fa-file-invoice"></i> Generate Invoices
                    </a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="background: #e2e8f0; color: var(--dark);">
                        <i class="fas fa-redo"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Report Filters</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Time Period</label>
                        <select name="period" class="form-control" id="periodSelect">
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>Last 3 Months</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>

                    <div class="form-group" id="dateRangeGroup" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>">
                        <label>Date Range</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
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
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Outstanding</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial Payment</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="background: #e2e8f0; color: var(--dark);">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-value">₦<?php echo number_format($summary_data['total_balance'] ?? 0, 2); ?></div>
                    <div class="summary-label">Total Outstanding</div>
                    <div class="summary-subtext"><?php echo $summary_data['total_invoices'] ?? 0; ?> invoices</div>
                </div>

                <div class="summary-card danger">
                    <div class="summary-value">₦<?php echo number_format($summary_data['overdue_amount'] ?? 0, 2); ?></div>
                    <div class="summary-label">Overdue Amount</div>
                    <div class="summary-subtext"><?php echo $summary_data['overdue_count'] ?? 0; ?> overdue invoices</div>
                </div>

                <div class="summary-card warning">
                    <div class="summary-value">₦<?php echo number_format($summary_data['due_soon_amount'] ?? 0, 2); ?></div>
                    <div class="summary-label">Due Soon</div>
                    <div class="summary-subtext"><?php echo $summary_data['due_soon_count'] ?? 0; ?> invoices due in 7 days</div>
                </div>

                <div class="summary-card">
                    <div class="summary-value">₦<?php echo number_format($summary_data['avg_balance'] ?? 0, 2); ?></div>
                    <div class="summary-label">Average Balance</div>
                    <div class="summary-subtext">Per outstanding invoice</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Aging Analysis -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-hourglass-half"></i> Aging Analysis</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="agingChart"></canvas>
                    </div>
                </div>

                <!-- Program Breakdown -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Outstanding by Program Type</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="programBreakdownChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Aging Analysis Table -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-table"></i> Aging Analysis Details</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Aging Bucket</th>
                                <th>Invoices</th>
                                <th>Total Balance</th>
                                <th>Percentage</th>
                                <th>Average Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($aging_analysis)):
                                $total_aging_balance = array_sum(array_column($aging_analysis, 'total_balance'));
                            ?>
                                <?php foreach ($aging_analysis as $item):
                                    $percentage = $total_aging_balance > 0 ? ($item['total_balance'] / $total_aging_balance * 100) : 0;
                                    $avg_balance = $item['invoice_count'] > 0 ? ($item['total_balance'] / $item['invoice_count']) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['aging_bucket']); ?></strong>
                                        </td>
                                        <td><?php echo $item['invoice_count']; ?></td>
                                        <td class="amount">₦<?php echo number_format($item['total_balance'], 2); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?php echo $percentage; ?>%; height: 100%; 
                                                        background
                                                        <?php echo strpos($item['aging_bucket'], 'Over 90') !== false ? '#ef4444' : (strpos($item['aging_bucket'], '61-90') !== false ? '#f97316' : (strpos($item['aging_bucket'], '31-60') !== false ? '#f59e0b' : (strpos($item['aging_bucket'], '1-30') !== false ? '#eab308' : (strpos($item['aging_bucket'], 'Due in 7') !== false ? '#3b82f6' : '#10b981')))); ?>">
                                                    </div>
                                                </div>
                                                <span style="font-size: 0.85rem;"><?php echo number_format($percentage, 1); ?>%</span>
                                            </div>
                                        </td>
                                        <td>₦<?php echo number_format($avg_balance, 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fas fa-hourglass-half" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3>No Aging Data</h3>
                                        <p>No outstanding invoices found for the selected filters.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Detailed Outstanding Invoices -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Detailed Outstanding Invoices</h3>
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Showing <?php echo count($outstanding_data); ?> outstanding invoices
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($outstanding_data)): ?>
                                <?php foreach ($outstanding_data as $invoice):
                                    $is_overdue = $invoice['due_date'] < date('Y-m-d');
                                    $days_diff = $is_overdue ? $invoice['days_overdue'] : $invoice['days_until_due'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $invoice['invoice_number']; ?></strong><br>
                                            <small style="color: #64748b;"><?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_type'])); ?></small>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></div>
                                            <small style="color: #64748b;"><?php echo $invoice['email']; ?></small><br>
                                            <?php if ($invoice['is_suspended']): ?>
                                                <small class="status-badge" style="background: #f1f5f9; color: #64748b; margin-top: 0.25rem;">
                                                    <i class="fas fa-user-slash"></i> Suspended
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($invoice['program_name']); ?></div>
                                            <span class="program-badge badge-<?php echo $invoice['program_type']; ?>">
                                                <?php echo $invoice['program_type']; ?>
                                            </span><br>
                                            <small style="color: #64748b;"><?php echo $invoice['course_title']; ?></small>
                                        </td>
                                        <td class="amount">₦<?php echo number_format($invoice['amount'], 2); ?></td>
                                        <td class="amount">₦<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                                        <td class="amount">
                                            <strong>₦<?php echo number_format($invoice['balance'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($invoice['due_date'])); ?><br>
                                            <?php if ($is_overdue): ?>
                                                <span class="days-badge days-overdue">
                                                    <i class="fas fa-clock"></i> <?php echo $days_diff; ?> days overdue
                                                </span>
                                            <?php elseif ($days_diff <= 7): ?>
                                                <span class="days-badge days-due-soon">
                                                    <i class="fas fa-exclamation-circle"></i> Due in <?php echo $days_diff; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="days-badge days-future">
                                                    <i class="fas fa-calendar"></i> Due in <?php echo $days_diff; ?> days
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $invoice['status']; ?>">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/view.php?id=<?php echo $invoice['id']; ?>"
                                                    class="btn btn-sm btn-primary" title="View Invoice">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?invoice_id=<?php echo $invoice['id']; ?>"
                                                    class="btn btn-sm btn-success" title="Record Payment">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/notifications/send_reminder.php?invoice_id=<?php echo $invoice['id']; ?>"
                                                    class="btn btn-sm btn-warning" title="Send Reminder">
                                                    <i class="fas fa-bell"></i>
                                                </a>
                                                <?php if ($invoice['is_suspended']): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/unsuspend.php?student_id=<?php echo $invoice['student_id']; ?>&class_id=<?php echo $invoice['class_id']; ?>"
                                                        class="btn btn-sm btn-info" title="Unsuspend Student">
                                                        <i class="fas fa-user-check"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/suspend.php?student_id=<?php echo $invoice['student_id']; ?>&class_id=<?php echo $invoice['class_id']; ?>"
                                                        class="btn btn-sm btn-danger" title="Suspend Student">
                                                        <i class="fas fa-user-slash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3>No Outstanding Invoices</h3>
                                        <p>All invoices are paid or there are no outstanding invoices for the selected filters.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($outstanding_data)): ?>
                    <div style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>Total Outstanding: ₦<?php echo number_format($summary_data['total_balance'] ?? 0, 2); ?></strong>
                                <span style="color: #64748b; margin-left: 1rem;">
                                    across <?php echo $summary_data['total_invoices'] ?? 0; ?> invoices
                                </span>
                            </div>
                            <div>
                                <span style="color: #dc2626; margin-right: 1rem;">
                                    <i class="fas fa-exclamation-circle"></i>
                                    ₦<?php echo number_format($summary_data['overdue_amount'] ?? 0, 2); ?> overdue
                                </span>
                                <span style="color: #d97706;">
                                    <i class="fas fa-clock"></i>
                                    ₦<?php echo number_format($summary_data['due_soon_amount'] ?? 0, 2); ?> due soon
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Toggle date range visibility
        document.getElementById('periodSelect').addEventListener('change', function(e) {
            const dateRangeGroup = document.getElementById('dateRangeGroup');
            dateRangeGroup.style.display = e.target.value === 'custom' ? 'block' : 'none';
        });

        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d"
        });

        // Prepare chart data
        <?php
        // Aging chart data
        $agingLabels = [];
        $agingData = [];
        $agingColors = [];

        foreach ($aging_analysis as $item) {
            $agingLabels[] = $item['aging_bucket'];
            $agingData[] = $item['total_balance'];

            // Assign colors based on aging bucket
            if (strpos($item['aging_bucket'], 'Over 90') !== false) {
                $agingColors[] = '#ef4444';
            } elseif (strpos($item['aging_bucket'], '61-90') !== false) {
                $agingColors[] = '#f97316';
            } elseif (strpos($item['aging_bucket'], '31-60') !== false) {
                $agingColors[] = '#f59e0b';
            } elseif (strpos($item['aging_bucket'], '1-30') !== false) {
                $agingColors[] = '#eab308';
            } elseif (strpos($item['aging_bucket'], 'Due in 7') !== false) {
                $agingColors[] = '#3b82f6';
            } else {
                $agingColors[] = '#10b981';
            }
        }

        // Program breakdown data
        $programBreakdownLabels = [];
        $programBreakdownData = [];
        $programBreakdownColors = ['#3b82f6', '#10b981'];

        $onlineTotal = 0;
        $onsiteTotal = 0;

        foreach ($program_breakdown as $program) {
            if ($program['program_type'] === 'online') {
                $onlineTotal += $program['total_balance'];
            } else {
                $onsiteTotal += $program['total_balance'];
            }
        }

        if ($onlineTotal > 0) {
            $programBreakdownLabels[] = 'Online Programs';
            $programBreakdownData[] = $onlineTotal;
        }

        if ($onsiteTotal > 0) {
            $programBreakdownLabels[] = 'Onsite Programs';
            $programBreakdownData[] = $onsiteTotal;
        }
        ?>

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Aging Chart
            const agingCtx = document.getElementById('agingChart');
            if (agingCtx) {
                new Chart(agingCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($agingLabels); ?>,
                        datasets: [{
                            label: 'Outstanding Balance',
                            data: <?php echo json_encode($agingData); ?>,
                            backgroundColor: <?php echo json_encode($agingColors); ?>,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₦' + value.toLocaleString('en-US');
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₦' + context.parsed.y.toLocaleString('en-US', {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        });
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Program Breakdown Chart
            const programBreakdownCtx = document.getElementById('programBreakdownChart');
            if (programBreakdownCtx) {
                new Chart(programBreakdownCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($programBreakdownLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($programBreakdownData); ?>,
                            backgroundColor: <?php echo json_encode($programBreakdownColors); ?>,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += '₦' + context.parsed.toLocaleString('en-US', {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        });
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });

        // Auto-refresh every 5 minutes
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 300000);
    </script>
</body>

</html>

<?php $conn->close(); ?>