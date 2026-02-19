<?php
// modules/admin/finance/reports/profit-loss.php

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
$report_format = $_GET['format'] ?? 'detailed'; // detailed, summary, consolidated

// Set default dates based on period
if ($period === 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-7 days'));
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
} elseif ($period === 'quarter') {
    $quarter = ceil(date('n') / 3);
    $month_start = (($quarter - 1) * 3) + 1;
    $date_from = date('Y-' . str_pad($month_start, 2, '0', STR_PAD_LEFT) . '-01');
} elseif ($period === 'year') {
    $date_from = date('Y-01-01');
} elseif ($period === 'all') {
    $date_from = '2024-01-01'; // Or earliest date in your system
}

// Get revenue data using the existing function
$revenue_breakdown = getRevenueBreakdown($period, $date_from, $date_to);

// Get expense data using the existing function
$expense_stats = getExpenseDashboardStats($period, $date_from, $date_to);

// With this direct query approach:
// Calculate registration fees
$registration_sql = "SELECT 
    SUM(amount) as total_registration
FROM registration_fee_payments 
WHERE status = 'completed' 
    AND DATE(payment_date) BETWEEN ? AND ?";

$registration_revenue = 0;
if ($stmt = $conn->prepare($registration_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $registration_revenue = $row['total_registration'] ?? 0;
    }
    $stmt->close();
}

// Calculate course/tuition fees
$course_sql = "SELECT 
    SUM(amount) as total_course
FROM course_payments 
WHERE status = 'completed' 
    AND DATE(payment_date) BETWEEN ? AND ?";

$course_revenue = 0;
if ($stmt = $conn->prepare($course_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $course_revenue = $row['total_course'] ?? 0;
    }
    $stmt->close();
}

// Calculate service revenue
$service_sql = "SELECT 
    SUM(amount) as total_service
FROM service_revenue 
WHERE status = 'completed' 
    AND DATE(payment_date) BETWEEN ? AND ?";

$service_revenue = 0;
if ($stmt = $conn->prepare($service_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $service_revenue = $row['total_service'] ?? 0;
    }
    $stmt->close();
}

// Calculate total revenue
$total_revenue = $registration_revenue + $course_revenue + $service_revenue;

// Create revenue breakdown array
$revenue_breakdown = [
    'total_revenue' => $total_revenue,
    'total_registration' => $registration_revenue,
    'total_course' => $course_revenue,
    'service_revenue' => $service_revenue
];

// Calculate totals
$total_revenue = $revenue_breakdown['total_revenue'] ?? 0;
$total_registration_revenue = $revenue_breakdown['total_registration'] ?? 0;
$total_course_revenue = $revenue_breakdown['total_course'] ?? 0;
$total_expenses = $expense_stats['total_expenses'] ?? 0;
$total_pending_expenses = $expense_stats['pending_expenses'] ?? 0;

// Calculate net profit/loss
$net_profit_loss = $total_revenue - $total_expenses;
$profit_margin = $total_revenue > 0 ? ($net_profit_loss / $total_revenue * 100) : 0;

// Get automated deductions (tithe and reserve)
$automated_deductions = calculateAutomatedDeductions($period, $date_from, $date_to);

// Get expense breakdown by category
$expense_breakdown = $expense_stats['breakdown'] ?? [];

// Get revenue by program type
$revenue_by_program_sql = "SELECT 
    p.program_type,
    COUNT(DISTINCT ft.id) as transaction_count,
    SUM(ft.amount) as total_revenue,
    COUNT(DISTINCT ft.student_id) as unique_students
FROM financial_transactions ft
JOIN class_batches cb ON cb.id = ft.class_id
JOIN courses c ON c.id = cb.course_id
JOIN programs p ON p.program_code = c.program_id
WHERE ft.status = 'completed' 
    AND DATE(ft.created_at) BETWEEN ? AND ?
    AND ft.transaction_type IN ('registration', 'tuition')
