<?php
// modules/admin/finance/settings/automation.php

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

// Get current penalty settings
$penalty_settings = [];
$program_types = ['online', 'onsite'];

foreach ($program_types as $type) {
    $stmt = $conn->prepare("SELECT * FROM penalty_settings WHERE program_type = ?");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $result = $stmt->get_result();
    $penalty_settings[$type] = $result->fetch_assoc();
    if (!$penalty_settings[$type]) {
        // Create default settings if not exists
        $penalty_settings[$type] = [
            'program_type' => $type,
            'grace_period_days' => 7,
            'late_fee_percentage' => 5.00,
            'min_late_fee' => 500.00,
            'max_late_fee' => 5000.00,
            'daily_penalty' => 0.00,
            'suspension_days' => 21,
            'is_active' => 1
        ];
    }
    $stmt->close();
}

// Get payment plan automation settings
$payment_plan_settings = [];
$plan_sql = "SELECT * FROM payment_plans ORDER BY program_type, plan_name";
$plan_result = $conn->query($plan_sql);
while ($row = $plan_result->fetch_assoc()) {
    $payment_plan_settings[] = $row;
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_penalty_settings':
            $program_type = $_POST['program_type'] ?? '';
            $grace_period_days = intval($_POST['grace_period_days'] ?? 7);
            $late_fee_percentage = floatval($_POST['late_fee_percentage'] ?? 5.00);
            $min_late_fee = floatval($_POST['min_late_fee'] ?? 500.00);
            $max_late_fee = floatval($_POST['max_late_fee'] ?? 5000.00);
            $daily_penalty = floatval($_POST['daily_penalty'] ?? 0.00);
            $suspension_days = intval($_POST['suspension_days'] ?? 21);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            // Check if exists
            $check_stmt = $conn->prepare("SELECT id FROM penalty_settings WHERE program_type = ?");
            $check_stmt->bind_param("s", $program_type);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if ($exists) {
                // Update
                $stmt = $conn->prepare("UPDATE penalty_settings SET 
                    grace_period_days = ?, late_fee_percentage = ?, min_late_fee = ?, max_late_fee = ?,
                    daily_penalty = ?, suspension_days = ?, is_active = ?, updated_at = NOW()
                    WHERE program_type = ?");
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
                // Insert
                $stmt = $conn->prepare("INSERT INTO penalty_settings 
                    (program_type, grace_period_days, late_fee_percentage, min_late_fee, max_late_fee,
                     daily_penalty, suspension_days, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
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
                $message = "Penalty settings for {$program_type} programs saved successfully";
                $message_type = 'success';
                logActivity(
                    $_SESSION['user_id'],
                    'penalty_settings_update',
                    "Updated penalty settings for {$program_type} programs"
                );
            } else {
                $message = "Error saving penalty settings: " . $conn->error;
                $message_type = 'danger';
            }
            $stmt->close();
            break;

        case 'save_automation_rules':
            $auto_reminders = isset($_POST['auto_reminders']) ? 1 : 0;
            $reminder_days = $_POST['reminder_days'] ?? [];
            $auto_suspension = isset($_POST['auto_suspension']) ? 1 : 0;
            $auto_late_fees = isset($_POST['auto_late_fees']) ? 1 : 0;
            $auto_invoices = isset($_POST['auto_invoices']) ? 1 : 0;
            $block_auto_progression = isset($_POST['block_auto_progression']) ? 1 : 0;

            // Save to system_settings
            $rules = [
                'auto_reminders' => $auto_reminders,
                'reminder_days' => $reminder_days,
                'auto_suspension' => $auto_suspension,
                'auto_late_fees' => $auto_late_fees,
                'auto_invoices' => $auto_invoices,
                'block_auto_progression' => $block_auto_progression
            ];

            $json_rules = json_encode($rules);
            $setting_key = 'finance_automation_rules';

            // Check if exists
            $check_stmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $check_stmt->bind_param("s", $setting_key);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
                $stmt->bind_param("ss", $json_rules, $setting_key);
            } else {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, data_type, created_at, updated_at)
                                       VALUES (?, ?, 'finance', 'json', NOW(), NOW())");
                $stmt->bind_param("ss", $setting_key, $json_rules);
            }

            if ($stmt->execute()) {
                $message = "Automation rules saved successfully";
                $message_type = 'success';
                logActivity($_SESSION['user_id'], 'automation_rules_update', "Updated finance automation rules");
            } else {
                $message = "Error saving automation rules: " . $conn->error;
                $message_type = 'danger';
            }
            $stmt->close();
            break;
    }
}

