<?php
// admin/schools.php - School Management
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: login.php");
    exit();
}

require_once '../config/config.php';

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$success_message = '';
$error_message = '';

// Handle different actions
$action = $_GET['action'] ?? 'list';
$school_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle Add/Edit School
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $db = getDB();

        // Add School
        if ($_POST['action'] === 'add_school') {
            $school_code = strtoupper(trim($_POST['school_code']));
            $school_name = trim($_POST['school_name']);
            $school_address = trim($_POST['school_address'] ?? '');
            $school_email = trim($_POST['school_email'] ?? '');
            $school_phone = trim($_POST['school_phone'] ?? '');
            $principal_name = trim($_POST['principal_name'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $subscription_plan = $_POST['subscription_plan'] ?? 'basic';
            $subscription_expiry = $_POST['subscription_expiry'] ?? null;

            // Validate
            if (empty($school_code) || empty($school_name)) {
                $error_message = "School code and name are required";
            } else {
                try {
                    // Check if school code exists
                    $stmt = $db->prepare("SELECT id FROM schools WHERE school_code = ?");
                    $stmt->execute([$school_code]);
                    if ($stmt->fetch()) {
                        $error_message = "School code already exists";
                    } else {
                        // Generate API key
                        $api_key = generateApiKey();

                        $stmt = $db->prepare("
                            INSERT INTO schools (school_code, school_name, school_address, school_email, school_phone, 
                                                principal_name, website, subscription_plan, subscription_expiry, api_key, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                        ");
                        $stmt->execute([
                            $school_code,
                            $school_name,
                            $school_address,
                            $school_email,
                            $school_phone,
                            $principal_name,
                            $website,
                            $subscription_plan,
                            $subscription_expiry,
                            $api_key
                        ]);

                        $new_school_id = $db->lastInsertId();

                        // Add default classes for the school
                        $default_classes = [
                            'JSS 1',
                            'JSS 2',
                            'JSS 3',
                            'SSS 1',
                            'SSS 2',
                            'SSS 3'
                        ];

                        $class_stmt = $db->prepare("
                            INSERT INTO school_classes (school_id, class_name, class_category, sort_order, status)
                            VALUES (?, ?, ?, ?, 'active')
                        ");

                        $categories = [
                            'JSS 1' => 'Junior Secondary',
                            'JSS 2' => 'Junior Secondary',
                            'JSS 3' => 'Junior Secondary',
                            'SSS 1' => 'Senior Secondary',
                            'SSS 2' => 'Senior Secondary',
                            'SSS 3' => 'Senior Secondary'
                        ];

                        $order = 1;
                        foreach ($default_classes as $class) {
                            $class_stmt->execute([$new_school_id, $class, $categories[$class], $order]);
                            $order++;
                        }

                        $success_message = "School added successfully! API Key: " . $api_key;
                        logActivity($admin_id, 'admin', 'Added new school', "School: $school_name");
                    }
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }

        // Edit School
        elseif ($_POST['action'] === 'edit_school' && $school_id > 0) {
            $school_name = trim($_POST['school_name']);
            $school_address = trim($_POST['school_address'] ?? '');
            $school_email = trim($_POST['school_email'] ?? '');
            $school_phone = trim($_POST['school_phone'] ?? '');
            $principal_name = trim($_POST['principal_name'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $subscription_plan = $_POST['subscription_plan'] ?? 'basic';
            $subscription_expiry = $_POST['subscription_expiry'] ?? null;
            $status = $_POST['status'] ?? 'active';

            try {
                $stmt = $db->prepare("
                    UPDATE schools SET 
                        school_name = ?, school_address = ?, school_email = ?, school_phone = ?,
                        principal_name = ?, website = ?, subscription_plan = ?, subscription_expiry = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $school_name,
                    $school_address,
                    $school_email,
                    $school_phone,
                    $principal_name,
                    $website,
                    $subscription_plan,
                    $subscription_expiry,
                    $status,
                    $school_id
                ]);

                $success_message = "School updated successfully!";
                logActivity($admin_id, 'admin', 'Updated school', "School ID: $school_id");
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }

        // Add Class
        elseif ($_POST['action'] === 'add_class' && $school_id > 0) {
            $class_name = trim($_POST['class_name']);
            $class_category = $_POST['class_category'] ?? 'Other';
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if (empty($class_name)) {
                $error_message = "Class name is required";
            } else {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO school_classes (school_id, class_name, class_category, sort_order, status)
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$school_id, $class_name, $class_category, $sort_order]);
                    $success_message = "Class added successfully!";
                    logActivity($admin_id, 'admin', 'Added class', "Class: $class_name for school ID: $school_id");
                } catch (PDOException $e) {
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }

        // Delete Class
        elseif ($_POST['action'] === 'delete_class') {
            $class_id = (int)$_POST['class_id'];
            try {
                $stmt = $db->prepare("DELETE FROM school_classes WHERE id = ? AND school_id = ?");
                $stmt->execute([$class_id, $school_id]);
                $success_message = "Class deleted successfully!";
            } catch (PDOException $e) {
                $error_message = "Cannot delete class. It may have associated data.";
            }
        }

        // Toggle Class Status
        elseif ($_POST['action'] === 'toggle_class_status') {
            $class_id = (int)$_POST['class_id'];
            $new_status = $_POST['new_status'];
            try {
                $stmt = $db->prepare("UPDATE school_classes SET status = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$new_status, $class_id, $school_id]);
                $success_message = "Class status updated!";
            } catch (PDOException $e) {
                $error_message = "Error updating class status";
            }
        }

        // Regenerate API Key
        elseif ($_POST['action'] === 'regenerate_api' && $school_id > 0) {
            $new_api_key = generateApiKey();
            try {
                $stmt = $db->prepare("UPDATE schools SET api_key = ? WHERE id = ?");
                $stmt->execute([$new_api_key, $school_id]);
                $success_message = "API Key regenerated successfully! New Key: " . $new_api_key;
            } catch (PDOException $e) {
                $error_message = "Error regenerating API key";
            }
        }
    }
}

// Handle Delete School
if (isset($_GET['delete']) && $admin_role === 'super_admin') {
    $delete_id = (int)$_GET['delete'];
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM schools WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_message = "School deleted successfully!";
        logActivity($admin_id, 'admin', 'Deleted school', "School ID: $delete_id");
        header("Location: schools.php?message=" . urlencode($success_message));
        exit();
    } catch (PDOException $e) {
        $error_message = "Cannot delete school. It may have associated data.";
    }
}

// Get all schools
$db = getDB();
$schools = [];
$stmt = $db->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM students WHERE school_id = s.id) as student_count,
           (SELECT COUNT(*) FROM result_pins WHERE school_id = s.id) as pin_count
    FROM schools s 
    ORDER BY s.created_at DESC
");
$schools = $stmt->fetchAll();

// Get single school data for edit view
$school_data = null;
if ($school_id > 0 && ($action === 'edit' || $action === 'view')) {
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school_data = $stmt->fetch();

    // Get classes for this school
    $stmt = $db->prepare("
        SELECT * FROM school_classes 
        WHERE school_id = ? 
        ORDER BY sort_order ASC, class_name ASC
    ");
    $stmt->execute([$school_id]);
    $school_classes = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Manage Schools - MyResultChecker Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            overflow-x: hidden;
        }

        /* Mobile Menu Toggle */
        .mobile-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 48px;
            height: 48px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #2c3e50, #1a252f);
            color: white;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: #3498db;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.7rem;
            opacity: 0.7;
        }

        .admin-info {
            padding: 20px;
            margin: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            text-align: center;
        }

        .admin-info h4 {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .admin-info p {
            font-size: 0.7rem;
            opacity: 0.7;
            text-transform: capitalize;
        }

        .nav-links {
            list-style: none;
            padding: 10px 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-links i {
            width: 22px;
            font-size: 18px;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .page-title h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .page-title p {
            color: #7f8c8d;
            font-size: 0.85rem;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .alert-error {
            background: #fef2f2;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
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

        /* Schools Grid */
        .schools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .school-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .school-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .school-header {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 20px;
            position: relative;
        }

        .school-status {
            position: absolute;
            top: 15px;
            right: 15px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .status-active {
            background: #27ae60;
            color: white;
        }

        .status-inactive {
            background: #e74c3c;
            color: white;
        }

        .school-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            padding-right: 60px;
        }

        .school-code {
            font-size: 0.8rem;
            opacity: 0.8;
            font-family: monospace;
        }

        .school-body {
            padding: 20px;
        }

        .school-info {
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 0.85rem;
            color: #555;
            flex-wrap: wrap;
        }

        .info-row i {
            width: 20px;
            color: #3498db;
        }

        .stats-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-top: 1px solid #ecf0f1;
            border-bottom: 1px solid #ecf0f1;
            margin: 15px 0;
        }

        .stat-item {
            text-align: center;
            flex: 1;
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        .school-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.2rem;
            color: #2c3e50;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Classes Table */
        .classes-section {
            margin-top: 30px;
            background: white;
            border-radius: 16px;
            padding: 20px;
        }

        .classes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .classes-table {
            width: 100%;
            border-collapse: collapse;
        }

        .classes-table th,
        .classes-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        .classes-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        /* API Key Display */
        .api-key-box {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
            font-family: monospace;
            font-size: 0.85rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .mobile-toggle {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar-overlay.active {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 75px 15px 20px;
            }

            .schools-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }

            .stats-row {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .school-actions {
                flex-direction: column;
            }

            .school-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .api-key-box {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Touch optimizations */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .nav-links a {
                min-height: 44px;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-toggle" id="mobileToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3>MyResultChecker</h3>
                    <p>Admin Portal</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator'); ?></h4>
            <p><?php echo ucfirst($_SESSION['admin_role'] ?? 'admin'); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="schools.php" class="active"><i class="fas fa-school"></i> Schools</a></li>
            <li><a href="students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="results.php"><i class="fas fa-file-alt"></i> Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-school"></i> Manage Schools</h1>
                <p>Add, edit, and manage schools using the portal</p>
            </div>
            <button class="btn btn-primary" onclick="openAddSchoolModal()">
                <i class="fas fa-plus-circle"></i> Add New School
            </button>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($action === 'edit' && $school_data): ?>
            <!-- Edit School View -->
            <div class="school-card" style="margin-bottom: 25px;">
                <div class="school-header">
                    <div class="school-status">
                        <span class="status-badge status-<?php echo $school_data['status']; ?>">
                            <?php echo ucfirst($school_data['status']); ?>
                        </span>
                    </div>
                    <div class="school-name"><?php echo htmlspecialchars($school_data['school_name']); ?></div>
                    <div class="school-code">Code: <?php echo htmlspecialchars($school_data['school_code']); ?></div>
                </div>
                <div class="school-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="edit_school">

                        <div class="form-row">
                            <div class="form-group">
                                <label>School Name *</label>
                                <input type="text" name="school_name" value="<?php echo htmlspecialchars($school_data['school_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="active" <?php echo $school_data['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $school_data['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $school_data['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="school_address" rows="2"><?php echo htmlspecialchars($school_data['school_address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="school_email" value="<?php echo htmlspecialchars($school_data['school_email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="school_phone" value="<?php echo htmlspecialchars($school_data['school_phone'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Principal Name</label>
                                <input type="text" name="principal_name" value="<?php echo htmlspecialchars($school_data['principal_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Website</label>
                                <input type="url" name="website" value="<?php echo htmlspecialchars($school_data['website'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Subscription Plan</label>
                                <select name="subscription_plan">
                                    <option value="basic" <?php echo ($school_data['subscription_plan'] ?? 'basic') === 'basic' ? 'selected' : ''; ?>>Basic</option>
                                    <option value="premium" <?php echo ($school_data['subscription_plan'] ?? '') === 'premium' ? 'selected' : ''; ?>>Premium</option>
                                    <option value="enterprise" <?php echo ($school_data['subscription_plan'] ?? '') === 'enterprise' ? 'selected' : ''; ?>>Enterprise</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Subscription Expiry</label>
                                <input type="date" name="subscription_expiry" value="<?php echo htmlspecialchars($school_data['subscription_expiry'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="api-key-box">
                            <span><i class="fas fa-key"></i> API Key: <strong><?php echo htmlspecialchars($school_data['api_key']); ?></strong></span>
                            <button type="submit" name="action" value="regenerate_api" class="btn btn-warning btn-sm" onclick="return confirm('Regenerating API key will break existing connections. Continue?')">
                                <i class="fas fa-sync-alt"></i> Regenerate
                            </button>
                        </div>

                        <div class="modal-footer" style="padding: 20px 0 0 0;">
                            <a href="schools.php" class="btn">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Classes Management Section -->
            <div class="classes-section">
                <div class="classes-header">
                    <h3><i class="fas fa-chalkboard"></i> School Classes</h3>
                    <button class="btn btn-primary btn-sm" onclick="openAddClassModal()">
                        <i class="fas fa-plus"></i> Add Class
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="classes-table">
                        <thead>
                            <tr>
                                <th>Class Name</th>
                                <th>Category</th>
                                <th>Sort Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($school_classes)): ?>
                                <?php foreach ($school_classes as $class): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($class['class_category']); ?></td>
                                        <td><?php echo $class['sort_order']; ?></td>
                                        <td>
                                            <button class="btn btn-sm <?php echo $class['status'] === 'active' ? 'btn-success' : 'btn-danger'; ?>"
                                                onclick="toggleClassStatus(<?php echo $class['id']; ?>, '<?php echo $class['status'] === 'active' ? 'inactive' : 'active'; ?>')">
                                                <?php echo ucfirst($class['status']); ?>
                                            </button>
                                        </td>
                                        <td>
                                            <button class="btn btn-danger btn-sm" onclick="deleteClass(<?php echo $class['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                etxek
                                <td colspan="5" style="text-align: center; padding: 30px; color: #999;">
                                    <i class="fas fa-chalkboard"></i> No classes added yet
                                </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Class Modal -->
            <div id="addClassModal" class="modal">
                <div class="modal-content">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_class">
                        <div class="modal-header">
                            <h3>Add New Class</h3>
                            <button type="button" class="close-modal" onclick="closeAddClassModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Class Name *</label>
                                <input type="text" name="class_name" placeholder="e.g., JSS 1A, Grade 1, Primary 1" required>
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="class_category">
                                    <option value="Primary">Primary</option>
                                    <option value="Junior Secondary">Junior Secondary</option>
                                    <option value="Senior Secondary">Senior Secondary</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sort Order</label>
                                <input type="number" name="sort_order" value="0" placeholder="Lower numbers appear first">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn" onclick="closeAddClassModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add Class</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Schools List View -->
            <div class="schools-grid">
                <?php foreach ($schools as $school): ?>
                    <div class="school-card">
                        <div class="school-header">
                            <div class="school-status">
                                <span class="status-badge status-<?php echo $school['status']; ?>">
                                    <?php echo ucfirst($school['status']); ?>
                                </span>
                            </div>
                            <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
                            <div class="school-code">Code: <?php echo htmlspecialchars($school['school_code']); ?></div>
                        </div>
                        <div class="school-body">
                            <div class="school-info">
                                <?php if ($school['principal_name']): ?>
                                    <div class="info-row">
                                        <i class="fas fa-user-tie"></i>
                                        <span><?php echo htmlspecialchars($school['principal_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school['school_email']): ?>
                                    <div class="info-row">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo htmlspecialchars($school['school_email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school['school_phone']): ?>
                                    <div class="info-row">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($school['school_phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="stats-row">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo number_format($school['student_count'] ?? 0); ?></div>
                                    <div class="stat-label">Students</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo number_format($school['pin_count'] ?? 0); ?></div>
                                    <div class="stat-label">PINs</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo ucfirst($school['subscription_plan'] ?? 'basic'); ?></div>
                                    <div class="stat-label">Plan</div>
                                </div>
                            </div>

                            <div class="school-actions">
                                <a href="schools.php?action=edit&id=<?php echo $school['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if ($admin_role === 'super_admin'): ?>
                                    <a href="schools.php?delete=<?php echo $school['id']; ?>" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Are you sure you want to delete this school? This will delete all associated data.')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($schools)): ?>
                    <div class="school-card" style="text-align: center; padding: 40px;">
                        <i class="fas fa-school" style="font-size: 48px; color: #ccc; margin-bottom: 15px; display: block;"></i>
                        <p style="color: #999;">No schools added yet</p>
                        <button class="btn btn-primary" style="margin-top: 15px;" onclick="openAddSchoolModal()">
                            <i class="fas fa-plus-circle"></i> Add Your First School
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add School Modal -->
    <div id="addSchoolModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_school">
                <div class="modal-header">
                    <h3><i class="fas fa-plus-circle"></i> Add New School</h3>
                    <button type="button" class="close-modal" onclick="closeAddSchoolModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>School Code *</label>
                            <input type="text" name="school_code" placeholder="e.g., TCBA001" required>
                            <small style="color: #7f8c8d;">Unique identifier for the school</small>
                        </div>
                        <div class="form-group">
                            <label>School Name *</label>
                            <input type="text" name="school_name" placeholder="e.g., The Climax Brains Academy" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="school_address" rows="2" placeholder="School address"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="school_email" placeholder="school@example.com">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="school_phone" placeholder="Phone number">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Principal Name</label>
                            <input type="text" name="principal_name" placeholder="Principal's full name">
                        </div>
                        <div class="form-group">
                            <label>Website</label>
                            <input type="url" name="website" placeholder="https://example.com">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Subscription Plan</label>
                            <select name="subscription_plan">
                                <option value="basic">Basic</option>
                                <option value="premium">Premium</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subscription Expiry</label>
                            <input type="date" name="subscription_expiry">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeAddSchoolModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add School</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu
        const mobileToggle = document.getElementById('mobileToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });

        // Modal functions
        function openAddSchoolModal() {
            document.getElementById('addSchoolModal').classList.add('active');
        }

        function closeAddSchoolModal() {
            document.getElementById('addSchoolModal').classList.remove('active');
        }

        function openAddClassModal() {
            document.getElementById('addClassModal').classList.add('active');
        }

        function closeAddClassModal() {
            document.getElementById('addClassModal').classList.remove('active');
        }

        function toggleClassStatus(classId, newStatus) {
            if (confirm('Change class status?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_class_status">
                    <input type="hidden" name="class_id" value="${classId}">
                    <input type="hidden" name="new_status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteClass(classId) {
            if (confirm('Are you sure you want to delete this class?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" value="${classId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>

</html>