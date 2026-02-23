<?php
// modules/admin/academic/classes/enroll.php

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

// Get class ID
$class_id = $_GET['class_id'] ?? 0;

if (!$class_id) {
    $_SESSION['error'] = 'No class specified.';
    header('Location: list.php');
    exit();
}

// Fetch class details
$sql = "SELECT cb.*, c.title as course_title, c.course_code, p.name as program_name,
               p.program_type, COUNT(e.id) as current_enrollments
        FROM class_batches cb
        JOIN courses c ON cb.course_id = c.id
        JOIN programs p ON c.program_id = p.id
        LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
        WHERE cb.id = ?
        GROUP BY cb.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();

if (!$class) {
    $_SESSION['error'] = 'Class not found.';
    header('Location: list.php');
    exit();
}

// Check if class is full
$is_full = $class['current_enrollments'] >= $class['max_students'];

// Fetch available students (not already enrolled in this class)
$available_students = [];
$search_term = '';
$program_type_filter = $class['program_type']; // Filter by same program type

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle form submission
    $csrf_token = $_POST['csrf_token'] ?? '';
    $student_ids = $_POST['student_ids'] ?? [];

    if (!validateCSRFToken($csrf_token)) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    if (empty($student_ids)) {
        $_SESSION['error'] = 'Please select at least one student to enroll.';
    } elseif ($is_full) {
        $_SESSION['error'] = 'Class is at maximum capacity. Cannot enroll more students.';
    } else {
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        // Begin transaction
        $conn->begin_transaction();

        try {
            foreach ($student_ids as $student_id) {
                // Debug: Log current operation
                error_log("Processing enrollment for student ID: " . $student_id);

                // Check if student is already enrolled
                $check_sql = "SELECT id FROM enrollments 
                      WHERE student_id = ? AND class_id = ? AND status != 'dropped'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('ii', $student_id, $class_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $errors[] = "Student #$student_id is already enrolled in this class.";
                    $error_count++;
                    continue;
                }

                // Check class capacity
                $current_count = $class['current_enrollments'] + $success_count;
                if ($current_count >= $class['max_students']) {
                    $errors[] = "Class capacity reached. Only enrolled $success_count student(s).";
                    $error_count++;
                    break;
                }

                // Get term/block based on program type
                $term_id = null;
                $block_id = null;

                if ($class['program_type'] === 'onsite') {
                    $term_sql = "SELECT id FROM academic_periods 
                        WHERE program_type = 'onsite' 
                        AND period_type = 'term'
                        AND start_date <= CURDATE() 
                        AND end_date >= CURDATE()
                        AND status = 'active'
                        LIMIT 1";
                    $term_result = $conn->query($term_sql);
                    if ($term_result && $term_result->num_rows > 0) {
                        $term = $term_result->fetch_assoc();
                        $term_id = $term['id'];
                    }
                } else {
                    $block_sql = "SELECT id FROM academic_periods 
                         WHERE program_type = 'online' 
                         AND period_type = 'block'
                         AND start_date <= CURDATE() 
                         AND end_date >= CURDATE()
                         AND status = 'active'
                         LIMIT 1";
                    $block_result = $conn->query($block_sql);
                    if ($block_result && $block_result->num_rows > 0) {
                        $block = $block_result->fetch_assoc();
                        $block_id = $block['id'];
                    }
                }

                // Map program_type for enrollments table
                $enrollment_program_type = $class['program_type'];
                if ($enrollment_program_type === 'school') {
                    $enrollment_program_type = 'online'; // or 'onsite' based on your needs
                }

                $attendance_mode = $class['program_type'] === 'onsite' ? 'physical' : 'virtual';
                if ($class['program_type'] === 'school') {
                    $attendance_mode = 'physical'; // adjust as needed
                }

                // Debug: Log the values before insertion
                error_log("Attempting enrollment with values:");
                error_log("student_id: " . $student_id . " (type: " . gettype($student_id) . ")");
                error_log("class_id: " . $class_id . " (type: " . gettype($class_id) . ")");
                error_log("program_type: " . $enrollment_program_type . " (type: " . gettype($enrollment_program_type) . ")");
                error_log("attendance_mode: " . $attendance_mode . " (type: " . gettype($attendance_mode) . ")");

                // Enroll student
                $enrollment_sql = "INSERT INTO enrollments 
                          (student_id, class_id, enrollment_date, status, 
                           program_type, attendance_mode, created_at, updated_at)
                          VALUES (?, ?, CURDATE(), 'active', ?, ?, NOW(), NOW())";

                $enroll_stmt = $conn->prepare($enrollment_sql);
                if (!$enroll_stmt) {
                    throw new Exception("Prepare failed for enrollment: " . $conn->error);
                }

                $enroll_stmt->bind_param(
                    'iiss',
                    $student_id,
                    $class_id,
                    $enrollment_program_type,
                    $attendance_mode
                );

                if ($enroll_stmt->execute()) {
                    $enrollment_id = $conn->insert_id;
                    error_log("Enrollment successful. Enrollment ID: " . $enrollment_id);

                    // Get program fee
                    $fee_sql = "SELECT p.fee FROM class_batches cb
                       JOIN courses c ON cb.course_id = c.id
                       JOIN programs p ON c.program_id = p.id
                       WHERE cb.id = ?";
                    $fee_stmt = $conn->prepare($fee_sql);
                    $fee_stmt->bind_param('i', $class_id);
                    $fee_stmt->execute();
                    $fee_result = $fee_stmt->get_result();
                    $fee_data = $fee_result->fetch_assoc();
                    $program_fee = $fee_data['fee'] ?? 0.00;

                    error_log("Program fee retrieved: " . $program_fee);

                    // Create financial status record
                    $financial_sql = "INSERT INTO student_financial_status 
                             (student_id, class_id, total_fee, paid_amount, balance, current_block, 
                              created_at, updated_at)
                             VALUES (?, ?, ?, 0, ?, 1, NOW(), NOW())";

                    $financial_stmt = $conn->prepare($financial_sql);
                    if (!$financial_stmt) {
                        throw new Exception("Prepare failed for financial status: " . $conn->error);
                    }

                    // Balance equals total_fee initially
                    $balance = $program_fee;
                    $current_block = 1;

                    error_log("Financial insert values:");
                    error_log("student_id: " . $student_id);
                    error_log("class_id: " . $class_id);
                    error_log("total_fee: " . $program_fee);
                    error_log("balance: " . $balance);
                    error_log("current_block: " . $current_block);

                    $financial_stmt->bind_param(
                        'iiddi',  // i=integer, i=integer, d=double, d=double, i=integer
                        $student_id,
                        $class_id,
                        $program_fee,
                        $balance,
                        $current_block
                    );

                    if ($financial_stmt->execute()) {
                        // Log activity
                        logActivity(
                            $_SESSION['user_id'],
                            'enrollment_create',
                            "Enrolled student #$student_id in class #$class_id",
                            'enrollments',
                            $enrollment_id
                        );

                        $success_count++;
                        error_log("Financial status created successfully for student " . $student_id);
                    } else {
                        $error_msg = "Failed to create financial record for student #$student_id: " . $financial_stmt->error;
                        error_log($error_msg);
                        $errors[] = $error_msg;
                        $error_count++;
                    }
                } else {
                    $error_msg = "Failed to enroll student #$student_id: " . $enroll_stmt->error;
                    error_log($error_msg);
                    $errors[] = $error_msg;
                    $error_count++;
                }
            }

            // Commit transaction
            $conn->commit();
            error_log("Transaction committed successfully");

            if ($success_count > 0) {
                $_SESSION['success'] = "Successfully enrolled $success_count student(s).";
                header('Location: view.php?id=' . $class_id);
                exit();
            }

            if (!empty($errors)) {
                $_SESSION['error'] = implode('<br>', $errors);
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();

            // Log detailed error information
            error_log("========== ENROLLMENT ERROR ==========");
            error_log("Exception message: " . $e->getMessage());
            error_log("Exception code: " . $e->getCode());
            error_log("Exception file: " . $e->getFile());
            error_log("Exception line: " . $e->getLine());
            error_log("Exception trace: " . $e->getTraceAsString());

            // Check MySQL error
            if (isset($conn)) {
                error_log("MySQL error number: " . $conn->errno);
                error_log("MySQL error: " . $conn->error);
            }

            error_log("========== END ERROR ==========");

            $_SESSION['error'] = "An error occurred during enrollment: " . $e->getMessage();
        }
    }
} else {
    // GET request - handle search/filter
    $search_term = $_GET['search'] ?? '';
    $program_type_filter = $_GET['program_type'] ?? $class['program_type'];
}

