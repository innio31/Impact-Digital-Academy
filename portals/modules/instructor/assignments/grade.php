<?php
// modules/instructor/assignments/grade.php

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
if (!isset($_GET['assignment_id']) && !isset($_GET['submission_id'])) {
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/pending.php');
    exit();
}

$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
$instructor_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Initialize variables
$assignment = null;
$submission = null;
$student = null;
$class = null;
$submission_files = [];
$other_submissions = [];

// If grading a specific submission
if ($submission_id > 0) {
    // Get submission details
    $sql = "SELECT s.*, a.id as assignment_id, a.title as assignment_title, 
                   a.total_points, a.due_date, a.class_id, a.instructions,
                   u.id as student_id, u.first_name, u.last_name, u.email,
                   cb.batch_code, c.title as course_title
            FROM assignment_submissions s 
            JOIN assignments a ON s.assignment_id = a.id 
            JOIN users u ON s.student_id = u.id 
            JOIN class_batches cb ON a.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            WHERE s.id = ? AND a.instructor_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $submission_id, $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header('Location: ' . BASE_URL . 'modules/instructor/assignments/pending.php');
        exit();
    }

    $submission = $result->fetch_assoc();
    $assignment_id = $submission['assignment_id'];
    $stmt->close();

    // Get assignment details
    $sql = "SELECT a.*, cb.batch_code, c.title as course_title
            FROM assignments a 
            JOIN class_batches cb ON a.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            WHERE a.id = ? AND a.instructor_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $assignment_id, $instructor_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get submission files
    $sql = "SELECT * FROM submission_files WHERE submission_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $submission_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get other submissions for this assignment (for navigation)
    $sql = "SELECT s.id, s.student_id, s.grade, s.status, 
                   u.first_name, u.last_name
            FROM assignment_submissions s 
            JOIN users u ON s.student_id = u.id 
            WHERE s.assignment_id = ? AND s.id != ? 
            ORDER BY s.submitted_at";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $assignment_id, $submission_id);
    $stmt->execute();
    $other_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
// If grading all submissions for an assignment
elseif ($assignment_id > 0) {
    // Get assignment details
    $sql = "SELECT a.*, cb.batch_code, c.title as course_title
            FROM assignments a 
            JOIN class_batches cb ON a.class_id = cb.id 
            JOIN courses c ON cb.course_id = c.id 
            WHERE a.id = ? AND a.instructor_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $assignment_id, $instructor_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();

    if (!$assignment) {
        header('Location: ' . BASE_URL . 'modules/instructor/assignments/pending.php');
        exit();
    }
    $stmt->close();

    // Get all ungraded submissions for this assignment
    $sql = "SELECT s.*, u.id as student_id, u.first_name, u.last_name, u.email
            FROM assignment_submissions s 
            JOIN users u ON s.student_id = u.id 
            WHERE s.assignment_id = ? AND s.grade IS NULL AND s.status = 'submitted'
            ORDER BY s.submitted_at";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $ungraded_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // If there are ungraded submissions, redirect to the first one
    if (!empty($ungraded_submissions)) {
        $first_submission = $ungraded_submissions[0];
        header('Location: ' . BASE_URL . 'modules/instructor/assignments/grade.php?submission_id=' . $first_submission['id']);
        exit();
    } else {
        // No ungraded submissions, show success message
        $all_graded = true;
    }
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $submission_id > 0) {
    $grade = isset($_POST['grade']) ? floatval($_POST['grade']) : null;
    $feedback = isset($_POST['feedback']) ? trim($_POST['feedback']) : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'graded';
    $publish_to_student = isset($_POST['publish_to_student']) ? 1 : 0;

    // Validation
    if ($grade !== null && ($grade < 0 || $grade > $assignment['total_points'])) {
        $errors[] = "Grade must be between 0 and " . $assignment['total_points'];
    }

    if (empty($errors)) {
        // First, check if submission is late
        $late_submission = 0;
        if (!empty($submission['submitted_at']) && !empty($assignment['due_date'])) {
            $submitted_time = strtotime($submission['submitted_at']);
            $due_time = strtotime($assignment['due_date']);
            $late_submission = ($submitted_time > $due_time) ? 1 : 0;
        }

        // Update submission
        $sql = "UPDATE assignment_submissions SET 
                grade = ?, 
                feedback = ?, 
                status = ?, 
                graded_by = ?, 
                graded_at = NOW(),
                late_submission = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("dssiii", $grade, $feedback, $status, $instructor_id, $late_submission, $submission_id);

        if ($stmt->execute()) {
            // Update gradebook
            $percentage = $grade !== null ? ($grade / $assignment['total_points']) * 100 : null;
            $grade_letter = calculateGradeLetter($percentage);

            $sql_gradebook = "INSERT INTO gradebook (enrollment_id, assignment_id, student_id, 
                              score, max_score, percentage, grade_letter, weight, published, created_at)
                              VALUES (
                                  (SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?),
                                  ?, ?, ?, ?, ?, ?, 1.00, ?, NOW()
                              )
                              ON DUPLICATE KEY UPDATE 
                              score = ?, percentage = ?, grade_letter = ?, published = ?, updated_at = NOW()";

            $stmt_gradebook = $conn->prepare($sql_gradebook);
            $published_value = $publish_to_student ? 1 : 0;
            $stmt_gradebook->bind_param(
                "iiidddsidddsi",
                $submission['student_id'],
                $assignment['class_id'],
                $assignment_id,
                $submission['student_id'],
                $grade,
                $assignment['total_points'],
                $percentage,
                $grade_letter,
                $published_value,
                $grade,
                $percentage,
                $grade_letter,
                $published_value
            );
            $stmt_gradebook->execute();
            $stmt_gradebook->close();

            // Send notification to student - temporarily commented out to fix
            // if ($publish_to_student) {
            //     sendGradeNotification($submission['student_id'], $assignment_id, $grade, $conn);
            // }

            // Log activity
            logActivity('assignment_graded', "Graded submission #{$submission_id}", $submission_id);

            $success = true;

            // Get next submission if in batch mode
            $next_submission_id = null;
            if (isset($_POST['save_and_next']) && !empty($other_submissions)) {
                foreach ($other_submissions as $other) {
                    if ($other['grade'] === null) {
                        $next_submission_id = $other['id'];
                        break;
                    }
                }
            }
        } else {
            $errors[] = "Failed to save grade. Please try again.";
        }
        $stmt->close();
    }
}

