<?php
// modules/admin/academic/programs/edit.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Get program ID from URL
$program_id = $_GET['id'] ?? 0;

if (!$program_id) {
    $_SESSION['error'] = "No program specified";
    header("Location: index.php");
    exit();
}

// Get program data
$program = getProgram($program_id);

if (!$program) {
    $_SESSION['error'] = "Program not found";
    header("Location: index.php");
    exit();
}

// Add this additional check to verify the program exists in the database
$check_program_sql = "SELECT id FROM programs WHERE id = ?";
$check_program_stmt = $conn->prepare($check_program_sql);
$check_program_stmt->bind_param("i", $program_id);
$check_program_stmt->execute();
$check_program_result = $check_program_stmt->get_result();

if ($check_program_result->num_rows === 0) {
    $_SESSION['error'] = "Program ID $program_id does not exist in the database";
    header("Location: index.php");
    exit();
}

// Get schools for dropdown
$schools = [];
$schools_sql = "SELECT id, name, short_name FROM schools WHERE partnership_status = 'active' ORDER BY name";
$schools_result = $conn->query($schools_sql);
if ($schools_result) {
    $schools = $schools_result->fetch_all(MYSQLI_ASSOC);
}

// Get all courses for this program (for requirements tab)
$all_courses = [];
$courses_sql = "SELECT * FROM courses WHERE program_id = ? ORDER BY order_number, title";
$courses_stmt = $conn->prepare($courses_sql);
$courses_stmt->bind_param("i", $program_id);
$courses_stmt->execute();
$courses_result = $courses_stmt->get_result();
while ($course = $courses_result->fetch_assoc()) {
    $all_courses[$course['id']] = $course;
}

// Get current program requirements
$core_courses = [];
$elective_courses = [];
$requirements_sql = "SELECT * FROM program_requirements WHERE program_id = ?";
$requirements_stmt = $conn->prepare($requirements_sql);
$requirements_stmt->bind_param("i", $program_id);
$requirements_stmt->execute();
$requirements_result = $requirements_stmt->get_result();
while ($req = $requirements_result->fetch_assoc()) {
    if ($req['course_type'] == 'core') {
        $core_courses[] = $req['course_id'];
    } else {
        $elective_courses[] = $req['course_id'];
    }
}

