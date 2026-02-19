<?php
// modules/instructor/students/list.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php'; // Include finance functions

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

$instructor_id = $_SESSION['user_id'];

// Get class_id from URL if specified
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_filter = isset($_GET['payment']) ? $_GET['payment'] : '';

// Get instructor's classes for dropdown
$classes = [];
$sql = "SELECT cb.id, cb.batch_code, cb.name, c.title as course_title 
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        WHERE cb.instructor_id = ? 
        AND cb.status IN ('scheduled', 'ongoing')
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build query for students with payment status
$where_conditions = ["cb.instructor_id = ?"];
$params = [$instructor_id];
$param_types = "i";

if ($class_id > 0) {
    $where_conditions[] = "cb.id = ?";
    $params[] = $class_id;
    $param_types .= "i";
}

if ($search) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

if ($status_filter && in_array($status_filter, ['active', 'completed', 'dropped', 'suspended'])) {
    $where_conditions[] = "e.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if ($payment_filter) {
    switch ($payment_filter) {
        case 'cleared':
            $where_conditions[] = "sfs.is_cleared = 1";
            break;
        case 'suspended':
            $where_conditions[] = "sfs.is_suspended = 1";
            break;
        case 'overdue':
            $where_conditions[] = "sfs.balance > 0 AND sfs.next_payment_due < CURDATE()";
            break;
        case 'partially_paid':
            $where_conditions[] = "sfs.paid_amount > 0 AND sfs.balance > 0";
            break;
        case 'not_paid':
            $where_conditions[] = "sfs.paid_amount = 0";
            break;
    }
}

// Count total students
$count_sql = "SELECT COUNT(DISTINCT e.student_id) as total 
              FROM enrollments e
              JOIN class_batches cb ON e.class_id = cb.id
              JOIN users u ON e.student_id = u.id
              LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
              WHERE " . implode(" AND ", $where_conditions);

$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_students = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_students / $limit);
$count_stmt->close();

// Get students with details and payment status
$students_sql = "SELECT 
                    e.id as enrollment_id,
                    e.student_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    e.status as enrollment_status,
                    e.enrollment_date,
                    e.final_grade,
                    cb.id as class_id,
                    cb.batch_code,
                    cb.name as class_name,
                    c.title as course_title,
                    c.course_code,
                    sfs.total_fee,
                    sfs.paid_amount,
                    sfs.balance,
                    sfs.is_cleared,
                    sfs.is_suspended,
                    sfs.registration_paid,
                    sfs.block1_paid,
                    sfs.block2_paid,
                    sfs.current_block,
                    sfs.next_payment_due,
                    sfs.suspended_at,
                    COUNT(DISTINCT s.id) as total_submissions,
                    COUNT(DISTINCT g.id) as graded_assignments,
                    COALESCE(AVG(g.percentage), 0) as average_score
                FROM enrollments e
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN users u ON e.student_id = u.id
                LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
                LEFT JOIN assignment_submissions s ON e.student_id = s.student_id AND s.assignment_id IN (
                    SELECT id FROM assignments WHERE class_id = cb.id
                )
                LEFT JOIN gradebook g ON e.student_id = g.student_id AND g.assignment_id IN (
                    SELECT id FROM assignments WHERE class_id = cb.id
                )
                WHERE " . implode(" AND ", $where_conditions) . "
                GROUP BY e.id, e.student_id, cb.id, sfs.id
                ORDER BY u.last_name, u.first_name
                LIMIT ? OFFSET ?";

// Add pagination parameters
$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($students_sql);
if ($params) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Log activity
logActivity('view_students_list', 'Instructor viewed students list with payment status');

// Close database connection
$conn->close();

