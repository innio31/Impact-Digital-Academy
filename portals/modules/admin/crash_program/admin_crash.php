<?php
// modules/admin/crash_program/admin_crash.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get settings
$settings_sql = "SELECT setting_key, setting_value FROM crash_program_settings";
$settings_result = $conn->query($settings_sql);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submissions
$message = '';
$error = '';

// Update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $settings_to_update = [
            'total_spots',
            'program_fee',
            'payment_deadline_days',
            'program_start_date',
            'program_end_date',
            'admin_whatsapp',
            'bank_name',
            'account_name',
            'account_number'
        ];

        foreach ($settings_to_update as $setting_key) {
            $post_key = 'setting_' . $setting_key;
            if (isset($_POST[$post_key])) {
                $update_sql = "UPDATE crash_program_settings SET setting_value = ? WHERE setting_key = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('ss', $_POST[$post_key], $setting_key);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
        $message = 'Settings updated successfully.';

        // Refresh settings
        $settings_result = $conn->query($settings_sql);
        $settings = [];
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
}

// Confirm payment manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $registration_id = intval($_POST['registration_id']);

        // Update registration
        $update_sql = "UPDATE crash_program_registrations 
                      SET payment_status = 'confirmed', 
                          status = 'payment_confirmed',
                          payment_confirmed_at = NOW()
                      WHERE id = ? AND payment_status = 'pending'";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $registration_id);

        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
            // Get registration details to send email
            $reg_sql = "SELECT * FROM crash_program_registrations WHERE id = ?";
            $reg_stmt = $conn->prepare($reg_sql);
            $reg_stmt->bind_param('i', $registration_id);
            $reg_stmt->execute();
            $registration = $reg_stmt->get_result()->fetch_assoc();
            $reg_stmt->close();

            // Send payment confirmation email if function exists
            if (function_exists('sendCrashProgramPaymentConfirmationEmail')) {
                sendCrashProgramPaymentConfirmationEmail($registration);
            }

            // Create user in main portal if not exists
            if (!$registration['portal_user_created'] && function_exists('createCrashProgramPortalUser')) {
                createCrashProgramPortalUser($registration, $conn);
            }

            $message = 'Payment confirmed for ' . htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']);

            // Log activity
            logActivity($_SESSION['user_id'], 'crash_payment_confirm', "Confirmed payment for #$registration_id");
        } elseif ($update_stmt->affected_rows == 0) {
            $error = 'Payment already confirmed or registration not found.';
        } else {
            $error = 'Failed to confirm payment.';
        }
        $update_stmt->close();
    }
}

// Send program details email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_program_details'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $registration_id = intval($_POST['registration_id']);

        // Get registration details
        $reg_sql = "SELECT * FROM crash_program_registrations WHERE id = ?";
        $reg_stmt = $conn->prepare($reg_sql);
        $reg_stmt->bind_param('i', $registration_id);
        $reg_stmt->execute();
        $registration = $reg_stmt->get_result()->fetch_assoc();
        $reg_stmt->close();

        if ($registration) {
            // Send program details email
            if (function_exists('sendCrashProgramDetailsEmail')) {
                sendCrashProgramDetailsEmail($registration);
            }

            $message = 'Program details sent to ' . htmlspecialchars($registration['email']);

            // Log activity
            logActivity($_SESSION['user_id'], 'crash_send_details', "Sent program details to #$registration_id");
        } else {
            $error = 'Registration not found.';
        }
    }
}

// Get all registrations with filters
$status_filter = $_GET['status'] ?? 'all';
$payment_filter = $_GET['payment'] ?? 'all';
$program_filter = $_GET['program'] ?? 'all';

$sql = "SELECT * FROM crash_program_registrations WHERE 1=1";
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($payment_filter !== 'all') {
    $sql .= " AND payment_status = ?";
    $params[] = $payment_filter;
    $types .= "s";
}

if ($program_filter !== 'all') {
    $sql .= " AND program_choice = ?";
    $params[] = $program_filter;
    $types .= "s";
}

$sql .= " ORDER BY registered_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_payment,
    SUM(CASE WHEN program_choice = 'web_development' THEN 1 ELSE 0 END) as web_dev,
    SUM(CASE WHEN program_choice = 'ai_faceless_video' THEN 1 ELSE 0 END) as ai_video,
    SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END) as status_pending,
    SUM(CASE WHEN status = 'payment_confirmed' THEN 1 ELSE 0 END) as status_confirmed
FROM crash_program_registrations";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$total_spots = intval($settings['total_spots'] ?? 50);
$confirmed_count = intval($stats['confirmed'] ?? 0);
$spots_left = $total_spots - $confirmed_count;

