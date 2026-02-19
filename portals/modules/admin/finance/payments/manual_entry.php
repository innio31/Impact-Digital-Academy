<?php
// modules/admin/finance/payments/manual_entry.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Handle POST request for manual payment entry
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_entry') {
    $record_type = isset($_POST['record_type']) ? $_POST['record_type'] : ''; // 'academic' or 'non_academic'
    $student_id = isset($_POST['student_id']) && $_POST['student_id'] != '' ? (int)$_POST['student_id'] : null;
    $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : null;
    $client_email = isset($_POST['client_email']) ? trim($_POST['client_email']) : null;
    $client_phone = isset($_POST['client_phone']) ? trim($_POST['client_phone']) : null;
    $payment_type = isset($_POST['payment_type']) ? $_POST['payment_type'] : '';
    $service_category = isset($_POST['service_category']) ? $_POST['service_category'] : '';
    $service_description = isset($_POST['service_description']) ? trim($_POST['service_description']) : '';
    $program_id = isset($_POST['program_id']) && $_POST['program_id'] != '' ? (int)$_POST['program_id'] : null;
    $course_id = isset($_POST['course_id']) && $_POST['course_id'] != '' ? (int)$_POST['course_id'] : null;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] != '' ? (int)$_POST['class_id'] : null;
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $bank_name = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : null;
    $account_name = isset($_POST['account_name']) ? trim($_POST['account_name']) : null;
    $account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : null;
    $transaction_reference = isset($_POST['transaction_reference']) ? trim($_POST['transaction_reference']) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $payment_date = isset($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $payment_time = isset($_POST['payment_time']) ? $_POST['payment_time'] : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $whatsapp_sender = isset($_POST['whatsapp_sender']) ? trim($_POST['whatsapp_sender']) : null;
    $whatsapp_message_date = isset($_POST['whatsapp_message_date']) ? $_POST['whatsapp_message_date'] : null;

    // Validate required fields
    $errors = [];

    if (!$record_type) {
        $errors[] = "Record type is required";
    }

    if ($record_type === 'academic' && !$student_id) {
        $errors[] = "Student is required for academic records";
    }

    if ($record_type === 'non_academic' && !$client_name) {
        $errors[] = "Client name is required for non-academic records";
    }

    if (!$payment_type) {
        $errors[] = "Payment type is required";
    }

    if (!$transaction_reference) {
        $errors[] = "Transaction reference is required";
    }

    if ($amount <= 0) {
        $errors[] = "Valid amount is required";
    }

    // For academic records, validate academic fields
    if ($record_type === 'academic' && $payment_type !== 'registration') {
        if (!$program_id) {
            $errors[] = "Program is required for academic payments";
        }
        if (!$course_id) {
            $errors[] = "Course is required for academic payments";
        }
    }

    // For non-academic records, validate service fields
    if ($record_type === 'non_academic' && !$service_category) {
        $errors[] = "Service category is required for non-academic records";
    }

    if (!empty($errors)) {
        $message = implode("<br>", $errors);
        $message_type = "error";
    } else {
        // Check if transaction reference already exists
        $check_sql = "SELECT id FROM manual_payment_entries WHERE transaction_reference = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $transaction_reference);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = "Transaction reference already exists in the system!";
            $message_type = "error";
        } else {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Insert manual payment entry with new non-academic fields
                $sql = "INSERT INTO manual_payment_entries (
                            admin_id, student_id, payment_type, program_id, course_id, class_id,
                            payment_method, bank_name, account_name, account_number, transaction_reference,
                            amount, payment_date, payment_time, description, whatsapp_sender, whatsapp_message_date,
                            status, created_at,
                            record_type, client_name, client_email, client_phone, service_category, service_description
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(),
                                  ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);

                // Prepare variables for binding (handle NULLs)
                $bind_student_id = $student_id ?: null;
                $bind_program_id = $program_id ?: null;
                $bind_course_id = $course_id ?: null;
                $bind_class_id = $class_id ?: null;
                $bind_bank_name = $bank_name ?: null;
                $bind_account_name = $account_name ?: null;
                $bind_account_number = $account_number ?: null;
                $bind_payment_time = $payment_time ?: null;
                $bind_description = $description ?: null;
                $bind_whatsapp_sender = $whatsapp_sender ?: null;
                $bind_whatsapp_message_date = $whatsapp_message_date ?: null;
                $bind_client_name = $client_name ?: null;
                $bind_client_email = $client_email ?: null;
                $bind_client_phone = $client_phone ?: null;
                $bind_service_category = $service_category ?: null;
                $bind_service_description = $service_description ?: null;

                $stmt->bind_param(
                    "iissiiisssssdsssssssssss",
                    $admin_id,
                    $bind_student_id,
                    $payment_type,
                    $bind_program_id,
                    $bind_course_id,
                    $bind_class_id,
                    $payment_method,
                    $bind_bank_name,
                    $bind_account_name,
                    $bind_account_number,
                    $transaction_reference,
                    $amount,
                    $payment_date,
                    $bind_payment_time,
                    $bind_description,
                    $bind_whatsapp_sender,
                    $bind_whatsapp_message_date,
                    $record_type,
                    $bind_client_name,
                    $bind_client_email,
                    $bind_client_phone,
                    $bind_service_category,
                    $bind_service_description
                );

                if (!$stmt->execute()) {
                    throw new Exception("Error adding manual payment entry: " . $conn->error);
                }

                $manual_entry_id = $stmt->insert_id;
                $stmt->close();

                // Create payment verification record (only for academic payments)
                if ($record_type === 'academic') {
                    // Map payment_type to verification type
                    $verification_type = '';
                    if (strpos($payment_type, 'tuition') !== false) {
                        $verification_type = 'tuition';
                    } elseif ($payment_type === 'registration') {
                        $verification_type = 'registration';
                    } elseif ($payment_type === 'course') {
                        $verification_type = 'course';
                    } else {
                        $verification_type = 'other';
                    }

                    // Generate unique payment reference
                    $payment_reference = 'MANUAL-' . date('Ymd') . '-' . str_pad($manual_entry_id, 6, '0', STR_PAD_LEFT);

                    $verification_sql = "INSERT INTO payment_verifications (
                                            payment_reference, student_id, payment_type, program_id, 
                                            course_id, class_id, amount, payment_method, 
                                            proof_text, status, entered_by, manual_entry_id, 
                                            created_at, updated_at
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())";

                    $verification_stmt = $conn->prepare($verification_sql);
                    $proof_text = "Manual entry #$manual_entry_id - $transaction_reference";

                    $verification_stmt->bind_param(
                        "sisiisissii",
                        $payment_reference,
                        $student_id,
                        $verification_type,
                        $bind_program_id,
                        $bind_course_id,
                        $bind_class_id,
                        $amount,
                        $payment_method,
                        $proof_text,
                        $admin_id,
                        $manual_entry_id
                    );

                    if (!$verification_stmt->execute()) {
                        throw new Exception("Error creating payment verification: " . $conn->error);
                    }

                    $verification_id = $verification_stmt->insert_id;
                    $verification_stmt->close();
                }

                // Create notification for other admins
                $record_type_text = $record_type === 'academic' ? 'Academic' : 'Non-Academic';
                $notify_sql = "INSERT INTO notifications (user_id, title, message, type, created_at)
                              SELECT id, 'New Manual Payment Entry', 
                                     CONCAT('A ', ?, ' payment entry of ₦', ?, ' has been added.'), 
                                     'system', NOW()
                              FROM users WHERE role = 'admin' AND id != ?";
                $notify_stmt = $conn->prepare($notify_sql);
                $notify_stmt->bind_param("sdi", $record_type_text, $amount, $admin_id);
                $notify_stmt->execute();
                $notify_stmt->close();

                // Also add to financial_transactions table for reporting
                $transaction_sql = "INSERT INTO financial_transactions (
                                        student_id, class_id, invoice_id, transaction_type, 
                                        payment_method, amount, currency, description, status, 
                                        is_verified, verified_by, verified_at, receipt_url, 
                                        created_at, updated_at
                                    ) VALUES (?, ?, NULL, ?, ?, ?, 'NGN', ?, 'completed', 
                                              1, ?, NOW(), NULL, NOW(), NOW())";
                
                $transaction_stmt = $conn->prepare($transaction_sql);
                $transaction_desc = $record_type === 'academic' 
                    ? "$payment_type payment - $transaction_reference"
                    : "Non-academic service: $service_category - $service_description";
                
                $transaction_stmt->bind_param(
                    "iissssi",
                    $bind_student_id,
                    $bind_class_id,
                    $payment_type,
                    $payment_method,
                    $amount,
                    $transaction_desc,
                    $admin_id
                );
                
                $transaction_stmt->execute();
                $transaction_stmt->close();

                // Commit transaction
                $conn->commit();

                $success_msg = "Manual payment entry added successfully!";
                if ($record_type === 'academic') {
                    $success_msg .= " Verification ID: $verification_id. It will appear on the verification page for review.";
                }
                $message = $success_msg;
                $message_type = "success";

                // Clear POST data
                $_POST = array();
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $message = "Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
        $check_stmt->close();
    }
}

