<?php
// modules/admin/finance/invoices/send.php

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
               p.name as program_name
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

// Process sending
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $send_method = $_POST['send_method'];
    $custom_message = $_POST['custom_message'];

    try {
        switch ($send_method) {
            case 'email':
                $sent = sendInvoiceNotification($student_id, $invoice_id, $invoice_number, $amount);
                if ($sent) {
                    $success = true;

                    // Update sent timestamp
                    $update_sql = "UPDATE invoices SET sent_at = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("i", $invoice_id);
                    $update_stmt->execute();

                    logActivity(
                        $_SESSION['user_id'],
                        'invoice_sent_email',
                        "Sent invoice #{$invoice['invoice_number']} to {$invoice['email']}"
                    );
                } else {
                    $error = "Failed to send email";
                }
                break;

            case 'sms':
                if (sendInvoiceSMS($invoice['student_id'], $invoice_id, $invoice['invoice_number'], $invoice['amount'], $custom_message)) {
                    $success = true;
                    logActivity(
                        $_SESSION['user_id'],
                        'invoice_sent_sms',
                        "Sent SMS for invoice #{$invoice['invoice_number']} to {$invoice['phone']}"
                    );
                } else {
                    $error = "Failed to send SMS";
                }
                break;

            case 'both':
                $email_sent = sendInvoiceNotification($invoice['student_id'], $invoice_id, $invoice['invoice_number'], $invoice['amount']);
                $sms_sent = sendInvoiceSMS($invoice['student_id'], $invoice_id, $invoice['invoice_number'], $invoice['amount'], $custom_message);

                if ($email_sent || $sms_sent) {
                    $success = true;

                    if ($email_sent) {
                        $update_sql = "UPDATE invoices SET sent_at = NOW() WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("i", $invoice_id);
                        $update_stmt->execute();
                    }

                    logActivity(
                        $_SESSION['user_id'],
                        'invoice_sent_both',
                        "Sent invoice #{$invoice['invoice_number']} via email and SMS"
                    );
                } else {
                    $error = "Failed to send both email and SMS";
                }
                break;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Invoice - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
        }

        body {
            background-color: #f1f5f9;
            color: #1e293b;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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

        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
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

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .invoice-preview {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .invoice-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
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
        }

        .form-group {
            margin-bottom: 1.5rem;
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

        .send-method-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .method-card {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .method-card:hover {
            border-color: var(--primary);
            background: #f0f9ff;
        }

        .method-card.selected {
            border-color: var(--primary);
            background: #dbeafe;
        }

        .method-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .method-card h4 {
            margin: 0 0 0.5rem 0;
            color: #1e293b;
        }

        .method-card p {
            margin: 0;
            color: #64748b;
            font-size: 0.85rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: #065f46;
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-paper-plane"></i>
                Send Invoice
            </h1>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Invoices
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Invoice sent successfully!</strong>
                    <p>The invoice has been sent to the student.</p>
                </div>
            </div>
            <div class="text-center">
                <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary">
                    <i class="fas fa-eye"></i> View Invoice
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> Back to List
                </a>
            </div>
            <?php exit(); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error sending invoice:</strong>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Invoice Preview -->
        <div class="invoice-preview">
            <div class="invoice-header">
                <div>
                    <h2 style="margin: 0 0 0.5rem 0; color: var(--primary);">
                        Invoice #<?php echo $invoice['invoice_number']; ?>
                    </h2>
                    <p style="color: #64748b; margin: 0;">
                        Generated on <?php echo date('F j, Y', strtotime($invoice['created_at'])); ?>
                    </p>
                </div>
                <div class="amount-display">
                    <?php echo formatCurrency($invoice['amount']); ?>
                </div>
            </div>

            <div class="invoice-details">
                <div>
                    <h4 style="margin: 0 0 0.5rem 0; color: #1e293b;">Student Details</h4>
                    <p style="margin: 0; color: #64748b;">
                        <strong><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></strong><br>
                        <?php echo $invoice['email']; ?><br>
                        <?php echo $invoice['phone'] ?: 'Phone: N/A'; ?>
                    </p>
                </div>
                <div>
                    <h4 style="margin: 0 0 0.5rem 0; color: #1e293b;">Program Details</h4>
                    <p style="margin: 0; color: #64748b;">
                        <strong><?php echo htmlspecialchars($invoice['program_name']); ?></strong><br>
                        <?php echo htmlspecialchars($invoice['course_title']); ?><br>
                        Batch: <?php echo $invoice['batch_code']; ?>
                    </p>
                </div>
            </div>

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
                    <?php if (strtotime($invoice['due_date']) < time()): ?>
                        <span style="color: #ef4444; margin-left: 0.5rem;">(Overdue)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Status:</span>
                <span class="detail-value">
                    <?php echo ucfirst($invoice['status']); ?>
                </span>
            </div>
            <?php if ($invoice['description']): ?>
                <div class="detail-item">
                    <span class="detail-label">Description:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($invoice['description']); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Send Form -->
        <form method="POST">
            <!-- Send Method -->
            <div class="form-group">
                <label>Select Send Method</label>
                <div class="send-method-grid">
                    <div class="method-card" onclick="selectSendMethod('email')">
                        <div class="method-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h4>Email Only</h4>
                        <p>Send to student's email address</p>
                        <small style="color: #64748b;">
                            <?php echo $invoice['email']; ?>
                        </small>
                    </div>
                    <div class="method-card" onclick="selectSendMethod('sms')">
                        <div class="method-icon">
                            <i class="fas fa-comment-alt"></i>
                        </div>
                        <h4>SMS Only</h4>
                        <p>Send SMS to student's phone</p>
                        <small style="color: #64748b;">
                            <?php echo $invoice['phone'] ?: 'Phone not available'; ?>
                        </small>
                    </div>
                    <div class="method-card" onclick="selectSendMethod('both')">
                        <div class="method-icon">
                            <i class="fas fa-broadcast-tower"></i>
                        </div>
                        <h4>Both Email & SMS</h4>
                        <p>Send via both channels</p>
                        <small style="color: #64748b;">
                            Recommended for urgent payments
                        </small>
                    </div>
                </div>
                <input type="hidden" name="send_method" id="send_method" required>
            </div>

            <!-- Custom Message -->
            <div class="form-group">
                <label for="custom_message">Custom Message (Optional)</label>
                <textarea name="custom_message" id="custom_message" class="form-control" rows="4"
                    placeholder="Add a custom message to include with the invoice notification...">
Dear <?php echo htmlspecialchars($invoice['first_name']); ?>,

Your invoice for <?php echo htmlspecialchars($invoice['program_name']); ?> - <?php echo htmlspecialchars($invoice['course_title']); ?> has been generated.

Invoice Number: <?php echo $invoice['invoice_number']; ?>
Amount Due: <?php echo formatCurrency($invoice['amount']); ?>
Due Date: <?php echo date('F j, Y', strtotime($invoice['due_date'])); ?>

Please make payment before the due date to avoid any late fees.

Thank you,
Impact Digital Academy Finance Team
                </textarea>
                <small style="color: #64748b;">This message will be included in the email/SMS notification.</small>
            </div>

            <!-- Last Sent Info -->
            <?php if ($invoice['sent_at']): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>This invoice was last sent on:</strong>
                        <p><?php echo date('F j, Y, g:i a', strtotime($invoice['sent_at'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary" onclick="return confirmSend()">
                    <i class="fas fa-paper-plane"></i> Send Invoice
                </button>
            </div>
        </form>
    </div>

    <script>
        // Select send method
        function selectSendMethod(method) {
            document.getElementById('send_method').value = method;

            // Update UI
            document.querySelectorAll('.method-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }

        // Confirm send
        function confirmSend() {
            const method = document.getElementById('send_method').value;
            if (!method) {
                alert('Please select a send method');
                return false;
            }

            let message = `Send invoice via ${method.toUpperCase()}?`;
            if (method === 'sms' && !<?php echo $invoice['phone'] ? 'true' : 'false'; ?>) {
                message += '\n\nNote: Student phone number is not available. SMS may not be delivered.';
            }

            return confirm(message);
        }

        // Auto-select email by default
        document.addEventListener('DOMContentLoaded', function() {
            selectSendMethod('email');
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>