// Function to get program display name
function getProgramDisplay($program_choice)
{
    return $program_choice === 'web_development' ? 'Web Development' : 'AI Faceless Video Creation';
}

// Get pending payment registrations for reminder
$pending_sql = "SELECT * FROM crash_program_registrations 
                WHERE payment_status = 'pending' 
                AND registered_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)";
$pending_result = $conn->query($pending_sql);
$pending_registrations = $pending_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Crash Program Management - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: #f1f5f9;
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--dark);
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li a {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s;
            gap: 0.75rem;
        }

        .sidebar-nav li a:hover,
        .sidebar-nav li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .sidebar-nav li a i {
            width: 20px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        /* Header */
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .page-header h1 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray-500);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray-500);
            font-size: 0.85rem;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            background: white;
            font-size: 0.85rem;
            cursor: pointer;
        }

        /* Settings Form */
        .settings-form {
            padding: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        td {
            font-size: 0.9rem;
        }

        tr:hover {
            background: var(--gray-50);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pending_payment {
            background: #fef3c7;
            color: #92400e;
        }

        .status-payment_confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
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

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 4px solid var(--info);
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid var(--warning);
        }

        /* Pending Reminder */
        .pending-reminder {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left: 4px solid var(--warning);
        }

        .pending-list {
            margin-top: 0.75rem;
            padding-left: 1.5rem;
        }

        .pending-list li {
            margin-bottom: 0.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Program Icon */
        .program-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .program-icon i {
            font-size: 0.85rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-300);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filters {
                width: 100%;
            }

            .filter-select {
                flex: 1;
            }

            th,
            td {
                padding: 0.75rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-sm {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .card-header .filters,
            .action-buttons,
            .btn,
            .pending-reminder {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 0;
            }

            .stat-card {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Digital Academy</h2>
                <p>Crash Program Admin</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard
                        </a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/crash_program/admin_crash.php" class="active">
                            <i class="fas fa-rocket"></i> Crash Program
                        </a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications
                        </a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users
                        </a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-rocket"></i> Crash Program Management</h1>
                <p>2-Week Intensive Program: Web Development & AI Faceless Video Creation (April 13-24, 2026)</p>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($pending_registrations)): ?>
                <div class="alert alert-warning pending-reminder">
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>Pending Payment Reminder:</strong>
                        <?php echo count($pending_registrations); ?> registration(s) have been pending for more than 2 days.
                        <ul class="pending-list">
                            <?php foreach (array_slice($pending_registrations, 0, 5) as $pending): ?>
                                <li>
                                    <?php echo htmlspecialchars($pending['first_name'] . ' ' . $pending['last_name']); ?> -
                                    <?php echo htmlspecialchars($pending['email']); ?> -
                                    Registered: <?php echo date('M j, Y', strtotime($pending['registered_at'])); ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($pending_registrations) > 5): ?>
                                <li>... and <?php echo count($pending_registrations) - 5; ?> more</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Registrations</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo $stats['confirmed'] ?? 0; ?></div>
                    <div class="stat-label">Confirmed Payments</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo $stats['pending_payment'] ?? 0; ?></div>
                    <div class="stat-label">Pending Payment</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-number <?php echo $spots_left <= 10 ? 'text-warning' : ''; ?>">
                        <?php echo $spots_left; ?>
                    </div>
                    <div class="stat-label">Spots Remaining</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['web_dev'] ?? 0; ?></div>
                    <div class="stat-label">Web Development</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['ai_video'] ?? 0; ?></div>
                    <div class="stat-label">AI Video Creation</div>
                </div>
            </div>

            <!-- Settings Card -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cog"></i> Program Settings</h3>
                </div>
                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Total Spots</label>
                            <input type="number" name="setting_total_spots" class="form-control"
                                value="<?php echo htmlspecialchars($settings['total_spots'] ?? 50); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Program Fee (₦)</label>
                            <input type="number" name="setting_program_fee" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_fee'] ?? 10000); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Payment Deadline (days)</label>
                            <input type="number" name="setting_payment_deadline_days" class="form-control"
                                value="<?php echo htmlspecialchars($settings['payment_deadline_days'] ?? 3); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Program Start Date</label>
                            <input type="date" name="setting_program_start_date" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_start_date'] ?? '2026-04-13'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Program End Date</label>
                            <input type="date" name="setting_program_end_date" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_end_date'] ?? '2026-04-24'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fab fa-whatsapp"></i> Admin WhatsApp</label>
                            <input type="text" name="setting_admin_whatsapp" class="form-control"
                                value="<?php echo htmlspecialchars($settings['admin_whatsapp'] ?? '+2349051586024'); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name</label>
                            <input type="text" name="setting_bank_name" class="form-control"
                                value="<?php echo htmlspecialchars($settings['bank_name'] ?? 'MoniePoint Microfinance Bank'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Account Name</label>
                            <input type="text" name="setting_account_name" class="form-control"
                                value="<?php echo htmlspecialchars($settings['account_name'] ?? 'Impact Digital Academy'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account Number</label>
                            <input type="text" name="setting_account_number" class="form-control"
                                value="<?php echo htmlspecialchars($settings['account_number'] ?? '6658393500'); ?>" required>
                        </div>
                    </div>

                    <button type="submit" name="update_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>

            <!-- Registrations Card -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Registrations</h3>
                    <div class="filters">
                        <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending_payment" <?php echo $status_filter == 'pending_payment' ? 'selected' : ''; ?>>Pending Payment</option>
                                <option value="payment_confirmed" <?php echo $status_filter == 'payment_confirmed' ? 'selected' : ''; ?>>Payment Confirmed</option>
                            </select>
                            <select name="payment" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $payment_filter == 'all' ? 'selected' : ''; ?>>All Payment</option>
                                <option value="pending" <?php echo $payment_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $payment_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            </select>
                            <select name="program" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $program_filter == 'all' ? 'selected' : ''; ?>>All Programs</option>
                                <option value="web_development" <?php echo $program_filter == 'web_development' ? 'selected' : ''; ?>>Web Development</option>
                                <option value="ai_faceless_video" <?php echo $program_filter == 'ai_faceless_video' ? 'selected' : ''; ?>>AI Video Creation</option>
                            </select>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-sync-alt"></i> Reset
                            </a>
                        </form>
                    </div>
                </div>
                <div class="table-wrapper">
                    <?php if (!empty($registrations)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Program</th>
                                    <th>School</th>
                                    <th>Payment</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td>#<?php echo $reg['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']); ?></strong>
                                        </td>
                                        <td>
                                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($reg['email']); ?></div>
                                            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($reg['phone']); ?></div>
                                        </td>
                                        <td>
                                            <span class="program-icon">
                                                <?php if ($reg['program_choice'] == 'web_development'): ?>
                                                    <i class="fas fa-code" style="color: var(--primary);"></i> Web Development
                                                <?php else: ?>
                                                    <i class="fas fa-video" style="color: var(--warning);"></i> AI Faceless Video
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($reg['is_student']): ?>
                                                <div><?php echo htmlspecialchars($reg['school_name'] ?: 'N/A'); ?></div>
                                                <small><?php echo htmlspecialchars($reg['school_class'] ?: ''); ?></small>
                                            <?php else: ?>
                                                <span class="status-badge" style="background: var(--gray-100);">Not a student</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $reg['payment_status']; ?>">
                                                <?php echo $reg['payment_status'] == 'confirmed' ? '✓ Confirmed' : '⏳ Pending'; ?>
                                            </span>
                                            <?php if ($reg['transaction_reference']): ?>
                                                <div><small>Ref: <?php echo htmlspecialchars($reg['transaction_reference']); ?></small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($reg['registered_at'])); ?><br>
                                            <small><?php echo date('g:i A', strtotime($reg['registered_at'])); ?></small>
                                        </td>
                                        <td class="action-buttons">
                                            <?php if ($reg['payment_status'] == 'pending'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                    <button type="submit" name="confirm_payment" class="btn btn-success btn-sm"
                                                        onclick="return confirm('Confirm payment for <?php echo addslashes($reg['first_name'] . ' ' . $reg['last_name']); ?>?')">
                                                        <i class="fas fa-check"></i> Confirm
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <button type="submit" name="send_program_details" class="btn btn-primary btn-sm"
                                                    onclick="return confirm('Send program details to <?php echo addslashes($reg['email']); ?>?')">
                                                    <i class="fas fa-envelope"></i> Send Details
                                                </button>
                                            </form>

                                            <a href="mailto:<?php echo urlencode($reg['email']); ?>" class="btn btn-warning btn-sm">
                                                <i class="fas fa-paper-plane"></i> Email
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Registrations Found</h3>
                            <p>Try adjusting your filters or check back later.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Export -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-download"></i> Export Data</h3>
                </div>
                <div class="settings-form" style="padding-top: 0;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Export All Registrations</label>
                            <a href="<?php echo BASE_URL; ?>modules/admin/crash_program/export.php" class="btn btn-primary">
                                <i class="fas fa-file-excel"></i> Export to CSV
                            </a>
                        </div>
                        <div class="form-group">
                            <label>Export Confirmed Only</label>
                            <a href="<?php echo BASE_URL; ?>modules/admin/crash_program/export.php?status=confirmed" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Confirmed
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh pending reminder (optional)
        setTimeout(function() {
            location.reload();
        }, 300000); // Refresh every 5 minutes

        // Confirm actions
        function confirmAction(message) {
            return confirm(message);
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>