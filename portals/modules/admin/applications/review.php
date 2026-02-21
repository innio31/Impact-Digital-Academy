<?php
// modules/admin/applications/review.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get application ID
$application_id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? '';

if (!$application_id) {
    $_SESSION['error'] = 'No application specified.';
    header('Location: list.php');
    exit();
}

// Function to create enrollment from approved application
function createEnrollmentFromApplication($application_id, $conn)
{
    // Get application details
    $sql = "SELECT a.*, p.program_type, p.name as program_name 
            FROM applications a 
            JOIN programs p ON a.program_id = p.id 
            WHERE a.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app) return false;

    // Check if user is applying as a student
    if ($app['applying_as'] !== 'student') {
        return false; // Only create enrollment for students
    }

    // Check if program_id exists
    if (!$app['program_id']) {
        return false; // No program specified
    }

    // Get the first available class batch for this program
    $sql = "SELECT cb.id as class_id, cb.course_id, cb.start_date
            FROM class_batches cb
            JOIN courses c ON cb.course_id = c.id
            WHERE c.program_id = ? 
            AND cb.status = 'scheduled'
            AND cb.start_date >= CURDATE()
            ORDER BY cb.start_date ASC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $app['program_id']);
    $stmt->execute();
    $class_result = $stmt->get_result();

    if ($class_result->num_rows > 0) {
        $class = $class_result->fetch_assoc();

        // Check if enrollment already exists
        $check_sql = "SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $app['user_id'], $class['class_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $check_stmt->close();
            return false; // Enrollment already exists
        }
        $check_stmt->close();

        // Create enrollment
        $enrollment_sql = "INSERT INTO enrollments 
                          (student_id, class_id, enrollment_date, status, program_type)
                          VALUES (?, ?, CURDATE(), 'active', ?)";
        $enrollment_stmt = $conn->prepare($enrollment_sql);
        $enrollment_stmt->bind_param(
            "iis",
            $app['user_id'],
            $class['class_id'],
            $app['program_type']
        );
        $enrollment_result = $enrollment_stmt->execute();
        $enrollment_id = $enrollment_stmt->insert_id;
        $enrollment_stmt->close();

        if ($enrollment_result) {
            // Create initial financial status if table exists
            try {
                $financial_sql = "INSERT INTO student_financial_status 
                                 (student_id, class_id, total_fee, current_block)
                                 VALUES (?, ?, 
                                    (SELECT total_amount FROM fee_structures 
                                     WHERE program_id = ? AND is_active = 1 LIMIT 1),
                                    1)";
                $financial_stmt = $conn->prepare($financial_sql);
                $financial_stmt->bind_param(
                    "iii",
                    $app['user_id'],
                    $class['class_id'],
                    $app['program_id']
                );
                $financial_stmt->execute();
                $financial_stmt->close();
            } catch (Exception $e) {
                // Table might not exist, continue without financial record
                error_log("Financial status table not found: " . $e->getMessage());
            }

            return $enrollment_id;
        }
    }

    return false;
}

// Fetch application details
$sql = "SELECT 
    a.*, 
    u.first_name, 
    u.last_name, 
    u.email, 
    u.phone,
    u.role as user_role,
    u.status as user_status,
    p.name as program_name,
    p.program_code,
    p.program_type,
    p.fee,
    up.date_of_birth,
    up.gender,
    up.address,
    up.city,
    up.state,
    up.country,
    up.qualifications as profile_qualifications,
    up.experience_years,
    r.first_name as reviewer_first_name,
    r.last_name as reviewer_last_name
FROM applications a
LEFT JOIN users u ON a.user_id = u.id
LEFT JOIN user_profiles up ON u.id = up.user_id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN users r ON a.reviewed_by = r.id
WHERE a.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $application_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();

if (!$application) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: list.php');
    exit();
}

