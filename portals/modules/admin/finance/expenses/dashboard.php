<?php
// modules/admin/finance/expenses/dashboard.php

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
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$status = $_GET['status'] ?? '';

// Validate period
$valid_periods = ['today', 'week', 'month', 'year', 'custom'];
if (!in_array($period, $valid_periods)) {
    $period = 'month';
}

// Set date range based on period
if ($period === 'today') {
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
    $date_to = date('Y-m-d');
} elseif ($period === 'month') {
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = date('Y-m-d');
} elseif ($period === 'year') {
    $date_from = date('Y-m-d', strtotime('-365 days'));
    $date_to = date('Y-m-d');
}

// Use custom dates if provided
if ($period === 'custom' && $date_from && $date_to) {
    if ($date_from > $date_to) {
        $temp = $date_from;
        $date_from = $date_to;
        $date_to = $temp;
    }
}

// Handle automated deductions calculation
if (isset($_GET['calculate_deductions']) && $_GET['calculate_deductions'] == 'true') {
    $created_expenses = calculateAutomatedDeductions($period, $date_from, $date_to);
    $calculation_message = count($created_expenses) > 0 ? 
        "Automated deductions calculated successfully!" : 
        "No new deductions to calculate.";
}

// Get expense statistics
$stats = getExpenseDashboardStats($period, $date_from, $date_to);

// Get expense categories for dropdown
$categories_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get recent expenses
$expenses_sql = "SELECT e.*, ec.name as category_name, ec.category_type, ec.color_code,
                        u.first_name as created_by_name, u.last_name as created_by_lastname,
                        a.first_name as approved_by_name, a.last_name as approved_by_lastname,
                        p.first_name as paid_by_name, p.last_name as paid_by_lastname
                 FROM expenses e
                 JOIN expense_categories ec ON ec.id = e.category_id
                 JOIN users u ON u.id = e.created_by
                 LEFT JOIN users a ON a.id = e.approved_by
                 LEFT JOIN users p ON p.id = e.paid_by
                 WHERE 1=1";

if ($category_id) {
    $expenses_sql .= " AND e.category_id = ?";
}

if ($status) {
    $expenses_sql .= " AND e.status = ?";
}

