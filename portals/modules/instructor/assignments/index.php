<?php
// modules/instructor/assignments/index.php

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

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];

// Get filter parameters
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for assignments
$query = "SELECT a.*, cb.batch_code, c.title as course_title, c.course_code,
                 COUNT(DISTINCT s.id) as submission_count,
                 COUNT(DISTINCT CASE WHEN s.status = 'submitted' AND s.grade IS NULL THEN s.id END) as pending_count,
                 COUNT(DISTINCT CASE WHEN s.grade IS NOT NULL THEN s.id END) as graded_count
          FROM assignments a 
          JOIN class_batches cb ON a.class_id = cb.id 
          JOIN courses c ON cb.course_id = c.id 
          LEFT JOIN assignment_submissions s ON a.id = s.assignment_id
          WHERE a.instructor_id = ?";

$params = ["i"];
$param_values = [$instructor_id];

// Add filters
if (!empty($filter_class)) {
    $query .= " AND a.class_id = ?";
    $params[0] .= "i";
    $param_values[] = $filter_class;
}

if (!empty($filter_status)) {
    if ($filter_status === 'pending') {
        $query .= " AND a.due_date <= NOW() AND EXISTS (
            SELECT 1 FROM assignment_submissions s2 
            WHERE s2.assignment_id = a.id AND s2.status = 'submitted' AND s2.grade IS NULL
        )";
    } elseif ($filter_status === 'upcoming') {
        $query .= " AND a.due_date > NOW()";
    } elseif ($filter_status === 'published') {
        $query .= " AND a.is_published = 1";
    } elseif ($filter_status === 'draft') {
        $query .= " AND a.is_published = 0";
    }
}

if (!empty($filter_search)) {
    $query .= " AND (a.title LIKE ? OR a.description LIKE ? OR c.title LIKE ?)";
    $params[0] .= "sss";
    $search_term = "%{$filter_search}%";
    $param_values[] = $search_term;
    $param_values[] = $search_term;
    $param_values[] = $search_term;
}

$query .= " GROUP BY a.id ORDER BY a.due_date DESC";

// Execute query
$assignments = [];
$stmt = $conn->prepare($query);

if ($stmt) {
    // Bind parameters correctly
    $types = $params[0];
    $stmt->bind_param($types, ...$param_values);

    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Query preparation failed: " . $conn->error);
    $assignments = [];
}

// Get instructor's classes for filter dropdown
$sql_classes = "SELECT cb.id, cb.batch_code, cb.name, c.title as course_title
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id 
                WHERE cb.instructor_id = ? AND cb.status IN ('ongoing', 'scheduled')
                ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql_classes);
if ($stmt) {
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $classes_result = $stmt->get_result();
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $classes = [];
}

