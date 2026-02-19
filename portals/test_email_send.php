<?php
// test-email-send.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session for authentication (if needed)
startSession();

// Only allow admin or from localhost for security
$allowed = false;
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $allowed = true;
} elseif ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') {
    $allowed = true;
}

if (!$allowed) {
    die("<h2>❌ Access Denied</h2><p>Only admins can run this test.</p>");
}

// Test email function
function testEmailFunction($to, $subject, $message)
{
    echo "<h3>Testing: {$subject}</h3>";
    
    try {
        // Initialize PHPMailer
        require_once __DIR__ . '/../vendor/autoload.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->SMTPDebug = 2; // Enable verbose debug output
        
        // Capture debug output
        ob_start();
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->isHTML(true);
        
        $success = $mail->send();
        $debug_output = ob_get_clean();
        
        if ($success) {
            echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; color: #155724;'>
                    ✅ Email sent successfully!
                  </div>";
        } else {
            echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; color: #721c24;'>
                    ❌ Email failed: {$mail->ErrorInfo}
                  </div>";
        }
        
        // Show debug info
        echo "<details style='margin-top: 10px;'>
                <summary>View Debug Information</summary>
                <pre style='background: #f8f9fa; padding: 10px; border: 1px solid #ddd;'>{$debug_output}</pre>
              </details>";
        
        return $success;
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; color: #721c24;'>
                ❌ Exception: {$e->getMessage()}
              </div>";
        return false;
    }
}

// HTML Interface
echo "<!DOCTYPE html>
<html>
<head>
    <title>Email System Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type='email'], input[type='text'], textarea {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;
        }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .test-button { background: #28a745; margin: 5px; }
        .test-button:hover { background: #1e7e34; }
        .result { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>📧 Email System Test</h1>
        
        <div class='form-group'>
            <label>Test Email Address:</label>
            <input type='email' id='test_email' value='your-test-email@gmail.com' placeholder='Enter test email'>
        </div>
        
        <div>
            <h3>Quick Tests:</h3>
            <button class='test-button' onclick='runTest(\"connection\")'>Test SMTP Connection</button>
            <button class='test-button' onclick='runTest(\"simple\")'>Send Simple Test Email</button>
            <button class='test-button' onclick='runTest(\"welcome\")'>Test Welcome Email</button>
            <button class='test-button' onclick='runTest(\"assignment\")'>Test Assignment Email</button>
        </div>
        
        <hr>
        
        <h3>Manual Test:</h3>
        <form method='POST' onsubmit='return sendManualTest(this)'>
            <div class='form-group'>
                <label>Recipient Email:</label>
                <input type='email' name='to_email' required>
            </div>
            <div class='form-group'>
                <label>Subject:</label>
                <input type='text' name='subject' value='Test Email from System' required>
            </div>
            <div class='form-group'>
                <label>Message:</label>
                <textarea name='message' rows='5' required>This is a test email from the system.</textarea>
            </div>
            <button type='submit'>Send Test Email</button>
        </form>
        
        <div id='test_results'></div>
    </div>
    
    <script>
    function runTest(testType) {
        const email = document.getElementById('test_email').value;
        if (!email) {
            alert('Please enter a test email address');
            return;
        }
        
        const resultsDiv = document.getElementById('test_results');
        resultsDiv.innerHTML = '<div class=\"result\">Testing... Please wait.</div>';
        
        fetch('ajax-test-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `test_type=${testType}&email=${encodeURIComponent(email)}`
        })
        .then(response => response.text())
        .then(html => {
            resultsDiv.innerHTML = html;
        })
        .catch(error => {
            resultsDiv.innerHTML = `<div class=\"error result\">Error: ${error}</div>`;
        });
    }
    
    function sendManualTest(form) {
        const formData = new FormData(form);
        const resultsDiv = document.getElementById('test_results');
        resultsDiv.innerHTML = '<div class=\"result\">Sending... Please wait.</div>';
        
        fetch('ajax-test-email.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            resultsDiv.innerHTML = html;
        })
        .catch(error => {
            resultsDiv.innerHTML = `<div class=\"error result\">Error: ${error}</div>`;
        });
        
        return false;
    }
    </script>
</body>
</html>";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['to_email'])) {
    $to = $_POST['to_email'];
    $subject = $_POST['subject'] ?? 'Test Email';
    $message = $_POST['message'] ?? 'Test message';
    
    echo "<div class='result'>";
    testEmailFunction($to, $subject, $message);
    echo "</div>";
}
?>