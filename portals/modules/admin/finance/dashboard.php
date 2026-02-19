<?php
// modules/admin/finance/dashboard.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$period = $_GET['period'] ?? 'monthly';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$program_type = $_GET['program_type'] ?? '';
$payment_type = $_GET['payment_type'] ?? 'all';
$expense_category = $_GET['expense_category'] ?? '';
$expense_status = $_GET['expense_status'] ?? '';

// Validate period
$valid_periods = ['today', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'];
if (!in_array($period, $valid_periods)) {
    $period = 'monthly';
}

// Set date range based on period
$today = date('Y-m-d');
switch ($period) {
    case 'today':
        $date_from = $today;
        $date_to = $today;
        break;
    case 'weekly':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = $today;
        break;
    case 'monthly':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = $today;
        break;
    case 'quarterly':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        $date_to = $today;
        break;
    case 'yearly':
        $date_from = date('Y-m-d', strtotime('-365 days'));
        $date_to = $today;
        break;
    case 'custom':
        // Use custom dates if provided
        if ($date_from && $date_to) {
            if ($date_from > $date_to) {
                $temp = $date_from;
                $date_from = $date_to;
                $date_to = $temp;
            }
        }
        break;
}

// Get dashboard statistics
$stats = getFinanceDashboardStats($period, $date_from, $date_to);

// ==================== EXPENSE STATISTICS ====================
// Get expense statistics
$expense_stats = [
    'total_expenses' => 0,
    'total_expense_transactions' => 0,
    'pending_expenses' => 0,
    'pending_expense_count' => 0,
    'approved_expenses' => 0,
    'paid_expenses' => 0,
    'monthly_expenses' => 0,
    'by_category' => [],
    'by_payment_method' => []
];

$expense_where = "WHERE 1=1";
if ($date_from && $date_to) {
    $expense_where .= " AND payment_date BETWEEN '$date_from' AND '$date_to'";
}
if ($expense_status) {
    $expense_where .= " AND status = '$expense_status'";
}

// Total expenses
$expense_total_sql = "SELECT 
    COUNT(*) as total_transactions,
    COALESCE(SUM(amount), 0) as total_expenses,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_expenses,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_expense_count,
    COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as approved_expenses,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_expenses,
    COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) as monthly_expenses
FROM expenses $expense_where";

$expense_total_result = $conn->query($expense_total_sql);
if ($expense_total_result && $expense_total_result->num_rows > 0) {
    $expense_stats = array_merge($expense_stats, $expense_total_result->fetch_assoc());
}

// Expenses by category
$expense_category_sql = "SELECT 
    ec.name as category_name,
    ec.category_type,
    ec.color_code,
    COUNT(e.id) as transaction_count,
    COALESCE(SUM(e.amount), 0) as total_amount
FROM expense_categories ec
LEFT JOIN expenses e ON e.category_id = ec.id AND ec.is_active = 1";
    
// Add date and status conditions for expenses
$expense_join_conditions = [];
if ($date_from && $date_to) {
    $expense_join_conditions[] = "e.payment_date BETWEEN '$date_from' AND '$date_to'";
}
if ($expense_status) {
    $expense_join_conditions[] = "e.status = '$expense_status'";
} else {
    $expense_join_conditions[] = "e.status IN ('approved', 'paid')";
}

if (!empty($expense_join_conditions)) {
    $expense_category_sql .= " AND " . implode(" AND ", $expense_join_conditions);
}

$expense_category_sql .= " GROUP BY ec.id, ec.name, ec.category_type, ec.color_code
ORDER BY total_amount DESC";

$expense_category_result = $conn->query($expense_category_sql);
if ($expense_category_result) {
    $expense_stats['by_category'] = $expense_category_result->fetch_all(MYSQLI_ASSOC);
}

// Expenses by payment method
$expense_method_sql = "SELECT 
    payment_method,
    COUNT(*) as count,
    SUM(amount) as total
FROM expenses
$expense_where
GROUP BY payment_method
ORDER BY total DESC";

$expense_method_result = $conn->query($expense_method_sql);
if ($expense_method_result) {
    $expense_stats['by_payment_method'] = $expense_method_result->fetch_all(MYSQLI_ASSOC);
}

// Add expense stats to main stats array
$stats['total_expenses'] = $expense_stats['total_expenses'] ?? 0;
$stats['expense_transactions'] = $expense_stats['total_transactions'] ?? 0;
$stats['pending_expenses'] = $expense_stats['pending_expenses'] ?? 0;
$stats['pending_expense_count'] = $expense_stats['pending_expense_count'] ?? 0;
$stats['approved_expenses'] = $expense_stats['approved_expenses'] ?? 0;
$stats['paid_expenses'] = $expense_stats['paid_expenses'] ?? 0;
$stats['monthly_expenses'] = $expense_stats['monthly_expenses'] ?? 0;

// Calculate net profit/loss
$stats['net_profit'] = ($stats['total_revenue'] ?? 0) - ($stats['total_expenses'] ?? 0);
$stats['net_profit_margin'] = ($stats['total_revenue'] ?? 0) > 0 ? 
    round((($stats['net_profit'] / $stats['total_revenue']) * 100), 2) : 0;

// Calculate combined totals (revenue + expenses)
$stats['total_transactions_all'] = ($stats['total_transactions'] ?? 0) + ($stats['expense_transactions'] ?? 0);
$stats['total_cash_flow'] = ($stats['total_revenue'] ?? 0) - ($stats['total_expenses'] ?? 0);

// ==================== SERVICE REVENUE INTEGRATION ====================
// Check if service_revenue table exists
$table_exists = false;
$check_table_sql = "SHOW TABLES LIKE 'service_revenue'";
$check_result = $conn->query($check_table_sql);
if ($check_result && $check_result->num_rows > 0) {
    $table_exists = true;
}

// Get service revenue statistics if table exists
$service_stats = [
    'total_revenue' => 0,
    'total_transactions' => 0,
    'monthly_revenue' => 0
];

if ($table_exists) {
    $service_stats_sql = "SELECT 
        COUNT(*) as total_transactions,
        COALESCE(SUM(amount), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) as monthly_revenue
    FROM service_revenue 
    WHERE status = 'completed'";
    
    // Add date filters if applicable
    if ($date_from && $date_to) {
        $service_stats_sql .= " AND payment_date BETWEEN '$date_from' AND '$date_to'";
    }
    
    $service_result = $conn->query($service_stats_sql);
    if ($service_result && $service_result->num_rows > 0) {
        $service_stats = $service_result->fetch_assoc();
    }
    
    // Get recent service transactions
    $recent_services_sql = "SELECT 
        sr.*,
        sc.name as category_name,
        sc.revenue_type
    FROM service_revenue sr
    LEFT JOIN service_categories sc ON sc.id = sr.service_category_id
    WHERE sr.status = 'completed'";
    
    if ($date_from && $date_to) {
        $recent_services_sql .= " AND sr.payment_date BETWEEN '$date_from' AND '$date_to'";
    }
    
    $recent_services_sql .= " ORDER BY sr.created_at DESC LIMIT 10";
    $recent_services_result = $conn->query($recent_services_sql);
    $recent_services = $recent_services_result ? $recent_services_result->fetch_all(MYSQLI_ASSOC) : [];
}

// Add service stats to main stats array
$stats['service_revenue'] = $service_stats['total_revenue'] ?? 0;
$stats['service_transactions'] = $service_stats['total_transactions'] ?? 0;
$stats['service_monthly_revenue'] = $service_stats['monthly_revenue'] ?? 0;

// Calculate combined revenue totals
$stats['combined_revenue'] = ($stats['total_revenue'] ?? 0) + ($stats['service_revenue'] ?? 0);
$stats['combined_transactions'] = ($stats['total_transactions'] ?? 0) + ($stats['service_transactions'] ?? 0);

// ==================== END SERVICE REVENUE INTEGRATION ====================

// Get recent transactions
$recent_transactions = [];

// For registration payments
$reg_sql = "SELECT 
                rf.id,
                rf.student_id,
                rf.amount,
                rf.payment_method,
                rf.status,
                rf.payment_date,
                rf.created_at,
                rf.updated_at,
                rf.transaction_reference,
                'registration' as transaction_category,
                'revenue' as flow_type,
                u.first_name,
                u.last_name,
                u.email,
                p.name as program_name,
                p.program_type,
                p.program_code,
                NULL as batch_code,
                NULL as course_title,
                'Registration Fee' as description
            FROM registration_fee_payments rf
            JOIN users u ON u.id = rf.student_id
            JOIN programs p ON p.id = rf.program_id
            WHERE rf.status = 'completed'";

