<?php
// modules/admin/finance/expenses/budgets.php

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

// Add new budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_budget') {
        $category_id = $_POST['category_id'];
        $month = $_POST['month'];
        $budget_amount = $_POST['budget_amount'];
        
        // Validate month format (YYYY-MM)
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $message = 'Invalid month format. Use YYYY-MM.';
            $message_type = 'error';
        } elseif ($budget_amount <= 0) {
            $message = 'Budget amount must be greater than 0.';
            $message_type = 'error';
        } else {
            // Check if budget already exists for this category and month
            $check_sql = "SELECT id FROM expense_budgets WHERE category_id = ? AND month = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('is', $category_id, $month . '-01');
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = 'A budget already exists for this category and month.';
                $message_type = 'warning';
            } else {
                // Insert new budget
                $insert_sql = "INSERT INTO expense_budgets (category_id, month, budget_amount, created_by, created_at) 
                               VALUES (?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $full_month = $month . '-01';
                $insert_stmt->bind_param('isd', $category_id, $full_month, $budget_amount, $_SESSION['user_id']);
                
                if ($insert_stmt->execute()) {
                    $message = 'Budget added successfully!';
                    $message_type = 'success';
                    
                    // Log activity
                    logActivity($_SESSION['user_id'], 'budget_add', "Added budget for category ID: $category_id, month: $month, amount: $budget_amount");
                } else {
                    $message = 'Error adding budget: ' . $conn->error;
                    $message_type = 'error';
                }
            }
        }
    }
    
    // Update existing budget
    if ($action === 'update_budget') {
        $budget_id = $_POST['budget_id'];
        $budget_amount = $_POST['budget_amount'];
        
        if ($budget_amount <= 0) {
            $message = 'Budget amount must be greater than 0.';
            $message_type = 'error';
        } else {
            $update_sql = "UPDATE expense_budgets SET budget_amount = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param('di', $budget_amount, $budget_id);
            
            if ($update_stmt->execute()) {
                $message = 'Budget updated successfully!';
                $message_type = 'success';
                
                // Log activity
                logActivity($_SESSION['user_id'], 'budget_update', "Updated budget ID: $budget_id, new amount: $budget_amount");
            } else {
                $message = 'Error updating budget: ' . $conn->error;
                $message_type = 'error';
            }
        }
    }
    
    // Delete budget
    if ($action === 'delete_budget') {
        $budget_id = $_POST['budget_id'];
        
        $delete_sql = "DELETE FROM expense_budgets WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param('i', $budget_id);
        
        if ($delete_stmt->execute()) {
            $message = 'Budget deleted successfully!';
            $message_type = 'success';
            
            // Log activity
            logActivity($_SESSION['user_id'], 'budget_delete', "Deleted budget ID: $budget_id");
        } else {
            $message = 'Error deleting budget: ' . $conn->error;
            $message_type = 'error';
        }
    }
}

// Get filter parameters
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_category = $_GET['category_id'] ?? '';
$filter_year = $_GET['year'] ?? date('Y');

// Get expense categories for dropdown
$categories_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get budgets with actual expenses
$budgets_sql = "SELECT 
                    eb.id as budget_id,
                    eb.category_id,
                    eb.month,
                    eb.budget_amount,
                    eb.actual_amount,
                    eb.created_at,
                    ec.name as category_name,
                    ec.category_type,
                    ec.color_code,
                    u.first_name as created_by_name,
                    u.last_name as created_by_lastname,
                    (eb.budget_amount - COALESCE(eb.actual_amount, 0)) as remaining_amount,
                    CASE 
                        WHEN eb.actual_amount = 0 THEN 0
                        ELSE (eb.actual_amount / eb.budget_amount) * 100 
                    END as utilization_percentage,
                    CASE 
                        WHEN eb.budget_amount > 0 AND eb.actual_amount > eb.budget_amount THEN 'over'
                        WHEN eb.budget_amount > 0 AND eb.actual_amount / eb.budget_amount >= 0.8 THEN 'warning'
                        ELSE 'normal'
                    END as budget_status
                FROM expense_budgets eb
                JOIN expense_categories ec ON ec.id = eb.category_id
                JOIN users u ON u.id = eb.created_by
                WHERE 1=1";

