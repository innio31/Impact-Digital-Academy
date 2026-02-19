<?php
// modules/student/finance/requests/waiver.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get user details
$user_id = $_SESSION['user_id'];
$student_details = [];
$sql = "SELECT u.*, up.* FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? AND u.role = 'student'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $student_details = $result->fetch_assoc();
    }
    $stmt->close();
}

if (empty($student_details)) {
    header('Location: ' . BASE_URL . 'modules/auth/logout.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = ''; // success, error, warning
$enrolled_classes = [];
$waiver_history = [];
$pending_requests = 0;
$current_date = date('Y-m-d');

// Get student's financial status with class and course details
$sql = "SELECT sfs.*, 
               e.status as enrollment_status,
               cb.*, 
               c.id as course_id, c.title as course_title, c.course_code,
               p.id as program_id, p.name as program_name, p.program_code,
               p.fee as program_fee, p.registration_fee,
               cf.fee as course_fee
        FROM student_financial_status sfs
        JOIN enrollments e ON sfs.student_id = e.student_id AND sfs.class_id = e.class_id
        JOIN class_batches cb ON sfs.class_id = cb.id
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN course_fees cf ON c.id = cf.course_id AND p.id = cf.program_id
        WHERE sfs.student_id = ? 
        AND e.status IN ('active', 'completed')
        AND sfs.balance > 0
        ORDER BY cb.start_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Calculate course fee (program fee in the system)
            $course_fee = floatval($row['course_fee'] ?? 0);
            if ($course_fee == 0) {
                // Fallback to program fee if course fee not set
                $course_fee = floatval($row['program_fee'] ?? 0);
            }
            
            $registration_fee = floatval($row['registration_fee'] ?? 0);
            $total_fee = $course_fee + $registration_fee;
            $paid_amount = floatval($row['paid_amount'] ?? 0);
            $balance = floatval($row['balance'] ?? 0);
            
            // Only show courses with outstanding course fee balance
            // Registration fee is not eligible for waiver
            $course_fee_balance = $balance;
            
            $row['course_fee'] = $course_fee;
            $row['total_fee'] = $total_fee;
            $row['balance'] = $balance;
            $row['course_fee_balance'] = $course_fee_balance;
            $row['registration_paid'] = $row['registration_paid'] ?? 0;
            
            $enrolled_classes[] = $row;
        }
    }
    $stmt->close();
}

// Get student's waiver history
$waiver_history_sql = "SELECT fw.*, 
                              cb.batch_code, cb.name as class_name,
                              c.title as course_title, c.course_code,
                              p.name as program_name, 
                              u.first_name, u.last_name,
                              CASE 
                                  WHEN fw.waiver_type = 'full' THEN CONCAT('Full Waiver (100%)')
                                  WHEN fw.waiver_type = 'percentage' THEN CONCAT(fw.waiver_value, '% Waiver')
                                  ELSE CONCAT('₦', FORMAT(fw.waiver_value, 2), ' Waiver')
                              END as waiver_display,
                              CASE 
                                  WHEN fw.status = 'approved' THEN 'Approved'
                                  WHEN fw.status = 'rejected' THEN 'Rejected'
                                  WHEN fw.status = 'expired' THEN 'Expired'
                                  ELSE 'Pending Review'
                              END as status_display
                       FROM fee_waivers fw
                       LEFT JOIN class_batches cb ON fw.class_id = cb.id
                       LEFT JOIN courses c ON fw.course_id = c.id
                       LEFT JOIN programs p ON fw.program_id = p.id
                       LEFT JOIN users u ON fw.approved_by = u.id
                       WHERE fw.student_id = ?
                       ORDER BY fw.created_at DESC";
$waiver_history_stmt = $conn->prepare($waiver_history_sql);
$waiver_history_stmt->bind_param("i", $user_id);
$waiver_history_stmt->execute();
$waiver_history_result = $waiver_history_stmt->get_result();
while ($waiver = $waiver_history_result->fetch_assoc()) {
    $waiver_history[] = $waiver;
}
$waiver_history_stmt->close();

// Count pending requests
$pending_sql = "SELECT COUNT(*) as pending_count 
                FROM fee_waivers 
                WHERE student_id = ? AND status = 'pending'";
