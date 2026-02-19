<?php
// modules/student/finance/dashboard.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/finance_functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];
$user_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($user_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

// Initialize financial data - NEW SYSTEM
$financial_overview = [
    'total_fee' => 0,
    'paid_amount' => 0,
    'balance' => 0,
    'overdue_balance' => 0,
    'registration_fee' => 0,
    'registration_paid' => 0,
    'course_fee' => 0,
    'course_fee_paid' => 0,
    'course_fee_balance' => 0,
    'late_fees' => 0,
    'penalties' => 0,
    'waivers' => 0
];

// Get student's enrolled classes with financial status - NEW SYSTEM
$enrolled_classes = [];
$sql = "SELECT e.*, cb.*, c.title as course_title, c.course_code, 
               p.name as program_name, p.program_code, p.program_type,
               p.fee as program_fee, p.registration_fee,
               sfs.*
        FROM enrollments e
        JOIN class_batches cb ON e.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
        WHERE e.student_id = ? AND e.status IN ('active', 'completed')
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $enrolled_classes[] = $row;

            // Accumulate financial data - NEW SYSTEM
            $program_fee = floatval($row['program_fee'] ?? 0);
            $registration_fee = floatval($row['registration_fee'] ?? 0);

            // Total fee is program fee + registration fee
            $financial_overview['total_fee'] += $program_fee + $registration_fee;

            // Get paid amount from student_financial_status
            $financial_overview['paid_amount'] += floatval($row['paid_amount'] ?? 0);
            $financial_overview['balance'] += floatval($row['balance'] ?? 0);

            // Check if overdue
            if ($row['next_payment_due'] && strtotime($row['next_payment_due']) < time()) {
                $financial_overview['overdue_balance'] += floatval($row['balance'] ?? 0);
            }

            // Registration fee
            $financial_overview['registration_fee'] += $registration_fee;
            if ($row['registration_paid']) {
                $financial_overview['registration_paid'] += $registration_fee;
            }

            // Course/Program fee
            $course_fee = $program_fee;
            $financial_overview['course_fee'] += $course_fee;
        }
    }
    $stmt->close();
}

// Get course payments to determine what's paid - NEW SYSTEM
$course_payments_total = 0;
$course_payment_sql = "SELECT SUM(amount) as total_paid 
                       FROM course_payments 
                       WHERE student_id = ? AND status = 'completed'";
$course_payment_stmt = $conn->prepare($course_payment_sql);
if ($course_payment_stmt) {
    $course_payment_stmt->bind_param("i", $user_id);
    $course_payment_stmt->execute();
    $course_payment_result = $course_payment_stmt->get_result();
    if ($course_payment_result && $course_payment_row = $course_payment_result->fetch_assoc()) {
        $course_payments_total = floatval($course_payment_row['total_paid'] ?? 0);
    }
    $course_payment_stmt->close();
}

// Get registration fee payments - NEW SYSTEM
$registration_payments_total = 0;
$registration_sql = "SELECT SUM(amount) as total_paid 
                     FROM registration_fee_payments 
                     WHERE student_id = ? AND status = 'completed'";
$registration_stmt = $conn->prepare($registration_sql);
if ($registration_stmt) {
    $registration_stmt->bind_param("i", $user_id);
    $registration_stmt->execute();
    $registration_result = $registration_stmt->get_result();
    if ($registration_result && $registration_row = $registration_result->fetch_assoc()) {
        $registration_payments_total = floatval($registration_row['total_paid'] ?? 0);
    }
    $registration_stmt->close();
}

// Update paid amounts with actual payments - NEW SYSTEM
$financial_overview['registration_paid'] = $registration_payments_total;
$financial_overview['course_fee_paid'] = $course_payments_total;
$financial_overview['paid_amount'] = $course_payments_total + $registration_payments_total;

// Calculate course fee balance
$financial_overview['course_fee_balance'] = max(0, $financial_overview['course_fee'] - $course_payments_total);

// Recalculate total balance
$financial_overview['balance'] = max(0, ($financial_overview['registration_fee'] - $registration_payments_total) +
    ($financial_overview['course_fee'] - $course_payments_total));

// Get fee waivers
$waivers = [];
$waiver_sql = "SELECT fw.*, p.name as program_name, fs.name as fee_structure_name
              FROM fee_waivers fw
              LEFT JOIN fee_structures fs ON fw.fee_structure_id = fs.id
              LEFT JOIN programs p ON fs.program_id = p.id
              WHERE fw.student_id = ? AND fw.status = 'approved'
              AND (fw.expiry_date IS NULL OR fw.expiry_date >= CURDATE())";
