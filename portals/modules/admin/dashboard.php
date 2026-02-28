<?php
// modules/admin/dashboard.php

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/finance_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Initialize stats array with defaults
$stats = [
    'total_users' => 0,
    'total_students' => 0,
    'total_instructors' => 0,
    'total_programs' => 0,
    'total_classes' => 0,
    'pending_apps' => 0,
    'recent_enrollments' => 0,
    'monthly_revenue' => 0,
    'total_revenue' => 0,
    'pending_payments_count' => 0,
    'pending_amount' => 0,
    'overdue_count' => 0,
    'overdue_amount' => 0,
    'payment_methods' => [],
    'recent_transactions' => []
];

// Get statistics with error handling
try {
    // Total active users
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    if ($result) {
        $stats['total_users'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Total students
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
    if ($result) {
        $stats['total_students'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Total instructors
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'instructor' AND status = 'active'");
    if ($result) {
        $stats['total_instructors'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Total active programs
    $result = $conn->query("SELECT COUNT(*) as total FROM programs WHERE status = 'active'");
    if ($result) {
        $stats['total_programs'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Total ongoing classes
    $result = $conn->query("SELECT COUNT(*) as total FROM class_batches WHERE status = 'ongoing'");
    if ($result) {
        $stats['total_classes'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Pending applications
    $result = $conn->query("SELECT COUNT(*) as total FROM applications WHERE status = 'pending'");
    if ($result) {
        $stats['pending_apps'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Recent enrollments (last 30 days)
    $result = $conn->query("SELECT COUNT(*) as total FROM enrollments WHERE enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'active'");
    if ($result) {
        $stats['recent_enrollments'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Monthly revenue
    $result = $conn->query("SELECT COALESCE(SUM(p.fee), 0) as total 
            FROM enrollments e 
            JOIN class_batches cb ON e.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            JOIN programs p ON c.program_id = p.id 
            WHERE MONTH(e.enrollment_date) = MONTH(CURDATE()) 
            AND YEAR(e.enrollment_date) = YEAR(CURDATE())
            AND e.status = 'active'");
    if ($result) {
        $stats['monthly_revenue'] = $result->fetch_assoc()['total'] ?? 0;
        $result->free();
    }

    // Get recent transactions
    $result = $conn->query("SELECT t.*, u.first_name, u.last_name, cb.batch_code 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            LEFT JOIN class_batches cb ON t.class_id = cb.id 
            ORDER BY t.created_at DESC 
            LIMIT 5");
    if ($result && $result->num_rows > 0) {
        $stats['recent_transactions'] = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    // Get payment methods distribution
    $result = $conn->query("SELECT payment_method, COUNT(*) as count 
            FROM transactions 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY payment_method");
    if ($result && $result->num_rows > 0) {
        $stats['payment_methods'] = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
} catch (Exception $e) {
    error_log("Dashboard statistics error: " . $e->getMessage());
}

// Get recent activity logs
$activities = [];
$result = $conn->query("SELECT al.*, u.first_name, u.last_name, u.email 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT 10");
if ($result && $result->num_rows > 0) {
    $activities = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

// Get pending applications for review
$pending_applications = [];
$result = $conn->query("SELECT a.*, u.first_name, u.last_name, u.email, u.phone, 
               p.name as program_name, p.program_code 
        FROM applications a 
        JOIN users u ON a.user_id = u.id 
        LEFT JOIN programs p ON a.program_id = p.id 
        WHERE a.status = 'pending' 
        ORDER BY a.created_at DESC 
        LIMIT 5");
if ($result && $result->num_rows > 0) {
    $pending_applications = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

// Get system notifications for current user
$notifications = [];
$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $conn->prepare("SELECT * FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 10");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get upcoming classes
$upcoming_classes = [];
$result = $conn->query("SELECT cb.*, c.title as course_title, p.name as program_name, 
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name 
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        LEFT JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.start_date >= CURDATE() 
        AND cb.status = 'scheduled' 
        ORDER BY cb.start_date ASC 
        LIMIT 5");
if ($result && $result->num_rows > 0) {
    $upcoming_classes = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

// Get students with overdue payments
$overdue_students = [];
$result = $conn->query("SELECT sfs.*, u.first_name, u.last_name, u.email, cb.batch_code, 
               c.title as course_title, DATEDIFF(CURDATE(), sfs.next_payment_due) as days_overdue
        FROM student_financial_status sfs
        JOIN users u ON u.id = sfs.student_id
        JOIN class_batches cb ON cb.id = sfs.class_id
        JOIN courses c ON c.id = cb.course_id
        WHERE sfs.balance > 0 
        AND sfs.next_payment_due < CURDATE()
        AND sfs.is_suspended = 0
        ORDER BY sfs.next_payment_due ASC
        LIMIT 5");
if ($result && $result->num_rows > 0) {
    $overdue_students = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

// Log dashboard access
if (function_exists('logActivity')) {
    logActivity($_SESSION['user_id'], 'admin_dashboard_access', 'Admin accessed dashboard', $_SERVER['REMOTE_ADDR']);
}

// DO NOT close the database connection here if you need it later in the HTML
// The connection will be closed automatically at the end of script execution
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* RESET & BASE STYLES */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            /* Spacing */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;

            /* Font sizes */
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-md: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-700);
            line-height: 1.5;
            overflow-x: hidden;
        }

        /* Mobile-first layout */
        .app-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, var(--gray-800) 0%, var(--gray-900) 100%);
            color: white;
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar.active {
            left: 0;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        .sidebar-header {
            padding: var(--space-lg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .user-info {
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .user-details h3 {
            font-size: var(--text-sm);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-details p {
            font-size: var(--text-xs);
            color: var(--gray-400);
        }

        .sidebar-nav {
            padding: var(--space-md) 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: var(--space-sm) var(--space-lg);
            color: var(--gray-300);
            text-decoration: none;
            gap: var(--space-sm);
            font-size: var(--text-sm);
            border-left: 3px solid transparent;
        }

        .nav-item:hover,
        .nav-item.active {
            background: rgba(67, 97, 238, 0.2);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        .badge {
            background: var(--primary);
            color: white;
            font-size: var(--text-xs);
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            margin-left: auto;
        }

        .dropdown-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            background: rgba(0, 0, 0, 0.2);
        }

        .nav-dropdown.active .dropdown-content {
            max-height: 500px;
        }

        .dropdown-content .nav-item {
            padding-left: calc(var(--space-lg) * 2);
        }

        .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .dropdown-toggle .fa-chevron-down {
            margin-left: auto;
            font-size: var(--text-xs);
        }

        .sidebar-footer {
            padding: var(--space-lg);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            width: 100%;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: var(--space-sm) var(--space-md);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            gap: var(--space-sm);
        }

        .mobile-menu-toggle {
            background: none;
            border: none;
            font-size: var(--text-xl);
            color: var(--gray-700);
            cursor: pointer;
            padding: var(--space-xs);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .page-title {
            flex: 1;
            min-width: 0;
        }

        .page-title h1 {
            font-size: var(--text-lg);
            font-weight: 600;
            color: var(--gray-800);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .page-title p {
            font-size: var(--text-xs);
            color: var(--gray-600);
            display: none;
        }

        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--gray-100);
            border: none;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
        }

        .notification-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            font-size: var(--text-xs);
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-menu {
            position: relative;
        }

        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 180px;
            display: none;
            z-index: 1000;
        }

        .user-menu:hover .user-menu-dropdown {
            display: block;
        }

        .user-menu-item {
            display: flex;
            align-items: center;
            padding: var(--space-sm) var(--space-md);
            color: var(--gray-700);
            text-decoration: none;
            gap: var(--space-sm);
            font-size: var(--text-sm);
        }

        .user-menu-item:hover {
            background: var(--gray-100);
        }

        /* Stats Grid */
        .stats-grid {
            padding: var(--space-md);
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--space-md);
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: var(--space-md);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--space-sm);
        }

        .stat-title {
            font-size: var(--text-xs);
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-lg);
        }

        .stat-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-icon.primary {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .stat-icon.info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .stat-change {
            font-size: var(--text-xs);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Content Grid */
        .content-grid {
            padding: 0 var(--space-md) var(--space-md);
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--space-md);
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: var(--space-md);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-md);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: var(--text-md);
            font-weight: 600;
            color: var(--gray-800);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            margin: 0 calc(var(--space-md) * -1);
            padding: 0 var(--space-md);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }

        .data-table th {
            padding: var(--space-xs) var(--space-sm);
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            background: var(--gray-50);
            font-size: var(--text-xs);
        }

        .data-table td {
            padding: var(--space-sm);
            border-bottom: 1px solid var(--gray-200);
            font-size: var(--text-xs);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: var(--text-xs);
            font-weight: 600;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-sm);
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: var(--space-md) var(--space-xs);
            background: var(--gray-50);
            border-radius: 10px;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.2s ease;
            text-align: center;
        }

        .quick-action:hover {
            background: var(--primary);
            color: white;
        }

        .quick-action-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-md);
            margin-bottom: var(--space-xs);
        }

        .quick-action-label {
            font-size: var(--text-xs);
            font-weight: 600;
        }

        /* Payment Methods */
        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }

        .payment-method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-sm);
            background: var(--gray-50);
            border-radius: 8px;
        }

        .payment-method-name {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            font-size: var(--text-xs);
        }

        .payment-method-count {
            font-weight: 600;
            color: var(--primary);
            font-size: var(--text-xs);
        }

        /* Overdue Students */
        .overdue-student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-sm);
            background: rgba(239, 68, 68, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--danger);
            margin-bottom: var(--space-sm);
        }

        .overdue-student-info {
            flex: 1;
            min-width: 0;
        }

        .overdue-student-info h4 {
            font-size: var(--text-xs);
            font-weight: 600;
            margin-bottom: 0.125rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .overdue-student-info p {
            font-size: var(--text-xs);
            color: var(--gray-600);
        }

        .overdue-amount {
            font-weight: 700;
            color: var(--danger);
            font-size: var(--text-sm);
            white-space: nowrap;
            margin-left: var(--space-sm);
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm);
            background: var(--gray-50);
            border-radius: 8px;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-sm);
            background: white;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: var(--text-xs);
            margin-bottom: 0.125rem;
        }

        .activity-meta {
            font-size: var(--text-xs);
            color: var(--gray-600);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: var(--text-xs);
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            gap: 0.25rem;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
        }

        /* Footer */
        .dashboard-footer {
            background: white;
            padding: var(--space-sm) var(--space-md);
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
            align-items: center;
            text-align: center;
            font-size: var(--text-xs);
            color: var(--gray-600);
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Tablet */
        @media (min-width: 768px) {
            .page-title p {
                display: block;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                text-align: left;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .table-container {
                margin: 0;
                padding: 0;
            }

            .data-table {
                min-width: 0;
            }
        }

        /* Desktop */
        @media (min-width: 1024px) {
            .app-container {
                flex-direction: row;
            }

            .sidebar {
                position: sticky;
                left: 0;
                width: 260px;
            }

            .sidebar-overlay {
                display: none !important;
            }

            .main-content {
                width: calc(100% - 260px);
            }

            .mobile-menu-toggle {
                display: none;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Utilities */
        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo-icon">IDA</div>
                <div class="logo-text">Impact Admin</div>
            </div>

            <div class="user-info">
                <div class="user-avatar">
                    <?php
                    $initials = isset($_SESSION['user_name']) ? strtoupper(substr($_SESSION['user_name'], 0, 1)) : 'A';
                    echo htmlspecialchars($initials);
                    ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?></h3>
                    <p><i class="fas fa-crown"></i> Administrator</p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="#" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>

                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle">
                        <i class="fas fa-file-alt"></i>
                        <span>Applications</span>
                        <?php if ($stats['pending_apps'] > 0): ?>
                            <span class="badge"><?php echo $stats['pending_apps']; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="#" class="nav-item">
                            <i class="fas fa-clock"></i>
                            <span>Pending</span>
                        </a>
                        <a href="#" class="nav-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Approved</span>
                        </a>
                    </div>
                </div>

                <a href="#" class="nav-item">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>

                <a href="#" class="nav-item">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Academic</span>
                </a>

                <a href="#" class="nav-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Finance</span>
                </a>

                <a href="#" class="nav-item">
                    <i class="fas fa-cogs"></i>
                    <span>Settings</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item" onclick="return confirm('Are you sure?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <button class="mobile-menu-toggle" onclick="toggleSidebar()" aria-label="Menu">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="page-title">
                    <h1>Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
                </div>

                <div class="top-bar-actions">
                    <button class="action-icon" onclick="alert('Notifications')">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-count"><?php echo min(count($notifications), 9); ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="user-menu">
                        <button class="action-icon">
                            <div class="user-avatar" style="width: 36px; height: 36px;">
                                <?php echo $initials; ?>
                            </div>
                        </button>
                        <div class="user-menu-dropdown">
                            <a href="#" class="user-menu-item">
                                <i class="fas fa-user"></i>
                                <span>Profile</span>
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="user-menu-item">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Total Revenue</div>
                        <div class="stat-icon success">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value">₦<?php echo number_format($stats['total_revenue'], 2); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-calendar"></i> Last 30 days
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Students</div>
                        <div class="stat-icon primary">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-user-plus"></i> <?php echo number_format($stats['recent_enrollments']); ?> new
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Applications</div>
                        <div class="stat-icon warning">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending_apps']); ?></div>
                    <div class="stat-change">
                        <?php if ($stats['pending_apps'] > 0): ?>
                            <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i> Pending review
                        <?php else: ?>
                            <i class="fas fa-check-circle" style="color: var(--success);"></i> All clear
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Classes</div>
                        <div class="stat-icon info">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_classes']); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-book"></i> <?php echo number_format($stats['total_programs']); ?> programs
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Transactions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Recent Transactions</h2>
                            <a href="#" class="btn btn-primary btn-sm">View All</a>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stats['recent_transactions'])): ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; padding: var(--space-xl); color: var(--gray-500);">
                                                <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                                No recent transactions
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stats['recent_transactions'] as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                        <div class="user-avatar" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                                            <?php echo strtoupper(substr($transaction['first_name'] ?? 'S', 0, 1)); ?>
                                                        </div>
                                                        <span class="text-truncate" style="max-width: 100px;">
                                                            <?php echo htmlspecialchars($transaction['first_name'] ?? 'Student'); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td style="font-weight: 600; color: var(--success);">
                                                    ₦<?php echo number_format($transaction['amount'] ?? 0, 2); ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M d', strtotime($transaction['created_at'] ?? date('Y-m-d'))); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-active">
                                                        <?php echo ucfirst($transaction['status'] ?? 'completed'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Overdue Payments -->
                    <div class="content-card" style="margin-top: var(--space-md);">
                        <div class="card-header">
                            <h2 class="card-title">Overdue Payments</h2>
                            <a href="#" class="btn btn-primary btn-sm">View All</a>
                        </div>

                        <?php if (empty($overdue_students)): ?>
                            <div style="text-align: center; padding: var(--space-xl); color: var(--gray-500);">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 0.5rem; display: block; color: var(--success);"></i>
                                No overdue payments
                            </div>
                        <?php else: ?>
                            <?php foreach ($overdue_students as $student): ?>
                                <div class="overdue-student-item">
                                    <div class="overdue-student-info">
                                        <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                        <p>
                                            <?php echo htmlspecialchars($student['course_title'] ?? 'Course'); ?>
                                            <br>
                                            <small><?php echo $student['days_overdue']; ?> days overdue</small>
                                        </p>
                                    </div>
                                    <div class="overdue-amount">
                                        ₦<?php echo number_format($student['balance'] ?? 0, 0); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Quick Actions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Quick Actions</h2>
                        </div>

                        <div class="quick-actions-grid">
                            <a href="#" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                                <div class="quick-action-label">Invoice</div>
                            </a>
                            <a href="#" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="quick-action-label">Verify</div>
                            </a>
                            <a href="#" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="quick-action-label">Overdue</div>
                            </a>
                            <a href="#" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="quick-action-label">Reports</div>
                            </a>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div class="content-card" style="margin-top: var(--space-md);">
                        <div class="card-header">
                            <h2 class="card-title">System Status</h2>
                        </div>

                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-database" style="color: var(--primary);"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Database</div>
                                    <div class="activity-meta">
                                        <span style="color: var(--success);">✓ Connected</span>
                                    </div>
                                </div>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-calculator" style="color: var(--success);"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Financial System</div>
                                    <div class="activity-meta">
                                        <span style="color: var(--success);">✓ Operational</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="dashboard-footer">
                <div class="system-status">
                    <div class="status-indicator"></div>
                    <span>All Systems Operational</span>
                </div>
                <div>
                    Updated: <?php echo date('M j, g:i a'); ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.nav-dropdown');
                parent.classList.toggle('active');

                const chevron = this.querySelector('.fa-chevron-down');
                if (chevron) {
                    chevron.style.transform = parent.classList.contains('active') ? 'rotate(180deg)' : '';
                }
            });
        });

        // Close sidebar on link click (mobile)
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 1024) {
                    closeSidebar();
                }
            });
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                closeSidebar();
            }
        });
    </script>
</body>

</html>