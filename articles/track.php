<?php
// track.php - Handle email open tracking (1x1 transparent pixel)
require_once 'config.php';

// Get tracking parameters
$email_id = isset($_GET['e']) ? $_GET['e'] : '';
$subscriber = isset($_GET['s']) ? base64_decode($_GET['s']) : '';
$title = isset($_GET['t']) ? base64_decode($_GET['t']) : '';

// Log the open if valid
if (!empty($email_id) && !empty($subscriber) && filter_var($subscriber, FILTER_VALIDATE_EMAIL)) {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // Get IP and user agent
        $ip = getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Check if this email_id already opened by this subscriber
        $checkStmt = $db->prepare("
            SELECT id, opens_count FROM email_tracking 
            WHERE email_id = ? AND subscriber_email = ?
        ");
        $checkStmt->execute([$email_id, $subscriber]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Update existing record
            $updateStmt = $db->prepare("
                UPDATE email_tracking 
                SET opens_count = opens_count + 1, last_open = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$existing['id']]);

            // Update campaign stats
            $campaignStmt = $db->prepare("
                UPDATE email_campaigns 
                SET opens_count = opens_count + 1 
                WHERE email_id = ?
            ");
            $campaignStmt->execute([$email_id]);
        } else {
            // New open
            $insertStmt = $db->prepare("
                INSERT INTO email_tracking 
                (email_id, subscriber_email, article_title, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$email_id, $subscriber, $title, $ip, $user_agent]);

            // Update campaign stats
            $campaignStmt = $db->prepare("
                UPDATE email_campaigns 
                SET opens_count = opens_count + 1, unique_opens = unique_opens + 1 
                WHERE email_id = ?
            ");
            $campaignStmt->execute([$email_id]);
        }
    } catch (Exception $e) {
        error_log("Tracking error: " . $e->getMessage());
    }
}

// Send 1x1 transparent GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// 1x1 transparent GIF pixel
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
