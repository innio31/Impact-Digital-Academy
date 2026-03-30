<?php
// admin/results.php - View all results uploaded by schools
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

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$school_filter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$session_filter = isset($_GET['session']) ? trim($_GET['session']) : '';
$term_filter = isset($_GET['term']) ? trim($_GET['term']) : '';
$class_filter = isset($_GET['class']) ? trim($_GET['class']) : '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR sc.school_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($school_filter > 0) {
    $where_conditions[] = "r.school_id = ?";
    $params[] = $school_filter;
}

if (!empty($session_filter)) {
    $where_conditions[] = "r.session_year = ?";
    $params[] = $session_filter;
}

if (!empty($term_filter)) {
    $where_conditions[] = "r.term = ?";
    $params[] = $term_filter;
}

if (!empty($class_filter)) {
    $where_conditions[] = "s.class = ?";
    $params[] = $class_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN schools sc ON r.school_id = sc.id
    WHERE $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_results = $stmt->fetch()['total'];
$total_pages = ceil($total_results / $limit);

// Get results
$sql = "
    SELECT r.*, s.full_name as student_name, s.admission_number, s.class, s.gender,
           sc.school_name, sc.school_code,
           (SELECT COUNT(*) FROM results WHERE school_id = r.school_id AND student_id = r.student_id) as total_results_for_student
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN schools sc ON r.school_id = sc.id
    WHERE $where_clause
    ORDER BY r.synced_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Get schools for filter
$stmt = $db->query("SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name");
$schools = $stmt->fetchAll();

// Get sessions for filter
$stmt = $db->query("SELECT DISTINCT session_year FROM results ORDER BY session_year DESC");
$sessions = $stmt->fetchAll();

// Get classes for filter
$stmt = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != '' ORDER BY class");
$classes = $stmt->fetchAll();

// Get statistics
$stmt = $db->query("SELECT COUNT(*) as total FROM results");
$total_all_results = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(DISTINCT school_id) as total FROM results");
$schools_with_results = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(DISTINCT student_id) as total FROM results");
$students_with_results = $stmt->fetch()['total'];

$stmt = $db->query("
    SELECT session_year, term, COUNT(*) as count 
    FROM results 
    GROUP BY session_year, term 
    ORDER BY session_year DESC, term DESC
");
$result_summary = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>View Results - MyResultChecker Admin</title>

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
            padding: 5px 12px;
            font-size: 0.75rem;
        }

        /* Stats Cards */
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
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-left-color: #3498db;
        }

        .stat-card.schools {
            border-left-color: #27ae60;
        }

        .stat-card.students {
            border-left-color: #f39c12;
        }

        .stat-card.terms {
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

        /* Results Table */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .results-table th,
        .results-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            font-size: 0.85rem;
        }

        .results-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .results-table tr:hover {
            background: #f8f9fa;
        }

        .school-badge {
            background: #3498db20;
            color: #3498db;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .grade-A {
            background: #d5f4e6;
            color: #27ae60;
        }

        .grade-B {
            background: #d5f4e6;
            color: #27ae60;
        }

        .grade-C {
            background: #fff8e1;
            color: #f39c12;
        }

        .grade-D {
            background: #fff8e1;
            color: #f39c12;
        }

        .grade-E {
            background: #fef2f2;
            color: #e74c3c;
        }

        .grade-F {
            background: #fef2f2;
            color: #e74c3c;
        }

        .status-published {
            background: #d5f4e6;
            color: #27ae60;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }

        .status-draft {
            background: #fff8e1;
            color: #f39c12;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-block;
        }

        /* Result Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .summary-session {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .summary-term {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .summary-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: #3498db;
            margin-top: 10px;
        }

        /* Pagination */
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

        /* Modal */
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
            max-width: 900px;
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
            position: sticky;
            top: 0;
            background: white;
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

        .result-detail {
            font-size: 0.9rem;
        }

        .detail-section {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        .detail-section h4 {
            color: #2c3e50;
            margin-bottom: 12px;
            font-size: 1rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
        }

        .detail-label {
            font-weight: 500;
            color: #7f8c8d;
        }

        .detail-value {
            font-weight: 500;
            color: #2c3e50;
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .scores-table th,
        .scores-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        .scores-table th {
            background: #f8f9fa;
            font-weight: 600;
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

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 1.5rem;
            }
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

        .view-link {
            color: #3498db;
            text-decoration: none;
            cursor: pointer;
        }

        .view-link:hover {
            text-decoration: underline;
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
            <li><a href="results.php" class="active"><i class="fas fa-file-alt"></i> Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-file-alt"></i> Student Results</h1>
                <p>View all results uploaded by schools</p>
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
            <div class="stat-card total">
                <div class="stat-number"><?php echo number_format($total_all_results); ?></div>
                <div class="stat-label">Total Results</div>
            </div>
            <div class="stat-card schools">
                <div class="stat-number"><?php echo number_format($schools_with_results); ?></div>
                <div class="stat-label">Schools with Results</div>
            </div>
            <div class="stat-card students">
                <div class="stat-number"><?php echo number_format($students_with_results); ?></div>
                <div class="stat-label">Students with Results</div>
            </div>
            <div class="stat-card terms">
                <div class="stat-number"><?php echo count($result_summary); ?></div>
                <div class="stat-label">Session/Term Combinations</div>
            </div>
        </div>

        <!-- Result Summary by Session/Term -->
        <?php if (!empty($result_summary)): ?>
            <div class="summary-cards">
                <?php foreach (array_slice($result_summary, 0, 6) as $summary): ?>
                    <div class="summary-card">
                        <div class="summary-session"><?php echo htmlspecialchars($summary['session_year']); ?></div>
                        <div class="summary-term"><?php echo htmlspecialchars($summary['term']); ?> Term</div>
                        <div class="summary-count"><?php echo number_format($summary['count']); ?></div>
                        <div class="summary-label">Results</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" placeholder="Student name, admission, school..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-school"></i> School</label>
                    <select name="school_id">
                        <option value="0">All Schools</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?php echo $school['id']; ?>"
                                <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Session</label>
                    <select name="session">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['session_year']); ?>"
                                <?php echo $session_filter === $s['session_year'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['session_year']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-chalkboard"></i> Term</label>
                    <select name="term">
                        <option value="">All Terms</option>
                        <option value="First" <?php echo $term_filter === 'First' ? 'selected' : ''; ?>>First Term</option>
                        <option value="Second" <?php echo $term_filter === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                        <option value="Third" <?php echo $term_filter === 'Third' ? 'selected' : ''; ?>>Third Term</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-users"></i> Class</label>
                    <select name="class">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['class']); ?>"
                                <?php echo $class_filter === $c['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="results.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>School</th>
                            <th>Session</th>
                            <th>Term</th>
                            <th>Class</th>
                            <th>Average</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Synced</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results)): ?>
                            <?php foreach ($results as $result):
                                $grade_class = 'grade-' . ($result['grade'] ?? 'F');
                                $avg_value = is_numeric($result['average']) ? number_format($result['average'], 2) . '%' : $result['average'];
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['student_name']); ?></strong><br>
                                        <small style="color: #7f8c8d;"><?php echo htmlspecialchars($result['admission_number']); ?></small>
                                    </td>
                                    <td>
                                        <span class="school-badge"><?php echo htmlspecialchars($result['school_name']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['session_year']); ?></td>
                                    <td><?php echo htmlspecialchars($result['term']); ?> Term</td>
                                    <td><?php echo htmlspecialchars($result['class'] ?? 'N/A'); ?></td>
                                    <td><?php echo $avg_value; ?></td>
                                    <td>
                                        <span class="grade-badge <?php echo $grade_class; ?>">
                                            <?php echo htmlspecialchars($result['grade'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($result['is_published']): ?>
                                            <span class="status-published"><i class="fas fa-check-circle"></i> Published</span>
                                        <?php else: ?>
                                            <span class="status-draft"><i class="fas fa-edit"></i> Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo date('M d, Y', strtotime($result['synced_at'])); ?></small>
                                    </td>
                                    <td>
                                        <a href="#" class="view-link" onclick="viewResult(<?php echo $result['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="fas fa-file-alt"></i>
                                        <h3>No Results Found</h3>
                                        <p>Results will appear here when schools sync their data to the portal.</p>
                                        <p style="margin-top: 10px; font-size: 0.8rem;">
                                            <i class="fas fa-info-circle"></i>
                                            Results are automatically synced when schools push their data.
                                        </p>
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
                    <a href="?page=1&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'session' => $session_filter, 'term' => $term_filter, 'class' => $class_filter])); ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'session' => $session_filter, 'term' => $term_filter, 'class' => $class_filter])); ?>">
                        <i class="fas fa-angle-left"></i>
                    </a>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'session' => $session_filter, 'term' => $term_filter, 'class' => $class_filter])); ?>"
                            class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'session' => $session_filter, 'term' => $term_filter, 'class' => $class_filter])); ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_filter(['search' => $search, 'school_id' => $school_filter, 'session' => $session_filter, 'term' => $term_filter, 'class' => $class_filter])); ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Result Detail Modal -->
    <div id="resultModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-alt"></i> Result Details</h3>
                <button type="button" class="close-modal" onclick="closeResultModal()">&times;</button>
            </div>
            <div class="modal-body" id="resultModalBody">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #3498db;"></i>
                    <p>Loading...</p>
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

        // View result details
        async function viewResult(resultId) {
            const modal = document.getElementById('resultModal');
            const modalBody = document.getElementById('resultModalBody');

            modal.classList.add('active');
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #3498db;"></i><p>Loading...</p></div>';

            try {
                const response = await fetch(`api/get_result.php?id=${resultId}`);
                const data = await response.json();

                if (data.success) {
                    const result = data.result;
                    const scores = result.scores || [];

                    // Build scores table
                    let scoresHtml = '';
                    if (scores.length > 0) {
                        scoresHtml = `
                            <div class="detail-section">
                                <h4><i class="fas fa-chart-line"></i> Subject Scores</h4>
                                <div style="overflow-x: auto;">
                                    <table class="scores-table">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th>CA 1</th>
                                                <th>CA 2</th>
                                                <th>Exam</th>
                                                <th>Total</th>
                                                <th>Max</th>
                                                <th>Grade</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;

                        scores.forEach(score => {
                            const ca1 = score.ca1 || 0;
                            const ca2 = score.ca2 || 0;
                            const exam = score.exam || 0;
                            const total = ca1 + ca2 + exam;
                            const max = (score.max_ca1 || 20) + (score.max_ca2 || 20) + (score.max_exam || 60);
                            const gradeClass = `grade-${score.grade || 'F'}`;

                            scoresHtml += `
                                <tr>
                                    <td><strong>${escapeHtml(score.subject_name)}</strong></td>
                                    <td>${ca1}</td>
                                    <td>${ca2}</td>
                                    <td>${exam}</td>
                                    <td><strong>${total}</strong></td>
                                    <td>${max}</td>
                                    <td class="${gradeClass}">${score.grade || '-'}</td>
                                </tr>
                            `;
                        });

                        scoresHtml += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    }

                    // Build affective traits
                    let affectiveHtml = '';
                    if (result.affective_traits && Object.keys(result.affective_traits).length > 0) {
                        const traits = result.affective_traits;
                        const traitLabels = {
                            punctuality: 'Punctuality',
                            attendance: 'Attendance',
                            politeness: 'Politeness',
                            honesty: 'Honesty',
                            neatness: 'Neatness',
                            reliability: 'Reliability',
                            relationship: 'Relationship',
                            self_control: 'Self Control'
                        };

                        let traitsHtml = '<div class="detail-grid">';
                        for (const [key, value] of Object.entries(traits)) {
                            if (value && traitLabels[key]) {
                                traitsHtml += `
                                    <div class="detail-item">
                                        <span class="detail-label">${traitLabels[key]}:</span>
                                        <span class="detail-value">${escapeHtml(value)}</span>
                                    </div>
                                `;
                            }
                        }
                        traitsHtml += '</div>';

                        affectiveHtml = `
                            <div class="detail-section">
                                <h4><i class="fas fa-heart"></i> Affective Traits</h4>
                                ${traitsHtml}
                            </div>
                        `;
                    }

                    // Build psychomotor skills
                    let psychomotorHtml = '';
                    if (result.psychomotor_skills && Object.keys(result.psychomotor_skills).length > 0) {
                        const skills = result.psychomotor_skills;
                        const skillLabels = {
                            handwriting: 'Handwriting',
                            verbal_fluency: 'Verbal Fluency',
                            sports: 'Sports',
                            handling_tools: 'Handling Tools',
                            drawing_painting: 'Drawing/Painting',
                            musical_skills: 'Musical Skills'
                        };

                        let skillsHtml = '<div class="detail-grid">';
                        for (const [key, value] of Object.entries(skills)) {
                            if (value && skillLabels[key]) {
                                skillsHtml += `
                                    <div class="detail-item">
                                        <span class="detail-label">${skillLabels[key]}:</span>
                                        <span class="detail-value">${escapeHtml(value)}</span>
                                    </div>
                                `;
                            }
                        }
                        skillsHtml += '</div>';

                        psychomotorHtml = `
                            <div class="detail-section">
                                <h4><i class="fas fa-running"></i> Psychomotor Skills</h4>
                                ${skillsHtml}
                            </div>
                        `;
                    }

                    modalBody.innerHTML = `
                        <div class="result-detail">
                            <div class="detail-section">
                                <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Name:</span>
                                        <span class="detail-value">${escapeHtml(result.student_name)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Admission No:</span>
                                        <span class="detail-value">${escapeHtml(result.admission_number)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">School:</span>
                                        <span class="detail-value">${escapeHtml(result.school_name)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Class:</span>
                                        <span class="detail-value">${escapeHtml(result.class)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Session:</span>
                                        <span class="detail-value">${escapeHtml(result.session_year)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Term:</span>
                                        <span class="detail-value">${escapeHtml(result.term)} Term</span>
                                    </div>
                                </div>
                            </div>
                            
                            ${scoresHtml}
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-chart-simple"></i> Summary</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Total Marks:</span>
                                        <span class="detail-value">${escapeHtml(result.total_marks)}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Average:</span>
                                        <span class="detail-value">${result.average}%</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Grade:</span>
                                        <span class="detail-value grade-${result.grade}">${result.grade}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Class Position:</span>
                                        <span class="detail-value">${result.class_position || 'N/A'}${result.class_total_students ? '/' + result.class_total_students : ''}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Days Present:</span>
                                        <span class="detail-value">${result.days_present || 0}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Days Absent:</span>
                                        <span class="detail-value">${result.days_absent || 0}</span>
                                    </div>
                                </div>
                            </div>
                            
                            ${affectiveHtml}
                            ${psychomotorHtml}
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-comment"></i> Comments</h4>
                                <div class="detail-item" style="margin-bottom: 10px;">
                                    <span class="detail-label">Teacher's Comment:</span>
                                    <span class="detail-value">${escapeHtml(result.teachers_comment) || 'No comment'}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Principal's Comment:</span>
                                    <span class="detail-value">${escapeHtml(result.principals_comment) || 'No comment'}</span>
                                </div>
                            </div>
                            
                            ${result.promoted_to ? `
                            <div class="detail-section">
                                <h4><i class="fas fa-arrow-up"></i> Promotion</h4>
                                <div class="detail-item">
                                    <span class="detail-label">Promoted to:</span>
                                    <span class="detail-value">${escapeHtml(result.promoted_to)}</span>
                                </div>
                            </div>
                            ` : ''}
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-clock"></i> Sync Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Synced at:</span>
                                        <span class="detail-value">${new Date(result.synced_at).toLocaleString()}</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Status:</span>
                                        <span class="detail-value">${result.is_published ? 'Published' : 'Draft'}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>${data.error || 'Failed to load result details'}</p>
                        </div>
                    `;
                }
            } catch (error) {
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <p>Network error. Please try again.</p>
                    </div>
                `;
            }
        }

        function closeResultModal() {
            document.getElementById('resultModal').classList.remove('active');
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