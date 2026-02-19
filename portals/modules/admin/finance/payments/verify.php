<?php
// Enable ALL error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'C:\xampp\htdocs\ida\verify_errors.log');

// Also log to a custom file for payment processing
$log_file = 'C:\xampp\htdocs\ida\payment_processing.log';
file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

// modules/admin/finance/payments/verify.php

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

// Handle POST request for verification
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

    if ($payment_id && $action) {
        // Get payment details
        $sql = "SELECT * FROM payment_verifications WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment = $result->fetch_assoc();
        $stmt->close();

        if ($payment) {

            if ($action === 'verify') {
                // Verify payment
                $sql = "UPDATE payment_verifications 
            SET status = 'verified', 
                verified_by = ?, 
                verified_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND status = 'pending'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $admin_id, $payment_id);

                if ($stmt->execute()) {
                    $stmt->close();

                    // **CRITICAL FIX**: Get fresh payment data after update
                    $sql = "SELECT * FROM payment_verifications WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $payment_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $payment = $result->fetch_assoc();
                    $stmt->close();

                    if (!$payment) {
                        $message = "Payment not found after verification!";
                        $message_type = "error";
                    } else {
                        // Process the payment based on type
                        $success = processPaymentVerification($payment_id, $conn);
                        error_log("=== Payment Verification Result: " . ($success ? "SUCCESS" : "FAILED") . " ===");

                        // Also add this to see the exact payment data:
                        error_log("=== Payment Data Being Processed ===");
                        error_log(print_r($payment, true));

                        if ($success) {
                            $message = "Payment verified and processed successfully!";
                            $message_type = "success";

                            // Update manual entry if exists
                            if (!empty($payment['manual_entry_id'])) {
                                $manual_update_sql = "UPDATE manual_payment_entries 
                                          SET status = 'verified',
                                              processed_at = NOW(),
                                              updated_at = NOW()
                                          WHERE id = ?";
                                $manual_stmt = $conn->prepare($manual_update_sql);
                                $manual_stmt->bind_param("i", $payment['manual_entry_id']);
                                $manual_stmt->execute();
                                $manual_stmt->close();
                            }
                        } else {
                            $message = "Payment marked as verified but processing failed! Check error logs.";
                            $message_type = "warning";
                        }
                    }
                } else {
                    $message = "Error verifying payment: " . $conn->error;
                    $message_type = "error";
                    $stmt->close();
                }
            } elseif ($action === 'reject') {
                // Reject payment
                if (empty($rejection_reason)) {
                    $message = "Rejection reason is required!";
                    $message_type = "error";
                } else {
                    $sql = "UPDATE payment_verifications 
                            SET status = 'rejected', 
                                rejection_reason = ?,
                                updated_at = NOW()
                            WHERE id = ? AND status = 'pending'";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("si", $rejection_reason, $payment_id);

                    if ($stmt->execute()) {
                        // Send notification to student
                        sendPaymentRejectionNotification($payment['student_id'], $payment['payment_reference'], $rejection_reason, $conn);

                        $message = "Payment rejected successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error rejecting payment: " . $conn->error;
                        $message_type = "error";
                    }
                    $stmt->close();
                }
            } elseif ($action === 'cancel') {
                // Cancel payment
                $sql = "UPDATE payment_verifications 
                        SET status = 'cancelled', 
                            updated_at = NOW()
                        WHERE id = ? AND status = 'pending'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $payment_id);

                if ($stmt->execute()) {
                    $message = "Payment cancelled successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error cancelling payment: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        } else {
            $message = "Payment not found!";
            $message_type = "error";
        }
    }
}

// Get pending payments with filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$where_conditions = [];
$params = [];
$types = "";

if ($filter_status === 'pending') {
    $where_conditions[] = "pv.status = 'pending'";
} elseif ($filter_status === 'verified') {
    $where_conditions[] = "pv.status = 'verified'";
} elseif ($filter_status === 'rejected') {
    $where_conditions[] = "pv.status = 'rejected'";
} elseif ($filter_status === 'all') {
    // Show all statuses
}

if ($filter_type) {
    $where_conditions[] = "pv.payment_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($search) {
    $where_conditions[] = "(pv.payment_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 4; $i++) {
        $params[] = $search_param;
        $types .= "s";
    }
}

$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM payment_verifications pv
              JOIN users u ON pv.student_id = u.id
              $where_sql";
