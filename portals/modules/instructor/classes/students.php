<?php
// modules/instructor/classes/students.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in and is instructor
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Get class ID from URL
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    header('Location: index.php');
    exit();
}

$class_id = (int)$_GET['class_id'];

// Get database connection
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Get class details and verify instructor access
$sql = "SELECT cb.*, c.title as course_title, c.course_code,
               p.name as program_name,
               CONCAT(u.first_name, ' ', u.last_name) as instructor_name
        FROM class_batches cb 
        JOIN courses c ON cb.course_id = c.id 
        JOIN programs p ON c.program_id = p.id 
        JOIN users u ON cb.instructor_id = u.id 
        WHERE cb.id = ? AND cb.instructor_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $instructor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit();
}

$class = $result->fetch_assoc();
$stmt->close();

// Initialize search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'active';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build base query for students
$base_sql = "SELECT 
                e.id as enrollment_id,
                e.enrollment_date,
                e.status as enrollment_status,
                e.final_grade,
                e.completion_date,
                e.certificate_issued,
                u.id as student_id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.profile_image,
                up.date_of_birth,
                up.gender,
                up.city,
                up.state,
                sfs.paid_amount,
                sfs.balance,
                sfs.is_cleared,
                sfs.is_suspended
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
            WHERE e.class_id = ?";

$params = [$class_id];
$types = "i";

