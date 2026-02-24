<?php
// modules/student/classes/announcements.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify student is enrolled in this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e 
        JOIN class_batches cb ON e.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        JOIN users u ON cb.instructor_id = u.id 
        WHERE e.class_id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Get message from URL if present
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$message_type = isset($_GET['type']) ? $_GET['type'] : 'success';

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'desc';

// Build query for announcements list
$where_conditions = ["a.class_id = ?", "a.is_published = 1"];
$params = [$class_id];
$param_types = "i";

if ($filter === 'unread') {
    $where_conditions[] = "ar.student_id IS NULL";
} elseif ($filter === 'read') {
    $where_conditions[] = "ar.student_id IS NOT NULL";
} elseif ($filter === 'active') {
    $where_conditions[] = "(a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
} elseif ($filter === 'expired') {
    $where_conditions[] = "a.expiry_date < CURDATE()";
}

if (!empty($search)) {
    $where_conditions[] = "(a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $param_types .= "ssss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$sql_count = "SELECT COUNT(DISTINCT a.id) as total 
              FROM announcements a
              LEFT JOIN users u ON a.author_id = u.id
              LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.student_id = ?
              WHERE a.class_id = ? AND a.is_published = 1";

// Add filter conditions for count query
$count_params = [$student_id, $class_id];
$count_param_types = "ii";

if ($filter === 'unread') {
    $sql_count .= " AND ar.student_id IS NULL";
} elseif ($filter === 'read') {
    $sql_count .= " AND ar.student_id IS NOT NULL";
} elseif ($filter === 'active') {
    $sql_count .= " AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
} elseif ($filter === 'expired') {
    $sql_count .= " AND a.expiry_date < CURDATE()";
}

if (!empty($search)) {
    $sql_count .= " AND (a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $count_params[] = "%{$search}%";
    $count_params[] = "%{$search}%";
    $count_params[] = "%{$search}%";
    $count_params[] = "%{$search}%";
    $count_param_types .= "ssss";
}

$stmt_count = $conn->prepare($sql_count);
if (!empty($count_params)) {
    $stmt_count->bind_param($count_param_types, ...$count_params);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_announcements = $count_result->fetch_assoc()['total'] ?? 0;
$stmt_count->close();

// Pagination
$per_page = 10;
$total_pages = ceil($total_announcements / $per_page);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Order by
$order_by = "a.{$sort_by} {$sort_order}";

// Get announcements with pagination
$sql_announcements = "SELECT a.*, 
                     CONCAT(u.first_name, ' ', u.last_name) as author_name,
                     CASE 
                         WHEN a.expiry_date < CURDATE() THEN 'expired'
                         ELSE 'active'
                     END as status_display,
                     ar.read_at as read_at,
                     CASE 
                         WHEN ar.student_id IS NULL THEN 0
                         ELSE 1
                     END as is_read
                     FROM announcements a
                     LEFT JOIN users u ON a.author_id = u.id
                     LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.student_id = ?
                     WHERE a.class_id = ? AND a.is_published = 1";

// Add filter conditions
$announcement_params = [$student_id, $class_id];
$announcement_param_types = "ii";

if ($filter === 'unread') {
    $sql_announcements .= " AND ar.student_id IS NULL";
} elseif ($filter === 'read') {
    $sql_announcements .= " AND ar.student_id IS NOT NULL";
} elseif ($filter === 'active') {
    $sql_announcements .= " AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
} elseif ($filter === 'expired') {
    $sql_announcements .= " AND a.expiry_date < CURDATE()";
}

if (!empty($search)) {
    $sql_announcements .= " AND (a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $announcement_params[] = "%{$search}%";
    $announcement_params[] = "%{$search}%";
    $announcement_params[] = "%{$search}%";
    $announcement_params[] = "%{$search}%";
    $announcement_param_types .= "ssss";
}

$sql_announcements .= " ORDER BY {$order_by} LIMIT ? OFFSET ?";
$announcement_params[] = $per_page;
$announcement_params[] = $offset;
$announcement_param_types .= "ii";

$stmt_announcements = $conn->prepare($sql_announcements);
if (!empty($announcement_params)) {
    $stmt_announcements->bind_param($announcement_param_types, ...$announcement_params);
}
$stmt_announcements->execute();
$announcements_result = $stmt_announcements->get_result();
$announcements = $announcements_result->fetch_all(MYSQLI_ASSOC);
$stmt_announcements->close();

// Get specific announcement for view
$action = $_GET['action'] ?? '';
$announcement_id = $_GET['id'] ?? 0;
$announcement = null;

if ($action === 'view' && $announcement_id) {
    $sql_single = "SELECT a.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   u.role as author_role
                   FROM announcements a
                   LEFT JOIN users u ON a.author_id = u.id
                   WHERE a.id = ? AND a.class_id = ? AND a.is_published = 1";
    $stmt_single = $conn->prepare($sql_single);
    $stmt_single->bind_param("ii", $announcement_id, $class_id);
    $stmt_single->execute();
    $single_result = $stmt_single->get_result();

    if ($single_result->num_rows > 0) {
        $announcement = $single_result->fetch_assoc();

        // Mark as read
        $sql_check_exists = "SELECT id FROM announcement_reads 
                            WHERE announcement_id = ? AND student_id = ?";
        $stmt_check_exists = $conn->prepare($sql_check_exists);
        $stmt_check_exists->bind_param("ii", $announcement_id, $student_id);
        $stmt_check_exists->execute();
        $result_check_exists = $stmt_check_exists->get_result();

        if ($result_check_exists->num_rows > 0) {
            $sql_update = "UPDATE announcement_reads 
                          SET read_at = NOW() 
                          WHERE announcement_id = ? AND student_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ii", $announcement_id, $student_id);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            $sql_insert = "INSERT INTO announcement_reads (announcement_id, student_id, read_at) 
                          VALUES (?, ?, NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("ii", $announcement_id, $student_id);
            $stmt_insert->execute();
            $stmt_insert->close();
        }
        $stmt_check_exists->close();
    } else {
        header("Location: announcements.php?class_id={$class_id}&message=" . urlencode('Announcement not found or no longer available.') . "&type=error");
        exit();
    }
    $stmt_single->close();
}

// Get announcement statistics
$sql_stats = "SELECT 
              COUNT(DISTINCT a.id) as total,
              COUNT(DISTINCT CASE WHEN ar.student_id IS NOT NULL THEN a.id END) as read_count,
              COUNT(DISTINCT CASE WHEN ar.student_id IS NULL THEN a.id END) as unread_count,
              COUNT(DISTINCT CASE WHEN a.expiry_date < CURDATE() THEN a.id END) as expired
              FROM announcements a
              LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.student_id = ?
              WHERE a.class_id = ? AND a.is_published = 1";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->bind_param("ii", $student_id, $class_id);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();
$stmt_stats->close();

// Get recent announcements for sidebar
$sql_recent = "SELECT a.*, 
              CONCAT(u.first_name, ' ', u.last_name) as author_name
              FROM announcements a
              LEFT JOIN users u ON a.author_id = u.id
              WHERE a.class_id = ? AND a.is_published = 1
              ORDER BY a.created_at DESC
              LIMIT 5";
$stmt_recent = $conn->prepare($sql_recent);
$stmt_recent->bind_param("i", $class_id);
$stmt_recent->execute();
$recent_announcements = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_recent->close();

$conn->close();

// Helper function for priority class
function getPriorityClass($priority)
{
    $classes = [
        'low' => 'priority-low',
        'medium' => 'priority-medium',
        'high' => 'priority-high'
    ];
    return $classes[$priority] ?? 'priority-normal';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#4361ee">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Announcements</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        /* CSS Variables - Mobile First */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f94144;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border: #dee2e6;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
            --safe-bottom: env(safe-area-inset-bottom, 0);
            --safe-top: env(safe-area-inset-top, 0);
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overscroll-behavior: none;
            padding-bottom: env(safe-area-inset-bottom);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: max(0.75rem, env(safe-area-inset-left)) max(0.75rem, env(safe-area-inset-right));
            padding-bottom: max(2rem, env(safe-area-inset-bottom));
        }

        /* Breadcrumb - Mobile Optimized */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            color: var(--gray);
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding: 0.25rem 0;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 2rem;
            border: 1px solid var(--border);
            transition: var(--transition);
            font-size: 0.75rem;
        }

        .breadcrumb a:hover {
            background: white;
            border-color: var(--primary);
        }

        .breadcrumb .separator {
            opacity: 0.5;
            margin: 0 0.15rem;
        }

        .breadcrumb span {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
            font-size: 0.75rem;
        }

        /* Header - Mobile Optimized */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-lg);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.25rem;
            position: relative;
            z-index: 2;
        }

        @media (min-width: 640px) {
            .header-top {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .class-info h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            word-break: break-word;
        }

        @media (min-width: 768px) {
            .class-info h1 {
                font-size: 2rem;
            }
        }

        .class-info p {
            font-size: 0.9rem;
            opacity: 0.9;
            word-break: break-word;
        }

        @media (min-width: 768px) {
            .class-info p {
                font-size: 1.1rem;
            }
        }

        /* Navigation - Mobile Optimized */
        .header-nav {
            display: flex;
            gap: 0.35rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.5rem 0 0.75rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            position: relative;
            z-index: 2;
        }

        .header-nav::-webkit-scrollbar {
            display: none;
        }

        .nav-link {
            padding: 0.6rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 2rem;
            text-decoration: none;
            color: white;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            white-space: nowrap;
            font-size: 0.8rem;
            min-height: 44px;
        }

        @media (min-width: 768px) {
            .nav-link {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        /* Stats Grid - Mobile First */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 1.25rem 0.75rem;
            box-shadow: var(--shadow);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.read {
            border-top-color: var(--success);
        }

        .stat-card.unread {
            border-top-color: var(--warning);
        }

        .stat-card.expired {
            border-top-color: var(--danger);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--dark);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Layout - Mobile First */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr 350px;
            }
        }

        /* Controls - Mobile Optimized */
        .controls {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .controls {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .filter-group {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
            border: none;
            font-size: 0.8rem;
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.7rem;
            min-height: 36px;
        }

        .search-box {
            width: 100%;
        }

        @media (min-width: 768px) {
            .search-box {
                flex: 1;
                max-width: 400px;
            }
        }

        .search-box input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            transition: var(--transition);
            -webkit-appearance: none;
            appearance: none;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Announcements List */
        .announcements-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .announcement-card {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .announcement-card:active {
            transform: scale(0.99);
        }

        .announcement-card.unread {
            border-left: 4px solid var(--primary);
        }

        .announcement-header {
            padding: 1.25rem 1.25rem 0.5rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .announcement-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .announcement-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .badge {
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }

        .badge-new {
            background: var(--primary);
            color: white;
        }

        .badge-expired {
            background: var(--danger);
            color: white;
        }

        .badge-active {
            background: var(--success);
            color: white;
        }

        .priority-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .priority-low {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .priority-medium {
            background: rgba(248, 150, 30, 0.1);
            color: var(--warning);
        }

        .priority-high {
            background: rgba(249, 65, 68, 0.1);
            color: var(--danger);
        }

        .priority-normal {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .read-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .read-badge.read {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .read-badge.unread {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray);
            align-items: center;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .announcement-content {
            padding: 0.75rem 1.25rem;
            color: var(--gray);
            font-size: 0.85rem;
            line-height: 1.5;
            word-break: break-word;
        }

        .announcement-footer {
            padding: 0.75rem 1.25rem;
            background: var(--light);
            border-top: 1px solid var(--gray-light);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .announcement-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Announcement View */
        .announcement-view {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .announcement-view-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-light);
        }

        .announcement-view-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.3;
            word-break: break-word;
        }

        @media (min-width: 768px) {
            .announcement-view-title {
                font-size: 1.75rem;
            }
        }

        .announcement-view-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            color: var(--gray);
            font-size: 0.8rem;
        }

        .announcement-view-content {
            line-height: 1.8;
            color: var(--dark);
            margin-bottom: 2rem;
            font-size: 0.95rem;
            word-break: break-word;
        }

        .announcement-view-content p {
            margin-bottom: 1rem;
        }

        .announcement-view-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--gray-light);
        }

        @media (min-width: 640px) {
            .announcement-view-actions {
                flex-direction: row;
            }
        }

        /* Sidebar Cards */
        .sidebar-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.25rem;
        }

        .sidebar-card h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-list {
            list-style: none;
        }

        .sidebar-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-list li:last-child {
            border-bottom: none;
        }

        .sidebar-list a {
            text-decoration: none;
            color: var(--dark);
            display: block;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .sidebar-list a:hover {
            color: var(--primary);
        }

        .sidebar-list .meta {
            font-size: 0.7rem;
            color: var(--gray);
            margin-top: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .stat-row:last-child {
            border-bottom: none;
        }

        .stat-label-sm {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .stat-value-sm {
            font-weight: 600;
            color: var(--dark);
        }

        .tips-list {
            list-style: none;
        }

        .tips-list li {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-light);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tips-list li i {
            width: 20px;
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: var(--radius);
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--gray);
            box-shadow: var(--shadow);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        /* Pagination - Mobile Optimized */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.25rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--dark);
            background: white;
            border: 2px solid var(--border);
            font-size: 0.8rem;
            min-width: 40px;
            text-align: center;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .pagination a:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.85rem;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .alert i {
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 0.1rem;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .announcement-card,
            .nav-link,
            .pagination a {
                -webkit-tap-highlight-color: transparent;
            }
        }

        /* Accessibility */
        .visually-hidden {
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

        :focus {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        :focus-visible {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i>
                <span class="visually-hidden">Dashboard</span>
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i>
                <span class="visually-hidden">My Classes</span>
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Announcements</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-book"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-users"></i> Classmates
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i> Join
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'error' ? 'error' : ($message_type === 'info' ? 'info' : 'success'); ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-circle' : ($message_type === 'info' ? 'info-circle' : 'check-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card unread">
                <div class="stat-value"><?php echo $stats['unread_count'] ?? 0; ?></div>
                <div class="stat-label">Unread</div>
            </div>
            <div class="stat-card read">
                <div class="stat-value"><?php echo $stats['read_count'] ?? 0; ?></div>
                <div class="stat-label">Read</div>
            </div>
            <div class="stat-card expired">
                <div class="stat-value"><?php echo $stats['expired'] ?? 0; ?></div>
                <div class="stat-label">Expired</div>
            </div>
        </div>

        <div class="content-layout">
            <!-- Main Content -->
            <div class="main-content">
                <?php if ($action === 'view' && $announcement): ?>
                    <!-- Announcement View -->
                    <div class="announcement-view">
                        <div class="announcement-view-header">
                            <h1 class="announcement-view-title">
                                <?php echo htmlspecialchars($announcement['title']); ?>
                            </h1>

                            <div class="announcement-view-meta">
                                <span class="meta-item"><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                <?php if ($announcement['author_role'] === 'instructor'): ?>
                                    <span class="badge" style="background: var(--primary); color: white;">Instructor</span>
                                <?php endif; ?>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                <?php if ($announcement['expiry_date']): ?>
                                    <span class="meta-item"><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?></span>
                                <?php endif; ?>
                                <span class="priority-badge <?php echo getPriorityClass($announcement['priority']); ?>">
                                    <?php echo ucfirst($announcement['priority']); ?> Priority
                                </span>
                                <span class="badge <?php echo ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) ? 'badge-expired' : 'badge-active'; ?>">
                                    <?php echo ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) ? 'Expired' : 'Active'; ?>
                                </span>
                                <span class="read-badge read">
                                    <i class="fas fa-check-circle"></i> Read
                                </span>
                            </div>
                        </div>

                        <div class="announcement-view-content">
                            <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                        </div>

                        <div class="announcement-view-actions">
                            <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                            <?php if (!($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time())): ?>
                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=create&announcement=<?php echo $announcement_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-comment"></i> Discuss
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Announcements List -->
                    <div class="controls">
                        <form method="get" action="" class="search-box">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="text"
                                name="search"
                                placeholder="Search announcements..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                aria-label="Search announcements">
                        </form>

                        <div class="filter-group">
                            <a href="?class_id=<?php echo $class_id; ?>&filter=all"
                                class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">
                                All
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=unread"
                                class="btn <?php echo $filter === 'unread' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Unread
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=read"
                                class="btn <?php echo $filter === 'read' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Read
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=active"
                                class="btn <?php echo $filter === 'active' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Active
                            </a>
                            <a href="?class_id=<?php echo $class_id; ?>&filter=expired"
                                class="btn <?php echo $filter === 'expired' ? 'btn-primary' : 'btn-secondary'; ?>">
                                Expired
                            </a>
                        </div>
                    </div>

                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h3>No announcements found</h3>
                            <p>
                                <?php if (!empty($search)): ?>
                                    Try different search terms or <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">clear filters</a>
                                <?php elseif ($filter !== 'all'): ?>
                                    Try a different filter or <a href="?class_id=<?php echo $class_id; ?>" style="color: var(--primary);">view all</a>
                                <?php else: ?>
                                    Check back later for updates
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="announcements-list">
                            <?php foreach ($announcements as $ann): ?>
                                <div class="announcement-card <?php echo $ann['is_read'] ? '' : 'unread'; ?>">
                                    <div class="announcement-header">
                                        <div style="flex: 1;">
                                            <div class="announcement-title">
                                                <?php echo htmlspecialchars($ann['title']); ?>
                                                <?php if (!$ann['is_read']): ?>
                                                    <span class="badge badge-new">New</span>
                                                <?php endif; ?>
                                                <?php if ($ann['status_display'] === 'expired'): ?>
                                                    <span class="badge badge-expired">Expired</span>
                                                <?php endif; ?>
                                                <span class="priority-badge <?php echo getPriorityClass($ann['priority']); ?>">
                                                    <?php echo ucfirst($ann['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="announcement-meta">
                                                <span class="meta-item"><i class="fas fa-user"></i> <?php echo htmlspecialchars($ann['author_name']); ?></span>
                                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($ann['created_at'])); ?></span>
                                                <?php if ($ann['expiry_date']): ?>
                                                    <span class="meta-item"><i class="fas fa-clock"></i> Exp: <?php echo date('M j', strtotime($ann['expiry_date'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="announcement-content">
                                        <?php
                                        $content = strip_tags($ann['content']);
                                        echo htmlspecialchars(substr($content, 0, 150));
                                        if (strlen($content) > 150) echo '...';
                                        ?>
                                    </div>

                                    <div class="announcement-footer">
                                        <div class="announcement-actions">
                                            <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $ann['id']; ?>"
                                                class="btn btn-primary btn-small">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if ($ann['status_display'] === 'active'): ?>
                                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=create&announcement=<?php echo $ann['id']; ?>"
                                                    class="btn btn-secondary btn-small">
                                                    <i class="fas fa-comment"></i> Discuss
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($ann['read_at']): ?>
                                            <span class="read-badge read">
                                                <i class="fas fa-check"></i> Read <?php echo date('M j', strtotime($ann['read_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>"
                                            class="<?php echo $i == $page ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <span>...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Quick Stats -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-chart-bar"></i> Quick Stats</h3>
                    <div class="stat-row">
                        <span class="stat-label-sm">Unread:</span>
                        <span class="stat-value-sm"><?php echo $stats['unread_count'] ?? 0; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label-sm">Active:</span>
                        <span class="stat-value-sm"><?php echo ($stats['total'] ?? 0) - ($stats['expired'] ?? 0); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label-sm">Expired:</span>
                        <span class="stat-value-sm"><?php echo $stats['expired'] ?? 0; ?></span>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-history"></i> Recent</h3>
                    <?php if (empty($recent_announcements)): ?>
                        <p style="color: var(--gray); text-align: center; padding: 0.5rem 0; font-size: 0.85rem;">No recent announcements</p>
                    <?php else: ?>
                        <ul class="sidebar-list">
                            <?php foreach ($recent_announcements as $recent): ?>
                                <li>
                                    <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $recent['id']; ?>">
                                        <strong><?php echo htmlspecialchars(substr($recent['title'], 0, 40)); ?><?php echo strlen($recent['title']) > 40 ? '...' : ''; ?></strong>
                                        <div class="meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($recent['created_at'])); ?></span>
                                            <span class="priority-badge <?php echo getPriorityClass($recent['priority']); ?> btn-small">
                                                <?php echo ucfirst($recent['priority']); ?>
                                            </span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Tips -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-lightbulb"></i> Tips</h3>
                    <ul class="tips-list">
                        <li><i class="fas fa-bell"></i> Check announcements regularly</li>
                        <li><i class="fas fa-clock"></i> Note expiration dates</li>
                        <li><i class="fas fa-exclamation-triangle"></i> High priority = important</li>
                        <li><i class="fas fa-comments"></i> Discuss in forums</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit search on enter
        document.querySelector('input[name="search"]')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }

            // Esc to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }

            // Alt + R to mark as read (when in view mode)
            if (e.altKey && e.key === 'r' && <?php echo $action === 'view' ? 'true' : 'false'; ?>) {
                e.preventDefault();
                // Could implement mark as read functionality here
            }
        });

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .announcement-card, .nav-link, .pagination a').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Store read status in localStorage for quick access
        document.addEventListener('DOMContentLoaded', function() {
            // Mark as read when viewed
            if (window.location.href.includes('action=view')) {
                const announcementId = <?php echo $announcement_id ?? 0; ?>;
                if (announcementId) {
                    localStorage.setItem(`announcement_${announcementId}_read`, Date.now().toString());

                    // Update unread count in stats
                    const unreadCount = document.querySelector('.stat-card.unread .stat-value');
                    if (unreadCount) {
                        const count = parseInt(unreadCount.textContent);
                        if (count > 0) {
                            unreadCount.textContent = count - 1;
                        }
                    }
                }
            }

            // Highlight unread announcements based on localStorage
            document.querySelectorAll('.announcement-card').forEach(card => {
                const link = card.querySelector('a[href*="action=view"]');
                if (link) {
                    const id = link.href.match(/id=(\d+)/)?.[1];
                    if (id && localStorage.getItem(`announcement_${id}_read`)) {
                        card.classList.remove('unread');
                        const newBadge = card.querySelector('.badge-new');
                        if (newBadge) newBadge.remove();
                    }
                }
            });
        });

        // Check for new announcements periodically
        let lastCheck = Date.now();
        const refreshInterval = 300000; // 5 minutes

        function checkForNewAnnouncements() {
            const now = Date.now();
            if (now - lastCheck > refreshInterval) {
                lastCheck = now;
                // This would typically be an AJAX call to check for new announcements
                // For now, we'll just update the UI if needed
            }
        }

        setInterval(checkForNewAnnouncements, refreshInterval);

        // Print announcement functionality
        function printAnnouncement() {
            const content = document.querySelector('.announcement-view-content')?.innerHTML;
            const title = document.querySelector('.announcement-view-title')?.textContent;

            if (content && title) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>${title}</title>
                            <style>
                                body { font-family: Arial, sans-serif; padding: 2rem; line-height: 1.6; }
                                h1 { color: #4361ee; }
                                .meta { color: #666; font-size: 0.9rem; margin-bottom: 2rem; }
                                .content { max-width: 800px; margin: 0 auto; }
                            </style>
                        </head>
                        <body>
                            <div class="content">
                                <h1>${title}</h1>
                                <div class="meta">
                                    ${document.querySelector('.announcement-view-meta')?.innerHTML || ''}
                                </div>
                                <div>${content}</div>
                            </div>
                        </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
            }
        }

        // Share announcement (if Web Share API is available)
        if (navigator.share && <?php echo $action === 'view' ? 'true' : 'false'; ?>) {
            const shareButton = document.createElement('button');
            shareButton.className = 'btn btn-secondary';
            shareButton.innerHTML = '<i class="fas fa-share-alt"></i> Share';
            shareButton.onclick = function() {
                navigator.share({
                    title: document.querySelector('.announcement-view-title')?.textContent,
                    text: 'Check out this announcement from class',
                    url: window.location.href
                }).catch(console.error);
            };

            const actionsDiv = document.querySelector('.announcement-view-actions');
            if (actionsDiv) {
                actionsDiv.appendChild(shareButton);
            }
        }
    </script>
</body>

</html>