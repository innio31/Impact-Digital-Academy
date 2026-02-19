<?php
// modules/instructor/schedule.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get current date and view parameters
$current_date = date('Y-m-d');
$view = isset($_GET['view']) ? sanitize_input($_GET['view']) : 'week'; // week, month, day
$date = isset($_GET['date']) ? sanitize_input($_GET['date']) : $current_date;

// Validate date
try {
    $date_obj = new DateTime($date);
} catch (Exception $e) {
    $date_obj = new DateTime($current_date);
    $date = $current_date;
}

// Calculate date range based on view
switch ($view) {
    case 'day':
        $start_date = $date_obj->format('Y-m-d');
        $end_date = $date_obj->format('Y-m-d');
        break;
    case 'month':
        $start_date = $date_obj->format('Y-m-01');
        $end_date = $date_obj->modify('last day of this month')->format('Y-m-d');
        $date_obj = new DateTime($date); // Reset for next calculations
        break;
    case 'week':
    default:
        // Get Monday of the week
        $day_of_week = $date_obj->format('N'); // 1 (Monday) to 7 (Sunday)
        $monday = clone $date_obj;
        if ($day_of_week != 1) {
            $monday->modify('-' . ($day_of_week - 1) . ' days');
        }
        $start_date = $monday->format('Y-m-d');

        // Get Sunday of the week
        $sunday = clone $monday;
        $sunday->modify('+6 days');
        $end_date = $sunday->format('Y-m-d');
        break;
}

// Get instructor's schedule for the date range
$schedule_data = [];
$sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = cb.id AND e.status = 'active') as student_count,
               TIME_FORMAT(cb.schedule, '%H:%i') as class_time_raw,
               DATE_FORMAT(cb.schedule, '%W') as day_of_week,
               CASE 
                   WHEN cb.status = 'scheduled' THEN 'upcoming'
                   WHEN cb.status = 'ongoing' THEN 'current'
                   WHEN cb.status = 'completed' THEN 'past'
                   ELSE cb.status
               END as schedule_status
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        WHERE cb.instructor_id = ? 
        AND cb.start_date <= ? 
        AND cb.end_date >= ?
        AND cb.status IN ('scheduled', 'ongoing')
        ORDER BY cb.schedule, cb.start_date";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $instructor_id, $end_date, $start_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $schedule_data[] = $row;
    }
}
$stmt->close();

// Group schedule data by date for easier display
$grouped_schedule = [];
foreach ($schedule_data as $class) {
    // For classes that have specific schedule times
    if ($class['class_time_raw']) {
        // Parse schedule to get actual class dates
        $class_start = new DateTime($class['start_date']);
        $class_end = new DateTime($class['end_date']);

        // Generate dates between start and end based on schedule pattern
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($class_start, $interval, $class_end->modify('+1 day'));

        foreach ($daterange as $date) {
            $date_str = $date->format('Y-m-d');
            $day_name = $date->format('l');

            // Check if class occurs on this day (simplified - assumes daily if schedule exists)
            // In real system, you'd parse the schedule recurrence pattern
            if ($class['day_of_week'] && $day_name === $class['day_of_week']) {
                if (!isset($grouped_schedule[$date_str])) {
                    $grouped_schedule[$date_str] = [];
                }
                $grouped_schedule[$date_str][] = $class;
            } elseif (!$class['day_of_week']) {
                // If no specific day, assume it's a one-time class on start date
                if ($date_str === $class['start_date']) {
                    if (!isset($grouped_schedule[$date_str])) {
                        $grouped_schedule[$date_str] = [];
                    }
                    $grouped_schedule[$date_str][] = $class;
                }
            }
        }
    } else {
        // Classes without specific time (use start date)
        $date_str = $class['start_date'];
        if (!isset($grouped_schedule[$date_str])) {
            $grouped_schedule[$date_str] = [];
        }
        $grouped_schedule[$date_str][] = $class;
    }
}

// Sort grouped schedule by date
ksort($grouped_schedule);

// Get upcoming deadlines (assignments due)
$upcoming_deadlines = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title,
               DATE_FORMAT(a.due_date, '%Y-%m-%d %H:%i') as due_datetime,
               TIMESTAMPDIFF(DAY, NOW(), a.due_date) as days_remaining,
               (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id) as submission_count
        FROM assignments a 
        JOIN class_batches cb ON a.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        WHERE a.instructor_id = ? 
        AND a.due_date >= NOW()
        AND a.is_published = 1
        ORDER BY a.due_date ASC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $upcoming_deadlines = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get instructor's availability (if any)
$availability = [];
$sql = "SELECT day_of_week, start_time, end_time, is_available 
        FROM instructor_availability 
        WHERE instructor_id = ? 
        ORDER BY day_of_week, start_time";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $availability = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get statistics
$stats = [
    'total_classes' => 0,
    'upcoming_classes' => 0,
    'ongoing_classes' => 0,
    'total_hours_week' => 0,
    'students_week' => 0,
];

// Calculate statistics
$sql = "SELECT 
        COUNT(*) as total_classes,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as upcoming_classes,
        SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_classes
        FROM class_batches 
        WHERE instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $stats['total_classes'] = $row['total_classes'] ?? 0;
    $stats['upcoming_classes'] = $row['upcoming_classes'] ?? 0;
    $stats['ongoing_classes'] = $row['ongoing_classes'] ?? 0;
}
$stmt->close();

