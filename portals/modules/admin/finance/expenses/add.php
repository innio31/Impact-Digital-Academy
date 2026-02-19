<?php
// modules/admin/finance/expenses/add.php

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

// Get expense categories for dropdown
$categories_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$error = '';
$success = '';
$form_data = [
    'category_id' => '',
    'description' => '',
    'amount' => '',
    'payment_method' => 'bank_transfer',
    'payment_date' => date('Y-m-d'),
    'vendor_name' => '',
    'vendor_contact' => '',
    'receipt_number' => '',
    'notes' => ''
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
        // Generate unique expense number
        $expense_number = 'EXP-' . date('Ymd') . '-' . strtoupper(uniqid());
        
        // Handle file upload if present
        $receipt_url = '';
        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
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
            // Insert expense
            $stmt = $conn->prepare("
                INSERT INTO expenses (
                    expense_number, category_id, description, amount, currency, 
                    payment_method, payment_date, vendor_name, vendor_contact,
                    receipt_number, receipt_url, notes, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'NGN', ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->bind_param(
                'sisdsssssssi',
                $expense_number,
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
                $_SESSION['user_id']
            );
            
            if ($stmt->execute()) {
                $expense_id = $conn->insert_id;
                
                // Log activity
                logActivity($_SESSION['user_id'], 'expense_add', "Added new expense: $expense_number for " . formatCurrency($amount));
                
                $success = "Expense added successfully! Expense Number: $expense_number";
                
                // Clear form data
                $form_data = [
                    'category_id' => '',
                    'description' => '',
                    'amount' => '',
                    'payment_method' => 'bank_transfer',
                    'payment_date' => date('Y-m-d'),
                    'vendor_name' => '',
                    'vendor_contact' => '',
                    'receipt_number' => '',
                    'notes' => ''
                ];
                
                // Redirect to expense view page
                header("Location: view.php?id=" . $expense_id . "&success=1");
                exit();
            } else {
                $error = "Failed to add expense: " . $conn->error;
            }
        }
    }
    
    // Preserve form data on error
    $form_data = [
        'category_id' => $_POST['category_id'] ?? '',
        'description' => $_POST['description'] ?? '',
        'amount' => $_POST['amount'] ?? '',
        'payment_method' => $_POST['payment_method'] ?? 'bank_transfer',
        'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
        'vendor_name' => $_POST['vendor_name'] ?? '',
        'vendor_contact' => $_POST['vendor_contact'] ?? '',
        'receipt_number' => $_POST['receipt_number'] ?? '',
        'notes' => $_POST['notes'] ?? ''
    ];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Expense - Admin Portal</title>
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
            --mobile-header-height: 60px;
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
            padding-top: var(--mobile-header-height);
        }

        /* Mobile Header */
        .mobile-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--mobile-header-height);
            background: var(--sidebar);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-header .logo {
            display: flex;
            flex-direction: column;
        }

        .mobile-header .logo h2 {
            font-size: 1.2rem;
            color: white;
        }

        .mobile-header .logo p {
            color: #94a3b8;
            font-size: 0.8rem;
        }

        .mobile-menu-toggle {
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

        .mobile-menu-toggle:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Mobile Sidebar */
        .mobile-sidebar {
            position: fixed;
            top: var(--mobile-header-height);
            left: -100%;
            width: 100%;
            height: calc(100vh - var(--mobile-header-height));
            background: var(--sidebar);
            color: white;
            overflow-y: auto;
            transition: left 0.3s ease;
            z-index: 999;
        }

        .mobile-sidebar.active {
            left: 0;
        }

        .mobile-sidebar-overlay {
            position: fixed;
            top: var(--mobile-header-height);
            left: 0;
            width: 100%;
            height: calc(100vh - var(--mobile-header-height));
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            z-index: 998;
        }

        .mobile-sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-nav-section {
            padding: 0.75rem 1.5rem;
            color: #94a3b8;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid var(--dark-light);
            margin-top: 0.5rem;
        }

        .mobile-nav-section:first-child {
            margin-top: 0;
            border-top: none;
        }

        .mobile-nav ul {
            list-style: none;
        }

        .mobile-nav li {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .mobile-nav a {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .mobile-nav a:hover,
        .mobile-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .mobile-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        /* Desktop Sidebar */
        .desktop-sidebar {
            display: none;
        }

        /* Main Content */
        .main-content {
            padding: 1rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
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

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-title {
            color: var(--dark);
            font-size: 1.25rem;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-title i {
            color: var(--primary);
        }

        .form-group {
            margin-bottom: 1.25rem;
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
            font-size: 1rem;
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
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
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
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            width: 100%;
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

        .file-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 6px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
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
            font-size: 1.75rem;
            color: var(--primary);
        }

        .file-preview {
            margin-top: 1rem;
            display: none;
        }

        .file-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 4px;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
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

        .currency-input {
            position: relative;
        }

        .currency-input .currency-symbol {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1rem;
        }

        .currency-input .form-control {
            padding-left: 2.5rem;
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

        .form-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        /* Desktop Styles */
        @media (min-width: 768px) {
            body {
                padding-top: 0;
                display: flex;
            }

            .mobile-header {
                display: none;
            }

            .mobile-sidebar,
            .mobile-sidebar-overlay {
                display: none;
            }

            .desktop-sidebar {
                display: block;
                width: 250px;
                background: var(--sidebar);
                color: white;
                padding: 1.5rem 0;
                height: 100vh;
                overflow-y: auto;
                position: sticky;
                top: 0;
            }

            .main-content {
                flex: 1;
                padding: 2rem;
                overflow-y: auto;
                height: 100vh;
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
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .header h1 {
                margin-bottom: 0;
            }

            .user-info {
                padding-top: 0;
                border-top: none;
            }

            .form-container {
                padding: 2rem;
            }

            .form-row {
                flex-direction: row;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }

            .btn {
                width: auto;
            }

            .form-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
        }

        @media (min-width: 992px) {
            .form-container {
                max-width: 800px;
                margin: 0 auto 2rem;
            }
        }

        /* Larger mobile adjustments */
        @media (min-width: 576px) and (max-width: 767px) {
            .btn {
                width: auto;
            }
            
            .form-actions {
                flex-direction: row;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="logo">
            <h2>Impact Academy</h2>
            <p>Add Expense</p>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div class="mobile-sidebar-overlay" id="mobileSidebarOverlay"></div>

    <!-- Mobile Sidebar -->
    <div class="mobile-sidebar" id="mobileSidebar">
        <nav class="mobile-nav">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                        <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/dashboard.php">
                        <i class="fas fa-money-bill-wave"></i> Expense Dashboard</a></li>

                <div class="mobile-nav-section">Expense Management</div>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php">
                        <i class="fas fa-list"></i> Manage Expenses</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php" class="active">
                        <i class="fas fa-plus-circle"></i> Add Expense</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php">
                        <i class="fas fa-tags"></i> Categories</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php">
                        <i class="fas fa-chart-pie"></i> Budgets</a></li>

                <div class="mobile-nav-section">Automated Deductions</div>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/deductions.php">
                        <i class="fas fa-cog"></i> Configure Deductions</a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/reports.php">
                        <i class="fas fa-file-alt"></i> Expense Reports</a></li>
            </ul>
        </nav>
    </div>

    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar">
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
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/add.php" class="active">
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
            <div>
                <h1>
                    <i class="fas fa-plus-circle"></i>
                    Add New Expense
                </h1>
            </div>
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

        <!-- Form Container -->
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="expenseForm">
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

                <h2 class="form-title">
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

                <h2 class="form-title">
                    <i class="fas fa-file-invoice"></i>
                    Receipt & Documentation
                </h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="receipt_number">Receipt/Invoice Number</label>
                        <input type="text" name="receipt_number" id="receipt_number" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['receipt_number']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Upload Receipt (Optional)</label>
                        <div class="file-upload" onclick="document.getElementById('receipt_file').click()">
                            <input type="file" name="receipt_file" id="receipt_file" 
                                   accept=".jpg,.jpeg,.png,.pdf,.gif" 
                                   onchange="previewFile(this)">
                            <label for="receipt_file" class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Click to upload receipt</span>
                                <small>Max 5MB. Allowed: JPG, PNG, PDF, GIF</small>
                            </label>
                        </div>
                        <div id="filePreview" class="file-preview"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Additional Notes</label>
                    <textarea name="notes" id="notes" class="form-control" 
                              rows="3"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                    <div class="help-text">Any additional information or context for this expense</div>
                </div>

                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/dashboard.php" 
                       class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const mobileSidebarOverlay = document.getElementById('mobileSidebarOverlay');
        const body = document.body;

        function toggleMobileMenu() {
            mobileSidebar.classList.toggle('active');
            mobileSidebarOverlay.classList.toggle('active');
            
            // Prevent body scroll when menu is open
            if (mobileSidebar.classList.contains('active')) {
                body.style.overflow = 'hidden';
            } else {
                body.style.overflow = '';
            }
        }

        mobileMenuToggle.addEventListener('click', toggleMobileMenu);
        mobileSidebarOverlay.addEventListener('click', toggleMobileMenu);

        // Close menu when clicking on a link (on mobile)
        const mobileNavLinks = document.querySelectorAll('.mobile-nav a');
        mobileNavLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    toggleMobileMenu();
                }
            });
        });

        // File Preview Function
        function previewFile(input) {
            const preview = document.getElementById('filePreview');
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        preview.innerHTML = `<img src="${e.target.result}" alt="Receipt Preview">`;
                    } else if (file.type === 'application/pdf') {
                        preview.innerHTML = `
                            <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; text-align: center;">
                                <i class="fas fa-file-pdf" style="font-size: 2rem; color: #ef4444;"></i>
                                <div style="margin-top: 0.5rem; font-weight: 500;">${file.name}</div>
                                <div style="font-size: 0.85rem; color: #64748b;">PDF Document</div>
                            </div>
                        `;
                    } else {
                        preview.innerHTML = `
                            <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; text-align: center;">
                                <i class="fas fa-file" style="font-size: 2rem; color: #64748b;"></i>
                                <div style="margin-top: 0.5rem; font-weight: 500;">${file.name}</div>
                                <div style="font-size: 0.85rem; color: #64748b;">${file.type}</div>
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

        // Set today's date as default if not already set
        document.addEventListener('DOMContentLoaded', function() {
            const paymentDate = document.getElementById('payment_date');
            if (paymentDate && !paymentDate.value) {
                const today = new Date().toISOString().split('T')[0];
                paymentDate.value = today;
            }
            
            // Form validation
            document.getElementById('expenseForm').addEventListener('submit', function(e) {
                const amount = document.getElementById('amount');
                if (amount && parseFloat(amount.value) <= 0) {
                    e.preventDefault();
                    alert('Amount must be greater than 0');
                    amount.focus();
                    return false;
                }
                
                const category = document.getElementById('category_id');
                if (category && category.value === '') {
                    e.preventDefault();
                    alert('Please select an expense category');
                    category.focus();
                    return false;
                }
                
                return true;
            });
            
            // Close mobile menu on window resize if it becomes desktop size
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    mobileSidebar.classList.remove('active');
                    mobileSidebarOverlay.classList.remove('active');
                    body.style.overflow = '';
                }
            });
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>