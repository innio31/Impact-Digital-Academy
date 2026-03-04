<?php
$page_title = 'Statistics & Analytics';
require_once 'auth_check.php';
require_once '../api/config.php';

// Get date range filters
$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$school_filter = isset($_GET['school_id']) ? intval($_GET['school_id']) : 0;
$subject_filter = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

// Get all schools for filter
$stmt = $pdo->query("SELECT id, school_name FROM schools ORDER BY school_name");
$schools = $stmt->fetchAll();

// Get all subjects for filter
$stmt = $pdo->query("SELECT id, subject_name FROM master_subjects WHERE is_active = 1 ORDER BY subject_name");
$subjects = $stmt->fetchAll();

// Overall Statistics
$stats = [];

// Total questions
$stmt = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM central_objective_questions) as objective_count,
    (SELECT COUNT(*) FROM central_theory_questions) as theory_count,
    (SELECT COUNT(*) FROM central_objective_questions WHERE is_approved = 1) as approved_objective,
    (SELECT COUNT(*) FROM central_theory_questions WHERE is_approved = 1) as approved_theory
");
$stats['questions'] = $stmt->fetch();

// Total schools and activity
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_schools,
    SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) as active_schools,
    SUM(CASE WHEN subscription_status = 'trial' THEN 1 ELSE 0 END) as trial_schools
    FROM schools
");
$stats['schools'] = $stmt->fetch();

