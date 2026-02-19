<?php
// modules/admin/finance/reports/collection.php

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
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$program_type = $_GET['program_type'] ?? '';
$export_type = $_GET['export'] ?? '';

// Set default dates based on period
$periods = [
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'week' => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
    'month' => [date('Y-m-01'), date('Y-m-d')],
    'quarter' => [date('Y-m-d', strtotime('-3 months')), date('Y-m-d')],
    'year' => [date('Y-01-01'), date('Y-m-d')],
    'all' => ['2024-01-01', date('Y-m-d')],
    'custom' => [$date_from, $date_to]
];

if (isset($periods[$period])) {
    $date_from = $periods[$period][0];
    $date_to = $periods[$period][1];
}

// Build WHERE clause
$where_conditions = ["ft.created_at BETWEEN ? AND ? AND ft.status = 'completed'"];
$params = [$date_from, $date_to . ' 23:59:59'];
$param_types = 'ss';

if (!empty($program_type)) {
    $where_conditions[] = "p.program_type = ?";
    $params[] = $program_type;
    $param_types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get collection efficiency data
$efficiency_sql = "SELECT 
    DATE_FORMAT(ft.created_at, '%Y-%m') as month,
    COUNT(DISTINCT ft.student_id) as active_students,
    COUNT(ft.id) as transactions,
    SUM(ft.amount) as collected_amount,
    AVG(ft.amount) as avg_collection,
    MAX(ft.amount) as max_collection,
    MIN(ft.amount) as min_collection
FROM financial_transactions ft
JOIN class_batches cb ON cb.id = ft.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
$where_clause
GROUP BY DATE_FORMAT(ft.created_at, '%Y-%m')
ORDER BY month DESC
LIMIT 12";

$efficiency_data = [];
if ($stmt = $conn->prepare($efficiency_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $efficiency_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get payment method efficiency
$payment_efficiency_sql = "SELECT 
    ft.payment_method,
    COUNT(ft.id) as transactions,
    SUM(ft.amount) as total_amount,
    AVG(ft.amount) as avg_amount,
    COUNT(DISTINCT ft.student_id) as unique_payers,
    MIN(ft.created_at) as first_use,
    MAX(ft.created_at) as last_use
FROM financial_transactions ft
JOIN class_batches cb ON cb.id = ft.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
$where_clause
GROUP BY ft.payment_method
ORDER BY total_amount DESC";

$payment_efficiency = [];
if ($stmt = $conn->prepare($payment_efficiency_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment_efficiency = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get collection rate (collected vs invoiced)
$collection_rate_sql = "SELECT 
    p.program_type,
    p.name as program_name,
    p.program_code,
    COUNT(DISTINCT i.id) as invoices_issued,
    SUM(i.amount) as total_invoiced,
    COUNT(DISTINCT ft.id) as payments_received,
    SUM(ft.amount) as total_collected,
    ROUND((SUM(ft.amount) / NULLIF(SUM(i.amount), 0)) * 100, 2) as collection_rate,
    AVG(DATEDIFF(ft.created_at, i.created_at)) as avg_days_to_pay
FROM invoices i
LEFT JOIN financial_transactions ft ON ft.invoice_id = i.id AND ft.status = 'completed'
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
WHERE i.created_at BETWEEN ? AND ?
" . (!empty($program_type) ? " AND p.program_type = ?" : "") . "
GROUP BY p.id
ORDER BY collection_rate DESC";

$collection_rate_params = [$date_from, $date_to];
$collection_rate_types = 'ss';
if (!empty($program_type)) {
    $collection_rate_params[] = $program_type;
    $collection_rate_types .= 's';
}

$collection_rates = [];
if ($stmt = $conn->prepare($collection_rate_sql)) {
    $stmt->bind_param($collection_rate_types, ...$collection_rate_params);
    $stmt->execute();
    $result = $stmt->get_result();
    $collection_rates = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get top collectors (students who pay promptly)
$top_collectors_sql = "SELECT 
    u.id,
    u.first_name,
    u.last_name,
    u.email,
    COUNT(ft.id) as payments_made,
    SUM(ft.amount) as total_paid,
    AVG(ft.amount) as avg_payment,
    MIN(ft.created_at) as first_payment,
    MAX(ft.created_at) as last_payment,
    AVG(DATEDIFF(ft.created_at, i.due_date)) as avg_days_early
FROM financial_transactions ft
JOIN invoices i ON i.id = ft.invoice_id
JOIN users u ON u.id = ft.student_id
JOIN class_batches cb ON cb.id = ft.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
WHERE ft.status = 'completed' AND ft.created_at BETWEEN ? AND ?
" . (!empty($program_type) ? " AND p.program_type = ?" : "") . "
GROUP BY u.id
HAVING COUNT(ft.id) >= 2
ORDER BY avg_days_early ASC
LIMIT 10";

$top_collectors = [];
if ($stmt = $conn->prepare($top_collectors_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_collectors = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get late payers
$late_payers_sql = "SELECT 
    u.id,
    u.first_name,
    u.last_name,
    u.email,
    COUNT(i.id) as overdue_invoices,
    SUM(i.amount - i.paid_amount) as total_overdue,
    AVG(DATEDIFF(CURDATE(), i.due_date)) as avg_days_late,
    MAX(DATEDIFF(CURDATE(), i.due_date)) as max_days_late,
    sfs.is_suspended
FROM invoices i
JOIN users u ON u.id = i.student_id
JOIN class_batches cb ON cb.id = i.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
LEFT JOIN student_financial_status sfs ON sfs.student_id = i.student_id AND sfs.class_id = i.class_id
WHERE i.status = 'overdue' AND i.due_date BETWEEN ? AND ?
" . (!empty($program_type) ? " AND p.program_type = ?" : "") . "
GROUP BY u.id
HAVING total_overdue > 0
ORDER BY total_overdue DESC
LIMIT 10";

$late_payers = [];
if ($stmt = $conn->prepare($late_payers_sql)) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $late_payers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Calculate summary statistics
$summary_sql = "SELECT 
    COUNT(DISTINCT ft.student_id) as unique_payers,
    COUNT(ft.id) as total_transactions,
    SUM(ft.amount) as total_collected,
    AVG(ft.amount) as avg_transaction,
    MIN(ft.amount) as min_transaction,
    MAX(ft.amount) as max_transaction,
    COUNT(DISTINCT DATE(ft.created_at)) as collection_days,
    SUM(ft.amount) / COUNT(DISTINCT DATE(ft.created_at)) as daily_avg
FROM financial_transactions ft
JOIN class_batches cb ON cb.id = ft.class_id
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

// Export to CSV if requested
if ($export_type === 'csv') {
    $export_filename = "collection_analysis_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export_filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, [
        'Month',
        'Active Students',
        'Transactions',
        'Collected Amount',
        'Average Collection',
        'Max Collection',
        'Min Collection'
    ]);

    // Write data
    foreach ($efficiency_data as $row) {
        fputcsv($output, [
            $row['month'],
            $row['active_students'],
            $row['transactions'],
            $row['collected_amount'],
            $row['avg_collection'],
            $row['max_collection'],
            $row['min_collection']
        ]);
    }

    fclose($output);
    exit();
}

// Log activity
logActivity($_SESSION['user_id'], 'collection_report_view', "Viewed collection analysis report");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Analysis Report - Finance Dashboard</title>
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

        .summary-card.success {
            border-top-color: var(--success);
        }

        .summary-card.warning {
            border-top-color: var(--warning);
        }

        .summary-card.info {
            border-top-color: var(--info);
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
            min-width: 800px;
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

        .status-good {
            background: #d1fae5;
            color: #065f46;
        }

        .status-average {
            background: #fef3c7;
            color: #92400e;
        }

        .status-poor {
            background: #fee2e2;
            color: #991b1b;
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

        .rate-indicator {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .rate-excellent {
            background: #d1fae5;
            color: #065f46;
        }

        .rate-good {
            background: #bbf7d0;
            color: #15803d;
        }

        .rate-average {
            background: #fef3c7;
            color: #92400e;
        }

        .rate-poor {
            background: #fed7aa;
            color: #c2410c;
        }

        .rate-bad {
            background: #fee2e2;
            color: #991b1b;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php" class="active">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

                    <div class="nav-section">Back to Finance</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/">
                            <i class="fas fa-cog"></i> Finance Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-chart-pie"></i>
                    Collection Analysis Report
                </h1>
                <p style="color: #64748b; margin-top: 0.5rem;">
                    Analyze payment collection efficiency and identify trends
                </p>
                <div class="page-actions">
                    <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&program_type=<?php echo $program_type; ?>"
                        class="btn btn-success">
                        <i class="fas fa-file-export"></i> Export to CSV
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php"
                        class="btn btn-warning">
                        <i class="fas fa-exclamation-triangle"></i> View Outstanding
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
                    <div class="summary-value">₦<?php echo number_format($summary_data['total_collected'] ?? 0, 2); ?></div>
                    <div class="summary-label">Total Collected</div>
                    <div class="summary-subtext"><?php echo $summary_data['collection_days'] ?? 0; ?> collection days</div>
                </div>

                <div class="summary-card success">
                    <div class="summary-value"><?php echo $summary_data['unique_payers'] ?? 0; ?></div>
                    <div class="summary-label">Unique Payers</div>
                    <div class="summary-subtext"><?php echo $summary_data['total_transactions'] ?? 0; ?> transactions</div>
                </div>

                <div class="summary-card info">
                    <div class="summary-value">₦<?php echo number_format($summary_data['daily_avg'] ?? 0, 2); ?></div>
                    <div class="summary-label">Daily Average</div>
                    <div class="summary-subtext">Collection per day</div>
                </div>

                <div class="summary-card warning">
                    <div class="summary-value">₦<?php echo number_format($summary_data['avg_transaction'] ?? 0, 2); ?></div>
                    <div class="summary-label">Avg Transaction</div>
                    <div class="summary-subtext">Per payment</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Monthly Collection Trend -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Monthly Collection Trend</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Payment Method Efficiency -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-credit-card"></i> Payment Method Distribution</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentMethodChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Collection Rates by Program -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-percentage"></i> Collection Rates by Program</h3>
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Shows collected amount vs invoiced amount
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Type</th>
                                <th>Invoices</th>
                                <th>Invoiced Amount</th>
                                <th>Payments</th>
                                <th>Collected Amount</th>
                                <th>Collection Rate</th>
                                <th>Avg Days to Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($collection_rates)): ?>
                                <?php foreach ($collection_rates as $program):
                                    $rate_class = '';
                                    if ($program['collection_rate'] >= 90) {
                                        $rate_class = 'rate-excellent';
                                    } elseif ($program['collection_rate'] >= 75) {
                                        $rate_class = 'rate-good';
                                    } elseif ($program['collection_rate'] >= 50) {
                                        $rate_class = 'rate-average';
                                    } elseif ($program['collection_rate'] >= 25) {
                                        $rate_class = 'rate-poor';
                                    } else {
                                        $rate_class = 'rate-bad';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($program['program_name']); ?></div>
                                            <small style="color: #64748b;"><?php echo $program['program_code']; ?></small>
                                        </td>
                                        <td>
                                            <span class="program-badge badge-<?php echo $program['program_type']; ?>">
                                                <?php echo $program['program_type']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $program['invoices_issued']; ?></td>
                                        <td class="amount">₦<?php echo number_format($program['total_invoiced'], 2); ?></td>
                                        <td><?php echo $program['payments_received']; ?></td>
                                        <td class="amount">₦<?php echo number_format($program['total_collected'], 2); ?></td>
                                        <td>
                                            <span class="rate-indicator <?php echo $rate_class; ?>">
                                                <?php echo number_format($program['collection_rate'], 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($program['avg_days_to_pay'] > 0): ?>
                                                <span style="color: #dc2626;">
                                                    <i class="fas fa-clock"></i> +<?php echo number_format($program['avg_days_to_pay'], 1); ?> days
                                                </span>
                                            <?php elseif ($program['avg_days_to_pay'] < 0): ?>
                                                <span style="color: #059669;">
                                                    <i class="fas fa-check-circle"></i> <?php echo number_format(abs($program['avg_days_to_pay']), 1); ?> days early
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #64748b;">On time</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fas fa-percentage" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3>No Collection Data</h3>
                                        <p>No collection data found for the selected filters.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Collectors & Late Payers -->
            <div class="charts-grid">
                <!-- Top Collectors -->
                <div class="data-table-card">
                    <div class="card-header">
                        <h3><i class="fas fa-trophy"></i> Top Collectors (Prompt Payers)</h3>
                        <div style="color: #64748b; font-size: 0.9rem;">
                            Students who pay early or on time
                        </div>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Payments</th>
                                    <th>Total Paid</th>
                                    <th>Avg Payment</th>
                                    <th>Avg Days Early</th>
                                    <th>Payment Period</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($top_collectors)): ?>
                                    <?php foreach ($top_collectors as $student): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                <small style="color: #64748b;"><?php echo $student['email']; ?></small>
                                            </td>
                                            <td><?php echo $student['payments_made']; ?></td>
                                            <td class="amount">₦<?php echo number_format($student['total_paid'], 2); ?></td>
                                            <td class="amount">₦<?php echo number_format($student['avg_payment'], 2); ?></td>
                                            <td>
                                                <span class="status-badge status-good">
                                                    <?php echo number_format(abs($student['avg_days_early']), 1); ?> days early
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($student['first_payment'])); ?> -
                                                <?php echo date('M j, Y', strtotime($student['last_payment'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 3rem; color: #64748b;">
                                            <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                            <h3>No Top Collectors</h3>
                                            <p>No prompt payers found for the selected filters.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Late Payers -->
                <div class="data-table-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Late Payers</h3>
                        <div style="color: #64748b; font-size: 0.9rem;">
                            Students with overdue payments
                        </div>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Overdue Invoices</th>
                                    <th>Total Overdue</th>
                                    <th>Avg Days Late</th>
                                    <th>Max Days Late</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($late_payers)): ?>
                                    <?php foreach ($late_payers as $student): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                <small style="color: #64748b;"><?php echo $student['email']; ?></small>
                                            </td>
                                            <td><?php echo $student['overdue_invoices']; ?></td>
                                            <td class="amount">₦<?php echo number_format($student['total_overdue'], 2); ?></td>
                                            <td>
                                                <span class="status-badge status-poor">
                                                    <?php echo number_format($student['avg_days_late'], 1); ?> days
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-poor">
                                                    <?php echo $student['max_days_late']; ?> days max
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($student['is_suspended']): ?>
                                                    <span class="status-badge" style="background: #f1f5f9; color: #64748b;">
                                                        <i class="fas fa-user-slash"></i> Suspended
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-poor">
                                                        Active (Overdue)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 3rem; color: #64748b;">
                                            <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                            <h3>No Late Payers</h3>
                                            <p>No late payers found for the selected filters.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Payment Method Efficiency -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Payment Method Efficiency</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment Method</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Average Amount</th>
                                <th>Unique Payers</th>
                                <th>First Used</th>
                                <th>Last Used</th>
                                <th>Popularity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payment_efficiency)):
                                $total_transactions = array_sum(array_column($payment_efficiency, 'transactions'));
                                $total_amount = array_sum(array_column($payment_efficiency, 'total_amount'));
                            ?>
                                <?php foreach ($payment_efficiency as $method):
                                    $popularity = $total_transactions > 0 ? ($method['transactions'] / $total_transactions * 100) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo ucfirst($method['payment_method']); ?></strong>
                                        </td>
                                        <td><?php echo $method['transactions']; ?></td>
                                        <td class="amount">₦<?php echo number_format($method['total_amount'], 2); ?></td>
                                        <td class="amount">₦<?php echo number_format($method['avg_amount'], 2); ?></td>
                                        <td><?php echo $method['unique_payers']; ?></td>
                                        <td>
                                            <?php echo $method['first_use'] ? date('M j, Y', strtotime($method['first_use'])) : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <?php echo $method['last_use'] ? date('M j, Y', strtotime($method['last_use'])) : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?php echo $popularity; ?>%; height: 100%; background: var(--primary);"></div>
                                                </div>
                                                <span style="font-size: 0.85rem;"><?php echo number_format($popularity, 1); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fas fa-credit-card" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3>No Payment Method Data</h3>
                                        <p>No payment method efficiency data found.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
        // Monthly trend data
        $monthlyLabels = [];
        $monthlyData = [];
        $monthlyTransactions = [];

        foreach ($efficiency_data as $month) {
            $monthlyLabels[] = date('M Y', strtotime($month['month'] . '-01'));
            $monthlyData[] = $month['collected_amount'];
            $monthlyTransactions[] = $month['transactions'];
        }

        // Payment method data
        $paymentLabels = [];
        $paymentData = [];
        $paymentColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];

        foreach ($payment_efficiency as $index => $method) {
            $paymentLabels[] = ucfirst($method['payment_method']);
            $paymentData[] = $method['total_amount'];
        }
        ?>

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Trend Chart
            const monthlyTrendCtx = document.getElementById('monthlyTrendChart');
            if (monthlyTrendCtx) {
                new Chart(monthlyTrendCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($monthlyLabels); ?>,
                        datasets: [{
                                label: 'Collected Amount',
                                data: <?php echo json_encode($monthlyData); ?>,
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderColor: '#3b82f6',
                                borderWidth: 1,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Transactions',
                                data: <?php echo json_encode($monthlyTransactions); ?>,
                                backgroundColor: 'rgba(16, 185, 129, 0.3)',
                                borderColor: '#10b981',
                                borderWidth: 1,
                                type: 'line',
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Amount (₦)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return '₦' + value.toLocaleString('en-US');
                                    }
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Transactions'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (context.dataset.label === 'Collected Amount') {
                                            return '₦' + context.parsed.y.toLocaleString('en-US', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2
                                            });
                                        } else {
                                            return context.parsed.y + ' transactions';
                                        }
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Payment Method Chart
            const paymentMethodCtx = document.getElementById('paymentMethodChart');
            if (paymentMethodCtx) {
                new Chart(paymentMethodCtx, {
                    type: 'pie',
                    data: {
                        labels: <?php echo json_encode($paymentLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($paymentData); ?>,
                            backgroundColor: <?php echo json_encode($paymentColors); ?>,
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