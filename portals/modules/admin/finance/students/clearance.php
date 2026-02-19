<?php
// modules/admin/finance/students/clearance.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$search = $_GET['search'] ?? '';
$program_type = $_GET['program_type'] ?? '';
$clearance_status = $_GET['clearance_status'] ?? 'not_cleared'; // Default to not cleared
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($search) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR cb.batch_code LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'sssss';
}

if ($program_type) {
    $where_conditions[] = "p.program_type = ?";
    $params[] = $program_type;
    $types .= 's';
}

if ($clearance_status === 'cleared') {
    $where_conditions[] = "sfs.is_cleared = 1";
} else {
    $where_conditions[] = "sfs.is_cleared = 0";
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "SELECT COUNT(DISTINCT sfs.id) as total
              FROM student_financial_status sfs
              JOIN users u ON u.id = sfs.student_id
              JOIN class_batches cb ON cb.id = sfs.class_id
              JOIN courses c ON c.id = cb.course_id
              JOIN programs p ON p.program_code = c.program_id
              $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);

// Get students for clearance
$students_sql = "SELECT 
                    sfs.*,
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.status as user_status,
                    cb.id as class_id,
                    cb.batch_code,
                    c.title as course_title,
                    p.name as program_name,
                    p.program_type,
                    p.program_code,
                    cb.end_date as class_end_date,
                    DATEDIFF(CURDATE(), cb.end_date) as days_since_completion,
                    CASE 
                        WHEN sfs.balance <= 0 THEN 'paid'
                        WHEN sfs.balance > 0 AND sfs.next_payment_due < CURDATE() THEN 'overdue'
                        ELSE 'pending'
                    END as payment_status_label,
                    COUNT(ft.id) as transaction_count,
                    GROUP_CONCAT(DISTINCT ft.payment_method) as payment_methods_used,
                    MAX(ft.created_at) as last_payment_date
                 FROM student_financial_status sfs
                 JOIN users u ON u.id = sfs.student_id
                 JOIN class_batches cb ON cb.id = sfs.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 LEFT JOIN financial_transactions ft ON ft.student_id = sfs.student_id 
                    AND ft.class_id = sfs.class_id 
                    AND ft.status = 'completed'
                 $where_clause
                 GROUP BY sfs.id, u.id, cb.id, c.id, p.id
                 ORDER BY 
                    CASE WHEN sfs.is_cleared = 0 AND sfs.balance <= 0 THEN 1
                         WHEN sfs.is_cleared = 0 AND sfs.balance > 0 THEN 2
                         ELSE 3
                    END,
                    cb.end_date DESC,
                    sfs.balance ASC
                 LIMIT ? OFFSET ?";

$limit_param = $limit;
$offset_param = $offset;
$types_with_limit = $types . 'ii';
$params_with_limit = array_merge($params, [$limit_param, $offset_param]);

$students_stmt = $conn->prepare($students_sql);
if ($params_with_limit) {
    $students_stmt->bind_param($types_with_limit, ...$params_with_limit);
}
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);

// Get summary statistics
$summary_sql = "SELECT 
                    COUNT(DISTINCT sfs.student_id) as total_students,
                    SUM(CASE WHEN sfs.is_cleared = 1 THEN 1 ELSE 0 END) as cleared_count,
                    SUM(CASE WHEN sfs.is_cleared = 0 AND sfs.balance <= 0 THEN 1 ELSE 0 END) as pending_clearance,
                    SUM(CASE WHEN sfs.is_cleared = 0 AND sfs.balance > 0 THEN 1 ELSE 0 END) as not_eligible,
                    SUM(sfs.balance) as total_outstanding,
                    AVG(CASE WHEN sfs.balance > 0 THEN sfs.balance ELSE NULL END) as avg_outstanding_amount
                 FROM student_financial_status sfs
                 JOIN users u ON u.id = sfs.student_id
                 JOIN class_batches cb ON cb.id = sfs.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 $where_clause";

$summary_stmt = $conn->prepare($summary_sql);
if ($params) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();

