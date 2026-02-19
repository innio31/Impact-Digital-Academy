<?php
// modules/instructor/assignments/grade_submission.php

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

// Check for required parameters
if (!isset($_GET['submission_id']) || !is_numeric($_GET['submission_id'])) {
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/pending.php');
    exit();
}

$submission_id = intval($_GET['submission_id']);
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get submission details - FIXED: Changed g.feedback to g.notes
$sql = "SELECT s.*, a.id as assignment_id, a.title as assignment_title, 
               a.total_points, a.due_date, a.class_id, a.instructions,
               a.submission_type, a.allowed_extensions,
               u.id as student_id, u.first_name, u.last_name, u.email,
               cb.batch_code, c.title as course_title,
               g.grade_letter, g.percentage, g.score, g.notes as gradebook_feedback
        FROM assignment_submissions s 
        JOIN assignments a ON s.assignment_id = a.id 
        JOIN users u ON s.student_id = u.id 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        LEFT JOIN gradebook g ON s.assignment_id = g.assignment_id AND s.student_id = g.student_id
        WHERE s.id = ? AND a.instructor_id = ?";

$stmt = $conn->prepare($sql);
// FIXED: bind_param with 2 parameters for 2 placeholders
$stmt->bind_param("ii", $submission_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/pending.php');
    exit();
}

$submission = $result->fetch_assoc();
$stmt->close();

// Get submission files
$sql = "SELECT * FROM submission_files WHERE submission_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get student's other submissions for this assignment (if any)
$sql = "SELECT * FROM assignment_submissions 
        WHERE student_id = ? AND assignment_id = ? AND id != ?
        ORDER BY submitted_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $submission['student_id'], $submission['assignment_id'], $submission_id);
$stmt->execute();
$other_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get assignment details
$sql = "SELECT * FROM assignments WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $submission['assignment_id']);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

// Calculate time since submission
$submitted_time = strtotime($submission['submitted_at']);
$current_time = time();
$hours_since = floor(($current_time - $submitted_time) / 3600);
$days_since = floor($hours_since / 24);

