<?php
// modules/shared/notifications/index.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
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
$user_role = $_SESSION['user_role'] ?? '';

// Get notification type filter
$type_filter = $_GET['type'] ?? 'all';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query based on filters
$where_clauses = ["(user_id = ? OR user_id IS NULL)"];
$params = [$user_id];
$param_types = "i";

if ($type_filter !== 'all') {
    $where_clauses[] = "type = ?";
    $params[] = $type_filter;
    $param_types .= "s";
}

// Get read/unread filter
$read_filter = $_GET['read'] ?? '';
if ($read_filter === 'unread') {
    $where_clauses[] = "is_read = 0";
} elseif ($read_filter === 'read') {
    $where_clauses[] = "is_read = 1";
}

// Get date filter
$date_filter = $_GET['date'] ?? '';
if ($date_filter === 'today') {
    $where_clauses[] = "DATE(created_at) = CURDATE()";
} elseif ($date_filter === 'week') {
    $where_clauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($date_filter === 'month') {
    $where_clauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM notifications WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_rows = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_rows = 0;
}

$total_pages = ceil($total_rows / $limit);

// Get notifications - FIXED: Removed sender_id join since column doesn't exist
$sql = "SELECT n.*
        FROM notifications n
        WHERE $where_sql
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
$notifications = [];
$unread_count = 0;

if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get unread count for badge
$unread_sql = "SELECT COUNT(*) as count FROM notifications 
               WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0";
$unread_stmt = $conn->prepare($unread_sql);
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $user_id);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result();
    if ($unread_row = $unread_result->fetch_assoc()) {
        $unread_count = $unread_row['count'];
    }
    $unread_stmt->close();
}

// Get notification statistics
$stats_sql = "SELECT 
                COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
                COUNT(CASE WHEN type = 'system' THEN 1 END) as system,
                COUNT(CASE WHEN type = 'assignment' THEN 1 END) as assignment,
                COUNT(CASE WHEN type = 'grade' THEN 1 END) as grade,
                COUNT(CASE WHEN type = 'announcement' THEN 1 END) as announcement,
                COUNT(CASE WHEN type = 'message' THEN 1 END) as message
              FROM notifications 
              WHERE user_id = ? OR user_id IS NULL";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// Mark notifications as read when viewing
if (!empty($notifications)) {
    $notification_ids = array_column($notifications, 'id');
    $mark_read_sql = "UPDATE notifications SET is_read = 1, read_at = NOW() 
                     WHERE id IN (" . implode(',', array_fill(0, count($notification_ids), '?')) . ") 
                     AND is_read = 0";
    $mark_stmt = $conn->prepare($mark_read_sql);
    if ($mark_stmt) {
        $mark_stmt->bind_param(str_repeat('i', count($notification_ids)), ...$notification_ids);
        $mark_stmt->execute();
        $mark_stmt->close();
    }
}

// Log activity
logActivity($user_id, 'view_notifications', 'Viewed notifications page', $_SERVER['REMOTE_ADDR']);

