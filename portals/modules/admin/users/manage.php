<?php
// modules/admin/users/manage.php

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
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$program_type = $_GET['program_type'] ?? '';
$school_id = $_GET['school_id'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get all schools for filter dropdown
$schools_query = "SELECT id, name FROM schools ORDER BY name";
$schools_result = $conn->query($schools_query);
$schools = $schools_result->fetch_all(MYSQLI_ASSOC);

// Build query with filters
$sql = "SELECT 
    u.*, 
    up.date_of_birth,
    up.gender,
    up.city,
    up.state,
    up.country,
    s.name as school_name,
    COUNT(DISTINCT a.id) as application_count,
    COUNT(DISTINCT e.id) as enrollment_count
FROM users u
LEFT JOIN user_profiles up ON u.id = up.user_id
LEFT JOIN schools s ON u.school_id = s.id
LEFT JOIN applications a ON u.id = a.user_id
LEFT JOIN enrollments e ON u.id = e.student_id
WHERE 1=1";

$params = [];
$types = "";

// Filter by role
if ($role && $role !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $role;
    $types .= "s";
}

// Filter by status
if ($status && $status !== 'all') {
    $sql .= " AND u.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Filter by program type (from applications or enrollments)
if ($program_type) {
    $sql .= " AND (EXISTS (
        SELECT 1 FROM applications a2 
        JOIN programs p2 ON a2.program_id = p2.id 
        WHERE a2.user_id = u.id AND p2.program_type = ?
    ) OR EXISTS (
        SELECT 1 FROM enrollments e2 
        JOIN class_batches cb ON e2.class_id = cb.id 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p3 ON c.program_id = p3.id 
        WHERE e2.student_id = u.id AND p3.program_type = ?
    ))";
    $params[] = $program_type;
    $params[] = $program_type;
    $types .= "ss";
}

// Filter by school
if ($school_id && $school_id !== 'all') {
    $sql .= " AND u.school_id = ?";
    $params[] = $school_id;
    $types .= "i";
}

// Filter by search term
if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR s.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sssss";
}

// Filter by date range
if ($date_from) {
    $sql .= " AND DATE(u.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $sql .= " AND DATE(u.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Group by user and order by creation date (newest first)
$sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics including school-based statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN role = 'instructor' THEN 1 ELSE 0 END) as instructors,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
    SUM(CASE WHEN role = 'applicant' THEN 1 ELSE 0 END) as applicants,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    COUNT(DISTINCT school_id) as total_schools,
    SUM(CASE WHEN school_id IS NOT NULL THEN 1 ELSE 0 END) as users_with_school
FROM users";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get school-specific statistics
$school_stats_sql = "SELECT 
    s.id,
    s.name,
    COUNT(u.id) as user_count,
    SUM(CASE WHEN u.role = 'student' THEN 1 ELSE 0 END) as students,
    SUM(CASE WHEN u.role = 'instructor' THEN 1 ELSE 0 END) as instructors,
    SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) as active_users
FROM schools s
LEFT JOIN users u ON s.id = u.school_id
GROUP BY s.id, s.name
ORDER BY user_count DESC";
$school_stats_result = $conn->query($school_stats_sql);
$school_stats = $school_stats_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_users', "Viewed users list with filters");

