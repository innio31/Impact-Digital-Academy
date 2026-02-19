<?php
// modules/student/program/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

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
$enrollment_status = 'none'; // none, approved, enrolled
$has_class_enrollment = false;
$class_count = 0;
$enrolled_courses = [];
$completed_courses = [];
$active_classes = []; // Classes that have actually started
$upcoming_classes = []; // Classes that are registered but haven't started

// Check if student has an approved program application
$app_sql = "SELECT p.*, a.id as application_id, a.status as application_status, 
                   a.created_at as application_date, a.program_type,
                   a.preferred_term, a.preferred_block
            FROM applications a
            JOIN programs p ON a.program_id = p.id
            WHERE a.user_id = ? 
            AND a.status = 'approved'
            -- REMOVE THIS: AND a.applying_as = 'student'
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
    // Get ALL class enrollments (both active and upcoming)
    $enrollment_sql = "SELECT e.*, cb.*, c.*, 
                              u.first_name as instructor_first, u.last_name as instructor_last
                       FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       JOIN courses c ON cb.course_id = c.id
                       LEFT JOIN users u ON cb.instructor_id = u.id
                       WHERE e.student_id = ? 
                       AND e.status = 'active'
                       AND c.program_id = ?
                       ORDER BY cb.start_date ASC";

    $enroll_stmt = $conn->prepare($enrollment_sql);
    $enroll_stmt->bind_param("ii", $user_id, $program['id']);
    $enroll_stmt->execute();
    $enroll_result = $enroll_stmt->get_result();
    $all_enrolled_courses = $enroll_result->fetch_all(MYSQLI_ASSOC);

    // Separate into active (started) and upcoming (not started) classes
    $today = date('Y-m-d');
    foreach ($all_enrolled_courses as $course) {
        if (strtotime($course['start_date']) <= strtotime($today)) {
            // Class has started
            $active_classes[] = $course;
            $enrolled_courses[] = $course; // For backward compatibility
        } else {
            // Class hasn't started yet
            $upcoming_classes[] = $course;
        }
    }

    $class_count = count($active_classes) + count($upcoming_classes);
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

// ============================================
// GET PROGRAM DETAILS AND COURSES
// ============================================

$program_courses = [];
$program_meta = [];
$total_courses = 0;
$core_courses = [];
$elective_courses = [];
$required_core_count = 0;
$required_elective_min = 0;
$required_elective_max = 0;

if (!empty($program)) {
    // Get program metadata including elective requirements
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
    $program_courses = $courses_result->fetch_all(MYSQLI_ASSOC);
    $total_courses = count($program_courses);
    $courses_stmt->close();

    // Separate core and elective courses
    foreach ($program_courses as $course) {
        if ($course['course_type'] == 'core') {
            $core_courses[] = $course;
            if ($course['is_required']) {
                $required_core_count++;
            }
        } else {
            $elective_courses[] = $course;
        }
    }
}

// ============================================
// CALCULATE PROGRESS STATISTICS
// ============================================

// Calculate completed courses by type
$completed_core_courses = [];
$completed_elective_courses = [];

foreach ($completed_courses as $course) {
    // Find course type from program_courses
    $course_type = 'core'; // default
    foreach ($program_courses as $program_course) {
        if ($program_course['id'] == $course['course_id']) {
            $course_type = $program_course['course_type'];
            break;
        }
    }

    if ($course_type == 'core') {
        $completed_core_courses[] = $course;
    } else {
        $completed_elective_courses[] = $course;
    }
}

// Calculate minimum required courses
$min_required_courses = $required_core_count + $required_elective_min;
$completed_required_courses = count($completed_core_courses) + min(count($completed_elective_courses), $required_elective_min);

$progress_stats = [
    'total_courses' => $total_courses,
    'core_courses' => count($core_courses),
    'elective_courses' => count($elective_courses),
    'required_core_count' => $required_core_count,
    'required_elective_min' => $required_elective_min,
    'min_required_courses' => $min_required_courses,
    'completed_courses' => count($completed_courses),
    'completed_core_courses' => count($completed_core_courses),
    'completed_elective_courses' => count($completed_elective_courses),
    'completed_required_courses' => $completed_required_courses,
    'active_courses' => count($active_classes), // Only classes that have started
    'upcoming_courses' => count($upcoming_classes), // Classes registered but not started
    'total_enrolled' => count($active_classes) + count($upcoming_classes),
    'completion_percentage' => 0,
    'gpa' => 0,
    'total_credits' => 0,
    'earned_credits' => 0
];

if ($min_required_courses > 0) {
    $progress_stats['completion_percentage'] = round(($completed_required_courses / $min_required_courses) * 100, 1);
}

// Calculate GPA and credits
$total_grade_points = 0;
$total_credits = 0;

foreach ($completed_courses as $course) {
    $grade = $course['final_grade'];
    $credits = $course['duration_hours'] / 10; // Assuming 10 hours = 1 credit

    // Convert grade to points
    $grade_points = [
        'A' => 4.0,
        'A-' => 3.7,
        'B+' => 3.3,
        'B' => 3.0,
        'B-' => 2.7,
        'C+' => 2.3,
        'C' => 2.0,
        'C-' => 1.7,
        'D+' => 1.3,
        'D' => 1.0,
        'F' => 0.0
    ];

    $points = $grade_points[strtoupper($grade)] ?? 0;
    $total_grade_points += $points * $credits;
    $total_credits += $credits;
}

