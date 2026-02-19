<?php
// modules/admin/finance/fees/waivers.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get filter parameters
$fee_structure_id = $_GET['fee_structure_id'] ?? 0;
$status = $_GET['status'] ?? 'pending';
$student_id = $_GET['student_id'] ?? 0;

// Get fee structures for dropdown
$fee_structures_sql = "SELECT fs.*, p.name as program_name, p.program_code 
                      FROM fee_structures fs 
                      JOIN programs p ON p.id = fs.program_id 
                      WHERE fs.is_active = 1 
                      ORDER BY p.name";
$fee_structures_result = $conn->query($fee_structures_sql);
$fee_structures = $fee_structures_result->fetch_all(MYSQLI_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_waiver') {
        $student_id = $_POST['student_id'] ?? '';
        $fee_structure_id = $_POST['fee_structure_id'] ?? '';
        $waiver_type = $_POST['waiver_type'] ?? '';
        $waiver_value = $_POST['waiver_value'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        $applicable_blocks = $_POST['applicable_blocks'] ?? 'all';
        $expiry_date = $_POST['expiry_date'] ?? '';

        $sql = "INSERT INTO fee_waivers (student_id, fee_structure_id, waiver_type, 
                                        waiver_value, reason, applicable_blocks, 
                                        expiry_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iisdsss",
            $student_id,
            $fee_structure_id,
            $waiver_type,
            $waiver_value,
            $reason,
            $applicable_blocks,
            $expiry_date
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Fee waiver request created successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = "Failed to create fee waiver: " . $stmt->error;
        }
    } elseif ($action === 'update_status') {
        $waiver_id = $_POST['waiver_id'] ?? 0;
        $new_status = $_POST['new_status'] ?? '';
        $admin_notes = $_POST['admin_notes'] ?? '';

        if ($new_status === 'approved' || $new_status === 'rejected') {
            $sql = "UPDATE fee_waivers SET 
                    status = ?, 
                    approved_by = ?, 
                    approved_at = NOW() 
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sii", $new_status, $_SESSION['user_id'], $waiver_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Fee waiver status updated to " . $new_status;

                // If approved, apply the waiver to student's financial status
                if ($new_status === 'approved') {
                    applyFeeWaiver($waiver_id, $conn);
                }

                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $_SESSION['error'] = "Failed to update waiver status: " . $stmt->error;
            }
        }
    } elseif ($action === 'delete_waiver') {
        $waiver_id = $_POST['waiver_id'] ?? 0;

        $sql = "DELETE FROM fee_waivers WHERE id = ? AND status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $waiver_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Fee waiver deleted successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = "Failed to delete fee waiver: " . $stmt->error;
        }
    }
}

// Build query for waivers list
$query = "SELECT w.*, 
          u.first_name, u.last_name, u.email,
          fs.name as fee_structure_name, fs.total_amount,
          p.name as program_name, p.program_code,
          a.first_name as approver_first_name, a.last_name as approver_last_name
          FROM fee_waivers w
          JOIN users u ON u.id = w.student_id
          JOIN fee_structures fs ON fs.id = w.fee_structure_id
          JOIN programs p ON p.id = fs.program_id
          LEFT JOIN users a ON a.id = w.approved_by
          WHERE 1=1";

$params = [];
$types = "";

if ($fee_structure_id) {
    $query .= " AND w.fee_structure_id = ?";
    $params[] = $fee_structure_id;
    $types .= "i";
}

if ($status && $status !== 'all') {
    $query .= " AND w.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($student_id) {
    $query .= " AND w.student_id = ?";
    $params[] = $student_id;
    $types .= "i";
}

$query .= " ORDER BY w.created_at DESC";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$waivers_result = $stmt->get_result();
$waivers = $waivers_result->fetch_all(MYSQLI_ASSOC);

