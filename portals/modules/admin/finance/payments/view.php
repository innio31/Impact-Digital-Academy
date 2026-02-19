<?php
// modules/admin/finance/payments/view.php

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

// Get payment ID
$payment_id = $_GET['id'] ?? 0;
if (!$payment_id) {
    header('Location: index.php');
    exit();
}

// Get payment details
$sql = "SELECT ft.*, u.first_name, u.last_name, u.email, u.phone,
               cb.batch_code, c.title as course_title,
               p.name as program_name, p.program_type,
               sfs.total_fee, sfs.paid_amount, sfs.balance,
               verified_by_user.first_name as verified_by_first_name,
               verified_by_user.last_name as verified_by_last_name
        FROM financial_transactions ft
        JOIN users u ON u.id = ft.student_id
        JOIN class_batches cb ON cb.id = ft.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        LEFT JOIN student_financial_status sfs ON sfs.student_id = ft.student_id 
            AND sfs.class_id = ft.class_id
        LEFT JOIN users verified_by_user ON verified_by_user.id = ft.verified_by
        WHERE ft.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

if (!$payment) {
    $_SESSION['error'] = 'Payment not found.';
    header('Location: index.php');
    exit();
}

// Get student's other payments for this class
$other_payments_sql = "SELECT * FROM financial_transactions 
                      WHERE student_id = ? AND class_id = ? AND id != ?
                      ORDER BY created_at DESC";
$other_stmt = $conn->prepare($other_payments_sql);
$other_stmt->bind_param("iii", $payment['student_id'], $payment['class_id'], $payment_id);
$other_stmt->execute();
$other_payments_result = $other_stmt->get_result();
$other_payments = $other_payments_result->fetch_all(MYSQLI_ASSOC);

