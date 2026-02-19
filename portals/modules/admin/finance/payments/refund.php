<?php
// modules/admin/finance/payments/refund.php

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
$bulk_ids = $_SESSION['bulk_refund_ids'] ?? [];

// Get payment details if single refund
$payment = null;
if ($payment_id) {
    $sql = "SELECT ft.*, u.first_name, u.last_name, u.email
            FROM financial_transactions ft
            JOIN users u ON u.id = ft.student_id
            WHERE ft.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
}

// Process refund
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $refund_amount = floatval($_POST['refund_amount']);
        $refund_reason = $_POST['refund_reason'] ?? '';
        $refund_method = $_POST['refund_method'] ?? 'bank_transfer';
        
        if ($refund_amount <= 0) {
            $_SESSION['error'] = 'Invalid refund amount.';
        } else {
            // Process refund (this would integrate with payment gateway)
            // For now, we'll just update the status
            
            $update_sql = "UPDATE financial_transactions 
                          SET status = 'refunded', updated_at = NOW()
                          WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $payment_id);
            
            if ($update_stmt->execute()) {
                // Create refund transaction record
                $refund_sql = "INSERT INTO financial_transactions 
                              (student_id, class_id, transaction_type, payment_method, 
                               amount, description, status, created_at)
                              SELECT student_id, class_id, 'refund', ?, ?, 
                                     ?, 'completed', NOW()
                              FROM financial_transactions 
                              WHERE id = ?";
                $refund_stmt = $conn->prepare($refund_sql);
                $description = "Refund for payment #$payment_id: $refund_reason";
                $refund_stmt->bind_param("sdsi", $refund_method, $refund_amount, $description, $payment_id);
                $refund_stmt->execute();
                
                $_SESSION['success'] = 'Refund processed successfully.';
                
                // Log activity
                logActivity($_SESSION['user_id'], 'payment_refunded', 
                    "Refunded payment #$payment_id: " . formatCurrency($refund_amount), 
                    'financial_transactions', $payment_id);
                
                // Clear bulk IDs if this was from bulk action
                if (isset($_SESSION['bulk_refund_ids'])) {
                    unset($_SESSION['bulk_refund_ids']);
                }
                
                header('Location: view.php?id=' . $payment_id);
                exit();
            } else {
                $_SESSION['error'] = 'Failed to process refund.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Refund - Admin Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
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
            max-width: 600px;
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
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
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
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }
        
        .payment-info {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid var(--warning);
        }
        
        .info-item {
            margin-bottom: 0.5rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
        }
        
        .amount-display {
            font-size: 2rem;
            font-weight: bold;
            color: var(--warning);
            margin: 1rem 0;
            text-align: center;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-undo"></i>
                Process Refund
            </h1>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Payments
            </a>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Bulk Refund Notice -->
        <?php if (!empty($bulk_ids) && !$payment): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Bulk Refund Selected</strong>
                <p>You have selected <?php echo count($bulk_ids); ?> payments for refund. 
                   Please process refunds individually or contact support for bulk refund processing.</p>
                <a href="index.php" class="btn btn-secondary" style="margin-top: 0.5rem;">
                    Return to Payments
                </a>
            </div>
        <?php endif; ?>

        <!-- Payment Information -->
        <?php if ($payment): ?>
            <div class="payment-info">
                <h2 style="margin-bottom: 1rem; color: var(--dark);">Refund Details</h2>
                
                <div class="info-item">
                    <div class="info-label">Payment ID</div>
                    <div><?php echo $payment['id']; ?> - <?php echo $payment['gateway_reference']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Student</div>
                    <div><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Original Amount</div>
                    <div><?php echo formatCurrency($payment['amount']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Payment Date</div>
                    <div><?php echo date('M j, Y', strtotime($payment['created_at'])); ?></div>
                </div>
                
                <div class="amount-display">
                    Maximum Refund: <?php echo formatCurrency($payment['amount']); ?>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> Refunds are typically processed back to the original payment method.
                    This action cannot be easily reversed.
                </div>
            </div>
        <?php endif; ?>

        <!-- Refund Form -->
        <?php if ($payment): ?>
            <div class="card">
                <h2 style="margin-bottom: 1.5rem; color: var(--dark);">Refund Information</h2>
                
                <form method="POST" id="refundForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="refund_amount">Refund Amount (NGN) *</label>
                        <input type="number" name="refund_amount" id="refund_amount" 
                               class="form-control" step="0.01" min="0.01" 
                               max="<?php echo $payment['amount']; ?>" 
                               value="<?php echo $payment['amount']; ?>" required>
                        <small style="color: #64748b; font-size: 0.85rem;">
                            Maximum: <?php echo formatCurrency($payment['amount']); ?>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="refund_method">Refund Method *</label>
                        <select name="refund_method" id="refund_method" class="form-control" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="original_method">Original Payment Method</option>
                            <option value="cash">Cash</option>
                            <option value="credit">Account Credit</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="refund_reason">Refund Reason *</label>
                        <textarea name="refund_reason" id="refund_reason" 
                                  class="form-control" rows="4" required
                                  placeholder="Please provide the reason for this refund..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="confirm_refund" required>
                            I confirm that I have verified this refund request and it complies with 
                            our refund policy.
                        </label>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-undo"></i> Process Refund
                        </button>
                        <a href="view.php?id=<?php echo $payment_id; ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Confirm refund submission
        document.getElementById('refundForm')?.addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('refund_amount').value);
            const maxAmount = parseFloat("<?php echo $payment['amount']; ?>");
            
            if (amount > maxAmount) {
                alert('Refund amount cannot exceed original payment amount.');
                e.preventDefault();
                return false;
            }
            
            const reason = document.getElementById('refund_reason').value.trim();
            if (!reason) {
                alert('Please provide a refund reason.');
                e.preventDefault();
                return false;
            }
            
            if (!confirm('Are you sure you want to process this refund? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>