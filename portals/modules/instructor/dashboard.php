<?php
// modules/instructor/dashboard.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];

// Initialize stats array
$stats = [
    'total_classes' => 0,
    'active_classes' => 0,
    'total_students' => 0,
    'pending_grading' => 0,
    'total_materials' => 0,
    'upcoming_classes' => 0
];

// Get instructor statistics with error handling
try {
    // Total classes assigned to instructor
    $sql = "SELECT COUNT(*) as total_classes FROM class_batches WHERE instructor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['total_classes'] = $row['total_classes'] ?? 0;
    }
    $stmt->close();

    // Active (ongoing) classes
    $sql = "SELECT COUNT(*) as active_classes FROM class_batches 
            WHERE instructor_id = ? AND status = 'ongoing'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['active_classes'] = $row['active_classes'] ?? 0;
    }
    $stmt->close();

    // Total students across all classes
    $sql = "SELECT COUNT(DISTINCT e.student_id) as total_students 
            FROM enrollments e 
            JOIN class_batches cb ON e.class_id = cb.id 
            WHERE cb.instructor_id = ? AND e.status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['total_students'] = $row['total_students'] ?? 0;
    }
    $stmt->close();

    // Pending assignments to grade
    $sql = "SELECT COUNT(DISTINCT a.id) as pending_grading 
            FROM assignments a 
            JOIN assignment_submissions s ON a.id = s.assignment_id 
            WHERE a.instructor_id = ? AND s.status = 'submitted' AND s.grade IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['pending_grading'] = $row['pending_grading'] ?? 0;
    }
    $stmt->close();

    // Total materials uploaded
    $sql = "SELECT COUNT(*) as total_materials FROM materials WHERE instructor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['total_materials'] = $row['total_materials'] ?? 0;
    }
    $stmt->close();

    // Upcoming classes (starting in next 7 days)
    $sql = "SELECT COUNT(*) as upcoming_classes FROM class_batches 
            WHERE instructor_id = ? AND status = 'scheduled' 
            AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stats['upcoming_classes'] = $row['upcoming_classes'] ?? 0;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Instructor dashboard statistics error: " . $e->getMessage());
}

// Get instructor's current classes
$current_classes = [];
$sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = cb.id AND e.status = 'active') as student_count
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        WHERE cb.instructor_id = ? 
        AND cb.status IN ('ongoing', 'scheduled')
        ORDER BY cb.start_date ASC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $current_classes = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get assignments pending grading
$pending_assignments = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title, 
               COUNT(s.id) as submission_count,
               MIN(s.submitted_at) as earliest_submission
        FROM assignments a 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.status = 'submitted'
        WHERE a.instructor_id = ? AND a.due_date <= NOW()
        GROUP BY a.id 
        HAVING COUNT(s.id) > 0
        ORDER BY a.due_date ASC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $pending_assignments = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get upcoming assignments (due in next 7 days)
$upcoming_assignments = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title,
               COUNT(s.id) as submission_count
        FROM assignments a 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        LEFT JOIN assignment_submissions s ON a.id = s.assignment_id
        WHERE a.instructor_id = ? 
        AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        GROUP BY a.id 
        ORDER BY a.due_date ASC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $upcoming_assignments = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get recent announcements by instructor
$recent_announcements = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title
        FROM announcements a 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        WHERE a.author_id = ? 
        ORDER BY a.created_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $recent_announcements = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get recent student submissions (last 24 hours)
$recent_submissions = [];
$sql = "SELECT s.*, a.title as assignment_title, u.first_name, u.last_name, 
               cb.batch_code, c.title as course_title
        FROM assignment_submissions s 
        JOIN assignments a ON s.assignment_id = a.id 
        JOIN users u ON s.student_id = u.id 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        WHERE a.instructor_id = ? 
        AND s.submitted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY s.submitted_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $recent_submissions = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get system notifications
