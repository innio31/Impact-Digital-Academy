<?php
// modules/instructor/announcements/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get filter parameters
$filter_class = isset($_GET['class']) ? (int)$_GET['class'] : '';
$filter_priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'published';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build SQL query with filters
$where_conditions = ["(a.class_id IS NULL OR cb.instructor_id = ?)"];
$params = [$instructor_id];
$param_types = "i";

// Add search filter
if (!empty($search)) {
    $where_conditions[] = "(a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "ssss";
}

// Add class filter
if (!empty($filter_class)) {
    $where_conditions[] = "a.class_id = ?";
    $params[] = $filter_class;
    $param_types .= "i";
}

// Add priority filter
if (!empty($filter_priority)) {
    $where_conditions[] = "a.priority = ?";
    $params[] = $filter_priority;
    $param_types .= "s";
}

// Add status filter
if ($filter_status === 'published') {
    $where_conditions[] = "a.is_published = 1";
} elseif ($filter_status === 'unpublished') {
    $where_conditions[] = "a.is_published = 0";
}

// Add date filters
if (!empty($filter_date_from)) {
    $where_conditions[] = "DATE(a.created_at) >= ?";
    $params[] = $filter_date_from;
    $param_types .= "s";
}

if (!empty($filter_date_to)) {
    $where_conditions[] = "DATE(a.created_at) <= ?";
    $params[] = $filter_date_to;
    $param_types .= "s";
}

