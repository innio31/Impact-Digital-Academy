<?php
// modules/student/dashboard.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/finance_functions.php';

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
$sql = "SELECT u.*, up.*, COUNT(e.id) as enrolled_classes_count
        FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN enrollments e ON u.id = e.student_id AND e.status = 'active'
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

// Initialize stats array
$stats = [
    'enrolled_classes' => $user_details['enrolled_classes_count'] ?? 0,
    'assignments_due' => 0,
    'assignments_submitted' => 0,
    'average_grade' => 0,
    'unread_notifications' => 0,
    'upcoming_classes' => 0,
    'discussion_posts' => 0,
    'materials_downloaded' => 0,

    // Financial Stats
    'total_fee' => 0,
    'paid_amount' => 0,
    'balance' => 0,
    'overdue_balance' => 0,
    'next_payment_due' => null,
    'is_suspended' => false,
    'pending_invoices' => 0,
    'payment_progress' => 0,
    'current_block' => 1,
    'is_cleared' => false
];

// Get student's enrolled classes
$enrolled_classes = [];
$sql = "SELECT e.*, cb.*, c.title as course_title, c.course_code, 
               p.name as program_name, p.program_code, p.program_type,
               CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
               sfs.total_fee, sfs.paid_amount, sfs.balance, 
               sfs.is_suspended, sfs.is_cleared, sfs.current_block,
               sfs.next_payment_due, sfs.registration_paid, 
               sfs.block1_paid, sfs.block2_paid
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN users i ON cb.instructor_id = i.id
        LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.student_id = ? AND e.status = 'active'
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $enrolled_classes = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Calculate statistics for enrolled classes
if (!empty($enrolled_classes)) {
    $total_fee = 0;
    $total_paid = 0;
    $total_balance = 0;
    $overdue_balance = 0;
    $suspended_classes = 0;
    $pending_invoices = 0;

    foreach ($enrolled_classes as $class) {
        $total_fee += floatval($class['total_fee'] ?? 0);
        $total_paid += floatval($class['paid_amount'] ?? 0);
        $balance = floatval($class['balance'] ?? 0);
        $total_balance += $balance;

        // Check if payment is overdue
        if ($class['next_payment_due'] && strtotime($class['next_payment_due']) < time()) {
            $overdue_balance += $balance;
        }

        // Check if suspended
        if ($class['is_suspended']) {
            $suspended_classes++;
        }

        // Count pending invoices for this class
        $invoice_sql = "SELECT COUNT(*) as count FROM invoices 
                       WHERE student_id = ? AND class_id = ? AND status IN ('pending', 'partial')";
        $invoice_stmt = $conn->prepare($invoice_sql);
        $invoice_stmt->bind_param("ii", $user_id, $class['id']);
        $invoice_stmt->execute();
        $invoice_result = $invoice_stmt->get_result();
        if ($invoice_row = $invoice_result->fetch_assoc()) {
            $pending_invoices += $invoice_row['count'];
        }
        $invoice_stmt->close();

        // Get current block progress
        if ($class['program_type'] === 'online') {
            $stats['current_block'] = $class['current_block'] ?? 1;
            $stats['is_cleared'] = $class['is_cleared'] ?? false;

            // Calculate block payment status
            $block_paid = 0;
            if ($class['registration_paid']) $block_paid++;
            if ($class['block1_paid']) $block_paid++;
            if ($class['block2_paid']) $block_paid++;

            $total_blocks = 3; // registration + 2 blocks
            $stats['payment_progress'] = round(($block_paid / $total_blocks) * 100);
        }
    }

    $stats['total_fee'] = $total_fee;
    $stats['paid_amount'] = $total_paid;
    $stats['balance'] = $total_balance;
    $stats['overdue_balance'] = $overdue_balance;
    $stats['is_suspended'] = $suspended_classes > 0;
    $stats['pending_invoices'] = $pending_invoices;

    // Get next payment due date
    $next_due_sql = "SELECT MIN(next_payment_due) as next_due 
                     FROM student_financial_status 
                     WHERE student_id = ? AND balance > 0 AND next_payment_due IS NOT NULL";
    $next_stmt = $conn->prepare($next_due_sql);
    $next_stmt->bind_param("i", $user_id);
    $next_stmt->execute();
    $next_result = $next_stmt->get_result();
    if ($next_row = $next_result->fetch_assoc()) {
        $stats['next_payment_due'] = $next_row['next_due'];
    }
    $next_stmt->close();
}

// Get assignments due soon (within next 7 days)
$assignments_due = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title, 
               DATEDIFF(a.due_date, NOW()) as days_left
        FROM assignments a
        JOIN class_batches cb ON a.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        WHERE a.class_id IN (SELECT class_id FROM enrollments WHERE student_id = ? AND status = 'active')
        AND a.is_published = 1
        AND a.due_date > NOW()
        AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        ORDER BY a.due_date ASC
        LIMIT 5";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $assignments_due = $result->fetch_all(MYSQLI_ASSOC);
        $stats['assignments_due'] = count($assignments_due);
    }
    $stmt->close();
}

// Get recent assignments submitted
$recent_submissions = [];
$sql = "SELECT asub.*, a.title as assignment_title, a.due_date,
               cb.batch_code, c.title as course_title
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        JOIN class_batches cb ON a.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        WHERE asub.student_id = ?
        ORDER BY asub.submitted_at DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $recent_submissions = $result->fetch_all(MYSQLI_ASSOC);
        $stats['assignments_submitted'] = count($recent_submissions);
    }
    $stmt->close();
}

// Calculate average grade
$grade_sql = "SELECT AVG(percentage) as avg_grade 
              FROM gradebook 
              WHERE student_id = ? AND published = 1";
$grade_stmt = $conn->prepare($grade_sql);
$grade_stmt->bind_param("i", $user_id);
$grade_stmt->execute();
$grade_result = $grade_stmt->get_result();
if ($grade_row = $grade_result->fetch_assoc()) {
    $stats['average_grade'] = round($grade_row['avg_grade'] ?? 0, 1);
}
$grade_stmt->close();

// Get unread notifications
$notifications_sql = "SELECT COUNT(*) as count FROM notifications 
                     WHERE (user_id = ? OR user_id IS NULL) 
                     AND is_read = 0";
$notif_stmt = $conn->prepare($notifications_sql);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();
if ($notif_row = $notif_result->fetch_assoc()) {
    $stats['unread_notifications'] = $notif_row['count'];
}
$notif_stmt->close();

// Get upcoming classes (next 3 days)
$today = date('Y-m-d');
$upcoming_date = date('Y-m-d', strtotime('+3 days'));
$upcoming_sql = "SELECT COUNT(DISTINCT cb.id) as count
                FROM enrollments e
                JOIN class_batches cb ON e.class_id = cb.id
                WHERE e.student_id = ? 
                AND e.status = 'active'
                AND cb.start_date BETWEEN ? AND ?";
