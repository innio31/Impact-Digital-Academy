<?php
// modules/admin/applications/history.php

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

// Get filter parameters
$status = $_GET['status'] ?? 'approved';
$reviewer_id = $_GET['reviewer_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$program_id = $_GET['program_id'] ?? '';

// Build query for historical applications (approved or rejected)
$sql = "SELECT 
    a.*, 
    u.first_name, 
    u.last_name, 
    u.email, 
    u.role as user_current_role,
    u.status as user_status,
    p.name as program_name,
    p.program_code,
    r.first_name as reviewer_first_name,
    r.last_name as reviewer_last_name
FROM applications a
LEFT JOIN users u ON a.user_id = u.id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN users r ON a.reviewed_by = r.id
WHERE a.status IN ('approved', 'rejected')";

$params = [];
$types = "";

// Filter by specific status
if ($status && $status !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Filter by reviewer
if ($reviewer_id) {
    $sql .= " AND a.reviewed_by = ?";
    $params[] = $reviewer_id;
    $types .= "i";
}

// Filter by date range
if ($date_from) {
    $sql .= " AND DATE(a.reviewed_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(a.reviewed_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Filter by program
if ($program_id) {
    $sql .= " AND a.program_id = ?";
    $params[] = $program_id;
    $types .= "i";
}

// Order by review date (newest first)
$sql .= " ORDER BY a.reviewed_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$applications = $result->fetch_all(MYSQLI_ASSOC);

// Get reviewers for filter dropdown
$reviewers_sql = "SELECT DISTINCT u.id, u.first_name, u.last_name 
                  FROM users u 
                  JOIN applications a ON u.id = a.reviewed_by 
                  WHERE a.reviewed_by IS NOT NULL 
                  ORDER BY u.first_name";
$reviewers_result = $conn->query($reviewers_sql);
$reviewers = $reviewers_result->fetch_all(MYSQLI_ASSOC);

// Get programs for filter dropdown
$programs_sql = "SELECT DISTINCT p.* FROM programs p 
                 JOIN applications a ON p.id = a.program_id 
                 WHERE a.program_id IS NOT NULL 
                 ORDER BY p.program_code";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Get statistics for the selected period
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    AVG(TIMESTAMPDIFF(HOUR, created_at, reviewed_at)) as avg_review_time_hours
FROM applications 
WHERE status IN ('approved', 'rejected') 
AND reviewed_at BETWEEN ? AND ?";

$stats_stmt = $conn->prepare($stats_sql);
$stats_date_to = $date_to . ' 23:59:59';
$stats_stmt->bind_param('ss', $date_from, $stats_date_to);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Log activity
logActivity($_SESSION['user_id'], 'view_applications_history', "Viewed applications history");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application History - Admin Dashboard</title>
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
            --approved: #10b981;
            --rejected: #ef4444;
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
        }

        .stat-card.approved {
            border-left-color: var(--approved);
        }

        .stat-card.rejected {
            border-left-color: var(--rejected);
        }

        .stat-card.time {
            border-left-color: var(--accent);
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

        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .chart-container h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .chart-wrapper {
            height: 300px;
            position: relative;
        }

        .applications-table {
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

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-student {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-instructor {
            background: #fef3c7;
            color: #92400e;
        }

        .time-indicator {
            font-size: 0.85rem;
            color: #64748b;
        }

        .time-fast {
            color: var(--success);
        }

        .time-medium {
            color: var(--warning);
        }

        .time-slow {
            color: var(--danger);
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

        .export-options {
            display: flex;
            gap: 0.5rem;
        }

        .btn-export {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .btn-export:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .export-options {
                flex-direction: column;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/history.php" class="active">
                            <i class="fas fa-history"></i> History</a></li>
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
                        History
                    </div>
                    <h1>Application History</h1>
                </div>
                <div class="export-options">
                    <button class="btn-export" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> CSV
                    </button>
                    <button class="btn-export" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button class="btn-export" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Processed</div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-number"><?php echo $stats['approved']; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-number"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
                <div class="stat-card time">
                    <div class="stat-number">
                        <?php echo $stats['avg_review_time_hours'] ? round($stats['avg_review_time_hours']) : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Avg. Review Time (Hours)</div>
                </div>
            </div>

            <!-- Chart -->
            <div class="chart-container">
                <h3>Application Decisions Trend</h3>
                <div class="chart-wrapper">
                    <canvas id="applicationsChart"></canvas>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter History</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved Only</option>
                            <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reviewed By</label>
                        <select name="reviewer_id" class="form-control">
                            <option value="">All Reviewers</option>
                            <?php foreach ($reviewers as $reviewer): ?>
                                <option value="<?php echo $reviewer['id']; ?>"
                                    <?php echo $reviewer_id == $reviewer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($reviewer['first_name'] . ' ' . $reviewer['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" class="form-control">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>"
                                    <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['name']); ?>
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
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="applications-table">
                <div class="table-header">
                    <h3>Historical Applications</h3>
                    <div class="pagination-info">
                        Showing <?php echo count($applications); ?> records
                    </div>
                </div>

                <?php if (!empty($applications)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Applicant</th>
                                    <th>Applying As</th>
                                    <th>Program</th>
                                    <th>Submitted</th>
                                    <th>Reviewed</th>
                                    <th>Review Time</th>
                                    <th>Reviewed By</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app):
                                    $review_time = '';
                                    $time_class = '';
                                    if ($app['created_at'] && $app['reviewed_at']) {
                                        $hours = round((strtotime($app['reviewed_at']) - strtotime($app['created_at'])) / 3600, 1);
                                        $review_time = $hours . ' hours';
                                        if ($hours < 24) {
                                            $time_class = 'time-fast';
                                        } elseif ($hours < 72) {
                                            $time_class = 'time-medium';
                                        } else {
                                            $time_class = 'time-slow';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td>#<?php echo str_pad($app['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong><br>
                                            <small style="color: #64748b;"><?php echo htmlspecialchars($app['email']); ?></small>
                                        </td>
                                        <td>
                                            <span class="role-badge badge-<?php echo $app['applying_as']; ?>">
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
                                            <?php echo date('M j, Y', strtotime($app['reviewed_at'])); ?><br>
                                            <small style="color: #64748b;"><?php echo date('g:i A', strtotime($app['reviewed_at'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="time-indicator <?php echo $time_class; ?>">
                                                <?php echo $review_time ?: 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($app['reviewer_first_name']): ?>
                                                <?php echo htmlspecialchars($app['reviewer_first_name'] . ' ' . $app['reviewer_last_name']); ?>
                                            <?php else: ?>
                                                <span style="color: #64748b;">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $app['status']; ?>">
                                                <?php echo ucfirst($app['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>"
                                                class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $app['user_id']; ?>"
                                                class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                <i class="fas fa-user"></i> Profile
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo count($applications); ?> of <?php echo count($applications); ?> applications
                        </div>
                        <div class="page-numbers">
                            <a href="#" class="page-link active">1</a>
                            <a href="#" class="page-link">2</a>
                            <a href="#" class="page-link">3</a>
                            <a href="#" class="page-link">Next</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Historical Applications Found</h3>
                        <p>There are no approved or rejected applications matching your filters.</p>
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-file-alt"></i> View Pending Applications
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Initialize Chart
        const ctx = document.getElementById('applicationsChart').getContext('2d');

        // Sample data - in a real application, this would come from an API
        const applicationsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                        label: 'Approved',
                        data: [12, 19, 15, 25, 22, 30, 28, 35, 32, 40, 38, 45],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Rejected',
                        data: [5, 8, 6, 10, 9, 12, 11, 15, 14, 18, 16, 20],
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Applications'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Month'
                        }
                    }
                }
            }
        });

        // Export functions
        function exportToCSV() {
            const params = new URLSearchParams(window.location.search);
            params.set('type', 'csv');
            window.location.href = '<?php echo BASE_URL; ?>modules/admin/applications/export.php?' + params.toString();
        }

        function exportToPDF() {
            const params = new URLSearchParams(window.location.search);
            params.set('type', 'pdf');
            window.location.href = '<?php echo BASE_URL; ?>modules/admin/applications/export.php?' + params.toString();
        }

        // Optional: Add loading indicators
        function showExportLoading(type) {
            const btn = document.querySelector(`.btn-export:nth-child(${type === 'csv' ? 1 : 2})`);
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            return () => {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            };
        }

        // Update chart when filters change
        document.querySelector('form.filter-form').addEventListener('submit', function(e) {
            // In production, this would fetch new chart data via AJAX
            // For now, we'll just show a loading indicator
            const chartWrapper = document.querySelector('.chart-wrapper');
            chartWrapper.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b;">Loading chart data...</div>';
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>