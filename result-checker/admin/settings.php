<?php
// admin/settings.php - System Settings
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

$db = getDB();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {

        // Update Profile
        if ($_POST['action'] === 'update_profile') {
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);

            if (empty($full_name)) {
                $error_message = "Full name is required";
            } else {
                try {
                    $stmt = $db->prepare("UPDATE portal_admins SET full_name = ?, email = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email, $admin_id]);

                    $_SESSION['admin_name'] = $full_name;
                    $success_message = "Profile updated successfully!";
                    logActivity($admin_id, 'admin', 'Updated profile', "Admin ID: $admin_id");
                } catch (PDOException $e) {
                    $error_message = "Error updating profile";
                }
            }
        }

        // Change Password
        elseif ($_POST['action'] === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = "All password fields are required";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match";
            } elseif (strlen($new_password) < 6) {
                $error_message = "Password must be at least 6 characters";
            } else {
                try {
                    // Verify current password
                    $stmt = $db->prepare("SELECT password FROM portal_admins WHERE id = ?");
                    $stmt->execute([$admin_id]);
                    $admin = $stmt->fetch();

                    if (password_verify($current_password, $admin['password'])) {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE portal_admins SET password = ? WHERE id = ?");
                        $stmt->execute([$hashed_password, $admin_id]);
                        $success_message = "Password changed successfully!";
                        logActivity($admin_id, 'admin', 'Changed password', "Admin ID: $admin_id");
                    } else {
                        $error_message = "Current password is incorrect";
                    }
                } catch (PDOException $e) {
                    $error_message = "Error changing password";
                }
            }
        }

        // Update System Settings (Super Admin only)
        elseif ($_POST['action'] === 'update_system_settings' && $admin_role === 'super_admin') {
            $site_name = trim($_POST['site_name']);
            $site_url = trim($_POST['site_url']);
            $contact_email = trim($_POST['contact_email']);
            $contact_phone = trim($_POST['contact_phone']);
            $default_pin_price = (float)$_POST['default_pin_price'];
            $default_max_uses = (int)$_POST['default_max_uses'];
            $pin_expiry_days = (int)$_POST['pin_expiry_days'];
            $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;

            try {
                // Update settings in database
                $settings = [
                    'site_name' => $site_name,
                    'site_url' => $site_url,
                    'contact_email' => $contact_email,
                    'contact_phone' => $contact_phone,
                    'default_pin_price' => $default_pin_price,
                    'default_max_uses' => $default_max_uses,
                    'pin_expiry_days' => $pin_expiry_days,
                    'maintenance_mode' => $maintenance_mode
                ];

                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $admin_id, $value, $admin_id]);
                }

                $success_message = "System settings updated successfully!";
                logActivity($admin_id, 'admin', 'Updated system settings', "Settings updated");
            } catch (PDOException $e) {
                $error_message = "Error updating system settings";
            }
        }

        // Update API Settings (Super Admin only)
        elseif ($_POST['action'] === 'update_api_settings' && $admin_role === 'super_admin') {
            $api_rate_limit = (int)$_POST['api_rate_limit'];
            $api_rate_window = (int)$_POST['api_rate_window'];
            $enable_api_logging = isset($_POST['enable_api_logging']) ? 1 : 0;

            try {
                $settings = [
                    'api_rate_limit' => $api_rate_limit,
                    'api_rate_window' => $api_rate_window,
                    'enable_api_logging' => $enable_api_logging
                ];

                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $admin_id, $value, $admin_id]);
                }

                $success_message = "API settings updated successfully!";
                logActivity($admin_id, 'admin', 'Updated API settings', "API settings updated");
            } catch (PDOException $e) {
                $error_message = "Error updating API settings";
            }
        }

        // Update Email Settings (Super Admin only)
        elseif ($_POST['action'] === 'update_email_settings' && $admin_role === 'super_admin') {
            $smtp_host = trim($_POST['smtp_host']);
            $smtp_port = (int)$_POST['smtp_port'];
            $smtp_user = trim($_POST['smtp_user']);
            $smtp_pass = trim($_POST['smtp_pass']);
            $smtp_encryption = $_POST['smtp_encryption'];

            try {
                $settings = [
                    'smtp_host' => $smtp_host,
                    'smtp_port' => $smtp_port,
                    'smtp_user' => $smtp_user,
                    'smtp_pass' => $smtp_pass,
                    'smtp_encryption' => $smtp_encryption
                ];

                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $admin_id, $value, $admin_id]);
                }

                $success_message = "Email settings updated successfully!";
                logActivity($admin_id, 'admin', 'Updated email settings', "Email settings updated");
            } catch (PDOException $e) {
                $error_message = "Error updating email settings";
            }
        }
    }
}

