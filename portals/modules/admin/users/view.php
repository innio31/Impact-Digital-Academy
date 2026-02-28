<?php
// modules/admin/users/view.php

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

// Get user ID
$user_id = $_GET['id'] ?? 0;

if (!$user_id) {
    $_SESSION['error'] = 'No user specified.';
    header('Location: manage.php');
    exit();
}

// Fetch user data with comprehensive information
$sql = "SELECT 
    u.*, 
    up.*,
    COUNT(DISTINCT a.id) as total_applications,
    COUNT(DISTINCT CASE WHEN a.status = 'approved' THEN a.id END) as approved_applications,
    COUNT(DISTINCT CASE WHEN a.status = 'pending' THEN a.id END) as pending_applications,
    COUNT(DISTINCT e.id) as total_enrollments,
    COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.id END) as active_enrollments,
    COUNT(DISTINCT m.id) as total_assignments,
    COUNT(DISTINCT CASE WHEN asub.grade IS NOT NULL THEN asub.id END) as graded_assignments
FROM users u
LEFT JOIN user_profiles up ON u.id = up.user_id
LEFT JOIN applications a ON u.id = a.user_id
LEFT JOIN enrollments e ON u.id = e.student_id
LEFT JOIN class_batches cb ON e.class_id = cb.id
LEFT JOIN materials m ON cb.id = m.class_id
LEFT JOIN assignment_submissions asub ON u.id = asub.student_id
WHERE u.id = ?
GROUP BY u.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header('Location: manage.php');
    exit();
}

// Fetch user's applications
$apps_sql = "SELECT a.*, p.name as program_name, p.program_code, p.program_type 
             FROM applications a 
             LEFT JOIN programs p ON a.program_id = p.id 
             WHERE a.user_id = ? 
             ORDER BY a.created_at DESC";
$apps_stmt = $conn->prepare($apps_sql);
$apps_stmt->bind_param('i', $user_id);
$apps_stmt->execute();
$applications = $apps_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch user's enrollments
$enroll_sql = "SELECT e.*, cb.batch_code, cb.name as class_name, c.title as course_title,
                      p.name as program_name, p.program_code, i.first_name as instructor_first_name,
                      i.last_name as instructor_last_name
               FROM enrollments e
               JOIN class_batches cb ON e.class_id = cb.id
               JOIN courses c ON cb.course_id = c.id
               JOIN programs p ON c.program_id = p.id
               LEFT JOIN users i ON cb.instructor_id = i.id
               WHERE e.student_id = ?
               ORDER BY e.enrollment_date DESC";
$enroll_stmt = $conn->prepare($enroll_sql);
$enroll_stmt->bind_param('i', $user_id);
$enroll_stmt->execute();
$enrollments = $enroll_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch user's activity logs
$logs_sql = "SELECT * FROM activity_logs 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT 20";
$logs_stmt = $conn->prepare($logs_sql);
$logs_stmt->bind_param('i', $user_id);
$logs_stmt->execute();
$activity_logs = $logs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch user's notifications
$notif_sql = "SELECT * FROM notifications 
              WHERE user_id = ? 
              ORDER BY created_at DESC 
              LIMIT 10";