// Add status filter
if ($status !== 'all') {
    $base_sql .= " AND e.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Add search filter
if (!empty($search)) {
    $base_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

// Get total count for pagination (using same WHERE conditions)
$count_sql = "SELECT COUNT(*) as total 
              FROM enrollments e 
              JOIN users u ON e.student_id = u.id 
              LEFT JOIN user_profiles up ON u.id = up.user_id 
              LEFT JOIN student_financial_status sfs ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
              WHERE e.class_id = ?";

$count_params = [$class_id];
$count_types = "i";

if ($status !== 'all') {
    $count_sql .= " AND e.status = ?";
    $count_params[] = $status;
    $count_types .= "s";
}

if (!empty($search)) {
    $count_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_types .= "sss";
}

$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'];
$count_stmt->close();

// Add sorting to main query
$sort_columns = [
    'name' => 'u.last_name, u.first_name',
    'email' => 'u.email',
    'enrollment_date' => 'e.enrollment_date',
    'status' => 'e.status',
    'grade' => 'e.final_grade',
    'balance' => 'sfs.balance'
];

$sort_column = $sort_columns[$sort] ?? 'u.last_name, u.first_name';
$sql = $base_sql . " ORDER BY {$sort_column} {$order} LIMIT ? OFFSET ?";

// Add pagination parameters
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Execute main query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    // Bind parameters properly
    $bind_params = array_merge([$types], $params);
    $refs = [];
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate pagination
$total_pages = ceil($total_count / $limit);
$start_record = ($page - 1) * $limit + 1;
$end_record = min($page * $limit, $total_count);

// Handle actions (update status, grade, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status' && isset($_POST['student_id'])) {
        $student_id = (int)$_POST['student_id'];
        $new_status = $_POST['status'] ?? 'active';

        $sql = "UPDATE enrollments SET status = ? WHERE student_id = ? AND class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $new_status, $student_id, $class_id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Student status updated successfully.";

            // If status changed to suspended, update financial status
            if ($new_status === 'suspended') {
                $sql = "UPDATE student_financial_status SET is_suspended = 1, suspended_at = NOW() 
                        WHERE student_id = ? AND class_id = ?";
                $stmt2 = $conn->prepare($sql);
                $stmt2->bind_param("ii", $student_id, $class_id);
                $stmt2->execute();
                $stmt2->close();
            } elseif ($new_status === 'active') {
                $sql = "UPDATE student_financial_status SET is_suspended = 0, suspended_at = NULL 
                        WHERE student_id = ? AND class_id = ?";
                $stmt2 = $conn->prepare($sql);
                $stmt2->bind_param("ii", $student_id, $class_id);
                $stmt2->execute();
                $stmt2->close();
            }
        } else {
            $_SESSION['error_message'] = "Failed to update student status.";
        }
        $stmt->close();

        // Redirect to avoid form resubmission
        header("Location: students.php?class_id={$class_id}&page={$page}&search={$search}&status={$status}&sort={$sort}&order={$order}");
        exit();
    }

    if ($action === 'update_grade' && isset($_POST['student_id'])) {
        $student_id = (int)$_POST['student_id'];
        $final_grade = $_POST['final_grade'] ?? null;

        $sql = "UPDATE enrollments SET final_grade = ? WHERE student_id = ? AND class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $final_grade, $student_id, $class_id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Student grade updated successfully.";

            // If grade is provided and student completed, mark completion
            if ($final_grade && !empty($final_grade)) {
                $sql = "UPDATE enrollments SET completion_date = CURDATE() 
                        WHERE student_id = ? AND class_id = ? AND completion_date IS NULL";
                $stmt2 = $conn->prepare($sql);
                $stmt2->bind_param("ii", $student_id, $class_id);
                $stmt2->execute();
                $stmt2->close();
            }
        } else {
            $_SESSION['error_message'] = "Failed to update student grade.";
        }
        $stmt->close();

        header("Location: students.php?class_id={$class_id}&page={$page}&search={$search}&status={$status}&sort={$sort}&order={$order}");
        exit();
    }

    if ($action === 'bulk_action' && isset($_POST['selected_students'])) {
        $selected_ids = $_POST['selected_students'];
        $bulk_action = $_POST['bulk_action_type'] ?? '';

        if (!empty($selected_ids) && $bulk_action) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids));

            if ($bulk_action === 'send_message') {
                // Store selected students in session for message composition
                $_SESSION['bulk_message_recipients'] = $selected_ids;
                header("Location: send_message.php?class_id={$class_id}");
                exit();
            } elseif ($bulk_action === 'update_status') {
                $new_status = $_POST['bulk_status'] ?? 'active';
                $sql = "UPDATE enrollments SET status = ? 
                        WHERE student_id IN ({$placeholders}) AND class_id = ?";
                $stmt = $conn->prepare($sql);

                // Bind parameters
                $bind_params = array_merge([$new_status], $selected_ids, [$class_id]);
                $stmt->bind_param(str_repeat('s', 1) . $types . 'i', ...$bind_params);

                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Updated status for " . count($selected_ids) . " students.";
                } else {
                    $_SESSION['error_message'] = "Failed to update student status.";
                }
                $stmt->close();
            } elseif ($bulk_action === 'export') {
                // Export functionality would go here
                $_SESSION['success_message'] = "Export prepared for " . count($selected_ids) . " students.";
            }
        }

        header("Location: students.php?class_id={$class_id}&page={$page}&search={$search}&status={$status}&sort={$sort}&order={$order}");
        exit();
    }
}

// Get statistics for the class
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) as dropped,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
              FROM enrollments WHERE class_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $class_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$class_stats = $stats_result->fetch_assoc();
$stats_stmt->close();

/// Get attendance summary for the class
$attendance_sql = "SELECT 
                    e.student_id,
                    AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_rate
                   FROM enrollments e 
                   LEFT JOIN attendance a ON e.id = a.enrollment_id AND e.class_id = a.class_id
                   WHERE e.class_id = ? 
                   GROUP BY e.student_id";
