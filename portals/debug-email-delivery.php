<?php
// debug-email-delivery.php
require_once 'includes/config.php';

// Enable detailed logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Email Delivery Debug</h2>";

// Test different email providers
$test_emails = [
    'samadeimmanuel@yahoo.com',
    'samadeimmanuel@gmail.com'  // Try Gmail too
];

foreach ($test_emails as $test_email) {
    echo "<h3>Testing: $test_email</h3>";
    
    // First check if user exists
    $conn = getDBConnection();
    $sql = "SELECT id, email, first_name FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $test_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        echo "✓ User exists in database<br>";
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        
        // Test sendEmail directly (bypass password reset)
        $subject = "Direct Test - Impact Digital Academy";
        $body = "<h1>Direct Email Test</h1><p>Testing email delivery to $test_email</p>";
        
        echo "Sending direct test email...<br>";
        $direct_result = sendEmail($test_email, $subject, $body);
        
        if ($direct_result) {
            echo "<span style='color:green;'>✓ Direct email marked as sent</span><br>";
        } else {
            echo "<span style='color:red;'>✗ Direct email failed</span><br>";
        }
        
        // Test password reset email
        echo "Testing password reset email...<br>";
        $reset_result = sendPasswordResetEmail($test_email, $token);
        
        if ($reset_result) {
            echo "<span style='color:green;'>✓ Password reset email marked as sent</span><br>";
        } else {
            echo "<span style='color:red;'>✗ Password reset email failed</span><br>";
        }
        
    } else {
        echo "<span style='color:orange;'>⚠ User not found in database</span><br>";
        echo "You need to add this email to the users table first.<br>";
    }
    
    echo "<hr>";
}

// Check Google Apps Script logs
echo "<h3>Google Apps Script Status</h3>";
echo "1. Go to <a href='https://script.google.com/' target='_blank'>script.google.com</a><br>";
echo "2. Open your project<br>";
echo "3. Click 'View' → 'Logs'<br>";
echo "4. Look for recent email sending attempts<br>";

// Check server logs
echo "<h3>Server Logs Location</h3>";
echo "Check error logs at: /home/vol8_2/infinityfree.com/if0_40714435/portal.impactdigitalacademy.com.ng/htdocs/error_log<br>";
?>