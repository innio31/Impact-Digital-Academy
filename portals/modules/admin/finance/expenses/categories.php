<?php
// modules/admin/finance/expenses/categories.php

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

// Handle category actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Add new category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $category_type = $_POST['category_type'];
    $color_code = $_POST['color_code'];
    $budget_amount = $_POST['budget_amount'] ?? 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($name)) {
        // Check if category already exists
        $check_sql = "SELECT id FROM expense_categories WHERE name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = "Category with this name already exists!";
            $message_type = 'danger';
        } else {
            $insert_sql = "INSERT INTO expense_categories (name, description, category_type, color_code, budget_amount, is_active, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param('ssssdi', $name, $description, $category_type, $color_code, $budget_amount, $is_active);
            
            if ($insert_stmt->execute()) {
                $message = "Category added successfully!";
                $message_type = 'success';
                logActivity($_SESSION['user_id'], 'expense_category_add', "Added new expense category: $name");
            } else {
                $message = "Error adding category: " . $conn->error;
                $message_type = 'danger';
            }
        }
    } else {
        $message = "Category name is required!";
        $message_type = 'danger';
    }
}

// Update category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $id = $_POST['id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $category_type = $_POST['category_type'];
    $color_code = $_POST['color_code'];
    $budget_amount = $_POST['budget_amount'] ?? 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($name)) {
        // Check if category name conflicts with another category
        $check_sql = "SELECT id FROM expense_categories WHERE name = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('si', $name, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = "Another category with this name already exists!";
            $message_type = 'danger';
        } else {
            $update_sql = "UPDATE expense_categories SET 
                          name = ?, description = ?, category_type = ?, 
                          color_code = ?, budget_amount = ?, is_active = ?,
                          updated_at = NOW()
                          WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param('ssssdii', $name, $description, $category_type, $color_code, $budget_amount, $is_active, $id);
            
            if ($update_stmt->execute()) {
                $message = "Category updated successfully!";
                $message_type = 'success';
                logActivity($_SESSION['user_id'], 'expense_category_update', "Updated expense category: $name (ID: $id)");
            } else {
                $message = "Error updating category: " . $conn->error;
                $message_type = 'danger';
            }
        }
    } else {
        $message = "Category name is required!";
        $message_type = 'danger';
    }
}