$pending_stmt = $conn->prepare($pending_sql);
$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();
if ($pending_row = $pending_result->fetch_assoc()) {
    $pending_requests = $pending_row['pending_count'];
}
$pending_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_waiver_request'])) {
    // Debug: Check what's being submitted
    error_log("POST data: " . print_r($_POST, true));
    
    // Validate required fields
    $required_fields = ['class_id', 'waiver_type', 'reason', 'terms'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || (empty($_POST[$field]) && $_POST[$field] !== '0')) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        $message = "Please fill in all required fields: " . implode(', ', $missing_fields);
        $message_type = 'error';
    } else {
        // Sanitize input
        $class_id = intval($_POST['class_id']);
        $waiver_type = $_POST['waiver_type'];
        $waiver_value = isset($_POST['waiver_value']) ? floatval($_POST['waiver_value']) : 0;
        $reason = trim($_POST['reason']);
        $supporting_docs = isset($_POST['supporting_docs']) ? trim($_POST['supporting_docs']) : '';
        $terms = $_POST['terms'] === 'on' ? 1 : 0;
        
        // Validate waiver value based on type
        if ($waiver_type === 'percentage' && ($waiver_value <= 0 || $waiver_value > 100)) {
            $message = "Percentage waiver must be between 1% and 100%";
            $message_type = 'error';
        } elseif ($waiver_type === 'fixed_amount' && $waiver_value <= 0) {
            $message = "Fixed amount must be greater than 0";
            $message_type = 'error';
        } elseif (!$terms) {
            $message = "You must accept the terms and conditions";
            $message_type = 'error';
        } else {
            // Check if student has financial status for this class
            $enrollment_check = false;
            $course_fee = 0;
            $course_fee_balance = 0;
            $fee_structure_id = null;
            $course_id = null;
            $program_id = null;
            $course_title = '';
            
            foreach ($enrolled_classes as $class) {
                if ($class['id'] == $class_id) {
                    $enrollment_check = true;
                    $course_fee = floatval($class['course_fee'] ?? 0);
                    $course_fee_balance = floatval($class['course_fee_balance'] ?? 0);
                    $course_id = $class['course_id'];
                    $program_id = $class['program_id'];
                    $course_title = $class['course_title'];
                    $batch_code = $class['batch_code'] ?? '';
                    
                    // Get the fee structure ID for this program
                    $fee_sql = "SELECT id FROM fee_structures 
                                WHERE program_id = ? AND is_active = 1 
                                LIMIT 1";
                    $fee_stmt = $conn->prepare($fee_sql);
                    $fee_stmt->bind_param("i", $program_id);
                    $fee_stmt->execute();
                    $fee_result = $fee_stmt->get_result();
                    if ($fee_result && $fee_result->num_rows > 0) {
                        $fee_structure = $fee_result->fetch_assoc();
                        $fee_structure_id = $fee_structure['id'];
                    } else {
                        // Create a default fee structure if none exists
                        $create_fee_sql = "INSERT INTO fee_structures 
                                          (program_id, name, total_amount, registration_fee, 
                                           block1_amount, block2_amount, block3_amount, is_active, created_at)
                                          VALUES (?, 'Default Fee Structure', ?, ?, ?, 0, 0, 1, NOW())";
                        $create_fee_stmt = $conn->prepare($create_fee_sql);
                        if ($create_fee_stmt) {
                            $total_amount = $course_fee;
                            $registration_fee = floatval($class['registration_fee'] ?? 0);
                            $block1_amount = $course_fee;
                            
                            $create_fee_stmt->bind_param("iddd", 
                                $program_id, 
                                $total_amount,
                                $registration_fee,
                                $block1_amount
                            );
                            
                            if ($create_fee_stmt->execute()) {
                                $fee_structure_id = $create_fee_stmt->insert_id;
                            }
                            $create_fee_stmt->close();
                        }
                    }
                    $fee_stmt->close();
                    break;
                }
            }
            
            if (!$enrollment_check) {
                $message = "You are not enrolled in the selected course";
                $message_type = 'error';
            } elseif ($course_fee_balance <= 0) {
                $message = "No outstanding course fee balance for this course";
                $message_type = 'error';
            } elseif (!$fee_structure_id) {
                $message = "No active fee structure found for this program and we couldn't create one automatically";
                $message_type = 'error';
            } else {
                // Calculate max waiver amount (only course fee, not registration fee)
                $max_waiver_amount = $course_fee_balance;
                $waiver_amount = 0;
                
                if ($waiver_type === 'full') {
                    $waiver_amount = $max_waiver_amount;
                    $waiver_value = $max_waiver_amount;
                } elseif ($waiver_type === 'percentage') {
                    $waiver_amount = ($max_waiver_amount * $waiver_value) / 100;
                } else { // fixed_amount
                    $waiver_amount = $waiver_value;
                    if ($waiver_amount > $max_waiver_amount) {
                        $message = "Waiver amount cannot exceed outstanding course fee of ₦" . number_format($max_waiver_amount, 2);
                        $message_type = 'error';
                    }
                }
                
                if (empty($message)) {
                    // Check for existing pending waiver for same class
                    $existing_sql = "SELECT id FROM fee_waivers 
                                     WHERE student_id = ? 
                                     AND class_id = ? 
                                     AND status = 'pending'";
                    $existing_stmt = $conn->prepare($existing_sql);
                    $existing_stmt->bind_param("ii", $user_id, $class_id);
                    $existing_stmt->execute();
                    $existing_result = $existing_stmt->get_result();
                    
                    if ($existing_result && $existing_result->num_rows > 0) {
                        $message = "You already have a pending waiver request for this class";
                        $message_type = 'warning';
                        $existing_stmt->close();
                    } else {
                        $existing_stmt->close();
                        
                        // Prepare reason with supporting docs
                        $reason_with_docs = $reason;
                        if (!empty($supporting_docs)) {
                            $reason_with_docs .= "\n\nSupporting Documents Information:\n" . $supporting_docs;
                        }
                        
                        // Insert waiver request
                        $insert_sql = "INSERT INTO fee_waivers 
                                      (student_id, class_id, course_id, program_id, fee_structure_id,
                                       waiver_type, waiver_value, reason, 
                                       applicable_blocks, expiry_date, status, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'all', 
                                              DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pending', NOW())";
                        
                        error_log("Insert SQL: $insert_sql");
                        error_log("Params: user_id=$user_id, class_id=$class_id, course_id=$course_id, program_id=$program_id, fee_structure_id=$fee_structure_id, waiver_type=$waiver_type, waiver_value=$waiver_value");
                        
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("iiiiisds", 
                                $user_id, 
                                $class_id,
                                $course_id,
                                $program_id,
                                $fee_structure_id,
                                $waiver_type, 
                                $waiver_value, 
                                $reason_with_docs
                            );
                            
                            if ($insert_stmt->execute()) {
                                $waiver_id = $insert_stmt->insert_id;
                                
                                error_log("Waiver request inserted successfully with ID: $waiver_id");
                                
                                // Log the waiver request
                                if (function_exists('logActivity')) {
                                    logActivity($user_id, 'waiver_request_submitted', 
                                        "Student submitted fee waiver request for course: $course_title (ID: $course_id, Class: $class_id)", 
                                        $_SERVER['REMOTE_ADDR']);
                                }
                                
                                // Send notification to admin
                                $notification_sql = "INSERT INTO internal_messages 
                                                    (sender_id, receiver_id, subject, message, 
                                                     message_type, priority, created_at)
                                                    VALUES (?, ?, ?, ?, 'system_reminder', 'high', NOW())";
                                $notification_stmt = $conn->prepare($notification_sql);
                                
                                // Get admin users
                                $admin_sql = "SELECT id FROM users WHERE role = 'admin' AND status = 'active'";
                                $admin_result = $conn->query($admin_sql);
                                
                                if ($admin_result && $admin_result->num_rows > 0) {
                                    $student_name = htmlspecialchars($student_details['first_name'] . ' ' . $student_details['last_name']);
                                    $subject = "New Course Fee Waiver Request";
                                    $message_content = "Student $student_name has submitted a course fee waiver request for Course: $course_title (ID: $course_id, Class: $class_id). Please review it in the admin panel.";
                                    
                                    while ($admin = $admin_result->fetch_assoc()) {
                                        $notification_stmt->bind_param("iiss", 
                                            $user_id, 
                                            $admin['id'],
                                            $subject,
                                            $message_content
                                        );
                                        $notification_stmt->execute();
                                    }
                                    $notification_stmt->close();
                                }
                                
                                $message = "Course fee waiver request submitted successfully! Your request is now pending review by administration.";
                                $message_type = 'success';
                                
                                // Clear form data
                                $_POST = [];
                                
                                // Refresh waiver history
                                $waiver_history_stmt = $conn->prepare($waiver_history_sql);
                                $waiver_history_stmt->bind_param("i", $user_id);
                                $waiver_history_stmt->execute();
                                $waiver_history_result = $waiver_history_stmt->get_result();
                                $waiver_history = [];
                                while ($waiver = $waiver_history_result->fetch_assoc()) {
                                    $waiver_history[] = $waiver;
                                }
                                $waiver_history_stmt->close();
                                
                                // Update pending count
                                $pending_sql = "SELECT COUNT(*) as pending_count 
                                                FROM fee_waivers 
                                                WHERE student_id = ? AND status = 'pending'";
                                $pending_stmt = $conn->prepare($pending_sql);
                                $pending_stmt->bind_param("i", $user_id);
                                $pending_stmt->execute();
                                $pending_result = $pending_stmt->get_result();
                                if ($pending_row = $pending_result->fetch_assoc()) {
                                    $pending_requests = $pending_row['pending_count'];
                                }
                                $pending_stmt->close();
                                
                            } else {
                                $message = "Error submitting waiver request: " . $conn->error;
                                $message_type = 'error';
                                error_log("Database error: " . $conn->error);
                            }
                            $insert_stmt->close();
                        } else {
                            $message = "Database error preparing statement: " . $conn->error;
                            $message_type = 'error';
                            error_log("Prepare error: " . $conn->error);
                        }
                    }
                }
            }
        }
    }
}

