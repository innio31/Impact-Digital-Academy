<?php
// modules/instructor/finance/overview.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Restrict access to instructors only
if (!isLoggedIn() || $_SESSION['role'] !== 'instructor') {
    header('Location: /modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get instructor details
$conn = getDBConnection();
$sql = "SELECT u.first_name, u.last_name, u.email 
        FROM users u 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$instructor = $result->fetch_assoc();

// Get all classes assigned to this instructor
$sql = "SELECT cb.*, c.title as course_title, p.name as program_name,
               p.program_code, p.program_type,
               COUNT(DISTINCT e.id) as enrolled_students,
               COUNT(DISTINCT sfs.id) as students_with_status
        FROM class_batches cb
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        LEFT JOIN enrollments e ON e.class_id = cb.id AND e.status = 'active'
        LEFT JOIN student_financial_status sfs ON sfs.class_id = cb.id
        WHERE cb.instructor_id = ?
        AND cb.status IN ('scheduled', 'ongoing')
        GROUP BY cb.id
        ORDER BY cb.start_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);

// Calculate financial statistics for each class
$class_stats = [];
$total_stats = [
    'total_students' => 0,
    'fully_paid' => 0,
    'partially_paid' => 0,
    'not_paid' => 0,
    'suspended' => 0,
    'overdue' => 0,
    'total_fee_amount' => 0,
    'total_paid_amount' => 0,
    'total_balance_amount' => 0
];

foreach ($classes as $class) {
    $class_id = $class['id'];

    // Get financial status for this class
    $sql = "SELECT 
                COUNT(sfs.id) as total_students,
                SUM(sfs.is_cleared = 1) as fully_paid,
                SUM(sfs.is_cleared = 0 AND sfs.paid_amount > 0) as partially_paid,
                SUM(sfs.is_cleared = 0 AND sfs.paid_amount = 0) as not_paid,
                SUM(sfs.is_suspended = 1) as suspended,
                SUM(sfs.balance > 0 AND sfs.next_payment_due < CURDATE()) as overdue,
                SUM(sfs.total_fee) as total_fee_amount,
                SUM(sfs.paid_amount) as total_paid_amount,
                SUM(sfs.balance) as total_balance_amount
            FROM student_financial_status sfs
            JOIN enrollments e ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
            WHERE sfs.class_id = ? AND e.status = 'active'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();

    // Add class info to stats
    $stats['class_info'] = $class;
    $stats['payment_progress'] = $stats['total_fee_amount'] > 0
        ? round(($stats['total_paid_amount'] / $stats['total_fee_amount']) * 100, 2)
        : 0;

    $class_stats[] = $stats;

    // Update total stats
    $total_stats['total_students'] += $stats['total_students'] ?? 0;
    $total_stats['fully_paid'] += $stats['fully_paid'] ?? 0;
    $total_stats['partially_paid'] += $stats['partially_paid'] ?? 0;
    $total_stats['not_paid'] += $stats['not_paid'] ?? 0;
    $total_stats['suspended'] += $stats['suspended'] ?? 0;
    $total_stats['overdue'] += $stats['overdue'] ?? 0;
    $total_stats['total_fee_amount'] += $stats['total_fee_amount'] ?? 0;
    $total_stats['total_paid_amount'] += $stats['total_paid_amount'] ?? 0;
    $total_stats['total_balance_amount'] += $stats['total_balance_amount'] ?? 0;
}

$overall_payment_progress = $total_stats['total_fee_amount'] > 0
    ? round(($total_stats['total_paid_amount'] / $total_stats['total_fee_amount']) * 100, 2)
    : 0;

// Get upcoming payment due dates
$sql = "SELECT sfs.*, u.first_name, u.last_name, u.email, 
               cb.batch_code, c.title as course_title,
               DATEDIFF(sfs.next_payment_due, CURDATE()) as days_until_due
        FROM student_financial_status sfs
        JOIN users u ON u.id = sfs.student_id
        JOIN class_batches cb ON cb.id = sfs.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN enrollments e ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE cb.instructor_id = ?
        AND sfs.balance > 0
        AND sfs.next_payment_due IS NOT NULL
        AND sfs.next_payment_due >= CURDATE()
        AND e.status = 'active'
        ORDER BY sfs.next_payment_due ASC
        LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$upcoming_payments = $result->fetch_all(MYSQLI_ASSOC);

// Get recent financial alerts
$sql = "SELECT fl.*, u.first_name, u.last_name, cb.batch_code,
               c.title as course_title
        FROM financial_logs fl
        JOIN users u ON u.id = fl.student_id
        LEFT JOIN class_batches cb ON cb.id = fl.class_id
        LEFT JOIN courses c ON c.id = cb.course_id
        WHERE fl.class_id IN (
            SELECT id FROM class_batches WHERE instructor_id = ?
        )
        AND fl.action IN ('payment_received', 'invoice_generated', 'overdue_notification', 'suspension_notification')
        ORDER BY fl.created_at DESC
        LIMIT 15";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructor_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_alerts = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Overview - Instructor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --border-color: #dee2e6;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .sidebar {
            background-color: var(--primary-color);
            min-height: 100vh;
            color: white;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .sidebar .nav-link i {
            width: 25px;
        }

        .main-content {
            padding: 20px;
        }

        .dashboard-header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .progress-bar-custom {
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
        }

        .payment-status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .paid {
            background-color: #d4edda;
            color: #155724;
        }

        .partial {
            background-color: #fff3cd;
            color: #856404;
        }

        .unpaid {
            background-color: #f8d7da;
            color: #721c24;
        }

        .overdue {
            background-color: #721c24;
            color: white;
        }

        .suspended {
            background-color: #6c757d;
            color: white;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .alert-card {
            border-left: 4px solid;
            margin-bottom: 10px;
        }

        .alert-payment {
            border-color: var(--success-color);
        }

        .alert-invoice {
            border-color: var(--secondary-color);
        }

        .alert-overdue {
            border-color: var(--danger-color);
        }

        .alert-suspension {
            border-color: var(--warning-color);
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .status-active {
            background-color: var(--success-color);
        }

        .status-upcoming {
            background-color: var(--warning-color);
        }

        .status-completed {
            background-color: #6c757d;
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-3">
                    <h4 class="text-center mb-4">
                        <i class="fas fa-graduation-cap"></i> Impact Academy
                    </h4>
                    <div class="text-center mb-4">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center"
                            style="width: 80px; height: 80px;">
                            <i class="fas fa-user-tie fa-2x text-primary"></i>
                        </div>
                        <h5 class="mt-2 mb-1"><?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?></h5>
                        <small class="text-light">Instructor</small>
                    </div>
                </div>

                <nav class="nav flex-column p-2">
                    <a href="/modules/instructor/dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="/modules/instructor/classes/index.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i> My Classes
                    </a>
                    <a href="/modules/instructor/schedule.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </a>
                    <a href="/modules/instructor/finance/overview.php" class="nav-link active">
                        <i class="fas fa-chart-line"></i> Finance Overview
                    </a>
                    <a href="/modules/instructor/finance/students/index.php" class="nav-link">
                        <i class="fas fa-users"></i> Student Payments
                    </a>
                    <a href="/modules/instructor/finance/reports/class_finance.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i> Financial Reports
                    </a>
                    <a href="/modules/shared/profile/edit_account.php" class="nav-link">
                        <i class="fas fa-user-cog"></i> Profile Settings
                    </a>
                    <a href="/modules/auth/logout.php" class="nav-link text-danger">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 main-content">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="fas fa-chart-line text-primary me-2"></i> Financial Overview</h2>
                            <p class="text-muted mb-0">
                                Track payment statuses, financial alerts, and revenue across all your classes
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary">
                                    <i class="fas fa-download"></i> Export Report
                                </button>
                                <button type="button" class="btn btn-primary">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="stat-value"><?php echo formatCurrency($total_stats['total_paid_amount']); ?></div>
                                    <div class="stat-label">Total Received</div>
                                </div>
                            </div>
                            <div class="progress progress-bar-custom mt-3">
                                <div class="progress-bar bg-success"
                                    role="progressbar"
                                    style="width: <?php echo $overall_payment_progress; ?>%">
                                    <?php echo $overall_payment_progress; ?>%
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="stat-value"><?php echo formatCurrency($total_stats['total_balance_amount']); ?></div>
                                    <div class="stat-label">Outstanding Balance</div>
                                </div>
                            </div>
                            <small class="text-muted">From <?php echo $total_stats['total_students']; ?> active students</small>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="stat-value"><?php echo $total_stats['overdue']; ?></div>
                                    <div class="stat-label">Overdue Payments</div>
                                </div>
                            </div>
                            <small class="text-muted"><?php echo $total_stats['suspended']; ?> students suspended</small>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="ms-3">
                                    <div class="stat-value"><?php echo count($classes); ?></div>
                                    <div class="stat-label">Active Classes</div>
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo $total_stats['fully_paid']; ?> fully paid,
                                <?php echo $total_stats['partially_paid']; ?> partially paid
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Class-wise Financial Status -->
                <div class="row">
                    <div class="col-md-8">
                        <div class="stat-card">
                            <h4 class="mb-3">
                                <i class="fas fa-chalkboard-teacher me-2"></i> Class-wise Financial Status
                            </h4>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Class</th>
                                            <th>Program</th>
                                            <th>Students</th>
                                            <th>Total Fee</th>
                                            <th>Received</th>
                                            <th>Balance</th>
                                            <th>Progress</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($class_stats)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">
                                                    No active classes found
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($class_stats as $stats):
                                                $class = $stats['class_info'];
                                                $payment_status = '';
                                                if ($stats['fully_paid'] == $stats['total_students']) {
                                                    $payment_status = 'success';
                                                    $status_text = 'All Paid';
                                                } elseif ($stats['overdue'] > 0) {
                                                    $payment_status = 'danger';
                                                    $status_text = 'Overdue';
                                                } elseif ($stats['payment_progress'] > 50) {
                                                    $payment_status = 'warning';
                                                    $status_text = 'Partial';
                                                } else {
                                                    $payment_status = 'secondary';
                                                    $status_text = 'Pending';
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($class['batch_code']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($class['course_title']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($class['program_name']); ?>
                                                        <br>
                                                        <span class="badge bg-<?php echo $class['program_type'] == 'online' ? 'info' : 'primary'; ?>">
                                                            <?php echo strtoupper($class['program_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $stats['total_students'] ?? 0; ?></td>
                                                    <td><?php echo formatCurrency($stats['total_fee_amount'] ?? 0); ?></td>
                                                    <td><?php echo formatCurrency($stats['total_paid_amount'] ?? 0); ?></td>
                                                    <td>
                                                        <?php if (($stats['total_balance_amount'] ?? 0) > 0): ?>
                                                            <span class="text-danger fw-bold">
                                                                <?php echo formatCurrency($stats['total_balance_amount']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-success">₦0.00</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="progress progress-bar-custom" style="height: 8px;">
                                                            <div class="progress-bar bg-success"
                                                                role="progressbar"
                                                                style="width: <?php echo $stats['payment_progress']; ?>%">
                                                            </div>
                                                        </div>
                                                        <small><?php echo $stats['payment_progress']; ?>%</small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $payment_status; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Payments & Recent Alerts -->
                    <div class="col-md-4">
                        <!-- Upcoming Payments -->
                        <div class="stat-card mb-4">
                            <h4 class="mb-3">
                                <i class="fas fa-calendar-check me-2"></i> Upcoming Payments
                            </h4>
                            <?php if (empty($upcoming_payments)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                    <p>No upcoming payments due</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($upcoming_payments as $payment):
                                        $due_days = $payment['days_until_due'];
                                        $due_class = $due_days <= 3 ? 'warning' : ($due_days <= 7 ? 'info' : 'secondary');
                                    ?>
                                        <a href="/modules/instructor/finance/students/index.php?class_id=<?php echo $payment['class_id']; ?>"
                                            class="list-group-item list-group-item-action">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>
                                                </h6>
                                                <small class="text-<?php echo $due_class; ?>">
                                                    <?php echo $due_days; ?> days
                                                </small>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($payment['course_title']); ?>
                                                <br>
                                                <span class="text-muted"><?php echo htmlspecialchars($payment['batch_code']); ?></span>
                                            </p>
                                            <small>
                                                Due: <?php echo date('M d, Y', strtotime($payment['next_payment_due'])); ?>
                                                • Balance: <?php echo formatCurrency($payment['balance']); ?>
                                            </small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="/modules/instructor/finance/students/alerts.php" class="btn btn-sm btn-outline-primary">
                                        View All Alerts <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Recent Financial Alerts -->
                        <div class="stat-card">
                            <h4 class="mb-3">
                                <i class="fas fa-bell me-2"></i> Recent Financial Activity
                            </h4>
                            <?php if (empty($recent_alerts)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-bell-slash fa-2x mb-2"></i>
                                    <p>No recent financial alerts</p>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($recent_alerts as $alert):
                                        $alert_class = '';
                                        $alert_icon = '';

                                        switch ($alert['action']) {
                                            case 'payment_received':
                                                $alert_class = 'alert-payment';
                                                $alert_icon = 'fa-money-bill-wave text-success';
                                                break;
                                            case 'invoice_generated':
                                                $alert_class = 'alert-invoice';
                                                $alert_icon = 'fa-file-invoice text-primary';
                                                break;
                                            case 'overdue_notification':
                                                $alert_class = 'alert-overdue';
                                                $alert_icon = 'fa-exclamation-triangle text-danger';
                                                break;
                                            case 'suspension_notification':
                                                $alert_class = 'alert-suspension';
                                                $alert_icon = 'fa-user-slash text-warning';
                                                break;
                                            default:
                                                $alert_class = '';
                                                $alert_icon = 'fa-info-circle text-secondary';
                                        }
                                    ?>
                                        <div class="alert-card p-3 bg-light <?php echo $alert_class; ?>">
                                            <div class="d-flex align-items-start">
                                                <i class="fas <?php echo $alert_icon; ?> mt-1 me-3"></i>
                                                <div class="flex-grow-1">
                                                    <p class="mb-1">
                                                        <?php echo htmlspecialchars($alert['description']); ?>
                                                    </p>
                                                    <small class="text-muted">
                                                        <i class="far fa-clock me-1"></i>
                                                        <?php echo formatDate($alert['created_at'], 'h:i A'); ?> •
                                                        <?php echo $alert['batch_code'] ? htmlspecialchars($alert['batch_code']) : 'System'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment Distribution -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="stat-card">
                            <h4 class="mb-3">
                                <i class="fas fa-chart-pie me-2"></i> Payment Status Distribution
                            </h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <canvas id="paymentStatusChart" height="200"></canvas>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="status-indicator status-active"></span>
                                        <div class="flex-grow-1">
                                            <span class="fw-bold">Fully Paid</span>
                                            <span class="float-end"><?php echo $total_stats['fully_paid']; ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="status-indicator" style="background-color: #ffc107;"></span>
                                        <div class="flex-grow-1">
                                            <span class="fw-bold">Partially Paid</span>
                                            <span class="float-end"><?php echo $total_stats['partially_paid']; ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="status-indicator" style="background-color: #dc3545;"></span>
                                        <div class="flex-grow-1">
                                            <span class="fw-bold">Not Paid</span>
                                            <span class="float-end"><?php echo $total_stats['not_paid']; ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="status-indicator" style="background-color: #721c24;"></span>
                                        <div class="flex-grow-1">
                                            <span class="fw-bold">Suspended</span>
                                            <span class="float-end"><?php echo $total_stats['suspended']; ?></span>
                                        </div>
                                    </div>
                                    <div class="mt-4 text-center">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-primary" onclick="downloadChart('paymentStatusChart')">
                                                <i class="fas fa-download"></i> Chart
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" onclick="printChart('paymentStatusChart')">
                                                <i class="fas fa-print"></i> Print
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="col-md-6">
                        <div class="stat-card">
                            <h4 class="mb-3">
                                <i class="fas fa-bolt me-2"></i> Quick Actions
                            </h4>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <a href="/modules/instructor/finance/students/index.php"
                                        class="card action-card text-decoration-none text-dark">
                                        <div class="card-body text-center py-4">
                                            <i class="fas fa-user-check fa-2x text-primary mb-3"></i>
                                            <h6>View Student Payments</h6>
                                            <small class="text-muted">Check individual payment status</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="/modules/instructor/finance/reports/class_finance.php"
                                        class="card action-card text-decoration-none text-dark">
                                        <div class="card-body text-center py-4">
                                            <i class="fas fa-file-chart-line fa-2x text-success mb-3"></i>
                                            <h6>Generate Reports</h6>
                                            <small class="text-muted">Create financial reports</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="/modules/instructor/finance/students/alerts.php"
                                        class="card action-card text-decoration-none text-dark">
                                        <div class="card-body text-center py-4">
                                            <i class="fas fa-exclamation-circle fa-2x text-warning mb-3"></i>
                                            <h6>Payment Alerts</h6>
                                            <small class="text-muted">View overdue payments</small>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="mailto:finance@impactacademy.edu?subject=Payment%20Issue%20-%20Instructor"
                                        class="card action-card text-decoration-none text-dark">
                                        <div class="card-body text-center py-4">
                                            <i class="fas fa-headset fa-2x text-info mb-3"></i>
                                            <h6>Contact Finance</h6>
                                            <small class="text-muted">Report payment issues</small>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Payment Status Chart
        const paymentStatusCtx = document.getElementById('paymentStatusChart').getContext('2d');

        // Check if we have data for the chart
        <?php if ($total_stats['total_students'] > 0): ?>
            const paymentStatusChart = new Chart(paymentStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Fully Paid', 'Partially Paid', 'Not Paid', 'Suspended'],
                    datasets: [{
                        data: [
                            <?php echo $total_stats['fully_paid']; ?>,
                            <?php echo $total_stats['partially_paid']; ?>,
                            <?php echo $total_stats['not_paid']; ?>,
                            <?php echo $total_stats['suspended']; ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#ffc107',
                            '#dc3545',
                            '#721c24'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?php echo $total_stats['total_students']; ?>;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        // Helper functions
        function downloadChart(chartId) {
            const canvas = document.getElementById(chartId);
            const link = document.createElement('a');
            link.download = 'payment-status-chart.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }

        function printChart(chartId) {
            const canvas = document.getElementById(chartId);
            const win = window.open('');
            win.document.write('<img src="' + canvas.toDataURL('image/png') + '"/>');
            win.print();
            win.close();
        }

        // Auto-refresh page every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes = 300000 milliseconds

        // Update last refresh time
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const refreshTime = now.toLocaleTimeString();
            console.log(`Page last refreshed at: ${refreshTime}`);
        });
    </script>
</body>

</html>