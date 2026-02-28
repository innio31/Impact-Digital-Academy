<?php
// modules/admin/dashboard.php

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

// Initialize stats array
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
    'pending_payments' => 0,
    'overdue_payments' => 0,
    'financial_issues_count' => 0,
    'payment_methods' => [],
    'recent_transactions' => []
];

// Get statistics with error handling
try {
    // Total active users
    $sql = "SELECT COUNT(*) as total_users FROM users WHERE status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_users'] = $row['total_users'] ?? 0;
    }

    // Total students
    $sql = "SELECT COUNT(*) as total_students FROM users WHERE role = 'student' AND status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_students'] = $row['total_students'] ?? 0;
    }

    // Total instructors
    $sql = "SELECT COUNT(*) as total_instructors FROM users WHERE role = 'instructor' AND status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_instructors'] = $row['total_instructors'] ?? 0;
    }

    // Total active programs
    $sql = "SELECT COUNT(*) as total_programs FROM programs WHERE status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_programs'] = $row['total_programs'] ?? 0;
    }

    // Total ongoing classes
    $sql = "SELECT COUNT(*) as total_classes FROM class_batches WHERE status = 'ongoing'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_classes'] = $row['total_classes'] ?? 0;
    }

    // Pending applications
    $sql = "SELECT COUNT(*) as pending_apps FROM applications WHERE status = 'pending'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['pending_apps'] = $row['pending_apps'] ?? 0;
    }

    // Recent enrollments (last 30 days)
    $sql = "SELECT COUNT(*) as recent_enrollments FROM enrollments WHERE enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['recent_enrollments'] = $row['recent_enrollments'] ?? 0;
    }

    // Get financial dashboard statistics
    $finance_stats = getFinanceDashboardStats('month');
    $stats = array_merge($stats, $finance_stats);

    // Monthly revenue calculation
    $sql = "SELECT COALESCE(SUM(p.fee), 0) as monthly_revenue 
            FROM enrollments e 
            JOIN class_batches cb ON e.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            JOIN programs p ON c.program_id = p.id 
            WHERE MONTH(e.enrollment_date) = MONTH(CURDATE()) 
            AND YEAR(e.enrollment_date) = YEAR(CURDATE())
            AND e.status = 'active'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['monthly_revenue'] = $row['monthly_revenue'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Dashboard statistics error: " . $e->getMessage());
}

// Get recent activity logs
$activities = [];
$sql = "SELECT al.*, u.first_name, u.last_name, u.email 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT 10";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $activities = $result->fetch_all(MYSQLI_ASSOC);
}

// Get pending applications for review
$pending_applications = [];
$sql = "SELECT a.*, u.first_name, u.last_name, u.email, u.phone, 
               p.name as program_name, p.program_code 
        FROM applications a 
        JOIN users u ON a.user_id = u.id 
        LEFT JOIN programs p ON a.program_id = p.id 
        WHERE a.status = 'pending' 
        ORDER BY a.created_at DESC 
        LIMIT 5";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $pending_applications = $result->fetch_all(MYSQLI_ASSOC);
}

// Get system notifications for current user
$notifications = [];
$user_id = $_SESSION['user_id'] ?? 0;
$sql = "SELECT * FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
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
$sql = "SELECT cb.*, c.title as course_title, p.name as program_name, 
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name 
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        LEFT JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.start_date >= CURDATE() 
        AND cb.status = 'scheduled' 
        ORDER BY cb.start_date ASC 
        LIMIT 5";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $upcoming_classes = $result->fetch_all(MYSQLI_ASSOC);
}