// Helper function to apply fee waiver
function applyFeeWaiver($waiver_id, $conn)
{
    // Get waiver details
    $sql = "SELECT * FROM fee_waivers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $waiver_id);
    $stmt->execute();
    $waiver = $stmt->get_result()->fetch_assoc();

    if (!$waiver) return false;

    // Get student's financial status for this program
    $student_sql = "SELECT sfs.* 
                   FROM student_financial_status sfs
                   JOIN enrollments e ON e.student_id = sfs.student_id AND e.class_id = sfs.class_id
                   JOIN class_batches cb ON cb.id = e.class_id
                   JOIN programs p ON p.id = cb.course_id
                   WHERE sfs.student_id = ? AND p.id = (
                       SELECT program_id FROM fee_structures WHERE id = ?
                   )";

    $stmt = $conn->prepare($student_sql);
    $stmt->bind_param("ii", $waiver['student_id'], $waiver['fee_structure_id']);
    $stmt->execute();
    $student_finance = $stmt->get_result()->fetch_assoc();

    if ($student_finance) {
        // Calculate waiver amount
        $waiver_amount = 0;
        if ($waiver['waiver_type'] === 'percentage') {
            $waiver_amount = ($student_finance['total_fee'] * $waiver['waiver_value']) / 100;
        } elseif ($waiver['waiver_type'] === 'fixed_amount') {
            $waiver_amount = $waiver['waiver_value'];
        } elseif ($waiver['waiver_type'] === 'full') {
            $waiver_amount = $student_finance['total_fee'];
        }

        // Update student financial status
        $update_sql = "UPDATE student_financial_status 
                      SET total_fee = total_fee - ?,
                          balance = balance - ?
                      WHERE student_id = ? AND class_id = ?";

        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param(
            "ddii",
            $waiver_amount,
            $waiver_amount,
            $waiver['student_id'],
            $student_finance['class_id']
        );
        $stmt->execute();

        return true;
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Waivers Management - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reuse styles from index.php */
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --dark-light: #334155;
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

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary);
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .waiver-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .waiver-card h3 {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 0.75rem;
        }

        .col-md-4 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
            padding: 0 0.75rem;
        }

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 0.75rem;
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
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
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
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

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-expired {
            background: #f1f5f9;
            color: #64748b;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
        }

        .waiver-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-percentage {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-fixed {
            background: #dcfce7;
            color: #166534;
        }

        .badge-full {
            background: #f3e8ff;
            color: #6b21a8;
        }

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
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }
    </style>
    <script>
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openStatusModal(waiverId, currentStatus) {
            document.getElementById('statusWaiverId').value = waiverId;
            document.getElementById('currentStatus').value = currentStatus;
            document.getElementById('statusModal').style.display = 'flex';
        }

        function confirmDelete(waiverId) {
            if (confirm('Are you sure you want to delete this waiver request?')) {
                document.getElementById('deleteWaiverId').value = waiverId;
                document.getElementById('deleteForm').submit();
            }
        }

        function updateWaiverType() {
            const type = document.getElementById('waiver_type').value;
            const valueInput = document.getElementById('waiver_value');
            const valueLabel = document.getElementById('waiver_value_label');

            if (type === 'percentage') {
                valueLabel.innerHTML = 'Waiver Percentage (%)';
                valueInput.max = 100;
                valueInput.min = 0;
                valueInput.step = 0.1;
            } else if (type === 'fixed_amount') {
                valueLabel.innerHTML = 'Waiver Amount (₦)';
                valueInput.max = 1000000;
                valueInput.min = 0;
                valueInput.step = 0.01;
            } else if (type === 'full') {
                valueLabel.innerHTML = 'Waiver Amount';
                valueInput.value = 100;
                valueInput.readOnly = true;
                valueInput.max = 100;
                valueInput.min = 100;
            }
        }
    </script>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar would be included from common template -->
        <div class="main-content">
            <div class="header">
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Fee Structures
                </a>
                <h1>
                    <i class="fas fa-percentage"></i>
                    Fee Waivers Management
                </h1>
                <p>Manage fee discounts, scholarships, and financial aid</p>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <h3>Filter Waivers</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Fee Structure</label>
                        <select name="fee_structure_id" class="form-control">
                            <option value="">All Structures</option>
                            <?php foreach ($fee_structures as $structure): ?>
                                <option value="<?= $structure['id'] ?>"
                                    <?= $structure['id'] == $fee_structure_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($structure['program_name']) ?> - <?= htmlspecialchars($structure['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?= $status == 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status == 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="expired" <?= $status == 'expired' ? 'selected' : '' ?>>Expired</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Student ID</label>
                        <input type="number" name="student_id" class="form-control"
                            value="<?= $student_id ?>" placeholder="Enter student ID">
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="waivers.php" class="btn">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="waiver-card">
                <div class="row">
                    <div class="col-md-6">
                        <h3>Quick Actions</h3>
                        <button onclick="openCreateModal()" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New Waiver
                        </button>
                        <a href="../students/overdue.php" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle"></i> View Overdue Students
                        </a>
                    </div>
                    <div class="col-md-6">
                        <h3>Statistics</h3>
                        <?php
                        $stats_sql = "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                            SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired
                            FROM fee_waivers";

                        if ($fee_structure_id) {
                            $stats_sql .= " WHERE fee_structure_id = $fee_structure_id";
                        }

                        $stats_result = $conn->query($stats_sql);
                        $stats = $stats_result->fetch_assoc();
                        ?>
                        <p>Total: <?= $stats['total'] ?> |
                            Pending: <?= $stats['pending'] ?> |
                            Approved: <?= $stats['approved'] ?> |
                            Expired: <?= $stats['expired'] ?></p>
                    </div>
                </div>
            </div>

            <!-- Waivers List -->
            <div class="waiver-card">
                <h3>Fee Waivers List</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Waiver Type</th>
                                <th>Amount</th>
                                <th>Applicable To</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($waivers) > 0): ?>
                                <?php foreach ($waivers as $waiver): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($waiver['first_name'] . ' ' . $waiver['last_name']) ?></strong><br>
                                            <small><?= $waiver['email'] ?></small><br>
                                            <small>ID: <?= $waiver['student_id'] ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($waiver['program_name']) ?><br>
                                            <small><?= $waiver['program_code'] ?></small><br>
                                            <small>Fee: ₦<?= number_format($waiver['total_amount'], 2) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($waiver['waiver_type'] === 'percentage'): ?>
                                                <span class="waiver-type-badge badge-percentage">
                                                    <?= $waiver['waiver_value'] ?>%
                                                </span>
                                            <?php elseif ($waiver['waiver_type'] === 'fixed_amount'): ?>
                                                <span class="waiver-type-badge badge-fixed">
                                                    ₦<?= number_format($waiver['waiver_value'], 2) ?>
                                                </span>
                                            <?php elseif ($waiver['waiver_type'] === 'full'): ?>
                                                <span class="waiver-type-badge badge-full">
                                                    Full Waiver
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount">
                                            <?php
                                            $waiver_amount = 0;
                                            if ($waiver['waiver_type'] === 'percentage') {
                                                $waiver_amount = ($waiver['total_amount'] * $waiver['waiver_value']) / 100;
                                            } elseif ($waiver['waiver_type'] === 'fixed_amount') {
                                                $waiver_amount = $waiver['waiver_value'];
                                            } elseif ($waiver['waiver_type'] === 'full') {
                                                $waiver_amount = $waiver['total_amount'];
                                            }
                                            ?>
                                            ₦<?= number_format($waiver_amount, 2) ?>
                                        </td>
                                        <td>
                                            <?= ucfirst(str_replace('_', ' ', $waiver['applicable_blocks'])) ?>
                                            <?php if ($waiver['expiry_date']): ?>
                                                <br><small>Expires: <?= date('M d, Y', strtotime($waiver['expiry_date'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(substr($waiver['reason'], 0, 50)) ?>
                                            <?= strlen($waiver['reason']) > 50 ? '...' : '' ?>
                                        </td>
                                        <td>
                                            <?php if ($waiver['status'] === 'pending'): ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php elseif ($waiver['status'] === 'approved'): ?>
                                                <span class="status-badge status-approved">Approved</span>
                                                <?php if ($waiver['approver_first_name']): ?>
                                                    <br><small>By: <?= $waiver['approver_first_name'] . ' ' . $waiver['approver_last_name'] ?></small>
                                                <?php endif; ?>
                                            <?php elseif ($waiver['status'] === 'rejected'): ?>
                                                <span class="status-badge status-rejected">Rejected</span>
                                            <?php elseif ($waiver['status'] === 'expired'): ?>
                                                <span class="status-badge status-expired">Expired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('M d, Y', strtotime($waiver['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($waiver['status'] === 'pending'): ?>
                                                    <button onclick="openStatusModal(<?= $waiver['id'] ?>, '<?= $waiver['status'] ?>')"
                                                        class="btn btn-sm btn-success" title="Approve">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button onclick="openStatusModal(<?= $waiver['id'] ?>, '<?= $waiver['status'] ?>')"
                                                        class="btn btn-sm btn-danger" title="Reject">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <button onclick="confirmDelete(<?= $waiver['id'] ?>)"
                                                        class="btn btn-sm" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php elseif ($waiver['status'] === 'approved'): ?>
                                                    <span class="status-badge status-approved">Applied</span>
                                                <?php endif; ?>
                                                <a href="../students/view.php?id=<?= $waiver['student_id'] ?>"
                                                    class="btn btn-sm btn-info" title="View Student">
                                                    <i class="fas fa-user"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem;">
                                        <p>No fee waivers found with the current filters.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Waiver Modal -->
    <div class="modal" id="createModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Fee Waiver</h3>
                <button class="close-btn" onclick="closeModal('createModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_waiver">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Student ID</label>
                        <input type="number" name="student_id" class="form-control" required
                            placeholder="Enter student ID">
                    </div>

                    <div class="form-group">
                        <label>Fee Structure</label>
                        <select name="fee_structure_id" class="form-control" required>
                            <option value="">Select Fee Structure</option>
                            <?php foreach ($fee_structures as $structure): ?>
                                <option value="<?= $structure['id'] ?>">
                                    <?= htmlspecialchars($structure['program_name']) ?> - <?= htmlspecialchars($structure['name']) ?>
                                    (₦<?= number_format($structure['total_amount'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Waiver Type</label>
                        <select name="waiver_type" id="waiver_type" class="form-control" required onchange="updateWaiverType()">
                            <option value="">Select Type</option>
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed_amount">Fixed Amount (₦)</option>
                            <option value="full">Full Waiver (100%)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label id="waiver_value_label">Waiver Value</label>
                        <input type="number" id="waiver_value" name="waiver_value" class="form-control"
                            step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label>Applicable To</label>
                        <select name="applicable_blocks" class="form-control" required>
                            <option value="all">All Blocks</option>
                            <option value="registration_only">Registration Only</option>
                            <option value="block1_only">Block 1 Only</option>
                            <option value="block2_only">Block 2 Only</option>
                            <option value="block3_only">Block 3 Only</option>
                            <option value="blocks_1_2">Blocks 1 & 2 Only</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expiry Date (Optional)</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Reason for Waiver</label>
                        <textarea name="reason" class="form-control" rows="4" required
                            placeholder="Explain the reason for this fee waiver..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('createModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Waiver</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Waiver Status</h3>
                <button class="close-btn" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="waiver_id" id="statusWaiverId">
                <input type="hidden" name="currentStatus" id="currentStatus">
                <div class="modal-body">
                    <div class="form-group">
                        <label>New Status</label>
                        <select name="new_status" class="form-control" required>
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea name="admin_notes" class="form-control" rows="3"
                            placeholder="Add any notes for the student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('statusModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Form -->
    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete_waiver">
        <input type="hidden" name="waiver_id" id="deleteWaiverId">
    </form>
</body>

</html>
<?php $conn->close(); ?>