<?php
// modules/admin/finance/services/categories.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Check if service_categories table exists
$check_table_sql = "SHOW TABLES LIKE 'service_categories'";
$check_result = $conn->query($check_table_sql);
if (!$check_result || $check_result->num_rows === 0) {
    die('Service categories table does not exist. Please run the database migrations.');
}

// Initialize variables
$action = $_GET['action'] ?? '';
$category_id = $_GET['id'] ?? 0;
$form_data = [];
$errors = [];

// Handle different actions
switch ($action) {
    case 'add':
    case 'edit':
        if ($category_id && $action === 'edit') {
            // Load existing category for editing
            $sql = "SELECT * FROM service_categories WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $category_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $form_data = $result->fetch_assoc();
            } else {
                $_SESSION['error_message'] = "Category not found.";
                header("Location: categories.php");
                exit();
            }
        } else {
            // Initialize new category
            $form_data = [
                'name' => '',
                'description' => '',
                'revenue_type' => 'service',
                'is_active' => 1
            ];
        }
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && validateCSRFToken($_POST['csrf_token'])) {
            // Check if category is used
            $check_use_sql = "SELECT COUNT(*) as count FROM service_revenue WHERE service_category_id = ?";
            $check_stmt = $conn->prepare($check_use_sql);
            $check_stmt->bind_param('i', $category_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $usage_count = $check_result->fetch_assoc()['count'];
            
            if ($usage_count > 0) {
                $_SESSION['error_message'] = "Cannot delete category. It is used in {$usage_count} revenue records.";
            } else {
                // Delete category
                $delete_sql = "DELETE FROM service_categories WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param('i', $category_id);
                
                if ($delete_stmt->execute()) {
                    $_SESSION['success_message'] = "Category deleted successfully.";
                    logActivity($_SESSION['user_id'], 'service_category_delete', "Deleted service category ID: {$category_id}");
                } else {
                    $_SESSION['error_message'] = "Failed to delete category: " . $conn->error;
                }
            }
        }
        header("Location: categories.php");
        exit();

    case 'toggle':
        if ($category_id) {
            $sql = "UPDATE service_categories SET is_active = NOT is_active WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $category_id);
            
            if ($stmt->execute()) {
                $status = $stmt->affected_rows > 0 ? 'enabled' : 'disabled';
                $_SESSION['success_message'] = "Category {$status} successfully.";
                logActivity($_SESSION['user_id'], 'service_category_toggle', "Toggled service category ID: {$category_id}");
            } else {
                $_SESSION['error_message'] = "Failed to update category.";
            }
        }
        header("Location: categories.php");
        exit();

    default:
        // Handle form submission for add/edit
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
                $errors[] = 'Invalid CSRF token. Please try again.';
            }
            
            // Get form data
            $form_data = array_map('trim', $_POST);
            $form_data['is_active'] = isset($form_data['is_active']) ? 1 : 0;
            
            // Validate required fields
            if (empty($form_data['name'])) {
                $errors[] = 'Category name is required.';
            }
            
            if (empty($form_data['revenue_type']) || !in_array($form_data['revenue_type'], ['product', 'service', 'consultancy', 'other'])) {
                $errors[] = 'Revenue type is required.';
            }
            
            // Check for duplicate name
            $check_duplicate_sql = "SELECT id FROM service_categories WHERE name = ?";
            if ($category_id) {
                $check_duplicate_sql .= " AND id != ?";
            }
            $check_stmt = $conn->prepare($check_duplicate_sql);
            if ($category_id) {
                $check_stmt->bind_param('si', $form_data['name'], $category_id);
            } else {
                $check_stmt->bind_param('s', $form_data['name']);
            }
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors[] = 'Category name already exists.';
            }
            
            // If no errors, save category
            if (empty($errors)) {
                if ($category_id) {
                    // Update existing category
                    $sql = "UPDATE service_categories 
                            SET name = ?, description = ?, revenue_type = ?, is_active = ? 
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        'sssii',
                        $form_data['name'],
                        $form_data['description'],
                        $form_data['revenue_type'],
                        $form_data['is_active'],
                        $category_id
                    );
                    
                    $action_type = 'update';
                } else {
                    // Insert new category
                    $sql = "INSERT INTO service_categories (name, description, revenue_type, is_active, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        'sssi',
                        $form_data['name'],
                        $form_data['description'],
                        $form_data['revenue_type'],
                        $form_data['is_active']
                    );
                    
                    $action_type = 'add';
                }
                
                if ($stmt->execute()) {
                    $new_id = $category_id ?: $stmt->insert_id;
                    $_SESSION['success_message'] = "Category {$action_type}d successfully.";
                    logActivity($_SESSION['user_id'], "service_category_{$action_type}", "{$action_type}d service category ID: {$new_id}");
                    
                    header("Location: categories.php");
                    exit();
                } else {
                    $errors[] = "Failed to save category: " . $conn->error;
                }
            }
        }
        break;
}

