<?php
// modules/crash_program/confirm_payment.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$conn = getDBConnection();

// Get registration ID
$registration_id = $_GET['id'] ?? $_SESSION['crash_registration_id'] ?? 0;

if (!$registration_id) {
    header('Location: register_crash.php');
    exit();
}

// Get registration details
$sql = "SELECT * FROM crash_program_registrations WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $registration_id);
$stmt->execute();
$result = $stmt->get_result();
$registration = $result->fetch_assoc();

if (!$registration) {
    header('Location: register_crash.php');
    exit();
}

// Check if payment is already confirmed
if ($registration['payment_status'] === 'confirmed') {
    // Redirect to success page or main portal
    $payment_confirmed = true;
} else {
    $payment_confirmed = false;
}

// Get payment settings
$settings_sql = "SELECT setting_key, setting_value FROM crash_program_settings 
                WHERE setting_key IN ('bank_name', 'account_name', 'account_number', 'admin_whatsapp', 'program_fee')";
$settings_result = $conn->query($settings_sql);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$bank_name = $settings['bank_name'] ?? 'MoniePoint Microfinance Bank';
$account_name = $settings['account_name'] ?? 'Impact Digital Academy';
$account_number = $settings['account_number'] ?? '6658393500';
$admin_whatsapp = $settings['admin_whatsapp'] ?? '+2349051586024';
$program_fee = number_format($settings['program_fee'] ?? 10000, 2);

// Handle payment confirmation submission (manual confirmation)
$confirmation_success = false;
$confirmation_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $confirmation_error = 'Invalid security token.';
    } else {
        $transaction_reference = trim($_POST['transaction_reference'] ?? '');
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);

        if (empty($transaction_reference)) {
            $confirmation_error = 'Please enter your transaction reference.';
        } elseif ($payment_amount < ($settings['program_fee'] ?? 10000)) {
            $confirmation_error = 'Payment amount is less than the program fee.';
        } else {
            // Update registration with payment details
            $update_sql = "UPDATE crash_program_registrations 
                          SET transaction_reference = ?, 
                              payment_amount = ?,
                              whatsapp_confirmation_sent = 1,
                              whatsapp_confirmation_date = NOW()
                          WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param('sdi', $transaction_reference, $payment_amount, $registration_id);

            if ($update_stmt->execute()) {
                // Generate WhatsApp message
                $program_name = $registration['program_choice'] === 'web_development' ? 'Web Development' : 'AI Faceless Video Creation';
                $whatsapp_message = "New Payment Confirmation Request%0A%0A" .
                    "Name: " . $registration['first_name'] . " " . $registration['last_name'] . "%0A" .
                    "Email: " . $registration['email'] . "%0A" .
                    "Phone: " . $registration['phone'] . "%0A" .
                    "Program: " . $program_name . "%0A" .
                    "Amount Paid: ₦" . number_format($payment_amount, 2) . "%0A" .
                    "Transaction Reference: " . $transaction_reference . "%0A" .
                    "Registration ID: #" . $registration_id . "%0A%0A" .
                    "Please verify and confirm payment.";

                // Redirect to WhatsApp
                $whatsapp_url = "https://wa.me/" . preg_replace('/[^0-9]/', '', $admin_whatsapp) . "?text=" . $whatsapp_message;

                $confirmation_success = true;

                // Store in session that we've sent confirmation
                $_SESSION['payment_confirmation_sent'] = true;
                $_SESSION['confirmation_registration_id'] = $registration_id;

                // Redirect to WhatsApp
                header("Refresh: 2; url=" . $whatsapp_url);
            } else {
                $confirmation_error = 'Failed to record your payment details. Please try again.';
            }
            $update_stmt->close();
        }
    }
}