// Initialize variables
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a requirements update
    if (isset($_POST['action']) && $_POST['action'] === 'update_requirements') {
        // Handle requirements update
        $new_core_courses = $_POST['core_courses'] ?? [];
        $new_elective_courses = $_POST['elective_courses'] ?? [];
        $min_electives = (int)($_POST['min_electives'] ?? 0);
        $max_electives = (int)($_POST['max_electives'] ?? 0);
        $total_credits = (int)($_POST['total_credits'] ?? 0);
        $graduation_requirements = trim($_POST['graduation_requirements'] ?? '');
        $min_grade_required = $_POST['min_grade_required'] ?? 'C';

        // Start transaction
        $conn->begin_transaction();

        try {
            // First, verify the program exists
            $verify_sql = "SELECT id FROM programs WHERE id = ? AND status != 'deleted'";
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->bind_param("i", $program_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();

            if ($verify_result->num_rows === 0) {
                throw new Exception("Program not found or has been deleted. Cannot update requirements.");
            }

            // Delete existing requirements
            $delete_sql = "DELETE FROM program_requirements WHERE program_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $program_id);
            $delete_stmt->execute();

            // Insert core courses
            foreach ($new_core_courses as $course_id) {
                $course_id = (int)$course_id;
                if ($course_id > 0 && isset($all_courses[$course_id])) {
                    $insert_sql = "INSERT INTO program_requirements (program_id, course_id, course_type, is_required, min_grade, created_at) 
                           VALUES (?, ?, 'core', 1, 'C', NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ii", $program_id, $course_id);
                    $insert_stmt->execute();
                }
            }

            // Insert elective courses
            foreach ($new_elective_courses as $course_id) {
                $course_id = (int)$course_id;
                if ($course_id > 0 && isset($all_courses[$course_id])) {
                    $insert_sql = "INSERT INTO program_requirements (program_id, course_id, course_type, is_required, min_grade, created_at) 
                           VALUES (?, ?, 'elective', 0, 'C', NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ii", $program_id, $course_id);
                    $insert_stmt->execute();
                }
            }

            // Update program requirements metadata
            $update_meta_sql = "INSERT INTO program_requirements_meta (program_id, min_electives, max_electives, total_credits, min_grade_required, graduation_requirements, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        min_electives = VALUES(min_electives),
                        max_electives = VALUES(max_electives),
                        total_credits = VALUES(total_credits),
                        min_grade_required = VALUES(min_grade_required),
                        graduation_requirements = VALUES(graduation_requirements),
                        updated_at = NOW()";
            $update_meta_stmt = $conn->prepare($update_meta_sql);
            $update_meta_stmt->bind_param("iiisss", $program_id, $min_electives, $max_electives, $total_credits, $min_grade_required, $graduation_requirements);
            $update_meta_stmt->execute();

            $conn->commit();

            // Update local arrays for display
            $core_courses = $new_core_courses;
            $elective_courses = $new_elective_courses;

            $_SESSION['success'] = "Program requirements updated successfully!";
            header("Location: edit.php?id=" . $program_id . "#requirements");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $errors['requirements'] = "Error updating requirements: " . $e->getMessage();
        }
    }
    else {
        // Original form submission logic (basic program info update)
        // Validate required fields
        $program_code = trim($_POST['program_code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $program_type = $_POST['program_type'] ?? 'online';
        $base_fee = (float)($_POST['base_fee'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $school_id = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

        // Validate program code
        if (empty($program_code)) {
            $errors['program_code'] = 'Program code is required';
        } else if ($program_code !== $program['program_code']) {
            // Check if new program code already exists
            $check_sql = "SELECT id FROM programs WHERE program_code = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("si", $program_code, $program_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors['program_code'] = 'Program code already exists';
            }
        }

        // Validate name
        if (empty($name)) {
            $errors['name'] = 'Program name is required';
        }

        // Validate fees
        if ($base_fee < 0) {
            $errors['base_fee'] = 'Base fee cannot be negative';
        }

        // If no errors, update the program
        if (empty($errors)) {
            // Gather all form data
            $description = trim($_POST['description'] ?? '');
            $duration_weeks = (int)($_POST['duration_weeks'] ?? 12);
            $registration_fee = (float)($_POST['registration_fee'] ?? 0);
            $online_fee = (float)($_POST['online_fee'] ?? $base_fee);
            $onsite_fee = (float)($_POST['onsite_fee'] ?? $base_fee * 1.2);
            $payment_plan_type = $_POST['payment_plan_type'] ?? 'full';
            $installment_count = (int)($_POST['installment_count'] ?? 1);
            $late_fee_percentage = (float)($_POST['late_fee_percentage'] ?? 5.00);
            $fee_description = trim($_POST['fee_description'] ?? '');
            $duration_mode = trim($_POST['duration_mode'] ?? '');
            $schedule_type = trim($_POST['schedule_type'] ?? '');
            $currency = 'NGN';

            // Calculate total fee based on program type
            if ($program_type === 'online') {
                $total_fee = $online_fee;
            } elseif ($program_type === 'onsite') {
                $total_fee = $onsite_fee;
            } else {
                // For school type, use base fee
                $total_fee = $base_fee;
            }

            // Start transaction
            $conn->begin_transaction();

            try {
                // Update program
                $sql = "UPDATE programs SET
                    program_code = ?,
                    name = ?,
                    description = ?,
                    duration_weeks = ?,
                    fee = ?,
                    base_fee = ?,
                    registration_fee = ?,
                    online_fee = ?,
                    onsite_fee = ?,
                    program_type = ?,
                    payment_plan_type = ?,
                    installment_count = ?,
                    late_fee_percentage = ?,
                    currency = ?,
                    fee_description = ?,
                    status = ?,
                    duration_mode = ?,
                    schedule_type = ?,
                    school_id = ?,
                    updated_by = ?,
                    updated_at = NOW()
                    WHERE id = ?";

                $stmt = $conn->prepare($sql);
                $user_id = $_SESSION['user_id'] ?? 1;

                $stmt->bind_param(
                    "sssidddddssidssssiiii",
                    $program_code,
                    $name,
                    $description,
                    $duration_weeks,
                    $total_fee,
                    $base_fee,
                    $registration_fee,
                    $online_fee,
                    $onsite_fee,
                    $program_type,
                    $payment_plan_type,
                    $installment_count,
                    $late_fee_percentage,
                    $currency,
                    $fee_description,
                    $status,
                    $duration_mode,
                    $schedule_type,
                    $school_id,
                    $user_id,
                    $program_id
                );

                if ($stmt->execute()) {
                    // Log activity
                    logActivity('program_update', "Updated program: $program_code - $name", 'programs', $program_id);

                    // If program type changed, update payment plans
                    if ($program['program_type'] !== $program_type && in_array($program_type, ['online', 'onsite'])) {
                        $update_plan_sql = "UPDATE payment_plans SET program_type = ? WHERE program_id = ?";
                        $update_plan_stmt = $conn->prepare($update_plan_sql);
                        $update_plan_stmt->bind_param("si", $program_type, $program_id);
                        $update_plan_stmt->execute();
                    }

                    $conn->commit();

                    $_SESSION['success'] = "Program updated successfully!";
                    header("Location: view.php?id=" . $program_id);
                    exit();
                } else {
                    throw new Exception("Failed to update program: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $errors['database'] = "Error updating program: " . $e->getMessage();
            }
        }

        // If there are errors, update program array with submitted values
        $program = array_merge($program, $_POST);
    }
}

// Get requirements metadata
$requirements_meta = [];
$meta_sql = "SELECT * FROM program_requirements_meta WHERE program_id = ?";
$meta_stmt = $conn->prepare($meta_sql);
$meta_stmt->bind_param("i", $program_id);
$meta_stmt->execute();
$meta_result = $meta_stmt->get_result();
if ($meta_row = $meta_result->fetch_assoc()) {
    $requirements_meta = $meta_row;
}

// Include header
$page_title = "Edit Program: " . htmlspecialchars($program['name']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Program - <?php echo htmlspecialchars($program['name']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        /* Keep all the CSS from the original edit.php - it's comprehensive and well-structured */
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --online-color: #10b981;
            --onsite-color: #8b5cf6;
            --school-color: #f59e0b;
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

        /* Breadcrumb */
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-header h1 {
            font-size: 1.8rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header h1 i {
            color: var(--primary);
        }

        /* Program Info Banner */
        .program-banner {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary);
        }

        .program-banner-info h3 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .program-banner-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .program-banner-badges {
            display: flex;
            gap: 1rem;
        }

        .program-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-type-online {
            background: rgba(16, 185, 129, 0.15);
            color: var(--online-color);
        }

        .badge-type-onsite {
            background: rgba(139, 92, 246, 0.15);
            color: var(--onsite-color);
        }

        .badge-type-school {
            background: rgba(245, 158, 11, 0.15);
            color: var(--school-color);
        }

        .badge-status-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .badge-status-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .badge-status-upcoming {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 2.5rem;
            margin-bottom: 2rem;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 2.5rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        /* Form Layout */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-label.required:after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .form-control.error {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .form-text {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--gray);
            line-height: 1.4;
        }

        .form-text.error {
            color: var(--danger);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* Program type specific styles */
        .program-type-hint {
            background: #f8f9fa;
            border-left: 4px solid var(--info);
            padding: 0.75rem;
            margin-top: 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .program-type-hint i {
            color: var(--info);
            margin-right: 0.5rem;
        }

        /* Fee Calculator */
        .fee-calculator {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--light-gray);
        }

        .fee-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .fee-item {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .fee-item.total {
            border-left-color: var(--success);
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            font-weight: 600;
        }

        .fee-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .fee-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 2rem;
            border-top: 1px solid var(--light-gray);
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.95rem;
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
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.2);
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

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
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

        .alert i {
            margin-top: 0.125rem;
        }

        /* Help Tips */
        .help-tip {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            display: flex;
            gap: 0.75rem;
        }

        .help-tip i {
            color: var(--info);
            font-size: 1.25rem;
            margin-top: 0.125rem;
        }

        .help-tip-content h4 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .help-tip-content p {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-container {
                padding: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .program-banner {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .fee-summary {
                grid-template-columns: 1fr;
            }
        }

        /* Navigation Tabs */
        .edit-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
            overflow-x: auto;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--gray);
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--multiple {
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            min-height: 120px;
            padding: 0.5rem;
        }

        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 4px;
            color: white;
            padding: 0.25rem 0.5rem;
            margin: 0.125rem;
        }

        /* Requirements Section */
        .requirements-summary {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--light-gray);
        }

        .requirements-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            border-top: 4px solid var(--primary);
        }

        .stat-card.core {
            border-top-color: var(--success);
        }

        .stat-card.elective {
            border-top-color: var(--info);
        }

        .stat-card.total {
            border-top-color: var(--warning);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .courses-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 992px) {
            .courses-container {
                grid-template-columns: 1fr;
            }
        }

        .course-category {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .course-category h4 {
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--light-gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .course-category.core h4 {
            border-bottom-color: var(--success);
        }

        .course-category.elective h4 {
            border-bottom-color: var(--info);
        }

        .course-category h4 i {
            color: inherit;
        }

        .course-list {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .course-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .course-item:hover {
            background: #f1f5f9;
            border-color: var(--light-gray);
        }

        .course-item.core {
            border-left: 4px solid var(--success);
        }

        .course-item.elective {
            border-left: 4px solid var(--info);
        }

        .course-code {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.75rem;
            min-width: 60px;
            text-align: center;
        }

        .course-code.core {
            background: var(--success);
        }

        .course-code.elective {
            background: var(--info);
        }

        .course-details {
            flex-grow: 1;
        }

        .course-title {
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .course-meta {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .no-courses {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            font-style: italic;
        }

        /* Read-only Info */
        .readonly-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--gray);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .info-value {
            color: var(--dark);
            font-weight: 500;
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
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <a href="index.php">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <a href="view.php?id=<?php echo $program_id; ?>"><?php echo htmlspecialchars($program['name']); ?></a>
            <i class="fas fa-chevron-right"></i>
            <span>Edit</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Edit Program</h1>
            <div>
                <a href="view.php?id=<?php echo $program_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Program
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Programs
                </a>
            </div>
        </div>

        <!-- Program Info Banner -->
        <div class="program-banner">
            <div class="program-banner-info">
                <h3><?php echo htmlspecialchars($program['program_code']); ?> - <?php echo htmlspecialchars($program['name']); ?></h3>
                <p>Last updated: <?php echo formatDate($program['updated_at'], 'F j, Y \a\t g:i A'); ?></p>
            </div>
            <div class="program-banner-badges">
                <div class="program-badge badge-type-<?php echo $program['program_type']; ?>">
                    <?php echo strtoupper($program['program_type']); ?>
                </div>
                <div class="program-badge badge-status-<?php echo $program['status']; ?>">
                    <?php echo ucfirst($program['status']); ?>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['database'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-database"></i>
                <?php echo htmlspecialchars($errors['database']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['requirements'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($errors['requirements']); ?>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="edit-tabs">
            <button class="tab-btn active" onclick="showTab('basic')">
                <i class="fas fa-info-circle"></i> Basic Info
            </button>
            <button class="tab-btn" onclick="showTab('structure')">
                <i class="fas fa-calendar-alt"></i> Program Structure
            </button>
            <button class="tab-btn" onclick="showTab('fees')">
                <i class="fas fa-money-bill-wave"></i> Fees & Payments
            </button>
            <button class="tab-btn" onclick="showTab('requirements')">
                <i class="fas fa-graduation-cap"></i> Program Requirements
            </button>
            <button class="tab-btn" onclick="showTab('advanced')">
                <i class="fas fa-cogs"></i> Advanced Settings
            </button>
        </div>

        <!-- Main Form Container -->
        <div class="form-container">
            <!-- Basic Information Tab -->
            <div id="basic-tab" class="tab-content active">
                <form method="POST" id="programForm">
                    <input type="hidden" name="id" value="<?php echo $program_id; ?>">

                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </h2>

                        <!-- School/Institution Field -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="school_id" class="form-label">School/Institution</label>
                                <select name="school_id" id="school_id" class="form-control">
                                    <option value="">Select School (Optional)</option>
                                    <?php foreach ($schools as $school): ?>
                                        <option value="<?php echo $school['id']; ?>" 
                                            <?php echo ($program['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($school['name']); ?>
                                            <?php if ($school['short_name']): ?> (<?php echo htmlspecialchars($school['short_name']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the school this program belongs to (leave empty for general programs)</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="program_code" class="form-label required">Program Code</label>
                                <input type="text" name="program_code" id="program_code"
                                    class="form-control <?php echo isset($errors['program_code']) ? 'error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($program['program_code']); ?>"
                                    placeholder="e.g., DM101, DS201" required>
                                <?php if (isset($errors['program_code'])): ?>
                                    <div class="form-text error"><?php echo htmlspecialchars($errors['program_code']); ?></div>
                                <?php else: ?>
                                    <div class="form-text">Unique identifier for the program</div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="name" class="form-label required">Program Name</label>
                                <input type="text" name="name" id="name"
                                    class="form-control <?php echo isset($errors['name']) ? 'error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($program['name']); ?>"
                                    placeholder="e.g., Digital Marketing Mastery" required>
                                <?php if (isset($errors['name'])): ?>
                                    <div class="form-text error"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php else: ?>
                                    <div class="form-text">Full name of the program</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="program_type" class="form-label required">Program Type</label>
                                <select name="program_type" id="program_type" class="form-control" required>
                                    <option value="online" <?php echo $program['program_type'] === 'online' ? 'selected' : ''; ?>>Online</option>
                                    <option value="onsite" <?php echo $program['program_type'] === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                                    <option value="school" <?php echo $program['program_type'] === 'school' ? 'selected' : ''; ?>>School</option>
                                </select>
                                <div class="form-text">Online: Virtual delivery | Onsite: Physical classroom | School: Partner school programs</div>
                                <div id="programTypeHint" class="program-type-hint" style="display: none;">
                                    <i class="fas fa-info-circle"></i>
                                    <span id="programTypeHintText"></span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="status" class="form-label required">Status</label>
                                <select name="status" id="status" class="form-control" required>
                                    <option value="active" <?php echo $program['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $program['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="upcoming" <?php echo $program['status'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                </select>
                                <div class="form-text">Active programs are available for enrollment</div>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control"
                                placeholder="Detailed description of the program, learning outcomes, target audience..."><?php echo htmlspecialchars($program['description']); ?></textarea>
                            <div class="form-text">This description will be visible to prospective students</div>
                        </div>
                    </div>

                    <!-- Form Actions for Basic Info -->
                    <div class="form-actions">
                        <div>
                            <a href="view.php?id=<?php echo $program_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Program Structure Tab -->
            <div id="structure-tab" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="id" value="<?php echo $program_id; ?>">

                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="fas fa-calendar-alt"></i> Program Structure
                        </h2>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="duration_weeks" class="form-label required">Duration (Weeks)</label>
                                <input type="number" name="duration_weeks" id="duration_weeks"
                                    class="form-control" min="1" max="52"
                                    value="<?php echo $program['duration_weeks']; ?>" required>
                                <div class="form-text">Total program duration in weeks</div>
                            </div>

                            <div class="form-group">
                                <label for="duration_mode" class="form-label">Duration Mode</label>
                                <select name="duration_mode" id="duration_mode" class="form-control">
                                    <option value="">Select mode</option>
                                    <option value="termly_10_weeks" <?php echo ($program['duration_mode'] ?? '') === 'termly_10_weeks' ? 'selected' : ''; ?>>Termly (10 weeks per term)</option>
                                    <option value="block_8_weeks" <?php echo ($program['duration_mode'] ?? '') === 'block_8_weeks' ? 'selected' : ''; ?>>Block-based (8 weeks per block)</option>
                                    <option value="intensive_4_weeks" <?php echo ($program['duration_mode'] ?? '') === 'intensive_4_weeks' ? 'selected' : ''; ?>>Intensive (4 weeks)</option>
                                </select>
                                <div class="form-text">How the program duration is structured</div>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="schedule_type" class="form-label">Schedule Type</label>
                            <input type="text" name="schedule_type" id="schedule_type" class="form-control"
                                value="<?php echo htmlspecialchars($program['schedule_type'] ?? ''); ?>"
                                placeholder="e.g., 'Weekdays 6-8pm', 'Weekends 10am-2pm'">
                            <div class="form-text">Typical class schedule for this program</div>
                        </div>

                        <!-- Form Actions for Program Structure -->
                        <div class="form-actions">
                            <div>
                                <a href="view.php?id=<?php echo $program_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-redo"></i> Reset Form
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Fees & Payments Tab -->
            <div id="fees-tab" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="id" value="<?php echo $program_id; ?>">

                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="fas fa-money-bill-wave"></i> Fee Structure
                        </h2>

                        <!-- Fee Calculator -->
                        <div class="fee-calculator" id="feeCalculator">
                            <h4 style="color: var(--dark); margin-bottom: 1rem;">Fee Calculator</h4>
                            <div class="fee-summary">
                                <div class="fee-item">
                                    <div class="fee-label">Base Program Fee</div>
                                    <div class="fee-value" id="baseFeeDisplay">₦0.00</div>
                                </div>
                                <div class="fee-item">
                                    <div class="fee-label">Registration Fee</div>
                                    <div class="fee-value" id="regFeeDisplay">₦0.00</div>
                                </div>
                                <div class="fee-item total">
                                    <div class="fee-label">Total Fee</div>
                                    <div class="fee-value" id="totalFeeDisplay">₦0.00</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="base_fee" class="form-label required">Base Program Fee (₦)</label>
                                <input type="number" name="base_fee" id="base_fee"
                                    class="form-control <?php echo isset($errors['base_fee']) ? 'error' : ''; ?>"
                                    value="<?php echo number_format($program['base_fee'], 2, '.', ''); ?>"
                                    step="0.01" min="0" required>
                                <?php if (isset($errors['base_fee'])): ?>
                                    <div class="form-text error"><?php echo htmlspecialchars($errors['base_fee']); ?></div>
                                <?php else: ?>
                                    <div class="form-text">Base tuition fee before registration</div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="registration_fee" class="form-label">Registration Fee (₦)</label>
                                <input type="number" name="registration_fee" id="registration_fee"
                                    class="form-control"
                                    value="<?php echo number_format($program['registration_fee'], 2, '.', ''); ?>"
                                    step="0.01" min="0">
                                <div class="form-text">One-time registration fee (optional)</div>
                            </div>
                        </div>

                        <div class="form-row" id="specificFeeGroups">
                            <!-- Online Fee (shown/hidden based on program type) -->
                            <div class="form-group" id="onlineFeeGroup">
                                <label for="online_fee" class="form-label">Online Program Fee (₦)</label>
                                <input type="number" name="online_fee" id="online_fee"
                                    class="form-control"
                                    value="<?php echo number_format($program['online_fee'], 2, '.', ''); ?>"
                                    step="0.01" min="0">
                                <div class="form-text">Specific fee for online delivery</div>
                            </div>

                            <!-- Onsite Fee (shown/hidden based on program type) -->
                            <div class="form-group" id="onsiteFeeGroup">
                                <label for="onsite_fee" class="form-label">Onsite Program Fee (₦)</label>
                                <input type="number" name="onsite_fee" id="onsite_fee"
                                    class="form-control"
                                    value="<?php echo number_format($program['onsite_fee'], 2, '.', ''); ?>"
                                    step="0.01" min="0">
                                <div class="form-text">Specific fee for onsite delivery</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="payment_plan_type" class="form-label">Payment Plan Type</label>
                                <select name="payment_plan_type" id="payment_plan_type" class="form-control">
                                    <option value="full" <?php echo ($program['payment_plan_type'] ?? 'full') === 'full' ? 'selected' : ''; ?>>Full Payment</option>
                                    <option value="installment" <?php echo ($program['payment_plan_type'] ?? '') === 'installment' ? 'selected' : ''; ?>>Installments</option>
                                    <option value="block" <?php echo ($program['payment_plan_type'] ?? '') === 'block' ? 'selected' : ''; ?>>Block-based (Online)</option>
                                </select>
                                <div class="form-text">How students will pay for this program</div>
                            </div>

                            <div class="form-group" id="installmentCountGroup" style="display: <?php echo ($program['payment_plan_type'] ?? 'full') === 'installment' ? 'block' : 'none'; ?>;">
                                <label for="installment_count" class="form-label">Number of Installments</label>
                                <input type="number" name="installment_count" id="installment_count"
                                    class="form-control" min="2" max="12"
                                    value="<?php echo $program['installment_count'] ?? 2; ?>">
                                <div class="form-text">For installment plans only (2-12 installments)</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="late_fee_percentage" class="form-label">Late Fee Percentage (%)</label>
                                <input type="number" name="late_fee_percentage" id="late_fee_percentage"
                                    class="form-control" step="0.01" min="0" max="50"
                                    value="<?php echo $program['late_fee_percentage'] ?? 5.00; ?>">
                                <div class="form-text">Percentage added for late payments (0-50%)</div>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="fee_description" class="form-label">Fee Description / Breakdown</label>
                            <textarea name="fee_description" id="fee_description" class="form-control" rows="4"
                                placeholder="Detailed breakdown of what the fee covers, additional costs, refund policy, etc."><?php echo htmlspecialchars($program['fee_description'] ?? ''); ?></textarea>
                            <div class="form-text">This will be shown to students on the program page</div>
                        </div>

                        <!-- Form Actions for Fees -->
                        <div class="form-actions">
                            <div>
                                <a href="view.php?id=<?php echo $program_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-redo"></i> Reset Form
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Program Requirements Tab -->
            <div id="requirements-tab" class="tab-content">
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="fas fa-graduation-cap"></i> Program Requirements
                    </h2>

                    <!-- Help Tip -->
                    <div class="help-tip">
                        <i class="fas fa-lightbulb"></i>
                        <div class="help-tip-content">
                            <h4>Program Requirements Guidelines</h4>
                            <p>Define the core (required) and elective (optional) courses for this program. Core courses must be completed by all students. Elective courses allow students to choose based on their interests.</p>
                        </div>
                    </div>

                    <!-- Requirements Summary -->
                    <div class="requirements-summary">
                        <h4>Current Requirements Summary</h4>
                        <div class="requirements-stats">
                            <div class="stat-card core">
                                <div class="stat-number"><?php echo count($core_courses); ?></div>
                                <div class="stat-label">Core Courses</div>
                            </div>
                            <div class="stat-card elective">
                                <div class="stat-number"><?php echo count($elective_courses); ?></div>
                                <div class="stat-label">Elective Courses</div>
                            </div>
                            <div class="stat-card total">
                                <div class="stat-number"><?php echo count($all_courses); ?></div>
                                <div class="stat-label">Total Courses Available</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $requirements_meta['min_electives'] ?? 0; ?></div>
                                <div class="stat-label">Min Electives Required</div>
                            </div>
                        </div>
                    </div>

                    <!-- Course Selection Form -->
                    <form method="POST" id="requirementsForm">
                        <input type="hidden" name="action" value="update_requirements">
                        <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="core_courses" class="form-label">Core Courses (Required)</label>
                                <select name="core_courses[]" id="core_courses" class="form-control" multiple="multiple">
                                    <?php foreach ($all_courses as $course): ?>
                                        <option value="<?php echo $course['id']; ?>"
                                            <?php echo in_array($course['id'], $core_courses) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['title']); ?>
                                            <?php if ($course['is_required'] ?? 0): ?> (Required)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select all courses that are mandatory for this program</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="elective_courses" class="form-label">Elective Courses (Optional)</label>
                                <select name="elective_courses[]" id="elective_courses" class="form-control" multiple="multiple">
                                    <?php foreach ($all_courses as $course): ?>
                                        <option value="<?php echo $course['id']; ?>"
                                            <?php echo in_array($course['id'], $elective_courses) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['title']); ?>
                                            <?php if ($course['level']): ?> (<?php echo ucfirst($course['level']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select courses that students can choose from based on interests</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="min_electives" class="form-label">Minimum Electives Required</label>
                                <input type="number" name="min_electives" id="min_electives" class="form-control"
                                    min="0" value="<?php echo $requirements_meta['min_electives'] ?? 3; ?>">
                                <div class="form-text">Minimum number of elective courses students must complete</div>
                            </div>

                            <div class="form-group">
                                <label for="max_electives" class="form-label">Maximum Electives Allowed</label>
                                <input type="number" name="max_electives" id="max_electives" class="form-control"
                                    min="0" value="<?php echo $requirements_meta['max_electives'] ?? count($elective_courses); ?>">
                                <div class="form-text">Maximum number of elective courses students can take</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="total_credits" class="form-label">Total Credits Required</label>
                                <input type="number" name="total_credits" id="total_credits" class="form-control"
                                    min="0" step="0.5"
                                    value="<?php echo $requirements_meta['total_credits'] ?? 0; ?>">
                                <div class="form-text">Total credit units required for program completion</div>
                            </div>

                            <div class="form-group">
                                <label for="min_grade_required" class="form-label">Minimum Passing Grade</label>
                                <select name="min_grade_required" id="min_grade_required" class="form-control">
                                    <option value="C" <?php echo ($requirements_meta['min_grade_required'] ?? 'C') === 'C' ? 'selected' : ''; ?>>C (Average)</option>
                                    <option value="B" <?php echo ($requirements_meta['min_grade_required'] ?? '') === 'B' ? 'selected' : ''; ?>>B (Good)</option>
                                    <option value="A" <?php echo ($requirements_meta['min_grade_required'] ?? '') === 'A' ? 'selected' : ''; ?>>A (Excellent)</option>
                                    <option value="D" <?php echo ($requirements_meta['min_grade_required'] ?? '') === 'D' ? 'selected' : ''; ?>>D (Passing)</option>
                                </select>
                                <div class="form-text">Minimum grade required for program completion</div>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="graduation_requirements" class="form-label">Additional Graduation Requirements</label>
                            <textarea name="graduation_requirements" id="graduation_requirements" class="form-control" rows="4"
                                placeholder="e.g., Minimum attendance rate, Final project completion, Internship requirements, etc."><?php echo htmlspecialchars($requirements_meta['graduation_requirements'] ?? ''); ?></textarea>
                            <div class="form-text">Any additional requirements for program completion</div>
                        </div>

                        <!-- Form Actions for Requirements -->
                        <div class="form-actions">
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="resetRequirements()">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Requirements
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Current Requirements Display -->
                    <div class="courses-container">
                        <div class="course-category core">
                            <h4><i class="fas fa-book"></i> Core Courses (<?php echo count($core_courses); ?>)</h4>
                            <div class="course-list">
                                <?php if (!empty($core_courses)): ?>
                                    <?php foreach ($core_courses as $course_id): ?>
                                        <?php if (isset($all_courses[$course_id])):
                                            $course = $all_courses[$course_id]; ?>
                                            <div class="course-item core">
                                                <div class="course-code core"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                <div class="course-details">
                                                    <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                                    <div class="course-meta">
                                                        <?php echo $course['duration_hours']; ?> hours
                                                        <?php if ($course['level']): ?> | <?php echo ucfirst($course['level']); ?><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-courses">
                                        <i class="fas fa-book-open"></i>
                                        <p>No core courses defined</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="course-category elective">
                            <h4><i class="fas fa-bookmark"></i> Elective Courses (<?php echo count($elective_courses); ?>)</h4>
                            <div class="course-list">
                                <?php if (!empty($elective_courses)): ?>
                                    <?php foreach ($elective_courses as $course_id): ?>
                                        <?php if (isset($all_courses[$course_id])):
                                            $course = $all_courses[$course_id]; ?>
                                            <div class="course-item elective">
                                                <div class="course-code elective"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                <div class="course-details">
                                                    <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                                    <div class="course-meta">
                                                        <?php echo $course['duration_hours']; ?> hours
                                                        <?php if ($course['level']): ?> | <?php echo ucfirst($course['level']); ?><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-courses">
                                        <i class="fas fa-book-open"></i>
                                        <p>No elective courses defined</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Settings Tab -->
            <div id="advanced-tab" class="tab-content">
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="fas fa-cogs"></i> Advanced Settings
                    </h2>

                    <!-- Help Tip -->
                    <div class="help-tip">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="help-tip-content">
                            <h4>Advanced Settings Warning</h4>
                            <p>These settings affect how the program functions. Changes may impact existing enrollments and financial calculations.</p>
                        </div>
                    </div>

                    <!-- Program Metadata -->
                    <div class="readonly-info">
                        <div class="info-row">
                            <span class="info-label">Program ID</span>
                            <span class="info-value">#<?php echo $program_id; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Created By</span>
                            <span class="info-value">
                                <?php
                                if (isset($program['created_by']) && $program['created_by']) {
                                    $creator_sql = "SELECT first_name, last_name FROM users WHERE id = ?";
                                    $creator_stmt = $conn->prepare($creator_sql);
                                    $creator_stmt->bind_param("i", $program['created_by']);
                                    $creator_stmt->execute();
                                    $creator_result = $creator_stmt->get_result();
                                    if ($creator = $creator_result->fetch_assoc()) {
                                        echo htmlspecialchars($creator['first_name'] . ' ' . $creator['last_name']);
                                    } else {
                                        echo 'Unknown';
                                    }
                                } else {
                                    echo 'System';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Created Date</span>
                            <span class="info-value"><?php echo formatDate($program['created_at'], 'F j, Y \a\t g:i A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Updated</span>
                            <span class="info-value"><?php echo formatDate($program['updated_at'], 'F j, Y \a\t g:i A'); ?></span>
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="form-section" style="border-color: var(--danger);">
                        <h2 class="section-title" style="color: var(--danger);">
                            <i class="fas fa-exclamation-triangle"></i> Danger Zone
                        </h2>

                        <div style="background: #fee2e2; padding: 1.5rem; border-radius: 8px; border: 1px solid #fecaca;">
                            <h4 style="color: #991b1b; margin-bottom: 1rem;">Irreversible Actions</h4>

                            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                                <a href="?action=clone&id=<?php echo $program_id; ?>"
                                    class="btn btn-warning"
                                    onclick="return confirm('Create a copy of this program? The new program will be inactive.')">
                                    <i class="fas fa-copy"></i> Clone Program
                                </a>

                                <a href="?action=delete&id=<?php echo $program_id; ?>"
                                    class="btn btn-danger"
                                    onclick="return confirm('⚠️ WARNING: This will permanently delete this program and all associated data including courses, payment plans, and program structure. This action cannot be undone.\n\nAre you absolutely sure?')">
                                    <i class="fas fa-trash"></i> Delete Program
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // DOM Elements
        const programTypeSelect = document.getElementById('program_type');
        const baseFeeInput = document.getElementById('base_fee');
        const regFeeInput = document.getElementById('registration_fee');
        const onlineFeeInput = document.getElementById('online_fee');
        const onsiteFeeInput = document.getElementById('onsite_fee');
        const paymentPlanSelect = document.getElementById('payment_plan_type');
        const installmentCountGroup = document.getElementById('installmentCountGroup');
        const onlineFeeGroup = document.getElementById('onlineFeeGroup');
        const onsiteFeeGroup = document.getElementById('onsiteFeeGroup');
        const specificFeeGroups = document.getElementById('specificFeeGroups');
        const programTypeHint = document.getElementById('programTypeHint');
        const programTypeHintText = document.getElementById('programTypeHintText');
        const feeCalculator = document.getElementById('feeCalculator');

        // Display elements
        const baseFeeDisplay = document.getElementById('baseFeeDisplay');
        const regFeeDisplay = document.getElementById('regFeeDisplay');
        const totalFeeDisplay = document.getElementById('totalFeeDisplay');

        // Original form values for reset
        const originalValues = {
            program_code: "<?php echo htmlspecialchars($program['program_code']); ?>",
            name: "<?php echo htmlspecialchars($program['name']); ?>",
            description: `<?php echo htmlspecialchars($program['description']); ?>`,
            program_type: "<?php echo $program['program_type']; ?>",
            status: "<?php echo $program['status']; ?>",
            duration_weeks: <?php echo $program['duration_weeks']; ?>,
            duration_mode: "<?php echo $program['duration_mode'] ?? ''; ?>",
            schedule_type: "<?php echo htmlspecialchars($program['schedule_type'] ?? ''); ?>",
            school_id: "<?php echo $program['school_id'] ?? ''; ?>",
            base_fee: <?php echo number_format($program['base_fee'], 2, '.', ''); ?>,
            registration_fee: <?php echo number_format($program['registration_fee'] ?? 0, 2, '.', ''); ?>,
            online_fee: <?php echo number_format($program['online_fee'] ?? $program['base_fee'], 2, '.', ''); ?>,
            onsite_fee: <?php echo number_format($program['onsite_fee'] ?? $program['base_fee'] * 1.2, 2, '.', ''); ?>,
            payment_plan_type: "<?php echo $program['payment_plan_type'] ?? 'full'; ?>",
            installment_count: <?php echo $program['installment_count'] ?? 2; ?>,
            late_fee_percentage: <?php echo $program['late_fee_percentage'] ?? 5.00; ?>,
            fee_description: `<?php echo htmlspecialchars($program['fee_description'] ?? ''); ?>`
        };

        // Format currency
        function formatCurrency(amount) {
            return '₦' + parseFloat(amount).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Calculate and update fee displays
        function updateFeeDisplays() {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const regFee = parseFloat(regFeeInput.value) || 0;
            const totalFee = baseFee + regFee;

            baseFeeDisplay.textContent = formatCurrency(baseFee);
            regFeeDisplay.textContent = formatCurrency(regFee);
            totalFeeDisplay.textContent = formatCurrency(totalFee);
        }

        // Update specific fees based on base fee
        function updateSpecificFees() {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const programType = programTypeSelect.value;

            // Only auto-update if the specific fee fields are empty or match base fee
            if (programType === 'online' && (!onlineFeeInput.value || parseFloat(onlineFeeInput.value) === baseFee)) {
                onlineFeeInput.value = baseFee.toFixed(2);
            }

            if (programType === 'onsite' && (!onsiteFeeInput.value || parseFloat(onsiteFeeInput.value) === baseFee)) {
                // Onsite is typically 20% higher
                onsiteFeeInput.value = (baseFee * 1.2).toFixed(2);
            }

            // Update displays
            updateFeeDisplays();
        }

        // Toggle program type settings
        function toggleProgramTypeSettings() {
            const programType = programTypeSelect.value;
            
            // Show/hide fee calculator and specific fee groups
            if (programType === 'school') {
                feeCalculator.style.display = 'none';
                specificFeeGroups.style.display = 'none';
                programTypeHint.style.display = 'block';
                programTypeHintText.textContent = 'School programs typically follow the school\'s own fee structure. The base fee entered will be used as the default program fee.';
                
                // Hide online/onsite specific fields
                onlineFeeGroup.style.display = 'none';
                onsiteFeeGroup.style.display = 'none';
            } else {
                feeCalculator.style.display = 'block';
                specificFeeGroups.style.display = 'grid';
                programTypeHint.style.display = 'none';
                
                // Show appropriate fee group
                if (programType === 'online') {
                    onlineFeeGroup.style.display = 'block';
                    onsiteFeeGroup.style.display = 'none';
                    programTypeHint.style.display = 'block';
                    programTypeHintText.textContent = 'Online programs are delivered virtually through our learning platform.';
                } else if (programType === 'onsite') {
                    onlineFeeGroup.style.display = 'none';
                    onsiteFeeGroup.style.display = 'block';
                    programTypeHint.style.display = 'block';
                    programTypeHintText.textContent = 'Onsite programs are delivered in physical classrooms at our academy locations.';
                }
            }
        }

        // Toggle installment count field
        function toggleInstallmentCount() {
            installmentCountGroup.style.display =
                paymentPlanSelect.value === 'installment' ? 'block' : 'none';
        }

        // Main Tab navigation
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Activate selected tab button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.textContent.includes(tabName.replace('_', ' '))) {
                    btn.classList.add('active');
                }
            });

            // Update URL hash
            window.location.hash = tabName;
        }

        // Reset form to original values
        function resetForm() {
            if (confirm('Reset all changes? You will lose any unsaved changes.')) {
                document.getElementById('program_code').value = originalValues.program_code;
                document.getElementById('name').value = originalValues.name;
                document.getElementById('description').value = originalValues.description;
                document.getElementById('program_type').value = originalValues.program_type;
                document.getElementById('status').value = originalValues.status;
                document.getElementById('duration_weeks').value = originalValues.duration_weeks;
                document.getElementById('duration_mode').value = originalValues.duration_mode;
                document.getElementById('schedule_type').value = originalValues.schedule_type;
                document.getElementById('school_id').value = originalValues.school_id;
                document.getElementById('base_fee').value = originalValues.base_fee;
                document.getElementById('registration_fee').value = originalValues.registration_fee;
                document.getElementById('online_fee').value = originalValues.online_fee;
                document.getElementById('onsite_fee').value = originalValues.onsite_fee;
                document.getElementById('payment_plan_type').value = originalValues.payment_plan_type;
                document.getElementById('installment_count').value = originalValues.installment_count;
                document.getElementById('late_fee_percentage').value = originalValues.late_fee_percentage;
                document.getElementById('fee_description').value = originalValues.fee_description;

                // Update UI
                toggleProgramTypeSettings();
                toggleInstallmentCount();
                updateFeeDisplays();

                // Clear any error states
                document.querySelectorAll('.form-control.error').forEach(el => {
                    el.classList.remove('error');
                });

                // Scroll to top
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        }

        // Reset requirements form
        function resetRequirements() {
            if (confirm('Reset requirements to original values?')) {
                // This would need to be implemented based on your specific requirements reset logic
                // For now, just reload the page
                window.location.reload();
            }
        }

        // Initialize form state
        function initializeForm() {
            toggleProgramTypeSettings();
            toggleInstallmentCount();
            updateFeeDisplays();

            // Set up main tab switching
            const hash = window.location.hash.substring(1);
            if (hash && ['basic', 'structure', 'fees', 'requirements', 'advanced'].includes(hash)) {
                showTab(hash);
            } else {
                showTab('basic');
            }

            // Initialize Select2 for course selection
            $('#core_courses').select2({
                placeholder: 'Select core courses',
                allowClear: true,
                width: '100%'
            });

            $('#elective_courses').select2({
                placeholder: 'Select elective courses',
                allowClear: true,
                width: '100%'
            });
        }

        // Event Listeners
        programTypeSelect.addEventListener('change', toggleProgramTypeSettings);
        baseFeeInput.addEventListener('input', updateSpecificFees);
        baseFeeInput.addEventListener('blur', updateSpecificFees);
        regFeeInput.addEventListener('input', updateFeeDisplays);
        paymentPlanSelect.addEventListener('change', toggleInstallmentCount);

        // Add event listeners to fee inputs to update displays
        if (onlineFeeInput) onlineFeeInput.addEventListener('input', updateFeeDisplays);
        if (onsiteFeeInput) onsiteFeeInput.addEventListener('input', updateFeeDisplays);

        // Form validation
        const programForm = document.getElementById('programForm');
        if (programForm) {
            programForm.addEventListener('submit', function(e) {
                let isValid = true;
                const programCode = document.getElementById('program_code')?.value.trim();
                const programName = document.getElementById('name')?.value.trim();
                const baseFee = parseFloat(document.getElementById('base_fee')?.value);
                const programType = document.getElementById('program_type')?.value;

                // Clear previous error states
                document.querySelectorAll('.form-control.error').forEach(el => {
                    el.classList.remove('error');
                });

                // Validate program code
                if (programCode !== undefined && !programCode) {
                    document.getElementById('program_code').classList.add('error');
                    isValid = false;
                }

                // Validate program name
                if (programName !== undefined && !programName) {
                    document.getElementById('name').classList.add('error');
                    isValid = false;
                }

                // Validate base fee
                if (baseFee !== undefined && (isNaN(baseFee) || baseFee < 0)) {
                    document.getElementById('base_fee').classList.add('error');
                    isValid = false;
                }

                // Validate specific fees based on program type
                if (programType === 'online') {
                    const onlineFee = parseFloat(document.getElementById('online_fee')?.value);
                    if (onlineFee !== undefined && (isNaN(onlineFee) || onlineFee < 0)) {
                        document.getElementById('online_fee').classList.add('error');
                        isValid = false;
                    }
                } else if (programType === 'onsite') {
                    const onsiteFee = parseFloat(document.getElementById('onsite_fee')?.value);
                    if (onsiteFee !== undefined && (isNaN(onsiteFee) || onsiteFee < 0)) {
                        document.getElementById('onsite_fee').classList.add('error');
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    alert('Please fix the errors in the form before submitting.');
                    // Switch to basic tab to show errors
                    showTab('basic');
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initializeForm);
    </script>
</body>

</html>