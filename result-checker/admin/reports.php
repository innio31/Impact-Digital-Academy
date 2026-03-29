<?php
// admin/reports.php - Analytics and Reports Dashboard
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: login.php");
    exit();
}

require_once '../config/config.php';

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$success_message = '';
$error_message = '';

$db = getDB();

// Get filter parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'overview';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$school_filter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

// Get schools for filter
$stmt = $db->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name");
$schools = $stmt->fetchAll();

// Build date condition for queries
$date_condition = "DATE(created_at) BETWEEN '$date_from' AND '$date_to'";
$school_condition = $school_filter > 0 ? "AND school_id = $school_filter" : "";

// ============ OVERVIEW STATISTICS ============
// Total revenue
$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM pin_batches 
    WHERE status = 'completed'
");
$total_revenue = $stmt->fetch()['total'];

// Revenue for selected period
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total 
    FROM pin_batches 
    WHERE status = 'completed' AND DATE(generated_at) BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$period_revenue = $stmt->fetch()['total'];

// Total PINs generated
$stmt = $db->query("SELECT COUNT(*) as total FROM result_pins");
$total_pins = $stmt->fetch()['total'];

// PINs used
$stmt = $db->query("SELECT SUM(used_count) as total FROM result_pins");
$total_views = $stmt->fetch()['total'] ?? 0;

// Active schools
$stmt = $db->query("SELECT COUNT(*) as total FROM schools WHERE status = 'active'");
$active_schools = $stmt->fetch()['total'];

// Total students
$stmt = $db->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
$total_students = $stmt->fetch()['total'];

// ============ PIN USAGE STATISTICS ============
$stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'unused' THEN 1 END) as unused,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN status = 'used_up' THEN 1 END) as used_up,
        COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired
    FROM result_pins
");
$stmt->execute();
$pin_status = $stmt->fetch();

