<?php
// modules/admin/academic/programs/index.php

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

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'desc';
$program_type = $_GET['program_type'] ?? 'all';
// Add school filter
$filter_school = isset($_GET['filter_school']) ? (int)$_GET['filter_school'] : 0;

// Validate sort and order
$valid_sorts = ['id', 'program_code', 'name', 'duration_weeks', 'fee', 'base_fee', 'registration_fee', 'program_type', 'status', 'created_at'];
$valid_orders = ['asc', 'desc'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'created_at';
$order = in_array($order, $valid_orders) ? $order : 'desc';

// Build query with filters
$query = "SELECT p.*, 
                 COUNT(DISTINCT c.id) as course_count,
                 COUNT(DISTINCT cb.id) as class_count,
                 COUNT(DISTINCT e.id) as enrollment_count,
                 u.first_name as creator_first_name,
                 u.last_name as creator_last_name,
                 s.name as school_name
          FROM programs p
          LEFT JOIN courses c ON p.id = c.program_id AND c.status = 'active'
          LEFT JOIN class_batches cb ON p.id = (
              SELECT c2.program_id FROM courses c2 WHERE c2.id = cb.course_id
          ) AND cb.status = 'ongoing'
          LEFT JOIN enrollments e ON cb.id = e.class_id AND e.status = 'active'
          LEFT JOIN users u ON p.created_by = u.id
          LEFT JOIN schools s ON p.school_id = s.id
          WHERE 1=1";

$params = [];
$types = '';

// Apply status filter
if ($status !== 'all') {
    $query .= " AND p.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Apply program type filter
if ($program_type !== 'all') {
    $query .= " AND p.program_type = ?";
    $params[] = $program_type;
    $types .= 's';
}

// Apply school filter
if ($filter_school) {
    $query .= " AND p.school_id = ?";
    $params[] = $filter_school;
    $types .= 'i';
}

// Apply search filter
if ($search) {
    $query .= " AND (p.program_code LIKE ? OR p.name LIKE ? OR p.description LIKE ? OR p.fee_description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

// Group by and order
$query .= " GROUP BY p.id ORDER BY p.$sort $order";

// Get total count for pagination
$count_query = str_replace(
    'p.*, COUNT(DISTINCT c.id) as course_count, COUNT(DISTINCT cb.id) as class_count, COUNT(DISTINCT e.id) as enrollment_count, u.first_name as creator_first_name, u.last_name as creator_last_name, s.name as school_name',
    'COUNT(DISTINCT p.id) as total',
    $query
);

$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_programs = $count_result->fetch_assoc()['total'] ?? 0;

// Pagination
$per_page = 12; // Reduced from 15 for better grid display
$total_pages = ceil($total_programs / $per_page);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute main query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get comprehensive statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
                SUM(fee) as total_revenue_potential,
                AVG(fee) as avg_fee,
                AVG(base_fee) as avg_base_fee,
                SUM(registration_fee) as total_registration_fees,
                AVG(duration_weeks) as avg_duration,
                SUM(CASE WHEN program_type = 'online' THEN 1 ELSE 0 END) as online_count,
                SUM(CASE WHEN program_type = 'onsite' THEN 1 ELSE 0 END) as onsite_count,
                SUM(CASE WHEN program_type = 'school' THEN 1 ELSE 0 END) as school_count,
                SUM(CASE WHEN payment_plan_type = 'full' THEN 1 ELSE 0 END) as full_payment_count,
                SUM(CASE WHEN payment_plan_type = 'installment' THEN 1 ELSE 0 END) as installment_count,
                SUM(CASE WHEN payment_plan_type = 'block' THEN 1 ELSE 0 END) as block_count
                FROM programs";

// Add school filter to stats query if applicable
if ($filter_school) {
    $stats_query .= " WHERE school_id = $filter_school";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Calculate total value including registration fees
$stats['total_value'] = ($stats['total_revenue_potential'] ?? 0) + ($stats['total_registration_fees'] ?? 0);

// Log activity
logActivity('view_programs', "Viewed programs list with filters");

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];

    if (!empty($selected_ids)) {
        $success_count = 0;
        $error_count = 0;

        foreach ($selected_ids as $id) {
            $id = (int)$id;

            switch ($bulk_action) {
                case 'activate':
                    $result = updateProgramStatus($id, 'active');
                    break;
                case 'deactivate':
                    $result = updateProgramStatus($id, 'inactive');
                    break;
                case 'delete':
                    $result = deleteProgram($id);
                    break;
                case 'clone':
                    $result = cloneProgram($id);
                    break;
                default:
                    continue 2;
            }

            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully processed $success_count program(s)";
        }
        if ($error_count > 0) {
            $_SESSION['error'] = "Failed to process $error_count program(s)";
        }

        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Function to update program status
function updateProgramStatus($id, $status)
{
    global $conn;

    $sql = "UPDATE programs SET status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $id);

    if ($stmt->execute()) {
        logActivity('program_status_update', "Updated program #$id status to $status", 'programs', $id);
        return ['success' => true];
    }

    return ['success' => false, 'message' => 'Database error'];
}

// Function to delete program
function deleteProgram($id)
{
    global $conn;

    // Start transaction
    $conn->begin_transaction();

    try {
        // Check if program has related records
        $check_sql = "SELECT 
                     (SELECT COUNT(*) FROM courses WHERE program_id = ?) as course_count,
                     (SELECT COUNT(*) FROM applications WHERE program_id = ?) as application_count,
                     (SELECT COUNT(*) FROM class_batches WHERE course_id IN (SELECT id FROM courses WHERE program_id = ?)) as class_count";

        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iii", $id, $id, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $counts = $check_result->fetch_assoc();

        if ($counts['course_count'] > 0 || $counts['application_count'] > 0 || $counts['class_count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete program with related courses, classes, or applications'];
        }

        // Delete payment plans first
        $delete_plans_sql = "DELETE FROM payment_plans WHERE program_id = ?";
        $delete_plans_stmt = $conn->prepare($delete_plans_sql);
        $delete_plans_stmt->bind_param("i", $id);
        $delete_plans_stmt->execute();

        // Delete program
        $delete_sql = "DELETE FROM programs WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        $delete_stmt->execute();

        $conn->commit();

        logActivity('program_delete', "Deleted program #$id", 'programs', $id);
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Function to clone program
function cloneProgram($id)
{
    global $conn;

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get original program
        $sql = "SELECT * FROM programs WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();

        if (!$original) {
            return ['success' => false, 'message' => 'Program not found'];
        }

        // Generate new program code
        $base_code = $original['program_code'];
        $counter = 1;
        $new_code = $base_code . '-COPY';

        // Check if code already exists
        while (true) {
            $check_sql = "SELECT id FROM programs WHERE program_code = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $new_code);
            $check_stmt->execute();

            if ($check_stmt->get_result()->num_rows === 0) {
                break;
            }

            $counter++;
            $new_code = $base_code . '-COPY' . $counter;
        }

        // Clone the program
        $clone_sql = "INSERT INTO programs (
            program_code, name, description, duration_weeks, fee, base_fee,
            registration_fee, online_fee, onsite_fee, program_type,
            payment_plan_type, installment_count, late_fee_percentage,
            currency, fee_description, status, duration_mode, schedule_type,
            created_by, school_id, school_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $clone_stmt = $conn->prepare($clone_sql);
        $user_id = $_SESSION['user_id'] ?? 1;
        $clone_stmt->bind_param(
            "sssididdddsiidssssiis",
            $new_code,
            $original['name'] . ' (Copy)',
            $original['description'],
            $original['duration_weeks'],
            $original['fee'],
            $original['base_fee'],
            $original['registration_fee'],
            $original['online_fee'],
            $original['onsite_fee'],
            $original['program_type'],
            $original['payment_plan_type'],
            $original['installment_count'],
            $original['late_fee_percentage'],
            $original['currency'] ?? 'NGN',
            $original['fee_description'],
            'inactive', // Default to inactive for cloned programs
            $original['duration_mode'],
            $original['schedule_type'],
            $user_id,
            $original['school_id'],
            $original['school_name']
        );

        if (!$clone_stmt->execute()) {
            throw new Exception("Failed to clone program");
        }

        $new_program_id = $conn->insert_id;

        // Clone payment plans
        $plan_sql = "SELECT * FROM payment_plans WHERE program_id = ?";
        $plan_stmt = $conn->prepare($plan_sql);
        $plan_stmt->bind_param("i", $id);
        $plan_stmt->execute();
        $plans = $plan_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($plans as $plan) {
            $insert_plan_sql = "INSERT INTO payment_plans (
                program_id, program_type, plan_name, registration_fee,
                block1_percentage, block2_percentage, block1_due_days, block2_due_days,
                late_fee_percentage, suspension_days, refund_policy_days, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insert_plan_stmt = $conn->prepare($insert_plan_sql);
            $insert_plan_stmt->bind_param(
                "issddddddddi",
                $new_program_id,
                $plan['program_type'],
                $plan['plan_name'],
                $plan['registration_fee'],
                $plan['block1_percentage'],
                $plan['block2_percentage'],
                $plan['block1_due_days'],
                $plan['block2_due_days'],
                $plan['late_fee_percentage'],
                $plan['suspension_days'],
                $plan['refund_policy_days'],
                $plan['is_active']
            );
            $insert_plan_stmt->execute();
        }

        $conn->commit();

        logActivity('program_clone', "Cloned program #$id to #$new_program_id", 'programs', $new_program_id);
        return ['success' => true, 'new_id' => $new_program_id];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manage Programs - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../../../../public/images/favicon.ico">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --gray-lighter: #e2e8f0;
            --white: #ffffff;
            --online-color: #10b981;
            --onsite-color: #8b5cf6;
            --school-color: #3b82f6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb::-webkit-scrollbar {
            display: none;
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

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Header */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
            background: var(--white);
            padding: 1.25rem;
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .page-title h1 {
            font-size: 1.8rem;
            color: var(--dark);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title h1 i {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.7rem 1.2rem;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            flex: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            color: var(--white);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: var(--white);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: var(--white);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: var(--white);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary-light);
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
            border: 2px solid transparent;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-light);
        }

        .stat-card.total {
            border-left-color: var(--primary);
        }

        .stat-card.active {
            border-left-color: var(--success);
        }

        .stat-card.inactive {
            border-left-color: var(--danger);
        }

        .stat-card.upcoming {
            border-left-color: var(--warning);
        }

        .stat-card.revenue {
            border-left-color: var(--secondary);
        }

        .stat-card.fee {
            border-left-color: var(--info);
        }

        .stat-card.online {
            border-left-color: var(--online-color);
        }

        .stat-card.onsite {
            border-left-color: var(--onsite-color);
        }

        .stat-card.school {
            border-left-color: var(--school-color);
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-value i {
            font-size: 1rem;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.65rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-subtext {
            font-size: 0.65rem;
            color: var(--gray-light);
            margin-top: 0.25rem;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -webkit-overflow-scrolling: touch;
        }

        .quick-action-btn {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            background: var(--white);
            border: 2px solid var(--gray-lighter);
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .quick-action-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--light);
        }

        .quick-action-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary);
            color: var(--white);
        }

        .quick-action-btn i {
            font-size: 0.9rem;
        }

        /* Filters Card */
        .filters-card {
            background: var(--white);
            border-radius: 20px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-reset {
            color: var(--primary);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .filter-reset:hover {
            background: rgba(37, 99, 235, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .filter-group {
            margin-bottom: 0.5rem;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-lighter);
            border-radius: 12px;
            font-size: 0.9rem;
            background: var(--white);
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray-lighter);
        }

        /* Programs Grid */
        .programs-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .program-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .program-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }

        .program-badges {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-end;
            z-index: 10;
        }

        .program-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .program-type-badge.online {
            color: var(--online-color);
        }

        .program-type-badge.onsite {
            color: var(--onsite-color);
        }

        .program-type-badge.school {
            color: var(--school-color);
        }

        .program-status {
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .status-active {
            color: var(--success);
        }

        .status-inactive {
            color: var(--danger);
        }

        .status-upcoming {
            color: var(--warning);
        }

        .program-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
        }

        .program-code {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
            font-family: 'Courier New', monospace;
            background: rgba(255, 255, 255, 0.1);
            display: inline-block;
            padding: 0.2rem 0.8rem;
            border-radius: 30px;
        }

        .program-title {
            font-size: 1.2rem;
            font-weight: 600;
            line-height: 1.3;
            word-break: break-word;
            padding-right: 80px;
        }

        .program-content {
            padding: 1.25rem;
        }

        .school-badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .school-badge i {
            margin-right: 0.3rem;
        }

        .program-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .program-fees {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 2px solid var(--gray-lighter);
        }

        .fee-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .fee-row:last-child {
            border-bottom: none;
        }

        .fee-row.total {
            font-weight: bold;
            color: var(--success);
            border-top: 2px solid var(--success);
            margin-top: 0.5rem;
            padding-top: 0.75rem;
        }

        .fee-label {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .fee-value {
            color: var(--dark);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .program-payment-info {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            flex-wrap: wrap;
        }

        .payment-type {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--gray);
            background: var(--light);
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
        }

        .payment-type i {
            color: var(--primary);
        }

        .program-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--light), var(--white));
            border-radius: 16px;
            border: 2px solid var(--gray-lighter);
        }

        .program-stat {
            text-align: center;
        }

        .stat-icon {
            font-size: 1rem;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-value-small {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.1rem;
        }

        .stat-label-small {
            font-size: 0.6rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .program-details {
            margin-bottom: 1rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px dashed var(--gray-lighter);
            font-size: 0.85rem;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--gray);
        }

        .detail-value {
            color: var(--dark);
            font-weight: 500;
        }

        .program-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            padding-top: 1rem;
            border-top: 2px solid var(--gray-lighter);
        }

        .btn-icon {
            padding: 0.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            background: var(--light);
            color: var(--gray);
        }

        .btn-icon i {
            font-size: 0.9rem;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-view {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .btn-edit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-fees {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-courses {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .btn-activate {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .btn-deactivate {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .btn-clone {
            background: rgba(139, 92, 246, 0.1);
            color: var(--onsite-color);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Table View */
        .programs-table-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: none;
        }

        .view-toggle {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .view-btn {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            background: var(--white);
            border: 2px solid var(--gray-lighter);
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        .view-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .view-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary);
            color: var(--white);
        }

        .table-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1.25rem;
            border-bottom: 2px solid var(--gray-lighter);
        }

        .table-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
        }

        .bulk-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th {
            text-align: left;
            padding: 1rem;
            background: linear-gradient(135deg, var(--light), var(--white));
            color: var(--gray);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-lighter);
            cursor: pointer;
            white-space: nowrap;
        }

        .data-table th:hover {
            color: var(--primary);
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-lighter);
            vertical-align: middle;
            font-size: 0.85rem;
        }

        .data-table tr:hover {
            background: var(--light);
        }

        .data-table tr.selected {
            background: rgba(37, 99, 235, 0.05);
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            border: 2px solid transparent;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .page-info {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .page-numbers {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-link {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            text-decoration: none;
            color: var(--primary);
            background: var(--white);
            border: 2px solid var(--gray-lighter);
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-online {
            background: rgba(16, 185, 129, 0.15);
            color: var(--online-color);
        }

        .badge-onsite {
            background: rgba(139, 92, 246, 0.15);
            color: var(--onsite-color);
        }

        .badge-school {
            background: rgba(59, 130, 246, 0.15);
            color: var(--school-color);
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }

        .badge-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .badge-upcoming {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state h3 {
            color: var(--dark);
            margin-bottom: 0.25rem;
            font-size: 1.1rem;
        }

        .empty-state p {
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        /* Tablet Breakpoint */
        @media (min-width: 640px) {
            .container {
                padding: 1.5rem;
            }

            .page-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
            }

            .page-title h1 {
                font-size: 2rem;
            }

            .btn {
                flex: none;
            }

            .stats-cards {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }

            .filters-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .programs-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }

            .table-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .bulk-actions {
                flex-direction: row;
                align-items: center;
            }

            .pagination {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        /* Desktop Breakpoint */
        @media (min-width: 1024px) {
            .stats-cards {
                grid-template-columns: repeat(5, 1fr);
            }

            .filters-grid {
                grid-template-columns: repeat(3, 1fr) auto;
            }

            .programs-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
            }

            .program-actions {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* Large Desktop Breakpoint */
        @media (min-width: 1280px) {
            .stats-cards {
                grid-template-columns: repeat(8, 1fr);
            }

            .programs-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {

            .btn,
            .stat-card,
            .program-card,
            .page-link,
            .filter-reset,
            .quick-action-btn,
            .view-btn,
            .btn-icon {
                -webkit-tap-highlight-color: transparent;
            }

            .btn:active,
            .stat-card:active,
            .program-card:active,
            .btn-icon:active {
                transform: scale(0.98);
            }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                transform: translateX(-20px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .program-card {
            animation: fadeInUp 0.5s ease;
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
            <span>Programs</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-graduation-cap"></i> Programs</h1>
                <div style="color: var(--gray); font-size: 0.9rem;">
                    <?php echo number_format($total_programs); ?> total programs
                </div>
            </div>
            <div class="page-actions">
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> New Program
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/" class="btn btn-secondary">
                    <i class="fas fa-book"></i> Courses
                </a>
                <button class="btn btn-success" onclick="exportPrograms()">
                    <i class="fas fa-file-export"></i> Export
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($_SESSION['success']); ?></div>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div><?php echo htmlspecialchars($_SESSION['error']); ?></div>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total" onclick="window.location.href='?status=all<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-graduation-cap"></i>
                    <?php echo number_format($stats['total'] ?? 0); ?>
                </div>
                <div class="stat-label">Total Programs</div>
                <div class="stat-subtext">
                    <?php echo number_format($stats['online_count'] ?? 0); ?> online,
                    <?php echo number_format($stats['onsite_count'] ?? 0); ?> onsite
                </div>
            </div>
            <div class="stat-card active" onclick="window.location.href='?status=active<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-check-circle"></i>
                    <?php echo number_format($stats['active'] ?? 0); ?>
                </div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card upcoming" onclick="window.location.href='?status=upcoming<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-clock"></i>
                    <?php echo number_format($stats['upcoming'] ?? 0); ?>
                </div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card revenue" onclick="window.location.href='?sort=fee&order=desc<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-naira-sign"></i>
                    <?php echo number_format($stats['total_value'] ?? 0, 0); ?>
                </div>
                <div class="stat-label">Total Value</div>
            </div>
            <div class="stat-card fee" onclick="window.location.href='?sort=fee&order=desc<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-money-bill"></i>
                    ₦<?php echo number_format($stats['avg_fee'] ?? 0, 0); ?>
                </div>
                <div class="stat-label">Avg. Fee</div>
            </div>
            <div class="stat-card online" onclick="window.location.href='?program_type=online<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-globe"></i>
                    <?php echo number_format($stats['online_count'] ?? 0); ?>
                </div>
                <div class="stat-label">Online</div>
            </div>
            <div class="stat-card onsite" onclick="window.location.href='?program_type=onsite<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-building"></i>
                    <?php echo number_format($stats['onsite_count'] ?? 0); ?>
                </div>
                <div class="stat-label">Onsite</div>
            </div>
            <div class="stat-card school" onclick="window.location.href='?program_type=school<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>'">
                <div class="stat-value">
                    <i class="fas fa-school"></i>
                    <?php echo number_format($stats['school_count'] ?? 0); ?>
                </div>
                <div class="stat-label">School</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="?status=active<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $status === 'active' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Active
            </a>
            <a href="?status=inactive<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $status === 'inactive' ? 'active' : ''; ?>">
                <i class="fas fa-times-circle"></i> Inactive
            </a>
            <a href="?status=upcoming<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $status === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Upcoming
            </a>
            <a href="?program_type=online<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $program_type === 'online' ? 'active' : ''; ?>">
                <i class="fas fa-globe"></i> Online
            </a>
            <a href="?program_type=onsite<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $program_type === 'onsite' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i> Onsite
            </a>
            <a href="?program_type=school<?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>" class="quick-action-btn <?php echo $program_type === 'school' ? 'active' : ''; ?>">
                <i class="fas fa-school"></i> School
            </a>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> Filter Programs</h3>
                <button type="button" class="filter-reset" onclick="resetFilters()">
                    <i class="fas fa-redo-alt"></i> Reset
                </button>
            </div>

            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="program_type">Program Type</label>
                        <select id="program_type" name="program_type" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $program_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="online" <?php echo $program_type === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="onsite" <?php echo $program_type === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                            <option value="school" <?php echo $program_type === 'school' ? 'selected' : ''; ?>>School</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="filter_school">School</label>
                        <select id="filter_school" name="filter_school" class="form-control" onchange="this.form.submit()">
                            <option value="0">All Schools</option>
                            <?php
                            $schools_sql = "SELECT id, name FROM schools ORDER BY name";
                            $schools_result = $conn->query($schools_sql);
                            while ($school = $schools_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo $school['id']; ?>"
                                    <?php echo $filter_school == $school['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" class="form-control"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search programs...">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- View Toggle -->
        <div class="view-toggle">
            <button class="view-btn active" onclick="showGridView()">
                <i class="fas fa-th-large"></i> Grid
            </button>
            <button class="view-btn" onclick="showTableView()">
                <i class="fas fa-table"></i> Table
            </button>
        </div>

        <!-- Grid View -->
        <div id="gridView">
            <div class="programs-grid">
                <?php if (empty($programs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No programs found</h3>
                        <p>No programs match your current filters.</p>
                        <button type="button" class="btn btn-primary" onclick="resetFilters()" style="margin-top: 0.5rem;">
                            <i class="fas fa-redo-alt"></i> Reset Filters
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($programs as $program):
                        $total_fee = $program['fee'] + ($program['registration_fee'] ?? 0);
                        $program_type_class = $program['program_type'];
                    ?>
                        <div class="program-card">
                            <div class="program-badges">
                                <div class="program-type-badge <?php echo $program_type_class; ?>">
                                    <?php echo strtoupper($program['program_type']); ?>
                                </div>
                                <div class="program-status status-<?php echo $program['status']; ?>">
                                    <?php echo ucfirst($program['status']); ?>
                                </div>
                            </div>

                            <div class="program-header">
                                <div class="program-code"><?php echo htmlspecialchars($program['program_code']); ?></div>
                                <h3 class="program-title"><?php echo htmlspecialchars($program['name']); ?></h3>
                            </div>

                            <div class="program-content">
                                <?php if ($program['school_name']): ?>
                                    <div class="school-badge">
                                        <i class="fas fa-school"></i>
                                        <?php echo htmlspecialchars($program['school_name']); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($program['description']): ?>
                                    <div class="program-description">
                                        <?php echo htmlspecialchars(substr($program['description'], 0, 100)); ?>
                                        <?php if (strlen($program['description']) > 100): ?>...<?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Fee Breakdown -->
                                <div class="program-fees">
                                    <div class="fee-row">
                                        <span class="fee-label">Program Fee:</span>
                                        <span class="fee-value">₦<?php echo number_format($program['fee'], 0); ?></span>
                                    </div>
                                    <?php if (($program['registration_fee'] ?? 0) > 0): ?>
                                        <div class="fee-row">
                                            <span class="fee-label">Registration:</span>
                                            <span class="fee-value">₦<?php echo number_format($program['registration_fee'], 0); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="fee-row total">
                                        <span class="fee-label">Total Fee:</span>
                                        <span class="fee-value">₦<?php echo number_format($total_fee, 0); ?></span>
                                    </div>
                                </div>

                                <!-- Payment Info -->
                                <div class="program-payment-info">
                                    <div class="payment-type">
                                        <i class="fas fa-credit-card"></i>
                                        <span><?php echo ucfirst($program['payment_plan_type'] ?? 'full'); ?></span>
                                    </div>
                                    <?php if ($program['installment_count'] > 1): ?>
                                        <div class="payment-type">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo $program['installment_count']; ?> installments</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="payment-type">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo $program['duration_weeks']; ?> weeks</span>
                                    </div>
                                </div>

                                <!-- Program Stats -->
                                <div class="program-stats">
                                    <div class="program-stat">
                                        <div class="stat-icon">
                                            <i class="fas fa-book"></i>
                                        </div>
                                        <div class="stat-value-small"><?php echo $program['course_count'] ?: '0'; ?></div>
                                        <div class="stat-label-small">Courses</div>
                                    </div>
                                    <div class="program-stat">
                                        <div class="stat-icon">
                                            <i class="fas fa-chalkboard"></i>
                                        </div>
                                        <div class="stat-value-small"><?php echo $program['class_count'] ?: '0'; ?></div>
                                        <div class="stat-label-small">Classes</div>
                                    </div>
                                    <div class="program-stat">
                                        <div class="stat-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div class="stat-value-small"><?php echo $program['enrollment_count'] ?: '0'; ?></div>
                                        <div class="stat-label-small">Students</div>
                                    </div>
                                </div>

                                <!-- Program Actions -->
                                <div class="program-actions">
                                    <a href="view.php?id=<?php echo $program['id']; ?>" class="btn-icon btn-view" title="View Details">
                                        <i class="fas fa-eye"></i>
                                        <span class="action-text">View</span>
                                    </a>
                                    <a href="edit.php?id=<?php echo $program['id']; ?>" class="btn-icon btn-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                        <span class="action-text">Edit</span>
                                    </a>
                                    <a href="fee_settings.php?id=<?php echo $program['id']; ?>" class="btn-icon btn-fees" title="Fee Settings">
                                        <i class="fas fa-money-bill"></i>
                                        <span class="action-text">Fees</span>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>modules/admin/academic/courses/index.php?program_id=<?php echo $program['id']; ?>" class="btn-icon btn-courses" title="Courses">
                                        <i class="fas fa-book"></i>
                                        <span class="action-text">Courses</span>
                                    </a>
                                    <?php if ($program['status'] === 'active'): ?>
                                        <a href="?action=deactivate&id=<?php echo $program['id']; ?><?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>"
                                            class="btn-icon btn-deactivate" title="Deactivate"
                                            onclick="return confirmDeactivation()">
                                            <i class="fas fa-pause-circle"></i>
                                            <span class="action-text">Pause</span>
                                        </a>
                                    <?php elseif ($program['status'] === 'inactive' || $program['status'] === 'upcoming'): ?>
                                        <a href="?action=activate&id=<?php echo $program['id']; ?><?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>"
                                            class="btn-icon btn-activate" title="Activate"
                                            onclick="return confirmActivation()">
                                            <i class="fas fa-play-circle"></i>
                                            <span class="action-text">Activate</span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="?action=clone&id=<?php echo $program['id']; ?><?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>"
                                        class="btn-icon btn-clone" title="Clone"
                                        onclick="return confirmClone()">
                                        <i class="fas fa-copy"></i>
                                        <span class="action-text">Clone</span>
                                    </a>
                                    <a href="?action=delete&id=<?php echo $program['id']; ?><?php echo $filter_school ? '&filter_school=' . $filter_school : ''; ?>"
                                        class="btn-icon btn-delete" title="Delete"
                                        onclick="return confirmDelete()">
                                        <i class="fas fa-trash-alt"></i>
                                        <span class="action-text">Delete</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table View -->
        <div id="tableView" class="programs-table-card">
            <form method="POST" id="bulkForm">
                <div class="table-header">
                    <h3>Programs List (<?php echo number_format($total_programs); ?> programs)</h3>
                    <div class="bulk-actions">
                        <div class="bulk-select">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            <label for="selectAll">Select All</label>
                        </div>
                        <select name="bulk_action" class="form-control" style="width: 150px;">
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="clone">Clone</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                            <i class="fas fa-play"></i> Apply
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="checkbox-cell">
                                    <input type="checkbox" id="selectAllTable" onchange="toggleSelectAll()">
                                </th>
                                <th onclick="sortTable('program_code')">
                                    Code <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('name')">
                                    Name <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('program_type')">
                                    Type <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('duration_weeks')">
                                    Duration <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('fee')">
                                    Fee <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('status')">
                                    Status <i class="fas fa-sort"></i>
                                </th>
                                <th>School</th>
                                <th>Stats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($programs)): ?>
                                <tr>
                                    <td colspan="10" class="empty-state">
                                        <i class="fas fa-graduation-cap"></i>
                                        <h3>No programs found</h3>
                                        <p>No programs match your current filters.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($programs as $program):
                                    $total_fee = $program['fee'] + ($program['registration_fee'] ?? 0);
                                ?>
                                    <tr data-id="<?php echo $program['id']; ?>">
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo $program['id']; ?>" class="row-selector">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($program['name']); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem;">
                                                <?php echo substr($program['description'] ?? '', 0, 30); ?>...
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $program['program_type']; ?>">
                                                <?php echo strtoupper($program['program_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $program['duration_weeks']; ?> weeks</td>
                                        <td>
                                            <div style="font-weight: 600;">₦<?php echo number_format($program['fee'], 0); ?></div>
                                            <?php if (($program['registration_fee'] ?? 0) > 0): ?>
                                                <div style="font-size: 0.7rem; color: var(--gray);">
                                                    +₦<?php echo number_format($program['registration_fee'], 0); ?> reg
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $program['status']; ?>">
                                                <?php echo ucfirst($program['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($program['school_name']): ?>
                                                <span style="font-size: 0.8rem; font-weight: 500;">
                                                    <?php echo htmlspecialchars($program['school_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--gray);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; font-size: 0.75rem;">
                                                <span title="Courses"><i class="fas fa-book"></i> <?php echo $program['course_count'] ?: '0'; ?></span>
                                                <span title="Classes"><i class="fas fa-chalkboard"></i> <?php echo $program['class_count'] ?: '0'; ?></span>
                                                <span title="Students"><i class="fas fa-users"></i> <?php echo $program['enrollment_count'] ?: '0'; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.3rem; flex-wrap: wrap;">
                                                <a href="view.php?id=<?php echo $program['id']; ?>" class="btn-icon btn-view" title="View" style="padding: 0.3rem;">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $program['id']; ?>" class="btn-icon btn-edit" title="Edit" style="padding: 0.3rem;">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=clone&id=<?php echo $program['id']; ?>" class="btn-icon btn-clone" title="Clone" style="padding: 0.3rem;">
                                                    <i class="fas fa-copy"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div class="page-info">
                    Showing <?php echo (($page - 1) * $per_page) + 1; ?>-<?php echo min($page * $per_page, $total_programs); ?> of <?php echo number_format($total_programs); ?>
                </div>
                <div class="page-numbers">
                    <?php
                    $query_params = $_GET;
                    unset($query_params['page']);
                    $query_string = http_build_query($query_params);
                    ?>

                    <?php if ($page > 1): ?>
                        <a href="?<?php echo $query_string; ?>&page=1" class="page-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?<?php echo $query_string; ?>&page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    for ($p = $start_page; $p <= $end_page; $p++):
                    ?>
                        <a href="?<?php echo $query_string; ?>&page=<?php echo $p; ?>"
                            class="page-link <?php echo $p == $page ? 'active' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo $query_string; ?>&page=<?php echo $page + 1; ?>" class="page-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo $query_string; ?>&page=<?php echo $total_pages; ?>" class="page-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // View toggle functions
        function showGridView() {
            document.getElementById('gridView').style.display = 'block';
            document.getElementById('tableView').style.display = 'none';
            document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.view-btn:nth-child(1)').classList.add('active');
            localStorage.setItem('programsView', 'grid');
        }

        function showTableView() {
            document.getElementById('gridView').style.display = 'none';
            document.getElementById('tableView').style.display = 'block';
            document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.view-btn:nth-child(2)').classList.add('active');
            localStorage.setItem('programsView', 'table');
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'index.php';
        }

        // Sort table
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort');
            const currentOrder = url.searchParams.get('order');

            let newOrder = 'asc';
            if (currentSort === column && currentOrder === 'asc') {
                newOrder = 'desc';
            }

            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // Bulk selection
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll') || document.getElementById('selectAllTable');
            const checkboxes = document.querySelectorAll('.row-selector');
            const isChecked = selectAll.checked;

            checkboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
                const row = checkbox.closest('tr');
                if (row) {
                    row.classList.toggle('selected', isChecked);
                }
            });

            const otherSelectAll = document.getElementById('selectAll') ? document.getElementById('selectAllTable') : document.getElementById('selectAll');
            if (otherSelectAll) {
                otherSelectAll.checked = isChecked;
            }
        }

        // Row selection
        document.querySelectorAll('.row-selector').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const row = this.closest('tr');
                if (row) {
                    row.classList.toggle('selected', this.checked);
                }

                const allCheckboxes = document.querySelectorAll('.row-selector');
                const selectAll = document.getElementById('selectAll') || document.getElementById('selectAllTable');
                if (selectAll) {
                    selectAll.checked = Array.from(allCheckboxes).every(cb => cb.checked);
                    selectAll.indeterminate = !selectAll.checked && Array.from(allCheckboxes).some(cb => cb.checked);
                }
            });
        });

        // Confirmation dialogs
        function confirmActivation() {
            return confirm('✅ Activate this program?\n\nThis will make the program available for enrollment.');
        }

        function confirmDeactivation() {
            return confirm('⏸️ Deactivate this program?\n\nThis will prevent new enrollments. Existing enrollments will not be affected.');
        }

        function confirmClone() {
            return confirm('📋 Clone this program?\n\nA copy will be created with "(Copy)" suffix and set to inactive status.');
        }

        function confirmDelete() {
            return confirm('⚠️ Delete this program?\n\nWARNING: This will permanently delete:\n• All program data\n• Related courses\n• Payment plans\n\nThis action CANNOT be undone!\n\nAre you absolutely sure?');
        }

        function confirmBulkAction() {
            const form = document.getElementById('bulkForm');
            const bulkAction = form.bulk_action.value;
            const selectedCount = document.querySelectorAll('.row-selector:checked').length;

            if (!bulkAction) {
                alert('Please select a bulk action');
                return false;
            }

            if (selectedCount === 0) {
                alert('Please select at least one program');
                return false;
            }

            const actionMessages = {
                'activate': 'activate',
                'deactivate': 'deactivate',
                'clone': 'create copies of',
                'delete': 'delete'
            };

            const actionText = actionMessages[bulkAction] || bulkAction;
            return confirm(`Are you sure you want to ${actionText} ${selectedCount} program(s)?`);
        }

        // Auto-submit filters on search after delay
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

        // Export programs
        function exportPrograms() {
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'csv');
            window.open(url.toString(), '_blank');
        }

        // Initialize view based on localStorage preference
        document.addEventListener('DOMContentLoaded', function() {
            const preferredView = localStorage.getItem('programsView') || 'grid';
            if (preferredView === 'table') {
                showTableView();
            } else {
                showGridView();
            }

            const searchInput = document.getElementById('search');
            if (searchInput && searchInput.value) {
                searchInput.focus();
                searchInput.select();
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.getElementById('search');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }

                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    window.location.href = 'create.php';
                }

                if (e.key === 'Escape') {
                    const searchInput = document.getElementById('search');
                    if (searchInput && searchInput.value) {
                        searchInput.value = '';
                        document.getElementById('filterForm').submit();
                    }
                }
            });

            // Animate elements on scroll
            const elements = document.querySelectorAll('.stat-card, .program-card');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            });

            elements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'all 0.5s ease';
                observer.observe(el);
            });
        });

        // Responsive action text
        function updateActionText() {
            const isMobile = window.innerWidth < 640;
            document.querySelectorAll('.action-text').forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });
        }

        window.addEventListener('load', updateActionText);
        window.addEventListener('resize', updateActionText);
    </script>
</body>

</html>