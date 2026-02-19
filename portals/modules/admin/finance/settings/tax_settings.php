<?php
// modules/admin/finance/settings/tax_settings.php

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

// Get tax settings
$tax_settings = [];
$tax_sql = "SELECT * FROM system_settings WHERE setting_key LIKE 'tax_%'";
$tax_result = $conn->query($tax_sql);
while ($row = $tax_result->fetch_assoc()) {
    $tax_settings[$row['setting_key']] = json_decode($row['setting_value'], true) ?? $row['setting_value'];
}

// Default tax settings if not exists
$default_tax_settings = [
    'tax_enabled' => 0,
    'tax_rate' => 7.5, // VAT rate in Nigeria
    'tax_name' => 'VAT',
    'tax_number' => '',
    'tax_inclusive' => 1, // 1 = inclusive, 0 = exclusive
    'tax_exempt_programs' => [],
    'tax_exempt_student_types' => [],
    'tax_country' => 'Nigeria',
    'tax_states' => [],
    'tax_threshold' => 2500000, // Annual turnover threshold
    'tax_calculation_method' => 'percentage', // percentage or fixed
    'tax_items' => [
        ['name' => 'Tuition Fees', 'taxable' => 1, 'rate' => 7.5],
        ['name' => 'Registration Fees', 'taxable' => 1, 'rate' => 7.5],
        ['name' => 'Late Fees', 'taxable' => 1, 'rate' => 7.5],
        ['name' => 'Certificate Fees', 'taxable' => 0, 'rate' => 0],
        ['name' => 'Materials Fees', 'taxable' => 0, 'rate' => 0]
    ]
];

// Merge with defaults
foreach ($default_tax_settings as $key => $value) {
    if (!isset($tax_settings['tax_' . $key])) {
        $tax_settings['tax_' . $key] = $value;
    }
}

