<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'enrollment') {
    
    // Simple log to file to verify data is received
    file_put_contents('form_log.txt', print_r($_POST, true), FILE_APPEND);
   
    
    // Collect form data
    $firstName = htmlspecialchars($_POST['firstName']);
    $lastName = htmlspecialchars($_POST['lastName']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $phone = htmlspecialchars($_POST['phone']);
    $country = htmlspecialchars($_POST['country']);
    $state = htmlspecialchars($_POST['state']);
    $lga = htmlspecialchars($_POST['lga']);
    $address = htmlspecialchars($_POST['address']);
    $programCategory = htmlspecialchars($_POST['programCategory']);
    $program = htmlspecialchars($_POST['program']);
    $school = isset($_POST['school']) ? htmlspecialchars($_POST['school']) : 'N/A';
    $howHeard = htmlspecialchars($_POST['howHeard']);
    $comments = htmlspecialchars($_POST['comments']);
    
    // Email configuration
    $to = 'dig2skills@gmail.com'; // Replace with your email
    $subject = 'New Program Enrollment: ' . $firstName . ' ' . $lastName;
    
    // Email body
    $message = "
    <html>
    <head>
        <title>New Program Enrollment</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .header { color: #2563eb; font-size: 24px; margin-bottom: 20px; }
            .details { margin-bottom: 15px; }
            .label { font-weight: bold; color: #1e293b; }
        </style>
    </head>
    <body>
        <div class='header'>New Program Enrollment</div>
        
        <div class='details'><span class='label'>Name:</span> $firstName $lastName</div>
        <div class='details'><span class='label'>Email:</span> $email</div>
        <div class='details'><span class='label'>Phone:</span> $phone</div>
        <div class='details'><span class='label'>Location:</span> $lga, $state, $country</div>
        <div class='details'><span class='label'>Address:</span> $address</div>
        <hr>
        <div class='details'><span class='label'>Program Category:</span> $programCategory</div>
        <div class='details'><span class='label'>Program:</span> $program</div>
        <div class='details'><span class='label'>School:</span> $school</div>
        <div class='details'><span class='label'>How they heard about us:</span> $howHeard</div>
        <div class='details'><span class='label'>Comments:</span> $comments</div>
        <hr>
        <p>This form was submitted on " . date('Y-m-d H:i:s') . "</p>
    </body>
    </html>
    ";
    
    // Email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $email\r\n";
    $headers .= "Reply-To: $email\r\n";
    
    // Send email to admin
    $mailSent = mail($to, $subject, $message, $headers);
    
    // Send confirmation email to applicant
    if ($mailSent) {
        $confirmationSubject = "Thank you for your enrollment application";
        $confirmationMessage = "
        <html>
        <head>
            <title>Thank you for your application</title>
        </head>
        <body>
            <p>Dear $firstName,</p>
            <p>Thank you for applying to the DigSkills program. We have received your application for <strong>$program</strong>.</p>
            <p>Our team will review your application and get back to you within 3-5 business days.</p>
            <p>If you have any questions, please don't hesitate to contact us at support@digskills.com.</p>
            <br>
            <p>Best regards,</p>
            <p>Emmanuel Ademuyiwa,</p>
            <p>Founder & Lead Trainer, DigSkills</p>
        </body>
        </html>
        ";
        
        $confirmationHeaders = "MIME-Version: 1.0\r\n";
        $confirmationHeaders .= "Content-type: text/html; charset=UTF-8\r\n";
        $confirmationHeaders .= "From: no-reply@digskills.com\r\n";
        
        mail($email, $confirmationSubject, $confirmationMessage, $confirmationHeaders);
    }
    
    // Redirect to thank you page
    header('Location: thank_you.html');
    exit();
} else {
    // Not a valid form submission
    header('Location: index.html');
    exit();
}
?>
