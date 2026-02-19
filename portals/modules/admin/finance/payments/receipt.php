<?php
// modules/admin/finance/payments/receipt.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/finance_functions.php';

// Check if user is admin or the student themselves
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Get payment ID
$payment_id = $_GET['id'] ?? 0;
if (!$payment_id) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

// Get payment details
$sql = "SELECT ft.*, u.first_name, u.last_name, u.email, u.phone,
               cb.batch_code, c.title as course_title,
               p.name as program_name, p.program_type,
               sfs.total_fee, sfs.paid_amount, sfs.balance
        FROM financial_transactions ft
        JOIN users u ON u.id = ft.student_id
        JOIN class_batches cb ON cb.id = ft.class_id
        JOIN courses c ON c.id = cb.course_id
        JOIN programs p ON p.program_code = c.program_id
        LEFT JOIN student_financial_status sfs ON sfs.student_id = ft.student_id 
            AND sfs.class_id = ft.class_id
        WHERE ft.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

if (!$payment) {
    die('Payment not found.');
}

// Check permission - admin or the student themselves
$is_admin = ($_SESSION['user_role'] === 'admin');
$is_student_owner = ($_SESSION['user_id'] == $payment['student_id']);

if (!$is_admin && !$is_student_owner) {
    die('Access denied. You do not have permission to view this receipt.');
}

// Log activity
logActivity($_SESSION['user_id'], 'view_receipt', "Viewed receipt for payment #$payment_id", 'financial_transactions', $payment_id);

// Generate receipt if not already generated
if (!$payment['receipt_url']) {
    $receipt_url = generateReceipt($payment_id);
    $payment['receipt_url'] = $receipt_url;
}