$notif_stmt = $conn->prepare($notif_sql);
$notif_stmt->bind_param('i', $user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'change_status':
                $new_status = $_POST['status'] ?? '';
                if (in_array($new_status, ['active', 'suspended', 'pending', 'rejected'])) {
                    $update_sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param('si', $new_status, $user_id);

                    if ($update_stmt->execute()) {
                        $_SESSION['success'] = "User status updated to $new_status.";

                        // Log activity
                        logActivity(
                            $_SESSION['user_id'],
                            'user_status_change',
                            "Changed user #$user_id status to $new_status",
                            'users',
                            $user_id
                        );
                    } else {
                        $_SESSION['error'] = 'Failed to update user status.';
                    }
                }
                break;

            case 'change_role':
                $new_role = $_POST['role'] ?? '';
                if (in_array($new_role, ['admin', 'instructor', 'student', 'applicant'])) {
                    $update_sql = "UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param('si', $new_role, $user_id);

                    if ($update_stmt->execute()) {
                        $_SESSION['success'] = "User role updated to $new_role.";

                        // Log activity
                        logActivity(
                            $_SESSION['user_id'],
                            'user_role_change',
                            "Changed user #$user_id role to $new_role",
                            'users',
                            $user_id
                        );
                    } else {
                        $_SESSION['error'] = 'Failed to update user role.';
                    }
                }
                break;

            case 'send_message':
                $subject = trim($_POST['message_subject'] ?? '');
                $message = trim($_POST['message_content'] ?? '');

                if (empty($subject) || empty($message)) {
                    $_SESSION['error'] = 'Subject and message are required.';
                } else {
                    // In a real application, this would send an email
                    // For now, we'll just log it
                    logActivity(
                        $_SESSION['user_id'],
                        'admin_message_sent',
                        "Sent message to user #$user_id: $subject",
                        'users',
                        $user_id
                    );

                    $_SESSION['success'] = 'Message has been sent to the user.';
                }
                break;

            case 'impersonate':
                // Create a unique impersonation token
                $impersonation_token = bin2hex(random_bytes(32));

                // Store impersonation data in database or session
                $token_sql = "INSERT INTO impersonation_tokens (admin_id, user_id, token, created_at, expires_at) 
                  VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
                $token_stmt = $conn->prepare($token_sql);
                $token_stmt->bind_param('iis', $_SESSION['user_id'], $user_id, $impersonation_token);
                $token_stmt->execute();

                // Log impersonation
                logActivity(
                    $_SESSION['user_id'],
                    'user_impersonation',
                    "Created impersonation token for user #$user_id",
                    'users',
                    $user_id
                );

                // Generate URL for impersonation
                $impersonate_url = BASE_URL . 'modules/auth/impersonate.php?token=' . $impersonation_token;

                // If this is an AJAX request, return the URL
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    echo json_encode(['url' => $impersonate_url]);
                    exit();
                }

                // Store URL in session for JavaScript to use
                $_SESSION['impersonate_url'] = $impersonate_url;

                $_SESSION['success'] = 'Impersonation link generated. It will open in a new tab.';
                break;
        }

        // Refresh page to show updated data
        header('Location: view.php?id=' . $user_id);
        exit();
    }
}

