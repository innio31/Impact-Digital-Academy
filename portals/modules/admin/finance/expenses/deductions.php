<?php
// modules/admin/finance/expenses/deductions.php

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

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'create' || $action === 'update') {
            $deduction_type = $_POST['deduction_type'] ?? '';
            $percentage = floatval($_POST['percentage'] ?? 0);
            $description = $_POST['description'] ?? '';
            $destination_account = $_POST['destination_account'] ?? '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $auto_generate = isset($_POST['auto_generate']) ? 1 : 0;
            
            // Validate percentage
            if ($percentage < 0 || $percentage > 100) {
                $message = 'Percentage must be between 0 and 100';
                $message_type = 'danger';
            } else {
                if ($action === 'create') {
                    // Create new deduction
                    $stmt = $conn->prepare("INSERT INTO automated_deductions (deduction_type, percentage, description, destination_account, is_active, auto_generate) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sdssii", $deduction_type, $percentage, $description, $destination_account, $is_active, $auto_generate);
                    
                    if ($stmt->execute()) {
                        $message = 'Automated deduction created successfully!';
                        $message_type = 'success';
                        logActivity($_SESSION['user_id'], 'create_deduction', "Created automated deduction: $deduction_type ($percentage%)");
                    } else {
                        $message = 'Error creating deduction: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                } else {
                    // Update existing deduction
                    $id = intval($_POST['id'] ?? 0);
                    $stmt = $conn->prepare("UPDATE automated_deductions SET deduction_type = ?, percentage = ?, description = ?, destination_account = ?, is_active = ?, auto_generate = ? WHERE id = ?");
                    $stmt->bind_param("sdssiii", $deduction_type, $percentage, $description, $destination_account, $is_active, $auto_generate, $id);
                    
                    if ($stmt->execute()) {
                        $message = 'Automated deduction updated successfully!';
                        $message_type = 'success';
                        logActivity($_SESSION['user_id'], 'update_deduction', "Updated automated deduction ID: $id");
                    } else {
                        $message = 'Error updating deduction: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            
            // First, check if there are any related expenses
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE description LIKE CONCAT('%', (SELECT deduction_type FROM automated_deductions WHERE id = ?), '%')");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $data = $result->fetch_assoc();
            
            if ($data['count'] > 0) {
                $message = 'Cannot delete deduction that has related expenses. Deactivate it instead.';
                $message_type = 'warning';
            } else {
                $stmt = $conn->prepare("DELETE FROM automated_deductions WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $message = 'Automated deduction deleted successfully!';
                    $message_type = 'success';
                    logActivity($_SESSION['user_id'], 'delete_deduction', "Deleted automated deduction ID: $id");
                } else {
                    $message = 'Error deleting deduction: ' . $stmt->error;
                    $message_type = 'danger';
                }
            }
        } elseif ($action === 'calculate') {
            // Calculate deductions now
            $id = intval($_POST['id'] ?? 0);
            $period = $_POST['period'] ?? 'month';
            
            // Set date range based on period
            $date_from = date('Y-m-d');
            $date_to = date('Y-m-d');
            
            if ($period === 'week') {
                $date_from = date('Y-m-d', strtotime('-7 days'));
            } elseif ($period === 'month') {
                $date_from = date('Y-m-d', strtotime('-30 days'));
            } elseif ($period === 'year') {
                $date_from = date('Y-m-d', strtotime('-365 days'));
            }
            
            $created_expenses = calculateAutomatedDeductions($period, $date_from, $date_to);
            $message = count($created_expenses) > 0 ? 
                'Automated deductions calculated successfully!' : 
                'No new deductions to calculate for this period.';
            $message_type = 'success';
        }
    }
}

// Get all automated deductions
$deductions_sql = "SELECT * FROM automated_deductions ORDER BY deduction_type, percentage DESC";
$deductions_result = $conn->query($deductions_sql);
$deductions = $deductions_result->fetch_all(MYSQLI_ASSOC);

// Get total revenue for this month for context
$revenue_sql = "SELECT COALESCE(SUM(amount), 0) as total_revenue 
                FROM financial_transactions 
                WHERE status = 'completed' 
                  AND transaction_type IN ('registration', 'tuition')
                  AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                  AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$revenue_result = $conn->query($revenue_sql);
$revenue_data = $revenue_result->fetch_assoc();
$current_month_revenue = $revenue_data['total_revenue'] ?? 0;

// Log activity
logActivity($_SESSION['user_id'], 'access_deductions', "Accessed automated deductions configuration");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automated Deductions - Admin Portal</title>
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
            --dark-light: #334155;
            --sidebar: #1e293b;
            --tithe: #10b981;
            --reserve: #3b82f6;
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
            overflow-x: hidden;
        }

        /* Mobile-first approach */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Header - Fixed at top */
        .mobile-header {
            background: var(--sidebar);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 60px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .mobile-header h1 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            flex: 1;
            justify-content: center;
        }

        .mobile-header .mobile-menu-btn {
            margin-right: auto;
        }

        /* Sidebar - Hidden on mobile by default */
        .sidebar {
            width: 280px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            top: 60px;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            display: none;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .main-content {
            flex: 1;
            padding: 1rem;
            width: 100%;
            margin-top: 60px;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid var(--dark-light);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
            margin-bottom: 0.5rem;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .nav-section {
            padding: 0.5rem 1.5rem;
            color: #94a3b8;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 1rem;
            border-top: 1px solid var(--dark-light);
            padding-top: 1rem;
        }

        /* Main Header */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h2 {
            color: var(--dark);
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
            min-height: 48px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .form-check-input {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            text-decoration: none;
            text-align: center;
            min-height: 48px;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            min-height: 40px;
        }

        .btn-block {
            width: 100%;
            display: block;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: -1rem;
            padding: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            vertical-align: middle;
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

        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f1f5f9; color: #64748b; }

        .type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-tithe { background: #d1fae5; color: #065f46; }
        .type-reserve { background: #dbeafe; color: #1e40af; }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-color: #a7f3d0;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-color: #bfdbfe;
        }

        .alert i {
            font-size: 1.25rem;
            margin-top: 0.125rem;
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--info);
        }

        .info-card h3 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card p {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
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
            z-index: 1100;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--dark);
            font-size: 1.25rem;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .modal-close:hover {
            background: #f1f5f9;
        }

        .modal-body {
            padding: 1.25rem;
        }

        .modal-footer {
            padding: 1.25rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Desktop Styles */
        @media (min-width: 768px) {
            .mobile-header {
                display: none;
            }

            .sidebar {
                width: 250px;
                top: 0;
                transform: translateX(0);
                position: fixed;
                height: 100vh;
            }

            .sidebar-overlay {
                display: none !important;
            }

            .main-content {
                margin-left: 250px;
                margin-top: 0;
                padding: 2rem;
                width: calc(100% - 250px);
            }

            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }

            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }

            .modal-content {
                max-width: 600px;
            }
        }

        @media (min-width: 1024px) {
            .main-content {
                padding: 2.5rem;
            }
        }

        /* Extra Small Devices */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                padding: 1rem;
            }

            .card-header, .card-body {
                padding: 1rem;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }
        }

        /* Touch-friendly improvements */
        .btn, .form-control, select, input[type="checkbox"] {
            -webkit-tap-highlight-color: transparent;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle navigation menu">
                <i class="fas fa-bars"></i>
            </button>
            <h1>
                <i class="fas fa-cog"></i>
                Automated Deductions
            </h1>
        </div>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Expense Management</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/dashboard.php">
                            <i class="fas fa-money-bill-wave"></i> Expense Dashboard</a></li>

                    <div class="nav-section">Expense Management</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php">
                            <i class="fas fa-list"></i> Manage Expenses</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php">
                            <i class="fas fa-plus-circle"></i> Add Expense</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php">
                            <i class="fas fa-tags"></i> Categories</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php">
                            <i class="fas fa-chart-pie"></i> Budgets</a></li>

                    <div class="nav-section">Automated Deductions</div>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php" class="active">
                            <i class="fas fa-cog"></i> Configure Deductions</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/reports.php">
                            <i class="fas fa-file-alt"></i> Expense Reports</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-cog"></i>
                    Automated Deductions Configuration
                </h1>
                <button class="btn btn-primary" id="addDeductionBtn">
                    <i class="fas fa-plus"></i> Add New Deduction
                </button>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Info Cards -->
            <div class="info-grid">
                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> How Automated Deductions Work</h3>
                    <p>Automated deductions automatically create expense records based on a percentage of total revenue. 
                    They're calculated periodically and help ensure consistent financial management.</p>
                </div>

                <div class="info-card">
                    <h3><i class="fas fa-calculator"></i> Current Month Context</h3>
                    <p>Total revenue this month: <strong><?php echo formatCurrency($current_month_revenue); ?></strong><br>
                    This is the base amount deductions will be calculated from.</p>
                </div>
            </div>

            <!-- Deductions List -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Configured Deductions</h2>
                    <span class="status-badge status-active"><?php echo count($deductions); ?> Active Rules</span>
                </div>
                <div class="card-body">
                    <?php if (empty($deductions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-cog"></i>
                            <h3>No Automated Deductions</h3>
                            <p>Get started by creating your first automated deduction rule.</p>
                            <button class="btn btn-primary mt-3" id="addFirstDeductionBtn">
                                <i class="fas fa-plus"></i> Create First Deduction
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Percentage</th>
                                        <th>Description</th>
                                        <th>Destination</th>
                                        <th>Status</th>
                                        <th>Auto Generate</th>
                                        <th>Last Calculated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deductions as $deduction): 
                                        $expected_amount = ($current_month_revenue * $deduction['percentage']) / 100;
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="type-badge type-<?php echo $deduction['deduction_type']; ?>">
                                                    <?php echo ucfirst($deduction['deduction_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong style="font-size: 1.1rem;"><?php echo $deduction['percentage']; ?>%</strong><br>
                                                <small style="color: #64748b; font-size: 0.85rem;">
                                                    ≈ <?php echo formatCurrency($expected_amount); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($deduction['description']); ?></td>
                                            <td><?php echo htmlspecialchars($deduction['destination_account']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $deduction['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $deduction['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $deduction['auto_generate'] ? 
                                                    '<i class="fas fa-check text-success"></i> Yes' : 
                                                    '<i class="fas fa-times text-muted"></i> No'; ?>
                                            </td>
                                            <td>
                                                <?php echo $deduction['last_calculated_date'] ? 
                                                    date('M j, Y', strtotime($deduction['last_calculated_date'])) : 
                                                    '<span style="color: #64748b;">Never</span>'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info calculate-btn" 
                                                            data-id="<?php echo $deduction['id']; ?>"
                                                            data-type="<?php echo $deduction['deduction_type']; ?>">
                                                        <i class="fas fa-calculator"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-primary edit-btn" 
                                                            data-id="<?php echo $deduction['id']; ?>"
                                                            data-type="<?php echo $deduction['deduction_type']; ?>"
                                                            data-percentage="<?php echo $deduction['percentage']; ?>"
                                                            data-description="<?php echo htmlspecialchars($deduction['description']); ?>"
                                                            data-destination="<?php echo htmlspecialchars($deduction['destination_account']); ?>"
                                                            data-active="<?php echo $deduction['is_active']; ?>"
                                                            data-auto="<?php echo $deduction['auto_generate']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger delete-btn" 
                                                            data-id="<?php echo $deduction['id']; ?>"
                                                            data-type="<?php echo $deduction['deduction_type']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Deduction Modal -->
    <div class="modal" id="deductionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Deduction</h3>
                <button class="modal-close" id="closeModal">&times;</button>
            </div>
            <form method="POST" id="deductionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="deductionId" value="">
                    
                    <div class="form-group">
                        <label for="deduction_type">Deduction Type *</label>
                        <select name="deduction_type" id="deduction_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="tithe">Tithe (e.g., 10%)</option>
                            <option value="reserve">Reserve Fund (e.g., 30%)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="percentage">Percentage (%) *</label>
                        <input type="number" name="percentage" id="percentage" class="form-control" 
                               min="0" max="100" step="0.01" required placeholder="e.g., 10.00">
                        <small style="color: #64748b; font-size: 0.85rem;">
                            Percentage of total revenue to deduct
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="description">Description *</label>
                        <input type="text" name="description" id="description" class="form-control" 
                               required placeholder="e.g., Monthly tithe deduction">
                    </div>

                    <div class="form-group">
                        <label for="destination_account">Destination Account</label>
                        <input type="text" name="destination_account" id="destination_account" class="form-control" 
                               placeholder="e.g., Tithe Account - Bank Name">
                    </div>

                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                            <label for="is_active" class="form-check-label">Active</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="auto_generate" id="auto_generate" class="form-check-input" checked>
                            <label for="auto_generate" class="form-check-label">Auto-generate expense records</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Deduction</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Calculate Modal -->
    <div class="modal" id="calculateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="calculateTitle">Calculate Deduction</h3>
                <button class="modal-close" id="closeCalculateModal">&times;</button>
            </div>
            <form method="POST" id="calculateForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="calculate">
                    <input type="hidden" name="id" id="calculateId" value="">
                    
                    <p>You're about to calculate deductions for: <strong id="deductionTypeLabel"></strong></p>
                    
                    <div class="form-group">
                        <label for="period">Time Period for Calculation</label>
                        <select name="period" id="period" class="form-control" required>
                            <option value="today">Today</option>
                            <option value="week">Last 7 Days</option>
                            <option value="month" selected>Last 30 Days</option>
                            <option value="year">Last Year</option>
                        </select>
                        <small style="color: #64748b; font-size: 0.85rem;">
                            Select the period to calculate deductions for
                        </small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        This will create expense records for the selected period based on revenue.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelCalculate">Cancel</button>
                    <button type="submit" class="btn btn-primary">Calculate Now</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
                <button class="modal-close" id="closeDeleteModal">&times;</button>
            </div>
            <form method="POST" id="deleteForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId" value="">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Are you sure you want to delete the deduction "<strong id="deleteTypeLabel"></strong>"?</p>
                        <p class="mt-2"><strong>Warning:</strong> This action cannot be undone. If this deduction has related expenses, consider deactivating it instead.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Permanently</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }
        
        mobileMenuBtn.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);
        
        // Close sidebar when clicking a link on mobile
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    toggleSidebar();
                }
            });
        });
        
        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                toggleSidebar();
            }
        });
        
        // Modal functionality
        const deductionModal = document.getElementById('deductionModal');
        const calculateModal = document.getElementById('calculateModal');
        const deleteModal = document.getElementById('deleteModal');
        
        // Add deduction button
        document.getElementById('addDeductionBtn').addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Add New Deduction';
            document.getElementById('formAction').value = 'create';
            document.getElementById('deductionId').value = '';
            document.getElementById('deduction_type').value = '';
            document.getElementById('percentage').value = '';
            document.getElementById('description').value = '';
            document.getElementById('destination_account').value = '';
            document.getElementById('is_active').checked = true;
            document.getElementById('auto_generate').checked = true;
            document.getElementById('saveBtn').textContent = 'Save Deduction';
            deductionModal.classList.add('active');
        });
        
        // Add first deduction button
        const addFirstBtn = document.getElementById('addFirstDeductionBtn');
        if (addFirstBtn) {
            addFirstBtn.addEventListener('click', () => {
                document.getElementById('addDeductionBtn').click();
            });
        }
        
        // Edit deduction buttons
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('modalTitle').textContent = 'Edit Deduction';
                document.getElementById('formAction').value = 'update';
                document.getElementById('deductionId').value = btn.dataset.id;
                document.getElementById('deduction_type').value = btn.dataset.type;
                document.getElementById('percentage').value = btn.dataset.percentage;
                document.getElementById('description').value = btn.dataset.description;
                document.getElementById('destination_account').value = btn.dataset.destination;
                document.getElementById('is_active').checked = btn.dataset.active === '1';
                document.getElementById('auto_generate').checked = btn.dataset.auto === '1';
                document.getElementById('saveBtn').textContent = 'Update Deduction';
                deductionModal.classList.add('active');
            });
        });
        
        // Calculate deduction buttons
        document.querySelectorAll('.calculate-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('calculateId').value = btn.dataset.id;
                document.getElementById('deductionTypeLabel').textContent = btn.dataset.type;
                calculateModal.classList.add('active');
            });
        });
        
        // Delete deduction buttons
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('deleteId').value = btn.dataset.id;
                document.getElementById('deleteTypeLabel').textContent = btn.dataset.type;
                deleteModal.classList.add('active');
            });
        });
        
        // Close modals
        document.getElementById('closeModal').addEventListener('click', () => {
            deductionModal.classList.remove('active');
        });
        
        document.getElementById('closeCalculateModal').addEventListener('click', () => {
            calculateModal.classList.remove('active');
        });
        
        document.getElementById('closeDeleteModal').addEventListener('click', () => {
            deleteModal.classList.remove('active');
        });
        
        document.getElementById('cancelModal').addEventListener('click', () => {
            deductionModal.classList.remove('active');
        });
        
        document.getElementById('cancelCalculate').addEventListener('click', () => {
            calculateModal.classList.remove('active');
        });
        
        document.getElementById('cancelDelete').addEventListener('click', () => {
            deleteModal.classList.remove('active');
        });
        
        // Close modals when clicking outside
        [deductionModal, calculateModal, deleteModal].forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
        
        // Close modals with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                deductionModal.classList.remove('active');
                calculateModal.classList.remove('active');
                deleteModal.classList.remove('active');
            }
        });
        
        // Form validation for percentage
        document.getElementById('percentage').addEventListener('change', function() {
            if (this.value < 0) this.value = 0;
            if (this.value > 100) this.value = 100;
        });
        
        // Touch-friendly improvements
        document.querySelectorAll('.btn, .form-control, select, input[type="checkbox"]').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            
            element.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
        
        // Prevent form submission if invalid
        document.getElementById('deductionForm').addEventListener('submit', function(e) {
            const percentage = document.getElementById('percentage').value;
            if (percentage < 0 || percentage > 100) {
                e.preventDefault();
                alert('Percentage must be between 0 and 100');
            }
        });
        
        // Adjust table padding on mobile
        function adjustTablePadding() {
            const tableContainers = document.querySelectorAll('.table-responsive');
            const isMobile = window.innerWidth < 768;
            
            tableContainers.forEach(container => {
                if (isMobile) {
                    container.style.padding = '0.5rem';
                    container.style.margin = '-0.5rem';
                } else {
                    container.style.padding = '1rem';
                    container.style.margin = '-1rem';
                }
            });
        }
        
        adjustTablePadding();
        window.addEventListener('resize', adjustTablePadding);
    </script>
</body>
</html>
<?php $conn->close(); ?>