// Close database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/shared.css">
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
        }

        /* Header */
        .notifications-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
        }

        .notifications-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .notifications-header p {
            opacity: 0.9;
            font-size: 0.875rem;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
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
            background-color: white;
            color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #f8f9fa;
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.2);
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

        /* Main Container */
        .notifications-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem 2rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card.active {
            border-left: 4px solid var(--primary);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-card[data-type="all"] .stat-icon {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .stat-card[data-type="unread"] .stat-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .stat-card[data-type="system"] .stat-icon {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .stat-card[data-type="assignment"] .stat-icon {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .stat-card[data-type="grade"] .stat-icon {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .stat-card[data-type="announcement"] .stat-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filters */
        .filters-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
        }

        .filters-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 0.875rem;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background: #e9ecef;
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Notifications List */
        .notifications-list {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .notification-item {
            display: flex;
            padding: 1.25rem;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: rgba(67, 97, 238, 0.05);
        }

        .notification-item.unread:hover {
            background: rgba(67, 97, 238, 0.08);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 1rem;
            font-size: 1.125rem;
        }

        .notification-icon.system {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .notification-icon.assignment {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .notification-icon.grade {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .notification-icon.announcement {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .notification-icon.message {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }

        .notification-message {
            color: var(--gray);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--gray-light);
        }

        .notification-type {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 10px;
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .notification-type.system {
            background: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .notification-type.assignment {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .notification-type.grade {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .notification-type.announcement {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border: 1px solid var(--border);
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .action-btn:hover {
            background: #e9ecef;
            color: var(--dark);
        }

        .action-btn.delete:hover {
            background: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .unread-badge {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-icon {
            font-size: 3rem;
            color: var(--gray-light);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-description {
            color: var(--gray);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-select input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .bulk-actions-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-link {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: white;
            color: var(--dark);
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
            box-shadow: var(--card-shadow);
        }

        .page-link:hover {
            background: #f8f9fa;
        }

        .page-link.active {
            background: var(--primary);
            color: white;
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .notifications-container {
                padding: 0 1rem 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .notification-item {
                flex-direction: column;
                gap: 1rem;
            }

            .notification-actions {
                margin-left: 0;
                margin-top: 0.5rem;
            }

            .bulk-actions {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .bulk-actions-buttons {
                justify-content: flex-end;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-buttons {
                justify-content: center;
            }

            .notification-meta {
                flex-direction: column;
                gap: 0.25rem;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <div class="notifications-header">
        <div class="notifications-container">
            <h1>Notifications</h1>
            <p>Stay updated with your academic activities and announcements</p>

            <div class="header-actions">
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
                <div>
                    <button onclick="markAllAsRead()" class="btn btn-secondary">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                    <button onclick="deleteAllRead()" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Read
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="notifications-container">
        <!-- Stats Grid -->
        <div class="stats-grid">
            <a href="?type=all" class="stat-card <?php echo $type_filter === 'all' ? 'active' : ''; ?>" data-type="all">
                <div class="stat-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo array_sum($stats); ?></div>
                    <div class="stat-label">All Notifications</div>
                </div>
            </a>

            <a href="?type=all&read=unread" class="stat-card <?php echo $read_filter === 'unread' ? 'active' : ''; ?>" data-type="unread">
                <div class="stat-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $unread_count; ?></div>
                    <div class="stat-label">Unread</div>
                </div>
            </a>

            <a href="?type=system" class="stat-card <?php echo $type_filter === 'system' ? 'active' : ''; ?>" data-type="system">
                <div class="stat-icon">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['system'] ?? 0; ?></div>
                    <div class="stat-label">System</div>
                </div>
            </a>

            <a href="?type=assignment" class="stat-card <?php echo $type_filter === 'assignment' ? 'active' : ''; ?>" data-type="assignment">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['assignment'] ?? 0; ?></div>
                    <div class="stat-label">Assignments</div>
                </div>
            </a>

            <a href="?type=grade" class="stat-card <?php echo $type_filter === 'grade' ? 'active' : ''; ?>" data-type="grade">
                <div class="stat-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['grade'] ?? 0; ?></div>
                    <div class="stat-label">Grades</div>
                </div>
            </a>

            <a href="?type=announcement" class="stat-card <?php echo $type_filter === 'announcement' ? 'active' : ''; ?>" data-type="announcement">
                <div class="stat-icon">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['announcement'] ?? 0; ?></div>
                    <div class="stat-label">Announcements</div>
                </div>
            </a>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <div class="filters-title">Filter by Type</div>
            <div class="filter-buttons">
                <a href="?type=all" class="filter-btn <?php echo $type_filter === 'all' ? 'active' : ''; ?>">
                    All
                </a>
                <a href="?type=system" class="filter-btn <?php echo $type_filter === 'system' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> System
                </a>
                <a href="?type=assignment" class="filter-btn <?php echo $type_filter === 'assignment' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="?type=grade" class="filter-btn <?php echo $type_filter === 'grade' ? 'active' : ''; ?>">
                    <i class="fas fa-star"></i> Grades
                </a>
                <a href="?type=announcement" class="filter-btn <?php echo $type_filter === 'announcement' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="?type=message" class="filter-btn <?php echo $type_filter === 'message' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Messages
                </a>
            </div>

            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                <div class="filters-title" style="font-size: 0.875rem; margin-bottom: 0.5rem;">Additional Filters</div>
                <div class="filter-buttons">
                    <a href="?type=<?php echo $type_filter; ?>&read=unread" class="filter-btn <?php echo $read_filter === 'unread' ? 'active' : ''; ?>">
                        Unread Only
                    </a>
                    <a href="?type=<?php echo $type_filter; ?>&read=read" class="filter-btn <?php echo $read_filter === 'read' ? 'active' : ''; ?>">
                        Read Only
                    </a>
                    <a href="?type=<?php echo $type_filter; ?>&date=today" class="filter-btn <?php echo $date_filter === 'today' ? 'active' : ''; ?>">
                        Today
                    </a>
                    <a href="?type=<?php echo $type_filter; ?>&date=week" class="filter-btn <?php echo $date_filter === 'week' ? 'active' : ''; ?>">
                        This Week
                    </a>
                    <a href="?type=<?php echo $type_filter; ?>&date=month" class="filter-btn <?php echo $date_filter === 'month' ? 'active' : ''; ?>">
                        This Month
                    </a>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions" id="bulkActions" style="display: none;">
            <div class="bulk-select">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                <span id="selectedCount">0 notifications selected</span>
            </div>
            <div class="bulk-actions-buttons">
                <button onclick="markSelectedAsRead()" class="btn btn-sm btn-secondary">
                    <i class="fas fa-check"></i> Mark as Read
                </button>
                <button onclick="deleteSelected()" class="btn btn-sm btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button onclick="clearSelection()" class="btn btn-sm btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notifications-list">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-bell-slash"></i>
                    </div>
                    <div class="empty-title">No notifications found</div>
                    <div class="empty-description">
                        <?php if ($type_filter !== 'all' || $read_filter || $date_filter): ?>
                            Try adjusting your filters or clear them to see all notifications
                        <?php else: ?>
                            You're all caught up! When you have new notifications, they'll appear here.
                        <?php endif; ?>
                    </div>
                    <?php if ($type_filter !== 'all' || $read_filter || $date_filter): ?>
                        <a href="?" class="btn btn-primary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                        data-id="<?php echo $notification['id']; ?>">
                        <?php if (!$notification['is_read']): ?>
                            <div class="unread-badge"></div>
                        <?php endif; ?>

                        <div class="notification-icon <?php echo $notification['type']; ?>">
                            <?php
                            $icons = [
                                'system' => 'fas fa-cog',
                                'assignment' => 'fas fa-tasks',
                                'grade' => 'fas fa-star',
                                'announcement' => 'fas fa-bullhorn',
                                'message' => 'fas fa-envelope'
                            ];
                            $icon = $icons[$notification['type']] ?? 'fas fa-bell';
                            ?>
                            <i class="<?php echo $icon; ?>"></i>
                        </div>

                        <div class="notification-content">
                            <div class="notification-title">
                                <?php echo htmlspecialchars($notification['title']); ?>
                            </div>

                            <div class="notification-message">
                                <?php echo htmlspecialchars($notification['message']); ?>

                                <?php if (!empty($notification['related_id'])): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <a href="<?php echo getNotificationLink($notification); ?>"
                                            class="btn btn-sm btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                            View Details
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="notification-meta">
                                <span>
                                    <i class="fas fa-clock"></i>
                                    <?php
                                    $created_at = strtotime($notification['created_at']);
                                    $now = time();
                                    $diff = $now - $created_at;

                                    if ($diff < 60) {
                                        echo 'Just now';
                                    } elseif ($diff < 3600) {
                                        $minutes = floor($diff / 60);
                                        echo $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff < 86400) {
                                        $hours = floor($diff / 3600);
                                        echo $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                                    } elseif ($diff < 604800) {
                                        $days = floor($diff / 86400);
                                        echo $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                                    } else {
                                        echo date('M j, Y', $created_at);
                                    }
                                    ?>
                                </span>

                                <span class="notification-type <?php echo $notification['type']; ?>">
                                    <?php echo ucfirst($notification['type']); ?>
                                </span>

                                <?php if ($notification['read_at']): ?>
                                    <span>
                                        <i class="fas fa-check-circle"></i>
                                        Read: <?php echo date('M j, g:i A', strtotime($notification['read_at'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="notification-actions">
                            <?php if (!$notification['is_read']): ?>
                                <button class="action-btn mark-read"
                                    onclick="markAsRead(<?php echo $notification['id']; ?>)"
                                    title="Mark as read">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>

                            <button class="action-btn delete"
                                onclick="deleteNotification(<?php echo $notification['id']; ?>)"
                                title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>

                            <input type="checkbox" class="notification-checkbox"
                                onchange="updateSelection()"
                                data-id="<?php echo $notification['id']; ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?type=<?php echo $type_filter; ?>&read=<?php echo $read_filter; ?>&date=<?php echo $date_filter; ?>&page=<?php echo $page - 1; ?>"
                        class="page-link">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled">
                        <i class="fas fa-chevron-left"></i>
                    </span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <a href="?type=<?php echo $type_filter; ?>&read=<?php echo $read_filter; ?>&date=<?php echo $date_filter; ?>&page=<?php echo $i; ?>"
                            class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span class="page-link disabled">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?type=<?php echo $type_filter; ?>&read=<?php echo $read_filter; ?>&date=<?php echo $date_filter; ?>&page=<?php echo $page + 1; ?>"
                        class="page-link">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Mark single notification as read
        function markAsRead(id) {
            fetch('mark_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        notification_id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = document.querySelector(`.notification-item[data-id="${id}"]`);
                        item.classList.remove('unread');
                        item.querySelector('.unread-badge')?.remove();
                        item.querySelector('.mark-read')?.remove();

                        // Update unread count
                        updateUnreadCount(-1);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Mark all as read
        function markAllAsRead() {
            if (!confirm('Mark all notifications as read?')) return;

            fetch('mark_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        all: true
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                            item.querySelector('.unread-badge')?.remove();
                            item.querySelector('.mark-read')?.remove();
                        });

                        // Update unread count
                        updateUnreadCount(-<?php echo $unread_count; ?>);

                        // Reload page after a moment
                        setTimeout(() => {
                            window.location.reload();
                        }, 500);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Delete single notification
        function deleteNotification(id) {
            if (!confirm('Delete this notification?')) return;

            fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        notification_id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = document.querySelector(`.notification-item[data-id="${id}"]`);
                        item.style.opacity = '0';
                        item.style.transform = 'translateX(-20px)';

                        setTimeout(() => {
                            item.remove();

                            // Update counts
                            if (item.classList.contains('unread')) {
                                updateUnreadCount(-1);
                            }

                            // Check if list is empty
                            if (document.querySelectorAll('.notification-item').length === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Delete all read notifications
        function deleteAllRead() {
            if (!confirm('Delete all read notifications? This action cannot be undone.')) return;

            fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        all_read: true
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item:not(.unread)').forEach(item => {
                            item.style.opacity = '0';
                            item.style.transform = 'translateX(-20px)';

                            setTimeout(() => {
                                item.remove();
                            }, 300);
                        });

                        // Reload after all animations
                        setTimeout(() => {
                            window.location.reload();
                        }, 500);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Update unread count
        function updateUnreadCount(change) {
            // Update unread stat card
            const unreadStat = document.querySelector('.stat-card[data-type="unread"] .stat-value');
            if (unreadStat) {
                let current = parseInt(unreadStat.textContent) || 0;
                current += change;
                if (current < 0) current = 0;
                unreadStat.textContent = current;
            }

            // Update total stat card
            const totalStat = document.querySelector('.stat-card[data-type="all"] .stat-value');
            if (totalStat && change < 0) {
                let current = parseInt(totalStat.textContent) || 0;
                current += change;
                if (current < 0) current = 0;
                totalStat.textContent = current;
            }
        }

        // Bulk selection functions
        let selectedNotifications = new Set();

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.notification-checkbox:checked');
            selectedNotifications.clear();

            checkboxes.forEach(checkbox => {
                selectedNotifications.add(checkbox.getAttribute('data-id'));
            });

            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');

            if (checkboxes.length > 0) {
                bulkActions.style.display = 'flex';
                selectedCount.textContent = `${checkboxes.length} notification${checkboxes.length > 1 ? 's' : ''} selected`;
            } else {
                bulkActions.style.display = 'none';
            }

            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.notification-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = checkboxes.length === allCheckboxes.length;
        }

        function toggleSelectAll(checkbox) {
            const allCheckboxes = document.querySelectorAll('.notification-checkbox');
            allCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelection();
        }

        function clearSelection() {
            document.querySelectorAll('.notification-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateSelection();
        }

        function markSelectedAsRead() {
            if (selectedNotifications.size === 0) return;

            if (!confirm(`Mark ${selectedNotifications.size} notification${selectedNotifications.size > 1 ? 's' : ''} as read?`)) return;

            fetch('mark_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        notification_ids: Array.from(selectedNotifications)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        selectedNotifications.forEach(id => {
                            const item = document.querySelector(`.notification-item[data-id="${id}"]`);
                            if (item) {
                                item.classList.remove('unread');
                                item.querySelector('.unread-badge')?.remove();
                                item.querySelector('.mark-read')?.remove();
                            }
                        });

                        // Update counts
                        updateUnreadCount(-selectedNotifications.size);

                        // Clear selection
                        clearSelection();
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function deleteSelected() {
            if (selectedNotifications.size === 0) return;

            if (!confirm(`Delete ${selectedNotifications.size} notification${selectedNotifications.size > 1 ? 's' : ''}? This action cannot be undone.`)) return;

            fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        notification_ids: Array.from(selectedNotifications)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Count unread notifications to be deleted
                        let unreadDeleted = 0;
                        selectedNotifications.forEach(id => {
                            const item = document.querySelector(`.notification-item[data-id="${id}"]`);
                            if (item) {
                                if (item.classList.contains('unread')) {
                                    unreadDeleted++;
                                }
                                item.style.opacity = '0';
                                item.style.transform = 'translateX(-20px)';

                                setTimeout(() => {
                                    item.remove();
                                }, 300);
                            }
                        });

                        // Update counts
                        updateUnreadCount(-unreadDeleted);

                        // Clear selection
                        clearSelection();

                        // Check if list is empty
                        setTimeout(() => {
                            if (document.querySelectorAll('.notification-item').length === 0) {
                                location.reload();
                            }
                        }, 400);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Auto refresh notifications every 30 seconds
        let autoRefresh = setInterval(() => {
            fetch('check_updates.php?user_id=<?php echo $user_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.new_notifications > 0) {
                        // Show notification badge
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.textContent = parseInt(badge.textContent || 0) + data.new_notifications;
                        }

                        // Play notification sound (if allowed)
                        if (Notification.permission === 'granted' && data.notifications.length > 0) {
                            const notification = data.notifications[0];
                            new Notification(notification.title, {
                                body: notification.message,
                                icon: '<?php echo BASE_URL; ?>assets/images/logo-icon.png'
                            });
                        }
                    }
                })
                .catch(error => console.error('Error checking updates:', error));
        }, 30000);

        // Request notification permission
        document.addEventListener('DOMContentLoaded', function() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        });
    </script>
</body>

</html>
<?php
// Helper function to generate notification links
function getNotificationLink($notification)
{
    $type = $notification['type'];
    $related_id = $notification['related_id'];

    switch ($type) {
        case 'assignment':
            return BASE_URL . 'modules/student/classes/assignments/view.php?assignment_id=' . $related_id;
        case 'grade':
            return BASE_URL . 'modules/student/classes/grades/';
        case 'announcement':
            return BASE_URL . 'modules/student/classes/announcements/view.php?id=' . $related_id;
        case 'message':
            return BASE_URL . 'modules/shared/messages/view.php?id=' . $related_id;
        default:
            return '#';
    }
}
?>