// Get students for dropdown
$students = [];
$students_sql = "SELECT id, first_name, last_name, email FROM users WHERE role = 'student' AND status = 'active' ORDER BY first_name, last_name";
$students_result = $conn->query($students_sql);
if ($students_result) {
    while ($student = $students_result->fetch_assoc()) {
        $students[] = $student;
    }
}

// Get programs for dropdown
$programs = [];
$programs_sql = "SELECT id, name, program_code FROM programs WHERE status = 'active' ORDER BY name";
$programs_result = $conn->query($programs_sql);
if ($programs_result) {
    while ($program = $programs_result->fetch_assoc()) {
        $programs[] = $program;
    }
}

// Get manual payments for listing (including non-academic)
$manual_payments = [];
$manual_where = [];
$manual_params = [];
$manual_types = "";

if (isset($_GET['manual_status']) && $_GET['manual_status'] !== '') {
    $manual_where[] = "mpe.status = ?";
    $manual_params[] = $_GET['manual_status'];
    $manual_types .= "s";
}

if (isset($_GET['record_type']) && $_GET['record_type'] !== '') {
    $manual_where[] = "mpe.record_type = ?";
    $manual_params[] = $_GET['record_type'];
    $manual_types .= "s";
}

$manual_where_sql = $manual_where ? "WHERE " . implode(" AND ", $manual_where) : "";

