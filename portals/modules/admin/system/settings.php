<?php
// modules/admin/system/settings.php

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

// Get all settings
$sql = "SELECT * FROM system_settings ORDER BY setting_group, setting_key";
$result = $conn->query($sql);
$settings = $result->fetch_all(MYSQLI_ASSOC);

// Group settings by category
$settings_by_group = [];
foreach ($settings as $setting) {
    $group = $setting['setting_group'];
    if (!isset($settings_by_group[$group])) {
        $settings_by_group[$group] = [];
    }
    $settings_by_group[$group][] = $setting;
}

// Handle setting updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $updates = 0;
        $errors = [];
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_id = substr($key, 8);
                
                // Validate setting exists
                $check_sql = "SELECT * FROM system_settings WHERE id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $setting_id);
                $check_stmt->execute();
                $setting = $check_stmt->get_result()->fetch_assoc();
                
                if ($setting) {
                    // Validate based on data type
                    $valid = true;
                    $processed_value = trim($value);
                    
                    switch ($setting['data_type']) {
                        case 'integer':
                            if (!is_numeric($processed_value)) {
                                $errors[] = "Setting '{$setting['setting_key']}' must be a number.";
                                $valid = false;
                            }
                            $processed_value = intval($processed_value);
                            break;
                            
                        case 'boolean':
                            $processed_value = $processed_value ? '1' : '0';
                            break;
                            
                        case 'json':
                        case 'array':
                            // Try to decode JSON
                            json_decode($processed_value);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $errors[] = "Setting '{$setting['setting_key']}' must be valid JSON.";
                                $valid = false;
                            }
                            break;
                            
                        case 'string':
                        default:
                            // String validation - escape special chars
                            $processed_value = $conn->real_escape_string($processed_value);
                            break;
                    }
                    
                    if ($valid) {
                        $update_sql = "UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("si", $processed_value, $setting_id);
                        
                        if ($update_stmt->execute()) {
                            $updates++;
                            
                            // Log setting change
                            logActivity($_SESSION['user_id'], 'setting_update', 
                                "Updated setting: {$setting['setting_key']} = {$processed_value}", 
                                'system_settings', $setting_id);
                        } else {
                            $errors[] = "Failed to update setting '{$setting['setting_key']}'.";
                        }
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $_SESSION['error'] = implode('<br>', $errors);
        } elseif ($updates > 0) {
            $_SESSION['success'] = "Successfully updated $updates setting(s).";
        }
        
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Log activity
logActivity($_SESSION['user_id'], 'view_settings', "Viewed system settings");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--dark);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
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

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .settings-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .settings-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
        }

        .settings-tab {
            padding: 1rem 1.5rem;
            border: none;
            background: none;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }

        .settings-tab:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.05);
        }

        .settings-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: white;
        }

        .settings-content {
            padding: 2rem;
        }

        .settings-group {
            margin-bottom: 2.5rem;
        }

        .settings-group h3 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .settings-group h3 i {
            color: var(--primary);
        }

        .setting-item {
            display: grid;
            grid-template-columns: 300px 1fr 200px;
            gap: 1.5rem;
            padding: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            align-items: start;
        }

        .setting-item:hover {
            background: #f8fafc;
        }

        .setting-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .setting-key {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .setting-description {
            color: #64748b;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .setting-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .setting-control {
            display: flex;
            align-items: center;
        }

        .setting-control input[type="text"],
        .setting-control input[type="number"],
        .setting-control textarea,
        .setting-control select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .setting-control input:focus,
        .setting-control textarea:focus,
        .setting-control select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .setting-control textarea {
            min-height: 100px;
            resize: vertical;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .setting-control .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .setting-control .checkbox-container input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }

        .setting-meta {
            color: #64748b;
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .setting-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-public { background: #d1fae5; color: #065f46; }
        .status-private { background: #fee2e2; color: #991b1b; }

        .form-actions {
            padding: 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
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
            gap: 0.5rem;
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

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

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 10px;
            overflow: hidden;
        }

        .modal-header {
            padding: 1.5rem;
            background: var(--primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        @media (max-width: 1024px) {
            .setting-item {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            .settings-tabs {
                flex-wrap: wrap;
            }
            .settings-tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p style="color: #94a3b8; font-size: 0.9rem;">Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/applications/list.php">
                        <i class="fas fa-file-alt"></i> Applications</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php">
                        <i class="fas fa-users"></i> Users</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/">
                        <i class="fas fa-graduation-cap"></i> Academic</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/analytics.php">
                        <i class="fas fa-chart-line"></i> Analytics</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/announcements.php">
                        <i class="fas fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/logs.php">
                        <i class="fas fa-history"></i> Activity Logs</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php" class="active">
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/system/settings.php">System</a> &rsaquo;
                        Settings
                    </div>
                    <h1>System Settings</h1>
                </div>
                <div>
                    <button onclick="openAddSettingModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Setting
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Settings Form -->
            <form method="POST" id="settingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="settings-container">
                    <!-- Settings Tabs -->
                    <div class="settings-tabs" id="settingsTabs">
                        <?php foreach (array_keys($settings_by_group) as $index => $group): ?>
                            <button type="button" class="settings-tab <?php echo $index === 0 ? 'active' : ''; ?>" 
                                    onclick="showTab('<?php echo $group; ?>')">
                                <?php echo ucfirst(str_replace('_', ' ', $group)); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Settings Content -->
                    <div class="settings-content">
                        <?php foreach ($settings_by_group as $group => $group_settings): ?>
                            <div id="tab-<?php echo $group; ?>" class="settings-tab-content" 
                                 style="<?php echo array_key_first($settings_by_group) !== $group ? 'display: none;' : ''; ?>">
                                
                                <div class="settings-group">
                                    <h3>
                                        <i class="fas fa-folder"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $group)); ?> Settings
                                    </h3>
                                    
                                    <?php foreach ($group_settings as $setting): ?>
                                        <div class="setting-item">
                                            <div class="setting-info">
                                                <div class="setting-key"><?php echo htmlspecialchars($setting['setting_key']); ?></div>
                                                <?php if ($setting['description']): ?>
                                                    <div class="setting-description"><?php echo htmlspecialchars($setting['description']); ?></div>
                                                <?php endif; ?>
                                                <span class="setting-type"><?php echo $setting['data_type']; ?></span>
                                            </div>
                                            
                                            <div class="setting-control">
                                                <?php
                                                $value = htmlspecialchars($setting['setting_value'] ?? '');
                                                $name = "setting_{$setting['id']}";
                                                
                                                switch ($setting['data_type']):
                                                    case 'boolean': ?>
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" id="<?php echo $name; ?>" 
                                                                   name="<?php echo $name; ?>" 
                                                                   value="1" <?php echo $value == '1' ? 'checked' : ''; ?>>
                                                            <label for="<?php echo $name; ?>">Enabled</label>
                                                        </div>
                                                        <?php break;
                                                    
                                                    case 'integer': ?>
                                                        <input type="number" id="<?php echo $name; ?>" 
                                                               name="<?php echo $name; ?>" 
                                                               value="<?php echo $value; ?>" 
                                                               step="1">
                                                        <?php break;
                                                    
                                                    case 'json':
                                                    case 'array': ?>
                                                        <textarea id="<?php echo $name; ?>" 
                                                                  name="<?php echo $name; ?>" 
                                                                  rows="4"><?php echo $value; ?></textarea>
                                                        <?php break;
                                                    
                                                    default: ?>
                                                        <input type="text" id="<?php echo $name; ?>" 
                                                               name="<?php echo $name; ?>" 
                                                               value="<?php echo $value; ?>">
                                                <?php endswitch; ?>
                                            </div>
                                            
                                            <div class="setting-meta">
                                                <div>
                                                    <strong>Visibility:</strong>
                                                    <span class="setting-status <?php echo $setting['is_public'] ? 'status-public' : 'status-private'; ?>">
                                                        <?php echo $setting['is_public'] ? 'Public' : 'Private'; ?>
                                                    </span>
                                                </div>
                                                <?php if ($setting['updated_at']): ?>
                                                    <div>
                                                        <strong>Last Updated:</strong>
                                                        <?php echo date('M j, Y', strtotime($setting['updated_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="button" onclick="resetForm()" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save All Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Setting Modal -->
    <div id="addSettingModal" class="modal">
        <div class="modal-content">
            <form id="addSettingForm" method="POST" action="add_setting.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-header">
                    <h3>Add New Setting</h3>
                    <button type="button" class="modal-close" onclick="closeAddSettingModal()">&times;</button>
                </div>
                
                <div class="modal-body">
                    <div style="margin-bottom: 1rem;">
                        <label for="new_setting_key">Setting Key *</label>
                        <input type="text" id="new_setting_key" name="setting_key" required 
                               placeholder="e.g., site_name, registration_open" 
                               style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label for="new_setting_value">Setting Value *</label>
                        <input type="text" id="new_setting_value" name="setting_value" required 
                               placeholder="Enter value" 
                               style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="new_setting_group">Setting Group</label>
                            <select id="new_setting_group" name="setting_group" 
                                    style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <option value="general">General</option>
                                <option value="academic">Academic</option>
                                <option value="admissions">Admissions</option>
                                <option value="system">System</option>
                                <option value="email">Email</option>
                                <option value="payment">Payment</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="new_data_type">Data Type</label>
                            <select id="new_data_type" name="data_type" 
                                    style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <option value="string">String</option>
                                <option value="integer">Integer</option>
                                <option value="boolean">Boolean</option>
                                <option value="json">JSON</option>
                                <option value="array">Array</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="new_is_public">Visibility</label>
                            <select id="new_is_public" name="is_public" 
                                    style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <option value="0">Private</option>
                                <option value="1">Public</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label for="new_description">Description</label>
                        <textarea id="new_description" name="description" 
                                  placeholder="Description of this setting..." 
                                  style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; min-height: 80px;"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddSettingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Setting
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.settings-tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('tab-' + tabName).style.display = 'block';
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Form reset functionality
        function resetForm() {
            if (confirm('Are you sure you want to reset all changes? This cannot be undone.')) {
                document.getElementById('settingsForm').reset();
                
                // Restore original checkbox states (since reset doesn't work properly with checkboxes)
                document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    const originalValue = checkbox.getAttribute('data-original') === 'true';
                    checkbox.checked = originalValue;
                });
            }
        }
        
        // Store original checkbox values
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.setAttribute('data-original', checkbox.checked);
            });
        });
        
        // Add Setting Modal
        function openAddSettingModal() {
            document.getElementById('addSettingModal').style.display = 'flex';
        }
        
        function closeAddSettingModal() {
            document.getElementById('addSettingModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Auto-save functionality (optional)
        let autoSaveTimer;
        document.querySelectorAll('input, textarea, select').forEach(element => {
            element.addEventListener('change', function() {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => {
                    // Show saving indicator
                    const submitBtn = document.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    submitBtn.disabled = true;
                    
                    // Submit form
                    document.getElementById('settingsForm').submit();
                    
                    // Restore button after 3 seconds if still showing
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 3000);
                }, 1000); // Auto-save after 1 second
            });
        });
        
        // Validate JSON inputs
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('blur', function() {
                try {
                    JSON.parse(this.value);
                    this.style.borderColor = '#10b981';
                } catch (e) {
                    this.style.borderColor = '#ef4444';
                }
            });
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>