$params = [];
$param_types = '';

if ($filter_month) {
    $budgets_sql .= " AND DATE_FORMAT(eb.month, '%Y-%m') = ?";
    $params[] = $filter_month;
    $param_types .= 's';
}

if ($filter_category) {
    $budgets_sql .= " AND eb.category_id = ?";
    $params[] = $filter_category;
    $param_types .= 'i';
}

$budgets_sql .= " ORDER BY eb.month DESC, ec.name ASC";

$budgets_stmt = $conn->prepare($budgets_sql);
if (!empty($params)) {
    $budgets_stmt->bind_param($param_types, ...$params);
}
$budgets_stmt->execute();
$budgets_result = $budgets_stmt->get_result();
$budgets = $budgets_result->fetch_all(MYSQLI_ASSOC);

// Get monthly summary
$summary_sql = "SELECT 
                    DATE_FORMAT(eb.month, '%Y-%m') as month,
                    COUNT(eb.id) as total_budgets,
                    SUM(eb.budget_amount) as total_budget,
                    SUM(eb.actual_amount) as total_actual,
                    AVG(CASE 
                        WHEN eb.budget_amount > 0 THEN (eb.actual_amount / eb.budget_amount) * 100 
                        ELSE 0 
                    END) as avg_utilization
                FROM expense_budgets eb
                WHERE YEAR(eb.month) = ?
                GROUP BY DATE_FORMAT(eb.month, '%Y-%m')
                ORDER BY month DESC";

$summary_stmt = $conn->prepare($summary_sql);
$summary_stmt->bind_param('s', $filter_year);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$monthly_summary = $summary_result->fetch_all(MYSQLI_ASSOC);

// Get category summary for current month
$category_summary_sql = "SELECT 
                            ec.id,
                            ec.name,
                            ec.category_type,
                            ec.color_code,
                            COALESCE(eb.budget_amount, 0) as budget_amount,
                            COALESCE(eb.actual_amount, 0) as actual_amount,
                            COALESCE(ec.budget_amount, 0) as default_budget
                         FROM expense_categories ec
                         LEFT JOIN expense_budgets eb ON eb.category_id = ec.id 
                             AND DATE_FORMAT(eb.month, '%Y-%m') = ?
                         WHERE ec.is_active = 1
                         ORDER BY ec.name";

$category_summary_stmt = $conn->prepare($category_summary_sql);
$current_month = date('Y-m');
$category_summary_stmt->bind_param('s', $current_month);
$category_summary_stmt->execute();
$category_summary_result = $category_summary_stmt->get_result();
$category_summary = $category_summary_result->fetch_all(MYSQLI_ASSOC);

// Get available years for filter
$years_sql = "SELECT DISTINCT YEAR(month) as year FROM expense_budgets ORDER BY year DESC";
$years_result = $conn->query($years_sql);
$years = $years_result->fetch_all(MYSQLI_ASSOC);

