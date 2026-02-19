<?php
// modules/admin/finance/expenses/edit.php

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

// Get expense details for editing
$stmt = $conn->prepare("
    SELECT e.*, ec.name as category_name, ec.category_type
    FROM expenses e
    JOIN expense_categories ec ON ec.id = e.category_id
    WHERE e.id = ? AND e.status IN ('pending', 'approved')
");
$stmt->bind_param('i', $expense_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ' . BASE_URL . 'modules/admin/finance/expenses/view.php?id=' . $expense_id . '&error=cannot_edit');
    exit();
}

$expense = $result->fetch_assoc();

// Get expense categories for dropdown
$categories_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$error = '';
$success = '';
$form_data = [
    'category_id' => $expense['category_id'],
    'description' => $expense['description'],
    'amount' => $expense['amount'],
    'payment_method' => $expense['payment_method'],
    'payment_date' => $expense['payment_date'],
    'vendor_name' => $expense['vendor_name'],
    'vendor_contact' => $expense['vendor_contact'],
    'receipt_number' => $expense['receipt_number'],
    'notes' => $expense['notes']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $category_id = intval($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $vendor_contact = trim($_POST['vendor_contact'] ?? '');
    $receipt_number = trim($_POST['receipt_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $remove_receipt = isset($_POST['remove_receipt']) ? true : false;
    
    // Validation
    if ($category_id <= 0) {
        $error = 'Please select a valid expense category';
    } elseif (empty($description)) {
        $error = 'Description is required';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than 0';
    } elseif (!strtotime($payment_date)) {
        $error = 'Invalid payment date';
    } else {
        // Handle file upload if present
        $receipt_url = $expense['receipt_url'];
        
        if ($remove_receipt && $receipt_url) {
            // Remove existing file
            $file_path = __DIR__ . '/../../../../' . $receipt_url;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $receipt_url = '';
        }
        
        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            // Remove old file if exists
            if ($receipt_url) {
                $old_file_path = __DIR__ . '/../../../../' . $receipt_url;
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
            }
            
            $upload_dir = __DIR__ . '/../../../../uploads/expense_receipts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $file_path)) {
                    $receipt_url = 'uploads/expense_receipts/' . $file_name;
                } else {
                    $error = 'Failed to upload receipt file';
                }
            } else {
                $error = 'Invalid file type. Allowed: JPG, PNG, PDF, GIF';
            }
        }
        
        if (empty($error)) {
            // Update expense
            $update_stmt = $conn->prepare("
                UPDATE expenses 
                SET category_id = ?, 
                    description = ?, 
                    amount = ?, 
                    payment_method = ?, 
                    payment_date = ?, 
                    vendor_name = ?, 
                    vendor_contact = ?, 
                    receipt_number = ?, 
                    receipt_url = ?, 
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $update_stmt->bind_param(
                'isdsssssssi',
                $category_id,
                $description,
                $amount,
                $payment_method,
                $payment_date,
                $vendor_name,
                $vendor_contact,
                $receipt_number,
                $receipt_url,
                $notes,
                $expense_id
            );
            
            if ($update_stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'expense_edit', "Edited expense: " . $expense['expense_number']);
                
                $success = "Expense updated successfully!";
                
                // Update form data
                $form_data = [
                    'category_id' => $category_id,
                    'description' => $description,
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'payment_date' => $payment_date,
                    'vendor_name' => $vendor_name,
                    'vendor_contact' => $vendor_contact,
                    'receipt_number' => $receipt_number,
                    'notes' => $notes
                ];
                
                // Update expense record
                $expense['receipt_url'] = $receipt_url;
                
                // Redirect to view page
                header('Location: view.php?id=' . $expense_id . '&success=updated');
                exit();
            } else {
                $error = "Failed to update expense: " . $conn->error;
            }
        }
    }
    
    // Preserve form data on error
    $form_data = [
        'category_id' => $_POST['category_id'] ?? $expense['category_id'],
        'description' => $_POST['description'] ?? $expense['description'],
        'amount' => $_POST['amount'] ?? $expense['amount'],
        'payment_method' => $_POST['payment_method'] ?? $expense['payment_method'],
        'payment_date' => $_POST['payment_date'] ?? $expense['payment_date'],
        'vendor_name' => $_POST['vendor_name'] ?? $expense['vendor_name'],
        'vendor_contact' => $_POST['vendor_contact'] ?? $expense['vendor_contact'],
        'receipt_number' => $_POST['receipt_number'] ?? $expense['receipt_number'],
        'notes' => $_POST['notes'] ?? $expense['notes']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Expense #<?php echo $expense['expense_number']; ?> - Admin Portal</title>
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

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }

        .form-title {
            color: var(--dark);
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-title i {
            color: var(--primary);
        }

        .expense-info {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .expense-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .info-item {
            font-size: 0.9rem;
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
        }

        .info-value {
            color: var(--dark);
            font-weight: 600;
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        .help-text {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 0.25rem;
        }

        .currency-input {
            position: relative;
        }

        .currency-input .currency-symbol {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 500;
        }

        .currency-input .form-control {
            padding-left: 2.5rem;
        }

        /* Receipt Section */
        .receipt-section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .current-receipt {
            margin-bottom: 1.5rem;
        }

        .receipt-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 0.5rem;
        }

        .receipt-icon {
            font-size: 2rem;
            color: var(--primary);
        }

        .receipt-info {
            flex: 1;
        }

        .receipt-actions {
            display: flex;
            gap: 0.5rem;
        }

        .file-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 6px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
        }

        .file-upload-label i {
            font-size: 2rem;
            color: var(--primary);
        }

        .file-preview {
            margin-top: 1rem;
            display: none;
        }

        .file-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 4px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-size: 0.9rem;
            color: #64748b;
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

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0da271;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
            flex-wrap: wrap;
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

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .form-container {
                padding: 1.5rem;
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

            .form-container {
                padding: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .expense-info-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .receipt-preview {
                flex-direction: column;
                text-align: center;
            }

            .receipt-actions {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.1rem;
            }

            .form-container {
                padding: 1rem;
            }

            .form-title {
                font-size: 1.1rem;
            }

            .expense-info {
                padding: 0.75rem;
            }

            .btn {
                padding: 0.75rem 1rem;
            }
        }

        /* Loading State */
        .loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid #e2e8f0;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
                    <p style="color: #94a3b8; font-size: 0.8rem;">Edit Expense</p>
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
                    <i class="fas fa-edit"></i>
                    Edit Expense #<?php echo htmlspecialchars($expense['expense_number']); ?>
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

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Form Container -->
            <div class="form-container">
                <!-- Expense Info -->
                <div class="expense-info">
                    <div class="expense-info-row">
                        <div class="info-item">
                            <span class="info-label">Expense Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($expense['expense_number']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Current Category:</span>
                            <span class="info-value"><?php echo htmlspecialchars($expense['category_name']); ?> (<?php echo ucfirst($expense['category_type']); ?>)</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created:</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($expense['created_at'])); ?></span>
                        </div>
                    </div>
                    <?php if ($expense['updated_at'] && $expense['updated_at'] !== $expense['created_at']): ?>
                    <div class="expense-info-row">
                        <div class="info-item">
                            <span class="info-label">Last Updated:</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($expense['updated_at'])); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" id="editExpenseForm">
                    <h2 class="form-title">
                        <i class="fas fa-receipt"></i>
                        Expense Details
                    </h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="category_id" class="required">Expense Category</label>
                            <select name="category_id" id="category_id" class="form-control" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                        <?php echo $form_data['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                        <?php if ($cat['category_type']): ?>
                                            (<?php echo ucfirst($cat['category_type']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="payment_date" class="required">Payment Date</label>
                            <input type="date" name="payment_date" id="payment_date" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['payment_date']); ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description" class="required">Description</label>
                        <textarea name="description" id="description" class="form-control" 
                                  required><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="help-text">Describe what this expense is for</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount" class="required">Amount (NGN)</label>
                            <div class="currency-input">
                                <span class="currency-symbol">₦</span>
                                <input type="number" name="amount" id="amount" 
                                       class="form-control" step="0.01" min="0.01" 
                                       value="<?php echo htmlspecialchars($form_data['amount']); ?>" 
                                       required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="payment_method" class="required">Payment Method</label>
                            <select name="payment_method" id="payment_method" class="form-control" required>
                                <option value="bank_transfer" <?php echo $form_data['payment_method'] == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cash" <?php echo $form_data['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="cheque" <?php echo $form_data['payment_method'] == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="online" <?php echo $form_data['payment_method'] == 'online' ? 'selected' : ''; ?>>Online Payment</option>
                                <option value="pos" <?php echo $form_data['payment_method'] == 'pos' ? 'selected' : ''; ?>>POS</option>
                                <option value="mobile_money" <?php echo $form_data['payment_method'] == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            </select>
                        </div>
                    </div>

                    <h2 class="form-title" style="margin-top: 2rem;">
                        <i class="fas fa-building"></i>
                        Vendor Information
                    </h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vendor_name">Vendor/Supplier Name</label>
                            <input type="text" name="vendor_name" id="vendor_name" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['vendor_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="vendor_contact">Vendor Contact</label>
                            <input type="text" name="vendor_contact" id="vendor_contact" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['vendor_contact']); ?>">
                            <div class="help-text">Phone, email, or other contact information</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="receipt_number">Receipt/Invoice Number</label>
                        <input type="text" name="receipt_number" id="receipt_number" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['receipt_number']); ?>">
                    </div>

                    <h2 class="form-title" style="margin-top: 2rem;">
                        <i class="fas fa-file-invoice"></i>
                        Receipt & Documentation
                    </h2>

                    <div class="receipt-section">
                        <?php if ($expense['receipt_url']): 
                            $file_ext = pathinfo($expense['receipt_url'], PATHINFO_EXTENSION);
                            $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif', 'bmp']);
                            $is_pdf = strtolower($file_ext) === 'pdf';
                        ?>
                            <div class="current-receipt">
                                <label>Current Receipt</label>
                                <div class="receipt-preview">
                                    <?php if ($is_image): ?>
                                        <i class="fas fa-image receipt-icon" style="color: #3b82f6;"></i>
                                    <?php elseif ($is_pdf): ?>
                                        <i class="fas fa-file-pdf receipt-icon" style="color: #ef4444;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-file receipt-icon" style="color: #64748b;"></i>
                                    <?php endif; ?>
                                    
                                    <div class="receipt-info">
                                        <div style="font-weight: 500;">
                                            <?php echo basename($expense['receipt_url']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #64748b;">
                                            Uploaded on <?php echo date('M j, Y', filemtime(__DIR__ . '/../../../../' . $expense['receipt_url'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="receipt-actions">
                                        <?php if ($is_image): ?>
                                            <a href="<?php echo BASE_URL . htmlspecialchars($expense['receipt_url']); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo BASE_URL . htmlspecialchars($expense['receipt_url']); ?>" 
                                           download 
                                           class="btn btn-sm btn-secondary">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="checkbox-group">
                                    <input type="checkbox" name="remove_receipt" id="remove_receipt" 
                                           value="1" onchange="toggleFileUpload()">
                                    <label for="remove_receipt">Remove current receipt</label>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div id="fileUploadSection" <?php echo $expense['receipt_url'] ? 'style="display: none;"' : ''; ?>>
                            <label>Upload New Receipt (Optional)</label>
                            <div class="help-text">Max 5MB. Allowed: JPG, PNG, PDF, GIF</div>
                            
                            <div class="file-upload" onclick="document.getElementById('receipt_file').click()">
                                <input type="file" name="receipt_file" id="receipt_file" 
                                       accept=".jpg,.jpeg,.png,.pdf,.gif" 
                                       onchange="previewFile(this)">
                                <label for="receipt_file" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Click to upload receipt</span>
                                    <small>Drag & drop or click to browse</small>
                                </label>
                            </div>
                            <div id="filePreview" class="file-preview"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea name="notes" id="notes" class="form-control" 
                                  rows="4"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                        <div class="help-text">Any additional information or context for this expense</div>
                    </div>

                    <div class="form-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/view.php?id=<?php echo $expense_id; ?>" 
                           class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="button" onclick="resetForm()" class="btn btn-warning">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Update Expense
                        </button>
                    </div>
                </form>
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

        // Toggle file upload section
        function toggleFileUpload() {
            const removeCheckbox = document.getElementById('remove_receipt');
            const fileUploadSection = document.getElementById('fileUploadSection');
            
            if (removeCheckbox.checked) {
                fileUploadSection.style.display = 'block';
            } else {
                fileUploadSection.style.display = 'none';
                // Clear file input
                document.getElementById('receipt_file').value = '';
                document.getElementById('filePreview').style.display = 'none';
            }
        }

        // File preview
        function previewFile(input) {
            const preview = document.getElementById('filePreview');
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        preview.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: white; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <img src="${e.target.result}" alt="Preview" style="width: 80px; height: 80px; object-fit: cover; border-radius: 4px;">
                                <div>
                                    <div style="font-weight: 500;">${file.name}</div>
                                    <div style="font-size: 0.85rem; color: #64748b;">
                                        ${(file.size / 1024 / 1024).toFixed(2)} MB
                                    </div>
                                </div>
                            </div>
                        `;
                    } else if (file.type === 'application/pdf') {
                        preview.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: white; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <i class="fas fa-file-pdf" style="font-size: 2rem; color: #ef4444;"></i>
                                <div>
                                    <div style="font-weight: 500;">${file.name}</div>
                                    <div style="font-size: 0.85rem; color: #64748b;">
                                        PDF Document • ${(file.size / 1024 / 1024).toFixed(2)} MB
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        preview.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: white; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <i class="fas fa-file" style="font-size: 2rem; color: #64748b;"></i>
                                <div>
                                    <div style="font-weight: 500;">${file.name}</div>
                                    <div style="font-size: 0.85rem; color: #64748b;">
                                        ${file.type} • ${(file.size / 1024 / 1024).toFixed(2)} MB
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        }

        // Reset form to original values
        function resetForm() {
            if (confirm('Are you sure you want to reset all changes?')) {
                const form = document.getElementById('editExpenseForm');
                form.reset();
                
                // Reset file preview
                document.getElementById('receipt_file').value = '';
                document.getElementById('filePreview').style.display = 'none';
                
                // Reset checkbox
                const removeCheckbox = document.getElementById('remove_receipt');
                if (removeCheckbox) {
                    removeCheckbox.checked = false;
                    toggleFileUpload();
                }
            }
        }

        // Form validation and submission
        document.getElementById('editExpenseForm').addEventListener('submit', function(e) {
            const amount = document.getElementById('amount');
            if (amount && parseFloat(amount.value) <= 0) {
                e.preventDefault();
                alert('Amount must be greater than 0');
                amount.focus();
                return;
            }
            
            const category = document.getElementById('category_id');
            if (category && category.value === '') {
                e.preventDefault();
                alert('Please select an expense category');
                category.focus();
                return;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        });

        // Set today's date as default if not already set
        document.addEventListener('DOMContentLoaded', function() {
            const paymentDate = document.getElementById('payment_date');
            if (paymentDate && !paymentDate.value) {
                const today = new Date().toISOString().split('T')[0];
                paymentDate.value = today;
            }
            
            // File size validation
            const fileInput = document.getElementById('receipt_file');
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        const maxSize = 5 * 1024 * 1024; // 5MB
                        if (file.size > maxSize) {
                            alert('File size exceeds 5MB limit. Please choose a smaller file.');
                            this.value = '';
                            document.getElementById('filePreview').style.display = 'none';
                        }
                    }
                });
            }
            
            // Handle window resize
            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('show');
                }
            });
        });

        // Auto-save draft (optional feature)
        let autoSaveTimeout;
        const formFields = ['category_id', 'description', 'amount', 'payment_method', 
                          'payment_date', 'vendor_name', 'vendor_contact', 
                          'receipt_number', 'notes'];

        formFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', () => {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(saveDraft, 2000);
                });
            }
        });

        function saveDraft() {
            // Optional: Save form data to localStorage for recovery
            const formData = {};
            formFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    formData[fieldId] = field.value;
                }
            });
            localStorage.setItem('expense_edit_draft', JSON.stringify(formData));
            
            // Show auto-save notification
            const notification = document.createElement('div');
            notification.className = 'alert alert-info';
            notification.innerHTML = '<i class="fas fa-save"></i> Changes saved locally';
            notification.style.position = 'fixed';
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '1000';
            notification.style.maxWidth = '300px';
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Load draft on page load
        window.addEventListener('load', () => {
            const draft = localStorage.getItem('expense_edit_draft');
            if (draft) {
                if (confirm('Found saved draft. Would you like to restore it?')) {
                    const formData = JSON.parse(draft);
                    Object.keys(formData).forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.value = formData[fieldId];
                        }
                    });
                }
            }
        });

        // Clear draft on successful submission
        document.getElementById('editExpenseForm').addEventListener('submit', () => {
            localStorage.removeItem('expense_edit_draft');
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>