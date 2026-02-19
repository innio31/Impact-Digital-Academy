<?php
// modules/admin/finance/fees/configure.php

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

// Get fee structure ID
$fee_structure_id = $_GET['id'] ?? 0;
if (!$fee_structure_id) {
    $_SESSION['error'] = "No fee structure specified";
    header('Location: index.php');
    exit();
}

// Get fee structure details
$stmt = $conn->prepare("
    SELECT fs.*, p.name as program_name, p.program_code, p.program_type 
    FROM fee_structures fs 
    JOIN programs p ON p.id = fs.program_id 
    WHERE fs.id = ?
");
$stmt->bind_param("i", $fee_structure_id);
$stmt->execute();
$fee_structure = $stmt->get_result()->fetch_assoc();

if (!$fee_structure) {
    $_SESSION['error'] = "Fee structure not found";
    header('Location: index.php');
    exit();
}

// Get payment plan for this program
$payment_plan_sql = "SELECT * FROM payment_plans WHERE program_id = ? AND program_type = ?";
$payment_plan_stmt = $conn->prepare($payment_plan_sql);
$payment_plan_stmt->bind_param("is", $fee_structure['program_id'], $fee_structure['program_type']);
$payment_plan_stmt->execute();
$payment_plan = $payment_plan_stmt->get_result()->fetch_assoc();

// Get penalty settings for this program type
$penalty_sql = "SELECT * FROM penalty_settings WHERE program_type = ?";
$penalty_stmt = $conn->prepare($penalty_sql);
$penalty_stmt->bind_param("s", $fee_structure['program_type']);
$penalty_stmt->execute();
$penalty_settings = $penalty_stmt->get_result()->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_payment_plan') {
        $registration_fee_percentage = $_POST['registration_fee_percentage'] ?? 0;
        $block1_percentage = $_POST['block1_percentage'] ?? 70;
        $block2_percentage = $_POST['block2_percentage'] ?? 30;
        $late_fee_percentage = $_POST['late_fee_percentage'] ?? 5;
        $block1_due_days = $_POST['block1_due_days'] ?? 30;
        $block2_due_days = $_POST['block2_due_days'] ?? 60;
        $refund_policy_days = $_POST['refund_policy_days'] ?? 14;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($payment_plan) {
            // Update existing payment plan
            $sql = "UPDATE payment_plans SET 
                    registration_fee = ?, 
                    block1_percentage = ?, 
                    block2_percentage = ?, 
                    late_fee_percentage = ?, 
                    block1_due_days = ?, 
                    block2_due_days = ?, 
                    refund_policy_days = ?, 
                    is_active = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ddddiiiii",
                $registration_fee_percentage,
                $block1_percentage,
                $block2_percentage,
                $late_fee_percentage,
                $block1_due_days,
                $block2_due_days,
                $refund_policy_days,
                $is_active,
                $payment_plan['id']
            );
        } else {
            // Create new payment plan
            $sql = "INSERT INTO payment_plans (program_id, program_type, plan_name, 
                    registration_fee, block1_percentage, block2_percentage, 
                    late_fee_percentage, block1_due_days, block2_due_days, 
                    refund_policy_days, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $plan_name = $fee_structure['program_code'] . ' Payment Plan';
            $stmt->bind_param(
                "issddddiiii",
                $fee_structure['program_id'],
                $fee_structure['program_type'],
                $plan_name,
                $registration_fee_percentage,
                $block1_percentage,
                $block2_percentage,
                $late_fee_percentage,
                $block1_due_days,
                $block2_due_days,
                $refund_policy_days,
                $is_active
            );
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = "Payment plan updated successfully";
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $fee_structure_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to update payment plan: " . $stmt->error;
        }
    } elseif ($action === 'update_penalty_settings') {
        $grace_period_days = $_POST['grace_period_days'] ?? 7;
        $late_fee_percentage = $_POST['late_fee_percentage'] ?? 5;
        $min_late_fee = $_POST['min_late_fee'] ?? 500;
        $max_late_fee = $_POST['max_late_fee'] ?? 5000;
        $daily_penalty = $_POST['daily_penalty'] ?? 0;
        $suspension_days = $_POST['suspension_days'] ?? 21;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($penalty_settings) {
            // Update existing settings
            $sql = "UPDATE penalty_settings SET 
                    grace_period_days = ?, 
                    late_fee_percentage = ?, 
                    min_late_fee = ?, 
                    max_late_fee = ?, 
                    daily_penalty = ?, 
                    suspension_days = ?, 
                    is_active = ? 
                    WHERE program_type = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "idddddis",
                $grace_period_days,
                $late_fee_percentage,
                $min_late_fee,
                $max_late_fee,
                $daily_penalty,
                $suspension_days,
                $is_active,
                $fee_structure['program_type']
            );
        } else {
            // Create new settings
            $sql = "INSERT INTO penalty_settings (program_type, grace_period_days, 
                    late_fee_percentage, min_late_fee, max_late_fee, daily_penalty, 
                    suspension_days, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sidddddi",
                $fee_structure['program_type'],
                $grace_period_days,
                $late_fee_percentage,
                $min_late_fee,
                $max_late_fee,
                $daily_penalty,
                $suspension_days,
                $is_active
            );
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = "Penalty settings updated successfully";
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $fee_structure_id);
            exit();
        } else {
            $_SESSION['error'] = "Failed to update penalty settings: " . $stmt->error;
        }
    }
}