$notifications = [];
$sql = "SELECT * FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get instructor's schedule for today
$today_schedule = [];
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               TIME_FORMAT(cb.schedule, '%h:%i %p') as class_time
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.instructor_id = ? 
        AND cb.status = 'ongoing'
        AND DAYOFWEEK(CURDATE()) = DAYOFWEEK(cb.schedule)
        ORDER BY cb.schedule";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $today_schedule = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();


$unread_message_count = getUnreadMessageCount($instructor_id, $conn);

// Log dashboard access
logActivity('instructor_dashboard_access', 'Instructor accessed dashboard');


// Get instructor name
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// In the PHP section of dashboard.php, add this after getting other data:

// Get admin announcements (breaking news) - FIXED VERSION
$admin_announcements = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title,
               CONCAT(u.first_name, ' ', u.last_name) as author_name,
               (SELECT 1 FROM announcement_acknowledgments aa 
                WHERE aa.announcement_id = a.id AND aa.user_id = ?) as is_acknowledged
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        LEFT JOIN courses c ON cb.course_id = c.id 
        JOIN users u ON u.id = a.author_id 
        WHERE u.role = 'admin' 
        AND a.is_published = 1
        AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())
        AND (a.requires_acknowledgment = 0 OR 
             NOT EXISTS(
                 SELECT 1 FROM announcement_acknowledgments aa 
                 WHERE aa.announcement_id = a.id AND aa.user_id = ?
             ))
        ORDER BY a.created_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $instructor_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $admin_announcements = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --sidebar-width: 280px;
            --sidebar-collapsed: 70px;
            --header-height: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile First Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Mobile Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(3px);
        }

        /* Sidebar - Mobile Optimized */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e3a8a, #1e40af);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
            left: 0;
            top: 0;
            transform: translateX(-100%);
        }

        @media (min-width: 768px) {
            .sidebar {
                transform: translateX(0);
                position: relative;
            }

            .sidebar.collapsed {
                width: var(--sidebar-collapsed);
            }
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: var(--header-height);
            position: sticky;
            top: 0;
            background: inherit;
            z-index: 10;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo img {
            height: 40px;
            width: auto;
            border-radius: 6px;
            padding: 4px;
            background: white;
        }

        @media (min-width: 768px) {
            .logo img {
                height: 50px;
            }
        }

        .logo-text {
            font-size: 1.1rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .toggle-sidebar {
            background: transparent;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.5rem;
            border-radius: 4px;
            transition: background 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-sidebar:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* User Info - Mobile Optimized */
        .user-info {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar.collapsed .user-info {
            justify-content: center;
            padding: 1rem 0.5rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e40af;
            font-weight: bold;
            font-size: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .user-avatar {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
        }

        .user-details h3 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
        }

        .user-details p {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .sidebar.collapsed .user-details {
            display: none;
        }

        /* Mobile Navigation */
        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        @media (min-width: 768px) {
            .nav-item {
                padding: 0.8rem 1.5rem;
            }
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: white;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            flex-shrink: 0;
            font-size: 1rem;
        }

        .nav-label {
            flex-grow: 1;
            white-space: nowrap;
            font-size: 0.9rem;
        }

        .sidebar.collapsed .nav-label {
            display: none;
        }

        .badge {
            background: var(--accent);
            color: var(--dark);
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .sidebar.collapsed .badge {
            position: absolute;
            top: 5px;
            right: 5px;
            transform: scale(0.8);
        }

        /* Dropdown for Mobile */
        .nav-dropdown {
            position: relative;
        }

        .nav-dropdown .dropdown-toggle {
            cursor: pointer;
            position: relative;
        }

        .nav-dropdown .dropdown-toggle .dropdown-arrow {
            font-size: 0.8rem;
            transition: transform 0.3s ease;
            margin-left: auto;
        }

        .nav-dropdown .dropdown-toggle.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .sidebar.collapsed .dropdown-arrow {
            display: none;
        }

        .nav-dropdown .dropdown-content {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin: 0.5rem;
            display: none;
            overflow: hidden;
        }

        .nav-dropdown .dropdown-content.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .nav-dropdown .dropdown-content .nav-item {
            padding-left: 2.5rem;
            border-left: none;
            font-size: 0.85rem;
        }

        /* Main Content - Mobile First */
        .main-content {
            flex: 1;
            padding: 1rem;
            min-height: 100vh;
            transition: all 0.3s ease;
            width: 100%;
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
                padding: 1.5rem;
            }

            .sidebar.collapsed ~ .main-content {
                margin-left: var(--sidebar-collapsed);
            }
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            margin: -1rem -1rem 1rem -1rem;
        }

        @media (min-width: 768px) {
            .mobile-header {
                display: none;
            }
        }

        .mobile-menu-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            padding: 0.5rem;
        }

        .mobile-title h1 {
            font-size: 1.2rem;
            color: var(--dark);
        }

        .mobile-title p {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Top Bar for Desktop */
        .top-bar {
            display: none;
        }

        @media (min-width: 768px) {
            .top-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2rem;
                padding-bottom: 1rem;
                border-bottom: 2px solid var(--light-gray);
            }
        }

        .page-title h1 {
            font-size: 1.8rem;
            color: var(--dark);
        }

        .page-title p {
            color: var(--gray);
            margin-top: 0.5rem;
            font-size: 0.95rem;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 0.8rem 1rem 0.8rem 2.5rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            width: 100%;
            max-width: 300px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.3rem;
            color: var(--gray);
            transition: color 0.3s ease;
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        /* Stats Grid - Mobile Responsive */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1.5rem;
            }
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .stat-title {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }

        @media (min-width: 768px) {
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
        }

        /* Content Grid - Mobile Stack */
        .content-grid {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1.5rem;
            }
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        /* Tables - Mobile Scrollable */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1.2rem;
            padding: 0 1.2rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            text-align: left;
            padding: 0.8rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 0.8rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-ongoing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-scheduled {
            background: #e0e7ff;
            color: #3730a3;
        }

        /* Activity Items */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            padding: 0.8rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.2rem;
            font-size: 0.9rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .activity-description {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.2rem;
        }

        /* Quick Actions - Mobile Grid */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.8rem;
            margin-top: 1rem;
        }

        @media (min-width: 640px) {
            .quick-actions-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: white;
            border-radius: 10px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid var(--light-gray);
            min-height: 100px;
        }

        .quick-action:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
        }

        .quick-action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .quick-action-label {
            font-weight: 600;
            font-size: 0.8rem;
            text-align: center;
        }

        /* Breaking News - Mobile Optimized */
        .breaking-news-alert {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
            position: relative;
            overflow: hidden;
        }

        .breaking-news-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .breaking-news-label {
            background: white;
            color: #ee5a52;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-weight: bold;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .breaking-news-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.3rem;
        }

        .breaking-news-content {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .breaking-news-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.7rem;
            opacity: 0.8;
        }

        @media (min-width: 640px) {
            .breaking-news-meta {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        /* Notifications Panel - Mobile Full Screen */
        .notifications-panel {
            position: fixed;
            top: 0;
            right: -100%;
            width: 100%;
            max-width: 400px;
            height: 100vh;
            background: white;
            box-shadow: -5px 0 20px rgba(0, 0, 0, 0.1);
            z-index: 2000;
            transition: right 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 640px) {
            .notifications-panel {
                top: 80px;
                right: -400px;
                height: auto;
                max-height: 500px;
                border-radius: 12px;
            }
        }

        .notifications-panel.show {
            right: 0;
        }

        .notifications-header {
            padding: 1rem;
            background: var(--light);
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notifications-content {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        /* Footer */
        .dashboard-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--light-gray);
            color: var(--gray);
            font-size: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }

        /* Mobile Bottom Navigation */
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem;
            z-index: 1000;
        }

        @media (min-width: 768px) {
            .mobile-bottom-nav {
                display: none;
            }
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem;
            color: var(--gray);
            text-decoration: none;
            font-size: 0.7rem;
            flex: 1;
            text-align: center;
            -webkit-tap-highlight-color: transparent;
        }

        .mobile-nav-item.active {
            color: var(--primary);
        }

        .mobile-nav-item i {
            font-size: 1.2rem;
        }

        /* Touch-friendly elements */
        button, 
        a,
        .nav-item,
        .mobile-nav-item {
            touch-action: manipulation;
        }

        /* Loading state */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* No data states */
        .no-data {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <button class="mobile-menu-btn" onclick="toggleMobileSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="mobile-title">
            <h1>Dashboard</h1>
            <p><?php echo htmlspecialchars($instructor_name); ?></p>
        </div>
        <div class="notification-bell" onclick="toggleNotifications()">
            <i class="fas fa-bell"></i>
            <?php if (count($notifications) > 0): ?>
                <span class="notification-count"><?php echo count($notifications); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" onclick="toggleMobileSidebar()"></div>

    <!-- App Container -->
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="logo">
                        <img src="<?php echo BASE_URL; ?>public/images/logo_official.jpg" alt="Impact Digital Academy">
                    </div>
                    <div class="logo-text">Instructor Panel</div>
                </div>
                <button class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($instructor_name); ?></h3>
                    <p><i class="fas fa-chalkboard-teacher"></i> Instructor</p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-label">Dashboard</span>
                </a>

                <!-- Teaching Center -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span class="nav-label">Teaching Center</span>
                        <?php if ($stats['active_classes'] > 0): ?>
                            <span class="badge"><?php echo $stats['active_classes']; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-item">
                            <i class="fas fa-chalkboard"></i>
                            <span class="nav-label">My Classes</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" class="nav-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="nav-label">Schedule</span>
                        </a>
                    </div>
                </div>

                <!-- Assessments -->
                <div class="nav-dropdown">
                    <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                        <i class="fas fa-tasks"></i>
                        <span class="nav-label">Assessments</span>
                        <?php if ($stats['pending_grading'] > 0): ?>
                            <span class="badge"><?php echo $stats['pending_grading']; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </div>
                    <div class="dropdown-content">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="nav-item">
                            <i class="fas fa-list"></i>
                            <span class="nav-label">All Assignments</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="nav-item">
                            <i class="fas fa-clock"></i>
                            <span class="nav-label">Pending Grading</span>
                        </a>
                    </div>
                </div>

                <!-- Students -->
                <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="nav-item">
                    <i class="fas fa-user-graduate"></i>
                    <span class="nav-label">Students</span>
                    <?php if ($stats['total_students'] > 0): ?>
                        <span class="badge"><?php echo $stats['total_students']; ?></span>
                    <?php endif; ?>
                </a>

                <!-- Materials -->
                <a href="<?php echo BASE_URL; ?>modules/instructor/materials/index.php" class="nav-item">
                    <i class="fas fa-folder-open"></i>
                    <span class="nav-label">Materials</span>
                    <?php if ($stats['total_materials'] > 0): ?>
                        <span class="badge"><?php echo $stats['total_materials']; ?></span>
                    <?php endif; ?>
                </a>

                <!-- Profile -->
                <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="nav-item">
                    <i class="fas fa-user"></i>
                    <span class="nav-label">Profile</span>
                </a>

                <!-- Settings -->
                <a href="<?php echo BASE_URL; ?>modules/instructor/settings.php" class="nav-item">
                    <i class="fas fa-cog"></i>
                    <span class="nav-label">Settings</span>
                </a>

                <!-- Help -->
                <a href="<?php echo BASE_URL; ?>modules/shared/help/" class="nav-item">
                    <i class="fas fa-question-circle"></i>
                    <span class="nav-label">Help</span>
                </a>

                <!-- Logout -->
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item"
                    onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-label">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Desktop Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1>Instructor Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($instructor_name); ?>! Here's your teaching overview.</p>
                </div>

                <div class="top-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search...">
                    </div>

                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-count"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="user-avatar" style="width: 40px; height: 40px; cursor: pointer;" 
                         onclick="toggleUserMenu()">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                </div>
            </div>

            <!-- Breaking News -->
            <?php if (!empty($admin_announcements)): ?>
                <div class="breaking-news-alert">
                    <div class="breaking-news-header">
                        <span class="breaking-news-label">
                            <i class="fas fa-bullhorn"></i> News
                        </span>
                        <span><?php echo count($admin_announcements); ?> new</span>
                    </div>

                    <?php foreach (array_slice($admin_announcements, 0, 1) as $announcement): ?>
                        <div class="breaking-news-title">
                            <?php echo htmlspecialchars($announcement['title']); ?>
                        </div>
                        <div class="breaking-news-content">
                            <?php echo truncate_text(strip_tags($announcement['content']), 100); ?>
                        </div>
                        <div class="breaking-news-meta">
                            <div class="breaking-news-author">
                                Admin: <?php echo htmlspecialchars($announcement['author_name']); ?>
                            </div>
                            <div class="breaking-news-time">
                                <?php echo time_ago($announcement['created_at']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Active Classes</div>
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_classes']; ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray);">
                        <?php echo $stats['total_classes']; ?> total
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Students</div>
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray);">
                        Across all classes
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Pending</div>
                        <div class="stat-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_grading']; ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray);">
                        Need grading
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Materials</div>
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_materials']; ?></div>
                    <div style="font-size: 0.75rem; color: var(--gray);">
                        Uploaded
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- My Classes -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">My Classes</h2>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="btn btn-primary">
                                View All
                            </a>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Course</th>
                                        <th>Students</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($current_classes)): ?>
                                        <tr>
                                            <td colspan="4" class="no-data">
                                                <i class="fas fa-chalkboard"></i>
                                                <div>No classes assigned</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach (array_slice($current_classes, 0, 3) as $class): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($class['batch_code']); ?></div>
                                                    <div style="font-size: 0.8rem; color: var(--gray);">
                                                        <?php echo truncate_text($class['name'], 15); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.8rem;">
                                                        <?php echo truncate_text($class['course_title'], 20); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-ongoing">
                                                        <?php echo $class['student_count']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($class['status']); ?>">
                                                        <?php echo ucfirst($class['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pending Assignments -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Pending Grading</h2>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="btn btn-primary">
                                Grade All
                            </a>
                        </div>

                        <div class="activity-list">
                            <?php if (empty($pending_assignments)): ?>
                                <div class="no-data">
                                    <i class="fas fa-check"></i>
                                    <div>All assignments graded</div>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($pending_assignments, 0, 3) as $assignment): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                                            <i class="fas fa-tasks"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo truncate_text($assignment['title'], 30); ?>
                                            </div>
                                            <div class="activity-description">
                                                <?php echo truncate_text($assignment['course_title'], 25); ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--gray);">
                                                <?php echo $assignment['submission_count']; ?> submissions
                                            </div>
                                        </div>
                                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?id=<?php echo $assignment['id']; ?>"
                                           class="btn btn-secondary btn-sm">
                                            Grade
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Today's Schedule -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Today's Schedule</h2>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" class="btn btn-secondary btn-sm">
                                Full
                            </a>
                        </div>

                        <div class="activity-list">
                            <?php if (empty($today_schedule)): ?>
                                <div class="no-data">
                                    <i class="fas fa-calendar"></i>
                                    <div>No classes today</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($today_schedule as $class): ?>
                                    <div class="activity-item">
                                        <div style="font-weight: 600; color: var(--primary); min-width: 70px;">
                                            <?php echo $class['class_time']; ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo htmlspecialchars($class['batch_code']); ?>
                                            </div>
                                            <div class="activity-description">
                                                <?php echo truncate_text($class['course_title'], 25); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Quick Actions</h2>
                        </div>

                        <div class="quick-actions-grid">
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/create.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <div class="quick-action-label">New Assignment</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/instructor/materials/upload.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-upload"></i>
                                </div>
                                <div class="quick-action-label">Upload Material</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/shared/mail/compose.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="quick-action-label">Send Message</div>
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/create.php" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-bullhorn"></i>
                                </div>
                                <div class="quick-action-label">Post Announcement</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="dashboard-footer">
                <div class="system-status">
                    <div class="status-indicator"></div>
                    <span>Teaching Status: Active</span>
                </div>
                <div>
                    <span>Updated: <?php echo date('M j, g:i a'); ?></span>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="mobile-nav-item active">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="mobile-nav-item">
            <i class="fas fa-chalkboard"></i>
            <span>Classes</span>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="mobile-nav-item">
            <i class="fas fa-tasks"></i>
            <span>Grade</span>
            <?php if ($stats['pending_grading'] > 0): ?>
                <span style="position: absolute; top: 5px; right: 20px; background: var(--danger); color: white; font-size: 0.6rem; padding: 0.1rem 0.3rem; border-radius: 50%;">
                    <?php echo $stats['pending_grading']; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/shared/mail/index.php" class="mobile-nav-item">
            <i class="fas fa-envelope"></i>
            <span>Mail</span>
            <?php if ($unread_message_count > 0): ?>
                <span style="position: absolute; top: 5px; right: 20px; background: var(--danger); color: white; font-size: 0.6rem; padding: 0.1rem 0.3rem; border-radius: 50%;">
                    <?php echo $unread_message_count; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="mobile-nav-item">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

    <script>
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.toggle('show');
            overlay.style.display = sidebar.classList.contains('show') ? 'block' : 'none';
            
            // Prevent body scroll when sidebar is open
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        }

        // Desktop sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            } else {
                toggleMobileSidebar();
            }
        }

        // Toggle dropdowns
        function toggleDropdown(element) {
            // Close other dropdowns on mobile
            if (window.innerWidth < 768) {
                const allDropdowns = document.querySelectorAll('.nav-dropdown .dropdown-content');
                const allToggles = document.querySelectorAll('.nav-dropdown .dropdown-toggle');
                
                allDropdowns.forEach(dropdown => {
                    if (dropdown !== element.nextElementSibling) {
                        dropdown.classList.remove('show');
                    }
                });
                
                allToggles.forEach(toggle => {
                    if (toggle !== element) {
                        toggle.classList.remove('active');
                    }
                });
            }
            
            // Toggle current dropdown
            const dropdownContent = element.nextElementSibling;
            element.classList.toggle('active');
            dropdownContent.classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.nav-dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    dropdown.querySelector('.dropdown-content')?.classList.remove('show');
                    dropdown.querySelector('.dropdown-toggle')?.classList.remove('active');
                }
            });
        });

        // Load sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth >= 768) {
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    document.getElementById('sidebar').classList.add('collapsed');
                }
            }
            
            // Add touch support for mobile
            addTouchSupport();
        });

        // Notifications panel
        let notificationsPanel = null;

        function toggleNotifications() {
            if (!notificationsPanel) {
                createNotificationsPanel();
            }
            
            notificationsPanel.classList.toggle('show');
            
            // Close on mobile when clicking outside
            if (notificationsPanel.classList.contains('show')) {
                setTimeout(() => {
                    document.addEventListener('click', closeNotificationsOnClickOutside);
                }, 10);
            } else {
                document.removeEventListener('click', closeNotificationsOnClickOutside);
            }
        }

        function closeNotificationsOnClickOutside(event) {
            if (!event.target.closest('.notifications-panel') && 
                !event.target.closest('.notification-bell')) {
                notificationsPanel.classList.remove('show');
                document.removeEventListener('click', closeNotificationsOnClickOutside);
            }
        }

        function createNotificationsPanel() {
            notificationsPanel = document.createElement('div');
            notificationsPanel.className = 'notifications-panel';
            notificationsPanel.innerHTML = `
                <div class="notifications-header">
                    <h3 style="margin: 0; font-size: 1.1rem;">Notifications</h3>
                    <button onclick="toggleNotifications()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="notifications-content">
                    <?php if (empty($notifications)): ?>
                        <div class="no-data">
                            <i class="fas fa-bell-slash"></i>
                            <div>No notifications</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="activity-item">
                                <div class="activity-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--primary);">
                                    <i class="fas fa-${notification.type === 'assignment' ? 'tasks' : 'bullhorn'}"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">${notification.title}</div>
                                    <div class="activity-description">${notification.message}</div>
                                    <div style="font-size: 0.7rem; color: var(--gray);">
                                        ${formatTimeAgo(notification.created_at)}
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="padding: 1rem; border-top: 1px solid var(--light-gray); text-align: center;">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/" 
                       class="btn btn-primary btn-sm" style="width: 100%;">
                        View All Notifications
                    </a>
                </div>
            `;
            
            document.body.appendChild(notificationsPanel);
        }

        // Format time ago for notifications
        function formatTimeAgo(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMin = Math.floor(diffMs / 60000);
            const diffHour = Math.floor(diffMin / 60);
            const diffDay = Math.floor(diffHour / 24);
            
            if (diffMin < 1) return 'just now';
            if (diffMin < 60) return diffMin + ' min ago';
            if (diffHour < 24) return diffHour + ' hour' + (diffHour > 1 ? 's' : '') + ' ago';
            if (diffDay < 7) return diffDay + ' day' + (diffDay > 1 ? 's' : '') + ' ago';
            
            return date.toLocaleDateString();
        }

        // Add touch support for mobile
        function addTouchSupport() {
            // Add swipe support for sidebar on mobile
            let touchStartX = 0;
            let touchEndX = 0;
            
            document.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            
            document.addEventListener('touchend', e => {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
            }, { passive: true });
            
            function handleSwipe() {
                const swipeThreshold = 50;
                const swipeDistance = touchEndX - touchStartX;
                
                // Swipe right to open sidebar
                if (swipeDistance > swipeThreshold && window.innerWidth < 768) {
                    toggleMobileSidebar();
                }
                // Swipe left to close sidebar
                else if (swipeDistance < -swipeThreshold && window.innerWidth < 768) {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar.classList.contains('show')) {
                        toggleMobileSidebar();
                    }
                }
            }
            
            // Prevent zoom on double tap
            let lastTap = 0;
            document.addEventListener('touchend', e => {
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                if (tapLength < 300 && tapLength > 0) {
                    e.preventDefault();
                }
                lastTap = currentTime;
            });
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            // Auto-close sidebar on mobile when switching to desktop
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('show');
                overlay.style.display = 'none';
                document.body.style.overflow = '';
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Esc to close modals
            if (e.key === 'Escape') {
                if (notificationsPanel && notificationsPanel.classList.contains('show')) {
                    toggleNotifications();
                }
                
                const sidebar = document.getElementById('sidebar');
                if (sidebar.classList.contains('show')) {
                    toggleMobileSidebar();
                }
            }
            
            // / to focus search
            if (e.key === '/' && window.innerWidth >= 768) {
                e.preventDefault();
                document.querySelector('.search-box input')?.focus();
            }
        });

        // Acknowledge announcement
        function acknowledgeAnnouncement(announcementId) {
            if (!confirm('Acknowledge this announcement?')) return;
            
            // Implement acknowledgment logic here
            console.log('Acknowledging announcement:', announcementId);
            
            // Show success message
            showNotification('Announcement acknowledged!', 'success');
        }

        // Show notification toast
        function showNotification(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 0.8rem 1.2rem;
                border-radius: 8px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideUp 0.3s ease;
                max-width: 90%;
                text-align: center;
            `;
            
            toast.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideDown 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
            
            // Add animation styles
            if (!document.querySelector('#toast-styles')) {
                const style = document.createElement('style');
                style.id = 'toast-styles';
                style.textContent = `
                    @keyframes slideUp {
                        from { transform: translate(-50%, 100%); opacity: 0; }
                        to { transform: translate(-50%, 0); opacity: 1; }
                    }
                    @keyframes slideDown {
                        from { transform: translate(-50%, 0); opacity: 1; }
                        to { transform: translate(-50%, 100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        // Handle mobile back button
        window.addEventListener('popstate', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('show')) {
                toggleMobileSidebar();
            }
        });
    </script>
</body>
</html>