// Fetch available students based on filters
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status,
               up.date_of_birth, up.gender, up.city, up.state,
               COUNT(e.id) as total_enrollments,
               GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as enrolled_programs
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN enrollments e ON u.id = e.student_id AND e.status = 'active'
        LEFT JOIN class_batches cb ON e.class_id = cb.id
        LEFT JOIN courses c ON cb.course_id = c.id
        LEFT JOIN programs p ON c.program_id = p.id
        WHERE u.role = 'student' 
        AND u.status = 'active'
        AND u.id NOT IN (
            SELECT student_id FROM enrollments 
            WHERE class_id = ? AND status IN ('active', 'completed')
        )";

$params = [$class_id];
$types = 'i';

// Add search filter
if (!empty($search_term)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search_term%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssss';
}

// Add program type filter (only show students compatible with class program type)
if ($program_type_filter) {
    $sql .= " AND u.id NOT IN (
                SELECT DISTINCT e2.student_id 
                FROM enrollments e2 
                JOIN class_batches cb2 ON e2.class_id = cb2.id 
                JOIN courses c2 ON cb2.course_id = c2.id 
                JOIN programs p2 ON c2.program_id = p2.id 
                WHERE e2.status IN ('active', 'completed') 
                AND p2.program_type != ? 
                AND e2.class_id != ?
            )";
    $params[] = $program_type_filter;
    $params[] = $class_id;
    $types .= 'si';
}