if ($date_from && $date_to) {
    $reg_sql .= " AND DATE(rf.created_at) BETWEEN '$date_from' AND '$date_to'";
}
if ($program_type) {
    $reg_sql .= " AND p.program_type = '$program_type'";
}

// For course/tuition payments
$course_sql = "SELECT 
                    cp.id,
                    cp.student_id,
                    cp.amount,
                    cp.payment_method,
                    cp.status,
                    cp.payment_date,
                    cp.created_at,
                    cp.updated_at,
                    cp.transaction_id as transaction_reference,
                    'tuition' as transaction_category,
                    'revenue' as flow_type,
                    u.first_name,
                    u.last_name,
                    u.email,
                    p.name as program_name,
                    p.program_type,
                    p.program_code,
                    cb.batch_code,
                    c.title as course_title,
                    CONCAT('Course: ', c.title) as description
                FROM course_payments cp
                JOIN users u ON u.id = cp.student_id
                JOIN class_batches cb ON cb.id = cp.class_id
                JOIN courses c ON c.id = cp.course_id
                JOIN programs p ON p.program_code = c.program_id
                WHERE cp.status = 'completed'";

if ($date_from && $date_to) {
    $course_sql .= " AND DATE(cp.created_at) BETWEEN '$date_from' AND '$date_to'";
}
if ($program_type) {
    $course_sql .= " AND p.program_type = '$program_type'";
}

// For expense payments
$expense_sql = "SELECT 
                    e.id,
                    NULL as student_id,
                    e.amount,
                    e.payment_method,
                    e.status,
                    e.payment_date,
                    e.created_at,
                    e.updated_at,
                    e.expense_number as transaction_reference,
                    ec.name as transaction_category,
                    'expense' as flow_type,
                    e.vendor_name as first_name,
                    '' as last_name,
                    '' as email,
                    '' as program_name,
                    '' as program_type,
                    '' as program_code,
                    '' as batch_code,
                    '' as course_title,
                    e.description
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.category_id
                WHERE 1=1";

// Add expense conditions
$expense_conditions = [];
if ($date_from && $date_to) {
    $expense_conditions[] = "DATE(e.payment_date) BETWEEN '$date_from' AND '$date_to'";
}
if ($expense_status) {
    $expense_conditions[] = "e.status = '$expense_status'";
} else {
    $expense_conditions[] = "e.status IN ('approved', 'paid')";
}
if ($expense_category) {
    $expense_conditions[] = "e.category_id = '$expense_category'";
}

if (!empty($expense_conditions)) {
    $expense_sql .= " AND " . implode(" AND ", $expense_conditions);
}

// Execute queries based on payment type filter
$all_results = [];