// Log activity
logActivity($_SESSION['user_id'], 'view_payment', "Viewed payment #$payment_id", 'financial_transactions', $payment_id);

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'verify' && !$payment['is_verified']) {
            $verify_sql = "UPDATE financial_transactions 
                          SET is_verified = 1, verified_at = NOW(), verified_by = ?
                          WHERE id = ?";
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->bind_param("ii", $_SESSION['user_id'], $payment_id);
            
            if ($verify_stmt->execute()) {
                $_SESSION['success'] = 'Payment verified successfully.';
                logActivity($_SESSION['user_id'], 'payment_verified', 
                    "Verified payment #$payment_id", 'financial_transactions', $payment_id);
                
                // Refresh page to show updated status
                header('Location: view.php?id=' . $payment_id);
                exit();
            }
        } elseif ($action === 'refund') {
            // Redirect to refund page
            header('Location: refund.php?id=' . $payment_id);
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details - Admin Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
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
            padding: 2rem;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
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
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
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
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .card h2 {
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            margin-bottom: 1rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 1rem;
            color: var(--dark);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .status-refunded { background: #e0f2fe; color: #0369a1; }
        
        .amount-large {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin: 1rem 0;
            text-align: center;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .transaction-log {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .log-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .log-item:last-child {
            border-bottom: none;
        }
        
        .log-time {
            color: #64748b;
            font-size: 0.85rem;
        }
        
        .log-action {
            font-weight: 500;
            margin-top: 0.25rem;
        }
        
        .other-payments {
            margin-top: 2rem;
        }
        
        .payment-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .payment-item:hover {
            background: #f8fafc;
        }
        
        .payment-amount {
            font-weight: 600;
            color: var(--primary);
        }
        
        .payment-date {
            color: #64748b;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-money-bill-wave"></i>
                Payment Details
            </h1>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Payments
                </a>
                <a href="receipt.php?id=<?php echo $payment_id; ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-receipt"></i> View Receipt
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="content-grid">
            <!-- Main Payment Details -->
            <div>
                <div class="card">
                    <h2>Transaction Information</h2>
                    
                    <div class="amount-large">
                        <?php echo formatCurrency($payment['amount']); ?>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Transaction ID</div>
                            <div class="info-value"><?php echo $payment['id']; ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Reference Number</div>
                            <div class="info-value"><?php echo $payment['gateway_reference']; ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Transaction Type</div>
                            <div class="info-value">
                                <?php echo ucfirst(str_replace('_', ' ', $payment['transaction_type'])); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Payment Method</div>
                            <div class="info-value">
                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo $payment['status']; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Verified</div>
                            <div class="info-value">
                                <?php if ($payment['is_verified']): ?>
                                    <span style="color: var(--success);">
                                        <i class="fas fa-check-circle"></i> Yes
                                        <?php if ($payment['verified_by']): ?>
                                            <br><small>by <?php echo $payment['verified_by_first_name'] . ' ' . $payment['verified_by_last_name']; ?></small>
                                            <?php if ($payment['verified_at']): ?>
                                                <br><small>on <?php echo date('M j, Y g:i A', strtotime($payment['verified_at'])); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--warning);">
                                        <i class="fas fa-clock"></i> Pending Verification
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Transaction Date</div>
                            <div class="info-value">
                                <?php echo date('F j, Y', strtotime($payment['created_at'])); ?><br>
                                <small style="color: #64748b;"><?php echo date('g:i A', strtotime($payment['created_at'])); ?></small>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Last Updated</div>
                            <div class="info-value">
                                <?php echo date('F j, Y', strtotime($payment['updated_at'])); ?><br>
                                <small style="color: #64748b;"><?php echo date('g:i A', strtotime($payment['updated_at'])); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($payment['description']): ?>
                        <div class="info-item">
                            <div class="info-label">Description</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($payment['description'])); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($payment['payment_gateway']): ?>
                        <div class="info-item">
                            <div class="info-label">Payment Gateway</div>
                            <div class="info-value"><?php echo ucfirst($payment['payment_gateway']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <form method="POST" id="paymentActions">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="action-buttons">
                            <?php if (!$payment['is_verified'] && $payment['status'] === 'completed'): ?>
                                <button type="submit" name="action" value="verify" class="btn btn-success">
                                    <i class="fas fa-check"></i> Verify Payment
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($payment['status'] === 'completed' && !$payment['is_verified']): ?>
                                <button type="submit" name="action" value="refund" class="btn btn-warning">
                                    <i class="fas fa-undo"></i> Initiate Refund
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($payment['receipt_url']): ?>
                                <a href="<?php echo BASE_URL . $payment['receipt_url']; ?>" 
                                   target="_blank" class="btn btn-primary">
                                    <i class="fas fa-print"></i> Print Receipt
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($payment['status'] === 'pending'): ?>
                                <button type="button" onclick="markAsCompleted(<?php echo $payment_id; ?>)" 
                                        class="btn btn-success">
                                    <i class="fas fa-check-circle"></i> Mark as Completed
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($payment['status'] === 'failed'): ?>
                                <button type="button" onclick="retryPayment(<?php echo $payment_id; ?>)" 
                                        class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Retry Payment
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Student Information -->
                <div class="card" style="margin-top: 1.5rem;">
                    <h2>Student Information</h2>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Student Name</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo $payment['email']; ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo $payment['phone'] ?: 'N/A'; ?></div>
                        </div>
                    </div>
                    
                    <!-- Financial Status -->
                    <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0;">
                        <h3 style="margin-bottom: 1rem;">Financial Status for this Class</h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; text-align: center;">
                            <div style="background: #f8fafc; padding: 1rem; border-radius: 6px;">
                                <div style="font-size: 0.85rem; color: #64748b;">Total Fee</div>
                                <div style="font-size: 1.25rem; font-weight: 600; color: var(--dark);">
                                    <?php echo formatCurrency($payment['total_fee']); ?>
                                </div>
                            </div>
                            
                            <div style="background: #f0f9ff; padding: 1rem; border-radius: 6px;">
                                <div style="font-size: 0.85rem; color: #64748b;">Paid Amount</div>
                                <div style="font-size: 1.25rem; font-weight: 600; color: var(--success);">
                                    <?php echo formatCurrency($payment['paid_amount']); ?>
                                </div>
                            </div>
                            
                            <div style="background: #fef2f2; padding: 1rem; border-radius: 6px;">
                                <div style="font-size: 0.85rem; color: #64748b;">Balance</div>
                                <div style="font-size: 1.25rem; font-weight: 600; color: var(--danger);">
                                    <?php echo formatCurrency($payment['balance']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div>
                <!-- Program Information -->
                <div class="card">
                    <h2>Program Information</h2>
                    
                    <div class="info-item">
                        <div class="info-label">Program</div>
                        <div class="info-value"><?php echo htmlspecialchars($payment['program_name']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Course</div>
                        <div class="info-value"><?php echo htmlspecialchars($payment['course_title']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Batch</div>
                        <div class="info-value"><?php echo $payment['batch_code']; ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Program Type</div>
                        <div class="info-value">
                            <span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 4px; 
                                  background: <?php echo $payment['program_type'] === 'online' ? '#dbeafe' : '#dcfce7'; ?>; 
                                  color: <?php echo $payment['program_type'] === 'online' ? '#1e40af' : '#166534'; ?>;">
                                <?php echo ucfirst($payment['program_type']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="action-buttons" style="border-top: none; margin-top: 1rem; padding-top: 0;">
                        <a href="<?php echo BASE_URL; ?>modules/admin/academic/programs/view.php?code=<?php echo urlencode($payment['program_name']); ?>" 
                           class="btn btn-secondary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-external-link-alt"></i> View Program
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/admin/users/view.php?id=<?php echo $payment['student_id']; ?>" 
                           class="btn btn-secondary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-user"></i> View Student Profile
                        </a>
                    </div>
                </div>
                
                <!-- Other Payments -->
                <?php if (!empty($other_payments)): ?>
                    <div class="card" style="margin-top: 1.5rem;">
                        <h2>Other Payments</h2>
                        <div class="other-payments">
                            <?php foreach ($other_payments as $other_payment): ?>
                                <a href="view.php?id=<?php echo $other_payment['id']; ?>" 
                                   style="text-decoration: none; color: inherit;">
                                    <div class="payment-item">
                                        <div>
                                            <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                                <?php echo date('M j, Y', strtotime($other_payment['created_at'])); ?>
                                            </div>
                                            <div class="payment-date">
                                                <?php echo ucfirst(str_replace('_', ' ', $other_payment['transaction_type'])); ?>
                                            </div>
                                        </div>
                                        <div class="payment-amount">
                                            <?php echo formatCurrency($other_payment['amount']); ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Quick Actions -->
                <div class="card" style="margin-top: 1.5rem;">
                    <h2>Quick Actions</h2>
                    
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="offline_entry.php?student_id=<?php echo $payment['student_id']; ?>&class_id=<?php echo $payment['class_id']; ?>" 
                           class="btn btn-primary" style="justify-content: center;">
                            <i class="fas fa-plus"></i> Record Another Payment
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/invoices/generate.php?student_id=<?php echo $payment['student_id']; ?>&class_id=<?php echo $payment['class_id']; ?>" 
                           class="btn btn-success" style="justify-content: center;">
                            <i class="fas fa-file-invoice"></i> Generate Invoice
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>modules/admin/finance/students/view.php?student_id=<?php echo $payment['student_id']; ?>" 
                           class="btn btn-info" style="justify-content: center;">
                            <i class="fas fa-chart-line"></i> View Student Finance
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function markAsCompleted(paymentId) {
            if (confirm('Mark this payment as completed?')) {
                fetch('ajax/mark_completed.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'payment_id=' + paymentId + '&csrf_token=<?php echo generateCSRFToken(); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Payment marked as completed!');
                        window.location.reload();
                    } else {
                        alert('Failed to update payment: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Error updating payment: ' + error);
                });
            }
        }
        
        function retryPayment(paymentId) {
            if (confirm('Retry this payment? This will create a new pending payment.')) {
                window.location.href = 'retry.php?id=' + paymentId;
            }
        }
        
        // Confirm form submission
        document.getElementById('paymentActions').addEventListener('submit', function(e) {
            const action = e.submitter?.value;
            
            if (action === 'verify') {
                if (!confirm('Are you sure you want to verify this payment?')) {
                    e.preventDefault();
                }
            } else if (action === 'refund') {
                if (!confirm('Are you sure you want to initiate a refund for this payment?')) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>