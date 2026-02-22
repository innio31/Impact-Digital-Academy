<?php
// includes/email_functions.php

// ===== EMAIL FUNCTIONS =====

/**
 * Main email sending function using PHP's mail() - IMPROVED VERSION
 */
function sendEmail($to, $subject, $body, $isHTML = true, $attachments = [])
{
    try {
        // Prepare recipients
        if (is_array($to)) {
            $toEmail = implode(', ', $to);
        } else {
            $toEmail = $to;
        }

        // Validate email
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: $toEmail");
            return false;
        }

        // Prepare headers
        $headers = "From: Impact Digital Academy <admin@impactdigitalacademy.com.ng>\r\n";
        $headers .= "Reply-To: admin@impactdigitalacademy.com.ng\r\n";
        $headers .= "Return-Path: admin@impactdigitalacademy.com.ng\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "X-Priority: 3\r\n";

        // Set content type
        if ($isHTML) {
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }

        // Add additional headers for better deliverability
        $headers .= "X-Mailer-Info: Impact Digital Academy Portal\r\n";
        $headers .= "X-Originating-IP: " . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . "\r\n";

        // Clean subject (remove any line breaks)
        $subject = str_replace(["\r", "\n"], '', $subject);
        $subject = trim($subject);

        // Ensure body is properly formatted
        if ($isHTML) {
            // Make sure HTML has proper structure
            if (stripos($body, '<html') === false) {
                $body = "<!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <title>{$subject}</title>
                </head>
                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0;'>
                    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                        {$body}
                    </div>
                </body>
                </html>";
            }
        }

        // Log the attempt
        error_log("Attempting to send email via PHP mail() to: $toEmail");

        // Send email with additional parameters for better delivery
        $additional_params = "-f admin@impactdigitalacademy.com.ng";

        // Try sending with additional parameters first
        if (function_exists('mail')) {
            $result = mail($toEmail, $subject, $body, $headers, $additional_params);

            // If that fails, try without additional parameters
            if (!$result) {
                $result = mail($toEmail, $subject, $body, $headers);
            }
        } else {
            error_log("mail() function is not available");
            return false;
        }

        if ($result) {
            error_log("Email sent successfully to: $toEmail");
            logActivity('email_sent', "Email sent via PHP mail() to: " . $toEmail);
            return true;
        } else {
            $error = error_get_last();
            error_log("PHP mail() failed to send to: $toEmail. Error: " . ($error['message'] ?? 'Unknown error'));
            logActivity('email_failed', "Failed to send email via PHP mail() to: " . $toEmail);
            return false;
        }
    } catch (Exception $e) {
        error_log("Email send exception: " . $e->getMessage());
        logActivity('email_failed', "Exception sending email: " . $e->getMessage());
        return false;
    }
}

/**
 * Test email configuration
 */
function testEmailConfiguration($test_email = null)
{
    if (!$test_email) {
        $test_email = 'admin@impactdigitalacademy.com.ng'; // Send to yourself for testing
    }

    $subject = "Test Email from Impact Digital Academy";
    $body = "<h2>Email Configuration Test</h2>
            <p>This is a test email to verify that the email system is working correctly.</p>
            <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</p>
            <p>If you received this email, your email configuration is working!</p>";

    return sendEmail($test_email, $subject, $body);
}

/**
 * Send welcome email to new user
 */
function sendWelcomeEmail($user_id, $password = null)
{
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Welcome email failed: User not found or no email for ID: $user_id");
        return false;
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "Welcome to $school_name";
    $login_url = BASE_URL . 'modules/auth/login.php';

    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
            <h1 style='margin: 0;'>Welcome to $school_name</h1>
        </div>
        
        <div style='background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 10px 10px;'>
            <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
            
            <p>Your account has been successfully created! Here are your account details:</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2563eb;'>
                <p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>
                <p><strong>Role:</strong> " . ucfirst($user['role']) . "</p>
                " . ($password ? "<p><strong>Temporary Password:</strong> <code style='background: #f1f5f9; padding: 5px; border-radius: 4px;'>" . htmlspecialchars($password) . "</code></p>" : "") . "
            </div>
            
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$login_url}' style='background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>Login to Your Account</a>
            </p>
            
            <p><strong>Next Steps:</strong></p>
            <ol style='background: white; padding: 20px 20px 20px 40px; border-radius: 8px;'>
                <li>Login using your email and " . ($password ? "temporary password" : "password") . "</li>
                <li>" . ($password ? "Change your temporary password" : "Complete your profile") . "</li>
                <li>Explore your dashboard and available courses</li>
            </ol>
            
            <p>If you have any questions, please contact our support team.</p>
            
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
            
            <p style='color: #64748b; font-size: 12px; text-align: center;'>
                &copy; " . date('Y') . " $school_name. All rights reserved.<br>
                This is an automated message, please do not reply.
            </p>
        </div>
    </div>";

    return sendEmail($user['email'], $subject, $body);
}

/**
 * Send password reset email - FIXED VERSION
 */
function sendPasswordResetEmail($email, $token)
{
    $conn = getDBConnection();

    try {
        // Check if email exists in users table
        $sql = "SELECT id, first_name, email FROM users WHERE email = ? AND status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {
            error_log("Password reset: User not found or inactive: $email");
            return false;
        }

        // Calculate expiry (2 hours from now)
        $expires_at = date('Y-m-d H:i:s', strtotime('+2 hours'));

        // Save reset token to database
        $sql = "INSERT INTO password_resets (email, token, expires_at, created_at) 
                VALUES (?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                token = VALUES(token), 
                expires_at = VALUES(expires_at),
                used = 0,
                created_at = NOW()";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $email, $token, $expires_at);

        if (!$stmt->execute()) {
            error_log("Password reset: Failed to save token: " . $stmt->error);
            return false;
        }

        // Create reset URL
        $reset_url = BASE_URL . "modules/auth/reset-password.php?token=" . urlencode($token);
        $expiry_hours = 2;

        $subject = "Password Reset Request - Impact Digital Academy";

        // HTML email template
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
                .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .warning { color: #dc2626; font-weight: bold; }
                .reset-link { background: #f1f5f9; padding: 15px; border-radius: 5px; word-break: break-all; font-family: monospace; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Password Reset Request</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                    
                    <p>We received a request to reset your password for your Impact Digital Academy account. If you didn't make this request, you can safely ignore this email.</p>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='{$reset_url}' class='button'>Reset Your Password</a>
                    </p>
                    
                    <p>Or copy and paste this link in your browser:</p>
                    <div class='reset-link'>{$reset_url}</div>
                    
                    <p class='warning' style='margin-top: 20px;'>⚠ This link will expire in {$expiry_hours} hours.</p>
                    
                    <p>For security reasons, this password reset link can only be used once. If you need to reset your password again, please request a new reset link.</p>
                    
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                    
                    <p style='color: #64748b; font-size: 13px;'>
                        If you didn't request this password reset, please ignore this email or contact support if you have concerns about your account security.
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                    <p>This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>";

        // Send the email
        $email_result = sendEmail($email, $subject, $body);

        if ($email_result) {
            error_log("Password reset email sent to: $email");
            logActivity('password_reset_requested', "Password reset requested for: $email", 'users', $user['id']);
            return true;
        } else {
            error_log("Failed to send password reset email to: $email");
            return false;
        }
    } catch (Exception $e) {
        error_log("Error in sendPasswordResetEmail: " . $e->getMessage());
        return false;
    }
}

/**
 * Send assignment notification email - FIXED VERSION
 */
function sendAssignmentNotificationEmail($assignment_id, $conn = null, $assignment = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get assignment details if not provided
    if (!$assignment) {
        $sql = "SELECT a.*, cb.batch_code, c.title as course_title, 
                       c.course_code, u.email as instructor_email
                FROM assignments a 
                JOIN class_batches cb ON a.class_id = cb.id 
                JOIN courses c ON cb.course_id = c.id 
                JOIN users u ON cb.instructor_id = u.id 
                WHERE a.id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignment = $result->fetch_assoc();
        $stmt->close();
    }

    if (!$assignment) {
        error_log("Assignment notification email failed: Assignment not found for ID: $assignment_id");
        return 0;
    }

    // Get enrolled students with valid emails
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name 
            FROM enrollments e 
            JOIN users u ON e.student_id = u.id 
            WHERE e.class_id = ? AND e.status = 'active' 
            AND u.email IS NOT NULL AND u.email != '' AND u.email LIKE '%@%.%'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment['class_id']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        error_log("Assignment notification email failed: No students found for class ID: " . $assignment['class_id']);
        return 0;
    }

    $notification_count = 0;
    $due_date = formatDate($assignment['due_date'], 'F j, Y g:i A');
    $course_link = BASE_URL . "modules/student/course.php?id=" . $assignment['class_id'];

    foreach ($students as $student) {
        if (empty($student['email'])) continue;

        $subject = "New Assignment: " . $assignment['title'] . " - " . $assignment['course_title'];

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4a6fa5; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #4a6fa5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .deadline { color: #d9534f; font-weight: bold; }
                .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4a6fa5; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>New Assignment Posted</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                    
                    <p>A new assignment has been posted for your course: <strong>" . htmlspecialchars($assignment['course_title']) . "</strong></p>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #4a6fa5;'>Assignment Details:</h3>
                        <p><strong>Title:</strong> " . htmlspecialchars($assignment['title']) . "</p>
                        <p><strong>Course:</strong> " . htmlspecialchars($assignment['course_title']) . " (" . htmlspecialchars($assignment['course_code']) . ")</p>
                        <p><strong>Batch:</strong> " . htmlspecialchars($assignment['batch_code']) . "</p>
                        <p class='deadline'><strong>Due Date:</strong> " . $due_date . "</p>
                    </div>
                    
                    <p>Please submit your assignment before the due date. Late submissions may be subject to grade deductions.</p>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='{$course_link}' class='button'>View Assignment Details</a>
                    </p>
                    
                    <p>If you have any questions, please contact your instructor.</p>
                    
                    <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                    
                    <p style='color: #666; font-size: 13px;'>
                        This is an automated notification from your learning portal. Please do not reply to this email.
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($student['email'], $subject, $body)) {
            $notification_count++;
        }
    }

    logActivity('assignment_email_sent', "Assignment notification emails sent for assignment #{$assignment_id} to {$notification_count} students");
    return $notification_count;
}

