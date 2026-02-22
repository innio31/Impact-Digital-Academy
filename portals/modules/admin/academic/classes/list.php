<?php
// modules/admin/academic/classes/list.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$status = $_GET['status'] ?? '';
$program_id = $_GET['program_id'] ?? '';
$instructor_id = $_GET['instructor_id'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$sql = "SELECT 
    cb.*,
    c.title as course_title,
    c.course_code,
    p.name as program_name,
    p.program_code,
    p.program_type,
    u.first_name as instructor_first_name,
    u.last_name as instructor_last_name,
    COUNT(DISTINCT e.id) as enrolled_students,
    COUNT(DISTINCT m.id) as total_materials,
    COUNT(DISTINCT a.id) as total_assignments
FROM class_batches cb
JOIN courses c ON cb.course_id = c.id
JOIN programs p ON c.program_id = p.id
LEFT JOIN users u ON cb.instructor_id = u.id
LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
LEFT JOIN materials m ON cb.id = m.class_id
LEFT JOIN assignments a ON cb.id = a.class_id
WHERE 1=1";

$params = [];
$types = "";

// Filter by status
if ($status && $status !== 'all') {
    $sql .= " AND cb.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Filter by program
if ($program_id) {
    $sql .= " AND p.id = ?";
    $params[] = $program_id;
    $types .= "i";
}

// Filter by instructor
if ($instructor_id) {
    $sql .= " AND cb.instructor_id = ?";
    $params[] = $instructor_id;
    $types .= "i";
}

// Filter by search term
if ($search) {
    $sql .= " AND (cb.batch_code LIKE ? OR cb.name LIKE ? OR c.title LIKE ? OR p.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

// Filter by date range
if ($date_from) {
    $sql .= " AND DATE(cb.start_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(cb.start_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Group by class and order by start date
$sql .= " GROUP BY cb.id ORDER BY cb.start_date DESC, cb.created_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
FROM class_batches";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get programs for filter dropdown
$programs_sql = "SELECT id, program_code, name FROM programs WHERE status = 'active' ORDER BY program_code";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Get instructors for filter dropdown
$instructors_sql = "SELECT id, first_name, last_name, email FROM users 
                    WHERE role = 'instructor' AND status = 'active' 
                    ORDER BY first_name, last_name";
$instructors_result = $conn->query($instructors_sql);
$instructors = $instructors_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_classes', "Viewed classes list with filters");

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } elseif (!empty($_POST['selected_classes'])) {
        $selected_ids = $_POST['selected_classes'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        $update_sql = "UPDATE class_batches SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_sql);

        $status_param = $_POST['bulk_action'];
        $all_params = array_merge([$status_param], $selected_ids);
        $types = str_repeat('i', count($selected_ids) + 1);

        $update_stmt->bind_param($types, ...$all_params);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = count($selected_ids) . ' classes updated successfully.';

            // Log each update
            foreach ($selected_ids as $class_id) {
                logActivity(
                    $_SESSION['user_id'],
                    'class_update',
                    "Class #$class_id bulk updated to $status_param",
                    'class_batches',
                    $class_id
                );
            }

            // Refresh page
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update classes.';
        }
    } else {
        $_SESSION['error'] = 'Please select at least one class.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Management - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --scheduled: #8b5cf6;
            --ongoing: #10b981;
            --completed: #3b82f6;
            --cancelled: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            transform: rotate(45deg) translate(30px, -30px);
        }

        .stat-card.scheduled {
            border-left-color: var(--scheduled);
        }

        .stat-card.ongoing {
            border-left-color: var(--ongoing);
        }

        .stat-card.completed {
            border-left-color: var(--completed);
        }

        .stat-card.cancelled {
            border-left-color: var(--cancelled);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 1.5rem;
            opacity: 0.5;
        }

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        .classes-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--dark);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-scheduled {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-onsite {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-online {
            background: #dcfce7;
            color: #166534;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .bulk-actions {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pagination {
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .class-code {
            font-weight: 600;
            color: var(--dark);
        }

        .class-name {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .date-cell {
            font-size: 0.9rem;
        }

        .date-label {
            color: #64748b;
            font-size: 0.8rem;
            display: block;
            margin-bottom: 0.25rem;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .table-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="active">
                            <i class="fas fa-chalkboard-teacher"></i> Classes</a></li>
                    <a href="schedule_builder.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                        <i class="fas fa-calendar-alt"></i> Schedule Content
                    </a>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                            <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">
                            <i class="fas fa-history"></i> Activity Logs</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php">
                            <i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Academic</a> &rsaquo;
                        Classes
                    </div>
                    <h1>Class Management</h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create New Class
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $stats['total']; ?>
                        <i class="fas fa-chalkboard-teacher stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Classes</div>
                </div>
                <div class="stat-card scheduled">
                    <div class="stat-number">
                        <?php echo $stats['scheduled']; ?>
                        <i class="fas fa-calendar-alt stat-icon"></i>
                    </div>
                    <div class="stat-label">Scheduled</div>
                </div>
                <div class="stat-card ongoing">
                    <div class="stat-number">
                        <?php echo $stats['ongoing']; ?>
                        <i class="fas fa-play-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Ongoing</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number">
                        <?php echo $stats['completed']; ?>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card cancelled">
                    <div class="stat-number">
                        <?php echo $stats['cancelled']; ?>
                        <i class="fas fa-times-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Classes</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="scheduled" <?php echo $status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="ongoing" <?php echo $status === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" class="form-control">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>"
                                    <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor</label>
                        <select name="instructor_id" class="form-control">
                            <option value="">All Instructors</option>
                            <?php foreach ($instructors as $instructor): ?>
                                <option value="<?php echo $instructor['id']; ?>"
                                    <?php echo $instructor_id == $instructor['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Batch code, class name, or course..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Classes Table -->
            <div class="classes-table">
                <div class="table-header">
                    <h3>Class Batches List</h3>
                    <div class="pagination-info">
                        Showing <?php echo count($classes); ?> classes
                    </div>
                </div>

                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <?php if (!empty($classes)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>Class Batch</th>
                                        <th>Course & Program</th>
                                        <th>Instructor</th>
                                        <th>Schedule</th>
                                        <th>Enrollment</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $class):
                                        // Calculate enrollment progress
                                        $enrollment_progress = $class['max_students'] > 0
                                            ? ($class['enrolled_students'] / $class['max_students']) * 100
                                            : 0;
                                    ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" name="selected_classes[]"
                                                    value="<?php echo $class['id']; ?>" class="class-checkbox">
                                            </td>
                                            <td>
                                                <div class="class-code">
                                                    <?php echo htmlspecialchars($class['batch_code']); ?>
                                                </div>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['name']); ?>
                                                </div>
                                                <div>
                                                    <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                                        <?php echo ucfirst($class['program_type']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($class['course_code']); ?></strong><br>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['course_title']); ?>
                                                </div>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['program_name']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($class['instructor_first_name']): ?>
                                                    <strong><?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: #64748b; font-style: italic;">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="date-cell">
                                                <span class="date-label">Starts</span>
                                                <?php echo date('M j, Y', strtotime($class['start_date'])); ?>
                                                <br>
                                                <span class="date-label">Ends</span>
                                                <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo $class['enrolled_students']; ?></strong> /
                                                    <?php echo $class['max_students']; ?> students
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo min($enrollment_progress, 100); ?>%;"></div>
                                                </div>
                                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.25rem;">
                                                    <?php echo $class['total_materials']; ?> materials • <?php echo $class['total_assignments']; ?> assignments
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $class['status']; ?>">
                                                    <?php echo ucfirst($class['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions">
                                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class['id']; ?>"
                                                    class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class['id']; ?>"
                                                    class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class['id']; ?>/home.php"
                                                    class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-chalkboard-teacher"></i> Class View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <select name="bulk_action" class="form-control" style="width: 200px;">
                                <option value="">Bulk Actions</option>
                                <option value="scheduled">Mark as Scheduled</option>
                                <option value="ongoing">Mark as Ongoing</option>
                                <option value="completed">Mark as Completed</option>
                                <option value="cancelled">Mark as Cancelled</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play"></i> Apply
                            </button>
                            <span style="color: #64748b; font-size: 0.9rem;">
                                <span id="selectedCount">0</span> classes selected
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h3>No Classes Found</h3>
                            <p>There are no classes matching your filters.</p>
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Create New Class
                            </a>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo count($classes); ?> of <?php echo count($classes); ?> classes
                    </div>
                    <div class="page-numbers">
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <a href="#" class="page-link">Next</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Select all checkbox functionality
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.class-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.class-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.class-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk form submission confirmation
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const action = this.bulk_action.value;
            const selectedCount = document.querySelectorAll('.class-checkbox:checked').length;

            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one class.');
                return false;
            }

            const actionText = action === 'scheduled' ? 'mark as scheduled' :
                action === 'ongoing' ? 'mark as ongoing' :
                action === 'completed' ? 'mark as completed' :
                'mark as cancelled';

            if (!confirm(`Are you sure you want to ${actionText} ${selectedCount} class(es)?`)) {
                e.preventDefault();
                return false;
            }
        });

        // Update selected count on page load
        document.addEventListener('DOMContentLoaded', updateSelectedCount);

        // Quick search functionality
        const searchInput = document.querySelector('input[name="search"]');
        let searchTimer;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    this.form.submit();
                }
            }, 500);
        });

        // Calculate and update progress bars
        document.querySelectorAll('.progress-bar').forEach(bar => {
            const fill = bar.querySelector('.progress-fill');
            const width = fill.style.width;
            fill.style.width = '0%';
            setTimeout(() => {
                fill.style.width = width;
            }, 100);
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>