$stmt = $conn->prepare($count_sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$count_result = $stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'];
$stmt->close();

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$total_pages = ceil($total_count / $per_page);
$offset = ($page - 1) * $per_page;

// Get payments
$sql = "SELECT pv.*, 
               u.first_name, u.last_name, u.email, u.phone,
               p.name as program_name,
               c.title as course_title,
               cb.batch_code
        FROM payment_verifications pv
        JOIN users u ON pv.student_id = u.id
        LEFT JOIN programs p ON pv.program_id = p.id
        LEFT JOIN courses c ON pv.course_id = c.id
        LEFT JOIN class_batches cb ON pv.class_id = cb.id
        $where_sql
        ORDER BY pv.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result();
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            justify-content: space-between;
            align-items: center;
        }

        .header-content h1 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-content h1 i {
            color: var(--primary);
        }

        .header-content p {
            color: var(--gray);
        }

        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        /* Message Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
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

        .alert-warning {
            background: #fef3c7;
            border-color: var(--warning);
            color: #92400e;
        }

        /* Filters */
        .filters {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
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

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Payments Table */
        .payments-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--primary);
            color: white;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }

        tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.3s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        td {
            padding: 1rem;
            color: var(--dark);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
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

        .status-cancelled {
            background: #e2e8f0;
            color: #475569;
        }

        /* Payment Type */
        .payment-type {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-block;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: block;
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: #f1f5f9;
            border-color: var(--gray);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            padding: 2rem;
            position: relative;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-header h3 {
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close-modal {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
        }

        .close-modal:hover {
            color: var(--dark);
        }

        .modal-body textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            resize: vertical;
            min-height: 100px;
        }

        .modal-body textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
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
            color: #cbd5e1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .nav-buttons {
                flex-direction: column;
                width: 100%;
            }

            .nav-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .filter-group {
                flex-direction: column;
            }

            .form-group {
                min-width: 100%;
            }

            .payments-table {
                overflow-x: auto;
            }

            table {
                min-width: 800px;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header with Navigation -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-check-circle"></i> Payment Verification</h1>
                <p>Verify and process student payments from payment verifications</p>
            </div>
            <div class="nav-buttons">
                <a href="manual_entry.php" class="btn btn-primary">
                    <i class="fas fa-whatsapp"></i> Manual Payment Entry
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/index.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Payment Dashboard
                </a>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'error' ? 'error' : 'warning'); ?>">
                <strong><?php echo ucfirst($message_type); ?>:</strong> <?php echo htmlspecialchars($message); ?>

                <?php
                // Display the last PHP error if available
                $last_error = error_get_last();
                if ($last_error && $message_type === 'error'): ?>
                    <br><br>
                    <strong>Technical Details:</strong><br>
                    <small><?php echo htmlspecialchars($last_error['message']); ?></small><br>
                    <small>File: <?php echo htmlspecialchars($last_error['file']); ?> (Line: <?php echo htmlspecialchars($last_error['line']); ?>)</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filter-group">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="verified" <?php echo $filter_status === 'verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="type">Payment Type</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
                            <option value="registration" <?php echo $filter_type === 'registration' ? 'selected' : ''; ?>>Registration</option>
                            <option value="course" <?php echo $filter_type === 'course' ? 'selected' : ''; ?>>Course</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" placeholder="Reference, Name, Email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="verify.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Payments Table -->
        <div class="payments-table">
            <?php if ($payments->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Details</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $payments->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong>
                                    <br>
                                    <small><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong>
                                    <br>
                                    <small><?php echo htmlspecialchars($payment['email']); ?></small>
                                    <br>
                                    <small><?php echo htmlspecialchars($payment['phone']); ?></small>
                                </td>
                                <td>
                                    <span class="payment-type">
                                        <?php echo ucfirst($payment['payment_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($payment['payment_type'] === 'registration'): ?>
                                        <strong>Program:</strong> <?php echo htmlspecialchars($payment['program_name']); ?>
                                    <?php else: ?>
                                        <strong>Course:</strong> <?php echo htmlspecialchars($payment['course_title']); ?>
                                        <br>
                                        <strong>Class:</strong> <?php echo htmlspecialchars($payment['batch_code']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>₦<?php echo number_format($payment['amount'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $payment['status']; ?>">
                                        <?php echo ucfirst($payment['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($payment['created_at'])); ?>
                                    <br>
                                    <small><?php echo date('g:i A', strtotime($payment['created_at'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($payment['status'] === 'pending'): ?>
                                        <div class="action-buttons">
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                <input type="hidden" name="action" value="verify">
                                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Verify this payment?')">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="openRejectModal(<?php echo $payment['id']; ?>)">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Cancel this payment?')">
                                                    <i class="fas fa-ban"></i> Cancel
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($payment['status'] === 'verified' && $payment['verified_by']): ?>
                                        <small>Verified by Admin</small>
                                        <br>
                                        <small><?php echo date('M j, Y', strtotime($payment['verified_at'])); ?></small>
                                    <?php elseif ($payment['status'] === 'rejected'): ?>
                                        <small>Rejected</small>
                                        <?php if ($payment['rejection_reason']): ?>
                                            <br>
                                            <small>Reason: <?php echo htmlspecialchars($payment['rejection_reason']); ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No payments found</h3>
                    <p>There are no payments matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <ul class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item">
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>&search=<?php echo urlencode($search); ?>"
                            class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Payment</h3>
                <button type="button" class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST" action="" id="rejectForm">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="rejectPaymentId">
                    <input type="hidden" name="action" value="reject">
                    <div class="form-group">
                        <label for="rejection_reason">Rejection Reason</label>
                        <textarea id="rejection_reason" name="rejection_reason"
                            placeholder="Please provide a reason for rejecting this payment..."
                            required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openRejectModal(paymentId) {
            document.getElementById('rejectPaymentId').value = paymentId;
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejection_reason').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target === modal) {
                closeRejectModal();
            }
        }

        // Auto-hide message alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Refresh page every 60 seconds to check for new payments
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>

</html>