// Get instructor name
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            padding: 2rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: var(--gray);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
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
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Filters Section */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filters-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
        }

        .filter-reset {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .filter-reset:hover {
            text-decoration: underline;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-control {
            padding: 0.75rem;
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light-gray);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-icon.total {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .stat-icon.cleared {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.suspended {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-icon.overdue {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        /* Students Table */
        .students-table-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
        }

        .results-info {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 1rem;
            background: var(--light);
            color: var(--gray);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: var(--light);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .student-details h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .student-details p {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            white-space: nowrap;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-dropped {
            background: #f3f4f6;
            color: #374151;
        }

        .payment-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .payment-cleared {
            background: #d1fae5;
            color: #065f46;
        }

        .payment-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .payment-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .payment-suspended {
            background: #fca5a5;
            color: #7f1d1d;
        }

        .payment-not-paid {
            background: #f3f4f6;
            color: #374151;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .amount.paid {
            color: var(--success);
        }

        .amount.due {
            color: var(--danger);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
        }

        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
        }

        .btn-progress {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-finance {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-message {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--light-gray);
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
        }

        .pagination .active {
            background: var(--primary);
            color: white;
        }

        .pagination .disabled {
            color: var(--gray);
            cursor: not-allowed;
        }

        /* Empty State */
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .data-table {
                font-size: 0.875rem;
            }

            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-user-graduate"></i> Student Management</h1>
                <p>View and manage students in your classes with payment status</p>
            </div>
            <div class="header-actions">
                <a href="<?php echo BASE_URL; ?>modules/instructor/students/progress.php" class="btn btn-secondary">
                    <i class="fas fa-chart-line"></i> Progress Analytics
                </a>
                <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> Filter Students</h3>
                <a href="list.php" class="filter-reset">Reset Filters</a>
            </div>

            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Search Students</label>
                        <input type="text" name="search" class="filter-control" placeholder="Name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Class</label>
                        <select name="class_id" class="filter-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['course_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Enrollment Status</label>
                        <select name="status" class="filter-control">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="dropped" <?php echo $status_filter == 'dropped' ? 'selected' : ''; ?>>Dropped</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Payment Status</label>
                        <select name="payment" class="filter-control">
                            <option value="">All Payment Status</option>
                            <option value="cleared" <?php echo $payment_filter == 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                            <option value="overdue" <?php echo $payment_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="suspended" <?php echo $payment_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="partially_paid" <?php echo $payment_filter == 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                            <option value="not_paid" <?php echo $payment_filter == 'not_paid' ? 'selected' : ''; ?>>Not Paid</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <button type="button" onclick="window.location.href='list.php'" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
            </div>

            <?php
            // Calculate stats (simplified - in real app, query database)
            $cleared_count = 0;
            $suspended_count = 0;
            $overdue_count = 0;

            foreach ($students as $student) {
                if ($student['is_cleared']) {
                    $cleared_count++;
                }
                if ($student['is_suspended']) {
                    $suspended_count++;
                }
                if ($student['balance'] > 0 && $student['next_payment_due'] && strtotime($student['next_payment_due']) < time()) {
                    $overdue_count++;
                }
            }
            ?>

            <div class="stat-card">
                <div class="stat-icon cleared">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $cleared_count; ?></div>
                    <div class="stat-label">Payment Cleared</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon suspended">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $suspended_count; ?></div>
                    <div class="stat-label">Suspended</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon overdue">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $overdue_count; ?></div>
                    <div class="stat-label">Payment Overdue</div>
                </div>
            </div>
        </div>

        <!-- Students Table -->
        <div class="students-table-card">
            <div class="table-header">
                <h3>Students List</h3>
                <div class="results-info">
                    Showing <?php echo min($offset + 1, $total_students); ?>-<?php echo min($offset + count($students), $total_students); ?> of <?php echo $total_students; ?> students
                </div>
            </div>

            <div class="table-container">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Found</h3>
                        <p>No students match your search criteria. Try adjusting your filters.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Academic Progress</th>
                                <th>Payment Status</th>
                                <th>Balance</th>
                                <th>Next Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <?php
                                // Determine payment status badge
                                $payment_status = '';
                                $payment_badge_class = '';
                                $payment_text = '';

                                if ($student['is_cleared']) {
                                    $payment_status = 'Cleared';
                                    $payment_badge_class = 'payment-cleared';
                                    $payment_text = 'All fees paid';
                                } elseif ($student['is_suspended']) {
                                    $payment_status = 'Suspended';
                                    $payment_badge_class = 'payment-suspended';
                                    $payment_text = 'Account suspended';
                                } elseif ($student['balance'] > 0 && $student['next_payment_due'] && strtotime($student['next_payment_due']) < time()) {
                                    $payment_status = 'Overdue';
                                    $payment_badge_class = 'payment-overdue';
                                    $payment_text = 'Payment overdue';
                                } elseif ($student['balance'] > 0) {
                                    $payment_status = 'Partial';
                                    $payment_badge_class = 'payment-partial';
                                    $payment_text = 'Partial payment';
                                } else {
                                    $payment_status = 'Not Paid';
                                    $payment_badge_class = 'payment-not-paid';
                                    $payment_text = 'No payment made';
                                }
                                ?>

                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-details">
                                                <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                                <p><?php echo htmlspecialchars($student['email']); ?></p>
                                                <?php if ($student['phone']): ?>
                                                    <p style="font-size: 0.75rem; color: var(--gray);"><?php echo htmlspecialchars($student['phone']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($student['batch_code']); ?></div>
                                        <div style="font-size: 0.875rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($student['course_title']); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($student['enrollment_status']); ?>">
                                            <?php echo ucfirst($student['enrollment_status']); ?>
                                        </span>
                                        <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                            Since <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div style="font-weight: 600;"><?php echo $student['total_submissions']; ?> submissions</div>
                                        <div style="font-size: 0.875rem; color: var(--gray);">
                                            Avg: <?php echo number_format($student['average_score'], 1); ?>%
                                            <?php if ($student['final_grade']): ?>
                                                | Final: <?php echo $student['final_grade']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="payment-badge <?php echo $payment_badge_class; ?>" title="<?php echo $payment_text; ?>">
                                            <?php echo $payment_status; ?>
                                        </span>
                                        <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                            Block: <?php echo $student['current_block'] ?? 1; ?>
                                            <?php if ($student['registration_paid']): ?>✓ Reg<?php endif; ?>
                                            <?php if ($student['block1_paid']): ?>✓ B1<?php endif; ?>
                                            <?php if ($student['block2_paid']): ?>✓ B2<?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="amount paid">₦<?php echo number_format($student['paid_amount'] ?? 0, 2); ?></div>
                                        <div class="amount due">₦<?php echo number_format($student['balance'] ?? 0, 2); ?></div>
                                    </td>

                                    <td>
                                        <?php if ($student['next_payment_due']): ?>
                                            <div style="font-weight: 600; <?php echo strtotime($student['next_payment_due']) < time() ? 'color: var(--danger);' : 'color: var(--dark);'; ?>">
                                                <?php echo date('M d, Y', strtotime($student['next_payment_due'])); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--gray);">
                                                <?php echo strtotime($student['next_payment_due']) < time() ? 'Overdue' : 'Due'; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray);">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="action-buttons">
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/students/progress.php?student_id=<?php echo $student['student_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn-icon btn-progress" title="View Progress">
                                                <i class="fas fa-chart-line"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/students/finance.php?student_id=<?php echo $student['student_id']; ?>&class_id=<?php echo $student['class_id']; ?>"
                                                class="btn-icon btn-finance" title="Financial Status">
                                                <i class="fas fa-money-check-alt"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/shared/messages/compose.php?to=<?php echo $student['student_id']; ?>"
                                                class="btn-icon btn-message" title="Send Message">
                                                <i class="fas fa-envelope"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);

                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit form when class selection changes (for better UX)
        document.querySelector('select[name="class_id"]')?.addEventListener('change', function() {
            this.form.submit();
        });

        // Export functionality
        function exportStudents(format) {
            const params = new URLSearchParams(window.location.search);
            params.append('export', format);

            if (format === 'csv') {
                window.location.href = 'export.php?' + params.toString();
            } else if (format === 'pdf') {
                alert('PDF export feature coming soon!');
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }

            // Esc to clear search
            if (e.key === 'Escape') {
                document.querySelector('input[name="search"]').value = '';
            }
        });

        // Highlight overdue payments
        document.addEventListener('DOMContentLoaded', function() {
            const overdueCells = document.querySelectorAll('td .amount.due');
            overdueCells.forEach(cell => {
                const amount = parseFloat(cell.textContent.replace(/[^0-9.]/g, ''));
                if (amount > 0) {
                    cell.style.color = 'var(--danger)';
                    cell.style.fontWeight = '700';
                }
            });
        });
    </script>
</body>

</html>