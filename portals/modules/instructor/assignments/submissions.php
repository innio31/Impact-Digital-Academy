<?php
// modules/instructor/assignments/submissions.php

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
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get filter parameters
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for submissions
$query = "SELECT s.*, a.title as assignment_title, a.due_date, a.total_points,
                 u.id as student_id, u.first_name, u.last_name, u.email,
                 cb.batch_code, c.title as course_title,
                 g.grade_letter, g.percentage, g.score
          FROM assignment_submissions s 
          JOIN assignments a ON s.assignment_id = a.id 
          JOIN users u ON s.student_id = u.id 
          JOIN class_batches cb ON a.class_id = cb.id 
          JOIN courses c ON cb.course_id = c.id 
          LEFT JOIN gradebook g ON s.assignment_id = g.assignment_id AND s.student_id = g.student_id
          WHERE a.instructor_id = ?";

$params = [];
$param_values = [$instructor_id];
$types = "i"; // Start with instructor_id as integer

// Add filters
if ($assignment_id > 0) {
    $query .= " AND s.assignment_id = ?";
    $types .= "i";
    $param_values[] = $assignment_id;
}

if (!empty($filter_status)) {
    if ($filter_status === 'graded') {
        $query .= " AND s.grade IS NOT NULL";
    } elseif ($filter_status === 'pending') {
        $query .= " AND s.grade IS NULL AND s.status = 'submitted'";
    } elseif ($filter_status === 'late') {
        $query .= " AND s.late_submission = 1";
    } elseif ($filter_status === 'missing') {
        $query .= " AND s.status = 'missing'";
    }
}

if (!empty($filter_class)) {
    $query .= " AND a.class_id = ?";
    $types .= "i";
    $param_values[] = $filter_class;
}

if (!empty($filter_search)) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR a.title LIKE ?)";
    $types .= "ssss";
    $search_term = "%{$filter_search}%";
    $param_values[] = $search_term;
    $param_values[] = $search_term;
    $param_values[] = $search_term;
    $param_values[] = $search_term;
}

$query .= " ORDER BY s.submitted_at DESC";