$upcoming_stmt = $conn->prepare($upcoming_sql);
$upcoming_stmt->bind_param("iss", $user_id, $today, $upcoming_date);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();
if ($upcoming_row = $upcoming_result->fetch_assoc()) {
    $stats['upcoming_classes'] = $upcoming_row['count'];
}
$upcoming_stmt->close();

// Get discussion activity
$discussion_sql = "SELECT COUNT(*) as count 
                  FROM discussion_replies 
                  WHERE user_id = ? 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$disc_stmt = $conn->prepare($discussion_sql);
$disc_stmt->bind_param("i", $user_id);
$disc_stmt->execute();
$disc_result = $disc_stmt->get_result();
if ($disc_row = $disc_result->fetch_assoc()) {
    $stats['discussion_posts'] = $disc_row['count'];
}
$disc_stmt->close();

// Get recent announcements
$announcements = [];
$sql = "SELECT a.*, cb.batch_code, c.title as course_title,
               CONCAT(u.first_name, ' ', u.last_name) as author_name
        FROM announcements a
        JOIN class_batches cb ON a.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN users u ON a.author_id = u.id
        WHERE a.class_id IN (SELECT class_id FROM enrollments WHERE student_id = ? AND status = 'active')
        AND a.is_published = 1
        AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())
        ORDER BY a.publish_date DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get recent financial transactions
$transactions = [];
$sql = "SELECT ft.*, cb.batch_code, c.title as course_title,
               p.name as program_name
        FROM financial_transactions ft
        LEFT JOIN class_batches cb ON ft.class_id = cb.id
        LEFT JOIN courses c ON cb.course_id = c.id
        LEFT JOIN programs p ON c.program_id = p.id
        WHERE ft.student_id = ?
        ORDER BY ft.created_at DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get upcoming payments
$upcoming_payments = [];
$sql = "SELECT i.*, cb.batch_code, c.title as course_title,
               DATEDIFF(i.due_date, CURDATE()) as days_left
        FROM invoices i
        JOIN class_batches cb ON i.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        WHERE i.student_id = ?
        AND i.status IN ('pending', 'partial')
        AND i.due_date >= CURDATE()
        ORDER BY i.due_date ASC
        LIMIT 3";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $upcoming_payments = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get recent activity
$activities = [];
$sql = "SELECT al.*, u.first_name, u.last_name 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE al.user_id = ? OR al.description LIKE ?
        ORDER BY al.created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$search_pattern = '%' . $user_details['first_name'] . ' ' . $user_details['last_name'] . '%';
$stmt->bind_param("is", $user_id, $search_pattern);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $activities = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Get class schedule for today
$today_schedule = [];
$today = date('Y-m-d');
$sql = "SELECT cb.*, c.title as course_title, 
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
               cb.meeting_link, cb.schedule
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN users u ON cb.instructor_id = u.id
        WHERE cb.id IN (SELECT class_id FROM enrollments WHERE student_id = ? AND status = 'active')
        AND cb.status = 'ongoing'
        AND ? BETWEEN cb.start_date AND cb.end_date
        ORDER BY cb.schedule";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $today_schedule = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Log dashboard access
logActivity($user_id, 'student_dashboard_access', 'Student accessed dashboard', $_SERVER['REMOTE_ADDR']);

