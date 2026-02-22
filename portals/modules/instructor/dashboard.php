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

// Get admin announcements
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Instructor Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --dark: #0f172a;
            --light: #f8fafc;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --white: #ffffff;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            --radius: 12px;
            --radius-sm: 8px;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            line-height: 1.5;
        }

        /* App Container */
        .app {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .header {
            background: var(--white);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .menu-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray);
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }

        .menu-btn:hover {
            background: var(--light);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo img {
            height: 32px;
            width: auto;
        }

        .logo span {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark);
            display: none;
        }

        @media (min-width: 480px) {
            .logo span {
                display: inline;
            }
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .icon-btn {
            background: none;
            border: none;
            font-size: 1.1rem;
            color: var(--gray);
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            position: relative;
        }

        .icon-btn:hover {
            background: var(--light);
        }

        .badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
        }

        .user-info:hover {
            background: var(--light);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            display: none;
        }

        @media (min-width: 768px) {
            .user-details {
                display: block;
            }

            .user-details .name {
                font-weight: 600;
                font-size: 0.9rem;
            }

            .user-details .role {
                font-size: 0.75rem;
                color: var(--gray);
            }
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: var(--white);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            transition: left 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.open {
            left: 0;
        }

        .sidebar-header {
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-logo img {
            height: 32px;
        }

        .sidebar-logo span {
            font-weight: 600;
            color: var(--dark);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.1rem;
            color: var(--gray);
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }

        .sidebar-user {
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-user .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .sidebar-user .details h4 {
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-user .details p {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.2s;
            margin: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
        }

        .nav-item i {
            width: 20px;
            font-size: 1rem;
        }

        .nav-item span {
            flex: 1;
            font-size: 0.9rem;
        }

        .nav-item .badge {
            position: static;
            background: var(--primary);
            color: white;
        }

        .nav-item:hover {
            background: var(--light);
            color: var(--primary);
        }

        .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .nav-item.active .badge {
            background: white;
            color: var(--primary);
        }

        .nav-divider {
            height: 1px;
            background: var(--gray-light);
            margin: 0.5rem 1rem;
        }

        /* Overlay */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 90;
            display: none;
        }

        .overlay.show {
            display: block;
        }

        /* Main Content */
        .main {
            flex: 1;
            padding: 1rem;
        }

        /* Welcome Section */
        .welcome {
            margin-bottom: 1.5rem;
        }

        .welcome h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .welcome p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Breaking News */
        .breaking-news {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .breaking-news-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .breaking-news-content {
            flex: 1;
        }

        .breaking-news-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .breaking-news-text {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .breaking-news-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            opacity: 0.8;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .stat-title {
            color: var(--gray);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .stat-card.primary .stat-icon {
            background: #dbeafe;
            color: var(--primary);
        }

        .stat-card.success .stat-icon {
            background: #d1fae5;
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background: #fed7aa;
            color: var(--warning);
        }

        .stat-card.info .stat-icon {
            background: #cffafe;
            color: var(--info);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-desc {
            font-size: 0.7rem;
            color: var(--gray);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .dashboard-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1rem;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
        }

        .card-header {
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--gray-light);
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary);
            font-size: 0.9rem;
        }

        .card-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--light);
            color: var(--gray);
        }

        .btn-secondary:hover {
            background: var(--gray-light);
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .card-body {
            padding: 1rem;
        }

        /* Class List */
        .class-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .class-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--radius-sm);
        }

        .class-info h4 {
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }

        .class-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .class-badge {
            background: var(--white);
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .class-badge.ongoing {
            background: #dbeafe;
            color: var(--primary);
        }

        .class-badge.scheduled {
            background: #f1f5f9;
            color: var(--gray);
        }

        /* Assignment List */
        .assignment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .assignment-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--radius-sm);
        }

        .assignment-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--warning);
        }

        .assignment-info {
            flex: 1;
        }

        .assignment-info h4 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .assignment-info p {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .assignment-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .assignment-count {
            background: var(--primary);
            color: white;
            padding: 0.15rem 0.4rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .assignment-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.75rem;
        }

        /* Schedule List */
        .schedule-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .schedule-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--light);
            border-radius: var(--radius-sm);
        }

        .schedule-time {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.8rem;
            min-width: 65px;
        }

        .schedule-info h4 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .schedule-info p {
            font-size: 0.7rem;
            color: var(--gray);
        }

        /* Quick Actions */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        @media (min-width: 480px) {
            .actions-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .action-item {
            background: var(--light);
            padding: 1rem 0.5rem;
            border-radius: var(--radius-sm);
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.2s;
        }

        .action-item:hover {
            background: var(--primary);
            color: white;
        }

        .action-item i {
            font-size: 1.2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
            display: block;
        }

        .action-item:hover i {
            color: white;
        }

        .action-item span {
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Notifications Panel */
        .notifications-panel {
            position: fixed;
            top: 0;
            right: -100%;
            width: 100%;
            max-width: 400px;
            height: 100vh;
            background: var(--white);
            box-shadow: var(--shadow-lg);
            z-index: 200;
            transition: right 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .notifications-panel.open {
            right: 0;
        }

        .panel-header {
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--gray-light);
        }

        .panel-header h3 {
            font-size: 1rem;
        }

        .panel-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        .notification-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .notification-text {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .notification-time {
            font-size: 0.7rem;
            color: var(--gray);
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0.25rem;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            z-index: 40;
        }

        @media (min-width: 768px) {
            .bottom-nav {
                display: none;
            }
        }

        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem;
            color: var(--gray);
            text-decoration: none;
            font-size: 0.7rem;
            flex: 1;
            position: relative;
        }

        .bottom-nav-item i {
            font-size: 1.1rem;
        }

        .bottom-nav-item.active {
            color: var(--primary);
        }

        .bottom-nav-item .badge {
            position: absolute;
            top: 0;
            right: 20px;
            font-size: 0.6rem;
        }

        /* Footer */
        .footer {
            margin-top: 2rem;
            margin-bottom: 4rem;
            padding: 1rem;
            text-align: center;
            color: var(--gray);
            font-size: 0.75rem;
            border-top: 1px solid var(--gray-light);
        }

        @media (min-width: 768px) {
            .footer {
                margin-bottom: 1rem;
            }
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            margin-right: 0.25rem;
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <div class="app">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="menu-btn" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo">
                    <img src="<?php echo BASE_URL; ?>public/images/logo_official.jpg" alt="Impact">
                    <span>Instructor Panel</span>
                </div>
            </div>
            <div class="header-right">
                <button class="icon-btn" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                        <span class="badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </button>
                <div class="user-info" onclick="toggleUserMenu()">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="name"><?php echo htmlspecialchars($instructor_name); ?></div>
                        <div class="role">Instructor</div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="<?php echo BASE_URL; ?>public/images/logo_official.jpg" alt="Impact">
                    <span>Instructor Panel</span>
                </div>
                <button class="close-btn" onclick="toggleSidebar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sidebar-user">
                <div class="avatar">
                    <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                </div>
                <div class="details">
                    <h4><?php echo htmlspecialchars($instructor_name); ?></h4>
                    <p>Instructor</p>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="#" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-item">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>My Classes</span>
                    <?php if ($stats['active_classes'] > 0): ?>
                        <span class="badge"><?php echo $stats['active_classes']; ?></span>
                    <?php endif; ?>
                </a>
                <!-- <a href="<?php echo BASE_URL; ?>modules/instructor/courses/schedule_builder.php" class="nav-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Schedule Builder</span>
                    <?php if ($stats['active_classes'] > 0): ?>
                        <span class="badge"><?php echo $stats['active_classes']; ?></span>
                    <?php endif; ?>
                </a> -->
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="nav-item">
                    <i class="fas fa-tasks"></i>
                    <span>Assignments</span>
                    <?php if ($stats['pending_grading'] > 0): ?>
                        <span class="badge"><?php echo $stats['pending_grading']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="nav-item">
                    <i class="fas fa-user-graduate"></i>
                    <span>Students</span>
                    <?php if ($stats['total_students'] > 0): ?>
                        <span class="badge"><?php echo $stats['total_students']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/materials/index.php" class="nav-item">
                    <i class="fas fa-folder-open"></i>
                    <span>Materials</span>
                    <?php if ($stats['total_materials'] > 0): ?>
                        <span class="badge"><?php echo $stats['total_materials']; ?></span>
                    <?php endif; ?>
                </a>
                <div class="nav-divider"></div>
                <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" class="nav-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Schedule</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="nav-item">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/mail/index.php" class="nav-item">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                    <?php if ($unread_message_count > 0): ?>
                        <span class="badge"><?php echo $unread_message_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/shared/help/" class="nav-item">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                </a>
                <div class="nav-divider"></div>
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item" onclick="return confirm('Are you sure you want to logout?')">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Overlay -->
        <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

        <!-- Main Content -->
        <main class="main">
            <!-- Welcome Section -->
            <div class="welcome">
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $instructor_name)[0]); ?>! 👋</h1>
                <p>Here's what's happening with your classes today.</p>
            </div>

            <!-- Breaking News -->
            <?php if (!empty($admin_announcements)): ?>
                <div class="breaking-news">
                    <div class="breaking-news-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="breaking-news-content">
                        <?php $announcement = $admin_announcements[0]; ?>
                        <div class="breaking-news-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                        <div class="breaking-news-text"><?php echo truncate_text(strip_tags($announcement['content']), 100); ?></div>
                        <div class="breaking-news-meta">
                            <span><i class="fas fa-user"></i> Admin</span>
                            <span><i class="fas fa-clock"></i> <?php echo time_ago($announcement['created_at']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <span class="stat-title">Active Classes</span>
                        <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_classes']; ?></div>
                    <div class="stat-desc"><?php echo $stats['total_classes']; ?> total classes</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-header">
                        <span class="stat-title">Students</span>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-desc">Active enrollments</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-header">
                        <span class="stat-title">To Grade</span>
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_grading']; ?></div>
                    <div class="stat-desc">Pending submissions</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-header">
                        <span class="stat-title">Materials</span>
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_materials']; ?></div>
                    <div class="stat-desc">Uploaded resources</div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Column -->
                <div class="left-col">
                    <!-- My Classes Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-chalkboard-teacher"></i>
                                My Classes
                            </h3>
                            <div class="card-actions">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="btn btn-secondary btn-sm">View All</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="class-list">
                                <?php if (empty($current_classes)): ?>
                                    <div class="no-data">
                                        <i class="fas fa-chalkboard"></i>
                                        <p>No classes assigned yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($current_classes, 0, 3) as $class): ?>
                                        <div class="class-item">
                                            <div class="class-info">
                                                <h4><?php echo htmlspecialchars($class['batch_code']); ?></h4>
                                                <p><?php echo truncate_text($class['course_title'], 30); ?></p>
                                            </div>
                                            <div class="class-meta">
                                                <span class="class-badge <?php echo strtolower($class['status']); ?>">
                                                    <?php echo ucfirst($class['status']); ?>
                                                </span>
                                                <div style="font-size: 0.7rem; color: var(--gray); text-align: right;">
                                                    <?php echo $class['student_count']; ?> students
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Assignments Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-clock"></i>
                                Need Grading
                            </h3>
                            <div class="card-actions">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="btn btn-primary btn-sm">Grade All</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="assignment-list">
                                <?php if (empty($pending_assignments)): ?>
                                    <div class="no-data">
                                        <i class="fas fa-check-circle"></i>
                                        <p>All caught up!</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($pending_assignments, 0, 3) as $assignment): ?>
                                        <div class="assignment-item">
                                            <div class="assignment-icon">
                                                <i class="fas fa-file-alt"></i>
                                            </div>
                                            <div class="assignment-info">
                                                <h4><?php echo truncate_text($assignment['title'], 25); ?></h4>
                                                <p><?php echo $assignment['course_title']; ?></p>
                                            </div>
                                            <div class="assignment-meta">
                                                <span class="assignment-count"><?php echo $assignment['submission_count']; ?></span>
                                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?id=<?php echo $assignment['id']; ?>" class="assignment-link">Grade</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-col">
                    <!-- Today's Schedule Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-calendar-day"></i>
                                Today's Schedule
                            </h3>
                            <div class="card-actions">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" class="btn btn-secondary btn-sm">View All</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="schedule-list">
                                <?php if (empty($today_schedule)): ?>
                                    <div class="no-data">
                                        <i class="fas fa-calendar"></i>
                                        <p>No classes today</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($today_schedule as $class): ?>
                                        <div class="schedule-item">
                                            <div class="schedule-time"><?php echo $class['class_time']; ?></div>
                                            <div class="schedule-info">
                                                <h4><?php echo htmlspecialchars($class['batch_code']); ?></h4>
                                                <p><?php echo truncate_text($class['course_title'], 25); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-bolt"></i>
                                Quick Actions
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="actions-grid">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/create.php" class="action-item">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Assignment</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/materials/upload.php" class="action-item">
                                    <i class="fas fa-upload"></i>
                                    <span>Material</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/shared/mail/compose.php" class="action-item">
                                    <i class="fas fa-envelope"></i>
                                    <span>Message</span>
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/create.php" class="action-item">
                                    <i class="fas fa-bullhorn"></i>
                                    <span>Announce</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Submissions Card -->
                    <?php if (!empty($recent_submissions)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-history"></i>
                                    Recent Submissions
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="assignment-list">
                                    <?php foreach (array_slice($recent_submissions, 0, 3) as $submission): ?>
                                        <div class="assignment-item">
                                            <div class="assignment-icon" style="color: var(--success);">
                                                <i class="fas fa-check-circle"></i>
                                            </div>
                                            <div class="assignment-info">
                                                <h4><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h4>
                                                <p><?php echo truncate_text($submission['assignment_title'], 20); ?></p>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--gray);">
                                                <?php echo time_ago($submission['submitted_at']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <div style="margin-bottom: 0.5rem;">
                    <span class="status-indicator"></span>
                    System Online
                </div>
                <div>&copy; <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.</div>
            </div>
        </main>

        <!-- Bottom Navigation -->
        <nav class="bottom-nav">
            <a href="#" class="bottom-nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Home</span>
            </a>
            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="bottom-nav-item">
                <i class="fas fa-chalkboard"></i>
                <span>Classes</span>
                <?php if ($stats['active_classes'] > 0): ?>
                    <span class="badge"><?php echo $stats['active_classes']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="bottom-nav-item">
                <i class="fas fa-tasks"></i>
                <span>Grade</span>
                <?php if ($stats['pending_grading'] > 0): ?>
                    <span class="badge"><?php echo $stats['pending_grading']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>modules/shared/mail/index.php" class="bottom-nav-item">
                <i class="fas fa-envelope"></i>
                <span>Mail</span>
                <?php if ($unread_message_count > 0): ?>
                    <span class="badge"><?php echo $unread_message_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="bottom-nav-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
        </nav>

        <!-- Notifications Panel -->
        <div class="notifications-panel" id="notificationsPanel">
            <div class="panel-header">
                <h3>Notifications</h3>
                <button class="close-btn" onclick="toggleNotifications()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="panel-content">
                <?php if (empty($notifications)): ?>
                    <div class="no-data">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item">
                            <div class="notification-icon">
                                <i class="fas fa-<?php echo $notification['type'] ?? 'info-circle'; ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                <div class="notification-text"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-time"><?php echo time_ago($notification['created_at']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="padding: 1rem; border-top: 1px solid var(--gray-light);">
                <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/" class="btn btn-primary" style="width: 100%; text-align: center; justify-content: center;">
                    View All Notifications
                </a>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        }

        // Toggle notifications panel
        let notificationsPanel = document.getElementById('notificationsPanel');

        function toggleNotifications() {
            notificationsPanel.classList.toggle('open');
        }

        // Close notifications when clicking outside
        document.addEventListener('click', function(event) {
            if (notificationsPanel.classList.contains('open')) {
                if (!notificationsPanel.contains(event.target) && !event.target.closest('.icon-btn')) {
                    notificationsPanel.classList.remove('open');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('overlay').classList.remove('show');
                notificationsPanel.classList.remove('open');
            }
        });

        // Mark notification as read
        function markAsRead(notificationId) {
            // AJAX call to mark notification as read
            fetch('<?php echo BASE_URL; ?>modules/instructor/mark-notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: notificationId
                })
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        // Show success message
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                background: ${type === 'success' ? 'var(--success)' : 'var(--danger)'};
                color: white;
                padding: 0.75rem 1.5rem;
                border-radius: 50px;
                font-size: 0.9rem;
                z-index: 1000;
                box-shadow: var(--shadow-lg);
                animation: slideUp 0.3s ease;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideDown 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Add animation styles
        const style = document.createElement('style');
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
    </script>
</body>

</html>