<?php
// modules/admin/crash_program/admin_crash.php

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

// Get settings
$settings_sql = "SELECT setting_key, setting_value FROM crash_program_settings";
$settings_result = $conn->query($settings_sql);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submissions
$message = '';
$error = '';

// Update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $settings_to_update = [
            'total_spots',
            'program_fee',
            'payment_deadline_days',
            'program_start_date',
            'program_end_date',
            'admin_whatsapp',
            'bank_name',
            'account_name',
            'account_number'
        ];

        foreach ($settings_to_update as $setting_key) {
            $post_key = 'setting_' . $setting_key;
            if (isset($_POST[$post_key])) {
                $update_sql = "UPDATE crash_program_settings SET setting_value = ? WHERE setting_key = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('ss', $_POST[$post_key], $setting_key);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
        $message = 'Settings updated successfully.';

        // Refresh settings
        $settings_result = $conn->query($settings_sql);
        $settings = [];
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
}

// Confirm payment manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $registration_id = intval($_POST['registration_id']);

        // Update registration
        $update_sql = "UPDATE crash_program_registrations 
                      SET payment_status = 'confirmed', 
                          status = 'payment_confirmed',
                          payment_confirmed_at = NOW()
                      WHERE id = ? AND payment_status = 'pending'";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $registration_id);

        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
            // Get registration details to send email
            $reg_sql = "SELECT * FROM crash_program_registrations WHERE id = ?";
            $reg_stmt = $conn->prepare($reg_sql);
            $reg_stmt->bind_param('i', $registration_id);
            $reg_stmt->execute();
            $registration = $reg_stmt->get_result()->fetch_assoc();
            $reg_stmt->close();

            // Send payment confirmation email if function exists
            if (function_exists('sendCrashProgramPaymentConfirmationEmail')) {
                sendCrashProgramPaymentConfirmationEmail($registration);
            }

            // Create user in main portal if not exists
            if (!$registration['portal_user_created'] && function_exists('createCrashProgramPortalUser')) {
                createCrashProgramPortalUser($registration, $conn);
            }

            $message = 'Payment confirmed for ' . htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']);

            // Log activity
            logActivity($_SESSION['user_id'], 'crash_payment_confirm', "Confirmed payment for #$registration_id");
        } elseif ($update_stmt->affected_rows == 0) {
            $error = 'Payment already confirmed or registration not found.';
        } else {
            $error = 'Failed to confirm payment.';
        }
        $update_stmt->close();
    }
}

// Send program details email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_program_details'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $registration_id = intval($_POST['registration_id']);

        // Get registration details
        $reg_sql = "SELECT * FROM crash_program_registrations WHERE id = ?";
        $reg_stmt = $conn->prepare($reg_sql);
        $reg_stmt->bind_param('i', $registration_id);
        $reg_stmt->execute();
        $registration = $reg_stmt->get_result()->fetch_assoc();
        $reg_stmt->close();

        if ($registration) {
            // Send program details email
            if (function_exists('sendCrashProgramDetailsEmail')) {
                sendCrashProgramDetailsEmail($registration);
            }

            $message = 'Program details sent to ' . htmlspecialchars($registration['email']);

            // Log activity
            logActivity($_SESSION['user_id'], 'crash_send_details', "Sent program details to #$registration_id");
        } else {
            $error = 'Registration not found.';
        }
    }
}

// Get all registrations with filters
$status_filter = $_GET['status'] ?? 'all';
$payment_filter = $_GET['payment'] ?? 'all';
$program_filter = $_GET['program'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM crash_program_registrations WHERE 1=1";
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($payment_filter !== 'all') {
    $sql .= " AND payment_status = ?";
    $params[] = $payment_filter;
    $types .= "s";
}

if ($program_filter !== 'all') {
    $sql .= " AND program_choice = ?";
    $params[] = $program_filter;
    $types .= "s";
}

if (!empty($search)) {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
}

$sql .= " ORDER BY registered_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_payment,
    SUM(CASE WHEN program_choice = 'web_development' THEN 1 ELSE 0 END) as web_dev,
    SUM(CASE WHEN program_choice = 'ai_faceless_video' THEN 1 ELSE 0 END) as ai_video,
    SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END) as status_pending,
    SUM(CASE WHEN status = 'payment_confirmed' THEN 1 ELSE 0 END) as status_confirmed