// Get fee structures for the student's programs
$program_ids = array_unique(array_column($enrolled_classes, 'program_id'));
$fee_structures = [];

if (!empty($program_ids)) {
    $program_ids_str = implode(',', $program_ids);
    
    $fee_structure_sql = "SELECT fs.*, p.name as program_name, p.program_code
                         FROM fee_structures fs
                         JOIN programs p ON fs.program_id = p.id
                         WHERE fs.program_id IN ($program_ids_str)
                         AND fs.is_active = 1
                         ORDER BY p.name";
    $fee_structure_result = $conn->query($fee_structure_sql);
    if ($fee_structure_result) {
        while ($structure = $fee_structure_result->fetch_assoc()) {
            $fee_structures[] = $structure;
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Fee Waiver - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/student.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --warning: #f72585;
            --danger: #e63946;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #adb5bd;
            --border: #dee2e6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            text-decoration: none;
            color: var(--primary);
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.1);
            border-left: 4px solid var(--success);
            color: var(--dark);
        }

        .alert-error {
            background-color: rgba(230, 57, 70, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--dark);
        }

        .alert-warning {
            background-color: rgba(247, 37, 133, 0.1);
            border-left: 4px solid var(--warning);
            color: var(--dark);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: #3da8d5;
        }

        .btn-secondary {
            background-color: var(--gray-light);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background-color: #9fa5ac;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background-color: white;
            cursor: pointer;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-help {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .waiver-type-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .waiver-type-options {
                grid-template-columns: 1fr;
            }
        }

        .waiver-type-option {
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .waiver-type-option:hover {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }

        .waiver-type-option.selected {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.1);
        }

        .waiver-type-option input[type="radio"] {
            display: none;
        }

        .waiver-type-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .waiver-type-desc {
            font-size: 0.75rem;
            color: var(--gray);
        }

        .amount-input-group {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.5rem;
            align-items: center;
        }

        .amount-prefix {
            padding: 0.75rem;
            background-color: var(--light);
            border: 1px solid var(--border);
            border-radius: 6px 0 0 6px;
            font-weight: 600;
            color: var(--dark);
        }

        .amount-input {
            border-radius: 0 6px 6px 0;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background-color: #f8f9fa;
        }

        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--warning);
        }

        .status-approved {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success);
        }

        .status-rejected {
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger);
        }

        .status-expired {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--gray);
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .info-box {
            background-color: rgba(72, 149, 239, 0.1);
            border-left: 4px solid var(--info);
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }

        .info-box p {
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .info-box ul {
            margin-left: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .info-box li {
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }

        .program-summary {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .program-summary h4 {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .program-summary p {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
        }

        .loading i {
            font-size: 2rem;
            color: var(--primary);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .fee-breakdown {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .fee-item:last-child {
            border-bottom: none;
        }

        .fee-label {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .fee-value {
            font-weight: 600;
            color: var(--dark);
        }

        .total-fee {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/student/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
            <a href="<?php echo BASE_URL; ?>modules/student/finance/dashboard.php">
                Financial Overview
            </a>
            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
            <span>Request Fee Waiver</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Request Fee Waiver</h1>
            <p>Apply for a fee waiver or discount on your program fees</p>
            <?php if ($pending_requests > 0): ?>
                <div style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--warning);">
                    <i class="fas fa-clock"></i> You have <?php echo $pending_requests; ?> pending waiver request(s) being reviewed
                </div>
            <?php endif; ?>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                <div style="flex: 1;"><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <div class="content-grid">
            <!-- Left Column: Waiver Request Form -->
            <div class="left-column">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">New Waiver Request</h2>
                    </div>

                    <div class="info-box">
                        <p><strong>Important Information:</strong></p>
                        <ul>
                            <li>Fee waivers are subject to approval by administration</li>
                            <li>Typically processed within 3-5 business days</li>
                            <li>You will be notified via email and dashboard notification</li>
                            <li>Approved waivers will be automatically applied to your balance</li>
                        </ul>
                        <p style="margin-top: 0.5rem; color: var(--warning);">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Note:</strong> You cannot request a waiver for fees that are already paid
                        </p>
                    </div>

                    <form id="waiverForm" method="POST" action="">
                        <!-- Program Selection -->
                        <div class="form-group">
                            <label class="form-label">
                                Select Class <span class="required">*</span>
                            </label>
                            <select name="class_id" id="class_id" class="form-select" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($enrolled_classes as $class): 
                                    $balance = $class['balance'] ?? 0;
                                    $registration_paid = $class['registration_paid'] ?? 0;
                                    $registration_fee = floatval($class['registration_fee'] ?? 0);
                                    
                                    // Only show programs with outstanding balance
                                    if ($balance > 0 || $registration_paid == 0): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                                data-fee="<?php echo $class['program_fee']; ?>"
                                                data-registration-fee="<?php echo $registration_fee; ?>"
                                                data-total-fee="<?php echo $class['total_fee']; ?>"
                                                data-balance="<?php echo $balance; ?>"
                                                data-registration-paid="<?php echo $registration_paid; ?>"
                                                data-program-id="<?php echo $class['program_id']; ?>"
                                                data-course-id="<?php echo $class['course_id']; ?>">
                                            <?php echo htmlspecialchars($class['program_name']); ?> 
                                            - <?php echo htmlspecialchars($class['course_title']); ?>
                                            <?php if (!empty($class['batch_code'])): ?>
                                                (<?php echo htmlspecialchars($class['batch_code']); ?>)
                                            <?php endif; ?>
                                            - Balance: ₦<?php echo number_format($balance, 2); ?>
                                        </option>
                                    <?php endif;
                                endforeach; ?>
                            </select>
                            <div class="form-help">
                                Only classes with outstanding balance are shown
                            </div>
                        </div>

                        <!-- Program Summary -->
                        <div id="programSummary" class="program-summary" style="display: none;">
                            <h4>Program Fee Summary</h4>
                            <div class="fee-breakdown">
                                <div class="fee-item">
                                    <span class="fee-label">Registration Fee:</span>
                                    <span class="fee-value" id="registrationFee">₦0.00</span>
                                </div>
                                <div class="fee-item">
                                    <span class="fee-label">Course/Program Fee:</span>
                                    <span class="fee-value" id="courseFee">₦0.00</span>
                                </div>
                                <div class="fee-item">
                                    <span class="fee-label">Total Fee:</span>
                                    <span class="fee-value total-fee" id="totalFee">₦0.00</span>
                                </div>
                                <div class="fee-item">
                                    <span class="fee-label">Current Balance:</span>
                                    <span class="fee-value" id="currentBalance" style="color: var(--warning);">₦0.00</span>
                                </div>
                                <div class="fee-item">
                                    <span class="fee-label">Registration Status:</span>
                                    <span class="fee-value" id="registrationStatus">Not Paid</span>
                                </div>
                            </div>
                        </div>

                        <!-- Waiver Type Selection -->
                        <div class="form-group">
                            <label class="form-label">
                                Waiver Type <span class="required">*</span>
                            </label>
                            <div class="waiver-type-options">
                                <label class="waiver-type-option" for="waiver_full">
                                    <input type="radio" id="waiver_full" name="waiver_type" value="full" required>
                                    <div class="waiver-type-title">Full Waiver</div>
                                    <div class="waiver-type-desc">100% of fees waived</div>
                                </label>
                                <label class="waiver-type-option" for="waiver_percentage">
                                    <input type="radio" id="waiver_percentage" name="waiver_type" value="percentage" required>
                                    <div class="waiver-type-title">Percentage</div>
                                    <div class="waiver-type-desc">Partial percentage waiver</div>
                                </label>
                                <label class="waiver-type-option" for="waiver_fixed">
                                    <input type="radio" id="waiver_fixed" name="waiver_type" value="fixed_amount" required>
                                    <div class="waiver-type-title">Fixed Amount</div>
                                    <div class="waiver-type-desc">Specific amount waived</div>
                                </label>
                            </div>
                        </div>

                        <!-- Waiver Amount (for percentage and fixed) -->
                        <div class="form-group" id="waiverAmountGroup" style="display: none;">
                            <label class="form-label">
                                <span id="amountLabel">Waiver Amount</span> <span class="required">*</span>
                            </label>
                            <div class="amount-input-group">
                                <span class="amount-prefix" id="amountPrefix">₦</span>
                                <input type="number" 
                                       name="waiver_value" 
                                       id="waiver_value" 
                                       class="form-control amount-input" 
                                       min="0" 
                                       step="0.01"
                                       placeholder="Enter amount">
                            </div>
                            <div class="form-help" id="amountHelp">
                                Enter the waiver amount
                            </div>
                        </div>

                        <!-- Reason for Waiver -->
                        <div class="form-group">
                            <label class="form-label">
                                Reason for Waiver Request <span class="required">*</span>
                            </label>
                            <textarea name="reason" id="reason" class="form-control form-textarea" 
                                      placeholder="Please explain why you are requesting a fee waiver. Include any relevant details that will help in the review process." 
                                      required><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                            <div class="form-help">
                                Be specific about your circumstances (financial hardship, academic merit, special circumstances, etc.)
                            </div>
                        </div>

                        <!-- Supporting Documents -->
                        <div class="form-group">
                            <label class="form-label">Supporting Documents (Optional)</label>
                            <textarea name="supporting_docs" id="supporting_docs" class="form-control form-textarea" 
                                      placeholder="List any supporting documents you can provide (e.g., income statement, recommendation letter, etc.). You may be asked to submit these documents later."><?php echo isset($_POST['supporting_docs']) ? htmlspecialchars($_POST['supporting_docs']) : ''; ?></textarea>
                            <div class="form-help">
                                Note: Large files should not be uploaded here. Just describe what documents you can provide.
                            </div>
                        </div>

                        <!-- Terms and Conditions -->
                        <div class="form-group">
                            <div style="padding: 1rem; background-color: #f8f9fa; border-radius: 6px; margin-bottom: 1rem;">
                                <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="terms" id="terms" required style="margin-top: 0.25rem;">
                                    <div>
                                        <strong>Terms & Conditions</strong>
                                        <div style="font-size: 0.875rem; color: var(--gray); margin-top: 0.25rem;">
                                            I understand that:
                                            <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                                                <li>This request is subject to approval</li>
                                                <li>Approval is not guaranteed</li>
                                                <li>I must provide truthful information</li>
                                                <li>I may be asked for additional documentation</li>
                                                <li>Approved waivers are valid for current enrollment only</li>
                                            </ul>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="form-group">
                            <button type="submit" name="submit_waiver_request" class="btn btn-primary" style="width: 100%; padding: 0.875rem;">
                                <i class="fas fa-paper-plane"></i> Submit Waiver Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Waiver History & Guidelines -->
            <div class="right-column">
                <!-- Waiver History -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Waiver History</h2>
                        <span class="status-badge <?php echo $pending_requests > 0 ? 'status-pending' : 'status-approved'; ?>">
                            <?php echo $pending_requests > 0 ? $pending_requests . ' Pending' : 'No Pending'; ?>
                        </span>
                    </div>

                    <?php if (empty($waiver_history)): ?>
                        <div class="no-data">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <p>No waiver requests found</p>
                            <p style="font-size: 0.875rem; margin-top: 0.5rem;">
                                Submit your first waiver request using the form
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Program/Class</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($waiver_history as $waiver): 
                                        $created_date = date('M d, Y', strtotime($waiver['created_at']));
                                        $status_class = 'status-' . $waiver['status'];
                                    ?>
                                        <tr>
                                            <td><?php echo $created_date; ?></td>
                                            <td>
                                                <div style="font-weight: 600; font-size: 0.875rem;">
                                                    <?php echo htmlspecialchars($waiver['program_name'] ?? 'Program'); ?>
                                                </div>
                                                <div style="font-size: 0.75rem; color: var(--gray);">
                                                    <?php echo htmlspecialchars($waiver['course_title'] ?? ''); ?>
                                                    <?php if (!empty($waiver['batch_code'])): ?>
                                                        (<?php echo htmlspecialchars($waiver['batch_code']); ?>)
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-size: 0.75rem;">
                                                    <?php echo $waiver['waiver_display']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($waiver['waiver_type'] === 'full'): ?>
                                                    100%
                                                <?php elseif ($waiver['waiver_type'] === 'percentage'): ?>
                                                    <?php echo $waiver['waiver_value']; ?>%
                                                <?php else: ?>
                                                    ₦<?php echo number_format($waiver['waiver_value'], 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $waiver['status_display']; ?>
                                                </span>
                                                <?php if ($waiver['status'] === 'approved' && !empty($waiver['first_name'])): ?>
                                                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem;">
                                                        By: <?php echo htmlspecialchars($waiver['first_name'] . ' ' . $waiver['last_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Waiver Guidelines -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Waiver Guidelines</h2>
                    </div>
                    <div style="padding: 1rem 0;">
                        <h4 style="font-size: 0.875rem; margin-bottom: 0.75rem; color: var(--dark);">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            Eligible Circumstances
                        </h4>
                        <ul style="margin-left: 1.5rem; margin-bottom: 1.5rem; font-size: 0.875rem;">
                            <li>Financial hardship or change in circumstances</li>
                            <li>Academic excellence or merit-based consideration</li>
                            <li>Special needs or disability accommodations</li>
                            <li>Family member also enrolled in the academy</li>
                            <li>Early payment or bulk payment discounts</li>
                        </ul>

                        <h4 style="font-size: 0.875rem; margin-bottom: 0.75rem; color: var(--dark);">
                            <i class="fas fa-times-circle" style="color: var(--danger); margin-right: 0.5rem;"></i>
                            Not Eligible For
                        </h4>
                        <ul style="margin-left: 1.5rem; margin-bottom: 1.5rem; font-size: 0.875rem;">
                            <li>Fees that are already paid in full</li>
                            <li>Late fees or penalty charges</li>
                            <li>Previous terms or blocks</li>
                            <li>Non-academic fees</li>
                        </ul>

                        <div style="background-color: rgba(76, 201, 240, 0.1); padding: 1rem; border-radius: 6px;">
                            <h4 style="font-size: 0.875rem; margin-bottom: 0.5rem; color: var(--success);">
                                <i class="fas fa-lightbulb"></i> Tips for Approval
                            </h4>
                            <ul style="margin-left: 1.5rem; font-size: 0.875rem;">
                                <li>Provide detailed explanation of your situation</li>
                                <li>Be honest and transparent in your request</li>
                                <li>Submit any supporting documentation when asked</li>
                                <li>Apply early - before payment deadlines</li>
                                <li>Follow up politely if no response in 5 days</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Need Help?</h2>
                    </div>
                    <div style="padding: 1rem 0;">
                        <p style="font-size: 0.875rem; margin-bottom: 1rem;">
                            If you have questions about the waiver process or need assistance with your application:
                        </p>
                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                            <div style="width: 40px; height: 40px; background-color: rgba(67, 97, 238, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-envelope" style="color: var(--primary);"></i>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem;">Email Support</div>
                                <div style="font-size: 0.75rem; color: var(--gray);">finance@impactdigitalacademy.com</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="width: 40px; height: 40px; background-color: rgba(247, 37, 133, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-phone" style="color: var(--warning);"></i>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem;">Phone Support</div>
                                <div style="font-size: 0.75rem; color: var(--gray);">+234 812 345 6789</div>
                            </div>
                        </div>
                        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                            <a href="<?php echo BASE_URL; ?>modules/student/support/ticket.php" class="btn btn-secondary" style="width: 100%;">
                                <i class="fas fa-headset"></i> Submit Support Ticket
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Program selection change handler
        document.getElementById('class_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const programSummary = document.getElementById('programSummary');
            
            if (this.value) {
                const fee = parseFloat(selectedOption.getAttribute('data-fee')) || 0;
                const regFee = parseFloat(selectedOption.getAttribute('data-registration-fee')) || 0;
                const totalFee = parseFloat(selectedOption.getAttribute('data-total-fee')) || 0;
                const balance = parseFloat(selectedOption.getAttribute('data-balance')) || 0;
                const regPaid = selectedOption.getAttribute('data-registration-paid') === '1';
                
                // Update program summary
                document.getElementById('registrationFee').textContent = '₦' + regFee.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                document.getElementById('courseFee').textContent = '₦' + fee.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                document.getElementById('totalFee').textContent = '₦' + totalFee.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                document.getElementById('currentBalance').textContent = '₦' + balance.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                document.getElementById('registrationStatus').textContent = regPaid ? 'Paid' : 'Not Paid';
                document.getElementById('registrationStatus').style.color = regPaid ? 'var(--success)' : 'var(--warning)';
                
                // Show program summary
                programSummary.style.display = 'block';
                
                // Update waiver value maximum
                updateWaiverMaxValue(totalFee);
            } else {
                programSummary.style.display = 'none';
            }
        });

        // Waiver type selection handlers
        const waiverTypeOptions = document.querySelectorAll('input[name="waiver_type"]');
        const waiverAmountGroup = document.getElementById('waiverAmountGroup');
        const amountLabel = document.getElementById('amountLabel');
        const amountPrefix = document.getElementById('amountPrefix');
        const amountHelp = document.getElementById('amountHelp');
        const waiverValueInput = document.getElementById('waiver_value');

        waiverTypeOptions.forEach(option => {
            option.addEventListener('change', function() {
                // Remove selected class from all options
                document.querySelectorAll('.waiver-type-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Add selected class to clicked option
                this.closest('.waiver-type-option').classList.add('selected');
                
                // Show/hide amount input based on selection
                if (this.value === 'full') {
                    waiverAmountGroup.style.display = 'none';
                    waiverValueInput.value = '';
                    waiverValueInput.required = false;
                } else {
                    waiverAmountGroup.style.display = 'block';
                    waiverValueInput.required = true;
                    
                    if (this.value === 'percentage') {
                        amountLabel.textContent = 'Percentage Waiver';
                        amountPrefix.textContent = '%';
                        amountHelp.textContent = 'Enter percentage (1-100%)';
                        waiverValueInput.min = 1;
                        waiverValueInput.max = 100;
                        waiverValueInput.placeholder = 'Enter percentage (e.g., 25)';
                    } else { // fixed_amount
                        amountLabel.textContent = 'Fixed Amount Waiver';
                        amountPrefix.textContent = '₦';
                        amountHelp.textContent = 'Enter amount in Naira';
                        waiverValueInput.min = 1;
                        waiverValueInput.max = '';
                        waiverValueInput.placeholder = 'Enter amount (e.g., 50000)';
                    }
                    
                    // Update max value based on selected program
                    const classSelect = document.getElementById('class_id');
                    if (classSelect.value) {
                        const selectedOption = classSelect.options[classSelect.selectedIndex];
                        const totalFee = parseFloat(selectedOption.getAttribute('data-total-fee')) || 0;
                        updateWaiverMaxValue(totalFee);
                    }
                }
            });
        });

        function updateWaiverMaxValue(totalFee) {
            const selectedWaiverType = document.querySelector('input[name="waiver_type"]:checked');
            
            if (selectedWaiverType) {
                if (selectedWaiverType.value === 'percentage') {
                    waiverValueInput.max = 100;
                    waiverValueInput.placeholder = 'Max: 100%';
                } else if (selectedWaiverType.value === 'fixed_amount') {
                    waiverValueInput.max = totalFee;
                    waiverValueInput.placeholder = 'Max: ₦' + totalFee.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
            }
        }

        // Form validation
        document.getElementById('waiverForm').addEventListener('submit', function(e) {
            const classId = document.getElementById('class_id').value;
            const waiverType = document.querySelector('input[name="waiver_type"]:checked');
            const waiverValue = document.getElementById('waiver_value').value;
            const reason = document.getElementById('reason').value;
            const terms = document.getElementById('terms').checked;
            
            let errors = [];
            
            if (!classId) {
                errors.push('Please select a class');
            }
            
            if (!waiverType) {
                errors.push('Please select a waiver type');
            } else if (waiverType.value !== 'full') {
                if (!waiverValue) {
                    errors.push('Please enter waiver amount');
                } else if (waiverType.value === 'percentage') {
                    const value = parseFloat(waiverValue);
                    if (value < 1 || value > 100) {
                        errors.push('Percentage must be between 1% and 100%');
                    }
                } else if (waiverType.value === 'fixed_amount') {
                    const value = parseFloat(waiverValue);
                    if (value <= 0) {
                        errors.push('Amount must be greater than 0');
                    }
                    // Check if class is selected to validate against total fee
                    if (classId) {
                        const selectedOption = document.getElementById('class_id').options[document.getElementById('class_id').selectedIndex];
                        const totalFee = parseFloat(selectedOption.getAttribute('data-total-fee')) || 0;
                        if (value > totalFee) {
                            errors.push('Waiver amount cannot exceed total fee of ₦' + totalFee.toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }));
                        }
                    }
                }
            }
            
            if (!reason.trim()) {
                errors.push('Please provide a reason for the waiver request');
            }
            
            if (!terms) {
                errors.push('You must accept the terms and conditions');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Show loading state
            const submitBtn = document.querySelector('button[name="submit_waiver_request"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            submitBtn.disabled = true;
        });

        // Initialize form with any previously selected values
        document.addEventListener('DOMContentLoaded', function() {
            // Check if waiver type was previously selected
            const waiverType = document.querySelector('input[name="waiver_type"]:checked');
            if (waiverType) {
                waiverType.closest('.waiver-type-option').classList.add('selected');
                
                if (waiverType.value !== 'full') {
                    waiverAmountGroup.style.display = 'block';
                    waiverValueInput.required = true;
                    
                    if (waiverType.value === 'percentage') {
                        amountLabel.textContent = 'Percentage Waiver';
                        amountPrefix.textContent = '%';
                        amountHelp.textContent = 'Enter percentage (1-100%)';
                    } else {
                        amountLabel.textContent = 'Fixed Amount Waiver';
                        amountPrefix.textContent = '₦';
                        amountHelp.textContent = 'Enter amount in Naira';
                    }
                }
            }
            
            // Trigger class change if one is selected
            const classSelect = document.getElementById('class_id');
            if (classSelect.value) {
                classSelect.dispatchEvent(new Event('change'));
            }
        });

        // Auto-resize textareas
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });
    </script>
</body>
</html>