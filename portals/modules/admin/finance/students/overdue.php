<?php
// modules/admin/finance/students/overdue.php

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
$days_overdue = $_GET['days_overdue'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause for overdue students
$where_conditions = ["sfs.next_payment_due < CURDATE()", "sfs.balance > 0", "sfs.is_suspended = 0"];
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

if ($days_overdue) {
    switch ($days_overdue) {
        case '1_7':
            $where_conditions[] = "DATEDIFF(CURDATE(), sfs.next_payment_due) BETWEEN 1 AND 7";
            break;
        case '8_30':
            $where_conditions[] = "DATEDIFF(CURDATE(), sfs.next_payment_due) BETWEEN 8 AND 30";
            break;
        case '30+':
            $where_conditions[] = "DATEDIFF(CURDATE(), sfs.next_payment_due) > 30";
            break;
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

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

// Get overdue students with pagination
$students_sql = "SELECT 
                    sfs.*,
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    cb.id as class_id,
                    cb.batch_code,
                    c.title as course_title,
                    p.name as program_name,
                    p.program_type,
                    p.program_code,
                    DATEDIFF(CURDATE(), sfs.next_payment_due) as days_overdue,
                    TIMESTAMPDIFF(DAY, sfs.last_reminder_sent, CURDATE()) as days_since_reminder,
                    sfs.next_payment_due,
                    sfs.current_block,
                    COUNT(ft.id) as transaction_count,
                    MAX(ft.created_at) as last_payment_date,
                    (SELECT COUNT(*) FROM invoices i 
                     WHERE i.student_id = sfs.student_id 
                     AND i.class_id = sfs.class_id 
                     AND i.status IN ('pending', 'overdue')) as pending_invoices
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
                 ORDER BY sfs.next_payment_due ASC, days_overdue DESC, sfs.balance DESC
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

// Get summary statistics for overdue payments
$summary_sql = "SELECT 
                    COUNT(DISTINCT sfs.student_id) as total_overdue_students,
                    SUM(sfs.balance) as total_overdue_amount,
                    AVG(sfs.balance) as avg_overdue_amount,
                    COUNT(CASE WHEN DATEDIFF(CURDATE(), sfs.next_payment_due) BETWEEN 1 AND 7 THEN 1 END) as overdue_1_7_days,
                    COUNT(CASE WHEN DATEDIFF(CURDATE(), sfs.next_payment_due) BETWEEN 8 AND 30 THEN 1 END) as overdue_8_30_days,
                    COUNT(CASE WHEN DATEDIFF(CURDATE(), sfs.next_payment_due) > 30 THEN 1 END) as overdue_30_plus_days
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

// Get overdue by program type
$program_summary_sql = "SELECT 
                            p.program_type,
                            COUNT(DISTINCT sfs.student_id) as student_count,
                            SUM(sfs.balance) as total_overdue,
                            AVG(DATEDIFF(CURDATE(), sfs.next_payment_due)) as avg_days_overdue
                         FROM student_financial_status sfs
                         JOIN users u ON u.id = sfs.student_id
                         JOIN class_batches cb ON cb.id = sfs.class_id
                         JOIN courses c ON c.id = cb.course_id
                         JOIN programs p ON p.program_code = c.program_id
                         $where_clause
                         GROUP BY p.program_type
                         ORDER BY total_overdue DESC";

$program_summary_stmt = $conn->prepare($program_summary_sql);
if ($params) {
    $program_summary_stmt->bind_param($types, ...$params);
}
$program_summary_stmt->execute();
$program_summary_result = $program_summary_stmt->get_result();
$program_summary = $program_summary_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_overdue_students', "Viewed overdue students with filters: " . json_encode($_GET));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overdue Payments - Admin Portal</title>
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
            --sidebar: #1e293b;
            --revenue: #10b981;
            --pending: #f59e0b;
            --overdue: #ef4444;
            --issues: #8b5cf6;
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
            color: var(--danger);
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

        .summary-card.danger {
            border-top: 4px solid var(--danger);
        }

        .summary-card.warning {
            border-top: 4px solid var(--warning);
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

        /* Program Summary */
        .program-summary {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .program-summary h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .program-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .program-card {
            padding: 1rem;
            border-radius: 8px;
            background: #f8fafc;
            border-left: 4px solid var(--primary);
        }

        .program-card.online {
            border-left-color: #3b82f6;
        }

        .program-card.onsite {
            border-left-color: #10b981;
        }

        .program-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
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
            background: #fef2f2;
            border-bottom: 2px solid #fee2e2;
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
            border-bottom: 1px solid #fee2e2;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #fef2f2;
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

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-suspended {
            background: #f1f5f9;
            color: #64748b;
        }

        .days-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .days-1-7 {
            background: #fef3c7;
            color: #92400e;
        }

        .days-8-30 {
            background: #fde68a;
            color: #92400e;
        }

        .days-30-plus {
            background: #fbbf24;
            color: #92400e;
        }

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.25rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--danger);
            transition: width 0.3s;
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
                    <i class="fas fa-exclamation-triangle"></i>
                    Overdue Payments
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> All Students
                    </a>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-grid">
                <div class="summary-card danger">
                    <div class="summary-value"><?php echo $summary['total_overdue_students'] ?? 0; ?></div>
                    <div class="summary-label">Overdue Students</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_overdue_amount'] ?? 0); ?></div>
                    <div class="summary-label">Total Overdue Amount</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-value"><?php echo $summary['overdue_1_7_days'] ?? 0; ?></div>
                    <div class="summary-label">1-7 Days Overdue</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-value"><?php echo $summary['overdue_8_30_days'] ?? 0; ?></div>
                    <div class="summary-label">8-30 Days Overdue</div>
                </div>
                <div class="summary-card info">
                    <div class="summary-value"><?php echo $summary['overdue_30_plus_days'] ?? 0; ?></div>
                    <div class="summary-label">30+ Days Overdue</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['avg_overdue_amount'] ?? 0); ?></div>
                    <div class="summary-label">Avg. Overdue Amount</div>
                </div>
            </div>

            <!-- Program Summary -->
            <?php if (!empty($program_summary)): ?>
                <div class="program-summary">
                    <h3><i class="fas fa-chart-pie"></i> Overdue by Program Type</h3>
                    <div class="program-grid">
                        <?php foreach ($program_summary as $program): ?>
                            <div class="program-card <?php echo $program['program_type']; ?>">
                                <div class="program-title">
                                    <i class="fas <?php echo $program['program_type'] === 'online' ? 'fa-laptop' : 'fa-building'; ?>"></i>
                                    <?php echo ucfirst($program['program_type']); ?> Programs
                                </div>
                                <div><strong>Students:</strong> <?php echo $program['student_count']; ?></div>
                                <div><strong>Total Overdue:</strong> <?php echo formatCurrency($program['total_overdue']); ?></div>
                                <div><strong>Avg. Days Overdue:</strong> <?php echo round($program['avg_days_overdue'], 1); ?> days</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <div class="select-all">
                    <input type="checkbox" id="selectAll">
                    <label for="selectAll">Select All</label>
                </div>
                <button type="button" class="btn btn-warning" onclick="sendBulkReminders()">
                    <i class="fas fa-bell"></i> Send Reminders
                </button>
                <button type="button" class="btn btn-danger" onclick="suspendBulk()">
                    <i class="fas fa-ban"></i> Suspend Selected
                </button>
                <button type="button" class="btn btn-info" onclick="generateBulkInvoices()">
                    <i class="fas fa-file-invoice"></i> Generate Invoices
                </button>
                <button type="button" class="btn btn-secondary" onclick="exportOverdueReport()">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Overdue Payments</h3>
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
                        <label>Days Overdue</label>
                        <select name="days_overdue" class="form-control">
                            <option value="">All Periods</option>
                            <option value="1_7" <?php echo $days_overdue === '1_7' ? 'selected' : ''; ?>>1-7 Days</option>
                            <option value="8_30" <?php echo $days_overdue === '8_30' ? 'selected' : ''; ?>>8-30 Days</option>
                            <option value="30+" <?php echo $days_overdue === '30+' ? 'selected' : ''; ?>>30+ Days</option>
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

            <!-- Overdue Students Table -->
            <div class="table-container">
                <?php if (!empty($students)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllTable"></th>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Overdue Details</th>
                                <th>Days Overdue</th>
                                <th>Last Reminder</th>
                                <th>Current Block</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $days_overdue = $student['days_overdue'];
                                $overdue_severity = '';
                                if ($days_overdue <= 7) {
                                    $overdue_severity = 'days-1-7';
                                } elseif ($days_overdue <= 30) {
                                    $overdue_severity = 'days-8-30';
                                } else {
                                    $overdue_severity = 'days-30-plus';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="student-checkbox"
                                            data-student-id="<?php echo $student['user_id']; ?>"
                                            data-class-id="<?php echo $student['class_id']; ?>">
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['email']); ?></small><br>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($student['program_name']); ?></div>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['batch_code']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong>Balance:</strong> <?php echo formatCurrency($student['balance']); ?></div>
                                        <div><strong>Due Date:</strong> <?php echo date('M j, Y', strtotime($student['next_payment_due'])); ?></div>
                                        <div><strong>Pending Invoices:</strong> <?php echo $student['pending_invoices']; ?></div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, ($student['paid_amount'] / $student['total_fee']) * 100); ?>%"></div>
                                        </div>
                                        <small style="color: #64748b;">
                                            Paid: <?php echo formatCurrency($student['paid_amount']); ?> / <?php echo formatCurrency($student['total_fee']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="days-badge <?php echo $overdue_severity; ?>">
                                            <?php echo $days_overdue; ?> days
                                        </span>
                                        <div style="margin-top: 0.25rem; font-size: 0.85rem; color: #64748b;">
                                            Since <?php echo date('M j', strtotime($student['next_payment_due'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($student['last_reminder_sent']): ?>
                                            <?php echo date('M j, Y', strtotime($student['last_reminder_sent'])); ?><br>
                                            <small style="color: #64748b;">
                                                <?php echo $student['days_since_reminder']; ?> days ago
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #64748b;">Never sent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; text-align: center;">Block <?php echo $student['current_block']; ?></div>
                                        <?php if ($student['last_payment_date']): ?>
                                            <small style="color: #64748b; display: block; text-align: center;">
                                                Last paid: <?php echo date('M j', strtotime($student['last_payment_date'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/view.php?id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-success" title="Record Payment">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-warning"
                                                onclick="sendReminder(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                title="Send Reminder">
                                                <i class="fas fa-bell"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger"
                                                onclick="suspendStudent(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                title="Suspend Student">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/generate.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-info" title="Generate Invoice">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
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
                        <i class="fas fa-check-circle"></i>
                        <h3>No Overdue Payments</h3>
                        <p>Great! All students are up to date with their payments.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            document.getElementById('selectAllTable').checked = e.target.checked;
        });

        document.getElementById('selectAllTable').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            document.getElementById('selectAll').checked = e.target.checked;
        });

        // Get selected students
        function getSelectedStudents() {
            const selected = [];
            document.querySelectorAll('.student-checkbox:checked').forEach(checkbox => {
                selected.push({
                    studentId: checkbox.dataset.studentId,
                    classId: checkbox.dataset.classId
                });
            });
            return selected;
        }

        // Bulk actions
        function sendBulkReminders() {
            const selected = getSelectedStudents();
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            if (confirm(`Send payment reminders to ${selected.length} selected students?`)) {
                const data = {
                    students: selected,
                    action: 'send_reminders',
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
                            alert(`Reminders sent to ${data.sent_count} students successfully!`);
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to send reminders'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        function suspendBulk() {
            const selected = getSelectedStudents();
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            if (confirm(`Suspend ${selected.length} selected students from class access? This will prevent them from accessing course materials.`)) {
                const data = {
                    students: selected,
                    action: 'suspend',
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
                            alert(`${data.suspended_count} students suspended successfully!`);
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to suspend students'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        function generateBulkInvoices() {
            const selected = getSelectedStudents();
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            if (confirm(`Generate invoices for ${selected.length} selected students?`)) {
                // Open a new window/tab for invoice generation
                const ids = selected.map(s => `${s.studentId}_${s.classId}`).join(',');
                window.open('<?php echo BASE_URL; ?>modules/admin/finance/invoices/bulk_generate.php?ids=' + ids, '_blank');
            }
        }

        function exportOverdueReport() {
            const params = new URLSearchParams(window.location.search);
            window.open('<?php echo BASE_URL; ?>modules/admin/finance/reports/export_overdue.php?' + params.toString(), '_blank');
        }

        // Individual actions
        function sendReminder(studentId, classId) {
            fetch('<?php echo BASE_URL; ?>modules/admin/finance/notifications/send_reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `student_id=${studentId}&class_id=${classId}&csrf_token=<?php echo generateCSRFToken(); ?>`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Payment reminder sent successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed to send reminder'));
                    }
                })
                .catch(error => {
                    alert('Network error: ' + error);
                });
        }

        function suspendStudent(studentId, classId) {
            if (confirm('Are you sure you want to suspend this student from class access? This will prevent them from accessing course materials.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/students/suspend.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `student_id=${studentId}&class_id=${classId}&action=suspend&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Student suspended successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to suspend student'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        // Auto-refresh every 2 minutes for real-time updates
        setInterval(() => {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 120000);
    </script>
</body>

</html>
<?php $conn->close(); ?>