// Re-fetch updated data
if ($payment_plan) {
    $payment_plan_stmt->execute();
    $payment_plan = $payment_plan_stmt->get_result()->fetch_assoc();
}
if ($penalty_settings) {
    $penalty_stmt->execute();
    $penalty_settings = $penalty_stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Fee Structure - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reuse styles from index.php */
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --dark-light: #334155;
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
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1 i {
            color: var(--primary);
        }

        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary);
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .config-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .config-card h3 {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            color: var(--dark);
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

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 0.75rem;
        }

        .col-md-4 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
            padding: 0 0.75rem;
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
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
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

        .percentage-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .percentage-display input {
            flex: 1;
        }

        .percentage-display span {
            color: #64748b;
            font-weight: 500;
        }

        .fee-summary {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }

        .fee-summary h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .fee-summary p {
            color: #64748b;
            font-size: 0.9rem;
        }

        .amount {
            font-weight: bold;
            color: var(--primary);
        }

        .toggle-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: var(--success);
        }

        input:checked+.slider:before {
            transform: translateX(26px);
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar would be included from common template -->
        <div class="main-content">
            <div class="header">
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Fee Structures
                </a>
                <h1>
                    <i class="fas fa-cogs"></i>
                    Configure Fee Structure: <?= htmlspecialchars($fee_structure['program_name']) ?> (<?= $fee_structure['program_code'] ?>)
                </h1>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success'];
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $_SESSION['error'];
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Fee Structure Summary -->
            <div class="config-card">
                <h3><i class="fas fa-file-invoice-dollar"></i> Current Fee Structure</h3>
                <div class="row">
                    <div class="col-md-6">
                        <div class="fee-summary">
                            <h4>Total Amount: <span class="amount">₦<?= number_format($fee_structure['total_amount'], 2) ?></span></h4>
                            <p>Program: <?= htmlspecialchars($fee_structure['program_name']) ?> (<?= strtoupper($fee_structure['program_type']) ?>)</p>
                            <p>Status: <?= $fee_structure['is_active'] ? 'Active' : 'Inactive' ?></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="fee-summary">
                            <h4>Fee Breakdown</h4>
                            <p>Registration Fee: ₦<?= number_format($fee_structure['registration_fee'], 2) ?></p>
                            <p>Block 1: ₦<?= number_format($fee_structure['block1_amount'], 2) ?></p>
                            <p>Block 2: ₦<?= number_format($fee_structure['block2_amount'] ?? 0, 2) ?></p>
                            <p>Block 3: ₦<?= number_format($fee_structure['block3_amount'] ?? 0, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Plan Configuration -->
            <div class="config-card">
                <h3><i class="fas fa-calendar-alt"></i> Payment Plan Configuration</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_payment_plan">

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Registration Fee (₦)</label>
                                <input type="number" name="registration_fee_percentage" class="form-control"
                                    value="<?= $payment_plan ? $payment_plan['registration_fee'] : $fee_structure['registration_fee'] ?>"
                                    step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Block 1 Percentage</label>
                                <div class="percentage-display">
                                    <input type="number" name="block1_percentage" class="form-control"
                                        value="<?= $payment_plan['block1_percentage'] ?? 70 ?>"
                                        min="0" max="100" step="0.1" required>
                                    <span>%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Block 2 Percentage</label>
                                <div class="percentage-display">
                                    <input type="number" name="block2_percentage" class="form-control"
                                        value="<?= $payment_plan['block2_percentage'] ?? 30 ?>"
                                        min="0" max="100" step="0.1" required>
                                    <span>%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Late Fee Percentage</label>
                                <div class="percentage-display">
                                    <input type="number" name="late_fee_percentage" class="form-control"
                                        value="<?= $payment_plan['late_fee_percentage'] ?? 5 ?>"
                                        min="0" max="100" step="0.1" required>
                                    <span>%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Block 1 Due (Days)</label>
                                <input type="number" name="block1_due_days" class="form-control"
                                    value="<?= $payment_plan['block1_due_days'] ?? 30 ?>"
                                    min="1" max="365" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Block 2 Due (Days)</label>
                                <input type="number" name="block2_due_days" class="form-control"
                                    value="<?= $payment_plan['block2_due_days'] ?? 60 ?>"
                                    min="1" max="365" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Refund Policy (Days)</label>
                                <input type="number" name="refund_policy_days" class="form-control"
                                    value="<?= $payment_plan['refund_policy_days'] ?? 14 ?>"
                                    min="0" max="365">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Active Status</label>
                                <div class="toggle-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="is_active" value="1"
                                            <?= ($payment_plan['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span><?= ($payment_plan['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Payment Plan
                    </button>
                </form>
            </div>

            <!-- Penalty Settings -->
            <div class="config-card">
                <h3><i class="fas fa-exclamation-triangle"></i> Penalty & Suspension Settings</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_penalty_settings">

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Grace Period (Days)</label>
                                <input type="number" name="grace_period_days" class="form-control"
                                    value="<?= $penalty_settings['grace_period_days'] ?? 7 ?>"
                                    min="0" max="30" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Late Fee Percentage</label>
                                <div class="percentage-display">
                                    <input type="number" name="late_fee_percentage" class="form-control"
                                        value="<?= $penalty_settings['late_fee_percentage'] ?? 5 ?>"
                                        min="0" max="100" step="0.1" required>
                                    <span>%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Suspension After (Days)</label>
                                <input type="number" name="suspension_days" class="form-control"
                                    value="<?= $penalty_settings['suspension_days'] ?? 21 ?>"
                                    min="1" max="90" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Minimum Late Fee (₦)</label>
                                <input type="number" name="min_late_fee" class="form-control"
                                    value="<?= $penalty_settings['min_late_fee'] ?? 500 ?>"
                                    step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Maximum Late Fee (₦)</label>
                                <input type="number" name="max_late_fee" class="form-control"
                                    value="<?= $penalty_settings['max_late_fee'] ?? 5000 ?>"
                                    step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Daily Penalty (₦)</label>
                                <input type="number" name="daily_penalty" class="form-control"
                                    value="<?= $penalty_settings['daily_penalty'] ?? 0 ?>"
                                    step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Active Status</label>
                                <div class="toggle-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="is_active" value="1"
                                            <?= ($penalty_settings['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span><?= ($penalty_settings['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save"></i> Save Penalty Settings
                    </button>
                </form>
            </div>

            <!-- Additional Configuration Options -->
            <div class="config-card">
                <h3><i class="fas fa-tools"></i> Additional Configuration</h3>
                <div class="row">
                    <div class="col-md-6">
                        <div class="fee-summary">
                            <h4>Quick Actions</h4>
                            <a href="edit.php?id=<?= $fee_structure_id ?>" class="btn btn-primary" style="margin-bottom: 0.5rem;">
                                <i class="fas fa-edit"></i> Edit Fee Structure
                            </a>
                            <a href="../students/index.php?program_id=<?= $fee_structure['program_id'] ?>" class="btn btn-success" style="margin-bottom: 0.5rem;">
                                <i class="fas fa-users"></i> View Students Using This Structure
                            </a>
                            <a href="waivers.php?fee_structure_id=<?= $fee_structure_id ?>" class="btn btn-warning">
                                <i class="fas fa-percentage"></i> Manage Fee Waivers
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="fee-summary">
                            <h4>Preview Calculations</h4>
                            <p>Based on current settings:</p>
                            <p>Block 1 Amount: ₦<?= number_format(($fee_structure['total_amount'] * ($payment_plan['block1_percentage'] ?? 70) / 100), 2) ?></p>
                            <p>Block 2 Amount: ₦<?= number_format(($fee_structure['total_amount'] * ($payment_plan['block2_percentage'] ?? 30) / 100), 2) ?></p>
                            <p>Late Fee per Block: ₦<?= number_format(($fee_structure['block1_amount'] * ($penalty_settings['late_fee_percentage'] ?? 5) / 100), 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
<?php $conn->close(); ?>