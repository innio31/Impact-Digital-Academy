<?php
// modules/instructor/assignments/pending.php

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

// Get filter parameters for pending submissions
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for PENDING submissions only
$query = "SELECT s.*, a.title as assignment_title, a.due_date, a.total_points,
                 u.id as student_id, u.first_name, u.last_name, u.email,
                 cb.batch_code, c.title as course_title
          FROM assignment_submissions s 
          JOIN assignments a ON s.assignment_id = a.id 
          JOIN users u ON s.student_id = u.id 
          JOIN class_batches cb ON a.class_id = cb.id 
          JOIN courses c ON cb.course_id = c.id 
          WHERE a.instructor_id = ? AND s.grade IS NULL AND s.status = 'submitted'";

$params = ["i", $instructor_id];
$param_values = [$instructor_id];

// Add filters
if ($assignment_id > 0) {
    $query .= " AND s.assignment_id = ?";
    $params[0] .= "i";
    $param_values[] = $assignment_id;
}

if (!empty($filter_class)) {
    $query .= " AND a.class_id = ?";
    $params[0] .= "i";
    $param_values[] = $filter_class;
}

if (!empty($filter_search)) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR a.title LIKE ?)";
    $params[0] .= "ssss";
    $search_term = "%{$filter_search}%";
    $param_values = array_merge($param_values, [$search_term, $search_term, $search_term, $search_term]);
}

$query .= " ORDER BY s.submitted_at DESC";

// Execute query
$stmt = $conn->prepare($query);
if ($stmt) {
    if ($param_values) {
        $stmt->bind_param(...array_merge($params, $param_values));
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $submissions = [];
}

// Get instructor's assignments for filter dropdown
$sql_assignments = "SELECT a.id, a.title, cb.batch_code, c.title as course_title
                    FROM assignments a 
                    JOIN class_batches cb ON a.class_id = cb.id 
                    JOIN courses c ON cb.course_id = c.id 
                    WHERE a.instructor_id = ? 
                    ORDER BY a.due_date DESC";
$stmt = $conn->prepare($sql_assignments);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$assignments_result = $stmt->get_result();
$assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get instructor's classes for filter dropdown
$sql_classes = "SELECT cb.id, cb.batch_code, c.title as course_title
                FROM class_batches cb 
                JOIN courses c ON cb.course_id = c.id 
                WHERE cb.instructor_id = ? AND cb.status IN ('ongoing', 'scheduled')
                ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql_classes);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics for pending submissions
$total_pending = count($submissions);
$late_pending = count(array_filter($submissions, function ($s) {
    return $s['late_submission'] == 1;
}));

