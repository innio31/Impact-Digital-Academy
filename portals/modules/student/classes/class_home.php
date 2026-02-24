<?php
// modules/student/classes/class_home.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify student enrollment
$sql = "SELECT cb.*, c.title as course_title, c.course_code, c.description as course_description,
               p.name as program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
               e.enrollment_date, e.status as enrollment_status, e.final_grade,
               e.certificate_issued, e.certificate_url,
               sfs.balance, sfs.is_cleared, sfs.current_block, sfs.is_suspended,
               sfs.next_payment_due
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        JOIN enrollments e ON cb.id = e.class_id 
        LEFT JOIN student_financial_status sfs ON (e.student_id = sfs.student_id AND e.class_id = sfs.class_id)
        WHERE cb.id = ? AND e.student_id = ? AND e.status IN ('active', 'completed')";
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

// Check if payment is required for this course
$sql = "SELECT cf.fee, p.payment_plan_type 
        FROM courses c 
        LEFT JOIN course_fees cf ON c.id = cf.course_id 
        LEFT JOIN programs p ON c.program_id = p.id 
        WHERE c.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class['course_id']);
$stmt->execute();
$result = $stmt->get_result();
$course_fee_info = $result->fetch_assoc();
$stmt->close();

// Check payment status for this student and course
$sql = "SELECT 
            sfs.total_fee,
            sfs.paid_amount,
            sfs.balance,
            sfs.is_cleared,
            sfs.registration_paid,
            sfs.registration_paid_date,
            sfs.block1_paid,
            sfs.block1_paid_date,
            sfs.block2_paid,
            sfs.block2_paid_date,
            sfs.current_block,
            sfs.is_suspended,
            sfs.suspended_at,
            sfs.last_reminder_sent,
            sfs.next_payment_due,
            e.enrollment_date,
            cb.start_date,
            cb.program_type
        FROM student_financial_status sfs
        JOIN enrollments e ON sfs.student_id = e.student_id AND sfs.class_id = e.class_id
        JOIN class_batches cb ON e.class_id = cb.id
        WHERE sfs.student_id = ? AND sfs.class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$payment_status = $result->fetch_assoc();
$stmt->close();

// Initialize variables
$course_fee = $course_fee_info['fee'] ?? 0;
$has_paid = false;
$grace_period_expired = false;
$is_grace_period = false;
$grace_days_remaining = 0;
$payment_message = '';
$payment_summary = ''; // Short status for alert

// Check if class has started or starts within 2 days
$class_start_date = new DateTime($class['start_date']);
$current_date = new DateTime();
$two_days_before = clone $class_start_date;
$two_days_before->modify('-2 days');

// Calculate grace period for payment
$grace_end_date = clone $class_start_date;
$grace_end_date->modify('+7 days'); // One week grace period

// Flags
$is_class_date_accessible = false;
$is_payment_accessible = false;
$days_remaining = 0;

// Check date-based accessibility
if ($current_date >= $two_days_before) {
    $is_class_date_accessible = true;
} else {
    $is_class_date_accessible = false;
    $interval = $current_date->diff($two_days_before);
    $days_remaining = $interval->days;
}

// Determine payment status and set summary/message
if ($course_fee == 0) {
    // Free course
    $is_payment_accessible = true;
    $has_paid = true;
    $payment_summary = "Free Course - No payment required";
    $payment_message = "This is a free course. No payment is required for access.";
} else {
    // Paid course
    if ($payment_status && $payment_status['balance'] <= 0) {
        $is_payment_accessible = true;
        $has_paid = true;
        $payment_summary = "Payment Complete - Full access granted";
        $payment_message = "Your payment is complete. Thank you! You have full access to all course materials.";
    } else {
        // Check if in grace period
        if ($current_date <= $grace_end_date) {
            $is_payment_accessible = true; // Still in grace period
            $has_paid = false;
            $is_grace_period = true;
            $grace_interval = $current_date->diff($grace_end_date);
            $grace_days_remaining = $grace_interval->days;
            $payment_summary = "Payment pending - Grace period active";
            $payment_message = "You are in the grace period. You have {$grace_days_remaining} day(s) to make payment before access is restricted.";
        } else {
            $is_payment_accessible = false;
            $has_paid = false;
            $grace_period_expired = true;
            $payment_summary = "Payment overdue - Access restricted";
            $payment_message = "Your grace period has ended. Payment is required to access this course.";
        }
    }
}

