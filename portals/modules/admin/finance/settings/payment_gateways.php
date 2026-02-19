<?php
// modules/admin/finance/settings/payment_gateways.php

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

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_gateway':
                $gateway_id = $_POST['gateway_id'] ?? 0;
                $gateway_name = $_POST['gateway_name'] ?? '';
                $gateway_key = $_POST['gateway_key'] ?? '';
                $gateway_secret = $_POST['gateway_secret'] ?? '';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $test_mode = isset($_POST['test_mode']) ? 1 : 0;
                $webhook_url = $_POST['webhook_url'] ?? '';

                // Validate
                if (empty($gateway_name) || empty($gateway_key)) {
                    $message = 'Gateway name and key are required';
                    $message_type = 'danger';
                } else {
                    if ($gateway_id > 0) {
                        // Update existing
                        $stmt = $conn->prepare("UPDATE payment_gateway_settings 
                                               SET gateway_name = ?, gateway_key = ?, gateway_secret = ?, 
                                                   is_active = ?, test_mode = ?, webhook_url = ?
                                               WHERE id = ?");
                        $stmt->bind_param(
                            "ssssisi",
                            $gateway_name,
                            $gateway_key,
                            $gateway_secret,
                            $is_active,
                            $test_mode,
                            $webhook_url,
                            $gateway_id
                        );
                    } else {
                        // Insert new
                        $stmt = $conn->prepare("INSERT INTO payment_gateway_settings 
                                               (gateway_name, gateway_key, gateway_secret, is_active, 
                                                test_mode, webhook_url, created_at, updated_at)
                                               VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->bind_param(
                            "ssssis",
                            $gateway_name,
                            $gateway_key,
                            $gateway_secret,
                            $is_active,
                            $test_mode,
                            $webhook_url
                        );
                    }

                    if ($stmt->execute()) {
                        $message = $gateway_id > 0 ? 'Gateway updated successfully' : 'Gateway added successfully';
                        $message_type = 'success';
                        logActivity(
                            $_SESSION['user_id'],
                            'payment_gateway_update',
                            "Updated payment gateway: $gateway_name"
                        );
                    } else {
                        $message = 'Error saving gateway: ' . $conn->error;
                        $message_type = 'danger';
                    }
                    $stmt->close();
                }
                break;

            case 'toggle_status':
                $gateway_id = $_POST['gateway_id'] ?? 0;
                $stmt = $conn->prepare("UPDATE payment_gateway_settings 
                                       SET is_active = NOT is_active, updated_at = NOW()
                                       WHERE id = ?");
                $stmt->bind_param("i", $gateway_id);
                if ($stmt->execute()) {
                    $message = 'Gateway status updated';
                    $message_type = 'success';
                }
                $stmt->close();
                break;

            case 'delete_gateway':
                $gateway_id = $_POST['gateway_id'] ?? 0;
                $stmt = $conn->prepare("DELETE FROM payment_gateway_settings WHERE id = ?");
                $stmt->bind_param("i", $gateway_id);
                if ($stmt->execute()) {
                    $message = 'Gateway deleted successfully';
                    $message_type = 'success';
                }
                $stmt->close();
                break;
        }
    }
}

// Get all payment gateways
$gateways_sql = "SELECT * FROM payment_gateway_settings ORDER BY gateway_name";
$gateways_result = $conn->query($gateways_sql);
$gateways = $gateways_result->fetch_all(MYSQLI_ASSOC);

