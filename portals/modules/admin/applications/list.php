<?php
// modules/admin/applications/list.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$status = $_GET['status'] ?? 'pending';
$program_type = $_GET['program_type'] ?? '';
$applying_as = $_GET['applying_as'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$sql = "SELECT 
    a.*, 
    u.first_name, 
    u.last_name, 
    u.email, 
    u.phone,
    p.name as program_name,
    p.program_code,
    up.gender,
    up.date_of_birth
FROM applications a
LEFT JOIN users u ON a.user_id = u.id
LEFT JOIN user_profiles up ON u.id = up.user_id
LEFT JOIN programs p ON a.program_id = p.id
WHERE 1=1";

$params = [];
$types = "";

// Filter by status
if ($status && $status !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Filter by program type
if ($program_type) {
    $sql .= " AND (p.program_type = ? OR (p.program_type IS NULL AND EXISTS (
        SELECT 1 FROM user_profiles up2 
        WHERE up2.user_id = u.id 
        AND up2.learning_mode_preference LIKE ?
    )))";
    $params[] = $program_type;
    $params[] = "%$program_type%";
    $types .= "ss";
}

// Filter by applying as
if ($applying_as) {
    $sql .= " AND a.applying_as = ?";
    $params[] = $applying_as;
    $types .= "s";
}

