<?php
// modules/admin/finance/expenses/view.php

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

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . BASE_URL . 'modules/admin/finance/expenses/manage.php');
    exit();
}

$expense_id = intval($_GET['id']);
$conn = getDBConnection();

// Get expense details
$stmt = $conn->prepare("
    SELECT e.*, 
           ec.name as category_name, 
           ec.category_type, 
           ec.color_code,
           u.first_name as created_by_name, 
           u.last_name as created_by_lastname,
           a.first_name as approved_by_name, 
           a.last_name as approved_by_lastname,
           p.first_name as paid_by_name, 
           p.last_name as paid_by_lastname
    FROM expenses e
    JOIN expense_categories ec ON ec.id = e.category_id
    JOIN users u ON u.id = e.created_by
    LEFT JOIN users a ON a.id = e.approved_by
    LEFT JOIN users p ON p.id = e.paid_by
    WHERE e.id = ?
");
$stmt->bind_param('i', $expense_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ' . BASE_URL . 'modules/admin/finance/expenses/manage.php?error=expense_not_found');
    exit();
}

$expense = $result->fetch_assoc();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    switch ($action) {
        case 'approve':
            if ($expense['status'] === 'pending') {
                $update_stmt = $conn->prepare("
                    UPDATE expenses 
                    SET status = 'approved', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        notes = CONCAT(IFNULL(notes, ''), '\n\n[Approved] ', ?)
                    WHERE id = ?
                ");
                $update_stmt->bind_param('isi', $_SESSION['user_id'], $notes, $expense_id);
                if ($update_stmt->execute()) {
                    logActivity($_SESSION['user_id'], 'expense_approve', "Approved expense: " . $expense['expense_number']);
                    header('Location: view.php?id=' . $expense_id . '&success=approved');
                    exit();
                }
            }
            break;
            
        case 'mark_paid':
            if ($expense['status'] === 'approved') {
                $update_stmt = $conn->prepare("
                    UPDATE expenses 
                    SET status = 'paid', 
                        paid_by = ?, 
                        paid_at = NOW(),
                        notes = CONCAT(IFNULL(notes, ''), '\n\n[Marked Paid] ', ?)
                    WHERE id = ?
                ");
                $update_stmt->bind_param('isi', $_SESSION['user_id'], $notes, $expense_id);
                if ($update_stmt->execute()) {
                    logActivity($_SESSION['user_id'], 'expense_paid', "Marked expense as paid: " . $expense['expense_number']);
                    header('Location: view.php?id=' . $expense_id . '&success=paid');
                    exit();
                }
            }
            break;
            
        case 'reject':
            if ($expense['status'] === 'pending') {
                $update_stmt = $conn->prepare("
                    UPDATE expenses 
                    SET status = 'rejected', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        notes = CONCAT(IFNULL(notes, ''), '\n\n[Rejected] ', ?)
                    WHERE id = ?
                ");
                $update_stmt->bind_param('isi', $_SESSION['user_id'], $notes, $expense_id);
                if ($update_stmt->execute()) {
                    logActivity($_SESSION['user_id'], 'expense_reject', "Rejected expense: " . $expense['expense_number']);
                    header('Location: view.php?id=' . $expense_id . '&success=rejected');
                    exit();
                }
            }
            break;
            
        case 'cancel':
            $update_stmt = $conn->prepare("
                UPDATE expenses 
                SET status = 'cancelled',
                    notes = CONCAT(IFNULL(notes, ''), '\n\n[Cancelled] ', ?)
                WHERE id = ?
            ");
            $update_stmt->bind_param('si', $notes, $expense_id);
            if ($update_stmt->execute()) {
                logActivity($_SESSION['user_id'], 'expense_cancel', "Cancelled expense: " . $expense['expense_number']);
                header('Location: view.php?id=' . $expense_id . '&success=cancelled');
                exit();
            }
            break;
            
        case 'update_notes':
            $update_stmt = $conn->prepare("
                UPDATE expenses 
                SET notes = ?
                WHERE id = ?
            ");
            $update_stmt->bind_param('si', $notes, $expense_id);
            if ($update_stmt->execute()) {
                logActivity($_SESSION['user_id'], 'expense_update', "Updated notes for expense: " . $expense['expense_number']);
                header('Location: view.php?id=' . $expense_id . '&success=notes_updated');
                exit();
            }
            break;
    }
}

// Refresh expense data after updates
if (isset($_GET['success'])) {
    $stmt->execute();
    $result = $stmt->get_result();
    $expense = $result->fetch_assoc();
}

$success_message = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'approved': $success_message = 'Expense approved successfully!'; break;
        case 'paid': $success_message = 'Expense marked as paid!'; break;
        case 'rejected': $success_message = 'Expense rejected.'; break;
        case 'cancelled': $success_message = 'Expense cancelled.'; break;
        case 'notes_updated': $success_message = 'Notes updated successfully!'; break;
        case '1': $success_message = 'Expense created successfully!'; break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Expense #<?php echo $expense['expense_number']; ?> - Admin Portal</title>
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
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile-first navigation */
        .mobile-header {
            display: none;
            background: var(--sidebar);
            color: white;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .mobile-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .sidebar {
            width: 250px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            height: 100vh;
            overflow-y: auto;
            position: fixed;
            transition: transform 0.3s ease;
            z-index: 999;
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
            position: relative;
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

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.9rem;
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
        }

        .nav-section:first-child {
            margin-top: 0;
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
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .header h1 i {
            color: var(--primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        /* Expense Details Card */
        .details-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .card-title {
            color: var(--dark);
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: var(--primary);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-group {
            margin-bottom: 1.5rem;
        }

        .info-label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .info-value {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 4px solid var(--primary);
        }

        .info-value.amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-paid {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .category-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .category-operational { background: #dbeafe; color: #1e40af; }
        .category-fixed { background: #fee2e2; color: #991b1b; }
        .category-variable { background: #fef3c7; color: #92400e; }
        .category-tithe { background: #d1fae5; color: #065f46; }
        .category-reserve { background: #e0e7ff; color: #3730a3; }
        .category-other { background: #f1f5f9; color: #64748b; }

        /* Receipt Preview */
        .receipt-preview {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .receipt-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        .file-preview {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }

        .file-preview i {
            font-size: 3rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .file-preview.pdf i {
            color: #ef4444;
        }

        .file-preview.image i {
            color: #3b82f6;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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
            background: #0da271;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #2563eb;
        }

        /* Modal for actions */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .form-group {
            margin-bottom: 1.5rem;
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
            padding: 0.75rem;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease;
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

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Audit Trail */
        .audit-trail {
            margin-top: 2rem;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .audit-item {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .audit-item:last-child {
            border-bottom: none;
        }

        .audit-time {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .audit-action {
            font-weight: 500;
            color: var(--dark);
        }

        .audit-user {
            font-size: 0.9rem;
            color: #64748b;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }
        }

        @media (max-width: 768px) {
            .mobile-header {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
            }

            .header h1 {
                font-size: 1.25rem;
            }

            .user-info {
                align-self: flex-end;
            }

            .details-card {
                padding: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.1rem;
            }

            .info-value.amount {
                font-size: 1.25rem;
            }

            .details-card {
                padding: 1rem;
            }

            .modal-content {
                padding: 1rem;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar, .header, .action-buttons, .mobile-header {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 0;
            }

            .details-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <div class="mobile-header-content">
                <div>
                    <h3 style="color: white; font-size: 1.1rem;">Impact Academy</h3>
                    <p style="color: #94a3b8; font-size: 0.8rem;">Expense Details</p>
                </div>
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>

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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php">
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
                    <i class="fas fa-receipt"></i>
                    Expense #<?php echo htmlspecialchars($expense['expense_number']); ?>
                    <span class="status-badge status-<?php echo $expense['status']; ?>" style="margin-left: 1rem;">
                        <?php echo ucfirst($expense['status']); ?>
                    </span>
                </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo $_SESSION['user_name']; ?></div>
                        <div style="font-size: 0.9rem; color: #64748b;">Finance Administrator</div>
                    </div>
                </div>
            </div>

            <!-- Success Message -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <!-- Expense Details -->
            <div class="details-card">
                <h2 class="card-title">
                    <i class="fas fa-info-circle"></i>
                    Expense Information
                </h2>

                <div class="info-grid">
                    <div class="info-group">
                        <span class="info-label">Expense Number</span>
                        <div class="info-value"><?php echo htmlspecialchars($expense['expense_number']); ?></div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Category</span>
                        <div class="info-value">
                            <span class="category-badge category-<?php echo $expense['category_type']; ?>">
                                <?php echo htmlspecialchars($expense['category_name']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Amount</span>
                        <div class="info-value amount"><?php echo formatCurrency($expense['amount']); ?></div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Payment Date</span>
                        <div class="info-value"><?php echo date('F j, Y', strtotime($expense['payment_date'])); ?></div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Payment Method</span>
                        <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $expense['payment_method'])); ?></div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Status</span>
                        <div class="info-value">
                            <span class="status-badge status-<?php echo $expense['status']; ?>">
                                <?php echo ucfirst($expense['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-group">
                        <span class="info-label">Created By</span>
                        <div class="info-value">
                            <?php echo htmlspecialchars($expense['created_by_name'] . ' ' . $expense['created_by_lastname']); ?>
                            <br>
                            <small style="color: #64748b;">
                                <?php echo date('M j, Y g:i A', strtotime($expense['created_at'])); ?>
                            </small>
                        </div>
                    </div>

                    <?php if ($expense['approved_by']): ?>
                    <div class="info-group">
                        <span class="info-label">Approved By</span>
                        <div class="info-value">
                            <?php echo htmlspecialchars($expense['approved_by_name'] . ' ' . $expense['approved_by_lastname']); ?>
                            <?php if ($expense['approved_at']): ?>
                            <br>
                            <small style="color: #64748b;">
                                <?php echo date('M j, Y g:i A', strtotime($expense['approved_at'])); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($expense['paid_by']): ?>
                    <div class="info-group">
                        <span class="info-label">Paid By</span>
                        <div class="info-value">
                            <?php echo htmlspecialchars($expense['paid_by_name'] . ' ' . $expense['paid_by_lastname']); ?>
                            <?php if ($expense['paid_at']): ?>
                            <br>
                            <small style="color: #64748b;">
                                <?php echo date('M j, Y g:i A', strtotime($expense['paid_at'])); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Description -->
                <div class="info-group">
                    <span class="info-label">Description</span>
                    <div class="info-value" style="white-space: pre-wrap;"><?php echo htmlspecialchars($expense['description']); ?></div>
                </div>

                <!-- Vendor Information -->
                <?php if ($expense['vendor_name'] || $expense['vendor_contact']): ?>
                <div style="margin-top: 2rem;">
                    <h3 style="font-size: 1.1rem; color: var(--dark); margin-bottom: 1rem;">
                        <i class="fas fa-building"></i> Vendor Information
                    </h3>
                    <div class="info-grid">
                        <?php if ($expense['vendor_name']): ?>
                        <div class="info-group">
                            <span class="info-label">Vendor Name</span>
                            <div class="info-value"><?php echo htmlspecialchars($expense['vendor_name']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($expense['vendor_contact']): ?>
                        <div class="info-group">
                            <span class="info-label">Vendor Contact</span>
                            <div class="info-value"><?php echo htmlspecialchars($expense['vendor_contact']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($expense['receipt_number']): ?>
                        <div class="info-group">
                            <span class="info-label">Receipt/Invoice #</span>
                            <div class="info-value"><?php echo htmlspecialchars($expense['receipt_number']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Receipt/File Preview -->
                <?php if ($expense['receipt_url']): ?>
                <div class="receipt-preview">
                    <h3 style="font-size: 1.1rem; color: var(--dark); margin-bottom: 1rem;">
                        <i class="fas fa-file-invoice"></i> Receipt/Document
                    </h3>
                    <?php
                    $file_ext = pathinfo($expense['receipt_url'], PATHINFO_EXTENSION);
                    $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif', 'bmp']);
                    $is_pdf = strtolower($file_ext) === 'pdf';
                    ?>
                    
                    <?php if ($is_image): ?>
                        <img src="<?php echo BASE_URL . htmlspecialchars($expense['receipt_url']); ?>" 
                             alt="Receipt" 
                             class="receipt-image"
                             onclick="openImageModal(this.src)">
                    <?php elseif ($is_pdf): ?>
                        <div class="file-preview pdf">
                            <i class="fas fa-file-pdf"></i>
                            <h4>PDF Document</h4>
                            <p style="color: #64748b; margin: 0.5rem 0;">Receipt/Invoice PDF</p>
                            <a href="<?php echo BASE_URL . htmlspecialchars($expense['receipt_url']); ?>" 
                               target="_blank" 
                               class="btn btn-primary">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="file-preview">
                            <i class="fas fa-file"></i>
                            <h4>Document</h4>
                            <p style="color: #64748b; margin: 0.5rem 0;">File extension: .<?php echo $file_ext; ?></p>
                            <a href="<?php echo BASE_URL . htmlspecialchars($expense['receipt_url']); ?>" 
                               target="_blank" 
                               class="btn btn-primary">
                                <i class="fas fa-download"></i> Download File
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Notes -->
                <?php if ($expense['notes']): ?>
                <div style="margin-top: 2rem;">
                    <h3 style="font-size: 1.1rem; color: var(--dark); margin-bottom: 1rem;">
                        <i class="fas fa-sticky-note"></i> Notes
                    </h3>
                    <div class="info-value" style="white-space: pre-wrap; background: #fefce8; border-left-color: #f59e0b;">
                        <?php echo htmlspecialchars($expense['notes']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php" 
                       class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/edit.php?id=<?php echo $expense_id; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Expense
                    </a>

                    <?php if ($expense['status'] === 'pending'): ?>
                        <button class="btn btn-success" onclick="openModal('approveModal')">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="openModal('rejectModal')">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>

                    <?php if ($expense['status'] === 'approved'): ?>
                        <button class="btn btn-info" onclick="openModal('payModal')">
                            <i class="fas fa-money-bill"></i> Mark as Paid
                        </button>
                    <?php endif; ?>

                    <?php if (in_array($expense['status'], ['pending', 'approved'])): ?>
                        <button class="btn btn-warning" onclick="openModal('cancelModal')">
                            <i class="fas fa-ban"></i> Cancel
                        </button>
                    <?php endif; ?>

                    <button class="btn btn-secondary" onclick="openModal('notesModal')">
                        <i class="fas fa-sticky-note"></i> Update Notes
                    </button>

                    <button class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals for Actions -->
    <!-- Approve Modal -->
    <div class="modal" id="approveModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-check text-success"></i> Approve Expense
                </h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="approveNotes">Approval Notes (Optional)</label>
                    <textarea name="notes" id="approveNotes" class="form-control" 
                              placeholder="Add any notes about this approval..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="approve" class="btn btn-success">
                        Confirm Approval
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mark as Paid Modal -->
    <div class="modal" id="payModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-money-bill text-info"></i> Mark as Paid
                </h3>
                <button class="modal-close" onclick="closeModal('payModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="payNotes">Payment Notes (Optional)</label>
                    <textarea name="notes" id="payNotes" class="form-control" 
                              placeholder="Add any notes about this payment..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('payModal')">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="mark_paid" class="btn btn-info">
                        Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-times text-danger"></i> Reject Expense
                </h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="rejectNotes">Rejection Reason (Required)</label>
                    <textarea name="notes" id="rejectNotes" class="form-control" 
                              placeholder="Please provide a reason for rejecting this expense..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        Reject Expense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-ban text-warning"></i> Cancel Expense
                </h3>
                <button class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="cancelNotes">Cancellation Reason (Required)</label>
                    <textarea name="notes" id="cancelNotes" class="form-control" 
                              placeholder="Please provide a reason for cancelling this expense..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('cancelModal')">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="cancel" class="btn btn-warning">
                        Cancel Expense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Notes Modal -->
    <div class="modal" id="notesModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-sticky-note text-primary"></i> Update Notes
                </h3>
                <button class="modal-close" onclick="closeModal('notesModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label for="updateNotes">Notes</label>
                    <textarea name="notes" id="updateNotes" class="form-control" 
                              placeholder="Enter notes..."><?php echo htmlspecialchars($expense['notes'] ?? ''); ?></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('notesModal')">
                        Cancel
                    </button>
                    <button type="submit" name="action" value="update_notes" class="btn btn-primary">
                        Update Notes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-content" style="background: transparent; max-width: 90%; padding: 0;">
            <div class="modal-header" style="background: rgba(0,0,0,0.7); color: white; border-radius: 10px 10px 0 0;">
                <h3 class="modal-title" style="color: white;">Receipt Preview</h3>
                <button class="modal-close" onclick="closeModal('imageModal')" style="color: white;">&times;</button>
            </div>
            <div style="background: rgba(0,0,0,0.7); padding: 1rem; border-radius: 0 0 10px 10px; text-align: center;">
                <img id="modalImage" src="" alt="Receipt" style="max-width: 100%; max-height: 70vh; border-radius: 4px;">
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            openModal('imageModal');
        }

        // Close modals with ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('show');
                });
                document.body.style.overflow = 'auto';
            }
        });

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeModal(modal.id);
                }
            });
        });

        // Auto-focus on textarea when opening modal
        const modals = {
            'approveModal': 'approveNotes',
            'payModal': 'payNotes',
            'rejectModal': 'rejectNotes',
            'cancelModal': 'cancelNotes',
            'notesModal': 'updateNotes'
        };

        // Handle modal opening with focus
        document.querySelectorAll('[onclick^="openModal"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const modalId = this.getAttribute('onclick').match(/'([^']+)'/)[1];
                setTimeout(() => {
                    const inputId = modals[modalId];
                    if (inputId) {
                        const input = document.getElementById(inputId);
                        if (input) input.focus();
                    }
                }, 100);
            });
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
            }
        });

        // Show success message for a few seconds
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0';
                successAlert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 500);
            }, 5000);
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>