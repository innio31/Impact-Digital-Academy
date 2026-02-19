<?php
// modules/instructor/assignments/view.php

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

// Check if assignment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/');
    exit();
}

$assignment_id = intval($_GET['id']);
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get assignment details
$sql = "SELECT a.*, cb.batch_code, cb.name as class_name, c.title as course_title, c.course_code,
               cb.instructor_id, u.first_name, u.last_name,
               (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as total_submissions,
               (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND grade IS NULL) as pending_grading,
               (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND grade IS NOT NULL) as graded_count
        FROM assignments a 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        JOIN users u ON cb.instructor_id = u.id
        WHERE a.id = ? AND cb.instructor_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("ii", $assignment_id, $instructor_id);

if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Assignment not found or instructor doesn't have permission
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/');
    exit();
}

$assignment = $result->fetch_assoc();
$stmt->close();

// Get submission statistics
$sql_stats = "SELECT 
                COUNT(*) as total_students,
                AVG(grade) as average_grade,
                MIN(grade) as min_grade,
                MAX(grade) as max_grade,
                SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) as late_submissions,
                SUM(CASE WHEN s.status = 'missing' THEN 1 ELSE 0 END) as missing_submissions
              FROM enrollments e 
              LEFT JOIN assignment_submissions s ON e.student_id = s.student_id AND s.assignment_id = ?
              WHERE e.class_id = ? AND e.status = 'active'";

$stmt = $conn->prepare($sql_stats);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("ii", $assignment_id, $assignment['class_id']);
if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stmt->close();

// Get recent submissions
$sql_recent = "SELECT s.*, u.first_name, u.last_name, u.email, s.submitted_at,
                      s.grade, s.status, s.feedback
               FROM assignment_submissions s
               JOIN users u ON s.student_id = u.id
               WHERE s.assignment_id = ?
               ORDER BY s.submitted_at DESC
               LIMIT 5";

$stmt = $conn->prepare($sql_recent);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("i", $assignment_id);
if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}
$recent_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get class students for missing submissions
$sql_students = "SELECT u.id, u.first_name, u.last_name, u.email,
                        s.grade, s.status, s.submitted_at
                 FROM enrollments e
                 JOIN users u ON e.student_id = u.id
                 LEFT JOIN assignment_submissions s ON u.id = s.student_id AND s.assignment_id = ?
                 WHERE e.class_id = ? AND e.status = 'active'
                 ORDER BY u.last_name, u.first_name";

$stmt = $conn->prepare($sql_students);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("ii", $assignment_id, $assignment['class_id']);
if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// Calculate statistics
$submission_rate = $stats['total_students'] > 0 ?
    (($assignment['total_submissions'] / $stats['total_students']) * 100) : 0;
$grading_completion = $assignment['total_submissions'] > 0 ?
    (($assignment['graded_count'] / $assignment['total_submissions']) * 100) : 0;