if ($period === 'today') {
    $expenses_sql .= " AND DATE(e.payment_date) = CURDATE()";
} elseif ($period === 'week') {
    $expenses_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($period === 'month') {
    $expenses_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($period === 'year') {
    $expenses_sql .= " AND e.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
} elseif ($period === 'custom' && $date_from && $date_to) {
    $expenses_sql .= " AND e.payment_date BETWEEN ? AND ?";
}

$expenses_sql .= " ORDER BY e.payment_date DESC, e.created_at DESC LIMIT 20";

$expenses_stmt = $conn->prepare($expenses_sql);
$param_types = '';
$params = [];

if ($category_id) {
    $param_types .= 'i';
    $params[] = $category_id;
}

if ($status) {
    $param_types .= 's';
    $params[] = $status;
}

if ($period === 'custom' && $date_from && $date_to) {
    $param_types .= 'ss';
    $params[] = $date_from;
    $params[] = $date_to;
}

if (!empty($params)) {
    $expenses_stmt->bind_param($param_types, ...$params);
}

$expenses_stmt->execute();
$expenses_result = $expenses_stmt->get_result();
$recent_expenses = $expenses_result->fetch_all(MYSQLI_ASSOC);

// Get monthly expense trend
$trend_sql = "SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month,
                SUM(amount) as total_expenses,
                COUNT(*) as expense_count
              FROM expenses
              WHERE status IN ('approved', 'paid')
                AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
              ORDER BY month ASC";
$trend_result = $conn->query($trend_sql);
$monthly_trend = $trend_result->fetch_all(MYSQLI_ASSOC);

// Get automated deductions summary
$deductions_sql = "SELECT 
                    ad.deduction_type,
                    ad.percentage,
                    ad.total_deducted,
                    ad.last_calculated_date,
                    SUM(CASE WHEN e.status IN ('approved', 'paid') THEN e.amount ELSE 0 END) as actual_deducted
                   FROM automated_deductions ad
                   LEFT JOIN expenses e ON e.description LIKE CONCAT('%', ad.deduction_type, '%')
                   WHERE ad.is_active = 1
                   GROUP BY ad.id";
$deductions_result = $conn->query($deductions_sql);
$automated_deductions = $deductions_result->fetch_all(MYSQLI_ASSOC);

// Get pending expenses for alerts
$pending_sql = "SELECT COUNT(*) as count, SUM(amount) as total 
                FROM expenses WHERE status = 'pending'";
$pending_result = $conn->query($pending_sql);
$pending_data = $pending_result->fetch_assoc();

// Get revenue for comparison
// Get combined revenue from all sources for comparison
$revenue = getRevenueForPeriod($period, $date_from, $date_to); // Existing academic revenue

// Add service revenue if table exists
$table_exists = false;
$check_table_sql = "SHOW TABLES LIKE 'service_revenue'";
$check_result = $conn->query($check_table_sql);
if ($check_result && $check_result->num_rows > 0) {
    $table_exists = true;
}

if ($table_exists) {
    $service_revenue_sql = "SELECT COALESCE(SUM(amount), 0) as service_revenue 
                           FROM service_revenue 
                           WHERE status = 'completed'";
    
    // Add date filters based on period
    if ($period === 'today') {
        $service_revenue_sql .= " AND DATE(payment_date) = CURDATE()";
    } elseif ($period === 'week') {
        $service_revenue_sql .= " AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $service_revenue_sql .= " AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === 'year') {
        $service_revenue_sql .= " AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    } elseif ($period === 'custom' && $date_from && $date_to) {
        $service_revenue_sql .= " AND payment_date BETWEEN '$date_from' AND '$date_to'";
    }
    
    $service_revenue_result = $conn->query($service_revenue_sql);
    if ($service_revenue_result && $service_revenue_result->num_rows > 0) {
        $service_revenue_data = $service_revenue_result->fetch_assoc();
        $revenue += $service_revenue_data['service_revenue'];
    }
}

// Log activity
logActivity($_SESSION['user_id'], 'expense_dashboard', "Accessed expense dashboard with period: $period");

// Process export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    if (!empty($recent_expenses)) {
        fputcsv($output, array_keys($recent_expenses[0]));
        foreach ($recent_expenses as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Dashboard - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            overflow-x: hidden;
        }

        /* Mobile-first approach */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Header - Fixed at top */
        .mobile-header {
            background: var(--sidebar);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 60px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .mobile-header h1 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            flex: 1;
            justify-content: center;
        }

        .mobile-header .mobile-menu-btn {
            margin-right: auto;
        }

        /* Sidebar - Hidden on mobile by default */
        .sidebar {
            width: 280px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            top: 60px; /* Below mobile header */
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            display: none;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .main-content {
            flex: 1;
            padding: 1rem;
            width: 100%;
            margin-top: 60px; /* Account for fixed header */
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
            border-left: 4px solid transparent;
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
            border-top: 1px solid var(--dark-light);
            padding-top: 1rem;
        }

        /* Main Header */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .header h1 i {
            color: var(--primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            align-self: flex-end;
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
            flex-shrink: 0;
        }

        /* Filters */
        .filters-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
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
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .date-range-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            text-decoration: none;
            text-align: center;
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
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.revenue {
            border-top-color: var(--revenue);
        }

        .stat-card.pending {
            border-top-color: var(--pending);
        }

        .stat-card.overdue {
            border-top-color: var(--overdue);
        }

        .stat-card.issues {
            border-top-color: var(--issues);
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.85rem;
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

        .stat-icon {
            font-size: 1.75rem;
            opacity: 0.2;
            color: inherit;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .content-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: -1rem;
            padding: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 0.75rem;
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

        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.approved { background: #d1fae5; color: #065f46; }
        .status-badge.paid { background: #dbeafe; color: #1e40af; }
        .status-badge.cancelled { background: #f1f5f9; color: #64748b; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }

        /* Expense Category Badges */
        .expense-category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .category-operational { background: #dbeafe; color: #1e40af; }
        .category-fixed { background: #fee2e2; color: #991b1b; }
        .category-variable { background: #fef3c7; color: #92400e; }
        .category-tithe { background: #d1fae5; color: #065f46; }
        .category-reserve { background: #e0e7ff; color: #3730a3; }
        .category-other { background: #f1f5f9; color: #64748b; }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .action-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .action-icon {
            font-size: 1.75rem;
            margin-bottom: 0.75rem;
            color: var(--primary);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .action-desc {
            color: #64748b;
            font-size: 0.85rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
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
            padding: 2rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        /* Automated Deductions */
        .deductions-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .deduction-card {
            background: white;
            border-left: 4px solid;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .deduction-tithe { border-left-color: #10b981; }
        .deduction-reserve { border-left-color: #3b82f6; }

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        /* Action Buttons in Tables */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        /* Desktop Styles */
        @media (min-width: 768px) {
            .mobile-header {
                display: none;
            }

            .sidebar {
                width: 250px;
                top: 0;
                transform: translateX(0);
                position: fixed;
                height: 100vh;
            }

            .sidebar-overlay {
                display: none !important;
            }

            .main-content {
                margin-left: 250px;
                margin-top: 0;
                padding: 2rem;
                width: calc(100% - 250px);
            }

            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }

            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
                align-items: end;
            }

            .date-range-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
            }

            .content-grid {
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 1.5rem;
            }

            .deductions-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }

            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .main-content {
                padding: 2.5rem;
            }
        }

        /* Extra Small Devices */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .filter-buttons {
                flex-direction: column;
            }

            .filter-buttons .btn {
                width: 100%;
            }

            .user-info {
                align-self: stretch;
                justify-content: space-between;
            }
        }

        /* Touch-friendly improvements */
        .btn, .action-card, .form-control, select {
            min-height: 48px; /* Minimum touch target size */
        }

        table a.btn {
            min-height: auto;
            padding: 0.5rem;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }

        /* Mobile menu animation */
        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
            }
        }

        .sidebar.active {
            animation: slideIn 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle navigation menu">
                <i class="fas fa-bars"></i>
            </button>
            <h1>
                <i class="fas fa-money-bill-wave"></i>
                Expense Dashboard
            </h1>
        </div>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Expense Management</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/dashboard.php" class="active">
                            <i class="fas fa-money-bill-wave"></i> Expense Dashboard</a></li>

                    <div class="nav-section">Expense Management</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php">
                            <i class="fas fa-list"></i> Manage Expenses</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php">
                            <i class="fas fa-plus-circle"></i> Add Expense</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php">
                            <i class="fas fa-tags"></i> Categories</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php">
                            <i class="fas fa-chart-pie"></i> Budgets</a></li>

                    <div class="nav-section">Automated Deductions</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php">
                            <i class="fas fa-cog"></i> Configure Deductions</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/reports.php">
                            <i class="fas fa-file-alt"></i> Expense Reports</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-money-bill-wave"></i>
                    Expense Management Dashboard
                </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo $_SESSION['user_name']; ?></div>
                        <div style="font-size: 0.9rem; color: #64748b;">Finance Administrator</div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($calculation_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $calculation_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($pending_data['count'] > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    You have <?php echo $pending_data['count']; ?> pending expenses totaling 
                    <?php echo formatCurrency($pending_data['total']); ?> awaiting approval.
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Expense Data</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Time Period</label>
                        <select name="period" class="form-control" id="periodSelect">
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Last Year</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>

                    <div class="form-group" id="dateRangeGroup" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>">
                        <div class="date-range-grid">
                            <div>
                                <label>From Date</label>
                                <input type="date" name="date_from" class="form-control"
                                    value="<?php echo $date_from; ?>">
                            </div>
                            <div>
                                <label>To Date</label>
                                <input type="date" name="date_to" class="form-control"
                                    value="<?php echo $date_to; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
                                class="btn btn-success">
                                <i class="fas fa-file-export"></i> Export CSV
                            </a>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?calculate_deductions=true&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
                                class="btn btn-info">
                                <i class="fas fa-calculator"></i> Calculate Deductions
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-title">Add New Expense</div>
                    <div class="action-desc">Record a new expense</div>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php?status=pending" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="action-title">Approve Expenses</div>
                    <div class="action-desc">Review pending expenses</div>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="action-title">Manage Categories</div>
                    <div class="action-desc">Edit expense categories</div>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="action-title">Set Budgets</div>
                    <div class="action-desc">Monthly budget planning</div>
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Expenses</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_expenses'] ?? 0); ?>
                        <i class="fas fa-receipt stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        <?php echo $stats['expense_count'] ?? 0; ?> expense records
                    </div>
                </div>

                <div class="stat-card revenue">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($revenue); ?>
                        <i class="fas fa-money-bill-wave stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        For comparison
                    </div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-label">Automated Tithe</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_tithe'] ?? 0); ?>
                        <i class="fas fa-church stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        10% of revenue
                    </div>
                </div>

                <div class="stat-card overdue">
                    <div class="stat-label">Reserve Fund</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_reserve'] ?? 0); ?>
                        <i class="fas fa-piggy-bank stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        30% of revenue
                    </div>
                </div>
            </div>

            <!-- Automated Deductions Summary -->
            <div class="content-card" style="margin-bottom: 1.5rem;">
                <div class="card-header">
                    <h3><i class="fas fa-cog"></i> Automated Deductions Summary</h3>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php" 
                       class="btn btn-sm btn-primary">Configure</a>
                </div>
                <div class="card-body">
                    <div class="deductions-grid">
                        <?php foreach ($automated_deductions as $deduction): 
                            $expected = ($revenue * $deduction['percentage']) / 100;
                            $actual = $deduction['actual_deducted'] ?? 0;
                            $completion = $expected > 0 ? ($actual / $expected) * 100 : 0;
                        ?>
                            <div class="deduction-card deduction-<?php echo $deduction['deduction_type']; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 0.5rem;">
                                    <div style="flex: 1;">
                                        <h4 style="margin-bottom: 0.25rem; font-size: 1rem;"><?php echo ucfirst($deduction['deduction_type']); ?></h4>
                                        <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
                                            <?php echo $deduction['percentage']; ?>% of revenue
                                        </p>
                                    </div>
                                    <span class="status-badge status-approved">Active</span>
                                </div>
                                
                                <div style="margin: 1rem 0;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; flex-wrap: wrap; gap: 0.5rem;">
                                        <span>Expected: <?php echo formatCurrency($expected); ?></span>
                                        <span>Actual: <?php echo formatCurrency($actual); ?></span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" 
                                             style="width: <?php echo min($completion, 100); ?>%; 
                                                    background: <?php echo $deduction['deduction_type'] == 'tithe' ? '#10b981' : '#3b82f6'; ?>;">
                                        </div>
                                    </div>
                                    <div style="text-align: center; font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                        <?php echo round($completion, 1); ?>% complete
                                    </div>
                                </div>
                                
                                <div style="font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="far fa-calendar"></i>
                                    <span>Last calculated: <?php echo $deduction['last_calculated_date'] ? 
                                        date('M j, Y', strtotime($deduction['last_calculated_date'])) : 'Never'; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Charts and Tables -->
            <div class="content-grid">
                <!-- Expense Breakdown by Category -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Expense Breakdown</h3>
                        <span style="font-size: 0.9rem; color: #64748b;">By Category</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($stats['breakdown'])): ?>
                            <div style="max-height: 300px; overflow-y: auto; padding-right: 0.5rem;">
                                <?php foreach ($stats['breakdown'] as $category): ?>
                                    <div style="margin-bottom: 1rem; padding: 0.75rem; background: #f8fafc; border-radius: 6px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1;">
                                                <span class="expense-category-badge category-<?php echo $category['category_type']; ?>">
                                                    <?php echo $category['category_type']; ?>
                                                </span>
                                                <strong style="font-size: 0.95rem;"><?php echo htmlspecialchars($category['name']); ?></strong>
                                            </div>
                                            <span class="amount" style="font-weight: 600;"><?php echo formatCurrency($category['total_amount']); ?></span>
                                        </div>
                                        
                                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem; flex-wrap: wrap; gap: 0.25rem;">
                                            <span><?php echo $category['expense_count']; ?> expenses</span>
                                            <span><?php echo $category['percentage']; ?>% of total</span>
                                        </div>
                                        
                                        <div class="progress-bar">
                                            <div class="progress-fill" 
                                                 style="width: <?php echo $category['percentage']; ?>%; 
                                                        background: <?php echo $category['color_code']; ?>;">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-pie"></i>
                                <h3 style="font-size: 1.1rem;">No Expense Data</h3>
                                <p>No expenses found for the selected period.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Trend -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Monthly Expense Trend</h3>
                        <span style="font-size: 0.9rem; color: #64748b;">Last 6 Months</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($monthly_trend)): ?>
                            <div style="height: 250px; position: relative;">
                                <div style="position: absolute; bottom: 0; left: 0; right: 0; display: flex; align-items: flex-end; height: 200px; gap: 0.25rem; padding: 0 0.5rem;">
                                    <?php 
                                    $max_amount = max(array_column($monthly_trend, 'total_expenses'));
                                    foreach ($monthly_trend as $month):
                                        $height = $max_amount > 0 ? ($month['total_expenses'] / $max_amount) * 100 : 0;
                                    ?>
                                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0;">
                                            <div style="width: 70%; background: var(--primary); border-radius: 4px 4px 0 0;"
                                                 title="<?php echo formatCurrency($month['total_expenses']); ?>"
                                                 style="height: <?php echo $height; ?>%; min-height: 2px;">
                                            </div>
                                            <div style="margin-top: 0.5rem; font-size: 0.75rem; color: #64748b; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%;">
                                                <?php echo date('M Y', strtotime($month['month'] . '-01')); ?><br>
                                                <small style="font-size: 0.7rem;"><?php echo $month['expense_count']; ?> expenses</small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-line"></i>
                                <h3 style="font-size: 1.1rem;">No Trend Data</h3>
                                <p>Not enough data for monthly trend analysis.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Expenses -->
            <div class="content-card" style="margin-bottom: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Recent Expenses</h3>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php" class="btn btn-sm btn-primary">
                        View All
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent_expenses)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Expense #</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_expenses as $expense): ?>
                                        <tr>
                                            <td>
                                                <span style="white-space: nowrap;">
                                                    <?php echo date('M j, Y', strtotime($expense['payment_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo $expense['expense_number']; ?></strong>
                                            </td>
                                            <td>
                                                <span class="expense-category-badge category-<?php echo $expense['category_type']; ?>"
                                                      style="background: <?php echo $expense['color_code']; ?>20; color: <?php echo $expense['color_code']; ?>;">
                                                    <?php echo htmlspecialchars(substr($expense['category_name'], 0, 15)); ?>
                                                    <?php if (strlen($expense['category_name']) > 15): ?>...<?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span title="<?php echo htmlspecialchars($expense['description']); ?>">
                                                    <?php echo htmlspecialchars(substr($expense['description'], 0, 30)); ?>
                                                    <?php if (strlen($expense['description']) > 30): ?>...<?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="amount" style="font-weight: 600;">
                                                <?php echo formatCurrency($expense['amount']); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $expense['status']; ?>">
                                                    <?php echo ucfirst($expense['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/view.php?id=<?php echo $expense['id']; ?>"
                                                        class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($expense['status'] == 'pending'): ?>
                                                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/approve.php?id=<?php echo $expense['id']; ?>"
                                                            class="btn btn-sm btn-success">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($expense['status'] == 'approved' && $expense['status'] != 'paid'): ?>
                                                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/mark_paid.php?id=<?php echo $expense['id']; ?>"
                                                            class="btn btn-sm btn-info">
                                                            <i class="fas fa-money-bill"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-list"></i>
                            <h3 style="font-size: 1.1rem;">No Expenses Found</h3>
                            <p>No expenses match the selected filters.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }
        
        mobileMenuBtn.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);
        
        // Close sidebar when clicking a link on mobile
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    toggleSidebar();
                }
            });
        });
        
        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                toggleSidebar();
            }
        });
        
        // Toggle date range based on period selection
        document.getElementById('periodSelect').addEventListener('change', function(e) {
            const dateRangeGroup = document.getElementById('dateRangeGroup');
            if (e.target.value === 'custom') {
                dateRangeGroup.style.display = 'block';
            } else {
                dateRangeGroup.style.display = 'none';
            }
        });

        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            allowInput: true,
            disableMobile: false // Enable mobile-friendly date picker
        });

        // Auto-refresh every 5 minutes (optional)
        setInterval(() => {
            if (!document.hidden && !sidebar.classList.contains('active')) {
                // Don't refresh if user is viewing sidebar
                window.location.reload();
            }
        }, 300000);
        
        // Adjust table container padding on mobile
        function adjustTablePadding() {
            const tableContainers = document.querySelectorAll('.table-container');
            const isMobile = window.innerWidth < 768;
            
            tableContainers.forEach(container => {
                if (isMobile) {
                    container.style.padding = '0.5rem';
                    container.style.margin = '-0.5rem';
                } else {
                    container.style.padding = '1rem';
                    container.style.margin = '-1rem';
                }
            });
        }
        
        // Initial adjustment
        adjustTablePadding();
        
        // Adjust on resize
        window.addEventListener('resize', adjustTablePadding);
        
        // Handle touch events for better mobile experience
        document.addEventListener('touchstart', function() {}, {passive: true});
        
        // Prevent body scroll when sidebar is open
        function preventBodyScroll() {
            if (window.innerWidth < 768 && sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
        
        window.addEventListener('resize', preventBodyScroll);
    </script>
</body>
</html>
<?php $conn->close(); ?>