// Delete category
if ($action === 'delete' && $id > 0) {
    // Check if category is used in expenses
    $check_sql = "SELECT COUNT(*) as count FROM expenses WHERE category_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    
    if ($check_data['count'] > 0) {
        $message = "Cannot delete category: It has associated expenses!";
        $message_type = 'danger';
    } else {
        $delete_sql = "DELETE FROM expense_categories WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param('i', $id);
        
        if ($delete_stmt->execute()) {
            $message = "Category deleted successfully!";
            $message_type = 'success';
            logActivity($_SESSION['user_id'], 'expense_category_delete', "Deleted expense category ID: $id");
        } else {
            $message = "Error deleting category: " . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Toggle category status
if ($action === 'toggle_status' && $id > 0) {
    $status_sql = "SELECT is_active FROM expense_categories WHERE id = ?";
    $status_stmt = $conn->prepare($status_sql);
    $status_stmt->bind_param('i', $id);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
    $status_data = $status_result->fetch_assoc();
    
    $new_status = $status_data['is_active'] ? 0 : 1;
    
    $toggle_sql = "UPDATE expense_categories SET is_active = ?, updated_at = NOW() WHERE id = ?";
    $toggle_stmt = $conn->prepare($toggle_sql);
    $toggle_stmt->bind_param('ii', $new_status, $id);
    
    if ($toggle_stmt->execute()) {
        $message = "Category status updated!";
        $message_type = 'success';
        logActivity($_SESSION['user_id'], 'expense_category_toggle', "Toggled expense category status ID: $id to " . ($new_status ? 'Active' : 'Inactive'));
    } else {
        $message = "Error updating category status: " . $conn->error;
        $message_type = 'danger';
    }
}

// Get category for editing
$edit_category = null;
if ($action === 'edit' && $id > 0) {
    $edit_sql = "SELECT * FROM expense_categories WHERE id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param('i', $id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_category = $edit_result->fetch_assoc();
}

// Get all categories with statistics
$categories_sql = "SELECT c.*, 
                  COUNT(e.id) as expense_count,
                  COALESCE(SUM(CASE WHEN e.status IN ('approved', 'paid') THEN e.amount ELSE 0 END), 0) as total_spent
                  FROM expense_categories c
                  LEFT JOIN expenses e ON e.category_id = c.id
                  GROUP BY c.id
                  ORDER BY c.name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Category types for dropdown
$category_types = ['operational', 'fixed', 'variable', 'tithe', 'reserve', 'other'];

// Predefined colors for easy selection
$color_options = [
    '#007bff' => 'Blue',
    '#28a745' => 'Green',
    '#dc3545' => 'Red',
    '#ffc107' => 'Yellow',
    '#6f42c1' => 'Purple',
    '#fd7e14' => 'Orange',
    '#20c997' => 'Teal',
    '#e83e8c' => 'Pink',
    '#17a2b8' => 'Cyan',
    '#343a40' => 'Dark Gray'
];

// Log activity
logActivity($_SESSION['user_id'], 'expense_categories', "Accessed expense categories page");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Categories - Admin Portal</title>
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
            overflow-x: hidden;
        }

        /* Mobile-first approach */
        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile Header */
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

        /* Sidebar */
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
            flex-wrap: wrap;
        }

        .header h1 i {
            color: var(--primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            align-self: flex-end;
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
            flex-shrink: 0;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
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

        /* Form Card */
        .form-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .form-card h3 {
            margin-bottom: 1.5rem;
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
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

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
        }

        .form-check-label {
            color: #64748b;
            font-size: 0.9rem;
        }

        /* Color Picker */
        .color-picker {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }

        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: transform 0.2s, border-color 0.2s;
        }

        .color-option:hover {
            transform: scale(1.1);
        }

        .color-option.selected {
            border-color: var(--dark);
            transform: scale(1.1);
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

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            min-height: auto;
        }

        /* Categories Table */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .table-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
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

        .status-badge.active { 
            background: #d1fae5; 
            color: #065f46; 
        }
        .status-badge.inactive { 
            background: #f1f5f9; 
            color: #64748b; 
        }

        /* Category Type Badges */
        .category-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-operational { background: #dbeafe; color: #1e40af; }
        .type-fixed { background: #fee2e2; color: #991b1b; }
        .type-variable { background: #fef3c7; color: #92400e; }
        .type-tithe { background: #d1fae5; color: #065f46; }
        .type-reserve { background: #e0e7ff; color: #3730a3; }
        .type-other { background: #f1f5f9; color: #64748b; }

        /* Color Preview */
        .color-preview {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            vertical-align: middle;
            margin-right: 0.5rem;
            border: 1px solid #e2e8f0;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            z-index: 1001;
            align-items: center;
            justify-content: center;
            padding: 1rem;
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
            font-size: 1.1rem;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
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

            .form-row {
                gap: 1.5rem;
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

            .form-card {
                padding: 1rem;
            }

            .form-group {
                min-width: 100%;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .user-info {
                align-self: stretch;
                justify-content: space-between;
            }
        }

        /* Touch-friendly improvements */
        .btn, .form-control, select {
            min-height: 48px;
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

        /* Mobile menu animation */
        @keyframes slideIn {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
            }
        }

        .sidebar.active {
            animation: slideIn 0.3s ease;
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
                <i class="fas fa-tags"></i>
                Expense Categories
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/categories.php" class="active">
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
                    <i class="fas fa-tags"></i>
                    Expense Categories Management
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

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Total Categories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $active_count = array_reduce($categories, function($carry, $item) {
                            return $carry + ($item['is_active'] ? 1 : 0);
                        }, 0);
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stat-label">Active Categories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $inactive_count = count($categories) - $active_count;
                        echo $inactive_count;
                        ?>
                    </div>
                    <div class="stat-label">Inactive Categories</div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Add/Edit Category Form -->
            <div class="form-card">
                <h3>
                    <i class="fas fa-<?php echo $edit_category ? 'edit' : 'plus-circle'; ?>"></i>
                    <?php echo $edit_category ? 'Edit Category' : 'Add New Category'; ?>
                </h3>
                <form method="POST" id="categoryForm">
                    <?php if ($edit_category): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_category['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Category Name *</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                value="<?php echo $edit_category ? htmlspecialchars($edit_category['name']) : ''; ?>"
                                required>
                        </div>
                        
                        <div class="form-group">
                            <label for="category_type">Category Type *</label>
                            <select id="category_type" name="category_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <?php foreach ($category_types as $type): ?>
                                    <option value="<?php echo $type; ?>" 
                                        <?php echo ($edit_category && $edit_category['category_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"><?php echo $edit_category ? htmlspecialchars($edit_category['description']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="budget_amount">Budget Amount (₦)</label>
                            <input type="number" id="budget_amount" name="budget_amount" class="form-control" 
                                value="<?php echo $edit_category ? $edit_category['budget_amount'] : '0'; ?>"
                                step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Color *</label>
                            <input type="text" id="color_code" name="color_code" class="form-control" 
                                value="<?php echo $edit_category ? $edit_category['color_code'] : '#007bff'; ?>"
                                required pattern="^#[0-9A-Fa-f]{6}$">
                            <div class="color-picker">
                                <?php foreach ($color_options as $color => $name): ?>
                                    <div class="color-option <?php echo ($edit_category && $edit_category['color_code'] == $color) || (!$edit_category && $color == '#007bff') ? 'selected' : ''; ?>" 
                                         style="background-color: <?php echo $color; ?>;"
                                         data-color="<?php echo $color; ?>"
                                         title="<?php echo $name; ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="is_active" name="is_active" class="form-check-input" 
                            <?php echo !$edit_category || $edit_category['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active" class="form-check-label">Active Category</label>
                    </div>
                    
                    <div style="margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button type="submit" name="<?php echo $edit_category ? 'update_category' : 'add_category'; ?>" 
                                class="btn btn-primary">
                            <i class="fas fa-<?php echo $edit_category ? 'save' : 'plus'; ?>"></i>
                            <?php echo $edit_category ? 'Update Category' : 'Add Category'; ?>
                        </button>
                        
                        <?php if ($edit_category): ?>
                            <a href="categories.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel Edit
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Categories Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> All Expense Categories</h3>
                    <div style="font-size: 0.9rem; color: #64748b;">
                        <?php echo count($categories); ?> categories found
                    </div>
                </div>
                
                <?php if (count($categories) > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Budget</th>
                                    <th>Spent</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): 
                                    $remaining = $category['budget_amount'] - $category['total_spent'];
                                    $spent_percentage = $category['budget_amount'] > 0 ? ($category['total_spent'] / $category['budget_amount']) * 100 : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div class="color-preview" style="background-color: <?php echo $category['color_code']; ?>;"></div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                                    <?php if ($category['description']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($category['description'], 0, 50)); ?>
                                                            <?php if (strlen($category['description']) > 50): ?>...<?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="category-type-badge type-<?php echo $category['category_type']; ?>">
                                                <?php echo ucfirst($category['category_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo formatCurrency($category['budget_amount']); ?></strong>
                                                <div style="font-size: 0.85rem; color: #64748b;">
                                                    <?php echo $category['expense_count']; ?> expenses
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo formatCurrency($category['total_spent']); ?></strong>
                                                <div style="font-size: 0.85rem; color: #64748b;">
                                                    <?php echo round($spent_percentage, 1); ?>% of budget
                                                </div>
                                                <?php if ($category['budget_amount'] > 0): ?>
                                                    <div style="height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 0.25rem; overflow: hidden;">
                                                        <div style="width: <?php echo min($spent_percentage, 100); ?>%; height: 100%; 
                                                             background: <?php echo $spent_percentage > 90 ? '#ef4444' : ($spent_percentage > 70 ? '#f59e0b' : '#10b981'); ?>;">
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $category['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="categories.php?action=edit&id=<?php echo $category['id']; ?>" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <a href="categories.php?action=toggle_status&id=<?php echo $category['id']; ?>" 
                                                   class="btn btn-sm btn-<?php echo $category['is_active'] ? 'warning' : 'success'; ?>">
                                                    <i class="fas fa-<?php echo $category['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                </a>
                                                
                                                <?php if ($category['expense_count'] == 0): ?>
                                                    <button class="btn btn-sm btn-danger delete-btn" 
                                                            data-id="<?php echo $category['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($category['name']); ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="btn btn-sm btn-secondary" style="cursor: not-allowed;" 
                                                          title="Cannot delete: Has <?php echo $category['expense_count']; ?> expenses">
                                                        <i class="fas fa-trash"></i>
                                                    </span>
                                                <?php endif; ?>
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
                        <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">No Categories Found</h3>
                        <p>Start by adding your first expense category above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the category "<strong id="deleteCategoryName"></strong>"?</p>
                <p class="text-danger"><i class="fas fa-exclamation-circle"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="cancelDelete">Cancel</button>
                <a href="#" class="btn btn-danger" id="confirmDelete">Delete Category</a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        // Color picker
        document.querySelectorAll('.color-option').forEach(option => {
            option.addEventListener('click', () => {
                const color = option.getAttribute('data-color');
                document.getElementById('color_code').value = color;
                
                // Update selected state
                document.querySelectorAll('.color-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');
            });
        });

        // Delete confirmation modal
        const deleteModal = document.getElementById('deleteModal');
        const modalClose = document.getElementById('modalClose');
        const cancelDelete = document.getElementById('cancelDelete');
        const confirmDelete = document.getElementById('confirmDelete');
        const deleteCategoryName = document.getElementById('deleteCategoryName');

        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const id = btn.getAttribute('data-id');
                const name = btn.getAttribute('data-name');
                
                deleteCategoryName.textContent = name;
                confirmDelete.href = `categories.php?action=delete&id=${id}`;
                deleteModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });

        function closeModal() {
            deleteModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        modalClose.addEventListener('click', closeModal);
        cancelDelete.addEventListener('click', closeModal);
        sidebarOverlay.addEventListener('click', closeModal);

        // Close modal when clicking outside
        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && deleteModal.classList.contains('active')) {
                closeModal();
            }
        });

        // Form validation
        document.getElementById('categoryForm')?.addEventListener('submit', (e) => {
            const name = document.getElementById('name').value.trim();
            const categoryType = document.getElementById('category_type').value;
            const colorCode = document.getElementById('color_code').value;
            
            if (!name) {
                e.preventDefault();
                alert('Category name is required!');
                document.getElementById('name').focus();
                return;
            }
            
            if (!categoryType) {
                e.preventDefault();
                alert('Category type is required!');
                document.getElementById('category_type').focus();
                return;
            }
            
            if (!/^#[0-9A-Fa-f]{6}$/.test(colorCode)) {
                e.preventDefault();
                alert('Please select a valid color!');
                return;
            }
        });

        // Prevent body scroll when modal is open
        function preventBodyScroll() {
            if (window.innerWidth < 768 && (sidebar.classList.contains('active') || deleteModal.classList.contains('active'))) {
                document.body.style.overflow = 'hidden';
            } else if (deleteModal.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else if (sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
        
        window.addEventListener('resize', preventBodyScroll);
        
        // Auto-focus name field if adding new category
        <?php if (!$edit_category): ?>
            window.addEventListener('DOMContentLoaded', () => {
                document.getElementById('name')?.focus();
            });
        <?php endif; ?>

        // Show current color in color picker when editing
        <?php if ($edit_category): ?>
            window.addEventListener('DOMContentLoaded', () => {
                const currentColor = '<?php echo $edit_category['color_code']; ?>';
                document.querySelectorAll('.color-option').forEach(option => {
                    if (option.getAttribute('data-color') === currentColor) {
                        option.classList.add('selected');
                    } else {
                        option.classList.remove('selected');
                    }
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>
<?php $conn->close(); ?>