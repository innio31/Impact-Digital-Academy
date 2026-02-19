<?php
// modules/admin/finance/reports/revenue.php

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
$payment_method = $_GET['payment_method'] ?? '';
$status = $_GET['status'] ?? 'completed';
$export_type = $_GET['export'] ?? '';
$revenue_type = $_GET['revenue_type'] ?? 'all'; // New filter for revenue type

// Set default dates based on period
if ($period === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
} elseif ($period === 'all') {
    $date_from = '2024-01-01'; // Or earliest date in your system
}

// Get revenue data from all three sources
$revenue_data = [];
$total_revenue = 0;
$total_transactions = 0;
$program_revenues = [];

// Build base conditions for all queries
// Note: Using different base conditions for each table to avoid ambiguity
$params = [$date_from, $date_to];
$param_types = 'ss';

// Add payment method filter if specified
$payment_method_condition = "";
if (!empty($payment_method) && $payment_method !== 'all') {
    $payment_method_condition = " AND payment_method = ?";
    $params[] = $payment_method;
    $param_types .= 's';
}

// Get Registration Fee Revenue
if ($revenue_type === 'all' || $revenue_type === 'registration') {
    $reg_sql = "SELECT 
                    rfp.*,
                    p.program_code,
                    p.name as program_name,
                    p.program_type,
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    u.email as student_email,
                    'registration' as revenue_source
                FROM registration_fee_payments rfp
                JOIN programs p ON p.id = rfp.program_id
                JOIN users u ON u.id = rfp.student_id
                WHERE rfp.status = 'completed' 
                AND DATE(rfp.payment_date) BETWEEN ? AND ?
                $payment_method_condition
                ORDER BY rfp.payment_date DESC";
    
    $reg_stmt = $conn->prepare($reg_sql);
    $reg_stmt->bind_param($param_types, ...$params);
    $reg_stmt->execute();
    $reg_result = $reg_stmt->get_result();
    
    while ($row = $reg_result->fetch_assoc()) {
        $program_key = $row['program_type'] . '_' . $row['program_code'];
        
        if (!isset($program_revenues[$program_key])) {
            $program_revenues[$program_key] = [
                'program_type' => $row['program_type'],
                'program_code' => $row['program_code'],
                'program_name' => $row['program_name'],
                'registration_revenue' => 0,
                'course_revenue' => 0,
                'service_revenue' => 0,
                'transaction_count' => 0,
                'total_amount' => 0,
                'transactions' => []
            ];
        }
        
        $program_revenues[$program_key]['registration_revenue'] += $row['amount'];
        $program_revenues[$program_key]['total_amount'] += $row['amount'];
        $program_revenues[$program_key]['transaction_count']++;
        $program_revenues[$program_key]['transactions'][] = [
            'type' => 'registration',
            'amount' => $row['amount'],
            'date' => $row['payment_date'],
            'student' => $row['student_name'],
            'payment_method' => $row['payment_method']
        ];
        
        $total_revenue += $row['amount'];
        $total_transactions++;
        
        // Add to detailed revenue data
        $row['revenue_type'] = 'Registration Fee';
        $revenue_data[] = $row;
    }
    $reg_stmt->close();
}