$waiver_stmt = $conn->prepare($waiver_sql);
$waiver_stmt->bind_param("i", $user_id);
$waiver_stmt->execute();
$waiver_result = $waiver_stmt->get_result();
while ($waiver = $waiver_result->fetch_assoc()) {
    $waivers[] = $waiver;

    // Calculate waiver value
    if ($waiver['waiver_type'] === 'full') {
        $waiver_value = $financial_overview['total_fee'];
    } elseif ($waiver['waiver_type'] === 'percentage') {
        $waiver_value = $financial_overview['total_fee'] * (floatval($waiver['waiver_value']) / 100);
    } else {
        $waiver_value = floatval($waiver['waiver_value']);
    }

    $financial_overview['waivers'] += $waiver_value;
}
$waiver_stmt->close();

// Get penalties
$penalties = [];
$penalty_sql = "SELECT ph.*, p.name as program_name
               FROM penalty_history ph
               LEFT JOIN programs p ON ph.program_id = p.id
               WHERE ph.student_id = ? AND ph.waived = 0";
$penalty_stmt = $conn->prepare($penalty_sql);
$penalty_stmt->bind_param("i", $user_id);
$penalty_stmt->execute();
$penalty_result = $penalty_stmt->get_result();
while ($penalty = $penalty_result->fetch_assoc()) {
    $penalties[] = $penalty;
    $financial_overview['penalties'] += floatval($penalty['penalty_amount']);
}
$penalty_stmt->close();

// Get recent transactions - UPDATED for new system
$transactions = [];
$trans_sql = "SELECT ft.*, cb.batch_code, c.title as course_title,
                     p.name as program_name,
                     CASE 
                         WHEN ft.transaction_type = 'registration' THEN 'Registration Fee'
                         WHEN ft.transaction_type = 'tuition' THEN 'Course Fee Payment'
                         WHEN ft.transaction_type = 'late_fee' THEN 'Late Fee'
                         WHEN ft.transaction_type = 'refund' THEN 'Refund'
                         ELSE 'Other'
                     END as type_label
              FROM financial_transactions ft
              LEFT JOIN class_batches cb ON ft.class_id = cb.id
              LEFT JOIN courses c ON cb.course_id = c.id
              LEFT JOIN programs p ON c.program_id = p.id
              WHERE ft.student_id = ?
              ORDER BY ft.created_at DESC
              LIMIT 10";

// Also get registration and course payments separately for completeness
$reg_trans_sql = "SELECT rfp.*, 'Registration Fee' as type_label, p.name as program_name
                  FROM registration_fee_payments rfp
                  LEFT JOIN programs p ON rfp.program_id = p.id
                  WHERE rfp.student_id = ?
                  ORDER BY rfp.payment_date DESC
                  LIMIT 5";
$reg_stmt = $conn->prepare($reg_trans_sql);
$reg_stmt->bind_param("i", $user_id);
$reg_stmt->execute();
$reg_result = $reg_stmt->get_result();
while ($reg_trans = $reg_result->fetch_assoc()) {
    $reg_trans['transaction_type'] = 'registration';
    $transactions[] = $reg_trans;
}
$reg_stmt->close();

$course_trans_sql = "SELECT cp.*, 'Course Fee Payment' as type_label, 
                            c.title as course_title, cb.batch_code, p.name as program_name
                     FROM course_payments cp
                     LEFT JOIN courses c ON cp.course_id = c.id
                     LEFT JOIN class_batches cb ON cp.class_id = cb.id
                     LEFT JOIN programs p ON c.program_id = p.id
                     WHERE cp.student_id = ?
                     ORDER BY cp.payment_date DESC
                     LIMIT 5";
