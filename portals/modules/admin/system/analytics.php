<?php
// modules/admin/system/analytics.php

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

// Get date range parameters
$period = $_GET['period'] ?? 'month'; // day, week, month, year, custom
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Calculate statistics
$stats = [];

// User statistics
$user_stats_sql = "SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as total_students,
    SUM(CASE WHEN role = 'instructor' THEN 1 ELSE 0 END) as total_instructors,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_users,
    SUM(CASE WHEN DATE(created_at) >= ? THEN 1 ELSE 0 END) as new_users_this_period
FROM users";

$user_stmt = $conn->prepare($user_stats_sql);
$user_stmt->bind_param("s", $date_from);
$user_stmt->execute();
$user_stats = $user_stmt->get_result()->fetch_assoc();
$stats['users'] = $user_stats;

// Application statistics
$app_stats_sql = "SELECT 
    COUNT(*) as total_applications,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_applications,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_applications,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_applications,
    SUM(CASE WHEN applying_as = 'student' THEN 1 ELSE 0 END) as student_applications,
    SUM(CASE WHEN applying_as = 'instructor' THEN 1 ELSE 0 END) as instructor_applications,
    SUM(CASE WHEN DATE(created_at) >= ? THEN 1 ELSE 0 END) as new_applications_this_period
FROM applications";

$app_stmt = $conn->prepare($app_stats_sql);
$app_stmt->bind_param("s", $date_from);
$app_stmt->execute();
$app_stats = $app_stmt->get_result()->fetch_assoc();
$stats['applications'] = $app_stats;

// Class statistics
$class_stats_sql = "SELECT 
    COUNT(*) as total_classes,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_classes,
    SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_classes,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_classes,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_classes,
    SUM(CASE WHEN DATE(start_date) >= ? THEN 1 ELSE 0 END) as new_classes_this_period
FROM class_batches";

$class_stmt = $conn->prepare($class_stats_sql);
$class_stmt->bind_param("s", $date_from);
$class_stmt->execute();
$class_stats = $class_stmt->get_result()->fetch_assoc();
$stats['classes'] = $class_stats;

// Enrollment statistics
$enrollment_sql = "SELECT 
    COUNT(*) as total_enrollments,
    SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) as active_enrollments,
    SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) as completed_enrollments,
    SUM(CASE WHEN DATE(e.enrollment_date) >= ? THEN 1 ELSE 0 END) as new_enrollments_this_period
FROM enrollments e";

$enrollment_stmt = $conn->prepare($enrollment_sql);
$enrollment_stmt->bind_param("s", $date_from);
$enrollment_stmt->execute();
$enrollment_stats = $enrollment_stmt->get_result()->fetch_assoc();
$stats['enrollments'] = $enrollment_stats;

// Activity logs for the period
$activity_sql = "SELECT 
    action,
    COUNT(*) as count,
    DATE(created_at) as date
FROM activity_logs 
WHERE created_at >= ? 
GROUP BY action, DATE(created_at)
ORDER BY date DESC, count DESC
LIMIT 50";

$activity_stmt = $conn->prepare($activity_sql);
$activity_stmt->bind_param("s", $date_from);
$activity_stmt->execute();
$activities = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// User registration trend
$registration_trend_sql = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as count,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
    SUM(CASE WHEN role = 'instructor' THEN 1 ELSE 0 END) as instructors
FROM users 
WHERE created_at >= ?
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30";