// Get gateway for editing if specified
$edit_gateway = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM payment_gateway_settings WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_gateway = $result->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Gateway Settings - Admin Portal</title>
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
            background: var(--dark);
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .btn-icon {
            padding: 0.5rem;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        th,
        td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #f1f5f9;
            color: #64748b;
        }

        .test-badge {
            background: #fef3c7;
            color: #92400e;
        }

        .live-badge {
            background: #d1fae5;
            color: #065f46;
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

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e1;
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <!-- Sidebar (Same as dashboard) -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Impact Academy</h2>
                <p>Finance Settings</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/dashboard.php">
                            <i class="fas fa-chart-line"></i> Finance Dashboard</a></li>

                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/payment_gateways.php" class="active">
                            <i class="fas fa-credit-card"></i> Payment Gateways</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/tax_settings.php">
                            <i class="fas fa-percentage"></i> Tax Settings</a></li>
                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/automation.php">
                            <i class="fas fa-robot"></i> Automation Rules</a></li>

                    <li><a href="<?php echo BASE_URL; ?>modules/admin/finance/fees/index.php">
                            <i class="fas fa-calculator"></i> Fee Management</a></li>
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
                    <i class="fas fa-credit-card"></i>
                    Payment Gateway Settings
                </h1>
                <div>
                    <a href="<?php echo BASE_URL; ?>modules/admin/finance/settings/" class="btn btn-secondary">
                        <i class="fas fa-cog"></i> All Settings
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

            <!-- Gateway Configuration Form -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas <?php echo $edit_gateway ? 'fa-edit' : 'fa-plus-circle'; ?>"></i>
                        <?php echo $edit_gateway ? 'Edit Payment Gateway' : 'Add New Payment Gateway'; ?>
                    </h3>
                    <?php if ($edit_gateway): ?>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel Edit
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?php if ($edit_gateway): ?>
                            <input type="hidden" name="gateway_id" value="<?php echo $edit_gateway['id']; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="action" value="save_gateway">

                        <div class="form-group">
                            <label for="gateway_name">Gateway Name *</label>
                            <input type="text" id="gateway_name" name="gateway_name" class="form-control"
                                value="<?php echo htmlspecialchars($edit_gateway['gateway_name'] ?? ''); ?>"
                                required placeholder="e.g., Paystack, Flutterwave, Stripe">
                        </div>

                        <div class="form-group">
                            <label for="gateway_key">API Public Key *</label>
                            <input type="password" id="gateway_key" name="gateway_key" class="form-control"
                                value="<?php echo htmlspecialchars($edit_gateway['gateway_key'] ?? ''); ?>"
                                required placeholder="Your gateway public key">
                            <small style="color: #64748b; font-size: 0.85rem;">
                                This key is used to initialize payments
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="gateway_secret">API Secret Key *</label>
                            <input type="password" id="gateway_secret" name="gateway_secret" class="form-control"
                                value="<?php echo htmlspecialchars($edit_gateway['gateway_secret'] ?? ''); ?>"
                                required placeholder="Your gateway secret key">
                            <small style="color: #64748b; font-size: 0.85rem;">
                                This key is used to verify transactions
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="webhook_url">Webhook URL</label>
                            <input type="url" id="webhook_url" name="webhook_url" class="form-control"
                                value="<?php echo htmlspecialchars($edit_gateway['webhook_url'] ?? ''); ?>"
                                placeholder="https://yourdomain.com/webhook/payment">
                            <small style="color: #64748b; font-size: 0.85rem;">
                                URL for payment gateway callbacks. Leave empty for default.
                            </small>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_active" name="is_active"
                                    <?php echo ($edit_gateway['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label for="is_active">Active Gateway</label>
                            </div>
                            <small style="color: #64748b; font-size: 0.85rem;">
                                When active, this gateway will be available for payments
                            </small>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="test_mode" name="test_mode"
                                    <?php echo ($edit_gateway['test_mode'] ?? 1) ? 'checked' : ''; ?>>
                                <label for="test_mode">Test Mode</label>
                            </div>
                            <small style="color: #64748b; font-size: 0.85rem;">
                                Use test mode for development. Uncheck for live transactions.
                            </small>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?php echo $edit_gateway ? 'Update Gateway' : 'Add Gateway'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Existing Gateways -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Configured Payment Gateways</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($gateways)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Gateway</th>
                                        <th>Status</th>
                                        <th>Mode</th>
                                        <th>Keys Configured</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gateways as $gateway): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($gateway['gateway_name']); ?></div>
                                                <?php if ($gateway['webhook_url']): ?>
                                                    <small style="color: #64748b;">Webhook: <?php echo htmlspecialchars($gateway['webhook_url']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $gateway['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $gateway['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $gateway['test_mode'] ? 'test-badge' : 'live-badge'; ?>">
                                                    <?php echo $gateway['test_mode'] ? 'Test Mode' : 'Live Mode'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($gateway['gateway_key'] && $gateway['gateway_secret']): ?>
                                                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                                    <span style="color: var(--success); font-size: 0.85rem;">Configured</span>
                                                <?php else: ?>
                                                    <i class="fas fa-exclamation-circle" style="color: var(--danger);"></i>
                                                    <span style="color: var(--danger); font-size: 0.85rem;">Incomplete</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($gateway['updated_at'])); ?><br>
                                                <small style="color: #64748b;"><?php echo date('g:i A', strtotime($gateway['updated_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?edit=<?php echo $gateway['id']; ?>" class="btn btn-icon btn-secondary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="gateway_id" value="<?php echo $gateway['id']; ?>">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <button type="submit" class="btn btn-icon <?php echo $gateway['is_active'] ? 'btn-warning' : 'btn-success'; ?>"
                                                            title="<?php echo $gateway['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="fas <?php echo $gateway['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="" style="display: inline;"
                                                        onsubmit="return confirm('Are you sure you want to delete this gateway?');">
                                                        <input type="hidden" name="gateway_id" value="<?php echo $gateway['id']; ?>">
                                                        <input type="hidden" name="action" value="delete_gateway">
                                                        <button type="submit" class="btn btn-icon btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-credit-card"></i>
                            <h3>No Payment Gateways Configured</h3>
                            <p>Add your first payment gateway to start accepting online payments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Gateway Integration Instructions -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle"></i> Integration Guide</h3>
                </div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">Paystack</h4>
                            <ul style="color: #64748b; line-height: 1.6;">
                                <li>Get your API keys from <a href="https://dashboard.paystack.com" target="_blank">Paystack Dashboard</a></li>
                                <li>Public Key: pk_test_... or pk_live_...</li>
                                <li>Secret Key: sk_test_... or sk_live_...</li>
                                <li>Webhook URL: <?php echo BASE_URL; ?>modules/shared/finance/payment_gateways/paystack_webhook.php</li>
                            </ul>
                        </div>

                        <div>
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">Flutterwave</h4>
                            <ul style="color: #64748b; line-height: 1.6;">
                                <li>Get your API keys from <a href="https://dashboard.flutterwave.com" target="_blank">Flutterwave Dashboard</a></li>
                                <li>Public Key: FLWPUBK_TEST_... or FLWPUBK_...</li>
                                <li>Secret Key: FLWSECK_TEST_... or FLWSECK_...</li>
                                <li>Webhook URL: <?php echo BASE_URL; ?>modules/shared/finance/payment_gateways/flutterwave_webhook.php</li>
                            </ul>
                        </div>

                        <div>
                            <h4 style="margin-bottom: 1rem; color: var(--dark);">Test Mode</h4>
                            <ul style="color: #64748b; line-height: 1.6;">
                                <li>Use test card: 4242 4242 4242 4242</li>
                                <li>Expiry: Any future date</li>
                                <li>CVV: 123</li>
                                <li>PIN: 1234 (if required)</li>
                                <li>OTP: 123456</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const type = input.type === 'password' ? 'text' : 'password';
            input.type = type;
        }

        // Add toggle buttons for password fields
        document.addEventListener('DOMContentLoaded', function() {
            const passwordFields = ['gateway_key', 'gateway_secret'];

            passwordFields.forEach(fieldId => {
                const input = document.getElementById(fieldId);
                if (input) {
                    const toggleBtn = document.createElement('button');
                    toggleBtn.type = 'button';
                    toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                    toggleBtn.style.position = 'absolute';
                    toggleBtn.style.right = '10px';
                    toggleBtn.style.top = '50%';
                    toggleBtn.style.transform = 'translateY(-50%)';
                    toggleBtn.style.background = 'none';
                    toggleBtn.style.border = 'none';
                    toggleBtn.style.cursor = 'pointer';
                    toggleBtn.style.color = '#64748b';

                    input.parentNode.style.position = 'relative';
                    input.parentNode.appendChild(toggleBtn);

                    toggleBtn.addEventListener('click', function() {
                        const type = input.type === 'password' ? 'text' : 'password';
                        input.type = type;
                        toggleBtn.innerHTML = type === 'password' ?
                            '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
                    });
                }
            });
        });

        // Test gateway connection
        function testGateway(gatewayId) {
            if (confirm('Test gateway connection?')) {
                fetch('<?php echo BASE_URL; ?>modules/admin/finance/settings/test_gateway.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'gateway_id=' + gatewayId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Connection successful: ' + data.message);
                        } else {
                            alert('Connection failed: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error testing connection: ' + error);
                    });
            }
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>