FROM crash_program_registrations";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$total_spots = intval($settings['total_spots'] ?? 50);
$confirmed_count = intval($stats['confirmed'] ?? 0);
$spots_left = $total_spots - $confirmed_count;

// Function to get program display name
function getProgramDisplay($program_choice)
{
    return $program_choice === 'web_development' ? 'Web Development' : 'AI Faceless Video';
}

// Get pending payment registrations for reminder
$pending_sql = "SELECT * FROM crash_program_registrations 
                WHERE payment_status = 'pending' 
                AND registered_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)";
$pending_result = $conn->query($pending_sql);
$pending_registrations = $pending_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Crash Program Management - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
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
        }

        body {
            background: var(--gray-100);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: var(--gray-800);
            line-height: 1.5;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }

        html {
            height: -webkit-fill-available;
        }

        /* Mobile Header */
        .mobile-header {
            background: var(--dark);
            color: white;
            padding: 0.75rem 1rem;
            padding-top: max(0.75rem, var(--safe-top));
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .mobile-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            transition: background 0.2s;
        }

        .mobile-menu-btn:active {
            background: rgba(255, 255, 255, 0.1);
        }

        .mobile-header-title h2 {
            font-size: 1rem;
            font-weight: 600;
        }

        .mobile-header-title p {
            font-size: 0.7rem;
            color: var(--gray-400);
        }

        .mobile-user {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* Mobile Sidebar */
        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -100%;
            width: min(85%, 300px);
            height: 100%;
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

        .mobile-sidebar-header {
            padding: 1.5rem;
            padding-top: max(1.5rem, var(--safe-top));
            border-bottom: 1px solid var(--gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mobile-sidebar-header h2 {
            font-size: 1.2rem;
        }

        .mobile-sidebar-header p {
            font-size: 0.8rem;
            color: var(--gray-400);
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
        }

        .close-sidebar:active {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li a {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            color: var(--gray-300);
            text-decoration: none;
            gap: 0.75rem;
            transition: background 0.2s;
            font-size: 0.95rem;
        }

        .sidebar-nav li a:active {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 3px solid var(--primary);
        }

        .sidebar-nav li a i {
            width: 24px;
        }

        /* Desktop Sidebar */
        .desktop-sidebar {
            display: none;
            width: 260px;
            background: var(--dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 1rem;
            padding-bottom: calc(1rem + var(--safe-bottom));
        }

        @media (min-width: 1024px) {
            .mobile-header {
                display: none;
            }

            .desktop-sidebar {
                display: block;
            }

            .main-content {
                margin-left: 260px;
                padding: 2rem;
            }
        }

        /* Institution Banner */
        .institution-banner {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 20px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            color: white;
        }

        .institution-banner h1 {
            font-size: 1.2rem;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .institution-banner p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        /* Stats Grid - Mobile First */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 3px solid var(--primary);
            transition: transform 0.2s;
        }

        .stat-card:active {
            transform: scale(0.98);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.info {
            border-left-color: var(--info);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (min-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(6, 1fr);
            }

            .stat-number {
                font-size: 1.8rem;
            }
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        @media (min-width: 640px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        /* Filters - Mobile Optimized */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-select {
            flex: 1;
            min-width: 120px;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            background: white;
            font-size: 0.85rem;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 12px;
            padding-right: 2rem;
        }

        .search-box {
            display: flex;
            gap: 0.5rem;
            width: 100%;
        }

        .search-box input {
            flex: 1;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            font-size: 0.85rem;
        }

        .search-box button {
            padding: 0.6rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
        }

        /* Settings Form - Mobile Optimized */
        .settings-form {
            padding: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        @media (min-width: 640px) {
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .form-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 500;
            font-size: 0.8rem;
            color: var(--gray-600);
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 0.85rem;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Mobile Cards for Registrations */
        .registration-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 0.75rem;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 3px solid var(--gray-300);
            transition: transform 0.2s;
        }

        .registration-card:active {
            transform: scale(0.99);
        }

        .registration-card.confirmed {
            border-left-color: var(--success);
        }

        .registration-card.pending {
            border-left-color: var(--warning);
        }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .registration-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--gray-800);
        }

        .registration-id {
            font-size: 0.7rem;
            color: var(--gray-400);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed,
        .status-payment_confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending,
        .status-pending_payment {
            background: #fef3c7;
            color: #92400e;
        }

        .registration-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin: 0.75rem 0;
            padding: 0.75rem 0;
            border-top: 1px solid var(--gray-100);
            border-bottom: 1px solid var(--gray-100);
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--gray-600);
        }

        .detail-item i {
            width: 20px;
            color: var(--gray-400);
        }

        .registration-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        /* Buttons - Touch Friendly */
        .btn {
            padding: 0.6rem 1rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
            min-height: 44px;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:active {
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

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.7rem;
            min-height: 36px;
        }

        .btn-block {
            width: 100%;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 3px solid var(--danger);
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 3px solid var(--warning);
        }

        /* Pending Reminder */
        .pending-reminder {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
        }

        .pending-list {
            margin-top: 0.5rem;
            padding-left: 1rem;
            font-size: 0.8rem;
        }

        .pending-list li {
            margin-bottom: 0.25rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        /* Desktop Table (Hidden on Mobile) */
        .desktop-table {
            display: none;
        }

        @media (min-width: 1024px) {
            .registration-card {
                display: none;
            }

            .desktop-table {
                display: block;
                overflow-x: auto;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 1rem;
                text-align: left;
                border-bottom: 1px solid var(--gray-200);
                font-size: 0.85rem;
            }

            th {
                background: var(--gray-50);
                font-weight: 600;
                color: var(--gray-600);
            }

            tr:hover {
                background: var(--gray-50);
            }
        }

        /* Action Buttons Group */
        .action-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Program Icon */
        .program-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 1rem;
            font-size: 0.7rem;
            color: var(--gray-400);
            margin-top: 1rem;
        }

        /* Touch Optimizations */
        button,
        a,
        .filter-select,
        .stat-card,
        .registration-card {
            touch-action: manipulation;
        }

        /* Pull to refresh indicator */
        .ptr-indicator {
            display: none;
            text-align: center;
            padding: 0.5rem;
            color: var(--gray-400);
            font-size: 0.7rem;
        }

        /* Prevent pull-to-refresh on mobile */
        body {
            overscroll-behavior-y: contain;
        }
    </style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="mobile-header-left">
            <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="mobile-header-title">
                <h2>Crash Program Admin</h2>
                <p>Impact Digital Academy</p>
            </div>
        </div>
        <div class="mobile-user">
            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Mobile Sidebar -->
    <div class="mobile-sidebar" id="mobileSidebar">
        <div class="mobile-sidebar-header">
            <div>
                <h2>Impact Digital Academy</h2>
                <p>Crash Program Admin</p>
            </div>
            <button class="close-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/crash_program/admin_crash.php" class="active">
                        <i class="fas fa-rocket"></i> Crash Program
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                        <i class="fas fa-file-alt"></i> Applications
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                        <i class="fas fa-users"></i> Users
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a></li>
            </ul>
        </nav>
    </div>

    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--gray-700);">
            <h2 style="font-size: 1.25rem;">Impact Digital Academy</h2>
            <p style="font-size: 0.8rem; color: var(--gray-400);">Crash Program Admin</p>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/crash_program/admin_crash.php" class="active">
                        <i class="fas fa-rocket"></i> Crash Program
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                        <i class="fas fa-file-alt"></i> Applications
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                        <i class="fas fa-users"></i> Users
                    </a></li>
                <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a></li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Institution Banner -->
        <div class="institution-banner">
            <h1>
                <i class="fas fa-rocket"></i>
                Crash Program Management
            </h1>
            <p>2-Week Intensive Program: Web Development & AI Faceless Video Creation (April 13-24, 2026)</p>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($pending_registrations)): ?>
            <div class="alert alert-warning pending-reminder">
                <i class="fas fa-clock"></i>
                <div style="flex:1">
                    <strong><?php echo count($pending_registrations); ?> pending payment(s) for &gt;2 days</strong>
                    <ul class="pending-list">
                        <?php foreach (array_slice($pending_registrations, 0, 3) as $pending): ?>
                            <li><?php echo htmlspecialchars($pending['first_name'] . ' ' . $pending['last_name']); ?> - <?php echo date('M j', strtotime($pending['registered_at'])); ?></li>
                        <?php endforeach; ?>
                        <?php if (count($pending_registrations) > 3): ?>
                            <li>+<?php echo count($pending_registrations) - 3; ?> more</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?php echo $stats['confirmed'] ?? 0; ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $stats['pending_payment'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number <?php echo $spots_left <= 10 ? 'text-warning' : ''; ?>">
                    <?php echo $spots_left; ?>
                </div>
                <div class="stat-label">Spots Left</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['web_dev'] ?? 0; ?></div>
                <div class="stat-label">Web Dev</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['ai_video'] ?? 0; ?></div>
                <div class="stat-label">AI Video</div>
            </div>
        </div>

        <!-- Settings Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-cog"></i> Program Settings</h3>
                <button class="btn btn-primary btn-sm" onclick="toggleSettings()" style="display: none;" id="toggleSettingsBtn">
                    <i class="fas fa-chevron-down"></i> Show/Hide
                </button>
            </div>
            <div id="settingsContent">
                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Total Spots</label>
                            <input type="number" name="setting_total_spots" class="form-control"
                                value="<?php echo htmlspecialchars($settings['total_spots'] ?? 50); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Program Fee (₦)</label>
                            <input type="number" name="setting_program_fee" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_fee'] ?? 10000); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Payment Deadline (days)</label>
                            <input type="number" name="setting_payment_deadline_days" class="form-control"
                                value="<?php echo htmlspecialchars($settings['payment_deadline_days'] ?? 3); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                            <input type="date" name="setting_program_start_date" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_start_date'] ?? '2026-04-13'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> End Date</label>
                            <input type="date" name="setting_program_end_date" class="form-control"
                                value="<?php echo htmlspecialchars($settings['program_end_date'] ?? '2026-04-24'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fab fa-whatsapp"></i> Admin WhatsApp</label>
                            <input type="text" name="setting_admin_whatsapp" class="form-control"
                                value="<?php echo htmlspecialchars($settings['admin_whatsapp'] ?? '+2349051586024'); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name</label>
                            <input type="text" name="setting_bank_name" class="form-control"
                                value="<?php echo htmlspecialchars($settings['bank_name'] ?? 'MoniePoint Microfinance Bank'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Account Name</label>
                            <input type="text" name="setting_account_name" class="form-control"
                                value="<?php echo htmlspecialchars($settings['account_name'] ?? 'Impact Digital Academy'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account Number</label>
                            <input type="text" name="setting_account_number" class="form-control"
                                value="<?php echo htmlspecialchars($settings['account_number'] ?? '6658393500'); ?>" required>
                        </div>
                    </div>

                    <button type="submit" name="update_settings" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>

        <!-- Filters Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Registrations</h3>
            </div>
            <div class="settings-form" style="padding-top: 0;">
                <form method="GET" style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div class="filters">
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending_payment" <?php echo $status_filter == 'pending_payment' ? 'selected' : ''; ?>>Pending Payment</option>
                            <option value="payment_confirmed" <?php echo $status_filter == 'payment_confirmed' ? 'selected' : ''; ?>>Payment Confirmed</option>
                        </select>
                        <select name="payment" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $payment_filter == 'all' ? 'selected' : ''; ?>>All Payment</option>
                            <option value="pending" <?php echo $payment_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $payment_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        </select>
                        <select name="program" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $program_filter == 'all' ? 'selected' : ''; ?>>All Programs</option>
                            <option value="web_development" <?php echo $program_filter == 'web_development' ? 'selected' : ''; ?>>Web Dev</option>
                            <option value="ai_faceless_video" <?php echo $program_filter == 'ai_faceless_video' ? 'selected' : ''; ?>>AI Video</option>
                        </select>
                    </div>
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search by name, email, phone..."
                            value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-sm">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Registrations -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Registrations (<?php echo count($registrations); ?>)</h3>
                <a href="<?php echo BASE_URL; ?>modules/admin/crash_program/export.php<?php echo $status_filter != 'all' ? '?status=' . $status_filter : ''; ?>"
                    class="btn btn-primary btn-sm">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>

            <?php if (!empty($registrations)): ?>
                <!-- Desktop Table View -->
                <div class="desktop-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Program</th>
                                <th>Payment</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $reg): ?>
                                <tr>
                                    <td>#<?php echo $reg['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']); ?></strong>
                                    </td>
                                    <td>
                                        <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($reg['email']); ?></div>
                                        <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($reg['phone']); ?></div>
                                    </td>
                                    <td>
                                        <span class="program-icon">
                                            <?php if ($reg['program_choice'] == 'web_development'): ?>
                                                <i class="fas fa-code" style="color: var(--primary);"></i> Web Dev
                                            <?php else: ?>
                                                <i class="fas fa-video" style="color: var(--warning);"></i> AI Video
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $reg['payment_status']; ?>">
                                            <?php echo $reg['payment_status'] == 'confirmed' ? '✓ Confirmed' : '⏳ Pending'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($reg['registered_at'])); ?></td>
                                    <td class="action-group">
                                        <?php if ($reg['payment_status'] == 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <button type="submit" name="confirm_payment" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i> Confirm
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                            <button type="submit" name="send_program_details" class="btn btn-primary btn-sm">
                                                <i class="fas fa-envelope"></i> Details
                                            </button>
                                        </form>
                                        <a href="mailto:<?php echo urlencode($reg['email']); ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-paper-plane"></i> Email
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <?php foreach ($registrations as $reg): ?>
                    <div class="registration-card <?php echo $reg['payment_status']; ?>">
                        <div class="card-header-row">
                            <div>
                                <div class="registration-name">
                                    <?php echo htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']); ?>
                                </div>
                                <div class="registration-id">ID: #<?php echo $reg['id']; ?></div>
                            </div>
                            <span class="status-badge status-<?php echo $reg['payment_status']; ?>">
                                <?php echo $reg['payment_status'] == 'confirmed' ? '✓ Paid' : '⏳ Pending'; ?>
                            </span>
                        </div>

                        <div class="registration-details">
                            <div class="detail-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo htmlspecialchars($reg['email']); ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo htmlspecialchars($reg['phone']); ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-code"></i>
                                <span><?php echo getProgramDisplay($reg['program_choice']); ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-calendar"></i>
                                <span><?php echo date('M j, Y', strtotime($reg['registered_at'])); ?></span>
                            </div>
                            <?php if ($reg['school_name']): ?>
                                <div class="detail-item">
                                    <i class="fas fa-school"></i>
                                    <span><?php echo htmlspecialchars($reg['school_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($reg['transaction_reference']): ?>
                                <div class="detail-item">
                                    <i class="fas fa-receipt"></i>
                                    <span><?php echo htmlspecialchars($reg['transaction_reference']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="registration-actions">
                            <?php if ($reg['payment_status'] == 'pending'): ?>
                                <form method="POST" style="flex:1">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                    <button type="submit" name="confirm_payment" class="btn btn-success btn-block">
                                        <i class="fas fa-check"></i> Confirm Payment
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="flex:1">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                <button type="submit" name="send_program_details" class="btn btn-primary btn-block">
                                    <i class="fas fa-envelope"></i> Send Details
                                </button>
                            </form>
                            <a href="mailto:<?php echo urlencode($reg['email']); ?>" class="btn btn-warning btn-block">
                                <i class="fas fa-paper-plane"></i> Email
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Registrations Found</h3>
                    <p>Try adjusting your filters or check back later.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Impact Digital Academy. All rights reserved.</p>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');

            if (sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Close sidebar when clicking links on mobile
        document.querySelectorAll('.mobile-sidebar .sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                toggleSidebar();
            });
        });

        // Toggle settings visibility on mobile
        function toggleSettings() {
            const content = document.getElementById('settingsContent');
            const btn = document.getElementById('toggleSettingsBtn');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-chevron-up"></i> Hide';
            } else {
                content.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-chevron-down"></i> Show';
            }
        }

        // Show toggle button only on mobile
        if (window.innerWidth < 768) {
            document.getElementById('toggleSettingsBtn').style.display = 'block';
            document.getElementById('settingsContent').style.display = 'none';
        }

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

        // Touch feedback
        document.querySelectorAll('.btn, .stat-card, .registration-card, .filter-select').forEach(el => {
            el.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            });
            el.addEventListener('touchend', function() {
                this.style.opacity = '';
            });
        });

        // Prevent double-tap zoom
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // Auto-refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>

</html>
<?php $conn->close(); ?>