$manual_sql = "SELECT mpe.*, 
               u1.first_name as student_first_name, u1.last_name as student_last_name, u1.email as student_email,
               u2.first_name as admin_first_name, u2.last_name as admin_last_name,
               p.name as program_name,
               c.title as course_title,
               cb.batch_code,
               pv.id as verification_id,
               pv.status as verification_status
        FROM manual_payment_entries mpe
        JOIN users u2 ON mpe.admin_id = u2.id
        LEFT JOIN users u1 ON mpe.student_id = u1.id
        LEFT JOIN programs p ON mpe.program_id = p.id
        LEFT JOIN courses c ON mpe.course_id = c.id
        LEFT JOIN class_batches cb ON mpe.class_id = cb.id
        LEFT JOIN payment_verifications pv ON pv.manual_entry_id = mpe.id
        $manual_where_sql
        ORDER BY mpe.created_at DESC
        LIMIT 50";

$stmt = $conn->prepare($manual_sql);
if ($manual_params) {
    $stmt->bind_param($manual_types, ...$manual_params);
}
$stmt->execute();
$manual_payments_result = $stmt->get_result();
$manual_payments = $manual_payments_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
// Handle AJAX requests for loading courses and classes
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $conn = getDBConnection();
    
    if (isset($_GET['load_courses']) && isset($_GET['program_id'])) {
        $program_id = (int)$_GET['program_id'];

        $courses_sql = "SELECT id, course_code, title FROM courses WHERE program_id = ? AND status = 'active' ORDER BY order_number";
        $courses_stmt = $conn->prepare($courses_sql);
        $courses_stmt->bind_param("i", $program_id);
        $courses_stmt->execute();
        $courses_result = $courses_stmt->get_result();

        $courses_data = [];
        while ($course = $courses_result->fetch_assoc()) {
            $courses_data[] = $course;
        }

        header('Content-Type: application/json');
        echo json_encode($courses_data);
        exit();
    }

    if (isset($_GET['load_classes']) && isset($_GET['course_id'])) {
        $course_id = (int)$_GET['course_id'];

        $classes_sql = "SELECT id, batch_code, name FROM class_batches WHERE course_id = ? AND status IN ('scheduled', 'ongoing') ORDER BY start_date DESC";
        $classes_stmt = $conn->prepare($classes_sql);
        $classes_stmt->bind_param("i", $course_id);
        $classes_stmt->execute();
        $classes_result = $classes_stmt->get_result();

        $classes_data = [];
        while ($class = $classes_result->fetch_assoc()) {
            $classes_data[] = $class;
        }

        header('Content-Type: application/json');
        echo json_encode($classes_data);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Financial Entry - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --academic: #8b5cf6;
            --non-academic: #ec4899;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
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
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .header-content h1 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
        }

        .header-content h1 i {
            color: var(--primary);
        }

        .header-content p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Message Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: #d1fae5;
            border-color: var(--success);
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border-color: var(--danger);
            color: #991b1b;
        }

        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            text-align: center;
            min-width: fit-content;
        }

        @media (min-width: 768px) {
            .btn {
                padding: 0.75rem 1.5rem;
            }
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
            color: var(--gray);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: var(--gray);
        }

        /* Toggle Buttons */
        .toggle-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .toggle-btn {
            flex: 1;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            min-width: 140px;
        }

        .toggle-btn:hover {
            border-color: var(--primary);
        }

        .toggle-btn.active {
            border-color: var(--primary);
            background: #dbeafe;
            color: var(--primary);
        }

        .toggle-btn i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        /* Manual Entry Section */
        .manual-verification-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 1rem;
        }

        .section-header h2 {
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }

        .manual-form-container {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .manual-form-container {
                padding: 1.5rem;
            }
        }

        /* Form Styles */
        .manual-entry-form .form-row {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 640px) {
            .manual-entry-form .form-row {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }

        .manual-entry-form .form-group {
            flex: 1;
            min-width: 100%;
        }

        @media (min-width: 640px) {
            .manual-entry-form .form-group {
                min-width: calc(50% - 0.5rem);
            }
        }

        @media (min-width: 1024px) {
            .manual-entry-form .form-group {
                min-width: calc(33.333% - 0.67rem);
            }
        }

        .manual-entry-form .form-group.full-width {
            min-width: 100%;
        }

        .manual-entry-form label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .manual-entry-form label i {
            width: 16px;
        }

        .manual-entry-form select,
        .manual-entry-form input,
        .manual-entry-form textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }

        .manual-entry-form select:focus,
        .manual-entry-form input:focus,
        .manual-entry-form textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .manual-entry-form textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Service Categories */
        .service-categories {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .category-option {
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            font-size: 0.9rem;
        }

        .category-option:hover {
            border-color: var(--primary);
            background: #f0f9ff;
        }

        .category-option.selected {
            border-color: var(--primary);
            background: #dbeafe;
            color: var(--primary);
        }

        .category-option i {
            display: block;
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e2e8f0;
        }

        @media (min-width: 640px) {
            .form-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
        }

        /* Manual Payments List */
        .manual-payments-list {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .manual-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .inline-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .inline-form select {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            min-width: 150px;
        }

        /* Payments Table */
        .payments-table {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead {
            background: var(--primary);
            color: white;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.3s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        td {
            padding: 0.75rem 1rem;
            color: var(--dark);
            font-size: 0.9rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-verified {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Record Type Badges */
        .record-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-academic {
            background: #ede9fe;
            color: var(--academic);
        }

        .badge-non-academic {
            background: #fce7f3;
            color: var(--non-academic);
        }

        /* Payment Type */
        .payment-type {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-block;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        /* Responsive Improvements */
        @media (max-width: 640px) {
            .container {
                padding: 0.75rem;
            }
            
            .header,
            .manual-verification-section,
            .manual-payments-list {
                padding: 1rem;
            }
            
            .manual-form-container {
                padding: 1rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
            
            .toggle-btn {
                min-width: 120px;
                padding: 0.75rem;
            }
            
            .toggle-btn i {
                font-size: 1.25rem;
            }
        }

        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 0.75rem;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }

        .mobile-menu-buttons {
            display: flex;
            justify-content: space-around;
        }

        .mobile-menu-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.75rem;
        }

        .mobile-menu-btn.active {
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .mobile-menu {
                display: block;
            }
            
            .nav-buttons {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header with Navigation -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-money-bill-wave"></i> Manual Financial Entry</h1>
                <p>Enter academic and non-academic financial records</p>
            </div>
            <div class="nav-buttons">
                <a href="verify.php" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> Payment Verification
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/index.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Payments Dashboard
                </a>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                <strong><?php echo ucfirst($message_type); ?>:</strong> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Record Type Toggle -->
        <div class="toggle-buttons" id="recordTypeToggle">
            <div class="toggle-btn active" data-type="academic">
                <i class="fas fa-graduation-cap"></i>
                <div>Academic</div>
                <small>Student payments, tuition, etc.</small>
            </div>
            <div class="toggle-btn" data-type="non_academic">
                <i class="fas fa-briefcase"></i>
                <div>Non-Academic</div>
                <small>Services, software, CBT, etc.</small>
            </div>
        </div>

        <!-- Manual Entry Form -->
        <div class="manual-verification-section">
            <div class="section-header">
                <h2><i class="fas fa-plus-circle"></i> New Financial Record</h2>
                <p>Enter payment details for academic or non-academic services</p>
            </div>

            <div class="manual-form-container">
                <form method="POST" action="" id="manualEntryForm" class="manual-entry-form">
                    <input type="hidden" name="action" value="manual_entry">
                    <input type="hidden" name="record_type" id="record_type" value="academic">

                    <!-- Academic Fields (visible by default) -->
                    <div id="academic_fields" class="record-fields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="student_id"><i class="fas fa-user"></i> Student *</label>
                                <select id="student_id" name="student_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>"
                                            <?php echo isset($_POST['student_id']) && $_POST['student_id'] == $student['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['email'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="payment_type"><i class="fas fa-money-bill-wave"></i> Payment Type *</label>
                                <select id="payment_type" name="payment_type" required onchange="toggleAcademicFields()">
                                    <option value="">Select Type</option>
                                    <option value="registration" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'registration' ? 'selected' : ''; ?>>Registration Fee</option>
                                    <option value="tuition_block1" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'tuition_block1' ? 'selected' : ''; ?>>Tuition - Block 1</option>
                                    <option value="tuition_block2" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'tuition_block2' ? 'selected' : ''; ?>>Tuition - Block 2</option>
                                    <option value="tuition_block3" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'tuition_block3' ? 'selected' : ''; ?>>Tuition - Block 3</option>
                                    <option value="course" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'course' ? 'selected' : ''; ?>>Course Fee</option>
                                    <option value="other" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'other' ? 'selected' : ''; ?>>Other Payment</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row" id="academic_program_fields">
                            <div class="form-group">
                                <label for="program_id"><i class="fas fa-graduation-cap"></i> Program *</label>
                                <select id="program_id" name="program_id" onchange="loadCourses()">
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>"
                                            <?php echo isset($_POST['program_id']) && $_POST['program_id'] == $program['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($program['name'] . ' (' . $program['program_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="course_id"><i class="fas fa-book"></i> Course *</label>
                                <select id="course_id" name="course_id" onchange="loadClasses()">
                                    <option value="">Select Course</option>
                                    <?php if (isset($_POST['program_id'])): ?>
                                        <?php 
                                        // Note: Courses will be loaded via AJAX
                                        ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="class_id"><i class="fas fa-users"></i> Class/Batch</label>
                                <select id="class_id" name="class_id">
                                    <option value="">Select Class</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Non-Academic Fields (hidden by default) -->
                    <div id="non_academic_fields" class="record-fields" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="client_name"><i class="fas fa-user-tie"></i> Client Name *</label>
                                <input type="text" id="client_name" name="client_name" 
                                    placeholder="Enter client/company name"
                                    value="<?php echo isset($_POST['client_name']) ? htmlspecialchars($_POST['client_name']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label for="client_email"><i class="fas fa-envelope"></i> Client Email</label>
                                <input type="email" id="client_email" name="client_email" 
                                    placeholder="client@example.com"
                                    value="<?php echo isset($_POST['client_email']) ? htmlspecialchars($_POST['client_email']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label for="client_phone"><i class="fas fa-phone"></i> Client Phone</label>
                                <input type="tel" id="client_phone" name="client_phone" 
                                    placeholder="+234 800 000 0000"
                                    value="<?php echo isset($_POST['client_phone']) ? htmlspecialchars($_POST['client_phone']) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-cogs"></i> Service Category *</label>
                                <input type="hidden" id="service_category" name="service_category" value="">
                                <div class="service-categories">
                                    <div class="category-option" data-category="software_installation">
                                        <i class="fas fa-desktop"></i>
                                        <div>Software Installation</div>
                                    </div>
                                    <div class="category-option" data-category="cbt_setup">
                                        <i class="fas fa-laptop-code"></i>
                                        <div>CBT Setup</div>
                                    </div>
                                    <div class="category-option" data-category="consultation">
                                        <i class="fas fa-comments"></i>
                                        <div>Consultation</div>
                                    </div>
                                    <div class="category-option" data-category="training">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <div>Corporate Training</div>
                                    </div>
                                    <div class="category-option" data-category="technical_support">
                                        <i class="fas fa-headset"></i>
                                        <div>Technical Support</div>
                                    </div>
                                    <div class="category-option" data-category="other_service">
                                        <i class="fas fa-tools"></i>
                                        <div>Other Service</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="service_description"><i class="fas fa-align-left"></i> Service Description *</label>
                                <textarea id="service_description" name="service_description" rows="3"
                                    placeholder="Describe the service provided..."><?php echo isset($_POST['service_description']) ? htmlspecialchars($_POST['service_description']) : ''; ?></textarea>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="non_academic_payment_type"><i class="fas fa-money-bill-wave"></i> Payment Type *</label>
                                <select id="non_academic_payment_type" name="payment_type" required>
                                    <option value="">Select Type</option>
                                    <option value="service_fee" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'service_fee' ? 'selected' : ''; ?>>Service Fee</option>
                                    <option value="consultation_fee" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'consultation_fee' ? 'selected' : ''; ?>>Consultation Fee</option>
                                    <option value="training_fee" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'training_fee' ? 'selected' : ''; ?>>Training Fee</option>
                                    <option value="installation_fee" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'installation_fee' ? 'selected' : ''; ?>>Installation Fee</option>
                                    <option value="other_service_payment" <?php echo isset($_POST['payment_type']) && $_POST['payment_type'] == 'other_service_payment' ? 'selected' : ''; ?>>Other Payment</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Common Fields (always visible) -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount"><i class="fas fa-money-bill"></i> Amount (₦) *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0" required
                                placeholder="0.00" value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method *</label>
                            <select id="payment_method" name="payment_method" required onchange="toggleBankDetails()">
                                <option value="">Select Method</option>
                                <option value="bank_transfer" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cash" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="pos" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'pos' ? 'selected' : ''; ?>>POS</option>
                                <option value="cheque" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="mobile_money" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="other" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="transaction_reference"><i class="fas fa-receipt"></i> Transaction Reference *</label>
                            <input type="text" id="transaction_reference" name="transaction_reference" required
                                placeholder="e.g., T20231226-001" value="<?php echo isset($_POST['transaction_reference']) ? htmlspecialchars($_POST['transaction_reference']) : ''; ?>">
                        </div>
                    </div>

                    <div class="form-row" id="bank_details_section" style="display: none;">
                        <div class="form-group">
                            <label for="bank_name"><i class="fas fa-university"></i> Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name"
                                placeholder="e.g., GTBank, Zenith Bank" value="<?php echo isset($_POST['bank_name']) ? htmlspecialchars($_POST['bank_name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="account_name"><i class="fas fa-user-tag"></i> Account Name</label>
                            <input type="text" id="account_name" name="account_name"
                                placeholder="Name on account" value="<?php echo isset($_POST['account_name']) ? htmlspecialchars($_POST['account_name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="account_number"><i class="fas fa-hashtag"></i> Account Number</label>
                            <input type="text" id="account_number" name="account_number"
                                placeholder="10-digit account number" value="<?php echo isset($_POST['account_number']) ? htmlspecialchars($_POST['account_number']) : ''; ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_date"><i class="fas fa-calendar-day"></i> Payment Date *</label>
                            <input type="date" id="payment_date" name="payment_date" required
                                value="<?php echo isset($_POST['payment_date']) ? htmlspecialchars($_POST['payment_date']) : date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="payment_time"><i class="fas fa-clock"></i> Payment Time</label>
                            <input type="time" id="payment_time" name="payment_time"
                                value="<?php echo isset($_POST['payment_time']) ? htmlspecialchars($_POST['payment_time']) : date('H:i'); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="whatsapp_sender"><i class="fab fa-whatsapp"></i> WhatsApp Sender</label>
                            <input type="text" id="whatsapp_sender" name="whatsapp_sender"
                                placeholder="Phone number or name from WhatsApp"
                                value="<?php echo isset($_POST['whatsapp_sender']) ? htmlspecialchars($_POST['whatsapp_sender']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="whatsapp_message_date"><i class="fas fa-comment-dots"></i> WhatsApp Message Date</label>
                            <input type="datetime-local" id="whatsapp_message_date" name="whatsapp_message_date"
                                value="<?php echo isset($_POST['whatsapp_message_date']) ? htmlspecialchars($_POST['whatsapp_message_date']) : date('Y-m-d\TH:i'); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="description"><i class="fas fa-align-left"></i> Additional Notes</label>
                            <textarea id="description" name="description" rows="3"
                                placeholder="Additional notes about this payment..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                            <i class="fas fa-redo"></i> Clear Form
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Financial Record
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Manual Payments List -->
        <div class="manual-payments-list">
            <div class="section-header">
                <h3><i class="fas fa-list"></i> Manual Financial Records</h3>
                <div class="manual-filters">
                    <form method="GET" action="" class="inline-form">
                        <select name="record_type" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <option value="academic" <?php echo isset($_GET['record_type']) && $_GET['record_type'] == 'academic' ? 'selected' : ''; ?>>Academic Only</option>
                            <option value="non_academic" <?php echo isset($_GET['record_type']) && $_GET['record_type'] == 'non_academic' ? 'selected' : ''; ?>>Non-Academic Only</option>
                        </select>
                        <select name="manual_status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo isset($_GET['manual_status']) && $_GET['manual_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="verified" <?php echo isset($_GET['manual_status']) && $_GET['manual_status'] == 'verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="rejected" <?php echo isset($_GET['manual_status']) && $_GET['manual_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </form>
                </div>
            </div>

            <?php if (!empty($manual_payments)): ?>
                <div class="payments-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Client/Student</th>
                                <th>Service/Program</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manual_payments as $mp): ?>
                                <tr>
                                    <td>
                                        <span class="record-type-badge badge-<?php echo $mp['record_type']; ?>">
                                            <?php echo $mp['record_type'] === 'academic' ? 'Academic' : 'Non-Academic'; ?>
                                        </span>
                                        <br>
                                        <small><?php echo ucfirst(str_replace('_', ' ', $mp['payment_type'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($mp['record_type'] === 'academic'): ?>
                                            <strong><?php echo htmlspecialchars($mp['student_first_name'] . ' ' . $mp['student_last_name']); ?></strong>
                                            <br>
                                            <small><?php echo htmlspecialchars($mp['student_email']); ?></small>
                                        <?php else: ?>
                                            <strong><?php echo htmlspecialchars($mp['client_name']); ?></strong>
                                            <?php if ($mp['client_email']): ?>
                                                <br><small><?php echo htmlspecialchars($mp['client_email']); ?></small>
                                            <?php endif; ?>
                                            <?php if ($mp['service_category']): ?>
                                                <br><small><?php echo ucfirst(str_replace('_', ' ', $mp['service_category'])); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($mp['record_type'] === 'academic'): ?>
                                            <?php if ($mp['program_name']): ?>
                                                <?php echo htmlspecialchars($mp['program_name']); ?>
                                                <?php if ($mp['course_title']): ?>
                                                    <br><small><?php echo htmlspecialchars($mp['course_title']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($mp['batch_code']): ?>
                                                    <br><small>Batch: <?php echo htmlspecialchars($mp['batch_code']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <em>Not specified</em>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($mp['service_description']): ?>
                                                <?php echo substr(htmlspecialchars($mp['service_description']), 0, 50); ?>...
                                            <?php else: ?>
                                                <em>No description</em>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>₦<?php echo number_format($mp['amount'], 2); ?></strong>
                                        <br>
                                        <small>Ref: <?php echo htmlspecialchars($mp['transaction_reference']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo ucfirst(str_replace('_', ' ', $mp['payment_method'])); ?>
                                        <?php if ($mp['bank_name']): ?>
                                            <br><small><?php echo htmlspecialchars($mp['bank_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($mp['payment_date'])); ?>
                                        <?php if ($mp['payment_time']): ?>
                                            <br><small><?php echo date('g:i A', strtotime($mp['payment_time'])); ?></small>
                                        <?php endif; ?>
                                        <br>
                                        <small><?php echo date('M j', strtotime($mp['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $mp['status']; ?>">
                                            <?php echo ucfirst($mp['status']); ?>
                                        </span>
                                        <?php if ($mp['verification_id']): ?>
                                            <br>
                                            <small>Verification: <?php echo ucfirst($mp['verification_status']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($mp['status'] === 'pending' && $mp['record_type'] === 'academic' && $mp['verification_status'] === 'pending'): ?>
                                                <a href="verify.php?source=manual&id=<?php echo $mp['verification_id'] ?: $mp['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-check"></i> Verify
                                                </a>
                                            <?php elseif ($mp['verification_id'] && $mp['verification_status'] === 'pending'): ?>
                                                <a href="verify.php?id=<?php echo $mp['verification_id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No financial records</h3>
                    <p>No manual financial entries found matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <div class="mobile-menu-buttons">
            <a href="index.php" class="mobile-menu-btn">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="manual_entry.php" class="mobile-menu-btn active">
                <i class="fas fa-plus-circle"></i>
                <span>New Entry</span>
            </a>
            <a href="verify.php" class="mobile-menu-btn">
                <i class="fas fa-check-circle"></i>
                <span>Verify</span>
            </a>
        </div>
    </div>

    <script>
        // Record type toggle
        document.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const recordType = this.dataset.type;
                
                // Update toggle buttons
                document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update hidden field
                document.getElementById('record_type').value = recordType;
                
                // Show/hide appropriate fields
                if (recordType === 'academic') {
                    document.getElementById('academic_fields').style.display = 'block';
                    document.getElementById('non_academic_fields').style.display = 'none';
                    
                    // Set required fields
                    document.getElementById('student_id').required = true;
                    document.getElementById('client_name').required = false;
                    document.getElementById('service_category').required = false;
                    document.getElementById('service_description').required = false;
                    
                    // Update payment type select
                    document.getElementById('non_academic_payment_type').name = 'payment_type_temp';
                    document.getElementById('payment_type').name = 'payment_type';
                } else {
                    document.getElementById('academic_fields').style.display = 'none';
                    document.getElementById('non_academic_fields').style.display = 'block';
                    
                    // Set required fields
                    document.getElementById('student_id').required = false;
                    document.getElementById('client_name').required = true;
                    document.getElementById('service_category').required = true;
                    document.getElementById('service_description').required = true;
                    
                    // Update payment type select
                    document.getElementById('payment_type').name = 'payment_type_temp';
                    document.getElementById('non_academic_payment_type').name = 'payment_type';
                }
            });
        });

        // Service category selection
        document.querySelectorAll('.category-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selection from all options
                document.querySelectorAll('.category-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Select this option
                this.classList.add('selected');
                
                // Update hidden field
                document.getElementById('service_category').value = this.dataset.category;
            });
        });

        // Toggle bank details based on payment method
        function toggleBankDetails() {
            const paymentMethod = document.getElementById('payment_method');
            const bankDetailsSection = document.getElementById('bank_details_section');

            if (paymentMethod.value === 'bank_transfer') {
                bankDetailsSection.style.display = 'flex';
            } else {
                bankDetailsSection.style.display = 'none';
            }
        }

        // Toggle academic fields based on payment type
        function toggleAcademicFields() {
            const paymentType = document.getElementById('payment_type');
            const academicProgramFields = document.getElementById('academic_program_fields');

            if (paymentType.value === 'registration') {
                academicProgramFields.style.display = 'none';
                // Clear program, course and class selections
                document.getElementById('program_id').value = '';
                document.getElementById('course_id').value = '';
                document.getElementById('class_id').value = '';
            } else {
                academicProgramFields.style.display = 'flex';
            }
        }

        // Load courses based on selected program using AJAX
        function loadCourses() {
            const programId = document.getElementById('program_id').value;
            const courseSelect = document.getElementById('course_id');
            const classSelect = document.getElementById('class_id');

            if (!programId) {
                courseSelect.innerHTML = '<option value="">Select Course</option>';
                classSelect.innerHTML = '<option value="">Select Class</option>';
                return;
            }

            // Clear current options
            courseSelect.innerHTML = '<option value="">Loading...</option>';
            classSelect.innerHTML = '<option value="">Select Class</option>';

            // Make AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `?ajax=1&load_courses=1&program_id=${programId}`, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const courses = JSON.parse(xhr.responseText);
                        courseSelect.innerHTML = '<option value="">Select Course</option>';
                        courses.forEach(course => {
                            const option = document.createElement('option');
                            option.value = course.id;
                            option.textContent = `${course.course_code} - ${course.title}`;
                            courseSelect.appendChild(option);
                        });
                    } catch (e) {
                        courseSelect.innerHTML = '<option value="">Error loading courses</option>';
                        console.error('Error parsing courses:', e);
                    }
                } else {
                    courseSelect.innerHTML = '<option value="">Error loading courses</option>';
                }
            };
            xhr.send();
        }

        // Load classes based on selected course using AJAX
        function loadClasses() {
            const courseId = document.getElementById('course_id').value;
            const classSelect = document.getElementById('class_id');

            if (!courseId) {
                classSelect.innerHTML = '<option value="">Select Class</option>';
                return;
            }

            // Clear current options
            classSelect.innerHTML = '<option value="">Loading...</option>';

            // Make AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `?ajax=1&load_classes=1&course_id=${courseId}`, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const classes = JSON.parse(xhr.responseText);
                        classSelect.innerHTML = '<option value="">Select Class</option>';
                        classes.forEach(cls => {
                            const option = document.createElement('option');
                            option.value = cls.id;
                            option.textContent = `${cls.batch_code} - ${cls.name}`;
                            classSelect.appendChild(option);
                        });
                    } catch (e) {
                        classSelect.innerHTML = '<option value="">Error loading classes</option>';
                        console.error('Error parsing classes:', e);
                    }
                } else {
                    classSelect.innerHTML = '<option value="">Error loading classes</option>';
                }
            };
            xhr.send();
        }

        // Reset form
        function resetForm() {
            document.getElementById('manualEntryForm').reset();
            toggleBankDetails();
            toggleAcademicFields();
            
            // Reset category selection
            document.querySelectorAll('.category-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById('service_category').value = '';
            
            // Reset to academic view
            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            document.querySelector('.toggle-btn[data-type="academic"]').classList.add('active');
            document.getElementById('record_type').value = 'academic';
            document.getElementById('academic_fields').style.display = 'block';
            document.getElementById('non_academic_fields').style.display = 'none';
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleBankDetails();
            toggleAcademicFields();

            // Auto-hide message alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.display = 'none';
                });
            }, 5000);

            // Form validation
            document.getElementById('manualEntryForm')?.addEventListener('submit', function(e) {
                const amount = document.getElementById('amount');
                if (amount && parseFloat(amount.value) <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid amount greater than 0.');
                    amount.focus();
                }

                const transactionRef = document.getElementById('transaction_reference');
                if (transactionRef && !transactionRef.value.trim()) {
                    e.preventDefault();
                    alert('Transaction reference is required.');
                    transactionRef.focus();
                }

                // Validate record type specific fields
                const recordType = document.getElementById('record_type').value;
                
                if (recordType === 'academic') {
                    const studentId = document.getElementById('student_id');
                    if (!studentId.value) {
                        e.preventDefault();
                        alert('Student selection is required for academic records.');
                        studentId.focus();
                    }
                    
                    const paymentType = document.getElementById('payment_type');
                    if (paymentType.value !== 'registration') {
                        const programId = document.getElementById('program_id');
                        const courseId = document.getElementById('course_id');
                        
                        if (!programId.value) {
                            e.preventDefault();
                            alert('Program selection is required for academic payments.');
                            programId.focus();
                        } else if (!courseId.value) {
                            e.preventDefault();
                            alert('Course selection is required for academic payments.');
                            courseId.focus();
                        }
                    }
                } else {
                    const clientName = document.getElementById('client_name');
                    if (!clientName.value.trim()) {
                        e.preventDefault();
                        alert('Client name is required for non-academic records.');
                        clientName.focus();
                    }
                    
                    const serviceCategory = document.getElementById('service_category');
                    if (!serviceCategory.value) {
                        e.preventDefault();
                        alert('Please select a service category.');
                        return false;
                    }
                    
                    const serviceDescription = document.getElementById('service_description');
                    if (!serviceDescription.value.trim()) {
                        e.preventDefault();
                        alert('Service description is required.');
                        serviceDescription.focus();
                    }
                }
            });

            // Set today's date by default
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('payment_date').value = today;
        });

        // Mobile menu active state
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.mobile-menu-btn').forEach(btn => {
                const href = btn.getAttribute('href');
                if (href && href.includes(currentPage)) {
                    btn.classList.add('active');
                }
            });
        });
    </script>
</body>

</html>