<?php
// debug_email_detailed.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h2>Detailed Email Debug</h2>";

$conn = getDBConnection();

// Test 1: Direct email test
echo "<h3>Test 1: Direct Email Test</h3>";
$test_email = "immanuelsamade@gmail.com"; // USE YOUR ACTUAL EMAIL HERE
$test_subject = "Direct Test " . date('H:i:s');
$test_body = "This is a direct test email.";

$mail = initMailer();
if (!$mail) {
    echo "✗ Failed to initialize PHPMailer<br>";
} else {
    try {
        $mail->addAddress($test_email);
        $mail->Subject = $test_subject;
        $mail->Body = $test_body;
        
        if ($mail->send()) {
            echo "✓ Direct email sent successfully<br>";
            echo "✓ Check your inbox for: $test_subject<br>";
        } else {
            echo "✗ Direct send failed: " . $mail->ErrorInfo . "<br>";
        }
    } catch (Exception $e) {
        echo "✗ Exception: " . $e->getMessage() . "<br>";
    }
}

// Test 2: Check SMTP settings
echo "<h3>Test 2: SMTP Configuration</h3>";
echo "SMTP Host: " . SMTP_HOST . "<br>";
echo "SMTP Port: " . SMTP_PORT . "<br>";
echo "SMTP Username: " . SMTP_USERNAME . "<br>";
echo "SMTP From: " . SMTP_FROM_EMAIL . "<br>";

// Test 3: Check if emails are being sent to spam
echo "<h3>Test 3: Email Headers Check</h3>";
echo "Always check your SPAM/JUNK folder!<br>";
echo "Common reasons emails go to spam:<br>";
echo "1. From address doesn't match domain<br>";
echo "2. Missing DKIM/SPF records<br>";
echo "3. Server IP has poor reputation (common with free hosting)<br>";

// Test 4: Test the sendEmail function
echo "<h3>Test 4: sendEmail() Function</h3>";
$result = sendEmail($test_email, "sendEmail() Test", "Testing sendEmail function");
echo "sendEmail() returned: " . ($result ? "true" : "false") . "<br>";

// Test 5: Check announcement recipients
echo "<h3>Test 5: Check Announcement Recipients</h3>";
$sql = "SELECT COUNT(*) as count, 
               SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
               SUM(CASE WHEN role = 'instructor' THEN 1 ELSE 0 END) as instructors,
               SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins
        FROM users 
        WHERE status = 'active' 
        AND email IS NOT NULL 
        AND email != ''";

$result = $conn->query($sql);
$row = $result->fetch_assoc();
echo "Total active users with email: " . $row['count'] . "<br>";
echo "Students: " . $row['students'] . "<br>";
echo "Instructors: " . $row['instructors'] . "<br>";
echo "Admins: " . $row['admins'] . "<br>";

// Test 6: Check a few actual emails
echo "<h3>Test 6: Sample User Emails</h3>";
$sql = "SELECT email, first_name, role FROM users 
        WHERE email IS NOT NULL AND email != '' 
        LIMIT 5";
$result = $conn->query($sql);
while ($user = $result->fetch_assoc()) {
    echo $user['email'] . " (" . $user['first_name'] . " - " . $user['role'] . ")<br>";
}
?>