// Log viewing of user profile
logActivity($_SESSION['user_id'], 'user_view', "Viewed user profile #$user_id", 'users', $user_id);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> - User Profile</title>
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
            --admin: #8b5cf6;
            --instructor: #f59e0b;
            --student: #10b981;
            --applicant: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        /* Mobile-First Base Styles */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Sidebar - Mobile First */
        .sidebar {
            width: 100%;
            background: var(--dark);
            color: white;
            position: relative;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            color: white;
        }

        .menu-toggle {
            display: block;
            background: transparent;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        .sidebar-nav {
            display: none;
            max-height: 80vh;
            overflow-y: auto;
        }

        .sidebar-nav.show {
            display: block;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 0;
        }

        .sidebar-nav li {
            margin: 0;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.875rem 1rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
            width: 100%;
        }

        /* Header Section */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.25rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow-x: auto;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        /* User Header */
        .user-header {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .user-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            margin: 0 auto;
        }

        .user-info {
            text-align: center;
        }

        .user-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .role-badge,
        .status-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .user-email {
            font-size: 0.95rem;
            color: #64748b;
            margin-bottom: 1rem;
            word-break: break-word;
        }

        .user-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .meta-item {
            min-width: 120px;
        }

        .meta-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .user-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .user-actions form {
            width: 100%;
        }

        .btn {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 3px solid var(--primary);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tab Navigation */
        .tab-nav {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            background: white;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            white-space: nowrap;
        }

        .tab-nav::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            padding: 1rem;
            border: none;
            background: none;
            font-weight: 500;
            font-size: 0.9rem;
            color: #64748b;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            justify-content: center;
            min-width: max-content;
            border-bottom: 2px solid transparent;
        }

        .tab-btn i {
            font-size: 0.9rem;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Content Sections */
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .section-header {
            padding: 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-header h3 {
            color: var(--dark);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-body {
            padding: 1rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .info-item {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.5rem;
        }

        .info-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .info-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
            word-break: break-word;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -0.5rem;
            padding: 0 0.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #e0f2fe;
            color: #0369a1;
        }

        /* Form Styles */
        .action-form {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            -webkit-appearance: none;
            appearance: none;
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        /* Log Items */
        .log-item {
            padding: 0.75rem;
            border-left: 3px solid #e2e8f0;
            margin-bottom: 0.75rem;
            background: #f8fafc;
            border-radius: 0 8px 8px 0;
        }

        .log-time {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .log-action {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .log-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        /* Tab Content */
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

        /* Quick Actions Grid */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        /* Tablet Styles (min-width: 640px) */
        @media (min-width: 640px) {
            .main-content {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .user-header {
                flex-direction: row;
                text-align: left;
            }

            .user-avatar {
                margin: 0;
            }

            .user-info {
                text-align: left;
            }

            .user-name {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .user-actions {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .user-actions .btn {
                width: auto;
            }

            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Desktop Styles (min-width: 1024px) */
        @media (min-width: 1024px) {
            .admin-container {
                flex-direction: row;
            }

            .sidebar {
                width: 250px;
                height: 100vh;
                position: sticky;
                top: 0;
            }

            .sidebar-header {
                padding: 1.5rem;
            }

            .menu-toggle {
                display: none;
            }

            .sidebar-nav {
                display: block !important;
                max-height: none;
                overflow-y: auto;
            }

            .main-content {
                margin-left: 0;
                padding: 2rem;
            }

            .content-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1.5rem;
            }
        }

        /* Landscape Mode on Mobile */
        @media (max-width: 896px) and (orientation: landscape) {
            .sidebar-nav {
                max-height: 60vh;
            }

            .user-header {
                padding: 1rem;
            }

            .user-avatar {
                width: 70px;
                height: 70px;
                font-size: 1.5rem;
            }
        }

        /* Touch-friendly improvements */
        button,
        .btn,
        .tab-btn,
        select.form-control {
            min-height: 44px;
        }

        a,
        button {
            touch-action: manipulation;
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .tab-nav,
            .user-actions,
            .btn,
            .quick-actions {
                display: none;
            }

            .main-content {
                margin: 0;
                padding: 0;
            }

            .user-header {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header" onclick="toggleSidebar()">
                <h2>Impact Academy</h2>
                <button class="menu-toggle" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <nav class="sidebar-nav" id="sidebarNav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="active">
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> ›
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">Users</a> ›
                        Profile
                    </div>
                    <h1>User Profile</h1>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- User Header -->
            <div class="user-header">
                <div class="user-avatar">
                    <?php
                    $initials = strtoupper(
                        substr($user['first_name'], 0, 1) .
                            substr($user['last_name'], 0, 1)
                    );
                    echo $initials;
                    ?>
                </div>
                <div class="user-info">
                    <div class="user-name">
                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                        <span class="role-badge role-<?php echo $user['role']; ?>">
                            <?php echo ucfirst($user['role']); ?>
                        </span>
                        <span class="status-badge status-<?php echo $user['status']; ?>">
                            <?php echo ucfirst($user['status']); ?>
                        </span>
                    </div>
                    <div class="user-email">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                        <?php if ($user['phone']): ?>
                            <br class="mobile-only"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-meta">
                        <div class="meta-item">
                            <div class="meta-label">User ID</div>
                            <div class="meta-value">#<?php echo str_pad($user['id'], 4, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">Registered</div>
                            <div class="meta-value"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">Last Login</div>
                            <div class="meta-value">
                                <?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="user-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php?edit=<?php echo $user_id; ?>"
                            class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                        <?php if ($user['role'] !== 'admin'): ?>
                            <form method="POST" style="display: inline;" id="impersonate-form-<?php echo $user_id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="impersonate">
                                <button type="submit" class="btn btn-warning"
                                    onclick="return impersonateUser(event, <?php echo $user_id; ?>)">
                                    <i class="fas fa-user-secret"></i> Impersonate
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($user['status'] === 'active'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="status" value="suspended">
                                <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('Suspend this user? They will not be able to access the system.')">
                                    <i class="fas fa-pause"></i> Suspend
                                </button>
                            </form>
                        <?php elseif ($user['status'] === 'suspended'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="status" value="active">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-play"></i> Activate
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $user['total_applications']; ?></div>
                    <div class="stat-label">Applications</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $user['total_enrollments']; ?></div>
                    <div class="stat-label">Enrollments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $user['total_assignments']; ?></div>
                    <div class="stat-label">Assignments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($activity_logs); ?></div>
                    <div class="stat-label">Activities</div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button type="button" class="tab-btn active" onclick="showTab('profile')">
                    <i class="fas fa-user"></i> <span>Profile</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('applications')">
                    <i class="fas fa-file-alt"></i> <span>Applications</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('enrollments')">
                    <i class="fas fa-graduation-cap"></i> <span>Enrollments</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('activity')">
                    <i class="fas fa-history"></i> <span>Activity</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('actions')">
                    <i class="fas fa-cogs"></i> <span>Actions</span>
                </button>
            </div>

            <!-- Profile Details Tab -->
            <div id="profile-tab" class="tab-content active">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : 'Not provided'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value">
                                    <?php echo $user['date_of_birth'] ? date('M j, Y', strtotime($user['date_of_birth'])) : 'Not provided'; ?>
                                </div>
                            </div>
                            <?php if ($user['address'] || $user['city'] || $user['state']): ?>
                                <div class="info-item">
                                    <div class="info-label">Address</div>
                                    <div class="info-value">
                                        <?php
                                        $address_parts = [];
                                        if ($user['address']) $address_parts[] = $user['address'];
                                        if ($user['city']) $address_parts[] = $user['city'];
                                        if ($user['state']) $address_parts[] = $user['state'];
                                        if ($user['country']) $address_parts[] = $user['country'];
                                        echo htmlspecialchars(implode(', ', $address_parts));
                                        ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($user['bio'] || $user['qualifications']): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h3><i class="fas fa-info-circle"></i> Additional Information</h3>
                        </div>
                        <div class="section-body">
                            <?php if ($user['bio']): ?>
                                <div class="info-item">
                                    <div class="info-label">Bio</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($user['bio'])); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($user['qualifications']): ?>
                                <div class="info-item">
                                    <div class="info-label">Qualifications</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($user['qualifications'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Applications Tab -->
            <div id="applications-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-file-alt"></i> Applications</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($applications)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Program</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($app['program_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars(substr($app['program_name'], 0, 30)) . '...'; ?></small>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($app['created_at'])); ?></td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    if ($app['status'] === 'approved') $status_class = 'badge-success';
                                                    elseif ($app['status'] === 'rejected') $status_class = 'badge-danger';
                                                    elseif ($app['status'] === 'pending') $status_class = 'badge-warning';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <h3>No Applications</h3>
                                <p>This user has not submitted any applications.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enrollments Tab -->
            <div id="enrollments-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-graduation-cap"></i> Enrollments</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($enrollments)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Class</th>
                                            <th>Status</th>
                                            <th>Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrollments as $enroll): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($enroll['batch_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars(substr($enroll['class_name'], 0, 25)); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = $enroll['status'] === 'active' ? 'badge-success' : 'badge-info';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($enroll['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $enroll['final_grade'] ?? 'N/A'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap"></i>
                                <h3>No Enrollments</h3>
                                <p>This user is not enrolled in any courses.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Activity Tab -->
            <div id="activity-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($activity_logs)): ?>
                            <?php foreach ($activity_logs as $log): ?>
                                <div class="log-item">
                                    <div class="log-time">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('M j, g:i A', strtotime($log['created_at'])); ?>
                                    </div>
                                    <div class="log-action">
                                        <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                    </div>
                                    <?php if ($log['description']): ?>
                                        <div class="log-details">
                                            <?php echo htmlspecialchars(substr($log['description'], 0, 100)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No Activity</h3>
                                <p>No recent activity recorded.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Tab -->
            <div id="actions-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-cogs"></i> Quick Actions</h3>
                    </div>
                    <div class="section-body">
                        <!-- Change Status -->
                        <form method="POST" class="action-form" style="margin-bottom: 1rem;">
                            <h4 style="margin-bottom: 1rem;">Change Status</h4>
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="change_status">

                            <div class="form-group">
                                <select name="status" class="form-control">
                                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="rejected" <?php echo $user['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Status</button>
                        </form>

                        <!-- Change Role -->
                        <form method="POST" class="action-form" style="margin-bottom: 1rem;">
                            <h4 style="margin-bottom: 1rem;">Change Role</h4>
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="change_role">

                            <div class="form-group">
                                <select name="role" class="form-control">
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    <option value="instructor" <?php echo $user['role'] === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                                    <option value="student" <?php echo $user['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                    <option value="applicant" <?php echo $user['role'] === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Role</button>
                        </form>

                        <!-- Send Message -->
                        <form method="POST" class="action-form">
                            <h4 style="margin-bottom: 1rem;">Send Message</h4>
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="send_message">

                            <div class="form-group">
                                <input type="text" name="message_subject" class="form-control"
                                    placeholder="Subject" required>
                            </div>
                            <div class="form-group">
                                <textarea name="message_content" class="form-control"
                                    placeholder="Message..." rows="3" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebarNav');
            sidebar.classList.toggle('show');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebarNav');
            const menuToggle = document.querySelector('.menu-toggle');

            if (window.innerWidth < 1024) {
                if (!event.target.closest('.sidebar') && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Tab functionality
        function showTab(tabName, event) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Activate clicked tab button
            if (event) {
                event.target.closest('.tab-btn').classList.add('active');
            } else {
                // Find and activate the button for this tab
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    if (btn.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                        btn.classList.add('active');
                    }
                });
            }

            // Store active tab in session storage
            sessionStorage.setItem('activeTab', tabName);

            // Scroll to top of content on mobile
            if (window.innerWidth < 768) {
                document.querySelector('.main-content').scrollIntoView({
                    behavior: 'smooth'
                });
            }
        }

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem('activeTab') || 'profile';
            showTab(activeTab);

            // Add touch-friendly form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = this.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.style.borderColor = 'var(--danger)';
                            isValid = false;
                        } else {
                            field.style.borderColor = '#e2e8f0';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
        });

        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                document.activeElement.scrollIntoView({
                    behavior: 'smooth'
                });
            }, 100);
        });

        // Impersonate user function
        function impersonateUser(event, userId) {
            event.preventDefault();

            if (!confirm('Impersonate this user? A new tab will open where you will be logged in as them.')) {
                return false;
            }

            const form = document.getElementById('impersonate-form-' + userId);
            const formData = new FormData(form);
            const headers = new Headers();
            headers.append('X-Requested-With', 'XMLHttpRequest');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: headers
                })
                .then(response => response.json())
                .then(data => {
                    if (data.url) {
                        window.open(data.url, '_blank');
                        alert('Impersonation started in new tab.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error occurred while trying to impersonate user.');
                });

            return false;
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>