// Get classes this week for hour calculation
$week_start = (new DateTime())->modify('monday this week')->format('Y-m-d');
$week_end = (new DateTime())->modify('sunday this week')->format('Y-m-d');

$sql = "SELECT COUNT(DISTINCT e.student_id) as students_week
        FROM enrollments e 
        JOIN class_batches cb ON e.class_id = cb.id 
        WHERE cb.instructor_id = ? 
        AND e.status = 'active'
        AND cb.start_date <= ? 
        AND cb.end_date >= ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $instructor_id, $week_end, $week_start);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $stats['students_week'] = $row['students_week'] ?? 0;
}
$stmt->close();

// Log activity
logActivity('schedule_view', 'Instructor viewed schedule');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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

        /* Mobile-first responsive design */
        .container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            padding: 0;
        }

        /* Mobile Navigation */
        .mobile-header {
            display: none;
            background: white;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        @media (max-width: 768px) {
            .mobile-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mobile-logo {
            font-weight: bold;
            color: var(--primary);
            font-size: 1.2rem;
        }

        .mobile-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Sidebar for mobile */
        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #1e3a8a, #1e40af);
            color: white;
            z-index: 2000;
            transition: left 0.3s ease;
            overflow-y: auto;
        }

        .mobile-sidebar.active {
            left: 0;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1500;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Main Content */
        .main-content {
            padding: 1rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        @media (min-width: 769px) {
            .main-content {
                padding: 2rem;
            }
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        @media (min-width: 768px) {
            .page-header h1 {
                font-size: 2rem;
            }
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* View Controls */
        .view-controls-mobile {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .view-controls-mobile {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .date-navigation-mobile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: white;
            padding: 0.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
        }

        .nav-btn-mobile {
            background: var(--light);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--dark);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .nav-btn-mobile:hover {
            background: var(--primary);
            color: white;
        }

        .current-date-mobile {
            font-weight: 600;
            color: var(--dark);
            text-align: center;
            flex-grow: 1;
            min-width: 160px;
            font-size: 0.9rem;
        }

        @media (min-width: 480px) {
            .current-date-mobile {
                font-size: 1rem;
                min-width: 200px;
            }
        }

        .view-toggle-mobile {
            display: flex;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
        }

        @media (min-width: 768px) {
            .view-toggle-mobile {
                width: auto;
            }
        }

        .view-btn-mobile {
            flex: 1;
            padding: 0.75rem 0.5rem;
            background: none;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--gray);
            font-size: 0.9rem;
            text-align: center;
            white-space: nowrap;
        }

        @media (min-width: 480px) {
            .view-btn-mobile {
                padding: 0.75rem 1rem;
                font-size: 1rem;
            }
        }

        .view-btn-mobile:hover {
            background: var(--light);
        }

        .view-btn-mobile.active {
            background: var(--primary);
            color: white;
        }

        /* Stats Grid - Mobile Responsive */
        .stats-grid-mobile {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid-mobile {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }
        }

        .stat-card-mobile {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: transform 0.3s ease;
        }

        .stat-card-mobile:hover {
            transform: translateY(-2px);
        }

        .stat-icon-mobile {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .stat-icon-mobile {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
        }

        .stat-icon-mobile.classes {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .stat-icon-mobile.upcoming {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon-mobile.ongoing {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon-mobile.students {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .stat-content-mobile {
            flex: 1;
            min-width: 0;
        }

        .stat-value-mobile {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1;
        }

        @media (min-width: 768px) {
            .stat-value-mobile {
                font-size: 1.8rem;
            }
        }

        .stat-label-mobile {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .stat-label-mobile {
                font-size: 0.875rem;
            }
        }

        /* Schedule Container - Mobile Responsive */
        .schedule-container-mobile {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .schedule-container-mobile {
                flex-direction: row;
            }
        }

        .schedule-main {
            flex: 1;
            min-width: 0;
        }

        .schedule-sidebar {
            width: 100%;
        }

        @media (min-width: 1024px) {
            .schedule-sidebar {
                width: 320px;
                flex-shrink: 0;
            }
        }

        /* Schedule View */
        .schedule-view-mobile {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .schedule-header-mobile {
            padding: 1.25rem;
            background: var(--light);
            border-bottom: 2px solid var(--light-gray);
        }

        .schedule-title-mobile {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .schedule-subtitle-mobile {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Week View - Mobile Responsive */
        .week-view-mobile {
            display: flex;
            flex-direction: column;
            max-height: 500px;
            overflow-y: auto;
        }

        .day-card-mobile {
            border-bottom: 1px solid var(--light-gray);
            padding: 1rem;
        }

        .day-card-mobile:last-child {
            border-bottom: none;
        }

        .day-header-mobile {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .day-info-mobile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .day-date-mobile {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            background: var(--light);
            color: var(--dark);
        }

        .day-date-mobile.today {
            background: var(--primary);
            color: white;
        }

        .day-name-mobile {
            font-weight: 600;
            color: var(--dark);
        }

        .day-full-date-mobile {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .class-count-mobile {
            background: var(--primary);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Class Events - Mobile Optimized */
        .class-event-mobile {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid var(--primary);
            border-radius: 8px;
            padding: 0.875rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .class-event-mobile:last-child {
            margin-bottom: 0;
        }

        .class-event-mobile:hover {
            background: rgba(59, 130, 246, 0.15);
            transform: translateX(2px);
        }

        .class-event-mobile.past {
            opacity: 0.7;
            background: rgba(100, 116, 139, 0.1);
            border-left-color: var(--gray);
        }

        .class-event-mobile.current {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
        }

        .event-time-mobile {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .event-title-mobile {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
            line-height: 1.3;
        }

        .event-details-mobile {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.8rem;
            color: var(--gray);
            align-items: center;
        }

        .event-detail-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .meeting-link-mobile {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--primary);
            color: white;
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.5rem;
            transition: background 0.3s ease;
        }

        .meeting-link-mobile:hover {
            background: var(--secondary);
        }

        /* Day View - Mobile */
        .day-view-mobile {
            padding: 1rem;
        }

        .day-classes-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        /* Month View - Mobile */
        .month-view-mobile {
            padding: 1rem;
        }

        .month-calendar-mobile {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            margin-top: 1rem;
        }

        .month-day-header {
            text-align: center;
            padding: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .month-day-cell {
            aspect-ratio: 1;
            background: white;
            border: 1px solid var(--light-gray);
            padding: 0.25rem;
            position: relative;
            overflow: hidden;
        }

        .month-day-cell.other-month {
            background: var(--light);
            color: var(--gray);
        }

        .day-number-mobile {
            position: absolute;
            top: 0.25rem;
            left: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .day-number-mobile.today {
            background: var(--primary);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .event-indicator-mobile {
            position: absolute;
            bottom: 0.25rem;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 2px;
        }

        .event-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
        }

        .event-dot.past {
            background: var(--gray);
        }

        .event-dot.current {
            background: var(--success);
        }

        /* Side Panel Cards */
        .panel-card-mobile {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .panel-card-mobile:last-child {
            margin-bottom: 0;
        }

        .panel-header-mobile {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.875rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .panel-title-mobile {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .panel-count {
            background: var(--light);
            color: var(--dark);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Deadlines List */
        .deadline-list-mobile {
            max-height: 300px;
            overflow-y: auto;
        }

        .deadline-item-mobile {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            transition: background 0.3s ease;
        }

        .deadline-item-mobile:hover {
            background: var(--light);
        }

        .deadline-item-mobile:last-child {
            border-bottom: none;
        }

        .deadline-title-mobile {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }

        .deadline-course-mobile {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
        }

        .deadline-meta-mobile {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
        }

        .deadline-due-mobile {
            color: var(--warning);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .deadline-submissions-mobile {
            color: var(--primary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Quick Actions - Mobile */
        .quick-actions-mobile {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        @media (max-width: 480px) {
            .quick-actions-mobile {
                grid-template-columns: 1fr;
            }
        }

        .action-btn-mobile {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            background: var(--light);
            border: none;
            border-radius: 10px;
            color: var(--dark);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            min-height: 90px;
        }

        .action-btn-mobile:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .action-btn-mobile i {
            font-size: 1.5rem;
        }

        .action-label {
            font-size: 0.85rem;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            display: block;
        }

        .empty-title {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-description {
            margin-bottom: 1.5rem;
        }

        /* Action Buttons */
        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--primary);
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .action-button:hover {
            background: var(--secondary);
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .fab:hover {
            background: var(--secondary);
            transform: scale(1.1);
        }

        /* Date Picker */
        .date-picker-container {
            display: flex;
            justify-content: center;
            margin: 1rem 0;
        }

        .date-picker-btn-mobile {
            background: white;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
            max-width: 300px;
        }

        .date-picker-btn-mobile:hover {
            border-color: var(--primary);
        }

        /* Bottom Navigation for Mobile */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-around;
            padding: 0.75rem;
            z-index: 1000;
        }

        @media (min-width: 769px) {
            .bottom-nav {
                display: none;
            }
        }

        .nav-item-mobile {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.75rem;
            transition: color 0.3s ease;
            padding: 0.5rem;
        }

        .nav-item-mobile.active {
            color: var(--primary);
        }

        .nav-item-mobile i {
            font-size: 1.2rem;
        }

        /* Desktop Sidebar */
        .desktop-sidebar {
            display: none;
        }

        @media (min-width: 769px) {
            .desktop-sidebar {
                display: block;
                width: 280px;
                background: linear-gradient(180deg, #1e3a8a, #1e40af);
                color: white;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .main-content {
                margin-left: 280px;
            }
        }

        /* Print Button */
        .print-btn-mobile {
            position: fixed;
            bottom: 5rem;
            right: 1.5rem;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .print-btn-mobile:hover {
            background: var(--secondary);
            transform: scale(1.1);
        }
    </style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <button class="mobile-menu-btn" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="mobile-logo">IDA Schedule</div>
        <div class="mobile-user">
            <div class="user-avatar-sm">
                <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
            </div>
        </div>
    </div>

    <!-- Mobile Sidebar -->
    <div class="mobile-sidebar" id="mobileSidebar">
        <div style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 2rem;">
                <div style="width: 40px; height: 40px; background: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #1e40af; font-weight: bold; font-size: 1.2rem;">
                    IDA
                </div>
                <div style="font-size: 1.2rem; font-weight: 600;">Instructor Panel</div>
            </div>

            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                <div style="width: 50px; height: 50px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; color: #1e40af; font-weight: bold; font-size: 1.2rem; border: 3px solid rgba(255, 255, 255, 0.2);">
                    <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
                </div>
                <div>
                    <h3 style="margin: 0;"><?php echo htmlspecialchars($instructor_name); ?></h3>
                    <p style="margin: 0; opacity: 0.8;"><i class="fas fa-chalkboard-teacher"></i> Instructor</p>
                </div>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.5rem;">
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                    <i class="fas fa-chalkboard"></i>
                    <span>My Classes</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: white; text-decoration: none; background: rgba(255, 255, 255, 0.15); border-left: 3px solid white;">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Schedule</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                    <i class="fas fa-tasks"></i>
                    <span>Assignments</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>

                <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent; margin-top: 2rem;" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Desktop Sidebar -->
    <aside class="desktop-sidebar">
        <div style="padding: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; background: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #1e40af; font-weight: bold; font-size: 1.2rem;">
                    IDA
                </div>
                <div style="font-size: 1.2rem; font-weight: 600;">Instructor Panel</div>
            </div>
        </div>

        <div style="padding: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; gap: 1rem;">
            <div style="width: 50px; height: 50px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; color: #1e40af; font-weight: bold; font-size: 1.2rem; border: 3px solid rgba(255, 255, 255, 0.2);">
                <?php echo strtoupper(substr($instructor_name, 0, 1)); ?>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($instructor_name); ?></h3>
                <p style="margin: 0; opacity: 0.8; font-size: 0.875rem;"><i class="fas fa-chalkboard-teacher"></i> Instructor</p>
            </div>
        </div>

        <nav style="padding: 1.5rem 0;">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.5rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.5rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                <i class="fas fa-chalkboard"></i>
                <span>My Classes</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.5rem; color: white; text-decoration: none; background: rgba(255, 255, 255, 0.15); border-left: 3px solid white;">
                <i class="fas fa-calendar-alt"></i>
                <span>Schedule</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.5rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                <i class="fas fa-tasks"></i>
                <span>Assignments</span>
            </a>

            <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.5rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
        </nav>

        <div style="padding: 1.5rem; position: absolute; bottom: 0; width: 100%; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" style="display: flex; align-items: center; gap: 1rem; padding: 0.8rem; color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: all 0.3s ease; border-left: 3px solid transparent;" onclick="return confirm('Are you sure you want to logout?');">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Schedule</h1>
            <p class="page-subtitle">Manage your teaching schedule and deadlines</p>
        </div>

        <!-- View Controls -->
        <div class="view-controls-mobile">
            <div class="date-navigation-mobile">
                <button class="nav-btn-mobile" onclick="navigateDate('prev')" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </button>
                
                <div class="current-date-mobile" id="currentDateDisplay">
                    <?php
                    switch ($view) {
                        case 'day':
                            echo date('F j, Y', strtotime($date));
                            break;
                        case 'week':
                            echo date('F j', strtotime($start_date)) . ' - ' . date('j, Y', strtotime($end_date));
                            break;
                        case 'month':
                            echo date('F Y', strtotime($date));
                            break;
                    }
                    ?>
                </div>
                
                <button class="nav-btn-mobile" onclick="navigateDate('next')" title="Next">
                    <i class="fas fa-chevron-right"></i>
                </button>
                
                <button class="nav-btn-mobile" onclick="navigateDate('today')" title="Today">
                    <i class="fas fa-calendar-day"></i>
                </button>
            </div>
            
            <div class="view-toggle-mobile">
                <button class="view-btn-mobile <?php echo $view === 'day' ? 'active' : ''; ?>" onclick="changeView('day')">Day</button>
                <button class="view-btn-mobile <?php echo $view === 'week' ? 'active' : ''; ?>" onclick="changeView('week')">Week</button>
                <button class="view-btn-mobile <?php echo $view === 'month' ? 'active' : ''; ?>" onclick="changeView('month')">Month</button>
            </div>
        </div>

        <!-- Date Picker -->
        <div class="date-picker-container">
            <button class="date-picker-btn-mobile" id="datePickerBtn">
                <i class="far fa-calendar-alt"></i>
                <span>Select Date</span>
            </button>
        </div>

        <!-- Statistics -->
        <div class="stats-grid-mobile">
            <div class="stat-card-mobile">
                <div class="stat-icon-mobile classes">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <div class="stat-content-mobile">
                    <div class="stat-value-mobile"><?php echo $stats['total_classes']; ?></div>
                    <div class="stat-label-mobile">Total Classes</div>
                </div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-icon-mobile upcoming">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="stat-content-mobile">
                    <div class="stat-value-mobile"><?php echo $stats['upcoming_classes']; ?></div>
                    <div class="stat-label-mobile">Upcoming</div>
                </div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-icon-mobile ongoing">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-content-mobile">
                    <div class="stat-value-mobile"><?php echo $stats['ongoing_classes']; ?></div>
                    <div class="stat-label-mobile">Ongoing</div>
                </div>
            </div>
            
            <div class="stat-card-mobile">
                <div class="stat-icon-mobile students">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-content-mobile">
                    <div class="stat-value-mobile"><?php echo $stats['students_week']; ?></div>
                    <div class="stat-label-mobile">Students This Week</div>
                </div>
            </div>
        </div>

        <!-- Schedule Container -->
        <div class="schedule-container-mobile">
            <div class="schedule-main">
                <!-- Schedule View -->
                <div class="schedule-view-mobile">
                    <div class="schedule-header-mobile">
                        <div class="schedule-title-mobile">
                            <?php
                            if ($view === 'day') {
                                echo date('l, F j, Y', strtotime($date));
                            } elseif ($view === 'week') {
                                echo 'Week of ' . date('F j', strtotime($start_date)) . ' - ' . date('j, Y', strtotime($end_date));
                            } else {
                                echo date('F Y', strtotime($date));
                            }
                            ?>
                        </div>
                        <div class="schedule-subtitle-mobile">
                            <span><?php echo count($schedule_data); ?> classes</span>
                            <span><?php echo count($upcoming_deadlines); ?> pending deadlines</span>
                        </div>
                    </div>
                    
                    <?php if (empty($grouped_schedule)): ?>
                        <div class="empty-state">
                            <i class="far fa-calendar-times empty-icon"></i>
                            <h3 class="empty-title">No classes scheduled</h3>
                            <p class="empty-description">You don't have any classes scheduled for this period.</p>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="action-button">
                                <i class="fas fa-chalkboard"></i>
                                View My Classes
                            </a>
                        </div>
                    <?php else: ?>
                        <?php if ($view === 'week'): ?>
                            <!-- Week View (Mobile) -->
                            <div class="week-view-mobile">
                                <?php
                                // Generate days of the week
                                $current = new DateTime($start_date);
                                for ($i = 0; $i < 7; $i++):
                                    $day_date = $current->format('Y-m-d');
                                    $is_today = $day_date === $current_date;
                                    $day_classes = $grouped_schedule[$day_date] ?? [];
                                ?>
                                    <div class="day-card-mobile">
                                        <div class="day-header-mobile">
                                            <div class="day-info-mobile">
                                                <div class="day-date-mobile <?php echo $is_today ? 'today' : ''; ?>">
                                                    <?php echo $current->format('j'); ?>
                                                </div>
                                                <div>
                                                    <div class="day-name-mobile"><?php echo $current->format('l'); ?></div>
                                                    <div class="day-full-date-mobile"><?php echo $current->format('F j, Y'); ?></div>
                                                </div>
                                            </div>
                                            <?php if (!empty($day_classes)): ?>
                                                <div class="class-count-mobile"><?php echo count($day_classes); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($day_classes)): ?>
                                            <div class="day-classes-list">
                                                <?php foreach ($day_classes as $class): ?>
                                                    <div class="class-event-mobile <?php echo $class['schedule_status']; ?>">
                                                        <div class="event-time-mobile">
                                                            <span>
                                                                <?php
                                                                if ($class['class_time_raw']) {
                                                                    echo date('g:i A', strtotime($class['class_time_raw']));
                                                                } else {
                                                                    echo 'All Day';
                                                                }
                                                                ?>
                                                            </span>
                                                            <span style="font-size: 0.7rem; background: var(--light); padding: 0.2rem 0.5rem; border-radius: 10px;">
                                                                <?php echo ucfirst($class['schedule_status']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="event-title-mobile">
                                                            <?php echo htmlspecialchars($class['course_title']); ?>
                                                        </div>
                                                        <div class="event-details-mobile">
                                                            <span class="event-detail-item">
                                                                <i class="fas fa-hashtag"></i>
                                                                <?php echo htmlspecialchars($class['batch_code']); ?>
                                                            </span>
                                                            <span class="event-detail-item">
                                                                <i class="fas fa-users"></i>
                                                                <?php echo $class['student_count']; ?> students
                                                            </span>
                                                        </div>
                                                        <?php if ($class['meeting_link']): ?>
                                                            <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="meeting-link-mobile">
                                                                <i class="fas fa-video"></i>
                                                                Join Class
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="text-align: center; padding: 1rem; color: var(--gray); font-style: italic;">
                                                No classes scheduled
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php
                                    $current->modify('+1 day');
                                endfor;
                                ?>
                            </div>
                            
                        <?php elseif ($view === 'day'): ?>
                            <!-- Day View (Mobile) -->
                            <div class="day-view-mobile">
                                <div class="day-classes-list">
                                    <?php
                                    $day_classes = $grouped_schedule[$date] ?? [];
                                    if (empty($day_classes)): ?>
                                        <div class="empty-state">
                                            <i class="far fa-calendar-check empty-icon"></i>
                                            <h3 class="empty-title">No classes today</h3>
                                            <p class="empty-description">Enjoy your free time!</p>
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        // Sort classes by time
                                        usort($day_classes, function ($a, $b) {
                                            $time_a = $a['class_time_raw'] ? strtotime($a['class_time_raw']) : 0;
                                            $time_b = $b['class_time_raw'] ? strtotime($b['class_time_raw']) : 0;
                                            return $time_a - $time_b;
                                        });
                                        
                                        foreach ($day_classes as $class): ?>
                                            <div class="class-event-mobile <?php echo $class['schedule_status']; ?>">
                                                <div class="event-time-mobile">
                                                    <span>
                                                        <?php
                                                        if ($class['class_time_raw']) {
                                                            echo date('g:i A', strtotime($class['class_time_raw']));
                                                        } else {
                                                            echo 'All Day';
                                                        }
                                                        ?>
                                                    </span>
                                                    <span style="font-size: 0.7rem; background: var(--light); padding: 0.2rem 0.5rem; border-radius: 10px;">
                                                        <?php echo ucfirst($class['schedule_status']); ?>
                                                    </span>
                                                </div>
                                                <div class="event-title-mobile">
                                                    <?php echo htmlspecialchars($class['course_title']); ?>
                                                </div>
                                                <div class="event-details-mobile">
                                                    <span class="event-detail-item">
                                                        <i class="fas fa-hashtag"></i>
                                                        <?php echo htmlspecialchars($class['batch_code']); ?>
                                                    </span>
                                                    <span class="event-detail-item">
                                                        <i class="fas fa-users"></i>
                                                        <?php echo $class['student_count']; ?> students
                                                    </span>
                                                    <?php if ($class['duration_hours'] ?? false): ?>
                                                        <span class="event-detail-item">
                                                            <i class="fas fa-clock"></i>
                                                            <?php echo $class['duration_hours']; ?> hrs
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($class['meeting_link']): ?>
                                                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="meeting-link-mobile">
                                                        <i class="fas fa-video"></i>
                                                        Join Class
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <!-- Month View (Mobile) -->
                            <div class="month-view-mobile">
                                <div class="month-calendar-mobile">
                                    <!-- Day Headers -->
                                    <div class="month-day-header">Mon</div>
                                    <div class="month-day-header">Tue</div>
                                    <div class="month-day-header">Wed</div>
                                    <div class="month-day-header">Thu</div>
                                    <div class="month-day-header">Fri</div>
                                    <div class="month-day-header">Sat</div>
                                    <div class="month-day-header">Sun</div>
                                    
                                    <?php
                                    $month_start = new DateTime(date('Y-m-01', strtotime($date)));
                                    $month_end = new DateTime(date('Y-m-t', strtotime($date)));
                                    
                                    // Get first day of month (0 = Sunday, 1 = Monday, etc.)
                                    $first_day = (int)$month_start->format('N') - 1; // Convert to 0-6 (Monday start)
                                    
                                    // Calculate total cells (6 weeks * 7 days = 42 cells)
                                    $total_cells = 42;
                                    
                                    // Create calendar
                                    $current_cell = 0;
                                    $current_date_cal = clone $month_start;
                                    $current_date_cal->modify('-' . $first_day . ' days');
                                    
                                    // Fill calendar
                                    for ($week = 0; $week < 6; $week++):
                                        for ($day = 0; $day < 7; $day++):
                                            $cell_date = $current_date_cal->format('Y-m-d');
                                            $is_current_month = $current_date_cal->format('m') === $month_start->format('m');
                                            $is_today = $cell_date === $current_date;
                                            $day_classes = $grouped_schedule[$cell_date] ?? [];
                                            $class_count = count($day_classes);
                                    ?>
                                            <div class="month-day-cell <?php echo !$is_current_month ? 'other-month' : ''; ?>">
                                                <div class="day-number-mobile <?php echo $is_today ? 'today' : ''; ?>">
                                                    <?php echo $current_date_cal->format('j'); ?>
                                                </div>
                                                
                                                <?php if ($class_count > 0): ?>
                                                    <div class="event-indicator-mobile">
                                                        <?php 
                                                        $display_count = min($class_count, 3);
                                                        for ($i = 0; $i < $display_count; $i++):
                                                            $class = $day_classes[$i] ?? null;
                                                        ?>
                                                            <div class="event-dot <?php echo $class['schedule_status'] ?? ''; ?>"></div>
                                                        <?php endfor; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                    <?php
                                            $current_date_cal->modify('+1 day');
                                        endfor;
                                    endfor;
                                    ?>
                                </div>
                                
                                <!-- Legend -->
                                <div style="display: flex; justify-content: center; gap: 1rem; margin-top: 1rem; font-size: 0.8rem; color: var(--gray);">
                                    <div style="display: flex; align-items: center; gap: 0.25rem;">
                                        <div class="event-dot" style="background: var(--primary);"></div>
                                        <span>Upcoming</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 0.25rem;">
                                        <div class="event-dot" style="background: var(--success);"></div>
                                        <span>Current</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 0.25rem;">
                                        <div class="event-dot" style="background: var(--gray);"></div>
                                        <span>Past</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Side Panel -->
            <div class="schedule-sidebar">
                <!-- Upcoming Deadlines -->
                <div class="panel-card-mobile">
                    <div class="panel-header-mobile">
                        <h3 class="panel-title-mobile">Upcoming Deadlines</h3>
                        <div class="panel-count"><?php echo count($upcoming_deadlines); ?></div>
                    </div>
                    
                    <div class="deadline-list-mobile">
                        <?php if (empty($upcoming_deadlines)): ?>
                            <div style="text-align: center; padding: 1rem; color: var(--gray);">
                                <i class="far fa-check-circle" style="font-size: 2rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                                No upcoming deadlines
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_deadlines as $deadline): ?>
                                <div class="deadline-item-mobile">
                                    <div class="deadline-title-mobile"><?php echo htmlspecialchars($deadline['title']); ?></div>
                                    <div class="deadline-course-mobile">
                                        <?php echo htmlspecialchars($deadline['course_title']); ?> - <?php echo $deadline['batch_code']; ?>
                                    </div>
                                    <div class="deadline-meta-mobile">
                                        <span class="deadline-due-mobile">
                                            <i class="far fa-clock"></i>
                                            <?php
                                            $days = $deadline['days_remaining'];
                                            if ($days == 0) {
                                                echo 'Today';
                                            } elseif ($days == 1) {
                                                echo 'Tomorrow';
                                            } elseif ($days < 7) {
                                                echo 'In ' . $days . ' days';
                                            } else {
                                                echo date('M j', strtotime($deadline['due_datetime']));
                                            }
                                            ?>
                                        </span>
                                        <span class="deadline-submissions-mobile">
                                            <i class="fas fa-paper-plane"></i> <?php echo $deadline['submission_count']; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="panel-card-mobile">
                    <div class="panel-header-mobile">
                        <h3 class="panel-title-mobile">Quick Actions</h3>
                    </div>
                    
                    <div class="quick-actions-mobile">
                        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/create.php" class="action-btn-mobile">
                            <i class="fas fa-plus-circle"></i>
                            <span class="action-label">New Class</span>
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/create.php" class="action-btn-mobile">
                            <i class="fas fa-tasks"></i>
                            <span class="action-label">Assignment</span>
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/instructor/announcements/create.php" class="action-btn-mobile">
                            <i class="fas fa-bullhorn"></i>
                            <span class="action-label">Announcement</span>
                        </a>
                        
                        <button class="action-btn-mobile" onclick="printSchedule()">
                            <i class="fas fa-print"></i>
                            <span class="action-label">Print</span>
                        </button>
                        
                        <button class="action-btn-mobile" onclick="exportSchedule()">
                            <i class="fas fa-download"></i>
                            <span class="action-label">Export</span>
                        </button>
                        
                        <button class="action-btn-mobile" onclick="shareSchedule()">
                            <i class="fas fa-share-alt"></i>
                            <span class="action-label">Share</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Print Button (Floating) -->
        <button class="print-btn-mobile" onclick="printSchedule()" title="Print Schedule">
            <i class="fas fa-print"></i>
        </button>
        
        <!-- Floating Action Button -->
        <button class="fab" onclick="createNewEvent()" title="Create New Event">
            <i class="fas fa-plus"></i>
        </button>
    </main>
    
    <!-- Bottom Navigation (Mobile Only) -->
    <nav class="bottom-nav">
        <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="nav-item-mobile">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        
        <a href="<?php echo BASE_URL; ?>modules/instructor/classes/" class="nav-item-mobile">
            <i class="fas fa-chalkboard"></i>
            <span>Classes</span>
        </a>
        
        <a href="<?php echo BASE_URL; ?>modules/instructor/schedule.php" class="nav-item-mobile active">
            <i class="fas fa-calendar-alt"></i>
            <span>Schedule</span>
        </a>
        
        <a href="<?php echo BASE_URL; ?>modules/instructor/assignments/" class="nav-item-mobile">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
        </a>
        
        <a href="<?php echo BASE_URL; ?>modules/instructor/profile/edit.php" class="nav-item-mobile">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        menuToggle.addEventListener('click', () => {
            mobileSidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = mobileSidebar.classList.contains('active') ? 'hidden' : '';
        });
        
        sidebarOverlay.addEventListener('click', () => {
            mobileSidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
        
        // Initialize date picker
        const datePicker = flatpickr("#datePickerBtn", {
            dateFormat: "Y-m-d",
            defaultDate: "<?php echo $date; ?>",
            onChange: function(selectedDates, dateStr) {
                if (dateStr) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('date', dateStr);
                    window.location.href = url.toString();
                }
            }
        });
        
        // Navigation functions
        function navigateDate(direction) {
            const currentDate = new Date("<?php echo $date; ?>");
            const view = "<?php echo $view; ?>";
            let newDate = new Date(currentDate);
            
            switch (direction) {
                case 'prev':
                    if (view === 'day') newDate.setDate(currentDate.getDate() - 1);
                    if (view === 'week') newDate.setDate(currentDate.getDate() - 7);
                    if (view === 'month') newDate.setMonth(currentDate.getMonth() - 1);
                    break;
                    
                case 'next':
                    if (view === 'day') newDate.setDate(currentDate.getDate() + 1);
                    if (view === 'week') newDate.setDate(currentDate.getDate() + 7);
                    if (view === 'month') newDate.setMonth(currentDate.getMonth() + 1);
                    break;
                    
                case 'today':
                    newDate = new Date();
                    break;
            }
            
            const dateStr = newDate.toISOString().split('T')[0];
            const url = new URL(window.location.href);
            url.searchParams.set('date', dateStr);
            window.location.href = url.toString();
        }
        
        function changeView(newView) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', newView);
            window.location.href = url.toString();
        }
        
        // Print schedule
        function printSchedule() {
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Schedule - <?php echo htmlspecialchars($instructor_name); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; font-size: 14px; }
                        .print-header { text-align: center; margin-bottom: 30px; }
                        .print-title { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
                        .print-date { color: #666; margin-bottom: 20px; }
                        .schedule-table { width: 100%; border-collapse: collapse; }
                        .schedule-table th { background: #f0f0f0; padding: 10px; text-align: left; border: 1px solid #ddd; }
                        .schedule-table td { padding: 10px; border: 1px solid #ddd; }
                        .class-item { margin-bottom: 10px; padding: 8px; border-left: 3px solid #3b82f6; background: #f8fafc; }
                        .class-time { font-weight: bold; color: #3b82f6; }
                        .class-title { font-weight: bold; margin: 5px 0; }
                        .class-details { font-size: 0.9em; color: #666; }
                        @media print {
                            .no-print { display: none; }
                            body { margin: 10px; }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <div class="print-title">Teaching Schedule</div>
                        <div class="print-subtitle"><?php echo htmlspecialchars($instructor_name); ?></div>
                        <div class="print-date">
                            <?php
                            if ($view === 'day') {
                                echo date('F j, Y', strtotime($date));
                            } elseif ($view === 'week') {
                                echo 'Week of ' . date('F j', strtotime($start_date)) . ' - ' . date('j, Y', strtotime($end_date));
                            } else {
                                echo date('F Y', strtotime($date));
                            }
                            ?>
                        </div>
                    </div>
                    
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Class</th>
                                <th>Students</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedule_data as $class): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($class['start_date'])); ?></td>
                                <td>
                                    <?php
                                    if ($class['class_time_raw']) {
                                        echo date('g:i A', strtotime($class['class_time_raw']));
                                    } else {
                                        echo 'TBD';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($class['course_title']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($class['batch_code']); ?></small>
                                </td>
                                <td><?php echo $class['student_count']; ?></td>
                                <td><?php echo ucfirst($class['status']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 30px; font-size: 12px; color: #666;">
                        <p>Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
                        <p>Impact Digital Academy - Instructor Portal</p>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }
        
        // Export to calendar
        function exportSchedule() {
            const calendarEvents = [];
            
            <?php foreach ($schedule_data as $class): ?>
                calendarEvents.push({
                    title: "<?php echo addslashes($class['course_title']); ?> - <?php echo addslashes($class['batch_code']); ?>",
                    start: "<?php echo $class['start_date']; ?>" + (<?php echo $class['class_time_raw'] ? "'T" . $class['class_time_raw'] . ":00'" : "''"; ?>),
                    end: "<?php echo $class['end_date']; ?>",
                    description: "Class: <?php echo addslashes($class['course_title']); ?>\nCode: <?php echo addslashes($class['batch_code']); ?>\nStudents: <?php echo $class['student_count']; ?>\nProgram: <?php echo addslashes($class['program_name']); ?>",
                    location: "<?php echo addslashes($class['meeting_link'] ?? 'Online/Classroom'); ?>"
                });
            <?php endforeach; ?>
            
            // Create iCalendar content
            let icsContent = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//Impact Digital Academy//Instructor Schedule//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH'
            ];
            
            calendarEvents.forEach((event, index) => {
                const uid = Date.now() + index + '@impactdigitalacademy.com';
                const start = event.start ? event.start.replace(/[-:]/g, '') : '';
                const end = event.end ? event.end.replace(/[-:]/g, '') : '';
                
                icsContent.push(
                    'BEGIN:VEVENT',
                    `UID:${uid}`,
                    `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').split('.')[0]}Z`,
                    event.start ? `DTSTART:${start}` : `DTSTART;VALUE=DATE:${start}`,
                    event.end ? `DTEND:${end}` : `DTEND;VALUE=DATE:${end}`,
                    `SUMMARY:${event.title}`,
                    `DESCRIPTION:${event.description.replace(/\n/g, '\\n')}`,
                    `LOCATION:${event.location}`,
                    'END:VEVENT'
                );
            });
            
            icsContent.push('END:VCALENDAR');
            
            // Download file
            const blob = new Blob([icsContent.join('\r\n')], {
                type: 'text/calendar;charset=utf-8'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `schedule-${<?php echo $instructor_id; ?>}-${new Date().toISOString().split('T')[0]}.ics`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Show success message
            showNotification('Schedule exported successfully! The .ics file can be imported into Google Calendar, Outlook, or Apple Calendar.', 'success');
        }
        
        // Share schedule
        function shareSchedule() {
            if (navigator.share) {
                navigator.share({
                    title: 'My Teaching Schedule',
                    text: 'Check out my teaching schedule at Impact Digital Academy',
                    url: window.location.href
                })
                .then(() => console.log('Shared successfully'))
                .catch((error) => console.log('Error sharing:', error));
            } else {
                // Fallback for browsers that don't support Web Share API
                navigator.clipboard.writeText(window.location.href)
                    .then(() => {
                        showNotification('Link copied to clipboard!', 'success');
                    })
                    .catch(err => {
                        showNotification('Failed to copy link: ' + err, 'error');
                    });
            }
        }
        
        // Create new event
        function createNewEvent() {
            window.location.href = '<?php echo BASE_URL; ?>modules/instructor/classes/create.php';
        }
        
        // Show notification
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                animation: slideIn 0.3s ease;
                max-width: 90vw;
                word-break: break-word;
            `;
            
            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-check-circle' : 
                            type === 'error' ? 'fas fa-exclamation-circle' : 
                            'fas fa-info-circle';
            
            const text = document.createElement('span');
            text.textContent = message;
            
            notification.appendChild(icon);
            notification.appendChild(text);
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Left/Right arrow navigation
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                navigateDate('prev');
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                navigateDate('next');
            } else if (e.key === 't' && e.ctrlKey) {
                e.preventDefault();
                navigateDate('today');
            } else if (e.key === 'p' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                printSchedule();
            }
        });
        
        // Touch gestures for mobile
        let touchStartX = 0;
        let touchEndX = 0;
        
        document.addEventListener('touchstart', e => {
            touchStartX = e.changedTouches[0].screenX;
        });
        
        document.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        });
        
        function handleSwipe() {
            const swipeThreshold = 50;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) > swipeThreshold) {
                if (diff > 0) {
                    // Swipe left - go to next
                    navigateDate('next');
                } else {
                    // Swipe right - go to previous
                    navigateDate('prev');
                }
            }
        }
        
        // Auto-refresh every 5 minutes
        setInterval(() => {
            // Check for new classes/deadlines without reloading
            console.log('Schedule auto-refresh at:', new Date().toLocaleTimeString());
        }, 5 * 60 * 1000);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set current date in the picker button
            const pickerBtn = document.getElementById('datePickerBtn');
            if (pickerBtn) {
                pickerBtn.innerHTML = `<i class="far fa-calendar-alt"></i><span>${document.getElementById('currentDateDisplay').textContent}</span>`;
            }
        });
    </script>
</body>
</html>