// Get all categories for listing
$categories_sql = "SELECT 
                    sc.*,
                    COUNT(sr.id) as revenue_count,
                    COALESCE(SUM(CASE WHEN sr.status = 'completed' THEN sr.amount ELSE 0 END), 0) as total_revenue
                  FROM service_categories sc
                  LEFT JOIN service_revenue sr ON sr.service_category_id = sc.id
                  GROUP BY sc.id, sc.name, sc.description, sc.revenue_type, sc.is_active, sc.created_at
                  ORDER BY sc.name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get category statistics
$stats_sql = "SELECT 
                COUNT(*) as total_categories,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_categories,
                COUNT(DISTINCT revenue_type) as unique_types
              FROM service_categories";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Revenue by type
$type_stats_sql = "SELECT 
                    revenue_type,
                    COUNT(*) as category_count,
                    COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active_count
                  FROM service_categories
                  GROUP BY revenue_type
                  ORDER BY category_count DESC";
$type_stats_result = $conn->query($type_stats_sql);
$type_stats = $type_stats_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Categories - Finance Admin</title>
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 1rem;
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
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
        }

        .stat-card.categories {
            border-top-color: var(--success);
        }

        .stat-card.active {
            border-top-color: var(--info);
        }

        .stat-card.types {
            border-top-color: var(--warning);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            color: var(--dark);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .form-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .form-header h2 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
        }

        .form-body {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
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

        .form-group label.required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
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

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }

        /* Table Styles */
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
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .revenue-type {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-product {
            background: #ede9fe;
            color: #7c3aed;
        }

        .type-service {
            background: #dcfce7;
            color: #059669;
        }

        .type-consultancy {
            background: #dbf4ff;
            color: #0284c7;
        }

        .type-other {
            background: #f3f4f6;
            color: #6b7280;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

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
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 1rem;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #fecaca;
        }

        .error-message ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .error-message li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .error-message li:last-child {
            margin-bottom: 0;
        }

        .error-message i {
            font-size: 1.1rem;
        }

        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #a7f3d0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Revenue Type Distribution */
        .type-distribution {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }

        .type-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .type-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .type-count {
            font-weight: 600;
            color: var(--dark);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 768px) {
            .admin-container {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-tags"></i>
                Service Categories Management
            </h1>
            <p>Organize your non-academic revenue into categories</p>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="header-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Service Revenue
            </a>
            <a href="categories.php?action=add" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Add New Category
            </a>
            <a href="dashboard.php" class="btn btn-info">
                <i class="fas fa-chart-line"></i> Analytics Dashboard
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card categories">
                <div class="stat-label">Total Categories</div>
                <div class="stat-number"><?php echo $stats['total_categories'] ?? 0; ?></div>
            </div>
            <div class="stat-card active">
                <div class="stat-label">Active Categories</div>
                <div class="stat-number"><?php echo $stats['active_categories'] ?? 0; ?></div>
            </div>
            <div class="stat-card types">
                <div class="stat-label">Unique Revenue Types</div>
                <div class="stat-number"><?php echo $stats['unique_types'] ?? 0; ?></div>
            </div>
        </div>

        <!-- Revenue Type Distribution -->
        <?php if (!empty($type_stats)): ?>
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-chart-pie"></i> Revenue Type Distribution</h2>
            </div>
            <div class="card-body">
                <div class="type-distribution">
                    <?php foreach ($type_stats as $type): ?>
                    <div class="type-item">
                        <div class="type-info">
                            <span class="revenue-type type-<?php echo $type['revenue_type']; ?>">
                                <?php echo ucfirst($type['revenue_type']); ?>
                            </span>
                            <span class="type-count">
                                <?php echo $type['category_count']; ?> categories
                            </span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-size: 0.9rem;">
                                <?php echo $type['active_count']; ?> active
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
            <!-- Category Form -->
            <div class="form-container">
                <div class="form-header">
                    <h2>
                        <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
                        <?php echo $action === 'add' ? 'Add New Category' : 'Edit Category'; ?>
                    </h2>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="error-message" style="margin: 1.5rem; margin-bottom: 0;">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li>
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo htmlspecialchars($error); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="form-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name" class="required">Category Name</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['name'] ?? ''); ?>" 
                                   required placeholder="e.g., Software Development">
                            <p class="form-help" style="margin-top: 0.5rem; color: #94a3b8; font-size: 0.85rem;">
                                Make it descriptive and unique
                            </p>
                        </div>
                        
                        <div class="form-group">
                            <label for="revenue_type" class="required">Revenue Type</label>
                            <select id="revenue_type" name="revenue_type" class="form-control" required>
                                <option value="service" <?php echo ($form_data['revenue_type'] ?? '') === 'service' ? 'selected' : ''; ?>>Service</option>
                                <option value="product" <?php echo ($form_data['revenue_type'] ?? '') === 'product' ? 'selected' : ''; ?>>Product</option>
                                <option value="consultancy" <?php echo ($form_data['revenue_type'] ?? '') === 'consultancy' ? 'selected' : ''; ?>>Consultancy</option>
                                <option value="other" <?php echo ($form_data['revenue_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <p class="form-help" style="margin-top: 0.5rem; color: #94a3b8; font-size: 0.85rem;">
                                Helps organize similar revenue streams
                            </p>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_active" name="is_active" value="1" 
                                       <?php echo ($form_data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label for="is_active">Active Category</label>
                            </div>
                            <p class="form-help" style="margin-top: 0.5rem; color: #94a3b8; font-size: 0.85rem;">
                                Inactive categories won't appear in dropdowns
                            </p>
                        </div>
                        
                        <div class="form-group" style="grid-column: span 1;">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" 
                                      rows="4" placeholder="Describe what this category includes..."><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                            <p class="form-help" style="margin-top: 0.5rem; color: #94a3b8; font-size: 0.85rem;">
                                Optional but helpful for organization
                            </p>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <a href="categories.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Category' : 'Save Changes'; ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Categories List -->
            <div class="content-card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> All Categories</h2>
                    <div>
                        <a href="categories.php?action=add" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> New Category
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($categories)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Revenue Records</th>
                                        <th>Total Revenue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                            <?php if ($category['description']): ?>
                                                <br><small style="color: #64748b;"><?php echo htmlspecialchars($category['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="revenue-type type-<?php echo $category['revenue_type']; ?>">
                                                <?php echo ucfirst($category['revenue_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $category['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $category['revenue_count']; ?> records
                                        </td>
                                        <td>
                                            <strong><?php echo formatCurrency($category['total_revenue']); ?></strong>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="categories.php?action=edit&id=<?php echo $category['id']; ?>" 
                                                   class="btn btn-sm btn-primary btn-icon" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=toggle&id=<?php echo $category['id']; ?>" 
                                                   class="btn btn-sm btn-info btn-icon" 
                                                   title="<?php echo $category['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas fa-power-off"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger btn-icon" 
                                                        title="Delete"
                                                        onclick="confirmDelete(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars(addslashes($category['name'])); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tags"></i>
                            <h3>No Categories Found</h3>
                            <p>You haven't created any service categories yet.</p>
                            <a href="categories.php?action=add" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus-circle"></i> Create Your First Category
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this category?</p>
            </div>
            <div class="modal-actions">
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" id="deleteId">
                    <input type="hidden" name="action" value="delete">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Category
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Delete confirmation
        function confirmDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteMessage').textContent = 
                `Are you sure you want to delete the category "${name}"? This action cannot be undone.`;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeDeleteModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDeleteModal();
            }
            
            if (event.ctrlKey && event.key === 'n') {
                event.preventDefault();
                window.location.href = 'categories.php?action=add';
            }
        });

        // Auto-focus first field in form
        <?php if ($action === 'add' || $action === 'edit'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('name').focus();
        });
        <?php endif; ?>

        // Form validation for add/edit
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const name = document.getElementById('name').value.trim();
                const type = document.getElementById('revenue_type').value;
                
                if (!name) {
                    e.preventDefault();
                    alert('Please enter a category name.');
                    document.getElementById('name').focus();
                    return false;
                }
                
                if (!type) {
                    e.preventDefault();
                    alert('Please select a revenue type.');
                    document.getElementById('revenue_type').focus();
                    return false;
                }
                
                return true;
            });
        }

        // Show warning for categories with revenue records
        const deleteButtons = document.querySelectorAll('.btn-danger');
        deleteButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                const row = this.closest('tr');
                const revenueCount = row.querySelector('td:nth-child(4)').textContent.trim();
                const count = parseInt(revenueCount);
                
                if (count > 0 && !confirm(`This category has ${count} revenue records. Deleting it will affect these records. Are you sure you want to proceed?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>