<?php
// modules/admin/finance/fees/penalties.php

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

// Get all penalty settings
$penalty_sql = "SELECT * FROM penalty_settings ORDER BY program_type";
$penalty_result = $conn->query($penalty_sql);
$penalty_settings = $penalty_result->fetch_all(MYSQLI_ASSOC);

// Get penalty history
$history_sql = "SELECT ph.*, 
               u.first_name, u.last_name, u.email,
               p.name as program_name, p.program_code,
               fs.name as fee_structure_name,
               wa.first_name as waived_by_first, wa.last_name as waived_by_last
               FROM penalty_history ph
               LEFT JOIN users u ON u.id = ph.student_id
               LEFT JOIN programs p ON p.id = ph.program_id
               LEFT JOIN fee_structures fs ON fs.id = ph.fee_structure_id
               LEFT JOIN users wa ON wa.id = ph.waived_by
               ORDER BY ph.applied_at DESC
               LIMIT 100";
$history_result = $conn->query($history_sql);
$penalty_history = $history_result->fetch_all(MYSQLI_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $program_type = $_POST['program_type'] ?? '';
        $grace_period_days = $_POST['grace_period_days'] ?? 7;
        $late_fee_percentage = $_POST['late_fee_percentage'] ?? 5;
        $min_late_fee = $_POST['min_late_fee'] ?? 500;
        $max_late_fee = $_POST['max_late_fee'] ?? 5000;
        $daily_penalty = $_POST['daily_penalty'] ?? 0;
        $suspension_days = $_POST['suspension_days'] ?? 21;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Check if setting exists for this program type
        $check_sql = "SELECT id FROM penalty_settings WHERE program_type = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $program_type);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc();

        if ($exists) {
            // Update existing
            $sql = "UPDATE penalty_settings SET 
                    grace_period_days = ?, 
                    late_fee_percentage = ?, 
                    min_late_fee = ?, 
                    max_late_fee = ?, 
                    daily_penalty = ?, 
                    suspension_days = ?, 
                    is_active = ?,
                    updated_at = NOW()
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
                $program_type
            );
        } else {
            // Insert new
            $sql = "INSERT INTO penalty_settings (program_type, grace_period_days, 
                    late_fee_percentage, min_late_fee, max_late_fee, daily_penalty, 
                    suspension_days, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sidddddi",
                $program_type,
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
            $_SESSION['success'] = "Penalty settings saved successfully for " . strtoupper($program_type) . " programs";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = "Failed to save penalty settings: " . $stmt->error;
        }
    } elseif ($action === 'apply_penalty') {
        $student_id = $_POST['student_id'] ?? 0;
        $penalty_type = $_POST['penalty_type'] ?? 'late_fee';
        $penalty_amount = $_POST['penalty_amount'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        $program_id = $_POST['program_id'] ?? NULL;
        $fee_structure_id = $_POST['fee_structure_id'] ?? NULL;

        // Get student details
        $student_sql = "SELECT first_name, last_name FROM users WHERE id = ?";
        $student_stmt = $conn->prepare($student_sql);
        $student_stmt->bind_param("i", $student_id);
        $student_stmt->execute();
        $student = $student_stmt->get_result()->fetch_assoc();

        if ($student) {
            $sql = "INSERT INTO penalty_history (student_id, program_id, fee_structure_id, 
                    penalty_type, penalty_amount, reason, applied_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "iiisdss",
                $student_id,
                $program_id,
                $fee_structure_id,
                $penalty_type,
                $penalty_amount,
                $reason,
                $_SESSION['user_id']
            );

            if ($stmt->execute()) {
                $_SESSION['success'] = "Penalty applied to " . $student['first_name'] . " " . $student['last_name'];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $_SESSION['error'] = "Failed to apply penalty: " . $stmt->error;
            }
        } else {
            $_SESSION['error'] = "Student not found";
        }
    } elseif ($action === 'waive_penalty') {
        $penalty_id = $_POST['penalty_id'] ?? 0;
        $waiver_reason = $_POST['waiver_reason'] ?? '';

        $sql = "UPDATE penalty_history SET 
                waived = 1, 
                waiver_reason = ?, 
                waived_by = ?, 
                waived_at = NOW() 
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $waiver_reason, $_SESSION['user_id'], $penalty_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Penalty waived successfully";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $_SESSION['error'] = "Failed to waive penalty: " . $stmt->error;
        }
    }
}

