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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
        }

        /* Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--gray-800) 0%, var(--gray-900) 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 100;
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
        }

        .logo-text {
            font-weight: 600;
            font-size: 1.25rem;
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
        }

        .user-details h3 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 0.25rem;
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
        }

        .nav-item:hover {
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
        }

        .nav-label {
            flex: 1;
            font-size: 0.875rem;
        }

        .badge {
            background: var(--primary);
            color: white;
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-weight: 600;
        }

        .badge-danger {
            background: var(--danger);
        }

        .nav-dropdown {
            position: relative;
        }

        .dropdown-content {
            display: none;
            background: rgba(0, 0, 0, 0.2);
        }

        .dropdown-content .nav-item {
            padding-left: 2.5rem;
        }

        .dropdown-toggle.active+.dropdown-content {
            display: block;
        }

        .dropdown-toggle {
            cursor: pointer;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 0.875rem;
            width: 300px;
            transition: all 0.2s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
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
        }

        .action-icon:hover {
            background: var(--gray-200);
        }

        .notification-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            font-size: 0.75rem;
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
        }

        .user-menu:hover .user-menu-dropdown {
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
        }

        .user-menu-item:hover {
            background: var(--gray-100);
        }

        /* Stats Grid */
        .stats-grid {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.875rem;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Main Content Grid */
        .content-grid {
            flex: 1;
            padding: 0 1.5rem 1.5rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            background: var(--gray-50);
            font-size: 0.875rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.875rem;
        }

        .data-table tbody tr:hover {
            background: var(--gray-50);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
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

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            background: var(--gray-50);
            border-radius: 10px;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.2s ease;
        }

        .quick-action:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .quick-action:hover .quick-action-icon {
            background: white;
            color: var(--primary);
        }

        .quick-action-icon {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }

        .quick-action-label {
            font-size: 0.875rem;
            font-weight: 600;
            text-align: center;
        }

        /* Payment Methods */
        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .payment-method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            background: var(--gray-50);
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .payment-method-item:hover {
            background: var(--gray-100);
        }

        .payment-method-name {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
        }

        .payment-method-count {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.875rem;
        }

        /* System Status */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: 8px;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .activity-icon i {
            color: var(--success);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.75rem;
            color: var(--gray-600);
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
        }

        .overdue-student-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .overdue-student-info p {
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .overdue-amount {
            font-weight: 700;
            color: var(--danger);
            font-size: 1rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Footer */
        .dashboard-footer {
            background: white;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
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

        /* Mobile Menu Toggle (hidden by default) */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-700);
            cursor: pointer;
            padding: 0.5rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .search-box input {
                width: 200px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 150px;
            }

            .content-grid {
                padding: 1rem;
            }

            .top-bar {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .search-box {
                display: none;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="app-container">
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
                        <i class="fas fa-users"></i>
                        <span class="nav-label">School Management</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/admin/schools/manage.php" class="nav-item">
                            <i class="fas fa-list"></i>
                            <span class="nav-label">Manage Schools</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/schools/create.php" class="nav-item">
                            <i class="fas fa-user-graduate"></i>
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
                        <input type="text" placeholder="Search users, classes, applications...">
                    </div>

                    <button class="action-icon" onclick="toggleNotifications()">
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
                            <a href="<?php echo BASE_URL; ?>modules/admin/profile/edit.php" class="user-menu-item">
                                <i class="fas fa-user"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/profile/settings.php" class="user-menu-item">
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
                        <?php echo number_format($stats['recent_enrollments']); ?> recent enrollments
                    </div>
                </div>

                <!-- Pending Applications -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Pending Applications</div>
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
                        <div class="stat-title">Active Programs & Classes</div>
                        <div class="stat-icon primary">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_programs']); ?> / <?php echo number_format($stats['total_classes']); ?></div>
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
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                        <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.875rem;">
                                                            <?php echo strtoupper(substr($transaction['first_name'] ?? 'S', 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                                            <div style="font-size: 0.875rem; color: var(--gray-500);"><?php echo htmlspecialchars($transaction['batch_code'] ?? 'N/A'); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span style="font-size: 0.875rem;">
                                                        <?php echo ucfirst(str_replace('_', ' ', $transaction['transaction_type'] ?? 'payment')); ?>
                                                    </span>
                                                </td>
                                                <td style="font-weight: 700; color: var(--success);">
                                                    ₦<?php echo number_format($transaction['amount'] ?? 0, 2); ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge" style="background-color: var(--gray-100); color: var(--gray-700);">
                                                        <?php echo ucfirst($transaction['payment_method'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($transaction['created_at'] ?? date('Y-m-d H:i:s'))); ?> <br>
                                                    <small style="color: var(--gray-500);"><?php echo date('h:i A', strtotime($transaction['created_at'] ?? date('Y-m-d H:i:s'))); ?></small>
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
                    <div class="content-card" style="margin-top: 1.5rem;">
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
                                            <?php echo $method['count']; ?> payments
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="content-card" style="margin-top: 1.5rem;">
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
                    <div class="content-card" style="margin-top: 1.5rem;">
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
                                        <?php if ($stats['overdue_count'] > 0): ?>
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
                    <span>System Status: Operational</span>
                </div>
                <div>
                    <span>Last Updated: <?php echo date('F j, Y, g:i a'); ?></span>
                    <?php if ($stats['total_revenue'] > 0): ?>
                        <span style="margin-left: 0.5rem; color: var(--success); font-weight: 600;">
                            <i class="fas fa-money-bill-wave"></i>
                            ₦<?php echo number_format($stats['total_revenue'], 2); ?> revenue
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
            initializeMobileMenu();
            initializeDropdowns();
            initializeSearch();
            initializeNotifications();
            initializeUserMenu();
            initializeKeyboardShortcuts();
        }

        // Mobile menu functions
        function initializeMobileMenu() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');

            if (mobileToggle) {
                mobileToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 1024 &&
                    sidebar.classList.contains('active') &&
                    !sidebar.contains(event.target) &&
                    !event.target.closest('.mobile-menu-toggle')) {
                    sidebar.classList.remove('active');
                }
            });

            // Close sidebar on window resize (when going back to desktop)
            window.addEventListener('resize', function() {
                if (window.innerWidth > 1024) {
                    sidebar.classList.remove('active');
                }
            });

            // Close sidebar when clicking sidebar links on mobile
            const sidebarLinks = document.querySelectorAll('.sidebar-nav a');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 1024) {
                        sidebar.classList.remove('active');
                    }
                });
            });
        }

        // Dropdown functions - SIMPLIFIED AND FIXED
        function initializeDropdowns() {
            // Remove any existing click handlers first
            const dropdownToggles = document.querySelectorAll('.dropdown-toggle');

            dropdownToggles.forEach(toggle => {
                // Clone and replace to remove any existing event listeners
                const newToggle = toggle.cloneNode(true);
                toggle.parentNode.replaceChild(newToggle, toggle);

                // Add new click event listener
                newToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Get the parent dropdown
                    const parentDropdown = this.closest('.nav-dropdown');
                    const wasActive = parentDropdown.classList.contains('active');

                    // Close all dropdowns first
                    closeAllDropdowns();

                    // If this dropdown wasn't active, open it
                    if (!wasActive) {
                        parentDropdown.classList.add('active');
                        // Update chevron icon
                        const chevron = this.querySelector('.fa-chevron-down');
                        if (chevron) {
                            chevron.classList.remove('fa-chevron-down');
                            chevron.classList.add('fa-chevron-up');
                        }
                    }
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.nav-dropdown')) {
                    closeAllDropdowns();
                }
            });

            // Close dropdowns on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        }

        function closeAllDropdowns() {
            const dropdowns = document.querySelectorAll('.nav-dropdown');
            dropdowns.forEach(dropdown => {
                dropdown.classList.remove('active');

                // Reset chevron icons
                const chevron = dropdown.querySelector('.fa-chevron-up');
                if (chevron) {
                    chevron.classList.remove('fa-chevron-up');
                    chevron.classList.add('fa-chevron-down');
                }
            });
        }

        // Search functionality
        function initializeSearch() {
            const searchInput = document.querySelector('.search-box input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch(this.value);
                    }
                });
            }
        }

        function performSearch(query) {
            if (query.trim().length < 2) {
                alert('Please enter at least 2 characters to search');
                return;
            }
            console.log('Searching for:', query);
            alert(`Searching for: ${query}\n\nThis would normally show search results.`);
        }

        // Notifications
        function initializeNotifications() {
            const notificationBtn = document.querySelector('.action-icon .fa-bell').closest('.action-icon');
            if (notificationBtn) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const notificationCount = this.querySelector('.notification-count');
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
                });
            }
        }

        // User menu
        function initializeUserMenu() {
            const userMenu = document.querySelector('.user-menu');
            const userMenuBtn = userMenu.querySelector('.action-icon');

            if (userMenuBtn) {
                userMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const dropdown = this.closest('.user-menu').querySelector('.user-menu-dropdown');
                    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                });
            }

            // Close user menu when clicking outside
            document.addEventListener('click', function() {
                const dropdowns = document.querySelectorAll('.user-menu-dropdown');
                dropdowns.forEach(dropdown => {
                    dropdown.style.display = 'none';
                });
            });
        }

        // Keyboard shortcuts
        function initializeKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + K for search
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-box input');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }

                // Escape key closes everything
                if (e.key === 'Escape') {
                    closeAllDropdowns();

                    const sidebar = document.getElementById('sidebar');
                    if (window.innerWidth <= 1024 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                    }

                    const userMenuDropdowns = document.querySelectorAll('.user-menu-dropdown');
                    userMenuDropdowns.forEach(dropdown => {
                        dropdown.style.display = 'none';
                    });
                }
            });
        }

        // Also need to add this CSS for smooth dropdown animations
        const style = document.createElement('style');
        style.textContent = `
        .dropdown-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .nav-dropdown.active .dropdown-content {
            max-height: 500px;
            transition: max-height 0.5s ease-in;
            display: block;
        }
        
        .user-menu-dropdown {
            display: none;
        }
        
        /* Smooth sidebar animation for mobile */
        @media (max-width: 1024px) {
            .sidebar {
                transition: transform 0.3s ease;
            }
        }
        
        /* Ensure dropdown chevrons are visible */
        .dropdown-toggle .fa-chevron-down,
        .dropdown-toggle .fa-chevron-up {
            font-size: 0.75rem;
            transition: transform 0.3s ease;
        }
        
        .nav-dropdown.active .dropdown-toggle .fa-chevron-down {
            transform: rotate(180deg);
        }
    `;
        document.head.appendChild(style);
    </script>
</body>

</html>