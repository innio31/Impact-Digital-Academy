
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

// Handle view action
$action = $_GET['action'] ?? '';
$announcement_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

/*
// Mark announcement as read - FIXED with better error handling
if ($action === 'view' && $announcement_id) {
    // Check if announcement exists and is in this class and published
    $sql_check = "SELECT a.id FROM announcements a 
                  WHERE a.id = ? AND a.class_id = ? AND a.is_published = 1";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("ii", $announcement_id, $class_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        // First check if record already exists
        $sql_check_exists = "SELECT id FROM announcement_reads 
                            WHERE announcement_id = ? AND student_id = ?";
        $stmt_check_exists = $conn->prepare($sql_check_exists);
        $stmt_check_exists->bind_param("ii", $announcement_id, $student_id);
        $stmt_check_exists->execute();
        $result_check_exists = $stmt_check_exists->get_result();
        
        if ($result_check_exists->num_rows > 0) {
            // Update existing record
            $sql_update = "UPDATE announcement_reads 
                          SET read_at = NOW() 
                          WHERE announcement_id = ? AND student_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ii", $announcement_id, $student_id);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            // Insert new record
            $sql_insert = "INSERT INTO announcement_reads (announcement_id, student_id, read_at) 
                          VALUES (?, ?, NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("ii", $announcement_id, $student_id);
            $stmt_insert->execute();
            $stmt_insert->close();
        }
        
        $stmt_check_exists->close();
    }
    $stmt_check->close();
}
*/