$trend_stmt = $conn->prepare($registration_trend_sql);
$trend_stmt->bind_param("s", $date_from);
$trend_stmt->execute();
$registration_trend = $trend_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_analytics', "Viewed system analytics for period: $period");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Analytics - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .filter-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filter-card h3 {
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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

        .stat-card.users { border-left-color: #3b82f6; }
        .stat-card.applications { border-left-color: #10b981; }
        .stat-card.classes { border-left-color: #8b5cf6; }
        .stat-card.enrollments { border-left-color: #f59e0b; }

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

        .stat-details {
            margin-top: 0.75rem;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        .stat-detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .stat-detail-label {
            color: #64748b;
        }

        .stat-detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .chart-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .chart-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .chart-wrapper {
            height: 300px;
            position: relative;
        }

        .activity-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .activity-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }

        .activity-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
        }

        .activity-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-table tbody tr:hover {
            background: #f8fafc;
        }

        .action-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-login { background: #d1fae5; color: #065f46; }
        .badge-registration { background: #dbeafe; color: #1e40af; }
        .badge-application { background: #f3e8ff; color: #6b21a8; }
        .badge-admin { background: #fef3c7; color: #92400e; }

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
            .chart-container {
                grid-template-columns: 1fr;
            }
            .chart-card {
                min-width: 100%;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/analytics.php" class="active">
                        <i class="fas fa-chart-line"></i> Analytics</a></li>
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/analytics.php">System</a> &rsaquo;
                        Analytics
                    </div>
                    <h1>System Analytics</h1>
                </div>
                <div>
                    <button onclick="exportAnalytics()" class="btn btn-primary">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="filter-card">
                <h3>Filter Analytics Period</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Period</label>
                        <select name="period" class="form-control" onchange="this.form.submit()">
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
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
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Statistics Overview -->
            <div class="stats-grid">
                <!-- Users Card -->
                <div class="stat-card users">
                    <div class="stat-number">
                        <?php echo $stats['users']['total_users']; ?>
                        <i class="fas fa-users stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-details">
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Students</span>
                            <span class="stat-detail-value"><?php echo $stats['users']['total_students']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Instructors</span>
                            <span class="stat-detail-value"><?php echo $stats['users']['total_instructors']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Active</span>
                            <span class="stat-detail-value"><?php echo $stats['users']['active_users']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">New This Period</span>
                            <span class="stat-detail-value"><?php echo $stats['users']['new_users_this_period']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Applications Card -->
                <div class="stat-card applications">
                    <div class="stat-number">
                        <?php echo $stats['applications']['total_applications']; ?>
                        <i class="fas fa-file-alt stat-icon"></i>
                    </div>
                    <div class="stat-label">Applications</div>
                    <div class="stat-details">
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Pending</span>
                            <span class="stat-detail-value"><?php echo $stats['applications']['pending_applications']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Approved</span>
                            <span class="stat-detail-value"><?php echo $stats['applications']['approved_applications']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Rejected</span>
                            <span class="stat-detail-value"><?php echo $stats['applications']['rejected_applications']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">New This Period</span>
                            <span class="stat-detail-value"><?php echo $stats['applications']['new_applications_this_period']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Classes Card -->
                <div class="stat-card classes">
                    <div class="stat-number">
                        <?php echo $stats['classes']['total_classes']; ?>
                        <i class="fas fa-chalkboard-teacher stat-icon"></i>
                    </div>
                    <div class="stat-label">Class Batches</div>
                    <div class="stat-details">
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Ongoing</span>
                            <span class="stat-detail-value"><?php echo $stats['classes']['ongoing_classes']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Scheduled</span>
                            <span class="stat-detail-value"><?php echo $stats['classes']['scheduled_classes']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Completed</span>
                            <span class="stat-detail-value"><?php echo $stats['classes']['completed_classes']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">New This Period</span>
                            <span class="stat-detail-value"><?php echo $stats['classes']['new_classes_this_period']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Enrollments Card -->
                <div class="stat-card enrollments">
                    <div class="stat-number">
                        <?php echo $stats['enrollments']['total_enrollments']; ?>
                        <i class="fas fa-user-graduate stat-icon"></i>
                    </div>
                    <div class="stat-label">Enrollments</div>
                    <div class="stat-details">
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Active</span>
                            <span class="stat-detail-value"><?php echo $stats['enrollments']['active_enrollments']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">Completed</span>
                            <span class="stat-detail-value"><?php echo $stats['enrollments']['completed_enrollments']; ?></span>
                        </div>
                        <div class="stat-detail-item">
                            <span class="stat-detail-label">New This Period</span>
                            <span class="stat-detail-value"><?php echo $stats['enrollments']['new_enrollments_this_period']; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="chart-container">
                <!-- Registration Trend Chart -->
                <div class="chart-card">
                    <h3>User Registration Trend</h3>
                    <div class="chart-wrapper">
                        <canvas id="registrationChart"></canvas>
                    </div>
                </div>

                <!-- Application Status Chart -->
                <div class="chart-card">
                    <h3>Application Status Distribution</h3>
                    <div class="chart-wrapper">
                        <canvas id="applicationChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Activity Logs -->
            <div class="activity-card">
                <h3>Recent System Activities</h3>
                <div class="table-container">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($activities)): ?>
                                <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($activity['date'])); ?></td>
                                        <td>
                                            <span class="action-badge 
                                                <?php echo strpos($activity['action'], 'login') !== false ? 'badge-login' : ''; ?>
                                                <?php echo strpos($activity['action'], 'registration') !== false ? 'badge-registration' : ''; ?>
                                                <?php echo strpos($activity['action'], 'application') !== false ? 'badge-application' : ''; ?>
                                                <?php echo strpos($activity['action'], 'admin') !== false ? 'badge-admin' : ''; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                        <td><strong><?php echo $activity['count']; ?></strong> times</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 2rem; color: #64748b;">
                                        <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <p>No activity data available for the selected period.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Export analytics function
        function exportAnalytics() {
            const params = new URLSearchParams(window.location.search);
            window.open('export_analytics.php?' + params.toString(), '_blank');
        }

        // Registration Trend Chart
        const registrationCtx = document.getElementById('registrationChart').getContext('2d');
        const registrationData = {
            labels: <?php echo json_encode(array_column($registration_trend, 'date')); ?>,
            datasets: [{
                label: 'Total Registrations',
                data: <?php echo json_encode(array_column($registration_trend, 'count')); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Students',
                data: <?php echo json_encode(array_column($registration_trend, 'students')); ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Instructors',
                data: <?php echo json_encode(array_column($registration_trend, 'instructors')); ?>,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                fill: true,
                tension: 0.4
            }]
        };

        new Chart(registrationCtx, {
            type: 'line',
            data: registrationData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Application Status Chart
        const applicationCtx = document.getElementById('applicationChart').getContext('2d');
        const applicationData = {
            labels: ['Pending', 'Approved', 'Rejected'],
            datasets: [{
                data: [
                    <?php echo $stats['applications']['pending_applications']; ?>,
                    <?php echo $stats['applications']['approved_applications']; ?>,
                    <?php echo $stats['applications']['rejected_applications']; ?>
                ],
                backgroundColor: [
                    '#f59e0b',
                    '#10b981',
                    '#ef4444'
                ],
                borderColor: [
                    '#d97706',
                    '#059669',
                    '#dc2626'
                ],
                borderWidth: 1
            }]
        };

        new Chart(applicationCtx, {
            type: 'doughnut',
            data: applicationData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });

        // Update chart colors based on theme
        function updateChartColors() {
            const isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const textColor = isDarkMode ? '#ffffff' : '#374151';
            const gridColor = isDarkMode ? '#4b5563' : '#e5e7eb';
            
            Chart.defaults.color = textColor;
            Chart.defaults.borderColor = gridColor;
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', updateChartColors);
    </script>
</body>
</html>
<?php $conn->close(); ?>