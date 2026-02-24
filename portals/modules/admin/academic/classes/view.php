<?php
// modules/admin/academic/classes/view.php

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

// Get class ID
$class_id = $_GET['id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class data with comprehensive information
$sql = "SELECT 
    cb.*,
    c.title as course_title,
    c.course_code,
    c.description as course_description,
    c.duration_hours,
    c.level,
    p.name as program_name,
    p.program_code,
    p.program_type,
    p.fee as program_fee,
    u.first_name as instructor_first_name,
    u.last_name as instructor_last_name,
    u.email as instructor_email,
    u.phone as instructor_phone,
    COUNT(DISTINCT e.id) as total_enrollments,
    COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.id END) as active_enrollments,
    COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.id END) as completed_enrollments,
    COUNT(DISTINCT m.id) as total_materials,
    COUNT(DISTINCT a.id) as total_assignments,
    COUNT(DISTINCT asub.id) as total_submissions,
    COUNT(DISTINCT CASE WHEN asub.grade IS NOT NULL THEN asub.id END) as graded_submissions
FROM class_batches cb
JOIN courses c ON cb.course_id = c.id
JOIN programs p ON c.program_id = p.id
LEFT JOIN users u ON cb.instructor_id = u.id
LEFT JOIN enrollments e ON cb.id = e.class_id
LEFT JOIN materials m ON cb.id = m.class_id
LEFT JOIN assignments a ON cb.id = a.class_id
LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id
WHERE cb.id = ?
GROUP BY cb.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();

if (!$class) {
    $_SESSION['error'] = 'Class not found.';
    header('Location: list.php');
    exit();
}

// Fetch enrolled students
$students_sql = "SELECT 
    e.*,
    u.id as user_id,
    u.first_name,
    u.last_name,
    u.email,
    u.phone,
    up.date_of_birth,
    up.gender,
    up.city,
    up.state
FROM enrollments e
JOIN users u ON e.student_id = u.id
LEFT JOIN user_profiles up ON u.id = up.user_id
WHERE e.class_id = ?
ORDER BY e.enrollment_date DESC";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param('i', $class_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class materials
$materials_sql = "SELECT * FROM materials 
                  WHERE class_id = ? 
                  ORDER BY week_number, created_at DESC";
$materials_stmt = $conn->prepare($materials_sql);
$materials_stmt->bind_param('i', $class_id);
$materials_stmt->execute();
$materials = $materials_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class assignments
$assignments_sql = "SELECT a.*, COUNT(asub.id) as submission_count 
                    FROM assignments a
                    LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id
                    WHERE a.class_id = ?
                    GROUP BY a.id
                    ORDER BY a.due_date";
$assignments_stmt = $conn->prepare($assignments_sql);
$assignments_stmt->bind_param('i', $class_id);
$assignments_stmt->execute();
$assignments = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch class announcements
$announcements_sql = "SELECT * FROM announcements 
                      WHERE class_id = ? 
                      ORDER BY publish_date DESC 
                      LIMIT 10";
$announcements_stmt = $conn->prepare($announcements_sql);
$announcements_stmt->bind_param('i', $class_id);
$announcements_stmt->execute();
$announcements = $announcements_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate class progress
$class_start = strtotime($class['start_date']);
$class_end = strtotime($class['end_date']);
$today = time();
$class_duration = $class_end - $class_start;
$elapsed = $today - $class_start;
$progress_percentage = $class_duration > 0 ? min(100, max(0, ($elapsed / $class_duration) * 100)) : 0;

// Determine class timeline status
$timeline_status = '';
if ($class['status'] === 'completed') {
    $timeline_status = 'completed';
} elseif ($class['status'] === 'cancelled') {
    $timeline_status = 'cancelled';
} elseif ($progress_percentage >= 100) {
    $timeline_status = 'completed';
} elseif ($progress_percentage > 0) {
    $timeline_status = 'ongoing';
} else {
    $timeline_status = 'scheduled';
}

// Log activity
logActivity($_SESSION['user_id'], 'class_view', "Viewed class #$class_id", 'class_batches', $class_id);

