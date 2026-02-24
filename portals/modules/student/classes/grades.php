<?php
// modules/student/classes/grades.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Verify student is enrolled in this class
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM enrollments e 
        JOIN class_batches cb ON e.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        JOIN users u ON cb.instructor_id = u.id 
        WHERE e.class_id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Get student's enrollment info
$enrollment_sql = "SELECT e.*, e.final_grade
                   FROM enrollments e 
                   WHERE e.class_id = ? AND e.student_id = ?";
$stmt = $conn->prepare($enrollment_sql);
$stmt->bind_param("ii", $class_id, $student_id);
$stmt->execute();
$enrollment_result = $stmt->get_result();
$enrollment = $enrollment_result->fetch_assoc();
$enrollment_id = $enrollment['id'] ?? 0;
$stmt->close();

// Get assignments and grades for this class
$assignments_sql = "SELECT a.*, 
                           s.id as submission_id,
                           s.grade as submission_grade,
                           s.feedback as submission_feedback,
                           s.submitted_at as submission_date,
                           s.late_submission,
                           s.status as submission_status
                    FROM assignments a 
                    LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
                    WHERE a.class_id = ? AND a.is_published = 1
                    ORDER BY a.due_date ASC";
$stmt = $conn->prepare($assignments_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$assignments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_assignments = count($assignments);
$submitted_count = count(array_filter($assignments, fn($a) => $a['submission_id']));
$graded_count = count(array_filter($assignments, fn($a) => $a['submission_grade'] !== null));
$missing_count = count(array_filter($assignments, fn($a) => !$a['submission_id'] && strtotime($a['due_date']) < time()));

// Calculate overall grade
$total_points = 0;
$total_possible = 0;
$graded_assignments = 0;

foreach ($assignments as $assignment) {
    if ($assignment['submission_grade'] !== null) {
        $total_points += (float)$assignment['submission_grade'];
        $total_possible += (float)$assignment['total_points'];
        $graded_assignments++;
    }
}

$overall_percentage = $total_possible > 0 ? round(($total_points / $total_possible) * 100, 1) : 0;

// Get recent grade updates (last 5)
$recent_grades_sql = "SELECT a.title, s.grade, s.feedback, s.submitted_at, a.total_points
                      FROM assignment_submissions s
                      JOIN assignments a ON s.assignment_id = a.id
                      WHERE s.student_id = ? AND a.class_id = ? AND s.grade IS NOT NULL
                      ORDER BY s.submitted_at DESC
                      LIMIT 5";
$stmt = $conn->prepare($recent_grades_sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$recent_grades_result = $stmt->get_result();
$recent_grades = $recent_grades_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// Function to get grade color
function getGradeColor($grade)
{
    if ($grade >= 90) return 'grade-excellent';
    if ($grade >= 80) return 'grade-good';
    if ($grade >= 70) return 'grade-average';
    if ($grade >= 60) return 'grade-poor';
    return 'grade-fail';
}

// Function to get grade badge
function getGradeBadge($grade)
{
    if ($grade >= 90) return 'grade-a';
    if ($grade >= 80) return 'grade-b';
    if ($grade >= 70) return 'grade-c';
    if ($grade >= 60) return 'grade-d';
    return 'grade-f';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - My Grades</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        /* CSS Variables */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f94144;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border: #dee2e6;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
            --safe-bottom: env(safe-area-inset-bottom, 0);
            --safe-top: env(safe-area-inset-top, 0);
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: max(1rem, env(safe-area-inset-left)) max(1rem, env(safe-area-inset-right));
            padding-bottom: max(2rem, env(safe-area-inset-bottom));
        }

        /* Breadcrumb - Mobile Optimized */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: var(--gray);
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding: 0.25rem 0;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 2rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            background: white;
            border-color: var(--primary);
        }

        .breadcrumb .separator {
            opacity: 0.5;
            margin: 0 0.25rem;
        }

        .breadcrumb span {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .header-top {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        @media (min-width: 768px) {
            .header-top {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .class-info h1 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            word-break: break-word;
        }

        .class-info p {
            font-size: 1rem;
            opacity: 0.9;
        }

        /* Navigation - Horizontal Scroll */
        .nav-container {
            display: flex;
            gap: 0.5rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.5rem 0;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            position: relative;
            z-index: 1;
        }

        .nav-container::-webkit-scrollbar {
            display: none;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 2rem;
            text-decoration: none;
            color: white;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            white-space: nowrap;
            font-size: 0.9rem;
            min-height: 48px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .page-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .page-title h2 {
            font-size: 1.3rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .header-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .header-stats span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Stats Grid - Mobile First */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 1.25rem 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .stat-card:active {
            transform: scale(0.97);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.submitted {
            border-top-color: var(--success);
        }

        .stat-card.graded {
            border-top-color: var(--info);
        }

        .stat-card.average {
            border-top-color: var(--secondary);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Overall Grade Section */
        .overall-grade-section {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .overall-grade-section h2 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .overall-grade-display {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            align-items: center;
        }

        @media (min-width: 640px) {
            .overall-grade-display {
                flex-direction: row;
                justify-content: center;
                gap: 3rem;
            }
        }

        .grade-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 2.5rem;
            position: relative;
            box-shadow: var(--shadow-lg);
        }

        .grade-circle::after {
            content: '';
            position: absolute;
            width: 90%;
            height: 90%;
            border-radius: 50%;
            background: white;
        }

        .grade-circle span {
            position: relative;
            z-index: 1;
            color: var(--dark);
        }

        .grade-excellent {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .grade-good {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .grade-average {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .grade-poor {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .grade-fail {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
        }

        .grade-details {
            text-align: center;
        }

        @media (min-width: 640px) {
            .grade-details {
                text-align: left;
            }
        }

        .grade-detail {
            margin-bottom: 1rem;
        }

        .grade-detail-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .grade-detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding: 0.25rem 0;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .tabs::-webkit-scrollbar {
            display: none;
        }

        .tab {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid var(--border);
            border-radius: 2rem;
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
            font-size: 0.9rem;
            min-height: 48px;
        }

        .tab:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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

        /* Grades Table */
        .grades-table-container {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .grades-table thead {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .grades-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .grades-table tbody tr {
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .grades-table tbody tr:hover {
            background: var(--light);
        }

        .grades-table td {
            padding: 1rem;
            font-size: 0.9rem;
        }

        .assignment-title {
            font-weight: 600;
            color: var(--dark);
        }

        .grade-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
            min-width: 40px;
            text-align: center;
        }

        .grade-a {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-b {
            background: #dbeafe;
            color: #1e40af;
        }

        .grade-c {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-d {
            background: #fed7aa;
            color: #9a3412;
        }

        .grade-f {
            background: #fecaca;
            color: #991b1b;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .status-graded {
            background: #d1fae5;
            color: #065f46;
        }

        .status-submitted {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-missing {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Recent Updates */
        .recent-updates {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .section-title {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .updates-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .update-item {
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            background: var(--light);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .update-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .update-header {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        @media (min-width: 640px) {
            .update-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .update-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .update-grade {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .update-date {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .update-feedback {
            font-size: 0.9rem;
            color: var(--gray);
            padding: 0.75rem;
            background: white;
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--info);
        }

        /* Grade Legend */
        .grade-legend {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }

        .legend-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        @media (min-width: 640px) {
            .legend-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            background: var(--light);
            border-radius: var(--radius-sm);
        }

        .legend-color {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .legend-a {
            background: #10b981;
        }

        .legend-b {
            background: #3b82f6;
        }

        .legend-c {
            background: #f59e0b;
        }

        .legend-d {
            background: #ef4444;
        }

        .legend-f {
            background: #6b7280;
        }

        .legend-text {
            font-size: 0.8rem;
            color: var(--dark);
            line-height: 1.3;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: var(--radius);
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .empty-state p {
            max-width: 400px;
            margin: 0 auto;
            font-size: 0.95rem;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.9rem 1.5rem;
            background: white;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--dark);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            margin-top: 1rem;
            min-height: 48px;
            width: 100%;
        }

        @media (min-width: 640px) {
            .back-button {
                width: auto;
                display: inline-flex;
            }
        }

        .back-button:hover {
            background: var(--light);
            border-color: var(--primary);
            color: var(--primary);
        }

        .back-button i {
            font-size: 1rem;
        }

        /* Print Button */
        .print-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.9rem 1.5rem;
            background: linear-gradient(135deg, var(--success), #2a9d8f);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
            margin-left: 0;
            min-height: 48px;
            width: 100%;
        }

        @media (min-width: 640px) {
            .print-button {
                width: auto;
                margin-left: 1rem;
                display: inline-flex;
            }
        }

        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(76, 201, 240, 0.3);
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .nav-link,
            .tab,
            .back-button,
            .print-button {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .nav-link:active,
            .tab:active {
                transform: scale(0.97);
            }
        }

        /* Accessibility */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        :focus {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        :focus-visible {
            outline: 3px solid rgba(67, 97, 238, 0.3);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i>
                <span class="visually-hidden">Dashboard</span>
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i>
                <span class="visually-hidden">My Classes</span>
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Grades</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                    <p><?php echo htmlspecialchars($class['course_title']); ?> - <?php echo htmlspecialchars($class['program_name']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-book"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Tasks
                </a>
                <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-chart-line"></i> Grades
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discuss
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Updates
                </a>
                <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-users"></i> Classmates
                </a>
                <?php if (!empty($class['meeting_link'])): ?>
                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                        <i class="fas fa-video"></i> Join
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>
                    <i class="fas fa-chart-line"></i>
                    My Grades
                </h2>
                <p>Track your performance in <?php echo htmlspecialchars($class['batch_code']); ?></p>
            </div>
            <div class="header-stats">
                <span><i class="fas fa-file-alt"></i> <?php echo $total_assignments; ?> tasks</span>
                <span><i class="fas fa-check-circle"></i> <?php echo $graded_count; ?> graded</span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $total_assignments; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card submitted">
                <div class="stat-value"><?php echo $submitted_count; ?></div>
                <div class="stat-label">Submitted</div>
            </div>
            <div class="stat-card graded">
                <div class="stat-value"><?php echo $graded_count; ?></div>
                <div class="stat-label">Graded</div>
            </div>
            <div class="stat-card average">
                <div class="stat-value"><?php echo $overall_percentage; ?>%</div>
                <div class="stat-label">Average</div>
            </div>
        </div>

        <!-- Overall Grade Section -->
        <div class="overall-grade-section">
            <h2><i class="fas fa-chart-pie"></i> Overall Performance</h2>
            <div class="overall-grade-display">
                <div class="grade-circle <?php echo getGradeColor($overall_percentage); ?>">
                    <span><?php echo calculateGradeLetter($overall_percentage); ?></span>
                </div>
                <div class="grade-details">
                    <div class="grade-detail">
                        <div class="grade-detail-label">Percentage</div>
                        <div class="grade-detail-value"><?php echo $overall_percentage; ?>%</div>
                    </div>
                    <div class="grade-detail">
                        <div class="grade-detail-label">Points</div>
                        <div class="grade-detail-value"><?php echo round($total_points, 1); ?>/<?php echo round($total_possible, 1); ?></div>
                    </div>
                    <div class="grade-detail">
                        <div class="grade-detail-label">Graded Tasks</div>
                        <div class="grade-detail-value"><?php echo $graded_assignments; ?>/<?php echo $total_assignments; ?></div>
                    </div>
                    <div class="grade-detail">
                        <div class="grade-detail-label">Final Grade</div>
                        <div class="grade-detail-value">
                            <?php if ($enrollment['final_grade']): ?>
                                <?php echo $enrollment['final_grade']; ?>
                            <?php else: ?>
                                In Progress
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="#all-grades" class="tab active">
                <i class="fas fa-list"></i> All Grades
            </a>
            <a href="#recent-updates" class="tab">
                <i class="fas fa-history"></i> Updates
            </a>
            <a href="#legend" class="tab">
                <i class="fas fa-chart-bar"></i> Legend
            </a>
        </div>

        <!-- All Grades Tab -->
        <div id="all-grades" class="tab-content active">
            <div class="grades-table-container">
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Tasks Yet</h3>
                        <p>No assignments have been published for this class yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Due</th>
                                    <th>Score</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment):
                                    $percentage = $assignment['submission_grade'] !== null ?
                                        round(($assignment['submission_grade'] / $assignment['total_points']) * 100, 1) :
                                        null;
                                    $grade_letter = $percentage !== null ? calculateGradeLetter($percentage) : null;

                                    // Determine status
                                    if ($assignment['submission_grade'] !== null) {
                                        $status = 'Graded';
                                        $status_class = 'status-graded';
                                    } elseif ($assignment['submission_id']) {
                                        $status = 'Submitted';
                                        $status_class = 'status-submitted';
                                    } elseif (strtotime($assignment['due_date']) < time()) {
                                        $status = 'Missing';
                                        $status_class = 'status-missing';
                                    } else {
                                        $status = 'Pending';
                                        $status_class = 'status-pending';
                                    }
                                ?>
                                    <tr>
                                        <td class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></td>
                                        <td><?php echo date('M d', strtotime($assignment['due_date'])); ?></td>
                                        <td>
                                            <?php if ($assignment['submission_grade'] !== null): ?>
                                                <?php echo round($assignment['submission_grade'], 1); ?>/<?php echo $assignment['total_points']; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($grade_letter): ?>
                                                <span class="grade-badge <?php echo getGradeBadge($percentage); ?>">
                                                    <?php echo $grade_letter; ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Updates Tab -->
        <div id="recent-updates" class="tab-content">
            <div class="recent-updates">
                <h3 class="section-title">
                    <i class="fas fa-history"></i>
                    Recent Grade Updates
                </h3>

                <?php if (empty($recent_grades)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clock"></i>
                        <h3>No Recent Updates</h3>
                        <p>Your recent graded assignments will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="updates-list">
                        <?php foreach ($recent_grades as $update):
                            $percentage = round(($update['grade'] / $update['total_points']) * 100, 1);
                            $grade_letter = calculateGradeLetter($percentage);
                        ?>
                            <div class="update-item">
                                <div class="update-header">
                                    <div class="update-title"><?php echo htmlspecialchars($update['title']); ?></div>
                                    <div class="update-grade <?php echo getGradeBadge($percentage); ?>">
                                        <?php echo $grade_letter; ?> (<?php echo $percentage; ?>%)
                                    </div>
                                </div>
                                <div class="update-date">
                                    <i class="far fa-clock"></i>
                                    <?php echo time_ago($update['submitted_at']); ?>
                                </div>
                                <?php if ($update['feedback']): ?>
                                    <div class="update-feedback">
                                        <i class="fas fa-comment"></i>
                                        <?php echo htmlspecialchars($update['feedback']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Legend Tab -->
        <div id="legend" class="tab-content">
            <div class="grade-legend">
                <h3 class="section-title">
                    <i class="fas fa-chart-bar"></i>
                    Grade Scale
                </h3>

                <div class="legend-grid">
                    <div class="legend-item">
                        <div class="legend-color legend-a"></div>
                        <div class="legend-text">A (90-100%)<br><small>Excellent</small></div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color legend-b"></div>
                        <div class="legend-text">B (80-89%)<br><small>Good</small></div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color legend-c"></div>
                        <div class="legend-text">C (70-79%)<br><small>Average</small></div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color legend-d"></div>
                        <div class="legend-text">D (60-69%)<br><small>Below Avg</small></div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color legend-f"></div>
                        <div class="legend-text">F (0-59%)<br><small>Needs Work</small></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 2rem;">
            <a href="class_home.php?id=<?php echo $class_id; ?>" class="back-button">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
            <button class="print-button" onclick="printGrades()">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();

                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(t => {
                    t.classList.remove('active');
                });

                // Add active class to clicked tab
                tab.classList.add('active');

                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Show corresponding content
                const targetId = tab.getAttribute('href').substring(1);
                document.getElementById(targetId).classList.add('active');
            });
        });

        // Animate grade circle on load
        document.addEventListener('DOMContentLoaded', () => {
            const gradeCircle = document.querySelector('.grade-circle');
            if (gradeCircle) {
                gradeCircle.style.transform = 'scale(0.8)';
                gradeCircle.style.opacity = '0';

                setTimeout(() => {
                    gradeCircle.style.transition = 'all 0.5s ease';
                    gradeCircle.style.transform = 'scale(1)';
                    gradeCircle.style.opacity = '1';
                }, 300);
            }
        });

        // Print function
        function printGrades() {
            const printContent = document.querySelector('.grades-table-container').innerHTML;
            const originalContent = document.body.innerHTML;

            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Grades Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
                        h1 { color: #333; margin-bottom: 10px; }
                        h2 { color: #666; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .grade-badge { padding: 3px 8px; border-radius: 10px; font-size: 12px; display: inline-block; }
                        .grade-a { background: #d1fae5; color: #065f46; }
                        .grade-b { background: #dbeafe; color: #1e40af; }
                        .grade-c { background: #fef3c7; color: #92400e; }
                        .grade-d { background: #fed7aa; color: #9a3412; }
                        .grade-f { background: #fecaca; color: #991b1b; }
                        .status-badge { padding: 3px 8px; border-radius: 10px; font-size: 12px; }
                        .status-graded { background: #d1fae5; color: #065f46; }
                        .status-submitted { background: #dbeafe; color: #1e40af; }
                        .status-pending { background: #fef3c7; color: #92400e; }
                        .status-missing { background: #fee2e2; color: #991b1b; }
                        .print-header { margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        .print-footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
                        .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0; }
                        .summary-item { background: #f9f9f9; padding: 15px; border-radius: 5px; text-align: center; }
                        .summary-value { font-size: 24px; font-weight: bold; color: #333; }
                        .summary-label { font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Grades Report</h1>
                        <p>Student: <?php echo $_SESSION['user_name'] ?? 'Student'; ?></p>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    
                    <div class="summary">
                        <div class="summary-item">
                            <div class="summary-value"><?php echo $total_assignments; ?></div>
                            <div class="summary-label">Total Tasks</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo $graded_count; ?></div>
                            <div class="summary-label">Graded</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo $overall_percentage; ?>%</div>
                            <div class="summary-label">Average</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo calculateGradeLetter($overall_percentage); ?></div>
                            <div class="summary-label">Final Grade</div>
                        </div>
                    </div>
                    
                    ${printContent}
                    
                    <div class="print-footer">
                        <p>Generated on ${new Date().toLocaleString()}</p>
                    </div>
                </body>
                </html>
            `;

            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + 1-3 to switch tabs
            if (e.ctrlKey && e.key >= '1' && e.key <= '3') {
                e.preventDefault();
                const tabIndex = parseInt(e.key) - 1;
                const tabs = document.querySelectorAll('.tab');
                if (tabs[tabIndex]) {
                    tabs[tabIndex].click();
                }
            }

            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'class_home.php?id=<?php echo $class_id; ?>';
            }

            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printGrades();
            }
        });

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.stat-card, .nav-link, .tab, .back-button, .print-button').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }
    </script>
</body>

</html>