// Get Course Payment Revenue
if ($revenue_type === 'all' || $revenue_type === 'course') {
    $course_sql = "SELECT 
                        cp.*,
                        p.program_code,
                        p.name as program_name,
                        p.program_type,
                        c.title as course_title,
                        cb.batch_code,
                        CONCAT(u.first_name, ' ', u.last_name) as student_name,
                        u.email as student_email,
                        'course' as revenue_source
                    FROM course_payments cp
                    JOIN class_batches cb ON cb.id = cp.class_id
                    JOIN courses c ON c.id = cp.course_id
                    JOIN programs p ON p.id = c.program_id  -- FIXED: p.id = c.program_id
                    JOIN users u ON u.id = cp.student_id
                    WHERE cp.status = 'completed' 
                    AND DATE(cp.payment_date) BETWEEN ? AND ?  -- ADDED: Date filter
                    $payment_method_condition
                    ORDER BY cp.payment_date DESC";
    
    $course_stmt = $conn->prepare($course_sql);
    $course_stmt->bind_param($param_types, ...$params);
    $course_stmt->execute();
    $course_result = $course_stmt->get_result();
    
    while ($row = $course_result->fetch_assoc()) {
        $program_key = $row['program_type'] . '_' . $row['program_code'];
        
        if (!isset($program_revenues[$program_key])) {
            $program_revenues[$program_key] = [
                'program_type' => $row['program_type'],
                'program_code' => $row['program_code'],
                'program_name' => $row['program_name'],
                'registration_revenue' => 0,
                'course_revenue' => 0,
                'service_revenue' => 0,
                'transaction_count' => 0,
                'total_amount' => 0,
                'transactions' => []
            ];
        }
        
        $program_revenues[$program_key]['course_revenue'] += $row['amount'];
        $program_revenues[$program_key]['total_amount'] += $row['amount'];
        $program_revenues[$program_key]['transaction_count']++;
        $program_revenues[$program_key]['transactions'][] = [
            'type' => 'course',
            'amount' => $row['amount'],
            'date' => $row['payment_date'],
            'student' => $row['student_name'],
            'course' => $row['course_title'],
            'batch' => $row['batch_code'],
            'payment_method' => $row['payment_method']
        ];
        
        $total_revenue += $row['amount'];
        $total_transactions++;
        
        // Add to detailed revenue data
        $row['revenue_type'] = 'Course Payment';
        $revenue_data[] = $row;
    }
    $course_stmt->close();
}

// Get Service Revenue
if ($revenue_type === 'all' || $revenue_type === 'service') {
    $service_sql = "SELECT 
                        sr.*,
                        sc.name as service_category,
                        CONCAT(uc.first_name, ' ', uc.last_name) as created_by_name,
                        'service' as revenue_source,
                        'service' as program_type,
                        'SRV' as program_code,
                        'Service Revenue' as program_name
                    FROM service_revenue sr
                    JOIN service_categories sc ON sc.id = sr.service_category_id
                    JOIN users uc ON uc.id = sr.created_by
                    WHERE sr.status = 'completed' 
                    AND DATE(sr.payment_date) BETWEEN ? AND ?
                    $payment_method_condition
                    ORDER BY sr.payment_date DESC";
    
    $service_stmt = $conn->prepare($service_sql);
    $service_stmt->bind_param($param_types, ...$params);
    $service_stmt->execute();
    $service_result = $service_stmt->get_result();
    
    while ($row = $service_result->fetch_assoc()) {
        $program_key = 'service_SRV';
        
        if (!isset($program_revenues[$program_key])) {
            $program_revenues[$program_key] = [
                'program_type' => 'service',
                'program_code' => 'SRV',
                'program_name' => 'Service Revenue',
                'registration_revenue' => 0,
                'course_revenue' => 0,
                'service_revenue' => 0,
                'transaction_count' => 0,
                'total_amount' => 0,
                'transactions' => []
            ];
        }
        
        $program_revenues[$program_key]['service_revenue'] += $row['amount'];
        $program_revenues[$program_key]['total_amount'] += $row['amount'];
        $program_revenues[$program_key]['transaction_count']++;
        $program_revenues[$program_key]['transactions'][] = [
            'type' => 'service',
            'amount' => $row['amount'],
            'date' => $row['payment_date'],
            'client' => $row['client_name'],
            'service' => $row['service_category'],
            'payment_method' => $row['payment_method']
        ];
        
        $total_revenue += $row['amount'];
        $total_transactions++;
        
        // Add to detailed revenue data
        $row['revenue_type'] = 'Service Revenue';
        $revenue_data[] = $row;
    }
    $service_stmt->close();
}

