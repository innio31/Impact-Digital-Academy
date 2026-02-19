<?php
// modules/student/program/available_periods.php

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

// Check if student has an approved application for a program
$program = [];
$application_sql = "SELECT a.*, p.* FROM applications a
                   JOIN programs p ON a.program_id = p.id
                   WHERE a.user_id = ? 
                   AND a.applying_as = 'student'
                   AND a.status = 'approved'
                   ORDER BY a.created_at DESC
                   LIMIT 1";
$application_stmt = $conn->prepare($application_sql);
$application_stmt->bind_param("i", $user_id);
$application_stmt->execute();
$application_result = $application_stmt->get_result();
if ($application_result->num_rows > 0) {
    $program = $application_result->fetch_assoc();
}
$application_stmt->close();

if (empty($program)) {
    header('Location: index.php');
    exit();
}

// Check if student has paid registration fee for this program
$registration_fee_paid = false;
$registration_fee_sql = "SELECT * FROM registration_fee_payments 
                        WHERE student_id = ? 
                        AND program_id = ? 
                        AND status = 'completed'";
$registration_fee_stmt = $conn->prepare($registration_fee_sql);
$registration_fee_stmt->bind_param("ii", $user_id, $program['program_id']);
$registration_fee_stmt->execute();
$registration_fee_result = $registration_fee_stmt->get_result();
if ($registration_fee_result->num_rows > 0) {
    $registration_fee_paid = true;
}
$registration_fee_stmt->close();

// Get registration fee amount from program
$registration_fee_amount = $program['registration_fee'] ?? 0;

// Get available upcoming periods for the student's program type
$current_date = date('Y-m-d');
$periods_sql = "SELECT * FROM academic_periods 
               WHERE program_type = ? 
               AND status = 'upcoming'
               AND (registration_start_date IS NULL OR registration_start_date <= ?)
               AND (registration_deadline IS NULL OR registration_deadline >= ?)
               ORDER BY start_date ASC";
$periods_stmt = $conn->prepare($periods_sql);
$program_type = $program['program_type'];
$periods_stmt->bind_param("sss", $program_type, $current_date, $current_date);
$periods_stmt->execute();
$periods_result = $periods_stmt->get_result();
$available_periods = $periods_result->fetch_all(MYSQLI_ASSOC);
$periods_stmt->close();

// Also get future periods for information
$future_periods_sql = "SELECT * FROM academic_periods 
                      WHERE program_type = ? 
                      AND status = 'upcoming'
                      AND registration_start_date > ?
                      ORDER BY start_date ASC";
$future_stmt = $conn->prepare($future_periods_sql);
$future_stmt->bind_param("ss", $program_type, $current_date);
$future_stmt->execute();
$future_result = $future_stmt->get_result();
$future_periods = $future_result->fetch_all(MYSQLI_ASSOC);
$future_stmt->close();

