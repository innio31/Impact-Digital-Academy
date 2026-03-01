<?php
// modules/admin/academic/programs/view.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get program ID from URL
$program_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$program_id) {
    $_SESSION['error'] = 'Program ID is required';
    header('Location: index.php');
    exit();
}

// Get database connection
$conn = getDBConnection();

// Handle student enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_student'])) {
    $student_id = (int)$_POST['student_id'];
    $enrollment_date = $_POST['enrollment_date'];

    // Validate inputs
    if (!$student_id) {
        $_SESSION['error'] = 'Please select a student';
    } elseif (!$enrollment_date) {
        $_SESSION['error'] = 'Please select an enrollment date';
    } else {
        try {
            // Check if student exists
            $check_student_sql = "SELECT id, first_name, last_name FROM users WHERE id = ? AND role = 'student'";
            $check_stmt = $conn->prepare($check_student_sql);
            $check_stmt->bind_param("i", $student_id);
            $check_stmt->execute();
            $student_result = $check_stmt->get_result();

            if ($student_result->num_rows === 0) {
                $_SESSION['error'] = 'Selected student does not exist';
            } else {
                $student = $student_result->fetch_assoc();

                // Check if student already has an application for this program
                $check_application_sql = "SELECT id, status FROM applications 
                                         WHERE user_id = ? AND program_id = ? AND applying_as = 'student'";
                $check_app_stmt = $conn->prepare($check_application_sql);
                $check_app_stmt->bind_param("ii", $student_id, $program_id);
                $check_app_stmt->execute();
                $application_result = $check_app_stmt->get_result();

                if ($application_result->num_rows > 0) {
                    $application = $application_result->fetch_assoc();
                    if ($application['status'] === 'approved') {
                        $_SESSION['error'] = 'Student is already approved for this program';
                    } else {
                        // Update existing application to approved
                        $update_sql = "UPDATE applications SET 
                                      status = 'approved',
                                      reviewed_by = ?,
                                      reviewed_at = NOW(),
                                      review_notes = 'Manually enrolled by admin',
                                      registration_fee_paid = 1,
                                      registration_paid_date = CURDATE()
                                      WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $admin_id = $_SESSION['user_id'];
                        $update_stmt->bind_param("ii", $admin_id, $application['id']);

                        if ($update_stmt->execute()) {
                            $_SESSION['success'] = "Student {$student['first_name']} {$student['last_name']} has been enrolled in the program";
                        } else {
                            $_SESSION['error'] = 'Failed to update application: ' . $update_stmt->error;
                        }
                        $update_stmt->close();
                    }
                } else {
                    // Create new approved application
                    $insert_sql = "INSERT INTO applications 
                                  (user_id, applying_as, program_id, program_type, 
                                   status, registration_fee_paid, registration_paid_date,
                                   reviewed_by, reviewed_at, review_notes, created_at)
                                  VALUES (?, 'student', ?, ?, 
                                          'approved', 1, CURDATE(),
                                          ?, NOW(), 'Manually enrolled by admin', NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $admin_id = $_SESSION['user_id'];
                    $program_type = $program['program_type'] ?? 'online';
                    $insert_stmt->bind_param("iisi", $student_id, $program_id, $program_type, $admin_id);

                    if ($insert_stmt->execute()) {
                        $_SESSION['success'] = "Student {$student['first_name']} {$student['last_name']} has been enrolled in the program";
                    } else {
                        $_SESSION['error'] = 'Failed to enroll student: ' . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
                $check_app_stmt->close();
            }
            $check_stmt->close();

            // Log the action
            if (isset($_SESSION['success'])) {
                logActivity('program_enrollment', "Enrolled student {$student_id} in program {$program_id}", 'programs', $program_id);
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'An error occurred: ' . $e->getMessage();
        }
    }
}

// Handle unenrollment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unenroll_student') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: view.php?id=' . $program_id);
        exit();
    }

    $student_id = intval($_POST['student_id']);

    // Verify student is enrolled in this program
    $check_sql = "SELECT a.id as application_id, a.user_id, u.first_name, u.last_name, a.status
                  FROM applications a
                  JOIN users u ON a.user_id = u.id
                  WHERE a.user_id = ? AND a.program_id = ? AND a.applying_as = 'student' AND a.status = 'approved'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('ii', $student_id, $program_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $enrollment = $check_result->fetch_assoc();

    if (!$enrollment) {
        $_SESSION['error'] = 'Student is not enrolled in this program.';
        header('Location: view.php?id=' . $program_id);
        exit();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get all class IDs for this program
        $class_ids_sql = "SELECT cb.id 
                         FROM class_batches cb
                         JOIN courses c ON cb.course_id = c.id
                         WHERE c.program_id = ?";
        $class_ids_stmt = $conn->prepare($class_ids_sql);
        $class_ids_stmt->bind_param('i', $program_id);
        $class_ids_stmt->execute();
        $class_ids_result = $class_ids_stmt->get_result();
        $class_ids = [];
        while ($row = $class_ids_result->fetch_assoc()) {
            $class_ids[] = $row['id'];
        }
        $class_ids_stmt->close();

        // Get all enrollment IDs for this student in these classes
        if (!empty($class_ids)) {
            $class_ids_str = implode(',', $class_ids);

            // 1. Get enrollment IDs for attendance and gradebook
            $enrollment_ids_sql = "SELECT id FROM enrollments WHERE student_id = ? AND class_id IN ($class_ids_str)";
            $enrollment_ids_stmt = $conn->prepare($enrollment_ids_sql);
            $enrollment_ids_stmt->bind_param('i', $student_id);
            $enrollment_ids_stmt->execute();
            $enrollment_ids_result = $enrollment_ids_stmt->get_result();
            $enrollment_ids = [];
            while ($row = $enrollment_ids_result->fetch_assoc()) {
                $enrollment_ids[] = $row['id'];
            }
            $enrollment_ids_stmt->close();

            // 2. Delete assignment submissions
            $delete_submissions_sql = "DELETE assignment_submissions 
                                       FROM assignment_submissions 
                                       INNER JOIN assignments ON assignment_submissions.assignment_id = assignments.id 
                                       WHERE assignment_submissions.student_id = ? AND assignments.class_id IN ($class_ids_str)";
            $submissions_stmt = $conn->prepare($delete_submissions_sql);
            $submissions_stmt->bind_param('i', $student_id);
            $submissions_stmt->execute();
            $submissions_stmt->close();

            // 3. Delete gradebook entries
            $delete_gradebook_sql = "DELETE gradebook 
                                     FROM gradebook 
                                     WHERE student_id = ? AND enrollment_id IN (SELECT id FROM enrollments WHERE student_id = ? AND class_id IN ($class_ids_str))";
            $gradebook_stmt = $conn->prepare($delete_gradebook_sql);
            $gradebook_stmt->bind_param('ii', $student_id, $student_id);
            $gradebook_stmt->execute();
            $gradebook_stmt->close();

            // 4. Delete quiz attempts
            $delete_quiz_attempts_sql = "DELETE quiz_attempts 
                                         FROM quiz_attempts 
                                         INNER JOIN quizzes ON quiz_attempts.quiz_id = quizzes.id 
                                         WHERE quiz_attempts.student_id = ? AND quizzes.class_id IN ($class_ids_str)";
            $quiz_attempts_stmt = $conn->prepare($delete_quiz_attempts_sql);
            $quiz_attempts_stmt->bind_param('i', $student_id);
            $quiz_attempts_stmt->execute();
            $quiz_attempts_stmt->close();

            // 5. Delete attendance records
            if (!empty($enrollment_ids)) {
                $enrollment_ids_str = implode(',', $enrollment_ids);
                $delete_attendance_sql = "DELETE FROM attendance WHERE enrollment_id IN ($enrollment_ids_str)";
                $attendance_stmt = $conn->prepare($delete_attendance_sql);
                $attendance_stmt->execute();
                $attendance_stmt->close();
            }

            // 6. Delete course payments
            $delete_payments_sql = "DELETE FROM course_payments WHERE student_id = ? AND class_id IN ($class_ids_str)";
            $payments_stmt = $conn->prepare($delete_payments_sql);
            $payments_stmt->bind_param('i', $student_id);
            $payments_stmt->execute();
            $payments_stmt->close();

            // 7. Delete student financial status records
            $delete_financial_sql = "DELETE FROM student_financial_status WHERE student_id = ? AND class_id IN ($class_ids_str)";
            $financial_stmt = $conn->prepare($delete_financial_sql);
            $financial_stmt->bind_param('i', $student_id);
            $financial_stmt->execute();
            $financial_stmt->close();

            // 8. Delete enrollments
            $delete_enrollments_sql = "DELETE FROM enrollments WHERE student_id = ? AND class_id IN ($class_ids_str)";
            $enrollments_stmt = $conn->prepare($delete_enrollments_sql);
            $enrollments_stmt->bind_param('i', $student_id);
            $enrollments_stmt->execute();
            $enrollments_stmt->close();
        }

        // 9. Delete registration fee payments
        $delete_reg_payments_sql = "DELETE FROM registration_fee_payments WHERE student_id = ? AND program_id = ?";
        $reg_payments_stmt = $conn->prepare($delete_reg_payments_sql);
        $reg_payments_stmt->bind_param('ii', $student_id, $program_id);
        $reg_payments_stmt->execute();
        $reg_payments_stmt->close();

        // 10. Delete fee waivers
        $delete_waivers_sql = "DELETE FROM fee_waivers WHERE student_id = ? AND program_id = ?";
        $waivers_stmt = $conn->prepare($delete_waivers_sql);
        $waivers_stmt->bind_param('ii', $student_id, $program_id);
        $waivers_stmt->execute();
        $waivers_stmt->close();

        // 11. Delete or update the application
        $update_application_sql = "UPDATE applications SET 
                                   status = 'rejected', 
                                   reviewed_by = ?,
                                   reviewed_at = NOW(),
                                   review_notes = 'Unenrolled from program by admin'
                                   WHERE id = ?";
        $app_update_stmt = $conn->prepare($update_application_sql);
        $admin_id = $_SESSION['user_id'];
        $app_update_stmt->bind_param('ii', $admin_id, $enrollment['application_id']);
        $app_update_stmt->execute();
        $app_update_stmt->close();

        // Commit transaction
        $conn->commit();

        // Log activity
        logActivity($_SESSION['user_id'], 'unenroll_student', "Unenrolled student #$student_id from program #$program_id", 'programs', $program_id);

        $_SESSION['success'] = "Student {$enrollment['first_name']} {$enrollment['last_name']} has been successfully unenrolled from the program.";
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = 'Error unenrolling student: ' . $e->getMessage();
    }

    header('Location: view.php?id=' . $program_id);
    exit();
}

// Fetch program details
$sql = "SELECT p.*, 
               COUNT(DISTINCT c.id) as course_count,
               COUNT(DISTINCT e.id) as enrollment_count,
               u.first_name as creator_first_name,
               u.last_name as creator_last_name,
               u2.first_name as updater_first_name,
               u2.last_name as updater_last_name
        FROM programs p
        LEFT JOIN courses c ON p.id = c.program_id
        LEFT JOIN enrollments e ON c.id IN (
            SELECT course_id FROM class_batches WHERE id = e.class_id
        ) AND e.status = 'active'
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN users u2 ON p.updated_by = u2.id
        WHERE p.id = ?
        GROUP BY p.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$result = $stmt->get_result();
$program = $result->fetch_assoc();

if (!$program) {
    $_SESSION['error'] = 'Program not found';
    header('Location: index.php');
    exit();
}

// Fetch total class count
$class_count_sql = "SELECT COUNT(DISTINCT cb.id) as total_classes
                    FROM class_batches cb
                    JOIN courses c ON cb.course_id = c.id
                    WHERE c.program_id = ?";

$class_stmt = $conn->prepare($class_count_sql);
$class_stmt->bind_param("i", $program_id);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class_data = $class_result->fetch_assoc();
$total_classes = $class_data['total_classes'] ?? 0;

// Fetch class statistics by status
$class_stats_sql = "SELECT 
                    cb.status,
                    COUNT(cb.id) as count
                    FROM class_batches cb
                    JOIN courses c ON cb.course_id = c.id
                    WHERE c.program_id = ?
                    GROUP BY cb.status";

$stats_stmt = $conn->prepare($class_stats_sql);
$stats_stmt->bind_param("i", $program_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$class_stats = [];
while ($row = $stats_result->fetch_assoc()) {
    $class_stats[$row['status']] = $row['count'];
}

// Fetch related courses
$courses_sql = "SELECT c.*, 
                       COUNT(DISTINCT cb.id) as class_count,
                       COUNT(DISTINCT e.id) as student_count
                FROM courses c
                LEFT JOIN class_batches cb ON c.id = cb.course_id
                LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
                WHERE c.program_id = ?
                GROUP BY c.id
                ORDER BY c.order_number";

$courses_stmt = $conn->prepare($courses_sql);
$courses_stmt->bind_param("i", $program_id);
$courses_stmt->execute();
$courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch recent applications
$applications_sql = "SELECT a.*, u.first_name, u.last_name, u.email
                     FROM applications a
                     JOIN users u ON a.user_id = u.id
                     WHERE a.program_id = ? AND a.status = 'pending'
                     ORDER BY a.created_at DESC
                     LIMIT 5";

$applications_stmt = $conn->prepare($applications_sql);
$applications_stmt->bind_param("i", $program_id);
$applications_stmt->execute();
$applications = $applications_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch current students enrolled in this program
$enrolled_students_sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone,
                                 a.status, a.created_at as enrollment_date,
                                 a.registration_fee_paid, a.id as application_id
                          FROM applications a
                          JOIN users u ON a.user_id = u.id
                          WHERE a.program_id = ? 
                          AND a.applying_as = 'student'
                          AND a.status = 'approved'
                          ORDER BY a.created_at DESC
                          LIMIT 50"; // Increased limit to show more students

$enrolled_stmt = $conn->prepare($enrolled_students_sql);
$enrolled_stmt->bind_param("i", $program_id);
$enrolled_stmt->execute();
$enrolled_students = $enrolled_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch available students not in this program for dropdown
$available_students_sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone
                           FROM users u
                           LEFT JOIN applications a ON u.id = a.user_id AND a.program_id = ? AND a.applying_as = 'student'
                           WHERE u.role = 'student' 
                           AND u.status = 'active'
                           AND (a.id IS NULL OR a.status != 'approved')
                           ORDER BY u.first_name, u.last_name
                           LIMIT 100";

$available_stmt = $conn->prepare($available_students_sql);
$available_stmt->bind_param("i", $program_id);
$available_stmt->execute();
$available_students = $available_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity('program_view', "Viewed program: {$program['program_code']}", 'programs', $program_id);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($program['name']); ?> - Impact Digital Academy</title>
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
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .program-info {
            flex: 1;
        }

        .program-code {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            font-family: 'Courier New', monospace;
        }

        .program-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .program-status {
            display: inline-block;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .status-active {
            background: var(--success);
            color: white;
        }

        .status-inactive {
            background: var(--danger);
            color: white;
        }

        .status-upcoming {
            background: var(--warning);
            color: var(--dark);
        }

        .program-description {
            color: var(--gray);
            line-height: 1.6;
            font-size: 1.1rem;
            max-width: 800px;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-top: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.courses {
            border-top-color: var(--success);
        }

        .stat-card.classes {
            border-top-color: var(--info);
        }

        .stat-card.students {
            border-top-color: var(--accent);
        }

        .stat-card.fee {
            border-top-color: var(--warning);
        }

        .stat-card.duration {
            border-top-color: var(--primary);
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--gray);
            margin-bottom: 1rem;
            opacity: 0.7;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .class-stats {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.5rem;
            line-height: 1.4;
        }

        .class-stats span {
            display: inline-block;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .section-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--light-gray);
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .section-content {
            padding: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .detail-group {
            margin-bottom: 1.5rem;
        }

        .detail-group label {
            display: block;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-group .value {
            font-size: 1.1rem;
            color: var(--dark);
            font-weight: 500;
            line-height: 1.6;
        }

        /* Student Enrollment Form */
        .enrollment-form {
            background: #f8fafc;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Student List */
        .students-list,
        .applications-list,
        .courses-list {
            list-style: none;
        }

        .student-item,
        .application-item,
        .course-item {
            padding: 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .student-item:hover,
        .application-item:hover,
        .course-item:hover {
            border-color: var(--primary);
            background: var(--light);
            transform: translateX(5px);
        }

        .student-header,
        .application-header,
        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .student-name,
        .applicant-name,
        .course-title {
            font-weight: 600;
            color: var(--dark);
        }

        .student-email,
        .applicant-email {
            color: var(--primary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .student-phone {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .enrollment-date,
        .application-date {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .payment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .payment-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .payment-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .student-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .student-actions .btn-sm {
            flex: 1;
            min-width: 100px;
        }

        .course-code {
            font-family: 'Courier New', monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .course-status,
        .application-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active,
        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-inactive,
        .status-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .course-details {
            display: flex;
            gap: 1.5rem;
            color: var(--gray);
            font-size: 0.9rem;
            flex-wrap: wrap;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
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
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Danger button for unenroll */
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

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .page-actions {
                width: 100%;
                justify-content: center;
            }

            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-grid {
                grid-template-columns: 1fr;
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($program['program_code']); ?></span>
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="program-info">
                <div class="program-code"><?php echo htmlspecialchars($program['program_code']); ?></div>
                <h1 class="program-title"><?php echo htmlspecialchars($program['name']); ?></h1>
                <div class="program-status status-<?php echo $program['status']; ?>">
                    <?php echo ucfirst($program['status']); ?>
                </div>
                <div class="program-description">
                    <?php echo nl2br(htmlspecialchars($program['description'])); ?>
                </div>
            </div>

            <div class="page-actions">
                <a href="edit.php?id=<?php echo $program['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Program
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php if ($program['status'] === 'active'): ?>
                    <a href="?action=deactivate&id=<?php echo $program['id']; ?>"
                        class="btn btn-warning"
                        onclick="return confirm('Deactivate this program?')">
                        <i class="fas fa-pause"></i> Deactivate
                    </a>
                <?php elseif ($program['status'] === 'inactive'): ?>
                    <a href="?action=activate&id=<?php echo $program['id']; ?>"
                        class="btn btn-success"
                        onclick="return confirm('Activate this program?')">
                        <i class="fas fa-play"></i> Activate
                    </a>
                <?php endif; ?>
                <a href="?action=delete&id=<?php echo $program['id']; ?>"
                    class="btn btn-danger"
                    onclick="return confirm('Delete this program? This action cannot be undone.')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card courses" onclick="window.location.href='<?php echo BASE_URL; ?>modules/admin/academic/courses/?program=<?php echo $program['id']; ?>'">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value"><?php echo $program['course_count'] ?: '0'; ?></div>
                <div class="stat-label">Courses</div>
            </div>

            <div class="stat-card classes" onclick="window.location.href='<?php echo BASE_URL; ?>modules/admin/academic/classes/?program=<?php echo $program['id']; ?>'">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <div class="stat-value"><?php echo $total_classes ?: '0'; ?></div>
                <div class="stat-label">Total Classes</div>
                <?php if (!empty($class_stats)): ?>
                    <div class="class-stats">
                        <?php foreach ($class_stats as $status => $count): ?>
                            <span><?php echo ucfirst($status) . ": " . $count; ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card students" onclick="window.location.href='#enrolled-students'">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo count($enrolled_students) ?: '0'; ?></div>
                <div class="stat-label">Enrolled Students</div>
            </div>

            <div class="stat-card fee">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value">₦<?php echo number_format($program['fee'], 0); ?></div>
                <div class="stat-label">Program Fee</div>
            </div>

            <div class="stat-card duration">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value"><?php echo $program['duration_weeks']; ?></div>
                <div class="stat-label">Weeks</div>
            </div>
        </div>

        <!-- Hidden Unenrollment Form -->
        <form id="unenrollForm" method="POST" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="unenroll_student">
            <input type="hidden" name="student_id" id="unenroll_student_id" value="">
        </form>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column: Program Details & Courses -->
            <div>
                <!-- Student Enrollment Form -->
                <div class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-user-plus"></i> Enroll New Student</h2>
                    </div>
                    <div class="section-content">
                        <form method="POST" class="enrollment-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="student_id">
                                        <i class="fas fa-user-graduate"></i> Select Student
                                    </label>
                                    <select name="student_id" id="student_id" class="form-control" required>
                                        <option value="">-- Choose a student --</option>
                                        <?php foreach ($available_students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                (<?php echo htmlspecialchars($student['email']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($available_students)): ?>
                                        <p style="color: var(--gray); font-size: 0.9rem; margin-top: 0.5rem;">
                                            <i class="fas fa-info-circle"></i> All active students are already enrolled in this program.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="form-group">
                                    <label for="enrollment_date">
                                        <i class="fas fa-calendar-alt"></i> Enrollment Date
                                    </label>
                                    <input type="date" name="enrollment_date" id="enrollment_date"
                                        class="form-control"
                                        value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label style="color: var(--info); font-size: 0.9rem;">
                                    <i class="fas fa-info-circle"></i> This will create an approved application and mark registration fee as paid.
                                </label>
                            </div>

                            <div class="form-group" style="text-align: right;">
                                <button type="submit" name="enroll_student" class="btn btn-success">
                                    <i class="fas fa-user-plus"></i> Enroll Student
                                </button>
                            </div>
                        </form>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php?role=student"
                                class="btn btn-secondary btn-sm">
                                <i class="fas fa-plus"></i> Create New Student
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/admin/users/?role=student&status=active"
                                class="btn btn-secondary btn-sm">
                                <i class="fas fa-list"></i> View All Students
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Enrolled Students Section -->
                <div id="enrolled-students" class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Enrolled Students</h2>
                        <span style="font-size: 0.9rem; opacity: 0.9; background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 20px;">
                            <?php echo count($enrolled_students); ?> students
                        </span>
                    </div>
                    <div class="section-content">
                        <?php if (empty($enrolled_students)): ?>
                            <div class="no-data">
                                <i class="fas fa-users"></i>
                                <h3>No students enrolled</h3>
                                <p>Enroll students using the form above</p>
                            </div>
                        <?php else: ?>
                            <ul class="students-list">
                                <?php foreach ($enrolled_students as $student): ?>
                                    <li class="student-item">
                                        <div class="student-header">
                                            <div>
                                                <div class="student-name">
                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                </div>
                                                <div class="student-email">
                                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?>
                                                </div>
                                            </div>
                                            <div class="payment-status <?php echo $student['registration_fee_paid'] ? 'payment-paid' : 'payment-pending'; ?>">
                                                <?php echo $student['registration_fee_paid'] ? 'Fee Paid' : 'Fee Pending'; ?>
                                            </div>
                                        </div>

                                        <?php if ($student['phone']): ?>
                                            <div class="student-phone">
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($student['phone']); ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="enrollment-date">
                                            <i class="fas fa-calendar-check"></i>
                                            Enrolled: <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?>
                                        </div>

                                        <div class="student-actions">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $student['id']; ?>"
                                                class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> View Profile
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/transactions.php?student_id=<?php echo $student['id']; ?>&program_id=<?php echo $program_id; ?>"
                                                class="btn btn-secondary btn-sm">
                                                <i class="fas fa-money-bill"></i> Payments
                                            </a>
                                            <button type="button"
                                                class="btn btn-danger btn-sm"
                                                onclick="confirmUnenroll(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
                                                <i class="fas fa-user-minus"></i> Unenroll
                                            </button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if (count($enrolled_students) >= 10): ?>
                                <div style="text-align: center; margin-top: 1.5rem;">
                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/?program=<?php echo $program['id']; ?>&status=approved"
                                        class="btn btn-secondary">
                                        <i class="fas fa-list"></i> View All Enrolled Students
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Courses Section -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-book"></i> Courses in this Program</h2>
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="remove-courses.php?program_id=<?php echo $program['id']; ?>"
                                class="btn btn-warning"
                                style="background: #f59e0b; color: white;">
                                <i class="fas fa-trash-alt"></i> Remove Courses
                            </a>
                            <a href="select-courses.php?target_program_id=<?php echo $program['id']; ?>"
                                class="btn btn-primary" style="background: var(--info); color: white;">
                                <i class="fas fa-copy"></i> Add from Other Programs
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/create.php?program_id=<?php echo $program['id']; ?>"
                                class="btn btn-primary" style="background: white; color: var(--primary);">
                                <i class="fas fa-plus"></i> Create New Course
                            </a>
                        </div>
                    </div>
                    <div class="section-content">
                        <?php if (empty($courses)): ?>
                            <div class="no-data">
                                <i class="fas fa-book"></i>
                                <h3>No courses yet</h3>
                                <p>Add courses to this program</p>
                            </div>
                        <?php else: ?>
                            <ul class="courses-list">
                                <?php foreach ($courses as $course): ?>
                                    <li class="course-item">
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div style="text-align: center; margin-top: 1.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/?program=<?php echo $program['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-list"></i> View All Courses
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Applications & Actions -->
            <div>
                <!-- Program Details -->
                <div class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-info-circle"></i> Program Details</h2>
                    </div>
                    <div class="section-content">
                        <div class="detail-grid">
                            <div class="detail-group">
                                <label>Program Code</label>
                                <div class="value"><?php echo htmlspecialchars($program['program_code']); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Status</label>
                                <div class="value">
                                    <span class="program-status status-<?php echo $program['status']; ?>">
                                        <?php echo ucfirst($program['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="detail-group">
                                <label>Duration</label>
                                <div class="value"><?php echo $program['duration_weeks']; ?> weeks</div>
                            </div>

                            <div class="detail-group">
                                <label>Program Fee</label>
                                <div class="value">₦<?php echo number_format($program['fee'], 2); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Program Type</label>
                                <div class="value"><?php echo ucfirst($program['program_type'] ?? 'online'); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Created By</label>
                                <div class="value">
                                    <?php if ($program['creator_first_name']): ?>
                                        <?php echo htmlspecialchars($program['creator_first_name'] . ' ' . $program['creator_last_name']); ?>
                                    <?php else: ?>
                                        <em>System</em>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="detail-group">
                                <label>Created Date</label>
                                <div class="value"><?php echo date('F j, Y \a\t h:i A', strtotime($program['created_at'])); ?></div>
                            </div>

                            <div class="detail-group">
                                <label>Last Updated</label>
                                <div class="value"><?php echo date('F j, Y \a\t h:i A', strtotime($program['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Applications -->
                <div class="section-card" style="margin-bottom: 2rem;">
                    <div class="section-header">
                        <h2><i class="fas fa-file-alt"></i> Pending Applications</h2>
                    </div>
                    <div class="section-content">
                        <?php if (empty($applications)): ?>
                            <div class="no-data">
                                <i class="fas fa-file-alt"></i>
                                <h3>No pending applications</h3>
                                <p>All applications have been reviewed</p>
                            </div>
                        <?php else: ?>
                            <ul class="applications-list">
                                <?php foreach ($applications as $application): ?>
                                    <li class="application-item">
                                        <div class="application-header">
                                            <div class="applicant-name">
                                                <?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?>
                                            </div>
                                            <div class="application-date">
                                                <?php echo date('M j', strtotime($application['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="applicant-email">
                                            <?php echo htmlspecialchars($application['email']); ?>
                                        </div>
                                        <div class="application-status status-<?php echo $application['status']; ?>">
                                            <?php echo ucfirst($application['status']); ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div style="text-align: center; margin-top: 1.5rem;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/applications/?program=<?php echo $program['id']; ?>&status=pending"
                                    class="btn btn-secondary">
                                    <i class="fas fa-eye"></i> View All Applications
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>
                    <div class="section-content">
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php?program=<?php echo $program['id']; ?>"
                                class="btn btn-primary">
                                <i class="fas fa-chalkboard"></i> Create New Class
                            </a>

                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="select-courses.php?target_program_id=<?php echo $program['id']; ?>"
                                    class="btn btn-secondary" style="flex: 1;">
                                    <i class="fas fa-copy"></i> Copy Courses
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/create.php?program_id=<?php echo $program['id']; ?>"
                                    class="btn btn-secondary" style="flex: 1;">
                                    <i class="fas fa-plus"></i> New Course
                                </a>
                            </div>

                            <a href="<?php echo BASE_URL; ?>modules/admin/reports/?type=program&id=<?php echo $program['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-chart-bar"></i> Generate Report
                            </a>

                            <a href="edit.php?id=<?php echo $program['id']; ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-cog"></i> Program Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle URL actions
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');

        if (action && id && id === '<?php echo $program_id; ?>') {
            // Actions are handled server-side, just show confirmation
            const actionMessages = {
                'activate': 'Program has been activated successfully.',
                'deactivate': 'Program has been deactivated successfully.',
                'delete': 'Program has been deleted. Redirecting...'
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

        // Set today's date as default enrollment date
        document.getElementById('enrollment_date').value = new Date().toISOString().split('T')[0];

        // Student search functionality
        const studentSelect = document.getElementById('student_id');
        if (studentSelect) {
            studentSelect.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                const options = e.target.options;

                if (searchTerm.length > 1) {
                    // Highlight matching options
                    for (let i = 0; i < options.length; i++) {
                        const option = options[i];
                        const text = option.text.toLowerCase();
                        if (text.includes(searchTerm)) {
                            option.style.backgroundColor = '#e8f4ff';
                        } else {
                            option.style.backgroundColor = '';
                        }
                    }
                }
            });
        }

        // Unenroll student functionality
        function confirmUnenroll(studentId, studentName) {
            if (confirm(`Are you sure you want to unenroll ${studentName} from this program?\n\nThis action will:\n• Remove the student from the program\n• Delete all their class enrollments\n• Remove their submissions and grades\n• Delete payment records\n• Mark their application as rejected\n\nThis action cannot be undone.`)) {
                document.getElementById('unenroll_student_id').value = studentId;
                document.getElementById('unenrollForm').submit();
            }
        }

        // Auto-refresh statistics every 30 seconds (optional)
        setInterval(() => {
            fetch(window.location.href + '&refresh=1')
                .then(response => response.text())
                .then(html => {
                    console.log('Refreshed program data');
                })
                .catch(error => console.error('Refresh failed:', error));
        }, 30000);
    </script>
</body>

</html>

<?php
// Close database statements
$stmt->close();
$class_stmt->close();
$stats_stmt->close();
$courses_stmt->close();
$applications_stmt->close();
$enrolled_stmt->close();
$available_stmt->close();
$conn->close();
?>