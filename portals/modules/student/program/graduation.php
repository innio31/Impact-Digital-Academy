<?php
// modules/student/program/graduation.php

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
    // Get any active class enrollments
    $enrollment_sql = "SELECT COUNT(*) as active_count FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       JOIN courses c ON cb.course_id = c.id
                       WHERE e.student_id = ? 
                       AND e.status = 'active'
                       AND c.program_id = ?";

    $enroll_stmt = $conn->prepare($enrollment_sql);
    $enroll_stmt->bind_param("ii", $user_id, $program['id']);
    $enroll_stmt->execute();
    $enroll_result = $enroll_stmt->get_result();
    $active_count = $enroll_result->fetch_assoc()['active_count'] ?? 0;
    $enroll_stmt->close();

    if ($active_count > 0) {
        $enrollment_status = 'enrolled';
    }
}

// ============================================
// GET PROGRAM DETAILS AND COURSES
// ============================================

$program_courses = [];
$program_meta = [];
$total_courses = 0;
$total_core_courses = 0;
$total_elective_courses = 0;

if (!empty($program)) {
    // Get program metadata
    $meta_sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
    $meta_stmt = $conn->prepare($meta_sql);
    $meta_stmt->bind_param("i", $program['id']);
    $meta_stmt->execute();
    $meta_result = $meta_stmt->get_result();
    if ($meta_result->num_rows > 0) {
        $program_meta = $meta_result->fetch_assoc();
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

    // Count core and elective courses
    foreach ($program_courses as $course) {
        if ($course['course_type'] == 'core') {
            $total_core_courses++;
        } else {
            $total_elective_courses++;
        }
    }
    $courses_stmt->close();
}

// ============================================
// GET STUDENT'S COURSE COMPLETION STATUS
// ============================================

$completed_courses = [];
$in_progress_courses = [];
$registered_courses = [];
$pending_courses = [];
$completed_core_courses = 0;
$completed_elective_courses = 0;

if (!empty($program)) {
    // Get completed courses
    $completed_sql = "SELECT e.*, cb.*, c.*, e.final_grade, e.completion_date, pr.course_type
                      FROM enrollments e
                      JOIN class_batches cb ON e.class_id = cb.id
                      JOIN courses c ON cb.course_id = c.id
                      JOIN program_requirements pr ON c.id = pr.course_id AND pr.program_id = ?
                      WHERE e.student_id = ? 
                      AND e.status = 'completed'
                      AND c.program_id = ?
                      AND e.final_grade IS NOT NULL
                      ORDER BY e.completion_date DESC";

    $completed_stmt = $conn->prepare($completed_sql);
    $completed_stmt->bind_param("iii", $program['id'], $user_id, $program['id']);
    $completed_stmt->execute();
    $completed_result = $completed_stmt->get_result();
    $completed_courses = $completed_result->fetch_all(MYSQLI_ASSOC);

    // Count completed core and elective courses
    foreach ($completed_courses as $course) {
        if ($course['course_type'] == 'core') {
            $completed_core_courses++;
        } else {
            $completed_elective_courses++;
        }
    }
    $completed_stmt->close();

    // Get in-progress courses (classes that have started)
    $current_date = date('Y-m-d');
    $inprogress_sql = "SELECT e.*, cb.*, c.*, pr.course_type
                       FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       JOIN courses c ON cb.course_id = c.id
                       JOIN program_requirements pr ON c.id = pr.course_id AND pr.program_id = ?
                       WHERE e.student_id = ? 
                       AND e.status = 'active'
                       AND c.program_id = ?
                       AND cb.start_date <= ?  -- Class has started
                       AND cb.end_date >= ?    -- Class hasn't ended
                       ORDER BY cb.start_date DESC";

    $inprogress_stmt = $conn->prepare($inprogress_sql);
    $inprogress_stmt->bind_param("iiiss", $program['id'], $user_id, $program['id'], $current_date, $current_date);
    $inprogress_stmt->execute();
    $inprogress_result = $inprogress_stmt->get_result();
    $in_progress_courses = $inprogress_result->fetch_all(MYSQLI_ASSOC);
    $inprogress_stmt->close();

    // Get registered courses (enrolled but class hasn't started yet)
    $registered_sql = "SELECT e.*, cb.*, c.*, pr.course_type
                       FROM enrollments e
                       JOIN class_batches cb ON e.class_id = cb.id
                       JOIN courses c ON cb.course_id = c.id
                       JOIN program_requirements pr ON c.id = pr.course_id AND pr.program_id = ?
                       WHERE e.student_id = ? 
                       AND e.status = 'active'
                       AND c.program_id = ?
                       AND cb.start_date > ?  -- Class hasn't started yet
                       ORDER BY cb.start_date ASC";

    $registered_stmt = $conn->prepare($registered_sql);
    $registered_stmt->bind_param("iiis", $program['id'], $user_id, $program['id'], $current_date);
    $registered_stmt->execute();
    $registered_result = $registered_stmt->get_result();
    $registered_courses = $registered_result->fetch_all(MYSQLI_ASSOC);
    $registered_stmt->close();

    // Determine pending courses (not enrolled or registered)
    $completed_course_ids = array_column($completed_courses, 'course_id');
    $inprogress_course_ids = array_column($in_progress_courses, 'course_id');
    $registered_course_ids = array_column($registered_courses, 'course_id');

    foreach ($program_courses as $course) {
        if (
            !in_array($course['id'], $completed_course_ids) &&
            !in_array($course['id'], $inprogress_course_ids) &&
            !in_array($course['id'], $registered_course_ids)
        ) {
            $pending_courses[] = $course;
        }
    }
}

// ============================================
// CALCULATE GRADUATION REQUIREMENTS
// ============================================

$min_electives_required = $program_meta['min_electives'] ?? 0;
$max_electives_allowed = $program_meta['max_electives'] ?? $total_elective_courses;
$required_courses_count = $total_core_courses + $min_electives_required;

$graduation_status = [
    'eligibility' => 'not_eligible',
    'message' => '',
    'requirements_met' => false,
    'courses_completed' => count($completed_courses),
    'courses_required' => $required_courses_count,
    'total_courses_available' => $total_courses,
    'min_electives_met' => false,
    'gpa_requirement_met' => false,
    'financial_clearance' => false,
    'total_credits_earned' => 0,
    'total_credits_required' => $program_meta['total_credits'] ?? 0,
    'gpa' => 0,
    'min_grade_met' => true,
    'core_courses_completed' => $completed_core_courses,
    'core_courses_required' => $total_core_courses,
    'elective_courses_completed' => $completed_elective_courses,
    'elective_courses_required' => $min_electives_required
];

// Grade to points mapping
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

// Calculate GPA and credits
$total_grade_points = 0;
$total_credits = 0;

// Calculate totals from completed courses
foreach ($completed_courses as $course) {
    $credits = $course['duration_hours'] / 10; // Assuming 10 hours = 1 credit
    $grade = strtoupper($course['final_grade']);
    $points = $grade_points[$grade] ?? 0;

    $total_grade_points += $points * $credits;
    $total_credits += $credits;
}

// Calculate GPA
if ($total_credits > 0) {
    $graduation_status['gpa'] = round($total_grade_points / $total_credits, 2);
    $graduation_status['total_credits_earned'] = $total_credits;
}

// Check course completion requirements
$all_core_completed = ($completed_core_courses >= $total_core_courses);
$electives_met = ($completed_elective_courses >= $min_electives_required) &&
    ($completed_elective_courses <= $max_electives_allowed);

$required_courses_completed = $all_core_completed && $electives_met;

// Calculate percentage based on required courses only
$courses_completed_percentage = ($required_courses_count > 0) ?
    round((($completed_core_courses + min($completed_elective_courses, $min_electives_required)) / $required_courses_count) * 100, 1) : 0;

// Check GPA requirement
$min_grade_required = $program_meta['min_grade_required'] ?? 'C';
$min_grade_points = $grade_points[strtoupper($min_grade_required)] ?? 2.0;
$gpa_requirement_met = ($graduation_status['gpa'] >= $min_grade_points);

// Check if all courses have at least the minimum grade
$all_min_grades_met = true;
foreach ($completed_courses as $course) {
    $course_grade = $grade_points[strtoupper($course['final_grade'])] ?? 0;
    if ($course_grade < $min_grade_points) {
        $all_min_grades_met = false;
        break;
    }
}

// Check financial clearance
$financial_sql = "SELECT sfs.* 
                  FROM student_financial_status sfs
                  JOIN enrollments e ON sfs.class_id = e.class_id
                  JOIN class_batches cb ON e.class_id = cb.id
                  JOIN courses c ON cb.course_id = c.id
                  WHERE sfs.student_id = ? 
                  AND c.program_id = ?
                  AND sfs.is_cleared = 0
                  LIMIT 1";

$financial_stmt = $conn->prepare($financial_sql);
$financial_stmt->bind_param("ii", $user_id, $program['id']);
$financial_stmt->execute();
$financial_result = $financial_stmt->get_result();
$financial_clearance = ($financial_result->num_rows == 0);
$financial_stmt->close();

// Determine graduation eligibility
$graduation_status['all_core_completed'] = $all_core_completed;
$graduation_status['electives_met'] = $electives_met;
$graduation_status['gpa_requirement_met'] = $gpa_requirement_met && $all_min_grades_met;
$graduation_status['financial_clearance'] = $financial_clearance;

if ($all_core_completed && $electives_met && $gpa_requirement_met && $all_min_grades_met && $financial_clearance) {
    $graduation_status['eligibility'] = 'eligible';
    $graduation_status['message'] = 'Congratulations! You have met all requirements for graduation.';
    $graduation_status['requirements_met'] = true;
} elseif (!$all_core_completed) {
    $graduation_status['eligibility'] = 'courses_pending';
    $remaining_core = $total_core_courses - $completed_core_courses;
    $graduation_status['message'] = 'You need to complete ' . $remaining_core . ' more core course(s).';
} elseif (!$electives_met) {
    $graduation_status['eligibility'] = 'electives_pending';
    if ($completed_elective_courses < $min_electives_required) {
        $graduation_status['message'] = 'You need ' . ($min_electives_required - $completed_elective_courses) . ' more elective course(s).';
    } else {
        $graduation_status['message'] = 'You have too many elective courses. Maximum allowed: ' . $max_electives_allowed;
    }
} elseif (!$gpa_requirement_met || !$all_min_grades_met) {
    $graduation_status['eligibility'] = 'grades_pending';
    $graduation_status['message'] = 'Your GPA (' . $graduation_status['gpa'] . ') does not meet the minimum requirement (' . $min_grade_required . ' or ' . $min_grade_points . ').';
} elseif (!$financial_clearance) {
    $graduation_status['eligibility'] = 'financial_pending';
    $graduation_status['message'] = 'You have outstanding financial obligations. Please clear your balance.';
} else {
    $graduation_status['eligibility'] = 'not_eligible';
    $graduation_status['message'] = 'You are not yet eligible for graduation.';
}

// Check if graduation application exists
$graduation_application = [];
if ($graduation_status['requirements_met']) {
    $app_sql = "SELECT * FROM applications 
                WHERE user_id = ? 
                AND applying_as = 'student'
                AND program_id = ?
                AND status IN ('pending', 'under_review', 'approved')
                AND (program_type = 'graduation' OR program_id = ?)
                ORDER BY created_at DESC
                LIMIT 1";

    $app_stmt = $conn->prepare($app_sql);
    $app_stmt->bind_param("iii", $user_id, $program['id'], $program['id']);
    $app_stmt->execute();
    $app_result = $app_stmt->get_result();
    if ($app_result->num_rows > 0) {
        $graduation_application = $app_result->fetch_assoc();
    }
    $app_stmt->close();
}

// Log activity
logActivity($user_id, 'graduation_view', 'Student viewed graduation status', $_SERVER['REMOTE_ADDR']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Graduation Status - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
        }

        /* Main Content Styles */
        .main-content {
            min-height: 100vh;
        }

        .top-bar {
            background-color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            min-height: 70px;
        }

        .page-title h1 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.75rem;
        }

        .back-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: var(--light);
            color: var(--dark);
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.75rem;
            transition: var(--transition);
            white-space: nowrap;
            touch-action: manipulation;
        }

        .back-button:hover,
        .back-button:active {
            background: var(--border);
        }

        .content-container {
            padding: 1rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header Section */
        .program-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem 1rem;
            margin-bottom: 1rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }

        .program-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }

        .program-header p {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }

        .program-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            margin-top: 1rem;
            font-size: 0.75rem;
        }

        .program-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            backdrop-filter: blur(10px);
        }

        /* Status Banner */
        .status-banner {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .status-banner.eligible {
            background: linear-gradient(135deg, rgba(76, 201, 240, 0.15), rgba(76, 201, 240, 0.3));
            border-left: 4px solid var(--success);
        }

        .status-banner.not-eligible {
            background: linear-gradient(135deg, rgba(247, 37, 133, 0.15), rgba(247, 37, 133, 0.3));
            border-left: 4px solid var(--warning);
        }

        .status-banner.pending {
            background: linear-gradient(135deg, rgba(72, 149, 239, 0.15), rgba(72, 149, 239, 0.3));
            border-left: 4px solid var(--info);
        }

        .status-icon {
            font-size: 2rem;
            align-self: center;
        }

        .eligible .status-icon {
            color: var(--success);
        }

        .not-eligible .status-icon {
            color: var(--warning);
        }

        .pending .status-icon {
            color: var(--info);
        }

        .status-content h3 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
            text-align: center;
        }

        .status-content p {
            color: var(--gray);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            text-align: center;
        }

        /* Grid Layout */
        .grid-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: var(--card-shadow);
            width: 100%;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Progress Section */
        .progress-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .progress-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .progress-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .progress-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Requirements Checklist */
        .requirements-list {
            list-style: none;
        }

        .requirement-item {
            display: flex;
            align-items: flex-start;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--border);
            transition: var(--transition);
        }

        .requirement-item.met {
            border-left-color: var(--success);
            background: rgba(76, 201, 240, 0.1);
        }

        .requirement-item.not-met {
            border-left-color: var(--warning);
            background: rgba(247, 37, 133, 0.1);
        }

        .requirement-icon {
            margin-right: 0.75rem;
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 0.125rem;
        }

        .met .requirement-icon {
            color: var(--success);
        }

        .not-met .requirement-icon {
            color: var(--warning);
        }

        .requirement-content {
            flex: 1;
            min-width: 0; /* For text truncation */
        }

        .requirement-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .requirement-desc {
            font-size: 0.75rem;
            color: var(--gray);
            line-height: 1.4;
        }

        /* Course Status - Mobile Cards */
        .course-cards {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .course-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            border-left: 4px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .course-code {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.875rem;
        }

        .course-type {
            font-size: 0.75rem;
            padding: 0.125rem 0.375rem;
            border-radius: 4px;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            font-weight: 500;
        }

        .course-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
            line-height: 1.4;
        }

        .course-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            font-size: 0.75rem;
        }

        .course-detail {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            color: var(--gray);
            margin-bottom: 0.125rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-inprogress {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .status-registered {
            background: rgba(115, 191, 105, 0.1);
            color: #73bf69;
        }

        .status-pending {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            text-align: center;
            width: 100%;
            touch-action: manipulation;
            min-height: 44px; /* Minimum touch target size */
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover,
        .btn-primary:active {
            background-color: var(--primary-dark);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover,
        .btn-success:active {
            background-color: #3da8d5;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-warning:hover,
        .btn-warning:active {
            background-color: #e1156d;
        }

        /* Application Status */
        .application-status {
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            background: #f8f9fa;
        }

        .application-status h4 {
            margin-bottom: 0.75rem;
            color: var(--dark);
            font-size: 1rem;
        }

        .application-details {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .application-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
            border: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark);
        }

        .detail-value {
            color: var(--gray);
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
            margin-top: 1rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1rem;
        }

        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            top: 4px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--border);
            border: 2px solid white;
        }

        .timeline-item.completed .timeline-marker {
            background: var(--success);
        }

        .timeline-item.current .timeline-marker {
            background: var(--primary);
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 8px;
            border-left: 4px solid var(--border);
        }

        .timeline-item.completed .timeline-content {
            border-left-color: var(--success);
        }

        .timeline-item.current .timeline-content {
            border-left-color: var(--primary);
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .timeline-desc {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            width: 100%;
        }

        .quick-actions .btn {
            min-height: 44px;
            font-size: 0.75rem;
            padding: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 1.125rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        /* Footer */
        .dashboard-footer {
            background-color: white;
            padding: 1rem;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 1.5rem;
        }

        .footer-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            text-align: center;
        }

        /* Table for larger screens */
        @media (min-width: 768px) {
            .top-bar {
                padding: 1rem 1.5rem;
                min-height: 80px;
            }

            .page-title h1 {
                font-size: 1.5rem;
            }

            .page-title p {
                font-size: 0.875rem;
            }

            .back-button {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }

            .content-container {
                padding: 1.5rem;
            }

            .program-header {
                padding: 2rem;
                margin-bottom: 1.5rem;
            }

            .program-header h1 {
                font-size: 2rem;
            }

            .program-header p {
                font-size: 1rem;
            }

            .program-meta {
                font-size: 0.875rem;
                gap: 1rem;
            }

            .program-meta-item {
                padding: 0.25rem 0.75rem;
            }

            .status-banner {
                padding: 1.5rem;
                flex-direction: row;
                align-items: center;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }

            .status-icon {
                font-size: 2.5rem;
                align-self: center;
            }

            .status-content h3 {
                font-size: 1.25rem;
                text-align: left;
            }

            .status-content p {
                font-size: 0.875rem;
                text-align: left;
            }

            .grid-container {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1.5rem;
            }

            .card {
                padding: 1.5rem;
            }

            .card-header h2 {
                font-size: 1.25rem;
            }

            .progress-summary {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }

            .progress-item {
                padding: 1.25rem;
            }

            .progress-value {
                font-size: 1.75rem;
            }

            .progress-label {
                font-size: 0.875rem;
            }

            .requirements-list {
                display: block;
            }

            .course-cards {
                display: none;
            }

            .course-table {
                display: table;
                width: 100%;
                border-collapse: collapse;
                margin-top: 1.5rem;
            }

            .course-table thead {
                background-color: #f8f9fa;
            }

            .course-table th {
                padding: 1rem;
                text-align: left;
                font-weight: 600;
                color: var(--dark);
                border-bottom: 2px solid var(--border);
                font-size: 0.875rem;
            }

            .course-table td {
                padding: 1rem;
                border-bottom: 1px solid var(--border);
                font-size: 0.875rem;
            }

            .course-table tbody tr:hover {
                background-color: #f8f9fa;
            }

            .btn {
                width: auto;
                min-height: auto;
                padding: 0.625rem 1.25rem;
            }

            .quick-actions {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .quick-actions .btn {
                font-size: 0.875rem;
                padding: 0.75rem 1rem;
            }

            .timeline {
                padding-left: 2rem;
            }

            .timeline-marker {
                left: -2rem;
                width: 16px;
                height: 16px;
                border: 3px solid white;
            }

            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                font-size: 0.875rem;
                padding: 1rem 1.5rem;
            }

            .footer-info {
                flex-direction: row;
                text-align: left;
            }
        }

        /* Medium screens */
        @media (min-width: 640px) and (max-width: 767px) {
            .progress-summary {
                grid-template-columns: repeat(4, 1fr);
            }

            .quick-actions {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Small screens adjustments */
        @media (max-width: 639px) {
            .course-table {
                display: none;
            }

            .course-cards {
                display: flex;
            }
        }

        /* Very small screens */
        @media (max-width: 360px) {
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
                min-height: auto;
                padding: 0.75rem;
            }

            .page-title h1 {
                font-size: 1.125rem;
            }

            .back-button {
                align-self: stretch;
                justify-content: center;
            }

            .progress-summary {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        /* Print styles */
        @media print {
            .top-bar,
            .back-button,
            .btn {
                display: none !important;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .status-banner {
                break-inside: avoid;
            }

            .grid-container {
                display: block;
            }
        }
    </style>
</head>

<body>
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>Graduation Status</h1>
                <p>Track your progress towards graduation</p>
            </div>
            <a href="index.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to Program
            </a>
        </div>

        <div class="content-container">
            <?php if (empty($program)): ?>
                <!-- NO PROGRAM ENROLLED STATE -->
                <div class="program-header" style="background: linear-gradient(135deg, var(--warning), var(--danger));">
                    <h1>No Program Enrolled</h1>
                    <p>You are not currently enrolled in any program. Please apply for a program to get started.</p>
                </div>

                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No Program Found</h3>
                        <p>You need to be enrolled in a program to track graduation status.</p>
                        <a href="<?php echo BASE_URL; ?>modules/student/applications/apply.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-file-alt"></i> Apply for a Program
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- PROGRAM HEADER -->
                <div class="program-header">
                    <h1><?php echo htmlspecialchars($program['name']); ?></h1>
                    <p>Graduation Requirements and Status</p>
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
                            <i class="fas fa-book"></i>
                            <span><?php echo $total_courses; ?> Courses</span>
                        </div>
                    </div>
                </div>

                <!-- STATUS BANNER -->
                <div class="status-banner <?php echo $graduation_status['eligibility']; ?>">
                    <div class="status-icon">
                        <?php if ($graduation_status['eligibility'] == 'eligible'): ?>
                            <i class="fas fa-check-circle"></i>
                        <?php elseif (strpos($graduation_status['eligibility'], 'pending') !== false): ?>
                            <i class="fas fa-clock"></i>
                        <?php else: ?>
                            <i class="fas fa-exclamation-circle"></i>
                        <?php endif; ?>
                    </div>
                    <div class="status-content">
                        <h3>
                            <?php if ($graduation_status['eligibility'] == 'eligible'): ?>
                                Congratulations! You're Eligible
                            <?php elseif (strpos($graduation_status['eligibility'], 'pending') !== false): ?>
                                Working Towards Graduation
                            <?php else: ?>
                                Not Yet Eligible
                            <?php endif; ?>
                        </h3>
                        <p><?php echo $graduation_status['message']; ?></p>
                        <p><strong>Progress:</strong> <?php echo $courses_completed_percentage; ?>% (<?php echo $completed_core_courses; ?>/<?php echo $total_core_courses; ?> core, <?php echo $completed_elective_courses; ?>/<?php echo $min_electives_required; ?> electives)</p>

                        <?php if ($graduation_status['eligibility'] == 'eligible' && empty($graduation_application)): ?>
                            <form method="POST" action="apply_graduation.php" style="margin-top: 0.75rem;">
                                <input type="hidden" name="program_id" value="<?php echo $program['id']; ?>">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-file-signature"></i> Apply for Graduation
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- GRID LAYOUT -->
                <div class="grid-container">
                    <!-- LEFT COLUMN: Requirements & Progress -->
                    <div>
                        <!-- Progress Summary -->
                        <div class="card">
                            <div class="card-header">
                                <h2>Progress Summary</h2>
                            </div>
                            <div class="progress-summary">
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo $courses_completed_percentage; ?>%</div>
                                    <div class="progress-label">Overall</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo $completed_core_courses; ?>/<?php echo $total_core_courses; ?></div>
                                    <div class="progress-label">Core</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo $completed_elective_courses; ?>/<?php echo $min_electives_required; ?></div>
                                    <div class="progress-label">Electives</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo $graduation_status['gpa']; ?></div>
                                    <div class="progress-label">GPA</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo $graduation_status['total_credits_earned']; ?>/<?php echo $graduation_status['total_credits_required']; ?></div>
                                    <div class="progress-label">Credits</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo count($in_progress_courses); ?></div>
                                    <div class="progress-label">In Progress</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo count($registered_courses); ?></div>
                                    <div class="progress-label">Registered</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-value"><?php echo count($pending_courses); ?></div>
                                    <div class="progress-label">Pending</div>
                                </div>
                            </div>
                        </div>

                        <!-- Requirements Checklist -->
                        <div class="card" style="margin-top: 1rem;">
                            <div class="card-header">
                                <h2>Graduation Requirements</h2>
                            </div>
                            <ul class="requirements-list">
                                <li class="requirement-item <?php echo $all_core_completed && $electives_met ? 'met' : 'not-met'; ?>">
                                    <div class="requirement-icon">
                                        <?php if ($all_core_completed && $electives_met): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="requirement-content">
                                        <div class="requirement-title">Complete Required Courses</div>
                                        <div class="requirement-desc">
                                            <?php echo $completed_core_courses; ?> of <?php echo $total_core_courses; ?> core completed<br>
                                            <?php echo $completed_elective_courses; ?> of <?php echo $min_electives_required; ?> electives completed
                                            <?php if (!$all_core_completed || !$electives_met): ?>
                                                <?php if (!$all_core_completed): ?>
                                                    (<?php echo $total_core_courses - $completed_core_courses; ?> core remaining)
                                                <?php elseif (!$electives_met): ?>
                                                    <?php if ($completed_elective_courses < $min_electives_required): ?>
                                                        (<?php echo $min_electives_required - $completed_elective_courses; ?> elective(s) remaining)
                                                    <?php else: ?>
                                                        (Max <?php echo $max_electives_allowed; ?> electives)
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                                <li class="requirement-item <?php echo $graduation_status['gpa_requirement_met'] ? 'met' : 'not-met'; ?>">
                                    <div class="requirement-icon">
                                        <?php if ($graduation_status['gpa_requirement_met']): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="requirement-content">
                                        <div class="requirement-title">Minimum GPA Requirement</div>
                                        <div class="requirement-desc">
                                            GPA: <?php echo $graduation_status['gpa']; ?> (Req: <?php echo $program_meta['min_grade_required'] ?? 'C'; ?> or <?php echo $min_grade_points; ?>)
                                        </div>
                                    </div>
                                </li>
                                <li class="requirement-item <?php echo $graduation_status['financial_clearance'] ? 'met' : 'not-met'; ?>">
                                    <div class="requirement-icon">
                                        <?php if ($graduation_status['financial_clearance']): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="requirement-content">
                                        <div class="requirement-title">Financial Clearance</div>
                                        <div class="requirement-desc">
                                            <?php if ($graduation_status['financial_clearance']): ?>
                                                All financial obligations cleared
                                            <?php else: ?>
                                                Outstanding balance needs to be cleared
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                                <li class="requirement-item <?php echo $all_min_grades_met ? 'met' : 'not-met'; ?>">
                                    <div class="requirement-icon">
                                        <?php if ($all_min_grades_met): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="requirement-content">
                                        <div class="requirement-title">Minimum Grade in All Courses</div>
                                        <div class="requirement-desc">
                                            <?php if ($all_min_grades_met): ?>
                                                All courses meet minimum grade
                                            <?php else: ?>
                                                Some courses need grade improvement
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <!-- Course Status -->
                        <div class="card" style="margin-top: 1rem;">
                            <div class="card-header">
                                <h2>Course Status</h2>
                            </div>
                            
                            <!-- Desktop Table -->
                            <div style="overflow-x: auto;">
                                <table class="course-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Grade</th>
                                            <th>Start Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Combine all courses with their status
                                        $all_courses = [];

                                        // Add completed courses
                                        foreach ($completed_courses as $course) {
                                            $all_courses[$course['course_id']] = [
                                                'course_code' => $course['course_code'],
                                                'title' => $course['title'],
                                                'type' => $course['course_type'],
                                                'status' => 'completed',
                                                'grade' => $course['final_grade'],
                                                'start_date' => $course['start_date']
                                            ];
                                        }

                                        // Add in-progress courses
                                        foreach ($in_progress_courses as $course) {
                                            $all_courses[$course['course_id']] = [
                                                'course_code' => $course['course_code'],
                                                'title' => $course['title'],
                                                'type' => $course['course_type'],
                                                'status' => 'inprogress',
                                                'grade' => 'In Progress',
                                                'start_date' => $course['start_date']
                                            ];
                                        }

                                        // Add registered courses (not started yet)
                                        foreach ($registered_courses as $course) {
                                            $all_courses[$course['course_id']] = [
                                                'course_code' => $course['course_code'],
                                                'title' => $course['title'],
                                                'type' => $course['course_type'],
                                                'status' => 'registered',
                                                'grade' => 'Not Started',
                                                'start_date' => $course['start_date']
                                            ];
                                        }

                                        // Add pending courses
                                        foreach ($pending_courses as $course) {
                                            $all_courses[$course['id']] = [
                                                'course_code' => $course['course_code'],
                                                'title' => $course['title'],
                                                'type' => $course['course_type'],
                                                'status' => 'pending',
                                                'grade' => 'Not Enrolled',
                                                'start_date' => 'N/A'
                                            ];
                                        }

                                        // Display courses in program order
                                        foreach ($program_courses as $program_course) {
                                            if (isset($all_courses[$program_course['id']])) {
                                                $course = $all_courses[$program_course['id']];
                                        ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($course['title']); ?></td>
                                                    <td>
                                                        <span style="font-weight: 600; color: <?php echo $course['type'] == 'core' ? 'var(--primary)' : 'var(--secondary)'; ?>">
                                                            <?php echo ucfirst($course['type'] ?? $course['course_type'] ?? 'core'); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $course['status']; ?>">
                                                            <?php
                                                            if ($course['status'] == 'inprogress') {
                                                                echo 'In Progress';
                                                            } elseif ($course['status'] == 'registered') {
                                                                echo 'Registered';
                                                            } else {
                                                                echo ucfirst($course['status']);
                                                            }
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $course['grade']; ?></td>
                                                    <td>
                                                        <?php
                                                        if ($course['status'] == 'registered' || $course['status'] == 'inprogress') {
                                                            echo date('M d, Y', strtotime($course['start_date']));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                        <?php
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile Cards -->
                            <div class="course-cards">
                                <?php
                                foreach ($program_courses as $program_course) {
                                    if (isset($all_courses[$program_course['id']])) {
                                        $course = $all_courses[$program_course['id']];
                                        $status_class = 'status-' . $course['status'];
                                ?>
                                        <div class="course-card">
                                            <div class="course-header">
                                                <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                <div class="course-type"><?php echo ucfirst($course['type'] ?? $course['course_type'] ?? 'core'); ?></div>
                                            </div>
                                            <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                            <div class="course-details">
                                                <div class="course-detail">
                                                    <div class="detail-label">Status</div>
                                                    <div class="detail-value">
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php
                                                            if ($course['status'] == 'inprogress') {
                                                                echo 'In Progress';
                                                            } elseif ($course['status'] == 'registered') {
                                                                echo 'Registered';
                                                            } else {
                                                                echo ucfirst($course['status']);
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="course-detail">
                                                    <div class="detail-label">Grade</div>
                                                    <div class="detail-value"><?php echo $course['grade']; ?></div>
                                                </div>
                                                <div class="course-detail">
                                                    <div class="detail-label">Start Date</div>
                                                    <div class="detail-value">
                                                        <?php
                                                        if ($course['status'] == 'registered' || $course['status'] == 'inprogress') {
                                                            echo date('M d, Y', strtotime($course['start_date']));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                <?php
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Timeline & Actions -->
                    <div>
                        <!-- Graduation Timeline -->
                        <div class="card">
                            <div class="card-header">
                                <h2>Graduation Timeline</h2>
                            </div>
                            <div class="timeline">
                                <div class="timeline-item <?php echo $all_core_completed ? 'completed' : ($completed_core_courses > 0 ? 'current' : ''); ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Complete Core Courses</div>
                                        <div class="timeline-desc">
                                            <?php echo $completed_core_courses; ?> of <?php echo $total_core_courses; ?> completed
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-item <?php echo $electives_met ? 'completed' : ($completed_elective_courses > 0 ? 'current' : ''); ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Meet Elective Requirements</div>
                                        <div class="timeline-desc">
                                            Electives: <?php echo $completed_elective_courses; ?>/<?php echo $min_electives_required; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-item <?php echo $graduation_status['gpa_requirement_met'] ? 'completed' : ($all_core_completed && $electives_met ? 'current' : ''); ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Achieve Minimum GPA</div>
                                        <div class="timeline-desc">
                                            GPA: <?php echo $graduation_status['gpa']; ?> (Req: <?php echo $min_grade_points; ?>)
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-item <?php echo $graduation_status['financial_clearance'] ? 'completed' : ($graduation_status['gpa_requirement_met'] ? 'current' : ''); ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Financial Clearance</div>
                                        <div class="timeline-desc">
                                            <?php echo $graduation_status['financial_clearance'] ? 'Cleared' : 'Pending'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-item <?php echo $graduation_status['requirements_met'] ? 'completed' : ''; ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Submit Graduation Application</div>
                                        <div class="timeline-desc">
                                            <?php if (!empty($graduation_application)): ?>
                                                Status: <?php echo ucfirst($graduation_application['status']); ?>
                                            <?php else: ?>
                                                Available when all requirements met
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-item <?php echo ($graduation_application['status'] ?? '') == 'approved' ? 'completed' : ''; ?>">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Graduation Ceremony</div>
                                        <div class="timeline-desc">
                                            Receive your certificate
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card" style="margin-top: 1rem;">
                            <div class="card-header">
                                <h2>Quick Actions</h2>
                            </div>
                            <div class="quick-actions">
                                <a href="index.php" class="btn btn-primary">
                                    <i class="fas fa-chart-line"></i> Overview
                                </a>
                                <a href="courses.php" class="btn" style="background: var(--light); color: var(--dark);">
                                    <i class="fas fa-book"></i> Courses
                                </a>
                                <?php if (!empty($in_progress_courses)): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn" style="background: var(--light); color: var(--dark);">
                                        <i class="fas fa-chalkboard-teacher"></i> Classes
                                    </a>
                                <?php endif; ?>
                                <?php if (!$graduation_status['financial_clearance']): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php" class="btn btn-warning">
                                        <i class="fas fa-money-bill-wave"></i> Finance
                                    </a>
                                <?php endif; ?>
                                <?php if ($pending_courses && $enrollment_status == 'enrolled'): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/student/enrollment/enroll.php" class="btn" style="background: var(--light); color: var(--dark);">
                                        <i class="fas fa-plus-circle"></i> Enroll
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Application Status -->
                        <?php if (!empty($graduation_application)): ?>
                            <div class="card" style="margin-top: 1rem;">
                                <div class="card-header">
                                    <h2>Graduation Application</h2>
                                </div>
                                <div class="application-status">
                                    <div class="application-details">
                                        <div class="application-detail">
                                            <span class="detail-label">Status:</span>
                                            <span class="detail-value">
                                                <span class="status-badge status-<?php echo $graduation_application['status']; ?>">
                                                    <?php echo ucfirst($graduation_application['status']); ?>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="application-detail">
                                            <span class="detail-label">Submitted:</span>
                                            <span class="detail-value"><?php echo date('M d, Y', strtotime($graduation_application['created_at'])); ?></span>
                                        </div>
                                        <?php if (!empty($graduation_application['reviewed_at'])): ?>
                                            <div class="application-detail">
                                                <span class="detail-label">Reviewed:</span>
                                                <span class="detail-value"><?php echo date('M d, Y', strtotime($graduation_application['reviewed_at'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="dashboard-footer">
                <div class="footer-info">
                    <span>Impact Digital Academy - Graduation Portal</span>
                    <?php if (!empty($graduation_status['gpa'])): ?>
                        <span style="color: var(--primary); font-weight: 600;">
                            <i class="fas fa-star"></i>
                            GPA: <?php echo $graduation_status['gpa']; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <span><?php echo date('F j, Y'); ?></span>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        // Mobile touch optimizations
        document.addEventListener('DOMContentLoaded', function() {
            // Add touch feedback to interactive elements
            const interactiveElements = document.querySelectorAll('.btn, .back-button, .requirement-item, .course-card');
            
            interactiveElements.forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                }, { passive: true });
                
                element.addEventListener('touchend', function() {
                    this.style.transform = 'scale(1)';
                }, { passive: true });
                
                element.addEventListener('touchcancel', function() {
                    this.style.transform = 'scale(1)';
                }, { passive: true });
            });

            // Prevent zoom on double tap
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, false);

            // Status badges tooltips for mobile
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                badge.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const status = this.textContent.trim().toLowerCase();
                    let message = '';
                    
                    switch (status) {
                        case 'completed':
                            message = 'This course has been successfully completed.';
                            break;
                        case 'inprogress':
                            message = 'This course is currently in progress (class has started).';
                            break;
                        case 'registered':
                            message = 'You are registered for this course but class hasn\'t started yet.';
                            break;
                        case 'pending':
                            message = 'This course has not been enrolled yet.';
                            break;
                        default:
                            message = 'Course status information.';
                    }
                    
                    // Use alert for simplicity on mobile
                    alert(message);
                });
            });

            // Handle orientation change
            let previousOrientation = window.orientation;
            window.addEventListener('orientationchange', function() {
                // Small delay to allow orientation to complete
                setTimeout(() => {
                    if (window.orientation !== previousOrientation) {
                        // Force a reflow to fix layout issues
                        document.body.style.display = 'none';
                        document.body.offsetHeight; // Trigger reflow
                        document.body.style.display = '';
                        previousOrientation = window.orientation;
                    }
                }, 100);
            });

            // Improve scrolling performance on mobile
            document.body.style.webkitOverflowScrolling = 'touch';

            // Auto-refresh every 10 minutes for status updates
            setTimeout(() => {
                window.location.reload();
            }, 600000); // 10 minutes
        });

        // Print function with mobile detection
        function printGraduationStatus() {
            if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
                // Mobile devices might not handle print well
                if (confirm('Printing on mobile devices may not work correctly. Continue?')) {
                    window.print();
                }
            } else {
                window.print();
            }
        }

        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P or Cmd + P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printGraduationStatus();
            }
        });
    </script>
</body>

</html>
<?php
// Only close the connection if it exists and is still open
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
    $conn->close();
}
?>