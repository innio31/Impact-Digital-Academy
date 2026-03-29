<?php
// admin/students.php - View Students across all schools
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$school_id_filter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$class_filter = isset($_GET['class']) ? trim($_GET['class']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

try {
    $db = getDB();

    // Build query conditions - using correct column names
    $where_conditions = ["s.status != 'archived'"];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($school_id_filter > 0) {
        $where_conditions[] = "s.school_id = ?";
        $params[] = $school_id_filter;
    }

    if (!empty($class_filter)) {
        $where_conditions[] = "s.class = ?";
        $params[] = $class_filter;
    }

    if (!empty($status_filter)) {
        $where_conditions[] = "s.status = ?";
        $params[] = $status_filter;
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Get total count
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM students s
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $total_result ? $total_result['total'] : 0;
    $total_pages = $total_students > 0 ? ceil($total_students / $limit) : 1;

    // Get students with school info - FIXED: removed s.created_at
    $sql = "
        SELECT s.*, sc.school_name, sc.school_code 
        FROM students s
        JOIN schools sc ON s.school_id = sc.id
        WHERE $where_clause
        ORDER BY s.last_sync_at DESC, s.id DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get schools for filter dropdown
    $schools_stmt = $db->query("SELECT id, school_name, school_code FROM schools WHERE status = 'active' ORDER BY school_name ASC");
    $schools = $schools_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique classes from students for filter
    $class_params = [];
    $class_where = "1=1";
    if ($school_id_filter > 0) {
        $class_where = "school_id = ?";
        $class_params[] = $school_id_filter;
    }
    $class_stmt = $db->prepare("SELECT DISTINCT class FROM students WHERE $class_where AND class IS NOT NULL AND class != '' ORDER BY class");
    $class_stmt->execute($class_params);
    $available_classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $active_stmt = $db->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
    $active_result = $active_stmt->fetch(PDO::FETCH_ASSOC);
    $total_active = $active_result ? $active_result['total'] : 0;

    $schools_count_stmt = $db->query("SELECT COUNT(DISTINCT school_id) as total FROM students");
    $schools_count_result = $schools_count_stmt->fetch(PDO::FETCH_ASSOC);
    $schools_with_students = $schools_count_result ? $schools_count_result['total'] : 0;

    $classes_count_stmt = $db->query("SELECT COUNT(DISTINCT class) as total FROM students WHERE class IS NOT NULL AND class != ''");
    $classes_count_result = $classes_count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_classes = $classes_count_result ? $classes_count_result['total'] : 0;
} catch (PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
    error_log("Students page error: " . $e->getMessage());
    $students = [];
    $schools = [];
    $available_classes = [];
    $total_students = 0;
    $total_active = 0;
    $schools_with_students = 0;
    $total_classes = 0;
    $total_pages = 1;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>View Students - MyResultChecker Admin</title>

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
            transition: all 0.3s ease;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: #3498db20;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 24px;
            color: #3498db;
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
            font-size: 0.75rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
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

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
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

        .students-table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .students-table th,
        .students-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 0.85rem;
        }

        .students-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            top: 0;
        }

        .students-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #fef2f2;
            color: #e74c3c;
        }

        .school-badge {
            background: #3498db20;
            color: #3498db;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
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

        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #f39c12;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: #2c3e50;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }

        .modal-body {
            padding: 20px;
        }

        .detail-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ecf0f1;
        }

        .detail-label {
            width: 120px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.85rem;
        }

        .detail-value {
            flex: 1;
            color: #555;
            font-size: 0.85rem;
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

            .filter-actions {
                width: 100%;
            }

            .filter-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .pagination a,
            .pagination span {
                padding: 6px 10px;
                font-size: 0.75rem;
            }
        }

        @media (hover: none) and (pointer: coarse) {

            .btn,
            .nav-links a,
            .students-table tbody tr {
                min-height: 48px;
            }
        }

        @media print {

            .sidebar,
            .mobile-toggle,
            .top-bar,
            .filter-bar,
            .pagination,
            .stats-grid {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .students-table-container {
                box-shadow: none;
            }
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
            <li><a href="students.php" class="active"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-users"></i> Students</h1>
                <p>View all students registered across all schools</p>
            </div>
            <div class="info-text">
                <span class="school-badge"><i class="fas fa-database"></i> Auto-synced from schools</span>
            </div>
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

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo number_format($total_students); ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?php echo number_format($total_active); ?></div>
                <div class="stat-label">Active Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-school"></i></div>
                <div class="stat-number"><?php echo number_format($schools_with_students); ?></div>
                <div class="stat-label">Schools</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
                <div class="stat-number"><?php echo number_format($total_classes); ?></div>
                <div class="stat-label">Unique Classes</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" placeholder="Name or Admission Number..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-school"></i> School</label>
                    <select name="school_id">
                        <option value="0">All Schools</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?php echo $school['id']; ?>"
                                <?php echo $school_id_filter == $school['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-chalkboard"></i> Class</label>
                    <select name="class">
                        <option value="">All Classes</option>
                        <?php foreach ($available_classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                <?php echo $class_filter == $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-flag"></i> Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="students.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Students Table -->
        <div class="students-table-container">
            <div class="table-responsive">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Admission No.</th>
                            <th>Student Name</th>
                            <th>School</th>
                            <th>Class</th>
                            <th>Gender</th>
                            <th>Status</th>
                            <th>Last Sync</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students) && count($students) > 0): ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['admission_number']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td>
                                        <span class="school-badge">
                                            <?php echo htmlspecialchars($student['school_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['class'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $gender_icon = $student['gender'] === 'M' ? 'fa-mars' : ($student['gender'] === 'F' ? 'fa-venus' : 'fa-genderless');
                                        $gender_color = $student['gender'] === 'M' ? '#3498db' : ($student['gender'] === 'F' ? '#e91e63' : '#95a5a6');
                                        ?>
                                        <i class="fas <?php echo $gender_icon; ?>" style="color: <?php echo $gender_color; ?>"></i>
                                        <?php echo $student['gender'] ?? 'N/A'; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['status']; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $student['last_sync_at'] ? date('M d, Y', strtotime($student['last_sync_at'])) : 'Never'; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-outline" onclick="viewStudent(<?php echo $student['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-user-graduate"></i>
                                        <h3>No Students Found</h3>
                                        <p>Students will appear here when schools sync their data to the portal.</p>
                                        <p style="margin-top: 10px; font-size: 0.8rem;">
                                            <i class="fas fa-info-circle"></i>
                                            Students are automatically added when schools push their results.
                                        </p>
                                        <?php if ($error_message): ?>
                                            <p style="margin-top: 15px; font-size: 0.8rem; color: #e74c3c;">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                Database error detected. Please check your database connection.
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <a href="?page=1&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_id_filter, 'class' => $class_filter, 'status' => $status_filter])); ?>"
                        class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_id_filter, 'class' => $class_filter, 'status' => $status_filter])); ?>"
                        class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_id_filter, 'class' => $class_filter, 'status' => $status_filter])); ?>"
                            class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_id_filter, 'class' => $class_filter, 'status' => $status_filter])); ?>"
                        class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_id_filter, 'class' => $class_filter, 'status' => $status_filter])); ?>"
                        class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Student Detail Modal -->
        <div id="studentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-user-graduate"></i> Student Details</h3>
                    <button type="button" class="close-modal" onclick="closeStudentModal()">&times;</button>
                </div>
                <div class="modal-body" id="studentModalBody">
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #3498db;"></i>
                        <p>Loading...</p>
                    </div>
                </div>
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

        // View student details
        async function viewStudent(studentId) {
            const modal = document.getElementById('studentModal');
            const modalBody = document.getElementById('studentModalBody');

            modal.classList.add('active');
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #3498db;"></i><p>Loading...</p></div>';

            try {
                const response = await fetch(`api/get_student.php?id=${studentId}`);
                const data = await response.json();

                if (data.success && data.student) {
                    const student = data.student;
                    modalBody.innerHTML = `
                        <div class="detail-row">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value"><strong>${escapeHtml(student.full_name)}</strong></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Admission Number</div>
                            <div class="detail-value">${escapeHtml(student.admission_number)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">School</div>
                            <div class="detail-value">${escapeHtml(student.school_name)} (${escapeHtml(student.school_code)})</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Class</div>
                            <div class="detail-value">${escapeHtml(student.class || 'N/A')}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Gender</div>
                            <div class="detail-value">${student.gender || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date of Birth</div>
                            <div class="detail-value">${student.dob || student.date_of_birth || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Parent Email</div>
                            <div class="detail-value">${student.parent_email || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Parent Phone</div>
                            <div class="detail-value">${student.parent_phone || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="status-badge status-${student.status}">${student.status}</span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Last Sync</div>
                            <div class="detail-value">${student.last_sync_at ? new Date(student.last_sync_at).toLocaleString() : 'Never'}</div>
                        </div>
                    `;
                } else {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>${data.error || 'Failed to load student details'}</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error fetching student:', error);
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <p>Network error. Please try again.</p>
                        <p style="font-size: 12px; margin-top: 10px;">${error.message}</p>
                    </div>
                `;
            }
        }

        function closeStudentModal() {
            document.getElementById('studentModal').classList.remove('active');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

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