// Download statistics
$download_sql = "SELECT 
    COUNT(*) as total_downloads,
    COUNT(DISTINCT school_id) as active_schools_downloading,
    COUNT(DISTINCT question_id) as unique_questions_downloaded,
    SUM(CASE WHEN DATE(downloaded_at) = CURDATE() THEN 1 ELSE 0 END) as downloads_today,
    SUM(CASE WHEN WEEK(downloaded_at) = WEEK(CURDATE()) THEN 1 ELSE 0 END) as downloads_this_week,
    SUM(CASE WHEN MONTH(downloaded_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) as downloads_this_month
    FROM question_downloads";

$stmt = $pdo->query($download_sql);
$stats['downloads'] = $stmt->fetch();

// Get download trends (last 30 days)
$trend_sql = "SELECT 
    DATE(downloaded_at) as date,
    COUNT(*) as count,
    COUNT(DISTINCT school_id) as schools
    FROM question_downloads
    WHERE downloaded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(downloaded_at)
    ORDER BY date DESC";

$stmt = $pdo->query($trend_sql);
$download_trends = $stmt->fetchAll();

// Get popular subjects
$popular_subjects_sql = "SELECT 
    s.subject_name,
    COUNT(d.id) as download_count,
    COUNT(DISTINCT d.school_id) as school_count
    FROM question_downloads d
    JOIN central_objective_questions q ON d.question_id = q.id AND d.question_type = 'objective'
    JOIN master_subjects s ON q.subject_id = s.id
    WHERE d.downloaded_at BETWEEN ? AND ?
    GROUP BY s.id
    ORDER BY download_count DESC
    LIMIT 10";

$stmt = $pdo->prepare($popular_subjects_sql);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$popular_subjects = $stmt->fetchAll();

// Get top downloading schools
$top_schools_sql = "SELECT 
    s.school_name,
    s.school_code,
    COUNT(d.id) as download_count,
    COUNT(DISTINCT d.question_id) as unique_questions,
    MAX(d.downloaded_at) as last_download
    FROM schools s
    LEFT JOIN question_downloads d ON s.id = d.school_id
    WHERE d.downloaded_at BETWEEN ? AND ?
    GROUP BY s.id
    HAVING download_count > 0
    ORDER BY download_count DESC
    LIMIT 10";

$stmt = $pdo->prepare($top_schools_sql);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$top_schools = $stmt->fetchAll();

// Get API usage stats
$api_sql = "SELECT 
    COUNT(*) as total_calls,
    COUNT(DISTINCT school_id) as active_schools_api,
    SUM(CASE WHEN response_code = 200 THEN 1 ELSE 0 END) as successful_calls,
    SUM(CASE WHEN response_code != 200 THEN 1 ELSE 0 END) as failed_calls,
    endpoint,
    COUNT(*) as endpoint_count
    FROM api_logs
    WHERE created_at BETWEEN ? AND ?
    GROUP BY endpoint WITH ROLLUP";

$stmt = $pdo->prepare($api_sql);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$api_stats = $stmt->fetchAll();

// Get hourly activity (last 24h)
$hourly_sql = "SELECT 
    HOUR(created_at) as hour,
    COUNT(*) as count
    FROM api_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(created_at)
    ORDER BY hour";

$stmt = $pdo->query($hourly_sql);
$hourly_activity = $stmt->fetchAll();
$hourly_data = array_fill(0, 24, 0);
foreach ($hourly_activity as $row) {
    $hourly_data[$row['hour']] = $row['count'];
}

// Get question difficulty distribution
$difficulty_sql = "SELECT 
    'objective' as type,
    difficulty_level,
    COUNT(*) as count
    FROM central_objective_questions
    GROUP BY difficulty_level
    UNION ALL
    SELECT 
    'theory' as type,
    difficulty_level,
    COUNT(*) as count
    FROM central_theory_questions
    GROUP BY difficulty_level";

$stmt = $pdo->query($difficulty_sql);
$difficulty_dist = $stmt->fetchAll();

// Format for charts
$easy_count = 0;
$medium_count = 0;
$hard_count = 0;
foreach ($difficulty_dist as $d) {
    if ($d['difficulty_level'] === 'easy') $easy_count += $d['count'];
    if ($d['difficulty_level'] === 'medium') $medium_count += $d['count'];
    if ($d['difficulty_level'] === 'hard') $hard_count += $d['count'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Central CBT - <?php echo $page_title; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="icon" href="../../portals/public/images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #4361ee;
            --primary-dark: #3a56d4;
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --border-color: #dee2e6;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: var(--dark-text);
            line-height: 1.6;
        }

        /* Layout */
        .wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 20px 0;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 10px;
        }

        .nav-links li a i {
            width: 20px;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding-left: 25px;
        }

        .nav-links li.logout {
            margin-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 10px;
        }

        .user-info {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            min-width: 0;
        }

        .top-bar {
            background: #fff;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--secondary-color);
            display: none;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-title i {
            color: var(--primary-color);
        }

        .date {
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        .content-wrapper {
            padding: 20px;
        }

        /* Filter Bar */
        .filter-bar {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.85rem;
            color: var(--secondary-color);
            font-weight: 500;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .stat-icon.primary {
            background: #e3f2fd;
            color: var(--primary-color);
        }

        .stat-icon.success {
            background: #d4edda;
            color: var(--success-color);
        }

        .stat-icon.warning {
            background: #fff3cd;
            color: var(--warning-color);
        }

        .stat-icon.info {
            background: #d1ecf1;
            color: var(--info-color);
        }

        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
        }

        .stat-details {
            flex: 1;
        }

        .stat-details h3 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .stat-details p {
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .stat-details small {
            color: #999;
            font-size: 0.8rem;
        }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .chart-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .chart-card h3 {
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--dark-text);
        }

        .chart-card h3 i {
            color: var(--primary-color);
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Tables */
        .table-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .table-card h3 {
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            background: var(--light-bg);
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .rank-1 {
            background: rgba(255, 215, 0, 0.1);
        }

        .rank-2 {
            background: rgba(192, 192, 192, 0.1);
        }

        .rank-3 {
            background: rgba(205, 127, 50, 0.1);
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Progress bars */
        .progress-container {
            margin-top: 20px;
        }

        .progress-item {
            margin-bottom: 15px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 8px;
            background: var(--light-bg);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            border-radius: 4px;
            transition: width 0.3s;
        }

        /* Loading Spinner */
        .loading-spinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            z-index: 2000;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .top-bar {
                padding: 12px 15px;
            }

            .content-wrapper {
                padding: 15px;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .date {
                display: none;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                flex-direction: column;
                width: 100%;
            }

            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 15px;
            }

            .chart-container {
                height: 250px;
            }
        }

        @media (max-width: 480px) {
            .user-info span {
                display: none;
            }

            .stat-card {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Print styles */
        @media print {

            .sidebar,
            .top-bar,
            .filter-bar,
            .btn {
                display: none;
            }

            .main-content {
                margin-left: 0;
            }

            .stat-card {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Central CBT</h3>
                <p>Admin Panel</p>
            </div>

            <ul class="nav-links">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="subjects.php">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="topics.php">
                        <i class="fas fa-tags"></i> Topics
                    </a>
                </li>
                <li>
                    <a href="manage_questions.php">
                        <i class="fas fa-question-circle"></i> Questions
                    </a>
                </li>
                <li>
                    <a href="manage_schools.php">
                        <i class="fas fa-school"></i> Schools
                    </a>
                </li>
                <li>
                    <a href="view_stats.php" class="active">
                        <i class="fas fa-chart-bar"></i> Statistics
                    </a>
                </li>
                <li class="logout">
                    <a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>

            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <i class="fas fa-chart-bar"></i> <?php echo $page_title; ?>
                </div>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                </div>
            </header>

            <div class="content-wrapper">
                <!-- Filter Section -->
                <div class="filter-bar">
                    <form method="GET" action="" class="filter-form">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> From Date</label>
                            <input type="date" name="from" value="<?php echo $date_from; ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> To Date</label>
                            <input type="date" name="to" value="<?php echo $date_to; ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-school"></i> School</label>
                            <select name="school_id">
                                <option value="0">All Schools</option>
                                <?php foreach ($schools as $school): ?>
                                    <option value="<?php echo $school['id']; ?>" <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($school['school_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-book"></i> Subject</label>
                            <select name="subject_id">
                                <option value="0">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="view_stats.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Key Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-download"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo number_format($stats['downloads']['total_downloads']); ?></h3>
                            <p>Total Downloads</p>
                            <small>
                                <i class="fas fa-chart-line"></i>
                                Today: <?php echo $stats['downloads']['downloads_today']; ?> |
                                Week: <?php echo $stats['downloads']['downloads_this_week']; ?> |
                                Month: <?php echo $stats['downloads']['downloads_this_month']; ?>
                            </small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-school"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['downloads']['active_schools_downloading']; ?> / <?php echo $stats['schools']['total_schools']; ?></h3>
                            <p>Active Schools</p>
                            <small>
                                <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                                <?php echo $stats['schools']['active_schools']; ?> Active |
                                <?php echo $stats['schools']['trial_schools']; ?> Trial
                            </small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo number_format($stats['questions']['objective_count'] + $stats['questions']['theory_count']); ?></h3>
                            <p>Total Questions</p>
                            <small>
                                <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                                <?php echo $stats['questions']['approved_objective'] + $stats['questions']['approved_theory']; ?> Approved
                            </small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon info">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['downloads']['unique_questions_downloaded']; ?></h3>
                            <p>Unique Questions Used</p>
                            <small>
                                <i class="fas fa-percent"></i>
                                <?php
                                $total_q = $stats['questions']['objective_count'] + $stats['questions']['theory_count'];
                                $usage_rate = $total_q > 0 ? round(($stats['downloads']['unique_questions_downloaded'] / $total_q) * 100, 1) : 0;
                                echo $usage_rate; ?>% Usage Rate
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Charts Row 1 -->
                <div class="chart-grid">
                    <!-- Download Trends -->
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line"></i> Download Trends (Last 30 Days)</h3>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <!-- Hourly Activity -->
                    <div class="chart-card">
                        <h3><i class="fas fa-clock"></i> API Activity (Last 24 Hours)</h3>
                        <div class="chart-container">
                            <canvas id="hourlyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Charts Row 2 -->
                <div class="chart-grid">
                    <!-- Subject Popularity -->
                    <div class="chart-card">
                        <h3><i class="fas fa-book"></i> Most Downloaded Subjects</h3>
                        <div class="chart-container">
                            <canvas id="subjectChart"></canvas>
                        </div>
                    </div>

                    <!-- Difficulty Distribution -->
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie"></i> Question Difficulty Distribution</h3>
                        <div class="chart-container">
                            <canvas id="difficultyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top Schools Table -->
                <div class="table-card">
                    <h3><i class="fas fa-trophy"></i> Top Downloading Schools</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>School</th>
                                    <th>Code</th>
                                    <th>Downloads</th>
                                    <th>Unique Questions</th>
                                    <th>Last Download</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rank = 1;
                                $max_downloads = !empty($top_schools) ? $top_schools[0]['download_count'] : 1;
                                foreach ($top_schools as $school):
                                    $performance = round(($school['download_count'] / $max_downloads) * 100);
                                ?>
                                    <tr class="rank-<?php echo $rank; ?>">
                                        <td>
                                            <strong>#<?php echo $rank; ?></strong>
                                            <?php if ($rank == 1): ?> 🏆
                                            <?php elseif ($rank == 2): ?> 🥈
                                            <?php elseif ($rank == 3): ?> 🥉
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                                        <td><code><?php echo $school['school_code']; ?></code></td>
                                        <td><strong><?php echo number_format($school['download_count']); ?></strong></td>
                                        <td><?php echo $school['unique_questions']; ?></td>
                                        <td><?php echo date('M d, H:i', strtotime($school['last_download'])); ?></td>
                                        <td style="width: 150px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $performance; ?>%;"></div>
                                            </div>
                                            <small><?php echo $performance; ?>% of top</small>
                                        </td>
                                    </tr>
                                <?php
                                    $rank++;
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- API Usage Stats -->
                <div class="table-card">
                    <h3><i class="fas fa-code"></i> API Endpoint Usage</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>Total Calls</th>
                                    <th>Successful</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_calls = 0;
                                $endpoint_stats = [];
                                foreach ($api_stats as $stat) {
                                    if ($stat['endpoint'] !== null) {
                                        $endpoint_stats[] = $stat;
                                        $total_calls += $stat['total_calls'];
                                    }
                                }

                                foreach ($endpoint_stats as $stat):
                                    $success_rate = $stat['total_calls'] > 0 ? round(($stat['successful_calls'] / $stat['total_calls']) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <code><?php echo $stat['endpoint'] ?: 'All Endpoints'; ?></code>
                                        </td>
                                        <td><strong><?php echo number_format($stat['total_calls']); ?></strong></td>
                                        <td style="color: var(--success-color);"><?php echo number_format($stat['successful_calls']); ?></td>
                                        <td style="color: var(--danger-color);"><?php echo number_format($stat['failed_calls']); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <span><?php echo $success_rate; ?>%</span>
                                                <div class="progress-bar" style="width: 100px;">
                                                    <div class="progress-fill" style="width: <?php echo $success_rate; ?>%; background: <?php echo $success_rate > 90 ? 'var(--success-color)' : ($success_rate > 70 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background: var(--light-bg); font-weight: bold;">
                                    <td>TOTAL</td>
                                    <td><?php echo number_format($total_calls); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Stats Summary -->
                <div class="chart-grid">
                    <div class="chart-card">
                        <h3><i class="fas fa-percent"></i> Question Approval Rate</h3>
                        <div style="text-align: center; padding: 20px;">
                            <?php
                            $total_q = $stats['questions']['objective_count'] + $stats['questions']['theory_count'];
                            $approved_q = $stats['questions']['approved_objective'] + $stats['questions']['approved_theory'];
                            $approval_rate = $total_q > 0 ? round(($approved_q / $total_q) * 100, 1) : 0;
                            ?>
                            <div style="font-size: 3rem; font-weight: bold; color: <?php echo $approval_rate > 80 ? 'var(--success-color)' : ($approval_rate > 50 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>;">
                                <?php echo $approval_rate; ?>%
                            </div>
                            <p style="color: #666;"><?php echo $approved_q; ?> approved out of <?php echo $total_q; ?> total</p>

                            <div style="margin-top: 20px;">
                                <div class="progress-item">
                                    <div class="progress-label">
                                        <span>Objective</span>
                                        <span><?php echo $stats['questions']['objective_count']; ?> total (<?php echo $stats['questions']['approved_objective']; ?> approved)</span>
                                    </div>
                                    <div class="progress-bar">
                                        <?php $obj_rate = $stats['questions']['objective_count'] > 0 ? ($stats['questions']['approved_objective'] / $stats['questions']['objective_count']) * 100 : 0; ?>
                                        <div class="progress-fill" style="width: <?php echo $obj_rate; ?>%;"></div>
                                    </div>
                                </div>

                                <div class="progress-item">
                                    <div class="progress-label">
                                        <span>Theory</span>
                                        <span><?php echo $stats['questions']['theory_count']; ?> total (<?php echo $stats['questions']['approved_theory']; ?> approved)</span>
                                    </div>
                                    <div class="progress-bar">
                                        <?php $theory_rate = $stats['questions']['theory_count'] > 0 ? ($stats['questions']['approved_theory'] / $stats['questions']['theory_count']) * 100 : 0; ?>
                                        <div class="progress-fill" style="width: <?php echo $theory_rate; ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="chart-card">
                        <h3><i class="fas fa-calendar-check"></i> Quick Actions</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px;">
                            <a href="manage_questions.php?status=pending" class="btn btn-warning" style="text-align: center;">
                                <i class="fas fa-hourglass-half"></i>
                                Review Pending<br>
                                <small><?php
                                        $pending = ($stats['questions']['objective_count'] - $stats['questions']['approved_objective']) +
                                            ($stats['questions']['theory_count'] - $stats['questions']['approved_theory']);
                                        echo $pending; ?> questions
                                </small>
                            </a>

                            <a href="manage_schools.php" class="btn btn-primary" style="text-align: center;">
                                <i class="fas fa-school"></i>
                                Manage Schools<br>
                                <small><?php echo $stats['schools']['total_schools']; ?> registered</small>
                            </a>

                            <a href="manage_questions.php?type=objective" class="btn btn-info" style="text-align: center;">
                                <i class="fas fa-list"></i>
                                Objective Questions<br>
                                <small><?php echo $stats['questions']['objective_count']; ?> total</small>
                            </a>

                            <a href="manage_questions.php?type=theory" class="btn btn-success" style="text-align: center;">
                                <i class="fas fa-pencil-alt"></i>
                                Theory Questions<br>
                                <small><?php echo $stats['questions']['theory_count']; ?> total</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle sidebar on mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Prepare data for charts
        const trendData = {
            labels: [<?php
                        $dates = array_reverse($download_trends);
                        foreach ($dates as $i => $trend) {
                            echo "'" . date('M d', strtotime($trend['date'])) . "'";
                            if ($i < count($dates) - 1) echo ',';
                        }
                        ?>],
            downloads: [<?php
                        foreach ($dates as $i => $trend) {
                            echo $trend['count'];
                            if ($i < count($dates) - 1) echo ',';
                        }
                        ?>],
            schools: [<?php
                        foreach ($dates as $i => $trend) {
                            echo $trend['schools'];
                            if ($i < count($dates) - 1) echo ',';
                        }
                        ?>]
        };

        // Download Trends Chart
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [{
                    label: 'Downloads',
                    data: trendData.downloads,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Active Schools',
                    data: trendData.schools,
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46, 204, 113, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Downloads'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                        title: {
                            display: true,
                            text: 'Schools'
                        }
                    }
                }
            }
        });

        // Hourly Activity Chart
        new Chart(document.getElementById('hourlyChart'), {
            type: 'bar',
            data: {
                labels: Array.from({
                    length: 24
                }, (_, i) => {
                    const hour = i;
                    const ampm = hour >= 12 ? 'PM' : 'AM';
                    const hour12 = hour % 12 || 12;
                    return `${hour12} ${ampm}`;
                }),
                datasets: [{
                    label: 'API Calls',
                    data: [<?php echo implode(',', $hourly_data); ?>],
                    backgroundColor: 'rgba(52, 152, 219, 0.7)',
                    borderColor: '#3498db',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Calls'
                        }
                    }
                }
            }
        });

        // Subject Popularity Chart
        new Chart(document.getElementById('subjectChart'), {
            type: 'bar',
            data: {
                labels: [<?php
                            foreach ($popular_subjects as $i => $subject) {
                                echo "'" . addslashes($subject['subject_name']) . "'";
                                if ($i < count($popular_subjects) - 1) echo ',';
                            }
                            ?>],
                datasets: [{
                    label: 'Downloads',
                    data: [<?php
                            foreach ($popular_subjects as $i => $subject) {
                                echo $subject['download_count'];
                                if ($i < count($popular_subjects) - 1) echo ',';
                            }
                            ?>],
                    backgroundColor: 'rgba(67, 97, 238, 0.7)',
                    borderColor: '#4361ee',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Difficulty Distribution Chart
        new Chart(document.getElementById('difficultyChart'), {
            type: 'doughnut',
            data: {
                labels: ['Easy', 'Medium', 'Hard'],
                datasets: [{
                    data: [<?php echo $easy_count; ?>, <?php echo $medium_count; ?>, <?php echo $hard_count; ?>],
                    backgroundColor: [
                        'rgba(46, 204, 113, 0.8)',
                        'rgba(243, 156, 18, 0.8)',
                        'rgba(231, 76, 60, 0.8)'
                    ],
                    borderColor: [
                        '#2ecc71',
                        '#f39c12',
                        '#e74c3c'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Auto-refresh every 5 minutes (optional)
        // setInterval(function() {
        //     location.reload();
        // }, 300000);

        // Print functionality
        function printReport() {
            window.print();
        }
    </script>
</body>

</html>