// Get automation rules
$automation_rules = [
    'auto_reminders' => 1,
    'reminder_days' => [3, 7, 14],
    'auto_suspension' => 1,
    'auto_late_fees' => 1,
    'auto_invoices' => 1,
    'block_auto_progression' => 1
];

$rules_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'finance_automation_rules'");
$rules_stmt->execute();
$rules_result = $rules_stmt->get_result();
if ($row = $rules_result->fetch_assoc()) {
    $automation_rules = json_decode($row['setting_value'], true) ?: $automation_rules;
}
$rules_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation Rules - Finance Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
            --sidebar: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: var(--sidebar);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
            background: var(--light);
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid #334155;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            color: white;
            margin-bottom: 0.5rem;
        }

        .sidebar-nav ul {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-nav li {
            margin-bottom: 0.25rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid var(--primary);
        }

        .sidebar-nav i {
            width: 24px;
            margin-right: 0.75rem;
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

        .header h1 i {
            color: var(--primary);
        }

        .content-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            color: var(--dark);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
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

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
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

        .tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s;
        }

        .tab:hover {
            color: var(--primary);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .rule-item {
            background: #f8fafc;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .rule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .rule-title {
            font-weight: 600;
            color: var(--dark);
        }

        .rule-status {
            font-size: 0.85rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .rule-description {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .badge-online {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-onsite {
            background: #dcfce7;
            color: #166534;
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

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Automation Rules</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/">
                            <i class="fas fa-cog"></i> Finance Settings</a></li>

                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/payment_gateways.php">
                            <i class="fas fa-credit-card"></i> Payment Gateways</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/tax_settings.php">
                            <i class="fas fa-percentage"></i> Tax Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/automation.php" class="active">
                            <i class="fas fa-robot"></i> Automation Rules</a></li>

                    <li><a href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>
                    <i class="fas fa-robot"></i>
                    Automation Rules
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Settings
                    </a>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('penalty')">Penalty Settings</button>
                <button class="tab" onclick="switchTab('automation')">Automation Rules</button>
                <button class="tab" onclick="switchTab('cron')">Cron Jobs</button>
            </div>

            <!-- Penalty Settings Tab -->
            <div id="penalty-tab" class="tab-content active">
                <div class="grid-2">
                    <!-- Online Programs Penalty -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-laptop"></i> Online Programs</h3>
                            <span class="rule-status <?php echo $penalty_settings['online']['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $penalty_settings['online']['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="save_penalty_settings">
                                <input type="hidden" name="program_type" value="online">

                                <div class="form-group">
                                    <label>Grace Period (Days)</label>
                                    <input type="number" name="grace_period_days" class="form-control"
                                        value="<?php echo $penalty_settings['online']['grace_period_days']; ?>"
                                        min="0" max="30">
                                    <small style="color: #64748b;">Days after due date before late fees apply</small>
                                </div>

                                <div class="form-group">
                                    <label>Late Fee Percentage</label>
                                    <input type="number" name="late_fee_percentage" class="form-control"
                                        value="<?php echo $penalty_settings['online']['late_fee_percentage']; ?>"
                                        min="0" max="100" step="0.01">
                                    <small style="color: #64748b;">Percentage of outstanding amount charged as late fee</small>
                                </div>

                                <div class="form-group">
                                    <label>Minimum Late Fee (₦)</label>
                                    <input type="number" name="min_late_fee" class="form-control"
                                        value="<?php echo $penalty_settings['online']['min_late_fee']; ?>"
                                        min="0" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Maximum Late Fee (₦)</label>
                                    <input type="number" name="max_late_fee" class="form-control"
                                        value="<?php echo $penalty_settings['online']['max_late_fee']; ?>"
                                        min="0" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Daily Penalty (₦ per day)</label>
                                    <input type="number" name="daily_penalty" class="form-control"
                                        value="<?php echo $penalty_settings['online']['daily_penalty']; ?>"
                                        min="0" step="0.01">
                                    <small style="color: #64748b;">Additional daily penalty after grace period</small>
                                </div>

                                <div class="form-group">
                                    <label>Auto-Suspension After (Days)</label>
                                    <input type="number" name="suspension_days" class="form-control"
                                        value="<?php echo $penalty_settings['online']['suspension_days']; ?>"
                                        min="1" max="90">
                                    <small style="color: #64748b;">Days overdue before automatic suspension</small>
                                </div>

                                <div class="checkbox-group">
                                    <input type="checkbox" id="online_active" name="is_active"
                                        <?php echo $penalty_settings['online']['is_active'] ? 'checked' : ''; ?>>
                                    <label for="online_active">Enable penalty system for online programs</label>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Online Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Onsite Programs Penalty -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-building"></i> Onsite Programs</h3>
                            <span class="rule-status <?php echo $penalty_settings['onsite']['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $penalty_settings['onsite']['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="save_penalty_settings">
                                <input type="hidden" name="program_type" value="onsite">

                                <div class="form-group">
                                    <label>Grace Period (Days)</label>
                                    <input type="number" name="grace_period_days" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['grace_period_days']; ?>"
                                        min="0" max="30">
                                </div>

                                <div class="form-group">
                                    <label>Late Fee Percentage</label>
                                    <input type="number" name="late_fee_percentage" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['late_fee_percentage']; ?>"
                                        min="0" max="100" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Minimum Late Fee (₦)</label>
                                    <input type="number" name="min_late_fee" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['min_late_fee']; ?>"
                                        min="0" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Maximum Late Fee (₦)</label>
                                    <input type="number" name="max_late_fee" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['max_late_fee']; ?>"
                                        min="0" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Daily Penalty (₦ per day)</label>
                                    <input type="number" name="daily_penalty" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['daily_penalty']; ?>"
                                        min="0" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Auto-Suspension After (Days)</label>
                                    <input type="number" name="suspension_days" class="form-control"
                                        value="<?php echo $penalty_settings['onsite']['suspension_days']; ?>"
                                        min="1" max="90">
                                </div>

                                <div class="checkbox-group">
                                    <input type="checkbox" id="onsite_active" name="is_active"
                                        <?php echo $penalty_settings['onsite']['is_active'] ? 'checked' : ''; ?>>
                                    <label for="onsite_active">Enable penalty system for onsite programs</label>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Onsite Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Penalty Calculation Preview -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calculator"></i> Penalty Calculation Preview</h3>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                            <div>
                                <h4 style="margin-bottom: 1rem; color: var(--dark);">Online Program Example</h4>
                                <div style="color: #64748b; line-height: 1.6;">
                                    <p>Outstanding: ₦100,000</p>
                                    <p>Grace Period: <?php echo $penalty_settings['online']['grace_period_days']; ?> days</p>
                                    <p>Late Fee: <?php echo $penalty_settings['online']['late_fee_percentage']; ?>% (₦5,000 - ₦<?php echo $penalty_settings['online']['max_late_fee']; ?>)</p>
                                    <p>Suspension: After <?php echo $penalty_settings['online']['suspension_days']; ?> days overdue</p>
                                </div>
                            </div>
                            <div>
                                <h4 style="margin-bottom: 1rem; color: var(--dark);">Onsite Program Example</h4>
                                <div style="color: #64748b; line-height: 1.6;">
                                    <p>Outstanding: ₦100,000</p>
                                    <p>Grace Period: <?php echo $penalty_settings['onsite']['grace_period_days']; ?> days</p>
                                    <p>Late Fee: <?php echo $penalty_settings['onsite']['late_fee_percentage']; ?>% (₦5,000 - ₦<?php echo $penalty_settings['onsite']['max_late_fee']; ?>)</p>
                                    <p>Suspension: After <?php echo $penalty_settings['onsite']['suspension_days']; ?> days overdue</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Automation Rules Tab -->
            <div id="automation-tab" class="tab-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_automation_rules">

                    <!-- Payment Reminders -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-bell"></i> Payment Reminders</h3>
                            <div class="checkbox-group">
                                <input type="checkbox" id="auto_reminders" name="auto_reminders"
                                    <?php echo $automation_rules['auto_reminders'] ? 'checked' : ''; ?>>
                                <label for="auto_reminders">Enable Automated Reminders</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Send reminders before due date (days):</label>
                                <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem;">
                                    <?php for ($i = 1; $i <= 14; $i++): ?>
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="reminder_day_<?php echo $i; ?>"
                                                name="reminder_days[]" value="<?php echo $i; ?>"
                                                <?php echo in_array($i, $automation_rules['reminder_days']) ? 'checked' : ''; ?>>
                                            <label for="reminder_day_<?php echo $i; ?>"><?php echo $i; ?> day<?php echo $i > 1 ? 's' : ''; ?></label>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <small style="color: #64748b;">Select days before due date to send payment reminders</small>
                            </div>
                        </div>
                    </div>

                    <!-- Auto-Suspension Rules -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-slash"></i> Auto-Suspension Rules</h3>
                            <div class="checkbox-group">
                                <input type="checkbox" id="auto_suspension" name="auto_suspension"
                                    <?php echo $automation_rules['auto_suspension'] ? 'checked' : ''; ?>>
                                <label for="auto_suspension">Enable Auto-Suspension</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="rule-item">
                                <div class="rule-header">
                                    <div class="rule-title">Payment Overdue Suspension</div>
                                    <span class="rule-status status-active">Active</span>
                                </div>
                                <div class="rule-description">
                                    Automatically suspend students when they are <?php echo max($penalty_settings['online']['suspension_days'], $penalty_settings['onsite']['suspension_days']); ?> days overdue on payments.
                                </div>
                                <div>
                                    <span class="badge badge-online">Online Programs</span>
                                    <span class="badge badge-onsite">Onsite Programs</span>
                                </div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-header">
                                    <div class="rule-title">Block Progression Block</div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="block_auto_progression" name="block_auto_progression"
                                            <?php echo $automation_rules['block_auto_progression'] ? 'checked' : ''; ?>>
                                        <label for="block_auto_progression">Enable</label>
                                    </div>
                                </div>
                                <div class="rule-description">
                                    Prevent students from progressing to next block/term if they have outstanding fees for current block.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Auto-Fee Calculations -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-calculator"></i> Auto-Fee Calculations</h3>
                        </div>
                        <div class="card-body">
                            <div class="rule-item">
                                <div class="rule-header">
                                    <div class="rule-title">Automatic Late Fees</div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="auto_late_fees" name="auto_late_fees"
                                            <?php echo $automation_rules['auto_late_fees'] ? 'checked' : ''; ?>>
                                        <label for="auto_late_fees">Enable</label>
                                    </div>
                                </div>
                                <div class="rule-description">
                                    Automatically calculate and apply late fees when payments are overdue beyond grace period.
                                </div>
                            </div>

                            <div class="rule-item">
                                <div class="rule-header">
                                    <div class="rule-title">Automatic Invoice Generation</div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="auto_invoices" name="auto_invoices"
                                            <?php echo $automation_rules['auto_invoices'] ? 'checked' : ''; ?>>
                                        <label for="auto_invoices">Enable</label>
                                    </div>
                                </div>
                                <div class="rule-description">
                                    Automatically generate invoices for upcoming payment blocks/installments.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="text-align: center; margin-top: 2rem;">
                        <button type="submit" class="btn btn-primary" style="padding: 1rem 3rem; font-size: 1.1rem;">
                            <i class="fas fa-save"></i> Save All Automation Rules
                        </button>
                    </div>
                </form>
            </div>

            <!-- Cron Jobs Tab -->
            <div id="cron-tab" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Scheduled Tasks (Cron Jobs)</h3>
                    </div>
                    <div class="card-body">
                        <div class="rule-item">
                            <div class="rule-header">
                                <div class="rule-title">Payment Reminders</div>
                                <span class="rule-status status-active">Daily at 9:00 AM</span>
                            </div>
                            <div class="rule-description">
                                Sends payment reminder emails/SMS to students with upcoming or overdue payments.
                            </div>
                            <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <code style="color: var(--dark);">0 9 * * * php <?php echo dirname(__DIR__, 4); ?>/cron/payment_reminders.php</code>
                            </div>
                        </div>

                        <div class="rule-item">
                            <div class="rule-header">
                                <div class="rule-title">Auto-Suspension Check</div>
                                <span class="rule-status status-active">Daily at 10:00 AM</span>
                            </div>
                            <div class="rule-description">
                                Checks for students who should be suspended due to overdue payments and updates their status.
                            </div>
                            <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <code style="color: var(--dark);">0 10 * * * php <?php echo dirname(__DIR__, 4); ?>/cron/auto_suspension.php</code>
                            </div>
                        </div>

                        <div class="rule-item">
                            <div class="rule-header">
                                <div class="rule-title">Late Fee Calculation</div>
                                <span class="rule-status status-active">Daily at 11:00 AM</span>
                            </div>
                            <div class="rule-description">
                                Calculates and applies late fees for overdue payments.
                            </div>
                            <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <code style="color: var(--dark);">0 11 * * * php <?php echo dirname(__DIR__, 4); ?>/cron/fee_calculation.php</code>
                            </div>
                        </div>

                        <div class="rule-item">
                            <div class="rule-header">
                                <div class="rule-title">Block Progression Check</div>
                                <span class="rule-status status-active">Weekly on Monday at 8:00 AM</span>
                            </div>
                            <div class="rule-description">
                                Checks if students are eligible to progress to next block/term based on payment status.
                            </div>
                            <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <code style="color: var(--dark);">0 8 * * 1 php <?php echo dirname(__DIR__, 4); ?>/cron/block_progression_check.php</code>
                            </div>
                        </div>

                        <div class="rule-item">
                            <div class="rule-header">
                                <div class="rule-title">Financial Reports Generation</div>
                                <span class="rule-status status-active">Monthly on 1st at 6:00 AM</span>
                            </div>
                            <div class="rule-description">
                                Generates monthly financial reports and sends to administrators.
                            </div>
                            <div style="background: #f1f5f9; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                <code style="color: var(--dark);">0 6 1 * * php <?php echo dirname(__DIR__, 4); ?>/cron/financial_reports.php</code>
                            </div>
                        </div>

                        <div style="margin-top: 2rem; padding: 1.5rem; background: #f8fafc; border-radius: 8px;">
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">Cron Configuration Instructions</h4>
                            <ol style="color: #64748b; line-height: 1.6; margin-left: 1.5rem;">
                                <li>Open your server's crontab: <code>crontab -e</code></li>
                                <li>Add the cron commands shown above</li>
                                <li>Replace the PHP path with your server's PHP executable path</li>
                                <li>Ensure the cron user has permission to execute the scripts</li>
                                <li>Test each cron job manually before relying on automation</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
        }

        // Enable/disable reminder days based on checkbox
        document.getElementById('auto_reminders').addEventListener('change', function() {
            const reminderDays = document.querySelectorAll('input[name="reminder_days[]"]');
            reminderDays.forEach(checkbox => {
                checkbox.disabled = !this.checked;
            });
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            const autoReminders = document.getElementById('auto_reminders');
            const reminderDays = document.querySelectorAll('input[name="reminder_days[]"]');
            reminderDays.forEach(checkbox => {
                checkbox.disabled = !autoReminders.checked;
            });
        });

        // Test automation rule
        function testRule(ruleType) {
            if (confirm('Run test for ' + ruleType + '?')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/settings/test_automation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'rule_type=' + ruleType
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Test completed: ' + data.message);
                        } else {
                            alert('Test failed: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error);
                    });
            }
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>