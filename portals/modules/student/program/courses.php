<?php
// modules/student/program/courses.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];
$user_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($user_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

// ============================================
// GET STUDENT'S PROGRAM AND ENROLLMENT STATUS
// ============================================

$program = [];
$enrollment_status = 'none';
$has_class_enrollment = false;
$class_count = 0;
$enrolled_courses = [];
$completed_courses = [];

// Check if student has an approved program application
$app_sql = "SELECT p.*, a.id as application_id, a.status as application_status, 
                   a.created_at as application_date, a.program_type,
                   a.preferred_term, a.preferred_block
            FROM applications a
            JOIN programs p ON a.program_id = p.id
            WHERE a.user_id = ? 
            AND a.status = 'approved'
            AND a.applying_as = 'student'
            ORDER BY a.created_at DESC
            LIMIT 1";

$app_stmt = $conn->prepare($app_sql);
$app_stmt->bind_param("i", $user_id);
$app_stmt->execute();
$app_result = $app_stmt->get_result();

if ($app_result->num_rows > 0) {
    $program = $app_result->fetch_assoc();
    $enrollment_status = 'approved';
}
$app_stmt->close();

// If student has approved program, check for class enrollments
if (!empty($program)) {
    // Get active class enrollments
    $enrollment_sql = "SELECT e.*, cb.*, c.*, 
                              u.first_name as instructor_first, u.last_name as instructor_last
                       FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       JOIN courses c ON cb.course_id = c.id
                       LEFT JOIN users u ON cb.instructor_id = u.id
                       WHERE e.student_id = ? 
                       AND e.status = 'active'
                       AND c.program_id = ?
                       ORDER BY cb.start_date DESC";

    $enroll_stmt = $conn->prepare($enrollment_sql);
    $enroll_stmt->bind_param("ii", $user_id, $program['id']);
    $enroll_stmt->execute();
    $enroll_result = $enroll_stmt->get_result();
    $enrolled_courses = $enroll_result->fetch_all(MYSQLI_ASSOC);
    $class_count = count($enrolled_courses);
    $has_class_enrollment = $class_count > 0;

    if ($has_class_enrollment) {
        $enrollment_status = 'enrolled';
    }
    $enroll_stmt->close();

    // Get completed courses
    $completed_sql = "SELECT e.*, cb.*, c.*, e.final_grade, e.completion_date
                      FROM enrollments e
                      JOIN class_batches cb ON e.class_id = cb.id
                      JOIN courses c ON cb.course_id = c.id
                      WHERE e.student_id = ? 
                      AND e.status = 'completed'
                      AND c.program_id = ?
                      AND e.final_grade IS NOT NULL
                      ORDER BY e.completion_date DESC";

    $completed_stmt = $conn->prepare($completed_sql);
    $completed_stmt->bind_param("ii", $user_id, $program['id']);
    $completed_stmt->execute();
    $completed_result = $completed_stmt->get_result();
    $completed_courses = $completed_result->fetch_all(MYSQLI_ASSOC);
    $completed_stmt->close();
}

// Redirect if no program enrolled
if ($enrollment_status === 'none') {
    header('Location: ' . BASE_URL . 'modules/student/program/');
    exit();
}

// ============================================
// GET ALL COURSES IN THE PROGRAM
// ============================================

$program_courses = [];
$core_courses = [];
$elective_courses = [];
$course_status = []; // Track status of each course for the student

if (!empty($program)) {
    // Get all courses in the program
    $courses_sql = "SELECT c.*, pr.course_type, pr.is_required, pr.min_grade,
                           pc.course_code as prereq_code, pc.title as prereq_title
                    FROM courses c
                    JOIN program_requirements pr ON c.id = pr.course_id
                    LEFT JOIN courses pc ON c.prerequisite_course_id = pc.id
                    WHERE pr.program_id = ?
                    ORDER BY c.order_number, c.course_code";

    $courses_stmt = $conn->prepare($courses_sql);
    $courses_stmt->bind_param("i", $program['id']);
    $courses_stmt->execute();
    $courses_result = $courses_stmt->get_result();
    $all_courses = $courses_result->fetch_all(MYSQLI_ASSOC);
    $courses_stmt->close();

    // Check which courses are prerequisites for others
    $prerequisite_for = [];
    $prereq_sql = "SELECT DISTINCT prerequisite_course_id FROM courses WHERE prerequisite_course_id IS NOT NULL";
    $prereq_stmt = $conn->prepare($prereq_sql);
    $prereq_stmt->execute();
    $prereq_result = $prereq_stmt->get_result();
    while ($row = $prereq_result->fetch_assoc()) {
        $prerequisite_for[] = $row['prerequisite_course_id'];
    }
    $prereq_stmt->close();

    // Separate core and elective courses and determine status
    foreach ($all_courses as $course) {
        $course_id = $course['id'];

        // Determine course status
        $status = 'available';
        $enrollment_id = null;
        $grade = null;
        $completion_date = null;

        // Check if enrolled
        foreach ($enrolled_courses as $enrolled) {
            if ($enrolled['course_id'] == $course_id) {
                $status = 'enrolled';
                $enrollment_id = $enrolled['id'];
                break;
            }
        }

        // Check if completed
        if ($status === 'available') {
            foreach ($completed_courses as $completed) {
                if ($completed['course_id'] == $course_id) {
                    $status = 'completed';
                    $grade = $completed['final_grade'];
                    $completion_date = $completed['completion_date'];
                    break;
                }
            }
        }

        // Check prerequisites
        if ($status === 'available' && !empty($course['prerequisite_course_id'])) {
            $prereq_completed = false;
            foreach ($completed_courses as $completed) {
                if ($completed['course_id'] == $course['prerequisite_course_id']) {
                    $prereq_completed = true;
                    break;
                }
            }

            if (!$prereq_completed) {
                $status = 'prerequisite_required';
            }
        }

        // Check if this course is a prerequisite for others
        $is_prerequisite_for = in_array($course_id, $prerequisite_for);

        // Add status info to course
        $course['status'] = $status;
        $course['enrollment_id'] = $enrollment_id;
        $course['grade'] = $grade;
        $course['completion_date'] = $completion_date;
        $course['is_prerequisite_for'] = $is_prerequisite_for;

        $program_courses[] = $course;

        if ($course['course_type'] == 'core') {
            $core_courses[] = $course;
        } else {
            $elective_courses[] = $course;
        }
    }
}

// ============================================
// GET PROGRAM META DATA
// ============================================

$program_meta = [];
$required_elective_min = 0;
$required_elective_max = 0;

if (!empty($program)) {
    $meta_sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
    $meta_stmt = $conn->prepare($meta_sql);
    $meta_stmt->bind_param("i", $program['id']);
    $meta_stmt->execute();
    $meta_result = $meta_stmt->get_result();
    if ($meta_result->num_rows > 0) {
        $program_meta = $meta_result->fetch_assoc();
        $required_elective_min = $program_meta['min_electives'] ?? 0;
        $required_elective_max = $program_meta['max_electives'] ?? 0;
    }
    $meta_stmt->close();
}

// ============================================
// CALCULATE STATISTICS
// ============================================

$stats = [
    'total_courses' => count($program_courses),
    'core_courses' => count($core_courses),
    'elective_courses' => count($elective_courses),
    'enrolled_courses' => count($enrolled_courses),
    'completed_courses' => count($completed_courses),
    'available_courses' => 0,
    'prerequisite_locked' => 0
];

foreach ($program_courses as $course) {
    if ($course['status'] === 'available') {
        $stats['available_courses']++;
    } elseif ($course['status'] === 'prerequisite_required') {
        $stats['prerequisite_locked']++;
    }
}

// Log activity
logActivity($user_id, 'courses_view', 'Student viewed program courses', $_SERVER['REMOTE_ADDR']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>All Courses - <?php echo htmlspecialchars($program['name'] ?? 'My Program'); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --sidebar-bg: #1e293b;
            --sidebar-text: #cbd5e1;
            --sidebar-hover: #334155;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Main Content Styles */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: var(--transition);
            position: relative;
        }

        .sidebar.collapsed~.main-content {
            margin-left: 70px;
        }

        .sidebar.hidden~.main-content {
            margin-left: 0;
        }

        .top-bar {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            min-height: 80px;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Content Containers */
        .content-container {
            padding: 0 1.5rem 1.5rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .card-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-box {
            background: #f8f9fa;
            padding: 1.25rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filter Controls */
        .filter-controls {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .filter-row {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 768px) {
            .filter-row {
                flex-direction: row;
                align-items: center;
            }
        }

        .filter-group {
            flex: 1;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-select,
        .filter-search {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .filter-select:focus,
        .filter-search:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Course Grid */
        .course-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .course-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .course-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        .course-card {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .course-card.completed {
            border-left-color: var(--success);
        }

        .course-card.enrolled {
            border-left-color: var(--info);
        }

        .course-card.available {
            border-left-color: #28a745;
        }

        .course-card.prerequisite_required {
            border-left-color: var(--warning);
            opacity: 0.7;
        }

        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .course-title h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .course-code {
            font-size: 0.75rem;
            color: var(--gray);
            font-weight: 600;
        }

        .course-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge-core {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .badge-elective {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-enrolled {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .status-available {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .status-prerequisite {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .course-details {
            margin: 0.75rem 0;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .course-details div {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
            flex-wrap: wrap;
        }

        .course-footer {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border);
        }

        @media (min-width: 480px) {
            .course-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .course-status {
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            text-align: center;
            white-space: nowrap;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-success {
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-info {
            background-color: var(--info);
            color: white;
        }

        .btn-info:hover {
            background-color: #2185d0;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background-color: #e1156d;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        .btn-block {
            width: 100%;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Course Type Tabs */
        .course-type-tabs {
            display: flex;
            border-bottom: 2px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            position: relative;
        }

        .tab:hover {
            color: var(--primary);
        }

        .tab.active {
            color: var(--primary);
            font-weight: 600;
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-badge {
            background: var(--primary);
            color: white;
            padding: 0.125rem 0.375rem;
            border-radius: 10px;
            font-size: 0.625rem;
            margin-left: 0.25rem;
            font-weight: 600;
        }

        /* Prerequisite Warning */
        .prerequisite-warning {
            background: rgba(247, 37, 133, 0.05);
            border: 1px solid rgba(247, 37, 133, 0.2);
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--warning);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Grade Display */
        .grade-display {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 0.875rem;
        }

        .grade-A {
            background: #28a745;
            color: white;
        }

        .grade-B {
            background: #17a2b8;
            color: white;
        }

        .grade-C {
            background: #ffc107;
            color: #212529;
        }

        .grade-D {
            background: #fd7e14;
            color: white;
        }

        .grade-F {
            background: #dc3545;
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }

        .modal-close:hover {
            background: #f8f9fa;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            padding: 0.75rem 1.5rem;
            background: white;
            border-bottom: 1px solid var(--border);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Print Button */
        .print-btn {
            background: var(--light);
            color: var(--dark);
            border: 1px solid var(--border);
        }

        .print-btn:hover {
            background: #e9ecef;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .top-bar {
                padding: 1rem;
                flex-wrap: wrap;
                gap: 1rem;
            }

            .content-container {
                padding: 0 1rem 1rem;
            }

            .card {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-row {
                gap: 0.75rem;
            }

            .course-type-tabs {
                justify-content: flex-start;
            }

            .tab {
                padding: 0.75rem 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .course-footer {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">Dashboard</a> &gt;
        <a href="<?php echo BASE_URL; ?>modules/student/program/">My Program</a> &gt;
        <span>All Courses</span>
    </div>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1>All Courses - <?php echo htmlspecialchars($program['name']); ?></h1>
            <p>Browse and filter all courses in your program</p>
        </div>
        <div class="top-actions">
            <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Program
            </a>
        </div>
    </div>

    <div class="content-container">
        <!-- Statistics -->
        <div class="card">
            <div class="card-header">
                <h2>Course Overview</h2>
            </div>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?php echo $stats['total_courses']; ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $stats['core_courses']; ?></div>
                    <div class="stat-label">Core Courses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $stats['elective_courses']; ?></div>
                    <div class="stat-label">Elective Courses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $stats['completed_courses']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="filter-controls">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label">Course Type</label>
                    <select class="filter-select" id="filterType">
                        <option value="all">All Types</option>
                        <option value="core">Core Courses</option>
                        <option value="elective">Elective Courses</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-select" id="filterStatus">
                        <option value="all">All Status</option>
                        <option value="completed">Completed</option>
                        <option value="enrolled">Currently Enrolled</option>
                        <option value="available">Available</option>
                        <option value="prerequisite_required">Prerequisite Required</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Search Courses</label>
                    <input type="text" class="filter-search" id="filterSearch" placeholder="Search by course name or code...">
                </div>
            </div>
            <button class="btn btn-primary" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <button class="btn print-btn" onclick="printCourses()" style="margin-left: 0.5rem;">
                <i class="fas fa-print"></i> Print
            </button>
        </div>

        <!-- Course Type Tabs -->
        <div class="course-type-tabs">
            <button class="tab active" onclick="showTab('all')">
                All Courses
                <span class="tab-badge"><?php echo $stats['total_courses']; ?></span>
            </button>
            <button class="tab" onclick="showTab('core')">
                Core Courses
                <span class="tab-badge"><?php echo $stats['core_courses']; ?></span>
            </button>
            <button class="tab" onclick="showTab('elective')">
                Elective Courses
                <span class="tab-badge"><?php echo $stats['elective_courses']; ?></span>
            </button>
            <button class="tab" onclick="showTab('enrolled')">
                Enrolled
                <span class="tab-badge"><?php echo $stats['enrolled_courses']; ?></span>
            </button>
            <button class="tab" onclick="showTab('completed')">
                Completed
                <span class="tab-badge"><?php echo $stats['completed_courses']; ?></span>
            </button>
        </div>

        <!-- Courses Grid -->
        <div id="coursesGrid">
            <?php if (empty($program_courses)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>No Courses Found</h3>
                        <p>There are no courses available in your program.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="course-grid">
                    <?php foreach ($program_courses as $course): ?>
                        <?php
                        $status_class = $course['status'];
                        $status_label = ucfirst(str_replace('_', ' ', $course['status']));
                        $status_color = '';

                        switch ($course['status']) {
                            case 'completed':
                                $status_color = 'var(--success)';
                                break;
                            case 'enrolled':
                                $status_color = 'var(--info)';
                                break;
                            case 'available':
                                $status_color = '#28a745';
                                break;
                            case 'prerequisite_required':
                                $status_color = 'var(--warning)';
                                break;
                        }

                        // Grade class for styling
                        $grade_class = '';
                        if ($course['grade']) {
                            $first_char = strtoupper(substr($course['grade'], 0, 1));
                            if (in_array($first_char, ['A', 'B', 'C', 'D', 'F'])) {
                                $grade_class = 'grade-' . $first_char;
                            }
                        }
                        ?>
                        <div class="course-card <?php echo $status_class; ?>"
                            data-type="<?php echo $course['course_type']; ?>"
                            data-status="<?php echo $course['status']; ?>"
                            data-title="<?php echo htmlspecialchars(strtolower($course['title'] . ' ' . $course['course_code'])); ?>">

                            <div class="course-header">
                                <div class="course-title">
                                    <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                </div>
                                <span class="course-badge <?php echo $course['course_type'] == 'core' ? 'badge-core' : 'badge-elective'; ?>">
                                    <?php echo $course['course_type']; ?>
                                </span>
                            </div>

                            <span class="status-badge status-<?php echo $course['status']; ?>">
                                <?php echo $status_label; ?>
                            </span>

                            <div class="course-details">
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo $course['duration_hours']; ?> hours</span>
                                </div>
                                <div>
                                    <i class="fas fa-star"></i>
                                    <span>Min Grade: <?php echo $course['min_grade']; ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-layer-group"></i>
                                    <span>
                                        <?php echo $course['is_required'] ? 'Required' : 'Optional'; ?>
                                        <?php echo $course['course_type']; ?>
                                    </span>
                                </div>
                                <?php if (!empty($course['prereq_code'])): ?>
                                    <div>
                                        <i class="fas fa-link"></i>
                                        <span>Prerequisite: <?php echo htmlspecialchars($course['prereq_code']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($course['is_prerequisite_for']): ?>
                                    <div>
                                        <i class="fas fa-external-link-alt"></i>
                                        <span>Required for other courses</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($course['status'] === 'prerequisite_required' && !empty($course['prereq_code'])): ?>
                                <div class="prerequisite-warning">
                                    <i class="fas fa-lock"></i>
                                    <span>Complete <?php echo htmlspecialchars($course['prereq_code']); ?> first</span>
                                </div>
                            <?php endif; ?>

                            <div class="course-footer">
                                <div class="course-status">
                                    <?php if ($course['status'] === 'completed'): ?>
                                        <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                        <span>
                                            Grade:
                                            <?php if ($course['grade']): ?>
                                                <span class="grade-display <?php echo $grade_class; ?>" title="Grade: <?php echo $course['grade']; ?>">
                                                    <?php echo $course['grade']; ?>
                                                </span>
                                                (<?php echo date('M Y', strtotime($course['completion_date'])); ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif ($course['status'] === 'enrolled'): ?>
                                        <i class="fas fa-spinner" style="color: var(--info);"></i>
                                        <span>Currently Enrolled</span>
                                    <?php elseif ($course['status'] === 'available'): ?>
                                        <i class="fas fa-check" style="color: #28a745;"></i>
                                        <span>Available for Registration</span>
                                    <?php elseif ($course['status'] === 'prerequisite_required'): ?>
                                        <i class="fas fa-lock" style="color: var(--warning);"></i>
                                        <span>Prerequisite Required</span>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if ($course['status'] === 'enrolled' && !empty($course['enrollment_id'])): ?>
                                        <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?class_id=<?php echo $course['enrollment_id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-chalkboard"></i> View Class
                                        </a>
                                    <?php elseif ($course['status'] === 'available'): ?>
                                        <button class="btn btn-success btn-sm" onclick="showRegistrationModal('<?php echo htmlspecialchars($course['course_code']); ?>', '<?php echo htmlspecialchars($course['title']); ?>')">
                                            <i class="fas fa-calendar-plus"></i> Register
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn btn-primary btn-sm" onclick="showCourseDetails('<?php echo htmlspecialchars(addslashes(json_encode($course))); ?>')">
                                        <i class="fas fa-info-circle"></i> Details
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Program Requirements Info -->
        <div class="card">
            <div class="card-header">
                <h2>Program Requirements</h2>
            </div>
            <div style="padding: 1rem;">
                <p>To complete the <strong><?php echo htmlspecialchars($program['name']); ?></strong> program, you must meet the following requirements:</p>

                <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                    <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Graduation Requirements:</h4>
                    <ul style="margin-left: 1.5rem; color: var(--gray);">
                        <li>Complete all <strong><?php echo count($core_courses); ?> core courses</strong> (required)</li>
                        <li>Complete at least <strong><?php echo $required_elective_min; ?> elective courses</strong> (minimum requirement)</li>
                        <li>Achieve a minimum grade of <strong><?php echo htmlspecialchars($program_meta['min_grade_required'] ?? 'C'); ?></strong> in all required courses</li>
                        <?php if ($required_elective_max > 0): ?>
                            <li>You may take up to <strong><?php echo $required_elective_max; ?> elective courses</strong> (maximum)</li>
                        <?php endif; ?>
                    </ul>

                    <?php if (!empty($program_meta['graduation_requirements'])): ?>
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                            <h5 style="margin-bottom: 0.5rem; color: var(--dark);">Additional Requirements:</h5>
                            <p style="color: var(--gray);"><?php echo htmlspecialchars($program_meta['graduation_requirements']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
                    <div style="flex: 1; min-width: 250px;">
                        <h5 style="margin-bottom: 0.5rem; color: var(--dark);">Your Progress:</h5>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div style="flex: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                <div style="height: 100%; background: var(--primary); width: <?php echo min(100, round((count($completed_courses) / (count($core_courses) + $required_elective_min)) * 100)); ?>%;"></div>
                            </div>
                            <span style="font-size: 0.875rem; font-weight: 600; color: var(--primary);">
                                <?php echo count($completed_courses); ?>/<?php echo count($core_courses) + $required_elective_min; ?> courses
                            </span>
                        </div>
                    </div>

                    <a href="<?php echo BASE_URL; ?>modules/student/program/graduation.php" class="btn btn-primary">
                        <i class="fas fa-award"></i> View Graduation Status
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Details Modal -->
    <div class="modal" id="courseDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalCourseTitle"></h3>
                <button class="modal-close" onclick="closeModal('courseDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="modalCourseBody">
                <!-- Course details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('courseDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Registration Modal -->
    <div class="modal" id="registrationModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Register for Course</h3>
                <button class="modal-close" onclick="closeModal('registrationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Registering for: <strong id="registerCourseTitle"></strong></p>
                <p style="margin-top: 1rem; color: var(--gray);">
                    <i class="fas fa-info-circle"></i>
                    You will be redirected to the course registration page where you can select available class batches.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('registrationModal')">Cancel</button>
                <button class="btn btn-success" onclick="proceedToRegistration()">Proceed to Registration</button>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        function showTab(tabName) {
            // Update active tab
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // Filter courses
            applyFilters();
        }

        // Filter functionality
        function applyFilters() {
            const typeFilter = document.getElementById('filterType').value;
            const statusFilter = document.getElementById('filterStatus').value;
            const searchFilter = document.getElementById('filterSearch').value.toLowerCase();
            const activeTab = document.querySelector('.tab.active').textContent.toLowerCase();

            let visibleCount = 0;

            document.querySelectorAll('.course-card').forEach(card => {
                const courseType = card.getAttribute('data-type');
                const courseStatus = card.getAttribute('data-status');
                const courseTitle = card.getAttribute('data-title');

                // Check tab filter
                let tabMatch = true;
                if (activeTab.includes('core') && courseType !== 'core') tabMatch = false;
                if (activeTab.includes('elective') && courseType !== 'elective') tabMatch = false;
                if (activeTab.includes('enrolled') && courseStatus !== 'enrolled') tabMatch = false;
                if (activeTab.includes('completed') && courseStatus !== 'completed') tabMatch = false;

                // Check type filter
                const typeMatch = typeFilter === 'all' || courseType === typeFilter;

                // Check status filter
                const statusMatch = statusFilter === 'all' || courseStatus === statusFilter;

                // Check search filter
                const searchMatch = searchFilter === '' || courseTitle.includes(searchFilter);

                // Show/hide card
                if (tabMatch && typeMatch && statusMatch && searchMatch) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Show empty state if no cards visible
            const emptyState = document.getElementById('emptyState');
            if (visibleCount === 0) {
                if (!emptyState) {
                    const coursesGrid = document.getElementById('coursesGrid');
                    const emptyDiv = document.createElement('div');
                    emptyDiv.className = 'card';
                    emptyDiv.id = 'emptyState';
                    emptyDiv.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No Courses Found</h3>
                            <p>No courses match your current filters.</p>
                            <button class="btn btn-primary" onclick="resetFilters()" style="margin-top: 1rem;">
                                Reset Filters
                            </button>
                        </div>
                    `;
                    coursesGrid.appendChild(emptyDiv);
                }
            } else if (emptyState) {
                emptyState.remove();
            }
        }

        function resetFilters() {
            document.getElementById('filterType').value = 'all';
            document.getElementById('filterStatus').value = 'all';
            document.getElementById('filterSearch').value = '';
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector('.tab:first-child').classList.add('active');
            applyFilters();
        }

        // Course details modal
        let selectedCourse = null;

        function showCourseDetails(courseJson) {
            try {
                const course = JSON.parse(courseJson.replace(/\\'/g, "'"));
                selectedCourse = course;
                const modal = document.getElementById('courseDetailsModal');
                const title = document.getElementById('modalCourseTitle');
                const body = document.getElementById('modalCourseBody');

                title.textContent = course.course_code + ' - ' + course.title;

                // Grade class for styling
                let gradeClass = '';
                if (course.grade) {
                    const firstChar = course.grade.charAt(0).toUpperCase();
                    if (['A', 'B', 'C', 'D', 'F'].includes(firstChar)) {
                        gradeClass = 'grade-' + firstChar;
                    }
                }

                let html = `
                    <div style="margin-bottom: 1rem;">
                        <h4 style="color: var(--dark); margin-bottom: 0.5rem;">Course Information</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                            <div>
                                <strong>Course Code:</strong><br>
                                ${course.course_code}
                            </div>
                            <div>
                                <strong>Type:</strong><br>
                                <span class="badge ${course.course_type === 'core' ? 'badge-core' : 'badge-elective'}">
                                    ${course.course_type}
                                </span>
                            </div>
                            <div>
                                <strong>Duration:</strong><br>
                                ${course.duration_hours} hours
                            </div>
                            <div>
                                <strong>Min Grade Required:</strong><br>
                                ${course.min_grade}
                            </div>
                            <div>
                                <strong>Status:</strong><br>
                                <span class="status-badge status-${course.status}">
                                    ${course.status.replace('_', ' ')}
                                </span>
                            </div>
                `;

                if (course.grade) {
                    html += `
                            <div>
                                <strong>Your Grade:</strong><br>
                                <span class="grade-display ${gradeClass}">
                                    ${course.grade}
                                </span>
                            </div>
                    `;
                }

                html += `
                        </div>
                        
                        ${course.description ? `
                            <div style="margin-bottom: 1rem;">
                                <strong>Description:</strong><br>
                                <p style="margin-top: 0.5rem; color: var(--gray);">${course.description}</p>
                            </div>
                        ` : ''}
                        
                        ${course.prereq_code ? `
                            <div style="margin-bottom: 1rem;">
                                <strong>Prerequisite:</strong><br>
                                <p style="margin-top: 0.5rem;">
                                    <i class="fas fa-link"></i> 
                                    ${course.prereq_title} (${course.prereq_code})
                                </p>
                            </div>
                        ` : ''}
                        
                        ${course.is_prerequisite_for ? `
                            <div style="margin-bottom: 1rem;">
                                <strong>Important:</strong><br>
                                <p style="margin-top: 0.5rem; color: var(--warning);">
                                    <i class="fas fa-exclamation-circle"></i>
                                    This course is a prerequisite for other courses in the program.
                                </p>
                            </div>
                        ` : ''}
                    </div>
                `;

                body.innerHTML = html;
                modal.classList.add('active');
            } catch (error) {
                console.error('Error parsing course data:', error);
                alert('Error loading course details. Please try again.');
            }
        }

        // Registration modal
        let courseToRegister = null;

        function showRegistrationModal(courseCode, courseTitle) {
            courseToRegister = courseCode;
            document.getElementById('registerCourseTitle').textContent = courseCode + ' - ' + courseTitle;
            document.getElementById('registrationModal').classList.add('active');
        }

        function proceedToRegistration() {
            if (courseToRegister) {
                window.location.href = `available_periods.php?course=${encodeURIComponent(courseToRegister)}`;
            }
        }

        // Modal close
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Print functionality
        function printCourses() {
            const printWindow = window.open('', '_blank');

            const programName = '<?php echo addslashes($program['name']); ?>';
            const studentName = '<?php echo addslashes($user_details['first_name'] . ' ' . $user_details['last_name']); ?>';
            const totalCourses = <?php echo $stats['total_courses']; ?>;
            const coreCourses = <?php echo $stats['core_courses']; ?>;
            const electiveCourses = <?php echo $stats['elective_courses']; ?>;
            const completedCourses = <?php echo $stats['completed_courses']; ?>;
            const enrolledCourses = <?php echo $stats['enrolled_courses']; ?>;

            // Collect course data
            const courses = [];
            document.querySelectorAll('.course-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const title = card.querySelector('.course-title h4').textContent;
                    const code = card.querySelector('.course-code').textContent;
                    const type = card.querySelector('.course-badge').textContent;
                    const status = card.querySelector('.status-badge').textContent;
                    const hours = card.querySelector('.course-details div:nth-child(1) span').textContent;
                    const minGrade = card.querySelector('.course-details div:nth-child(2) span').textContent;

                    courses.push({
                        title,
                        code,
                        type,
                        status,
                        hours,
                        minGrade
                    });
                }
            });

            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Program Courses - ${programName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #333; margin-bottom: 10px; }
                        h2 { color: #555; margin-top: 20px; }
                        .course { border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px; }
                        .course-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
                        .course-code { font-weight: bold; color: #4361ee; }
                        .course-type { background: #f0f0f0; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
                        .course-details { font-size: 14px; color: #666; }
                        .course-details div { margin-bottom: 5px; }
                        .status { font-size: 12px; padding: 2px 8px; border-radius: 3px; }
                        .status-completed { background: #d4edda; color: #155724; }
                        .status-enrolled { background: #d1ecf1; color: #0c5460; }
                        .status-available { background: #d1f2eb; color: #0d6251; }
                        .status-prerequisite_required { background: #f8d7da; color: #721c24; }
                        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        .table th { background: #f8f9fa; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Program Courses - ${programName}</h1>
                    <p>Student: ${studentName}</p>
                    <p>Printed: ${new Date().toLocaleString()}</p>
                    
                    <h2>Course Summary</h2>
                    <table class="table">
                        <tr>
                            <th>Total Courses</th>
                            <th>Core Courses</th>
                            <th>Elective Courses</th>
                            <th>Completed</th>
                            <th>Enrolled</th>
                        </tr>
                        <tr>
                            <td>${totalCourses}</td>
                            <td>${coreCourses}</td>
                            <td>${electiveCourses}</td>
                            <td>${completedCourses}</td>
                            <td>${enrolledCourses}</td>
                        </tr>
                    </table>
                    
                    <h2>All Courses</h2>
                    ${courses.map(course => `
                        <div class="course">
                            <div class="course-header">
                                <div>
                                    <strong>${course.title}</strong><br>
                                    <span class="course-code">${course.code}</span>
                                </div>
                                <span class="course-type">${course.type}</span>
                            </div>
                            <div class="course-details">
                                <div>${course.hours}</div>
                                <div>${course.minGrade}</div>
                                <div><span class="status status-${course.status.toLowerCase().replace(' ', '-')}">${course.status}</span></div>
                            </div>
                        </div>
                    `).join('')}
                    
                    <div class="no-print" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #999;">
                        Printed from Impact Digital Academy Student Portal
                    </div>
                </body>
                </html>
            `;

            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }

        // Initialize filters
        document.addEventListener('DOMContentLoaded', function() {
            applyFilters();

            // Close modals on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal('courseDetailsModal');
                    closeModal('registrationModal');
                }
            });

            // Close modals on outside click
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });

            // Search on enter key
            document.getElementById('filterSearch').addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
        });
    </script>
</body>

</html>

<?php $conn->close(); ?>