// Get user's previous applications
$prev_apps_sql = "SELECT * FROM applications WHERE user_id = ? AND id != ? ORDER BY created_at DESC";
$prev_stmt = $conn->prepare($prev_apps_sql);
$prev_stmt->bind_param('ii', $application['user_id'], $application_id);
$prev_stmt->execute();
$prev_apps = $prev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle status update
$success = false;
$error = '';
$enrollment_created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $new_status = $_POST['status'] ?? '';
        $review_notes = trim($_POST['review_notes'] ?? '');

        if (!in_array($new_status, ['pending', 'under_review', 'approved', 'rejected'])) {
            $error = 'Invalid status specified.';
        } else {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Update application
                $update_sql = "UPDATE applications 
                              SET status = ?, 
                                  reviewed_by = ?, 
                                  reviewed_at = NOW(),
                                  review_notes = CONCAT(IFNULL(review_notes, ''), ?, '\n---\n')
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $admin_notes = "\n[" . date('Y-m-d H:i') . "] " . $_SESSION['user_name'] . ": " . $review_notes;
                $update_stmt->bind_param('sisi', $new_status, $_SESSION['user_id'], $admin_notes, $application_id);
                $update_stmt->execute();

                // If approved, update user role and status
                if ($new_status === 'approved') {
                    $user_update_sql = "UPDATE users 
                                       SET role = ?, 
                                           status = 'active',
                                           updated_at = NOW()
                                       WHERE id = ?";
                    $user_stmt = $conn->prepare($user_update_sql);
                    $user_role = $application['applying_as']; // 'student' or 'instructor'
                    $user_stmt->bind_param('si', $user_role, $application['user_id']);
                    $user_stmt->execute();

                    // Create enrollment for student applications
                    $enrollment_id = false;
                    if ($application['applying_as'] === 'student' && $application['program_id']) {
                        $enrollment_id = createEnrollmentFromApplication($application_id, $conn);
                        $enrollment_created = ($enrollment_id !== false);

                        // Add enrollment note to review notes if enrollment was created
                        if ($enrollment_created) {
                            $enrollment_note = "\n[" . date('Y-m-d H:i') . "] SYSTEM: Student automatically enrolled (Enrollment ID: #" . $enrollment_id . ")";
                            $update_notes_sql = "UPDATE applications SET review_notes = CONCAT(review_notes, ?) WHERE id = ?";
                            $update_notes_stmt = $conn->prepare($update_notes_sql);
                            $update_notes_stmt->bind_param('si', $enrollment_note, $application_id);
                            $update_notes_stmt->execute();
                            $update_notes_stmt->close();
                        }
                    }

                    // Create notification for user with appropriate message
                    $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                        VALUES (?, ?, ?, 'system', ?)";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $title = "Application Approved";

                    // Customize message based on enrollment status
                    if ($application['applying_as'] === 'student') {
                        if ($enrollment_created) {
                            $message = "Congratulations! Your application has been approved and you have been automatically enrolled in the " .
                                htmlspecialchars($application['program_name']) . " program. You can now access your student dashboard.";
                        } else {
                            $message = "Your application has been approved. However, no available class batches were found for automatic enrollment. " .
                                "Please contact the administration for further instructions.";
                        }
                    } else {
                        $message = "Your application has been approved. You can now access your dashboard as an instructor.";
                    }

                    $notification_stmt->bind_param('issi', $application['user_id'], $title, $message, $application_id);
                    $notification_stmt->execute();

                    // ===== ADD APPROVAL EMAIL NOTIFICATION =====
                    // Send approval email
                    if (function_exists('sendApplicationApprovalEmail')) {
                        $email_sent = sendApplicationApprovalEmail($application['user_id']);
                        if (!$email_sent) {
                            // Log email failure but don't stop the process
                            error_log("Failed to send approval email to user ID: " . $application['user_id']);
                        }
                    }
                    // ===== END APPROVAL EMAIL NOTIFICATION =====
                }

                // If rejected, update user status
                if ($new_status === 'rejected') {
                    $user_update_sql = "UPDATE users 
                                       SET status = 'rejected',
                                           updated_at = NOW()
                                       WHERE id = ?";
                    $user_stmt = $conn->prepare($user_update_sql);
                    $user_stmt->bind_param('i', $application['user_id']);
                    $user_stmt->execute();

                    // Create notification for user
                    $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                        VALUES (?, ?, ?, 'system', ?)";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $title = "Application Status Update";
                    $message = "Your application has been reviewed and unfortunately was not approved at this time.";
                    $notification_stmt->bind_param('issi', $application['user_id'], $title, $message, $application_id);
                    $notification_stmt->execute();

                    // ===== ADD REJECTION EMAIL NOTIFICATION =====
                    // Get rejection reason from form
                    $rejection_reason = $_POST['review_notes'] ?? '';

                    // Send rejection email
                    if (function_exists('sendApplicationRejectionEmail')) {
                        $email_sent = sendApplicationRejectionEmail($application['user_id'], $rejection_reason);
                        if (!$email_sent) {
                            // Log email failure but don't stop the process
                            error_log("Failed to send rejection email to user ID: " . $application['user_id']);
                        }
                    }
                    // ===== END REJECTION EMAIL NOTIFICATION =====
                }

                $conn->commit();
                $success = true;

                // Log activity
                $log_message = "Application #$application_id marked as $new_status";
                if ($enrollment_created) {
                    $log_message .= " (automatic enrollment created)";
                }

                logActivity(
                    $_SESSION['user_id'],
                    'application_review',
                    $log_message,
                    'applications',
                    $application_id
                );

                // Redirect with appropriate parameters
                $redirect_url = 'review.php?id=' . $application_id . '&updated=1';
                if ($enrollment_created) {
                    $redirect_url .= '&enrolled=1';
                }
                header('Location: ' . $redirect_url);
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to update application: ' . $e->getMessage();
                error_log("Application update error: " . $e->getMessage());
            }
        }
    }
}