// Handle unenrollment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unenroll_student') {
    // Verify CSRF token - use validateCSRFToken instead
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: view.php?id=' . $class_id);
        exit();
    }

    $student_id = intval($_POST['student_id']);


    // Verify student is enrolled in this class
    $check_sql = "SELECT e.*, u.first_name, u.last_name 
                  FROM enrollments e 
                  JOIN users u ON e.student_id = u.id 
                  WHERE e.student_id = ? AND e.class_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('ii', $student_id, $class_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $enrollment = $check_result->fetch_assoc();

    if (!$enrollment) {
        $_SESSION['error'] = 'Student is not enrolled in this class.';
        header('Location: view.php?id=' . $class_id);
        exit();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // 1. Delete assignment submissions
        $delete_submissions_sql = "DELETE assignment_submissions 
                               FROM assignment_submissions 
                               INNER JOIN assignments ON assignment_submissions.assignment_id = assignments.id 
                               WHERE assignment_submissions.student_id = ? AND assignments.class_id = ?";
        $submissions_stmt = $conn->prepare($delete_submissions_sql);
        $submissions_stmt->bind_param('ii', $student_id, $class_id);
        $submissions_stmt->execute();

        // 2. Delete gradebook entries
        $delete_gradebook_sql = "DELETE gradebook 
                             FROM gradebook 
                             INNER JOIN assignments ON gradebook.assignment_id = assignments.id 
                             WHERE gradebook.student_id = ? AND assignments.class_id = ?";
        $gradebook_stmt = $conn->prepare($delete_gradebook_sql);
        $gradebook_stmt->bind_param('ii', $student_id, $class_id);
        $gradebook_stmt->execute();

        // 3. Delete quiz attempts
        $delete_quiz_attempts_sql = "DELETE quiz_attempts 
                                 FROM quiz_attempts 
                                 INNER JOIN quizzes ON quiz_attempts.quiz_id = quizzes.id 
                                 WHERE quiz_attempts.student_id = ? AND quizzes.class_id = ?";
        $quiz_attempts_stmt = $conn->prepare($delete_quiz_attempts_sql);
        $quiz_attempts_stmt->bind_param('ii', $student_id, $class_id);
        $quiz_attempts_stmt->execute();

        // 4. Delete attendance records
        $delete_attendance_sql = "DELETE FROM attendance WHERE enrollment_id = ?";
        $attendance_stmt = $conn->prepare($delete_attendance_sql);
        $attendance_stmt->bind_param('i', $enrollment['id']);
        $attendance_stmt->execute();

        // 5. Delete financial status
        $delete_financial_sql = "DELETE FROM student_financial_status WHERE student_id = ? AND class_id = ?";
        $financial_stmt = $conn->prepare($delete_financial_sql);
        $financial_stmt->bind_param('ii', $student_id, $class_id);
        $financial_stmt->execute();

        // 6. Delete course payments
        $delete_payments_sql = "DELETE FROM course_payments WHERE student_id = ? AND class_id = ?";
        $payments_stmt = $conn->prepare($delete_payments_sql);
        $payments_stmt->bind_param('ii', $student_id, $class_id);
        $payments_stmt->execute();

        // 7. Delete discussion replies
        $delete_replies_sql = "DELETE discussion_replies 
                           FROM discussion_replies 
                           INNER JOIN discussions ON discussion_replies.discussion_id = discussions.id 
                           WHERE discussion_replies.user_id = ? AND discussions.class_id = ?";
        $replies_stmt = $conn->prepare($delete_replies_sql);
        $replies_stmt->bind_param('ii', $student_id, $class_id);
        $replies_stmt->execute();

        // 8. Delete discussions created by student
        $delete_discussions_sql = "DELETE FROM discussions WHERE user_id = ? AND class_id = ?";
        $discussions_stmt = $conn->prepare($delete_discussions_sql);
        $discussions_stmt->bind_param('ii', $student_id, $class_id);
        $discussions_stmt->execute();

        // 9. Delete the enrollment
        $delete_enrollment_sql = "DELETE FROM enrollments WHERE id = ?";
        $enrollment_stmt = $conn->prepare($delete_enrollment_sql);
        $enrollment_stmt->bind_param('i', $enrollment['id']);
        $enrollment_stmt->execute();

        // Commit transaction
        $conn->commit();

        // Log activity
        logActivity($_SESSION['user_id'], 'unenroll_student', "Unenrolled student #$student_id from class #$class_id", 'enrollments', $enrollment['id']);

        $_SESSION['success'] = 'Student successfully unenrolled from the class.';
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = 'Error unenrolling student: ' . $e->getMessage();
    }

    header('Location: view.php?id=' . $class_id);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['name']); ?> - Class Details</title>
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
            --gray-50: #f9fafb;
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

        /* Mobile-First Sidebar */
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
            white-space: nowrap;
            overflow-x: auto;
            padding-bottom: 0.25rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .header-actions .btn {
            flex: 1;
            min-width: 120px;
        }

        /* Class Header Card */
        .class-header {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .class-title {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .class-title h2 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
            word-break: break-word;
        }

        .class-code {
            font-size: 0.95rem;
            color: #64748b;
            font-weight: 500;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
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

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.7rem;
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

        .class-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.15rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
            word-break: break-word;
        }

        .class-progress {
            margin-top: 1.5rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .progress-label {
            font-size: 0.8rem;
            color: #64748b;
        }

        .progress-percentage {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-dates {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.5rem;
        }

        .class-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .action-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .action-row .btn {
            flex: 1;
            min-width: 120px;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            text-decoration: none;
            width: 100%;
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
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
        }

        .stat-card.enrollments {
            border-top-color: var(--success);
        }

        .stat-card.materials {
            border-top-color: var(--accent);
        }

        .stat-card.assignments {
            border-top-color: var(--primary);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sub {
            font-size: 0.65rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Tab Navigation - Mobile Optimized */
        .tab-nav {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            background: white;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            position: sticky;
            top: 70px;
            z-index: 40;
        }

        .tab-nav::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            padding: 0.75rem 1rem;
            border: none;
            background: none;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
        }

        .tab-btn i {
            font-size: 0.9rem;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .section-header {
            padding: 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header i {
            color: var(--primary);
        }

        .section-body {
            padding: 1rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.5rem;
        }

        .info-label {
            font-size: 0.65rem;
            color: #64748b;
            margin-bottom: 0.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
            word-break: break-word;
        }

        .text-content {
            line-height: 1.6;
            color: var(--dark);
            white-space: pre-wrap;
            font-size: 0.9rem;
        }

        /* Instructor Card */
        .instructor-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            margin-bottom: 1rem;
        }

        .instructor-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .instructor-contact {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        /* Tables - Mobile Optimized */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1rem;
            padding: 0 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        /* Mobile Card View for Students */
        .students-card-view {
            display: block;
        }

        .student-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .student-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .student-info {
            flex: 1;
            margin-left: 0.75rem;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .student-email {
            font-size: 0.75rem;
            color: #64748b;
        }

        .student-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin: 0.75rem 0;
            font-size: 0.8rem;
        }

        .student-detail-item span {
            font-size: 0.65rem;
            color: #64748b;
            display: block;
            margin-bottom: 0.1rem;
        }

        .student-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #e2e8f0;
        }

        .student-actions .btn-sm {
            flex: 1;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #e0f2fe;
            color: #0369a1;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 6px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.8rem;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 2px solid white;
        }

        .timeline-item.current::before {
            background: var(--primary);
        }

        .timeline-item.completed::before {
            background: var(--success);
        }

        .timeline-item.cancelled::before {
            background: var(--danger);
        }

        .timeline-date {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }

        .empty-state p {
            font-size: 0.85rem;
        }

        /* Materials Card View */
        .materials-card-view {
            display: block;
        }

        .material-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .material-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .material-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--dark);
        }

        .material-meta {
            display: flex;
            gap: 0.5rem;
            font-size: 0.7rem;
            color: #64748b;
            margin: 0.5rem 0;
            flex-wrap: wrap;
        }

        /* Assignments Card View */
        .assignment-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .assignment-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--dark);
        }

        .assignment-due {
            font-size: 0.7rem;
            color: #64748b;
        }

        .assignment-stats {
            display: flex;
            gap: 1rem;
            margin: 0.5rem 0;
            font-size: 0.8rem;
        }

        /* Tablet and Desktop Breakpoints */
        @media (min-width: 640px) {
            .main-content {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .class-meta-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .class-actions {
                flex-direction: row;
            }

            .info-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .students-card-view {
                display: none;
            }

            .table-container {
                margin: 0;
                padding: 0;
            }
        }

        @media (min-width: 768px) {
            .sidebar {
                width: 250px;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar-header {
                padding: 1.5rem 1.5rem 1.5rem;
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

            .header-actions .btn {
                flex: none;
                width: auto;
            }

            .class-title {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }

            .class-title h2 {
                font-size: 1.8rem;
            }

            .class-actions {
                flex-direction: row;
                margin-top: 0;
            }

            .action-row {
                flex: 1;
            }

            .tab-nav {
                top: 0;
            }

            .info-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .materials-card-view {
                display: none;
            }

            .assignments-card-view {
                display: none;
            }
        }

        @media (min-width: 1024px) {
            .content-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 2rem;
            }

            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .tab-nav,
            .class-actions,
            .btn,
            .header-actions,
            .menu-toggle {
                display: none !important;
            }

            .main-content {
                margin: 0;
                padding: 1rem;
            }

            .class-header,
            .section-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }

            .progress-bar {
                border: 1px solid #ddd;
            }
        }

        /* Unenroll button specific styling */
        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .student-actions .btn-danger {
            border-color: transparent;
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
                        <?php echo htmlspecialchars($class['batch_code']); ?>
                    </div>
                    <h1>Class Details</h1>
                </div>
                <div class="header-actions">
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
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

            <!-- Class Header Card -->
            <div class="class-header">
                <div class="class-title">
                    <div>
                        <h2><?php echo htmlspecialchars($class['name']); ?></h2>
                        <div class="class-code">
                            <?php echo htmlspecialchars($class['batch_code']); ?>
                            <div class="badge-container">
                                <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                    <?php echo ucfirst($class['program_type']); ?>
                                </span>
                                <span class="status-badge status-<?php echo $class['status']; ?>">
                                    <?php echo ucfirst($class['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="class-meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Course</div>
                        <div class="meta-value"><?php echo htmlspecialchars($class['course_code']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Program</div>
                        <div class="meta-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Instructor</div>
                        <div class="meta-value">
                            <?php if ($class['instructor_first_name']): ?>
                                <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                            <?php else: ?>
                                <span style="color: #64748b;">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Duration</div>
                        <div class="meta-value"><?php echo $class['duration_hours']; ?> hrs</div>
                    </div>
                </div>

                <!-- Class Progress -->
                <div class="class-progress">
                    <div class="progress-header">
                        <div class="progress-label">Class Timeline</div>
                        <div class="progress-percentage"><?php echo round($progress_percentage); ?>%</div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                    </div>
                    <div class="progress-dates">
                        <div><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($class['start_date'])); ?></div>
                        <div><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($class['end_date'])); ?></div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="class-actions">
                    <div class="action-row">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/home.php" class="btn btn-success" target="_blank">
                            <i class="fas fa-chalkboard-teacher"></i> Class View
                        </a>
                        <?php if ($class['meeting_link']): ?>
                            <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" class="btn btn-warning" target="_blank">
                                <i class="fas fa-video"></i> Join
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="action-row">
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Enroll Student
                        </a>
                        <?php if ($class['status'] !== 'completed' && $class['status'] !== 'cancelled'): ?>
                            <form method="POST" style="display: inline; width: 100%;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="mark_completed">
                                <button type="submit" class="btn btn-warning" style="width: 100%;"
                                    onclick="return confirm('Mark this class as completed? This action cannot be undone.')">
                                    <i class="fas fa-check-circle"></i> Complete
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Hidden Unenrollment Form - ADD THIS HERE -->
            <form id="unenrollForm" method="POST" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="unenroll_student">
                <input type="hidden" name="student_id" id="unenroll_student_id" value="">
            </form>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $class['total_enrollments']; ?></div>
                    <div class="stat-label">Enrollments</div>
                    <div class="stat-sub"><?php echo $class['active_enrollments']; ?> active</div>
                </div>
                <div class="stat-card enrollments">
                    <div class="stat-number"><?php echo $class['max_students']; ?></div>
                    <div class="stat-label">Capacity</div>
                    <div class="stat-sub"><?php echo round(($class['active_enrollments'] / $class['max_students']) * 100); ?>% filled</div>
                </div>
                <div class="stat-card materials">
                    <div class="stat-number"><?php echo $class['total_materials']; ?></div>
                    <div class="stat-label">Materials</div>
                </div>
                <div class="stat-card assignments">
                    <div class="stat-number"><?php echo $class['total_assignments']; ?></div>
                    <div class="stat-label">Assignments</div>
                    <div class="stat-sub"><?php echo $class['graded_submissions']; ?> graded</div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button type="button" class="tab-btn active" onclick="showTab('overview')">
                    <i class="fas fa-chalkboard"></i> Overview
                </button>
                <button type="button" class="tab-btn" onclick="showTab('students')">
                    <i class="fas fa-users"></i> Students (<?php echo $class['total_enrollments']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('materials')">
                    <i class="fas fa-book"></i> Materials (<?php echo $class['total_materials']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('assignments')">
                    <i class="fas fa-tasks"></i> Assignments (<?php echo $class['total_assignments']; ?>)
                </button>
                <button type="button" class="tab-btn" onclick="showTab('timeline')">
                    <i class="fas fa-history"></i> Timeline
                </button>
            </div>

            <!-- Overview Tab -->
            <div id="overview-tab" class="tab-content active">
                <!-- Course Information -->
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-graduation-cap"></i> Course Information</h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Course Code</div>
                                <div class="info-value"><?php echo htmlspecialchars($class['course_code']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Course Title</div>
                                <div class="info-value"><?php echo htmlspecialchars($class['course_title']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Program</div>
                                <div class="info-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Program Type</div>
                                <div class="info-value">
                                    <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                        <?php echo ucfirst($class['program_type']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Course Level</div>
                                <div class="info-value"><?php echo ucfirst($class['level']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Duration</div>
                                <div class="info-value"><?php echo $class['duration_hours']; ?> hours</div>
                            </div>
                        </div>

                        <?php if ($class['course_description']): ?>
                            <div style="margin-top: 1rem;">
                                <div class="info-label">Course Description</div>
                                <div class="text-content" style="margin-top: 0.5rem;">
                                    <?php echo nl2br(htmlspecialchars($class['course_description'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Schedule & Meeting -->
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-calendar-alt"></i> Schedule & Meeting</h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Schedule</div>
                                <div class="info-value">
                                    <?php echo $class['schedule'] ? nl2br(htmlspecialchars($class['schedule'])) : 'Not specified'; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Meeting Link</div>
                                <div class="info-value">
                                    <?php if ($class['meeting_link']): ?>
                                        <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>"
                                            target="_blank" style="color: var(--primary); text-decoration: none; word-break: break-all;">
                                            Join Meeting <i class="fas fa-external-link-alt" style="font-size: 0.7rem;"></i>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #64748b;">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Days Remaining</div>
                                <div class="info-value">
                                    <?php
                                    $end_date = strtotime($class['end_date']);
                                    $today = time();
                                    $days_remaining = ceil(($end_date - $today) / (60 * 60 * 24));
                                    echo $days_remaining > 0 ? $days_remaining . ' days' : 'Completed';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instructor Information -->
                <?php if ($class['instructor_first_name']): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-user-tie"></i> Instructor</h3>
                        </div>
                        <div class="section-body">
                            <div class="instructor-card">
                                <div class="instructor-name">
                                    <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                                </div>
                                <div class="instructor-contact">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo htmlspecialchars($class['instructor_email']); ?>
                                </div>
                                <?php if ($class['instructor_phone']): ?>
                                    <div class="instructor-contact">
                                        <i class="fas fa-phone"></i>
                                        <?php echo htmlspecialchars($class['instructor_phone']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                                <a href="mailto:<?php echo urlencode($class['instructor_email']); ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-envelope"></i> Email
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $class['instructor_id']; ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> Profile
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Students Tab -->
            <div id="students-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-users"></i> Enrolled Students (<?php echo $class['total_enrollments']; ?>)</h3>
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-user-plus"></i> Enroll
                        </a>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($students)): ?>
                            <!-- Mobile Card View -->
                            <div class="students-card-view">
                                <?php foreach ($students as $student):
                                    $initials = strtoupper(
                                        substr($student['first_name'], 0, 1) .
                                            substr($student['last_name'], 0, 1)
                                    );
                                ?>
                                    <div class="student-card">
                                        <div class="student-card-header">
                                            <div style="display: flex; align-items: center;">
                                                <div class="student-avatar"><?php echo $initials; ?></div>
                                                <div class="student-info">
                                                    <div class="student-name">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </div>
                                                    <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                                </div>
                                            </div>
                                            <?php
                                            $status_class = '';
                                            if ($student['status'] === 'active') $status_class = 'badge-success';
                                            elseif ($student['status'] === 'completed') $status_class = 'badge-info';
                                            elseif ($student['status'] === 'dropped') $status_class = 'badge-danger';
                                            else $status_class = 'badge-warning';
                                            ?>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($student['status']); ?>
                                            </span>
                                        </div>

                                        <div class="student-details">
                                            <div class="student-detail-item">
                                                <span>Enrolled</span>
                                                <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?>
                                            </div>
                                            <?php if ($student['phone']): ?>
                                                <div class="student-detail-item">
                                                    <span>Phone</span>
                                                    <?php echo htmlspecialchars($student['phone']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($student['gender']): ?>
                                                <div class="student-detail-item">
                                                    <span>Gender</span>
                                                    <?php echo ucfirst($student['gender']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($student['final_grade']): ?>
                                                <div class="student-detail-item">
                                                    <span>Grade</span>
                                                    <span style="color: var(--success); font-weight: 600;">
                                                        <?php echo $student['final_grade']; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="student-actions">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $student['user_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Profile
                                            </a>
                                            <a href="mailto:<?php echo urlencode($student['email']); ?>" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-envelope"></i> Email
                                            </a>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmUnenroll(<?php echo $student['user_id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
                                                <i class="fas fa-user-minus"></i> Unenroll
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Desktop Table View -->
                            <div class="table-container" style="display: none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Contact</th>
                                            <th>Enrolled</th>
                                            <th>Status</th>
                                            <th>Grade</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                        <div class="student-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                            <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                            <div style="font-size: 0.7rem; color: #64748b;"><?php echo htmlspecialchars($student['email']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?></td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    if ($student['status'] === 'active') $status_class = 'badge-success';
                                                    elseif ($student['status'] === 'completed') $status_class = 'badge-info';
                                                    elseif ($student['status'] === 'dropped') $status_class = 'badge-danger';
                                                    else $status_class = 'badge-warning';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($student['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($student['final_grade']): ?>
                                                        <span style="color: var(--success);"><?php echo $student['final_grade']; ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.25rem;">
                                                        <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $student['user_id']; ?>" class="btn btn-primary btn-sm" title="View Profile">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="mailto:<?php echo urlencode($student['email']); ?>" class="btn btn-secondary btn-sm" title="Send Email">
                                                            <i class="fas fa-envelope"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmUnenroll(<?php echo $student['user_id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')" title="Unenroll Student">
                                                            <i class="fas fa-user-minus"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h3>No Students Enrolled</h3>
                                <p>Start by enrolling students to this class.</p>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/enroll.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary" style="margin-top: 0.5rem;">
                                    <i class="fas fa-user-plus"></i> Enroll Student
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Materials Tab -->
            <div id="materials-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-book"></i> Course Materials (<?php echo $class['total_materials']; ?>)</h3>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/upload.php" class="btn btn-primary btn-sm" target="_blank">
                            <i class="fas fa-upload"></i> Upload
                        </a>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($materials)): ?>
                            <!-- Mobile Card View -->
                            <div class="materials-card-view">
                                <?php foreach ($materials as $material): ?>
                                    <div class="material-card">
                                        <div class="material-header">
                                            <div class="material-title"><?php echo htmlspecialchars($material['title']); ?></div>
                                            <span class="badge badge-info"><?php echo ucfirst($material['file_type']); ?></span>
                                        </div>

                                        <?php if ($material['description']): ?>
                                            <div style="font-size: 0.8rem; color: #64748b; margin: 0.5rem 0;">
                                                <?php echo htmlspecialchars(substr($material['description'], 0, 100)); ?>...
                                            </div>
                                        <?php endif; ?>

                                        <div class="material-meta">
                                            <?php if ($material['week_number']): ?>
                                                <span><i class="far fa-calendar"></i> Week <?php echo $material['week_number']; ?></span>
                                            <?php endif; ?>
                                            <span><i class="far fa-eye"></i> <?php echo $material['downloads_count']; ?> downloads</span>
                                            <span><i class="far fa-clock"></i> <?php echo date('M j, Y', strtotime($material['publish_date'])); ?></span>
                                        </div>

                                        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                                            <?php if ($material['file_url']): ?>
                                                <a href="<?php echo BASE_URL . 'public/uploads/' . $material['file_url']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/manage.php" class="btn btn-secondary btn-sm" target="_blank">
                                                <i class="fas fa-cog"></i> Manage
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Desktop Table View (hidden on mobile) -->
                            <div class="table-container" style="display: none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>Week</th>
                                            <th>Downloads</th>
                                            <th>Published</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($materials as $material): ?>
                                            <tr>
                                                <td>
                                                    <div><strong><?php echo htmlspecialchars($material['title']); ?></strong></div>
                                                    <?php if ($material['description']): ?>
                                                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars(substr($material['description'], 0, 50)); ?>...</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge badge-info"><?php echo ucfirst($material['file_type']); ?></span></td>
                                                <td><?php echo $material['week_number'] ? 'Week ' . $material['week_number'] : '-'; ?></td>
                                                <td><?php echo $material['downloads_count']; ?></td>
                                                <td><?php echo date('M j, Y', strtotime($material['publish_date'])); ?></td>
                                                <td>
                                                    <?php if ($material['file_url']): ?>
                                                        <a href="<?php echo BASE_URL . 'public/uploads/' . $material['file_url']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <h3>No Materials</h3>
                                <p>Upload course materials for students.</p>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/materials/upload.php" class="btn btn-primary" style="margin-top: 0.5rem;" target="_blank">
                                    <i class="fas fa-upload"></i> Upload Material
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Assignments Tab -->
            <div id="assignments-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-tasks"></i> Assignments (<?php echo $class['total_assignments']; ?>)</h3>
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/create.php" class="btn btn-primary btn-sm" target="_blank">
                            <i class="fas fa-plus"></i> Create
                        </a>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($assignments)): ?>
                            <!-- Mobile Card View -->
                            <div class="assignments-card-view">
                                <?php foreach ($assignments as $assignment): ?>
                                    <div class="assignment-card">
                                        <div class="assignment-header">
                                            <div class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                            <?php if ($assignment['is_published']): ?>
                                                <span class="badge badge-success">Published</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Draft</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($assignment['description']): ?>
                                            <div style="font-size: 0.8rem; color: #64748b; margin: 0.5rem 0;">
                                                <?php echo htmlspecialchars(substr($assignment['description'], 0, 100)); ?>...
                                            </div>
                                        <?php endif; ?>

                                        <div class="assignment-stats">
                                            <div><i class="far fa-clock"></i> Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></div>
                                            <div><i class="fas fa-star"></i> <?php echo $assignment['total_points']; ?> pts</div>
                                            <div><i class="fas fa-users"></i> <?php echo $assignment['submission_count']; ?>/<?php echo $class['active_enrollments']; ?></div>
                                        </div>

                                        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/submissions.php?assignment_id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i> Submissions
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/grade.php?assignment_id=<?php echo $assignment['id']; ?>" class="btn btn-success btn-sm" target="_blank">
                                                <i class="fas fa-check"></i> Grade
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Desktop Table View (hidden on mobile) -->
                            <div class="table-container" style="display: none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Due Date</th>
                                            <th>Points</th>
                                            <th>Submissions</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <div><strong><?php echo htmlspecialchars($assignment['title']); ?></strong></div>
                                                    <?php if ($assignment['description']): ?>
                                                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars(substr($assignment['description'], 0, 50)); ?>...</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                                    <?php if (strtotime($assignment['due_date']) < time()): ?>
                                                        <div class="badge badge-danger">Overdue</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $assignment['total_points']; ?></td>
                                                <td><?php echo $assignment['submission_count']; ?>/<?php echo $class['active_enrollments']; ?></td>
                                                <td>
                                                    <?php if ($assignment['is_published']): ?>
                                                        <span class="badge badge-success">Published</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/submissions.php?assignment_id=<?php echo $assignment['id']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-tasks"></i>
                                <h3>No Assignments</h3>
                                <p>Create assignments for this class.</p>
                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class_id; ?>/assignments/create.php" class="btn btn-primary" style="margin-top: 0.5rem;" target="_blank">
                                    <i class="fas fa-plus"></i> Create Assignment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Timeline Tab -->
            <div id="timeline-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-history"></i> Class Timeline</h3>
                    </div>
                    <div class="section-body">
                        <div class="timeline">
                            <div class="timeline-item <?php echo $timeline_status === 'scheduled' ? 'current' : ''; ?>">
                                <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['start_date'])); ?></div>
                                <div class="timeline-content">
                                    <strong>Class Scheduled</strong>
                                    <p style="margin-top: 0.25rem; color: #64748b; font-size: 0.75rem;">
                                        Class scheduled to start
                                    </p>
                                </div>
                            </div>

                            <?php if ($class['status'] === 'ongoing' || $timeline_status === 'ongoing'): ?>
                                <div class="timeline-item current">
                                    <div class="timeline-date">Currently</div>
                                    <div class="timeline-content">
                                        <strong>Class in Progress</strong>
                                        <p style="margin-top: 0.25rem; color: #64748b; font-size: 0.75rem;">
                                            <?php echo round($progress_percentage); ?>% completed
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($class['status'] === 'completed' || $timeline_status === 'completed'): ?>
                                <div class="timeline-item completed">
                                    <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['end_date'])); ?></div>
                                    <div class="timeline-content">
                                        <strong>Class Completed</strong>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($class['status'] === 'cancelled'): ?>
                                <div class="timeline-item cancelled">
                                    <div class="timeline-date"><?php echo date('M j, Y', strtotime($class['updated_at'])); ?></div>
                                    <div class="timeline-content">
                                        <strong>Class Cancelled</strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <?php if (!empty($announcements)): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                        </div>
                        <div class="section-body">
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="instructor-card" style="margin-bottom: 0.5rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                            <?php if ($announcement['priority'] === 'high'): ?>
                                                <span class="badge badge-danger" style="margin-left: 0.25rem;">High</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.65rem; color: #64748b;">
                                            <?php echo date('M j, Y', strtotime($announcement['publish_date'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($announcement['content']): ?>
                                        <div style="margin-top: 0.25rem; font-size: 0.75rem; color: #64748b;">
                                            <?php echo htmlspecialchars(substr($announcement['content'], 0, 100)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebarNav').classList.toggle('show');
        });

        // Tab navigation functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Mark selected tab button as active
            event.currentTarget.classList.add('active');

            // Store active tab in sessionStorage
            sessionStorage.setItem('activeClassTab', tabName);

            // Scroll to top of tab content on mobile
            if (window.innerWidth < 768) {
                document.getElementById(tabName + '-tab').scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem('activeClassTab') || 'overview';

            // Find and click the appropriate tab button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.textContent.toLowerCase().includes(activeTab)) {
                    btn.click();
                }
            });

            // Animate progress bar
            const progressFill = document.querySelector('.progress-fill');
            if (progressFill) {
                const width = progressFill.style.width;
                progressFill.style.width = '0%';
                setTimeout(() => {
                    progressFill.style.width = width;
                }, 100);
            }

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                const sidebar = document.querySelector('.sidebar');
                const menuToggle = document.getElementById('menuToggle');
                const sidebarNav = document.getElementById('sidebarNav');

                if (!sidebar.contains(event.target) && sidebarNav.classList.contains('show')) {
                    sidebarNav.classList.remove('show');
                }
            });

            // Handle form submissions (for mark completed/cancel class)
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (this.querySelector('input[name="action"]')) {
                        const action = this.querySelector('input[name="action"]').value;
                        let confirmed = false;

                        if (action === 'mark_completed') {
                            confirmed = confirm('Mark this class as completed?\n\nThis will:\n• Close enrollment\n• Lock assignments\n• Generate final grades\n\nThis action cannot be undone.');
                        } else if (action === 'cancel_class') {
                            confirmed = confirm('Cancel this class?\n\nThis will:\n• Cancel all enrollments\n• Notify all students\n• Remove from active listings\n\nThis action cannot be undone.');
                        }

                        if (!confirmed) {
                            e.preventDefault();
                        }
                    }
                });
            });
        });

        // Responsive table switching
        function handleResponsiveTables() {
            const isMobile = window.innerWidth < 640;

            // Handle students table
            const studentsCardView = document.querySelector('.students-card-view');
            const studentsTableView = document.querySelector('#students-tab .table-container');

            if (studentsCardView && studentsTableView) {
                if (isMobile) {
                    studentsCardView.style.display = 'block';
                    studentsTableView.style.display = 'none';
                } else {
                    studentsCardView.style.display = 'none';
                    studentsTableView.style.display = 'block';
                }
            }

            // Handle materials table
            const materialsCardView = document.querySelector('.materials-card-view');
            const materialsTableView = document.querySelector('#materials-tab .table-container');

            if (materialsCardView && materialsTableView) {
                if (isMobile) {
                    materialsCardView.style.display = 'block';
                    materialsTableView.style.display = 'none';
                } else {
                    materialsCardView.style.display = 'none';
                    materialsTableView.style.display = 'block';
                }
            }

            // Handle assignments table
            const assignmentsCardView = document.querySelector('.assignments-card-view');
            const assignmentsTableView = document.querySelector('#assignments-tab .table-container');

            if (assignmentsCardView && assignmentsTableView) {
                if (isMobile) {
                    assignmentsCardView.style.display = 'block';
                    assignmentsTableView.style.display = 'none';
                } else {
                    assignmentsCardView.style.display = 'none';
                    assignmentsTableView.style.display = 'block';
                }
            }
        }

        // Run on load and resize
        window.addEventListener('load', handleResponsiveTables);
        window.addEventListener('resize', handleResponsiveTables);

        // Search functionality for students (if needed)
        function searchStudents() {
            const searchTerm = document.getElementById('studentSearch')?.value.toLowerCase() || '';
            const cards = document.querySelectorAll('.student-card');

            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchTerm) ? 'block' : 'none';
            });
        }

        // Touch-friendly interactions
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            btn.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });

        // Unenroll student functionality
        function confirmUnenroll(studentId, studentName) {
            if (confirm(`Are you sure you want to unenroll ${studentName} from this class?\n\nThis action will:\n• Remove the student from the class\n• Delete their submissions and grades\n• Free up a seat in the class\n\nThis action cannot be undone.`)) {
                document.getElementById('unenroll_student_id').value = studentId;
                document.getElementById('unenrollForm').submit();
            }
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>