if ($total_credits > 0) {
    $progress_stats['gpa'] = round($total_grade_points / $total_credits, 2);
    $progress_stats['earned_credits'] = $total_credits;
}

// Calculate total program credits (for required courses only)
foreach ($program_courses as $course) {
    // Count credits for required core courses
    if ($course['course_type'] == 'core' && $course['is_required']) {
        $progress_stats['total_credits'] += $course['duration_hours'] / 10;
    }
}

// Add credits for minimum required electives
// This is an estimate - assumes average elective credits
if ($required_elective_min > 0 && count($elective_courses) > 0) {
    $average_elective_credits = 0;
    foreach ($elective_courses as $elective) {
        $average_elective_credits += $elective['duration_hours'] / 10;
    }
    $average_elective_credits = $average_elective_credits / count($elective_courses);
    $progress_stats['total_credits'] += ($average_elective_credits * $required_elective_min);
}

// ============================================
// GET ACADEMIC PERIODS
// ============================================

$current_period = [];
$upcoming_periods = [];

if (!empty($program)) {
    $program_type = $program['program_type'] ?? 'online';

    // Get current academic period
    $period_sql = "SELECT * FROM academic_periods 
                   WHERE program_type = ? 
                   AND status = 'active'
                   AND start_date <= CURDATE() 
                   AND end_date >= CURDATE()
                   LIMIT 1";
    $period_stmt = $conn->prepare($period_sql);
    $period_stmt->bind_param("s", $program_type);
    $period_stmt->execute();
    $period_result = $period_stmt->get_result();
    if ($period_result->num_rows > 0) {
        $current_period = $period_result->fetch_assoc();
    }
    $period_stmt->close();

    // Get upcoming periods for registration
    $upcoming_sql = "SELECT * FROM academic_periods 
                     WHERE program_type = ? 
                     AND status = 'upcoming'
                     AND registration_deadline >= CURDATE()
                     ORDER BY start_date ASC
                     LIMIT 3";
    $upcoming_stmt = $conn->prepare($upcoming_sql);
    $upcoming_stmt->bind_param("s", $program_type);
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
    $upcoming_periods = $upcoming_result->fetch_all(MYSQLI_ASSOC);
    $upcoming_stmt->close();
}