// Prepare program revenues array for display
$program_revenues_display = [];
foreach ($program_revenues as $key => $program) {
    // Calculate averages
    $avg_amount = $program['transaction_count'] > 0 ? 
        $program['total_amount'] / $program['transaction_count'] : 0;
    
    // Find min and max amounts from transactions
    $amounts = array_column($program['transactions'], 'amount');
    $min_amount = !empty($amounts) ? min($amounts) : 0;
    $max_amount = !empty($amounts) ? max($amounts) : 0;
    
    $program_revenues_display[] = [
        'program_type' => $program['program_type'],
        'program_code' => $program['program_code'],
        'program_name' => $program['program_name'],
        'transaction_count' => $program['transaction_count'],
        'total_amount' => $program['total_amount'],
        'registration_revenue' => $program['registration_revenue'],
        'course_revenue' => $program['course_revenue'],
        'service_revenue' => $program['service_revenue'],
        'avg_amount' => $avg_amount,
        'min_amount' => $min_amount,
        'max_amount' => $max_amount
    ];
}

// Sort by total amount descending
usort($program_revenues_display, function($a, $b) {
    return $b['total_amount'] <=> $a['total_amount'];
});

// Get daily revenue trend
$daily_trend_sql = "SELECT 
    DATE(payment_date) as date,
    SUM(amount) as daily_revenue,
    COUNT(*) as daily_transactions
FROM (
    SELECT payment_date, amount FROM registration_fee_payments WHERE status = 'completed'
    UNION ALL
    SELECT payment_date, amount FROM course_payments WHERE status = 'completed'
    UNION ALL
    SELECT payment_date, amount FROM service_revenue WHERE status = 'completed'
) combined_payments
WHERE DATE(payment_date) BETWEEN ? AND ?
GROUP BY DATE(payment_date)
ORDER BY date ASC";

$daily_trend = [];
try {
    if ($daily_stmt = $conn->prepare($daily_trend_sql)) {
        $daily_stmt->bind_param('ss', $date_from, $date_to);
        $daily_stmt->execute();
        $daily_result = $daily_stmt->get_result();
        $daily_trend = $daily_result->fetch_all(MYSQLI_ASSOC);
        $daily_stmt->close();
    }
} catch (Exception $e) {
    echo "Error executing daily trend query: " . $e->getMessage();
}

// Get payment method breakdown
$payment_method_sql = "SELECT 
    payment_method,
    COUNT(*) as count,
    SUM(amount) as total
FROM (
    SELECT payment_method, amount FROM registration_fee_payments WHERE status = 'completed' AND DATE(payment_date) BETWEEN ? AND ?
    UNION ALL
    SELECT payment_method, amount FROM course_payments WHERE status = 'completed' AND DATE(payment_date) BETWEEN ? AND ?
    UNION ALL
    SELECT payment_method, amount FROM service_revenue WHERE status = 'completed' AND DATE(payment_date) BETWEEN ? AND ?
) combined_payments
GROUP BY payment_method
ORDER BY total DESC";

