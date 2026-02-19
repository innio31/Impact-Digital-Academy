<?php
// modules/admin/academic/classes/create.php

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

// Check if editing existing class
$edit_mode = false;
$class_id = $_GET['id'] ?? 0;
$class_data = [];

if ($class_id) {
    $edit_mode = true;

    // Fetch class data
    $sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name, 
                   p.program_code, p.program_type, u.first_name as instructor_first_name,
                   u.last_name as instructor_last_name,
                   ap.id as academic_period_id, ap.period_name, ap.period_type,
                   ap.start_date, ap.end_date, ap.academic_year
            FROM class_batches cb
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            LEFT JOIN users u ON cb.instructor_id = u.id
            LEFT JOIN academic_periods ap ON (
                (cb.program_type = 'onsite' AND cb.term_number = ap.period_number AND ap.period_type = 'term') OR
                (cb.program_type = 'online' AND cb.block_number = ap.period_number AND ap.period_type = 'block') OR
                (cb.program_type = 'school' AND cb.term_number = ap.period_number AND ap.period_type = 'term')
            ) AND ap.program_type = cb.program_type
            WHERE cb.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $class_data = $result->fetch_assoc();

    if (!$class_data) {
        $_SESSION['error'] = 'Class not found.';
        header('Location: list.php');
        exit();
    }
}

// Get courses for dropdown
$courses_sql = "SELECT c.*, p.program_code, p.name as program_name, p.program_type 
                FROM courses c 
                JOIN programs p ON c.program_id = p.id 
                WHERE c.status = 'active' 
                ORDER BY p.program_code, c.order_number";
$courses_result = $conn->query($courses_sql);
$courses = $courses_result->fetch_all(MYSQLI_ASSOC);

// Get instructors for dropdown
$instructors_sql = "SELECT id, first_name, last_name, email FROM users 
                    WHERE role = 'instructor' AND status = 'active' 
                    ORDER BY first_name, last_name";
$instructors_result = $conn->query($instructors_sql);
$instructors = $instructors_result->fetch_all(MYSQLI_ASSOC);

// Get academic periods for dropdown (active upcoming periods)
$academic_periods_sql = "SELECT * FROM academic_periods 
                         WHERE status IN ('upcoming', 'active')
                         ORDER BY program_type, academic_year DESC, period_number";