// Get program-specific clearance data
$program_clearance_sql = "SELECT 
                            p.name as program_name,
                            p.program_type,
                            COUNT(DISTINCT sfs.student_id) as total_students,
                            SUM(CASE WHEN sfs.is_cleared = 1 THEN 1 ELSE 0 END) as cleared_count,
                            SUM(CASE WHEN sfs.is_cleared = 0 AND sfs.balance <= 0 THEN 1 ELSE 0 END) as pending_clearance,
                            ROUND(SUM(CASE WHEN sfs.is_cleared = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT sfs.student_id), 1) as clearance_rate
                         FROM student_financial_status sfs
                         JOIN users u ON u.id = sfs.student_id
                         JOIN class_batches cb ON cb.id = sfs.class_id
                         JOIN courses c ON c.id = cb.course_id
                         JOIN programs p ON p.program_code = c.program_id
                         $where_clause
                         GROUP BY p.id, p.name, p.program_type
                         ORDER BY clearance_rate DESC, total_students DESC";

$program_clearance_stmt = $conn->prepare($program_clearance_sql);
if ($params) {
    $program_clearance_stmt->bind_param($types, ...$params);
}
$program_clearance_stmt->execute();
$program_clearance_result = $program_clearance_stmt->get_result();
$program_clearance = $program_clearance_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_clearance_status', "Viewed clearance status with filter: $clearance_status");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Clearance Status - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
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

        .main-content {
            flex: 1;
            padding: 2rem;
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--success);
        }

        /* Summary Statistics */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .summary-card.success {
            border-top: 4px solid var(--success);
        }

        .summary-card.warning {
            border-top: 4px solid var(--warning);
        }

        .summary-card.danger {
            border-top: 4px solid var(--danger);
        }

        .summary-card.info {
            border-top: 4px solid var(--info);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .summary-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Program Clearance Stats */
        .program-clearance {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .program-clearance h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .program-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .program-card {
            padding: 1rem;
            border-radius: 8px;
            background: #f8fafc;
            border-left: 4px solid var(--primary);
        }

        .program-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .clearance-rate {
            font-size: 0.9rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background: #d1fae5;
            color: #065f46;
        }

        .clearance-progress {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .clearance-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s;
        }

        /* Filters */
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
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Buttons */
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
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
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
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-cleared {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-not-eligible {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .program-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-online {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-onsite {
            background: #dcfce7;
            color: #166534;
        }

        /* Eligibility Indicator */
        .eligibility-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .indicator-icon {
            font-size: 1.2rem;
        }

        .eligible {
            color: var(--success);
        }

        .not-eligible {
            color: var(--danger);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-container {
                overflow-x: auto;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .program-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-check-circle"></i>
                    Student Clearance Status
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> All Students
                    </a>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-grid">
                <div class="summary-card success">
                    <div class="summary-value"><?php echo $summary['cleared_count'] ?? 0; ?></div>
                    <div class="summary-label">Cleared Students</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-value"><?php echo $summary['pending_clearance'] ?? 0; ?></div>
                    <div class="summary-label">Pending Clearance</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-value"><?php echo $summary['not_eligible'] ?? 0; ?></div>
                    <div class="summary-label">Not Eligible</div>
                </div>
                <div class="summary-card info">
                    <div class="summary-value"><?php echo $summary['total_students'] ?? 0; ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_outstanding'] ?? 0); ?></div>
                    <div class="summary-label">Total Outstanding</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value">
                        <?php
                        $clearance_rate = $summary['total_students'] > 0 ?
                            round(($summary['cleared_count'] / $summary['total_students']) * 100, 1) : 0;
                        echo $clearance_rate; ?>%
                    </div>
                    <div class="summary-label">Clearance Rate</div>
                </div>
            </div>

            <!-- Program Clearance Stats -->
            <?php if (!empty($program_clearance)): ?>
                <div class="program-clearance">
                    <h3><i class="fas fa-chart-bar"></i> Clearance by Program</h3>
                    <div class="program-grid">
                        <?php foreach ($program_clearance as $program): ?>
                            <div class="program-card">
                                <div class="program-title">
                                    <span><?php echo htmlspecialchars($program['program_name']); ?></span>
                                    <span class="clearance-rate"><?php echo $program['clearance_rate']; ?>%</span>
                                </div>
                                <div><small style="color: #64748b;">
                                        <span class="program-badge badge-<?php echo $program['program_type']; ?>">
                                            <?php echo $program['program_type']; ?>
                                        </span>
                                    </small></div>
                                <div class="clearance-progress">
                                    <div class="clearance-fill" style="width: <?php echo $program['clearance_rate']; ?>%"></div>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                                    <span>Cleared: <?php echo $program['cleared_count']; ?></span>
                                    <span>Pending: <?php echo $program['pending_clearance']; ?></span>
                                    <span>Total: <?php echo $program['total_students']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Bulk Actions -->
            <?php if ($clearance_status === 'not_cleared'): ?>
                <div class="bulk-actions">
                    <div class="select-all">
                        <input type="checkbox" id="selectAll">
                        <label for="selectAll">Select All Eligible</label>
                    </div>
                    <button type="button" class="btn btn-success" onclick="clearBulkStudents()">
                        <i class="fas fa-check-circle"></i> Clear Selected
                    </button>
                    <button type="button" class="btn btn-info" onclick="generateClearanceCertificates()">
                        <i class="fas fa-file-certificate"></i> Generate Certificates
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="exportClearanceReport()">
                        <i class="fas fa-file-export"></i> Export Report
                    </button>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Clearance Status</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Name, email, phone, or batch code..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="form-group">
                        <label>Program Type</label>
                        <select name="program_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Clearance Status</label>
                        <select name="clearance_status" class="form-control">
                            <option value="not_cleared" <?php echo $clearance_status === 'not_cleared' ? 'selected' : ''; ?>>Not Cleared</option>
                            <option value="cleared" <?php echo $clearance_status === 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Students Table -->
            <div class="table-container">
                <?php if (!empty($students)): ?>
                    <table>
                        <thead>
                            <tr>
                                <?php if ($clearance_status === 'not_cleared'): ?>
                                    <th><input type="checkbox" id="selectAllTable"></th>
                                <?php endif; ?>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Class Details</th>
                                <th>Financial Status</th>
                                <th>Clearance Eligibility</th>
                                <th>Completion Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $is_eligible = $student['balance'] <= 0;
                                $class_completed = strtotime($student['class_end_date']) < time();
                            ?>
                                <tr>
                                    <?php if ($clearance_status === 'not_cleared'): ?>
                                        <td>
                                            <?php if ($is_eligible && $class_completed): ?>
                                                <input type="checkbox" class="student-checkbox"
                                                    data-student-id="<?php echo $student['user_id']; ?>"
                                                    data-class-id="<?php echo $student['class_id']; ?>">
                                            <?php else: ?>
                                                <span style="color: #cbd5e1;">–</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['email']); ?></small><br>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($student['program_name']); ?></div>
                                        <span class="program-badge badge-<?php echo $student['program_type']; ?>">
                                            <?php echo $student['program_type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($student['batch_code']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['course_title']); ?></small><br>
                                        <small style="color: #64748b;">
                                            <?php if ($student['class_end_date']): ?>
                                                End: <?php echo date('M j, Y', strtotime($student['class_end_date'])); ?>
                                                <?php if ($student['days_since_completion'] > 0): ?>
                                                    (<?php echo $student['days_since_completion']; ?> days ago)
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div><strong>Balance:</strong> <?php echo formatCurrency($student['balance']); ?></div>
                                        <div><strong>Status:</strong>
                                            <span class="status-badge status-<?php echo $student['payment_status_label']; ?>">
                                                <?php echo ucfirst($student['payment_status_label']); ?>
                                            </span>
                                        </div>
                                        <?php if ($student['transaction_count'] > 0): ?>
                                            <small style="color: #64748b; display: block;">
                                                <?php echo $student['transaction_count']; ?> payments
                                                <?php if ($student['payment_methods_used']): ?>
                                                    via <?php echo $student['payment_methods_used']; ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="eligibility-indicator">
                                            <?php if ($student['is_cleared']): ?>
                                                <span class="indicator-icon eligible">✓</span>
                                                <span class="status-badge status-cleared">Cleared</span>
                                            <?php elseif ($is_eligible && $class_completed): ?>
                                                <span class="indicator-icon eligible">✓</span>
                                                <span class="status-badge status-pending">Eligible</span>
                                            <?php elseif (!$is_eligible): ?>
                                                <span class="indicator-icon not-eligible">✗</span>
                                                <span class="status-badge status-not-eligible">Not Eligible</span>
                                                <small style="color: var(--danger); display: block; margin-top: 0.25rem;">
                                                    Balance: <?php echo formatCurrency($student['balance']); ?>
                                                </small>
                                            <?php elseif (!$class_completed): ?>
                                                <span class="indicator-icon not-eligible">✗</span>
                                                <span class="status-badge status-not-eligible">Class Ongoing</span>
                                                <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                                                    Ends in <?php echo abs($student['days_since_completion']); ?> days
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($student['class_end_date']): ?>
                                            <?php echo date('M j, Y', strtotime($student['class_end_date'])); ?>
                                            <?php if ($class_completed): ?>
                                                <div class="status-badge status-completed" style="margin-top: 0.25rem;">
                                                    Completed
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #64748b;">Ongoing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/view.php?id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>

                                            <?php if (!$student['is_cleared'] && $is_eligible && $class_completed): ?>
                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="clearStudent(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                    title="Clear Student">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php elseif ($student['is_cleared']): ?>
                                                <button type="button" class="btn btn-sm btn-warning"
                                                    onclick="unclearStudent(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                    title="Mark as Not Cleared">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($student['balance'] > 0): ?>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                    class="btn btn-sm btn-info" title="Record Payment">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($student['is_cleared']): ?>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/certificate.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                    class="btn btn-sm btn-secondary" title="View Certificate" target="_blank">
                                                    <i class="fas fa-file-certificate"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                    class="page-link">
                                    <i class="fas fa-chevron-left"></i> Prev
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                                    class="page-link">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Students Found</h3>
                        <p>No student clearance records match your filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Select All functionality (only for not cleared page)
        <?php if ($clearance_status === 'not_cleared'): ?>
            document.getElementById('selectAll')?.addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('.student-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                });
                document.getElementById('selectAllTable').checked = e.target.checked;
            });

            document.getElementById('selectAllTable')?.addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('.student-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                });
                document.getElementById('selectAll').checked = e.target.checked;
            });

            // Get selected eligible students
            function getSelectedEligibleStudents() {
                const selected = [];
                document.querySelectorAll('.student-checkbox:checked').forEach(checkbox => {
                    selected.push({
                        studentId: checkbox.dataset.studentId,
                        classId: checkbox.dataset.classId
                    });
                });
                return selected;
            }
        <?php endif; ?>

        // Individual student clearance
        function clearStudent(studentId, classId) {
            if (confirm('Mark this student as cleared? This will allow them to receive certificates and complete the program.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/students/clear.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `student_id=${studentId}&class_id=${classId}&action=clear&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Student marked as cleared successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to clear student'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        function unclearStudent(studentId, classId) {
            if (confirm('Mark this student as not cleared? This will revoke their clearance status.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/students/clear.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `student_id=${studentId}&class_id=${classId}&action=unclear&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Student marked as not cleared successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to unclear student'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        // Bulk clearance
        function clearBulkStudents() {
            <?php if ($clearance_status === 'not_cleared'): ?>
                const selected = getSelectedEligibleStudents();
                if (selected.length === 0) {
                    alert('Please select at least one eligible student.');
                    return;
                }

                if (confirm(`Mark ${selected.length} selected students as cleared?`)) {
                    const data = {
                        students: selected,
                        action: 'bulk_clear',
                        csrf_token: '<?php echo generateCSRFToken(); ?>'
                    };

                    fetch('<?php echo BASE_URL; ?>modules/admin/finance/students/bulk_actions.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(`${data.cleared_count} students cleared successfully!`);
                                window.location.reload();
                            } else {
                                alert('Error: ' + (data.error || 'Failed to clear students'));
                            }
                        })
                        .catch(error => {
                            alert('Network error: ' + error);
                        });
                }
            <?php endif; ?>
        }

        function generateClearanceCertificates() {
            <?php if ($clearance_status === 'not_cleared'): ?>
                const selected = getSelectedEligibleStudents();
                if (selected.length === 0) {
                    alert('Please select at least one eligible student.');
                    return;
                }

                if (confirm(`Generate clearance certificates for ${selected.length} selected students?`)) {
                    const ids = selected.map(s => `${s.studentId}_${s.classId}`).join(',');
                    window.open('<?php echo BASE_URL; ?>modules/admin/finance/students/certificates_bulk.php?ids=' + ids, '_blank');
                }
            <?php endif; ?>
        }

        function exportClearanceReport() {
            const params = new URLSearchParams(window.location.search);
            window.open('<?php echo BASE_URL; ?>modules/admin/finance/reports/export_clearance.php?' + params.toString(), '_blank');
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>