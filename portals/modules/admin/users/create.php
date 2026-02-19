<?php
// modules/admin/users/create.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Check if editing existing user
$is_edit = isset($_GET['edit']);
$user_id = $is_edit ? (int)$_GET['edit'] : 0;
$user = null;
$profile = null;

if ($is_edit && $user_id) {
    // Get user details
    $sql = "SELECT u.*, up.*, s.name as school_name 
            FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            LEFT JOIN schools s ON u.school_id = s.id 
            WHERE u.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        $_SESSION['error'] = 'User not found';
        header('Location: manage.php');
        exit();
    }
}

// Get programs for student selection
$programs = [];
$programs_sql = "SELECT id, program_code, name FROM programs WHERE status = 'active' ORDER BY name";
$programs_result = $conn->query($programs_sql);
if ($programs_result) {
    $programs = $programs_result->fetch_all(MYSQLI_ASSOC);
}

// Get schools for selection
$schools = [];
$schools_sql = "SELECT id, name, short_name, city, state FROM schools WHERE partnership_status = 'active' ORDER BY name";
$schools_result = $conn->query($schools_sql);
if ($schools_result) {
    $schools = $schools_result->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Collect form data
        $form_data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'role' => $_POST['role'] ?? 'student',
            'status' => $_POST['status'] ?? 'pending',
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
            'gender' => $_POST['gender'] ?? null,
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'country' => trim($_POST['country'] ?? 'Nigeria'),
            'qualifications' => trim($_POST['qualifications'] ?? ''),
            'experience_years' => !empty($_POST['experience_years']) ? (int)$_POST['experience_years'] : 0,
            'current_job_title' => trim($_POST['current_job_title'] ?? ''),
            'current_company' => trim($_POST['current_company'] ?? ''),
            'bio' => trim($_POST['bio'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'linkedin_url' => trim($_POST['linkedin_url'] ?? ''),
            'github_url' => trim($_POST['github_url'] ?? ''),
            'send_welcome_email' => isset($_POST['send_welcome_email']),
            'school_id' => !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null
        ];

        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'email', 'role', 'status'];
        if (!$is_edit) {
            $required_fields[] = 'password';
            $required_fields[] = 'confirm_password';
        }
        
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate email
        if (!empty($form_data['email']) && !isValidEmail($form_data['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Check if email already exists (for new users or if email changed)
        if (!empty($form_data['email'])) {
            $check_sql = "SELECT id FROM users WHERE email = ?";
            if ($is_edit) {
                $check_sql .= " AND id != ?";
            }
            
            $check_stmt = $conn->prepare($check_sql);
            if ($is_edit) {
                $check_stmt->bind_param("si", $form_data['email'], $user_id);
            } else {
                $check_stmt->bind_param("s", $form_data['email']);
            }
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = 'Email already exists in the system.';
            }
        }

        // Validate password for new users
        if (!$is_edit && !empty($form_data['password'])) {
            if (strlen($form_data['password']) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if ($form_data['password'] !== $form_data['confirm_password']) {
                $errors[] = 'Passwords do not match.';
            }
        }

        // Validate password for existing users (if changing)
        if ($is_edit && !empty($form_data['password'])) {
            if (strlen($form_data['password']) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if ($form_data['password'] !== $form_data['confirm_password']) {
                $errors[] = 'Passwords do not match.';
            }
        }

        // Validate school selection for student role
        if ($form_data['role'] === 'student' && empty($form_data['school_id']) && !empty($_POST['school_selection'])) {
            if ($_POST['school_selection'] === 'select_existing' && empty($form_data['school_id'])) {
                $errors[] = 'Please select a school for the student.';
            } elseif ($_POST['school_selection'] === 'create_new' && (empty($_POST['new_school_name']) || empty($_POST['new_school_address']))) {
                $errors[] = 'Please provide school name and address when creating a new school.';
            }
        }

        // If no errors, process the form
        if (empty($errors)) {
            if ($is_edit) {
                // Update existing user
                $conn->begin_transaction();
                
                try {
                    // Update users table
                    if (!empty($form_data['password'])) {
                        $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
                        $user_sql = "UPDATE users SET 
                                    first_name = ?, last_name = ?, email = ?, phone = ?, 
                                    role = ?, status = ?, password = ?, school_id = ?
                                    WHERE id = ?";
                        $user_stmt = $conn->prepare($user_sql);
                        $user_stmt->bind_param("ssssssssi", 
                            $form_data['first_name'], $form_data['last_name'], 
                            $form_data['email'], $form_data['phone'],
                            $form_data['role'], $form_data['status'],
                            $hashed_password, $form_data['school_id'],
                            $user_id
                        );
                    } else {
                        $user_sql = "UPDATE users SET 
                                    first_name = ?, last_name = ?, email = ?, phone = ?, 
                                    role = ?, status = ?, school_id = ?
                                    WHERE id = ?";
                        $user_stmt = $conn->prepare($user_sql);
                        $user_stmt->bind_param("sssssssi", 
                            $form_data['first_name'], $form_data['last_name'], 
                            $form_data['email'], $form_data['phone'],
                            $form_data['role'], $form_data['status'],
                            $form_data['school_id'],
                            $user_id
                        );
                    }
                    
                    $user_stmt->execute();
                    
                    // Update or insert user profile
                    $profile_check = "SELECT id FROM user_profiles WHERE user_id = ?";
                    $profile_check_stmt = $conn->prepare($profile_check);
                    $profile_check_stmt->bind_param("i", $user_id);
                    $profile_check_stmt->execute();
                    $profile_exists = $profile_check_stmt->get_result()->num_rows > 0;
                    
                    if ($profile_exists) {
                        $profile_sql = "UPDATE user_profiles SET 
                                       date_of_birth = ?, gender = ?, address = ?, city = ?, 
                                       state = ?, country = ?, qualifications = ?, 
                                       experience_years = ?, current_job_title = ?, 
                                       current_company = ?, bio = ?, website = ?, 
                                       linkedin_url = ?, github_url = ?
                                       WHERE user_id = ?";
                        $profile_stmt = $conn->prepare($profile_sql);
                        $profile_stmt->bind_param("ssssssisssssssi",
                            $form_data['date_of_birth'], $form_data['gender'], 
                            $form_data['address'], $form_data['city'],
                            $form_data['state'], $form_data['country'],
                            $form_data['qualifications'], $form_data['experience_years'],
                            $form_data['current_job_title'], $form_data['current_company'],
                            $form_data['bio'], $form_data['website'],
                            $form_data['linkedin_url'], $form_data['github_url'],
                            $user_id
                        );
                    } else {
                        $profile_sql = "INSERT INTO user_profiles 
                                       (user_id, date_of_birth, gender, address, city, state, 
                                       country, qualifications, experience_years, 
                                       current_job_title, current_company, bio, website, 
                                       linkedin_url, github_url) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $profile_stmt = $conn->prepare($profile_sql);
                        $profile_stmt->bind_param("isssssssissssss",
                            $user_id,
                            $form_data['date_of_birth'], $form_data['gender'], 
                            $form_data['address'], $form_data['city'],
                            $form_data['state'], $form_data['country'],
                            $form_data['qualifications'], $form_data['experience_years'],
                            $form_data['current_job_title'], $form_data['current_company'],
                            $form_data['bio'], $form_data['website'],
                            $form_data['linkedin_url'], $form_data['github_url']
                        );
                    }
                    
                    $profile_stmt->execute();
                    
                    $conn->commit();
                    
                    // Send welcome email if requested
                    if ($form_data['send_welcome_email'] && $form_data['status'] === 'active') {
                        // In a real application, you would send an actual email here
                        logActivity('welcome_email_sent', "Welcome email sent to user #$user_id", 'users', $user_id);
                    }
                    
                    logActivity('user_update', "Updated user #$user_id", 'users', $user_id);
                    $_SESSION['success'] = 'User updated successfully!';
                    $success = true;
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = 'Failed to update user: ' . $e->getMessage();
                }
            } else {
                // Create new user
                $conn->begin_transaction();
                
                try {
                    // Handle school creation if needed
                    if ($form_data['role'] === 'student' && !empty($_POST['school_selection']) && $_POST['school_selection'] === 'create_new') {
                        // Create new school
                        $new_school_name = trim($_POST['new_school_name'] ?? '');
                        $new_school_address = trim($_POST['new_school_address'] ?? '');
                        $new_school_city = trim($_POST['new_school_city'] ?? '');
                        $new_school_state = trim($_POST['new_school_state'] ?? '');
                        
                        $school_sql = "INSERT INTO schools (name, address, city, state, partnership_status) 
                                      VALUES (?, ?, ?, ?, 'active')";
                        $school_stmt = $conn->prepare($school_sql);
                        $school_stmt->bind_param("ssss", 
                            $new_school_name, $new_school_address, 
                            $new_school_city, $new_school_state
                        );
                        $school_stmt->execute();
                        $form_data['school_id'] = $conn->insert_id;
                        
                        logActivity('school_create', "Created new school #{$form_data['school_id']}: $new_school_name", 'schools', $form_data['school_id']);
                    }
                    
                    // Hash password
                    $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
                    
                    // Insert into users table
                    $user_sql = "INSERT INTO users 
                                (first_name, last_name, email, phone, role, status, password, school_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $user_stmt = $conn->prepare($user_sql);
                    $user_stmt->bind_param("sssssssi", 
                        $form_data['first_name'], $form_data['last_name'], 
                        $form_data['email'], $form_data['phone'],
                        $form_data['role'], $form_data['status'],
                        $hashed_password, $form_data['school_id']
                    );
                    $user_stmt->execute();
                    
                    $new_user_id = $conn->insert_id;
                    
                    // Insert into user_profiles table
                    $profile_sql = "INSERT INTO user_profiles 
                                   (user_id, date_of_birth, gender, address, city, state, 
                                   country, qualifications, experience_years, 
                                   current_job_title, current_company, bio, website, 
                                   linkedin_url, github_url) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $profile_stmt = $conn->prepare($profile_sql);
                    $profile_stmt->bind_param("isssssssissssss",
                        $new_user_id,
                        $form_data['date_of_birth'], $form_data['gender'], 
                        $form_data['address'], $form_data['city'],
                        $form_data['state'], $form_data['country'],
                        $form_data['qualifications'], $form_data['experience_years'],
                        $form_data['current_job_title'], $form_data['current_company'],
                        $form_data['bio'], $form_data['website'],
                        $form_data['linkedin_url'], $form_data['github_url']
                    );
                    $profile_stmt->execute();
                    
                    $conn->commit();
                    
                    // Send welcome email if requested and user is active
                    if ($form_data['send_welcome_email'] && $form_data['status'] === 'active') {
                        // In a real application, you would send an actual email here
                        logActivity('welcome_email_sent', "Welcome email sent to new user #$new_user_id", 'users', $new_user_id);
                    }
                    
                    logActivity('user_create', "Created new user #$new_user_id", 'users', $new_user_id);
                    
                    // Show success message and redirect
                    $_SESSION['success'] = 'User created successfully!';
                    header("Location: view.php?id=$new_user_id");
                    exit();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = 'Failed to create user: ' . $e->getMessage();
                }
            }
        }
    }
}

// If editing, pre-fill form with user data
if ($is_edit && $user && empty($_POST)) {
    $_POST = array_merge($_POST, $user);
    $_POST['school_selection'] = $user['school_id'] ? 'select_existing' : 'none';
}

// Log activity
logActivity('user_form_access', $is_edit ? "Accessed edit user form #$user_id" : "Accessed create user form");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit User' : 'Create User'; ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f1f5f9;
            color: var(--dark);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
        .header {
            margin-bottom: 2rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .page-title h1 {
            font-size: 2rem;
            color: var(--dark);
        }

        .page-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .form-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .form-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .form-content {
            padding: 2rem;
        }

        /* Form Layout */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-section {
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-gray);
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: var(--primary);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-control.invalid {
            border-color: var(--danger);
        }

        .form-text {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
        }

        .error-message {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .error-message i {
            font-size: 0.75rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert ul {
            margin: 0.5rem 0 0 1.5rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 2rem;
            border-top: 2px solid var(--light-gray);
            margin-top: 2rem;
        }

        /* Password Generator */
        .password-generator {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .password-generator input {
            flex: 1;
        }

        .password-strength {
            margin-top: 0.5rem;
        }

        .strength-bar {
            height: 5px;
            background: var(--light-gray);
            border-radius: 3px;
            margin-bottom: 0.5rem;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            background: var(--danger);
        }

        .strength-fill.weak { width: 33%; background: var(--danger); }
        .strength-fill.medium { width: 66%; background: var(--warning); }
        .strength-fill.strong { width: 100%; background: var(--success); }

        .strength-text {
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Tabs for different user types */
        .user-type-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        .type-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 1px solid var(--light-gray);
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .type-tab:hover {
            background: var(--light);
            color: var(--primary);
        }

        .type-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .type-content {
            display: none;
        }

        .type-content.active {
            display: block;
        }

        /* School Selection Styles */
        .school-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }

        .school-option {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .school-option:hover {
            border-color: var(--primary);
            background: rgba(37, 99, 235, 0.02);
        }

        .school-option.selected {
            border-color: var(--primary);
            background: rgba(37, 99, 235, 0.05);
        }

        .school-option input[type="radio"] {
            margin-top: 0.25rem;
        }

        .school-option-content {
            flex: 1;
        }

        .school-option-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .school-option-description {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .school-form {
            display: none;
            padding: 1.5rem;
            background: var(--light);
            border-radius: 6px;
            margin-top: 1rem;
        }

        .school-form.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .school-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            margin-top: 0.5rem;
        }

        .school-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--light-gray);
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .school-item:hover {
            background: var(--light);
        }

        .school-item.selected {
            background: rgba(37, 99, 235, 0.1);
            border-left: 3px solid var(--primary);
        }

        .school-item:last-child {
            border-bottom: none;
        }

        .school-name {
            font-weight: 600;
            color: var(--dark);
        }

        .school-location {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .user-type-tabs {
                overflow-x: auto;
            }

            .school-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="manage.php">Users</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo $is_edit ? 'Edit User' : 'Create User'; ?></span>
        </div>

        <!-- Page Title -->
        <div class="page-title">
            <h1><?php echo $is_edit ? 'Edit User' : 'Create New User'; ?></h1>
            <div class="page-actions">
                <a href="manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
                <?php if ($is_edit): ?>
                    <a href="view.php?id=<?php echo $user_id; ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View User
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success'] ?? ''); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <div class="form-header">
                <h2><?php echo $is_edit ? 'Update User Information' : 'Add New User to System'; ?></h2>
                <p>Fill in the user details below. Fields marked with * are required.</p>
            </div>

            <div class="form-content">
                <form method="POST" id="userForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- User Type Selection -->
                    <div class="user-type-tabs">
                        <div class="type-tab active" onclick="showTab('basic')">Basic Information</div>
                        <div class="type-tab" onclick="showTab('account')">Account Settings</div>
                        <div class="type-tab" onclick="showTab('profile')">Profile Details</div>
                        <div class="type-tab" onclick="showTab('professional')">Professional Info</div>
                        <div class="type-tab" onclick="showTab('school')">School Info</div>
                    </div>

                    <!-- Basic Information Tab -->
                    <div class="type-content active" id="basic-tab">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="first_name" class="required">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" 
                                       required
                                       placeholder="Enter first name">
                            </div>
                            
                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" 
                                       required
                                       placeholder="Enter last name">
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="required">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                       required
                                       placeholder="user@example.com">
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                       placeholder="+234 800 000 0000">
                            </div>
                            
                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                       max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo ($_POST['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($_POST['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($_POST['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" 
                                      placeholder="Enter full address"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" 
                                       placeholder="Enter city">
                            </div>
                            
                            <div class="form-group">
                                <label for="state">State/Province</label>
                                <input type="text" id="state" name="state" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>" 
                                       placeholder="Enter state">
                            </div>
                            
                            <div class="form-group">
                                <label for="country">Country</label>
                                <input type="text" id="country" name="country" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['country'] ?? 'Nigeria'); ?>" 
                                       placeholder="Enter country">
                            </div>
                        </div>
                    </div>

                    <!-- Account Settings Tab -->
                    <div class="type-content" id="account-tab">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="role" class="required">User Role</label>
                                <select id="role" name="role" class="form-control" required onchange="updateRoleSpecificFields()">
                                    <option value="">Select Role</option>
                                    <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    <option value="instructor" <?php echo ($_POST['role'] ?? '') === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                                    <option value="student" <?php echo ($_POST['role'] ?? '') === 'student' ? 'selected' : ''; ?>>Student</option>
                                    <option value="applicant" <?php echo ($_POST['role'] ?? '') === 'applicant' ? 'selected' : ''; ?>>Applicant</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="status" class="required">Account Status</label>
                                <select id="status" name="status" class="form-control" required>
                                    <option value="">Select Status</option>
                                    <option value="active" <?php echo ($_POST['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="pending" <?php echo ($_POST['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="suspended" <?php echo ($_POST['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Password Section -->
                        <div class="section-title">
                            <h3>Password Settings</h3>
                        </div>
                        
                        <?php if (!$is_edit): ?>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="password" class="required">Password</label>
                                    <input type="password" id="password" name="password" class="form-control" 
                                           required minlength="8"
                                           placeholder="Enter password">
                                    <div class="password-strength">
                                        <div class="strength-bar">
                                            <div class="strength-fill" id="strength-fill"></div>
                                        </div>
                                        <div class="strength-text" id="strength-text">Password Strength: Weak</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password" class="required">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                           required
                                           placeholder="Confirm password">
                                    <div class="error-message" id="password-match-error" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> Passwords do not match
                                    </div>
                                </div>
                            </div>
                            
                            <div class="password-generator">
                                <input type="text" id="generated-password" class="form-control" readonly 
                                       placeholder="Click Generate to create a password">
                                <button type="button" class="btn btn-secondary" onclick="generatePassword()">
                                    <i class="fas fa-key"></i> Generate
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="copyPassword()">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Leave password fields blank to keep the current password.
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="password">New Password</label>
                                    <input type="password" id="password" name="password" class="form-control" 
                                           minlength="8"
                                           placeholder="Leave blank to keep current">
                                    <div class="password-strength">
                                        <div class="strength-bar">
                                            <div class="strength-fill" id="strength-fill"></div>
                                        </div>
                                        <div class="strength-text" id="strength-text">Password Strength: Weak</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                           placeholder="Confirm new password">
                                    <div class="error-message" id="password-match-error" style="display: none;">
                                        <i class="fas fa-exclamation-circle"></i> Passwords do not match
                                    </div>
                                </div>
                            </div>
                            
                            <div class="password-generator">
                                <input type="text" id="generated-password" class="form-control" readonly 
                                       placeholder="Click Generate to create a password">
                                <button type="button" class="btn btn-secondary" onclick="generatePassword()">
                                    <i class="fas fa-key"></i> Generate
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="copyPassword()">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="send_welcome_email" name="send_welcome_email" 
                                   <?php echo ($_POST['send_welcome_email'] ?? true) ? 'checked' : ''; ?>>
                            <label for="send_welcome_email">Send welcome email to user</label>
                        </div>
                    </div>

                    <!-- Profile Details Tab -->
                    <div class="type-content" id="profile-tab">
                        <div class="form-group">
                            <label for="bio">Biography</label>
                            <textarea id="bio" name="bio" class="form-control" 
                                      placeholder="Tell us about yourself..."
                                      rows="4"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                            <div class="form-text">
                                Brief introduction about the user. This will be visible on their profile.
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="website">Website</label>
                                <input type="url" id="website" name="website" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>" 
                                       placeholder="https://example.com">
                            </div>
                            
                            <div class="form-group">
                                <label for="linkedin_url">LinkedIn Profile</label>
                                <input type="url" id="linkedin_url" name="linkedin_url" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['linkedin_url'] ?? ''); ?>" 
                                       placeholder="https://linkedin.com/in/username">
                            </div>
                            
                            <div class="form-group">
                                <label for="github_url">GitHub Profile</label>
                                <input type="url" id="github_url" name="github_url" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['github_url'] ?? ''); ?>" 
                                       placeholder="https://github.com/username">
                            </div>
                        </div>
                    </div>

                    <!-- Professional Info Tab -->
                    <div class="type-content" id="professional-tab">
                        <div class="form-group">
                            <label for="qualifications">Qualifications</label>
                            <textarea id="qualifications" name="qualifications" class="form-control" 
                                      placeholder="Educational background, certifications, degrees..."
                                      rows="3"><?php echo htmlspecialchars($_POST['qualifications'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="experience_years">Years of Experience</label>
                                <input type="number" id="experience_years" name="experience_years" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['experience_years'] ?? '0'); ?>" 
                                       min="0" max="50"
                                       placeholder="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="current_job_title">Current Job Title</label>
                                <input type="text" id="current_job_title" name="current_job_title" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['current_job_title'] ?? ''); ?>" 
                                       placeholder="e.g., Senior Developer">
                            </div>
                            
                            <div class="form-group">
                                <label for="current_company">Current Company</label>
                                <input type="text" id="current_company" name="current_company" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['current_company'] ?? ''); ?>" 
                                       placeholder="Company name">
                            </div>
                        </div>
                    </div>

                    <!-- School Info Tab -->
                    <div class="type-content" id="school-tab">
                        <div class="form-group">
                            <label for="role">User Role</label>
                            <input type="text" id="display_role" class="form-control" 
                                   value="<?php echo htmlspecialchars(ucfirst($_POST['role'] ?? '')); ?>" 
                                   readonly>
                            <div class="form-text">
                                School information is primarily for students. Other roles may not require school assignment.
                            </div>
                        </div>
                        
                        <?php if (($_POST['role'] ?? '') === 'student' || $is_edit): ?>
                        <div class="school-options">
                            <div class="school-option <?php echo (($_POST['school_selection'] ?? 'none') === 'none') ? 'selected' : ''; ?>" 
                                 onclick="selectSchoolOption('none')">
                                <input type="radio" name="school_selection" value="none" 
                                       <?php echo (($_POST['school_selection'] ?? 'none') === 'none') ? 'checked' : ''; ?>>
                                <div class="school-option-content">
                                    <div class="school-option-title">No School</div>
                                    <div class="school-option-description">
                                        User is not associated with any school (e.g., independent student)
                                    </div>
                                </div>
                            </div>
                            
                            <div class="school-option <?php echo (($_POST['school_selection'] ?? '') === 'select_existing') ? 'selected' : ''; ?>" 
                                 onclick="selectSchoolOption('select_existing')">
                                <input type="radio" name="school_selection" value="select_existing" 
                                       <?php echo (($_POST['school_selection'] ?? '') === 'select_existing') ? 'checked' : ''; ?>>
                                <div class="school-option-content">
                                    <div class="school-option-title">Select Existing School</div>
                                    <div class="school-option-description">
                                        Choose from the list of registered partner schools
                                    </div>
                                </div>
                            </div>
                            
                            <div class="school-option <?php echo (($_POST['school_selection'] ?? '') === 'create_new') ? 'selected' : ''; ?>" 
                                 onclick="selectSchoolOption('create_new')">
                                <input type="radio" name="school_selection" value="create_new" 
                                       <?php echo (($_POST['school_selection'] ?? '') === 'create_new') ? 'checked' : ''; ?>>
                                <div class="school-option-content">
                                    <div class="school-option-title">Create New School</div>
                                    <div class="school-option-description">
                                        Register a new partner school in the system
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Existing School Selection -->
                        <div id="existing-school-form" class="school-form <?php echo (($_POST['school_selection'] ?? '') === 'select_existing') ? 'active' : ''; ?>">
                            <div class="form-group">
                                <label for="school_id" class="required">Select School</label>
                                <?php if (!empty($schools)): ?>
                                    <div class="school-list">
                                        <?php foreach ($schools as $school): ?>
                                            <div class="school-item <?php echo (($_POST['school_id'] ?? '') == $school['id']) ? 'selected' : ''; ?>" 
                                                 onclick="selectSchool(<?php echo $school['id']; ?>, '<?php echo htmlspecialchars($school['name']); ?>')">
                                                <div class="school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                                                <div class="school-location">
                                                    <?php echo htmlspecialchars($school['city'] ?? ''); ?>
                                                    <?php if (!empty($school['city']) && !empty($school['state'])): ?>, <?php endif; ?>
                                                    <?php echo htmlspecialchars($school['state'] ?? ''); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" id="school_id" name="school_id" value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>">
                                <?php else: ?>
                                    <div class="alert alert-error">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        No active schools found. Please create a new school or contact an administrator.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- New School Form -->
                        <div id="new-school-form" class="school-form <?php echo (($_POST['school_selection'] ?? '') === 'create_new') ? 'active' : ''; ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="new_school_name" class="required">School Name</label>
                                    <input type="text" id="new_school_name" name="new_school_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_name'] ?? ''); ?>" 
                                           placeholder="Enter school name">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_school_short_name">Short Name / Abbreviation</label>
                                    <input type="text" id="new_school_short_name" name="new_school_short_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_short_name'] ?? ''); ?>" 
                                           placeholder="e.g., ABC High School">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_school_address" class="required">School Address</label>
                                <textarea id="new_school_address" name="new_school_address" class="form-control" 
                                          placeholder="Enter full school address" rows="3"><?php echo htmlspecialchars($_POST['new_school_address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="new_school_city">City</label>
                                    <input type="text" id="new_school_city" name="new_school_city" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_city'] ?? ''); ?>" 
                                           placeholder="City">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_school_state">State/Province</label>
                                    <input type="text" id="new_school_state" name="new_school_state" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_state'] ?? ''); ?>" 
                                           placeholder="State">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_school_country">Country</label>
                                    <input type="text" id="new_school_country" name="new_school_country" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_country'] ?? 'Nigeria'); ?>" 
                                           placeholder="Country">
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="new_school_contact_person">Contact Person</label>
                                    <input type="text" id="new_school_contact_person" name="new_school_contact_person" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_contact_person'] ?? ''); ?>" 
                                           placeholder="Principal/Coordinator name">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_school_contact_email">Contact Email</label>
                                    <input type="email" id="new_school_contact_email" name="new_school_contact_email" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_contact_email'] ?? ''); ?>" 
                                           placeholder="contact@school.edu">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_school_contact_phone">Contact Phone</label>
                                    <input type="tel" id="new_school_contact_phone" name="new_school_contact_phone" class="form-control" 
                                           value="<?php echo htmlspecialchars($_POST['new_school_contact_phone'] ?? ''); ?>" 
                                           placeholder="+234 800 000 0000">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_school_notes">Additional Notes</label>
                                <textarea id="new_school_notes" name="new_school_notes" class="form-control" 
                                          placeholder="Any additional information about the school" rows="2"><?php echo htmlspecialchars($_POST['new_school_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            School information is only applicable for students. Please select "Student" role in Account Settings tab to assign a school.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <div>
                            <a href="manage.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                        
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $is_edit ? 'Update User' : 'Create User'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tab navigation
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.type-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Deactivate all tab buttons
            document.querySelectorAll('.type-tab').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            event.target.classList.add('active');
            
            // Update role display in school tab
            if (tabName === 'school') {
                updateSchoolTab();
            }
        }

        // School selection handling
        function selectSchoolOption(option) {
            // Update radio button
            document.querySelector(`input[name="school_selection"][value="${option}"]`).checked = true;
            
            // Update visual selection
            document.querySelectorAll('.school-option').forEach(el => {
                el.classList.remove('selected');
            });
            event.target.closest('.school-option').classList.add('selected');
            
            // Show/hide forms
            document.getElementById('existing-school-form').classList.remove('active');
            document.getElementById('new-school-form').classList.remove('active');
            
            if (option === 'select_existing') {
                document.getElementById('existing-school-form').classList.add('active');
            } else if (option === 'create_new') {
                document.getElementById('new-school-form').classList.add('active');
            }
        }

        function selectSchool(schoolId, schoolName) {
            // Update hidden input
            document.getElementById('school_id').value = schoolId;
            
            // Update visual selection
            document.querySelectorAll('.school-item').forEach(el => {
                el.classList.remove('selected');
            });
            event.target.closest('.school-item').classList.add('selected');
        }

        function updateSchoolTab() {
            const role = document.getElementById('role').value;
            const displayRole = document.getElementById('display_role');
            const schoolTab = document.getElementById('school-tab');
            
            // Update displayed role
            displayRole.value = role.charAt(0).toUpperCase() + role.slice(1);
            
            // Show/hide school options based on role
            const schoolOptions = schoolTab.querySelector('.school-options');
            const schoolInfoMessage = schoolTab.querySelector('.alert-info');
            
            if (role === 'student') {
                if (schoolOptions) schoolOptions.style.display = 'flex';
                if (schoolInfoMessage) schoolInfoMessage.style.display = 'none';
                
                // Ensure school selection is visible
                const currentSelection = document.querySelector('input[name="school_selection"]:checked')?.value || 'none';
                selectSchoolOption(currentSelection);
            } else {
                if (schoolOptions) schoolOptions.style.display = 'none';
                if (schoolInfoMessage) schoolInfoMessage.style.display = 'block';
            }
        }

        // Update role-specific fields
        function updateRoleSpecificFields() {
            updateSchoolTab();
            
            // You can add other role-specific field updates here
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            let score = 0;
            
            // Length check
            if (password.length >= 8) score++;
            if (password.length >= 12) score++;
            
            // Complexity checks
            if (/[A-Z]/.test(password)) score++;
            if (/[a-z]/.test(password)) score++;
            if (/\d/.test(password)) score++;
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) score++;
            
            // Update UI
            const strengthFill = document.getElementById('strength-fill');
            const strengthText = document.getElementById('strength-text');
            
            if (password.length === 0) {
                strengthFill.className = 'strength-fill';
                strengthFill.style.width = '0';
                strengthText.textContent = 'Password Strength: Weak';
                return 'weak';
            }
            
            if (score <= 3) {
                strengthFill.className = 'strength-fill weak';
                strengthText.textContent = 'Password Strength: Weak';
                return 'weak';
            } else if (score <= 5) {
                strengthFill.className = 'strength-fill medium';
                strengthText.textContent = 'Password Strength: Medium';
                return 'medium';
            } else {
                strengthFill.className = 'strength-fill strong';
                strengthText.textContent = 'Password Strength: Strong';
                return 'strong';
            }
        }

        // Password match validation
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const errorElement = document.getElementById('password-match-error');
            
            if (password && confirmPassword && password !== confirmPassword) {
                errorElement.style.display = 'flex';
                return false;
            } else {
                errorElement.style.display = 'none';
                return true;
            }
        }

        // Generate random password
        function generatePassword() {
            const length = 12;
            const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
            let password = '';
            
            for (let i = 0; i < length; i++) {
                const randomIndex = Math.floor(Math.random() * charset.length);
                password += charset[randomIndex];
            }
            
            document.getElementById('password').value = password;
            document.getElementById('confirm_password').value = password;
            document.getElementById('generated-password').value = password;
            
            checkPasswordStrength(password);
            checkPasswordMatch();
        }

        // Copy password to clipboard
        function copyPassword() {
            const passwordField = document.getElementById('generated-password');
            if (passwordField.value) {
                passwordField.select();
                document.execCommand('copy');
                
                // Show confirmation
                const originalText = passwordField.placeholder;
                passwordField.placeholder = 'Password copied to clipboard!';
                setTimeout(() => {
                    passwordField.placeholder = originalText;
                }, 2000);
            }
        }

        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All unsaved changes will be lost.')) {
                document.getElementById('userForm').reset();
                
                // Reset password strength indicator
                document.getElementById('strength-fill').className = 'strength-fill';
                document.getElementById('strength-fill').style.width = '0';
                document.getElementById('strength-text').textContent = 'Password Strength: Weak';
                
                // Hide password match error
                document.getElementById('password-match-error').style.display = 'none';
                
                // Clear generated password field
                document.getElementById('generated-password').value = '';
                
                // Reset school selection
                selectSchoolOption('none');
                
                // Reset to first tab
                showTab('basic');
            }
        }

        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            // Check required fields
            const requiredFields = this.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('invalid');
                    isValid = false;
                } else {
                    field.classList.remove('invalid');
                }
            });
            
            // Check email format
            const emailField = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailField.value && !emailRegex.test(emailField.value)) {
                emailField.classList.add('invalid');
                isValid = false;
            }
            
            // Check password match (if password is provided)
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            if (password || confirmPassword) {
                if (!checkPasswordMatch()) {
                    isValid = false;
                }
                if (password.length < 8) {
                    document.getElementById('password').classList.add('invalid');
                    isValid = false;
                }
            }
            
            // Validate school selection for students
            const role = document.getElementById('role').value;
            if (role === 'student') {
                const schoolSelection = document.querySelector('input[name="school_selection"]:checked')?.value;
                if (schoolSelection === 'select_existing' && !document.getElementById('school_id').value) {
                    alert('Please select a school from the list.');
                    isValid = false;
                } else if (schoolSelection === 'create_new') {
                    const schoolName = document.getElementById('new_school_name').value;
                    const schoolAddress = document.getElementById('new_school_address').value;
                    if (!schoolName || !schoolAddress) {
                        alert('Please provide school name and address when creating a new school.');
                        isValid = false;
                    }
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = this.querySelector('.invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        // Real-time validation
        document.getElementById('password')?.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
            if (this.value.length >= 8) {
                this.classList.remove('invalid');
            }
        });

        document.getElementById('confirm_password')?.addEventListener('input', checkPasswordMatch);
        document.getElementById('email')?.addEventListener('input', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.classList.add('invalid');
            } else {
                this.classList.remove('invalid');
            }
        });

        // Auto-fill password fields when generated password is used
        document.getElementById('generated-password')?.addEventListener('input', function() {
            if (this.value) {
                document.getElementById('password').value = this.value;
                document.getElementById('confirm_password').value = this.value;
                checkPasswordStrength(this.value);
                checkPasswordMatch();
            }
        });

        // Role change updates school tab
        document.getElementById('role')?.addEventListener('change', updateSchoolTab);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[type="submit"]').click();
            }
            
            // Ctrl+1 through Ctrl+5 for tabs
            if (e.ctrlKey && e.key >= '1' && e.key <= '5') {
                e.preventDefault();
                const tabs = ['basic', 'account', 'profile', 'professional', 'school'];
                const index = parseInt(e.key) - 1;
                if (tabs[index]) {
                    showTab(tabs[index]);
                }
            }
        });

        // Initialize password strength if password exists
        const initialPassword = document.getElementById('password').value;
        if (initialPassword) {
            checkPasswordStrength(initialPassword);
            checkPasswordMatch();
        }

        // Initialize school tab
        updateSchoolTab();

        // Auto-focus first field
        document.getElementById('first_name').focus();
    </script>
</body>
</html>