GROUP BY p.program_type
ORDER BY total_revenue DESC";

$revenue_by_program = [];
if ($stmt = $conn->prepare($revenue_by_program_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    $revenue_by_program = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get monthly trend for the last 12 months
$monthly_trend_sql = "SELECT 
    DATE_FORMAT(payment_date, '%Y-%m') as month,
    'revenue' as type,
    SUM(amount) as amount
FROM (
    SELECT payment_date, amount FROM registration_fee_payments WHERE status = 'completed'
    UNION ALL
    SELECT payment_date, amount FROM course_payments WHERE status = 'completed'
    UNION ALL
    SELECT payment_date, amount FROM service_revenue WHERE status = 'completed'
) combined_revenue
WHERE payment_date BETWEEN DATE_SUB(?, INTERVAL 11 MONTH) AND ?
GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
ORDER BY month";

$monthly_revenue = [];
if ($stmt = $conn->prepare($monthly_trend_sql)) {
    $start_date = date('Y-m-d', strtotime('-11 months', strtotime($date_from)));
    $stmt->bind_param('ss', $start_date, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthly_revenue[$row['month']] = $row['amount'];
    }
    $stmt->close();
}

// Get monthly expenses
$monthly_expenses_sql = "SELECT 
    DATE_FORMAT(payment_date, '%Y-%m') as month,
    SUM(amount) as amount
FROM expenses
WHERE status IN ('approved', 'paid')
    AND payment_date BETWEEN DATE_SUB(?, INTERVAL 11 MONTH) AND ?
GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
ORDER BY month";

$monthly_expenses = [];
if ($stmt = $conn->prepare($monthly_expenses_sql)) {
    $start_date = date('Y-m-d', strtotime('-11 months', strtotime($date_from)));
    $stmt->bind_param('ss', $start_date, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthly_expenses[$row['month']] = $row['amount'];
    }
    $stmt->close();
}

// Get top 10 expenses
$top_expenses_sql = "SELECT 
    e.expense_number,
    ec.name as category,
    ec.category_type,
    e.description,
    e.amount,
    e.payment_date,
    e.vendor_name,
    CONCAT(u.first_name, ' ', u.last_name) as approved_by
FROM expenses e
JOIN expense_categories ec ON ec.id = e.category_id
LEFT JOIN users u ON u.id = e.approved_by
WHERE e.status IN ('approved', 'paid')
    AND e.payment_date BETWEEN ? AND ?
ORDER BY e.amount DESC
LIMIT 10";

$top_expenses = [];
if ($stmt = $conn->prepare($top_expenses_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_expenses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get top revenue sources (students/programs)
$top_revenue_sources_sql = "SELECT 
    u.first_name,
    u.last_name,
    u.email,
    p.name as program_name,
    COUNT(DISTINCT ft.id) as transaction_count,
    SUM(ft.amount) as total_paid,
    MAX(ft.created_at) as last_payment_date
FROM financial_transactions ft
JOIN users u ON u.id = ft.student_id
LEFT JOIN class_batches cb ON cb.id = ft.class_id
LEFT JOIN courses c ON c.id = cb.course_id
LEFT JOIN programs p ON p.program_code = c.program_id
WHERE ft.status = 'completed'
    AND DATE(ft.created_at) BETWEEN ? AND ?
    AND ft.transaction_type IN ('registration', 'tuition')
GROUP BY ft.student_id, p.id
ORDER BY total_paid DESC
LIMIT 10";

$top_revenue_sources = [];
if ($stmt = $conn->prepare($top_revenue_sources_sql)) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    $top_revenue_sources = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Calculate expense ratios
$expense_ratios = [];
foreach ($expense_breakdown as $category) {
    if ($total_revenue > 0) {
        $ratio = ($category['total_amount'] / $total_revenue) * 100;
        $expense_ratios[] = [
            'category' => $category['name'],
            'amount' => $category['total_amount'],
            'ratio' => $ratio,
            'type' => $category['category_type']
        ];
    }
}

// Export to CSV if requested
if ($export_type === 'csv') {
    $export_filename = "profit_loss_report_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export_filename . '"');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    // Write report header
    fputcsv($output, ["IMPACT DIGITAL ACADEMY - PROFIT & LOSS STATEMENT"]);
    fputcsv($output, ["Period: " . date('F j, Y', strtotime($date_from)) . " to " . date('F j, Y', strtotime($date_to))]);
    fputcsv($output, ["Generated: " . date('F j, Y, h:i A')]);
    fputcsv($output, [""]);

    // Write revenue section
    fputcsv($output, ["REVENUE"]);
    fputcsv($output, ["Description", "Amount", "Percentage"]);
    
    $revenue_percentage = $total_revenue > 0 ? 100 : 0;
    fputcsv($output, ["Registration Fees", formatCurrency($total_registration_revenue, false), number_format($total_registration_revenue/$total_revenue*100, 1) . "%"]);
    fputcsv($output, ["Course/Tuition Fees", formatCurrency($total_course_revenue, false), number_format($total_course_revenue/$total_revenue*100, 1) . "%"]);
    fputcsv($output, ["Service Revenue", formatCurrency($revenue_breakdown['service_revenue'] ?? 0, false), number_format(($revenue_breakdown['service_revenue'] ?? 0)/$total_revenue*100, 1) . "%"]);
    fputcsv($output, ["", "", ""]);
    fputcsv($output, ["TOTAL REVENUE", formatCurrency($total_revenue, false), "100.0%"]);
    fputcsv($output, [""]);

    // Write expense section
    fputcsv($output, ["EXPENSES"]);
    fputcsv($output, ["Category", "Amount", "As % of Revenue"]);
    
    foreach ($expense_breakdown as $expense) {
        $percentage = $total_revenue > 0 ? ($expense['total_amount'] / $total_revenue * 100) : 0;
        fputcsv($output, [$expense['name'], formatCurrency($expense['total_amount'], false), number_format($percentage, 1) . "%"]);
    }
    fputcsv($output, ["", "", ""]);
    fputcsv($output, ["TOTAL EXPENSES", formatCurrency($total_expenses, false), number_format(($total_expenses/$total_revenue*100), 1) . "%"]);
    fputcsv($output, [""]);

    // Write net profit/loss
    fputcsv($output, ["NET PROFIT/LOSS", formatCurrency($net_profit_loss, false), number_format($profit_margin, 1) . "%"]);
    fputcsv($output, [""]);

    // Write automated deductions
    if (!empty($automated_deductions)) {
        fputcsv($output, ["AUTOMATED DEDUCTIONS"]);
        fputcsv($output, ["Type", "Amount", "Based on Revenue"]);
        foreach ($automated_deductions as $deduction) {
            fputcsv($output, [
                ucfirst($deduction['type']),
                formatCurrency($deduction['amount'], false),
                formatCurrency($deduction['based_on_revenue'], false)
            ]);
        }
        fputcsv($output, [""]);
    }

    // Write top expenses
    fputcsv($output, ["TOP 10 EXPENSES"]);
    fputcsv($output, ["Expense #", "Category", "Description", "Amount", "Date", "Vendor"]);
    foreach ($top_expenses as $expense) {
        fputcsv($output, [
            $expense['expense_number'],
            $expense['category'],
            $expense['description'],
            formatCurrency($expense['amount'], false),
            $expense['payment_date'],
            $expense['vendor_name']
        ]);
    }
    fputcsv($output, [""]);

    // Write audit trail
    fputcsv($output, ["AUDIT INFORMATION"]);
    fputcsv($output, ["Report Generated By", $_SESSION['user_name'] ?? 'System']);
    fputcsv($output, ["Generation Time", date('Y-m-d H:i:s')]);
    fputcsv($output, ["IP Address", $_SERVER['REMOTE_ADDR'] ?? 'N/A']);

    fclose($output);
    exit();
}

// Export to PDF if requested
if ($export_type === 'pdf') {
    // In a real implementation, you would use TCPDF, FPDF, or Dompdf
    // For now, we'll redirect to a PDF generation script
    $pdf_url = BASE_URL . "modules/admin/finance/reports/generate_pdf.php?" . http_build_query($_GET);
    header("Location: $pdf_url");
    exit();
}

// Log activity
logActivity($_SESSION['user_id'], 'profit_loss_report_view', "Viewed profit & loss report for period: $date_from to $date_to");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement - Finance Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
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
            border-left: 5px solid var(--success);
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--success);
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
            text-decoration: none;
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

        .btn-success:hover {
            background: #0da271;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        /* Profit/Loss Summary */
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

        .summary-card.revenue {
            border-top-color: var(--success);
        }

        .summary-card.expenses {
            border-top-color: var(--danger);
        }

        .summary-card.profit {
            border-top-color: var(--success);
        }

        .summary-card.loss {
            border-top-color: var(--danger);
        }

        .summary-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .summary-card.revenue .summary-value {
            color: var(--success);
        }

        .summary-card.expenses .summary-value {
            color: var(--danger);
        }

        .summary-card.profit .summary-value {
            color: var(--success);
        }

        .summary-card.loss .summary-value {
            color: var(--danger);
        }

        .summary-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Profit/Loss Statement */
        .statement-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .statement-section {
            padding: 1.5rem;
        }

        .statement-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .statement-row.total {
            font-weight: bold;
            border-bottom: 2px solid #e2e8f0;
        }

        .statement-row.net {
            background: #f8fafc;
            font-size: 1.1rem;
            font-weight: bold;
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 6px;
        }

        .statement-row.net.profit {
            background: var(--success-light);
            color: var(--success);
        }

        .statement-row.net.loss {
            background: var(--danger-light);
            color: var(--danger);
        }

        .statement-label {
            flex: 1;
        }

        .statement-amount {
            width: 150px;
            text-align: right;
            font-weight: 500;
        }

        .statement-percentage {
            width: 100px;
            text-align: right;
            color: #64748b;
            font-size: 0.9rem;
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

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .positive {
            color: var(--success);
        }

        .negative {
            color: var(--danger);
        }

        /* Audit Info */
        .audit-info {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 2rem;
        }

        .audit-info p {
            margin: 0.25rem 0;
        }

        /* Print styles */
        @media print {
            .sidebar,
            .filters-card,
            .page-actions,
            .audit-info {
                display: none;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            .statement-card,
            .summary-card,
            .chart-card,
            .data-table-card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }
            
            .header {
                border: none;
                box-shadow: none;
            }
        }

        /* Sidebar navigation */
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
            border-left: 4px solid var(--success);
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
            
            .statement-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .statement-amount,
            .statement-percentage {
                width: 100%;
                text-align: left;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .page-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/expenses.php">
                            <i class="fas fa-chart-line"></i> Expense Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/profit-loss.php" class="active">
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
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h1>
                            <i class="fas fa-balance-scale"></i>
                            Profit & Loss Statement
                        </h1>
                        <p style="color: #64748b; margin-top: 0.5rem;">
                            Financial performance analysis for audit and review
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.9rem; color: #64748b;">Period:</div>
                        <div style="font-weight: 600;"><?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></div>
                    </div>
                </div>
                
                <div class="page-actions">
                    <a href="?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&program_type=<?php echo $program_type; ?>"
                        class="btn btn-success">
                        <i class="fas fa-file-export"></i> Export to CSV
                    </a>
                    <a href="?export=pdf&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&program_type=<?php echo $program_type; ?>"
                        class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Export to PDF
                    </a>
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="background: #e2e8f0; color: var(--dark);">
                        <i class="fas fa-redo"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Report Parameters</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Time Period</label>
                        <select name="period" class="form-control" id="periodSelect">
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
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
                        <label>Report Format</label>
                        <select name="format" class="form-control">
                            <option value="detailed" <?php echo $report_format === 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                            <option value="summary" <?php echo $report_format === 'summary' ? 'selected' : ''; ?>>Summary</option>
                            <option value="consolidated" <?php echo $report_format === 'consolidated' ? 'selected' : ''; ?>>Consolidated</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Generate Report
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn" style="background: #e2e8f0; color: var(--dark);">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Profit/Loss Summary -->
            <div class="summary-grid">
                <div class="summary-card revenue">
                    <div class="summary-value">₦<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="summary-label">Total Revenue</div>
                </div>

                <div class="summary-card expenses">
                    <div class="summary-value">₦<?php echo number_format($total_expenses, 2); ?></div>
                    <div class="summary-label">Total Expenses</div>
                </div>

                <div class="summary-card <?php echo $net_profit_loss >= 0 ? 'profit' : 'loss'; ?>">
                    <div class="summary-value">₦<?php echo number_format(abs($net_profit_loss), 2); ?></div>
                    <div class="summary-label">
                        <?php echo $net_profit_loss >= 0 ? 'Net Profit' : 'Net Loss'; ?>
                        <br>
                        <small><?php echo number_format(abs($profit_margin), 1); ?>% Margin</small>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-value">₦<?php echo number_format($total_pending_expenses, 2); ?></div>
                    <div class="summary-label">Pending Expenses</div>
                </div>
            </div>

            <!-- Profit & Loss Statement -->
            <div class="statement-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> PROFIT & LOSS STATEMENT</h3>
                </div>
                
                <div class="statement-section">
                    <!-- Revenue Section -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="color: var(--success); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-money-bill-wave"></i> REVENUE
                        </h4>
                        
                        <div class="statement-row">
                            <div class="statement-label">Registration Fees</div>
                            <div class="statement-amount">₦<?php echo number_format($total_registration_revenue, 2); ?></div>
                            <div class="statement-percentage">
                                <?php echo $total_revenue > 0 ? number_format($total_registration_revenue/$total_revenue*100, 1) : '0.0'; ?>%
                            </div>
                        </div>
                        
                        <div class="statement-row">
                            <div class="statement-label">Course/Tuition Fees</div>
                            <div class="statement-amount">₦<?php echo number_format($total_course_revenue, 2); ?></div>
                            <div class="statement-percentage">
                                <?php echo $total_revenue > 0 ? number_format($total_course_revenue/$total_revenue*100, 1) : '0.0'; ?>%
                            </div>
                        </div>
                        
                        <div class="statement-row">
                            <div class="statement-label">Service Revenue</div>
                            <div class="statement-amount">₦<?php echo number_format($revenue_breakdown['service_revenue'] ?? 0, 2); ?></div>
                            <div class="statement-percentage">
                                <?php echo $total_revenue > 0 ? number_format(($revenue_breakdown['service_revenue'] ?? 0)/$total_revenue*100, 1) : '0.0'; ?>%
                            </div>
                        </div>
                        
                        <div class="statement-row total">
                            <div class="statement-label">TOTAL REVENUE</div>
                            <div class="statement-amount">₦<?php echo number_format($total_revenue, 2); ?></div>
                            <div class="statement-percentage">100.0%</div>
                        </div>
                    </div>
                    
                    <!-- Expenses Section -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="color: var(--danger); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-shopping-cart"></i> EXPENSES
                        </h4>
                        
                        <?php foreach ($expense_breakdown as $expense): 
                            $percentage = $total_revenue > 0 ? ($expense['total_amount'] / $total_revenue * 100) : 0;
                        ?>
                        <div class="statement-row">
                            <div class="statement-label"><?php echo htmlspecialchars($expense['name']); ?></div>
                            <div class="statement-amount">₦<?php echo number_format($expense['total_amount'], 2); ?></div>
                            <div class="statement-percentage"><?php echo number_format($percentage, 1); ?>%</div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="statement-row total">
                            <div class="statement-label">TOTAL EXPENSES</div>
                            <div class="statement-amount">₦<?php echo number_format($total_expenses, 2); ?></div>
                            <div class="statement-percentage">
                                <?php echo $total_revenue > 0 ? number_format(($total_expenses/$total_revenue*100), 1) : '0.0'; ?>%
                            </div>
                        </div>
                    </div>
                    
                    <!-- Net Profit/Loss -->
                    <div class="statement-row net <?php echo $net_profit_loss >= 0 ? 'profit' : 'loss'; ?>">
                        <div class="statement-label">
                            <?php if ($net_profit_loss >= 0): ?>
                                <i class="fas fa-arrow-up"></i> NET PROFIT
                            <?php else: ?>
                                <i class="fas fa-arrow-down"></i> NET LOSS
                            <?php endif; ?>
                        </div>
                        <div class="statement-amount">₦<?php echo number_format(abs($net_profit_loss), 2); ?></div>
                        <div class="statement-percentage"><?php echo number_format(abs($profit_margin), 1); ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Revenue vs Expenses Trend -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Revenue vs Expenses Trend (Last 12 Months)</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                <!-- Expense Breakdown -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Expense Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="expenseChart"></canvas>
                    </div>
                </div>

                <!-- Profit Margin Trend -->
                <div class="chart-card">
                    <div class="card-header">
                        <h3><i class="fas fa-percentage"></i> Profit Margin Trend</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="marginChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Expenses Table -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-ol"></i> Top 10 Expenses</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Expense #</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th>Approved By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_expenses)): ?>
                                <?php foreach ($top_expenses as $expense): ?>
                                    <tr>
                                        <td><?php echo $expense['expense_number']; ?></td>
                                        <td>
                                            <span style="display: inline-block; padding: 0.25rem 0.5rem; background: #e2e8f0; border-radius: 4px; font-size: 0.8rem;">
                                                <?php echo $expense['category']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($expense['description'], 0, 50)) . (strlen($expense['description']) > 50 ? '...' : ''); ?></td>
                                        <td class="amount negative">₦<?php echo number_format($expense['amount'], 2); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($expense['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($expense['vendor_name']); ?></td>
                                        <td><?php echo $expense['approved_by']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem; color: #64748b;">
                                        No expenses found for the selected period.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Revenue Sources -->
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Top Revenue Sources</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Transactions</th>
                                <th>Total Paid</th>
                                <th>Last Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_revenue_sources)): ?>
                                <?php foreach ($top_revenue_sources as $source): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($source['first_name'] . ' ' . $source['last_name']); ?></strong>
                                            <br>
                                            <small><?php echo htmlspecialchars($source['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($source['program_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $source['transaction_count']; ?></td>
                                        <td class="amount positive">₦<?php echo number_format($source['total_paid'], 2); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($source['last_payment_date'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                                        No revenue data found for the selected period.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Automated Deductions -->
            <?php if (!empty($automated_deductions)): ?>
            <div class="data-table-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot"></i> Automated Deductions</h3>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Based on Revenue</th>
                                <th>Expense #</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($automated_deductions as $deduction): ?>
                                <tr>
                                    <td>
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: #e2e8f0; border-radius: 4px; font-size: 0.8rem;">
                                            <?php echo ucfirst($deduction['type']); ?>
                                        </span>
                                    </td>
                                    <td class="amount">₦<?php echo number_format($deduction['amount'], 2); ?></td>
                                    <td>₦<?php echo number_format($deduction['based_on_revenue'], 2); ?></td>
                                    <td><?php echo $deduction['expense_number']; ?></td>
                                    <td>
                                        <?php 
                                            $deduction_percentage = $deduction['based_on_revenue'] > 0 ? 
                                                ($deduction['amount'] / $deduction['based_on_revenue'] * 100) : 0;
                                            echo number_format($deduction_percentage, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Audit Information -->
            <div class="audit-info">
                <h4><i class="fas fa-clipboard-check"></i> Audit Information</h4>
                <p><strong>Report Generated By:</strong> <?php echo $_SESSION['user_name'] ?? 'System'; ?></p>
                <p><strong>Generation Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                <p><strong>Period Covered:</strong> <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>
                <p><strong>Data Source:</strong> Financial Transactions & Expense Records</p>
                <p><strong>Notes:</strong> This report is generated for audit and review purposes. All amounts are in NGN.</p>
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
        // Prepare monthly trend data
        $months = [];
        $revenue_data_chart = [];
        $expense_data_chart = [];
        $profit_margin_chart = [];
        
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months", strtotime($date_to)));
            $months[] = date('M Y', strtotime($month . '-01'));
            $revenue_data_chart[] = $monthly_revenue[$month] ?? 0;
            $expense_data_chart[] = $monthly_expenses[$month] ?? 0;
            
            $revenue = $monthly_revenue[$month] ?? 0;
            $expense = $monthly_expenses[$month] ?? 0;
            $profit = $revenue - $expense;
            $margin = $revenue > 0 ? ($profit / $revenue * 100) : 0;
            $profit_margin_chart[] = $margin;
        }

        // Prepare expense breakdown data
        $expense_labels = [];
        $expense_amounts = [];
        $expense_colors = [
            '#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6',
            '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#84cc16'
        ];
        
        foreach ($expense_breakdown as $index => $expense) {
            $expense_labels[] = $expense['name'];
            $expense_amounts[] = $expense['total_amount'];
        }
        ?>

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Trend Chart
            const trendCtx = document.getElementById('trendChart');
            if (trendCtx) {
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($months); ?>,
                        datasets: [
                            {
                                label: 'Revenue',
                                data: <?php echo json_encode($revenue_data_chart); ?>,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Expenses',
                                data: <?php echo json_encode($expense_data_chart); ?>,
                                borderColor: '#ef4444',
                                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
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
                                        return context.dataset.label + ': ₦' + 
                                            context.parsed.y.toLocaleString('en-US', {
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

            // Expense Breakdown Chart
            const expenseCtx = document.getElementById('expenseChart');
            if (expenseCtx) {
                new Chart(expenseCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($expense_labels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($expense_amounts); ?>,
                            backgroundColor: <?php echo json_encode(array_slice($expense_colors, 0, count($expense_labels))); ?>,
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
                                        
                                        // Add percentage
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((context.parsed / total) * 100);
                                        label += ' (' + percentage + '%)';
                                        
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Profit Margin Chart
            const marginCtx = document.getElementById('marginChart');
            if (marginCtx) {
                new Chart(marginCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($months); ?>,
                        datasets: [{
                            label: 'Profit Margin %',
                            data: <?php echo json_encode($profit_margin_chart); ?>,
                            backgroundColor: function(context) {
                                const value = context.raw;
                                return value >= 0 ? 'rgba(16, 185, 129, 0.7)' : 'rgba(239, 68, 68, 0.7)';
                            },
                            borderColor: function(context) {
                                const value = context.raw;
                                return value >= 0 ? '#10b981' : '#ef4444';
                            },
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
                                        return value + '%';
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Profit Margin %'
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Margin: ' + context.parsed.y.toFixed(1) + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });

        // Print optimization
        window.addEventListener('beforeprint', function() {
            document.querySelectorAll('.chart-card').forEach(card => {
                card.style.height = '400px';
            });
        });

        window.addEventListener('afterprint', function() {
            document.querySelectorAll('.chart-card').forEach(card => {
                card.style.height = '';
            });
        });

        // Auto-refresh every 10 minutes
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 600000);
    </script>
</body>

</html>

<?php 
$conn->close(); 
?>