// Check if student has existing registrations for each period
$student_registrations = [];
if (!empty($available_periods) || !empty($future_periods)) {
    $period_ids = array_merge(
        array_column($available_periods, 'id'),
        array_column($future_periods, 'id')
    );

    if (!empty($period_ids)) {
        $placeholders = implode(',', array_fill(0, count($period_ids), '?'));
        $registration_sql = "SELECT * FROM student_course_registrations 
                            WHERE student_id = ? 
                            AND period_id IN ($placeholders)";
        $registration_stmt = $conn->prepare($registration_sql);

        // Build parameters: student_id + all period_ids
        $params = array_merge([$user_id], $period_ids);
        $types = str_repeat('i', count($params));
        $registration_stmt->bind_param($types, ...$params);
        $registration_stmt->execute();
        $registration_result = $registration_stmt->get_result();

        while ($row = $registration_result->fetch_assoc()) {
            $student_registrations[$row['period_id']] = $row;
        }
        $registration_stmt->close();

        // Get registration course counts
        if (!empty($student_registrations)) {
            $registration_ids = array_column($student_registrations, 'id');
            if (!empty($registration_ids)) {
                $course_sql = "SELECT registration_id, COUNT(*) as course_count 
                              FROM registration_courses 
                              WHERE registration_id IN (" . implode(',', $registration_ids) . ")
                              GROUP BY registration_id";
                $course_result = $conn->query($course_sql);
                if ($course_result) {
                    while ($row = $course_result->fetch_assoc()) {
                        // Find the period_id for this registration_id
                        foreach ($student_registrations as $period_id => $reg) {
                            if ($reg['id'] == $row['registration_id']) {
                                $student_registrations[$period_id]['course_count'] = $row['course_count'];
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
}

// Check if student is already enrolled (active enrollment) for any period
$registered_periods = [];
$registered_sql = "SELECT DISTINCT 
                   CASE 
                     WHEN e.program_type = 'onsite' THEN e.term_id
                     WHEN e.program_type = 'online' THEN e.block_id
                   END as period_id
                   FROM enrollments e
                   WHERE e.student_id = ?
                   AND e.status = 'active'";
$registered_stmt = $conn->prepare($registered_sql);
$registered_stmt->bind_param("i", $user_id);
$registered_stmt->execute();
$registered_result = $registered_stmt->get_result();
while ($row = $registered_result->fetch_assoc()) {
    $registered_periods[] = $row['period_id'];
}
$registered_stmt->close();

// Get user details for sidebar
$user_details = [];
$user_sql = "SELECT u.*, up.* FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE u.id = ? AND u.role = 'student'";
$user_stmt = $conn->prepare($user_sql);
if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result && $user_result->num_rows > 0) {
        $user_details = $user_result->fetch_assoc();
    }
    $user_stmt->close();
}

// Get enrolled classes count for sidebar
$class_count = 0;
$class_count_sql = "SELECT COUNT(*) as count FROM enrollments WHERE student_id = ? AND status = 'active'";
$class_stmt = $conn->prepare($class_count_sql);
$class_stmt->bind_param("i", $user_id);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
if ($class_result) {
    $class_row = $class_result->fetch_assoc();
    $class_count = $class_row['count'] ?? 0;
}
$class_stmt->close();

// Handle registration status messages
$message = '';
$message_type = '';
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = isset($_GET['type']) ? $_GET['type'] : 'info';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Periods - Impact Digital Academy</title>
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
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }

        .toggle-sidebar:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
        }

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-details h3 {
            font-size: 1rem;
            font-weight: 600;
            color: white;
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
        }

        .sidebar.collapsed .user-details {
            display: none;
        }

        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 2px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            gap: 0.75rem;
        }

        .nav-item:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .nav-item.active {
            background-color: rgba(67, 97, 238, 0.2);
            color: white;
            border-left: 3px solid var(--primary);
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

        .sidebar.collapsed .nav-label {
            display: none;
        }

        .badge {
            background-color: var(--primary);
            color: white;
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 10px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .nav-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 1rem 0;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Main Content Styles */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: var(--transition);
        }

        .sidebar.collapsed~.main-content {
            margin-left: 70px;
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

        /* Periods Container */
        .periods-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
        }

        .header-card h1 {
            color: var(--dark);
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .header-card p {
            color: var(--gray);
            font-size: 1rem;
        }

        .program-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #f8f9ff;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            border: 1px solid var(--border);
        }

        .program-info i {
            color: var(--primary);
        }

        /* Period Card Styles */
        .period-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
            animation: slideUp 0.5s ease;
        }

        .period-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .period-card.upcoming {
            opacity: 0.9;
            border-left-color: var(--warning);
        }

        .period-card.registered {
            border-left-color: var(--success);
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .period-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .period-header h3 {
            color: var(--dark);
            font-size: 1.25rem;
            flex: 1;
        }

        .period-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-open {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .status-upcoming {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
            border: 1px solid rgba(247, 37, 133, 0.3);
        }

        .status-registered {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
            border: 1px solid rgba(76, 201, 240, 0.4);
        }

        .period-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .detail-item:hover {
            background: white;
            border-color: var(--primary);
        }

        .detail-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .deadline-warning {
            background: rgba(247, 37, 133, 0.1);
            border-left: 3px solid var(--warning);
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
        }

        .period-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        /* Button Styles */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn:disabled:hover {
            transform: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-top: 2rem;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            display: block;
            color: var(--primary);
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 1.25rem;
        }

        /* Dashboard Footer */
        .dashboard-footer {
            background-color: white;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 3rem;
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-indicator {
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

        /* Section Headers */
        .section-header {
            margin-top: 2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border);
            position: relative;
        }

        .section-header h2 {
            color: var(--dark);
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
            }

            .sidebar.collapsed {
                width: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar.collapsed~.main-content {
                margin-left: 0;
            }

            .top-actions {
                display: none;
            }

            .periods-container {
                padding: 1rem;
            }

            .period-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .period-details {
                grid-template-columns: 1fr;
            }

            .period-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Message Styles */
        .message-alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .message-alert.success {
            background-color: rgba(76, 201, 240, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .message-alert.info {
            background-color: rgba(72, 149, 239, 0.1);
            border: 1px solid var(--info);
            color: var(--info);
        }

        .message-alert.warning {
            background-color: rgba(247, 37, 133, 0.1);
            border: 1px solid var(--warning);
            color: var(--warning);
        }

        .message-alert.danger {
            background-color: rgba(230, 57, 70, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .close-message {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.25rem;
            opacity: 0.7;
            transition: var(--transition);
        }

        .close-message:hover {
            opacity: 1;
        }

        /* Registration Status Badges */
        .status-draft {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        .status-submitted {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
            border: 1px solid rgba(72, 149, 239, 0.3);
        }

        .status-confirmed {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
            border: 1px solid rgba(76, 201, 240, 0.4);
        }

        .status-cancelled {
            background: rgba(230, 57, 70, 0.1);
            color: var(--danger);
            border: 1px solid rgba(230, 57, 70, 0.3);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: var(--transition);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--dark);
            font-size: 1.25rem;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--danger);
            background-color: rgba(230, 57, 70, 0.1);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Registration Details */
        .registration-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .registration-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .registration-courses {
            margin-top: 1rem;
        }

        .course-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            border: 1px solid var(--border);
        }

        .course-code {
            font-weight: 600;
            color: var(--primary);
        }

        .course-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .status-approved {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .no-courses {
            text-align: center;
            color: var(--gray);
            padding: 1rem;
            font-style: italic;
        }

        /* Registration Actions */
        .registration-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-outline-danger {
            border-color: var(--danger);
            color: var(--danger);
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* Registration Deadline Warning */
        .edit-deadline-warning {
            background: rgba(247, 37, 133, 0.1);
            border-left: 3px solid var(--warning);
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        .edit-deadline-warning i {
            color: var(--warning);
            margin-right: 0.5rem;
        }

        /* Registration Period Info */
        .registration-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .registration-info i {
            width: 16px;
            text-align: center;
        }
    </style>
</head>

<body>
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
                $initials = 'S';
                if (!empty($user_details['first_name']) || !empty($user_details['last_name'])) {
                    $first = substr($user_details['first_name'] ?? '', 0, 1);
                    $last = substr($user_details['last_name'] ?? '', 0, 1);
                    $initials = strtoupper($first . $last) ?: 'S';
                }
                echo $initials;
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars(($user_details['first_name'] ?? '') . ' ' . ($user_details['last_name'] ?? '')); ?></h3>
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

            <!-- Program Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="nav-label">My Program</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="nav-item">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-label">Program Progress</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/available_periods.php" class="nav-item active">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-label">Available Periods</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/courses.php" class="nav-item">
                        <i class="fas fa-book"></i>
                        <span class="nav-label">All Courses</span>
                    </a>
                </div>
            </div>

            <!-- Classes Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-chalkboard"></i>
                    <span class="nav-label">My Classes</span>
                    <?php if ($class_count > 0): ?>
                        <span class="badge"><?php echo $class_count; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="nav-item">
                        <i class="fas fa-list"></i>
                        <span class="nav-label">All Classes</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/calendar.php" class="nav-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-label">Class Schedule</span>
                    </a>
                </div>
            </div>

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
                <h1>Available Periods</h1>
                <p>Select a period to register for courses</p>
            </div>

            <div class="top-actions">
                <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Program
                </a>
            </div>
        </div>

        <div class="periods-container">
            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="message-alert <?php echo $message_type; ?>">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo $message; ?></span>
                    <button class="close-message" onclick="this.parentElement.style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Header Card -->
            <div class="header-card">
                <h1>Academic Periods</h1>
                <p>Browse available periods for course registration. Select an open period to register for courses.</p>
                <div class="program-info">
                    <i class="fas fa-graduation-cap"></i>
                    <div>
                        <strong>Current Program:</strong> <?php echo htmlspecialchars($program['name'] ?? 'Not specified'); ?>
                        <span style="margin-left: 1rem; color: var(--primary);">
                            <i class="fas fa-<?php echo $program_type == 'onsite' ? 'building' : 'laptop'; ?>"></i>
                            <?php echo ucfirst($program_type); ?> Program
                        </span>
                    </div>
                </div>
            </div>

            <?php if (empty($available_periods) && empty($future_periods)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Available Periods</h3>
                    <p>There are currently no periods available for registration. Please check back later.</p>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary" style="margin-top: 1rem;">
                        <i class="fas fa-arrow-left"></i> Back to Program
                    </a>
                </div>
            <?php else: ?>
                <!-- Currently Open for Registration -->
                <?php if (!empty($available_periods)): ?>
                    <div class="section-header">
                        <h2><i class="fas fa-door-open"></i> Open for Registration</h2>
                    </div>

                    <?php foreach ($available_periods as $period): ?>
                        <?php
                        $is_registered = in_array($period['id'], $registered_periods);
                        $has_registration = isset($student_registrations[$period['id']]);
                        $registration = $has_registration ? $student_registrations[$period['id']] : null;
                        $can_edit = !$period['edit_deadline'] || strtotime($period['edit_deadline']) >= time();
                        $days_left = !empty($period['registration_deadline']) ?
                            floor((strtotime($period['registration_deadline']) - time()) / (60 * 60 * 24)) : null;
                        ?>
                        <div class="period-card <?php echo $is_registered ? 'registered' : ''; ?>">
                            <div class="period-header">
                                <h3>
                                    <i class="fas fa-calendar-week"></i>
                                    <?php echo htmlspecialchars($period['period_name']); ?>
                                    <span style="font-size: 0.875rem; color: var(--gray); margin-left: 0.5rem;">
                                        <?php echo $period['program_type'] == 'onsite' ? 'Term' : 'Block'; ?> <?php echo $period['period_number']; ?>
                                    </span>
                                </h3>
                                <span class="period-status 
                                    <?php if ($is_registered): ?>status-registered
                                    <?php elseif ($has_registration): ?>status-<?php echo $registration['status']; ?>
                                    <?php else: ?>status-open<?php endif; ?>">
                                    <i class="fas fa-<?php
                                                        if ($is_registered): ?>check-circle
                                        <?php elseif ($has_registration):
                                                            switch ($registration['status']) {
                                                                case 'draft':
                                                                    echo 'edit';
                                                                    break;
                                                                case 'submitted':
                                                                    echo 'paper-plane';
                                                                    break;
                                                                case 'confirmed':
                                                                    echo 'check-circle';
                                                                    break;
                                                                case 'cancelled':
                                                                    echo 'times-circle';
                                                                    break;
                                                                default:
                                                                    echo 'calendar-check';
                                                            }
                                                        else: ?>calendar-check<?php endif; ?>"></i>
                                    <?php
                                    if ($is_registered):
                                        echo 'Enrolled';
                                    elseif ($has_registration):
                                        echo ucfirst($registration['status']);
                                    else:
                                        echo 'Registration Open';
                                    endif;
                                    ?>
                                </span>
                            </div>

                            <div class="period-details">
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-alt"></i>
                                        Academic Year
                                    </div>
                                    <div class="detail-value"><?php echo htmlspecialchars($period['academic_year']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-clock"></i>
                                        Duration
                                    </div>
                                    <div class="detail-value"><?php echo $period['duration_weeks']; ?> weeks</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-plus"></i>
                                        Start Date
                                    </div>
                                    <div class="detail-value"><?php echo date('M d, Y', strtotime($period['start_date'])); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-minus"></i>
                                        Registration Deadline
                                    </div>
                                    <div class="detail-value">
                                        <?php if (!empty($period['registration_deadline'])): ?>
                                            <?php echo date('M d, Y', strtotime($period['registration_deadline'])); ?>
                                        <?php else: ?>
                                            Not specified
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($has_registration && !empty($registration['course_count'])): ?>
                                <div class="registration-info">
                                    <i class="fas fa-book"></i>
                                    <span><?php echo $registration['course_count']; ?> course(s) registered</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($period['registration_deadline']) && !$is_registered): ?>
                                <?php if ($days_left !== null && $days_left >= 0 && $days_left <= 7): ?>
                                    <div class="deadline-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <div>
                                            <strong>Registration Deadline:</strong>
                                            <?php echo date('M d, Y', strtotime($period['registration_deadline'])); ?>
                                            <span style="margin-left: 10px; font-weight: bold; color: <?php echo $days_left <= 3 ? 'var(--danger)' : 'var(--warning)'; ?>">
                                                (<?php echo $days_left; ?> days left)
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!$can_edit && $has_registration && $registration['status'] == 'draft'): ?>
                                <div class="edit-deadline-warning">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Edit period has ended. You can no longer modify your registration.
                                </div>
                            <?php endif; ?>

                            <div class="period-actions">
                                <?php if ($is_registered): ?>
                                    <button class="btn btn-secondary" disabled>
                                        <i class="fas fa-check-circle"></i> Already Enrolled
                                    </button>
                                <?php elseif ($has_registration): ?>
                                    <div class="registration-actions">
                                        <?php if ($registration['status'] == 'draft' && $can_edit): ?>
                                            <a href="register_courses.php?period_id=<?php echo $period['id']; ?>&edit=1" class="btn btn-primary btn-small">
                                                <i class="fas fa-edit"></i> Edit Registration
                                            </a>
                                            <a href="submit_registration.php?registration_id=<?php echo $registration['id']; ?>" class="btn btn-success btn-small">
                                                <i class="fas fa-paper-plane"></i> Submit Registration
                                            </a>
                                            <button class="btn btn-outline-danger btn-small" onclick="cancelRegistration(<?php echo $registration['id']; ?>, '<?php echo htmlspecialchars($period['period_name']); ?>')">
                                                <i class="fas fa-trash"></i> Cancel
                                            </button>
                                        <?php elseif ($registration['status'] == 'submitted' && $can_edit): ?>
                                            <button class="btn btn-secondary btn-small" disabled>
                                                <i class="fas fa-paper-plane"></i> Submitted for Review
                                            </button>
                                            <?php if ($period['allow_registration_edits']): ?>
                                                <a href="register_courses.php?period_id=<?php echo $period['id']; ?>&edit=1" class="btn btn-outline btn-small">
                                                    <i class="fas fa-edit"></i> Request Edit
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif ($registration['status'] == 'confirmed'): ?>
                                            <button class="btn btn-success btn-small" disabled>
                                                <i class="fas fa-check-circle"></i> Registration Confirmed
                                            </button>
                                        <?php elseif ($registration['status'] == 'cancelled'): ?>
                                            <button class="btn btn-secondary btn-small" disabled>
                                                <i class="fas fa-times-circle"></i> Registration Cancelled
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-small" disabled>
                                                <i class="fas fa-lock"></i> Cannot Modify
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-outline btn-small" onclick="showRegistrationDetails(<?php echo $period['id']; ?>, <?php echo $registration['id']; ?>)">
                                            <i class="fas fa-info-circle"></i> View Details
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <?php if (!$registration_fee_paid && $registration_fee_amount > 0): ?>
                                        <div class="payment-required-alert" style="background: rgba(247, 37, 133, 0.1); border-left: 3px solid var(--warning); padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem;">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <strong>Registration Fee Required:</strong>
                                            You need to pay the one-time, non-refundable registration fee (₦<?php echo number_format($registration_fee_amount, 2); ?>) before registering for courses.
                                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?type=registration&program_id=<?php echo $program['program_id']; ?>&amount=<?php echo $registration_fee_amount; ?>" class="btn btn-primary btn-small" style="margin-left: 1rem;">
                                                <i class="fas fa-credit-card"></i> Pay Now
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <div class="period-actions">
                                        <?php if ($registration_fee_paid || $registration_fee_amount == 0): ?>
                                            <!-- Show registration button if fee is paid or no fee required -->
                                            <a href="register_courses.php?period_id=<?php echo $period['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-calendar-plus"></i> Register for Courses
                                            </a>
                                        <?php else: ?>
                                            <!-- Disable button if registration fee not paid -->
                                            <button class="btn btn-primary" disabled title="Pay registration fee first">
                                                <i class="fas fa-lock"></i> Register for Courses
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary" onclick="showPeriodDetails(<?php echo $period['id']; ?>)">
                                            <i class="fas fa-info-circle"></i> View Details
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Future Periods (Not yet open for registration) -->
                <?php if (!empty($future_periods)): ?>
                    <div class="section-header" style="margin-top: 3rem;">
                        <h2><i class="fas fa-clock"></i> Upcoming Periods</h2>
                    </div>

                    <?php foreach ($future_periods as $period): ?>
                        <div class="period-card upcoming">
                            <div class="period-header">
                                <h3>
                                    <i class="fas fa-calendar-plus"></i>
                                    <?php echo htmlspecialchars($period['period_name']); ?>
                                    <span style="font-size: 0.875rem; color: var(--gray); margin-left: 0.5rem;">
                                        <?php echo $period['program_type'] == 'onsite' ? 'Term' : 'Block'; ?> <?php echo $period['period_number']; ?>
                                    </span>
                                </h3>
                                <span class="period-status status-upcoming">
                                    <i class="fas fa-clock"></i>
                                    Opens: <?php echo date('M d, Y', strtotime($period['registration_start_date'])); ?>
                                </span>
                            </div>

                            <div class="period-details">
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-alt"></i>
                                        Academic Year
                                    </div>
                                    <div class="detail-value"><?php echo htmlspecialchars($period['academic_year']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-clock"></i>
                                        Duration
                                    </div>
                                    <div class="detail-value"><?php echo $period['duration_weeks']; ?> weeks</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-plus"></i>
                                        Start Date
                                    </div>
                                    <div class="detail-value"><?php echo date('M d, Y', strtotime($period['start_date'])); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-calendar-check"></i>
                                        Registration Opens
                                    </div>
                                    <div class="detail-value"><?php echo date('M d, Y', strtotime($period['registration_start_date'])); ?></div>
                                </div>
                            </div>

                            <div class="period-actions">
                                <button class="btn btn-secondary" disabled>
                                    <i class="fas fa-lock"></i> Registration Not Yet Open
                                </button>
                                <button class="btn btn-secondary" onclick="showPeriodDetails(<?php echo $period['id']; ?>)">
                                    <i class="fas fa-info-circle"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Back Button -->
            <div style="margin-top: 2rem; text-align: center;">
                <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Program Dashboard
                </a>
            </div>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <div class="system-status">
                <div class="status-indicator"></div>
                <span>System Status: Operational</span>
            </div>
            <div>
                <span><?php echo date('F j, Y'); ?></span>
            </div>
        </div>
    </main>

    <!-- Registration Details Modal -->
    <div class="modal-overlay" id="registrationModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Registration Details</h3>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }

        // Toggle dropdown navigation
        function toggleDropdown(element) {
            const dropdown = element.closest('.nav-dropdown');
            dropdown.classList.toggle('active');
            const allDropdowns = document.querySelectorAll('.nav-dropdown.active');
            allDropdowns.forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('active');
                }
            });
        }

        // Show period details
        function showPeriodDetails(periodId) {
            fetch(`get_period_details.php?period_id=${periodId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('modalTitle').textContent = 'Period Details';
                    document.getElementById('modalBody').innerHTML = data;
                    document.getElementById('registrationModal').classList.add('active');
                })
                .catch(error => {
                    document.getElementById('modalBody').innerHTML = `
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: var(--danger); margin-bottom: 1rem;"></i>
                            <p>Error loading period details. Please try again.</p>
                        </div>
                    `;
                    document.getElementById('registrationModal').classList.add('active');
                });
        }

        // Show registration details
        function showRegistrationDetails(periodId, registrationId) {
            fetch(`get_registration_details.php?registration_id=${registrationId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('modalTitle').textContent = 'Registration Details';
                    document.getElementById('modalBody').innerHTML = data;
                    document.getElementById('registrationModal').classList.add('active');
                })
                .catch(error => {
                    document.getElementById('modalBody').innerHTML = `
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: var(--danger); margin-bottom: 1rem;"></i>
                            <p>Error loading registration details. Please try again.</p>
                        </div>
                    `;
                    document.getElementById('registrationModal').classList.add('active');
                });
        }

        // Cancel registration
        function cancelRegistration(registrationId, periodName) {
            if (confirm(`Are you sure you want to cancel your registration for ${periodName}? This action cannot be undone.`)) {
                fetch(`cancel_registration.php?registration_id=${registrationId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = `available_periods.php?message=${encodeURIComponent(data.message)}&type=success`;
                        } else {
                            alert(data.message || 'Error cancelling registration');
                        }
                    })
                    .catch(error => {
                        alert('Error cancelling registration');
                    });
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('registrationModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('registrationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Esc to close modal
            if (e.key === 'Escape' && document.getElementById('registrationModal').classList.contains('active')) {
                closeModal();
            }

            // Esc to close dropdowns
            if (e.key === 'Escape') {
                document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
            }

            // Ctrl + B to go back
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/program/';
            }
        });

        // Load sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                document.getElementById('sidebar').classList.add('collapsed');
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.nav-dropdown') && !event.target.closest('.sidebar')) {
                    document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                        dropdown.classList.remove('active');
                    });
                }
            });

            // Auto-hide message after 5 seconds
            const messageAlert = document.querySelector('.message-alert');
            if (messageAlert) {
                setTimeout(() => {
                    messageAlert.style.display = 'none';
                }, 5000);
            }
        });
    </script>
</body>

</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>