<?php
// test_mail.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing InfinityFree Email System</h2>";
echo "<p>Server: " . $_SERVER['SERVER_NAME'] . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test mail() function
$to = "dig2skills@gmail.com";
$subject = "Test Email from InfinityFree";
$message = "This is a test email to confirm PHP mail() is working.";
$headers = "From: noreply@impactdigitalacademy.com.ng\r\n";
$headers .= "Reply-To: dig2skills@gmail.com\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8";

echo "<h3>Sending test email...</h3>";

if (mail($to, $subject, $message, $headers)) {
    echo "<p style='color: green;'><strong>✓ Email sent successfully!</strong></p>";
    echo "<p>Sent to: $to</p>";
    echo "<p>Check your inbox (and spam folder).</p>";
} else {
    echo "<p style='color: red;'><strong>✗ Email failed to send</strong></p>";
    echo "<p>Last error: " . error_get_last()['message'] . "</p>";
}

// Display PHP mail configuration
echo "<hr><h3>PHP Mail Configuration:</h3>";
echo "<pre>";
echo "SMTP = " . ini_get('SMTP') . "\n";
echo "smtp_port = " . ini_get('smtp_port') . "\n";
echo "sendmail_from = " . ini_get('sendmail_from') . "\n";
echo "sendmail_path = " . ini_get('sendmail_path') . "\n";
echo "</pre>";
?>