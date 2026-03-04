<?php
$page_title = 'Manage Schools';
require_once 'auth_check.php';
require_once '../api/config.php';

// Handle form submissions
$message = '';
$message_type = '';

// Generate unique API key
function generateApiKey()
{
    return bin2hex(random_bytes(16)); // 32 character hex string
}

// Generate school code
function generateSchoolCode($name)
{
    // Take first 3 letters of school name, uppercase, add random numbers
    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 3));
    if (strlen($prefix) < 3) {
        $prefix = str_pad($prefix, 3, 'X');
    }
    $suffix = rand(100, 999);
    return $prefix . $suffix;
}

// Add new school
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $school_name = trim($_POST['school_name']);
    $contact_email = trim($_POST['contact_email']);
    $contact_phone = trim($_POST['contact_phone']);
    $address = trim($_POST['address']);
    $subscription_status = $_POST['subscription_status'] ?? 'trial';
    $subscription_expiry = $_POST['subscription_expiry'] ?? date('Y-m-d', strtotime('+30 days'));
    $max_users = intval($_POST['max_users'] ?? 100);

    if (!empty($school_name)) {
        try {
            // Generate unique school code and API key
            $school_code = generateSchoolCode($school_name);
            $api_key = generateApiKey();

            // Check if code exists
            $check = $pdo->prepare("SELECT id FROM schools WHERE school_code = ?");
            $check->execute([$school_code]);
            if ($check->fetch()) {
                // Regenerate code if exists
                $school_code = generateSchoolCode($school_name) . rand(10, 99);
            }

            $stmt = $pdo->prepare("
                INSERT INTO schools 
                (school_name, school_code, contact_email, contact_phone, address, 
                 subscription_status, subscription_expiry, max_users, api_key) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $school_name,
                $school_code,
                $contact_email,
                $contact_phone,
                $address,
                $subscription_status,
                $subscription_expiry,
                $max_users,
                $api_key
            ]);

            $new_id = $pdo->lastInsertId();
            $message = "School added successfully! API Key generated.";
            $message_type = "success";

            // Show API key to admin (only once)
            $_SESSION['new_api_key'] = $api_key;
            $_SESSION['new_school_name'] = $school_name;
        } catch (PDOException $e) {
            $message = "Error adding school: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "School name is required!";
        $message_type = "error";
    }
}

