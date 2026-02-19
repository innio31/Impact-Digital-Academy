<?php
// modules/admin/finance/payments/offline_entry.php

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

// Get invoice_id from query string if exists
$invoice_id = $_GET['invoice_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;
$class_id = $_GET['class_id'] ?? 0;

// Initialize variables
$students = [];
$classes = [];
$invoice_info = null;

// Get all active students
$students_sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role 
                 FROM users u 
                 WHERE u.status = 'active' AND u.role IN ('student', 'applicant')
                 ORDER BY u.first_name, u.last_name";
$students_result = $conn->query($students_sql);
$students = $students_result->fetch_all(MYSQLI_ASSOC);

// Get all active classes
$classes_sql = "SELECT cb.id, cb.batch_code, c.title as course_title, 
                       p.name as program_name, p.program_type
                FROM class_batches cb
                JOIN courses c ON c.id = cb.course_id
                JOIN programs p ON p.program_code = c.program_id
                WHERE cb.status IN ('scheduled', 'ongoing')
                ORDER BY cb.start_date DESC";
$classes_result = $conn->query($classes_sql);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// If invoice_id is provided, get invoice details
if ($invoice_id) {
    $invoice_sql = "SELECT i.*, u.first_name, u.last_name, u.email, 
                           cb.batch_code, c.title as course_title
                    FROM invoices i
                    JOIN users u ON u.id = i.student_id
                    JOIN class_batches cb ON cb.id = i.class_id
                    JOIN courses c ON c.id = cb.course_id
                    WHERE i.id = ?";
    $invoice_stmt = $conn->prepare($invoice_sql);
    $invoice_stmt->bind_param("i", $invoice_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    $invoice_info = $invoice_result->fetch_assoc();
    
    if ($invoice_info) {
        $student_id = $invoice_info['student_id'];
        $class_id = $invoice_info['class_id'];
    }
}

// If student and class are provided, get fee information
$fee_info = null;
if ($student_id && $class_id) {
    $fee_info = calculateTotalFee($class_id);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
    } else {
        $student_id = $_POST['student_id'];
        $class_id = $_POST['class_id'];
        $transaction_type = $_POST['transaction_type'];
        $payment_method = $_POST['payment_method'];
        $amount = floatval($_POST['amount']);
        $description = $_POST['description'] ?? '';
        $reference = $_POST['reference'] ?? '';
        
        // Validate inputs
        if (!$student_id || !$class_id || !$payment_method || $amount <= 0) {
            $_SESSION['error'] = 'Please fill in all required fields.';
        } else {
            // Record the payment transaction
            $result = recordPaymentTransaction(
                $student_id, 
                $class_id, 
                $amount, 
                $payment_method, 
                $transaction_type, 
                $description
            );
            
            if ($result['success']) {
                $_SESSION['success'] = 'Payment recorded successfully! Transaction ID: ' . $result['transaction_id'];
                
                // Update invoice status if invoice_id was provided
                if ($invoice_id) {
                    $invoice_update_sql = "UPDATE invoices 
                                           SET status = 'paid', 
                                               paid_amount = amount,
                                               balance = 0
                                           WHERE id = ?";
                    $invoice_stmt = $conn->prepare($invoice_update_sql);
                    $invoice_stmt->bind_param("i", $invoice_id);
                    $invoice_stmt->execute();
                }
                
                // Redirect to receipt
                header('Location: receipt.php?id=' . $result['transaction_id']);
                exit();
            } else {
                $_SESSION['error'] = 'Failed to record payment: ' . ($result['error'] ?? 'Unknown error');
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
    <title>Record Offline Payment - Admin Finance</title>
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
            max-width: 800px;
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
        
        .card h2 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
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
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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
            font-size: 1rem;
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
            color: var(--dark);
        }
        
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        
        .invoice-info {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }
        
        .invoice-info h3 {
            margin-bottom: 1rem;
            color: var(--dark);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .info-item {
            margin-bottom: 0.5rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 0.9rem;
        }
        
        .info-value {
            font-size: 1rem;
            color: var(--dark);
        }
        
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
            margin: 0.5rem 0;
        }
        
        .fee-breakdown {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 6px;
            margin-top: 1rem;
        }
        
        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .fee-item:last-child {
            border-bottom: none;
            font-weight: bold;
            color: var(--primary);
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .container {
                max-width: 100%;
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
                <i class="fas fa-hand-holding-usd"></i>
                Record Offline Payment
            </h1>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Payments
            </a>
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

        <!-- Invoice Information -->
        <?php if ($invoice_info): ?>
            <div class="invoice-info">
                <h3>Invoice Details</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Invoice Number</div>
                        <div class="info-value"><?php echo $invoice_info['invoice_number']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Student</div>
                        <div class="info-value"><?php echo htmlspecialchars($invoice_info['first_name'] . ' ' . $invoice_info['last_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Course</div>
                        <div class="info-value"><?php echo htmlspecialchars($invoice_info['course_title']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Due Date</div>
                        <div class="info-value"><?php echo date('M j, Y', strtotime($invoice_info['due_date'])); ?></div>
                    </div>
                </div>
                <div class="amount-display">
                    Amount Due: <?php echo formatCurrency($invoice_info['amount']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payment Form -->
        <div class="card">
            <h2>Payment Details</h2>
            
            <form method="POST" id="paymentForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="student_id">Student *</label>
                    <select name="student_id" id="student_id" class="form-control" required 
                            onchange="updateClasses()">
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" 
                                    <?php echo $student['id'] == $student_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                (<?php echo $student['email']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="class_id">Class/Program *</label>
                    <select name="class_id" id="class_id" class="form-control" required 
                            onchange="updateFeeInfo()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                    <?php echo $class['id'] == $class_id ? 'selected' : ''; ?>
                                    data-program-type="<?php echo $class['program_type']; ?>">
                                <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['course_title']); ?>
                                (<?php echo $class['batch_code']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Fee Information Display -->
                <div id="feeInfo" style="display: none;">
                    <div class="fee-breakdown">
                        <h4 style="margin-bottom: 1rem;">Fee Breakdown</h4>
                        <div id="feeDetails"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="transaction_type">Payment Type *</label>
                    <select name="transaction_type" id="transaction_type" class="form-control" required>
                        <option value="registration">Registration Fee</option>
                        <option value="tuition">Tuition Fee</option>
                        <option value="other">Other Payment</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="payment_method">Payment Method *</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="">Select Method</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="pos">POS</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="amount">Amount (NGN) *</label>
                    <input type="number" name="amount" id="amount" class="form-control" 
                           step="0.01" min="0.01" required 
                           value="<?php echo $invoice_info ? $invoice_info['amount'] : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="reference">Payment Reference/Receipt Number</label>
                    <input type="text" name="reference" id="reference" class="form-control" 
                           placeholder="e.g., Bank ref, receipt number, etc.">
                </div>
                
                <div class="form-group">
                    <label for="description">Description/Notes</label>
                    <textarea name="description" id="description" class="form-control" 
                              rows="3" placeholder="Additional payment details..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Record Payment
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Update classes based on selected student
        function updateClasses() {
            const studentId = document.getElementById('student_id').value;
            if (!studentId) return;
            
            // In a real implementation, you would fetch student's enrolled classes via AJAX
            // For now, we'll just enable the class selection
            console.log('Student selected:', studentId);
        }
        
        // Update fee information based on selected class
        function updateFeeInfo() {
            const classId = document.getElementById('class_id').value;
            const feeInfoDiv = document.getElementById('feeInfo');
            
            if (!classId) {
                feeInfoDiv.style.display = 'none';
                return;
            }
            
            // Fetch fee information via AJAX
            fetch('ajax/get_fee_info.php?class_id=' + classId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const feeDetails = document.getElementById('feeDetails');
                        let html = '';
                        
                        if (data.program_type === 'online') {
                            html = `
                                <div class="fee-item">
                                    <span>Registration Fee:</span>
                                    <span>${formatCurrency(data.registration_fee)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Block 1 (${data.block1_percentage}%):</span>
                                    <span>${formatCurrency(data.block1_amount)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Block 2 (${data.block2_percentage}%):</span>
                                    <span>${formatCurrency(data.block2_amount)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Total Program Fee:</span>
                                    <span>${formatCurrency(data.total_fee)}</span>
                                </div>
                            `;
                        } else {
                            html = `
                                <div class="fee-item">
                                    <span>Registration Fee:</span>
                                    <span>${formatCurrency(data.registration_fee)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Per Term Fee:</span>
                                    <span>${formatCurrency(data.term_fee)}</span>
                                </div>
                                <div class="fee-item">
                                    <span>Total Term Fee:</span>
                                    <span>${formatCurrency(data.total_fee)}</span>
                                </div>
                            `;
                        }
                        
                        feeDetails.innerHTML = html;
                        feeInfoDiv.style.display = 'block';
                        
                        // Set default amount to total fee
                        document.getElementById('amount').value = data.total_fee;
                    }
                })
                .catch(error => {
                    console.error('Error fetching fee info:', error);
                    feeInfoDiv.style.display = 'none';
                });
        }
        
        // Format currency
        function formatCurrency(amount) {
            return '₦' + parseFloat(amount).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Initialize if class is pre-selected
        <?php if ($class_id): ?>
        document.addEventListener('DOMContentLoaded', function() {
            updateFeeInfo();
        });
        <?php endif; ?>
    </script>
</body>
</html>
<?php $conn->close(); ?>