// Log activity
logActivity($user_id, 'program_view', 'Student viewed program details', $_SERVER['REMOTE_ADDR']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Program - Impact Digital Academy</title>
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

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transform: translateX(0);
        }

        .sidebar.collapsed {
            width: 70px;
            transform: translateX(0);
        }

        .sidebar.hidden {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 80px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
        }

        .logo-text {
            font-weight: 600;
            font-size: 1.25rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--sidebar-text);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .toggle-sidebar:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* User Info */
        .user-info {
            padding: 1.5rem 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .user-details h3 {
            color: white;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-details p {
            font-size: 0.875rem;
            color: var(--sidebar-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .sidebar.collapsed .user-info {
            flex-direction: column;
            padding: 1rem;
            text-align: center;
        }

        .sidebar.collapsed .user-details {
            display: none;
        }

        /* Navigation */
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1.25rem;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
        }

        .nav-item:hover,
        .nav-item.active {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .nav-label {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge {
            background-color: var(--info);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
        }

        .sidebar.collapsed .nav-label,
        .sidebar.collapsed .badge {
            display: none;
        }

        .nav-dropdown {
            position: relative;
        }

        .dropdown-toggle {
            cursor: pointer;
        }

        .dropdown-toggle i:last-child {
            transition: var(--transition);
        }

        .nav-dropdown.active .dropdown-toggle i:last-child {
            transform: rotate(180deg);
        }

        .dropdown-content {
            background-color: rgba(0, 0, 0, 0.2);
            padding-left: 1.5rem;
        }

        .nav-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 0.5rem 1.25rem;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            font-size: 0.75rem;
            color: var(--sidebar-text);
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

        /* User Menu */
        .user-menu {
            position: relative;
        }

        .top-bar .user-avatar {
            width: 40px;
            height: 40px;
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            min-width: 200px;
            z-index: 1000;
            display: none;
        }

        .user-menu-dropdown a {
            display: block;
            padding: 0.75rem 1rem;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu-dropdown a:hover {
            background-color: #f8f9fa;
        }

        .user-menu-dropdown a:not(:last-child) {
            border-bottom: 1px solid var(--border);
        }

        /* Program Header */
        .program-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem 1.5rem;
            margin: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }

        .program-header h1 {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .program-header p {
            font-size: clamp(0.875rem, 2vw, 1rem);
            opacity: 0.9;
            margin-bottom: 1rem;
        }

        .program-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .program-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            opacity: 0.9;
            flex-wrap: wrap;
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

        /* Progress Section */
        .progress-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 992px) {
            .progress-container {
                grid-template-columns: 2fr 1fr;
            }
        }

        .progress-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .progress-bar-container {
            margin: 1.5rem 0;
        }

        .progress-bar {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 10px;
            transition: width 0.5s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-image: linear-gradient(45deg,
                    rgba(255, 255, 255, 0.15) 25%,
                    transparent 25%,
                    transparent 50%,
                    rgba(255, 255, 255, 0.15) 50%,
                    rgba(255, 255, 255, 0.15) 75%,
                    transparent 75%,
                    transparent);
            background-size: 1rem 1rem;
            animation: progress-stripes 1s linear infinite;
        }

        @keyframes progress-stripes {
            from {
                background-position: 1rem 0;
            }

            to {
                background-position: 0 0;
            }
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

        @media (min-width: 768px) {
            .stat-value {
                font-size: 2rem;
            }
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        .course-card.upcoming {
            border-left-color: var(--warning);
        }

        .course-card.available {
            border-left-color: #6c757d;
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

        .badge-completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .badge-enrolled {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .badge-upcoming {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .badge-available {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
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
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
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

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            margin: 1.5rem;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            animation: slideIn 0.5s ease;
        }

        @media (min-width: 768px) {
            .alert {
                flex-direction: row;
                align-items: center;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-info {
            background-color: rgba(72, 149, 239, 0.1);
            border-left: 4px solid var(--info);
            color: var(--dark);
        }

        .alert-warning {
            background-color: rgba(247, 37, 133, 0.1);
            border-left: 4px solid var(--warning);
            color: var(--dark);
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert i {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content h3 {
            margin-bottom: 0.5rem;
        }

        /* Table Styles */
        .data-table-container {
            overflow-x: auto;
            margin-top: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 600px;
        }

        .data-table thead {
            background-color: #f8f9fa;
        }

        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Status Indicators */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-approved {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-enrolled {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .status-pending {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
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

        /* Workflow Steps */
        .workflow-steps {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            margin: 2rem 0;
            position: relative;
        }

        @media (min-width: 768px) {
            .workflow-steps {
                flex-direction: row;
                justify-content: space-between;
                gap: 1rem;
            }
        }

        .workflow-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            height: 2px;
            background: var(--border);
            z-index: 1;
            display: none;
        }

        @media (min-width: 768px) {
            .workflow-steps::before {
                display: block;
            }
        }

        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .step {
                flex-direction: column;
                flex: 1;
            }
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--gray);
            flex-shrink: 0;
        }

        .step.active .step-icon {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .step.completed .step-icon {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .step-label {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .step.active .step-label {
            color: var(--primary);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: var(--success);
        }

        /* Dashboard Footer */
        .dashboard-footer {
            background-color: white;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 2rem;
        }

        @media (min-width: 640px) {
            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-pulse {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .top-bar {
                padding: 1rem;
                flex-wrap: wrap;
                gap: 1rem;
            }

            .program-header {
                margin: 1rem;
                padding: 1.5rem;
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

            .alert {
                margin: 1rem;
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .program-meta-item {
                font-size: 0.75rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .top-actions .user-avatar {
                width: 35px;
                height: 35px;
                font-size: 0.875rem;
            }
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .top-bar,
            .dashboard-footer,
            .alert,
            .btn {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .program-header {
                margin: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .card {
                box-shadow: none;
                border: 1px solid var(--border);
            }

            .course-card {
                break-inside: avoid;
            }
        }

        /* Backdrop for mobile menu */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        /* Requirements Summary */
        .requirements-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        @media (min-width: 768px) {
            .requirements-summary {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .requirement-item {
            text-align: center;
            padding: 0.5rem;
        }

        .requirement-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .requirement-label {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .requirement-note {
            font-size: 0.75rem;
            color: var(--gray-light);
            margin-top: 0.25rem;
        }

        /* Section Tabs */
        .section-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .section-tab {
            padding: 0.5rem 1rem;
            background: none;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .section-tab:hover {
            background: #f8f9fa;
            color: var(--dark);
        }

        .section-tab.active {
            background: var(--primary);
            color: white;
        }

        .section-content {
            display: none;
        }

        .section-content.active {
            display: block;
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Backdrop for mobile menu -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">IDA</div>
                <div class="logo-text">Student Portal</div>
            </div>
            <button class="toggle-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php
                $initials = strtoupper(substr($user_details['first_name'] ?? '', 0, 1) . substr($user_details['last_name'] ?? '', 0, 1));
                echo $initials ?: 'S';
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></h3>
                <p><i class="fas fa-user-graduate"></i> Student</p>
                <?php if (!empty($user_details['current_job_title'])): ?>
                    <p><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($user_details['current_job_title']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-label">Dashboard</span>
            </a>

            <div class="nav-dropdown active">
                <div class="nav-item dropdown-toggle active" onclick="toggleDropdown(this)">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="nav-label">My Program</span>
                    <?php if ($progress_stats['completion_percentage'] > 0 && $progress_stats['completion_percentage'] < 100): ?>
                        <span class="badge">
                            <?php echo $progress_stats['completion_percentage']; ?>%
                        </span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content" style="display: block;">
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="nav-item active">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-label">Program Overview</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/courses.php" class="nav-item">
                        <i class="fas fa-book"></i>
                        <span class="nav-label">All Courses</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/available_periods.php" class="nav-item">
                        <i class="fas fa-calendar-plus"></i>
                        <span class="nav-label">Course Registration</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/graduation.php" class="nav-item">
                        <i class="fas fa-award"></i>
                        <span class="nav-label">Graduation Status</span>
                    </a>
                </div>
            </div>

            <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="nav-item">
                <i class="fas fa-chalkboard"></i>
                <span class="nav-label">My Classes</span>
                <?php if ($progress_stats['total_enrolled'] > 0): ?>
                    <span class="badge"><?php echo $progress_stats['total_enrolled']; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                <span class="nav-label">Finance</span>
            </a>

            <div class="nav-divider"></div>

            <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" class="nav-item">
                <i class="fas fa-user"></i>
                <span class="nav-label">My Profile</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/shared/notifications/" class="nav-item">
                <i class="fas fa-bell"></i>
                <span class="nav-label">Notifications</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="nav-item" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i>
                <span class="nav-label">Logout</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div style="padding: 0.5rem; font-size: 0.75rem; color: var(--sidebar-text); text-align: center;">
                <div>Impact Digital Academy</div>
                <div style="font-size: 0.625rem; opacity: 0.7;">Student Portal v1.0</div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>My Program</h1>
                <p>Program Overview and Progress Tracking</p>
            </div>
            <div class="top-actions">
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleUserMenu()">
                        <?php echo $initials ?: 'S'; ?>
                    </div>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" onclick="return confirm('Are you sure you want to logout?');">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($enrollment_status === 'none'): ?>
            <!-- NO PROGRAM ENROLLED STATE -->
            <div class="program-header" style="background: linear-gradient(135deg, var(--warning), var(--danger));">
                <h1>No Program Enrolled</h1>
                <p>You are not currently enrolled in any program. Please apply for a program to get started.</p>
                <div class="program-meta">
                    <div class="program-meta-item">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Program enrollment required</span>
                    </div>
                </div>
            </div>

            <div class="content-container">
                <div class="card">
                    <div class="card-header">
                        <h2>Get Started with Your Education</h2>
                    </div>
                    <p style="margin-bottom: 1.5rem; color: var(--gray);">
                        To begin your learning journey at Impact Digital Academy, you need to apply for a program first.
                    </p>

                    <div class="workflow-steps">
                        <div class="step active">
                            <div class="step-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="step-label">Apply for Program</div>
                        </div>
                        <div class="step">
                            <div class="step-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="step-label">Wait for Approval</div>
                        </div>
                        <div class="step">
                            <div class="step-icon">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <div class="step-label">Register for Courses</div>
                        </div>
                        <div class="step">
                            <div class="step-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="step-label">Get Class Assignment</div>
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>modules/student/applications/apply.php" class="btn btn-primary">
                            <i class="fas fa-file-alt"></i> Apply for a Program
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/student/programs/" class="btn" style="background: var(--light); color: var(--dark);">
                            <i class="fas fa-search"></i> Browse Programs
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($enrollment_status === 'approved'): ?>
            <!-- PROGRAM APPROVED - AWAITING COURSE REGISTRATION -->
            <div class="program-header">
                <h1><?php echo htmlspecialchars($program['name']); ?></h1>
                <p><?php echo htmlspecialchars($program['description'] ?? ''); ?></p>
                <div class="program-meta">
                    <div class="program-meta-item">
                        <i class="fas fa-hashtag"></i>
                        <span><?php echo strtoupper($program['program_code']); ?></span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-tag"></i>
                        <span><?php echo ucfirst($program['program_type']); ?> Program</span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-check-circle" style="color: var(--success);"></i>
                        <span>Application Approved</span>
                    </div>
                    <?php if (!empty($program['application_date'])): ?>
                        <div class="program-meta-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>Approved: <?php echo date('M d, Y', strtotime($program['application_date'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <div class="alert-content">
                    <h3>Next Step: Course Registration</h3>
                    <p>Your program application has been approved! Now you need to pay for registration fee before you can register for courses in an upcoming term/block.</p>
                </div>
                <a href="available_periods.php" class="btn btn-success">
                    <i class="fas fa-calendar-plus"></i> Register for Courses
                </a>
            </div>

            <div class="content-container">
                <div class="progress-container">
                    <div class="progress-card">
                        <div class="card-header">
                            <h2>Program Details</h2>
                            <span class="status-indicator status-approved">
                                <i class="fas fa-check-circle"></i> Application Approved
                            </span>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $program['duration_weeks']; ?></div>
                                <div class="stat-label">Weeks</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo count($program_courses); ?></div>
                                <div class="stat-label">Total Courses</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $program['program_type'] == 'onsite' ? 'On-site' : 'Online'; ?></div>
                                <div class="stat-label">Delivery</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $program['currency'] ?? 'NGN'; ?></div>
                                <div class="stat-label">Currency</div>
                            </div>
                        </div>

                        <?php if (!empty($program['fee_description'])): ?>
                            <div style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                                <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Fee Structure:</h4>
                                <p style="color: var(--gray);"><?php echo htmlspecialchars($program['fee_description']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="progress-card">
                        <div class="card-header">
                            <h2>Next Steps</h2>
                        </div>

                        <div class="workflow-steps">
                            <div class="step completed">
                                <div class="step-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="step-label">Apply for Program</div>
                            </div>
                            <div class="step completed">
                                <div class="step-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="step-label">Get Approved</div>
                            </div>
                            <div class="step active">
                                <div class="step-icon">
                                    <i class="fas fa-calendar-plus"></i>
                                </div>
                                <div class="step-label">Register Courses</div>
                            </div>
                            <div class="step">
                                <div class="step-icon">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                <div class="step-label">Class Assignment</div>
                            </div>
                        </div>

                        <p style="margin-top: 1.5rem; color: var(--gray);">
                            <i class="fas fa-info-circle"></i>
                            Once you register for courses, administration will assign you to classes.
                        </p>

                        <?php if (!empty($upcoming_periods)): ?>
                            <div style="margin-top: 1.5rem;">
                                <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Upcoming Registration Periods:</h4>
                                <?php foreach ($upcoming_periods as $period): ?>
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.75rem; background: #f8f9fa; border-radius: 6px; margin-bottom: 0.5rem;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($period['period_name']); ?></strong>
                                            <div style="font-size: 0.875rem; color: var(--gray);">
                                                <?php echo date('M d', strtotime($period['start_date'])); ?> -
                                                <?php echo date('M d, Y', strtotime($period['end_date'])); ?>
                                            </div>
                                        </div>
                                        <a href="available_periods.php?period_id=<?php echo $period['id']; ?>" class="btn btn-primary btn-sm" style="align-self: flex-start;">
                                            Register
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>Program Curriculum</h2>
                        <span style="font-size: 0.875rem; color: var(--gray);">
                            <?php echo count($program_courses); ?> total courses
                        </span>
                    </div>

                    <!-- Requirements Summary -->
                    <div class="requirements-summary">
                        <div class="requirement-item">
                            <div class="requirement-value"><?php echo count($core_courses); ?></div>
                            <div class="requirement-label">Core Courses</div>
                            <div class="requirement-note">All required</div>
                        </div>
                        <div class="requirement-item">
                            <div class="requirement-value"><?php echo count($elective_courses); ?></div>
                            <div class="requirement-label">Elective Courses</div>
                            <div class="requirement-note">Choose <?php echo $required_elective_min; ?>+</div>
                        </div>
                        <div class="requirement-item">
                            <div class="requirement-value"><?php echo $min_required_courses; ?></div>
                            <div class="requirement-label">Min Required</div>
                            <div class="requirement-note">To graduate</div>
                        </div>
                        <div class="requirement-item">
                            <div class="requirement-value"><?php echo count($program_courses); ?></div>
                            <div class="requirement-label">Total Available</div>
                            <div class="requirement-note">You can take more</div>
                        </div>
                    </div>

                    <div class="course-grid">
                        <?php foreach ($program_courses as $course): ?>
                            <div class="course-card available">
                                <div class="course-header">
                                    <div class="course-title">
                                        <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                        <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    </div>
                                    <span class="course-badge <?php echo $course['course_type'] == 'core' ? 'badge-core' : 'badge-elective'; ?>">
                                        <?php echo $course['course_type']; ?>
                                    </span>
                                </div>
                                <div class="course-details">
                                    <div>
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo $course['duration_hours']; ?> hours</span>
                                    </div>
                                    <div>
                                        <i class="fas fa-star"></i>
                                        <span>Min Grade: <?php echo $course['min_grade']; ?></span>
                                    </div>
                                    <?php if (!empty($course['prereq_code'])): ?>
                                        <div>
                                            <i class="fas fa-link"></i>
                                            <span>Prerequisite: <?php echo htmlspecialchars($course['prereq_code']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="course-footer">
                                    <div class="course-status">
                                        <i class="fas fa-clock" style="color: var(--gray);"></i>
                                        <span>Available for Registration</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- PROGRAM WITH CLASS ENROLLMENTS -->
            <div class="program-header">
                <h1><?php echo htmlspecialchars($program['name']); ?></h1>
                <p><?php echo htmlspecialchars($program['description'] ?? ''); ?></p>
                <div class="program-meta">
                    <div class="program-meta-item">
                        <i class="fas fa-hashtag"></i>
                        <span><?php echo strtoupper($program['program_code']); ?></span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-calendar"></i>
                        <span><?php echo $program['duration_weeks']; ?> weeks</span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-tag"></i>
                        <span><?php echo ucfirst($program['program_type']); ?> Program</span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span><?php echo $progress_stats['total_enrolled']; ?> Registered Classes</span>
                    </div>
                    <div class="program-meta-item">
                        <i class="fas fa-percentage"></i>
                        <span><?php echo $progress_stats['completion_percentage']; ?>% Complete</span>
                    </div>
                </div>
            </div>

            <div class="content-container">
                <div class="progress-container">
                    <div class="progress-card">
                        <div class="card-header">
                            <h2>Program Progress</h2>
                            <span class="status-indicator status-enrolled">
                                <i class="fas fa-user-check"></i> Enrolled
                            </span>
                        </div>

                        <div class="progress-bar-container">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progress_stats['completion_percentage']; ?>%"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.875rem; color: var(--gray);">
                                <span><?php echo $progress_stats['completion_percentage']; ?>% Complete</span>
                                <span><?php echo $progress_stats['completed_required_courses']; ?> of <?php echo $progress_stats['min_required_courses']; ?> required courses</span>
                            </div>
                        </div>

                        <!-- Requirements Progress -->
                        <div class="requirements-summary">
                            <div class="requirement-item">
                                <div class="requirement-value"><?php echo $progress_stats['completed_core_courses']; ?>/<?php echo $progress_stats['required_core_count']; ?></div>
                                <div class="requirement-label">Core Courses</div>
                                <div class="requirement-note">Completed</div>
                            </div>
                            <div class="requirement-item">
                                <div class="requirement-value"><?php echo min($progress_stats['completed_elective_courses'], $progress_stats['required_elective_min']); ?>/<?php echo $progress_stats['required_elective_min']; ?></div>
                                <div class="requirement-label">Electives</div>
                                <div class="requirement-note">Minimum required</div>
                            </div>
                            <div class="requirement-item">
                                <div class="requirement-value"><?php echo $progress_stats['completed_courses']; ?></div>
                                <div class="requirement-label">Total Taken</div>
                                <div class="requirement-note">All courses completed</div>
                            </div>
                            <div class="requirement-item">
                                <div class="requirement-value"><?php echo $progress_stats['active_courses']; ?></div>
                                <div class="requirement-label">In Progress</div>
                                <div class="requirement-note">Currently active</div>
                            </div>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $progress_stats['completed_required_courses']; ?>/<?php echo $progress_stats['min_required_courses']; ?></div>
                                <div class="stat-label">Required Done</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $progress_stats['active_courses']; ?></div>
                                <div class="stat-label">In Progress</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $progress_stats['upcoming_courses']; ?></div>
                                <div class="stat-label">Upcoming</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo round($progress_stats['earned_credits'], 1); ?>/<?php echo round($progress_stats['total_credits'], 1); ?></div>
                                <div class="stat-label">Credits</div>
                            </div>
                        </div>
                    </div>

                    <div class="progress-card">
                        <div class="card-header">
                            <h2>Quick Actions</h2>
                        </div>

                        <div style="display: grid; gap: 1rem; margin-top: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-primary">
                                <i class="fas fa-chalkboard-teacher"></i> View My Classes
                            </a>

                            <?php if (!empty($upcoming_periods)): ?>
                                <a href="available_periods.php" class="btn btn-success">
                                    <i class="fas fa-calendar-plus"></i> Register More Courses
                                </a>
                            <?php endif; ?>

                            <a href="<?php echo BASE_URL; ?>modules/student/program/graduation.php" class="btn" style="background: var(--light); color: var(--dark);">
                                <i class="fas fa-award"></i> Graduation Status
                            </a>
                        </div>

                        <?php if (!empty($current_period)): ?>
                            <div style="margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                                <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Current Academic Period:</h4>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($current_period['period_name']); ?></strong>
                                        <div style="font-size: 0.875rem; color: var(--gray);">
                                            <?php echo date('M d, Y', strtotime($current_period['start_date'])); ?> -
                                            <?php echo date('M d, Y', strtotime($current_period['end_date'])); ?>
                                        </div>
                                    </div>
                                    <span class="badge" style="background: var(--success); align-self: flex-start;">Active</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- My Classes Tabs -->
                <div class="card">
                    <div class="card-header">
                        <h2>My Classes</h2>
                        <span style="font-size: 0.875rem; color: var(--gray);">
                            Total: <?php echo $progress_stats['total_enrolled']; ?> classes
                        </span>
                    </div>

                    <?php if ($progress_stats['total_enrolled'] > 0): ?>
                        <!-- Tabs for different class statuses -->
                        <div class="section-tabs" id="classTabs">
                            <button class="section-tab <?php echo !empty($active_classes) ? 'active' : ''; ?>" onclick="showSection('active-classes')">
                                <i class="fas fa-spinner"></i> Active (<?php echo count($active_classes); ?>)
                            </button>
                            <button class="section-tab <?php echo empty($active_classes) && !empty($upcoming_classes) ? 'active' : ''; ?>" onclick="showSection('upcoming-classes')">
                                <i class="fas fa-calendar"></i> Upcoming (<?php echo count($upcoming_classes); ?>)
                            </button>
                            <button class="section-tab <?php echo !empty($completed_courses) ? '' : 'active'; ?>" onclick="showSection('completed-classes')">
                                <i class="fas fa-check-circle"></i> Completed (<?php echo count($completed_courses); ?>)
                            </button>
                        </div>

                        <!-- Active Classes Section -->
                        <div id="active-classes" class="section-content <?php echo !empty($active_classes) ? 'active' : ''; ?>">
                            <?php if (!empty($active_classes)): ?>
                                <div class="course-grid">
                                    <?php foreach ($active_classes as $course): ?>
                                        <?php
                                        // Determine if this is a core or elective course
                                        $is_core = true;
                                        foreach ($program_courses as $program_course) {
                                            if ($program_course['id'] == $course['course_id']) {
                                                $is_core = ($program_course['course_type'] == 'core');
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="course-card enrolled">
                                            <div class="course-header">
                                                <div class="course-title">
                                                    <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                </div>
                                                <span class="course-badge <?php echo $is_core ? 'badge-core' : 'badge-elective'; ?>">
                                                    <?php echo $is_core ? 'Core' : 'Elective'; ?>
                                                </span>
                                            </div>
                                            <div class="course-details">
                                                <div>
                                                    <i class="fas fa-chalkboard-teacher"></i>
                                                    <span>Instructor: <?php echo htmlspecialchars($course['instructor_first'] . ' ' . $course['instructor_last']); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar"></i>
                                                    <span>Batch: <?php echo htmlspecialchars($course['batch_code']); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-clock"></i>
                                                    <span><?php echo $course['duration_hours']; ?> hours</span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <span>Started: <?php echo date('M d, Y', strtotime($course['start_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar-check"></i>
                                                    <span>Ends: <?php echo date('M d, Y', strtotime($course['end_date'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="course-footer">
                                                <div class="course-status">
                                                    <i class="fas fa-spinner" style="color: var(--info);"></i>
                                                    <span>In Progress</span>
                                                </div>
                                                <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?class_id=<?php echo $course['id']; ?>" class="btn btn-primary btn-sm">
                                                    View Class
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <h3>No Active Classes</h3>
                                    <p>You don't have any classes that have started yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Upcoming Classes Section -->
                        <div id="upcoming-classes" class="section-content <?php echo empty($active_classes) && !empty($upcoming_classes) ? 'active' : ''; ?>">
                            <?php if (!empty($upcoming_classes)): ?>
                                <div class="course-grid">
                                    <?php foreach ($upcoming_classes as $course): ?>
                                        <?php
                                        // Determine if this is a core or elective course
                                        $is_core = true;
                                        foreach ($program_courses as $program_course) {
                                            if ($program_course['id'] == $course['course_id']) {
                                                $is_core = ($program_course['course_type'] == 'core');
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="course-card upcoming">
                                            <div class="course-header">
                                                <div class="course-title">
                                                    <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                </div>
                                                <span class="course-badge <?php echo $is_core ? 'badge-core' : 'badge-elective'; ?>">
                                                    <?php echo $is_core ? 'Core' : 'Elective'; ?>
                                                </span>
                                            </div>
                                            <div class="course-details">
                                                <div>
                                                    <i class="fas fa-chalkboard-teacher"></i>
                                                    <span>Instructor: <?php echo htmlspecialchars($course['instructor_first'] . ' ' . $course['instructor_last']); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar"></i>
                                                    <span>Batch: <?php echo htmlspecialchars($course['batch_code']); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-clock"></i>
                                                    <span><?php echo $course['duration_hours']; ?> hours</span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar-plus"></i>
                                                    <span>Starts: <?php echo date('M d, Y', strtotime($course['start_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar-check"></i>
                                                    <span>Ends: <?php echo date('M d, Y', strtotime($course['end_date'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="course-footer">
                                                <div class="course-status">
                                                    <i class="fas fa-clock" style="color: var(--warning);"></i>
                                                    <span>Starts Soon</span>
                                                </div>
                                                <div style="font-size: 0.75rem; color: var(--gray);">
                                                    <i class="fas fa-info-circle"></i>
                                                    Class hasn't started yet
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar"></i>
                                    <h3>No Upcoming Classes</h3>
                                    <p>You don't have any classes scheduled to start in the future.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Completed Classes Section -->
                        <div id="completed-classes" class="section-content <?php echo empty($active_classes) && empty($upcoming_classes) && !empty($completed_courses) ? 'active' : ''; ?>">
                            <?php if (!empty($completed_courses)): ?>
                                <div class="course-grid">
                                    <?php foreach ($completed_courses as $course): ?>
                                        <?php
                                        // Determine if this is a core or elective course
                                        $is_core = true;
                                        foreach ($program_courses as $program_course) {
                                            if ($program_course['id'] == $course['course_id']) {
                                                $is_core = ($program_course['course_type'] == 'core');
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="course-card completed">
                                            <div class="course-header">
                                                <div class="course-title">
                                                    <h4><?php echo htmlspecialchars($course['title']); ?></h4>
                                                    <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                </div>
                                                <span class="course-badge <?php echo $is_core ? 'badge-core' : 'badge-elective'; ?>">
                                                    <?php echo $is_core ? 'Core' : 'Elective'; ?>
                                                </span>
                                            </div>
                                            <div class="course-details">
                                                <div>
                                                    <i class="fas fa-star"></i>
                                                    <span>Grade: <strong><?php echo $course['final_grade']; ?></strong></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-calendar-check"></i>
                                                    <span>Completed: <?php echo date('M d, Y', strtotime($course['completion_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-clock"></i>
                                                    <span><?php echo $course['duration_hours']; ?> hours</span>
                                                </div>
                                                <div>
                                                    <i class="fas fa-graduation-cap"></i>
                                                    <span>Type: <?php echo $is_core ? 'Core' : 'Elective'; ?></span>
                                                </div>
                                            </div>
                                            <div class="course-footer">
                                                <div class="course-status">
                                                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                                    <span>Completed: <?php echo $course['final_grade']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h3>No Completed Classes</h3>
                                    <p>You haven't completed any classes yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h3>No Classes Enrolled</h3>
                            <p>You are not currently enrolled in any classes.</p>
                            <a href="available_periods.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-calendar-plus"></i> Register for Courses
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Grade History Table -->
                <?php if (!empty($completed_courses)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2>Grade History</h2>
                            <span style="font-size: 0.875rem; color: var(--gray);">
                                GPA: <?php echo $progress_stats['gpa']; ?>
                            </span>
                        </div>

                        <div class="data-table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Course Code</th>
                                        <th>Type</th>
                                        <th>Grade</th>
                                        <th>Credits</th>
                                        <th>Completion Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed_courses as $course): ?>
                                        <?php
                                        // Determine if this is a core or elective course
                                        $is_core = true;
                                        foreach ($program_courses as $program_course) {
                                            if ($program_course['id'] == $course['course_id']) {
                                                $is_core = ($program_course['course_type'] == 'core');
                                                break;
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($course['title']); ?></td>
                                            <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $is_core ? 'badge-core' : 'badge-elective'; ?>" style="padding: 0.25rem 0.5rem; border-radius: 20px; font-size: 0.75rem;">
                                                    <?php echo $is_core ? 'Core' : 'Elective'; ?>
                                                </span>
                                            </td>
                                            <td><strong><?php echo $course['final_grade']; ?></strong></td>
                                            <td><?php echo round($course['duration_hours'] / 10, 1); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($course['completion_date'])); ?></td>
                                            <td>
                                                <span class="badge" style="background: var(--success); padding: 0.25rem 0.5rem; border-radius: 20px; font-size: 0.75rem;">
                                                    Completed
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="dashboard-footer">
            <div class="system-status">
                <div class="status-pulse"></div>
                <span>Impact Digital Academy</span>
            </div>
            <div>
                <span><?php echo date('F j, Y, g:i a'); ?></span>
                <?php if ($progress_stats['completion_percentage'] > 0): ?>
                    <span style="margin-left: 1rem; color: var(--primary); font-weight: 600;">
                        <i class="fas fa-chart-line"></i>
                        Progress: <?php echo $progress_stats['completion_percentage']; ?>%
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.add('active');
            document.getElementById('sidebarBackdrop').classList.add('active');
        });

        // Close sidebar on mobile
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('sidebarBackdrop').classList.remove('active');
        }

        // Toggle sidebar (desktop)
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }

        // Toggle dropdown navigation
        function toggleDropdown(element) {
            const dropdown = element.closest('.nav-dropdown');
            dropdown.classList.toggle('active');

            // Close other dropdowns
            document.querySelectorAll('.nav-dropdown.active').forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('active');
                }
            });
        }

        // Toggle user menu dropdown
        function toggleUserMenu() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

        // Show/hide section content
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.section-content').forEach(section => {
                section.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.section-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected section
            document.getElementById(sectionId).classList.add('active');

            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            // Close dropdowns
            if (!event.target.closest('.nav-dropdown') && !event.target.closest('.sidebar')) {
                document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
            }

            // Close user menu
            if (!event.target.closest('.user-menu')) {
                document.getElementById('userMenuDropdown').style.display = 'none';
            }
        });

        // Load sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                document.getElementById('sidebar').classList.add('collapsed');
            }

            // Close mobile sidebar if screen is resized to desktop
            function handleResize() {
                if (window.innerWidth > 768) {
                    closeSidebar();
                }
            }

            window.addEventListener('resize', handleResize);

            // Initialize tooltips
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                element.addEventListener('mouseenter', function(e) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = this.title;
                    document.body.appendChild(tooltip);

                    const rect = this.getBoundingClientRect();
                    tooltip.style.position = 'fixed';
                    tooltip.style.background = 'rgba(0,0,0,0.9)';
                    tooltip.style.color = 'white';
                    tooltip.style.padding = '0.5rem 0.75rem';
                    tooltip.style.borderRadius = '4px';
                    tooltip.style.fontSize = '0.75rem';
                    tooltip.style.zIndex = '10000';
                    tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
                    tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';

                    this._tooltip = tooltip;
                });

                element.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        delete this._tooltip;
                    }
                });
            });
        });

        // Print function
        function printProgramProgress() {
            window.print();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printProgramProgress();
            }

            // Esc to close dropdowns and sidebar
            if (e.key === 'Escape') {
                document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
                document.getElementById('userMenuDropdown').style.display = 'none';
                closeSidebar();
            }
        });
    </script>
</body>

</html>

<?php $conn->close(); ?>