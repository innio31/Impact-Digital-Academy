<?php
// modules/admin/finance/reports/expenses.php

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

// Get filter parameters with sanitization
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$category_id = $_GET['category_id'] ?? '';
$category_type = $_GET['category_type'] ?? '';
$status = $_GET['status'] ?? 'paid';
$payment_method = $_GET['payment_method'] ?? '';
$export_type = $_GET['export'] ?? '';

// Debug: Log received parameters
error_log("Expenses Report - Period: $period, Date From: $date_from, Date To: $date_to");

// Set default dates based on period
if ($period === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
} elseif ($period === 'quarter') {
    $date_from = date('Y-m-01', strtotime('-3 months'));
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
} elseif ($period === 'all') {
    // Get earliest expense date from database
    $earliest_sql = "SELECT MIN(payment_date) as earliest_date FROM expenses WHERE status = 'paid'";
    if ($stmt = $conn->prepare($earliest_sql)) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $date_from = $row['earliest_date'] ?: '2024-01-01';
        } else {
            $date_from = '2024-01-01';
        }
        $stmt->close();
    }
}

// Debug: Log calculated dates
error_log("Calculated Date From: $date_from, Date To: $date_to");

// Build WHERE clause for main query
$where_conditions = ["e.payment_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$param_types = 'ss';

if (!empty($category_id)) {
    $where_conditions[] = "e.category_id = ?";
    $params[] = $category_id;
    $param_types .= 'i';
}

if (!empty($category_type)) {
    $where_conditions[] = "ec.category_type = ?";
    $params[] = $category_type;
    $param_types .= 's';
}

if (!empty($status) && $status !== 'all') {
    $where_conditions[] = "e.status = ?";
    $params[] = $status;
    $param_types .= 's';
}

if (!empty($payment_method) && $payment_method !== 'all') {
    $where_conditions[] = "e.payment_method = ?";
    $params[] = $payment_method;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : 'WHERE 1=1';

// Debug: Log WHERE clause and parameters
error_log("WHERE Clause: $where_clause");
error_log("Params: " . json_encode($params));
error_log("Param Types: $param_types");

// Get expenses data - FIXED QUERY (simplified without budget join first)
$expenses_sql = "SELECT 
    ec.id as category_id,
    ec.name as category_name,
    ec.category_type,
    ec.color_code,
    COUNT(e.id) as expense_count,
    SUM(e.amount) as total_amount,
    AVG(e.amount) as avg_amount,
    MIN(e.amount) as min_amount,
    MAX(e.amount) as max_amount
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$where_clause
GROUP BY ec.id, ec.category_type, ec.name, ec.color_code
ORDER BY total_amount DESC";

$expenses_data = [];
try {
    error_log("Main Query: " . $expenses_sql);
    
    if ($stmt = $conn->prepare($expenses_sql)) {
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $expenses_result = $stmt->get_result();
        $expenses_data = $expenses_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Debug: Log results
        error_log("Expenses Data Count: " . count($expenses_data));
        if (!empty($expenses_data)) {
            error_log("Sample Data: " . json_encode($expenses_data[0]));
        }
    } else {
        error_log("Error preparing statement: " . $conn->error);
        echo "Error preparing statement: " . $conn->error;
    }
} catch (Exception $e) {
    error_log("Error executing query: " . $e->getMessage());
    echo "Error executing query: " . $e->getMessage();
}

// Now get budget data separately and merge
if (!empty($expenses_data)) {
    // Get budget data for the current month/period
    $budget_sql = "SELECT 
        eb.category_id,
        eb.budget_amount,
        eb.actual_amount
    FROM expense_budgets eb
    WHERE eb.month BETWEEN ? AND ?";
    
    $budget_params = [$date_from, $date_to];
    
    if ($budget_stmt = $conn->prepare($budget_sql)) {
        $budget_stmt->bind_param('ss', $date_from, $date_to);
        $budget_stmt->execute();
        $budget_result = $budget_stmt->get_result();
        $budgets = $budget_result->fetch_all(MYSQLI_ASSOC);
        $budget_stmt->close();
        
        // Create budget lookup array
        $budget_lookup = [];
        foreach ($budgets as $budget) {
            $budget_lookup[$budget['category_id']] = [
                'budget_amount' => $budget['budget_amount'],
                'actual_spent' => $budget['actual_amount']
            ];
        }
        
        // Merge budget data into expenses data
        foreach ($expenses_data as &$expense) {
            $cat_id = $expense['category_id'];
            if (isset($budget_lookup[$cat_id])) {
                $expense['budget_amount'] = $budget_lookup[$cat_id]['budget_amount'];
                $expense['actual_spent'] = $budget_lookup[$cat_id]['actual_spent'];
            } else {
                $expense['budget_amount'] = 0;
                $expense['actual_spent'] = 0;
            }
        }
        unset($expense); // Break reference
    }
} else {
    // Initialize with empty budget fields
    foreach ($expenses_data as &$expense) {
        $expense['budget_amount'] = 0;
        $expense['actual_spent'] = 0;
    }
    unset($expense);
}

// Get daily expense trend
$daily_where_conditions = ["DATE(e.payment_date) BETWEEN ? AND ?"];
$daily_params = [$date_from, $date_to];
$daily_param_types = 'ss';

if (!empty($category_id)) {
    $daily_where_conditions[] = "e.category_id = ?";
    $daily_params[] = $category_id;
    $daily_param_types .= 'i';
}

if (!empty($category_type)) {
    $daily_where_conditions[] = "ec.category_type = ?";
    $daily_params[] = $category_type;
    $daily_param_types .= 's';
}

if (!empty($status) && $status !== 'all') {
    $daily_where_conditions[] = "e.status = ?";
    $daily_params[] = $status;
    $daily_param_types .= 's';
}

if (!empty($payment_method) && $payment_method !== 'all') {
    $daily_where_conditions[] = "e.payment_method = ?";
    $daily_params[] = $payment_method;
    $daily_param_types .= 's';
}

$daily_where_clause = 'WHERE ' . implode(' AND ', $daily_where_conditions);

$daily_trend_sql = "SELECT 
    DATE(e.payment_date) as date,
    COUNT(e.id) as daily_expenses,
    SUM(e.amount) as daily_amount,
    AVG(e.amount) as avg_daily_amount
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$daily_where_clause
GROUP BY DATE(e.payment_date)
ORDER BY date ASC";

$daily_trend = [];
try {
    if ($daily_stmt = $conn->prepare($daily_trend_sql)) {
        $daily_stmt->bind_param($daily_param_types, ...$daily_params);
        $daily_stmt->execute();
        $daily_result = $daily_stmt->get_result();
        $daily_trend = $daily_result->fetch_all(MYSQLI_ASSOC);
        $daily_stmt->close();
        
        // Debug: Log daily trend
        error_log("Daily Trend Count: " . count($daily_trend));
    }
} catch (Exception $e) {
    error_log("Error executing daily trend query: " . $e->getMessage());
}

// Get expense categories for filter dropdown
$categories_sql = "SELECT id, name, category_type FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories = [];
if ($cat_stmt = $conn->prepare($categories_sql)) {
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    $categories = $cat_result->fetch_all(MYSQLI_ASSOC);
    $cat_stmt->close();
}

// Get payment method breakdown - Use main WHERE conditions
$payment_method_sql = "SELECT 
    COALESCE(e.payment_method, 'unknown') as payment_method,
    COUNT(*) as count,
    SUM(e.amount) as total
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$where_clause
GROUP BY e.payment_method
ORDER BY total DESC";

$payment_methods = [];
if ($payment_stmt = $conn->prepare($payment_method_sql)) {
    if (!empty($params)) {
        $payment_stmt->bind_param($param_types, ...$params);
    }
    $payment_stmt->execute();
    $payment_result = $payment_stmt->get_result();
    $payment_methods = $payment_result->fetch_all(MYSQLI_ASSOC);
    $payment_stmt->close();
}

// Get status breakdown
$status_sql = "SELECT 
    e.status,
    COUNT(*) as count,
    SUM(e.amount) as total
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$where_clause
GROUP BY e.status
ORDER BY total DESC";

$status_breakdown = [];
if ($status_stmt = $conn->prepare($status_sql)) {
    if (!empty($params)) {
        $status_stmt->bind_param($param_types, ...$params);
    }
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
    $status_breakdown = $status_result->fetch_all(MYSQLI_ASSOC);
    $status_stmt->close();
}

// Get category type breakdown
$category_type_sql = "SELECT 
    ec.category_type,
    COUNT(*) as count,
    SUM(e.amount) as total
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$where_clause
GROUP BY ec.category_type
ORDER BY total DESC";

$category_types = [];
if ($type_stmt = $conn->prepare($category_type_sql)) {
    if (!empty($params)) {
        $type_stmt->bind_param($param_types, ...$params);
    }
    $type_stmt->execute();
    $type_result = $type_stmt->get_result();
    $category_types = $type_result->fetch_all(MYSQLI_ASSOC);
    $type_stmt->close();
}

// Calculate totals
$total_expenses = 0;
$total_expense_count = 0;
$total_budget = 0;
foreach ($expenses_data as $row) {
    $total_expenses += $row['total_amount'];
    $total_expense_count += $row['expense_count'];
    $total_budget += $row['budget_amount'];
}

// Calculate variance
$variance = $total_budget - $total_expenses;
$variance_percentage = $total_budget > 0 ? ($variance / $total_budget * 100) : 0;

// Get top expenses (individual items)
$top_expenses_sql = "SELECT 
    e.id,
    e.expense_number,
    e.description,
    ec.name as category_name,
    ec.category_type,
    e.amount,
    e.payment_date,
    e.vendor_name,
    e.status,
    e.payment_method,
    e.currency
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
$where_clause
ORDER BY e.amount DESC
LIMIT 20";

$top_expenses = [];
if ($top_stmt = $conn->prepare($top_expenses_sql)) {
    if (!empty($params)) {
        $top_stmt->bind_param($param_types, ...$params);
    }
    $top_stmt->execute();
    $top_result = $top_stmt->get_result();
    $top_expenses = $top_result->fetch_all(MYSQLI_ASSOC);
    $top_stmt->close();
}

// Debug: Log summary stats
error_log("Total Expenses: $total_expenses, Count: $total_expense_count, Budget: $total_budget");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Report - Finance Dashboard</title>
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
            color: var(--danger);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
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

        .btn-danger {
            background: var(--danger);
            color: white;
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
        }

        .summary-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .summary-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Charts Container */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
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

        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-operational {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-fixed {
            background: #dcfce7;
            color: #166534;
        }

        .badge-variable {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-tithe {
            background: #e0e7ff;
            color: #3730a3;
        }

        .badge-reserve {
            background: #fce7f3;
            color: #9d174d;
        }

        .badge-other {
            background: #f1f5f9;
            color: #64748b;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #dbeafe;
            color: #1e40af;
        }

        .variance-positive {
            color: var(--success);
        }

        .variance-negative {
            color: var(--danger);
        }

        /* Budget Bar */
        .budget-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .budget-used {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
        }

        .budget-over {
            height: 100%;
            background: var(--danger);
            border-radius: 4px;
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
            border-left: 4px solid var(--danger);
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/expenses.php" class="active">
                            <i class="fas fa-chart-line"></i> Expense Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/profit-loss.php">
                            <i class="fas fa-balance-scale"></i> Profit & Loss</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

                    <div class="nav-section">Expense Management</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/">
                            <i class="fas fa-money-bill-wave"></i> Manage Expenses</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php">
                            <i class="fas fa-tags"></i> Expense Categories</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php">
                            <i class="fas fa-chart-pie"></i> Budget Management</a></li>

                    <div class="nav-section">Back to Finance</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-chart-line"></i>
                    Expenses Report
                </h1>
                <p style="color: #64748b; margin-top: 0.5rem;">
                    Analyze expense trends and track budget performance
                    <?php if (!empty($category_type)): ?>
                        <br><small>Filtered by: <?php echo ucfirst($category_type); ?> expenses</small>
                    <?php endif; ?>
                </p>
                <div class="page-actions">
                    <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&category_id=<?php echo $category_id; ?>&category_type=<?php echo $category_type; ?>&status=<?php echo $status; ?>&payment_method=<?php echo $payment_method; ?>"
                        class="btn btn-success">
                        <i class="fas fa-file-export"></i> Export to CSV
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
                            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>Last Quarter</option>
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
                        <label>Category Type</label>
                        <select name="category_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="operational" <?php echo $category_type === 'operational' ? 'selected' : ''; ?>>Operational</option>
                            <option value="fixed" <?php echo $category_type === 'fixed' ? 'selected' : ''; ?>>Fixed</option>
                            <option value="variable" <?php echo $category_type === 'variable' ? 'selected' : ''; ?>>Variable</option>
                            <option value="tithe" <?php echo $category_type === 'tithe' ? 'selected' : ''; ?>>Tithe</option>
                            <option value="reserve" <?php echo $category_type === 'reserve' ? 'selected' : ''; ?>>Reserve</option>
                            <option value="other" <?php echo $category_type === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?> (<?php echo $category['category_type']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="all">All Methods</option>
                            <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="cheque" <?php echo $payment_method === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="pos" <?php echo $payment_method === 'pos' ? 'selected' : ''; ?>>POS</option>
                            <option value="mobile_money" <?php echo $payment_method === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all">All Statuses</option>
                            <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                    <div class="summary-value <?php echo $total_expenses > 0 ? 'variance-negative' : ''; ?>">
                        ₦<?php echo number_format($total_expenses, 2); ?>
                    </div>
                    <div class="summary-label">Total Expenses</div>
                </div>

                <div class="summary-card">
                    <div class="summary-value">
                        <?php echo number_format($total_expense_count); ?>
                    </div>
                    <div class="summary-label">Total Expenses Count</div>
                </div>

                <div class="summary-card">
                    <div class="summary-value">
                        ₦<?php echo number_format($total_budget, 2); ?>
                    </div>
                    <div class="summary-label">Total Budget</div>
                </div>

                <div class="summary-card">
                    <div class="summary-value <?php echo $variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                        ₦<?php echo number_format($variance, 2); ?>
                        <small style="font-size: 0.8rem;">
                            (<?php echo number_format($variance_percentage, 1); ?>%)
                        </small>
                    </div>
                    <div class="summary-label">Budget Variance</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Expense by Category Type -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Expenses by Category Type</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="categoryTypeChart"></canvas>
                    </div>
                </div>

                <!-- Daily Expense Trend -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Daily Expense Trend</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Payment Method Distribution -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-credit-card"></i> Payment Method Distribution</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentMethodChart"></canvas>
                    </div>
                </div>

                <!-- Budget vs Actual -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-balance-scale"></i> Budget vs Actual</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="budgetChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Detailed Expenses by Category -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-table"></i> Detailed Expenses by Category</h3>
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?>
                        <?php if ($status !== 'all'): ?>
                            | Status: <?php echo ucfirst($status); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Expenses</th>
                                <th>Total Amount</th>
                                <th>Average</th>
                                <th>Budget</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($expenses_data)): ?>
                                <?php foreach ($expenses_data as $row):
                                    $budget = $row['budget_amount'];
                                    $actual = $row['total_amount'];
                                    $category_variance = $budget - $actual;
                                    $utilization = $budget > 0 ? ($actual / $budget * 100) : ($actual > 0 ? 100 : 0);
                                    $type_class = 'badge-' . strtolower($row['category_type']);
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($row['category_name']); ?></div>
                                            <small style="color: #64748b; font-size: 0.8rem;">
                                                <?php if (!empty($row['color_code'])): ?>
                                                    <span style="display: inline-block; width: 12px; height: 12px; background: <?php echo $row['color_code']; ?>; border-radius: 50%; margin-right: 4px;"></span>
                                                <?php endif; ?>
                                                ID: <?php echo $row['category_id']; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="category-badge <?php echo $type_class; ?>">
                                                <?php echo $row['category_type']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $row['expense_count']; ?></td>
                                        <td class="amount">₦<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td>₦<?php echo number_format($row['avg_amount'] ?? 0, 2); ?></td>
                                        <td>₦<?php echo number_format($budget, 2); ?></td>
                                        <td>₦<?php echo number_format($actual, 2); ?></td>
                                        <td>
                                            <span class="<?php echo $category_variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                                ₦<?php echo number_format($category_variance, 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="flex: 1;">
                                                    <div class="budget-bar">
                                                        <div class="<?php echo $utilization > 100 ? 'budget-over' : 'budget-used'; ?>" 
                                                             style="width: <?php echo min($utilization, 100); ?>%"></div>
                                                    </div>
                                                </div>
                                                <span style="font-size: 0.85rem; font-weight: 500; color: <?php echo $utilization > 100 ? 'var(--danger)' : 'var(--dark)'; ?>">
                                                    <?php echo number_format($utilization, 1); ?>%
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3>No Expense Data</h3>
                                        <p>No expenses found for the selected filters.</p>
                                        <?php if (!empty($category_type) || !empty($category_id) || !empty($status) || !empty($payment_method)): ?>
                                            <p style="margin-top: 1rem;">
                                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-primary">
                                                    <i class="fas fa-filter"></i> Clear Filters
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($expenses_data)): ?>
                    <div style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc; text-align: center;">
                        <strong>Total Expenses: ₦<?php echo number_format($total_expenses, 2); ?></strong>
                        <span style="color: #64748b; margin-left: 1rem;">
                            from <?php echo number_format($total_expense_count); ?> expense items
                            across <?php echo count($expenses_data); ?> categories
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Expenses Table -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-ol"></i> Top 20 Expenses</h3>
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Sorted by highest amount
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Expense #</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Vendor</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_expenses)): ?>
                                <?php foreach ($top_expenses as $expense):
                                    $type_class = 'badge-' . strtolower($expense['category_type']);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($expense['expense_number']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($expense['description']); ?></div>
                                            <small style="color: #64748b; font-size: 0.8rem;">
                                                <?php echo date('M j, Y', strtotime($expense['payment_date'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="category-badge <?php echo $type_class; ?>" style="font-size: 0.7rem;">
                                                <?php echo htmlspecialchars($expense['category_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($expense['vendor_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($expense['payment_date'])); ?></td>
                                        <td>
                                            <span style="font-size: 0.8rem;">
                                                <?php echo ucfirst(str_replace('_', ' ', $expense['payment_method'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $expense['status']; ?>">
                                                <?php echo ucfirst($expense['status']); ?>
                                            </span>
                                        </td>
                                        <td class="amount">₦<?php echo number_format($expense['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 2rem; color: #64748b;">
                                        No expense items found for the selected filters.
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

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Category Type Chart
            const categoryTypeCtx = document.getElementById('categoryTypeChart');
            if (categoryTypeCtx && <?php echo !empty($categoryTypeData) ? 'true' : 'false'; ?>) {
                new Chart(categoryTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: <?php echo json_encode($categoryTypeLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($categoryTypeData); ?>,
                            backgroundColor: <?php echo json_encode($categoryTypeColors); ?>,
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
            } else if (categoryTypeCtx) {
                categoryTypeCtx.parentElement.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b;"><i class="fas fa-chart-pie" style="font-size: 2rem; margin-right: 0.5rem;"></i> No data available</div>';
            }

            // Daily Trend Chart
            const dailyTrendCtx = document.getElementById('dailyTrendChart');
            if (dailyTrendCtx && <?php echo !empty($dailyData) ? 'true' : 'false'; ?>) {
                new Chart(dailyTrendCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($dailyLabels); ?>,
                        datasets: [{
                            label: 'Daily Expenses',
                            data: <?php echo json_encode($dailyData); ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
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
            } else if (dailyTrendCtx) {
                dailyTrendCtx.parentElement.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b;"><i class="fas fa-chart-line" style="font-size: 2rem; margin-right: 0.5rem;"></i> No data available</div>';
            }

            // Payment Method Chart
            const paymentMethodCtx = document.getElementById('paymentMethodChart');
            if (paymentMethodCtx && <?php echo !empty($paymentData) ? 'true' : 'false'; ?>) {
                new Chart(paymentMethodCtx, {
                    type: 'doughnut',
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
            } else if (paymentMethodCtx) {
                paymentMethodCtx.parentElement.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b;"><i class="fas fa-credit-card" style="font-size: 2rem; margin-right: 0.5rem;"></i> No data available</div>';
            }

            // Budget vs Actual Chart
            const budgetCtx = document.getElementById('budgetChart');
            if (budgetCtx && <?php echo !empty($budgetData) ? 'true' : 'false'; ?>) {
                new Chart(budgetCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($budgetLabels); ?>,
                        datasets: [
                            {
                                label: 'Budget',
                                data: <?php echo json_encode($budgetData); ?>,
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderColor: '#3b82f6',
                                borderWidth: 1
                            },
                            {
                                label: 'Actual',
                                data: <?php echo json_encode($actualData); ?>,
                                backgroundColor: 'rgba(239, 68, 68, 0.7)',
                                borderColor: '#ef4444',
                                borderWidth: 1
                            }
                        ]
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
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += '₦' + context.parsed.y.toLocaleString('en-US', {
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
            } else if (budgetCtx) {
                budgetCtx.parentElement.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b;"><i class="fas fa-balance-scale" style="font-size: 2rem; margin-right: 0.5rem;"></i> No data available</div>';
            }
        });

        // Auto-refresh report every 5 minutes
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 300000);
    </script>
</body>
</html>

<?php $conn->close(); ?>