// Get programs for dropdown
$programs_sql = "SELECT id, name, program_code, program_type FROM programs WHERE status = 'active' ORDER BY name";
$programs_result = $conn->query($programs_sql);
$programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Get fee structures
$fee_structures_sql = "SELECT fs.*, p.name as program_name FROM fee_structures fs 
                      JOIN programs p ON p.id = fs.program_id 
                      WHERE fs.is_active = 1 
                      ORDER BY p.name";
$fee_structures_result = $conn->query($fee_structures_sql);
$fee_structures = $fee_structures_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penalty Management - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reuse styles from waivers.php */
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

        .penalty-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .penalty-card h3 {
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

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 0.75rem;
        }

        .col-md-2 {
            flex: 0 0 16.666%;
            max-width: 16.666%;
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
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
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

        .btn-danger {
            background: var(--danger);
            color: white;
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

        .filters-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .table-container {
            overflow-x: auto;
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
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: #f8fafc;
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

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-waived {
            background: #fef3c7;
            color: #92400e;
        }

        .amount {
            font-weight: 600;
            color: var(--dark);
        }

        .currency {
            color: #64748b;
            font-size: 0.85rem;
            margin-left: 0.25rem;
        }

        .penalty-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-late-fee {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-administrative {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .badge-other {
            background: #f1f5f9;
            color: #64748b;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .setting-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-top: 4px solid var(--warning);
        }

        .setting-card h4 {
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .program-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .badge-online {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-onsite {
            background: #dcfce7;
            color: #166534;
        }
    </style>
    <script>
        function openApplyPenaltyModal() {
            document.getElementById('applyPenaltyModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openWaiveModal(penaltyId, studentName, amount) {
            document.getElementById('waivePenaltyId').value = penaltyId;
            document.getElementById('waiveStudentInfo').textContent =
                'Waive penalty for: ' + studentName + ' (₦' + parseFloat(amount).toFixed(2) + ')';
            document.getElementById('waiveModal').style.display = 'flex';
        }

        function updatePenaltyAmount() {
            const type = document.getElementById('penalty_type').value;
            const amountInput = document.getElementById('penalty_amount');

            if (type === 'late_fee') {
                amountInput.placeholder = 'Enter late fee amount';
            } else if (type === 'administrative') {
                amountInput.placeholder = 'Enter administrative fee';
            } else {
                amountInput.placeholder = 'Enter penalty amount';
            }
        }

        function calculateLateFee() {
            const amount = parseFloat(document.getElementById('late_fee_amount').value) || 0;
            const percentage = parseFloat(document.getElementById('late_fee_percentage').value) || 5;
            const minFee = parseFloat(document.getElementById('min_late_fee').value) || 500;
            const maxFee = parseFloat(document.getElementById('max_late_fee').value) || 5000;

            let calculated = (amount * percentage) / 100;
            calculated = Math.max(calculated, minFee);
            calculated = Math.min(calculated, maxFee);

            document.getElementById('calculated_late_fee').textContent =
                'Calculated Late Fee: ₦' + calculated.toFixed(2);
        }
    </script>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar would be included from common template -->
        <div class="main-content">
            <div class="header">
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Fee Management
                </a>
                <h1>
                    <i class="fas fa-exclamation-triangle"></i>
                    Penalty & Late Fee Management
                </h1>
                <p>Configure penalty settings and manage late fee applications</p>
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

            <!-- Penalty Settings -->
            <div class="penalty-card">
                <h3><i class="fas fa-cogs"></i> Global Penalty Settings</h3>
                <div class="settings-grid">
                    <?php
                    $program_types = ['online', 'onsite'];
                    foreach ($program_types as $program_type):
                        $setting = null;
                        foreach ($penalty_settings as $ps) {
                            if ($ps['program_type'] === $program_type) {
                                $setting = $ps;
                                break;
                            }
                        }
                    ?>
                        <div class="setting-card">
                            <div class="program-type-badge badge-<?= $program_type ?>">
                                <?= strtoupper($program_type) ?> Programs
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_settings">
                                <input type="hidden" name="program_type" value="<?= $program_type ?>">

                                <div class="form-group">
                                    <label>Grace Period (Days)</label>
                                    <input type="number" name="grace_period_days" class="form-control"
                                        value="<?= $setting['grace_period_days'] ?? 7 ?>"
                                        min="0" max="30" required>
                                </div>

                                <div class="form-group">
                                    <label>Late Fee Percentage</label>
                                    <input type="number" name="late_fee_percentage" class="form-control"
                                        value="<?= $setting['late_fee_percentage'] ?? 5 ?>"
                                        min="0" max="100" step="0.1" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Min Late Fee (₦)</label>
                                            <input type="number" name="min_late_fee" class="form-control"
                                                value="<?= $setting['min_late_fee'] ?? 500 ?>"
                                                step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Max Late Fee (₦)</label>
                                            <input type="number" name="max_late_fee" class="form-control"
                                                value="<?= $setting['max_late_fee'] ?? 5000 ?>"
                                                step="0.01" min="0" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Daily Penalty (₦)</label>
                                    <input type="number" name="daily_penalty" class="form-control"
                                        value="<?= $setting['daily_penalty'] ?? 0 ?>"
                                        step="0.01" min="0">
                                </div>

                                <div class="form-group">
                                    <label>Suspension After (Days)</label>
                                    <input type="number" name="suspension_days" class="form-control"
                                        value="<?= $setting['suspension_days'] ?? 21 ?>"
                                        min="1" max="90" required>
                                </div>

                                <div class="form-group">
                                    <div class="toggle-group">
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="is_active" value="1"
                                                <?= ($setting['is_active'] ?? 1) ? 'checked' : '' ?>>
                                            <span class="slider"></span>
                                        </label>
                                        <span><?= ($setting['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Late Fee Calculator -->
            <div class="penalty-card">
                <h3><i class="fas fa-calculator"></i> Late Fee Calculator</h3>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Original Amount (₦)</label>
                            <input type="number" id="late_fee_amount" class="form-control"
                                step="0.01" min="0" oninput="calculateLateFee()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Late Fee Percentage (%)</label>
                            <input type="number" id="late_fee_percentage" class="form-control"
                                value="5" step="0.1" min="0" max="100" oninput="calculateLateFee()">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Minimum Late Fee (₦)</label>
                            <input type="number" id="min_late_fee" class="form-control"
                                value="500" step="0.01" min="0" oninput="calculateLateFee()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Maximum Late Fee (₦)</label>
                            <input type="number" id="max_late_fee" class="form-control"
                                value="5000" step="0.01" min="0" oninput="calculateLateFee()">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <h4 id="calculated_late_fee">Calculated Late Fee: ₦0.00</h4>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="penalty-card">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <button onclick="openApplyPenaltyModal()" class="btn btn-warning">
                    <i class="fas fa-plus"></i> Apply Manual Penalty
                </button>
                <a href="../students/overdue.php" class="btn btn-danger">
                    <i class="fas fa-clock"></i> View Overdue Students
                </a>
                <a href="../reports/outstanding.php" class="btn btn-primary">
                    <i class="fas fa-chart-bar"></i> Outstanding Payments Report
                </a>
                <button class="btn btn-success" onclick="alert('Feature coming soon: Auto-apply late fees to all overdue students')">
                    <i class="fas fa-robot"></i> Auto-apply Late Fees
                </button>
            </div>

            <!-- Penalty History -->
            <div class="penalty-card">
                <h3><i class="fas fa-history"></i> Penalty History</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Applied By</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($penalty_history) > 0): ?>
                                <?php foreach ($penalty_history as $penalty): ?>
                                    <tr>
                                        <td>
                                            <?php if ($penalty['student_id']): ?>
                                                <strong><?= htmlspecialchars($penalty['first_name'] . ' ' . $penalty['last_name']) ?></strong><br>
                                                <small><?= $penalty['email'] ?></small>
                                            <?php else: ?>
                                                <em>System Applied</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($penalty['program_name']): ?>
                                                <?= htmlspecialchars($penalty['program_name']) ?><br>
                                                <small><?= $penalty['program_code'] ?></small>
                                            <?php else: ?>
                                                <em>N/A</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($penalty['penalty_type'] === 'late_fee'): ?>
                                                <span class="penalty-type-badge badge-late-fee">Late Fee</span>
                                            <?php elseif ($penalty['penalty_type'] === 'administrative'): ?>
                                                <span class="penalty-type-badge badge-administrative">Administrative</span>
                                            <?php else: ?>
                                                <span class="penalty-type-badge badge-other">Other</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount">
                                            ₦<?= number_format($penalty['penalty_amount'], 2) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(substr($penalty['reason'], 0, 50)) ?>
                                            <?= strlen($penalty['reason']) > 50 ? '...' : '' ?>
                                        </td>
                                        <td>
                                            <?php if ($penalty['applied_by'] == $_SESSION['user_id']): ?>
                                                <strong>You</strong>
                                            <?php else: ?>
                                                System
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('M d, Y', strtotime($penalty['applied_at'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($penalty['waived']): ?>
                                                <span class="status-badge status-waived">Waived</span>
                                                <?php if ($penalty['waived_by_first']): ?>
                                                    <br><small>By: <?= $penalty['waived_by_first'] . ' ' . $penalty['waived_by_last'] ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="status-badge status-active">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if (!$penalty['waived'] && $penalty['student_id']): ?>
                                                    <button onclick="openWaiveModal(<?= $penalty['id'] ?>, '<?= htmlspecialchars($penalty['first_name'] . ' ' . $penalty['last_name']) ?>', <?= $penalty['penalty_amount'] ?>)"
                                                        class="btn btn-sm btn-success" title="Waive Penalty">
                                                        <i class="fas fa-hand-holding-usd"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="../students/view.php?id=<?= $penalty['student_id'] ?>"
                                                    class="btn btn-sm btn-info" title="View Student">
                                                    <i class="fas fa-user"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem;">
                                        <p>No penalty history found.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Apply Penalty Modal -->
    <div class="modal" id="applyPenaltyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Apply Manual Penalty</h3>
                <button class="close-btn" onclick="closeModal('applyPenaltyModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="apply_penalty">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Student ID</label>
                        <input type="number" name="student_id" class="form-control" required
                            placeholder="Enter student ID">
                    </div>

                    <div class="form-group">
                        <label>Penalty Type</label>
                        <select name="penalty_type" id="penalty_type" class="form-control" required onchange="updatePenaltyAmount()">
                            <option value="late_fee">Late Fee</option>
                            <option value="administrative">Administrative Fee</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Penalty Amount (₦)</label>
                        <input type="number" id="penalty_amount" name="penalty_amount" class="form-control"
                            step="0.01" min="0" required placeholder="Enter penalty amount">
                    </div>

                    <div class="form-group">
                        <label>Program (Optional)</label>
                        <select name="program_id" class="form-control">
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= $program['id'] ?>">
                                    <?= htmlspecialchars($program['name']) ?> (<?= $program['program_code'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Fee Structure (Optional)</label>
                        <select name="fee_structure_id" class="form-control">
                            <option value="">Select Fee Structure</option>
                            <?php foreach ($fee_structures as $structure): ?>
                                <option value="<?= $structure['id'] ?>">
                                    <?= htmlspecialchars($structure['program_name']) ?> - <?= htmlspecialchars($structure['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Reason for Penalty</label>
                        <textarea name="reason" class="form-control" rows="4" required
                            placeholder="Explain the reason for this penalty..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('applyPenaltyModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Apply Penalty</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Waive Penalty Modal -->
    <div class="modal" id="waiveModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Waive Penalty</h3>
                <button class="close-btn" onclick="closeModal('waiveModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="waive_penalty">
                <input type="hidden" name="penalty_id" id="waivePenaltyId">
                <div class="modal-body">
                    <div class="form-group">
                        <h4 id="waiveStudentInfo"></h4>
                    </div>

                    <div class="form-group">
                        <label>Reason for Waiver</label>
                        <textarea name="waiver_reason" class="form-control" rows="4" required
                            placeholder="Explain why this penalty is being waived..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('waiveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Waive Penalty</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Initialize the late fee calculator
        calculateLateFee();
    </script>
</body>

</html>
<?php $conn->close(); ?>