// Get current admin data
$stmt = $db->prepare("SELECT * FROM portal_admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_data = $stmt->fetch();

// Get system settings
$stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
$system_settings = [];
while ($row = $stmt->fetch()) {
    $system_settings[$row['setting_key']] = $row['setting_value'];
}

// Default settings if not set
$default_settings = [
    'site_name' => 'MyResultChecker',
    'site_url' => 'https://myresultchecker.com',
    'contact_email' => 'info@myresultchecker.com',
    'contact_phone' => '+234 800 000 0000',
    'default_pin_price' => 500,
    'default_max_uses' => 3,
    'pin_expiry_days' => 365,
    'maintenance_mode' => 0,
    'api_rate_limit' => 100,
    'api_rate_window' => 60,
    'enable_api_logging' => 1,
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_encryption' => 'tls'
];

foreach ($default_settings as $key => $value) {
    if (!isset($system_settings[$key])) {
        $system_settings[$key] = $value;
    }
}

// Get system stats
$stmt = $db->query("SELECT COUNT(*) as total FROM portal_admins");
$total_admins = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM schools WHERE status = 'active'");
$total_active_schools = $stmt->fetch()['total'];

// Get recent admin activities
$stmt = $db->prepare("
    SELECT * FROM portal_activity_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$admin_id]);
