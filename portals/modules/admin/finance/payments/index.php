<?php
// modules/admin/finance/payments/index.php

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
$payment_method = $_GET['payment_method'] ?? '';
$program_type = $_GET['program_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$period = $_GET['period'] ?? 'month';
$payment_type = $_GET['payment_type'] ?? ''; // New filter for payment type
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

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
    // Validate dates
    if ($date_from > $date_to) {
        $temp = $date_from;
        $date_from = $date_to;
        $date_to = $temp;
    }
}

// Build subquery for all payments
$base_sql = "SELECT 
            'financial_transaction' as source_table,
            ft.id,
            ft.student_id,
            ft.class_id,
            ft.invoice_id,
            ft.transaction_type,
            ft.payment_method,
            ft.amount,
            ft.currency,
            ft.gateway_reference,
            ft.description,
            ft.status,
            ft.is_verified,
            ft.verified_by,
            ft.verified_at,
            ft.receipt_url,
            ft.created_at,
            ft.updated_at,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            cb.batch_code,
            cb.program_type as batch_program_type,
            c.title as course_title,
            p.name as program_name,
            p.program_type,
            sfs.balance as student_balance
        FROM financial_transactions ft
        JOIN users u ON u.id = ft.student_id
        LEFT JOIN class_batches cb ON cb.id = ft.class_id
        LEFT JOIN courses c ON c.id = cb.course_id
        LEFT JOIN programs p ON p.id = c.program_id
        LEFT JOIN student_financial_status sfs ON sfs.student_id = ft.student_id 
            AND sfs.class_id = ft.class_id
        
        UNION ALL
        
        SELECT 
            'registration_fee' as source_table,
            rfp.id,
            rfp.student_id,
            NULL as class_id,
            NULL as invoice_id,
            'registration' as transaction_type,
            rfp.payment_method,
            rfp.amount,
            'NGN' as currency,
            rfp.transaction_reference as gateway_reference,
            'Registration Fee Payment' as description,
            rfp.status,
            CASE WHEN rfp.status = 'completed' THEN 1 ELSE 0 END as is_verified,
            NULL as verified_by,
            NULL as verified_at,
            rfp.receipt_url,
            rfp.created_at,
            rfp.updated_at,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            NULL as batch_code,
            NULL as batch_program_type,
            NULL as course_title,
            p.name as program_name,
            p.program_type,
            0 as student_balance
        FROM registration_fee_payments rfp
        JOIN users u ON u.id = rfp.student_id
        JOIN programs p ON p.id = rfp.program_id
        
        UNION ALL
        
        SELECT 
            'course_payment' as source_table,
            cp.id,
            cp.student_id,
            cp.class_id,
            NULL as invoice_id,
            'course_fee' as transaction_type,
            cp.payment_method,
            cp.amount,
            'NGN' as currency,
            cp.transaction_id as gateway_reference,
            'Course Fee Payment' as description,
            cp.status,
            CASE WHEN cp.status = 'completed' THEN 1 ELSE 0 END as is_verified,
            NULL as verified_by,
            NULL as verified_at,
            cp.receipt_url,
            cp.created_at,
            cp.updated_at,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            cb.batch_code,
            cb.program_type as batch_program_type,
            c.title as course_title,
            p.name as program_name,
            p.program_type,
            0 as student_balance
        FROM course_payments cp
        JOIN users u ON u.id = cp.student_id
        LEFT JOIN class_batches cb ON cb.id = cp.class_id
        LEFT JOIN courses c ON c.id = cp.course_id
        LEFT JOIN programs p ON p.id = c.program_id";

// Build the main query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM ($base_sql) as combined_payments WHERE 1=1";

$params = [];
$types = "";

// Filter by payment type (source)
if ($payment_type) {
    $sql .= " AND source_table = ?";
    $params[] = $payment_type;
    $types .= "s";
}

// Filter by status
if ($status) {
    $sql .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}

// Filter by payment method
if ($payment_method) {
    $sql .= " AND payment_method = ?";
    $params[] = $payment_method;
    $types .= "s";
}

// Filter by program type
if ($program_type) {
    $sql .= " AND program_type = ?";
    $params[] = $program_type;
    $types .= "s";
}

// Filter by date range
if ($date_from) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Filter by search term
if ($search) {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? 
            OR phone LIKE ? OR gateway_reference LIKE ? OR description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= str_repeat("s", 6);
}

