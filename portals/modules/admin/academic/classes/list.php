<?php
// modules/admin/academic/classes/list.php

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

// Get filter parameters
$status = $_GET['status'] ?? '';
$program_id = $_GET['program_id'] ?? '';
$instructor_id = $_GET['instructor_id'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$offset = ($page - 1) * $per_page;

// Build base query without pagination for count
$count_sql = "SELECT COUNT(DISTINCT cb.id) as total
FROM class_batches cb
JOIN courses c ON cb.course_id = c.id
JOIN programs p ON c.program_id = p.id
LEFT JOIN users u ON cb.instructor_id = u.id
WHERE 1=1";

$count_params = [];
$count_types = "";

// Build query with filters
$sql = "SELECT 
    cb.*,
    c.title as course_title,
    c.course_code,
    p.name as program_name,
    p.program_code,
    p.program_type,
    u.first_name as instructor_first_name,
    u.last_name as instructor_last_name,
    COUNT(DISTINCT e.id) as enrolled_students,
    COUNT(DISTINCT m.id) as total_materials,
    COUNT(DISTINCT a.id) as total_assignments
FROM class_batches cb
JOIN courses c ON cb.course_id = c.id
JOIN programs p ON c.program_id = p.id
LEFT JOIN users u ON cb.instructor_id = u.id
LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
LEFT JOIN materials m ON cb.id = m.class_id
LEFT JOIN assignments a ON cb.id = a.class_id
WHERE 1=1";

$params = [];
$types = "";

// Apply filters to both count and main queries
function applyFilters(&$sql, &$params, &$types, $status, $program_id, $instructor_id, $search, $date_from, $date_to)
{
    if ($status && $status !== 'all') {
        $sql .= " AND cb.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($program_id) {
        $sql .= " AND p.id = ?";
        $params[] = $program_id;
        $types .= "i";
    }

    if ($instructor_id) {
        $sql .= " AND cb.instructor_id = ?";
        $params[] = $instructor_id;
        $types .= "i";
    }

    if ($search) {
        $sql .= " AND (cb.batch_code LIKE ? OR cb.name LIKE ? OR c.title LIKE ? OR p.name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ssss";
    }

    if ($date_from) {
        $sql .= " AND DATE(cb.start_date) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }

    if ($date_to) {
        $sql .= " AND DATE(cb.start_date) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
}

// Apply filters to count query
applyFilters($count_sql, $count_params, $count_types, $status, $program_id, $instructor_id, $search, $date_from, $date_to);

// Get total count for pagination
$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);

// Apply filters to main query
applyFilters($sql, $params, $types, $status, $program_id, $instructor_id, $search, $date_from, $date_to);

// Group by class and order by start date with pagination
$sql .= " GROUP BY cb.id ORDER BY cb.start_date DESC, cb.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
FROM class_batches";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get programs for filter dropdown
$programs_sql = "SELECT id, program_code, name FROM programs WHERE status = 'active' ORDER BY program_code";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Get instructors for filter dropdown
$instructors_sql = "SELECT id, first_name, last_name, email FROM users 
                    WHERE role = 'instructor' AND status = 'active' 
                    ORDER BY first_name, last_name";
$instructors_result = $conn->query($instructors_sql);
$instructors = $instructors_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_classes', "Viewed classes list with filters");

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } elseif (!empty($_POST['selected_classes'])) {
        $selected_ids = $_POST['selected_classes'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        $update_sql = "UPDATE class_batches SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_sql);

        $status_param = $_POST['bulk_action'];
        $all_params = array_merge([$status_param], $selected_ids);
        $types = str_repeat('i', count($selected_ids) + 1);

        $update_stmt->bind_param($types, ...$all_params);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = count($selected_ids) . ' classes updated successfully.';

            // Log each update
            foreach ($selected_ids as $class_id) {
                logActivity(
                    $_SESSION['user_id'],
                    'class_update',
                    "Class #$class_id bulk updated to $status_param",
                    'class_batches',
                    $class_id
                );
            }

            // Refresh page
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update classes.';
        }
    } else {
        $_SESSION['error'] = 'Please select at least one class.';
    }
}

