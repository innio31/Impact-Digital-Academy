<?php
// modules/admin/finance/invoices/view.php

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

// Get invoice ID
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$invoice_id) {
    header('Location: index.php');
    exit();
}

// Get invoice details
$sql = "SELECT i.*, 
               u.first_name, u.last_name, u.email, u.phone,
               cb.batch_code, c.title as course_title,
               p.name as program_name, p.program_type,
               (SELECT SUM(amount) FROM financial_transactions 
                WHERE invoice_id = i.id AND status = 'completed') as total_payments
        FROM invoices i
        JOIN users u ON u.id = i.student_id
        JOIN class_batches cb ON cb.id = i.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        WHERE i.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header('Location: index.php');
    exit();
}

// Get payment history for this invoice
$payments_sql = "SELECT ft.*, 
                        u2.first_name as recorded_first, u2.last_name as recorded_last
                 FROM financial_transactions ft
                 LEFT JOIN users u2 ON u2.id = ft.verified_by
                 WHERE ft.invoice_id = ?
                 ORDER BY ft.created_at DESC";
$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("i", $invoice_id);
$payments_stmt->execute();
$payments = $payments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate remaining balance
$total_paid = $invoice['total_payments'] ?: 0;
$balance = $invoice['amount'] - $total_paid;

// Update invoice balance if needed
if ($invoice['balance'] != $balance) {
    $update_sql = "UPDATE invoices SET balance = ?, paid_amount = ?, 
                    status = CASE 
                        WHEN ? >= amount THEN 'paid'
                        WHEN ? > 0 AND ? < amount THEN 'partial'
                        WHEN due_date < CURDATE() THEN 'overdue'
                        ELSE 'pending'
                    END
                    WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("dddii", $balance, $total_paid, $total_paid, $total_paid, $total_paid, $invoice_id);
    $update_stmt->execute();

    // Refresh invoice data
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
}

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    switch ($action) {
        case 'update_status':
            $new_status = $_POST['status'];
            $update_sql = "UPDATE invoices SET status = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $new_status, $invoice_id);
            $update_stmt->execute();

            logActivity(
                $_SESSION['user_id'],
                'invoice_status_updated',
                "Updated invoice #{$invoice['invoice_number']} status to $new_status"
            );

            $_SESSION['flash_message'] = "Invoice status updated successfully";
            header("Location: view.php?id=$invoice_id");
            exit();
            break;

        case 'add_note':
            $note = $_POST['note'];
            $notes_sql = "UPDATE invoices SET notes = CONCAT(notes, '\n', ?, ' - ', NOW()) WHERE id = ?";
            $notes_stmt = $conn->prepare($notes_sql);
            $notes_stmt->bind_param("si", $note, $invoice_id);
            $notes_stmt->execute();

            logActivity(
                $_SESSION['user_id'],
                'invoice_note_added',
                "Added note to invoice #{$invoice['invoice_number']}"
            );

            header("Location: view.php?id=$invoice_id");
            exit();
            break;
    }
}

