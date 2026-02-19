<?php
// modules/admin/system/announcements.php

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

// Handle announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $priority = $_POST['priority'];
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        // Validate required fields
        if (empty($title) || empty($content)) {
            $_SESSION['error'] = 'Title and content are required.';
        } else {
            $sql = "INSERT INTO announcements (author_id, title, content, priority, is_published, expiry_date) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssis", $_SESSION['user_id'], $title, $content, $priority, $is_published, $expiry_date);

            if ($stmt->execute()) {
    $announcement_id = $stmt->insert_id;
    $_SESSION['success'] = 'Announcement created successfully.';

    // Log activity
    logActivity(
        $_SESSION['user_id'],
        'announcement_create',
        "Created announcement: $title",
        'announcements',
        $announcement_id
    );

    // SEND EMAIL NOTIFICATION if published
    if ($is_published == 1) {
        // Call the email function from functions.php
        $emailResult = sendAnnouncementNotification($announcement_id, $conn);
        
        if ($emailResult) {
            $_SESSION['success'] .= ' Email notifications sent.';
        } else {
            $_SESSION['warning'] = 'Announcement created, but email notifications failed.';
        }
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
        }
    }
}

// Handle announcement update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_announcement'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $announcement_id = $_POST['announcement_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $priority = $_POST['priority'];
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        $sql = "UPDATE announcements SET 
                title = ?, content = ?, priority = ?, 
                is_published = ?, expiry_date = ?, updated_at = NOW()
                WHERE id = ? AND author_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssii", $title, $content, $priority, $is_published, $expiry_date, $announcement_id, $_SESSION['user_id']);

        if ($stmt->execute()) {
            $_SESSION['success'] = 'Announcement updated successfully.';

            // Log activity
            logActivity(
                $_SESSION['user_id'],
                'announcement_update',
                "Updated announcement: $title",
                'announcements',
                $announcement_id
            );

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update announcement.';
        }
    }
}

// Handle announcement deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $announcement_id = $_POST['announcement_id'];

        // Get announcement title for logging
        $title_sql = "SELECT title FROM announcements WHERE id = ?";
        $title_stmt = $conn->prepare($title_sql);
        $title_stmt->bind_param("i", $announcement_id);
        $title_stmt->execute();
        $title_result = $title_stmt->get_result();
        $announcement = $title_result->fetch_assoc();

        $sql = "DELETE FROM announcements WHERE id = ? AND author_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $announcement_id, $_SESSION['user_id']);

        if ($stmt->execute()) {
            $_SESSION['success'] = 'Announcement deleted successfully.';

            // Log activity
            logActivity(
                $_SESSION['user_id'],
                'announcement_delete',
                "Deleted announcement: " . $announcement['title'],
                'announcements',
                $announcement_id
            );

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to delete announcement.';
        }
    }
}

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for announcements
$sql = "SELECT a.*, 
        u.first_name, u.last_name,
        (SELECT COUNT(*) FROM notifications WHERE related_id = a.id AND type = 'announcement') as views_count
        FROM announcements a
        LEFT JOIN users u ON a.author_id = u.id
        WHERE 1=1";

$params = [];
$types = "";

// Filter by status
if ($status !== 'all') {
    if ($status === 'active') {
        $sql .= " AND a.is_published = 1 AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())";
    } elseif ($status === 'expired') {
        $sql .= " AND a.expiry_date < CURDATE()";
    } elseif ($status === 'draft') {
        $sql .= " AND a.is_published = 0";
    }
}

// Filter by priority
if ($priority !== 'all') {
    $sql .= " AND a.priority = ?";
    $params[] = $priority;
    $types .= "s";
}

// Filter by search
if ($search) {
    $sql .= " AND (a.title LIKE ? OR a.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

$sql .= " ORDER BY a.publish_date DESC, a.created_at DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics - FIXED VERSION
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_published = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired,
    SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) as draft,
    SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as `high_priority`,
    SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as `medium_priority`,
    SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as `low_priority`
