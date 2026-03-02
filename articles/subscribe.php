<?php
// subscribe.php - Simplified and robust
require_once 'config.php';

// Enable error logging
error_log("Subscribe.php accessed - " . date('Y-m-d H:i:s'));

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(false, 'Method not allowed');
}

$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJSON(false, 'Please enter a valid email address');
}

try {
    // Connect to database
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        error_log("Database connection failed in subscribe.php");
        sendJSON(false, 'Service temporarily unavailable. Please try again later.');
    }

    // Check if email exists
    $stmt = $db->prepare("SELECT id, status FROM subscribers WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            sendJSON(false, 'This email is already subscribed to our newsletter.');
        } else {
            // Reactivate
            $updateStmt = $db->prepare("UPDATE subscribers SET status = 'active', subscribed_at = NOW() WHERE id = ?");
            $updateStmt->execute([$existing['id']]);

            // Try to send email but don't fail if it doesn't work
            try {
                $mailer = new MailSender();
                $mailer->sendWelcomeEmail($email);
            } catch (Exception $e) {
                error_log("Welcome email failed (reactivation) for $email: " . $e->getMessage());
            }

            sendJSON(true, 'Subscription reactivated! You will receive our next newsletter.');
        }
        exit;
    }

    // Insert new subscriber
    $ip = getClientIP();
    $insertStmt = $db->prepare("INSERT INTO subscribers (email, ip_address) VALUES (?, ?)");

    if ($insertStmt->execute([$email, $ip])) {
        // Try to send email but don't fail if it doesn't work
        $emailSent = false;
        try {
            $mailer = new MailSender();
            $emailSent = $mailer->sendWelcomeEmail($email);
        } catch (Exception $e) {
            error_log("Welcome email failed for $email: " . $e->getMessage());
        }

        if ($emailSent) {
            sendJSON(true, 'Successfully subscribed! Check your inbox for welcome email.');
        } else {
            sendJSON(true, 'Subscribed successfully! Welcome email will be sent shortly.');
        }
    } else {
        sendJSON(false, 'Failed to subscribe. Please try again.');
    }
} catch (PDOException $e) {
    error_log("Database error in subscribe.php: " . $e->getMessage());
    sendJSON(false, 'An error occurred. Please try again later.');
} catch (Exception $e) {
    error_log("General error in subscribe.php: " . $e->getMessage());
    sendJSON(false, 'An unexpected error occurred.');
}