/**
 * Send invoice/payment reminder email - FIXED VERSION
 */
function sendInvoiceEmail($invoice_id, $type = 'new', $conn = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get invoice details
    $sql = "SELECT i.*, u.email, u.first_name, u.last_name,
                   c.title as course_title, cb.batch_code
            FROM invoices i
            JOIN users u ON i.student_id = u.id
            LEFT JOIN class_batches cb ON i.class_id = cb.id
            LEFT JOIN courses c ON cb.course_id = c.id
            WHERE i.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();

    if (!$invoice || empty($invoice['email'])) {
        error_log("Invoice email failed: Invoice not found or no email for ID: $invoice_id");
        return false;
    }

    $due_date = formatDate($invoice['due_date'], 'F j, Y');
    $amount = formatCurrency($invoice['amount']);
    $payment_url = BASE_URL . "modules/student/payment.php?invoice=" . $invoice['invoice_number'];

    // Set colors and titles based on invoice type
    switch ($type) {
        case 'new':
            $bg_color = '#337ab7';
            $subject = "New Invoice: " . $invoice['invoice_number'] . " - Impact Digital Academy";
            $title = "New Invoice Generated";
            $message = "A new invoice has been generated for your account.";
            break;
        case 'reminder':
            $bg_color = '#f0ad4e';
            $subject = "Payment Reminder: Invoice " . $invoice['invoice_number'] . " - Due Soon";
            $title = "Payment Reminder";
            $message = "This is a friendly reminder that your invoice is due soon.";
            break;
        case 'overdue':
            $bg_color = '#d9534f';
            $subject = "URGENT: Invoice " . $invoice['invoice_number'] . " is Overdue";
            $title = "Invoice Overdue - Immediate Action Required";
            $message = "Your invoice is now overdue. Please make payment immediately to avoid account suspension.";
            break;
        default:
            $bg_color = '#337ab7';
            $subject = "Invoice: " . $invoice['invoice_number'];
            $title = "Invoice";
            $message = "Invoice details";
    }

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: {$bg_color}; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .invoice-box { background: white; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .invoice-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #eee; }
            .invoice-label { font-weight: bold; color: #555; }
            .invoice-value { font-weight: bold; }
            .total-row { background: #f8f9fa; font-size: 18px; font-weight: bold; border-bottom: none; }
            .button { display: inline-block; background: {$bg_color}; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>{$title}</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($invoice['first_name']) . ",</p>
                
                <p>{$message}</p>
                
                <div class='invoice-box'>
                    <div class='invoice-row'>
                        <span class='invoice-label'>Invoice Number:</span>
                        <span class='invoice-value'>" . htmlspecialchars($invoice['invoice_number']) . "</span>
                    </div>
                    <div class='invoice-row'>
                        <span class='invoice-label'>Description:</span>
                        <span class='invoice-value'>" . htmlspecialchars($invoice['description']) . "</span>
                    </div>";

    if ($invoice['course_title']) {
        $body .= "<div class='invoice-row'>
                    <span class='invoice-label'>Course:</span>
                    <span class='invoice-value'>" . htmlspecialchars($invoice['course_title']) . " (" . htmlspecialchars($invoice['batch_code']) . ")</span>
                  </div>";
    }

    $body .= "<div class='invoice-row'>
                <span class='invoice-label'>Amount Due:</span>
                <span class='invoice-value' style='color: {$bg_color};'>{$amount}</span>
              </div>
              <div class='invoice-row'>
                <span class='invoice-label'>Due Date:</span>
                <span class='invoice-value'>" . ($type == 'overdue' ? '<span style="color: #d9534f;">' . $due_date . ' (OVERDUE)</span>' : $due_date) . "</span>
              </div>
              <div class='invoice-row total-row'>
                <span class='invoice-label'>Total Amount:</span>
                <span class='invoice-value' style='color: {$bg_color};'>{$amount}</span>
              </div>
            </div>
            
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$payment_url}' class='button'>Make Payment Now</a>
            </p>
            
            <p><strong>Payment Options:</strong></p>
            <ul style='background: white; padding: 20px 20px 20px 40px; border-radius: 8px;'>
                <li>Pay online via our secure payment portal</li>
                <li>Bank transfer to our official account (details available upon request)</li>
                <li>Visit our finance office for in-person payment</li>
            </ul>
            
            <p>If you have already made this payment, please disregard this email. For payment questions, contact our finance department.</p>
            
            <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
            
            <p style='color: #666; font-size: 13px;'>
                This is an automated notification from your learning portal. Please do not reply to this email.
            </p>
        </div>
        
        <div class='footer'>
            <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $result = sendEmail($invoice['email'], $subject, $body);

    if ($result) {
        logActivity('invoice_email_sent', "Invoice email ({$type}) sent for invoice #{$invoice_id}");
    } else {
        error_log("Failed to send invoice email for invoice #{$invoice_id}");
    }

    return $result;
}

/**
 * Send bulk emails to multiple recipients
 */
function sendBulkEmail($recipients, $subject, $body, $isHTML = true)
{
    $results = [
        'success' => 0,
        'failed' => 0,
        'failed_emails' => []
    ];

    foreach ($recipients as $email => $name) {
        if (sendEmail($email, $subject, $body, $isHTML)) {
            $results['success']++;
        } else {
            $results['failed']++;
            $results['failed_emails'][] = $email;
        }
    }

    return $results;
}

/**
 * Send system announcement email
 */
function sendAnnouncementEmail($announcement_id, $recipient_type = 'all', $conn = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get announcement details
    $sql = "SELECT a.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as author_name,
                   cb.batch_code,
                   c.title as course_title
            FROM announcements a 
            JOIN users u ON a.author_id = u.id
            LEFT JOIN class_batches cb ON a.class_id = cb.id
            LEFT JOIN courses c ON cb.course_id = c.id
            WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $announcement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $announcement = $result->fetch_assoc();
    $stmt->close();

    if (!$announcement) {
        error_log("Announcement email failed: Announcement not found for ID: $announcement_id");
        return false;
    }

    // Determine recipients
    $recipients = [];

    switch ($recipient_type) {
        case 'all':
            $sql = "SELECT email, first_name FROM users WHERE status = 'active' AND email IS NOT NULL AND email != '' AND email LIKE '%@%.%'";
            $result = $conn->query($sql);
            break;

        case 'students':
            $sql = "SELECT email, first_name FROM users WHERE role = 'student' AND status = 'active' AND email IS NOT NULL AND email != '' AND email LIKE '%@%.%'";
            $result = $conn->query($sql);
            break;

        case 'instructors':
            $sql = "SELECT email, first_name FROM users WHERE role = 'instructor' AND status = 'active' AND email IS NOT NULL AND email != '' AND email LIKE '%@%.%'";
            $result = $conn->query($sql);
            break;

        case 'class':
            if (!$announcement['class_id']) return false;
            $sql = "SELECT u.email, u.first_name 
                    FROM enrollments e 
                    JOIN users u ON e.student_id = u.id 
                    WHERE e.class_id = ? AND e.status = 'active' 
                    AND u.email IS NOT NULL AND u.email != '' AND u.email LIKE '%@%.%'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $announcement['class_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            break;

        default:
            return false;
    }

    while ($row = $result->fetch_assoc()) {
        if (!empty($row['email'])) {
            $recipients[$row['email']] = $row['first_name'];
        }
    }

    if (empty($recipients)) {
        error_log("Announcement email failed: No recipients found for announcement #{$announcement_id}");
        return false;
    }

    // Send emails
    $subject = "Announcement: " . $announcement['title'];
    $announcement_link = BASE_URL . "modules/student/announcements.php";

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #6f42c1; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .announcement-box { background: white; border-left: 4px solid #6f42c1; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .button { display: inline-block; background: #6f42c1; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .meta-info { color: #666; font-size: 14px; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>Important Announcement</h1>
            </div>
            
            <div class='content'>
                <p>Hello,</p>
                
                <p>An important announcement has been posted:</p>
                
                <div class='announcement-box'>
                    <h2 style='margin-top: 0; color: #6f42c1;'>" . htmlspecialchars($announcement['title']) . "</h2>
                    
                    <div class='meta-info'>
                        <p><strong>From:</strong> " . htmlspecialchars($announcement['author_name']) . "</p>";

    if ($announcement['course_title']) {
        $body .= "<p><strong>Course:</strong> " . htmlspecialchars($announcement['course_title']) . " (" . htmlspecialchars($announcement['batch_code']) . ")</p>";
    }

    $body .= "<p><strong>Posted:</strong> " . formatDate($announcement['created_at'], 'F j, Y g:i A') . "</p>
                    </div>
                    
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>
                        " . nl2br(htmlspecialchars($announcement['content'])) . "
                    </div>
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$announcement_link}' class='button'>View All Announcements</a>
                </p>
                
                <p>If you have any questions, please contact the administration.</p>
                
                <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                
                <p style='color: #666; font-size: 13px;'>
                    This is an automated notification from your learning portal. Please do not reply to this email.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    $results = sendBulkEmail($recipients, $subject, $body);

    logActivity('announcement_email_sent', "Announcement email sent to " . count($recipients) . " recipients. Success: {$results['success']}, Failed: {$results['failed']}");

    return $results;
}

/**
 * Send application confirmation email to applicant
 */
function sendApplicationConfirmationEmail($user_id, $application_data = [])
{
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Application confirmation email failed: User not found or no email for ID: $user_id");
        return false;
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "Application Received - Impact Digital Academy";

    // Format program type for display
    $program_type = isset($application_data['program_type']) ? ucfirst($application_data['program_type']) : 'Online';
    $program_name = 'Not specified';

    // Get program name if program_id exists
    if (!empty($application_data['program_id'])) {
        $conn = getDBConnection();
        $sql = "SELECT name FROM programs WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $application_data['program_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $program_name = $row['name'];
        }
        $stmt->close();
    }

    // Format preferred period
    $preferred_period = '';
    if ($program_type === 'Onsite' && !empty($application_data['preferred_term'])) {
        $preferred_period = "Preferred Term: " . $application_data['preferred_term'];
    } elseif ($program_type === 'Online' && !empty($application_data['preferred_block'])) {
        $preferred_period = "Preferred Block: " . $application_data['preferred_block'];
    } elseif ($program_type === 'School' && !empty($application_data['preferred_school_term'])) {
        $preferred_period = "Preferred School Term: " . $application_data['preferred_school_term'];
    }

    // School name if applicable
    $school_info = '';
    if (!empty($application_data['school_name'])) {
        $school_info = "School: " . $application_data['school_name'];
    }

    $login_url = BASE_URL . 'modules/auth/login.php';

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
            .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2563eb; }
            .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .status-badge { background: #f59e0b; color: #1e293b; padding: 5px 15px; border-radius: 20px; font-size: 14px; font-weight: bold; display: inline-block; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>Application Received!</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                
                <p>Thank you for applying to <strong>$school_name</strong>! We have received your application and it is now under review.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #2563eb;'>Application Details:</h3>
                    <p><strong>Application ID:</strong> #" . $user_id . date('Ymd') . "</p>
                    <p><strong>Name:</strong> " . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>
                    <p><strong>Program Type:</strong> " . $program_type . " Program</p>
                    <p><strong>Program:</strong> " . htmlspecialchars($program_name) . "</p>
                    " . (!empty($school_info) ? "<p><strong>$school_info</strong></p>" : "") . "
                    " . (!empty($preferred_period) ? "<p><strong>$preferred_period</strong></p>" : "") . "
                    <p><strong>Status:</strong> <span class='status-badge'>Pending Review</span></p>
                </div>
                
                <p><strong>What happens next?</strong></p>
                <ol style='background: white; padding: 20px 20px 20px 40px; border-radius: 8px;'>
                    <li>Our admissions team will review your application</li>
                    <li>You'll receive an email notification once your application is approved</li>
                    <li>After approval, you can log in to complete your registration and make payment</li>
                </ol>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$login_url}' class='button'>Track Your Application</a>
                </p>
                
                <p>If you have any questions, please contact our admissions office.</p>
                
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                
                <p style='color: #64748b; font-size: 13px;'>
                    This is an automated message from your learning portal. Please do not reply to this email.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " $school_name. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($user['email'], $subject, $body);
}

/**
 * Send application approval email to student
 */
function sendApplicationApprovalEmail($user_id)
{
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Application approval email failed: User not found or no email for ID: $user_id");
        return false;
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "Congratulations! Your Application has been Approved - Impact Digital Academy";
    $login_url = BASE_URL . 'modules/auth/login.php';
    $payment_url = BASE_URL . 'modules/student/make-payment.php';

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #10b981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
            .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
            .success-box { background: #d1fae5; border: 2px solid #10b981; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
            .button { display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 5px; }
            .button-secondary { background: #2563eb; }
            .steps { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>Application Approved! 🎉</h1>
            </div>
            
            <div class='content'>
                <div class='success-box'>
                    <h2 style='color: #065f46; margin: 0;'>Congratulations " . htmlspecialchars($user['first_name']) . "!</h2>
                </div>
                
                <p>We are pleased to inform you that your application to <strong>$school_name</strong> has been <strong style='color: #10b981;'>APPROVED</strong>.</p>
                
                <div class='steps'>
                    <h3 style='color: #2563eb; margin-top: 0;'>Next Steps:</h3>
                    <ol style='margin-bottom: 0;'>
                        <li><strong>Login to Your Account</strong> - Use your email and password to access your dashboard</li>
                        <li><strong>Complete Your Registration</strong> - Fill out any remaining required information</li>
                        <li><strong>Make Payment</strong> - Pay your registration fee to secure your spot</li>
                        <li><strong>Start Learning</strong> - Access your courses and begin your journey</li>
                    </ol>
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$login_url}' class='button button-secondary'>Login to Dashboard</a>
                    <a href='{$payment_url}' class='button'>Make Payment</a>
                </p>
                
                <p><strong>Important:</strong> Please complete your registration and payment within 7 days to secure your spot in the upcoming session.</p>
                
                <p>If you have any questions, please contact our admissions office.</p>
                
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                
                <p style='color: #64748b; font-size: 13px;'>
                    Welcome to the $school_name family! We're excited to have you on board.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " $school_name. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($user['email'], $subject, $body);
}

/**
 * Send application rejection email to applicant
 */
function sendApplicationRejectionEmail($user_id, $reason = '')
{
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Application rejection email failed: User not found or no email for ID: $user_id");
        return false;
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "Update on Your Application - Impact Digital Academy";

    $reason_text = !empty($reason) ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "";

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #64748b; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
            .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
            .info-box { background: #fee2e2; border: 1px solid #ef4444; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>Application Status Update</h1>
            </div>
            
            <div class='content'>
                <p>Dear " . htmlspecialchars($user['first_name']) . ",</p>
                
                <p>Thank you for your interest in <strong>$school_name</strong>.</p>
                
                <div class='info-box'>
                    <p style='margin: 0;'>After careful review of your application, we regret to inform you that we are unable to offer you admission at this time.</p>
                    $reason_text
                </div>
                
                <p>This decision does not diminish your potential, and we encourage you to:</p>
                <ul>
                    <li>Consider applying for our next intake</li>
                    <li>Explore our short courses and workshops</li>
                    <li>Strengthen your application with additional qualifications</li>
                </ul>
                
                <p>If you would like feedback on your application or have any questions, please contact our admissions office.</p>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='mailto:admissions@impactdigitalacademy.com.ng' class='button'>Contact Admissions</a>
                </p>
                
                <p>We wish you success in your future endeavors.</p>
                
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " $school_name. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($user['email'], $subject, $body);
}

// ===== ADD TO functions.php =====

/**
 * Send weekly reminder to online students about new content
 * Run this via cron job every Monday morning
 */
function sendWeeklyReminderToOnlineStudents()
{
    $conn = getDBConnection();

    // Get all active online students with their classes
    $sql = "SELECT DISTINCT 
                u.id as student_id,
                u.email,
                u.first_name,
                u.last_name,
                cb.id as class_id,
                cb.batch_code,
                c.title as course_title,
                c.course_code,
                p.name as program_name
            FROM users u
            JOIN enrollments e ON u.id = e.student_id
            JOIN class_batches cb ON e.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN programs p ON c.program_id = p.id
            WHERE u.role = 'student' 
                AND u.status = 'active'
                AND e.status = 'active'
                AND e.program_type = 'online'
                AND u.email IS NOT NULL 
                AND u.email != ''";

    $result = $conn->query($sql);
    $students = $result->fetch_all(MYSQLI_ASSOC);

    if (empty($students)) {
        logActivity('weekly_reminder', 'No online students found for weekly reminder');
        return 0;
    }

    $sent_count = 0;
    $week_number = date('W');
    $week_dates = getWeekDates();

    foreach ($students as $student) {
        // Get this week's materials, assignments, and quizzes
        $materials = getWeeklyMaterials($student['class_id'], $week_number);
        $assignments = getWeeklyAssignments($student['class_id']);
        $quizzes = getWeeklyQuizzes($student['class_id']);

        // Only send if there's content for the week
        if (!empty($materials) || !empty($assignments) || !empty($quizzes)) {
            if (sendWeeklyReminderEmail($student, $materials, $assignments, $quizzes, $week_dates)) {
                $sent_count++;
            }
        }
    }

    logActivity('weekly_reminder', "Weekly reminders sent to {$sent_count} online students");
    return $sent_count;
}

/**
 * Get materials for the current week
 */
function getWeeklyMaterials($class_id, $week_number)
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM course_materials 
            WHERE class_id = ? AND week_number = ? AND is_published = 1
            ORDER BY order_number";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $week_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $materials = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $materials;
}

/**
 * Get assignments for the current week
 */
function getWeeklyAssignments($class_id)
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM assignments 
            WHERE class_id = ? AND is_published = 1
            AND WEEK(due_date) = WEEK(NOW())
            ORDER BY due_date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $assignments;
}

/**
 * Get quizzes for the current week
 */
function getWeeklyQuizzes($class_id)
{
    $conn = getDBConnection();

    $sql = "SELECT * FROM quizzes 
            WHERE class_id = ? AND is_published = 1
            AND WEEK(available_from) = WEEK(NOW())
            ORDER BY available_from";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quizzes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $quizzes;
}

/**
 * Get start and end dates for current week
 */
function getWeekDates()
{
    $start = new DateTime();
    $start->modify('monday this week');
    $end = new DateTime();
    $end->modify('sunday this week');

    return [
        'start' => $start->format('M j'),
        'end' => $end->format('M j, Y')
    ];
}

/**
 * Send weekly reminder email
 */
function sendWeeklyReminderEmail($student, $materials, $assignments, $quizzes, $week_dates)
{
    $subject = "📚 Week " . date('W') . " Learning Materials - " . htmlspecialchars($student['course_title']);

    // Build materials list HTML
    $materials_html = '';
    if (!empty($materials)) {
        $materials_html = '<div style="margin: 15px 0; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #3b82f6;">
            <h4 style="color: #1e293b; margin: 0 0 10px 0;"><i class="fas fa-book"></i> 📖 New Materials This Week:</h4>
            <ul style="list-style: none; padding: 0; margin: 0;">';
        foreach ($materials as $material) {
            $materials_html .= '<li style="padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                <span style="font-weight: 600;">' . htmlspecialchars($material['title']) . '</span><br>
                <span style="color: #64748b; font-size: 0.875rem;">' . htmlspecialchars($material['description']) . '</span>
            </li>';
        }
        $materials_html .= '</ul></div>';
    }

    // Build assignments list HTML
    $assignments_html = '';
    if (!empty($assignments)) {
        $assignments_html = '<div style="margin: 15px 0; padding: 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;">
            <h4 style="color: #1e293b; margin: 0 0 10px 0;"><i class="fas fa-tasks"></i> 📝 Assignments Due This Week:</h4>
            <ul style="list-style: none; padding: 0; margin: 0;">';
        foreach ($assignments as $assignment) {
            $due_date = date('M j, g:i a', strtotime($assignment['due_date']));
            $days_left = floor((strtotime($assignment['due_date']) - time()) / 86400);
            $urgency = $days_left < 2 ? '<span style="color: #ef4444; font-weight: 600;"> (Due Soon!)</span>' : '';

            $assignments_html .= '<li style="padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                <span style="font-weight: 600;">' . htmlspecialchars($assignment['title']) . '</span>' . $urgency . '<br>
                <span style="color: #64748b;">Due: ' . $due_date . ' | Points: ' . $assignment['total_points'] . '</span>
            </li>';
        }
        $assignments_html .= '</ul></div>';
    }

    // Build quizzes list HTML
    $quizzes_html = '';
    if (!empty($quizzes)) {
        $quizzes_html = '<div style="margin: 15px 0; padding: 15px; background: #e0f2fe; border-radius: 8px; border-left: 4px solid #0ea5e9;">
            <h4 style="color: #1e293b; margin: 0 0 10px 0;"><i class="fas fa-question-circle"></i> ❓ Quizzes Available This Week:</h4>
            <ul style="list-style: none; padding: 0; margin: 0;">';
        foreach ($quizzes as $quiz) {
            $available_from = date('M j', strtotime($quiz['available_from']));
            $available_to = date('M j, g:i a', strtotime($quiz['available_to']));

            $quizzes_html .= '<li style="padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                <span style="font-weight: 600;">' . htmlspecialchars($quiz['title']) . '</span><br>
                <span style="color: #64748b;">Available: ' . $available_from . ' - ' . $available_to . ' | ' . $quiz['total_points'] . ' points</span>
            </li>';
        }
        $quizzes_html .= '</ul></div>';
    }

    $class_link = BASE_URL . "modules/student/class_home.php?id=" . $student['class_id'];

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .week-summary { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>Welcome to Week " . date('W') . "! 🎉</h1>
                <p style='margin: 10px 0 0; opacity: 0.9;'>" . $week_dates['start'] . " - " . $week_dates['end'] . "</p>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                
                <p>Welcome to a new week in <strong>" . htmlspecialchars($student['course_title']) . "</strong>! We've prepared new learning materials for you.</p>
                
                <div class='week-summary'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>📊 This Week's Overview:</h3>
                    <ul style='margin: 0; padding-left: 20px;'>
                        <li><strong>" . count($materials) . "</strong> new learning materials</li>
                        <li><strong>" . count($assignments) . "</strong> assignment" . (count($assignments) != 1 ? 's' : '') . " to complete</li>
                        <li><strong>" . count($quizzes) . "</strong> quiz" . (count($quizzes) != 1 ? 'zes' : '') . " available</li>
                    </ul>
                </div>
                
                $materials_html
                $assignments_html
                $quizzes_html
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$class_link}' class='button'>Go to Your Class Dashboard</a>
                </p>
                
                <p style='color: #64748b; font-size: 13px; margin-top: 20px;'>
                    <i class='fas fa-lightbulb'></i> <strong>Tip:</strong> Check your class dashboard daily to stay on top of all assignments and discussions!
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                <p style='font-size: 11px;'>You're receiving this because you're enrolled in " . htmlspecialchars($student['course_title']) . ".</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($student['email'], $subject, $body);
}

/**
 * Check for upcoming deadlines and send reminders
 * Run this function every few hours via cron
 */
function checkAndSendDeadlineReminders()
{
    $conn = getDBConnection();
    $notifications_sent = 0;

    // Check assignments due in next 48 hours
    $sql = "SELECT 
                a.*,
                cb.batch_code,
                c.title as course_title,
                e.student_id,
                u.email,
                u.first_name,
                u.last_name,
                CONCAT(u2.first_name, ' ', u2.last_name) as instructor_name
            FROM assignments a
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            JOIN enrollments e ON cb.id = e.class_id
            JOIN users u ON e.student_id = u.id
            JOIN users u2 ON cb.instructor_id = u2.id
            WHERE a.is_published = 1
                AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
                AND e.status = 'active'
                AND u.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM assignment_submissions s 
                    WHERE s.assignment_id = a.id AND s.student_id = e.student_id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.user_id = u.id 
                        AND n.related_id = a.id 
                        AND n.type = 'assignment_reminder'
                        AND n.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                )";

    $result = $conn->query($sql);
    $upcoming_assignments = $result->fetch_all(MYSQLI_ASSOC);

    foreach ($upcoming_assignments as $assignment) {
        if (sendDeadlineReminderEmail($assignment, 'assignment')) {
            $notifications_sent++;

            // Log the reminder to avoid duplicate sends
            $sql_log = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                       VALUES (?, ?, ?, 'assignment_reminder', ?, NOW())";
            $stmt = $conn->prepare($sql_log);
            $title = "Reminder: Assignment Due Soon";
            $message = "Your assignment '{$assignment['title']}' is due soon";
            $stmt->bind_param("issi", $assignment['student_id'], $title, $message, $assignment['id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Check quizzes due in next 48 hours
    $sql_quizzes = "SELECT 
                        q.*,
                        cb.batch_code,
                        c.title as course_title,
                        e.student_id,
                        u.email,
                        u.first_name,
                        u.last_name,
                        CONCAT(u2.first_name, ' ', u2.last_name) as instructor_name
                    FROM quizzes q
                    JOIN class_batches cb ON q.class_id = cb.id
                    JOIN courses c ON cb.course_id = c.id
                    JOIN enrollments e ON cb.id = e.class_id
                    JOIN users u ON e.student_id = u.id
                    JOIN users u2 ON cb.instructor_id = u2.id
                    WHERE q.is_published = 1
                        AND q.available_to BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
                        AND e.status = 'active'
                        AND u.status = 'active'
                        AND NOT EXISTS (
                            SELECT 1 FROM quiz_attempts qa 
                            WHERE qa.quiz_id = q.id AND qa.student_id = e.student_id AND qa.status = 'completed'
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM notifications n 
                            WHERE n.user_id = u.id 
                                AND n.related_id = q.id 
                                AND n.type = 'quiz_reminder'
                                AND n.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        )";

    $result_quizzes = $conn->query($sql_quizzes);
    $upcoming_quizzes = $result_quizzes->fetch_all(MYSQLI_ASSOC);

    foreach ($upcoming_quizzes as $quiz) {
        if (sendDeadlineReminderEmail($quiz, 'quiz')) {
            $notifications_sent++;

            $sql_log = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                       VALUES (?, ?, ?, 'quiz_reminder', ?, NOW())";
            $stmt = $conn->prepare($sql_log);
            $title = "Reminder: Quiz Due Soon";
            $message = "Your quiz '{$quiz['title']}' is due soon";
            $stmt->bind_param("issi", $quiz['student_id'], $title, $message, $quiz['id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    logActivity('deadline_reminders', "Sent {$notifications_sent} deadline reminder emails");
    return $notifications_sent;
}

/**
 * Send deadline reminder email
 */
function sendDeadlineReminderEmail($item, $type)
{
    $is_assignment = ($type === 'assignment');
    $due_date = date('F j, Y g:i A', strtotime($item['due_date'] ?? $item['available_to']));
    $hours_left = round((strtotime($item['due_date'] ?? $item['available_to']) - time()) / 3600, 1);

    $subject = $is_assignment
        ? "⏰ Reminder: Assignment Due in {$hours_left} hours"
        : "⏰ Reminder: Quiz Closes in {$hours_left} hours";

    $item_type = $is_assignment ? 'Assignment' : 'Quiz';
    $item_title = $item['title'];
    $course_title = $item['course_title'];
    $points = $item['total_points'];

    $action_link = $is_assignment
        ? BASE_URL . "modules/student/classes/assignments.php?class_id=" . $item['class_id']
        : BASE_URL . "modules/student/classes/quizzes/quizzes.php?class_id=" . $item['class_id'];

    $urgency_color = $hours_left < 6 ? '#ef4444' : '#f59e0b';
    $urgency_text = $hours_left < 6
        ? "<span style='color: #ef4444; font-weight: 600;'>⚠️ URGENT: Less than 6 hours remaining!</span>"
        : "";

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: {$urgency_color}; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: {$urgency_color}; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .info-box { background: white; border-left: 4px solid {$urgency_color}; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0; }
            .deadline { font-size: 24px; font-weight: bold; color: {$urgency_color}; text-align: center; padding: 15px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>⏰ Deadline Reminder</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($item['first_name']) . ",</p>
                
                <p>This is a friendly reminder about an upcoming {$item_type}:</p>
                
                <div class='info-box'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>{$item_title}</h3>
                    <p><strong>Course:</strong> " . htmlspecialchars($course_title) . "</p>
                    <p><strong>Due:</strong> {$due_date}</p>
                    <p><strong>Points:</strong> {$points}</p>
                </div>
                
                <div class='deadline'>
                    ⏰ Due in {$hours_left} hours
                </div>
                
                {$urgency_text}
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$action_link}' class='button'>Submit Now</a>
                </p>
                
                <p style='color: #64748b; font-size: 13px;'>
                    <i class='fas fa-lightbulb'></i> Don't wait until the last minute - technical issues can occur!
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($item['email'], $subject, $body);
}

/**
 * Ensure the grade notification email function exists and works
 */
function sendGradeNotificationEmail($student_id, $assignment_id, $grade, $conn)
{
    // Get student details
    $student = getUserById($student_id);
    if (!$student || empty($student['email'])) {
        error_log("Grade notification email failed: Student not found or no email for ID: $student_id");
        return false;
    }

    // Get assignment details
    $sql = "SELECT a.*, c.title as course_title, c.course_code, cb.id as class_id,
                   CONCAT(u.first_name, ' ', u.last_name) as instructor_name
            FROM assignments a 
            JOIN courses c ON a.course_id = c.id 
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN users u ON cb.instructor_id = u.id
            WHERE a.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignment = $result->fetch_assoc();
    $stmt->close();

    if (!$assignment) {
        error_log("Grade notification email failed: Assignment not found for ID: $assignment_id");
        return false;
    }

    $percentage = ($grade / $assignment['total_points']) * 100;
    $grade_letter = calculateGradeLetter($percentage);

    // Determine grade color class
    $grade_color = '#10b981'; // default green
    if ($percentage >= 90) $grade_color = '#059669';
    elseif ($percentage >= 80) $grade_color = '#3b82f6';
    elseif ($percentage >= 70) $grade_color = '#f59e0b';
    elseif ($percentage >= 60) $grade_color = '#f97316';
    else $grade_color = '#ef4444';

    $subject = "📊 Grade Posted: " . $assignment['title'] . " - " . $assignment['course_title'];
    $course_link = BASE_URL . "modules/student/classes/assignments.php?class_id=" . $assignment['class_id'];

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .grade-box { background: white; border: 2px solid {$grade_color}; border-radius: 10px; padding: 20px; margin: 20px 0; text-align: center; }
            .grade-number { font-size: 48px; font-weight: bold; color: {$grade_color}; }
            .grade-letter { font-size: 24px; color: {$grade_color}; margin-top: 10px; }
            .grade-details { color: #64748b; margin-top: 10px; }
            .feedback-box { background: #f8fafc; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid {$grade_color}; }
            .button { display: inline-block; background: {$grade_color}; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>📊 Grade Posted</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($student['first_name']) . ",</p>
                
                <p>Your instructor, <strong>" . htmlspecialchars($assignment['instructor_name']) . "</strong>, has posted a grade for your assignment:</p>
                
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($assignment['title']) . "</h3>
                    <p><strong>Course:</strong> " . htmlspecialchars($assignment['course_title']) . " (" . htmlspecialchars($assignment['course_code']) . ")</p>
                </div>
                
                <div class='grade-box'>
                    <div class='grade-number'>" . round($grade, 1) . "/" . round($assignment['total_points'], 1) . "</div>
                    <div class='grade-letter'>Grade: " . $grade_letter . "</div>
                    <div class='grade-details'>" . round($percentage, 1) . "%</div>
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$course_link}' class='button'>View All Grades</a>
                </p>
                
                <p>Keep up the great work! If you have any questions about your grade, you can reply to this email or contact your instructor directly.</p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($student['email'], $subject, $body);
}

/**
 * Send notification when someone replies to a discussion
 */
function sendDiscussionReplyNotification($reply_id, $conn = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get reply details with discussion and author info
    $sql = "SELECT 
                dr.*,
                d.title as discussion_title,
                d.user_id as discussion_author_id,
                d.class_id,
                CONCAT(replier.first_name, ' ', replier.last_name) as replier_name,
                replier.role as replier_role,
                CONCAT(author.first_name, ' ', author.last_name) as discussion_author_name,
                author.email as discussion_author_email,
                cb.batch_code,
                c.title as course_title
            FROM discussion_replies dr
            JOIN discussions d ON dr.discussion_id = d.id
            JOIN users replier ON dr.user_id = replier.id
            JOIN users author ON d.user_id = author.id
            JOIN class_batches cb ON d.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            WHERE dr.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $reply_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $reply = $result->fetch_assoc();
    $stmt->close();

    $notifications_sent = 0;

    // 1. Notify the discussion author (if they're not the one replying)
    if ($reply['discussion_author_id'] != $reply['user_id']) {
        $subject = "💬 New Reply to Your Discussion: " . $reply['discussion_title'];

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #3b82f6; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .reply-box { background: white; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .button { display: inline-block; background: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
                .badge-instructor { background: #3b82f6; color: white; }
                .badge-student { background: #10b981; color: white; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>💬 New Discussion Reply</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($reply['discussion_author_name']) . ",</p>
                    
                    <p><strong>" . htmlspecialchars($reply['replier_name']) . "</strong> 
                    <span class='badge " . ($reply['replier_role'] === 'instructor' ? 'badge-instructor' : 'badge-student') . "'>" . ucfirst($reply['replier_role']) . "</span> 
                    has replied to your discussion in <strong>" . htmlspecialchars($reply['course_title']) . " (" . htmlspecialchars($reply['batch_code']) . ")</strong>:</p>
                    
                    <div class='reply-box'>
                        <h4 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($reply['discussion_title']) . "</h4>
                        <p style='color: #4b5563;'>" . nl2br(htmlspecialchars(substr($reply['content'], 0, 200))) . (strlen($reply['content']) > 200 ? '...' : '') . "</p>
                    </div>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='" . BASE_URL . "modules/student/classes/discussions.php?class_id=" . $reply['class_id'] . "&action=view&id=" . $reply['discussion_id'] . "' class='button'>View Reply</a>
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($reply['discussion_author_email'], $subject, $body)) {
            $notifications_sent++;

            // Create in-app notification
            $sql_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                         VALUES (?, ?, ?, 'discussion_reply', ?, NOW())";
            $stmt_notif = $conn->prepare($sql_notif);
            $title = "New reply to your discussion";
            $message = $reply['replier_name'] . " replied to '" . $reply['discussion_title'] . "'";
            $stmt_notif->bind_param("issi", $reply['discussion_author_id'], $title, $message, $reply['discussion_id']);
            $stmt_notif->execute();
            $stmt_notif->close();
        }
    }

    // 2. Notify all other participants (students who have replied to this discussion)
    // This ensures everyone stays in the loop
    $sql_participants = "SELECT DISTINCT 
                            u.id,
                            u.email,
                            u.first_name,
                            u.last_name
                        FROM discussion_replies dr2
                        JOIN users u ON dr2.user_id = u.id
                        WHERE dr2.discussion_id = ? 
                            AND dr2.user_id != ? 
                            AND dr2.user_id != ?"; // Exclude current replier and discussion author

    $stmt_participants = $conn->prepare($sql_participants);
    $stmt_participants->bind_param("iii", $reply['discussion_id'], $reply['user_id'], $reply['discussion_author_id']);
    $stmt_participants->execute();
    $participants = $stmt_participants->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_participants->close();

    foreach ($participants as $participant) {
        $subject = "💬 New Activity in Discussion: " . $reply['discussion_title'];

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #10b981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .reply-box { background: white; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .button { display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>💬 New Discussion Activity</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($participant['first_name']) . ",</p>
                    
                    <p><strong>" . htmlspecialchars($reply['replier_name']) . "</strong> has added a new reply to a discussion you're following:</p>
                    
                    <div class='reply-box'>
                        <h4 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($reply['discussion_title']) . "</h4>
                        <p style='color: #4b5563;'>" . nl2br(htmlspecialchars($reply['content'])) . "</p>
                    </div>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='" . BASE_URL . "modules/student/classes/discussions.php?class_id=" . $reply['class_id'] . "&action=view&id=" . $reply['discussion_id'] . "' class='button'>Join the Discussion</a>
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($participant['email'], $subject, $body)) {
            $notifications_sent++;

            // Create in-app notification
            $sql_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                         VALUES (?, ?, ?, 'discussion_reply', ?, NOW())";
            $stmt_notif = $conn->prepare($sql_notif);
            $title = "New reply in discussion";
            $message = $reply['replier_name'] . " replied to '" . $reply['discussion_title'] . "'";
            $stmt_notif->bind_param("issi", $participant['id'], $title, $message, $reply['discussion_id']);
            $stmt_notif->execute();
            $stmt_notif->close();
        }
    }

    logActivity('discussion_reply_notifications', "Sent {$notifications_sent} notifications for reply #{$reply_id}");
    return $notifications_sent;
}

/**
 * Send notification when a new discussion is created
 */
function sendNewDiscussionNotification($discussion_id, $conn = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get discussion details
    $sql = "SELECT 
                d.*,
                CONCAT(u.first_name, ' ', u.last_name) as author_name,
                u.role as author_role,
                cb.batch_code,
                c.title as course_title,
                c.course_code
            FROM discussions d
            JOIN users u ON d.user_id = u.id
            JOIN class_batches cb ON d.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            WHERE d.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $discussion_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $discussion = $result->fetch_assoc();
    $stmt->close();

    // Get all enrolled students and instructor for this class
    $sql_recipients = "SELECT DISTINCT 
                            u.id,
                            u.email,
                            u.first_name,
                            u.last_name,
                            u.role
                        FROM enrollments e
                        JOIN users u ON e.student_id = u.id
                        WHERE e.class_id = ? AND e.status = 'active' AND u.status = 'active'
                        UNION
                        SELECT 
                            u.id,
                            u.email,
                            u.first_name,
                            u.last_name,
                            u.role
                        FROM users u
                        WHERE u.id = ?";

    $stmt_recipients = $conn->prepare($sql_recipients);
    $stmt_recipients->bind_param("ii", $discussion['class_id'], $discussion['user_id']);
    $stmt_recipients->execute();
    $recipients = $stmt_recipients->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_recipients->close();

    $notifications_sent = 0;

    foreach ($recipients as $recipient) {
        // Don't notify the author
        if ($recipient['id'] == $discussion['user_id']) {
            continue;
        }

        $subject = "💬 New Discussion: " . $discussion['title'] . " - " . $discussion['course_title'];

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #8b5cf6; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .discussion-box { background: white; border-left: 4px solid #8b5cf6; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .button { display: inline-block; background: #8b5cf6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
                .badge-instructor { background: #3b82f6; color: white; }
                .badge-student { background: #10b981; color: white; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>💬 New Discussion Started</h1>
                </div>
                
                <div class='content'>
                    <p>Hello " . htmlspecialchars($recipient['first_name']) . ",</p>
                    
                    <p><strong>" . htmlspecialchars($discussion['author_name']) . "</strong> 
                    <span class='badge " . ($discussion['author_role'] === 'instructor' ? 'badge-instructor' : 'badge-student') . "'>" . ucfirst($discussion['author_role']) . "</span> 
                    has started a new discussion in <strong>" . htmlspecialchars($discussion['course_title']) . " (" . htmlspecialchars($discussion['batch_code']) . ")</strong>:</p>
                    
                    <div class='discussion-box'>
                        <h3 style='margin: 0 0 10px 0; color: #1e293b;'>" . htmlspecialchars($discussion['title']) . "</h3>
                        <p style='color: #4b5563;'>" . nl2br(htmlspecialchars(substr($discussion['content'], 0, 200))) . (strlen($discussion['content']) > 200 ? '...' : '') . "</p>
                    </div>
                    
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='" . BASE_URL . "modules/student/classes/discussions.php?class_id=" . $discussion['class_id'] . "&action=view&id=" . $discussion['id'] . "' class='button'>Join the Discussion</a>
                    </p>
                </div>
                
                <div class='footer'>
                    <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        if (sendEmail($recipient['email'], $subject, $body)) {
            $notifications_sent++;

            // Create in-app notification
            $sql_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                         VALUES (?, ?, ?, 'new_discussion', ?, NOW())";
            $stmt_notif = $conn->prepare($sql_notif);
            $title = "New discussion: " . $discussion['title'];
            $message = $discussion['author_name'] . " started a new discussion in " . $discussion['course_title'];
            $stmt_notif->bind_param("issi", $recipient['id'], $title, $message, $discussion['id']);
            $stmt_notif->execute();
            $stmt_notif->close();
        }
    }

    logActivity('new_discussion_notifications', "Sent {$notifications_sent} notifications for new discussion #{$discussion_id}");
    return $notifications_sent;
}

/**
 * Send notification to instructor when student submits an assignment
 */
function sendAssignmentSubmissionNotification($submission_id, $conn = null)
{
    if (!$conn) $conn = getDBConnection();

    // Get submission details with student and assignment info
    $sql = "SELECT 
                s.*,
                a.title as assignment_title,
                a.class_id,
                a.instructor_id,
                a.due_date,
                a.total_points,
                CONCAT(student.first_name, ' ', student.last_name) as student_name,
                student.email as student_email,
                CONCAT(instructor.first_name, ' ', instructor.last_name) as instructor_name,
                instructor.email as instructor_email,
                cb.batch_code,
                c.title as course_title
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            JOIN users student ON s.student_id = student.id
            JOIN users instructor ON a.instructor_id = instructor.id
            JOIN class_batches cb ON a.class_id = cb.id
            JOIN courses c ON cb.course_id = c.id
            WHERE s.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $submission = $result->fetch_assoc();
    $stmt->close();

    $subject = "📝 New Assignment Submission: " . $submission['assignment_title'] . " - " . $submission['student_name'];

    $submission_time = date('F j, Y g:i A', strtotime($submission['submitted_at']));
    $due_time = date('F j, Y g:i A', strtotime($submission['due_date']));
    $is_late = $submission['late_submission'] ? 'Yes' : 'No';

    $grading_link = BASE_URL . "modules/instructor/classes/grade_submission.php?id=" . $submission_id;

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #10b981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #e2e8f0; }
            .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
            .info-label { font-weight: 600; width: 120px; color: #475569; }
            .info-value { flex: 1; }
            .button { display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .warning { color: #ef4444; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>📝 New Assignment Submission</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($submission['instructor_name']) . ",</p>
                
                <p>A student has submitted an assignment for your class <strong>" . htmlspecialchars($submission['course_title']) . " (" . htmlspecialchars($submission['batch_code']) . ")</strong>.</p>
                
                <div class='info-box'>
                    <h3 style='margin: 0 0 15px 0; color: #1e293b;'>Submission Details:</h3>
                    
                    <div class='info-row'>
                        <span class='info-label'>Assignment:</span>
                        <span class='info-value'><strong>" . htmlspecialchars($submission['assignment_title']) . "</strong></span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Student:</span>
                        <span class='info-value'>" . htmlspecialchars($submission['student_name']) . "</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Email:</span>
                        <span class='info-value'>" . htmlspecialchars($submission['student_email']) . "</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Submitted:</span>
                        <span class='info-value'>{$submission_time}</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Due Date:</span>
                        <span class='info-value'>{$due_time}</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Late Submission:</span>
                        <span class='info-value " . ($submission['late_submission'] ? 'warning' : '') . "'><strong>{$is_late}</strong></span>
                    </div>
                </div>";

    if (!empty($submission['submission_text'])) {
        $body .= "
                <div class='info-box'>
                    <h4 style='margin: 0 0 10px 0;'>Student's Text Submission:</h4>
                    <p style='background: #f8fafc; padding: 15px; border-radius: 8px;'>" . nl2br(htmlspecialchars($submission['submission_text'])) . "</p>
                </div>";
    }

    $body .= "
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$grading_link}' class='button'>Grade This Submission</a>
                </p>
                
                <p style='color: #64748b; font-size: 13px;'>
                    <i class='fas fa-clock'></i> This submission is waiting for your review. Please grade it as soon as possible.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " Impact Digital Academy. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    // Send email to instructor
    $email_result = sendEmail($submission['instructor_email'], $subject, $body);

    // Create in-app notification
    $sql_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                  VALUES (?, ?, ?, 'submission', ?, NOW())";
    $stmt_notif = $conn->prepare($sql_notif);
    $title = "New Assignment Submission";
    $message = $submission['student_name'] . " submitted '" . $submission['assignment_title'] . "'";
    $stmt_notif->bind_param("issi", $submission['instructor_id'], $title, $message, $submission_id);
    $notif_result = $stmt_notif->execute();
    $stmt_notif->close();

    if ($email_result && $notif_result) {
        logActivity('submission_notified', "Sent submission notification to instructor #{$submission['instructor_id']} for submission #{$submission_id}");
    }

    return $email_result;
}

/**
 * Send login notification email to user
 */
function sendLoginNotificationEmail($user_id)
{
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Login notification email failed: User not found or no email for ID: $user_id");
        return false;
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "New Login Detected - Impact Digital Academy";

    // Get login details
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
    $login_time = date('F j, Y g:i A');
    $location = getIPLocation($ip_address); // Optional: You can add IP geolocation later

    // Simplify user agent for display
    $browser_info = getBrowserInfo($user_agent);

    $login_url = BASE_URL . 'modules/auth/login-history.php';
    $support_email = getSetting('support_email', 'support@impactdigitalacademy.com.ng');

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
            .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
            .info-box { background: white; border-left: 4px solid #2563eb; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
            .info-label { font-weight: 600; width: 120px; color: #475569; }
            .info-value { flex: 1; color: #1e293b; }
            .info-row:last-child { border-bottom: none; }
            .badge { background: #e2e8f0; padding: 3px 10px; border-radius: 15px; font-size: 12px; color: #475569; }
            .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .warning-box { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>🔐 New Login Detected</h1>
            </div>
            
            <div class='content'>
                <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                
                <p>We detected a new login to your Impact Digital Academy account. If this was you, no action is needed. If you don't recognize this activity, please secure your account immediately.</p>
                
                <div class='info-box'>
                    <h3 style='margin: 0 0 15px 0; color: #1e293b;'>Login Details:</h3>
                    
                    <div class='info-row'>
                        <span class='info-label'>Date & Time:</span>
                        <span class='info-value'><strong>{$login_time}</strong></span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>IP Address:</span>
                        <span class='info-value'><strong>{$ip_address}</strong> <span class='badge'>" . (isPrivateIP($ip_address) ? 'Local Network' : 'Public IP') . "</span></span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Browser:</span>
                        <span class='info-value'>{$browser_info}</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Device Type:</span>
                        <span class='info-value'>" . getDeviceType($user_agent) . "</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='info-label'>Platform:</span>
                        <span class='info-value'>" . getOperatingSystem($user_agent) . "</span>
                    </div>
                </div>";

    // Add location info if available (requires additional service)
    if (!empty($location) && $location !== 'Unknown') {
        $body .= "
                <div class='info-box'>
                    <h4 style='margin: 0 0 10px 0;'>📍 Approximate Location:</h4>
                    <p>{$location}</p>
                </div>";
    }

    $body .= "
                <div class='warning-box'>
                    <p style='margin: 0;'><strong>⚠️ Didn't recognize this login?</strong></p>
                    <p style='margin: 10px 0 0;'>Immediately:</p>
                    <ol style='margin: 10px 0 0; padding-left: 20px;'>
                        <li>Change your password</li>
                        <li>Enable two-factor authentication if not already enabled</li>
                        <li>Contact support at <a href='mailto:{$support_email}'>{$support_email}</a></li>
                    </ol>
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$login_url}' class='button'>View Login History</a>
                </p>
                
                <p style='color: #64748b; font-size: 13px; margin-top: 20px;'>
                    <i class='fas fa-shield-alt'></i> This is an automated security notification. Regular logins help us protect your account from unauthorized access.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " $school_name. All rights reserved.</p>
                <p style='font-size: 11px;'>This email was sent because a login was detected on your account.</p>
            </div>
        </div>
    </body>
    </html>";

    return sendEmail($user['email'], $subject, $body);
}

/**
 * Helper function to get browser info from user agent
 */
function getBrowserInfo($user_agent)
{
    $browsers = [
        'Edg' => 'Microsoft Edge',
        'Edge' => 'Microsoft Edge',
        'OPR' => 'Opera',
        'Chrome' => 'Google Chrome',
        'Firefox' => 'Mozilla Firefox',
        'Safari' => 'Apple Safari',
        'MSIE' => 'Internet Explorer',
        'Trident' => 'Internet Explorer'
    ];

    foreach ($browsers as $key => $name) {
        if (strpos($user_agent, $key) !== false) {
            return $name;
        }
    }

    return 'Unknown Browser';
}

/**
 * Helper function to get device type
 */
function getDeviceType($user_agent)
{
    if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $user_agent)) {
        return 'Tablet';
    }

    if (preg_match('/(mobile|iphone|ipod|android|blackberry|opera mini|iemobile)/i', $user_agent)) {
        return 'Mobile';
    }

    return 'Desktop';
}

/**
 * Helper function to get operating system
 */
function getOperatingSystem($user_agent)
{
    $os_list = [
        'Windows NT 10.0' => 'Windows 10/11',
        'Windows NT 6.3' => 'Windows 8.1',
        'Windows NT 6.2' => 'Windows 8',
        'Windows NT 6.1' => 'Windows 7',
        'Windows NT 6.0' => 'Windows Vista',
        'Windows NT 5.1' => 'Windows XP',
        'Mac OS X' => 'macOS',
        'iPhone' => 'iOS',
        'iPad' => 'iOS',
        'Android' => 'Android',
        'Linux' => 'Linux'
    ];

    foreach ($os_list as $key => $os) {
        if (strpos($user_agent, $key) !== false) {
            return $os;
        }
    }

    return 'Unknown OS';
}

/**
 * Check if IP is private/local
 */
function isPrivateIP($ip)
{
    $private_ranges = [
        '10.0.0.0|10.255.255.255' => '10.0.0.0/8',
        '172.16.0.0|172.31.255.255' => '172.16.0.0/12',
        '192.168.0.0|192.168.255.255' => '192.168.0.0/16',
        '127.0.0.0|127.255.255.255' => '127.0.0.0/8'
    ];

    $ip_long = ip2long($ip);
    if ($ip_long === false) return false;

    foreach ($private_ranges as $range => $cidr) {
        list($start, $end) = explode('|', $range);
        $start_long = ip2long($start);
        $end_long = ip2long($end);

        if ($ip_long >= $start_long && $ip_long <= $end_long) {
            return true;
        }
    }

    return false;
}

/**
 * Optional: Get IP location (requires external service)
 * You can implement this later using a service like ipapi.co, ipinfo.io, etc.
 */
function getIPLocation($ip)
{
    // Skip for private IPs
    if (isPrivateIP($ip)) {
        return 'Local Network';
    }

    // You can implement IP geolocation here using an API
    // For now, return empty string
    return '';
}

/**
 * Send notification to admins when a new application is submitted
 */
function sendNewApplicationAdminNotification($user_id, $application_data = [])
{
    $conn = getDBConnection();

    // Get applicant details
    $user = getUserById($user_id);
    if (!$user || empty($user['email'])) {
        error_log("Admin notification failed: User not found for ID: $user_id");
        return false;
    }

    // Get all admin emails
    $sql = "SELECT email, first_name FROM users WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email != ''";
    $result = $conn->query($sql);

    if ($result->num_rows === 0) {
        error_log("No admin emails found for application notification");
        return false;
    }

    $admins = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['email'])) {
            $admins[$row['email']] = $row['first_name'];
        }
    }

    $school_name = getSetting('school_name', 'Impact Digital Academy');
    $subject = "New Application Received - Impact Digital Academy";

    // Format program type for display
    $program_type = isset($application_data['program_type']) ? ucfirst($application_data['program_type']) : 'Online';
    $program_name = 'Not specified';

    // Get program name if program_id exists
    if (!empty($application_data['program_id'])) {
        $sql = "SELECT name FROM programs WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $application_data['program_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $program_name = $row['name'];
        }
        $stmt->close();
    }

    // Format preferred period
    $preferred_period = '';
    if ($program_type === 'Onsite' && !empty($application_data['preferred_term'])) {
        $preferred_period = "Preferred Term: " . $application_data['preferred_term'];
    } elseif ($program_type === 'Online' && !empty($application_data['preferred_block'])) {
        $preferred_period = "Preferred Block: " . $application_data['preferred_block'];
    } elseif ($program_type === 'School' && !empty($application_data['preferred_school_term'])) {
        $preferred_period = "Preferred School Term: " . $application_data['preferred_school_term'];
    }

    // School name if applicable
    $school_info = '';
    if (!empty($application_data['school_name'])) {
        $school_info = "School: " . $application_data['school_name'];
    }

    $admin_url = BASE_URL . 'modules/admin/applications.php';
    $profile_url = BASE_URL . 'modules/admin/user-details.php?id=' . $user_id;

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; border-top: none; }
            .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2563eb; }
            .button { display: inline-block; background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 5px; }
            .button-secondary { background: #10b981; }
            .badge { background: #f59e0b; color: #1e293b; padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
            .info-row { padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
            .info-row:last-child { border-bottom: none; }
            .label { font-weight: 600; color: #475569; width: 140px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0;'>📝 New Application Received</h1>
                <p style='margin: 10px 0 0; opacity: 0.9;'>" . date('F j, Y g:i A') . "</p>
            </div>
            
            <div class='content'>
                <p>Hello Admin,</p>
                
                <p>A new application has been submitted to <strong>$school_name</strong> and is awaiting your review.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #2563eb;'>Applicant Details:</h3>
                    
                    <div class='info-row'>
                        <span class='label'>Full Name:</span>
                        <span><strong>" . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</strong></span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='label'>Email:</span>
                        <span><a href='mailto:" . htmlspecialchars($user['email']) . "'>" . htmlspecialchars($user['email']) . "</a></span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='label'>Phone:</span>
                        <span>" . htmlspecialchars($user['phone'] ?? 'Not provided') . "</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='label'>Application ID:</span>
                        <span>#" . $user_id . date('Ymd') . " <span class='badge'>Pending</span></span>
                    </div>
                </div>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #2563eb;'>Program Details:</h3>
                    
                    <div class='info-row'>
                        <span class='label'>Program Type:</span>
                        <span>" . $program_type . " Program</span>
                    </div>
                    
                    <div class='info-row'>
                        <span class='label'>Program:</span>
                        <span>" . htmlspecialchars($program_name) . "</span>
                    </div>
                    
                    " . (!empty($school_info) ? "<div class='info-row'><span class='label'>School Info:</span><span>" . htmlspecialchars($school_info) . "</span></div>" : "") . "
                    
                    " . (!empty($preferred_period) ? "<div class='info-row'><span class='label'>Preferred Period:</span><span>" . htmlspecialchars($preferred_period) . "</span></div>" : "") . "
                </div>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$profile_url}' class='button'>View Applicant Details</a>
                    <a href='{$admin_url}' class='button button-secondary'>Manage Applications</a>
                </p>
                
                <p><strong>Next Steps:</strong></p>
                <ol style='background: white; padding: 20px 20px 20px 40px; border-radius: 8px;'>
                    <li>Review the applicant's information and qualifications</li>
                    <li>Approve or reject the application based on admission criteria</li>
                    <li>Contact the applicant if additional information is needed</li>
                    <li>The applicant will be notified automatically of your decision</li>
                </ol>
                
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                
                <p style='color: #64748b; font-size: 13px;'>
                    <i class='fas fa-clock'></i> This application was submitted on " . date('F j, Y \a\t g:i A') . ". Please review it at your earliest convenience.
                </p>
            </div>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " $school_name. All rights reserved.</p>
                <p style='font-size: 11px;'>This is an automated notification for administrators.</p>
            </div>
        </div>
    </body>
    </html>";

    // Send to all admins
    $results = sendBulkEmail($admins, $subject, $body);

    logActivity('admin_application_notification', "Sent new application notification to " . count($admins) . " admins for applicant #{$user_id}");

    return $results['success'] > 0;
}
