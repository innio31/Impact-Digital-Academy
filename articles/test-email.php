<?php
// test-email.php - Comprehensive email test
require_once 'config.php';

echo "<!DOCTYPE html><html><head><title>Email Test</title>";
echo "<style>
    body{font-family:Arial;padding:20px;background:#f5f5f5;} 
    .success{color:green;background:#e8f5e8;padding:10px;border-radius:5px;}
    .error{color:red;background:#ffe8e8;padding:10px;border-radius:5px;}
    .info{background:#e8e8ff;padding:10px;border-radius:5px;}
    pre{background:#fff;padding:10px;border-radius:5px;overflow:auto;}
    button{padding:10px 20px;background:#008080;color:white;border:none;border-radius:5px;cursor:pointer;}
</style>";
echo "</head><body>";
echo "<h1>Email Configuration Test</h1>";

// Test SMTP connection
echo "<h2>Testing SMTP Connection</h2>";

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "<div class='error'>PHPMailer not loaded!</div>";
    exit;
}

echo "<div class='info'>";
echo "SMTP Host: " . SMTP_HOST . "<br>";
echo "SMTP Port: " . SMTP_PORT . "<br>";
echo "SMTP User: " . SMTP_USER . "<br>";
echo "SMTP From: " . SMTP_FROM . "<br>";
echo "</div>";

// Test sending
if (isset($_POST['test_email'])) {
    $test_email = $_POST['test_email'];

    echo "<h3>Sending Test Email to: $test_email</h3>";

    $mailer = new MailSender();

    // Enable SMTP debugging for this test
    if (method_exists($mailer, 'setDebug')) {
        $mailer->setDebug(2);
    }

    ob_start(); // Start output buffering to capture debug info
    $result = $mailer->sendWelcomeEmail($test_email);
    $debug_output = ob_get_clean();

    if ($result) {
        echo "<div class='success'>✓ Email sent successfully! Check $test_email inbox (and spam folder).</div>";
    } else {
        echo "<div class='error'>✗ Email failed to send.</div>";
        echo "<h4>Error Details:</h4>";
        echo "<pre>" . $mailer->getError() . "</pre>";

        // Try alternative configuration
        echo "<h4>Trying Alternative Configuration...</h4>";

        try {
            $alt_mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $alt_mail->isSMTP();
            $alt_mail->Host       = SMTP_HOST;
            $alt_mail->SMTPAuth   = true;
            $alt_mail->Username   = SMTP_USER;
            $alt_mail->Password   = SMTP_PASS;
            $alt_mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; // Try SSL
            $alt_mail->Port       = 465; // Try SSL port
            $alt_mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $alt_mail->addAddress($test_email);
            $alt_mail->Subject = 'Alternative Test';
            $alt_mail->Body = 'This is a test using SSL on port 465';

            if ($alt_mail->send()) {
                echo "<div class='success'>✓ Success with SSL on port 465!</div>";
                echo "<p>Update your config.php to use port 465 with ENCRYPTION_SMTPS</p>";
            }
        } catch (Exception $e) {
            echo "<div class='error'>Alternative also failed: " . $e->getMessage() . "</div>";

            // Try no encryption
            try {
                $alt_mail2 = new PHPMailer\PHPMailer\PHPMailer(true);
                $alt_mail2->isSMTP();
                $alt_mail2->Host       = SMTP_HOST;
                $alt_mail2->SMTPAuth   = true;
                $alt_mail2->Username   = SMTP_USER;
                $alt_mail2->Password   = SMTP_PASS;
                $alt_mail2->SMTPSecure = false;
                $alt_mail2->Port       = 25;
                $alt_mail2->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                $alt_mail2->addAddress($test_email);
                $alt_mail2->Subject = 'No Encryption Test';
                $alt_mail2->Body = 'This is a test with no encryption on port 25';

                if ($alt_mail2->send()) {
                    echo "<div class='success'>✓ Success with no encryption on port 25!</div>";
                }
            } catch (Exception $e) {
                echo "<div class='error'>All attempts failed. Check your email credentials in cPanel.</div>";
            }
        }
    }
}

// Test form
echo "<h2>Send Test Email</h2>";
echo "<form method='post'>";
echo "<input type='email' name='test_email' placeholder='Enter your email' required style='padding:10px; width:300px;'>";
echo "<button type='submit'>Send Test Email</button>";
echo "</form>";

// Check email logs
echo "<h2>Recent Email Logs</h2>";
if (file_exists('php_errors.log')) {
    $logs = tailFile('php_errors.log', 20);
    echo "<pre>" . htmlspecialchars($logs) . "</pre>";
} else {
    echo "No error log found.";
}

function tailFile($filepath, $lines = 20)
{
    if (!file_exists($filepath)) return '';
    $data = array_slice(file($filepath), -$lines);
    return implode('', $data);
}

echo "</body></html>";
