<?php
// modules/admin/finance/students/index.php

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

// Get filter parameters with sanitization
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$program_type = isset($_GET['program_type']) ? trim($_GET['program_type']) : '';
$payment_status = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause with proper table aliases
$where_conditions = [];
$params = [];
$types = '';

if ($search) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR cb.batch_code LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'sssss';
}

if ($status_filter) {
    $where_conditions[] = "sfs.is_cleared = ?";
    $params[] = ($status_filter === 'cleared') ? 1 : 0;
    $types .= 'i';
}

if ($program_type) {
    $where_conditions[] = "p.program_type = ?";
    $params[] = $program_type;
    $types .= 's';
}

if ($payment_status) {
    if ($payment_status === 'suspended') {
        $where_conditions[] = "sfs.is_suspended = 1";
    } elseif ($payment_status === 'overdue') {
        $where_conditions[] = "sfs.next_payment_due < CURDATE() AND sfs.balance > 0";
    } elseif ($payment_status === 'paid') {
        $where_conditions[] = "sfs.balance <= 0";
    } elseif ($payment_status === 'partial') {
        $where_conditions[] = "sfs.balance > 0 AND sfs.paid_amount > 0";
    } elseif ($payment_status === 'unpaid') {
        $where_conditions[] = "sfs.paid_amount = 0 AND sfs.balance > 0";
    }
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

// Get student financial data with pagination
// Fixed SQL query - removed program_id ambiguity and added missing columns
$students_sql = "SELECT 
                    sfs.*,
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.role,
                    u.status as user_status,
                    cb.id as class_id,
                    cb.batch_code,
                    c.title as course_title,
                    p.id as program_id,
                    p.name as program_name,
                    p.program_type,
                    p.program_code,
                    IF(sfs.next_payment_due < CURDATE() AND sfs.balance > 0, 'overdue',
                       IF(sfs.is_suspended = 1, 'suspended',
                          IF(sfs.balance <= 0, 'paid',
                             IF(sfs.paid_amount > 0, 'partial', 'unpaid')))) as payment_status_label,
                    DATEDIFF(sfs.next_payment_due, CURDATE()) as days_until_due,
                    CASE 
                        WHEN sfs.registration_paid = 1 THEN '✓'
                        ELSE '✗'
                    END as registration_status,
                    CASE 
                        WHEN sfs.block1_paid = 1 THEN '✓'
                        ELSE '✗'
                    END as block1_status,
                    CASE 
                        WHEN sfs.block2_paid = 1 THEN '✓'
                        ELSE '✗'
                    END as block2_status,
                    (SELECT COUNT(*) FROM financial_transactions ft 
                     WHERE ft.student_id = sfs.student_id 
                     AND ft.class_id = sfs.class_id 
                     AND ft.status = 'completed') as transaction_count,
                    (SELECT MAX(ft.created_at) FROM financial_transactions ft 
                     WHERE ft.student_id = sfs.student_id 
                     AND ft.class_id = sfs.class_id 
                     AND ft.status = 'completed') as last_payment_date
                 FROM student_financial_status sfs
                 JOIN users u ON u.id = sfs.student_id
                 JOIN class_batches cb ON cb.id = sfs.class_id
                 JOIN courses c ON c.id = cb.course_id
                 JOIN programs p ON p.program_code = c.program_id
                 $where_clause
                 GROUP BY sfs.id, u.id, cb.id, c.id, p.id
                 ORDER BY sfs.is_suspended DESC, 
                          (sfs.next_payment_due < CURDATE() AND sfs.balance > 0) DESC,
                          sfs.next_payment_due ASC
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
                    SUM(sfs.total_fee) as total_fees,
                    SUM(sfs.paid_amount) as total_paid,
                    SUM(sfs.balance) as total_balance,
                    COUNT(CASE WHEN sfs.is_suspended = 1 THEN 1 END) as suspended_count,
                    COUNT(CASE WHEN sfs.is_cleared = 1 THEN 1 END) as cleared_count,
                    COUNT(CASE WHEN sfs.next_payment_due < CURDATE() AND sfs.balance > 0 THEN 1 END) as overdue_count
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

// Log activity
logActivity($_SESSION['user_id'], 'view_students_finance', "Viewed students financial status with filters: " . json_encode($_GET));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Financial Status - Admin Portal</title>
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
            color: var(--primary);
        }

        /* Summary Cards */
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

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-suspended {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-unpaid {
            background: #f3f4f6;
            color: #4b5563;
        }

        .status-cleared {
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

        /* Payment Status Indicators */
        .payment-indicator {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .indicator-icon {
            font-size: 1rem;
        }

        .indicator-paid {
            color: var(--success);
        }

        .indicator-pending {
            color: var(--warning);
        }

        .indicator-overdue {
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
                    <i class="fas fa-users"></i>
                    Student Financial Status
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Finance Dashboard
                    </a>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-value"><?php echo $summary['total_students'] ?? 0; ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_fees'] ?? 0); ?></div>
                    <div class="summary-label">Total Fees</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_paid'] ?? 0); ?></div>
                    <div class="summary-label">Total Paid</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo formatCurrency($summary['total_balance'] ?? 0); ?></div>
                    <div class="summary-label">Total Balance</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo $summary['suspended_count'] ?? 0; ?></div>
                    <div class="summary-label">Suspended</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo $summary['overdue_count'] ?? 0; ?></div>
                    <div class="summary-label">Overdue</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Students</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Name, email, phone, or batch code..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="form-group">
                        <label>Payment Status</label>
                        <select name="payment_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Partial Payment</option>
                            <option value="unpaid" <?php echo $payment_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="overdue" <?php echo $payment_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="suspended" <?php echo $payment_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
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
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            <option value="cleared" <?php echo $status_filter === 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                            <option value="not_cleared" <?php echo $status_filter === 'not_cleared' ? 'selected' : ''; ?>>Not Cleared</option>
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/overdue.php" class="btn btn-danger">
                            <i class="fas fa-exclamation-triangle"></i> View Overdue
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
                                <th>Student</th>
                                <th>Program</th>
                                <th>Batch</th>
                                <th>Fee Details</th>
                                <th>Payment Status</th>
                                <th>Blocks Paid</th>
                                <th>Last Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
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
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($student['course_title']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong>Total:</strong> <?php echo formatCurrency($student['total_fee']); ?></div>
                                        <div><strong>Paid:</strong> <?php echo formatCurrency($student['paid_amount']); ?></div>
                                        <div><strong>Balance:</strong> <?php echo formatCurrency($student['balance']); ?></div>
                                        <?php if ($student['balance'] > 0): ?>
                                            <small style="color: <?php echo ($student['days_until_due'] ?? 0) < 0 ? 'var(--danger)' : '#64748b'; ?>;">
                                                <?php if ($student['next_payment_due']): ?>
                                                    Next due: <?php echo date('M j, Y', strtotime($student['next_payment_due'])); ?>
                                                    <?php if (($student['days_until_due'] ?? 0) < 0): ?>
                                                        (<?php echo abs($student['days_until_due']); ?> days overdue)
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . ($student['payment_status_label'] ?? 'unpaid');
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($student['payment_status_label'] ?? 'unpaid'); ?>
                                        </span>
                                        <?php if ($student['is_suspended']): ?>
                                            <div class="status-badge status-suspended" style="margin-top: 0.25rem;">
                                                <i class="fas fa-ban"></i> Suspended
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($student['is_cleared']): ?>
                                            <div class="status-badge status-cleared" style="margin-top: 0.25rem;">
                                                <i class="fas fa-check-circle"></i> Cleared
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="payment-indicator">
                                            <span class="indicator-icon <?php echo $student['registration_paid'] ? 'indicator-paid' : 'indicator-pending'; ?>">
                                                <?php echo $student['registration_paid'] ? '✓' : '✗'; ?>
                                            </span>
                                            <span>Reg</span>

                                            <span class="indicator-icon <?php echo $student['block1_paid'] ? 'indicator-paid' : 'indicator-pending'; ?>">
                                                <?php echo $student['block1_paid'] ? '✓' : '✗'; ?>
                                            </span>
                                            <span>B1</span>

                                            <?php if ($student['block2_amount'] > 0): ?>
                                                <span class="indicator-icon <?php echo $student['block2_paid'] ? 'indicator-paid' : 'indicator-pending'; ?>">
                                                    <?php echo $student['block2_paid'] ? '✓' : '✗'; ?>
                                                </span>
                                                <span>B2</span>
                                            <?php endif; ?>
                                        </div>
                                        <small style="color: #64748b; margin-top: 0.25rem; display: block;">
                                            Current Block: <?php echo $student['current_block'] ?? 1; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($student['last_payment_date']): ?>
                                            <?php echo date('M j, Y', strtotime($student['last_payment_date'])); ?><br>
                                            <small style="color: #64748b;">
                                                <?php echo $student['transaction_count']; ?> payments
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #64748b;">No payments yet</span>
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
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/generate.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-warning" title="Generate Invoice">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            <?php if ($student['is_suspended']): ?>
                                                <button type="button" class="btn btn-sm btn-info"
                                                    onclick="unsuspendStudent(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                    title="Unsuspend Student">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-danger"
                                                    onclick="suspendStudent(<?php echo $student['user_id']; ?>, <?php echo $student['class_id']; ?>)"
                                                    title="Suspend Student">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/notifications/send_reminder.php?student_id=<?php echo $student['user_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn btn-sm btn-info" title="Send Reminder">
                                                <i class="fas fa-bell"></i>
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
                        <i class="fas fa-users"></i>
                        <h3>No Students Found</h3>
                        <p>No student financial records match your filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Suspend/Unsuspend student functions
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

        function unsuspendStudent(studentId, classId) {
            if (confirm('Are you sure you want to unsuspend this student? This will restore their access to course materials.')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/students/suspend.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `student_id=${studentId}&class_id=${classId}&action=unsuspend&csrf_token=<?php echo generateCSRFToken(); ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Student unsuspended successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to unsuspend student'));
                        }
                    })
                    .catch(error => {
                        alert('Network error: ' + error);
                    });
            }
        }

        // Quick filter by status
        document.querySelectorAll('.status-badge').forEach(badge => {
            badge.addEventListener('click', function(e) {
                const status = this.textContent.toLowerCase().trim();
                window.location.href = `?payment_status=${status}`;
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>