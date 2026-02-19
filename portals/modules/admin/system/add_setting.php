<?php
// modules/admin/system/add_setting.php

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $setting_key = trim($_POST['setting_key']);
        $setting_value = trim($_POST['setting_value']);
        $setting_group = $_POST['setting_group'] ?? 'general';
        $data_type = $_POST['data_type'] ?? 'string';
        $is_public = intval($_POST['is_public'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        // Validate required fields
        if (empty($setting_key) || empty($setting_value)) {
            $_SESSION['error'] = 'Setting key and value are required.';
        } else {
            // Check if setting key already exists
            $check_sql = "SELECT id FROM system_settings WHERE setting_key = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $setting_key);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $_SESSION['error'] = 'Setting key already exists. Please use a different key.';
            } else {
                // Insert new setting
                $sql = "INSERT INTO system_settings (setting_key, setting_value, setting_group, data_type, is_public, description) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssis", $setting_key, $setting_value, $setting_group, $data_type, $is_public, $description);
                
                if ($stmt->execute()) {
                    $setting_id = $stmt->insert_id;
                    $_SESSION['success'] = 'Setting added successfully.';
                    
                    // Log activity
                    logActivity($_SESSION['user_id'], 'setting_create', 
                        "Added new setting: $setting_key", 'system_settings', $setting_id);
                } else {
                    $_SESSION['error'] = 'Failed to add setting. Please try again.';
                }
            }
        }
    }
}

// Redirect back to settings page
header('Location: ' . BASE_URL . 'modules/admin/system/settings.php');
exit();
?>