// Calculate totals for current view
$total_budget = 0;
$total_actual = 0;
foreach ($budgets as $budget) {
    $total_budget += $budget['budget_amount'];
    $total_actual += $budget['actual_amount'];
}
$total_remaining = $total_budget - $total_actual;
$overall_utilization = $total_budget > 0 ? ($total_actual / $total_budget) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Budgets - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            --revenue: #10b981;
            --pending: #f59e0b;
            --overdue: #ef4444;
            --issues: #8b5cf6;
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

        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

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

        .filters-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 0;
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
        }

        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

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
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
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
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
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

        .stat-number {
            font-size: 1.75rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-icon {
            font-size: 1.75rem;
            opacity: 0.2;
            color: inherit;
        }

        .content-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
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

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        .table-container {
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

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .budget-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .budget-status.normal {
            background: #d1fae5;
            color: #065f46;
        }

        .budget-status.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .budget-status.over {
            background: #fee2e2;
            color: #991b1b;
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

        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
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

            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
                align-items: end;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-buttons {
                flex-direction: column;
            }

            .filter-buttons .btn {
                width: 100%;
            }

            .user-info {
                align-self: stretch;
                justify-content: space-between;
            }
        }

        .budget-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
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
                <i class="fas fa-chart-pie"></i>
                Expense Budgets
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/budgets.php" class="active">
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
                    <i class="fas fa-chart-pie"></i>
                    Expense Budget Management
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
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'danger'); ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Budget (Current)</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($total_budget); ?>
                        <i class="fas fa-wallet stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        For <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-label">Actual Expenses</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($total_actual); ?>
                        <i class="fas fa-receipt stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        <?php echo round($overall_utilization, 1); ?>% utilized
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-label">Remaining Budget</div>
                    <div class="stat-number">
                        <?php echo formatCurrency($total_remaining); ?>
                        <i class="fas fa-piggy-bank stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        Available for spending
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-label">Active Budgets</div>
                    <div class="stat-number">
                        <?php echo count($budgets); ?>
                        <i class="fas fa-clipboard-list stat-icon"></i>
                    </div>
                    <div class="stat-trend">
                        For current filter
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Budgets</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Month</label>
                        <input type="month" name="month" class="form-control" 
                               value="<?php echo $filter_month; ?>" 
                               max="<?php echo date('Y-m'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Year (Summary)</label>
                        <select name="year" class="form-control">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" 
                                    <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                            <button type="button" class="btn btn-success" id="addBudgetBtn">
                                <i class="fas fa-plus"></i> Add New Budget
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Budgets Table -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Budget List</h3>
                    <div>
                        <span style="font-size: 0.9rem; color: #64748b;">
                            <?php echo count($budgets); ?> budget(s) found
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($budgets)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Category</th>
                                        <th>Budget Amount</th>
                                        <th>Actual Expenses</th>
                                        <th>Remaining</th>
                                        <th>Utilization</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($budgets as $budget): 
                                        $utilization = $budget['utilization_percentage'];
                                        $status_class = $budget['budget_status'];
                                        $status_text = $status_class === 'over' ? 'Over Budget' : ($status_class === 'warning' ? 'Approaching Limit' : 'Normal');
                                    ?>
                                        <tr>
                                            <td>
                                                <?php echo date('M Y', strtotime($budget['month'])); ?>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                    <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo $budget['color_code']; ?>;"></span>
                                                    <?php echo htmlspecialchars($budget['category_name']); ?>
                                                    <span class="badge"><?php echo $budget['category_type']; ?></span>
                                                </span>
                                            </td>
                                            <td class="amount" style="font-weight: 600;">
                                                <?php echo formatCurrency($budget['budget_amount']); ?>
                                            </td>
                                            <td>
                                                <?php echo formatCurrency($budget['actual_amount']); ?>
                                            </td>
                                            <td>
                                                <?php echo formatCurrency($budget['remaining_amount']); ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <span><?php echo round($utilization, 1); ?>%</span>
                                                    <div class="progress-bar" style="flex: 1; max-width: 100px;">
                                                        <div class="progress-fill" 
                                                             style="width: <?php echo min($utilization, 100); ?>%; 
                                                                    background: <?php echo $status_class === 'over' ? '#ef4444' : ($status_class === 'warning' ? '#f59e0b' : '#10b981'); ?>;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="budget-status <?php echo $status_class; ?>">
                                                    <?php if ($status_class === 'over'): ?>
                                                        <i class="fas fa-exclamation-circle"></i>
                                                    <?php elseif ($status_class === 'warning'): ?>
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-check-circle"></i>
                                                    <?php endif; ?>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($budget['created_by_name'] . ' ' . $budget['created_by_lastname']); ?>
                                                <br>
                                                <small style="color: #64748b; font-size: 0.8rem;">
                                                    <?php echo date('M j, Y', strtotime($budget['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn btn-sm btn-primary edit-budget-btn"
                                                            data-budget-id="<?php echo $budget['budget_id']; ?>"
                                                            data-budget-amount="<?php echo $budget['budget_amount']; ?>"
                                                            data-category-id="<?php echo $budget['category_id']; ?>"
                                                            data-month="<?php echo date('Y-m', strtotime($budget['month'])); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger delete-budget-btn"
                                                            data-budget-id="<?php echo $budget['budget_id']; ?>"
                                                            data-category-name="<?php echo htmlspecialchars($budget['category_name']); ?>"
                                                            data-month="<?php echo date('M Y', strtotime($budget['month'])); ?>">
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
                            <i class="fas fa-chart-pie"></i>
                            <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">No Budgets Found</h3>
                            <p style="margin-bottom: 1.5rem;">No budgets match the selected filters.</p>
                            <button type="button" class="btn btn-primary" id="addFirstBudgetBtn">
                                <i class="fas fa-plus"></i> Create Your First Budget
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Category Summary -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Category Budget Summary</h3>
                    <span style="font-size: 0.9rem; color: #64748b;">Current Month (<?php echo date('F Y'); ?>)</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($category_summary)): ?>
                        <div style="display: grid; gap: 1rem;">
                            <?php foreach ($category_summary as $category): 
                                $budget = $category['budget_amount'] > 0 ? $category['budget_amount'] : $category['default_budget'];
                                $actual = $category['actual_amount'];
                                $utilization = $budget > 0 ? ($actual / $budget) * 100 : 0;
                                $has_budget = $category['budget_amount'] > 0;
                            ?>
                                <div style="padding: 1rem; background: #f8fafc; border-radius: 6px; border-left: 4px solid <?php echo $category['color_code']; ?>;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem; flex: 1;">
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($category['name']); ?></span>
                                            <span class="badge"><?php echo $category['category_type']; ?></span>
                                            <?php if (!$has_budget && $category['default_budget'] > 0): ?>
                                                <span class="badge badge-info">Default Budget</span>
                                            <?php elseif (!$has_budget): ?>
                                                <span class="badge badge-warning">No Budget Set</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 600; font-size: 1.1rem;">
                                                <?php echo formatCurrency($actual); ?>
                                                <span style="font-size: 0.9rem; color: #64748b;">of <?php echo formatCurrency($budget); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress-bar">
                                        <div class="progress-fill" 
                                             style="width: <?php echo min($utilization, 100); ?>%; 
                                                    background: <?php echo $utilization > 100 ? '#ef4444' : ($utilization > 80 ? '#f59e0b' : '#10b981'); ?>;">
                                        </div>
                                    </div>
                                    
                                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #64748b; margin-top: 0.5rem;">
                                        <span><?php echo round($utilization, 1); ?>% utilized</span>
                                        <span><?php echo formatCurrency($budget - $actual); ?> remaining</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h3 style="font-size: 1.1rem;">No Categories Found</h3>
                            <p>No expense categories have been set up yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monthly Summary -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar-alt"></i> Monthly Budget Summary</h3>
                    <span style="font-size: 0.9rem; color: #64748b;">Year: <?php echo $filter_year; ?></span>
                </div>
                <div class="card-body">
                    <?php if (!empty($monthly_summary)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Number of Budgets</th>
                                        <th>Total Budget</th>
                                        <th>Actual Expenses</th>
                                        <th>Difference</th>
                                        <th>Avg Utilization</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_summary as $summary): 
                                        $diff = $summary['total_budget'] - $summary['total_actual'];
                                        $avg_util = $summary['avg_utilization'];
                                        $status_class = $avg_util > 100 ? 'danger' : ($avg_util > 80 ? 'warning' : 'success');
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('F Y', strtotime($summary['month'] . '-01')); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo $summary['total_budgets']; ?>
                                            </td>
                                            <td class="amount" style="font-weight: 600;">
                                                <?php echo formatCurrency($summary['total_budget']); ?>
                                            </td>
                                            <td>
                                                <?php echo formatCurrency($summary['total_actual']); ?>
                                            </td>
                                            <td class="amount" style="color: <?php echo $diff >= 0 ? '#10b981' : '#ef4444'; ?>; font-weight: 600;">
                                                <?php echo ($diff >= 0 ? '+' : '') . formatCurrency($diff); ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <span><?php echo round($avg_util, 1); ?>%</span>
                                                    <div class="progress-bar" style="flex: 1; max-width: 100px;">
                                                        <div class="progress-fill" 
                                                             style="width: <?php echo min($avg_util, 100); ?>%; 
                                                                    background: <?php echo $status_class === 'danger' ? '#ef4444' : ($status_class === 'warning' ? '#f59e0b' : '#10b981'); ?>;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="budget-status <?php echo $status_class; ?>">
                                                    <?php if ($status_class === 'danger'): ?>
                                                        <i class="fas fa-exclamation-circle"></i> Over Budget
                                                    <?php elseif ($status_class === 'warning'): ?>
                                                        <i class="fas fa-exclamation-triangle"></i> High Usage
                                                    <?php else: ?>
                                                        <i class="fas fa-check-circle"></i> Within Budget
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <h3 style="font-size: 1.1rem;">No Monthly Data</h3>
                            <p>No budget data available for the selected year.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Budget Modal -->
    <div class="modal" id="budgetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Budget</h3>
                <button type="button" class="modal-close" id="closeModalBtn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="budgetForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_budget">
                    <input type="hidden" name="budget_id" id="budgetId" value="">
                    
                    <div class="form-group">
                        <label for="category_id">Expense Category *</label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['category_type']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="month">Month *</label>
                        <input type="month" name="month" id="month" class="form-control" 
                               value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="budget_amount">Budget Amount *</label>
                        <input type="number" name="budget_amount" id="budget_amount" 
                               class="form-control" step="0.01" min="0.01" required
                               placeholder="Enter budget amount">
                    </div>
                    
                    <div id="budgetInfo" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 6px;">
                        <p style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #64748b;">
                            <i class="fas fa-info-circle"></i> 
                            <span id="budgetInfoText"></span>
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelModalBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitModalBtn">
                        <i class="fas fa-save"></i> Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle text-danger"></i> Confirm Delete</h3>
                <button type="button" class="modal-close" id="closeDeleteModalBtn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="deleteForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_budget">
                    <input type="hidden" name="budget_id" id="deleteBudgetId" value="">
                    
                    <p id="deleteMessage">Are you sure you want to delete this budget?</p>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This action cannot be undone. All budget data will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelDeleteBtn">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Budget
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
        
        // Modal functionality
        const budgetModal = document.getElementById('budgetModal');
        const deleteModal = document.getElementById('deleteModal');
        const addBudgetBtn = document.getElementById('addBudgetBtn');
        const addFirstBudgetBtn = document.getElementById('addFirstBudgetBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const closeDeleteModalBtn = document.getElementById('closeDeleteModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
        
        // Open add budget modal
        function openBudgetModal(isEdit = false, budgetData = null) {
            const modalTitle = document.getElementById('modalTitle');
            const formAction = document.getElementById('formAction');
            const submitBtn = document.getElementById('submitModalBtn');
            const budgetInfo = document.getElementById('budgetInfo');
            
            if (isEdit && budgetData) {
                modalTitle.textContent = 'Edit Budget';
                formAction.value = 'update_budget';
                document.getElementById('budgetId').value = budgetData.budgetId;
                document.getElementById('category_id').value = budgetData.categoryId;
                document.getElementById('month').value = budgetData.month;
                document.getElementById('budget_amount').value = budgetData.budgetAmount;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Budget';
                
                // Disable category and month for editing
                document.getElementById('category_id').disabled = true;
                document.getElementById('month').disabled = true;
                
                // Show budget info
                budgetInfo.style.display = 'block';
                document.getElementById('budgetInfoText').textContent = 
                    `Editing budget for ${budgetData.categoryName} in ${budgetData.monthName}`;
            } else {
                modalTitle.textContent = 'Add New Budget';
                formAction.value = 'add_budget';
                document.getElementById('budgetId').value = '';
                document.getElementById('category_id').value = '';
                document.getElementById('month').value = '<?php echo date('Y-m'); ?>';
                document.getElementById('budget_amount').value = '';
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Budget';
                
                // Enable category and month
                document.getElementById('category_id').disabled = false;
                document.getElementById('month').disabled = false;
                
                // Hide budget info
                budgetInfo.style.display = 'none';
            }
            
            budgetModal.classList.add('active');
        }
        
        // Open delete confirmation modal
        function openDeleteModal(budgetId, categoryName, month) {
            document.getElementById('deleteBudgetId').value = budgetId;
            document.getElementById('deleteMessage').textContent = 
                `Are you sure you want to delete the budget for "${categoryName}" in ${month}?`;
            deleteModal.classList.add('active');
        }
        
        // Close modals
        function closeAllModals() {
            budgetModal.classList.remove('active');
            deleteModal.classList.remove('active');
            document.getElementById('category_id').disabled = false;
            document.getElementById('month').disabled = false;
        }
        
        // Event listeners
        addBudgetBtn.addEventListener('click', () => openBudgetModal(false));
        if (addFirstBudgetBtn) {
            addFirstBudgetBtn.addEventListener('click', () => openBudgetModal(false));
        }
        
        closeModalBtn.addEventListener('click', closeAllModals);
        closeDeleteModalBtn.addEventListener('click', closeAllModals);
        cancelModalBtn.addEventListener('click', closeAllModals);
        cancelDeleteBtn.addEventListener('click', closeAllModals);
        
        // Close modal when clicking outside
        [budgetModal, deleteModal].forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeAllModals();
                }
            });
        });
        
        // Edit budget buttons
        document.querySelectorAll('.edit-budget-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const budgetId = btn.dataset.budgetId;
                const budgetAmount = btn.dataset.budgetAmount;
                const categoryId = btn.dataset.categoryId;
                const month = btn.dataset.month;
                const categoryName = btn.closest('tr').querySelector('td:nth-child(2)').textContent.trim();
                const monthName = btn.closest('tr').querySelector('td:nth-child(1)').textContent.trim();
                
                openBudgetModal(true, {
                    budgetId: budgetId,
                    budgetAmount: budgetAmount,
                    categoryId: categoryId,
                    month: month,
                    categoryName: categoryName,
                    monthName: monthName
                });
            });
        });
        
        // Delete budget buttons
        document.querySelectorAll('.delete-budget-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const budgetId = btn.dataset.budgetId;
                const categoryName = btn.dataset.categoryName;
                const month = btn.dataset.month;
                
                openDeleteModal(budgetId, categoryName, month);
            });
        });
        
        // Form validation
        document.getElementById('budgetForm').addEventListener('submit', function(e) {
            const budgetAmount = document.getElementById('budget_amount').value;
            const categoryId = document.getElementById('category_id').value;
            const month = document.getElementById('month').value;
            
            if (!categoryId) {
                e.preventDefault();
                alert('Please select a category.');
                return;
            }
            
            if (!month) {
                e.preventDefault();
                alert('Please select a month.');
                return;
            }
            
            if (!budgetAmount || parseFloat(budgetAmount) <= 0) {
                e.preventDefault();
                alert('Please enter a valid budget amount greater than 0.');
                return;
            }
        });
        
        // Initialize date pickers
        flatpickr("input[type='month']", {
            dateFormat: "Y-m",
            allowInput: true,
            disableMobile: false
        });
        
        // Prevent body scroll when modals are open
        function handleModalScroll() {
            if (budgetModal.classList.contains('active') || deleteModal.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
        
        // Observe modal state changes
        const observer = new MutationObserver(handleModalScroll);
        observer.observe(budgetModal, { attributes: true, attributeFilter: ['class'] });
        observer.observe(deleteModal, { attributes: true, attributeFilter: ['class'] });
        
        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Escape key closes modals
            if (e.key === 'Escape') {
                closeAllModals();
            }
            
            // Ctrl/Cmd + N opens new budget modal
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                if (!budgetModal.classList.contains('active') && !deleteModal.classList.contains('active')) {
                    openBudgetModal(false);
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>