// Order and pagination
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Prepare and execute
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$transactions = $result->fetch_all(MYSQLI_ASSOC);

// Get total count
$total_result = $conn->query("SELECT FOUND_ROWS() as total");
$total_row = $total_result->fetch_assoc();
$total_transactions = $total_row['total'];
$total_pages = ceil($total_transactions / $limit);

// Get statistics from all payment sources
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded,
    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_amount
    FROM (
        SELECT status, amount FROM financial_transactions
        UNION ALL
        SELECT status, amount FROM registration_fee_payments
        UNION ALL
        SELECT status, amount FROM course_payments
    ) as all_payments";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Ensure all stats have default values
$stats = array_merge([
    'total' => 0,
    'completed' => 0,
    'pending' => 0,
    'failed' => 0,
    'refunded' => 0,
    'total_amount' => 0
], $stats ?: []);

// Get payment method statistics
$method_stats_sql = "SELECT 
    payment_method, 
    COUNT(*) as count, 
    COALESCE(SUM(amount), 0) as total 
FROM (
    SELECT payment_method, amount FROM financial_transactions WHERE status = 'completed'
    UNION ALL
    SELECT payment_method, amount FROM registration_fee_payments WHERE status = 'completed'
    UNION ALL
    SELECT payment_method, amount FROM course_payments WHERE status = 'completed'
) as all_methods
GROUP BY payment_method
ORDER BY total DESC";

$method_stats_result = $conn->query($method_stats_sql);
$method_stats = $method_stats_result->fetch_all(MYSQLI_ASSOC);

// Get payment type statistics
$type_stats_sql = "SELECT 
    source_table as payment_type,
    COUNT(*) as count,
    COALESCE(SUM(amount), 0) as total
FROM (
    SELECT 'financial_transaction' as source_table, amount FROM financial_transactions WHERE status = 'completed'
    UNION ALL
    SELECT 'registration_fee' as source_table, amount FROM registration_fee_payments WHERE status = 'completed'
    UNION ALL
    SELECT 'course_payment' as source_table, amount FROM course_payments WHERE status = 'completed'
) as all_types
GROUP BY source_table
ORDER BY total DESC";

