<?php
// ajax-test-email.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Security check
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    // Check if user is admin
    startSession();
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        die('Access denied');
    }
}

// Handle different test types
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_type = $_POST['test_type'] ?? 'simple';
    $email = $_POST['email'] ?? $_POST['to_email'] ?? '';
    
    if (empty($email)) {
        echo "<div class='error'>❌ Please provide an email address</div>";
        exit;
    }
    
    switch ($test_type) {
        case 'connection':
            testSMTPConnection($email);
            break;
            
        case 'simple':
            sendSimpleTestEmail($email);
            break;
            
        case 'welcome':
            testWelcomeEmail($email);
            break;
            
        case 'assignment':
            testAssignmentEmail($email);
            break;
            
        default:
            sendManualEmail($email, $_POST['subject'] ?? '', $_POST['message'] ?? '');
    }
}

function testSMTPConnection($email)
{
    echo "<h3>🔧 Testing SMTP Connection</h3>";
    
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->SMTPDebug = 2;
        
        // Just try to connect, don't send
        ob_start();
        $mail->smtpConnect();
        $mail->smtpClose();
        $debug = ob_get_clean();
        
        echo "<div class='success'>✅ SMTP Connection Successful!</div>";
        echo "<details><summary>Connection Details</summary><pre>{$debug}</pre></details>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ SMTP Connection Failed: {$e->getMessage()}</div>";
    }
}

function sendSimpleTestEmail($email)
{
    $subject = "Test Email from " . getSetting('school_name', 'System');
    $message = "<h2>Test Email</h2><p>This is a test email sent from your system.</p>";
    
    echo "<h3>📨 Sending Simple Test Email</h3>";
    
    if (sendEmail($email, $subject, $message)) {
        echo "<div class='success'>✅ Test email sent successfully to {$email}</div>";
    } else {
        echo "<div class='error'>❌ Failed to send test email</div>";
    }
}

function testWelcomeEmail($email)
{
    echo "<h3>👋 Testing Welcome Email</h3>";
    
    // Create a temporary user for testing
    $temp_user = [
        'id' => 999999,
        'email' => $email,
        'first_name' => 'Test',
        'last_name' => 'User',
        'role' => 'student'
    ];
    
    // Mock the getUserById function for testing
    global $mock_user_data;
    $mock_user_data = $temp_user;
    
    // Create test version of the function
    $subject = "Welcome to " . getSetting('school_name', 'Our School');
    $body = getWelcomeEmailTemplate($temp_user, 'temp_password123');
    
    if (sendEmail($email, $subject, $body)) {
        echo "<div class='success'>✅ Welcome email test sent successfully</div>";
    } else {
        echo "<div class='error'>❌ Failed to send welcome email</div>";
    }
}

function testAssignmentEmail($email)
{
    echo "<h3>📚 Testing Assignment Notification Email</h3>";
    
    $assignment_data = [
        'title' => 'Test Assignment',
        'course_title' => 'Test Course',
        'course_code' => 'TEST101',
        'batch_code' => 'BATCH001',
        'due_date' => date('Y-m-d H:i:s', strtotime('+7 days'))
    ];
    
    $subject = "New Assignment: " . $assignment_data['title'] . " - " . $assignment_data['course_title'];
    $body = getAssignmentEmailTemplate('Test Student', $assignment_data);
    
    if (sendEmail($email, $subject, $body)) {
        echo "<div class='success'>✅ Assignment notification test sent successfully</div>";
    } else {
        echo "<div class='error'>❌ Failed to send assignment notification</div>";
    }
}

function sendManualEmail($email, $subject, $message)
{
    if (sendEmail($email, $subject, $message)) {
        echo "<div class='success'>✅ Email sent successfully to {$email}</div>";
    } else {
        echo "<div class='error'>❌ Failed to send email</div>";
    }
}

// Helper function for email templates
function getWelcomeEmailTemplate($user, $password)
{
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; }
            .header { background: #4a6fa5; color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 30px; }
            .footer { background: #eee; padding: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to Our System!</h1>
            </div>
            <div class='content'>
                <p>Hello {$user['first_name']},</p>
                <p>This is a test welcome email.</p>
                <p>Your temporary password: {$password}</p>
            </div>
            <div class='footer'>
                <p>Test Email - Not a real account</p>
            </div>
        </div>
    </body>
    </html>";
}

function getAssignmentEmailTemplate($student_name, $assignment)
{
    $due_date = date('F j, Y g:i A', strtotime($assignment['due_date']));
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; }
            .header { background: #4a6fa5; color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 30px; }
            .footer { background: #eee; padding: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>New Assignment</h1>
            </div>
            <div class='content'>
                <p>Hello {$student_name},</p>
                <p>Test assignment notification for:</p>
                <ul>
                    <li><strong>Course:</strong> {$assignment['course_title']}</li>
                    <li><strong>Assignment:</strong> {$assignment['title']}</li>
                    <li><strong>Due Date:</strong> {$due_date}</li>
                </ul>
            </div>
            <div class='footer'>
                <p>Test Assignment Email</p>
            </div>
        </div>
    </body>
    </html>";
}
?>