// Get message from URL if present
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $message_type = isset($_GET['type']) ? $_GET['type'] : 'success';
}

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
    } else {
        // Announcement not found or not accessible
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Announcements</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .class-info h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .class-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
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
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .content-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Controls */
        .controls {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        .search-box {
            flex: 1;
            min-width: 300px;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Announcements List */
        .announcements-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .announcement-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .announcement-card.unread {
            border-left: 4px solid var(--primary);
        }

        .announcement-header {
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .announcement-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--gray);
            align-items: center;
            flex-wrap: wrap;
        }

        .announcement-content {
            padding: 1rem 1.5rem;
            color: var(--dark);
            line-height: 1.6;
        }

        .announcement-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .priority-low {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .priority-medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .priority-high {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .read-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .read-badge.read {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .read-badge.unread {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            color: var(--gray);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Announcement View */
        .announcement-view {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .announcement-view-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .announcement-view-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .announcement-view-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            color: var(--gray);
            font-size: 0.875rem;
        }

        .announcement-view-content {
            line-height: 1.8;
            color: var(--dark);
            margin-bottom: 2rem;
        }

        .announcement-view-content p {
            margin-bottom: 1rem;
        }

        .announcement-view-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        /* Sidebar Cards */
        .sidebar-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .sidebar-card h3 {
            font-size: 1.1rem;
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
            border-bottom: 1px solid #f1f5f9;
        }

        .sidebar-list li:last-child {
            border-bottom: none;
        }

        .sidebar-list a {
            text-decoration: none;
            color: var(--dark);
            display: block;
            transition: color 0.3s ease;
        }

        .sidebar-list a:hover {
            color: var(--primary);
        }

        .sidebar-list .meta {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger);
            color: var(--danger);
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--dark);
            background: white;
            border: 2px solid #e2e8f0;
        }

        .pagination a:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                min-width: 100%;
                max-width: 100%;
            }

            .filter-group {
                justify-content: center;
            }

            .announcement-header,
            .announcement-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .announcement-view-actions {
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
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; color: var(--gray);">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" style="color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 0.25rem;">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php" style="color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 0.25rem;">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>" style="color: var(--primary); text-decoration: none;">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Announcements</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Announcements</h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?> - <?php echo htmlspecialchars($class['program_name']); ?></p>
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
                    <i class="fas fa-tasks"></i> Quizzes
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
                        <i class="fas fa-video"></i> Join Class
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Announcements</div>
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
                            <div class="announcement-view-title">
                                <?php echo htmlspecialchars($announcement['title']); ?>
                            </div>

                            <div class="announcement-view-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?>
                                    <?php if ($announcement['author_role'] === 'instructor'): ?>
                                        <span style="color: var(--primary); font-weight: 600;">(Instructor)</span>
                                    <?php endif; ?>
                                </span>
                                <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                <?php if ($announcement['expiry_date']): ?>
                                    <span><i class="fas fa-clock"></i> Expires: <?php echo date('F j, Y', strtotime($announcement['expiry_date'])); ?></span>
                                <?php endif; ?>
                                <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                    <?php echo ucfirst($announcement['priority']); ?> Priority
                                </span>
                                <span class="status-badge status-<?php echo ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time()) ? 'expired' : 'active'; ?>">
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
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            <?php if ($announcement['expiry_date'] && strtotime($announcement['expiry_date']) > time()): ?>
                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=create&announcement=<?php echo $announcement_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-comment"></i> Discuss This
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Announcements List -->
                    <div class="controls">
                        <form method="get" action="" class="search-box">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="text" name="search" placeholder="Search announcements..."
                                value="<?php echo htmlspecialchars($search); ?>">
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
                                    No announcements match your search criteria.
                                <?php elseif ($filter !== 'all'): ?>
                                    No announcements match the selected filter.
                                <?php else: ?>
                                    No announcements have been posted yet.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="announcements-list">
                            <?php foreach ($announcements as $ann): ?>
                                <div class="announcement-card <?php echo $ann['is_read'] ? '' : 'unread'; ?>">
                                    <div class="announcement-header">
                                        <div>
                                            <div class="announcement-title">
                                                <?php echo htmlspecialchars($ann['title']); ?>
                                                <?php if (!$ann['is_read']): ?>
                                                    <span class="read-badge unread">
                                                        <i class="fas fa-circle"></i> New
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($ann['status_display'] === 'expired'): ?>
                                                    <span class="status-badge status-expired">
                                                        Expired
                                                    </span>
                                                <?php endif; ?>
                                                <span class="priority-badge priority-<?php echo $ann['priority']; ?>">
                                                    <?php echo ucfirst($ann['priority']); ?>
                                                </span>
                                            </div>
                                            <div class="announcement-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($ann['author_name']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($ann['created_at'])); ?></span>
                                                <?php if ($ann['expiry_date']): ?>
                                                    <span><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y', strtotime($ann['expiry_date'])); ?></span>
                                                <?php endif; ?>
                                                <?php if ($ann['read_at']): ?>
                                                    <span><i class="fas fa-check"></i> Read: <?php echo date('M j', strtotime($ann['read_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="announcement-content">
                                        <?php
                                        $content = strip_tags($ann['content']);
                                        if (strlen($content) > 200) {
                                            echo htmlspecialchars(substr($content, 0, 200)) . '...';
                                        } else {
                                            echo htmlspecialchars($content);
                                        }
                                        ?>
                                    </div>

                                    <div class="announcement-footer">
                                        <div class="announcement-actions">
                                            <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $ann['id']; ?>"
                                                class="btn btn-primary">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                            <?php if ($ann['expiry_date'] && strtotime($ann['expiry_date']) > time()): ?>
                                                <a href="discussions.php?class_id=<?php echo $class_id; ?>&action=create&announcement=<?php echo $ann['id']; ?>" class="btn btn-secondary">
                                                    <i class="fas fa-comment"></i> Discuss
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
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
                                        Next <i class="fas fa-chevron-right"></i>
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
                    <div style="display: grid; gap: 0.75rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Unread:</span>
                            <span style="font-weight: 600;"><?php echo $stats['unread_count'] ?? 0; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Active:</span>
                            <span style="font-weight: 600;"><?php echo ($stats['total'] ?? 0) - ($stats['expired'] ?? 0); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>Expired:</span>
                            <span style="font-weight: 600;"><?php echo $stats['expired'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <div class="sidebar-card">
                    <h3><i class="fas fa-history"></i> Recent Announcements</h3>
                    <?php if (empty($recent_announcements)): ?>
                        <p style="color: var(--gray); text-align: center; padding: 1rem 0;">No recent announcements</p>
                    <?php else: ?>
                        <ul class="sidebar-list">
                            <?php foreach ($recent_announcements as $recent): ?>
                                <li>
                                    <a href="announcements.php?class_id=<?php echo $class_id; ?>&action=view&id=<?php echo $recent['id']; ?>">
                                        <strong><?php echo htmlspecialchars(substr($recent['title'], 0, 50)); ?><?php echo strlen($recent['title']) > 50 ? '...' : ''; ?></strong>
                                        <div class="meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($recent['created_at'])); ?></span>
                                            <span class="priority-badge priority-<?php echo $recent['priority']; ?> btn-sm">
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
                    <ul style="list-style: none; padding-left: 0;">
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-bell" style="color: var(--primary); margin-right: 0.5rem;"></i>
                            Check announcements regularly
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-clock" style="color: var(--warning); margin-right: 0.5rem;"></i>
                            Note expiration dates
                        </li>
                        <li style="padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                            <i class="fas fa-exclamation-triangle" style="color: var(--danger); margin-right: 0.5rem;"></i>
                            High priority = important info
                        </li>
                        <li style="padding: 0.5rem 0;">
                            <i class="fas fa-comments" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Discuss in class forums
                        </li>
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

        // Mark all as read functionality
        function markAllAsRead() {
            if (confirm('Mark all announcements as read?')) {
                // This would typically be an AJAX call
                window.location.href = 'announcements.php?class_id=<?php echo $class_id; ?>&mark_all_read=1';
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
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
        });

        // Auto-refresh for new announcements
        let lastCheck = Date.now();
        const refreshInterval = 300000; // 5 minutes

        function checkForNewAnnouncements() {
            const now = Date.now();
            if (now - lastCheck > refreshInterval) {
                lastCheck = now;
                fetch(`announcements.php?class_id=<?php echo $class_id; ?>&check_new=1&t=${now}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.new > 0) {
                            showNotification(`${data.new} new announcement${data.new > 1 ? 's' : ''}`, data.new);
                        }
                    })
                    .catch(error => console.error('Error checking for new announcements:', error));
            }
        }

        function showNotification(message, count) {
            // Check if notification permission is granted
            if (Notification.permission === "granted") {
                new Notification("New Announcements", {
                    body: message,
                    icon: "/favicon.ico"
                });
            } else if (Notification.permission !== "denied") {
                Notification.requestPermission().then(permission => {
                    if (permission === "granted") {
                        new Notification("New Announcements", {
                            body: message,
                            icon: "/favicon.ico"
                        });
                    }
                });
            }

            // Update page badge if we're on the page
            document.title = `(${count}) ${document.title.replace(/^\(\d+\)\s*/, '')}`;
        }

        // Start checking for new announcements
        if (Notification.permission === "default") {
            Notification.requestPermission();
        }

        // Check every 5 minutes
        setInterval(checkForNewAnnouncements, refreshInterval);
        checkForNewAnnouncements(); // Initial check

        // Store read status
        document.addEventListener('DOMContentLoaded', function() {
            // Mark announcements as read when viewed
            if (window.location.href.includes('action=view')) {
                const announcementId = <?php echo $announcement_id ?? 0; ?>;
                if (announcementId) {
                    localStorage.setItem(`announcement_${announcementId}_read`, 'true');
                }
            }

            // Highlight unread announcements
            document.querySelectorAll('.announcement-card').forEach(card => {
                const link = card.querySelector('a[href*="action=view"]');
                if (link) {
                    const id = link.href.match(/id=(\d+)/)?.[1];
                    if (id && localStorage.getItem(`announcement_${id}_read`)) {
                        card.classList.remove('unread');
                    }
                }
            });
        });

        // Print announcement
        function printAnnouncement() {
            window.print();
        }
    </script>
</body>

</html>