$academic_periods_result = $conn->query($academic_periods_sql);
$academic_periods = $academic_periods_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Collect form data
        $form_data = [
            'batch_code' => trim($_POST['batch_code'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'course_id' => !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null,
            'instructor_id' => !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null,
            'max_students' => !empty($_POST['max_students']) ? (int)$_POST['max_students'] : 30,
            'schedule' => trim($_POST['schedule'] ?? ''),
            'meeting_link' => trim($_POST['meeting_link'] ?? ''),
            'program_type' => trim($_POST['program_type'] ?? 'online'),
            'academic_period_id' => !empty($_POST['academic_period_id']) ? (int)$_POST['academic_period_id'] : null
        ];

        // Get academic period details
        $academic_period = null;
        if ($form_data['academic_period_id']) {
            $period_sql = "SELECT * FROM academic_periods WHERE id = ?";
            $period_stmt = $conn->prepare($period_sql);
            $period_stmt->bind_param('i', $form_data['academic_period_id']);
            $period_stmt->execute();
            $period_result = $period_stmt->get_result();
            $academic_period = $period_result->fetch_assoc();
        }

        // Validate required fields
        $required_fields = ['batch_code', 'name', 'course_id', 'program_type', 'academic_period_id'];
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate batch code uniqueness (except in edit mode for same class)
        if (!empty($form_data['batch_code'])) {
            $check_sql = "SELECT id FROM class_batches WHERE batch_code = ?";
            $check_params = [$form_data['batch_code']];

            if ($edit_mode) {
                $check_sql .= " AND id != ?";
                $check_params[] = $class_id;
            }

            $check_stmt = $conn->prepare($check_sql);
            $check_types = $edit_mode ? 'si' : 's';
            $check_stmt->bind_param($check_types, ...$check_params);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $errors[] = 'Batch code already exists. Please use a unique code.';
            }
        }

        // Validate max students
        if ($form_data['max_students'] < 1 || $form_data['max_students'] > 100) {
            $errors[] = 'Maximum students must be between 1 and 100.';
        }

        // Validate meeting link (if provided)
        if (!empty($form_data['meeting_link']) && !filter_var($form_data['meeting_link'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid meeting link URL.';
        }

        // Validate academic period
        if ($academic_period) {
            $form_data['start_date'] = $academic_period['start_date'];
            $form_data['end_date'] = $academic_period['end_date'];
            $form_data['duration_weeks'] = $academic_period['duration_weeks'];

            // Set term/block based on program type
            if (($academic_period['program_type'] === 'onsite' || $academic_period['program_type'] === 'school') && $academic_period['period_type'] === 'term') {
                $form_data['term_number'] = $academic_period['period_number'];
                $form_data['term_name'] = $academic_period['period_name'];
                $form_data['block_number'] = null;
                $form_data['block_name'] = null;
            } else if ($academic_period['program_type'] === 'online' && $academic_period['period_type'] === 'block') {
                $form_data['block_number'] = $academic_period['period_number'];
                $form_data['block_name'] = $academic_period['period_name'];
                $form_data['term_number'] = null;
                $form_data['term_name'] = null;
            }

            $form_data['academic_year'] = $academic_period['academic_year'];

            // Set status based on academic period
            $form_data['status'] = $academic_period['status'] === 'active' ? 'ongoing' : 'scheduled';
        }

        // If no errors, process creation/update
        if (empty($errors)) {
            // Start transaction
            $conn->begin_transaction();

            try {
                if ($edit_mode) {
                    // Update existing class
                    $update_sql = "UPDATE class_batches SET 
                        batch_code = ?, 
                        name = ?, 
                        description = ?, 
                        course_id = ?, 
                        instructor_id = ?, 
                        max_students = ?, 
                        start_date = ?, 
                        end_date = ?, 
                        schedule = ?, 
                        meeting_link = ?, 
                        status = ?, 
                        program_type = ?,
                        term_number = ?,
                        block_number = ?,
                        academic_year = ?,
                        term_name = ?,
                        block_name = ?,
                        updated_at = NOW() 
                        WHERE id = ?";

                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param(
                        'sssiiissssssiisssi',  // 18 parameters
                        $form_data['batch_code'],
                        $form_data['name'],
                        $form_data['description'],
                        $form_data['course_id'],
                        $form_data['instructor_id'],
                        $form_data['max_students'],
                        $form_data['start_date'],
                        $form_data['end_date'],
                        $form_data['schedule'],
                        $form_data['meeting_link'],
                        $form_data['status'],
                        $form_data['program_type'],
                        $form_data['term_number'],
                        $form_data['block_number'],
                        $form_data['academic_year'],
                        $form_data['term_name'],
                        $form_data['block_name'],
                        $class_id
                    );

                    $update_stmt->execute();

                    $action = 'update';
                    $action_text = 'updated';
                } else {
                    // Create new class
                    $insert_sql = "INSERT INTO class_batches (
                        batch_code, name, description, course_id, instructor_id, 
                        max_students, start_date, end_date, schedule, meeting_link, 
                        status, program_type, term_number, block_number, academic_year,
                        term_name, block_name, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        'sssiiissssssiisss',  // 17 parameters (no class_id)
                        $form_data['batch_code'],
                        $form_data['name'],
                        $form_data['description'],
                        $form_data['course_id'],
                        $form_data['instructor_id'],
                        $form_data['max_students'],
                        $form_data['start_date'],
                        $form_data['end_date'],
                        $form_data['schedule'],
                        $form_data['meeting_link'],
                        $form_data['status'],
                        $form_data['program_type'],
                        $form_data['term_number'],
                        $form_data['block_number'],
                        $form_data['academic_year'],
                        $form_data['term_name'],
                        $form_data['block_name']
                    );

                    $insert_stmt->execute();
                    $class_id = $conn->insert_id;

                    $action = 'create';
                    $action_text = 'created';
                }

                $conn->commit();
                $success = true;

                // Log activity
                logActivity(
                    $_SESSION['user_id'],
                    "class_{$action}",
                    "Class {$action_text}: {$form_data['batch_code']} - {$form_data['name']}",
                    'class_batches',
                    $class_id
                );

                // Set success message
                $_SESSION['success'] = "Class successfully {$action_text}.";

                // Redirect to view page
                header('Location: view.php?id=' . $class_id);
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Failed to save class: ' . $e->getMessage();
            }
        }
    }
}