// Log view activity
logActivity(
    $_SESSION['user_id'],
    'invoice_viewed',
    "Viewed invoice #{$invoice['invoice_number']}"
);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Details - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        body {
            background-color: #f1f5f9;
            color: #1e293b;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .header h1 {
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.8rem;
            margin: 0;
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
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
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

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .invoice-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .invoice-title {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0 0 0.5rem 0;
        }

        .invoice-subtitle {
            color: #64748b;
            margin: 0;
        }

        .invoice-amount {
            text-align: right;
        }

        .amount-total {
            font-size: 2rem;
            font-weight: bold;
            color: #065f46;
            margin: 0;
        }

        .amount-label {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-section {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
        }

        .detail-section h3 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-label {
            color: #64748b;
        }

        .detail-value {
            font-weight: 500;
            text-align: right;
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

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #f1f5f9;
            color: #64748b;
        }

        .payment-summary {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .payment-summary h3 {
            margin: 0 0 1rem 0;
            color: #065f46;
        }

        .payment-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .payment-stat {
            text-align: center;
        }

        .payment-stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 0.25rem;
        }

        .payment-stat-label {
            color: #64748b;
            font-size: 0.85rem;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .action-desc {
            color: #64748b;
            font-size: 0.85rem;
        }

        .notes-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-top: 2rem;
        }

        .notes-section h3 {
            margin: 0 0 1rem 0;
            color: #1e293b;
        }

        .notes-content {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            min-height: 100px;
            white-space: pre-wrap;
        }

        .form-group {
            margin-bottom: 1rem;
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

        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
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
            color: #1e293b;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-file-invoice-dollar"></i>
                Invoice Details
            </h1>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="generate.php?duplicate=1&id=<?php echo $invoice_id; ?>" class="btn btn-info">
                    <i class="fas fa-copy"></i> Duplicate
                </a>
                <a href="#" onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php
                echo $_SESSION['flash_message'];
                unset($_SESSION['flash_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="content-grid">
            <!-- Left Column: Invoice Details -->
            <div>
                <!-- Invoice Card -->
                <div class="invoice-card">
                    <div class="invoice-header">
                        <div>
                            <h2 class="invoice-title">
                                Invoice #<?php echo $invoice['invoice_number']; ?>
                            </h2>
                            <p class="invoice-subtitle">
                                Generated on <?php echo date('F j, Y', strtotime($invoice['created_at'])); ?>
                            </p>
                            <span class="status-badge status-<?php echo $invoice['status']; ?>">
                                <?php echo ucfirst($invoice['status']); ?>
                            </span>
                        </div>
                        <div class="invoice-amount">
                            <p class="amount-total"><?php echo formatCurrency($invoice['amount']); ?></p>
                            <p class="amount-label">Total Amount</p>
                        </div>
                    </div>

                    <!-- Invoice Details -->
                    <div class="details-grid">
                        <!-- Student Details -->
                        <div class="detail-section">
                            <h3>Student Details</h3>
                            <div class="detail-item">
                                <span class="detail-label">Name:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Email:</span>
                                <span class="detail-value"><?php echo $invoice['email']; ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Phone:</span>
                                <span class="detail-value"><?php echo $invoice['phone'] ?: 'N/A'; ?></span>
                            </div>
                        </div>

                        <!-- Program Details -->
                        <div class="detail-section">
                            <h3>Program Details</h3>
                            <div class="detail-item">
                                <span class="detail-label">Program:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($invoice['program_name']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Course:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($invoice['course_title']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Batch:</span>
                                <span class="detail-value"><?php echo $invoice['batch_code']; ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Program Type:</span>
                                <span class="detail-value">
                                    <?php echo ucfirst($invoice['program_type']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Invoice Details -->
                        <div class="detail-section">
                            <h3>Invoice Details</h3>
                            <div class="detail-item">
                                <span class="detail-label">Invoice Type:</span>
                                <span class="detail-value">
                                    <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_type'])); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Due Date:</span>
                                <span class="detail-value">
                                    <?php echo date('F j, Y', strtotime($invoice['due_date'])); ?>
                                    <?php
                                    $days_until_due = floor((strtotime($invoice['due_date']) - time()) / (60 * 60 * 24));
                                    if ($days_until_due < 0) {
                                        echo '<br><small style="color: var(--danger);">' . abs($days_until_due) . ' days overdue</small>';
                                    } elseif ($days_until_due <= 7) {
                                        echo '<br><small style="color: var(--warning);">Due in ' . $days_until_due . ' days</small>';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Description:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($invoice['description'] ?: 'No description provided'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="payment-summary">
                        <h3>Payment Summary</h3>
                        <div class="payment-stats">
                            <div class="payment-stat">
                                <div class="payment-stat-value"><?php echo formatCurrency($invoice['amount']); ?></div>
                                <div class="payment-stat-label">Total Amount</div>
                            </div>
                            <div class="payment-stat">
                                <div class="payment-stat-value"><?php echo formatCurrency($total_paid); ?></div>
                                <div class="payment-stat-label">Total Paid</div>
                            </div>
                            <div class="payment-stat">
                                <div class="payment-stat-value"><?php echo formatCurrency($balance); ?></div>
                                <div class="payment-stat-label">Remaining Balance</div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="actions-grid">
                        <a href="send.php?id=<?php echo $invoice_id; ?>" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="action-title">Send Invoice</div>
                            <div class="action-desc">Send to student</div>
                        </a>

                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?invoice_id=<?php echo $invoice_id; ?>" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="action-title">Record Payment</div>
                            <div class="action-desc">Manual payment entry</div>
                        </a>

                        <a href="#" class="action-card" onclick="event.preventDefault(); document.getElementById('statusForm').style.display='block'">
                            <div class="action-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div class="action-title">Update Status</div>
                            <div class="action-desc">Change invoice status</div>
                        </a>

                        <a href="#" class="action-card" onclick="window.print()">
                            <div class="action-icon">
                                <i class="fas fa-print"></i>
                            </div>
                            <div class="action-title">Print Invoice</div>
                            <div class="action-desc">Print or save as PDF</div>
                        </a>
                    </div>
                </div>

                <!-- Update Status Form -->
                <div class="invoice-card" id="statusForm" style="display: none;">
                    <h3>Update Invoice Status</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <div class="form-group">
                            <select name="status" class="form-control" required>
                                <option value="pending" <?php echo $invoice['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo $invoice['status'] === 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                                <option value="paid" <?php echo $invoice['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo $invoice['status'] === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="cancelled" <?php echo $invoice['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 1rem;">
                            <button type="submit" class="btn btn-primary">Update Status</button>
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('statusForm').style.display='none'">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Payment History & Notes -->
            <div>
                <!-- Payment History -->
                <div class="invoice-card">
                    <h3 style="margin: 0 0 1.5rem 0;">Payment History</h3>

                    <?php if (!empty($payments)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Verified By</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($payment['created_at'])); ?><br>
                                                <small style="color: #64748b;"><?php echo date('g:i a', strtotime($payment['created_at'])); ?></small>
                                            </td>
                                            <td style="font-weight: 600;">
                                                <?php echo formatCurrency($payment['amount']); ?>
                                            </td>
                                            <td>
                                                <?php echo ucfirst($payment['payment_method'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php if ($payment['verified_by']): ?>
                                                    <?php echo htmlspecialchars($payment['recorded_first'] . ' ' . $payment['recorded_last']); ?>
                                                <?php else: ?>
                                                    <span style="color: #64748b;">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; 
                                                      background: <?php echo $payment['status'] === 'completed' ? '#d1fae5' : '#fef3c7'; ?>; 
                                                      color: <?php echo $payment['status'] === 'completed' ? '#065f46' : '#92400e'; ?>;">
                                                    <?php echo ucfirst($payment['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <h4>No Payments Recorded</h4>
                            <p>No payments have been recorded for this invoice yet.</p>
                            <a href="<?php echo BASE_URL; ?>modules/admin/finance/payments/offline_entry.php?invoice_id=<?php echo $invoice_id; ?>"
                                class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Record Payment
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Internal Notes -->
                <div class="invoice-card">
                    <h3 style="margin: 0 0 1.5rem 0;">Internal Notes</h3>

                    <div class="notes-content">
                        <?php echo $invoice['notes'] ? nl2br(htmlspecialchars($invoice['notes'])) : 'No notes added yet.'; ?>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="add_note">
                        <div class="form-group">
                            <textarea name="note" class="form-control" rows="3"
                                placeholder="Add an internal note about this invoice..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Note
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Print invoice
        function printInvoice() {
            window.print();
        }

        // Download as PDF (would need PDF generation library)
        function downloadPDF() {
            alert('PDF generation feature would require a PDF library like TCPDF or mPDF');
            // In production, this would call a PDF generation script
            // window.open('generate_pdf.php?id=<?php echo $invoice_id; ?>', '_blank');
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>