// Execute query
$stmt = $conn->prepare($query);
if ($stmt) {
    if (count($param_values) > 0) {
        // Bind parameters correctly
        $stmt->bind_param($types, ...$param_values);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $submissions = [];
    error_log("Query preparation failed: " . $conn->error);
}

// Get instructor's assignments for filter dropdown
$sql_assignments = "SELECT a.id, a.title, cb.batch_code, c.title as course_title
                    FROM assignments a 
                    JOIN class_batches cb ON a.class_id = cb.id 
                    JOIN courses c ON cb.course_id = c.id 
                    WHERE a.instructor_id = ? 
                    ORDER BY a.due_date DESC";
$stmt = $conn->prepare($sql_assignments);
if ($stmt) {
    $stmt->bind_param("i", $instructor_id);
    $stmt->execute();
    $assignments_result = $stmt->get_result();
    $assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $assignments = [];
}

// Get instructor's classes for filter dropdown
$sql_classes = "SELECT cb.id, cb.batch_code, c.title as course_title
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

// Get statistics
$total_submissions = count($submissions);
$graded_count = count(array_filter($submissions, function ($s) {
    return $s['grade'] !== null;
}));
$pending_count = $total_submissions - $graded_count;
$late_count = count(array_filter($submissions, function ($s) {
    return $s['late_submission'] == 1;
}));

$conn->close();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=submissions_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // CSV header
    fputcsv($output, [
        'Student Name',
        'Email',
        'Assignment',
        'Course',
        'Batch',
        'Submitted At',
        'Grade',
        'Percentage',
        'Grade Letter',
        'Status',
        'Late Submission',
        'Feedback'
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
            $submission['grade'] ?? '',
            $submission['percentage'] ?? '',
            $submission['grade_letter'] ?? '',
            $submission['status'],
            $submission['late_submission'] ? 'Yes' : 'No',
            substr($submission['feedback'] ?? '', 0, 100) // Limit feedback length
        ]);
    }

    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Submissions - Instructor Dashboard</title>
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
        .submissions-container {
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

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
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

        .stat-card.total {
            border-left-color: #3498db;
        }

        .stat-card.graded {
            border-left-color: #27ae60;
        }

        .stat-card.pending {
            border-left-color: #f39c12;
        }

        .stat-card.late {
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

        .submissions-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .submissions-table th {
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

        .submissions-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .submissions-table tr:hover {
            background: #f8f9fa;
        }

        .submissions-table tr:last-child td {
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

        .grade-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            min-width: 50px;
            text-align: center;
        }

        .grade-excellent {
            background: #d4edda;
            color: #155724;
        }

        .grade-good {
            background: #cce5ff;
            color: #004085;
        }

        .grade-average {
            background: #fff3cd;
            color: #856404;
        }

        .grade-poor {
            background: #f8d7da;
            color: #721c24;
        }

        .grade-pending {
            background: #e2e3e5;
            color: #383d41;
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

        .status-submitted {
            background: #d4edda;
            color: #155724;
        }

        .status-graded {
            background: #cce5ff;
            color: #004085;
        }

        .status-late {
            background: #fff3cd;
            color: #856404;
        }

        .status-missing {
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

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
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

        .view-all-cell {
            text-align: center;
            padding: 30px !important;
        }

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

        .quick-grade-input {
            width: 80px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .feedback-popup {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .feedback-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        /* Footer Styles */
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

            .header-container {
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links {
                margin-left: 0;
                flex-wrap: wrap;
                justify-content: center;
            }

            .submissions-table {
                display: block;
                overflow-x: auto;
            }
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
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="nav-link active">
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
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/index.php" class="breadcrumb-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">
                    <i class="fas fa-inbox"></i> Student Submissions
                </span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="submissions-container">
        <div class="page-header">
            <div class="page-title">
                <h1>Student Submissions</h1>
                <p>View and manage all student assignment submissions</p>
            </div>
            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Assignments
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="btn btn-warning">
                    <i class="fas fa-clock"></i> Pending Grading
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-export">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <span class="stat-value"><?php echo $total_submissions; ?></span>
                <span class="stat-label">Total Submissions</span>
                <span class="stat-detail">Across all assignments</span>
            </div>

            <div class="stat-card graded">
                <span class="stat-value"><?php echo $graded_count; ?></span>
                <span class="stat-label">Graded</span>
                <span class="stat-detail">
                    <?php echo $total_submissions > 0 ? round(($graded_count / $total_submissions) * 100) : 0; ?>% complete
                </span>
            </div>

            <div class="stat-card pending">
                <span class="stat-value"><?php echo $pending_count; ?></span>
                <span class="stat-label">Pending Grading</span>
                <span class="stat-detail">Need your attention</span>
            </div>

            <div class="stat-card late">
                <span class="stat-value"><?php echo $late_count; ?></span>
                <span class="stat-label">Late Submissions</span>
                <span class="stat-detail">
                    <?php echo $total_submissions > 0 ? round(($late_count / $total_submissions) * 100) : 0; ?>% of total
                </span>
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
                    <label for="status">Filter by Status</label>
                    <select name="status" id="status" class="filter-control">
                        <option value="">All Status</option>
                        <option value="graded" <?php echo ($filter_status == 'graded') ? 'selected' : ''; ?>>Graded</option>
                        <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="late" <?php echo ($filter_status == 'late') ? 'selected' : ''; ?>>Late</option>
                        <option value="missing" <?php echo ($filter_status == 'missing') ? 'selected' : ''; ?>>Missing</option>
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
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="btn btn-reset">
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
                <option value="feedback">Add Feedback</option>
            </select>

            <button type="button" id="applyBulkAction" class="btn btn-primary" disabled>
                <i class="fas fa-play"></i> Apply
            </button>
        </div>

        <!-- Submissions Table -->
        <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Submissions Found</h3>
                <p>No student submissions match your current filters.</p>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php" class="btn btn-primary">
                    <i class="fas fa-redo"></i> Clear Filters
                </a>
            </div>
        <?php else: ?>
            <table class="submissions-table">
                <thead>
                    <tr>
                        <th style="width: 30px;">
                            <input type="checkbox" id="selectAllRows">
                        </th>
                        <th>Student</th>
                        <th>Assignment & Course</th>
                        <th>Submitted</th>
                        <th>Grade</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission):
                        $is_late = $submission['late_submission'] == 1;
                        $is_graded = $submission['grade'] !== null;

                        // Determine grade class
                        $grade_class = 'grade-pending';
                        if ($is_graded) {
                            $percentage = $submission['percentage'] ?? ($submission['grade'] / $submission['total_points']) * 100;
                            if ($percentage >= 90) $grade_class = 'grade-excellent';
                            elseif ($percentage >= 80) $grade_class = 'grade-good';
                            elseif ($percentage >= 70) $grade_class = 'grade-average';
                            else $grade_class = 'grade-poor';
                        }

                        // Determine status
                        $status = $submission['status'];
                        if ($status === 'submitted' && !$is_graded) {
                            $status = 'submitted';
                        }
                    ?>
                        <tr data-submission-id="<?php echo $submission['id']; ?>">
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
                                </div>
                            </td>
                            <td>
                                <div class="submission-date <?php echo $is_late ? 'late' : ''; ?>">
                                    <?php if ($submission['submitted_at']): ?>
                                        <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                        <?php if ($is_late): ?>
                                            <br><small style="color: #e74c3c;">Late</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #95a5a6;">Not submitted</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($is_graded): ?>
                                    <span class="grade-badge <?php echo $grade_class; ?>">
                                        <?php echo $submission['grade']; ?> / <?php echo $submission['total_points']; ?>
                                    </span>
                                    <?php if ($submission['grade_letter']): ?>
                                        <br><small><?php echo $submission['grade_letter']; ?> (<?php echo round($submission['percentage'], 1); ?>%)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="grade-badge grade-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($status); ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons-small">
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade_submission.php?submission_id=<?php echo $submission['id']; ?>"
                                        class="btn-action btn-view" title="View Submission">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission['id']; ?>"
                                        class="btn-action btn-grade" title="Grade Submission">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <?php if (!empty($submission_files)): ?>
                                        <a href="<?php echo BASE_URL; ?>uploads/assignments/<?php echo $submission['id']; ?>/download"
                                            class="btn-action btn-download" title="Download Files">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <a href="#" class="page-link"><i class="fas fa-chevron-left"></i></a>
                <a href="#" class="page-link active">1</a>
                <a href="#" class="page-link">2</a>
                <a href="#" class="page-link">3</a>
                <a href="#" class="page-link"><i class="fas fa-chevron-right"></i></a>
            </div>
        <?php endif; ?>

        <!-- View All Link -->
        <?php if ($assignment_id > 0): ?>
            <div style="text-align: center; margin-top: 30px;">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment_id; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Assignment Details
                </a>
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
            } else if (action === 'feedback') {
                addFeedbackMultiple(selectedIds);
            } else {
                alert('Please select a bulk action.');
            }
        });

        // Quick grade multiple submissions
        function quickGradeMultiple(ids) {
            const grade = prompt('Enter grade for selected submissions:');
            if (grade === null) return;

            const numericGrade = parseFloat(grade);
            if (isNaN(numericGrade) || numericGrade < 0) {
                alert('Please enter a valid grade.');
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

        // Add feedback to multiple submissions
        function addFeedbackMultiple(ids) {
            const feedback = prompt('Enter feedback for selected submissions:');
            if (feedback === null || feedback.trim() === '') return;

            if (confirm(`Add feedback to ${ids.length} selected submission(s)?`)) {
                fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/bulk_feedback.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            submission_ids: ids,
                            feedback: feedback.trim()
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(`Feedback added to ${data.updated} submission(s).`);
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

        // Quick grade from table
        function quickGradeSubmission(submissionId) {
            const row = document.querySelector(`tr[data-submission-id="${submissionId}"]`);
            const gradeInput = row.querySelector('.quick-grade-input');
            const grade = gradeInput ? gradeInput.value : prompt('Enter grade:');

            if (grade === null) return;

            const numericGrade = parseFloat(grade);
            if (isNaN(numericGrade)) {
                alert('Please enter a valid grade.');
                return;
            }

            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/quick_grade.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        submission_id: submissionId,
                        grade: numericGrade
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Grade saved successfully!');
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

        // Auto-refresh submissions
        function refreshSubmissions() {
            const currentUrl = window.location.href;
            if (!currentUrl.includes('export=csv')) {
                fetch(currentUrl)
                    .then(response => response.text())
                    .then(html => {
                        // Update only the table portion (simplified)
                        console.log('Submissions refreshed at:', new Date().toLocaleTimeString());
                    })
                    .catch(error => console.error('Refresh error:', error));
            }
        }

        // Refresh every 60 seconds
        setInterval(refreshSubmissions, 60000);

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

            // Ctrl+G for bulk grade
            if (e.ctrlKey && e.key === 'g') {
                e.preventDefault();
                document.getElementById('bulkAction').value = 'grade';
                document.getElementById('applyBulkAction').click();
            }
        });

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
            alert('Notifications feature coming soon!');
        });

        // Add confirmation for deleting submissions
        document.addEventListener('DOMContentLoaded', function() {
            const deleteLinks = document.querySelectorAll('.btn-delete');
            deleteLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to delete this submission? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            });
        });

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