$time_text = '';
if ($days_since > 0) {
    $time_text = $days_since . ' day' . ($days_since > 1 ? 's' : '') . ' ago';
} elseif ($hours_since > 0) {
    $time_text = $hours_since . ' hour' . ($hours_since > 1 ? 's' : '') . ' ago';
} else {
    $time_text = 'Less than an hour ago';
}

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.css">
    <style>
        /* Header Styles */
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo i {
            font-size: 28px;
        }

        .logo-text {
            font-size: 20px;
        }

        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
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
            color: white;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            opacity: 0.8;
        }

        .nav-links {
            display: flex;
            gap: 20px;
            margin-left: 40px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: 4px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 8px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .hamburger-menu {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 5px;
        }

        @media (max-width: 992px) {
            .nav-links {
                display: none;
                position: absolute;
                top: 70px;
                left: 0;
                right: 0;
                background: #667eea;
                flex-direction: column;
                padding: 20px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }

            .nav-links.show {
                display: flex;
            }

            .hamburger-menu {
                display: block;
            }

            .logo-text {
                display: none;
            }
        }

        /* Main Content Styles */
        .submission-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
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

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
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

        .student-info-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .student-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 24px;
            flex-shrink: 0;
        }

        .student-details h2 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 22px;
        }

        .student-details p {
            margin: 0;
            color: #7f8c8d;
            font-size: 14px;
        }

        .submission-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #555;
            font-size: 14px;
        }

        .meta-item i {
            color: #3498db;
            width: 16px;
        }

        .grade-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .grade-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }

        .grade-score {
            font-size: 40px;
            font-weight: 900;
        }

        .grade-details {
            text-align: right;
        }

        .grade-percentage {
            font-size: 24px;
            font-weight: 700;
        }

        .grade-letter {
            font-size: 32px;
            font-weight: 900;
            margin-top: 5px;
        }

        .submission-content {
            line-height: 1.8;
            color: #34495e;
            font-size: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }

        .submission-content h3 {
            color: #2c3e50;
            margin-top: 25px;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .files-section {
            margin-top: 30px;
        }

        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .file-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            background: white;
        }

        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .file-preview {
            height: 150px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .file-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .file-icon {
            font-size: 48px;
            color: #7f8c8d;
        }

        .file-info {
            padding: 15px;
            background: white;
        }

        .file-name {
            font-size: 14px;
            color: #2c3e50;
            margin-bottom: 8px;
            word-break: break-all;
            font-weight: 600;
        }

        .file-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #7f8c8d;
        }

        .feedback-section {
            margin-top: 30px;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }

        .feedback-section h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .feedback-content {
            line-height: 1.7;
            color: #34495e;
            font-size: 15px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .assignment-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
        }

        .assignment-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 15px 0;
            line-height: 1.3;
        }

        .assignment-meta {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.6;
        }

        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-full {
            width: 100%;
            justify-content: center;
        }

        .history-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        .history-title {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .history-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-date {
            color: #7f8c8d;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .history-grade {
            font-weight: 600;
            color: #2c3e50;
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

        .image-viewer {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .image-viewer img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }

        .close-viewer {
            position: absolute;
            top: 20px;
            right: 20px;
            background: white;
            color: #333;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            z-index: 1001;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .action-buttons {
                width: 100%;
                justify-content: flex-start;
            }

            .student-info-card {
                flex-direction: column;
                text-align: center;
            }

            .grade-display {
                flex-direction: column;
                text-align: center;
            }

            .grade-details {
                text-align: center;
            }
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

        .text-preview {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>

<body>
    <!-- Header Section -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="logo-section">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="logo">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="logo-text">Impact Digital Academy</span>
                </a>
                <button class="hamburger-menu" onclick="toggleMenu()">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="nav-links" id="navLinks">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/index.php" class="nav-link">
                        <i class="fas fa-chalkboard"></i> Classes
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="nav-link active">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/gradebook/index.php" class="nav-link">
                        <i class="fas fa-book-open"></i> Gradebook
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/messages/index.php" class="nav-link">
                        <i class="fas fa-envelope"></i> Messages
                    </a>
                </div>
            </div>
            
            <div class="user-section">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        $initials = '';
                        if (isset($_SESSION['user_name'])) {
                            $name_parts = explode(' ', $_SESSION['user_name']);
                            $initials = strtoupper(substr($name_parts[0], 0, 1));
                            if (count($name_parts) > 1) {
                                $initials .= strtoupper(substr($name_parts[1], 0, 1));
                            }
                        } else {
                            $initials = 'I';
                        }
                        echo $initials;
                        ?>
                    </div>
                    <div class="user-details">
                        <span class="user-name"><?php echo $_SESSION['user_name'] ?? 'Instructor'; ?></span>
                        <span class="user-role">Instructor</span>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="submission-container">
        <div class="page-header">
            <div class="page-title">
                <h1>View Submission</h1>
                <p>Review student's submitted work and feedback</p>
            </div>

            <div class="action-buttons">
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $submission['assignment_id']; ?>"
                    class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Submissions
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission_id; ?>"
                    class="btn btn-warning">
                    <i class="fas fa-check"></i> Edit Grade
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $submission['class_id']; ?>/students/progress.php?student_id=<?php echo $submission['student_id']; ?>"
                    class="btn btn-primary">
                    <i class="fas fa-user-graduate"></i> Student Profile
                </a>
            </div>
        </div>

        <div class="content-grid">
            <!-- Main Content -->
            <div class="main-content">
                <!-- Student Info -->
                <div class="content-card">
                    <div class="student-info-card">
                        <div class="student-avatar">
                            <?php echo strtoupper(substr($submission['first_name'], 0, 1)); ?>
                        </div>
                        <div class="student-details">
                            <h2><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h2>
                            <p><?php echo htmlspecialchars($submission['email']); ?></p>
                            <p style="margin-top: 5px;">
                                <i class="far fa-clock"></i> Submitted <?php echo $time_text; ?>
                                <span class="status-badge status-<?php echo strtolower($submission['status']); ?>" style="margin-left: 10px;">
                                    <?php echo ucfirst($submission['status']); ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="submission-meta">
                        <div class="meta-item">
                            <i class="fas fa-chalkboard"></i>
                            <span><?php echo htmlspecialchars($submission['course_title'] . ' - ' . $submission['batch_code']); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="far fa-calendar-alt"></i>
                            <span>Due: <?php echo date('F j, Y g:i A', strtotime($submission['due_date'])); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-star"></i>
                            <span>Max Points: <?php echo $submission['total_points']; ?></span>
                        </div>
                        <?php if ($submission['late_submission']): ?>
                            <div class="meta-item" style="color: #e74c3c;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Late Submission</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Grade Display -->
                <?php if ($submission['grade'] !== null): ?>
                    <div class="grade-info">
                        <div class="grade-display">
                            <div class="grade-score">
                                <?php echo $submission['grade']; ?> / <?php echo $submission['total_points']; ?>
                            </div>
                            <div class="grade-details">
                                <div class="grade-percentage">
                                    <?php echo round($submission['percentage'], 1); ?>%
                                </div>
                                <div class="grade-letter">
                                    <?php echo $submission['grade_letter']; ?>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 15px; font-size: 14px; opacity: 0.9;">
                            <i class="fas fa-user-tie"></i> Graded by: <?php echo $instructor_name; ?>
                            | <i class="far fa-clock"></i> Graded on: <?php echo date('F j, Y', strtotime($submission['graded_at'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Submission Content -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-file-alt"></i> Submission Content
                        </h2>
                    </div>

                    <?php if (!empty($submission['submission_text'])): ?>
                        <div class="submission-content">
                            <h3><i class="fas fa-keyboard"></i> Text Submission</h3>
                            <div class="text-preview">
                                <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($submission_files)): ?>
                        <div class="files-section">
                            <h3 style="color: #2c3e50; margin-bottom: 15px;">
                                <i class="fas fa-paperclip"></i> Attached Files (<?php echo count($submission_files); ?>)
                            </h3>
                            <div class="files-grid" id="filesGrid">
                                <?php foreach ($submission_files as $file):
                                    $extension = strtolower(pathinfo($file['file_url'], PATHINFO_EXTENSION));
                                    $icon = getFileIcon($extension);
                                    $file_url = BASE_URL . '' . $file['file_url'];
                                    $file_name = $file['file_name'] ?? basename($file['file_url']);
                                    $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                                ?>
                                    <div class="file-card">
                                        <div class="file-preview" <?php echo $is_image ? 'onclick="viewImage(\'' . $file_url . '\')"' : ''; ?>>
                                            <?php if ($is_image): ?>
                                                <img src="<?php echo $file_url; ?>" alt="<?php echo htmlspecialchars($file_name); ?>"
                                                    loading="lazy" data-original="<?php echo $file_url; ?>">
                                            <?php else: ?>
                                                <i class="<?php echo $icon; ?> file-icon"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name" title="<?php echo htmlspecialchars($file_name); ?>">
                                                <?php echo htmlspecialchars($file_name); ?>
                                            </div>
                                            <div class="file-meta">
                                                <span><?php echo strtoupper($extension); ?> File</span>
                                                <span><?php echo formatFileSize($file['file_size'] ?? 0); ?></span>
                                            </div>
                                            <div style="margin-top: 10px; display: flex; gap: 10px;">
                                                <a href="<?php echo $file_url; ?>" download class="btn btn-sm" style="padding: 5px 10px; font-size: 12px;">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <a href="<?php echo $file_url; ?>" target="_blank" class="btn btn-sm" style="padding: 5px 10px; font-size: 12px; background: #3498db; color: white;">
                                                    <i class="fas fa-external-link-alt"></i> Open
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($submission['submission_text']) && empty($submission_files)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Submission Content</h3>
                            <p>The student hasn't submitted any content for this assignment.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Feedback -->
                <?php if (!empty($submission['feedback']) || !empty($submission['gradebook_feedback'])): ?>
                    <div class="feedback-section">
                        <h3><i class="fas fa-comment"></i> Instructor Feedback</h3>
                        <div class="feedback-content">
                            <?php
                            $feedback = $submission['gradebook_feedback'] ?? $submission['feedback'];
                            echo nl2br(htmlspecialchars($feedback));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-content">
                <!-- Assignment Info -->
                <div class="assignment-info-card">
                    <h3 class="assignment-title"><?php echo htmlspecialchars($submission['assignment_title']); ?></h3>
                    <div class="assignment-meta">
                        <p><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($submission['course_title']); ?></p>
                        <p><i class="fas fa-users"></i> <?php echo htmlspecialchars($submission['batch_code']); ?></p>
                        <p><i class="far fa-calendar-alt"></i> Due: <?php echo date('M j, Y', strtotime($submission['due_date'])); ?></p>
                        <p><i class="fas fa-star"></i> <?php echo $submission['total_points']; ?> points</p>
                        <p><i class="fas fa-upload"></i> Type: <?php echo ucfirst($submission['submission_type']); ?></p>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-bolt"></i> Quick Actions
                        </h2>
                    </div>

                    <div class="quick-actions">
                        <?php if ($submission['grade'] === null): ?>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission_id; ?>"
                                class="btn btn-warning btn-full">
                                <i class="fas fa-check"></i> Grade This Submission
                            </a>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission_id; ?>"
                                class="btn btn-warning btn-full">
                                <i class="fas fa-edit"></i> Edit Grade
                            </a>
                        <?php endif; ?>

                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $submission['assignment_id']; ?>"
                            class="btn btn-primary btn-full">
                            <i class="fas fa-forward"></i> Grade Next Ungraded
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $submission['assignment_id']; ?>"
                            class="btn btn-secondary btn-full">
                            <i class="fas fa-eye"></i> View Assignment
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $submission['class_id']; ?>/students/progress.php?student_id=<?php echo $submission['student_id']; ?>"
                            class="btn btn-secondary btn-full">
                            <i class="fas fa-chart-line"></i> Student Progress
                        </a>
                    </div>
                </div>

                <!-- Submission History -->
                <?php if (!empty($other_submissions)): ?>
                    <div class="history-section">
                        <div class="history-title">
                            <i class="fas fa-history"></i> Submission History
                        </div>

                        <div class="history-list">
                            <?php foreach ($other_submissions as $other): ?>
                                <div class="history-item">
                                    <div class="history-date">
                                        <?php echo date('M j, Y g:i A', strtotime($other['submitted_at'])); ?>
                                        <?php if ($other['late_submission']): ?>
                                            <span style="color: #e74c3c; margin-left: 5px;">(Late)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="history-grade">
                                        <?php if ($other['grade'] !== null): ?>
                                            Grade: <?php echo $other['grade']; ?> / <?php echo $submission['total_points']; ?>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d;">Not graded</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top: 5px;">
                                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade_submission.php?submission_id=<?php echo $other['id']; ?>"
                                            class="btn btn-sm" style="padding: 3px 8px; font-size: 11px;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Image Viewer -->
    <div class="image-viewer" id="imageViewer">
        <div class="close-viewer" onclick="closeImageViewer()">
            <i class="fas fa-times"></i>
        </div>
        <img id="viewerImage" src="" alt="">
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.js"></script>
    <script>
        // Toggle mobile menu
        function toggleMenu() {
            const navLinks = document.getElementById('navLinks');
            navLinks.classList.toggle('show');
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const navLinks = document.getElementById('navLinks');
            const hamburger = document.querySelector('.hamburger-menu');
            
            if (!navLinks.contains(event.target) && !hamburger.contains(event.target)) {
                navLinks.classList.remove('show');
            }
        });

        // Image viewer functionality
        function viewImage(imageUrl) {
            const viewer = document.getElementById('imageViewer');
            const viewerImage = document.getElementById('viewerImage');

            viewerImage.src = imageUrl;
            viewer.style.display = 'flex';

            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
        }

        function closeImageViewer() {
            const viewer = document.getElementById('imageViewer');
            viewer.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close viewer on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageViewer();
            }
        });

        // Initialize image gallery viewer
        const images = document.querySelectorAll('#filesGrid img');
        const imageArray = Array.from(images).map(img => img.src);
        let currentImageIndex = 0;

        // Navigation between images
        document.addEventListener('keydown', function(e) {
            const viewer = document.getElementById('imageViewer');
            if (viewer.style.display === 'flex') {
                if (e.key === 'ArrowRight') {
                    currentImageIndex = (currentImageIndex + 1) % imageArray.length;
                    document.getElementById('viewerImage').src = imageArray[currentImageIndex];
                } else if (e.key === 'ArrowLeft') {
                    currentImageIndex = (currentImageIndex - 1 + imageArray.length) % imageArray.length;
                    document.getElementById('viewerImage').src = imageArray[currentImageIndex];
                }
            }
        });

        // Set current image index when opening
        images.forEach((img, index) => {
            img.addEventListener('click', function() {
                currentImageIndex = index;
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // G to go to grading
            if (e.key === 'g' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $submission_id; ?>';
            }

            // N for next ungraded
            if (e.key === 'n' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $submission['assignment_id']; ?>';
            }

            // A to go back to assignment
            if (e.key === 'a' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $submission['assignment_id']; ?>';
            }

            // S for student profile
            if (e.key === 's' && e.ctrlKey) {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $submission['class_id']; ?>/students/progress.php?student_id=<?php echo $submission['student_id']; ?>';
            }
        });

        // Print submission
        function printSubmission() {
            const printContent = document.querySelector('.main-content').innerHTML;
            const originalContent = document.body.innerHTML;

            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Submission - <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        .student-info { margin-bottom: 20px; }
                        .grade-display { background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                        .submission-content { margin-bottom: 30px; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Submission Review</h1>
                        <h2><?php echo htmlspecialchars($submission['assignment_title']); ?></h2>
                        <p><?php echo htmlspecialchars($submission['course_title'] . ' - ' . $submission['batch_code']); ?></p>
                    </div>
                    ${printContent}
                    <div class="no-print" style="margin-top: 50px; text-align: center;">
                        <button onclick="window.close()">Close Print View</button>
                    </div>
                </body>
                </html>
            `;

            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }

        // Download all files as ZIP
        function downloadAllFiles() {
            const fileIds = <?php echo json_encode(array_column($submission_files, 'id')); ?>;

            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/download_zip.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        submission_id: <?php echo $submission_id; ?>,
                        file_ids: fileIds
                    })
                })
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'submission_<?php echo $submission_id; ?>_files.zip';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                })
                .catch(error => {
                    console.error('Error downloading files:', error);
                    alert('Error downloading files. Please try again.');
                });
        }

        // Add print and download buttons to page header
        document.addEventListener('DOMContentLoaded', function() {
            const actionButtons = document.querySelector('.action-buttons');

            const printBtn = document.createElement('button');
            printBtn.className = 'btn btn-secondary';
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print';
            printBtn.onclick = printSubmission;

            const downloadBtn = document.createElement('button');
            downloadBtn.className = 'btn btn-primary';
            downloadBtn.innerHTML = '<i class="fas fa-file-archive"></i> Download All';
            downloadBtn.onclick = downloadAllFiles;

            actionButtons.insertBefore(downloadBtn, actionButtons.firstChild);
            actionButtons.insertBefore(printBtn, actionButtons.firstChild);
        });
    </script>
</body>
</html>