switch ($payment_type) {
    case 'registration':
        $reg_sql .= " ORDER BY rf.created_at DESC LIMIT 10";
        $result = $conn->query($reg_sql);
        if ($result) {
            $all_results = $result->fetch_all(MYSQLI_ASSOC);
        }
        break;
    case 'tuition':
        $course_sql .= " ORDER BY cp.created_at DESC LIMIT 10";
        $result = $conn->query($course_sql);
        if ($result) {
            $all_results = $result->fetch_all(MYSQLI_ASSOC);
        }
        break;
    case 'expense':
        $expense_sql .= " ORDER BY e.created_at DESC LIMIT 10";
        $result = $conn->query($expense_sql);
        if ($result) {
            $all_results = $result->fetch_all(MYSQLI_ASSOC);
        }
        break;
    default: // 'all'
        $reg_sql .= " ORDER BY rf.created_at DESC LIMIT 10";
        $course_sql .= " ORDER BY cp.created_at DESC LIMIT 10";
        $expense_sql .= " ORDER BY e.created_at DESC LIMIT 10";
        
        // Combine all results
        $reg_result = $conn->query($reg_sql);
        $course_result = $conn->query($course_sql);
        $expense_result = $conn->query($expense_sql);
        
        if ($reg_result) {
            $all_results = array_merge($all_results, $reg_result->fetch_all(MYSQLI_ASSOC));
        }
        if ($course_result) {
            $all_results = array_merge($all_results, $course_result->fetch_all(MYSQLI_ASSOC));
        }
        if ($expense_result) {
            $all_results = array_merge($all_results, $expense_result->fetch_all(MYSQLI_ASSOC));
        }
        
        // Sort by created_at descending
        usort($all_results, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Take only top 10
        $all_results = array_slice($all_results, 0, 10);
        break;
}

$recent_transactions = $all_results;

// Get top programs by revenue
$top_programs_sql = "SELECT 
                        p.name,
                        p.program_code,
                        p.program_type,
                        COALESCE(
                            (SELECT SUM(rf.amount) 
                             FROM registration_fee_payments rf
                             WHERE rf.status = 'completed' 
                             AND rf.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND rf.program_id = p.id), 0
                        ) +
                        COALESCE(
                            (SELECT SUM(cp.amount) 
                             FROM course_payments cp
                             JOIN courses c ON c.id = cp.course_id
                             WHERE cp.status = 'completed' 
                             AND cp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND c.program_id = p.program_code), 0
                        ) as total_revenue,
                        
                        COALESCE(
                            (SELECT COUNT(rf.id) 
                             FROM registration_fee_payments rf
                             WHERE rf.status = 'completed' 
                             AND rf.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND rf.program_id = p.id), 0
                        ) +
                        COALESCE(
                            (SELECT COUNT(cp.id) 
                             FROM course_payments cp
                             JOIN courses c ON c.id = cp.course_id
                             WHERE cp.status = 'completed' 
                             AND cp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND c.program_id = p.program_code), 0
                        ) as transaction_count
                    FROM programs p
                    HAVING total_revenue > 0
                    ORDER BY total_revenue DESC
                    LIMIT 5";

$top_programs_result = $conn->query($top_programs_sql);
$top_programs = $top_programs_result ? $top_programs_result->fetch_all(MYSQLI_ASSOC) : [];

// Get payment method distribution (revenue)
$payment_methods_sql = "SELECT 
                            payment_method,
                            COUNT(*) as count,
                            SUM(amount) as total
                        FROM (
                            SELECT payment_method, amount, created_at
                            FROM registration_fee_payments 
                            WHERE status = 'completed'
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            UNION ALL
                            SELECT payment_method, amount, created_at
                            FROM course_payments 
                            WHERE status = 'completed'
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ) combined_payments
                        GROUP BY payment_method
                        ORDER BY total DESC";

$payment_methods_result = $conn->query($payment_methods_sql);
$payment_methods = $payment_methods_result ? $payment_methods_result->fetch_all(MYSQLI_ASSOC) : [];

// Calculate percentages for revenue payment methods
$total_count = array_sum(array_column($payment_methods, 'count'));
foreach ($payment_methods as &$method) {
    $method['percentage'] = $total_count > 0 ? round(($method['count'] / $total_count) * 100, 1) : 0;
}

// Get students with overdue payments
$overdue_sql = "SELECT sfs.*, u.first_name, u.last_name, u.email, u.phone,
                       cb.batch_code, c.title as course_title,
                       p.name as program_name,
                       DATEDIFF(sfs.next_payment_due, CURDATE()) as days_overdue
                FROM student_financial_status sfs
                JOIN users u ON u.id = sfs.student_id
                JOIN class_batches cb ON cb.id = sfs.class_id
                JOIN courses c ON c.id = cb.course_id
                JOIN programs p ON p.program_code = c.program_id
                WHERE sfs.balance > 0 
                AND sfs.next_payment_due < CURDATE()
                AND sfs.is_suspended = 0
                ORDER BY days_overdue ASC
                LIMIT 10";

$overdue_result = $conn->query($overdue_sql);
$overdue_students = $overdue_result ? $overdue_result->fetch_all(MYSQLI_ASSOC) : [];

// Get pending invoices
$pending_invoices_sql = "SELECT i.*, u.first_name, u.last_name, u.email,
                                cb.batch_code, c.title as course_title,
                                DATEDIFF(i.due_date, CURDATE()) as days_until_due
                         FROM invoices i
                         JOIN users u ON u.id = i.student_id
                         JOIN class_batches cb ON cb.id = i.class_id
                         JOIN courses c ON c.id = cb.course_id
                         WHERE i.status = 'pending'
                         ORDER BY i.due_date ASC
                         LIMIT 10";

$pending_invoices_result = $conn->query($pending_invoices_sql);
$pending_invoices = $pending_invoices_result ? $pending_invoices_result->fetch_all(MYSQLI_ASSOC) : [];

// Get pending expenses
$pending_expenses_sql = "SELECT e.*, ec.name as category_name,
                                u.first_name, u.last_name as recorded_by_name
                         FROM expenses e
                         JOIN expense_categories ec ON ec.id = e.category_id
                         LEFT JOIN users u ON u.id = e.created_by
                         WHERE e.status = 'pending'
                         ORDER BY e.payment_date ASC
                         LIMIT 10";

$pending_expenses_result = $conn->query($pending_expenses_sql);
$pending_expenses = $pending_expenses_result ? $pending_expenses_result->fetch_all(MYSQLI_ASSOC) : [];

// Get recent expenses
$recent_expenses_sql = "SELECT e.*, ec.name as category_name,
                               ec.color_code, u.first_name, u.last_name as recorded_by_name
                        FROM expenses e
                        JOIN expense_categories ec ON ec.id = e.category_id
                        LEFT JOIN users u ON u.id = e.created_by
                        WHERE 1=1";

$recent_expense_conditions = ["e.status IN ('approved', 'paid')"];
if ($date_from && $date_to) {
    $recent_expense_conditions[] = "DATE(e.payment_date) BETWEEN '$date_from' AND '$date_to'";
}
if ($expense_status) {
    $recent_expense_conditions[] = "e.status = '$expense_status'";
}
if ($expense_category) {
    $recent_expense_conditions[] = "e.category_id = '$expense_category'";
}

$recent_expenses_sql .= " AND " . implode(" AND ", $recent_expense_conditions);
$recent_expenses_sql .= " ORDER BY e.created_at DESC LIMIT 10";

$recent_expenses_result = $conn->query($recent_expenses_sql);
$recent_expenses = $recent_expenses_result ? $recent_expenses_result->fetch_all(MYSQLI_ASSOC) : [];

// Get revenue breakdown by category for selected period
$revenue_breakdown_sql = "SELECT 
                            'registration' as category,
                            'revenue' as flow_type,
                            COUNT(*) as transaction_count,
                            COALESCE(SUM(amount), 0) as total_amount
                          FROM registration_fee_payments 
                          WHERE status = 'completed'";

if ($date_from && $date_to) {
    $revenue_breakdown_sql .= " AND created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_breakdown_sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$revenue_breakdown_sql .= " UNION ALL
                          SELECT 
                            'tuition' as category,
                            'revenue' as flow_type,
                            COUNT(*) as transaction_count,
                            COALESCE(SUM(amount), 0) as total_amount
                          FROM course_payments 
                          WHERE status = 'completed'";

if ($date_from && $date_to) {
    $revenue_breakdown_sql .= " AND created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_breakdown_sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// Add service revenue if table exists
if ($table_exists) {
    $revenue_breakdown_sql .= " UNION ALL
                          SELECT 
                            'service' as category,
                            'revenue' as flow_type,
                            COUNT(*) as transaction_count,
                            COALESCE(SUM(amount), 0) as total_amount
                          FROM service_revenue 
                          WHERE status = 'completed'";
    
    if ($date_from && $date_to) {
        $revenue_breakdown_sql .= " AND payment_date BETWEEN '$date_from' AND '$date_to'";
    } else {
        $revenue_breakdown_sql .= " AND payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
}

$revenue_breakdown_result = $conn->query($revenue_breakdown_sql);
$revenue_breakdown = [];
$total_revenue_all_categories = 0;

if ($revenue_breakdown_result) {
    $revenue_breakdown = $revenue_breakdown_result->fetch_all(MYSQLI_ASSOC);
    foreach ($revenue_breakdown as $item) {
        $total_revenue_all_categories += $item['total_amount'] ?? 0;
    }
}

// Get expense breakdown by category for selected period
$expense_breakdown_sql = "SELECT 
                            ec.name as category,
                            'expense' as flow_type,
                            COUNT(e.id) as transaction_count,
                            COALESCE(SUM(e.amount), 0) as total_amount,
                            ec.category_type,
                            ec.color_code
                          FROM expense_categories ec
                          LEFT JOIN expenses e ON e.category_id = ec.id 
                          AND e.status IN ('approved', 'paid')";
                          
if ($date_from && $date_to) {
    $expense_breakdown_sql .= " AND e.payment_date BETWEEN '$date_from' AND '$date_to'";
} else {
    $expense_breakdown_sql .= " AND e.payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$expense_breakdown_sql .= " WHERE ec.is_active = 1
                          GROUP BY ec.id, ec.name, ec.category_type, ec.color_code
                          HAVING total_amount > 0
                          ORDER BY total_amount DESC";

$expense_breakdown_result = $conn->query($expense_breakdown_sql);
$expense_breakdown = [];
$total_expense_all_categories = 0;

if ($expense_breakdown_result) {
    $expense_breakdown = $expense_breakdown_result->fetch_all(MYSQLI_ASSOC);
    foreach ($expense_breakdown as $item) {
        $total_expense_all_categories += $item['total_amount'] ?? 0;
    }
}

// Get revenue by program type (online vs onsite)
$revenue_by_program_type_sql = "SELECT 
                                    'online' as program_type,
                                    COALESCE(
                                        (SELECT SUM(rf.amount) 
                                         FROM registration_fee_payments rf
                                         JOIN programs p ON p.id = rf.program_id
                                         WHERE rf.status = 'completed'";
                                         
if ($date_from && $date_to) {
    $revenue_by_program_type_sql .= " AND rf.created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_by_program_type_sql .= " AND rf.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$revenue_by_program_type_sql .= " AND p.program_type = 'online'), 0
                                    ) +
                                    COALESCE(
                                        (SELECT SUM(cp.amount) 
                                         FROM course_payments cp
                                         JOIN courses c ON c.id = cp.course_id
                                         JOIN programs p ON p.program_code = c.program_id
                                         WHERE cp.status = 'completed'";
                                         
if ($date_from && $date_to) {
    $revenue_by_program_type_sql .= " AND cp.created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_by_program_type_sql .= " AND cp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$revenue_by_program_type_sql .= " AND p.program_type = 'online'), 0
                                    ) as total_amount
                                    
                                UNION ALL
                                
                                SELECT 
                                    'onsite' as program_type,
                                    COALESCE(
                                        (SELECT SUM(rf.amount) 
                                         FROM registration_fee_payments rf
                                         JOIN programs p ON p.id = rf.program_id
                                         WHERE rf.status = 'completed'";
                                         
if ($date_from && $date_to) {
    $revenue_by_program_type_sql .= " AND rf.created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_by_program_type_sql .= " AND rf.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$revenue_by_program_type_sql .= " AND p.program_type = 'onsite'), 0
                                    ) +
                                    COALESCE(
                                        (SELECT SUM(cp.amount) 
                                         FROM course_payments cp
                                         JOIN courses c ON c.id = cp.course_id
                                         JOIN programs p ON p.program_code = c.program_id
                                         WHERE cp.status = 'completed'";
                                         
if ($date_from && $date_to) {
    $revenue_by_program_type_sql .= " AND cp.created_at BETWEEN '$date_from' AND '$date_to'";
} else {
    $revenue_by_program_type_sql .= " AND cp.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$revenue_by_program_type_sql .= " AND p.program_type = 'onsite'), 0
                                    ) as total_amount";

$revenue_by_program_type_result = $conn->query($revenue_by_program_type_sql);
$revenue_by_program_type = $revenue_by_program_type_result ? $revenue_by_program_type_result->fetch_all(MYSQLI_ASSOC) : [];

// Get monthly trend data (last 6 months)
$monthly_trend_sql = "SELECT 
    DATE_FORMAT(date_range.month, '%Y-%m') as month,
    DATE_FORMAT(date_range.month, '%b %Y') as month_display,
    COALESCE(SUM(CASE WHEN rev.flow_type = 'revenue' THEN rev.amount ELSE 0 END), 0) as total_revenue,
    COALESCE(SUM(CASE WHEN rev.flow_type = 'expense' THEN rev.amount ELSE 0 END), 0) as total_expense,
    COALESCE(SUM(CASE WHEN rev.flow_type = 'revenue' THEN rev.amount ELSE 0 END), 0) - 
    COALESCE(SUM(CASE WHEN rev.flow_type = 'expense' THEN rev.amount ELSE 0 END), 0) as net_profit
FROM (
    SELECT LAST_DAY(CURRENT_DATE - INTERVAL n MONTH) + INTERVAL 1 DAY - INTERVAL 1 MONTH as month
    FROM (
        SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
    ) months
) date_range
LEFT JOIN (
    -- Revenue
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m-01') as month,
        'revenue' as flow_type,
        amount
    FROM registration_fee_payments 
    WHERE status = 'completed'
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    
    UNION ALL
    
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m-01') as month,
        'revenue' as flow_type,
        amount
    FROM course_payments 
    WHERE status = 'completed'
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    
    UNION ALL
    
    -- Expenses
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m-01') as month,
        'expense' as flow_type,
        amount
    FROM expenses 
    WHERE status IN ('approved', 'paid')
    AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
) rev ON DATE_FORMAT(rev.month, '%Y-%m') = DATE_FORMAT(date_range.month, '%Y-%m')
GROUP BY date_range.month, month_display
ORDER BY date_range.month";

$monthly_trend_result = $conn->query($monthly_trend_sql);
$monthly_trend = $monthly_trend_result ? $monthly_trend_result->fetch_all(MYSQLI_ASSOC) : [];

// Get top expense categories
$top_expense_categories_sql = "SELECT 
    ec.name as category_name,
    ec.category_type,
    COUNT(e.id) as transaction_count,
    COALESCE(SUM(e.amount), 0) as total_amount,
    ec.color_code
FROM expense_categories ec
LEFT JOIN expenses e ON e.category_id = ec.id 
    AND e.status IN ('approved', 'paid')";
    
if ($date_from && $date_to) {
    $top_expense_categories_sql .= " AND e.payment_date BETWEEN '$date_from' AND '$date_to'";
} else {
    $top_expense_categories_sql .= " AND e.payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$top_expense_categories_sql .= " WHERE ec.is_active = 1
GROUP BY ec.id, ec.name, ec.category_type, ec.color_code
HAVING total_amount > 0
ORDER BY total_amount DESC
LIMIT 5";

$top_expense_categories_result = $conn->query($top_expense_categories_sql);
$top_expense_categories = $top_expense_categories_result ? $top_expense_categories_result->fetch_all(MYSQLI_ASSOC) : [];

// Log activity
logActivity($_SESSION['user_id'], 'finance_dashboard', "Accessed finance dashboard with period: $period");

// Process export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $export_data = exportFinancialData('transactions', $date_from, $date_to);
    
    if ($export_data) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="financial_transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // Write headers
        if (!empty($export_data)) {
            fputcsv($output, array_keys($export_data[0]));
            
            // Write data
            foreach ($export_data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Finance Dashboard - Admin Portal</title>
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
            --expense: #ef4444;
            --profit: #8b5cf6;
            --pending: #f59e0b;
            --overdue: #ef4444;
            --issues: #8b5cf6;
            --registration: #7c3aed;
            --tuition: #1d4ed8;
            --service: #8b5cf6;
            --online: #059669;
            --onsite: #dc2626;
            --operational: #3b82f6;
            --fixed: #8b5cf6;
            --variable: #ec4899;
            --tithe: #10b981;
            --reserve: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile First Design */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            position: relative;
        }

        /* Mobile Header */
        .mobile-header {
            display: none;
            background: var(--sidebar);
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

        .hamburger-menu {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .hamburger-menu:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .mobile-header-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .mobile-user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mobile-user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
        }

        /* Sidebar - Hidden by default on mobile */
        .sidebar {
            width: 280px;
            background: var(--sidebar);
            color: white;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1001;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .sidebar-overlay.active {
            display: block;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--dark-light);
            background: rgba(255, 255, 255, 0.05);
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
            font-size: 0.95rem;
            position: relative;
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
            flex-shrink: 0;
        }

        .nav-section {
            padding: 0.75rem 1.5rem;
            color: #94a3b8;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-section:first-child {
            margin-top: 0;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
            margin-top: 60px; /* Space for mobile header */
        }

        /* Desktop Header - Hidden on mobile */
        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.6rem;
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
            width: 44px;
            height: 44px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }

        /* Filters */
        .filters-card {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr;
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
            background: white;
            -webkit-appearance: none;
            appearance: none;
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

        /* Buttons - Larger touch targets for mobile */
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
            min-height: 44px; /* Minimum touch target size */
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
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
            font-size: 0.9rem;
            min-height: 36px;
        }

        .filter-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        /* Period Filter Buttons */
        .period-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .period-btn {
            padding: 0.6rem 1.2rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .period-btn:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        .period-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Stats Cards - Stack on mobile */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.revenue {
            border-top-color: var(--revenue);
        }

        .stat-card.expense {
            border-top-color: var(--expense);
        }

        .stat-card.profit {
            border-top-color: var(--profit);
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
            flex-wrap: wrap;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .trend-neutral {
            color: #64748b;
        }

        .stat-icon {
            font-size: 1.75rem;
            opacity: 0.2;
            color: inherit;
        }

        /* Charts and Tables Container */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            min-width: 0; /* Prevent overflow */
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
            flex: 1;
            min-width: 200px;
        }

        .card-body {
            padding: 1.25rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Responsive Tables */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: -0.5rem;
            padding: 0.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px; /* Minimum table width for scrolling */
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
            vertical-align: top;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status-completed, .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-suspended {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-cancelled {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
        }

        .amount.positive {
            color: var(--success);
        }

        .amount.negative {
            color: var(--danger);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
        }

        /* Program Badges */
        .program-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge-online {
            background: #dbeafe;
            color: var(--online);
        }

        .badge-onsite {
            background: #dcfce7;
            color: var(--onsite);
        }

        .badge-registration {
            background: #ede9fe;
            color: var(--registration);
        }

        .badge-tuition {
            background: #dbf4ff;
            color: var(--tuition);
        }

        .badge-service {
            background: #f3e8ff;
            color: var(--service);
        }

        .badge-expense {
            background: #fee2e2;
            color: var(--expense);
        }

        .badge-operational {
            background: #dbeafe;
            color: var(--operational);
        }

        .badge-fixed {
            background: #f3e8ff;
            color: var(--fixed);
        }

        .badge-variable {
            background: #fce7f3;
            color: var(--variable);
        }

        .badge-tithe {
            background: #d1fae5;
            color: var(--tithe);
        }

        .badge-reserve {
            background: #fef3c7;
            color: var(--reserve);
        }

        .badge-revenue {
            background: #d1fae5;
            color: var(--revenue);
        }

        .badge-profit {
            background: #e9d5ff;
            color: var(--profit);
        }

        /* Chart Placeholder */
        .chart-placeholder {
            height: 250px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border-radius: 8px;
            color: #94a3b8;
            font-size: 0.9rem;
            padding: 2rem;
        }

        .chart-placeholder i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Empty State */
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

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        /* Revenue & Expense Breakdown */
        .breakdown-chart {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1rem 0;
            margin-bottom: 1rem;
        }

        .chart-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chart-bar-fill {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .chart-bar-info {
            flex: 1;
            min-width: 0;
        }

        .chart-bar-label {
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .chart-bar-amount {
            font-size: 0.9rem;
            color: #64748b;
        }

        .chart-bar-percentage {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        /* Mini Stats */
        .mini-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .mini-stat-item {
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            text-align: center;
        }

        .mini-stat-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .mini-stat-value {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .mini-stat-online {
            color: var(--online);
        }

        .mini-stat-onsite {
            color: var(--onsite);
        }

        .mini-stat-revenue {
            color: var(--revenue);
        }

        .mini-stat-expense {
            color: var(--expense);
        }

        .mini-stat-profit {
            color: var(--profit);
        }

        /* Mobile Action Buttons */
        .mobile-actions {
            display: none;
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 999;
        }

        .mobile-action-btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            cursor: pointer;
            transition: all 0.3s;
            -webkit-tap-highlight-color: transparent;
        }

        .mobile-action-btn:hover {
            transform: scale(1.1);
        }

        /* Date Range Group */
        .date-range-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        /* Service Revenue Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            border-radius: 12px 12px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: #f1f5f9;
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: span 1;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        /* Quick Expense Button */
        .quick-expense-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .quick-expense-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .quick-expense-btn i {
            font-size: 1.1rem;
        }

        /* Quick Service Button */
        .quick-service-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .quick-service-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .quick-service-btn i {
            font-size: 1.1rem;
        }

        /* Monthly Trend Chart */
        .trend-chart {
            padding: 1rem 0;
        }

        .trend-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .trend-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .trend-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .trend-month {
            font-weight: 600;
            color: var(--dark);
        }

        .trend-amounts {
            display: flex;
            gap: 1rem;
        }

        .trend-revenue {
            color: var(--revenue);
            font-weight: 500;
        }

        .trend-expense {
            color: var(--expense);
            font-weight: 500;
        }

        .trend-profit {
            color: var(--profit);
            font-weight: 600;
        }

        .trend-bar-container {
            height: 30px;
            background: #f1f5f9;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .trend-bar-revenue {
            height: 100%;
            background: var(--revenue);
            position: absolute;
            left: 0;
        }

        .trend-bar-expense {
            height: 100%;
            background: var(--expense);
            position: absolute;
            left: 0;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .mobile-header {
                display: block;
            }
            
            .header {
                display: none;
            }
            
            .main-content {
                padding: 1rem;
                margin-top: 60px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-buttons {
                grid-template-columns: 1fr;
            }
            
            .period-filter {
                overflow-x: auto;
                padding-bottom: 0.5rem;
            }
            
            .period-btn {
                flex-shrink: 0;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .card-header h3 {
                min-width: auto;
            }
            
            .mobile-actions {
                display: block;
            }
            
            .date-range-group {
                grid-template-columns: 1fr;
            }
            
            /* Touch-friendly table cells */
            td, th {
                padding: 0.75rem 0.5rem;
            }
            
            /* Adjust font sizes for mobile */
            .stat-number {
                font-size: 1.5rem;
            }
            
            .btn {
                padding: 0.75rem 1rem;
                font-size: 0.95rem;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            
            .main-content {
                margin-left: 240px;
                margin-top: 0;
                padding: 1.5rem;
            }
            
            .mobile-header {
                display: none;
            }
            
            .header {
                display: flex;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 1025px) {
            .admin-container {
                flex-direction: row;
            }
            
            .sidebar {
                transform: translateX(0);
                position: fixed;
            }
            
            .main-content {
                margin-left: 280px;
                margin-top: 0;
                padding: 2rem;
            }
            
            .mobile-header {
                display: none;
            }
            
            .header {
                display: flex;
            }
            
            .filter-form {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .content-grid {
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            }
        }

        @media (min-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 2;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar, .mobile-header, .mobile-actions, .btn {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .filters-card, .card-header {
                display: none !important;
            }
            
            .content-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            
            table {
                width: 100% !important;
                min-width: auto !important;
            }
        }

        /* Accessibility Improvements */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Loading State */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Animation for mobile menu */
        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="mobile-header-content">
            <button class="hamburger-menu" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="mobile-header-title">Finance Dashboard</div>
            <div class="mobile-user-info">
                <div class="mobile-user-avatar">
                    <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay"></div>

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
                    <i class="fas fa-money-bill-wave"></i> All Payments</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/registrations.php">
                    <i class="fas fa-user-plus"></i> Registration Payments</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/tuitions.php">
                    <i class="fas fa-graduation-cap"></i> Tuition Payments</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/index.php">
                    <i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/fees/index.php">
                    <i class="fas fa-calculator"></i> Fee Management</a></li>
                
                <div class="nav-section">Expense Management</div>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/dashboard.php">
                    <i class="fas fa-chart-line"></i> Expense Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/index.php">
                    <i class="fas fa-list"></i> All Expenses</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php">
                    <i class="fas fa-plus-circle"></i> Add Expense</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php">
                    <i class="fas fa-tags"></i> Categories</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php">
                    <i class="fas fa-chart-pie"></i> Budgets</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php">
                    <i class="fas fa-cog"></i> Automated Deductions</a></li>

                <div class="nav-section">Reports & Analytics</div>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php">
                    <i class="fas fa-chart-bar"></i> Revenue Reports</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/expenses.php">
                    <i class="fas fa-chart-line"></i> Expense Reports</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/profit-loss.php">
                    <i class="fas fa-balance-scale"></i> Profit & Loss</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/outstanding.php">
                    <i class="fas fa-exclamation-triangle"></i> Outstanding</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/collection.php">
                    <i class="fas fa-chart-pie"></i> Collection Analysis</a></li>

                <div class="nav-section">System</div>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/index.php">
                    <i class="fas fa-cog"></i> Finance Settings</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/payment_gateways.php">
                    <i class="fas fa-credit-card"></i> Payment Gateways</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/expense_settings.php">
                    <i class="fas fa-calculator"></i> Expense Settings</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/system/">
                    <i class="fas fa-sliders-h"></i> System Settings</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="admin-container">
        <div class="main-content">
            <!-- Desktop Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-chart-line"></i>
                    Finance Dashboard
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

            <!-- Period Quick Filter -->
            <div class="filters-card">
                <h3>Quick Period Filter</h3>
                <div class="period-filter">
                    <button type="button" class="period-btn <?php echo $period === 'today' ? 'active' : ''; ?>" onclick="setPeriod('today')">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                    <button type="button" class="period-btn <?php echo $period === 'weekly' ? 'active' : ''; ?>" onclick="setPeriod('weekly')">
                        <i class="fas fa-calendar-week"></i> Weekly
                    </button>
                    <button type="button" class="period-btn <?php echo $period === 'monthly' ? 'active' : ''; ?>" onclick="setPeriod('monthly')">
                        <i class="fas fa-calendar-alt"></i> Monthly
                    </button>
                    <button type="button" class="period-btn <?php echo $period === 'quarterly' ? 'active' : ''; ?>" onclick="setPeriod('quarterly')">
                        <i class="fas fa-calendar"></i> Quarterly
                    </button>
                    <button type="button" class="period-btn <?php echo $period === 'yearly' ? 'active' : ''; ?>" onclick="setPeriod('yearly')">
                        <i class="fas fa-calendar-star"></i> Yearly
                    </button>
                    <button type="button" class="period-btn <?php echo $period === 'custom' ? 'active' : ''; ?>" onclick="showCustomDateRange()">
                        <i class="fas fa-calendar-check"></i> Custom
                    </button>
                </div>

                <!-- Custom Date Range (hidden by default) -->
                <div id="customDateRange" style="display: <?php echo $period === 'custom' ? 'block' : 'none'; ?>; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 1rem; color: var(--dark); font-size: 1rem;">Custom Date Range</h4>
                    <form method="GET" class="filter-form" id="customFilterForm">
                        <input type="hidden" name="period" value="custom">
                        <input type="hidden" name="program_type" value="<?php echo $program_type; ?>">
                        <input type="hidden" name="payment_type" value="<?php echo $payment_type; ?>">
                        <input type="hidden" name="expense_status" value="<?php echo $expense_status; ?>">
                        
                        <div class="date-range-group">
                            <div>
                                <label for="customDateFrom">From Date</label>
                                <input type="date" id="customDateFrom" name="date_from" class="form-control"
                                    value="<?php echo $date_from; ?>" required>
                            </div>
                            <div>
                                <label for="customDateTo">To Date</label>
                                <input type="date" id="customDateTo" name="date_to" class="form-control"
                                    value="<?php echo $date_to; ?>" required>
                            </div>
                        </div>
                        
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Custom Range
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="hideCustomDateRange()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Advanced Filters -->
                <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 1rem; color: var(--dark); font-size: 1rem;">Advanced Filters</h4>
                    <form method="GET" class="filter-form" id="filterForm">
                        <input type="hidden" name="period" value="<?php echo $period; ?>">
                        <input type="hidden" name="date_from" value="<?php echo $date_from; ?>">
                        <input type="hidden" name="date_to" value="<?php echo $date_to; ?>">
                        
                        <div class="form-group">
                            <label for="programType">Program Type</label>
                            <select name="program_type" class="form-control" id="programType">
                                <option value="">All Types</option>
                                <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                                <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="paymentType">Transaction Type</label>
                            <select name="payment_type" class="form-control" id="paymentType">
                                <option value="all" <?php echo $payment_type === 'all' ? 'selected' : ''; ?>>All Transactions</option>
                                <option value="registration" <?php echo $payment_type === 'registration' ? 'selected' : ''; ?>>Registration Only</option>
                                <option value="tuition" <?php echo $payment_type === 'tuition' ? 'selected' : ''; ?>>Tuition Only</option>
                                <option value="expense" <?php echo $payment_type === 'expense' ? 'selected' : ''; ?>>Expenses Only</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="expenseStatus">Expense Status</label>
                            <select name="expense_status" class="form-control" id="expenseStatus">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $expense_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $expense_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="paid" <?php echo $expense_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="cancelled" <?php echo $expense_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="filter-buttons">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset All
                                </a>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?export=csv&period=<?php echo $period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&payment_type=<?php echo $payment_type; ?>"
                                    class="btn btn-success">
                                    <i class="fas fa-file-export"></i> Export
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Quick Action Buttons -->
                <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 1rem; color: var(--dark); font-size: 1rem;">
                        <i class="fas fa-bolt"></i> Quick Actions
                    </h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
                        <button type="button" class="btn btn-danger" onclick="openExpenseModal()">
                            <i class="fas fa-plus-circle"></i> Record Expense
                        </button>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php" class="btn btn-warning">
                            <i class="fas fa-file-invoice-dollar"></i> Add Detailed Expense
                        </a>
                        <button type="button" class="btn btn-success" onclick="openServiceModal()">
                            <i class="fas fa-briefcase"></i> Record Service Revenue
                        </button>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/services/" class="btn btn-info">
                            <i class="fas fa-list"></i> View Service Revenue
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card revenue">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_revenue'] ?? 0); ?>
                        <i class="fas fa-money-bill-wave stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        <span style="color: var(--registration);">Reg: <?php echo formatCurrency($stats['registration_revenue'] ?? 0); ?></span> | 
                        <span style="color: var(--tuition);">Tui: <?php echo formatCurrency($stats['tuition_revenue'] ?? 0); ?></span> |
                        <span style="color: var(--info);">Srv: <?php echo formatCurrency($stats['service_revenue'] ?? 0); ?></span>
                    </div>
                </div>

                <div class="stat-card expense">
                    <div class="stat-label">Total Expenses</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_expenses'] ?? 0); ?>
                        <i class="fas fa-money-bill-wave-alt stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        <span style="color: var(--warning);">Pending: <?php echo formatCurrency($stats['pending_expenses'] ?? 0); ?></span> | 
                        <span style="color: var(--success);">Paid: <?php echo formatCurrency($stats['paid_expenses'] ?? 0); ?></span>
                    </div>
                </div>

                <div class="stat-card profit">
                    <div class="stat-label">Net Profit/Loss</div>
                    <div class="stat-number <?php echo ($stats['net_profit'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatCurrency($stats['net_profit'] ?? 0); ?>
                        <i class="fas fa-chart-line stat-icon"></i>
                    </div>
                    <div class="stat-trend <?php echo ($stats['net_profit'] ?? 0) >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        Margin: <?php echo $stats['net_profit_margin'] ?? 0; ?>%
                    </div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-label">Pending & Overdue</div>
                    <div class="stat-number">
                        <?php echo formatCurrency(($stats['pending_amount'] ?? 0) + ($stats['overdue_amount'] ?? 0)); ?>
                        <i class="fas fa-exclamation-triangle stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        <?php echo ($stats['pending_payments_count'] ?? 0) + ($stats['overdue_count'] ?? 0) + ($stats['pending_expense_count'] ?? 0); ?> items pending
                    </div>
                </div>
            </div>

            <!-- Display current period -->
            <div style="background: white; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-calendar-alt" style="color: var(--primary);"></i>
                    <div>
                        <strong>Current Period:</strong> 
                        <span style="text-transform: capitalize;"><?php echo $period; ?></span>
                        <?php if ($date_from && $date_to): ?>
                            (<?php echo date('M j, Y', strtotime($date_from)); ?> - <?php echo date('M j, Y', strtotime($date_to)); ?>)
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <?php if (!empty($monthly_trend)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Financial Trend (Last 6 Months)</h3>
                    <span>Revenue vs Expenses</span>
                </div>
                <div class="card-body">
                    <div class="trend-chart">
                        <?php foreach ($monthly_trend as $trend): ?>
                            <div class="trend-item">
                                <div class="trend-header">
                                    <div class="trend-month"><?php echo $trend['month_display']; ?></div>
                                    <div class="trend-amounts">
                                        <span class="trend-revenue">+<?php echo formatCurrency($trend['total_revenue']); ?></span>
                                        <span class="trend-expense">-<?php echo formatCurrency($trend['total_expense']); ?></span>
                                        <span class="trend-profit">= <?php echo formatCurrency($trend['net_profit']); ?></span>
                                    </div>
                                </div>
                                <div class="trend-bar-container">
                                    <?php 
                                    $total = $trend['total_revenue'] + $trend['total_expense'];
                                    $revenue_percent = $total > 0 ? ($trend['total_revenue'] / $total) * 100 : 0;
                                    $expense_percent = $total > 0 ? ($trend['total_expense'] / $total) * 100 : 0;
                                    ?>
                                    <div class="trend-bar-revenue" style="width: <?php echo $revenue_percent; ?>%;"></div>
                                    <div class="trend-bar-expense" style="width: <?php echo $expense_percent; ?>%; left: <?php echo $revenue_percent; ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Revenue & Expense Breakdown -->
            <?php if (!empty($revenue_breakdown) || !empty($expense_breakdown)): ?>
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Financial Breakdown (<?php echo ucfirst($period); ?> Period)</h3>
                    <div>
                        <span style="color: var(--revenue); margin-right: 1rem;">Revenue: <?php echo formatCurrency($total_revenue_all_categories); ?></span>
                        <span style="color: var(--expense);">Expenses: <?php echo formatCurrency($total_expense_all_categories); ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Revenue Breakdown -->
                    <?php if (!empty($revenue_breakdown)): ?>
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--dark); font-size: 1rem;">
                            <i class="fas fa-arrow-up" style="color: var(--revenue);"></i> Revenue Breakdown
                        </h4>
                        <div class="breakdown-chart">
                            <?php 
                            $total_rev = $total_revenue_all_categories;
                            foreach ($revenue_breakdown as $item): 
                                $percentage = $total_rev > 0 ? round(($item['total_amount'] / $total_rev) * 100) : 0;
                                $color = '';
                                $label = '';
                                switch ($item['category']) {
                                    case 'registration':
                                        $color = 'var(--registration)';
                                        $label = 'Registration';
                                        break;
                                    case 'tuition':
                                        $color = 'var(--tuition)';
                                        $label = 'Tuition';
                                        break;
                                    case 'service':
                                        $color = 'var(--service)';
                                        $label = 'Services';
                                        break;
                                    default:
                                        $color = '#64748b';
                                        $label = ucfirst($item['category']);
                                }
                            ?>
                            <div class="chart-bar">
                                <div class="chart-bar-fill" style="background-color: <?php echo $color; ?>;">
                                    <?php echo $percentage; ?>%
                                </div>
                                <div class="chart-bar-info">
                                    <div class="chart-bar-label">
                                        <span class="program-badge badge-<?php echo $item['category']; ?>">
                                            <?php echo $label; ?>
                                        </span>
                                    </div>
                                    <div class="chart-bar-amount"><?php echo formatCurrency($item['total_amount']); ?></div>
                                </div>
                                <div class="chart-bar-percentage">
                                    <?php echo $percentage; ?>%
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Expense Breakdown -->
                    <?php if (!empty($expense_breakdown)): ?>
                    <div>
                        <h4 style="margin-bottom: 1rem; color: var(--dark); font-size: 1rem;">
                            <i class="fas fa-arrow-down" style="color: var(--expense);"></i> Expense Breakdown
                        </h4>
                        <div class="breakdown-chart">
                            <?php 
                            $total_exp = $total_expense_all_categories;
                            foreach ($expense_breakdown as $item): 
                                $percentage = $total_exp > 0 ? round(($item['total_amount'] / $total_exp) * 100) : 0;
                                $color = $item['color_code'] ?? '#64748b';
                                $badge_class = '';
                                switch ($item['category_type']) {
                                    case 'operational':
                                        $badge_class = 'operational';
                                        break;
                                    case 'fixed':
                                        $badge_class = 'fixed';
                                        break;
                                    case 'variable':
                                        $badge_class = 'variable';
                                        break;
                                    case 'tithe':
                                        $badge_class = 'tithe';
                                        break;
                                    case 'reserve':
                                        $badge_class = 'reserve';
                                        break;
                                    default:
                                        $badge_class = 'other';
                                }
                            ?>
                            <div class="chart-bar">
                                <div class="chart-bar-fill" style="background-color: <?php echo $color; ?>;">
                                    <?php echo $percentage; ?>%
                                </div>
                                <div class="chart-bar-info">
                                    <div class="chart-bar-label">
                                        <span class="program-badge badge-<?php echo $badge_class; ?>">
                                            <?php echo $item['category']; ?>
                                        </span>
                                    </div>
                                    <div class="chart-bar-amount"><?php echo formatCurrency($item['total_amount']); ?></div>
                                </div>
                                <div class="chart-bar-percentage">
                                    <?php echo $percentage; ?>%
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Charts and Tables -->
            <div class="content-grid">
                <!-- Recent Transactions -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-exchange-alt"></i> Recent Transactions</h3>
                        <div>
                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/" class="btn btn-sm btn-primary">
                                View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_transactions)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('M j', strtotime($transaction['created_at'])); ?><br>
                                                    <small style="color: #64748b;"><?php echo date('g:i A', strtotime($transaction['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($transaction['flow_type'] === 'revenue'): ?>
                                                        <span class="program-badge badge-<?php echo $transaction['transaction_category']; ?>">
                                                            <?php echo ucfirst($transaction['transaction_category']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="program-badge badge-expense">
                                                            Expense
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($transaction['flow_type'] === 'revenue'): ?>
                                                        <?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?><br>
                                                        <small style="color: #64748b;"><?php echo $transaction['email']; ?></small>
                                                    <?php else: ?>
                                                        <strong><?php echo htmlspecialchars($transaction['first_name']); ?></strong><br>
                                                        <small style="color: #64748b;"><?php echo htmlspecialchars($transaction['description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount <?php echo $transaction['flow_type'] === 'revenue' ? 'positive' : 'negative'; ?>">
                                                    <?php echo ($transaction['flow_type'] === 'revenue' ? '+' : '-') . formatCurrency($transaction['amount']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-exchange-alt"></i>
                                <h3>No Recent Transactions</h3>
                                <p>No financial transactions found for the selected period.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Programs & Expense Categories -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Top Revenue & Expenses</h3>
                        <span><?php echo ucfirst($period); ?> Period</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($top_programs) || !empty($top_expense_categories)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Top Revenue Programs -->
                                        <?php foreach ($top_programs as $program): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($program['name']); ?></div>
                                                    <small style="color: #64748b;"><?php echo $program['program_type']; ?> Program</small>
                                                </td>
                                                <td>
                                                    <span class="program-badge badge-revenue">Revenue</span>
                                                </td>
                                                <td class="amount positive">
                                                    <?php echo formatCurrency($program['total_revenue']); ?>
                                                </td>
                                                <td>
                                                    <?php echo $program['transaction_count']; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Top Expense Categories -->
                                        <?php foreach ($top_expense_categories as $expense_cat): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($expense_cat['category_name']); ?></div>
                                                    <small style="color: #64748b;"><?php echo ucfirst($expense_cat['category_type']); ?> Expense</small>
                                                </td>
                                                <td>
                                                    <span class="program-badge badge-expense">Expense</span>
                                                </td>
                                                <td class="amount negative">
                                                    <?php echo formatCurrency($expense_cat['total_amount']); ?>
                                                </td>
                                                <td>
                                                    <?php echo $expense_cat['transaction_count']; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-bar"></i>
                                <h3>No Revenue or Expense Data</h3>
                                <p>No data available for the selected period.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Overdue Students -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> Overdue Payments</h3>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/overdue.php" class="btn btn-sm btn-danger">
                            Manage
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($overdue_students)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Balance</th>
                                            <th>Days</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdue_students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                    <small style="color: #64748b;"><?php echo $student['email']; ?></small>
                                                </td>
                                                <td class="amount negative">
                                                    <?php echo formatCurrency($student['balance']); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-overdue">
                                                        <?php echo abs($student['days_overdue']); ?>d
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h3>No Overdue Payments</h3>
                                <p>All students are up to date with their payments.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending Expenses -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Pending Expenses</h3>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/index.php?status=pending" class="btn btn-sm btn-warning">
                            Manage
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pending_expenses)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Expense #</th>
                                            <th>Category</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_expenses as $expense): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $expense['expense_number']; ?></strong><br>
                                                    <small style="color: #64748b;"><?php echo date('M j', strtotime($expense['payment_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo $expense['color_code'] ?? '#64748b'; ?>; margin-right: 0.5rem;"></span>
                                                    <?php echo $expense['category_name']; ?>
                                                </td>
                                                <td class="amount negative">
                                                    <?php echo formatCurrency($expense['amount']); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-pending">
                                                        Pending
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h3>No Pending Expenses</h3>
                                <p>All expenses are processed or there are no pending expenses.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pending Invoices -->
            <div class="content-card" style="margin-bottom: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Pending Invoices</h3>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/index.php" class="btn btn-sm btn-warning">
                        View All
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($pending_invoices)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Student</th>
                                        <th>Amount</th>
                                        <th>Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_invoices as $invoice): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $invoice['invoice_number']; ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?><br>
                                                <small style="color: #64748b;"><?php echo $invoice['email']; ?></small>
                                            </td>
                                            <td class="amount positive">
                                                <?php echo formatCurrency($invoice['amount']); ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j', strtotime($invoice['due_date'])); ?><br>
                                                <?php if ($invoice['days_until_due'] < 0): ?>
                                                    <small style="color: var(--danger);">
                                                        Overdue
                                                    </small>
                                                <?php elseif ($invoice['days_until_due'] <= 7): ?>
                                                    <small style="color: var(--warning);">
                                                        Soon
                                                    </small>
                                                <?php else: ?>
                                                    <small style="color: #64748b;">
                                                        Due
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No Pending Invoices</h3>
                            <p>All invoices have been paid or there are no pending invoices.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Expense Modal -->
    <div id="expenseModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Record Quick Expense</h3>
                <button type="button" class="modal-close" onclick="closeExpenseModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="expenseForm" onsubmit="saveExpense(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="expenseCategory">Expense Category *</label>
                            <select id="expenseCategory" name="category_id" class="form-control" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $category_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
                                $category_result = $conn->query($category_sql);
                                while ($category = $category_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?> (<?php echo $category['category_type']; ?>)</option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="expenseDescription">Description *</label>
                            <input type="text" id="expenseDescription" name="description" class="form-control" 
                                   placeholder="e.g., Office supplies, Internet bill, etc." required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expenseAmount">Amount (₦) *</label>
                            <input type="number" id="expenseAmount" name="amount" class="form-control" 
                                   step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expensePaymentMethod">Payment Method *</label>
                            <select id="expensePaymentMethod" name="payment_method" class="form-control" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="pos">POS</option>
                                <option value="cheque">Cheque</option>
                                <option value="online">Online Payment</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="expenseDate">Payment Date *</label>
                            <input type="date" id="expenseDate" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expenseVendor">Vendor/Supplier (Optional)</label>
                            <input type="text" id="expenseVendor" name="vendor_name" class="form-control" 
                                   placeholder="e.g., XYZ Supplies Ltd.">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="expenseNotes">Notes (Optional)</label>
                            <textarea id="expenseNotes" name="notes" class="form-control" 
                                      rows="3" placeholder="Additional information about this expense..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-save"></i> Save Expense
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeExpenseModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Service Revenue Modal -->
    <div id="serviceRevenueModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Record Service Revenue</h3>
                <button type="button" class="modal-close" onclick="closeServiceModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="serviceRevenueForm" onsubmit="saveServiceRevenue(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="serviceCategory">Service Type *</label>
                            <select id="serviceCategory" name="service_category" class="form-control" required>
                                <option value="">-- Select Service Type --</option>
                                <option value="software">Software Development</option>
                                <option value="procurement">Computer Procurement</option>
                                <option value="accessories">Computer Accessories</option>
                                <option value="consultancy">IT Consultancy</option>
                                <option value="cbt">CBT Setup & Configuration</option>
                                <option value="maintenance">System Maintenance</option>
                                <option value="training">IT Training</option>
                                <option value="networking">Networking Services</option>
                                <option value="website">Website Development</option>
                                <option value="digital_marketing">Digital Marketing</option>
                                <option value="other">Other Services</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="clientName">Client/Company Name *</label>
                            <input type="text" id="clientName" name="client_name" class="form-control" 
                                   placeholder="e.g., ABC Company or John Doe" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="clientContact">Client Contact (Optional)</label>
                            <input type="text" id="clientContact" name="client_contact" class="form-control" 
                                   placeholder="Email or phone number">
                        </div>
                        
                        <div class="form-group">
                            <label for="serviceAmount">Amount (₦) *</label>
                            <input type="number" id="serviceAmount" name="amount" class="form-control" 
                                   step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="paymentMethod">Payment Method *</label>
                            <select id="paymentMethod" name="payment_method" class="form-control" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="pos">POS</option>
                                <option value="cheque">Cheque</option>
                                <option value="online">Online Payment</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="paymentDate">Payment Date *</label>
                            <input type="date" id="paymentDate" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="serviceDescription">Description *</label>
                            <textarea id="serviceDescription" name="description" class="form-control" 
                                      rows="3" placeholder="Describe the service provided..." required></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="referenceNumber">Reference/Invoice Number (Optional)</label>
                            <input type="text" id="referenceNumber" name="reference_number" class="form-control" 
                                   placeholder="e.g., INV-2024-001 or Bank Reference">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="serviceNotes">Additional Notes (Optional)</label>
                            <textarea id="serviceNotes" name="notes" class="form-control" 
                                      rows="2" placeholder="Any additional information..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Revenue
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeServiceModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Mobile Action Button -->
    <div class="mobile-actions">
        <button class="mobile-action-btn" aria-label="Quick actions">
            <i class="fas fa-bolt"></i>
        </button>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Mobile Navigation
        document.addEventListener('DOMContentLoaded', function() {
            const hamburgerMenu = document.querySelector('.hamburger-menu');
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const mobileActionBtn = document.querySelector('.mobile-action-btn');
            
            // Toggle sidebar
            hamburgerMenu.addEventListener('click', function() {
                sidebar.classList.add('active');
                sidebarOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
            
            // Close sidebar when clicking overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768 && 
                    !sidebar.contains(event.target) && 
                    !hamburgerMenu.contains(event.target) &&
                    sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            // Mobile action button
            mobileActionBtn.addEventListener('click', function() {
                // Show quick actions modal
                showQuickActions();
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            // Initialize date pickers
            flatpickr("input[type='date']", {
                dateFormat: "Y-m-d",
                allowInput: true,
                disableMobile: false,
                theme: "light"
            });
            
            // Form submission loading state
            const filterForm = document.getElementById('filterForm');
            filterForm.addEventListener('submit', function() {
                this.classList.add('loading');
            });
            
            // Touch-friendly table rows
            document.querySelectorAll('tbody tr').forEach(row => {
                row.addEventListener('touchstart', function() {
                    this.style.backgroundColor = '#f8fafc';
                });
                
                row.addEventListener('touchend', function() {
                    this.style.backgroundColor = '';
                });
            });
        });
        
        // Period filter functions
        function setPeriod(period) {
            const url = new URL(window.location.href);
            url.searchParams.set('period', period);
            
            // Remove date parameters for predefined periods
            if (period !== 'custom') {
                url.searchParams.delete('date_from');
                url.searchParams.delete('date_to');
            }
            
            window.location.href = url.toString();
        }
        
        function showCustomDateRange() {
            document.getElementById('customDateRange').style.display = 'block';
            setPeriod('custom');
        }
        
        function hideCustomDateRange() {
            document.getElementById('customDateRange').style.display = 'none';
            // Reset to monthly if canceling custom
            setPeriod('monthly');
        }
        
        // Show quick actions modal
        function showQuickActions() {
            const actions = [
                { icon: 'fa-plus', label: 'Add Payment', url: '<?php echo BASE_URL; ?>modules/admin/finance/payments/add.php' },
                { icon: 'fa-file-invoice', label: 'Create Invoice', url: '<?php echo BASE_URL; ?>modules/admin/finance/invoices/create.php' },
                { icon: 'fa-money-bill', label: 'Record Expense', onclick: 'openExpenseModal()' },
                { icon: 'fa-briefcase', label: 'Service Revenue', onclick: 'openServiceModal()' },
                { icon: 'fa-chart-bar', label: 'Quick Report', url: '<?php echo BASE_URL; ?>modules/admin/finance/reports/quick.php' },
                { icon: 'fa-bell', label: 'Send Reminders', onclick: 'sendBulkReminders()' }
            ];
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                bottom: 80px;
                right: 1rem;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                z-index: 1000;
                min-width: 200px;
                animation: slideUp 0.3s ease;
            `;
            
            let html = '<div style="padding: 1rem;">';
            actions.forEach(action => {
                if (action.url) {
                    html += `
                        <a href="${action.url}" style="display: flex; align-items: center; padding: 0.75rem; 
                               color: var(--dark); text-decoration: none; border-bottom: 1px solid #e2e8f0;
                               transition: background-color 0.3s;">
                            <i class="fas ${action.icon}" style="margin-right: 0.75rem; color: var(--primary);"></i>
                            ${action.label}
                        </a>
                    `;
                } else {
                    html += `
                        <button onclick="${action.onclick}" style="display: flex; align-items: center; padding: 0.75rem; 
                                width: 100%; border: none; background: none; color: var(--dark);
                                text-align: left; border-bottom: 1px solid #e2e8f0; cursor: pointer;">
                            <i class="fas ${action.icon}" style="margin-right: 0.75rem; color: var(--primary);"></i>
                            ${action.label}
                        </button>
                    `;
                }
            });
            html += '</div>';
            
            modal.innerHTML = html;
            document.body.appendChild(modal);
            
            // Close modal when clicking outside
            setTimeout(() => {
                const closeModal = (e) => {
                    if (!modal.contains(e.target) && e.target !== document.querySelector('.mobile-action-btn')) {
                        modal.remove();
                        document.removeEventListener('click', closeModal);
                    }
                };
                document.addEventListener('click', closeModal);
            }, 100);
        }
        
        // Expense Modal Functions
        function openExpenseModal() {
            document.getElementById('expenseModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeExpenseModal() {
            document.getElementById('expenseModal').style.display = 'none';
            document.body.style.overflow = '';
            resetExpenseForm();
        }

        function resetExpenseForm() {
            document.getElementById('expenseForm').reset();
            document.getElementById('expenseDate').value = new Date().toISOString().split('T')[0];
        }

        async function saveExpense(event) {
            event.preventDefault();
            
            const form = document.getElementById('expenseForm');
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('modules/admin/finance/expenses/quick_add.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Show success message
                    showNotification('Expense recorded successfully!', 'success');
                    
                    // Close modal
                    closeExpenseModal();
                    
                    // Reload the dashboard to update stats
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(result.error || 'Failed to save expense', 'error');
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'error');
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
        }

        // Service Revenue Modal Functions
        function openServiceModal() {
            document.getElementById('serviceRevenueModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeServiceModal() {
            document.getElementById('serviceRevenueModal').style.display = 'none';
            document.body.style.overflow = '';
            resetServiceForm();
        }

        function resetServiceForm() {
            document.getElementById('serviceRevenueForm').reset();
            document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
        }

        async function saveServiceRevenue(event) {
            event.preventDefault();
            
            const form = document.getElementById('serviceRevenueForm');
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('modules/admin/finance/services/quick_add.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Show success message
                    showNotification('Revenue recorded successfully!', 'success');
                    
                    // Close modal
                    closeServiceModal();
                    
                    // Reload the dashboard to update stats
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification(result.error || 'Failed to save revenue', 'error');
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'error');
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
        }

        // Send reminder function
        function sendReminder(invoiceId) {
            if (confirm('Send payment reminder for this invoice?')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/notifications/send_reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'invoice_id=' + invoiceId + '&csrf_token=<?php echo generateCSRFToken(); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Reminder sent successfully!');
                    } else {
                        alert('Failed to send reminder: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Error sending reminder: ' + error);
                });
            }
        }
        
        // Send bulk reminders
        function sendBulkReminders() {
            if (confirm('Send reminders for all overdue invoices?')) {
                // Implement bulk reminder logic here
                alert('Bulk reminder feature coming soon!');
            }
        }
        
        // Auto-refresh dashboard every 5 minutes (only on desktop)
        if (window.innerWidth > 768) {
            setInterval(() => {
                if (!document.hidden) {
                    window.location.reload();
                }
            }, 300000);
        }
        
        // Update stats every 60 seconds
        function updateQuickStats() {
            if (!document.hidden) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/ajax/stats.php?period=<?php echo $period; ?>&payment_type=<?php echo $payment_type; ?>')
                    .then(response => response.json())
                    .then(data => {
                        // Update stats cards if needed
                        console.log('Stats updated:', data);
                    })
                    .catch(error => console.error('Error updating stats:', error));
            }
        }
        
        // Only update stats on desktop
        if (window.innerWidth > 768) {
            setInterval(updateQuickStats, 60000);
        }

        // Notification function
        function showNotification(message, type = 'info') {
            // Remove existing notification
            const existing = document.querySelector('.custom-notification');
            if (existing) existing.remove();
            
            // Create notification
            const notification = document.createElement('div');
            notification.className = `custom-notification ${type}`;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                    <span>${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 3000;
                display: flex;
                align-items: center;
                justify-content: space-between;
                min-width: 300px;
                animation: slideInRight 0.3s ease;
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOutRight 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @media (max-width: 768px) {
                /* Improve touch scrolling */
                .table-container::-webkit-scrollbar {
                    height: 6px;
                }
                
                .table-container::-webkit-scrollbar-track {
                    background: #f1f1f1;
                    border-radius: 3px;
                }
                
                .table-container::-webkit-scrollbar-thumb {
                    background: #c1c1c1;
                    border-radius: 3px;
                }
                
                .table-container::-webkit-scrollbar-thumb:hover {
                    background: #a1a1a1;
                }
            }
            
            .custom-notification {
                font-family: inherit;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>