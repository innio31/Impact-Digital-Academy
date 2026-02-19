<?php
// modules/admin/finance/expenses/manage.php

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

// Get filter parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'payment_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validate sort order
$sort_order = in_array(strtoupper($sort_order), ['ASC', 'DESC']) ? strtoupper($sort_order) : 'DESC';

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['selected_expenses'])) {
    $selected_ids = $_POST['selected_expenses'];
    $action = $_POST['bulk_action'];
    $user_id = $_SESSION['user_id'];
    
    if (!empty($selected_ids)) {
        $id_list = implode(',', array_map('intval', $selected_ids));
        
        switch ($action) {
            case 'approve':
                $sql = "UPDATE expenses SET status = 'approved', approved_by = ?, approved_at = NOW() 
                        WHERE id IN ($id_list) AND status = 'pending'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $success_message = count($selected_ids) . " expense(s) approved successfully.";
                break;
                
            case 'mark_paid':
                $sql = "UPDATE expenses SET status = 'paid', paid_by = ?, paid_at = NOW() 
                        WHERE id IN ($id_list) AND status = 'approved'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $success_message = count($selected_ids) . " expense(s) marked as paid.";
                break;
                
            case 'cancel':
                $sql = "UPDATE expenses SET status = 'cancelled' WHERE id IN ($id_list) 
                        AND status IN ('pending', 'approved')";
                $conn->query($sql);
                $success_message = count($selected_ids) . " expense(s) cancelled.";
                break;
                
            case 'delete':
                // Only allow deletion of pending or cancelled expenses
                $sql = "DELETE FROM expenses WHERE id IN ($id_list) 
                        AND status IN ('pending', 'cancelled', 'rejected')";
                $conn->query($sql);
                $success_message = count($selected_ids) . " expense(s) deleted.";
                break;
        }
        
        // Log activity
        logActivity($user_id, 'bulk_expense_action', "Performed bulk action '$action' on expenses: $id_list");
    }
}

// Handle individual actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $expense_id = intval($_GET['id']);
    $action = $_GET['action'];
    $user_id = $_SESSION['user_id'];
    
    switch ($action) {
        case 'approve':
            $sql = "UPDATE expenses SET status = 'approved', approved_by = ?, approved_at = NOW() 
                    WHERE id = ? AND status = 'pending'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $user_id, $expense_id);
            $stmt->execute();
            $success_message = "Expense approved successfully.";
            break;
            
        case 'mark_paid':
            $sql = "UPDATE expenses SET status = 'paid', paid_by = ?, paid_at = NOW() 
                    WHERE id = ? AND status = 'approved'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $user_id, $expense_id);
            $stmt->execute();
            $success_message = "Expense marked as paid.";
            break;
            
        case 'cancel':
            $sql = "UPDATE expenses SET status = 'cancelled' WHERE id = ? 
                    AND status IN ('pending', 'approved')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $expense_id);
            $stmt->execute();
            $success_message = "Expense cancelled.";
            break;
            
        case 'delete':
            // Check if expense can be deleted
            $check_sql = "SELECT status FROM expenses WHERE id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('i', $expense_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $expense_data = $check_result->fetch_assoc();
            
            if ($expense_data && in_array($expense_data['status'], ['pending', 'cancelled', 'rejected'])) {
                $delete_sql = "DELETE FROM expenses WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param('i', $expense_id);
                $delete_stmt->execute();
                $success_message = "Expense deleted successfully.";
            } else {
                $error_message = "Cannot delete expense with status: " . $expense_data['status'];
            }
            break;
    }
    
    // Log activity
    logActivity($user_id, 'expense_action', "Performed action '$action' on expense ID: $expense_id");
}

// Build query for expenses
$query = "SELECT e.*, ec.name as category_name, ec.category_type, ec.color_code,
                 u.first_name as created_by_name, u.last_name as created_by_lastname,
                 a.first_name as approved_by_name, a.last_name as approved_by_lastname,
                 p.first_name as paid_by_name, p.last_name as paid_by_lastname
          FROM expenses e
          JOIN expense_categories ec ON ec.id = e.category_id
          JOIN users u ON u.id = e.created_by
          LEFT JOIN users a ON a.id = e.approved_by
          LEFT JOIN users p ON p.id = e.paid_by
          WHERE 1=1";

