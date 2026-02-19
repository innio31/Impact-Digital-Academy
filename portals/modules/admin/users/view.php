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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        .user-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        @media (max-width: 768px) {
            .user-header {
                flex-direction: column;
                text-align: center;
            }
        }

        .user-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-email {
            font-size: 1rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .user-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1rem;
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
            color: var(--dark);
        }

        .user-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.5rem 1.5rem;
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

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
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
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: #ede9fe;
            color: #5b21b6;
        }

        .role-instructor {
            background: #fef3c7;
            color: #92400e;
        }

        .role-student {
            background: #d1fae5;
            color: #065f46;
        }

        .role-applicant {
            background: #e2e8f0;
            color: #475569;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-rejected {
            background: #f1f5f9;
            color: #64748b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-card.applications {
            border-top-color: var(--accent);
        }

        .stat-card.enrollments {
            border-top-color: var(--success);
        }

        .stat-card.assignments {
            border-top-color: var(--primary);
        }

        .stat-card.activity {
            border-top-color: var(--warning);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .action-form {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .action-form h4 {
            margin-bottom: 0.75rem;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            color: #64748b;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .log-item {
            padding: 0.75rem;
            border-left: 3px solid #e2e8f0;
            margin-bottom: 0.75rem;
            background: #f8fafc;
        }

        .log-item:last-child {
            margin-bottom: 0;
        }

        .log-time {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .log-action {
            font-weight: 500;
            color: var(--dark);
        }

        .log-details {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 0.25rem;
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

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .tab-nav {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .tab-btn {
            padding: 1rem 1.5rem;
            border: none;
            background: none;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            background: white;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
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

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
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

            .user-actions {
                flex-direction: column;
            }

            .user-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-nav {
                flex-direction: column;
            }

            .tab-btn {
                padding: 0.75rem 1rem;
                justify-content: center;
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">Users</a> &rsaquo;
                        User Profile
                    </div>
                    <h1>User Profile</h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
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
                            &nbsp; • &nbsp; <i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone']); ?>
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
                                <?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?>
                            </div>
                        </div>
                        <?php if ($user['city'] && $user['state']): ?>
                            <div class="meta-item">
                                <div class="meta-label">Location</div>
                                <div class="meta-value"><?php echo htmlspecialchars($user['city'] . ', ' . $user['state']); ?></div>
                            </div>
                        <?php endif; ?>
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
                                    onclick="impersonateUser(event, <?php echo $user_id; ?>)">
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
                <div class="stat-card applications">
                    <div class="stat-number"><?php echo $user['total_applications']; ?></div>
                    <div class="stat-label">Applications</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo $user['approved_applications']; ?> approved
                    </div>
                </div>
                <div class="stat-card enrollments">
                    <div class="stat-number"><?php echo $user['total_enrollments']; ?></div>
                    <div class="stat-label">Enrollments</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo $user['active_enrollments']; ?> active
                    </div>
                </div>
                <div class="stat-card assignments">
                    <div class="stat-number"><?php echo $user['total_assignments']; ?></div>
                    <div class="stat-label">Assignments</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo $user['graded_assignments']; ?> graded
                    </div>
                </div>
                <div class="stat-card activity">
                    <div class="stat-number"><?php echo count($activity_logs); ?></div>
                    <div class="stat-label">Recent Activities</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        Last 20 actions
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button type="button" class="tab-btn active" onclick="showTab('profile')">
                    <i class="fas fa-user"></i> Profile Details
                </button>
                <button type="button" class="tab-btn" onclick="showTab('applications')">
                    <i class="fas fa-file-alt"></i> Applications
                </button>
                <button type="button" class="tab-btn" onclick="showTab('enrollments')">
                    <i class="fas fa-graduation-cap"></i> Enrollments
                </button>
                <button type="button" class="tab-btn" onclick="showTab('activity')">
                    <i class="fas fa-history"></i> Activity
                </button>
                <button type="button" class="tab-btn" onclick="showTab('actions')">
                    <i class="fas fa-cogs"></i> Quick Actions
                </button>
            </div>

            <!-- Profile Details Tab -->
            <div id="profile-tab" class="tab-content active">
                <div class="content-grid">
                    <!-- Left Column: Personal Information -->
                    <div>
                        <!-- Personal Details -->
                        <div class="section-card" style="margin-bottom: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-user"></i> Personal Information</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Full Name</div>
                                        <div class="info-value">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        </div>
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
                                    <div class="info-item">
                                        <div class="info-label">Gender</div>
                                        <div class="info-value">
                                            <?php echo $user['gender'] ? ucfirst($user['gender']) : 'Not specified'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <?php if ($user['address'] || $user['city'] || $user['state']): ?>
                            <div class="section-card" style="margin-bottom: 1.5rem;">
                                <div class="section-header">
                                    <h3><i class="fas fa-map-marker-alt"></i> Address Information</h3>
                                </div>
                                <div class="section-body">
                                    <div class="info-grid">
                                        <?php if ($user['address']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Address</div>
                                                <div class="info-value"><?php echo nl2br(htmlspecialchars($user['address'])); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['city']): ?>
                                            <div class="info-item">
                                                <div class="info-label">City</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['city']); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['state']): ?>
                                            <div class="info-item">
                                                <div class="info-label">State/Province</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['state']); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['country']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Country</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['country']); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Professional Information -->
                        <?php if ($user['qualifications'] || $user['experience_years'] || $user['current_job_title']): ?>
                            <div class="section-card">
                                <div class="section-header">
                                    <h3><i class="fas fa-briefcase"></i> Professional Information</h3>
                                </div>
                                <div class="section-body">
                                    <div class="info-grid">
                                        <?php if ($user['experience_years']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Years of Experience</div>
                                                <div class="info-value"><?php echo $user['experience_years']; ?> years</div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['current_job_title']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Current Job Title</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['current_job_title']); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['current_company']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Current Company</div>
                                                <div class="info-value"><?php echo htmlspecialchars($user['current_company']); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($user['qualifications']): ?>
                                        <div style="margin-top: 1.5rem;">
                                            <div class="info-label">Qualifications</div>
                                            <div class="text-content" style="margin-top: 0.5rem;">
                                                <?php echo nl2br(htmlspecialchars($user['qualifications'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Online Presence & Bio -->
                    <div>
                        <!-- Bio -->
                        <?php if ($user['bio']): ?>
                            <div class="section-card" style="margin-bottom: 1.5rem;">
                                <div class="section-header">
                                    <h3><i class="fas fa-globe"></i> Bio / About Me</h3>
                                </div>
                                <div class="section-body">
                                    <div class="text-content">
                                        <?php echo nl2br(htmlspecialchars($user['bio'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Online Presence -->
                        <?php if ($user['website'] || $user['linkedin_url'] || $user['github_url']): ?>
                            <div class="section-card">
                                <div class="section-header">
                                    <h3><i class="fas fa-share-alt"></i> Online Presence</h3>
                                </div>
                                <div class="section-body">
                                    <div class="info-grid">
                                        <?php if ($user['website']): ?>
                                            <div class="info-item">
                                                <div class="info-label">Website</div>
                                                <div class="info-value">
                                                    <a href="<?php echo htmlspecialchars($user['website']); ?>" target="_blank"
                                                        style="color: var(--primary); text-decoration: none;">
                                                        <?php echo htmlspecialchars(parse_url($user['website'], PHP_URL_HOST) ?: $user['website']); ?>
                                                        <i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-left: 0.25rem;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['linkedin_url']): ?>
                                            <div class="info-item">
                                                <div class="info-label">LinkedIn</div>
                                                <div class="info-value">
                                                    <a href="<?php echo htmlspecialchars($user['linkedin_url']); ?>" target="_blank"
                                                        style="color: var(--primary); text-decoration: none;">
                                                        LinkedIn Profile
                                                        <i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-left: 0.25rem;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($user['github_url']): ?>
                                            <div class="info-item">
                                                <div class="info-label">GitHub</div>
                                                <div class="info-value">
                                                    <a href="<?php echo htmlspecialchars($user['github_url']); ?>" target="_blank"
                                                        style="color: var(--primary); text-decoration: none;">
                                                        GitHub Profile
                                                        <i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-left: 0.25rem;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Account Information -->
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-user-cog"></i> Account Information</h3>
                            </div>
                            <div class="section-body">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Account Created</div>
                                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Last Updated</div>
                                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user['updated_at'])); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Email Verified</div>
                                        <div class="info-value">
                                            <?php echo $user['email_verified_at'] ? date('M j, Y', strtotime($user['email_verified_at'])) : 'Not verified'; ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Last Login</div>
                                        <div class="info-value">
                                            <?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never logged in'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Applications Tab -->
            <div id="applications-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-file-alt"></i> User Applications</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($applications)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Applying As</th>
                                            <th>Program</th>
                                            <th>Submitted</th>
                                            <th>Status</th>
                                            <th>Reviewed</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>#<?php echo str_pad($app['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo ucfirst($app['applying_as']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['program_name']): ?>
                                                        <strong><?php echo htmlspecialchars($app['program_code']); ?></strong><br>
                                                        <small style="color: #64748b;"><?php echo htmlspecialchars($app['program_name']); ?></small>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($app['created_at'])); ?><br>
                                                    <small style="color: #64748b;"><?php echo date('g:i A', strtotime($app['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    if ($app['status'] === 'approved') $status_class = 'badge-success';
                                                    elseif ($app['status'] === 'rejected') $status_class = 'badge-danger';
                                                    elseif ($app['status'] === 'pending') $status_class = 'badge-warning';
                                                    else $status_class = 'badge-info';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['reviewed_at']): ?>
                                                        <?php echo date('M j, Y', strtotime($app['reviewed_at'])); ?><br>
                                                        <small style="color: #64748b;"><?php echo date('g:i A', strtotime($app['reviewed_at'])); ?></small>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">Not reviewed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i> View
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
                                <h3>No Applications Found</h3>
                                <p>This user has not submitted any applications yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enrollments Tab -->
            <div id="enrollments-tab" class="tab-content">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-graduation-cap"></i> Course Enrollments</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($enrollments)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Class</th>
                                            <th>Program</th>
                                            <th>Instructor</th>
                                            <th>Enrollment Date</th>
                                            <th>Status</th>
                                            <th>Completion</th>
                                            <th>Final Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrollments as $enroll): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($enroll['batch_code']); ?></strong><br>
                                                    <small style="color: #64748b;"><?php echo htmlspecialchars($enroll['class_name']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($enroll['program_code']); ?></strong><br>
                                                    <small style="color: #64748b;"><?php echo htmlspecialchars($enroll['program_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($enroll['instructor_first_name']): ?>
                                                        <?php echo htmlspecialchars($enroll['instructor_first_name'] . ' ' . $enroll['instructor_last_name']); ?>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">Not assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($enroll['enrollment_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    if ($enroll['status'] === 'active') $status_class = 'badge-success';
                                                    elseif ($enroll['status'] === 'completed') $status_class = 'badge-info';
                                                    elseif ($enroll['status'] === 'dropped') $status_class = 'badge-danger';
                                                    else $status_class = 'badge-warning';
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($enroll['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($enroll['completion_date']): ?>
                                                        <?php echo date('M j, Y', strtotime($enroll['completion_date'])); ?>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">In progress</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($enroll['final_grade']): ?>
                                                        <span style="font-weight: bold; color: var(--success);">
                                                            <?php echo $enroll['final_grade']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #64748b;">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap"></i>
                                <h3>No Enrollments Found</h3>
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
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($activity_logs as $log): ?>
                                    <div class="log-item">
                                        <div class="log-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                        <div class="log-action">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                        </div>
                                        <?php if ($log['description']): ?>
                                            <div class="log-details">
                                                <?php echo htmlspecialchars($log['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($log['user_ip']): ?>
                                            <div class="log-details" style="font-size: 0.75rem;">
                                                IP: <?php echo htmlspecialchars($log['user_ip']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h3>No Activity Found</h3>
                                <p>No recent activity recorded for this user.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Notifications -->
                <div class="section-card" style="margin-top: 1.5rem;">
                    <div class="section-header">
                        <h3><i class="fas fa-bell"></i> Recent Notifications</h3>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($notifications)): ?>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="log-item" style="background: <?php echo $notif['is_read'] ? '#f8fafc' : '#e0f2fe'; ?>;">
                                        <div class="log-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?>
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="badge badge-info" style="margin-left: 0.5rem;">New</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="log-action">
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                        </div>
                                        <div class="log-details">
                                            <?php echo htmlspecialchars($notif['message']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 1rem;">
                                <i class="fas fa-bell-slash"></i>
                                <h4>No Notifications</h4>
                                <p>No notifications found for this user.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Tab -->
            <div id="actions-tab" class="tab-content">
                <div class="content-grid">
                    <!-- Left Column: Account Actions -->
                    <div>
                        <!-- Change Status -->
                        <div class="section-card" style="margin-bottom: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-user-cog"></i> Account Status</h3>
                            </div>
                            <div class="section-body">
                                <form method="POST" class="action-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="change_status">

                                    <div class="form-group">
                                        <label for="status">Change Account Status</label>
                                        <select id="status" name="status" class="form-control">
                                            <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="pending" <?php echo $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                            <option value="rejected" <?php echo $user['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Status
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Change Role -->
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-user-tag"></i> User Role</h3>
                            </div>
                            <div class="section-body">
                                <form method="POST" class="action-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="change_role">

                                    <div class="form-group">
                                        <label for="role">Change User Role</label>
                                        <select id="role" name="role" class="form-control">
                                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                            <option value="instructor" <?php echo $user['role'] === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                                            <option value="student" <?php echo $user['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                            <option value="applicant" <?php echo $user['role'] === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Role
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Communication -->
                    <div>
                        <!-- Send Message -->
                        <div class="section-card">
                            <div class="section-header">
                                <h3><i class="fas fa-envelope"></i> Send Message</h3>
                            </div>
                            <div class="section-body">
                                <form method="POST" class="action-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="send_message">

                                    <div class="form-group">
                                        <label for="message_subject">Subject</label>
                                        <input type="text" id="message_subject" name="message_subject" class="form-control"
                                            placeholder="Message subject" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="message_content">Message</label>
                                        <textarea id="message_content" name="message_content" class="form-control"
                                            placeholder="Type your message here..." rows="4" required></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Send Message
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div class="section-card" style="margin-top: 1.5rem;">
                            <div class="section-header">
                                <h3><i class="fas fa-link"></i> Quick Links</h3>
                            </div>
                            <div class="section-body">
                                <div class="quick-actions">
                                    <a href="mailto:<?php echo urlencode($user['email']); ?>" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-envelope"></i> Email User
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php?edit=<?php echo $user_id; ?>"
                                        class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-edit"></i> Edit Profile
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-arrow-left"></i> Back to Users List
                                    </a>
                                    <button type="button" onclick="window.print()" class="btn btn-secondary" style="justify-content: center;">
                                        <i class="fas fa-print"></i> Print Profile
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        function showTab(tabName) {
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
            event.target.classList.add('active');

            // Store active tab in session storage
            sessionStorage.setItem('activeTab', tabName);
        }

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem('activeTab') || 'profile';
            showTab(activeTab);

            // Form validation for message sending
            const messageForm = document.querySelector('form[action="send_message"]');
            if (messageForm) {
                messageForm.addEventListener('submit', function(e) {
                    const subject = this.querySelector('#message_subject').value.trim();
                    const content = this.querySelector('#message_content').value.trim();

                    if (!subject || !content) {
                        e.preventDefault();
                        alert('Please fill in both subject and message fields.');
                        return false;
                    }

                    if (!confirm('Send this message to the user?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Form validation for status/role changes
            const statusForm = document.querySelector('form[action="change_status"]');
            const roleForm = document.querySelector('form[action="change_role"]');

            if (statusForm) {
                statusForm.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to change the user status?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            if (roleForm) {
                roleForm.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to change the user role?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });

        // Print user profile
        function printProfile() {
            const printContent = document.querySelector('.user-header').outerHTML +
                document.querySelector('#profile-tab').outerHTML;
            const originalContent = document.body.innerHTML;

            document.body.innerHTML = printContent;
            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }

        function impersonateUser(event, userId) {
            event.preventDefault();

            if (!confirm('Impersonate this user? A new tab will open where you will be logged in as them.')) {
                return false;
            }

            // Submit the form via AJAX to generate the token
            const form = document.getElementById('impersonate-form-' + userId);
            const formData = new FormData(form);

            // Add AJAX header
            const headers = new Headers();
            headers.append('X-Requested-With', 'XMLHttpRequest');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: headers
                })
                .then(response => {
                    if (response.headers.get('content-type')?.includes('application/json')) {
                        return response.json();
                    }
                    return response.text();
                })
                .then(data => {
                    if (typeof data === 'object' && data.url) {
                        // If we got a JSON response with URL, open it immediately
                        window.open(data.url, '_blank');
                        // Show success message
                        alert('Impersonation started in new tab. You can continue working here as admin.');
                    } else {
                        // Fallback: reload the page and check for session URL
                        window.location.reload();
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