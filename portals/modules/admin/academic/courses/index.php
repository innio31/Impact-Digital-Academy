<?php
// modules/admin/academic/courses/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get filter parameters
$program_id = $_GET['program'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$level = $_GET['level'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'order_number';
$order = $_GET['order'] ?? 'asc';

// Validate sort and order
$valid_sorts = ['id', 'course_code', 'title', 'duration_hours', 'level', 'order_number', 'status', 'created_at'];
$valid_orders = ['asc', 'desc'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'order_number';
$order = in_array($order, $valid_orders) ? $order : 'asc';

// Build query with filters
$query = "SELECT c.*, 
                 p.name as program_name,
                 p.program_code,
                 COUNT(DISTINCT cb.id) as class_count,
                 COUNT(DISTINCT e.id) as student_count
          FROM courses c
          JOIN programs p ON c.program_id = p.id
          LEFT JOIN class_batches cb ON c.id = cb.course_id AND cb.status = 'ongoing'
          LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
          WHERE 1=1";

$params = [];
$types = '';

// Apply program filter
if ($program_id !== 'all') {
    $query .= " AND c.program_id = ?";
    $params[] = $program_id;
    $types .= 'i';
}

// Apply status filter
if ($status !== 'all') {
    $query .= " AND c.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Apply level filter
if ($level !== 'all') {
    $query .= " AND c.level = ?";
    $params[] = $level;
    $types .= 's';
}

// Apply search filter
if ($search) {
    $query .= " AND (c.course_code LIKE ? OR c.title LIKE ? OR c.description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

// Group by and order
$query .= " GROUP BY c.id ORDER BY c.$sort $order";

// Get total count for pagination
$count_query = str_replace(
    'c.*, p.name as program_name, p.program_code, COUNT(DISTINCT cb.id) as class_count, COUNT(DISTINCT e.id) as student_count',
    'COUNT(DISTINCT c.id) as total',
    $query
);

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_courses = $count_result->fetch_assoc()['total'] ?? 0;

// Pagination
$per_page = 12; // Reduced from 20 for better grid display
$total_pages = ceil($total_courses / $per_page);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute main query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all programs for filter dropdown
$programs_query = "SELECT id, program_code, name FROM programs WHERE status = 'active' ORDER BY program_code";
$programs_result = $conn->query($programs_query);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                AVG(duration_hours) as avg_duration,
                SUM(CASE WHEN level = 'beginner' THEN 1 ELSE 0 END) as beginner,
                SUM(CASE WHEN level = 'intermediate' THEN 1 ELSE 0 END) as intermediate,
                SUM(CASE WHEN level = 'advanced' THEN 1 ELSE 0 END) as advanced
                FROM courses";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity('view_courses', "Viewed courses list with filters");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manage Courses - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --gray-lighter: #e2e8f0;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Page Header */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            background: var(--white);
            padding: 1.25rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .page-title {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .page-title h1 {
            font-size: 1.8rem;
            color: var(--dark);
            font-weight: 700;
            line-height: 1.2;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            color: var(--white);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .stat-card.total {
            border-left: 4px solid var(--primary);
        }

        .stat-card.active {
            border-left: 4px solid var(--success);
        }

        .stat-card.beginner {
            border-left: 4px solid var(--info);
        }

        .stat-card.intermediate {
            border-left: 4px solid var(--warning);
        }

        .stat-card.advanced {
            border-left: 4px solid var(--danger);
        }

        .stat-card.duration {
            border-left: 4px solid var(--secondary);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            background: linear-gradient(135deg, var(--dark), var(--gray));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filters Card */
        .filters-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .filter-reset {
            color: var(--primary);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .filter-reset:hover {
            background: rgba(37, 99, 235, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .filter-group {
            margin-bottom: 0.5rem;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-lighter);
            border-radius: 12px;
            font-size: 0.9rem;
            background: var(--white);
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray-lighter);
        }

        /* Courses Grid */
        .courses-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .course-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid var(--gray-lighter);
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-color: var(--primary-light);
        }

        .course-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            position: relative;
        }

        .course-status {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
        }

        .status-active {
            background: rgba(16, 185, 129, 0.3);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.3);
        }

        .course-program {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .course-program i {
            font-size: 0.7rem;
        }

        .course-code {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            display: inline-block;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
        }

        .course-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1.3;
            word-break: break-word;
        }

        .course-content {
            padding: 1.25rem;
        }

        .course-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 1.25rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .course-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--light), var(--white));
            border-radius: 16px;
            border: 2px solid var(--gray-lighter);
        }

        .course-stat {
            text-align: center;
        }

        .stat-icon {
            font-size: 1rem;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-value-small {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.1rem;
        }

        .stat-label-small {
            font-size: 0.6rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .course-details {
            margin-bottom: 1.25rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.6rem 0;
            border-bottom: 1px dashed var(--gray-lighter);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--gray);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-value {
            color: var(--dark);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .level-badge {
            padding: 0.2rem 0.8rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .level-beginner {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .level-intermediate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .level-advanced {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .course-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray-lighter);
        }

        .action-row {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            flex: 1;
            padding: 0.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
        }

        .btn-view {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .btn-edit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-activate {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-deactivate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }

        .btn-icon i {
            font-size: 0.9rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            border: 2px solid transparent;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray);
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .page-info {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-link {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            text-decoration: none;
            color: var(--primary);
            background: var(--white);
            border: 2px solid var(--gray-lighter);
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Tablet Breakpoint */
        @media (min-width: 640px) {
            .container {
                padding: 1.5rem;
            }

            .page-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }

            .btn {
                width: auto;
            }

            .stats-cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .filters-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .courses-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }

            .pagination {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        /* Desktop Breakpoint */
        @media (min-width: 1024px) {
            .stats-cards {
                grid-template-columns: repeat(6, 1fr);
            }

            .filters-grid {
                grid-template-columns: repeat(3, 1fr) auto;
            }

            .courses-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
            }

            .course-actions {
                flex-direction: row;
            }

            .btn-icon {
                padding: 0.5rem 0.75rem;
            }
        }

        /* Large Desktop Breakpoint */
        @media (min-width: 1280px) {
            .courses-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .course-card,
            .page-link,
            .filter-reset {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .course-card:active {
                transform: scale(0.98);
            }
        }

        /* Loading Animation */
        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }

            100% {
                background-position: 1000px 0;
            }
        }

        .loading {
            animation: shimmer 2s infinite;
            background: linear-gradient(to right, var(--gray-lighter) 8%, var(--light) 18%, var(--gray-lighter) 33%);
            background-size: 1000px 100%;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <span>Courses</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>📚 Manage Courses</h1>
                <div style="color: var(--gray); font-size: 0.9rem;">
                    <?php echo number_format($total_courses); ?> total courses
                </div>
            </div>
            <div class="page-actions">
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> New Course
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/" class="btn btn-secondary">
                    <i class="fas fa-chalkboard"></i> Classes
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total" onclick="window.location.href='?status=all'">
                <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total Courses</div>
            </div>
            <div class="stat-card active" onclick="window.location.href='?status=active'">
                <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card beginner" onclick="window.location.href='?level=beginner'">
                <div class="stat-value"><?php echo number_format($stats['beginner'] ?? 0); ?></div>
                <div class="stat-label">Beginner</div>
            </div>
            <div class="stat-card intermediate" onclick="window.location.href='?level=intermediate'">
                <div class="stat-value"><?php echo number_format($stats['intermediate'] ?? 0); ?></div>
                <div class="stat-label">Intermediate</div>
            </div>
            <div class="stat-card advanced" onclick="window.location.href='?level=advanced'">
                <div class="stat-value"><?php echo number_format($stats['advanced'] ?? 0); ?></div>
                <div class="stat-label">Advanced</div>
            </div>
            <div class="stat-card duration">
                <div class="stat-value"><?php echo number_format($stats['avg_duration'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Avg. Duration</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3><i class="fas fa-filter" style="margin-right: 0.5rem;"></i> Filter Courses</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="program">
                            <i class="fas fa-graduation-cap" style="margin-right: 0.3rem;"></i> Program
                        </label>
                        <select id="program" name="program" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $program_id === 'all' ? 'selected' : ''; ?>>All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>"
                                    <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">
                            <i class="fas fa-circle" style="margin-right: 0.3rem;"></i> Status
                        </label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="level">
                            <i class="fas fa-signal" style="margin-right: 0.3rem;"></i> Level
                        </label>
                        <select id="level" name="level" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $level === 'all' ? 'selected' : ''; ?>>All Levels</option>
                            <option value="beginner" <?php echo $level === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                            <option value="intermediate" <?php echo $level === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                            <option value="advanced" <?php echo $level === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">
                            <i class="fas fa-search" style="margin-right: 0.3rem;"></i> Search
                        </label>
                        <input type="text" id="search" name="search" class="form-control"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search courses...">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Courses Grid -->
        <div class="courses-grid" id="coursesGrid">
            <?php if (empty($courses)): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>No courses found</h3>
                    <p>No courses match your current filters.</p>
                    <button type="button" class="btn btn-primary" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): ?>
                    <div class="course-card" data-course-id="<?php echo $course['id']; ?>">
                        <div class="course-header">
                            <span class="course-status status-<?php echo $course['status']; ?>">
                                <?php echo ucfirst($course['status']); ?>
                            </span>
                            <div class="course-program">
                                <i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($course['program_code']); ?>
                            </div>
                            <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                            <h3 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h3>
                        </div>

                        <div class="course-content">
                            <?php if ($course['description']): ?>
                                <div class="course-description">
                                    <?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>
                                    <?php if (strlen($course['description']) > 100): ?>...<?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="course-stats">
                                <div class="course-stat">
                                    <div class="stat-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-value-small"><?php echo $course['duration_hours']; ?></div>
                                    <div class="stat-label-small">Hours</div>
                                </div>
                                <div class="course-stat">
                                    <div class="stat-icon">
                                        <i class="fas fa-chalkboard"></i>
                                    </div>
                                    <div class="stat-value-small"><?php echo $course['class_count'] ?: '0'; ?></div>
                                    <div class="stat-label-small">Classes</div>
                                </div>
                                <div class="course-stat">
                                    <div class="stat-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-value-small"><?php echo $course['student_count'] ?: '0'; ?></div>
                                    <div class="stat-label-small">Students</div>
                                </div>
                            </div>

                            <div class="course-details">
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <i class="fas fa-tag"></i> Level
                                    </span>
                                    <span class="detail-value">
                                        <span class="level-badge level-<?php echo $course['level']; ?>">
                                            <?php echo ucfirst($course['level']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <i class="fas fa-sort-numeric-up-alt"></i> Order
                                    </span>
                                    <span class="detail-value">#<?php echo $course['order_number']; ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <i class="fas fa-check-circle"></i> Required
                                    </span>
                                    <span class="detail-value">
                                        <?php echo $course['is_required'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </div>
                            </div>

                            <div class="course-actions">
                                <div class="action-row">
                                    <a href="view.php?id=<?php echo $course['id']; ?>" class="btn-icon btn-view" title="View course details">
                                        <i class="fas fa-eye"></i>
                                        <span class="action-text">View</span>
                                    </a>
                                    <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn-icon btn-edit" title="Edit course">
                                        <i class="fas fa-edit"></i>
                                        <span class="action-text">Edit</span>
                                    </a>
                                </div>
                                <div class="action-row">
                                    <?php if ($course['status'] === 'active'): ?>
                                        <a href="?action=deactivate&id=<?php echo $course['id']; ?>"
                                            class="btn-icon btn-deactivate"
                                            onclick="return confirm('Deactivate this course?')"
                                            title="Deactivate course">
                                            <i class="fas fa-pause-circle"></i>
                                            <span class="action-text">Deactivate</span>
                                        </a>
                                    <?php elseif ($course['status'] === 'inactive'): ?>
                                        <a href="?action=activate&id=<?php echo $course['id']; ?>"
                                            class="btn-icon btn-activate"
                                            onclick="return confirm('Activate this course?')"
                                            title="Activate course">
                                            <i class="fas fa-play-circle"></i>
                                            <span class="action-text">Activate</span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="?action=delete&id=<?php echo $course['id']; ?>"
                                        class="btn-icon btn-delete"
                                        onclick="return confirm('Are you sure you want to delete this course? This action cannot be undone.')"
                                        title="Delete course">
                                        <i class="fas fa-trash-alt"></i>
                                        <span class="action-text">Delete</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div class="page-info">
                    Showing <strong><?php echo (($page - 1) * $per_page) + 1; ?></strong> -
                    <strong><?php echo min($page * $per_page, $total_courses); ?></strong>
                    of <strong><?php echo number_format($total_courses); ?></strong> courses
                </div>
                <div class="page-numbers">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link" title="First page">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link" title="Previous page">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    for ($p = $start_page; $p <= $end_page; $p++):
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"
                            class="page-link <?php echo $p == $page ? 'active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link" title="Next page">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link" title="Last page">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Reset filters
        function resetFilters() {
            window.location.href = 'index.php';
        }

        // Auto-submit filters on search after delay
        let searchTimeout;
        const searchInput = document.getElementById('search');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            });
        }

        // Handle URL actions with better UX
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');

        if (action && id) {
            const actionMessages = {
                'activate': '✅ Course has been activated successfully.',
                'deactivate': '⏸️ Course has been deactivated successfully.',
                'delete': '🗑️ Course has been deleted successfully.'
            };

            if (actionMessages[action]) {
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success';
                alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${actionMessages[action]}`;

                const container = document.querySelector('.container');
                container.insertBefore(alertDiv, container.firstChild.nextSibling.nextSibling);

                // Remove message after 3 seconds
                setTimeout(() => {
                    alertDiv.remove();

                    // Clean up URL
                    const newUrl = window.location.pathname;
                    window.history.replaceState({}, document.title, newUrl);
                }, 3000);
            }
        }

        // Add loading animation for filter submissions
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;

                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying...';
                submitBtn.disabled = true;

                // Re-enable after 2 seconds (just in case)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 2000);
            });
        }

        // Add touch-friendly hover states
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .course-card, .page-link').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Animate stat cards on load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animation = `fadeInUp 0.5s ease ${index * 0.1}s both`;
            });

            // Add intersection observer for lazy loading cards
            if ('IntersectionObserver' in window) {
                const cards = document.querySelectorAll('.course-card');
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }
                    });
                });

                cards.forEach(card => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.5s ease';
                    observer.observe(card);
                });
            }
        });

        // Add smooth scrolling to top when changing pages
        document.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!this.classList.contains('disabled')) {
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Confirm delete with better UX
        document.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();

                if (confirm('⚠️ Are you absolutely sure?\n\nThis will permanently delete this course and all associated data. This action cannot be undone!')) {
                    window.location.href = this.href;
                }
            });
        });

        // Responsive action text
        function updateActionText() {
            const isMobile = window.innerWidth < 640;
            document.querySelectorAll('.action-text').forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });
        }

        window.addEventListener('load', updateActionText);
        window.addEventListener('resize', updateActionText);
    </script>

    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</body>

</html>