// Helper function to build query string for pagination
function buildQueryString($exclude = [])
{
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Class Management - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --scheduled: #8b5cf6;
            --ongoing: #10b981;
            --completed: #3b82f6;
            --cancelled: #ef4444;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--gray-800);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Mobile-First Sidebar */
        .sidebar {
            background: var(--dark);
            color: white;
            width: 100%;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.25rem;
            color: white;
        }

        .sidebar-header p {
            display: none;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            display: block;
        }

        .sidebar-nav {
            display: none;
            max-height: calc(100vh - 70px);
            overflow-y: auto;
        }

        .sidebar-nav.show {
            display: block;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 0.5rem 0;
        }

        .sidebar-nav li {
            margin: 0;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .header-actions .btn {
            flex: 1;
            min-width: 120px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card.scheduled {
            border-left-color: var(--scheduled);
        }

        .stat-card.ongoing {
            border-left-color: var(--ongoing);
        }

        .stat-card.completed {
            border-left-color: var(--completed);
        }

        .stat-card.cancelled {
            border-left-color: var(--cancelled);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 1.25rem;
            opacity: 0.3;
        }

        /* Filters Card */
        .filters-card {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.25rem;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
            width: 100%;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
        }

        /* Classes Table */
        .classes-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
        }

        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .per-page-selector select {
            padding: 0.4rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        /* Card View for Mobile */
        .classes-card-view {
            display: block;
        }

        .class-card {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem;
        }

        .class-card:last-child {
            border-bottom: none;
        }

        .class-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .class-code {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .class-name {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 0.2rem;
        }

        .class-card-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .class-info-item {
            font-size: 0.85rem;
        }

        .info-label {
            color: #64748b;
            font-size: 0.7rem;
            display: block;
            margin-bottom: 0.2rem;
        }

        .info-value {
            font-weight: 500;
        }

        .class-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #e2e8f0;
        }

        .class-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .class-actions .btn-sm {
            flex: 1;
            min-width: 70px;
        }

        /* Table View for Desktop */
        .classes-table-view {
            display: none;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
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
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status-scheduled {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .program-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-onsite {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-online {
            background: #dcfce7;
            color: #166534;
        }

        /* Progress Bar */
        .progress-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
            width: 100%;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: #f8fafc;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bulk-actions-row {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .bulk-actions select {
            flex: 1;
            min-width: 150px;
        }

        /* Pagination */
        .pagination {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.85rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-link {
            padding: 0.4rem 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.85rem;
            min-width: 32px;
            text-align: center;
        }

        .page-link:hover,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        /* Checkbox */
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .class-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Tablet and Desktop Breakpoints */
        @media (min-width: 640px) {
            .main-content {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }

            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }

            .bulk-actions {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }

            .bulk-actions-row {
                flex: 1;
            }

            .pagination {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        @media (min-width: 768px) {
            .sidebar {
                width: 250px;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar-header {
                padding: 1.5rem 1.5rem 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .sidebar-header h2 {
                font-size: 1.5rem;
            }

            .sidebar-header p {
                display: block;
                color: #94a3b8;
                font-size: 0.9rem;
            }

            .menu-toggle {
                display: none;
            }

            .sidebar-nav {
                display: block;
            }

            .sidebar-nav a {
                padding: 0.75rem 1.5rem;
            }

            .main-content {
                margin-left: 250px;
                padding: 2rem;
            }

            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .header-actions .btn {
                flex: none;
                width: auto;
            }

            .stats-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .filter-form {
                grid-template-columns: repeat(4, 1fr) auto;
            }

            .filter-actions {
                grid-template-columns: 1fr;
                margin-top: 1.5rem;
            }

            .classes-card-view {
                display: none;
            }

            .classes-table-view {
                display: block;
            }

            .bulk-actions {
                padding: 1rem 1.5rem;
            }

            .pagination {
                padding: 1rem 1.5rem;
            }
        }

        @media (min-width: 1024px) {
            .filter-form {
                grid-template-columns: repeat(6, 1fr) auto;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div>
                    <h2>Impact Academy</h2>
                    <p>Admin Dashboard</p>
                </div>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <nav class="sidebar-nav" id="sidebarNav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="active">
                            <i class="fas fa-chalkboard-teacher"></i> Classes</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                            <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">
                            <i class="fas fa-history"></i> Activity Logs</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php">
                            <i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <div class="breadcrumb">
                        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">Dashboard</a> &rsaquo;
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">Academic</a> &rsaquo;
                        Classes
                    </div>
                    <h1>Class Management</h1>
                </div>
                <div class="header-actions">
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Class
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/schedule_builder.php" class="btn btn-secondary">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php echo $stats['total']; ?>
                        <i class="fas fa-chalkboard-teacher stat-icon"></i>
                    </div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-card scheduled">
                    <div class="stat-number">
                        <?php echo $stats['scheduled']; ?>
                        <i class="fas fa-calendar-alt stat-icon"></i>
                    </div>
                    <div class="stat-label">Scheduled</div>
                </div>
                <div class="stat-card ongoing">
                    <div class="stat-number">
                        <?php echo $stats['ongoing']; ?>
                        <i class="fas fa-play-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Ongoing</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number">
                        <?php echo $stats['completed']; ?>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card cancelled">
                    <div class="stat-number">
                        <?php echo $stats['cancelled']; ?>
                        <i class="fas fa-times-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>
                    <i class="fas fa-filter"></i> Filter Classes
                </h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="scheduled" <?php echo $status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="ongoing" <?php echo $status === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" class="form-control">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>"
                                    <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor</label>
                        <select name="instructor_id" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($instructors as $instructor): ?>
                                <option value="<?php echo $instructor['id']; ?>"
                                    <?php echo $instructor_id == $instructor['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Search classes..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-row">
                        <div class="form-group">
                            <label>From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="form-group">
                            <label>To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Classes List -->
            <div class="classes-table">
                <div class="table-header">
                    <h3>Class Batches</h3>
                    <div class="per-page-selector">
                        <label for="per_page">Show:</label>
                        <select id="per_page" onchange="window.location.href='?<?php echo buildQueryString(['per_page', 'page']); ?>&per_page='+this.value">
                            <option value="5" <?php echo $per_page == 5 ? 'selected' : ''; ?>>5</option>
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                        </select>
                    </div>
                </div>

                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <?php if (!empty($classes)): ?>
                        <!-- Mobile Card View -->
                        <div class="classes-card-view">
                            <?php foreach ($classes as $class):
                                $enrollment_progress = $class['max_students'] > 0
                                    ? ($class['enrolled_students'] / $class['max_students']) * 100
                                    : 0;
                            ?>
                                <div class="class-card">
                                    <div class="class-card-header">
                                        <div>
                                            <input type="checkbox" name="selected_classes[]"
                                                value="<?php echo $class['id']; ?>" class="class-checkbox">
                                            <span class="class-code"><?php echo htmlspecialchars($class['batch_code']); ?></span>
                                            <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
                                        </div>
                                        <span class="status-badge status-<?php echo $class['status']; ?>">
                                            <?php echo ucfirst($class['status']); ?>
                                        </span>
                                    </div>

                                    <div class="class-card-body">
                                        <div class="class-info-item">
                                            <span class="info-label">Course</span>
                                            <span class="info-value"><?php echo htmlspecialchars($class['course_code']); ?></span>
                                        </div>
                                        <div class="class-info-item">
                                            <span class="info-label">Program</span>
                                            <span class="info-value">
                                                <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                                    <?php echo ucfirst($class['program_type']); ?>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="class-info-item">
                                            <span class="info-label">Instructor</span>
                                            <span class="info-value">
                                                <?php if ($class['instructor_first_name']): ?>
                                                    <?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: #64748b;">Not assigned</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="class-info-item">
                                            <span class="info-label">Schedule</span>
                                            <span class="info-value">
                                                <?php echo date('M j', strtotime($class['start_date'])); ?> -
                                                <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                            </span>
                                        </div>
                                        <div class="class-info-item">
                                            <span class="info-label">Enrollment</span>
                                            <span class="info-value">
                                                <?php echo $class['enrolled_students']; ?>/<?php echo $class['max_students']; ?>
                                            </span>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($enrollment_progress, 100); ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="class-info-item">
                                            <span class="info-label">Materials/Assign.</span>
                                            <span class="info-value">
                                                <?php echo $class['total_materials']; ?> / <?php echo $class['total_assignments']; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="class-card-footer">
                                        <div class="class-actions">
                                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class['id']; ?>"
                                                class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class['id']; ?>"
                                                class="btn btn-secondary btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class['id']; ?>/home.php"
                                                class="btn btn-success btn-sm" target="_blank">
                                                <i class="fas fa-chalkboard-teacher"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Desktop Table View -->
                        <div class="classes-table-view">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll" class="class-checkbox">
                                        </th>
                                        <th>Class Batch</th>
                                        <th>Course & Program</th>
                                        <th>Instructor</th>
                                        <th>Schedule</th>
                                        <th>Enrollment</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $class):
                                        $enrollment_progress = $class['max_students'] > 0
                                            ? ($class['enrolled_students'] / $class['max_students']) * 100
                                            : 0;
                                    ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" name="selected_classes[]"
                                                    value="<?php echo $class['id']; ?>" class="class-checkbox">
                                            </td>
                                            <td>
                                                <div class="class-code">
                                                    <?php echo htmlspecialchars($class['batch_code']); ?>
                                                </div>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['name']); ?>
                                                </div>
                                                <div>
                                                    <span class="program-badge badge-<?php echo $class['program_type']; ?>">
                                                        <?php echo ucfirst($class['program_type']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($class['course_code']); ?></strong><br>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['course_title']); ?>
                                                </div>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['program_name']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($class['instructor_first_name']): ?>
                                                    <strong><?php echo htmlspecialchars($class['instructor_first_name'] . ' ' . $class['instructor_last_name']); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: #64748b; font-style: italic;">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="date-cell">
                                                <span class="date-label">Starts</span>
                                                <?php echo date('M j, Y', strtotime($class['start_date'])); ?>
                                                <br>
                                                <span class="date-label">Ends</span>
                                                <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo $class['enrolled_students']; ?></strong> /
                                                    <?php echo $class['max_students']; ?> students
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo min($enrollment_progress, 100); ?>%;"></div>
                                                </div>
                                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                                                    <?php echo $class['total_materials']; ?> materials • <?php echo $class['total_assignments']; ?> assignments
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $class['status']; ?>">
                                                    <?php echo ucfirst($class['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions">
                                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class['id']; ?>"
                                                    class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/edit.php?id=<?php echo $class['id']; ?>"
                                                    class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>modules/instructor/classes/<?php echo $class['id']; ?>/home.php"
                                                    class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-chalkboard-teacher"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <div class="bulk-actions-row">
                                <select name="bulk_action" class="form-control">
                                    <option value="">Bulk Actions</option>
                                    <option value="scheduled">Mark Scheduled</option>
                                    <option value="ongoing">Mark Ongoing</option>
                                    <option value="completed">Mark Completed</option>
                                    <option value="cancelled">Mark Cancelled</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    Apply
                                </button>
                            </div>
                            <span style="color: #64748b; font-size: 0.85rem;">
                                <span id="selectedCount">0</span> selected
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h3>No Classes Found</h3>
                            <p>Try adjusting your filters or create a new class.</p>
                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/create.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create New Class
                            </a>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_rows); ?> of <?php echo $total_rows; ?>
                        </div>
                        <div class="page-numbers">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page - 1; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1) {
                                echo '<a href="?' . buildQueryString(['page']) . '&page=1" class="page-link">1</a>';
                                if ($start_page > 2) {
                                    echo '<span class="page-link">...</span>';
                                }
                            }

                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $i; ?>"
                                    class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor;

                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<span class="page-link">...</span>';
                                }
                                echo '<a href="?' . buildQueryString(['page']) . '&page=' . $total_pages . '" class="page-link">' . $total_pages . '</a>';
                            }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page + 1; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebarNav').classList.toggle('show');
        });

        // Select all checkbox functionality
        document.getElementById('selectAll')?.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.class-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.class-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.class-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk form submission confirmation
        document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
            const action = this.bulk_action.value;
            const selectedCount = document.querySelectorAll('.class-checkbox:checked').length;

            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one class.');
                return false;
            }

            const actionText = action === 'scheduled' ? 'mark as scheduled' :
                action === 'ongoing' ? 'mark as ongoing' :
                action === 'completed' ? 'mark as completed' :
                'mark as cancelled';

            if (!confirm(`Are you sure you want to ${actionText} ${selectedCount} class(es)?`)) {
                e.preventDefault();
                return false;
            }
        });

        // Update selected count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                const sidebar = document.querySelector('.sidebar');
                const menuToggle = document.getElementById('menuToggle');
                const sidebarNav = document.getElementById('sidebarNav');

                if (!sidebar.contains(event.target) && sidebarNav.classList.contains('show')) {
                    sidebarNav.classList.remove('show');
                }
            });
        });

        // Quick search with debounce
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimer;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    if (this.value.length >= 2 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        // Animate progress bars
        document.querySelectorAll('.progress-bar').forEach(bar => {
            const fill = bar.querySelector('.progress-fill');
            if (fill) {
                const width = fill.style.width;
                fill.style.width = '0%';
                setTimeout(() => {
                    fill.style.width = width;
                }, 100);
            }
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>