$conn->close();

// If success and next submission exists, redirect
if ($success && isset($next_submission_id)) {
    header('Location: ' . BASE_URL . 'modules/instructor/assignments/grade.php?submission_id=' . $next_submission_id);
    exit();
}

// Calculate time since submission
if ($submission) {
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
}

// Get instructor name for display
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Submission - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/simplemde/1.11.2/simplemde.min.css">
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
            min-height: 100vh;
        }

        .main-header {
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white;
            padding: 0 20px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
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
            height: 70px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 28px;
            color: #3498db;
        }

        .logo h1 {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(to right, #3498db, #2ecc71);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
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
            background: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
        }

        .user-details h3 {
            font-size: 14px;
            font-weight: 600;
        }

        .user-details p {
            font-size: 12px;
            opacity: 0.8;
        }

        .nav-menu {
            display: flex;
            gap: 5px;
        }

        .nav-link {
            padding: 10px 20px;
            color: #ecf0f1;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 14px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: #3498db;
            color: white;
        }

        .grade-container {
            padding: 20px;
            max-width: 1200px;
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
            background: #27ae60;
            color: white;
        }

        .btn-primary:hover {
            background: #219653;
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .main-content {
            display: flex;
            flex-direction: column;
            gap: 25px;
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

        .submission-content {
            line-height: 1.8;
            color: #34495e;
            font-size: 15px;
            margin-bottom: 30px;
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
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .file-card {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .file-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .file-preview {
            height: 100px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #7f8c8d;
        }

        .file-info {
            padding: 10px;
            background: white;
        }

        .file-name {
            font-size: 12px;
            color: #2c3e50;
            margin-bottom: 5px;
            word-break: break-all;
        }

        .file-size {
            font-size: 11px;
            color: #7f8c8d;
        }

        .grading-form {
            margin-top: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }

        .grade-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .grade-input {
            width: 120px;
            padding: 12px;
            border: 2px solid #3498db;
            border-radius: 4px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }

        .max-grade {
            font-size: 14px;
            color: #7f8c8d;
        }

        .grade-slider {
            width: 100%;
            margin: 15px 0;
            -webkit-appearance: none;
            height: 8px;
            border-radius: 4px;
            background: #e0e0e0;
            outline: none;
        }

        .grade-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #3498db;
            cursor: pointer;
        }

        .grade-percentage {
            font-size: 24px;
            font-weight: 700;
            color: #27ae60;
            text-align: center;
            margin-top: 10px;
        }

        .grade-letter {
            font-size: 32px;
            font-weight: 900;
            color: #2c3e50;
            text-align: center;
            margin-top: 5px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
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

        .navigation-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        .navigation-title {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .submission-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .submission-nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            text-decoration: none;
            color: #555;
            transition: all 0.3s ease;
        }

        .submission-nav-item:hover {
            background: #f8f9fa;
            color: #3498db;
        }

        .submission-nav-item.current {
            background: #e3f2fd;
            color: #1976d2;
            border-left: 4px solid #1976d2;
        }

        .student-name {
            font-weight: 500;
        }

        .grade-status {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

        .status-ungraded {
            background: #f8d7da;
            color: #721c24;
        }

        .status-graded {
            background: #d4edda;
            color: #155724;
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

        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d5edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert i {
            font-size: 18px;
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

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            margin: 0;
        }

        .status-select {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        .status-option {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                height: auto;
                padding: 15px 0;
            }

            .nav-menu {
                margin-top: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .user-menu {
                margin-top: 15px;
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

            .student-info-card {
                flex-direction: column;
                text-align: center;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        .preview-comments {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
        }

        .preview-comments h4 {
            margin: 0 0 10px 0;
            color: #856404;
        }

        .comments-preview {
            font-size: 14px;
            color: #856404;
            line-height: 1.5;
        }

        .footer {
            margin-top: 40px;
            padding: 20px;
            text-align: center;
            color: #7f8c8d;
            font-size: 14px;
            border-top: 1px solid #e0e0e0;
        }
    </style>
</head>

<body>
    <!-- Main Header -->
    <header class="main-header">
        <div class="header-container">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h1>Impact Digital Academy</h1>
            </div>

            <nav class="nav-menu">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="nav-link active">
                    <i class="fas fa-tasks"></i> Grade Assignments
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/courses.php" class="nav-link">
                    <i class="fas fa-chalkboard"></i> My Courses
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/gradebook.php" class="nav-link">
                    <i class="fas fa-book"></i> Gradebook
                </a>
            </nav>

            <div class="user-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h3><?php echo htmlspecialchars($instructor_name); ?></h3>
                        <p>Instructor</p>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="btn btn-secondary" style="padding: 8px 15px; font-size: 13px;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <div class="grade-container">
        <!-- Success/Error Messages -->
        <?php if ($success && !isset($next_submission_id)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Grade saved successfully!
                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/submissions.php?assignment_id=<?php echo $assignment_id; ?>"
                    style="color: #155724; text-decoration: underline; margin-left: 10px;">
                    View All Submissions
                </a>
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($all_graded) && $all_graded): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>All Submissions Graded!</h3>
                <p>All submissions for this assignment have been graded.</p>
                <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment_id; ?>"
                        class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Assignment
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php"
                        class="btn btn-secondary">
                        <i class="fas fa-tasks"></i> More Pending
                    </a>
                </div>
            </div>
        <?php elseif ($submission): ?>
            <div class="page-header">
                <div class="page-title">
                    <h1>Grade Submission</h1>
                    <p>Review and evaluate student work</p>
                </div>

                <div class="action-buttons">
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/pending.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Pending
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment_id; ?>"
                        class="btn btn-secondary">
                        <i class="fas fa-eye"></i> View Assignment
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
                                <span>Due: <?php echo date('F j, Y g:i A', strtotime($assignment['due_date'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-star"></i>
                                <span>Max Points: <?php echo $assignment['total_points']; ?></span>
                            </div>
                            <?php if ($submission['late_submission']): ?>
                                <div class="meta-item" style="color: #e74c3c;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Late Submission</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Submission Content -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-file-alt"></i> Submission Content
                            </h2>
                        </div>

                        <?php if (!empty($submission['submission_text'])): ?>
                            <div class="submission-content">
                                <h3>Student's Response:</h3>
                                <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($submission_files)): ?>
                            <div class="files-section">
                                <h3 style="color: #2c3e50; margin-bottom: 15px;">
                                    <i class="fas fa-paperclip"></i> Attached Files
                                </h3>
                                <div class="files-grid">
                                    <?php foreach ($submission_files as $file):
                                        $extension = pathinfo($file['file_url'], PATHINFO_EXTENSION);
                                        $icon = getFileIcon($extension);
                                    ?>
                                        <a href="<?php echo BASE_URL . 'uploads/assignments/' . $file['file_url']; ?>"
                                            target="_blank" class="file-card">
                                            <div class="file-preview">
                                                <i class="<?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="file-info">
                                                <div class="file-name">
                                                    <?php echo htmlspecialchars($file['file_name'] ?? basename($file['file_url'])); ?>
                                                </div>
                                                <div class="file-size">
                                                    <?php echo formatFileSize($file['file_size'] ?? 0); ?>
                                                </div>
                                            </div>
                                        </a>
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

                    <!-- Grading Form -->
                    <form method="POST" action="" class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-check-circle"></i> Grading
                            </h2>
                        </div>

                        <div class="grading-form">
                            <!-- Grade Input -->
                            <div class="form-group">
                                <label for="grade" class="form-label">Grade (Points)</label>
                                <div class="grade-input-group">
                                    <input type="number" name="grade" id="grade" class="grade-input"
                                        value="<?php echo $submission['grade'] ?? ''; ?>"
                                        min="0" max="<?php echo $assignment['total_points']; ?>"
                                        step="0.5" placeholder="0.0">
                                    <span class="max-grade">out of <?php echo $assignment['total_points']; ?></span>
                                </div>

                                <input type="range" id="gradeSlider" class="grade-slider"
                                    min="0" max="<?php echo $assignment['total_points']; ?>"
                                    step="0.5" value="<?php echo $submission['grade'] ?? 0; ?>">

                                <div id="gradePercentage" class="grade-percentage">
                                    <?php if ($submission['grade'] !== null): ?>
                                        <?php echo round(($submission['grade'] / $assignment['total_points']) * 100, 1); ?>%
                                    <?php else: ?>
                                        0%
                                    <?php endif; ?>
                                </div>

                                <div id="gradeLetter" class="grade-letter">
                                    <?php if ($submission['grade'] !== null):
                                        $percentage = ($submission['grade'] / $assignment['total_points']) * 100;
                                        echo calculateGradeLetter($percentage);
                                    else: ?>
                                        F
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Feedback -->
                            <div class="form-group">
                                <label for="feedback" class="form-label">Feedback for Student</label>
                                <textarea name="feedback" id="feedback" class="form-control"
                                    placeholder="Provide constructive feedback to help the student improve..."><?php echo htmlspecialchars($submission['feedback'] ?? ''); ?></textarea>
                                <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">
                                    <i class="fas fa-info-circle"></i> This feedback will be visible to the student
                                </div>

                                <!-- Feedback Preview -->
                                <div class="preview-comments" id="feedbackPreview" style="display: none;">
                                    <h4><i class="fas fa-eye"></i> Preview</h4>
                                    <div class="comments-preview" id="previewContent"></div>
                                </div>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label class="form-label">Submission Status</label>
                                <div class="status-select">
                                    <div class="status-option">
                                        <input type="radio" name="status" id="status_graded" value="graded"
                                            <?php echo ($submission['status'] === 'graded' || $submission['status'] === 'submitted') ? 'checked' : ''; ?>>
                                        <label for="status_graded">Graded</label>
                                    </div>
                                    <div class="status-option">
                                        <input type="radio" name="status" id="status_late" value="late"
                                            <?php echo ($submission['status'] === 'late') ? 'checked' : ''; ?>>
                                        <label for="status_late">Late (Graded)</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Publish Options -->
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="publish_to_student" id="publish_to_student" value="1" checked>
                                    <label for="publish_to_student" style="font-weight: 600; color: #2c3e50;">
                                        Make grade and feedback visible to student
                                    </label>
                                </div>
                                <div style="font-size: 12px; color: #7f8c8d; margin-left: 25px;">
                                    <i class="fas fa-info-circle"></i> Student will receive a notification
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <button type="submit" name="save" class="btn btn-secondary">
                                    <i class="fas fa-save"></i> Save Grade
                                </button>
                                <button type="submit" name="save_and_next" class="btn btn-primary">
                                    <i class="fas fa-forward"></i> Save & Grade Next
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Sidebar -->
                <div class="sidebar-content">
                    <!-- Assignment Info -->
                    <div class="assignment-info-card">
                        <h3 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <div class="assignment-meta">
                            <p><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($submission['course_title']); ?></p>
                            <p><i class="fas fa-users"></i> <?php echo htmlspecialchars($submission['batch_code']); ?></p>
                            <p><i class="far fa-calendar-alt"></i> Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></p>
                            <p><i class="fas fa-star"></i> <?php echo $assignment['total_points']; ?> points</p>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="navigation-card">
                        <div class="navigation-title">
                            <i class="fas fa-list"></i> Other Submissions
                        </div>

                        <div class="submission-list">
                            <?php if (empty($other_submissions)): ?>
                                <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                    <i class="fas fa-user-friends"></i>
                                    <p style="margin-top: 10px;">No other submissions</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($other_submissions as $other): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?submission_id=<?php echo $other['id']; ?>"
                                        class="submission-nav-item <?php echo $other['id'] == $submission_id ? 'current' : ''; ?>">
                                        <span class="student-name">
                                            <?php echo htmlspecialchars($other['first_name'] . ' ' . $other['last_name']); ?>
                                        </span>
                                        <span class="grade-status status-<?php echo $other['grade'] ? 'graded' : 'ungraded'; ?>">
                                            <?php echo $other['grade'] ? $other['grade'] : 'Ungraded'; ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="navigation-card">
                        <div class="navigation-title">
                            <i class="fas fa-bolt"></i> Quick Actions
                        </div>

                        <div class="quick-actions">
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/grade.php?assignment_id=<?php echo $assignment_id; ?>"
                                class="btn btn-warning btn-full">
                                <i class="fas fa-forward"></i> Grade Next Ungraded
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/view.php?id=<?php echo $assignment_id; ?>"
                                class="btn btn-secondary btn-full">
                                <i class="fas fa-eye"></i> View Assignment
                            </a>
                            <button type="button" onclick="useRubric()" class="btn btn-secondary btn-full">
                                <i class="fas fa-list-check"></i> Apply Rubric
                            </button>
                            <button type="button" onclick="previewFeedback()" class="btn btn-secondary btn-full">
                                <i class="fas fa-eye"></i> Preview Feedback
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Simple Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> EduManage - Learning Management System</p>
        <p style="font-size: 12px; margin-top: 5px; opacity: 0.7;">Instructor Grading Interface</p>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/simplemde/1.11.2/simplemde.min.js"></script>
    <script>
        // Initialize Markdown editor for feedback
        const simplemde = new SimpleMDE({
            element: document.getElementById("feedback"),
            spellChecker: false,
            toolbar: ["bold", "italic", "heading", "|", "quote", "unordered-list", "ordered-list", "|", "link", "preview", "guide"],
            placeholder: "Provide constructive feedback to help the student improve..."
        });

        // Grade slider functionality
        const gradeInput = document.getElementById('grade');
        const gradeSlider = document.getElementById('gradeSlider');
        const gradePercentage = document.getElementById('gradePercentage');
        const gradeLetter = document.getElementById('gradeLetter');
        const maxPoints = <?php echo $assignment['total_points']; ?>;

        function updateGradeDisplay(value) {
            const percentage = (value / maxPoints) * 100;
            gradePercentage.textContent = percentage.toFixed(1) + '%';

            // Calculate grade letter
            let letter = 'F';
            if (percentage >= 90) letter = 'A';
            else if (percentage >= 80) letter = 'B';
            else if (percentage >= 70) letter = 'C';
            else if (percentage >= 60) letter = 'D';

            gradeLetter.textContent = letter;

            // Update color based on grade
            if (percentage >= 90) {
                gradePercentage.style.color = '#27ae60';
                gradeLetter.style.color = '#27ae60';
            } else if (percentage >= 70) {
                gradePercentage.style.color = '#f39c12';
                gradeLetter.style.color = '#f39c12';
            } else {
                gradePercentage.style.color = '#e74c3c';
                gradeLetter.style.color = '#e74c3c';
            }
        }

        // Sync grade input and slider
        gradeInput.addEventListener('input', function() {
            const value = parseFloat(this.value) || 0;
            gradeSlider.value = value;
            updateGradeDisplay(value);
        });

        gradeSlider.addEventListener('input', function() {
            const value = parseFloat(this.value);
            gradeInput.value = value;
            updateGradeDisplay(value);
        });

        // Initialize display
        updateGradeDisplay(parseFloat(gradeInput.value) || 0);

        // Common feedback templates
        const feedbackTemplates = {
            excellent: "Excellent work! Your submission demonstrates a thorough understanding of the concepts and goes above and beyond the requirements.",
            good: "Good job! You've met all the requirements and demonstrated a solid understanding of the material.",
            average: "You've completed the assignment adequately. Consider reviewing the key concepts to improve your understanding.",
            needs_improvement: "Your submission needs improvement. Please review the assignment instructions and consider resubmitting after making corrections.",
            late: "Note: This submission was received after the due date."
        };

        // Apply feedback template
        function applyFeedbackTemplate(templateKey) {
            const currentFeedback = simplemde.value();
            const template = feedbackTemplates[templateKey];

            if (currentFeedback) {
                simplemde.value(currentFeedback + "\n\n" + template);
            } else {
                simplemde.value(template);
            }
        }

        // Use rubric
        function useRubric() {
            const rubricScore = prompt('Enter rubric score (0-' + maxPoints + '):');
            if (rubricScore !== null) {
                const score = parseFloat(rubricScore);
                if (!isNaN(score) && score >= 0 && score <= maxPoints) {
                    gradeInput.value = score;
                    gradeSlider.value = score;
                    updateGradeDisplay(score);

                    // Apply appropriate feedback template
                    const percentage = (score / maxPoints) * 100;
                    if (percentage >= 90) {
                        applyFeedbackTemplate('excellent');
                    } else if (percentage >= 70) {
                        applyFeedbackTemplate('good');
                    } else if (percentage >= 50) {
                        applyFeedbackTemplate('average');
                    } else {
                        applyFeedbackTemplate('needs_improvement');
                    }

                    if (<?php echo $submission['late_submission'] ? 'true' : 'false'; ?>) {
                        applyFeedbackTemplate('late');
                    }
                }
            }
        }

        // Preview feedback
        function previewFeedback() {
            const feedback = simplemde.value();
            const previewContent = document.getElementById('previewContent');
            const feedbackPreview = document.getElementById('feedbackPreview');

            if (feedback.trim()) {
                // Convert markdown to HTML (simplified)
                let html = feedback
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>')
                    .replace(/^# (.*$)/gm, '<h4>$1</h4>')
                    .replace(/^## (.*$)/gm, '<h5>$1</h5>')
                    .replace(/\n/g, '<br>');

                previewContent.innerHTML = html;
                feedbackPreview.style.display = 'block';

                // Scroll to preview
                feedbackPreview.scrollIntoView({
                    behavior: 'smooth'
                });
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[name="save"]').click();
            }

            // Ctrl+N to save and next
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                document.querySelector('button[name="save_and_next"]').click();
            }

            // Number keys 1-5 for quick grades
            if (e.key >= '1' && e.key <= '5' && e.ctrlKey) {
                e.preventDefault();
                const percentage = (parseInt(e.key) / 5) * 100;
                const grade = (percentage / 100) * maxPoints;
                gradeInput.value = grade.toFixed(1);
                gradeSlider.value = grade;
                updateGradeDisplay(grade);
            }

            // Tab to navigate between submissions
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                const currentLink = document.querySelector('.submission-nav-item.current');
                const nextLink = currentLink ? currentLink.nextElementSibling : document.querySelector('.submission-nav-item');

                if (nextLink && nextLink.href) {
                    window.location.href = nextLink.href;
                }
            }

            // Shift+Tab to navigate backward
            if (e.key === 'Tab' && e.shiftKey) {
                e.preventDefault();
                const currentLink = document.querySelector('.submission-nav-item.current');
                const prevLink = currentLink ? currentLink.previousElementSibling : null;

                if (prevLink && prevLink.href) {
                    window.location.href = prevLink.href;
                }
            }
        });

        // Auto-save draft
        let autoSaveTimer;

        function autoSaveDraft() {
            const formData = new FormData(document.querySelector('form'));
            formData.append('auto_save', '1');
            formData.append('submission_id', <?php echo $submission_id; ?>);

            fetch('<?php echo BASE_URL; ?>modules/instructor/assignments/autosave_grade.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Grade draft auto-saved at:', new Date().toLocaleTimeString());
                    }
                })
                .catch(error => console.error('Auto-save error:', error));
        }

        // Auto-save every 30 seconds
        setInterval(autoSaveDraft, 30000);

        // Warn before leaving with unsaved changes
        let hasUnsavedChanges = false;

        gradeInput.addEventListener('input', () => hasUnsavedChanges = true);
        gradeSlider.addEventListener('input', () => hasUnsavedChanges = true);
        simplemde.codemirror.on('change', () => hasUnsavedChanges = true);

        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            hasUnsavedChanges = false;

            // Validate grade
            const grade = parseFloat(gradeInput.value);
            if (isNaN(grade) || grade < 0 || grade > maxPoints) {
                e.preventDefault();
                alert('Please enter a valid grade between 0 and ' + maxPoints);
                gradeInput.focus();
                return false;
            }
        });
    </script>
</body>

</html>