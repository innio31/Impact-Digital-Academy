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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Review Application #<?php echo $application_id; ?> - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
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
            --pending: #f59e0b;
            --under-review: #8b5cf6;
            --approved: #10b981;
            --rejected: #ef4444;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
            --safe-left: env(safe-area-inset-left);
            --safe-right: env(safe-area-inset-right);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            overflow-x: hidden;
        }

        html {
            height: -webkit-fill-available;
        }

        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            background: var(--dark);
            color: white;
            padding: 0.75rem 1rem;
            padding-top: max(0.75rem, var(--safe-top));
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .mobile-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mobile-header-left h2 {
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .mobile-header-left p {
            color: #94a3b8;
            font-size: 0.75rem;
            margin-top: 0.1rem;
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: background-color 0.2s;
        }

        .mobile-menu-btn:active {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Mobile Sidebar */
        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -100%;
            width: min(85%, 320px);
            height: 100vh;
            height: -webkit-fill-available;
            background: var(--dark);
            color: white;
            z-index: 1000;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            padding-bottom: var(--safe-bottom);
        }

        .mobile-sidebar.active {
            left: 0;
        }

        .mobile-sidebar-header {
            padding: 1.5rem 1.25rem;
            padding-top: max(1.5rem, var(--safe-top));
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mobile-sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .mobile-sidebar-header p {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .close-sidebar {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .close-sidebar:active {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(3px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Desktop Sidebar */
        .sidebar {
            display: none;
            width: 250px;
            background: var(--dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .main-content {
            flex: 1;
            padding: 1rem;
            padding-bottom: calc(1rem + var(--safe-bottom));
        }

        @media (min-width: 1024px) {
            .mobile-header {
                display: none;
            }

            .sidebar {
                display: block;
            }

            .main-content {
                margin-left: 250px;
                padding: 2rem;
            }
        }

        .sidebar-header {
            padding: 1.5rem 1.5rem 1.5rem;
            padding-top: max(1.5rem, var(--safe-top));
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.9rem;
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
            padding: 0.875rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.95rem;
            min-height: 48px;
        }

        .sidebar-nav a:active {
            background: rgba(255, 255, 255, 0.1);
        }

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

        /* Header Section */
        .header {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:active {
            text-decoration: underline;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.3rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-top: 1px solid #e2e8f0;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .header-content {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .user-info {
                border-top: none;
                padding: 0;
            }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-buttons .btn {
            flex: 1;
        }

        @media (min-width: 640px) {
            .action-buttons .btn {
                flex: none;
            }
        }

        /* Application Header */
        .application-header {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
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
            flex-shrink: 0;
        }

        .applicant-details h2 {
            font-size: 1.2rem;
            margin-bottom: 0.25rem;
        }

        .applicant-details p {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .application-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .meta-value {
            font-weight: 500;
            font-size: 0.9rem;
        }

        @media (min-width: 768px) {
            .application-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .application-meta {
                padding-top: 0;
                border-top: none;
                gap: 2rem;
            }
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.8rem;
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

        /* Content Grid */
        .content-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 2rem;
            }
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .section-card:last-child {
            margin-bottom: 0;
        }

        .section-header {
            padding: 1rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header i {
            color: var(--primary);
            font-size: 1rem;
        }

        .section-body {
            padding: 1.25rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.5rem;
        }

        .info-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
            word-break: break-word;
        }

        .text-content {
            line-height: 1.6;
            color: var(--dark);
            white-space: pre-wrap;
            font-size: 0.95rem;
            word-break: break-word;
        }

        .text-content.empty {
            color: #94a3b8;
            font-style: italic;
        }

        /* Review Form */
        .review-form {
            margin-top: 0;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s;
            min-height: 48px;
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        /* Button Group */
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .btn-group {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            min-height: 48px;
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:active {
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

        .btn-secondary:active {
            background: #cbd5e1;
        }

        .btn-sm {
            padding: 0.6rem 0.75rem;
            font-size: 0.9rem;
            min-height: 40px;
        }

        .btn-block-mobile {
            width: 100%;
        }

        @media (min-width: 640px) {
            .btn-block-mobile {
                width: auto;
            }
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .quick-actions .btn {
            width: 100%;
            justify-content: center;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 6px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.75rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 2px solid white;
            z-index: 1;
        }

        .timeline-item.current::before {
            background: var(--primary);
        }

        .timeline-date {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .timeline-content strong {
            font-size: 0.95rem;
        }

        .timeline-content p {
            font-size: 0.85rem;
            margin-top: 0.25rem;
            color: #64748b;
        }

        /* History Items */
        .history-item {
            padding: 0.75rem;
            border-left: 3px solid #e2e8f0;
            margin-bottom: 0.75rem;
            background: #f8fafc;
            border-radius: 0 8px 8px 0;
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
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .history-status {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .history-notes {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--dark);
            white-space: pre-wrap;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.95rem;
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

        .alert i {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        /* Info Box */
        .info-box {
            margin-top: 1rem;
            padding: 1rem;
            background: #f0f9ff;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .info-box strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #0369a1;
        }

        .info-box p {
            font-size: 0.9rem;
            color: #334155;
            line-height: 1.5;
        }

        /* Touch Optimizations */
        * {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }

        input,
        textarea,
        select,
        button,
        a {
            -webkit-touch-callout: default;
            -webkit-user-select: text;
            user-select: text;
        }

        body {
            overscroll-behavior-y: contain;
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .mobile-header,
            .action-buttons,
            .btn-group,
            .quick-actions,
            .review-form {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .application-header,
            .section-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #e2e8f0;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <div class="mobile-header-content">
                <div class="mobile-header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h2>Impact Academy</h2>
                        <p>Admin Dashboard</p>
                    </div>
                </div>
                <div class="user-avatar" style="width: 40px; height: 40px; font-size: 1rem;">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>
        </div>

        <!-- Mobile Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <!-- Mobile Sidebar -->
        <div class="mobile-sidebar" id="mobileSidebar">
            <div class="mobile-sidebar-header">
                <div>
                    <h2>Impact Academy</h2>
                    <p>Admin Dashboard</p>
                </div>
                <button class="close-sidebar" onclick="toggleSidebar()" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
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

        <!-- Desktop Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Admin Dashboard</p>
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
                <div class="header-content">
                    <div>
                        <div class="breadcrumb">
                            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                            <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">Applications</a> &rsaquo;
                            Review #<?php echo str_pad($application_id, 4, '0', STR_PAD_LEFT); ?>
                        </div>
                        <h1>Review Application</h1>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo $_SESSION['user_name'] ?? 'Admin'; ?></div>
                            <div style="font-size: 0.85rem; color: #64748b;">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons" style="margin-bottom: 1rem;">
                <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="#" onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </a>
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
                                <i class="fas fa-exclamation-triangle"></i> No available class batches found for automatic enrollment.
                            </div>
                        <?php endif; ?>
                    </div>
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
                        <p><i class="fas fa-envelope" style="width: 16px;"></i> <?php echo htmlspecialchars($application['email']); ?></p>
                        <p><i class="fas fa-phone" style="width: 16px;"></i> <?php echo $application['phone'] ?? 'No phone'; ?></p>
                        <p><i class="fas fa-user-tag" style="width: 16px;"></i> Applying as: <strong><?php echo ucfirst($application['applying_as']); ?></strong></p>
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
                        <div class="meta-value"><?php echo date('M j, Y', strtotime($application['created_at'])); ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?php echo date('g:i A', strtotime($application['created_at'])); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Last Updated</div>
                        <div class="meta-value"><?php echo $application['updated_at'] ? date('M j, Y', strtotime($application['updated_at'])) : 'N/A'; ?></div>
                    </div>
                    <?php if ($application['reviewed_by']): ?>
                        <div class="meta-item">
                            <div class="meta-label">Reviewed By</div>
                            <div class="meta-value"><?php echo htmlspecialchars($application['reviewer_first_name'] . ' ' . $application['reviewer_last_name']); ?></div>
                        </div>
                    <?php endif; ?>
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
                        <div class="section-card">
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
                        <div class="section-card">
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
                                        <div class="info-value">₦<?php echo number_format($application['fee'] ?? 0, 2); ?></div>
                                    </div>
                                </div>
                                <?php if ($application['status'] === 'approved'): ?>
                                    <div class="info-box">
                                        <strong><i class="fas fa-info-circle"></i> Automatic Enrollment</strong>
                                        <p>When this application is approved, the student will be automatically enrolled in the next available class batch for this program.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Application Content -->
                    <?php if ($application['motivation'] || $application['qualifications'] || $application['experience']): ?>
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-file-alt"></i> Application Content</h3>
                            </div>
                            <div class="section-body">
                                <?php if ($application['motivation']): ?>
                                    <div style="margin-bottom: 1.5rem;">
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.95rem;">Motivation Statement</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($application['qualifications']): ?>
                                    <div style="margin-bottom: 1.5rem;">
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.95rem;">Qualifications</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['qualifications'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($application['experience']): ?>
                                    <div>
                                        <h4 style="margin-bottom: 0.5rem; color: var(--dark); font-size: 0.95rem;">Experience</h4>
                                        <div class="text-content"><?php echo nl2br(htmlspecialchars($application['experience'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Review Form -->
                    <div class="section-card">
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
                                        placeholder="Add notes about your review decision..."><?php echo htmlspecialchars($review_notes ?? ''); ?></textarea>
                                </div>

                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update
                                    </button>
                                    <?php if ($application['status'] === 'pending'): ?>
                                        <button type="submit" name="status" value="under_review" class="btn btn-secondary">
                                            <i class="fas fa-search"></i> Start Review
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
                                        <p>Application received and marked as pending</p>
                                    </div>
                                </div>

                                <?php if ($application['reviewed_at']): ?>
                                    <div class="timeline-item current">
                                        <div class="timeline-date"><?php echo date('M j, Y g:i A', strtotime($application['reviewed_at'])); ?></div>
                                        <div class="timeline-content">
                                            <strong>Status Updated</strong>
                                            <p>
                                                Marked as <?php echo $application['status']; ?> by
                                                <?php echo htmlspecialchars($application['reviewer_first_name'] . ' ' . $application['reviewer_last_name']); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Review History -->
                    <?php if ($application['review_notes']): ?>
                        <div class="section-card">
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
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-copy"></i> Previous Applications</h3>
                            </div>
                            <div class="section-body">
                                <?php foreach ($prev_apps as $prev): ?>
                                    <div class="history-item">
                                        <div class="history-date"><?php echo date('M j, Y', strtotime($prev['created_at'])); ?></div>
                                        <span class="history-status status-<?php echo str_replace('_', '-', $prev['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $prev['status'])); ?>
                                        </span>
                                        <div style="margin-top: 0.5rem; font-size: 0.85rem;">
                                            <?php if ($prev['program_id']): ?>
                                                Program: <?php echo htmlspecialchars($prev['program_name'] ?? 'Unknown'); ?>
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
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                        </div>
                        <div class="section-body">
                            <div class="quick-actions">
                                <a href="mailto:<?php echo urlencode($application['email']); ?>" class="btn btn-secondary">
                                    <i class="fas fa-envelope"></i> Email Applicant
                                </a>
                                <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $application['user_id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-user"></i> View User Profile
                                </a>
                                <?php if ($application['applying_as'] === 'student' && $application['program_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?id=<?php echo $application['program_id']; ?>" class="btn btn-secondary">
                                        <i class="fas fa-graduation-cap"></i> View Program
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/class-batches/?program_id=<?php echo $application['program_id']; ?>" class="btn btn-secondary">
                                        <i class="fas fa-calendar-alt"></i> View Class Batches
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');

            // Prevent body scroll when sidebar is open
            if (sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Close sidebar when clicking a link (on mobile)
        document.querySelectorAll('.mobile-sidebar .sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                toggleSidebar();
            });
        });

        // Confirmation for approve action
        function confirmApprove() {
            const isStudent = <?php echo $application['applying_as'] === 'student' ? 'true' : 'false'; ?>;
            const hasProgram = <?php echo !empty($application['program_id']) ? 'true' : 'false'; ?>;

            let message = 'Are you sure you want to approve this application?';

            if (isStudent && hasProgram) {
                message += '\n\nThis will automatically enroll the student in the next available class batch.';
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

        // Auto-save review notes
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

        // Swipe to close sidebar
        let touchStartX = 0;
        document.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, false);

        document.addEventListener('touchend', function(e) {
            const sidebar = document.getElementById('mobileSidebar');
            const touchEndX = e.changedTouches[0].screenX;
            const diffX = touchEndX - touchStartX;

            if (sidebar.classList.contains('active') && diffX < -50) {
                toggleSidebar();
            }
        }, false);

        // Touch-friendly optimizations
        document.querySelectorAll('select, .btn, a').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            element.addEventListener('touchend', function() {
                this.style.opacity = '';
            });
        });

        // Prevent zoom on double-tap
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
    </script>
</body>

</html>
<?php $conn->close(); ?>