// Close database connection
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        /* Dropdown Navigation Styles */
        .nav-dropdown {
            position: relative;
        }

        .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            width: 100%;
            background: none;
            border: none;
            color: inherit;
            font-family: inherit;
            font-size: inherit;
        }

        .dropdown-toggle i:last-child {
            margin-left: auto;
            transition: transform 0.3s ease;
            font-size: 0.875rem;
        }

        .nav-dropdown.active .dropdown-toggle i:last-child {
            transform: rotate(180deg);
        }

        .dropdown-content {
            display: none;
            background-color: rgba(0, 0, 0, 0.2);
            border-left: 2px solid var(--primary);
            padding-left: 0;
        }

        .nav-dropdown.active .dropdown-content {
            display: block;
        }

        .dropdown-content .nav-item {
            padding-left: 3.5rem;
            font-size: 0.875rem;
            padding-top: 0.625rem;
            padding-bottom: 0.625rem;
        }

        .dropdown-content .nav-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .dropdown-content .nav-item.active {
            background-color: rgba(67, 97, 238, 0.15);
            color: white;
        }

        .sidebar.collapsed .dropdown-content {
            display: none !important;
        }

        .sidebar.collapsed .dropdown-toggle i:last-child {
            display: none;
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

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .search-box input {
            padding: 0.5rem 0.75rem 0.5rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            width: 250px;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Search results dropdown */
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: var(--card-shadow);
            display: none;
            z-index: 1000;
            max-height: 400px;
            overflow-y: auto;
        }

        .search-result-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: var(--dark);
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        .search-result-icon {
            width: 32px;
            height: 32px;
            background-color: #f1f5f9;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            color: var(--primary);
        }

        .search-result-content h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .search-result-content p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* User menu dropdown */
        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: var(--card-shadow);
            display: none;
            min-width: 200px;
            z-index: 1000;
        }

        .user-menu-dropdown a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
        }

        .user-menu-dropdown a:hover {
            background-color: #f8f9fa;
        }

        .user-menu-dropdown a i {
            width: 20px;
            margin-right: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .stat-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card.primary {
            border-top: 4px solid var(--primary);
        }

        .stat-card.success {
            border-top: 4px solid var(--success);
        }

        .stat-card.warning {
            border-top: 4px solid var(--warning);
        }

        .stat-card.accent {
            border-top: 4px solid var(--secondary);
        }

        .stat-card.info {
            border-top: 4px solid var(--info);
        }

        .stat-card.danger {
            border-top: 4px solid var(--danger);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: rgba(67, 97, 238, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .stat-card.success .stat-icon {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .stat-card.accent .stat-icon {
            background-color: rgba(114, 9, 183, 0.1);
            color: var(--secondary);
        }

        .stat-card.info .stat-icon {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .stat-card.danger .stat-icon {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Alert Banners */
        .alert-banner {
            padding: 1rem 1.5rem;
            margin: 0 1.5rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease;
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

        .alert-warning {
            background-color: rgba(247, 37, 133, 0.1);
            border-left: 4px solid var(--warning);
            color: var(--dark);
        }

        .alert-danger {
            background-color: rgba(230, 57, 70, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--dark);
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert-info {
            background-color: rgba(72, 149, 239, 0.1);
            border-left: 4px solid var(--info);
            color: var(--dark);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            padding: 0 1.5rem 1.5rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .card-actions .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background-color: #f8f9fa;
        }

        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background-color: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-overdue {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .status-suspended {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }

        .activity-item:hover {
            background-color: #f8f9fa;
        }

        .activity-item.assignment {
            border-left-color: var(--info);
        }

        .activity-item.payment {
            border-left-color: var(--success);
        }

        .activity-item.announcement {
            border-left-color: var(--warning);
        }

        .activity-item.class {
            border-left-color: var(--primary);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.25rem;
        }

        .assignment-icon {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .payment-icon {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .announcement-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .class-icon {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }

        .activity-description {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .activity-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray-light);
        }

        .activity-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            background-color: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
        }

        .quick-action:hover {
            background-color: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        .quick-action:hover .quick-action-icon {
            background-color: white;
            color: var(--primary);
        }

        .quick-action-icon {
            width: 48px;
            height: 48px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            transition: var(--transition);
        }

        .quick-action-label {
            font-size: 0.875rem;
            font-weight: 600;
            text-align: center;
        }

        /* Today's Schedule */
        .schedule-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .schedule-item:hover {
            background-color: #f8f9fa;
        }

        .schedule-time {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.875rem;
            min-width: 80px;
        }

        .schedule-details {
            flex: 1;
            margin: 0 1rem;
        }

        .schedule-details h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .schedule-details p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .join-btn {
            padding: 0.375rem 0.75rem;
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-block;
        }

        .join-btn:hover {
            background-color: #3da8d5;
        }

        /* Financial Summary */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .financial-item {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .financial-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .financial-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .financial-value.paid {
            color: var(--success);
        }

        .financial-value.due {
            color: var(--danger);
        }

        .financial-value.balance {
            color: var(--warning);
        }

        /* Class Cards */
        .class-cards {
            display: grid;
            gap: 1rem;
        }

        .class-card {
            background-color: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .class-card:hover {
            transform: translateX(5px);
        }

        .class-card.suspended {
            border-left-color: var(--danger);
            opacity: 0.7;
        }

        .class-card.completed {
            border-left-color: var(--success);
        }

        .class-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .class-card-title h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .class-card-title p {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .class-card-progress {
            margin: 0.75rem 0;
        }

        .class-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Button Styles */
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
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #d62839;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                padding: 1rem;
            }

            .search-box input {
                width: 200px;
            }

            .financial-summary {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Profile Picture Styles */
        .user-avatar-with-pic {
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--primary);
            background-color: #f8f9fa;
        }

        .user-avatar-with-pic img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-avatar-with-pic:hover {
            transform: scale(1.05);
            transition: var(--transition);
        }

        /* Add fallback styling for avatar */
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

        /* Top bar avatar specific */
        .top-actions .user-avatar {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }

        .top-actions .user-avatar-with-pic {
            width: 40px;
            height: 40px;
        }

        /* Ensure sidebar collapsed state still works */
        .sidebar.collapsed .user-avatar-with-pic {
            width: 40px;
            height: 40px;
            margin: 0 auto;
        }

        .sidebar.collapsed .user-avatar {
            width: 40px;
            height: 40px;
            margin: 0 auto;
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .top-actions {
                display: none;
                /* This hides the entire top actions section */
            }
        }

        /* Add to the existing CSS, after the existing media query */

        /* Mobile Menu Toggle Button */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background-color: #f1f5f9;
        }

        /* Update the existing mobile media query */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .top-actions {
                display: none;
            }

            .sidebar {
                width: 0;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.mobile-open {
                width: 260px;
                transform: translateX(0);
                box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                padding: 1rem;
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 200px;
            }

            .financial-summary {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            /* Add overlay for mobile menu */
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .sidebar-overlay.active {
                display: block;
            }
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
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-label">Dashboard</span>
            </a>

            <!-- Classes Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-chalkboard"></i>
                    <span class="nav-label">My Classes</span>
                    <?php if ($stats['enrolled_classes'] > 0): ?>
                        <span class="badge"><?php echo $stats['enrolled_classes']; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="nav-item">
                        <i class="fas fa-list"></i>
                        <span class="nav-label">All Classes</span>
                    </a>
                    <?php if (!empty($enrolled_classes)): ?>
                        <?php foreach (array_slice($enrolled_classes, 0, 3) as $class): ?>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/class_home.php?<?php echo $class['id']; ?>" class="nav-item">
                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                <span class="nav-label"><?php echo htmlspecialchars(substr($class['course_title'], 0, 20)) . (strlen($class['course_title']) > 20 ? '...' : ''); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($enrolled_classes) > 3): ?>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="nav-item">
                                <i class="fas fa-ellipsis-h"></i>
                                <span class="nav-label">View All...</span>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="nav-item">
                            <i class="fas fa-search"></i>
                            <span class="nav-label">Browse Classes</span>
                        </a>
                    <?php endif; ?>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/calendar.php" class="nav-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-label">Class Schedule</span>
                    </a>
                </div>
            </div>

            <!-- Add to dashboard.php sidebar navigation -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="nav-label">My Program</span>
                    <?php
                    $program_progress = getStudentProgramProgress($user_id);
                    if ($program_progress['percentage'] > 0 && $program_progress['percentage'] < 100):
                    ?>
                        <span class="badge" style="background-color: var(--info);">
                            <?php echo round($program_progress['percentage'], 0); ?>%
                        </span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/program/" class="nav-item">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-label">Program Progress</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/requirements.php" class="nav-item">
                        <i class="fas fa-clipboard-list"></i>
                        <span class="nav-label">Requirements</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/courses.php" class="nav-item">
                        <i class="fas fa-book"></i>
                        <span class="nav-label">All Courses</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/register_courses.php" class="nav-item">
                        <i class="fas fa-calendar-plus"></i>
                        <span class="nav-label">Course Registration</span>
                    </a>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/program/graduation.php" class="nav-item">
                        <i class="fas fa-graduation-cap"></i>
                        <span class="nav-label">Graduation Status</span>
                    </a>
                </div>
            </div>

            <!-- Finance Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-money-bill-wave"></i>
                    <span class="nav-label">Finance</span>
                    <?php if ($stats['pending_invoices'] > 0 || $stats['overdue_balance'] > 0): ?>
                        <span class="badge" style="background-color: var(--danger);">!</span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php" class="nav-item">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="nav-label">Financial Overview</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php" class="nav-item">
                        <i class="fas fa-credit-card"></i>
                        <span class="nav-label">Payment History</span>
                    </a>
                    <!--
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="nav-item">
                        <i class="fas fa-plus-circle"></i>
                        <span class="nav-label">Make Payment</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/" class="nav-item">
                        <i class="fas fa-file-invoice"></i>
                        <span class="nav-label">Invoices</span>
                    </a>
                    -->
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/fees/index.php" class="nav-item">
                        <i class="fas fa-coins"></i>
                        <span class="nav-label">Fee Structure</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/status/" class="nav-item">
                        <i class="fas fa-clipboard-check"></i>
                        <span class="nav-label">Clearance Status</span>
                    </a>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/requests/waiver.php" class="nav-item">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span class="nav-label">Request Waiver</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/requests/installment.php" class="nav-item">
                        <i class="fas fa-calendar-check"></i>
                        <span class="nav-label">Installment Plan</span>
                    </a>
                </div>
            </div>

            <!-- Assignments Dropdown
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-tasks"></i>
                    <span class="nav-label">Assignments</span>
                    <?php if ($stats['assignments_due'] > 0): ?>
                        <span class="badge" style="background-color: var(--warning);"><?php echo $stats['assignments_due']; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/assignments/index.php" class="nav-item">
                        <i class="fas fa-list"></i>
                        <span class="nav-label">All Assignments</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/?filter=upcoming" class="nav-item">
                        <i class="fas fa-clock"></i>
                        <span class="nav-label">Upcoming</span>
                        <?php if ($stats['assignments_due'] > 0): ?>
                            <span class="badge" style="background-color: var(--warning); font-size: 0.625rem;"><?php echo $stats['assignments_due']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/?filter=submitted" class="nav-item">
                        <i class="fas fa-check-circle"></i>
                        <span class="nav-label">Submitted</span>
                        <?php if ($stats['assignments_submitted'] > 0): ?>
                            <span class="badge" style="font-size: 0.625rem;"><?php echo $stats['assignments_submitted']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/?filter=graded" class="nav-item">
                        <i class="fas fa-star"></i>
                        <span class="nav-label">Graded</span>
                    </a>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/grades/" class="nav-item">
                        <i class="fas fa-chart-bar"></i>
                        <span class="nav-label">Gradebook</span>
                    </a>
                </div>
            </div>
                        -->

            <!-- Materials Dropdown 
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-book"></i>
                    <span class="nav-label">Learning Materials</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <?php if (!empty($enrolled_classes)): ?>
                        <?php foreach (array_slice($enrolled_classes, 0, 3) as $class): ?>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/<?php echo $class['id']; ?>/materials/" class="nav-item">
                                <i class="fas fa-folder"></i>
                                <span class="nav-label"><?php echo htmlspecialchars(substr($class['course_title'], 0, 18)) . (strlen($class['course_title']) > 18 ? '...' : ''); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="nav-item">
                            <i class="fas fa-search"></i>
                            <span class="nav-label">No Materials Yet</span>
                        </a>
                    <?php endif; ?>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/materials/recent.php" class="nav-item">
                        <i class="fas fa-history"></i>
                        <span class="nav-label">Recently Added</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/materials/downloaded.php" class="nav-item">
                        <i class="fas fa-download"></i>
                        <span class="nav-label">Downloaded</span>
                    </a>
                </div>
            </div>
                    -->

            <!-- Discussions Dropdown
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-comments"></i>
                    <span class="nav-label">Discussions</span>
                    <?php if ($stats['discussion_posts'] > 0): ?>
                        <span class="badge" style="background-color: var(--info);"><?php echo $stats['discussion_posts']; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <?php if (!empty($enrolled_classes)): ?>
                        <?php foreach (array_slice($enrolled_classes, 0, 3) as $class): ?>
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/<?php echo $class['id']; ?>/discussions/" class="nav-item">
                                <i class="fas fa-comment-alt"></i>
                                <span class="nav-label"><?php echo htmlspecialchars(substr($class['course_title'], 0, 18)) . (strlen($class['course_title']) > 18 ? '...' : ''); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="#" class="nav-item">
                            <i class="fas fa-comment-slash"></i>
                            <span class="nav-label">No Active Discussions</span>
                        </a>
                    <?php endif; ?>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/discussions/recent.php" class="nav-item">
                        <i class="fas fa-history"></i>
                        <span class="nav-label">Recent Posts</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/classes/discussions/my_posts.php" class="nav-item">
                        <i class="fas fa-user-edit"></i>
                        <span class="nav-label">My Posts</span>
                        <?php if ($stats['discussion_posts'] > 0): ?>
                            <span class="badge" style="font-size: 0.625rem; background-color: var(--info);"><?php echo $stats['discussion_posts']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div> 
            -->

            <div class="nav-divider"></div>

            <!-- Profile Dropdown -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-user"></i>
                    <span class="nav-label">My Profile</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" class="nav-item">
                        <i class="fas fa-user-edit"></i>
                        <span class="nav-label">Edit Profile</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/profile/view.php" class="nav-item">
                        <i class="fas fa-eye"></i>
                        <span class="nav-label">View Profile</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/student/profile/certificates.php" class="nav-item">
                        <i class="fas fa-certificate"></i>
                        <span class="nav-label">Certificates</span>
                    </a>
                    <!--
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/shared/profile/edit_account.php" class="nav-item">
                        <i class="fas fa-cog"></i>
                        <span class="nav-label">Account Settings</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/profile/security.php" class="nav-item">
                        <i class="fas fa-shield-alt"></i>
                        <span class="nav-label">Security</span>
                    </a>
                        -->
                </div>
            </div>

            <!-- Notifications & Messages -->
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-bell"></i>
                    <span class="nav-label">Notifications</span>
                    <?php if ($stats['unread_notifications'] > 0): ?>
                        <span class="badge" style="background-color: var(--warning);"><?php echo $stats['unread_notifications']; ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/shared/notifications/index.php" class="nav-item">
                        <i class="fas fa-inbox"></i>
                        <span class="nav-label">All Notifications</span>
                        <?php if ($stats['unread_notifications'] > 0): ?>
                            <span class="badge" style="background-color: var(--warning); font-size: 0.625rem;"><?php echo $stats['unread_notifications']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/notifications/?type=system" class="nav-item">
                        <i class="fas fa-cog"></i>
                        <span class="nav-label">System Notifications</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/notifications/?type=assignment" class="nav-item">
                        <i class="fas fa-tasks"></i>
                        <span class="nav-label">Assignment Alerts</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/notifications/?type=grade" class="nav-item">
                        <i class="fas fa-star"></i>
                        <span class="nav-label">Grade Updates</span>
                    </a>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/shared/mail/index.php" class="nav-item">
                        <i class="fas fa-envelope"></i>
                        <span class="nav-label">Messages</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/mail/compose.php" class="nav-item">
                        <i class="fas fa-pen"></i>
                        <span class="nav-label">Compose Message</span>
                    </a>
                </div>
            </div>

            <!-- Help & Support
            <div class="nav-dropdown">
                <div class="nav-item dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="fas fa-question-circle"></i>
                    <span class="nav-label">Help & Support</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown-content">
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/" class="nav-item">
                        <i class="fas fa-question-circle"></i>
                        <span class="nav-label">Help Center</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/faq.php" class="nav-item">
                        <i class="fas fa-question"></i>
                        <span class="nav-label">FAQ</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/ticket.php" class="nav-item">
                        <i class="fas fa-ticket-alt"></i>
                        <span class="nav-label">Submit Ticket</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/my_tickets.php" class="nav-item">
                        <i class="fas fa-list"></i>
                        <span class="nav-label">My Tickets</span>
                    </a>
                    <div class="nav-divider" style="margin: 0.5rem 1.5rem;"></div>
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/resources.php" class="nav-item">
                        <i class="fas fa-book-open"></i>
                        <span class="nav-label">Resources</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/shared/help/contact.php" class="nav-item">
                        <i class="fas fa-phone-alt"></i>
                        <span class="nav-label">Contact Support</span>
                    </a>
                </div>
            </div>
                        -->

            <div class="nav-divider"></div>

            <a href="<?php echo BASE_URL; ?>modules/shared/search.php" class="nav-item">
                <i class="fas fa-search"></i>
                <span class="nav-label">Search</span>
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
        <!-- Top Bar -->
        <div class="top-bar">
            <!-- Add this mobile menu button -->
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>

            <div class="page-title">
                <h1>Student Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user_details['first_name']); ?>! Here's your academic overview.</p>
            </div>

            <div class="top-actions">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search classes, materials, assignments..." id="globalSearch" oninput="performSearch(this.value)">
                    <div class="search-results" id="searchResults"></div>
                </div>

                <a href="<?php echo BASE_URL; ?>modules/shared/notifications/index.php" class="notification-bell" style="position: relative; text-decoration: none; color: inherit; display: flex; align-items: center;">
                    <i class="fas fa-bell" style="font-size: 1.25rem;"></i>
                    <?php if ($stats['unread_notifications'] > 0): ?>
                        <span class="badge" style="position: absolute; top: -5px; right: -5px; background-color: var(--warning);"><?php echo $stats['unread_notifications']; ?></span>
                    <?php endif; ?>
                </a>

                <div class="user-menu" style="position: relative;">
                    <?php
                    // Check if user has a profile image
                    $profile_pic = !empty($user_details['profile_image']) ? BASE_URL . $user_details['profile_image'] : '';
                    ?>

                    <?php if ($profile_pic): ?>
                        <div class="user-avatar-with-pic" onclick="toggleUserMenu()"
                            style="width: 40px; height: 40px; cursor: pointer; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary);">
                            <img src="<?php echo htmlspecialchars($profile_pic); ?>"
                                alt="Profile Picture"
                                style="width: 100%; height: 100%; object-fit: cover;"
                                onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\'width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; font-weight: bold;\'><?php echo $initials ?: "S"; ?></div>'">
                        </div>
                    <?php else: ?>
                        <div class="user-avatar" onclick="toggleUserMenu()"
                            style="width: 40px; height: 40px; cursor: pointer; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; color: white;">
                            <?php echo $initials ?: 'S'; ?>
                        </div>
                    <?php endif; ?>

                    <div class="user-menu-dropdown" id="userMenuDropdown" style="position: absolute; top: 100%; right: 0; background: white; border: 1px solid var(--border); border-radius: 6px; box-shadow: var(--card-shadow); display: none; min-width: 200px; z-index: 1000;">
                        <a href="<?php echo BASE_URL; ?>modules/student/profile/edit.php" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--dark);">
                            <i class="fas fa-user" style="width: 20px; margin-right: 0.5rem;"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/shared/profile/edit_account.php" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--dark);">
                            <i class="fas fa-cog" style="width: 20px; margin-right: 0.5rem;"></i>
                            <span>Account Settings</span>
                        </a>
                        <div style="height: 1px; background-color: var(--border); margin: 0.25rem 0;"></div>
                        <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--danger);" onclick="return confirm('Are you sure you want to logout?');">
                            <i class="fas fa-sign-out-alt" style="width: 20px; margin-right: 0.5rem;"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Banners -->
        <?php if ($stats['is_suspended']): ?>
            <div class="alert-banner alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Account Suspended!</strong> Your access has been suspended due to overdue payments. Please clear your balance to regain access.
                </div>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php" class="btn btn-danger btn-sm" style="margin-left: auto;">
                    View Payments
                </a>
            </div>
        <?php elseif ($stats['overdue_balance'] > 0): ?>
            <div class="alert-banner alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Overdue Payments!</strong> You have overdue payments of ₦<?php echo number_format($stats['overdue_balance'], 2); ?>. Please make payment immediately to avoid suspension.
                </div>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-warning btn-sm" style="margin-left: auto;">
                    Pay Now
                </a>
            </div>
        <?php elseif ($stats['assignments_due'] > 0): ?>
            <div class="alert-banner alert-info">
                <i class="fas fa-tasks"></i>
                <div>
                    <strong>Assignments Due Soon!</strong> You have <?php echo $stats['assignments_due']; ?> assignment(s) due in the next 7 days.
                </div>
                <a href="<?php echo BASE_URL; ?>portals/modules/student/assingments/?status=upcoming_week&class_id=all&sort=due_date_asc&search=" class="btn btn-info btn-sm" style="margin-left: auto;">
                    View Assignments
                </a>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <!-- Financial Overview Card -->
            <div class="stat-card accent">
                <div class="stat-header">
                    <div class="stat-title">Financial Status</div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-value">₦<?php echo number_format($stats['paid_amount'], 2); ?></div>
                <div class="stat-change">
                    <span style="color: <?php echo $stats['balance'] > 0 ? 'var(--warning)' : 'var(--success)'; ?>">
                        ₦<?php echo number_format($stats['balance'], 2); ?> balance
                    </span>
                    <?php if ($stats['overdue_balance'] > 0): ?>
                        <span style="color: var(--danger); margin-left: 0.5rem;">
                            <i class="fas fa-exclamation-circle"></i>
                            ₦<?php echo number_format($stats['overdue_balance'], 2); ?> overdue
                        </span>
                    <?php endif; ?>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $stats['total_fee'] > 0 ? ($stats['paid_amount'] / $stats['total_fee'] * 100) : 0; ?>%"></div>
                </div>
                <div style="font-size: 0.875rem; margin-top: 0.5rem;">
                    Progress: <?php echo $stats['total_fee'] > 0 ? round(($stats['paid_amount'] / $stats['total_fee'] * 100), 1) : 0; ?>% paid
                </div>
            </div>

            <!-- Academic Progress -->
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-title">Academic Progress</div>
                    <div class="stat-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['average_grade']; ?>%</div>
                <div class="stat-change">
                    <i class="fas fa-chart-line"></i>
                    Average Grade
                </div>
                <?php if ($stats['enrolled_classes'] > 0): ?>
                    <div style="font-size: 0.875rem; margin-top: 0.5rem;">
                        Enrolled in <?php echo $stats['enrolled_classes']; ?> class(es)
                    </div>
                <?php endif; ?>
            </div>

            <!-- Assignments -->
            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-title">Assignments</div>
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['assignments_submitted']; ?></div>
                <div class="stat-change">
                    <i class="fas fa-clock"></i>
                    <?php echo $stats['assignments_due']; ?> due soon
                </div>
            </div>

            <!-- Classes & Schedule -->
            <div class="stat-card primary">
                <div class="stat-header">
                    <div class="stat-title">Classes & Schedule</div>
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['enrolled_classes']; ?></div>
                <div class="stat-change">
                    <i class="fas fa-calendar"></i>
                    <?php echo $stats['upcoming_classes']; ?> upcoming
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- My Classes -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">My Classes</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="btn btn-primary">
                                <i class="fas fa-list"></i> View All
                            </a>
                        </div>
                    </div>

                    <div class="class-cards">
                        <?php if (empty($enrolled_classes)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                <i class="fas fa-chalkboard-teacher" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: var(--gray-light);"></i>
                                No classes enrolled yet
                                <div style="margin-top: 1rem;">
                                    <a href="<?php echo BASE_URL; ?>modules/student/classes/index.php" class="btn btn-primary">
                                        Browse Available Classes
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($enrolled_classes, 0, 3) as $class): ?>
                                <div class="class-card <?php echo $class['is_suspended'] ? 'suspended' : ''; ?>">
                                    <div class="class-card-header">
                                        <div class="class-card-title">
                                            <h4><?php echo htmlspecialchars($class['course_title']); ?></h4>
                                            <p><?php echo htmlspecialchars($class['program_name']); ?> • <?php echo htmlspecialchars($class['batch_code']); ?></p>
                                        </div>
                                        <?php if ($class['program_type'] === 'online'): ?>
                                            <span class="status-badge status-active">Online</span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background-color: rgba(114, 9, 183, 0.1); color: var(--secondary);">Onsite</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="class-card-progress">
                                        <div style="font-size: 0.875rem; margin-bottom: 0.25rem;">
                                            Instructor: <?php echo htmlspecialchars($class['instructor_name'] ?? 'Not assigned'); ?>
                                        </div>
                                        <?php if ($class['program_type'] === 'online'): ?>
                                            <div style="font-size: 0.875rem; color: var(--gray); margin-bottom: 0.5rem;">
                                                Current Block: <?php echo $class['current_block'] ?? 1; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="class-card-footer">
                                        <div>
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M d, Y', strtotime($class['start_date'])); ?> - <?php echo date('M d, Y', strtotime($class['end_date'])); ?>
                                        </div>
                                        <a href="classes/class_home.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-primary">
                                            Enter Class
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Assignments -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title">Upcoming Assignments</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-secondary">
                                <i class="fas fa-tasks"></i> All Assignments
                            </a>
                        </div>
                    </div>

                    <div class="activity-list">
                        <?php if (empty($assignments_due)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 1rem; display: block; color: var(--success);"></i>
                                No upcoming assignments
                            </div>
                        <?php else: ?>
                            <?php foreach ($assignments_due as $assignment): ?>
                                <a href="<?php echo BASE_URL; ?>modules/student/classes/assignments/view.php?assignment_id=<?php echo $assignment['id']; ?>" class="activity-item assignment" style="text-decoration: none; color: inherit;">
                                    <div class="activity-icon assignment-icon">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </div>
                                        <div class="activity-description">
                                            <?php echo htmlspecialchars($assignment['course_title']); ?> • <?php echo htmlspecialchars($assignment['batch_code']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                Due: <?php echo date('M d, g:i A', strtotime($assignment['due_date'])); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-clock"></i>
                                                <?php echo $assignment['days_left']; ?> days left
                                            </span>
                                            <span>
                                                <i class="fas fa-coins"></i>
                                                <?php echo number_format($assignment['total_points'] ?? 100, 1); ?> points
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Financial Summary -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Financial Summary</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php" class="btn btn-primary btn-sm">
                                Details
                            </a>
                        </div>
                    </div>

                    <div class="financial-summary">
                        <div class="financial-item">
                            <div class="financial-label">Total Fee</div>
                            <div class="financial-value">₦<?php echo number_format($stats['total_fee'], 2); ?></div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-label">Paid Amount</div>
                            <div class="financial-value paid">₦<?php echo number_format($stats['paid_amount'], 2); ?></div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-label">Balance</div>
                            <div class="financial-value balance">₦<?php echo number_format($stats['balance'], 2); ?></div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-label">Overdue</div>
                            <div class="financial-value due">₦<?php echo number_format($stats['overdue_balance'], 2); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($upcoming_payments)): ?>
                        <div style="margin-top: 1.5rem;">
                            <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--dark);">Upcoming Payments</h3>
                            <?php foreach ($upcoming_payments as $invoice): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background-color: #f8f9fa; border-radius: 6px; margin-bottom: 0.5rem;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.875rem;"><?php echo htmlspecialchars($invoice['course_title']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--gray);">Due: <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 0.875rem; color: var(--warning);">₦<?php echo number_format($invoice['amount'] - ($invoice['paid_amount'] ?? 0), 2); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--gray);"><?php echo $invoice['days_left']; ?> days left</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 1.5rem;">
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-success" style="width: 100%;">
                            <i class="fas fa-credit-card"></i> Make Payment
                        </a>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 0.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/" class="btn btn-secondary btn-sm">
                                View Invoices
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php" class="btn btn-secondary btn-sm">
                                Payment History
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Add to dashboard.php after the financial summary section -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title">My Program</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/program/" class="btn btn-primary">
                                <i class="fas fa-graduation-cap"></i> View Program
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($program)): ?>
                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                            <div>
                                <h3 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($program['name']); ?></h3>
                                <p style="color: var(--gray); margin-bottom: 1rem;"><?php echo htmlspecialchars($program['program_code']); ?> • <?php echo ucfirst($program['program_type']); ?> Program</p>

                                <div class="progress-bar" style="height: 10px; margin: 1rem 0;">
                                    <div class="progress-fill" style="width: <?php echo $program_progress['percentage']; ?>%"></div>
                                </div>

                                <div style="display: flex; justify-content: space-between; font-size: 0.875rem;">
                                    <span><?php echo round($program_progress['percentage'], 1); ?>% Complete</span>
                                    <span>GPA: <?php echo round($program_progress['gpa'], 2); ?></span>
                                </div>
                            </div>

                            <div>
                                <div style="text-align: center;">
                                    <div style="font-size: 2rem; font-weight: bold; color: var(--primary);">
                                        <?php echo $program_progress['completed_courses']; ?>/<?php echo $program_progress['total_courses']; ?>
                                    </div>
                                    <div style="font-size: 0.875rem; color: var(--gray);">Courses Completed</div>
                                </div>

                                <?php if (!empty($current_period)): ?>
                                    <div style="margin-top: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 6px;">
                                        <div style="font-size: 0.875rem; font-weight: 600;">Current <?php echo $program['program_type'] == 'onsite' ? 'Term' : 'Block'; ?></div>
                                        <div style="font-size: 0.75rem;"><?php echo htmlspecialchars($current_period['period_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($upcoming_periods)): ?>
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                                <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem;">Upcoming Registration</h4>
                                <?php foreach (array_slice($upcoming_periods, 0, 2) as $period): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; 
                                padding: 0.75rem; background: #f8f9fa; border-radius: 6px; margin-bottom: 0.5rem;">
                                        <div>
                                            <div style="font-weight: 600; font-size: 0.875rem;"><?php echo htmlspecialchars($period['period_name']); ?></div>
                                            <div style="font-size: 0.75rem; color: var(--gray);">
                                                Deadline: <?php echo date('M d', strtotime($period['registration_deadline'])); ?>
                                            </div>
                                        </div>
                                        <a href="<?php echo BASE_URL; ?>modules/student/program/register_courses.php?period_id=<?php echo $period['id']; ?>"
                                            class="btn btn-sm btn-primary">Register</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem; color: var(--gray);">
                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                            Not enrolled in any program
                            <div style="margin-top: 1rem;">
                                <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="btn btn-primary">Browse Programs</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title">Quick Actions</h2>
                    </div>

                    <div class="quick-actions-grid">
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <div class="quick-action-label">Make Payment</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/student/classes/" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-chalkboard"></i>
                            </div>
                            <div class="quick-action-label">My Classes</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/student/profile/certificates.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-certificate"></i>
                            </div>
                            <div class="quick-action-label">Certificates</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/shared/help/ticket.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-question-circle"></i>
                            </div>
                            <div class="quick-action-label">Get Help</div>
                        </a>
                    </div>
                </div>

                <!-- Today's Schedule -->
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2 class="card-title">Today's Schedule</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/calendar.php" class="btn btn-secondary btn-sm">
                                Full Calendar
                            </a>
                        </div>
                    </div>

                    <div>
                        <?php if (empty($today_schedule)): ?>
                            <div style="text-align: center; padding: 1rem; color: var(--gray);">
                                <i class="fas fa-calendar-day" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                                No classes scheduled for today
                            </div>
                        <?php else: ?>
                            <?php foreach ($today_schedule as $class): ?>
                                <div class="schedule-item">
                                    <div class="schedule-time">
                                        <?php
                                        // Extract time from schedule if available, otherwise show default
                                        if (!empty($class['schedule'])) {
                                            echo date('g:i A', strtotime($class['schedule']));
                                        } else {
                                            echo '9:00 AM';
                                        }
                                        ?>
                                    </div>
                                    <div class="schedule-details">
                                        <h4><?php echo htmlspecialchars($class['course_title']); ?></h4>
                                        <p><?php echo htmlspecialchars($class['instructor_name']); ?></p>
                                    </div>
                                    <?php if ($class['program_type'] === 'online' && !empty($class['meeting_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="join-btn">
                                            Join
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <div class="system-status">
                <div class="status-indicator"></div>
                <span>System Status: Operational</span>
            </div>
            <div>
                <span>Last Updated: <?php echo date('F j, Y, g:i a'); ?></span>
                <?php if ($stats['average_grade'] > 0): ?>
                    <span style="margin-left: 1rem; color: var(--success); font-weight: 600;">
                        <i class="fas fa-chart-line"></i>
                        GPA: <?php echo $stats['average_grade']; ?>%
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');

            // Save preference to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }

        // Toggle dropdown navigation
        function toggleDropdown(element) {
            const dropdown = element.closest('.nav-dropdown');
            dropdown.classList.toggle('active');

            // Close other dropdowns
            const allDropdowns = document.querySelectorAll('.nav-dropdown.active');
            allDropdowns.forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('active');
                }
            });
        }

        // Close all dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.nav-dropdown') && !event.target.closest('.sidebar')) {
                document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
            }

            // Close user menu
            if (!event.target.closest('.user-menu')) {
                document.getElementById('userMenuDropdown').style.display = 'none';
            }

            // Close search results
            if (!event.target.closest('.search-box')) {
                hideSearchResults();
            }
        });

        // Toggle user menu dropdown
        function toggleUserMenu() {
            const dropdown = document.getElementById('userMenuDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

        // Load sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                document.getElementById('sidebar').classList.add('collapsed');
            }

            // Initialize dropdowns
            initDropdowns();

            // Auto-refresh dashboard every 2 minutes
            setInterval(() => {
                refreshDashboardStats();
            }, 2 * 60 * 1000);
        });

        // Initialize dropdown functionality
        function initDropdowns() {
            // Add click handlers to all dropdown toggles
            const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleDropdown(this);
                });
            });

            // Add active class to current page in dropdowns
            const currentPath = window.location.pathname;
            const navItems = document.querySelectorAll('.dropdown-content .nav-item');
            navItems.forEach(item => {
                if (item.href && currentPath.includes(item.getAttribute('href').split('/').pop())) {
                    item.classList.add('active');
                    // Also expand parent dropdown
                    const parentDropdown = item.closest('.nav-dropdown');
                    if (parentDropdown) {
                        parentDropdown.classList.add('active');
                    }
                }
            });
        }

        // Search functionality
        let searchTimeout;

        function performSearch(searchTerm) {
            clearTimeout(searchTimeout);

            if (searchTerm.length < 2) {
                hideSearchResults();
                return;
            }

            searchTimeout = setTimeout(() => {
                fetchSearchResults(searchTerm);
            }, 300);
        }

        async function fetchSearchResults(searchTerm) {
            try {
                const response = await fetch(`<?php echo BASE_URL; ?>modules/shared/search_ajax.php?q=${encodeURIComponent(searchTerm)}&student_id=<?php echo $user_id; ?>`);
                const results = await response.json();
                displaySearchResults(results);
            } catch (error) {
                console.error('Search error:', error);
            }
        }

        function displaySearchResults(results) {
            const container = document.getElementById('searchResults');
            if (!container) return;

            container.innerHTML = '';

            if (!results || results.length === 0) {
                container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--gray);">No results found</div>';
                container.style.display = 'block';
                return;
            }

            results.forEach(result => {
                const item = document.createElement('a');
                item.href = result.url;
                item.className = 'search-result-item';
                item.innerHTML = `
                    <div class="search-result-icon">
                        <i class="${getSearchIcon(result.type)}"></i>
                    </div>
                    <div class="search-result-content">
                        <h4>${result.title}</h4>
                        <p>${result.description || ''}</p>
                    </div>
                `;
                container.appendChild(item);
            });

            container.style.display = 'block';
        }

        function getSearchIcon(type) {
            const icons = {
                'class': 'fas fa-chalkboard',
                'assignment': 'fas fa-tasks',
                'material': 'fas fa-book',
                'discussion': 'fas fa-comments',
                'announcement': 'fas fa-bullhorn',
                'user': 'fas fa-user',
                'default': 'fas fa-search'
            };
            return icons[type] || icons.default;
        }

        function hideSearchResults() {
            const container = document.getElementById('searchResults');
            if (container) {
                container.style.display = 'none';
            }
        }

        // Refresh dashboard stats
        async function refreshDashboardStats() {
            try {
                const response = await fetch(`<?php echo BASE_URL; ?>modules/student/ajax/dashboard_stats.php?student_id=<?php echo $user_id; ?>`);
                const data = await response.json();

                if (data.success) {
                    updateDashboardStats(data.stats);
                }
            } catch (error) {
                console.error('Error refreshing dashboard stats:', error);
            }
        }

        function updateDashboardStats(stats) {
            // Update financial stats
            if (stats.total_fee !== undefined) {
                document.querySelectorAll('.financial-value').forEach((el, index) => {
                    switch (index) {
                        case 0:
                            el.textContent = '₦' + stats.total_fee.toLocaleString('en-US', {
                                minimumFractionDigits: 2
                            });
                            break;
                        case 1:
                            el.textContent = '₦' + stats.paid_amount.toLocaleString('en-US', {
                                minimumFractionDigits: 2
                            });
                            break;
                        case 2:
                            el.textContent = '₦' + stats.balance.toLocaleString('en-US', {
                                minimumFractionDigits: 2
                            });
                            break;
                        case 3:
                            el.textContent = '₦' + stats.overdue_balance.toLocaleString('en-US', {
                                minimumFractionDigits: 2
                            });
                            break;
                    }
                });

                // Update progress bar
                const progressBar = document.querySelector('.progress-fill');
                if (progressBar && stats.total_fee > 0) {
                    const progress = (stats.paid_amount / stats.total_fee) * 100;
                    progressBar.style.width = progress + '%';
                    document.querySelector('.stat-card.accent div:last-child').textContent =
                        `Progress: ${progress.toFixed(1)}% paid`;
                }
            }

            // Update assignment counts
            if (stats.assignments_due !== undefined) {
                const assignmentBadges = document.querySelectorAll('.badge[style*="var(--warning)"]');
                assignmentBadges.forEach(badge => {
                    if (badge.textContent.includes('assignment')) {
                        badge.textContent = stats.assignments_due;
                    }
                });
            }

            // Update notification count
            if (stats.unread_notifications !== undefined) {
                const notificationBadges = document.querySelectorAll('.badge[style*="var(--warning)"]');
                notificationBadges.forEach(badge => {
                    if (badge.parentElement.querySelector('.fa-bell')) {
                        badge.textContent = stats.unread_notifications;
                    }
                });
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + D for dashboard
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/dashboard.php';
            }

            // Ctrl + C for classes
            if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/classes/';
            }

            // Ctrl + F for finance
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/dashboard.php';
            }

            // Ctrl + P for profile
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/profile/edit.php';
            }

            // Esc to close dropdowns
            if (e.key === 'Escape') {
                document.querySelectorAll('.nav-dropdown.active').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
                document.getElementById('userMenuDropdown').style.display = 'none';
                hideSearchResults();
            }

            // Ctrl + / to focus search
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                document.getElementById('globalSearch')?.focus();
            }
        });

        // Initialize tooltips
        function initTooltips() {
            const tooltips = document.querySelectorAll('[data-tooltip]');
            tooltips.forEach(element => {
                element.addEventListener('mouseenter', function(e) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = this.getAttribute('data-tooltip');
                    document.body.appendChild(tooltip);

                    const rect = this.getBoundingClientRect();
                    tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
                    tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';

                    this._tooltip = tooltip;
                });

                element.addEventListener('mouseleave', function(e) {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        delete this._tooltip;
                    }
                });
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initTooltips();
            initDropdowns();

            // Check for any alerts that need immediate attention
            const overdueBalance = <?php echo $stats['overdue_balance']; ?>;
            const isSuspended = <?php echo $stats['is_suspended'] ? 'true' : 'false'; ?>;

            if (isSuspended) {
                console.warn('Account suspended - immediate action required');
            } else if (overdueBalance > 0) {
                console.warn('Overdue payments detected - action recommended');
            }
        });

        // Export function for financial data
        function exportFinancialData() {
            window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/reports/export.php?type=dashboard&student_id=<?php echo $user_id; ?>';
        }

        // Quick payment function
        function quickPay(amount) {
            if (confirm(`Make payment of ₦${amount.toLocaleString()}?`)) {
                window.location.href = `<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?amount=${amount}`;
            }
        }

        // Join class meeting
        function joinClassMeeting(classId, meetingLink) {
            if (meetingLink) {
                window.open(meetingLink, '_blank');
            } else {
                alert('No meeting link available for this class.');
            }
        }

        // View assignment details
        function viewAssignment(assignmentId) {
            window.location.href = `<?php echo BASE_URL; ?>modules/student/classes/assignments/view.php?assignment_id=${assignmentId}`;
        }
        // Add this function to your existing JavaScript
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (sidebar.classList.contains('mobile-open')) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        }

        function openMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.createElement('div');
            overlay.id = 'sidebarOverlay';
            overlay.className = 'sidebar-overlay active';
            overlay.onclick = closeMobileMenu;

            document.body.appendChild(overlay);
            sidebar.classList.add('mobile-open');
            sidebar.classList.remove('collapsed'); // Make sure it's expanded on mobile
        }

        function closeMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (overlay) {
                overlay.remove();
            }
            sidebar.classList.remove('mobile-open');
        }

        // Update the existing toggleSidebar function to handle mobile
        function toggleSidebar() {
            if (window.innerWidth <= 768) {
                closeMobileMenu();
            } else {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.toggle('collapsed');

                // Save preference to localStorage
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed);
            }
        }

        // Add this to your existing DOMContentLoaded event listener
        document.addEventListener('DOMContentLoaded', function() {
            // ... existing code ...

            // Close mobile menu on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeMobileMenu();
                }
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768) {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');

                    if (overlay && !event.target.closest('.sidebar') &&
                        !event.target.closest('.mobile-menu-toggle')) {
                        closeMobileMenu();
                    }
                }
            });
        });

        // Update the toggleDropdown function to handle mobile
        function toggleDropdown(element) {
            const dropdown = element.closest('.nav-dropdown');
            dropdown.classList.toggle('active');

            // On mobile, don't auto-close other dropdowns for better UX
            if (window.innerWidth > 768) {
                const allDropdowns = document.querySelectorAll('.nav-dropdown.active');
                allDropdowns.forEach(otherDropdown => {
                    if (otherDropdown !== dropdown) {
                        otherDropdown.classList.remove('active');
                    }
                });
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                // On desktop, ensure mobile menu is closed
                closeMobileMenu();
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('mobile-open');

                // Restore collapsed state from localStorage
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                }
            } else {
                // On mobile, ensure sidebar is hidden by default
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('collapsed');
            }
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>