// Log activity
$action_text = $edit_mode ? 'edit' : 'create';
logActivity($_SESSION['user_id'], "class_form_access", "Accessed {$action_text} class form");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit Class' : 'Create New Class'; ?> - Admin Dashboard</title>
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
            --school: #8b5cf6;
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

        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .form-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .form-header h2 {
            color: var(--dark);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-header i {
            color: var(--primary);
        }

        .form-content {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-section h3 i {
            color: var(--primary);
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
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-control.invalid {
            border-color: var(--danger);
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

        .form-help {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
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

        .form-actions {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
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

        .course-info {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .course-info h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .course-info p {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .radio-group {
            display: flex;
            gap: 2rem;
            margin-top: 0.5rem;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-option input[type="radio"] {
            margin: 0;
        }

        .character-count {
            text-align: right;
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .period-info {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .period-info h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .period-info p {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .status-upcoming {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .date-display {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .date-item {
            flex: 1;
        }

        .program-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .badge-online {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-onsite {
            background: #dcfce7;
            color: #166534;
        }

        .badge-school {
            background: #f3e8ff;
            color: #6b21a8;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .radio-group {
                flex-direction: column;
                gap: 1rem;
            }

            .date-display {
                flex-direction: column;
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
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/">
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php">Classes</a> &rsaquo;
                        <?php echo $edit_mode ? 'Edit Class' : 'Create New Class'; ?>
                    </div>
                    <h1><?php echo $edit_mode ? 'Edit Class' : 'Create New Class'; ?></h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Class successfully <?php echo $edit_mode ? 'updated' : 'created'; ?>!
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Container -->
            <div class="form-container">
                <div class="form-header">
                    <h2><i class="fas fa-chalkboard-teacher"></i> <?php echo $edit_mode ? 'Edit Class Details' : 'Create New Class Batch'; ?></h2>
                </div>

                <!-- Form -->
                <form method="POST" id="classForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-content">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="batch_code" class="required">Batch Code</label>
                                    <input type="text" id="batch_code" name="batch_code" class="form-control"
                                        value="<?php echo htmlspecialchars($form_data['batch_code'] ?? $class_data['batch_code'] ?? ''); ?>"
                                        required
                                        placeholder="e.g., DM101-2024-B1"
                                        pattern="[A-Za-z0-9\-]+"
                                        title="Only letters, numbers, and hyphens allowed">
                                    <div class="form-help">Unique identifier for this class batch</div>
                                </div>

                                <div class="form-group">
                                    <label for="name" class="required">Class Name</label>
                                    <input type="text" id="name" name="name" class="form-control"
                                        value="<?php echo htmlspecialchars($form_data['name'] ?? $class_data['name'] ?? ''); ?>"
                                        required
                                        placeholder="e.g., Digital Marketing Fundamentals - Batch 1">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="form-control"
                                    placeholder="Brief description of this class batch..."
                                    rows="4"><?php echo htmlspecialchars($form_data['description'] ?? $class_data['description'] ?? ''); ?></textarea>
                                <div class="character-count">
                                    <span id="description_count">0</span> / 500 characters
                                </div>
                            </div>
                        </div>

                        <!-- Course Selection -->
                        <div class="form-section">
                            <h3><i class="fas fa-graduation-cap"></i> Course Selection</h3>
                            <div class="form-group">
                                <label for="course_id" class="required">Select Course</label>
                                <select id="course_id" name="course_id" class="form-control" required
                                    onchange="updateCourseInfo()">
                                    <option value="">-- Select a Course --</option>
                                    <?php
                                    $courses_by_program = [];
                                    foreach ($courses as $course) {
                                        if (!isset($courses_by_program[$course['program_name']])) {
                                            $courses_by_program[$course['program_name']] = [];
                                        }
                                        $courses_by_program[$course['program_name']][] = $course;
                                    }

                                    foreach ($courses_by_program as $program_name => $program_courses):
                                    ?>
                                        <optgroup label="<?php echo htmlspecialchars($program_name); ?>">
                                            <?php foreach ($program_courses as $course): ?>
                                                <option value="<?php echo $course['id']; ?>"
                                                    <?php echo ($form_data['course_id'] ?? $class_data['course_id'] ?? '') == $course['id'] ? 'selected' : ''; ?>
                                                    data-program-type="<?php echo $course['program_type']; ?>"
                                                    data-course-code="<?php echo $course['course_code']; ?>"
                                                    data-course-title="<?php echo htmlspecialchars($course['title']); ?>"
                                                    data-program-name="<?php echo htmlspecialchars($course['program_name']); ?>">
                                                    <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['title']); ?>
                                                    (<?php 
                                                        if ($course['program_type'] === 'onsite') echo 'Onsite';
                                                        elseif ($course['program_type'] === 'online') echo 'Online';
                                                        elseif ($course['program_type'] === 'school') echo 'School-Based';
                                                        else echo ucfirst($course['program_type']);
                                                    ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="course-info" style="display: none;">
                                <div class="course-info">
                                    <h4 id="selected-course-title"></h4>
                                    <p><strong>Course Code:</strong> <span id="selected-course-code"></span></p>
                                    <p><strong>Program:</strong> <span id="selected-program-name"></span></p>
                                    <p><strong>Program Type:</strong> 
                                        <span id="selected-program-type-badge"></span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Program Type Selection -->
                        <div class="form-section">
                            <h3><i class="fas fa-graduation-cap"></i> Program Type</h3>
                            <div class="form-group">
                                <label class="required">Program Type</label>
                                <div class="radio-group">
                                    <div class="radio-option">
                                        <input type="radio" id="program_type_online" name="program_type"
                                            value="online"
                                            <?php echo ($form_data['program_type'] ?? $class_data['program_type'] ?? 'online') === 'online' ? 'checked' : ''; ?>
                                            onchange="filterAcademicPeriods()">
                                        <label for="program_type_online">Online Program</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="program_type_onsite" name="program_type"
                                            value="onsite"
                                            <?php echo ($form_data['program_type'] ?? $class_data['program_type'] ?? '') === 'onsite' ? 'checked' : ''; ?>
                                            onchange="filterAcademicPeriods()">
                                        <label for="program_type_onsite">Onsite Program</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="program_type_school" name="program_type"
                                            value="school"
                                            <?php echo ($form_data['program_type'] ?? $class_data['program_type'] ?? '') === 'school' ? 'checked' : ''; ?>
                                            onchange="filterAcademicPeriods()">
                                        <label for="program_type_school">School-Based Program</label>
                                    </div>
                                </div>
                                <div class="form-help">Select the program type for this class</div>
                            </div>
                        </div>

                        <!-- Academic Period Selection -->
                        <div class="form-section">
                            <h3><i class="fas fa-calendar-alt"></i> Academic Period</h3>
                            <div class="form-group">
                                <label for="academic_period_id" class="required">Select Block/Term</label>
                                <select id="academic_period_id" name="academic_period_id" class="form-control" required
                                    onchange="updatePeriodInfo()">
                                    <option value="">-- Select a Block/Term --</option>
                                    <?php
                                    // Group periods by program type and academic year
                                    $periods_by_program = [];
                                    foreach ($academic_periods as $period) {
                                        $key = $period['program_type'] . '_' . $period['academic_year'];
                                        if (!isset($periods_by_program[$key])) {
                                            $periods_by_program[$key] = [];
                                        }
                                        $periods_by_program[$key][] = $period;
                                    }

                                    foreach ($periods_by_program as $key => $periods):
                                        list($program_type, $academic_year) = explode('_', $key, 2);
                                    ?>
                                        <optgroup label="<?php 
                                            if ($program_type === 'onsite') echo 'Onsite';
                                            elseif ($program_type === 'online') echo 'Online';
                                            elseif ($program_type === 'school') echo 'School-Based';
                                            else echo ucfirst($program_type);
                                        ?> - <?php echo $academic_year; ?>"
                                            data-program-type="<?php echo $program_type; ?>">
                                            <?php foreach ($periods as $period): ?>
                                                <option value="<?php echo $period['id']; ?>"
                                                    <?php echo ($form_data['academic_period_id'] ?? $class_data['academic_period_id'] ?? '') == $period['id'] ? 'selected' : ''; ?>
                                                    data-program-type="<?php echo $period['program_type']; ?>"
                                                    data-period-type="<?php echo $period['period_type']; ?>"
                                                    data-period-name="<?php echo htmlspecialchars($period['period_name']); ?>"
                                                    data-period-number="<?php echo $period['period_number']; ?>"
                                                    data-academic-year="<?php echo $period['academic_year']; ?>"
                                                    data-start-date="<?php echo $period['start_date']; ?>"
                                                    data-end-date="<?php echo $period['end_date']; ?>"
                                                    data-duration-weeks="<?php echo $period['duration_weeks']; ?>"
                                                    data-status="<?php echo $period['status']; ?>">
                                                    <?php echo htmlspecialchars($period['period_name']); ?>
                                                    (<?php echo $period['period_type'] === 'term' ? 'Term' : 'Block'; ?> <?php echo $period['period_number']; ?>)
                                                    - <?php echo date('M j', strtotime($period['start_date'])); ?> to <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">Select the academic period for this class</div>
                            </div>

                            <div id="period-info" style="display: none;">
                                <div class="period-info">
                                    <h4 id="selected-period-name"></h4>
                                    <div class="date-display">
                                        <div class="date-item">
                                            <p><strong>Start Date:</strong> <span id="selected-start-date"></span></p>
                                        </div>
                                        <div class="date-item">
                                            <p><strong>End Date:</strong> <span id="selected-end-date"></span></p>
                                        </div>
                                        <div class="date-item">
                                            <p><strong>Duration:</strong> <span id="selected-duration"></span> weeks</p>
                                        </div>
                                    </div>
                                    <p><strong>Academic Year:</strong> <span id="selected-academic-year"></span></p>
                                    <p><strong>Status:</strong> <span id="selected-period-status"></span></p>
                                    <p><strong>Class Status:</strong> <span id="calculated-class-status"></span></p>
                                </div>
                            </div>
                        </div>

                        <!-- Instructor Assignment -->
                        <div class="form-section">
                            <h3><i class="fas fa-user-tie"></i> Instructor Assignment</h3>
                            <div class="form-group">
                                <label for="instructor_id">Assign Instructor</label>
                                <select id="instructor_id" name="instructor_id" class="form-control">
                                    <option value="">-- No Instructor Assigned --</option>
                                    <?php foreach ($instructors as $instructor): ?>
                                        <option value="<?php echo $instructor['id']; ?>"
                                            <?php echo ($form_data['instructor_id'] ?? $class_data['instructor_id'] ?? '') == $instructor['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?>
                                            (<?php echo htmlspecialchars($instructor['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">You can assign an instructor later if needed</div>
                            </div>
                        </div>

                        <!-- Schedule & Capacity -->
                        <div class="form-section">
                            <h3><i class="fas fa-calendar-alt"></i> Schedule & Capacity</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="max_students" class="required">Maximum Students</label>
                                    <input type="number" id="max_students" name="max_students" class="form-control"
                                        value="<?php echo htmlspecialchars($form_data['max_students'] ?? $class_data['max_students'] ?? 30); ?>"
                                        required min="1" max="100"
                                        placeholder="30">
                                    <div class="form-help">Maximum number of students allowed in this class</div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="schedule">Class Schedule</label>
                                    <textarea id="schedule" name="schedule" class="form-control"
                                        placeholder="e.g., Mondays & Wednesdays, 6:00 PM - 8:00 PM...
Or: Every Tuesday, 10:00 AM - 12:00 PM (Virtual)"
                                        rows="3"><?php echo htmlspecialchars($form_data['schedule'] ?? $class_data['schedule'] ?? ''); ?></textarea>
                                    <div class="form-help">Describe the class schedule and timing</div>
                                </div>

                                <div class="form-group">
                                    <label for="meeting_link">Meeting Link (for online classes)</label>
                                    <input type="url" id="meeting_link" name="meeting_link" class="form-control"
                                        value="<?php echo htmlspecialchars($form_data['meeting_link'] ?? $class_data['meeting_link'] ?? ''); ?>"
                                        placeholder="https://meet.google.com/xxx-yyyy-zzz">
                                    <div class="form-help">For online classes only. Leave blank for onsite or school-based classes.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <div>
                            <?php if ($edit_mode): ?>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>"
                                    class="btn btn-secondary">
                                    <i class="fas fa-eye"></i> View Class
                                </a>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; gap: 1rem;">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $edit_mode ? 'Update Class' : 'Create Class'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Update course information when course is selected
        function updateCourseInfo() {
            const courseSelect = document.getElementById('course_id');
            const selectedOption = courseSelect.options[courseSelect.selectedIndex];
            const courseInfo = document.getElementById('course-info');

            if (selectedOption.value) {
                document.getElementById('selected-course-code').textContent = selectedOption.getAttribute('data-course-code');
                document.getElementById('selected-course-title').textContent = selectedOption.getAttribute('data-course-title');
                document.getElementById('selected-program-name').textContent = selectedOption.getAttribute('data-program-name');
                
                // Create program type badge
                const programType = selectedOption.getAttribute('data-program-type');
                let badgeClass = '';
                let badgeText = '';
                
                switch(programType) {
                    case 'online':
                        badgeClass = 'badge-online';
                        badgeText = 'Online Program';
                        break;
                    case 'onsite':
                        badgeClass = 'badge-onsite';
                        badgeText = 'Onsite Program';
                        break;
                    case 'school':
                        badgeClass = 'badge-school';
                        badgeText = 'School-Based Program';
                        break;
                    default:
                        badgeClass = '';
                        badgeText = programType.charAt(0).toUpperCase() + programType.slice(1) + ' Program';
                }
                
                const badgeHtml = `<span class="program-type-badge ${badgeClass}">${badgeText}</span>`;
                document.getElementById('selected-program-type-badge').innerHTML = badgeHtml;
                
                courseInfo.style.display = 'block';

                // Auto-select program type based on course
                if (programType === 'onsite' || programType === 'school' || programType === 'online') {
                    document.getElementById(`program_type_${programType}`).checked = true;
                }
                filterAcademicPeriods();
            } else {
                courseInfo.style.display = 'none';
            }
        }

        // Update academic period information when period is selected
        function updatePeriodInfo() {
            const periodSelect = document.getElementById('academic_period_id');
            const selectedOption = periodSelect.options[periodSelect.selectedIndex];
            const periodInfo = document.getElementById('period-info');

            if (selectedOption.value) {
                const periodName = selectedOption.getAttribute('data-period-name');
                const periodType = selectedOption.getAttribute('data-period-type');
                const periodNumber = selectedOption.getAttribute('data-period-number');
                const academicYear = selectedOption.getAttribute('data-academic-year');
                const startDate = new Date(selectedOption.getAttribute('data-start-date'));
                const endDate = new Date(selectedOption.getAttribute('data-end-date'));
                const durationWeeks = selectedOption.getAttribute('data-duration-weeks');
                const periodStatus = selectedOption.getAttribute('data-status');

                document.getElementById('selected-period-name').textContent = periodName;
                document.getElementById('selected-start-date').textContent = startDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                document.getElementById('selected-end-date').textContent = endDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                document.getElementById('selected-duration').textContent = durationWeeks;
                document.getElementById('selected-academic-year').textContent = academicYear;

                // Display period status with badge
                const statusBadge = periodStatus === 'active' ?
                    '<span class="status-badge status-active">Active</span>' :
                    '<span class="status-badge status-upcoming">Upcoming</span>';
                document.getElementById('selected-period-status').innerHTML = statusBadge;

                // Calculate class status based on period status
                const classStatus = periodStatus === 'active' ? 'Ongoing' : 'Scheduled';
                const classStatusBadge = periodStatus === 'active' ?
                    '<span class="status-badge status-active">Ongoing</span>' :
                    '<span class="status-badge status-upcoming">Scheduled</span>';
                document.getElementById('calculated-class-status').innerHTML = classStatusBadge;

                periodInfo.style.display = 'block';
            } else {
                periodInfo.style.display = 'none';
            }
        }

        // Filter academic periods based on selected program type
        function filterAcademicPeriods() {
            const programTypeOnline = document.getElementById('program_type_online');
            const programTypeOnsite = document.getElementById('program_type_onsite');
            const programTypeSchool = document.getElementById('program_type_school');
            const periodSelect = document.getElementById('academic_period_id');
            const selectedValue = periodSelect.value;

            let selectedProgramType = '';
            if (programTypeOnline.checked) {
                selectedProgramType = 'online';
            } else if (programTypeOnsite.checked) {
                selectedProgramType = 'onsite';
            } else if (programTypeSchool.checked) {
                selectedProgramType = 'school';
            }

            // Show/hide option groups based on program type
            for (let i = 0; i < periodSelect.options.length; i++) {
                const option = periodSelect.options[i];
                const optgroup = option.parentElement;

                if (optgroup.tagName === 'OPTGROUP') {
                    const optgroupProgramType = optgroup.getAttribute('data-program-type');
                    if (optgroupProgramType === selectedProgramType || selectedProgramType === '') {
                        optgroup.style.display = '';
                    } else {
                        optgroup.style.display = 'none';
                        if (option.value === selectedValue) {
                            option.selected = false;
                        }
                    }
                }
            }

            // Re-evaluate selected option
            updatePeriodInfo();
        }

        // Character counter for description
        const descriptionField = document.getElementById('description');
        const descriptionCount = document.getElementById('description_count');

        if (descriptionField && descriptionCount) {
            descriptionField.addEventListener('input', function() {
                const length = this.value.length;
                descriptionCount.textContent = length;

                if (length > 500) {
                    descriptionCount.style.color = '#ef4444';
                    this.classList.add('invalid');
                } else {
                    descriptionCount.style.color = '';
                    this.classList.remove('invalid');
                }
            });

            // Initialize count
            descriptionCount.textContent = descriptionField.value.length;
        }

        // Form validation
        document.getElementById('classForm').addEventListener('submit', function(e) {
            let isValid = true;

            // Clear previous error messages
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            document.querySelectorAll('.form-control').forEach(el => el.classList.remove('invalid'));

            // Check required fields
            const requiredFields = this.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('invalid');
                    showError(field, 'This field is required');
                    isValid = false;
                }
            });

            // Validate batch code format
            const batchCodeField = document.getElementById('batch_code');
            if (batchCodeField.value && !/^[A-Za-z0-9\-]+$/.test(batchCodeField.value)) {
                batchCodeField.classList.add('invalid');
                showError(batchCodeField, 'Only letters, numbers, and hyphens allowed');
                isValid = false;
            }

            // Validate max students
            const maxStudents = document.getElementById('max_students').value;
            if (maxStudents < 1 || maxStudents > 100) {
                document.getElementById('max_students').classList.add('invalid');
                showError(document.getElementById('max_students'), 'Maximum students must be between 1 and 100');
                isValid = false;
            }

            // Validate meeting link (if provided)
            const meetingLink = document.getElementById('meeting_link').value;
            if (meetingLink && !/^https?:\/\/.+\..+/.test(meetingLink)) {
                document.getElementById('meeting_link').classList.add('invalid');
                showError(document.getElementById('meeting_link'), 'Please enter a valid URL starting with http:// or https://');
                isValid = false;
            }

            // Validate program type selection
            const programTypeSelected = document.querySelector('input[name="program_type"]:checked');
            if (!programTypeSelected) {
                showError(document.querySelector('.radio-group'), 'Please select a program type');
                isValid = false;
            }

            // Validate academic period selection
            const academicPeriod = document.getElementById('academic_period_id').value;
            if (!academicPeriod) {
                document.getElementById('academic_period_id').classList.add('invalid');
                showError(document.getElementById('academic_period_id'), 'Please select an academic period');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();

                // Scroll to first error
                const firstError = this.querySelector('.invalid');
                if (firstError) {
                    firstError.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    firstError.focus();
                }

                // Show error alert
                if (!document.querySelector('.alert-error')) {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-error';
                    alertDiv.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Please fix the errors in the form before submitting.</strong>
                    `;
                    this.parentNode.insertBefore(alertDiv, this);
                }
            }
        });

        function showError(element, message) {
            let errorDiv = element.nextElementSibling;
            while (errorDiv && errorDiv.classList && errorDiv.classList.contains('error-message')) {
                errorDiv = errorDiv.nextElementSibling;
            }

            if (!errorDiv || !errorDiv.classList.contains('error-message')) {
                errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                element.parentNode.appendChild(errorDiv);
            }
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCourseInfo();
            updatePeriodInfo();
            filterAcademicPeriods();

            // Auto-generate batch code if creating new class
            <?php if (!$edit_mode): ?>
                document.getElementById('course_id').addEventListener('change', function() {
                    const batchCodeField = document.getElementById('batch_code');
                    if (!batchCodeField.value) {
                        const selectedOption = this.options[this.selectedIndex];
                        if (selectedOption.value) {
                            const courseCode = selectedOption.getAttribute('data-course-code');
                            const year = new Date().getFullYear();
                            const randomNum = Math.floor(Math.random() * 90) + 10; // 10-99

                            // Get program type
                            const programType = selectedOption.getAttribute('data-program-type');
                            let periodType = 'B';
                            if (programType === 'onsite' || programType === 'school') {
                                periodType = 'T';
                            }

                            batchCodeField.value = `${courseCode}-${year}-${periodType}${randomNum}`;
                        }
                    }
                });

                // Auto-generate class name if creating new class
                document.getElementById('course_id').addEventListener('change', function() {
                    const nameField = document.getElementById('name');
                    if (!nameField.value) {
                        const selectedOption = this.options[this.selectedIndex];
                        if (selectedOption.value) {
                            const courseTitle = selectedOption.getAttribute('data-course-title');
                            const programType = selectedOption.getAttribute('data-program-type');

                            // Get academic year for naming
                            const periodSelect = document.getElementById('academic_period_id');
                            let academicYear = new Date().getFullYear();
                            if (periodSelect.value) {
                                const selectedPeriod = periodSelect.options[periodSelect.selectedIndex];
                                academicYear = selectedPeriod.getAttribute('data-academic-year').split('/')[0];
                            }

                            let programTypeText = '';
                            switch(programType) {
                                case 'online':
                                    programTypeText = 'Online';
                                    break;
                                case 'onsite':
                                    programTypeText = 'Onsite';
                                    break;
                                case 'school':
                                    programTypeText = 'School-Based';
                                    break;
                            }

                            nameField.value = `${courseTitle} - ${programTypeText} ${academicYear}`;
                        }
                    }
                });

                // Update class name when academic period changes
                document.getElementById('academic_period_id').addEventListener('change', function() {
                    const nameField = document.getElementById('name');
                    const courseSelect = document.getElementById('course_id');
                    const selectedCourse = courseSelect.options[courseSelect.selectedIndex];

                    if (selectedCourse.value && this.value) {
                        const courseTitle = selectedCourse.getAttribute('data-course-title');
                        const selectedPeriod = this.options[this.selectedIndex];
                        const periodName = selectedPeriod.getAttribute('data-period-name');
                        const periodType = selectedPeriod.getAttribute('data-period-type');
                        const academicYear = selectedPeriod.getAttribute('data-academic-year');
                        const programType = selectedPeriod.getAttribute('data-program-type');

                        let programTypeText = '';
                        switch(programType) {
                            case 'online':
                                programTypeText = 'Online';
                                break;
                            case 'onsite':
                                programTypeText = 'Onsite';
                                break;
                            case 'school':
                                programTypeText = 'School-Based';
                                break;
                        }

                        nameField.value = `${courseTitle} - ${periodName} (${programTypeText} ${periodType === 'term' ? 'Term' : 'Block'}) ${academicYear}`;
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>