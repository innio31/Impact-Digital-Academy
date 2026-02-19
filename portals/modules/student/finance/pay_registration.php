<?php
// modules/student/finance/pay_registration.php

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$program_id = $_GET['program_id'] ?? 0;

// Get program details
$program = [];
$program_sql = "SELECT * FROM programs WHERE id = ?";
$program_stmt = $conn->prepare($program_sql);
$program_stmt->bind_param("i", $program_id);
$program_stmt->execute();
$program_result = $program_stmt->get_result();
if ($program_result->num_rows > 0) {
    $program = $program_result->fetch_assoc();
}
$program_stmt->close();

if (empty($program)) {
    header('Location: ' . BASE_URL . 'modules/student/program/');
    exit();
}

// Check if already paid
$already_paid = false;
$payment_sql = "SELECT * FROM registration_fee_payments 
                WHERE student_id = ? AND program_id = ? AND status = 'completed'";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("ii", $user_id, $program_id);
$payment_stmt->execute();
$payment_result = $payment_stmt->get_result();
if ($payment_result->num_rows > 0) {
    $already_paid = true;
}
$payment_stmt->close();

// Handle payment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_payment'])) {
    $payment_method = $_POST['payment_method'] ?? 'online';

    // For demo purposes, we'll simulate successful payment
    // In production, integrate with payment gateway here

    $insert_sql = "INSERT INTO registration_fee_payments 
                  (student_id, program_id, amount, payment_method, status, payment_date, transaction_id)
                  VALUES (?, ?, ?, ?, 'completed', CURDATE(), ?)";

    $transaction_id = 'REG_' . time() . '_' . $user_id;
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param(
        "iidss",
        $user_id,
        $program_id,
        $program['registration_fee'],
        $payment_method,
        $transaction_id
    );

    if ($insert_stmt->execute()) {
        // Log activity
        logActivity(
            $user_id,
            'registration_fee_payment',
            'Paid registration fee for program: ' . $program['name'],
            $_SERVER['REMOTE_ADDR']
        );

        // Redirect to success page or back to periods
        header('Location: ' . BASE_URL . 'modules/student/program/available_periods.php?message=Registration+fee+paid+successfully&type=success');
        exit();
    }

    $insert_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Registration Fee - Impact Digital Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Add styling similar to other pages */
    </style>
</head>

<body>
    <?php if ($already_paid): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div>
                <h3>Registration Fee Already Paid</h3>
                <p>You have already paid the registration fee for this program.</p>
                <a href="<?php echo BASE_URL; ?>modules/student/program/available_periods.php" class="btn btn-primary">
                    Go to Available Periods
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="payment-container">
            <h2>Pay Registration Fee</h2>
            <div class="payment-details">
                <p><strong>Program:</strong> <?php echo htmlspecialchars($program['name']); ?></p>
                <p><strong>Registration Fee:</strong> ₦<?php echo number_format($program['registration_fee'], 2); ?></p>

                <form method="POST">
                    <div class="payment-methods">
                        <h3>Select Payment Method</h3>
                        <label>
                            <input type="radio" name="payment_method" value="online" checked>
                            <i class="fas fa-credit-card"></i> Online Payment
                        </label>
                        <label>
                            <input type="radio" name="payment_method" value="bank_transfer">
                            <i class="fas fa-university"></i> Bank Transfer
                        </label>
                        <label>
                            <input type="radio" name="payment_method" value="pos">
                            <i class="fas fa-shopping-cart"></i> POS
                        </label>
                    </div>

                    <button type="submit" name="process_payment" class="btn btn-success">
                        <i class="fas fa-lock"></i> Secure Payment - ₦<?php echo number_format($program['registration_fee'], 2); ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</body>

</html>
<?php $conn->close(); ?>