// If viewing as student, redirect to student finance receipt
if ($is_student_owner && !$is_admin) {
    header('Location: ' . BASE_URL . 'modules/student/finance/payments/receipt.php?id=' . $payment_id);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo getSetting('site_name', 'Impact Digital Academy'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .receipt-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .receipt-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .receipt-header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .paid-stamp {
            position: absolute;
            top: 2rem;
            right: 2rem;
            transform: rotate(15deg);
            color: #10b981;
            font-size: 2rem;
            font-weight: bold;
            opacity: 0.2;
            border: 4px solid #10b981;
            padding: 1rem 2rem;
            border-radius: 50%;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .receipt-body {
            padding: 2rem;
        }
        
        .section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            color: #2563eb;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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
            color: #1e293b;
        }
        
        .amount-display {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            padding: 2rem;
            border-radius: 8px;
            text-align: center;
            margin: 2rem 0;
            border: 2px dashed #3b82f6;
        }
        
        .amount-label {
            font-size: 1.1rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        
        .amount-value {
            font-size: 3rem;
            font-weight: bold;
            color: #2563eb;
        }
        
        .payment-details {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            font-weight: bold;
            color: #1e40af;
            font-size: 1.1rem;
        }
        
        .receipt-footer {
            background: #f1f5f9;
            padding: 2rem;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-note {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
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
            text-decoration: none;
            font-size: 1rem;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .receipt-container {
                box-shadow: none;
                border: none;
            }
            
            .action-buttons {
                display: none;
            }
            
            .paid-stamp {
                opacity: 0.1;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .amount-value {
                font-size: 2.5rem;
            }
            
            .action-buttons {
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
        <div class="receipt-container">
            <!-- Paid Stamp -->
            <div class="paid-stamp">PAID</div>
            
            <!-- Header -->
            <div class="receipt-header">
                <h1><?php echo getSetting('site_name', 'Impact Digital Academy'); ?></h1>
                <p>OFFICIAL PAYMENT RECEIPT</p>
                <p style="margin-top: 1rem; font-size: 1rem;">
                    Transaction #: <?php echo $payment['gateway_reference']; ?>
                </p>
            </div>
            
            <!-- Body -->
            <div class="receipt-body">
                <!-- Transaction Details -->
                <div class="section">
                    <h2 class="section-title">Transaction Details</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Receipt Number</div>
                            <div class="info-value"><?php echo $payment['gateway_reference']; ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Date & Time</div>
                            <div class="info-value">
                                <?php echo date('F j, Y', strtotime($payment['created_at'])); ?><br>
                                <span style="color: #64748b;"><?php echo date('g:i A', strtotime($payment['created_at'])); ?></span>
                            </div>
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
                                <span style="display: inline-block; padding: 0.25rem 0.75rem; 
                                      background: #d1fae5; color: #065f46; border-radius: 20px; 
                                      font-weight: 600; font-size: 0.85rem;">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($payment['is_verified']): ?>
                            <div class="info-item">
                                <div class="info-label">Verified</div>
                                <div class="info-value">
                                    <span style="color: #10b981;">
                                        <i class="fas fa-check-circle"></i> Yes
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Amount -->
                <div class="amount-display">
                    <div class="amount-label">Amount Paid</div>
                    <div class="amount-value"><?php echo formatCurrency($payment['amount']); ?></div>
                    <div style="margin-top: 0.5rem; color: #64748b; font-size: 1rem;">
                        in Nigerian Naira (NGN)
                    </div>
                </div>
                
                <!-- Student Information -->
                <div class="section">
                    <h2 class="section-title">Student Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Student Name</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?php echo $payment['email']; ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo $payment['phone'] ?: 'N/A'; ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Program Information -->
                <div class="section">
                    <h2 class="section-title">Program Information</h2>
                    <div class="info-grid">
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
                    </div>
                </div>
                
                <!-- Payment Breakdown -->
                <div class="payment-details">
                    <h3 style="margin-bottom: 1.5rem; color: #1e293b;">Payment Details</h3>
                    
                    <?php if ($payment['description']): ?>
                        <div class="detail-row">
                            <span>Description:</span>
                            <span><?php echo htmlspecialchars($payment['description']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-row">
                        <span>Payment Method:</span>
                        <span><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                    </div>
                    
                    <?php if ($payment['payment_gateway']): ?>
                        <div class="detail-row">
                            <span>Payment Gateway:</span>
                            <span><?php echo ucfirst($payment['payment_gateway']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-row">
                        <span>Amount Paid:</span>
                        <span><?php echo formatCurrency($payment['amount']); ?></span>
                    </div>
                </div>
                
                <!-- Financial Summary -->
                <?php if ($payment['total_fee']): ?>
                    <div class="payment-details" style="margin-top: 1.5rem;">
                        <h3 style="margin-bottom: 1.5rem; color: #1e293b;">Financial Summary</h3>
                        
                        <div class="detail-row">
                            <span>Total Program Fee:</span>
                            <span><?php echo formatCurrency($payment['total_fee']); ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span>Previously Paid:</span>
                            <span><?php echo formatCurrency($payment['paid_amount'] - $payment['amount']); ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span>This Payment:</span>
                            <span><?php echo formatCurrency($payment['amount']); ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span>Total Paid to Date:</span>
                            <span><?php echo formatCurrency($payment['paid_amount']); ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span>Remaining Balance:</span>
                            <span style="color: <?php echo $payment['balance'] > 0 ? '#ef4444' : '#10b981'; ?>;">
                                <?php echo formatCurrency($payment['balance']); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Notes -->
                <div class="section" style="margin-top: 2rem;">
                    <h2 class="section-title">Important Notes</h2>
                    <div style="color: #64748b; line-height: 1.6; font-size: 0.9rem;">
                        <p>1. This is an official receipt from <?php echo getSetting('site_name', 'Impact Digital Academy'); ?>.</p>
                        <p>2. Please keep this receipt for your records and any future references.</p>
                        <p>3. For any inquiries regarding this payment, please contact our finance department.</p>
                        <p>4. Receipt generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="receipt-footer">
                <div class="footer-note">
                    Thank you for your payment. We appreciate your trust in <?php echo getSetting('site_name', 'Impact Digital Academy'); ?>.
                </div>
                
                <div style="color: #64748b; font-size: 0.85rem;">
                    <p>Contact: <?php echo getSetting('site_email', 'info@impactacademy.edu'); ?></p>
                    <p>Phone: +234 (0) 123 456 7890</p>
                    <p>Address: 123 Education Street, Learning City, Nigeria</p>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            
            <a href="view.php?id=<?php echo $payment_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Payment Details
            </a>
            
            <a href="index.php" class="btn btn-success">
                <i class="fas fa-list"></i> View All Payments
            </a>
            
            <?php if ($payment['receipt_url']): ?>
                <a href="<?php echo BASE_URL . $payment['receipt_url']; ?>" 
                   download="receipt_<?php echo $payment['gateway_reference']; ?>.html" 
                   class="btn btn-primary">
                    <i class="fas fa-download"></i> Download Receipt
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-print option for admin
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('print')) {
            window.print();
        }
        
        // Add print styles
        window.addEventListener('beforeprint', () => {
            document.body.style.padding = '0';
        });
        
        window.addEventListener('afterprint', () => {
            document.body.style.padding = '2rem';
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>