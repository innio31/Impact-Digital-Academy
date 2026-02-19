<?php
// process_form.php - Using Gmail SMTP
header("Content-Type: application/json; charset=UTF-8");

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Get form data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (empty($data)) {
    $data = $_POST;
}

// Validate required fields
$required = ['firstName', 'lastName', 'email', 'phone', 'department', 'subject', 'message'];
foreach ($required as $field) {
    if (empty(trim($data[$field] ?? ''))) {
        echo json_encode([
            'success' => false,
            'message' => "Please fill in all required fields. Missing: $field"
        ]);
        exit;
    }
}

// Sanitize data
$firstName = htmlspecialchars(trim($data['firstName']));
$lastName = htmlspecialchars(trim($data['lastName']));
$email = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
$phone = htmlspecialchars(trim($data['phone']));
$department = htmlspecialchars(trim($data['department']));
$subject = htmlspecialchars(trim($data['subject']));
$message = htmlspecialchars(trim($data['message']));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address'
    ]);
    exit;
}

// ============================================
// OPTION 1: SIMPLE FILE LOGGING (Works 100%)
// ============================================

$log_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'form_data' => [
        'name' => $firstName . ' ' . $lastName,
        'email' => $email,
        'phone' => $phone,
        'department' => $department,
        'subject' => $subject,
        'message' => $message
    ]
];

// Save to file
$log_file = __DIR__ . '/form_submissions.log';
file_put_contents($log_file, json_encode($log_data, JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);

// ============================================
// OPTION 2: USE FORMSPREE (Free & Reliable)
// ============================================
// Redirect to Formspree (uncomment if you want to use this)

/*
header('Location: https://formspree.io/f/YOUR_FORM_ID');
exit;
*/

// ============================================
// OPTION 3: GOOGLE APPS SCRIPT (Recommended)
// ============================================

// Use this Google Apps Script URL
$google_apps_script_url = "https://script.google.com/macros/s/AKfycbw8Q-dl1U28l6N59ySERm8NcJ2hFZX3duW6iC_WfK3jqZRVGq7RfhqoHQrvjlbJGKjI/exec";

// Send data to Google Apps Script
$post_data = [
    'firstName' => $firstName,
    'lastName' => $lastName,
    'email' => $email,
    'phone' => $phone,
    'department' => $department,
    'subject' => $subject,
    'message' => $message,
    'source' => 'Impact Digital Website'
];

// Use cURL to send to Google Apps Script
$ch = curl_init($google_apps_script_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
curl_close($ch);

// Also send notification to your WhatsApp (optional)
$whatsapp_message = urlencode("📧 New Contact Form:\nName: $firstName $lastName\nPhone: $phone\nEmail: $email\nSubject: $subject\nDepartment: $department");
$whatsapp_url = "https://wa.me/+2349051586024?text=" . $whatsapp_message;

// Save WhatsApp URL for manual checking
file_put_contents($log_file, "WhatsApp Notification: $whatsapp_url\n", FILE_APPEND);

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your message has been received. We will contact you within 24 hours.',
    'whatsapp_url' => $whatsapp_url // Optional: for debugging
]);

exit;
?>