// Filter by date range
if ($date_from) {
    $sql .= " AND DATE(a.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(a.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Order by creation date (newest first)
$sql .= " ORDER BY a.created_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$applications = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
FROM applications";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get programs for filter dropdown
$programs_sql = "SELECT DISTINCT p.* FROM programs p 
                 LEFT JOIN applications a ON p.id = a.program_id 
                 WHERE a.id IS NOT NULL";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Check for recently approved applications to show reminder
$show_reminder = false;
$reminder_applications = [];
if (isset($_SESSION['recently_approved'])) {
    $recently_approved = $_SESSION['recently_approved'];
    $show_reminder = true;

    // Get details for reminder applications
    if (!empty($recently_approved)) {
        $placeholders = implode(',', array_fill(0, count($recently_approved), '?'));
        $reminder_sql = "SELECT a.*, u.first_name, u.last_name, u.email, p.name as program_name 
                        FROM applications a
                        JOIN users u ON a.user_id = u.id
                        LEFT JOIN programs p ON a.program_id = p.id
                        WHERE a.id IN ($placeholders)";
        $reminder_stmt = $conn->prepare($reminder_sql);
        $types = str_repeat('i', count($recently_approved));
        $reminder_stmt->bind_param($types, ...$recently_approved);
        $reminder_stmt->execute();
        $reminder_result = $reminder_stmt->get_result();
        $reminder_applications = $reminder_result->fetch_all(MYSQLI_ASSOC);
    }
    unset($_SESSION['recently_approved']);
}

// Log activity
$filter_desc = "Viewed applications list";
if ($status) $filter_desc .= " - status:$status";
logActivity($_SESSION['user_id'], 'view_applications', $filter_desc);

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } elseif (!empty($_POST['selected_applications'])) {
        $selected_ids = $_POST['selected_applications'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        $update_sql = "UPDATE applications SET status = ? WHERE id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_sql);

        $status_param = $_POST['bulk_action'];
        $all_params = array_merge([$status_param], $selected_ids);
        $types = str_repeat('i', count($selected_ids) + 1);

        $update_stmt->bind_param($types, ...$all_params);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = count($selected_ids) . ' applications updated successfully.';

            // Store approved applications for reminder
            if ($status_param === 'approved') {
                $_SESSION['recently_approved'] = $selected_ids;
            }

            // Log each update
            foreach ($selected_ids as $app_id) {
                logActivity(
                    $_SESSION['user_id'],
                    'bulk_update',
                    "Application #$app_id to $status_param"
                );
            }

            // Refresh page
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update applications.';
        }
    } else {
        $_SESSION['error'] = 'Please select at least one application.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Application Management - Admin Dashboard</title>
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
            --pending: #f59e0b;
            --under-review: #8b5cf6;
            --approved: #10b981;
            --rejected: #ef4444;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
            --safe-left: env(safe-area-inset-left);
            --safe-right: env(safe-area-inset-right);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            overflow-x: hidden;
        }

        html {
            height: -webkit-fill-available;
        }

        .admin-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }

        /* Mobile First Approach */
        .mobile-header {
            display: flex;
            background: var(--dark);
            color: white;
            padding: 0.75rem 1rem;
            padding-top: max(0.75rem, var(--safe-top));
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .mobile-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .mobile-header-left h2 {
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .mobile-header-left p {
            color: #94a3b8;
            font-size: 0.75rem;
            margin-top: 0.1rem;
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: background-color 0.2s;
        }

        .mobile-menu-btn:active {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Mobile Sidebar */
        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -100%;
            width: min(85%, 320px);
            height: 100vh;
            height: -webkit-fill-available;
            background: var(--dark);
            color: white;
            z-index: 1000;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            padding-bottom: var(--safe-bottom);
        }

        .mobile-sidebar.active {
            left: 0;
        }

        .mobile-sidebar-header {
            padding: 1.5rem 1.25rem;
            padding-top: max(1.5rem, var(--safe-top));
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mobile-sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .mobile-sidebar-header p {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .close-sidebar {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .close-sidebar:active {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(3px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Desktop Sidebar */
        .sidebar {
            display: none;
            width: 250px;
            background: var(--dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .main-content {
            flex: 1;
            padding: 1rem;
            padding-bottom: calc(1rem + var(--safe-bottom));
        }

        @media (min-width: 1024px) {
            .mobile-header {
                display: none;
            }

            .sidebar {
                display: block;
            }

            .main-content {
                margin-left: 250px;
                padding: 1.5rem;
            }
        }

        .sidebar-header {
            padding: 1.5rem 1.5rem 1.5rem;
            padding-top: max(1.5rem, var(--safe-top));
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.85rem;
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
            padding: 0.875rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.95rem;
            min-height: 48px;
        }

        .sidebar-nav a:active {
            background: rgba(255, 255, 255, 0.1);
        }

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

        /* Header Section */
        .header {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.35rem;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-top: 1px solid #e2e8f0;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        @media (min-width: 640px) {
            .header-content {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .user-info {
                border-top: none;
                padding: 0;
            }
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
        }

        .stat-card.pending {
            border-left-color: var(--pending);
        }

        .stat-card.review {
            border-left-color: var(--under-review);
        }

        .stat-card.approved {
            border-left-color: var(--approved);
        }

        .stat-card.rejected {
            border-left-color: var(--rejected);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 500;
            line-height: 1.3;
        }

        @media (min-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        /* Filters Section */
        .filters-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
        }

        .filters-card h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 600;
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
            margin-bottom: 0.35rem;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            -webkit-appearance: none;
            appearance: none;
            min-height: 48px;
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
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .filter-actions .btn {
            flex: 1;
        }

        @media (min-width: 768px) {
            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 1rem;
                align-items: end;
            }

            .filter-actions {
                margin-top: 0;
            }
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            min-height: 48px;
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:active {
            background: var(--secondary);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:active {
            background: #cbd5e1;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.6rem 0.75rem;
            font-size: 0.9rem;
            min-height: 40px;
        }

        .btn-block-mobile {
            width: 100%;
        }

        @media (min-width: 640px) {
            .btn-block-mobile {
                width: auto;
            }
        }

        /* Applications Section */
        .applications-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
        }

        .table-header {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .table-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .table-header-actions .btn {
            flex: 1;
        }

        @media (min-width: 768px) {
            .table-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .table-header-actions {
                width: auto;
            }

            .table-header-actions .btn {
                flex: none;
            }
        }

        /* Mobile Card View */
        .mobile-app-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin: 0.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s;
        }

        .mobile-app-card:active {
            transform: scale(0.99);
        }

        .mobile-app-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .mobile-app-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .mobile-app-subtitle {
            color: #64748b;
            font-size: 0.85rem;
        }

        .mobile-app-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0.5rem 0;
        }

        .mobile-app-details {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.75rem;
            margin: 0.75rem 0;
        }

        .mobile-app-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .mobile-app-detail-row:last-child {
            border-bottom: none;
        }

        .mobile-app-detail-label {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .mobile-app-detail-value {
            font-weight: 500;
            font-size: 0.9rem;
            text-align: right;
        }

        .mobile-app-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .mobile-app-actions .btn {
            flex: 1;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-under-review {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.6rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
        }

        /* Desktop Table */
        .desktop-table {
            display: none;
        }

        @media (min-width: 1024px) {
            .mobile-app-card {
                display: none;
            }

            .desktop-table {
                display: block;
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
                letter-spacing: 0.3px;
                white-space: nowrap;
            }

            td {
                padding: 1rem;
                border-bottom: 1px solid #e2e8f0;
                vertical-align: middle;
            }

            tbody tr:hover {
                background: #f8fafc;
            }
        }

        /* Checkbox Styling */
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }

        input[type="checkbox"] {
            width: 22px;
            height: 22px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            position: sticky;
            bottom: 0;
            z-index: 50;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bulk-actions-row {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bulk-actions .btn {
            width: 100%;
        }

        .selected-count {
            color: #64748b;
            font-size: 0.9rem;
            text-align: center;
            font-weight: 500;
        }

        @media (min-width: 640px) {
            .bulk-actions-row {
                flex-direction: row;
                align-items: center;
            }

            .bulk-actions select {
                flex: 2;
            }

            .bulk-actions .btn {
                flex: 1;
            }

            .selected-count {
                text-align: right;
            }
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.95rem;
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
            flex-shrink: 0;
        }

        /* Reminder Alert */
        .reminder-alert {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid #fbbf24;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .reminder-alert i {
            color: #d97706;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .reminder-alert p {
            color: #92400e;
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.4;
            flex: 1;
        }

        .reminder-alert button {
            background: none;
            border: none;
            color: #92400e;
            text-decoration: underline;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 0.25rem;
            white-space: nowrap;
        }

        /* Reminder Modal */
        .reminder-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }

        .reminder-modal.active {
            display: flex;
        }

        .reminder-content {
            background: white;
            border-radius: 20px 20px 0 0;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        @media (min-width: 768px) {
            .reminder-modal {
                align-items: center;
                padding: 1rem;
            }

            .reminder-content {
                border-radius: 12px;
                max-width: 600px;
                animation: slideIn 0.3s ease;
            }

            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        }

        .reminder-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .reminder-header h3 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .reminder-body {
            padding: 1.25rem;
        }

        .reminder-tasks {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }

        .reminder-tasks h4 {
            color: #0369a1;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .task-list {
            list-style: none;
        }

        .task-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .task-list li:last-child {
            border-bottom: none;
        }

        .task-list i {
            color: var(--success);
            margin-top: 0.125rem;
            flex-shrink: 0;
        }

        .reminder-app {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .reminder-app-info h4 {
            margin-bottom: 0.25rem;
            color: var(--dark);
            font-size: 1rem;
        }

        .reminder-app-info p {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .reminder-app-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .reminder-app-actions .btn {
            flex: 1;
            min-height: 40px;
            padding: 0.5rem;
        }

        .reminder-footer {
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: sticky;
            bottom: 0;
            background: white;
        }

        @media (min-width: 480px) {
            .reminder-footer {
                flex-direction: row;
                justify-content: flex-end;
            }

            .reminder-app {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .reminder-app-actions {
                margin-top: 0;
            }
        }

        .close-reminder {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .close-reminder:active {
            background: rgba(255, 255, 255, 0.2);
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
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        /* Pagination */
        .pagination {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
            text-align: center;
        }

        .page-numbers {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-link {
            padding: 0.6rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.9rem;
            min-width: 44px;
            text-align: center;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .page-link:active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        @media (min-width: 640px) {
            .pagination {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .pagination-info {
                text-align: left;
            }

            .page-numbers {
                justify-content: flex-end;
            }
        }

        /* Pull to refresh indicator */
        .ptr-indicator {
            display: none;
            text-align: center;
            padding: 1rem;
            color: #64748b;
        }

        /* Touch optimizations */
        * {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }

        input,
        textarea,
        select,
        button,
        a {
            -webkit-touch-callout: default;
            -webkit-user-select: text;
            user-select: text;
        }

        /* Prevent pull-to-refresh on mobile */
        body {
            overscroll-behavior-y: contain;
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <div class="mobile-header-content">
                <div class="mobile-header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h2>Impact Digital Academy</h2>
                        <p>Admin Dashboard</p>
                    </div>
                </div>
                <div class="user-avatar" style="width: 40px; height: 40px; font-size: 1rem;">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>
        </div>

        <!-- Mobile Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <!-- Mobile Sidebar -->
        <div class="mobile-sidebar" id="mobileSidebar">
            <div class="mobile-sidebar-header">
                <div>
                    <h2>Impact Digital Academy</h2>
                    <p>Admin Dashboard</p>
                </div>
                <button class="close-sidebar" onclick="toggleSidebar()" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="active">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
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

        <!-- Desktop Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Digital Academy</h2>
                <p>Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php" class="active">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
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
                <div class="header-content">
                    <h1>Application Management</h1>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo $_SESSION['user_name'] ?? 'Admin'; ?></div>
                            <div style="font-size: 0.85rem; color: #64748b;">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $_SESSION['success'];
                            unset($_SESSION['success']); ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $_SESSION['error'];
                            unset($_SESSION['error']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Reminder Alert -->
            <?php if ($show_reminder && !empty($reminder_applications)): ?>
                <div class="reminder-alert" id="reminderAlert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>
                        <strong>Reminder:</strong> You have <?php echo count($reminder_applications); ?>
                        recently approved application(s).
                        <button type="button" onclick="showReminderModal()">View Details</button>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card review">
                    <div class="stat-number"><?php echo $stats['under_review'] ?? 0; ?></div>
                    <div class="stat-label">Review</div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-number"><?php echo $stats['rejected'] ?? 0; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>

            <!-- Reminder Modal -->
            <div class="reminder-modal" id="reminderModal">
                <div class="reminder-content">
                    <div class="reminder-header">
                        <h3>
                            <i class="fas fa-bell"></i>
                            Complete Student Setup
                        </h3>
                        <button type="button" class="close-reminder" onclick="closeReminderModal()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="reminder-body">
                        <p style="margin-bottom: 1rem;">The following applications have been approved. Please complete these required setup steps:</p>

                        <div class="reminder-tasks">
                            <h4><i class="fas fa-tasks"></i> Required Actions</h4>
                            <ul class="task-list">
                                <li>
                                    <i class="fas fa-user-check"></i>
                                    <span><strong>Assign Student Role:</strong> Update user role to "student"</span>
                                </li>
                                <li>
                                    <i class="fas fa-user-graduate"></i>
                                    <span><strong>Enroll in Program:</strong> Add student to their approved program</span>
                                </li>
                                <li>
                                    <i class="fas fa-envelope"></i>
                                    <span><strong>Send Welcome Email:</strong> Notify student about approval</span>
                                </li>
                            </ul>
                        </div>

                        <div class="reminder-applications">
                            <h4 style="margin-bottom: 1rem;">Approved Applications</h4>
                            <?php foreach ($reminder_applications as $app): ?>
                                <div class="reminder-app">
                                    <div class="reminder-app-info">
                                        <h4><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($app['email']); ?></p>
                                        <?php if ($app['program_name']): ?>
                                            <p><strong>Program:</strong> <?php echo htmlspecialchars($app['program_name']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reminder-app-actions">
                                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?search=<?php echo urlencode($app['email']); ?>"
                                            class="btn btn-primary btn-sm" target="_blank">
                                            <i class="fas fa-user-edit"></i> Role
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/enrollments/add.php?application_id=<?php echo $app['id']; ?>"
                                            class="btn btn-success btn-sm" target="_blank">
                                            <i class="fas fa-user-plus"></i> Enroll
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="reminder-footer">
                        <button type="button" class="btn btn-secondary btn-block-mobile" onclick="closeReminderModal()">
                            Later
                        </button>
                        <button type="button" class="btn btn-primary btn-block-mobile" onclick="markAsCompleted()">
                            <i class="fas fa-check"></i> Completed
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Applications</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Program Type</label>
                        <select name="program_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Applying As</label>
                        <select name="applying_as" class="form-control">
                            <option value="">All Roles</option>
                            <option value="student" <?php echo $applying_as === 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="instructor" <?php echo $applying_as === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
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

            <!-- Applications Table -->
            <div class="applications-table">
                <div class="table-header">
                    <h3>Applications List</h3>
                    <div class="table-header-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/history.php" class="btn btn-secondary">
                            <i class="fas fa-history"></i> History
                        </a>
                    </div>
                </div>

                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <?php if (!empty($applications)): ?>
                        <!-- Desktop Table View -->
                        <div class="desktop-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>ID</th>
                                        <th>Applicant</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Program</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $app): ?>
                                        <tr>
                                            <td class="checkbox-cell">
                                                <input type="checkbox" name="selected_applications[]"
                                                    value="<?php echo $app['id']; ?>" class="application-checkbox">
                                            </td>
                                            <td>#<?php echo str_pad($app['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong><br>
                                                <small style="color: #64748b;"><?php echo $app['phone'] ?? 'N/A'; ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($app['email']); ?></td>
                                            <td>
                                                <span class="role-badge">
                                                    <?php echo ucfirst($app['applying_as']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($app['program_name']): ?>
                                                    <strong><?php echo htmlspecialchars($app['program_code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($app['program_name']); ?></small>
                                                <?php else: ?>
                                                    <span style="color: #64748b;">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($app['created_at'])); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo str_replace('_', '-', $app['status']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem;">
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($app['status'] === 'pending'): ?>
                                                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>&action=review"
                                                            class="btn btn-secondary btn-sm">
                                                            <i class="fas fa-search"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <?php foreach ($applications as $app): ?>
                            <div class="mobile-app-card">
                                <div class="mobile-app-header">
                                    <div>
                                        <div class="mobile-app-title">
                                            <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                                        </div>
                                        <div class="mobile-app-subtitle">
                                            #<?php echo str_pad($app['id'], 4, '0', STR_PAD_LEFT); ?>
                                        </div>
                                    </div>
                                    <input type="checkbox" name="selected_applications[]"
                                        value="<?php echo $app['id']; ?>" class="application-checkbox">
                                </div>

                                <div class="mobile-app-badges">
                                    <span class="status-badge status-<?php echo str_replace('_', '-', $app['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                    </span>
                                    <span class="role-badge">
                                        <?php echo ucfirst($app['applying_as']); ?>
                                    </span>
                                </div>

                                <div class="mobile-app-details">
                                    <div class="mobile-app-detail-row">
                                        <span class="mobile-app-detail-label">Email:</span>
                                        <span class="mobile-app-detail-value"><?php echo htmlspecialchars($app['email']); ?></span>
                                    </div>
                                    <div class="mobile-app-detail-row">
                                        <span class="mobile-app-detail-label">Phone:</span>
                                        <span class="mobile-app-detail-value"><?php echo $app['phone'] ?? 'N/A'; ?></span>
                                    </div>
                                    <div class="mobile-app-detail-row">
                                        <span class="mobile-app-detail-label">Program:</span>
                                        <span class="mobile-app-detail-value">
                                            <?php echo $app['program_name'] ? htmlspecialchars($app['program_code']) : 'N/A'; ?>
                                        </span>
                                    </div>
                                    <div class="mobile-app-detail-row">
                                        <span class="mobile-app-detail-label">Submitted:</span>
                                        <span class="mobile-app-detail-value">
                                            <?php echo date('M j, Y', strtotime($app['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="mobile-app-actions">
                                    <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>"
                                        class="btn btn-primary">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                    <?php if ($app['status'] === 'pending'): ?>
                                        <a href="<?php echo BASE_URL; ?>modules/admin/applications/review.php?id=<?php echo $app['id']; ?>&action=review"
                                            class="btn btn-secondary">
                                            <i class="fas fa-search"></i> Start
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <div class="bulk-actions-row">
                                <select name="bulk_action" class="form-control">
                                    <option value="">Bulk Actions</option>
                                    <option value="under_review">Mark Under Review</option>
                                    <option value="approved">Approve</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    Apply
                                </button>
                            </div>
                            <div class="selected-count">
                                <span id="selectedCount">0</span> applications selected
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Applications Found</h3>
                            <p>Try adjusting your filters</p>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo count($applications); ?> applications
                    </div>
                    <div class="page-numbers">
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <a href="#" class="page-link">Next</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Select all checkbox functionality
        document.getElementById('selectAll')?.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.application-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.application-checkbox:checked');
            const countElement = document.getElementById('selectedCount');
            if (countElement) {
                countElement.textContent = checkboxes.length;
            }
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.application-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk form submission confirmation
        document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
            const action = this.bulk_action.value;
            const selectedCount = document.querySelectorAll('.application-checkbox:checked').length;

            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one application.');
                return false;
            }

            const actionText = action === 'approved' ? 'approve' :
                action === 'rejected' ? 'reject' :
                'mark as under review';

            if (!confirm(`Are you sure you want to ${actionText} ${selectedCount} application(s)?`)) {
                e.preventDefault();
                return false;
            }
        });

        // Update selected count on page load
        document.addEventListener('DOMContentLoaded', updateSelectedCount);

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');

            // Prevent body scroll when sidebar is open
            if (sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Close sidebar when clicking a link (on mobile)
        document.querySelectorAll('.mobile-sidebar .sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                toggleSidebar();
            });
        });

        // Reminder Modal Functions
        function showReminderModal() {
            document.getElementById('reminderModal').classList.add('active');
            const reminderAlert = document.getElementById('reminderAlert');
            if (reminderAlert) {
                reminderAlert.style.display = 'none';
            }
        }

        function closeReminderModal() {
            document.getElementById('reminderModal').classList.remove('active');
        }

        function markAsCompleted() {
            // Send AJAX request to mark reminder as completed
            fetch('<?php echo BASE_URL; ?>modules/admin/applications/mark_reminder_completed.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'csrf_token=<?php echo generateCSRFToken(); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeReminderModal();
                        const reminderAlert = document.getElementById('reminderAlert');
                        if (reminderAlert) {
                            reminderAlert.style.display = 'none';
                        }
                    } else {
                        alert('Error marking reminder as completed.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error marking reminder as completed.');
                });
        }

        // Show reminder modal automatically if there are approved applications
        <?php if ($show_reminder && !empty($reminder_applications)): ?>
            // Show modal after 1 second delay on desktop only
            if (window.innerWidth > 768) {
                setTimeout(() => {
                    showReminderModal();
                }, 1000);
            }
        <?php endif; ?>

        // Close modal when clicking outside
        document.getElementById('reminderModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeReminderModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeReminderModal();
            }
        });

        // Touch-friendly optimizations
        document.querySelectorAll('select, .btn, a').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            element.addEventListener('touchend', function() {
                this.style.opacity = '';
            });
        });

        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                window.scrollTo(0, 0);
            }, 100);
        });

        // Prevent zoom on double-tap for better UX
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // Swipe to close sidebar
        let touchStartX = 0;
        document.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, false);

        document.addEventListener('touchend', function(e) {
            const sidebar = document.getElementById('mobileSidebar');
            const touchEndX = e.changedTouches[0].screenX;
            const diffX = touchEndX - touchStartX;

            if (sidebar.classList.contains('active') && diffX < -50) {
                toggleSidebar();
            }
        }, false);
    </script>
</body>

</html>
<?php $conn->close(); ?>