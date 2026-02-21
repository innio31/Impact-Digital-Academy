<?php
// modules/admin/academic/classes/edit.php

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

// Get class ID from URL
$class_id = $_GET['id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class data with comprehensive information
$sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name, 
               p.program_code, p.program_type, u.first_name as instructor_first_name,
               u.last_name as instructor_last_name, u.email as instructor_email,
               COUNT(DISTINCT e.id) as total_enrollments,
               ap.id as academic_period_id, ap.period_name, ap.period_type,
               ap.start_date as period_start_date, ap.end_date as period_end_date,
               ap.academic_year, ap.status as period_status
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN users u ON cb.instructor_id = u.id
        LEFT JOIN enrollments e ON cb.id = e.class_id
        LEFT JOIN academic_periods ap ON (
            (cb.program_type = 'onsite' AND cb.term_number = ap.period_number AND ap.period_type = 'term') OR
            (cb.program_type = 'online' AND cb.block_number = ap.period_number AND ap.period_type = 'block')
        ) AND ap.program_type = cb.program_type AND ap.academic_year = cb.academic_year
        WHERE cb.id = ?
        GROUP BY cb.id";

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

// Check if class can be edited
$can_edit = true;
$edit_restrictions = [];

if ($class_data['status'] === 'completed') {
    $can_edit = false;
    $edit_restrictions[] = 'Completed classes cannot be edited.';
}

if ($class_data['status'] === 'cancelled') {
    $can_edit = false;
    $edit_restrictions[] = 'Cancelled classes cannot be edited.';
}

// Get courses for dropdown (only active ones)
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

// Get academic periods for dropdown (including completed for reference)
$academic_periods_sql = "SELECT * FROM academic_periods 
                         ORDER BY program_type, academic_year DESC, period_number";
$academic_periods_result = $conn->query($academic_periods_sql);
$academic_periods = $academic_periods_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
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
            'program_type' => trim($_POST['program_type'] ?? $class_data['program_type']),
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

        // Validate batch code uniqueness (except for this class)
        if (!empty($form_data['batch_code'])) {
            $check_sql = "SELECT id FROM class_batches WHERE batch_code = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('si', $form_data['batch_code'], $class_id);
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

        // Check if reducing max_students below current enrollment
        if ($form_data['max_students'] < $class_data['total_enrollments']) {
            $errors[] = "Cannot reduce maximum students below current enrollment ({$class_data['total_enrollments']} students).";
        }

        // Validate meeting link (if provided)
        if (!empty($form_data['meeting_link']) && !filter_var($form_data['meeting_link'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid meeting link URL.';
        }

        // Validate academic period
        if ($academic_period) {
            // Check if changing academic period when class has enrollments
            if ($academic_period['id'] != $class_data['academic_period_id'] && $class_data['total_enrollments'] > 0) {
                $errors[] = 'Cannot change academic period when class has enrolled students.';
            } else {
                $form_data['start_date'] = $academic_period['start_date'];
                $form_data['end_date'] = $academic_period['end_date'];
                $form_data['duration_weeks'] = $academic_period['duration_weeks'];

                // Set term/block based on program type
                if ($academic_period['program_type'] === 'onsite' && $academic_period['period_type'] === 'term') {
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

                // Set status based on academic period and class current status
                if ($class_data['status'] === 'completed' || $class_data['status'] === 'cancelled') {
                    $form_data['status'] = $class_data['status'];
                } else {
                    $form_data['status'] = $academic_period['status'] === 'active' ? 'ongoing' : 'scheduled';
                }
            }
        }

        // If no errors, process update
        if (empty($errors)) {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Update class
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
                    'sssiiissssssissssi',
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

                // Log academic period change if it changed
                if ($class_data['academic_period_id'] != $form_data['academic_period_id']) {
                    $old_period_name = $class_data['period_name'] ?? 'Not set';
                    $new_period_name = $academic_period['period_name'] ?? 'Not set';

                    logActivity(
                        $_SESSION['user_id'],
                        'class_academic_period_change',
                        "Changed academic period from {$old_period_name} to {$new_period_name}",
                        'class_batches',
                        $class_id
                    );
                }

                // Log status change if it changed
                if ($class_data['status'] !== $form_data['status']) {
                    logActivity(
                        $_SESSION['user_id'],
                        'class_status_change',
                        "Changed class status from {$class_data['status']} to {$form_data['status']}",
                        'class_batches',
                        $class_id
                    );

                    // If changing to completed, log completion
                    if ($form_data['status'] === 'completed') {
                        logActivity(
                            $_SESSION['user_id'],
                            'class_completed',
                            "Marked class as completed",
                            'class_batches',
                            $class_id
                        );
                    }
                }

                // Log instructor change if it changed
                if ($class_data['instructor_id'] != $form_data['instructor_id']) {
                    $old_instructor = $class_data['instructor_first_name'] . ' ' . $class_data['instructor_last_name'];

                    if ($form_data['instructor_id']) {
                        $new_instructor_sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?";
                        $new_stmt = $conn->prepare($new_instructor_sql);
                        $new_stmt->bind_param('i', $form_data['instructor_id']);
                        $new_stmt->execute();
                        $new_result = $new_stmt->get_result();
                        $new_instructor = $new_result->fetch_assoc()['name'] ?? 'Not assigned';
                    } else {
                        $new_instructor = 'Not assigned';
                    }

                    logActivity(
                        $_SESSION['user_id'],
                        'class_instructor_change',
                        "Changed instructor from {$old_instructor} to {$new_instructor}",
                        'class_batches',
                        $class_id
                    );
                }

                $conn->commit();
                $success = true;

                // Log the update
                logActivity(
                    $_SESSION['user_id'],
                    'class_update',
                    "Updated class: {$form_data['batch_code']} - {$form_data['name']}",
                    'class_batches',
                    $class_id
                );

                // Update class data for display
                $class_data = array_merge($class_data, $form_data);
                $class_data['academic_period_id'] = $form_data['academic_period_id'];
                $class_data['period_status'] = $academic_period['status'] ?? $class_data['period_status'];

                // Set success message
                $_SESSION['success'] = "Class successfully updated.";

                // Redirect to view page
                header('Location: view.php?id=' . $class_id);
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Failed to update class: ' . $e->getMessage();
            }
        }
    }
}

// Log activity
logActivity($_SESSION['user_id'], "class_edit_access", "Accessed edit class form for class #$class_id");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Class: <?php echo htmlspecialchars($class_data['batch_code']); ?> - Admin Dashboard</title>
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

        .class-header-info {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .class-header-info h3 {
            color: var(--dark);
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .class-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            margin-top: 1rem;
        }

        .meta-item {
            min-width: 200px;
        }

        .meta-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 1rem;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .form-control:disabled {
            background-color: #f8fafc;
            cursor: not-allowed;
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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
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

        .character-count {
            text-align: right;
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .date-display {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .date-item {
            flex: 1;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .status-scheduled {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-ongoing {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .warning-box {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #92400e;
        }

        .warning-box h4 {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

            .date-display {
                flex-direction: column;
            }

            .class-meta {
                flex-direction: column;
                gap: 1rem;
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
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>">
                            <?php echo htmlspecialchars($class_data['batch_code']); ?>
                        </a> &rsaquo;
                        Edit
                    </div>
                    <h1>Edit Class: <?php echo htmlspecialchars($class_data['batch_code'] . ' - ' . $class_data['name']); ?></h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Class
                    </a>
                </div>
            </div>

            <!-- Class Information Header -->
            <div class="class-header-info">
                <h3>Class Information</h3>
                <div class="class-meta">
                    <div class="meta-item">
                        <div class="meta-label">Current Status</div>
                        <div class="meta-value">
                            <?php echo ucfirst($class_data['status']); ?>
                            <span class="status-badge status-<?php echo $class_data['status']; ?>">
                                <?php echo ucfirst($class_data['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Total Enrollments</div>
                        <div class="meta-value"><?php echo $class_data['total_enrollments']; ?> students</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Instructor</div>
                        <div class="meta-value">
                            <?php if ($class_data['instructor_first_name']): ?>
                                <?php echo htmlspecialchars($class_data['instructor_first_name'] . ' ' . $class_data['instructor_last_name']); ?>
                            <?php else: ?>
                                <span style="color: #64748b; font-style: italic;">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Academic Period</div>
                        <div class="meta-value">
                            <?php echo htmlspecialchars($class_data['period_name'] ?? 'Not set'); ?>
                            (<?php echo $class_data['program_type'] === 'onsite' ? 'Term' : 'Block'; ?>)
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Dates</div>
                        <div class="meta-value">
                            <?php echo date('M j, Y', strtotime($class_data['start_date'])); ?> -
                            <?php echo date('M j, Y', strtotime($class_data['end_date'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Restrictions Warning -->
            <?php if (!$can_edit): ?>
                <div class="warning-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> Editing Restricted</h4>
                    <p>This class cannot be edited because:</p>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <?php foreach ($edit_restrictions as $restriction): ?>
                            <li><?php echo $restriction; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top: 0.5rem;">
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-eye"></i> View Class Details
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Class successfully updated!
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
            <?php if ($can_edit): ?>
                <div class="form-container">
                    <div class="form-header">
                        <h2><i class="fas fa-edit"></i> Edit Class Details</h2>
                        <div>
                            <span class="form-help">
                                Last updated: <?php echo date('M j, Y g:i A', strtotime($class_data['updated_at'])); ?>
                            </span>
                        </div>
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
                                            value="<?php echo htmlspecialchars($form_data['batch_code'] ?? $class_data['batch_code']); ?>"
                                            required
                                            placeholder="e.g., DM101-2024-B1"
                                            pattern="[A-Za-z0-9\-]+"
                                            title="Only letters, numbers, and hyphens allowed">
                                        <div class="form-help">Unique identifier for this class batch</div>
                                    </div>

                                    <div class="form-group">
                                        <label for="name" class="required">Class Name</label>
                                        <input type="text" id="name" name="name" class="form-control"
                                            value="<?php echo htmlspecialchars($form_data['name'] ?? $class_data['name']); ?>"
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

                            <!-- Course Information -->
                            <div class="form-section">
                                <h3><i class="fas fa-graduation-cap"></i> Course Information</h3>
                                <div class="course-info">
                                    <h4><?php echo htmlspecialchars($class_data['course_code'] . ' - ' . $class_data['course_title']); ?></h4>
                                    <p><strong>Program:</strong> <?php echo htmlspecialchars($class_data['program_name']); ?> (<?php echo $class_data['program_type'] === 'onsite' ? 'Onsite' : 'Online'; ?>)</p>
                                    <p class="form-help">Warning: Changing course after enrollment may affect student progress.</p>
                                </div>

                                <div class="form-group">
                                    <label for="course_id" class="required">Select Course</label>
                                    <select id="course_id" name="course_id" class="form-control" required>
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
                                                        <?php echo ($form_data['course_id'] ?? $class_data['course_id']) == $course['id'] ? 'selected' : ''; ?>
                                                        data-program-type="<?php echo $course['program_type']; ?>"
                                                        data-course-code="<?php echo $course['course_code']; ?>"
                                                        data-course-title="<?php echo htmlspecialchars($course['title']); ?>"
                                                        data-program-name="<?php echo htmlspecialchars($course['program_name']); ?>">
                                                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['title']); ?>
                                                        (<?php echo $course['program_type'] === 'onsite' ? 'Onsite' : 'Online'; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-help">
                                        <?php if ($class_data['total_enrollments'] > 0): ?>
                                            <span style="color: var(--danger);">Warning: Changing course with enrolled students may affect their progress.</span>
                                        <?php endif; ?>
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
                                                <?php echo ($form_data['program_type'] ?? $class_data['program_type']) === 'online' ? 'checked' : ''; ?>
                                                onchange="filterAcademicPeriods()"
                                                <?php echo $class_data['total_enrollments'] > 0 ? 'disabled' : ''; ?>>
                                            <label for="program_type_online">Online Program</label>
                                        </div>
                                        <div class="radio-option">
                                            <input type="radio" id="program_type_onsite" name="program_type"
                                                value="onsite"
                                                <?php echo ($form_data['program_type'] ?? $class_data['program_type']) === 'onsite' ? 'checked' : ''; ?>
                                                onchange="filterAcademicPeriods()"
                                                <?php echo $class_data['total_enrollments'] > 0 ? 'disabled' : ''; ?>>
                                            <label for="program_type_onsite">Onsite Program</label>
                                        </div>
                                    </div>
                                    <div class="form-help">
                                        <?php if ($class_data['total_enrollments'] > 0): ?>
                                            <span style="color: var(--danger);">Cannot change program type when class has enrolled students.</span>
                                        <?php else: ?>
                                            Select whether this is an online or onsite class
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Academic Period Selection -->
                            <div class="form-section">
                                <h3><i class="fas fa-calendar-alt"></i> Academic Period</h3>
                                <div class="form-group">
                                    <label for="academic_period_id" class="required">Select Block/Term</label>
                                    <select id="academic_period_id" name="academic_period_id" class="form-control" required
                                        onchange="updatePeriodInfo()"
                                        <?php echo $class_data['total_enrollments'] > 0 ? 'disabled' : ''; ?>>
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
                                            <optgroup label="<?php echo ($program_type === 'onsite' ? 'Onsite' : 'Online') . ' - ' . $academic_year; ?>"
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
                                                        <?php if ($period['status'] === 'completed'): ?>
                                                            [Completed]
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-help">
                                        <?php if ($class_data['total_enrollments'] > 0): ?>
                                            <span style="color: var(--danger);">Cannot change academic period when class has enrolled students.</span>
                                        <?php else: ?>
                                            Select the academic period (block for online, term for onsite) for this class
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div id="period-info" style="<?php echo $class_data['academic_period_id'] ? 'display: block;' : 'display: none;'; ?>">
                                    <div class="period-info">
                                        <h4 id="selected-period-name"><?php echo htmlspecialchars($class_data['period_name'] ?? 'Not set'); ?></h4>
                                        <div class="date-display">
                                            <div class="date-item">
                                                <p><strong>Start Date:</strong> <span id="selected-start-date"><?php echo date('M j, Y', strtotime($class_data['start_date'])); ?></span></p>
                                            </div>
                                            <div class="date-item">
                                                <p><strong>End Date:</strong> <span id="selected-end-date"><?php echo date('M j, Y', strtotime($class_data['end_date'])); ?></span></p>
                                            </div>
                                            <div class="date-item">
                                                <p><strong>Duration:</strong> <span id="selected-duration">
                                                        <?php
                                                        $start = new DateTime($class_data['start_date']);
                                                        $end = new DateTime($class_data['end_date']);
                                                        $interval = $start->diff($end);
                                                        echo ceil($interval->days / 7);
                                                        ?>
                                                    </span> weeks</p>
                                            </div>
                                        </div>
                                        <p><strong>Academic Year:</strong> <span id="selected-academic-year"><?php echo $class_data['academic_year']; ?></span></p>
                                        <p><strong>Period Status:</strong> <span id="selected-period-status">
                                                <?php if ($class_data['period_status']): ?>
                                                    <span class="status-badge status-<?php echo $class_data['period_status']; ?>">
                                                        <?php echo ucfirst($class_data['period_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span></p>
                                        <p><strong>Class Status:</strong> <span id="calculated-class-status">
                                                <span class="status-badge status-<?php echo $class_data['status']; ?>">
                                                    <?php echo ucfirst($class_data['status']); ?>
                                                </span>
                                            </span></p>
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
                                    <div class="form-help">
                                        <?php if ($class_data['instructor_id']): ?>
                                            Current instructor: <?php echo htmlspecialchars($class_data['instructor_first_name'] . ' ' . $class_data['instructor_last_name']); ?>
                                            (<?php echo htmlspecialchars($class_data['instructor_email']); ?>)
                                        <?php else: ?>
                                            No instructor currently assigned
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Schedule & Capacity -->
                            <div class="form-section">
                                <h3><i class="fas fa-calendar-alt"></i> Schedule & Capacity</h3>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="max_students" class="required">Maximum Students</label>
                                        <input type="number" id="max_students" name="max_students" class="form-control"
                                            value="<?php echo htmlspecialchars($form_data['max_students'] ?? $class_data['max_students']); ?>"
                                            required min="<?php echo $class_data['total_enrollments']; ?>" max="100"
                                            placeholder="30">
                                        <div class="form-help">
                                            Current enrollment: <?php echo $class_data['total_enrollments']; ?> students
                                            (Minimum: <?php echo $class_data['total_enrollments']; ?>)
                                        </div>
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
                                        <div class="form-help">
                                            <?php if ($class_data['program_type'] === 'online'): ?>
                                                Required for online classes
                                            <?php else: ?>
                                                For online classes only. Leave blank for onsite classes.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Class Status (for completed/cancelled classes) -->
                            <?php if ($class_data['status'] === 'completed' || $class_data['status'] === 'cancelled'): ?>
                                <div class="form-section">
                                    <h3><i class="fas fa-exclamation-circle"></i> Class Status</h3>
                                    <div class="form-group">
                                        <label for="status">Current Status</label>
                                        <select id="status" name="status" class="form-control" disabled>
                                            <option value="scheduled" <?php echo $class_data['status'] === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                            <option value="ongoing" <?php echo $class_data['status'] === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                            <option value="completed" <?php echo $class_data['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $class_data['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <div class="form-help">
                                            Status cannot be changed for <?php echo $class_data['status']; ?> classes.
                                            <input type="hidden" name="status" value="<?php echo $class_data['status']; ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Danger Zone -->
                            <div class="form-section">
                                <h3 style="color: var(--danger); border-color: var(--danger);">
                                    <i class="fas fa-exclamation-triangle"></i> Danger Zone
                                </h3>
                                <div class="warning-box">
                                    <h4><i class="fas fa-exclamation-circle"></i> Critical Actions</h4>
                                    <p>These actions may have significant impacts on the class and enrolled students:</p>

                                    <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                                        <?php if ($class_data['status'] !== 'cancelled'): ?>
                                            <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/cancel.php?id=<?php echo $class_id; ?>"
                                                class="btn btn-danger"
                                                onclick="return confirm('Are you sure you want to cancel this class? This will notify all enrolled students and cannot be undone.')">
                                                <i class="fas fa-times-circle"></i> Cancel Class
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($class_data['status'] !== 'completed'): ?>
                                            <button type="button" class="btn btn-warning"
                                                onclick="if(confirm('Mark this class as completed? This will finalize grades and close enrollment.')) { 
                                                    document.querySelector('input[name=\'status\']').value = 'completed';
                                                    document.getElementById('classForm').submit(); 
                                                }">
                                                <i class="fas fa-check-circle"></i> Mark as Completed
                                            </button>
                                        <?php endif; ?>

                                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/duplicate.php?id=<?php echo $class_id; ?>"
                                            class="btn btn-secondary">
                                            <i class="fas fa-copy"></i> Duplicate Class
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <div>
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>"
                                    class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div style="display: flex; gap: 1rem;">
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset Changes
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Update course information when course is selected
        function updateCourseInfo() {
            const courseSelect = document.getElementById('course_id');
            const selectedOption = courseSelect.options[courseSelect.selectedIndex];

            if (selectedOption.value) {
                const programType = selectedOption.getAttribute('data-program-type');

                // Update program type if no enrollments
                const totalEnrollments = <?php echo $class_data['total_enrollments']; ?>;
                if (totalEnrollments === 0) {
                    if (programType === 'onsite') {
                        document.getElementById('program_type_onsite').checked = true;
                    } else {
                        document.getElementById('program_type_online').checked = true;
                    }
                    filterAcademicPeriods();
                }
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
                let statusBadge = '';
                if (periodStatus === 'active') {
                    statusBadge = '<span class="status-badge status-active">Active</span>';
                } else if (periodStatus === 'upcoming') {
                    statusBadge = '<span class="status-badge status-upcoming">Upcoming</span>';
                } else if (periodStatus === 'completed') {
                    statusBadge = '<span class="status-badge status-completed">Completed</span>';
                } else {
                    statusBadge = periodStatus;
                }
                document.getElementById('selected-period-status').innerHTML = statusBadge;

                // Calculate class status based on period status
                let classStatus = '';
                let classStatusBadge = '';
                if (periodStatus === 'active') {
                    classStatus = 'Ongoing';
                    classStatusBadge = '<span class="status-badge status-active">Ongoing</span>';
                } else if (periodStatus === 'upcoming') {
                    classStatus = 'Scheduled';
                    classStatusBadge = '<span class="status-badge status-upcoming">Scheduled</span>';
                } else if (periodStatus === 'completed') {
                    classStatus = 'Completed';
                    classStatusBadge = '<span class="status-badge status-completed">Completed</span>';
                }
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
            const periodSelect = document.getElementById('academic_period_id');
            const selectedValue = periodSelect.value;

            let selectedProgramType = '';
            if (programTypeOnline.checked) {
                selectedProgramType = 'online';
            } else if (programTypeOnsite.checked) {
                selectedProgramType = 'onsite';
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
        document.getElementById('classForm')?.addEventListener('submit', function(e) {
            let isValid = true;

            // Clear previous error messages
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            document.querySelectorAll('.form-control').forEach(el => el.classList.remove('invalid'));

            // Check required fields
            const requiredFields = this.querySelectorAll('[required]:not(:disabled)');
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
            const currentEnrollment = <?php echo $class_data['total_enrollments']; ?>;

            if (maxStudents < currentEnrollment) {
                document.getElementById('max_students').classList.add('invalid');
                showError(document.getElementById('max_students'), `Cannot be less than current enrollment (${currentEnrollment} students)`);
                isValid = false;
            }

            if (maxStudents < 1 || maxStudents > 100) {
                document.getElementById('max_students').classList.add('invalid');
                showError(document.getElementById('max_students'), 'Maximum students must be between 1 and 100');
                isValid = false;
            }

            // Validate meeting link (if provided and program is online)
            const meetingLink = document.getElementById('meeting_link').value;
            const programType = '<?php echo $class_data['program_type']; ?>';

            if (programType === 'online' && !meetingLink) {
                document.getElementById('meeting_link').classList.add('invalid');
                showError(document.getElementById('meeting_link'), 'Meeting link is required for online classes');
                isValid = false;
            } else if (meetingLink && !/^https?:\/\/.+\..+/.test(meetingLink)) {
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
            const totalEnrollments = <?php echo $class_data['total_enrollments']; ?>;
            const academicPeriodSelect = document.getElementById('academic_period_id');

            if (!academicPeriod && !academicPeriodSelect.disabled) {
                document.getElementById('academic_period_id').classList.add('invalid');
                showError(document.getElementById('academic_period_id'), 'Please select an academic period');
                isValid = false;
            }

            // Confirm critical changes
            const originalStatus = '<?php echo $class_data['status']; ?>';
            const newStatus = '<?php echo $class_data['status']; ?>'; // Status is determined by academic period

            if (originalStatus !== newStatus) {
                let confirmMessage = '';

                if (newStatus === 'cancelled') {
                    confirmMessage = 'Are you sure you want to cancel this class?\n\nThis will:\n• Notify all enrolled students\n• Close all enrollments\n• Prevent further activity\n\nThis action cannot be undone.';
                } else if (newStatus === 'completed') {
                    confirmMessage = 'Are you sure you want to mark this class as completed?\n\nThis will:\n• Finalize all grades\n• Close enrollment\n• Generate certificates\n• Archive the class\n\nThis action cannot be undone.';
                } else if (originalStatus === 'completed' && newStatus !== 'completed') {
                    confirmMessage = 'Warning: You are changing a completed class back to an active status.\n\nThis may:\n• Re-open enrollment\n• Allow grade changes\n• Reactivate assignments\n\nAre you sure you want to proceed?';
                }

                if (confirmMessage && !confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            }

            // Check if changing course with enrolled students
            const originalCourseId = '<?php echo $class_data['course_id']; ?>';
            const newCourseId = document.getElementById('course_id').value;

            if (originalCourseId != newCourseId && totalEnrollments > 0) {
                if (!confirm('Warning: Changing course with enrolled students may affect their progress. Are you sure you want to change the course?')) {
                    e.preventDefault();
                    return false;
                }
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
            filterAcademicPeriods();

            // Add confirmation for course change
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                const originalCourseId = '<?php echo $class_data['course_id']; ?>';
                const totalEnrollments = <?php echo $class_data['total_enrollments']; ?>;

                courseSelect.addEventListener('change', function() {
                    if (this.value !== originalCourseId && totalEnrollments > 0) {
                        if (!confirm('Warning: Changing course with enrolled students may affect their progress. Are you sure you want to change the course?')) {
                            this.value = originalCourseId;
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>