// Add expiry filter (show only non-expired or all)
if ($filter_status === 'active') {
    $where_conditions[] = "(a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(DISTINCT a.id) as total 
              FROM announcements a 
              LEFT JOIN class_batches cb ON a.class_id = cb.id 
              LEFT JOIN users u ON a.author_id = u.id 
              $where_clause";

$stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$count_result = $stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'];
$stmt->close();

// Calculate total pages
$total_pages = ceil($total_count / $limit);

// Get announcements with pagination
$announcements = [];
$sql = "SELECT a.*, 
               cb.batch_code, 
               cb.name as class_name,
               c.title as course_title,
               c.course_code,
               CONCAT(u.first_name, ' ', u.last_name) as author_name,
               u.role as author_role,
               (SELECT COUNT(*) FROM notifications WHERE related_id = a.id AND type = 'announcement') as notification_count,
               CASE 
                   WHEN a.expiry_date IS NULL THEN 'active'
                   WHEN a.expiry_date >= CURDATE() THEN 'active'
                   ELSE 'expired'
               END as status_display
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        LEFT JOIN courses c ON cb.course_id = c.id 
        LEFT JOIN users u ON a.author_id = u.id 
        $where_clause 
        GROUP BY a.id 
        ORDER BY a.is_published DESC, a.priority DESC, a.created_at DESC 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$announcements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get instructor's classes for filter dropdown
$instructor_classes = [];
$sql = "SELECT cb.id, cb.batch_code, cb.name, c.title as course_title
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.instructor_id = ? AND cb.status IN ('scheduled', 'ongoing')
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$instructor_classes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get announcement statistics
$stats = [
    'total' => 0,
    'published' => 0,
    'unpublished' => 0,
    'expired' => 0,
    'high_priority' => 0
];

$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN a.is_published = 1 THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN a.is_published = 0 THEN 1 ELSE 0 END) as unpublished,
            SUM(CASE WHEN a.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN a.priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        WHERE (a.class_id IS NULL OR cb.instructor_id = ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row) {
    $stats['total'] = $row['total'] ?? 0;
    $stats['published'] = $row['published'] ?? 0;
    $stats['unpublished'] = $row['unpublished'] ?? 0;
    $stats['expired'] = $row['expired'] ?? 0;
    $stats['high_priority'] = $row['high_priority_count'] ?? 0;
}
$stmt->close();

// Get recent announcement activity
$recent_activity = [];
$sql = "SELECT DATE(a.created_at) as date, COUNT(*) as count
        FROM announcements a 
        LEFT JOIN class_batches cb ON a.class_id = cb.id 
        WHERE (a.class_id IS NULL OR cb.instructor_id = ?) 
        AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(a.created_at)
        ORDER BY date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_activity = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Impact Digital Academy</title>
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
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 4px solid var(--info);
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid var(--warning);
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
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.total {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .stat-icon.published {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.unpublished {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-icon.high {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-section h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.875rem;
            color: var(--gray);
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            grid-column: 1 / -1;
            justify-content: flex-end;
        }

        /* Announcements List */
        .announcements-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .announcements-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .announcements-header h2 {
            font-size: 1.25rem;
            color: var(--dark);
        }

        .announcements-count {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .announcements-list {
            max-height: 800px;
            overflow-y: auto;
        }

        .announcement-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            transition: background 0.3s ease;
        }

        .announcement-item:hover {
            background: var(--light);
        }

        .announcement-item:last-child {
            border-bottom: none;
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .announcement-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .announcement-meta {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .announcement-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-priority-high {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status-published {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status-unpublished {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-status-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-author-admin {
            background: #e0e7ff;
            color: #3730a3;
        }

        .badge-author-instructor {
            background: #f0f9ff;
            color: #0369a1;
        }

        .announcement-content {
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
            max-height: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
        }

        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .btn-view:hover {
            background: var(--primary);
            color: white;
        }

        /* No Announcements */
        .no-announcements {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .no-announcements i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
            opacity: 0.5;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            background: white;
            color: var(--dark);
            text-decoration: none;
            border: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-item.active .page-link {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-item.disabled .page-link {
            background: var(--light);
            color: var(--gray);
            cursor: not-allowed;
        }

        /* Content Layout */
        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }

        @media (max-width: 1200px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }
        }

        /* Sidebar */
        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            height: fit-content;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-section h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .class-list {
            list-style: none;
        }

        .class-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            transition: background 0.3s ease;
            cursor: pointer;
        }

        .class-item:hover {
            background: var(--light);
        }

        .class-item.active {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid var(--primary);
        }

        .class-item:last-child {
            border-bottom: none;
        }

        .class-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .class-code {
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Recent Activity */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-date {
            font-weight: 600;
            color: var(--dark);
        }

        .activity-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border-radius: 10px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            text-align: center;
        }

        .quick-action:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.1);
        }

        .quick-action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .quick-action-label {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Back button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .back-button:hover {
            color: var(--secondary);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Back Button -->
        <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Header -->
        <div class="header">
            <div>
                <h1><i class="fas fa-bullhorn"></i> Announcements</h1>
                <p style="color: var(--gray); margin-top: 0.5rem;">
                    View all announcements from admin and for your classes
                </p>
            </div>

            <div class="header-actions">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Announcement
                </a>
            </div>
        </div>

        <!-- Success Message -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success'];
                unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['total'] ?? 0; ?></h3>
                    <p>Total Announcements</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon published">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['published'] ?? 0; ?></h3>
                    <p>Published</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon expired">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['expired'] ?? 0; ?></h3>
                    <p>Expired</p>
                </div>
            </div>
        </div>

        <div class="content-wrapper">
            <div class="main-content">
                <!-- Filters Section -->
                <div class="filters-section">
                    <h3><i class="fas fa-filter"></i> Filter Announcements</h3>
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" placeholder="Search announcements..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-chalkboard"></i> Class</label>
                            <select name="class">
                                <option value="">All Classes</option>
                                <?php foreach ($instructor_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select name="priority">
                                <option value="">All Priorities</option>
                                <option value="high" <?php echo $filter_priority == 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $filter_priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $filter_priority == 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Announcements List -->
                <div class="announcements-container">
                    <div class="announcements-header">
                        <div>
                            <h2>All Announcements</h2>
                            <p class="announcements-count">
                                Showing <?php echo count($announcements); ?> of <?php echo $total_count; ?> announcements
                            </p>
                        </div>
                        <div>
                            <span style="color: var(--gray); font-size: 0.875rem;">
                                Sorted by: Priority & Date
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($announcements)): ?>
                        <div class="announcements-list">
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="announcement-item">
                                    <div class="announcement-header">
                                        <div style="flex: 1;">
                                            <h3 class="announcement-title">
                                                <?php echo htmlspecialchars($announcement['title']); ?>
                                                <?php if ($announcement['notification_count'] > 0): ?>
                                                    <span style="font-size: 0.75rem; color: var(--primary); margin-left: 0.5rem;">
                                                        <i class="fas fa-bell"></i> <?php echo $announcement['notification_count']; ?> notified
                                                    </span>
                                                <?php endif; ?>
                                            </h3>

                                            <div class="announcement-meta">
                                                <span class="announcement-badge badge-priority-<?php echo $announcement['priority']; ?>">
                                                    <i class="fas fa-flag"></i> <?php echo ucfirst($announcement['priority']); ?> Priority
                                                </span>

                                                <span class="announcement-badge badge-status-<?php echo $announcement['is_published'] ? 'published' : 'unpublished'; ?>">
                                                    <?php if ($announcement['is_published']): ?>
                                                        <i class="fas fa-check"></i> Published
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i> Unpublished
                                                    <?php endif; ?>
                                                </span>

                                                <span class="announcement-badge badge-author-<?php echo strtolower($announcement['author_role']); ?>">
                                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?>
                                                </span>

                                                <?php if ($announcement['class_name']): ?>
                                                    <span style="color: var(--gray); font-size: 0.875rem;">
                                                        <i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($announcement['batch_code']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--gray); font-size: 0.875rem;">
                                                        <i class="fas fa-globe"></i> All Classes
                                                    </span>
                                                <?php endif; ?>

                                                <span style="color: var(--gray); font-size: 0.875rem;">
                                                    <i class="fas fa-clock"></i> <?php echo time_ago($announcement['created_at']); ?>
                                                </span>

                                                <?php if ($announcement['expiry_date']): ?>
                                                    <span style="color: <?php echo strtotime($announcement['expiry_date']) < time() ? 'var(--danger)' : 'var(--gray)'; ?>; font-size: 0.875rem;">
                                                        <i class="fas fa-calendar-times"></i> Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="announcement-content">
                                        <?php echo nl2br(htmlspecialchars(truncate_text($announcement['content'], 300))); ?>
                                    </div>

                                    <div class="announcement-actions">
                                        <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/view.php?id=<?php echo $announcement['id']; ?>"
                                            class="btn-icon btn-view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </span>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);

                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </span>
                                    </li>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- No Announcements -->
                        <div class="no-announcements">
                            <i class="fas fa-bullhorn-slash"></i>
                            <h3 style="margin-bottom: 1rem; color: var(--gray);">No announcements found</h3>
                            <p style="color: var(--gray); margin-bottom: 1.5rem;">
                                <?php if (!empty($search) || !empty($filter_class) || !empty($filter_priority)): ?>
                                    Try changing your search criteria or filters
                                <?php else: ?>
                                    There are no announcements available at the moment
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($search) || !empty($filter_class) || !empty($filter_priority)): ?>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/" class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Quick Actions -->
                <div class="sidebar-section">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    <div class="quick-actions-grid">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/create.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="quick-action-label">New Announcement</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="quick-action-label">Dashboard</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/view.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="quick-action-label">Notifications</div>
                        </a>
                    </div>
                </div>

                <!-- My Classes -->
                <div class="sidebar-section">
                    <h3><i class="fas fa-chalkboard"></i> My Classes</h3>
                    <ul class="class-list">
                        <?php foreach ($instructor_classes as $class): ?>
                            <li class="class-item <?php echo $filter_class == $class['id'] ? 'active' : ''; ?>"
                                onclick="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['class' => $class['id']])); ?>'">
                                <div class="class-name">
                                    <?php echo htmlspecialchars($class['course_title']); ?>
                                </div>
                                <div class="class-code">
                                    <?php echo htmlspecialchars($class['batch_code']); ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($instructor_classes)): ?>
                            <li style="text-align: center; padding: 1rem; color: var(--gray);">
                                <i class="fas fa-chalkboard" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block;"></i>
                                No classes assigned
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Recent Activity -->
                <?php if (!empty($recent_activity)): ?>
                    <div class="sidebar-section">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        <ul class="activity-list">
                            <?php foreach (array_slice($recent_activity, 0, 5) as $activity): ?>
                                <li class="activity-item">
                                    <span class="activity-date">
                                        <?php echo date('M j', strtotime($activity['date'])); ?>
                                    </span>
                                    <span class="activity-count">
                                        <?php echo $activity['count']; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Export Options -->
                <div class="sidebar-section">
                    <h3><i class="fas fa-download"></i> Export</h3>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <button onclick="exportAnnouncements('csv')" class="btn btn-secondary btn-sm">
                            <i class="fas fa-file-csv"></i> Export as CSV
                        </button>
                        <button onclick="exportAnnouncements('pdf')" class="btn btn-secondary btn-sm">
                            <i class="fas fa-file-pdf"></i> Export as PDF
                        </button>
                        <button onclick="printAnnouncements()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print"></i> Print List
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Export functions
        function exportAnnouncements(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            window.location.href = '<?php echo BASE_URL; ?>modules/instructor/announcements/export.php?' + params.toString();
        }

        function printAnnouncements() {
            window.print();
        }

        // Auto-refresh announcements every 5 minutes
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 300000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }

            // Ctrl + N for new announcement
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/announcements/create.php';
            }

            // Esc to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }
        });

        // Highlight new announcements (less than 24 hours old)
        document.addEventListener('DOMContentLoaded', function() {
            const announcementItems = document.querySelectorAll('.announcement-item');
            announcementItems.forEach(item => {
                const timeElement = item.querySelector('.announcement-meta span:last-child');
                if (timeElement && timeElement.textContent.includes('just now') || timeElement.textContent.includes('minute')) {
                    item.style.animation = 'pulse 2s infinite';
                    item.style.borderLeft = '4px solid var(--primary)';
                }
            });
        });

        // Add CSS animation for new announcements
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
                70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
                100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
            }
            
            .new-announcement {
                position: relative;
            }
            
            .new-announcement::before {
                content: 'NEW';
                position: absolute;
                top: 10px;
                right: 10px;
                background: var(--danger);
                color: white;
                padding: 0.25rem 0.5rem;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 600;
                z-index: 1;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>