// Process bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } elseif (!empty($_POST['selected_users'])) {
        $selected_ids = $_POST['selected_users'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        $update_sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $update_stmt = $conn->prepare($update_sql);

        $status_param = $_POST['bulk_action'];
        $all_params = array_merge([$status_param], $selected_ids);
        $types = str_repeat('i', count($selected_ids) + 1);

        $update_stmt->bind_param($types, ...$all_params);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = count($selected_ids) . ' users updated successfully.';

            // Log each update
            foreach ($selected_ids as $user_id) {
                logActivity(
                    $_SESSION['user_id'],
                    'user_update',
                    "User #$user_id bulk updated to $status_param",
                    'users',
                    $user_id
                );
            }

            // Refresh page
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update users.';
        }
    } else {
        $_SESSION['error'] = 'Please select at least one user.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>User Management - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --admin: #8b5cf6;
            --instructor: #f59e0b;
            --student: #10b981;
            --applicant: #64748b;
            --school: #8b5cf6;
            --sidebar-width: 250px;
            --sidebar-mobile-width: 85%;
            --header-height: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar - Mobile First */
        .sidebar {
            width: var(--sidebar-mobile-width);
            max-width: 320px;
            background: var(--dark);
            color: white;
            position: fixed;
            left: -100%;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: left 0.3s ease-in-out;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            padding: 1.5rem 0;
        }

        .sidebar.active {
            left: 0;
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Desktop sidebar */
        @media (min-width: 1024px) {
            .sidebar {
                width: var(--sidebar-width);
                left: 0;
                position: fixed;
                box-shadow: none;
            }

            .sidebar-overlay {
                display: none !important;
            }
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            color: white;
        }

        .sidebar-header p {
            color: #94a3b8;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .sidebar-header h2 {
                font-size: 1.5rem;
            }

            .sidebar-header p {
                font-size: 0.9rem;
            }
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
            min-height: 48px;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a:active,
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

        /* Main Content */
        .main-content {
            flex: 1;
            width: 100%;
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .main-content {
                padding: 2rem;
            }
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: var(--sidebar-width);
                padding: 2rem;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            padding: 0.5rem;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        @media (min-width: 1024px) {
            .mobile-menu-toggle {
                display: none;
            }
        }

        /* Header */
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

        @media (min-width: 640px) {
            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.5rem;
        }

        @media (min-width: 768px) {
            .header h1 {
                font-size: 1.8rem;
            }
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
            flex-shrink: 0;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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

        /* Stats Grid - Mobile Optimized */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                gap: 1rem;
            }
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.5rem;
            }
        }

        @media (min-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            border-left: 3px solid var(--primary);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease;
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            transform: rotate(45deg) translate(20px, -20px);
        }

        .stat-card.admin {
            border-left-color: var(--admin);
        }

        .stat-card.instructor {
            border-left-color: var(--instructor);
        }

        .stat-card.student {
            border-left-color: var(--student);
        }

        .stat-card.applicant {
            border-left-color: var(--applicant);
        }

        .stat-card.active {
            border-left-color: var(--success);
        }

        .stat-card.pending {
            border-left-color: var(--warning);
        }

        .stat-card.school {
            border-left-color: var(--school);
        }

        .stat-number {
            font-size: 1.3rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 2rem;
            }
        }

        .stat-label {
            color: #64748b;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (min-width: 768px) {
            .stat-label {
                font-size: 0.9rem;
            }
        }

        .stat-icon {
            font-size: 1.2rem;
            opacity: 0.5;
        }

        @media (min-width: 768px) {
            .stat-icon {
                font-size: 1.5rem;
            }
        }

        /* School Statistics */
        .school-stats {
            background: white;
            padding: 1.25rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .school-stats {
                padding: 1.5rem;
            }
        }

        .school-stats h3 {
            margin-bottom: 1rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        @media (min-width: 768px) {
            .school-stats h3 {
                font-size: 1.25rem;
            }
        }

        .schools-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .schools-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }

        @media (min-width: 1024px) {
            .schools-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .school-stat-item {
            padding: 0.875rem;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid var(--school);
        }

        .school-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .school-details {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: space-between;
            font-size: 0.8rem;
        }

        .school-count {
            background: var(--school);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Filters Card */
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
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }

        @media (min-width: 1024px) {
            .filter-form {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            color: #64748b;
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.6rem 1rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            min-height: 44px;
            text-decoration: none;
            width: 100%;
        }

        .btn:active {
            transform: scale(0.97);
        }

        @media (min-width: 640px) {
            .btn {
                width: auto;
                min-width: 120px;
            }
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover,
        .btn-primary:active {
            background: var(--secondary);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover,
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

        .btn-school {
            background: var(--school);
            color: white;
        }

        .btn-school:hover,
        .btn-school:active {
            background: #7c3aed;
        }

        .btn-sm {
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
            min-height: 36px;
        }

        /* Users Table */
        .users-table {
            background: white;
            border-radius: 10px;
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

        @media (min-width: 640px) {
            .table-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
        }

        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 0.875rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 0.875rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        /* Mobile card view for table on very small screens */
        @media (max-width: 640px) {
            .table-container {
                overflow-x: visible;
            }

            table {
                min-width: 0;
                display: block;
            }

            thead {
                display: none;
            }

            tbody {
                display: block;
            }

            tr {
                display: block;
                padding: 1rem;
                background: #f8fafc;
                border-radius: 8px;
                margin-bottom: 1rem;
                border: 1px solid #e2e8f0;
            }

            td {
                display: flex;
                align-items: flex-start;
                padding: 0.5rem 0;
                border-bottom: 1px solid #e2e8f0;
                font-size: 0.85rem;
                border-bottom: none;
            }

            td:before {
                content: attr(data-label);
                font-weight: 600;
                width: 90px;
                min-width: 90px;
                color: var(--dark);
            }

            td:last-child {
                border-bottom: none;
            }

            /* Special handling for specific columns */
            td[data-label="User"]:before {
                content: "User:";
            }

            td[data-label="Role"]:before {
                content: "Role:";
            }

            td[data-label="Status"]:before {
                content: "Status:";
            }

            td[data-label="School"]:before {
                content: "School:";
            }

            td[data-label="Location"]:before {
                content: "Location:";
            }

            td[data-label="Applications"]:before {
                content: "Apps:";
            }

            td[data-label="Enrollments"]:before {
                content: "Enroll:";
            }

            td[data-label="Registered"]:before {
                content: "Registered:";
            }

            td[data-label="Last Login"]:before {
                content: "Last Login:";
            }

            td[data-label="Actions"]:before {
                content: "Actions:";
            }

            .user-name-cell {
                width: 100%;
            }
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .user-name-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .user-name {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-email {
            font-size: 0.8rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-badge,
        .status-badge,
        .school-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .role-admin {
            background: #ede9fe;
            color: #5b21b6;
        }

        .role-instructor {
            background: #fef3c7;
            color: #92400e;
        }

        .role-student {
            background: #d1fae5;
            color: #065f46;
        }

        .role-applicant {
            background: #e2e8f0;
            color: #475569;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-rejected {
            background: #f1f5f9;
            color: #64748b;
        }

        .school-badge {
            background: #ede9fe;
            color: #5b21b6;
        }

        .actions {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .checkbox-cell input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .bulk-actions {
                flex-direction: row;
                align-items: center;
                padding: 1rem 1.5rem;
            }
        }

        .bulk-actions .form-control {
            width: 100%;
        }

        @media (min-width: 640px) {
            .bulk-actions .form-control {
                width: 200px;
            }
        }

        .selected-count {
            color: #64748b;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        /* Pagination */
        .pagination {
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        @media (min-width: 640px) {
            .pagination {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1rem 1.5rem;
            }
        }

        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 0.4rem 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
            min-width: 36px;
            text-align: center;
        }

        .page-link:hover,
        .page-link:active,
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .empty-state .btn {
            width: auto;
            margin: 0 0.25rem;
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Touch-friendly improvements */
        button,
        .btn,
        .nav-item,
        .page-link,
        input[type="checkbox"] {
            touch-action: manipulation;
        }

        /* Prevent text overflow */
        * {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* Improve scrolling */
        .sidebar,
        .table-container,
        .main-content {
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                            <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php" class="active">
                            <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/schools/manage.php">
                            <i class="fas fa-school"></i> Schools</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
                            <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-money-bill-wave"></i> Finance</a></li>
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
            <!-- Mobile Menu Toggle -->
            <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Header -->
            <div class="header">
                <h1>User Management</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo $_SESSION['user_name']; ?></div>
                        <div style="font-size: 0.8rem; color: #64748b;">Administrator</div>
                    </div>
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
                        <i class="fas fa-users stat-icon"></i>
                    </div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card admin">
                    <div class="stat-number">
                        <?php echo $stats['admins']; ?>
                        <i class="fas fa-user-shield stat-icon"></i>
                    </div>
                    <div class="stat-label">Admins</div>
                </div>
                <div class="stat-card instructor">
                    <div class="stat-number">
                        <?php echo $stats['instructors']; ?>
                        <i class="fas fa-chalkboard-teacher stat-icon"></i>
                    </div>
                    <div class="stat-label">Instructors</div>
                </div>
                <div class="stat-card student">
                    <div class="stat-number">
                        <?php echo $stats['students']; ?>
                        <i class="fas fa-user-graduate stat-icon"></i>
                    </div>
                    <div class="stat-label">Students</div>
                </div>
                <div class="stat-card school">
                    <div class="stat-number">
                        <?php echo $stats['total_schools']; ?>
                        <i class="fas fa-school stat-icon"></i>
                    </div>
                    <div class="stat-label">Schools</div>
                </div>
                <div class="stat-card active">
                    <div class="stat-number">
                        <?php echo $stats['active']; ?>
                        <i class="fas fa-check-circle stat-icon"></i>
                    </div>
                    <div class="stat-label">Active</div>
                </div>
            </div>

            <!-- School Statistics -->
            <?php if (!empty($school_stats)): ?>
                <div class="school-stats">
                    <h3>School Statistics</h3>
                    <div class="schools-grid">
                        <?php foreach ($school_stats as $school): ?>
                            <div class="school-stat-item">
                                <div class="school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                                <div class="school-details">
                                    <span>
                                        <i class="fas fa-users" style="color: #64748b; margin-right: 0.25rem;"></i>
                                        <?php echo $school['user_count']; ?> users
                                    </span>
                                    <span class="school-count">
                                        <?php echo $school['students']; ?> students
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Users</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" class="form-control">
                            <option value="all" <?php echo $role === 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="instructor" <?php echo $role === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                            <option value="student" <?php echo $role === 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="applicant" <?php echo $role === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>School</label>
                        <select name="school_id" class="form-control">
                            <option value="all" <?php echo $school_id === 'all' ? 'selected' : ''; ?>>All Schools</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>"
                                    <?php echo $school_id == $school['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school['name']); ?>
                                </option>
                            <?php endforeach; ?>
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
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="users-table">
                <div class="table-header">
                    <h3>Users List (<?php echo count($users); ?>)</h3>
                    <div class="header-actions">
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> New User
                        </a>
                        <a href="<?php echo BASE_URL; ?>modules/admin/schools/manage.php" class="btn btn-school btn-sm">
                            <i class="fas fa-school"></i> Schools
                        </a>
                    </div>
                </div>

                <form method="POST" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <?php if (!empty($users)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>School</th>
                                        <th>Location</th>
                                        <th>Apps</th>
                                        <th>Enroll</th>
                                        <th>Registered</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user):
                                        $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
                                    ?>
                                        <tr>
                                            <td class="checkbox-cell" data-label="">
                                                <input type="checkbox" name="selected_users[]"
                                                    value="<?php echo $user['id']; ?>" class="user-checkbox">
                                            </td>
                                            <td data-label="User">
                                                <div class="user-name-cell">
                                                    <div class="user-avatar-small">
                                                        <?php echo $initials; ?>
                                                    </div>
                                                    <div class="user-details">
                                                        <div class="user-name">
                                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                        </div>
                                                        <div class="user-email">
                                                            <?php echo htmlspecialchars($user['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Role">
                                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td data-label="School">
                                                <?php if ($user['school_name']): ?>
                                                    <span class="school-badge">
                                                        <?php echo htmlspecialchars($user['school_name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #64748b; font-size: 0.8rem;">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Location">
                                                <?php if ($user['city'] && $user['state']): ?>
                                                    <?php echo htmlspecialchars($user['city']); ?><br>
                                                    <small style="color: #64748b;"><?php echo htmlspecialchars($user['state']); ?></small>
                                                <?php else: ?>
                                                    <span style="color: #64748b;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Applications">
                                                <span style="font-weight: 500;"><?php echo $user['application_count']; ?></span>
                                            </td>
                                            <td data-label="Enrollments">
                                                <span style="font-weight: 500;"><?php echo $user['enrollment_count']; ?></span>
                                            </td>
                                            <td data-label="Registered">
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td data-label="Last Login">
                                                <?php if ($user['last_login']): ?>
                                                    <?php echo date('M j, Y', strtotime($user['last_login'])); ?>
                                                <?php else: ?>
                                                    <span style="color: #64748b;">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="actions">
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $user['id']; ?>"
                                                        class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php?edit=<?php echo $user['id']; ?>"
                                                        class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($user['status'] === 'active' && $user['role'] !== 'admin'): ?>
                                                        <button type="button" onclick="suspendUser(<?php echo $user['id']; ?>)"
                                                            class="btn btn-danger btn-sm">
                                                            <i class="fas fa-pause"></i>
                                                        </button>
                                                    <?php elseif ($user['status'] === 'suspended'): ?>
                                                        <button type="button" onclick="activateUser(<?php echo $user['id']; ?>)"
                                                            class="btn btn-success btn-sm">
                                                            <i class="fas fa-play"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <select name="bulk_action" class="form-control">
                                <option value="">Bulk Actions</option>
                                <option value="active">Activate</option>
                                <option value="suspended">Suspend</option>
                                <option value="pending">Mark Pending</option>
                                <option value="rejected">Reject</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> Apply
                            </button>
                            <span class="selected-count">
                                <span id="selectedCount">0</span> selected
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Users Found</h3>
                            <p>Try adjusting your filters or create a new user.</p>
                            <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                                <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> New User
                                </a>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo count($users); ?> users
                    </div>
                    <div class="page-numbers">
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <a href="#" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Select all checkbox functionality
        document.getElementById('selectAll')?.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.user-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Bulk form submission confirmation
        document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
            const action = this.bulk_action.value;
            const selectedCount = document.querySelectorAll('.user-checkbox:checked').length;

            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one user.');
                return false;
            }

            const actionText = action === 'active' ? 'activate' :
                action === 'suspended' ? 'suspend' :
                action === 'pending' ? 'mark as pending' :
                'reject';

            if (!confirm(`Are you sure you want to ${actionText} ${selectedCount} user(s)?`)) {
                e.preventDefault();
                return false;
            }
        });

        // Individual user actions
        function suspendUser(userId) {
            if (confirm('Are you sure you want to suspend this user? They will not be able to access the system.')) {
                window.location.href = 'action.php?action=suspend&id=' + userId + '&token=<?php echo generateCSRFToken(); ?>';
            }
        }

        function activateUser(userId) {
            if (confirm('Are you sure you want to activate this user?')) {
                window.location.href = 'action.php?action=activate&id=' + userId + '&token=<?php echo generateCSRFToken(); ?>';
            }
        }

        // Update selected count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const toggle = document.querySelector('.mobile-menu-toggle');

                if (window.innerWidth < 1024 &&
                    sidebar.classList.contains('active') &&
                    !sidebar.contains(event.target) &&
                    !toggle.contains(event.target)) {
                    closeSidebar();
                }
            });

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 1024) {
                        closeSidebar();
                    }
                }, 250);
            });
        });

        // Quick search with debounce
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimer;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        // Escape key to close sidebar
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        // Touch-friendly improvements
        document.querySelectorAll('.btn, .page-link, .sidebar-nav a').forEach(element => {
            element.addEventListener('touchstart', function() {
                // Just for touch feedback, no action needed
            }, {
                passive: true
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>