$recent_activities = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Settings - MyResultChecker Admin</title>

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
        }

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

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

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
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #3498db;
            color: #3498db;
        }

        .settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
            background: white;
            border-radius: 16px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
        }

        .tab-btn:hover {
            background: #ecf0f1;
        }

        .tab-btn.active {
            background: #3498db;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .settings-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
        }

        .card-header h2 {
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 20px;
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
            gap: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-group input {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

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

        .info-box {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 12px;
            margin-top: 15px;
            font-size: 0.8rem;
            color: #2c3e50;
        }

        .info-box i {
            color: #3498db;
            margin-right: 8px;
        }

        .activity-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #e8f4fd;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3498db;
        }

        .activity-details {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

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

        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #ecf0f1;
        }

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

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .settings-tabs {
                flex-direction: column;
            }

            .tab-btn {
                text-align: center;
            }

            .top-bar {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .settings-card {
                padding: 20px;
            }
        }

        .danger-zone {
            background: #fef2f2;
            border: 1px solid #f8d7da;
        }

        .danger-zone .card-header {
            border-bottom-color: #f8d7da;
        }

        .danger-zone .card-header h2 {
            color: #e74c3c;
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
            <li><a href="schools.php"><i class="fas fa-school"></i> Schools</a></li>
            <li><a href="students.php"><i class="fas fa-users"></i> Students</a></li>
            <li><a href="pins.php"><i class="fas fa-key"></i> PIN Management</a></li>
            <li><a href="batches.php"><i class="fas fa-layer-group"></i> PIN Batches</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-cog"></i> System Settings</h1>
                <p>Configure system preferences, profile settings, and security options</p>
            </div>
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

        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <button class="tab-btn active" onclick="showTab('profile')">
                <i class="fas fa-user"></i> Profile
            </button>
            <button class="tab-btn" onclick="showTab('password')">
                <i class="fas fa-lock"></i> Password
            </button>
            <?php if ($admin_role === 'super_admin'): ?>
                <button class="tab-btn" onclick="showTab('system')">
                    <i class="fas fa-globe"></i> System
                </button>
                <button class="tab-btn" onclick="showTab('api')">
                    <i class="fas fa-code"></i> API Settings
                </button>
                <button class="tab-btn" onclick="showTab('email')">
                    <i class="fas fa-envelope"></i> Email
                </button>
            <?php endif; ?>
            <button class="tab-btn" onclick="showTab('activity')">
                <i class="fas fa-history"></i> Activity Log
            </button>
        </div>

        <!-- Profile Tab -->
        <div id="profileTab" class="tab-content active">
            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fas fa-user-circle"></i> Profile Information</h2>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" value="<?php echo htmlspecialchars($admin_data['username']); ?>" disabled>
                            <small style="color: #7f8c8d;">Username cannot be changed</small>
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($admin_data['full_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($admin_data['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" value="<?php echo ucfirst($admin_data['role']); ?>" disabled>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Account Status</label>
                            <input type="text" value="<?php echo ucfirst($admin_data['status']); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label>Member Since</label>
                            <input type="text" value="<?php echo date('M d, Y', strtotime($admin_data['created_at'])); ?>" disabled>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
            </div>
        </div>

        <!-- Password Tab -->
        <div id="passwordTab" class="tab-content">
            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fas fa-key"></i> Change Password</h2>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required>
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-shield-alt"></i>
                        Password must be at least 6 characters long. Use a combination of letters, numbers, and symbols for better security.
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Change Password
                    </button>
                </form>
            </div>
        </div>

        <?php if ($admin_role === 'super_admin'): ?>

            <!-- System Settings Tab -->
            <div id="systemTab" class="tab-content">
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-globe"></i> General System Settings</h2>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_system_settings">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Site Name</label>
                                <input type="text" name="site_name" value="<?php echo htmlspecialchars($system_settings['site_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Site URL</label>
                                <input type="url" name="site_url" value="<?php echo htmlspecialchars($system_settings['site_url']); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Contact Email</label>
                                <input type="email" name="contact_email" value="<?php echo htmlspecialchars($system_settings['contact_email']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Contact Phone</label>
                                <input type="text" name="contact_phone" value="<?php echo htmlspecialchars($system_settings['contact_phone']); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Default PIN Price (₦)</label>
                                <input type="number" name="default_pin_price" step="0.01" value="<?php echo $system_settings['default_pin_price']; ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Default Max Uses per PIN</label>
                                <select name="default_max_uses">
                                    <option value="1" <?php echo $system_settings['default_max_uses'] == 1 ? 'selected' : ''; ?>>1 time</option>
                                    <option value="2" <?php echo $system_settings['default_max_uses'] == 2 ? 'selected' : ''; ?>>2 times</option>
                                    <option value="3" <?php echo $system_settings['default_max_uses'] == 3 ? 'selected' : ''; ?>>3 times</option>
                                    <option value="5" <?php echo $system_settings['default_max_uses'] == 5 ? 'selected' : ''; ?>>5 times</option>
                                    <option value="10" <?php echo $system_settings['default_max_uses'] == 10 ? 'selected' : ''; ?>>10 times</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>PIN Expiry Days (0 = never)</label>
                                <input type="number" name="pin_expiry_days" value="<?php echo $system_settings['pin_expiry_days']; ?>">
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group" style="margin-top: 32px;">
                                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode" value="1" <?php echo $system_settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                    <label for="maintenance_mode">Enable Maintenance Mode</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save System Settings
                        </button>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="settings-card danger-zone">
                    <div class="card-header">
                        <h2><i class="fas fa-exclamation-triangle"></i> Danger Zone</h2>
                    </div>
                    <div class="info-box" style="background: #fef2f2; color: #e74c3c;">
                        <i class="fas fa-warning"></i>
                        These actions are irreversible. Please proceed with caution.
                    </div>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                        <button class="btn btn-danger" onclick="confirmClearLogs()">
                            <i class="fas fa-trash-alt"></i> Clear Activity Logs
                        </button>
                        <button class="btn btn-danger" onclick="confirmClearCache()">
                            <i class="fas fa-database"></i> Clear System Cache
                        </button>
                    </div>
                </div>
            </div>

            <!-- API Settings Tab -->
            <div id="apiTab" class="tab-content">
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-code"></i> API Configuration</h2>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_api_settings">

                        <div class="form-row">
                            <div class="form-group">
                                <label>API Rate Limit (requests per window)</label>
                                <input type="number" name="api_rate_limit" value="<?php echo $system_settings['api_rate_limit']; ?>" required>
                                <small>Maximum number of API requests per IP within the time window</small>
                            </div>
                            <div class="form-group">
                                <label>Rate Limit Window (seconds)</label>
                                <input type="number" name="api_rate_window" value="<?php echo $system_settings['api_rate_window']; ?>" required>
                            </div>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" name="enable_api_logging" id="enable_api_logging" value="1" <?php echo $system_settings['enable_api_logging'] ? 'checked' : ''; ?>>
                            <label for="enable_api_logging">Enable API Request Logging</label>
                        </div>

                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            API endpoints are used by schools to sync student data and results. Rate limiting helps prevent abuse.
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save API Settings
                        </button>
                    </form>
                </div>

                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-key"></i> API Keys</h2>
                    </div>
                    <p>API keys are generated per school. Manage them from the <a href="schools.php" style="color: #3498db;">Schools Management</a> page.</p>
                </div>
            </div>

            <!-- Email Settings Tab -->
            <div id="emailTab" class="tab-content">
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-envelope"></i> SMTP Configuration</h2>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_email_settings">

                        <div class="form-row">
                            <div class="form-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($system_settings['smtp_host']); ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="form-group">
                                <label>SMTP Port</label>
                                <input type="number" name="smtp_port" value="<?php echo $system_settings['smtp_port']; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>SMTP Username</label>
                                <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($system_settings['smtp_user']); ?>">
                            </div>
                            <div class="form-group">
                                <label>SMTP Password</label>
                                <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars($system_settings['smtp_pass']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Encryption</label>
                            <select name="smtp_encryption">
                                <option value="none" <?php echo $system_settings['smtp_encryption'] === 'none' ? 'selected' : ''; ?>>None</option>
                                <option value="tls" <?php echo $system_settings['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo $system_settings['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            </select>
                        </div>

                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            Configure SMTP settings to enable email notifications. Leave empty to disable email features.
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Email Settings
                        </button>
                    </form>
                </div>
            </div>

        <?php endif; ?>

        <!-- Activity Log Tab -->
        <div id="activityTab" class="tab-content">
            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> Recent Activity</h2>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-user-cog"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['activity']); ?></div>
                                    <div class="activity-time">
                                        <?php echo date('M d, Y H:i:s', strtotime($activity['created_at'])); ?>
                                        <?php if ($activity['details']): ?>
                                            • <span style="color: #7f8c8d;"><?php echo htmlspecialchars($activity['details']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #95a5a6; padding: 40px;">No activity records found</p>
                    <?php endif; ?>
                </div>
            </div>
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

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');

            // Add active class to clicked button
            event.target.closest('.tab-btn').classList.add('active');

            // Save to localStorage
            localStorage.setItem('activeSettingsTab', tabName);
        }

        // Load last active tab
        const lastTab = localStorage.getItem('activeSettingsTab');
        if (lastTab && document.getElementById(lastTab + 'Tab')) {
            showTab(lastTab);
        }

        // Danger zone confirmations
        function confirmClearLogs() {
            if (confirm('WARNING: This will permanently delete all activity logs. This action cannot be undone. Continue?')) {
                window.location.href = '?clear_logs=1';
            }
        }

        function confirmClearCache() {
            if (confirm('Clear system cache? This may temporarily slow down the system while it rebuilds.')) {
                window.location.href = '?clear_cache=1';
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