// Final accessibility check
$is_class_accessible = $is_class_date_accessible && $is_payment_accessible;

// Only fetch additional data if class is accessible
if ($is_class_accessible) {
    // Get class statistics
    $stats = [
        'assignments' => 0,
        'materials' => 0,
        'announcements' => 0,
        'discussions' => 0,
        'classmates' => 0
    ];

    // Assignment count
    $sql = "SELECT COUNT(*) as count FROM assignments WHERE class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['assignments'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Material count
    $sql = "SELECT COUNT(*) as count FROM materials WHERE class_id = ? AND is_published = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['materials'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Announcement count
    $sql = "SELECT COUNT(*) as count FROM announcements WHERE class_id = ? AND is_published = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['announcements'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Discussion count
    $sql = "SELECT COUNT(*) as count FROM discussions WHERE class_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['discussions'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Classmate count
    $sql = "SELECT COUNT(*) as count FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            WHERE e.class_id = ? AND e.status = 'active' AND e.student_id != ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['classmates'] = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Get recent announcements
    $recent_announcements = [];
    $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as author_name
            FROM announcements a 
            JOIN users u ON a.author_id = u.id 
            WHERE a.class_id = ? AND a.is_published = 1 
            ORDER BY a.created_at DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_announcements = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get upcoming assignments
    $upcoming_assignments = [];
    $sql = "SELECT a.*, 
                   (SELECT COUNT(*) FROM assignment_submissions s 
                    WHERE s.assignment_id = a.id AND s.student_id = ?) as submission_count
            FROM assignments a 
            WHERE a.class_id = ? AND a.due_date >= NOW()
            ORDER BY a.due_date ASC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $upcoming_assignments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get recent materials
    $recent_materials = [];
    $sql = "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM materials m 
            JOIN users u ON m.instructor_id = u.id 
            WHERE m.class_id = ? AND m.is_published = 1 
            ORDER BY m.created_at DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_materials = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get my grades
    $my_grades = [];
    $sql = "SELECT a.id, a.title, a.total_points, a.due_date,
                   s.grade, s.feedback, s.status as submission_status,
                   s.submitted_at
            FROM assignments a 
            LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
            WHERE a.class_id = ? AND s.grade IS NOT NULL
            ORDER BY a.due_date DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $my_grades = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get active discussions
    $active_discussions = [];
    $sql = "SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   d.views_count, d.replies_count
            FROM discussions d 
            JOIN users u ON d.user_id = u.id 
            WHERE d.class_id = ? AND d.is_locked = 0
            ORDER BY d.last_reply_at DESC, d.created_at DESC 
            LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $active_discussions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get classmates
    $classmates = [];
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.profile_image,
                   up.current_job_title, up.current_company
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE e.class_id = ? AND e.status = 'active' AND e.student_id != ?
            ORDER BY u.first_name 
            LIMIT 6";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $classmates = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Check financial clearance
    $is_financially_cleared = $class['is_cleared'] ?? false;
    $financial_status = getStudentFinancialStatus($class_id, $student_id);

    // Log activity
    logActivity('enter_class_student', "Entered class: {$class['batch_code']}", 'class_batches', $class_id);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($class['batch_code']); ?> - Class Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            overscroll-behavior: none;
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

        /* Payment Alert - Compact */
        .payment-alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: white;
            border-radius: var(--radius);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border-left: 5px solid transparent;
            flex-wrap: wrap;
        }

        .payment-alert.success {
            border-left-color: var(--success);
            background: linear-gradient(to right, white, #f0fdf4);
        }

        .payment-alert.warning {
            border-left-color: var(--warning);
            background: linear-gradient(to right, white, #fffbeb);
        }

        .payment-alert.danger {
            border-left-color: var(--danger);
            background: linear-gradient(to right, white, #fef2f2);
        }

        .payment-alert.info {
            border-left-color: var(--info);
            background: linear-gradient(to right, white, #eff6ff);
        }

        .payment-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .payment-alert.success .payment-icon {
            background: var(--success);
            color: white;
        }

        .payment-alert.warning .payment-icon {
            background: var(--warning);
            color: white;
        }

        .payment-alert.danger .payment-icon {
            background: var(--danger);
            color: white;
        }

        .payment-alert.info .payment-icon {
            background: var(--info);
            color: white;
        }

        .payment-summary {
            flex: 1;
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .payment-summary small {
            font-weight: normal;
            color: var(--gray);
            font-size: 0.85rem;
            display: block;
            margin-top: 0.2rem;
        }

        .payment-details-btn {
            background: none;
            border: 2px solid var(--border);
            padding: 0.6rem 1.2rem;
            border-radius: 2rem;
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            min-height: 44px;
            white-space: nowrap;
        }

        .payment-details-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius);
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }

        .modal-header h2 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.3rem;
            color: var(--dark);
        }

        .modal-header h2 i {
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
            min-width: 44px;
            min-height: 44px;
        }

        .modal-close:hover {
            background: var(--light);
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Payment Grid inside Modal */
        .modal-payment-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 480px) {
            .modal-payment-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .modal-payment-item {
            background: var(--light);
            border-radius: var(--radius-sm);
            padding: 1rem;
            border-left: 4px solid var(--primary);
        }

        .modal-payment-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .modal-payment-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.2;
            word-break: break-word;
        }

        .modal-payment-value.paid {
            color: var(--success);
        }

        .modal-payment-value.pending {
            color: var(--warning);
        }

        .modal-payment-value.overdue {
            color: var(--danger);
        }

        .modal-message {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: var(--radius-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--info);
        }

        .modal-message p {
            margin-bottom: 0.75rem;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .modal-actions {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.9rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            box-shadow: var(--shadow);
            -webkit-tap-highlight-color: transparent;
            min-height: 48px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--gray);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #2a9d8f 100%);
            color: white;
        }
    </style>
    <!-- Include the full CSS from previous version here -->
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
            overscroll-behavior: none;
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

        /* Payment Status Card */
        .payment-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            border-left: 5px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .payment-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            opacity: 0.05;
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .payment-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-light);
        }

        .payment-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .payment-title i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .payment-badge {
            padding: 0.6rem 1.2rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow);
            white-space: nowrap;
        }

        .badge-paid {
            background: linear-gradient(135deg, var(--success) 0%, #2a9d8f 100%);
            color: white;
        }

        .badge-pending {
            background: linear-gradient(135deg, var(--warning) 0%, #f3722c 100%);
            color: white;
        }

        .badge-overdue {
            background: linear-gradient(135deg, var(--danger) 0%, #e63946 100%);
            color: white;
        }

        .badge-free {
            background: linear-gradient(135deg, var(--info) 0%, #457b9d 100%);
            color: white;
        }

        /* Payment Grid - Mobile First */
        .payment-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 480px) {
            .payment-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .payment-item {
            background: var(--light);
            border-radius: var(--radius-sm);
            padding: 1rem;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .payment-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .payment-label {
            font-size: 0.7rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .payment-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.2;
            word-break: break-word;
        }

        .payment-value small {
            font-size: 0.8rem;
            font-weight: normal;
            color: var(--gray);
        }

        .payment-value.paid {
            color: var(--success);
        }

        .payment-value.pending {
            color: var(--warning);
        }

        .payment-value.overdue {
            color: var(--danger);
        }

        /* Payment Message */
        .payment-message {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: var(--radius-sm);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--info);
        }

        .payment-message h3 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .payment-message p {
            color: var(--dark);
            margin-bottom: 0.75rem;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .payment-message strong {
            color: var(--primary);
        }

        /* Payment Actions */
        .payment-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .payment-actions {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.9rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            box-shadow: var(--shadow);
            -webkit-tap-highlight-color: transparent;
            min-height: 48px;
            /* Better touch target */
            width: 100%;
        }

        @media (min-width: 640px) {
            .btn {
                width: auto;
                min-width: 180px;
            }
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 2px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--gray);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #2a9d8f 100%);
            color: white;
            opacity: 0.8;
            cursor: not-allowed;
        }

        /* Countdown Styles */
        .countdown-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius);
            padding: 2rem 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            text-align: center;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .countdown-container::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.1;
        }

        .countdown-title {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .countdown-description {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .countdown-timer {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 480px) {
            .countdown-timer {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.5rem;
            }
        }

        .countdown-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.25rem;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-sm);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .countdown-value {
            font-size: 2rem;
            font-weight: 900;
            margin-bottom: 0.25rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        @media (min-width: 768px) {
            .countdown-value {
                font-size: 3rem;
            }
        }

        .countdown-label {
            font-size: 0.8rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Grace Period Info */
        .grace-info {
            background: linear-gradient(135deg, #f8961e 0%, #f3722c 100%);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin: 1.5rem auto;
            color: white;
            max-width: 800px;
            box-shadow: var(--shadow);
        }

        .grace-info h3 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .grace-info p {
            margin-bottom: 0.75rem;
            opacity: 0.9;
            line-height: 1.5;
        }

        /* Tips Grid */
        .tips-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .tips-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .tips-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .tip-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .tip-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .tip-card h3 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .tip-card p {
            color: var(--gray);
            line-height: 1.5;
            font-size: 0.95rem;
        }

        /* Main Header */
        .main-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .main-header::after {
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

        .header-content {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        @media (min-width: 768px) {
            .header-content {
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
            font-size: 1.1rem;
            opacity: 0.9;
            word-break: break-word;
        }

        .status-badge {
            padding: 0.8rem 1.5rem;
            border-radius: 2rem;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .status-badge {
                align-self: flex-start;
            }
        }

        .status-active {
            background: rgba(16, 185, 129, 0.3);
        }

        .status-completed {
            background: rgba(107, 114, 128, 0.3);
        }

        .status-upcoming {
            background: rgba(245, 158, 11, 0.3);
        }

        .status-payment {
            background: rgba(239, 68, 68, 0.3);
        }

        /* Navigation - Mobile Optimized */
        .nav-container {
            display: flex;
            gap: 0.5rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.5rem 0 1rem;
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
            padding: 0.8rem 1.2rem;
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

        .nav-disabled .nav-link {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Stats Grid */
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
            padding: 1.5rem 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card:active {
            transform: scale(0.97);
        }

        .stat-card.assignments {
            border-top-color: var(--warning);
        }

        .stat-card.materials {
            border-top-color: var(--info);
        }

        .stat-card.announcements {
            border-top-color: var(--success);
        }

        .stat-card.discussions {
            border-top-color: var(--secondary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .stat-link {
            margin-top: 0.75rem;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: var(--transition);
            padding: 0.5rem;
        }

        /* Main Content Layout */
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .main-content {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Content Cards */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-light);
        }

        @media (min-width: 640px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .card-title i {
            color: var(--primary);
        }

        /* List Items */
        .list-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.25rem;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .list-item:active {
            background: var(--light);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-content {
            flex: 1;
            min-width: 0;
            /* Prevents overflow */
        }

        .item-title {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        @media (min-width: 640px) {
            .item-title {
                flex-direction: row;
                justify-content: space-between;
                align-items: flex-start;
            }
        }

        .item-title span:first-child {
            font-weight: 600;
            color: var(--dark);
            word-break: break-word;
        }

        .item-title span:last-child {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
            background: var(--gray-light);
            padding: 0.25rem 1rem;
            border-radius: 2rem;
            display: inline-block;
            align-self: flex-start;
        }

        .item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
        }

        .item-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .item-description {
            font-size: 0.9rem;
            color: var(--gray);
            line-height: 1.5;
            word-break: break-word;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        @media (min-width: 480px) {
            .quick-actions-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 0.75rem;
            background: white;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
            border: 2px solid var(--border);
            text-align: center;
            min-height: 100px;
        }

        .action-item:active {
            background: var(--light);
            transform: scale(0.96);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 0.75rem;
        }

        .action-label {
            font-size: 0.8rem;
            font-weight: 600;
            line-height: 1.3;
        }

        /* Progress Bar */
        .progress-container {
            margin: 1rem 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .progress-bar {
            height: 10px;
            background: var(--gray-light);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 5px;
            transition: width 1s ease;
        }

        /* Classmates Grid */
        .classmates-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        @media (min-width: 480px) {
            .classmates-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 640px) {
            .classmates-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        .classmate-card {
            text-align: center;
            padding: 1rem;
            background: var(--light);
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .classmate-card:active {
            transform: scale(0.96);
        }

        .classmate-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 auto 0.75rem;
        }

        .classmate-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
            word-break: break-word;
        }

        .classmate-title {
            font-size: 0.7rem;
            color: var(--gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Financial Alerts */
        .financial-alert {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        @media (min-width: 640px) {
            .financial-alert {
                flex-direction: row;
                align-items: flex-start;
            }
        }

        .financial-alert.warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 5px solid var(--warning);
        }

        .financial-alert.danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border-left: 5px solid var(--danger);
        }

        .financial-alert.success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-left: 5px solid var(--success);
        }

        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .warning .alert-icon {
            background: var(--warning);
            color: white;
        }

        .danger .alert-icon {
            background: var(--danger);
            color: white;
        }

        .success .alert-icon {
            background: var(--success);
            color: white;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content h4 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--dark);
        }

        .alert-content p {
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .alert-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 480px) {
            .alert-actions {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1rem;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.5;
        }

        /* Class Details */
        .class-details {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        @media (min-width: 640px) {
            .details-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .detail-item {
            margin-bottom: 1rem;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
            line-height: 1.5;
            word-break: break-word;
        }

        .detail-value a {
            color: var(--primary);
            text-decoration: none;
            word-break: break-word;
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: max(1rem, env(safe-area-inset-bottom));
            left: 1rem;
            right: 1rem;
            background: white;
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 1000;
            animation: slideUp 0.3s ease;
            max-width: 400px;
            margin: 0 auto;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Loading Animation */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .loading {
            animation: pulse 2s infinite;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .class-card,
            .action-item,
            .classmate-card {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .class-card:active,
            .action-item:active {
                transform: scale(0.98);
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
            <span><?php echo htmlspecialchars($class['batch_code']); ?></span>
        </div>

        <!-- Compact Payment Alert -->
        <?php
        $alert_class = 'success';
        $icon = 'fa-check-circle';
        if ($grace_period_expired) {
            $alert_class = 'danger';
            $icon = 'fa-exclamation-triangle';
        } elseif ($is_grace_period) {
            $alert_class = 'warning';
            $icon = 'fa-clock';
        } elseif ($has_paid) {
            $alert_class = 'success';
            $icon = 'fa-check-circle';
        } else {
            $alert_class = 'info';
            $icon = 'fa-info-circle';
        }
        ?>
        <div class="payment-alert <?php echo $alert_class; ?>">
            <div class="payment-icon">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <div class="payment-summary">
                <?php echo $payment_summary; ?>
                <?php if ($is_grace_period): ?>
                    <small><?php echo $grace_days_remaining; ?> day(s) remaining in grace period</small>
                <?php elseif ($grace_period_expired): ?>
                    <small>Access restricted until payment</small>
                <?php endif; ?>
            </div>
            <button class="payment-details-btn" onclick="openPaymentModal()">
                <i class="fas fa-credit-card"></i> Details
            </button>
        </div>

        <!-- Payment Details Modal -->
        <div class="modal-overlay" id="paymentModal" onclick="if(event.target === this) closePaymentModal()">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-credit-card"></i> Payment Details</h2>
                    <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="modal-payment-grid">
                        <div class="modal-payment-item">
                            <div class="modal-payment-label">Course Fee</div>
                            <div class="modal-payment-value">₦<?php echo number_format($course_fee, 2); ?></div>
                        </div>
                        <div class="modal-payment-item">
                            <div class="modal-payment-label">Amount Paid</div>
                            <div class="modal-payment-value paid">₦<?php echo number_format($payment_status['paid_amount'] ?? 0, 2); ?></div>
                        </div>
                        <div class="modal-payment-item">
                            <div class="modal-payment-label">Balance</div>
                            <div class="modal-payment-value <?php echo ($payment_status['balance'] ?? $course_fee) > 0 ? 'pending' : 'paid'; ?>">
                                ₦<?php echo number_format($payment_status['balance'] ?? $course_fee, 2); ?>
                            </div>
                        </div>
                        <div class="modal-payment-item">
                            <div class="modal-payment-label">Deadline</div>
                            <div class="modal-payment-value">
                                <?php echo $grace_end_date->format('M j, Y'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="modal-message">
                        <p><?php echo $payment_message; ?></p>
                        <?php if ($is_grace_period): ?>
                            <p><strong>Grace Period:</strong> You have <?php echo $grace_days_remaining; ?> day(s) remaining to make payment.</p>
                        <?php elseif ($grace_period_expired): ?>
                            <p><strong>Access Restricted:</strong> Please complete payment to regain access.</p>
                        <?php endif; ?>
                    </div>

                    <div class="modal-actions">
                        <?php if (!$has_paid && $course_fee > 0): ?>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Make Payment Now
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-file-invoice"></i> View Invoices
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payment_history.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-history"></i> Payment History
                        </a>
                        <?php if ($has_paid): ?>
                            <button class="btn btn-success" disabled>
                                <i class="fas fa-check-circle"></i> Payment Complete
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$is_class_accessible): ?>
            <?php if (!$is_class_date_accessible): ?>
                <!-- Countdown Section (same as before) -->
                <div class="countdown-container">
                    <h1 class="countdown-title">Starts Soon!</h1>
                    <p class="countdown-description">
                        <?php echo date('M j', strtotime($class['start_date'])); ?>
                    </p>
                    <div class="countdown-timer">
                        <div class="countdown-item">
                            <div class="countdown-value" id="days"><?php echo $days_remaining; ?></div>
                            <div class="countdown-label">Days</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-value" id="hours">00</div>
                            <div class="countdown-label">Hours</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-value" id="minutes">00</div>
                            <div class="countdown-label">Mins</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-value" id="seconds">00</div>
                            <div class="countdown-label">Secs</div>
                        </div>
                    </div>
                </div>
            <?php elseif ($is_class_date_accessible && !$is_payment_accessible): ?>
                <!-- Payment Required Section (same as before) -->
                <div class="countdown-container" style="background: linear-gradient(135deg, #f94144 0%, #e63946 100%);">
                    <h1 class="countdown-title">
                        <i class="fas fa-exclamation-triangle"></i> Payment Required
                    </h1>
                    <?php if ($grace_period_expired): ?>
                        <p class="countdown-description">Access restricted - payment overdue</p>
                    <?php else: ?>
                        <p class="countdown-description"><?php echo $grace_days_remaining; ?> days left in grace period</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Class Header (simplified for non-accessible) -->
            <div class="main-header" style="background: linear-gradient(135deg, var(--gray) 0%, #4a5568 100%);">
                <div class="header-content">
                    <div class="class-info">
                        <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                        <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                    </div>
                    <span class="status-badge status-upcoming">
                        <i class="fas fa-lock"></i> Locked
                    </span>
                </div>
                <!-- Minimal navigation -->
                <div class="nav-container nav-disabled">
                    <a href="#" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
                    <a href="#" class="nav-link"><i class="fas fa-book"></i><span>Materials</span></a>
                    <a href="#" class="nav-link"><i class="fas fa-tasks"></i><span>Tasks</span></a>
                </div>
            </div>

            <!-- Class Details (always visible) -->
            <div class="class-details">
                <h2 class="card-title"><i class="fas fa-info-circle"></i> Class Information</h2>
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Instructor</div>
                        <div class="detail-value"><?php echo htmlspecialchars($class['instructor_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Program</div>
                        <div class="detail-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Start Date</div>
                        <div class="detail-value"><?php echo date('M j, Y', strtotime($class['start_date'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Course Code</div>
                        <div class="detail-value"><?php echo htmlspecialchars($class['course_code']); ?></div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Regular Class Dashboard (same as previous version) -->
            <!-- Financial Alerts (if any, but now compact) -->
            <?php if ($is_grace_period): ?>
                <div class="financial-alert warning">
                    <div class="alert-icon"><i class="fas fa-clock"></i></div>
                    <div class="alert-content">
                        <h4>Grace Period Active</h4>
                        <p><?php echo $grace_days_remaining; ?> day(s) remaining to make payment.</p>
                        <div class="alert-actions">
                            <button onclick="openPaymentModal()" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> View Details
                            </button>
                        </div>
                    </div>
                </div>
            <?php elseif ($class['is_suspended']): ?>
                <div class="financial-alert danger">
                    <div class="alert-icon"><i class="fas fa-ban"></i></div>
                    <div class="alert-content">
                        <h4>Account Suspended</h4>
                        <p>Please clear outstanding balance.</p>
                        <div class="alert-actions">
                            <button onclick="openPaymentModal()" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Resolve
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Header -->
            <div class="main-header">
                <div class="header-content">
                    <div class="class-info">
                        <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                        <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                    </div>
                    <span class="status-badge status-<?php echo $class['enrollment_status']; ?>">
                        <?php echo ucfirst($class['enrollment_status']); ?>
                    </span>
                </div>

                <!-- Navigation (same as previous) -->
                <div class="nav-container">
                    <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link active">
                        <i class="fas fa-home"></i><span>Home</span>
                    </a>
                    <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-book"></i><span>Materials</span>
                    </a>
                    <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-tasks"></i><span>Tasks</span>
                    </a>
                    <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-question-circle"></i><span>Quizzes</span>
                    </a>
                    <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-chart-line"></i><span>Grades</span>
                    </a>
                    <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-comments"></i><span>Discuss</span>
                    </a>
                    <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-users"></i><span>Classmates</span>
                    </a>
                    <?php if (!empty($class['meeting_link'])): ?>
                        <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                            <i class="fas fa-video"></i><span>Join</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Grid (same as previous) -->
            <div class="stats-grid">
                <div class="stat-card assignments" onclick="window.location.href='assignments.php?class_id=<?php echo $class_id; ?>'">
                    <div class="stat-value"><?php echo $stats['assignments']; ?></div>
                    <div class="stat-label">Tasks</div>
                </div>
                <div class="stat-card materials" onclick="window.location.href='materials/view.php?class_id=<?php echo $class_id; ?>'">
                    <div class="stat-value"><?php echo $stats['materials']; ?></div>
                    <div class="stat-label">Materials</div>
                </div>
                <div class="stat-card announcements" onclick="window.location.href='announcements.php?class_id=<?php echo $class_id; ?>'">
                    <div class="stat-value"><?php echo $stats['announcements']; ?></div>
                    <div class="stat-label">Updates</div>
                </div>
                <div class="stat-card discussions" onclick="window.location.href='discussions/index.php?class_id=<?php echo $class_id; ?>'">
                    <div class="stat-value"><?php echo $stats['discussions']; ?></div>
                    <div class="stat-label">Discussions</div>
                </div>
            </div>

            <!-- Main Content (same as previous) -->
            <div class="main-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Upcoming Assignments -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Upcoming</h2>
                            <a href="assignments/index.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-list"></i> All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_assignments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-tasks"></i>
                                    <p>No upcoming tasks</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming_assignments as $assignment): ?>
                                    <div class="list-item" onclick="window.location.href='assignments/view.php?id=<?php echo $assignment['id']; ?>'">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($assignment['title']); ?></span>
                                                <span><?php echo $assignment['total_points']; ?> pts</span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-clock"></i> <?php echo date('M d', strtotime($assignment['due_date'])); ?></span>
                                                <span>
                                                    <?php if ($assignment['submission_count'] > 0): ?>
                                                        <i class="fas fa-check-circle" style="color: var(--success);"></i> Done
                                                    <?php else: ?>
                                                        <i class="fas fa-hourglass-half" style="color: var(--warning);"></i> Pending
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Materials -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-book"></i> New Materials</h2>
                            <a href="materials/view.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_materials)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-book"></i>
                                    <p>No materials yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_materials as $material): ?>
                                    <div class="list-item" onclick="window.location.href='materials/view.php?id=<?php echo $material['id']; ?>'">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($material['title']); ?></span>
                                                <span style="text-transform: capitalize;"><?php echo $material['file_type']; ?></span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($material['instructor_name']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M d', strtotime($material['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Quick Actions -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bolt"></i> Quick</h2>
                        </div>
                        <div class="quick-actions-grid">
                            <a href="assignments/index.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon"><i class="fas fa-tasks"></i></div>
                                <div class="action-label">Tasks</div>
                            </a>
                            <a href="discussions/index.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon"><i class="fas fa-comments"></i></div>
                                <div class="action-label">Discuss</div>
                            </a>
                            <a href="materials/view.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon"><i class="fas fa-book-open"></i></div>
                                <div class="action-label">Materials</div>
                            </a>
                            <?php if (!empty($class['meeting_link'])): ?>
                                <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="action-item">
                                    <div class="action-icon"><i class="fas fa-video"></i></div>
                                    <div class="action-label">Join Live</div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Announcements -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bullhorn"></i> Announcements</h2>
                            <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-list"></i> All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_announcements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bullhorn"></i>
                                    <p>No announcements</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_announcements as $announcement): ?>
                                    <div class="list-item" onclick="window.location.href='announcements/view.php?id=<?php echo $announcement['id']; ?>'">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($announcement['title']); ?></span>
                                                <span><?php echo date('M d', strtotime($announcement['created_at'])); ?></span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Classmates -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-users"></i> Classmates (<?php echo $stats['classmates']; ?>)</h2>
                            <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-users"></i> All
                            </a>
                        </div>
                        <div class="classmates-grid">
                            <?php if (empty($classmates)): ?>
                                <div class="empty-state">
                                    <p>No classmates yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($classmates as $classmate): ?>
                                    <div class="classmate-card" onclick="window.location.href='profile.php?id=<?php echo $classmate['id']; ?>'">
                                        <div class="classmate-avatar">
                                            <?php echo strtoupper(substr($classmate['first_name'], 0, 1)); ?>
                                        </div>
                                        <div class="classmate-name">
                                            <?php echo htmlspecialchars($classmate['first_name']); ?>
                                        </div>
                                        <?php if (!empty($classmate['current_job_title'])): ?>
                                            <div class="classmate-title">
                                                <?php echo htmlspecialchars($classmate['current_job_title']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Modal functions
        function openPaymentModal() {
            document.getElementById('paymentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentModal();
            }
        });

        <?php if (!$is_class_accessible && !$is_class_date_accessible): ?>
            // Countdown Timer (same as before)
            function updateCountdown() {
                const startDate = new Date('<?php echo $class['start_date']; ?>');
                const twoDaysBefore = new Date(startDate);
                twoDaysBefore.setDate(startDate.getDate() - 2);

                const now = new Date();
                const timeDiff = twoDaysBefore - now;

                if (timeDiff <= 0) {
                    location.reload();
                    return;
                }

                const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);

                document.getElementById('days').textContent = days;
                document.getElementById('hours').textContent = hours.toString().padStart(2, '0');
                document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
                document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
            }

            updateCountdown();
            setInterval(updateCountdown, 1000);
        <?php endif; ?>

        // Auto-refresh when countdown reaches zero
        setInterval(() => {
            <?php if (!$is_class_date_accessible): ?>
                const startDate = new Date('<?php echo $class['start_date']; ?>');
                const twoDaysBefore = new Date(startDate);
                twoDaysBefore.setDate(startDate.getDate() - 2);

                if (new Date() >= twoDaysBefore) {
                    location.reload();
                }
            <?php endif; ?>
        }, 30000);

        // Payment status check (optional, can be kept)
        setInterval(() => {
            fetch('<?php echo BASE_URL; ?>modules/student/finance/check_payment_status.php?class_id=<?php echo $class_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.paid) {
                        // Optionally show a notification and reload
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(() => {});
        }, 30000);

        // Touch-friendly enhancements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .stat-card, .list-item, .action-item, .classmate-card').forEach(el => {
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