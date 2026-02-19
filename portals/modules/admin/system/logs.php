<?php
// modules/admin/system/logs.php

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

// Handle log clearing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $days = intval($_POST['days_older'] ?? 90);
        $sql = "DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $days);
        
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $_SESSION['success'] = "Cleared $affected_rows log entries older than $days days.";
            
            // Log the clearing activity
            logActivity($_SESSION['user_id'], 'logs_cleared', 
                "Cleared $affected_rows log entries older than $days days");
            
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to clear logs. Please try again.';
        }
    }
}

// Get filter parameters
$user_id = $_GET['user_id'] ?? '';
$action = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query for logs
$sql = "SELECT al.*, 
        u.first_name, u.last_name, u.email, u.role,
        DATE(al.created_at) as log_date,
        TIME(al.created_at) as log_time
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total FROM activity_logs al WHERE 1=1";
$params = [];
$types = "";
$where_conditions = [];

// Filter by user
if ($user_id) {
    $where_conditions[] = "al.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

// Filter by action
if ($action) {
    $where_conditions[] = "al.action LIKE ?";
    $params[] = "%$action%";
    $types .= "s";
}

// Filter by date range
if ($date_from) {
    $where_conditions[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where_conditions[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Filter by search term
if ($search) {
    $where_conditions[] = "(al.description LIKE ? OR al.action LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= str_repeat("s", 5);
}

// Apply where conditions
if (!empty($where_conditions)) {
    $where_clause = " AND " . implode(" AND ", $where_conditions);
    $sql .= $where_clause;
    $count_sql .= $where_clause;
}

// Get total count for pagination
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result()->fetch_assoc();
$total_logs = $count_result['total'];
$total_pages = ceil($total_logs / $limit);

// Add order and limit
$sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Get logs
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unique actions for filter dropdown
$actions_sql = "SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL ORDER BY action";
$actions_result = $conn->query($actions_sql);
$actions = $actions_result->fetch_all(MYSQLI_ASSOC);

// Get users for filter dropdown
$users_sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email 
              FROM activity_logs al 
              JOIN users u ON al.user_id = u.id 
              ORDER BY u.first_name, u.last_name";
$users_result = $conn->query($users_sql);
$filter_users = $users_result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT action) as unique_actions,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as yesterday
FROM activity_logs";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity($_SESSION['user_id'], 'view_logs', "Viewed activity logs");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin Dashboard</title>
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

        .stat-card.total { border-left-color: #3b82f6; }
        .stat-card.users { border-left-color: #10b981; }
        .stat-card.actions { border-left-color: #8b5cf6; }
        .stat-card.recent { border-left-color: #f59e0b; }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0));
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

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        .logs-table {
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            color: var(--dark);
        }

        .user-email {
            font-size: 0.85rem;
            color: #64748b;
        }

        .action-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-login { background: #d1fae5; color: #065f46; }
        .badge-logout { background: #fee2e2; color: #991b1b; }
        .badge-registration { background: #dbeafe; color: #1e40af; }
        .badge-application { background: #f3e8ff; color: #6b21a8; }
        .badge-admin { background: #fef3c7; color: #92400e; }
        .badge-view { background: #e0f2fe; color: #0369a1; }
        .badge-update { background: #fce7f3; color: #9d174d; }
        .badge-delete { background: #fee2e2; color: #991b1b; }

        .description {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #64748b;
            font-size: 0.9rem;
        }

        .ip-address {
            font-family: monospace;
            font-size: 0.85rem;
            color: #64748b;
        }

        .pagination {
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            max-width: 500px;
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
        }

        .modal-body {
            padding: 1.5rem;
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

        .form-row select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
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
            .table-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                        <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php" class="active">
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">System</a> &rsaquo;
                        Activity Logs
                    </div>
                    <h1>Activity Logs</h1>
                </div>
                <div>
                    <button onclick="openClearModal()" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear Old Logs
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number">
                        <?php echo number_format($stats['total']); ?>
                        <i class="fas fa-history stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Log Entries</div>
                </div>
                <div class="stat-card users">
                    <div class="stat-number">
                        <?php echo $stats['unique_users']; ?>
                        <i class="fas fa-users stat-icon"></i>
                    </div>
                    <div class="stat-label">Unique Users</div>
                </div>
                <div class="stat-card actions">
                    <div class="stat-number">
                        <?php echo $stats['unique_actions']; ?>
                        <i class="fas fa-cogs stat-icon"></i>
                    </div>
                    <div class="stat-label">Unique Actions</div>
                </div>
                <div class="stat-card recent">
                    <div class="stat-number">
                        <?php echo $stats['today']; ?>
                        <i class="fas fa-calendar-day stat-icon"></i>
                    </div>
                    <div class="stat-label">Today's Activities</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Activity Logs</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>User</label>
                        <select name="user_id" class="form-control">
                            <option value="">All Users</option>
                            <?php foreach ($filter_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" 
                                    <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['email'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Action</label>
                        <select name="action" class="form-control">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action_item): ?>
                                <option value="<?php echo $action_item['action']; ?>" 
                                    <?php echo $action == $action_item['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $action_item['action'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search description, action, or user..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                        <button type="button" onclick="exportLogs()" class="btn btn-success">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </form>
            </div>

            <!-- Logs Table -->
            <div class="logs-table">
                <div class="table-header">
                    <h3>Activity Logs</h3>
                    <div class="pagination-info">
                        Showing <?php echo count($logs); ?> of <?php echo number_format($total_logs); ?> entries
                    </div>
                </div>

                <?php if (!empty($logs)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>Table / Record</th>
                                    <th>IP Address</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): 
                                    $action_class = '';
                                    if (strpos($log['action'], 'login') !== false) $action_class = 'badge-login';
                                    elseif (strpos($log['action'], 'logout') !== false) $action_class = 'badge-logout';
                                    elseif (strpos($log['action'], 'registration') !== false) $action_class = 'badge-registration';
                                    elseif (strpos($log['action'], 'application') !== false) $action_class = 'badge-application';
                                    elseif (strpos($log['action'], 'admin') !== false) $action_class = 'badge-admin';
                                    elseif (strpos($log['action'], 'view') !== false) $action_class = 'badge-view';
                                    elseif (strpos($log['action'], 'update') !== false) $action_class = 'badge-update';
                                    elseif (strpos($log['action'], 'delete') !== false) $action_class = 'badge-delete';
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($log['user_id']): ?>
                                                <div class="user-info">
                                                    <div class="user-avatar">
                                                        <?php echo strtoupper(substr($log['first_name'] ?: 'S', 0, 1) . substr($log['last_name'] ?: 'Y', 0, 1)); ?>
                                                    </div>
                                                    <div class="user-details">
                                                        <span class="user-name">
                                                            <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                        </span>
                                                        <span class="user-email">
                                                            <?php echo htmlspecialchars($log['email']); ?>
                                                        </span>
                                                        <small style="color: #64748b;"><?php echo ucfirst($log['role']); ?></small>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-style: italic;">System / Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="action-badge <?php echo $action_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                            </span>
                                        </td>
                                        <td class="description" title="<?php echo htmlspecialchars($log['description']); ?>">
                                            <?php echo htmlspecialchars($log['description']); ?>
                                        </td>
                                        <td>
                                            <?php if ($log['table_name']): ?>
                                                <span style="font-weight: 500;"><?php echo $log['table_name']; ?></span>
                                                <?php if ($log['record_id']): ?>
                                                    <br><small style="color: #64748b;">Record #<?php echo $log['record_id']; ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #64748b;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ip-address"><?php echo $log['user_ip']; ?></span>
                                        </td>
                                        <td>
                                            <div><?php echo date('M j, Y', strtotime($log['log_date'])); ?></div>
                                            <div style="color: #64748b; font-size: 0.85rem;"><?php echo $log['log_time']; ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </div>
                        <div class="page-numbers">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">First</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">Previous</a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">Next</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">Last</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Activity Logs Found</h3>
                        <p>There are no activity logs matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Clear Logs Modal -->
    <div id="clearModal" class="modal">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-header">
                    <h3>Clear Old Activity Logs</h3>
                    <button type="button" class="modal-close" onclick="closeClearModal()">&times;</button>
                </div>
                
                <div class="modal-body">
                    <div class="form-row">
                        <label for="days_older">Delete logs older than (days):</label>
                        <select id="days_older" name="days_older" class="form-control">
                            <option value="30">30 days</option>
                            <option value="60" selected>60 days</option>
                            <option value="90">90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">1 year</option>
                        </select>
                    </div>
                    <div style="background: #fef3c7; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                        <strong><i class="fas fa-exclamation-triangle"></i> Warning:</strong>
                        <p style="margin-top: 0.5rem; color: #92400e;">
                            This action cannot be undone. All activity logs older than the specified days will be permanently deleted.
                        </p>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeClearModal()">Cancel</button>
                    <button type="submit" name="clear_logs" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear Logs
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openClearModal() {
            document.getElementById('clearModal').style.display = 'flex';
        }
        
        function closeClearModal() {
            document.getElementById('clearModal').style.display = 'none';
        }
        
        function exportLogs() {
            const params = new URLSearchParams(window.location.search);
            window.open('export_logs.php?' + params.toString(), '_blank');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target === document.getElementById('clearModal')) {
                closeClearModal();
            }
        }
        
        // Auto-submit search after typing delay
        let searchTimer;
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>