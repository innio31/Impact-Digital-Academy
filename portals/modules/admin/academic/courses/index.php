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
$per_page = 20;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
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
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-left-color: var(--primary);
        }

        .stat-card.active {
            border-left-color: var(--success);
        }

        .stat-card.beginner {
            border-left-color: var(--info);
        }

        .stat-card.intermediate {
            border-left-color: var(--warning);
        }

        .stat-card.advanced {
            border-left-color: var(--danger);
        }

        .stat-card.duration {
            border-left-color: var(--accent);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filters-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filters-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
        }

        .filter-reset {
            color: var(--primary);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light-gray);
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }
        }

        .course-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid var(--light-gray);
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
        }

        .course-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            position: relative;
        }

        .course-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(255, 255, 255, 0.2);
        }

        .status-inactive {
            background: rgba(0, 0, 0, 0.2);
        }

        .course-program {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .course-code {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .course-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .course-content {
            padding: 1.5rem;
        }

        .course-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .course-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light);
            border-radius: 8px;
        }

        .course-stat {
            text-align: center;
        }

        .stat-icon {
            font-size: 1.1rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-value-small {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label-small {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .course-details {
            margin-bottom: 1.5rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .detail-value {
            color: var(--dark);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .level-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
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
            gap: 0.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light-gray);
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            flex: 1;
        }

        .btn-view {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .btn-edit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-activate {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-deactivate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-full {
            flex: 1;
            padding: 0.5rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding: 1rem;
            border-top: 1px solid var(--light-gray);
        }

        .page-info {
            color: var(--gray);
            font-size: 0.9rem;
            margin-right: 1rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            text-decoration: none;
            color: var(--primary);
            background: white;
            border: 1px solid var(--light-gray);
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .course-stats {
                grid-template-columns: repeat(2, 1fr);
            }
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <span>Courses</span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1>Manage Courses</h1>
            <div class="page-actions">
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Create New Course
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/" class="btn btn-secondary">
                    <i class="fas fa-chalkboard"></i> Manage Classes
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
                <div class="stat-value"><?php echo number_format($stats['avg_duration'] ?? 0, 1); ?></div>
                <div class="stat-label">Avg. Hours</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3>Filter Courses</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset Filters
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="program">Program</label>
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
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="level">Level</label>
                        <select id="level" name="level" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $level === 'all' ? 'selected' : ''; ?>>All Levels</option>
                            <option value="beginner" <?php echo $level === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                            <option value="intermediate" <?php echo $level === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                            <option value="advanced" <?php echo $level === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort" class="form-control" onchange="this.form.submit()">
                            <option value="order_number" <?php echo $sort === 'order_number' ? 'selected' : ''; ?>>Order Number</option>
                            <option value="course_code" <?php echo $sort === 'course_code' ? 'selected' : ''; ?>>Course Code</option>
                            <option value="title" <?php echo $sort === 'title' ? 'selected' : ''; ?>>Course Title</option>
                            <option value="duration_hours" <?php echo $sort === 'duration_hours' ? 'selected' : ''; ?>>Duration</option>
                            <option value="level" <?php echo $sort === 'level' ? 'selected' : ''; ?>>Level</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order">Order</label>
                        <select id="order" name="order" class="form-control" onchange="this.form.submit()">
                            <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" class="form-control"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search by code, title, or description...">
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
        <div class="courses-grid">
            <?php if (empty($courses)): ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h3>No courses found</h3>
                    <p>No courses match your current filters.</p>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="margin-top: 1rem;">
                        Reset Filters
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): ?>
                    <div class="course-card">
                        <div class="course-header">
                            <div class="course-status status-<?php echo $course['status']; ?>">
                                <?php echo ucfirst($course['status']); ?>
                            </div>
                            <div class="course-program">
                                <?php echo htmlspecialchars($course['program_code'] . ' - ' . $course['program_name']); ?>
                            </div>
                            <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                            <h3 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h3>
                        </div>

                        <div class="course-content">
                            <?php if ($course['description']): ?>
                                <div class="course-description" title="<?php echo htmlspecialchars($course['description']); ?>">
                                    <?php echo htmlspecialchars(substr($course['description'], 0, 120)); ?>
                                    <?php if (strlen($course['description']) > 120): ?>...<?php endif; ?>
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
                                    <span class="detail-label">Level:</span>
                                    <span class="detail-value">
                                        <span class="level-badge level-<?php echo $course['level']; ?>">
                                            <?php echo ucfirst($course['level']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Order:</span>
                                    <span class="detail-value">#<?php echo $course['order_number']; ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Required:</span>
                                    <span class="detail-value"><?php echo $course['is_required'] ? 'Yes' : 'No'; ?></span>
                                </div>
                            </div>

                            <div class="course-actions">
                                <a href="view.php?id=<?php echo $course['id']; ?>" class="btn-full btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="edit.php?id=<?php echo $course['id']; ?>" class="btn-full btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if ($course['status'] === 'active'): ?>
                                    <a href="?action=deactivate&id=<?php echo $course['id']; ?>"
                                        class="btn-full btn-deactivate"
                                        onclick="return confirm('Deactivate this course?')">
                                        <i class="fas fa-pause"></i> Deactivate
                                    </a>
                                <?php elseif ($course['status'] === 'inactive'): ?>
                                    <a href="?action=activate&id=<?php echo $course['id']; ?>"
                                        class="btn-full btn-activate"
                                        onclick="return confirm('Activate this course?')">
                                        <i class="fas fa-play"></i> Activate
                                    </a>
                                <?php endif; ?>
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
                    Showing <?php echo (($page - 1) * $per_page) + 1; ?>-<?php echo min($page * $per_page, $total_courses); ?> of <?php echo number_format($total_courses); ?> courses
                </div>

                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
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
                    if ($p == 1 || $p == $total_pages || ($p >= $page - 2 && $p <= $page + 2)):
                ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"
                            class="page-link <?php echo $p == $page ? 'active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php
                    elseif ($p == $start_page + 2 || $p == $end_page - 2):
                    ?>
                        <span class="page-link">...</span>
                <?php endif;
                endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                    <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                <?php endif; ?>
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
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

        // Handle URL actions
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');

        if (action && id) {
            // Actions are handled server-side, just show confirmation
            const actionMessages = {
                'activate': 'Course has been activated successfully.',
                'deactivate': 'Course has been deactivated successfully.',
                'delete': 'Course has been deleted. Redirecting...'
            };

            if (actionMessages[action]) {
                alert(actionMessages[action]);
                if (action === 'delete') {
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 1500);
                }
            }
        }
    </script>
</body>

</html>