$course_stmt = $conn->prepare($course_trans_sql);
$course_stmt->bind_param("i", $user_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result();
while ($course_trans = $course_result->fetch_assoc()) {
    $course_trans['transaction_type'] = 'tuition';
    $transactions[] = $course_trans;
}
$course_stmt->close();

// Sort all transactions by date
usort($transactions, function ($a, $b) {
    $dateA = isset($a['created_at']) ? $a['created_at'] : (isset($a['payment_date']) ? $a['payment_date'] . ' 00:00:00' : '1970-01-01');
    $dateB = isset($b['created_at']) ? $b['created_at'] : (isset($b['payment_date']) ? $b['payment_date'] . ' 00:00:00' : '1970-01-01');
    return strtotime($dateB) - strtotime($dateA);
});
$transactions = array_slice($transactions, 0, 10);

// Get pending invoices - UPDATED for new system
$pending_invoices = [];
$invoice_sql = "SELECT i.*, cb.batch_code, c.title as course_title,
                       p.name as program_name,
                       DATEDIFF(i.due_date, CURDATE()) as days_left
                FROM invoices i
                JOIN class_batches cb ON i.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN programs p ON c.program_id = p.id
                WHERE i.student_id = ?
                AND i.status IN ('pending', 'partial')
                AND i.due_date >= CURDATE()
                AND i.invoice_type IN ('registration', 'tuition')
                ORDER BY i.due_date ASC
                LIMIT 5";
$invoice_stmt = $conn->prepare($invoice_sql);
$invoice_stmt->bind_param("i", $user_id);
$invoice_stmt->execute();
$invoice_result = $invoice_stmt->get_result();
while ($invoice = $invoice_result->fetch_assoc()) {
    $pending_invoices[] = $invoice;
}
$invoice_stmt->close();

// Get overdue invoices - UPDATED for new system
$overdue_invoices = [];
$overdue_sql = "SELECT i.*, cb.batch_code, c.title as course_title,
                       p.name as program_name,
                       DATEDIFF(CURDATE(), i.due_date) as days_overdue
                FROM invoices i
                JOIN class_batches cb ON i.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                JOIN programs p ON c.program_id = p.id
                WHERE i.student_id = ?
                AND i.status IN ('pending', 'partial')
                AND i.due_date < CURDATE()
                AND i.invoice_type IN ('registration', 'tuition')
                ORDER BY i.due_date ASC
                LIMIT 5";
$overdue_stmt = $conn->prepare($overdue_sql);
$overdue_stmt->bind_param("i", $user_id);
$overdue_stmt->execute();
$overdue_result = $overdue_stmt->get_result();
while ($invoice = $overdue_result->fetch_assoc()) {
    $overdue_invoices[] = $invoice;
}
$overdue_stmt->close();

// Get payment plan details - UPDATED for new system
$payment_plans = [];
$plan_sql = "SELECT pp.*, p.name as program_name
            FROM payment_plans pp
            JOIN programs p ON pp.program_id = p.id
            WHERE pp.program_id IN (
                SELECT DISTINCT c.program_id 
                FROM enrollments e
                JOIN class_batches cb ON e.class_id = cb.id
                JOIN courses c ON cb.course_id = c.id
                WHERE e.student_id = ?
            ) AND pp.is_active = 1";
$plan_stmt = $conn->prepare($plan_sql);
$plan_stmt->bind_param("i", $user_id);
$plan_stmt->execute();
$plan_result = $plan_stmt->get_result();
while ($plan = $plan_result->fetch_assoc()) {
    // Adapt plan display for new system - FIXED VERSION
    $plan['registration_fee'] = $plan['registration_fee'] ?? 0;

    // Calculate course fee based on what's available in the plan
    // Since there's no total_amount in the new system, we need to check what fields exist
    if (isset($plan['total_amount'])) {
        // Old system had total_amount
        $plan['course_fee'] = $plan['total_amount'] - $plan['registration_fee'] ?? 0;
    } else {
        // New system - check if we have program fee information
        // Get the program fee for this plan
        $program_sql = "SELECT fee FROM programs WHERE id = ?";
        $program_stmt = $conn->prepare($program_sql);
        $program_stmt->bind_param("i", $plan['program_id']);
        $program_stmt->execute();
        $program_result = $program_stmt->get_result();
        if ($program_row = $program_result->fetch_assoc()) {
            $program_fee = floatval($program_row['fee'] ?? 0);
            $plan['course_fee'] = $program_fee;
        } else {
            $plan['course_fee'] = 0;
        }
        $program_stmt->close();
    }

    $payment_plans[] = $plan;
}
$plan_stmt->close();

// Calculate payment progress
$payment_progress = 0;
if ($financial_overview['total_fee'] > 0) {
    $payment_progress = ($financial_overview['paid_amount'] / $financial_overview['total_fee']) * 100;
}

// Log access
logActivity($user_id, 'finance_dashboard_access', 'Student accessed financial dashboard', $_SERVER['REMOTE_ADDR']);

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Overview - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            text-decoration: none;
            color: var(--primary);
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border-top: 4px solid var(--primary);
        }

        .stat-card.success {
            border-top-color: var(--success);
        }

        .stat-card.warning {
            border-top-color: var(--warning);
        }

        .stat-card.danger {
            border-top-color: var(--danger);
        }

        .stat-card.info {
            border-top-color: var(--info);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: rgba(67, 97, 238, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .stat-card.success .stat-icon {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .stat-card.warning .stat-icon {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .stat-card.danger .stat-icon {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .stat-card.info .stat-icon {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-bar {
            height: 8px;
            background-color: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-warning {
            background-color: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background-color: #e01475;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #d62839;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background-color: #f8f9fa;
        }

        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-paid {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-overdue {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .status-partial {
            background-color: rgba(72, 149, 239, 0.1);
            color: var(--info);
        }

        .financial-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .financial-item {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .financial-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .financial-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }

        .financial-value.paid {
            color: var(--success);
        }

        .financial-value.due {
            color: var(--danger);
        }

        .financial-value.balance {
            color: var(--warning);
        }

        .alert-banner {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-warning {
            background-color: rgba(247, 37, 133, 0.1);
            border-left: 4px solid var(--warning);
            color: var(--dark);
        }

        .alert-danger {
            background-color: rgba(230, 57, 70, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--dark);
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert-info {
            background-color: rgba(72, 149, 239, 0.1);
            border-left: 4px solid var(--info);
            color: var(--dark);
        }

        .invoice-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .invoice-item:hover {
            background-color: #f8f9fa;
        }

        .invoice-item.overdue {
            background-color: rgba(230, 57, 70, 0.05);
            border-left: 3px solid var(--danger);
        }

        .invoice-info {
            flex: 1;
        }

        .invoice-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .invoice-info p {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .invoice-amount {
            text-align: right;
            margin-left: 1rem;
        }

        .invoice-amount .amount {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
        }

        .invoice-amount .due-date {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            background-color: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .quick-action:hover {
            background-color: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        .quick-action:hover .quick-action-icon {
            background-color: white;
            color: var(--primary);
        }

        .quick-action-icon {
            width: 48px;
            height: 48px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }

        .quick-action-label {
            font-size: 0.875rem;
            font-weight: 600;
            text-align: center;
        }

        .payment-breakdown {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background-color: #f8f9fa;
            border-radius: 6px;
        }

        .breakdown-label {
            font-size: 0.875rem;
            color: var(--dark);
        }

        .breakdown-value {
            font-weight: 600;
            color: var(--dark);
        }

        .breakdown-value.paid {
            color: var(--success);
        }

        .breakdown-value.pending {
            color: var(--warning);
        }

        .breakdown-value.overdue {
            color: var(--danger);
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
            <span>Financial Overview</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Financial Overview</h1>
            <p>Manage your payments, view invoices, and track your financial status</p>
        </div>

        <!-- Alert Banners -->
        <?php if ($financial_overview['overdue_balance'] > 0): ?>
            <div class="alert-banner alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div style="flex: 1;">
                    <strong>Overdue Payments Detected!</strong>
                    You have ₦<?php echo number_format($financial_overview['overdue_balance'], 2); ?> in overdue payments.
                    Please make payment immediately to avoid suspension.
                </div>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-danger">
                    Pay Now
                </a>
            </div>
        <?php elseif ($financial_overview['balance'] > 0): ?>
            <div class="alert-banner alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                <div style="flex: 1;">
                    <strong>Balance Due!</strong>
                    You have ₦<?php echo number_format($financial_overview['balance'], 2); ?> outstanding balance.
                    Please ensure payments are made by their due dates.
                </div>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-warning">
                    Make Payment
                </a>
            </div>
        <?php endif; ?>

        <!-- Financial Stats Grid -->
        <div class="stats-grid">
            <!-- Total Balance Card -->
            <div class="stat-card <?php echo $financial_overview['balance'] == 0 ? 'success' : ($financial_overview['overdue_balance'] > 0 ? 'danger' : 'warning'); ?>">
                <div class="stat-header">
                    <div class="stat-title">Total Balance</div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($financial_overview['balance'], 2); ?>
                </div>
                <div class="stat-change">
                    <?php if ($financial_overview['overdue_balance'] > 0): ?>
                        <span style="color: var(--danger);">
                            <i class="fas fa-exclamation-circle"></i>
                            ₦<?php echo number_format($financial_overview['overdue_balance'], 2); ?> overdue
                        </span>
                    <?php else: ?>
                        <span>
                            <i class="fas fa-info-circle"></i>
                            Total outstanding amount
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Paid Amount Card -->
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-title">Total Paid</div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($financial_overview['paid_amount'], 2); ?>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $payment_progress; ?>%"></div>
                </div>
                <div class="stat-change">
                    <i class="fas fa-chart-line"></i>
                    <?php echo round($payment_progress, 1); ?>% of total fees paid
                </div>
            </div>

            <!-- Total Fees Card -->
            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-title">Total Fees</div>
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="stat-value">
                    ₦<?php echo number_format($financial_overview['total_fee'], 2); ?>
                </div>
                <div class="stat-change">
                    <i class="fas fa-calculator"></i>
                    Includes registration and course fees
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Payment Breakdown - UPDATED for new system -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Payment Breakdown</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/fees/" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>

                    <div class="payment-breakdown">
                        <!-- Registration Fee -->
                        <div class="breakdown-item">
                            <div class="breakdown-label">
                                <i class="fas fa-user-plus" style="color: var(--info); margin-right: 0.5rem;"></i>
                                Registration Fee
                            </div>
                            <div class="breakdown-value <?php echo $financial_overview['registration_paid'] >= $financial_overview['registration_fee'] ? 'paid' : 'pending'; ?>">
                                ₦<?php echo number_format($financial_overview['registration_fee'], 2); ?>
                                <?php if ($financial_overview['registration_paid'] >= $financial_overview['registration_fee']): ?>
                                    <span class="status-badge status-paid" style="margin-left: 0.5rem;">Paid</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending" style="margin-left: 0.5rem;">
                                        ₦<?php echo number_format($financial_overview['registration_fee'] - $financial_overview['registration_paid'], 2); ?> due
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Course/Program Fee -->
                        <div class="breakdown-item">
                            <div class="breakdown-label">
                                <i class="fas fa-graduation-cap" style="color: var(--primary); margin-right: 0.5rem;"></i>
                                Course/Program Fee
                            </div>
                            <div class="breakdown-value <?php echo $financial_overview['course_fee_paid'] >= $financial_overview['course_fee'] ? 'paid' : 'pending'; ?>">
                                ₦<?php echo number_format($financial_overview['course_fee'], 2); ?>
                                <?php if ($financial_overview['course_fee_paid'] >= $financial_overview['course_fee']): ?>
                                    <span class="status-badge status-paid" style="margin-left: 0.5rem;">Paid</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending" style="margin-left: 0.5rem;">
                                        ₦<?php echo number_format($financial_overview['course_fee_balance'], 2); ?> due
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Penalties -->
                        <?php if ($financial_overview['penalties'] > 0): ?>
                            <div class="breakdown-item">
                                <div class="breakdown-label">
                                    <i class="fas fa-exclamation-triangle" style="color: var(--danger); margin-right: 0.5rem;"></i>
                                    Penalties & Late Fees
                                </div>
                                <div class="breakdown-value overdue">
                                    ₦<?php echo number_format($financial_overview['penalties'], 2); ?>
                                    <span class="status-badge status-overdue" style="margin-left: 0.5rem;">Due</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Waivers -->
                        <?php if ($financial_overview['waivers'] > 0): ?>
                            <div class="breakdown-item">
                                <div class="breakdown-label">
                                    <i class="fas fa-hand-holding-usd" style="color: var(--success); margin-right: 0.5rem;"></i>
                                    Approved Waivers
                                </div>
                                <div class="breakdown-value paid">
                                    -₦<?php echo number_format($financial_overview['waivers'], 2); ?>
                                    <span class="status-badge status-paid" style="margin-left: 0.5rem;">Applied</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Recent Transactions</h2>
                        <div class="card-actions">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/" class="btn btn-primary btn-sm">
                                <i class="fas fa-history"></i> View All
                            </a>
                        </div>
                    </div>

                    <?php if (empty($transactions)): ?>
                        <div class="no-data">
                            <i class="fas fa-exchange-alt"></i>
                            <p>No transactions found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction):
                                        $transaction_date = isset($transaction['created_at']) ? $transaction['created_at'] : (isset($transaction['payment_date']) ? $transaction['payment_date'] : '');
                                        $transaction_amount = isset($transaction['amount']) ? $transaction['amount'] : 0;
                                        $transaction_status = isset($transaction['status']) ? $transaction['status'] : 'completed';
                                        $transaction_type = isset($transaction['type_label']) ? $transaction['type_label'] : 'Payment';
                                        $receipt_url = isset($transaction['receipt_url']) ? $transaction['receipt_url'] : '';
                                        $transaction_id = isset($transaction['id']) ? $transaction['id'] : 0;
                                        $course_title = isset($transaction['course_title']) ? $transaction['course_title'] : (isset($transaction['program_name']) ? $transaction['program_name'] : '');
                                    ?>
                                        <tr>
                                            <td><?php echo !empty($transaction_date) ? date('M d, Y', strtotime($transaction_date)) : 'N/A'; ?></td>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($transaction_type); ?></div>
                                                <?php if (!empty($course_title)): ?>
                                                    <div style="font-size: 0.75rem; color: var(--gray);">
                                                        <?php echo htmlspecialchars($course_title); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="font-weight: 700; color: var(--dark);">
                                                    ₦<?php echo number_format($transaction_amount, 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($transaction_status == 'completed'): ?>
                                                    <span class="status-badge status-paid">Completed</span>
                                                <?php elseif ($transaction_status == 'pending'): ?>
                                                    <span class="status-badge status-pending">Pending</span>
                                                <?php elseif ($transaction_status == 'failed'): ?>
                                                    <span class="status-badge status-overdue">Failed</span>
                                                <?php else: ?>
                                                    <span class="status-badge"><?php echo ucfirst($transaction_status); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($transaction_id > 0): ?>
                                                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/view.php?id=<?php echo $transaction_id; ?>"
                                                        class="btn btn-sm" style="background: #f1f5f9; color: var(--dark); padding: 0.25rem 0.5rem;">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($receipt_url)): ?>
                                                    <a href="<?php echo htmlspecialchars($receipt_url); ?>"
                                                        target="_blank" class="btn btn-sm btn-success" style="padding: 0.25rem 0.5rem;">
                                                        <i class="fas fa-receipt"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Program Fee Details -->
                <?php if (!empty($enrolled_classes)): ?>
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">Program Fee Details</h2>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Program/Class</th>
                                        <th>Registration Fee</th>
                                        <th>Course Fee</th>
                                        <th>Total Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_classes as $class):
                                        $program_fee = floatval($class['program_fee'] ?? 0);
                                        $registration_fee = floatval($class['registration_fee'] ?? 0);
                                        $total_fee = $program_fee + $registration_fee;
                                        $paid_amount = floatval($class['paid_amount'] ?? 0);
                                        $balance = max(0, $total_fee - $paid_amount);
                                        $is_registration_paid = !empty($class['registration_paid']);
                                        $is_cleared = !empty($class['is_cleared']);
                                        $is_suspended = !empty($class['is_suspended']);
                                        $next_payment_due = $class['next_payment_due'] ?? null;
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($class['program_name']); ?></div>
                                                <div style="font-size: 0.75rem; color: var(--gray);">
                                                    <?php echo htmlspecialchars($class['course_title']); ?> • <?php echo htmlspecialchars($class['batch_code']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="<?php echo $is_registration_paid ? 'financial-value paid' : 'financial-value due'; ?>">
                                                    ₦<?php echo number_format($registration_fee, 2); ?>
                                                </span>
                                            </td>
                                            <td>₦<?php echo number_format($program_fee, 2); ?></td>
                                            <td>₦<?php echo number_format($paid_amount, 2); ?></td>
                                            <td>
                                                <span style="font-weight: 700; color: <?php echo $balance > 0 ? 'var(--warning)' : 'var(--success)'; ?>">
                                                    ₦<?php echo number_format($balance, 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($is_suspended): ?>
                                                    <span class="status-badge status-overdue">Suspended</span>
                                                <?php elseif ($is_cleared): ?>
                                                    <span class="status-badge status-paid">Cleared</span>
                                                <?php else: ?>
                                                    <?php if ($balance > 0): ?>
                                                        <?php if ($next_payment_due && strtotime($next_payment_due) < time()): ?>
                                                            <span class="status-badge status-overdue">Overdue</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-pending">Balance Due</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="status-badge status-paid">Paid</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Quick Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Quick Actions</h2>
                    </div>

                    <div class="quick-actions">
                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <div class="quick-action-label">Make Payment</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                            <div class="quick-action-label">View Invoices</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/history.php" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="quick-action-label">Payment History</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/student/finance/fees/" class="quick-action">
                            <div class="quick-action-icon">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="quick-action-label">Fee Structure</div>
                        </a>
                    </div>

                    <?php if ($financial_overview['balance'] > 0): ?>
                        <div style="margin-top: 1.5rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-success" style="width: 100%;">
                                <i class="fas fa-credit-card"></i> Pay Balance Now
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Overdue Invoices -->
                <?php if (!empty($overdue_invoices)): ?>
                    <div class="content-card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h2 class="card-title">Overdue Invoices</h2>
                            <span class="status-badge status-overdue"><?php echo count($overdue_invoices); ?></span>
                        </div>

                        <?php foreach ($overdue_invoices as $invoice):
                            $invoice_balance = floatval($invoice['amount']) - floatval($invoice['paid_amount'] ?? 0);
                            $invoice_type = $invoice['invoice_type'] == 'registration' ? 'Registration Fee' : 'Course Fee';
                        ?>
                            <div class="invoice-item overdue">
                                <div class="invoice-info">
                                    <h4><?php echo htmlspecialchars($invoice['course_title']); ?></h4>
                                    <p><?php echo htmlspecialchars($invoice['batch_code']); ?> • <?php echo htmlspecialchars($invoice_type); ?></p>
                                    <div style="font-size: 0.75rem; color: var(--danger); margin-top: 0.25rem;">
                                        <i class="fas fa-clock"></i> <?php echo $invoice['days_overdue']; ?> days overdue
                                    </div>
                                </div>
                                <div class="invoice-amount">
                                    <div class="amount">₦<?php echo number_format($invoice_balance, 2); ?></div>
                                    <div class="due-date">Was due <?php echo date('M d', strtotime($invoice['due_date'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/?filter=overdue" class="btn btn-danger btn-sm" style="width: 100%;">
                                <i class="fas fa-exclamation-circle"></i> View All Overdue
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Upcoming Payments -->
                <?php if (!empty($pending_invoices)): ?>
                    <div class="content-card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h2 class="card-title">Upcoming Payments</h2>
                            <span class="status-badge status-pending"><?php echo count($pending_invoices); ?></span>
                        </div>

                        <?php foreach ($pending_invoices as $invoice):
                            $invoice_balance = floatval($invoice['amount']) - floatval($invoice['paid_amount'] ?? 0);
                            $invoice_type = $invoice['invoice_type'] == 'registration' ? 'Registration Fee' : 'Course Fee';
                            $days_left = $invoice['days_left'] ?? 0;
                        ?>
                            <div class="invoice-item">
                                <div class="invoice-info">
                                    <h4><?php echo htmlspecialchars($invoice['course_title']); ?></h4>
                                    <p><?php echo htmlspecialchars($invoice['batch_code']); ?> • <?php echo htmlspecialchars($invoice_type); ?></p>
                                </div>
                                <div class="invoice-amount">
                                    <div class="amount">₦<?php echo number_format($invoice_balance, 2); ?></div>
                                    <div class="due-date <?php echo $days_left <= 3 ? 'overdue' : ''; ?>">
                                        <?php if ($days_left == 0): ?>
                                            Due today
                                        <?php elseif ($days_left == 1): ?>
                                            Due tomorrow
                                        <?php elseif ($days_left > 0): ?>
                                            Due in <?php echo $days_left; ?> days
                                        <?php else: ?>
                                            Overdue
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/invoices/" class="btn btn-warning btn-sm" style="width: 100%;">
                                <i class="fas fa-calendar-check"></i> View All Upcoming
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment Plans - UPDATED for new system -->
                <?php if (!empty($payment_plans)): ?>
                    <div class="content-card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h2 class="card-title">Payment Plans</h2>
                        </div>

                        <?php foreach ($payment_plans as $plan): ?>
                            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($plan['program_name']); ?></h3>
                                <div style="font-size: 0.75rem; color: var(--gray); margin-bottom: 0.5rem;"><?php echo ucfirst($plan['plan_name']); ?></div>
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.75rem;">
                                    <div>Registration Fee:</div>
                                    <div style="text-align: right; font-weight: 600;">₦<?php echo number_format($plan['registration_fee'] ?? 0, 2); ?></div>
                                    <div>Course Fee:</div>
                                    <div style="text-align: right; font-weight: 600;">₦<?php echo number_format($plan['course_fee'] ?? 0, 2); ?></div>
                                    <?php if ($plan['late_fee_percentage'] > 0): ?>
                                        <div>Late Fee:</div>
                                        <div style="text-align: right; color: var(--danger);"><?php echo $plan['late_fee_percentage']; ?>% after due date</div>
                                    <?php endif; ?>
                                    <?php if ($plan['suspension_days'] > 0): ?>
                                        <div>Suspension:</div>
                                        <div style="text-align: right; color: var(--warning);">After <?php echo $plan['suspension_days']; ?> days overdue</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Fee Waivers -->
                <?php if (!empty($waivers)): ?>
                    <div class="content-card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h2 class="card-title">Approved Waivers</h2>
                            <span class="status-badge status-paid"><?php echo count($waivers); ?></span>
                        </div>

                        <?php foreach ($waivers as $waiver): ?>
                            <div style="background: rgba(76, 201, 240, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 3px solid var(--success);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 600; font-size: 0.875rem;"><?php echo htmlspecialchars($waiver['program_name'] ?? $waiver['fee_structure_name']); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--success); font-weight: 600;">
                                        <?php if ($waiver['waiver_type'] == 'full'): ?>
                                            Full Waiver
                                        <?php elseif ($waiver['waiver_type'] == 'percentage'): ?>
                                            <?php echo $waiver['waiver_value']; ?>% Off
                                        <?php else: ?>
                                            ₦<?php echo number_format($waiver['waiver_value'], 2); ?> Off
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--gray); margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($waiver['reason']); ?>
                                </div>
                                <?php if ($waiver['expiry_date']): ?>
                                    <div style="font-size: 0.7rem; color: var(--gray);">
                                        <i class="fas fa-calendar"></i> Expires: <?php echo date('M d, Y', strtotime($waiver['expiry_date'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 1rem;">
                            <a href="<?php echo BASE_URL; ?>modules/student/finance/requests/waiver.php" class="btn btn-success btn-sm" style="width: 100%;">
                                <i class="fas fa-hand-holding-usd"></i> Request New Waiver
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Pay Section - UPDATED for new system -->
        <div class="content-card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h2 class="card-title">Quick Payment</h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <?php if ($financial_overview['registration_fee'] > $financial_overview['registration_paid']): ?>
                    <button class="btn btn-warning" onclick="quickPay(<?php echo $financial_overview['registration_fee'] - $financial_overview['registration_paid']; ?>, 'registration')" style="width: 100%;">
                        <i class="fas fa-user-plus"></i>
                        Pay Registration: ₦<?php echo number_format($financial_overview['registration_fee'] - $financial_overview['registration_paid'], 2); ?>
                    </button>
                <?php endif; ?>
                <?php if ($financial_overview['course_fee_balance'] > 0): ?>
                    <button class="btn btn-primary" onclick="quickPay(<?php echo $financial_overview['course_fee_balance']; ?>, 'course')" style="width: 100%;">
                        <i class="fas fa-graduation-cap"></i>
                        Pay Course Fee: ₦<?php echo number_format($financial_overview['course_fee_balance'], 2); ?>
                    </button>
                <?php endif; ?>
                <?php if ($financial_overview['penalties'] > 0): ?>
                    <button class="btn btn-danger" onclick="quickPay(<?php echo $financial_overview['penalties']; ?>, 'penalty')" style="width: 100%;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Pay Penalties: ₦<?php echo number_format($financial_overview['penalties'], 2); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export & Reports -->
        <div class="content-card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h2 class="card-title">Reports & Statements</h2>
            </div>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export.php?type=statement&student_id=<?php echo $user_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-file-pdf"></i> Download Statement
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export.php?type=transactions&student_id=<?php echo $user_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-file-excel"></i> Export Transactions
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/reports/export.php?type=receipts&student_id=<?php echo $user_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-receipt"></i> All Receipts
                </a>
                <a href="<?php echo BASE_URL; ?>modules/student/finance/status/" class="btn btn-info">
                    <i class="fas fa-clipboard-check"></i> Clearance Status
                </a>
            </div>
        </div>
    </div>

    <script>
        // Quick pay function
        function quickPay(amount, type) {
            if (confirm(`Make payment of ₦${amount.toLocaleString()} for ${type}?`)) {
                window.location.href = `<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php?amount=${amount}&type=${type}`;
            }
        }

        // Auto-refresh financial data every 5 minutes
        setInterval(() => {
            fetch(`<?php echo BASE_URL; ?>modules/student/finance/ajax/refresh_finance.php?student_id=<?php echo $user_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateFinancialStats(data);
                    }
                })
                .catch(error => console.error('Error refreshing financial data:', error));
        }, 5 * 60 * 1000);

        function updateFinancialStats(data) {
            // Update total balance
            const balanceElement = document.querySelector('.stat-card .stat-value');
            if (balanceElement && data.balance !== undefined) {
                balanceElement.textContent = '₦' + data.balance.toLocaleString('en-US', {
                    minimumFractionDigits: 2
                });
            }

            // Update paid amount
            const paidElement = document.querySelector('.stat-card.success .stat-value');
            if (paidElement && data.paid_amount !== undefined) {
                paidElement.textContent = '₦' + data.paid_amount.toLocaleString('en-US', {
                    minimumFractionDigits: 2
                });
            }

            // Update progress bar
            const progressBar = document.querySelector('.progress-fill');
            if (progressBar && data.payment_progress !== undefined) {
                progressBar.style.width = data.payment_progress + '%';
            }

            // Update overdue notice if applicable
            if (data.overdue_balance > 0) {
                const alertContainer = document.querySelector('.alert-banner.alert-danger');
                if (!alertContainer) {
                    createOverdueAlert(data.overdue_balance);
                }
            }
        }

        function createOverdueAlert(amount) {
            const alertHTML = `
                <div class="alert-banner alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div style="flex: 1;">
                        <strong>Overdue Payments Detected!</strong> 
                        You have ₦${amount.toLocaleString('en-US', { minimumFractionDigits: 2 })} in overdue payments. 
                        Please make payment immediately to avoid suspension.
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php" class="btn btn-danger">
                        Pay Now
                    </a>
                </div>
            `;

            const container = document.querySelector('.container');
            const firstElement = container.firstElementChild;
            if (firstElement) {
                firstElement.insertAdjacentHTML('afterend', alertHTML);
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + M for make payment
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/payments/make_payment.php';
            }

            // Ctrl + I for invoices
            if (e.ctrlKey && e.key === 'i') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/invoices/';
            }

            // Ctrl + H for history
            if (e.ctrlKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>modules/student/finance/payments/';
            }
        });

        // Print financial statement
        function printStatement() {
            window.open(`<?php echo BASE_URL; ?>modules/student/finance/reports/print.php?student_id=<?php echo $user_id; ?>`, '_blank');
        }

        // Initialize tooltips
        document.querySelectorAll('[data-tooltip]').forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.getAttribute('data-tooltip');
                document.body.appendChild(tooltip);

                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
                tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';

                this._tooltip = tooltip;
            });

            element.addEventListener('mouseleave', function(e) {
                if (this._tooltip) {
                    this._tooltip.remove();
                    delete this._tooltip;
                }
            });
        });
    </script>
</body>

</html>