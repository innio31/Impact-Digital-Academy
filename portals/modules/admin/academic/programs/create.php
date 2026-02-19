<?php
// modules/admin/academic/programs/create.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and functions
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is logged in and is admin
requireRole('admin');

// Get database connection
$conn = getDBConnection();

// Initialize variables
$errors = [];
$success = false;

// Default values for new program
$program = [
    'program_code' => '',
    'name' => '',
    'description' => '',
    'duration_weeks' => 12,
    'program_type' => 'online',
    'base_fee' => 0.00,
    'registration_fee' => 0.00,
    'online_fee' => 0.00,
    'onsite_fee' => 0.00,
    'payment_plan_type' => 'full',
    'installment_count' => 2,
    'late_fee_percentage' => 5.00,
    'fee_description' => '',
    'status' => 'active',
    'duration_mode' => '',
    'schedule_type' => '',
    'school_id' => 0
];

// Get school_id from URL if provided
$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$program['school_id'] = $school_id;

// Get schools for dropdown if no specific school is selected
$schools = [];
if (!$school_id) {
    $schools_sql = "SELECT id, name, short_name FROM schools WHERE partnership_status = 'active' ORDER BY name";
    $schools_result = $conn->query($schools_sql);
    if ($schools_result) {
        $schools = $schools_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Generate a suggested program code
function generateProgramCode($name, $conn)
{
    if (empty($name)) {
        return '';
    }

    $words = explode(' ', trim($name));
    $code = '';

    // Take first letters of first 2-3 words
    if (count($words) >= 2) {
        $code = strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        if (count($words) >= 3) {
            $code .= strtoupper(substr($words[2], 0, 1));
        }
    } else {
        // For single word, use first 3 letters
        $code = strtoupper(substr($words[0], 0, 3));
    }

    // Add 3-digit sequential number
    $counter = 1;
    while (true) {
        $suggestedCode = $code . str_pad($counter, 3, '0', STR_PAD_LEFT);

        // Check if code already exists
        $check_sql = "SELECT id FROM programs WHERE program_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $suggestedCode);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows === 0) {
            return $suggestedCode;
        }

        $counter++;

        // Safety break
        if ($counter > 999) {
            // If all numbers are taken, add timestamp
            $timestamp = date('His');
            return $code . substr($timestamp, -3);
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $program_code = trim($_POST['program_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $program_type = $_POST['program_type'] ?? 'online';
    $base_fee = (float)($_POST['base_fee'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $school_id = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

    // If program code is empty, generate one
    if (empty($program_code) && !empty($name)) {
        $program_code = generateProgramCode($name, $conn);
    }

    // Validate program code
    if (empty($program_code)) {
        $errors['program_code'] = 'Program code is required';
    } else {
        // Check if program code already exists
        $check_sql = "SELECT id FROM programs WHERE program_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $program_code);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $errors['program_code'] = 'Program code already exists';
        }
    }

    // Validate name
    if (empty($name)) {
        $errors['name'] = 'Program name is required';
    }

    // Validate fees
    if ($base_fee < 0) {
        $errors['base_fee'] = 'Base fee cannot be negative';
    }

    // If no errors, create the program
    if (empty($errors)) {
        // Gather all form data
        $description = trim($_POST['description'] ?? '');
        $duration_weeks = (int)($_POST['duration_weeks'] ?? 12);
        $registration_fee = (float)($_POST['registration_fee'] ?? 0);
        $online_fee = (float)($_POST['online_fee'] ?? $base_fee);
        $onsite_fee = (float)($_POST['onsite_fee'] ?? $base_fee * 1.2); // 20% higher for onsite
        $payment_plan_type = $_POST['payment_plan_type'] ?? 'full';
        $installment_count = (int)($_POST['installment_count'] ?? 1);
        $late_fee_percentage = (float)($_POST['late_fee_percentage'] ?? 5.00);
        $fee_description = trim($_POST['fee_description'] ?? '');
        $duration_mode = trim($_POST['duration_mode'] ?? '');
        $schedule_type = trim($_POST['schedule_type'] ?? '');
        $currency = 'NGN';

        // Calculate total fee based on program type
        if ($program_type === 'online') {
            $total_fee = $online_fee;
        } elseif ($program_type === 'onsite') {
            $total_fee = $onsite_fee;
        } else {
            // For school type, use base fee
            $total_fee = $base_fee;
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert into programs table
            $sql = "INSERT INTO programs (
                program_code, name, description, duration_weeks,
                fee, base_fee, registration_fee, online_fee, onsite_fee,
                program_type, payment_plan_type, installment_count,
                late_fee_percentage, currency, fee_description, status,
                duration_mode, schedule_type, school_id, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($sql);
            $user_id = $_SESSION['user_id'] ?? 1;
            
            // Count the parameters: we have 20 parameters before created_by
            // Let's debug what we're binding
            $bind_params = [
                $program_code,          // 1. s
                $name,                  // 2. s
                $description,           // 3. s
                $duration_weeks,        // 4. i
                $total_fee,             // 5. d
                $base_fee,              // 6. d
                $registration_fee,      // 7. d
                $online_fee,            // 8. d
                $onsite_fee,            // 9. d
                $program_type,          // 10. s
                $payment_plan_type,     // 11. s
                $installment_count,     // 12. i
                $late_fee_percentage,   // 13. d
                $currency,              // 14. s
                $fee_description,       // 15. s
                $status,                // 16. s
                $duration_mode,         // 17. s
                $schedule_type,         // 18. s
                $school_id,             // 19. i
                $user_id                // 20. i
            ];
            
            // Type string should match: 20 parameters
            // s = string, i = integer, d = double/float
            $type_string = "sssididdddssidssssii";
            
            $stmt->bind_param(
                $type_string,
                $program_code,
                $name,
                $description,
                $duration_weeks,
                $total_fee,
                $base_fee,
                $registration_fee,
                $online_fee,
                $onsite_fee,
                $program_type,
                $payment_plan_type,
                $installment_count,
                $late_fee_percentage,
                $currency,
                $fee_description,
                $status,
                $duration_mode,
                $schedule_type,
                $school_id,
                $user_id
            );

            if ($stmt->execute()) {
                $program_id = $conn->insert_id; // Get the auto-incremented ID

                // Create default payment plan (only for online and onsite programs)
                if (in_array($program_type, ['online', 'onsite'])) {
                    $plan_data = [
                        'registration_fee' => $registration_fee,
                        'block1_percentage' => 70.00,
                        'block2_percentage' => 30.00,
                        'block1_due_days' => 30,
                        'block2_due_days' => 60,
                        'late_fee_percentage' => $late_fee_percentage,
                        'suspension_days' => 21,
                        'refund_policy_days' => 14
                    ];

                    $plan_sql = "INSERT INTO payment_plans (
                        program_id, program_type, plan_name, registration_fee,
                        block1_percentage, block2_percentage, block1_due_days, block2_due_days,
                        late_fee_percentage, suspension_days, refund_policy_days, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

                    $plan_stmt = $conn->prepare($plan_sql);
                    $plan_name = "Default Payment Plan";
                    $plan_stmt->bind_param(
                        "issdddddddd",
                        $program_id,
                        $program_type,
                        $plan_name,
                        $plan_data['registration_fee'],
                        $plan_data['block1_percentage'],
                        $plan_data['block2_percentage'],
                        $plan_data['block1_due_days'],
                        $plan_data['block2_due_days'],
                        $plan_data['late_fee_percentage'],
                        $plan_data['suspension_days'],
                        $plan_data['refund_policy_days']
                    );
                    $plan_stmt->execute();
                }

                // Log activity
                logActivity('program_create', "Created new program: $program_code - $name (ID: $program_id)", 'programs', $program_id);

                $conn->commit();

                $_SESSION['success'] = "Program created successfully! Program ID: $program_id";
                header("Location: view.php?id=" . $program_id);
                exit();
            } else {
                throw new Exception("Failed to create program: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errors['database'] = "Error creating program: " . $e->getMessage();
        }
    }

    // If there are errors, repopulate form with submitted values
    $program = array_merge($program, $_POST);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Program - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
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
            padding: 2rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.75rem;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }

        .page-header h1 {
            font-size: 1.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 2.5rem;
            margin-bottom: 2rem;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 2.5rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        /* Form Layout */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-label.required:after {
            content: " *";
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .form-control.error {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .form-text {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--gray);
            line-height: 1.4;
        }

        .form-text.error {
            color: var(--danger);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* Auto-suggest Box */
        .auto-suggest-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            display: none;
        }

        .auto-suggest-box.active {
            display: block;
        }

        .suggested-code {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }

        .suggested-code:hover {
            background: #e9ecef;
            border-color: var(--primary);
        }

        .suggested-code .code {
            font-weight: 600;
            color: var(--primary);
        }

        .suggested-code .action {
            color: var(--gray);
            font-size: 0.85rem;
        }

        .auto-generate-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-left: 0.5rem;
        }

        .auto-generate-btn:hover {
            background: #0ea5e9;
        }

        /* Fee Calculator */
        .fee-calculator {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--light-gray);
        }

        .fee-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .fee-item {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .fee-item.total {
            border-left-color: var(--success);
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            font-weight: 600;
        }

        .fee-label {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .fee-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 2rem;
            border-top: 1px solid var(--light-gray);
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.2);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert i {
            margin-top: 0.125rem;
        }

        /* Help Tips */
        .help-tip {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            display: flex;
            gap: 0.75rem;
        }

        .help-tip i {
            color: var(--info);
            font-size: 1.25rem;
            margin-top: 0.125rem;
        }

        .help-tip-content h4 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .help-tip-content p {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-container {
                padding: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .fee-summary {
                grid-template-columns: 1fr;
            }
        }

        /* Toggle Switch */
        .toggle-group {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .toggle-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .toggle-option input[type="radio"] {
            margin: 0;
        }

        /* Program type specific styles */
        .program-type-hint {
            background: #f8f9fa;
            border-left: 4px solid var(--info);
            padding: 0.75rem;
            margin-top: 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            color: var(--gray);
        }

        .program-type-hint i {
            color: var(--info);
            margin-right: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="<?php echo BASE_URL; ?>modules/admin/academic/">Academics</a>
            <i class="fas fa-chevron-right"></i>
            <a href="index.php">Programs</a>
            <i class="fas fa-chevron-right"></i>
            <span>Create New</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Create New Program</h1>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Programs
            </a>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['database'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-database"></i>
                <?php echo htmlspecialchars($errors['database']); ?>
            </div>
        <?php endif; ?>

        <!-- Help Tip -->
        <div class="help-tip">
            <i class="fas fa-lightbulb"></i>
            <div class="help-tip-content">
                <h4>Program Creation Guide</h4>
                <p>Program ID will be auto-generated. Program code can be manually entered or auto-generated from the program name. All fields with * are required.</p>
            </div>
        </div>

        <!-- Main Form -->
        <form method="POST" id="programForm" class="form-container">
            <!-- Basic Information Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-info-circle"></i> Basic Information
                </h2>

                <!-- School/Institution Field -->
                <?php if ($school_id): ?>
                    <input type="hidden" name="school_id" value="<?php echo $school_id; ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">School/Institution</label>
                            <?php 
                                $school_name = '';
                                if ($school_id) {
                                    $school_stmt = $conn->prepare("SELECT name FROM schools WHERE id = ?");
                                    $school_stmt->bind_param("i", $school_id);
                                    $school_stmt->execute();
                                    $school_result = $school_stmt->get_result();
                                    if ($school_row = $school_result->fetch_assoc()) {
                                        $school_name = $school_row['name'];
                                    }
                                }
                            ?>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($school_name); ?>" readonly>
                            <div class="form-text">This program will be associated with <?php echo htmlspecialchars($school_name); ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="school_id" class="form-label">School/Institution</label>
                            <select name="school_id" id="school_id" class="form-control">
                                <option value="">Select School (Optional)</option>
                                <?php foreach ($schools as $school): ?>
                                    <option value="<?php echo $school['id']; ?>" 
                                        <?php echo ($program['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($school['name']); ?>
                                        <?php if ($school['short_name']): ?> (<?php echo htmlspecialchars($school['short_name']); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select the school this program belongs to (leave empty for general programs)</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="program_code" class="form-label required">Program Code
                            <button type="button" class="auto-generate-btn" onclick="generateProgramCode()">
                                <i class="fas fa-magic"></i> Auto-generate
                            </button>
                        </label>
                        <input type="text" name="program_code" id="program_code"
                            class="form-control <?php echo isset($errors['program_code']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($program['program_code']); ?>"
                            placeholder="e.g., DM101, DS201" required>
                        <?php if (isset($errors['program_code'])): ?>
                            <div class="form-text error"><?php echo htmlspecialchars($errors['program_code']); ?></div>
                        <?php else: ?>
                            <div class="form-text">Unique identifier for the program. Auto-generates from program name.</div>
                        <?php endif; ?>

                        <!-- Auto-suggest box -->
                        <div class="auto-suggest-box" id="autoSuggestBox">
                            <div class="suggested-code" onclick="useSuggestedCode('<?php echo generateProgramCode($program['name'], $conn); ?>')">
                                <span class="code"><?php echo generateProgramCode($program['name'], $conn); ?></span>
                                <span class="action">Click to use</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="name" class="form-label required">Program Name</label>
                        <input type="text" name="name" id="name"
                            class="form-control <?php echo isset($errors['name']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($program['name']); ?>"
                            placeholder="e.g., Digital Marketing Mastery" required>
                        <?php if (isset($errors['name'])): ?>
                            <div class="form-text error"><?php echo htmlspecialchars($errors['name']); ?></div>
                        <?php else: ?>
                            <div class="form-text">Full name of the program</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="program_type" class="form-label required">Program Type</label>
                        <select name="program_type" id="program_type" class="form-control" required>
                            <option value="online" <?php echo $program['program_type'] === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="onsite" <?php echo $program['program_type'] === 'onsite' ? 'selected' : ''; ?>>Onsite</option>
                            <option value="school" <?php echo $program['program_type'] === 'school' ? 'selected' : ''; ?>>School</option>
                        </select>
                        <div class="form-text">Online: Virtual delivery | Onsite: Physical classroom | School: Partner school programs</div>
                        <div id="programTypeHint" class="program-type-hint" style="display: none;">
                            <i class="fas fa-info-circle"></i>
                            <span id="programTypeHintText"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="active" <?php echo $program['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $program['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="upcoming" <?php echo $program['status'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        </select>
                        <div class="form-text">Active programs are available for enrollment</div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control"
                        placeholder="Detailed description of the program, learning outcomes, target audience..."><?php echo htmlspecialchars($program['description']); ?></textarea>
                    <div class="form-text">This description will be visible to prospective students</div>
                </div>
            </div>

            <!-- Program Structure Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="fas fa-calendar-alt"></i> Program Structure
                </h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="duration_weeks" class="form-label required">Duration (Weeks)</label>
                        <input type="number" name="duration_weeks" id="duration_weeks"
                            class="form-control" min="1" max="52"
                            value="<?php echo $program['duration_weeks']; ?>" required>
                        <div class="form-text">Total program duration in weeks</div>
                    </div>

                    <div class="form-group">
                        <label for="duration_mode" class="form-label">Duration Mode</label>
                        <select name="duration_mode" id="duration_mode" class="form-control">
                            <option value="">Select mode</option>
                            <option value="termly_10_weeks" <?php echo $program['duration_mode'] === 'termly_10_weeks' ? 'selected' : ''; ?>>Termly (10 weeks per term)</option>
                            <option value="block_8_weeks" <?php echo $program['duration_mode'] === 'block_8_weeks' ? 'selected' : ''; ?>>Block-based (8 weeks per block)</option>
                            <option value="intensive_4_weeks" <?php echo $program['duration_mode'] === 'intensive_4_weeks' ? 'selected' : ''; ?>>Intensive (4 weeks)</option>
                        </select>
                        <div class="form-text">How the program duration is structured</div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="schedule_type" class="form-label">Schedule Type</label>
                    <input type="text" name="schedule_type" id="schedule_type" class="form-control"
                        value="<?php echo htmlspecialchars($program['schedule_type']); ?>"
                        placeholder="e.g., 'Weekdays 6-8pm', 'Weekends 10am-2pm'">
                    <div class="form-text">Typical class schedule for this program</div>
                </div>
            </div>

            <!-- Fee Structure Section -->
            <div class="form-section" id="feeSection">
                <h2 class="section-title">
                    <i class="fas fa-money-bill-wave"></i> Fee Structure
                </h2>

                <!-- Fee Calculator -->
                <div class="fee-calculator" id="feeCalculator">
                    <h4 style="color: var(--dark); margin-bottom: 1rem;">Fee Calculator</h4>
                    <div class="fee-summary">
                        <div class="fee-item">
                            <div class="fee-label">Base Program Fee</div>
                            <div class="fee-value" id="baseFeeDisplay">₦0.00</div>
                        </div>
                        <div class="fee-item">
                            <div class="fee-label">Registration Fee</div>
                            <div class="fee-value" id="regFeeDisplay">₦0.00</div>
                        </div>
                        <div class="fee-item total">
                            <div class="fee-label">Total Fee</div>
                            <div class="fee-value" id="totalFeeDisplay">₦0.00</div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="base_fee" class="form-label required">Base Program Fee (₦)</label>
                        <input type="number" name="base_fee" id="base_fee"
                            class="form-control <?php echo isset($errors['base_fee']) ? 'error' : ''; ?>"
                            value="<?php echo number_format($program['base_fee'], 2, '.', ''); ?>"
                            step="0.01" min="0" required>
                        <?php if (isset($errors['base_fee'])): ?>
                            <div class="form-text error"><?php echo htmlspecialchars($errors['base_fee']); ?></div>
                        <?php else: ?>
                            <div class="form-text">Base tuition fee before registration</div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="registration_fee" class="form-label">Registration Fee (₦)</label>
                        <input type="number" name="registration_fee" id="registration_fee"
                            class="form-control"
                            value="<?php echo number_format($program['registration_fee'], 2, '.', ''); ?>"
                            step="0.01" min="0">
                        <div class="form-text">One-time registration fee (optional)</div>
                    </div>
                </div>

                <div class="form-row" id="specificFeeGroups">
                    <!-- Online Fee (shown/hidden based on program type) -->
                    <div class="form-group" id="onlineFeeGroup">
                        <label for="online_fee" class="form-label">Online Program Fee (₦)</label>
                        <input type="number" name="online_fee" id="online_fee"
                            class="form-control"
                            value="<?php echo number_format($program['online_fee'], 2, '.', ''); ?>"
                            step="0.01" min="0">
                        <div class="form-text">Specific fee for online delivery</div>
                    </div>

                    <!-- Onsite Fee (shown/hidden based on program type) -->
                    <div class="form-group" id="onsiteFeeGroup">
                        <label for="onsite_fee" class="form-label">Onsite Program Fee (₦)</label>
                        <input type="number" name="onsite_fee" id="onsite_fee"
                            class="form-control"
                            value="<?php echo number_format($program['onsite_fee'], 2, '.', ''); ?>"
                            step="0.01" min="0">
                        <div class="form-text">Specific fee for onsite delivery</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="payment_plan_type" class="form-label">Payment Plan Type</label>
                        <select name="payment_plan_type" id="payment_plan_type" class="form-control">
                            <option value="full" <?php echo $program['payment_plan_type'] === 'full' ? 'selected' : ''; ?>>Full Payment</option>
                            <option value="installment" <?php echo $program['payment_plan_type'] === 'installment' ? 'selected' : ''; ?>>Installments</option>
                            <option value="block" <?php echo $program['payment_plan_type'] === 'block' ? 'selected' : ''; ?>>Block-based (Online)</option>
                        </select>
                        <div class="form-text">How students will pay for this program</div>
                    </div>

                    <div class="form-group" id="installmentCountGroup" style="display: <?php echo $program['payment_plan_type'] === 'installment' ? 'block' : 'none'; ?>;">
                        <label for="installment_count" class="form-label">Number of Installments</label>
                        <input type="number" name="installment_count" id="installment_count"
                            class="form-control" min="2" max="12"
                            value="<?php echo $program['installment_count']; ?>">
                        <div class="form-text">For installment plans only (2-12 installments)</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="late_fee_percentage" class="form-label">Late Fee Percentage (%)</label>
                        <input type="number" name="late_fee_percentage" id="late_fee_percentage"
                            class="form-control" step="0.01" min="0" max="50"
                            value="<?php echo $program['late_fee_percentage']; ?>">
                        <div class="form-text">Percentage added for late payments (0-50%)</div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="fee_description" class="form-label">Fee Description / Breakdown</label>
                    <textarea name="fee_description" id="fee_description" class="form-control" rows="4"
                        placeholder="Detailed breakdown of what the fee covers, additional costs, refund policy, etc."><?php echo htmlspecialchars($program['fee_description']); ?></textarea>
                    <div class="form-text">This will be shown to students on the program page</div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <div>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Program
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // DOM Elements
        const programTypeSelect = document.getElementById('program_type');
        const baseFeeInput = document.getElementById('base_fee');
        const regFeeInput = document.getElementById('registration_fee');
        const onlineFeeInput = document.getElementById('online_fee');
        const onsiteFeeInput = document.getElementById('onsite_fee');
        const paymentPlanSelect = document.getElementById('payment_plan_type');
        const installmentCountGroup = document.getElementById('installmentCountGroup');
        const onlineFeeGroup = document.getElementById('onlineFeeGroup');
        const onsiteFeeGroup = document.getElementById('onsiteFeeGroup');
        const programNameInput = document.getElementById('name');
        const programCodeInput = document.getElementById('program_code');
        const autoSuggestBox = document.getElementById('autoSuggestBox');
        const feeSection = document.getElementById('feeSection');
        const feeCalculator = document.getElementById('feeCalculator');
        const specificFeeGroups = document.getElementById('specificFeeGroups');
        const programTypeHint = document.getElementById('programTypeHint');
        const programTypeHintText = document.getElementById('programTypeHintText');

        // Display elements
        const baseFeeDisplay = document.getElementById('baseFeeDisplay');
        const regFeeDisplay = document.getElementById('regFeeDisplay');
        const totalFeeDisplay = document.getElementById('totalFeeDisplay');

        // Format currency
        function formatCurrency(amount) {
            return '₦' + parseFloat(amount).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Calculate and update fee displays
        function updateFeeDisplays() {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const regFee = parseFloat(regFeeInput.value) || 0;
            const totalFee = baseFee + regFee;

            baseFeeDisplay.textContent = formatCurrency(baseFee);
            regFeeDisplay.textContent = formatCurrency(regFee);
            totalFeeDisplay.textContent = formatCurrency(totalFee);
        }

        // Update specific fees based on base fee
        function updateSpecificFees() {
            const baseFee = parseFloat(baseFeeInput.value) || 0;
            const programType = programTypeSelect.value;

            // Only auto-update if the specific fee fields are empty or match base fee
            if (programType === 'online' && (!onlineFeeInput.value || parseFloat(onlineFeeInput.value) === baseFee)) {
                onlineFeeInput.value = baseFee.toFixed(2);
            }

            if (programType === 'onsite' && (!onsiteFeeInput.value || parseFloat(onsiteFeeInput.value) === baseFee)) {
                // Onsite is typically 20% higher
                onsiteFeeInput.value = (baseFee * 1.2).toFixed(2);
            }

            // Update displays
            updateFeeDisplays();
        }

        // Toggle fee groups based on program type
        function toggleProgramTypeSettings() {
            const programType = programTypeSelect.value;
            
            // Show/hide fee calculator and specific fee groups
            if (programType === 'school') {
                feeCalculator.style.display = 'none';
                specificFeeGroups.style.display = 'none';
                programTypeHint.style.display = 'block';
                programTypeHintText.textContent = 'School programs typically follow the school\'s own fee structure. The base fee entered will be used as the default program fee.';
                
                // Hide online/onsite specific fields
                onlineFeeGroup.style.display = 'none';
                onsiteFeeGroup.style.display = 'none';
            } else {
                feeCalculator.style.display = 'block';
                specificFeeGroups.style.display = 'grid';
                programTypeHint.style.display = 'none';
                
                // Show appropriate fee group
                if (programType === 'online') {
                    onlineFeeGroup.style.display = 'block';
                    onsiteFeeGroup.style.display = 'none';
                    programTypeHint.style.display = 'block';
                    programTypeHintText.textContent = 'Online programs are delivered virtually through our learning platform.';
                } else if (programType === 'onsite') {
                    onlineFeeGroup.style.display = 'none';
                    onsiteFeeGroup.style.display = 'block';
                    programTypeHint.style.display = 'block';
                    programTypeHintText.textContent = 'Onsite programs are delivered in physical classrooms at our academy locations.';
                }
            }
        }

        // Toggle installment count field
        function toggleInstallmentCount() {
            installmentCountGroup.style.display =
                paymentPlanSelect.value === 'installment' ? 'block' : 'none';
        }

        // Generate program code from program name
        function generateProgramCode() {
            const name = programNameInput.value.trim();

            if (!name) {
                alert('Please enter a program name first');
                return;
            }

            // Simple code generation logic
            const words = name.split(' ');
            let code = '';

            if (words.length >= 2) {
                code = (words[0].charAt(0) + words[1].charAt(0)).toUpperCase();
                if (words.length >= 3) {
                    code += words[2].charAt(0).toUpperCase();
                }
            } else {
                code = name.substring(0, 3).toUpperCase();
            }

            // Add timestamp for uniqueness
            const timestamp = new Date().getTime().toString().slice(-3);
            const suggestedCode = code + timestamp;

            // Show suggestion
            autoSuggestBox.innerHTML = `
                <div class="suggested-code" onclick="useSuggestedCode('${suggestedCode}')">
                    <span class="code">${suggestedCode}</span>
                    <span class="action">Click to use</span>
                </div>
            `;
            autoSuggestBox.classList.add('active');
        }

        // Use suggested code
        function useSuggestedCode(code) {
            programCodeInput.value = code;
            autoSuggestBox.classList.remove('active');
        }

        // Initialize form state
        function initializeForm() {
            toggleProgramTypeSettings();
            toggleInstallmentCount();
            updateFeeDisplays();

            // Auto-generate code if name is filled but code is empty
            if (programNameInput.value.trim() && !programCodeInput.value.trim()) {
                setTimeout(generateProgramCode, 500);
            }
        }

        // Event Listeners
        programTypeSelect.addEventListener('change', toggleProgramTypeSettings);
        baseFeeInput.addEventListener('input', updateSpecificFees);
        baseFeeInput.addEventListener('blur', updateSpecificFees);
        regFeeInput.addEventListener('input', updateFeeDisplays);
        paymentPlanSelect.addEventListener('change', toggleInstallmentCount);

        // Auto-generate code when name field loses focus
        programNameInput.addEventListener('blur', function() {
            if (this.value.trim() && !programCodeInput.value.trim()) {
                generateProgramCode();
            }
        });

        // Hide suggestion box when clicking outside
        document.addEventListener('click', function(e) {
            if (!programCodeInput.contains(e.target) && !autoSuggestBox.contains(e.target)) {
                autoSuggestBox.classList.remove('active');
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initializeForm);

        // Form validation
        document.getElementById('programForm').addEventListener('submit', function(e) {
            let isValid = true;
            const programCode = document.getElementById('program_code').value.trim();
            const programName = document.getElementById('name').value.trim();
            const baseFee = parseFloat(document.getElementById('base_fee').value);
            const programType = document.getElementById('program_type').value;

            // Clear previous error states
            document.querySelectorAll('.form-control.error').forEach(el => {
                el.classList.remove('error');
            });

            // Validate program code
            if (!programCode) {
                document.getElementById('program_code').classList.add('error');
                isValid = false;
            }

            // Validate program name
            if (!programName) {
                document.getElementById('name').classList.add('error');
                isValid = false;
            }

            // Validate base fee
            if (isNaN(baseFee) || baseFee < 0) {
                document.getElementById('base_fee').classList.add('error');
                isValid = false;
            }

            // Validate specific fees based on program type
            if (programType === 'online') {
                const onlineFee = parseFloat(document.getElementById('online_fee').value);
                if (isNaN(onlineFee) || onlineFee < 0) {
                    document.getElementById('online_fee').classList.add('error');
                    isValid = false;
                }
            } else if (programType === 'onsite') {
                const onsiteFee = parseFloat(document.getElementById('onsite_fee').value);
                if (isNaN(onsiteFee) || onsiteFee < 0) {
                    document.getElementById('onsite_fee').classList.add('error');
                    isValid = false;
                }
            }

            if (!isValid) {
                e.preventDefault();
                alert('Please fix the errors in the form before submitting.');
            }
        });

        // Auto-fill program code when typing program name (optional feature)
        programNameInput.addEventListener('input', function() {
            if (!programCodeInput.value.trim()) {
                // Show generating indicator
                autoSuggestBox.innerHTML = '<div style="padding: 0.5rem; text-align: center; color: var(--gray);">Type more and then click "Auto-generate"</div>';
                autoSuggestBox.classList.add('active');
            }
        });
    </script>
</body>

</html>