$payment_methods = [];
if ($payment_stmt = $conn->prepare($payment_method_sql)) {
    $payment_stmt->bind_param('ssssss', $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
    $payment_stmt->execute();
    $payment_result = $payment_stmt->get_result();
    $payment_methods = $payment_result->fetch_all(MYSQLI_ASSOC);

    // Calculate percentages
    $total_count = array_sum(array_column($payment_methods, 'count'));
    foreach ($payment_methods as &$method) {
        $method['percentage'] = $total_count > 0 ?
            round(($method['count'] / $total_count) * 100, 1) : 0;
    }
    $payment_stmt->close();
}

// Get revenue type breakdown
$revenue_type_breakdown = [];
if ($total_revenue > 0) {
    $revenue_type_breakdown = [
        ['type' => 'Registration', 'amount' => 0, 'percentage' => 0],
        ['type' => 'Course', 'amount' => 0, 'percentage' => 0],
        ['type' => 'Service', 'amount' => 0, 'percentage' => 0]
    ];
    
    // Sum up by revenue type
    foreach ($revenue_data as $transaction) {
        if ($transaction['revenue_type'] == 'Registration Fee') {
            $revenue_type_breakdown[0]['amount'] += $transaction['amount'];
        } elseif ($transaction['revenue_type'] == 'Course Payment') {
            $revenue_type_breakdown[1]['amount'] += $transaction['amount'];
        } elseif ($transaction['revenue_type'] == 'Service Revenue') {
            $revenue_type_breakdown[2]['amount'] += $transaction['amount'];
        }
    }
    
    // Calculate percentages
    foreach ($revenue_type_breakdown as &$type) {
        $type['percentage'] = $total_revenue > 0 ?
            round(($type['amount'] / $total_revenue) * 100, 1) : 0;
    }
}

// Export to CSV if requested
if ($export_type === 'csv') {
    $export_filename = "revenue_report_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export_filename . '"');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, [
        'Date',
        'Revenue Type',
        'Program Type',
        'Program Code',
        'Program/Service Name',
        'Student/Client',
        'Amount',
        'Payment Method',
        'Transaction Reference'
    ]);

    // Write data
    foreach ($revenue_data as $row) {
        fputcsv($output, [
            $row['payment_date'] ?? '',
            $row['revenue_type'] ?? '',
            $row['program_type'] ?? '',
            $row['program_code'] ?? '',
            $row['program_name'] ?? ($row['service_category'] ?? ''),
            $row['student_name'] ?? $row['client_name'] ?? '',
            $row['amount'],
            $row['payment_method'] ?? '',
            $row['transaction_reference'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}

// Log activity
logActivity($_SESSION['user_id'], 'revenue_report_view', "Viewed revenue report for period: $date_from to $date_to");

?>
<?php
// ... [PHP code remains exactly the same until the HTML starts] ...

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Revenue Report - Finance Dashboard</title>
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
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Header */
        .mobile-header {
            display: none;
            background: var(--dark);
            color: white;
            padding: 1rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
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
            flex-wrap: wrap;
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
            font-size: 1.2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        select.form-control {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1em;
            padding-right: 2.5rem;
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
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            min-height: 44px;
            min-width: 44px;
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
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .summary-card.registration {
            border-top-color: var(--success);
        }

        .summary-card.course {
            border-top-color: var(--info);
        }

        .summary-card.service {
            border-top-color: var(--warning);
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--dark);
            word-break: break-word;
        }

        .summary-label {
            color: #64748b;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-source {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.5rem;
        }

        /* Charts Container */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            min-width: 0;
        }

        .card-header {
            padding: 1.25rem;
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
            padding: 1.25rem;
            height: 250px;
        }

        /* Data Table */
        .data-table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
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

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
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

        .badge-service {
            background: #fef3c7;
            color: #92400e;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .revenue-type-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-registration {
            background: #dcfce7;
            color: #166534;
        }

        .badge-course {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-service-type {
            background: #fef3c7;
            color: #92400e;
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
            min-height: 44px;
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

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tabs::-webkit-scrollbar {
            display: none;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #64748b;
            position: relative;
            transition: all 0.3s;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 44px;
        }

        .tab:hover {
            color: var(--primary);
        }

        .tab.active {
            color: var(--primary);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
        }

        .mobile-overlay.active {
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .mobile-header {
                display: block;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .summary-value {
                font-size: 1.25rem;
            }
            
            .header {
                padding: 1rem;
            }
            
            .page-actions {
                flex-direction: column;
            }
            
            .page-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .card-body {
                height: 200px;
                padding: 1rem;
            }
            
            .filters-card {
                padding: 1rem;
            }
        }

        @media (max-width: 576px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                gap: 0.75rem;
            }
            
            .summary-card {
                padding: 1rem;
            }
            
            .tabs {
                margin-bottom: 1rem;
            }
            
            .tab {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            th, td {
                padding: 0.5rem;
                font-size: 0.85rem;
            }
            
            .table-container {
                max-height: 400px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }
            
            .header h1 {
                font-size: 1.25rem;
            }
            
            .filters-card h3 {
                font-size: 1.1rem;
            }
            
            .btn {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .card-header h3 {
                font-size: 1rem;
            }
            
            .card-body {
                height: 180px;
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .btn {
                min-height: 48px;
                min-width: 48px;
            }
            
            .sidebar-nav a {
                min-height: 48px;
            }
            
            .tab {
                min-height: 48px;
            }
            
            select.form-control,
            input.form-control {
                min-height: 48px;
                font-size: 16px; /* Prevents iOS zoom on focus */
            }
            
            /* Larger touch targets */
            .program-badge,
            .revenue-type-badge {
                min-height: 32px;
                min-width: 32px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }

        /* Print styles */
        @media print {
            .sidebar,
            .mobile-header,
            .mobile-overlay,
            .page-actions,
            .filters-card,
            .tabs,
            .charts-grid {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            .header,
            .data-table-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .table-container {
                max-height: none;
                overflow: visible;
            }
            
            table {
                min-width: auto;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="mobile-header-content">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div>
                <h2 style="font-size: 1.2rem; margin: 0;">Revenue Report</h2>
                <small style="color: #94a3b8;">Finance Dashboard</small>
            </div>
            <div></div> <!-- Spacer -->
        </div>
    </div>

    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header" style="padding: 1.5rem; border-bottom: 1px solid var(--dark-light);">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Finance Reports</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <div class="nav-section">Financial Reports</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php" class="active">
                            <i class="fas fa-chart-bar"></i> Revenue Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/expenses.php">
                            <i class="fas fa-chart-line"></i> Expense Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/profit-loss.php">
                            <i class="fas fa-balance-scale"></i> Profit & Loss</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                            <i class="fas fa-exclamation-triangle"></i> Outstanding Report</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                            <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

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
        <div class="main-content" id="mainContent">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-chart-bar"></i>
                    Revenue Report
                </h1>
                <p style="color: #64748b; margin-top: 0.5rem; font-size: 0.95rem;">
                    Analyze revenue trends from Registration Fees, Course Payments, and Service Revenue
                </p>
                <div class="page-actions">
                    <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&program_type=<?php echo $program_type; ?>&payment_method=<?php echo $payment_method; ?>&status=<?php echo $status; ?>&revenue_type=<?php echo $revenue_type; ?>"
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
                        <label>Revenue Type</label>
                        <select name="revenue_type" class="form-control">
                            <option value="all" <?php echo $revenue_type === 'all' ? 'selected' : ''; ?>>All Revenue Types</option>
                            <option value="registration" <?php echo $revenue_type === 'registration' ? 'selected' : ''; ?>>Registration Fees Only</option>
                            <option value="course" <?php echo $revenue_type === 'course' ? 'selected' : ''; ?>>Course Payments Only</option>
                            <option value="service" <?php echo $revenue_type === 'service' ? 'selected' : ''; ?>>Service Revenue Only</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Program Type</label>
                        <select name="program_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                            <option value="service" <?php echo $program_type === 'service' ? 'selected' : ''; ?>>Service</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="all">All Methods</option>
                            <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="cheque" <?php echo $payment_method === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="background: #e2e8f0; color: var(--dark);">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-value">
                        ₦<?php echo number_format($total_revenue, 2); ?>
                    </div>
                    <div class="summary-label">Total Revenue</div>
                    <div class="summary-source">
                        <?php echo number_format($total_transactions); ?> transactions
                    </div>
                </div>

                <div class="summary-card registration">
                    <div class="summary-value">
                        ₦<?php 
                            $registration_total = 0;
                            foreach ($program_revenues_display as $program) {
                                $registration_total += $program['registration_revenue'];
                            }
                            echo number_format($registration_total, 2); 
                        ?>
                    </div>
                    <div class="summary-label">Registration Fees</div>
                    <div class="summary-source">
                        From registration_fee_payments
                    </div>
                </div>

                <div class="summary-card course">
                    <div class="summary-value">
                        ₦<?php 
                            $course_total = 0;
                            foreach ($program_revenues_display as $program) {
                                $course_total += $program['course_revenue'];
                            }
                            echo number_format($course_total, 2); 
                        ?>
                    </div>
                    <div class="summary-label">Course Payments</div>
                    <div class="summary-source">
                        From course_payments
                    </div>
                </div>

                <div class="summary-card service">
                    <div class="summary-value">
                        ₦<?php 
                            $service_total = 0;
                            foreach ($program_revenues_display as $program) {
                                $service_total += $program['service_revenue'];
                            }
                            echo number_format($service_total, 2); 
                        ?>
                    </div>
                    <div class="summary-label">Service Revenue</div>
                    <div class="summary-source">
                        From service_revenue
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Revenue by Program Type -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Revenue by Program Type</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="programTypeChart"></canvas>
                    </div>
                </div>

                <!-- Daily Revenue Trend -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Daily Revenue Trend</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Revenue Type Breakdown -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Revenue Type Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueTypeChart"></canvas>
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
            </div>

            <!-- Tabs for different views -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('programs')">Program Summary</button>
                <button class="tab" onclick="switchTab('transactions')">Detailed Transactions</button>
            </div>

            <!-- Program Revenue Summary -->
            <div id="programsTab" class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-table"></i> Revenue by Program/Service</h3>
                    <div style="color: #64748b; font-size: 0.85rem; margin-top: 0.25rem;">
                        Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Program/Service</th>
                                <th>Type</th>
                                <th>Transactions</th>
                                <th>Registration Fees</th>
                                <th>Course Payments</th>
                                <th>Service Revenue</th>
                                <th>Total Revenue</th>
                                <th>Average</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($program_revenues_display)): ?>
                                <?php foreach ($program_revenues_display as $row):
                                    $percentage = $total_revenue > 0 ? ($row['total_amount'] / $total_revenue * 100) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($row['program_name']); ?></div>
                                            <small style="color: #64748b; font-size: 0.8rem;"><?php echo $row['program_code']; ?></small>
                                        </td>
                                        <td>
                                            <span class="program-badge badge-<?php echo $row['program_type']; ?>">
                                                <?php echo $row['program_type']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $row['transaction_count']; ?></td>
                                        <td class="amount">₦<?php echo number_format($row['registration_revenue'], 2); ?></td>
                                        <td class="amount">₦<?php echo number_format($row['course_revenue'], 2); ?></td>
                                        <td class="amount">₦<?php echo number_format($row['service_revenue'], 2); ?></td>
                                        <td class="amount" style="font-weight: 700;">₦<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td>₦<?php echo number_format($row['avg_amount'] ?? 0, 2); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?php echo $percentage; ?>%; height: 100%; background: var(--primary);"></div>
                                                </div>
                                                <span style="font-size: 0.8rem;"><?php echo number_format($percentage, 1); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: #64748b;">
                                        <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3 style="font-size: 1.1rem;">No Revenue Data</h3>
                                        <p style="font-size: 0.9rem;">No revenue transactions found for the selected filters.</p>
                                        <?php if (!empty($program_type) || !empty($payment_method) || $revenue_type !== 'all'): ?>
                                            <p style="margin-top: 1rem;">
                                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-primary" style="font-size: 0.9rem; padding: 0.5rem 1rem;">
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
                <?php if (!empty($program_revenues_display)): ?>
                    <div style="padding: 1rem; border-top: 1px solid #e2e8f0; background: #f8fafc; text-align: center;">
                        <strong>Total Revenue: ₦<?php echo number_format($total_revenue, 2); ?></strong>
                        <span style="color: #64748b; margin-left: 0.5rem; font-size: 0.9rem; display: block; margin-top: 0.25rem;">
                            <?php echo number_format($total_transactions); ?> transactions
                            across <?php echo count($program_revenues_display); ?> programs/services
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Detailed Transactions -->
            <div id="transactionsTab" class="data-table-card" style="display: none;">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Detailed Transactions</h3>
                    <div style="color: #64748b; font-size: 0.85rem; margin-top: 0.25rem;">
                        Showing <?php echo count($revenue_data); ?> transactions
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Program/Service</th>
                                <th>Student/Client</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($revenue_data)): ?>
                                <?php foreach ($revenue_data as $row): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($row['payment_date'] ?? '')); ?></td>
                                        <td>
                                            <span class="revenue-type-badge badge-<?php 
                                                echo $row['revenue_type'] == 'Registration Fee' ? 'registration' : 
                                                    ($row['revenue_type'] == 'Course Payment' ? 'course' : 'service-type'); 
                                            ?>">
                                                <?php echo $row['revenue_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($row['revenue_type'] == 'Service Revenue'): ?>
                                                <?php echo htmlspecialchars($row['service_category'] ?? 'Service'); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($row['program_name'] ?? ''); ?>
                                                <small style="color: #64748b; display: block; font-size: 0.8rem;">
                                                    <?php echo $row['program_code'] ?? ''; ?>
                                                    <?php if (isset($row['course_title'])): ?>
                                                        <br><?php echo $row['course_title']; ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['student_name'] ?? $row['client_name'] ?? ''); ?>
                                            <small style="color: #64748b; display: block; font-size: 0.8rem;">
                                                <?php echo $row['student_email'] ?? ''; ?>
                                            </small>
                                        </td>
                                        <td class="amount">₦<?php echo number_format($row['amount'], 2); ?></td>
                                        <td>
                                            <?php echo ucfirst(str_replace('_', ' ', $row['payment_method'] ?? '')); ?>
                                        </td>
                                        <td>
                                            <small style="color: #64748b; font-size: 0.8rem;"><?php echo $row['transaction_reference'] ?? ''; ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: #64748b;">
                                        <i class="fas fa-receipt" style="font-size: 2rem; margin-bottom: 1rem; color: #cbd5e1;"></i>
                                        <h3 style="font-size: 1.1rem;">No Transactions Found</h3>
                                        <p style="font-size: 0.9rem;">No detailed transactions found for the selected filters.</p>
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
        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const mainContent = document.getElementById('mainContent');
        
        function toggleSidebar() {
            sidebar.classList.toggle('active');
            mobileOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }
        
        menuToggle.addEventListener('click', toggleSidebar);
        mobileOverlay.addEventListener('click', toggleSidebar);
        
        // Close sidebar when clicking on a link (mobile)
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 1200) {
                    toggleSidebar();
                }
            });
        });
        
        // Close sidebar on window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 1200) {
                sidebar.classList.remove('active');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Toggle date range visibility
        document.getElementById('periodSelect').addEventListener('change', function(e) {
            const dateRangeGroup = document.getElementById('dateRangeGroup');
            dateRangeGroup.style.display = e.target.value === 'custom' ? 'block' : 'none';
        });

        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            disableMobile: true // Use native date picker on mobile
        });

        // Tab switching
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show/hide tab content
            document.getElementById('programsTab').style.display = tabName === 'programs' ? 'block' : 'none';
            document.getElementById('transactionsTab').style.display = tabName === 'transactions' ? 'block' : 'none';
            
            // Scroll to top of table on mobile
            if (window.innerWidth <= 768) {
                document.getElementById(tabName + 'Tab').scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        // Chart data preparation
        <?php
        // Prepare data for Program Type Chart
        $programTypeData = [];
        $programTypeLabels = [];
        $programTypeColors = [];

        $onlineRevenue = 0;
        $onsiteRevenue = 0;
        $serviceRevenue = 0;

        foreach ($program_revenues_display as $row) {
            if ($row['program_type'] === 'online') {
                $onlineRevenue += $row['total_amount'];
            } elseif ($row['program_type'] === 'onsite') {
                $onsiteRevenue += $row['total_amount'];
            } else {
                $serviceRevenue += $row['total_amount'];
            }
        }

        if ($onlineRevenue > 0) {
            $programTypeData[] = $onlineRevenue;
            $programTypeLabels[] = 'Online';
            $programTypeColors[] = '#3b82f6';
        }

        if ($onsiteRevenue > 0) {
            $programTypeData[] = $onsiteRevenue;
            $programTypeLabels[] = 'Onsite';
            $programTypeColors[] = '#10b981';
        }

        if ($serviceRevenue > 0) {
            $programTypeData[] = $serviceRevenue;
            $programTypeLabels[] = 'Services';
            $programTypeColors[] = '#f59e0b';
        }

        // Daily trend data
        $dailyLabels = [];
        $dailyData = [];
        foreach ($daily_trend as $day) {
            $dailyLabels[] = date('M j', strtotime($day['date']));
            $dailyData[] = $day['daily_revenue'];
        }

        // Payment method data
        $paymentLabels = [];
        $paymentData = [];
        $paymentColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
        foreach ($payment_methods as $index => $method) {
            $paymentLabels[] = ucfirst(str_replace('_', ' ', $method['payment_method']));
            $paymentData[] = $method['total'];
        }

        // Revenue type data
        $revenueTypeLabels = [];
        $revenueTypeData = [];
        $revenueTypeColors = ['#10b981', '#3b82f6', '#f59e0b'];
        
        if (!empty($revenue_type_breakdown)) {
            foreach ($revenue_type_breakdown as $type) {
                if ($type['amount'] > 0) {
                    $revenueTypeLabels[] = $type['type'];
                    $revenueTypeData[] = $type['amount'];
                }
            }
        }
        ?>

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile-friendly chart options
            const mobileOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: window.innerWidth <= 768 ? 'bottom' : 'right',
                        labels: {
                            boxWidth: window.innerWidth <= 768 ? 12 : 20,
                            font: {
                                size: window.innerWidth <= 768 ? 10 : 12
                            }
                        }
                    },
                    tooltip: {
                        titleFont: {
                            size: window.innerWidth <= 768 ? 11 : 12
                        },
                        bodyFont: {
                            size: window.innerWidth <= 768 ? 11 : 12
                        },
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
            };

            // Program Type Chart
            const programTypeCtx = document.getElementById('programTypeChart');
            if (programTypeCtx && <?php echo json_encode($programTypeData); ?>.length > 0) {
                new Chart(programTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: <?php echo json_encode($programTypeLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($programTypeData); ?>,
                            backgroundColor: <?php echo json_encode($programTypeColors); ?>,
                            borderWidth: 1
                        }]
                    },
                    options: mobileOptions
                });
            } else if (programTypeCtx) {
                // Show no data message
                programTypeCtx.parentElement.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; text-align: center; padding: 1rem;">
                        <i class="fas fa-chart-pie" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p style="font-size: 0.9rem;">No program data available</p>
                    </div>
                `;
            }

            // Daily Trend Chart
            const dailyTrendCtx = document.getElementById('dailyTrendChart');
            if (dailyTrendCtx && <?php echo json_encode($dailyData); ?>.length > 0) {
                new Chart(dailyTrendCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($dailyLabels); ?>,
                        datasets: [{
                            label: 'Daily Revenue',
                            data: <?php echo json_encode($dailyData); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        ...mobileOptions,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₦' + value.toLocaleString('en-US');
                                    },
                                    font: {
                                        size: window.innerWidth <= 768 ? 10 : 12
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: window.innerWidth <= 768 ? 10 : 12
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (dailyTrendCtx) {
                dailyTrendCtx.parentElement.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; text-align: center; padding: 1rem;">
                        <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p style="font-size: 0.9rem;">No daily trend data available</p>
                    </div>
                `;
            }

            // Revenue Type Chart
            const revenueTypeCtx = document.getElementById('revenueTypeChart');
            if (revenueTypeCtx && <?php echo json_encode($revenueTypeData); ?>.length > 0) {
                new Chart(revenueTypeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($revenueTypeLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($revenueTypeData); ?>,
                            backgroundColor: <?php echo json_encode($revenueTypeColors); ?>,
                            borderWidth: 1
                        }]
                    },
                    options: mobileOptions
                });
            } else if (revenueTypeCtx) {
                revenueTypeCtx.parentElement.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; text-align: center; padding: 1rem;">
                        <i class="fas fa-chart-pie" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p style="font-size: 0.9rem;">No revenue type data available</p>
                    </div>
                `;
            }

            // Payment Method Chart
            const paymentMethodCtx = document.getElementById('paymentMethodChart');
            if (paymentMethodCtx && <?php echo json_encode($paymentData); ?>.length > 0) {
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
                    options: mobileOptions
                });
            } else if (paymentMethodCtx) {
                paymentMethodCtx.parentElement.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #64748b; text-align: center; padding: 1rem;">
                        <i class="fas fa-credit-card" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p style="font-size: 0.9rem;">No payment method data available</p>
                    </div>
                `;
            }
        });

        // Update charts on window resize
        window.addEventListener('resize', function() {
            // Charts automatically handle resizing with responsive: true
        });

        // Auto-refresh report every 5 minutes (only on desktop)
        if (window.innerWidth > 768) {
            setInterval(() => {
                if (!document.hidden) {
                    window.location.reload();
                }
            }, 300000);
        }
    </script>
</body>

</html>

<?php $conn->close(); ?>