$count_query = "SELECT COUNT(*) as total FROM expenses e WHERE 1=1";

$params = [];
$types = '';

// Apply filters
if (!empty($search)) {
    $query .= " AND (e.expense_number LIKE ? OR e.description LIKE ? OR e.vendor_name LIKE ?)";
    $count_query .= " AND (e.expense_number LIKE ? OR e.description LIKE ? OR e.vendor_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($category_id)) {
    $query .= " AND e.category_id = ?";
    $count_query .= " AND e.category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}

if (!empty($status)) {
    $query .= " AND e.status = ?";
    $count_query .= " AND e.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($date_from)) {
    $query .= " AND e.payment_date >= ?";
    $count_query .= " AND e.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $query .= " AND e.payment_date <= ?";
    $count_query .= " AND e.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Get total count
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Add sorting and pagination
$valid_sort_columns = ['payment_date', 'created_at', 'amount', 'status'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'payment_date';
}

$query .= " ORDER BY e.$sort_by $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Get expenses
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$expenses = $result->fetch_all(MYSQLI_ASSOC);

// Get expense categories for filter dropdown
$categories_sql = "SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get status counts for tabs
$status_counts_sql = "SELECT status, COUNT(*) as count FROM expenses GROUP BY status";
$status_counts_result = $conn->query($status_counts_sql);
$status_counts = [];
while ($row = $status_counts_result->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Expenses - Admin Portal</title>
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
            --card-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Mobile Header */
        .mobile-header {
            display: none;
            background: var(--sidebar);
            color: white;
            padding: 1rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1001;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .mobile-header h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mobile-header h1 i {
            color: var(--primary);
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Sidebar - Mobile Version */
        .sidebar {
            width: 280px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar.mobile-hidden {
            transform: translateX(-100%);
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

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .main-content.full-width {
            margin-left: 0;
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        /* Header */
        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
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

        /* Status Tabs */
        .status-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .status-tabs::-webkit-scrollbar {
            height: 4px;
        }

        .status-tabs::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 2px;
        }

        .status-tab {
            padding: 0.75rem 1rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .status-tab:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .status-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .status-count {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.125rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .status-tab:not(.active) .status-count {
            background: #e2e8f0;
            color: var(--dark);
        }

        /* Filters Card */
        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .btn-block {
            width: 100%;
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
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-select input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
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
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            vertical-align: top;
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

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-paid { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #f1f5f9; color: #64748b; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        /* Category Badges */
        .expense-category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
            min-width: 40px;
            text-align: center;
        }

        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-info {
            color: #64748b;
            font-size: 0.9rem;
            text-align: center;
            width: 100%;
            margin-bottom: 0.5rem;
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

        /* Mobile Filter Toggle */
        .mobile-filter-toggle {
            display: none;
            width: 100%;
            margin-bottom: 1rem;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 240px;
            }
            
            .main-content {
                margin-left: 240px;
            }
        }

        @media (max-width: 992px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            /* Mobile Header */
            .mobile-header {
                display: flex;
            }
            
            /* Sidebar */
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-visible {
                transform: translateX(0);
            }
            
            .sidebar-overlay.active {
                display: block;
            }
            
            /* Main Content */
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 5rem; /* Account for fixed header */
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }
            
            .user-info {
                align-self: flex-end;
            }
            
            /* Filters */
            .mobile-filter-toggle {
                display: block;
            }
            
            .filters-card {
                display: none;
            }
            
            .filters-card.mobile-visible {
                display: block;
                margin-top: 0.5rem;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            /* Bulk Actions */
            .bulk-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .bulk-actions > div:last-child {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }
            
            /* Table */
            .table-container {
                border-radius: 8px;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            th, td {
                padding: 0.75rem 0.5rem;
            }
            
            /* Status Tabs */
            .status-tabs {
                padding-bottom: 1rem;
            }
            
            .status-tab {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
            
            /* Action Buttons */
            .action-buttons {
                flex-direction: column;
                min-width: 80px;
            }
            
            .btn-sm {
                padding: 0.4rem;
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .status-tab {
                padding: 0.5rem 0.6rem;
                font-size: 0.8rem;
            }
            
            .status-count {
                padding: 0.1rem 0.3rem;
                font-size: 0.7rem;
            }
            
            .page-link {
                padding: 0.4rem 0.6rem;
                min-width: 36px;
            }
            
            .main-content {
                padding: 0.75rem;
                padding-top: 5rem;
            }
            
            .bulk-actions {
                padding: 0.75rem;
            }
        }

        /* Card View for Mobile */
        .card-view {
            display: none;
        }

        @media (max-width: 768px) {
            .table-container {
                overflow-x: visible;
            }
            
            table {
                display: none;
            }
            
            .card-view {
                display: block;
                padding: 1rem;
            }
            
            .expense-card {
                background: white;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 1rem;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                border: 1px solid #e2e8f0;
            }
            
            .expense-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 0.75rem;
                padding-bottom: 0.75rem;
                border-bottom: 1px solid #f1f5f9;
            }
            
            .expense-card-checkbox {
                margin-right: 0.5rem;
            }
            
            .expense-card-title {
                flex: 1;
            }
            
            .expense-card-title h4 {
                color: var(--dark);
                margin-bottom: 0.25rem;
            }
            
            .expense-card-title .expense-number {
                font-weight: 600;
                font-size: 0.9rem;
            }
            
            .expense-card-date {
                color: #64748b;
                font-size: 0.8rem;
            }
            
            .expense-card-details {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
                margin-bottom: 1rem;
            }
            
            .expense-card-detail {
                font-size: 0.85rem;
            }
            
            .expense-card-detail label {
                display: block;
                color: #64748b;
                font-size: 0.75rem;
                margin-bottom: 0.25rem;
                font-weight: 500;
            }
            
            .expense-card-detail .value {
                color: var(--dark);
                font-weight: 500;
            }
            
            .expense-card-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.25rem;
                padding-top: 0.75rem;
                border-top: 1px solid #f1f5f9;
            }
        }

        /* Utility Classes */
        .d-none {
            display: none !important;
        }

        .d-flex {
            display: flex !important;
        }

        .mb-2 {
            margin-bottom: 0.5rem;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
        <h1><i class="fas fa-list"></i> Expenses</h1>
        <div class="user-avatar">
            <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
        </div>
    </div>

    <!-- Sidebar Overlay (for mobile) -->
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
                <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/expenses/manage.php" class="active">
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
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-list"></i>
                Manage Expenses
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
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Status Tabs -->
        <div class="status-tabs">
            <a href="?status=" class="status-tab <?php echo empty($status) ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i>
                All
                <span class="status-count"><?php echo array_sum($status_counts); ?></span>
            </a>
            <a href="?status=pending" class="status-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i>
                Pending
                <span class="status-count"><?php echo $status_counts['pending'] ?? 0; ?></span>
            </a>
            <a href="?status=approved" class="status-tab <?php echo $status === 'approved' ? 'active' : ''; ?>">
                <i class="fas fa-check"></i>
                Approved
                <span class="status-count"><?php echo $status_counts['approved'] ?? 0; ?></span>
            </a>
            <a href="?status=paid" class="status-tab <?php echo $status === 'paid' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                Paid
                <span class="status-count"><?php echo $status_counts['paid'] ?? 0; ?></span>
            </a>
            <a href="?status=cancelled" class="status-tab <?php echo $status === 'cancelled' ? 'active' : ''; ?>">
                <i class="fas fa-ban"></i>
                Cancelled
                <span class="status-count"><?php echo $status_counts['cancelled'] ?? 0; ?></span>
            </a>
        </div>

        <!-- Mobile Filter Toggle -->
        <button class="btn btn-secondary mobile-filter-toggle" id="filterToggle">
            <i class="fas fa-filter"></i> Show Filters
        </button>

        <!-- Filters -->
        <div class="filters-card" id="filtersCard">
            <h3>Filter Expenses</h3>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Expense #, description, vendor..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" class="form-control">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" 
                                <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <div class="form-group">
                    <label>Sort By</label>
                    <select name="sort_by" class="form-control">
                        <option value="payment_date" <?php echo $sort_by === 'payment_date' ? 'selected' : ''; ?>>Payment Date</option>
                        <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="amount" <?php echo $sort_by === 'amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sort Order</label>
                    <select name="sort_order" class="form-control">
                        <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="d-flex" style="gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="manage.php" class="btn btn-secondary btn-block">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk Actions Form -->
        <form method="POST" id="bulkForm" onsubmit="return confirmBulkAction()">
            <div class="bulk-actions">
                <div class="bulk-select">
                    <input type="checkbox" id="selectAll" class="select-all" onclick="toggleSelectAll(this)">
                    <label for="selectAll" style="font-weight: 500;">Select All</label>
                </div>

                <select name="bulk_action" class="form-control" style="flex: 1; min-width: 200px;">
                    <option value="">-- Bulk Actions --</option>
                    <option value="approve">Approve Selected</option>
                    <option value="mark_paid">Mark as Paid</option>
                    <option value="cancel">Cancel Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>

                <button type="submit" class="btn btn-primary" name="apply_bulk_action">
                    <i class="fas fa-play"></i> Apply
                </button>

                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <span class="page-info">
                        Showing <?php echo min($offset + 1, $total_rows); ?>-<?php echo min($offset + $limit, $total_rows); ?> 
                        of <?php echo $total_rows; ?> expenses
                    </span>
                </div>
            </div>

            <!-- Expenses Table (Desktop) -->
            <div class="table-container">
                <?php if (!empty($expenses)): ?>
                    <!-- Desktop Table View -->
                    <table id="desktopTable">
                        <thead>
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" id="tableSelectAll" onclick="toggleTableSelectAll(this)">
                                </th>
                                <th class="sortable" onclick="sortBy('payment_date')">
                                    Date
                                    <?php if ($sort_by === 'payment_date'): ?>
                                        <i class="fas fa-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                    <?php endif; ?>
                                </th>
                                <th>Expense #</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th class="sortable" onclick="sortBy('amount')">
                                    Amount
                                    <?php if ($sort_by === 'amount'): ?>
                                        <i class="fas fa-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                    <?php endif; ?>
                                </th>
                                <th class="sortable" onclick="sortBy('status')">
                                    Status
                                    <?php if ($sort_by === 'status'): ?>
                                        <i class="fas fa-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                    <?php endif; ?>
                                </th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_expenses[]" 
                                               value="<?php echo $expense['id']; ?>" 
                                               class="expense-checkbox desktop-checkbox">
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($expense['payment_date'])); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo $expense['expense_number']; ?></strong>
                                        <?php if ($expense['vendor_name']): ?>
                                            <div class="vendor-info">
                                                <small>Vendor: <?php echo htmlspecialchars($expense['vendor_name']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="expense-category-badge category-<?php echo $expense['category_type']; ?>"
                                              style="background: <?php echo $expense['color_code']; ?>20; color: <?php echo $expense['color_code']; ?>;">
                                            <?php echo htmlspecialchars($expense['category_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars(substr($expense['description'], 0, 60)); ?></div>
                                        <?php if ($expense['receipt_number']): ?>
                                            <div class="vendor-info">
                                                <small>Receipt: <?php echo htmlspecialchars($expense['receipt_number']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount">
                                        <?php echo formatCurrency($expense['amount']); ?>
                                        <span class="currency"><?php echo $expense['currency']; ?></span>
                                        <?php if ($expense['payment_method']): ?>
                                            <div class="vendor-info">
                                                <small><?php echo ucfirst(str_replace('_', ' ', $expense['payment_method'])); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $expense['status']; ?>">
                                            <?php echo ucfirst($expense['status']); ?>
                                        </span>
                                        <?php if ($expense['approved_by_name'] && $expense['status'] == 'approved'): ?>
                                            <div class="vendor-info">
                                                <small>By: <?php echo $expense['approved_by_name'] . ' ' . $expense['approved_by_lastname']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($expense['paid_by_name'] && $expense['status'] == 'paid'): ?>
                                            <div class="vendor-info">
                                                <small>Paid by: <?php echo $expense['paid_by_name'] . ' ' . $expense['paid_by_lastname']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $expense['created_by_name'] . ' ' . $expense['created_by_lastname']; ?>
                                        <div class="vendor-info">
                                            <small><?php echo date('M j, Y', strtotime($expense['created_at'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $expense['id']; ?>" 
                                               class="btn btn-sm btn-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($expense['status'] == 'pending'): ?>
                                                <a href="?action=approve&id=<?php echo $expense['id']; ?>" 
                                                   class="btn btn-sm btn-success" title="Approve"
                                                   onclick="return confirm('Approve this expense?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($expense['status'] == 'approved'): ?>
                                                <a href="?action=mark_paid&id=<?php echo $expense['id']; ?>" 
                                                   class="btn btn-sm btn-info" title="Mark as Paid"
                                                   onclick="return confirm('Mark this expense as paid?')">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($expense['status'], ['pending', 'approved'])): ?>
                                                <a href="?action=cancel&id=<?php echo $expense['id']; ?>" 
                                                   class="btn btn-sm btn-warning" title="Cancel"
                                                   onclick="return confirm('Cancel this expense?')">
                                                    <i class="fas fa-ban"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($expense['status'], ['pending', 'cancelled', 'rejected'])): ?>
                                                <a href="?action=delete&id=<?php echo $expense['id']; ?>" 
                                                   class="btn btn-sm btn-danger" title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this expense? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($expense['receipt_url']): ?>
                                                <a href="<?php echo $expense['receipt_url']; ?>" 
                                                   target="_blank" class="btn btn-sm btn-secondary" title="View Receipt">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Mobile Card View -->
                    <div class="card-view" id="mobileCardView">
                        <?php foreach ($expenses as $expense): ?>
                            <div class="expense-card">
                                <div class="expense-card-header">
                                    <div class="expense-card-checkbox">
                                        <input type="checkbox" name="selected_expenses[]" 
                                               value="<?php echo $expense['id']; ?>" 
                                               class="expense-checkbox mobile-checkbox">
                                    </div>
                                    <div class="expense-card-title">
                                        <h4 class="expense-number"><?php echo $expense['expense_number']; ?></h4>
                                        <div class="expense-card-date">
                                            <?php echo date('M j, Y', strtotime($expense['payment_date'])); ?>
                                        </div>
                                    </div>
                                    <div class="expense-card-status">
                                        <span class="status-badge status-<?php echo $expense['status']; ?>">
                                            <?php echo ucfirst($expense['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="expense-card-details">
                                    <div class="expense-card-detail">
                                        <label>Category</label>
                                        <div class="value">
                                            <span class="expense-category-badge category-<?php echo $expense['category_type']; ?>"
                                                  style="background: <?php echo $expense['color_code']; ?>20; color: <?php echo $expense['color_code']; ?>;">
                                                <?php echo htmlspecialchars($expense['category_name']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="expense-card-detail">
                                        <label>Amount</label>
                                        <div class="value amount">
                                            <?php echo formatCurrency($expense['amount']); ?>
                                            <span class="currency"><?php echo $expense['currency']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="expense-card-detail">
                                        <label>Description</label>
                                        <div class="value">
                                            <?php echo htmlspecialchars(substr($expense['description'], 0, 40)); ?>...
                                        </div>
                                    </div>
                                    
                                    <div class="expense-card-detail">
                                        <label>Created By</label>
                                        <div class="value">
                                            <?php echo $expense['created_by_name'] . ' ' . $expense['created_by_lastname']; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="expense-card-actions">
                                    <a href="view.php?id=<?php echo $expense['id']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    
                                    <?php if ($expense['status'] == 'pending'): ?>
                                        <a href="?action=approve&id=<?php echo $expense['id']; ?>" 
                                           class="btn btn-sm btn-success"
                                           onclick="return confirm('Approve this expense?')">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($expense['status'] == 'approved'): ?>
                                        <a href="?action=mark_paid&id=<?php echo $expense['id']; ?>" 
                                           class="btn btn-sm btn-info"
                                           onclick="return confirm('Mark this expense as paid?')">
                                            <i class="fas fa-money-bill-wave"></i> Paid
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($expense['status'], ['pending', 'approved'])): ?>
                                        <a href="?action=cancel&id=<?php echo $expense['id']; ?>" 
                                           class="btn btn-sm btn-warning"
                                           onclick="return confirm('Cancel this expense?')">
                                            <i class="fas fa-ban"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-list"></i>
                        <h3>No Expenses Found</h3>
                        <p>No expenses match the selected filters.</p>
                        <a href="add.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus-circle"></i> Add New Expense
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div class="page-info">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                </div>
                
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($_GET); ?>" 
                       class="page-link">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>

                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($_GET); ?>" 
                       class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            allowInput: true
        });

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.getElementById('mainContent');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-visible');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('mobile-visible') ? 'hidden' : '';
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-visible');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });

        // Filter toggle for mobile
        const filterToggle = document.getElementById('filterToggle');
        const filtersCard = document.getElementById('filtersCard');

        filterToggle.addEventListener('click', function() {
            filtersCard.classList.toggle('mobile-visible');
            filterToggle.innerHTML = filtersCard.classList.contains('mobile-visible') 
                ? '<i class="fas fa-times"></i> Hide Filters' 
                : '<i class="fas fa-filter"></i> Show Filters';
        });

        // Check if device is mobile
        function isMobileDevice() {
            return window.innerWidth <= 768;
        }

        // Toggle select all checkboxes
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.expense-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            document.getElementById('tableSelectAll').checked = source.checked;
        }

        function toggleTableSelectAll(source) {
            const checkboxes = document.querySelectorAll('.expense-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            document.getElementById('selectAll').checked = source.checked;
        }

        // Update select all when individual checkboxes are clicked
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('expense-checkbox')) {
                const allCheckboxes = document.querySelectorAll('.expense-checkbox');
                const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                document.getElementById('selectAll').checked = allChecked;
                document.getElementById('tableSelectAll').checked = allChecked;
            }
        });

        // Sort function
        function sortBy(column) {
            if (isMobileDevice()) {
                // On mobile, sorting might be done via a dropdown instead
                return;
            }
            
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort_by');
            const currentOrder = url.searchParams.get('sort_order');
            
            let newOrder = 'DESC';
            if (currentSort === column && currentOrder === 'DESC') {
                newOrder = 'ASC';
            }
            
            url.searchParams.set('sort_by', column);
            url.searchParams.set('sort_order', newOrder);
            window.location.href = url.toString();
        }

        // Confirm bulk action
        function confirmBulkAction() {
            const form = document.getElementById('bulkForm');
            const action = form.bulk_action.value;
            const selected = document.querySelectorAll('.expense-checkbox:checked').length;
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (selected === 0) {
                alert('Please select at least one expense.');
                return false;
            }
            
            const actionText = {
                'approve': 'approve',
                'mark_paid': 'mark as paid',
                'cancel': 'cancel',
                'delete': 'delete'
            }[action];
            
            return confirm(`Are you sure you want to ${actionText} ${selected} expense(s)?`);
        }

        // Auto-hide sidebar on mobile when clicking a link
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', function() {
                if (isMobileDevice()) {
                    sidebar.classList.remove('mobile-visible');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Handle window resize
        function handleResize() {
            // On larger screens, ensure sidebar is visible
            if (window.innerWidth > 768) {
                sidebar.classList.remove('mobile-visible');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
                
                // Show filters by default on desktop
                filtersCard.classList.add('mobile-visible');
                filterToggle.innerHTML = '<i class="fas fa-filter"></i> Hide Filters';
            } else {
                // On mobile, hide filters by default
                filtersCard.classList.remove('mobile-visible');
                filterToggle.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            handleResize();
            
            // Auto-submit form when filters change (desktop only)
            if (!isMobileDevice()) {
                document.querySelectorAll('.filter-form select').forEach(select => {
                    select.addEventListener('change', function() {
                        if (this.name !== 'sort_by' && this.name !== 'sort_order') {
                            this.form.submit();
                        }
                    });
                });
            }
        });

        // Handle window resize events
        window.addEventListener('resize', handleResize);
    </script>
</body>
</html>
<?php $conn->close(); ?>