FROM announcements";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity($_SESSION['user_id'], 'view_announcements', "Viewed announcements list");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Announcements - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --high: #ef4444;
            --medium: #f59e0b;
            --low: #10b981;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.active {
            border-left-color: var(--success);
        }

        .stat-card.expired {
            border-left-color: var(--danger);
        }

        .stat-card.draft {
            border-left-color: var(--warning);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            transform: rotate(45deg) translate(30px, -30px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 1.5rem;
            opacity: 0.5;
        }

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        .announcements-list {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--dark);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .priority-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .content-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #64748b;
            font-size: 0.9rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            border-radius: 10px;
            overflow: hidden;
        }

        .modal-header {
            padding: 1.5rem;
            background: var(--primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-row {
            margin-bottom: 1rem;
        }

        .form-row label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }

        .form-row input[type="text"],
        .form-row textarea,
        .form-row select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .form-row textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-row.checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row.checkbox input {
            width: auto;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
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
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/analytics.php">
                            <i class="fas fa-chart-line"></i> Analytics</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php" class="active">
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">System</a> &rsaquo;
                        Announcements
                    </div>
                    <h1>System Announcements</h1>
                </div>
                <div>
                    <button onclick="openCreateModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Announcement
                    </button>
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $stats['total']; ?>
                        <i class="fas fa-bullhorn stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Announcements</div>
                </div>
                <div class="stat-card active">
                    <div class="stat-number">
                        <?php echo $stats['active']; ?>
                        <i class="fas fa-eye stat-icon"></i>
                    </div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card draft">
                    <div class="stat-number">
                        <?php echo $stats['draft']; ?>
                        <i class="fas fa-edit stat-icon"></i>
                    </div>
                    <div class="stat-label">Draft</div>
                </div>
                <div class="stat-card expired">
                    <div class="stat-number">
                        <?php echo $stats['expired']; ?>
                        <i class="fas fa-clock stat-icon"></i>
                    </div>
                    <div class="stat-label">Expired</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Announcements</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="all" <?php echo $priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                            <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Search title, content or author..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Announcements Table -->
            <div class="announcements-list">
                <div class="table-header">
                    <h3>Announcements List</h3>
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Showing <?php echo count($announcements); ?> announcements
                    </div>
                </div>

                <?php if (!empty($announcements)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Content</th>
                                    <th>Author</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Published</th>
                                    <th>Expires</th>
                                    <th>Views</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($announcements as $announcement):
                                    $is_expired = $announcement['expiry_date'] && strtotime($announcement['expiry_date']) < time();
                                    $is_active = $announcement['is_published'] == 1 && !$is_expired;
                                    $status = $is_active ? 'active' : ($is_expired ? 'expired' : 'draft');
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                            <?php if ($announcement['class_id']): ?>
                                                <br><small style="color: #64748b;">Class Announcement</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="content-preview" title="<?php echo htmlspecialchars($announcement['content']); ?>">
                                            <?php echo htmlspecialchars(substr($announcement['content'], 0, 100)); ?>...
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($announcement['first_name'] . ' ' . $announcement['last_name']); ?>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                                                <?php echo ucfirst($announcement['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $status; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($announcement['publish_date'])); ?>
                                        </td>
                                        <td>
                                            <?php echo $announcement['expiry_date'] ? date('M j, Y', strtotime($announcement['expiry_date'])) : 'Never'; ?>
                                        </td>
                                        <td>
                                            <?php echo $announcement['views_count'] ?? 0; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.25rem;">
                                                <button onclick="viewAnnouncement(<?php echo $announcement['id']; ?>)"
                                                    class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="editAnnouncement(<?php echo $announcement['id']; ?>)"
                                                    class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>, '<?php echo htmlspecialchars($announcement['title']); ?>')"
                                                    class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-bullhorn"></i>
                        <h3>No Announcements Found</h3>
                        <p>There are no announcements matching your filters.</p>
                        <button onclick="openCreateModal()" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Create Your First Announcement
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create/Edit Announcement Modal -->
    <div id="announcementModal" class="modal">
        <div class="modal-content">
            <form id="announcementForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="announcement_id" id="announcement_id" value="">

                <div class="modal-header">
                    <h3 id="modalTitle">Create New Announcement</h3>
                    <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-row">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required>
                    </div>

                    <div class="form-row">
                        <label for="content">Content *</label>
                        <textarea id="content" name="content" required></textarea>
                    </div>

                    <div class="form-row">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                    <div class="form-row checkbox">
                        <input type="checkbox" id="is_published" name="is_published" checked>
                        <label for="is_published">Publish Immediately</label>
                    </div>

                    <div class="form-row">
                        <label for="expiry_date">Expiry Date (Optional)</label>
                        <input type="date" id="expiry_date" name="expiry_date">
                        <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                            Leave empty for announcement that never expires
                        </small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="create_announcement" id="submitButton" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Announcement Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="viewTitle"></h3>
                <button type="button" class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>

            <div class="modal-body">
                <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0;">
                    <div>
                        <strong>Priority:</strong>
                        <span id="viewPriority" class="priority-badge"></span>
                    </div>
                    <div>
                        <strong>Status:</strong>
                        <span id="viewStatus" class="status-badge"></span>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <strong>Published:</strong> <span id="viewPublished"></span><br>
                    <strong>Expires:</strong> <span id="viewExpires"></span><br>
                    <strong>Views:</strong> <span id="viewViews"></span>
                </div>

                <div id="viewContent" style="background: #f8fafc; padding: 1.5rem; border-radius: 8px; white-space: pre-wrap;">
                    <!-- Content will be inserted here -->
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        let announcementData = <?php echo json_encode($announcements); ?>;

        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create New Announcement';
            document.getElementById('announcementForm').reset();
            document.getElementById('announcement_id').value = '';
            document.getElementById('submitButton').name = 'create_announcement';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Create Announcement';
            document.getElementById('announcementModal').style.display = 'flex';
        }

        function editAnnouncement(id) {
            const announcement = announcementData.find(a => a.id == id);
            if (!announcement) return;

            document.getElementById('modalTitle').textContent = 'Edit Announcement';
            document.getElementById('announcement_id').value = announcement.id;
            document.getElementById('title').value = announcement.title;
            document.getElementById('content').value = announcement.content;
            document.getElementById('priority').value = announcement.priority;
            document.getElementById('is_published').checked = announcement.is_published == 1;
            document.getElementById('expiry_date').value = announcement.expiry_date ? announcement.expiry_date.split(' ')[0] : '';
            document.getElementById('submitButton').name = 'update_announcement';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Update Announcement';

            document.getElementById('announcementModal').style.display = 'flex';
        }

        function viewAnnouncement(id) {
            const announcement = announcementData.find(a => a.id == id);
            if (!announcement) return;

            const isExpired = announcement.expiry_date && new Date(announcement.expiry_date) < new Date();
            const isActive = announcement.is_published == 1 && !isExpired;
            const status = isActive ? 'active' : (isExpired ? 'expired' : 'draft');

            document.getElementById('viewTitle').textContent = announcement.title;
            document.getElementById('viewPriority').textContent = announcement.priority;
            document.getElementById('viewPriority').className = `priority-badge priority-${announcement.priority}`;
            document.getElementById('viewStatus').textContent = status;
            document.getElementById('viewStatus').className = `status-badge status-${status}`;
            document.getElementById('viewPublished').textContent = new Date(announcement.publish_date).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('viewExpires').textContent = announcement.expiry_date ?
                new Date(announcement.expiry_date).toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }) : 'Never';
            document.getElementById('viewViews').textContent = announcement.views_count || 0;
            document.getElementById('viewContent').textContent = announcement.content;

            document.getElementById('viewModal').style.display = 'flex';
        }

        function deleteAnnouncement(id, title) {
            if (confirm(`Are you sure you want to delete the announcement "${title}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = 'csrf_token';
                csrfToken.value = '<?php echo generateCSRFToken(); ?>';

                const announcementId = document.createElement('input');
                announcementId.type = 'hidden';
                announcementId.name = 'announcement_id';
                announcementId.value = id;

                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_announcement';
                deleteInput.value = '1';

                form.appendChild(csrfToken);
                form.appendChild(announcementId);
                form.appendChild(deleteInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal() {
            document.getElementById('announcementModal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>