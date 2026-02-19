<?php
// test-password-reset.php
require_once 'includes/config.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing Password Reset Function</h2>";

// Test email (use one that exists in your database)
$test_email = 'dig2skills@gmail.com'; // Change to an email in your users table

// Generate a token
$token = bin2hex(random_bytes(32));
echo "Generated token: $token<br>";

// Test the function
echo "Testing sendPasswordResetEmail('$test_email', '$token')...<br>";

$result = sendPasswordResetEmail($test_email, $token);

if ($result) {
    echo "<span style='color:green;font-weight:bold;'>✓ Password reset email sent successfully!</span><br>";
    echo "Check your inbox for the reset link.<br>";
    
    // Show what the reset link would be
    $reset_url = BASE_URL . "modules/auth/reset-password.php?token=" . urlencode($token);
    echo "Reset URL: <a href='$reset_url' target='_blank'>$reset_url</a><br>";
} else {
    echo "<span style='color:red;font-weight:bold;'>✗ Password reset email failed</span><br>";
    
    // Check if user exists
    echo "<h3>Debugging:</h3>";
    
    $conn = getDBConnection();
    $sql = "SELECT id, email, first_name FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $test_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        echo "✓ User found in database:<br>";
        echo "- ID: " . $user['id'] . "<br>";
        echo "- Name: " . $user['first_name'] . "<br>";
        echo "- Email: " . $user['email'] . "<br>";
    } else {
        echo "✗ User NOT found in database with email: $test_email<br>";
        echo "You need to add this email to the users table first.<br>";
    }
}

// Check if token was saved
echo "<h3>Checking if token was saved:</h3>";
$check_sql = "SELECT * FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("s", $test_email);
$check_stmt->execute();
$token_result = $check_stmt->get_result();

if ($token_row = $token_result->fetch_assoc()) {
    echo "✓ Token saved in database:<br>";
    echo "- Token: " . $token_row['token'] . "<br>";
    echo "- Created: " . $token_row['created_at'] . "<br>";
    echo "- Expires: " . $token_row['expires_at'] . "<br>";
    echo "- Used: " . ($token_row['used'] ? 'Yes' : 'No') . "<br>";
} else {
    echo "✗ Token NOT found in password_resets table<br>";
}
?>