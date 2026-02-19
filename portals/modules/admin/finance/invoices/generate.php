<?php
// modules/admin/finance/invoices/generate.php

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

// Check if duplicating an existing invoice
$duplicate_id = isset($_GET['duplicate']) && isset($_GET['id']) ? (int)$_GET['id'] : 0;
$duplicate_invoice = null;

if ($duplicate_id) {
    $dup_sql = "SELECT i.*, u.first_name, u.last_name, u.email, 
                       cb.batch_code, c.title as course_title,
                       p.name as program_name
                FROM invoices i
                JOIN users u ON u.id = i.student_id
                JOIN class_batches cb ON cb.id = i.class_id
                JOIN courses c ON c.id = cb.course_id
                JOIN programs p ON p.program_code = c.program_id
                WHERE i.id = ?";
    $dup_stmt = $conn->prepare($dup_sql);
    $dup_stmt->bind_param("i", $duplicate_id);
    $dup_stmt->execute();
    $duplicate_invoice = $dup_stmt->get_result()->fetch_assoc();
}

// Get students for dropdown
$students_sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status 
                 FROM users u 
                 WHERE u.role = 'student' AND u.status = 'active' 
                 ORDER BY u.first_name, u.last_name";
$students_result = $conn->query($students_sql);
$students = $students_result->fetch_all(MYSQLI_ASSOC);

// Get existing classes
// Get classes for dropdown - show both existing classes and available programs
$classes_sql = "SELECT 
                    cb.id, 
                    cb.batch_code, 
                    c.title as course_title, 
                    p.name as program_name, 
                    p.program_type,
                    'existing_class' as source
                FROM class_batches cb
                JOIN courses c ON c.id = cb.course_id
                JOIN programs p ON p.program_code = c.program_id
                WHERE cb.status IN ('scheduled', 'ongoing')
                
                UNION ALL
                
                SELECT 
                    CONCAT('prog_', p.id) as id,  -- Prefix to distinguish from real class IDs
                    'No class yet' as batch_code,
                    p.name as course_title,
                    p.name as program_name,
                    p.program_type,
                    'available_program' as source
                FROM programs p
                WHERE p.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM class_batches cb
                        JOIN courses c ON c.id = cb.course_id
                        WHERE c.program_id = p.program_code
                        AND cb.status IN ('scheduled', 'ongoing')
                    )
                
                ORDER BY program_name, course_title";

