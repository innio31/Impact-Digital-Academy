<?php
// modules/admin/finance/services/add.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();

// Check if service_revenue table exists
$check_table_sql = "SHOW TABLES LIKE 'service_revenue'";
$check_result = $conn->query($check_table_sql);
if (!$check_result || $check_result->num_rows === 0) {
    die('Service revenue table does not exist. Please run the database migrations.');
}

// Get categories for dropdown
$categories_sql = "SELECT * FROM service_categories WHERE is_active = 1 ORDER BY name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Initialize form data
$form_data = [
    'service_category_id' => '',
    'client_name' => '',
    'client_email' => '',
    'client_phone' => '',
    'description' => '',
    'amount' => '',
    'currency' => 'NGN',
    'payment_method' => 'bank_transfer',
    'payment_date' => date('Y-m-d'),
    'invoice_number' => '',
    'receipt_url' => '',
    'status' => 'completed',
    'notes' => ''
];

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    }
    
    // Get form data
    $form_data = array_map('trim', $_POST);
    
    // Validate required fields
    $required_fields = ['service_category_id', 'client_name', 'description', 'amount', 'payment_date'];
    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    
    // Validate amount
    if (!empty($form_data['amount']) && (!is_numeric($form_data['amount']) || $form_data['amount'] <= 0)) {
        $errors[] = 'Amount must be a positive number.';
    }
    
    // Validate date
    if (!empty($form_data['payment_date']) && !strtotime($form_data['payment_date'])) {
        $errors[] = 'Invalid payment date.';
    }
    
    // Validate email if provided
    if (!empty($form_data['client_email']) && !filter_var($form_data['client_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    
    // If no errors, insert record
    if (empty($errors)) {
        // Generate invoice number if not provided
        if (empty($form_data['invoice_number'])) {
            $prefix = 'SRV-' . date('Ymd');
            $last_invoice_sql = "SELECT MAX(id) as max_id FROM service_revenue WHERE invoice_number LIKE '$prefix%'";
            $last_result = $conn->query($last_invoice_sql);
            $last_id = $last_result->fetch_assoc()['max_id'] ?? 0;
            $form_data['invoice_number'] = $prefix . '-' . str_pad($last_id + 1, 4, '0', STR_PAD_LEFT);
        }
        
        // Prepare SQL
        $sql = "INSERT INTO service_revenue (
                    service_category_id,
                    client_name,
                    client_email,
                    client_phone,
                    description,
                    amount,
                    currency,
                    payment_method,
                    payment_date,
                    invoice_number,
                    receipt_url,
                    status,
                    notes,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'issssdsssssssi',
            $form_data['service_category_id'],
            $form_data['client_name'],
            $form_data['client_email'],
            $form_data['client_phone'],
            $form_data['description'],
            $form_data['amount'],
            $form_data['currency'],
            $form_data['payment_method'],
            $form_data['payment_date'],
            $form_data['invoice_number'],
            $form_data['receipt_url'],
            $form_data['status'],
            $form_data['notes'],
            $_SESSION['user_id']
        );
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            
            // Log activity
            logActivity($_SESSION['user_id'], 'service_revenue_add', "Added new service revenue record ID: {$new_id}");
            
            // Redirect to success page or show success message
            $_SESSION['success_message'] = "Service revenue record added successfully!";
            header("Location: view.php?id={$new_id}");
            exit();
        } else {
            $errors[] = "Failed to save record: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Service Revenue - Finance Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
        }

        .admin-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
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
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .form-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .form-header h2 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
        }

        .form-body {
            padding: 2rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            font-size: 1.2rem;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section h3 i {
            color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .form-group label.required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .form-help {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 0.5rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #fecaca;
        }

        .error-message ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .error-message li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .error-message li:last-child {
            margin-bottom: 0;
        }

        .error-message i {
            font-size: 1.1rem;
        }

        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #a7f3d0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .field-error {
            color: var(--danger);
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon .form-control {
            padding-right: 2.5rem;
        }

        .input-icon {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .currency-symbol {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 500;
        }

        .currency-input .form-control {
            padding-left: 2rem;
        }

        .preview-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
            display: none;
        }

        .preview-box.show {
            display: block;
        }

        .preview-label {
            font-weight: 500;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .preview-value {
            font-size: 1.1rem;
            color: var(--dark);
        }

        @media (max-width: 768px) {
            .admin-container {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-plus-circle"></i>
                Add Service Revenue
            </h1>
            <p>Record non-academic revenue from services, products, or consultancy</p>
        </div>

        <!-- Quick Actions -->
        <div class="header-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Service Revenue
            </a>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-chart-line"></i> Analytics Dashboard
            </a>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li>
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Main Form -->
        <div class="form-container">
            <div class="form-header">
                <h2>
                    <i class="fas fa-briefcase"></i>
                    New Service Revenue Record
                </h2>
            </div>
            
            <form method="POST" class="form-body" id="serviceForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <!-- Client Information -->
                <div class="form-section">
                    <h3><i class="fas fa-user-tie"></i> Client Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="client_name" class="required">Client/Company Name</label>
                            <input type="text" id="client_name" name="client_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['client_name']); ?>" 
                                   required placeholder="e.g., ABC Company or John Doe">
                        </div>
                        
                        <div class="form-group">
                            <label for="client_email">Email Address</label>
                            <div class="input-with-icon">
                                <input type="email" id="client_email" name="client_email" class="form-control" 
                                       value="<?php echo htmlspecialchars($form_data['client_email']); ?>" 
                                       placeholder="client@example.com">
                                <span class="input-icon">
                                    <i class="fas fa-envelope"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="client_phone">Phone Number</label>
                            <div class="input-with-icon">
                                <input type="tel" id="client_phone" name="client_phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($form_data['client_phone']); ?>" 
                                       placeholder="+234 800 000 0000">
                                <span class="input-icon">
                                    <i class="fas fa-phone"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Details -->
                <div class="form-section">
                    <h3><i class="fas fa-cog"></i> Service Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="service_category_id" class="required">Service Category</label>
                            <select id="service_category_id" name="service_category_id" class="form-control" required>
                                <option value="">-- Select a Category --</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" 
                                        <?php echo $form_data['service_category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?> 
                                        (<?php echo ucfirst($category['revenue_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($categories)): ?>
                                <p class="form-help">
                                    <a href="categories.php">No categories found. Create categories first.</a>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="description" class="required">Service Description</label>
                            <textarea id="description" name="description" class="form-control" 
                                      required rows="3" 
                                      placeholder="Describe the service provided, products sold, or consultancy offered..."><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                            <p class="form-help">Be specific about what was delivered</p>
                        </div>
                    </div>
                </div>

                <!-- Financial Information -->
                <div class="form-section">
                    <h3><i class="fas fa-money-bill-wave"></i> Financial Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="amount" class="required">Amount (₦)</label>
                            <div class="input-with-icon currency-input">
                                <span class="currency-symbol">₦</span>
                                <input type="number" id="amount" name="amount" class="form-control" 
                                       value="<?php echo htmlspecialchars($form_data['amount']); ?>" 
                                       required step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div id="amountPreview" class="preview-box">
                                <div class="preview-label">Amount in Words:</div>
                                <div class="preview-value" id="amountInWords"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency" class="form-control">
                                <option value="NGN" <?php echo $form_data['currency'] === 'NGN' ? 'selected' : ''; ?>>Nigerian Naira (₦)</option>
                                <option value="USD" <?php echo $form_data['currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar ($)</option>
                                <option value="EUR" <?php echo $form_data['currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (€)</option>
                                <option value="GBP" <?php echo $form_data['currency'] === 'GBP' ? 'selected' : ''; ?>>British Pound (£)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_method" class="required">Payment Method</label>
                            <select id="payment_method" name="payment_method" class="form-control" required>
                                <option value="bank_transfer" <?php echo $form_data['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cash" <?php echo $form_data['payment_method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="pos" <?php echo $form_data['payment_method'] === 'pos' ? 'selected' : ''; ?>>POS</option>
                                <option value="cheque" <?php echo $form_data['payment_method'] === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="online" <?php echo $form_data['payment_method'] === 'online' ? 'selected' : ''; ?>>Online Payment</option>
                                <option value="mobile_money" <?php echo $form_data['payment_method'] === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_date" class="required">Payment Date</label>
                            <input type="date" id="payment_date" name="payment_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['payment_date']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="completed" <?php echo $form_data['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="pending" <?php echo $form_data['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="cancelled" <?php echo $form_data['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="refunded" <?php echo $form_data['status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="form-section">
                    <h3><i class="fas fa-file-alt"></i> Additional Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="invoice_number">Invoice/Reference Number</label>
                            <div class="input-with-icon">
                                <input type="text" id="invoice_number" name="invoice_number" class="form-control" 
                                       value="<?php echo htmlspecialchars($form_data['invoice_number']); ?>" 
                                       placeholder="Leave blank to auto-generate">
                                <span class="input-icon">
                                    <i class="fas fa-hashtag"></i>
                                </span>
                            </div>
                            <p class="form-help">Auto-generates if left blank: SRV-YYYYMMDD-0001</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="receipt_url">Receipt/Proof URL</label>
                            <div class="input-with-icon">
                                <input type="url" id="receipt_url" name="receipt_url" class="form-control" 
                                       value="<?php echo htmlspecialchars($form_data['receipt_url']); ?>" 
                                       placeholder="https://example.com/receipt.jpg">
                                <span class="input-icon">
                                    <i class="fas fa-link"></i>
                                </span>
                            </div>
                            <p class="form-help">Link to receipt, screenshot, or proof of payment</p>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="notes">Additional Notes</label>
                            <textarea id="notes" name="notes" class="form-control" 
                                      rows="3" placeholder="Any additional information, terms, or special instructions..."><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Service Revenue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date picker
        flatpickr("#payment_date", {
            dateFormat: "Y-m-d",
            defaultDate: "<?php echo date('Y-m-d'); ?>",
            maxDate: "today"
        });

        // Amount in words converter
        function numberToWords(num) {
            const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
            const scales = ['', 'Thousand', 'Million', 'Billion', 'Trillion'];
            
            if (num == 0) return 'Zero';
            if (num < 0) return 'Negative ' + numberToWords(Math.abs(num));
            
            let words = '';
            
            for (let i = 0; num > 0; i++) {
                if (num % 1000 != 0) {
                    let chunk = Math.floor(num % 1000);
                    if (chunk < 20) {
                        words = ones[chunk] + (scales[i] ? ' ' + scales[i] : '') + ' ' + words;
                    } else if (chunk < 100) {
                        words = tens[Math.floor(chunk / 10)] + (chunk % 10 ? '-' + ones[chunk % 10] : '') + (scales[i] ? ' ' + scales[i] : '') + ' ' + words;
                    } else {
                        words = ones[Math.floor(chunk / 100)] + ' Hundred' + (chunk % 100 ? ' and ' : '') + numberToWords(chunk % 100) + (scales[i] ? ' ' + scales[i] : '') + ' ' + words;
                    }
                }
                num = Math.floor(num / 1000);
            }
            
            return words.trim() + ' Naira Only';
        }

        // Update amount preview
        document.getElementById('amount').addEventListener('input', function(e) {
            const amount = parseFloat(e.target.value);
            const previewBox = document.getElementById('amountPreview');
            
            if (!isNaN(amount) && amount > 0) {
                document.getElementById('amountInWords').textContent = numberToWords(Math.floor(amount)) + 
                    (amount % 1 !== 0 ? ' and ' + Math.round((amount % 1) * 100) + ' Kobo' : '');
                previewBox.classList.add('show');
            } else {
                previewBox.classList.remove('show');
            }
        });

        // Form validation
        document.getElementById('serviceForm').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('amount').value);
            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than 0.');
                document.getElementById('amount').focus();
                return false;
            }
            
            const category = document.getElementById('service_category_id').value;
            if (!category) {
                e.preventDefault();
                alert('Please select a service category.');
                document.getElementById('service_category_id').focus();
                return false;
            }
            
            const date = document.getElementById('payment_date').value;
            if (!date) {
                e.preventDefault();
                alert('Please select a payment date.');
                document.getElementById('payment_date').focus();
                return false;
            }
            
            return true;
        });

        // Auto-focus first field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('client_name').focus();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('serviceForm').submit();
            }
            
            if (e.key === 'Escape') {
                window.location.href = 'index.php';
            }
        });

        // Auto-generate invoice number if empty
        document.getElementById('invoice_number').addEventListener('blur', function(e) {
            if (!e.target.value.trim()) {
                const now = new Date();
                const dateStr = now.getFullYear() + 
                              String(now.getMonth() + 1).padStart(2, '0') + 
                              String(now.getDate()).padStart(2, '0');
                e.target.value = 'SRV-' + dateStr + '-XXXX';
            }
        });
    </script>
</body>
</html>