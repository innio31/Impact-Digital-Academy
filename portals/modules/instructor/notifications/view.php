<?php
// modules/instructor/notifications/view.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Initialize variables
$single_notification = null;
$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$total_notifications = 0;
$unread_count = 0;
$notifications = [];
$notification_stats = [];
$recent_activity = [];

// Mark single notification as read if ID is provided
if ($notification_id > 0) {
    $sql = "UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $instructor_id);
    $stmt->execute();
    $stmt->close();

    // Get the single notification
    $sql = "SELECT n.*, 
                   DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted,
                   DATE_FORMAT(n.read_at, '%Y-%m-%d %H:%i:%s') as read_at_formatted
            FROM notifications n 
            WHERE n.id = ? AND n.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $single_notification = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $sql = "UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = ? AND is_read = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = 'All notifications marked as read.';
    } elseif (isset($_POST['delete_all_read'])) {
        $sql = "DELETE FROM notifications 
                WHERE user_id = ? AND is_read = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = 'All read notifications deleted.';
    } elseif (isset($_POST['selected_notifications']) && is_array($_POST['selected_notifications'])) {
        $action = $_POST['bulk_action'] ?? '';
        $selected_ids = array_map('intval', $_POST['selected_notifications']);

        if ($action === 'mark_read' && !empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $sql = "UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE id IN ($placeholders) AND user_id = ?";
            $stmt = $conn->prepare($sql);

            // Build parameters: IDs + user_id
            $types = str_repeat('i', count($selected_ids)) . 'i';
            $params = array_merge($selected_ids, [$instructor_id]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Selected notifications marked as read.';
        } elseif ($action === 'delete' && !empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $sql = "DELETE FROM notifications 
                    WHERE id IN ($placeholders) AND user_id = ?";
            $stmt = $conn->prepare($sql);

            $types = str_repeat('i', count($selected_ids)) . 'i';
            $params = array_merge($selected_ids, [$instructor_id]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Selected notifications deleted.';
        }
    }

    // Redirect to avoid form resubmission
    header('Location: ' . BASE_URL . 'modules/instructor/notifications/view.php' . ($notification_id > 0 ? '?id=' . $notification_id : ''));
    exit();
}

// Get total notifications count
$sql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_notifications = $row['total'];
$stmt->close();

// Get unread count
$sql = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$unread_count = $row['unread'];
$stmt->close();

// Calculate total pages
$total_pages = ceil($total_notifications / $limit);

// Get notifications with pagination (only if not viewing single notification)
if (!$single_notification) {
    $sql = "SELECT n.*, 
                   DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') as created_at_formatted,
                   DATE_FORMAT(n.read_at, '%Y-%m-%d %H:%i:%s') as read_at_formatted
            FROM notifications n 
            WHERE n.user_id = ? 
            ORDER BY n.is_read ASC, n.created_at DESC 
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $instructor_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get notification statistics by type
$sql = "SELECT type, COUNT(*) as count, 
               SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM notifications 
        WHERE user_id = ? 
        GROUP BY type";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notification_stats[] = $row;
}
$stmt->close();

// Get recent notification activity
$sql = "SELECT DATE(created_at) as date, COUNT(*) as count
        FROM notifications 
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_activity[] = $row;
}
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $single_notification ? 'View Notification' : 'My Notifications'; ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --sidebar-width: 280px;
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: var(--light);
            color: var(--dark);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light-gray);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 4px solid var(--info);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.total {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .stat-icon.unread {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-icon.system {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.assignment {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        /* Single Notification View */
        .notification-detail {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .notification-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-system {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-assignment {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-grade {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-announcement {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .badge-message {
            background: #f0f9ff;
            color: #0369a1;
        }

        .notification-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .notification-meta {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
            font-size: 0.875rem;
        }

        .notification-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notification-content {
            background: var(--light);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .notification-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light-gray);
        }

        /* Notifications List */
        .notifications-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .notifications-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bulk-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: 1rem;
        }

        .bulk-select select {
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            background: white;
        }

        .notifications-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: background 0.3s ease;
        }

        .notification-item:hover {
            background: var(--light);
        }

        .notification-item.unread {
            background: rgba(59, 130, 246, 0.05);
        }

        .notification-checkbox {
            flex-shrink: 0;
            margin-top: 0.25rem;
        }

        .notification-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .notification-icon.system {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .notification-icon.assignment {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .notification-icon.grade {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .notification-icon.announcement {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .notification-icon.message {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .notification-content-small {
            flex: 1;
        }

        .notification-title-small {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-message {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .notification-meta-small {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray);
        }

        .notification-actions-small {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
        }

        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .btn-mark {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .no-notifications {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .no-notifications i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
            opacity: 0.5;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            background: white;
            color: var(--dark);
            text-decoration: none;
            border: 1px solid var(--light-gray);
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-item.active .page-link {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-item.disabled .page-link {
            background: var(--light);
            color: var(--gray);
            cursor: not-allowed;
        }

        /* Filter Section */
        .filters {
            background: var(--light);
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            background: white;
        }

        .filter-group select {
            min-width: 150px;
        }

        /* Sidebar Layout */
        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }
        }

        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            height: fit-content;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-section h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .notification-type-list {
            list-style: none;
        }

        .notification-type-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .notification-type-item:last-child {
            border-bottom: none;
        }

        .type-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Notification Settings */
        .settings-list {
            list-style: none;
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--light-gray);
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: var(--success);
        }

        input:checked+.slider:before {
            transform: translateX(26px);
        }

        /* Back button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .back-button:hover {
            color: var(--secondary);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Back Button -->
        <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Header -->
        <div class="header">
            <div>
                <h1>
                    <?php if ($single_notification): ?>
                        <i class="fas fa-bell"></i> Notification Details
                    <?php else: ?>
                        <i class="fas fa-bell"></i> My Notifications
                    <?php endif; ?>
                </h1>
                <p style="color: var(--gray); margin-top: 0.5rem;">
                    <?php if ($single_notification): ?>
                        Viewing a single notification
                    <?php else: ?>
                        <?php echo $total_notifications; ?> total notifications • <?php echo $unread_count; ?> unread
                    <?php endif; ?>
                </p>
            </div>

            <div class="header-actions">
                <?php if (!$single_notification): ?>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-success">
                            <i class="fas fa-check-double"></i> Mark All Read
                        </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete all read notifications?');">
                        <button type="submit" name="delete_all_read" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Read
                        </button>
                    </form>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/view.php" class="btn btn-secondary">
                        <i class="fas fa-list"></i> All Notifications
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Success Message -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success'];
                unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if ($single_notification): ?>
            <!-- Single Notification View -->
            <div class="notification-detail">
                <div class="notification-header">
                    <div>
                        <span class="notification-type-badge badge-<?php echo $single_notification['type']; ?>">
                            <i class="fas fa-<?php echo $single_notification['type'] === 'assignment' ? 'tasks' : ($single_notification['type'] === 'grade' ? 'graduation-cap' : ($single_notification['type'] === 'announcement' ? 'bullhorn' : 'bell')); ?>"></i>
                            <?php echo ucfirst($single_notification['type']); ?> Notification
                        </span>
                        <?php if ($single_notification['is_read']): ?>
                            <span style="margin-left: 1rem; color: var(--success); font-size: 0.875rem;">
                                <i class="fas fa-check"></i> Read
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="color: var(--gray); font-size: 0.875rem;">
                        ID: #<?php echo $single_notification['id']; ?>
                    </div>
                </div>

                <h2 class="notification-title"><?php echo htmlspecialchars($single_notification['title']); ?></h2>

                <div class="notification-meta">
                    <div class="notification-meta-item">
                        <i class="fas fa-clock"></i>
                        Received: <?php echo date('F j, Y g:i A', strtotime($single_notification['created_at_formatted'])); ?>
                    </div>
                    <?php if ($single_notification['read_at_formatted']): ?>
                        <div class="notification-meta-item">
                            <i class="fas fa-eye"></i>
                            Read: <?php echo date('F j, Y g:i A', strtotime($single_notification['read_at_formatted'])); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($single_notification['related_id']): ?>
                        <div class="notification-meta-item">
                            <i class="fas fa-link"></i>
                            Reference ID: <?php echo $single_notification['related_id']; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="notification-content">
                    <?php echo nl2br(htmlspecialchars($single_notification['message'])); ?>
                </div>

                <div class="notification-actions">
                    <?php if ($single_notification['related_id']): ?>
                        <?php
                        $action_url = '';
                        switch ($single_notification['type']) {
                            case 'assignment':
                                $action_url = BASE_URL . 'modules/instructor/assignments/grade.php?id=' . $single_notification['related_id'];
                                break;
                            case 'grade':
                                $action_url = BASE_URL . 'modules/instructor/gradebook/view.php?assignment_id=' . $single_notification['related_id'];
                                break;
                            case 'announcement':
                                $action_url = BASE_URL . 'modules/instructor/announcements/view.php?id=' . $single_notification['related_id'];
                                break;
                            case 'message':
                                $action_url = BASE_URL . 'modules/shared/mail/view.php?id=' . $single_notification['related_id'];
                                break;
                        }

                        if ($action_url): ?>
                            <a href="<?php echo $action_url; ?>" class="btn btn-primary">
                                <i class="fas fa-external-link-alt"></i> View Related Item
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/view.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>

                    <form method="POST" action="<?php echo BASE_URL; ?>modules/instructor/notifications/delete.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this notification?');">
                        <input type="hidden" name="notification_id" value="<?php echo $single_notification['id']; ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Notifications List View -->
            <div class="content-wrapper">
                <div class="main-content">
                    <?php if (!empty($notifications)): ?>
                        <div class="notifications-container">
                            <!-- Bulk Actions -->
                            <form method="POST" class="notifications-header">
                                <div class="bulk-actions">
                                    <div class="bulk-select">
                                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                                        <label for="select-all">Select All</label>
                                    </div>

                                    <select name="bulk_action" class="bulk-action-select" required>
                                        <option value="">Bulk Action</option>
                                        <option value="mark_read">Mark as Read</option>
                                        <option value="delete">Delete</option>
                                    </select>

                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return validateBulkAction()">
                                        <i class="fas fa-play"></i> Apply
                                    </button>
                                </div>
                            </form>

                            <!-- Notifications List -->
                            <form method="POST" id="notifications-form">
                                <div class="notifications-list">
                                    <?php foreach ($notifications as $notification): ?>
                                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                            <div class="notification-checkbox">
                                                <input type="checkbox" name="selected_notifications[]" value="<?php echo $notification['id']; ?>" class="notification-check">
                                            </div>

                                            <div class="notification-icon <?php echo $notification['type']; ?>">
                                                <i class="fas fa-<?php echo $notification['type'] === 'assignment' ? 'tasks' : ($notification['type'] === 'grade' ? 'graduation-cap' : ($notification['type'] === 'announcement' ? 'bullhorn' : 'bell')); ?>"></i>
                                            </div>

                                            <div class="notification-content-small">
                                                <div class="notification-title-small">
                                                    <span><?php echo htmlspecialchars($notification['title']); ?></span>
                                                    <span class="notification-time" style="font-size: 0.75rem; color: var(--gray);">
                                                        <?php echo time_ago($notification['created_at_formatted']); ?>
                                                    </span>
                                                </div>

                                                <div class="notification-message">
                                                    <?php echo truncate_text(htmlspecialchars($notification['message']), 150); ?>
                                                </div>

                                                <div class="notification-meta-small">
                                                    <span>
                                                        <i class="fas fa-<?php echo $notification['type'] === 'assignment' ? 'tasks' : ($notification['type'] === 'grade' ? 'graduation-cap' : ($notification['type'] === 'announcement' ? 'bullhorn' : 'bell')); ?>"></i>
                                                        <?php echo ucfirst($notification['type']); ?>
                                                    </span>
                                                    <span>
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo date('M j, Y', strtotime($notification['created_at_formatted'])); ?>
                                                    </span>
                                                    <?php if ($notification['is_read']): ?>
                                                        <span style="color: var(--success);">
                                                            <i class="fas fa-check"></i> Read
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: var(--danger);">
                                                            <i class="fas fa-circle"></i> Unread
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="notification-actions-small">
                                                <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/view.php?id=<?php echo $notification['id']; ?>"
                                                    class="btn-icon btn-view" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>

                                                <?php if (!$notification['is_read']): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/mark_read.php?id=<?php echo $notification['id']; ?>"
                                                        class="btn-icon btn-mark" title="Mark as Read">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <a href="<?php echo BASE_URL; ?>modules/instructor/notifications/delete.php?id=<?php echo $notification['id']; ?>"
                                                    class="btn-icon btn-delete" title="Delete" onclick="return confirm('Are you sure?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </form>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </span>
                                        </li>
                                    <?php endif; ?>

                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $page + 2);

                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </span>
                                        </li>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- No Notifications -->
                        <div class="no-notifications">
                            <i class="fas fa-bell-slash"></i>
                            <h3 style="margin-bottom: 1rem; color: var(--gray);">No notifications yet</h3>
                            <p style="color: var(--gray); margin-bottom: 1.5rem;">
                                You don't have any notifications at the moment.
                            </p>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-primary">
                                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="sidebar">
                    <!-- Statistics -->
                    <div class="sidebar-section">
                        <h3><i class="fas fa-chart-bar"></i> Notification Stats</h3>
                        <div style="margin-bottom: 1rem;">
                            <div style="margin-bottom: 0.5rem;">
                                <span style="color: var(--gray);">Total: </span>
                                <span style="font-weight: 600;"><?php echo $total_notifications; ?></span>
                            </div>
                            <div style="margin-bottom: 0.5rem;">
                                <span style="color: var(--gray);">Unread: </span>
                                <span style="font-weight: 600; color: var(--danger);"><?php echo $unread_count; ?></span>
                            </div>
                            <div>
                                <span style="color: var(--gray);">Read: </span>
                                <span style="font-weight: 600; color: var(--success);"><?php echo $total_notifications - $unread_count; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Notification Types -->
                    <div class="sidebar-section">
                        <h3><i class="fas fa-tags"></i> By Type</h3>
                        <ul class="notification-type-list">
                            <?php foreach ($notification_stats as $stat): ?>
                                <li class="notification-type-item">
                                    <span>
                                        <i class="fas fa-<?php echo $stat['type'] === 'assignment' ? 'tasks' : ($stat['type'] === 'grade' ? 'graduation-cap' : ($stat['type'] === 'announcement' ? 'bullhorn' : 'bell')); ?>"></i>
                                        <?php echo ucfirst($stat['type']); ?>
                                    </span>
                                    <span class="type-badge" style="background: <?php echo $stat['type'] === 'assignment' ? '#d1fae5' : ($stat['type'] === 'grade' ? '#dbeafe' : ($stat['type'] === 'announcement' ? '#f3e8ff' : '#fef3c7')); ?>; 
                                           color: <?php echo $stat['type'] === 'assignment' ? '#065f46' : ($stat['type'] === 'grade' ? '#1e40af' : ($stat['type'] === 'announcement' ? '#6b21a8' : '#92400e')); ?>;">
                                        <?php echo $stat['count']; ?>
                                        <?php if ($stat['unread'] > 0): ?>
                                            <span style="color: var(--danger); margin-left: 0.25rem;">(<?php echo $stat['unread']; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Quick Actions -->
                    <div class="sidebar-section">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <a href="javascript:void(0)" onclick="markAllAsRead()" class="btn btn-success btn-sm" style="text-align: left;">
                                <i class="fas fa-check-double"></i> Mark All as Read
                            </a>
                            <a href="javascript:void(0)" onclick="clearAllNotifications()" class="btn btn-danger btn-sm" style="text-align: left;">
                                <i class="fas fa-trash"></i> Clear All Read
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-primary btn-sm" style="text-align: left;">
                                <i class="fas fa-home"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <?php if (!empty($recent_activity)): ?>
                        <div class="sidebar-section">
                            <h3><i class="fas fa-history"></i> Recent Activity</h3>
                            <div style="font-size: 0.875rem;">
                                <?php foreach (array_slice($recent_activity, 0, 5) as $activity): ?>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--light-gray);">
                                        <span><?php echo date('M j', strtotime($activity['date'])); ?></span>
                                        <span style="font-weight: 600;"><?php echo $activity['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Bulk Actions
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.notification-check');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        function validateBulkAction() {
            const selected = document.querySelectorAll('.notification-check:checked');
            const action = document.querySelector('.bulk-action-select').value;

            if (selected.length === 0) {
                alert('Please select at least one notification.');
                return false;
            }

            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }

            if (action === 'delete') {
                return confirm(`Are you sure you want to delete ${selected.length} notification(s)?`);
            }

            return true;
        }

        // Quick Actions
        function markAllAsRead() {
            if (confirm('Mark all notifications as read?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="mark_all_read" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function clearAllNotifications() {
            if (confirm('Delete all read notifications?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="delete_all_read" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Auto-refresh every 2 minutes
        setInterval(() => {
            if (!document.hidden && !window.location.search.includes('id=')) {
                window.location.reload();
            }
        }, 120000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }

            // Ctrl + A to select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                document.getElementById('select-all')?.click();
            }

            // Esc to go back
            if (e.key === 'Escape') {
                if (window.location.search.includes('id=')) {
                    window.location.href = '<?php echo BASE_URL; ?>modules/instructor/notifications/view.php';
                } else {
                    window.location.href = '<?php echo BASE_URL; ?>modules/instructor/dashboard.php';
                }
            }
        });

        // Auto-check for new notifications every 30 seconds
        if (!window.location.search.includes('id=')) {
            setInterval(() => {
                fetch('<?php echo BASE_URL; ?>modules/instructor/notifications/get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        const currentUnread = <?php echo $unread_count; ?>;
                        if (data.unread_count > currentUnread) {
                            // Show notification badge
                            const badge = document.createElement('div');
                            badge.innerHTML = `
                                <div style="position: fixed; top: 20px; right: 20px; background: var(--primary); color: white; padding: 1rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(59,130,246,0.3); z-index: 1000; animation: slideIn 0.3s ease;">
                                    <i class="fas fa-bell"></i> ${data.unread_count - currentUnread} new notification(s)
                                </div>
                            `;
                            document.body.appendChild(badge);

                            // Remove after 3 seconds
                            setTimeout(() => {
                                badge.style.animation = 'slideOut 0.3s ease';
                                setTimeout(() => badge.remove(), 300);
                            }, 3000);

                            // Play notification sound
                            const audio = new Audio('<?php echo BASE_URL; ?>assets/sounds/notification.mp3');
                            audio.play().catch(() => {});
                        }
                    });
            }, 30000);
        }

        // Add CSS for notification animation
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
    </script>
</body>

</html>