<?php
// test_email.php - Simple email test

// Include your config file (adjust path if needed)
require_once __DIR__ . '/includes/config.php';

// Test email function
function testEmail()
{
    echo "<h2>Email Configuration Test</h2>";

    // Send test email to yourself
    $test_email = "samadeimmanuel@gmail.com"; // Change this to your email

    echo "<p>Attempting to send test email to: <strong>$test_email</strong></p>";

    $subject = "Test Email from Portal";
    $body = "
    <h3>Email Test</h3>
    <p>This is a test email from your Impact Digital Academy portal.</p>
    <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
    <p><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</p>
    <p>If you received this, your email is working!</p>
    ";

    $result = sendEmail($test_email, $subject, $body);

    if ($result) {
        echo "<p style='color: green; font-weight: bold;'>✓ Email sent successfully!</p>";
        echo "<p>Check your inbox (and spam folder) for the test email.</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗ Email sending failed!</p>";
        echo "<p>Check the error logs for details.</p>";
    }
}

// Run the test
testEmail();

// Display recent error logs
echo "<h3>Recent Email Logs:</h3>";
echo "<pre style='background: #f4f4f4; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow: auto;'>";

$log_file = ini_get('error_log');
if (file_exists($log_file)) {
    $logs = file($log_file);
    $email_logs = array_filter($logs, function ($line) {
        return strpos($line, 'email') !== false || strpos($line, 'mail') !== false;
    });

    if (!empty($email_logs)) {
        foreach (array_slice($email_logs, -10) as $log) {
            echo htmlspecialchars($log) . "\n";
        }
    } else {
        echo "No recent email-related logs found.\n";
    }
} else {
    echo "Error log file not found.\n";
}
echo "</pre>";

// Display PHP mail configuration
echo "<h3>PHP Mail Configuration:</h3>";
echo "<pre>";
echo "SMTP: " . ini_get('SMTP') . "\n";
echo "smtp_port: " . ini_get('smtp_port') . "\n";
echo "sendmail_from: " . ini_get('sendmail_from') . "\n";
echo "sendmail_path: " . ini_get('sendmail_path') . "\n";
echo "</pre>";

// Add a link to test password reset
echo "<p><a href='modules/auth/forgot-password.php' target='_blank'>Test Password Reset Page</a></p>";