// Edit school
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = intval($_POST['id']);
    $school_name = trim($_POST['school_name']);
    $contact_email = trim($_POST['contact_email']);
    $contact_phone = trim($_POST['contact_phone']);
    $address = trim($_POST['address']);
    $subscription_status = $_POST['subscription_status'] ?? 'active';
    $subscription_expiry = $_POST['subscription_expiry'];
    $max_users = intval($_POST['max_users'] ?? 100);
    $regenerate_api = isset($_POST['regenerate_api']);

    if ($id > 0 && !empty($school_name)) {
        try {
            if ($regenerate_api) {
                $api_key = generateApiKey();
                $stmt = $pdo->prepare("
                    UPDATE schools SET 
                        school_name = ?, contact_email = ?, contact_phone = ?, 
                        address = ?, subscription_status = ?, subscription_expiry = ?,
                        max_users = ?, api_key = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $school_name,
                    $contact_email,
                    $contact_phone,
                    $address,
                    $subscription_status,
                    $subscription_expiry,
                    $max_users,
                    $api_key,
                    $id
                ]);
                $_SESSION['new_api_key'] = $api_key;
                $_SESSION['new_school_name'] = $school_name;
                $message = "School updated with new API key!";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE schools SET 
                        school_name = ?, contact_email = ?, contact_phone = ?, 
                        address = ?, subscription_status = ?, subscription_expiry = ?,
                        max_users = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $school_name,
                    $contact_email,
                    $contact_phone,
                    $address,
                    $subscription_status,
                    $subscription_expiry,
                    $max_users,
                    $id
                ]);
                $message = "School updated successfully!";
            }
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating school: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Delete school
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Check if school has any activity
    $check = $pdo->prepare("SELECT COUNT(*) FROM question_downloads WHERE school_id = ?");
    $check->execute([$id]);
    $download_count = $check->fetchColumn();

    $check = $pdo->prepare("SELECT COUNT(*) FROM api_logs WHERE school_id = ?");
    $check->execute([$id]);
    $log_count = $check->fetchColumn();

    if ($download_count > 0 || $log_count > 0) {
        $message = "Cannot delete school with existing activity. Deactivate it instead.";
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM schools WHERE id = ?");
            $stmt->execute([$id]);
            $message = "School deleted successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error deleting school: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Regenerate API key
if (isset($_GET['regenerate_key'])) {
    $id = intval($_GET['regenerate_key']);
    $new_key = generateApiKey();

    try {
        $stmt = $pdo->prepare("UPDATE schools SET api_key = ? WHERE id = ?");
        $stmt->execute([$new_key, $id]);

        // Get school name
        $stmt = $pdo->prepare("SELECT school_name FROM schools WHERE id = ?");
        $stmt->execute([$id]);
        $school = $stmt->fetch();

        $_SESSION['new_api_key'] = $new_key;
        $_SESSION['new_school_name'] = $school['school_name'];
        $message = "API key regenerated successfully!";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Error regenerating API key: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all schools with stats
$sql = "SELECT s.*,
        (SELECT COUNT(*) FROM question_downloads WHERE school_id = s.id) as total_downloads,
        (SELECT COUNT(*) FROM question_downloads WHERE school_id = s.id AND DATE(downloaded_at) = CURDATE()) as today_downloads,
        (SELECT MAX(downloaded_at) FROM question_downloads WHERE school_id = s.id) as last_download,
        (SELECT COUNT(*) FROM api_logs WHERE school_id = s.id AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as api_calls_24h
        FROM schools s
        ORDER BY s.created_at DESC";

$stmt = $pdo->query($sql);
$schools = $stmt->fetchAll();

// Get school for editing
$edit_school = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$id]);
    $edit_school = $stmt->fetch();
}

// Status colors for subscription
$status_colors = [
    'active' => '#2ecc71',
    'inactive' => '#e74c3c',
    'trial' => '#f39c12'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Central CBT - <?php echo $page_title; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="../../portals/public/images/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #4361ee;
            --primary-dark: #3a56d4;
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --border-color: #dee2e6;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: var(--dark-text);
            line-height: 1.6;
        }

        /* Layout */
        .wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .nav-links {
            list-style: none;
            padding: 20px 0;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 10px;
        }

        .nav-links li a i {
            width: 20px;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding-left: 25px;
        }

        .nav-links li.logout {
            margin-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 10px;
        }

        .user-info {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            min-width: 0;
        }

        .top-bar {
            background: #fff;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--secondary-color);
            display: none;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-title i {
            color: var(--primary-color);
        }

        .date {
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        .content-wrapper {
            padding: 20px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.5;
        }

        .close-alert:hover {
            opacity: 1;
        }

        /* API Key Display */
        .api-key-display {
            background: #2c3e50;
            color: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            animation: slideDown 0.3s ease;
        }

        .api-key-display .key-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .api-key-display .key-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .api-key-display .key-value {
            font-family: monospace;
            font-size: 1.2rem;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 4px;
            letter-spacing: 1px;
        }

        .api-key-display .btn-sm {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .api-key-display .btn-sm:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Cards */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            background: #fff;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-header h2 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2 i {
            color: var(--primary-color);
        }

        .card-body {
            padding: 20px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 500;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary {
            background: #e3f2fd;
            color: var(--primary-color);
        }

        .stat-icon.success {
            background: #d4edda;
            color: var(--success-color);
        }

        .stat-icon.warning {
            background: #fff3cd;
            color: var(--warning-color);
        }

        .stat-icon.info {
            background: #d1ecf1;
            color: var(--info-color);
        }

        .stat-details h3 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .stat-details p {
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        /* Forms */
        .form-container {
            max-width: 800px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group label i {
            color: var(--primary-color);
            margin-right: 5px;
            width: 16px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-group input.error {
            border-color: var(--danger-color);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            text-align: left;
            padding: 12px;
            background: var(--light-bg);
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark-text);
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        tr:hover {
            background: var(--light-bg);
        }

        .school-info {
            display: flex;
            flex-direction: column;
        }

        .school-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .school-code {
            font-size: 0.8rem;
            color: var(--secondary-color);
            font-family: monospace;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.85rem;
        }

        .contact-info i {
            width: 16px;
            color: var(--primary-color);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-trial {
            background: #fff3cd;
            color: #856404;
        }

        .expiry-warning {
            color: var(--danger-color);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .expiry-ok {
            color: var(--success-color);
        }

        .stats-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #e3f2fd;
            color: var(--primary-color);
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .api-key-preview {
            font-family: monospace;
            font-size: 0.8rem;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            background: #f8f9fa;
            padding: 4px 6px;
            border-radius: 4px;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 4px;
        }

        .action-btn.edit {
            color: var(--primary-color);
            background: #e3f2fd;
        }

        .action-btn.edit:hover {
            background: var(--primary-color);
            color: white;
        }

        .action-btn.key {
            color: var(--warning-color);
            background: #fff3cd;
        }

        .action-btn.key:hover {
            background: var(--warning-color);
            color: white;
        }

        .action-btn.delete {
            color: var(--danger-color);
            background: #ffebee;
        }

        .action-btn.delete:hover {
            background: var(--danger-color);
            color: white;
        }

        .action-btn.stats {
            color: var(--info-color);
            background: #d1ecf1;
        }

        .action-btn.stats:hover {
            background: var(--info-color);
            color: white;
        }

        /* Loading Spinner */
        .loading-spinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            z-index: 2000;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .top-bar {
                padding: 12px 15px;
            }

            .content-wrapper {
                padding: 15px;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .date {
                display: none;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .card-header .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .api-key-display {
                flex-direction: column;
                align-items: flex-start;
            }

            .api-key-display .key-info {
                width: 100%;
            }

            .api-key-display .key-value {
                font-size: 0.9rem;
                word-break: break-all;
            }

            .action-buttons {
                justify-content: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stat-card {
                flex-direction: column;
                text-align: center;
            }

            .user-info span {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Central CBT</h3>
                <p>Admin Panel</p>
            </div>

            <ul class="nav-links">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="subjects.php">
                        <i class="fas fa-book"></i> Subjects
                    </a>
                </li>
                <li>
                    <a href="topics.php">
                        <i class="fas fa-tags"></i> Topics
                    </a>
                </li>
                <li>
                    <a href="manage_questions.php">
                        <i class="fas fa-question-circle"></i> Questions
                    </a>
                </li>
                <li>
                    <a href="upload.php">
                        <i class="fas fa-upload"></i> Bulk Upload
                    </a>
                </li>
                <li>
                    <a href="manage_schools.php" class="active">
                        <i class="fas fa-school"></i> Schools
                    </a>
                </li>
                <li>
                    <a href="view_stats.php">
                        <i class="fas fa-chart-bar"></i> Statistics
                    </a>
                </li>
                <li class="logout">
                    <a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>

            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <i class="fas fa-school"></i> <?php echo $page_title; ?>
                </div>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                </div>
            </header>

            <div class="content-wrapper">
                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button class="close-alert" onclick="this.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- New API Key Display -->
                <?php if (isset($_SESSION['new_api_key'])): ?>
                    <div class="api-key-display">
                        <div class="key-info">
                            <span class="key-label"><i class="fas fa-key"></i> API Key for <?php echo htmlspecialchars($_SESSION['new_school_name']); ?>:</span>
                            <span class="key-value" id="newApiKey"><?php echo $_SESSION['new_api_key']; ?></span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-info" onclick="copyApiKey()">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="dismissApiKey()">
                                <i class="fas fa-times"></i> Dismiss
                            </button>
                        </div>
                    </div>
                <?php
                    // Clear after displaying
                    unset($_SESSION['new_api_key']);
                    unset($_SESSION['new_school_name']);
                endif;
                ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <?php
                    $total_schools = count($schools);
                    $active_schools = count(array_filter($schools, function ($s) {
                        return $s['subscription_status'] === 'active';
                    }));
                    $trial_schools = count(array_filter($schools, function ($s) {
                        return $s['subscription_status'] === 'trial';
                    }));
                    $total_downloads = array_sum(array_column($schools, 'total_downloads'));
                    ?>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-school"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $total_schools; ?></h3>
                            <p>Total Schools</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $active_schools; ?></h3>
                            <p>Active Schools</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $trial_schools; ?></h3>
                            <p>Trial Schools</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon info">
                            <i class="fas fa-download"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo number_format($total_downloads); ?></h3>
                            <p>Total Downloads</p>
                        </div>
                    </div>
                </div>

                <!-- Add/Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-<?php echo $edit_school ? 'edit' : 'plus-circle'; ?>"></i>
                            <?php echo $edit_school ? 'Edit School' : 'Add New School'; ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="form-container">
                            <form method="POST" action="" onsubmit="return validateForm()">
                                <input type="hidden" name="action" value="<?php echo $edit_school ? 'edit' : 'add'; ?>">
                                <?php if ($edit_school): ?>
                                    <input type="hidden" name="id" value="<?php echo $edit_school['id']; ?>">
                                <?php endif; ?>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="school_name">
                                            <i class="fas fa-school"></i> School Name *
                                        </label>
                                        <input type="text"
                                            id="school_name"
                                            name="school_name"
                                            value="<?php echo $edit_school ? htmlspecialchars($edit_school['school_name']) : ''; ?>"
                                            required
                                            placeholder="e.g., Sunshine College">
                                    </div>

                                    <div class="form-group">
                                        <label for="contact_email">
                                            <i class="fas fa-envelope"></i> Contact Email
                                        </label>
                                        <input type="email"
                                            id="contact_email"
                                            name="contact_email"
                                            value="<?php echo $edit_school ? htmlspecialchars($edit_school['contact_email']) : ''; ?>"
                                            placeholder="admin@school.com">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="contact_phone">
                                            <i class="fas fa-phone"></i> Contact Phone
                                        </label>
                                        <input type="text"
                                            id="contact_phone"
                                            name="contact_phone"
                                            value="<?php echo $edit_school ? htmlspecialchars($edit_school['contact_phone']) : ''; ?>"
                                            placeholder="+234 123 456 7890">
                                    </div>

                                    <div class="form-group">
                                        <label for="max_users">
                                            <i class="fas fa-users"></i> Max Users
                                        </label>
                                        <input type="number"
                                            id="max_users"
                                            name="max_users"
                                            value="<?php echo $edit_school ? $edit_school['max_users'] : '100'; ?>"
                                            min="1"
                                            max="10000">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="subscription_status">
                                            <i class="fas fa-tag"></i> Subscription Status
                                        </label>
                                        <select id="subscription_status" name="subscription_status">
                                            <option value="active" <?php echo ($edit_school && $edit_school['subscription_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="trial" <?php echo ($edit_school && $edit_school['subscription_status'] === 'trial') ? 'selected' : ''; ?>>Trial</option>
                                            <option value="inactive" <?php echo ($edit_school && $edit_school['subscription_status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="subscription_expiry">
                                            <i class="fas fa-calendar-alt"></i> Expiry Date
                                        </label>
                                        <input type="date"
                                            id="subscription_expiry"
                                            name="subscription_expiry"
                                            value="<?php echo $edit_school ? $edit_school['subscription_expiry'] : date('Y-m-d', strtotime('+30 days')); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="address">
                                        <i class="fas fa-map-marker-alt"></i> Address
                                    </label>
                                    <textarea id="address"
                                        name="address"
                                        rows="2"
                                        placeholder="School address"><?php echo $edit_school ? htmlspecialchars($edit_school['address']) : ''; ?></textarea>
                                </div>

                                <?php if ($edit_school): ?>
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox"
                                                id="regenerate_api"
                                                name="regenerate_api">
                                            <label for="regenerate_api">
                                                <i class="fas fa-sync-alt"></i> Regenerate API Key (warning: old key will stop working)
                                            </label>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <?php echo $edit_school ? 'Update School' : 'Add School'; ?>
                                    </button>

                                    <?php if ($edit_school): ?>
                                        <a href="manage_schools.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Schools List -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-list"></i> Registered Schools
                        </h2>
                        <span class="badge"><?php echo count($schools); ?> schools</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($schools)): ?>
                            <div style="text-align: center; padding: 40px; color: var(--secondary-color);">
                                <i class="fas fa-school" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                <p>No schools registered yet. Add your first school above.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>School</th>
                                            <th>Contact</th>
                                            <th>API Key</th>
                                            <th>Status</th>
                                            <th>Expiry</th>
                                            <th>Usage</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schools as $school):
                                            $expiry = strtotime($school['subscription_expiry']);
                                            $now = time();
                                            $days_left = floor(($expiry - $now) / (60 * 60 * 24));
                                            $expiry_class = $days_left < 7 ? 'expiry-warning' : 'expiry-ok';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="school-info">
                                                        <span class="school-name">
                                                            <?php echo htmlspecialchars($school['school_name']); ?>
                                                        </span>
                                                        <span class="school-code">
                                                            <i class="fas fa-code"></i> <?php echo $school['school_code']; ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="contact-info">
                                                        <?php if ($school['contact_email']): ?>
                                                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($school['contact_email']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($school['contact_phone']): ?>
                                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($school['contact_phone']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="api-key-preview" title="<?php echo $school['api_key']; ?>">
                                                        <?php echo substr($school['api_key'], 0, 16) . '...'; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $school['subscription_status']; ?>">
                                                        <i class="fas fa-<?php
                                                                            echo $school['subscription_status'] === 'active' ? 'check-circle' : ($school['subscription_status'] === 'trial' ? 'clock' : 'times-circle');
                                                                            ?>"></i>
                                                        <?php echo ucfirst($school['subscription_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="<?php echo $expiry_class; ?>">
                                                        <i class="fas fa-calendar-day"></i>
                                                        <?php echo date('M d, Y', strtotime($school['subscription_expiry'])); ?>
                                                        <?php if ($days_left >= 0): ?>
                                                            <br><small>(<?php echo $days_left; ?> days left)</small>
                                                        <?php else: ?>
                                                            <br><small class="expiry-warning">Expired</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                                        <span class="stats-badge">
                                                            <i class="fas fa-download"></i> <?php echo $school['total_downloads']; ?> total
                                                        </span>
                                                        <span class="stats-badge">
                                                            <i class="fas fa-clock"></i> <?php echo $school['api_calls_24h']; ?> today
                                                        </span>
                                                        <?php if ($school['last_download']): ?>
                                                            <small style="color: #666;">
                                                                Last: <?php echo date('M d, H:i', strtotime($school['last_download'])); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?php echo $school['id']; ?>"
                                                            class="action-btn edit"
                                                            title="Edit School">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?regenerate_key=<?php echo $school['id']; ?>"
                                                            class="action-btn key"
                                                            title="Regenerate API Key"
                                                            onclick="return confirm('Are you sure you want to regenerate the API key for <?php echo htmlspecialchars(addslashes($school['school_name'])); ?>?\n\nThe old key will stop working immediately.')">
                                                            <i class="fas fa-key"></i>
                                                        </a>
                                                        <a href="view_stats.php?school_id=<?php echo $school['id']; ?>"
                                                            class="action-btn stats"
                                                            title="View Statistics">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $school['id']; ?>"
                                                            class="action-btn delete"
                                                            title="Delete School"
                                                            onclick="return confirmDelete('<?php echo htmlspecialchars(addslashes($school['school_name'])); ?>', <?php echo $school['total_downloads']; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Guide Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-question-circle"></i> School Integration Guide
                        </h2>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                            <div>
                                <h4><i class="fas fa-key" style="color: var(--primary-color);"></i> 1. API Authentication</h4>
                                <p style="color: #666; margin: 10px 0;">Schools need to include their API key in requests:</p>
                                <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto;">
X-API-Key: your_api_key_here</pre>
                            </div>

                            <div>
                                <h4><i class="fas fa-download" style="color: var(--primary-color);"></i> 2. Pull Questions</h4>
                                <p style="color: #666; margin: 10px 0;">Example API call to get questions:</p>
                                <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto;">
GET /api/?action=get_questions&subject_id=1&limit=50</pre>
                            </div>

                            <div>
                                <h4><i class="fas fa-sync" style="color: var(--primary-color);"></i> 3. Sync Script</h4>
                                <p style="color: #666; margin: 10px 0;">Provide schools with the sync script:</p>
                                <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto;">
php school_sync.php --api-key=YOUR_KEY</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle sidebar on mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-hide alert after 5 seconds
        const alertMessage = document.getElementById('alertMessage');
        if (alertMessage) {
            setTimeout(function() {
                alertMessage.style.transition = 'opacity 0.5s';
                alertMessage.style.opacity = '0';
                setTimeout(function() {
                    alertMessage.remove();
                }, 500);
            }, 5000);
        }

        // Form validation
        function validateForm() {
            const name = document.getElementById('school_name');
            let isValid = true;

            // Reset errors
            name.classList.remove('error');

            // Validate name
            if (name.value.trim().length < 3) {
                showError(name, 'School name must be at least 3 characters');
                isValid = false;
            }

            if (!isValid) {
                return false;
            }

            // Show loading
            showLoading();
            return true;
        }

        function showError(element, message) {
            element.classList.add('error');

            // Create or update error message
            let error = element.parentElement.querySelector('.error-message');
            if (!error) {
                error = document.createElement('small');
                error.className = 'error-message';
                error.style.color = 'var(--danger-color)';
                error.style.marginTop = '5px';
                error.style.display = 'block';
                element.parentElement.appendChild(error);
            }
            error.textContent = message;

            // Remove error after 3 seconds
            setTimeout(function() {
                element.classList.remove('error');
                if (error) {
                    error.remove();
                }
            }, 3000);
        }

        // Confirm delete
        function confirmDelete(schoolName, downloadCount) {
            if (downloadCount > 0) {
                alert(`Cannot delete "${schoolName}" because it has ${downloadCount} downloads. Deactivate it instead.`);
                return false;
            }
            return confirm(`Are you sure you want to delete "${schoolName}"? This action cannot be undone.`);
        }

        // Copy API key to clipboard
        function copyApiKey() {
            const apiKey = document.getElementById('newApiKey');
            if (apiKey) {
                navigator.clipboard.writeText(apiKey.textContent).then(function() {
                    alert('API key copied to clipboard!');
                }, function() {
                    alert('Failed to copy API key');
                });
            }
        }

        // Dismiss API key display
        function dismissApiKey() {
            const apiDisplay = document.querySelector('.api-key-display');
            if (apiDisplay) {
                apiDisplay.remove();
            }
        }

        // Show loading spinner
        function showLoading() {
            const spinner = document.createElement('div');
            spinner.className = 'loading-spinner';
            spinner.id = 'loadingSpinner';
            spinner.innerHTML = '<div class="spinner"></div>';
            document.body.appendChild(spinner);
        }

        // Hide loading spinner
        window.onload = function() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) {
                spinner.remove();
            }
        };

        // Auto-select expiry date based on status
        document.getElementById('subscription_status')?.addEventListener('change', function() {
            const expiryInput = document.getElementById('subscription_expiry');
            const today = new Date();

            if (this.value === 'trial') {
                // Trial: 30 days from now
                const trialDate = new Date(today.setDate(today.getDate() + 30));
                expiryInput.value = trialDate.toISOString().split('T')[0];
            } else if (this.value === 'active') {
                // Active: 1 year from now
                const activeDate = new Date(today.setFullYear(today.getFullYear() + 1));
                expiryInput.value = activeDate.toISOString().split('T')[0];
            }
        });
    </script>
</body>

</html>