$program_display = $registration['program_choice'] === 'web_development' ? 'Web Development' : 'AI Faceless Video Creation';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Confirm Payment - Crash Program</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 1.5rem;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), #1e40af);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .card-header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .card-content {
            padding: 2rem;
        }

        .bank-details {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .bank-details h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .bank-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .bank-row:last-child {
            border-bottom: none;
        }

        .bank-label {
            font-weight: 600;
            color: #475569;
        }

        .bank-value {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .registration-info {
            background: #fef3c7;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--warning);
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #334155;
        }

        .form-control {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            width: 100%;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-whatsapp {
            background: #25D366;
            color: white;
        }

        .btn-whatsapp:hover {
            background: #128C7E;
        }

        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }

        .step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .step-number {
            width: 40px;
            height: 40px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            color: #64748b;
        }

        .step.active .step-number {
            background: var(--primary);
            color: white;
        }

        .step.completed .step-number {
            background: var(--success);
            color: white;
        }

        .step-label {
            font-size: 0.85rem;
            color: #64748b;
        }

        .step.active .step-label {
            color: var(--primary);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: var(--success);
        }

        @media (max-width: 640px) {
            .step-label {
                font-size: 0.7rem;
            }

            .step-number {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }

            .card-content {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>Complete Your Payment</h1>
                <p>Secure your spot in the <?php echo $program_display; ?> program</p>
            </div>
            <div class="card-content">
                <?php if ($payment_confirmed): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Payment Confirmed!</strong><br>
                            Your payment has been verified. You will receive access to the program shortly.<br>
                            <a href="<?php echo BASE_URL; ?>modules/auth/login.php" style="color: #065f46; margin-top: 0.5rem; display: inline-block;">Click here to login to your dashboard</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="steps">
                        <div class="step completed">
                            <div class="step-number"><i class="fas fa-check"></i></div>
                            <div class="step-label">Registration</div>
                        </div>
                        <div class="step active">
                            <div class="step-number">2</div>
                            <div class="step-label">Make Payment</div>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <div class="step-label">Confirmation</div>
                        </div>
                        <div class="step">
                            <div class="step-number">4</div>
                            <div class="step-label">Access Program</div>
                        </div>
                    </div>

                    <div class="registration-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Registration Details:</strong><br>
                        Name: <?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?><br>
                        Program: <?php echo $program_display; ?><br>
                        Email: <?php echo htmlspecialchars($registration['email']); ?>
                    </div>

                    <div class="bank-details">
                        <h3><i class="fas fa-university"></i> Make Payment to:</h3>
                        <div class="bank-row">
                            <span class="bank-label">Bank Name:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($bank_name); ?></span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Account Name:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($account_name); ?></span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Account Number:</span>
                            <span class="bank-value"><?php echo htmlspecialchars($account_number); ?></span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Amount:</span>
                            <span class="bank-value">₦<?php echo $program_fee; ?></span>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb"></i>
                        <div>
                            <strong>Important:</strong> After making the payment, click the button below to send your payment details to our admin via WhatsApp. Your spot will be reserved for 3 days after registration.
                        </div>
                    </div>

                    <?php if ($confirmation_success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Payment details sent!</strong><br>
                                Redirecting to WhatsApp to send your payment confirmation. If not redirected, <a href="<?php echo $whatsapp_url ?? '#'; ?>" target="_blank">click here</a>.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($confirmation_error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div><?php echo $confirmation_error; ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="form-group">
                            <label for="transaction_reference">Transaction Reference / Receipt Number</label>
                            <input type="text" id="transaction_reference" name="transaction_reference"
                                class="form-control" placeholder="e.g., T123456789, or the reference from your bank" required>
                        </div>

                        <div class="form-group">
                            <label for="payment_amount">Amount Paid (₦)</label>
                            <input type="number" id="payment_amount" name="payment_amount"
                                class="form-control" value="<?php echo $settings['program_fee'] ?? 10000; ?>"
                                step="0.01" required>
                        </div>

                        <button type="submit" name="confirm_payment" class="btn btn-whatsapp">
                            <i class="fab fa-whatsapp"></i> Confirm Payment via WhatsApp
                        </button>
                    </form>

                    <div style="margin-top: 1rem; text-align: center;">
                        <a href="<?php echo BASE_URL; ?>modules/crash_program/register_crash.php" style="color: var(--primary);">
                            <i class="fas fa-arrow-left"></i> Back to Registration
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
<?php $conn->close(); ?>