$sql .= " GROUP BY u.id
          ORDER BY u.first_name, u.last_name
          LIMIT 100";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$available_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll Students - <?php echo htmlspecialchars($class['batch_code'] . ' - ' . $class['name']); ?></title>
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
            --info: #3b82f6;
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

        .class-info {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-header h2 {
            font-size: 1.4rem;
            color: var(--dark);
        }

        .class-code {
            color: #64748b;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .class-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .detail-item {
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 3px solid var(--primary);
        }

        .detail-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-weight: 500;
            color: var(--dark);
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-primary {
            background: #dbeafe;
            color: #1e40af;
        }

        .capacity-meter {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .capacity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .capacity-label {
            font-size: 0.9rem;
            color: #64748b;
        }

        .capacity-count {
            font-weight: 600;
            color: var(--primary);
        }

        .capacity-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .capacity-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .capacity-full .capacity-fill {
            background: var(--danger);
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .content-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
        }

        .search-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 300px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }

        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            background: white;
            color: var(--dark);
            min-width: 150px;
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

        .btn-success:hover {
            background: #0da271;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .student-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.875rem;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .student-name {
            font-weight: 500;
            color: var(--dark);
        }

        .student-email {
            font-size: 0.85rem;
            color: #64748b;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .checkbox-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #475569;
        }

        .bulk-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 6px;
            margin-top: 1rem;
            border: 1px solid #e2e8f0;
        }

        .selected-count {
            font-weight: 500;
            color: var(--primary);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
            margin-top: 1.5rem;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .page-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-btn:hover {
            background: #f8fafc;
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .student-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .stat-badge {
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 3px solid var(--primary);
        }

        .stat-value {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
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

            .search-container {
                flex-direction: column;
            }

            .search-box {
                min-width: 100%;
            }

            .class-header {
                flex-direction: column;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Checkbox styling */
        .checkbox-container {
            display: flex;
            align-items: center;
            position: relative;
            cursor: pointer;
        }

        .checkbox-container input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: relative;
            height: 18px;
            width: 18px;
            background-color: white;
            border: 2px solid #cbd5e1;
            border-radius: 3px;
            transition: all 0.2s;
        }

        .checkbox-container input:checked~.checkmark {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 5px;
            top: 2px;
            width: 5px;
            height: 8px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .checkbox-container input:checked~.checkmark:after {
            display: block;
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
                            <?php echo htmlspecialchars($class['batch_code']); ?>
                        </a> &rsaquo;
                        Enroll Students
                    </div>
                    <h1>Enroll Students</h1>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>"
                        class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Class
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if ($is_full): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    This class is at maximum capacity (<?php echo $class['max_students']; ?> students).
                    You cannot enroll more students until some students are removed or the capacity is increased.
                </div>
            <?php endif; ?>

            <!-- Class Information -->
            <div class="class-info">
                <div class="class-header">
                    <div>
                        <h2><?php echo htmlspecialchars($class['name']); ?></h2>
                        <div class="class-code">
                            <?php echo htmlspecialchars($class['batch_code']); ?>
                            <span class="badge badge-primary" style="margin-left: 0.5rem;">
                                <?php echo ucfirst($class['program_type']); ?> Program
                            </span>
                            <span class="badge badge-info" style="margin-left: 0.5rem;">
                                <?php echo htmlspecialchars($class['course_code']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="student-stats">
                        <div class="stat-badge">
                            <div class="stat-value"><?php echo $class['current_enrollments']; ?></div>
                            <div class="stat-label">Currently Enrolled</div>
                        </div>
                        <div class="stat-badge">
                            <div class="stat-value"><?php echo $class['max_students']; ?></div>
                            <div class="stat-label">Maximum Capacity</div>
                        </div>
                        <div class="stat-badge">
                            <div class="stat-value">
                                <?php echo $class['max_students'] - $class['current_enrollments']; ?>
                            </div>
                            <div class="stat-label">Available Slots</div>
                        </div>
                    </div>
                </div>

                <div class="class-details">
                    <div class="detail-item">
                        <div class="detail-label">Program</div>
                        <div class="detail-value"><?php echo htmlspecialchars($class['program_name']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Course</div>
                        <div class="detail-value"><?php echo htmlspecialchars($class['course_title']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Schedule</div>
                        <div class="detail-value"><?php echo $class['schedule'] ? nl2br(htmlspecialchars($class['schedule'])) : 'Not specified'; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Class Dates</div>
                        <div class="detail-value">
                            <?php echo date('M j, Y', strtotime($class['start_date'])); ?> -
                            <?php echo date('M j, Y', strtotime($class['end_date'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Capacity Meter -->
                <div class="capacity-meter <?php echo $is_full ? 'capacity-full' : ''; ?>">
                    <div class="capacity-header">
                        <div class="capacity-label">Class Capacity</div>
                        <div class="capacity-count">
                            <?php echo $class['current_enrollments']; ?> / <?php echo $class['max_students']; ?>
                            (<?php echo round(($class['current_enrollments'] / $class['max_students']) * 100); ?>%)
                        </div>
                    </div>
                    <div class="capacity-bar">
                        <div class="capacity-fill"
                            style="width: <?php echo min(100, ($class['current_enrollments'] / $class['max_students']) * 100); ?>%"></div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        <?php if ($is_full): ?>
                            <i class="fas fa-exclamation-circle"></i> Class is full
                        <?php else: ?>
                            <?php echo $class['max_students'] - $class['current_enrollments']; ?> slots available
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Student Selection Form -->
            <form method="POST" action="" id="enrollment-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus"></i> Select Students to Enroll</h3>
                        <div style="font-size: 0.9rem; color: #64748b;">
                            <i class="fas fa-info-circle"></i>
                            Showing students available for <?php echo ucfirst($class['program_type']); ?> programs
                        </div>
                    </div>

                    <div class="card-body">
                        <!-- Search and Filter -->
                        <div class="search-container">
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text"
                                    class="search-input"
                                    id="student-search"
                                    placeholder="Search students by name, email, or phone..."
                                    value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>

                            <select class="filter-select" id="program-filter" disabled>
                                <option value="<?php echo $class['program_type']; ?>" selected>
                                    <?php echo ucfirst($class['program_type']); ?> Program Students Only
                                </option>
                            </select>

                            <button type="button" class="btn btn-secondary" onclick="searchStudents()">
                                <i class="fas fa-search"></i> Search
                            </button>

                            <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>

                        <!-- Available Students Table -->
                        <div class="table-container">
                            <?php if (!empty($available_students)): ?>
                                <table id="students-table">
                                    <thead>
                                        <tr>
                                            <th class="checkbox-cell">
                                                <label class="checkbox-container">
                                                    <input type="checkbox" id="select-all" <?php echo $is_full ? 'disabled' : ''; ?>>
                                                    <span class="checkmark"></span>
                                                </label>
                                            </th>
                                            <th>Student</th>
                                            <th>Contact</th>
                                            <th>Details</th>
                                            <th>Current Enrollments</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($available_students as $student):
                                            $initials = strtoupper(
                                                substr($student['first_name'], 0, 1) .
                                                    substr($student['last_name'], 0, 1)
                                            );
                                            $age = $student['date_of_birth'] ?
                                                date('Y') - date('Y', strtotime($student['date_of_birth'])) : null;
                                        ?>
                                            <tr>
                                                <td class="checkbox-cell">
                                                    <label class="checkbox-container">
                                                        <input type="checkbox"
                                                            name="student_ids[]"
                                                            value="<?php echo $student['id']; ?>"
                                                            class="student-checkbox"
                                                            <?php echo $is_full ? 'disabled' : ''; ?>
                                                            onchange="updateSelectionCount()">
                                                        <span class="checkmark"></span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <div class="student-cell">
                                                        <div class="student-avatar">
                                                            <?php echo $initials; ?>
                                                        </div>
                                                        <div>
                                                            <div class="student-name">
                                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                            </div>
                                                            <div class="student-email">
                                                                ID: <?php echo $student['id']; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($student['email']); ?></div>
                                                    <?php if ($student['phone']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b;">
                                                            <?php echo htmlspecialchars($student['phone']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($student['gender'] || $age): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b;">
                                                            <?php if ($student['gender']): ?>
                                                                <?php echo ucfirst($student['gender']); ?>
                                                            <?php endif; ?>
                                                            <?php if ($age): ?>
                                                                <?php if ($student['gender']) echo ' • '; ?>
                                                                <?php echo $age; ?> years
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($student['city'] || $student['state']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b;">
                                                            <?php echo htmlspecialchars($student['city'] ?? ''); ?>
                                                            <?php if ($student['city'] && $student['state']) echo ', '; ?>
                                                            <?php echo htmlspecialchars($student['state'] ?? ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div><?php echo $student['total_enrollments']; ?> active enrollments</div>
                                                    <?php if ($student['enrolled_programs']): ?>
                                                        <div style="font-size: 0.85rem; color: #64748b;">
                                                            <?php echo htmlspecialchars($student['enrolled_programs']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $student['id']; ?>"
                                                        class="btn btn-secondary btn-sm"
                                                        target="_blank">
                                                        <i class="fas fa-eye"></i> View Profile
                                                    </a>
                                                    <button type="button"
                                                        class="btn btn-primary btn-sm"
                                                        onclick="quickEnroll(<?php echo $student['id']; ?>)"
                                                        <?php echo $is_full ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-user-plus"></i> Quick Enroll
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <h3>No Available Students Found</h3>
                                    <p>
                                        <?php if (!empty($search_term)): ?>
                                            No students match your search criteria. Try a different search term.
                                        <?php else: ?>
                                            All active students are already enrolled in this class or enrolled in incompatible program types.
                                        <?php endif; ?>
                                    </p>
                                    <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1rem;">
                                        <a href="<?php echo BASE_URL; ?>modules/admin/users/create.php"
                                            class="btn btn-primary" target="_blank">
                                            <i class="fas fa-user-plus"></i> Create New Student
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>modules/admin/users/manage.php?role=student"
                                            class="btn btn-secondary" target="_blank">
                                            <i class="fas fa-users"></i> Manage All Students
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Bulk Actions -->
                        <?php if (!empty($available_students)): ?>
                            <div class="bulk-actions" id="bulk-actions" style="display: none;">
                                <div class="selected-count">
                                    <span id="selected-count">0</span> student(s) selected
                                </div>
                                <div style="flex: 1;"></div>
                                <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                    <i class="fas fa-times"></i> Clear Selection
                                </button>
                                <button type="button" class="btn btn-primary" onclick="selectAllStudents()">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <a href="<?php echo BASE_URL; ?>modules/admin/academic/classes/view.php?id=<?php echo $class_id; ?>"
                                    class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit"
                                    class="btn btn-success"
                                    id="enroll-btn"
                                    <?php echo $is_full ? 'disabled' : ''; ?>>
                                    <i class="fas fa-user-plus"></i> Enroll Selected Students
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Update selection count
        function updateSelectionCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const selectedCount = checkboxes.length;
            const bulkActions = document.getElementById('bulk-actions');

            document.getElementById('selected-count').textContent = selectedCount;

            if (selectedCount > 0) {
                bulkActions.style.display = 'flex';
            } else {
                bulkActions.style.display = 'none';
            }

            // Update select all checkbox
            const totalCheckboxes = document.querySelectorAll('.student-checkbox:not(:disabled)').length;
            const selectAllCheckbox = document.getElementById('select-all');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
                selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
            }
        }

        // Select all students
        function selectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectionCount();
        }

        // Clear all selections
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectionCount();
        }

        // Quick enroll single student
        function quickEnroll(studentId) {
            if (confirm('Enroll this student in the class?')) {
                // Uncheck all checkboxes
                clearSelection();

                // Check this student's checkbox
                const checkbox = document.querySelector(`.student-checkbox[value="${studentId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    updateSelectionCount();

                    // Scroll to enroll button
                    document.getElementById('enroll-btn').scrollIntoView({
                        behavior: 'smooth'
                    });

                    // Show confirmation
                    alert('Student selected. Click "Enroll Selected Students" to complete enrollment.');
                }
            }
        }

        // Search students
        function searchStudents() {
            const searchTerm = document.getElementById('student-search').value.trim();
            const programFilter = document.getElementById('program-filter').value;

            let url = `enroll.php?class_id=<?php echo $class_id; ?>`;

            if (searchTerm) {
                url += `&search=${encodeURIComponent(searchTerm)}`;
            }

            if (programFilter) {
                url += `&program_type=${encodeURIComponent(programFilter)}`;
            }

            window.location.href = url;
        }

        // Clear search
        function clearSearch() {
            window.location.href = `enroll.php?class_id=<?php echo $class_id; ?>`;
        }

        // Initialize select all checkbox
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateSelectionCount();
                });
            }

            // Initialize selection count
            updateSelectionCount();

            // Add search on Enter key
            const searchInput = document.getElementById('student-search');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchStudents();
                    }
                });
            }

            // Form submission validation
            const form = document.getElementById('enrollment-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const checkboxes = document.querySelectorAll('.student-checkbox:checked');

                    if (checkboxes.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one student to enroll.');
                        return false;
                    }

                    // Show loading state
                    const enrollBtn = document.getElementById('enroll-btn');
                    const originalText = enrollBtn.innerHTML;
                    enrollBtn.innerHTML = '<div class="loading"></div> Enrolling...';
                    enrollBtn.disabled = true;

                    // Allow form to submit
                    return true;
                });
            }

            // Filter table rows based on search
            const searchInput2 = document.getElementById('student-search');
            if (searchInput2) {
                searchInput2.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#students-table tbody tr');

                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('student-search');
                if (searchInput) {
                    searchInput.focus();
                }
            }

            // Ctrl/Cmd + A to select all (when not in input field)
            if ((e.ctrlKey || e.metaKey) && e.key === 'a' &&
                e.target.type !== 'text' && e.target.type !== 'textarea') {
                e.preventDefault();
                selectAllStudents();
            }

            // Escape to clear selection
            if (e.key === 'Escape') {
                clearSelection();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>