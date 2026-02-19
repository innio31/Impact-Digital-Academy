<?php
// modules/admin/finance/services/dashboard.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Check if service tables exist
$check_table_sql = "SHOW TABLES LIKE 'service_revenue'";
$check_result = $conn->query($check_table_sql);
if (!$check_result || $check_result->num_rows === 0) {
    die('Service revenue table does not exist. Please run the database migrations.');
}

// Get filter parameters
$period = $_GET['period'] ?? 'month';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$revenue_type = $_GET['revenue_type'] ?? '';

// Validate period
$valid_periods = ['today', 'week', 'month', 'quarter', 'year', 'all', 'custom'];
if (!in_array($period, $valid_periods)) {
    $period = 'month';
}

// Set date range based on period
$now = new DateTime();
switch ($period) {
    case 'today':
        $date_from = $date_to = $now->format('Y-m-d');
        break;
    case 'week':
        $date_from = $now->modify('-7 days')->format('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $month_start = ($quarter - 1) * 3 + 1;
        $month_end = $quarter * 3;
        $date_from = date('Y-' . sprintf('%02d', $month_start) . '-01');
        $date_to = date('Y-' . sprintf('%02d', $month_end) . '-t');
        break;
    case 'year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
        break;
    case 'all':
        $date_from = '';
        $date_to = '';
        break;
}

// Use custom dates if provided
if ($period === 'custom' && $date_from && $date_to) {
    if ($date_from > $date_to) {
        $temp = $date_from;
        $date_from = $date_to;
        $date_to = $temp;
    }
}

// Build WHERE clause for date filtering
$date_where = '';
$params = [];
$types = '';

if ($date_from && $date_to && $period !== 'all') {
    $date_where = "AND service_revenue.payment_date BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

if (!empty($category_id) && is_numeric($category_id)) {
    $date_where .= " AND service_revenue.service_category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}

if (!empty($revenue_type) && in_array($revenue_type, ['product', 'service', 'consultancy', 'other'])) {
    $date_where .= " AND service_categories.revenue_type = ?";
    $params[] = $revenue_type;
    $types .= 's';
}

// Get dashboard statistics
$stats_sql = "SELECT 
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN service_revenue.status = 'pending' THEN service_revenue.amount ELSE 0 END), 0) as pending_revenue,
                COALESCE(SUM(CASE WHEN service_revenue.status = 'cancelled' THEN service_revenue.amount ELSE 0 END), 0) as cancelled_revenue,
                COALESCE(SUM(CASE WHEN service_revenue.status = 'refunded' THEN service_revenue.amount ELSE 0 END), 0) as refunded_revenue,
                COALESCE(AVG(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE NULL END), 0) as avg_transaction,
                MIN(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE NULL END) as min_transaction,
                MAX(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE NULL END) as max_transaction
              FROM service_revenue
              LEFT JOIN service_categories ON service_categories.id = service_revenue.service_category_id
              WHERE 1=1 {$date_where}";

// Prepare and execute the stats query
if (!empty($params)) {
    $stmt = $conn->prepare($stats_sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats_result = $stmt->get_result();
        $stats = $stats_result->fetch_assoc();
        $stmt->close();
    } else {
        die("Error preparing stats query: " . $conn->error);
    }
} else {
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result ? $stats_result->fetch_assoc() : [];
}

// Get revenue by category
$category_stats_sql = "SELECT 
                        service_categories.name as category_name,
                        service_categories.revenue_type,
                        COUNT(service_revenue.id) as transaction_count,
                        COALESCE(SUM(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE 0 END), 0) as total_revenue,
                        COALESCE(AVG(CASE WHEN service_revenue.status = 'completed' THEN service_revenue.amount ELSE NULL END), 0) as avg_revenue
                      FROM service_categories
                      LEFT JOIN service_revenue ON service_revenue.service_category_id = service_categories.id
                      WHERE service_categories.is_active = 1 {$date_where}
                      GROUP BY service_categories.id, service_categories.name, service_categories.revenue_type
                      ORDER BY total_revenue DESC";

// Prepare and execute category stats query
if (!empty($params)) {
    $category_stmt = $conn->prepare($category_stats_sql);
    if ($category_stmt) {
        $category_stmt->bind_param($types, ...$params);
        $category_stmt->execute();
        $category_stats_result = $category_stmt->get_result();
        $category_stats = $category_stats_result->fetch_all(MYSQLI_ASSOC);
        $category_stmt->close();
    } else {
        die("Error preparing category stats query: " . $conn->error);
    }
} else {
    $category_stats_result = $conn->query($category_stats_sql);
    $category_stats = $category_stats_result ? $category_stats_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get revenue by month (for chart)
$monthly_sql = "SELECT 
                  DATE_FORMAT(payment_date, '%Y-%m') as month,
                  COUNT(*) as transaction_count,
                  COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_revenue
                FROM service_revenue
                WHERE status = 'completed' 
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12";

$monthly_result = $conn->query($monthly_sql);
$monthly_data = $monthly_result ? $monthly_result->fetch_all(MYSQLI_ASSOC) : [];

// Get revenue by status
$status_sql = "SELECT 
                 status,
                 COUNT(*) as transaction_count,
                 COALESCE(SUM(amount), 0) as total_amount
               FROM service_revenue
               WHERE 1=1 {$date_where}
               GROUP BY status
               ORDER BY total_amount DESC";

// Prepare and execute status query
if (!empty($params)) {
    $status_stmt = $conn->prepare($status_sql);
    if ($status_stmt) {
        $status_stmt->bind_param($types, ...$params);
        $status_stmt->execute();
        $status_result = $status_stmt->get_result();
        $status_data = $status_result->fetch_all(MYSQLI_ASSOC);
        $status_stmt->close();
    } else {
        die("Error preparing status query: " . $conn->error);
    }
} else {
    $status_result = $conn->query($status_sql);
    $status_data = $status_result ? $status_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get revenue by payment method
$method_sql = "SELECT 
                 payment_method,
                 COUNT(*) as transaction_count,
                 COALESCE(SUM(amount), 0) as total_amount
               FROM service_revenue
               WHERE status = 'completed' {$date_where}
               GROUP BY payment_method
               ORDER BY total_amount DESC";

// Prepare and execute method query
if (!empty($params)) {
    $method_stmt = $conn->prepare($method_sql);
    if ($method_stmt) {
        $method_stmt->bind_param($types, ...$params);
        $method_stmt->execute();
        $method_result = $method_stmt->get_result();
        $method_data = $method_result->fetch_all(MYSQLI_ASSOC);
        $method_stmt->close();
    } else {
        die("Error preparing method query: " . $conn->error);
    }
} else {
    $method_result = $conn->query($method_sql);
    $method_data = $method_result ? $method_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get top clients
$clients_sql = "SELECT 
                  client_name,
                  COUNT(*) as transaction_count,
                  COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_revenue,
                  MAX(payment_date) as last_transaction
                FROM service_revenue
                WHERE 1=1 {$date_where}
                GROUP BY client_name
                HAVING total_revenue > 0
                ORDER BY total_revenue DESC
                LIMIT 10";

// Prepare and execute clients query
if (!empty($params)) {
    $clients_stmt = $conn->prepare($clients_sql);
    if ($clients_stmt) {
        $clients_stmt->bind_param($types, ...$params);
        $clients_stmt->execute();
        $clients_result = $clients_stmt->get_result();
        $top_clients = $clients_result->fetch_all(MYSQLI_ASSOC);
        $clients_stmt->close();
    } else {
        die("Error preparing clients query: " . $conn->error);
    }
} else {
    $clients_result = $conn->query($clients_sql);
    $top_clients = $clients_result ? $clients_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get recent transactions
$recent_sql = "SELECT 
                 service_revenue.*,
                 service_categories.name as category_name,
                 service_categories.revenue_type
               FROM service_revenue
               LEFT JOIN service_categories ON service_categories.id = service_revenue.service_category_id
               WHERE 1=1 {$date_where}
               ORDER BY service_revenue.created_at DESC
               LIMIT 10";

// Prepare and execute recent transactions query
if (!empty($params)) {
    $recent_stmt = $conn->prepare($recent_sql);
    if ($recent_stmt) {
        $recent_stmt->bind_param($types, ...$params);
        $recent_stmt->execute();
        $recent_result = $recent_stmt->get_result();
        $recent_transactions = $recent_result->fetch_all(MYSQLI_ASSOC);
        $recent_stmt->close();
    } else {
        die("Error preparing recent transactions query: " . $conn->error);
    }
} else {
    $recent_result = $conn->query($recent_sql);
    $recent_transactions = $recent_result ? $recent_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get categories for filter dropdown
$categories_sql = "SELECT * FROM service_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

// Calculate growth rates (compared to previous period)
$previous_stats = [];
if ($period !== 'all') {
    $prev_date_from = '';
    $prev_date_to = '';
    
    // Calculate previous period dates
    switch ($period) {
        case 'today':
            $prev_date = date('Y-m-d', strtotime('-1 day'));
            $prev_date_from = $prev_date_to = $prev_date;
            break;
        case 'week':
            $prev_date_from = date('Y-m-d', strtotime('-14 days'));
            $prev_date_to = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $prev_date_from = date('Y-m-01', strtotime('-1 month'));
            $prev_date_to = date('Y-m-t', strtotime('-1 month'));
            break;
        case 'quarter':
            $quarter = ceil(date('n') / 3) - 1;
            if ($quarter <= 0) {
                $quarter = 4;
                $year = date('Y') - 1;
            } else {
                $year = date('Y');
            }
            $month_start = ($quarter - 1) * 3 + 1;
            $month_end = $quarter * 3;
            $prev_date_from = $year . '-' . sprintf('%02d', $month_start) . '-01';
            $prev_date_to = date($year . '-' . sprintf('%02d', $month_end) . '-t');
            break;
        case 'year':
            $prev_year = date('Y') - 1;
            $prev_date_from = $prev_year . '-01-01';
            $prev_date_to = $prev_year . '-12-31';
            break;
    }
    
    if ($prev_date_from && $prev_date_to) {
        $prev_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_revenue,
                        COUNT(*) as total_transactions
                     FROM service_revenue 
                     WHERE payment_date BETWEEN ? AND ?";
        $prev_stmt = $conn->prepare($prev_sql);
        if ($prev_stmt) {
            $prev_stmt->bind_param('ss', $prev_date_from, $prev_date_to);
            $prev_stmt->execute();
            $prev_result = $prev_stmt->get_result();
            $previous_stats = $prev_result->fetch_assoc();
            $prev_stmt->close();
        }
    }
}

// Calculate growth percentages
$growth = [
    'revenue' => 0,
    'transactions' => 0
];

if (!empty($previous_stats) && $previous_stats['total_revenue'] > 0) {
    $current_revenue = $stats['total_revenue'] ?? 0;
    $previous_revenue = $previous_stats['total_revenue'] ?? 0;
    $growth['revenue'] = $previous_revenue > 0 ? 
        (($current_revenue - $previous_revenue) / $previous_revenue) * 100 : 0;
    
    $current_transactions = $stats['total_transactions'] ?? 0;
    $previous_transactions = $previous_stats['total_transactions'] ?? 0;
    $growth['transactions'] = $previous_transactions > 0 ? 
        (($current_transactions - $previous_transactions) / $previous_transactions) * 100 : 0;
}

// Log activity
logActivity($_SESSION['user_id'], 'service_dashboard', "Accessed service revenue dashboard with period: {$period}");

// Export functionality
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="service_revenue_dashboard_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    $headers = ['Period', 'Total Revenue', 'Total Transactions', 'Pending Revenue', 'Avg Transaction'];
    fputcsv($output, $headers);
    
    // Write data
    $row = [
        ucfirst($period) . ' Period',
        formatCurrency($stats['total_revenue']),
        $stats['total_transactions'],
        formatCurrency($stats['pending_revenue']),
        formatCurrency($stats['avg_transaction'])
    ];
    fputcsv($output, $row);
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Revenue Analytics - Finance Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Chart.js for graphs -->
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
            --product: #8b5cf6;
            --service: #10b981;
            --consultancy: #3b82f6;
            --other: #6b7280;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .admin-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 1rem;
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
            font-size: 0.9rem;
        }

        /* Filters */
        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
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
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.revenue {
            border-top-color: var(--success);
        }

        .stat-card.transactions {
            border-top-color: var(--primary);
        }

        .stat-card.pending {
            border-top-color: var(--warning);
        }

        .stat-card.avg {
            border-top-color: var(--info);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            transform: rotate(45deg) translate(20px, -20px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--dark);
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

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .chart-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .chart-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-body {
            padding: 1.5rem;
            height: 300px;
            position: relative;
        }

        /* Data Grid */
        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .data-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .data-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .data-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .data-body {
            padding: 1.5rem;
            max-height: 400px;
            overflow-y: auto;
        }

        /* Table Styles */
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
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
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

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-refunded {
            background: #dbeafe;
            color: #1e40af;
        }

        .revenue-type {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-product {
            background: #ede9fe;
            color: var(--product);
        }

        .type-service {
            background: #dcfce7;
            color: var(--service);
        }

        .type-consultancy {
            background: #dbf4ff;
            color: var(--consultancy);
        }

        .type-other {
            background: #f3f4f6;
            color: var(--other);
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 1rem;
        }

        /* Metric Cards */
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .metric-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .metric-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .metric-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Period Summary */
        .period-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .period-info h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .period-dates {
            font-size: 1rem;
            opacity: 0.9;
        }

        .period-actions {
            display: flex;
            gap: 0.75rem;
        }

        /* Loading State */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        /* Legend */
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        .legend-label {
            font-size: 0.9rem;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .admin-container {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .data-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .period-summary {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Service Revenue Analytics Dashboard
            </h1>
            <p>Comprehensive analytics for non-academic revenue streams</p>
        </div>

        <!-- Quick Actions -->
        <div class="header-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> View All Revenue
            </a>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Add New Revenue
            </a>
            <a href="categories.php" class="btn btn-info">
                <i class="fas fa-tags"></i> Manage Categories
            </a>
            <a href="../dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Finance Dashboard
            </a>
        </div>

        <!-- Period Summary -->
        <div class="period-summary">
            <div class="period-info">
                <h3>Service Revenue Analytics</h3>
                <div class="period-dates">
                    <?php if ($period === 'all'): ?>
                        All Time Data
                    <?php else: ?>
                        <?php echo ucfirst($period); ?> Period: 
                        <?php echo date('M j, Y', strtotime($date_from)); ?> - 
                        <?php echo date('M j, Y', strtotime($date_to)); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="period-actions">
                <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                   class="btn btn-success btn-sm">
                    <i class="fas fa-file-export"></i> Export Data
                </a>
                <a href="dashboard.php?period=all" class="btn btn-secondary btn-sm">
                    <i class="fas fa-chart-bar"></i> View All Time
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <h3><i class="fas fa-filter"></i> Filter Analytics</h3>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="period">Time Period</label>
                    <select id="period" name="period" class="form-control" onchange="updateDateFields()">
                        <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                        <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
                        <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>

                <div class="form-group" id="dateRangeGroup" style="display: <?php echo $period === 'custom' ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                    <div>
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" class="form-control" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div>
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" class="form-control" 
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-control">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="revenue_type">Revenue Type</label>
                    <select id="revenue_type" name="revenue_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="product" <?php echo $revenue_type === 'product' ? 'selected' : ''; ?>>Product</option>
                        <option value="service" <?php echo $revenue_type === 'service' ? 'selected' : ''; ?>>Service</option>
                        <option value="consultancy" <?php echo $revenue_type === 'consultancy' ? 'selected' : ''; ?>>Consultancy</option>
                        <option value="other" <?php echo $revenue_type === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group filter-actions">
                    <label>&nbsp;</label>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-number">
                    <?php echo formatCurrency($stats['total_revenue'] ?? 0); ?>
                    <i class="fas fa-money-bill-wave stat-icon"></i>
                </div>
                <?php if ($growth['revenue'] != 0): ?>
                    <div class="stat-trend">
                        <span class="<?php echo $growth['revenue'] > 0 ? 'trend-up' : 'trend-down'; ?>">
                            <i class="fas fa-arrow-<?php echo $growth['revenue'] > 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo abs(round($growth['revenue'], 1)); ?>%
                        </span>
                        vs previous period
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card transactions">
                <div class="stat-label">Total Transactions</div>
                <div class="stat-number">
                    <?php echo $stats['total_transactions'] ?? 0; ?>
                    <i class="fas fa-exchange-alt stat-icon"></i>
                </div>
                <?php if ($growth['transactions'] != 0): ?>
                    <div class="stat-trend">
                        <span class="<?php echo $growth['transactions'] > 0 ? 'trend-up' : 'trend-down'; ?>">
                            <i class="fas fa-arrow-<?php echo $growth['transactions'] > 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo abs(round($growth['transactions'], 1)); ?>%
                        </span>
                        vs previous period
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card pending">
                <div class="stat-label">Pending Revenue</div>
                <div class="stat-number">
                    <?php echo formatCurrency($stats['pending_revenue'] ?? 0); ?>
                    <i class="fas fa-clock stat-icon"></i>
                </div>
                <div class="stat-trend">
                    <?php echo round(($stats['pending_revenue'] / max($stats['total_revenue'], 1)) * 100, 1); ?>% of total
                </div>
            </div>

            <div class="stat-card avg">
                <div class="stat-label">Average Transaction</div>
                <div class="stat-number">
                    <?php echo formatCurrency($stats['avg_transaction'] ?? 0); ?>
                    <i class="fas fa-calculator stat-icon"></i>
                </div>
                <div class="stat-trend">
                    Range: <?php echo formatCurrency($stats['min_transaction'] ?? 0); ?> - 
                    <?php echo formatCurrency($stats['max_transaction'] ?? 0); ?>
                </div>
            </div>
        </div>

        <!-- Additional Metrics -->
        <div class="metric-grid">
            <div class="metric-card">
                <div class="metric-label">Cancelled Revenue</div>
                <div class="metric-value"><?php echo formatCurrency($stats['cancelled_revenue'] ?? 0); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Refunded Revenue</div>
                <div class="metric-value"><?php echo formatCurrency($stats['refunded_revenue'] ?? 0); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Success Rate</div>
                <div class="metric-value">
                    <?php 
                    $success_rate = $stats['total_transactions'] > 0 ? 
                        (($stats['total_transactions'] - (($stats['cancelled_revenue'] > 0 ? 1 : 0) + ($stats['refunded_revenue'] > 0 ? 1 : 0))) / $stats['total_transactions']) * 100 : 0;
                    echo round($success_rate, 1); ?>%
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Revenue per Day</div>
                <div class="metric-value">
                    <?php
                    $days = $period === 'all' ? 365 : (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24) + 1;
                    $revenue_per_day = $days > 0 ? $stats['total_revenue'] / $days : 0;
                    echo formatCurrency($revenue_per_day);
                    ?>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Revenue by Category Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Revenue by Category</h3>
                </div>
                <div class="chart-body">
                    <?php if (!empty($category_stats) && array_sum(array_column($category_stats, 'total_revenue')) > 0): ?>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                        <div class="chart-legend" id="categoryLegend"></div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-pie"></i>
                            <h3>No Revenue Data</h3>
                            <p>No revenue data available for the selected period.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monthly Revenue Trend -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Monthly Revenue Trend</h3>
                </div>
                <div class="chart-body">
                    <?php if (!empty($monthly_data)): ?>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <h3>No Monthly Data</h3>
                            <p>No monthly revenue data available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Data Tables Section -->
        <div class="data-grid">
            <!-- Revenue by Status -->
            <div class="data-card">
                <div class="data-header">
                    <h3><i class="fas fa-chart-bar"></i> Revenue by Status</h3>
                </div>
                <div class="data-body">
                    <?php if (!empty($status_data)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Transactions</th>
                                    <th>Amount</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_amount = array_sum(array_column($status_data, 'total_amount'));
                                foreach ($status_data as $status): 
                                    $percentage = $total_amount > 0 ? ($status['total_amount'] / $total_amount) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <span class="status-badge status-<?php echo $status['status']; ?>">
                                            <?php echo ucfirst($status['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $status['transaction_count']; ?></td>
                                    <td class="amount"><?php echo formatCurrency($status['total_amount']); ?></td>
                                    <td><?php echo round($percentage, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h3>No Status Data</h3>
                            <p>No revenue status data available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Clients -->
            <div class="data-card">
                <div class="data-header">
                    <h3><i class="fas fa-users"></i> Top Clients</h3>
                </div>
                <div class="data-body">
                    <?php if (!empty($top_clients)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Transactions</th>
                                    <th>Total Revenue</th>
                                    <th>Last Transaction</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_clients as $client): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($client['client_name']); ?></td>
                                    <td><?php echo $client['transaction_count']; ?></td>
                                    <td class="amount"><?php echo formatCurrency($client['total_revenue']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($client['last_transaction'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Client Data</h3>
                            <p>No client revenue data available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="data-card">
            <div class="data-header">
                <h3><i class="fas fa-history"></i> Recent Transactions</h3>
                <a href="index.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="data-body">
                <?php if (!empty($recent_transactions)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <?php echo date('M j', strtotime($transaction['payment_date'])); ?><br>
                                    <small style="color: #64748b;"><?php echo date('g:i A', strtotime($transaction['created_at'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['client_name']); ?></td>
                                <td>
                                    <span class="revenue-type type-<?php echo $transaction['revenue_type']; ?>">
                                        <?php echo htmlspecialchars($transaction['category_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td class="amount"><?php echo formatCurrency($transaction['amount']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                        <?php echo ucfirst($transaction['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Recent Transactions</h3>
                        <p>No recent transactions found for the selected period.</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            allowInput: true
        });

        // Update date fields based on period selection
        function updateDateFields() {
            const period = document.getElementById('period').value;
            const dateRangeGroup = document.getElementById('dateRangeGroup');
            
            if (period === 'custom') {
                dateRangeGroup.style.display = 'grid';
            } else {
                dateRangeGroup.style.display = 'none';
            }
        }

        // Form loading state
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function() {
                this.classList.add('loading');
            });
        }

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($category_stats) && array_sum(array_column($category_stats, 'total_revenue')) > 0): ?>
                // Category Chart
                const categoryCanvas = document.getElementById('categoryChart');
                if (categoryCanvas) {
                    const categoryCtx = categoryCanvas.getContext('2d');
                    const categoryLabels = <?php echo json_encode(array_column($category_stats, 'category_name')); ?>;
                    const categoryData = <?php echo json_encode(array_column($category_stats, 'total_revenue')); ?>;
                    const categoryColors = categoryLabels.map(label => stringToColor(label));
                    
                    const categoryChart = new Chart(categoryCtx, {
                        type: 'doughnut',
                        data: {
                            labels: categoryLabels,
                            datasets: [{
                                data: categoryData,
                                backgroundColor: categoryColors,
                                borderWidth: 1,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                            return `${label}: ${formatCurrency(value)} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                    
                    // Create custom legend
                    const legendHtml = categoryLabels.map((label, index) => {
                        const value = categoryData[index];
                        const percentage = categoryData.reduce((a, b) => a + b, 0) > 0 ? 
                            Math.round((value / categoryData.reduce((a, b) => a + b, 0)) * 100) : 0;
                        return `
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: ${categoryColors[index]}"></div>
                                <div class="legend-label">
                                    ${label}: ${formatCurrency(value)} (${percentage}%)
                                </div>
                            </div>
                        `;
                    }).join('');
                    
                    const legendElement = document.getElementById('categoryLegend');
                    if (legendElement) {
                        legendElement.innerHTML = legendHtml;
                    }
                }
            <?php endif; ?>
            
            <?php if (!empty($monthly_data)): ?>
                // Monthly Trend Chart
                const monthlyCanvas = document.getElementById('monthlyChart');
                if (monthlyCanvas) {
                    const monthlyCtx = monthlyCanvas.getContext('2d');
                    const monthlyLabels = <?php echo json_encode(array_column($monthly_data, 'month')); ?>.reverse();
                    const monthlyData = <?php echo json_encode(array_column($monthly_data, 'total_revenue')); ?>.reverse();
                    
                    const monthlyChart = new Chart(monthlyCtx, {
                        type: 'line',
                        data: {
                            labels: monthlyLabels.map(m => {
                                const [year, month] = m.split('-');
                                return new Date(year, month - 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
                            }),
                            datasets: [{
                                label: 'Monthly Revenue',
                                data: monthlyData,
                                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                                borderColor: 'rgba(37, 99, 235, 1)',
                                borderWidth: 2,
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: 'rgba(37, 99, 235, 1)',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return `Revenue: ${formatCurrency(context.raw)}`;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return formatCurrency(value);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>
        });

        // Utility function to generate color from string
        function stringToColor(str) {
            if (!str) return '#6b7280';
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = str.charCodeAt(i) + ((hash << 5) - hash);
            }
            let color = '#';
            for (let i = 0; i < 3; i++) {
                const value = (hash >> (i * 8)) & 0xFF;
                color += ('00' + value.toString(16)).substr(-2);
            }
            return color;
        }

        // Currency formatting helper
        function formatCurrency(amount) {
            if (isNaN(amount)) amount = 0;
            return '₦' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.ctrlKey && event.key === 'r') {
                event.preventDefault();
                window.location.reload();
            }
            
            if (event.key === 'Escape') {
                window.location.href = 'index.php';
            }
        });

        // Print functionality
        function printDashboard() {
            window.print();
        }

        // Add print button event listener if needed
        const printBtn = document.createElement('button');
        printBtn.className = 'btn btn-secondary';
        printBtn.innerHTML = '<i class="fas fa-print"></i> Print Report';
        printBtn.style.marginLeft = '1rem';
        printBtn.onclick = printDashboard;
        
        // Add print button to period actions if not on mobile
        if (window.innerWidth > 768) {
            const periodActions = document.querySelector('.period-actions');
            if (periodActions) {
                periodActions.appendChild(printBtn);
            }
        }
    </script>
</body>
</html>