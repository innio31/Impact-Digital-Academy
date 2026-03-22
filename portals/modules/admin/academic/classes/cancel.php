<?php
// modules/admin/academic/classes/cancel.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/email_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get class ID
$class_id = $_GET['id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class data to verify existence and current status
$class_sql = "SELECT cb.*, 
             c.title as course_title,
             p.name as program_name,
             u.first_name as instructor_first_name,
             u.last_name as instructor_last_name,
             u.email as instructor_email
      FROM class_batches cb
      JOIN courses c ON cb.course_id = c.id
      JOIN programs p ON c.program_id = p.id
      LEFT JOIN users u ON cb.instructor_id = u.id
      WHERE cb.id = ?";

$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param('i', $class_id);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class = $class_result->fetch_assoc();

if (!$class) {
    $_SESSION['error'] = 'Class not found.';
    header('Location: list.php');
    exit();
}

// If already cancelled, redirect
if ($class['status'] === 'cancelled') {
    $_SESSION['error'] = 'This class is already cancelled.';
    header('Location: view.php?id=' . $class_id);
    exit();
}

// Process cancellation when form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cancel'])) {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: cancel.php?id=' . $class_id);
        exit();
    }

    // Start transaction for data cleanup
    $conn->begin_transaction();

    try {
        // 1. Get all enrollments for this class (active students)
        $enrollments_sql = "SELECT e.id as enrollment_id, e.student_id, u.first_name, u.last_name, u.email
                           FROM enrollments e
                           JOIN users u ON e.student_id = u.id
                           WHERE e.class_id = ?";
        $enrollments_stmt = $conn->prepare($enrollments_sql);
        $enrollments_stmt->bind_param('i', $class_id);
        $enrollments_stmt->execute();
        $enrollments = $enrollments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $enrollments_stmt->close();

        // 2. Delete assignment submissions for this class
        $del_submissions_sql = "DELETE assignment_submissions 
                               FROM assignment_submissions 
                               INNER JOIN assignments ON assignment_submissions.assignment_id = assignments.id 
                               WHERE assignments.class_id = ?";
        $submissions_stmt = $conn->prepare($del_submissions_sql);
        $submissions_stmt->bind_param('i', $class_id);
        $submissions_stmt->execute();
        $submissions_stmt->close();

        // 3. Delete gradebook entries
        $del_gradebook_sql = "DELETE gradebook 
                             FROM gradebook 
                             INNER JOIN assignments ON gradebook.assignment_id = assignments.id 
                             WHERE assignments.class_id = ?";
        $gradebook_stmt = $conn->prepare($del_gradebook_sql);
        $gradebook_stmt->bind_param('i', $class_id);
        $gradebook_stmt->execute();
        $gradebook_stmt->close();

        // 4. Delete quiz attempts
        $del_quiz_attempts_sql = "DELETE quiz_attempts 
                                 FROM quiz_attempts 
                                 INNER JOIN quizzes ON quiz_attempts.quiz_id = quizzes.id 
                                 WHERE quizzes.class_id = ?";
        $quiz_stmt = $conn->prepare($del_quiz_attempts_sql);
        $quiz_stmt->bind_param('i', $class_id);
        $quiz_stmt->execute();
        $quiz_stmt->close();

        // 5. Delete attendance records
        $del_attendance_sql = "DELETE attendance 
                              FROM attendance 
                              INNER JOIN enrollments ON attendance.enrollment_id = enrollments.id 
                              WHERE enrollments.class_id = ?";
        $attendance_stmt = $conn->prepare($del_attendance_sql);
        $attendance_stmt->bind_param('i', $class_id);
        $attendance_stmt->execute();
        $attendance_stmt->close();

        // 6. Delete financial status entries
        $del_financial_sql = "DELETE FROM student_financial_status WHERE class_id = ?";
        $financial_stmt = $conn->prepare($del_financial_sql);
        $financial_stmt->bind_param('i', $class_id);
        $financial_stmt->execute();
        $financial_stmt->close();

        // 7. Delete course payments
        $del_payments_sql = "DELETE FROM course_payments WHERE class_id = ?";
        $payments_stmt = $conn->prepare($del_payments_sql);
        $payments_stmt->bind_param('i', $class_id);
        $payments_stmt->execute();
        $payments_stmt->close();

        // 8. Delete discussion replies
        $del_replies_sql = "DELETE discussion_replies 
                           FROM discussion_replies 
                           INNER JOIN discussions ON discussion_replies.discussion_id = discussions.id 
                           WHERE discussions.class_id = ?";
        $replies_stmt = $conn->prepare($del_replies_sql);
        $replies_stmt->bind_param('i', $class_id);
        $replies_stmt->execute();
        $replies_stmt->close();

        // 9. Delete discussions
        $del_discussions_sql = "DELETE FROM discussions WHERE class_id = ?";
        $discussions_stmt = $conn->prepare($del_discussions_sql);
        $discussions_stmt->bind_param('i', $class_id);
        $discussions_stmt->execute();
        $discussions_stmt->close();

        // 10. Delete assignments (after submissions and gradebook are removed)
        $del_assignments_sql = "DELETE FROM assignments WHERE class_id = ?";
        $assignments_stmt = $conn->prepare($del_assignments_sql);
        $assignments_stmt->bind_param('i', $class_id);
        $assignments_stmt->execute();
        $assignments_stmt->close();

        // 11. Delete materials
        // Get material file paths to delete from server
        $materials_sql = "SELECT file_url FROM materials WHERE class_id = ?";
        $materials_stmt = $conn->prepare($materials_sql);
        $materials_stmt->bind_param('i', $class_id);
        $materials_stmt->execute();
        $materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $materials_stmt->close();

        // Delete material files from server
        foreach ($materials as $material) {
            if (!empty($material['file_url'])) {
                $file_path = dirname(__DIR__, 4) . '/public/uploads/' . $material['file_url'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }

        // Delete material records
        $del_materials_sql = "DELETE FROM materials WHERE class_id = ?";
        $del_materials_stmt = $conn->prepare($del_materials_sql);
        $del_materials_stmt->bind_param('i', $class_id);
        $del_materials_stmt->execute();
        $del_materials_stmt->close();

        // 12. Delete enrollments (after all related data is removed)
        $del_enrollments_sql = "DELETE FROM enrollments WHERE class_id = ?";
        $del_enrollments_stmt = $conn->prepare($del_enrollments_sql);
        $del_enrollments_stmt->bind_param('i', $class_id);
        $del_enrollments_stmt->execute();
        $del_enrollments_stmt->close();

        // 13. Update class status to cancelled
        $update_sql = "UPDATE class_batches 
                       SET status = 'cancelled', 
                           updated_at = NOW()
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $class_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Commit transaction
        $conn->commit();

        // Log activity
        logActivity(
            $_SESSION['user_id'],
            'class_cancelled',
            "Cancelled class #$class_id: {$class['batch_code']} - {$class['name']}",
            'class_batches',
            $class_id
        );

        // Send cancellation emails to enrolled students (don't stop if emails fail)
        $email_success_count = 0;
        $email_failure_count = 0;

        foreach ($enrollments as $enrollment) {
            $student_id = $enrollment['student_id'];
            $student_name = $enrollment['first_name'] . ' ' . $enrollment['last_name'];

            // Check if sendClassCancellationEmail function exists
            if (function_exists('sendClassCancellationEmail')) {
                $email_sent = sendClassCancellationEmail($student_id, $class_id, $student_name);

                if ($email_sent) {
                    $email_success_count++;
                    logActivity('class_cancellation_email', "Cancellation email sent to student #{$student_id} for class #{$class_id}");
                } else {
                    $email_failure_count++;
                    error_log("Failed to send cancellation email to student #{$student_id} for class #{$class_id}");
                }
            } else {
                error_log("sendClassCancellationEmail function does not exist");
            }
        }

        // Send notification to instructor
        if ($class['instructor_id']) {
            if (function_exists('sendInstructorClassCancellationNotification')) {
                $instructor_email_sent = sendInstructorClassCancellationNotification(
                    $class['instructor_id'],
                    $class_id,
                    $class['batch_code'] . ' - ' . $class['name']
                );

                if ($instructor_email_sent) {
                    logActivity('instructor_cancellation_notification', "Cancellation notification email sent to instructor #{$class['instructor_id']} for class #{$class_id}");
                } else {
                    error_log("Failed to send cancellation notification email to instructor #{$class['instructor_id']} for class #{$class_id}");
                }
            } else {
                error_log("sendInstructorClassCancellationNotification function does not exist");
            }
        }

        // Set success message with email statistics
        $success_message = "Class cancelled successfully.";
        if ($email_success_count > 0) {
            $success_message .= " Notification emails sent to {$email_success_count} student(s).";
        }
        if ($email_failure_count > 0) {
            $success_message .= " Failed to send emails to {$email_failure_count} student(s).";
        }

        $_SESSION['success'] = $success_message;

        // Redirect to class list
        header('Location: list.php');
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();

        $_SESSION['error'] = 'Error cancelling class: ' . $e->getMessage();
        error_log("Class cancellation error for class #$class_id: " . $e->getMessage());

        header('Location: view.php?id=' . $class_id);
        exit();
    }
}

// Get additional stats for the confirmation page
$stats_sql = "SELECT 
                COUNT(DISTINCT e.id) as enrolled_students,
                COUNT(DISTINCT m.id) as total_materials,
                COUNT(DISTINCT a.id) as total_assignments
              FROM class_batches cb
              LEFT JOIN enrollments e ON cb.id = e.class_id
              LEFT JOIN materials m ON cb.id = m.class_id
              LEFT JOIN assignments a ON cb.id = a.class_id
              WHERE cb.id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param('i', $class_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

$enrolled_students = $stats['enrolled_students'] ?? 0;
$total_materials = $stats['total_materials'] ?? 0;
$total_assignments = $stats['total_assignments'] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Cancel Class - <?php echo htmlspecialchars($class['batch_code']); ?></title>
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
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--gray-800);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            background: var(--dark);
            color: white;
            width: 100%;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.25rem;
            color: white;
        }

        .sidebar-header p {
            display: none;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            display: block;
        }

        .sidebar-nav {
            display: none;
            max-height: calc(100vh - 70px);
            overflow-y: auto;
        }

        .sidebar-nav.show {
            display: block;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 0.5rem 0;
        }

        .sidebar-nav li {
            margin: 0;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        /* Cancellation Card */
        .cancel-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .cancel-header {
            background: #fef2f2;
            padding: 1.5rem;
            border-bottom: 1px solid #fecaca;
            text-align: center;
        }

        .cancel-header i {
            font-size: 3rem;
            color: var(--danger);
            margin-bottom: 0.75rem;
        }

        .cancel-header h2 {
            color: var(--danger);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .cancel-header p {
            color: #64748b;
            font-size: 0.9rem;
        }

        .class-summary {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .class-summary h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .summary-item {
            display: flex;
            flex-direction: column;
        }

        .summary-label {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.15rem;
        }

        .summary-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
            word-break: break-word;
        }

        .warning-box {
            background: #fffbeb;
            border-left: 4px solid var(--warning);
            padding: 1rem 1.5rem;
            margin: 1rem 1.5rem;
            border-radius: 8px;
        }

        .warning-box h4 {
            color: #b45309;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .warning-box ul {
            margin-left: 1.5rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .warning-box li {
            margin: 0.5rem 0;
        }

        .stats-box {
            background: var(--gray-100);
            padding: 1rem 1.5rem;
            margin: 1rem 1.5rem;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stat-badge {
            text-align: center;
            flex: 1;
            min-width: 80px;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.7rem;
            color: #64748b;
        }

        .form-actions {
            padding: 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
            border-top: 1px solid var(--gray-200);
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-danger:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.85rem;
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

        /* Tablet and Desktop Breakpoints */
        @media (min-width: 768px) {
            .sidebar {
                width: 250px;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar-header {
                padding: 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .sidebar-header h2 {
                font-size: 1.5rem;
            }

            .sidebar-header p {
                display: block;
                color: #94a3b8;
                font-size: 0.9rem;
            }

            .menu-toggle {
                display: none;
            }

            .sidebar-nav {
                display: block;
            }

            .sidebar-nav a {
                padding: 0.75rem 1.5rem;
            }

            .main-content {
                margin-left: 250px;
                padding: 2rem;
            }

            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .summary-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div>
                    <h2>Impact Academy</h2>
                    <p>Admin Dashboard</p>
                </div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <nav class="sidebar-nav" id="sidebarNav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="active">
                            <i class="fas fa-chalkboard-teacher"></i> Classes</a></li>
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php">Classes</a> &rsaquo;
                        <a href="view.php?id=<?php echo $class_id; ?>"><?php echo htmlspecialchars($class['batch_code']); ?></a> &rsaquo;
                        Cancel Class
                    </div>
                    <h1>Cancel Class</h1>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Class
                    </a>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Cancellation Confirmation Card -->
            <div class="cancel-card">
                <div class="cancel-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h2>Confirm Class Cancellation</h2>
                    <p>This action will permanently cancel this class and remove all associated data.</p>
                </div>

                <div class="class-summary">
                    <h3>Class Information</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">Class Name</span>
                            <span class="summary-value"><?php echo htmlspecialchars($class['name']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Batch Code</span>
                            <span class="summary-value"><?php echo htmlspecialchars($class['batch_code']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Course</span>
                            <span class="summary-value"><?php echo htmlspecialchars($class['course_title']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Program</span>
                            <span class="summary-value"><?php echo htmlspecialchars($class['program_name']); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Start Date</span>
                            <span class="summary-value"><?php echo date('M j, Y', strtotime($class['start_date'])); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">End Date</span>
                            <span class="summary-value"><?php echo date('M j, Y', strtotime($class['end_date'])); ?></span>
                        </div>
                        <?php if ($class['instructor_first_name']): ?>
                            <div class="summary-item">
                                <span class="summary-label">Instructor</span>
                                <span class="summary-value"><?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="summary-item">
                            <span class="summary-label">Current Status</span>
                            <span class="summary-value">
                                <span class="status-badge status-<?php echo $class['status']; ?>">
                                    <?php echo ucfirst($class['status']); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="warning-box">
                    <h4><i class="fas fa-skull-crosswalk"></i> Warning: This action cannot be undone!</h4>
                    <ul>
                        <li>All <strong><?php echo $enrolled_students; ?> student(s)</strong> will be unenrolled from this class.</li>
                        <li>All <strong><?php echo $total_materials; ?> materials</strong> will be permanently deleted from the server.</li>
                        <li>All <strong><?php echo $total_assignments; ?> assignments</strong> and student submissions will be deleted.</li>
                        <li>All attendance records, grades, and quiz attempts will be removed.</li>
                        <li>Students will receive an email notification about the cancellation.</li>
                    </ul>
                </div>

                <div class="stats-box">
                    <div class="stat-badge">
                        <div class="stat-number"><?php echo $enrolled_students; ?></div>
                        <div class="stat-label">Enrolled Students</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-number"><?php echo $total_materials; ?></div>
                        <div class="stat-label">Materials</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-number"><?php echo $total_assignments; ?></div>
                        <div class="stat-label">Assignments</div>
                    </div>
                </div>

                <form method="POST" id="cancelForm" onsubmit="return confirmCancellation(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="confirm_cancel" value="1">

                    <div class="form-actions">
                        <a href="view.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> No, Go Back
                        </a>
                        <button type="submit" class="btn btn-danger" id="confirmCancelBtn">
                            <i class="fas fa-ban"></i> Yes, Cancel Class
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
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
    </style>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebarNav').classList.toggle('show');
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const sidebarNav = document.getElementById('sidebarNav');

            if (sidebar && !sidebar.contains(event.target) && sidebarNav && sidebarNav.classList.contains('show')) {
                sidebarNav.classList.remove('show');
            }
        });

        // Confirmation function
        function confirmCancellation(event) {
            event.preventDefault();

            const confirmMessage = '⚠️ CLASS CANCELLATION CONFIRMATION ⚠️\n\n' +
                'You are about to CANCEL this class. This action is IRREVERSIBLE and will:\n\n' +
                '• Unenroll all <?php echo $enrolled_students; ?> student(s)\n' +
                '• Delete <?php echo $total_materials; ?> materials permanently\n' +
                '• Delete <?php echo $total_assignments; ?> assignments and all submissions\n' +
                '• Remove all grades, attendance, and quiz attempts\n' +
                '• Send cancellation notifications to all students\n\n' +
                'Type "CANCEL CLASS" below to confirm this action.';

            const userInput = prompt(confirmMessage);

            if (userInput !== 'CANCEL CLASS') {
                alert('Class cancellation cancelled. You did not type "CANCEL CLASS" correctly.');
                return false;
            }

            const finalConfirm = confirm('Are you ABSOLUTELY sure you want to cancel this class?\n\nThis action cannot be undone!');

            if (finalConfirm) {
                // Disable button and submit form
                const btn = document.getElementById('confirmCancelBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

                // Submit the form
                document.getElementById('cancelForm').submit();
                return true;
            }

            return false;
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>