$attendance_stmt = $conn->prepare($attendance_sql);
$attendance_stmt->bind_param("i", $class_id);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();
$attendance_rates = [];
while ($row = $attendance_result->fetch_assoc()) {
    $attendance_rates[$row['student_id']] = $row['attendance_rate'];
}
$attendance_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - <?php echo htmlspecialchars($class['batch_code']); ?> - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--primary);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .class-info h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .class-info p {
            color: var(--gray);
            font-size: 1.1rem;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
        }

        .nav-link {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
        }

        .stat-card.total {
            border-top-color: var(--primary);
        }

        .stat-card.active {
            border-top-color: var(--success);
        }

        .stat-card.completed {
            border-top-color: var(--info);
        }

        .stat-card.dropped {
            border-top-color: var(--warning);
        }

        .stat-card.suspended {
            border-top-color: var(--danger);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filters Card */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            padding-right: 3rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
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

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }

        /* Students Table */
        .students-table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            background: #f8fafc;
        }

        .table-header h2 {
            font-size: 1.25rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .students-table th {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .students-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .students-table tr:hover {
            background: #f8fafc;
        }

        .students-table tr:last-child td {
            border-bottom: none;
        }

        .checkbox-cell {
            width: 50px;
            text-align: center;
        }

        .student-info-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .student-details h4 {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .student-details p {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-dropped {
            background: #fef3c7;
            color: #92400e;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-pending {
            background: #f3f4f6;
            color: #4b5563;
        }

        .grade-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f3f4f6;
            color: var(--dark);
        }

        .grade-a {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-b {
            background: #d1fae5;
            color: #065f46;
        }

        .grade-c {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-d {
            background: #fef3c7;
            color: #92400e;
        }

        .grade-f {
            background: #fee2e2;
            color: #991b1b;
        }

        .attendance-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f3f4f6;
            color: var(--dark);
        }

        .attendance-high {
            background: #d1fae5;
            color: #065f46;
        }

        .attendance-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .attendance-low {
            background: #fee2e2;
            color: #991b1b;
        }

        .financial-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .financial-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .financial-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .financial-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
        }

        .pagination-info {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Modal */
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
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            opacity: 0.5;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .message-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Sort Links */
        .sort-link {
            color: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .sort-link:hover {
            color: var(--primary);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/instructor/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <span class="separator">/</span>
            <a href="index.php">
                <i class="fas fa-chalkboard"></i> My Classes
            </a>
            <span class="separator">/</span>
            <a href="class_home.php?id=<?php echo $class_id; ?>">
                <?php echo htmlspecialchars($class['batch_code']); ?>
            </a>
            <span class="separator">/</span>
            <span>Students</span>
        </div>

        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="class-info">
                    <h1><?php echo htmlspecialchars($class['batch_code']); ?> - Students</h1>
                    <p><?php echo htmlspecialchars($class['name']); ?></p>
                </div>
            </div>

            <!-- Navigation -->
            <div class="header-nav">
                <a href="class_home.php?id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="materials.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-file-alt"></i> Materials
                </a>
                <a href="assignments.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-tasks"></i> Assignments
                </a>
                <a href="quizzes.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-question-circle"></i> Quizzes
                </a>
                <a href="students.php?class_id=<?php echo $class_id; ?>" class="nav-link active">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
                <a href="gradebook.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-chart-line"></i> Gradebook
                </a>
                <a href="announcements.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-bullhorn"></i> Announcements
                </a>
                <a href="discussions.php?class_id=<?php echo $class_id; ?>" class="nav-link">
                    <i class="fas fa-comments"></i> Discussions
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']); ?></div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Class Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $class_stats['total']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card active">
                <div class="stat-value"><?php echo $class_stats['active']; ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-value"><?php echo $class_stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card dropped">
                <div class="stat-value"><?php echo $class_stats['dropped']; ?></div>
                <div class="stat-label">Dropped</div>
            </div>
            <div class="stat-card suspended">
                <div class="stat-value"><?php echo $class_stats['suspended']; ?></div>
                <div class="stat-label">Suspended</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="filters-form">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">

                <div class="form-group">
                    <label class="form-label" for="search">Search Students</label>
                    <input type="text" id="search" name="search" class="form-control"
                        placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control form-select">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="dropped" <?php echo $status === 'dropped' ? 'selected' : ''; ?>>Dropped</option>
                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sort">Sort By</label>
                    <select id="sort" name="sort" class="form-control form-select">
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="email" <?php echo $sort === 'email' ? 'selected' : ''; ?>>Email</option>
                        <option value="enrollment_date" <?php echo $sort === 'enrollment_date' ? 'selected' : ''; ?>>Enrollment Date</option>
                        <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                        <option value="grade" <?php echo $sort === 'grade' ? 'selected' : ''; ?>>Grade</option>
                        <option value="balance" <?php echo $sort === 'balance' ? 'selected' : ''; ?>>Balance</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="order">Order</label>
                    <select id="order" name="order" class="form-control form-select">
                        <option value="asc" <?php echo ($order === 'ASC') ? 'selected' : ''; ?>>Ascending</option>
                        <option value="desc" <?php echo ($order === 'DESC') ? 'selected' : ''; ?>>Descending</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Students Table -->
        <div class="students-table-container">
            <div class="table-header">
                <h2><i class="fas fa-user-graduate"></i> Students List</h2>

                <form method="POST" id="bulk-action-form" class="bulk-actions">
                    <input type="hidden" name="action" value="bulk_action">
                    <select name="bulk_action_type" class="form-control form-select" style="width: 200px;">
                        <option value="">Bulk Actions</option>
                        <option value="send_message">Send Message</option>
                        <option value="update_status">Update Status</option>
                        <option value="export">Export Selected</option>
                    </select>
                    <button type="button" id="apply-bulk-action" class="btn btn-primary">
                        <i class="fas fa-play"></i> Apply
                    </button>
                </form>
            </div>

            <div class="table-responsive">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Found</h3>
                        <p>No students match your current filters.</p>
                        <a href="students.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary mt-2">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th class="checkbox-cell">
                                    <input type="checkbox" id="select-all">
                                </th>
                                <th>
                                    <a href="?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=name&order=<?php echo $order === 'ASC' ? 'desc' : 'asc'; ?>" class="sort-link">
                                        Student
                                        <?php if ($sort === 'name'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($order); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=email&order=<?php echo $order === 'ASC' ? 'desc' : 'asc'; ?>" class="sort-link">
                                        Contact
                                        <?php if ($sort === 'email'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($order); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Attendance</th>
                                <th>Financial Status</th>
                                <th>
                                    <a href="?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=enrollment_date&order=<?php echo $order === 'ASC' ? 'desc' : 'asc'; ?>" class="sort-link">
                                        Enrollment
                                        <?php if ($sort === 'enrollment_date'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($order); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=status&order=<?php echo $order === 'ASC' ? 'desc' : 'asc'; ?>" class="sort-link">
                                        Status
                                        <?php if ($sort === 'status'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($order); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?class_id=<?php echo $class_id; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=grade&order=<?php echo $order === 'ASC' ? 'desc' : 'asc'; ?>" class="sort-link">
                                        Grade
                                        <?php if ($sort === 'grade'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($order); ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $attendance_rate = $attendance_rates[$student['student_id']] ?? 0;
                                $attendance_class = $attendance_rate >= 80 ? 'attendance-high' : ($attendance_rate >= 60 ? 'attendance-medium' : 'attendance-low');

                                $financial_status = $student['is_cleared'] ? 'paid' : ($student['paid_amount'] > 0 ? 'partial' : 'unpaid');
                                $financial_class = 'financial-' . $financial_status;

                                $grade_class = '';
                                if ($student['final_grade']) {
                                    $first_char = strtoupper(substr($student['final_grade'], 0, 1));
                                    if (in_array($first_char, ['A', 'B'])) $grade_class = 'grade-a';
                                    elseif (in_array($first_char, ['C', 'D'])) $grade_class = 'grade-c';
                                    elseif ($first_char === 'F') $grade_class = 'grade-f';
                                }
                            ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="selected_students[]" value="<?php echo $student['student_id']; ?>" class="student-checkbox">
                                    </td>
                                    <td>
                                        <div class="student-info-cell">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-details">
                                                <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                                <p>ID: <?php echo $student['student_id']; ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.875rem;">
                                            <div style="margin-bottom: 0.25rem;">
                                                <i class="fas fa-envelope" style="color: var(--gray); width: 16px;"></i>
                                                <?php echo htmlspecialchars($student['email']); ?>
                                            </div>
                                            <?php if ($student['phone']): ?>
                                                <div>
                                                    <i class="fas fa-phone" style="color: var(--gray); width: 16px;"></i>
                                                    <?php echo htmlspecialchars($student['phone']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="attendance-badge <?php echo $attendance_class; ?>">
                                            <?php echo number_format($attendance_rate, 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="financial-badge <?php echo $financial_class; ?>">
                                            <?php
                                            if ($financial_status === 'paid') echo 'Paid';
                                            elseif ($financial_status === 'partial') echo 'Partial';
                                            else echo 'Unpaid';
                                            ?>
                                        </span>
                                        <?php if ($student['balance'] > 0): ?>
                                            <div style="font-size: 0.75rem; color: var(--danger); margin-top: 0.25rem;">
                                                ₦<?php echo number_format($student['balance'], 2); ?> due
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.875rem;">
                                            <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?>
                                        </div>
                                        <?php if ($student['completion_date']): ?>
                                            <div style="font-size: 0.75rem; color: var(--success); margin-top: 0.25rem;">
                                                Completed: <?php echo date('M d, Y', strtotime($student['completion_date'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['enrollment_status']; ?>">
                                            <?php echo ucfirst($student['enrollment_status']); ?>
                                        </span>
                                        <?php if ($student['certificate_issued']): ?>
                                            <div style="font-size: 0.75rem; color: var(--success); margin-top: 0.25rem;">
                                                <i class="fas fa-certificate"></i> Certificate Issued
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($student['final_grade']): ?>
                                            <span class="grade-badge <?php echo $grade_class; ?>">
                                                <?php echo htmlspecialchars($student['final_grade']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="grade-badge">--</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-sm btn-primary" onclick="openStudentModal(<?php echo $student['student_id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="openGradeModal(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['final_grade'] ?? ''); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="student_profile.php?student_id=<?php echo $student['student_id']; ?>&class_id=<?php echo $class_id; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-user"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $start_record; ?> - <?php echo $end_record; ?> of <?php echo $total_count; ?> students
                    </div>
                    <div class="pagination-controls">
                        <a href="?class_id=<?php echo $class_id; ?>&page=1&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"
                            class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"
                            class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($start_page > 1) {
                            echo '<span class="page-link disabled">...</span>';
                        }

                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"
                                class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor;

                        if ($end_page < $total_pages) {
                            echo '<span class="page-link disabled">...</span>';
                        }
                        ?>

                        <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo min($total_pages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"
                            class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?class_id=<?php echo $class_id; ?>&page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>&order=<?php echo strtolower($order); ?>"
                            class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Export Options -->
        <div class="filters-card">
            <h3 style="margin-bottom: 1rem; color: var(--dark);">
                <i class="fas fa-download"></i> Export Options
            </h3>
            <div style="display: flex; gap: 1rem;">
                <a href="export_students.php?class_id=<?php echo $class_id; ?>&format=csv" class="btn btn-secondary">
                    <i class="fas fa-file-csv"></i> Export as CSV
                </a>
                <a href="export_students.php?class_id=<?php echo $class_id; ?>&format=pdf" class="btn btn-secondary">
                    <i class="fas fa-file-pdf"></i> Export as PDF
                </a>
                <a href="export_students.php?class_id=<?php echo $class_id; ?>&format=excel" class="btn btn-secondary">
                    <i class="fas fa-file-excel"></i> Export as Excel
                </a>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-graduate"></i> Student Details</h3>
                <button type="button" onclick="closeModal('studentModal')" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer;">
                    &times;
                </button>
            </div>
            <div class="modal-body" id="studentModalContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('studentModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Update Grade Modal -->
    <div id="gradeModal" class="modal">
        <div class="modal-content">
            <form method="POST" id="gradeForm">
                <div class="modal-header">
                    <h3><i class="fas fa-edit"></i> Update Student Grade</h3>
                    <button type="button" onclick="closeModal('gradeModal')" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer;">
                        &times;
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_grade">
                    <input type="hidden" name="student_id" id="grade_student_id">

                    <div class="form-group">
                        <label class="form-label" for="final_grade">Final Grade</label>
                        <select name="final_grade" id="final_grade" class="form-control form-select">
                            <option value="">-- Select Grade --</option>
                            <option value="A+">A+ (97-100)</option>
                            <option value="A">A (93-96)</option>
                            <option value="A-">A- (90-92)</option>
                            <option value="B+">B+ (87-89)</option>
                            <option value="B">B (83-86)</option>
                            <option value="B-">B- (80-82)</option>
                            <option value="C+">C+ (77-79)</option>
                            <option value="C">C (73-76)</option>
                            <option value="C-">C- (70-72)</option>
                            <option value="D+">D+ (67-69)</option>
                            <option value="D">D (63-66)</option>
                            <option value="D-">D- (60-62)</option>
                            <option value="F">F (Below 60)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('gradeModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Grade
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal (for bulk actions) -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Update Student Status</h3>
                <button type="button" onclick="closeModal('statusModal')" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer;">
                    &times;
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="bulk_status">New Status</label>
                    <select id="bulk_status" name="bulk_status" class="form-control form-select">
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="dropped">Dropped</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="applyBulkStatus()">
                    <i class="fas fa-play"></i> Apply to Selected
                </button>
            </div>
        </div>
    </div>

    <script>
        // Select/Deselect All Checkboxes
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Update select all checkbox when individual checkboxes change
        document.querySelectorAll('.student-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('.student-checkbox');
                const selectAll = document.getElementById('select-all');
                const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                selectAll.checked = allChecked;
            });
        });

        // Bulk Actions
        document.getElementById('apply-bulk-action').addEventListener('click', function() {
            const actionType = document.querySelector('[name="bulk_action_type"]').value;
            const selectedStudents = Array.from(document.querySelectorAll('.student-checkbox:checked'))
                .map(cb => cb.value);

            if (selectedStudents.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            if (actionType === 'update_status') {
                openModal('statusModal');
                return;
            }

            if (actionType === 'send_message') {
                // Store in form and submit
                const form = document.getElementById('bulk-action-form');
                const studentsInput = document.createElement('input');
                studentsInput.type = 'hidden';
                studentsInput.name = 'selected_students[]';
                studentsInput.value = selectedStudents.join(',');
                form.appendChild(studentsInput);
                form.submit();
            } else if (actionType === 'export') {
                // Handle export
                const url = `export_students.php?class_id=<?php echo $class_id; ?>&format=csv&students=${selectedStudents.join(',')}`;
                window.open(url, '_blank');
            }
        });

        function applyBulkStatus() {
            const selectedStudents = Array.from(document.querySelectorAll('.student-checkbox:checked'))
                .map(cb => cb.value);
            const newStatus = document.getElementById('bulk_status').value;

            if (selectedStudents.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_action';
            form.appendChild(actionInput);

            const bulkActionInput = document.createElement('input');
            bulkActionInput.type = 'hidden';
            bulkActionInput.name = 'bulk_action_type';
            bulkActionInput.value = 'update_status';
            form.appendChild(bulkActionInput);

            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'bulk_status';
            statusInput.value = newStatus;
            form.appendChild(statusInput);

            selectedStudents.forEach(studentId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_students[]';
                input.value = studentId;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openStudentModal(studentId) {
            // Load student details via AJAX
            fetch(`get_student_details.php?student_id=${studentId}&class_id=<?php echo $class_id; ?>`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('studentModalContent').innerHTML = html;
                    openModal('studentModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('studentModalContent').innerHTML =
                        '<p>Error loading student details. Please try again.</p>';
                    openModal('studentModal');
                });
        }

        function openGradeModal(studentId, currentGrade) {
            document.getElementById('grade_student_id').value = studentId;
            document.getElementById('final_grade').value = currentGrade;
            openModal('gradeModal');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Esc to close modals
            if (e.key === 'Escape') {
                closeModal('studentModal');
                closeModal('gradeModal');
                closeModal('statusModal');
            }

            // Ctrl + A to select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                const selectAll = document.getElementById('select-all');
                selectAll.checked = !selectAll.checked;
                const checkboxes = document.querySelectorAll('.student-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = selectAll.checked;
                });
            }
        });
    </script>
</body>

</html>