// Log viewing of application
logActivity(
    $_SESSION['user_id'],
    'view_application',
    "Viewed application #$application_id",
    'applications',
    $application_id
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Application #<?php echo $application_id; ?> - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .application-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .applicant-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .applicant-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .applicant-details h2 {
            font-size: 1.3rem;
            margin-bottom: 0.25rem;
        }

        .applicant-details p {
            color: #64748b;
            font-size: 0.9rem;
        }

        .application-meta {
            display: flex;
            gap: 2rem;
            text-align: right;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 500;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-under-review {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
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
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .section-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header i {
            color: var(--primary);
        }

        .section-body {
            padding: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            margin-bottom: 1rem;
        }

        .info-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
        }

        .text-content {
            line-height: 1.6;
            color: var(--dark);
            white-space: pre-wrap;
        }

        .text-content.empty {
            color: #94a3b8;
            font-style: italic;
        }

        .review-form {
            margin-top: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
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
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
        }

        .history-item {
            padding: 1rem;
            border-left: 3px solid #e2e8f0;
            margin-bottom: 1rem;
            background: #f8fafc;
        }

        .history-item:last-child {
            margin-bottom: 0;
        }

        .history-item.current {
            border-left-color: var(--primary);
            background: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .history-date {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .history-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .history-notes {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark);
            white-space: pre-wrap;
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
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.35rem;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 2px solid white;
        }

        .timeline-item.current::before {
            background: var(--primary);
        }

        .timeline-date {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .application-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .application-meta {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="active">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                            <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">
                            <i class="fas fa-history"></i> Activity Logs</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php">
                            <i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">Applications</a> &rsaquo;
                        Review Application
                    </div>
                    <h1>Review Application #<?php echo str_pad($application_id, 4, '0', STR_PAD_LEFT); ?></h1>
                </div>
                <div class="action-buttons">
                    <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Application updated successfully!</strong>
                        <?php if (isset($_GET['enrolled']) && $application['applying_as'] === 'student'): ?>
                            <div style="margin-top: 0.5rem;">
                                <i class="fas fa-user-graduate"></i> Student has been automatically enrolled in the next available class batch.
                            </div>
                        <?php elseif ($application['applying_as'] === 'student' && $application['program_id']): ?>
                            <div style="margin-top: 0.5rem; color: #92400e;">
                                <i class="fas fa-exclamation-triangle"></i> No available class batches found for automatic enrollment. Student should contact administration.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Application status updated successfully!
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Application Header -->
            <div class="application-header">
                <div class="applicant-info">
                    <div class="applicant-avatar">
                        <?php echo strtoupper(substr($application['first_name'], 0, 1) . substr($application['last_name'], 0, 1)); ?>
                    </div>
                    <div class="applicant-details">
                        <h2><?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?></h2>
                        <p><?php echo htmlspecialchars($application['email']); ?> • <?php echo $application['phone'] ?? 'No phone'; ?></p>
                        <p>Applying as: <strong><?php echo ucfirst($application['applying_as']); ?></strong></p>
                    </div>
                </div>
                <div class="application-meta">
                    <div class="meta-item">
                        <div class="meta-label">Status</div>
                        <div class="meta-value">
                            <span class="status-badge status-<?php echo str_replace('_', '-', $application['status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $application['status'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Submitted</div>
                        <div class="meta-value"><?php echo date('M j, Y g:i A', strtotime($application['created_at'])); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Last Updated</div>
                        <div class="meta-value"><?php echo $application['updated_at'] ? date('M j, Y g:i A', strtotime($application['updated_at'])) : 'N/A'; ?></div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column: Application Details -->
                <div>
                    <!-- Personal Information -->
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-user"></i> Personal Information</h3>
                        </div>
                        <div class="section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Full Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo htmlspecialchars($application['email']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Phone</div>
                                    <div class="info-value"><?php echo $application['phone'] ? htmlspecialchars($application['phone']) : 'Not provided'; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Date of Birth</div>
                                    <div class="info-value"><?php echo $application['date_of_birth'] ? date('M j, Y', strtotime($application['date_of_birth'])) : 'Not provided'; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Gender</div>
                                    <div class="info-value"><?php echo $application['gender'] ? ucfirst($application['gender']) : 'Not provided'; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <?php if ($application['address'] || $application['city'] || $application['state']): ?>
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-map-marker-alt"></i> Address Information</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Address</div>
                                        <div class="info-value"><?php echo $application['address'] ? htmlspecialchars($application['address']) : 'Not provided'; ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">City</div>
                                        <div class="info-value"><?php echo $application['city'] ? htmlspecialchars($application['city']) : 'Not provided'; ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">State</div>
                                        <div class="info-value"><?php echo $application['state'] ? htmlspecialchars($application['state']) : 'Not provided'; ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Country</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['country'] ?? 'Nigeria'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Program Information -->
                    <?php if ($application['applying_as'] === 'student' && $application['program_name']): ?>
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-graduation-cap"></i> Program Information</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Program</div>
                                        <div class="info-value">
                                            <strong><?php echo htmlspecialchars($application['program_code']); ?></strong> -
                                            <?php echo htmlspecialchars($application['program_name']); ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Program Type</div>
                                        <div class="info-value"><?php echo ucfirst($application['program_type'] ?? 'Not specified'); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Program Fee</div>
                                        <div class="info-value">₦<?php echo number_format($application['fee'], 2); ?></div>
                                    </div>
                                </div>
                                <?php if ($application['status'] === 'approved'): ?>
                                    <div style="margin-top: 1rem; padding: 1rem; background: #f0f9ff; border-radius: 6px; border-left: 4px solid var(--primary);">
                                        <strong><i class="fas fa-info-circle"></i> Automatic Enrollment</strong>
                                        <p style="margin-top: 0.5rem; font-size: 0.9rem;">
                                            When this application is approved, the student will be automatically enrolled in the next available class batch for this program.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Application Content -->
                    <?php if ($application['motivation'] || $application['qualifications'] || $application['experience']): ?>
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-file-alt"></i> Application Content</h3>
                            </div>
                            <div class="section-body">
                                <?php if ($application['motivation']): ?>
                                    <div style="margin-bottom: 1.5rem;">
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Motivation Statement</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($application['qualifications']): ?>
                                    <div style="margin-bottom: 1.5rem;">
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Qualifications</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['qualifications'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($application['experience']): ?>
                                    <div>
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark);">Experience</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['experience'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Review Form -->
                    <div class="section-card" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <h3><i class="fas fa-clipboard-check"></i> Review & Decision</h3>
                        </div>
                        <div class="section-body">
                            <form method="POST" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                                <div class="form-group">
                                    <label for="status">Update Status</label>
                                    <select name="status" id="status" class="form-control" required>
                                        <option value="pending" <?php echo $application['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="under_review" <?php echo $application['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                        <option value="approved" <?php echo $application['status'] === 'approved' ? 'selected' : ''; ?>>Approve</option>
                                        <option value="rejected" <?php echo $application['status'] === 'rejected' ? 'selected' : ''; ?>>Reject</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="review_notes">Review Notes (Internal)</label>
                                    <textarea name="review_notes" id="review_notes" class="form-control"
                                        placeholder="Add notes about your review decision..."></textarea>
                                </div>

                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Application
                                    </button>
                                    <?php if ($application['status'] === 'pending'): ?>
                                        <button type="submit" name="status" value="under_review" class="btn btn-secondary">
                                            <i class="fas fa-search"></i> Mark as Under Review
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-success" onclick="confirmApprove()">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="confirmReject()">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Review Information -->
                <div>
                    <!-- Application Timeline -->
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-history"></i> Application Timeline</h3>
                        </div>
                        <div class="section-body">
                            <div class="timeline">
                                <div class="timeline-item current">
                                    <div class="timeline-date"><?php echo date('M j, Y g:i A', strtotime($application['created_at'])); ?></div>
                                    <div class="timeline-content">
                                        <strong>Application Submitted</strong>
                                        <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                            Application received and marked as pending
                                        </p>
                                    </div>
                                </div>

                                <?php if ($application['reviewed_at']): ?>
                                    <div class="timeline-item current">
                                        <div class="timeline-date"><?php echo date('M j, Y g:i A', strtotime($application['reviewed_at'])); ?></div>
                                        <div class="timeline-content">
                                            <strong>Status Updated</strong>
                                            <p style="margin-top: 0.5rem; color: #64748b; font-size: 0.9rem;">
                                                Marked as <?php echo $application['status']; ?> by
                                                <?php echo $application['reviewer_first_name'] . ' ' . $application['reviewer_last_name']; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Review History -->
                    <?php if ($application['review_notes']): ?>
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-clipboard-list"></i> Review History</h3>
                            </div>
                            <div class="section-body">
                                <?php
                                $notes = explode('---', $application['review_notes']);
                                foreach ($notes as $note):
                                    if (trim($note)):
                                ?>
                                        <div class="history-item">
                                            <div class="text-content"><?php echo nl2br(htmlspecialchars(trim($note))); ?></div>
                                        </div>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Previous Applications -->
                    <?php if (!empty($prev_apps)): ?>
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-copy"></i> Previous Applications</h3>
                            </div>
                            <div class="section-body">
                                <?php foreach ($prev_apps as $prev): ?>
                                    <div class="history-item">
                                        <div class="history-date"><?php echo date('M j, Y', strtotime($prev['created_at'])); ?></div>
                                        <div class="history-status status-<?php echo str_replace('_', '-', $prev['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $prev['status'])); ?>
                                        </div>
                                        <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                                            <?php if ($prev['program_id']): ?>
                                                Program: <?php echo $prev['program_name'] ?? 'Unknown'; ?>
                                            <?php else: ?>
                                                Applying as: <?php echo ucfirst($prev['applying_as']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Quick Actions -->
                    <div class="section-card" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                        </div>
                        <div class="section-body">
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <a href="mailto:<?php echo urlencode($application['email']); ?>" class="btn btn-secondary" style="justify-content: center;">
                                    <i class="fas fa-envelope"></i> Email Applicant
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $application['user_id']; ?>" class="btn btn-secondary" style="justify-content: center;">
                                    <i class="fas fa-user"></i> View User Profile
                                </a>
                                <?php if ($application['applying_as'] === 'student' && $application['program_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $application['program_id']; ?>" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-graduation-cap"></i> View Program
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/class-batches/?program_id=<?php echo $application['program_id']; ?>" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-calendar-alt"></i> View Class Batches
                                    </a>
                                <?php endif; ?>
                                <a href="#" onclick="window.print()" class="btn btn-secondary" style="justify-content: center;">
                                    <i class="fas fa-print"></i> Print Application
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Confirmation for approve action
        function confirmApprove() {
            const isStudent = <?php echo $application['applying_as'] === 'student' ? 'true' : 'false'; ?>;
            const hasProgram = <?php echo !empty($application['program_id']) ? 'true' : 'false'; ?>;

            let message = 'Are you sure you want to approve this application?';

            if (isStudent && hasProgram) {
                message += '\n\nThis will automatically enroll the student in the next available class batch for this program.';
            }

            if (confirm(message)) {
                document.getElementById('status').value = 'approved';
                document.querySelector('.review-form').submit();
            }
        }

        // Confirmation for reject action
        function confirmReject() {
            if (confirm('Are you sure you want to reject this application?')) {
                document.getElementById('status').value = 'rejected';
                document.querySelector('.review-form').submit();
            }
        }

        // Auto-save review notes (optional enhancement)
        let autoSaveTimer;
        const reviewNotes = document.getElementById('review_notes');

        if (reviewNotes) {
            reviewNotes.addEventListener('input', function() {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => {
                    // Save to localStorage
                    localStorage.setItem('review_notes_<?php echo $application_id; ?>', this.value);
                }, 1000);
            });

            // Load saved notes
            const savedNotes = localStorage.getItem('review_notes_<?php echo $application_id; ?>');
            if (savedNotes && !reviewNotes.value) {
                reviewNotes.value = savedNotes;
            }
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>