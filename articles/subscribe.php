<?php
// subscribe.php - Handle email subscriptions
header('Content-Type: application/json');
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Check if email already exists
    $checkStmt = $db->prepare("SELECT id, status FROM subscribers WHERE email = ?");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            echo json_encode(['success' => false, 'message' => 'Email already subscribed']);
        } else {
            // Reactivate unsubscribed user
            $updateStmt = $db->prepare("UPDATE subscribers SET status = 'active', subscribed_at = NOW() WHERE id = ?");
            $updateStmt->execute([$existing['id']]);

            // Send welcome email again
            $mailer = new Mailer();
            $mailer->sendWelcomeEmail($email);

            echo json_encode(['success' => true, 'message' => 'Subscription reactivated! Check your email.']);
        }
        exit;
    }

    // Insert new subscriber
    $ip = getClientIP();
    $stmt = $db->prepare("INSERT INTO subscribers (email, ip_address) VALUES (?, ?)");
    $stmt->execute([$email, $ip]);

    // Send welcome email
    $mailer = new Mailer();
    $welcomeSent = $mailer->sendWelcomeEmail($email);

    if ($welcomeSent) {
        echo json_encode(['success' => true, 'message' => 'Successfully subscribed! Welcome email sent.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Subscribed successfully! (Welcome email pending)']);
    }
} catch (Exception $e) {
    error_log("Subscription error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
