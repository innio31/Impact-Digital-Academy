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

// Check payment-based accessibility
if ($course_fee > 0) {
    if ($payment_status && $payment_status['balance'] <= 0) {
        $is_payment_accessible = true;
        $has_paid = true;
        $payment_message = "Payment Status: <strong>Paid</strong> - You have full access to this course.";
    } else {
        // Check if in grace period
        if ($current_date <= $grace_end_date) {
            $is_payment_accessible = true; // Still in grace period
            $has_paid = false;
            $is_grace_period = true;
            $grace_interval = $current_date->diff($grace_end_date);
            $grace_days_remaining = $grace_interval->days;
            $payment_message = "Payment Status: <strong>Pending</strong> - You are in the grace period. You have {$grace_days_remaining} day(s) to make payment.";
        } else {
            $is_payment_accessible = false;
            $has_paid = false;
            $grace_period_expired = true;
            $payment_message = "Payment Status: <strong>Overdue</strong> - Grace period has ended. Payment required to access this course.";
        }
    }
} else {
    // Free course - no payment check needed
    $is_payment_accessible = true;
    $has_paid = true;
    $payment_message = "Payment Status: <strong>Free Course</strong> - No payment required.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            font-size: 14px;
            color: var(--gray);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            padding: 8px 12px;
            border-radius: var(--radius-sm);
        }

        .breadcrumb a:hover {
            background: var(--gray-light);
            color: var(--primary-dark);
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Payment Status Card */
        .payment-card {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            margin-bottom: 30px;
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
            transform: translate(50%, -50%);
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-light);
        }

        .payment-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
        }

        .payment-title i {
            color: var(--primary);
            font-size: 28px;
        }

        .payment-badge {
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow);
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

        /* Payment Grid */
        .payment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .payment-item {
            background: var(--light);
            border-radius: var(--radius-sm);
            padding: 20px;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .payment-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .payment-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .payment-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.2;
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
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--info);
        }

        .payment-message h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark);
            margin-bottom: 12px;
            font-size: 18px;
        }

        .payment-message h3 i {
            color: var(--info);
        }

        .payment-message p {
            color: var(--dark);
            margin-bottom: 8px;
            line-height: 1.6;
        }

        .payment-message strong {
            color: var(--primary);
        }

        /* Payment Actions */
        .payment-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 16px;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
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
            transform: translateY(-3px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #2a9d8f 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(76, 201, 240, 0.3);
        }

        /* Countdown Styles */
        .countdown-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius);
            padding: 40px;
            margin-bottom: 30px;
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
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .countdown-description {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 30px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .countdown-timer {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .countdown-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 25px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-sm);
            min-width: 120px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .countdown-value {
            font-size: 48px;
            font-weight: 900;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .countdown-label {
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Grace Period Info */
        .grace-info {
            background: linear-gradient(135deg, #f8961e 0%, #f3722c 100%);
            border-radius: var(--radius-sm);
            padding: 25px;
            margin: 30px auto;
            color: white;
            max-width: 800px;
            box-shadow: var(--shadow);
        }

        .grace-info h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 20px;
        }

        .grace-info p {
            margin-bottom: 10px;
            opacity: 0.9;
        }

        /* Tips Grid */
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .tip-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 25px;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
            transition: var(--transition);
        }

        .tip-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .tip-card h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .tip-card h3 i {
            color: var(--primary);
        }

        .tip-card p {
            color: var(--gray);
            line-height: 1.6;
        }

        /* Main Header */
        .main-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--radius);
            padding: 30px;
            margin-bottom: 30px;
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
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 20px;
        }

        .class-info h1 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .class-info p {
            font-size: 18px;
            opacity: 0.9;
        }

        .status-badge {
            padding: 12px 24px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        /* Navigation */
        .nav-container {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 25px;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: white;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-3px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary);
            border-color: white;
        }

        .nav-disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 25px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
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
            font-size: 42px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 10px;
            line-height: 1;
        }

        .stat-label {
            font-size: 14px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .stat-link {
            margin-top: 15px;
        }

        .stat-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .stat-link a:hover {
            color: var(--primary-dark);
            gap: 10px;
        }

        /* Main Content Layout */
        .main-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        @media (max-width: 992px) {
            .main-content {
                grid-template-columns: 1fr;
            }
        }

        /* Content Cards */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-light);
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
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
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .list-item:hover {
            background: var(--light);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-content {
            flex: 1;
        }

        .item-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .item-title span:first-child {
            font-weight: 600;
            color: var(--dark);
            flex: 1;
        }

        .item-title span:last-child {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            background: var(--gray-light);
            padding: 4px 12px;
            border-radius: 12px;
        }

        .item-meta {
            display: flex;
            gap: 20px;
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 10px;
        }

        .item-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .item-description {
            font-size: 14px;
            color: var(--gray);
            line-height: 1.5;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: white;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
            border: 2px solid var(--border);
            text-align: center;
        }

        .action-item:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 15px;
        }

        .action-label {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        /* Progress Bar */
        .progress-container {
            margin: 20px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
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
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .classmate-card {
            text-align: center;
            padding: 15px;
            background: var(--light);
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .classmate-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .classmate-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            margin: 0 auto 10px;
        }

        .classmate-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .classmate-title {
            font-size: 12px;
            color: var(--gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Financial Alerts */
        .financial-alert {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            padding: 25px;
            border-radius: var(--radius-sm);
            margin-bottom: 30px;
            box-shadow: var(--shadow);
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
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
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
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .alert-content p {
            color: var(--dark);
            margin-bottom: 15px;
        }

        .alert-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Class Details */
        .class-details {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .detail-item {
            margin-bottom: 20px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .detail-value {
            font-size: 16px;
            color: var(--dark);
            font-weight: 500;
            line-height: 1.5;
        }

        .detail-value a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .detail-value a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: white;
            padding: 20px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            max-width: 400px;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .payment-grid {
                grid-template-columns: 1fr;
            }

            .countdown-timer {
                gap: 10px;
            }

            .countdown-item {
                min-width: 80px;
                padding: 15px;
            }

            .countdown-value {
                font-size: 32px;
            }

            .payment-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .main-header {
                padding: 20px;
            }

            .class-info h1 {
                font-size: 28px;
            }

            .nav-container {
                justify-content: center;
            }

            .nav-link {
                padding: 12px 20px;
                font-size: 14px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .main-content {
                gap: 20px;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .classmates-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .countdown-timer {
                flex-direction: column;
                align-items: center;
            }

            .countdown-item {
                width: 100%;
                max-width: 200px;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            .classmates-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .payment-title {
                font-size: 20px;
            }

            .payment-badge {
                padding: 8px 16px;
                font-size: 12px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
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

        /* Focus Styles */
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

        /* Selection */
        ::selection {
            background: rgba(67, 97, 238, 0.3);
            color: var(--dark);
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
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <span><?php echo htmlspecialchars($class['batch_code']); ?></span>
        </div>

        <!-- Payment Status Card -->
        <div class="payment-card">
            <div class="payment-header">
                <h2 class="payment-title">
                    <i class="fas fa-credit-card"></i> Course Payment Status
                </h2>
                <span class="payment-badge 
                    <?php
                    if ($has_paid) echo 'badge-paid';
                    elseif ($grace_period_expired) echo 'badge-overdue';
                    elseif ($is_grace_period) echo 'badge-pending';
                    else echo 'badge-free';
                    ?>">
                    <?php
                    if ($has_paid) echo 'Paid';
                    elseif ($grace_period_expired) echo 'Overdue';
                    elseif ($is_grace_period) echo 'Pending';
                    else echo 'Free';
                    ?>
                </span>
            </div>

            <div class="payment-grid">
                <div class="payment-item">
                    <div class="payment-label">Course Fee</div>
                    <div class="payment-value">₦<?php echo number_format($course_fee, 2); ?></div>
                </div>

                <div class="payment-item">
                    <div class="payment-label">Amount Paid</div>
                    <div class="payment-value paid">₦<?php echo number_format($payment_status['paid_amount'] ?? 0, 2); ?></div>
                </div>

                <div class="payment-item">
                    <div class="payment-label">Balance Due</div>
                    <div class="payment-value 
                        <?php
                        if ($has_paid) echo 'paid';
                        elseif ($grace_period_expired) echo 'overdue';
                        else echo 'pending';
                        ?>">
                        ₦<?php echo number_format($payment_status['balance'] ?? $course_fee, 2); ?>
                    </div>
                </div>

                <div class="payment-item">
                    <div class="payment-label">Payment Deadline</div>
                    <div class="payment-value">
                        <?php echo $grace_end_date->format('F j, Y'); ?>
                        <?php if ($is_grace_period): ?>
                            <br><small>(<?php echo $grace_days_remaining; ?> day(s) remaining)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="payment-message">
                <h3><i class="fas fa-info-circle"></i> Important Information</h3>
                <p><?php echo $payment_message; ?></p>

                <?php if ($is_grace_period): ?>
                    <p><strong>Grace Period:</strong> You have a 7-day grace period from the class start date to make payment. During this period, you can access the course materials.</p>
                    <p><strong>After Grace Period:</strong> If payment is not made within the grace period, your access to course materials will be restricted until payment is completed.</p>
                <?php elseif ($grace_period_expired): ?>
                    <p><strong>Important:</strong> Your grace period has ended. You must make payment immediately to regain access to this course.</p>
                <?php endif; ?>
            </div>

            <div class="payment-actions">
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

        <?php if (!$is_class_accessible): ?>
            <?php if (!$is_class_date_accessible): ?>
                <!-- Countdown Section -->
                <div class="countdown-container">
                    <h1 class="countdown-title">Class Starts Soon!</h1>
                    <p class="countdown-description">
                        Your class will begin on <?php echo date('F j, Y', strtotime($class['start_date'])); ?>.
                        Access will be granted 2 days before the start date.
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
                            <div class="countdown-label">Minutes</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-value" id="seconds">00</div>
                            <div class="countdown-label">Seconds</div>
                        </div>
                    </div>

                    <?php if (!$has_paid && $course_fee > 0): ?>
                        <div class="grace-info">
                            <h3><i class="fas fa-clock"></i> Payment Reminder</h3>
                            <p>While waiting for the class to start, you can make your payment now to secure your spot.</p>
                            <p>The grace period will begin when the class starts on <?php echo date('F j, Y', strtotime($class['start_date'])); ?>.</p>
                        </div>
                    <?php endif; ?>

                    <p class="countdown-description">
                        Please use this time to prepare for your class. The course materials will be available once the countdown reaches zero.
                    </p>
                </div>

                <!-- Preparation Tips -->
                <div class="content-card">
                    <h2 class="card-title"><i class="fas fa-lightbulb"></i> Preparation Tips</h2>
                    <div class="tips-grid">
                        <div class="tip-card">
                            <h3><i class="fas fa-book"></i> Review Prerequisites</h3>
                            <p>Make sure you have the necessary background knowledge for this course. Review any prerequisite materials provided.</p>
                        </div>
                        <div class="tip-card">
                            <h3><i class="fas fa-desktop"></i> Check Your Setup</h3>
                            <p>Test your computer, internet connection, and any required software to ensure you're ready for the first session.</p>
                        </div>
                        <div class="tip-card">
                            <h3><i class="fas fa-calendar-alt"></i> Plan Your Schedule</h3>
                            <p>Block out time in your calendar for classes, study sessions, and assignment work.</p>
                        </div>
                        <div class="tip-card">
                            <h3><i class="fas fa-question-circle"></i> Prepare Questions</h3>
                            <p>Think about what you want to learn and prepare questions for your instructor.</p>
                        </div>
                    </div>
                </div>

                <!-- Class Header -->
                <div class="main-header">
                    <div class="header-content">
                        <div class="class-info">
                            <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                            <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                        </div>
                        <span class="status-badge status-upcoming">
                            <i class="fas fa-clock"></i> Starts in <?php echo $days_remaining; ?> days
                        </span>
                    </div>

                    <!-- Navigation -->
                    <div class="nav-container nav-disabled">
                        <a href="#" class="nav-link">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-book"></i> Materials
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-tasks"></i> Assignments
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-comments"></i> Discussions
                        </a>
                        <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-users"></i> Classmates
                        </a>
                    </div>
                </div>

                <!-- Class Details -->
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
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($class['start_date'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">End Date</div>
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($class['end_date'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Schedule</div>
                            <div class="detail-value">
                                <?php
                                if (!empty($class['schedule'])) {
                                    echo htmlspecialchars($class['schedule']);
                                } else {
                                    echo 'To be announced';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Course Code</div>
                            <div class="detail-value"><?php echo htmlspecialchars($class['course_code']); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($class['course_description'])): ?>
                        <div class="detail-item" style="margin-top: 30px;">
                            <div class="detail-label">Course Description</div>
                            <div class="detail-value" style="line-height: 1.8; padding: 15px; background: var(--light); border-radius: var(--radius-sm);">
                                <?php echo nl2br(htmlspecialchars($class['course_description'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($is_class_date_accessible && !$is_payment_accessible): ?>
                <!-- Payment Required Section -->
                <div class="countdown-container" style="background: linear-gradient(135deg, #f94144 0%, #e63946 100%);">
                    <h1 class="countdown-title">
                        <i class="fas fa-exclamation-triangle"></i> Payment Required
                    </h1>

                    <?php if ($grace_period_expired): ?>
                        <p class="countdown-description">
                            Your grace period has ended. You must make payment to continue accessing this course.
                        </p>
                    <?php else: ?>
                        <p class="countdown-description">
                            You are in the grace period. You have <?php echo $grace_days_remaining; ?> day(s) to make payment before access is restricted.
                        </p>
                    <?php endif; ?>

                    <?php if (!$grace_period_expired): ?>
                        <div class="countdown-timer">
                            <div class="countdown-item">
                                <div class="countdown-value" id="grace-days"><?php echo $grace_days_remaining; ?></div>
                                <div class="countdown-label">Days Remaining</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Information -->
                <div class="payment-card">
                    <div class="payment-grid">
                        <div class="payment-item">
                            <div class="payment-label">Course Fee</div>
                            <div class="payment-value">₦<?php echo number_format($course_fee, 2); ?></div>
                        </div>
                        <div class="payment-item">
                            <div class="payment-label">Amount Paid</div>
                            <div class="payment-value paid">₦<?php echo number_format($payment_status['paid_amount'] ?? 0, 2); ?></div>
                        </div>
                        <div class="payment-item">
                            <div class="payment-label">Balance Due</div>
                            <div class="payment-value overdue">₦<?php echo number_format($payment_status['balance'] ?? $course_fee, 2); ?></div>
                        </div>
                        <div class="payment-item">
                            <div class="payment-label">Payment Status</div>
                            <div class="payment-value <?php echo $grace_period_expired ? 'overdue' : 'pending'; ?>">
                                <?php echo $grace_period_expired ? 'Overdue' : 'Grace Period'; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$grace_period_expired): ?>
                        <div class="payment-message">
                            <h3><i class="fas fa-clock"></i> Payment Deadline</h3>
                            <p>Payment due by: <?php echo $grace_end_date->format('F j, Y'); ?></p>
                            <p>You have <?php echo $grace_days_remaining; ?> day(s) to complete your payment.</p>
                        </div>
                    <?php endif; ?>

                    <div class="payment-actions">
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-credit-card"></i> Make Payment Now
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-file-invoice"></i> View Invoices
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payment_history.php" class="btn btn-secondary">
                            <i class="fas fa-history"></i> Payment History
                        </a>
                    </div>
                </div>

                <!-- Class Header -->
                <div class="main-header" style="background: linear-gradient(135deg, #f94144 0%, #e63946 100%);">
                    <div class="header-content">
                        <div class="class-info">
                            <h1><?php echo htmlspecialchars($class['batch_code']); ?></h1>
                            <p><?php echo htmlspecialchars($class['course_title']); ?></p>
                        </div>
                        <span class="status-badge status-payment">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php if ($grace_period_expired): ?>
                                Payment Required
                            <?php else: ?>
                                Grace Period: <?php echo $grace_days_remaining; ?> day(s)
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Navigation -->
                    <div class="nav-container nav-disabled">
                        <a href="#" class="nav-link">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-book"></i> Materials
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-tasks"></i> Assignments
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-comments"></i> Discussions
                        </a>
                        <a href="#" class="nav-link">
                            <i class="fas fa-users"></i> Classmates
                        </a>
                    </div>
                </div>

                <!-- Class Details -->
                <div class="class-details">
                    <h2 class="card-title"><i class="fas fa-info-circle"></i> Course Information</h2>
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
                            <div class="detail-value"><?php echo date('F j, Y', strtotime($class['start_date'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Course Fee</div>
                            <div class="detail-value">₦<?php echo number_format($course_fee, 2); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Current Status</div>
                            <div class="detail-value">
                                <?php if ($grace_period_expired): ?>
                                    <span style="color: var(--danger); font-weight: bold;">
                                        <i class="fas fa-ban"></i> Access Restricted
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--warning); font-weight: bold;">
                                        <i class="fas fa-clock"></i> Grace Period Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Payment Deadline</div>
                            <div class="detail-value">
                                <?php echo $grace_end_date->format('F j, Y'); ?>
                                <?php if (!$grace_period_expired): ?>
                                    <br><small style="color: var(--warning);">(<?php echo $grace_days_remaining; ?> day(s) remaining)</small>
                                <?php else: ?>
                                    <br><small style="color: var(--danger);">(Deadline passed)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-item" style="margin-top: 30px;">
                        <div class="detail-label">Important Notice</div>
                        <div class="detail-value" style="padding: 20px; background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); border-radius: var(--radius-sm); border-left: 5px solid var(--warning);">
                            <i class="fas fa-exclamation-circle" style="color: var(--warning); margin-right: 10px;"></i>
                            <?php if ($grace_period_expired): ?>
                                <strong>Your access to course materials has been restricted.</strong> Please complete your payment to regain access to all course features, including materials, assignments, and discussions.
                            <?php else: ?>
                                <strong>This is a paid course.</strong> You are currently in the grace period. Full access will be granted once payment is completed. Please make payment before the deadline to avoid access restrictions.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        <?php else: ?>
            <!-- Regular Class Dashboard -->

            <!-- Financial Alerts -->
            <?php if (!$has_paid && $is_grace_period): ?>
                <div class="financial-alert warning">
                    <div class="alert-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="alert-content">
                        <h4>Grace Period Active</h4>
                        <p>You have <?php echo $grace_days_remaining; ?> day(s) remaining to make payment for this course.</p>
                        <div class="alert-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Pay Now
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-file-invoice"></i> View Invoice
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif ($class['is_suspended']): ?>
                <div class="financial-alert danger">
                    <div class="alert-icon">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="alert-content">
                        <h4>Account Suspended</h4>
                        <p>Your access to this class has been suspended due to outstanding payments. Please clear your balance to regain access.</p>
                        <div class="alert-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Make Payment
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-file-invoice"></i> View Invoices
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif ($class['balance'] > 0 && !$class['is_cleared']): ?>
                <div class="financial-alert warning">
                    <div class="alert-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="alert-content">
                        <h4>Outstanding Balance: ₦<?php echo number_format($class['balance'], 2); ?></h4>
                        <p>Next payment due: <?php echo date('M d, Y', strtotime($class['next_payment_due'])); ?></p>
                        <div class="alert-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Pay Now
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-file-invoice"></i> View Invoices
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif ($class['is_cleared'] || $has_paid): ?>
                <div class="financial-alert success">
                    <div class="alert-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="alert-content">
                        <h4>Payment Status: <?php echo $has_paid ? 'Paid' : 'Financially Cleared'; ?></h4>
                        <p>All fees for this class have been paid. You have full access to all course materials.</p>
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
                        <?php if ($class['final_grade']): ?>
                            | Grade: <?php echo $class['final_grade']; ?>
                        <?php endif; ?>
                        <?php if (!$has_paid && $is_grace_period): ?>
                            | Grace: <?php echo $grace_days_remaining; ?> days
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Navigation -->
                <div class="nav-container">
                    <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link active">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-book"></i> Materials
                    </a>
                    <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-tasks"></i> Assignments
                    </a>
                    <a href="quizzes/quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-question-circle"></i> Quizzes
                    </a>
                    <a href="grades.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-chart-line"></i> Grades
                    </a>
                    <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-comments"></i> Discussions
                    </a>
                    <a href="classmates.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                        <i class="fas fa-users"></i> Classmates
                    </a>
                    <?php if (!empty($class['meeting_link'])): ?>
                        <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="nav-link">
                            <i class="fas fa-video"></i> Join Class
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card assignments">
                    <div class="stat-value"><?php echo $stats['assignments']; ?></div>
                    <div class="stat-label">Assignments</div>
                    <div class="stat-link">
                        <a href="assignments.php?class_id=<?php echo $class_id; ?>">
                            View Assignments <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <div class="stat-card materials">
                    <div class="stat-value"><?php echo $stats['materials']; ?></div>
                    <div class="stat-label">Materials</div>
                    <div class="stat-link">
                        <a href="materials/view.php?class_id=<?php echo $class_id; ?>">
                            View Materials <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <div class="stat-card announcements">
                    <div class="stat-value"><?php echo $stats['announcements']; ?></div>
                    <div class="stat-label">Announcements</div>
                    <div class="stat-link">
                        <a href="<?php echo BASE_URL; ?>modules/student/classes/announcements.php?class_id=<?php echo $class_id; ?>">
                            View Announcements <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <div class="stat-card discussions">
                    <div class="stat-value"><?php echo $stats['discussions']; ?></div>
                    <div class="stat-label">Discussions</div>
                    <div class="stat-link">
                        <a href="discussions/index.php?class_id=<?php echo $class_id; ?>">
                            Join Discussions <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Upcoming Assignments -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Upcoming Assignments</h2>
                            <a href="assignments/index.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-list"></i> View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming_assignments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-tasks"></i>
                                    <p>No upcoming assignments</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming_assignments as $assignment): ?>
                                    <div class="list-item">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($assignment['title']); ?></span>
                                                <span><?php echo $assignment['total_points']; ?> pts</span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-clock"></i> Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?></span>
                                                <span>
                                                    <?php if ($assignment['submission_count'] > 0): ?>
                                                        <i class="fas fa-check-circle" style="color: var(--success);"></i> Submitted
                                                    <?php else: ?>
                                                        <i class="fas fa-clock" style="color: var(--warning);"></i> Not Submitted
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if ($assignment['description']): ?>
                                                <div class="item-description">
                                                    <?php echo htmlspecialchars(substr($assignment['description'], 0, 100)); ?>
                                                    <?php if (strlen($assignment['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Materials -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-book"></i> Recent Materials</h2>
                            <a href="materials/view.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_materials)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-book"></i>
                                    <p>No materials available yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_materials as $material): ?>
                                    <div class="list-item">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($material['title']); ?></span>
                                                <span style="text-transform: capitalize;"><?php echo $material['file_type']; ?></span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($material['instructor_name']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M d', strtotime($material['created_at'])); ?></span>
                                            </div>
                                            <?php if ($material['description']): ?>
                                                <div class="item-description">
                                                    <?php echo htmlspecialchars(substr($material['description'], 0, 100)); ?>
                                                    <?php if (strlen($material['description']) > 100): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- My Grades -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-chart-line"></i> My Recent Grades</h2>
                            <a href="grades/index.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-chart-bar"></i> View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($my_grades)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-line"></i>
                                    <p>No grades available yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($my_grades as $grade): ?>
                                    <div class="list-item">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($grade['title']); ?></span>
                                                <span class="payment-badge" style="background: var(--success); padding: 4px 12px; font-size: 12px;">
                                                    <?php echo $grade['grade']; ?>/<?php echo $grade['total_points']; ?>
                                                </span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('M d, Y', strtotime($grade['due_date'])); ?></span>
                                                <span><i class="fas fa-check-circle"></i> Submitted: <?php echo date('M d', strtotime($grade['submitted_at'])); ?></span>
                                            </div>
                                            <?php if ($grade['feedback']): ?>
                                                <div class="item-description">
                                                    <strong>Feedback:</strong> <?php echo htmlspecialchars(substr($grade['feedback'], 0, 150)); ?>
                                                    <?php if (strlen($grade['feedback']) > 150): ?>...<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
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
                            <h2 class="card-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                        </div>
                        <div class="quick-actions-grid">
                            <a href="assignments/index.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <div class="action-label">View Assignments</div>
                            </a>
                            <a href="discussions/index.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon">
                                    <i class="fas fa-comments"></i>
                                </div>
                                <div class="action-label">Join Discussion</div>
                            </a>
                            <a href="materials/view.php?class_id=<?php echo $class_id; ?>" class="action-item">
                                <div class="action-icon">
                                    <i class="fas fa-book-open"></i>
                                </div>
                                <div class="action-label">Study Materials</div>
                            </a>
                            <?php if (!empty($class['meeting_link'])): ?>
                                <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="action-item">
                                    <div class="action-icon">
                                        <i class="fas fa-video"></i>
                                    </div>
                                    <div class="action-label">Join Live Class</div>
                                </a>
                            <?php endif; ?>
                            <?php if (!$has_paid || $class['balance'] > 0): ?>
                                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?class_id=<?php echo $class_id; ?>&course_id=<?php echo $class['course_id']; ?>" class="action-item">
                                    <div class="action-icon">
                                        <i class="fas fa-credit-card"></i>
                                    </div>
                                    <div class="action-label">Make Payment</div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Class Progress -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-chart-pie"></i> Class Progress</h2>
                        </div>
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Assignments Submitted</span>
                                <span>
                                    <?php
                                    $total_assigned = $stats['assignments'];
                                    $submitted = 0;
                                    foreach ($upcoming_assignments as $assignment) {
                                        if ($assignment['submission_count'] > 0) $submitted++;
                                    }
                                    echo $submitted . '/' . $total_assigned;
                                    ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $total_assigned > 0 ? ($submitted / $total_assigned * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Announcements -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-bullhorn"></i> Recent Announcements</h2>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/announcements.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary">
                                <i class="fas fa-list"></i> All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_announcements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bullhorn"></i>
                                    <p>No announcements yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_announcements as $announcement): ?>
                                    <div class="list-item">
                                        <div class="list-content">
                                            <div class="item-title">
                                                <span><?php echo htmlspecialchars($announcement['title']); ?></span>
                                                <span style="font-size: 12px;">
                                                    <?php echo date('M d', strtotime($announcement['created_at'])); ?>
                                                </span>
                                            </div>
                                            <div class="item-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                            </div>
                                            <div class="item-description">
                                                <?php echo htmlspecialchars(substr($announcement['content'], 0, 100)); ?>
                                                <?php if (strlen($announcement['content']) > 100): ?>...<?php endif; ?>
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
                                <i class="fas fa-users"></i> View All
                            </a>
                        </div>
                        <div class="classmates-grid">
                            <?php if (empty($classmates)): ?>
                                <div class="empty-state">
                                    <p>No classmates yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($classmates as $classmate): ?>
                                    <div class="classmate-card">
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
        <?php if (!$is_class_accessible && !$is_class_date_accessible): ?>
            // Countdown Timer for class starting
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

                document.getElementById('days').textContent = days.toString().padStart(2, '0');
                document.getElementById('hours').textContent = hours.toString().padStart(2, '0');
                document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
                document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
            }

            updateCountdown();
            setInterval(updateCountdown, 1000);

        <?php elseif (!$is_class_accessible && $is_class_date_accessible && !$is_payment_accessible): ?>
            // Grace period countdown for payment
            function updateGraceCountdown() {
                const graceEndDate = new Date('<?php echo $grace_end_date->format('Y-m-d H:i:s'); ?>');
                const now = new Date();
                const timeDiff = graceEndDate - now;

                if (timeDiff <= 0) {
                    location.reload();
                    return;
                }

                const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);

                const graceDaysEl = document.getElementById('grace-days');
                if (graceDaysEl) {
                    graceDaysEl.textContent = days.toString().padStart(2, '0');
                }
            }

            updateGraceCountdown();
            setInterval(updateGraceCountdown, 1000);

        <?php endif; ?>

        // Common notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification';

            const icon = type === 'info' ? 'fa-info-circle' :
                type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            const bgColor = type === 'info' ? 'var(--primary)' :
                type === 'success' ? 'var(--success)' : 'var(--warning)';

            notification.innerHTML = `
                <i class="fas ${icon}" style="color: ${bgColor}; font-size: 24px;"></i>
                <div style="flex: 1;">
                    <strong>${message}</strong>
                </div>
                <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: var(--gray);">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

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

        // Payment status check
        setInterval(() => {
            fetch('<?php echo BASE_URL; ?>modules/student/finance/check_payment_status.php?class_id=<?php echo $class_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.paid) {
                        showNotification('Payment confirmed! Your status has been updated.', 'success');
                        setTimeout(() => location.reload(), 3000);
                    }
                })
                .catch(err => console.error('Payment check failed:', err));
        }, 30000);

        // Add smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Add loading states to buttons
        document.querySelectorAll('.btn-primary').forEach(button => {
            button.addEventListener('click', function() {
                if (!this.hasAttribute('disabled')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    this.setAttribute('disabled', 'true');

                    // Reset after 5 seconds if still on same page
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.removeAttribute('disabled');
                    }, 5000);
                }
            });
        });
    </script>
</body>

</html>