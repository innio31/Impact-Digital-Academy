<?php
// click.php - Track link clicks from emails
require_once 'config.php';

// Get tracking parameters
$email_id = isset($_GET['e']) ? $_GET['e'] : '';
$subscriber = isset($_GET['s']) ? base64_decode($_GET['s']) : '';
$title = isset($_GET['t']) ? base64_decode($_GET['t']) : '';
$destination = isset($_GET['url']) ? base64_decode($_GET['url']) : '';

if (!empty($email_id) && !empty($subscriber) && !empty($destination)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        $ip = getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Log the click
        $insertStmt = $db->prepare("
            INSERT INTO email_clicks 
            (email_id, subscriber_email, article_title, article_url, clicked_link, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $email_id,
            $subscriber,
            $title,
            $destination,
            $destination,
            $ip,
            $user_agent
        ]);

        // Update campaign stats
        $campaignStmt = $db->prepare("
            UPDATE email_campaigns 
            SET clicks_count = clicks_count + 1, unique_clicks = unique_clicks + 1 
            WHERE email_id = ?
        ");
        $campaignStmt->execute([$email_id]);
    } catch (Exception $e) {
        error_log("Click tracking error: " . $e->getMessage());
    }
}

// Redirect to actual destination
if (!empty($destination) && filter_var($destination, FILTER_VALIDATE_URL)) {
    header('Location: ' . $destination);
    exit;
} else {
    // Fallback to home page
    header('Location: ' . SITE_URL);
    exit;
}