$type_stats_result = $conn->query($type_stats_sql);
$type_stats = $type_stats_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_payments', "Viewed payments list with filters");

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } elseif (!empty($_POST['selected_payments'])) {
        $selected_items = $_POST['selected_payments'];
        
        // Parse the selected items (format: source_table|id)
        $financial_ids = [];
        $registration_ids = [];
        $course_ids = [];
        
        foreach ($selected_items as $item) {
            list($source, $id) = explode('|', $item);
            switch ($source) {
                case 'financial_transaction':
                    $financial_ids[] = $id;
                    break;
                case 'registration_fee':
                    $registration_ids[] = $id;
                    break;
                case 'course_payment':
                    $course_ids[] = $id;
                    break;
            }
        }
        
        if ($_POST['bulk_action'] === 'verify') {
            $success_count = 0;
            
            // Update financial_transactions
            if (!empty($financial_ids)) {
                $placeholders = implode(',', array_fill(0, count($financial_ids), '?'));
                $update_sql = "UPDATE financial_transactions SET is_verified = 1, 
                              verified_at = NOW(), verified_by = ? 
                              WHERE id IN ($placeholders)";
                $update_stmt = $conn->prepare($update_sql);
                $all_params = array_merge([$_SESSION['user_id']], $financial_ids);
                $types = str_repeat('i', count($financial_ids) + 1);
                
                if ($update_stmt->bind_param($types, ...$all_params) && $update_stmt->execute()) {
                    $success_count += $update_stmt->affected_rows;
                    
                    foreach ($financial_ids as $payment_id) {
                        logActivity(
                            $_SESSION['user_id'],
                            'payment_verified',
                            "Financial transaction #$payment_id bulk verified",
                            'financial_transactions',
                            $payment_id
                        );
                    }
                }
            }
            
            $_SESSION['success'] = $success_count . ' payments verified successfully.';
            
        } elseif ($_POST['bulk_action'] === 'refund') {
            $_SESSION['bulk_refund_ids'] = $selected_items;
            header('Location: refund.php');
            exit();
        }

        // Refresh page
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit();
    } else {
        $_SESSION['error'] = 'Please select at least one payment.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Finance Dashboard</title>
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
            --sidebar: #1e293b;
            --revenue: #10b981;
            --pending: #f59e0b;
            --overdue: #ef4444;
            --registration-color: #8b5cf6;
            --course-color: #ec4899;
            --tuition-color: #10b981;
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

        /* Mobile-first approach */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Header */
        .mobile-header {
            background: var(--sidebar);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .mobile-header h1 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        /* Sidebar for mobile (hidden by default) */
        .sidebar {
            background: var(--sidebar);
            color: white;
            position: fixed;
            top: 0;
            left: -100%;
            width: 280px;
            height: 100vh;
            overflow-y: auto;
            z-index: 2000;
            transition: left 0.3s ease;
            padding: 1rem 0;
        }

        .sidebar.active {
            left: 0;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            color: white;
            margin-bottom: 0.5rem;
        }

        .close-sidebar {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
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

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1500;
            display: none;
        }

        .overlay.active {
            display: block;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
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

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.9rem;
            color: #64748b;
        }

        /* Stats Grid - Mobile first */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
        }

        .stat-card.completed {
            border-top-color: var(--success);
        }

        .stat-card.pending {
            border-top-color: var(--warning);
        }

        .stat-card.failed {
            border-top-color: var(--danger);
        }

        .stat-card.refunded {
            border-top-color: var(--info);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 1.2rem;
            opacity: 0.3;
        }

        /* Cards */
        .card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        /* Payment Type Stats */
        .payment-type-stats {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .payment-type-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .payment-type-card.registration {
            border-left-color: var(--registration-color);
        }

        .payment-type-card.course {
            border-left-color: var(--course-color);
        }

        .payment-type-card.tuition {
            border-left-color: var(--tuition-color);
        }

        .payment-type-header {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .payment-type-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .payment-count {
            color: #64748b;
            font-size: 0.85rem;
        }

        .payment-total {
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .payment-icon {
            font-size: 1.5rem;
            color: #cbd5e1;
        }

        /* Payment Method Stats */
        .method-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.75rem;
        }

        .method-card {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
        }

        .method-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .method-count {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .method-total {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .method-icon {
            font-size: 1.25rem;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
        }

        /* Filters */
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

        .date-range-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
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
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            min-width: 32px;
            min-height: 32px;
        }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .table-header {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }

        .table-subtitle {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        /* Table Wrapper for horizontal scrolling on mobile */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 1000px; /* Ensure table has minimum width for horizontal scroll */
            border-collapse: collapse;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
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
            font-size: 0.85rem;
            vertical-align: top;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
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

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-refunded {
            background: #e0f2fe;
            color: #0369a1;
        }

        .payment-type-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-financial {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-registration {
            background: #f3e8ff;
            color: #6d28d9;
        }

        .badge-course {
            background: #fce7f3;
            color: #be185d;
        }

        .program-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
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

        /* Bulk Actions */
        .bulk-actions {
            background: #f8fafc;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bulk-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .selected-count {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: auto;
        }

        /* Pagination */
        .pagination {
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
            text-align: center;
        }

        .pagination-controls {
            display: flex;
            gap: 0.25rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .page-btn {
            min-width: 36px;
            min-height: 36px;
            padding: 0.25rem 0.5rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Transaction Details Compact View */
        .transaction-ref {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .transaction-type {
            color: #64748b;
            font-size: 0.8rem;
            display: block;
            margin-top: 0.25rem;
        }

        .student-name {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .student-email {
            color: #64748b;
            font-size: 0.8rem;
            display: block;
            margin-top: 0.25rem;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .balance-warning {
            color: var(--warning);
            font-size: 0.8rem;
            display: block;
            margin-top: 0.25rem;
        }

        .date-primary {
            font-size: 0.9rem;
        }

        .date-secondary {
            color: #64748b;
            font-size: 0.8rem;
            display: block;
            margin-top: 0.25rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        /* Desktop Styles */
        @media (min-width: 768px) {
            .admin-container {
                flex-direction: row;
            }
            
            .mobile-header {
                display: none;
            }
            
            .sidebar {
                position: relative;
                left: 0;
                width: 250px;
                height: 100vh;
                overflow-y: auto;
            }
            
            .close-sidebar {
                display: none;
            }
            
            .overlay {
                display: none !important;
            }
            
            .main-content {
                padding: 2rem;
                flex: 1;
                overflow-y: auto;
            }
            
            .header {
                padding: 1.5rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .header h1 {
                margin-bottom: 0;
            }
            
            .user-info {
                padding-top: 0;
                border-top: none;
                border-left: 1px solid #e2e8f0;
                padding-left: 1rem;
                margin-left: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.5rem;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .payment-type-stats {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .payment-type-card {
                flex: 1;
                min-width: 200px;
            }
            
            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
                align-items: end;
            }
            
            .date-range-group {
                grid-column: 1 / -1;
            }
            
            .btn-group {
                margin-top: 0;
                grid-column: 1 / -1;
            }
            
            .table-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .table-actions {
                margin-top: 0;
                justify-content: flex-end;
            }
            
            .bulk-actions {
                flex-direction: row;
                align-items: center;
            }
            
            .bulk-controls {
                flex: 1;
            }
            
            .pagination {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
            
            .pagination-info {
                text-align: left;
            }
            
            table {
                min-width: auto;
                width: 100%;
            }
            
            th, td {
                padding: 1rem;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 280px;
            }
            
            .stats-grid {
                gap: 2rem;
            }
        }

        /* Extra small devices */
        @media (max-width: 360px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .method-stats {
                grid-template-columns: 1fr;
            }
            
            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Header (only visible on mobile) -->
        <div class="mobile-header">
            <h1>
                <i class="fas fa-money-bill-wave"></i>
                Payments
            </h1>
            <button class="mobile-menu-btn" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Overlay for mobile sidebar -->
        <div class="overlay" id="overlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div>
                    <h2>Impact Academy</h2>
                    <p>Finance Management</p>
                </div>
                <button class="close-sidebar" id="closeSidebar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <div class="nav-section">Financial Management</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/index.php" class="active">
                            <i class="fas fa-money-bill-wave"></i> Payments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/">
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
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-money-bill-wave"></i>
                    Payment Management
                </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo $_SESSION['user_name']; ?></div>
                        <div class="user-role">Finance Administrator</div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $stats['total']; ?>
                        <i class="fas fa-list-alt stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Payments</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number">
                        <?php echo $stats['completed']; ?>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number">
                        <?php echo $stats['pending']; ?>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo formatCurrency($stats['total_amount']); ?>
                        <i class="fas fa-money-bill-wave stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Amount</div>
                </div>
            </div>

            <!-- Payment Type Statistics -->
            <div class="card">
                <h3>Payment Type Breakdown</h3>
                <div class="payment-type-stats">
                    <?php if (!empty($type_stats)): ?>
                        <?php foreach ($type_stats as $type): ?>
                            <div class="payment-type-card <?php echo str_replace('_', '', $type['payment_type']); ?>">
                                <div class="payment-type-header">
                                    <?php 
                                    $type_names = [
                                        'financial_transaction' => 'Tuition Fees',
                                        'registration_fee' => 'Registration Fees',
                                        'course_payment' => 'Course Fees'
                                    ];
                                    echo $type_names[$type['payment_type']] ?? ucfirst(str_replace('_', ' ', $type['payment_type']));
                                    ?>
                                </div>
                                <div class="payment-type-details">
                                    <div>
                                        <div class="payment-count"><?php echo $type['count']; ?> payments</div>
                                        <div class="payment-total"><?php echo formatCurrency($type['total']); ?></div>
                                    </div>
                                    <div class="payment-icon">
                                        <?php
                                        $icons = [
                                            'financial_transaction' => 'fa-graduation-cap',
                                            'registration_fee' => 'fa-user-plus',
                                            'course_payment' => 'fa-book'
                                        ];
                                        echo '<i class="fas ' . ($icons[$type['payment_type']] ?? 'fa-money-check') . '"></i>';
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="width: 100%; text-align: center; padding: 2rem; color: #64748b;">
                            <i class="fas fa-credit-card" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No payment type data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Method Stats -->
            <div class="card">
                <h3>Payment Method Distribution</h3>
                <div class="method-stats">
                    <?php if (!empty($method_stats)): ?>
                        <?php foreach ($method_stats as $method): ?>
                            <div class="method-card">
                                <div class="method-icon">
                                    <?php
                                    $icons = [
                                        'online' => 'fa-globe',
                                        'bank_transfer' => 'fa-university',
                                        'cash' => 'fa-money-bill',
                                        'cheque' => 'fa-file-signature',
                                        'pos' => 'fa-credit-card'
                                    ];
                                    echo '<i class="fas ' . ($icons[$method['payment_method']] ?? 'fa-money-check') . '"></i>';
                                    ?>
                                </div>
                                <div class="method-name"><?php echo ucfirst($method['payment_method']); ?></div>
                                <div class="method-count"><?php echo $method['count']; ?> payments</div>
                                <div class="method-total"><?php echo formatCurrency($method['total']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="width: 100%; text-align: center; padding: 2rem; color: #64748b;">
                            <i class="fas fa-credit-card" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No payment method data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters -->
            <div class="card">
                <h3>Filter Payments</h3>
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
                        <label>Date Range</label>
                        <div class="date-range-group">
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Payment Type</label>
                        <select name="payment_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="financial_transaction" <?php echo $payment_type === 'financial_transaction' ? 'selected' : ''; ?>>Tuition Fees</option>
                            <option value="registration_fee" <?php echo $payment_type === 'registration_fee' ? 'selected' : ''; ?>>Registration Fees</option>
                            <option value="course_payment" <?php echo $payment_type === 'course_payment' ? 'selected' : ''; ?>>Course Fees</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="">All Methods</option>
                            <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="cheque" <?php echo $payment_method === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="pos" <?php echo $payment_method === 'pos' ? 'selected' : ''; ?>>POS</option>
                        </select>
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
                        <input type="text" name="search" class="form-control"
                            placeholder="Student name, email, reference..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Payments Table -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="table-container">
                    <div class="table-header">
                        <div>
                            <h3>All Payments</h3>
                            <div class="table-subtitle">
                                Showing <?php echo count($transactions); ?> of <?php echo $total_transactions; ?> payments
                            </div>
                        </div>
                        <div class="table-actions">
                            <a href="manual_entry.php" class="btn btn-primary">
                                <i class="fas fa-hand-holding-usd"></i> Record Payment
                            </a>
                            <a href="../dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($transactions)): ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>Transaction</th>
                                        <th>Type</th>
                                        <th>Student</th>
                                        <th>Program</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Verified</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <?php 
                                        $type_display = '';
                                        $type_class = '';
                                        if ($transaction['source_table'] === 'financial_transaction') {
                                            $type_display = 'Tuition';
                                            $type_class = 'badge-financial';
                                        } elseif ($transaction['source_table'] === 'registration_fee') {
                                            $type_display = 'Registration';
                                            $type_class = 'badge-registration';
                                        } elseif ($transaction['source_table'] === 'course_payment') {
                                            $type_display = 'Course';
                                            $type_class = 'badge-course';
                                        }
                                        ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" name="selected_payments[]"
                                                    value="<?php echo $transaction['source_table'] . '|' . $transaction['id']; ?>" class="payment-checkbox">
                                            </td>
                                            <td>
                                                <div class="transaction-ref"><?php echo $transaction['gateway_reference']; ?></div>
                                                <small class="transaction-type">
                                                    <?php echo ucfirst(str_replace('_', ' ', $transaction['transaction_type'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="payment-type-badge <?php echo $type_class; ?>">
                                                    <?php echo $type_display; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="student-name"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                                <small class="student-email"><?php echo $transaction['email']; ?></small>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($transaction['program_name']); ?></div>
                                                <?php if ($transaction['batch_code']): ?>
                                                    <small style="color: #64748b; font-size: 0.85rem;"><?php echo $transaction['batch_code']; ?></small>
                                                <?php endif; ?>
                                                <?php if ($transaction['program_type']): ?>
                                                    <span class="program-badge badge-<?php echo $transaction['program_type']; ?>" style="margin-top: 0.25rem;">
                                                        <?php echo $transaction['program_type']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount">
                                                <div><?php echo formatCurrency($transaction['amount']); ?></div>
                                                <?php if ($transaction['student_balance'] > 0): ?>
                                                    <small class="balance-warning">
                                                        Balance: <?php echo formatCurrency($transaction['student_balance']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo ucfirst($transaction['payment_method']); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                    <?php echo ucfirst($transaction['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="date-primary"><?php echo date('M j, Y', strtotime($transaction['created_at'])); ?></div>
                                                <small class="date-secondary">
                                                    <?php echo date('g:i A', strtotime($transaction['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($transaction['is_verified']): ?>
                                                    <span style="color: var(--success);">
                                                        <i class="fas fa-check-circle"></i> Yes
                                                    </span>
                                                    <?php if ($transaction['verified_at']): ?>
                                                        <br><small class="date-secondary">
                                                            <?php echo date('M j', strtotime($transaction['verified_at'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($transaction['status'] === 'completed'): ?>
                                                        <span style="color: var(--warning);">
                                                            <i class="fas fa-clock"></i> Pending
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">
                                                            <i class="fas fa-minus"></i> N/A
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="view.php?source=<?php echo $transaction['source_table']; ?>&id=<?php echo $transaction['id']; ?>"
                                                        class="btn btn-sm btn-primary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (!$transaction['is_verified'] && $transaction['status'] === 'completed'): ?>
                                                        <?php if ($transaction['source_table'] === 'financial_transaction'): ?>
                                                            <button type="button" onclick="verifyPayment('<?php echo $transaction['source_table']; ?>', <?php echo $transaction['id']; ?>)"
                                                                class="btn btn-sm btn-success" title="Verify Payment">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($transaction['status'] === 'completed'): ?>
                                                        <button type="button" onclick="refundPayment('<?php echo $transaction['source_table']; ?>', <?php echo $transaction['id']; ?>)"
                                                            class="btn btn-sm btn-warning" title="Refund">
                                                            <i class="fas fa-undo"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($transaction['receipt_url']): ?>
                                                        <a href="<?php echo $transaction['receipt_url']; ?>"
                                                            target="_blank" class="btn btn-sm btn-info" title="View Receipt">
                                                            <i class="fas fa-receipt"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <div class="bulk-controls">
                                <select name="bulk_action" class="form-control" style="flex: 1; min-width: 150px;">
                                    <option value="">Bulk Actions</option>
                                    <option value="verify">Verify Selected</option>
                                    <option value="refund">Refund Selected</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-play"></i> Apply
                                </button>
                                <span class="selected-count">
                                    <span id="selectedCount">0</span> payments selected
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <h3>No Payments Found</h3>
                            <p>There are no payments matching your filters.</p>
                            <a href="manual_entry.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-hand-holding-usd"></i> Record First Payment
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if (!empty($transactions) && $total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                    class="btn btn-sm btn-secondary page-btn">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="btn btn-sm <?php echo $i == $page ? 'btn-primary' : 'btn-secondary'; ?> page-btn">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                                    class="btn btn-sm btn-secondary page-btn">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Mobile sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const closeSidebar = document.getElementById('closeSidebar');
        const overlay = document.getElementById('overlay');
        const sidebar = document.getElementById('sidebar');

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            });
        }

        if (closeSidebar) {
            closeSidebar.addEventListener('click', () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }

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
            allowInput: true
        });

        // Select all checkbox functionality
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.payment-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.payment-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.payment-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk form submission confirmation
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const action = this.bulk_action.value;
            const selectedCount = document.querySelectorAll('.payment-checkbox:checked').length;

            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one payment.');
                return false;
            }

            const actionText = action === 'verify' ? 'verify' : 'refund';
            const confirmText = action === 'verify' ?
                `Are you sure you want to verify ${selectedCount} payment(s)?` :
                `Are you sure you want to refund ${selectedCount} payment(s)?`;

            if (!confirm(confirmText)) {
                e.preventDefault();
                return false;
            }
        });

        // Individual payment actions
        function verifyPayment(source, paymentId) {
            if (confirm('Are you sure you want to verify this payment?')) {
                fetch('verify.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'source=' + source + '&payment_id=' + paymentId + '&csrf_token=<?php echo generateCSRFToken(); ?>'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Payment verified successfully!');
                            window.location.reload();
                        } else {
                            alert('Failed to verify payment: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        alert('Error verifying payment: ' + error);
                    });
            }
        }

        function refundPayment(source, paymentId) {
            if (confirm('Are you sure you want to initiate a refund for this payment?')) {
                window.location.href = 'refund.php?source=' + source + '&id=' + paymentId;
            }
        }

        // Update selected count on page load
        document.addEventListener('DOMContentLoaded', updateSelectedCount);
    </script>
</body>
</html>
<?php $conn->close(); ?>
<?php $conn->close(); ?>