// Get programs for tax exemption
$programs_sql = "SELECT id, program_code, name, program_type FROM programs WHERE status = 'active'";
$programs_result = $conn->query($programs_sql);
$all_programs = $programs_result->fetch_all(MYSQLI_ASSOC);

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_tax_settings':
            $tax_enabled = isset($_POST['tax_enabled']) ? 1 : 0;
            $tax_rate = floatval($_POST['tax_rate'] ?? 7.5);
            $tax_name = $_POST['tax_name'] ?? 'VAT';
            $tax_number = $_POST['tax_number'] ?? '';
            $tax_inclusive = isset($_POST['tax_inclusive']) ? 1 : 0;
            $tax_country = $_POST['tax_country'] ?? 'Nigeria';
            $tax_threshold = floatval($_POST['tax_threshold'] ?? 2500000);
            $tax_calculation_method = $_POST['tax_calculation_method'] ?? 'percentage';

            // Tax exemptions
            $tax_exempt_programs = $_POST['tax_exempt_programs'] ?? [];
            $tax_exempt_student_types = $_POST['tax_exempt_student_types'] ?? [];

            // State taxes
            $tax_states = [];
            if (isset($_POST['state_names']) && isset($_POST['state_rates'])) {
                $state_names = $_POST['state_names'];
                $state_rates = $_POST['state_rates'];
                for ($i = 0; $i < count($state_names); $i++) {
                    if (!empty($state_names[$i]) && is_numeric($state_rates[$i])) {
                        $tax_states[] = [
                            'name' => $state_names[$i],
                            'rate' => floatval($state_rates[$i]),
                            'code' => $_POST['state_codes'][$i] ?? ''
                        ];
                    }
                }
            }

            // Tax items
            $tax_items = [];
            if (isset($_POST['item_names']) && isset($_POST['item_taxable']) && isset($_POST['item_rates'])) {
                $item_names = $_POST['item_names'];
                $item_taxable = $_POST['item_taxable'];
                $item_rates = $_POST['item_rates'];
                for ($i = 0; $i < count($item_names); $i++) {
                    if (!empty($item_names[$i])) {
                        $tax_items[] = [
                            'name' => $item_names[$i],
                            'taxable' => isset($item_taxable[$i]) ? 1 : 0,
                            'rate' => floatval($item_rates[$i] ?? $tax_rate),
                            'id' => $_POST['item_ids'][$i] ?? $i
                        ];
                    }
                }
            }

            // Save all tax settings
            $tax_settings_to_save = [
                'tax_enabled' => $tax_enabled,
                'tax_rate' => $tax_rate,
                'tax_name' => $tax_name,
                'tax_number' => $tax_number,
                'tax_inclusive' => $tax_inclusive,
                'tax_exempt_programs' => $tax_exempt_programs,
                'tax_exempt_student_types' => $tax_exempt_student_types,
                'tax_country' => $tax_country,
                'tax_states' => $tax_states,
                'tax_threshold' => $tax_threshold,
                'tax_calculation_method' => $tax_calculation_method,
                'tax_items' => $tax_items
            ];

            // Start transaction
            $conn->begin_transaction();

            try {
                foreach ($tax_settings_to_save as $key => $value) {
                    $setting_value = is_array($value) ? json_encode($value) : $value;
                    $data_type = is_array($value) ? 'json' : 'string';

                    // Check if exists
                    $check_stmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
                    $check_stmt->bind_param("s", $key);
                    $check_stmt->execute();
                    $exists = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();

                    if ($exists) {
                        $stmt = $conn->prepare("UPDATE system_settings SET 
                            setting_value = ?, data_type = ?, updated_at = NOW()
                            WHERE setting_key = ?");
                        $stmt->bind_param("sss", $setting_value, $data_type, $key);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO system_settings 
                            (setting_key, setting_value, setting_group, data_type, created_at, updated_at)
                            VALUES (?, ?, 'finance', ?, NOW(), NOW())");
                        $stmt->bind_param("sss", $key, $setting_value, $data_type);
                    }

                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
                $message = "Tax settings saved successfully";
                $message_type = 'success';
                logActivity($_SESSION['user_id'], 'tax_settings_update', "Updated tax configuration");

                // Refresh settings
                $tax_result = $conn->query($tax_sql);
                $tax_settings = [];
                while ($row = $tax_result->fetch_assoc()) {
                    $tax_settings[$row['setting_key']] = json_decode($row['setting_value'], true) ?? $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error saving tax settings: " . $e->getMessage();
                $message_type = 'danger';
            }
            break;

        case 'test_tax_calculation':
            $amount = floatval($_POST['test_amount'] ?? 100000);
            $tax_rate = floatval($tax_settings['tax_rate']);
            $tax_inclusive = $tax_settings['tax_inclusive'];

            if ($tax_inclusive) {
                $tax_amount = $amount * ($tax_rate / (100 + $tax_rate));
                $net_amount = $amount - $tax_amount;
            } else {
                $tax_amount = $amount * ($tax_rate / 100);
                $net_amount = $amount;
                $gross_amount = $amount + $tax_amount;
            }

            $message = "Tax Calculation Test:<br>";
            $message .= "Amount: " . formatCurrency($amount) . "<br>";
            $message .= "Tax Rate: " . $tax_rate . "%<br>";
            $message .= "Tax Amount: " . formatCurrency($tax_amount) . "<br>";

            if ($tax_inclusive) {
                $message .= "Net Amount: " . formatCurrency($net_amount) . "<br>";
                $message .= "Gross Amount (including tax): " . formatCurrency($amount);
            } else {
                $message .= "Gross Amount: " . formatCurrency($gross_amount ?? $amount + $tax_amount);
            }

            $message_type = 'info';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Settings - Finance Settings</title>
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

        .radio-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .radio-group input[type="radio"] {
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-info {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
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
            white-space: nowrap;
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

        .tax-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-enabled {
            background: #d1fae5;
            color: #065f46;
        }

        .status-disabled {
            background: #fee2e2;
            color: #991b1b;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
        }

        .tax-preview {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .tax-item-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .state-tax-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
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

        .badge-taxable {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-nontaxable {
            background: #f1f5f9;
            color: #64748b;
        }

        @media (max-width: 1024px) {

            .grid-2,
            .grid-3 {
                grid-template-columns: 1fr;
            }

            .tax-item-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .state-tax-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
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
                <p>Tax Settings</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/">
                            <i class="fas fa-cog"></i> Finance Settings</a></li>

                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/payment_gateways.php">
                            <i class="fas fa-credit-card"></i> Payment Gateways</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/tax_settings.php" class="active">
                            <i class="fas fa-percentage"></i> Tax Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/automation.php">
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
                    <i class="fas fa-percentage"></i>
                    Tax Settings
                </h1>
                <div>
                    <span class="tax-status <?php echo $tax_settings['tax_enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                        <?php echo $tax_settings['tax_enabled'] ? 'Tax Enabled' : 'Tax Disabled'; ?>
                    </span>
                </div>
            </div>

            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo strpos($message, 'Test') === 0 ? 'info' : $message_type; ?>">
                    <i class="fas <?php
                                    if (strpos($message, 'Test') === 0) echo 'fa-calculator';
                                    elseif ($message_type === 'success') echo 'fa-check-circle';
                                    else echo 'fa-exclamation-circle';
                                    ?>"></i>
                    <div style="flex: 1;"><?php echo $message; ?></div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('general')">General Settings</button>
                <button class="tab" onclick="switchTab('exemptions')">Tax Exemptions</button>
                <button class="tab" onclick="switchTab('items')">Taxable Items</button>
                <button class="tab" onclick="switchTab('states')">State Taxes</button>
                <button class="tab" onclick="switchTab('test')">Test & Preview</button>
            </div>

            <!-- General Settings Tab -->
            <div id="general-tab" class="tab-content active">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_tax_settings">

                    <div class="grid-2">
                        <div class="content-card">
                            <div class="card-header">
                                <h3><i class="fas fa-toggle-on"></i> Tax System</h3>
                            </div>
                            <div class="card-body">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="tax_enabled" name="tax_enabled"
                                        <?php echo $tax_settings['tax_enabled'] ? 'checked' : ''; ?>>
                                    <label for="tax_enabled" style="font-weight: 600;">Enable Tax System</label>
                                </div>
                                <small style="color: #64748b; display: block; margin-top: 0.5rem;">
                                    When enabled, taxes will be calculated on all applicable fees.
                                </small>

                                <div class="form-group" style="margin-top: 1.5rem;">
                                    <label for="tax_name">Tax Name *</label>
                                    <input type="text" id="tax_name" name="tax_name" class="form-control"
                                        value="<?php echo htmlspecialchars($tax_settings['tax_name']); ?>"
                                        required placeholder="e.g., VAT, GST, Sales Tax">
                                </div>

                                <div class="form-group">
                                    <label for="tax_number">Tax Registration Number</label>
                                    <input type="text" id="tax_number" name="tax_number" class="form-control"
                                        value="<?php echo htmlspecialchars($tax_settings['tax_number']); ?>"
                                        placeholder="e.g., VAT123456789">
                                </div>

                                <div class="form-group">
                                    <label for="tax_country">Country</label>
                                    <input type="text" id="tax_country" name="tax_country" class="form-control"
                                        value="<?php echo htmlspecialchars($tax_settings['tax_country']); ?>"
                                        required placeholder="e.g., Nigeria">
                                </div>
                            </div>
                        </div>

                        <div class="content-card">
                            <div class="card-header">
                                <h3><i class="fas fa-sliders-h"></i> Tax Configuration</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="tax_rate">Default Tax Rate (%) *</label>
                                    <input type="number" id="tax_rate" name="tax_rate" class="form-control"
                                        value="<?php echo $tax_settings['tax_rate']; ?>"
                                        min="0" max="100" step="0.01" required>
                                    <small style="color: #64748b;">Standard tax rate applied to taxable items</small>
                                </div>

                                <div class="form-group">
                                    <label>Tax Calculation Method</label>
                                    <div class="radio-group">
                                        <input type="radio" id="tax_inclusive_1" name="tax_inclusive" value="1"
                                            <?php echo $tax_settings['tax_inclusive'] ? 'checked' : ''; ?>>
                                        <label for="tax_inclusive_1">Prices include tax (Inclusive)</label>
                                    </div>
                                    <div class="radio-group">
                                        <input type="radio" id="tax_inclusive_0" name="tax_inclusive" value="0"
                                            <?php echo !$tax_settings['tax_inclusive'] ? 'checked' : ''; ?>>
                                        <label for="tax_inclusive_0">Tax added to prices (Exclusive)</label>
                                    </div>
                                    <small style="color: #64748b;">
                                        Inclusive: ₦100,000 includes tax<br>
                                        Exclusive: ₦100,000 + tax = ₦107,500 (with 7.5% tax)
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label for="tax_calculation_method">Calculation Method</label>
                                    <select id="tax_calculation_method" name="tax_calculation_method" class="form-control">
                                        <option value="percentage" <?php echo $tax_settings['tax_calculation_method'] === 'percentage' ? 'selected' : ''; ?>>Percentage of amount</option>
                                        <option value="fixed" <?php echo $tax_settings['tax_calculation_method'] === 'fixed' ? 'selected' : ''; ?>>Fixed amount per item</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="tax_threshold">Annual Turnover Threshold (₦)</label>
                                    <input type="number" id="tax_threshold" name="tax_threshold" class="form-control"
                                        value="<?php echo $tax_settings['tax_threshold']; ?>"
                                        min="0" step="0.01">
                                    <small style="color: #64748b;">Tax registration threshold for the country</small>
                                </div>
                            </div>
                        </div>
                    </div>
            </div>

            <!-- Tax Exemptions Tab -->
            <div id="exemptions-tab" class="tab-content">
                <div class="grid-2">
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-ban"></i> Program Exemptions</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Select programs that are tax-exempt:</label>
                                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1rem;">
                                    <?php foreach ($all_programs as $program): ?>
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="program_<?php echo $program['id']; ?>"
                                                name="tax_exempt_programs[]" value="<?php echo $program['id']; ?>"
                                                <?php echo in_array($program['id'], $tax_settings['tax_exempt_programs']) ? 'checked' : ''; ?>>
                                            <label for="program_<?php echo $program['id']; ?>">
                                                <?php echo htmlspecialchars($program['name']); ?>
                                                <small style="color: #64748b;">(<?php echo $program['program_type']; ?>)</small>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small style="color: #64748b;">Fees for selected programs will not be taxed</small>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-graduate"></i> Student Type Exemptions</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Select student types that are tax-exempt:</label>
                                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 1rem;">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exempt_fulltime" name="tax_exempt_student_types[]" value="fulltime"
                                            <?php echo in_array('fulltime', $tax_settings['tax_exempt_student_types']) ? 'checked' : ''; ?>>
                                        <label for="exempt_fulltime">Full-time Students</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exempt_parttime" name="tax_exempt_student_types[]" value="parttime"
                                            <?php echo in_array('parttime', $tax_settings['tax_exempt_student_types']) ? 'checked' : ''; ?>>
                                        <label for="exempt_parttime">Part-time Students</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exempt_international" name="tax_exempt_student_types[]" value="international"
                                            <?php echo in_array('international', $tax_settings['tax_exempt_student_types']) ? 'checked' : ''; ?>>
                                        <label for="exempt_international">International Students</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exempt_scholarship" name="tax_exempt_student_types[]" value="scholarship"
                                            <?php echo in_array('scholarship', $tax_settings['tax_exempt_student_types']) ? 'checked' : ''; ?>>
                                        <label for="exempt_scholarship">Scholarship Recipients</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="exempt_corporate" name="tax_exempt_student_types[]" value="corporate"
                                            <?php echo in_array('corporate', $tax_settings['tax_exempt_student_types']) ? 'checked' : ''; ?>>
                                        <label for="exempt_corporate">Corporate-sponsored Students</label>
                                    </div>
                                </div>
                                <small style="color: #64748b;">Selected student types will be exempt from taxes</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Taxable Items Tab -->
            <div id="items-tab" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Taxable Fee Items</h3>
                        <button type="button" class="btn btn-sm btn-success" onclick="addTaxItem()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="tax-preview">
                            <p style="color: #64748b; margin-bottom: 1rem;">
                                Configure which fee items are taxable and their tax rates. Items can have different tax rates than the default.
                            </p>

                            <div id="tax-items-container">
                                <?php foreach ($tax_settings['tax_items'] as $index => $item): ?>
                                    <div class="tax-item-row" id="tax-item-<?php echo $index; ?>">
                                        <input type="hidden" name="item_ids[]" value="<?php echo $item['id'] ?? $index; ?>">

                                        <div>
                                            <input type="text" name="item_names[]" class="form-control"
                                                value="<?php echo htmlspecialchars($item['name']); ?>"
                                                placeholder="Item name (e.g., Tuition Fees)" required>
                                        </div>

                                        <div>
                                            <div class="checkbox-group">
                                                <input type="checkbox" id="item_taxable_<?php echo $index; ?>"
                                                    name="item_taxable[]" value="<?php echo $index; ?>"
                                                    <?php echo $item['taxable'] ? 'checked' : ''; ?>>
                                                <label for="item_taxable_<?php echo $index; ?>">Taxable</label>
                                            </div>
                                        </div>

                                        <div>
                                            <input type="number" name="item_rates[]" class="form-control"
                                                value="<?php echo $item['rate']; ?>"
                                                min="0" max="100" step="0.01"
                                                placeholder="Tax rate %">
                                        </div>

                                        <div>
                                            <button type="button" class="btn btn-sm btn-danger"
                                                onclick="removeTaxItem(<?php echo $index; ?>)"
                                                <?php echo count($tax_settings['tax_items']) <= 1 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top: 1.5rem;">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="apply_default_rate" checked>
                                    <label for="apply_default_rate">Apply default tax rate to all items when adding new items</label>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 1.5rem;">
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">Current Configuration</h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                <?php foreach ($tax_settings['tax_items'] as $item): ?>
                                    <span class="badge <?php echo $item['taxable'] ? 'badge-taxable' : 'badge-nontaxable'; ?>">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                        <?php if ($item['taxable']): ?>
                                            (<?php echo $item['rate']; ?>%)
                                        <?php else: ?>
                                            (Non-taxable)
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- State Taxes Tab -->
            <div id="states-tab" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-map-marker-alt"></i> State/Regional Taxes</h3>
                        <button type="button" class="btn btn-sm btn-success" onclick="addStateTax()">
                            <i class="fas fa-plus"></i> Add State
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Configure additional state or regional taxes that apply in specific locations.
                        </div>

                        <div id="state-taxes-container">
                            <?php foreach ($tax_settings['tax_states'] as $index => $state): ?>
                                <div class="state-tax-row" id="state-tax-<?php echo $index; ?>">
                                    <div>
                                        <input type="text" name="state_names[]" class="form-control"
                                            value="<?php echo htmlspecialchars($state['name']); ?>"
                                            placeholder="State/Region name" required>
                                    </div>

                                    <div>
                                        <input type="text" name="state_codes[]" class="form-control"
                                            value="<?php echo htmlspecialchars($state['code'] ?? ''); ?>"
                                            placeholder="State code (e.g., LA)">
                                    </div>

                                    <div>
                                        <input type="number" name="state_rates[]" class="form-control"
                                            value="<?php echo $state['rate']; ?>"
                                            min="0" max="100" step="0.01"
                                            placeholder="Tax rate %" required>
                                    </div>

                                    <div>
                                        <button type="button" class="btn btn-sm btn-danger"
                                            onclick="removeStateTax(<?php echo $index; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($tax_settings['tax_states'])): ?>
                                <div class="state-tax-row" id="state-tax-0">
                                    <div>
                                        <input type="text" name="state_names[]" class="form-control"
                                            placeholder="State/Region name" required>
                                    </div>

                                    <div>
                                        <input type="text" name="state_codes[]" class="form-control"
                                            placeholder="State code (e.g., LA)">
                                    </div>

                                    <div>
                                        <input type="number" name="state_rates[]" class="form-control"
                                            value="0" min="0" max="100" step="0.01"
                                            placeholder="Tax rate %" required>
                                    </div>

                                    <div>
                                        <button type="button" class="btn btn-sm btn-danger"
                                            onclick="removeStateTax(0)" disabled>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tax-preview" style="margin-top: 2rem;">
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">State Tax Reference</h4>
                            <div style="color: #64748b; line-height: 1.6;">
                                <p><strong>Nigeria VAT:</strong> Standard rate is 7.5% nationwide (as of 2023)</p>
                                <p><strong>State Taxes:</strong> Some states may have additional levies or reduced rates for education</p>
                                <p><strong>Educational Exemptions:</strong> Tuition fees for approved institutions are often VAT-exempt</p>
                                <p><strong>Registration:</strong> Ensure compliance with FIRS (Federal Inland Revenue Service) regulations</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test & Preview Tab -->
            <div id="test-tab" class="tab-content">
                <div class="grid-2">
                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-calculator"></i> Tax Calculator</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="test_tax_calculation">

                                <div class="form-group">
                                    <label for="test_amount">Test Amount (₦)</label>
                                    <input type="number" id="test_amount" name="test_amount" class="form-control"
                                        value="100000" min="0" step="0.01" required>
                                </div>

                                <div class="form-group">
                                    <label>Tax Settings Applied:</label>
                                    <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; margin-top: 0.5rem;">
                                        <div style="color: #64748b; line-height: 1.6;">
                                            <p><strong>Tax Rate:</strong> <?php echo $tax_settings['tax_rate']; ?>%</p>
                                            <p><strong>Calculation:</strong> <?php echo $tax_settings['tax_inclusive'] ? 'Inclusive' : 'Exclusive'; ?></p>
                                            <p><strong>Method:</strong> <?php echo ucfirst($tax_settings['tax_calculation_method']); ?></p>
                                            <p><strong>Status:</strong> <?php echo $tax_settings['tax_enabled'] ? 'Enabled' : 'Disabled'; ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-calculator"></i> Calculate Tax
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h3><i class="fas fa-receipt"></i> Invoice Preview</h3>
                        </div>
                        <div class="card-body">
                            <div style="border: 2px dashed #e2e8f0; border-radius: 8px; padding: 1.5rem;">
                                <div style="text-align: center; margin-bottom: 1.5rem;">
                                    <h4 style="color: var(--dark);">Sample Invoice</h4>
                                    <p style="color: #64748b;">Tax calculation preview</p>
                                </div>

                                <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span style="color: #64748b;">Tuition Fee:</span>
                                        <span style="font-weight: 500;">₦100,000.00</span>
                                    </div>
                                    <?php if ($tax_settings['tax_enabled'] && $tax_settings['tax_inclusive']): ?>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <span style="color: #64748b;">Tax (<?php echo $tax_settings['tax_rate']; ?>%):</span>
                                            <span style="font-weight: 500;">-₦<?php
                                                                                $tax_amount = 100000 * ($tax_settings['tax_rate'] / (100 + $tax_settings['tax_rate']));
                                                                                echo number_format($tax_amount, 2);
                                                                                ?></span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; font-weight: 600; padding-top: 0.5rem; border-top: 1px solid #e2e8f0;">
                                            <span>Net Amount:</span>
                                            <span>₦<?php echo number_format(100000 - $tax_amount, 2); ?></span>
                                        </div>
                                    <?php elseif ($tax_settings['tax_enabled'] && !$tax_settings['tax_inclusive']): ?>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <span style="color: #64748b;">Tax (<?php echo $tax_settings['tax_rate']; ?>%):</span>
                                            <span style="font-weight: 500;">+₦<?php
                                                                                $tax_amount = 100000 * ($tax_settings['tax_rate'] / 100);
                                                                                echo number_format($tax_amount, 2);
                                                                                ?></span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; font-weight: 600; padding-top: 0.5rem; border-top: 1px solid #e2e8f0;">
                                            <span>Total Amount:</span>
                                            <span>₦<?php echo number_format(100000 + $tax_amount, 2); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div style="display: flex; justify-content: space-between; font-weight: 600; padding-top: 0.5rem; border-top: 1px solid #e2e8f0;">
                                            <span>Total Amount:</span>
                                            <span>₦100,000.00</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="color: #64748b; font-size: 0.9rem; line-height: 1.5;">
                                    <p><strong>Note:</strong> This is a preview. Actual calculations will apply based on item taxability and student exemptions.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-invoice"></i> Tax Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid-3">
                            <div style="text-align: center; padding: 1.5rem; background: #f8fafc; border-radius: 8px;">
                                <div style="font-size: 2rem; color: var(--primary); margin-bottom: 0.5rem;">
                                    <?php echo $tax_settings['tax_rate']; ?>%
                                </div>
                                <div style="color: #64748b; font-weight: 500;">Default Tax Rate</div>
                            </div>

                            <div style="text-align: center; padding: 1.5rem; background: #f8fafc; border-radius: 8px;">
                                <div style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;">
                                    <?php echo count($tax_settings['tax_items']); ?>
                                </div>
                                <div style="color: #64748b; font-weight: 500;">Taxable Items</div>
                            </div>

                            <div style="text-align: center; padding: 1.5rem; background: #f8fafc; border-radius: 8px;">
                                <div style="font-size: 2rem; color: var(--warning); margin-bottom: 0.5rem;">
                                    <?php echo count($tax_settings['tax_exempt_programs']); ?>
                                </div>
                                <div style="color: #64748b; font-weight: 500;">Exempt Programs</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <div class="content-card">
                <div class="card-body" style="text-align: center;">
                    <button type="submit" class="btn btn-primary" style="padding: 1rem 3rem; font-size: 1.1rem;">
                        <i class="fas fa-save"></i> Save All Tax Settings
                    </button>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/" class="btn btn-secondary" style="margin-left: 1rem;">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </div>
            </form>
        </div>
    </div>

    <script>
        let taxItemCounter = <?php echo count($tax_settings['tax_items']); ?>;
        let stateTaxCounter = <?php echo count($tax_settings['tax_states']); ?>;

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

        function addTaxItem() {
            const container = document.getElementById('tax-items-container');
            const defaultRate = document.getElementById('tax_rate').value;
            const applyDefault = document.getElementById('apply_default_rate').checked;

            const newItem = document.createElement('div');
            newItem.className = 'tax-item-row';
            newItem.id = 'tax-item-' + taxItemCounter;

            newItem.innerHTML = `
                <input type="hidden" name="item_ids[]" value="${taxItemCounter}">
                
                <div>
                    <input type="text" name="item_names[]" class="form-control"
                           placeholder="Item name (e.g., Tuition Fees)" required>
                </div>
                
                <div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="item_taxable_${taxItemCounter}" 
                               name="item_taxable[]" value="${taxItemCounter}" checked>
                        <label for="item_taxable_${taxItemCounter}">Taxable</label>
                    </div>
                </div>
                
                <div>
                    <input type="number" name="item_rates[]" class="form-control"
                           value="${applyDefault ? defaultRate : 0}"
                           min="0" max="100" step="0.01"
                           placeholder="Tax rate %">
                </div>
                
                <div>
                    <button type="button" class="btn btn-sm btn-danger" 
                            onclick="removeTaxItem(${taxItemCounter})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            container.appendChild(newItem);
            taxItemCounter++;

            // Enable delete buttons if there are multiple items
            if (document.querySelectorAll('.tax-item-row').length > 1) {
                document.querySelectorAll('.tax-item-row .btn-danger').forEach(btn => {
                    btn.disabled = false;
                });
            }
        }

        function removeTaxItem(index) {
            const item = document.getElementById('tax-item-' + index);
            if (item && document.querySelectorAll('.tax-item-row').length > 1) {
                item.remove();

                // Disable delete button if only one item remains
                if (document.querySelectorAll('.tax-item-row').length === 1) {
                    document.querySelector('.tax-item-row .btn-danger').disabled = true;
                }
            }
        }

        function addStateTax() {
            const container = document.getElementById('state-taxes-container');

            const newState = document.createElement('div');
            newState.className = 'state-tax-row';
            newState.id = 'state-tax-' + stateTaxCounter;

            newState.innerHTML = `
                <div>
                    <input type="text" name="state_names[]" class="form-control"
                           placeholder="State/Region name" required>
                </div>
                
                <div>
                    <input type="text" name="state_codes[]" class="form-control"
                           placeholder="State code (e.g., LA)">
                </div>
                
                <div>
                    <input type="number" name="state_rates[]" class="form-control"
                           value="0" min="0" max="100" step="0.01"
                           placeholder="Tax rate %" required>
                </div>
                
                <div>
                    <button type="button" class="btn btn-sm btn-danger" 
                            onclick="removeStateTax(${stateTaxCounter})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            container.appendChild(newState);
            stateTaxCounter++;

            // Enable delete buttons
            document.querySelectorAll('.state-tax-row .btn-danger').forEach(btn => {
                btn.disabled = false;
            });
        }

        function removeStateTax(index) {
            const state = document.getElementById('state-tax-' + index);
            if (state) {
                state.remove();

                // Disable delete button if only one state remains
                if (document.querySelectorAll('.state-tax-row').length === 1) {
                    document.querySelector('.state-tax-row .btn-danger').disabled = true;
                }
            }
        }

        // Enable/disable tax settings based on checkbox
        document.getElementById('tax_enabled').addEventListener('change', function() {
            const taxInputs = document.querySelectorAll('input[name="tax_rate"], input[name="tax_name"], input[name="tax_number"]');
            const taxRadios = document.querySelectorAll('input[name="tax_inclusive"]');

            taxInputs.forEach(input => {
                input.disabled = !this.checked;
            });

            taxRadios.forEach(radio => {
                radio.disabled = !this.checked;
            });

            // Update status badge
            const statusBadge = document.querySelector('.tax-status');
            if (this.checked) {
                statusBadge.className = 'tax-status status-enabled';
                statusBadge.textContent = 'Tax Enabled';
            } else {
                statusBadge.className = 'tax-status status-disabled';
                statusBadge.textContent = 'Tax Disabled';
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            const taxEnabled = document.getElementById('tax_enabled');
            const taxInputs = document.querySelectorAll('input[name="tax_rate"], input[name="tax_name"], input[name="tax_number"]');
            const taxRadios = document.querySelectorAll('input[name="tax_inclusive"]');

            taxInputs.forEach(input => {
                input.disabled = !taxEnabled.checked;
            });

            taxRadios.forEach(radio => {
                radio.disabled = !taxEnabled.checked;
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>