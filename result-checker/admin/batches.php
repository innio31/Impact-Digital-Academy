<?php
// admin/batches.php - PIN Batches Management
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

// Handle batch deletion (super admin only)
if (isset($_GET['delete']) && $admin_role === 'super_admin') {
    $batch_number = $_GET['delete'];

    try {
        // First check if batch exists
        $stmt = $db->prepare("SELECT batch_number FROM pin_batches WHERE batch_number = ?");
        $stmt->execute([$batch_number]);
        $batch = $stmt->fetch();

        if ($batch) {
            // Delete associated PINs
            $stmt = $db->prepare("DELETE FROM result_pins WHERE batch_number = ?");
            $stmt->execute([$batch_number]);

            // Delete batch record
            $stmt = $db->prepare("DELETE FROM pin_batches WHERE batch_number = ?");
            $stmt->execute([$batch_number]);

            $success_message = "Batch {$batch_number} and all associated PINs deleted successfully";
            logActivity($admin_id, 'admin', 'Deleted PIN batch', "Batch: $batch_number");
        } else {
            $error_message = "Batch not found";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting batch: " . $e->getMessage();
    }
}

// Pagination and filters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$school_filter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build query
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pb.batch_number LIKE ? OR s.school_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($school_filter > 0) {
    $where_conditions[] = "pb.school_id = ?";
    $params[] = $school_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(pb.generated_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(pb.generated_at) <= ?";
    $params[] = $date_to;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM pin_batches pb
    JOIN schools s ON pb.school_id = s.id
    WHERE $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_batches = $stmt->fetch()['total'];
$total_pages = ceil($total_batches / $limit);

// Get batches with statistics
$sql = "
    SELECT pb.*, s.school_name, s.school_code,
           (SELECT COUNT(*) FROM result_pins WHERE batch_number = pb.batch_number) as total_pins,
           (SELECT COUNT(*) FROM result_pins WHERE batch_number = pb.batch_number AND status IN ('active', 'used_up')) as used_pins,
           (SELECT SUM(used_count) FROM result_pins WHERE batch_number = pb.batch_number) as total_views,
           (SELECT SUM(price) FROM result_pins WHERE batch_number = pb.batch_number) as total_value
    FROM pin_batches pb
    JOIN schools s ON pb.school_id = s.id
    WHERE $where_clause
    ORDER BY pb.generated_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

// Get schools for dropdown
$stmt = $db->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name");
$schools = $stmt->fetchAll();

// Get summary statistics
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_batches,
        SUM(quantity) as total_pins_generated,
        SUM(total_amount) as total_revenue,
        AVG(price_per_pin) as avg_price
    FROM pin_batches WHERE status = 'completed'
");
$summary = $stmt->fetch();

$stmt = $db->query("
    SELECT 
        s.school_name,
        COUNT(pb.id) as batch_count,
        SUM(pb.quantity) as total_pins
    FROM pin_batches pb
    JOIN schools s ON pb.school_id = s.id
    GROUP BY pb.school_id
    ORDER BY total_pins DESC
    LIMIT 5
");
$top_schools = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>PIN Batches - MyResultChecker Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

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

        .btn-danger {
            background: #e74c3c;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
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

        .stat-card.batches {
            border-left-color: #3498db;
        }

        .stat-card.pins {
            border-left-color: #27ae60;
        }

        .stat-card.revenue {
            border-left-color: #f39c12;
        }

        .stat-card.avg {
            border-left-color: #9b59b6;
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

        /* Table */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .data-table th,
        .data-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 0.85rem;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .batch-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .batch-header {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .batch-number {
            font-family: monospace;
            font-size: 1rem;
            font-weight: 600;
        }

        .batch-body {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .batch-stat {
            text-align: center;
        }

        .batch-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .batch-stat-label {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        .progress-bar {
            height: 8px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: #27ae60;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .pagination a {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .pagination a:hover {
            background: #3498db;
            color: white;
        }

        .pagination .active {
            background: #3498db;
            color: white;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .alert-error {
            background: #fef2f2;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Top Schools Section */
        .top-schools {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .top-schools h3 {
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 15px;
        }

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

        .school-name {
            flex: 1;
            font-weight: 500;
        }

        .school-stats {
            color: #7f8c8d;
            font-size: 0.8rem;
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

            .batch-header {
                flex-direction: column;
                text-align: center;
            }

            .batch-body {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .view-pins-link {
            color: #3498db;
            text-decoration: none;
        }

        .view-pins-link:hover {
            text-decoration: underline;
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
            <li><a href="batches.php" class="active"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-layer-group"></i> PIN Batches</h1>
                <p>View and manage all PIN generation batches</p>
            </div>
            <a href="pins.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Generate New Batch
            </a>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card batches">
                <div class="stat-number"><?php echo number_format($summary['total_batches'] ?? 0); ?></div>
                <div class="stat-label">Total Batches</div>
            </div>
            <div class="stat-card pins">
                <div class="stat-number"><?php echo number_format($summary['total_pins_generated'] ?? 0); ?></div>
                <div class="stat-label">Total PINs Generated</div>
            </div>
            <div class="stat-card revenue">
                <div class="stat-number">₦<?php echo number_format($summary['total_revenue'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card avg">
                <div class="stat-number">₦<?php echo number_format($summary['avg_price'] ?? 0, 2); ?></div>
                <div class="stat-label">Avg Price per PIN</div>
            </div>
        </div>

        <!-- Top Schools -->
        <?php if (!empty($top_schools)): ?>
            <div class="top-schools">
                <h3><i class="fas fa-trophy"></i> Top Schools by PIN Generation</h3>
                <?php $rank = 1;
                foreach ($top_schools as $school): ?>
                    <div class="school-rank">
                        <div class="rank-number"><?php echo $rank++; ?></div>
                        <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
                        <div class="school-stats">
                            <?php echo number_format($school['batch_count']); ?> batches |
                            <?php echo number_format($school['total_pins']); ?> PINs
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" placeholder="Batch number or school..." value="<?php echo htmlspecialchars($search); ?>">
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
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="batches.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Batches List -->
        <div class="table-container">
            <?php if (!empty($batches)): ?>
                <?php foreach ($batches as $batch):
                    $usage_percentage = ($batch['total_pins'] > 0) ? ($batch['used_pins'] / $batch['total_pins']) * 100 : 0;
                ?>
                    <div class="batch-card">
                        <div class="batch-header">
                            <div>
                                <i class="fas fa-layer-group"></i>
                                <span class="batch-number"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                            </div>
                            <div>
                                <i class="fas fa-school"></i> <?php echo htmlspecialchars($batch['school_name']); ?>
                                <span style="margin-left: 15px;"><i class="fas fa-calendar"></i> <?php echo date('M d, Y H:i', strtotime($batch['generated_at'])); ?></span>
                            </div>
                        </div>
                        <div class="batch-body">
                            <div class="batch-stat">
                                <div class="batch-stat-value"><?php echo number_format($batch['total_pins']); ?></div>
                                <div class="batch-stat-label">Total PINs</div>
                            </div>
                            <div class="batch-stat">
                                <div class="batch-stat-value"><?php echo number_format($batch['used_pins']); ?></div>
                                <div class="batch-stat-label">Used PINs</div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $usage_percentage; ?>%"></div>
                                </div>
                            </div>
                            <div class="batch-stat">
                                <div class="batch-stat-value"><?php echo number_format($batch['total_views'] ?? 0); ?></div>
                                <div class="batch-stat-label">Total Views</div>
                            </div>
                            <div class="batch-stat">
                                <div class="batch-stat-value">₦<?php echo number_format($batch['total_amount'], 2); ?></div>
                                <div class="batch-stat-label">Total Value</div>
                            </div>
                            <div class="batch-stat">
                                <div class="batch-stat-value">₦<?php echo number_format($batch['price_per_pin'], 2); ?></div>
                                <div class="batch-stat-label">Price per PIN</div>
                            </div>
                            <div class="batch-stat">
                                <a href="pins.php?batch=<?php echo urlencode($batch['batch_number']); ?>" class="view-pins-link">
                                    <i class="fas fa-key"></i> View PINs
                                </a>
                                <?php if ($admin_role === 'super_admin'): ?>
                                    <div style="margin-top: 10px;">
                                        <a href="?delete=<?php echo urlencode($batch['batch_number']); ?>"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Delete this entire batch and all its PINs? This cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete Batch
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=1&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'date_from' => $date_from, 'date_to' => $date_to])); ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'date_from' => $date_from, 'date_to' => $date_to])); ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'date_from' => $date_from, 'date_to' => $date_to])); ?>"
                                class="<?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <a href="?page=<?php echo min($total_pages, $page + 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'date_from' => $date_from, 'date_to' => $date_to])); ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'date_from' => $date_from, 'date_to' => $date_to])); ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px; color: #95a5a6;">
                    <i class="fas fa-layer-group" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i>
                    <h3>No Batches Found</h3>
                    <p>Generate your first PIN batch to get started.</p>
                    <a href="pins.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus-circle"></i> Generate PINs
                    </a>
                </div>
            <?php endif; ?>
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

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>

</html>