<?php
// modules/admin/finance/services/index.php

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

// Check if service_revenue table exists
$check_table_sql = "SHOW TABLES LIKE 'service_revenue'";
$check_result = $conn->query($check_table_sql);
if (!$check_result || $check_result->num_rows === 0) {
    die('Service revenue table does not exist. Please run the database migrations.');
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 20;
$offset = ($page - 1) * $limit;

// Build query with filters
$sql = "SELECT 
            sr.*,
            sc.name as category_name,
            sc.revenue_type,
            u.first_name as created_by_name,
            u.last_name as created_by_last_name
        FROM service_revenue sr
        LEFT JOIN service_categories sc ON sc.id = sr.service_category_id
        LEFT JOIN users u ON u.id = sr.created_by
        WHERE 1=1";

$params = [];
$types = '';

if (!empty($search)) {
    $sql .= " AND (
        sr.client_name LIKE ? OR 
        sr.client_email LIKE ? OR 
        sr.client_phone LIKE ? OR 
        sr.description LIKE ? OR
        sr.invoice_number LIKE ?
    )";
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term, $search_term, $search_term, $search_term);
    $types .= 'sssss';
}

if (!empty($category_id) && is_numeric($category_id)) {
    $sql .= " AND sr.service_category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}

if (!empty($date_from)) {
    $sql .= " AND sr.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $sql .= " AND sr.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($status) && in_array($status, ['pending', 'completed', 'refunded', 'cancelled'])) {
    $sql .= " AND sr.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Count total records
$count_sql = "SELECT COUNT(*) as total FROM service_revenue sr WHERE 1=1";
$count_params = [];
$count_types = '';

if (!empty($search)) {
    $count_sql .= " AND (
        sr.client_name LIKE ? OR 
        sr.client_email LIKE ? OR 
        sr.client_phone LIKE ? OR 
        sr.description LIKE ? OR
        sr.invoice_number LIKE ?
    )";
    $search_term = "%{$search}%";
    array_push($count_params, $search_term, $search_term, $search_term, $search_term, $search_term);
    $count_types .= 'sssss';
}

if (!empty($category_id) && is_numeric($category_id)) {
    $count_sql .= " AND sr.service_category_id = ?";
    $count_params[] = $category_id;
    $count_types .= 'i';
}

if (!empty($date_from)) {
    $count_sql .= " AND sr.payment_date >= ?";
    $count_params[] = $date_from;
    $count_types .= 's';
}

if (!empty($date_to)) {
    $count_sql .= " AND sr.payment_date <= ?";
    $count_params[] = $date_to;
    $count_types .= 's';
}

if (!empty($status)) {
    $count_sql .= " AND sr.status = ?";
    $count_params[] = $status;
    $count_types .= 's';
}

// Prepare count statement
if ($count_types) {
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $count_result = $stmt->get_result();
} else {
    $count_result = $conn->query($count_sql);
}

$total_count = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_count / $limit);

// Add sorting and pagination
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$valid_sort_columns = ['id', 'client_name', 'amount', 'payment_date', 'created_at'];
if (!in_array($sort_by, $valid_sort_columns)) {
    $sort_by = 'created_at';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

$sql .= " ORDER BY sr.{$sort_by} {$sort_order} LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Prepare main statement
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$service_revenues = $result->fetch_all(MYSQLI_ASSOC);

// Get categories for filter dropdown
$categories_sql = "SELECT * FROM service_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$stats_sql = "SELECT 
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_revenue,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) as cancelled_revenue
              FROM service_revenue";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent transactions
$recent_sql = "SELECT sr.*, sc.name as category_name 
               FROM service_revenue sr 
               LEFT JOIN service_categories sc ON sc.id = sr.service_category_id 
               ORDER BY sr.created_at DESC LIMIT 5";
$recent_result = $conn->query($recent_sql);
$recent_transactions = $recent_result->fetch_all(MYSQLI_ASSOC);

// Get revenue by category
$category_stats_sql = "SELECT 
                        sc.name as category_name,
                        COUNT(sr.id) as transaction_count,
                        COALESCE(SUM(CASE WHEN sr.status = 'completed' THEN sr.amount ELSE 0 END), 0) as total_revenue
                      FROM service_categories sc
                      LEFT JOIN service_revenue sr ON sr.service_category_id = sc.id
                      WHERE sc.is_active = 1
                      GROUP BY sc.id, sc.name
                      ORDER BY total_revenue DESC";