// Format dates
$due_date = date('F j, Y g:i A', strtotime($assignment['due_date']));
$created_date = date('F j, Y', strtotime($assignment['created_at']));
$days_remaining = max(0, floor((strtotime($assignment['due_date']) - time()) / (60 * 60 * 24)));

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Assignment - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        /* Header Styles */
        .main-header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }

        .logo i {
            font-size: 2rem;
            color: #3498db;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 25px;
            transition: all 0.3s ease;
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        .nav-links {
            display: flex;
            gap: 20px;
            margin-left: 30px;
        }

        .nav-links a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: #c0392b;
        }

        .view-container {
            padding: 30px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .assignment-info {
            flex: 1;
        }

        .assignment-title {
            font-size: 32px;
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-weight: 700;
            line-height: 1.2;
        }

        .assignment-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #7f8c8d;
            font-size: 14px;
        }

        .meta-item i {
            color: #3498db;
            width: 16px;
        }

        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-title {
            font-size: 20px;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: #3498db;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-detail {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 5px;
        }

        .instructions-content {
            line-height: 1.8;
            color: #34495e;
            font-size: 15px;
        }

        .instructions-content h3 {
            color: #2c3e50;
            margin-top: 25px;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .instructions-content ul,
        .instructions-content ol {
            margin-left: 20px;
            margin-bottom: 15px;
        }

        .instructions-content li {
            margin-bottom: 8px;
        }

        .instructions-content p {
            margin-bottom: 15px;
        }

        .submission-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .submission-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s ease;
        }

        .submission-item:hover {
            background: #f8f9fa;
        }

        .submission-info {
            display: flex;
            align-items: center;
            gap: 15px;
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
        }

        .student-details h4 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 14px;
        }

        .student-details p {
            margin: 0;
            color: #7f8c8d;
            font-size: 12px;
        }

        .submission-meta {
            text-align: right;
        }

        .submission-grade {
            font-size: 18px;
            font-weight: 700;
            color: #27ae60;
            margin-bottom: 5px;
        }

        .submission-status {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
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

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            text-align: left;
            padding: 12px 15px;
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
        }

        .students-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .students-table tr:hover {
            background: #f8f9fa;
        }

        .grade-input {
            width: 70px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }

        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            color: #7f8c8d;
        }

        .chart-container {
            height: 200px;
            margin-top: 20px;
        }

        .timeline {
            margin-top: 30px;
        }

        .timeline-item {
            display: flex;
            margin-bottom: 20px;
            position: relative;
            padding-left: 30px;
        }

        .timeline-item:before {
            content: '';
            position: absolute;
            left: 0;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #3498db;
            border: 2px solid white;
            box-shadow: 0 0 0 3px #3498db;
        }

        .timeline-item:after {
            content: '';
            position: absolute;
            left: 5px;
            top: 17px;
            bottom: -20px;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item:last-child:after {
            display: none;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-date {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .timeline-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .timeline-description {
            color: #666;
            font-size: 14px;
        }

        .due-date-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .due-date-warning i {
            color: #f39c12;
            font-size: 24px;
        }

        .due-date-warning p {
            margin: 0;
            color: #856404;
        }

        .due-date-warning strong {
            color: #e67e22;
        }

        .tab-navigation {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 25px;
            background: white;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
        }

        .tab-button {
            padding: 15px 25px;
            background: none;
            border: none;
            font-weight: 600;
            color: #7f8c8d;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .tab-button:hover {
            color: #3498db;
        }

        .tab-button.active {
            color: #3498db;
        }

        .tab-button.active:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #3498db;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 20px;
            }

            .action-buttons {
                width: 100%;
                justify-content: center;
            }

            .tab-navigation {
                overflow-x: auto;
                white-space: nowrap;
            }

            .tab-button {
                padding: 12px 15px;
                font-size: 14px;
            }

            .header-container {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                margin-left: 0;
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        /* Footer Styles */
        .main-footer {
            background: #2c3e50;
            color: white;
            padding: 30px 20px;
            margin-top: 50px;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }

        .footer-section h3 {
            color: #3498db;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .footer-section p {
            color: #bdc3c7;
            line-height: 1.8;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: #bdc3c7;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: #3498db;
        }

        .copyright {
            text-align: center;
            padding-top: 30px;
            margin-top: 30px;
            border-top: 1px solid #34495e;
            color: #95a5a6;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <header class="main-header">
        <div class="header-container">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
                <h1>Impact Digital Academy</h1>
            </div>
            
            <div class="user-info">
                <nav class="nav-links">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/">
                        <i class="fas fa-chalkboard-teacher"></i> Classes
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/">
                        <i class="fas fa-bullhorn"></i> Announcements
                    </a>
                </nav>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                    <div>
                        <strong><?php echo htmlspecialchars($instructor_name); ?></strong>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8);">Instructor</div>
                    </div>
                </div>
                
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="view-container">
        <div class="page-header">
            <div class="assignment-info">
                <h1 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h1>

                <div class="assignment-meta">
                    <div class="meta-item">
                        <i class="fas fa-chalkboard"></i>
                        <span><?php echo htmlspecialchars($assignment['course_title'] . ' - ' . $assignment['batch_code']); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="far fa-calendar-alt"></i>
                        <span>Due: <?php echo $due_date; ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-star"></i>
                        <span>Points: <?php echo $assignment['total_points']; ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="status-badge status-<?php echo $assignment['is_published'] ? 'published' : 'draft'; ?>">
                            <?php echo $assignment['is_published'] ? 'Published' : 'Draft'; ?>
                        </span>
                    </div>
                </div>

                <p style="color: #666; margin-top: 10px;">
                    <i class="fas fa-user-tie"></i> Created by: <?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?>
                    | <i class="far fa-clock"></i> Created: <?php echo $created_date; ?>
                </p>
            </div>

            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment_id; ?>"
                    class="btn btn-primary">
                    <i class="fas fa-inbox"></i> View Submissions
                </a>
                <?php if ($assignment['pending_grading'] > 0): ?>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment_id; ?>"
                        class="btn btn-warning">
                        <i class="fas fa-check-circle"></i> Grade (<?php echo $assignment['pending_grading']; ?>)
                    </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/edit.php?id=<?php echo $assignment_id; ?>"
                    class="btn btn-success">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <?php if ($days_remaining < 3 && $assignment['is_published']): ?>
            <div class="due-date-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <p>
                    <strong>Due date approaching!</strong>
                    This assignment is due in <?php echo $days_remaining; ?> day<?php echo $days_remaining != 1 ? 's' : ''; ?>.
                    <?php if ($submission_rate < 50): ?>
                        Only <?php echo round($submission_rate); ?>% of students have submitted so far.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-button active" data-tab="overview">Overview</button>
            <button class="tab-button" data-tab="instructions">Instructions</button>
            <button class="tab-button" data-tab="submissions">Submissions</button>
            <button class="tab-button" data-tab="students">Students</button>
            <button class="tab-button" data-tab="analytics">Analytics</button>
        </div>

        <!-- Overview Tab -->
        <div id="overview-tab" class="tab-content active">
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Statistics -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-chart-bar"></i> Assignment Statistics
                            </h2>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-card">
                                <span class="stat-value"><?php echo $assignment['total_submissions']; ?></span>
                                <span class="stat-label">Submissions</span>
                                <span class="stat-detail">of <?php echo $stats['total_students']; ?> students</span>
                            </div>

                            <div class="stat-card">
                                <span class="stat-value"><?php echo round($submission_rate); ?>%</span>
                                <span class="stat-label">Submission Rate</span>
                                <span class="stat-detail">
                                    <?php echo $stats['total_students'] - $assignment['total_submissions']; ?> pending
                                </span>
                            </div>

                            <div class="stat-card">
                                <span class="stat-value"><?php echo round($grading_completion); ?>%</span>
                                <span class="stat-label">Graded</span>
                                <span class="stat-detail">
                                    <?php echo $assignment['pending_grading']; ?> to grade
                                </span>
                            </div>

                            <div class="stat-card">
                                <span class="stat-value">
                                    <?php echo $stats['average_grade'] ? round($stats['average_grade'], 1) : '0'; ?>
                                </span>
                                <span class="stat-label">Average Grade</span>
                                <span class="stat-detail">
                                    <?php echo $stats['min_grade'] ? $stats['min_grade'] : '0'; ?>-<?php echo $stats['max_grade'] ? $stats['max_grade'] : '0'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Submission Timeline -->
                        <div class="timeline">
                            <h3 style="margin-bottom: 20px; color: #2c3e50;">Submission Timeline</h3>

                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="timeline-date"><?php echo $created_date; ?></div>
                                    <div class="timeline-title">Assignment Created</div>
                                    <div class="timeline-description">Assignment was created and <?php echo $assignment['is_published'] ? 'published' : 'saved as draft'; ?></div>
                                </div>
                            </div>

                            <?php if ($assignment['total_submissions'] > 0 && !empty($recent_submissions)): ?>
                                <div class="timeline-item">
                                    <div class="timeline-content">
                                        <div class="timeline-date"><?php echo date('F j, Y', strtotime($recent_submissions[0]['submitted_at'])); ?></div>
                                        <div class="timeline-title">First Submission</div>
                                        <div class="timeline-description">
                                            First student submission received
                                        </div>
                                    </div>
                                </div>

                                <div class="timeline-item">
                                    <div class="timeline-content">
                                        <div class="timeline-date"><?php echo date('F j, Y', strtotime(end($recent_submissions)['submitted_at'])); ?></div>
                                        <div class="timeline-title">Most Recent Submission</div>
                                        <div class="timeline-description">
                                            Latest student submission received
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="timeline-date"><?php echo date('F j, Y', strtotime($assignment['due_date'])); ?></div>
                                    <div class="timeline-title">Due Date</div>
                                    <div class="timeline-description">
                                        Assignment submission deadline
                                        <?php if ($days_remaining > 0): ?>
                                            (<?php echo $days_remaining; ?> days remaining)
                                        <?php else: ?>
                                            (Past due)
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Submissions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i> Recent Submissions
                            </h2>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment_id; ?>"
                                class="btn btn-secondary btn-sm">
                                View All
                            </a>
                        </div>

                        <div class="submission-list">
                            <?php if (empty($recent_submissions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h3>No Submissions Yet</h3>
                                    <p>Students haven't submitted this assignment yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_submissions as $submission): ?>
                                    <div class="submission-item">
                                        <div class="submission-info">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($submission['first_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-details">
                                                <h4><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h4>
                                                <p><?php echo date('M j, g:i A', strtotime($submission['submitted_at'])); ?></p>
                                            </div>
                                        </div>
                                        <div class="submission-meta">
                                            <?php if ($submission['grade']): ?>
                                                <div class="submission-grade"><?php echo $submission['grade']; ?></div>
                                            <?php endif; ?>
                                            <span class="submission-status status-<?php echo strtolower($submission['status']); ?>">
                                                <?php echo ucfirst($submission['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="content-card" style="margin-top: 20px;">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-bolt"></i> Quick Actions
                            </h2>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment_id; ?>"
                                class="btn btn-warning" style="justify-content: center;">
                                <i class="fas fa-check-circle"></i> Grade Submissions
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/edit.php?id=<?php echo $assignment_id; ?>"
                                class="btn btn-success" style="justify-content: center;">
                                <i class="fas fa-edit"></i> Edit Assignment
                            </a>

                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $assignment['class_id']; ?>/announcements/create.php?assignment_id=<?php echo $assignment_id; ?>"
                                class="btn btn-primary" style="justify-content: center;">
                                <i class="fas fa-bullhorn"></i> Send Reminder
                            </a>

                            <?php if ($assignment['is_published']): ?>
                                <button onclick="unpublishAssignment()" class="btn btn-secondary">
                                    <i class="fas fa-eye-slash"></i> Unpublish
                                </button>
                            <?php else: ?>
                                <button onclick="publishAssignment()" class="btn btn-primary">
                                    <i class="fas fa-eye"></i> Publish
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instructions Tab -->
        <div id="instructions-tab" class="tab-content">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-file-alt"></i> Assignment Instructions
                    </h2>
                </div>

                <div class="instructions-content">
                    <?php if (empty($assignment['instructions'])): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Detailed Instructions</h3>
                            <p>No detailed instructions have been provided for this assignment.</p>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/edit.php?id=<?php echo $assignment_id; ?>"
                                class="btn btn-primary">
                                <i class="fas fa-edit"></i> Add Instructions
                            </a>
                        </div>
                    <?php else: ?>
                        <?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 6px;">
                    <h4 style="margin-bottom: 10px; color: #2c3e50;">
                        <i class="fas fa-info-circle"></i> Assignment Details
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <strong>Submission Type:</strong>
                            <?php echo ucfirst($assignment['submission_type']); ?>
                        </div>
                        <div>
                            <strong>Maximum Files:</strong>
                            <?php echo $assignment['max_files']; ?>
                        </div>
                        <div>
                            <strong>Allowed File Types:</strong>
                            <?php echo $assignment['allowed_extensions'] ? htmlspecialchars($assignment['allowed_extensions']) : 'Any'; ?>
                        </div>
                        <div>
                            <strong>Total Points:</strong>
                            <?php echo $assignment['total_points']; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submissions Tab -->
        <div id="submissions-tab" class="tab-content">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-inbox"></i> All Submissions
                    </h2>
                    <div>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment_id; ?>"
                            class="btn btn-warning">
                            <i class="fas fa-check-circle"></i> Grade All
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment_id; ?>&export=csv"
                            class="btn btn-secondary">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </div>
                </div>

                <div class="table-container">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submission Date</th>
                                <th>Status</th>
                                <th>Grade</th>
                                <th>Feedback</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <h3>No Students Enrolled</h3>
                                        <p>There are no active students in this class.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student):
                                    $has_submitted = !empty($student['submitted_at']);
                                    $is_graded = !empty($student['grade']);
                                ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div class="student-avatar">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong><br>
                                                    <small style="color: #7f8c8d;"><?php echo htmlspecialchars($student['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($has_submitted): ?>
                                                <?php echo date('M j, g:i A', strtotime($student['submitted_at'])); ?>
                                                <?php if ($student['status'] == 'late'): ?>
                                                    <br><small style="color: #e74c3c;">Late submission</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #95a5a6;">Not submitted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($has_submitted): ?>
                                                <span class="submission-status status-<?php echo strtolower($student['status']); ?>">
                                                    <?php echo ucfirst($student['status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="submission-status status-missing">
                                                    Missing
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_graded): ?>
                                                <strong style="color: #27ae60;"><?php echo $student['grade']; ?></strong>
                                                / <?php echo $assignment['total_points']; ?>
                                            <?php elseif ($has_submitted): ?>
                                                <span style="color: #f39c12;">Pending</span>
                                            <?php else: ?>
                                                <span style="color: #95a5a6;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($student['feedback'])): ?>
                                                <i class="fas fa-comment" title="<?php echo htmlspecialchars($student['feedback']); ?>"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <?php if ($has_submitted): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade_submission.php?submission_id=<?php echo $student['id']; ?>"
                                                        class="btn btn-sm" style="padding: 5px 10px; font-size: 12px;">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $student['id']; ?>"
                                                        class="btn btn-sm" style="padding: 5px 10px; font-size: 12px; background: #f39c12; color: white;">
                                                        <i class="fas fa-check"></i> Grade
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Students Tab -->
        <div id="students-tab" class="tab-content">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-user-graduate"></i> Student Progress
                    </h2>
                </div>

                <div class="table-container">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submission</th>
                                <th>Grade</th>
                                <th>Percentage</th>
                                <th>Grade Letter</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $has_submitted = !empty($student['submitted_at']);
                                $is_graded = !empty($student['grade']);
                                $percentage = $is_graded ? ($student['grade'] / $assignment['total_points']) * 100 : 0;

                                // Calculate grade letter
                                $grade_letter = 'F';
                                if ($percentage >= 90) $grade_letter = 'A';
                                elseif ($percentage >= 80) $grade_letter = 'B';
                                elseif ($percentage >= 70) $grade_letter = 'C';
                                elseif ($percentage >= 60) $grade_letter = 'D';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($has_submitted): ?>
                                            <?php echo date('M j', strtotime($student['submitted_at'])); ?>
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">Not submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_graded): ?>
                                            <input type="number" class="grade-input"
                                                value="<?php echo $student['grade']; ?>"
                                                data-student-id="<?php echo $student['id']; ?>"
                                                min="0" max="<?php echo $assignment['total_points']; ?>"
                                                step="0.5">
                                        <?php elseif ($has_submitted): ?>
                                            <input type="number" class="grade-input"
                                                value=""
                                                data-student-id="<?php echo $student['id']; ?>"
                                                placeholder="Enter grade"
                                                min="0" max="<?php echo $assignment['total_points']; ?>"
                                                step="0.5">
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">—</span>
                                        <?php endif; ?>
                                        / <?php echo $assignment['total_points']; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_graded): ?>
                                            <span><?php echo round($percentage, 1); ?>%</span>
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_graded): ?>
                                            <span class="status-badge" style="background: <?php
                                                                                            echo $grade_letter == 'A' ? '#d4edda' : ($grade_letter == 'B' ? '#cce5ff' : ($grade_letter == 'C' ? '#fff3cd' : ($grade_letter == 'D' ? '#f8d7da' : '#f5c6cb'))); 
                                                                                            ?>; color: <?php
                                                        echo $grade_letter == 'A' ? '#155724' : ($grade_letter == 'B' ? '#004085' : ($grade_letter == 'C' ? '#856404' : ($grade_letter == 'D' ? '#721c24' : '#721c24')));
                                                        ?>;">
                                                <?php echo $grade_letter; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_submitted): ?>
                                            <span class="submission-status status-<?php echo strtolower($student['status']); ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="submission-status status-missing">
                                                Missing
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <?php if ($has_submitted): ?>
                                                <button onclick="quickGrade(<?php echo $student['id']; ?>)" 
                                                    class="btn btn-sm" style="padding: 5px 10px; font-size: 12px; background: #27ae60; color: white;">
                                                    <i class="fas fa-check"></i> Save
                                                </button>
                                                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade_submission.php?submission_id=<?php echo $student['id']; ?>"
                                                    class="btn btn-sm" style="padding: 5px 10px; font-size: 12px; background: #3498db; color: white;">
                                                    <i class="fas fa-edit"></i> Feedback
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Analytics Tab -->
        <div id="analytics-tab" class="tab-content">
            <div class="content-grid">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-chart-pie"></i> Grade Distribution
                        </h2>
                    </div>
                    <div class="chart-container">
                        <canvas id="gradeChart"></canvas>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-chart-line"></i> Submission Timeline
                        </h2>
                    </div>
                    <div class="chart-container">
                        <canvas id="submissionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-section">
                <h3>Impact Digital Academy</h3>
                <p>Empowering the next generation of digital professionals through quality education and practical skills training.</p>
            </div>
            
            <div class="footer-section">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="<?php echo BASE_URL; ?>">Home</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">Instructor Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/instructor/assignments/">Assignments</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/instructor/classes/">Classes</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Contact Info</h3>
                <p><i class="fas fa-envelope"></i> support@impactdigitalacademy.com</p>
                <p><i class="fas fa-phone"></i> +234 123 456 7890</p>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Tab functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all tabs
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

                // Add active class to clicked tab
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab') + '-tab';
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Publish/Unpublish functions
        function publishAssignment() {
            if (confirm('Publish this assignment? Students will be able to see and submit it.')) {
                fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/publish.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            assignment_id: <?php echo $assignment_id; ?>,
                            action: 'publish'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
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

        function unpublishAssignment() {
            if (confirm('Unpublish this assignment? Students will no longer be able to see it.')) {
                fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/publish.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            assignment_id: <?php echo $assignment_id; ?>,
                            action: 'unpublish'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
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

        // Quick grade functionality
        function quickGrade(studentId) {
            const input = document.querySelector(`.grade-input[data-student-id="${studentId}"]`);
            const grade = parseFloat(input.value);

            if (isNaN(grade) || grade < 0 || grade > <?php echo $assignment['total_points']; ?>) {
                alert('Please enter a valid grade between 0 and <?php echo $assignment['total_points']; ?>');
                input.focus();
                return;
            }

            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/quick_grade.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        assignment_id: <?php echo $assignment_id; ?>,
                        student_id: studentId,
                        grade: grade
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

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeChart').getContext('2d');
            const gradeChart = new Chart(gradeCtx, {
                type: 'pie',
                data: {
                    labels: ['A (90-100%)', 'B (80-89%)', 'C (70-79%)', 'D (60-69%)', 'F (0-59%)', 'Ungraded'],
                    datasets: [{
                        data: [12, 19, 8, 5, 3, 15], // Sample data
                        backgroundColor: [
                            '#27ae60',
                            '#3498db',
                            '#f39c12',
                            '#e74c3c',
                            '#95a5a6',
                            '#ecf0f1'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Submission Timeline Chart
            const submissionCtx = document.getElementById('submissionChart').getContext('2d');
            const submissionChart = new Chart(submissionCtx, {
                type: 'line',
                data: {
                    labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7'],
                    datasets: [{
                        label: 'Submissions',
                        data: [0, 3, 7, 12, 18, 22, 25],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Submissions'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Days since assignment posted'
                            }
                        }
                    }
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+G to go to grading
                if (e.ctrlKey && e.key === 'g') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment_id; ?>';
                }

                // Ctrl+E to edit
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/edit.php?id=<?php echo $assignment_id; ?>';
                }

                // Ctrl+S to view submissions
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment_id; ?>';
                }
            });
        });

        // Auto-save grades on input change
        document.querySelectorAll('.grade-input').forEach(input => {
            let saveTimer;
            input.addEventListener('input', function() {
                clearTimeout(saveTimer);
                saveTimer = setTimeout(() => {
                    const grade = parseFloat(this.value);
                    if (!isNaN(grade) && grade >= 0 && grade <= <?php echo $assignment['total_points']; ?>) {
                        quickGrade(this.dataset.studentId);
                    }
                }, 2000);
            });
        });
    </script>
</body>

</html>