// Close database connection
$conn->close();

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assignments - Instructor Dashboard</title>
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
            min-height: 100vh;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e40af;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .header-user {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .user-name {
            font-weight: 600;
        }

        .user-role {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            margin-left: 2rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
        }

        .notification-bell i {
            font-size: 1.2rem;
            color: white;
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        /* Breadcrumb */
        .breadcrumb {
            background: var(--light);
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .breadcrumb-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .breadcrumb-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .breadcrumb-link {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb-separator {
            color: var(--gray);
        }

        .breadcrumb-current {
            color: var(--dark);
            font-weight: 500;
        }

        /* Main Container */
        .assignments-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 160px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }

        .page-title h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 28px;
        }

        .page-title p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }

        .btn-primary {
            background: #3498db;
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.2);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #34495e;
            border: 1px solid #bdc3c7;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 13px;
        }

        .filters-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        .filter-control {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .btn-filter {
            padding: 10px 20px;
        }

        .btn-reset {
            background: transparent;
            color: #7f8c8d;
            border: 1px solid #ddd;
        }

        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .assignment-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #eaeaea;
        }

        .assignment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .assignment-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: relative;
        }

        .assignment-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-published {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .status-draft {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .status-overdue {
            background: rgba(231, 76, 60, 0.2);
            color: #fff;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .assignment-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 5px 0;
            line-height: 1.3;
        }

        .assignment-course {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }

        .assignment-body {
            padding: 20px;
        }

        .assignment-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
            font-size: 14px;
        }

        .info-item i {
            width: 16px;
            color: #3498db;
        }

        .assignment-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .meta-item {
            text-align: center;
        }

        .meta-value {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            display: block;
            line-height: 1;
            margin-bottom: 5px;
        }

        .meta-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .assignment-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
            flex: 1;
            padding: 8px 12px;
            text-align: center;
            font-size: 13px;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.3s ease;
            min-width: 80px;
        }

        .btn-view {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }

        .btn-view:hover {
            background: #bbdefb;
        }

        .btn-submissions {
            background: #f3e5f5;
            color: #7b1fa2;
            border: 1px solid #e1bee7;
        }

        .btn-submissions:hover {
            background: #e1bee7;
        }

        .btn-grade {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid #ffe0b2;
        }

        .btn-grade:hover {
            background: #ffe0b2;
        }

        .btn-edit {
            background: #e8f5e9;
            color: #388e3c;
            border: 1px solid #c8e6c9;
        }

        .btn-edit:hover {
            background: #c8e6c9;
        }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 60px;
            color: #bdc3c7;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #7f8c8d;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #95a5a6;
            max-width: 400px;
            margin: 0 auto 20px;
        }

        /* Footer Styles */
        .footer {
            background: #1e293b;
            color: white;
            padding: 2rem;
            margin-top: 3rem;
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--accent);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .assignments-grid {
                grid-template-columns: 1fr;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .action-buttons {
                width: 100%;
                justify-content: flex-start;
            }

            .header-container {
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links {
                margin-left: 0;
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 40px;
            gap: 5px;
        }

        .page-link {
            padding: 8px 15px;
            border: 1px solid #ddd;
            background: white;
            color: #3498db;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: #f8f9fa;
        }

        .page-link.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        /* Quick stats */
        .quick-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-details h4 {
            font-size: 1.5rem;
            margin: 0;
            color: var(--dark);
        }

        .stat-details p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--gray);
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-container">
            <div class="logo-section">
                <div class="logo">IDA</div>
                <div class="logo-text">Impact Digital Academy</div>
                <div class="nav-links">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-link">
                        <i class="fas fa-chalkboard"></i> Classes
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="nav-link active">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="nav-link">
                        <i class="fas fa-inbox"></i> Submissions
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                </div>
            </div>

            <div class="header-user">
                <div class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-count">3</span>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($instructor_name); ?></div>
                        <div class="user-role">Instructor</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <div class="breadcrumb-container">
            <div class="breadcrumb-links">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="breadcrumb-link">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">
                    <i class="fas fa-tasks"></i> Manage Assignments
                </span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="assignments-container">
        <div class="page-header">
            <div class="page-title">
                <h1>Manage Assignments</h1>
                <p>Create, edit, and grade assignments across your classes</p>
            </div>
            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Create Assignment
                </a>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <?php
            // Calculate quick stats
            $total_assignments = count($assignments);
            $published_assignments = array_filter($assignments, fn($a) => $a['is_published'] == 1);
            $pending_grading = array_sum(array_column($assignments, 'pending_count'));
            $total_submissions = array_sum(array_column($assignments, 'submission_count'));
            ?>
            <div class="stat-badge">
                <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--primary);">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-details">
                    <h4><?php echo $total_assignments; ?></h4>
                    <p>Total Assignments</p>
                </div>
            </div>
            <div class="stat-badge">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h4><?php echo count($published_assignments); ?></h4>
                    <p>Published</p>
                </div>
            </div>
            <div class="stat-badge">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-details">
                    <h4><?php echo $pending_grading; ?></h4>
                    <p>Pending Grading</p>
                </div>
            </div>
            <div class="stat-badge">
                <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="stat-details">
                    <h4><?php echo $total_submissions; ?></h4>
                    <p>Total Submissions</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label for="class_id">Filter by Class</label>
                    <select name="class_id" id="class_id" class="filter-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"
                                <?php echo ($filter_class == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status">Filter by Status</label>
                    <select name="status" id="status" class="filter-control">
                        <option value="">All Status</option>
                        <option value="published" <?php echo ($filter_status == 'published') ? 'selected' : ''; ?>>Published</option>
                        <option value="draft" <?php echo ($filter_status == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending Grading</option>
                        <option value="upcoming" <?php echo ($filter_status == 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Search Assignments</label>
                    <input type="text" name="search" id="search" class="filter-control"
                        placeholder="Search by title, course..." value="<?php echo htmlspecialchars($filter_search); ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-filter">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" class="btn btn-reset">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Assignments Grid -->
        <?php if (empty($assignments)): ?>
            <div class="empty-state">
                <i class="fas fa-tasks"></i>
                <h3>No Assignments Found</h3>
                <p>You haven't created any assignments yet. Get started by creating your first assignment.</p>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Create Your First Assignment
                </a>
            </div>
        <?php else: ?>
            <div class="assignments-grid">
                <?php foreach ($assignments as $assignment):
                    $is_past_due = strtotime($assignment['due_date']) < time() && $assignment['is_published'] == 1;
                    $status_class = $assignment['is_published'] ? 'published' : 'draft';
                    if ($is_past_due && $assignment['pending_count'] > 0) {
                        $status_class = 'overdue';
                    }
                ?>
                    <div class="assignment-card">
                        <div class="assignment-header">
                            <span class="assignment-status status-<?php echo $status_class; ?>">
                                <?php
                                if ($status_class == 'overdue') {
                                    echo 'Overdue';
                                } else {
                                    echo $assignment['is_published'] ? 'Published' : 'Draft';
                                }
                                ?>
                            </span>
                            <h3 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                            <p class="assignment-course">
                                <?php echo htmlspecialchars($assignment['course_title'] . ' - ' . $assignment['batch_code']); ?>
                            </p>
                        </div>

                        <div class="assignment-body">
                            <div class="assignment-info">
                                <div class="info-item">
                                    <i class="far fa-calendar-alt"></i>
                                    <span>
                                        <strong>Due:</strong>
                                        <?php echo date('F j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                    </span>
                                </div>

                                <?php if (!empty($assignment['description'])): ?>
                                    <div class="info-item">
                                        <i class="far fa-file-alt"></i>
                                        <span><?php echo truncate_text($assignment['description'], 100); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="info-item">
                                    <i class="fas fa-graduation-cap"></i>
                                    <span>
                                        <strong>Points:</strong> <?php echo $assignment['total_points']; ?>
                                    </span>
                                </div>
                            </div>

                            <div class="assignment-meta">
                                <div class="meta-item">
                                    <span class="meta-value"><?php echo $assignment['submission_count']; ?></span>
                                    <span class="meta-label">Submissions</span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-value"><?php echo $assignment['pending_count']; ?></span>
                                    <span class="meta-label">Pending</span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-value"><?php echo $assignment['graded_count']; ?></span>
                                    <span class="meta-label">Graded</span>
                                </div>
                            </div>

                            <div class="assignment-actions">
                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment['id']; ?>"
                                    class="btn-action btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment['id']; ?>"
                                    class="btn-action btn-submissions">
                                    <i class="fas fa-inbox"></i> Submissions
                                </a>
                                <?php if ($assignment['pending_count'] > 0): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment['id']; ?>"
                                        class="btn-action btn-grade">
                                        <i class="fas fa-check-circle"></i> Grade
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/edit.php?id=<?php echo $assignment['id']; ?>"
                                    class="btn-action btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination (if implemented) -->
            <div class="pagination">
                <a href="#" class="page-link"><i class="fas fa-chevron-left"></i></a>
                <a href="#" class="page-link active">1</a>
                <a href="#" class="page-link">2</a>
                <a href="#" class="page-link">3</a>
                <a href="#" class="page-link"><i class="fas fa-chevron-right"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Impact Digital Academy</h3>
                    <p>Empowering instructors with comprehensive teaching tools and management systems.</p>
                </div>
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">Dashboard</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/classes/">My Classes</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/assignments/">Assignments</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/students/">Students</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Resources</h3>
                    <ul class="footer-links">
                        <li><a href="<?php echo BASE_URL; ?>modules/shared/help/">Help Center</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/settings.php">Settings</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/instructor/profile/">My Profile</a></li>
                        <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.</p>
                <p>Instructor Panel v1.0</p>
            </div>
        </div>
    </footer>

    <script>
        // Auto-submit form when filter changes
        document.getElementById('class_id').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });

        // Add confirmation for deleting assignments
        document.addEventListener('DOMContentLoaded', function() {
            const deleteLinks = document.querySelectorAll('.btn-delete');
            deleteLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to delete this assignment? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            });

            // Notification bell click
            document.querySelector('.notification-bell').addEventListener('click', function() {
                alert('Notifications feature coming soon!');
            });
        });

        // Quick stats update
        function updateAssignmentStats() {
            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/stats.php')
                .then(response => response.json())
                .then(data => {
                    // Update stats if needed
                })
                .catch(error => console.error('Error fetching stats:', error));
        }

        // Refresh stats every 30 seconds
        setInterval(updateAssignmentStats, 30000);

        // Mobile menu toggle
        function toggleMobileMenu() {
            const navLinks = document.querySelector('.nav-links');
            navLinks.classList.toggle('show');
        }

        // Auto-hide notifications after 5 seconds
        setTimeout(() => {
            const notificationBell = document.querySelector('.notification-bell');
            if (notificationBell) {
                notificationBell.style.opacity = '0.7';
            }
        }, 5000);
    </script>
</body>

</html>