$category_stats_result = $conn->query($category_stats_sql);
$category_stats = $category_stats_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'service_revenue_view', "Accessed service revenue list");

// Process delete request
if (isset($_POST['delete_id']) && isset($_POST['csrf_token']) && validateCSRFToken($_POST['csrf_token'])) {
    $delete_id = intval($_POST['delete_id']);
    
    // Check if revenue record exists
    $check_sql = "SELECT id FROM service_revenue WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $delete_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Delete the record
        $delete_sql = "DELETE FROM service_revenue WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param('i', $delete_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "Service revenue record deleted successfully.";
            logActivity($_SESSION['user_id'], 'service_revenue_delete', "Deleted service revenue record ID: {$delete_id}");
        } else {
            $_SESSION['error_message'] = "Failed to delete service revenue record.";
        }
    } else {
        $_SESSION['error_message'] = "Service revenue record not found.";
    }
    
    header("Location: {$_SERVER['PHP_SELF']}");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Revenue - Finance Admin</title>
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
            --sidebar: #1e293b;
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
            max-width: 1400px;
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

        .stat-card.revenue {
            border-top-color: var(--success);
        }

        .stat-card.pending {
            border-top-color: var(--warning);
        }

        .stat-card.cancelled {
            border-top-color: var(--danger);
        }

        .stat-card.transactions {
            border-top-color: var(--info);
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

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
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

        .card-header h3 {
            color: var(--dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
            overflow-x: auto;
        }

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

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-refunded {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
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

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
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

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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

        .revenue-chart {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }

        .chart-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chart-bar-fill {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
            background: var(--primary);
        }

        .chart-bar-info {
            flex: 1;
        }

        .chart-bar-label {
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .chart-bar-amount {
            font-size: 0.9rem;
            color: #64748b;
        }

        /* Modal styles */
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

        /* Alert messages */
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

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert i {
            font-size: 1.25rem;
        }

        @media (max-width: 768px) {
            .admin-container {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-briefcase"></i>
                Service Revenue Management
            </h1>
            <p>Track non-academic revenue from services, products, and consultancy</p>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="header-actions">
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Add New Revenue
            </a>
            <a href="categories.php" class="btn btn-secondary">
                <i class="fas fa-tags"></i> Manage Categories
            </a>
            <a href="dashboard.php" class="btn btn-info">
                <i class="fas fa-chart-line"></i> Analytics Dashboard
            </a>
            <a href="../dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Finance Dashboard
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-number"><?php echo formatCurrency($stats['total_revenue'] ?? 0); ?></div>
            </div>
            <div class="stat-card pending">
                <div class="stat-label">Pending Revenue</div>
                <div class="stat-number"><?php echo formatCurrency($stats['pending_revenue'] ?? 0); ?></div>
            </div>
            <div class="stat-card cancelled">
                <div class="stat-label">Cancelled Revenue</div>
                <div class="stat-number"><?php echo formatCurrency($stats['cancelled_revenue'] ?? 0); ?></div>
            </div>
            <div class="stat-card transactions">
                <div class="stat-label">Total Transactions</div>
                <div class="stat-number"><?php echo $stats['total_transactions'] ?? 0; ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <h3>Filter Revenue Records</h3>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           placeholder="Client name, email, phone, description..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="form-control">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="date_from">From Date</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group">
                    <label for="date_to">To Date</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group filter-actions">
                    <label>&nbsp;</label>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Recent Transactions -->
        <?php if (!empty($recent_transactions)): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Transactions</h3>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($transaction['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['client_name']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['category_name'] ?? 'N/A'); ?></td>
                                <td class="amount"><?php echo formatCurrency($transaction['amount']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                        <?php echo ucfirst($transaction['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Revenue by Category -->
        <?php if (!empty($category_stats)): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Revenue by Category</h3>
            </div>
            <div class="card-body">
                <div class="revenue-chart">
                    <?php foreach ($category_stats as $category): ?>
                        <?php if ($category['total_revenue'] > 0): ?>
                        <div class="chart-bar">
                            <div class="chart-bar-fill" style="background-color: <?php echo generateColor($category['category_name']); ?>;">
                                <?php echo formatCurrencyShort($category['total_revenue']); ?>
                            </div>
                            <div class="chart-bar-info">
                                <div class="chart-bar-label"><?php echo htmlspecialchars($category['category_name']); ?></div>
                                <div class="chart-bar-amount">
                                    <?php echo $category['transaction_count']; ?> transactions
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Revenue Table -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Service Revenue Records</h3>
                <div>
                    <span style="color: #64748b; font-size: 0.9rem;">
                        Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $limit, $total_count); ?> of <?php echo $total_count; ?> records
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($service_revenues)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <a href="?sort_by=id&sort_order=<?php echo $sort_by === 'id' && $sort_order === 'DESC' ? 'ASC' : 'DESC'; ?><?php echo getQueryString(['sort_by', 'sort_order']); ?>">
                                            ID <?php echo $sort_by === 'id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?sort_by=client_name&sort_order=<?php echo $sort_by === 'client_name' && $sort_order === 'DESC' ? 'ASC' : 'DESC'; ?><?php echo getQueryString(['sort_by', 'sort_order']); ?>">
                                            Client <?php echo $sort_by === 'client_name' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </a>
                                    </th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>
                                        <a href="?sort_by=amount&sort_order=<?php echo $sort_by === 'amount' && $sort_order === 'DESC' ? 'ASC' : 'DESC'; ?><?php echo getQueryString(['sort_by', 'sort_order']); ?>">
                                            Amount <?php echo $sort_by === 'amount' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?sort_by=payment_date&sort_order=<?php echo $sort_by === 'payment_date' && $sort_order === 'DESC' ? 'ASC' : 'DESC'; ?><?php echo getQueryString(['sort_by', 'sort_order']); ?>">
                                            Date <?php echo $sort_by === 'payment_date' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
                                        </a>
                                    </th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($service_revenues as $revenue): ?>
                                <tr>
                                    <td>#<?php echo $revenue['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($revenue['client_name']); ?></strong>
                                        <?php if ($revenue['client_email']): ?>
                                            <br><small style="color: #64748b;"><?php echo htmlspecialchars($revenue['client_email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($revenue['client_phone']): ?>
                                            <br><small style="color: #64748b;"><?php echo htmlspecialchars($revenue['client_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($revenue['category_name'] ?? 'Uncategorized'); ?></div>
                                        <span class="revenue-type type-<?php echo strtolower($revenue['revenue_type'] ?? 'other'); ?>">
                                            <?php echo ucfirst($revenue['revenue_type'] ?? 'Other'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(substr($revenue['description'], 0, 100)); ?>
                                        <?php if (strlen($revenue['description']) > 100): ?>...<?php endif; ?>
                                        <?php if ($revenue['invoice_number']): ?>
                                            <br><small style="color: #64748b;">Ref: <?php echo htmlspecialchars($revenue['invoice_number']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount"><?php echo formatCurrency($revenue['amount']); ?></td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($revenue['payment_date'])); ?>
                                        <br><small style="color: #64748b;"><?php echo date('g:i A', strtotime($revenue['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $revenue['status']; ?>">
                                            <?php echo ucfirst($revenue['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="edit.php?id=<?php echo $revenue['id']; ?>" class="btn btn-sm btn-primary btn-icon" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="view.php?id=<?php echo $revenue['id']; ?>" class="btn btn-sm btn-info btn-icon" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger btn-icon" title="Delete" onclick="confirmDelete(<?php echo $revenue['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo getQueryString(['page']); ?>" class="page-link">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo getQueryString(['page']); ?>" class="page-link">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $start + 4);
                        $start = max(1, min($start, $end - 4));
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?><?php echo getQueryString(['page']); ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo getQueryString(['page']); ?>" class="page-link">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?><?php echo getQueryString(['page']); ?>" class="page-link">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-briefcase"></i>
                        <h3>No Service Revenue Records Found</h3>
                        <p>No service revenue records match your search criteria.</p>
                        <a href="add.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus-circle"></i> Add Your First Revenue Record
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this service revenue record? This action cannot be undone.</p>
            </div>
            <div class="modal-actions">
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Record
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            allowInput: true
        });

        // Delete confirmation
        function confirmDelete(id) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDeleteModal();
            }
        });

        // Auto-refresh page every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);

        // Generate a color based on string
        function stringToColor(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = str.charCodeAt(i) + ((hash << 5) - hash);
            }
            let color = '#';
            for (let i = 0; i < 3; i++) {
                const value = (hash >> (i * 8)) & 0xFF;
                color += ('00' + value.toString(16)).substr(-2);
            }
            return color;
        }

        // Update chart colors
        document.querySelectorAll('.chart-bar-fill').forEach(element => {
            const text = element.parentElement.querySelector('.chart-bar-label').textContent;
            element.style.backgroundColor = stringToColor(text);
        });
    </script>
</body>
</html>