$conn->close();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pending_submissions_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // CSV header
    fputcsv($output, [
        'Student Name',
        'Email',
        'Assignment',
        'Course',
        'Batch',
        'Submitted At',
        'Status',
        'Late Submission',
        'Due Date'
    ]);

    // CSV data
    foreach ($submissions as $submission) {
        fputcsv($output, [
            $submission['first_name'] . ' ' . $submission['last_name'],
            $submission['email'],
            $submission['assignment_title'],
            $submission['course_title'],
            $submission['batch_code'],
            $submission['submitted_at'],
            $submission['status'],
            $submission['late_submission'] ? 'Yes' : 'No',
            $submission['due_date']
        ]);
    }

    fclose($output);
    exit();
}

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Grading - Instructor Dashboard</title>
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
            max-width: 1600px;
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
            max-width: 1600px;
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
        .pending-container {
            padding: 20px;
            max-width: 1600px;
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
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #34495e;
            border: 1px solid #bdc3c7;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
        }

        .btn-export {
            background: #9b59b6;
            color: white;
        }

        .btn-export:hover {
            background: #8e44ad;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.pending {
            border-left-color: #f39c12;
        }

        .stat-card.late {
            border-left-color: #e74c3c;
        }

        .stat-card.overdue {
            border-left-color: #e74c3c;
        }

        .stat-card.urgent {
            border-left-color: #e74c3c;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            display: block;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 500;
        }

        .stat-detail {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 5px;
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

        .btn-reset:hover {
            background: #f8f9fa;
        }

        .pending-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .pending-table th {
            text-align: left;
            padding: 15px 20px;
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
        }

        .pending-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .pending-table tr:hover {
            background: #f8f9fa;
        }

        .pending-table tr:last-child td {
            border-bottom: none;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            flex-shrink: 0;
        }

        .student-details h4 {
            margin: 0 0 3px 0;
            color: #2c3e50;
            font-size: 14px;
        }

        .student-details p {
            margin: 0;
            color: #7f8c8d;
            font-size: 12px;
        }

        .assignment-info h4 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 14px;
        }

        .assignment-info p {
            margin: 0;
            color: #7f8c8d;
            font-size: 12px;
        }

        .submission-date {
            font-size: 13px;
            color: #555;
        }

        .submission-date.late {
            color: #e74c3c;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-late {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons-small {
            display: flex;
            gap: 5px;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-view {
            background: #e3f2fd;
            color: #1976d2;
        }

        .btn-view:hover {
            background: #bbdefb;
        }

        .btn-grade {
            background: #fff3e0;
            color: #f57c00;
        }

        .btn-grade:hover {
            background: #ffe0b2;
        }

        .btn-download {
            background: #e8f5e9;
            color: #388e3c;
        }

        .btn-download:hover {
            background: #c8e6c9;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
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

        .footer {
            background: #1e293b;
            color: white;
            padding: 2rem;
            margin-top: 3rem;
        }

        .footer-container {
            max-width: 1600px;
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

        /* Bulk Actions */
        .bulk-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-all {
            margin-right: 5px;
        }

        .urgent-badge {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
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

            .pending-table {
                display: block;
                overflow-x: auto;
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

            .bulk-actions {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Overdue styling */
        .overdue-row {
            background: #fff5f5;
        }

        .overdue-row:hover {
            background: #ffeaea;
        }

        .due-soon {
            background: #fffbf0;
        }

        .due-soon:hover {
            background: #fff5e6;
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
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="nav-link">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="nav-link">
                        <i class="fas fa-inbox"></i> Submissions
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="nav-link active">
                        <i class="fas fa-clock"></i> Pending
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/students/list.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                </div>
            </div>

            <div class="header-user">
                <div class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-count"><?php echo $total_pending > 0 ? $total_pending : ''; ?></span>
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
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="breadcrumb-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">
                    <i class="fas fa-clock"></i> Pending Grading
                </span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="pending-container">
        <div class="page-header">
            <div class="page-title">
                <h1>Pending Grading</h1>
                <p>Review and grade pending student submissions</p>
            </div>
            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Assignments
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="btn btn-primary">
                    <i class="fas fa-inbox"></i> All Submissions
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-export">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card pending">
                <span class="stat-value"><?php echo $total_pending; ?></span>
                <span class="stat-label">Pending Submissions</span>
                <span class="stat-detail">Require grading attention</span>
            </div>

            <div class="stat-card late">
                <span class="stat-value"><?php echo $late_pending; ?></span>
                <span class="stat-label">Late Submissions</span>
                <span class="stat-detail">
                    <?php echo $total_pending > 0 ? round(($late_pending / $total_pending) * 100) : 0; ?>% of pending
                </span>
            </div>

            <?php
            // Calculate overdue submissions (submitted more than 3 days ago)
            $overdue_count = 0;
            $due_soon_count = 0;
            foreach ($submissions as $submission) {
                $submitted_days = floor((time() - strtotime($submission['submitted_at'])) / (60 * 60 * 24));
                if ($submitted_days > 7) {
                    $overdue_count++;
                } elseif ($submitted_days > 3) {
                    $due_soon_count++;
                }
            }
            ?>

            <div class="stat-card overdue">
                <span class="stat-value"><?php echo $overdue_count; ?></span>
                <span class="stat-label">Overdue Grading</span>
                <span class="stat-detail">> 7 days old</span>
            </div>

            <div class="stat-card urgent">
                <span class="stat-value"><?php echo $due_soon_count; ?></span>
                <span class="stat-label">Due Soon</span>
                <span class="stat-detail">3-7 days old</span>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label for="assignment_id">Filter by Assignment</label>
                    <select name="assignment_id" id="assignment_id" class="filter-control">
                        <option value="">All Assignments</option>
                        <?php foreach ($assignments as $ass): ?>
                            <option value="<?php echo $ass['id']; ?>"
                                <?php echo ($assignment_id == $ass['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ass['title'] . ' - ' . $ass['course_title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="class_id">Filter by Class</label>
                    <select name="class_id" id="class_id" class="filter-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"
                                <?php echo ($filter_class == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['course_title'] . ' - ' . $class['batch_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Search Students</label>
                    <input type="text" name="search" id="search" class="filter-control"
                        placeholder="Search by student name or email..." value="<?php echo htmlspecialchars($filter_search); ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-filter">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="btn btn-reset">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <div class="bulk-select">
                <input type="checkbox" id="selectAll" class="select-all">
                <label for="selectAll">Select All</label>
                <span id="selectedCount">0 selected</span>
            </div>

            <select id="bulkAction" class="filter-control" style="width: auto;">
                <option value="">Bulk Actions</option>
                <option value="grade">Quick Grade Selected</option>
                <option value="download">Download Selected</option>
                <option value="mark_graded">Mark as Graded</option>
            </select>

            <button type="button" id="applyBulkAction" class="btn btn-primary" disabled>
                <i class="fas fa-play"></i> Apply
            </button>
        </div>

        <!-- Pending Submissions Table -->
        <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>No Pending Submissions</h3>
                <p>All submissions have been graded! Great work.</p>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="btn btn-primary">
                    <i class="fas fa-tasks"></i> View All Assignments
                </a>
            </div>
        <?php else: ?>
            <table class="pending-table">
                <thead>
                    <tr>
                        <th style="width: 30px;">
                            <input type="checkbox" id="selectAllRows">
                        </th>
                        <th>Student</th>
                        <th>Assignment & Course</th>
                        <th>Submitted</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission):
                        $is_late = $submission['late_submission'] == 1;
                        $submitted_days = floor((time() - strtotime($submission['submitted_at'])) / (60 * 60 * 24));
                        $is_overdue = $submitted_days > 7;
                        $is_due_soon = $submitted_days > 3 && $submitted_days <= 7;
                        $row_class = '';
                        if ($is_overdue) $row_class = 'overdue-row';
                        elseif ($is_due_soon) $row_class = 'due-soon';
                    ?>
                        <tr data-submission-id="<?php echo $submission['id']; ?>" class="<?php echo $row_class; ?>">
                            <td>
                                <input type="checkbox" class="submission-checkbox" value="<?php echo $submission['id']; ?>">
                            </td>
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar">
                                        <?php echo strtoupper(substr($submission['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="student-details">
                                        <h4><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($submission['email']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="assignment-info">
                                    <h4><?php echo htmlspecialchars($submission['assignment_title']); ?></h4>
                                    <p><?php echo htmlspecialchars($submission['course_title'] . ' - ' . $submission['batch_code']); ?></p>
                                    <p><small>Points: <?php echo $submission['total_points']; ?></small></p>
                                </div>
                            </td>
                            <td>
                                <div class="submission-date <?php echo $is_late ? 'late' : ''; ?>">
                                    <?php if ($submission['submitted_at']): ?>
                                        <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                        <br>
                                        <small style="color: #95a5a6;">
                                            <?php echo $submitted_days; ?> day<?php echo $submitted_days != 1 ? 's' : ''; ?> ago
                                            <?php if ($is_overdue): ?>
                                                <span class="urgent-badge">OVERDUE</span>
                                            <?php elseif ($is_due_soon): ?>
                                                <span style="color: #f39c12;">Due Soon</span>
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="submission-date">
                                    <?php echo date('M j, Y g:i A', strtotime($submission['due_date'])); ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $is_late ? 'late' : 'pending'; ?>">
                                    <?php echo $is_late ? 'Late' : 'Pending'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons-small">
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade_submission.php?submission_id=<?php echo $submission['id']; ?>"
                                        class="btn-action btn-view" title="View Submission">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission['id']; ?>"
                                        class="btn-action btn-grade" title="Grade Now">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="#" class="btn-action btn-download" title="Download Files" onclick="downloadSubmission(<?php echo $submission['id']; ?>)">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
        // Select all functionality
        document.getElementById('selectAllRows').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.submission-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.submission-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = selected + ' selected';
            document.getElementById('applyBulkAction').disabled = selected === 0;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.submission-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk actions
        document.getElementById('applyBulkAction').addEventListener('click', function() {
            const action = document.getElementById('bulkAction').value;
            const selectedIds = Array.from(document.querySelectorAll('.submission-checkbox:checked'))
                .map(cb => cb.value);

            if (selectedIds.length === 0) {
                alert('Please select at least one submission.');
                return;
            }

            if (action === 'grade') {
                quickGradeMultiple(selectedIds);
            } else if (action === 'download') {
                downloadMultiple(selectedIds);
            } else if (action === 'mark_graded') {
                markAsGraded(selectedIds);
            } else {
                alert('Please select a bulk action.');
            }
        });

        // Quick grade multiple submissions
        function quickGradeMultiple(ids) {
            if (ids.length === 1) {
                // For single submission, redirect to grade page
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=' + ids[0];
                return;
            }

            const grade = prompt('Enter grade for selected submissions (out of 100):');
            if (grade === null) return;

            const numericGrade = parseFloat(grade);
            if (isNaN(numericGrade) || numericGrade < 0 || numericGrade > 100) {
                alert('Please enter a valid grade between 0 and 100.');
                return;
            }

            if (confirm(`Apply grade ${numericGrade} to ${ids.length} selected submission(s)?`)) {
                fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/bulk_grade.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            submission_ids: ids,
                            grade: numericGrade
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(`Grades applied successfully to ${data.updated} submission(s).`);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            }
        }

        // Download multiple submissions
        function downloadMultiple(ids) {
            if (ids.length === 1) {
                downloadSubmission(ids[0]);
                return;
            }

            // Create a temporary form to submit the request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo BASE_URL; ?>modules/instructor/assignments/bulk_download.php';
            form.style.display = 'none';

            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'submission_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // Mark as graded
        function markAsGraded(ids) {
            if (confirm(`Mark ${ids.length} selected submission(s) as graded?`)) {
                fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/bulk_mark_graded.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            submission_ids: ids
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(`${data.updated} submission(s) marked as graded.`);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            }
        }

        // Download single submission
        function downloadSubmission(submissionId) {
            window.open('<?php echo BASE_URL; ?>modules/instructor/assignments/download.php?submission_id=' + submissionId, '_blank');
        }

        // Auto-submit form when filter changes
        document.getElementById('assignment_id').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });

        document.getElementById('class_id').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });

        // Notification bell click
        document.querySelector('.notification-bell').addEventListener('click', function() {
            alert('You have <?php echo $total_pending; ?> pending submissions to grade.');
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Ctrl+A to select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                document.getElementById('selectAllRows').click();
            }

            // Ctrl+G for quick grade
            if (e.ctrlKey && e.key === 'g') {
                e.preventDefault();
                const selectedIds = Array.from(document.querySelectorAll('.submission-checkbox:checked'))
                    .map(cb => cb.value);
                if (selectedIds.length > 0) {
                    quickGradeMultiple(selectedIds);
                } else {
                    alert('Please select submissions to grade first.');
                }
            }
        });

        // Auto-refresh every 60 seconds
        setInterval(() => {
            if (!window.location.href.includes('export=csv')) {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        // Simple refresh by reloading if pending count might have changed
                        const currentPending = <?php echo $total_pending; ?>;
                        // Could implement more sophisticated DOM update here
                        console.log('Auto-refresh at:', new Date().toLocaleTimeString());
                    })
                    .catch(error => console.error('Refresh error:', error));
            }
        }, 60000);
    </script>
</body>

</html>