$classes_result = $conn->query($classes_sql);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// Get payment plans for fee calculation
$payment_plans_sql = "SELECT * FROM payment_plans WHERE is_active = 1";
$payment_plans_result = $conn->query($payment_plans_sql);
$payment_plans = $payment_plans_result->fetch_all(MYSQLI_ASSOC);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'];
    $class_id = $_POST['class_id'];
    $invoice_type = $_POST['invoice_type'];
    $amount = $_POST['amount'];
    $due_date = $_POST['due_date'];
    $description = $_POST['description'];
    $notes = $_POST['notes'];
    $send_email = isset($_POST['send_email']);

    // Validate inputs
    $errors = [];

    if (empty($student_id)) $errors[] = "Student is required";
    if (empty($class_id)) $errors[] = "Class is required";
    if (empty($invoice_type)) $errors[] = "Invoice type is required";
    if (!is_numeric($amount) || $amount <= 0) $errors[] = "Valid amount is required";
    if (empty($due_date)) $errors[] = "Due date is required";

    // Generate invoice number
    $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());

    if (empty($errors)) {
        // Convert class_id to integer if it's prefixed with 'prog_'
        $db_class_id = $class_id;
        if (is_string($class_id) && strpos($class_id, 'prog_') === 0) {
            $db_class_id = (int)str_replace('prog_', '', $class_id);
        }

        // Check for existing invoice of same type for this student/class
        $check_sql = "SELECT id FROM invoices 
                  WHERE student_id = ? AND class_id = ? AND invoice_type = ? AND status != 'cancelled'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iis", $student_id, $db_class_id, $invoice_type);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();

        if ($existing) {
            $errors[] = "An invoice of this type already exists for this student and class";
        } else {
            // Insert invoice - with description column
            $insert_sql = "INSERT INTO invoices 
                      (invoice_number, student_id, class_id, invoice_type, amount, 
                       due_date, description, notes, status, balance, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $balance = $amount;

            $insert_stmt->bind_param(
                "siisddssd",  // 9 parameters
                $invoice_number,    // s
                $student_id,        // i
                $db_class_id,       // i (converted to int)
                $invoice_type,      // s
                $amount,            // d
                $due_date,          // s (date as string)
                $description,       // s
                $notes,             // s
                $balance            // d
            );

            if ($insert_stmt->execute()) {
                $invoice_id = $conn->insert_id;

                // Log activity
                logActivity(
                    $_SESSION['user_id'],
                    'invoice_generated',
                    "Generated invoice #$invoice_number for student $student_id, amount: $amount"
                );

                // Send email notification if requested
                if ($send_email && $invoice_id) {
                    sendInvoiceNotification($student_id, $invoice_id, $invoice_number, $amount);
                }

                $_SESSION['flash_message'] = "Invoice generated successfully! Invoice #: $invoice_number";

                // Redirect to view page
                header("Location: view.php?id=$invoice_id");
                exit();
            } else {
                $errors[] = "Failed to generate invoice: " . $conn->error;
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
    <title>Generate Invoice - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
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
            max-width: 1000px;
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
            background: var(--primary-dark);
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

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
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

        .student-info-card,
        .class-info-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .student-info-card h4,
        .class-info-card h4 {
            margin: 0 0 0.5rem 0;
            color: #1e293b;
        }

        .student-details,
        .class-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .detail-item {
            color: #64748b;
        }

        .detail-value {
            font-weight: 500;
            color: #1e293b;
        }

        .invoice-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .invoice-type-card {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .invoice-type-card:hover {
            border-color: var(--primary);
            background: #f0f9ff;
        }

        .invoice-type-card.selected {
            border-color: var(--primary);
            background: #dbeafe;
        }

        .invoice-type-card h4 {
            margin: 0 0 0.5rem 0;
            color: #1e293b;
        }

        .invoice-type-card p {
            margin: 0;
            color: #64748b;
            font-size: 0.85rem;
        }

        .amount-preview {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .amount-preview h4 {
            margin: 0 0 0.5rem 0;
            color: #065f46;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-file-invoice"></i>
                <?php echo $duplicate_invoice ? 'Duplicate Invoice' : 'Generate New Invoice'; ?>
            </h1>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Invoices
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

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin: 0.5rem 0 0 1rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Main Form -->
        <form method="POST" id="invoiceForm">
            <!-- Student Selection -->
            <div class="form-group">
                <label for="student_id">Select Student *</label>
                <select name="student_id" id="student_id" class="form-control" required
                    onchange="loadStudentInfo(this.value)">
                    <option value="">-- Select Student --</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>"
                            <?php if ($duplicate_invoice && $duplicate_invoice['student_id'] == $student['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['email'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="studentInfo" style="display: none;">
                <!-- Student info will be loaded here via AJAX -->
            </div>

            <!-- Class Selection -->
            <div class="form-group">
                <label for="class_id">Select Class/Program *</label>
                <select name="class_id" id="class_id" class="form-control" required
                    onchange="loadClassInfo(this.value)">
                    <option value="">-- Select Class --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"
                            <?php if ($duplicate_invoice && $duplicate_invoice['class_id'] == $class['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['course_title'] . ' (' . $class['batch_code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="classInfo" style="display: none;">
                <!-- Class info will be loaded here via AJAX -->
            </div>

            <!-- Invoice Type -->
            <div class="form-group">
                <label>Invoice Type *</label>
                <div class="invoice-type-grid">
                    <div class="invoice-type-card" onclick="selectInvoiceType('registration')">
                        <h4>Registration Fee</h4>
                        <p>One-time registration fee for new enrollment</p>
                    </div>
                    <div class="invoice-type-card" onclick="selectInvoiceType('tuition_block1')">
                        <h4>Tuition - Block 1</h4>
                        <p>First installment (70% of total fee)</p>
                    </div>
                    <div class="invoice-type-card" onclick="selectInvoiceType('tuition_block2')">
                        <h4>Tuition - Block 2</h4>
                        <p>Second installment (30% of total fee)</p>
                    </div>
                    <div class="invoice-type-card" onclick="selectInvoiceType('late_fee')">
                        <h4>Late Fee</h4>
                        <p>Penalty for late payment</p>
                    </div>
                    <div class="invoice-type-card" onclick="selectInvoiceType('other')">
                        <h4>Other</h4>
                        <p>Miscellaneous charges</p>
                    </div>
                </div>
                <input type="hidden" name="invoice_type" id="invoice_type" required>
            </div>

            <!-- Amount -->
            <div class="form-group">
                <label for="amount">Amount (₦) *</label>
                <input type="number" name="amount" id="amount" class="form-control"
                    step="0.01" min="0" required
                    value="<?php echo $duplicate_invoice ? $duplicate_invoice['amount'] : ''; ?>">
                <small style="color: #64748b;">Enter amount in Nigerian Naira</small>
            </div>

            <!-- Due Date -->
            <div class="form-group">
                <label for="due_date">Due Date *</label>
                <input type="date" name="due_date" id="due_date" class="form-control" required
                    value="<?php echo $duplicate_invoice ? $duplicate_invoice['due_date'] : date('Y-m-d', strtotime('+30 days')); ?>">
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description">Description</label>
                <textarea name="description" id="description" class="form-control" rows="3"
                    placeholder="Brief description of this invoice"><?php echo $duplicate_invoice ? htmlspecialchars($duplicate_invoice['description']) : ''; ?></textarea>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label for="notes">Internal Notes (Optional)</label>
                <textarea name="notes" id="notes" class="form-control" rows="2"
                    placeholder="Internal notes for admin only"><?php echo $duplicate_invoice ? htmlspecialchars($duplicate_invoice['notes']) : ''; ?></textarea>
            </div>

            <!-- Send Email Notification -->
            <div class="checkbox-group">
                <input type="checkbox" name="send_email" id="send_email" value="1" checked>
                <label for="send_email">Send email notification to student</label>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-file-invoice"></i> Generate Invoice
                </button>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Select invoice type
        function selectInvoiceType(type) {
            document.getElementById('invoice_type').value = type;

            // Update UI
            document.querySelectorAll('.invoice-type-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');

            // Auto-calculate amount if student and class are selected
            calculateInvoiceAmount();
        }

        // Load student info via AJAX
        function loadStudentInfo(studentId) {
            if (!studentId) {
                document.getElementById('studentInfo').style.display = 'none';
                return;
            }

            $.ajax({
                url: '<?php echo BASE_URL; ?>modules/admin/finance/ajax/get_student_info.php',
                method: 'POST',
                data: {
                    student_id: studentId
                },
                success: function(response) {
                    if (response.success) {
                        const html = `
                            <div class="student-info-card">
                                <h4>Student Information</h4>
                                <div class="student-details">
                                    <div class="detail-item">
                                        <strong>Email:</strong> ${response.data.email}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Phone:</strong> ${response.data.phone || 'N/A'}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Status:</strong> ${response.data.status}
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('studentInfo').innerHTML = html;
                        document.getElementById('studentInfo').style.display = 'block';
                    }
                }
            });

            // Auto-calculate amount if class and type are selected
            calculateInvoiceAmount();
        }

        // Load class info via AJAX
        function loadClassInfo(classId) {
            if (!classId) {
                document.getElementById('classInfo').style.display = 'none';
                return;
            }

            $.ajax({
                url: '<?php echo BASE_URL; ?>modules/admin/finance/ajax/get_class_info.php',
                method: 'POST',
                data: {
                    class_id: classId
                },
                success: function(response) {
                    if (response.success) {
                        const html = `
                            <div class="class-info-card">
                                <h4>Class Information</h4>
                                <div class="class-details">
                                    <div class="detail-item">
                                        <strong>Program:</strong> ${response.data.program_name}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Course:</strong> ${response.data.course_title}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Batch:</strong> ${response.data.batch_code}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Type:</strong> ${response.data.program_type}
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('classInfo').innerHTML = html;
                        document.getElementById('classInfo').style.display = 'block';
                    }
                }
            });

            // Auto-calculate amount if student and type are selected
            calculateInvoiceAmount();
        }

        // Calculate invoice amount based on type, student, and class
        function calculateInvoiceAmount() {
            const studentId = document.getElementById('student_id').value;
            const classId = document.getElementById('class_id').value;
            const invoiceType = document.getElementById('invoice_type').value;

            if (studentId && classId && invoiceType) {
                $.ajax({
                    url: '<?php echo BASE_URL; ?>modules/admin/finance/ajax/calculate_invoice_amount.php',
                    method: 'POST',
                    data: {
                        student_id: studentId,
                        class_id: classId,
                        invoice_type: invoiceType
                    },
                    success: function(response) {
                        if (response.success && response.data.amount) {
                            document.getElementById('amount').value = response.data.amount;

                            // Show amount preview
                            if (!document.getElementById('amountPreview')) {
                                const preview = document.createElement('div');
                                preview.id = 'amountPreview';
                                preview.className = 'amount-preview';
                                preview.innerHTML = `
                                    <h4>Suggested Amount: ₦${response.data.amount.toLocaleString()}</h4>
                                    <small>Based on program fee structure. You can modify this amount if needed.</small>
                                `;
                                document.getElementById('amount').parentNode.insertBefore(preview, document.getElementById('amount').nextSibling);
                            } else {
                                document.getElementById('amountPreview').innerHTML = `
                                    <h4>Suggested Amount: ₦${response.data.amount.toLocaleString()}</h4>
                                    <small>Based on program fee structure. You can modify this amount if needed.</small>
                                `;
                            }
                        }
                    }
                });
            }
        }

        // Form validation
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            const invoiceType = document.getElementById('invoice_type').value;
            if (!invoiceType) {
                e.preventDefault();
                alert('Please select an invoice type');
                return false;
            }

            const amount = document.getElementById('amount').value;
            if (amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount');
                return false;
            }
        });

        // Auto-select invoice type if duplicating
        <?php if ($duplicate_invoice): ?>
            document.addEventListener('DOMContentLoaded', function() {
                selectInvoiceType('<?php echo $duplicate_invoice['invoice_type']; ?>');
            });
        <?php endif; ?>
    </script>
</body>

</html>
<?php $conn->close(); ?>