// Get students with overdue payments
$overdue_students = [];
$sql = "SELECT sfs.*, u.first_name, u.last_name, u.email, cb.batch_code, 
               c.title as course_title, DATEDIFF(CURDATE(), sfs.next_payment_due) as days_overdue
        FROM student_financial_status sfs
        JOIN users u ON u.id = sfs.student_id
        JOIN class_batches cb ON cb.id = sfs.class_id
        JOIN courses c ON c.id = cb.course_id
        WHERE sfs.balance > 0 
        AND sfs.next_payment_due < CURDATE()
        AND sfs.is_suspended = 0
        ORDER BY sfs.next_payment_due ASC
        LIMIT 5";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $overdue_students = $result->fetch_all(MYSQLI_ASSOC);
}

// Log dashboard access
logActivity($_SESSION['user_id'], 'admin_dashboard_access', 'Admin accessed dashboard', $_SERVER['REMOTE_ADDR']);

// Close database connection
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f8fafc;
            color: #334155;
            line-height: 1.5;
        }

        /* Color Variables */
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
            --sidebar-width: 260px;
            --sidebar-mobile-width: 85%;
            --header-height: 70px;
        }

        /* Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar - Mobile First Approach */
        .sidebar {
            width: var(--sidebar-mobile-width);
            max-width: 320px;
            background: linear-gradient(180deg, var(--gray-800) 0%, var(--gray-900) 100%);
            color: white;
            position: fixed;
            left: -100%;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: left 0.3s ease-in-out;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar.active {
            left: 0;
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Desktop sidebar */
        @media (min-width: 1024px) {
            .sidebar {
                width: var(--sidebar-width);
                left: 0;
                position: fixed;
                box-shadow: none;
            }

            .sidebar-overlay {
                display: none !important;
            }
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .logo-text {
            font-weight: 600;
            font-size: 1.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }

        .user-details {
            min-width: 0;
            flex: 1;
        }

        .user-details h3 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--gray-300);
            text-decoration: none;
            transition: all 0.2s ease;
            gap: 0.75rem;
            min-height: 48px;
            /* Better touch target */
        }

        .nav-item:hover,
        .nav-item:active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-item.active {
            background: rgba(67, 97, 238, 0.2);
            color: white;
            border-left: 3px solid var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .nav-label {
            flex: 1;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge {
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            padding: 0.125rem 0.4rem;
            border-radius: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-danger {
            background: var(--danger);
        }

        .nav-dropdown {
            position: relative;
        }

        .dropdown-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            background: rgba(0, 0, 0, 0.2);
        }

        .nav-dropdown.active .dropdown-content {
            max-height: 500px;
            transition: max-height 0.5s ease-in;
        }

        .dropdown-content .nav-item {
            padding-left: 3rem;
        }

        .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .dropdown-toggle .fa-chevron-down,
        .dropdown-toggle .fa-chevron-up {
            font-size: 0.75rem;
            transition: transform 0.3s ease;
            margin-left: auto;
        }

        .nav-dropdown.active .dropdown-toggle .fa-chevron-down {
            transform: rotate(180deg);
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        /* Top Bar - Mobile Optimized */
        .top-bar {
            background: white;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 90;
            height: var(--header-height);
        }

        .mobile-menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-700);
            cursor: pointer;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: var(--gray-100);
            flex-shrink: 0;
        }

        .mobile-menu-toggle:active {
            background: var(--gray-200);
        }

        @media (min-width: 1024px) {
            .mobile-menu-toggle {
                display: none;
            }
        }

        .page-title {
            flex: 1;
            min-width: 0;
        }

        .page-title h1 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.125rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .page-title p {
            font-size: 0.75rem;
            color: var(--gray-600);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (min-width: 640px) {
            .page-title h1 {
                font-size: 1.5rem;
            }

            .page-title p {
                font-size: 0.875rem;
            }
        }

        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        @media (min-width: 640px) {
            .top-bar-actions {
                gap: 1rem;
            }
        }

        .search-box {
            display: none;
            position: relative;
        }

        @media (min-width: 768px) {
            .search-box {
                display: block;
            }
        }

        .search-box input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 0.875rem;
            width: 200px;
            transition: all 0.2s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
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
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
        }

        .action-icon:active {
            background: var(--gray-200);
            transform: scale(0.95);
        }

        .notification-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
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
            min-width: 200px;
            display: none;
            z-index: 1000;
            margin-top: 0.5rem;
        }

        .user-menu-dropdown.active {
            display: block;
        }

        .user-menu-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--gray-700);
            text-decoration: none;
            gap: 0.75rem;
            transition: all 0.2s ease;
            min-height: 44px;
        }

        .user-menu-item:active {
            background: var(--gray-100);
        }

        /* Stats Grid - Mobile Optimized */
        .stats-grid {
            padding: 1rem;
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                padding: 1.5rem;
                gap: 1.5rem;
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        @media (min-width: 640px) {
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }
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
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.2;
            word-break: break-word;
        }

        @media (min-width: 640px) {
            .stat-value {
                font-size: 2rem;
            }
        }

        .stat-change {
            font-size: 0.75rem;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Main Content Grid - Mobile Optimized */
        .content-grid {
            flex: 1;
            padding: 0 1rem 1rem;
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                padding: 0 1.5rem 1.5rem;
                grid-template-columns: 2fr 1fr;
                gap: 1.5rem;
            }
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        @media (min-width: 640px) {
            .content-card {
                padding: 1.5rem;
            }
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        @media (min-width: 640px) {
            .card-title {
                font-size: 1.25rem;
            }
        }

        /* Tables - Mobile Optimized */
        .table-container {
            overflow-x: auto;
            margin: 0 -0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            padding: 0.75rem 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            background: var(--gray-50);
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.8rem;
        }

        /* Mobile card view for tables on very small screens */
        @media (max-width: 480px) {
            .table-container {
                overflow-x: visible;
            }

            .data-table {
                min-width: 0;
                display: block;
            }

            .data-table thead {
                display: none;
            }

            .data-table tbody {
                display: block;
            }

            .data-table tr {
                display: block;
                padding: 1rem;
                background: var(--gray-50);
                border-radius: 8px;
                margin-bottom: 1rem;
                border: 1px solid var(--gray-200);
            }

            .data-table td {
                display: flex;
                align-items: center;
                padding: 0.5rem 0;
                border-bottom: 1px solid var(--gray-200);
                border-bottom: none;
                font-size: 0.875rem;
            }

            .data-table td:before {
                content: attr(data-label);
                font-weight: 600;
                width: 100px;
                min-width: 100px;
                color: var(--gray-600);
            }

            .data-table td:last-child {
                border-bottom: none;
            }

            /* Special handling for transaction rows */
            .data-table td:first-child:before {
                content: "Student:";
            }

            .data-table td:nth-child(2):before {
                content: "Transaction:";
            }

            .data-table td:nth-child(3):before {
                content: "Amount:";
            }

            .data-table td:nth-child(4):before {
                content: "Method:";
            }

            .data-table td:nth-child(5):before {
                content: "Date:";
            }

            .data-table td:nth-child(6):before {
                content: "Status:";
            }
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-overdue {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Quick Actions - Mobile Optimized */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        @media (min-width: 480px) {
            .quick-actions-grid {
                gap: 1rem;
            }
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem 0.5rem;
            background: var(--gray-50);
            border-radius: 10px;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.2s ease;
            min-height: 100px;
        }

        .quick-action:active {
            background: var(--primary);
            color: white;
            transform: scale(0.98);
        }

        .quick-action:active .quick-action-icon {
            background: white;
            color: var(--primary);
        }

        .quick-action-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }

        .quick-action-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
            word-break: break-word;
        }

        @media (min-width: 640px) {
            .quick-action-icon {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .quick-action-label {
                font-size: 0.875rem;
            }
        }

        /* Payment Methods */
        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .payment-method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: 8px;
            transition: all 0.2s ease;
            min-height: 56px;
        }

        .payment-method-item:active {
            background: var(--gray-100);
            transform: scale(0.98);
        }

        .payment-method-name {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.8rem;
            flex-wrap: wrap;
        }

        .payment-method-name i {
            width: 20px;
        }

        .payment-method-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.8rem;
            white-space: nowrap;
        }

        /* Overdue Students */
        .overdue-student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(239, 68, 68, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--danger);
            margin-bottom: 0.75rem;
            gap: 1rem;
        }

        .overdue-student-info {
            flex: 1;
            min-width: 0;
        }

        .overdue-student-info h4 {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .overdue-student-info p {
            font-size: 0.7rem;
            color: var(--gray-600);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .overdue-amount {
            font-weight: 700;
            color: var(--danger);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        @media (min-width: 640px) {
            .overdue-amount {
                font-size: 1rem;
            }
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
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
            font-size: 0.9rem;
            background: white;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 0.125rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-meta {
            font-size: 0.7rem;
            color: var(--gray-600);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            gap: 0.5rem;
            min-height: 36px;
            white-space: nowrap;
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:active {
            background: var(--primary-dark);
        }

        .btn-sm {
            padding: 0.375rem 0.5rem;
            font-size: 0.7rem;
            min-height: 32px;
        }

        /* Footer - Mobile Optimized */
        .dashboard-footer {
            background: white;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: var(--gray-600);
        }

        @media (min-width: 640px) {
            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1rem 1.5rem;
                font-size: 0.875rem;
            }
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        /* Mobile Search Modal */
        .mobile-search-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: white;
            z-index: 1100;
            padding: 1rem;
        }

        .mobile-search-modal.active {
            display: block;
        }

        .mobile-search-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .mobile-search-input {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 1rem;
        }

        .mobile-search-close {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: var(--gray-100);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        @media (max-width: 767px) {
            .mobile-search-toggle {
                display: flex !important;
            }
        }

        .mobile-search-toggle {
            display: none;
        }

        /* Touch-friendly improvements */
        button,
        .nav-item,
        .quick-action,
        .btn,
        .action-icon {
            touch-action: manipulation;
        }

        /* Prevent text overflow */
        * {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* Improve scrolling on iOS */
        .sidebar,
        .main-content,
        .table-container {
            -webkit-overflow-scrolling: touch;
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
                    echo $initials;
                    ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?></h3>
                    <p><i class="fas fa-crown"></i> Administrator</p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-label">Dashboard</span>
                </a>

                <!-- Applications Dropdown -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-file-alt"></i>
                        <span class="nav-label">Applications</span>
                        <?php if ($stats['pending_apps'] > 0): ?>
                            <span class="badge"><?php echo $stats['pending_apps']; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php?status=pending" class="nav-item">
                            <i class="fas fa-clock"></i>
                            <span class="nav-label">Pending Review</span>
                            <?php if ($stats['pending_apps'] > 0): ?>
                                <span class="badge"><?php echo $stats['pending_apps']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php?status=under_review" class="nav-item">
                            <i class="fas fa-search"></i>
                            <span class="nav-label">Under Review</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php?status=approved" class="nav-item">
                            <i class="fas fa-check-circle"></i>
                            <span class="nav-label">Approved</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php?status=rejected" class="nav-item">
                            <i class="fas fa-times-circle"></i>
                            <span class="nav-label">Rejected</span>
                        </a>
                    </div>
                </div>

                <!-- Users Management -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-users"></i>
                        <span class="nav-label">Users Management</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="nav-item">
                            <i class="fas fa-list"></i>
                            <span class="nav-label">All Users</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?role=student" class="nav-item">
                            <i class="fas fa-user-graduate"></i>
                            <span class="nav-label">Students</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?role=instructor" class="nav-item">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span class="nav-label">Instructors</span>
                        </a>
                    </div>
                </div>

                <!-- School Management -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-school"></i>
                        <span class="nav-label">School Management</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/schools/manage.php" class="nav-item">
                            <i class="fas fa-list"></i>
                            <span class="nav-label">Manage Schools</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/schools/create.php" class="nav-item">
                            <i class="fas fa-plus-circle"></i>
                            <span class="nav-label">Register a School</span>
                        </a>
                    </div>
                </div>

                <!-- Academic Management -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-graduation-cap"></i>
                        <span class="nav-label">Academic Management</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/" class="nav-item">
                            <i class="fas fa-project-diagram"></i>
                            <span class="nav-label">Programs</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/" class="nav-item">
                            <i class="fas fa-book"></i>
                            <span class="nav-label">Courses</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="nav-item">
                            <i class="fas fa-chalkboard"></i>
                            <span class="nav-label">Classes/Batches</span>
                        </a>
                    </div>
                </div>

                <!-- Finance Management -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-money-bill-wave"></i>
                        <span class="nav-label">Finance Management</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php" class="nav-item">
                            <i class="fas fa-tachometer-alt"></i>
                            <span class="nav-label">Financial Overview</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/verify.php" class="nav-item">
                            <i class="fas fa-check-circle"></i>
                            <span class="nav-label">Verify Payments</span>
                            <?php
                            if (isset($conn)) {
                                $sql = "SELECT COUNT(*) as count FROM payment_verifications WHERE status = 'pending'";
                                $result = $conn->query($sql);
                                if ($result) {
                                    $count = $result->fetch_assoc()['count'];
                                    if ($count > 0): ?>
                                        <span class="badge badge-danger"><?php echo $count; ?></span>
                            <?php endif;
                                }
                            }
                            ?>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/index.php" class="nav-item">
                            <i class="fas fa-credit-card"></i>
                            <span class="nav-label">Payment Management</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/index.php" class="nav-item">
                            <i class="fas fa-file-invoice"></i>
                            <span class="nav-label">Invoice Management</span>
                        </a>
                    </div>
                </div>

                <!-- System -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-cogs"></i>
                        <span class="nav-label">System</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php" class="nav-item">
                            <i class="fas fa-history"></i>
                            <span class="nav-label">System Logs</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php" class="nav-item">
                            <i class="fas fa-sliders-h"></i>
                            <span class="nav-label">System Settings</span>
                        </a>
                    </div>
                </div>

                <div class="nav-divider"></div>

                <a href="<?php echo BASE_URL; ?>modules/admin/profile/edit.php" class="nav-item">
                    <i class="fas fa-user"></i>
                    <span class="nav-label">My Profile</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/admin/help/" class="nav-item">
                    <i class="fas fa-question-circle"></i>
                    <span class="nav-label">Help & Docs</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-label">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="page-title">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?>!</p>
                </div>

                <div class="top-bar-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search..." id="searchInput">
                    </div>

                    <button class="action-icon mobile-search-toggle" onclick="openMobileSearch()">
                        <i class="fas fa-search"></i>
                    </button>

                    <button class="action-icon" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-count"><?php echo min(count($notifications), 9); ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="user-menu">
                        <button class="action-icon" onclick="toggleUserMenu()">
                            <div class="user-avatar" style="width: 36px; height: 36px;">
                                <?php echo $initials; ?>
                            </div>
                        </button>
                        <div class="user-menu-dropdown" id="userMenuDropdown">
                            <a href="<?php echo BASE_URL; ?>modules/admin/profile/edit.php" class="user-menu-item">
                                <i class="fas fa-user"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/admin/settings.php" class="user-menu-item">
                                <i class="fas fa-cog"></i>
                                <span>Account Settings</span>
                            </a>
                            <div class="user-menu-divider"></div>
                            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="user-menu-item">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile Search Modal -->
            <div class="mobile-search-modal" id="mobileSearchModal">
                <div class="mobile-search-header">
                    <input type="text" class="mobile-search-input" placeholder="Search users, classes, applications..." id="mobileSearchInput">
                    <button class="mobile-search-close" onclick="closeMobileSearch()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="mobileSearchResults" style="padding: 1rem 0;">
                    <!-- Search results will appear here -->
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <!-- Total Revenue -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Total Revenue</div>
                        <div class="stat-icon success">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value">₦<?php echo number_format($stats['total_revenue'], 2); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-calendar"></i>
                        Last 30 days
                    </div>
                </div>

                <!-- Pending Payments -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Pending Payments</div>
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending_payments_count'] ?? 0); ?></div>
                    <div class="stat-change">
                        ₦<?php echo number_format($stats['pending_amount'] ?? 0, 2); ?> pending
                    </div>
                </div>

                <!-- Overdue Payments -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Overdue Payments</div>
                        <div class="stat-icon danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['overdue_count'] ?? 0); ?></div>
                    <div class="stat-change">
                        ₦<?php echo number_format($stats['overdue_amount'] ?? 0, 2); ?> overdue
                    </div>
                </div>

                <!-- Active Students -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Active Students</div>
                        <div class="stat-icon primary">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <?php echo number_format($stats['total_instructors']); ?> instructors
                    </div>
                </div>

                <!-- Total Users -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Total Users</div>
                        <div class="stat-icon info">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-user-plus"></i>
                        <?php echo number_format($stats['recent_enrollments']); ?> recent
                    </div>
                </div>

                <!-- Pending Applications -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Pending Apps</div>
                        <div class="stat-icon info">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending_apps']); ?></div>
                    <div class="stat-change">
                        <?php if ($stats['pending_apps'] > 0): ?>
                            <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i>
                            Needs attention
                        <?php else: ?>
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            All clear
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Revenue -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Monthly Revenue</div>
                        <div class="stat-icon success">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value">₦<?php echo number_format($stats['monthly_revenue'], 2); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-project-diagram"></i>
                        <?php echo number_format($stats['total_programs']); ?> programs
                    </div>
                </div>

                <!-- Active Programs & Classes -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Active Programs</div>
                        <div class="stat-icon primary">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_programs']); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <?php echo number_format($stats['total_classes']); ?> ongoing classes
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Financial Transactions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Recent Financial Transactions</h2>
                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/" class="btn btn-primary btn-sm">
                                View All
                            </a>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Transaction</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stats['recent_transactions'])): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                                <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: var(--gray-300);"></i>
                                                No recent transactions
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stats['recent_transactions'] as $transaction): ?>
                                            <tr>
                                                <td data-label="Student">
                                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                        <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.875rem; flex-shrink: 0;">
                                                            <?php echo strtoupper(substr($transaction['first_name'] ?? 'S', 0, 1)); ?>
                                                        </div>
                                                        <div style="min-width: 0;">
                                                            <div style="font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                                            <div style="font-size: 0.75rem; color: var(--gray-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($transaction['batch_code'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Transaction">
                                                    <span style="font-size: 0.875rem;">
                                                        <?php echo ucfirst(str_replace('_', ' ', $transaction['transaction_type'] ?? 'payment')); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Amount" style="font-weight: 700; color: var(--success);">
                                                    ₦<?php echo number_format($transaction['amount'] ?? 0, 2); ?>
                                                </td>
                                                <td data-label="Method">
                                                    <span class="status-badge" style="background-color: var(--gray-100); color: var(--gray-700);">
                                                        <?php echo ucfirst($transaction['payment_method'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Date">
                                                    <?php echo date('M d, Y', strtotime($transaction['created_at'] ?? date('Y-m-d H:i:s'))); ?> <br>
                                                    <small style="color: var(--gray-500);"><?php echo date('h:i A', strtotime($transaction['created_at'] ?? date('Y-m-d H:i:s'))); ?></small>
                                                </td>
                                                <td data-label="Status">
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
                    <div class="content-card" style="margin-top: 1rem;">
                        <div class="card-header">
                            <h2 class="card-title">Overdue Payments</h2>
                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/overdue.php" class="btn btn-primary btn-sm">
                                View All
                            </a>
                        </div>

                        <?php if (empty($overdue_students)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: var(--success);"></i>
                                No overdue payments
                            </div>
                        <?php else: ?>
                            <?php foreach ($overdue_students as $student): ?>
                                <div class="overdue-student-item">
                                    <div class="overdue-student-info">
                                        <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                        <p>
                                            <?php echo htmlspecialchars($student['course_title']); ?> •
                                            <?php echo htmlspecialchars($student['batch_code']); ?>
                                            <br>
                                            <small>
                                                <i class="fas fa-calendar"></i>
                                                <?php echo $student['days_overdue']; ?> days overdue
                                            </small>
                                        </p>
                                    </div>
                                    <div class="overdue-amount">
                                        ₦<?php echo number_format($student['balance'], 2); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Payment Methods Distribution -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Payment Methods</h2>
                            <span style="font-size: 0.75rem; color: var(--gray-500);">Last 30 days</span>
                        </div>

                        <div class="payment-methods">
                            <?php if (empty($stats['payment_methods'])): ?>
                                <div style="text-align: center; padding: 1rem; color: var(--gray-500);">
                                    <i class="fas fa-credit-card" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                                    No payment data
                                </div>
                            <?php else: ?>
                                <?php foreach ($stats['payment_methods'] as $method): ?>
                                    <div class="payment-method-item">
                                        <div class="payment-method-name">
                                            <?php
                                            $icons = [
                                                'online' => 'fa-globe',
                                                'bank_transfer' => 'fa-university',
                                                'cash' => 'fa-money-bill',
                                                'cheque' => 'fa-money-check',
                                                'pos' => 'fa-credit-card'
                                            ];
                                            $icon = $icons[$method['payment_method']] ?? 'fa-money-bill';
                                            ?>
                                            <i class="fas <?php echo $icon; ?>" style="color: var(--primary);"></i>
                                            <span><?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?></span>
                                        </div>
                                        <div class="payment-method-count">
                                            <?php echo $method['count']; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="content-card" style="margin-top: 1rem;">
                        <div class="card-header">
                            <h2 class="card-title">Quick Actions</h2>
                        </div>

                        <div class="quick-actions-grid">
                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/generate.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                                <div class="quick-action-label">Generate Invoice</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/verify.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="quick-action-label">Verify Payments</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/overdue.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="quick-action-label">Overdue Payments</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/reports/revenue.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="quick-action-label">Revenue Report</div>
                            </a>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div class="content-card" style="margin-top: 1rem;">
                        <div class="card-header">
                            <h2 class="card-title">System Status</h2>
                        </div>

                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Database Connection</div>
                                    <div class="activity-meta">
                                        <span style="color: var(--success);">
                                            <i class="fas fa-check-circle"></i> Active
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Financial Calculations</div>
                                    <div class="activity-meta">
                                        <span style="color: var(--success);">
                                            <i class="fas fa-check-circle"></i> Operational
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Payment Reminders</div>
                                    <div class="activity-meta">
                                        <?php if (($stats['overdue_count'] ?? 0) > 0): ?>
                                            <span style="color: var(--warning);">
                                                <i class="fas fa-exclamation-circle"></i> <?php echo $stats['overdue_count']; ?> pending
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--success);">
                                                <i class="fas fa-check-circle"></i> Up to date
                                            </span>
                                        <?php endif; ?>
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
                    <span>System: Operational</span>
                </div>
                <div>
                    <span>Updated: <?php echo date('M j, Y g:i a'); ?></span>
                    <?php if ($stats['total_revenue'] > 0): ?>
                        <span style="margin-left: 0.5rem; color: var(--success); font-weight: 600;">
                            <i class="fas fa-money-bill-wave"></i>
                            ₦<?php echo number_format($stats['total_revenue'], 2); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize on DOM load
        document.addEventListener('DOMContentLoaded', function() {
            initializeAll();
        });

        function initializeAll() {
            initializeDropdowns();
            initializeKeyboardShortcuts();
            initializeTouchEvents();
        }

        // Sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Dropdown functions
        function toggleDropdown(element) {
            const parentDropdown = element.closest('.nav-dropdown');
            const wasActive = parentDropdown.classList.contains('active');

            // Close all dropdowns first
            document.querySelectorAll('.nav-dropdown').forEach(dropdown => {
                dropdown.classList.remove('active');
            });

            // If this dropdown wasn't active, open it
            if (!wasActive) {
                parentDropdown.classList.add('active');
            }
        }

        // Close all dropdowns
        function closeAllDropdowns() {
            document.querySelectorAll('.nav-dropdown').forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }

        // User menu
        function toggleUserMenu() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.classList.toggle('active');

            // Close when clicking outside
            if (dropdown.classList.contains('active')) {
                setTimeout(() => {
                    document.addEventListener('click', closeUserMenuOnClickOutside);
                }, 100);
            }
        }

        function closeUserMenuOnClickOutside(event) {
            const dropdown = document.getElementById('userMenuDropdown');
            const userMenuBtn = document.querySelector('.user-menu .action-icon');

            if (!dropdown.contains(event.target) && !userMenuBtn.contains(event.target)) {
                dropdown.classList.remove('active');
                document.removeEventListener('click', closeUserMenuOnClickOutside);
            }
        }

        // Notifications
        function toggleNotifications() {
            const notificationCount = document.querySelector('.notification-count');

            if (notificationCount) {
                const count = parseInt(notificationCount.textContent);
                if (count > 0) {
                    if (confirm(`You have ${count} unread notifications. Mark all as read?`)) {
                        notificationCount.style.display = 'none';
                        alert('All notifications marked as read!');
                    }
                } else {
                    alert('No new notifications');
                }
            } else {
                alert('No new notifications');
            }
        }

        // Mobile Search
        function openMobileSearch() {
            document.getElementById('mobileSearchModal').classList.add('active');
            document.getElementById('mobileSearchInput').focus();
        }

        function closeMobileSearch() {
            document.getElementById('mobileSearchModal').classList.remove('active');
        }

        // Search functionality
        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch(this.value);
                    }
                });
            }

            if (mobileSearchInput) {
                mobileSearchInput.addEventListener('keyup', function(e) {
                    performSearch(this.value);
                });
            }
        }

        function performSearch(query) {
            if (query.trim().length < 2) return;

            const resultsDiv = document.getElementById('mobileSearchResults');
            if (resultsDiv) {
                resultsDiv.innerHTML = `<div style="text-align: center; padding: 1rem; color: var(--gray-500);">
                    <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                    Searching for "${query}"...
                </div>`;

                // Simulate search results (replace with actual AJAX call)
                setTimeout(() => {
                    resultsDiv.innerHTML = `<div style="text-align: center; padding: 1rem; color: var(--gray-500);">
                        No results found for "${query}"
                    </div>`;
                }, 1000);
            }
        }

        // Keyboard shortcuts
        function initializeKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + K for search
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    if (window.innerWidth >= 768) {
                        document.getElementById('searchInput')?.focus();
                    } else {
                        openMobileSearch();
                    }
                }

                // Escape key closes everything
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                    closeSidebar();
                    closeMobileSearch();
                    document.getElementById('userMenuDropdown')?.classList.remove('active');
                }
            });
        }

        // Touch events
        function initializeTouchEvents() {
            // Swipe to close sidebar on mobile
            let touchStartX = 0;
            let touchEndX = 0;

            document.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            }, false);

            document.addEventListener('touchend', e => {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
            }, false);

            function handleSwipe() {
                const sidebar = document.getElementById('sidebar');
                const swipeThreshold = 100;

                if (touchEndX < touchStartX - swipeThreshold && sidebar.classList.contains('active')) {
                    // Swipe left to close
                    closeSidebar();
                }
            }
        }

        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                // Close mobile elements when resizing to desktop
                if (window.innerWidth >= 1024) {
                    closeSidebar();
                }
            }, 250);
        });
    </script>
</body>

</html>