// ============ MONTHLY REVENUE CHART DATA ============
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(generated_at, '%Y-%m') as month,
        COUNT(*) as batch_count,
        SUM(quantity) as pins_generated,
        SUM(total_amount) as revenue
    FROM pin_batches 
    WHERE status = 'completed'
    GROUP BY DATE_FORMAT(generated_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12
");
$stmt->execute();
$monthly_data = $stmt->fetchAll();

// ============ SCHOOL PERFORMANCE ============
$stmt = $db->prepare("
    SELECT 
        s.id,
        s.school_name,
        COUNT(DISTINCT pb.id) as batch_count,
        SUM(pb.quantity) as total_pins,
        SUM(pb.total_amount) as revenue,
        (SELECT COUNT(*) FROM result_pins WHERE school_id = s.id) as pins_issued,
        (SELECT SUM(used_count) FROM result_pins WHERE school_id = s.id) as total_views
    FROM schools s
    LEFT JOIN pin_batches pb ON s.id = pb.school_id AND pb.status = 'completed'
    WHERE s.status = 'active'
    GROUP BY s.id
    ORDER BY revenue DESC
    LIMIT 10
");
$stmt->execute();
$top_schools = $stmt->fetchAll();

// ============ RECENT ACTIVITIES ============
$stmt = $db->prepare("
    SELECT 
        'pin_generation' as type,
        pb.batch_number as reference,
        pb.quantity as amount,
        pb.total_amount as value,
        pb.generated_at as created_at,
        s.school_name,
        a.full_name as admin_name
    FROM pin_batches pb
    JOIN schools s ON pb.school_id = s.id
    JOIN portal_admins a ON pb.generated_by = a.id
    WHERE DATE(pb.generated_at) BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'result_view' as type,
        rp.pin_code as reference,
        1 as amount,
        rp.price as value,
        pl.accessed_at as created_at,
        s.school_name,
        NULL as admin_name
    FROM pin_usage_log pl
    JOIN result_pins rp ON pl.pin_id = rp.id
    JOIN schools s ON rp.school_id = s.id
    WHERE DATE(pl.accessed_at) BETWEEN ? AND ?
    
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->execute([$date_from, $date_to, $date_from, $date_to]);
$recent_activities = $stmt->fetchAll();

// ============ DAILY USAGE TREND ============
$stmt = $db->prepare("
    SELECT 
        DATE(accessed_at) as date,
        COUNT(*) as view_count,
        COUNT(DISTINCT pin_id) as unique_pins
    FROM pin_usage_log
    WHERE DATE(accessed_at) BETWEEN ? AND ?
    GROUP BY DATE(accessed_at)
    ORDER BY date ASC
");
$stmt->execute([$date_from, $date_to]);
$daily_trend = $stmt->fetchAll();

// ============ EXPORT DATA ============
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];

    if ($export_type === 'pins') {
        $stmt = $db->prepare("
            SELECT 
                rp.pin_code,
                rp.batch_number,
                s.school_name,
                rp.status,
                rp.used_count,
                rp.max_uses,
                rp.price,
                rp.generated_at,
                rp.first_used_at,
                rp.last_used_at,
                stu.full_name as student_name,
                stu.admission_number
            FROM result_pins rp
            JOIN schools s ON rp.school_id = s.id
            LEFT JOIN students stu ON rp.student_id = stu.id
            ORDER BY rp.generated_at DESC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pins_export_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['PIN Code', 'Batch', 'School', 'Status', 'Used/Max', 'Price (₦)', 'Generated Date', 'First Used', 'Last Used', 'Student Name', 'Admission Number']);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['pin_code'],
                $row['batch_number'],
                $row['school_name'],
                $row['status'],
                $row['used_count'] . '/' . $row['max_uses'],
                $row['price'],
                $row['generated_at'],
                $row['first_used_at'] ?? '',
                $row['last_used_at'] ?? '',
                $row['student_name'] ?? '',
                $row['admission_number'] ?? ''
            ]);
        }
        fclose($output);
        exit();
    }

    if ($export_type === 'revenue') {
        $stmt = $db->prepare("
            SELECT 
                pb.batch_number,
                s.school_name,
                pb.quantity,
                pb.price_per_pin,
                pb.total_amount,
                pb.generated_at,
                a.full_name as generated_by
            FROM pin_batches pb
            JOIN schools s ON pb.school_id = s.id
            JOIN portal_admins a ON pb.generated_by = a.id
            WHERE pb.status = 'completed'
            ORDER BY pb.generated_at DESC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="revenue_export_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Batch Number', 'School', 'Quantity', 'Price per PIN (₦)', 'Total Amount (₦)', 'Generated Date', 'Generated By']);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['batch_number'],
                $row['school_name'],
                $row['quantity'],
                $row['price_per_pin'],
                $row['total_amount'],
                $row['generated_at'],
                $row['generated_by']
            ]);
        }
        fclose($output);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Reports - MyResultChecker Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Chart.js for charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            overflow-x: hidden;
        }

        .mobile-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 48px;
            height: 48px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #2c3e50, #1a252f);
            color: white;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: #3498db;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.7;
        }

        .admin-info {
            padding: 20px;
            margin: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            text-align: center;
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .admin-info p {
            font-size: 0.7rem;
            opacity: 0.7;
            text-transform: capitalize;
        }

        .nav-links {
            list-style: none;
            padding: 10px 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-links i {
            width: 22px;
            font-size: 18px;
        }

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

        .top-bar {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .page-title h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .page-title p {
            color: #7f8c8d;
            font-size: 0.85rem;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #3498db;
            color: #3498db;
        }

        .btn-outline:hover {
            background: #3498db;
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card.revenue {
            border-left-color: #27ae60;
        }

        .stat-card.pins {
            border-left-color: #3498db;
        }

        .stat-card.schools {
            border-left-color: #f39c12;
        }

        .stat-card.students {
            border-left-color: #9b59b6;
        }

        .stat-card.views {
            border-left-color: #e74c3c;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #7f8c8d;
            margin-top: 5px;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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
            font-size: 0.7rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        /* Report Sections */
        .report-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-header h2 {
            font-size: 1.1rem;
            color: #2c3e50;
        }

        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .mini-stat {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .mini-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .mini-stat-label {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        /* School Rankings */
        .school-rank {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .rank-number {
            width: 30px;
            height: 30px;
            background: #3498db;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .school-info {
            flex: 1;
        }

        .school-name {
            font-weight: 500;
        }

        .school-stats {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        .school-revenue {
            font-weight: 600;
            color: #27ae60;
        }

        /* Activity Timeline */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .activity-icon.pin {
            background: #e8f4fd;
            color: #3498db;
        }

        .activity-icon.view {
            background: #d5f4e6;
            color: #27ae60;
        }

        .activity-details {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .activity-meta {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        .activity-value {
            font-weight: 600;
            color: #27ae60;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar-overlay.active {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 75px 15px 20px;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                text-align: center;
            }

            .chart-container {
                height: 250px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                grid-template-columns: 1fr;
            }
        }

        .text-success {
            color: #27ae60;
        }

        .text-primary {
            color: #3498db;
        }

        .text-warning {
            color: #f39c12;
        }

        .text-danger {
            color: #e74c3c;
        }
    </style>
</head>

<body>
    <button class="mobile-toggle" id="mobileToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3>MyResultChecker</h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator'); ?></h4>
            <p><?php echo ucfirst($_SESSION['admin_role'] ?? 'admin'); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="schools.php"><i class="fas fa-school"></i> Schools</a></li>
            <li><a href="students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php" class="active"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-chart-line"></i> Analytics & Reports</h1>
                <p>View system performance, revenue analytics, and usage statistics</p>
            </div>
            <div class="export-buttons">
                <a href="?export=pins" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export PINs
                </a>
                <a href="?export=revenue" class="btn btn-success">
                    <i class="fas fa-file-csv"></i> Export Revenue
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-school"></i> School (Optional)</label>
                    <select name="school_id">
                        <option value="0">All Schools</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?php echo $school['id']; ?>" <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Update Reports
                    </button>
                </div>
            </form>
        </div>

        <!-- Overview Statistics -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-number">₦<?php echo number_format($period_revenue, 2); ?></div>
                <div class="stat-label">Revenue (Selected Period)</div>
                <small style="color: #7f8c8d;">Total: ₦<?php echo number_format($total_revenue, 2); ?></small>
            </div>
            <div class="stat-card pins">
                <div class="stat-number"><?php echo number_format($total_pins); ?></div>
                <div class="stat-label">Total PINs Generated</div>
            </div>
            <div class="stat-card schools">
                <div class="stat-number"><?php echo number_format($active_schools); ?></div>
                <div class="stat-label">Active Schools</div>
            </div>
            <div class="stat-card students">
                <div class="stat-number"><?php echo number_format($total_students); ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card views">
                <div class="stat-number"><?php echo number_format($total_views); ?></div>
                <div class="stat-label">Total Result Views</div>
            </div>
        </div>

        <!-- Monthly Revenue Chart -->
        <div class="report-section">
            <div class="section-header">
                <h2><i class="fas fa-chart-bar"></i> Monthly Revenue Trend</h2>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
            <div class="stats-row">
                <div class="mini-stat">
                    <div class="mini-stat-value text-success">₦<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="mini-stat-label">Total Revenue (All Time)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value text-primary"><?php echo number_format($total_pins); ?></div>
                    <div class="mini-stat-label">Total PINs Generated</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value text-warning">₦<?php echo number_format($summary['avg_price'] ?? 0, 2); ?></div>
                    <div class="mini-stat-label">Avg Price per PIN</div>
                </div>
            </div>
        </div>

        <!-- PIN Status Distribution -->
        <div class="report-section">
            <div class="section-header">
                <h2><i class="fas fa-chart-pie"></i> PIN Status Distribution</h2>
            </div>
            <div class="stats-row">
                <div class="mini-stat">
                    <div class="mini-stat-value text-primary"><?php echo number_format($pin_status['unused'] ?? 0); ?></div>
                    <div class="mini-stat-label">Unused PINs</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value text-success"><?php echo number_format($pin_status['active'] ?? 0); ?></div>
                    <div class="mini-stat-label">Active (In Use)</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value text-warning"><?php echo number_format($pin_status['used_up'] ?? 0); ?></div>
                    <div class="mini-stat-label">Used Up</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value text-danger"><?php echo number_format($pin_status['expired'] ?? 0); ?></div>
                    <div class="mini-stat-label">Expired</div>
                </div>
            </div>
            <div class="chart-container" style="height: 200px; margin-top: 20px;">
                <canvas id="pinStatusChart"></canvas>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
            <!-- Top Performing Schools -->
            <div class="report-section">
                <div class="section-header">
                    <h2><i class="fas fa-trophy"></i> Top Performing Schools</h2>
                </div>
                <?php if (!empty($top_schools)): ?>
                    <?php $rank = 1;
                    foreach ($top_schools as $school): ?>
                        <div class="school-rank">
                            <div class="rank-number"><?php echo $rank++; ?></div>
                            <div class="school-info">
                                <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
                                <div class="school-stats">
                                    <?php echo number_format($school['batch_count'] ?? 0); ?> batches |
                                    <?php echo number_format($school['total_pins'] ?? 0); ?> PINs |
                                    <?php echo number_format($school['total_views'] ?? 0); ?> views
                                </div>
                            </div>
                            <div class="school-revenue">₦<?php echo number_format($school['revenue'] ?? 0, 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #95a5a6; padding: 20px;">No data available</p>
                <?php endif; ?>
            </div>

            <!-- Daily Usage Trend -->
            <div class="report-section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Daily Result Views</h2>
                </div>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="dailyTrendChart"></canvas>
                </div>
                <?php
                $total_views_period = array_sum(array_column($daily_trend, 'view_count'));
                $avg_views = !empty($daily_trend) ? round($total_views_period / count($daily_trend)) : 0;
                ?>
                <div class="stats-row" style="margin-top: 15px;">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format($total_views_period); ?></div>
                        <div class="mini-stat-label">Total Views (Period)</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?php echo number_format($avg_views); ?></div>
                        <div class="mini-stat-label">Avg Daily Views</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="report-section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Recent Activities</h2>
                <small><?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></small>
            </div>
            <div class="activity-list">
                <?php if (!empty($recent_activities)): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="fas <?php echo $activity['type'] === 'pin_generation' ? 'fa-key' : 'fa-eye'; ?>"></i>
                            </div>
                            <div class="activity-details">
                                <div class="activity-title">
                                    <?php if ($activity['type'] === 'pin_generation'): ?>
                                        PIN Batch Generated: <strong><?php echo htmlspecialchars($activity['reference']); ?></strong>
                                    <?php else: ?>
                                        Result Viewed with PIN: <strong><?php echo htmlspecialchars($activity['reference']); ?></strong>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <?php echo htmlspecialchars($activity['school_name']); ?> •
                                    <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                    <?php if ($activity['admin_name']): ?>
                                        • By: <?php echo htmlspecialchars($activity['admin_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-value">
                                <?php if ($activity['type'] === 'pin_generation'): ?>
                                    ₦<?php echo number_format($activity['value'], 2); ?>
                                <?php else: ?>
                                    <?php echo $activity['amount']; ?> view
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #95a5a6; padding: 40px;">No activities found for selected period</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu
        const mobileToggle = document.getElementById('mobileToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });

        // Revenue Chart
        const monthlyData = <?php echo json_encode(array_reverse($monthly_data)); ?>;
        const months = monthlyData.map(item => item.month);
        const revenueData = monthlyData.map(item => parseFloat(item.revenue));
        const pinsData = monthlyData.map(item => parseInt(item.pins_generated));

        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                        label: 'Revenue (₦)',
                        data: revenueData,
                        backgroundColor: 'rgba(39, 174, 96, 0.7)',
                        borderColor: '#27ae60',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'PINs Generated',
                        data: pinsData,
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: '#3498db',
                        borderWidth: 1,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
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
                            text: 'Revenue (₦)'
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of PINs'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // PIN Status Chart
        const pinStatusCtx = document.getElementById('pinStatusChart').getContext('2d');
        new Chart(pinStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Unused', 'Active', 'Used Up', 'Expired'],
                datasets: [{
                    data: [
                        <?php echo $pin_status['unused'] ?? 0; ?>,
                        <?php echo $pin_status['active'] ?? 0; ?>,
                        <?php echo $pin_status['used_up'] ?? 0; ?>,
                        <?php echo $pin_status['expired'] ?? 0; ?>
                    ],
                    backgroundColor: ['#3498db', '#27ae60', '#f39c12', '#e74c3c'],
                    borderWidth: 0
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

        // Daily Trend Chart
        const dailyData = <?php echo json_encode($daily_trend); ?>;
        const dates = dailyData.map(item => item.date);
        const viewCounts = dailyData.map(item => item.view_count);

        const dailyCtx = document.getElementById('dailyTrendChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Daily Result Views',
                    data: viewCounts,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
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
                            text: 'Number of Views'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>